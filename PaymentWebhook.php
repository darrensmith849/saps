<?php

namespace NGD_THEME\Functions;

if (!defined('ABSPATH'))
    exit;

use WP_REST_Request;
use WP_REST_Response;
use GuzzleHttp\Client;
use DateTime;

class PaymentWebhook
{

    public function __construct($run_hooks = false)
    {
        if ($run_hooks) {
            $this->run_hooks();
        }
    }

    public function run_hooks(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes()
    {
        register_rest_route('ngd/v1', '/payment_receiver', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_webhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_webhook(WP_REST_Request $request)
    {
        global $wpdb;
        $params = $request->get_json_params();

        $secret_key = 'T9S%OK&vK9]qsWU5hpMIbbR9ZTl7';
        $lifetime_ids = [240483];
        $premium_package_id = 247687; // The Premium Package ID

        if (!isset($params['secret']) || $params['secret'] !== $secret_key) {
            return new WP_REST_Response(['success' => false, 'message' => 'Invalid Secret'], 403);
        }

        $reference_in = sanitize_text_field($params['reference']);
        $is_fuzzy = !empty($params['is_fuzzy']);

        // Debug info accumulator
        $debug = [
            'resolved_via' => 'unresolved',
            'resolved_post_id' => 0,
            'resolved_author_id' => 0
        ];

        $listings = [];

        // LOGIC 1: Parse Canonical SCHU (SCHU-UserId-Rand4)
        if (preg_match('/^SCHU-(\d+)-(\d{4})$/i', $reference_in, $matches)) {
            $parsed_user_id = (int) $matches[1];

            // 1A. Try exact meta match (Renewal Ref OR Alias)
            $listings = get_posts([
                'post_type' => 'job_listing',
                'post_status' => 'any',
                'posts_per_page' => -1,
                'meta_query' => [
                    'relation' => 'OR',
                    [
                        'key' => '_renewal_reference',
                        'value' => $reference_in
                    ],
                    [
                        'key' => '_renewal_reference_alias',
                        'value' => $reference_in
                    ]
                ]
            ]);

            if (!empty($listings)) {
                $debug['resolved_via'] = 'schu_meta_lookup';
            } else {
                // 1B. Fallback: Author Search
                // If the user ID is valid, we trust the payment belongs to them even if ref is missing on listing
                if ($parsed_user_id > 0) {
                    $listings = get_posts([
                        'post_type' => 'job_listing',
                        'post_status' => 'any',
                        'posts_per_page' => -1,
                        'author' => $parsed_user_id
                    ]);
                    if (!empty($listings)) {
                        $debug['resolved_via'] = 'schu_author_fallback';
                    }
                }
            }
        }
        // LOGIC 2: Parse Legacy SCH (SCH-RepPostID-Rand4)
        elseif (preg_match('/^SCH-(\d+)-(\d{4})$/i', $reference_in, $matches)) {
            $parsed_rep_id = (int) $matches[1];

            // Validate that post exists and is job_listing
            $rep_post = get_post($parsed_rep_id);
            if ($rep_post && $rep_post->post_type === 'job_listing') {
                $listings = get_posts([
                    'post_type' => 'job_listing',
                    'post_status' => 'any',
                    'posts_per_page' => -1,
                    'meta_query' => [
                        'relation' => 'OR',
                        [
                            'key' => '_renewal_reference',
                            'value' => $reference_in
                        ],
                        [
                            'key' => '_renewal_reference_alias',
                            'value' => $reference_in
                        ]
                    ]
                ]);

                if (!empty($listings)) {
                    $debug['resolved_via'] = 'sch_parse_meta_lookup';
                }
            }
        }
        // LOGIC 3: Legacy NGD/ShortCode check (if not regex matched above)
        else {
            if (!$is_fuzzy) {
                // Last ditch meta lookup for non-standard formats
                $listings = get_posts([
                    'post_type' => 'job_listing',
                    'post_status' => 'any',
                    'posts_per_page' => -1,
                    'meta_query' => [
                        'relation' => 'OR',
                        [
                            'key' => '_renewal_reference',
                            'value' => $reference_in
                        ],
                        [
                            'key' => '_renewal_reference_alias',
                            'value' => $reference_in
                        ]
                    ]
                ]);

                if (!empty($listings)) {
                    $debug['resolved_via'] = 'meta_exact_lookup';
                }
            }
        }

        // Final check
        if (empty($listings)) {
            if ($is_fuzzy) {
                return new WP_REST_Response(['success' => false, 'message' => 'Fuzzy Warning: Manual Check Required', 'debug' => $debug], 404);
            }
            return new WP_REST_Response(['success' => false, 'message' => 'Reference not found', 'debug' => $debug], 404);
        }

        // --- BATCH UPGRADE LOGIC ---
        $table_meta = $wpdb->prefix . 'postmeta';
        $author_id = 0;

        // Ensure we capture the author ID for the email
        if (!empty($listings)) {
            $author_id = $listings[0]->post_author;
            $debug['resolved_post_id'] = $listings[0]->ID;
            $debug['resolved_author_id'] = $author_id;
        }

        foreach ($listings as $listing) {
            $listing_id = $listing->ID;

            if (in_array($listing_id, $lifetime_ids)) {
                // Lifetime Logic
                $wpdb->delete($table_meta, ['post_id' => $listing_id, 'meta_key' => '_job_duration']);
                $wpdb->insert($table_meta, ['post_id' => $listing_id, 'meta_key' => '_job_duration', 'meta_value' => '0'], ['%d', '%s', '%s']);
                $wpdb->delete($table_meta, ['post_id' => $listing_id, 'meta_key' => '_job_expires']);
            } else {
                // Standard Logic
                $current_expiry = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM $table_meta WHERE post_id = %d AND meta_key = '_job_expires'", $listing_id));

                if (!empty($current_expiry)) {
                    $new_expiry = date('Y-m-d', strtotime($current_expiry . ' +1 year'));
                } else {
                    $new_expiry = date('Y-m-d', strtotime('+1 year'));
                }

                // Strict Cycle: Fix "Future Post" bug by resetting Publish Date if needed
                $original_publish = $listing->post_date;
                if (strtotime($original_publish) > time()) {
                    $original_publish = date('Y-m-d H:i:s');
                    $wpdb->update($wpdb->posts, ['post_date' => $original_publish, 'post_date_gmt' => $original_publish], ['ID' => $listing_id]);
                }

                $start_dt = new DateTime($original_publish);
                $end_dt = new DateTime($new_expiry);
                $diff = $start_dt->diff($end_dt);
                $new_duration = $diff->days;

                // Nuclear Date Updates
                $wpdb->delete($table_meta, ['post_id' => $listing_id, 'meta_key' => '_job_expires']);
                $wpdb->insert($table_meta, ['post_id' => $listing_id, 'meta_key' => '_job_expires', 'meta_value' => $new_expiry], ['%d', '%s', '%s']);

                $wpdb->delete($table_meta, ['post_id' => $listing_id, 'meta_key' => '_job_duration']);
                $wpdb->insert($table_meta, ['post_id' => $listing_id, 'meta_key' => '_job_duration', 'meta_value' => $new_duration], ['%d', '%s', '%s']);
            }

            // --- NUCLEAR PACKAGE UPGRADE ---
            // 1. Force Package ID
            $wpdb->delete($table_meta, ['post_id' => $listing_id, 'meta_key' => '_package_id']);
            $wpdb->insert($table_meta, ['post_id' => $listing_id, 'meta_key' => '_package_id', 'meta_value' => $premium_package_id], ['%d', '%s', '%s']);

            // 2. Force Featured Status (Optional but recommended for Premium)
            $wpdb->delete($table_meta, ['post_id' => $listing_id, 'meta_key' => '_featured']);
            $wpdb->insert($table_meta, ['post_id' => $listing_id, 'meta_key' => '_featured', 'meta_value' => '1'], ['%d', '%s', '%s']);
            // -------------------------------

            // Cleanup
            $wpdb->delete($table_meta, ['post_id' => $listing_id, 'meta_key' => '_job_subscription_id']);
            clean_post_cache($listing_id);
            update_post_meta($listing_id, '_payment_status', 'PAID');

            // Clear renewal ref to prevent double-payment confusion (optional but standard)
            update_post_meta($listing_id, '_renewal_reference', '');

            // ✅ CLEAR DUE EXPIRY (so dashboard updates immediately to 'PAID' not 'INVOICED')
            delete_post_meta($listing_id, '_ngd_due_expires_ts');
        }

        if ($author_id > 0) {
            $this->send_success_email($author_id, isset($new_expiry) ? $new_expiry : 'Lifetime');
        }

        return new WP_REST_Response(['success' => true, 'message' => 'Renewed & Upgraded (Nuclear)', 'debug' => $debug], 200);
    }

    public function send_success_email($user_id, $new_date)
    {
        $user_email = get_the_author_meta('user_email', $user_id);
        if (!$user_email)
            return;

        $html = "<div style='background:#f6f9fc;padding:40px 0;font-family:Helvetica,Arial,sans-serif;color:#333;text-align:center;'>
            <div style='max-width:600px;margin:0 auto;background:#fff;padding:40px;border-radius:8px;box-shadow:0 4px 10px rgba(0,0,0,0.05);'>
                <div style='padding-bottom:30px;border-bottom:1px solid #f0f0f0;margin-bottom:30px;'>
                    <img src='https://saprivateschools.co.za/wp-content/uploads/2023/11/SA-Private-Schools-Logo-150x150.png' style='height:50px;'>
                </div>
                <h1 style='color:#28a745;margin:0 0 10px 0;'>Payment Successful!</h1>
                <p style='font-size:16px;color:#555;'>For the next year, your profile will be on the <strong>Premium Package</strong>.</p>
                <div style='background:#d4edda;color:#155724;border:1px solid #c3e6cb;padding:20px;border-radius:6px;margin:30px 0;'>
                    <p style='margin:0;font-weight:bold;'>Active Until</p>
                    <p style='margin:5px 0 0 0;font-size:24px;'>" . date('d F Y', strtotime($new_date)) . "</p>
                </div>
                <p style='color:#888;font-size:14px;'>We look forward to a great year ahead with you.</p>
            </div>
        </div>";

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'Bcc: upgrades@saprivateschools.co.za'
        ];

        wp_mail($user_email, "Payment Received: Listing Renewed & Upgraded!", $html, $headers);
    }
}