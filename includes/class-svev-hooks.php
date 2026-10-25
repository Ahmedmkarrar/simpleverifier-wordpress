<?php
/**
 * Hooks into WordPress core, WooCommerce and form plugins.
 *
 * @package SimpleVerifierEmailValidation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Form integrations. Every callback returns the input untouched unless the policy rejects the address.
 */
final class SVEV_Hooks {

	/**
	 * Attach all integrations. Hooks for plugins that are not installed simply never fire.
	 */
	public static function register() {
		add_filter( 'registration_errors', array( __CLASS__, 'registration' ), 20, 3 );
		add_filter( 'preprocess_comment', array( __CLASS__, 'comment' ), 5 );

		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'woo_checkout' ), 20, 2 );
		add_filter( 'woocommerce_process_registration_errors', array( __CLASS__, 'woo_registration' ), 20, 4 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'woo_blocks_checkout' ), 20, 2 );

		add_filter( 'wpcf7_validate_email', array( __CLASS__, 'cf7' ), 20, 2 );
		add_filter( 'wpcf7_validate_email*', array( __CLASS__, 'cf7' ), 20, 2 );
		add_action( 'wpforms_process_validate_email', array( __CLASS__, 'wpforms' ), 20, 3 );
		add_filter( 'gform_field_validation', array( __CLASS__, 'gravity_forms' ), 20, 4 );

		add_action( 'admin_init', array( __CLASS__, 'privacy_policy_text' ) );
	}

	/**
	 * WordPress registration form.
	 *
	 * @param WP_Error $errors     Registration errors.
	 * @param string   $login      Sanitised username.
	 * @param string   $user_email Submitted email.
	 * @return WP_Error
	 */
	public static function registration( $errors, $login, $user_email ) {
		unset( $login );
		$message = SVEV_Policy::validate( $user_email, 'registration' );
		if ( '' !== $message && $errors instanceof WP_Error ) {
			$errors->add( 'svev_email', esc_html( $message ) );
		}
		return $errors;
	}

	/**
	 * Comment form for visitors who are not logged in.
	 *
	 * @param array $commentdata Comment data.
	 * @return array
	 */
	public static function comment( $commentdata ) {
		$type = isset( $commentdata['comment_type'] ) ? $commentdata['comment_type'] : '';
		if ( is_user_logged_in() || ! in_array( $type, array( '', 'comment' ), true ) || empty( $commentdata['comment_author_email'] ) ) {
			return $commentdata;
		}
		$message = SVEV_Policy::validate( $commentdata['comment_author_email'], 'comments' );
		if ( '' !== $message ) {
			wp_die(
				esc_html( $message ),
				esc_html__( 'Email address not accepted', 'simpleverifier-email-validation' ),
				array(
					'response'  => 200,
					'back_link' => true,
				)
			);
		}
		return $commentdata;
	}

	/**
	 * Classic WooCommerce checkout.
	 *
	 * @param array    $data   Posted checkout data.
	 * @param WP_Error $errors Validation errors.
	 */
	public static function woo_checkout( $data, $errors ) {
		$email = isset( $data['billing_email'] ) ? $data['billing_email'] : '';
		if ( $errors instanceof WP_Error && ! $errors->has_errors() ) {
			$message = SVEV_Policy::validate( $email, 'woocommerce' );
			if ( '' !== $message ) {
				$errors->add( 'svev_email', esc_html( $message ) );
			}
		}
	}

	/**
	 * WooCommerce My Account registration.
	 *
	 * @param WP_Error $validation_error Errors so far.
	 * @param string   $username         Username.
	 * @param string   $password         Password.
	 * @param string   $email            Email.
	 * @return WP_Error
	 */
	public static function woo_registration( $validation_error, $username, $password, $email ) {
		unset( $username, $password );
		$message = SVEV_Policy::validate( $email, 'woocommerce' );
		if ( '' !== $message && $validation_error instanceof WP_Error ) {
			$validation_error->add( 'svev_email', esc_html( $message ) );
		}
		return $validation_error;
	}

	/**
	 * WooCommerce block checkout (Store API).
	 *
	 * @param WC_Order        $order   Order being built.
	 * @param WP_REST_Request $request Checkout request.
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When the address is rejected.
	 */
	public static function woo_blocks_checkout( $order, $request ) {
		if ( ! class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) || ! method_exists( $order, 'get_billing_email' ) ) {
			return;
		}
		unset( $request );
		$message = SVEV_Policy::validate( $order->get_billing_email(), 'woocommerce' );
		if ( '' !== $message ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'svev_email', esc_html( $message ), 400 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	/**
	 * Contact Form 7 email and email* fields.
	 *
	 * @param WPCF7_Validation $result Validation result.
	 * @param WPCF7_FormTag    $tag    Form tag.
	 * @return WPCF7_Validation
	 */
	public static function cf7( $result, $tag ) {
		$name = is_object( $tag ) && isset( $tag->name ) ? $tag->name : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Contact Form 7 verifies its own request.
		$value = '' !== $name && isset( $_POST[ $name ] ) ? sanitize_email( wp_unslash( $_POST[ $name ] ) ) : '';
		if ( '' === $value || ! is_object( $result ) || ! method_exists( $result, 'invalidate' ) ) {
			return $result;
		}
		if ( method_exists( $result, 'get_invalid_fields' ) ) {
			$invalid = $result->get_invalid_fields();
			if ( isset( $invalid[ $name ] ) ) {
				return $result;
			}
		}
		$message = SVEV_Policy::validate( $value, 'cf7' );
		if ( '' !== $message ) {
			$result->invalidate( $tag, $message );
		}
		return $result;
	}

	/**
	 * WPForms email fields.
	 *
	 * @param int   $field_id    Field ID.
	 * @param mixed $field_submit Submitted value (string, or array for confirmation).
	 * @param array $form_data   Form data.
	 */
	public static function wpforms( $field_id, $field_submit, $form_data ) {
		$value = is_array( $field_submit ) ? reset( $field_submit ) : $field_submit;
		if ( ! function_exists( 'wpforms' ) || empty( $form_data['id'] ) || ! is_string( $value ) ) {
			return;
		}
		$wpforms = wpforms();
		if ( ! isset( $wpforms->process ) || ! empty( $wpforms->process->errors[ $form_data['id'] ][ $field_id ] ) ) {
			return;
		}
		$message = SVEV_Policy::validate( $value, 'wpforms' );
		if ( '' !== $message ) {
			$wpforms->process->errors[ $form_data['id'] ][ $field_id ] = $message;
		}
	}

	/**
	 * Gravity Forms email fields.
	 *
	 * @param array    $result Validation result.
	 * @param mixed    $value  Submitted value.
	 * @param array    $form   Form.
	 * @param GF_Field $field  Field.
	 * @return array
	 */
	public static function gravity_forms( $result, $value, $form, $field ) {
		unset( $form );
		$type = is_object( $field ) && isset( $field->type ) ? $field->type : '';
		$text = is_array( $value ) ? reset( $value ) : $value;
		if ( 'email' !== $type || ! is_string( $text ) || ! is_array( $result ) || empty( $result['is_valid'] ) ) {
			return $result;
		}
		$message = SVEV_Policy::validate( $text, 'gravityforms' );
		if ( '' !== $message ) {
			$result['is_valid'] = false;
			$result['message']  = $message;
		}
		return $result;
	}

	/**
	 * Suggested privacy policy text for Settings > Privacy.
	 */
	public static function privacy_policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content( 'SimpleVerifier Email Validation', wp_kses_post( SVEV_Admin::privacy_text() ) );
	}
}
