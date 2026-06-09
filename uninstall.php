<?php
/**
 * Runs when an admin fully deletes the plugin (Plugins → Delete).
 * Removes all plugin data: options, token timestamps, posted history, logs table, cron events.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit; // Must only run via WordPress uninstall flow.
}

global $wpdb;

// ── Options ──────────────────────────────────────────────────────────────────
$options = [
	'sasp_settings',
	'sasp_fb_token_set_at',
	'sasp_ig_token_set_at',
	'sasp_posted_product_ids',
];

foreach ( $options as $option ) {
	delete_option( $option );
	// Also clean up site options in multisite.
	delete_site_option( $option );
}

// ── Logs table ────────────────────────────────────────────────────────────────
$table = $wpdb->prefix . 'sasp_logs';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );

// ── Cron events ───────────────────────────────────────────────────────────────
wp_clear_scheduled_hook( 'sasp_post_event_1' );
wp_clear_scheduled_hook( 'sasp_post_event_2' );
