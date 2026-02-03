<?php
/**
 * One-off: Author-Centric Cleanup & Audit for Renewal References
 * 
 * Goal:
 * - Group listings by author.
 * - Detect state mismatches (some invoiced, some not; some downgraded, some not).
 * - "State Fingerprint" per listing.
 * - Options to apply Block Cleanup, Backfill Expiry, Normalize Refs.
 * 
 * Switches:
 * ?apply=1             -> Enable write mode
 * $enable_block_cleanup = false;
 * $enable_backfill_expiry = false;
 * $enable_ref_normalize = false;
 */

$root = __DIR__;
$wp_load = $root . '/wp-load.php';
if (!file_exists($wp_load)) {
    die("WP load not found");
}
define('WP_USE_THEMES', false);
require_once $wp_load;

global $wpdb;

// === SAFETY & CONFIG ===
$cutoff_date = '2026-02-01 00:00:00';
$only_if_no_invoice_sent = true;

// DANGEROUS SWITCHES (Set to true manually if needed, or via GET for easier testing if safe)
// For this script, we'll keep them effectively hardcoded or settable via Code Edit, 
// as request says "Safety switches at top of script".
$enable_block_cleanup = false;    // If true, applies cleanup to ALL author listings if ANY match test criteria
$enable_backfill_expiry = false;  // If true, copies expiry from sibling
$enable_ref_normalize = false;    // If true, unifies renewal references

// Allow overriding via query param for experimental usage if you really want, 
// but user asked for "Safety switches at top", so we stick to variables.
// We can display their state.

$apply = isset($_GET['apply']) && $_GET['apply'] === '1';

// 1. Fetch ALL published job listings (lightweight)
// We need everything to form complete author groups.
// We DO NOT filter by date here, because we need to see *modern* listings to compare against *old* ones.
$sql = "
    SELECT ID, post_title, post_author, post_date, post_modified_gmt
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

$results = [];
$counts = [
    'authors_scanned' => 0,
    'listings_scanned' => 0,
    'mismatches_found' => 0,
    'cleaned_count' => 0,
    'expiry_backfilled' => 0,
    'refs_normalized' => 0
];

foreach ($groups as $author_id => $listings) {
    if ($author_id == 0)
        continue; // Orphaned
    $counts['authors_scanned']++;
    $counts['listings_scanned'] += count($listings);

    // Build Listing Data
    $listing_data = [];
    $group_fingerprints = [];

    // Aggregate Group Stats
    $has_downgrade_final = false;
    $has_ref_any = false;
    $has_invoiced_any = false;
    $has_due_any = false;
    $ref_values = [];
    $valid_expiry_sources = []; // [ts => pid]

    $any_test_candidate = false; // "Test Era" candidate found in group?

    foreach ($listings as $p) {
        $pid = $p->ID;

        // Metadata
        $ref = get_post_meta($pid, '_renewal_reference', true);
        $pay_status = get_post_meta($pid, '_payment_status', true);
        $inv_sent_ts = (int) get_post_meta($pid, '_invoice_sent_timestamp', true);
        $expires = get_post_meta($pid, '_job_expires', true);

        // Downgrade Final Check
        $is_downgraded = false;
        // Check dynamic keys
        $all_keys = get_post_custom_keys($pid);
        $sent_invoice_keys = [];
        if ($all_keys) {
            foreach ($all_keys as $k) {
                if (strpos($k, '_sent_invoice_') === 0)
                    $sent_invoice_keys[] = $k;
                if (strpos($k, '_sent_downgrade_final_') === 0)
                    $is_downgraded = true;
            }
        }

        // Expiry TS
        $expiry_ts = 0;
        if ($expires && preg_match('/^\d{4}-\d{2}-\d{2}/', $expires)) {
            $expiry_ts = strtotime($expires);
        }

        // Test Era Candidate Check
        // Criteria: Has Ref AND Modified < Cutoff AND (Optional: No Invoice Sent)
        $is_test_candidate = false;
        if ($ref && $p->post_modified_gmt < $cutoff_date) {
            if (!$only_if_no_invoice_sent || $inv_sent_ts <= 0) {
                $is_test_candidate = true;
            }
        }
        if ($is_test_candidate)
            $any_test_candidate = true;

        // Group Aggregates
        if ($is_downgraded)
            $has_downgrade_final = true;
        if ($ref) {
            $has_ref_any = true;
            $ref_values[] = $ref;
        }
        if ($inv_sent_ts > 0)
            $has_invoiced_any = true;
        if ($pay_status === 'DUE')
            $has_due_any = true;

        if ($expiry_ts > 0) {
            $valid_expiry_sources[$expiry_ts] = $pid;
        }

        // Status Bucket
        $bucket = 'PAID';
        if ($is_downgraded)
            $bucket = 'DOWNGRADED';
        elseif ($inv_sent_ts > 0)
            $bucket = 'INVOICED';
        elseif ($pay_status === 'DUE' || $ref)
            $bucket = 'DUE';

        $listing_data[] = [
            'pid' => $pid,
            'title' => $p->post_title,
            'modified' => $p->post_modified_gmt,
            'ref' => $ref,
            'pay_status' => $pay_status,
            'inv_sent_ts' => $inv_sent_ts,
            'expiry_ts' => $expiry_ts,
            'expires_raw' => $expires,
            'is_downgraded' => $is_downgraded,
            'bucket' => $bucket,
            'sent_invoice_keys' => $sent_invoice_keys,
            'is_test_candidate' => $is_test_candidate
        ];

        $group_fingerprints[] = $bucket;
    }

    // Detect Mismatches
    $mismatches = [];
    $unique_buckets = array_unique($group_fingerprints);

    // 1. Downgrade Split
    if ($has_downgrade_final) {
        foreach ($listing_data as $ld) {
            if (!$ld['is_downgraded'])
                $mismatches[] = 'DOWNGRADED_SPLIT';
        }
    }

    // 2. Ref Partial
    if ($has_ref_any) {
        foreach ($listing_data as $ld) {
            if (!$ld['ref'])
                $mismatches[] = 'REF_PARTIAL';
        }
    }

    // 3. Invoiced Partial
    if ($has_invoiced_any) {
        foreach ($listing_data as $ld) {
            if ($ld['inv_sent_ts'] <= 0)
                $mismatches[] = 'INVOICED_PARTIAL';
        }
    }

    // 4. Missing Expiry (some have, some don't)
    if (!empty($valid_expiry_sources)) {
        foreach ($listing_data as $ld) {
            if ($ld['expiry_ts'] <= 0)
                $mismatches[] = 'EXPIRY_MISSING_PARTIAL';
        }
    }

    // 5. Multiple Refs
    $unique_refs = array_unique(array_filter($ref_values));
    if (count($unique_refs) > 1) {
        $mismatches[] = 'MULTIPLE_REFS';
    }

    $mismatches = array_unique($mismatches);

    // Determine Recommended Action
    $rec_action = 'NONE';
    if (in_array('DOWNGRADED_SPLIT', $mismatches))
        $rec_action = 'BLOCK-ALIGN TO DOWNGRADED';
    elseif (in_array('MULTIPLE_REFS', $mismatches))
        $rec_action = 'KEEP MOST RECENT REF';
    elseif (in_array('EXPIRY_MISSING_PARTIAL', $mismatches))
        $rec_action = 'BACKFILL EXPIRY FROM SIBLING';
    elseif ($any_test_candidate)
        $rec_action = 'CLEANUP TEST REFS';

    // Only process interesting groups
    if (empty($mismatches) && !$any_test_candidate) {
        continue;
    }

    if (!empty($mismatches)) {
        $counts['mismatches_found']++;
    }

    // === ACTION LOGIC ===
    $actions_taken = [];

    if ($apply) {
        // 1. Block Cleanup (Test Era)
        // If enabled and ANY listing is a test candidate, clean ALL listings in data.
        // OR standard cleanup if block cleanup disabled (only clean specific test candidates)
        if ($any_test_candidate) {
            $to_clean = [];
            if ($enable_block_cleanup) {
                // Aggressive: All listings
                $to_clean = $listing_data;
                $actions_taken[] = "Block Cleanup: All listings for Author {$author_id}";
            } else {
                // Conservative: Only the candidates
                foreach ($listing_data as $ld) {
                    if ($ld['is_test_candidate'])
                        $to_clean[] = $ld;
                }
                if (count($to_clean) > 0)
                    $actions_taken[] = "Standard Cleanup: " . count($to_clean) . " listings";
            }

            foreach ($to_clean as $ld) {
                if ($ld['is_downgraded'])
                    continue; // Don't mess with downgrade records generally

                $pid = $ld['pid'];
                delete_post_meta($pid, '_renewal_reference');
                delete_post_meta($pid, '_payment_status');
                delete_post_meta($pid, '_invoice_sent_timestamp');
                foreach ($ld['sent_invoice_keys'] as $k)
                    delete_post_meta($pid, $k);
                $counts['cleaned_count']++;
            }
        }

        // 2. Backfill Expiry
        if ($enable_backfill_expiry && in_array('EXPIRY_MISSING_PARTIAL', $mismatches)) {
            // Find best source (max ts)
            krsort($valid_expiry_sources); // max key first
            $best_ts = array_key_first($valid_expiry_sources);
            $best_date = date('Y-m-d', $best_ts);

            foreach ($listing_data as $ld) {
                if ($ld['expiry_ts'] <= 0) {
                    update_post_meta($ld['pid'], '_job_expires', $best_date);
                    // Recalc duration if needed? Assuming core logic handles it eventually, or mostly irrelevant for audit.
                    $actions_taken[] = "Backfilled Expiry ID {$ld['pid']} -> $best_date";
                    $counts['expiry_backfilled']++;
                }
            }
        }

        // 3. Ref Normalize
        if ($enable_ref_normalize && in_array('MULTIPLE_REFS', $mismatches)) {
            // Logic: Pick ref from "Invoiced" listing or most recent
            // Simple: First ref in the list? No, unique_refs.
            // Let's just pick the first one and apply it.
            $target_ref = reset($unique_refs);
            foreach ($listing_data as $ld) {
                if ($ld['ref'] !== $target_ref) {
                    update_post_meta($ld['pid'], '_renewal_reference', $target_ref);
                    $actions_taken[] = "Normalized Ref ID {$ld['pid']} -> $target_ref";
                    $counts['refs_normalized']++;
                }
            }
        }
    }

    $results[] = [
        'author_id' => $author_id,
        'email' => get_the_author_meta('user_email', $author_id),
        'listings' => $listing_data,
        'mismatches' => $mismatches,
        'rec_action' => $rec_action,
        'actions_taken' => $actions_taken,
        'any_test_candidate' => $any_test_candidate
    ];
}

// Render
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html>

<head>
    <title>Author-Centric Audit</title>
    <style>
        body {
            font-family: sans-serif;
            padding: 20px;
            background: #f0f2f5;
        }

        .card {
            background: #fff;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        h2 {
            margin-top: 0;
        }

        table {
            border-collapse: collapse;
            width: 100%;
            font-size: 13px;
        }

        th,
        td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }

        th {
            background: #f9f9f9;
        }

        .tag {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
            margin-right: 4px;
        }

        .tag.PAID {
            background: #dcfce7;
            color: #166534;
        }

        // Green
        .tag.INVOICED {
            background: #fef9c3;
            color: #854d0e;
        }

        // Yellow
        .tag.DUE {
            background: #ffedd5;
            color: #9a3412;
        }

        // Orange
        .tag.DOWNGRADED {
            background: #fee2e2;
            color: #991b1b;
        }

        // Red
        .rec {
            font-weight: bold;
            color: #2563eb;
        }

        .warn {
            background: #fff3cd;
            padding: 10px;
            border-left: 4px solid #fbbf24;
            margin-bottom: 20px;
        }
    </style>
</head>

<body>
    <div class="card">
        <h2>Ref Audit & Cleanup</h2>
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div>
                <strong>Stats:</strong><br>
                Authors Scanned: <?php echo $counts['authors_scanned']; ?><br>
                Listings Scanned: <?php echo $counts['listings_scanned']; ?><br>
                Groups with Mismatches: <?php echo $counts['mismatches_found']; ?>
            </div>
            <div>
                <strong>Switches:</strong><br>
                Block Cleanup: <?php echo $enable_block_cleanup ? '✅ ON' : '❌ OFF'; ?><br>
                Backfill Expiry: <?php echo $enable_backfill_expiry ? '✅ ON' : '❌ OFF'; ?><br>
                Normalize Refs: <?php echo $enable_ref_normalize ? '✅ ON' : '❌ OFF'; ?><br>
            </div>
        </div>

        <?php if ($apply): ?>
            <div class="warn">
                APPLY MODE ACTIVE <br>
                Cleaned: <?php echo $counts['cleaned_count']; ?><br>
                Backfilled: <?php echo $counts['expiry_backfilled']; ?><br>
                Normalized: <?php echo $counts['refs_normalized']; ?>
            </div>
        <?php else: ?>
            <div style="margin-top:20px;">
                <a href="?apply=1"
                    style="background:#dc2626; color:white; padding:10px 20px; text-decoration:none; border-radius:4px;"
                    onclick="return confirm('Ensure you have backups!')">ACTIVATE APPLY MODE</a>
            </div>
        <?php endif; ?>
    </div>

    <?php foreach ($results as $r): ?>
        <div class="card">
            <div style="display:flex; justify-content:space-between; margin-bottom:10px;">
                <div>
                    <strong>User #<?php echo $r['author_id']; ?> (<?php echo esc_html($r['email']); ?>)</strong>
                    <?php if ($r['any_test_candidate']): ?><span class="tag DUE">TEST CANDIDATE</span><?php endif; ?>
                </div>
                <div class="rec">
                    Rec: <?php echo $r['rec_action']; ?>
                </div>
            </div>

            <?php if (!empty($r['mismatches'])): ?>
                <div style="margin-bottom:10px; color:#b91c1c; font-size:12px;">
                    Mismatches: <?php echo implode(', ', $r['mismatches']); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($r['actions_taken'])): ?>
                <div style="margin-bottom:10px; background:#eff6ff; padding:8px; font-size:12px; border-radius:4px;">
                    <?php foreach ($r['actions_taken'] as $act): ?>
                        <div>✅ <?php echo esc_html($act); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Title</th>
                        <th>Bucket</th>
                        <th>Ref</th>
                        <th>Inv Sent</th>
                        <th>Expires</th>
                        <th>Meta</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($r['listings'] as $l): ?>
                        <tr>
                            <td><?php echo $l['pid']; ?></td>
                            <td><?php echo esc_html($l['title']); ?></td>
                            <td><span class="tag <?php echo $l['bucket']; ?>"><?php echo $l['bucket']; ?></span></td>
                            <td><?php echo esc_html($l['ref']); ?></td>
                            <td><?php echo $l['inv_sent_ts'] > 0 ? date('Y-m-d', $l['inv_sent_ts']) : '-'; ?></td>
                            <td><?php echo esc_html($l['expires_raw']); ?></td>
                            <td style="font-size:11px; color:#666;">
                                <?php echo implode(', ', $l['sent_invoice_keys']); ?>
                                <?php if ($l['is_downgraded'])
                                    echo '<strong>DOWNGRADED_FLAG</strong>'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>

</body>

</html>