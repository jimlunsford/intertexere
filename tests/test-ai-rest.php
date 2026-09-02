<?php
/**
 * AI enhancement REST authentication and transport tests.
 */

use Intertexere\AI_Enhancement;
use Intertexere\Editor_REST;
use Intertexere\Editor_Suggestions;
use Intertexere\Settings;

class Intertexere_AI_REST_Test extends WP_UnitTestCase {
	private int $administrator_id;

	public function set_up(): void {
		parent::set_up();
		update_option( Settings::OPTION, Settings::defaults(), false );
		$this->administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		remove_all_actions( 'intertexere_ai_rest_before_enhancement' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_ai_route_is_post_only_nonce_protected_and_requires_source_capability(): void {
		$get = new WP_REST_Request( 'GET', '/' . Editor_REST::NAMESPACE . Editor_REST::AI_ROUTE );
		$this->assertSame( 404, rest_get_server()->dispatch( $get )->get_status() );

		wp_set_current_user( $this->administrator_id );
		$missing_nonce = $this->request( $this->payload(), false );
		$this->assertSame( 403, rest_get_server()->dispatch( $missing_nonce )->get_status() );

		$authorized = rest_get_server()->dispatch( $this->request( $this->payload() ) );
		$this->assertSame( 403, $authorized->get_status() );
		$this->assertSame( 'intertexere_ai_disabled', $authorized->get_data()['code'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$forbidden = rest_get_server()->dispatch( $this->request( $this->payload() ) );
		$this->assertSame( 403, $forbidden->get_status() );
		$this->assertSame( 'intertexere_rest_forbidden', $forbidden->get_data()['code'] );
	}

	public function test_ai_raw_body_limit_runs_before_json_parsing_and_leaves_other_routes_untouched(): void {
		wp_set_current_user( $this->administrator_id );
		$calls = 0;
		add_action( 'intertexere_ai_rest_before_enhancement', static function () use ( &$calls ): void {
			++$calls;
		} );

		$malformed = '{"draft":"' . str_repeat( 'x', Editor_Suggestions::MAX_PAYLOAD_BYTES );
		$request = $this->raw_request( $malformed );
		$request->set_header( 'Content-Length', '1' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 413, $response->get_status() );
		$this->assertSame( 'intertexere_payload_too_large', $response->get_data()['code'] );
		$this->assertSame( 0, $calls );

		$small = rest_get_server()->dispatch( $this->raw_request( '{"draft":' ) );
		$this->assertSame( 400, $small->get_status() );
		$this->assertSame( 'rest_invalid_json', $small->get_data()['code'] );
		$this->assertSame( 0, $calls );

		$unrelated = new WP_REST_Request( 'POST', '/wp/v2/posts' );
		$unrelated->set_header( 'Content-Type', 'application/json' );
		$unrelated->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$unrelated->set_body( $malformed );
		$this->assertSame( 'rest_invalid_json', rest_get_server()->dispatch( $unrelated )->get_data()['code'] );
	}

	/** @return array<string, mixed> */
	private function payload(): array {
		return array(
			'draft' => array(
				'post_id'    => 0,
				'post_type'  => 'post',
				'title'      => 'AI REST boundary',
				'taxonomies' => array(),
				'units'      => array(
					array(
						'client_id' => 'ai-rest-paragraph',
						'block_name'=> 'core/paragraph',
						'markup'    => '<!-- wp:paragraph --><p>AI REST boundary remains read only.</p><!-- /wp:paragraph -->',
					),
				),
			),
			'analysis_id' => str_repeat( 'a', 64 ),
			'candidate_ids' => array( 1 ),
		);
	}

	private function request( array $payload, bool $nonce = true ): WP_REST_Request {
		return $this->raw_request( (string) wp_json_encode( $payload ), $nonce );
	}

	private function raw_request( string $body, bool $nonce = true ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/' . Editor_REST::NAMESPACE . Editor_REST::AI_ROUTE );
		$request->set_header( 'Content-Type', 'application/json; charset=UTF-8' );
		if ( $nonce ) {
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		$request->set_body( $body );
		return $request;
	}
}
