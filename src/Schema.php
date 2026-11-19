<?php
/**
 * Database tables.
 *
 * @package Saizul\RedirectManager
 */

namespace Saizul\RedirectManager;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the two custom tables with dbDelta().
 */
final class Schema {

	const VERSION        = '1';
	const VERSION_OPTION = 'wp_redirect_manager_db_version';

	/**
	 * Redirects table name.
	 */
	public static function rules_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'redirect_manager';
	}

	/**
	 * 404 log table name.
	 */
	public static function not_found_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'redirect_manager_404';
	}

	/**
	 * CREATE TABLE statements in dbDelta() format.
	 *
	 * @param string $charset_collate Charset and collation clause.
	 * @return string[]
	 */
	public static function statements( string $charset_collate ): array {
		$rules     = self::rules_table();
		$not_found = self::not_found_table();

		return array(
			"CREATE TABLE {$rules} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source text NOT NULL,
  source_key char(40) NOT NULL DEFAULT '',
  target text NOT NULL,
  status smallint(3) unsigned NOT NULL DEFAULT 301,
  match_type varchar(10) NOT NULL DEFAULT 'exact',
  query_mode varchar(10) NOT NULL DEFAULT 'ignore',
  enabled tinyint(1) NOT NULL DEFAULT 1,
  hits bigint(20) unsigned NOT NULL DEFAULT 0,
  last_hit datetime NULL DEFAULT NULL,
  note varchar(255) NOT NULL DEFAULT '',
  origin varchar(40) NOT NULL DEFAULT 'manual',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY source_key (source_key),
  KEY enabled (enabled)
) {$charset_collate};",
			"CREATE TABLE {$not_found} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  url_hash char(40) NOT NULL,
  url text NOT NULL,
  referrer text NOT NULL,
  user_agent varchar(255) NOT NULL DEFAULT '',
  hits bigint(20) unsigned NOT NULL DEFAULT 1,
  first_seen datetime NOT NULL,
  last_seen datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY url_hash (url_hash),
  KEY last_seen (last_seen)
) {$charset_collate};",
		);
	}

	/**
	 * Create or upgrade the tables when the stored version is older.
	 */
	public static function maybe_install(): void {
		if ( self::VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}
		self::install();
	}

	/**
	 * Create or upgrade the tables.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( self::statements( $wpdb->get_charset_collate() ) );
		update_option( self::VERSION_OPTION, self::VERSION );
	}
}
