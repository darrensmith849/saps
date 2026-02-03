<?php
/**
 * One-off: Author-Centric Cleanup & Audit
 *
 * MODIFIED: Intra-Author Mismatch Fixer
 *
 * Goal:
 * - Group listings by author.
 * - Detect state mismatches (REF, INVOICE_SENT, EXPIRY, PACKAGE).
 * - "State Fingerprint" per listing.
 * - Filter: Authors with >= 2 listings.
 * - Actions: Preview Sync, Apply Sync (using Dashboard helpers).
 */

$root = __DIR__;
$wp_load = $root . '/wp-load.php';
if (!file_exists($wp_load)) {
    die("WP load not found");
}
define('WP_USE_THEMES', false);
require_once $wp_load;

if (!current_user_can('manage_options')) {
    die('Access denied');
}

global $wpdb;

// === ACTIONS ===
$action = $_GET['action'] ?? '';
$target_user = (int) ($_GET['uid'] ?? 0);
$nonce = $_GET['_wpnonce'] ?? '';

if ($action && $target_user) {
    if (!wp_verify_nonce($nonce, 'ngd_sync_' . $target_user)) {
        die('Invalid nonce');
    }

    // Determine Logic
    $is_preview = ($action === 'preview_sync');
    $is_apply = ($action === 'apply_sync');

    if ($is_preview || $is_apply) {
        echo '<div style="padding:20px;background:#fff;font-family:sans-serif;">';
        echo '<h1>' . ($is_preview ? 'Preview Sync' : 'Applying Sync') . ' for User ' . $target_user . '</h1>';

        // 1. Identify Representative (Source)
        // Prefer 'primary' role, else Use Dashboard logic
        $listings = get_posts([
            'post_type' => 'job_listing',
            'post_status' => 'publish',
            'author' => $target_user,
            'posts_per_page' => -1
        ]);

        if (empty($listings))
            die("No listings found.");

        $source = null;
        // Check for explicit primary
        foreach ($listings as $l) {
            if (get_post_meta($l->ID, '_ngd_listing_role', true) === 'primary') {
                $source = $l;
                break;
            }
        }

        // Fallback to Dashboard Logic (Premium > Invoice > Exp > ID)
        if (!$source) {
            usort($listings, function ($a, $b) {
                // simple approximation or logic:
                $pkg_a = (int) get_post_meta($a->ID, '_package_id', true);
                $pkg_b = (int) get_post_meta($b->ID, '_package_id', true);
                $paid_pkg = 247687;
                $a_pd = ($pkg_a === $paid_pkg);
                $b_pd = ($pkg_b === $paid_pkg);

                if ($a_pd !== $b_pd)
                    return $b_pd <=> $a_pd; // Paid first

                $inv_a = (int) get_post_meta($a->ID, '_invoice_sent_timestamp', true);
                $inv_b = (int) get_post_meta($b->ID, '_invoice_sent_timestamp', true);
                if ($inv_a !== $inv_b)
                    return $inv_b <=> $inv_a; // Recent invoice first

                return $a->ID <=> $b->ID; // Lowest ID last
            });
            $source = $listings[0];
        }

        echo "<h3>Source Listing: #{$source->ID} - {$source->post_title}</h3>";

        if (class_exists('NGD_Renewals_Dashboard')) {
            // keys to sync
            $keys = ['_renewal_reference', '_invoice_sent_timestamp', '_payment_status', '_job_expires'];
            $prefixes = ['_sent_invoice_', '_sent_reminder_', '_sent_downgrade_'];

            if ($is_preview) {
                echo "<p>Running Preview... (Simulated)</p>";
                // Simulate
                $siblings = NGD_Renewals_Dashboard::ngd_get_author_job_listing_ids($target_user, false);
                echo "<ul>";
                foreach ($siblings as $sid) {
                    if ($sid == $source->ID)
                        continue;
                    echo "<li>Target #$sid: Will copy metadata from #{$source->ID}. Will fill expiry if empty.</li>";
                }
                echo "</ul>";
                $confirm_url = add_query_arg(['action' => 'apply_sync', 'uid' => $target_user, '_wpnonce' => $nonce]);
                echo "<p><a href='" . $confirm_url . "' style='background:red;color:white;padding:10px;'>Confirm Apply</a></p>";

            } elseif ($is_apply) {
                $res = NGD_Renewals_Dashboard::ngd_sync_meta_to_author_listings($target_user, $source->ID, $keys, $prefixes);
                echo "<pre>" . print_r($res, true) . "</pre>";
                echo "<p><strong>Sync Complete.</strong></p>";
            }

        } else {
            echo "Error: Dashboard class not found.";
        }

        echo '<p><a href="?">Back to Report</a></p>';
        echo '</div>';
        exit;
    }
}

// === REPORT ===

$sql = "
    SELECT ID, post_title, post_author, post_date
    FROM {$wpdb->posts}
    WHERE post_type = 'job_listing'
      AND post_status = 'publish'
    ORDER BY post_author ASC, ID DESC
";
$all_posts = $wpdb->get_results($sql);
$groups = [];
foreach ($all_posts as $p) {
    $groups[$p->post_author][] = $p;
}

echo "<style>
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; padding: 20px; color: #333; }
    h1 { font-size: 24px; margin-bottom: 20px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 30px; font-size: 13px; }
    th { text-align: left; background: #f1f5f9; padding: 8px; border-bottom: 2px solid #e2e8f0; }
    td { padding: 8px; border-bottom: 1px solid #e2e8f0; }
    .mismatch { background: #fee2e2; }
    .pill { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: bold; }
    .pill.red { background: #fee2e2; color: #991b1b; }
    .btn { display: inline-block; padding: 4px 8px; background: #fff; border: 1px solid #ccc; text-decoration: none; color: #333; border-radius: 4px; font-size: 12px; }
    .btn:hover { background: #f8f8f8; }
</style>";

echo "<h1>Intra-Author Listing Mismatches</h1>";

foreach ($groups as $uid => $listings) {
    if (count($listings) < 2)
        continue;

    // Check Mismatches
    $metas = [];
    foreach ($listings as $l) {
        $metas[$l->ID] = [
            'pid' => $l->ID,
            'title' => $l->post_title,
            'role' => get_post_meta($l->ID, '_ngd_listing_role', true),
            'pkg' => get_post_meta($l->ID, '_package_id', true),
            'feat' => get_post_meta($l->ID, '_featured', true),
            'pay' => get_post_meta($l->ID, '_payment_status', true),
            'ref' => get_post_meta($l->ID, '_renewal_reference', true),
            'sent' => get_post_meta($l->ID, '_invoice_sent_timestamp', true),
            'exp' => get_post_meta($l->ID, '_job_expires', true),
        ];
    }

    // Filter out duplicates for mismatch check
    $check_set = array_filter($metas, function ($m) {
        return $m['role'] !== 'duplicate'; });
    if (count($check_set) < 2)
        continue;

    $base = reset($check_set);
    $mis = [];
    foreach ($check_set as $m) {
        if ($m['ref'] !== $base['ref'])
            $mis['REF'] = true;
        if ($m['sent'] != $base['sent'])
            $mis['INVOICE_SENT'] = true;

        $e1 = empty($m['exp']);
        $e2 = empty($base['exp']);
        if ($e1 !== $e2)
            $mis['EXPIRY'] = true;

        if ($m['pkg'] != $base['pkg'])
            $mis['PACKAGE'] = true;
    }

    if (empty($mis))
        continue; // No mismatch

    // Render Group
    echo "<div style='background:#fff; border:1px solid #cbd5e1; border-radius:8px; padding:16px; margin-bottom:20px;'>";
    echo "<div style='display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;'>";
    echo "<strong style='font-size:16px;'>User #$uid</strong>";
    echo "<div>";
    foreach (array_keys($mis) as $k)
        echo "<span class='pill red' style='margin-left:5px;'>$k Mismatch</span>";
    echo "</div>";
    echo "</div>";

    echo "<table>";
    echo "<thead><tr><th>ID</th><th>Title</th><th>Role</th><th>Ref</th><th>Sent TS</th><th>Expiry</th><th>Pkg</th></tr></thead>";
    foreach ($metas as $m) {
        $hl = ($m['role'] === 'duplicate') ? 'color:#94a3b8;' : '';
        echo "<tr style='$hl'>";
        echo "<td>{$m['pid']}</td>";
        echo "<td>{$m['title']}</td>";
        echo "<td>{$m['role']}</td>";
        echo "<td>{$m['ref']}</td>";
        echo "<td>{$m['sent']}</td>";
        echo "<td>{$m['exp']}</td>";
        echo "<td>{$m['pkg']}</td>";
        echo "</tr>";
    }
    echo "</table>";

    $url_pre = add_query_arg(['action' => 'preview_sync', 'uid' => $uid, '_wpnonce' => wp_create_nonce('ngd_sync_' . $uid)]);
    echo "<a href='$url_pre' class='btn'>Preview Sync</a>";

    echo "</div>";
}