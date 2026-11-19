<?php
/**
 * Plugin Name:       Redirect Manager
 * Plugin URI:        https://github.com/saizul/wp-redirect-manager
 * Description:       Lightweight 301/302/307/308/410 redirect manager with exact, prefix and regex matching, a 404 monitor, automatic redirects for changed post URLs, CSV import/export, loop detection and WP-CLI.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Saizul Amin
 * Author URI:        https://saizul.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-redirect-manager
 * Domain Path:       /languages
 *
 * @package Saizul\RedirectManager
 */

defined( 'ABSPATH' ) || exit;

if ( PHP_VERSION_ID < 70400 ) {
	add_action(
		'admin_notices',
		static function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Redirect Manager requires PHP 7.4 or newer.', 'wp-redirect-manager' ) . '</p></div>';
			}
		}
	);
	return;
}

define( 'WP_REDIRECT_MANAGER_FILE', __FILE__ );
define( 'WP_REDIRECT_MANAGER_VERSION', '0.1.0' );

if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	// PSR-4 autoloader for installs without Composer (the release zip).
	spl_autoload_register(
		static function ( $class_name ) {
			$prefix = 'Saizul\\RedirectManager\\';
			if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
				return;
			}
			$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

register_activation_hook( __FILE__, array( '\Saizul\RedirectManager\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Saizul\RedirectManager\Plugin', 'deactivate' ) );

\Saizul\RedirectManager\Plugin::boot( __FILE__ );
