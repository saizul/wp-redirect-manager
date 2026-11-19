<?php
/**
 * Matching engine.
 *
 * @package Saizul\RedirectManager
 */

namespace Saizul\RedirectManager;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the rule for a request URI and builds the target URL.
 *
 * Order: exact rules (rules that also match the query string first), then
 * regex rules in ID order, then prefix rules, longest first.
 */
final class Matcher {

	/**
	 * Find the rule for a request URI.
	 *
	 * @param Index  $index Rules.
	 * @param string $uri   Request URI, for example `/old-page/?utm_source=x`.
	 */
	public static function match( Index $index, string $uri ): ?MatchResult {
		list( $path, $query ) = Path::split( $uri );
		$key                  = $index->key( $path );

		$candidates = $index->exact_for( $key );
		foreach ( $candidates as $rule ) {
			if ( Rule::QUERY_EXACT === $rule->query && Path::query_equals( Path::split( $rule->source )[1], $query ) ) {
				return self::result( $rule, $rule->target, $query );
			}
		}
		foreach ( $candidates as $rule ) {
			if ( Rule::QUERY_EXACT !== $rule->query ) {
				return self::result( $rule, $rule->target, $query );
			}
		}

		$decoded = (string) preg_replace( '#/{2,}#', '/', rawurldecode( $path ) );
		foreach ( $index->regex() as $rule ) {
			$subject = $decoded;
			if ( Rule::QUERY_EXACT === $rule->query && '' !== $query ) {
				$subject .= '?' . rawurldecode( $query );
			}
			$pattern = self::pattern( $rule->source, $index->ignore_case() );
			// The pattern was validated on save; a runtime failure (backtrack limit) is a miss.
			if ( 1 === @preg_match( $pattern, $subject, $groups ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return self::result( $rule, self::substitute( $rule->target, $groups ), $query );
			}
		}

		foreach ( $index->prefixes() as $rule ) {
			$prefix = $index->source_key( $rule->source );
			$rest   = self::prefix_rest( $key, $prefix, $decoded, $index->ignore_case() );
			if ( null !== $rest ) {
				return self::result( $rule, Path::join( $rule->target, $rest ), $query );
			}
		}

		return null;
	}

	/**
	 * PCRE pattern for a rule source: `#` delimiters, `i` flag when paths are
	 * compared case-insensitively. Patterns are not anchored automatically.
	 *
	 * @param string $source      Regex source as typed, without delimiters.
	 * @param bool   $ignore_case Add the `i` flag.
	 */
	public static function pattern( string $source, bool $ignore_case ): string {
		$escaped = (string) preg_replace( '/(?<!\\\\)#/', '\\#', $source );

		return '#' . $escaped . '#' . ( $ignore_case ? 'i' : '' );
	}

	/**
	 * Replace `$1`, `${1}` (and `$0`) in a target with captured groups. Values
	 * are percent-encoded again, keeping `/`.
	 *
	 * @param string                   $target Target with placeholders.
	 * @param array<int|string,string> $groups Groups from preg_match().
	 */
	public static function substitute( string $target, array $groups ): string {
		return (string) preg_replace_callback(
			'/\$(?:\{(\d{1,2})\}|(\d{1,2}))/',
			static function ( array $m ) use ( $groups ) {
				$n = (int) ( '' !== $m[1] ? $m[1] : $m[2] );
				return isset( $groups[ $n ] ) ? Path::encode( (string) $groups[ $n ] ) : '';
			},
			$target
		);
	}

	/**
	 * Rest of the decoded path after a prefix, or null when it does not match.
	 * Prefixes match whole segments: `/blog` matches `/blog` and `/blog/x`,
	 * not `/blogging`.
	 *
	 * @param string $key         Normalised request path.
	 * @param string $prefix      Normalised prefix.
	 * @param string $decoded     Decoded request path (original case, trailing slash kept).
	 * @param bool   $ignore_case Paths are compared case-insensitively.
	 */
	public static function prefix_rest( string $key, string $prefix, string $decoded, bool $ignore_case ): ?string {
		if ( '/' === $prefix ) {
			return $decoded;
		}
		$length = strlen( $prefix );
		if ( 0 !== strncmp( $key, $prefix, $length ) ) {
			return null;
		}
		$boundary = strlen( $key ) === $length || '/' === substr( $prefix, -1 ) || '/' === $key[ $length ];
		if ( ! $boundary ) {
			return null;
		}
		if ( $ignore_case && function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $decoded, mb_strlen( $prefix, 'UTF-8' ), null, 'UTF-8' );
		}

		return (string) substr( $decoded, $length );
	}

	/**
	 * Build the result, applying the query string mode.
	 *
	 * @param Rule   $rule   Rule.
	 * @param string $target Target with placeholders replaced.
	 * @param string $query  Request query string.
	 */
	private static function result( Rule $rule, string $target, string $query ): MatchResult {
		if ( ! $rule->is_gone() && Rule::QUERY_PASS === $rule->query ) {
			$target = Path::append_query( $target, $query );
		}

		return new MatchResult( $rule, $rule->is_gone() ? '' : $target );
	}
}
