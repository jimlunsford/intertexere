<?php
/**
 * Content eligibility rules.
 *
 * @package Intertexere
 */

namespace Intertexere;

final class Eligibility {
	/**
	 * Determine whether a post is a valid 0.1 index target.
	 *
	 * @param int|\WP_Post $post Post ID or object.
	 */
	public static function is_eligible( $post ): bool {
		$post = get_post( $post );

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		$settings = Settings::get();
		$eligible = in_array( $post->post_type, $settings['eligible_post_types'], true )
			&& in_array( $post->post_status, $settings['eligible_post_statuses'], true )
			&& '' === (string) $post->post_password
			&& ! wp_is_post_revision( $post->ID )
			&& ! wp_is_post_autosave( $post->ID );

		/**
		 * Filter final eligibility after the deliberate core rules are applied.
		 *
		 * @param bool     $eligible Whether the post is eligible.
		 * @param \WP_Post $post     Current post object.
		 */
		return (bool) apply_filters( 'intertexere_is_post_eligible', $eligible, $post );
	}

	/**
	 * Return the configured eligible post types.
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		return Settings::get()['eligible_post_types'];
	}

	/**
	 * Return the configured eligible statuses.
	 *
	 * @return string[]
	 */
	public static function post_statuses(): array {
		return Settings::get()['eligible_post_statuses'];
	}
}
