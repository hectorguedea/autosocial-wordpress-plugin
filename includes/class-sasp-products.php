<?php
/**
 * Product selection, caption building, and posted-history management.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SASP_Products {

	private const POSTED_OPTION = 'sasp_posted_product_ids';

	// ── Selection ─────────────────────────────────────────────────────────────

	/**
	 * Returns the next eligible WC_Product or null when none found.
	 */
	public static function get_eligible_product(): ?WC_Product {
		$settings     = get_option( 'sasp_settings', [] );
		$include_cats = array_map( 'intval', (array) ( $settings['include_categories'] ?? [] ) );
		$exclude_cats = array_map( 'intval', (array) ( $settings['exclude_categories'] ?? [] ) );

		$args = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => [
				[
					'key'   => '_stock_status',
					'value' => 'instock',
				],
			],
		];

		$tax_query = [];

		if ( ! empty( $include_cats ) ) {
			$tax_query[] = [
				'taxonomy' => 'product_cat',
				'field'    => 'term_id',
				'terms'    => $include_cats,
				'operator' => 'IN',
			];
		}

		if ( ! empty( $exclude_cats ) ) {
			$tax_query[] = [
				'taxonomy' => 'product_cat',
				'field'    => 'term_id',
				'terms'    => $exclude_cats,
				'operator' => 'NOT IN',
			];
		}

		if ( count( $tax_query ) > 1 ) {
			$tax_query['relation'] = 'AND';
		}

		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = $tax_query;
		}

		$all_ids = get_posts( $args );

		if ( empty( $all_ids ) ) {
			return null;
		}

		// Keep only products with a valid HTTPS featured image.
		$eligible = array_values(
			array_filter( $all_ids, static function ( int $id ): bool {
				$url = get_the_post_thumbnail_url( $id, 'full' );
				return ! empty( $url ) && str_starts_with( (string) $url, 'https://' );
			} )
		);

		if ( empty( $eligible ) ) {
			return null;
		}

		$posted    = self::get_posted_ids();
		$unposted  = array_values( array_diff( $eligible, $posted ) );

		if ( empty( $unposted ) ) {
			// Full cycle complete — reset and start over.
			self::reset_history();
			$unposted = $eligible;
		}

		$product_id = $unposted[0];

		return wc_get_product( $product_id ) ?: null;
	}

	// ── Posted history ────────────────────────────────────────────────────────

	public static function get_posted_ids(): array {
		$ids = get_option( self::POSTED_OPTION, [] );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
	}

	public static function mark_as_posted( int $product_id ): void {
		$posted = self::get_posted_ids();
		if ( ! in_array( $product_id, $posted, true ) ) {
			$posted[] = $product_id;
			update_option( self::POSTED_OPTION, $posted, false );
		}
	}

	public static function reset_history(): void {
		delete_option( self::POSTED_OPTION );
	}

	// ── Caption ───────────────────────────────────────────────────────────────

	public static function build_caption( WC_Product $product, string $platform = '' ): string {
		$settings = get_option( 'sasp_settings', [] );
		$template = ! empty( $settings['caption_template'] )
			? $settings['caption_template']
			: self::default_template();

		$product_id  = $product->get_id();
		$title       = $product->get_name();
		$short_desc  = wp_strip_all_tags( $product->get_short_description() );
		$description = wp_strip_all_tags( $product->get_description() );
		$price       = html_entity_decode( wp_strip_all_tags( wc_price( (float) $product->get_price() ) ) );
		$sku         = $product->get_sku();
		$product_url = get_permalink( $product_id );

		// Append UTM parameters.
		if ( 'facebook' === $platform ) {
			$product_url = add_query_arg(
				[
					'utm_source'   => 'facebook',
					'utm_medium'   => 'social',
					'utm_campaign' => 'auto_product_post',
				],
				$product_url
			);
		} elseif ( 'instagram' === $platform ) {
			$product_url = add_query_arg(
				[
					'utm_source'   => 'instagram',
					'utm_medium'   => 'social',
					'utm_campaign' => 'auto_product_post',
				],
				$product_url
			);
		}

		// Categories.
		$cat_terms  = get_the_terms( $product_id, 'product_cat' );
		$categories = ( $cat_terms && ! is_wp_error( $cat_terms ) )
			? implode( ', ', wp_list_pluck( $cat_terms, 'name' ) )
			: '';

		// Tags.
		$tag_terms = get_the_terms( $product_id, 'product_tag' );
		$tags      = ( $tag_terms && ! is_wp_error( $tag_terms ) )
			? implode( ', ', wp_list_pluck( $tag_terms, 'name' ) )
			: '';

		$hashtags = trim( (string) ( $settings['hashtags'] ?? self::default_hashtags() ) );

		$caption = str_replace(
			[ '{product_title}', '{short_description}', '{description}', '{price}', '{product_url}', '{sku}', '{categories}', '{tags}', '{hashtags}' ],
			[ $title, $short_desc, $description, $price, $product_url, $sku, $categories, $tags, $hashtags ],
			$template
		);

		// Auto-append hashtags when the template does not already use {hashtags}.
		if ( ! str_contains( $template, '{hashtags}' ) && '' !== $hashtags ) {
			$caption = rtrim( $caption ) . "\n\n" . $hashtags;
		}

		return $caption;
	}

	public static function default_template(): string {
		return "💿 Available now!\n\n{product_title}\n\n{description}\n\nPrice: {price}\n\nBuy at:\n{product_url}";
	}

	public static function default_hashtags(): string {
		return '#sindromeproductions #deathmetal #brutaldeathmetal #goregrind #grindcore #metalcds #metalcollector #metalcollection';
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/** Returns all WC product categories as an array of WP_Term objects. */
	public static function get_all_categories(): array {
		$terms = get_terms( [
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
		] );
		return ( is_array( $terms ) && ! is_wp_error( $terms ) ) ? $terms : [];
	}
}
