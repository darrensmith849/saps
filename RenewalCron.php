<?php

namespace NGD_THEME\Functions;

if (!defined('ABSPATH'))
    exit;

class RenewalCron
{

    public function __construct($run_hooks = false)
    {
        if ($run_hooks) {
            $this->run_hooks();
        }
    }

    public function run_hooks(): void
    {
        if (!wp_next_scheduled('ngd_daily_renewal_check')) {
            wp_schedule_event(time(), 'daily', 'ngd_daily_renewal_check');
            Functions::logMessage('Cron scheduled for the first time');
        }
        add_action('ngd_daily_renewal_check', [$this, 'process_daily_logic']);
        Functions::logMessage('Hooks registered successfully');
    }

    public function process_daily_logic(): void
    {
        Functions::logMessage('=== CRON JOB STARTED ===');
        Functions::logMessage('Current time: ' . current_time('mysql'));

        try {
            // 1. STANDARD EXPIRY CHECKS
            Functions::logMessage('Running standard expiry checks...');
            $this->check_expiry_window(30, 'invoice');
            $this->check_expiry_window(14, 'reminder_14');
            $this->check_expiry_window(7, 'reminder_07');
            $this->check_expiry_window(3, 'reminder_03');
            $this->check_expiry_window(-1, 'downgrade_warn');
            $this->check_expiry_window(-8, 'downgrade_final');

            // 2. LATE PAYER CHASER (For current batch catch-up)
            Functions::logMessage('Running overdue chase...');
            $this->process_overdue_chase();

            // 3. PROCESS QUEUE (Safe Renewals)
            if (class_exists('\NGD_Renewals_Queue')) {
                Functions::logMessage('Processing Queue...');
                \NGD_Renewals_Queue::process_batch(50);
                \NGD_Renewals_Queue::send_preflight_summary();
            }

            Functions::logMessage('=== CRON JOB COMPLETED SUCCESSFULLY ===');
        } catch (\Exception $e) {
            Functions::logMessage('ERROR: ' . $e->getMessage());
            Functions::logMessage('Stack trace: ' . $e->getTraceAsString());
        }
    }

    private function check_expiry_window($days_offset, $type)
    {
        $target_package_id = 247687; // Paid Package
        $target_date = date('Y-m-d', strtotime(($days_offset >= 0 ? "+$days_offset" : "$days_offset") . " days"));

        // RELIABILITY FIX: Look back 60 days to catch "missed" runs
        $lookback_limit = date('Y-m-d', strtotime($target_date . ' -60 days'));

        Functions::logMessage("Checking {$type}: offset={$days_offset}, target_date <= {$target_date}");

        $args = [
            'post_type' => 'job_listing',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'meta_query' => [
                'relation' => 'AND',
                ['key' => '_package_id', 'value' => $target_package_id, 'compare' => '='],

                // Range check for reliability
                ['key' => '_job_expires', 'value' => $target_date, 'compare' => '<='],
                ['key' => '_job_expires', 'value' => $lookback_limit, 'compare' => '>='],
            ]
        ];

        // TARGETING FIX:
        // Invoice should target PAID users (who are about to expire).
        // Reminders/Downgrades should target DUE users (who have been invoiced).
        if ($type === 'invoice') {
            // Target currently PAID users (or missing status if legacy)
            // We EXCLUDE 'DUE' to avoid double-invoicing someone who is already in the cycle.
            $args['meta_query'][] = ['key' => '_payment_status', 'value' => 'DUE', 'compare' => '!='];
        } else {
            // Target DUE users only for reminders/downgrades
            $args['meta_query'][] = ['key' => '_payment_status', 'value' => 'DUE', 'compare' => '='];
        }

        $flag_key = '_sent_' . $type . '_' . date('Y');
        $args['meta_query'][] = ['key' => $flag_key, 'compare' => 'NOT EXISTS'];

        $this->group_and_send($args, $type, $flag_key);
    }

    private function process_overdue_chase()
    {
        $args = [
            'post_type' => 'job_listing',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'meta_query' => [
                ['key' => '_payment_status', 'value' => 'DUE', 'compare' => '=']
            ]
        ];

        $listings = get_posts($args);
        Functions::logMessage("Found " . count($listings) . " listings with DUE status for overdue chase");

        if (empty($listings)) {
            Functions::logMessage("No overdue listings to chase");
            return;
        }

        $groups = [];

        foreach ($listings as $l) {
            // Self-Healing: Stamp timestamp if missing so we can start counting days
            if (!get_post_meta($l->ID, '_invoice_sent_timestamp', true)) {
                update_post_meta($l->ID, '_invoice_sent_timestamp', time());
                Functions::logMessage("Self-healing: Added timestamp to listing {$l->ID}");
            }
            $groups[$l->post_author][] = $l;
        }

        Functions::logMessage("Grouped into " . count($groups) . " user groups for chase");

        foreach ($groups as $author_id => $author_listings) {
            // EVERGREEN FIX: Skip chase for evergreen users
            if (get_user_meta($author_id, '_ngd_evergreen', true) === 'yes') {
                Functions::logMessage("Skipping User {$author_id} (Evergreen Status)");
                continue;
            }

            $id = $author_listings[0]->ID;
            $sent_time = get_post_meta($id, '_invoice_sent_timestamp', true);
            $days_elapsed = floor((time() - $sent_time) / (60 * 60 * 24));

            Functions::logMessage("User {$author_id}: {$days_elapsed} days since invoice sent");

            $y = date('Y');

            // Standard Reminder Alignment:
            // T-3 (Reminder 03) is approx 27 days after Invoice
            // T-7 (Reminder 07) is approx 23 days after Invoice
            // T-14 (Reminder 14) is approx 16 days after Invoice

            // We use conservative thresholds to catch them if the standard date-match missed.

            if ($days_elapsed >= 27 && !get_post_meta($id, '_sent_reminder_03_' . $y, true)) {
                Functions::logMessage("Catch-up: Sending reminder_03 to user {$author_id}");
                \NGD_Renewals_Queue::enqueue_author($author_id, 'CRON_CATCHUP');
            } elseif ($days_elapsed >= 21 && !get_post_meta($id, '_sent_reminder_07_' . $y, true)) {
                Functions::logMessage("Catch-up: Sending reminder_07 to user {$author_id}");
                \NGD_Renewals_Queue::enqueue_author($author_id, 'CRON_CATCHUP');
            } elseif ($days_elapsed >= 14 && !get_post_meta($id, '_sent_reminder_14_' . $y, true)) {
                Functions::logMessage("Catch-up: Sending reminder_14 to user {$author_id}");
                \NGD_Renewals_Queue::enqueue_author($author_id, 'CRON_CATCHUP');
            } else {
                Functions::logMessage("No catch-up needed for user {$author_id} (days={$days_elapsed})");
            }
        }
    }

    private function group_and_send($args, $type, $flag_key)
    {
        $listings = get_posts($args);
        Functions::logMessage("Found " . count($listings) . " listings for {$type}");

        if (empty($listings)) {
            Functions::logMessage("No listings to process for {$type}");
            return;
        }

        $groups = [];
        foreach ($listings as $l) {
            $groups[$l->post_author][] = $l;
        }

        Functions::logMessage("Grouped into " . count($groups) . " user groups");

        foreach ($groups as $author_id => $author_listings) {
            // EVERGREEN FIX: Skip all automated emails/downgrades for evergreen users
            if (get_user_meta($author_id, '_ngd_evergreen', true) === 'yes') {
                Functions::logMessage("Skipping User {$author_id} (Evergreen Status) for {$type}");
                continue;
            }

            Functions::logMessage("Sending {$type} email to user {$author_id} for " . count($author_listings) . " listings");
            \NGD_Renewals_Queue::enqueue_author($author_id, 'CRON_STANDARD');
        }
    }

    // calculate_bundle_price removed in favor of PricingHelper logic

    private function perform_downgrade($listings)
    {
        $free_package_id = 138;

        // 1. Gather all unique authors implicated in this downgrade
        $author_ids = [];
        foreach ($listings as $l) {
            $aid = (int) $l->post_author;
            if ($aid > 0)
                $author_ids[$aid] = true;
        }
        $author_ids = array_keys($author_ids);

        if (empty($author_ids))
            return;

        // 2. Fetch ALL listings for these authors to ensure block alignment
        $all_listings = get_posts([
            'post_type' => 'job_listing',
            'post_status' => 'publish',
            'author__in' => $author_ids,
            'posts_per_page' => -1
        ]);

        if (empty($all_listings))
            return;

        foreach ($all_listings as $l) {
            update_post_meta($l->ID, '_package_id', $free_package_id);
            update_post_meta($l->ID, '_featured', '0');
            update_post_meta($l->ID, '_claimed', '0');
            update_post_meta($l->ID, '_job_duration', '0');

            // Belt-and-braces: Clear payment status so it doesn't read as PAID
            delete_post_meta($l->ID, '_payment_status');
        }
    }

    public function send_manual_stage_email($user_id, $listings, $type)
    {
        $flag_key = '_sent_' . $type . '_' . date('Y');
        $this->send_email_logic($user_id, $listings, $type, $flag_key);
    }

    private function send_email_logic($user_id, $listings, $type, $flag_key)
    {
        Functions::logMessage("=== SENDING EMAIL ===");
        Functions::logMessage("Type: {$type}, User: {$user_id}, Listings: " . count($listings));

        $user_data = get_userdata($user_id);
        if (!$user_data) {
            Functions::logMessage("ERROR: User {$user_id} not found");
            return;
        }
        $user_email = $user_data->user_email;
        $user_real_name = trim($user_data->first_name . ' ' . $user_data->last_name) ?: $user_data->display_name;

        $unique_ref = get_post_meta($listings[0]->ID, '_renewal_reference', true);
        if (!$unique_ref)
            $unique_ref = 'SCH-' . $user_id . '-' . rand(1000, 9999);

        require_once __DIR__ . '/PricingHelper.php';
        $listing_ids = array_map(function ($l) {
            return $l->ID;
        }, $listings);
        $calc = PricingHelper::calculate_price_from_listing_ids($listing_ids);

        if (!$calc['ok']) {
            Functions::logMessage("PRICING ERROR for user {$user_id}: " . $calc['error']);
            return; // Abort sending
        }
        $total_amount = $calc['total_formatted'];

        $names = [];
        $school_logo_url = get_post_meta($listings[0]->ID, '_job_logo', true);
        $school_display_name = $listings[0]->post_title;

        // --- GET BILLING DETAILS ---
        $b_company = get_user_meta($user_id, '_billing_company', true);
        $b_vat = get_user_meta($user_id, '_billing_vat', true);
        $b_reg = get_user_meta($user_id, '_billing_reg', true);
        $b_address = nl2br(get_user_meta($user_id, '_billing_address', true));
        $b_contact = get_user_meta($user_id, '_billing_contact', true);

        // Smart Default: Company > Real Name > Display Name
        $display_to = $b_company ? $b_company : $user_real_name;

        $update_link = "https://saprivateschools.co.za/update-invoice/?ref=" . $unique_ref;
        $track_img = "<img src='https://saprivateschools.co.za/wp-json/ngd/v1/track_open?ref=$unique_ref' width='1' height='1' style='display:none;' alt='' />";

        // --- BUILD INVOICE BOX (Always Show) ---
        $invoice_to_html = "
        <div style='margin-bottom: 25px; padding: 20px; background: #F9F9F9; border: 1px solid #F0F0F0; border-left: 4px solid #0191FF; font-size: 14px; border-radius: 4px;'>
            <table width='100%' cellspacing='0' cellpadding='0' border='0' style='margin-bottom: 12px;'>
                <tr>
                    <td align='left' valign='middle' style='color: #999; font-size: 11px; text-transform: uppercase; font-weight: bold; letter-spacing: 0.5px;'>INVOICE TO:</td>
                    <td align='right' valign='middle'>
                        <a href='$update_link' style='background-color: #0191FF; color: #ffffff; font-size: 11px; font-weight: bold; text-decoration: none; padding: 6px 12px; border-radius: 4px; display: inline-block;'>Change Details</a>
                    </td>
                </tr>
            </table>
            <div style='color: #333; line-height: 1.5;'>
                <strong>$display_to</strong><br>";

        if ($b_contact)
            $invoice_to_html .= "<span style='color:#555;'>Attn:</span> $b_contact<br>";
        if ($b_reg)
            $invoice_to_html .= "<span style='color:#555;'>Reg:</span> $b_reg<br>";
        if ($b_vat)
            $invoice_to_html .= "<span style='color:#555;'>VAT:</span> $b_vat<br>";
        if ($b_address)
            $invoice_to_html .= "<div style='margin-top: 10px; color: #555;'>$b_address</div>";

        $invoice_to_html .= "</div></div>";

        // Action: Downgrade if needed
        if ($type === 'downgrade_final') {
            $this->perform_downgrade($listings);
        }

        foreach ($listings as $l) {
            $names[] = $l->post_title;
            update_post_meta($l->ID, $flag_key, date('Y-m-d')); // Mark stage as sent

            if ($type === 'invoice') {
                update_post_meta($l->ID, '_renewal_reference', $unique_ref);
                update_post_meta($l->ID, '_renewal_reference_issued_ts', time());
                update_post_meta($l->ID, '_renewal_reference_source', 'prod');
                update_post_meta($l->ID, '_payment_status', 'DUE');
                update_post_meta($l->ID, '_current_year_invoice_sent', date('Y'));
                update_post_meta($l->ID, '_invoice_sent_timestamp', time());
            } elseif (strpos($type, 'reminder') !== false) {
                update_post_meta($l->ID, '_reminder_sent_date', date('Y-m-d'));
            }
        }

        // SYNC SIBLINGS (Prompt 1C/1D)
        // Ensure all listings for this author have the same state markers
        if (class_exists('NGD_Renewals_Dashboard') && !empty($listings)) {
            $source_id = $listings[0]->ID;
            $keys_to_sync = [];
            $prefixes = [$flag_key]; // Sync the 'sent' flag (e.g. _sent_invoice_2025)

            if ($type === 'invoice') {
                $keys_to_sync = [
                    '_renewal_reference',
                    '_renewal_reference_issued_ts',
                    '_renewal_reference_source',
                    '_payment_status',
                    '_current_year_invoice_sent',
                    '_invoice_sent_timestamp'
                ];
            } elseif (strpos($type, 'reminder') !== false) {
                $keys_to_sync = ['_reminder_sent_date'];
            }

            NGD_Renewals_Dashboard::ngd_sync_meta_to_author_listings($user_id, $source_id, $keys_to_sync, $prefixes);
        }

        // --- CONTENT SWITCHER ---
        $subject = "";
        $headline = "";
        $intro = "";
        $box_bg = "#fff3cd";
        $box_border = "#ffeeba";
        $box_text = "#856404"; // Default Yellow

        switch ($type) {
            case 'invoice':
                $subject = "Invoice: Annual Listing Renewal ($unique_ref)";
                $headline = "Annual Listing Renewal";
                $intro = "Your SA Private Schools membership will expire in 30 days. To avoid any interuption, please attend to the invoice details below and make a payment.";
                break;
            case 'reminder_14':
                $subject = "Reminder: Payment Outstanding ($unique_ref)";
                $headline = "Payment Reminder";
                $intro = "We noticed we haven't received your renewal payment yet. Please attend to this to ensure your premium membership remains active.";
                break;
            case 'reminder_07':
                $subject = "Action Required: 7 Days Left ($unique_ref)";
                $headline = "Payment Outstanding";
                $intro = "You have one week remaining before your premium membership expires.";
                $box_bg = "#f8d7da";
                $box_border = "#f5c6cb";
                $box_text = "#721c24"; // Red
                break;
            case 'reminder_03':
                $subject = "URGENT: Premium Membership Expires in 3 Days ($unique_ref)";
                $headline = "Final Reminder";
                $intro = "Your premium membership is about to expire. Please make payment immediately to avoid any disruption.";
                $box_bg = "#f8d7da";
                $box_border = "#f5c6cb";
                $box_text = "#721c24"; // Red
                break;
            case 'downgrade_warn':
                $subject = "Notice: Membership Expired - 7-Day Grace Period";
                $headline = "Downgrade Initiated";
                $intro = "Your premium membership has now expired. <strong>We understand that things happen and that deadlines can be missed, so we have activated a final 7-day Grace Period</strong> to keep your profile live.<br><br>If payment is not received within 7 days, your account will be automatically downgraded.";
                $box_bg = "#e2e3e5";
                $box_border = "#d6d8db";
                $box_text = "#383d41"; // Grey
                break;
            case 'downgrade_final':
                $subject = "Account Downgraded: Premium Features Removed";
                $headline = "Account Downgraded";
                $intro = "Your grace period has ended and no payment was received. <strong>Your listing has been downgraded to the Basic (Free) Tier.</strong><br><br>You have lost access to Premium features. To restore them, please pay the invoice below immediately.";
                $box_bg = "#343a40";
                $box_border = "#343a40";
                $box_text = "#ffffff"; // Black Box
                break;
        }

        // --- HTML TEMPLATE ---
        $school_list_items = "";
        foreach ($names as $name) {
            $school_list_items .= "<div style='padding: 8px 0; border-bottom: 1px solid #e1e8ed;'>$name</div>";
        }

        $client_logo_html = "";
        if ($school_logo_url) {
            if (is_array($school_logo_url))
                $school_logo_url = $school_logo_url[0];
            $client_logo_html = "<img src='$school_logo_url' alt='Logo' style='max-height: 50px; width: auto; border-radius: 4px;'>";
        }

        $html_body = "
        <div style='background-color: #f6f9fc; padding: 40px 0; font-family: \"Helvetica Neue\", Helvetica, Arial, sans-serif; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.05);'>
                <div style='padding: 30px 40px; border-bottom: 1px solid #f0f0f0;'>
                    <table width='100%' cellpadding='0' cellspacing='0'>
                        <tr>
                            <td align='left'><img src='https://saprivateschools.co.za/wp-content/uploads/2023/11/SA-Private-Schools-Logo-150x150.png' style='height: 50px;'></td>
                            <td align='right' style='text-align: right;'>
                                <div style='font-size: 14px; font-weight: bold; color: #555; margin-bottom: 5px;'>$school_display_name</div>
                                $client_logo_html
                            </td>
                        </tr>
                    </table>
                </div>
                <div style='padding: 40px;'>
                    <h1 style='font-size: 24px; font-weight: 800; color: #1a1a1a; margin: 0 0 20px 0;'>$headline</h1>
                    
                    $invoice_to_html

                    <p style='font-size: 16px; line-height: 1.6; color: #555; margin-bottom: 30px;'>$intro</p>
                    
                    <div style='background-color: #f8f9fa; border: 1px solid #e9ecef; border-radius: 6px; padding: 20px; margin-bottom: 30px;'>
                        <p style='font-weight: bold; font-size: 12px; text-transform: uppercase; color: #999; margin: 0 0 10px 0;'>Schools Included</p>
                        $school_list_items
                        <div style='margin-top: 15px; padding-top: 15px; border-top: 2px solid #e9ecef; text-align: right;'>
                            <span style='font-size: 14px; color: #666; margin-right: 10px;'>Total Due:</span>
                            <span style='font-size: 20px; font-weight: bold; color: #000;'>R$total_amount</span>
                        </div>
                    </div>

                    <div style='background-color: $box_bg; border: 1px solid $box_border; border-radius: 6px; padding: 25px; text-align: center; margin-bottom: 30px;'>
                        <p style='font-size: 12px; font-weight: bold; letter-spacing: 1px; color: $box_text; margin: 0 0 5px 0; text-transform: uppercase;'>Beneficiary Reference</p>
                        <div style='font-size: 32px; font-weight: 900; color: #222; letter-spacing: 1px; margin-bottom: 5px;'>$unique_ref</div>
                        <p style='font-size: 13px; color: $box_text; margin: 0;'>⚠️ Use this exact reference for automatic activation.</p>
                    </div>

                    <div style='margin-bottom: 20px;'>
                        <p style='font-weight: bold; font-size: 14px; text-transform: uppercase; color: #999; margin-bottom: 10px;'>Banking Details</p>
                        <table width='100%' style='font-size: 15px; color: #444; border-collapse: collapse;'>
                            <tr><td style='padding: 5px 0; color: #777;'>Bank:</td><td style='padding: 5px 0; font-weight: 600;'>FNB</td></tr>
                            <tr><td style='padding: 5px 0; color: #777;'>Account Name:</td><td style='padding: 5px 0; font-weight: 600;'>SA Private Schools</td></tr>
                            <tr><td style='padding: 5px 0; color: #777;'>Account Number:</td><td style='padding: 5px 0; font-weight: 600;'>63000024636</td></tr>
                            <tr><td style='padding: 5px 0; color: #777;'>Branch Code:</td><td style='padding: 5px 0; font-weight: 600;'>250655</td></tr>
                        </table>
                    </div>
                </div>

                <div style='background-color: #fafafa; padding: 20px; text-align: center; border-top: 1px solid #eee; font-size: 12px; color: #aaa;'>
                    <p style='margin: 0;'>This email serves as a tax invoice.</p>
                    <p style='margin: 5px 0 0 0;'>&copy; " . date('Y') . " SA Private Schools</p>
                </div>
            </div>
            $track_img
        </div>";

        // SEND VIA WORDPRESS SMTP
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'Bcc: upgrades@saprivateschools.co.za'
        ];

        Functions::logMessage("Sending email to: {$user_email}");
        Functions::logMessage("Subject: {$subject}");
        Functions::logMessage("Reference: {$unique_ref}");

        $result = wp_mail($user_email, $subject, $html_body, $headers);

        if ($result) {
            Functions::logMessage("✓ Email sent successfully to {$user_email}");
        } else {
            Functions::logMessage("✗ Email FAILED to send to {$user_email}");
            Functions::logMessage("Check SMTP configuration in EmailConfiguration.php");
        }

        Functions::logMessage("=== EMAIL SEND COMPLETE ===");
    }
}