<?php
declare(strict_types=1);

namespace Saizul\RedirectManager\Tests\Support;

use Saizul\RedirectManager\Path;
use Saizul\RedirectManager\Rule;
use Saizul\RedirectManager\Store\RuleStore;

/**
 * In-memory rule store with the same behaviour as DbRuleStore.
 */
final class MemoryRuleStore implements RuleStore {

	/** @var array<int,Rule> */
	public $rules = array();

	/** @var int */
	private $next = 1;

	/** @var array<int,string> */
	public $hits = array();

	public function find( int $id ): ?Rule {
		return isset( $this->rules[ $id ] ) ? clone $this->rules[ $id ] : null;
	}

	public function all( bool $enabled_only = false ): array {
		ksort( $this->rules );
		$out = array();
		foreach ( $this->rules as $rule ) {
			if ( ! $enabled_only || $rule->enabled ) {
				$out[] = clone $rule;
			}
		}
		return $out;
	}

	public function exact_by_loose_key( string $loose_key ): array {
		return array_values(
			array_filter(
				$this->all( true ),
				static function ( Rule $rule ) use ( $loose_key ) {
					return Rule::MATCH_EXACT === $rule->match_type && Path::normalize( Path::split( $rule->source )[0], true, true ) === $loose_key;
				}
			)
		);
	}

	public function save( Rule $rule ): Rule {
		if ( $rule->id <= 0 ) {
			$rule->id = $this->next++;
		}
		$this->rules[ $rule->id ] = clone $rule;
		return $rule;
	}

	public function delete( array $ids ): int {
		$count = 0;
		foreach ( $ids as $id ) {
			if ( isset( $this->rules[ (int) $id ] ) ) {
				unset( $this->rules[ (int) $id ] );
				++$count;
			}
		}
		return $count;
	}

	public function set_enabled( array $ids, bool $enabled ): int {
		$count = 0;
		foreach ( $ids as $id ) {
			if ( isset( $this->rules[ (int) $id ] ) ) {
				$this->rules[ (int) $id ]->enabled = $enabled;
				++$count;
			}
		}
		return $count;
	}

	public function reset_hits( array $ids ): int {
		$count = 0;
		foreach ( $ids as $id ) {
			if ( isset( $this->rules[ (int) $id ] ) ) {
				$this->rules[ (int) $id ]->hits     = 0;
				$this->rules[ (int) $id ]->last_hit = '';
				++$count;
			}
		}
		return $count;
	}

	public function record_hit( int $id, string $time ): void {
		if ( isset( $this->rules[ $id ] ) ) {
			++$this->rules[ $id ]->hits;
			$this->rules[ $id ]->last_hit = $time;
			$this->hits[]                 = (string) $id;
		}
	}

	public function query( array $args ): array {
		$items  = $this->all();
		$search = (string) ( $args['search'] ?? '' );
		if ( '' !== $search ) {
			$items = array_values(
				array_filter(
					$items,
					static function ( Rule $rule ) use ( $search ) {
						return false !== stripos( $rule->source . ' ' . $rule->target . ' ' . $rule->note, $search );
					}
				)
			);
		}
		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		return array(
			'items' => array_slice( $items, ( $page - 1 ) * $per_page, $per_page ),
			'total' => count( $items ),
		);
	}
}
