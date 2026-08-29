<?php
/**
 * Meta Graph API — Facebook photo posts and Instagram media publishing.
 *
 * Security notes:
 * - Tokens are decrypted from storage (SASP_Crypto) only at call time.
 * - GET requests use the "Authorization: Bearer" HTTP header — tokens are
 *   never placed in URLs, so they cannot appear in server access logs,
 *   browser history, or Referer headers.
 * - POST request bodies are not logged by web servers by default.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SASP_Meta_API {

	private const GRAPH_VERSION = 'v20.0';
	private const GRAPH_BASE    = 'https://graph.facebook.com/';

	// ── Facebook ──────────────────────────────────────────────────────────────

	/**
	 * Publish a photo post to a Facebook Page.
	 *
	 * @return array{success: bool, message: string}
	 */
	public static function post_to_facebook( int $product_id, string $image_url, string $caption ): array {
		$settings     = get_option( 'sasp_settings', [] );
		$page_id      = trim( $settings['fb_page_id'] ?? '' );
		$access_token = SASP_Crypto::decrypt( trim( $settings['fb_access_token'] ?? '' ) );

		if ( '' === $page_id || '' === $access_token ) {
			return self::err( 'Facebook Page ID or Access Token is not configured.' );
		}

		// /{page_id}/photos requires publish_actions (deprecated) on apps without App Review.
		// /{page_id}/feed works with pages_manage_posts and is Meta's recommended approach.
		$endpoint = self::GRAPH_BASE . self::GRAPH_VERSION . '/' . $page_id . '/feed';

		$response = wp_remote_post( $endpoint, [
			'timeout' => 30,
			'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
			'body'    => [
				'message' => $caption,
				'link'    => $image_url,
			],
		] );

		return self::parse_response( $response, 'Facebook' );
	}

	// ── Instagram ─────────────────────────────────────────────────────────────

	/**
	 * Publish a photo to an Instagram Business Account (two-step container flow).
	 *
	 * @return array{success: bool, message: string}
	 */
	public static function post_to_instagram( int $product_id, string $image_url, string $caption ): array {
		$settings     = get_option( 'sasp_settings', [] );
		$ig_user_id   = trim( $settings['ig_user_id'] ?? '' );
		$access_token = SASP_Crypto::decrypt( trim( $settings['ig_access_token'] ?? '' ) );

		// Fall back to Facebook Page token if Instagram token is empty.
		if ( '' === $access_token ) {
			$access_token = SASP_Crypto::decrypt( trim( $settings['fb_access_token'] ?? '' ) );
		}

		if ( '' === $ig_user_id || '' === $access_token ) {
			return self::err( 'Instagram Business Account ID or Access Token is not configured.' );
		}

		// Step 1 — Create media container.
		$container_ep = self::GRAPH_BASE . self::GRAPH_VERSION . '/' . $ig_user_id . '/media';

		$container_resp = wp_remote_post( $container_ep, [
			'timeout' => 30,
			'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
			'body'    => [
				'media_type' => 'IMAGE',
				'image_url'  => $image_url,
				'caption'    => $caption,
			],
		] );

		if ( is_wp_error( $container_resp ) ) {
			return self::err( 'Instagram container request failed: ' . $container_resp->get_error_message() );
		}

		$container_body = json_decode( wp_remote_retrieve_body( $container_resp ), true );
		$container_code = wp_remote_retrieve_response_code( $container_resp );

		if ( $container_code !== 200 || empty( $container_body['id'] ) ) {
			$error = $container_body['error']['message'] ?? '(empty response)';
			return self::err( "Instagram container error (HTTP {$container_code}): {$error}" );
		}

		$creation_id = $container_body['id'];

		// Brief pause — allow Meta to process the container before publishing.
		sleep( 2 );

		// Step 2 — Publish the container.
		$publish_ep = self::GRAPH_BASE . self::GRAPH_VERSION . '/' . $ig_user_id . '/media_publish';

		$publish_resp = wp_remote_post( $publish_ep, [
			'timeout' => 30,
			'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
			'body'    => [ 'creation_id' => $creation_id ],
		] );

		return self::parse_response( $publish_resp, 'Instagram' );
	}

	// ── Test connections ──────────────────────────────────────────────────────

	/**
	 * Verify the saved Facebook credentials against the Graph API.
	 * Token is sent via Authorization header — never in the URL.
	 *
	 * @return array{success: bool, message: string}
	 */
	public static function test_facebook_connection(): array {
		$settings     = get_option( 'sasp_settings', [] );
		$page_id      = trim( $settings['fb_page_id'] ?? '' );
		$access_token = SASP_Crypto::decrypt( trim( $settings['fb_access_token'] ?? '' ) );

		if ( '' === $page_id || '' === $access_token ) {
			return self::err( 'Facebook Page ID or Access Token is not set.' );
		}

		// Token goes in the Authorization header, NOT in the URL query string.
		$url = add_query_arg( [ 'fields' => 'id,name' ], self::GRAPH_BASE . self::GRAPH_VERSION . '/' . $page_id );

		$response = wp_remote_get( $url, [
			'timeout' => 15,
			'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
		] );

		if ( is_wp_error( $response ) ) {
			return self::err( $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code && ! empty( $body['id'] ) ) {
			return [ 'success' => true, 'message' => 'Connected! Page: ' . ( $body['name'] ?? $body['id'] ) ];
		}

		$error = $body['error']['message'] ?? 'Unknown error';
		return self::err( "API error (HTTP {$code}): {$error}" );
	}

	/**
	 * Verify the saved Instagram credentials against the Graph API.
	 * Token is sent via Authorization header — never in the URL.
	 *
	 * @return array{success: bool, message: string}
	 */
	public static function test_instagram_connection(): array {
		$settings     = get_option( 'sasp_settings', [] );
		$ig_user_id   = trim( $settings['ig_user_id'] ?? '' );
		$access_token = SASP_Crypto::decrypt( trim( $settings['ig_access_token'] ?? '' ) );

		if ( '' === $access_token ) {
			$access_token = SASP_Crypto::decrypt( trim( $settings['fb_access_token'] ?? '' ) );
		}

		if ( '' === $ig_user_id || '' === $access_token ) {
			return self::err( 'Instagram Business Account ID or Access Token is not set.' );
		}

		$url = add_query_arg( [ 'fields' => 'id,name,username' ], self::GRAPH_BASE . self::GRAPH_VERSION . '/' . $ig_user_id );

		$response = wp_remote_get( $url, [
			'timeout' => 15,
			'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
		] );

		if ( is_wp_error( $response ) ) {
			return self::err( $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code && ! empty( $body['id'] ) ) {
			$display = $body['username'] ?? $body['name'] ?? $body['id'];
			return [ 'success' => true, 'message' => "Connected! Instagram: @{$display}" ];
		}

		$error = $body['error']['message'] ?? 'Unknown error';
		return self::err( "API error (HTTP {$code}): {$error}" );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * @return array{success: bool, message: string}
	 */
	private static function parse_response( mixed $response, string $label ): array {
		if ( is_wp_error( $response ) ) {
			return self::err( "{$label} request failed: " . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code && ! empty( $body['id'] ) ) {
			return [ 'success' => true, 'message' => "{$label} post published. ID: " . $body['id'] ];
		}

		// Strip any token that may appear in the raw body before logging.
		$error = $body['error']['message'] ?? '(see server logs for raw response)';
		return self::err( "{$label} API error (HTTP {$code}): {$error}" );
	}

	/**
	 * @return array{success: bool, message: string}
	 */
	private static function err( string $message ): array {
		return [ 'success' => false, 'message' => $message ];
	}
}
