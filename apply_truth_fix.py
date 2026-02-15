import sys

file_path = 'ngd-renewals-dashboard.php'

replacement = r"""/**
 * TRUTH ENGINE
 * Determines the single-source-of-truth state for an author.
 */
class NGD_Renewals_Truth
{
    public static function compute_author_state(int $user_id): array
    {
        // 1) Blockers
        if (get_user_meta($user_id, '_ngd_evergreen', true) === 'yes') {
            return self::response('NONE', 'PAID', 'User is Evergreen', true, 'Evergreen');
        }

        // 2) Fetch Listings (IDs only)
        $listing_ids = get_posts([
            'post_type'      => 'job_listing',
            'post_status'    => ['publish', 'pending', 'draft', 'private', 'future', 'expired'],
            'author'         => $user_id,
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        if (empty($listing_ids)) {
            return self::response('NONE', 'NONE', 'No listings found', true, 'No Listings');
        }

        // 3) Analyse listings
        $has_due          = false;
        $max_invoice_sent = 0;
        $max_expires      = 0;
        $paid_package_id  = 247687;
        $is_premium       = false;
        $sent_flags       = [];

        foreach ($listing_ids as $pid) {
            $pm = get_post_meta($pid);

            // Payment status
            if (($pm['_payment_status'][0] ?? '') === 'DUE') {
                $has_due = true;
            }

            // Premium detection
            if (
                (int)($pm['_package_id'][0] ?? 0) === (int)$paid_package_id ||
                (($pm['_featured'][0] ?? '') === '1')
            ) {
                $is_premium = true;
            }

            // Invoice sent timestamp
            $inv_ts = (int)($pm['_invoice_sent_timestamp'][0] ?? 0);
            if ($inv_ts > $max_invoice_sent) {
                $max_invoice_sent = $inv_ts;
            }

            // Expiry timestamp (take max)
            $exp_raw = $pm['_job_expires'][0] ?? '';
            if (!empty($exp_raw)) {
                $ts = strtotime((string)$exp_raw);
                if ($ts && $ts > $max_expires) {
                    $max_expires = $ts;
                }
            }

            // Sent flags
            foreach ($pm as $k => $v) {
                if (strpos($k, '_sent_') === 0 && !empty($v[0])) {
                    $sent_flags[$k] = true;
                }
            }
        }

        $y = date('Y');

        $expiry_reason = function (int $days_to_expiry): string {
            if ($days_to_expiry > 0)   return "Expires in {$days_to_expiry} days";
            if ($days_to_expiry === 0) return "Expires today";
            return "Expired " . abs($days_to_expiry) . " days ago";
        };

        // 4) Logic Tree

        // A) DUE users: choose reminders/downgrades based on EXPIRY windows
        if ($has_due) {
            if (!$max_expires) {
                $days_elapsed = $max_invoice_sent ? (int)floor((time() - $max_invoice_sent) / 86400) : 0;
                return self::response('NONE', 'DUE', "DUE but missing expiry date (invoice age: {$days_elapsed}d)");
            }

            $days_to_expiry = (int)floor(($max_expires - time()) / 86400);

            // Post-expiry downgrades
            if ($days_to_expiry <= -8 && empty($sent_flags["_sent_downgrade_final_{$y}"])) {
                return self::response('downgrade_final', 'DUE', $expiry_reason($days_to_expiry));
            }

            if ($days_to_expiry <= -1 && empty($sent_flags["_sent_downgrade_warn_{$y}"])) {
                return self::response('downgrade_warn', 'DUE', $expiry_reason($days_to_expiry));
            }

            // Pre-expiry reminders (T-14 / T-7 / T-3)
            if ($days_to_expiry <= 3 && empty($sent_flags["_sent_reminder_03_{$y}"])) {
                return self::response('reminder_03', 'DUE', $expiry_reason($days_to_expiry));
            }

            if ($days_to_expiry <= 7 && empty($sent_flags["_sent_reminder_07_{$y}"])) {
                return self::response('reminder_07', 'DUE', $expiry_reason($days_to_expiry));
            }

            if ($days_to_expiry <= 14 && empty($sent_flags["_sent_reminder_14_{$y}"])) {
                return self::response('reminder_14', 'DUE', $expiry_reason($days_to_expiry));
            }

            return self::response('NONE', 'DUE', "DUE but outside reminder window (" . $expiry_reason($days_to_expiry) . ")");
        }

        // B) PAID users: invoice trigger only
        if (!$is_premium) {
            return self::response('NONE', 'DOWNGRADED', 'User is not premium');
        }

        if (!$max_expires) {
            return self::response('NONE', 'PAID', 'Premium but no expiry date?');
        }

        $days_to_expiry = (int)floor(($max_expires - time()) / 86400);

        if ($days_to_expiry <= 30 && empty($sent_flags["_sent_invoice_{$y}"])) {
            return self::response('invoice', 'PAID', $expiry_reason($days_to_expiry));
        }

        return self::response('NONE', 'PAID', 'No action required');
    }

    private static function response(
        string $stage,
        string $payment_state,
        string $reason,
        bool $blocked = false,
        string $blocked_reason = ''
    ): array {
        return [
            'stage'          => $stage,
            'payment_state'  => $payment_state,
            'reason'         => $reason,
            'blocked'        => $blocked,
            'blocked_reason' => $blocked_reason,
        ];
    }
}
"""

with open(file_path, 'r') as f:
    lines = f.readlines()

start_index = 2953 # Line 2954
end_index = 3065   # Line 3065 (inclusive of replacement, so slice stops at 3065 which is line 3066)

print(f"Replacing lines {start_index+1} to {end_index}")
print(f"Original line at start: {lines[start_index]}")
print(f"Original line at end-1: {lines[end_index-1]}")
print(f"Line after replacement: {lines[end_index]}")

lines[start_index:end_index] = [replacement + "\n"]

with open(file_path, 'w') as f:
    f.writelines(lines)

print("Applied fix successfully.")
