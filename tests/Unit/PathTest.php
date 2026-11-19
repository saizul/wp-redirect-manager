<?php
declare(strict_types=1);

namespace Saizul\RedirectManager\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Saizul\RedirectManager\Path;

final class PathTest extends TestCase {

	public function test_split(): void {
		self::assertSame( array( '/a/b', 'x=1' ), Path::split( '/a/b?x=1#frag' ) );
		self::assertSame( array( '/a', '' ), Path::split( 'https://example.org/a' ) );
		self::assertSame( array( '/', '' ), Path::split( 'https://example.org' ) );
		self::assertSame( array( '/', 'q=1' ), Path::split( 'https://example.org?q=1' ) );
		self::assertSame( array( '//a//b', '' ), Path::split( '//a//b' ) );
		self::assertSame( array( '/a', '' ), Path::split( 'a' ) );
		self::assertSame( array( '/', '' ), Path::split( '' ) );
	}

	public function test_host(): void {
		self::assertSame( 'example.org', Path::host( 'https://Example.org:8080/a' ) );
		self::assertSame( 'cdn.example.org', Path::host( '//cdn.example.org/x' ) );
		self::assertSame( '', Path::host( '/relative' ) );
	}

	public function test_normalize(): void {
		self::assertSame( '/abc', Path::normalize( '/ABC/', true, true ) );
		self::assertSame( '/ABC/', Path::normalize( '/ABC/', false, false ) );
		self::assertSame( '/', Path::normalize( '/', true, true ) );
		self::assertSame( '/', Path::normalize( '///', true, true ) );
		self::assertSame( '/ärger', Path::normalize( '/%C3%84rger', true, true ) );
	}

	public function test_query_equals_ignores_order(): void {
		self::assertTrue( Path::query_equals( 'a=1&b=2', 'b=2&a=1' ) );
		self::assertTrue( Path::query_equals( 'a[y]=1&a[x]=2', 'a[x]=2&a[y]=1' ) );
		self::assertFalse( Path::query_equals( 'a=1', 'a=2' ) );
		self::assertTrue( Path::query_equals( '', '' ) );
	}

	public function test_join(): void {
		self::assertSame( '/new/a', Path::join( '/new/', '/a' ) );
		self::assertSame( '/new/a', Path::join( '/new', 'a' ) );
		self::assertSame( '/new', Path::join( '/new', '' ) );
		self::assertSame( '/new/a?x=1#f', Path::join( '/new?x=1#f', '/a' ) );
	}

	public function test_encode_keeps_delimiters_and_encodes_the_rest(): void {
		self::assertSame( '/a%20b/c?d=e&f', Path::encode( '/a b/c?d=e&f' ) );
		self::assertSame( '100%25', Path::encode( '100%' ) );
		self::assertSame( '%22%3Cx%3E', Path::encode( '"<x>' ) );
	}
}
