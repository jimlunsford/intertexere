<?php
/**
 * Activation and deactivation behavior.
 *
 * @package Intertexere
 */

namespace Intertexere;

final class Lifecycle {
	/**
	 * Install the derived schema and queue the initial build.
	 */
	public static function activate(): void {
		if ( ! Plugin::is_compatible() ) {
			if ( function_exists( 'deactivate_plugins' ) ) {
				deactivate_plugins( plugin_basename( INTERTEXERE_FILE ) );
			}

			wp_die(
				esc_html( sprintf( 'Intertexere requires WordPress %s or newer.', INTERTEXERE_MINIMUM_WP_VERSION ) ),
				esc_html__( 'Intertexere could not be activated', 'intertexere' ),
				array( 'back_link' => true )
			);
		}

		self::add_capability();
		Settings::install();
		Schema::install();
		Indexer::request_rebuild();
		Link_Graph::request_rebuild();
	}

	/**
	 * Stop temporary background work. Content, settings, and derived data stay put.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( Indexer::REBUILD_HOOK );
		wp_clear_scheduled_hook( Link_Graph::REBUILD_HOOK );
		delete_option( Indexer::LOCK_OPTION );
		delete_option( Indexer::RERUN_OPTION );
		delete_option( Link_Graph::LOCK_OPTION );
		delete_option( Link_Graph::RERUN_OPTION );
		self::remove_capability();
	}

	public static function add_capability(): void {
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( 'manage_intertexere' );
		}
	}

	public static function remove_capability(): void {
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->remove_cap( 'manage_intertexere' );
		}
	}
}
