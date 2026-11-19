<?php
/**
 * dbDelta() stub.
 *
 * @package Saizul\RedirectManager
 */

// phpcs:ignoreFile

if ( ! function_exists( 'dbDelta' ) ) {
	function dbDelta( $queries = '', $execute = true ) {
		WpStubs::$registry['dbDelta'] = (array) $queries;
		return array();
	}
}
