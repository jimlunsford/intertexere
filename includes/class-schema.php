<?php
/**
 * Derived content-index schema.
 *
 * @package Intertexere
 */

namespace Intertexere;

final class Schema {
	public const VERSION = '2';
	public const VERSION_OPTION = 'intertexere_schema_version';
	public const GENERATION_OPTION = 'intertexere_index_generation';
	public const GRAPH_GENERATION_OPTION = 'intertexere_graph_generation';

	/**
	 * Return the site-prefixed index table name.
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'intertexere_content_index';
	}

	/**
	 * Return the site-prefixed graph source-state table name.
	 */
	public static function graph_sources_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'intertexere_link_graph_sources';
	}

	/**
	 * Return the site-prefixed graph edge table name.
	 */
	public static function link_edges_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'intertexere_link_edges';
	}

	/**
	 * Install or upgrade the rebuildable derived-data table.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::table_name();
		$sources_table   = self::graph_sources_table_name();
		$edges_table     = self::link_edges_table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$content_sql     = "CREATE TABLE {$table_name} (
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
		$sources_sql     = "CREATE TABLE {$sources_table} (
			generation varchar(36) NOT NULL,
			source_post_id bigint(20) unsigned NOT NULL,
			source_content_hash char(64) NOT NULL,
			source_state varchar(20) NOT NULL,
			indexed_at_gmt datetime NOT NULL,
			PRIMARY KEY  (generation,source_post_id),
			KEY source_post_id (source_post_id),
			KEY generation_state (generation,source_state)
		) {$charset_collate};";
		$edges_sql       = "CREATE TABLE {$edges_table} (
			generation varchar(36) NOT NULL,
			source_post_id bigint(20) unsigned NOT NULL,
			target_identity_hash char(64) NOT NULL,
			target_post_id bigint(20) unsigned NULL DEFAULT NULL,
			normalized_url text NOT NULL,
			occurrence_count int(10) unsigned NOT NULL DEFAULT 1,
			is_self tinyint(1) unsigned NOT NULL DEFAULT 0,
			indexed_at_gmt datetime NOT NULL,
			PRIMARY KEY  (generation,source_post_id,target_identity_hash),
			KEY source_lookup (generation,source_post_id),
			KEY target_lookup (generation,target_post_id,source_post_id)
		) {$charset_collate};";

		dbDelta( $content_sql );
		dbDelta( $sources_sql );
		dbDelta( $edges_sql );
		update_option( self::VERSION_OPTION, self::VERSION, false );

		if ( ! get_option( self::GENERATION_OPTION ) ) {
			add_option( self::GENERATION_OPTION, wp_generate_uuid4(), '', false );
		}

		if ( ! get_option( self::GRAPH_GENERATION_OPTION ) ) {
			add_option( self::GRAPH_GENERATION_OPTION, wp_generate_uuid4(), '', false );
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
