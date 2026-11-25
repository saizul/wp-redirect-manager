<?php
/**
 * Plugin bootstrap.
 *
 * @package Saizul\RedirectManager
 */

namespace Saizul\RedirectManager;

use Saizul\RedirectManager\Admin\AdminPage;
use Saizul\RedirectManager\Cli\Command;
use Saizul\RedirectManager\Cli\NotFoundCommand;
use Saizul\RedirectManager\Store\DbNotFoundStore;
use Saizul\RedirectManager\Store\DbRuleStore;
use Saizul\RedirectManager\Store\NotFoundStore;
use Saizul\RedirectManager\Store\RuleStore;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the front end, the slug watcher, the admin screen, cron and WP-CLI.
 */
final class Plugin {

	const VERSION    = '0.1.0';
	const CRON_HOOK  = 'wp_redirect_manager_purge_404';
	const TEXTDOMAIN = 'wp-redirect-manager';

	/**
	 * Running instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance;

	/**
	 * Main plugin file.
	 *
	 * @var string
	 */
	private $file;

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
	private $not_found;

	/**
	 * Constructor.
	 *
	 * @param string             $file      Main plugin file.
	 * @param RuleStore|null     $rules     Rule storage (database by default).
	 * @param NotFoundStore|null $not_found 404 log (database by default).
	 */
	public function __construct( string $file, ?RuleStore $rules = null, ?NotFoundStore $not_found = null ) {
		$this->file      = $file;
		$this->redirects = new Redirects( $rules ?? new DbRuleStore(), self::hosts() );
		$this->not_found = $not_found ?? new DbNotFoundStore();
	}

	/**
	 * Create the instance and register the hooks.
	 *
	 * @param string $file Main plugin file.
	 */
	public static function boot( string $file ): self {
		if ( null === self::$instance ) {
			self::$instance = new self( $file );
			self::$instance->hooks();
		}

		return self::$instance;
	}

	/**
	 * Running instance, if booted.
	 */
	public static function instance(): ?self {
		return self::$instance;
	}

	/**
	 * Forget the instance (tests).
	 */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * Register hooks.
	 */
	public function hooks(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( Schema::class, 'maybe_install' ), 1 );
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'purge_404' ) );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'settings_changed' ) );
		add_action( 'add_option_' . Settings::OPTION, array( $this, 'settings_changed' ) );

		( new Frontend( $this->redirects, $this->not_found ) )->register();
		( new SlugWatcher( $this->redirects ) )->register();

		if ( is_admin() ) {
			( new AdminPage( $this ) )->register();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'redirects', new Command( $this ) );
			\WP_CLI::add_command( 'redirects 404', new NotFoundCommand( $this ) );
		}
	}

	/**
	 * Activation: create tables, schedule the 404 clean-up.
	 */
	public static function activate(): void {
		Schema::install();
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Deactivation: stop the clean-up. Data stays until uninstall.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Make sure the daily clean-up is scheduled (for example after a migration).
	 */
	public function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Load translations for installs outside WordPress.org.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( self::TEXTDOMAIN, false, dirname( plugin_basename( $this->file ) ) . '/languages' );
	}

	/**
	 * Apply the 404 log retention settings.
	 *
	 * @return int Rows deleted.
	 */
	public function purge_404(): int {
		$settings = Settings::get();
		$before   = gmdate( 'Y-m-d H:i:s', time() - (int) $settings['log_retention_days'] * DAY_IN_SECONDS );

		return $this->not_found->purge( $before, (int) $settings['log_max_rows'] );
	}

	/**
	 * Matching settings changed: rebuild the lookup index.
	 */
	public function settings_changed(): void {
		$this->redirects->rebuild_index();
	}

	/**
	 * Redirect service.
	 */
	public function redirects(): Redirects {
		return $this->redirects;
	}

	/**
	 * 404 log.
	 */
	public function not_found(): NotFoundStore {
		return $this->not_found;
	}

	/**
	 * Main plugin file.
	 */
	public function file(): string {
		return $this->file;
	}

	/**
	 * Capability needed to manage redirects.
	 */
	public static function capability(): string {
		/**
		 * Filters the capability needed to manage redirects and the 404 log.
		 *
		 * @param string $capability Capability, `manage_options` by default.
		 */
		$capability = apply_filters( 'redirect_manager_capability', 'manage_options' );

		return is_string( $capability ) && '' !== $capability ? $capability : 'manage_options';
	}

	/**
	 * Hosts that count as this site: the home URL host and its www/non-www twin.
	 *
	 * @return string[]
	 */
	public static function hosts(): array {
		$host  = Path::host( home_url( '/' ) );
		$hosts = array();
		if ( '' !== $host ) {
			$hosts[] = $host;
			$hosts[] = 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : 'www.' . $host;
		}

		/**
		 * Filters the hosts treated as this site by loop detection.
		 *
		 * @param string[] $hosts Hosts, lower case.
		 */
		$hosts = apply_filters( 'redirect_manager_local_hosts', $hosts );

		return is_array( $hosts ) ? array_values( array_filter( array_map( 'strval', $hosts ) ) ) : array();
	}
}
