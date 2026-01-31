<?php

namespace NGD_THEME\Functions;

if ( ! defined( 'ABSPATH' ) ) exit;

class UpgradePortal {

    public function __construct( $run_hooks = false ) {
        if ( $run_hooks ) {
            $this->run_hooks();
        }
    }

    public function run_hooks(): void {
        // 1. Shortcode
        add_shortcode( 'school_upgrade_portal', [ $this, 'render_portal' ] );

        // 2. AJAX Handlers
        add_action( 'wp_ajax_saps_search_school', [ $this, 'ajax_search_school' ] );
        add_action( 'wp_ajax_nopriv_saps_search_school', [ $this, 'ajax_search_school' ] );

        add_action( 'wp_ajax_saps_generate_invoice', [ $this, 'ajax_generate_invoice' ] );
        add_action( 'wp_ajax_nopriv_saps_generate_invoice', [ $this, 'ajax_generate_invoice' ] );
    }

    // --- FRONTEND RENDER ---
    public function render_portal() {
        wp_enqueue_script('jquery');
        ob_start();
        ?>
        <style>
            .saps-upgrade-wrapper { max-width: 800px; margin: 0 auto; font-family: sans-serif; padding: 40px 20px; text-align: center; }
            .saps-search-box { position: relative; margin-bottom: 30px; }
            .saps-search-input { width: 100%; padding: 20px; font-size: 18px; border: 2px solid #ddd; border-radius: 8px; outline: none; transition: 0.3s; }
            .saps-search-input:focus { border-color: #0191FF; box-shadow: 0 4px 12px rgba(1,145,255,0.1); }
            
            .saps-btn { background: #0191FF; color: white; padding: 15px 30px; border: none; border-radius: 5px; font-size: 16px; font-weight: bold; cursor: pointer; transition: 0.3s; margin-top: 20px; }
            .saps-btn:hover { background: #0077d4; }
            .saps-btn:disabled { background: #ccc; cursor: not-allowed; }

            /* RESULTS CARD */
            #saps-results-area { display: none; text-align: left; background: #fff; border: 1px solid #eee; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.08); margin-top: 30px; }
            .res-header { background: #f9f9f9; padding: 20px; border-bottom: 1px solid #eee; }
            .res-header h3 { margin: 0; color: #333; }
            .res-header p { margin: 5px 0 0; color: #666; font-size: 14px; }
            
            .res-body { padding: 30px; }
            .school-list { list-style: none; padding: 0; margin: 0 0 20px 0; }
            .school-list li { padding: 10px 0; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between; color: #555; }
            .school-list li:last-child { border-bottom: none; }
            
            .price-box { background: #e7f6ff; color: #005a8d; padding: 20px; border-radius: 8px; text-align: center; margin-bottom: 20px; }
            .price-amount { font-size: 32px; font-weight: 800; display: block; margin-top: 5px; color: #0191FF; }

            .loader { border: 4px solid #f3f3f3; border-top: 4px solid #0191FF; border-radius: 50%; width: 30px; height: 30px; animation: spin 1s linear infinite; margin: 20px auto; display: none; }
            @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        </style>

        <div class="saps-upgrade-wrapper">
            <h2 style="margin-bottom:10px;">Upgrade Your School</h2>
            <p style="color:#666; margin-bottom:40px;">Search for your school below to view your bundle pricing and receive an invoice.</p>
            
            <div class="saps-search-box">
                <input type="text" id="saps_search_input" class="saps-search-input" placeholder="Enter School Name...">
                <div id="saps-loader" class="loader"></div>
            </div>

            <button id="saps_search_btn" class="saps-btn">Search School</button>

            <div id="saps-results-area">
                <div class="res-header">
                    <h3 id="res-author-name">Found Schools</h3>
                    <p>Associated with email: <strong id="res-email-mask"></strong></p>
                </div>
                <div class="res-body">
                    <ul class="school-list" id="res-list"></ul>
                    
                    <div class="price-box">
                        Bundle Price (Annual)
                        <span class="price-amount" id="res-price">R0.00</span>
                    </div>

                    <p style="font-size:14px; color:#888; text-align:center;">Clicking below will email an invoice to the address above.</p>
                    <button id="saps-confirm-btn" class="saps-btn" style="width:100%; background:#28a745;">✅ Confirm & Send Invoice</button>
                </div>
            </div>
            
            <div id="saps-success-msg" style="display:none; margin-top:30px; background:#d4edda; color:#155724; padding:20px; border-radius:8px;"></div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                let foundAuthorId = 0;

                // SEARCH
                $('#saps_search_btn').on('click', function() {
                    let term = $('#saps_search_input').val();
                    if(term.length < 3) { alert('Please enter at least 3 characters'); return; }

                    $('#saps-loader').show();
                    $('#saps-results-area').hide();
                    $('#saps_search_btn').prop('disabled', true);

                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: { action: 'saps_search_school', term: term },
                        success: function(res) {
                            $('#saps-loader').hide();
                            $('#saps_search_btn').prop('disabled', false);

                            if(res.success) {
                                let data = res.data;
                                foundAuthorId = data.author_id;
                                $('#res-author-name').text('Found ' + data.count + ' Listings');
                                $('#res-email-mask').text(data.masked_email);
                                $('#res-price').text(data.price_formatted);
                                
                                let listHtml = '';
                                data.listings.forEach(function(title) {
                                    listHtml += '<li><span>' + title + '</span><span>✔ Found</span></li>';
                                });
                                $('#res-list').html(listHtml);
                                
                                $('#saps-results-area').fadeIn();
                            } else {
                                alert(res.data || 'No schools found. Try searching by precise name.');
                            }
                        }
                    });
                });

                // GENERATE INVOICE
                $('#saps-confirm-btn').on('click', function() {
                    if(!foundAuthorId) return;
                    
                    $(this).prop('disabled', true).text('Generating Invoice...');
                    
                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: { action: 'saps_generate_invoice', author_id: foundAuthorId },
                        success: function(res) {
                            $('#saps-results-area').hide();
                            $('#saps-success-msg').html('<h3>Invoice Sent!</h3><p>' + res.data + '</p>').fadeIn();
                        }
                    });
                });
            });
        </script>
        <?php
        return ob_get_clean();
    }

    // --- AJAX: SEARCH SCHOOL ---
    public function ajax_search_school() {
        global $wpdb;
        $term = sanitize_text_field($_POST['term']);

        // 1. Find Author ID
        $seed_post = $wpdb->get_row( $wpdb->prepare(
            "SELECT post_author FROM {$wpdb->prefix}posts WHERE post_type='job_listing' AND post_status='publish' AND post_title LIKE %s LIMIT 1", 
            '%' . $wpdb->esc_like($term) . '%'
        ));

        $author_id = 0;
        if ($seed_post) {
            $author_id = $seed_post->post_author;
        } else {
            // Try User Email / Name
            $user = get_user_by('email', $term);
            if (!$user) {
                $user_search = $wpdb->get_row( $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->prefix}users WHERE display_name LIKE %s LIMIT 1", 
                    '%' . $wpdb->esc_like($term) . '%'
                ));
                if($user_search) $user = get_userdata($user_search->ID);
            }
            if ($user) $author_id = $user->ID;
        }

        if (!$author_id) wp_send_json_error("No schools found matching '$term'. Please try the exact School Name.");

        // 2. Get Listings
        $listings = get_posts([
            'author' => $author_id,
            'post_type' => 'job_listing',
            'posts_per_page' => -1,
            'post_status' => 'any'
        ]);

        if (empty($listings)) wp_send_json_error("Author found, but no listings attached.");

        // 3. Calculate Bundle
        $count = count($listings);
        $total_price = ($count >= 2) ? 4999 : 2499;

        // 4. Mask Email
        $user_info = get_userdata($author_id);
        $email = $user_info->user_email;
        $masked_email = substr($email, 0, 3) . '****' . substr($email, strpos($email, '@'));

        $listing_titles = [];
        foreach($listings as $l) $listing_titles[] = $l->post_title;

        wp_send_json_success([
            'author_id' => $author_id,
            'count' => $count,
            'listings' => $listing_titles,
            'price_formatted' => 'R' . number_format($total_price, 0),
            'masked_email' => $masked_email
        ]);
    }

    // --- AJAX: GENERATE INVOICE ---
    public function ajax_generate_invoice() {
        $author_id = intval($_POST['author_id']);
        if (!$author_id) wp_send_json_error("Invalid ID");

        $listings = get_posts([
            'author' => $author_id,
            'post_type' => 'job_listing',
            'posts_per_page' => -1
        ]);

        $count = count($listings);
        $total = ($count >= 2) ? 4999 : 2499;
        
        $ref_code = 'SCH-' . $author_id . '-' . date('y');

        foreach ($listings as $listing) {
            update_post_meta($listing->ID, '_payment_status', 'DUE');
            update_post_meta($listing->ID, '_renewal_reference', $ref_code);
            update_post_meta($listing->ID, '_invoice_sent_timestamp', time());
            update_post_meta($listing->ID, '_package_id', 247687);
        }

        $this->send_invoice_email($author_id, $listings, $total, $ref_code);

        wp_send_json_success("An invoice for <strong>R" . number_format($total,0) . "</strong> has been sent to the registered email address.");
    }

    // --- THE PERFECT TEMPLATE ---
    private function send_invoice_email($author_id, $listings, $amount, $ref) {
        $user = get_userdata($author_id);
        $to = $user->user_email;
        $subject = "Annual Listing Renewal: " . $listings[0]->post_title;
        
        // 1. Logo
        $custom_logo_id = get_theme_mod( 'custom_logo' );
        $logo_url = 'https://saprivateschools.co.za/wp-content/uploads/2019/07/logo.png'; 
        if ( $custom_logo_id ) {
            $image = wp_get_attachment_image_src( $custom_logo_id , 'full' );
            if(isset($image[0])) $logo_url = $image[0];
        }

        // 2. Dynamic Details
        $school_name = $listings[0]->post_title;
        $billing_company = get_user_meta($author_id, '_billing_company', true); // Added underscore
        $invoice_to_name = $billing_company ? $billing_company : $user->display_name;

        // 3. Invoice Updater Link (FIXED TO USE ?REF=)
        $update_link = home_url( '/update-invoice/?ref=' . $ref );

        // 4. Listing Rows
        $listing_rows = "";
        foreach($listings as $l) {
            $listing_rows .= "<div style='border-bottom:1px solid #eee; padding: 10px 0; color:#555;'>{$l->post_title}</div>";
        }

        // 5. The HTML
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
                            <div class='client-name'>{$invoice_to_name}</div>
                            <a href='{$update_link}' class='change-btn'>Change Details</a>
                        </div>

                        <p style='color:#555; font-size:15px; margin-bottom:25px;'>Your listing(s) on SA Private Schools are due for renewal.</p>

                        <div class='summary-box'>
                            <div class='label' style='margin-bottom:15px;'>SCHOOLS INCLUDED</div>
                            {$listing_rows}
                            
                            <div class='total-row'>
                                <span class='total-label'>Total Due:</span>
                                <span class='total-amount'>R" . number_format($amount, 2) . "</span>
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

        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail( $to, $subject, $html, $headers );
    }
}