<?php
/**
 * One-off: Cleanup test renewal references
 * MODE: Preview by default.
 * APPLY: ?apply=1
 *
 * Criteria:
 * - post_type = job_listing
 * - has meta _renewal_reference
 * - (optional) _invoice_sent_timestamp invalid/missing
 * - post_modified_gmt < cutoff
 */

$root = __DIR__;
$wp_load = $root . '/wp-load.php';
if (!file_exists($wp_load)) {
    die("WP load not found");
}
define('WP_USE_THEMES', false);
require_once $wp_load;

global $wpdb;

// === CONFIG ===
$cutoff_date = '2026-02-01 00:00:00'; // Target "testing era"
$only_if_no_invoice_sent = true;       // Extra safety
$ref_prefix = 'NGD';                   // Optional filter
// ==============

$apply = isset($_GET['apply']) && $_GET['apply'] === '1';

// 1. Find candidates
$sql = "
    SELECT p.ID, p.post_title, p.post_author, p.post_modified_gmt,
           pm_ref.meta_value as renewal_ref
    FROM {$wpdb->posts} p
    INNER JOIN {$wpdb->postmeta} pm_ref ON p.ID = pm_ref.post_id
    WHERE p.post_type = 'job_listing'
      AND p.post_status = 'publish'
      AND pm_ref.meta_key = '_renewal_reference'
      AND pm_ref.meta_value != ''
      AND p.post_modified_gmt < %s
";

// Optional filter by prefix
if ($ref_prefix) {
    $sql .= $wpdb->prepare(" AND pm_ref.meta_value LIKE %s", $ref_prefix . '%');
}

$candidates = $wpdb->get_results($wpdb->prepare($sql, $cutoff_date));

$results = [];
$counts = ['scanned' => 0, 'matched' => 0, 'cleaned' => 0];

foreach ($candidates as $post) {
    $counts['scanned']++;
    $pid = (int) $post->ID;

    // Safety: check invoice sent timestamp
    $invoice_sent = get_post_meta($pid, '_invoice_sent_timestamp', true);
    if ($only_if_no_invoice_sent && !empty($invoice_sent) && (int) $invoice_sent > 0) {
        continue; // Skip if invoice was sent
    }

    $counts['matched']++;

    // Collect meta to kill
    $kill_keys = ['_renewal_reference', '_payment_status', '_invoice_sent_timestamp'];

    // Find dynamic keys starting with _sent_invoice_
    $all_meta = get_post_meta($pid);
    foreach ($all_meta as $k => $v) {
        if (strpos($k, '_sent_invoice_') === 0) {
            $kill_keys[] = $k;
        }
    }
    $kill_keys = array_unique($kill_keys);

    $payment_status = get_post_meta($pid, '_payment_status', true);

    if ($apply) {
        foreach ($kill_keys as $k) {
            delete_post_meta($pid, $k);
        }
        $counts['cleaned']++;
        $results[] = [
            'id' => $pid,
            'title' => $post->post_title,
            'modified' => $post->post_modified_gmt,
            'ref' => $post->renewal_ref,
            'status' => 'CLEANED 🧹',
            'removed' => implode(', ', $kill_keys)
        ];
    } else {
        $results[] = [
            'id' => $pid,
            'title' => $post->post_title,
            'modified' => $post->post_modified_gmt,
            'ref' => $post->renewal_ref,
            'status' => 'WOULD CLEAN 🔍',
            'meta_found' => [
                'bg_payment' => $payment_status,
                'bg_sent_ts' => $invoice_sent,
                'kill_list' => $kill_keys
            ]
        ];
    }
}

// Display
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html>

<head>
    <title>Cleanup Test Refs</title>
    <style>
        body {
            font-family: sans-serif;
            padding: 20px
        }

        table {
            border-collapse: collapse;
            width: 100%;
            margin-top: 20px
        }

        td,
        th {
            border: 1px solid #ccc;
            padding: 8px;
            font-size: 13px
        }

        .meta {
            color: #666
        }

        .btn {
            background: red;
            color: white;
            padding: 10px;
            text-decoration: none;
            border-radius: 5px
        }
    </style>
</head>

<body>
    <h2>Test Renewal Ref Cleanup</h2>
    <div class="meta">
        Cutoff:
        <?php echo esc_html($cutoff_date); ?><br>
        Only if no invoice sent:
        <?php echo $only_if_no_invoice_sent ? 'YES' : 'NO'; ?><br>
        Prefix:
        <?php echo esc_html($ref_prefix); ?>
    </div>

    <p>
        Scanned: <strong>
            <?php echo $counts['scanned']; ?>
        </strong><br>
        Matched: <strong>
            <?php echo $counts['matched']; ?>
        </strong><br>
        Cleaned: <strong>
            <?php echo $counts['cleaned']; ?>
        </strong>
    </p>

    <?php if (!$apply && $counts['matched'] > 0): ?>
        <p><a href="?apply=1" class="btn" onclick="return confirm('Really delete meta?')">APPLY CLEANUP</a></p>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Title</th>
                <th>Modified (GMT)</th>
                <th>Ref</th>
                <th>Status</th>
                <th>Details</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($results as $r): ?>
                <tr>
                    <td>
                        <?php echo $r['id']; ?>
                    </td>
                    <td>
                        <?php echo esc_html($r['title']); ?>
                    </td>
                    <td>
                        <?php echo $r['modified']; ?>
                    </td>
                    <td>
                        <?php echo esc_html($r['ref']); ?>
                    </td>
                    <td><strong>
                            <?php echo $r['status']; ?>
                        </strong></td>
                    <td>
                        <?php if ($apply): ?>
                            Removed:
                            <?php echo esc_html($r['removed']); ?>
                        <?php else: ?>
                            PayStatus:
                            <?php echo esc_html($r['meta_found']['bg_payment']); ?><br>
                            InvSent:
                            <?php echo esc_html($r['meta_found']['bg_sent_ts']); ?><br>
                            Targets:
                            <?php echo implode(', ', $r['meta_found']['kill_list']); ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>

</html>