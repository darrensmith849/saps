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
    private const VERSION = '1.0.3';

    // IMPORTANT: Your package IDs
    private int $paid_package_id = 247687;
    private int $free_package_id = 138;

    // Pagination defaults
    private int $default_per_page = 50;
    private int $max_per_page = 100;

    public function __construct()
    {
        add_action('init', [$this, 'register_routes']);
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_action('template_redirect', [$this, 'maybe_render_dashboard']);

        register_activation_hook(__FILE__, [$this, 'on_activate']);
        register_deactivation_hook(__FILE__, [$this, 'on_deactivate']);
    }

    public function on_activate(): void
    {
        $this->register_routes();
        flush_rewrite_rules();
    }

    public function on_deactivate(): void
    {
        flush_rewrite_rules();
    }

    public function register_routes(): void
    {
        add_rewrite_rule('^renewals/?$', 'index.php?ngd_renewals=1', 'top');
        add_rewrite_rule('^renewals/action/?$', 'index.php?ngd_renewals=1&ngd_renewals_action=1', 'top');
        add_rewrite_rule('^renewals/export/?$', 'index.php?ngd_renewals=1&ngd_renewals_export=1', 'top');

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

        $vars[] = 'ngd_issue'; // NEW
        $vars[] = 'ngd_show_downgraded';

        $vars[] = 'ngd_year'; // NEW

        $vars[] = 'ngd_sort';
        $vars[] = 'ngd_dir';

        $vars[] = 'ngd_page';
        $vars[] = 'ngd_per_page';

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
                    if (!$eff_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eff_date)) {
                        wp_send_json_error(['message' => 'Invalid effective date (YYYY-MM-DD required)'], 400);
                    }
                    $this->action_upgrade($user_id, $eff_date);
                    break;

                case 'downgrade':
                    $this->action_downgrade($user_id);
                    break;

                case 'toggle_duplicate':
                    $post_id = (int) ($input['post_id'] ?? 0);
                    $this->action_toggle_duplicate($post_id);
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

    private function action_upgrade(int $user_id, string $eff_date): void
    {
        $new_expiry = date('Y-m-d', strtotime($eff_date . ' +1 year'));

        $listings = get_posts([
            'post_type' => 'job_listing',
            'post_status' => 'publish',
            'author' => $user_id,
            'posts_per_page' => -1
        ]);

        if (!$listings)
            wp_send_json_error(['message' => 'No listings found for user'], 404);

        global $wpdb;
        $table_meta = $wpdb->prefix . 'postmeta';

        foreach ($listings as $l) {
            // Apply Expiry
            update_post_meta($l->ID, '_job_expires', $new_expiry);

            // Recalc Duration
            $post_date = $l->post_date;
            if (strtotime($post_date) > time()) {
                // Fix future posts logic (same as webhook)
                $post_date = current_time('mysql');
                wp_update_post(['ID' => $l->ID, 'post_date' => $post_date, 'post_date_gmt' => $post_date]);
            }
            $days = (int) ceil((strtotime($new_expiry) - strtotime($post_date)) / DAY_IN_SECONDS);
            update_post_meta($l->ID, '_job_duration', $days);

            // Premium Signals
            update_post_meta($l->ID, '_package_id', $this->paid_package_id);
            update_post_meta($l->ID, '_featured', '1');
            update_post_meta($l->ID, '_payment_status', 'PAID');

            // Clear renewal ref
            update_post_meta($l->ID, '_renewal_reference', '');

            // Clear cache
            clean_post_cache($l->ID);
        }

        // Send Email
        require_once __DIR__ . '/PaymentWebhook.php';
        if (class_exists('\NGD_THEME\Functions\PaymentWebhook')) {
            $hook = new \NGD_THEME\Functions\PaymentWebhook(false);
            $hook->send_success_email($user_id, $new_expiry);
        }

        // Persistent Client Marker
        update_user_meta($user_id, '_ngd_client', 'yes');

        wp_send_json_success(['message' => "Upgraded! New expiry: $new_expiry. Email sent."]);
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

    private function action_toggle_duplicate(int $post_id): void
    {
        if ($post_id <= 0)
            wp_send_json_error(['message' => 'Invalid Post ID'], 400);

        $curr = get_post_meta($post_id, '_ngd_listing_role', true);
        if ($curr === 'duplicate') {
            delete_post_meta($post_id, '_ngd_listing_role');
            wp_send_json_success(['message' => 'Unmarked as duplicate.']);
        } else {
            update_post_meta($post_id, '_ngd_listing_role', 'duplicate');
            wp_send_json_success(['message' => 'Marked as duplicate.']);
        }
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

        </html><?php
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
                    'owner_name' => $owner ? ($owner->display_name ?: ('User #' . $owner_id)) : ('User #' . $owner_id),
                    'owner_email' => $owner ? (string) $owner->user_email : '',
                    // School name will be derived from Representative Listing later
                    'school' => '',

                    // All listings for drawer
                    'siblings' => [], // {id, title, status}
                    'listing_count' => 0,

                    // Representative Listing (Deterministic)
                    'rep_post_id' => 0,
                    'rep_post_title' => '',
                    'rep_reason' => '',

                    // Candidate tracking for determinstic selection
                    'candidate_score_max' => -1,

                    // Premium / billing signals (ANY listing)
                    'is_current_premium' => false,
                    'has_paid_signal' => false,
                    'has_due_signal' => false,

                    // Evergreen author-level override
                    'is_evergreen' => false,

                    // Persistent client marker match
                    'is_ngd_client' => false,

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

                    // Temp best score tracking
                    'best_score_is_premium' => -1,
                    'best_score_sent_ts' => -1,
                    'best_score_expires_ts' => -1,
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

            // Current premium signal (any listing is in paid package OR featured OR paid status)
            if ($package_id === $this->paid_package_id || $featured || $is_paid_signal) {
                $a['is_current_premium'] = true;
            }
            if ($is_paid_signal) {
                $a['has_paid_signal'] = true;
            }
            if ($payment_status === 'DUE') {
                $a['has_due_signal'] = true;
            }

            // Renewal reference (strict invoiced trigger)
            $renewal_ref = trim((string) get_post_meta($post_id, '_renewal_reference', true));
            if ($renewal_ref !== '') {
                // Ignore test refs
                $ref_source = get_post_meta($post_id, '_renewal_reference_source', true);
                if ($ref_source === 'test') {
                    $renewal_ref = '';
                }
            }

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

            // Sibling info for drawer
            $is_dup = get_post_meta($post_id, '_ngd_listing_role', true) === 'duplicate';
            $a['siblings'][] = [
                'id' => $post_id,
                'title' => get_the_title($post_id),
                'status' => $payment_status ?: '—',
                'ref' => $renewal_ref,
                'is_dup' => $is_dup
            ];

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

            // Sort listings to find deterministic representative
            // Rules:
            // 1. is_current_premium DESC
            // 2. invoice_sent_ts DESC
            // 3. expires_ts DESC
            // 4. post_id ASC
            usort($a['listing_ids'], function ($ida, $idb) use ($a) {
                // We need to re-fetch meta for sort context or store better structure.
                // Since we already iterated, let's optimize by identifying "best" during loop instead?
                // Actually, let's keep it simple: we have the IDs, let's grab what we need.
                // NOTE: This might be slow if many listings per author.
                // Better approach: stored "best_candidate" in $a during the loop.
                return 0;
            });
            // ... Actually, doing it inside the loop is way more efficient.
            // Let's refactor the loop logic above to pick the winner.

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

            // Calculate Score for this listing to see if it's the "Representative"
            // Score tuple: [is_current_premium(1/0), invoice_sent_ts(int), expires_ts(int), -post_id(int)]
            // We want highest score. Post ID is negated so smaller ID (older) wins tiebreak (ASC).

            $score_is_premium = ($package_id === $this->paid_package_id || $featured || $is_paid_signal) ? 1 : 0;
            $score_sent_ts = $invoice_sent_ts; // already parsed above
            $score_expires_ts = $expires_ts; // already parsed above

            // Comparison logic
            // We can't easily store a tuple array and sort later without re-fetching.
            // So we do "Keep Best" valid logic here.

            $is_better = false;

            if ($a['rep_post_id'] === 0) {
                $is_better = true;
            } else {
                // Compare against current best (stored in temp vars in $a? No, we need to track best score comps)
                // Let's store best score components in $a
                if ($score_is_premium > $a['best_score_is_premium']) {
                    $is_better = true;
                } elseif ($score_is_premium === $a['best_score_is_premium']) {
                    if ($score_sent_ts > $a['best_score_sent_ts']) {
                        $is_better = true;
                    } elseif ($score_sent_ts === $a['best_score_sent_ts']) {
                        if ($score_expires_ts > $a['best_score_expires_ts']) {
                            $is_better = true;
                        } elseif ($score_expires_ts === $a['best_score_expires_ts']) {
                            if ($post_id < $a['rep_post_id']) { // ASC ID
                                $is_better = true;
                            }
                        }
                    }
                }
            }

            if ($is_better) {
                $a['rep_post_id'] = $post_id;
                $a['rep_post_title'] = get_the_title($post_id);
                $a['best_score_is_premium'] = $score_is_premium;
                $a['best_score_sent_ts'] = $score_sent_ts;
                $a['best_score_expires_ts'] = $score_expires_ts;

                // Construct reason string for UI
                $parts = [];
                if ($score_is_premium)
                    $parts[] = 'Premium';
                if ($score_sent_ts > 0)
                    $parts[] = 'InvSent';
                if ($score_expires_ts > 0)
                    $parts[] = 'Expires';
                $parts[] = 'ID:' . $post_id;
                $a['rep_reason'] = implode('>', $parts);
            }

            unset($a);
        }

        // Convert to rows (only include commercially relevant authors)
        $rows_base = [];
        $kpi = [
            'CLIENTS' => 0,    // (Total filtered rows)
            'PAID' => 0,       // Payment = PAID
            'DOWNGRADED' => 0, // Status = DOWNGRADED
            'MISSING_EXPIRY' => 0,
            'DUE_NOT_INVOICED' => 0,
        ];

        $seen_years = [];

        // 1. BUILD BASE DATASET (Apply Scope: Year Filter only)
        foreach ($authors as $user_id => $a) {

            // INCLUDE RULE
            // Include if premium, involved in renewals, evergreen, OR explicitly marked as a client
            $include = ($a['is_current_premium'] || $a['has_renewal_ref'] || $a['has_downgrade_final'] || $a['is_evergreen'] || $a['is_ngd_client']);
            if (!$include)
                continue;

            // Days to expiry (based on LATEST expiry)
            $days_to_expiry = null;
            $row_year = null;

            if ($a['expires_max_ts'] > 0) {
                $days_to_expiry = (int) floor(($a['expires_max_ts'] - $now_ts) / DAY_IN_SECONDS);
                $row_year = date('Y', $a['expires_max_ts']);
            }

            $missing_expiry = ($a['expires_max_ts'] <= 0);

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

            // Apply Year Filter (BASE SCOPE)
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
            $ui_status = 'DOWNGRADED';
            if ($a['is_evergreen']) {
                $ui_status = 'EVERGREEN';
            } elseif ($a['has_downgrade_final']) {
                $ui_status = 'DOWNGRADED';
            } elseif ($a['has_due_signal'] || ($a['has_renewal_ref'] && ($a['invoice_sent_ts_max'] > 0 || $in_renewal_window))) {
                $ui_status = 'INVOICED';
            } elseif (!$missing_expiry && $days_to_expiry !== null && $days_to_expiry <= -8) {
                // Expired beyond grace period overrides PAID status
                $ui_status = 'DOWNGRADED';
            } elseif (($a['is_current_premium'] || $a['has_paid_signal']) && ($missing_expiry || $days_to_expiry >= 0)) {
                $ui_status = 'PAID';
            } else {
                $ui_status = 'DOWNGRADED';
            }

            // Payment label (UI-only)
            if ($ui_status === 'PAID')
                $payment_label = 'PAID';
            elseif ($ui_status === 'INVOICED')
                $payment_label = 'DUE';
            else
                $payment_label = 'DOWNGRADED';

            // Days metric + label
            $days_metric = null;
            $days_label = '—';
            $days_is_estimated = false;

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
                // PAID / INVOICED: normal expiry countdown
                // For INVOICED, we might override logic to show days until due
                if ($ui_status === 'INVOICED' && $a['invoice_sent_ts_max'] > 0) {
                    $due_ts = $a['invoice_sent_ts_max'] + (28 * DAY_IN_SECONDS);
                    $days_metric = (int) floor(($due_ts - $now_ts) / DAY_IN_SECONDS);
                } else {
                    $days_metric = $days_to_expiry;
                }

                if ($days_metric === null) {
                    $days_label = '—';
                } else {
                    $days_label = ($days_metric >= 0) ? ('+' . $days_metric) : (string) $days_metric;
                }
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

            // Ensure Representative Fields exist
            if (empty($a['rep_post_title'])) {
                $a['rep_post_title'] = $a['school'] ?: ('User Listing #' . $user_id);
            }
            if (empty($a['rep_reason'])) {
                $a['rep_reason'] = 'default';
            }

            // Add to BASE set
            $row_data = [
                'user_id' => $user_id,
                'school' => $a['rep_post_title'], // USE DETERMINISTIC TITLE
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

                'expires_date' => $a['expires_max_ts'] ? date('Y-m-d', $a['expires_max_ts']) : '—',
                'days_metric' => $days_metric,
                'days_label' => $days_label,
                'days_is_estimated' => (bool) $days_is_estimated,

                'renewal_ref' => $a['has_renewal_ref'] ? ($a['renewal_ref'] ?: '—') : '—',

                'timeline' => $timeline,

                'admin_url' => admin_url('edit.php?post_type=job_listing&author=' . $user_id),
            ];

            $rows_base[] = $row_data;

            // INCREMENT KPI (Based on Base Set)
            $kpi['CLIENTS']++;

            if ($payment_label === 'PAID') {
                $kpi['PAID']++;
            }
            if ($ui_status === 'DOWNGRADED') {
                $kpi['DOWNGRADED']++;
            }

            if ($alert_missing_expiry)
                $kpi['MISSING_EXPIRY']++;
            // if ($alert_due_not_invoiced) $kpi['DUE_NOT_INVOICED']++; 
        }

        // 2. APPLY UI FILTERS (Search, Toggle, Dropdowns) => Visible Rows
        $rows_visible = [];

        foreach ($rows_base as $r) {
            // Toggle: Hide downgraded rows by default (unless toggle ON)
            if (!$show_downgraded && $r['status'] === 'DOWNGRADED') {
                continue;
            }

            // Filter: Status
            if ($status && $status !== 'ALL' && $r['status'] !== $status)
                continue;

            // Filter: Issue
            if ($issue && $issue !== 'ALL') {
                if ($issue === 'MISSING_EXPIRY' && empty($r['alert_missing_expiry']))
                    continue;
                if ($issue === 'DUE_NOT_INVOICED' && empty($r['alert_due_not_invoiced']))
                    continue;
            }

            // Filter: Search (Q)
            if ($q !== '') {
                $hay = strtolower(
                    ($r['school'] ?? '') . ' ' .
                    ($r['owner_name'] ?? '') . ' ' .
                    ($r['owner_email'] ?? '') . ' ' .
                    ($r['renewal_ref'] ?? '') . ' ' .
                    $r['user_id']
                );
                if (strpos($hay, strtolower($q)) === false)
                    continue;
            }

            $rows_visible[] = $r;
        }

        // Sort
        $rows_visible = $this->sort_rows($rows_visible, $sort, $dir);

        // Pagination
        $total = count($rows_visible);
        $total_pages = max(1, (int) ceil($total / $per_page));
        if ($page > $total_pages)
            $page = $total_pages;

        $offset = ($page - 1) * $per_page;
        $paged_rows = array_slice($rows_visible, $offset, $per_page);

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
                                    <div class="school"><?php echo esc_html($r['school']); ?></div>

                                    <!-- TIMELINE -->
                                    <div class="meta mt-6"><?php echo esc_html($r['owner_name']); ?> · User
                                        #<?php echo esc_html((string) $r['user_id']); ?></div>

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
                <div class="sTitle">Representative Listing</div>
                <div class="kv">
                    <div class="k">Title</div><div class="v">${r.rep_post_title || r.school || '—'}</div>
                    <div class="k">Rule</div><div class="v" style="font-family:monospace;font-size:11px;">${r.rep_reason || 'default'}</div>
                </div>
            </div>


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

                <div class="timeline">${timeline || '<div class="k">—</div>'}</div>
            </div>

            <!-- SIBLINGS SECTION -->
            ${(r.siblings && r.siblings.length > 1) ? `
            <div class="section">
                <div class="sTitle">All Listings (${r.siblings.length})</div>
                <div style="font-size:12px;display:flex;flex-direction:column;gap:8px;">
                    ${r.siblings.map(s => `
                        <div style="display:flex;justify-content:space-between;align-items:center;background:#f8fafc;padding:8px;border-radius:6px;border:1px solid #e2e8f0; ${s.id === r.rep_post_id ? 'border-left:3px solid #3b82f6;' : ''}">
                            <div style="display:flex;align-items:center;gap:6px;">
                                <div style="font-weight:600;color:#334155;">${s.title}</div>
                                ${s.is_dup ? '<span style="font-size:10px;background:#fee2e2;color:#991b1b;padding:2px 4px;border-radius:4px;">DUP</span>' : ''}
                            </div>
                            <div style="display:flex;gap:8px;align-items:center;">
                                <div style="color:#64748b;font-size:11px;">#${s.id}</div>
                                <button style="border:1px solid #cbd5e1;background:#fff;border-radius:4px;cursor:pointer;font-size:10px;color:#64748b;" onclick="doAction('toggle_duplicate', 0, ${s.id})" title="Toggle Duplicate">D</button>
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>
            ` : ``}

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
                            <button class="btnWide primary" style="background:#16a34a;" onclick="doAction('upgrade', ${r.user_id})">Apply Upgrade (+1 Year)</button>
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

        async function doAction(type, userId = 0, postId = 0) {
            const payload = { do: type, user_id: userId, post_id: postId, nonce: nonce };


            if (type === 'upgrade') {
                const dateInput = document.getElementById('upgrade_date_' + userId);
                if (!dateInput || !dateInput.value) {
                    alert('Please select an effective date');
                    return;
                }
                payload.effective_date = dateInput.value;
                if (!confirm('Confirm upgrade for ' + payload.effective_date + '? This will charge them 0, set +1 year expiry, and send the success email.')) return;
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
                    alert(json.data.message);
                    window.location.reload();
                } else {
                    alert('Error: ' + (json.data ? json.data.message : 'Unknown error'));
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
}

new NGD_Renewals_Dashboard();
