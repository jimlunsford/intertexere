<?php
/**
 * Editor suggestion REST route permissions and transport tests.
 */

use Intertexere\Editor_REST;
use Intertexere\Editor_Suggestions;
use Intertexere\Indexer;
use Intertexere\Link_Graph;
use Intertexere\Settings;

class Intertexere_Editor_REST_Test extends WP_UnitTestCase {
	private $administrator_id;

	public function set_up(): void {
		parent::set_up();
		update_option( Settings::OPTION, Settings::defaults(), false );
		Indexer::reset();
		Link_Graph::reset();
		$this->administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		remove_all_actions( 'intertexere_editor_rest_before_analysis' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_authenticated_editor_can_analyze_existing_unsaved_source(): void {
		$source = self::factory()->post->create( array( 'post_status' => 'draft', 'post_author' => $this->administrator_id ) );
		wp_set_current_user( $this->administrator_id );
		$response = rest_get_server()->dispatch( $this->request( $this->payload( $source ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['contract_version'] );
		$this->assertArrayHasKey( 'suggestions', $response->get_data() );
	}

	public function test_new_source_requires_post_type_edit_capability(): void {
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		wp_set_current_user( $contributor );
		$response = rest_get_server()->dispatch( $this->request( $this->payload( 0 ) ) );
		$this->assertSame( 200, $response->get_status() );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$forbidden = rest_get_server()->dispatch( $this->request( $this->payload( 0 ) ) );
		$this->assertSame( 403, $forbidden->get_status() );
		$this->assertSame( 'intertexere_rest_forbidden', $forbidden->get_data()['code'] );
	}

	public function test_existing_source_requires_edit_post_for_that_post(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$other_author = self::factory()->user->create( array( 'role' => 'author' ) );
		$source = self::factory()->post->create( array( 'post_status' => 'draft', 'post_author' => $author ) );
		wp_set_current_user( $other_author );
		$response = rest_get_server()->dispatch( $this->request( $this->payload( $source ) ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'intertexere_rest_forbidden', $response->get_data()['code'] );
	}

	public function test_missing_invalid_nonce_and_logged_out_requests_fail(): void {
		wp_set_current_user( $this->administrator_id );
		$missing = $this->request( $this->payload( 0 ), false );
		$missing_response = rest_get_server()->dispatch( $missing );
		$this->assertSame( 403, $missing_response->get_status() );
		$this->assertSame( 'intertexere_rest_nonce_required', $missing_response->get_data()['code'] );

		$invalid = $this->request( $this->payload( 0 ), false );
		$invalid->set_header( 'X-WP-Nonce', 'invalid' );
		$this->assertSame( 403, rest_get_server()->dispatch( $invalid )->get_status() );

		wp_set_current_user( 0 );
		$logged_out = $this->request( $this->payload( 0 ), false );
		$logged_out->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$this->assertSame( 401, rest_get_server()->dispatch( $logged_out )->get_status() );
	}

	public function test_json_transport_and_strict_payload_errors_are_non_sensitive(): void {
		wp_set_current_user( $this->administrator_id );
		$wrong_content_type = $this->request( $this->payload( 0 ) );
		$wrong_content_type->set_header( 'Content-Type', 'text/plain' );
		$response = rest_get_server()->dispatch( $wrong_content_type );
		$this->assertSame( 415, $response->get_status() );
		$this->assertSame( 'intertexere_json_required', $response->get_data()['code'] );

		$unknown = $this->payload( 0 );
		$unknown['unexpected'] = 'value';
		$invalid = rest_get_server()->dispatch( $this->request( $unknown ) );
		$this->assertSame( 400, $invalid->get_status() );
		$this->assertSame( 'intertexere_invalid_draft', $invalid->get_data()['code'] );
		$this->assertStringNotContainsString( ABSPATH, wp_json_encode( $invalid->get_data() ) );
	}

	public function test_route_is_post_only_and_has_no_mutation_side_effect(): void {
		global $wpdb;
		wp_set_current_user( $this->administrator_id );
		$get = new WP_REST_Request( 'GET', '/' . Editor_REST::NAMESPACE . Editor_REST::ROUTE );
		$this->assertSame( 404, rest_get_server()->dispatch( $get )->get_status() );

		$before_posts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );
		$before_options = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );
		$response = rest_get_server()->dispatch( $this->request( $this->payload( 0 ) ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $before_posts, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ) );
		$this->assertSame( $before_options, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" ) );
	}

	public function test_raw_transport_limit_uses_actual_body_bytes_before_analysis(): void {
		wp_set_current_user( $this->administrator_id );
		$analysis_calls = 0;
		add_action(
			'intertexere_editor_rest_before_analysis',
			static function () use ( &$analysis_calls ): void {
				++$analysis_calls;
			}
		);

		$ordinary = wp_json_encode( $this->payload( 0 ) );
		$this->assertIsString( $ordinary );
		$this->assertLessThan( Editor_Suggestions::MAX_PAYLOAD_BYTES, strlen( $ordinary ) );
		$this->assertSame( 200, rest_get_server()->dispatch( $this->raw_request( $ordinary ) )->get_status() );
		$this->assertSame( 1, $analysis_calls );

		$oversized = str_repeat( ' ', Editor_Suggestions::MAX_PAYLOAD_BYTES ) . $ordinary;
		$request = $this->raw_request( $oversized );
		$request->set_header( 'Content-Length', '1' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 413, $response->get_status() );
		$this->assertSame( 'intertexere_payload_too_large', $response->get_data()['code'] );
		$this->assertSame( 1, $analysis_calls );
	}

	public function test_json_escape_sequences_cannot_shrink_past_the_raw_transport_limit(): void {
		wp_set_current_user( $this->administrator_id );
		$analysis_calls = 0;
		add_action(
			'intertexere_editor_rest_before_analysis',
			static function () use ( &$analysis_calls ): void {
				++$analysis_calls;
			}
		);

		$raw = $this->escaped_oversized_body();
		$decoded = json_decode( $raw, true );
		$this->assertIsArray( $decoded );
		$this->assertGreaterThan( Editor_Suggestions::MAX_PAYLOAD_BYTES, strlen( $raw ) );
		$this->assertLessThan( Editor_Suggestions::MAX_PAYLOAD_BYTES, strlen( wp_json_encode( $decoded ) ) );

		$response = rest_get_server()->dispatch( $this->raw_request( $raw ) );
		$this->assertSame( 413, $response->get_status() );
		$this->assertSame( 'intertexere_payload_too_large', $response->get_data()['code'] );
		$this->assertSame( 0, $analysis_calls );
	}

	public function test_oversized_malformed_json_is_rejected_before_core_json_validation(): void {
		wp_set_current_user( $this->administrator_id );
		$analysis_calls = 0;
		add_action(
			'intertexere_editor_rest_before_analysis',
			static function () use ( &$analysis_calls ): void {
				++$analysis_calls;
			}
		);

		// This body is intentionally malformed. If Core parses it before the
		// transport guard, dispatch returns rest_invalid_json instead of 413.
		$malformed_oversized = '{"post_id":0,"broken":"'
			. str_repeat( 'x', Editor_Suggestions::MAX_PAYLOAD_BYTES );
		$this->assertGreaterThan( Editor_Suggestions::MAX_PAYLOAD_BYTES, strlen( $malformed_oversized ) );

		$request = $this->raw_request( $malformed_oversized );
		$request->set_header( 'Content-Length', '1' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 413, $response->get_status() );
		$this->assertSame( 'intertexere_payload_too_large', $response->get_data()['code'] );
		$this->assertSame( 0, $analysis_calls );

		$malformed_small = rest_get_server()->dispatch( $this->raw_request( '{"post_id":0' ) );
		$this->assertSame( 400, $malformed_small->get_status() );
		$this->assertSame( 'rest_invalid_json', $malformed_small->get_data()['code'] );
		$this->assertSame( 0, $analysis_calls );
	}

	public function test_raw_body_guard_leaves_unrelated_rest_routes_untouched(): void {
		wp_set_current_user( $this->administrator_id );

		$get = new WP_REST_Request( 'GET', '/wp/v2/types/post' );
		$this->assertSame( 200, rest_get_server()->dispatch( $get )->get_status() );

		$malformed_oversized = '{"title":"'
			. str_repeat( 'x', Editor_Suggestions::MAX_PAYLOAD_BYTES );
		$unrelated = new WP_REST_Request( 'POST', '/wp/v2/posts' );
		$unrelated->set_header( 'Content-Type', 'application/json' );
		$unrelated->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$unrelated->set_header( 'Content-Length', '1' );
		$unrelated->set_body( $malformed_oversized );

		$response = rest_get_server()->dispatch( $unrelated );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_json', $response->get_data()['code'] );
	}

	public function test_rest_boundary_retains_unit_count_and_per_unit_limits(): void {
		wp_set_current_user( $this->administrator_id );
		$unit = $this->payload( 0 )['units'][0];

		$too_many = $this->payload( 0 );
		$too_many['units'] = array_fill( 0, Editor_Suggestions::MAX_UNITS + 1, $unit );
		$count_response = rest_get_server()->dispatch( $this->request( $too_many ) );
		$this->assertSame( 413, $count_response->get_status() );
		$this->assertSame( 'intertexere_too_many_units', $count_response->get_data()['code'] );

		$too_large = $this->payload( 0 );
		$too_large['units'][0]['markup'] = '<!-- wp:paragraph --><p>'
			. str_repeat( 'x', Editor_Suggestions::MAX_UNIT_BYTES )
			. '</p><!-- /wp:paragraph -->';
		$unit_response = rest_get_server()->dispatch( $this->request( $too_large ) );
		$this->assertSame( 413, $unit_response->get_status() );
		$this->assertSame( 'intertexere_unit_too_large', $unit_response->get_data()['code'] );
	}

	/** @return array<string, mixed> */
	private function payload( int $post_id ): array {
		return array(
			'post_id'    => $post_id,
			'post_type'  => 'post',
			'title'      => 'REST unsaved draft title',
			'taxonomies' => array(),
			'units'      => array(
				array(
					'client_id' => 'rest-paragraph',
					'block_name'=> 'core/paragraph',
					'markup'    => '<!-- wp:paragraph --><p>REST unsaved draft content stays read only.</p><!-- /wp:paragraph -->',
				),
			),
		);
	}

	private function request( array $payload, bool $with_nonce = true ): WP_REST_Request {
		return $this->raw_request( wp_json_encode( $payload ), $with_nonce );
	}

	private function raw_request( string $body, bool $with_nonce = true ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/' . Editor_REST::NAMESPACE . Editor_REST::ROUTE );
		$request->set_header( 'Content-Type', 'application/json; charset=UTF-8' );
		if ( $with_nonce ) {
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		$request->set_body( $body );
		return $request;
	}

	private function escaped_oversized_body(): string {
		$escaped = str_repeat( '\u0061', 7000 );
		$units = array();
		for ( $index = 0; $index < 7; ++$index ) {
			$units[] = '{"client_id":"escaped-' . $index
				. '","block_name":"core/paragraph","markup":"<!-- wp:paragraph --><p>'
				. $escaped . '</p><!-- /wp:paragraph -->"}';
		}

		return '{"post_id":0,"post_type":"post","title":"Escaped transport boundary",'
			. '"taxonomies":{},"units":[' . implode( ',', $units ) . ']}';
	}
}
