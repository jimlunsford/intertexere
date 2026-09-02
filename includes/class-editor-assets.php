<?php
/**
 * Native Block Editor asset registration.
 *
 * @package Intertexere
 */

namespace Intertexere;

final class Editor_Assets {
	/**
	 * Register editor-only hooks.
	 */
	public static function register_hooks(): void {
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue' ) );
	}

	/**
	 * Enqueue the built native sidebar only for supported post editors.
	 */
	public static function enqueue(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base || ! Editor_Suggestions::is_supported_source_type( (string) $screen->post_type ) ) {
			return;
		}

		$asset_path = INTERTEXERE_PATH . 'build/editor/index.asset.php';
		$script_path = INTERTEXERE_PATH . 'build/editor/index.js';
		if ( ! is_readable( $asset_path ) || ! is_readable( $script_path ) ) {
			return;
		}

		$asset = require $asset_path;
		if ( ! is_array( $asset ) || ! isset( $asset['dependencies'], $asset['version'] ) ) {
			return;
		}

		wp_enqueue_script(
			'intertexere-editor',
			plugins_url( 'build/editor/index.js', INTERTEXERE_FILE ),
			(array) $asset['dependencies'],
			(string) $asset['version'],
			true
		);

		$style_path = INTERTEXERE_PATH . 'build/editor/style-index.css';
		if ( is_readable( $style_path ) ) {
			wp_enqueue_style(
				'intertexere-editor',
				plugins_url( 'build/editor/style-index.css', INTERTEXERE_FILE ),
				array( 'wp-components' ),
				(string) filemtime( $style_path )
			);
			wp_style_add_data( 'intertexere-editor', 'rtl', 'replace' );
		}

		wp_add_inline_script(
			'intertexere-editor',
			'window.IntertexereEditorSettings = ' . wp_json_encode( self::settings( (string) $screen->post_type ) ) . ';',
			'before'
		);
	}

	/**
	 * Return non-secret editor configuration.
	 *
	 * @return array<string, mixed>
	 */
	private static function settings( string $post_type ): array {
		$taxonomies = array();
		$ai         = AI_Enhancement::availability();
		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy ) {
			if ( empty( $taxonomy->show_in_rest ) ) {
				continue;
			}
			$taxonomies[ $taxonomy->name ] = $taxonomy->rest_base ?: $taxonomy->name;
		}
		ksort( $taxonomies );

		return array(
			'route'          => '/' . Editor_REST::NAMESPACE . Editor_REST::ROUTE,
			'aiRoute'        => '/' . Editor_REST::NAMESPACE . Editor_REST::AI_ROUTE,
			'ai'             => array(
				'enabled'         => $ai['enabled'],
				'available'       => $ai['available'],
				'contractVersion' => AI_Enhancement::CONTRACT_VERSION,
				'promptVersion'   => AI_Enhancement::PROMPT_VERSION,
				'maxCandidates'   => AI_Enhancement::MAX_CANDIDATES,
			),
			'postType'       => $post_type,
			'taxonomyFields' => $taxonomies,
			'limits'         => array(
				'payloadBytes' => Editor_Suggestions::MAX_PAYLOAD_BYTES,
				'units'        => Editor_Suggestions::MAX_UNITS,
				'unitBytes'    => Editor_Suggestions::MAX_UNIT_BYTES,
			),
		);
	}
}
