<?php
/**
 * Read-only editor-suggestion service integration tests.
 */

use Intertexere\Editor_Suggestions;
use Intertexere\Indexer;
use Intertexere\Link_Graph;
use Intertexere\Schema;
use Intertexere\Settings;

class Intertexere_Editor_Suggestions_Test extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		update_option( Settings::OPTION, Settings::defaults(), false );
		Indexer::reset();
		Link_Graph::reset();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		remove_all_actions( 'intertexere_editor_suggestions_before_target' );
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	public function test_draft_payload_validation_is_strict_and_bounded(): void {
		$valid = $this->payload( 0, 'A valid unsaved title', array( $this->paragraph( 'Valid literal draft content for analysis.' ) ) );
		$this->assertIsArray( Editor_Suggestions::validate_payload( $valid ) );

		$unknown = $valid;
		$unknown['unknown'] = true;
		$this->assertWPError( Editor_Suggestions::validate_payload( $unknown ) );

		$wrong_id = $valid;
		$wrong_id['post_id'] = -1;
		$this->assertWPError( Editor_Suggestions::validate_payload( $wrong_id ) );

		$wrong_type = $valid;
		$wrong_type['post_type'] = 'attachment';
		$this->assertWPError( Editor_Suggestions::validate_payload( $wrong_type ) );

		$malformed = $valid;
		$malformed['units'][0]['markup'] = '<p>No block wrapper</p>';
		$this->assertWPError( Editor_Suggestions::validate_payload( $malformed ) );

		$oversized = $valid;
		$oversized['units'][0]['markup'] = str_repeat( 'x', Editor_Suggestions::MAX_UNIT_BYTES + 1 );
		$this->assertSame( 'intertexere_unit_too_large', Editor_Suggestions::validate_payload( $oversized )->get_error_code() );

		$too_many = $valid;
		$too_many['units'] = array_fill( 0, Editor_Suggestions::MAX_UNITS + 1, $valid['units'][0] );
		$this->assertSame( 'intertexere_too_many_units', Editor_Suggestions::validate_payload( $too_many )->get_error_code() );

		$bad_taxonomy = $valid;
		$bad_taxonomy['taxonomies'] = array( 'category' => array( 99999999 ) );
		$this->assertWPError( Editor_Suggestions::validate_payload( $bad_taxonomy ) );
	}

	public function test_existing_source_must_match_the_submitted_post_type(): void {
		$source = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'draft' ) );
		$payload = $this->payload( $source, 'Mismatched source type', array( $this->paragraph( 'Enough meaningful content appears in this draft.' ) ) );
		$this->assertWPError( Editor_Suggestions::validate_payload( $payload ) );
	}

	public function test_literal_parser_covers_supported_units_without_rendering_or_mutating(): void {
		$executed = 0;
		add_shortcode(
			'intertexere_editor_test',
			static function () use ( &$executed ): string {
				++$executed;
				return 'executed';
			}
		);

		$units = array(
			$this->unit( 'p', 'core/paragraph', '<!-- wp:paragraph --><p>Paragraph [intertexere_editor_test]</p><!-- /wp:paragraph -->' ),
			$this->unit( 'h', 'core/heading', '<!-- wp:heading --><h2>Heading text</h2><!-- /wp:heading -->' ),
			$this->unit( 'li', 'core/list-item', '<!-- wp:list-item --><li>List item text</li><!-- /wp:list-item -->' ),
			$this->unit( 'pq', 'core/pullquote', '<!-- wp:pullquote --><figure><blockquote><p>Pullquote text</p></blockquote></figure><!-- /wp:pullquote -->' ),
			$this->unit( 'v', 'core/verse', '<!-- wp:verse --><pre class="wp-block-verse">Verse text</pre><!-- /wp:verse -->' ),
			$this->unit( 'pre', 'core/preformatted', '<!-- wp:preformatted --><pre>Preformatted text</pre><!-- /wp:preformatted -->' ),
			$this->unit( 'table', 'core/table', '<!-- wp:table --><figure><table><tbody><tr><td>Cell one</td><td>Cell two</td></tr></tbody></table></figure><!-- /wp:table -->' ),
			$this->unit( 'image', 'core/image', '<!-- wp:image --><figure class="wp-block-image"><img src="x.jpg"/><figcaption>Image caption</figcaption></figure><!-- /wp:image -->' ),
		);
		$payload = $this->payload( 0, 'Literal parser test', $units );
		$validated = Editor_Suggestions::validate_payload( $payload );
		$parsed = $this->call_private( 'parse_units', array( $validated ) );
		$texts = wp_list_pluck( $parsed['text_units'], 'text' );

		$this->assertSame( 0, $executed );
		$this->assertContains( 'Paragraph [intertexere_editor_test]', $texts );
		$this->assertContains( 'Heading text', $texts );
		$this->assertContains( 'List item text', $texts );
		$this->assertContains( 'Cell one', $texts );
		$this->assertContains( 'Cell two', $texts );
		$this->assertContains( 'Image caption', $texts );
		$this->assertCount( 9, $texts );
		$this->assertSame( $units, $payload['units'] );
		remove_shortcode( 'intertexere_editor_test' );
	}

	public function test_unsaved_links_use_one_decode_and_durable_target_identity(): void {
		$target = $this->create_target( 'Decoded Link Destination' );
		$markup = '<!-- wp:paragraph --><p>'
			. '<a href="/?p=' . $target . '&amp;view=full">Resolved query link</a>'
			. '<a href="/unresolved/?value=one&amp;amp;two">Double escaped</a>'
			. '<a href="/unresolved/?value=one&amp;two">Normal escaped</a>'
			. '</p><!-- /wp:paragraph -->';
		$validated = Editor_Suggestions::validate_payload(
			$this->payload( 0, 'Decoded links in unsaved content', array( $this->unit( 'links', 'core/paragraph', $markup ) ) )
		);
		$parsed = $this->call_private( 'parse_units', array( $validated ) );

		$this->assertArrayHasKey( $target, $parsed['resolved_target_ids'] );
		$this->assertArrayHasKey( home_url( '/unresolved/?value=one&amp;two' ), $parsed['unresolved_urls'] );
		$this->assertArrayHasKey( home_url( '/unresolved/?value=one&two' ), $parsed['unresolved_urls'] );
		$this->assertCount( 2, $parsed['unresolved_urls'] );

		$response = Editor_Suggestions::analyze(
			$this->payload( 0, 'Decoded Link Destination', array( $this->unit( 'links', 'core/paragraph', $markup ) ) )
		);
		$this->assertSame( array(), $response['suggestions'] );
		$this->assertSame( 1, $response['excluded_already_linked_count'] );
	}

	public function test_server_hash_is_canonical_and_changes_only_with_supported_snapshot_state(): void {
		$category_a = self::factory()->category->create();
		$category_b = self::factory()->category->create();
		$payload_a = $this->payload( 0, 'Canonical hash title', array( $this->paragraph( 'Canonical hash draft content.' ) ) );
		$payload_a['taxonomies'] = array( 'category' => array( $category_b, $category_a ) );
		$payload_b = $payload_a;
		$payload_b['taxonomies']['category'] = array( $category_a, $category_b );
		$validated_a = Editor_Suggestions::validate_payload( $payload_a );
		$validated_b = Editor_Suggestions::validate_payload( $payload_b );

		$this->assertSame( Editor_Suggestions::draft_hash( $validated_a ), Editor_Suggestions::draft_hash( $validated_b ) );
		$payload_b['title'] = 'Changed canonical hash title';
		$this->assertNotSame(
			Editor_Suggestions::draft_hash( $validated_a ),
			Editor_Suggestions::draft_hash( Editor_Suggestions::validate_payload( $payload_b ) )
		);
		$this->assertSame( 64, strlen( Editor_Suggestions::draft_hash( $validated_a ) ) );
	}

	public function test_title_retrieval_scoring_and_current_metadata_are_deterministic(): void {
		$category = self::factory()->category->create();
		$target = $this->create_target(
			'Deterministic Editorial Workflow',
			'<!-- wp:heading --><h2>Editorial workflow details</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Deterministic local publishing guidance.</p><!-- /wp:paragraph -->',
			'Editorial workflow excerpt'
		);
		$payload = $this->payload( 0, 'Deterministic Editorial Workflow', array( $this->paragraph( 'A deterministic editorial workflow supports local publishing guidance.' ) ) );
		wp_set_post_categories( $target, array( $category ) );
		Indexer::refresh_post( $target );
		$payload['taxonomies'] = array( 'category' => array( $category ) );
		$first = Editor_Suggestions::analyze( $payload );
		$second = Editor_Suggestions::analyze( $payload );

		$this->assertSame( $target, $first['suggestions'][0]['target_post_id'] );
		$this->assertSame( $first['suggestions'], $second['suggestions'] );
		$this->assertSame( 'title-overlap', $first['suggestions'][0]['reason']['code'] === 'title-phrase' ? 'title-overlap' : $first['suggestions'][0]['reason']['code'] );
		$this->assertGreaterThanOrEqual( Editor_Suggestions::MIN_SCORE, $first['suggestions'][0]['score'] );

		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_title' => 'Current Target Metadata' ), array( 'ID' => $target ), array( '%s' ), array( '%d' ) );
		clean_post_cache( $target );
		$current = Editor_Suggestions::analyze( $payload );
		$this->assertSame( 'Current Target Metadata', $current['suggestions'][0]['target_title'] );
	}

	public function test_taxonomy_retrieval_and_scoring_use_edited_unsaved_terms(): void {
		$category = self::factory()->category->create( array( 'name' => 'Architecture' ) );
		$target = $this->create_target( 'Related reference destination', 'General supporting material.' );
		wp_set_post_categories( $target, array( $category ) );
		Indexer::refresh_post( $target );
		$payload = $this->payload( 0, 'Completely different draft title', array( $this->paragraph( 'This draft contains enough unrelated meaningful words for analysis.' ) ) );
		$payload['taxonomies'] = array( 'category' => array( $category ) );
		$response = Editor_Suggestions::analyze( $payload );

		$this->assertSame( $target, $response['suggestions'][0]['target_post_id'] );
		$this->assertContains( 'taxonomy-overlap', $response['suggestions'][0]['reason']['signals'] );
		$this->assertSame( 17, $response['suggestions'][0]['score'] );
	}

	public function test_published_scoring_formula_signals_and_caps_are_exact(): void {
		$category_a = self::factory()->category->create();
		$category_b = self::factory()->category->create();
		$target_id = $this->create_target( 'Alpha Beta', 'Alpha beta gamma delta', 'Alpha beta excerpt' );
		$target = get_post( $target_id );
		$record = Indexer::get_record( $target_id );
		$record['headings'] = wp_json_encode( array( 'Alpha Beta Heading' ) );
		$record['taxonomies'] = wp_json_encode(
			array(
				'category' => array(
					array( 'id' => $category_a ),
					array( 'id' => $category_b ),
				),
			)
		);
		$validated = array(
			'post_id' => 99,
			'post_type' => 'post',
			'taxonomies' => array( 'category' => array( $category_a, $category_b ) ),
		);
		$draft_text = 'Alpha Beta Heading gamma delta';
		$terms = Editor_Suggestions::normalize_terms( $draft_text );
		$result = $this->call_private(
			'score_record',
			array( $record, $target, $validated, $draft_text, $terms, array( 'links_to_source' => true, 'shared_destinations' => 9 ) )
		);

		$this->assertSame( 161, $result['score'] );
		$this->assertSame(
			array( 'title-phrase', 'title-overlap', 'heading-overlap', 'taxonomy-overlap', 'excerpt-overlap', 'content-overlap', 'links-to-source', 'shared-destination', 'same-post-type' ),
			$result['reason']['signals']
		);
	}

	public function test_hard_exclusions_revalidate_current_target_state_and_configuration(): void {
		$target = $this->create_target( 'Current Eligibility Destination' );
		$payload = $this->payload( 0, 'Current Eligibility Destination', array( $this->paragraph( 'Current eligibility destination has enough supporting words.' ) ) );
		$this->assertCount( 1, Editor_Suggestions::analyze( $payload )['suggestions'] );

		$changed = false;
		add_action(
			'intertexere_editor_suggestions_before_target',
			static function ( int $candidate_id ) use ( $target, &$changed ): void {
				if ( $candidate_id === $target && ! $changed ) {
					$changed = true;
					global $wpdb;
					$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $target ), array( '%s' ), array( '%d' ) );
					clean_post_cache( $target );
				}
			}
		);
		$this->assertSame( array(), Editor_Suggestions::analyze( $payload )['suggestions'] );
		$this->assertTrue( $changed );

		wp_update_post( array( 'ID' => $target, 'post_status' => 'publish', 'post_password' => 'secret' ) );
		$this->assertSame( array(), Editor_Suggestions::analyze( $payload )['suggestions'] );
	}

	public function test_self_already_linked_and_exact_unresolved_identity_are_excluded(): void {
		$target = $this->create_target( 'Identity Exclusion Destination' );
		$self_payload = $this->payload( $target, 'Identity Exclusion Destination', array( $this->paragraph( 'Identity exclusion destination supporting content.' ) ) );
		$this->assertSame( array(), Editor_Suggestions::analyze( $self_payload )['suggestions'] );

		$link = '<!-- wp:paragraph --><p><a href="' . esc_url( get_permalink( $target ) ) . '">Identity Exclusion Destination</a> supporting content.</p><!-- /wp:paragraph -->';
		$linked = Editor_Suggestions::analyze( $this->payload( 0, 'Identity Exclusion Destination', array( $this->unit( 'linked', 'core/paragraph', $link ) ) ) );
		$this->assertSame( array(), $linked['suggestions'] );
		$this->assertSame( 1, $linked['excluded_already_linked_count'] );
	}

	public function test_empty_short_unsupported_and_low_signal_drafts_return_no_suggestions(): void {
		$this->create_target( 'A relevant destination' );
		$this->assertSame( array(), Editor_Suggestions::analyze( $this->payload( 0, '', array() ) )['suggestions'] );
		$this->assertSame( array(), Editor_Suggestions::analyze( $this->payload( 0, 'Tiny', array( $this->paragraph( 'small text' ) ) ) )['suggestions'] );
		$this->assertSame( array(), Editor_Suggestions::analyze( $this->payload( 0, 'Unrelated substantial subject', array( $this->paragraph( 'Different vocabulary remains below the published threshold.' ) ) ) )['suggestions'] );
	}

	public function test_location_contract_uses_exact_single_unit_text_and_stale_identifiers(): void {
		$target = $this->create_target( 'Café Editorial Workflow' );
		$payload = $this->payload(
			0,
			'Location contract draft',
			array(
				$this->paragraph( 'Earlier unrelated context remains here.' ),
				$this->unit( 'second', 'core/paragraph', '<!-- wp:paragraph --><p>😀 Café Editorial Workflow appears twice. Café Editorial Workflow.</p><!-- /wp:paragraph -->' ),
			)
		);
		$response = Editor_Suggestions::analyze( $payload );
		$location = $response['suggestions'][0]['location'];

		$this->assertSame( 'second', $location['block_client_id'] );
		$this->assertSame( 'Café Editorial Workflow', $location['anchor_text'] );
		$this->assertSame( 2, $location['start'] );
		$this->assertSame( 23, $location['length'] );
		$this->assertSame( 0, $location['occurrence'] );
		$this->assertSame( $response['draft_hash'], $location['draft_hash'] );
		$this->assertSame( $response['analysis_id'], $location['analysis_id'] );
		$this->assertSame( 64, strlen( $location['block_text_hash'] ) );
	}

	public function test_relationship_can_return_a_block_location_with_a_null_anchor(): void {
		$category = self::factory()->category->create();
		$second_category = self::factory()->category->create();
		$target = $this->create_target( 'Unrelated Candidate Title' );
		wp_set_post_categories( $target, array( $category, $second_category ) );
		Indexer::refresh_post( $target );
		$payload = $this->payload( 0, 'Draft with distinct vocabulary', array( $this->paragraph( 'Substantial distinct vocabulary creates a safe draft location.' ) ) );
		$payload['taxonomies'] = array( 'category' => array( $category, $second_category ) );
		$response = Editor_Suggestions::analyze( $payload );

		$this->assertSame( $target, $response['suggestions'][0]['target_post_id'] );
		$this->assertNull( $response['suggestions'][0]['location'] );
	}

	public function test_analysis_is_read_only_and_makes_no_external_request(): void {
		global $wpdb;
		$target = $this->create_target( 'Read Only Analysis Target' );
		$payload = $this->payload( 0, 'Read Only Analysis Target', array( $this->paragraph( 'Read only analysis target appears in unsaved prose.' ) ) );
		$before_post = get_post( $target, ARRAY_A );
		$before_options = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );
		$before_index = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table_name() );
		$before_graph = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::link_edges_table_name() );
		$http_calls = 0;
		add_filter(
			'pre_http_request',
			static function ( $preempt ) use ( &$http_calls ) {
				++$http_calls;
				return $preempt;
			}
		);

		$response = Editor_Suggestions::analyze( $payload );
		$this->assertCount( 1, $response['suggestions'] );
		$this->assertSame( 0, $http_calls );
		$this->assertSame( $before_post, get_post( $target, ARRAY_A ) );
		$this->assertSame( $before_options, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" ) );
		$this->assertSame( $before_index, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table_name() ) );
		$this->assertSame( $before_graph, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::link_edges_table_name() ) );
		$this->assertSame( array(), get_posts( array( 'post_type' => 'revision', 'post_parent' => $target ) ) );
	}

	public function test_active_generations_only_are_read_and_change_analysis_identity(): void {
		global $wpdb;
		$active_target = $this->create_target( 'Active Generation Destination' );
		$inactive_target = $this->create_target( 'Replacement Only Destination' );
		$inactive_record = Indexer::get_record( $inactive_target );
		$replacement = wp_generate_uuid4();
		$wpdb->delete( Schema::table_name(), array( 'post_id' => $inactive_target, 'generation' => get_option( Schema::GENERATION_OPTION ) ) );
		$inactive_record['id'] = null;
		$inactive_record['generation'] = $replacement;
		unset( $inactive_record['id'] );
		$wpdb->insert( Schema::table_name(), $inactive_record );

		$active_response = Editor_Suggestions::analyze(
			$this->payload( 0, 'Active Generation Destination', array( $this->paragraph( 'Active generation destination supporting draft prose.' ) ) )
		);
		$this->assertSame( $active_target, $active_response['suggestions'][0]['target_post_id'] );

		$inactive_response = Editor_Suggestions::analyze(
			$this->payload( 0, 'Replacement Only Destination', array( $this->paragraph( 'Replacement only destination supporting draft prose.' ) ) )
		);
		$this->assertSame( array(), $inactive_response['suggestions'] );

		$before_id = $active_response['analysis_id'];
		update_option( Schema::GRAPH_GENERATION_OPTION, wp_generate_uuid4(), false );
		$after = Editor_Suggestions::analyze(
			$this->payload( 0, 'Active Generation Destination', array( $this->paragraph( 'Active generation destination supporting draft prose.' ) ) )
		);
		$this->assertNotSame( $before_id, $after['analysis_id'] );
	}

	public function test_missing_index_is_unavailable_and_missing_graph_degrades_to_lexical_signals(): void {
		$this->create_target( 'Graceful Lexical Destination' );
		$payload = $this->payload( 0, 'Graceful Lexical Destination', array( $this->paragraph( 'Graceful lexical destination appears in draft prose.' ) ) );
		delete_option( Schema::GRAPH_GENERATION_OPTION );
		$this->assertCount( 1, Editor_Suggestions::analyze( $payload )['suggestions'] );

		delete_option( Schema::GENERATION_OPTION );
		$error = Editor_Suggestions::analyze( $payload );
		$this->assertWPError( $error );
		$this->assertSame( 'intertexere_index_unavailable', $error->get_error_code() );
	}

	public function test_large_fixture_keeps_candidates_results_and_queries_bounded(): void {
		$category = self::factory()->category->create();
		$ids = array();
		for ( $index = 0; $index < 125; ++$index ) {
			$ids[] = $this->create_target(
				$index < 65 ? 'Bounded Candidate ' . $index : 'Taxonomy Fixture ' . $index,
				$index < 65
					? 'Bounded candidate shared deterministic fixture content number ' . $index . '.'
					: 'Different taxonomy-only fixture content number ' . $index . '.'
			);
		}
		foreach ( array_slice( $ids, 65 ) as $post_id ) {
			wp_set_post_categories( $post_id, array( $category ) );
			Indexer::refresh_post( $post_id );
		}
		$payload = $this->payload( 0, 'Bounded Candidate', array( $this->paragraph( 'Bounded candidate shared deterministic fixture content.' ) ) );
		$payload['taxonomies'] = array( 'category' => array( $category ) );
		$response = Editor_Suggestions::analyze( $payload );
		$metrics = Editor_Suggestions::last_metrics();

		$this->assertLessThanOrEqual( Editor_Suggestions::MAX_CANDIDATES, $response['limits']['candidate_count'] );
		$this->assertLessThanOrEqual( Editor_Suggestions::MAX_RESULTS, count( $response['suggestions'] ) );
		$this->assertLessThanOrEqual( 12, $metrics['query_count'] );
		$this->assertLessThan( 3000, $metrics['elapsed_ms'] );
		$this->assertTrue( $response['limits']['truncated'] || 100 === $response['limits']['candidate_count'] );
	}

	private function create_target( string $title, string $content = 'Deterministic supporting content for this eligible destination.', string $excerpt = '' ): int {
		return self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
				'post_excerpt' => $excerpt,
			)
		);
	}

	/** @return array<string, mixed> */
	private function payload( int $post_id, string $title, array $units ): array {
		return array(
			'post_id'    => $post_id,
			'post_type'  => 'post',
			'title'      => $title,
			'taxonomies' => array(),
			'units'      => $units,
		);
	}

	/** @return array<string, string> */
	private function paragraph( string $text ): array {
		static $counter = 0;
		++$counter;
		return $this->unit( 'paragraph-' . $counter, 'core/paragraph', '<!-- wp:paragraph --><p>' . esc_html( $text ) . '</p><!-- /wp:paragraph -->' );
	}

	/** @return array<string, string> */
	private function unit( string $client_id, string $block_name, string $markup ): array {
		return array( 'client_id' => $client_id, 'block_name' => $block_name, 'markup' => $markup );
	}

	/** @return mixed */
	private function call_private( string $method, array $arguments ) {
		$reflection = new ReflectionMethod( Editor_Suggestions::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( null, $arguments );
	}
}
