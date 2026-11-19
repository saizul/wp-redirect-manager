<?php
declare(strict_types=1);

namespace Saizul\RedirectManager\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Saizul\RedirectManager\Index;
use Saizul\RedirectManager\Matcher;
use Saizul\RedirectManager\Rule;

/**
 * The matching engine: exact, prefix, regex, query string modes, order.
 */
final class MatcherTest extends TestCase {

	/**
	 * @param array<int,array<string,mixed>> $rules Rule data.
	 */
	private static function index( array $rules, bool $ignore_case = true, bool $ignore_trailing_slash = true ): Index {
		$objects = array();
		foreach ( $rules as $i => $data ) {
			$objects[] = Rule::from_array( array_merge( array( 'id' => $i + 1 ), $data ) );
		}
		return Index::build( $objects, $ignore_case, $ignore_trailing_slash );
	}

	/**
	 * @param array<int,array<string,mixed>> $rules Rule data.
	 */
	private static function target( array $rules, string $uri, bool $ignore_case = true, bool $ignore_trailing_slash = true ): ?string {
		$match = Matcher::match( self::index( $rules, $ignore_case, $ignore_trailing_slash ), $uri );
		return null === $match ? null : $match->url;
	}

	/**
	 * @return array<string,array{0:string,1:string,2:?string}>
	 */
	public static function exact_cases(): array {
		return array(
			'same path'                => array( '/old', '/old', '/new' ),
			'trailing slash ignored'   => array( '/old', '/old/', '/new' ),
			'source with slash'        => array( '/old/', '/old', '/new' ),
			'case ignored'             => array( '/Old-Page', '/old-page', '/new' ),
			'query ignored'            => array( '/old', '/old?utm_source=x', '/new' ),
			'percent-encoded request'  => array( '/café', '/caf%C3%A9', '/new' ),
			'percent-encoded source'   => array( '/caf%C3%A9', '/café', '/new' ),
			'double slashes collapsed' => array( '/a/b', '//a//b', '/new' ),
			'other path'               => array( '/old', '/older', null ),
			'child path'               => array( '/old', '/old/child', null ),
			'full URL request'         => array( '/old', 'https://example.org/old?x=1', '/new' ),
		);
	}

	/**
	 * @dataProvider exact_cases
	 */
	public function test_exact_matching( string $source, string $uri, ?string $expected ): void {
		self::assertSame( $expected, self::target( array( array( 'source' => $source, 'target' => '/new' ) ), $uri ) );
	}

	public function test_case_and_trailing_slash_can_be_strict(): void {
		$rules = array( array( 'source' => '/Old', 'target' => '/new' ) );

		self::assertNull( self::target( $rules, '/old', false, true ) );
		self::assertSame( '/new', self::target( $rules, '/Old/', false, true ) );
		self::assertNull( self::target( $rules, '/Old/', false, false ) );
		self::assertSame( '/new', self::target( $rules, '/Old', false, false ) );
	}

	public function test_query_exact_mode_requires_the_same_parameters_in_any_order(): void {
		$rules = array(
			array( 'source' => '/page?id=5&lang=en', 'target' => '/five', 'query' => Rule::QUERY_EXACT ),
			array( 'source' => '/page', 'target' => '/fallback' ),
		);

		self::assertSame( '/five', self::target( $rules, '/page?lang=en&id=5' ) );
		self::assertSame( '/fallback', self::target( $rules, '/page?id=6&lang=en' ) );
		self::assertSame( '/fallback', self::target( $rules, '/page' ) );
	}

	public function test_query_exact_rule_wins_over_an_earlier_ignore_rule(): void {
		$rules = array(
			array( 'source' => '/page', 'target' => '/fallback' ),
			array( 'source' => '/page?id=5', 'target' => '/five', 'query' => Rule::QUERY_EXACT ),
		);

		self::assertSame( '/five', self::target( $rules, '/page?id=5' ) );
	}

	public function test_query_exact_mode_without_query_matches_only_requests_without_query(): void {
		$rules = array( array( 'source' => '/page', 'target' => '/new', 'query' => Rule::QUERY_EXACT ) );

		self::assertSame( '/new', self::target( $rules, '/page' ) );
		self::assertNull( self::target( $rules, '/page?x=1' ) );
	}

	public function test_pass_mode_appends_the_request_query(): void {
		$rules = array(
			array( 'source' => '/a', 'target' => '/b', 'query' => Rule::QUERY_PASS ),
			array( 'source' => '/c', 'target' => '/d?ref=x', 'query' => Rule::QUERY_PASS ),
			array( 'source' => '/e', 'target' => '/f?utm=keep#top', 'query' => Rule::QUERY_PASS ),
		);

		self::assertSame( '/b?utm_source=mail&x=1', self::target( $rules, '/a?utm_source=mail&x=1' ) );
		self::assertSame( '/b', self::target( $rules, '/a' ) );
		self::assertSame( '/d?ref=x&utm=1', self::target( $rules, '/c?utm=1' ) );
		self::assertSame( '/f?utm=keep&x=2#top', self::target( $rules, '/e?utm=drop&x=2' ) );
	}

	public function test_ignore_mode_drops_the_request_query(): void {
		self::assertSame( '/b', self::target( array( array( 'source' => '/a', 'target' => '/b' ) ), '/a?x=1' ) );
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string,3:?string}>
	 */
	public static function prefix_cases(): array {
		return array(
			'rest appended'           => array( '/old', '/new', '/old/a/b', '/new/a/b' ),
			'trailing slash kept'     => array( '/old', '/new', '/old/a/', '/new/a/' ),
			'prefix itself'           => array( '/old', '/new', '/old', '/new' ),
			'prefix with slash'       => array( '/old/', '/new/', '/old/a', '/new/a' ),
			'segment boundary'        => array( '/blog', '/news', '/blogging', null ),
			'case kept in rest'       => array( '/old', '/new', '/OLD/Mixed', '/new/Mixed' ),
			'external target'         => array( '/shop', 'https://shop.example.com', '/shop/item/1', 'https://shop.example.com/item/1' ),
			'target with query'       => array( '/old', '/new?from=old', '/old/a', '/new/a?from=old' ),
			'root prefix'             => array( '/', 'https://new.example.com', '/any/page', 'https://new.example.com/any/page' ),
			'encoded rest re-encoded' => array( '/old', '/new', '/old/caf%C3%A9 x', '/new/caf%C3%A9%20x' ),
		);
	}

	/**
	 * @dataProvider prefix_cases
	 */
	public function test_prefix_matching( string $source, string $target, string $uri, ?string $expected ): void {
		self::assertSame( $expected, self::target( array( array( 'source' => $source, 'target' => $target, 'match_type' => Rule::MATCH_PREFIX ) ), $uri ) );
	}

	public function test_longest_prefix_wins(): void {
		$rules = array(
			array( 'source' => '/docs', 'target' => '/manual', 'match_type' => Rule::MATCH_PREFIX ),
			array( 'source' => '/docs/v1', 'target' => '/archive/v1', 'match_type' => Rule::MATCH_PREFIX ),
		);

		self::assertSame( '/archive/v1/install', self::target( $rules, '/docs/v1/install' ) );
		self::assertSame( '/manual/v2/install', self::target( $rules, '/docs/v2/install' ) );
	}

	public function test_regex_with_capture_groups(): void {
		$rules = array(
			array( 'source' => '^/blog/(\d{4})/(\d{2})/(.+?)/?$', 'target' => '/news/$3?year=$1&month=${2}', 'match_type' => Rule::MATCH_REGEX ),
		);

		self::assertSame( '/news/hello-world?year=2024&month=05', self::target( $rules, '/blog/2024/05/hello-world/' ) );
		self::assertNull( self::target( $rules, '/blog/24/05/hello' ) );
	}

	public function test_regex_named_groups_missing_groups_and_whole_match(): void {
		$rules = array(
			array( 'source' => '^/p/(?<slug>[a-z-]+)(/(\d+))?$', 'target' => '/post/$1/$3/$9', 'match_type' => Rule::MATCH_REGEX ),
			array( 'source' => '^/raw/.*$', 'target' => '/archive$0', 'match_type' => Rule::MATCH_REGEX ),
		);

		self::assertSame( '/post/abc//', self::target( $rules, '/p/abc' ) );
		self::assertSame( '/post/abc/7/', self::target( $rules, '/p/abc/7' ) );
		self::assertSame( '/archive/raw/x', self::target( $rules, '/raw/x' ) );
	}

	public function test_regex_case_insensitive_and_hash_delimiter(): void {
		$rules = array( array( 'source' => '^/Tag/(.*)#?$', 'target' => '/topic/$1', 'match_type' => Rule::MATCH_REGEX ) );

		self::assertSame( '/topic/php', self::target( $rules, '/tag/php' ) );
		self::assertNull( self::target( $rules, '/tag/php', false ) );
	}

	public function test_regex_captures_are_encoded(): void {
		$rules = array( array( 'source' => '^/s/(.*)$', 'target' => '/search?q=$1', 'match_type' => Rule::MATCH_REGEX ) );

		self::assertSame( '/search?q=two%20words%3Cb%3E', self::target( $rules, '/s/two%20words%3Cb%3E' ) );
	}

	public function test_regex_can_match_the_query_string_in_exact_mode(): void {
		$rules = array( array( 'source' => '^/index\.php\?p=(\d+)$', 'target' => '/?p=$1', 'match_type' => Rule::MATCH_REGEX, 'query' => Rule::QUERY_EXACT ) );

		self::assertSame( '/?p=42', self::target( $rules, '/index.php?p=42' ) );
		self::assertNull( self::target( $rules, '/index.php' ) );
	}

	public function test_order_exact_then_regex_then_prefix(): void {
		$rules = array(
			array( 'source' => '/shop', 'target' => '/prefix', 'match_type' => Rule::MATCH_PREFIX ),
			array( 'source' => '^/shop/sale', 'target' => '/regex', 'match_type' => Rule::MATCH_REGEX ),
			array( 'source' => '/shop/sale/today', 'target' => '/exact' ),
		);

		self::assertSame( '/exact', self::target( $rules, '/shop/sale/today' ) );
		self::assertSame( '/regex', self::target( $rules, '/shop/sale/tomorrow' ) );
		self::assertSame( '/prefix/shoes', self::target( $rules, '/shop/shoes' ) );
	}

	public function test_regex_rules_are_tried_in_id_order(): void {
		$index = Index::build(
			array(
				Rule::from_array( array( 'id' => 9, 'source' => '^/x', 'target' => '/nine', 'match_type' => 'regex' ) ),
				Rule::from_array( array( 'id' => 2, 'source' => '^/x', 'target' => '/two', 'match_type' => 'regex' ) ),
			)
		);

		self::assertSame( '/two', Matcher::match( $index, '/x' )->url );
	}

	public function test_disabled_rules_are_not_indexed(): void {
		self::assertNull( self::target( array( array( 'source' => '/a', 'target' => '/b', 'enabled' => false ) ), '/a' ) );
	}

	public function test_gone_rule_returns_an_empty_url_and_410(): void {
		$match = Matcher::match( self::index( array( array( 'source' => '/gone', 'status' => 410, 'query' => Rule::QUERY_PASS ) ) ), '/gone?x=1' );

		self::assertNotNull( $match );
		self::assertSame( 410, $match->status );
		self::assertSame( '', $match->url );
	}

	public function test_broken_regex_at_runtime_is_a_miss(): void {
		self::assertNull( self::target( array( array( 'source' => '(', 'target' => '/x', 'match_type' => 'regex' ) ), '/(' ) );
	}

	public function test_index_round_trip_through_the_option_array(): void {
		$index = self::index(
			array(
				array( 'source' => '/a', 'target' => '/b' ),
				array( 'source' => '^/r/(.*)', 'target' => '/s/$1', 'match_type' => 'regex' ),
				array( 'source' => '/p', 'target' => '/q', 'match_type' => 'prefix' ),
			),
			false,
			true
		);
		$copy  = Index::from_array( $index->to_array() );

		self::assertNotNull( $copy );
		self::assertFalse( $copy->ignore_case() );
		self::assertSame( 3, $copy->count() );
		self::assertSame( '/b', Matcher::match( $copy, '/a' )->url );
		self::assertSame( '/s/x', Matcher::match( $copy, '/r/x' )->url );
		self::assertSame( '/q/z', Matcher::match( $copy, '/p/z' )->url );
		self::assertNull( Index::from_array( 'garbage' ) );
		self::assertNull( Index::from_array( array( 'version' => 99 ) ) );
	}

	public function test_index_without_exact_map_uses_the_loader(): void {
		$index = Index::from_array( self::index( array( array( 'source' => '/a', 'target' => '/b' ) ) )->to_array( false ) );
		self::assertNotNull( $index );
		self::assertNull( Matcher::match( $index, '/a' ) );

		$asked = array();
		$index->set_exact_loader(
			static function ( string $key ) use ( &$asked ) {
				$asked[] = $key;
				return array( Rule::from_array( array( 'id' => 1, 'source' => '/a', 'target' => '/from-db' ) ) );
			}
		);
		self::assertSame( '/from-db', Matcher::match( $index, '/A/' )->url );
		self::assertSame( array( '/a' ), $asked );
	}

	public function test_index_remove(): void {
		$index = self::index(
			array(
				array( 'source' => '/a', 'target' => '/b' ),
				array( 'source' => '/a', 'target' => '/c', 'query' => 'exact' ),
			)
		);
		$index->remove( 1 );
		self::assertSame( 1, $index->exact_count() );
		$index->remove( 2 );
		self::assertSame( 0, $index->count() );
		self::assertSame( array(), $index->exact_for( '/a' ) );
	}
}
