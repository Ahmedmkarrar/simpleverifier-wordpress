<?php
/**
 * SimpleVerifier API client with a short cache, local rate limits and fail-open errors.
 *
 * Only the email address is sent, in the JSON body of a POST. The API key goes in the
 * Authorization header. Nothing about the site, the visitor or the form is sent.
 *
 * @package SimpleVerifierEmailValidation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API client.
 */
final class SVEV_Client {

	const STATUS_OPTION = 'svev_api_status';

	/** Calls this site may make per minute. The API allows 120 a minute per key. */
	const SITE_LIMIT_PER_MINUTE = 60;

	/** Uncached checks one visitor address may trigger per ten minutes. */
	const VISITOR_LIMIT_PER_WINDOW = 10;

	/**
	 * API endpoint. Override with define( 'SVEV_API_URL', ... ) in wp-config.php for testing.
	 *
	 * @return string
	 */
	public static function endpoint() {
		return defined( 'SVEV_API_URL' ) ? SVEV_API_URL : 'https://simpleverifier.com/api/v1/verify';
	}

	/**
	 * Check one address.
	 *
	 * Returns state "ok" with a status of valid, risky or invalid, or state "skipped" or
	 * "error" when no verdict is available. Callers treat anything but "ok" as allow.
	 *
	 * @param string $email Email address.
	 * @return array{state:string,status:string,reason:string}
	 */
	public static function check( $email ) {
		$email = strtolower( trim( (string) $email ) );
		$key   = (string) SVEV_Settings::get( 'api_key' );
		if ( '' === $key || ! is_email( $email ) ) {
			return self::result( 'skipped' );
		}

		$cache_key = 'svev_c_' . hash( 'sha256', $email );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['status'] ) ) {
			return self::result( 'ok', $cached['status'], isset( $cached['reason'] ) ? $cached['reason'] : '' );
		}

		if ( get_transient( 'svev_backoff' ) ) {
			return self::result( 'error' );
		}
		if ( ! self::take_slot( 'svev_site_' . gmdate( 'YmdHi' ), self::SITE_LIMIT_PER_MINUTE, 90 ) ) {
			return self::result( 'skipped' );
		}
		if ( ! self::take_slot( 'svev_ip_' . self::visitor_hash(), self::VISITOR_LIMIT_PER_WINDOW, 600 ) ) {
			return self::result( 'skipped' );
		}

		$response = wp_remote_post(
			self::endpoint(),
			array(
				'timeout'    => (int) SVEV_Settings::get( 'timeout' ),
				'user-agent' => 'SimpleVerifier-WordPress/' . SVEV_VERSION,
				'headers'    => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'       => wp_json_encode( array( 'email' => $email ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::record_status( 0, $response->get_error_message() );
			set_transient( 'svev_backoff', 1, 60 );
			return self::result( 'error' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$body = is_array( $body ) ? $body : array();

		if ( 200 !== $code || ! isset( $body['status'] ) || ! in_array( $body['status'], array( 'valid', 'risky', 'invalid' ), true ) ) {
			$message = isset( $body['error'] ) ? sanitize_text_field( (string) $body['error'] ) : '';
			self::record_status( $code, $message );
			$config_problem = in_array( $code, array( 401, 402 ), true );
			$retry          = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			set_transient( 'svev_backoff', 1, $config_problem ? 300 : min( 300, max( 60, $retry ) ) );
			return self::result( 'error' );
		}

		$status = $body['status'];
		$reason = isset( $body['reason'] ) ? substr( sanitize_text_field( (string) $body['reason'] ), 0, 120 ) : '';
		set_transient( $cache_key, array( 'status' => $status, 'reason' => $reason ), max( 1, (int) SVEV_Settings::get( 'cache_minutes' ) ) * MINUTE_IN_SECONDS );
		self::record_status( 200, '' );
		return self::result( 'ok', $status, $reason );
	}

	/**
	 * Build a result array.
	 *
	 * @param string $state  ok, skipped or error.
	 * @param string $status valid, risky or invalid.
	 * @param string $reason Short reason from the API.
	 * @return array{state:string,status:string,reason:string}
	 */
	private static function result( $state, $status = '', $reason = '' ) {
		return array(
			'state'  => $state,
			'status' => $status,
			'reason' => $reason,
		);
	}

	/**
	 * Count one use of a limited resource. Returns false once the limit is reached.
	 *
	 * @param string $key    Transient key.
	 * @param int    $limit  Maximum uses.
	 * @param int    $window Seconds the counter lives.
	 * @return bool
	 */
	private static function take_slot( $key, $limit, $window ) {
		$used = (int) get_transient( $key );
		if ( $used >= $limit ) {
			return false;
		}
		set_transient( $key, $used + 1, $window );
		return true;
	}

	/**
	 * One-way hash of the visitor address, used only as a rate-limit key.
	 *
	 * @return string
	 */
	public static function visitor_hash() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return substr( wp_hash( $ip ), 0, 16 );
	}

	/**
	 * Remember the last API outcome so the settings page can show it.
	 *
	 * @param int    $code    HTTP status, 0 for a transport error.
	 * @param string $message API message or transport error.
	 */
	private static function record_status( $code, $message ) {
		$previous = get_option( self::STATUS_OPTION, array() );
		if ( is_array( $previous ) && isset( $previous['code'] ) && (int) $previous['code'] === $code && ( time() - (int) $previous['time'] ) < HOUR_IN_SECONDS ) {
			return;
		}
		update_option(
			self::STATUS_OPTION,
			array(
				'code'    => $code,
				'message' => substr( $message, 0, 300 ),
				'time'    => time(),
			),
			false
		);
	}
}
