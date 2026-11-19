<?php
/**
 * Redirect loop and chain detection.
 *
 * @package Saizul\RedirectManager
 */

namespace Saizul\RedirectManager;

defined( 'ABSPATH' ) || exit;

/**
 * Follows a rule's target through the other rules, the way a browser would,
 * and reports loops (A -> B -> A) and chains that are too long.
 */
final class LoopDetector {

	const OK       = 'ok';
	const LOOP     = 'loop';
	const TOO_LONG = 'too_long';

	/**
	 * Hops followed before a chain counts as too long.
	 *
	 * @var int
	 */
	private $max_hops;

	/**
	 * Hosts that are this site (lower case).
	 *
	 * @var string[]
	 */
	private $hosts;

	/**
	 * Constructor.
	 *
	 * @param string[] $hosts    Hosts of this site, for example `example.org`.
	 * @param int      $max_hops Maximum hops, at least 1.
	 */
	public function __construct( array $hosts, int $max_hops = 10 ) {
		$this->hosts    = array_values( array_filter( array_map( 'strtolower', $hosts ) ) );
		$this->max_hops = max( 1, $max_hops );
	}

	/**
	 * Check a new or changed rule against an index that already contains it.
	 *
	 * @param Rule  $rule  Rule being saved (must be in $index when enabled).
	 * @param Index $index All enabled rules, including $rule.
	 * @return array{result:string,chain:string[]} Result and the visited URLs.
	 */
	public function check( Rule $rule, Index $index ): array {
		if ( $rule->is_gone() || ! $rule->enabled ) {
			return array(
				'result' => self::OK,
				'chain'  => array(),
			);
		}

		$chain   = array();
		$visited = array();
		if ( Rule::MATCH_REGEX !== $rule->match_type ) {
			$chain[] = $rule->source;
			$visited[ $this->visit_key( $rule->source, $index ) ] = true;
		}

		$url = $rule->target;
		for ( $hop = 0; $hop < $this->max_hops; $hop++ ) {
			$chain[] = $url;
			if ( ! $this->is_local( $url ) ) {
				return array(
					'result' => self::OK,
					'chain'  => $chain,
				);
			}
			$key = $this->visit_key( $url, $index );
			if ( isset( $visited[ $key ] ) ) {
				return array(
					'result' => self::LOOP,
					'chain'  => $chain,
				);
			}
			$visited[ $key ] = true;

			$match = Matcher::match( $index, $this->local_uri( $url ) );
			if ( null === $match ) {
				return array(
					'result' => self::OK,
					'chain'  => $chain,
				);
			}
			if ( $match->rule->id === $rule->id && 0 !== $rule->id ) {
				$chain[] = $match->url;
				return array(
					'result' => $match->rule->is_gone() ? self::OK : self::LOOP,
					'chain'  => $chain,
				);
			}
			if ( $match->rule->is_gone() ) {
				return array(
					'result' => self::OK,
					'chain'  => $chain,
				);
			}
			$url = $match->url;
		}

		return array(
			'result' => self::TOO_LONG,
			'chain'  => $chain,
		);
	}

	/**
	 * Whether a target points at this site (relative path or one of its hosts).
	 *
	 * @param string $url Target.
	 */
	public function is_local( string $url ): bool {
		$host = Path::host( $url );
		if ( '' === $host ) {
			return '' !== $url && ( '/' === $url[0] || false === strpos( $url, ':' ) );
		}

		return in_array( $host, $this->hosts, true );
	}

	/**
	 * Whether a request to `$uri` would be sent straight back to itself. Used at
	 * runtime as a last guard.
	 *
	 * @param string $uri    Request URI.
	 * @param string $target Target URL.
	 * @param Index  $index  Index (for normalisation).
	 */
	public function is_self( string $uri, string $target, Index $index ): bool {
		if ( ! $this->is_local( $target ) ) {
			return false;
		}

		return $this->visit_key( $uri, $index ) === $this->visit_key( $target, $index );
	}

	/**
	 * Path and query of a local URL.
	 *
	 * @param string $url Local URL.
	 */
	private function local_uri( string $url ): string {
		list( $path, $query ) = Path::split( $url );

		return '' === $query ? $path : $path . '?' . $query;
	}

	/**
	 * Key that identifies a URL for the visited set.
	 *
	 * @param string $url   URL.
	 * @param Index  $index Index (for normalisation).
	 */
	private function visit_key( string $url, Index $index ): string {
		list( $path, $query ) = Path::split( $url );

		return $index->key( $path ) . '?' . http_build_query( Path::query_array( $query ) );
	}
}
