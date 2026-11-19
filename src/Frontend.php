<?php
/**
 * Front-end redirects and the 404 monitor.
 *
 * @package Saizul\RedirectManager
 */

namespace Saizul\RedirectManager;

use Saizul\RedirectManager\Store\NotFoundStore;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on `template_redirect`: priority 1 sends redirects and 410 responses
 * (before core's canonical and old-slug redirects), priority 99 logs 404s.
 */
final class Frontend {

	/**
	 * Redirect service.
	 *
	 * @var Redirects
	 */
	private $redirects;

	/**
	 * 404 log.
	 *
	 * @var NotFoundStore
	 */
	private $log;

	/**
	 * Whether this request was answered with 410 by a rule.
	 *
	 * @var bool
	 */
	private $gone = false;

	/**
	 * Constructor.
	 *
	 * @param Redirects     $redirects Redirect service.
	 * @param NotFoundStore $log       404 log.
	 */
	public function __construct( Redirects $redirects, NotFoundStore $log ) {
		$this->redirects = $redirects;
		$this->log       = $log;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'redirect' ), 1 );
		add_action( 'template_redirect', array( $this, 'log_404' ), 99 );
	}

	/**
	 * Current request URI (path and query).
	 */
	public static function request_uri(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Control characters are removed; the value is only compared and escaped on output.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? Sanitizer::clean( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/';

		return '' === $uri ? '/' : $uri;
	}

	/**
	 * Send the redirect or 410 for the current request, if a rule matches.
	 */
	public function redirect(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		$uri   = self::request_uri();
		$match = $this->redirects->resolve( $uri );

		/**
		 * Filters the matched redirect for a request.
		 *
		 * @param MatchResult|null $match Match, or null when no rule matched.
		 * @param string           $uri   Request URI.
		 */
		$match = apply_filters( 'redirect_manager_match', $match, $uri );
		if ( ! $match instanceof MatchResult ) {
			return;
		}

		if ( 410 === $match->status ) {
			$this->gone = true;
			$this->redirects->record_hit( $match->rule );
			global $wp_query;
			if ( is_object( $wp_query ) && method_exists( $wp_query, 'set_404' ) ) {
				$wp_query->set_404();
			}
			status_header( 410 );
			nocache_headers();

			/**
			 * Fires before the 404 template is shown with status 410.
			 *
			 * @param MatchResult $match Match.
			 */
			do_action( 'redirect_manager_gone', $match );
			return;
		}

		if ( '' === $match->url || $this->redirects->loop_detector()->is_self( $uri, $match->url, $this->redirects->index() ) ) {
			return;
		}

		$this->redirects->record_hit( $match->rule );

		/**
		 * Fires before a redirect is sent.
		 *
		 * @param MatchResult $match Match.
		 * @param string      $uri   Request URI.
		 */
		do_action( 'redirect_manager_redirect', $match, $uri );

		// Targets are set by administrators and may point to other sites, so wp_safe_redirect() does not fit.
		wp_redirect( $match->url, $match->status, 'WP Redirect Manager' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect

		/**
		 * Filters whether to stop after sending a redirect (tests turn it off).
		 *
		 * @param bool $exit Exit, true by default.
		 */
		if ( apply_filters( 'redirect_manager_exit', true ) ) {
			exit;
		}
	}

	/**
	 * Log the request when WordPress answers with a 404.
	 */
	public function log_404(): void {
		if ( $this->gone || ! is_404() ) {
			return;
		}
		$settings = Settings::get();
		if ( empty( $settings['log_404'] ) ) {
			return;
		}
		$uri = self::truncate( self::request_uri(), Sanitizer::MAX_LENGTH );
		foreach ( (array) $settings['log_ignore'] as $needle ) {
			if ( is_string( $needle ) && '' !== $needle && false !== stripos( $uri, $needle ) ) {
				return;
			}
		}

		/**
		 * Filters whether a 404 is logged.
		 *
		 * @param bool   $log Log it.
		 * @param string $uri Request URI.
		 */
		if ( ! apply_filters( 'redirect_manager_log_404', true, $uri ) ) {
			return;
		}

		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['HTTP_REFERER'] ) ) : '';
		$agent    = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$this->log->record( $uri, self::truncate( $referrer, Sanitizer::MAX_LENGTH ), self::truncate( $agent, 255 ), gmdate( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Cut a string to a byte length without breaking UTF-8.
	 *
	 * @param string $value  Value.
	 * @param int    $length Maximum length in bytes.
	 */
	private static function truncate( string $value, int $length ): string {
		if ( strlen( $value ) <= $length ) {
			return $value;
		}

		return function_exists( 'mb_strcut' ) ? mb_strcut( $value, 0, $length, 'UTF-8' ) : substr( $value, 0, $length );
	}
}
