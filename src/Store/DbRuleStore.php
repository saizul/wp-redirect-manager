<?php
/**
 * Rule storage in a custom table.
 *
 * @package Saizul\RedirectManager
 */

namespace Saizul\RedirectManager\Store;

use Saizul\RedirectManager\Path;
use Saizul\RedirectManager\Rule;
use Saizul\RedirectManager\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom table; lookups are cached in the index option by Redirects.

/**
 * `{$wpdb->prefix}redirect_manager` table.
 */
final class DbRuleStore implements RuleStore {

	/**
	 * Columns that can be sorted in the admin list.
	 */
	const SORTABLE = array( 'id', 'source', 'target', 'status', 'hits', 'last_hit', 'match_type' );

	/**
	 * Loose lookup key stored in the `source_key` column.
	 *
	 * @param string $source Exact or prefix source.
	 */
	public static function loose_key( string $source ): string {
		return sha1( Path::normalize( Path::split( $source )[0], true, true ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id Rule ID.
	 */
	public function find( int $id ): ?Rule {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::rules_table(), $id ), ARRAY_A );

		return is_array( $row ) ? self::rule( $row ) : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param bool $enabled_only Only enabled rules.
	 */
	public function all( bool $enabled_only = false ): array {
		global $wpdb;
		$table = Schema::rules_table();
		$rows  = $enabled_only
			? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE enabled = 1 ORDER BY id ASC', $table ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC', $table ), ARRAY_A );

		return array_map( array( self::class, 'rule' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $loose_key Normalised path.
	 */
	public function exact_by_loose_key( string $loose_key ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE source_key = %s AND enabled = 1 AND match_type = %s ORDER BY id ASC',
				Schema::rules_table(),
				sha1( $loose_key ),
				Rule::MATCH_EXACT
			),
			ARRAY_A
		);

		return array_map( array( self::class, 'rule' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Rule $rule Rule.
	 */
	public function save( Rule $rule ): Rule {
		global $wpdb;
		$now    = gmdate( 'Y-m-d H:i:s' );
		$data   = array(
			'source'     => $rule->source,
			'source_key' => Rule::MATCH_REGEX === $rule->match_type ? '' : self::loose_key( $rule->source ),
			'target'     => $rule->target,
			'status'     => $rule->status,
			'match_type' => $rule->match_type,
			'query_mode' => $rule->query,
			'enabled'    => $rule->enabled ? 1 : 0,
			'note'       => $rule->note,
			'origin'     => $rule->origin,
			'updated_at' => $now,
		);
		$format = array( '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s' );
		if ( $rule->id > 0 ) {
			$wpdb->update( Schema::rules_table(), $data, array( 'id' => $rule->id ), $format, array( '%d' ) );
			return $rule;
		}
		$data['created_at'] = $now;
		$data['hits']       = $rule->hits;
		$format[]           = '%s';
		$format[]           = '%d';
		$wpdb->insert( Schema::rules_table(), $data, $format );
		$rule->id = (int) $wpdb->insert_id;

		return $rule;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int[] $ids Rule IDs.
	 */
	public function delete( array $ids ): int {
		global $wpdb;
		$ids = self::ids( $ids );
		if ( array() === $ids ) {
			return 0;
		}
		$in = self::in( $ids );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %d placeholders, one per ID.
		$sql = $wpdb->prepare( "DELETE FROM %i WHERE id IN ({$in})", array_merge( array( Schema::rules_table() ), $ids ) );

		return (int) $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int[] $ids     Rule IDs.
	 * @param bool  $enabled New state.
	 */
	public function set_enabled( array $ids, bool $enabled ): int {
		global $wpdb;
		$ids = self::ids( $ids );
		if ( array() === $ids ) {
			return 0;
		}
		$in = self::in( $ids );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %d placeholders, one per ID.
		$sql = $wpdb->prepare( "UPDATE %i SET enabled = %d, updated_at = %s WHERE id IN ({$in})", array_merge( array( Schema::rules_table(), $enabled ? 1 : 0, gmdate( 'Y-m-d H:i:s' ) ), $ids ) );

		return (int) $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int[] $ids Rule IDs.
	 */
	public function reset_hits( array $ids ): int {
		global $wpdb;
		$ids = self::ids( $ids );
		if ( array() === $ids ) {
			return 0;
		}
		$in = self::in( $ids );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %d placeholders, one per ID.
		$sql = $wpdb->prepare( "UPDATE %i SET hits = 0, last_hit = NULL WHERE id IN ({$in})", array_merge( array( Schema::rules_table() ), $ids ) );

		return (int) $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $id   Rule ID.
	 * @param string $time Time.
	 */
	public function record_hit( int $id, string $time ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET hits = hits + 1, last_hit = %s WHERE id = %d', Schema::rules_table(), $time, $id ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $args Query arguments.
	 */
	public function query( array $args ): array {
		global $wpdb;
		$table  = Schema::rules_table();
		$where  = array( '1=1' );
		$params = array();
		$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(source LIKE %s OR target LIKE %s OR note LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %d';
			$params[] = (int) $args['status'];
		}
		if ( ! empty( $args['match_type'] ) && in_array( $args['match_type'], Rule::MATCH_TYPES, true ) ) {
			$where[]  = 'match_type = %s';
			$params[] = (string) $args['match_type'];
		}
		if ( isset( $args['enabled'] ) && '' !== $args['enabled'] ) {
			$where[]  = 'enabled = %d';
			$params[] = (int) $args['enabled'];
		}
		$orderby  = isset( $args['orderby'] ) && in_array( $args['orderby'], self::SORTABLE, true ) ? (string) $args['orderby'] : 'id';
		$order    = isset( $args['order'] ) && 'asc' === strtolower( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$per_page = max( 1, min( 500, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$sql      = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $sql holds only placeholders, one per value in $params; $orderby and $order are allowlisted.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$sql}", array_merge( array( $table ), $params ) ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE {$sql} ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d",
				array_merge( array( $table ), $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return array(
			'items' => array_map( array( self::class, 'rule' ), is_array( $rows ) ? $rows : array() ),
			'total' => $total,
		);
	}

	/**
	 * Rule from a database row.
	 *
	 * @param array<string,mixed> $row Row.
	 */
	public static function rule( array $row ): Rule {
		$row['query']    = $row['query_mode'] ?? Rule::QUERY_IGNORE;
		$row['last_hit'] = $row['last_hit'] ?? '';

		return Rule::from_array( $row );
	}

	/**
	 * Positive integer IDs.
	 *
	 * @param array<mixed> $ids IDs.
	 * @return int[]
	 */
	private static function ids( array $ids ): array {
		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	/**
	 * `%d` placeholders for an IN list.
	 *
	 * @param int[] $ids IDs.
	 */
	private static function in( array $ids ): string {
		return implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	}
}
