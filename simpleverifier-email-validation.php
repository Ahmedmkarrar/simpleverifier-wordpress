<?php
/**
 * Plugin Name:       SimpleVerifier Email Validation
 * Plugin URI:        https://simpleverifier.com/integrations/wordpress
 * Description:       Checks email addresses on sign-up, comment, WooCommerce checkout and form-plugin submissions with your own SimpleVerifier API key. Fails open if the service is unreachable.
 * Version:           0.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            SimpleVerifier Team
 * Author URI:        https://simpleverifier.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       simpleverifier-email-validation
 * Domain Path:       /languages
 *
 * @package SimpleVerifierEmailValidation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SVEV_VERSION', '0.1.0' );
define( 'SVEV_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-svev-settings.php';
require_once __DIR__ . '/includes/class-svev-client.php';
require_once __DIR__ . '/includes/class-svev-policy.php';
require_once __DIR__ . '/includes/class-svev-hooks.php';
require_once __DIR__ . '/includes/class-svev-admin.php';

add_action(
	'plugins_loaded',
	static function () {
		SVEV_Hooks::register();
		if ( is_admin() ) {
			SVEV_Admin::register();
		}
	}
);
