<?php
declare(strict_types=1);

namespace Saizul\RedirectManager\Tests;

use PHPUnit\Framework\TestCase;
use Saizul\RedirectManager\Plugin;
use Saizul\RedirectManager\Redirects;
use Saizul\RedirectManager\Settings;
use Saizul\RedirectManager\Tests\Support\MemoryNotFoundStore;
use Saizul\RedirectManager\Tests\Support\MemoryRuleStore;
use WP_CLI;
use WpStubs;

/**
 * Base class: resets the WordPress and WP-CLI stubs before every test and
 * offers a plugin wired to in-memory stores.
 */
abstract class WpTestCase extends TestCase {

	/** @var MemoryRuleStore */
	protected $rules;

	/** @var MemoryNotFoundStore */
	protected $log;

	/** @var Plugin */
	protected $plugin;

	protected function setUp(): void {
		WpStubs::reset();
		WP_CLI::reset();
		Plugin::reset();
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$_FILES   = array();
		add_filter( 'redirect_manager_exit', '__return_false' );

		$this->rules  = new MemoryRuleStore();
		$this->log    = new MemoryNotFoundStore();
		$this->plugin = new Plugin( '/var/www/wp-content/plugins/wp-redirect-manager/wp-redirect-manager.php', $this->rules, $this->log );
	}

	protected function redirects(): Redirects {
		return $this->plugin->redirects();
	}

	/**
	 * Store settings.
	 *
	 * @param array<string,mixed> $overrides Overrides.
	 */
	protected static function settings( array $overrides ): void {
		WpStubs::$options[ Settings::OPTION ] = array_merge( Settings::defaults(), $overrides );
	}

	/**
	 * Save a rule and fail the test on validation errors.
	 *
	 * @param array<string,mixed> $input Input.
	 */
	protected function add( array $input ): \Saizul\RedirectManager\Rule {
		$result = $this->redirects()->save( $input );
		self::assertSame( array(), $result['errors'] );
		self::assertNotNull( $result['rule'] );

		return $result['rule'];
	}
}
