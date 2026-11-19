<?php
declare(strict_types=1);

namespace Saizul\RedirectManager\Tests\Unit;

use Saizul\RedirectManager\Redirects;
use Saizul\RedirectManager\Rule;
use Saizul\RedirectManager\Tests\WpTestCase;
use WpStubs;

/**
 * Saving, the cached index, import/export and automatic slug redirects.
 */
final class RedirectsTest extends WpTestCase {

	public function test_save_rebuilds_the_cached_index(): void {
		$this->add( array( 'source' => '/a', 'target' => '/b' ) );

		$cached = WpStubs::$options[ Redirects::INDEX_OPTION ];
		self::assertTrue( $cached['with_exact'] );
		self::assertSame( '/a', $cached['exact'][0]['source'] );

		// A fresh service (next request) resolves from the option, without the store.
		$fresh = new Redirects( new \Saizul\RedirectManager\Tests\Support\MemoryRuleStore(), array( 'example.org' ) );
		self::assertSame( '/b', $fresh->resolve( '/a' )->url );
	}

	public function test_index_is_rebuilt_when_matching_settings_change(): void {
		$this->add( array( 'source' => '/About', 'target' => '/b' ) );
		self::settings( array( 'ignore_case' => false ) );

		$fresh = new Redirects( $this->rules, array( 'example.org' ) );
		self::assertNull( $fresh->resolve( '/about' ) );
		self::assertSame( '/b', $fresh->resolve( '/About' )->url );
		self::assertFalse( WpStubs::$options[ Redirects::INDEX_OPTION ]['ignore_case'] );
	}

	public function test_large_sites_load_exact_rules_from_the_store(): void {
		add_filter(
			'redirect_manager_cache_limit',
			static function () {
				return 1;
			}
		);
		$this->add( array( 'source' => '/a', 'target' => '/b' ) );
		$this->add( array( 'source' => '/C/', 'target' => '/d' ) );
		$this->add( array( 'source' => '^/r$', 'target' => '/regex', 'match_type' => 'regex' ) );

		$cached = WpStubs::$options[ Redirects::INDEX_OPTION ];
		self::assertFalse( $cached['with_exact'] );
		self::assertSame( array(), $cached['exact'] );
		self::assertCount( 1, $cached['regex'] );

		$fresh = new Redirects( $this->rules, array( 'example.org' ) );
		self::assertSame( '/d', $fresh->resolve( '/c' )->url );
		self::assertSame( '/b', $this->redirects()->resolve( '/a' )->url );
		self::assertSame( '/regex', $fresh->resolve( '/r' )->url );
	}

	public function test_save_refuses_loops_and_keeps_the_index_clean(): void {
		$this->add( array( 'source' => '/a', 'target' => '/b' ) );
		$result = $this->redirects()->save( array( 'source' => '/b', 'target' => '/a' ) );

		self::assertNull( $result['rule'] );
		self::assertStringStartsWith( 'This redirect would create a loop: /b -> /a -> /b', $result['errors'][0] );
		self::assertCount( 1, $this->rules->rules );
		self::assertNull( $this->redirects()->resolve( '/b' ) );
	}

	public function test_update_keeps_hits_and_origin(): void {
		$rule = $this->add( array( 'source' => '/a', 'target' => '/b' ) );
		$this->rules->record_hit( $rule->id, '2026-11-01 00:00:00' );
		$this->rules->rules[ $rule->id ]->origin = 'import';

		$result = $this->redirects()->save( array( 'source' => '/a', 'target' => '/c', 'status' => 302 ), $rule->id );

		self::assertSame( $rule->id, $result['rule']->id );
		self::assertSame( 1, $this->rules->rules[ $rule->id ]->hits );
		self::assertSame( 'import', $this->rules->rules[ $rule->id ]->origin );
		self::assertSame( '/c', $this->redirects()->resolve( '/a' )->url );
	}

	public function test_update_of_missing_rule(): void {
		self::assertSame( array( 'The redirect does not exist.' ), $this->redirects()->save( array( 'source' => '/a', 'target' => '/b' ), 99 )['errors'] );
	}

	public function test_update_can_fix_a_loop_by_changing_the_target(): void {
		$a = $this->add( array( 'source' => '/a', 'target' => '/b' ) );
		$this->add( array( 'source' => '/b', 'target' => '/c' ) );

		self::assertNull( $this->redirects()->save( array( 'source' => '/c', 'target' => '/a' ) )['rule'] );
		self::assertNotNull( $this->redirects()->save( array( 'source' => '/a', 'target' => '/d' ), $a->id )['rule'] );
		self::assertNotNull( $this->redirects()->save( array( 'source' => '/c', 'target' => '/a' ) )['rule'] );
	}

	public function test_enable_skips_rules_that_would_loop(): void {
		$this->add( array( 'source' => '/a', 'target' => '/b' ) );
		$loop = $this->add( array( 'source' => '/b', 'target' => '/a', 'enabled' => false ) );
		$fine = $this->add( array( 'source' => '/x', 'target' => '/y', 'enabled' => false ) );

		self::assertSame( 1, $this->redirects()->set_enabled( array( $loop->id, $fine->id, 999 ), true ) );
		self::assertFalse( $this->rules->rules[ $loop->id ]->enabled );
		self::assertTrue( $this->rules->rules[ $fine->id ]->enabled );
		self::assertSame( '/y', $this->redirects()->resolve( '/x' )->url );
	}

	public function test_disable_and_delete_update_the_index(): void {
		$rule = $this->add( array( 'source' => '/a', 'target' => '/b' ) );

		$this->redirects()->set_enabled( array( $rule->id ), false );
		self::assertNull( $this->redirects()->resolve( '/a' ) );
		$this->redirects()->set_enabled( array( $rule->id ), true );
		self::assertNotNull( $this->redirects()->resolve( '/a' ) );
		self::assertSame( 1, $this->redirects()->delete( array( $rule->id ) ) );
		self::assertNull( $this->redirects()->resolve( '/a' ) );
	}

	public function test_record_hit_respects_the_setting(): void {
		$rule = $this->add( array( 'source' => '/a', 'target' => '/b' ) );
		$this->redirects()->record_hit( $rule );
		self::settings( array( 'count_hits' => false ) );
		$this->redirects()->record_hit( $rule );

		self::assertSame( 1, $this->rules->rules[ $rule->id ]->hits );
		self::assertNotSame( '', $this->rules->rules[ $rule->id ]->last_hit );
	}

	public function test_import_adds_updates_and_reports_errors_per_line(): void {
		$existing = $this->add( array( 'source' => '/kept', 'target' => '/old-target' ) );
		$csv      = "source,target,status,match_type\n"
			. "/a,/b,301,exact\n"
			. "/KEPT/,/new-target,302,exact\n"
			. "/bad,javascript:alert(1)\n"
			. "/b,/a\n"
			. "^/r/(.*),/s/$1,308,regex\n"
			. "/gone,,410\n";

		$report = $this->redirects()->import( $csv );

		self::assertSame( 3, $report['added'] );
		self::assertSame( 1, $report['updated'] );
		self::assertSame( array( 4, 5 ), array_keys( $report['errors'] ) );
		self::assertStringContainsString( 'loop', $report['errors'][5] );
		self::assertSame( '/new-target', $this->rules->rules[ $existing->id ]->target );
		self::assertSame( 302, $this->rules->rules[ $existing->id ]->status );
		self::assertCount( 4, $this->rules->rules );
		self::assertSame( '/s/x', $this->redirects()->resolve( '/r/x' )->url );
		self::assertSame( 410, $this->redirects()->resolve( '/gone' )->status );
		self::assertSame( 'import', $this->rules->rules[2]->origin );
	}

	public function test_import_dry_run_saves_nothing(): void {
		$report = $this->redirects()->import( "/a,/b\n/c,/d\n", true );

		self::assertSame( 2, $report['added'] );
		self::assertSame( array(), $this->rules->rules );
		self::assertArrayNotHasKey( Redirects::INDEX_OPTION, WpStubs::$options );
	}

	public function test_import_skip_existing(): void {
		$this->add( array( 'source' => '/a', 'target' => '/b' ) );
		$report = $this->redirects()->import( "/a,/changed\n/c,/d\n", false, false );

		self::assertSame( 1, $report['skipped'] );
		self::assertSame( 1, $report['added'] );
		self::assertSame( '/b', $this->redirects()->resolve( '/a' )->url );
	}

	public function test_import_detects_loops_inside_the_file_and_duplicate_rows(): void {
		$report = $this->redirects()->import( "/a,/b\n/b,/c\n/c,/a\n/a,/z\n" );

		self::assertSame( array( 3 ), array_keys( $report['errors'] ) );
		self::assertSame( 2, $report['added'] );
		self::assertSame( '/z', $this->redirects()->resolve( '/a' )->url );
	}

	public function test_export_round_trip(): void {
		$this->add( array( 'source' => '/a', 'target' => '/b', 'note' => 'first' ) );
		$this->add( array( 'source' => '/old', 'target' => '/new', 'match_type' => 'prefix', 'query' => 'pass', 'enabled' => false ) );
		$csv = $this->redirects()->export();

		$this->rules->rules = array();
		$report             = $this->redirects()->import( $csv );

		self::assertSame( 2, $report['added'] );
		self::assertSame( array(), $report['errors'] );
		$rules = $this->rules->all();
		self::assertSame( 'first', $rules[0]->note );
		self::assertFalse( $rules[1]->enabled );
		self::assertSame( Rule::MATCH_PREFIX, $rules[1]->match_type );
	}

	public function test_the_example_csv_imports_cleanly(): void {
		$report = $this->redirects()->import( (string) file_get_contents( dirname( __DIR__, 2 ) . '/examples/redirects.csv' ) );

		self::assertSame( array(), $report['errors'] );
		self::assertSame( 9, $report['added'] );
		self::assertSame( '/new-page', $this->redirects()->resolve( '/old-page' )->url );
		self::assertSame( '/contact/', $this->redirects()->resolve( '/index.php?page_id=12' )->url );
		self::assertNull( $this->redirects()->resolve( '/index.php?page_id=13' ) );
		self::assertSame( '/blog/a/b?x=1', $this->redirects()->resolve( '/old-blog/a/b?x=1' )->url );
		self::assertSame( '/news/hello', $this->redirects()->resolve( '/2024/05/hello' )->url );
		self::assertNull( $this->redirects()->resolve( '/tag/php' ) );
		self::assertSame( '/shop/?campaign=summer&utm=1', $this->redirects()->resolve( '/summer-sale?utm=1' )->url );
		self::assertSame( 410, $this->redirects()->resolve( '/discontinued-product' )->status );
	}

	public function test_post_moved_adds_a_301_and_flattens_chains(): void {
		$first = $this->redirects()->post_moved( 'https://example.org/hello/', 'https://example.org/hello-world/', 7 );

		self::assertNotNull( $first );
		self::assertSame( 'post:7', $first->origin );
		self::assertSame( Rule::QUERY_PASS, $first->query );
		self::assertSame( '/hello-world/?utm=x', $this->redirects()->resolve( '/hello?utm=x' )->url );

		$this->redirects()->post_moved( 'https://example.org/hello-world/', 'https://example.org/news/hello-world/', 7 );

		self::assertSame( '/news/hello-world/', $this->redirects()->resolve( '/hello' )->url );
		self::assertSame( '/news/hello-world/', $this->redirects()->resolve( '/hello-world/' )->url );
		self::assertCount( 2, $this->rules->rules );
	}

	public function test_post_moved_back_to_an_old_url_removes_the_redirect(): void {
		$this->redirects()->post_moved( 'https://example.org/a/', 'https://example.org/b/', 3 );
		$this->redirects()->post_moved( 'https://example.org/b/', 'https://example.org/a/', 3 );

		self::assertNull( $this->redirects()->resolve( '/a/' ) );
		self::assertSame( '/a/', $this->redirects()->resolve( '/b/' )->url );
		self::assertCount( 1, $this->rules->rules );
	}

	public function test_post_moved_keeps_manual_rules(): void {
		$this->add( array( 'source' => '/a', 'target' => '/manual' ) );

		self::assertNull( $this->redirects()->post_moved( '/a', '/b', 3 ) );
		self::assertSame( '/manual', $this->redirects()->resolve( '/a' )->url );
	}

	public function test_post_moved_ignores_same_url(): void {
		self::assertNull( $this->redirects()->post_moved( '/a/', '/A', 3 ) );
		self::assertSame( array(), $this->rules->rules );
	}

	public function test_max_hops_filter(): void {
		add_filter(
			'redirect_manager_max_hops',
			static function () {
				return 1;
			}
		);
		$this->add( array( 'source' => '/b', 'target' => '/c' ) );
		$result = $this->redirects()->save( array( 'source' => '/a', 'target' => '/b' ) );

		self::assertStringContainsString( 'too long', $result['errors'][0] );
	}
}
