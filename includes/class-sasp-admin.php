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
		add_action( 'wp_ajax_sasp_test_facebook',      [ __CLASS__, 'ajax_test_facebook' ] );
		add_action( 'wp_ajax_sasp_test_instagram',     [ __CLASS__, 'ajax_test_instagram' ] );
		add_action( 'wp_ajax_sasp_post_now',           [ __CLASS__, 'ajax_post_now' ] );
		add_action( 'wp_ajax_sasp_reset_history',      [ __CLASS__, 'ajax_reset_history' ] );
		add_action( 'wp_ajax_sasp_clear_logs',         [ __CLASS__, 'ajax_clear_logs' ] );
		add_action( 'wp_ajax_sasp_dismiss_checklist',  [ __CLASS__, 'ajax_dismiss_checklist' ] );

		// First-run redirect to setup guide.
		add_action( 'admin_init', [ __CLASS__, 'maybe_redirect_to_guide' ] );
	}

	// ── Menu ──────────────────────────────────────────────────────────────────

	public static function add_menu(): void {
		add_menu_page(
			__( 'AutoSocial Poster', 'sasp' ),
			__( 'AutoSocial Poster', 'sasp' ),
			'manage_options',
			'sasp-settings',
			[ __CLASS__, 'render_settings_page' ],
			'dashicons-share',
			56
		);

		// First submenu replaces the duplicate parent label with "Settings".
		add_submenu_page(
			'sasp-settings',
			__( 'Settings — AutoSocial Poster', 'sasp' ),
			__( 'Settings', 'sasp' ),
			'manage_options',
			'sasp-settings',
			[ __CLASS__, 'render_settings_page' ]
		);

		add_submenu_page(
			'sasp-settings',
			__( 'Logs — AutoSocial Poster', 'sasp' ),
			__( 'Logs', 'sasp' ),
			'manage_options',
			'sasp-logs',
			[ __CLASS__, 'render_logs_page' ]
		);

		add_submenu_page(
			'sasp-settings',
			__( 'Setup Guide — AutoSocial Poster', 'sasp' ),
			__( '📋 Setup Guide', 'sasp' ),
			'manage_options',
			'sasp-guide',
			[ __CLASS__, 'render_guide_page' ]
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
			delete_option( 'sasp_fb_tested' );
		} elseif ( '' !== $new_fb_raw ) {
			$clean['fb_access_token'] = SASP_Crypto::encrypt( $new_fb_raw );
			update_option( 'sasp_fb_token_set_at', time(), false );
			delete_option( 'sasp_fb_tested' ); // must re-test new token
		} else {
			$clean['fb_access_token'] = $old['fb_access_token'] ?? ''; // keep existing
		}

		// ── Instagram Access Token ───────────────────────────────────────────
		$new_ig_raw = sanitize_text_field( $input['ig_access_token'] ?? '' );
		if ( ! empty( $input['ig_access_token_clear'] ) ) {
			$clean['ig_access_token'] = '';
			delete_option( 'sasp_ig_token_set_at' );
			delete_option( 'sasp_ig_tested' );
		} elseif ( '' !== $new_ig_raw ) {
			$clean['ig_access_token'] = SASP_Crypto::encrypt( $new_ig_raw );
			update_option( 'sasp_ig_token_set_at', time(), false );
			delete_option( 'sasp_ig_tested' );
		} else {
			$clean['ig_access_token'] = $old['ig_access_token'] ?? ''; // keep existing
		}

		// Post times — dynamic array (replaces post_time_1/post_time_2 from v1.0.0).
		$raw_times   = (array) ( $input['post_times'] ?? [] );
		$clean_times = [];
		foreach ( $raw_times as $t ) {
			$t = sanitize_text_field( (string) $t );
			if ( preg_match( '/^\d{2}:\d{2}$/', $t ) ) {
				$clean_times[] = $t;
			}
		}
		if ( empty( $clean_times ) ) {
			$clean_times = SASP_Cron::get_post_times(); // keep existing or default
		}
		$clean_times = array_slice( array_unique( $clean_times ), 0, 20 );
		sort( $clean_times );
		$clean['post_times'] = $clean_times;

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
		if ( ( $old['post_times'] ?? [] ) !== $clean['post_times'] ) {
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

		$date_fmt    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$post_times  = SASP_Cron::get_post_times();
		$next_events = [];
		foreach ( $post_times as $idx => $_ ) {
			$ts = wp_next_scheduled( 'sasp_post_event', [ $idx ] );
			if ( $ts ) {
				$next_events[] = $ts;
			}
		}
		sort( $next_events );

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
				<?php if ( ! empty( $next_events ) ) : ?>
					<span class="sasp-meta">
						<?php printf(
							esc_html__( 'Next post: %s', 'sasp' ),
							'<strong>' . esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next_events[0] ), $date_fmt ) ) . '</strong>'
						); ?>
					</span>
					<?php if ( count( $next_events ) > 1 ) : ?>
						<span class="sasp-meta">
							<?php printf( esc_html__( '+%d more scheduled today', 'sasp' ), count( $next_events ) - 1 ); ?>
						</span>
					<?php endif; ?>
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

				<?php self::render_setup_checklist(); ?>

				<div class="sasp-grid">

					<!-- General -->
					<div class="sasp-card" id="sasp-card-general">
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
					<div class="sasp-card" id="sasp-card-schedule">
						<h2><?php esc_html_e( 'Schedule', 'sasp' ); ?></h2>
						<p class="description">
							<?php printf(
								wp_kses( __( 'Add as many post times per day as you like. Using timezone: <code>%s</code>', 'sasp' ), [ 'code' => [] ] ),
								esc_html( $tz_string )
							); ?>
						</p>
						<div id="sasp-times-container" class="sasp-times-list">
							<?php
							$saved_times = $post_times ?: [ '10:00', '18:00' ];
							foreach ( $saved_times as $t ) :
							?>
							<div class="sasp-time-slot">
								<span class="dashicons dashicons-clock sasp-time-icon"></span>
								<input type="time" name="sasp_settings[post_times][]"
									value="<?php echo esc_attr( $t ); ?>"
									class="sasp-time-input">
								<button type="button" class="button sasp-remove-time" title="<?php esc_attr_e( 'Remove this time', 'sasp' ); ?>">
									<span class="dashicons dashicons-no-alt"></span>
								</button>
							</div>
							<?php endforeach; ?>
						</div>
						<button type="button" id="sasp-add-time" class="button sasp-add-time-btn">
							<span class="dashicons dashicons-plus-alt2"></span>
							<?php esc_html_e( 'Add another time', 'sasp' ); ?>
						</button>
						<p class="description" style="margin-top:8px">
							<?php esc_html_e( 'Each slot posts one product. You can have 1–20 slots per day.', 'sasp' ); ?>
						</p>
					</div>

					<!-- Facebook credentials -->
					<div class="sasp-card" id="sasp-card-facebook">
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
									<a href="#" class="sasp-help-toggle" data-target="sasp-help-fb-page-id"><?php esc_html_e( 'How to find it ▾', 'sasp' ); ?></a>
									<div id="sasp-help-fb-page-id" class="sasp-help-panel" hidden>
										<strong><?php esc_html_e( 'Option A — from your Facebook Page:', 'sasp' ); ?></strong>
										<ol>
											<li><?php esc_html_e( 'Go to your Facebook Page.', 'sasp' ); ?></li>
											<li><?php esc_html_e( 'Click "About" in the left sidebar.', 'sasp' ); ?></li>
											<li><?php esc_html_e( 'Scroll down — the Page ID is in a grey box at the bottom.', 'sasp' ); ?></li>
										</ol>
										<strong><?php esc_html_e( 'Option B — Graph API Explorer:', 'sasp' ); ?></strong>
										<p><?php esc_html_e( 'Run', 'sasp' ); ?> <code>me?fields=id,name</code> <?php esc_html_e( 'with your Page token. The "id" field is your Page ID.', 'sasp' ); ?></p>
									</div>
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
									<a href="#" class="sasp-help-toggle" data-target="sasp-help-fb-token"><?php esc_html_e( 'How to get a token ▾', 'sasp' ); ?></a>
									<div id="sasp-help-fb-token" class="sasp-help-panel" hidden>
										<ol>
											<li><?php printf( wp_kses( __( 'Go to <a href="%s" target="_blank" rel="noopener">Graph API Explorer</a>.', 'sasp' ), [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ), 'https://developers.facebook.com/tools/explorer' ); ?></li>
											<li><?php esc_html_e( 'Select your Meta App → click "Generate Access Token".', 'sasp' ); ?></li>
											<li><?php esc_html_e( 'Approve permissions: pages_manage_posts, pages_read_engagement, pages_show_list.', 'sasp' ); ?></li>
											<li><?php esc_html_e( 'Switch from "User Token" to your Page in the token dropdown.', 'sasp' ); ?></li>
											<li><?php printf( wp_kses( __( 'Extend the token to 60 days in the <a href="%s" target="_blank" rel="noopener">Token Debugger</a>.', 'sasp' ), [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ), 'https://developers.facebook.com/tools/debug/accesstoken' ); ?></li>
										</ol>
										<?php printf( wp_kses( __( 'Full walkthrough: <a href="%s">Setup Guide →</a>', 'sasp' ), [ 'a' => [ 'href' => [] ] ] ), esc_url( admin_url( 'admin.php?page=sasp-guide' ) ) ); ?>
									</div>
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
					<div class="sasp-card" id="sasp-card-instagram">
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
									<a href="#" class="sasp-help-toggle" data-target="sasp-help-ig-id"><?php esc_html_e( 'How to find it ▾', 'sasp' ); ?></a>
									<div id="sasp-help-ig-id" class="sasp-help-panel" hidden>
										<ol>
											<li><?php esc_html_e( 'Make sure your Instagram account is a Business or Creator account and is connected to your Facebook Page.', 'sasp' ); ?></li>
											<li><?php printf( wp_kses( __( 'In <a href="%s" target="_blank" rel="noopener">Graph API Explorer</a>, with your Facebook Page Token selected, run:', 'sasp' ), [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ), 'https://developers.facebook.com/tools/explorer' ); ?>
												<br><code>YOUR_PAGE_ID?fields=instagram_business_account</code>
											</li>
											<li><?php esc_html_e( 'Copy the "id" value inside "instagram_business_account" — that is your Instagram Business Account ID.', 'sasp' ); ?></li>
										</ol>
										<?php printf( wp_kses( __( 'Full walkthrough: <a href="%s">Setup Guide →</a>', 'sasp' ), [ 'a' => [ 'href' => [] ] ] ), esc_url( admin_url( 'admin.php?page=sasp-guide#guide-instagram' ) ) ); ?>
									</div>
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
		if ( $result['success'] ) {
			update_option( 'sasp_fb_tested', 1, false );
		}
		$result['success'] ? wp_send_json_success( $result['message'] ) : wp_send_json_error( $result['message'] );
	}

	public static function ajax_test_instagram(): void {
		self::verify_ajax();
		$result = SASP_Meta_API::test_instagram_connection();
		if ( $result['success'] ) {
			update_option( 'sasp_ig_tested', 1, false );
		}
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

	// ── First-run redirect ────────────────────────────────────────────────────

	public static function maybe_redirect_to_guide(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! get_transient( 'sasp_setup_redirect' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification
		$current_page = sanitize_key( $_GET['page'] ?? '' );
		if ( str_starts_with( $current_page, 'sasp-' ) ) {
			return;
		}
		delete_transient( 'sasp_setup_redirect' );
		wp_safe_redirect( admin_url( 'admin.php?page=sasp-guide' ) );
		exit;
	}

	// ── Setup checklist ───────────────────────────────────────────────────────

	private static function get_setup_status(): array {
		$s = get_option( 'sasp_settings', [] );
		return [
			'fb_configured' => ! empty( $s['fb_page_id'] ) && ! empty( $s['fb_access_token'] ),
			'fb_tested'     => (bool) get_option( 'sasp_fb_tested', 0 ),
			'ig_configured' => ! empty( $s['ig_user_id'] ),
			'ig_tested'     => (bool) get_option( 'sasp_ig_tested', 0 ),
			'platform_on'   => ! empty( $s['enable_facebook'] ) || ! empty( $s['enable_instagram'] ),
			'autopost_on'   => ! empty( $s['enabled'] ),
		];
	}

	private static function render_setup_checklist(): void {
		$user_id = get_current_user_id();
		if ( get_user_meta( $user_id, 'sasp_checklist_dismissed', true ) ) {
			return;
		}

		$st       = self::get_setup_status();
		$all_done = $st['fb_configured'] && $st['fb_tested'] && $st['platform_on'] && $st['autopost_on'];
		if ( $all_done ) {
			return;
		}

		$guide_url = esc_url( admin_url( 'admin.php?page=sasp-guide' ) );
		$items     = [
			[
				'done'  => true,
				'icon'  => 'dashicons-plugins-checked',
				'label' => __( 'Plugin installed and active', 'sasp' ),
			],
			[
				'done'   => $st['fb_configured'],
				'icon'   => 'dashicons-facebook',
				'label'  => __( 'Facebook Page ID & Token saved', 'sasp' ),
				'anchor' => '#sasp-card-facebook',
				'cta'    => __( 'Add credentials ↓', 'sasp' ),
			],
			[
				'done'   => $st['fb_tested'],
				'icon'   => 'dashicons-yes-alt',
				'label'  => __( 'Facebook connection verified', 'sasp' ),
				'anchor' => '#sasp-card-facebook',
				'cta'    => __( 'Test connection ↓', 'sasp' ),
			],
			[
				'done'     => $st['ig_configured'],
				'icon'     => 'dashicons-instagram',
				'label'    => __( 'Instagram Account ID saved', 'sasp' ),
				'anchor'   => '#sasp-card-instagram',
				'cta'      => __( 'Add ID ↓', 'sasp' ),
				'optional' => true,
			],
			[
				'done'   => $st['platform_on'],
				'icon'   => 'dashicons-share',
				'label'  => __( 'At least one platform enabled', 'sasp' ),
				'anchor' => '#sasp-card-general',
				'cta'    => __( 'Enable ↓', 'sasp' ),
			],
			[
				'done'   => $st['autopost_on'],
				'icon'   => 'dashicons-controls-play',
				'label'  => __( 'Auto-posting turned ON', 'sasp' ),
				'anchor' => '#sasp-card-general',
				'cta'    => __( 'Turn on ↓', 'sasp' ),
			],
		];

		$required = array_filter( $items, fn( $i ) => empty( $i['optional'] ) );
		$done_req = count( array_filter( $required, fn( $i ) => $i['done'] ) );
		$total_req = count( $required );
		$pct       = (int) round( $done_req / $total_req * 100 );
		?>
		<div class="sasp-onboard" id="sasp-checklist">
			<div class="sasp-onboard-header">
				<div class="sasp-onboard-title">
					<span class="sasp-onboard-icon">🚀</span>
					<div>
						<strong><?php esc_html_e( 'Getting Started', 'sasp' ); ?></strong>
						<span class="sasp-onboard-subtitle"><?php printf( esc_html__( '%d of %d required steps complete', 'sasp' ), $done_req, $total_req ); ?></span>
					</div>
				</div>
				<div class="sasp-onboard-actions">
					<a href="<?php echo $guide_url; ?>" class="sasp-onboard-guide-btn">
						📋 <?php esc_html_e( 'Setup Guide', 'sasp' ); ?>
					</a>
					<button type="button" id="sasp-dismiss-checklist" class="sasp-onboard-dismiss" title="<?php esc_attr_e( 'Hide this checklist', 'sasp' ); ?>">✕</button>
				</div>
			</div>

			<div class="sasp-progress-wrap">
				<div class="sasp-progress-bar">
					<div class="sasp-progress-fill" style="width:<?php echo $pct; ?>%"></div>
				</div>
				<span class="sasp-progress-pct"><?php echo $pct; ?>%</span>
			</div>

			<div class="sasp-onboard-steps">
				<?php foreach ( $items as $item ) :
					$cls = $item['done'] ? 'sasp-step-done' : ( ! empty( $item['optional'] ) ? 'sasp-step-optional' : 'sasp-step-todo' );
				?>
				<div class="sasp-onboard-step <?php echo $cls; ?>">
					<span class="sasp-onboard-step-check">
						<?php if ( $item['done'] ) : ?>
							<span class="dashicons dashicons-yes"></span>
						<?php else : ?>
							<span class="dashicons <?php echo esc_attr( $item['icon'] ); ?>"></span>
						<?php endif; ?>
					</span>
					<span class="sasp-onboard-step-label">
						<?php echo esc_html( $item['label'] ); ?>
						<?php if ( ! empty( $item['optional'] ) ) : ?>
							<em class="sasp-optional-tag"><?php esc_html_e( 'optional', 'sasp' ); ?></em>
						<?php endif; ?>
					</span>
					<?php if ( ! $item['done'] && ! empty( $item['anchor'] ) ) : ?>
						<a href="<?php echo esc_attr( $item['anchor'] ); ?>" class="sasp-onboard-cta">
							<?php echo esc_html( $item['cta'] ?? __( 'Go ↓', 'sasp' ) ); ?>
						</a>
					<?php endif; ?>
				</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	public static function ajax_dismiss_checklist(): void {
		check_ajax_referer( 'sasp_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}
		update_user_meta( get_current_user_id(), 'sasp_checklist_dismissed', 1 );
		wp_send_json_success();
	}

	// ── Setup Guide page ──────────────────────────────────────────────────────

	public static function render_guide_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sasp' ) );
		}
		$settings_url  = esc_url( admin_url( 'admin.php?page=sasp-settings' ) );
		$allowed_a     = [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ];
		$allowed_a_str = array_merge( $allowed_a, [ 'strong' => [] ] );
		?>
		<div class="wrap sasp-wrap sasp-guide-page">

			<!-- Hero -->
			<div class="sasp-guide-hero">
				<div class="sasp-guide-hero-text">
					<h1>
						<span class="dashicons dashicons-share"></span>
						<?php esc_html_e( 'Setup Guide', 'sasp' ); ?>
					</h1>
					<p><?php esc_html_e( 'Connect your WooCommerce store to Facebook and Instagram in three steps.', 'sasp' ); ?></p>
					<div class="sasp-guide-flow">
						<span class="sasp-flow-step sasp-flow-active">① <?php esc_html_e( 'Get Token', 'sasp' ); ?></span>
						<span class="sasp-flow-arrow">→</span>
						<span class="sasp-flow-step sasp-flow-active">② <?php esc_html_e( 'Enter Credentials', 'sasp' ); ?></span>
						<span class="sasp-flow-arrow">→</span>
						<span class="sasp-flow-step sasp-flow-active">③ <?php esc_html_e( 'Test & Enable', 'sasp' ); ?></span>
					</div>
				</div>
				<div class="sasp-guide-platforms">
					<div class="sasp-platform-chip sasp-chip-fb">
						<span>📘</span> Facebook
					</div>
					<div class="sasp-platform-chip sasp-chip-ig">
						<span>📸</span> Instagram
					</div>
				</div>
			</div>

			<!-- Requirements quick-check -->
			<div class="sasp-guide-requirements">
				<strong><?php esc_html_e( 'Before you begin:', 'sasp' ); ?></strong>
				<ul>
					<li>✅ <?php esc_html_e( 'A Facebook Page for your store (not a personal profile)', 'sasp' ); ?></li>
					<li>✅ <?php esc_html_e( 'Admin access to that Facebook Page', 'sasp' ); ?></li>
					<li>✅ <?php printf( wp_kses( __( 'A free Meta Developer account — sign up at <a href="%s" target="_blank" rel="noopener">developers.facebook.com</a>', 'sasp' ), $allowed_a ), 'https://developers.facebook.com' ); ?></li>
					<li>☑ <?php esc_html_e( 'Instagram Business/Creator account linked to your Page (only if posting to Instagram)', 'sasp' ); ?></li>
				</ul>
			</div>

			<!-- Navigation pills -->
			<div class="sasp-guide-nav">
				<a href="#guide-facebook" class="sasp-guide-nav-pill sasp-nav-fb">📘 <?php esc_html_e( 'Part 1: Facebook', 'sasp' ); ?></a>
				<a href="#guide-instagram" class="sasp-guide-nav-pill sasp-nav-ig">📸 <?php esc_html_e( 'Part 2: Instagram', 'sasp' ); ?></a>
				<a href="#guide-renewal" class="sasp-guide-nav-pill sasp-nav-renewal">🔄 <?php esc_html_e( 'Part 3: Renewal', 'sasp' ); ?></a>
			</div>

			<!-- ── Part 1: Facebook ─────────────────────────────────────────── -->
			<div class="sasp-guide-section" id="guide-facebook">
				<div class="sasp-section-header sasp-section-fb">
					<span class="sasp-section-emoji">📘</span>
					<div>
						<h2><?php esc_html_e( 'Part 1: Facebook Setup', 'sasp' ); ?></h2>
						<p><?php esc_html_e( 'Create an App, get a Page Access Token, and find your Page ID.', 'sasp' ); ?></p>
					</div>
				</div>

				<div class="sasp-step-card">
					<div class="sasp-step-num">1</div>
					<div class="sasp-step-body">
						<h3><?php esc_html_e( 'Register as a Meta Developer', 'sasp' ); ?></h3>
						<ol>
							<li><?php printf(
								wp_kses( __( 'Go to <a href="%s" target="_blank" rel="noopener">developers.facebook.com</a> and log in with the Facebook account that manages your Page.', 'sasp' ), $allowed_a ),
								'https://developers.facebook.com'
							); ?></li>
							<li><?php esc_html_e( 'Click "Get Started" and complete the developer registration (free, takes about 1 minute).', 'sasp' ); ?></li>
						</ol>
					</div>
				</div>

				<div class="sasp-step-card">
					<div class="sasp-step-num">2</div>
					<div class="sasp-step-body">
						<h3><?php esc_html_e( 'Create a Meta App', 'sasp' ); ?></h3>
						<ol>
							<li><?php esc_html_e( 'In the developer dashboard, click "My Apps" → "Create App".', 'sasp' ); ?></li>
							<li><?php esc_html_e( 'Select "Other" as the use case, then "Business" as the app type.', 'sasp' ); ?></li>
							<li><?php esc_html_e( 'Enter an app name (e.g. "My Store Social Poster") and your contact email, then click "Create App".', 'sasp' ); ?></li>
						</ol>
						<div class="sasp-guide-note">
							<?php esc_html_e( 'You only create the app once. You can reuse it every time you need to renew your token.', 'sasp' ); ?>
						</div>
					</div>
				</div>

				<div class="sasp-step-card">
					<div class="sasp-step-num">3</div>
					<div class="sasp-step-body">
						<h3><?php esc_html_e( 'Get a Page Access Token via Graph API Explorer', 'sasp' ); ?></h3>
						<ol>
							<li><?php printf(
								wp_kses( __( 'Go to the <a href="%s" target="_blank" rel="noopener">Graph API Explorer</a>.', 'sasp' ), $allowed_a ),
								'https://developers.facebook.com/tools/explorer'
							); ?></li>
							<li><?php esc_html_e( 'In the "Meta App" dropdown (top right), select the app you just created.', 'sasp' ); ?></li>
							<li><?php esc_html_e( 'Click the blue "Generate Access Token" button.', 'sasp' ); ?></li>
							<li><?php esc_html_e( 'A permissions dialog appears. Make sure to add these three permissions:', 'sasp' ); ?>
								<ul class="sasp-perm-list">
									<li><code>pages_manage_posts</code> — <?php esc_html_e( 'publish posts to your page', 'sasp' ); ?></li>
									<li><code>pages_read_engagement</code> — <?php esc_html_e( 'read page details', 'sasp' ); ?></li>
									<li><code>pages_show_list</code> — <?php esc_html_e( 'list your pages', 'sasp' ); ?></li>
								</ul>
							</li>
							<li><?php esc_html_e( 'Click "Generate Access Token", log in with your Facebook account, and approve the permissions.', 'sasp' ); ?></li>
							<li><?php esc_html_e( 'You now have a short-lived User Token. In the token field at the top of the Explorer, click the dropdown and switch from "User Token" to your Page name.', 'sasp' ); ?></li>
							<li><?php esc_html_e( 'Copy the token shown — this is your Page Access Token.', 'sasp' ); ?></li>
						</ol>
					</div>
				</div>

				<div class="sasp-step-card">
					<div class="sasp-step-num">4</div>
					<div class="sasp-step-body">
						<h3><?php esc_html_e( 'Extend to a Long-Lived Token (~60 days)', 'sasp' ); ?></h3>
						<p><?php esc_html_e( 'Page tokens from the Explorer expire in about 1 hour. Extend them to ~60 days before saving.', 'sasp' ); ?></p>
						<ol>
							<li><?php printf(
								wp_kses( __( 'Go to the <a href="%s" target="_blank" rel="noopener">Access Token Debugger</a>.', 'sasp' ), $allowed_a ),
								'https://developers.facebook.com/tools/debug/accesstoken'
							); ?></li>
							<li><?php esc_html_e( 'Paste your token and click "Debug".', 'sasp' ); ?></li>
							<li><?php esc_html_e( 'Scroll to the bottom of the page and click "Extend Access Token".', 'sasp' ); ?></li>
							<li><?php esc_html_e( 'Copy the extended token — this is your Long-Lived Page Access Token.', 'sasp' ); ?></li>
						</ol>
						<div class="sasp-guide-warning">
							<?php esc_html_e( '⚠ This token expires in ~60 days. The plugin warns you at 53 days. You must repeat steps 3–4 to renew it.', 'sasp' ); ?>
						</div>
					</div>
				</div>

				<div class="sasp-step-card">
					<div class="sasp-step-num">5</div>
					<div class="sasp-step-body">
						<h3><?php esc_html_e( 'Find your Facebook Page ID', 'sasp' ); ?></h3>
						<p><strong><?php esc_html_e( 'Option A — from your Facebook Page:', 'sasp' ); ?></strong></p>
						<ol>
							<li><?php esc_html_e( 'Go to your Facebook Page → click "About" in the left sidebar.', 'sasp' ); ?></li>
							<li><?php esc_html_e( 'Scroll down — the Page ID is displayed in a grey box.', 'sasp' ); ?></li>
						</ol>
						<p><strong><?php esc_html_e( 'Option B — Graph API Explorer:', 'sasp' ); ?></strong></p>
						<ol>
							<li><?php esc_html_e( 'With your Page Token selected in the Explorer, type:', 'sasp' ); ?>
								<pre class="sasp-code-block">me?fields=id,name</pre>
							</li>
							<li><?php esc_html_e( 'Click ▶. The "id" value is your Page ID.', 'sasp' ); ?></li>
						</ol>
					</div>
				</div>

				<div class="sasp-step-card sasp-step-card-action">
					<div class="sasp-step-num sasp-step-num-done">✓</div>
					<div class="sasp-step-body">
						<h3><?php esc_html_e( 'Enter credentials in the plugin', 'sasp' ); ?></h3>
						<p><?php printf(
							wp_kses( __( 'Go to <a href="%s">Settings → Facebook card</a>, fill in your <strong>Page ID</strong> and long-lived <strong>Page Access Token</strong>, then click "Test Facebook Connection".', 'sasp' ), array_merge( $allowed_a, [ 'strong' => [] ] ) ),
							$settings_url
						); ?></p>
					</div>
				</div>
			</div>

			<!-- ── Part 2: Instagram ─────────────────────────────────────────── -->
			<div class="sasp-guide-section" id="guide-instagram">
				<div class="sasp-section-header sasp-section-ig">
					<span class="sasp-section-emoji">📸</span>
					<div>
						<h2><?php esc_html_e( 'Part 2: Instagram Setup', 'sasp' ); ?></h2>
						<p><?php esc_html_e( 'Link your Instagram Business account to your Facebook Page. Optional — skip if you only need Facebook.', 'sasp' ); ?></p>
					</div>
				</div>

				<div class="sasp-step-card">
					<div class="sasp-step-num">1</div>
					<div class="sasp-step-body">
						<h3><?php esc_html_e( 'Switch to a Business or Creator Account', 'sasp' ); ?></h3>
						<p><?php esc_html_e( 'The Meta API only works with Business or Creator accounts — personal accounts are not supported.', 'sasp' ); ?></p>
						<ol>
							<li><?php esc_html_e( 'In the Instagram app: go to your profile → tap ≡ → Settings and privacy → Account type and tools.', 'sasp' ); ?></li>
							<li><?php esc_html_e( 'Tap "Switch to Professional Account" → choose "Business".', 'sasp' ); ?></li>
							<li><?php esc_html_e( 'Follow the prompts to complete the business profile setup.', 'sasp' ); ?></li>
						</ol>
					</div>
				</div>

				<div class="sasp-step-card">
					<div class="sasp-step-num">2</div>
					<div class="sasp-step-body">
						<h3><?php esc_html_e( 'Connect Instagram to your Facebook Page', 'sasp' ); ?></h3>
						<p><?php esc_html_e( 'Instagram must be linked to the same Facebook Page you set up in Part 1.', 'sasp' ); ?></p>
						<p><strong><?php esc_html_e( 'From Instagram:', 'sasp' ); ?></strong></p>
						<ol>
							<li><?php esc_html_e( 'Profile → Edit Profile → scroll to "Page" → Connect or create a Facebook Page.', 'sasp' ); ?></li>
							<li><?php esc_html_e( 'Select your store\'s Facebook Page and confirm.', 'sasp' ); ?></li>
						</ol>
						<p><strong><?php esc_html_e( 'From Facebook:', 'sasp' ); ?></strong></p>
						<ol>
							<li><?php esc_html_e( 'Go to your Facebook Page → Settings → Instagram.', 'sasp' ); ?></li>
							<li><?php esc_html_e( 'Click "Connect Account" and log in with your Instagram credentials.', 'sasp' ); ?></li>
						</ol>
					</div>
				</div>

				<div class="sasp-step-card">
					<div class="sasp-step-num">3</div>
					<div class="sasp-step-body">
						<h3><?php esc_html_e( 'Find your Instagram Business Account ID', 'sasp' ); ?></h3>
						<ol>
							<li><?php printf(
								wp_kses( __( 'In <a href="%s" target="_blank" rel="noopener">Graph API Explorer</a>, with your Facebook Page Access Token selected, enter:', 'sasp' ), $allowed_a ),
								'https://developers.facebook.com/tools/explorer'
							); ?>
								<pre class="sasp-code-block">YOUR_PAGE_ID?fields=instagram_business_account</pre>
							</li>
							<li><?php esc_html_e( 'Click ▶. You\'ll see a response like:', 'sasp' ); ?>
								<pre class="sasp-code-block">{
  "instagram_business_account": {
    "id": "17841400000000000"
  },
  "id": "123456789012345"
}</pre>
							</li>
							<li><?php esc_html_e( 'Copy the "id" value inside "instagram_business_account" — that is your Instagram Business Account ID.', 'sasp' ); ?></li>
						</ol>
						<div class="sasp-guide-note">
							<?php esc_html_e( 'If the "instagram_business_account" key is missing from the response, Instagram is not yet connected to your Facebook Page. Go back to Step 2.', 'sasp' ); ?>
						</div>
					</div>
				</div>

				<div class="sasp-step-card">
					<div class="sasp-step-num">4</div>
					<div class="sasp-step-body">
						<h3><?php esc_html_e( 'Token for Instagram', 'sasp' ); ?></h3>
						<p><?php esc_html_e( 'You do not need a separate Instagram token. The plugin uses the same Facebook Page Access Token for both platforms.', 'sasp' ); ?></p>
						<p><?php printf(
							wp_kses( __( 'In <a href="%s">Settings → Instagram card</a>: enter the Instagram Business Account ID. Leave the Token field blank — the Facebook token will be used automatically.', 'sasp' ), $allowed_a ),
							$settings_url
						); ?></p>
					</div>
				</div>

				<div class="sasp-step-card sasp-step-card-action">
					<div class="sasp-step-num sasp-step-num-done">✓</div>
					<div class="sasp-step-body">
						<h3><?php esc_html_e( 'Test the Instagram connection', 'sasp' ); ?></h3>
						<p><?php printf(
							wp_kses( __( 'Go to <a href="%s">Settings → Instagram card</a> and click "Test Instagram Connection".', 'sasp' ), $allowed_a ),
							$settings_url
						); ?></p>
					</div>
				</div>
			</div>

			<!-- ── Part 3: Token Renewal ─────────────────────────────────────── -->
			<div class="sasp-guide-section" id="guide-renewal">
				<div class="sasp-section-header sasp-section-renewal">
					<span class="sasp-section-emoji">🔄</span>
					<div>
						<h2><?php esc_html_e( 'Part 3: Token Renewal', 'sasp' ); ?></h2>
						<p><?php esc_html_e( 'Meta tokens expire in ~60 days. Here\'s how to renew them.', 'sasp' ); ?></p>
					</div>
				</div>

				<!-- Token lifetime visual -->
				<div class="sasp-token-timeline">
					<div class="sasp-tl-segment sasp-tl-safe">
						<span><?php esc_html_e( 'Days 0–52', 'sasp' ); ?></span>
						<strong><?php esc_html_e( '✅ Active', 'sasp' ); ?></strong>
					</div>
					<div class="sasp-tl-segment sasp-tl-warn">
						<span><?php esc_html_e( 'Days 53–59', 'sasp' ); ?></span>
						<strong><?php esc_html_e( '⚠ Warning', 'sasp' ); ?></strong>
					</div>
					<div class="sasp-tl-segment sasp-tl-expire">
						<span><?php esc_html_e( 'Day 60+', 'sasp' ); ?></span>
						<strong><?php esc_html_e( '❌ Expired', 'sasp' ); ?></strong>
					</div>
				</div>

				<div class="sasp-step-card">
					<div class="sasp-step-num" style="background:#d97706">!</div>
					<div class="sasp-step-body">
						<h3><?php esc_html_e( 'When you see the expiry warning:', 'sasp' ); ?></h3>
						<ol>
							<li><?php printf( wp_kses( __( 'Go to <a href="%s" target="_blank" rel="noopener">Graph API Explorer</a> → select your app → generate a new User Token with the same permissions.', 'sasp' ), $allowed_a ), 'https://developers.facebook.com/tools/explorer' ); ?></li>
							<li><?php esc_html_e( 'Switch from User Token to your Page in the dropdown, then copy the Page Token.', 'sasp' ); ?></li>
							<li><?php printf( wp_kses( __( 'Open the <a href="%s" target="_blank" rel="noopener">Access Token Debugger</a> → paste the token → click "Extend Access Token".', 'sasp' ), $allowed_a ), 'https://developers.facebook.com/tools/debug/accesstoken' ); ?></li>
							<li><?php printf( wp_kses( __( 'Go to <a href="%s">Settings → Facebook card</a> → paste the new long-lived token → Save Settings.', 'sasp' ), $allowed_a ), $settings_url ); ?></li>
							<li><?php esc_html_e( 'Click "Test Facebook Connection" to confirm.', 'sasp' ); ?></li>
						</ol>
						<div class="sasp-guide-note">
							💡 <?php esc_html_e( 'Set a calendar reminder for 55 days after saving a new token so you never miss the renewal window.', 'sasp' ); ?>
						</div>
					</div>
				</div>
			</div>

			<div class="sasp-guide-footer">
				<a href="<?php echo $settings_url; ?>" class="button button-primary button-hero">
					<?php esc_html_e( '← Back to Settings', 'sasp' ); ?>
				</a>
				<span class="sasp-guide-footer-note">
					<?php esc_html_e( 'Questions? Check the WordPress admin notice when your token is about to expire — the plugin will remind you automatically.', 'sasp' ); ?>
				</span>
			</div>
		</div>
		<?php
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
