<?php

namespace NGD_THEME;

if ( ! defined( 'ABSPATH' ) ) exit;

use NGD_THEME\Front\Front;
use NGD_THEME\Admin\Admin;
use NGD_THEME\Admin\DashboardColumns;
use NGD_THEME\Admin\CronDebugHelper;
use NGD_THEME\Functions\WooCommerce;
use NGD_THEME\Functions\EmailConfiguration;
use NGD_THEME\Functions\RenewalCron;
use NGD_THEME\Functions\PaymentWebhook;
use NGD_THEME\Functions\InvoiceUpdater;
use NGD_THEME\Functions\TrackingWebhook;
use NGD_THEME\Functions\PricingPage;
use NGD_THEME\Functions\SchoolResources;
use NGD_THEME\Functions\UpgradePortal;

class App {

	public function __construct( $run_hooks = false ) {
		if ( $run_hooks ) {
			$this->run_hooks();

			// Core theme components
			new Front( $run_hooks );

			if ( class_exists( 'WC_Product' ) ) {
				new WooCommerce( $run_hooks );
			}

			if ( is_admin() ) {
				new Admin( $run_hooks );
				new DashboardColumns( $run_hooks );
				new CronDebugHelper( $run_hooks );
			}

			// Email Configuration
			new EmailConfiguration( $run_hooks );

			// Automation Engine
			new RenewalCron( $run_hooks );
			new PaymentWebhook( $run_hooks );
			new InvoiceUpdater( $run_hooks );
			new TrackingWebhook( $run_hooks );

			// Shortcodes & Portals
			new PricingPage( $run_hooks );
			new SchoolResources( $run_hooks );
			new UpgradePortal( $run_hooks );
		}
	}

	public function run_hooks(): void {
		add_filter('mylisting/expiring-listings/days-notice', function( $days ) {
			return 14;
		});
	}
}