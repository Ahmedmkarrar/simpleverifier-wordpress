<?php
/**
 * Turns an API verdict into allow, warn or block, and the message the visitor sees.
 *
 * @package SimpleVerifierEmailValidation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decision policy.
 */
final class SVEV_Policy {

	/**
	 * Validate an address for a form. Returns an empty string to let it through, or the message to show.
	 *
	 * @param string $email   Email address.
	 * @param string $context Form type, one of SVEV_Settings::form_types().
	 * @return string
	 */
	public static function validate( $email, $context ) {
		$email = trim( (string) $email );
		if ( '' === $email || ! SVEV_Settings::form_enabled( $context ) ) {
			return '';
		}
		if ( ! apply_filters( 'svev_should_validate', true, $email, $context ) ) {
			return '';
		}

		$result = SVEV_Client::check( $email );
		if ( 'ok' !== $result['state'] ) {
			return '';
		}

		$decision = self::decide( $result['status'], $result['reason'] );
		if ( 'allow' === $decision['action'] ) {
			return '';
		}
		if ( 'warn' === $decision['action'] && self::consume_warning( $email ) ) {
			return '';
		}

		$message = self::message( $decision, $result['reason'] );
		return (string) apply_filters( 'svev_rejection_message', $message, $email, $context, $result );
	}

	/**
	 * Decide what to do with a verdict.
	 *
	 * @param string $status valid, risky or invalid.
	 * @param string $reason Reason text from the API.
	 * @return array{action:string,kind:string}
	 */
	public static function decide( $status, $reason ) {
		if ( 'invalid' === $status ) {
			if ( SVEV_Settings::get( 'block_disposable' ) && false !== stripos( $reason, 'disposable' ) ) {
				return array(
					'action' => 'block',
					'kind'   => 'disposable',
				);
			}
			return array(
				'action' => SVEV_Settings::get( 'invalid_action' ),
				'kind'   => 'invalid',
			);
		}
		if ( 'risky' === $status ) {
			return array(
				'action' => SVEV_Settings::get( 'risky_action' ),
				'kind'   => 'risky',
			);
		}
		return array(
			'action' => 'allow',
			'kind'   => 'valid',
		);
	}

	/**
	 * Visitor-facing message. Unconfirmed addresses are never called valid.
	 *
	 * @param array  $decision Output of decide().
	 * @param string $reason   Reason text from the API.
	 * @return string
	 */
	private static function message( $decision, $reason ) {
		if ( 'disposable' === $decision['kind'] ) {
			return __( 'Disposable email addresses are not accepted. Please use a permanent address.', 'simpleverifier-email-validation' );
		}
		if ( 'invalid' === $decision['kind'] ) {
			$custom = (string) SVEV_Settings::get( 'msg_invalid' );
			$text   = '' !== $custom ? $custom : __( 'This email address does not look deliverable. Please check it or use a different one.', 'simpleverifier-email-validation' );
			if ( preg_match( '/did you mean ([A-Za-z0-9.\-]+\.[A-Za-z]{2,})/i', $reason, $match ) ) {
				/* translators: %s: suggested domain such as gmail.com */
				$text .= ' ' . sprintf( __( 'Did you mean %s?', 'simpleverifier-email-validation' ), $match[1] );
			}
			return $text;
		}
		$custom = (string) SVEV_Settings::get( 'msg_risky' );
		$text   = '' !== $custom ? $custom : __( 'We could not confirm that this email address can receive mail. Please check it for typos.', 'simpleverifier-email-validation' );
		if ( 'warn' === $decision['action'] ) {
			$text .= ' ' . __( 'If it is correct, submit the form again to continue.', 'simpleverifier-email-validation' );
		}
		return $text;
	}

	/**
	 * Warn mode: the first submission of an address is stopped, the same visitor sending it again within 15 minutes is let through.
	 *
	 * @param string $email Email address.
	 * @return bool True when this is the repeat submission.
	 */
	private static function consume_warning( $email ) {
		$key = 'svev_w_' . hash( 'sha256', strtolower( $email ) . '|' . SVEV_Client::visitor_hash() );
		if ( get_transient( $key ) ) {
			delete_transient( $key );
			return true;
		}
		set_transient( $key, 1, 15 * MINUTE_IN_SECONDS );
		return false;
	}
}
