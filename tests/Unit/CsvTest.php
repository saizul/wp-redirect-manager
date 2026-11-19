<?php
declare(strict_types=1);

namespace Saizul\RedirectManager\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Saizul\RedirectManager\Csv;
use Saizul\RedirectManager\Rule;

/**
 * CSV parsing and building.
 */
final class CsvTest extends TestCase {

	public function test_positional_rows_without_header(): void {
		$parsed = Csv::parse( "/old,/new\n/gone,,410\n/a,/b,302,prefix,pass,0,Moved shop\n" );

		self::assertFalse( $parsed['header'] );
		self::assertSame( array( 1, 2, 3 ), array_keys( $parsed['rows'] ) );
		self::assertSame( '/old', $parsed['rows'][1]['source'] );
		self::assertSame( '/new', $parsed['rows'][1]['target'] );
		self::assertSame( '', $parsed['rows'][1]['status'] );
		self::assertSame( '410', $parsed['rows'][2]['status'] );
		self::assertSame(
			array(
				'source'     => '/a',
				'target'     => '/b',
				'status'     => '302',
				'match_type' => 'prefix',
				'query'      => 'pass',
				'enabled'    => '0',
				'note'       => 'Moved shop',
			),
			$parsed['rows'][3]
		);
	}

	public function test_header_maps_columns_by_name_in_any_order(): void {
		$parsed = Csv::parse( "Status,Target URL,Source URL\n308,/b,/a\n" );

		self::assertTrue( $parsed['header'] );
		self::assertSame( '/a', $parsed['rows'][2]['source'] );
		self::assertSame( '/b', $parsed['rows'][2]['target'] );
		self::assertSame( '308', $parsed['rows'][2]['status'] );
	}

	public function test_header_aliases_and_regex_flag(): void {
		$parsed = Csv::parse( "from,to,code,regex\n^/x/(.*),/y/$1,301,1\n/plain,/p,301,0\n" );

		self::assertSame( Rule::MATCH_REGEX, $parsed['rows'][2]['match_type'] );
		self::assertArrayNotHasKey( 'regex', $parsed['rows'][2] );
		self::assertArrayNotHasKey( 'match_type', $parsed['rows'][3] );
	}

	public function test_bom_comments_blank_lines_and_crlf(): void {
		$parsed = Csv::parse( "\xEF\xBB\xBF# exported from staging\r\n\r\nsource,target\r\n/a,/b\r\n\r\n/c,/d\r\n" );

		self::assertTrue( $parsed['header'] );
		self::assertCount( 2, $parsed['rows'] );
		self::assertSame( array( 4, 6 ), array_keys( $parsed['rows'] ) );
	}

	public function test_quoted_fields_with_delimiters_quotes_and_newlines(): void {
		$parsed = Csv::parse( "\"/a,b\",\"/c\"\"d\",301,exact,ignore,1,\"line one\nline two\"\n/next,/x\n" );

		self::assertSame( '/a,b', $parsed['rows'][1]['source'] );
		self::assertSame( '/c"d', $parsed['rows'][1]['target'] );
		self::assertSame( "line one\nline two", $parsed['rows'][1]['note'] );
		self::assertSame( '/next', $parsed['rows'][3]['source'] );
	}

	public function test_backslashes_are_not_escape_characters(): void {
		$parsed = Csv::parse( "\"^/a\\\\(\\d+)\",/b\n" );

		self::assertSame( '^/a\\\\(\\d+)', $parsed['rows'][1]['source'] );
	}

	public function test_semicolon_and_tab_delimiters_are_detected(): void {
		self::assertSame( ';', Csv::delimiter( "source;target\n" ) );
		self::assertSame( "\t", Csv::delimiter( "/a\t/b\n" ) );
		self::assertSame( ',', Csv::delimiter( "\"a;b;c\",d\n" ) );
		self::assertSame( ',', Csv::delimiter( "/only-one-column\n" ) );

		$parsed = Csv::parse( "source;target;status\n/a;/b;302\n" );
		self::assertSame( '302', $parsed['rows'][2]['status'] );
	}

	public function test_hits_and_last_hit_are_ignored_on_import(): void {
		$parsed = Csv::parse( implode( ',', Csv::EXPORT_COLUMNS ) . "\n/a,/b,301,exact,ignore,1,,99,2026-01-01 00:00:00\n" );

		self::assertArrayNotHasKey( 'hits', $parsed['rows'][2] );
		self::assertArrayNotHasKey( 'last_hit', $parsed['rows'][2] );
	}

	public function test_empty_input(): void {
		self::assertSame( array(), Csv::parse( '' )['rows'] );
		self::assertSame( array(), Csv::parse( "\n\n# only a comment\n" )['rows'] );
	}

	public function test_build_and_parse_round_trip(): void {
		$rules = array(
			Rule::from_array( array( 'id' => 1, 'source' => '/a', 'target' => '/b', 'status' => 302, 'hits' => 5, 'last_hit' => '2026-11-01 10:00:00', 'note' => 'Say "hi", ok' ) ),
			Rule::from_array( array( 'id' => 2, 'source' => '^/r/(.*)', 'target' => '/s/$1', 'match_type' => 'regex', 'query' => 'pass', 'enabled' => false ) ),
			Rule::from_array( array( 'id' => 3, 'source' => '/gone', 'status' => 410, 'note' => '=HYPERLINK("x")' ) ),
		);
		$csv    = Csv::build( $rules );
		$lines  = explode( "\n", trim( $csv ) );

		self::assertSame( implode( ',', Csv::EXPORT_COLUMNS ), $lines[0] );
		self::assertSame( '/a,/b,302,exact,ignore,1,"Say ""hi"", ok",5,"2026-11-01 10:00:00"', $lines[1] );
		self::assertStringContainsString( "'=HYPERLINK", $lines[3] );

		$parsed = Csv::parse( $csv );
		self::assertSame( 'Say "hi", ok', $parsed['rows'][2]['note'] );
		self::assertSame( 'regex', $parsed['rows'][3]['match_type'] );
		self::assertSame( '0', $parsed['rows'][3]['enabled'] );
		self::assertSame( '=HYPERLINK("x")', $parsed['rows'][4]['note'] );
	}

	public function test_formula_cells_are_neutralised(): void {
		self::assertSame( "'=1+1", Csv::cell( '=1+1' ) );
		self::assertSame( "'+x", Csv::cell( '+x' ) );
		self::assertSame( "'-x", Csv::cell( '-x' ) );
		self::assertSame( "'@x", Csv::cell( '@x' ) );
		self::assertSame( '/path', Csv::cell( '/path' ) );
		self::assertSame( '', Csv::cell( '' ) );
		self::assertSame( '=1', Csv::uncell( "'=1" ) );
		self::assertSame( "'plain", Csv::uncell( "'plain" ) );
	}
}
