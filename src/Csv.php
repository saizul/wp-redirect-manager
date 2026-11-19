<?php
/**
 * CSV import and export format.
 *
 * @package Saizul\RedirectManager
 */

namespace Saizul\RedirectManager;

defined( 'ABSPATH' ) || exit;

/**
 * CSV format shared by import, export and WP-CLI.
 *
 * Column order: `source,target,status,match_type,query,enabled,note`.
 * Export adds `hits,last_hit` at the end; import ignores them. A header row
 * is optional; when present, columns are matched by name in any order.
 * Only `source` is required (plus `target` unless the status is 410). Comma,
 * semicolon and tab delimiters are detected, a UTF-8 BOM is skipped, lines
 * starting with `#` are comments.
 */
final class Csv {

	const COLUMNS        = array( 'source', 'target', 'status', 'match_type', 'query', 'enabled', 'note' );
	const EXPORT_COLUMNS = array( 'source', 'target', 'status', 'match_type', 'query', 'enabled', 'note', 'hits', 'last_hit' );
	const MAX_ROWS       = 50000;

	/**
	 * Header aliases used by other tools and spreadsheets.
	 *
	 * @var array<string,string>
	 */
	const ALIASES = array(
		'from'         => 'source',
		'source_url'   => 'source',
		'old'          => 'source',
		'old_url'      => 'source',
		'url'          => 'source',
		'to'           => 'target',
		'target_url'   => 'target',
		'destination'  => 'target',
		'new'          => 'target',
		'new_url'      => 'target',
		'redirect_to'  => 'target',
		'code'         => 'status',
		'http_code'    => 'status',
		'status_code'  => 'status',
		'match'        => 'match_type',
		'matching'     => 'match_type',
		'query_string' => 'query',
		'active'       => 'enabled',
		'comment'      => 'note',
		'title'        => 'note',
		'regex'        => 'regex',
	);

	/**
	 * Parse CSV text into rows of named fields.
	 *
	 * @param string $content CSV text.
	 * @return array{rows:array<int,array<string,string>>,errors:array<int,string>,header:bool} Rows keyed by line number.
	 */
	public static function parse( string $content ): array {
		$content = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $content );
		$result  = array(
			'rows'   => array(),
			'errors' => array(),
			'header' => false,
		);
		if ( '' === trim( $content ) ) {
			return $result;
		}

		$delimiter = self::delimiter( $content );
		$stream    = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory stream.
		if ( false === $stream ) {
			return $result;
		}
		fwrite( $stream, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $stream );

		$columns = null;
		$offset  = 0;
		$line    = 1;
		while ( true ) {
			$start  = $line;
			$fields = fgetcsv( $stream, 0, $delimiter, '"', '' );
			if ( false === $fields ) {
				break;
			}
			$position = (int) ftell( $stream );
			$line    += substr_count( $content, "\n", $offset, max( 0, $position - $offset ) );
			$offset   = $position;
			if ( array( null ) === $fields || '' === trim( implode( '', array_map( 'strval', $fields ) ) ) ) {
				continue;
			}
			$first = trim( (string) $fields[0] );
			if ( '' !== $first && '#' === $first[0] ) {
				continue;
			}
			if ( null === $columns ) {
				$header = self::header( $fields );
				if ( null !== $header ) {
					$columns          = $header;
					$result['header'] = true;
					continue;
				}
				$columns = self::COLUMNS;
			}
			if ( count( $result['rows'] ) >= self::MAX_ROWS ) {
				/* translators: %d: maximum number of rows. */
				$result['errors'][ $start ] = sprintf( __( 'Stopped after %d rows.', 'wp-redirect-manager' ), self::MAX_ROWS );
				break;
			}
			$result['rows'][ $start ] = self::row( $fields, $columns );
		}
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $result;
	}

	/**
	 * Build CSV text with a header row.
	 *
	 * @param Rule[] $rules Rules.
	 */
	public static function build( array $rules ): string {
		$stream = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory stream.
		if ( false === $stream ) {
			return '';
		}
		fputcsv( $stream, self::EXPORT_COLUMNS, ',', '"', '' );
		foreach ( $rules as $rule ) {
			fputcsv(
				$stream,
				array_map(
					array( self::class, 'cell' ),
					array(
						$rule->source,
						$rule->target,
						(string) $rule->status,
						$rule->match_type,
						$rule->query,
						$rule->enabled ? '1' : '0',
						$rule->note,
						(string) $rule->hits,
						$rule->last_hit,
					)
				),
				',',
				'"',
				''
			);
		}
		rewind( $stream );
		$csv = (string) stream_get_contents( $stream );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $csv;
	}

	/**
	 * Protect spreadsheet users from formula injection: cells starting with
	 * `=`, `+`, `-`, `@`, tab or CR get a leading apostrophe.
	 *
	 * @param string $value Cell value.
	 */
	public static function cell( string $value ): string {
		if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * Undo cell() on import.
	 *
	 * @param string $value Cell value.
	 */
	public static function uncell( string $value ): string {
		if ( strlen( $value ) > 1 && "'" === $value[0] && false !== strpos( "=+-@\t\r", $value[1] ) ) {
			return substr( $value, 1 );
		}

		return $value;
	}

	/**
	 * Detect the delimiter from the first non-comment line.
	 *
	 * @param string $content CSV text.
	 */
	public static function delimiter( string $content ): string {
		foreach ( preg_split( '/\r\n|\r|\n/', $content ) as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			$plain  = (string) preg_replace( '/"[^"]*"/', '', $line );
			$counts = array(
				','  => substr_count( $plain, ',' ),
				';'  => substr_count( $plain, ';' ),
				"\t" => substr_count( $plain, "\t" ),
			);
			arsort( $counts );
			$best = (string) key( $counts );

			return $counts[ $best ] > 0 ? $best : ',';
		}

		return ',';
	}

	/**
	 * Column names when the row is a header, otherwise null.
	 *
	 * @param array<int,string|null> $fields First row.
	 * @return string[]|null
	 */
	private static function header( array $fields ): ?array {
		$names = array();
		$known = 0;
		foreach ( $fields as $field ) {
			$raw = trim( (string) $field );
			if ( '' !== $raw && ( '/' === $raw[0] || '^' === $raw[0] || false !== strpos( $raw, '://' ) ) ) {
				return null;
			}
			$name = strtolower( $raw );
			$name = (string) preg_replace( '/[^a-z_]+/', '_', $name );
			$name = trim( $name, '_' );
			if ( isset( self::ALIASES[ $name ] ) ) {
				$name = self::ALIASES[ $name ];
			}
			if ( in_array( $name, self::EXPORT_COLUMNS, true ) || 'regex' === $name ) {
				++$known;
			}
			$names[] = $name;
		}

		return in_array( 'source', $names, true ) && $known >= 2 ? $names : null;
	}

	/**
	 * Map fields to column names.
	 *
	 * @param array<int,string|null> $fields  Fields.
	 * @param string[]               $columns Column names.
	 * @return array<string,string>
	 */
	private static function row( array $fields, array $columns ): array {
		$row = array();
		foreach ( $columns as $i => $name ) {
			if ( '' === $name || isset( $row[ $name ] ) ) {
				continue;
			}
			$row[ $name ] = self::uncell( trim( (string) ( $fields[ $i ] ?? '' ) ) );
		}
		if ( isset( $row['regex'] ) ) {
			if ( Sanitizer::bool( $row['regex'] ) && empty( $row['match_type'] ) ) {
				$row['match_type'] = Rule::MATCH_REGEX;
			}
			unset( $row['regex'] );
		}
		unset( $row['hits'], $row['last_hit'] );

		return $row;
	}
}
