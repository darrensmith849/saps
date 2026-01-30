<?php
/**
 * Plugin Name: NGD Renewals Dashboard
 * Description: Admin-only, front-end styled renewals dashboard (author-level, no WP admin styling).
 * Version: 1.1.1
 */

if (!defined('ABSPATH')) exit;

final class NGD_Renewals_Dashboard {

    // IMPORTANT: Your package IDs
    private int $paid_package_id = 247687;
    private int $free_package_id = 138;

    // Pagination defaults
    private int $default_per_page = 50;
    private int $max_per_page = 100;

    public function __construct() {
        add_action('init', [$this, 'register_routes']);
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_action('template_redirect', [$this, 'maybe_render_dashboard']);

        register_activation_hook(__FILE__, [$this, 'on_activate']);
        register_deactivation_hook(__FILE__, [$this, 'on_deactivate']);
    }

    public function on_activate(): void {
        $this->register_routes();
        flush_rewrite_rules();
    }

    public function on_deactivate(): void {
        flush_rewrite_rules();
    }

    public function register_routes(): void {
        add_rewrite_rule('^renewals/?$', 'index.php?ngd_renewals=1', 'top');
        add_rewrite_rule('^renewals/export/?$', 'index.php?ngd_renewals=1&ngd_renewals_export=1', 'top');
    }

    public function register_query_vars(array $vars): array {
        $vars[] = 'ngd_renewals';
        $vars[] = 'ngd_renewals_export';

        $vars[] = 'ngd_status';
        $vars[] = 'ngd_q';

        $vars[] = 'ngd_issue'; // NEW

        $vars[] = 'ngd_sort';
        $vars[] = 'ngd_dir';

        $vars[] = 'ngd_page';
        $vars[] = 'ngd_per_page';

        return $vars;
    }


    public function maybe_render_dashboard(): void {
        if (intval(get_query_var('ngd_renewals')) !== 1) return;

        // Reduce cache-related auth weirdness (login loops often involve caching/session)
        nocache_headers();

        // SAFER THAN auth_redirect(): never forces redirect loops.
        if (!is_user_logged_in()) {
            $this->render_login_required();
            exit;
        }

        // Admin only
        if (!current_user_can('manage_options')) {
            status_header(403);
            echo 'Forbidden';
            exit;
        }

        $is_export = intval(get_query_var('ngd_renewals_export')) === 1;
        if ($is_export) {
            $this->render_export_csv();
            exit;
        }

        $data = $this->get_dashboard_data();
        $this->render_dashboard_html($data);
        exit;
    }

    private function render_login_required(): void {
        status_header(401);
        nocache_headers();

        // Redirect back to /renewals after successful login
        $redirect_to = home_url('/renewals');
        $login_url = wp_login_url($redirect_to);

        ?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Login required</title>
    <style>
        :root{
            --bg:#ffffff; --text:#0b1220; --muted:#64748b; --border:#e2e8f0; --soft:#f8fafc;
            --shadow:0 14px 34px rgba(2,6,23,.08); --radius:18px;
            --blue:#2563eb; --blueSoft:#eff6ff;
        }
        *{box-sizing:border-box}
        body{
            margin:0;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial;
            color:var(--text);
            background: linear-gradient(180deg, #ffffff 0%, #fbfdff 55%, #ffffff 100%);
        }
        .wrap{min-height:100vh;display:grid;place-items:center;padding:24px}
        .card{
            width:min(520px, 100%);
            border:1px solid var(--border);
            border-radius:var(--radius);
            padding:22px;
            background:#fff;
            box-shadow:var(--shadow);
        }
        h1{margin:0 0 8px 0;font-size:22px}
        p{margin:0 0 16px 0;color:var(--muted);line-height:1.45}
        .btn{
            display:inline-flex;align-items:center;justify-content:center;
            padding:12px 14px;border-radius:14px;
            background:var(--blue);color:#fff;text-decoration:none;font-weight:750;
            box-shadow:0 12px 24px rgba(37,99,235,.18);
        }
        .link{margin-left:12px;color:var(--blue);text-decoration:none;font-weight:650}
        .small{margin-top:14px;font-size:12px;color:var(--muted)}
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>Login required</h1>
            <p>This renewals dashboard is admin-only. Please log in, then you’ll be returned to <strong>/renewals</strong>.</p>
            <a class="btn" href="<?php echo esc_url($login_url); ?>">Log in</a>
            <a class="link" href="<?php echo esc_url(home_url('/')); ?>">Back to site</a>
            <div class="small">If you keep seeing login issues, it’s almost always a cache/security cookie rule. This page avoids redirect loops by design.</div>
        </div>
    </div>
</body>
</html><?php
    }

    /* =========================================================
     * DATA LAYER (AUTHOR-LEVEL)
     * ========================================================= */

    private function get_dashboard_data(): array {
        $q = trim((string) get_query_var('ngd_q'));
        $status = strtoupper(trim((string) get_query_var('ngd_status')));

        $issue = strtoupper(trim((string) get_query_var('ngd_issue'))); // (ALL | MISSING_EXPIRY | DUE_NOT_INVOICED)

        $sort = strtolower(trim((string) get_query_var('ngd_sort')));
        $dir  = strtolower(trim((string) get_query_var('ngd_dir'))) === 'asc' ? 'asc' : 'desc';

        $page = max(1, intval(get_query_var('ngd_page')));
        $per_page = intval(get_query_var('ngd_per_page'));
        if (!in_array($per_page, [$this->default_per_page, $this->max_per_page], true)) {
            $per_page = $this->default_per_page;
        }

        // Fetch all published listings
        $listing_ids = get_posts([
            'post_type'      => 'job_listing',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ]);

        $now_ts = current_time('timestamp');

        // Build author aggregates
        $authors = []; // keyed by user_id

        foreach ($listing_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post) continue;

            $owner_id = (int) $post->post_author;
            if ($owner_id <= 0) continue;

            if (!isset($authors[$owner_id])) {
                $owner = get_user_by('id', $owner_id);
                $authors[$owner_id] = [
                    'user_id' => $owner_id,
                    'owner_name' => $owner ? ($owner->display_name ?: ('User #' . $owner_id)) : ('User #' . $owner_id),
                    'owner_email' => $owner ? (string)$owner->user_email : '',
                    'school' => $this->derive_school_name_for_author($owner_id),

                    'listing_ids' => [],
                    'listing_count' => 0,

                    // Premium / billing signals (ANY listing)
                    'is_current_premium' => false,
                    'has_paid_signal' => false,

                    // Renewal signals (ANY listing)
                    'renewal_ref' => '',
                    'has_renewal_ref' => false,

                    // Downgrade signals (ANY listing, ANY YEAR)
                    'has_downgrade_final' => false,

                    // Email tracking (ANY listing)
                    'invoice_seen_any' => false,
                    'last_seen_ts_max' => 0,
                    'last_seen_raw_max' => '',
                    'invoice_sent_ts_max' => 0,

                    // Timeline flags (ANY listing, ANY YEAR)
                    'flag_invoice' => false,
                    'flag_r14' => false,
                    'flag_r07' => false,
                    'flag_r03' => false,
                    'flag_warn' => false,
                    'flag_final' => false,

                    // Downgrade-final send date (derived from meta key suffix _sent_downgrade_final_YYYYMMDD...)
                    'downgrade_final_ts_max' => 0,

                    // Latest modified timestamp across listings (READ-ONLY fallback for “estimated downgrade date”)
                    'latest_modified_ts_max' => 0,

                    // Expiry (LATEST across listings)
                    'expires_max_ts' => 0,
                    'expires_max_raw' => '',

                    // For debugging / future (not displayed)
                    'expires_min_ts' => 0,
                    'expires_min_raw' => '',
                ];
            }

            $a = &$authors[$owner_id];
            $a['listing_ids'][] = $post_id;
            $a['listing_count']++;

            // Track latest modified time (server-based). This is READ-ONLY and safe.
            $modified_ts = (int) get_post_modified_time('U', true, $post_id);
            if ($modified_ts > $a['latest_modified_ts_max']) {
                $a['latest_modified_ts_max'] = $modified_ts;
            }

            // Listing-level meta
            $package_id = (int) get_post_meta($post_id, '_package_id', true);
            $featured   = (string) get_post_meta($post_id, '_featured', true) === '1';

            $payment_status = strtoupper(trim((string) get_post_meta($post_id, '_payment_status', true)));
            $is_paid_signal = ($payment_status === 'PAID');

            // Current premium signal (any listing is in paid package OR featured OR paid status)
            if ($package_id === $this->paid_package_id || $featured || $is_paid_signal) {
                $a['is_current_premium'] = true;
            }
            if ($is_paid_signal) {
                $a['has_paid_signal'] = true;
            }

            // Renewal reference (strict invoiced trigger)
            $renewal_ref = trim((string) get_post_meta($post_id, '_renewal_reference', true));
            if ($renewal_ref !== '') {
                $a['has_renewal_ref'] = true;
                if ($a['renewal_ref'] === '') {
                    $a['renewal_ref'] = $renewal_ref;
                }
            }

            // Email tracking
            $invoice_seen = (string) get_post_meta($post_id, '_invoice_seen_status', true) === 'yes';
            if ($invoice_seen) $a['invoice_seen_any'] = true;

            $last_seen_raw = trim((string) get_post_meta($post_id, '_invoice_last_seen', true));
            $last_seen_ts = $last_seen_raw !== '' ? $this->parse_datetime_to_ts($last_seen_raw) : 0;
            if ($last_seen_ts > $a['last_seen_ts_max']) {
                $a['last_seen_ts_max'] = $last_seen_ts;
                $a['last_seen_raw_max'] = $last_seen_raw;
            }

            $invoice_sent_ts = (int) get_post_meta($post_id, '_invoice_sent_timestamp', true);
            if ($invoice_sent_ts > $a['invoice_sent_ts_max']) {
                $a['invoice_sent_ts_max'] = $invoice_sent_ts;
            }

            // Expiry parsing
            $expires_raw = trim((string) get_post_meta($post_id, '_job_expires', true));
            $expires_ts = $expires_raw !== '' ? $this->parse_expiry_to_ts($expires_raw) : 0;

            // LATEST expiry
            if ($expires_ts > $a['expires_max_ts']) {
                $a['expires_max_ts'] = $expires_ts;
                $a['expires_max_raw'] = $expires_raw;
            }

            // Optional: MIN expiry
            if ($expires_ts > 0 && ($a['expires_min_ts'] === 0 || $expires_ts < $a['expires_min_ts'])) {
                $a['expires_min_ts'] = $expires_ts;
                $a['expires_min_raw'] = $expires_raw;
            }

            // Timeline flags (ANY YEAR)
            $all_meta = get_post_meta($post_id);
            foreach ($all_meta as $key => $vals) {
                if (!$this->meta_truthy($vals)) continue;

                if ($this->starts_with($key, '_sent_invoice_')) $a['flag_invoice'] = true;
                if ($this->starts_with($key, '_sent_reminder_14_')) $a['flag_r14'] = true;
                if ($this->starts_with($key, '_sent_reminder_07_')) $a['flag_r07'] = true;
                if ($this->starts_with($key, '_sent_reminder_03_')) $a['flag_r03'] = true;
                if ($this->starts_with($key, '_sent_downgrade_warn_')) $a['flag_warn'] = true;

                if ($this->starts_with($key, '_sent_downgrade_final_')) {
                    $a['flag_final'] = true;
                    $a['has_downgrade_final'] = true;

                    // Extract YYYYMMDD from anywhere in the key after _sent_downgrade_final_
                    if (preg_match('/_sent_downgrade_final_(\d{8})/', $key, $m)) {
                        $ts = $this->parse_expiry_to_ts($m[1]); // midnight local
                        if ($ts > $a['downgrade_final_ts_max']) {
                            $a['downgrade_final_ts_max'] = $ts;
                        }
                    }
                }
            }

            // If package is explicitly free and we have renewal history, treat as downgrade evidence too (date may still be unknown)
            if ($package_id === $this->free_package_id && $a['has_renewal_ref']) {
                $a['has_downgrade_final'] = true;
            }

            unset($a);
        }

        // Convert to rows (only include commercially relevant authors)
        $rows = [];
        $kpi = [
            'PAID' => 0,
            'INVOICED' => 0,
            'DOWNGRADED' => 0,
            'MISSING_EXPIRY' => 0,
            'DUE_NOT_INVOICED' => 0,
        ];

        foreach ($authors as $user_id => $a) {

            // INCLUDE RULE
            $include = ($a['is_current_premium'] || $a['has_renewal_ref'] || $a['has_downgrade_final']);
            if (!$include) continue;

            // Days to expiry (based on LATEST expiry)
            $days_to_expiry = null;
            if ($a['expires_max_ts'] > 0) {
                $days_to_expiry = (int) floor(($a['expires_max_ts'] - $now_ts) / DAY_IN_SECONDS);
            }

            $missing_expiry = ($a['expires_max_ts'] <= 0);

            // Renewal window
            $in_renewal_window = (!$missing_expiry && $days_to_expiry !== null && $days_to_expiry <= 35 && $days_to_expiry >= -8);

            // Status rules
            $ui_status = 'DOWNGRADED';
            if (($a['is_current_premium'] || $a['has_paid_signal'])) {
                $ui_status = 'PAID';
            } elseif ($a['has_renewal_ref'] && $in_renewal_window) {
                $ui_status = 'INVOICED';
            } else {
                $ui_status = 'DOWNGRADED';
            }

            // Payment label (UI-only)
            if ($ui_status === 'PAID') $payment_label = 'PAID';
            elseif ($ui_status === 'INVOICED') $payment_label = 'DUE';
            else $payment_label = 'DOWNGRADED';

            // Days metric + label
            $days_metric = null;
            $days_label = '—';
            $days_is_estimated = false;

            if ($ui_status === 'DOWNGRADED') {
                $downgrade_ts = 0;

                // (a) best: real downgrade-final meta date
                if (!empty($a['downgrade_final_ts_max']) && (int)$a['downgrade_final_ts_max'] > 0) {
                    $downgrade_ts = (int)$a['downgrade_final_ts_max'];
                }
                // (b) fallback: expiry + 8 days if expiry exists and is already in the past
                elseif (!$missing_expiry && (int)$a['expires_max_ts'] > 0) {
                    $fallback = (int)$a['expires_max_ts'] + 8 * DAY_IN_SECONDS;
                    if ($now_ts >= $fallback) $downgrade_ts = $fallback;
                }
                // (c) fallback: latest modified (estimated)
                elseif (!empty($a['latest_modified_ts_max']) && (int)$a['latest_modified_ts_max'] > 0) {
                    $downgrade_ts = (int)$a['latest_modified_ts_max'];
                    $days_is_estimated = true;
                }

                if ($downgrade_ts > 0) {
                    $days_since = (int) floor(($now_ts - $downgrade_ts) / DAY_IN_SECONDS);
                    $days_metric = -abs($days_since);
                    $days_label = (string)$days_metric; // negative value
                } else {
                    $days_metric = null;
                    $days_label = '—';
                }
            } else {
                // PAID / INVOICED: normal expiry countdown
                $days_metric = $days_to_expiry;
                if ($days_to_expiry === null) {
                    $days_label = '—';
                } else {
                    $days_label = ($days_to_expiry > 0) ? ('+' . $days_to_expiry) : (string)$days_to_expiry;
                }
            }

            // Alerts
            $alert_missing_expiry = (($a['is_current_premium'] || $a['has_paid_signal']) && $missing_expiry);
            $alert_due_not_invoiced = (
                ($a['is_current_premium'] || $a['has_paid_signal']) &&
                !$missing_expiry &&
                $days_to_expiry !== null &&
                $days_to_expiry <= 30 &&
                $days_to_expiry >= -8 &&
                !$a['has_renewal_ref']
            );

            if ($alert_missing_expiry) $kpi['MISSING_EXPIRY']++;
            if ($alert_due_not_invoiced) $kpi['DUE_NOT_INVOICED']++;

            // Filters
            if ($status && $status !== 'ALL' && $ui_status !== $status) continue;

            if ($issue && $issue !== 'ALL') {
                if ($issue === 'MISSING_EXPIRY' && empty($alert_missing_expiry)) continue;
                if ($issue === 'DUE_NOT_INVOICED' && empty($alert_due_not_invoiced)) continue;
            }

            if ($q !== '') {
                $hay = strtolower(
                    ($a['school'] ?? '') . ' ' .
                    ($a['owner_name'] ?? '') . ' ' .
                    ($a['owner_email'] ?? '') . ' ' .
                    ($a['renewal_ref'] ?? '') . ' ' .
                    $user_id
                );
                if (strpos($hay, strtolower($q)) === false) continue;
            }

            // KPI
            if (isset($kpi[$ui_status])) $kpi[$ui_status]++;

            // Build timeline
            $timeline = $this->build_timeline_author(
                $a,
                $a['expires_max_ts'],
                $a['invoice_sent_ts_max'],
                $a['invoice_seen_any'],
                $a['last_seen_ts_max'],
                $a['last_seen_raw_max']
            );

            $rows[] = [
                'user_id' => $user_id,
                'school' => $a['school'],
                'owner_name' => $a['owner_name'],
                'owner_email' => $a['owner_email'],

                'status' => $ui_status,
                'payment' => $payment_label,

                'alert_missing_expiry' => (bool)$alert_missing_expiry,
                'alert_due_not_invoiced' => (bool)$alert_due_not_invoiced,

                'opened_any' => (bool) $a['invoice_seen_any'],
                'invoice_sent' => $a['invoice_sent_ts_max'] ? date('Y-m-d H:i', $a['invoice_sent_ts_max']) : '—',
                'last_seen' => $a['last_seen_ts_max'] ? date('Y-m-d H:i', $a['last_seen_ts_max']) : ($a['last_seen_raw_max'] ?: '—'),

                'expires_date' => $a['expires_max_ts'] ? date('Y-m-d', $a['expires_max_ts']) : '—',
                'days_metric' => $days_metric,
                'days_label' => $days_label,
                'days_is_estimated' => (bool)$days_is_estimated,

                'renewal_ref' => $a['has_renewal_ref'] ? ($a['renewal_ref'] ?: '—') : '—',

                'timeline' => $timeline,

                'admin_url' => admin_url('edit.php?post_type=job_listing&author=' . $user_id),
            ];
        }

        // Sort
        $rows = $this->sort_rows($rows, $sort, $dir);

        // Pagination
        $total = count($rows);
        $total_pages = max(1, (int) ceil($total / $per_page));
        if ($page > $total_pages) $page = $total_pages;

        $offset = ($page - 1) * $per_page;
        $paged_rows = array_slice($rows, $offset, $per_page);

        $selected = $paged_rows[0] ?? null;

        return [
            'kpi' => $kpi,
            'rows' => $paged_rows,
            'selected' => $selected,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $per_page,
                'total_pages' => $total_pages,
            ],
            'filters' => [
                'q' => $q,
                'status' => $status ?: 'ALL',
                'issue' => $issue ?: 'ALL',
                'sort' => $sort ?: 'days',
                'dir' => $dir ?: 'asc',
            ],
        ];
    }

    private function derive_school_name_for_author(int $user_id): string {
        $latest = get_posts([
            'post_type'      => 'job_listing',
            'post_status'    => 'publish',
            'author'         => $user_id,
            'posts_per_page' => 1,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        if (!empty($latest[0])) {
            return (string) get_the_title((int)$latest[0]);
        }

        $u = get_user_by('id', $user_id);
        return $u ? ($u->display_name ?: ('User #' . $user_id)) : ('User #' . $user_id);
    }

    private function sort_rows(array $rows, string $sort, string $dir): array {
        $sort = $sort ?: 'days';
        $dir = ($dir === 'asc') ? 'asc' : 'desc';

        $status_rank = [
            'PAID' => 1,
            'INVOICED' => 2,
            'DOWNGRADED' => 3,
        ];

        usort($rows, function($a, $b) use ($sort, $dir, $status_rank) {
            $cmp = 0;

            switch ($sort) {
                case 'school':
                    $cmp = strcasecmp((string)$a['school'], (string)$b['school']);
                    break;

                case 'status':
                    $ra = $status_rank[$a['status']] ?? 99;
                    $rb = $status_rank[$b['status']] ?? 99;
                    $cmp = $ra <=> $rb;
                    break;

                case 'payment':
                    $pa = ($a['payment'] === 'PAID') ? 1 : 2;
                    $pb = ($b['payment'] === 'PAID') ? 1 : 2;
                    $cmp = $pa <=> $pb;
                    break;

                case 'opened':
                    $cmp = ((int)!empty($a['opened_any'])) <=> ((int)!empty($b['opened_any']));
                    break;

                case 'days':
                default:
                    $da = $a['days_metric'];
                    $db = $b['days_metric'];

                    if ($da === null && $db === null) $cmp = 0;
                    elseif ($da === null) $cmp = 1;
                    elseif ($db === null) $cmp = -1;
                    else $cmp = ((int)$da) <=> ((int)$db);
                    break;
            }

            if ($cmp === 0) $cmp = strcasecmp((string)$a['school'], (string)$b['school']);
            return ($dir === 'asc') ? $cmp : -$cmp;
        });

        return $rows;
    }

    private function build_timeline_author(array $a, int $expires_ts, int $invoice_sent_ts, bool $opened_any, int $last_seen_ts, string $last_seen_raw): array {
        $items = [];

        $items[] = [
            'key' => 'invoice',
            'label' => 'Invoice sent',
            'sent' => (bool)($a['flag_invoice'] || $invoice_sent_ts > 0 || $a['has_renewal_ref']),
            'date' => $invoice_sent_ts ? date('Y-m-d', $invoice_sent_ts) : '—',
        ];

        $items[] = [
            'key' => 'opened',
            'label' => 'Opened (any)',
            'sent' => $opened_any,
            'date' => $last_seen_ts ? date('Y-m-d', $last_seen_ts) : ($last_seen_raw ?: '—'),
        ];

        $items[] = [
            'key' => 'r14',
            'label' => 'Reminder 14',
            'sent' => (bool)$a['flag_r14'],
            'date' => $expires_ts ? date('Y-m-d', $expires_ts - 14 * DAY_IN_SECONDS) : '—',
        ];

        $items[] = [
            'key' => 'r07',
            'label' => 'Reminder 7',
            'sent' => (bool)$a['flag_r07'],
            'date' => $expires_ts ? date('Y-m-d', $expires_ts - 7 * DAY_IN_SECONDS) : '—',
        ];

        $items[] = [
            'key' => 'r03',
            'label' => 'Reminder 3',
            'sent' => (bool)$a['flag_r03'],
            'date' => $expires_ts ? date('Y-m-d', $expires_ts - 3 * DAY_IN_SECONDS) : '—',
        ];

        $items[] = [
            'key' => 'warn',
            'label' => 'Downgrade warn',
            'sent' => (bool)$a['flag_warn'],
            'date' => $expires_ts ? date('Y-m-d', $expires_ts + 1 * DAY_IN_SECONDS) : '—',
        ];

        $items[] = [
            'key' => 'final',
            'label' => 'Downgraded (final)',
            'sent' => (bool)$a['flag_final'],
            'date' => $expires_ts ? date('Y-m-d', $expires_ts + 8 * DAY_IN_SECONDS) : '—',
        ];

        return $items;
    }

    private function parse_expiry_to_ts(string $raw): int {
        $raw = trim($raw);
        if ($raw === '') return 0;

        if (ctype_digit($raw)) {
            if (strlen($raw) === 8) {
                $y = substr($raw, 0, 4);
                $m = substr($raw, 4, 2);
                $d = substr($raw, 6, 2);
                $ts = strtotime($y . '-' . $m . '-' . $d . ' 00:00:00');
                return $ts ? (int)$ts : 0;
            }
            if (strlen($raw) >= 10) return (int)$raw;
        }

        $ts = strtotime($raw);
        return $ts ? (int)$ts : 0;
    }

    private function parse_datetime_to_ts(string $raw): int {
        $raw = trim($raw);
        if ($raw === '') return 0;
        $ts = strtotime($raw);
        return $ts ? (int)$ts : 0;
    }

    private function starts_with(string $haystack, string $prefix): bool {
        return strncmp($haystack, $prefix, strlen($prefix)) === 0;
    }

    private function meta_truthy($vals): bool {
        if (!is_array($vals) || empty($vals)) return false;
        $v = $vals[0] ?? '';
        if (is_array($v)) return !empty($v);
        $v = strtolower(trim((string)$v));
        return ($v !== '' && $v !== '0' && $v !== 'false' && $v !== 'no');
    }

    /* =========================================================
     * EXPORT
     * ========================================================= */

    private function render_export_csv(): void {
        $data = $this->get_dashboard_data();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=renewals-export-authors.csv');

        $out = fopen('php://output', 'w');

        fputcsv($out, [
            'User ID', 'School', 'Owner', 'Owner Email',
            'Status', 'Payment', 'Opened (Any)', 'Days', 'Expiry Date',
            'Renewal Reference', 'Invoice Sent', 'Last Seen'
        ]);

        foreach ($data['rows'] as $r) {
            fputcsv($out, [
                $r['user_id'],
                $r['school'],
                $r['owner_name'],
                $r['owner_email'],
                $r['status'],
                $r['payment'],
                $r['opened_any'] ? 'Yes' : 'No',
                $r['days_label'] ?? '—',
                $r['expires_date'],
                $r['renewal_ref'],
                $r['invoice_sent'],
                $r['last_seen'],
            ]);
        }

        fclose($out);
    }

    /* =========================================================
     * UI RENDER
     * ========================================================= */

    private function render_dashboard_html(array $data): void {
        $rows = $data['rows'];
        $selected = $data['selected'];
        $filters = $data['filters'];
        $meta = $data['meta'];

        $q = esc_attr($filters['q']);
        $status = esc_attr($filters['status']);
        $sort = esc_attr($filters['sort']);
        $dir = esc_attr($filters['dir']);

        $base_url = home_url('/renewals');

        $export_url = home_url('/renewals/export') . $this->build_query_string([
            'ngd_q' => $filters['q'],
            'ngd_status' => $filters['status'],
            'ngd_issue' => $filters['issue'] ?? 'ALL',
            'ngd_sort' => $filters['sort'],
            'ngd_dir' => $filters['dir'],
            'ngd_page' => $meta['page'],
            'ngd_per_page' => $meta['per_page'],
        ]);


        $json_selected = wp_json_encode($selected);

        ?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Renewals</title>
    <style>
        :root{
            --bg:#ffffff; --text:#0b1220; --muted:#64748b; --border:#e2e8f0; --soft:#f8fafc;
            --shadow:0 14px 34px rgba(2,6,23,.08); --radius:18px;

            --blue:#2563eb; --blueSoft:#eff6ff;
            --green:#16a34a; --greenSoft:#ecfdf5;
            --amber:#f59e0b; --amberSoft:#fffbeb;
            --red:#ef4444; --redSoft:#fef2f2;
            --slateSoft:#f1f5f9;
        }
        *{box-sizing:border-box}
        html,body{height:100%}
        body{
            margin:0;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial;
            color:var(--text);
            background: linear-gradient(180deg, #ffffff 0%, #fbfdff 55%, #ffffff 100%);
        }
        a{color:inherit;text-decoration:none}
        .wrap{display:flex;min-height:100vh}
        .main{flex:1;padding:28px 28px 18px 28px}
        .drawer{
            width:420px; max-width:42vw;
            border-left:1px solid var(--border);
            background:#fff;
            position:sticky; top:0;
            height:100vh;
            overflow:auto;
            padding:22px;
        }

        .topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:22px}
        .brand{font-weight:700;letter-spacing:.2px}
        .tabs{display:flex;gap:18px;align-items:center}
        .tab{font-size:14px;color:var(--muted);padding:10px 6px;position:relative}
        .tab.active{color:var(--text);font-weight:650}
        .tab.active:after{content:"";position:absolute;left:6px;right:6px;bottom:2px;height:2px;background:var(--blue);border-radius:99px}
        .user{display:flex;align-items:center;gap:10px;color:var(--muted);font-size:14px}
        .avatar{width:30px;height:30px;border-radius:999px;background:var(--slateSoft);display:grid;place-items:center;font-weight:800;color:#0b1220}

        .h1{font-size:42px;line-height:1.05;margin:0 0 6px 0}
        .sub{color:var(--muted);margin:0 0 22px 0}

        .controls{display:flex;gap:14px;align-items:center;justify-content:space-between;margin-bottom:18px}
        .controlsLeft{display:flex;gap:14px;align-items:center;flex-wrap:wrap}
        .pill{
            display:flex;gap:10px;align-items:center;
            border:1px solid var(--border); border-radius:999px;
            padding:12px 14px; min-width:320px;
            background:#fff;
            box-shadow: 0 1px 0 rgba(2,6,23,.04);
        }
        .pill input{border:none;outline:none;width:100%;font-size:14px}
        .select{
            border:1px solid var(--border); border-radius:999px;
            padding:12px 14px; background:#fff;
            font-size:14px; color:var(--text); min-width:190px;
            box-shadow: 0 1px 0 rgba(2,6,23,.04);
        }
        .btn{border:none;border-radius:999px;padding:12px 16px;font-size:14px;cursor:pointer}
        .btn.primary{background:var(--blue);color:#fff;box-shadow:0 12px 24px rgba(37,99,235,.18)}
        .btn.icon{width:44px;height:44px;border:1px solid var(--border);background:#fff;border-radius:999px}

        .kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:18px 0}
        .card{
            border:1px solid var(--border);
            border-radius:var(--radius);
            padding:16px;
            background:#fff;
            box-shadow: var(--shadow);
        }
        .krow{display:flex;align-items:center;gap:12px}
        .kicon{
            width:38px;height:38px;border-radius:14px;
            background:var(--soft);
            display:grid;place-items:center;
            border:1px solid var(--border);
        }
        .knum{font-size:28px;font-weight:800;line-height:1}
        .klabel{color:var(--muted);font-size:13px;margin-top:3px}

        .grid{
            border:1px solid var(--border);
            border-radius:var(--radius);
            overflow:hidden;
            background:#fff;
            box-shadow: var(--shadow);
        }

        .head,.row{
            display:grid;
            grid-template-columns: 1.6fr .9fr .7fr .7fr .8fr .3fr;
            gap:12px;
            align-items:center;
            padding:14px 16px;
        }

        .head{
            color:var(--muted);
            font-size:12px;
            border-bottom:1px solid var(--border);
            background:#fff;
            position:sticky;
            top:0;
            z-index:10;
        }

        .hcell{
            display:flex; align-items:center; gap:8px;
            cursor:pointer;
            user-select:none;
        }
        .hcell.noSort{cursor:default}
        .sortIcon{opacity:.55; font-size:12px}
        .sortIcon.on{opacity:1;color:var(--text)}

        .row{border-bottom:1px solid var(--border);cursor:pointer}
        .row:last-child{border-bottom:none}
        .row:hover{background:var(--soft)}
        .school{font-weight:700}
        .meta{color:var(--muted);font-size:12px;margin-top:4px}

        .badge{
            display:inline-flex;gap:8px;align-items:center;
            border-radius:999px;padding:8px 10px;
            font-size:12px;font-weight:700;
            border:1px solid transparent;
            white-space:nowrap;
        }

        /* NEW alert pills */
        .alertPills{display:flex;gap:8px;margin-top:8px;flex-wrap:wrap}
        .apill{
            display:inline-flex;gap:8px;align-items:center;
            padding:6px 10px;border-radius:999px;
            font-size:12px;font-weight:750;
            border:1px solid var(--border);
            background:#fff;
            color:#0b1220;
        }
        .apill.warn{background:var(--amberSoft);border-color:#fde68a;color:#92400e}
        .apill.bad{background:var(--redSoft);border-color:#fecaca;color:#991b1b}
        .apill.gray{background:var(--slateSoft);border-color:#e2e8f0;color:#334155}

        .b-invoiced{background:var(--amberSoft);color:#92400e;border-color:#fde68a}
        .b-paid{background:var(--greenSoft);color:#166534;border-color:#d1fae5}
        .b-downgraded{background:var(--redSoft);color:#991b1b;border-color:#fecaca}

        .b-pay-paid{background:var(--greenSoft);color:#166534;border-color:#d1fae5}
        .b-pay-due{background:var(--amberSoft);color:#92400e;border-color:#fde68a}
        .b-pay-downgraded{background:var(--redSoft);color:#991b1b;border-color:#fecaca}

        .openIcon{font-size:16px;color:var(--muted)}
        .actionBtn{width:36px;height:36px;border-radius:14px;border:1px solid var(--border);background:#fff;display:grid;place-items:center}

        .foot{
            color:var(--muted);
            font-size:12px;
            margin-top:14px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            flex-wrap:wrap;
        }

        .pager{
            display:flex;align-items:center;gap:10px;flex-wrap:wrap;
        }
        .pager a{
            border:1px solid var(--border);
            background:#fff;
            padding:8px 10px;
            border-radius:999px;
            font-size:13px;
            color:var(--text);
        }
        .pager a.active{
            background:var(--blueSoft);
            border-color:#dbeafe;
            color:#1d4ed8;
            font-weight:700;
        }

        /* Drawer */
        .drawerTop{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:18px}
        .dTitle{font-size:20px;font-weight:850;margin:0}
        .dSub{margin-top:6px;color:var(--muted);font-size:13px}
        .xbtn{border:none;background:transparent;font-size:20px;cursor:pointer;color:var(--muted)}
        .pillRow{display:flex;gap:10px;margin:14px 0 18px 0;flex-wrap:wrap}
        .section{padding:16px 0;border-top:1px solid var(--border)}
        .section:first-of-type{border-top:none}
        .sTitle{font-size:13px;font-weight:850;margin:0 0 10px 0}
        .refBox{display:flex;align-items:center;justify-content:space-between;border:1px solid var(--border);border-radius:14px;padding:12px;background:#fff}
        .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;font-size:13px}
        .copyBtn{border:1px solid var(--border);background:#fff;border-radius:12px;width:38px;height:38px;cursor:pointer}
        .kv{display:grid;grid-template-columns: 1fr 1fr;gap:10px 14px}
        .k{color:var(--muted);font-size:13px}
        .v{font-size:13px;text-align:right}

        .timeline{display:flex;flex-direction:column;gap:10px}
        .tItem{display:flex;align-items:flex-start;gap:10px}
        .tDot{width:10px;height:10px;border-radius:999px;background:#cbd5e1;margin-top:5px;flex:0 0 auto}
        .tDot.on{background:var(--blue)}
        .tLabel{font-size:13px}
        .tDate{margin-left:auto;color:var(--muted);font-size:12px}

        .drawerFooter{display:flex;gap:10px;margin-top:18px}
        .btnWide{flex:1;border-radius:14px;padding:12px 14px;font-weight:750;font-size:13px}
        .btnWide.primary{background:var(--blue);color:#fff;border:none}
        .btnWide.secondary{background:#fff;color:#0b1220;border:1px solid var(--border)}

        @media (max-width: 1100px){
            .wrap{flex-direction:column}
            .drawer{
                width:100%; max-width:none;
                border-left:none; border-top:1px solid var(--border);
                position:relative; height:auto;
            }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="main">
        <div class="topbar">
            <div class="brand">SA Private Schools</div>
            <div class="tabs">
                <div class="tab active">Renewals</div>
                <div class="tab">Settings</div>
            </div>
            <div class="user">
                <div class="avatar">A</div>
                <div><strong style="color:#0b1220;">Admin</strong></div>
            </div>
        </div>

        <h1 class="h1">Renewals</h1>
        <p class="sub">Schools only. Paid → Invoiced (renewal ref) → Downgraded.</p>

        <div class="controls">
            <div class="controlsLeft">
                <div class="pill">
                    <?php echo $this->icon('search'); ?>
                    <input id="q" value="<?php echo $q; ?>" placeholder="Search school, owner, reference…">
                </div>

                <select id="status" class="select">
                    <?php
                        $opts = ['ALL','PAID','INVOICED','DOWNGRADED'];
                        foreach ($opts as $opt) {
                            $sel = ($status === $opt) ? 'selected' : '';
                            $label = ($opt === 'ALL') ? 'Status: All' : 'Status: ' . $opt;
                            echo '<option value="' . esc_attr($opt) . '" ' . $sel . '>' . esc_html($label) . '</option>';
                        }
                    ?>
                </select>

                <select id="issue" class="select" style="min-width:210px;">
                    <?php
                        $issue = strtoupper(trim((string) get_query_var('ngd_issue')));
                        $issue = $issue ?: 'ALL';
                        $issue_opts = [
                            'ALL' => 'Issues: All',
                            'MISSING_EXPIRY' => 'Issues: Missing expiry',
                            'DUE_NOT_INVOICED' => 'Issues: Due but not invoiced',
                        ];
                        foreach ($issue_opts as $val => $label) {
                            $sel = ($issue === $val) ? 'selected' : '';
                            echo '<option value="' . esc_attr($val) . '" ' . $sel . '>' . esc_html($label) . '</option>';
                        }
                    ?>
                </select>

                <select id="per_page" class="select" style="min-width:140px;">
                    <option value="50" <?php echo $meta['per_page'] == 50 ? 'selected' : ''; ?>>50 / page</option>
                    <option value="100" <?php echo $meta['per_page'] == 100 ? 'selected' : ''; ?>>100 / page</option>
                </select>

            </div>

            <div style="display:flex;gap:10px;align-items:center;">
                <a class="btn primary" href="<?php echo esc_url($export_url); ?>">Export CSV</a>
                <button class="btn icon" title="More">⋯</button>
            </div>
        </div>

        <div class="kpis">
            <?php echo $this->kpi_card($this->icon('shield'), (int)$data['kpi']['PAID'], 'Paid'); ?>
            <?php echo $this->kpi_card($this->icon('bolt'), (int)$data['kpi']['INVOICED'], 'Invoiced'); ?>
            <?php echo $this->kpi_card($this->icon('arrowDown'), (int)$data['kpi']['DOWNGRADED'], 'Downgraded'); ?>
        </div>

        <div class="grid">
            <div class="head">
                <?php echo $this->head_cell('School', 'school', $sort, $dir); ?>
                <?php echo $this->head_cell('Status', 'status', $sort, $dir); ?>
                <?php echo $this->head_cell('Payment', 'payment', $sort, $dir); ?>
                <?php echo $this->head_cell('Opened', 'opened', $sort, $dir); ?>
                <?php echo $this->head_cell('Days', 'days', $sort, $dir); ?>
                <div class="hcell noSort" style="justify-content:flex-end;">Action</div>
            </div>

            <?php foreach ($rows as $r): ?>
                <div class="row" data-row='<?php echo esc_attr(wp_json_encode($r)); ?>'>
                    <div>
                        <div class="school"><?php echo esc_html($r['school']); ?></div>
                        <div class="meta"><?php echo esc_html($r['owner_name']); ?> · User #<?php echo esc_html((string)$r['user_id']); ?></div>

                        <?php if (!empty($r['alert_missing_expiry']) || !empty($r['alert_due_not_invoiced'])): ?>
                            <div class="alertPills">
                                <?php if (!empty($r['alert_missing_expiry'])): ?>
                                    <span class="apill gray"><?php echo $this->icon('alert'); ?>Missing expiry</span>
                                <?php endif; ?>
                                <?php if (!empty($r['alert_due_not_invoiced'])): ?>
                                    <span class="apill warn"><?php echo $this->icon('flag'); ?>Due but not invoiced</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div><?php echo $this->status_badge($r['status']); ?></div>
                    <div><?php echo $this->payment_badge($r['payment']); ?></div>
                    <div class="openIcon"><?php echo $r['opened_any'] ? '✓' : '—'; ?></div>
                    <div style="font-weight:750;"><?php echo esc_html($r['days_label'] ?? '—'); ?></div>
                    <div style="display:flex;justify-content:flex-end;"><div class="actionBtn">→</div></div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="foot">
            <div>Admin-only. Author-level dashboard derived from listing meta.</div>

            <div class="pager">
                <?php echo $this->render_pagination($base_url, $filters, $meta); ?>
            </div>
        </div>
    </div>

    <aside class="drawer" id="drawer"></aside>
</div>

<script>
    const selected = <?php echo $json_selected ?: 'null'; ?>;
    const baseUrl = <?php echo wp_json_encode($base_url); ?>;

    function renderDrawer(r){
        if(!r){ document.getElementById('drawer').innerHTML=''; return; }

        const timeline = (r.timeline || []).map(t => `
            <div class="tItem">
                <div class="tDot ${t.sent ? 'on' : ''}"></div>
                <div class="tLabel">${t.label}</div>
                <div class="tDate">${t.date || '—'}</div>
            </div>
        `).join('');

        const ref = r.renewal_ref || '—';

        const statusClass =
            r.status === 'PAID' ? 'b-paid' :
            r.status === 'INVOICED' ? 'b-invoiced' : 'b-downgraded';

        const payClass = r.payment === 'PAID' ? 'b-pay-paid' : 'b-pay-due';

        document.getElementById('drawer').innerHTML = `
            <div class="drawerTop">
                <div>
                    <h2 class="dTitle">${r.school}</h2>
                    <div class="dSub">${r.owner_name} · ${r.owner_email || ''}</div>
                </div>
                <button class="xbtn" onclick="renderDrawer(null)">×</button>
            </div>

            <div class="pillRow">
                <span class="badge ${statusClass}">Status: ${r.status}</span>
                <span class="badge ${payClass}">Payment: ${r.payment}</span>
            </div>

            ${(r.alert_missing_expiry || r.alert_due_not_invoiced) ? `
                <div class="section" style="padding-top:0;">
                    <div class="sTitle">Alerts</div>
                    <div class="alertPills">
                        ${r.alert_missing_expiry ? `<span class="apill gray">Missing expiry</span>` : ``}
                        ${r.alert_due_not_invoiced ? `<span class="apill warn">Due but not invoiced</span>` : ``}
                    </div>
                </div>
            ` : ``}


            <div class="section">
                <div class="sTitle">Renewal Reference</div>
                <div class="refBox">
                    <div class="mono">${ref}</div>
                    <button class="copyBtn" onclick="navigator.clipboard.writeText('${ref.replace(/'/g,"\\'")}')">⧉</button>
                </div>
            </div>

            <div class="section">
                <div class="sTitle">Expiry</div>
                <div class="kv">
                    <div class="k">Days</div><div class="v">${r.days_label || '—'}${r.days_is_estimated ? ' (est.)' : ''}</div>
                    <div class="k">Expiry date</div><div class="v">${r.expires_date || '—'}</div>
                </div>
            </div>

            <div class="section">
                <div class="sTitle">Email Tracking</div>
                <div class="kv">
                    <div class="k">Invoice sent</div><div class="v">${r.invoice_sent || '—'}</div>
                    <div class="k">Opened (any)</div><div class="v">${r.opened_any ? 'Yes' : 'No'}</div>
                    <div class="k">Last seen</div><div class="v">${r.last_seen || '—'}</div>
                </div>
            </div>

            <div class="section">
                <div class="sTitle">Timeline</div>
                <div class="timeline">${timeline || '<div class="k">—</div>'}</div>
            </div>

            <div class="drawerFooter">
                <a class="btnWide primary" href="${r.admin_url}" target="_blank" rel="noopener">Open in WP Admin</a>
                <button class="btnWide secondary" onclick="alert('Next step: wire “Resend” into your Renewal sender endpoint.')">Resend</button>
            </div>
        `;
    }

    function applyFiltersAndReload(extraParams = {}){
        const q = document.getElementById('q').value.trim();
        const status = document.getElementById('status').value;
        const issue = document.getElementById('issue') ? document.getElementById('issue').value : 'ALL';
        const perPage = document.getElementById('per_page').value;

        const params = new URLSearchParams(window.location.search);

        // Reset page whenever filters change
        params.delete('ngd_page');

        if(q) params.set('ngd_q', q); else params.delete('ngd_q');
        if(status && status !== 'ALL') params.set('ngd_status', status); else params.delete('ngd_status');

        if(issue && issue !== 'ALL') params.set('ngd_issue', issue); else params.delete('ngd_issue');
        if(perPage) params.set('ngd_per_page', perPage);

        Object.keys(extraParams).forEach(k => {
            if(extraParams[k] === null) params.delete(k);
            else params.set(k, extraParams[k]);
        });

        window.location.href = baseUrl + (params.toString() ? ('?' + params.toString()) : '');
    }

    document.getElementById('q').addEventListener('keydown', (e)=>{ if(e.key==='Enter') applyFiltersAndReload(); });
    document.getElementById('status').addEventListener('change', ()=>applyFiltersAndReload());
    if (document.getElementById('issue')) {
        document.getElementById('issue').addEventListener('change', ()=>applyFiltersAndReload());
    }
    document.getElementById('per_page').addEventListener('change', ()=>applyFiltersAndReload());

    document.querySelectorAll('.row').forEach(el=>{
        el.addEventListener('click', ()=>{
            const r = JSON.parse(el.getAttribute('data-row'));
            renderDrawer(r);
        });
    });

    // Sorting
    document.querySelectorAll('[data-sort]').forEach(el=>{
        el.addEventListener('click', ()=>{
            const key = el.getAttribute('data-sort');
            const currentSort = '<?php echo esc_js($sort); ?>';
            const currentDir  = '<?php echo esc_js($dir); ?>';

            let nextDir = 'asc';
            if (currentSort === key) {
                nextDir = (currentDir === 'asc') ? 'desc' : 'asc';
            }

            applyFiltersAndReload({ ngd_sort: key, ngd_dir: nextDir });
        });
    });

    renderDrawer(selected);
</script>
</body>
</html><?php
    }

    private function head_cell(string $label, string $sort_key, string $current_sort, string $current_dir): string {
        $is_on = ($current_sort === $sort_key);
        $arrow = '↕';
        if ($is_on) $arrow = ($current_dir === 'asc') ? '↑' : '↓';

        $icon_class = $is_on ? 'sortIcon on' : 'sortIcon';

        return '
            <div class="hcell" data-sort="' . esc_attr($sort_key) . '">
                <span>' . esc_html($label) . '</span>
                <span class="' . esc_attr($icon_class) . '">' . esc_html($arrow) . '</span>
            </div>
        ';
    }

    private function render_pagination(string $base_url, array $filters, array $meta): string {
        $page = (int)$meta['page'];
        $total_pages = (int)$meta['total_pages'];

        if ($total_pages <= 1) return '';

        $html = '';

        $mk = function(int $p) use ($base_url, $filters, $meta) {
            return esc_url($base_url . $this->build_query_string([
                'ngd_q' => $filters['q'],
                'ngd_status' => $filters['status'],
                'ngd_issue' => $filters['issue'] ?? 'ALL',
                'ngd_sort' => $filters['sort'],
                'ngd_dir' => $filters['dir'],
                'ngd_per_page' => $meta['per_page'],
                'ngd_page' => $p,

            ]));
        };

        if ($page > 1) {
            $html .= '<a href="' . $mk($page - 1) . '">Prev</a>';
        }

        $start = max(1, $page - 2);
        $end = min($total_pages, $page + 2);

        if ($start > 1) {
            $html .= '<a href="' . $mk(1) . '">1</a>';
            if ($start > 2) $html .= '<span style="color:#94a3b8;">…</span>';
        }

        for ($p = $start; $p <= $end; $p++) {
            $cls = ($p === $page) ? 'active' : '';
            $html .= '<a class="' . esc_attr($cls) . '" href="' . $mk($p) . '">' . esc_html((string)$p) . '</a>';
        }

        if ($end < $total_pages) {
            if ($end < $total_pages - 1) $html .= '<span style="color:#94a3b8;">…</span>';
            $html .= '<a href="' . $mk($total_pages) . '">' . esc_html((string)$total_pages) . '</a>';
        }

        if ($page < $total_pages) {
            $html .= '<a href="' . $mk($page + 1) . '">Next</a>';
        }

        return $html;
    }

    /* =========================================================
     * UI HELPERS
     * ========================================================= */

    private function kpi_card(string $icon_svg, int $number, string $label): string {
        return '
            <div class="card">
                <div class="krow">
                    <div class="kicon">' . $icon_svg . '</div>
                    <div>
                        <div class="knum">' . esc_html((string)$number) . '</div>
                        <div class="klabel">' . esc_html($label) . '</div>
                    </div>
                </div>
            </div>
        ';
    }

    private function status_badge(string $status): string {
        $status = strtoupper($status);

        if ($status === 'PAID') {
            return '<span class="badge b-paid">' . $this->icon('check') . 'PAID</span>';
        }
        if ($status === 'INVOICED') {
            return '<span class="badge b-invoiced">' . $this->icon('bolt') . 'INVOICED</span>';
        }

        return '<span class="badge b-downgraded">' . $this->icon('arrowDown') . 'DOWNGRADED</span>';
    }

    private function payment_badge(string $payment): string {
        $p = strtoupper(trim($payment ?: 'DUE'));

        if ($p === 'PAID') {
            $class = 'b-pay-paid';
            $icon = 'check';
        } elseif ($p === 'DOWNGRADED') {
            $class = 'b-pay-downgraded';
            $icon = 'arrowDown';
        } else {
            $class = 'b-pay-due';
            $icon = 'clock';
        }

        return '<span class="badge ' . esc_attr($class) . '">' . $this->icon($icon) . esc_html($p) . '</span>';
    }

    private function build_query_string(array $params): string {
        $filtered = [];
        foreach ($params as $k => $v) {
            if ($v === '' || $v === null) continue;
            if ($v === false) continue;
            $filtered[$k] = is_bool($v) ? ($v ? '1' : '0') : (string)$v;
        }
        return $filtered ? ('?' . http_build_query($filtered)) : '';
    }

    private function icon(string $name): string {
        // Minimal inline SVGs (no external libs, clean modern)
        $common = 'width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"';
        $stroke = 'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';

        switch ($name) {
            case 'search':
                return '<svg ' . $common . ' ' . $stroke . '><circle cx="11" cy="11" r="7"/><path d="M20 20l-3-3"/></svg>';
            case 'check':
                return '<svg ' . $common . ' ' . $stroke . '><path d="M20 6L9 17l-5-5"/></svg>';
            case 'clock':
                return '<svg ' . $common . ' ' . $stroke . '><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>';
            case 'bolt':
                return '<svg ' . $common . ' ' . $stroke . '><path d="M13 2L3 14h8l-1 8 10-12h-8l1-8z"/></svg>';
            case 'arrowDown':
                return '<svg ' . $common . ' ' . $stroke . '><path d="M12 5v14"/><path d="M19 12l-7 7-7-7"/></svg>';
            case 'shield':
                return '<svg ' . $common . ' ' . $stroke . '><path d="M12 2l8 4v6c0 5-3.5 9.5-8 10-4.5-.5-8-5-8-10V6l8-4z"/></svg>';

            case 'alert':
                return '<svg ' . $common . ' ' . $stroke . '><path d="M10.3 4.3l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.7-2.7l-8-14a2 2 0 0 0-3.4 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>';

            case 'flag':
                return '<svg ' . $common . ' ' . $stroke . '><path d="M5 3v18"/><path d="M5 4h12l-2 4 2 4H5"/></svg>';

            default:
                return '';

        }
    }
}

new NGD_Renewals_Dashboard();
