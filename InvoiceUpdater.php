<?php

namespace NGD_THEME\Functions;

if (!defined('ABSPATH'))
    exit;

class InvoiceUpdater
{

    public function __construct($run_hooks = false)
    {
        if ($run_hooks) {
            $this->run_hooks();
        }
    }

    public function run_hooks(): void
    {
        add_shortcode('invoice_updater', [$this, 'render_wrapper']);
        add_shortcode('ngd_invoice_viewer', [$this, 'render_wrapper']); // alias for /invoice page
    }

    private function resolve_ref(): string
    {
        // 1) Standard query string (most emails)
        $keys = ['ref', 'invoice_ref', 'ngd_ref', 'renewal_ref', 'r'];

        foreach ($keys as $k) {
            if (isset($_GET[$k])) {
                $v = sanitize_text_field(wp_unslash($_GET[$k]));
                if ($v !== '') {
                    return $v;
                }
            }
        }

        // 2) Pretty URL / rewrite var (e.g. /invoice-view/SCH-XXX/)
        if (function_exists('get_query_var')) {
            $qv = get_query_var('ref');
            if (is_string($qv) && $qv !== '') {
                return sanitize_text_field($qv);
            }
        }

        return '';
    }

    /**
     * Main distinct shortcode handler.
     * Renders:
     * 1. Full Invoice HTML (Viewer)
     * 2. Success Message (if updated)
     * 3. Updater Form
     */
    public function render_wrapper()
    {
        // Avoid caching weirdness where a “no-ref” version gets cached and served to all
        if (!headers_sent()) {
            nocache_headers();
        }

        $ref = $this->resolve_ref();
        if ($ref === '') {
            return '<p>Invalid Link.</p>';
        }

        $args = [
            'post_type' => 'job_listing',
            'posts_per_page' => 1,
            'post_status' => 'any',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => '_renewal_reference',
                    'value' => $ref,
                ],
                [
                    'key' => '_renewal_reference_alias',
                    'value' => $ref,
                ],
            ],
        ];

        $check = get_posts($args);

        if (empty($check)) {
            return '<p style="color:red;">Invoice Reference Not Found or Expired.</p>';
        }

        $user_id = $check[0]->post_author;
        $success_msg = '';

        if (isset($_POST['update_invoice_submit'])) {
            $success_msg = $this->handle_submission($user_id, $ref);
        }

        $invoice_html = $this->generate_invoice_html($user_id, $ref, false);
        $form_html = $this->get_form_html($user_id, $ref);

        return '<div class="ngd-invoice-page-wrapper">' .
            $invoice_html .
            $success_msg .
            '<div style="height:30px;"></div>' .
            $form_html .
            '</div>';
    }

    private function get_form_html($user_id, $ref)
    {
        // Get Existing Data
        $val_company = get_user_meta($user_id, '_billing_company', true);

        if (empty($val_company)) {
            $user_data = get_userdata($user_id);
            $user_name = trim($user_data->first_name . ' ' . $user_data->last_name);
            $val_company = $user_name ? $user_name : $user_data->display_name;
        }

        $val_vat = get_user_meta($user_id, '_billing_vat', true);
        $val_reg = get_user_meta($user_id, '_billing_reg', true);
        $val_address = get_user_meta($user_id, '_billing_address', true);
        $val_contact = get_user_meta($user_id, '_billing_contact', true);

        ob_start();
        ?>
        <div class="invoice-updater-box"
            style="max-width: 600px; margin: 0 auto; padding: 30px; background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 8px; font-family: sans-serif;">
            <h3 style="text-align:center; margin-top:0; margin-bottom: 10px; color:#333;">Update Invoice Details</h3>
            <p style="text-align:center; font-size: 14px; color: #666; margin-bottom:30px;">Need to change the billing info on
                the invoice above? Enter details below to update and resend.</p>

            <form method="post">
                <p style="margin-bottom:15px;">
                    <label style="font-weight:bold; display:block; margin-bottom:5px; font-size:13px;">Registered Entity /
                        Invoice To</label>
                    <input type="text" name="billing_company" value="<?php echo esc_attr($val_company); ?>"
                        style="width:100%; padding: 10px; border:1px solid #ccc; border-radius:4px;">
                </p>
                <div style="display:flex; gap:15px;">
                    <p style="margin-bottom:15px; flex:1;">
                        <label style="font-weight:bold; display:block; margin-bottom:5px; font-size:13px;">VAT Number</label>
                        <input type="text" name="billing_vat" value="<?php echo esc_attr($val_vat); ?>"
                            style="width:100%; padding: 10px; border:1px solid #ccc; border-radius:4px;">
                    </p>
                    <p style="margin-bottom:15px; flex:1;">
                        <label style="font-weight:bold; display:block; margin-bottom:5px; font-size:13px;">Registration
                            No</label>
                        <input type="text" name="billing_reg" value="<?php echo esc_attr($val_reg); ?>"
                            style="width:100%; padding: 10px; border:1px solid #ccc; border-radius:4px;">
                    </p>
                </div>
                <p style="margin-bottom:15px;">
                    <label style="font-weight:bold; display:block; margin-bottom:5px; font-size:13px;">Contact Person
                        (Finance)</label>
                    <input type="text" name="billing_contact" value="<?php echo esc_attr($val_contact); ?>"
                        style="width:100%; padding: 10px; border:1px solid #ccc; border-radius:4px;">
                </p>
                <p style="margin-bottom:15px;">
                    <label style="font-weight:bold; display:block; margin-bottom:5px; font-size:13px;">Physical Address</label>
                    <textarea name="billing_address"
                        style="width:100%; padding: 10px; height: 80px; border:1px solid #ccc; border-radius:4px;"><?php echo esc_textarea($val_address); ?></textarea>
                </p>
                <p style="text-align:center; margin-top: 25px;">
                    <input type="submit" name="update_invoice_submit" value="Update & Resend Invoice"
                        style="background: #0191FF; color: #fff; padding: 12px 30px; border: none; cursor: pointer; font-size: 16px; border-radius: 4px; font-weight:bold;">
                </p>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    private function handle_submission($user_id, $ref)
    {
        update_user_meta($user_id, '_billing_company', sanitize_text_field($_POST['billing_company']));
        update_user_meta($user_id, '_billing_vat', sanitize_text_field($_POST['billing_vat']));
        update_user_meta($user_id, '_billing_reg', sanitize_text_field($_POST['billing_reg']));
        update_user_meta($user_id, '_billing_contact', sanitize_text_field($_POST['billing_contact']));
        update_user_meta($user_id, '_billing_address', sanitize_textarea_field($_POST['billing_address']));

        $this->resend_invoice_email($user_id, $ref);

        return '<div style="background: #d4edda; color: #155724; padding: 20px; border-radius: 5px; text-align: center; max-width:600px; margin:20px auto; border: 1px solid #c3e6cb; font-family:sans-serif;">
                    <h3 style="margin-top:0;">✅ Success!</h3>
                    <p style="margin-bottom:5px;">Your invoice details have been updated.</p>
                    <p style="font-size:13px;">A copy has also been updated in the viewer above and sent to your email.</p>
                </div>';
    }

    private function resend_invoice_email($user_id, $ref)
    {
        $user_data = get_userdata($user_id);
        $user_email = $user_data->user_email;

        // Generate full HTML (email mode)
        $html = $this->generate_invoice_html($user_id, $ref, true);

        $listings = get_posts(['post_type' => 'job_listing', 'meta_key' => '_renewal_reference', 'meta_value' => $ref, 'posts_per_page' => 1]);
        $school_name = $listings ? $listings[0]->post_title : 'Invoice';

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'Cc: Darren <darren@saprivateschools.co.za>',
        ];
        wp_mail($user_email, "Tax Invoice (Updated): $school_name", $html, $headers);
    }

    /**
     * Generates the Invoice HTML.
     * Used by both the Web Viewer and Email Sender.
     */
    private function generate_invoice_html($user_id, $ref, $for_email = false)
    {
        $user_data = get_userdata($user_id);

        // Get Listings
        // Get Listings (try both current ref and alias)
        $listings = get_posts([
            'post_type' => 'job_listing',
            'posts_per_page' => -1,
            'post_status' => 'any',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => '_renewal_reference',
                    'value' => $ref,
                ],
                [
                    'key' => '_renewal_reference_alias',
                    'value' => $ref,
                ],
            ],
        ]);

        if (empty($listings))
            return "<p>Invoice data not found.</p>";

        // NEW PRICING HELPER INTEGRATION
        // NEW PRICING HELPER INTEGRATION
        $listing_ids = array_map(function ($l) {
            return $l->ID; }, $listings);

        $total_amount = 0;
        if (file_exists(__DIR__ . '/PricingHelper.php')) {
            require_once __DIR__ . '/PricingHelper.php';
        }

        $calc_class = class_exists('\NGD_THEME\Functions\PricingHelper')
            ? '\NGD_THEME\Functions\PricingHelper'
            : (class_exists('PricingHelper') ? 'PricingHelper' : null);

        if ($calc_class) {
            $calc = $calc_class::calculate_price_from_listing_ids($listing_ids);
            $total_amount = (!empty($calc['ok']) && isset($calc['total'])) ? (float) $calc['total'] : 0;
        }

        $school_name = $listings[0]->post_title;

        // GET BILLING DETAILS
        $b_company = get_user_meta($user_id, '_billing_company', true);
        $b_vat = get_user_meta($user_id, '_billing_vat', true);
        $b_reg = get_user_meta($user_id, '_billing_reg', true);
        $b_address = nl2br(get_user_meta($user_id, '_billing_address', true));
        $b_contact = get_user_meta($user_id, '_billing_contact', true);

        if ($b_company) {
            $invoice_to_name = $b_company;
        } else {
            $user_name = trim($user_data->first_name . ' ' . $user_data->last_name);
            $invoice_to_name = $user_name ? $user_name : $user_data->display_name;
        }

        // Build Extra Details Block
        $extra_details = "";
        if ($b_vat)
            $extra_details .= "<br><span style='font-weight:normal; font-size:13px; color:#666;'>VAT: $b_vat</span>";
        if ($b_reg)
            $extra_details .= "<br><span style='font-weight:normal; font-size:13px; color:#666;'>Reg: $b_reg</span>";
        if ($b_address)
            $extra_details .= "<div style='margin-top:5px; font-weight:normal; font-size:13px; color:#666;'>$b_address</div>";

        // Logic for Links/Buttons
        $update_link = home_url('/update-invoice/?ref=' . $ref);

        // If Web View, maybe show a "Download PDF" (Javascript Print) button?
        // User requested "Download Invoice PDF" button/link.
        // Easiest is generic window.print() or a specific PDF generation link if available.
        // Assuming window.print() for now as no PDF generator was prompted. 
        // Or if there IS a method, user didn't specify. I'll add a Print button.

        $custom_logo_id = get_theme_mod('custom_logo');
        $logo_url = 'https://saprivateschools.co.za/wp-content/uploads/2019/07/logo.png';
        if ($custom_logo_id) {
            $image = wp_get_attachment_image_src($custom_logo_id, 'full');
            if (isset($image[0]))
                $logo_url = $image[0];
        }

        $listing_rows = "";
        foreach ($listings as $l) {
            $listing_rows .= "<div style='border-bottom:1px solid #eee; padding: 10px 0; color:#555;'>{$l->post_title}</div>";
        }

        // --- HTML CONSTRUCTION ---
        // We strip <html><body> for web view to ensure it fits in the page, 
        // essentially returning just the .wrapper content.

        $container_styles = "max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 0; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); overflow: hidden; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;";
        if ($for_email) {
            $container_styles .= " margin-bottom: 40px;"; // Email spacing
        } else {
            // Web view adjustments
        }

        $core_html = "
            <style>
                .saps-inv-box { background-color: #f8f9fa; border-left: 4px solid #3b82f6; padding: 20px; border-radius: 4px; margin-bottom: 25px; position: relative; }
                .saps-inv-label { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #888; margin-bottom: 8px; font-weight: 600; }
            </style>
            <div class='container' style=\"$container_styles\">
                
                <table width='100%' class='header' style='border-bottom:1px solid #eee; padding: 30px 40px;'>
                    <tr>
                        <td align='left'>
                            <img src='{$logo_url}' style='width: 40px; height: auto;'> 
                        </td>
                        <td align='right' style='font-weight: bold; color: #555; font-size: 14px;'>
                            {$school_name}
                        </td>
                    </tr>
                </table>

                <div class='content' style='padding: 40px;'>
                    <div style='font-size: 24px; font-weight: 800; color: #1a1a1a; margin-bottom: 25px;'>Annual Listing Renewal</div>

                    <div class='saps-inv-box'>
                        <div class='saps-inv-label'>INVOICE TO:</div>
                        <div style='font-size: 16px; font-weight: bold; color: #1a1a1a;'>
                            {$invoice_to_name}
                            {$extra_details}
                        </div>
                    </div>

                    <p style='color:#555; font-size:15px; margin-bottom:25px;'>Your listing(s) on SA Private Schools are due for renewal.</p>

                    <div style='background-color: #f8f9fa; padding: 25px; border-radius: 6px; margin-top: 25px;'>
                        <div style='margin-bottom:15px; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #888; font-weight: 600;'>SCHOOLS INCLUDED</div>
                        {$listing_rows}
                        
                        <div style='display: flex; justify-content: flex-end; align-items: baseline; margin-top: 15px; border-top: 1px solid #eee; padding-top: 15px;'>
                            <span style='color: #666; font-size: 14px; margin-right: 10px;'>Total Due:</span>
                            <span style='font-size: 24px; font-weight: 800; color: #1a1a1a;'>R" . number_format($total_amount, 2) . "</span>
                        </div>
                    </div>

                    <div style='background-color: #FFF8DC; padding: 30px; text-align: center; border-radius: 6px; margin: 25px 0; border: 1px solid #FFE4B5;'>
                        <div style='color:#B8860B; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; font-weight: 600;'>BENEFICIARY REFERENCE</div>
                        <span style='font-size: 28px; font-weight: 800; color: #333; margin: 10px 0; display: block; letter-spacing: 1px;'>{$ref}</span>
                        <div style='font-size: 14px; color: #856404; font-weight: 500;'>⚠️ Use this exact reference.</div>
                    </div>

                    <div style='margin-top: 30px;'>
                        <div style='font-size: 12px; font-weight: 700; text-transform: uppercase; color: #888; margin-bottom: 15px; letter-spacing: 0.5px;'>BANKING DETAILS</div>
                        <div style='display: table; width: 100%;'>
                            <div style='display: table-row'><span style='display: table-cell; color: #666; padding: 4px 0; width: 140px; font-size: 14px;'>Bank:</span><span style='display: table-cell; color: #1a1a1a; font-weight: 700; font-size: 14px;'>FNB</span></div>
                            <div style='display: table-row'><span style='display: table-cell; color: #666; padding: 4px 0; width: 140px; font-size: 14px;'>Account Name:</span><span style='display: table-cell; color: #1a1a1a; font-weight: 700; font-size: 14px;'>SA Private Schools</span></div>
                            <div style='display: table-row'><span style='display: table-cell; color: #666; padding: 4px 0; width: 140px; font-size: 14px;'>Account Number:</span><span style='display: table-cell; color: #1a1a1a; font-weight: 700; font-size: 14px;'>63000024636</span></div>
                            <div style='display: table-row'><span style='display: table-cell; color: #666; padding: 4px 0; width: 140px; font-size: 14px;'>Branch Code:</span><span style='display: table-cell; color: #1a1a1a; font-weight: 700; font-size: 14px;'>250655</span></div>
                        </div>
                    </div>

                </div>

                <div style='background-color: #fcfcfc; padding: 20px; text-align: center; font-size: 12px; color: #999; border-top: 1px solid #f0f0f0;'>
                    This email serves as a tax invoice.<br>
                    &copy; 2025 SA Private Schools
                </div>
            </div>";

        // Web View: Add Download Button
        if (!$for_email) {
            $core_html .= "
            <div style='text-align:center; margin-top:20px; margin-bottom:40px;'>
                 <button onclick='window.print()' style='background:#333; color:white; border:none; padding:10px 20px; border-radius:4px; font-weight:bold; cursor:pointer;'>🖨️ Print / Save as PDF</button>
            </div>";
        }

        // Return wrapped if email, else just core
        if ($for_email) {
            return "<!DOCTYPE html><html><head></head><body style='margin:0; padding:0; background-color:#f6f9fc;'><div style='width:100%; padding:40px 0;'>$core_html</div></body></html>";
        }

        return $core_html;
    }
}