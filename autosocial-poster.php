<?php
/**
 * Plugin Name:       AutoSocial Poster
 * Plugin URI:        https://hectorguedea.com
 * Description:       Automatically publishes your WooCommerce products to Facebook Page and Instagram Business via Meta Graph API. Schedule as many daily posts as you want, customize captions and hashtags, filter by category, and track every post from the built-in log. Includes a step-by-step setup guide for getting your Meta tokens.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Héctor Guedea
 * Author URI:        https://hectorguedea.com
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sasp
 * Domain Path:       /languages
 *
 * WC requires at least: 7.0
 * WC tested up to:      8.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SASP_VERSION', '1.2.0' );
define( 'SASP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SASP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SASP_PLUGIN_FILE', __FILE__ );

// ── Activation ──────────────────────────────────────────────────────────────
register_activation_hook( __FILE__, 'sasp_activate' );
function sasp_activate(): void {
	require_once SASP_PLUGIN_DIR . 'includes/class-sasp-logger.php';
	require_once SASP_PLUGIN_DIR . 'includes/class-sasp-cron.php';
	SASP_Logger::create_table();
	SASP_Cron::schedule_events();
	set_transient( 'sasp_setup_redirect', 1, 60 );
}

// ── Deactivation ─────────────────────────────────────────────────────────────
register_deactivation_hook( __FILE__, 'sasp_deactivate' );
function sasp_deactivate(): void {
	require_once SASP_PLUGIN_DIR . 'includes/class-sasp-cron.php';
	SASP_Cron::clear_events();
}

// ── Boot ─────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', 'sasp_init' );
function sasp_init(): void {
	load_plugin_textdomain( 'sasp', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', static function () {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'AutoSocial Poster requires WooCommerce to be installed and active.', 'sasp' )
				. '</p></div>';
		} );
		return;
	}

	require_once SASP_PLUGIN_DIR . 'includes/class-sasp-crypto.php';
	require_once SASP_PLUGIN_DIR . 'includes/class-sasp-logger.php';
	require_once SASP_PLUGIN_DIR . 'includes/class-sasp-products.php';
	require_once SASP_PLUGIN_DIR . 'includes/class-sasp-meta-api.php';
	require_once SASP_PLUGIN_DIR . 'includes/class-sasp-cron.php';
	require_once SASP_PLUGIN_DIR . 'includes/class-sasp-admin.php';

	SASP_Admin::init();
	SASP_Cron::init();
}

// ── WooCommerce HPOS compatibility ────────────────────────────────────────────
add_action( 'before_woocommerce_init', static function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
	}
} );
