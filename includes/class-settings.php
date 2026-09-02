<?php
/**
 * Persistent plugin settings.
 *
 * @package Intertexere
 */

namespace Intertexere;

final class Settings {
	public const OPTION = 'intertexere_settings';

	/**
	 * Install the initial settings without overwriting an existing choice.
	 */
	public static function install(): void {
		add_option( self::OPTION, self::defaults(), '', false );
	}

	/**
	 * Return the durable 0.1 defaults.
	 *
	 * Only public posts and pages are normal link targets in 0.1. Drafts,
	 * private posts, scheduled posts, attachments, revisions, and custom post
	 * types are excluded unless a later setting deliberately opts them in.
	 *
	 * @return array{eligible_post_types: string[], eligible_post_statuses: string[], enable_ai_enhancement: bool}
	 */
	public static function defaults(): array {
		return array(
			'eligible_post_types'    => array( 'post', 'page' ),
			'eligible_post_statuses' => array( 'publish' ),
			'enable_ai_enhancement'  => false,
		);
	}

	/**
	 * Return validated settings.
	 *
	 * @return array{eligible_post_types: string[], eligible_post_statuses: string[], enable_ai_enhancement: bool}
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, self::defaults() );

		if ( ! is_array( $stored ) ) {
			$stored = self::defaults();
		}

		return self::sanitize( $stored );
	}

	/**
	 * Sanitize settings at the persistence boundary.
	 *
	 * The 0.1 status contract is intentionally fixed to published content.
	 * Supporting other statuses requires a later product decision because
	 * private and unpublished content must not become normal link targets.
	 *
	 * @param mixed $value Submitted settings.
	 * @return array{eligible_post_types: string[], eligible_post_statuses: string[], enable_ai_enhancement: bool}
	 */
	public static function sanitize( $value ): array {
		$value      = is_array( $value ) ? $value : array();
		$post_types = isset( $value['eligible_post_types'] ) && is_array( $value['eligible_post_types'] )
			? $value['eligible_post_types']
			: array();

		$allowed = array_keys( self::available_post_types() );
		$clean   = array_values(
			array_unique(
				array_intersect(
					array_map( 'sanitize_key', $post_types ),
					$allowed
				)
			)
		);

		sort( $clean );

		return array(
			'eligible_post_types'    => $clean,
			'eligible_post_statuses' => array( 'publish' ),
			'enable_ai_enhancement'  => ! empty( $value['enable_ai_enhancement'] ),
		);
	}

	/**
	 * Merge a focused settings update without resetting unrelated values.
	 *
	 * @param array<string, mixed>      $changes Submitted recognized changes.
	 * @param array<string, mixed>|null $current Existing settings, or null to load them.
	 * @return array{eligible_post_types: string[], eligible_post_statuses: string[], enable_ai_enhancement: bool}
	 */
	public static function merge( array $changes, ?array $current = null ): array {
		$current = self::sanitize( null === $current ? self::get() : $current );

		if ( array_key_exists( 'eligible_post_types', $changes ) ) {
			$current['eligible_post_types'] = $changes['eligible_post_types'];
		}
		if ( array_key_exists( 'enable_ai_enhancement', $changes ) ) {
			$current['enable_ai_enhancement'] = $changes['enable_ai_enhancement'];
		}

		return self::sanitize( $current );
	}

	/**
	 * Return only settings whose changes require rebuilding derived data.
	 *
	 * @param mixed $value Settings value.
	 * @return array{eligible_post_types: string[], eligible_post_statuses: string[]}
	 */
	public static function eligibility_signature( $value ): array {
		$clean = self::sanitize( $value );

		return array(
			'eligible_post_types'    => $clean['eligible_post_types'],
			'eligible_post_statuses' => $clean['eligible_post_statuses'],
		);
	}

	/**
	 * Get public, viewable post types that may be selected for indexing.
	 *
	 * @return array<string, object>
	 */
	public static function available_post_types(): array {
		$objects = get_post_types( array( 'show_ui' => true ), 'objects' );

		return array_filter(
			$objects,
			static function ( $object ): bool {
				return 'attachment' !== $object->name && is_post_type_viewable( $object );
			}
		);
	}
}
