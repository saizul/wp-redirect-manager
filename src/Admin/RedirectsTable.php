<?php
/**
 * Redirects list table.
 *
 * @package Saizul\RedirectManager
 */

namespace Saizul\RedirectManager\Admin;

use Saizul\RedirectManager\Rule;
use Saizul\RedirectManager\Store\RuleStore;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Searchable, sortable list of redirects with bulk actions.
 */
final class RedirectsTable extends \WP_List_Table {

	/**
	 * Rule storage.
	 *
	 * @var RuleStore
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param RuleStore $store Rule storage.
	 */
	public function __construct( RuleStore $store ) {
		$this->store = $store;
		parent::__construct(
			array(
				'singular' => 'redirect',
				'plural'   => 'redirects',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'source'     => __( 'Source', 'wp-redirect-manager' ),
			'target'     => __( 'Target', 'wp-redirect-manager' ),
			'status'     => __( 'Status', 'wp-redirect-manager' ),
			'match_type' => __( 'Match', 'wp-redirect-manager' ),
			'hits'       => __( 'Hits', 'wp-redirect-manager' ),
			'last_hit'   => __( 'Last hit', 'wp-redirect-manager' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	protected function get_sortable_columns() {
		return array(
			'source'     => array( 'source', false ),
			'target'     => array( 'target', false ),
			'status'     => array( 'status', false ),
			'match_type' => array( 'match_type', false ),
			'hits'       => array( 'hits', true ),
			'last_hit'   => array( 'last_hit', true ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string,string>
	 */
	protected function get_bulk_actions() {
		return array(
			'enable'     => __( 'Enable', 'wp-redirect-manager' ),
			'disable'    => __( 'Disable', 'wp-redirect-manager' ),
			'reset_hits' => __( 'Reset hits', 'wp-redirect-manager' ),
			'delete'     => __( 'Delete', 'wp-redirect-manager' ),
		);
	}

	/**
	 * Load the current page of rules.
	 */
	public function prepare_items() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$orderby  = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'id';
		$order    = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc';
		$status   = isset( $_GET['status'] ) ? absint( $_GET['status'] ) : 0;
		$match    = isset( $_GET['match_type'] ) ? sanitize_key( wp_unslash( $_GET['match_type'] ) ) : '';
		$enabled  = isset( $_GET['enabled'] ) && in_array( $_GET['enabled'], array( '0', '1' ), true ) ? sanitize_key( wp_unslash( $_GET['enabled'] ) ) : '';
		$per_page = $this->get_items_per_page( 'redirect_manager_per_page', 20 );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result = $this->store->query(
			array(
				'search'     => $search,
				'status'     => $status,
				'match_type' => $match,
				'enabled'    => $enabled,
				'orderby'    => $orderby,
				'order'      => $order,
				'per_page'   => $per_page,
				'page'       => $this->get_pagenum(),
			)
		);

		$this->items           = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'source' );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Message when there are no rules.
	 */
	public function no_items() {
		esc_html_e( 'No redirects yet. Add one above, import a CSV file or create one from the 404 log.', 'wp-redirect-manager' );
	}

	/**
	 * Checkbox column.
	 *
	 * @param Rule $item Rule.
	 */
	protected function column_cb( $item ) {
		return '<input type="checkbox" name="ids[]" value="' . esc_attr( (string) $item->id ) . '" />';
	}

	/**
	 * Source column with row actions.
	 *
	 * @param Rule $item Rule.
	 */
	protected function column_source( $item ) {
		$out = '<code>' . esc_html( $item->source ) . '</code>';
		if ( ! $item->enabled ) {
			$out .= ' <span class="post-state">&mdash; ' . esc_html__( 'Disabled', 'wp-redirect-manager' ) . '</span>';
		}
		if ( '' !== $item->note ) {
			$out .= '<br><span class="description">' . esc_html( $item->note ) . '</span>';
		}
		$actions = array(
			'edit'   => '<a href="' . esc_url( AdminPage::url( array( 'edit' => (string) $item->id ) ) ) . '">' . esc_html__( 'Edit', 'wp-redirect-manager' ) . '</a>',
			'toggle' => '<a href="' . esc_url( AdminPage::row_action_url( $item->enabled ? 'disable' : 'enable', $item->id ) ) . '">' . ( $item->enabled ? esc_html__( 'Disable', 'wp-redirect-manager' ) : esc_html__( 'Enable', 'wp-redirect-manager' ) ) . '</a>',
			'delete' => '<a class="submitdelete" href="' . esc_url( AdminPage::row_action_url( 'delete', $item->id ) ) . '">' . esc_html__( 'Delete', 'wp-redirect-manager' ) . '</a>',
		);

		return $out . $this->row_actions( $actions );
	}

	/**
	 * Target column.
	 *
	 * @param Rule $item Rule.
	 */
	protected function column_target( $item ) {
		if ( $item->is_gone() ) {
			return '<em>' . esc_html__( 'Gone (no target)', 'wp-redirect-manager' ) . '</em>';
		}
		$query = array(
			Rule::QUERY_PASS  => __( 'passes the query string', 'wp-redirect-manager' ),
			Rule::QUERY_EXACT => __( 'query string must match', 'wp-redirect-manager' ),
		);
		$out   = '<code>' . esc_html( $item->target ) . '</code>';
		if ( isset( $query[ $item->query ] ) ) {
			$out .= '<br><span class="description">' . esc_html( $query[ $item->query ] ) . '</span>';
		}

		return $out;
	}

	/**
	 * Other columns.
	 *
	 * @param Rule   $item        Rule.
	 * @param string $column_name Column.
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'status':
				return esc_html( (string) $item->status );
			case 'match_type':
				return esc_html( $item->match_type );
			case 'hits':
				return esc_html( number_format_i18n( $item->hits ) );
			case 'last_hit':
				return '' === $item->last_hit ? '&mdash;' : esc_html( AdminPage::format_time( $item->last_hit ) );
		}

		return '';
	}
}
