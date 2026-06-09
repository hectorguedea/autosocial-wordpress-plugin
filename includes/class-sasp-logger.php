<?php
/**
 * Logging — writes to a custom DB table, keeps the last 500 entries.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SASP_Logger {

	private static string $table_name = '';

	// ── Schema ────────────────────────────────────────────────────────────────

	private static function table(): string {
		global $wpdb;
		if ( '' === self::$table_name ) {
			self::$table_name = $wpdb->prefix . 'sasp_logs';
		}
		return self::$table_name;
	}

	public static function create_table(): void {
		global $wpdb;
		$t               = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$t} (
			id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			log_time    DATETIME            NOT NULL DEFAULT '0000-00-00 00:00:00',
			product_id  BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			product_title VARCHAR(255)      NOT NULL DEFAULT '',
			platform    VARCHAR(50)         NOT NULL DEFAULT '',
			status      VARCHAR(50)         NOT NULL DEFAULT '',
			message     TEXT                NOT NULL,
			PRIMARY KEY (id),
			KEY platform (platform),
			KEY status   (status),
			KEY log_time (log_time)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	// ── Write ─────────────────────────────────────────────────────────────────

	public static function add(
		int $product_id,
		string $product_title,
		string $platform,
		string $status,
		string $message
	): void {
		global $wpdb;

		$wpdb->insert(
			self::table(),
			[
				'log_time'      => current_time( 'mysql' ),
				'product_id'    => $product_id,
				'product_title' => $product_title,
				'platform'      => $platform,
				'status'        => $status,
				'message'       => $message,
			],
			[ '%s', '%d', '%s', '%s', '%s', '%s' ]
		);

		self::prune();
	}

	private static function prune(): void {
		global $wpdb;
		$t     = self::table();
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $count > 500 ) {
			$excess = $count - 500;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} ORDER BY id ASC LIMIT %d", $excess ) );
		}
	}

	// ── Read ──────────────────────────────────────────────────────────────────

	public static function get_logs( int $limit = 100, int $offset = 0 ): array {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$t} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ),
			ARRAY_A
		) ?: [];
	}

	public static function get_count(): int {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
	}

	public static function get_last_success( string $platform = '' ): string {
		global $wpdb;
		$t = self::table();

		if ( '' !== $platform ) {
			$result = $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT log_time FROM {$t} WHERE status = 'success' AND platform = %s ORDER BY id DESC LIMIT 1",
					$platform
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$result = $wpdb->get_var( "SELECT log_time FROM {$t} WHERE status = 'success' ORDER BY id DESC LIMIT 1" );
		}

		return (string) ( $result ?? '' );
	}

	// ── Management ───────────────────────────────────────────────────────────

	public static function clear(): void {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$t}" );
	}
}
