<?php
/**
 * Derived content-index schema.
 *
 * @package Intertexere
 */

namespace Intertexere;

final class Schema {
	public const VERSION = '1';
	public const VERSION_OPTION = 'intertexere_schema_version';
	public const GENERATION_OPTION = 'intertexere_index_generation';

	/**
	 * Return the site-prefixed index table name.
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'intertexere_content_index';
	}

	/**
	 * Install or upgrade the rebuildable derived-data table.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			generation varchar(36) NOT NULL,
			post_type varchar(20) NOT NULL,
			post_status varchar(20) NOT NULL,
			post_modified_gmt datetime NOT NULL,
			permalink text NOT NULL,
			title text NOT NULL,
			excerpt longtext NOT NULL,
			normalized_content longtext NOT NULL,
			headings longtext NOT NULL,
			taxonomies longtext NOT NULL,
			content_hash char(64) NOT NULL,
			indexed_at_gmt datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY generation_post (generation,post_id),
			KEY post_id (post_id),
			KEY type_status (post_type,post_status),
			KEY content_hash (content_hash)
		) {$charset_collate};";

		dbDelta( $sql );
		update_option( self::VERSION_OPTION, self::VERSION, false );

		if ( ! get_option( self::GENERATION_OPTION ) ) {
			add_option( self::GENERATION_OPTION, wp_generate_uuid4(), '', false );
		}
	}

	/**
	 * Upgrade only when the recorded schema version is stale.
	 */
	public static function maybe_upgrade(): void {
		if ( self::VERSION !== get_option( self::VERSION_OPTION ) ) {
			self::install();
		}
	}
}
