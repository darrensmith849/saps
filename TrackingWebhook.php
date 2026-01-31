<?php
namespace NGD_THEME\Functions;
if ( ! defined( 'ABSPATH' ) ) exit;
use WP_REST_Request;

class TrackingWebhook {
    public function __construct( $run_hooks = false ) {
        if ( $run_hooks ) {
            $this->run_hooks();
        }
    }

    public function run_hooks(): void {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes() {
        register_rest_route( 'ngd/v1', '/track_open', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'track' ],
            'permission_callback' => '__return_true'
        ]);
    }

    public function track( WP_REST_Request $req ) {
        $ref = sanitize_text_field( $req->get_param( 'ref' ) );

        if ( $ref ) {
            $listings = get_posts([
                'post_type'      => 'job_listing',
                'meta_key'       => '_renewal_reference',
                'meta_value'     => $ref,
                'posts_per_page' => -1
            ]);

            foreach ( $listings as $listing ) {
                update_post_meta( $listing->ID, '_invoice_seen_status', 'yes' );
                update_post_meta( $listing->ID, '_invoice_last_seen', current_time( 'mysql' ) );
            }
        }

        header( 'Content-Type: image/gif' );
        echo base64_decode( 'R0lGODlhAQABAJAAAP8AAAAAACH5BAUQAAAALAAAAAABAAEAAAICBAEAOw==' );
        exit;
    }
}