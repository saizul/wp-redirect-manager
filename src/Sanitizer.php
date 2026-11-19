<?php
/**
 * Rule input sanitising and validation.
 *
 * @package Saizul\RedirectManager
 */

namespace Saizul\RedirectManager;

defined( 'ABSPATH' ) || exit;

/**
 * Turns untrusted input (admin form, CSV row, WP-CLI arguments) into a Rule,
 * or a list of error messages.
 */
final class Sanitizer {

	const MAX_LENGTH      = 2000;
	const MAX_NOTE_LENGTH = 255;

	/**
	 * Sanitise and validate rule input.
	 *
	 * @param array<string,mixed> $input Raw input with the keys source, target, status, match_type, query, enabled, note.
	 * @return array{rule:Rule|null,errors:string[]}
	 */
	public static function rule( array $input ): array {
		$errors = array();
		$rule   = new Rule();

		$rule->match_type = self::choice( $input['match_type'] ?? '', Rule::MATCH_TYPES, Rule::MATCH_EXACT );
		$rule->query      = self::choice( $input['query'] ?? '', Rule::QUERY_MODES, Rule::QUERY_IGNORE );
		$enabled          = $input['enabled'] ?? true;
		$rule->enabled    = is_string( $enabled ) && '' === trim( $enabled ) ? true : self::bool( $enabled );
		$rule->note       = self::note( $input['note'] ?? '' );

		$status = self::scalar( $input['status'] ?? '' );
		if ( '' === $status ) {
			$rule->status = 301;
		} elseif ( ctype_digit( $status ) && in_array( (int) $status, Rule::STATUSES, true ) ) {
			$rule->status = (int) $status;
		} else {
			$errors[] = __( 'The status must be 301, 302, 307, 308 or 410.', 'wp-redirect-manager' );
		}

		$source = self::clean( self::scalar( $input['source'] ?? '' ) );
		if ( '' === $source ) {
			$errors[] = __( 'The source is required.', 'wp-redirect-manager' );
		} elseif ( strlen( $source ) > self::MAX_LENGTH ) {
			$errors[] = __( 'The source is too long.', 'wp-redirect-manager' );
		} elseif ( Rule::MATCH_REGEX === $rule->match_type ) {
			if ( ! self::valid_regex( $source ) ) {
				$errors[] = __( 'The source is not a valid regular expression.', 'wp-redirect-manager' );
			}
			$rule->source = $source;
		} else {
			$rule->source = self::source_path( $source, $rule );
		}

		$target = self::clean( self::scalar( $input['target'] ?? '' ) );
		if ( $rule->is_gone() ) {
			$rule->target = '';
		} elseif ( '' === $target ) {
			$errors[] = __( 'The target is required (or use status 410).', 'wp-redirect-manager' );
		} elseif ( strlen( $target ) > self::MAX_LENGTH ) {
			$errors[] = __( 'The target is too long.', 'wp-redirect-manager' );
		} else {
			$clean = self::target( $target );
			if ( '' === $clean ) {
				$errors[] = __( 'The target must be a path starting with "/" or an http(s) URL.', 'wp-redirect-manager' );
			}
			$rule->target = $clean;
		}

		if ( array() === $errors && Rule::MATCH_EXACT === $rule->match_type && self::same_url( $rule->source, $rule->target ) ) {
			$errors[] = __( 'The source and the target are the same URL.', 'wp-redirect-manager' );
		}

		return array(
			'rule'   => array() === $errors ? $rule : null,
			'errors' => $errors,
		);
	}

	/**
	 * Whether a regex compiles. The pattern is used with `#` delimiters.
	 *
	 * @param string $source Pattern without delimiters.
	 */
	public static function valid_regex( string $source ): bool {
		// Compiling an invalid pattern raises a warning; we only need the result.
		return false !== @preg_match( Matcher::pattern( $source, false ), '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Clean a target: relative path starting with "/" or an absolute http(s) URL.
	 * Spaces are encoded; anything else returns an empty string.
	 *
	 * @param string $target Raw target.
	 */
	public static function target( string $target ): string {
		$target = str_replace( ' ', '%20', self::clean( $target ) );
		if ( '' === $target ) {
			return '';
		}
		if ( '/' === $target[0] ) {
			return '/' === ( $target[1] ?? '' ) || '\\' === ( $target[1] ?? '' ) ? '' : $target;
		}
		if ( preg_match( '#^https?://[^/?\#\s\\\\]+#i', $target ) ) {
			return $target;
		}

		return '';
	}

	/**
	 * Normalise an exact or prefix source to a path (and query for exact rules).
	 *
	 * A full URL is reduced to its path. A query string on an exact source
	 * switches the rule to "match the query string exactly"; prefix sources
	 * never keep a query string.
	 *
	 * @param string $source Raw source.
	 * @param Rule   $rule   Rule being built (its query mode may change).
	 */
	private static function source_path( string $source, Rule $rule ): string {
		list( $path, $query ) = Path::split( $source );
		$path                 = str_replace( ' ', '%20', $path );
		if ( Rule::MATCH_PREFIX === $rule->match_type ) {
			if ( Rule::QUERY_EXACT === $rule->query ) {
				$rule->query = Rule::QUERY_IGNORE;
			}
			return $path;
		}
		if ( '' !== $query ) {
			$rule->query = Rule::QUERY_EXACT;
			return $path . '?' . $query;
		}

		return $path;
	}

	/**
	 * Whether a source path and a target are the same local URL.
	 *
	 * @param string $source Source path.
	 * @param string $target Target.
	 */
	private static function same_url( string $source, string $target ): bool {
		if ( '/' !== ( $target[0] ?? '' ) ) {
			return false;
		}
		list( $sp, $sq ) = Path::split( $source );
		list( $tp, $tq ) = Path::split( $target );

		return Path::normalize( $sp, false, false ) === Path::normalize( $tp, false, false ) && Path::query_equals( $sq, $tq );
	}

	/**
	 * One of the allowed values, or the default.
	 *
	 * @param mixed    $value   Raw value.
	 * @param string[] $allowed Allowed values.
	 * @param string   $fallback Default.
	 */
	public static function choice( $value, array $allowed, string $fallback ): string {
		$value = strtolower( self::scalar( $value ) );

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Loose boolean: 1/0, yes/no, true/false, on/off.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		$value = strtolower( self::scalar( $value ) );

		return ! in_array( $value, array( '', '0', 'no', 'false', 'off', 'disabled' ), true );
	}

	/**
	 * Note: plain text, at most 255 characters.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function note( $value ): string {
		$note = sanitize_text_field( self::scalar( $value ) );

		return function_exists( 'mb_substr' ) ? mb_substr( $note, 0, self::MAX_NOTE_LENGTH ) : substr( $note, 0, self::MAX_NOTE_LENGTH );
	}

	/**
	 * Remove control characters (CR/LF would allow header injection) and trim.
	 *
	 * @param string $value Raw value.
	 */
	public static function clean( string $value ): string {
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $value ) );
	}

	/**
	 * String form of a scalar, empty for arrays and objects.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function scalar( $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
