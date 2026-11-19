<?php
/**
 * Redirect service used by the front end, admin screens, WP-CLI and the slug watcher.
 *
 * @package Saizul\RedirectManager
 */

namespace Saizul\RedirectManager;

use Saizul\RedirectManager\Store\RuleStore;

defined( 'ABSPATH' ) || exit;

/**
 * Validates and saves rules (with loop detection), keeps the cached lookup
 * index in sync, resolves requests and imports/exports CSV.
 */
final class Redirects {

	const INDEX_OPTION = 'wp_redirect_manager_index';

	/**
	 * Rule storage.
	 *
	 * @var RuleStore
	 */
	private $store;

	/**
	 * Hosts of this site.
	 *
	 * @var string[]
	 */
	private $hosts;

	/**
	 * Index loaded for this request.
	 *
	 * @var Index|null
	 */
	private $index;

	/**
	 * Constructor.
	 *
	 * @param RuleStore $store Rule storage.
	 * @param string[]  $hosts Hosts of this site (for loop detection).
	 */
	public function __construct( RuleStore $store, array $hosts ) {
		$this->store = $store;
		$this->hosts = $hosts;
	}

	/**
	 * Rule storage.
	 */
	public function store(): RuleStore {
		return $this->store;
	}

	/**
	 * Loop detector for this site.
	 */
	public function loop_detector(): LoopDetector {
		/**
		 * Filters how many hops a redirect chain may have before it is refused.
		 *
		 * @param int $hops Maximum hops, 10 by default.
		 */
		return new LoopDetector( $this->hosts, (int) apply_filters( 'redirect_manager_max_hops', 10 ) );
	}

	/**
	 * Lookup index: from the option cache, rebuilt when missing or when the
	 * matching settings changed.
	 */
	public function index(): Index {
		if ( null !== $this->index ) {
			return $this->index;
		}
		$settings = Settings::get();
		$cached   = get_option( self::INDEX_OPTION );
		$index    = Index::from_array( $cached );
		if ( null === $index
			|| (bool) $settings['ignore_case'] !== $index->ignore_case()
			|| (bool) $settings['ignore_trailing_slash'] !== $index->ignore_trailing_slash() ) {
			return $this->rebuild_index();
		}
		if ( is_array( $cached ) && ! Index::has_exact( $cached ) ) {
			$this->attach_loader( $index );
		}
		$this->index = $index;

		return $index;
	}

	/**
	 * Rebuild the index from the database and store it in the option cache.
	 */
	public function rebuild_index(): Index {
		$settings = Settings::get();
		$index    = Index::build( $this->store->all( true ), (bool) $settings['ignore_case'], (bool) $settings['ignore_trailing_slash'] );

		/**
		 * Filters how many exact rules are cached in the autoloaded index
		 * option. Above the limit, exact rules are looked up with one indexed
		 * query per request instead.
		 *
		 * @param int $limit Maximum cached exact rules, 2000 by default.
		 */
		$limit      = (int) apply_filters( 'redirect_manager_cache_limit', 2000 );
		$with_exact = $index->exact_count() <= $limit;
		update_option( self::INDEX_OPTION, $index->to_array( $with_exact ), true );
		if ( ! $with_exact ) {
			$index = Index::from_array( $index->to_array( false ) );
			if ( null === $index ) {
				$index = new Index();
			}
			$this->attach_loader( $index );
		}
		$this->index = $index;

		return $index;
	}

	/**
	 * Find the redirect for a request URI.
	 *
	 * @param string $uri Request URI.
	 */
	public function resolve( string $uri ): ?MatchResult {
		return Matcher::match( $this->index(), $uri );
	}

	/**
	 * Count a hit when hit counting is on.
	 *
	 * @param Rule $rule Rule.
	 */
	public function record_hit( Rule $rule ): void {
		if ( $rule->id > 0 && ! empty( Settings::get()['count_hits'] ) ) {
			$this->store->record_hit( $rule->id, gmdate( 'Y-m-d H:i:s' ) );
		}
	}

	/**
	 * Validate input for a new rule or an update, including loop detection.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @param int                 $id    Rule ID when updating, 0 for a new rule.
	 * @return array{rule:Rule|null,errors:string[]}
	 */
	public function validate( array $input, int $id = 0 ): array {
		$result = Sanitizer::rule( $input );
		if ( null === $result['rule'] ) {
			return $result;
		}
		$rule = $result['rule'];
		if ( $id > 0 ) {
			$existing = $this->store->find( $id );
			if ( null === $existing ) {
				return array(
					'rule'   => null,
					'errors' => array( __( 'The redirect does not exist.', 'wp-redirect-manager' ) ),
				);
			}
			$rule->id       = $id;
			$rule->hits     = $existing->hits;
			$rule->last_hit = $existing->last_hit;
			$rule->origin   = $existing->origin;
		}
		$error = $this->loop_error( $rule, $this->working_index() );
		if ( null !== $error ) {
			return array(
				'rule'   => null,
				'errors' => array( $error ),
			);
		}

		return $result;
	}

	/**
	 * Validate and save a rule.
	 *
	 * @param array<string,mixed> $input  Raw input.
	 * @param int                 $id     Rule ID when updating, 0 for a new rule.
	 * @param string              $origin Origin for new rules.
	 * @return array{rule:Rule|null,errors:string[]}
	 */
	public function save( array $input, int $id = 0, string $origin = Rule::ORIGIN_MANUAL ): array {
		$result = $this->validate( $input, $id );
		if ( null === $result['rule'] ) {
			return $result;
		}
		if ( 0 === $id ) {
			$result['rule']->origin = $origin;
		}
		$result['rule'] = $this->store->save( $result['rule'] );
		$this->rebuild_index();

		/**
		 * Fires after a redirect was saved.
		 *
		 * @param Rule $rule Saved rule.
		 */
		do_action( 'redirect_manager_saved', $result['rule'] );

		return $result;
	}

	/**
	 * Delete rules.
	 *
	 * @param int[] $ids Rule IDs.
	 */
	public function delete( array $ids ): int {
		$count = $this->store->delete( $ids );
		$this->rebuild_index();

		return $count;
	}

	/**
	 * Enable or disable rules. Enabling skips rules that would now loop.
	 *
	 * @param int[] $ids     Rule IDs.
	 * @param bool  $enabled New state.
	 * @return int Rules changed.
	 */
	public function set_enabled( array $ids, bool $enabled ): int {
		if ( $enabled ) {
			$index = $this->working_index();
			$ok    = array();
			foreach ( $ids as $id ) {
				$rule = $this->store->find( (int) $id );
				if ( null === $rule ) {
					continue;
				}
				$rule->enabled = true;
				if ( null === $this->loop_error( $rule, $index ) ) {
					$ok[] = $rule->id;
				} else {
					$index->remove( $rule->id );
				}
			}
			$ids = $ok;
		}
		$count = $this->store->set_enabled( $ids, $enabled );
		$this->rebuild_index();

		return $count;
	}

	/**
	 * Reset hit counters.
	 *
	 * @param int[] $ids Rule IDs.
	 */
	public function reset_hits( array $ids ): int {
		return $this->store->reset_hits( $ids );
	}

	/**
	 * Import CSV text.
	 *
	 * Rows with the same match type and source as an existing rule update it
	 * (or are skipped with `$update_existing = false`). Every row is validated
	 * and checked for loops against the rules saved so far.
	 *
	 * @param string $csv             CSV text.
	 * @param bool   $dry_run         Validate only, save nothing.
	 * @param bool   $update_existing Update rules that already exist.
	 * @return array{added:int,updated:int,skipped:int,errors:array<int,string>}
	 */
	public function import( string $csv, bool $dry_run = false, bool $update_existing = true ): array {
		$parsed = Csv::parse( $csv );
		$report = array(
			'added'   => 0,
			'updated' => 0,
			'skipped' => 0,
			'errors'  => $parsed['errors'],
		);

		$index    = $this->working_index();
		$existing = array();
		foreach ( $this->store->all() as $rule ) {
			$existing[ $this->identity( $rule, $index ) ] = $rule;
		}
		$pending = array();
		$temp_id = 0;

		foreach ( $parsed['rows'] as $line => $row ) {
			$result = Sanitizer::rule( $row );
			if ( null === $result['rule'] ) {
				$report['errors'][ $line ] = implode( ' ', $result['errors'] );
				continue;
			}
			$rule     = $result['rule'];
			$identity = $this->identity( $rule, $index );
			$previous = $existing[ $identity ] ?? null;
			if ( null !== $previous ) {
				if ( ! $update_existing ) {
					++$report['skipped'];
					continue;
				}
				$rule->id       = $previous->id;
				$rule->hits     = $previous->hits;
				$rule->last_hit = $previous->last_hit;
				$rule->origin   = $previous->origin;
			} else {
				$rule->id     = --$temp_id;
				$rule->origin = 'import';
			}

			$error = $this->loop_error( $rule, $index );
			if ( null !== $error ) {
				if ( null !== $previous ) {
					$index->add( $previous );
				}
				$report['errors'][ $line ] = $error;
				continue;
			}
			$existing[ $identity ] = $rule;
			$pending[ $rule->id ]  = $rule;
		}

		foreach ( $pending as $rule ) {
			if ( $rule->id > 0 ) {
				++$report['updated'];
			} else {
				++$report['added'];
			}
			if ( ! $dry_run ) {
				if ( $rule->id < 0 ) {
					$rule->id = 0;
				}
				$this->store->save( $rule );
			}
		}
		if ( ! $dry_run && array() !== $pending ) {
			$this->rebuild_index();
		}
		ksort( $report['errors'] );

		return $report;
	}

	/**
	 * Export every rule as CSV text.
	 */
	public function export(): string {
		return Csv::build( $this->store->all() );
	}

	/**
	 * Keep a published post reachable after its URL changed: add an exact 301
	 * from the old path, retarget older automatic redirects of the same post
	 * and remove one that now points away from the live URL.
	 *
	 * @param string $old_url Old permalink.
	 * @param string $new_url New permalink.
	 * @param int    $post_id Post ID.
	 * @return Rule|null The new or updated redirect.
	 */
	public function post_moved( string $old_url, string $new_url, int $post_id ): ?Rule {
		$old_path = Path::split( $old_url )[0];
		$new_path = Path::split( $new_url )[0];
		$index    = $this->index();
		if ( $index->key( $old_path ) === $index->key( $new_path ) ) {
			return null;
		}
		$origin = 'post:' . $post_id;
		$rules  = $this->store->all();

		$remove = array();
		foreach ( $rules as $rule ) {
			if ( $rule->origin === $origin && Rule::MATCH_EXACT === $rule->match_type
				&& $index->source_key( $rule->source ) === $index->key( $new_path ) ) {
				$remove[] = $rule->id;
			}
		}
		if ( array() !== $remove ) {
			$this->store->delete( $remove );
		}

		$current = null;
		foreach ( $rules as $rule ) {
			if ( in_array( $rule->id, $remove, true ) ) {
				continue;
			}
			if ( $rule->origin === $origin && $index->key( Path::split( $rule->target )[0] ) === $index->key( $old_path ) ) {
				$rule->target = $new_path;
				$this->store->save( $rule );
			}
			if ( Rule::MATCH_EXACT === $rule->match_type && Rule::QUERY_EXACT !== $rule->query
				&& $index->source_key( $rule->source ) === $index->key( $old_path ) ) {
				$current = $rule;
			}
		}
		$this->rebuild_index();

		if ( null !== $current ) {
			if ( $current->origin !== $origin ) {
				return null;
			}
			$saved = $this->save( $this->input( $current, array( 'target' => $new_path ) ), $current->id );
			return $saved['rule'];
		}

		/* translators: %d: post ID. */
		$note  = sprintf( __( 'Added automatically when post %d changed its URL.', 'wp-redirect-manager' ), $post_id );
		$saved = $this->save(
			array(
				'source'     => $old_path,
				'target'     => $new_path,
				'status'     => 301,
				'match_type' => Rule::MATCH_EXACT,
				'query'      => Rule::QUERY_PASS,
				'note'       => $note,
			),
			0,
			$origin
		);

		return $saved['rule'];
	}

	/**
	 * Raw input array for a rule, with overrides.
	 *
	 * @param Rule                $rule      Rule.
	 * @param array<string,mixed> $overrides Overrides.
	 * @return array<string,mixed>
	 */
	public function input( Rule $rule, array $overrides = array() ): array {
		return array_merge(
			array(
				'source'     => $rule->source,
				'target'     => $rule->target,
				'status'     => $rule->status,
				'match_type' => $rule->match_type,
				'query'      => $rule->query,
				'enabled'    => $rule->enabled,
				'note'       => $rule->note,
			),
			$overrides
		);
	}

	/**
	 * Loop error message for a rule, or null. The rule is added to (or
	 * replaces its old version in) `$index`; on error it is removed again.
	 *
	 * @param Rule  $rule  Rule (ID 0 for unsaved rules).
	 * @param Index $index Working index.
	 */
	private function loop_error( Rule $rule, Index $index ): ?string {
		$check = clone $rule;
		if ( 0 === $check->id ) {
			$check->id = PHP_INT_MIN;
		}
		$index->add( $check );
		$result = $this->loop_detector()->check( $check, $index );
		if ( LoopDetector::OK === $result['result'] ) {
			return null;
		}
		$index->remove( $check->id );
		$chain = implode( ' -> ', $result['chain'] );
		if ( LoopDetector::LOOP === $result['result'] ) {
			/* translators: %s: chain of URLs. */
			return sprintf( __( 'This redirect would create a loop: %s', 'wp-redirect-manager' ), $chain );
		}

		/* translators: %s: chain of URLs. */
		return sprintf( __( 'This redirect would create a chain that is too long: %s', 'wp-redirect-manager' ), $chain );
	}

	/**
	 * A full in-memory index of the enabled rules (not the cached one, which
	 * may load exact rules lazily).
	 */
	private function working_index(): Index {
		$settings = Settings::get();

		return Index::build( $this->store->all( true ), (bool) $settings['ignore_case'], (bool) $settings['ignore_trailing_slash'] );
	}

	/**
	 * Identity of a rule for import de-duplication: match type and source.
	 *
	 * @param Rule  $rule  Rule.
	 * @param Index $index Index (for normalisation).
	 */
	private function identity( Rule $rule, Index $index ): string {
		if ( Rule::MATCH_REGEX === $rule->match_type ) {
			return 'regex|' . $rule->source;
		}
		list( $path, $query ) = Path::split( $rule->source );

		return $rule->match_type . '|' . $index->key( $path ) . '?' . http_build_query( Path::query_array( $query ) );
	}

	/**
	 * Load exact rules per request from the database.
	 *
	 * @param Index $index Index without the exact map.
	 */
	private function attach_loader( Index $index ): void {
		$store = $this->store;
		$index->set_exact_loader(
			static function ( string $key ) use ( $store, $index ) {
				$loose = Path::normalize( $key, true, true );
				return array_values(
					array_filter(
						$store->exact_by_loose_key( $loose ),
						static function ( Rule $rule ) use ( $index, $key ) {
							return $index->source_key( $rule->source ) === $key;
						}
					)
				);
			}
		);
	}
}
