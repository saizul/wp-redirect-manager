<?php
/**
 * URL path helpers shared by matching, sanitising and loop detection.
 *
 * @package Saizul\RedirectManager
 */

namespace Saizul\RedirectManager;

defined( 'ABSPATH' ) || exit;

/**
 * Pure string helpers. No WordPress calls, so everything here is easy to test.
 */
final class Path {

	/**
	 * Split a request URI or URL into path and query string (fragment dropped).
	 *
	 * A leading `//` is kept as part of the path: request URIs can start with
	 * it, so it is not read as a protocol-relative host.
	 *
	 * @param string $uri `/a/b?x=1`, `https://example.org/a?x=1` or `a/b`.
	 * @return array{0:string,1:string} Path (always starting with "/") and query without "?".
	 */
	public static function split( string $uri ): array {
		$uri  = trim( $uri );
		$hash = strpos( $uri, '#' );
		if ( false !== $hash ) {
			$uri = substr( $uri, 0, $hash );
		}
		if ( preg_match( '#^[a-z][a-z0-9+.-]*://[^/?]*#i', $uri, $m ) ) {
			$uri = (string) substr( $uri, strlen( $m[0] ) );
		}
		$query = '';
		$mark  = strpos( $uri, '?' );
		if ( false !== $mark ) {
			$query = (string) substr( $uri, $mark + 1 );
			$uri   = (string) substr( $uri, 0, $mark );
		}
		if ( '' === $uri || '/' !== $uri[0] ) {
			$uri = '/' . $uri;
		}

		return array( $uri, $query );
	}

	/**
	 * Host of an absolute URL, lower case, or empty for relative URLs.
	 *
	 * @param string $url URL.
	 */
	public static function host( string $url ): string {
		if ( preg_match( '#^(?:[a-z][a-z0-9+.-]*:)?//([^/?\#:]+)#i', trim( $url ), $m ) ) {
			return strtolower( $m[1] );
		}

		return '';
	}

	/**
	 * Decoded path used for comparisons: percent-decoded, optionally lower case
	 * and without a trailing slash.
	 *
	 * @param string $path                  Path.
	 * @param bool   $ignore_case           Compare case-insensitively.
	 * @param bool   $ignore_trailing_slash Treat `/a/` and `/a` as the same.
	 */
	public static function normalize( string $path, bool $ignore_case, bool $ignore_trailing_slash ): string {
		$path = rawurldecode( $path );
		if ( '' === $path || '/' !== $path[0] ) {
			$path = '/' . $path;
		}
		$path = (string) preg_replace( '#/{2,}#', '/', $path );
		if ( $ignore_trailing_slash && strlen( $path ) > 1 ) {
			$path = rtrim( $path, '/' );
			if ( '' === $path ) {
				$path = '/';
			}
		}
		if ( $ignore_case ) {
			$path = function_exists( 'mb_strtolower' ) ? mb_strtolower( $path, 'UTF-8' ) : strtolower( $path );
		}

		return $path;
	}

	/**
	 * Query string as a sorted array, for order-insensitive comparison.
	 *
	 * @param string $query Query string without "?".
	 * @return array<string,mixed>
	 */
	public static function query_array( string $query ): array {
		if ( '' === $query ) {
			return array();
		}
		parse_str( $query, $out );
		self::ksort_recursive( $out );

		return $out;
	}

	/**
	 * Whether two query strings carry the same parameters, in any order.
	 *
	 * @param string $a Query string.
	 * @param string $b Query string.
	 */
	public static function query_equals( string $a, string $b ): bool {
		return self::query_array( $a ) === self::query_array( $b );
	}

	/**
	 * Merge the request query into a target URL. Parameters already in the
	 * target win.
	 *
	 * @param string $target Target URL.
	 * @param string $query  Request query string.
	 */
	public static function append_query( string $target, string $query ): string {
		if ( '' === $query ) {
			return $target;
		}
		$fragment = '';
		$hash     = strpos( $target, '#' );
		if ( false !== $hash ) {
			$fragment = substr( $target, $hash );
			$target   = substr( $target, 0, $hash );
		}
		$mark = strpos( $target, '?' );
		if ( false === $mark ) {
			return $target . '?' . $query . $fragment;
		}
		$existing = self::query_array( substr( $target, $mark + 1 ) );
		$extra    = array();
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$name = rawurldecode( (string) strstr( $pair . '=', '=', true ) );
			$base = (string) preg_replace( '/\[.*$/', '', $name );
			if ( ! array_key_exists( $base, $existing ) ) {
				$extra[] = $pair;
			}
		}
		if ( array() === $extra ) {
			return $target . $fragment;
		}
		$glue = '?' === substr( $target, -1 ) || '&' === substr( $target, -1 ) ? '' : '&';

		return $target . $glue . implode( '&', $extra ) . $fragment;
	}

	/**
	 * Join a target URL and the rest of a prefix match: `/new` + `/a/b` = `/new/a/b`.
	 *
	 * @param string $target Target URL, may contain a query string.
	 * @param string $rest   Remaining decoded path.
	 */
	public static function join( string $target, string $rest ): string {
		if ( '' === $rest || '/' === $rest ) {
			return $target;
		}
		$suffix = '';
		$cut    = strcspn( $target, '?#' );
		if ( $cut < strlen( $target ) ) {
			$suffix = substr( $target, $cut );
			$target = substr( $target, 0, $cut );
		}

		return rtrim( $target, '/' ) . '/' . ltrim( self::encode( $rest ), '/' ) . $suffix;
	}

	/**
	 * Percent-encode a decoded path piece again, keeping URL delimiters.
	 *
	 * @param string $value Decoded text.
	 */
	public static function encode( string $value ): string {
		return str_replace(
			array( '%2F', '%3F', '%26', '%3D', '%2B', '%3A', '%40', '%2C', '%3B', '%7E', '%21', '%2A', '%27', '%28', '%29', '%24' ),
			array( '/', '?', '&', '=', '+', ':', '@', ',', ';', '~', '!', '*', "'", '(', ')', '$' ),
			rawurlencode( $value )
		);
	}

	/**
	 * Sort an array by key, recursively.
	 *
	 * @param array<mixed> $data Array, sorted in place.
	 */
	private static function ksort_recursive( array &$data ): void {
		ksort( $data );
		foreach ( $data as &$value ) {
			if ( is_array( $value ) ) {
				self::ksort_recursive( $value );
			}
		}
	}
}
