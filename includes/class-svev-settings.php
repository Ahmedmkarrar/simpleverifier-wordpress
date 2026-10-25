<?php
/**
 * Plugin settings: defaults, access and sanitising.
 *
 * @package SimpleVerifierEmailValidation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings storage.
 */
final class SVEV_Settings {

	const OPTION = 'svev_settings';

	/**
	 * Form types the plugin can protect.
	 *
	 * @return string[]
	 */
	public static function form_types() {
		return array( 'registration', 'comments', 'woocommerce', 'cf7', 'wpforms', 'gravityforms' );
	}

	/**
	 * Default settings. Risky addresses are allowed by default so a real customer is never refused over an unconfirmed result.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'api_key'          => '',
			'invalid_action'   => 'block',
			'risky_action'     => 'allow',
			'block_disposable' => 1,
			'cache_minutes'    => 60,
			'timeout'          => 8,
			'forms'            => self::form_types(),
			'msg_invalid'      => '',
			'msg_risky'        => '',
		);
	}

	/**
	 * All settings merged over the defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	/**
	 * One setting.
	 *
	 * @param string $name Setting name.
	 * @return mixed
	 */
	public static function get( $name ) {
		$all = self::all();
		return isset( $all[ $name ] ) ? $all[ $name ] : null;
	}

	/**
	 * Whether protection is on for a form type.
	 *
	 * @param string $form Form type.
	 * @return bool
	 */
	public static function form_enabled( $form ) {
		$forms = self::get( 'forms' );
		return is_array( $forms ) && in_array( $form, $forms, true );
	}

	/**
	 * Sanitise submitted settings. A blank key field keeps the stored key.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$previous = self::all();
		$actions  = array( 'allow', 'warn', 'block' );

		$key = isset( $input['api_key'] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $input['api_key'] ) : '';
		if ( ! empty( $input['clear_key'] ) ) {
			$key = '';
		} elseif ( '' === $key ) {
			$key = (string) $previous['api_key'];
		}

		$invalid = isset( $input['invalid_action'] ) ? sanitize_key( $input['invalid_action'] ) : 'block';
		$risky   = isset( $input['risky_action'] ) ? sanitize_key( $input['risky_action'] ) : 'allow';
		$forms   = isset( $input['forms'] ) && is_array( $input['forms'] ) ? array_map( 'sanitize_key', $input['forms'] ) : array();

		return array(
			'api_key'          => $key,
			'invalid_action'   => in_array( $invalid, $actions, true ) ? $invalid : 'block',
			'risky_action'     => in_array( $risky, $actions, true ) ? $risky : 'allow',
			'block_disposable' => empty( $input['block_disposable'] ) ? 0 : 1,
			'cache_minutes'    => min( 1440, max( 1, isset( $input['cache_minutes'] ) ? absint( $input['cache_minutes'] ) : 60 ) ),
			'timeout'          => min( 15, max( 2, isset( $input['timeout'] ) ? absint( $input['timeout'] ) : 8 ) ),
			'forms'            => array_values( array_intersect( $forms, self::form_types() ) ),
			'msg_invalid'      => isset( $input['msg_invalid'] ) ? sanitize_text_field( $input['msg_invalid'] ) : '',
			'msg_risky'        => isset( $input['msg_risky'] ) ? sanitize_text_field( $input['msg_risky'] ) : '',
		);
	}
}
