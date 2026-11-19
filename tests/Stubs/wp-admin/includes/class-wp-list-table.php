<?php
/**
 * Minimal WP_List_Table stub: enough to prepare items and render rows.
 *
 * @package Saizul\RedirectManager
 */

// phpcs:ignoreFile

class WP_List_Table {
	/** @var array<int,mixed> */
	public $items = array();
	/** @var array<int,mixed> */
	protected $_column_headers = array();
	/** @var array<string,mixed> */
	protected $_args = array();
	/** @var array<string,int> */
	public $pagination = array();

	public function __construct( $args = array() ) {
		$this->_args = $args;
	}

	public function get_pagenum() {
		return isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
	}

	protected function get_items_per_page( $option, $default = 20 ) {
		return $default;
	}

	protected function set_pagination_args( $args ) {
		$this->pagination = $args;
	}

	protected function row_actions( $actions, $always_visible = false ) {
		return '<div class="row-actions">' . implode( ' | ', $actions ) . '</div>';
	}

	public function search_box( $text, $input_id ) {
		echo '<input type="search" id="' . esc_attr( $input_id ) . '" name="s">';
	}

	public function display() {
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'bulk-' . $this->_args['plural'] ) ) . '">';
		echo '<table>';
		if ( array() === $this->items ) {
			echo '<tr><td>';
			$this->no_items();
			echo '</td></tr>';
		}
		foreach ( $this->items as $item ) {
			echo '<tr>';
			foreach ( array_keys( $this->get_columns() ) as $column ) {
				$method = 'column_' . $column;
				echo '<td>' . ( method_exists( $this, $method ) ? $this->$method( $item ) : $this->column_default( $item, $column ) ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</table>';
	}

	public function no_items() {
		echo 'No items found.';
	}
}
