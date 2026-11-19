<?php
declare(strict_types=1);

namespace Saizul\RedirectManager\Tests\Unit;

use Saizul\RedirectManager\Rule;
use Saizul\RedirectManager\Sanitizer;
use Saizul\RedirectManager\Settings;
use Saizul\RedirectManager\Tests\WpTestCase;

/**
 * Rule and settings sanitising.
 */
final class SanitizerTest extends WpTestCase {

	/**
	 * @param array<string,mixed> $input Input.
	 */
	private static function rule( array $input ): Rule {
		$result = Sanitizer::rule( $input );
		self::assertSame( array(), $result['errors'] );
		self::assertNotNull( $result['rule'] );
		return $result['rule'];
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return string[]
	 */
	private static function errors( array $input ): array {
		$result = Sanitizer::rule( $input );
		self::assertNull( $result['rule'] );
		return $result['errors'];
	}

	public function test_defaults(): void {
		$rule = self::rule( array( 'source' => '/a', 'target' => '/b' ) );

		self::assertSame( 301, $rule->status );
		self::assertSame( Rule::MATCH_EXACT, $rule->match_type );
		self::assertSame( Rule::QUERY_IGNORE, $rule->query );
		self::assertTrue( $rule->enabled );
	}

	public function test_full_url_source_becomes_a_path_and_a_leading_slash_is_added(): void {
		self::assertSame( '/old/page', self::rule( array( 'source' => 'https://example.org/old/page#x', 'target' => '/b' ) )->source );
		self::assertSame( '/old', self::rule( array( 'source' => 'old', 'target' => '/b' ) )->source );
		self::assertSame( '/with%20space', self::rule( array( 'source' => '/with space', 'target' => '/b' ) )->source );
	}

	public function test_source_with_query_switches_exact_rules_to_query_exact(): void {
		$rule = self::rule( array( 'source' => '/p?id=1', 'target' => '/b' ) );

		self::assertSame( '/p?id=1', $rule->source );
		self::assertSame( Rule::QUERY_EXACT, $rule->query );
	}

	public function test_prefix_rules_drop_the_query_and_exact_query_mode(): void {
		$rule = self::rule( array( 'source' => '/shop?x=1', 'target' => '/b', 'match_type' => 'PREFIX', 'query' => 'exact' ) );

		self::assertSame( '/shop', $rule->source );
		self::assertSame( Rule::MATCH_PREFIX, $rule->match_type );
		self::assertSame( Rule::QUERY_IGNORE, $rule->query );
	}

	public function test_control_characters_are_removed(): void {
		$rule = self::rule( array( 'source' => "/a\r\nSet-Cookie: x", 'target' => "/b\r\nLocation: https://evil.example" ) );

		self::assertSame( '/aSet-Cookie:%20x', $rule->source );
		self::assertSame( '/bLocation:%20https://evil.example', $rule->target );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function targets(): array {
		return array(
			'relative'            => array( '/new', '/new' ),
			'relative with query' => array( '/new?a=1#b', '/new?a=1#b' ),
			'https'               => array( 'https://example.com/x', 'https://example.com/x' ),
			'http upper case'     => array( 'HTTP://example.com', 'HTTP://example.com' ),
			'space encoded'       => array( '/a b', '/a%20b' ),
			'javascript'          => array( 'javascript:alert(1)', '' ),
			'data'                => array( 'data:text/html,x', '' ),
			'protocol relative'   => array( '//evil.example/x', '' ),
			'backslash trick'     => array( '/\\evil.example', '' ),
			'no slash'            => array( 'new-page', '' ),
			'empty host'          => array( 'https:///x', '' ),
		);
	}

	/**
	 * @dataProvider targets
	 */
	public function test_target( string $input, string $expected ): void {
		self::assertSame( $expected, Sanitizer::target( $input ) );
	}

	public function test_invalid_target_is_an_error(): void {
		self::assertContains( 'The target must be a path starting with "/" or an http(s) URL.', self::errors( array( 'source' => '/a', 'target' => 'javascript:alert(1)' ) ) );
	}

	public function test_required_fields(): void {
		self::assertContains( 'The source is required.', self::errors( array( 'target' => '/b' ) ) );
		self::assertContains( 'The target is required (or use status 410).', self::errors( array( 'source' => '/a' ) ) );
	}

	public function test_410_clears_the_target(): void {
		$rule = self::rule( array( 'source' => '/a', 'target' => '/ignored', 'status' => '410' ) );

		self::assertSame( '', $rule->target );
		self::assertTrue( $rule->is_gone() );
	}

	public function test_status_must_be_allowed(): void {
		foreach ( array( '301', '302', '307', '308', 410, ' 302 ' ) as $status ) {
			self::assertContains( (int) $status, Rule::STATUSES );
			self::rule( array( 'source' => '/a', 'target' => '/b', 'status' => $status ) );
		}
		foreach ( array( '200', '303', '404', 'abc', '301.5', '-301' ) as $status ) {
			self::assertContains( 'The status must be 301, 302, 307, 308 or 410.', self::errors( array( 'source' => '/a', 'target' => '/b', 'status' => $status ) ) );
		}
	}

	public function test_unknown_choices_fall_back(): void {
		$rule = self::rule( array( 'source' => '/a', 'target' => '/b', 'match_type' => 'glob', 'query' => 'keep' ) );

		self::assertSame( Rule::MATCH_EXACT, $rule->match_type );
		self::assertSame( Rule::QUERY_IGNORE, $rule->query );
	}

	public function test_regex_is_validated_and_kept_as_typed(): void {
		self::assertSame( '^/a/(\d+)$', self::rule( array( 'source' => '^/a/(\d+)$', 'target' => '/b/$1', 'match_type' => 'regex' ) )->source );
		self::assertContains( 'The source is not a valid regular expression.', self::errors( array( 'source' => '^/a/(', 'target' => '/b', 'match_type' => 'regex' ) ) );
		self::assertTrue( Sanitizer::valid_regex( 'a#b' ) );
		self::assertFalse( Sanitizer::valid_regex( '[a-' ) );
	}

	public function test_same_source_and_target_is_an_error(): void {
		self::assertContains( 'The source and the target are the same URL.', self::errors( array( 'source' => '/a', 'target' => '/a' ) ) );
		self::rule( array( 'source' => '/a', 'target' => '/A' ) );
	}

	public function test_too_long_values(): void {
		self::assertContains( 'The source is too long.', self::errors( array( 'source' => '/' . str_repeat( 'a', 2000 ), 'target' => '/b' ) ) );
		self::assertContains( 'The target is too long.', self::errors( array( 'source' => '/a', 'target' => '/' . str_repeat( 'b', 2000 ) ) ) );
	}

	public function test_note_is_plain_text_and_limited(): void {
		$rule = self::rule( array( 'source' => '/a', 'target' => '/b', 'note' => '<script>x</script>' . str_repeat( 'n', 300 ) ) );

		self::assertSame( 255, strlen( $rule->note ) );
		self::assertStringNotContainsString( '<', $rule->note );
	}

	public function test_non_scalar_input_is_ignored(): void {
		self::assertContains( 'The source is required.', self::errors( array( 'source' => array( '/a' ), 'target' => '/b' ) ) );
	}

	public function test_empty_enabled_means_enabled(): void {
		self::assertTrue( self::rule( array( 'source' => '/a', 'target' => '/b', 'enabled' => '' ) )->enabled );
		self::assertFalse( self::rule( array( 'source' => '/a', 'target' => '/b', 'enabled' => false ) )->enabled );
		self::assertFalse( self::rule( array( 'source' => '/a', 'target' => '/b', 'enabled' => '0' ) )->enabled );
	}

	public function test_bool(): void {
		foreach ( array( true, 1, '1', 'yes', 'true', 'on', 'enabled' ) as $value ) {
			self::assertTrue( Sanitizer::bool( $value ) );
		}
		foreach ( array( false, 0, '0', '', 'no', 'false', 'off', 'disabled', ' NO ' ) as $value ) {
			self::assertFalse( Sanitizer::bool( $value ) );
		}
	}

	public function test_settings_sanitize(): void {
		$out = Settings::sanitize(
			array(
				'ignore_case'        => '1',
				'log_404'            => '0',
				'log_retention_days' => '99999',
				'log_max_rows'       => '5',
				'log_ignore'         => "/wp-content/\n\n.php\r\n.php\n" . str_repeat( 'x', 201 ),
			)
		);

		self::assertTrue( $out['ignore_case'] );
		self::assertFalse( $out['ignore_trailing_slash'] );
		self::assertFalse( $out['log_404'] );
		self::assertSame( 3650, $out['log_retention_days'] );
		self::assertSame( 100, $out['log_max_rows'] );
		self::assertSame( array( '/wp-content/', '.php' ), $out['log_ignore'] );
		self::assertSame( Settings::defaults()['log_retention_days'], Settings::sanitize( 'nope' )['log_retention_days'] );
	}
}
