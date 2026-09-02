<?php
/**
 * Explicit insertion authority and zero-mutation tests.
 */

use Intertexere\Editor_REST;
use Intertexere\Editor_Suggestions;
use Intertexere\Indexer;
use Intertexere\Insertion_Validation;
use Intertexere\Link_Graph;
use Intertexere\Schema;
use Intertexere\Settings;

class Intertexere_Insertion_Validation_Test extends WP_UnitTestCase {
	private int $administrator_id;
	private int $source_id;
	private int $target_id;
	/** @var array<string, mixed> */
	private array $draft;
	/** @var array<string, mixed> */
	private array $analysis;

	public function set_up(): void {
		parent::set_up();
		update_option( Settings::OPTION, Settings::defaults(), false );
		Indexer::reset();
		Link_Graph::reset();
		$this->administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->administrator_id );
		$this->source_id = self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_author'  => $this->administrator_id,
				'post_title'   => 'Insertion source',
				'post_content' => '<!-- wp:paragraph --><p>Persisted source remains unchanged.</p><!-- /wp:paragraph -->',
			)
		);
		$this->target_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Insertion Target Alpha',
				'post_name'    => 'insertion-target-alpha',
				'post_content' => '<!-- wp:paragraph --><p>Insertion Target Alpha offers focused supporting context.</p><!-- /wp:paragraph -->',
			)
		);
		Indexer::refresh_post( $this->target_id );
		Link_Graph::refresh_post( $this->target_id );
		$this->draft = $this->draft_with_markup(
			'<!-- wp:paragraph --><p>Before Insertion Target Alpha after.</p><!-- /wp:paragraph -->'
		);
		$this->refresh_analysis();
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		remove_all_actions( 'intertexere_insertion_validation_after_target_snapshot' );
		remove_all_actions( 'intertexere_insertion_validation_before_final_target' );
		remove_all_actions( 'intertexere_insertion_rest_before_validation' );
		remove_all_filters( 'intertexere_is_post_eligible' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_success_returns_only_current_bounded_authority_and_performance(): void {
		$result = Insertion_Validation::validate( $this->request_payload() );
		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['contract_version'] );
		$this->assertSame( $this->analysis['analysis_id'], $result['analysis_id'] );
		$this->assertSame( $this->analysis['draft_hash'], $result['draft_hash'] );
		$this->assertSame( $this->target_id, $result['target_post_id'] );
		$this->assertSame( get_permalink( $this->target_id ), $result['current_permalink'] );
		$this->assertSame( 'content', $result['block']['attribute'] );
		$this->assertSame( 'Insertion Target Alpha', $result['anchor']['exact_text'] );

		$metrics = Insertion_Validation::last_metrics();
		fwrite( STDOUT, sprintf( "\n0.5 insertion fixture: %d queries total, %d insertion-specific queries, %.3f ms total, %.3f ms insertion-specific\n", $metrics['total_query_count'], $metrics['insertion_query_count'], $metrics['total_ms'], $metrics['insertion_ms'] ) );
		$this->assertLessThanOrEqual( 16, $metrics['total_query_count'] );
		$this->assertLessThanOrEqual( 4, $metrics['insertion_query_count'] );
		$this->assertLessThan( 3250, $metrics['total_ms'] );
		$this->assertLessThan( 250, $metrics['insertion_ms'] );
	}

	public function test_established_125_post_fixture_stays_within_validation_budgets(): void {
		for ( $index = 0; $index < 124; ++$index ) {
			$post_id = self::factory()->post->create(
				array(
					'post_status'  => 'publish',
					'post_title'   => 'Bounded insertion corpus ' . $index,
					'post_content' => '<p>Distinct bounded fixture material ' . $index . '.</p>',
				)
			);
			Indexer::refresh_post( $post_id );
			Link_Graph::refresh_post( $post_id );
		}
		$this->refresh_analysis();
		$result = Insertion_Validation::validate( $this->request_payload() );
		$this->assertIsArray( $result );
		$metrics = Insertion_Validation::last_metrics();
		fwrite( STDOUT, sprintf( "\n0.5 125-post fixture: %d queries total, %d insertion-specific queries, %.3f ms total, %.3f ms insertion-specific\n", $metrics['total_query_count'], $metrics['insertion_query_count'], $metrics['total_ms'], $metrics['insertion_ms'] ) );
		$this->assertLessThanOrEqual( 16, $metrics['total_query_count'] );
		$this->assertLessThanOrEqual( 4, $metrics['insertion_query_count'] );
		$this->assertLessThan( 3250, $metrics['total_ms'] );
		$this->assertLessThan( 250, $metrics['insertion_ms'] );
	}

	public function test_source_capability_and_supported_post_type_are_required(): void {
		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $other );
		$this->assertError( 'intertexere_insertion_forbidden', Insertion_Validation::validate( $this->request_payload() ) );

		wp_set_current_user( $this->administrator_id );
		$request = $this->request_payload();
		$request['draft']['post_type'] = 'attachment';
		$this->assertError( 'intertexere_invalid_draft', Insertion_Validation::validate( $request ) );
	}

	public function test_strict_request_anchor_and_bounds_reject_unknown_or_malformed_evidence(): void {
		$request = $this->request_payload();
		$request['unknown'] = true;
		$this->assertError( 'intertexere_insertion_invalid_request', Insertion_Validation::validate( $request ) );

		$request = $this->request_payload();
		$request['anchor']['unknown'] = true;
		$this->assertError( 'intertexere_insertion_invalid_request', Insertion_Validation::validate( $request ) );

		$request = $this->request_payload();
		$request['anchor']['exact_text'] = str_repeat( 'x', Insertion_Validation::MAX_ANCHOR_CHARACTERS + 1 );
		$this->assertError( 'intertexere_insertion_invalid_request', Insertion_Validation::validate( $request ) );

		$request = $this->request_payload();
		$request['anchor']['occurrence'] = -1;
		$this->assertError( 'intertexere_insertion_invalid_request', Insertion_Validation::validate( $request ) );

		$request = $this->request_payload();
		$request['anchor']['block_name'] = 'core/quote';
		$this->assertError( 'intertexere_insertion_unsupported', Insertion_Validation::validate( $request ) );
	}

	public function test_self_target_and_stale_analysis_identity_are_rejected(): void {
		$request = $this->request_payload();
		$request['target_post_id'] = $this->source_id;
		$this->assertError( 'intertexere_insertion_self_target', Insertion_Validation::validate( $request ) );

		$request = $this->request_payload();
		$request['analysis_id'] = str_repeat( '0', 64 );
		$this->assertError( 'intertexere_insertion_stale', Insertion_Validation::validate( $request ) );

		$request = $this->request_payload();
		$request['draft']['title'] = 'Changed canonical draft';
		$this->assertError( 'intertexere_insertion_stale', Insertion_Validation::validate( $request ) );
	}

	public function test_current_candidate_membership_and_exact_case_sensitive_occurrence_are_required(): void {
		$request = $this->request_payload();
		$request['anchor']['exact_text'] = 'insertion Target Alpha';
		$this->assertError( 'intertexere_insertion_stale', Insertion_Validation::validate( $request ) );

		$request = $this->request_payload();
		$request['anchor']['occurrence'] = 1;
		$this->assertError( 'intertexere_insertion_stale', Insertion_Validation::validate( $request ) );

		$request = $this->request_payload();
		$request['anchor']['exact_text'] = 'Before';
		$this->assertError( 'intertexere_insertion_stale', Insertion_Validation::validate( $request ) );

		$other_target = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'Unrelated target' ) );
		Indexer::refresh_post( $other_target );
		$request = $this->request_payload();
		$request['target_post_id'] = $other_target;
		$this->assertError( 'intertexere_insertion_stale', Insertion_Validation::validate( $request ) );
	}

	public function test_repeated_unicode_anchor_occurrence_is_exact(): void {
		$this->draft = $this->draft_with_markup(
			'<!-- wp:paragraph --><p>🙂 Insertion Target Alpha, then Insertion Target Alpha.</p><!-- /wp:paragraph -->'
		);
		$this->refresh_analysis();
		$request = $this->request_payload();
		$request['anchor']['occurrence'] = 1;
		$result = Insertion_Validation::validate( $request );
		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['anchor']['occurrence'] );
		$this->assertSame( 31, $result['anchor']['start'] );
	}

	public function test_target_existence_status_password_type_and_configured_eligibility(): void {
		$cases = array(
			'unpublished' => array( 'post_status' => 'draft' ),
			'password'    => array( 'post_password' => 'secret' ),
			'post type'   => array( 'post_type' => 'page' ),
		);
		foreach ( $cases as $label => $change ) {
			$this->restore_target();
			wp_update_post( array_merge( array( 'ID' => $this->target_id ), $change ) );
			clean_post_cache( $this->target_id );
			$this->assertError( 'intertexere_insertion_target_unavailable', Insertion_Validation::validate( $this->request_payload() ), $label );
		}

		$this->restore_target();
		add_filter( 'intertexere_is_post_eligible', '__return_false' );
		$this->assertError( 'intertexere_insertion_target_unavailable', Insertion_Validation::validate( $this->request_payload() ), 'filtered exclusion' );
		remove_filter( 'intertexere_is_post_eligible', '__return_false' );

		wp_delete_post( $this->target_id, true );
		$this->assertError( 'intertexere_insertion_target_unavailable', Insertion_Validation::validate( $this->request_payload() ), 'deleted' );
	}

	public function test_duplicate_target_uses_resolved_post_id_for_alternate_urls(): void {
		$permalink = get_permalink( $this->target_id );
		$path      = (string) wp_parse_url( $permalink, PHP_URL_PATH );
		$forms     = array(
			$permalink,
			$path,
			home_url( '/?p=' . $this->target_id ),
			'?p=' . $this->target_id,
		);
		foreach ( $forms as $form ) {
			$request = $this->request_payload();
			$request['draft_links'] = array( $form );
			$this->assertError( 'intertexere_insertion_duplicate', Insertion_Validation::validate( $request ), $form );
		}

		add_post_meta( $this->target_id, '_wp_old_slug', 'retained-target-slug' );
		$request = $this->request_payload();
		$request['draft_links'] = array( home_url( '/retained-target-slug/' ) );
		$this->assertError( 'intertexere_insertion_duplicate', Insertion_Validation::validate( $request ), 'retained old slug' );
	}

	public function test_already_linked_and_different_link_overlap_fail_before_mutation(): void {
		$this->draft = $this->draft_with_markup(
			'<!-- wp:paragraph --><p>Before <a href="' . esc_url( get_permalink( $this->target_id ) ) . '">Insertion Target Alpha</a> after.</p><!-- /wp:paragraph -->'
		);
		$request = $this->request_payload();
		$this->assertError( 'intertexere_insertion_already_linked', Insertion_Validation::validate( $request ) );

		$this->draft = $this->draft_with_markup(
			'<!-- wp:paragraph --><p>Before <a href="https://example.org/other">Insertion Target Alpha</a> after.</p><!-- /wp:paragraph -->'
		);
		$request = $this->request_payload();
		$this->assertError( 'intertexere_insertion_link_overlap', Insertion_Validation::validate( $request ) );
	}

	public function test_ai_anchor_must_map_to_server_held_current_unit(): void {
		$request = $this->request_payload();
		$request['source_kind'] = 'ai';
		$request['anchor']['unit_key'] = 'u1';
		$result = Insertion_Validation::validate( $request );
		$this->assertIsArray( $result );
		$this->assertSame( 'ai', $result['source_kind'] );

		$request['anchor']['unit_key'] = 'u2';
		$this->assertError( 'intertexere_insertion_stale', Insertion_Validation::validate( $request ) );
	}

	public function test_all_material_target_races_fail_stale(): void {
		$races = array(
			'title'     => array( 'post_title' => 'Changed during validation' ),
			'permalink' => array( 'post_name' => 'changed-during-validation' ),
			'status'    => array( 'post_status' => 'draft' ),
			'password'  => array( 'post_password' => 'race' ),
			'post type' => array( 'post_type' => 'page' ),
		);
		foreach ( $races as $label => $change ) {
			$this->restore_target();
			add_action(
				'intertexere_insertion_validation_before_final_target',
				function () use ( $change ): void {
					wp_update_post( array_merge( array( 'ID' => $this->target_id ), $change ) );
					clean_post_cache( $this->target_id );
				},
				10,
				0
			);
			$this->assertError( 'intertexere_insertion_stale', Insertion_Validation::validate( $this->request_payload() ), $label );
			remove_all_actions( 'intertexere_insertion_validation_before_final_target' );
		}

		$this->restore_target();
		add_action(
			'intertexere_insertion_validation_before_final_target',
			function (): void {
				add_filter( 'intertexere_is_post_eligible', '__return_false' );
			},
			10,
			0
		);
		$this->assertError( 'intertexere_insertion_stale', Insertion_Validation::validate( $this->request_payload() ), 'eligibility' );
		remove_all_actions( 'intertexere_insertion_validation_before_final_target' );
		remove_filter( 'intertexere_is_post_eligible', '__return_false' );

		$this->restore_target();
		add_action(
			'intertexere_insertion_validation_before_final_target',
			function (): void {
				wp_delete_post( $this->target_id, true );
			},
			10,
			0
		);
		$this->assertError( 'intertexere_insertion_stale', Insertion_Validation::validate( $this->request_payload() ), 'deletion' );
	}

	public function test_validation_creates_no_content_revision_autosave_index_graph_option_transient_or_schema_write(): void {
		global $wpdb;
		$source_content = get_post_field( 'post_content', $this->source_id );
		$before = array(
			'posts'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ),
			'revisions'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" ),
			'index_rows'  => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table_name() ),
			'source_rows' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::graph_sources_table_name() ),
			'edge_rows'   => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::link_edges_table_name() ),
			'options'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" ),
			'transients'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'" ),
			'schema'      => get_option( Schema::VERSION_OPTION ),
			'generation'  => get_option( Schema::GENERATION_OPTION ),
			'graph'       => get_option( Schema::GRAPH_GENERATION_OPTION ),
		);

		$this->assertIsArray( Insertion_Validation::validate( $this->request_payload() ) );
		$this->assertSame( $source_content, get_post_field( 'post_content', $this->source_id ) );
		$this->assertSame( $before['posts'], (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ) );
		$this->assertSame( $before['revisions'], (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" ) );
		$this->assertSame( $before['index_rows'], (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table_name() ) );
		$this->assertSame( $before['source_rows'], (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::graph_sources_table_name() ) );
		$this->assertSame( $before['edge_rows'], (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::link_edges_table_name() ) );
		$this->assertSame( $before['options'], (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" ) );
		$this->assertSame( $before['transients'], (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'" ) );
		$this->assertSame( $before['schema'], get_option( Schema::VERSION_OPTION ) );
		$this->assertSame( $before['generation'], get_option( Schema::GENERATION_OPTION ) );
		$this->assertSame( $before['graph'], get_option( Schema::GRAPH_GENERATION_OPTION ) );
	}

	public function test_rest_route_requires_nonce_post_and_raw_body_limit_before_json(): void {
		$payload = $this->request_payload();
		$body    = wp_json_encode( $payload );
		$this->assertIsString( $body );

		$request = new WP_REST_Request( 'POST', '/' . Editor_REST::NAMESPACE . Editor_REST::INSERTION_ROUTE );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( $body );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );

		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );

		$get = new WP_REST_Request( 'GET', '/' . Editor_REST::NAMESPACE . Editor_REST::INSERTION_ROUTE );
		$this->assertSame( 404, rest_get_server()->dispatch( $get )->get_status() );

		$calls = 0;
		add_action( 'intertexere_insertion_rest_before_validation', static function () use ( &$calls ): void { ++$calls; } );
		$oversized = '{"broken":"' . str_repeat( 'x', Editor_Suggestions::MAX_PAYLOAD_BYTES );
		$request = new WP_REST_Request( 'POST', '/' . Editor_REST::NAMESPACE . Editor_REST::INSERTION_ROUTE );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Length', '1' );
		$request->set_body( $oversized );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 413, $response->get_status() );
		$this->assertSame( 'intertexere_payload_too_large', $response->get_data()['code'] );
		$this->assertSame( 0, $calls );
	}

	/** @return array<string, mixed> */
	private function draft_with_markup( string $markup ): array {
		return array(
			'post_id'    => $this->source_id,
			'post_type'  => 'post',
			// Candidate retrieval uses the draft's first bounded phrase. Keeping
			// that phrase exact makes this an insertion test, not a search-recall
			// fixture whose authority depends on database full-text behavior.
			'title'      => 'Insertion Target Alpha',
			'taxonomies' => array(),
			'units'      => array(
				array(
					'client_id' => 'insertion-paragraph',
					'block_name'=> 'core/paragraph',
					'markup'    => $markup,
				),
			),
		);
	}

	private function refresh_analysis(): void {
		$this->analysis = Editor_Suggestions::analyze( $this->draft );
		$this->assertIsArray( $this->analysis );
		$this->assertContains( $this->target_id, array_column( $this->analysis['suggestions'], 'target_post_id' ) );
	}

	/** @return array<string, mixed> */
	private function request_payload(): array {
		return array(
			'draft'          => $this->draft,
			'analysis_id'    => $this->analysis['analysis_id'],
			'target_post_id' => $this->target_id,
			'source_kind'    => 'deterministic',
			'anchor'         => array(
				'block_client_id' => 'insertion-paragraph',
				'block_name'      => 'core/paragraph',
				'exact_text'      => 'Insertion Target Alpha',
				'occurrence'      => 0,
				'unit_key'        => null,
			),
			'draft_links'    => array(),
		);
	}

	private function restore_target(): void {
		wp_update_post(
			array(
				'ID'            => $this->target_id,
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_password' => '',
				'post_title'    => 'Insertion Target Alpha',
				'post_name'     => 'insertion-target-alpha',
			)
		);
		clean_post_cache( $this->target_id );
	}

	/** @param mixed $actual */
	private function assertError( string $code, $actual, string $message = '' ): void {
		$this->assertWPError( $actual, $message );
		$this->assertSame( $code, $actual->get_error_code(), $message );
	}
}
