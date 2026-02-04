<?php
/**
 * Plugin Name: NGD Standalone Invoice (Signed Link)
 * Description: Standalone invoice viewer at /invoice-view/ requiring signed link. Allows inline invoice detail edits via signed save.
 * Version: 1.1.0
 */

if (!defined('ABSPATH'))
    exit;

// Constant-time compare helper (moved to top for safety)
if (!function_exists('give_safe_compare')) {
    function give_safe_compare($a, $b)
    {
        if (function_exists('hash_equals'))
            return hash_equals($a, $b);
        if (!is_string($a) || !is_string($b))
            return false;
        if (strlen($a) !== strlen($b))
            return false;
        $res = 0;
        for ($i = 0; $i < strlen($a); $i++)
            $res |= ord($a[$i]) ^ ord($b[$i]);
        return $res === 0;
    }
}

class NGD_Standalone_Invoice_Signed
{

    // ===== Config =====
    const ROUTE_SLUG = 'invoice-view'; // /invoice-view/
    const META_RENEWAL_REF = '_renewal_reference';

    // Company details (edit to match)
    const COMPANY_NAME = 'SA Private Schools Pty Ltd';
    const COMPANY_REG = '2022 / 379336 / 07';
    const COMPANY_VAT = ''; // optional
    const SUPPORT_EMAIL = 'accounts@saprivateschools.co.za';

    // Pricing defaults (edit as needed)
    const CURRENCY_SYMBOL = 'R';
    const DEFAULT_ANNUAL_PRICE = 4999.00;
    const DEFAULT_YEARS = 1;

    // Banking details (edit)
    const BANK_ACCOUNT_NAME = 'SA Private Schools';
    const BANK_NAME = 'First National Bank';
    const BANK_ACCOUNT_TYPE = 'Cheque';
    const BANK_SWIFT = 'FIRNZAJJ';
    const BANK_ACCOUNT_NO = '63000024636';
    const BANK_BRANCH_CODE = '204-009';

    // Billing meta keys (we store these on USER meta by default)
    const UM_BILLING_CONTACT = 'billing_contact';
    const UM_BILLING_EMAIL = 'billing_email';
    const UM_BILLING_ADDRESS = 'billing_address';
    const UM_BILLING_REG = 'billing_reg';
    const UM_BILLING_VAT = 'billing_vat';

    // Option storing shared secret
    const OPT_SECRET = 'ngd_invoice_secret';

    public static function init()
    {
        add_action('init', [__CLASS__, 'register_rewrite']);
        add_filter('query_vars', [__CLASS__, 'register_query_vars']);
        add_action('template_redirect', [__CLASS__, 'handle_invoice_view']);

        // Save endpoint via admin-ajax (no login, signed)
        add_action('wp_ajax_nopriv_ngd_invoice_save', [__CLASS__, 'ajax_save_invoice_details']);
        add_action('wp_ajax_ngd_invoice_save', [__CLASS__, 'ajax_save_invoice_details']);

        // Compat Shortcode (Redirects to standalone view or shows error)
        add_shortcode('ngd_invoice_viewer', [__CLASS__, 'shortcode_invoice_viewer']);
    }

    public static function shortcode_invoice_viewer($atts = [])
    {
        // If ref/exp/sig are present, redirect to /invoice-view/?ref=...&exp=...&sig=...
        $ref = isset($_GET['ref']) ? self::sanitize_ref($_GET['ref']) : '';
        // handle both 'exp' and 'expiry' if needed, but standard is 'exp'
        $exp = isset($_GET['exp']) ? (int) $_GET['exp'] : 0;
        $sig = isset($_GET['sig']) ? self::sanitize_sig($_GET['sig']) : '';

        // Check if we have valid params
        if ($ref && $exp && $sig) {
            // Build target URL
            $url = home_url('/' . self::ROUTE_SLUG . '/') . '?ref=' . rawurlencode($ref) . '&exp=' . rawurlencode((string) $exp) . '&sig=' . rawurlencode($sig);

            // Redirect if possible
            if (!headers_sent()) {
                wp_safe_redirect($url);
                exit;
            }
            return '<script>window.location.href="' . esc_url($url) . '";</script><a href="' . esc_url($url) . '">Opening invoice...</a>';
        }

        // If missing params, show message
        return '<div style="padding:20px; text-align:center; border:1px solid #ddd; border-radius:8px; background:#f9f9f9; max-width:600px; margin:20px auto;">
            <strong>Invoice Link Required</strong><br>
            Please use the specific signed invoice link from your email.<br>
            <span style="font-size:12px; color:#666;">(Missing reference, expiry, or signature)</span>
        </div>';
    }

    public static function activate()
    {
        if (!get_option(self::OPT_SECRET)) {
            $secret = wp_generate_password(48, true, true);
            update_option(self::OPT_SECRET, $secret, false);
        }
        self::register_rewrite();
        flush_rewrite_rules();
    }

    public static function deactivate()
    {
        flush_rewrite_rules();
    }

    public static function register_rewrite()
    {
        add_rewrite_rule('^' . preg_quote(self::ROUTE_SLUG, '/') . '/?$', 'index.php?ngd_invoice_view=1', 'top');
    }

    public static function register_query_vars($vars)
    {
        $vars[] = 'ngd_invoice_view';
        return $vars;
    }

    /**
     * Helper: generate signed URL for a given invoice ref (use in email sender).
     */
    public static function generate_signed_invoice_url($ref, $ttl_seconds = 60 * 60 * 24 * 14)
    {
        $ref = self::sanitize_ref($ref);
        $exp = time() + (int) $ttl_seconds;
        $sig = self::sign($ref, $exp);

        return home_url('/' . self::ROUTE_SLUG . '/') . '?ref=' . rawurlencode($ref) . '&exp=' . rawurlencode((string) $exp) . '&sig=' . rawurlencode($sig);
    }

    // ===== Core handler =====

    public static function handle_invoice_view()
    {
        global $wp;
        $is_invoice_var = (int) get_query_var('ngd_invoice_view') === 1;

        // Robust fallback: Check request path manually
        $req = isset($wp->request) ? trim((string) $wp->request, "/") : "";
        $is_path_match = ($req === self::ROUTE_SLUG);

        // Allow if: Variable is set OR (Path Matches AND Ref exists)
        if (!$is_invoice_var && !($is_path_match && isset($_GET['ref']))) {
            return;
        }

        if (function_exists('nocache_headers'))
            nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        $ref = isset($_GET['ref']) ? self::sanitize_ref($_GET['ref']) : '';
        $exp = isset($_GET['exp']) ? (int) $_GET['exp'] : 0;
        $sig = isset($_GET['sig']) ? self::sanitize_sig($_GET['sig']) : '';

        if (!$ref || !$exp || !$sig) {
            self::render_error_page('Invalid link', 'This invoice link is missing required parameters. Please use the link provided in your email.');
        }

        if (!self::verify_signature($ref, $exp, $sig)) {
            self::render_error_page('Link expired or invalid', 'This invoice link is no longer valid. Please request a new invoice link.');
        }

        // Resolve Context (User + Representative Listing)
        $ctx = self::resolve_invoice_context($ref);
        if (!$ctx) {
            self::render_error_page('Invoice not found', 'We could not find an invoice matching this reference. Please contact support.');
        }

        $invoice = self::build_invoice_data($ctx['listing_id'], $ctx['author_id'], $ref, $exp, $sig);

        self::render_invoice_page($invoice);
    }

    // ===== Signing =====

    private static function get_secret()
    {
        $secret = get_option(self::OPT_SECRET);
        if (!$secret) {
            $secret = wp_generate_password(48, true, true);
            update_option(self::OPT_SECRET, $secret, false);
        }
        return (string) $secret;
    }

    private static function sign($ref, $exp)
    {
        $payload = $ref . '|' . (string) $exp . '|invoice-view';
        return hash_hmac('sha256', $payload, self::get_secret());
    }

    private static function verify_signature($ref, $exp, $sig)
    {
        if ($exp < time())
            return false;
        $expected = self::sign($ref, $exp);
        return give_safe_compare($expected, $sig);
    }

    // ===== Data =====

    // ===== Resolution & Pricing =====

    /**
     * Resolves a reference to an Author and a Representative Listing ID.
     * Supports:
     * 1. Canonical: SCH-{USER_ID}-{RAND}
     * 2. Legacy/Stored: _renewal_reference OR _renewal_reference_alias
     */
    private static function resolve_invoice_context($ref)
    {
        // 1. Try Regex for Canonical Ref
        if (preg_match('/^SCH-(\d+)-/', $ref, $m)) {
            $user_id = (int) $m[1];
            if ($user_id > 0) {
                // Find ANY published/draft/pending listing for this user to act as the "anchor"
                $one = get_posts([
                    'post_type' => 'job_listing',
                    'post_status' => ['publish', 'draft', 'pending', 'private', 'expired', 'future'],
                    'author' => $user_id,
                    'posts_per_page' => 1,
                    'fields' => 'ids',
                    'orderby' => 'ID',
                    'order' => 'DESC'
                ]);
                if (!empty($one)) {
                    return ['listing_id' => (int) $one[0], 'author_id' => $user_id];
                }
            }
        }

        // 2. Try Meta Lookup (Ref or Alias)
        $q = new WP_Query([
            'post_type' => 'job_listing',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'expired', 'future'],
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => [
                'relation' => 'OR',
                ['key' => self::META_RENEWAL_REF, 'value' => $ref],
                ['key' => '_renewal_reference_alias', 'value' => $ref]
            ]
        ]);

        if (!empty($q->posts)) {
            $pid = (int) $q->posts[0];
            $post = get_post($pid);
            if ($post) {
                return ['listing_id' => $pid, 'author_id' => (int) $post->post_author];
            }
        }

        return null;
    }

    /**
     * Calculates total price for Author using Dashboard logic (or fallback).
     */
    private static function calculate_author_total_price(int $user_id): float
    {
        // 1. Fetch all listings for author
        $listing_ids = get_posts([
            'post_type' => 'job_listing',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future', 'expired'],
            'author' => $user_id,
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        if (empty($listing_ids)) {
            return self::DEFAULT_ANNUAL_PRICE;
        }

        // 2. Try to use Shared Helper
        if (class_exists('\NGD_THEME\Functions\PricingHelper')) {
            $res = \NGD_THEME\Functions\PricingHelper::calculate_price_from_listing_ids($listing_ids);
            if ($res && isset($res['total'])) {
                return (float) $res['total'];
            }
        }

        // 3. Fallback: Use Local Copy of Logic
        $res = self::calc_price_local($listing_ids);
        return (float) $res['total'];
    }

    // --- Local Pricing Implementation (Fallback) ---

    private static function calc_price_local(array $listing_ids): array
    {
        $types = ['pre' => [], 'primary' => [], 'high' => []];
        foreach ($listing_ids as $id) {
            $type = self::detect_type_local($id);
            if ($type !== 'unknown') {
                $types[$type][] = $id;
            }
        }

        $campus_count = max(count($types['pre']), count($types['primary']), count($types['high']));
        $total = 0;

        for ($i = 0; $i < $campus_count; $i++) {
            $c_types = 0;
            if (!empty($types['pre'])) {
                array_shift($types['pre']);
                $c_types++;
            }
            if (!empty($types['primary'])) {
                array_shift($types['primary']);
                $c_types++;
            }
            if (!empty($types['high'])) {
                array_shift($types['high']);
                $c_types++;
            }

            $total += ($c_types >= 2) ? 4999 : 2499;
        }
        return ['total' => $total];
    }

    private static function detect_type_local($listing_id)
    {
        $terms = wp_get_object_terms($listing_id, 'job_listing_category', ['fields' => 'all']);
        if (is_wp_error($terms) || empty($terms))
            return 'unknown';

        foreach ($terms as $t) {
            $s = strtolower($t->slug);
            if (strpos($s, 'pre') !== false)
                return 'pre';
            if (strpos($s, 'primary') !== false)
                return 'primary';
            if (strpos($s, 'high') !== false || strpos($s, 'secondary') !== false)
                return 'high';
        }
        foreach ($terms as $t) {
            $n = strtolower($t->name);
            if (strpos($n, 'preschool') !== false || strpos($n, 'pre-school') !== false || strpos($n, 'pre') !== false)
                return 'pre';
            if (strpos($n, 'primary') !== false)
                return 'primary';
            if (strpos($n, 'high') !== false || strpos($n, 'secondary') !== false)
                return 'high';
        }
        return 'unknown';
    }

    private static function build_invoice_data($listing_id, $author_id, $ref, $exp, $sig)
    {
        $post = get_post($listing_id);
        // $author_id passed explicitly to ensure it matches context


        $school_name = self::first_non_empty([
            get_the_author_meta('display_name', $author_id),
            get_the_title($listing_id),
            'School'
        ]);

        $contact_person = self::first_non_empty([
            get_user_meta($author_id, self::UM_BILLING_CONTACT, true),
            get_the_author_meta('first_name', $author_id),
        ]);

        $client_email = self::first_non_empty([
            get_user_meta($author_id, self::UM_BILLING_EMAIL, true),
            get_the_author_meta('user_email', $author_id),
        ]);

        $client_address = (string) get_user_meta($author_id, self::UM_BILLING_ADDRESS, true);
        $client_reg = (string) get_user_meta($author_id, self::UM_BILLING_REG, true);
        $client_vat = (string) get_user_meta($author_id, self::UM_BILLING_VAT, true);

        $issued_ts = (int) get_post_meta($listing_id, '_invoice_sent_timestamp', true);
        $issued_ts = $issued_ts ?: time();

        $issued_date = date('Y/m/d', $issued_ts);
        $due_date = date('Y/m/d', $issued_ts + (28 * DAY_IN_SECONDS));

        $years = (int) get_post_meta($listing_id, 'invoice_years', true);
        if ($years <= 0)
            $years = self::DEFAULT_YEARS;

        $annual_cost = self::calculate_author_total_price($author_id);

        $total = $annual_cost * $years;

        return [
            'ref' => $ref,
            'exp' => $exp,
            'sig' => $sig,

            'listing_id' => $listing_id,
            'author_id' => $author_id,

            'issued_date' => $issued_date,
            'due_date' => $due_date,

            'client_name' => $school_name,
            'contact_person' => $contact_person,
            'client_reg' => $client_reg,
            'client_vat' => $client_vat,
            'client_email' => $client_email,
            'client_address' => $client_address,

            'annual_cost' => $annual_cost,
            'years' => $years,
            'total' => $total,

            'invoice_url' => self::generate_signed_invoice_url($ref, max(60, $exp - time())),
        ];
    }

    // ===== Rendering =====

    private static function render_invoice_page($inv)
    {
        $money_total = self::money($inv['total']);
        $money_annual = self::money($inv['annual_cost']);
        $money_total_cost = self::money($inv['annual_cost'] * $inv['years']);

        $invoice_ref_display = $inv['ref'];
        $ajax_url = admin_url('admin-ajax.php');

        $css = self::invoice_css();

        header('Content-Type: text/html; charset=UTF-8');

        echo '<!doctype html><html lang="en"><head>';
        echo '<meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>Tax Invoice ' . esc_html($inv['ref']) . '</title>';
        echo '<style>' . $css . '</style>';
        echo '</head><body>';

        echo '<div class="ngd-wrap">';

        // Actions
        echo '<div class="ngd-actions no-print">';
        echo '<div class="ngd-actions-left">';
        echo '<div class="ngd-pill">Reference: <strong>' . esc_html($inv['ref']) . '</strong></div>';
        echo '<div class="ngd-pill">Valid until: <strong>' . esc_html(date('Y-m-d H:i', (int) $inv['exp'])) . '</strong></div>';
        echo '</div>';

        echo '<div class="ngd-actions-right">';
        echo '<button class="btn" id="ngdEditBtn">Edit invoice details</button>';
        echo '<button class="btn" id="ngdSaveBtn" style="display:none;">Save changes</button>';
        echo '<button class="btn primary" onclick="window.print()">Download / Print</button>';
        echo '</div>';
        echo '</div>';

        // Invoice sheet
        echo '<div class="sheet" id="invoice">';
        echo '  <div class="topbar">';
        echo '    <div class="title">Tax Invoice</div>';
        echo '    <div class="companybox">';
        echo '      <div>' . esc_html(self::COMPANY_NAME) . '</div>';
        echo '      <div>' . esc_html(self::COMPANY_REG) . '</div>';
        if (!empty(self::COMPANY_VAT))
            echo '      <div>VAT: ' . esc_html(self::COMPANY_VAT) . '</div>';
        echo '    </div>';
        echo '  </div>';

        echo '  <div class="refrow">';
        echo '    <div><strong>' . esc_html(self::COMPANY_NAME) . '</strong> — ' . esc_html(self::COMPANY_REG) . '</div>';
        echo '    <div><strong>Reference:</strong> ' . esc_html($invoice_ref_display) . '</div>';
        echo '  </div>';

        echo '  <div class="info">';
        echo '    <div class="box">';
        echo '      <div class="row"><span class="k">School Name:</span><span class="v">' . esc_html($inv['client_name']) . '</span></div>';
        echo '      <div class="row"><span class="k">Contact person:</span><span class="v editable" data-key="contact_person" contenteditable="false">' . esc_html($inv['contact_person']) . '</span></div>';
        echo '      <div class="row"><span class="k">School Reg No:</span><span class="v editable" data-key="client_reg" contenteditable="false">' . esc_html($inv['client_reg']) . '</span></div>';
        echo '      <div class="row"><span class="k">VAT Number:</span><span class="v editable" data-key="client_vat" contenteditable="false">' . esc_html($inv['client_vat']) . '</span></div>';
        echo '    </div>';

        echo '    <div class="box">';
        echo '      <div class="row"><span class="k">Date:</span><span class="v">' . esc_html($inv['issued_date']) . '</span></div>';
        echo '      <div class="row"><span class="k">Email:</span><span class="v editable" data-key="client_email" contenteditable="false">' . esc_html($inv['client_email']) . '</span></div>';
        echo '      <div class="row"><span class="k">Address:</span><span class="v editable editable-multiline" data-key="client_address" contenteditable="false">' . esc_html($inv['client_address']) . '</span></div>';
        echo '    </div>';
        echo '  </div>';

        echo '  <div class="table">';
        echo '    <div class="thead">';
        echo '      <div>Description of Services</div><div class="r">Annual Cost</div><div class="r">Years</div><div class="r">Total Cost</div><div class="r">Cost</div>';
        echo '    </div>';

        echo '    <div class="trow">';
        echo '      <div>';
        echo '        <div class="service">Premium Listing</div>';
        echo '        <div class="subhead">Benefits</div>';
        echo '        <ul class="list">';
        echo '          <li>No delay for enquiries</li>';
        echo '          <li>Featured banner</li>';
        echo '          <li>Top of search results</li>';
        echo '          <li>Wider catchment for enquiries</li>';
        echo '          <li>Enquiry log downloads</li>';
        echo '          <li>Priority support</li>';
        echo '          <li>Auto-responder</li>';
        echo '        </ul>';
        echo '        <div class="subhead">Features</div>';
        echo '        <ul class="list">';
        echo '          <li>Gallery images</li>';
        echo '          <li>Price range</li>';
        echo '          <li>Google map</li>';
        echo '          <li>Personalised FAQs</li>';
        echo '          <li>Contact details</li>';
        echo '          <li>Website URL</li>';
        echo '          <li>Enquiry form</li>';
        echo '          <li>Social media links</li>';
        echo '          <li>School slogan</li>';
        echo '          <li>Introduction video</li>';
        echo '          <li>Announcements</li>';
        echo '        </ul>';
        echo '      </div>';

        echo '      <div class="r">' . esc_html($money_annual) . '</div>';
        echo '      <div class="r">' . (int) $inv['years'] . '</div>';
        echo '      <div class="r">' . esc_html($money_total_cost) . '</div>';
        echo '      <div class="r"><strong>' . esc_html($money_total) . '</strong></div>';
        echo '    </div>';

        echo '    <div class="totalrow">';
        echo '      <div class="total-label">Total Invoiced amount</div>';
        echo '      <div class="total-val">' . esc_html($money_total) . '</div>';
        echo '    </div>';
        echo '  </div>';

        echo '  <div class="bottom">';
        echo '    <div class="box">';
        echo '      <div class="bt">Banking Details</div>';
        echo '      <div class="row"><span class="k">Account Name:</span><span class="v">' . esc_html(self::BANK_ACCOUNT_NAME) . '</span></div>';
        echo '      <div class="row"><span class="k">Bank:</span><span class="v">' . esc_html(self::BANK_NAME) . '</span></div>';
        echo '      <div class="row"><span class="k">Account Type:</span><span class="v">' . esc_html(self::BANK_ACCOUNT_TYPE) . '</span></div>';
        echo '      <div class="row"><span class="k">SWIFT Code:</span><span class="v">' . esc_html(self::BANK_SWIFT) . '</span></div>';
        echo '      <div class="row"><span class="k">Account Number:</span><span class="v">' . esc_html(self::BANK_ACCOUNT_NO) . '</span></div>';
        echo '      <div class="row"><span class="k">Branch Code:</span><span class="v">' . esc_html(self::BANK_BRANCH_CODE) . '</span></div>';
        echo '    </div>';

        echo '    <div class="box">';
        echo '      <div class="bt">Payment details</div>';
        echo '      <div class="row"><span class="k">Payment REF:</span><span class="v">' . esc_html($inv['ref']) . '</span></div>';
        echo '      <div class="row"><span class="k">Amount due (100%):</span><span class="v">' . esc_html($money_total) . '</span></div>';
        echo '      <div class="row"><span class="k">Send POP to:</span><span class="v">' . esc_html(self::SUPPORT_EMAIL) . '</span></div>';
        echo '      <div class="row"><span class="k">Payment due by:</span><span class="v"><strong>' . esc_html($inv['due_date']) . '</strong></span></div>';
        echo '    </div>';
        echo '  </div>';

        echo '  <div class="foot">If you need help, email ' . esc_html(self::SUPPORT_EMAIL) . '.</div>';
        echo '</div>'; // sheet

        // JS: toggle edit mode + save
        echo '<script>
            (function(){
              var editBtn = document.getElementById("ngdEditBtn");
              var saveBtn = document.getElementById("ngdSaveBtn");
              var editableEls = Array.prototype.slice.call(document.querySelectorAll(".editable"));
              var isEditing = false;

              function setEditing(on){
                isEditing = !!on;
                editableEls.forEach(function(el){
                  el.setAttribute("contenteditable", on ? "true" : "false");
                  el.classList.toggle("is-editing", on);
                });
                if(on){
                  editBtn.style.display = "none";
                  saveBtn.style.display = "inline-block";
                  // focus first field
                  if(editableEls[0]) editableEls[0].focus();
                } else {
                  editBtn.style.display = "inline-block";
                  saveBtn.style.display = "none";
                }
              }

              function val(key){
                var el = document.querySelector("[data-key=\'"+key+"\']");
                if(!el) return "";
                return (el.innerText || "").trim();
              }

              function post(data){
                return fetch(' . json_encode($ajax_url) . ', {
                  method: "POST",
                  headers: {"Content-Type": "application/x-www-form-urlencoded"},
                  body: new URLSearchParams(data).toString()
                }).then(function(r){ return r.json(); });
              }

              editBtn.addEventListener("click", function(){ setEditing(true); });

              saveBtn.addEventListener("click", function(){
                saveBtn.disabled = true;
                saveBtn.innerText = "Saving...";
                post({
                  action: "ngd_invoice_save",
                  ref: ' . json_encode($inv['ref']) . ',
                  exp: ' . json_encode((string) $inv['exp']) . ',
                  sig: ' . json_encode($inv['sig']) . ',
                  contact_person: val("contact_person"),
                  client_email: val("client_email"),
                  client_address: val("client_address"),
                  client_reg: val("client_reg"),
                  client_vat: val("client_vat")
                }).then(function(resp){
                  if(resp && resp.success){
                    saveBtn.innerText = "Saved ✅";
                    setTimeout(function(){
                      saveBtn.disabled = false;
                      saveBtn.innerText = "Save changes";
                      setEditing(false);
                    }, 700);
                  } else {
                    alert((resp && resp.data && resp.data.message) ? resp.data.message : "Save failed");
                    saveBtn.innerText = "Save changes";
                    saveBtn.disabled = false;
                  }
                }).catch(function(){
                  alert("Save failed");
                  saveBtn.innerText = "Save changes";
                  saveBtn.disabled = false;
                });
              });

              // Start in view mode
              setEditing(false);
            })();
        </script>';

        echo '</div></body></html>';
        exit;
    }

    private static function render_error_page($title, $message)
    {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . esc_html($title) . '</title>';
        echo '<style>
            body{font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;background:#f5f7fb;margin:0;padding:40px;}
            .card{max-width:760px;margin:0 auto;background:#fff;border:1px solid #d7dbe2;border-radius:14px;padding:22px;box-shadow:0 10px 30px rgba(0,0,0,.07);}
            h1{margin:0 0 8px 0;font-size:24px;}
            p{margin:0;color:#333;line-height:1.5;}
        </style></head><body><div class="card"><h1>' . esc_html($title) . '</h1><p>' . esc_html($message) . '</p></div></body></html>';
        exit;
    }

    // ===== Save handler =====

    public static function ajax_save_invoice_details()
    {
        $ref = isset($_POST['ref']) ? self::sanitize_ref($_POST['ref']) : '';
        $exp = isset($_POST['exp']) ? (int) $_POST['exp'] : 0;
        $sig = isset($_POST['sig']) ? self::sanitize_sig($_POST['sig']) : '';

        if (!$ref || !$exp || !$sig) {
            wp_send_json_error(['message' => 'Missing parameters.'], 400);
        }
        if (!self::verify_signature($ref, $exp, $sig)) {
            wp_send_json_error(['message' => 'Link expired or invalid. Please request a new link.'], 403);
        }

        $ctx = self::resolve_invoice_context($ref);
        if (!$ctx) {
            wp_send_json_error(['message' => 'Invoice not found.'], 404);
        }
        $listing_id = $ctx['listing_id'];
        $author_id = $ctx['author_id'];
        if (!$listing_id) {
            wp_send_json_error(['message' => 'Invoice not found.'], 404);
        }



        $contact = isset($_POST['contact_person']) ? sanitize_text_field(wp_unslash($_POST['contact_person'])) : '';
        $email = isset($_POST['client_email']) ? sanitize_email(wp_unslash($_POST['client_email'])) : '';
        $addr = isset($_POST['client_address']) ? sanitize_textarea_field(wp_unslash($_POST['client_address'])) : '';
        $reg = isset($_POST['client_reg']) ? sanitize_text_field(wp_unslash($_POST['client_reg'])) : '';
        $vat = isset($_POST['client_vat']) ? sanitize_text_field(wp_unslash($_POST['client_vat'])) : '';

        update_user_meta($author_id, self::UM_BILLING_CONTACT, $contact);
        update_user_meta($author_id, self::UM_BILLING_EMAIL, $email);
        update_user_meta($author_id, self::UM_BILLING_ADDRESS, $addr);
        update_user_meta($author_id, self::UM_BILLING_REG, $reg);
        update_user_meta($author_id, self::UM_BILLING_VAT, $vat);

        // mirror to listing meta (optional compatibility)
        update_post_meta($listing_id, 'billing_contact', $contact);
        update_post_meta($listing_id, 'billing_email', $email);
        update_post_meta($listing_id, 'billing_address', $addr);
        update_post_meta($listing_id, 'billing_reg', $reg);
        update_post_meta($listing_id, 'billing_vat', $vat);

        wp_send_json_success(['message' => 'Saved']);
    }

    // ===== Utils =====

    private static function sanitize_ref($raw)
    {
        $raw = trim((string) $raw);
        $raw = preg_replace('/[^A-Za-z0-9\-_]/', '', $raw);
        return substr($raw, 0, 64);
    }

    private static function sanitize_sig($raw)
    {
        $raw = trim((string) $raw);
        $raw = preg_replace('/[^a-fA-F0-9]/', '', $raw);
        return substr($raw, 0, 64);
    }

    private static function first_non_empty($arr)
    {
        foreach ($arr as $v) {
            $v = is_string($v) ? trim($v) : $v;
            if (!empty($v))
                return $v;
        }
        return '';
    }

    private static function money($amount)
    {
        return self::CURRENCY_SYMBOL . ' ' . number_format((float) $amount, 2, ',', ' ');
    }

    private static function invoice_css()
    {
        return <<<CSS
:root{--blue:#0b66ff;--border:#cfd6e1;--muted:#f7f9fc;}
*{box-sizing:border-box}
body{margin:0;background:#eef2f8;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;color:#111;}
.ngd-wrap{max-width:980px;margin:28px auto;padding:0 14px;}
.ngd-actions{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:12px;flex-wrap:wrap;}
.ngd-actions-left{display:flex;gap:10px;flex-wrap:wrap;}
.ngd-pill{background:#fff;border:1px solid var(--border);border-radius:999px;padding:8px 12px;font-size:13px;box-shadow:0 6px 18px rgba(0,0,0,.05)}
.btn{padding:10px 14px;border-radius:12px;border:1px solid var(--border);background:#fff;font-weight:700;cursor:pointer}
.btn.primary{background:#2563eb;border-color:#2563eb;color:#fff}
.sheet{background:#fff;border:1px solid var(--border);box-shadow:0 10px 30px rgba(0,0,0,.08)}
.topbar{background:var(--blue);color:#fff;padding:26px 22px;display:flex;justify-content:space-between;gap:16px;align-items:flex-start}
.title{font-size:44px;font-weight:900;letter-spacing:.3px;line-height:1}
.companybox{font-size:12px;line-height:1.5;text-align:right;opacity:.95}
.refrow{display:flex;justify-content:space-between;gap:14px;padding:10px 16px;border-top:4px solid var(--blue);border-bottom:1px solid var(--border);font-size:12px;background:var(--muted)}
.info{display:grid;grid-template-columns:1fr 1fr;gap:14px;padding:14px 16px;}
.box{border:1px solid var(--border);padding:12px;background:#fff}
.row{display:flex;gap:10px;margin:6px 0;align-items:flex-start}
.row .k{width:140px;font-weight:800;color:#222}
.row .v{flex:1;white-space:pre-wrap}
.table{margin:0 16px 16px 16px;border:1px solid var(--border)}
.thead{display:grid;grid-template-columns:1.6fr .6fr .3fr .6fr .4fr;background:#f1f5fb;border-bottom:1px solid var(--border);padding:10px;font-weight:900;font-size:12px}
.trow{display:grid;grid-template-columns:1.6fr .6fr .3fr .6fr .4fr;border-bottom:1px solid var(--border);padding:10px;font-size:12px}
.service{font-size:18px;font-weight:900;margin-bottom:10px}
.subhead{font-weight:900;margin:10px 0 6px}
.list{margin:0;padding-left:18px;line-height:1.55}
.list li{margin:0 0 4px}
.r{text-align:right}
.totalrow{display:flex;justify-content:flex-end;gap:14px;padding:12px 10px;align-items:center}
.total-label{font-weight:900}
.total-val{font-weight:900;font-size:16px}
.bottom{display:grid;grid-template-columns:1fr 1fr;gap:14px;padding:0 16px 16px}
.bt{font-weight:900;margin-bottom:10px}
.foot{padding:10px 16px;border-top:1px solid var(--border);background:#fbfcff;font-size:12px;color:#444}

/* Editing cues */
.editable{border-radius:10px;padding:6px 8px;min-height:20px}
.editable.is-editing{
  border:2px dashed rgba(11,102,255,.55);
  background:#f6faff;
}
.editable.is-editing:focus{
  outline:none;
  border-style:solid;
  box-shadow:0 0 0 3px rgba(11,102,255,.18);
}

@media(max-width:760px){.info,.bottom{grid-template-columns:1fr}.thead,.trow{grid-template-columns:1fr}.thead{display:none}.r{text-align:left}}

/* ===== PRINT ===== */
@page{size:A4;margin:12mm}
@media print{
  body{background:#fff}
  .no-print{display:none !important}
  .ngd-wrap{max-width:100%;margin:0;padding:0}
  .sheet{border:none;box-shadow:none}
  .topbar{print-color-adjust:exact;-webkit-print-color-adjust:exact}
  .box,.table{break-inside:avoid-page;page-break-inside:avoid}
  .trow{break-inside:avoid-page;page-break-inside:avoid}
  .list{break-inside:avoid-page;page-break-inside:avoid}
  .editable,.editable.is-editing{border:none !important;background:transparent !important;box-shadow:none !important;padding:0 !important}
}
CSS;
    }
}

NGD_Standalone_Invoice_Signed::init();

register_activation_hook(__FILE__, ['NGD_Standalone_Invoice_Signed', 'activate']);
register_deactivation_hook(__FILE__, ['NGD_Standalone_Invoice_Signed', 'deactivate']);
