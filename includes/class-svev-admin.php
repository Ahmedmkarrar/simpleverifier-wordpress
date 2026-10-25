<?php
/**
 * Settings page under Settings > SimpleVerifier.
 *
 * @package SimpleVerifierEmailValidation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI.
 */
final class SVEV_Admin {

	const PAGE = 'simpleverifier-email-validation';

	/**
	 * Attach admin hooks.
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_action( 'admin_post_svev_test', array( __CLASS__, 'handle_test' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SVEV_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Suggested privacy policy wording.
	 *
	 * @return string
	 */
	public static function privacy_text() {
		return '<p>' . esc_html__( 'When a visitor submits an email address in a protected form, this site sends that address, and nothing else, to SimpleVerifier (simpleverifier.com) to check whether it can receive mail. The request is made from this site\'s server with the site owner\'s own API key. Results are kept on this site for a short time to avoid repeat checks, and the visitor\'s IP address is kept only as a one-way hash to limit abuse. See simpleverifier.com/privacy for how SimpleVerifier handles the address.', 'simpleverifier-email-validation' ) . '</p>';
	}

	/**
	 * Add the settings page.
	 */
	public static function menu() {
		add_options_page(
			__( 'SimpleVerifier', 'simpleverifier-email-validation' ),
			__( 'SimpleVerifier', 'simpleverifier-email-validation' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Register the option.
	 */
	public static function register_setting() {
		register_setting(
			self::PAGE,
			SVEV_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'SVEV_Settings', 'sanitize' ),
				'default'           => SVEV_Settings::defaults(),
			)
		);
	}

	/**
	 * Link to the settings page from the plugins list.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'simpleverifier-email-validation' ) . '</a>' );
		return $links;
	}

	/**
	 * Send one test check on request and report what the API said.
	 */
	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'simpleverifier-email-validation' ) );
		}
		check_admin_referer( 'svev_test' );
		delete_transient( 'svev_backoff' );
		delete_transient( 'svev_c_' . hash( 'sha256', 'hello@example.com' ) );
		$result = SVEV_Client::check( 'hello@example.com' );
		$state  = 'ok' === $result['state'] ? 'connected' : 'failed';
		wp_safe_redirect( add_query_arg( 'svev_test', $state, admin_url( 'options-general.php?page=' . self::PAGE ) ) );
		exit;
	}

	/**
	 * Explain the last API problem in plain words.
	 *
	 * @return string
	 */
	private static function status_notice() {
		$status = get_option( SVEV_Client::STATUS_OPTION, array() );
		if ( ! is_array( $status ) || empty( $status['code'] ) && empty( $status['message'] ) ) {
			return '';
		}
		$code = isset( $status['code'] ) ? (int) $status['code'] : 0;
		if ( 200 === $code ) {
			return '';
		}
		if ( 402 === $code ) {
			$text = __( 'SimpleVerifier answered 402: this API key\'s plan does not include API access (it comes with Pro, Business or Agency), or the plan is not active. While this lasts, forms are not checked and every submission goes through.', 'simpleverifier-email-validation' );
		} elseif ( 401 === $code ) {
			$text = __( 'SimpleVerifier did not accept the API key (401). Forms are not checked until the key is corrected.', 'simpleverifier-email-validation' );
		} else {
			$text = __( 'The last check could not reach SimpleVerifier or got an error. Submissions were let through. This clears on its own once a check succeeds.', 'simpleverifier-email-validation' );
		}
		if ( ! empty( $status['message'] ) ) {
			/* translators: %s: message returned by the API */
			$text .= ' ' . sprintf( __( 'Message: %s', 'simpleverifier-email-validation' ), $status['message'] );
		}
		return $text;
	}

	/**
	 * Print the settings page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s       = SVEV_Settings::all();
		$name    = SVEV_Settings::OPTION;
		$has_key = '' !== $s['api_key'];
		$labels  = array(
			'registration' => __( 'WordPress registration', 'simpleverifier-email-validation' ),
			'comments'     => __( 'Comments (visitors)', 'simpleverifier-email-validation' ),
			'woocommerce'  => __( 'WooCommerce checkout and account registration', 'simpleverifier-email-validation' ),
			'cf7'          => __( 'Contact Form 7', 'simpleverifier-email-validation' ),
			'wpforms'      => __( 'WPForms', 'simpleverifier-email-validation' ),
			'gravityforms' => __( 'Gravity Forms', 'simpleverifier-email-validation' ),
		);
		$actions = array(
			'allow' => __( 'Allow it', 'simpleverifier-email-validation' ),
			'warn'  => __( 'Warn once, then allow if resubmitted', 'simpleverifier-email-validation' ),
			'block' => __( 'Block it', 'simpleverifier-email-validation' ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag.
		$test   = isset( $_GET['svev_test'] ) ? sanitize_key( wp_unslash( $_GET['svev_test'] ) ) : '';
		$notice = self::status_notice();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SimpleVerifier Email Validation', 'simpleverifier-email-validation' ); ?></h1>

			<?php if ( 'connected' === $test ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Connected. The API key works.', 'simpleverifier-email-validation' ); ?></p></div>
			<?php elseif ( 'failed' === $test ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'The test check did not return a result. See the message below.', 'simpleverifier-email-validation' ); ?></p></div>
			<?php endif; ?>
			<?php if ( '' !== $notice ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<p>
				<?php esc_html_e( 'Checks the email address in your forms with your own SimpleVerifier API key. API access comes with the Pro, Business and Agency plans; Starter and Growth do not include it. Each check counts as one API call on your plan.', 'simpleverifier-email-validation' ); ?>
				<a href="https://simpleverifier.com/docs/api" target="_blank" rel="noopener"><?php esc_html_e( 'Pricing and limits', 'simpleverifier-email-validation' ); ?></a>
			</p>
			<p><strong><?php esc_html_e( 'Privacy:', 'simpleverifier-email-validation' ); ?></strong> <?php esc_html_e( 'Only the email address is sent to simpleverifier.com, and only when a visitor submits a protected form. Nothing else about the visitor or your site is sent.', 'simpleverifier-email-validation' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( self::PAGE ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="svev_api_key"><?php esc_html_e( 'API key', 'simpleverifier-email-validation' ); ?></label></th>
						<td>
							<input type="password" id="svev_api_key" name="<?php echo esc_attr( $name ); ?>[api_key]" value="" class="regular-text" autocomplete="off" placeholder="<?php echo $has_key ? esc_attr__( 'Key saved. Paste a new one to replace it.', 'simpleverifier-email-validation' ) : 'sv_live_...'; ?>" />
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to the API keys page */
									esc_html__( 'Create a key at %s. Keep it private. Until a key is saved, nothing is checked.', 'simpleverifier-email-validation' ),
									'<a href="https://simpleverifier.com/api-keys" target="_blank" rel="noopener">simpleverifier.com/api-keys</a>'
								);
								?>
							</p>
							<?php if ( $has_key ) : ?>
								<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[clear_key]" value="1" /> <?php esc_html_e( 'Remove the saved key', 'simpleverifier-email-validation' ); ?></label>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Protect these forms', 'simpleverifier-email-validation' ); ?></th>
						<td>
							<?php foreach ( $labels as $form => $label ) : ?>
								<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[forms][]" value="<?php echo esc_attr( $form ); ?>" <?php checked( in_array( $form, $s['forms'], true ) ); ?> /> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="svev_invalid"><?php esc_html_e( 'Invalid address', 'simpleverifier-email-validation' ); ?></label></th>
						<td>
							<select id="svev_invalid" name="<?php echo esc_attr( $name ); ?>[invalid_action]">
								<?php foreach ( $actions as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['invalid_action'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Invalid means the address will bounce: bad format, no mail server, or a mailbox that does not exist.', 'simpleverifier-email-validation' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="svev_risky"><?php esc_html_e( 'Risky address', 'simpleverifier-email-validation' ); ?></label></th>
						<td>
							<select id="svev_risky" name="<?php echo esc_attr( $name ); ?>[risky_action]">
								<?php foreach ( $actions as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['risky_action'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Risky means delivery could not be confirmed: a catch-all domain, a role address such as info@, a provider that hides mailbox status, or a server that did not answer. Many real people land here, so allowing it is the safe default. Only a result the mail server confirmed is treated as good; an unconfirmed address is never reported as valid.', 'simpleverifier-email-validation' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Disposable addresses', 'simpleverifier-email-validation' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[block_disposable]" value="1" <?php checked( ! empty( $s['block_disposable'] ) ); ?> /> <?php esc_html_e( 'Always block throwaway addresses, whatever the invalid setting says', 'simpleverifier-email-validation' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="svev_cache"><?php esc_html_e( 'Cache results for', 'simpleverifier-email-validation' ); ?></label></th>
						<td><input type="number" id="svev_cache" min="1" max="1440" name="<?php echo esc_attr( $name ); ?>[cache_minutes]" value="<?php echo esc_attr( (string) $s['cache_minutes'] ); ?>" class="small-text" /> <?php esc_html_e( 'minutes (1 to 1440). A repeat of the same address inside this time does not use another API call.', 'simpleverifier-email-validation' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="svev_timeout"><?php esc_html_e( 'Give up after', 'simpleverifier-email-validation' ); ?></label></th>
						<td><input type="number" id="svev_timeout" min="2" max="15" name="<?php echo esc_attr( $name ); ?>[timeout]" value="<?php echo esc_attr( (string) $s['timeout'] ); ?>" class="small-text" /> <?php esc_html_e( 'seconds (2 to 15). A slow mail server can take longer than this; the submission then goes through unchecked.', 'simpleverifier-email-validation' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="svev_msg_invalid"><?php esc_html_e( 'Custom message, invalid', 'simpleverifier-email-validation' ); ?></label></th>
						<td><input type="text" id="svev_msg_invalid" name="<?php echo esc_attr( $name ); ?>[msg_invalid]" value="<?php echo esc_attr( $s['msg_invalid'] ); ?>" class="large-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="svev_msg_risky"><?php esc_html_e( 'Custom message, risky', 'simpleverifier-email-validation' ); ?></label></th>
						<td><input type="text" id="svev_msg_risky" name="<?php echo esc_attr( $name ); ?>[msg_risky]" value="<?php echo esc_attr( $s['msg_risky'] ); ?>" class="large-text" /></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<?php if ( $has_key ) : ?>
				<h2><?php esc_html_e( 'Test the connection', 'simpleverifier-email-validation' ); ?></h2>
				<p><?php esc_html_e( 'Sends one check for hello@example.com. It uses one API call.', 'simpleverifier-email-validation' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="svev_test" />
					<?php wp_nonce_field( 'svev_test' ); ?>
					<?php submit_button( __( 'Test connection', 'simpleverifier-email-validation' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<h2><?php esc_html_e( 'If SimpleVerifier cannot be reached', 'simpleverifier-email-validation' ); ?></h2>
			<p><?php esc_html_e( 'The plugin fails open: on a timeout, an error, a rate limit, a missing plan or a bad key, the submission goes through unchecked. A real visitor is never turned away because of an outage or a billing problem.', 'simpleverifier-email-validation' ); ?></p>
		</div>
		<?php
	}
}
