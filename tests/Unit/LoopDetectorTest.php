<?php
declare(strict_types=1);

namespace Saizul\RedirectManager\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Saizul\RedirectManager\Index;
use Saizul\RedirectManager\LoopDetector;
use Saizul\RedirectManager\Rule;

/**
 * Loop and chain detection.
 */
final class LoopDetectorTest extends TestCase {

	/** @var LoopDetector */
	private $detector;

	protected function setUp(): void {
		$this->detector = new LoopDetector( array( 'example.org', 'www.example.org' ), 5 );
	}

	/**
	 * Check the last rule against an index of all rules.
	 *
	 * @param array<int,array<string,mixed>> $rules Rule data.
	 * @return array{result:string,chain:string[]}
	 */
	private function check( array $rules ): array {
		$objects = array();
		foreach ( $rules as $i => $data ) {
			$objects[] = Rule::from_array( array_merge( array( 'id' => $i + 1 ), $data ) );
		}
		return $this->detector->check( end( $objects ), Index::build( $objects ) );
	}

	public function test_simple_redirect_is_ok(): void {
		$result = $this->check( array( array( 'source' => '/a', 'target' => '/b' ) ) );

		self::assertSame( LoopDetector::OK, $result['result'] );
		self::assertSame( array( '/a', '/b' ), $result['chain'] );
	}

	public function test_self_redirect_is_a_loop(): void {
		self::assertSame( LoopDetector::LOOP, $this->check( array( array( 'source' => '/a', 'target' => '/a/' ) ) )['result'] );
	}

	public function test_two_rule_loop(): void {
		$result = $this->check(
			array(
				array( 'source' => '/a', 'target' => '/b' ),
				array( 'source' => '/b', 'target' => '/a' ),
			)
		);

		self::assertSame( LoopDetector::LOOP, $result['result'] );
		self::assertSame( array( '/b', '/a', '/b' ), $result['chain'] );
	}

	public function test_three_rule_loop_through_absolute_local_url(): void {
		$result = $this->check(
			array(
				array( 'source' => '/a', 'target' => '/b' ),
				array( 'source' => '/b', 'target' => 'https://www.example.org/c' ),
				array( 'source' => '/c', 'target' => 'https://example.org/A' ),
			)
		);

		self::assertSame( LoopDetector::LOOP, $result['result'] );
	}

	public function test_external_target_ends_the_chain(): void {
		self::assertSame(
			LoopDetector::OK,
			$this->check(
				array(
					array( 'source' => '/a', 'target' => 'https://other.example.com/a' ),
				)
			)['result']
		);
	}

	public function test_prefix_rule_that_matches_its_own_target_loops(): void {
		$result = $this->check( array( array( 'source' => '/shop', 'target' => '/shop/new', 'match_type' => Rule::MATCH_PREFIX ) ) );

		self::assertSame( LoopDetector::LOOP, $result['result'] );
	}

	public function test_regex_rule_that_matches_its_own_target_loops(): void {
		$result = $this->check( array( array( 'source' => '^/item/(.*)$', 'target' => '/item/$1', 'match_type' => Rule::MATCH_REGEX ) ) );

		self::assertSame( LoopDetector::LOOP, $result['result'] );
	}

	public function test_regex_rule_with_anchored_pattern_is_ok(): void {
		$result = $this->check( array( array( 'source' => '^/old/(.*)$', 'target' => '/new/$1', 'match_type' => Rule::MATCH_REGEX ) ) );

		self::assertSame( LoopDetector::OK, $result['result'] );
	}

	public function test_query_string_rules_do_not_loop_when_the_query_differs(): void {
		$result = $this->check( array( array( 'source' => '/page?old=1', 'target' => '/page', 'query' => Rule::QUERY_EXACT ) ) );

		self::assertSame( LoopDetector::OK, $result['result'] );
	}

	public function test_ignore_mode_loops_when_target_only_adds_a_query(): void {
		$result = $this->check( array( array( 'source' => '/page', 'target' => '/page?new=1' ) ) );

		self::assertSame( LoopDetector::LOOP, $result['result'] );
	}

	public function test_chain_into_410_is_ok(): void {
		$result = $this->check(
			array(
				array( 'source' => '/b', 'status' => 410 ),
				array( 'source' => '/a', 'target' => '/b' ),
			)
		);

		self::assertSame( LoopDetector::OK, $result['result'] );
	}

	public function test_gone_and_disabled_rules_never_loop(): void {
		self::assertSame( LoopDetector::OK, $this->check( array( array( 'source' => '/a', 'status' => 410 ) ) )['result'] );
		self::assertSame( LoopDetector::OK, $this->check( array( array( 'source' => '/a', 'target' => '/a', 'enabled' => false ) ) )['result'] );
	}

	public function test_long_chain_is_reported(): void {
		$rules = array();
		for ( $i = 1; $i <= 7; $i++ ) {
			$rules[] = array( 'source' => '/p' . $i, 'target' => '/p' . ( $i + 1 ) );
		}
		$rules[] = array( 'source' => '/p0', 'target' => '/p1' );

		self::assertSame( LoopDetector::TOO_LONG, $this->check( $rules )['result'] );
	}

	public function test_existing_loop_downstream_is_reported(): void {
		$result = $this->check(
			array(
				array( 'source' => '/x', 'target' => '/y' ),
				array( 'source' => '/y', 'target' => '/x' ),
				array( 'source' => '/start', 'target' => '/x' ),
			)
		);

		self::assertSame( LoopDetector::LOOP, $result['result'] );
	}

	public function test_is_local(): void {
		self::assertTrue( $this->detector->is_local( '/a' ) );
		self::assertTrue( $this->detector->is_local( 'https://WWW.example.org/a' ) );
		self::assertFalse( $this->detector->is_local( 'https://example.com/a' ) );
		self::assertFalse( $this->detector->is_local( 'mailto:x@example.org' ) );
		self::assertFalse( $this->detector->is_local( '' ) );
	}

	public function test_is_self(): void {
		$index = new Index();

		self::assertTrue( $this->detector->is_self( '/a/?x=1', 'https://example.org/A?x=1', $index ) );
		self::assertFalse( $this->detector->is_self( '/a', '/a?x=1', $index ) );
		self::assertFalse( $this->detector->is_self( '/a', 'https://other.example/a', $index ) );
	}
}
