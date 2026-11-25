<?php
declare(strict_types=1);

namespace Saizul\RedirectManager\Tests\Unit;

use Saizul\RedirectManager\Cli\NotFoundCommand;
use Saizul\RedirectManager\Tests\WpTestCase;
use WP_CLI;
use WpCliHalt;

/**
 * wp redirects 404 list|clear.
 */
final class NotFoundCliTest extends WpTestCase {

	/** @var NotFoundCommand */
	private $command;

	protected function setUp(): void {
		parent::setUp();
		$this->command = new NotFoundCommand( $this->plugin );
		$this->log->record( '/missing', 'https://example.com/page', 'Mozilla/5.0', '2026-11-20 10:00:00' );
		$this->log->record( '/old-shop?id=3', '', 'curl/8', '2026-11-21 10:00:00' );
		$this->log->record( '/missing', '', 'Mozilla/5.0', '2026-11-22 10:00:00' );
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

	public function test_list_defaults(): void {
		$this->command->list_( array(), array() );

		self::assertSame( 'table', WP_CLI::$items['format'] );
		self::assertSame( array( 'id', 'url', 'hits', 'referrer', 'last_seen' ), WP_CLI::$items['fields'] );
		self::assertCount( 2, WP_CLI::$items['items'] );
		self::assertSame( 2, WP_CLI::$items['items'][0]['hits'] );
	}

	public function test_list_fields_format_limit_and_ids(): void {
		$this->command->list_( array(), array( 'format' => 'json', 'fields' => 'url, user_agent', 'limit' => '1' ) );
		self::assertSame( 'json', WP_CLI::$items['format'] );
		self::assertSame( array( 'url', 'user_agent' ), WP_CLI::$items['fields'] );
		self::assertCount( 1, WP_CLI::$items['items'] );

		$this->command->list_( array(), array( 'format' => 'ids', 'limit' => '0' ) );
		self::assertSame( '1 2', $this->last( 'line' ) );
	}

	public function test_list_reads_more_than_one_page(): void {
		for ( $i = 0; $i < 620; $i++ ) {
			$this->log->record( '/p' . $i, '', '', '2026-11-23 10:00:00' );
		}
		$this->command->list_( array(), array( 'format' => 'count', 'limit' => '0' ) );
		self::assertCount( 622, WP_CLI::$items['items'] );

		$this->command->list_( array(), array( 'format' => 'count' ) );
		self::assertCount( 50, WP_CLI::$items['items'] );
	}

	public function test_negative_limit_is_an_error(): void {
		$this->expectException( WpCliHalt::class );
		$this->expectExceptionMessage( '--limit must be 0 or a positive number.' );
		$this->command->list_( array(), array( 'limit' => '-1' ) );
	}

	public function test_clear_asks_first(): void {
		WP_CLI::$confirm = false;
		try {
			$this->command->clear( array(), array() );
			self::fail( 'Expected the confirmation to abort.' );
		} catch ( WpCliHalt $halt ) {
			self::assertSame( 'aborted', $halt->getMessage() );
		}
		self::assertCount( 2, $this->log->rows );

		$this->command->clear( array(), array( 'yes' => true ) );
		self::assertSame( array(), $this->log->rows );
		self::assertSame( 'The 404 log is empty.', $this->last( 'success' ) );
	}
}
