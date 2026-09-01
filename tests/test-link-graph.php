<?php
/**
 * Internal-link graph integration and concurrency tests.
 */

use Intertexere\Indexer;
use Intertexere\Link_Graph;
use Intertexere\Link_Resolver;
use Intertexere\Settings;

class Intertexere_Link_Graph_Test extends WP_UnitTestCase {
	private $original_permalink_structure;

	public function set_up(): void {
		parent::set_up();
		$this->original_permalink_structure = get_option( 'permalink_structure' );
		update_option( Settings::OPTION, Settings::defaults(), false );
		delete_option( Link_Graph::LOCK_OPTION );
		delete_option( Link_Graph::RERUN_OPTION );
		wp_clear_scheduled_hook( Link_Graph::REBUILD_HOOK );
		Link_Graph::reset();
	}

	public function tear_down(): void {
		global $wp_rewrite;

		remove_all_actions( 'intertexere_graph_rebuild_batch_completed' );
		remove_shortcode( 'intertexere_graph_test' );
		delete_option( Link_Graph::LOCK_OPTION );
		delete_option( Link_Graph::RERUN_OPTION );
		wp_clear_scheduled_hook( Link_Graph::REBUILD_HOOK );
		$wp_rewrite->set_permalink_structure( $this->original_permalink_structure );
		flush_rewrite_rules( false );
		parent::tear_down();
	}

	public function test_literal_parser_does_not_execute_shortcodes_or_mutate_content(): void {
		$this->use_pretty_permalinks();
		$target_id = $this->create_published_post( 'Literal target' );
		$executed  = 0;
		add_shortcode(
			'intertexere_graph_test',
			static function () use ( &$executed, $target_id ): string {
				++$executed;
				return '<a href="' . esc_url( get_permalink( $target_id ) ) . '">Rendered only</a>';
			}
		);

		$content = '[intertexere_graph_test]<p><a href="' . esc_url( get_permalink( $target_id ) ) . '">Literal link</a></p>';
		$source  = $this->create_published_post( 'Literal source', $content );
		$before  = get_post_field( 'post_content', $source );

		$this->assertTrue( Link_Graph::refresh_post( $source ) );
		$direct = Link_Resolver::resolve( get_permalink( $target_id ), get_post( $source ) );
		$parser = new WP_HTML_Tag_Processor( get_post_field( 'post_content', $source ) );
		$this->assertTrue( $parser->next_tag( 'a' ) );
		$this->assertIsString( $parser->get_attribute( 'href' ) );
		$this->assertSame( $target_id, $direct['target_post_id'] );
		$edges = Link_Graph::outbound( $source, true, false );

		$this->assertSame( 0, $executed );
		$this->assertCount( 1, $edges, wp_json_encode( Link_Graph::diagnostics() ) );
		$this->assertSame( $target_id, $edges[0]['target_post_id'] );
		$this->assertSame( $before, get_post_field( 'post_content', $source ) );
	}

	public function test_saved_href_attributes_are_decoded_exactly_once_before_resolution(): void {
		$this->use_pretty_permalinks();
		$target = $this->create_published_post( 'Attribute decoding target' );
		$content = '<a href="/encoded/?value=one&amp;amp;two">Double escaped</a>'
			. '<a href="/encoded/?value=one&amp;two">Normally escaped</a>'
			. '<a href="javascript&amp;colon;alert(1)">Literal character-reference text</a>'
			. '<a href="/?p=' . $target . '&amp;view=full">Query-style target</a>'
			. '<a href="' . get_permalink( $target ) . '">Current permalink target</a>';
		$source = $this->create_published_post( 'Attribute decoding source', $content );
		$before = get_post_field( 'post_content', $source );

		$processor = new WP_HTML_Tag_Processor( $before );
		$hrefs     = array();
		while ( $processor->next_tag( 'a' ) ) {
			$href = $processor->get_attribute( 'href' );
			if ( is_string( $href ) ) {
				$hrefs[] = $href;
			}
		}

		$this->assertSame(
			array(
				'/encoded/?value=one&amp;two',
				'/encoded/?value=one&two',
				'javascript&colon;alert(1)',
				'/?p=' . $target . '&view=full',
				get_permalink( $target ),
			),
			$hrefs,
			'WordPress must decode each saved href attribute exactly once.'
		);

		$this->assertTrue( Link_Graph::refresh_post( $source ) );
		$all        = Link_Graph::outbound( $source, true, false );
		$unresolved = Link_Graph::unresolved( $source );
		$this->assertCount( 4, $all );
		$this->assertCount( 3, $unresolved );

		$unresolved_by_url = array();
		$literal_reference = null;
		foreach ( $unresolved as $edge ) {
			$unresolved_by_url[ $edge['normalized_url'] ] = $edge;
			if ( false !== strpos( $edge['normalized_url'], 'javascript&colon;alert(1)' ) ) {
				$literal_reference = $edge;
			}
		}

		$double_escaped_url = home_url( '/encoded/?value=one&amp;two' );
		$normal_escaped_url = home_url( '/encoded/?value=one&two' );
		$this->assertArrayHasKey( $double_escaped_url, $unresolved_by_url );
		$this->assertArrayHasKey( $normal_escaped_url, $unresolved_by_url );
		$this->assertSame( 1, $unresolved_by_url[ $double_escaped_url ]['occurrence_count'] );
		$this->assertSame( 1, $unresolved_by_url[ $normal_escaped_url ]['occurrence_count'] );
		$this->assertNotSame(
			$unresolved_by_url[ $double_escaped_url ]['target_identity_hash'],
			$unresolved_by_url[ $normal_escaped_url ]['target_identity_hash'],
			'Distinct query identities must not aggregate after parsing.'
		);
		$this->assertNotNull( $literal_reference );
		$this->assertStringNotContainsString( 'javascript:alert(1)', wp_json_encode( $all ) );

		$resolved = $this->edge_for_target( $all, $target );
		$this->assertSame( 2, $resolved['occurrence_count'] );
		$this->assertStringContainsString( '/?p=' . $target . '&view=full', $resolved['normalized_url'] );
		$this->assertSame( $before, get_post_field( 'post_content', $source ) );
	}

	public function test_url_classification_handles_supported_forms_and_excludes_external_or_non_web_links(): void {
		$this->use_pretty_permalinks();
		$source = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'source-page',
			)
		);
		$child = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_parent' => $source,
				'post_name'   => 'child-page',
			)
		);
		$post = get_post( $source );
		$url  = get_permalink( $child );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		$this->assertSame( $child, Link_Resolver::resolve( $url . '#section', $post )['target_post_id'] );
		$this->assertSame( $child, Link_Resolver::resolve( $path, $post )['target_post_id'] );
		$this->assertSame( $child, Link_Resolver::resolve( '//' . $host . $path, $post )['target_post_id'] );
		$this->assertSame( $child, Link_Resolver::resolve( 'child-page/', $post )['target_post_id'] );
		$this->assertSame( $child, Link_Resolver::resolve( 'https://' . $host . ':443' . $path, $post )['target_post_id'] );
		$this->assertNull( Link_Resolver::resolve( 'http://' . $host . ':443' . $path, $post ) );
		$this->assertNull( Link_Resolver::resolve( 'https://external.example/article/', $post ) );
		$this->assertNull( Link_Resolver::resolve( 'https://sub.' . $host . $path, $post ) );
		$this->assertNull( Link_Resolver::resolve( 'mailto:editor@example.com', $post ) );
		$this->assertNull( Link_Resolver::resolve( 'tel:+15555551212', $post ) );
		$this->assertNull( Link_Resolver::resolve( 'javascript:alert(1)', $post ) );
		$this->assertNull( Link_Resolver::resolve( 'data:text/plain,not-a-link', $post ) );
		$this->assertNull( Link_Resolver::resolve( '#local-section', $post ) );
	}

	public function test_query_strings_are_preserved_for_unresolved_internal_urls_and_fragments_are_removed(): void {
		$source = $this->create_published_post( 'Query source' );
		$post   = get_post( $source );
		$edge   = Link_Resolver::resolve( '/unresolved-resource/?view=full&order=asc#details', $post );

		$this->assertNull( $edge['target_post_id'] );
		$this->assertStringContainsString( '?view=full&order=asc', $edge['normalized_url'] );
		$this->assertStringNotContainsString( '#details', $edge['normalized_url'] );

		$source_with_variants = $this->create_published_post(
			'Query variants',
			'<a href="/unresolved-resource/?view=full">Full</a><a href="/unresolved-resource/?view=compact">Compact</a>'
		);
		$this->assertCount( 2, Link_Graph::unresolved( $source_with_variants ) );
	}

	public function test_current_permalink_and_query_style_urls_resolve_to_wordpress_post_identity(): void {
		$this->use_pretty_permalinks();
		$target = $this->create_published_post( 'Resolved target' );
		$source = $this->create_published_post( 'Resolved source' );
		$post   = get_post( $source );

		$this->assertSame( $target, Link_Resolver::resolve( get_permalink( $target ), $post )['target_post_id'] );
		$this->assertSame( $target, Link_Resolver::resolve( '/?p=' . $target, $post )['target_post_id'] );
		$this->assertNull( Link_Resolver::resolve( '/?p=999999999', $post )['target_post_id'] );
	}

	public function test_duplicates_aggregate_by_post_identity_and_self_links_are_flagged(): void {
		$this->use_pretty_permalinks();
		$target = $this->create_published_post( 'Duplicate target' );
		$source = $this->create_published_post( 'Duplicate source' );
		$target_url = get_permalink( $target );
		$target_path = (string) wp_parse_url( $target_url, PHP_URL_PATH );
		$source_url = get_permalink( $source );
		$content = '<a href="' . esc_url( $target_url ) . '">One</a>'
			. '<a href="' . esc_attr( $target_path ) . '#part">Two</a>'
			. '<a href="' . esc_url( set_url_scheme( $target_url, 'https' ) ) . '">Three</a>'
			. '<a href="' . esc_url( $source_url ) . '">Self</a>';

		wp_update_post( array( 'ID' => $source, 'post_content' => $content ) );
		$all = Link_Graph::outbound( $source, true );

		$this->assertCount( 2, $all );
		$target_edge = $this->edge_for_target( $all, $target );
		$self_edge   = $this->edge_for_target( $all, $source );
		$this->assertSame( 3, $target_edge['occurrence_count'] );
		$this->assertFalse( $target_edge['is_self'] );
		$this->assertTrue( $self_edge['is_self'] );
		$this->assertCount( 1, Link_Graph::outbound( $source ) );
	}

	public function test_outbound_unresolved_and_derived_inbound_reads_share_one_edge_table(): void {
		$target = $this->create_published_post( 'Inbound target' );
		$source = $this->create_published_post(
			'Inbound source',
			'<a href="' . esc_url( get_permalink( $target ) ) . '">Resolved</a><a href="/missing-destination/?ref=source">Unresolved</a>'
		);
		$outbound   = Link_Graph::outbound( $source );
		$unresolved = Link_Graph::unresolved( $source );
		$inbound    = Link_Graph::inbound( $target );

		$this->assertCount( 1, $outbound );
		$this->assertCount( 1, $unresolved );
		$this->assertNull( $unresolved[0]['target_post_id'] );
		$this->assertCount( 1, $inbound );
		$this->assertSame( $source, $inbound[0]['source_post_id'] );
		$this->assertSame( get_the_title( $target ), $inbound[0]['target_title'] );
		$this->assertSame( get_permalink( $target ), $inbound[0]['target_permalink'] );
	}

	public function test_source_edit_atomically_replaces_edges_and_retains_zero_edge_source_state(): void {
		$target = $this->create_published_post( 'Replace target' );
		$source = $this->create_published_post( 'Replace source', '<a href="' . esc_url( get_permalink( $target ) ) . '">Link</a>' );
		$this->assertCount( 1, Link_Graph::outbound( $source ) );

		wp_update_post( array( 'ID' => $source, 'post_content' => '<p>No links remain.</p>' ) );

		$this->assertSame( array(), Link_Graph::outbound( $source, true, false ) );
		$this->assertSame( array(), Link_Graph::inbound( $target ) );
		$this->assertGreaterThanOrEqual( 2, Link_Graph::diagnostics()['active_sources'] );
	}

	public function test_source_deletion_unpublish_and_reeligibility_update_graph(): void {
		$target = $this->create_published_post( 'Lifecycle target' );
		$source = $this->create_published_post( 'Lifecycle source', '<a href="' . esc_url( get_permalink( $target ) ) . '">Link</a>' );

		wp_update_post( array( 'ID' => $source, 'post_status' => 'draft' ) );
		$this->assertSame( array(), Link_Graph::inbound( $target ) );

		wp_update_post( array( 'ID' => $source, 'post_status' => 'publish' ) );
		$this->assertCount( 1, Link_Graph::inbound( $target ) );

		wp_delete_post( $source, true );
		$this->assertSame( array(), Link_Graph::inbound( $target ) );
	}

	public function test_target_deletion_or_ineligibility_invalidates_active_relationship_without_rewriting_source(): void {
		$target = $this->create_published_post( 'Target lifecycle' );
		$source = $this->create_published_post( 'Target source', '<a href="' . esc_url( get_permalink( $target ) ) . '">Link</a>' );
		$before = get_post_field( 'post_content', $source );

		wp_update_post( array( 'ID' => $target, 'post_status' => 'draft' ) );
		$this->assertSame( array(), Link_Graph::inbound( $target ) );
		$this->assertSame( array(), Link_Graph::outbound( $source ) );
		$observed = Link_Graph::outbound( $source, true, false );
		$this->assertCount( 1, $observed );
		$this->assertFalse( $observed[0]['target_active'] );
		$this->assertSame( $before, get_post_field( 'post_content', $source ) );

		wp_delete_post( $target, true );
		$this->assertSame( array(), Link_Graph::outbound( $source ) );
		$this->assertSame( $before, get_post_field( 'post_content', $source ) );
	}

	public function test_permalink_change_keeps_durable_identity_and_old_slug_rebuild_is_deterministic(): void {
		$this->use_pretty_permalinks();
		$target = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_name'   => 'original-target-slug',
				'post_title'  => 'Slug target',
			)
		);
		$old_url = get_permalink( $target );
		$source  = $this->create_published_post( 'Slug source', '<a href="' . esc_url( $old_url ) . '">Link</a>' );
		$this->assertSame( $target, Link_Graph::outbound( $source )[0]['target_post_id'] );

		wp_update_post( array( 'ID' => $target, 'post_name' => 'current-target-slug', 'post_title' => 'Current target title' ) );
		$current_url = get_permalink( $target );
		$edge        = Link_Graph::outbound( $source )[0];
		$this->assertSame( $target, $edge['target_post_id'] );
		$this->assertSame( $current_url, $edge['target_permalink'] );
		$this->assertSame( 'Current target title', $edge['target_title'] );

		$this->assertTrue( Link_Graph::rebuild() );
		$rebuilt = Link_Graph::outbound( $source )[0];
		$this->assertSame( $target, $rebuilt['target_post_id'] );
		$this->assertSame( $old_url, $rebuilt['normalized_url'] );
		$this->assertSame( $current_url, $rebuilt['target_permalink'] );
	}

	public function test_ambiguous_old_slug_is_preserved_as_unresolved_instead_of_guessed(): void {
		$this->use_pretty_permalinks();
		$first  = $this->create_published_post( 'First candidate' );
		$second = $this->create_published_post( 'Second candidate' );
		add_post_meta( $first, '_wp_old_slug', 'shared-old-slug' );
		add_post_meta( $second, '_wp_old_slug', 'shared-old-slug' );
		$source = $this->create_published_post( 'Ambiguous source', '<a href="/shared-old-slug/">Unknown</a>' );

		$unresolved = Link_Graph::unresolved( $source );
		$this->assertCount( 1, $unresolved );
		$this->assertNull( $unresolved[0]['target_post_id'] );
		$this->assertStringContainsString( '/shared-old-slug/', $unresolved[0]['normalized_url'] );
	}

	public function test_settings_eligibility_change_requests_both_rebuilds_and_graph_reconciles(): void {
		$source = $this->create_published_post( 'Settings source', '<a href="/unresolved-settings-target/">Link</a>' );
		$this->assertCount( 1, Link_Graph::unresolved( $source ) );

		update_option(
			Settings::OPTION,
			array(
				'eligible_post_types'    => array(),
				'eligible_post_statuses' => array( 'publish' ),
			),
			false
		);

		$this->assertNotFalse( wp_next_scheduled( Indexer::REBUILD_HOOK ) );
		$this->assertNotFalse( wp_next_scheduled( Link_Graph::REBUILD_HOOK ) );
		$this->assertTrue( Link_Graph::rebuild() );
		$this->assertSame( 0, Link_Graph::diagnostics()['active_sources'] );
		$this->assertSame( 0, Link_Graph::diagnostics()['observed_edges'] );
	}

	public function test_rebuild_is_repeatable_and_reset_recovers_without_mutating_posts(): void {
		$target = $this->create_published_post( 'Recovery target' );
		$source = $this->create_published_post( 'Recovery source', '<a href="' . esc_url( get_permalink( $target ) ) . '">Link</a>' );
		$before = get_post_field( 'post_content', $source );

		$this->assertTrue( Link_Graph::rebuild() );
		$first_generation = Link_Graph::diagnostics()['active_generation'];
		$this->assertCount( 1, Link_Graph::outbound( $source ) );
		$this->assertTrue( Link_Graph::rebuild() );
		$this->assertNotSame( $first_generation, Link_Graph::diagnostics()['active_generation'] );
		$this->assertCount( 1, Link_Graph::outbound( $source ) );

		$this->assertTrue( Link_Graph::reset() );
		$this->assertSame( 0, Link_Graph::diagnostics()['active_sources'] );
		$this->assertNotNull( get_post( $source ) );
		$this->assertSame( $before, get_post_field( 'post_content', $source ) );

		$this->assertTrue( Link_Graph::rebuild() );
		$this->assertCount( 1, Link_Graph::outbound( $source ) );
		$this->assertSame( $before, get_post_field( 'post_content', $source ) );
	}

	public function test_interrupted_rebuild_preserves_previous_active_generation(): void {
		$target = $this->create_published_post( 'Failure target' );
		$source = $this->create_published_post( 'Failure source', '<a href="' . esc_url( get_permalink( $target ) ) . '">Link</a>' );
		$this->assertTrue( Link_Graph::rebuild() );
		$generation = Link_Graph::diagnostics()['active_generation'];

		add_action(
			'intertexere_graph_rebuild_batch_completed',
			static function (): void {
				throw new RuntimeException( 'Simulated graph rebuild interruption.' );
			}
		);
		$result = Link_Graph::rebuild();

		$this->assertWPError( $result );
		$this->assertSame( 'intertexere_graph_rebuild_failed', $result->get_error_code() );
		$this->assertSame( $generation, Link_Graph::diagnostics()['active_generation'] );
		$this->assertCount( 1, Link_Graph::outbound( $source ) );
		$this->assertSame( 'failed', Link_Graph::state()['status'] );
	}

	public function test_live_multi_batch_mutations_and_second_request_survive_cutover(): void {
		$target_a = $this->create_published_post( 'Concurrent target A' );
		$target_b = $this->create_published_post( 'Concurrent target B' );
		$target_stale = $this->create_published_post( 'Concurrent target stale' );
		$updated = $this->create_published_post( 'Concurrent update', '<a href="' . esc_url( get_permalink( $target_a ) ) . '">A</a>' );
		$zero = $this->create_published_post( 'Concurrent zero', '<a href="' . esc_url( get_permalink( $target_a ) ) . '">A</a>' );
		$deleted = $this->create_published_post( 'Concurrent deleted', '<a href="' . esc_url( get_permalink( $target_a ) ) . '">A</a>' );
		$unpublished = $this->create_published_post( 'Concurrent unpublished', '<a href="' . esc_url( get_permalink( $target_a ) ) . '">A</a>' );
		$stale_target_source = $this->create_published_post( 'Stale target source', '<a href="' . esc_url( get_permalink( $target_stale ) ) . '">Stale</a>' );
		$published = self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_title'   => 'Concurrent published',
				'post_content' => '<a href="' . esc_url( get_permalink( $target_b ) ) . '">B</a>',
			)
		);
		$fillers = array();
		for ( $i = 0; $i < Link_Graph::BATCH_SIZE + 20; ++$i ) {
			$fillers[] = $this->create_published_post( 'Batch filler ' . $i, '<a href="' . esc_url( get_permalink( $target_a ) ) . '">A</a>' );
		}

		$callback_ran   = false;
		$request_result = null;
		$running_state  = '';
		$last_filler    = (int) end( $fillers );
		add_action(
			'intertexere_graph_rebuild_batch_completed',
			static function ( string $generation, int $last_id ) use (
				&$callback_ran,
				&$request_result,
				&$running_state,
				$last_filler,
				$updated,
				$zero,
				$deleted,
				$unpublished,
				$published,
				$target_b,
				$target_stale
			): void {
				if ( $callback_ran || $last_id < $unpublished || $last_id >= $last_filler ) {
					return;
				}

				$callback_ran   = true;
				$running_state  = (string) Link_Graph::state()['status'];
				$request_result = Link_Graph::request_rebuild();
				wp_update_post( array( 'ID' => $updated, 'post_content' => '<a href="' . esc_url( get_permalink( $target_b ) ) . '">B</a>' ) );
				wp_update_post( array( 'ID' => $zero, 'post_content' => '<p>No links now.</p>' ) );
				wp_delete_post( $deleted, true );
				wp_update_post( array( 'ID' => $unpublished, 'post_status' => 'draft' ) );
				wp_update_post( array( 'ID' => $published, 'post_status' => 'publish' ) );
				wp_update_post( array( 'ID' => $target_stale, 'post_status' => 'draft' ) );
			},
			10,
			2
		);

		$this->assertTrue( Link_Graph::rebuild() );
		$this->assertTrue( $callback_ran );
		$this->assertTrue( $request_result );
		$this->assertSame( 'running', $running_state );
		$this->assertSame( $target_b, Link_Graph::outbound( $updated )[0]['target_post_id'] );
		$this->assertSame( array(), Link_Graph::outbound( $zero, true, false ) );
		$this->assertSame( array(), Link_Graph::outbound( $deleted, true, false ) );
		$this->assertSame( array(), Link_Graph::outbound( $unpublished, true, false ) );
		$this->assertSame( $target_b, Link_Graph::outbound( $published )[0]['target_post_id'] );
		$this->assertSame( array(), Link_Graph::outbound( $stale_target_source ) );
		$this->assertFalse( Link_Graph::outbound( $stale_target_source, true, false )[0]['target_active'] );

		foreach ( $fillers as $post_id ) {
			$this->assertSame( $target_a, Link_Graph::outbound( $post_id )[0]['target_post_id'], 'Later source ' . $post_id . ' was skipped.' );
		}

		$this->assertNotFalse( wp_next_scheduled( Link_Graph::REBUILD_HOOK ) );
		$this->assertFalse( get_option( Link_Graph::RERUN_OPTION, false ) );
		remove_all_actions( 'intertexere_graph_rebuild_batch_completed' );
		wp_clear_scheduled_hook( Link_Graph::REBUILD_HOOK );
		$this->assertTrue( Link_Graph::rebuild() );
		$this->assertSame( $target_b, Link_Graph::outbound( $updated )[0]['target_post_id'] );
		$this->assertSame( array(), Link_Graph::outbound( $zero, true, false ) );
		$this->assertSame( array(), Link_Graph::outbound( $unpublished, true, false ) );
	}

	private function use_pretty_permalinks(): void {
		global $wp_rewrite;

		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		flush_rewrite_rules( false );
	}

	private function create_published_post( string $title, string $content = '' ): int {
		return self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
			)
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $edges Edge rows.
	 * @return array<string, mixed>
	 */
	private function edge_for_target( array $edges, int $target_id ): array {
		foreach ( $edges as $edge ) {
			if ( $target_id === $edge['target_post_id'] ) {
				return $edge;
			}
		}

		$this->fail( 'Expected edge to target ' . $target_id . ' was not found.' );
		return array();
	}
}
