<?php
/**
 * 404 log list table.
 *
 * @package Saizul\RedirectManager
 */

namespace Saizul\RedirectManager\Admin;

use Saizul\RedirectManager\Store\NotFoundStore;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * 404 log with "Create redirect" links.
 */
final class NotFoundTable extends \WP_List_Table {

	/**
	 * 404 log.
	 *
	 * @var NotFoundStore
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param NotFoundStore $store 404 log.
	 */
	public function __construct( NotFoundStore $store ) {
		$this->store = $store;
		parent::__construct(
			array(
				'singular' => 'not_found',
				'plural'   => 'not_founds',
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
			'url'        => __( 'URL', 'wp-redirect-manager' ),
			'hits'       => __( 'Hits', 'wp-redirect-manager' ),
			'referrer'   => __( 'Last referrer', 'wp-redirect-manager' ),
			'last_seen'  => __( 'Last seen', 'wp-redirect-manager' ),
			'first_seen' => __( 'First seen', 'wp-redirect-manager' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	protected function get_sortable_columns() {
		return array(
			'url'        => array( 'url', false ),
			'hits'       => array( 'hits', true ),
			'last_seen'  => array( 'last_seen', true ),
			'first_seen' => array( 'first_seen', true ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string,string>
	 */
	protected function get_bulk_actions() {
		return array(
			'delete' => __( 'Delete', 'wp-redirect-manager' ),
		);
	}

	/**
	 * Load the current page.
	 */
	public function prepare_items() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$orderby  = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'last_seen';
		$order    = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc';
		$per_page = $this->get_items_per_page( 'redirect_manager_404_per_page', 20 );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result = $this->store->query(
			array(
				'search'   => $search,
				'orderby'  => $orderby,
				'order'    => $order,
				'per_page' => $per_page,
				'page'     => $this->get_pagenum(),
			)
		);

		$this->items           = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'url' );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Message when the log is empty.
	 */
	public function no_items() {
		esc_html_e( 'No 404 errors logged.', 'wp-redirect-manager' );
	}

	/**
	 * Checkbox column.
	 *
	 * @param array<string,mixed> $item Entry.
	 */
	protected function column_cb( $item ) {
		return '<input type="checkbox" name="ids[]" value="' . esc_attr( (string) $item['id'] ) . '" />';
	}

	/**
	 * URL column with actions.
	 *
	 * @param array<string,mixed> $item Entry.
	 */
	protected function column_url( $item ) {
		$actions = array(
			'create' => '<a href="' . esc_url( AdminPage::url( array( 'from_404' => (string) $item['id'] ) ) ) . '">' . esc_html__( 'Create redirect', 'wp-redirect-manager' ) . '</a>',
			'delete' => '<a class="submitdelete" href="' . esc_url( AdminPage::row_action_url( 'delete_404', (int) $item['id'] ) ) . '">' . esc_html__( 'Delete', 'wp-redirect-manager' ) . '</a>',
		);

		return '<code>' . esc_html( (string) $item['url'] ) . '</code>' . $this->row_actions( $actions );
	}

	/**
	 * Other columns.
	 *
	 * @param array<string,mixed> $item        Entry.
	 * @param string              $column_name Column.
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'hits':
				return esc_html( number_format_i18n( (int) $item['hits'] ) );
			case 'referrer':
				$referrer = (string) $item['referrer'];
				$agent    = '' === (string) $item['user_agent'] ? '' : '<br><span class="description">' . esc_html( (string) $item['user_agent'] ) . '</span>';
				if ( '' === $referrer ) {
					return '&mdash;' . $agent;
				}
				return '<a href="' . esc_url( $referrer ) . '" rel="noopener noreferrer nofollow" target="_blank">' . esc_html( $referrer ) . '</a>' . $agent;
			case 'last_seen':
			case 'first_seen':
				return esc_html( AdminPage::format_time( (string) $item[ $column_name ] ) );
		}

		return '';
	}
}
