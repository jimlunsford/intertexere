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
	public const AI_ROUTE = '/editor-suggestions/ai-enhance';

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

		register_rest_route(
			self::NAMESPACE,
			self::AI_ROUTE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'enhance_with_ai' ),
				'permission_callback' => array( self::class, 'permissions' ),
			)
		);
	}

	/**
	 * Reject an oversized editor-analysis body before Core parses JSON.
	 *
	 * WP_REST_Server::dispatch() applies rest_pre_dispatch before route matching
	 * and before WP_REST_Request::has_valid_params() calls parse_json_params().
	 * Returning a WP_Error here short-circuits the dispatcher at the transport
	 * boundary. Every other method and route retains normal Core behavior.
	 *
	 * @param mixed           $result  Earlier pre-dispatch result.
	 * @param \WP_REST_Server  $server  REST server instance.
	 * @param \WP_REST_Request $request Request being dispatched.
	 * @return mixed|\WP_Error
	 */
	public static function enforce_raw_body_limit( $result, \WP_REST_Server $server, \WP_REST_Request $request ) {
		if ( ! empty( $result ) ) {
			return $result;
		}

		if ( 'POST' !== strtoupper( $request->get_method() )
			|| ! in_array( $request->get_route(), self::bounded_routes(), true ) ) {
			return $result;
		}

		$transport = self::validate_transport_size( $request );
		return is_wp_error( $transport ) ? $transport : $result;
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

		$transport = self::validate_transport_size( $request );
		if ( is_wp_error( $transport ) ) {
			return $transport;
		}

		$payload   = $request->get_json_params();
		if ( '/' . self::NAMESPACE . self::AI_ROUTE === $request->get_route() ) {
			$payload = is_array( $payload ) && isset( $payload['draft'] ) ? $payload['draft'] : null;
		}
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

		$transport = self::validate_transport_size( $request );
		if ( is_wp_error( $transport ) ) {
			return $transport;
		}

		/**
		 * Fires after transport validation and immediately before draft analysis.
		 *
		 * This read-boundary hook exists for observability and boundary tests.
		 */
		do_action( 'intertexere_editor_rest_before_analysis', $request );

		$result = Editor_Suggestions::analyze( $request->get_json_params() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Explicitly enhance a current deterministic result without mutation.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function enhance_with_ai( \WP_REST_Request $request ) {
		if ( ! self::has_json_content_type( $request ) ) {
			return self::json_required_error();
		}

		$transport = self::validate_transport_size( $request );
		if ( is_wp_error( $transport ) ) {
			return $transport;
		}

		do_action( 'intertexere_ai_rest_before_enhancement', $request );
		$result = AI_Enhancement::enhance( $request->get_json_params() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	private static function has_json_content_type( \WP_REST_Request $request ): bool {
		return 'application/json' === strtolower( trim( explode( ';', (string) $request->get_header( 'Content-Type' ) )[0] ) );
	}

	/**
	 * Enforce the encoded transport boundary before parsing JSON.
	 *
	 * Content-Length is advisory. The body held by WP_REST_Request is the
	 * authoritative byte sequence that the JSON parser would consume.
	 *
	 * @return true|\WP_Error
	 */
	private static function validate_transport_size( \WP_REST_Request $request ) {
		if ( strlen( $request->get_body() ) <= Editor_Suggestions::MAX_PAYLOAD_BYTES ) {
			return true;
		}

		return new \WP_Error(
			'intertexere_payload_too_large',
			'The draft analysis request exceeds the 256 KiB encoded limit.',
			array( 'status' => 413 )
		);
	}

	private static function json_required_error(): \WP_Error {
		return new \WP_Error(
			'intertexere_json_required',
			'Editor analysis requires an application/json request.',
			array( 'status' => 415 )
		);
	}

	/** @return string[] */
	private static function bounded_routes(): array {
		return array(
			'/' . self::NAMESPACE . self::ROUTE,
			'/' . self::NAMESPACE . self::AI_ROUTE,
		);
	}
}
