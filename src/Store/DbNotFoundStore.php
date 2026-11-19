<?php
/**
 * 404 log in a custom table.
 *
 * @package Saizul\RedirectManager
 */

namespace Saizul\RedirectManager\Store;

use Saizul\RedirectManager\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom table, written on 404 responses only.

/**
 * `{$wpdb->prefix}redirect_manager_404` table. One row per URL; repeated
 * hits update the counter, the last referrer and the last seen time.
 */
final class DbNotFoundStore implements NotFoundStore {

	const SORTABLE = array( 'url', 'hits', 'last_seen', 'first_seen' );

	/**
	 * {@inheritDoc}
	 *
	 * @param string $url        URL.
	 * @param string $referrer   Referrer.
	 * @param string $user_agent User agent.
	 * @param string $time       Time.
	 */
	public function record( string $url, string $referrer, string $user_agent, string $time ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (url_hash, url, referrer, user_agent, hits, first_seen, last_seen) VALUES (%s, %s, %s, %s, 1, %s, %s)
				ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen),
				referrer = IF(VALUES(referrer) = \'\', referrer, VALUES(referrer)), user_agent = VALUES(user_agent)',
				Schema::not_found_table(),
				sha1( $url ),
				$url,
				$referrer,
				$user_agent,
				$time,
				$time
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id Entry ID.
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::not_found_table(), $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $args Query arguments.
	 */
	public function query( array $args ): array {
		global $wpdb;
		$table    = Schema::not_found_table();
		$search   = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		$orderby  = isset( $args['orderby'] ) && in_array( $args['orderby'], self::SORTABLE, true ) ? (string) $args['orderby'] : 'last_seen';
		$order    = isset( $args['order'] ) && 'asc' === strtolower( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$per_page = max( 1, min( 500, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$like     = '%' . $wpdb->esc_like( $search ) . '%';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $orderby and $order are allowlisted.
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE url LIKE %s OR referrer LIKE %s', $table, $like, $like ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE url LIKE %s OR referrer LIKE %s ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d",
				$table,
				$like,
				$like,
				$per_page,
				( $page - 1 ) * $per_page
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'items' => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int[] $ids Entry IDs.
	 */
	public function delete( array $ids ): int {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( array() === $ids ) {
			return 0;
		}
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in holds only %d placeholders, one per ID.
		$sql = $wpdb->prepare( "DELETE FROM %i WHERE id IN ({$in})", array_merge( array( Schema::not_found_table() ), $ids ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $url URL.
	 */
	public function delete_url( string $url ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE url_hash = %s', Schema::not_found_table(), sha1( $url ) ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function clear(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', Schema::not_found_table() ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $before   Time.
	 * @param int    $max_rows Rows to keep.
	 */
	public function purge( string $before, int $max_rows ): int {
		global $wpdb;
		$table   = Schema::not_found_table();
		$deleted = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE last_seen < %s', $table, $before ) );
		$total   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		if ( $total > $max_rows ) {
			$cutoff = $wpdb->get_var( $wpdb->prepare( 'SELECT last_seen FROM %i ORDER BY last_seen DESC, id DESC LIMIT 1 OFFSET %d', $table, $max_rows - 1 ) );
			if ( null !== $cutoff ) {
				$deleted += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE last_seen < %s', $table, $cutoff ) );
			}
		}

		return $deleted;
	}
}
