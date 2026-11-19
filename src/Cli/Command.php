<?php
/**
 * WP-CLI command.
 *
 * @package Saizul\RedirectManager
 */

namespace Saizul\RedirectManager\Cli;

use Saizul\RedirectManager\Plugin;
use Saizul\RedirectManager\Rule;

defined( 'ABSPATH' ) || exit;

/**
 * Manage redirects from the command line.
 *
 * ## EXAMPLES
 *
 *     wp redirects add /old-page /new-page
 *     wp redirects add "^/blog/(\d+)/(.*)$" "/news/$2" --match=regex --status=308
 *     wp redirects add /discontinued --status=410
 *     wp redirects list --format=csv
 *     wp redirects import redirects.csv --dry-run
 *     wp redirects export redirects.csv
 *     wp redirects test /old-page?utm_source=mail
 */
final class Command {

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
	 * Add a redirect.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Source path (exact, prefix) or regular expression (regex).
	 *
	 * [<target>]
	 * : Target path or URL. Not needed with --status=410. Regex targets can use $1, $2...
	 *
	 * [--status=<status>]
	 * : HTTP status.
	 * ---
	 * default: 301
	 * options:
	 *   - 301
	 *   - 302
	 *   - 307
	 *   - 308
	 *   - 410
	 * ---
	 *
	 * [--match=<type>]
	 * : How the source is matched.
	 * ---
	 * default: exact
	 * options:
	 *   - exact
	 *   - prefix
	 *   - regex
	 * ---
	 *
	 * [--query=<mode>]
	 * : Query string handling: ignore it, require it to match exactly, or pass it on to the target.
	 * ---
	 * default: ignore
	 * options:
	 *   - ignore
	 *   - exact
	 *   - pass
	 * ---
	 *
	 * [--note=<note>]
	 * : Note shown in the admin list.
	 *
	 * [--disabled]
	 * : Save the redirect switched off.
	 *
	 * [--porcelain]
	 * : Print only the new ID.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function add( $args, $assoc_args ): void {
		$result = $this->plugin->redirects()->save(
			array(
				'source'     => $args[0] ?? '',
				'target'     => $args[1] ?? '',
				'status'     => $assoc_args['status'] ?? '301',
				'match_type' => $assoc_args['match'] ?? Rule::MATCH_EXACT,
				'query'      => $assoc_args['query'] ?? Rule::QUERY_IGNORE,
				'note'       => $assoc_args['note'] ?? '',
				'enabled'    => empty( $assoc_args['disabled'] ),
			),
			0,
			'cli'
		);
		if ( null === $result['rule'] ) {
			\WP_CLI::error( implode( ' ', $result['errors'] ) );
			return;
		}
		if ( ! empty( $assoc_args['porcelain'] ) ) {
			\WP_CLI::line( (string) $result['rule']->id );
			return;
		}
		\WP_CLI::success( sprintf( 'Added redirect %d: %s -> %s (%d).', $result['rule']->id, $result['rule']->source, '' === $result['rule']->target ? 'gone' : $result['rule']->target, $result['rule']->status ) );
	}

	/**
	 * List redirects.
	 *
	 * ## OPTIONS
	 *
	 * [--search=<text>]
	 * : Only redirects whose source, target or note contains the text.
	 *
	 * [--status=<status>]
	 * : Only redirects with this HTTP status.
	 *
	 * [--match=<type>]
	 * : Only redirects with this match type.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated fields. Default: id,source,target,status,match_type,query,enabled,hits,last_hit
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
	 * @subcommand list
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function list_( $args, $assoc_args ): void {
		$result = $this->plugin->redirects()->store()->query(
			array(
				'search'     => $assoc_args['search'] ?? '',
				'status'     => $assoc_args['status'] ?? '',
				'match_type' => $assoc_args['match'] ?? '',
				'orderby'    => 'id',
				'order'      => 'asc',
				'per_page'   => 500,
				'page'       => 1,
			)
		);
		$items  = $result['items'];
		$page   = 1;
		$total  = (int) $result['total'];
		$loaded = count( $items );
		while ( $loaded < $total ) {
			++$page;
			$more = $this->plugin->redirects()->store()->query(
				array(
					'search'     => $assoc_args['search'] ?? '',
					'status'     => $assoc_args['status'] ?? '',
					'match_type' => $assoc_args['match'] ?? '',
					'orderby'    => 'id',
					'order'      => 'asc',
					'per_page'   => 500,
					'page'       => $page,
				)
			);
			if ( array() === $more['items'] ) {
				break;
			}
			$items  = array_merge( $items, $more['items'] );
			$loaded = count( $items );
		}

		$format = $assoc_args['format'] ?? 'table';
		if ( 'ids' === $format ) {
			\WP_CLI::line(
				implode(
					' ',
					array_map(
						static function ( Rule $rule ) {
							return (string) $rule->id;
						},
						$items
					)
				)
			);
			return;
		}
		$fields = isset( $assoc_args['fields'] ) ? array_map( 'trim', explode( ',', $assoc_args['fields'] ) ) : array( 'id', 'source', 'target', 'status', 'match_type', 'query', 'enabled', 'hits', 'last_hit' );
		$rows   = array_map(
			static function ( Rule $rule ) {
				$row            = $rule->to_array();
				$row['enabled'] = $rule->enabled ? 'yes' : 'no';
				return $row;
			},
			$items
		);
		\WP_CLI\Utils\format_items( $format, $rows, $fields );
	}

	/**
	 * Import redirects from a CSV file.
	 *
	 * Columns: source,target,status,match_type,query,enabled,note. A header row
	 * is optional. Rows with the same source and match type as an existing
	 * redirect update it.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : CSV file, or "-" for standard input.
	 *
	 * [--dry-run]
	 * : Validate and report without saving.
	 *
	 * [--skip-existing]
	 * : Keep existing redirects unchanged instead of updating them.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function import( $args, $assoc_args ): void {
		$file = $args[0] ?? '';
		if ( '-' === $file ) {
			$file = 'php://stdin';
		} elseif ( '' === $file || ! is_readable( $file ) ) {
			\WP_CLI::error( sprintf( 'Cannot read %s.', $file ) );
			return;
		}
		$csv = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file or standard input.
		if ( false === $csv ) {
			\WP_CLI::error( sprintf( 'Cannot read %s.', $file ) );
			return;
		}
		$dry_run = ! empty( $assoc_args['dry-run'] );
		$report  = $this->plugin->redirects()->import( $csv, $dry_run, empty( $assoc_args['skip-existing'] ) );
		foreach ( $report['errors'] as $line => $message ) {
			\WP_CLI::warning( sprintf( 'Line %d: %s', $line, $message ) );
		}
		$summary = sprintf( '%d added, %d updated, %d skipped, %d errors.', $report['added'], $report['updated'], $report['skipped'], count( $report['errors'] ) );
		\WP_CLI::success( ( $dry_run ? 'Dry run: ' : 'Imported: ' ) . $summary );
	}

	/**
	 * Export every redirect as CSV.
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : File to write. Prints to standard output when left out.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function export( $args, $assoc_args ): void {
		$csv = $this->plugin->redirects()->export();
		if ( empty( $args[0] ) ) {
			\WP_CLI::line( rtrim( $csv, "\n" ) );
			return;
		}
		if ( false === file_put_contents( $args[0], $csv ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI output file.
			\WP_CLI::error( sprintf( 'Cannot write %s.', $args[0] ) );
			return;
		}
		\WP_CLI::success( sprintf( 'Exported %d redirects to %s.', max( 0, substr_count( $csv, "\n" ) - 1 ), $args[0] ) );
	}

	/**
	 * Delete redirects.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Redirect IDs.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function delete( $args, $assoc_args ): void {
		$count = $this->plugin->redirects()->delete( array_map( 'intval', $args ) );
		\WP_CLI::success( sprintf( 'Deleted %d redirects.', $count ) );
	}

	/**
	 * Show which redirect a URL would get.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : Path or URL, for example /old-page?x=1.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function test( $args, $assoc_args ): void {
		$match = $this->plugin->redirects()->resolve( $args[0] ?? '/' );
		if ( null === $match ) {
			\WP_CLI::line( 'No redirect matches.' );
			return;
		}
		if ( 410 === $match->status ) {
			\WP_CLI::line( sprintf( '410 Gone (redirect %d)', $match->rule->id ) );
			return;
		}
		\WP_CLI::line( sprintf( '%d %s (redirect %d, %s match)', $match->status, $match->url, $match->rule->id, $match->rule->match_type ) );
	}
}
