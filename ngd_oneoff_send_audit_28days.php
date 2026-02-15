<?php
/**
 * One-off: Send “Audit + 28 days to pay” email (GENERATES/SETS renewal ref if missing)
 * Place in WP root, run via:
 *  PREVIEW: https://your-site.com/ngd_oneoff_send_audit_28days.php
 *  SEND:    https://your-site.com/ngd_oneoff_send_audit_28days.php?send=1
 * Then delete.
 */

$root = __DIR__;
$wp_load = $root . '/wp-load.php';
if (!file_exists($wp_load)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "ERROR: wp-load.php not found.\nPut this file in WP root (next to wp-config.php).\n";
    exit;
}

define('WP_USE_THEMES', false);
require_once $wp_load;

global $wpdb;

// ====== CONFIG ======
$from_name = 'Taryn';
$from_email = 'taryn@saprivateschools.co.za'; // adjust if needed
$cc_email = 'darren@saprivateschools.co.za';

// Signature image at WP root
$sig_image_path = ABSPATH . 'taryn-signature.jpg';
$sig_image_cid = 'taryn_signature_image';

// Invoice page URL (MUST be your real invoice-view page)
$invoice_page_base = home_url('/invoice'); // <-- CHANGE THIS

// Banking details (FILL THESE IN)
$banking = [
    'account_name' => 'SA Private Schools (Pty) Ltd',
    'bank' => 'First National Bank',
    'account_no' => '63000024636',
    'branch_code' => '204-009',
    'account_type' => 'Cheque / Current',
    'swift' => '',
];

// Your reinvoice list
$schools = [
    'Treverton College',
    'St Thomas Aquinas Primary School',
    'St Martin’s High School',
    'Sacred Heart Primary School',
    'Royal Pre-Schools Queens Private',
    'Riverside Pre-School',
    'Ridgeway College',
    'Parklands College',
    'Orel Private Academy Pre-School',
    'Loreto School Queenswood Primary School',
    'Glen Play Centre and Pre-Primary School',
    'Excelsior Learning Centre High School',
    'Excelsior Aanlynleersentrum / Online Learning centre',
    'Dainfern Preparatory School',
    'Curro Thatchfield High School',
    'Chartwell House Montessori Eco Pre-School',
    'Canterbury Pre-School',
    'Audeamus Private Primary School',
    'Auburn House Montessori Primary School',
    'Addnum Academy Primary School',
];
// ====================

$do_send = isset($_GET['send']) && $_GET['send'] === '1';

// Embed signature image inline (CID)
add_action('phpmailer_init', function ($phpmailer) use ($sig_image_path, $sig_image_cid) {
    if (is_string($sig_image_path) && file_exists($sig_image_path)) {
        $lower = strtolower($sig_image_path);
        $mime = (substr($lower, -4) === '.jpg' || substr($lower, -5) === '.jpeg') ? 'image/jpeg' : 'image/png';
        $phpmailer->addEmbeddedImage($sig_image_path, $sig_image_cid, basename($sig_image_path), 'base64', $mime);
    }
});

/**
 * Generate canonical SCH reference: INV-{USER_ID}-{RAND4}
 * Changed from SCH-{REP_POST_ID}-{RAND4}
 */
function ngd_generate_payment_reference_sch(int $user_id): string
{
    // RAND4 (0000-9999)
    $rand4 = str_pad((string) mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);

    return sprintf('INV-%d-%s', $user_id, $rand4);
}

/**
 * Match school title to latest published job_listing and return author + email + post_id.
 */
function ngd_match_school_to_post_and_email(string $school_title): array
{
    global $wpdb;

    $school_title = trim((string) $school_title);
    if ($school_title === '')
        return ['mode' => 'NONE', 'post_id' => 0, 'user_id' => 0, 'email' => '', 'matched_title' => '', 'notes' => 'Empty title'];

    // EXACT
    $sql = $wpdb->prepare(
        "SELECT ID, post_author, post_title
         FROM {$wpdb->posts}
         WHERE post_type='job_listing'
           AND post_status='publish'
           AND post_title=%s
         ORDER BY post_modified_gmt DESC
         LIMIT 1",
        $school_title
    );
    $r = $wpdb->get_row($sql);

    $mode = 'NONE';
    $post_id = 0;
    $user_id = 0;
    $matched_title = '';
    $notes = '';

    if ($r && !empty($r->ID)) {
        $mode = 'EXACT';
        $post_id = (int) $r->ID;
        $user_id = (int) $r->post_author;
        $matched_title = (string) $r->post_title;
    } else {
        // LIKE fallback
        $like = '%' . $wpdb->esc_like($school_title) . '%';
        $sql2 = $wpdb->prepare(
            "SELECT ID, post_author, post_title
             FROM {$wpdb->posts}
             WHERE post_type='job_listing'
               AND post_status='publish'
               AND post_title LIKE %s
             ORDER BY post_modified_gmt DESC
             LIMIT 1",
            $like
        );
        $r2 = $wpdb->get_row($sql2);

        if ($r2 && !empty($r2->ID)) {
            $mode = 'LIKE';
            $post_id = (int) $r2->ID;
            $user_id = (int) $r2->post_author;
            $matched_title = (string) $r2->post_title;
            $notes = 'LIKE fallback used';
        }
    }

    $email = '';
    if ($user_id > 0) {
        $user = get_user_by('id', $user_id);
        if ($user && !empty($user->user_email)) {
            $email = (string) $user->user_email;
        } else {
            $notes = trim(($notes ? $notes . ' ' : '') . 'Author user missing or no email');
        }
    }

    return ['mode' => $mode, 'post_id' => $post_id, 'user_id' => $user_id, 'email' => $email, 'matched_title' => $matched_title, 'notes' => $notes];
}

/**
 * Ensure canonical SCH reference exists for the author.
 * Legacy Handling: Migrate NGD* or SCH* refs to alias, generate new SCHU ref (unless SCHU exists).
 */
function ngd_get_or_create_author_reference(int $user_id, int $fallback_post_id): array
{
    // 1. Fetch ALL listings (publish, draft, pending, etc)
    $listings = get_posts([
        'post_type' => 'job_listing',
        'post_status' => ['publish', 'pending', 'draft', 'private', 'future', 'expired'],
        'author' => $user_id,
        'posts_per_page' => -1,
        'fields' => 'ids'
    ]);

    if (empty($listings)) {
        return ['ref' => '', 'created' => false, 'notes' => 'No listings found', 'legacy_aliases' => ''];
    }

    // 2. Scan for existing refs
    $existing_canonical = '';
    $legacy_refs = [];

    foreach ($listings as $pid) {
        $val = get_post_meta($pid, '_renewal_reference', true);
        if ($val) {
            // Check for new CANONICAL format: INV-{USER_ID}-{RAND4}
            if (preg_match('/^INV-\d+-\d{4}$/i', $val)) {
                if (!$existing_canonical)
                    $existing_canonical = $val; // Take first found canonical
            } else {
                // Anything else (SCH-..., NGD-..., SCHU-...) is legacy
                if (!in_array($val, $legacy_refs))
                    $legacy_refs[] = $val;
            }
        }
    }

    // 3. Logic
    $canonical_ref = '';
    $created_new = false;
    $notes = '';

    if ($existing_canonical) {
        $canonical_ref = $existing_canonical;
        $notes = 'Existing INV ref found';
    } else {
        // Needs new SCHU ref
        $canonical_ref = ngd_generate_payment_reference_sch($user_id);
        $created_new = true;

        if (!empty($legacy_refs)) {
            $notes = 'Legacy ref(s) aliased; New INV generated';
            // Save aliases to all listings (do not overwrite existing aliases, append if unique)
            foreach ($listings as $pid) {
                foreach ($legacy_refs as $lref) {
                    // Check if alias already exists
                    $cur_aliases = get_post_meta($pid, '_renewal_reference_alias');
                    if (!in_array($lref, $cur_aliases)) {
                        add_post_meta($pid, '_renewal_reference_alias', $lref);
                    }
                }
            }
        } else {
            $notes = 'New INV ref generated';
        }

        // Save Canonical Ref to ALL listings (overwrite)
        foreach ($listings as $pid) {
            update_post_meta($pid, '_renewal_reference', $canonical_ref);
        }

        // Metadata on Rep/Fallback
        $target_id = (!empty($listings)) ? $listings[0] : $fallback_post_id;
        update_post_meta($target_id, '_renewal_reference_issued_ts', time());
        update_post_meta($target_id, '_renewal_reference_source', 'prod');
    }

    return [
        'ref' => $canonical_ref,
        'created' => $created_new,
        'notes' => $notes,
        'legacy_aliases' => implode(' | ', $legacy_refs)
    ];
}

/**
 * Stamp invoice markers ACROSS ALL AUTHOR LISTINGS.
 * Prevents mismatch.
 */
function ngd_stamp_invoice_sent_for_author(int $user_id): void
{
    if ($user_id <= 0)
        return;

    $listings = get_posts([
        'post_type' => 'job_listing',
        'post_status' => ['publish', 'pending', 'draft', 'private', 'future', 'expired'],
        'author' => $user_id,
        'posts_per_page' => -1,
        'fields' => 'ids'
    ]);

    $now = time();
    $flag_key = '_sent_invoice_' . date('Ymd', $now);

    foreach ($listings as $pid) {
        update_post_meta($pid, '_invoice_sent_timestamp', $now);
        update_post_meta($pid, $flag_key, 1);
        update_post_meta($pid, '_payment_status', 'DUE');
    }
}

/**
 * Set due expiry timestamp for author across ALL listings.
 */
function ngd_set_due_expiry_for_author(int $user_id, int $days = 28): void
{
    if ($user_id <= 0)
        return;

    // Find ALL job_listing posts
    $listings = get_posts([
        'post_type' => 'job_listing',
        'post_status' => ['publish', 'pending', 'draft', 'private', 'future', 'expired'],
        'author' => $user_id,
        'posts_per_page' => -1,
        'fields' => 'ids',
    ]);

    // Constant usually defined in WP, fallback just in case
    $in_seconds = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
    $due_ts = time() + ($days * $in_seconds);

    foreach ($listings as $pid) {
        update_post_meta($pid, '_ngd_due_expires_ts', $due_ts);
    }
}

$results = [];
$sent_ok = 0;
$sent_fail = 0;
$skipped = 0;

$subject = 'SA Private Schools – Premium listing audit & 28 days to renew';

// Calc generic due date for preview (28 days from now)
$in_seconds = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
$preview_due_date = date('Y-m-d', time() + (28 * $in_seconds));

foreach ($schools as $school) {
    $m = ngd_match_school_to_post_and_email($school);
    $to = $m['email'];
    $can_send = ($m['mode'] !== 'NONE' && $to !== '' && $m['user_id'] > 0 && $m['post_id'] > 0);

    $ref_info = ['ref' => '', 'created' => false, 'notes' => '', 'legacy_aliases' => ''];
    $invoice_link = '';

    if ($can_send) {
        $ref_info = ngd_get_or_create_author_reference((int) $m['user_id'], (int) $m['post_id']);

        // Use signed link if available (TTL = 28 days)
        if (class_exists('NGD_Standalone_Invoice_Signed')) {
            $invoice_link = NGD_Standalone_Invoice_Signed::generate_signed_invoice_url($ref_info['ref'], 60 * 60 * 24 * 28);
        } else {
            $invoice_link = add_query_arg(['ref' => $ref_info['ref']], $invoice_page_base);
        }
    }

    $sig_html = '';
    if (is_string($sig_image_path) && file_exists($sig_image_path)) {
        $sig_html = '<p style="margin-top:10px;">
            <img src="cid:' . esc_attr($sig_image_cid) . '" alt="Taryn signature" style="max-width:260px;height:auto;">
        </p>';
    }

    $ref_html = $ref_info['ref']
        ? '<span style="color:#dc2626;font-weight:800;">' . esc_html($ref_info['ref']) . '</span>'
        : '<em>Reference unavailable</em>';

    $bank_lines = [];
    $bank_lines[] = '<strong>Banking details:</strong>';
    $bank_lines[] = 'Account name: ' . esc_html($banking['account_name'] ?? '');
    $bank_lines[] = 'Bank: ' . esc_html($banking['bank'] ?? '');
    $bank_lines[] = 'Account number: ' . esc_html($banking['account_no'] ?? '');
    $bank_lines[] = 'Branch code: ' . esc_html($banking['branch_code'] ?? '');
    $bank_lines[] = 'Account type: ' . esc_html($banking['account_type'] ?? '');
    if (!empty($banking['swift']))
    </div>';

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $from_name . ' <' . $from_email . '>',
        'Cc: ' . $cc_email,
    ];

    // Check existing stored expiry for visibility
    $stored_due_ts = '';
    if ($m['post_id'] > 0) {
        $ts = get_post_meta($m['post_id'], '_ngd_due_expires_ts', true);
        if ($ts) {
            $stored_due_ts = date('Y-m-d', $ts);
        }
    }

    if (!$do_send) {
        $results[] = [
            'school' => $school,
            'match' => $m['mode'],
            'matched_title' => $m['matched_title'],
            'email' => $to ?: '(missing)',
            'ref' => $ref_info['ref'] ?: '(n/a)',
            'legacy_aliases' => $ref_info['legacy_aliases'],
            'invoice_link' => $invoice_link ?: '(n/a)',
            'due_date_preview' => $can_send ? $preview_due_date : '-',
            'stored_due_date' => $stored_due_ts ?: '-',
            'status' => $can_send ? 'READY' : 'NOT READY',
            'notes' => trim(($m['notes'] ? $m['notes'] . '; ' : '') . ($ref_info['notes'] ?? '')),
        ];
        continue;
    }

    if (!$can_send) {
        $skipped++;
        $results[] = [
            'school' => $school,
            'match' => $m['mode'],
            'matched_title' => $m['matched_title'],
            'email' => $to ?: '(missing)',
            'ref' => $ref_info['ref'] ?: '(n/a)',
            'legacy_aliases' => $ref_info['legacy_aliases'],
            'invoice_link' => $invoice_link ?: '(n/a)',
            'due_date_preview' => '-',
            'stored_due_date' => $stored_due_ts ?: '-',
            'status' => 'SKIPPED',
            'notes' => $m['notes'] ?: 'No match/email',
        ];
        continue;
    }

    // ✅ Stamp invoice markers BEFORE sending (Author-Wide)
    ngd_stamp_invoice_sent_for_author((int) $m['user_id']);

    // ✅ NEW: Stamp Due Expiry (28 Days)
    ngd_set_due_expiry_for_author((int) $m['user_id'], 28);

    $ok = wp_mail($to, $subject, $body, $headers);

    if ($ok) {
        $sent_ok++;
        $results[] = [
            'school' => $school,
            'match' => $m['mode'],
            'matched_title' => $m['matched_title'],
            'email' => $to,
            'ref' => $ref_info['ref'],
            'legacy_aliases' => $ref_info['legacy_aliases'],
            'invoice_link' => $invoice_link,
            'due_date_preview' => date('Y-m-d', time() + (28 * $in_seconds)), // Approx for display
            'stored_due_date' => date('Y-m-d', time() + (28 * $in_seconds)), // It's set now
            'status' => 'SENT ✅',
            'notes' => trim(($m['notes'] ? $m['notes'] . '; ' : '') . ($ref_info['created'] ? 'ref created; stamped author' : 'ref reused; stamped author'))
        ];
    } else {
        $sent_fail++;
        $results[] = [
            'school' => $school,
            'match' => $m['mode'],
            'matched_title' => $m['matched_title'],
            'email' => $to,
            'ref' => $ref_info['ref'],
            'legacy_aliases' => $ref_info['legacy_aliases'],
            'invoice_link' => $invoice_link,
            'due_date_preview' => '-',
            'stored_due_date' => $stored_due_ts ?: '-',
            'status' => 'FAILED ❌',
            'notes' => 'wp_mail failed. Check SMTP/logs.'
        ];
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <title>NGD One-off Audit + 28 Days Sender</title>
    <style>
        body {
            font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial;
            margin: 20px;
            color: #0b1220
        }

        table {
            border-collapse: collapse;
            width: 100%;
            margin-top: 14px
        }

        th,
        td {
            border: 1px solid #e2e8f0;
            padding: 8px;
            font-size: 13px;
            vertical-align: top
        }

        th {
            background: #f8fafc;
            text-align: left
        }

        .meta {
            color: #64748b;
            font-size: 13px
        }

        code {
            background: #f1f5f9;
            padding: 2px 6px;
            border-radius: 6px
        }

        .btn {
            display: inline-block;
            padding: 10px 12px;
            border-radius: 10px;
            background: #2563eb;
            color: #fff;
            text-decoration: none;
            font-weight: 800
        }

        .warn {
            background: #fffbeb
        }

        .bad {
            background: #fef2f2
        }

        .good {
            background: #ecfdf5
        }
    </style>
</head>

<body>
    <h2>NGD One-off: Audit + 28 Days Sender 📧</h2>

    <div class="meta">
        Mode: <strong><?php echo $do_send ? 'SEND' : 'PREVIEW'; ?></strong><br>
        From: <code><?php echo esc_html($from_name . ' <' . $from_email . '>'); ?></code><br>
        CC: <code><?php echo esc_html($cc_email); ?></code><br>
        Invoice base: <code><?php echo esc_html($invoice_page_base); ?></code><br>
        Signature image exists:
        <strong><?php echo (file_exists($sig_image_path) ? 'YES' : 'NO (ensure taryn-signature.jpg is in WP root)'); ?></strong>
    </div>

    <?php if (!$do_send): ?>
        <p><a class="btn" href="<?php echo esc_url(add_query_arg(['send' => 1])); ?>">Send now (send=1)</a></p>
        <p class="meta">Preview only right now. Click “Send now” to actually send.</p>
    <?php else: ?>
        <p class="meta">
            Sent OK: <strong><?php echo (int) $sent_ok; ?></strong> ·
            Failed: <strong><?php echo (int) $sent_fail; ?></strong> ·
            Skipped: <strong><?php echo (int) $skipped; ?></strong>
        </p>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th>School</th>
                <th>Match</th>
                <th>Email</th>
                <th>Reference</th>
                <th>Legacy Aliases</th>
                <th>Invoice Link</th>
                <th>Due Date (Preview)</th>
                <th>Stored Due Date</th>
                <th>Status</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($results as $r):
                $cls = '';
                if (strpos($r['status'], 'READY') !== false || strpos($r['status'], 'SENT') !== false)
                    $cls = 'good';
                if (strpos($r['status'], 'NOT READY') !== false || strpos($r['status'], 'SKIPPED') !== false)
                    $cls = 'warn';
                if (strpos($r['status'], 'FAILED') !== false)
                    $cls = 'bad';
                ?>
                <tr class="<?php echo esc_attr($cls); ?>">
                    <td>
                        <?php echo esc_html($r['school']); ?>
                        <?php if (!empty($r['matched_title'])): ?>
                            <br><small style="color:#666">Matched: <?php echo esc_html($r['matched_title']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($r['match']); ?></td>
                    <td><?php echo esc_html($r['email']); ?></td>
                    <td><strong><?php echo esc_html($r['ref'] ?? '(n/a)'); ?></strong></td>
                    <td><?php echo esc_html($r['legacy_aliases']); ?></td>
                    <td><?php echo esc_html($r['invoice_link'] ?? '(n/a)'); ?></td>
                    <td><?php echo esc_html($r['due_date_preview']); ?></td>
                    <td><strong><?php echo esc_html($r['stored_due_date']); ?></strong></td>
                    <td><strong><?php echo esc_html($r['status']); ?></strong></td>
                    <td><?php echo esc_html($r['notes']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <p class="meta">Delete this file after use.</p>
</body>

</html>