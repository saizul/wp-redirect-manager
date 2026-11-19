<?php
/**
 * Rule storage contract.
 *
 * @package Saizul\RedirectManager
 */

namespace Saizul\RedirectManager\Store;

use Saizul\RedirectManager\Rule;

defined( 'ABSPATH' ) || exit;

/**
 * Storage for redirect rules. The database implementation is DbRuleStore;
 * tests use an in-memory one.
 */
interface RuleStore {

	/**
	 * One rule.
	 *
	 * @param int $id Rule ID.
	 */
	public function find( int $id ): ?Rule;

	/**
	 * Every rule, ordered by ID.
	 *
	 * @param bool $enabled_only Only enabled rules.
	 * @return Rule[]
	 */
	public function all( bool $enabled_only = false ): array;

	/**
	 * Enabled exact rules whose loose key (lower case, no trailing slash) is `$loose_key`.
	 *
	 * @param string $loose_key Path normalised with case and trailing slash ignored.
	 * @return Rule[]
	 */
	public function exact_by_loose_key( string $loose_key ): array;

	/**
	 * Insert or update a rule.
	 *
	 * @param Rule $rule Rule; inserted when its ID is 0.
	 * @return Rule The saved rule with its ID.
	 */
	public function save( Rule $rule ): Rule;

	/**
	 * Delete rules.
	 *
	 * @param int[] $ids Rule IDs.
	 * @return int Rows deleted.
	 */
	public function delete( array $ids ): int;

	/**
	 * Enable or disable rules.
	 *
	 * @param int[] $ids     Rule IDs.
	 * @param bool  $enabled New state.
	 * @return int Rows changed.
	 */
	public function set_enabled( array $ids, bool $enabled ): int;

	/**
	 * Reset the hit counter.
	 *
	 * @param int[] $ids Rule IDs.
	 * @return int Rows changed.
	 */
	public function reset_hits( array $ids ): int;

	/**
	 * Count one use of a rule.
	 *
	 * @param int    $id   Rule ID.
	 * @param string $time `Y-m-d H:i:s` in UTC.
	 */
	public function record_hit( int $id, string $time ): void;

	/**
	 * Page of rules for the admin list.
	 *
	 * @param array<string,mixed> $args search, status, match_type, enabled (''|0|1), orderby, order, per_page, page.
	 * @return array{items:Rule[],total:int}
	 */
	public function query( array $args ): array;
}
