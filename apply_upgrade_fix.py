import sys

file_path = 'ngd-renewals-dashboard.php'

replacement = r"""    private function action_upgrade(int $user_id, string $eff_date, bool $send_email = true): void
    {
        global $wpdb;
        $table_meta = $wpdb->prefix . 'postmeta';

        // Treat input as ACTUAL Expiry Date (YYYY-MM-DD).
        $eff_ts = strtotime($eff_date);
        if (!$eff_ts) {
            wp_send_json_error(['message' => 'Invalid effective date timestamp'], 400);
        }

        // Requirement 3: Date Picker = Expiry Date. Do NOT add +1 year.
        $expiry_ts = $eff_ts;
        $expiry_ymd = date('Y-m-d', $expiry_ts);

        // Fetch ALL statuses for this author (including expired)
        $listings = get_posts([
            'post_type'      => 'job_listing',
            'post_status'    => ['publish', 'pending', 'draft', 'private', 'future', 'expired'],
            'author'         => $user_id,
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        if (empty($listings)) {
            wp_send_json_error(['message' => 'No listings found for this user'], 404);
        }

        $count = 0;
        $republished = 0;

        foreach ($listings as $pid) {
            // a) _job_expires
            $this->ngd_sql_set_single_meta($pid, '_job_expires', $expiry_ymd);

            // b) _job_duration (days remaining; keep existing logic)
            $days = max(0, (int) floor(($expiry_ts - time()) / DAY_IN_SECONDS));
            $this->ngd_sql_set_single_meta($pid, '_job_duration', (string) $days);

            // c) _featured
            $this->ngd_sql_set_single_meta($pid, '_featured', '1');

            // d) _payment_status
            $this->ngd_sql_set_single_meta($pid, '_payment_status', 'PAID');

            // e) _package_id
            $this->ngd_sql_set_single_meta($pid, '_package_id', (string) $this->paid_package_id);

            // Clear invoice/DUE meta
            $this->ngd_sql_delete_meta_rows($pid, '_ngd_due_expires_ts');
            $this->ngd_sql_delete_meta_rows($pid, '_invoice_sent_timestamp');

            // Delete any meta keys starting with '_sent_invoice_'
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$table_meta} WHERE post_id = %d AND meta_key LIKE %s",
                    $pid,
                    $wpdb->esc_like('_sent_invoice_') . '%'
                )
            );

            // ✅ CRITICAL FIX: If listing is currently expired, republish it
            $current_status = get_post_status($pid);
            if ($current_status === 'expired') {
                wp_update_post([
                    'ID'          => $pid,
                    'post_status' => 'publish',
                ]);
                $republished++;
            }

            // Cache busting
            clean_post_cache($pid);
            wp_cache_delete($pid, 'post_meta');

            // Verification
            $verify_expires = get_post_meta($pid, '_job_expires', true);
            $verify_status  = get_post_meta($pid, '_payment_status', true);
            $verify_post_status = get_post_status($pid);

            if ($verify_expires !== $expiry_ymd || strtoupper($verify_status) !== 'PAID') {
                wp_send_json_error([
                    'message' => 'Verification failed after write (ghost meta persisted?)',
                    'post_id' => $pid,
                    'expected_expires' => $expiry_ymd,
                    'actual_expires' => $verify_expires,
                    'expected_payment_status' => 'PAID',
                    'actual_payment_status' => $verify_status,
                    'post_status' => $verify_post_status,
                ], 500);
            }

            $count++;
        }

        // Optional success email
        if ($send_email) {
            $webhook_class = '\NGD_THEME\Functions\PaymentWebhook';
            if (!class_exists($webhook_class)) {
                $theme_path = get_stylesheet_directory() . '/app/Functions/PaymentWebhook.php';
                if (!file_exists($theme_path)) {
                    wp_send_json_error(['message' => 'PaymentWebhook missing in theme at: ' . $theme_path], 500);
                }
                require_once $theme_path;
            }

            if (class_exists($webhook_class)) {
                $hook = new \NGD_THEME\Functions\PaymentWebhook(false);
                $hook->send_success_email($user_id, $expiry_ymd);
            } else {
                wp_send_json_error(['message' => 'PaymentWebhook class not found'], 500);
            }
        }

        // Persistent Client Marker
        update_user_meta($user_id, '_ngd_client', 'yes');

        wp_send_json_success([
            'message' => sprintf(
                'Upgraded successfully. %d listing(s) updated. Republished %d expired listing(s). Expiry set to: %s. Email: %s',
                (int) $count,
                (int) $republished,
                $expiry_ymd,
                $send_email ? 'sent' : 'not sent'
            ),
            'ok' => true,
            'user_id' => $user_id,
            'updated_posts' => $count,
            'republished' => $republished,
            'effective_date' => $eff_date,
            'expiry' => $expiry_ymd,
            'email_sent' => $send_email
        ]);
    }
"""

with open(file_path, 'r') as f:
    lines = f.readlines()

start_index = 231 # Line 232
end_index = 350   # Line 350 (inclusive of replacement, so slice stops at 350 which is line 351)

print(f"Replacing lines {start_index+1} to {end_index}")
print(f"Original line at start: {lines[start_index]}")
print(f"Original line at end-1: {lines[end_index-1]}")
print(f"Line after replacement: {lines[end_index]}")

lines[start_index:end_index] = [replacement + "\n"]

with open(file_path, 'w') as f:
    f.writelines(lines)

print("Applied fix successfully.")
