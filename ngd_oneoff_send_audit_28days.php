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
    'bank' => 'Your Bank Name',
    'account_no' => '0000000000',
    'branch_code' => '000000',
    'account_type' => 'Cheque / Current',
    'swift' => '',
];

// Your reinvoice list
$schools = [
    'Western Province Preparatory School',
    'Treverton College',
    'St Thomas Aquinas Primary School',
    'St Martin’s High School',
    'Sacred Heart Primary School',
    'Royal Pre-Schools Queens Private',
    'Riverside Pre-School',
    'Ridgeway College',
    'Rallim High School',
    'Parklands College',
    'Orel Private Academy Pre-School',
    'Lux College',
    'Loreto School Queenswood Primary School',
    'Little Star Educare Centre',
    'Glen Play Centre and Pre-Primary School',
    'Future Nation Schools Lyndhurst (Primary School)',
    'Future Nation Schools Fleurhof (High School)',
    'Excelsior Learning Centre High School',
    'Excelsior Aanlynleersentrum / Online Learning centre',
    'Dainfern Preparatory School',
    'Curro Thatchfield High School',
    'Chartwell House Montessori Eco Pre-School',
    'Canterbury Pre-School',
    'Bateleur College Primary School',
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
 * Short payment reference (MAX 10 chars).
 * Format: NGD + 7 hex chars = 10 chars total.
 * Example: NGD7F3A91C
 */
function ngd_generate_payment_reference_short(): string
{
    return 'NGD' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 7)); // 3 + 7 = 10 chars
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
 * Ensure renewal reference exists for the author.
 * - If any published listing has _renewal_reference, reuse it.
 * - Else create short ref and write to the matched post.
 */
function ngd_get_or_create_author_reference(int $user_id, int $fallback_post_id): array
{
    global $wpdb;

    $sql = $wpdb->prepare(
        "SELECT pm.meta_value AS ref
         FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE p.post_type='job_listing'
           AND p.post_status='publish'
           AND p.post_author=%d
           AND pm.meta_key='_renewal_reference'
           AND pm.meta_value <> ''
         ORDER BY p.post_modified_gmt DESC
         LIMIT 1",
        $user_id
    );
    $existing = $wpdb->get_var($sql);
    $existing = is_string($existing) ? trim($existing) : '';

    if ($existing !== '') {
        return ['ref' => $existing, 'created' => false, 'notes' => 'Existing ref found'];
    }

    $new_ref = ngd_generate_payment_reference_short();

    if ($fallback_post_id > 0) {
        update_post_meta($fallback_post_id, '_renewal_reference', $new_ref);
        update_post_meta($fallback_post_id, '_renewal_reference_issued_ts', time());
        update_post_meta($fallback_post_id, '_renewal_reference_source', 'prod');
    }

    return ['ref' => $new_ref, 'created' => true, 'notes' => 'New short ref generated and saved'];
}

/**
 * Stamp invoice markers so the dashboard reflects “in process”.
 * This is the key fix: always mark invoice sent on SEND (even if ref already existed).
 */
function ngd_stamp_invoice_sent(int $post_id): void
{
    if ($post_id <= 0)
        return;

    $now = time();
    update_post_meta($post_id, '_invoice_sent_timestamp', $now);

    $key = '_sent_invoice_' . date('Ymd', $now);
    update_post_meta($post_id, $key, 1);

    // Ensure dashboard picks this up as "INVOICED / DUE"
    update_post_meta($post_id, '_payment_status', 'DUE');
}

$results = [];
$sent_ok = 0;
$sent_fail = 0;
$skipped = 0;

$subject = 'SA Private Schools – Premium listing audit & 28 days to renew';

foreach ($schools as $school) {
    $m = ngd_match_school_to_post_and_email($school);
    $to = $m['email'];
    $can_send = ($m['mode'] !== 'NONE' && $to !== '' && $m['user_id'] > 0 && $m['post_id'] > 0);

    $ref_info = ['ref' => '', 'created' => false, 'notes' => ''];
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
        $bank_lines[] = 'SWIFT: ' . esc_html($banking['swift']);

    $bank_html = '<div style="background:#f8fafc;border:1px solid #e2e8f0;padding:12px;border-radius:12px;margin:12px 0;">'
        . '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#0b1220;">'
        . implode('<br>', $bank_lines)
        . '</div></div>';

    $body = '
    <div style="font-family:Arial,Helvetica,sans-serif;color:#0b1220;line-height:1.55">
      <p>Hello ' . esc_html($school) . ',</p>

      <p>We’ve recently moved SA Private Schools onto an automated renewals system and ran a full audit of premium listings.</p>

      <p>It looks like your profile has remained on <strong>premium</strong> for some time without an annual renewal being settled. This is almost certainly an oversight on our side — and we’ll waive the historic premium access you’ve received.</p>

      <p><strong>You can renew immediately via EFT using this payment reference:</strong><br>
      ' . $ref_html . '</p>

      ' . $bank_html . '

      <p><strong>Renewal terms:</strong></p>
      <ol>
        <li>You have <strong>28 days</strong> to settle the renewal.</li>
        <li>If payment is not received by then, your profile will be <strong>downgraded automatically</strong> to the free tier.</li>
      </ol>

      <p><strong>Need the official invoice?</strong><br>
      You can view/download your invoice here: <a href="' . esc_url($invoice_link) . '">' . esc_html($invoice_link) . '</a></p>

      <p><strong>If downgraded, you’ll lose:</strong> premium placement, enhanced visibility, and premium-only exposure features — your listing remains visible, but on the free tier.</p>

      <p>If you believe this is incorrect, simply reply to this email and we’ll sort it out quickly.</p>

      <p>Kind regards,<br>
      <strong>Taryn</strong><br>
      SA Private Schools</p>
      ' . $sig_html . '
    </div>';

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $from_name . ' <' . $from_email . '>',
        'Cc: ' . $cc_email,
    ];

    if (!$do_send) {
        $results[] = [
            'school' => $school,
            'match' => $m['mode'],
            'email' => $to ?: '(missing)',
            'ref' => $ref_info['ref'] ?: '(n/a)',
            'invoice_link' => $invoice_link ?: '(n/a)',
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
            'email' => $to ?: '(missing)',
            'ref' => $ref_info['ref'] ?: '(n/a)',
            'invoice_link' => $invoice_link ?: '(n/a)',
            'status' => 'SKIPPED',
            'notes' => $m['notes'] ?: 'No match/email',
        ];
        continue;
    }

    // ✅ Stamp invoice markers BEFORE sending so dashboard reflects immediately
    ngd_stamp_invoice_sent((int) $m['post_id']);

    $ok = wp_mail($to, $subject, $body, $headers);

    if ($ok) {
        $sent_ok++;
        $results[] = [
            'school' => $school,
            'match' => $m['mode'],
            'email' => $to,
            'ref' => $ref_info['ref'],
            'invoice_link' => $invoice_link,
            'status' => 'SENT ✅',
            'notes' => trim(($m['notes'] ? $m['notes'] . '; ' : '') . ($ref_info['created'] ? 'ref created; stamped invoice' : 'ref reused; stamped invoice'))
        ];
    } else {
        $sent_fail++;
        $results[] = [
            'school' => $school,
            'match' => $m['mode'],
            'email' => $to,
            'ref' => $ref_info['ref'],
            'invoice_link' => $invoice_link,
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
                <th>Invoice Link</th>
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
                    <td><?php echo esc_html($r['school']); ?></td>
                    <td><?php echo esc_html($r['match']); ?></td>
                    <td><?php echo esc_html($r['email']); ?></td>
                    <td><?php echo esc_html($r['ref'] ?? '(n/a)'); ?></td>
                    <td><?php echo esc_html($r['invoice_link'] ?? '(n/a)'); ?></td>
                    <td><strong><?php echo esc_html($r['status']); ?></strong></td>
                    <td><?php echo esc_html($r['notes']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <p class="meta">Delete this file after use.</p>
</body>

</html>