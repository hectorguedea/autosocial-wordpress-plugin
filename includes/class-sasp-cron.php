<?php
/**
 * WP-Cron scheduling — two single-event hooks rescheduled daily.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SASP_Cron {

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public static function init(): void {
		add_action( 'sasp_post_event_1', [ __CLASS__, 'handle_event_1' ] );
		add_action( 'sasp_post_event_2', [ __CLASS__, 'handle_event_2' ] );
	}

	// ── Schedule management ───────────────────────────────────────────────────

	public static function schedule_events(): void {
		$s = get_option( 'sasp_settings', [] );

		$time1 = (string) ( $s['post_time_1'] ?? '10:00' );
		$time2 = (string) ( $s['post_time_2'] ?? '18:00' );

		if ( ! wp_next_scheduled( 'sasp_post_event_1' ) ) {
			wp_schedule_single_event( self::next_occurrence( $time1 ), 'sasp_post_event_1' );
		}

		if ( ! wp_next_scheduled( 'sasp_post_event_2' ) ) {
			wp_schedule_single_event( self::next_occurrence( $time2 ), 'sasp_post_event_2' );
		}
	}

	public static function reschedule_events(): void {
		self::clear_events();
		self::schedule_events();
	}

	public static function clear_events(): void {
		wp_clear_scheduled_hook( 'sasp_post_event_1' );
		wp_clear_scheduled_hook( 'sasp_post_event_2' );
	}

	// ── Cron callbacks ────────────────────────────────────────────────────────

	public static function handle_event_1(): void {
		$s = get_option( 'sasp_settings', [] );
		if ( ! empty( $s['enabled'] ) ) {
			self::execute_post();
		}
		// Always reschedule even if posting is off (so it fires again tomorrow).
		$time1 = (string) ( $s['post_time_1'] ?? '10:00' );
		wp_schedule_single_event( self::next_occurrence_tomorrow( $time1 ), 'sasp_post_event_1' );
	}

	public static function handle_event_2(): void {
		$s = get_option( 'sasp_settings', [] );
		if ( ! empty( $s['enabled'] ) ) {
			self::execute_post();
		}
		$time2 = (string) ( $s['post_time_2'] ?? '18:00' );
		wp_schedule_single_event( self::next_occurrence_tomorrow( $time2 ), 'sasp_post_event_2' );
	}

	// ── Core post logic ───────────────────────────────────────────────────────

	/**
	 * Select one product, build captions, post to enabled platforms, log results.
	 *
	 * Returns an array keyed by platform ('facebook', 'instagram') or a single
	 * 'error' key when no product is available.
	 *
	 * @return array<string, array{success: bool, message: string}>
	 */
	public static function execute_post(): array {
		$settings = get_option( 'sasp_settings', [] );

		$product = SASP_Products::get_eligible_product();

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

		$results     = [];
		$any_success = false;

		// ── Facebook ──────────────────────────────────────────────────────────
		if ( ! empty( $settings['enable_facebook'] ) ) {
			$caption = SASP_Products::build_caption( $product, 'facebook' );
			$result  = SASP_Meta_API::post_to_facebook( $product_id, $image_url, $caption );
			SASP_Logger::add(
				$product_id,
				$product_title,
				'facebook',
				$result['success'] ? 'success' : 'failed',
				$result['message']
			);
			$results['facebook'] = $result;
			if ( $result['success'] ) {
				$any_success = true;
			}
		}

		// ── Instagram ─────────────────────────────────────────────────────────
		if ( ! empty( $settings['enable_instagram'] ) ) {
			$caption = SASP_Products::build_caption( $product, 'instagram' );
			$result  = SASP_Meta_API::post_to_instagram( $product_id, $image_url, $caption );
			SASP_Logger::add(
				$product_id,
				$product_title,
				'instagram',
				$result['success'] ? 'success' : 'failed',
				$result['message']
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

		// Only mark as posted if at least one platform succeeded.
		if ( $any_success ) {
			SASP_Products::mark_as_posted( $product_id );
		}

		return $results;
	}

	// ── Time helpers ──────────────────────────────────────────────────────────

	/**
	 * Returns the next Unix timestamp for $time_str (HH:MM) in WP timezone.
	 * If the time has already passed today, returns tomorrow's occurrence.
	 */
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

	/** Returns tomorrow's occurrence of $time_str in WP timezone. */
	private static function next_occurrence_tomorrow( string $time_str ): int {
		[ $hour, $minute ] = array_map( 'intval', explode( ':', $time_str ) );
		$tz     = wp_timezone();
		$target = new DateTime( 'tomorrow', $tz );
		$target->setTime( $hour, $minute );
		return $target->getTimestamp();
	}
}
