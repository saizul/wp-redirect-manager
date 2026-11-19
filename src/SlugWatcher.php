<?php
/**
 * Automatic redirects when a published post changes its URL.
 *
 * @package Saizul\RedirectManager
 */

namespace Saizul\RedirectManager;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to `post_updated` and adds a 301 from the old permalink to the new
 * one when a published post, page or public custom post type changes its
 * slug or parent.
 */
final class SlugWatcher {

	/**
	 * Redirect service.
	 *
	 * @var Redirects
	 */
	private $redirects;

	/**
	 * Constructor.
	 *
	 * @param Redirects $redirects Redirect service.
	 */
	public function __construct( Redirects $redirects ) {
		$this->redirects = $redirects;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'post_updated', array( $this, 'post_updated' ), 10, 3 );
	}

	/**
	 * Compare the permalinks before and after an update.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $after   Post after the update.
	 * @param \WP_Post $before  Post before the update.
	 */
	public function post_updated( $post_id, $after, $before ): void {
		if ( empty( Settings::get()['auto_slug'] ) || ! is_object( $after ) || ! is_object( $before ) ) {
			return;
		}
		if ( 'publish' !== $before->post_status || 'publish' !== $after->post_status ) {
			return;
		}
		if ( wp_is_post_revision( (int) $post_id ) || ! is_post_type_viewable( $after->post_type ) ) {
			return;
		}
		$old = get_permalink( $before );
		$new = get_permalink( $after );
		if ( ! is_string( $old ) || ! is_string( $new ) || '' === $old || '' === $new || $old === $new ) {
			return;
		}

		/**
		 * Filters whether an automatic redirect is added for a post whose URL changed.
		 *
		 * @param bool     $add  Add it.
		 * @param \WP_Post $post Post after the update.
		 * @param string   $old  Old permalink.
		 * @param string   $new  New permalink.
		 */
		if ( ! apply_filters( 'redirect_manager_auto_redirect', true, $after, $old, $new ) ) {
			return;
		}

		$this->redirects->post_moved( $old, $new, (int) $post_id );
	}
}
