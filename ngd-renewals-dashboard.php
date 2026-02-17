<?php
/**
 * Plugin Name: NGD Renewals Dashboard
 * Description: Admin-only, front-end styled renewals dashboard (author-level, no WP admin styling).
 * Version: 1.1.1
 */

if (!defined('ABSPATH'))
    exit;

final class NGD_Renewals_Dashboard
{

    // Versioning for rewrite flushing
    private const VERSION = '1.1.3';

    // IMPORTANT: Your package IDs
    private int $paid_package_id = 247687;
    private int $free_package_id = 138;

    // Pagination defaults
    private int $default_per_page = 50;
    private int $max_per_page = 100;

    public function __construct()
    {
        add_action('init', [$this, 'register_routes']);
        add_action('template_redirect', [$this, 'maybe_render_dashboard']);

        // Invoice Fixes (Caching + Redirects + Forced Render)
        add_action('init', [$this, 'ensure_invoice_shortcodes'], 5);
        add_action('template_redirect', [$this, 'maybe_handle_invoice_pages'], 0);
        add_filter('the_content', [$this, 'maybe_force_invoice_content'], 1);
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('wp_ajax_ngd_queue_action', [$this, 'handle_queue_ajax']); // Admin-only AJAX

        // Queue dispatcher cron (every minute)
        add_filter('cron_schedules', [$this, 'register_cron_schedules']);
        add_action('init', [$this, 'ensure_queue_dispatcher_scheduled']);
        add_action('ngd_renewals_queue_dispatch', [$this, 'run_queue_dispatcher']);

        register_activation_hook(__FILE__, [$this, 'on_activate']);
        register_deactivation_hook(__FILE__, [$this, 'on_deactivate']);
    }

    public function on_activate(): void
    {
        $this->register_routes();
        flush_rewrite_rules();

        // Install/upgrade Queue Table
        if (class_exists('NGD_Renewals_Queue')) {
            NGD_Renewals_Queue::install();
            NGD_Renewals_Queue::ensure_ready(); // also ensures schema upgrades (new columns)
        }

        // Ensure dispatcher scheduled
        $this->ensure_queue_dispatcher_scheduled();
    }

    public function on_deactivate(): void
    {
        flush_rewrite_rules();
        wp_clear_scheduled_hook('ngd_renewals_queue_dispatch');
    }

    public function register_routes(): void
    {
        add_rewrite_rule('^renewals/?$', 'index.php?ngd_renewals=1', 'top');
        add_rewrite_rule('^renewals/action/?$', 'index.php?ngd_renewals=1&ngd_renewals_action=1', 'top');
        add_rewrite_rule('^renewals/export/?$', 'index.php?ngd_renewals=1&ngd_renewals_export=1', 'top');

        // ✅ Invoice viewer pretty URLs (prevents query-string stripping/caching issues)
        // Supports both /invoice-view/{REF}/ and /invoice/{REF}/
        add_rewrite_rule('^invoice-view/([^/]+)/?$', 'index.php?pagename=invoice-view&ref=$matches[1]', 'top');
        add_rewrite_rule('^invoice/([^/]+)/?$', 'index.php?pagename=invoice&ref=$matches[1]', 'top');

        // ✅ Update page pretty URL (optional but consistent)
        add_rewrite_rule('^update-invoice/([^/]+)/?$', 'index.php?pagename=update-invoice&ref=$matches[1]', 'top');

        // One-time flush if version changed
        if (get_option('ngd_renewals_dash_version') !== self::VERSION) {
            flush_rewrite_rules(false);
            update_option('ngd_renewals_dash_version', self::VERSION, false);
        }
    }

    public function register_query_vars(array $vars): array
    {
        $vars[] = 'ngd_renewals';
        $vars[] = 'ngd_renewals_action';
        $vars[] = 'ngd_renewals_export';

        $vars[] = 'ngd_status';
        $vars[] = 'ngd_q';

        $vars[] = 'ngd_issue';
        $vars[] = 'ngd_show_downgraded';

        $vars[] = 'ngd_year';

        $vars[] = 'ngd_sort';
        $vars[] = 'ngd_dir';

        $vars[] = 'ngd_page';
        $vars[] = 'ngd_per_page';

        // ✅ allow invoice ref to be carried via rewrite rules
        $vars[] = 'ref';

        return $vars;
    }


    public function maybe_render_dashboard(): void
    {
        if (intval(get_query_var('ngd_renewals')) !== 1)
            return;

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

        $is_action = intval(get_query_var('ngd_renewals_action')) === 1;
        if ($is_action) {
            $this->handle_action_request();
            exit;
        }

        $data = $this->get_dashboard_data();
        $this->render_dashboard_html($data);
        exit;
    }

    private function handle_action_request(): void
    {
        try {
            // 1. Security Check
            if (!is_user_logged_in() || !current_user_can('manage_options')) {
                wp_send_json_error(['message' => 'Unauthorized'], 403);
            }

            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                wp_send_json_error(['message' => 'POST required'], 405);
            }

            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                wp_send_json_error(['message' => 'Invalid JSON'], 400);
            }

            if (empty($input['nonce']) || !wp_verify_nonce($input['nonce'], 'ngd_renewals_action')) {
                wp_send_json_error(['message' => 'Invalid Nonce'], 403);
            }

            $user_id = (int) ($input['user_id'] ?? 0);
            if (!$user_id) {
                wp_send_json_error(['message' => 'User ID missing'], 400);
            }

            $do = $input['do'] ?? '';

            // 2. Logic Dispatch
            switch ($do) {
                case 'toggle_evergreen':
                    $this->action_toggle_evergreen($user_id);
                    break;

                case 'upgrade':
                    $eff_date = $input['effective_date'] ?? '';
                    $send_email = !empty($input['send_email']); // Expects true/1 from JS

                    if (!$eff_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eff_date)) {
                        wp_send_json_error(['message' => 'Invalid effective date (YYYY-MM-DD required)'], 400);
                    }
                    $this->action_upgrade($user_id, $eff_date, $send_email);
                    break;

                case 'downgrade':
                    $this->action_downgrade($user_id);
                    break;

                default:
                    wp_send_json_error(['message' => 'Unknown action'], 400);
            }
        } catch (Throwable $e) {
            wp_send_json_error(['message' => 'Server error: ' . $e->getMessage()], 500);
        }
    }

    private function action_toggle_evergreen(int $user_id): void
    {
        // Mark as client whenever interacted with
        update_user_meta($user_id, '_ngd_client', 'yes');

        $curr = get_user_meta($user_id, '_ngd_evergreen', true);
        if ($curr === 'yes') {
            delete_user_meta($user_id, '_ngd_evergreen');
            wp_send_json_success(['message' => 'Evergreen status removed. Automations will resume.']);
        } else {
            update_user_meta($user_id, '_ngd_evergreen', 'yes');
            wp_send_json_success(['message' => 'Evergreen enabled. Downgrades suppressed.']);
        }
    }

    /**
     * Helper: Delete all meta rows for a key via SQL (Bypass WP filters)
     */
    private function ngd_sql_delete_meta_rows(int $post_id, string $meta_key): void
    {
        global $wpdb;
        $wpdb->delete($wpdb->postmeta, ['post_id' => $post_id, 'meta_key' => $meta_key]);
    }

    /**
     * Helper: Insert a single meta row via SQL (Bypass WP filters)
     */
    private function ngd_sql_insert_meta_row(int $post_id, string $meta_key, string $meta_value): void
    {
        global $wpdb;
        $wpdb->insert(
            $wpdb->postmeta,
            ['post_id' => $post_id, 'meta_key' => $meta_key, 'meta_value' => $meta_value],
            ['%d', '%s', '%s']
        );
    }

    /**
     * Helper: Set single meta value via SQL (Delete all + Insert one)
     */
    private function ngd_sql_set_single_meta(int $post_id, string $meta_key, string $meta_value): void
    {
        $this->ngd_sql_delete_meta_rows($post_id, $meta_key);
        $this->ngd_sql_insert_meta_row($post_id, $meta_key, $meta_value);
        clean_post_cache($post_id);
        wp_cache_delete($post_id, 'post_meta');
    }

    private function action_upgrade(int $user_id, string $eff_date, bool $send_email = true): void
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
            'post_type' => 'job_listing',
            'post_status' => ['publish', 'pending', 'draft', 'private', 'future', 'expired'],
            'author' => $user_id,
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
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
                    'ID' => $pid,
                    'post_status' => 'publish',
                ]);
                $republished++;
            }

            // Cache busting
            clean_post_cache($pid);
            wp_cache_delete($pid, 'post_meta');

            // Verification
            $verify_expires = get_post_meta($pid, '_job_expires', true);
            $verify_status = get_post_meta($pid, '_payment_status', true);
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


    private function action_downgrade(int $user_id): void
    {
        // Persistent Client Marker
        update_user_meta($user_id, '_ngd_client', 'yes');

        // Evergreen Protection
        if (get_user_meta($user_id, '_ngd_evergreen', true) === 'yes') {
            wp_send_json_error(['message' => 'Cannot downgrade: User is Evergreen. Remove Evergreen first.'], 400);
        }

        $listings = get_posts([
            'post_type' => 'job_listing',
            'post_status' => 'publish',
            'author' => $user_id,
            'posts_per_page' => -1
        ]);

        if (!$listings)
            wp_send_json_error(['message' => 'No listings found'], 404);

        // Use Cron Wrapper (downgrades logic + email)
        // Ensure class is loaded
        if (!class_exists('\NGD_THEME\Functions\RenewalCron')) {
            require_once __DIR__ . '/RenewalCron.php';
        }

        if (class_exists('\NGD_THEME\Functions\RenewalCron')) {
            $cron = new \NGD_THEME\Functions\RenewalCron(false);
            if (!method_exists($cron, 'send_manual_stage_email')) {
                wp_send_json_error(['message' => 'send_manual_stage_email missing on RenewalCron class'], 500);
            }
            $cron->send_manual_stage_email($user_id, $listings, 'downgrade_final');
        } else {
            wp_send_json_error(['message' => 'RenewalCron class missing after require attempt'], 500);
        }

        // Belt and braces (in case wrapper fails or logic changes)
        // Ensure paid status is dead
        // Manual marker for precise date compatibility
        $key_date = '_sent_downgrade_final_' . date('Ymd');
        foreach ($listings as $l) {
            update_post_meta($l->ID, $key_date, '1');
            delete_post_meta($l->ID, '_payment_status');
        }

        wp_send_json_success(['message' => 'Downgraded successfully. Email sent.']);
    }



    private function render_login_required(): void
    {
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
                :root {
                    --bg: #ffffff;
                    --text: #0b1220;
                    --muted: #64748b;
                    --border: #e2e8f0;
                    --soft: #f8fafc;
                    --shadow: 0 14px 34px rgba(2, 6, 23, .08);
                    --radius: 18px;
                    --blue: #2563eb;
                    --blueSoft: #eff6ff;
                }

                * {
                    box-sizing: border-box
                }

                body {
                    margin: 0;
                    font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial;
                    color: var(--text);
                    background: linear-gradient(180deg, #ffffff 0%, #fbfdff 55%, #ffffff 100%);
                }

                .wrap {
                    min-height: 100vh;
                    display: grid;
                    place-items: center;
                    padding: 24px
                }

                .card {
                    width: min(520px, 100%);
                    border: 1px solid var(--border);
                    border-radius: var(--radius);
                    padding: 22px;
                    background: #fff;
                    box-shadow: var(--shadow);
                }

                h1 {
                    margin: 0 0 8px 0;
                    font-size: 22px
                }

                p {
                    margin: 0 0 16px 0;
                    color: var(--muted);
                    line-height: 1.45
                }

                .btn {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    padding: 12px 14px;
                    border-radius: 14px;
                    background: var(--blue);
                    color: #fff;
                    text-decoration: none;
                    font-weight: 750;
                    box-shadow: 0 12px 24px rgba(37, 99, 235, .18);
                }

                .link {
                    margin-left: 12px;
                    color: var(--blue);
                    text-decoration: none;
                    font-weight: 650
                }

                .small {
                    margin-top: 14px;
                    font-size: 12px;
                    color: var(--muted)
                }
            </style>
        </head>

        <body>
            <div class="wrap">
                <div class="card">
                    <h1>Login required</h1>
                    <p>This renewals dashboard is admin-only. Please log in, then you’ll be returned to
                        <strong>/renewals</strong>.
                    </p>
                    <a class="btn" href="<?php echo esc_url($login_url); ?>">Log in</a>
                    <a class="link" href="<?php echo esc_url(home_url('/')); ?>">Back to site</a>
                    <div class="small">If you keep seeing login issues, it’s almost always a cache/security cookie rule. This
                        page avoids redirect loops by design.</div>
                </div>
            </div>
        </body>

        </html>
        <?php
    }

    /* =========================================================
     * DATA LAYER (AUTHOR-LEVEL)
     * ========================================================= */

    private function get_dashboard_data(): array
    {
        $q = trim((string) get_query_var('ngd_q'));
        $status = strtoupper(trim((string) get_query_var('ngd_status')));

        $issue = strtoupper(trim((string) get_query_var('ngd_issue'))); // (ALL | MISSING_EXPIRY | DUE_NOT_INVOICED)

        $show_downgraded_raw = (string) get_query_var('ngd_show_downgraded');
        $show_downgraded = ($show_downgraded_raw === '1' || strtolower(trim($show_downgraded_raw)) === 'true');

        $year_param = trim((string) get_query_var('ngd_year'));
        $current_year = date('Y');
        // Default to ALL if empty
        $filter_year = ($year_param === '') ? 'ALL' : $year_param;

        $sort = strtolower(trim((string) get_query_var('ngd_sort')));
        $dir = strtolower(trim((string) get_query_var('ngd_dir'))) === 'asc' ? 'asc' : 'desc';

        $page = max(1, intval(get_query_var('ngd_page')));
        $per_page = intval(get_query_var('ngd_per_page'));
        if (!in_array($per_page, [$this->default_per_page, $this->max_per_page], true)) {
            $per_page = $this->default_per_page;
        }

        // Fetch all published listings
        $listing_ids = get_posts([
            'post_type' => 'job_listing',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'DESC',
            'no_found_rows' => true,
        ]);

        $now_ts = current_time('timestamp');

        // Build author aggregates
        $authors = []; // keyed by user_id

        foreach ($listing_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post)
                continue;

            $owner_id = (int) $post->post_author;
            if ($owner_id <= 0)
                continue;

            if (!isset($authors[$owner_id])) {
                $owner = get_user_by('id', $owner_id);
                $authors[$owner_id] = [
                    'user_id' => $owner_id,
                    'owner_name' => $owner ? ($owner->display_name ?: ('User #' . $owner_id)) : ('User #' . $owner_id),
                    'owner_email' => $owner ? (string) $owner->user_email : '',
                    'school' => $this->derive_school_name_for_author($owner_id),

                    'listing_ids' => [],
                    'listing_count' => 0,

                    // Premium / billing signals (ANY listing)
                    'is_current_premium' => false,
                    'has_paid_signal' => false,

                    // Evergreen author-level override
                    'is_evergreen' => false,

                    // Persistent client marker match
                    'is_ngd_client' => false,

                    // Renewal signals (ANY listing)
                    'renewal_ref' => '',
                    'has_renewal_ref' => false,

                    // Payment status tracking (for DUE detection)
                    'has_due' => false,

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

                    // DUE DATE STAMP (LATEST) - for invoice due date tracking
                    'due_expires_ts_max' => 0,

                    // For debugging / future (not displayed)
                    'expires_min_ts' => 0,
                    'expires_min_raw' => '',
                ];
            }

            $a = &$authors[$owner_id];
            $a['listing_ids'][] = $post_id;
            $a['listing_count']++;

            // Check Evergreen status (user meta)
            // We only need to check this once really, but doing it here is fine as it's cached by WP
            if (!$a['is_evergreen']) {
                $is_eg = get_user_meta($owner_id, '_ngd_evergreen', true) === 'yes';
                if ($is_eg)
                    $a['is_evergreen'] = true;
            }

            // Check Persistent Client Marker (user meta)
            if (!$a['is_ngd_client']) {
                $is_cli = get_user_meta($owner_id, '_ngd_client', true) === 'yes';
                if ($is_cli)
                    $a['is_ngd_client'] = true;
            }

            // Track latest modified time (server-based). This is READ-ONLY and safe.
            $modified_ts = (int) get_post_modified_time('U', true, $post_id);
            if ($modified_ts > $a['latest_modified_ts_max']) {
                $a['latest_modified_ts_max'] = $modified_ts;
            }

            // Listing-level meta
            $package_id = (int) get_post_meta($post_id, '_package_id', true);
            $featured = (string) get_post_meta($post_id, '_featured', true) === '1';

            $payment_status = strtoupper(trim((string) get_post_meta($post_id, '_payment_status', true)));
            $is_paid_signal = ($payment_status === 'PAID');

            // Track DUE status
            if ($payment_status === 'DUE') {
                $a['has_due'] = true;
            }

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
            if ($invoice_seen)
                $a['invoice_seen_any'] = true;

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

            // DUE EXPIRY (stamped by audit script)
            $due_ts = (int) get_post_meta($post_id, '_ngd_due_expires_ts', true);
            if ($due_ts > $a['due_expires_ts_max']) {
                $a['due_expires_ts_max'] = $due_ts;
            }

            // Optional: MIN expiry
            if ($expires_ts > 0 && ($a['expires_min_ts'] === 0 || $expires_ts < $a['expires_min_ts'])) {
                $a['expires_min_ts'] = $expires_ts;
                $a['expires_min_raw'] = $expires_raw;
            }

            // Timeline flags (ANY YEAR)
            $all_meta = get_post_meta($post_id);
            foreach ($all_meta as $key => $vals) {
                if (!$this->meta_truthy($vals))
                    continue;

                if ($this->starts_with($key, '_sent_invoice_'))
                    $a['flag_invoice'] = true;
                if ($this->starts_with($key, '_sent_reminder_14_'))
                    $a['flag_r14'] = true;
                if ($this->starts_with($key, '_sent_reminder_07_'))
                    $a['flag_r07'] = true;
                if ($this->starts_with($key, '_sent_reminder_03_'))
                    $a['flag_r03'] = true;
                if ($this->starts_with($key, '_sent_downgrade_warn_'))
                    $a['flag_warn'] = true;

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

            // If package is explicitly free, treat as downgrade evidence (unconditionally)
            if ($package_id === $this->free_package_id) {
                $a['has_downgrade_final'] = true;
                $a['flag_final'] = true;
            }

            unset($a);
        }

        // Convert to rows (only include commercially relevant authors)
        $rows = [];
        $kpi = [
            'CLIENTS' => 0,    // (Total filtered rows)
            'PAID' => 0,       // Payment = PAID
            'DOWNGRADED' => 0, // Status = DOWNGRADED
        ];

        $seen_years = [];

        foreach ($authors as $user_id => $a) {

            // INCLUDE RULE
            // Include if premium, involved in renewals, evergreen, OR explicitly marked as a client
            $include = ($a['is_current_premium'] || $a['has_renewal_ref'] || $a['has_downgrade_final'] || $a['is_evergreen'] || $a['is_ngd_client']);
            if (!$include)
                continue;

            // EFFECTIVE EXPIRY COMPUTATION
            // Priority: If author has DUE status, use due_expires_ts (or invoice_sent + 28 fallback)
            // Otherwise: use job_expires
            $effective_ts = 0;

            if ($a['has_due']) {
                // DUE state detected - use invoice due date
                $effective_ts = $a['due_expires_ts_max'];
                if ($effective_ts <= 0 && $a['invoice_sent_ts_max'] > 0) {
                    // Fallback: invoice_sent + 28 days
                    $effective_ts = $a['invoice_sent_ts_max'] + (28 * DAY_IN_SECONDS);
                }
            } else {
                // Normal state - use job expiry
                $effective_ts = $a['expires_max_ts'];
            }

            // Days to expiry (based on LATEST expiry for non-invoiced, or effective_due for invoiced)
            $days_to_expiry = null;
            $row_year = null;

            if ($a['expires_max_ts'] > 0) {
                $days_to_expiry = (int) floor(($a['expires_max_ts'] - $now_ts) / DAY_IN_SECONDS);
                $row_year = date('Y', $a['expires_max_ts']);
            }

            // Missing expiry: only true if job_expires is missing AND we don't have a DUE effective date
            $missing_expiry = ($a['expires_max_ts'] <= 0 && !($a['has_due'] && $effective_ts > 0));

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

            // Year assignment logic
            if (!$row_year) {
                // If missing expiry alert is active => Treat as CURRENT YEAR to stay visible
                if ($alert_missing_expiry) {
                    $row_year = $current_year;
                }
                // Otherwise remain null (only visible in "All")
            }

            if ($row_year) {
                $seen_years[$row_year] = true;
            }

            // Apply Year Filter
            // If filter is ALL, show everything.
            // If filter is specific year, row must match.
            // (Note: if row_year is null, it only shows in ALL)
            if (strtoupper($filter_year) !== 'ALL') {
                if ((string) $row_year !== (string) $filter_year) {
                    continue;
                }
            }

            // Renewal window
            $in_renewal_window = (!$missing_expiry && $days_to_expiry !== null && $days_to_expiry <= 35 && $days_to_expiry >= -8);


            // Status rules
            // 1) Evergreen Override
            $ui_status = 'DOWNGRADED';
            if ($a['is_evergreen']) {
                $ui_status = 'EVERGREEN';
            }
            // 2) DUE status overrides everything except Evergreen
            elseif ($a['has_due']) {
                $ui_status = 'INVOICED';
            }
            // 3) Expired beyond grace period (HARD DOWNGRADE)
            elseif (!$missing_expiry && $days_to_expiry !== null && $days_to_expiry <= -8) {
                $ui_status = 'DOWNGRADED';
            }
            // 4) Premium/Paid Signal + Expiry OK => PAID
            elseif (($a['is_current_premium'] || $a['has_paid_signal']) && ($missing_expiry || $days_to_expiry >= 0)) {
                $ui_status = 'PAID';
            }
            // 5) Default
            else {
                $ui_status = 'DOWNGRADED';
            }

            // Payment label (UI-only)
            if ($ui_status === 'PAID')
                $payment_label = 'PAID';
            elseif ($ui_status === 'INVOICED') // Covers DUE case
                $payment_label = 'DUE';
            elseif ($ui_status === 'EVERGREEN')
                $payment_label = 'PAID'; // Evergreen implies paid
            else
                $payment_label = 'DOWNGRADED';

            // Hide downgraded rows by default unless toggle is enabled
            if (!$show_downgraded && $ui_status === 'DOWNGRADED') {
                continue;
            }

            // Days metric + label
            $days_metric = null;
            $days_label = '—';
            $days_is_estimated = false;
            $display_expires_date = '';

            if ($ui_status === 'DOWNGRADED') {
                $downgrade_ts = 0;

                // (a) best: real downgrade-final meta date
                if (!empty($a['downgrade_final_ts_max']) && (int) $a['downgrade_final_ts_max'] > 0) {
                    $downgrade_ts = (int) $a['downgrade_final_ts_max'];
                }
                // (b) fallback: expiry + 8 days if expiry exists and is already in the past
                elseif (!$missing_expiry && (int) $a['expires_max_ts'] > 0) {
                    $fallback = (int) $a['expires_max_ts'] + 8 * DAY_IN_SECONDS;
                    if ($now_ts >= $fallback)
                        $downgrade_ts = $fallback;
                }
                // (c) fallback: latest modified (estimated)
                elseif (!empty($a['latest_modified_ts_max']) && (int) $a['latest_modified_ts_max'] > 0) {
                    $downgrade_ts = (int) $a['latest_modified_ts_max'];
                    $days_is_estimated = true;
                }

                if ($downgrade_ts > 0) {
                    $days_since = (int) floor(($now_ts - $downgrade_ts) / DAY_IN_SECONDS);
                    $days_metric = -abs($days_since);
                    $days_label = (string) $days_metric; // negative value
                } else {
                    $days_metric = null;
                    $days_label = '—';
                }
            } else {
                // PAID / INVOICED / DUE: check if we should use effective due date
                if ($a['has_due'] && $effective_ts > 0) {
                    // Override with invoice due date for DUE status
                    $days_metric = (int) floor(($effective_ts - $now_ts) / DAY_IN_SECONDS);
                    $days_label = ($days_metric >= 0) ? ('+' . $days_metric) : (string) $days_metric;
                    $display_expires_date = date('Y-m-d', $effective_ts);
                } else {
                    // Normal expiry countdown
                    $days_metric = $days_to_expiry;
                    if ($days_to_expiry === null) {
                        $days_label = '—';
                    } else {
                        $days_label = ($days_to_expiry > 0) ? ('+' . $days_to_expiry) : (string) $days_to_expiry;
                    }
                }
            }

            // Alerts
            // Suppress missing_expiry alert if we have DUE status with effective date
            $alert_missing_expiry = (($a['is_current_premium'] || $a['has_paid_signal']) && $missing_expiry);
            if ($a['has_due'] && $effective_ts > 0) {
                $alert_missing_expiry = false;
            }
            $alert_due_not_invoiced = (
                ($a['is_current_premium'] || $a['has_paid_signal']) &&
                !$missing_expiry &&
                $days_to_expiry !== null &&
                $days_to_expiry <= 30 &&
                $days_to_expiry >= -8 &&
                !$a['has_renewal_ref']
            );

            if ($alert_missing_expiry)
                $kpi['MISSING_EXPIRY']++;
            if ($alert_due_not_invoiced) {
                // $kpi['DUE_NOT_INVOICED']++; 
            }

            // Filters
            if ($status && $status !== 'ALL' && $ui_status !== $status)
                continue;

            if ($issue && $issue !== 'ALL') {
                if ($issue === 'MISSING_EXPIRY' && empty($alert_missing_expiry))
                    continue;
                if ($issue === 'DUE_NOT_INVOICED' && empty($alert_due_not_invoiced))
                    continue;
            }

            if ($q !== '') {
                $hay = strtolower(
                    ($a['school'] ?? '') . ' ' .
                    ($a['owner_name'] ?? '') . ' ' .
                    ($a['owner_email'] ?? '') . ' ' .
                    ($a['renewal_ref'] ?? '') . ' ' .
                    $user_id
                );
                if (strpos($hay, strtolower($q)) === false)
                    continue;
            }

            // KPI Counting (Filtered dataset)
            $kpi['CLIENTS']++;

            if ($payment_label === 'PAID') {
                $kpi['PAID']++;
            }
            if ($ui_status === 'DOWNGRADED') {
                $kpi['DOWNGRADED']++;
            }

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

                'is_evergreen' => (bool) $a['is_evergreen'],

                'status' => $ui_status,
                'payment' => $payment_label,

                'alert_missing_expiry' => (bool) $alert_missing_expiry,
                'alert_due_not_invoiced' => (bool) $alert_due_not_invoiced,

                'opened_any' => (bool) $a['invoice_seen_any'],
                'invoice_sent' => $a['invoice_sent_ts_max'] ? date('Y-m-d H:i', $a['invoice_sent_ts_max']) : '—',
                'last_seen' => $a['last_seen_ts_max'] ? date('Y-m-d H:i', $a['last_seen_ts_max']) : ($a['last_seen_raw_max'] ?: '—'),

                'expires_date' => $display_expires_date ?: ($a['expires_max_ts'] ? date('Y-m-d', $a['expires_max_ts']) : '—'),
                'days_metric' => $days_metric,
                'days_label' => $days_label,
                'days_is_estimated' => (bool) $days_is_estimated,

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
        if ($page > $total_pages)
            $page = $total_pages;

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
                'year' => $filter_year,
                'show_downgraded' => $show_downgraded ? '1' : '0',
                'sort' => $sort ?: 'days',
                'dir' => $dir ?: 'asc',
            ],
            'available_years' => array_keys($seen_years),
        ];
    }

    private function derive_school_name_for_author(int $user_id): string
    {
        $latest = get_posts([
            'post_type' => 'job_listing',
            'post_status' => 'publish',
            'author' => $user_id,
            'posts_per_page' => 1,
            'orderby' => 'modified',
            'order' => 'DESC',
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        if (!empty($latest[0])) {
            return (string) get_the_title((int) $latest[0]);
        }

        $u = get_user_by('id', $user_id);
        return $u ? ($u->display_name ?: ('User #' . $user_id)) : ('User #' . $user_id);
    }

    private function sort_rows(array $rows, string $sort, string $dir): array
    {
        $sort = $sort ?: 'days';
        $dir = ($dir === 'asc') ? 'asc' : 'desc';

        $status_rank = [
            'PAID' => 1,
            'INVOICED' => 2,
            'DOWNGRADED' => 3,
            'EVERGREEN' => 99,
        ];

        usort($rows, function ($a, $b) use ($sort, $dir, $status_rank) {
            $cmp = 0;

            switch ($sort) {
                case 'school':
                    $cmp = strcasecmp((string) $a['school'], (string) $b['school']);
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
                    $cmp = ((int) !empty($a['opened_any'])) <=> ((int) !empty($b['opened_any']));
                    break;

                case 'days':
                default:
                    $da = $a['days_metric'];
                    $db = $b['days_metric'];

                    if ($da === null && $db === null)
                        $cmp = 0;
                    elseif ($da === null)
                        $cmp = 1;
                    elseif ($db === null)
                        $cmp = -1;
                    else
                        $cmp = ((int) $da) <=> ((int) $db);
                    break;
            }

            if ($cmp === 0)
                $cmp = strcasecmp((string) $a['school'], (string) $b['school']);
            return ($dir === 'asc') ? $cmp : -$cmp;
        });

        return $rows;
    }

    private function build_timeline_author(array $a, int $expires_ts, int $invoice_sent_ts, bool $opened_any, int $last_seen_ts, string $last_seen_raw): array
    {
        $items = [];

        $items[] = [
            'key' => 'invoice',
            'label' => 'Invoice sent',
            'sent' => (bool) ($a['flag_invoice'] || $invoice_sent_ts > 0 || $a['has_renewal_ref']),
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
            'sent' => (bool) $a['flag_r14'],
            'date' => $expires_ts ? date('Y-m-d', $expires_ts - 14 * DAY_IN_SECONDS) : '—',
        ];

        $items[] = [
            'key' => 'r07',
            'label' => 'Reminder 7',
            'sent' => (bool) $a['flag_r07'],
            'date' => $expires_ts ? date('Y-m-d', $expires_ts - 7 * DAY_IN_SECONDS) : '—',
        ];

        $items[] = [
            'key' => 'r03',
            'label' => 'Reminder 3',
            'sent' => (bool) $a['flag_r03'],
            'date' => $expires_ts ? date('Y-m-d', $expires_ts - 3 * DAY_IN_SECONDS) : '—',
        ];

        $items[] = [
            'key' => 'warn',
            'label' => 'Downgrade warn',
            'sent' => (bool) $a['flag_warn'],
            'date' => $expires_ts ? date('Y-m-d', $expires_ts + 1 * DAY_IN_SECONDS) : '—',
        ];

        $items[] = [
            'key' => 'final',
            'label' => 'Downgraded (final)',
            'sent' => (bool) $a['flag_final'],
            'date' => $expires_ts ? date('Y-m-d', $expires_ts + 8 * DAY_IN_SECONDS) : '—',
        ];

        return $items;
    }

    private function parse_expiry_to_ts(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '')
            return 0;

        if (ctype_digit($raw)) {
            if (strlen($raw) === 8) {
                $y = substr($raw, 0, 4);
                $m = substr($raw, 4, 2);
                $d = substr($raw, 6, 2);
                $ts = strtotime($y . '-' . $m . '-' . $d . ' 00:00:00');
                return $ts ? (int) $ts : 0;
            }
            if (strlen($raw) >= 10)
                return (int) $raw;
        }

        $ts = strtotime($raw);
        return $ts ? (int) $ts : 0;
    }

    private function parse_datetime_to_ts(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '')
            return 0;
        $ts = strtotime($raw);
        return $ts ? (int) $ts : 0;
    }

    private function starts_with(string $haystack, string $prefix): bool
    {
        return strncmp($haystack, $prefix, strlen($prefix)) === 0;
    }

    private function meta_truthy($vals): bool
    {
        if (!is_array($vals) || empty($vals))
            return false;
        $v = $vals[0] ?? '';
        if (is_array($v))
            return !empty($v);
        $v = strtolower(trim((string) $v));
        return ($v !== '' && $v !== '0' && $v !== 'false' && $v !== 'no');
    }

    /* =========================================================
     * EXPORT
     * ========================================================= */

    private function render_export_csv(): void
    {
        $data = $this->get_dashboard_data();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=renewals-export-authors.csv');

        $out = fopen('php://output', 'w');

        fputcsv($out, [
            'User ID',
            'School',
            'Owner',
            'Owner Email',
            'Status',
            'Payment',
            'Opened (Any)',
            'Days',
            'Expiry Date',
            'Renewal Reference',
            'Invoice Sent',
            'Last Seen'
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

    private function render_dashboard_html(array $data): void
    {
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
            'ngd_year' => $filters['year'],
            'ngd_show_downgraded' => $filters['show_downgraded'] ?? '0',
            'ngd_sort' => $filters['sort'],
            'ngd_dir' => $filters['dir'],
            'ngd_page' => $meta['page'],
            'ngd_per_page' => $meta['per_page'],
        ]);


        $json_selected = wp_json_encode($selected);

        ?>
        <!doctype html>
        <html lang="en">

        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title>Renewals</title>
            <style>
                :root {
                    --bg: #ffffff;
                    --text: #0b1220;
                    --muted: #64748b;
                    --border: #e2e8f0;
                    --soft: #f8fafc;
                    --shadow: 0 14px 34px rgba(2, 6, 23, .08);
                    --radius: 18px;

                    --blue: #2563eb;
                    --blueSoft: #eff6ff;
                    --green: #16a34a;
                    --greenSoft: #ecfdf5;
                    --amber: #f59e0b;
                    --amberSoft: #fffbeb;
                    --red: #ef4444;
                    --redSoft: #fef2f2;
                    --slateSoft: #f1f5f9;
                }

                * {
                    box-sizing: border-box
                }

                html,
                body {
                    height: 100%
                }

                body {
                    margin: 0;
                    font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial;
                    color: var(--text);
                    background: linear-gradient(180deg, #ffffff 0%, #fbfdff 55%, #ffffff 100%);
                }

                a {
                    color: inherit;
                    text-decoration: none
                }

                .wrap {
                    display: flex;
                    min-height: 100vh
                }

                .main {
                    flex: 1;
                    padding: 28px 28px 18px 28px
                }

                .drawer {
                    width: 420px;
                    max-width: 42vw;
                    border-left: 1px solid var(--border);
                    background: #fff;
                    position: sticky;
                    top: 0;
                    height: 100vh;
                    overflow: auto;
                    padding: 22px;
                }

                .topbar {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    margin-bottom: 22px
                }

                .brand {
                    font-weight: 700;
                    letter-spacing: .2px
                }

                .topRight {
                    display: flex;
                    align-items: center;
                    gap: 12px
                }

                .toggleWrap {
                    display: inline-flex;
                    align-items: center;
                    gap: 10px;
                    color: var(--muted);
                    font-size: 14px;
                    font-weight: 650;
                    user-select: none
                }

                .toggleWrap input {
                    display: none
                }

                .toggleUi {
                    width: 46px;
                    height: 28px;
                    border-radius: 999px;
                    border: 1px solid var(--border);
                    background: #fff;
                    position: relative;
                    box-shadow: 0 1px 0 rgba(2, 6, 23, .04);
                    transition: all .15s ease;
                }

                .toggleUi:after {
                    content: "";
                    position: absolute;
                    top: 50%;
                    left: 4px;
                    width: 20px;
                    height: 20px;
                    border-radius: 999px;
                    transform: translateY(-50%);
                    background: var(--slateSoft);
                    border: 1px solid var(--border);
                    transition: all .15s ease;
                }

                #show_downgraded:checked+.toggleUi {
                    background: var(--blueSoft);
                    border-color: #dbeafe;
                }

                #show_downgraded:checked+.toggleUi:after {
                    left: 22px;
                    background: #fff;
                    border-color: #bfdbfe;
                }

                .toggleText {
                    color: var(--text);
                    font-weight: 650
                }

                .h1 {
                    font-size: 42px;
                    line-height: 1.05;
                    margin: 0 0 6px 0
                }

                .sub {
                    color: var(--muted);
                    margin: 0 0 22px 0
                }

                .controls {
                    display: flex;
                    gap: 14px;
                    align-items: center;
                    justify-content: space-between;
                    margin-bottom: 18px
                }

                .controlsLeft {
                    display: flex;
                    gap: 14px;
                    align-items: center;
                    flex-wrap: wrap
                }

                .pill {
                    display: flex;
                    gap: 10px;
                    align-items: center;
                    border: 1px solid var(--border);
                    border-radius: 999px;
                    padding: 12px 14px;
                    min-width: 320px;
                    background: #fff;
                    box-shadow: 0 1px 0 rgba(2, 6, 23, .04);
                }

                .pill input {
                    border: none;
                    outline: none;
                    width: 100%;
                    font-size: 14px
                }

                .select {
                    border: 1px solid var(--border);
                    border-radius: 999px;
                    padding: 12px 14px;
                    background: #fff;
                    font-size: 14px;
                    color: var(--text);
                    min-width: 190px;
                    box-shadow: 0 1px 0 rgba(2, 6, 23, .04);
                }

                .btn {
                    border: none;
                    border-radius: 999px;
                    padding: 12px 16px;
                    font-size: 14px;
                    cursor: pointer
                }

                .btn.primary {
                    background: var(--blue);
                    color: #fff;
                    box-shadow: 0 12px 24px rgba(37, 99, 235, .18)
                }

                .btn.icon {
                    width: 44px;
                    height: 44px;
                    border: 1px solid var(--border);
                    background: #fff;
                    border-radius: 999px
                }

                .kpis {
                    display: grid;
                    grid-template-columns: repeat(3, minmax(0, 1fr));
                    gap: 14px;
                    margin: 18px 0
                }

                .card {
                    border: 1px solid var(--border);
                    border-radius: var(--radius);
                    padding: 16px;
                    background: #fff;
                    box-shadow: var(--shadow);
                }

                .krow {
                    display: flex;
                    align-items: center;
                    gap: 12px
                }

                .kicon {
                    width: 38px;
                    height: 38px;
                    border-radius: 14px;
                    background: var(--soft);
                    display: grid;
                    place-items: center;
                    border: 1px solid var(--border);
                }

                .knum {
                    font-size: 28px;
                    font-weight: 800;
                    line-height: 1
                }

                .klabel {
                    color: var(--muted);
                    font-size: 13px;
                    margin-top: 3px
                }

                .grid {
                    border: 1px solid var(--border);
                    border-radius: var(--radius);
                    overflow: hidden;
                    background: #fff;
                    box-shadow: var(--shadow);
                }

                .head,
                .row {
                    display: grid;
                    grid-template-columns: 1.6fr .9fr .7fr .7fr .8fr .3fr;
                    gap: 12px;
                    align-items: center;
                    padding: 14px 16px;
                }

                .head {
                    color: var(--muted);
                    font-size: 12px;
                    border-bottom: 1px solid var(--border);
                    background: #fff;
                    position: sticky;
                    top: 0;
                    z-index: 10;
                }

                .hcell {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    cursor: pointer;
                    user-select: none;
                }

                .hcell.noSort {
                    cursor: default
                }

                .sortIcon {
                    opacity: .55;
                    font-size: 12px
                }

                .sortIcon.on {
                    opacity: 1;
                    color: var(--text)
                }

                .row {
                    border-bottom: 1px solid var(--border);
                    cursor: pointer
                }

                .row:last-child {
                    border-bottom: none
                }

                .row:hover {
                    background: var(--soft)
                }

                .school {
                    font-weight: 700
                }

                .meta {
                    color: var(--muted);
                    font-size: 12px;
                    margin-top: 4px
                }

                .badge {
                    display: inline-flex;
                    gap: 8px;
                    align-items: center;
                    border-radius: 999px;
                    padding: 8px 10px;
                    font-size: 12px;
                    font-weight: 700;
                    border: 1px solid transparent;
                    white-space: nowrap;
                }

                /* NEW alert pills */
                .alertPills {
                    display: flex;
                    gap: 8px;
                    margin-top: 8px;
                    flex-wrap: wrap
                }

                .apill {
                    display: inline-flex;
                    gap: 8px;
                    align-items: center;
                    padding: 6px 10px;
                    border-radius: 999px;
                    font-size: 12px;
                    font-weight: 750;
                    border: 1px solid var(--border);
                    background: #fff;
                    color: #0b1220;
                }

                .apill.warn {
                    background: var(--amberSoft);
                    border-color: #fde68a;
                    color: #92400e
                }

                .apill.bad {
                    background: var(--redSoft);
                    border-color: #fecaca;
                    color: #991b1b
                }

                .apill.gray {
                    background: var(--slateSoft);
                    border-color: #e2e8f0;
                    color: #334155
                }

                .b-invoiced {
                    background: var(--amberSoft);
                    color: #92400e;
                    border-color: #fde68a
                }

                .b-paid {
                    background: var(--greenSoft);
                    color: #166534;
                    border-color: #d1fae5
                }

                .b-downgraded {
                    background: var(--redSoft);
                    color: #991b1b;
                    border-color: #fecaca
                }

                .b-pay-paid {
                    background: var(--greenSoft);
                    color: #166534;
                    border-color: #d1fae5
                }

                .b-pay-due {
                    background: var(--amberSoft);
                    color: #92400e;
                    border-color: #fde68a
                }

                .b-pay-downgraded {
                    background: var(--redSoft);
                    color: #991b1b;
                    border-color: #fecaca
                }

                .openIcon {
                    font-size: 16px;
                    color: var(--muted)
                }

                .actionBtn {
                    width: 36px;
                    height: 36px;
                    border-radius: 14px;
                    border: 1px solid var(--border);
                    background: #fff;
                    display: grid;
                    place-items: center
                }

                .foot {
                    color: var(--muted);
                    font-size: 12px;
                    margin-top: 14px;
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    gap: 12px;
                    flex-wrap: wrap;
                }

                .pager {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    flex-wrap: wrap;
                }

                .pager a {
                    border: 1px solid var(--border);
                    background: #fff;
                    padding: 8px 10px;
                    border-radius: 999px;
                    font-size: 13px;
                    color: var(--text);
                }

                .pager a.active {
                    background: var(--blueSoft);
                    border-color: #dbeafe;
                    color: #1d4ed8;
                    font-weight: 700;
                }

                /* Drawer */
                .drawerTop {
                    display: flex;
                    align-items: flex-start;
                    justify-content: space-between;
                    margin-bottom: 18px
                }

                .dTitle {
                    font-size: 20px;
                    font-weight: 850;
                    margin: 0
                }

                .dSub {
                    margin-top: 6px;
                    color: var(--muted);
                    font-size: 13px
                }

                .xbtn {
                    border: none;
                    background: transparent;
                    font-size: 20px;
                    cursor: pointer;
                    color: var(--muted)
                }

                .pillRow {
                    display: flex;
                    gap: 10px;
                    margin: 14px 0 18px 0;
                    flex-wrap: wrap
                }

                .section {
                    padding: 16px 0;
                    border-top: 1px solid var(--border)
                }

                .section:first-of-type {
                    border-top: none
                }

                .sTitle {
                    font-size: 13px;
                    font-weight: 850;
                    margin: 0 0 10px 0
                }

                .refBox {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    border: 1px solid var(--border);
                    border-radius: 14px;
                    padding: 12px;
                    background: #fff
                }

                .mono {
                    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
                    font-size: 13px
                }

                .copyBtn {
                    border: 1px solid var(--border);
                    background: #fff;
                    border-radius: 12px;
                    width: 38px;
                    height: 38px;
                    cursor: pointer
                }

                .kv {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 10px 14px
                }

                .k {
                    color: var(--muted);
                    font-size: 13px
                }

                .v {
                    font-size: 13px;
                    text-align: right
                }

                .timeline {
                    display: flex;
                    flex-direction: column;
                    gap: 10px
                }

                .tItem {
                    display: flex;
                    align-items: flex-start;
                    gap: 10px
                }

                .tDot {
                    width: 10px;
                    height: 10px;
                    border-radius: 999px;
                    background: #cbd5e1;
                    margin-top: 5px;
                    flex: 0 0 auto
                }

                .tDot.on {
                    background: var(--blue)
                }

                .tLabel {
                    font-size: 13px
                }

                .tDate {
                    margin-left: auto;
                    color: var(--muted);
                    font-size: 12px
                }

                .drawerFooter {
                    display: flex;
                    gap: 10px;
                    margin-top: 18px
                }

                .btnWide {
                    flex: 1;
                    border-radius: 14px;
                    padding: 12px 14px;
                    font-weight: 750;
                    font-size: 13px
                }

                .btnWide.primary {
                    background: var(--blue);
                    color: #fff;
                    border: none
                }

                .btnWide.secondary {
                    background: #fff;
                    color: #0b1220;
                    border: 1px solid var(--border)
                }

                @media (max-width: 1100px) {
                    .wrap {
                        flex-direction: column
                    }

                    .drawer {
                        width: 100%;
                        max-width: none;
                        border-left: none;
                        border-top: 1px solid var(--border);
                        position: relative;
                        height: auto;
                    }
                }
            </style>
        </head>

        <body>
            <div class="wrap">
                <div class="main">
                    <div class="topbar">
                        <div class="brand">SA Private Schools</div>
                        <div class="topRight">
                            <label class="toggleWrap" title="Toggle visibility of downgraded schools">
                                <input type="checkbox" id="show_downgraded" <?php echo (!empty($filters['show_downgraded']) && $filters['show_downgraded'] === '1') ? 'checked' : ''; ?> />
                                <span class="toggleUi"></span>
                                <span class="toggleText">Show downgraded</span>
                            </label>
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

                            <select id="year" class="select" style="min-width:110px;">
                                <?php
                                $cur_filter = (string) $filters['year'];
                                // Option: All Time
                                $sel = (strtoupper($cur_filter) === 'ALL') ? 'selected' : '';
                                echo '<option value="ALL" ' . $sel . '>Year: All time</option>';

                                // Available years from data + Current Year standard range
                                $years = $data['available_years'] ?? [];
                                $years[] = date('Y');
                                $years = array_unique($years);
                                rsort($years);

                                foreach ($years as $y) {
                                    $sel = ($cur_filter === (string) $y) ? 'selected' : '';
                                    echo '<option value="' . esc_attr($y) . '" ' . $sel . '>Year: ' . esc_html($y) . '</option>';
                                }
                                ?>
                            </select>

                            <select id="status" class="select">
                                <?php
                                $opts = ['ALL', 'PAID', 'INVOICED', 'DOWNGRADED'];
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
                        <?php echo $this->kpi_card($this->icon('users'), (int) $data['kpi']['CLIENTS'], 'Clients'); ?>
                        <?php echo $this->kpi_card($this->icon('check'), (int) $data['kpi']['PAID'], 'Paid'); ?>
                        <?php echo $this->kpi_card($this->icon('arrowDown'), (int) $data['kpi']['DOWNGRADED'], 'Downgraded'); ?>
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
                                    <div class="school">
                                        <?php echo esc_html($r['school']); ?>
                                    </div>
                                    <div class="meta">
                                        <?php echo esc_html($r['owner_name']); ?> · User
                                        #
                                        <?php echo esc_html((string) $r['user_id']); ?>
                                    </div>

                                    <?php if (!empty($r['alert_missing_expiry']) || !empty($r['alert_due_not_invoiced'])): ?>
                                        <div class="alertPills">
                                            <?php if (!empty($r['alert_missing_expiry'])): ?>
                                                <span class="apill gray">
                                                    <?php echo $this->icon('alert'); ?>Missing expiry
                                                </span>
                                            <?php endif; ?>
                                            <?php if (!empty($r['alert_due_not_invoiced'])): ?>
                                                <span class="apill warn">
                                                    <?php echo $this->icon('flag'); ?>Due but not invoiced
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div>
                                    <?php echo $this->status_badge($r['status']); ?>
                                </div>
                                <div>
                                    <?php echo $this->payment_badge($r['payment']); ?>
                                </div>
                                <div class="openIcon">
                                    <?php echo $r['opened_any'] ? '✓' : '—'; ?>
                                </div>
                                <div style="font-weight:750;">
                                    <?php echo esc_html($r['days_label'] ?? '—'); ?>
                                </div>
                                <div style="display:flex;justify-content:flex-end;">
                                    <div class="actionBtn">→</div>
                                </div>
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

                function renderDrawer(r) {
                    if (!r) { document.getElementById('drawer').innerHTML = ''; return; }

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

                    const payClass = r.payment === 'PAID' ? 'b-pay-paid' :
                        r.payment === 'DOWNGRADED' ? 'b-pay-downgraded' : 'b-pay-due';

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
                    <button class="copyBtn" onclick="navigator.clipboard.writeText('${ref.replace(/'/g, "\\'")}')">⧉</button>
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
            </div>

            <!-- Actions Section -->
            <div class="section" style="margin-top:20px;padding-top:20px;border-top:2px dashed #e2e8f0;">
                <div class="sTitle" style="margin-bottom:15px;">Manual Actions</div>
                
                <div style="display:flex;flex-direction:column;gap:12px;">
                    
                    <!-- Evergreen Toggle -->
                    <button class="btnWide secondary" onclick="doAction('toggle_evergreen', ${r.user_id})">
                        ${r.is_evergreen ? 'Disable Evergreen (Allow Downgrade)' : 'Enable Evergreen (Suppress Alerts)'}
                    </button>

                    <!-- Upgrade (Date Picker Reveal) -->
                     <details style="border:1px solid #e2e8f0;border-radius:12px;padding:10px;background:#f8fafc;">
                        <summary style="font-size:13px;font-weight:750;cursor:pointer;color:var(--text);margin-bottom:8px;">Manually Upgrade...</summary>
                        <div style="margin-top:10px;">
                            <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:4px;">Effective Date (YYYY-MM-DD)</label>
                            <input type="date" id="upgrade_date_${r.user_id}" value="<?php echo date('Y-m-d'); ?>" style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;margin-bottom:8px;">
                            
                            <label style="display:flex;align-items:center;cursor:pointer;font-size:13px;color:var(--text);margin-bottom:12px;">
                                <input type="checkbox" id="upgrade_email_${r.user_id}" checked style="width:18px;height:18px;margin-right:8px;accent-color:#16a34a;">
                                Send confirmation email
                            </label>
                            <button class="btnWide primary" style="background:#16a34a;" onclick="doAction('upgrade', ${r.user_id})">Apply Upgrade</button>
                        </div>
                    </details>


                    <!-- Downgrade -->
                    <button class="btnWide secondary" style="color:#dc2626;border-color:#fecaca;background:#fef2f2;" onclick="if(confirm('Are you sure you want to downgrade this user immediately? This will remove premium features and send an email.')) doAction('downgrade', ${r.user_id})">
                        Downgrade to Free
                    </button>

                </div>
            </div>
        `;
        }

        const actionUrl = `${window.location.origin}/renewals/action`;
        const nonce = "<?php echo esc_js(wp_create_nonce('ngd_renewals_action')); ?>";

        async function doAction(type, userId) {
            const payload = { do: type, user_id: userId, nonce: nonce };

            if (type === 'upgrade') {
                const dateInput = document.getElementById('upgrade_date_' + userId);
                const emailInput = document.getElementById('upgrade_email_' + userId);

                if (!dateInput || !dateInput.value) {
                    alert('Please select an effective date');
                    return;
                }

                payload.effective_date = dateInput.value;
                payload.send_email = (emailInput && emailInput.checked) ? 1 : 0;

                const emailMsg = payload.send_email ? "and send the success email" : "and NO email will be sent (silent)";

                if (!confirm('Confirm upgrade for ' + payload.effective_date + '? This will charge them 0, set expiry to selected date, ' + emailMsg + '.')) return;
            }

            // UI Feedback
            const oldBody = document.body.style.pointerEvents;
            document.body.style.pointerEvents = 'none';
            document.body.style.opacity = '0.7';

            try {
                const res = await fetch(actionUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const json = await res.json();

                if (json.success) {
                    alert((json.data && json.data.message) ? json.data.message : 'Done.');
                    window.location.reload();
                } else {
                    alert('Error: ' + (json.data && json.data.message ? json.data.message : 'Unknown error'));
                }
            } catch (e) {
                alert('Network error: ' + e);
            } finally {
                document.body.style.pointerEvents = oldBody;
                document.body.style.opacity = '1';
            }
        }

        function applyFiltersAndReload(extraParams = {}) {
            const q = document.getElementById('q').value.trim();
            const year = document.getElementById('year') ? document.getElementById('year').value : '';
            const status = document.getElementById('status').value;
            const issue = document.getElementById('issue') ? document.getElementById('issue').value : 'ALL';
            const perPage = document.getElementById('per_page').value;

            const showDowngraded = document.getElementById('show_downgraded') ? (document.getElementById('show_downgraded').checked ? '1' : '0') : '0';

            const params = new URLSearchParams(window.location.search);

            // Reset page whenever filters change
            params.delete('ngd_page');

            if (q) params.set('ngd_q', q); else params.delete('ngd_q');
            if (year) params.set('ngd_year', year); else params.delete('ngd_year');
            if (status && status !== 'ALL') params.set('ngd_status', status); else params.delete('ngd_status');

            if (issue && issue !== 'ALL') params.set('ngd_issue', issue); else params.delete('ngd_issue');
            if (perPage) params.set('ngd_per_page', perPage);

            if (showDowngraded === '1') params.set('ngd_show_downgraded', '1');
            else params.delete('ngd_show_downgraded');

            Object.keys(extraParams).forEach(k => {
                if (extraParams[k] === null) params.delete(k);
                else params.set(k, extraParams[k]);
            });

            window.location.href = baseUrl + (params.toString() ? ('?' + params.toString()) : '');
        }

        document.getElementById('q').addEventListener('keydown', (e) => { if (e.key === 'Enter') applyFiltersAndReload(); });
        if (document.getElementById('year')) document.getElementById('year').addEventListener('change', () => applyFiltersAndReload());
        document.getElementById('status').addEventListener('change', () => applyFiltersAndReload());
        if (document.getElementById('issue')) {
            document.getElementById('issue').addEventListener('change', () => applyFiltersAndReload());
        }
        document.getElementById('per_page').addEventListener('change', () => applyFiltersAndReload());

        if (document.getElementById('show_downgraded')) {
            document.getElementById('show_downgraded').addEventListener('change', () => applyFiltersAndReload());
        }

        document.querySelectorAll('.row').forEach(el => {
            el.addEventListener('click', () => {
                const r = JSON.parse(el.getAttribute('data-row'));
                renderDrawer(r);
            });
        });

        // Sorting
        document.querySelectorAll('[data-sort]').forEach(el => {
            el.addEventListener('click', () => {
                const key = el.getAttribute('data-sort');
                const currentSort = '<?php echo esc_js($sort); ?>';
                const currentDir = '<?php echo esc_js($dir); ?>';

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

</html>
<?php
    }

    private function head_cell(string $label, string $sort_key, string $current_sort, string $current_dir): string
    {
        $is_on = ($current_sort === $sort_key);
        $arrow = '↕';
        if ($is_on)
            $arrow = ($current_dir === 'asc') ? '↑' : '↓';

        $icon_class = $is_on ? 'sortIcon on' : 'sortIcon';

        return '
            <div class="hcell" data-sort="' . esc_attr($sort_key) . '">
                <span>' . esc_html($label) . '</span>
                <span class="' . esc_attr($icon_class) . '">' . esc_html($arrow) . '</span>
            </div>
        ';
    }

    private function render_pagination(string $base_url, array $filters, array $meta): string
    {
        $page = (int) $meta['page'];
        $total_pages = (int) $meta['total_pages'];

        if ($total_pages <= 1)
            return '';

        $html = '';

        $mk = function (int $p) use ($base_url, $filters, $meta) {
            return esc_url($base_url . $this->build_query_string([
                'ngd_q' => $filters['q'],
                'ngd_status' => $filters['status'],
                'ngd_issue' => $filters['issue'] ?? 'ALL',
                'ngd_year' => $filters['year'],
                'ngd_show_downgraded' => $filters['show_downgraded'] ?? '0',
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
            if ($start > 2)
                $html .= '<span style="color:#94a3b8;">…</span>';
        }

        for ($p = $start; $p <= $end; $p++) {
            $cls = ($p === $page) ? 'active' : '';
            $html .= '<a class="' . esc_attr($cls) . '" href="' . $mk($p) . '">' . esc_html((string) $p) . '</a>';
        }

        if ($end < $total_pages) {
            if ($end < $total_pages - 1)
                $html .= '<span style="color:#94a3b8;">…</span>';
            $html .= '<a href="' . $mk($total_pages) . '">' . esc_html((string) $total_pages) . '</a>';
        }

        if ($page < $total_pages) {
            $html .= '<a href="' . $mk($page + 1) . '">Next</a>';
        }

        return $html;
    }

    /* =========================================================
     * UI HELPERS
     * ========================================================= */

    private function kpi_card(string $icon_svg, int $number, string $label): string
    {
        return '
            <div class="card">
                <div class="krow">
                    <div class="kicon">' . $icon_svg . '</div>
                    <div>
                        <div class="knum">' . esc_html((string) $number) . '</div>
                        <div class="klabel">' . esc_html($label) . '</div>
                    </div>
                </div>
            </div>
        ';
    }

    private function status_badge(string $status): string
    {
        $status = strtoupper($status);

        if ($status === 'PAID') {
            return '<span class="badge b-paid">' . $this->icon('check') . 'PAID</span>';
        }
        if ($status === 'INVOICED') {
            return '<span class="badge b-invoiced">' . $this->icon('bolt') . 'INVOICED</span>';
        }

        if ($status === 'EVERGREEN') {
            return '<span class="badge b-paid" style="background:#dcfce7;color:#166534;border-color:#bbf7d0;">' . $this->icon('shield') . 'EVERGREEN</span>';
        }

        return '<span class="badge b-downgraded">' . $this->icon('arrowDown') . 'DOWNGRADED</span>';
    }

    private function payment_badge(string $payment): string
    {
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

    private function build_query_string(array $params): string
    {
        $filtered = [];
        foreach ($params as $k => $v) {
            if ($v === '' || $v === null)
                continue;
            if ($v === false)
                continue;
            $filtered[$k] = is_bool($v) ? ($v ? '1' : '0') : (string) $v;
        }
        return $filtered ? ('?' . http_build_query($filtered)) : '';
    }

    private function icon(string $name): string
    {
        // Minimal inline SVGs (no external libs, clean modern)
        $common = 'width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"';
        $stroke = 'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';

        switch ($name) {
            case 'search':
                return '<svg ' . $common . ' ' . $stroke . '><circle cx="11" cy="11" r="7"/><path d="M20 20l-3-3"/></svg>';
            case 'users':
                return '<svg ' . $common . ' ' . $stroke . '><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
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

    // --- ADMIN OPS UI ---

    public function register_admin_menu(): void
    {
        add_menu_page(
            'Renewals Ops',
            'Renewals Ops',
            'manage_options',
            'ngd-renewals-ops',
            [$this, 'render_ops_page'],
            'dashicons-email-alt',
            58
        );
    }

    public function register_cron_schedules(array $schedules): array
    {
        if (!isset($schedules['ngd_every_minute'])) {
            $schedules['ngd_every_minute'] = [
                'interval' => 60,
                'display' => 'NGD Every Minute',
            ];
        }
        return $schedules;
    }

    public function ensure_queue_dispatcher_scheduled(): void
    {
        // Avoid re-scheduling on every request
        if (!wp_next_scheduled('ngd_renewals_queue_dispatch')) {
            wp_schedule_event(time() + 60, 'ngd_every_minute', 'ngd_renewals_queue_dispatch');
        }
    }

    public function run_queue_dispatcher(): void
    {
        if (class_exists('NGD_Renewals_Queue')) {
            // Process due items only (send_after_ts respected)
            NGD_Renewals_Queue::process_batch(50);
        }
    }

    public function render_ops_page(): void
    {
        global $wpdb;

        $t = $wpdb->prefix . 'ngd_renewals_queue';

        // Ensure queue table exists (plugin updates don’t re-run activation hooks)
        if (class_exists('NGD_Renewals_Queue')) {
            NGD_Renewals_Queue::ensure_ready();
        }

        // Handle Autopilot toggle
        if (isset($_POST['ngd_autopilot_toggle']) && check_admin_referer('ngd_ops_settings')) {
            $enabled = isset($_POST['ngd_autopilot_enabled']) ? 1 : 0;
            update_option('ngd_renewals_autopilot_enabled', $enabled);
            echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
        }

        // Handle Force Run Processor
        if (isset($_POST['ngd_run_processor']) && check_admin_referer('ngd_ops_settings')) {
            if (class_exists('NGD_Renewals_Queue')) {
                $summary = NGD_Renewals_Queue::process_batch(10); // small batch
                $msg = 'Queue Processor Triggered (Batch 10).';
                if (is_array($summary)) {
                    $sent = (int) ($summary['sent'] ?? 0);
                    $failed = (int) ($summary['failed'] ?? 0);
                    $next_in = (string) ($summary['next_in_hms'] ?? '—');
                    $msg .= " Sent: {$sent} • Failed: {$failed} • Next due in: {$next_in}";
                }
                echo '<div class="notice notice-success"><p>' . esc_html($msg) . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>NGD_Renewals_Queue class not found.</p></div>';
            }
        }

        // Handle Force Cron (feed the queue)
        if (isset($_POST['ngd_run_cron']) && check_admin_referer('ngd_ops_settings')) {
            if (class_exists('\\NGD_THEME\\Functions\\RenewalCron')) {
                $cron = new \NGD_THEME\Functions\RenewalCron();
                $cron->process_daily_logic();
                echo '<div class="notice notice-success"><p>Daily Checks Triggered. Check Queue for new items.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>RenewalCron class not found.</p></div>';
            }
        }

        $autopilot = (int) get_option('ngd_renewals_autopilot_enabled', 0);

        // Next scheduled cron run time
        $next_cron_ts = wp_next_scheduled('ngd_daily_renewal_check');
        $next_cron_str = $next_cron_ts ? date_i18n('Y-m-d H:i:s', $next_cron_ts) : 'Not scheduled';
        $now_ts = time();
        $now_str = current_time('mysql');

        $next_due_ts = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MIN(send_after_ts) FROM $t
                 WHERE status IN ('APPROVED','PENDING')
                   AND send_after_ts IS NOT NULL
                   AND send_after_ts > %d",
                $now_ts
            )
        );

        // Fetch Queue
        $tab = isset($_GET['tab']) ? strtoupper(sanitize_text_field($_GET['tab'])) : 'PENDING';
        $allowed = ['PENDING', 'APPROVED', 'SENT', 'FAILED', 'SKIPPED'];
        if (!in_array($tab, $allowed, true)) {
            $tab = 'PENDING';
        }

        // Sorting
        $sort = isset($_GET['sort']) ? strtolower(sanitize_text_field($_GET['sort'])) : '';
        $sort_allowed = ['countdown', 'newest'];
        if (!in_array($sort, $sort_allowed, true)) {
            // Operational default: show what is due soonest first
            $sort = in_array($tab, ['PENDING', 'APPROVED', 'FAILED'], true) ? 'countdown' : 'newest';
        }

        $order_sql = "ORDER BY created_at DESC";
        if ($sort === 'countdown') {
            // Soonest send first, missing schedule last
            $order_sql = "ORDER BY (send_after_ts IS NULL OR send_after_ts = 0) ASC, send_after_ts ASC, created_at DESC";
        } elseif ($tab === 'SENT') {
            $order_sql = "ORDER BY sent_at DESC, created_at DESC";
        }

        $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE status = %s $order_sql LIMIT 100", $tab));

        // Counts keyed by status
        $counts = $wpdb->get_results("SELECT status, COUNT(*) as c FROM $t GROUP BY status", OBJECT_K);

        // Bulk derive School + key meta
        $user_ids = [];
        foreach ($items as $it) {
            $uid = (int) $it->user_id;
            if ($uid > 0)
                $user_ids[$uid] = $uid;
        }
        $user_ids = array_values($user_ids);

        $user_info = []; // user_id => ['school'=>..., 'latest_listing_id'=>..., 'expiry_ts'=>..., 'invoice_ts'=>...]
        if (!empty($user_ids)) {
            $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
            $post_statuses = "'publish','pending','draft','private','future','expired'";

            $posts_sql = $wpdb->prepare(
                "SELECT ID, post_author, post_title, post_modified
                 FROM {$wpdb->posts}
                 WHERE post_type = 'job_listing'
                   AND post_status IN ($post_statuses)
                   AND post_author IN ($placeholders)
                 ORDER BY post_author ASC, post_modified DESC, ID DESC",
                $user_ids
            );

            $rows = $wpdb->get_results($posts_sql);
            $post_to_author = [];
            $all_post_ids = [];

            foreach ($rows as $r) {
                $uid = (int) $r->post_author;
                $pid = (int) $r->ID;
                $post_to_author[$pid] = $uid;
                $all_post_ids[$pid] = $pid;

                if (!isset($user_info[$uid])) {
                    $user_info[$uid] = [
                        'school' => (string) $r->post_title,
                        'latest_listing_id' => $pid,
                        'expiry_ts' => 0,
                        'invoice_ts' => 0
                    ];
                } else {
                    if (empty($user_info[$uid]['school']) && !empty($r->post_title)) {
                        $user_info[$uid]['school'] = (string) $r->post_title;
                    }
                }
            }

            foreach ($user_ids as $uid) {
                if (!isset($user_info[$uid])) {
                    $u = get_user_by('id', $uid);
                    $user_info[$uid] = [
                        'school' => $u ? ($u->display_name ?: ('User #' . $uid)) : ('User #' . $uid),
                        'latest_listing_id' => 0,
                        'expiry_ts' => 0,
                        'invoice_ts' => 0
                    ];
                }
            }

            if (!empty($all_post_ids)) {
                $post_ids = array_values($all_post_ids);
                $ph2 = implode(',', array_fill(0, count($post_ids), '%d'));

                $meta_sql = $wpdb->prepare(
                    "SELECT post_id, meta_key, meta_value
                     FROM {$wpdb->postmeta}
                     WHERE post_id IN ($ph2)
                       AND meta_key IN ('_job_expires','_invoice_sent_timestamp')",
                    $post_ids
                );

                $meta_rows = $wpdb->get_results($meta_sql);

                foreach ($meta_rows as $mr) {
                    $pid = (int) $mr->post_id;
                    $uid = $post_to_author[$pid] ?? 0;
                    if (!$uid || !isset($user_info[$uid]))
                        continue;

                    if ($mr->meta_key === '_job_expires') {
                        $ts = 0;
                        if (!empty($mr->meta_value)) {
                            $ts = strtotime((string) $mr->meta_value);
                        }
                        if ($ts && $ts > (int) $user_info[$uid]['expiry_ts']) {
                            $user_info[$uid]['expiry_ts'] = $ts;
                        }
                    }

                    if ($mr->meta_key === '_invoice_sent_timestamp') {
                        $inv = (int) $mr->meta_value;
                        if ($inv > (int) $user_info[$uid]['invoice_ts']) {
                            $user_info[$uid]['invoice_ts'] = $inv;
                        }
                    }
                }
            }
        }

        $format_date = function ($ts) {
            return $ts ? date_i18n('Y-m-d H:i', (int) $ts) : '—';
        };

        $expiry_delta = function (int $expiry_ts) use ($now_ts): string {
            if (!$expiry_ts)
                return 'Missing _job_expires';
            $days = (int) floor(($expiry_ts - $now_ts) / 86400);
            if ($days > 0)
                return "Expires in {$days} days";
            if ($days === 0)
                return "Expires today";
            return "Expired " . abs($days) . " days ago";
        };

        $build_timing = function (string $stage, int $expiry_ts, int $invoice_ts) use ($format_date, $expiry_delta, $now_ts): array {
            $trigger_label = '—';
            $trigger_date = '—';
            $delta = '—';

            if ($stage === 'invoice') {
                $trigger_label = 'Expires';
                $trigger_date = $format_date($expiry_ts);
                $delta = $expiry_delta($expiry_ts);
                return [$trigger_label, $trigger_date, $delta];
            }

            if (strpos($stage, 'reminder') === 0 || strpos($stage, 'downgrade') === 0) {
                $trigger_label = 'Expires';
                $trigger_date = $format_date($expiry_ts);
                $delta = $expiry_delta($expiry_ts);

                if ($invoice_ts) {
                    $days_since_inv = (int) floor(($now_ts - $invoice_ts) / 86400);
                    $delta .= " • Invoice sent {$days_since_inv} days ago";
                } else {
                    $delta .= " • Invoice timestamp missing";
                }

                return [$trigger_label, $trigger_date, $delta];
            }

            if ($expiry_ts) {
                $trigger_label = 'Expires';
                $trigger_date = $format_date($expiry_ts);
                $delta = $expiry_delta($expiry_ts);
            }

            return [$trigger_label, $trigger_date, $delta];
        };

        ?>
<div class="wrap">
    <h1>Renewals Ops Console</h1>

    <div class="notice notice-info" style="padding:10px 12px;">
        <p style="margin:0;">
            <strong>Now:</strong> <?php echo esc_html($now_str); ?>
            &nbsp;|&nbsp;
            <strong>Next renewals cron:</strong> <?php echo esc_html($next_cron_str); ?>
            &nbsp;|&nbsp;
            <strong>Autopilot:</strong>
            <?php echo $autopilot ? '<span style="color:#0a7;">ON</span>' : '<span style="color:#a00;">OFF</span>'; ?>
            &nbsp;—&nbsp;
            <?php if ($autopilot): ?>
            Pending/Approved items may send on the next cron run.
            <?php else: ?>
            Nothing sends unless you approve and manually run the processor.
            <?php endif; ?>
            &nbsp;|&nbsp;
            <strong>Next queued send in:</strong>
            <span id="ngd-next-send-countdown" data-send-after-ts="<?php echo (int) $next_due_ts; ?>">—</span>
        </p>
    </div>

    <form method="post"
        style="background:#fff;padding:15px;border:1px solid #ccc;margin:20px 0;display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
        <?php wp_nonce_field('ngd_ops_settings'); ?>
        <label style="font-weight:bold;font-size:1.1em;">
            <input type="checkbox" name="ngd_autopilot_enabled" value="1" <?php checked($autopilot, 1); ?>>
            Enable Autopilot
        </label>
        <button type="submit" name="ngd_autopilot_toggle" class="button button-primary">Save Settings</button>
        <button type="submit" name="ngd_run_cron" class="button button-secondary">Run Daily Checks (Feed Queue)</button>
        <button type="submit" name="ngd_run_processor" class="button button-secondary">Run Processor (Send
            Emails)</button>
        <div style="font-size: 0.9em; color:#666;">
            Run Processor respects scheduled times (Send at).
        </div>
    </form>

    <h2 class="nav-tab-wrapper">
        <?php
        $tabs = ['PENDING', 'APPROVED', 'SENT', 'FAILED', 'SKIPPED'];
        foreach ($tabs as $st) {
            $c = isset($counts[$st]) ? (int) $counts[$st]->c : 0;
            $active = ($tab === $st) ? 'nav-tab-active' : '';
            echo "<a href='?page=ngd-renewals-ops&tab=$st&sort={$sort}' class='nav-tab $active'>$st ($c)</a>";
        }
        ?>
    </h2>

    <p style="margin:10px 0 16px 0; color:#444;">
        <strong>Sort:</strong>
        <?php
        $base = '?page=ngd-renewals-ops&tab=' . urlencode($tab);
        $a1 = $base . '&sort=countdown';
        $a2 = $base . '&sort=newest';
        $s1 = ($sort === 'countdown') ? 'font-weight:700;text-decoration:underline;' : '';
        $s2 = ($sort === 'newest') ? 'font-weight:700;text-decoration:underline;' : '';
        ?>
        <a href="<?php echo esc_url($a1); ?>" style="<?php echo esc_attr($s1); ?>">Countdown (soonest first)</a>
        &nbsp;|&nbsp;
        <a href="<?php echo esc_url($a2); ?>" style="<?php echo esc_attr($s2); ?>">Newest queued</a>
    </p>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:170px;">Queued</th>
                <th>School</th>
                <th style="width:190px;">User</th>
                <th style="width:120px;">Stage</th>
                <th style="width:140px;">Trigger</th>
                <th>Delta</th>
                <th style="width:170px;">Send at</th>
                <th style="width:120px;">Countdown</th>
                <th style="width:120px;">Source</th>
                <th style="width:160px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($items)): ?>
            <tr>
                <td colspan="10">No items found in <?php echo esc_html($tab); ?>.</td>
            </tr>
            <?php else:
                foreach ($items as $i):
                    $uid = (int) $i->user_id;
                    $user = get_user_by('id', $uid);
                    $email = $user ? $user->user_email : 'Unknown';

                    $school = $user_info[$uid]['school'] ?? ('User #' . $uid);
                    $expiry_ts = (int) ($user_info[$uid]['expiry_ts'] ?? 0);
                    $invoice_ts = (int) ($user_info[$uid]['invoice_ts'] ?? 0);

                    [$trigger_label, $trigger_date, $delta] = $build_timing($i->stage, $expiry_ts, $invoice_ts);

                    $send_after_ts = isset($i->send_after_ts) ? (int) $i->send_after_ts : 0;
                    $send_at_str = $send_after_ts ? date_i18n('Y-m-d H:i:s', $send_after_ts) : '—';

                    $open = admin_url('user-edit.php?user_id=' . $uid);
                    ?>
            <tr>
                <td><?php echo esc_html($i->created_at); ?></td>
                <td><strong><?php echo esc_html($school); ?></strong></td>
                <td>
                    <strong>#<?php echo esc_html($uid); ?></strong>
                    <?php
                    $is_silenced = class_exists('NGD_Renewals_Silence') && NGD_Renewals_Silence::is_silenced($uid);
                    if ($is_silenced) {
                        echo '<span style="display:inline-block;margin-left:6px;padding:2px 6px;border-radius:10px;background:#fee;color:#a00;font-size:11px;">SILENCED</span>';
                    }
                    ?>
                    <br>
                    <a href="<?php echo esc_url($open); ?>" target="_blank"><?php echo esc_html($email); ?></a>
                </td>
                <td><?php echo esc_html($i->stage); ?><br><small><?php echo esc_html($i->status); ?></small></td>
                <td>
                    <div><strong><?php echo esc_html($trigger_label); ?></strong></div>
                    <div><?php echo esc_html($trigger_date); ?></div>
                </td>
                <td><?php echo esc_html($delta); ?></td>
                <td><?php echo esc_html($send_at_str); ?></td>
                <td>
                    <span class="ngd-countdown" data-send-after-ts="<?php echo (int) $send_after_ts; ?>"
                        data-status="<?php echo esc_attr($i->status); ?>">—</span>
                </td>
                <td><?php echo esc_html($i->source ?? $i->recommended_by); ?></td>
                <td>
                    <?php if ($i->status === 'PENDING'): ?>
                    <button class="button button-primary ngd-q-btn" data-action="approve"
                        data-id="<?php echo (int) $i->id; ?>">Approve</button>
                    <button class="button ngd-q-btn" data-action="skip"
                        data-id="<?php echo (int) $i->id; ?>">Skip</button>
                    <br><br>
                    <?php endif; ?>
                    <?php if ($i->status === 'SKIPPED'): ?>
                    <button class="button ngd-q-btn" data-action="restore"
                        data-id="<?php echo (int) $i->id; ?>">Restore</button>
                    <br><br>
                    <?php endif; ?>
                    <?php if (in_array($i->status, ['APPROVED', 'PENDING'], true)): ?>
                    <button class="button button-secondary ngd-q-btn" data-action="send_now"
                        data-id="<?php echo (int) $i->id; ?>">Send Now</button>
                    <br><br>
                    <?php endif; ?>
                    <?php if (in_array($i->status, ['PENDING', 'APPROVED'], true)): ?>
                    <button class="button ngd-q-btn" data-action="send_test" data-id="<?php echo (int) $i->id; ?>">Send
                        Test</button>
                    <br><br>
                    <?php endif; ?>


                    <button class="button ngd-q-btn" style="font-size:11px;"
                        data-action="<?php echo $is_silenced ? 'unsilence_user' : 'silence_user'; ?>"
                        data-uid="<?php echo (int) $uid; ?>">
                        <?php echo $is_silenced ? 'Unsilence User' : 'Silence User'; ?>
                    </button>
                </td>
            </tr>
            <?php endforeach;
            endif; ?>
        </tbody>
    </table>

    <script>
        jQuery(document).ready(function ($) {
            $('.ngd-q-btn').off('click').on('click', function (e) {
                e.preventDefault();

                var btn = $(this);
                var act = String(btn.data('action') || '');
                var id = 0;
                var uid = 0;

                // Actions that operate on a queue row ID
                if (act === 'approve' || act === 'skip' || act === 'send_now' || act === 'restore' || act === 'send_test') {
                    id = parseInt(btn.data('id') || 0, 10);
                }

                // Actions that operate on a user ID
                if (act === 'silence_user' || act === 'unsilence_user') {
                    uid = parseInt(btn.data('uid') || 0, 10);
                }

                if (!id && !uid) {
                    alert('Error: Missing queue id / user id on button. (Check data-id / data-uid)');
                    return;
                }

                var originalText = btn.text();
                btn.prop('disabled', true).text('Processing...');

                $.post(ajaxurl, {
                    action: 'ngd_queue_action',
                    id: id,
                    uid: uid,
                    do: act,
                    nonce: '<?php echo wp_create_nonce("ngd_queue_op"); ?>'
                }, function (res) {
                    if (res && res.success) {

                        if (act === 'send_now') {
                            alert('Sent ✅');
                            location.reload();
                            return;
                        }

                        if (act === 'send_test') {
                            alert('Test email sent to darren@2ko.co.za ✅');
                            btn.prop('disabled', false).text('Send Test');
                            return;
                        }

                        if (act === 'restore') {
                            alert('Restored ✅');
                            location.reload();
                            return;
                        }

                        btn.closest('tr').fadeOut();

                    } else {
                        var msg = (res && res.data) ? res.data : 'Unknown error';
                        alert('Error: ' + msg);
                        btn.prop('disabled', false).text(originalText);
                    }
                }).fail(function () {
                    alert('Error: AJAX request failed');
                    btn.prop('disabled', false).text(originalText);
                });
            });

            function ngdFormatHMS(totalSeconds) {
                totalSeconds = Math.max(0, Math.floor(totalSeconds));
                var h = Math.floor(totalSeconds / 3600);
                var m = Math.floor((totalSeconds % 3600) / 60);
                var s = totalSeconds % 60;
                return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
            }

            function ngdRenderCountdown($el) {
                var sendAfter = parseInt($el.data('send-after-ts') || 0, 10);
                var status = String($el.data('status') || '');
                var now = Math.floor(Date.now() / 1000);

                if (status === 'SENT') {
                    $el.text('Sent');
                    return;
                }

                if (!sendAfter) {
                    if (status === 'PENDING') {
                        $el.text('Awaiting approval');
                    } else if (status === 'APPROVED') {
                        $el.text('Scheduled (missing)');
                    } else {
                        $el.text('—');
                    }
                    return;
                }

                var diff = sendAfter - now;
                if (diff >= 0) {
                    $el.text(ngdFormatHMS(diff));
                } else {
                    $el.text('Overdue ' + ngdFormatHMS(Math.abs(diff)));
                }
            }

            setInterval(function () {
                $('.ngd-countdown').each(function () { ngdRenderCountdown($(this)); });

                var $global = $('#ngd-next-send-countdown');
                if ($global.length) {
                    ngdRenderCountdown($global);
                }
            }, 1000);

            // initial paint
            $('.ngd-countdown').each(function () { ngdRenderCountdown($(this)); });
            var $global = $('#ngd-next-send-countdown');
            if ($global.length) { ngdRenderCountdown($global); }
        });
    </script>
</div>
<?php
    }


    public function handle_queue_ajax(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Auth');
        }

        check_ajax_referer('ngd_queue_op', 'nonce');

        global $wpdb;
        $t = $wpdb->prefix . 'ngd_renewals_queue';

        $id = (int) ($_POST['id'] ?? 0);
        $uid = (int) ($_POST['uid'] ?? 0);
        $act = (string) ($_POST['do'] ?? '');

        if (!$id && !$uid) {
            wp_send_json_error('Missing id/uid');
        }

        if (class_exists('NGD_Renewals_Queue')) {
            NGD_Renewals_Queue::ensure_ready();
        }

        if ($act === 'approve') {

            $send_after_ts = class_exists('NGD_Renewals_Queue')
                ? NGD_Renewals_Queue::compute_send_after_ts()
                : (time() + 300);

            $wpdb->update($t, [
                'status' => 'APPROVED',
                'approved_at' => current_time('mysql'),
                'send_after_ts' => $send_after_ts,
            ], ['id' => $id]);

            wp_send_json_success(['status' => 'APPROVED', 'send_after_ts' => $send_after_ts]);

        } elseif ($act === 'send_now') {

            if (!class_exists('NGD_Renewals_Queue')) {
                wp_send_json_error('NGD_Renewals_Queue missing');
            }

            $res = NGD_Renewals_Queue::send_queue_item_now($id);
            if (!($res['ok'] ?? false)) {
                wp_send_json_error(($res['result'] ?? 'Send Now failed'));
            }
            wp_send_json_success($res);

        } elseif ($act === 'send_test') {

            if (!class_exists('NGD_Renewals_Queue')) {
                wp_send_json_error('NGD_Renewals_Queue missing');
            }

            $res = NGD_Renewals_Queue::send_queue_item_test($id, 'darren@2ko.co.za');
            if (!($res['ok'] ?? false)) {
                wp_send_json_error(($res['result'] ?? 'Send Test failed'));
            }
            wp_send_json_success($res);

        } elseif ($act === 'restore') {

            // Move from SKIPPED back to APPROVED (scheduled by your normal approval policy)
            $send_after_ts = class_exists('NGD_Renewals_Queue')
                ? NGD_Renewals_Queue::compute_send_after_ts()
                : (time() + 300);

            $wpdb->update($t, [
                'status' => 'APPROVED',
                'approved_at' => current_time('mysql'),
                'send_after_ts' => $send_after_ts,
                'send_result' => null,
            ], ['id' => $id]);

            wp_send_json_success(['status' => 'APPROVED', 'send_after_ts' => $send_after_ts]);

        } elseif ($act === 'skip') {

            $wpdb->update($t, ['status' => 'SKIPPED'], ['id' => $id]);
            wp_send_json_success(['status' => 'SKIPPED']);

        } elseif ($act === 'silence_user') {

            if (class_exists('NGD_Renewals_Silence') && $uid) {
                NGD_Renewals_Silence::silence($uid, get_current_user_id());
                wp_send_json_success(['status' => 'OK']);
            }
            wp_send_json_error('Silence class missing or invalid uid');

        } elseif ($act === 'unsilence_user') {

            if (class_exists('NGD_Renewals_Silence') && $uid) {
                NGD_Renewals_Silence::unsilence($uid);
                wp_send_json_success(['status' => 'OK']);
            }
            wp_send_json_error('Silence class missing or invalid uid');

        } else {
            wp_send_json_error('Invalid action');
        }
    }
}

new NGD_Renewals_Dashboard();

/**
 * SCOPE LOCKDOWN
 */
class NGD_Renewals_Scope
{
    private static $allowlist = [
        1374,
        335,
        1190,
        171,
        1093,
        1104,
        522,
        64,
        158,
        261,
        267,
        284,
        365,
        2689,
        695,
        1149,
        1251,
        761,
        800,
        804,
        896,
        920,
        987,
        988,
        1023,
        265,
        3696,
        1018,
        2409,
        1969,
        820,
        1012
    ];

    public static function in_scope(int $user_id): bool
    {
        // Default to allowlist unless constant overrides
        $mode = defined('NGD_RENEWALS_SCOPE_MODE') ? NGD_RENEWALS_SCOPE_MODE : 'allowlist';

        if ($mode === 'all') {
            return true;
        }

        return in_array($user_id, self::$allowlist, true);
    }

    public static function allowlist_user_ids(): array
    {
        return self::$allowlist;
    }
}


/**
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
            'post_type' => 'job_listing',
            'post_status' => ['publish', 'pending', 'draft', 'private', 'future', 'expired'],
            'author' => $user_id,
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        if (empty($listing_ids)) {
            return self::response('NONE', 'NONE', 'No listings found', true, 'No Listings');
        }

        // 3) Analyse listings
        $has_due = false;
        $max_invoice_sent = 0;
        $max_expires = 0;
        $paid_package_id = 247687;
        $is_premium = false;
        $sent_flags = [];

        foreach ($listing_ids as $pid) {
            $pm = get_post_meta($pid);

            // Payment status
            if (($pm['_payment_status'][0] ?? '') === 'DUE') {
                $has_due = true;
            }

            // Premium detection
            if (
                (int) ($pm['_package_id'][0] ?? 0) === (int) $paid_package_id ||
                (($pm['_featured'][0] ?? '') === '1')
            ) {
                $is_premium = true;
            }

            // Invoice sent timestamp
            $inv_ts = (int) ($pm['_invoice_sent_timestamp'][0] ?? 0);
            if ($inv_ts > $max_invoice_sent) {
                $max_invoice_sent = $inv_ts;
            }

            // Expiry timestamp (take max)
            $exp_raw = $pm['_job_expires'][0] ?? '';
            if (!empty($exp_raw)) {
                $ts = strtotime((string) $exp_raw);
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
            if ($days_to_expiry > 0)
                return "Expires in {$days_to_expiry} days";
            if ($days_to_expiry === 0)
                return "Expires today";
            return "Expired " . abs($days_to_expiry) . " days ago";
        };

        // 4) Logic Tree

        // A) DUE users: choose reminders/downgrades based on EXPIRY windows
        if ($has_due) {
            if (!$max_expires) {
                $days_elapsed = $max_invoice_sent ? (int) floor((time() - $max_invoice_sent) / 86400) : 0;
                return self::response('NONE', 'DUE', "DUE but missing expiry date (invoice age: {$days_elapsed}d)");
            }

            $days_to_expiry = (int) floor(($max_expires - time()) / 86400);

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

        $days_to_expiry = (int) floor(($max_expires - time()) / 86400);

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
            'stage' => $stage,
            'payment_state' => $payment_state,
            'reason' => $reason,
            'blocked' => $blocked,
            'blocked_reason' => $blocked_reason,
        ];
    }
}

/**
 * QUEUE MANAGER
 * Handles DB operations for wp_ngd_renewals_queue
 */
class NGD_Renewals_Silence
{
    public const META_KEY = '_ngd_renewals_silenced';
    public const META_AT = '_ngd_renewals_silenced_at';
    public const META_BY = '_ngd_renewals_silenced_by';

    public static function is_silenced(int $user_id): bool
    {
        return get_user_meta($user_id, self::META_KEY, true) === '1';
    }

    public static function silence(int $user_id, int $by_user_id): void
    {
        update_user_meta($user_id, self::META_KEY, '1');
        update_user_meta($user_id, self::META_AT, current_time('mysql'));
        update_user_meta($user_id, self::META_BY, (string) $by_user_id);

        self::purge_email_queue($user_id, 'Silenced by admin');
    }

    public static function unsilence(int $user_id): void
    {
        delete_user_meta($user_id, self::META_KEY);
        delete_user_meta($user_id, self::META_AT);
        delete_user_meta($user_id, self::META_BY);
    }

    public static function purge_email_queue(int $user_id, string $reason): int
    {
        global $wpdb;
        $t = $wpdb->prefix . 'ngd_renewals_queue';

        // We skip ONLY email stages. We do not interfere with any downgrade automation outside emails.
        $stages = ['invoice', 'reminder_14', 'reminder_07', 'reminder_03', 'downgrade_warn'];

        $in = implode(',', array_fill(0, count($stages), '%s'));
        $sql = "
            UPDATE {$t}
            SET status = 'SKIPPED',
                send_result = %s
            WHERE user_id = %d
              AND status IN ('PENDING','APPROVED')
              AND stage IN ($in)
        ";

        $params = array_merge([$reason, $user_id], $stages);
        $prepared = $wpdb->prepare($sql, $params);
        $wpdb->query($prepared);

        return (int) $wpdb->rows_affected;
    }
}

class NGD_Renewals_Queue
{
    private static $table = 'wp_ngd_renewals_queue';
    private static $last_send_error = '';

    private static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'ngd_renewals_queue';
    }

    private static function ensure_installed(): bool
    {
        global $wpdb;
        $t = self::table_name();
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t));
        if ($exists === $t) {
            return true;
        }

        self::install();

        $exists2 = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t));
        return ($exists2 === $t);
    }

    public static function install(): void
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ngd_renewals_queue';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            stage varchar(50) NOT NULL,
            recommended_by varchar(20) NOT NULL, -- STANDARD | CATCHUP
            reason text NOT NULL,
            status varchar(20) DEFAULT 'PENDING' NOT NULL, -- PENDING | APPROVED | SENT | FAILED | SKIPPED
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            approved_at datetime NULL,
            sent_at datetime NULL,
            send_result text NULL,
            send_after_ts bigint(20) NULL,
            source varchar(50) NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY status (status),
            KEY send_after (send_after_ts)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public static function ensure_ready(): void
    {
        if (self::ensure_installed()) {
            self::ensure_schema();
        }
    }

    public static function ensure_schema(): void
    {
        global $wpdb;
        $t = self::table_name();
        // Check if send_after_ts exists
        $col = $wpdb->get_results("SHOW COLUMNS FROM $t LIKE 'send_after_ts'");
        if (empty($col)) {
            $wpdb->query("ALTER TABLE $t ADD COLUMN send_after_ts bigint(20) NULL AFTER send_result");
            $wpdb->query("ALTER TABLE $t ADD KEY send_after (send_after_ts)");
        }
        $col2 = $wpdb->get_results("SHOW COLUMNS FROM $t LIKE 'source'");
        if (empty($col2)) {
            $wpdb->query("ALTER TABLE $t ADD COLUMN source varchar(50) NULL AFTER send_after_ts");
            // Backfill from recommended_by if useful, or just leave null
            $wpdb->query("UPDATE $t SET source = recommended_by WHERE source IS NULL");
        }
    }

    public static function normalize_send_after_ts_to_0700(int $ts): int
    {
        $ts = (int) $ts;
        if ($ts <= 0) {
            return 0;
        }

        $now = time();

        try {
            $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('Africa/Johannesburg');
        } catch (Throwable $e) {
            $tz = new DateTimeZone('UTC');
        }

        $dt = (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->setTime(7, 0, 0);
        $out = (int) $dt->getTimestamp();

        // If today's 07:00 for that date is already in the past, send immediately
        return ($out <= $now) ? $now : $out;
    }

    static function compute_send_after_ts(): int
    {
        // Policy: send at 07:00 site time. If 07:00 has already passed today, send immediately.
        return self::normalize_send_after_ts_to_0700(time());
    }

    public static function format_hms(int $seconds): string
    {
        if ($seconds <= 0)
            return '00:00:00';
        $h = floor($seconds / 3600);
        $m = floor(($seconds % 3600) / 60);
        $s = $seconds % 60;
        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }

    public static function enqueue_author(int $user_id, string $source): void
    {
        // Hard blocker: silenced users do not get renewal emails queued at all.
        if (class_exists('NGD_Renewals_Silence') && NGD_Renewals_Silence::is_silenced($user_id)) {
            return;
        }

        // SCOPE LOCKDOWN
        if (class_exists('NGD_Renewals_Scope') && !NGD_Renewals_Scope::in_scope($user_id)) {
            return;
        }

        if (!self::ensure_installed()) {
            return;
        }
        global $wpdb;
        $t = $wpdb->prefix . 'ngd_renewals_queue';

        // 1. Compute Truth
        $state = NGD_Renewals_Truth::compute_author_state($user_id);

        if ($state['blocked'] || $state['stage'] === 'NONE') {
            // Nothing to do
            return;
        }

        // 2. Idempotency Check (Don't spam queue)
        // If there is already a PENDING or APPROVED item for this user + stage created in last 24h, skip.
        $recent = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t WHERE user_id = %d AND stage = %s AND status IN ('PENDING', 'APPROVED') AND created_at > %s",
            $user_id,
            $state['stage'],
            date('Y-m-d H:i:s', strtotime('-24 hours'))
        ));

        if ($recent)
            return;

        // 3. Insert
        $data = [
            'user_id' => $user_id,
            'stage' => $state['stage'],
            'recommended_by' => $source,
            'source' => $source,
            'reason' => $state['reason'],
            'status' => 'PENDING',
            'created_at' => current_time('mysql'),
            'send_after_ts' => null
        ];

        // Autopilot
        $autopilot = (int) get_option('ngd_renewals_autopilot_enabled', 0);
        if ($autopilot) {
            $data['status'] = 'APPROVED';
            $data['approved_at'] = current_time('mysql');
            $data['send_after_ts'] = self::compute_send_after_ts();
        }

        $wpdb->insert($t, $data);
    }

    public static function get_pending_counts(): array
    {
        if (!self::ensure_installed()) {
            return [];
        }
        global $wpdb;
        $t = $wpdb->prefix . 'ngd_renewals_queue';
        return $wpdb->get_results("SELECT stage, COUNT(*) as c FROM $t WHERE status='PENDING' GROUP BY stage", ARRAY_A);
    }

    public static function send_preflight_summary($to = 'darren@2ko.co.za'): void
    {
        if (!self::ensure_installed()) {
            return;
        }
        $counts = self::get_pending_counts();
        if (empty($counts))
            return;

        $lines = [];
        foreach ($counts as $r)
            $lines[] = "{$r['stage']}: {$r['c']}";

        $headers = [
            'Cc: Darren <darren@saprivateschools.co.za>',
        ];

        wp_mail($to, 'Renewals Queue: Items Pending Approval', "Pending Items:\n" . implode("\n", $lines) . "\n\nGo to WP Admin > Renewals Ops to approve.", $headers);
    }

    public static function process_batch(int $limit = 50): array
    {
        if (!self::ensure_installed()) {
            return [];
        }
        global $wpdb;
        $t = $wpdb->prefix . 'ngd_renewals_queue';

        $autopilot = get_option('ngd_renewals_autopilot_enabled', 0);
        $now = time();

        // If autopilot is ON, we might have PENDING items that need auto-approving?
        // Actually enqueue_author handles new ones. Old ones might sit there.
        // For query, we just look for items that are APPROVED and ready to send.
        // If autopilot is ON, we also auto-approve PENDING items effectively by treating them as candidates?
        // No, let's keep it strict: Only APPROVED items send. Autopilot just auto-approves at creation.
        // BUT, what if someone toggles autopilot ON? Should PENDING items start sending?
        // User requested: "autopilot is enabled, approving a queue item now automatically sets send_after_ts".
        // Let's assume process_batch only touches APPROVED items for sending.

        $sql = "SELECT * FROM $t WHERE status='APPROVED' ";

        // Respect send_after_ts
        // If send_after_ts is NULL, send immediately (legacy behavior)
        // If send_after_ts > now, DO NOT SEND yet.

        $sql .= " AND (send_after_ts IS NULL OR send_after_ts <= $now) ";
        $sql .= " ORDER BY send_after_ts ASC, approved_at ASC LIMIT %d";

        $items = $wpdb->get_results($wpdb->prepare($sql, $limit));

        $sent_count = 0;
        $failed_count = 0;

        if (!empty($items)) {
            foreach ($items as $item) {
                $res = self::process_queue_item($item); // returns boolean (sent or not)
                if ($res === true)
                    $sent_count++;
                elseif ($res === false && $item->status === 'FAILED')
                    $failed_count++;
            }
        }

        // Calculate next one
        $next = $wpdb->get_var("SELECT MIN(send_after_ts) FROM $t WHERE status='APPROVED' AND send_after_ts > $now");
        $next_in = '—';
        if ($next) {
            $diff = (int) $next - time();
            $next_in = self::format_hms($diff);
        }

        return [
            'sent' => $sent_count,
            'failed' => $failed_count,
            'next_in_hms' => $next_in
        ];
    }

    public static function process_queue_item($item)
    {
        $user_id = (int) $item->user_id;

        // Hard blocker: silenced users never send emails.
        if (class_exists('NGD_Renewals_Silence') && NGD_Renewals_Silence::is_silenced($user_id)) {
            // We need to update the status to SKIPPED, but process_queue_item usually returns bool.
            // We'll do it manually here.
            global $wpdb;
            $t = $wpdb->prefix . 'ngd_renewals_queue';
            $wpdb->update($t, ['status' => 'SKIPPED', 'send_result' => 'Silenced by admin'], ['id' => $item->id]);
            return false;
        }

        if (!self::ensure_installed()) {
            return false;
        }
        global $wpdb;
        $t = $wpdb->prefix . 'ngd_renewals_queue';

        $user_id = (int) $item->user_id;
        if (!$user_id) {
            $user_id = (int) $item->user_id;
        }
        $stage = $item->stage;

        // 1) Re-verify Truth (avoid sending stale queue rows)
        $current_state = NGD_Renewals_Truth::compute_author_state($user_id);

        if ($current_state['stage'] !== $stage && $stage !== 'downgrade_final') {
            if ($current_state['stage'] !== $stage) {
                $wpdb->update($t, ['status' => 'SKIPPED', 'send_result' => "State changed to {$current_state['stage']}"], ['id' => $item->id]);
                return false;
            }
        } elseif ($current_state['stage'] !== $stage) {
            $wpdb->update($t, ['status' => 'SKIPPED', 'send_result' => "State changed to {$current_state['stage']}"], ['id' => $item->id]);
            return false;
        }

        // 2) Fetch Listings
        $author_listings = get_posts([
            'post_type' => 'job_listing',
            'post_status' => 'publish',
            'author' => $user_id,
            'posts_per_page' => -1
        ]);

        if (empty($author_listings)) {
            $wpdb->update($t, ['status' => 'FAILED', 'send_result' => 'No listings found'], ['id' => $item->id]);
            return false;
        }

        // 3) Side Effects (Downgrade)
        if ($stage === 'downgrade_final') {
            self::perform_downgrade($author_listings);
        }

        // 4) Send Email (rate-limited per user)
        $sent = self::send_email($user_id, $author_listings, $stage);

        // 5) Update Queue
        if ($sent) {
            $wpdb->update($t, [
                'status' => 'SENT',
                'sent_at' => current_time('mysql'),
                'send_result' => 'OK'
            ], ['id' => $item->id]);
            return true;
        }

        // If we blocked it intentionally (rate-limit / in-flight lock), mark as SKIPPED not FAILED
        $err = is_string(self::$last_send_error) ? trim(self::$last_send_error) : '';
        if ($err !== '' && strpos($err, 'RATE_LIMIT:') === 0) {
            $wpdb->update($t, [
                'status' => 'SKIPPED',
                'send_result' => $err
            ], ['id' => $item->id]);
            return false;
        }

        // Default: real failure
        $wpdb->update($t, [
            'status' => 'FAILED',
            'send_result' => ($err !== '' ? $err : 'Email send failed')
        ], ['id' => $item->id]);
        return false;
    }

    public static function send_email($user_id, $listings, $type, bool $bypass_rate_limit = false, ?string $override_to = null, bool $dry_run = false): bool
    {
        self::$last_send_error = '';
        $user_id = (int) $user_id;

        // TEST MODE CHECK
        $is_test = ($override_to && is_email($override_to));

        $user_data = get_userdata($user_id);
        if (!$user_data) {
            self::$last_send_error = 'User not found';
            error_log("[NGD Renewals] User {$user_id} not found");
            return false;
        }

        if (empty($listings) || !is_array($listings) || !isset($listings[0]->ID)) {
            self::$last_send_error = 'No listings supplied';
            error_log("[NGD Renewals] No listings supplied for user {$user_id}");
            return false;
        }

        // Safety net: block duplicate sends per user within a short window (unless bypassed)
        $rate_limit_seconds = (int) apply_filters('ngd_renewals_email_rate_limit_window_seconds', 120, $user_id, $type);
        $now_ts = current_time('timestamp');
        $rate_limit_meta_key = '_ngd_renewals_last_email_ts';

        global $wpdb;
        $lock_name = 'ngd_renewals_email_' . (int) $user_id;
        $got_lock = 1;

        if ($wpdb instanceof wpdb) {
            $got_lock = (int) $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 0)", $lock_name));
        }
        if ($got_lock !== 1) {
            self::$last_send_error = "RATE_LIMIT: In-flight send lock exists for user {$user_id} (stage={$type})";
            error_log('[NGD Renewals] ' . self::$last_send_error);
            return false;
        }

        try {
            $last_ts = (int) get_user_meta($user_id, $rate_limit_meta_key, true);
            if (!$bypass_rate_limit && $last_ts > 0 && ($now_ts - $last_ts) < $rate_limit_seconds) {
                $age = $now_ts - $last_ts;
                $wait = $rate_limit_seconds - $age;
                self::$last_send_error = "RATE_LIMIT: Blocked duplicate email for user {$user_id} (stage={$type}). Last email {$age}s ago; wait {$wait}s.";
                error_log('[NGD Renewals] ' . self::$last_send_error);
                return false;
            }

            $user_email = (string) $user_data->user_email;
            if ($is_test) {
                $user_email = $override_to;
            }
            $user_real_name = trim($user_data->first_name . ' ' . $user_data->last_name) ?: $user_data->display_name;

            // Smart Default: Company > Real Name > Display Name
            $b_company = (string) get_user_meta($user_id, '_billing_company', true);
            $display_to = $b_company ? $b_company : $user_real_name;

            // Renewal reference
            $unique_ref = (string) get_post_meta($listings[0]->ID, '_renewal_reference', true);
            if (!$unique_ref) {
                $unique_ref = 'SCH-' . $user_id . '-' . rand(1000, 9999);
            }

            // Pricing
            if (file_exists(__DIR__ . '/PricingHelper.php')) {
                require_once __DIR__ . '/PricingHelper.php';
            }
            $calc_class = class_exists('\NGD_THEME\Functions\PricingHelper') ? '\NGD_THEME\Functions\PricingHelper' : 'PricingHelper';

            if (!class_exists($calc_class)) {
                self::$last_send_error = "PricingHelper class not found";
                error_log("[NGD Renewals] " . self::$last_send_error);
                return false;
            }

            $listing_ids = array_map(function ($l) {
                return $l->ID;
            }, $listings);
            $calc = $calc_class::calculate_price_from_listing_ids($listing_ids);

            if (empty($calc['ok'])) {
                self::$last_send_error = "PRICING ERROR: " . ($calc['error'] ?? 'Unknown pricing error');
                error_log("[NGD Renewals] " . self::$last_send_error);
                return false;
            }

            $total_amount = (string) $calc['total_formatted'];

            // Expiry (max across listings)
            $max_exp_ts = 0;
            $max_exp_ymd = '';
            foreach ($listings as $l) {
                $v = (string) get_post_meta($l->ID, '_job_expires', true);
                $ts = $v ? strtotime($v . ' 00:00:00') : 0;
                if ($ts && $ts > $max_exp_ts) {
                    $max_exp_ts = $ts;
                    $max_exp_ymd = $v;
                }
            }
            $expiry_human = $max_exp_ts ? date_i18n('j F Y', $max_exp_ts) : '—';

            // Grace end date for warn email
            $grace_end_human = '—';
            if ($max_exp_ymd) {
                $grace_end_human = date_i18n('j F Y', strtotime($max_exp_ymd . ' +7 days'));
            }

            // Ensure a valid renewal reference exists on listings for ALL stages,
            // otherwise /invoice-view/?ref=... won't resolve.
            // IMPORTANT: Never write meta during test/dry-run.
            if (!$dry_run) {
                foreach ($listings as $l) {
                    $pid = (int) $l->ID;

                    $current = (string) get_post_meta($pid, '_renewal_reference', true);

                    // If there's no current ref, set it.
                    if (!$current) {
                        update_post_meta($pid, '_renewal_reference', $unique_ref);
                        continue;
                    }

                    // If there is a current ref but it differs, preserve it as alias before overwriting.
                    // (This protects old links and makes reconciliation easier.)
                    if ($current !== $unique_ref) {
                        $alias = (string) get_post_meta($pid, '_renewal_reference_alias', true);
                        if (!$alias) {
                            update_post_meta($pid, '_renewal_reference_alias', $current);
                        }
                        update_post_meta($pid, '_renewal_reference', $unique_ref);
                    }
                }
            }

            // ONE link only
            $invoice_link = home_url('/invoice-view/' . rawurlencode($unique_ref) . '/');


            // Tracking pixel
            $track_img = "<img src='" . esc_url(home_url('/wp-json/ngd/v1/track_open?ref=' . rawurlencode($unique_ref))) . "' width='1' height='1' style='display:none;' alt='' />";

            // Signature image (optional)
            $sig_image_path = ABSPATH . 'taryn-signature.jpg';
            $sig_image_cid = 'taryn-signature.jpg'; // Using .jpg in cid for safety, though 'taryn_signature_image' works if consistent
            // User snippet had 'taryn_signature_image', checking snippet again...
            // User snippet: $sig_image_cid  = 'taryn_signature_image';
            // I will stick to user snippet exactly.
            $sig_image_cid = 'taryn_signature_image';

            $sig_html = '';
            if (is_string($sig_image_path) && file_exists($sig_image_path)) {
                $sig_html = "<p style='margin-top:10px;'><img src='cid:" . esc_attr($sig_image_cid) . "' alt='Taryn signature' style='max-width:700px;height:auto;'></p>";
            }

            // Email body (Taryn voice)
            $subject = '';
            $body_parts = [];

            $body_parts[] = "<p>Good Morning,</p>";
            $body_parts[] = "<p>I hope you're well 🌻</p>";

            $help_line = "<p>Please let me know if you have any questions — I'd be so happy to help.</p>";
            $invoice_line = "<p>If you need the official invoice, you can <a href='" . esc_url($invoice_link) . "'><strong>download/view/edit it here</strong></a>.</p>";
            $signature = "<p>Kind regards,</p>" . $sig_html;

            switch ($type) {
                case 'invoice':
                    $subject = "Invoice: Annual Listing Renewal ({$unique_ref})";
                    $body_parts[] = "<p>This is just a message to tell you that your package expires in 30 days 🙂</p>";
                    $body_parts[] = "<p>To ensure that you continue to maximise your chances of enquiries & enrolments into your school, you can renew via EFT using this payment reference: <strong style='color:#dc2626;'>" . esc_html($unique_ref) . "</strong>.</p>";
                    $body_parts[] = "<p><strong>Total due:</strong> R" . esc_html($total_amount) . "</p>";
                    if ($expiry_human !== '—') {
                        $body_parts[] = "<p>You'll have <strong>30 days</strong> from now to settle the renewal (expiry: <strong>{$expiry_human}</strong>). After that, your profile may be downgraded automatically to the free tier.</p>";
                    } else {
                        $body_parts[] = "<p>You'll have <strong>30 days</strong> from now to settle the renewal. After that, your profile may be downgraded automatically to the free tier.</p>";
                    }
                    $body_parts[] = $invoice_line;
                    $body_parts[] = "<p>If you believe this is incorrect, please let me know — it really could be — in which case please send me the POP and I will immediately rectify it from my side 😊</p>";
                    $body_parts[] = $help_line;
                    $body_parts[] = $signature;
                    break;

                case 'reminder_14':
                    $subject = "Reminder: Payment Outstanding ({$unique_ref})";
                    $body_parts[] = "<p>Just a friendly reminder — we still haven't received the renewal payment yet.</p>";
                    if ($expiry_human !== '—') {
                        $body_parts[] = "<p>Your Premium listing is due to renew on <strong>{$expiry_human}</strong> (14 days remaining).</p>";
                    } else {
                        $body_parts[] = "<p>Your Premium listing is now within the 14-day renewal window.</p>";
                    }
                    $body_parts[] = "<p>Please could you attend to it when you get a moment so your Premium benefits remain active.</p>";
                    $body_parts[] = "<p><strong>Payment reference:</strong> <span style='color:#dc2626;font-weight:800;'>" . esc_html($unique_ref) . "</span><br><strong>Total due:</strong> R" . esc_html($total_amount) . "</p>";
                    $body_parts[] = $invoice_line;
                    $body_parts[] = "<p>If you've already paid, please reply with your proof of payment and I'll sort it out straight away.</p>";
                    $body_parts[] = $help_line;
                    $body_parts[] = $signature;
                    break;

                case 'reminder_07':
                    $subject = "Action Required: 7 Days Left ({$unique_ref})";
                    $body_parts[] = "<p>Just following up — your renewal payment is still outstanding, and we're now inside the final week.</p>";
                    if ($expiry_human !== '—') {
                        $body_parts[] = "<p><strong>Expiry date:</strong> {$expiry_human} (7 days remaining)</p>";
                    }
                    $body_parts[] = "<p><strong>Please prioritise this</strong> so your profile doesn't lose Premium placement and visibility.</p>";
                    $body_parts[] = "<p><strong>Payment reference:</strong> <span style='color:#dc2626;font-weight:800;'>" . esc_html($unique_ref) . "</span><br><strong>Total due:</strong> R" . esc_html($total_amount) . "</p>";
                    $body_parts[] = $invoice_line;
                    $body_parts[] = "<p>If you believe this is incorrect, just reply and I'll assist immediately.</p>";
                    $body_parts[] = $help_line;
                    $body_parts[] = $signature;
                    break;

                case 'reminder_03':
                    $subject = "URGENT: Premium Membership Expires in 3 Days ({$unique_ref})";
                    $body_parts[] = "<p>This is a final reminder — your renewal payment is still outstanding and we're very close to expiry now.</p>";
                    if ($expiry_human !== '—') {
                        $body_parts[] = "<p><strong>Expiry date:</strong> {$expiry_human} (3 days remaining)</p>";
                    }
                    $body_parts[] = "<p><strong>Please make payment today</strong> to avoid any disruption or automatic downgrade.</p>";
                    $body_parts[] = "<p><strong>Payment reference:</strong> <span style='color:#dc2626;font-weight:800;'>" . esc_html($unique_ref) . "</span><br><strong>Total due:</strong> R" . esc_html($total_amount) . "</p>";
                    $body_parts[] = $invoice_line;
                    $body_parts[] = "<p>If you've already paid, please reply with your POP and I'll fix it immediately 😊</p>";
                    $body_parts[] = $help_line;
                    $body_parts[] = $signature;
                    break;

                case 'downgrade_warn':
                    $subject = "Notice: Membership Expired - 7-Day Grace Period ({$unique_ref})";
                    $body_parts[] = "<p>Your Premium membership has now expired.</p>";
                    if ($expiry_human !== '—') {
                        $body_parts[] = "<p><strong>Expiry date:</strong> {$expiry_human}</p>";
                    }
                    $body_parts[] = "<p>We understand things happen, so we've activated a <strong>final 7-day grace period</strong> to keep your profile live.</p>";
                    $body_parts[] = "<p>If payment is not received by <strong>{$grace_end_human}</strong>, your account will be automatically downgraded to the Basic (Free) tier.</p>";
                    $body_parts[] = "<p><strong>Payment reference:</strong> <span style='color:#dc2626;font-weight:800;'>" . esc_html($unique_ref) . "</span><br><strong>Total due:</strong> R" . esc_html($total_amount) . "</p>";
                    $body_parts[] = $invoice_line;
                    $body_parts[] = "<p>If you believe this is incorrect, please reply with your POP and I'll rectify it straight away.</p>";
                    $body_parts[] = $help_line;
                    $body_parts[] = $signature;
                    break;

                case 'downgrade_final':
                    $subject = "Account Downgraded: Premium Features Removed ({$unique_ref})";
                    $body_parts[] = "<p>Your 7-day grace period has ended and we still haven't received payment, so unfortunately your listing has been downgraded to the Basic (Free) tier.</p>";
                    $body_parts[] = "<p>You can restore Premium at any time as soon as payment reflects — please just use the reference below.</p>";
                    $body_parts[] = "<p><strong>Payment reference:</strong> <span style='color:#dc2626;font-weight:800;'>" . esc_html($unique_ref) . "</span><br><strong>Total due:</strong> R" . esc_html($total_amount) . "</p>";
                    $body_parts[] = $invoice_line;
                    $body_parts[] = "<p>If you've already paid, please reply with your POP and I'll reinstate Premium immediately.</p>";
                    $body_parts[] = $help_line;
                    $body_parts[] = $signature;
                    break;

                default:
                    $subject = "Annual Listing Renewal ({$unique_ref})";
                    $body_parts[] = "<p>Please see the invoice link below.</p>";
                    $body_parts[] = "<p><strong>Payment reference:</strong> <span style='color:#dc2626;font-weight:800;'>" . esc_html($unique_ref) . "</span><br><strong>Total due:</strong> R" . esc_html($total_amount) . "</p>";
                    $body_parts[] = $invoice_line;
                    $body_parts[] = $help_line;
                    $body_parts[] = $signature;
                    break;
            }

            // Final HTML (simple like a real typed email)
            $html_body = "<div style='font-family: Arial, Helvetica, sans-serif; font-size: 15px; color: #0b1220; line-height: 1.6;'>" .
                implode("\n", $body_parts) .
                $track_img .
                "</div>";

            // Headers: From Taryn + Reply-To
            $headers = [
                'Content-Type: text/html; charset=UTF-8',
                'From: Taryn <taryn@saprivateschools.co.za>',
                'Reply-To: Taryn <taryn@saprivateschools.co.za>',
            ];

            if (!$is_test) {
                $headers[] = 'Cc: Darren <darren@saprivateschools.co.za>';
                $headers[] = 'Bcc: upgrades@saprivateschools.co.za';
            }

            // Embed signature image (CID), if present
            $embed_cb = function ($phpmailer) use ($sig_image_path, $sig_image_cid) {
                try {
                    if (is_string($sig_image_path) && file_exists($sig_image_path)) {
                        $phpmailer->AddEmbeddedImage($sig_image_path, $sig_image_cid, 'taryn-signature.jpg');
                    }
                } catch (Throwable $e) {
                    // Do not block sending
                }
            };
            add_action('phpmailer_init', $embed_cb);

            if ($is_test) {
                $subject = '[TEST] ' . $subject;
            }
            $result = wp_mail($user_email, $subject, $html_body, $headers);

            remove_action('phpmailer_init', $embed_cb);

            if (!$result) {
                self::$last_send_error = 'Email send failed (wp_mail returned false)';
                error_log('[NGD Renewals] ' . self::$last_send_error . " user={$user_id} stage={$type}");
                return false;
            }

            if (!$dry_run) {
                // Mark last successful send (used for rate limit)
                update_user_meta($user_id, $rate_limit_meta_key, (string) $now_ts);

                // META UPDATES (Flags)
                $flag_key = '_sent_' . $type . '_' . date('Y');
                foreach ($listings as $l) {
                    update_post_meta($l->ID, $flag_key, date('Y-m-d'));

                    if ($type === 'invoice') {
                        update_post_meta($l->ID, '_renewal_reference', $unique_ref);
                        update_post_meta($l->ID, '_renewal_reference_issued_ts', time());
                        update_post_meta($l->ID, '_renewal_reference_source', 'prod');
                        update_post_meta($l->ID, '_payment_status', 'DUE');
                        update_post_meta($l->ID, '_current_year_invoice_sent', date('Y'));
                        update_post_meta($l->ID, '_invoice_sent_timestamp', time());

                        if (method_exists('NGD_Renewals_Dashboard', 'ngd_sync_meta_to_author_listings')) {
                            $keys = ['_renewal_reference', '_renewal_reference_issued_ts', '_renewal_reference_source', '_payment_status', '_current_year_invoice_sent', '_invoice_sent_timestamp'];
                            NGD_Renewals_Dashboard::ngd_sync_meta_to_author_listings($user_id, $l->ID, $keys, [$flag_key]);
                        }

                    } elseif (strpos($type, 'reminder') !== false) {
                        update_post_meta($l->ID, '_reminder_sent_date', date('Y-m-d'));
                        if (method_exists('NGD_Renewals_Dashboard', 'ngd_sync_meta_to_author_listings')) {
                            NGD_Renewals_Dashboard::ngd_sync_meta_to_author_listings($user_id, $l->ID, ['_reminder_sent_date'], [$flag_key]);
                        }
                    }
                }
            }

            return true;

        } finally {
            if ($wpdb instanceof wpdb) {
                $wpdb->get_var($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
            }
        }
    }

    public static function send_queue_item_now(int $queue_id): array
    {
        global $wpdb;
        $t = self::table_name();

        self::ensure_ready();

        $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $queue_id));
        if (!$item) {
            return ['ok' => false, 'status' => 'MISSING', 'result' => 'Queue row not found'];
        }

        $user_id = (int) $item->user_id;
        $stage = (string) $item->stage;

        // Do NOT silently SKIP on Send Now — return an explicit error instead.
        if (class_exists('NGD_Renewals_Silence') && NGD_Renewals_Silence::is_silenced($user_id)) {
            return ['ok' => false, 'status' => 'BLOCKED', 'result' => 'User is silenced. Unsilence then Send Now.'];
        }

        // Force the row due immediately (but we will NOT call process_queue_item)
        $wpdb->update($t, [
            'status' => 'APPROVED',
            'approved_at' => current_time('mysql'),
            'send_after_ts' => time() - 1,
            'source' => 'OPS_SEND_NOW',
        ], ['id' => $queue_id]);

        // Fetch listings
        $listings = get_posts([
            'post_type' => 'job_listing',
            'post_status' => 'publish',
            'author' => $user_id,
            'posts_per_page' => -1,
        ]);

        if (empty($listings)) {
            $wpdb->update($t, [
                'status' => 'FAILED',
                'send_result' => 'No listings found (Send Now)',
            ], ['id' => $queue_id]);

            return ['ok' => false, 'status' => 'FAILED', 'result' => 'No listings found'];
        }

        // Downgrade side effects if needed
        if ($stage === 'downgrade_final') {
            self::perform_downgrade($listings);
        }

        // Send email NOW — bypass rate-limit window (but lock still applies)
        $sent = self::send_email($user_id, $listings, $stage, true);

        if ($sent) {
            $wpdb->update($t, [
                'status' => 'SENT',
                'sent_at' => current_time('mysql'),
                'send_result' => 'OK (Send Now)',
            ], ['id' => $queue_id]);

            return ['ok' => true, 'status' => 'SENT', 'result' => 'OK'];
        }

        $err = is_string(self::$last_send_error) ? trim(self::$last_send_error) : '';
        $wpdb->update($t, [
            'status' => 'FAILED',
            'send_result' => ($err !== '' ? $err : 'Email send failed (Send Now)'),
        ], ['id' => $queue_id]);

        return ['ok' => false, 'status' => 'FAILED', 'result' => ($err !== '' ? $err : 'Email send failed')];
    }

    public static function send_queue_item_test(int $queue_id, string $test_to = 'darren@2ko.co.za'): array
    {
        global $wpdb;
        $t = self::table_name();

        self::ensure_ready();

        $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $queue_id));
        if (!$item) {
            return ['ok' => false, 'status' => 'MISSING', 'result' => 'Queue row not found'];
        }

        $user_id = (int) $item->user_id;
        $stage = (string) $item->stage;

        if (!is_email($test_to)) {
            return ['ok' => false, 'status' => 'BAD_EMAIL', 'result' => 'Invalid test email address'];
        }

        // Fetch listings (same as real send)
        $listings = get_posts([
            'post_type' => 'job_listing',
            'post_status' => 'publish',
            'author' => $user_id,
            'posts_per_page' => -1,
        ]);

        if (empty($listings)) {
            return ['ok' => false, 'status' => 'FAILED', 'result' => 'No listings found'];
        }

        // Important: dry_run=true => no meta writes, no flags, no timestamps
        $sent = self::send_email($user_id, $listings, $stage, true, $test_to, true);

        if ($sent) {
            return ['ok' => true, 'status' => 'TEST_SENT', 'result' => 'Test email sent'];
        }

        $err = is_string(self::$last_send_error) ? trim(self::$last_send_error) : '';
        return ['ok' => false, 'status' => 'FAILED', 'result' => ($err !== '' ? $err : 'Test email send failed')];
    }

    private static function perform_downgrade($listings)
    {
        $free_package_id = 138;
        foreach ($listings as $l) {
            update_post_meta($l->ID, '_package_id', $free_package_id);
            update_post_meta($l->ID, '_featured', '0');
            update_post_meta($l->ID, '_claimed', '0');
            update_post_meta($l->ID, '_job_duration', '0');
            delete_post_meta($l->ID, '_payment_status');
        }
    }
    public function ensure_invoice_shortcodes(): void
    {
        // Make invoice shortcodes available even if theme bootstrap missed it.
        // Safe: only runs once.
        static $done = false;
        if ($done) {
            return;
        }

        // Try load the class from the theme if it isn't already loaded.
        if (!class_exists('\\NGD_THEME\\Functions\\InvoiceUpdater')) {
            $candidates = [
                get_stylesheet_directory() . '/Functions/InvoiceUpdater.php',
                get_template_directory() . '/Functions/InvoiceUpdater.php',
            ];

            foreach ($candidates as $p) {
                if (is_string($p) && $p !== '' && file_exists($p)) {
                    require_once $p;
                    break;
                }
            }
        }

        if (class_exists('\\NGD_THEME\\Functions\\InvoiceUpdater')) {
            // Register shortcodes by invoking with run_hooks=true
            new \NGD_THEME\Functions\InvoiceUpdater(true);
            $done = true;
        }
    }

    public function maybe_handle_invoice_pages(): void
    {
        // Disable caching + redirect legacy query-string links to pretty URLs
        if (!is_page()) {
            return;
        }

        $slug = (string) get_query_var('pagename');

        if (!in_array($slug, ['invoice-view', 'invoice', 'update-invoice'], true)) {
            return;
        }

        // Tell common caching layers to skip this request
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (!defined('DONOTCACHEOBJECT')) {
            define('DONOTCACHEOBJECT', true);
        }
        if (!defined('DONOTCACHEDB')) {
            define('DONOTCACHEDB', true);
        }
        if (!defined('DONOTMINIFY')) {
            define('DONOTMINIFY', true);
        }

        if (!headers_sent()) {
            nocache_headers();
            header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true);
            header('Pragma: no-cache', true);
            header('Expires: 0', true);
        }

        // Redirect /invoice-view/?ref=XYZ  -> /invoice-view/XYZ/
        $ref = '';
        $keys = ['ref', 'invoice_ref', 'ngd_ref', 'renewal_ref', 'r'];

        foreach ($keys as $k) {
            if (isset($_GET[$k])) {
                $v = sanitize_text_field(wp_unslash($_GET[$k]));
                if ($v !== '') {
                    $ref = $v;
                    break;
                }
            }
        }

        if ($ref === '') {
            return;
        }

        $path = trim((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? ''), '/');

        // Only redirect if the path is just the base slug (no ref segment already)
        if ($path === 'invoice-view' || $path === 'invoice' || $path === 'update-invoice') {
            $encoded = rawurlencode($ref);

            if ($path === 'invoice') {
                $target = home_url('/invoice/' . $encoded . '/');
            } elseif ($path === 'update-invoice') {
                $target = home_url('/update-invoice/' . $encoded . '/');
            } else {
                $target = home_url('/invoice-view/' . $encoded . '/');
            }

            wp_safe_redirect($target, 302);
            exit;
        }
    }

    public function maybe_force_invoice_content(string $content): string
    {
        // If invoice pages aren't actually executing the shortcode (Elementor/template mismatch),
        // force render using InvoiceUpdater directly.
        if (!is_page()) {
            return $content;
        }

        $slug = (string) get_query_var('pagename');

        if (!in_array($slug, ['invoice-view', 'invoice', 'update-invoice'], true)) {
            return $content;
        }

        // If the page already contains one of our shortcodes, leave it alone.
        if (stripos($content, '[invoice_updater') !== false || stripos($content, '[ngd_invoice_viewer') !== false) {
            return $content;
        }

        // Ensure shortcodes exist (and class loaded) before forcing render
        $this->ensure_invoice_shortcodes();

        if (class_exists('\\NGD_THEME\\Functions\\InvoiceUpdater')) {
            $updater = new \NGD_THEME\Functions\InvoiceUpdater(false);
            return (string) $updater->render_wrapper();
        }

        // Fallback: at least try the shortcode without fatals
        return (string) do_shortcode('[invoice_updater]');
    }
}
