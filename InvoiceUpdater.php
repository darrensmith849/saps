<?php

namespace NGD_THEME\Functions;

if ( ! defined( 'ABSPATH' ) ) exit;

class InvoiceUpdater {

    public function __construct( $run_hooks = false ) {
        if ( $run_hooks ) {
            $this->run_hooks();
        }
    }

    public function run_hooks(): void {
        add_shortcode( 'invoice_updater', [ $this, 'render_form' ] );
    }

    public function render_form() {
        $ref = sanitize_text_field( $_GET['ref'] ?? '' );
        if ( empty( $ref ) ) return '<p>Invalid Link.</p>';

        $args = [
            'post_type'  => 'job_listing',
            'meta_key'   => '_renewal_reference',
            'meta_value' => $ref,
            'posts_per_page' => 1,
            'post_status' => 'any'
        ];
        $check = get_posts($args);
        
        if ( empty( $check ) ) return '<p style="color:red;">Invoice Reference Not Found or Expired.</p>';

        $user_id = $check[0]->post_author;
        
        if ( isset( $_POST['update_invoice_submit'] ) ) {
            return $this->handle_submission( $user_id, $ref );
        }

        // Get Existing Data
        $val_company = get_user_meta( $user_id, '_billing_company', true );
        
        if ( empty($val_company) ) {
            $user_data = get_userdata($user_id);
            $user_name = trim($user_data->first_name . ' ' . $user_data->last_name);
            $val_company = $user_name ? $user_name : $user_data->display_name;
        }

        $val_vat = get_user_meta( $user_id, '_billing_vat', true );
        $val_reg = get_user_meta( $user_id, '_billing_reg', true );
        $val_address = get_user_meta( $user_id, '_billing_address', true );
        $val_contact = get_user_meta( $user_id, '_billing_contact', true );

        ob_start();
        ?>
        <div class="invoice-updater-box" style="max-width: 500px; margin: 40px auto; padding: 30px; background: #fff; border: 1px solid #e1e8ed; border-radius: 8px; font-family: sans-serif;">
            <h2 style="text-align:center; margin-bottom: 10px; color:#333;">Update Invoice Details</h2>
            <p style="text-align:center; font-size: 14px; color: #666; margin-bottom:30px;">Enter your specific billing details below. We will update and resend your invoice immediately.</p>
            
            <form method="post">
                <p style="margin-bottom:15px;">
                    <label style="font-weight:bold; display:block; margin-bottom:5px;">Registered Entity / User Name</label>
                    <input type="text" name="billing_company" value="<?php echo esc_attr($val_company); ?>" style="width:100%; padding: 10px; border:1px solid #ccc; border-radius:4px;">
                </p>
                <div style="display:flex; gap:15px;">
                    <p style="margin-bottom:15px; flex:1;">
                        <label style="font-weight:bold; display:block; margin-bottom:5px;">VAT Number</label>
                        <input type="text" name="billing_vat" value="<?php echo esc_attr($val_vat); ?>" style="width:100%; padding: 10px; border:1px solid #ccc; border-radius:4px;">
                    </p>
                    <p style="margin-bottom:15px; flex:1;">
                        <label style="font-weight:bold; display:block; margin-bottom:5px;">Registration No</label>
                        <input type="text" name="billing_reg" value="<?php echo esc_attr($val_reg); ?>" style="width:100%; padding: 10px; border:1px solid #ccc; border-radius:4px;">
                    </p>
                </div>
                <p style="margin-bottom:15px;">
                    <label style="font-weight:bold; display:block; margin-bottom:5px;">Contact Person (Finance)</label>
                    <input type="text" name="billing_contact" value="<?php echo esc_attr($val_contact); ?>" style="width:100%; padding: 10px; border:1px solid #ccc; border-radius:4px;">
                </p>
                <p style="margin-bottom:15px;">
                    <label style="font-weight:bold; display:block; margin-bottom:5px;">Physical Address</label>
                    <textarea name="billing_address" style="width:100%; padding: 10px; height: 80px; border:1px solid #ccc; border-radius:4px;"><?php echo esc_textarea($val_address); ?></textarea>
                </p>
                <p style="text-align:center; margin-top: 25px;">
                    <input type="submit" name="update_invoice_submit" value="Update & Resend Invoice" style="background: #0191FF; color: #fff; padding: 12px 30px; border: none; cursor: pointer; font-size: 16px; border-radius: 4px; font-weight:bold;">
                </p>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    private function handle_submission( $user_id, $ref ) {
        update_user_meta( $user_id, '_billing_company', sanitize_text_field( $_POST['billing_company'] ) );
        update_user_meta( $user_id, '_billing_vat', sanitize_text_field( $_POST['billing_vat'] ) );
        update_user_meta( $user_id, '_billing_reg', sanitize_text_field( $_POST['billing_reg'] ) );
        update_user_meta( $user_id, '_billing_contact', sanitize_text_field( $_POST['billing_contact'] ) );
        update_user_meta( $user_id, '_billing_address', sanitize_textarea_field( $_POST['billing_address'] ) );

        $this->resend_invoice_email( $user_id, $ref );

        return '<div style="background: #d4edda; color: #155724; padding: 30px; border-radius: 5px; text-align: center; max-width:500px; margin:40px auto; border: 1px solid #c3e6cb; font-family:sans-serif;">
                    <h2 style="margin-top:0;">✅ Success!</h2>
                    <p>Your details have been updated.</p>
                    <p>A new invoice has been sent to your email address.</p>
                    <p><a href="?ref=' . esc_attr($ref) . '">Edit again</a></p>
                </div>';
    }

    private function calculate_bundle_price($count) {
        if ($count >= 2) return 4999.00;
        return 2499.00;
    }

    private function resend_invoice_email( $user_id, $ref ) {
        $user_data = get_userdata($user_id);
        $user_email = $user_data->user_email;

        // Get Listings
        $listings = get_posts(['post_type' => 'job_listing', 'meta_key' => '_renewal_reference', 'meta_value' => $ref, 'posts_per_page' => -1, 'post_status' => 'any']);
        if ( empty($listings) ) return;

        $total_amount = $this->calculate_bundle_price(count($listings));
        $school_name = $listings[0]->post_title;

        // GET NEW BILLING DETAILS
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
        if ($b_vat) $extra_details .= "<br><span style='font-weight:normal; font-size:13px; color:#666;'>VAT: $b_vat</span>";
        if ($b_reg) $extra_details .= "<br><span style='font-weight:normal; font-size:13px; color:#666;'>Reg: $b_reg</span>";
        if ($b_address) $extra_details .= "<br><span style='font-weight:normal; font-size:13px; color:#666;'>$b_address</span>";

        $update_link = home_url( '/update-invoice/?ref=' . $ref );

        $custom_logo_id = get_theme_mod( 'custom_logo' );
        $logo_url = 'https://saprivateschools.co.za/wp-content/uploads/2019/07/logo.png'; 
        if ( $custom_logo_id ) {
            $image = wp_get_attachment_image_src( $custom_logo_id , 'full' );
            if(isset($image[0])) $logo_url = $image[0];
        }

        $listing_rows = "";
        foreach($listings as $l) {
            $listing_rows .= "<div style='border-bottom:1px solid #eee; padding: 10px 0; color:#555;'>{$l->post_title}</div>";
        }

        // REUSE THE SAME "PERFECT TEMPLATE" HTML
        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f6f9fc; }
                .wrapper { width: 100%; background-color: #f6f9fc; padding: 40px 0; }
                .container { max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 0; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); overflow: hidden; }
                
                /* HEADER */
                .header { padding: 30px 40px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f0f0f0; }
                .logo { width: 40px; height: auto; }
                .school-top-name { font-weight: bold; color: #555; font-size: 14px; text-align: right; }

                /* BODY */
                .content { padding: 40px; }
                .title { font-size: 24px; font-weight: 800; color: #1a1a1a; margin-bottom: 25px; }

                /* INVOICE TO BOX */
                .invoice-to-box { background-color: #f8f9fa; border-left: 4px solid #3b82f6; padding: 20px; border-radius: 4px; margin-bottom: 25px; position: relative; }
                .label { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #888; margin-bottom: 8px; font-weight: 600; }
                .client-name { font-size: 16px; font-weight: bold; color: #1a1a1a; }
                .change-btn { position: absolute; top: 20px; right: 20px; background: #3b82f6; color: white; text-decoration: none; font-size: 12px; font-weight: bold; padding: 6px 12px; border-radius: 4px; }

                /* SUMMARY BOX */
                .summary-box { background-color: #f8f9fa; padding: 25px; border-radius: 6px; margin-top: 25px; }
                .total-row { display: flex; justify-content: flex-end; align-items: baseline; margin-top: 15px; border-top: 1px solid #eee; padding-top: 15px; }
                .total-label { color: #666; font-size: 14px; margin-right: 10px; }
                .total-amount { font-size: 24px; font-weight: 800; color: #1a1a1a; }

                /* REF BOX */
                .ref-box { background-color: #FFF8DC; padding: 30px; text-align: center; border-radius: 6px; margin: 25px 0; border: 1px solid #FFE4B5; }
                .ref-code { font-size: 28px; font-weight: 800; color: #333; margin: 10px 0; display: block; letter-spacing: 1px; }
                .ref-warn { font-size: 14px; color: #856404; font-weight: 500; }

                /* BANKING */
                .banking-section { margin-top: 30px; }
                .banking-header { font-size: 12px; font-weight: 700; text-transform: uppercase; color: #888; margin-bottom: 15px; letter-spacing: 0.5px; }
                .bank-grid { display: table; width: 100%; }
                .bank-row { display: table-row; }
                .bank-label { display: table-cell; color: #666; padding: 4px 0; width: 140px; font-size: 14px; }
                .bank-val { display: table-cell; color: #1a1a1a; font-weight: 700; font-size: 14px; }

                /* FOOTER */
                .footer { background-color: #fcfcfc; padding: 20px; text-align: center; font-size: 12px; color: #999; border-top: 1px solid #f0f0f0; }
            </style>
        </head>
        <body>
            <div class='wrapper'>
                <div class='container'>
                    
                    <table width='100%' class='header' style='border-bottom:1px solid #eee;'>
                        <tr>
                            <td align='left'>
                                <img src='{$logo_url}' class='logo'> 
                            </td>
                            <td align='right' class='school-top-name'>
                                {$school_name}
                            </td>
                        </tr>
                    </table>

                    <div class='content'>
                        <div class='title'>Annual Listing Renewal</div>

                        <div class='invoice-to-box'>
                            <div class='label'>INVOICE TO:</div>
                            <div class='client-name'>
                                {$invoice_to_name}
                                {$extra_details}
                            </div>
                            <a href='{$update_link}' class='change-btn'>Change Details</a>
                        </div>

                        <p style='color:#555; font-size:15px; margin-bottom:25px;'>Your listing(s) on SA Private Schools are due for renewal.</p>

                        <div class='summary-box'>
                            <div class='label' style='margin-bottom:15px;'>SCHOOLS INCLUDED</div>
                            {$listing_rows}
                            
                            <div class='total-row'>
                                <span class='total-label'>Total Due:</span>
                                <span class='total-amount'>R" . number_format($total_amount, 2) . "</span>
                            </div>
                        </div>

                        <div class='ref-box'>
                            <div class='label' style='color:#B8860B;'>BENEFICIARY REFERENCE</div>
                            <span class='ref-code'>{$ref}</span>
                            <div class='ref-warn'>⚠️ Use this exact reference.</div>
                        </div>

                        <div class='banking-section'>
                            <div class='banking-header'>BANKING DETAILS</div>
                            <div class='bank-grid'>
                                <div class='bank-row'><span class='bank-label'>Bank:</span><span class='bank-val'>FNB</span></div>
                                <div class='bank-row'><span class='bank-label'>Account Name:</span><span class='bank-val'>SA Private Schools</span></div>
                                <div class='bank-row'><span class='bank-label'>Account Number:</span><span class='bank-val'>63000024636</span></div>
                                <div class='bank-row'><span class='bank-label'>Branch Code:</span><span class='bank-val'>250655</span></div>
                            </div>
                        </div>

                    </div>

                    <div class='footer'>
                        This email serves as a tax invoice.<br>
                        &copy; " . date('Y') . " SA Private Schools
                    </div>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        wp_mail($user_email, "Tax Invoice (Updated): $school_name", $html, $headers);
    }
}