<?php
/**
 * Plugin settings (one option).
 *
 * @package Saizul\RedirectManager
 */

namespace Saizul\RedirectManager;

defined( 'ABSPATH' ) || exit;

/**
 * Defaults, reading and sanitising of `wp_redirect_manager_settings`.
 */
final class Settings {

	const OPTION = 'wp_redirect_manager_settings';

	/**
	 * Default settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'ignore_case'           => true,
			'ignore_trailing_slash' => true,
			'count_hits'            => true,
			'auto_slug'             => true,
			'log_404'               => true,
			'log_retention_days'    => 30,
			'log_max_rows'          => 10000,
			'log_ignore'            => array( '/wp-content/', '/favicon.ico', '/apple-touch-icon' ),
		);
	}

	/**
	 * Current settings merged with the defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );

		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Sanitise settings input (Settings API callback, WP-CLI).
	 *
	 * @param mixed $input Raw input.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$out      = array();
		foreach ( array( 'ignore_case', 'ignore_trailing_slash', 'count_hits', 'auto_slug', 'log_404' ) as $key ) {
			$out[ $key ] = ! empty( $input[ $key ] ) && Sanitizer::bool( $input[ $key ] );
		}
		$out['log_retention_days'] = self::int_between( $input['log_retention_days'] ?? $defaults['log_retention_days'], 1, 3650 );
		$out['log_max_rows']       = self::int_between( $input['log_max_rows'] ?? $defaults['log_max_rows'], 100, 1000000 );

		$lines = $input['log_ignore'] ?? array();
		if ( is_string( $lines ) ) {
			$lines = preg_split( '/[\r\n]+/', $lines );
		}
		$ignore = array();
		foreach ( (array) $lines as $line ) {
			$line = is_scalar( $line ) ? Sanitizer::clean( (string) $line ) : '';
			if ( '' !== $line && strlen( $line ) <= 200 ) {
				$ignore[] = $line;
			}
		}
		$out['log_ignore'] = array_slice( array_values( array_unique( $ignore ) ), 0, 100 );

		return $out;
	}

	/**
	 * Integer clamped to a range.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $min   Minimum.
	 * @param int   $max   Maximum.
	 */
	private static function int_between( $value, int $min, int $max ): int {
		$value = is_numeric( $value ) ? (int) $value : $min;

		return max( $min, min( $max, $value ) );
	}
}
