<?php
declare(strict_types=1);

namespace Saizul\RedirectManager\Tests\Unit;

use Saizul\RedirectManager\Cli\Command;
use Saizul\RedirectManager\Tests\WpTestCase;
use WP_CLI;
use WpCliHalt;

/**
 * wp redirects add|list|import|export|delete|test.
 */
final class CliTest extends WpTestCase {

	/** @var Command */
	private $command;

	/** @var string */
	private $tmp;

	protected function setUp(): void {
		parent::setUp();
		$this->command = new Command( $this->plugin );
		$this->tmp     = (string) tempnam( sys_get_temp_dir(), 'rm-cli' );
	}

	protected function tearDown(): void {
		if ( is_file( $this->tmp ) ) {
			unlink( $this->tmp );
		}
	}

	private function last( string $type ): string {
		$messages = array_values(
			array_filter(
				WP_CLI::$log,
				static function ( array $entry ) use ( $type ): bool {
					return $type === $entry[0];
				}
			)
		);
		return $messages ? (string) end( $messages )[1] : '';
	}

	public function test_add(): void {
		$this->command->add( array( '/old', '/new' ), array( 'status' => '302', 'note' => 'From CLI' ) );

		self::assertSame( 'Added redirect 1: /old -> /new (302).', $this->last( 'success' ) );
		$rule = $this->rules->all()[0];
		self::assertSame( 'cli', $rule->origin );
		self::assertSame( 'From CLI', $rule->note );
	}

	public function test_add_regex_gone_disabled_and_porcelain(): void {
		$this->command->add( array( '^/a/(.*)$', '/b/$1' ), array( 'match' => 'regex', 'query' => 'pass', 'porcelain' => true ) );
		self::assertSame( '1', $this->last( 'line' ) );

		$this->command->add( array( '/gone' ), array( 'status' => '410', 'disabled' => true ) );
		self::assertSame( 'Added redirect 2: /gone -> gone (410).', $this->last( 'success' ) );
		self::assertFalse( $this->rules->rules[2]->enabled );
	}

	public function test_add_errors(): void {
		$this->command->add( array( '/a', '/b' ), array() );
		$this->expectException( WpCliHalt::class );
		$this->expectExceptionMessage( 'This redirect would create a loop' );
		$this->command->add( array( '/b', '/a' ), array() );
	}

	public function test_list(): void {
		$this->command->add( array( '/a', '/b' ), array() );
		$this->command->add( array( '/c', '/d' ), array( 'disabled' => true ) );
		$this->command->list_( array(), array( 'format' => 'json' ) );

		self::assertSame( 'json', WP_CLI::$items['format'] );
		self::assertCount( 2, WP_CLI::$items['items'] );
		self::assertSame( 'no', WP_CLI::$items['items'][1]['enabled'] );
		self::assertContains( 'hits', WP_CLI::$items['fields'] );

		$this->command->list_( array(), array( 'format' => 'ids' ) );
		self::assertSame( '1 2', $this->last( 'line' ) );

		$this->command->list_( array(), array( 'search' => '/c', 'fields' => 'id, source' ) );
		self::assertSame( array( 'id', 'source' ), WP_CLI::$items['fields'] );
		self::assertCount( 1, WP_CLI::$items['items'] );
	}

	public function test_list_pages_through_many_rules(): void {
		for ( $i = 0; $i < 520; $i++ ) {
			$this->rules->save( \Saizul\RedirectManager\Rule::from_array( array( 'source' => '/p' . $i, 'target' => '/t' ) ) );
		}
		$this->command->list_( array(), array( 'format' => 'count' ) );

		self::assertCount( 520, WP_CLI::$items['items'] );
	}

	public function test_import_and_export_files(): void {
		file_put_contents( $this->tmp, "source,target,status\n/a,/b,301\n/bad,ftp://x\n/c,/d,308\n" );
		$this->command->import( array( $this->tmp ), array( 'dry-run' => true ) );
		self::assertSame( 'Dry run: 2 added, 0 updated, 0 skipped, 1 errors.', $this->last( 'success' ) );
		self::assertStringStartsWith( 'Line 3:', $this->last( 'warning' ) );
		self::assertSame( array(), $this->rules->rules );

		$this->command->import( array( $this->tmp ), array() );
		self::assertSame( 'Imported: 2 added, 0 updated, 0 skipped, 1 errors.', $this->last( 'success' ) );

		$this->command->import( array( $this->tmp ), array( 'skip-existing' => true ) );
		self::assertSame( 'Imported: 0 added, 0 updated, 2 skipped, 1 errors.', $this->last( 'success' ) );

		$this->command->export( array( $this->tmp ), array() );
		self::assertSame( 'Exported 2 redirects to ' . $this->tmp . '.', $this->last( 'success' ) );
		self::assertStringContainsString( '/c,/d,308,exact', (string) file_get_contents( $this->tmp ) );

		$this->command->export( array(), array() );
		self::assertStringStartsWith( 'source,target,status', $this->last( 'line' ) );
	}

	public function test_import_missing_file(): void {
		$this->expectException( WpCliHalt::class );
		$this->expectExceptionMessage( 'Cannot read /nonexistent/file.csv.' );
		$this->command->import( array( '/nonexistent/file.csv' ), array() );
	}

	public function test_delete_and_test(): void {
		$this->command->add( array( '/a', '/b' ), array( 'query' => 'pass' ) );
		$this->command->add( array( '/gone' ), array( 'status' => '410' ) );

		$this->command->test( array( '/a?x=1' ), array() );
		self::assertSame( '301 /b?x=1 (redirect 1, exact match)', $this->last( 'line' ) );
		$this->command->test( array( '/gone' ), array() );
		self::assertSame( '410 Gone (redirect 2)', $this->last( 'line' ) );
		$this->command->test( array( '/none' ), array() );
		self::assertSame( 'No redirect matches.', $this->last( 'line' ) );

		$this->command->delete( array( '1', '2', '3' ), array() );
		self::assertSame( 'Deleted 2 redirects.', $this->last( 'success' ) );
	}
}
