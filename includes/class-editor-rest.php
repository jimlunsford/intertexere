<?php
/**
 * Authenticated read-only editor analysis REST boundary.
 *
 * @package Intertexere
 */

namespace Intertexere;

final class Editor_REST {
	public const NAMESPACE = 'intertexere/v1';
	public const ROUTE = '/editor-suggestions';

	/**
	 * Register the route.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'analyze' ),
				'permission_callback' => array( self::class, 'permissions' ),
			)
		);
	}

	/**
	 * Require a REST nonce and the applicable source-edit capability.
	 *
	 * @return true|\WP_Error
	 */
	public static function permissions( \WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! is_string( $nonce ) || '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error(
				'intertexere_rest_nonce_required',
				'Editor analysis requires a valid WordPress REST nonce.',
				array( 'status' => 403 )
			);
		}

		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'intertexere_rest_forbidden',
				'You are not allowed to analyze this draft.',
				array( 'status' => 401 )
			);
		}

		if ( ! self::has_json_content_type( $request ) ) {
			return self::json_required_error();
		}

		$payload   = $request->get_json_params();
		$post_id   = is_array( $payload ) && isset( $payload['post_id'] ) && is_int( $payload['post_id'] ) ? $payload['post_id'] : null;
		$post_type = is_array( $payload ) && isset( $payload['post_type'] ) && is_string( $payload['post_type'] ) ? $payload['post_type'] : '';

		if ( null === $post_id || $post_id < 0 || ! Editor_Suggestions::is_supported_source_type( $post_type ) ) {
			return new \WP_Error(
				'intertexere_rest_forbidden',
				'This editor source is not available for analysis.',
				array( 'status' => 403 )
			);
		}

		if ( $post_id > 0 ) {
			return current_user_can( 'edit_post', $post_id )
				? true
				: new \WP_Error( 'intertexere_rest_forbidden', 'You are not allowed to analyze this draft.', array( 'status' => 403 ) );
		}

		$object = get_post_type_object( $post_type );
		return $object && current_user_can( $object->cap->edit_posts )
			? true
			: new \WP_Error( 'intertexere_rest_forbidden', 'You are not allowed to analyze this draft.', array( 'status' => 403 ) );
	}

	/**
	 * Analyze submitted editor state without persisting it.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function analyze( \WP_REST_Request $request ) {
		if ( ! self::has_json_content_type( $request ) ) {
			return self::json_required_error();
		}

		$result = Editor_Suggestions::analyze( $request->get_json_params() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	private static function has_json_content_type( \WP_REST_Request $request ): bool {
		return 'application/json' === strtolower( trim( explode( ';', (string) $request->get_header( 'Content-Type' ) )[0] ) );
	}

	private static function json_required_error(): \WP_Error {
		return new \WP_Error(
			'intertexere_json_required',
			'Editor analysis requires an application/json request.',
			array( 'status' => 415 )
		);
	}
}
