<?php
/**
 * Plugin runtime bootstrap.
 *
 * @package Intertexere
 */

namespace Intertexere;

final class Plugin {
	private static bool $booted = false;

	/**
	 * Register runtime behavior only on a supported WordPress version.
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		if ( ! self::is_compatible() ) {
			add_action( 'admin_notices', array( self::class, 'compatibility_notice' ) );
			return;
		}

		Schema::maybe_upgrade();
		add_action( 'wp_after_insert_post', array( Indexer::class, 'handle_post_saved' ), 10, 2 );
		add_action( 'before_delete_post', array( Indexer::class, 'handle_post_deleted' ) );
		add_action( Indexer::REBUILD_HOOK, array( Indexer::class, 'rebuild' ) );
		add_action( 'update_option_' . Settings::OPTION, array( self::class, 'settings_changed' ), 10, 2 );

		if ( is_admin() ) {
			Admin::register_hooks();
		}
	}

	public static function is_compatible(): bool {
		global $wp_version;

		return isset( $wp_version ) && version_compare( $wp_version, INTERTEXERE_MINIMUM_WP_VERSION, '>=' );
	}

	public static function compatibility_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>'
			. esc_html( sprintf( 'Intertexere requires WordPress %s or newer and is not running.', INTERTEXERE_MINIMUM_WP_VERSION ) )
			. '</p></div>';
	}

	/**
	 * Eligibility changes require a new complete generation.
	 *
	 * @param mixed $old_value Previous option value.
	 * @param mixed $new_value New option value.
	 */
	public static function settings_changed( $old_value, $new_value ): void {
		if ( $old_value !== $new_value ) {
			Indexer::request_rebuild();
		}
	}
}
