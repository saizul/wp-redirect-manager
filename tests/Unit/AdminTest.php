<?php
declare(strict_types=1);

namespace Saizul\RedirectManager\Tests\Unit;

use Saizul\RedirectManager\Admin\AdminPage;
use Saizul\RedirectManager\Settings;
use Saizul\RedirectManager\Tests\WpTestCase;
use WpDie;
use WpStubs;

/**
 * Tools > Redirects: rendering, handlers, nonces and capabilities.
 */
final class AdminTest extends WpTestCase {

	/** @var AdminPage */
	private $page;

	protected function setUp(): void {
		parent::setUp();
		WpStubs::$logged_in = true;
		$this->page         = new AdminPage( $this->plugin );
	}

	private function render( array $get = array() ): string {
		$_GET = $get;
		ob_start();
		try {
			$this->page->render();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	public function test_register_and_menu(): void {
		$this->page->register();
		$this->page->add_page();
		$this->page->register_settings();

		self::assertSame( array( 'wp-redirect-manager' ), WpStubs::calls( 'management_page' ) );
		self::assertSame( 10, has_filter( 'load-tools_page_wp-redirect-manager', array( $this->page, 'handle_bulk' ) ) );
		self::assertSame( 10, has_filter( 'admin_post_redirect_manager_save', array( $this->page, 'handle_save' ) ) );
		self::assertSame( array( Settings::class, 'sanitize' ), WpStubs::$registry['settings'][ Settings::OPTION ]['sanitize_callback'] );
		self::assertStringContainsString( 'tools.php?page=wp-redirect-manager', $this->page->action_links( array() )[0] );
	}

	public function test_render_requires_the_capability(): void {
		WpStubs::$can = false;
		$this->expectException( WpDie::class );
		$this->render();
	}

	public function test_list_escapes_rule_data(): void {
		$this->rules->save(
			\Saizul\RedirectManager\Rule::from_array(
				array(
					'source' => '/a"><script>alert(1)</script>',
					'target' => '/b',
					'note'   => '<img src=x onerror=alert(1)>',
				)
			)
		);
		$html = $this->render();

		self::assertStringNotContainsString( '<script>alert(1)</script>', $html );
		self::assertStringNotContainsString( '<img src=x', $html );
		self::assertStringContainsString( '&lt;script&gt;', $html );
		self::assertStringContainsString( 'action=redirect_manager_row', $html );
		self::assertStringContainsString( 'name="_wpnonce"', $html );
	}

	public function test_edit_form_is_prefilled(): void {
		$rule = $this->add( array( 'source' => '/edit-me', 'target' => '/there', 'status' => 302 ) );
		$html = $this->render( array( 'edit' => (string) $rule->id ) );

		self::assertStringContainsString( 'Edit redirect', $html );
		self::assertStringContainsString( 'value="/edit-me"', $html );
		self::assertStringContainsString( "value=\"302\" selected='selected'", $html );
	}

	public function test_create_redirect_from_a_404_entry(): void {
		$this->log->record( '/missing-page?utm=x', '', '', '2026-11-19 10:00:00' );
		$html = $this->render( array( 'from_404' => '1' ) );

		self::assertStringContainsString( 'value="/missing-page"', $html );
		self::assertStringContainsString( 'name="from_404" value="1"', $html );
	}

	public function test_save_success_deletes_the_404_entry(): void {
		$this->log->record( '/missing', '', '', '2026-11-19 10:00:00' );
		$_POST = array(
			'rule'     => array( 'source' => '/missing', 'target' => '/found', 'status' => '301', 'enabled' => '1' ),
			'id'       => '0',
			'from_404' => '1',
		);
		$this->page->handle_save();

		self::assertSame( array( 'redirect_manager_save' ), WpStubs::calls( 'check_admin_referer' ) );
		self::assertStringContainsString( 'notice=added', WpStubs::$redirect );
		self::assertSame( '/found', $this->redirects()->resolve( '/missing' )->url );
		self::assertSame( array(), $this->log->rows );
	}

	public function test_save_without_enabled_checkbox_saves_disabled(): void {
		$_POST = array( 'rule' => array( 'source' => '/a', 'target' => '/b' ) );
		$this->page->handle_save();

		self::assertFalse( $this->rules->all()[0]->enabled );
	}

	public function test_save_failure_keeps_input_and_shows_errors(): void {
		$_POST = array( 'rule' => array( 'source' => '/a', 'target' => 'javascript:alert(1)', 'note' => 'keep me' ) );
		$this->page->handle_save();

		self::assertStringContainsString( 'failed=1', WpStubs::$redirect );
		$html = $this->render( array( 'failed' => '1' ) );
		self::assertStringContainsString( 'The target must be a path', $html );
		self::assertStringContainsString( 'value="keep me"', $html );
		self::assertStringContainsString( 'value="javascript:alert(1)"', $html );
		self::assertSame( array(), WpStubs::$transients );
	}

	public function test_save_checks_nonce_and_capability(): void {
		$_POST              = array( 'rule' => array( 'source' => '/a', 'target' => '/b' ) );
		WpStubs::$nonce_ok = false;
		try {
			$this->page->handle_save();
			self::fail( 'Expected wp_die' );
		} catch ( WpDie $e ) {
			self::assertSame( array(), $this->rules->rules );
		}
		WpStubs::$nonce_ok = true;
		WpStubs::$can      = false;
		$this->expectException( WpDie::class );
		$this->page->handle_save();
	}

	public function test_row_actions(): void {
		$rule = $this->add( array( 'source' => '/a', 'target' => '/b' ) );
		$_GET = array( 'op' => 'disable', 'id' => (string) $rule->id );
		$this->page->handle_row();

		self::assertSame( array( 'redirect_manager_row_disable_' . $rule->id ), WpStubs::calls( 'check_admin_referer' ) );
		self::assertFalse( $this->rules->rules[ $rule->id ]->enabled );
		self::assertStringContainsString( 'notice=disable', WpStubs::$redirect );

		$_GET = array( 'op' => 'delete', 'id' => (string) $rule->id );
		$this->page->handle_row();
		self::assertSame( array(), $this->rules->rules );

		$_GET = array( 'op' => 'drop_tables', 'id' => '1' );
		$this->expectException( WpDie::class );
		$this->page->handle_row();
	}

	public function test_row_action_url_has_a_per_row_nonce(): void {
		$url = AdminPage::row_action_url( 'delete', 5 );

		self::assertStringContainsString( 'admin-post.php', $url );
		self::assertStringContainsString( '_wpnonce=' . wp_create_nonce( 'redirect_manager_row_delete_5' ), $url );
	}

	public function test_bulk_actions(): void {
		$a                          = $this->add( array( 'source' => '/a', 'target' => '/b' ) );
		$c                          = $this->add( array( 'source' => '/c', 'target' => '/d' ) );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                      = array(
			'action' => '-1',
			'action2' => 'disable',
			'ids'     => array( (string) $a->id, (string) $c->id ),
		);
		$this->page->handle_bulk();

		self::assertSame( array( 'bulk-redirects' ), WpStubs::calls( 'check_admin_referer' ) );
		self::assertFalse( $this->rules->rules[ $a->id ]->enabled );
		self::assertFalse( $this->rules->rules[ $c->id ]->enabled );
		self::assertStringContainsString( 'count=2', WpStubs::$redirect );
		unset( $_SERVER['REQUEST_METHOD'] );
	}

	public function test_bulk_delete_on_the_404_tab(): void {
		$this->log->record( '/x', '', '', '2026-11-19 10:00:00' );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_GET                       = array( 'tab' => '404' );
		$_POST                      = array( 'action' => 'delete', 'ids' => array( '1' ) );
		$this->page->handle_bulk();

		self::assertSame( array( 'bulk-not_founds' ), WpStubs::calls( 'check_admin_referer' ) );
		self::assertSame( array(), $this->log->rows );
		unset( $_SERVER['REQUEST_METHOD'] );
	}

	public function test_bulk_ignores_get_and_missing_action(): void {
		$_POST = array( 'ids' => array( '1' ) );
		$this->page->handle_bulk();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->page->handle_bulk();
		unset( $_SERVER['REQUEST_METHOD'] );

		self::assertSame( array(), WpStubs::calls( 'check_admin_referer' ) );
	}

	public function test_import_without_upload_fails_safely(): void {
		$_FILES = array( 'csv' => array( 'tmp_name' => __FILE__, 'size' => 10 ) );
		$this->page->handle_import();

		self::assertStringContainsString( 'notice=upload_failed', WpStubs::$redirect );
		self::assertSame( array(), $this->rules->rules );
		self::assertStringContainsString( 'could not be uploaded', $this->render( array( 'tab' => 'import', 'notice' => 'upload_failed' ) ) );
	}

	public function test_import_tab_shows_format_and_report(): void {
		WpStubs::$transients['wp_redirect_manager_form_1_import'] = array(
			'added'   => 2,
			'updated' => 1,
			'skipped' => 0,
			'errors'  => array( 4 => 'Bad <b>row</b>' ),
			'dry_run' => true,
		);
		$html = $this->render( array( 'tab' => 'import' ) );

		self::assertStringContainsString( 'source,target,status,match_type,query,enabled,note', $html );
		self::assertStringContainsString( '2 added, 1 updated, 0 skipped, 1 errors.', $html );
		self::assertStringContainsString( 'Line 4: Bad &lt;b&gt;row&lt;/b&gt;', $html );
		self::assertStringContainsString( 'Dry run', $html );
		self::assertArrayNotHasKey( 'wp_redirect_manager_form_1_import', WpStubs::$transients );
	}

	public function test_export_prints_csv(): void {
		$this->add( array( 'source' => '/a', 'target' => '/b' ) );
		ob_start();
		$this->page->handle_export();
		$csv = (string) ob_get_clean();

		self::assertSame( array( 'redirect_manager_export' ), WpStubs::calls( 'check_admin_referer' ) );
		self::assertStringStartsWith( 'source,target,status,match_type,query,enabled,note,hits,last_hit', $csv );
		self::assertStringContainsString( '/a,/b,301,exact,ignore,1,,0,', $csv );
	}

	public function test_clear_404(): void {
		$this->log->record( '/x', '', '', '2026-11-19 10:00:00' );
		$this->page->handle_clear_404();

		self::assertSame( array(), $this->log->rows );
		self::assertStringContainsString( 'notice=cleared', WpStubs::$redirect );
	}

	public function test_404_and_settings_tabs(): void {
		$this->log->record( '/missing"<x>', 'https://ref.example/', 'UA', '2026-11-19 10:00:00' );
		$html = $this->render( array( 'tab' => '404' ) );
		self::assertStringContainsString( 'Create redirect', $html );
		self::assertStringContainsString( '/missing&quot;&lt;x&gt;', $html );
		self::assertStringContainsString( 'name="tab" value="404"', $html );

		$html = $this->render( array( 'tab' => 'settings' ) );
		self::assertStringContainsString( 'name="wp_redirect_manager_settings[log_retention_days]"', $html );
		self::assertStringContainsString( 'value="wp_redirect_manager"', $html );
	}

	public function test_notices(): void {
		self::assertStringContainsString( '3 redirects deleted.', $this->render( array( 'notice' => 'delete', 'count' => '3' ) ) );
		self::assertStringContainsString( 'Redirects that would create a loop stay disabled', $this->render( array( 'notice' => 'enable', 'count' => '0' ) ) );
		self::assertStringNotContainsString( 'notice-success', $this->render( array( 'notice' => '<script>' ) ) );
	}

	public function test_format_time_uses_site_formats(): void {
		self::assertSame( '2026-11-19 10:00', AdminPage::format_time( '2026-11-19 10:00:00' ) );
		self::assertSame( 'garbage', AdminPage::format_time( 'garbage' ) );
	}
}
