<?php
/**
 * Database schema: dbDelta install plus version-gated migrations.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_Schema {

	public const VERSION_OPTION = 'advtn_db_version';

	/**
	 * Fully qualified items table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'advtn_items';
	}

	/**
	 * Create or update the schema. Safe to call repeatedly.
	 *
	 * dbDelta is fussy: two spaces after PRIMARY KEY, lowercase column types,
	 * one field per line, KEY (not INDEX), and stable key names across runs.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  url_hash char(40) NOT NULL,
  url varchar(2048) NOT NULL,
  source_id varchar(32) NOT NULL,
  source_type varchar(16) NOT NULL,
  site_name varchar(191) NOT NULL DEFAULT '',
  host varchar(191) NOT NULL DEFAULT '',
  title text NOT NULL,
  excerpt text NULL,
  image_url varchar(2048) NULL,
  published_at datetime NULL,
  first_seen datetime NOT NULL,
  last_seen datetime NOT NULL,
  first_shown_at datetime NULL,
  last_shown_at datetime NULL,
  times_shown int(10) unsigned NOT NULL DEFAULT 0,
  status varchar(12) NOT NULL DEFAULT 'active',
  PRIMARY KEY  (id),
  UNIQUE KEY url_hash (url_hash),
  KEY status_published (status, published_at),
  KEY selection (status, times_shown, published_at),
  KEY source_seen (source_id, last_seen),
  KEY host (host)
) {$charset_collate};";

		dbDelta( $sql );

		update_option( self::VERSION_OPTION, ADVTN_DB_VERSION, false );
	}

	/**
	 * Run install/migrations when the stored DB version lags the code.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$installed = (string) get_option( self::VERSION_OPTION, '0' );

		if ( version_compare( $installed, ADVTN_DB_VERSION, '>=' ) ) {
			return;
		}

		self::install();

		// Future migrations key off $installed here, e.g.:
		// if ( version_compare( $installed, '2', '<' ) ) { ... }
		ADVTN_Logger::log( 'info', 'Schema upgraded.', array( 'from' => $installed, 'to' => ADVTN_DB_VERSION ) );
	}

	/**
	 * Whether the items table physically exists.
	 *
	 * @return bool
	 */
	public static function table_exists(): bool {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		return $found === $table;
	}
}
