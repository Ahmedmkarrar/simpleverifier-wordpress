<?php
/**
 * Smoke test with stubbed WordPress functions. Run: php tests/smoke.php
 * Covers the decision policy, cache, fail-open errors, rate limits and the form hooks.
 * It does not replace a test on a real WordPress install.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['opts']      = array();
$GLOBALS['transient'] = array();
$GLOBALS['api_calls'] = array();
$GLOBALS['api_reply'] = array( 200, array( 'status' => 'valid', 'reason' => 'Mailbox exists' ) );
$GLOBALS['filters']   = array();

class WP_Error {
	public $codes = array();
	public function __construct( $c = '', $m = '' ) { if ( $c ) { $this->codes[ $c ] = $m; } }
	public function add( $c, $m ) { $this->codes[ $c ] = $m; }
	public function has_errors() { return (bool) $this->codes; }
	public function get_error_message() { return reset( $this->codes ); }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function __( $t ) { return $t; }
function esc_html( $t ) { return $t; }
function esc_html__( $t ) { return $t; }
function sanitize_text_field( $t ) { return trim( strip_tags( (string) $t ) ); }
function sanitize_key( $t ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $t ) ); }
function sanitize_email( $t ) { return trim( (string) $t ); }
function wp_unslash( $t ) { return $t; }
function absint( $t ) { return abs( (int) $t ); }
function wp_parse_args( $a, $d ) { return array_merge( $d, $a ); }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function wp_hash( $s ) { return hash( 'sha256', 'salt' . $s ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['transient'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transient'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transient'][ $k ] ); return true; }
function apply_filters( $n, $v ) { return $v; }
function add_action() {}
function add_filter() {}
function is_user_logged_in() { return false; }
function wp_remote_post( $url, $args ) {
	$GLOBALS['api_calls'][] = array( $url, $args );
	list( $code, $body ) = $GLOBALS['api_reply'];
	if ( 'timeout' === $code ) { return new WP_Error( 'timeout', 'cURL error 28' ); }
	return array( 'code' => $code, 'body' => json_encode( $body ) );
}
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_retrieve_header( $r, $h ) { return ''; }

$root = dirname( __DIR__ );
define( 'SVEV_VERSION', '0.1.0' );
define( 'SVEV_FILE', $root . '/simpleverifier-email-validation.php' );
foreach ( array( 'settings', 'client', 'policy', 'hooks' ) as $f ) {
	require $root . "/includes/class-svev-$f.php";
}
class SVEV_Admin { public static function privacy_text() { return ''; } }

$fail = 0;
function check( $name, $ok ) { global $fail; echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n"; if ( ! $ok ) { $fail++; } }
function reset_state( $settings = array() ) {
	$GLOBALS['transient'] = array();
	$GLOBALS['api_calls'] = array();
	$GLOBALS['opts']      = array( 'svev_settings' => array_merge( array( 'api_key' => 'sv_live_test' ), $settings ) );
	$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
}
function reply( $code, $body ) { $GLOBALS['api_reply'] = array( $code, $body ); }

// 1. valid passes, only the email is sent.
reset_state(); reply( 200, array( 'status' => 'valid', 'reason' => 'Mailbox exists' ) );
check( 'valid passes', '' === SVEV_Policy::validate( 'Jane@Example.com', 'cf7' ) );
$call = $GLOBALS['api_calls'][0];
check( 'body is only the lowercased email', '{"email":"jane@example.com"}' === $call[1]['body'] );
check( 'key sent as bearer header', 'Bearer sv_live_test' === $call[1]['headers']['Authorization'] );
check( 'user agent has no site data', 'SimpleVerifier-WordPress/0.1.0' === $call[1]['user-agent'] );

// 2. cache: second check of same address makes no call.
SVEV_Policy::validate( 'jane@example.com', 'cf7' );
check( 'cached repeat makes no second call', 1 === count( $GLOBALS['api_calls'] ) );

// 3. invalid blocks by default.
reset_state(); reply( 200, array( 'status' => 'invalid', 'reason' => 'Mailbox not found' ) );
check( 'invalid blocked', '' !== SVEV_Policy::validate( 'x@example.com', 'cf7' ) );

// 4. risky allowed by default, never called valid in message.
reset_state(); reply( 200, array( 'status' => 'risky', 'reason' => 'Accepts all addresses' ) );
check( 'risky allowed by default', '' === SVEV_Policy::validate( 'x@example.com', 'cf7' ) );
reset_state( array( 'risky_action' => 'block' ) );
$msg = SVEV_Policy::validate( 'x@example.com', 'cf7' );
check( 'risky block message says could not confirm', false !== strpos( $msg, 'could not confirm' ) && false === stripos( $msg, ' is valid' ) );

// 5. warn: first stops, resubmit passes.
reset_state( array( 'risky_action' => 'warn' ) );
check( 'warn stops first submit', '' !== SVEV_Policy::validate( 'x@example.com', 'cf7' ) );
check( 'warn lets resubmit through', '' === SVEV_Policy::validate( 'x@example.com', 'cf7' ) );
check( 'warn stops again after use', '' !== SVEV_Policy::validate( 'x@example.com', 'cf7' ) );

// 6. disposable always blocked even when invalid is allow.
reset_state( array( 'invalid_action' => 'allow' ) ); reply( 200, array( 'status' => 'invalid', 'reason' => 'Disposable address' ) );
check( 'disposable blocked despite allow', '' !== SVEV_Policy::validate( 'x@mailinator.com', 'cf7' ) );
reset_state( array( 'invalid_action' => 'allow', 'block_disposable' => 0 ) );
check( 'disposable allowed when toggle off', '' === SVEV_Policy::validate( 'x@mailinator.com', 'cf7' ) );

// 7. typo suggestion appended.
reset_state(); reply( 200, array( 'status' => 'invalid', 'reason' => 'Typo domain - did you mean gmail.com?' ) );
check( 'typo suggestion shown', false !== strpos( SVEV_Policy::validate( 'x@gmial.com', 'cf7' ), 'gmail.com' ) );

// 8. fail open on every error shape.
foreach ( array( array( 'timeout', array() ), array( 503, array( 'error' => 'down' ) ), array( 429, array( 'error' => 'slow' ) ), array( 401, array( 'error' => 'bad key' ) ), array( 402, array( 'error' => 'Starter and Growth cover CSV only' ) ), array( 200, array( 'junk' => 1 ) ) ) as $case ) {
	reset_state(); reply( $case[0], $case[1] );
	check( "fail open on {$case[0]}", '' === SVEV_Policy::validate( 'x@example.com', 'cf7' ) );
}
reset_state(); reply( 402, array( 'error' => 'Starter and Growth cover CSV only' ) );
SVEV_Policy::validate( 'x@example.com', 'cf7' );
$status = get_option( 'svev_api_status' );
check( '402 recorded for admin notice', 402 === $status['code'] );
SVEV_Policy::validate( 'y@example.com', 'cf7' );
check( 'backoff stops hammering after an error', 1 === count( $GLOBALS['api_calls'] ) );

// 9. no key, bad syntax, form off: no call.
reset_state( array( 'api_key' => '' ) );
check( 'no key allows, no call', '' === SVEV_Policy::validate( 'x@example.com', 'cf7' ) && 0 === count( $GLOBALS['api_calls'] ) );
reset_state(); SVEV_Policy::validate( 'not-an-email', 'cf7' );
check( 'bad syntax makes no call', 0 === count( $GLOBALS['api_calls'] ) );
reset_state( array( 'forms' => array( 'comments' ) ) );
SVEV_Policy::validate( 'x@example.com', 'cf7' );
check( 'disabled form makes no call', 0 === count( $GLOBALS['api_calls'] ) );

// 10. per-visitor rate limit: 11th distinct address is not checked.
reset_state(); reply( 200, array( 'status' => 'invalid', 'reason' => 'Mailbox not found' ) );
$blocked = 0;
for ( $i = 1; $i <= 12; $i++ ) { if ( '' !== SVEV_Policy::validate( "u$i@example.com", 'cf7' ) ) { $blocked++; } }
check( 'visitor limited to 10 uncached checks', 10 === count( $GLOBALS['api_calls'] ) && 10 === $blocked );

// 11. hooks.
reset_state(); reply( 200, array( 'status' => 'invalid', 'reason' => 'Mailbox not found' ) );
$errors = SVEV_Hooks::registration( new WP_Error(), 'bob', 'bob@example.com' );
check( 'registration adds error', $errors->has_errors() );
reset_state(); reply( 200, array( 'status' => 'invalid', 'reason' => 'Mailbox not found' ) );
$wc = new WP_Error(); SVEV_Hooks::woo_checkout( array( 'billing_email' => 'bob@example.com' ), $wc );
check( 'woo checkout adds error', $wc->has_errors() );
reset_state(); reply( 200, array( 'status' => 'invalid', 'reason' => 'Mailbox not found' ) );
$gf = SVEV_Hooks::gravity_forms( array( 'is_valid' => true, 'message' => '' ), 'bob@example.com', array(), (object) array( 'type' => 'email' ) );
check( 'gravity forms invalidates', false === $gf['is_valid'] );
$gf = SVEV_Hooks::gravity_forms( array( 'is_valid' => true, 'message' => '' ), 'bob@example.com', array(), (object) array( 'type' => 'text' ) );
check( 'gravity forms ignores non-email field', true === $gf['is_valid'] );
class FakeCf7Result { public $bad = array(); public function invalidate( $t, $m ) { $this->bad[ $t->name ] = $m; } public function get_invalid_fields() { return $this->bad; } }
reset_state(); reply( 200, array( 'status' => 'invalid', 'reason' => 'Mailbox not found' ) );
$_POST['your-email'] = 'bob@example.com';
$r = SVEV_Hooks::cf7( new FakeCf7Result(), (object) array( 'name' => 'your-email' ) );
check( 'cf7 invalidates field', isset( $r->bad['your-email'] ) );

// 12. sanitizer.
$_s = SVEV_Settings::sanitize( array( 'api_key' => " sv_live_<b>abc\n", 'invalid_action' => 'nuke', 'cache_minutes' => 99999, 'timeout' => 0, 'forms' => array( 'cf7', 'evil' ) ) );
check( 'sanitize strips key junk', 'sv_live_babc' === $_s['api_key'] );
check( 'sanitize clamps and whitelists', 'block' === $_s['invalid_action'] && 1440 === $_s['cache_minutes'] && 2 === $_s['timeout'] && array( 'cf7' ) === $_s['forms'] );
reset_state();
$_s = SVEV_Settings::sanitize( array( 'api_key' => '' ) );
check( 'blank key field keeps stored key', 'sv_live_test' === $_s['api_key'] );

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
