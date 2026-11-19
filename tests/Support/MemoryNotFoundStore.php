<?php
declare(strict_types=1);

namespace Saizul\RedirectManager\Tests\Support;

use Saizul\RedirectManager\Store\NotFoundStore;

/**
 * In-memory 404 log.
 */
final class MemoryNotFoundStore implements NotFoundStore {

	/** @var array<int,array<string,mixed>> */
	public $rows = array();

	/** @var int */
	private $next = 1;

	public function record( string $url, string $referrer, string $user_agent, string $time ): void {
		foreach ( $this->rows as $id => $row ) {
			if ( $row['url'] === $url ) {
				++$this->rows[ $id ]['hits'];
				$this->rows[ $id ]['last_seen']  = $time;
				$this->rows[ $id ]['user_agent'] = $user_agent;
				if ( '' !== $referrer ) {
					$this->rows[ $id ]['referrer'] = $referrer;
				}
				return;
			}
		}
		$id                = $this->next++;
		$this->rows[ $id ] = array(
			'id'         => $id,
			'url'        => $url,
			'referrer'   => $referrer,
			'user_agent' => $user_agent,
			'hits'       => 1,
			'first_seen' => $time,
			'last_seen'  => $time,
		);
	}

	public function find( int $id ): ?array {
		return $this->rows[ $id ] ?? null;
	}

	public function query( array $args ): array {
		return array(
			'items' => array_values( $this->rows ),
			'total' => count( $this->rows ),
		);
	}

	public function delete( array $ids ): int {
		$count = 0;
		foreach ( $ids as $id ) {
			if ( isset( $this->rows[ (int) $id ] ) ) {
				unset( $this->rows[ (int) $id ] );
				++$count;
			}
		}
		return $count;
	}

	public function delete_url( string $url ): void {
		foreach ( $this->rows as $id => $row ) {
			if ( $row['url'] === $url ) {
				unset( $this->rows[ $id ] );
			}
		}
	}

	public function clear(): void {
		$this->rows = array();
	}

	public function purge( string $before, int $max_rows ): int {
		$count = 0;
		foreach ( $this->rows as $id => $row ) {
			if ( $row['last_seen'] < $before ) {
				unset( $this->rows[ $id ] );
				++$count;
			}
		}
		uasort(
			$this->rows,
			static function ( $a, $b ) {
				return strcmp( $b['last_seen'], $a['last_seen'] );
			}
		);
		while ( count( $this->rows ) > $max_rows ) {
			array_pop( $this->rows );
			++$count;
		}
		return $count;
	}
}
