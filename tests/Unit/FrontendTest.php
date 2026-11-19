<?php
declare(strict_types=1);

namespace Saizul\RedirectManager\Tests\Unit;

use Saizul\RedirectManager\Frontend;
use Saizul\RedirectManager\MatchResult;
use Saizul\RedirectManager\Tests\WpTestCase;
use WpStubs;

/**
 * template_redirect handlers: redirects, 410 and the 404 monitor.
 */
final class FrontendTest extends WpTestCase {

	/** @var Frontend */
	private $frontend;

	protected function setUp(): void {
		parent::setUp();
		$this->frontend = new Frontend( $this->redirects(), $this->log );
	}

	private function visit( string $uri ): void {
		$_SERVER['REQUEST_URI'] = $uri;
		$this->frontend->redirect();
		$this->frontend->log_404();
	}

	public function test_register(): void {
		$this->frontend->register();

		self::assertSame( 1, has_filter( 'template_redirect', array( $this->frontend, 'redirect' ) ) );
		self::assertSame( 99, has_filter( 'template_redirect', array( $this->frontend, 'log_404' ) ) );
	}

	public function test_redirect_sends_status_and_counts_a_hit(): void {
		$rule = $this->add( array( 'source' => '/old', 'target' => 'https://example.com/new', 'status' => 308 ) );
		$this->visit( '/old/?x=1' );

		self::assertSame( array( 'https://example.com/new', 308 ), WpStubs::$wp_redirect );
		self::assertSame( 1, $this->rules->rules[ $rule->id ]->hits );
		self::assertSame( array( 'redirect_manager_redirect' ), array_values( array_intersect( WpStubs::calls( 'do_action' ), array( 'redirect_manager_redirect' ) ) ) );
	}

	public function test_no_match_does_nothing(): void {
		$this->visit( '/nothing' );

		self::assertNull( WpStubs::$wp_redirect );
	}

	public function test_gone_sets_410_and_the_404_template_without_logging(): void {
		$this->add( array( 'source' => '/gone', 'status' => 410 ) );
		WpStubs::$is_404 = true;
		$this->visit( '/gone' );

		self::assertNull( WpStubs::$wp_redirect );
		self::assertSame( array( '410' ), WpStubs::calls( 'status_header' ) );
		self::assertTrue( $GLOBALS['wp_query']->is_404 );
		self::assertSame( array(), $this->log->rows );
	}

	public function test_self_redirect_is_never_sent(): void {
		$this->add( array( 'source' => '/a', 'target' => '/b' ) );
		add_filter(
			'redirect_manager_match',
			static function ( $match ) {
				$match->url = 'https://example.org/A/';
				return $match;
			}
		);
		$this->visit( '/a' );

		self::assertNull( WpStubs::$wp_redirect );
	}

	public function test_match_filter_can_cancel(): void {
		$this->add( array( 'source' => '/a', 'target' => '/b' ) );
		add_filter( 'redirect_manager_match', '__return_false' );
		$this->visit( '/a' );

		self::assertNull( WpStubs::$wp_redirect );
	}

	public function test_admin_ajax_and_cron_are_skipped(): void {
		$this->add( array( 'source' => '/a', 'target' => '/b' ) );
		WpStubs::$admin = true;
		$this->visit( '/a' );
		WpStubs::$admin = false;
		WpStubs::$ajax  = true;
		$this->visit( '/a' );

		self::assertNull( WpStubs::$wp_redirect );
	}

	public function test_404_is_logged_with_referrer_and_agent(): void {
		WpStubs::$is_404             = true;
		$_SERVER['HTTP_REFERER']     = 'https://other.example/page?a=1';
		$_SERVER['HTTP_USER_AGENT']  = "Bot/1.0 <script>\r\n";
		$this->visit( '/missing?q=1' );
		$this->visit( '/missing?q=1' );
		unset( $_SERVER['HTTP_REFERER'], $_SERVER['HTTP_USER_AGENT'] );

		self::assertCount( 1, $this->log->rows );
		$row = reset( $this->log->rows );
		self::assertSame( '/missing?q=1', $row['url'] );
		self::assertSame( 2, $row['hits'] );
		self::assertSame( 'https://other.example/page?a=1', $row['referrer'] );
		self::assertSame( 'Bot/1.0', $row['user_agent'] );
	}

	public function test_404_log_respects_settings_ignore_list_and_filter(): void {
		WpStubs::$is_404 = true;
		$this->visit( '/wp-content/uploads/missing.jpg' );
		$this->visit( '/FAVICON.ICO' );
		add_filter(
			'redirect_manager_log_404',
			static function ( $log, $uri ) {
				return false === strpos( $uri, 'skip' );
			},
			10,
			2
		);
		$this->visit( '/skip-me' );
		self::assertSame( array(), $this->log->rows );

		self::settings( array( 'log_404' => false ) );
		$this->visit( '/other' );
		self::assertSame( array(), $this->log->rows );
	}

	public function test_non_404_is_not_logged(): void {
		$this->visit( '/fine' );

		self::assertSame( array(), $this->log->rows );
	}

	public function test_long_urls_are_truncated(): void {
		WpStubs::$is_404 = true;
		$this->visit( '/' . str_repeat( 'é', 1500 ) );

		$row = reset( $this->log->rows );
		self::assertLessThanOrEqual( 2000, strlen( $row['url'] ) );
		self::assertTrue( mb_check_encoding( $row['url'], 'UTF-8' ) );
	}

	public function test_request_uri_strips_control_characters(): void {
		$_SERVER['REQUEST_URI'] = "/a\r\nb";
		self::assertSame( '/ab', Frontend::request_uri() );
		unset( $_SERVER['REQUEST_URI'] );
		self::assertSame( '/', Frontend::request_uri() );
	}

	public function test_match_result_carries_the_status(): void {
		$rule  = $this->add( array( 'source' => '/a', 'target' => '/b', 'status' => 307 ) );
		$match = new MatchResult( $rule, '/b' );

		self::assertSame( 307, $match->status );
	}
}
