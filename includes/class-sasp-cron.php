<?php
/**
 * WP-Cron scheduling — dynamic number of daily post slots.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SASP_Cron {

	const MAX_SLOTS = 20;

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public static function init(): void {
		add_action( 'sasp_post_event', [ __CLASS__, 'handle_event' ] );
	}

	// ── Schedule management ───────────────────────────────────────────────────

	public static function schedule_events(): void {
		$times = self::get_post_times();
		foreach ( $times as $index => $time ) {
			if ( ! wp_next_scheduled( 'sasp_post_event', [ $index ] ) ) {
				wp_schedule_single_event(
					self::next_occurrence( $time ),
					'sasp_post_event',
					[ $index ]
				);
			}
		}
	}

	public static function reschedule_events(): void {
		self::clear_events();
		self::schedule_events();
	}

	public static function clear_events(): void {
		for ( $i = 0; $i < self::MAX_SLOTS; $i++ ) {
			wp_clear_scheduled_hook( 'sasp_post_event', [ $i ] );
		}
		// Also clear old-style events from v1.0.0 for migration.
		wp_clear_scheduled_hook( 'sasp_post_event_1' );
		wp_clear_scheduled_hook( 'sasp_post_event_2' );
	}

	// ── Cron callbacks ────────────────────────────────────────────────────────

	public static function handle_event( int $slot_index ): void {
		$s = get_option( 'sasp_settings', [] );
		if ( ! empty( $s['enabled'] ) ) {
			self::execute_post();
		}
		// Always reschedule for tomorrow even when posting is off.
		$times = self::get_post_times();
		$time  = $times[ $slot_index ] ?? '10:00';
		wp_schedule_single_event(
			self::next_occurrence_tomorrow( $time ),
			'sasp_post_event',
			[ $slot_index ]
		);
	}

	// ── Core post logic ───────────────────────────────────────────────────────

	/**
	 * Post a specific product to one platform (used by the Logs "Retry" button).
	 *
	 * @return array{success: bool, message: string}
	 */
	public static function execute_post_for_product( int $product_id, string $platform ): array {
		if ( ! in_array( $platform, [ 'facebook', 'instagram' ], true ) ) {
			return [ 'success' => false, 'message' => "Unknown platform: {$platform}" ];
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return [ 'success' => false, 'message' => "Product not found (ID: {$product_id})." ];
		}

		$product_title = $product->get_name();
		$image_url     = (string) get_the_post_thumbnail_url( $product_id, 'full' );

		if ( '' === $image_url || ! str_starts_with( $image_url, 'https://' ) ) {
			return [ 'success' => false, 'message' => 'Product image is missing or not accessible via HTTPS.' ];
		}

		$image_url = self::encode_image_url( $image_url );

		$ext = strtolower( pathinfo( strtok( $image_url, '?' ), PATHINFO_EXTENSION ) );
		if ( in_array( $ext, [ 'webp', 'gif', 'svg', 'avif', 'bmp', 'tiff', 'tif' ], true ) ) {
			return [ 'success' => false, 'message' => "Image is {$ext} format. Meta requires JPEG or PNG." ];
		}

		$caption = SASP_Products::build_caption( $product, $platform );

		if ( 'facebook' === $platform ) {
			$result = SASP_Meta_API::post_to_facebook( $product_id, $image_url, $caption );
		} else {
			$result = SASP_Meta_API::post_to_instagram( $product_id, $image_url, $caption );
		}

		SASP_Logger::add(
			$product_id,
			$product_title,
			$platform,
			$result['success'] ? 'success' : 'failed',
			'[Retry] ' . $result['message']
		);

		return $result;
	}

	/**
	 * @return array<string, array{success: bool, message: string}>
	 */
	public static function execute_post(): array {
		$settings = get_option( 'sasp_settings', [] );
		$product  = SASP_Products::get_eligible_product();

		if ( null === $product ) {
			$msg = 'No eligible product found (no published, in-stock products with an HTTPS image).';
			SASP_Logger::add( 0, '', 'system', 'skipped', $msg );
			return [ 'error' => [ 'success' => false, 'message' => $msg ] ];
		}

		$product_id    = $product->get_id();
		$product_title = $product->get_name();
		$image_url     = (string) get_the_post_thumbnail_url( $product_id, 'full' );

		if ( '' === $image_url || ! str_starts_with( $image_url, 'https://' ) ) {
			$msg = 'Skipped: product image is missing or not publicly accessible via HTTPS.';
			SASP_Logger::add( $product_id, $product_title, 'system', 'skipped', $msg );
			return [ 'error' => [ 'success' => false, 'message' => $msg ] ];
		}

		// Percent-encode any non-ASCII characters in the filename (e.g. Unicode ellipsis)
		// so Meta's servers can fetch the image without a 400 error.
		$image_url = self::encode_image_url( $image_url );

		// Meta API only accepts JPEG and PNG. Detect unsupported formats via URL extension
		// and via a HEAD request so we skip before burning an API call.
		$ext = strtolower( pathinfo( strtok( $image_url, '?' ), PATHINFO_EXTENSION ) );
		if ( in_array( $ext, [ 'webp', 'gif', 'svg', 'avif', 'bmp', 'tiff', 'tif' ], true ) ) {
			$msg = "Skipped: product image is {$ext} format. Meta API requires JPEG or PNG. Convert the featured image and retry.";
			SASP_Logger::add( $product_id, $product_title, 'system', 'skipped', $msg );
			SASP_Products::mark_as_posted( $product_id ); // skip this product so rotation advances
			return [ 'error' => [ 'success' => false, 'message' => $msg ] ];
		}

		$results     = [];
		$any_success = false;

		if ( ! empty( $settings['enable_facebook'] ) ) {
			$caption = SASP_Products::build_caption( $product, 'facebook' );
			$result  = SASP_Meta_API::post_to_facebook( $product_id, $image_url, $caption );
			SASP_Logger::add(
				$product_id, $product_title, 'facebook',
				$result['success'] ? 'success' : 'failed', $result['message']
			);
			$results['facebook'] = $result;
			if ( $result['success'] ) {
				$any_success = true;
			}
		}

		if ( ! empty( $settings['enable_instagram'] ) ) {
			$caption = SASP_Products::build_caption( $product, 'instagram' );
			$result  = SASP_Meta_API::post_to_instagram( $product_id, $image_url, $caption );
			SASP_Logger::add(
				$product_id, $product_title, 'instagram',
				$result['success'] ? 'success' : 'failed', $result['message']
			);
			$results['instagram'] = $result;
			if ( $result['success'] ) {
				$any_success = true;
			}
		}

		if ( empty( $results ) ) {
			$msg = 'No platforms are enabled. Enable Facebook and/or Instagram in settings.';
			SASP_Logger::add( $product_id, $product_title, 'system', 'skipped', $msg );
			return [ 'error' => [ 'success' => false, 'message' => $msg ] ];
		}

		if ( $any_success ) {
			SASP_Products::mark_as_posted( $product_id );
		}

		return $results;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Ensure every path segment in a URL is properly percent-encoded.
	 * Handles filenames with Unicode characters (e.g. ellipsis …, accented chars)
	 * that Instagram's servers cannot fetch when passed raw or double-encoded.
	 */
	private static function encode_image_url( string $url ): string {
		$parts = parse_url( $url );
		if ( empty( $parts['host'] ) || empty( $parts['path'] ) ) {
			return $url;
		}
		// Decode first (handles already-encoded URLs), then re-encode each segment.
		$segments     = explode( '/', rawurldecode( $parts['path'] ) );
		$encoded_path = implode( '/', array_map( 'rawurlencode', $segments ) );
		$query        = ! empty( $parts['query'] ) ? '?' . $parts['query'] : '';
		return $parts['scheme'] . '://' . $parts['host'] . $encoded_path . $query;
	}

	/**
	 * Returns the array of configured post times.
	 * Migrates automatically from the v1.0.0 post_time_1/post_time_2 format.
	 */
	public static function get_post_times(): array {
		$s = get_option( 'sasp_settings', [] );

		if ( ! empty( $s['post_times'] ) && is_array( $s['post_times'] ) ) {
			return $s['post_times'];
		}

		// Migrate from v1.0.0 two-slot format.
		$times = [];
		if ( ! empty( $s['post_time_1'] ) ) {
			$times[] = $s['post_time_1'];
		}
		if ( ! empty( $s['post_time_2'] ) ) {
			$times[] = $s['post_time_2'];
		}
		return $times ?: [ '10:00', '18:00' ];
	}

	private static function next_occurrence( string $time_str ): int {
		[ $hour, $minute ] = array_map( 'intval', explode( ':', $time_str ) );
		$tz     = wp_timezone();
		$now    = new DateTime( 'now', $tz );
		$target = new DateTime( 'today', $tz );
		$target->setTime( $hour, $minute );
		if ( $target <= $now ) {
			$target->modify( '+1 day' );
		}
		return $target->getTimestamp();
	}

	private static function next_occurrence_tomorrow( string $time_str ): int {
		[ $hour, $minute ] = array_map( 'intval', explode( ':', $time_str ) );
		$tz     = wp_timezone();
		$target = new DateTime( 'tomorrow', $tz );
		$target->setTime( $hour, $minute );
		return $target->getTimestamp();
	}
}
