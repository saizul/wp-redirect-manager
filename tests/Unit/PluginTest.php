<?php
declare(strict_types=1);

namespace Saizul\RedirectManager\Tests\Unit;

use Saizul\RedirectManager\Plugin;
use Saizul\RedirectManager\Redirects;
use Saizul\RedirectManager\Schema;
use Saizul\RedirectManager\Settings;
use Saizul\RedirectManager\Tests\WpTestCase;
use WpStubs;

/**
 * Bootstrap, cron, schema and uninstall.
 */
final class PluginTest extends WpTestCase {

	public function test_hooks(): void {
		$this->plugin->hooks();

		self::assertNotFalse( has_filter( 'template_redirect' ) );
		self::assertSame( 10, has_filter( 'post_updated' ) );
		self::assertSame( 1, has_filter( 'init', array( Schema::class, 'maybe_install' ) ) );
		self::assertSame( 10, has_filter( Plugin::CRON_HOOK, array( $this->plugin, 'purge_404' ) ) );
		self::assertFalse( has_filter( 'admin_menu' ) );

		WpStubs::reset();
		WpStubs::$admin = true;
		$this->plugin->hooks();
		self::assertNotFalse( has_filter( 'admin_menu' ) );
	}

	public function test_boot_returns_one_instance(): void {
		$first = Plugin::boot( '/x/wp-redirect-manager.php' );

		self::assertSame( $first, Plugin::boot( '/y/wp-redirect-manager.php' ) );
		self::assertSame( $first, Plugin::instance() );
	}

	public function test_activation_creates_tables_and_schedules_the_clean_up(): void {
		Plugin::activate();

		self::assertCount( 2, WpStubs::$registry['dbDelta'] );
		self::assertStringContainsString( 'CREATE TABLE wp_redirect_manager (', WpStubs::$registry['dbDelta'][0] );
		self::assertStringContainsString( 'UNIQUE KEY url_hash (url_hash)', WpStubs::$registry['dbDelta'][1] );
		self::assertSame( Schema::VERSION, WpStubs::$options[ Schema::VERSION_OPTION ] );
		self::assertNotFalse( wp_next_scheduled( Plugin::CRON_HOOK ) );

		Plugin::deactivate();
		self::assertFalse( wp_next_scheduled( Plugin::CRON_HOOK ) );
	}

	public function test_maybe_install_runs_only_for_new_versions(): void {
		WpStubs::$options[ Schema::VERSION_OPTION ] = Schema::VERSION;
		Schema::maybe_install();
		self::assertArrayNotHasKey( 'dbDelta', WpStubs::$registry );

		WpStubs::$options[ Schema::VERSION_OPTION ] = '0';
		Schema::maybe_install();
		self::assertArrayHasKey( 'dbDelta', WpStubs::$registry );
	}

	public function test_schedule_is_idempotent(): void {
		$this->plugin->schedule();
		$first = wp_next_scheduled( Plugin::CRON_HOOK );
		$this->plugin->schedule();

		self::assertSame( $first, wp_next_scheduled( Plugin::CRON_HOOK ) );
	}

	public function test_purge_applies_retention_and_row_cap(): void {
		self::settings( array( 'log_retention_days' => 7, 'log_max_rows' => 100 ) );
		$this->log->record( '/old', '', '', gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS ) );
		for ( $i = 0; $i < 105; $i++ ) {
			$this->log->record( '/new' . $i, '', '', gmdate( 'Y-m-d H:i:s', time() - $i ) );
		}

		self::assertSame( 6, $this->plugin->purge_404() );
		self::assertCount( 100, $this->log->rows );
	}

	public function test_settings_change_rebuilds_the_index(): void {
		$this->add( array( 'source' => '/A', 'target' => '/b' ) );
		WpStubs::$options[ Settings::OPTION ] = array( 'ignore_case' => false );
		$this->plugin->settings_changed();

		self::assertFalse( WpStubs::$options[ Redirects::INDEX_OPTION ]['ignore_case'] );
	}

	public function test_capability_filter(): void {
		self::assertSame( 'manage_options', Plugin::capability() );
		add_filter(
			'redirect_manager_capability',
			static function () {
				return 'edit_pages';
			}
		);
		self::assertSame( 'edit_pages', Plugin::capability() );
	}

	public function test_hosts_include_the_www_twin(): void {
		self::assertSame( array( 'example.org', 'www.example.org' ), Plugin::hosts() );
		WpStubs::$registry['home'] = 'https://www.example.net';
		self::assertSame( array( 'www.example.net', 'example.net' ), Plugin::hosts() );
	}

	public function test_uninstall_drops_tables_and_options(): void {
		WpStubs::$options = array(
			'wp_redirect_manager_settings'   => array(),
			'wp_redirect_manager_index'      => array(),
			'wp_redirect_manager_db_version' => '1',
			'unrelated'                      => 'kept',
		);
		WpStubs::$events[ Plugin::CRON_HOOK ] = 1;
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'wp-redirect-manager/wp-redirect-manager.php' );
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';

		self::assertSame( array( 'unrelated' => 'kept' ), WpStubs::$options );
		self::assertSame( array( 'DROP TABLE IF EXISTS `wp_redirect_manager`', 'DROP TABLE IF EXISTS `wp_redirect_manager_404`' ), $GLOBALS['wpdb']->queries );
		self::assertArrayNotHasKey( Plugin::CRON_HOOK, WpStubs::$events );
	}
}
