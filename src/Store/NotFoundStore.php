<?php
/**
 * 404 log storage contract.
 *
 * @package Saizul\RedirectManager
 */

namespace Saizul\RedirectManager\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Storage for the 404 monitor. One row per URL with a hit counter.
 */
interface NotFoundStore {

	/**
	 * Record one 404.
	 *
	 * @param string $url        Request path and query.
	 * @param string $referrer   Referrer or empty.
	 * @param string $user_agent User agent or empty.
	 * @param string $time       `Y-m-d H:i:s` in UTC.
	 */
	public function record( string $url, string $referrer, string $user_agent, string $time ): void;

	/**
	 * One entry.
	 *
	 * @param int $id Entry ID.
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array;

	/**
	 * Page of entries.
	 *
	 * @param array<string,mixed> $args search, orderby (url|hits|last_seen), order, per_page, page.
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	public function query( array $args ): array;

	/**
	 * Delete entries.
	 *
	 * @param int[] $ids Entry IDs.
	 * @return int Rows deleted.
	 */
	public function delete( array $ids ): int;

	/**
	 * Delete entries for a URL (after a redirect was created for it).
	 *
	 * @param string $url Request path and query.
	 */
	public function delete_url( string $url ): void;

	/**
	 * Delete every entry.
	 */
	public function clear(): void;

	/**
	 * Delete entries last seen before `$before`, then the oldest beyond `$max_rows`.
	 *
	 * @param string $before   `Y-m-d H:i:s` in UTC.
	 * @param int    $max_rows Rows to keep at most.
	 * @return int Rows deleted.
	 */
	public function purge( string $before, int $max_rows ): int;
}
