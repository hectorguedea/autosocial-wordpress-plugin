<?php
/**
 * Admin UI — settings page, logs page, AJAX handlers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SASP_Admin {

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'admin_notices', [ __CLASS__, 'token_expiry_notice' ] );

		// AJAX handlers.
		add_action( 'wp_ajax_sasp_test_facebook',  [ __CLASS__, 'ajax_test_facebook' ] );
		add_action( 'wp_ajax_sasp_test_instagram', [ __CLASS__, 'ajax_test_instagram' ] );
		add_action( 'wp_ajax_sasp_post_now',       [ __CLASS__, 'ajax_post_now' ] );
		add_action( 'wp_ajax_sasp_reset_history',  [ __CLASS__, 'ajax_reset_history' ] );
		add_action( 'wp_ajax_sasp_clear_logs',     [ __CLASS__, 'ajax_clear_logs' ] );
	}

	// ── Menu ──────────────────────────────────────────────────────────────────

	public static function add_menu(): void {
		$parent = class_exists( 'WooCommerce' ) ? 'woocommerce' : 'options-general.php';

		add_submenu_page(
			$parent,
			__( 'AutoSocial Poster', 'sasp' ),
			__( 'Auto Social Poster', 'sasp' ),
			'manage_options',
			'sasp-settings',
			[ __CLASS__, 'render_settings_page' ]
		);

		add_submenu_page(
			$parent,
			__( 'Social Poster Logs', 'sasp' ),
			__( 'Social Poster Logs', 'sasp' ),
			'manage_options',
			'sasp-logs',
			[ __CLASS__, 'render_logs_page' ]
		);
	}

	// ── Settings API ──────────────────────────────────────────────────────────

	public static function register_settings(): void {
		register_setting(
			'sasp_settings_group',
			'sasp_settings',
			[ 'sanitize_callback' => [ __CLASS__, 'sanitize_settings' ] ]
		);
	}

	public static function sanitize_settings( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			$input = [];
		}

		$old   = get_option( 'sasp_settings', [] );
		$clean = [];

		// Checkboxes.
		$clean['enabled']          = ! empty( $input['enabled'] ) ? 1 : 0;
		$clean['enable_facebook']  = ! empty( $input['enable_facebook'] ) ? 1 : 0;
		$clean['enable_instagram'] = ! empty( $input['enable_instagram'] ) ? 1 : 0;

		// Non-sensitive credentials.
		$clean['fb_page_id'] = sanitize_text_field( $input['fb_page_id'] ?? '' );
		$clean['ig_user_id'] = sanitize_text_field( $input['ig_user_id'] ?? '' );

		// ── Facebook Access Token ────────────────────────────────────────────
		// Security rules:
		//  • If the user submitted a non-empty value → encrypt and save as new token.
		//  • If the user submitted empty            → keep the existing stored token.
		//  • If the "clear" checkbox is ticked      → delete the token entirely.
		$new_fb_raw = sanitize_text_field( $input['fb_access_token'] ?? '' );
		if ( ! empty( $input['fb_access_token_clear'] ) ) {
			$clean['fb_access_token'] = '';
			delete_option( 'sasp_fb_token_set_at' );
		} elseif ( '' !== $new_fb_raw ) {
			$clean['fb_access_token'] = SASP_Crypto::encrypt( $new_fb_raw );
			update_option( 'sasp_fb_token_set_at', time(), false );
		} else {
			$clean['fb_access_token'] = $old['fb_access_token'] ?? ''; // keep existing
		}

		// ── Instagram Access Token ───────────────────────────────────────────
		$new_ig_raw = sanitize_text_field( $input['ig_access_token'] ?? '' );
		if ( ! empty( $input['ig_access_token_clear'] ) ) {
			$clean['ig_access_token'] = '';
			delete_option( 'sasp_ig_token_set_at' );
		} elseif ( '' !== $new_ig_raw ) {
			$clean['ig_access_token'] = SASP_Crypto::encrypt( $new_ig_raw );
			update_option( 'sasp_ig_token_set_at', time(), false );
		} else {
			$clean['ig_access_token'] = $old['ig_access_token'] ?? ''; // keep existing
		}

		// Time validation (HH:MM format).
		$t1 = sanitize_text_field( $input['post_time_1'] ?? '10:00' );
		$t2 = sanitize_text_field( $input['post_time_2'] ?? '18:00' );
		$clean['post_time_1'] = preg_match( '/^\d{2}:\d{2}$/', $t1 ) ? $t1 : '10:00';
		$clean['post_time_2'] = preg_match( '/^\d{2}:\d{2}$/', $t2 ) ? $t2 : '18:00';

		// Caption template.
		$tpl = sanitize_textarea_field( $input['caption_template'] ?? '' );
		$clean['caption_template'] = '' !== $tpl ? $tpl : SASP_Products::default_template();

		// Hashtags (separate field — auto-appended after every caption).
		$ht = sanitize_textarea_field( $input['hashtags'] ?? '' );
		$clean['hashtags'] = '' !== $ht ? $ht : SASP_Products::default_hashtags();

		// Category lists.
		$clean['include_categories'] = array_map( 'intval', (array) ( $input['include_categories'] ?? [] ) );
		$clean['exclude_categories'] = array_map( 'intval', (array) ( $input['exclude_categories'] ?? [] ) );

		// Reschedule cron if posting times changed.
		if (
			( $old['post_time_1'] ?? '' ) !== $clean['post_time_1'] ||
			( $old['post_time_2'] ?? '' ) !== $clean['post_time_2']
		) {
			add_action( 'shutdown', [ 'SASP_Cron', 'reschedule_events' ] );
		}

		return $clean;
	}

	// ── Token expiry notice ───────────────────────────────────────────────────

	public static function token_expiry_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = get_option( 'sasp_settings', [] );
		$warnings = [];

		$fb_token_set = (int) get_option( 'sasp_fb_token_set_at', 0 );
		$ig_token_set = (int) get_option( 'sasp_ig_token_set_at', 0 );
		$now          = time();

		if ( ! empty( $settings['enable_facebook'] ) && ! empty( $settings['fb_access_token'] ) && $fb_token_set > 0 ) {
			$days_old = (int) round( ( $now - $fb_token_set ) / DAY_IN_SECONDS );
			if ( $days_old >= 53 ) {
				$warnings[] = sprintf(
					/* translators: %d = number of days */
					__( '<strong>AutoSocial Poster:</strong> Your Facebook Access Token is %d days old and may expire soon (Meta tokens last ~60 days). <a href="%s">Renew it now</a>.', 'sasp' ),
					$days_old,
					esc_url( admin_url( 'admin.php?page=sasp-settings' ) )
				);
			}
		}

		if ( ! empty( $settings['enable_instagram'] ) && ! empty( $settings['ig_access_token'] ) && $ig_token_set > 0 ) {
			$days_old = (int) round( ( $now - $ig_token_set ) / DAY_IN_SECONDS );
			if ( $days_old >= 53 ) {
				$warnings[] = sprintf(
					__( '<strong>AutoSocial Poster:</strong> Your Instagram Access Token is %d days old and may expire soon. <a href="%s">Renew it now</a>.', 'sasp' ),
					$days_old,
					esc_url( admin_url( 'admin.php?page=sasp-settings' ) )
				);
			}
		}

		foreach ( $warnings as $msg ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . wp_kses( $msg, [ 'strong' => [], 'a' => [ 'href' => [] ] ] ) . '</p></div>';
		}
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	public static function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, 'sasp' ) ) {
			return;
		}
		wp_enqueue_style( 'sasp-admin', SASP_PLUGIN_URL . 'assets/admin.css', [], SASP_VERSION );
		wp_enqueue_script( 'sasp-admin', SASP_PLUGIN_URL . 'assets/admin.js', [ 'jquery' ], SASP_VERSION, true );
		wp_localize_script( 'sasp-admin', 'sasp_ajax', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'sasp_ajax_nonce' ),
			'strings'  => [
				'confirm_post'  => __( 'This will immediately post a product to your enabled platforms. Continue?', 'sasp' ),
				'confirm_reset' => __( 'Reset the posted history? All products will become eligible again. Continue?', 'sasp' ),
				'confirm_clear' => __( 'Delete all logs? This cannot be undone. Continue?', 'sasp' ),
				'testing'       => __( 'Testing…', 'sasp' ),
				'posting'       => __( 'Posting…', 'sasp' ),
				'conn_error'    => __( 'Connection error — check browser console.', 'sasp' ),
			],
		] );
	}

	// ── Settings page ─────────────────────────────────────────────────────────

	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sasp' ) );
		}

		$settings     = get_option( 'sasp_settings', [] );
		$categories   = SASP_Products::get_all_categories();
		$posted_count = count( SASP_Products::get_posted_ids() );
		$last_success = SASP_Logger::get_last_success();
		$tz_string    = wp_timezone_string();

		$next1_ts = wp_next_scheduled( 'sasp_post_event_1' );
		$next2_ts = wp_next_scheduled( 'sasp_post_event_2' );
		$date_fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		// Token age info.
		$fb_token_info = self::token_age_info( 'sasp_fb_token_set_at' );
		$ig_token_info = self::token_age_info( 'sasp_ig_token_set_at' );

		// Cron health.
		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$site_url      = get_site_url();
		?>
		<div class="wrap sasp-wrap">
			<h1 class="sasp-page-title">
				<span class="dashicons dashicons-share"></span>
				<?php esc_html_e( 'AutoSocial Poster', 'sasp' ); ?>
			</h1>

			<!-- Status bar -->
			<div class="sasp-status-bar">
				<span class="sasp-pill <?php echo ! empty( $settings['enabled'] ) ? 'sasp-pill-green' : 'sasp-pill-red'; ?>">
					<?php echo ! empty( $settings['enabled'] ) ? esc_html__( '● Auto-posting ON', 'sasp' ) : esc_html__( '● Auto-posting OFF', 'sasp' ); ?>
				</span>
				<?php if ( $last_success ) : ?>
					<span class="sasp-meta">
						<?php printf( esc_html__( 'Last success: %s', 'sasp' ), '<strong>' . esc_html( $last_success ) . '</strong>' ); ?>
					</span>
				<?php endif; ?>
				<span class="sasp-meta">
					<?php printf( esc_html__( 'Posted history: %d product(s)', 'sasp' ), $posted_count ); ?>
				</span>
				<?php if ( $next1_ts ) : ?>
					<span class="sasp-meta">
						<?php printf(
							esc_html__( 'Next post 1: %s', 'sasp' ),
							'<strong>' . esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next1_ts ), $date_fmt ) ) . '</strong>'
						); ?>
					</span>
				<?php endif; ?>
				<?php if ( $next2_ts ) : ?>
					<span class="sasp-meta">
						<?php printf(
							esc_html__( 'Next post 2: %s', 'sasp' ),
							'<strong>' . esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next2_ts ), $date_fmt ) ) . '</strong>'
						); ?>
					</span>
				<?php endif; ?>
			</div>

			<!-- Action buttons -->
			<div class="sasp-actions">
				<button type="button" id="sasp-post-now" class="button button-primary">
					<span class="dashicons dashicons-controls-play"></span>
					<?php esc_html_e( 'Post Now (Test)', 'sasp' ); ?>
				</button>
				<button type="button" id="sasp-reset-history" class="button">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Reset Posted History', 'sasp' ); ?>
				</button>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=sasp-logs' ) ); ?>" class="button">
					<span class="dashicons dashicons-list-view"></span>
					<?php esc_html_e( 'View Logs', 'sasp' ); ?>
				</a>
			</div>
			<div id="sasp-action-result" class="sasp-notice" style="display:none;"></div>

			<!-- Settings form -->
			<form method="post" action="options.php">
				<?php settings_fields( 'sasp_settings_group' ); ?>

				<div class="sasp-grid">

					<!-- General -->
					<div class="sasp-card">
						<h2><?php esc_html_e( 'General', 'sasp' ); ?></h2>
						<table class="form-table sasp-form-table">
							<tr>
								<th><?php esc_html_e( 'Enable Auto-posting', 'sasp' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="sasp_settings[enabled]" value="1"
											<?php checked( $settings['enabled'] ?? 0, 1 ); ?>>
										<?php esc_html_e( 'Post products automatically on schedule', 'sasp' ); ?>
									</label>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Enable Facebook', 'sasp' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="sasp_settings[enable_facebook]" value="1"
											<?php checked( $settings['enable_facebook'] ?? 0, 1 ); ?>>
										<?php esc_html_e( 'Post to Facebook Page', 'sasp' ); ?>
									</label>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Enable Instagram', 'sasp' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="sasp_settings[enable_instagram]" value="1"
											<?php checked( $settings['enable_instagram'] ?? 0, 1 ); ?>>
										<?php esc_html_e( 'Post to Instagram Business', 'sasp' ); ?>
									</label>
								</td>
							</tr>
						</table>
					</div>

					<!-- Schedule -->
					<div class="sasp-card">
						<h2><?php esc_html_e( 'Schedule', 'sasp' ); ?></h2>
						<p class="description">
							<?php printf(
								esc_html__( 'Times follow WordPress timezone: %s', 'sasp' ),
								'<code>' . esc_html( $tz_string ) . '</code>'
							); ?>
						</p>
						<table class="form-table sasp-form-table">
							<tr>
								<th><?php esc_html_e( 'First Post Time', 'sasp' ); ?></th>
								<td>
									<input type="time" name="sasp_settings[post_time_1]"
										value="<?php echo esc_attr( $settings['post_time_1'] ?? '10:00' ); ?>">
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Second Post Time', 'sasp' ); ?></th>
								<td>
									<input type="time" name="sasp_settings[post_time_2]"
										value="<?php echo esc_attr( $settings['post_time_2'] ?? '18:00' ); ?>">
								</td>
							</tr>
						</table>
					</div>

					<!-- Facebook credentials -->
					<div class="sasp-card">
						<h2><?php esc_html_e( 'Facebook', 'sasp' ); ?></h2>
						<?php if ( '' !== $fb_token_info['label'] ) : ?>
							<p class="sasp-token-age sasp-token-age-<?php echo esc_attr( $fb_token_info['level'] ); ?>">
								<?php echo esc_html( $fb_token_info['label'] ); ?>
							</p>
						<?php endif; ?>
						<table class="form-table sasp-form-table">
							<tr>
								<th><?php esc_html_e( 'Page ID', 'sasp' ); ?></th>
								<td>
									<input type="text" name="sasp_settings[fb_page_id]"
										value="<?php echo esc_attr( $settings['fb_page_id'] ?? '' ); ?>"
										class="regular-text" placeholder="123456789012345">
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Page Access Token', 'sasp' ); ?></th>
								<td>
									<?php $fb_token_saved = ! empty( $settings['fb_access_token'] ); ?>
									<?php if ( $fb_token_saved ) : ?>
										<span class="sasp-token-saved-badge">🔒 <?php esc_html_e( 'Token saved (encrypted)', 'sasp' ); ?></span><br><br>
									<?php endif; ?>
									<input type="password" name="sasp_settings[fb_access_token]"
										value=""
										placeholder="<?php echo $fb_token_saved ? esc_attr__( 'Leave blank to keep current token', 'sasp' ) : esc_attr__( 'Paste Page Access Token here', 'sasp' ); ?>"
										class="regular-text" autocomplete="new-password">
									<?php if ( $fb_token_saved ) : ?>
										<br><label class="sasp-clear-label">
											<input type="checkbox" name="sasp_settings[fb_access_token_clear]" value="1">
											<?php esc_html_e( 'Remove saved token', 'sasp' ); ?>
										</label>
									<?php endif; ?>
									<p class="description">
										<?php esc_html_e( 'Stored encrypted. Paste a new value to replace; leave blank to keep the existing token.', 'sasp' ); ?>
									</p>
								</td>
							</tr>
						</table>
						<div class="sasp-test-row">
							<button type="button" id="sasp-test-facebook" class="button">
								<?php esc_html_e( 'Test Facebook Connection', 'sasp' ); ?>
							</button>
							<span id="sasp-fb-result" class="sasp-inline-result"></span>
						</div>
					</div>

					<!-- Instagram credentials -->
					<div class="sasp-card">
						<h2><?php esc_html_e( 'Instagram', 'sasp' ); ?></h2>
						<?php if ( '' !== $ig_token_info['label'] ) : ?>
							<p class="sasp-token-age sasp-token-age-<?php echo esc_attr( $ig_token_info['level'] ); ?>">
								<?php echo esc_html( $ig_token_info['label'] ); ?>
							</p>
						<?php endif; ?>
						<table class="form-table sasp-form-table">
							<tr>
								<th><?php esc_html_e( 'Business Account ID', 'sasp' ); ?></th>
								<td>
									<input type="text" name="sasp_settings[ig_user_id]"
										value="<?php echo esc_attr( $settings['ig_user_id'] ?? '' ); ?>"
										class="regular-text" placeholder="123456789012345">
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Access Token', 'sasp' ); ?></th>
								<td>
									<?php $ig_token_saved = ! empty( $settings['ig_access_token'] ); ?>
									<?php if ( $ig_token_saved ) : ?>
										<span class="sasp-token-saved-badge">🔒 <?php esc_html_e( 'Token saved (encrypted)', 'sasp' ); ?></span><br><br>
									<?php endif; ?>
									<input type="password" name="sasp_settings[ig_access_token]"
										value=""
										placeholder="<?php echo $ig_token_saved ? esc_attr__( 'Leave blank to keep current token', 'sasp' ) : esc_attr__( 'Paste Instagram Access Token here', 'sasp' ); ?>"
										class="regular-text" autocomplete="new-password">
									<?php if ( $ig_token_saved ) : ?>
										<br><label class="sasp-clear-label">
											<input type="checkbox" name="sasp_settings[ig_access_token_clear]" value="1">
											<?php esc_html_e( 'Remove saved token', 'sasp' ); ?>
										</label>
									<?php endif; ?>
									<p class="description">
										<?php esc_html_e( 'Stored encrypted. Leave blank to reuse the Facebook Page Access Token.', 'sasp' ); ?>
									</p>
								</td>
							</tr>
						</table>
						<div class="sasp-test-row">
							<button type="button" id="sasp-test-instagram" class="button">
								<?php esc_html_e( 'Test Instagram Connection', 'sasp' ); ?>
							</button>
							<span id="sasp-ig-result" class="sasp-inline-result"></span>
						</div>
					</div>

					<!-- Caption template -->
					<div class="sasp-card sasp-card-wide">
						<h2><?php esc_html_e( 'Caption Template', 'sasp' ); ?></h2>
						<p class="description">
							<?php esc_html_e( 'Placeholders:', 'sasp' ); ?>
							<code>{product_title}</code> <code>{short_description}</code>
							<code>{price}</code> <code>{product_url}</code>
							<code>{sku}</code> <code>{categories}</code> <code>{tags}</code> <code>{hashtags}</code>
						</p>
						<textarea name="sasp_settings[caption_template]" rows="10" class="large-text code"><?php
							echo esc_textarea( $settings['caption_template'] ?? SASP_Products::default_template() );
						?></textarea>
						<p class="description">
							<?php esc_html_e( 'UTM parameters are appended automatically to {product_url}. Hashtags from the field below are appended after the caption unless you place {hashtags} manually.', 'sasp' ); ?>
						</p>
					</div>

					<!-- Hashtags -->
					<div class="sasp-card sasp-card-wide">
						<h2><?php esc_html_e( 'Hashtags', 'sasp' ); ?></h2>
						<p class="description">
							<?php esc_html_e( 'These hashtags are automatically added at the end of every post. You can also place {hashtags} anywhere in the caption template above.', 'sasp' ); ?>
						</p>
						<textarea name="sasp_settings[hashtags]" rows="3" class="large-text code"><?php
							echo esc_textarea( $settings['hashtags'] ?? SASP_Products::default_hashtags() );
						?></textarea>
					</div>

					<!-- Product categories -->
					<?php if ( ! empty( $categories ) ) : ?>
					<div class="sasp-card sasp-card-wide">
						<h2><?php esc_html_e( 'Product Categories', 'sasp' ); ?></h2>
						<div class="sasp-cat-grid">
							<div>
								<h3><?php esc_html_e( 'Include Only These Categories', 'sasp' ); ?></h3>
								<p class="description"><?php esc_html_e( 'Leave all unchecked to include every category.', 'sasp' ); ?></p>
								<?php foreach ( $categories as $cat ) : ?>
									<label class="sasp-cat-label">
										<input type="checkbox" name="sasp_settings[include_categories][]"
											value="<?php echo esc_attr( $cat->term_id ); ?>"
											<?php checked( in_array( $cat->term_id, $settings['include_categories'] ?? [], false ) ); ?>>
										<?php echo esc_html( $cat->name ); ?>
										<span class="sasp-cat-count">(<?php echo esc_html( $cat->count ); ?>)</span>
									</label>
								<?php endforeach; ?>
							</div>
							<div>
								<h3><?php esc_html_e( 'Exclude These Categories', 'sasp' ); ?></h3>
								<p class="description"><?php esc_html_e( 'Takes precedence over the include list.', 'sasp' ); ?></p>
								<?php foreach ( $categories as $cat ) : ?>
									<label class="sasp-cat-label">
										<input type="checkbox" name="sasp_settings[exclude_categories][]"
											value="<?php echo esc_attr( $cat->term_id ); ?>"
											<?php checked( in_array( $cat->term_id, $settings['exclude_categories'] ?? [], false ) ); ?>>
										<?php echo esc_html( $cat->name ); ?>
									</label>
								<?php endforeach; ?>
							</div>
						</div>
					</div>
					<?php endif; ?>

					<!-- Cron health -->
					<div class="sasp-card sasp-card-wide">
						<h2><?php esc_html_e( 'WP-Cron Health', 'sasp' ); ?></h2>
						<?php if ( $cron_disabled ) : ?>
							<div class="sasp-cron-badge sasp-cron-ok">
								✔ <?php esc_html_e( 'DISABLE_WP_CRON is active — using system cron (recommended).', 'sasp' ); ?>
							</div>
						<?php else : ?>
							<div class="sasp-cron-badge sasp-cron-warn">
								⚠ <?php esc_html_e( 'Using native WP-Cron. Posts fire only when someone visits your site. On low-traffic stores the scheduled time may be delayed.', 'sasp' ); ?>
							</div>
						<?php endif; ?>

						<div class="sasp-cron-info">
							<p>
								<strong><?php esc_html_e( 'Recommended fix — set up a real crontab:', 'sasp' ); ?></strong>
							</p>
							<p class="description">
								<?php esc_html_e( 'Step 1: Add this line to wp-config.php to disable WP\'s built-in cron:', 'sasp' ); ?>
							</p>
							<pre class="sasp-code-block">define( 'DISABLE_WP_CRON', true );</pre>
							<p class="description">
								<?php esc_html_e( 'Step 2: Add one of these to your server crontab (run every 5 minutes):', 'sasp' ); ?>
							</p>
							<pre class="sasp-code-block">*/5 * * * * curl -s "<?php echo esc_url( $site_url ); ?>/wp-cron.php?doing_wp_cron" &gt;/dev/null 2&gt;&amp;1</pre>
							<pre class="sasp-code-block">*/5 * * * * wget -q -O - "<?php echo esc_url( $site_url ); ?>/wp-cron.php?doing_wp_cron" &gt;/dev/null 2&gt;&amp;1</pre>
							<p class="description">
								<?php esc_html_e( 'To edit crontab on your server: run', 'sasp' ); ?>
								<code>crontab -e</code>
								<?php esc_html_e( 'in SSH and paste the curl or wget line above.', 'sasp' ); ?>
							</p>
						</div>
					</div>

				</div><!-- .sasp-grid -->

				<?php submit_button( __( 'Save Settings', 'sasp' ) ); ?>

			</form>
		</div>
		<?php
	}

	// ── Logs page ─────────────────────────────────────────────────────────────

	public static function render_logs_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sasp' ) );
		}

		$per_page = 50;
		$page     = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$offset   = ( $page - 1 ) * $per_page;
		$logs     = SASP_Logger::get_logs( $per_page, $offset );
		$total    = SASP_Logger::get_count();
		$pages    = (int) ceil( $total / $per_page );
		?>
		<div class="wrap sasp-wrap">
			<h1 class="sasp-page-title">
				<span class="dashicons dashicons-list-view"></span>
				<?php esc_html_e( 'Social Poster Logs', 'sasp' ); ?>
			</h1>

			<div class="sasp-actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=sasp-settings' ) ); ?>" class="button">
					&larr; <?php esc_html_e( 'Back to Settings', 'sasp' ); ?>
				</a>
				<button type="button" id="sasp-clear-logs" class="button">
					<?php esc_html_e( 'Clear All Logs', 'sasp' ); ?>
				</button>
			</div>

			<p class="description">
				<?php printf(
					esc_html__( 'Showing %1$d of %2$d entries (max 500 stored).', 'sasp' ),
					count( $logs ),
					$total
				); ?>
			</p>

			<table class="wp-list-table widefat fixed striped sasp-logs-table">
				<thead>
					<tr>
						<th class="column-time"><?php esc_html_e( 'Date / Time', 'sasp' ); ?></th>
						<th class="column-product"><?php esc_html_e( 'Product', 'sasp' ); ?></th>
						<th class="column-platform"><?php esc_html_e( 'Platform', 'sasp' ); ?></th>
						<th class="column-status"><?php esc_html_e( 'Status', 'sasp' ); ?></th>
						<th><?php esc_html_e( 'Message / API Response', 'sasp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $logs ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No log entries yet.', 'sasp' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<td><?php echo esc_html( $log['log_time'] ); ?></td>
								<td>
									<?php if ( (int) $log['product_id'] > 0 ) : ?>
										<a href="<?php echo esc_url( (string) get_edit_post_link( (int) $log['product_id'] ) ); ?>">
											<?php echo esc_html( $log['product_title'] ); ?>
										</a>
										<br><small class="sasp-muted">ID: <?php echo esc_html( $log['product_id'] ); ?></small>
									<?php else : ?>
										<em class="sasp-muted"><?php esc_html_e( '(system)', 'sasp' ); ?></em>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( ucfirst( $log['platform'] ) ); ?></td>
								<td>
									<span class="sasp-badge sasp-badge-<?php echo esc_attr( $log['status'] ); ?>">
										<?php echo esc_html( ucfirst( $log['status'] ) ); ?>
									</span>
								</td>
								<td class="sasp-log-msg"><small><?php echo esc_html( $log['message'] ); ?></small></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post( paginate_links( [
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
							'total'     => $pages,
							'current'   => $page,
						] ) );
						?>
					</div>
				</div>
			<?php endif; ?>

		</div>
		<?php
	}

	// ── AJAX handlers ─────────────────────────────────────────────────────────

	public static function ajax_test_facebook(): void {
		self::verify_ajax();
		$result = SASP_Meta_API::test_facebook_connection();
		$result['success'] ? wp_send_json_success( $result['message'] ) : wp_send_json_error( $result['message'] );
	}

	public static function ajax_test_instagram(): void {
		self::verify_ajax();
		$result = SASP_Meta_API::test_instagram_connection();
		$result['success'] ? wp_send_json_success( $result['message'] ) : wp_send_json_error( $result['message'] );
	}

	public static function ajax_post_now(): void {
		self::verify_ajax();

		// Rate limit: 1 manual post per 60 seconds per site.
		if ( get_transient( 'sasp_post_now_lock' ) ) {
			wp_send_json_error( __( 'Please wait 60 seconds before triggering another manual post.', 'sasp' ) );
			return;
		}
		set_transient( 'sasp_post_now_lock', 1, 60 );

		$results = SASP_Cron::execute_post();

		if ( isset( $results['error'] ) ) {
			wp_send_json_error( $results['error']['message'] );
			return;
		}

		$any_success = false;
		$lines       = [];
		foreach ( $results as $platform => $r ) {
			$lines[]     = ucfirst( $platform ) . ': ' . $r['message'];
			$any_success = $any_success || $r['success'];
		}

		$any_success
			? wp_send_json_success( implode( "\n", $lines ) )
			: wp_send_json_error( implode( "\n", $lines ) );
	}

	public static function ajax_reset_history(): void {
		self::verify_ajax();
		SASP_Products::reset_history();
		wp_send_json_success( __( 'Posted history has been reset. All products are eligible again.', 'sasp' ) );
	}

	public static function ajax_clear_logs(): void {
		self::verify_ajax();
		SASP_Logger::clear();
		wp_send_json_success( __( 'All logs have been deleted.', 'sasp' ) );
	}

	// ── Internal helpers ──────────────────────────────────────────────────────

	private static function verify_ajax(): void {
		check_ajax_referer( 'sasp_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}
	}

	/**
	 * Returns display info about a stored token timestamp.
	 *
	 * @return array{label: string, level: string}  level: ok | warn | danger
	 */
	private static function token_age_info( string $option_key ): array {
		$set_at = (int) get_option( $option_key, 0 );
		if ( 0 === $set_at ) {
			return [ 'label' => '', 'level' => 'ok' ];
		}

		$days = (int) round( ( time() - $set_at ) / DAY_IN_SECONDS );

		if ( $days >= 60 ) {
			return [
				'label' => sprintf( __( '⚠ Token is %d days old — likely expired! Renew immediately.', 'sasp' ), $days ),
				'level' => 'danger',
			];
		}

		if ( $days >= 53 ) {
			return [
				'label' => sprintf( __( '⚠ Token is %d days old — expiring soon (Meta tokens last ~60 days).', 'sasp' ), $days ),
				'level' => 'warn',
			];
		}

		return [
			'label' => sprintf( __( '✔ Token saved %d day(s) ago.', 'sasp' ), $days ),
			'level' => 'ok',
		];
	}
}
