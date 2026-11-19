<?php
/**
 * Result of a match.
 *
 * @package Saizul\RedirectManager
 */

namespace Saizul\RedirectManager;

defined( 'ABSPATH' ) || exit;

/**
 * The matched rule and the final target URL.
 */
final class MatchResult {

	/**
	 * Matched rule.
	 *
	 * @var Rule
	 */
	public $rule;

	/**
	 * Target URL with captures and query string applied. Empty for 410.
	 *
	 * @var string
	 */
	public $url;

	/**
	 * HTTP status.
	 *
	 * @var int
	 */
	public $status;

	/**
	 * Constructor.
	 *
	 * @param Rule   $rule Rule.
	 * @param string $url  Target URL.
	 */
	public function __construct( Rule $rule, string $url ) {
		$this->rule   = $rule;
		$this->url    = $url;
		$this->status = $rule->status;
	}
}
