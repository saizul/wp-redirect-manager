<?php
declare(strict_types=1);

namespace Saizul\RedirectManager\Tests\Unit;

use Saizul\RedirectManager\SlugWatcher;
use Saizul\RedirectManager\Tests\WpTestCase;
use WpStubs;

/**
 * Automatic redirects when a published post changes its URL.
 */
final class SlugWatcherTest extends WpTestCase {

	/** @var SlugWatcher */
	private $watcher;

	protected function setUp(): void {
		parent::setUp();
		$this->watcher = new SlugWatcher( $this->redirects() );
	}

	private static function post( string $status, string $url, string $type = 'post' ): \stdClass {
		$post                                         = new \stdClass();
		$post->post_status                            = $status;
		$post->post_type                              = $type;
		WpStubs::$permalinks[ spl_object_id( $post ) ] = $url;
		return $post;
	}

	public function test_register(): void {
		$this->watcher->register();
		self::assertSame( 10, has_filter( 'post_updated', array( $this->watcher, 'post_updated' ) ) );
	}

	public function test_slug_change_of_a_published_post_adds_a_redirect(): void {
		$this->watcher->post_updated( 5, self::post( 'publish', 'https://example.org/new-slug/' ), self::post( 'publish', 'https://example.org/old-slug/' ) );

		self::assertSame( '/new-slug/', $this->redirects()->resolve( '/old-slug/' )->url );
		self::assertSame( 'post:5', $this->rules->all()[0]->origin );
	}

	public function test_page_parent_change(): void {
		$this->watcher->post_updated( 6, self::post( 'publish', 'https://example.org/about/team/', 'page' ), self::post( 'publish', 'https://example.org/team/', 'page' ) );

		self::assertSame( '/about/team/', $this->redirects()->resolve( '/team' )->url );
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function skipped(): array {
		return array(
			'draft before'        => array( 'draft', 'publish', 'post' ),
			'unpublished'         => array( 'publish', 'draft', 'post' ),
			'not viewable'        => array( 'publish', 'publish', 'wp_block' ),
		);
	}

	/**
	 * @dataProvider skipped
	 */
	public function test_skipped( string $before, string $after, string $type ): void {
		$this->watcher->post_updated( 5, self::post( $after, 'https://example.org/b/', $type ), self::post( $before, 'https://example.org/a/', $type ) );

		self::assertSame( array(), $this->rules->rules );
	}

	public function test_unchanged_url_and_setting_off(): void {
		$this->watcher->post_updated( 5, self::post( 'publish', 'https://example.org/a/' ), self::post( 'publish', 'https://example.org/a/' ) );
		self::settings( array( 'auto_slug' => false ) );
		$this->watcher->post_updated( 5, self::post( 'publish', 'https://example.org/b/' ), self::post( 'publish', 'https://example.org/a/' ) );

		self::assertSame( array(), $this->rules->rules );
	}

	public function test_filter_can_skip(): void {
		add_filter( 'redirect_manager_auto_redirect', '__return_false' );
		$this->watcher->post_updated( 5, self::post( 'publish', 'https://example.org/b/' ), self::post( 'publish', 'https://example.org/a/' ) );

		self::assertSame( array(), $this->rules->rules );
	}
}
