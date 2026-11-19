<?php
/**
 * One redirect rule.
 *
 * @package Saizul\RedirectManager
 */

namespace Saizul\RedirectManager;

defined( 'ABSPATH' ) || exit;

/**
 * Value object for a redirect. Sanitising happens in Sanitizer; this class
 * only carries the data.
 */
final class Rule {

	const MATCH_EXACT  = 'exact';
	const MATCH_PREFIX = 'prefix';
	const MATCH_REGEX  = 'regex';

	const QUERY_IGNORE = 'ignore';
	const QUERY_EXACT  = 'exact';
	const QUERY_PASS   = 'pass';

	const STATUSES      = array( 301, 302, 307, 308, 410 );
	const MATCH_TYPES   = array( self::MATCH_EXACT, self::MATCH_PREFIX, self::MATCH_REGEX );
	const QUERY_MODES   = array( self::QUERY_IGNORE, self::QUERY_EXACT, self::QUERY_PASS );
	const ORIGIN_MANUAL = 'manual';

	/**
	 * Database ID, 0 while unsaved.
	 *
	 * @var int
	 */
	public $id = 0;

	/**
	 * Source path (exact, prefix) or pattern (regex).
	 *
	 * @var string
	 */
	public $source = '';

	/**
	 * Target URL or path. Empty for 410.
	 *
	 * @var string
	 */
	public $target = '';

	/**
	 * HTTP status.
	 *
	 * @var int
	 */
	public $status = 301;

	/**
	 * One of the MATCH_* constants.
	 *
	 * @var string
	 */
	public $match_type = self::MATCH_EXACT;

	/**
	 * One of the QUERY_* constants.
	 *
	 * @var string
	 */
	public $query = self::QUERY_IGNORE;

	/**
	 * Whether the rule is active.
	 *
	 * @var bool
	 */
	public $enabled = true;

	/**
	 * Number of times the rule was used.
	 *
	 * @var int
	 */
	public $hits = 0;

	/**
	 * Last use, `Y-m-d H:i:s` in UTC, or empty.
	 *
	 * @var string
	 */
	public $last_hit = '';

	/**
	 * Free text note.
	 *
	 * @var string
	 */
	public $note = '';

	/**
	 * Where the rule came from: manual, import, cli, post:<id>.
	 *
	 * @var string
	 */
	public $origin = self::ORIGIN_MANUAL;

	/**
	 * Build from an array (database row, cached index entry or test data).
	 *
	 * @param array<string,mixed> $data Data.
	 */
	public static function from_array( array $data ): self {
		$rule             = new self();
		$rule->id         = isset( $data['id'] ) ? (int) $data['id'] : 0;
		$rule->source     = isset( $data['source'] ) ? (string) $data['source'] : '';
		$rule->target     = isset( $data['target'] ) ? (string) $data['target'] : '';
		$rule->status     = isset( $data['status'] ) ? (int) $data['status'] : 301;
		$rule->match_type = isset( $data['match_type'] ) ? (string) $data['match_type'] : self::MATCH_EXACT;
		$rule->query      = isset( $data['query'] ) ? (string) $data['query'] : self::QUERY_IGNORE;
		$rule->enabled    = isset( $data['enabled'] ) ? (bool) (int) $data['enabled'] : true;
		$rule->hits       = isset( $data['hits'] ) ? (int) $data['hits'] : 0;
		$rule->last_hit   = isset( $data['last_hit'] ) ? (string) $data['last_hit'] : '';
		$rule->note       = isset( $data['note'] ) ? (string) $data['note'] : '';
		$rule->origin     = isset( $data['origin'] ) && '' !== $data['origin'] ? (string) $data['origin'] : self::ORIGIN_MANUAL;

		return $rule;
	}

	/**
	 * Array form, with the same keys as from_array().
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'id'         => $this->id,
			'source'     => $this->source,
			'target'     => $this->target,
			'status'     => $this->status,
			'match_type' => $this->match_type,
			'query'      => $this->query,
			'enabled'    => $this->enabled,
			'hits'       => $this->hits,
			'last_hit'   => $this->last_hit,
			'note'       => $this->note,
			'origin'     => $this->origin,
		);
	}

	/**
	 * Small array for the cached lookup index (only what matching needs).
	 *
	 * @return array<string,mixed>
	 */
	public function to_index(): array {
		return array(
			'id'         => $this->id,
			'source'     => $this->source,
			'target'     => $this->target,
			'status'     => $this->status,
			'match_type' => $this->match_type,
			'query'      => $this->query,
		);
	}

	/**
	 * Whether the rule answers with 410 Gone instead of redirecting.
	 */
	public function is_gone(): bool {
		return 410 === $this->status;
	}
}
