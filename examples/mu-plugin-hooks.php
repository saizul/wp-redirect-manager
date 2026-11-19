<?php
/**
 * Plugin Name: Redirect Manager tweaks (example)
 * Description: Example must-use plugin showing the Redirect Manager hooks. Copy what you need to wp-content/mu-plugins/.
 *
 * @package Saizul\RedirectManager
 */

defined( 'ABSPATH' ) || exit;

// Let editors manage redirects too.
add_filter(
	'redirect_manager_capability',
	static function () {
		return 'edit_others_pages';
	}
);

// Never redirect a health checker, so it sees the real response.
add_filter(
	'redirect_manager_match',
	static function ( $match, $uri ) {
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		return false !== strpos( $agent, 'HealthCheck' ) ? null : $match;
	},
	10,
	2
);

// Do not log 404s for probes of other CMSs.
add_filter(
	'redirect_manager_log_404',
	static function ( $log, $uri ) {
		foreach ( array( '/administrator/', '/.env', '/phpmyadmin' ) as $probe ) {
			if ( 0 === stripos( $uri, $probe ) ) {
				return false;
			}
		}
		return $log;
	},
	10,
	2
);

// No automatic redirects for the "landing" post type.
add_filter(
	'redirect_manager_auto_redirect',
	static function ( $add, $post ) {
		return 'landing' === $post->post_type ? false : $add;
	},
	10,
	2
);

// Treat the old domain as this site, so loops through it are detected.
add_filter(
	'redirect_manager_local_hosts',
	static function ( $hosts ) {
		$hosts[] = 'old-domain.example';
		return $hosts;
	}
);

// Write every redirect to the PHP error log while debugging.
add_action(
	'redirect_manager_redirect',
	static function ( $match, $uri ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( 'redirect %s -> %s (%d, rule %d)', $uri, $match->url, $match->status, $match->rule->id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	},
	10,
	2
);
