<?php
/**
 * Lookup index of the enabled rules.
 *
 * @package Saizul\RedirectManager
 */

namespace Saizul\RedirectManager;

defined( 'ABSPATH' ) || exit;

/**
 * Exact rules keyed by their normalised path (O(1) lookup), regex rules in ID
 * order and prefix rules longest first. The whole index is cached in one
 * option, so a front-end request needs no extra database query. For very
 * large sites the exact map can be left out of the cache and loaded per
 * request through a callback (one indexed query).
 */
final class Index {

	/**
	 * Compare paths case-insensitively.
	 *
	 * @var bool
	 */
	private $ignore_case;

	/**
	 * Treat `/a/` and `/a` as the same.
	 *
	 * @var bool
	 */
	private $ignore_trailing_slash;

	/**
	 * Exact rules: normalised path => rules.
	 *
	 * @var array<string,array<int,Rule>>
	 */
	private $exact = array();

	/**
	 * Key of every exact rule, by ID.
	 *
	 * @var array<int,string>
	 */
	private $exact_keys = array();

	/**
	 * Regex rules by ID.
	 *
	 * @var array<int,Rule>
	 */
	private $regex = array();

	/**
	 * Prefix rules by ID.
	 *
	 * @var array<int,Rule>
	 */
	private $prefix = array();

	/**
	 * Whether $prefix is sorted longest first.
	 *
	 * @var bool
	 */
	private $prefix_sorted = true;

	/**
	 * Loads exact rules for a key when the map is not in memory.
	 *
	 * @var callable|null
	 */
	private $exact_loader;

	/**
	 * Constructor.
	 *
	 * @param bool $ignore_case           Compare case-insensitively.
	 * @param bool $ignore_trailing_slash Treat `/a/` and `/a` as the same.
	 */
	public function __construct( bool $ignore_case = true, bool $ignore_trailing_slash = true ) {
		$this->ignore_case           = $ignore_case;
		$this->ignore_trailing_slash = $ignore_trailing_slash;
	}

	/**
	 * Build an index from rules. Disabled rules are skipped.
	 *
	 * @param Rule[] $rules                 Rules.
	 * @param bool   $ignore_case           Compare case-insensitively.
	 * @param bool   $ignore_trailing_slash Treat `/a/` and `/a` as the same.
	 */
	public static function build( array $rules, bool $ignore_case = true, bool $ignore_trailing_slash = true ): self {
		$index = new self( $ignore_case, $ignore_trailing_slash );
		foreach ( $rules as $rule ) {
			$index->add( $rule );
		}

		return $index;
	}

	/**
	 * Add or replace a rule.
	 *
	 * @param Rule $rule Rule.
	 */
	public function add( Rule $rule ): void {
		$this->remove( $rule->id );
		if ( ! $rule->enabled ) {
			return;
		}
		if ( Rule::MATCH_REGEX === $rule->match_type ) {
			$this->regex[ $rule->id ] = $rule;
			ksort( $this->regex );
			return;
		}
		if ( Rule::MATCH_PREFIX === $rule->match_type ) {
			$this->prefix[ $rule->id ] = $rule;
			$this->prefix_sorted       = false;
			return;
		}
		$key                           = $this->source_key( $rule->source );
		$this->exact[ $key ][]         = $rule;
		$this->exact_keys[ $rule->id ] = $key;
	}

	/**
	 * Remove a rule by ID.
	 *
	 * @param int $id Rule ID.
	 */
	public function remove( int $id ): void {
		if ( 0 === $id ) {
			return;
		}
		unset( $this->regex[ $id ], $this->prefix[ $id ] );
		if ( isset( $this->exact_keys[ $id ] ) ) {
			$key = $this->exact_keys[ $id ];
			unset( $this->exact_keys[ $id ] );
			$this->exact[ $key ] = array_values(
				array_filter(
					$this->exact[ $key ] ?? array(),
					static function ( Rule $rule ) use ( $id ) {
						return $rule->id !== $id;
					}
				)
			);
			if ( array() === $this->exact[ $key ] ) {
				unset( $this->exact[ $key ] );
			}
		}
	}

	/**
	 * Normalised key of a path.
	 *
	 * @param string $path Path, without query string.
	 */
	public function key( string $path ): string {
		return Path::normalize( $path, $this->ignore_case, $this->ignore_trailing_slash );
	}

	/**
	 * Normalised key of a rule source (exact or prefix): the path part only.
	 *
	 * @param string $source Source, may contain a query string.
	 */
	public function source_key( string $source ): string {
		return $this->key( Path::split( $source )[0] );
	}

	/**
	 * Exact rules for a key.
	 *
	 * @param string $key Normalised path.
	 * @return Rule[]
	 */
	public function exact_for( string $key ): array {
		if ( isset( $this->exact[ $key ] ) ) {
			return $this->exact[ $key ];
		}
		if ( null !== $this->exact_loader ) {
			$rules = call_user_func( $this->exact_loader, $key );
			return is_array( $rules ) ? $rules : array();
		}

		return array();
	}

	/**
	 * Regex rules in ID order.
	 *
	 * @return Rule[]
	 */
	public function regex(): array {
		return array_values( $this->regex );
	}

	/**
	 * Prefix rules, longest source first.
	 *
	 * @return Rule[]
	 */
	public function prefixes(): array {
		if ( ! $this->prefix_sorted ) {
			$lengths = array();
			foreach ( $this->prefix as $id => $rule ) {
				$lengths[ $id ] = strlen( $this->source_key( $rule->source ) );
			}
			uksort(
				$this->prefix,
				static function ( $a, $b ) use ( $lengths ) {
					$by_length = $lengths[ $b ] <=> $lengths[ $a ];
					return 0 !== $by_length ? $by_length : $a <=> $b;
				}
			);
			$this->prefix_sorted = true;
		}

		return array_values( $this->prefix );
	}

	/**
	 * Whether paths are compared case-insensitively.
	 */
	public function ignore_case(): bool {
		return $this->ignore_case;
	}

	/**
	 * Whether trailing slashes are ignored.
	 */
	public function ignore_trailing_slash(): bool {
		return $this->ignore_trailing_slash;
	}

	/**
	 * Number of exact rules held in memory.
	 */
	public function exact_count(): int {
		return count( $this->exact_keys );
	}

	/**
	 * Number of rules held in memory.
	 */
	public function count(): int {
		return count( $this->exact_keys ) + count( $this->regex ) + count( $this->prefix );
	}

	/**
	 * Use a callback for exact lookups instead of the in-memory map.
	 *
	 * @param callable|null $loader Receives the normalised key, returns Rule[].
	 */
	public function set_exact_loader( ?callable $loader ): void {
		$this->exact_loader = $loader;
	}

	/**
	 * Array form for the option cache.
	 *
	 * @param bool $with_exact Include the exact map.
	 * @return array<string,mixed>
	 */
	public function to_array( bool $with_exact = true ): array {
		$pack  = static function ( array $rules ): array {
			return array_values(
				array_map(
					static function ( Rule $rule ) {
						return $rule->to_index();
					},
					$rules
				)
			);
		};
		$exact = array();
		if ( $with_exact ) {
			foreach ( $this->exact as $rules ) {
				foreach ( $rules as $rule ) {
					$exact[] = $rule->to_index();
				}
			}
		}

		return array(
			'version'               => 1,
			'ignore_case'           => $this->ignore_case,
			'ignore_trailing_slash' => $this->ignore_trailing_slash,
			'with_exact'            => $with_exact,
			'exact'                 => $exact,
			'regex'                 => $pack( $this->regex ),
			'prefix'                => $pack( $this->prefix ),
		);
	}

	/**
	 * Restore from the option cache.
	 *
	 * @param mixed $data Cached array.
	 * @return self|null Null when the cache is missing or from another version.
	 */
	public static function from_array( $data ): ?self {
		if ( ! is_array( $data ) || 1 !== ( $data['version'] ?? 0 ) ) {
			return null;
		}
		$index = new self( ! empty( $data['ignore_case'] ), ! empty( $data['ignore_trailing_slash'] ) );
		foreach ( array( 'exact', 'regex', 'prefix' ) as $group ) {
			foreach ( (array) ( $data[ $group ] ?? array() ) as $row ) {
				if ( is_array( $row ) ) {
					$index->add( Rule::from_array( $row ) );
				}
			}
		}

		return $index;
	}

	/**
	 * Whether the cached array holds the exact map.
	 *
	 * @param array<string,mixed> $data Cached array.
	 */
	public static function has_exact( array $data ): bool {
		return ! empty( $data['with_exact'] );
	}
}
