<?php
/**
 * WP-CLI command for the 404 log.
 *
 * @package Saizul\RedirectManager
 */

namespace Saizul\RedirectManager\Cli;

use Saizul\RedirectManager\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Read and clear the 404 log.
 *
 * ## EXAMPLES
 *
 *     wp redirects 404 list
 *     wp redirects 404 list --orderby=hits --limit=20 --format=csv
 *     wp redirects 404 clear --yes
 */
final class NotFoundCommand {

	/**
	 * Columns of the default output.
	 */
	const DEFAULT_FIELDS = array( 'id', 'url', 'hits', 'referrer', 'last_seen' );

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * List logged 404s.
	 *
	 * ## OPTIONS
	 *
	 * [--search=<text>]
	 * : Only entries whose URL or referrer contains the text.
	 *
	 * [--orderby=<field>]
	 * : Sort field.
	 * ---
	 * default: last_seen
	 * options:
	 *   - last_seen
	 *   - first_seen
	 *   - hits
	 *   - url
	 * ---
	 *
	 * [--order=<order>]
	 * : Sort direction.
	 * ---
	 * default: desc
	 * options:
	 *   - asc
	 *   - desc
	 * ---
	 *
	 * [--limit=<n>]
	 * : Number of entries to show. 0 for all.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Comma-separated fields. Default: id,url,hits,referrer,last_seen. Also available: user_agent, first_seen.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 *   - ids
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp redirects 404 list --orderby=hits --limit=20
	 *     wp redirects 404 list --search=/shop --format=json
	 *
	 * @subcommand list
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function list_( $args, $assoc_args ): void {
		$limit = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 50;
		if ( $limit < 0 ) {
			\WP_CLI::error( '--limit must be 0 or a positive number.' );
			return;
		}
		$query = array(
			'search'   => $assoc_args['search'] ?? '',
			'orderby'  => $assoc_args['orderby'] ?? 'last_seen',
			'order'    => $assoc_args['order'] ?? 'desc',
			'per_page' => 500,
			'page'     => 1,
		);
		$items = array();
		do {
			$result = $this->plugin->not_found()->query( $query );
			$items  = array_merge( $items, $result['items'] );
			$loaded = count( $items );
			++$query['page'];
			$more = array() !== $result['items'] && $loaded < (int) $result['total'] && ( 0 === $limit || $loaded < $limit );
		} while ( $more );
		if ( $limit > 0 ) {
			$items = array_slice( $items, 0, $limit );
		}

		$format = $assoc_args['format'] ?? 'table';
		if ( 'ids' === $format ) {
			\WP_CLI::line(
				implode(
					' ',
					array_map(
						static function ( array $row ): string {
							return (string) $row['id'];
						},
						$items
					)
				)
			);
			return;
		}
		$fields = isset( $assoc_args['fields'] ) ? array_map( 'trim', explode( ',', $assoc_args['fields'] ) ) : self::DEFAULT_FIELDS;
		\WP_CLI\Utils\format_items( $format, $items, $fields );
	}

	/**
	 * Delete every entry in the 404 log.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function clear( $args, $assoc_args ): void {
		\WP_CLI::confirm( 'Delete every entry in the 404 log?', $assoc_args );
		$this->plugin->not_found()->clear();
		\WP_CLI::success( 'The 404 log is empty.' );
	}
}
