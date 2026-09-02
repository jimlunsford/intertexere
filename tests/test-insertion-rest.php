<?php
/**
 * Dedicated insertion-validation REST boundary tests.
 */

use Intertexere\Editor_REST;
use Intertexere\Editor_Suggestions;
use Intertexere\Indexer;
use Intertexere\Link_Graph;
use Intertexere\Settings;

class Intertexere_Insertion_REST_Test extends WP_UnitTestCase {
	private int $administrator_id;
	private int $source_id;
	private int $target_id;
	/** @var array<string, mixed> */
	private array $payload;

	public function set_up(): void {
		parent::set_up();
		update_option( Settings::OPTION, Settings::defaults(), false );
		Indexer::reset();
		Link_Graph::reset();
		$this->administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->administrator_id );
		$this->source_id = self::factory()->post->create(
			array( 'post_status' => 'draft', 'post_author' => $this->administrator_id )
		);
		$this->target_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'REST Insertion Target',
				'post_content' => '<p>Current target content.</p>',
			)
		);
		Indexer::refresh_post( $this->target_id );
		Link_Graph::refresh_post( $this->target_id );
		$draft = array(
			'post_id'    => $this->source_id,
			'post_type'  => 'post',
			'title'      => 'Unsaved REST insertion source',
			'taxonomies' => array(),
			'units'      => array(
				array(
					'client_id' => 'rest-insertion-paragraph',
					'block_name'=> 'core/paragraph',
					'markup'    => '<!-- wp:paragraph --><p>Use REST Insertion Target here.</p><!-- /wp:paragraph -->',
				),
			),
		);
		$analysis = Editor_Suggestions::analyze( $draft );
		$this->assertIsArray( $analysis );
		$this->payload = array(
			'draft'          => $draft,
			'analysis_id'    => $analysis['analysis_id'],
			'target_post_id' => $this->target_id,
			'source_kind'    => 'deterministic',
			'anchor'         => array(
				'block_client_id' => 'rest-insertion-paragraph',
				'block_name'      => 'core/paragraph',
				'exact_text'      => 'REST Insertion Target',
				'occurrence'      => 0,
				'unit_key'       => null,
			),
			'draft_links'    => array(),
		);
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		remove_all_actions( 'intertexere_insertion_rest_before_validation' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_authenticated_nonce_protected_post_route_validates_without_writing_source(): void {
		$before = get_post_field( 'post_content', $this->source_id );
		$response = rest_get_server()->dispatch( $this->request( $this->payload ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( get_permalink( $this->target_id ), $response->get_data()['current_permalink'] );
		$this->assertSame( $before, get_post_field( 'post_content', $this->source_id ) );

		$get = new WP_REST_Request( 'GET', '/' . Editor_REST::NAMESPACE . Editor_REST::INSERTION_ROUTE );
		$this->assertSame( 404, rest_get_server()->dispatch( $get )->get_status() );
	}

	public function test_nonce_login_and_source_capability_are_mandatory(): void {
		$missing = $this->request( $this->payload, false );
		$this->assertSame( 403, rest_get_server()->dispatch( $missing )->get_status() );

		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $other );
		$this->assertSame( 403, rest_get_server()->dispatch( $this->request( $this->payload ) )->get_status() );

		wp_set_current_user( 0 );
		$logged_out = $this->request( $this->payload, false );
		$logged_out->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$this->assertSame( 401, rest_get_server()->dispatch( $logged_out )->get_status() );
	}

	public function test_raw_actual_body_limit_runs_before_json_parsing_and_only_for_exact_route(): void {
		$calls = 0;
		add_action(
			'intertexere_insertion_rest_before_validation',
			static function () use ( &$calls ): void {
				++$calls;
			}
		);
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

		$other_route = new WP_REST_Request( 'POST', '/wp/v2/posts' );
		$other_route->set_header( 'Content-Type', 'application/json' );
		$other_route->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$other_route->set_header( 'Content-Length', '1' );
		$other_route->set_body( $malformed );
		$this->assertSame( 'rest_invalid_json', rest_get_server()->dispatch( $other_route )->get_data()['code'] );
	}

	public function test_unknown_fields_and_non_json_transport_fail_without_validation(): void {
		$calls = 0;
		add_action(
			'intertexere_insertion_rest_before_validation',
			static function () use ( &$calls ): void {
				++$calls;
			}
		);
		$unknown = $this->payload;
		$unknown['permalink'] = 'https://client.example/untrusted';
		$response = rest_get_server()->dispatch( $this->request( $unknown ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'intertexere_insertion_invalid_request', $response->get_data()['code'] );
		$this->assertSame( 1, $calls );

		$request = $this->request( $this->payload );
		$request->set_header( 'Content-Type', 'text/plain' );
		$this->assertSame( 415, rest_get_server()->dispatch( $request )->get_status() );
		$this->assertSame( 1, $calls );
	}

	/** @param array<string, mixed> $payload */
	private function request( array $payload, bool $nonce = true ): WP_REST_Request {
		return $this->raw_request( wp_json_encode( $payload ), $nonce );
	}

	private function raw_request( string $body, bool $nonce = true ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/' . Editor_REST::NAMESPACE . Editor_REST::INSERTION_ROUTE );
		$request->set_header( 'Content-Type', 'application/json; charset=UTF-8' );
		if ( $nonce ) {
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		$request->set_body( $body );
		return $request;
	}
}
