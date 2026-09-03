<?php
/**
 * Site Link Audit integration, concurrency, and performance tests.
 */

use Intertexere\Admin;
use Intertexere\Eligibility;
use Intertexere\Indexer;
use Intertexere\Link_Graph;
use Intertexere\Schema;
use Intertexere\Settings;
use Intertexere\Site_Link_Audit;

class Intertexere_Site_Link_Audit_Test extends WP_UnitTestCase {
	private int $administrator_id;

	public function set_up(): void {
		parent::set_up();
		update_option( Settings::OPTION, Settings::defaults(), false );
		Indexer::reset();
		Link_Graph::reset();
		$role = get_role( 'administrator' );
		$role->add_cap( Admin::CAPABILITY );
		$this->administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->administrator_id );
	}

	public function tear_down(): void {
		remove_all_actions( 'intertexere_audit_after_overview_read' );
		remove_all_actions( 'intertexere_audit_before_final_derived_evidence' );
		remove_all_actions( 'intertexere_audit_before_final_object_authority' );
		remove_all_filters( 'intertexere_is_post_eligible' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_exact_orphan_and_thin_classification_and_content_body_language(): void {
		$target = $this->post( 'Audit target' );
		$orphan = $this->page( 'orphans', $target );
		$this->assertSame( 'current', $orphan['status'] );
		$this->assertCount( 1, $orphan['rows'] );
		$this->assertSame( 0, $orphan['rows'][0]['class'] );

		$source = $this->post( 'Audit source', '<a href="' . esc_url( get_permalink( $target ) ) . '">Target</a>' );
		$thin = $this->page( 'thin', $target );
		$this->assertSame( 'current', $thin['status'] );
		$this->assertCount( 1, $thin['rows'] );
		$this->assertSame( 1, $thin['rows'][0]['class'] );
		$this->assertSame( $source, $thin['rows'][0]['evidence'][0]['source_post_id'] );

		ob_start();
		$_GET = array( 'page' => 'intertexere-site-link-audit' );
		Admin::render_audit_page();
		$html = (string) ob_get_clean();
		$_GET = array();
		$this->assertStringContainsString( 'literal links in saved post content', $html );
		$this->assertStringContainsString( 'Navigation, templates', $html );
		$this->assertStringNotContainsString( 'no internal links anywhere', strtolower( $html ) );
	}

	public function test_ready_removed_and_absent_source_and_index_semantics(): void {
		global $wpdb;

		$target = $this->post( 'State target' );
		$source = $this->post( 'State source', '<a href="' . esc_url( get_permalink( $target ) ) . '">Target</a>' );
		$generations = $this->generations();

		$ready = Site_Link_Audit::classify_targets( array( $target ), $generations );
		$this->assertSame( 1, $ready[ $target ]['class'] );

		$wpdb->update(
			Schema::graph_sources_table_name(),
			array( 'source_state' => 'removed' ),
			array( 'generation' => $generations['graph'], 'source_post_id' => $source ),
			array( '%s' ),
			array( '%s', '%d' )
		);
		$removed = Site_Link_Audit::classify_targets( array( $target ), $generations );
		$this->assertSame( 0, $removed[ $target ]['class'] );

		$wpdb->delete( Schema::graph_sources_table_name(), array( 'generation' => $generations['graph'], 'source_post_id' => $source ), array( '%s', '%d' ) );
		$absent_graph = Site_Link_Audit::classify_targets( array( $target ), $generations );
		$this->assertSame( 0, $absent_graph[ $target ]['class'] );

		Link_Graph::refresh_post( $source );
		$wpdb->delete( Schema::table_name(), array( 'generation' => $generations['index'], 'post_id' => $source ), array( '%s', '%d' ) );
		$absent_index = Site_Link_Audit::classify_targets( array( $target ), $generations );
		$this->assertSame( 0, $absent_index[ $target ]['class'] );
	}

	public function test_audit_sql_never_uses_nonexistent_current_source_state(): void {
		$this->post( 'SQL audit target' );
		$queries = array();
		$capture = static function ( string $query ) use ( &$queries ): string {
			if ( false !== strpos( $query, 'intertexere_' ) ) {
				$queries[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $capture );
		$this->page( 'orphans' );
		Site_Link_Audit::overview();
		remove_filter( 'query', $capture );
		$this->assertNotEmpty( $queries );
		$this->assertStringNotContainsString( "source_state = 'current'", strtolower( implode( "\n", $queries ) ) );
		$this->assertStringContainsString( 'source_state', strtolower( implode( "\n", $queries ) ) );
	}

	public function test_self_repeated_unresolved_and_noncanonical_findings_are_distinct(): void {
		$target = $this->post( 'Edge target' );
		$source = $this->post( 'Edge source' );
		$content = '<a href="/?p=' . $target . '">Query target</a>'
			. '<a href="' . esc_url( get_permalink( $target ) ) . '">Canonical target</a>'
			. '<a href="/missing-audit-destination/">Missing</a>'
			. '<a href="' . esc_url( get_permalink( $source ) ) . '">Self</a>';
		wp_update_post( array( 'ID' => $source, 'post_content' => $content ) );

		$repeated = $this->page( 'repeated', $source );
		$this->assertSame( 'current', $repeated['status'] );
		$this->assertCount( 1, $repeated['rows'] );
		$this->assertSame( 2, $repeated['rows'][0]['occurrence_count'] );

		$self = $this->page( 'self', $source );
		$this->assertCount( 1, $self['rows'] );
		$this->assertTrue( $self['rows'][0]['is_self'] );

		$unavailable = $this->page( 'unavailable', $source );
		$this->assertCount( 1, $unavailable['rows'] );
		$this->assertNull( $unavailable['rows'][0]['target_post_id'] );
		$this->assertStringContainsString( 'Unresolved internal URL', $unavailable['rows'][0]['finding_reason'] );

		$noncanonical = $this->page( 'noncanonical', $source );
		$this->assertCount( 1, $noncanonical['rows'] );
		$this->assertSame( $target, $noncanonical['rows'][0]['target_post_id'] );
		$this->assertStringContainsString( 'representative URL', $noncanonical['rows'][0]['finding_reason'] );
	}

	public function test_resolved_unavailable_target_reasons_are_current_and_distinct(): void {
		$states = array(
			'trash'   => 'Trash',
			'private' => 'private',
			'draft'   => 'not published',
		);
		foreach ( $states as $status => $message ) {
			$target = $this->post( 'Unavailable ' . $status );
			$source = $this->post( 'Source ' . $status, '<a href="' . esc_url( get_permalink( $target ) ) . '">Target</a>' );
			wp_update_post( array( 'ID' => $target, 'post_status' => $status ) );
			$result = $this->page( 'unavailable', $source );
			$this->assertSame( 'current', $result['status'], $status );
			$this->assertCount( 1, $result['rows'], $status );
			$this->assertStringContainsStringIgnoringCase( $message, $result['rows'][0]['finding_reason'], $status );
		}

		$target = $this->post( 'Password target' );
		$source = $this->post( 'Password source', '<a href="' . esc_url( get_permalink( $target ) ) . '">Target</a>' );
		wp_update_post( array( 'ID' => $target, 'post_password' => 'secret' ) );
		$result = $this->page( 'unavailable', $source );
		$this->assertStringContainsString( 'password protected', $result['rows'][0]['finding_reason'] );
	}

	public function test_materialized_runtime_filter_changes_counts_only_after_refresh(): void {
		$target = $this->post( 'Filter target' );
		$source = $this->post( 'Filter source', '<a href="' . esc_url( get_permalink( $target ) ) . '">Target</a>' );
		$this->assertSame( 1, Site_Link_Audit::classify_targets( array( $target ), $this->generations() )[ $target ]['class'] );

		$exclude = static function ( bool $eligible, WP_Post $post ) use ( $source ): bool {
			return $post->ID === $source ? false : $eligible;
		};
		add_filter( 'intertexere_is_post_eligible', $exclude, 10, 2 );
		$this->assertSame( 1, Site_Link_Audit::classify_targets( array( $target ), $this->generations() )[ $target ]['class'] );
		$this->assertSame( 'stale', $this->page( 'thin', $target )['status'] );

		Indexer::refresh_post( $source );
		Link_Graph::refresh_post( $source );
		$this->assertSame( 0, Site_Link_Audit::classify_targets( array( $target ), $this->generations() )[ $target ]['class'] );
		remove_filter( 'intertexere_is_post_eligible', $exclude, 10 );
	}

	public function test_same_generation_orphan_to_thin_race_returns_whole_page_stale(): void {
		$target = $this->post( 'Race orphan target' );
		$source = $this->post( 'Race source', '<p>No link yet.</p>' );
		$before = $this->generations();
		add_action(
			'intertexere_audit_before_final_derived_evidence',
			static function () use ( $source, $target ): void {
				wp_update_post( array( 'ID' => $source, 'post_content' => '<a href="' . esc_url( get_permalink( $target ) ) . '">Target</a>' ) );
			},
			10,
			0
		);
		$result = $this->page( 'orphans', $target );
		$this->assertSame( 'stale', $result['status'] );
		$this->assertArrayNotHasKey( 'rows', $result );
		$this->assertSame( $before, $this->generations() );
	}

	public function test_same_generation_thin_cardinality_races_are_detected_with_ready_source_state(): void {
		global $wpdb;

		$target = $this->post( 'Thin race target' );
		$source_a = $this->post( 'Thin race A', '<a href="' . esc_url( get_permalink( $target ) ) . '">Target</a>' );
		$source_b = $this->post( 'Thin race B', '<p>No link.</p>' );
		add_action(
			'intertexere_audit_before_final_derived_evidence',
			static function () use ( $source_b, $target ): void {
				wp_update_post( array( 'ID' => $source_b, 'post_content' => '<a href="' . esc_url( get_permalink( $target ) ) . '">Target</a>' ) );
			},
			10,
			0
		);
		$this->assertSame( 'stale', $this->page( 'thin', $target )['status'] );
		remove_all_actions( 'intertexere_audit_before_final_derived_evidence' );

		wp_update_post( array( 'ID' => $source_b, 'post_content' => '<p>No link.</p>' ) );
		add_action(
			'intertexere_audit_before_final_derived_evidence',
			static function () use ( $source_a ): void {
				wp_update_post( array( 'ID' => $source_a, 'post_content' => '<p>Link removed while source remains eligible.</p>' ) );
			},
			10,
			0
		);
		$this->assertSame( 'stale', $this->page( 'thin', $target )['status'] );
		$state = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT source_state FROM ' . Schema::graph_sources_table_name() . ' WHERE generation = %s AND source_post_id = %d',
				get_option( Schema::GRAPH_GENERATION_OPTION ),
				$source_a
			)
		);
		$this->assertSame( 'ready', $state );
	}

	public function test_edge_occurrence_unresolved_self_and_url_races_fail_stale(): void {
		$target = $this->post( 'Edge race target' );
		$source = $this->post( 'Edge race source', '<a href="' . esc_url( get_permalink( $target ) ) . '">One</a><a href="' . esc_url( get_permalink( $target ) ) . '">Two</a>' );
		add_action(
			'intertexere_audit_before_final_derived_evidence',
			static function () use ( $source, $target ): void {
				wp_update_post( array( 'ID' => $source, 'post_content' => '<a href="' . esc_url( get_permalink( $target ) ) . '">One</a>' ) );
			},
			10,
			0
		);
		$this->assertSame( 'stale', $this->page( 'repeated', $source )['status'] );
		remove_all_actions( 'intertexere_audit_before_final_derived_evidence' );

		wp_update_post( array( 'ID' => $source, 'post_content' => '<a href="/unresolved-race/">Unknown</a>' ) );
		add_action(
			'intertexere_audit_before_final_derived_evidence',
			static function () use ( $source ): void {
				wp_update_post( array( 'ID' => $source, 'post_content' => '<p>Removed.</p>' ) );
			},
			10,
			0
		);
		$this->assertSame( 'stale', $this->page( 'unavailable', $source )['status'] );
		remove_all_actions( 'intertexere_audit_before_final_derived_evidence' );

		wp_update_post( array( 'ID' => $source, 'post_content' => '<a href="' . esc_url( get_permalink( $source ) ) . '">Self</a>' ) );
		add_action(
			'intertexere_audit_before_final_derived_evidence',
			static function () use ( $source ): void {
				wp_update_post( array( 'ID' => $source, 'post_content' => '<p>Removed.</p>' ) );
			},
			10,
			0
		);
		$this->assertSame( 'stale', $this->page( 'self', $source )['status'] );
	}

	public function test_generation_and_current_object_races_fail_closed(): void {
		$target = $this->post( 'Generation target' );
		$original = get_option( Schema::GRAPH_GENERATION_OPTION );
		add_action(
			'intertexere_audit_before_final_object_authority',
			static function (): void {
				update_option( Schema::GRAPH_GENERATION_OPTION, 'audit-race-generation', false );
			},
			10,
			0
		);
		$this->assertSame( 'stale', $this->page( 'orphans', $target )['status'] );
		remove_all_actions( 'intertexere_audit_before_final_object_authority' );
		update_option( Schema::GRAPH_GENERATION_OPTION, $original, false );

		add_action(
			'intertexere_audit_before_final_object_authority',
			static function () use ( $target ): void {
				wp_update_post( array( 'ID' => $target, 'post_title' => 'Changed during audit' ) );
			},
			10,
			0
		);
		$this->assertSame( 'stale', $this->page( 'orphans', $target )['status'] );
	}

	public function test_overview_uses_one_aggregate_statement_and_detects_cutover(): void {
		$this->post( 'Overview target' );
		$aggregate_queries = array();
		$capture = static function ( string $query ) use ( &$aggregate_queries ): string {
			if ( false !== strpos( $query, 'AS orphans' ) && false !== strpos( $query, 'AS noncanonical' ) ) {
				$aggregate_queries[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $capture );
		$result = Site_Link_Audit::overview();
		remove_filter( 'query', $capture );
		$this->assertSame( 'current', $result['status'] );
		$this->assertCount( 1, $aggregate_queries );
		$this->assertArrayHasKey( 'orphans', $result['counts'] );
		$this->assertLessThanOrEqual( 12, $result['metrics']['query_count'] );
		$this->assertLessThan( 1000, $result['metrics']['elapsed_ms'] );

		add_action(
			'intertexere_audit_after_overview_read',
			static function (): void {
				update_option( Schema::GENERATION_OPTION, 'overview-cutover', false );
			},
			10,
			0
		);
		$this->assertSame( 'stale', Site_Link_Audit::overview()['status'] );
	}

	public function test_keyset_cursor_is_signed_bound_and_has_no_duplicate_rows(): void {
		for ( $i = 0; $i < 7; ++$i ) {
			$this->post( 'Cursor target ' . $i );
		}
		$first_request = $this->request( 'orphans', 3 );
		$first = Site_Link_Audit::page( $first_request );
		$this->assertCount( 3, $first['rows'] );
		$this->assertNotEmpty( $first['next_cursor'] );

		$second_request = Site_Link_Audit::parse_request(
			array(
				'page' => 'intertexere-site-link-audit',
				'category' => 'orphans',
				'per_page' => '3',
				'cursor' => $first['next_cursor'],
			)
		);
		$second = Site_Link_Audit::page( $second_request );
		$first_ids = wp_list_pluck( $first['rows'], 'post_id' );
		$second_ids = wp_list_pluck( $second['rows'], 'post_id' );
		$this->assertSame( array(), array_intersect( $first_ids, $second_ids ) );
		$this->assertGreaterThan( max( $first_ids ), min( $second_ids ) );

		$bad = substr( $first['next_cursor'], 0, -1 ) . ( 'a' === substr( $first['next_cursor'], -1 ) ? 'b' : 'a' );
		$this->assertWPError( Site_Link_Audit::parse_request( array( 'page' => 'intertexere-site-link-audit', 'category' => 'orphans', 'cursor' => $bad ) ) );
	}

	public function test_strict_input_permissions_and_row_action_capabilities(): void {
		$this->assertWPError( Site_Link_Audit::parse_request( array( 'page' => 'intertexere-site-link-audit', 'category' => 'other' ) ) );
		$this->assertWPError( Site_Link_Audit::parse_request( array( 'page' => 'intertexere-site-link-audit', 'per_page' => '51' ) ) );
		$this->assertWPError( Site_Link_Audit::parse_request( array( 'page' => 'intertexere-site-link-audit', 'search' => '%unbounded%' ) ) );
		$this->assertWPError( Site_Link_Audit::parse_request( array( 'page' => 'intertexere-site-link-audit', 'unknown' => '1' ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 'forbidden', Site_Link_Audit::overview()['status'] );
		$this->expectException( WPDieException::class );
		Admin::render_audit_page();
	}

	public function test_audit_is_read_only_and_changes_no_plugin_or_wordpress_state(): void {
		global $wpdb;

		$target = $this->post( 'Mutation target' );
		$source = $this->post( 'Mutation source', '<a href="' . esc_url( get_permalink( $target ) ) . '">Target</a>' );
		$before = array(
			'content' => get_post_field( 'post_content', $source ),
			'posts' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ),
			'index' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table_name() ),
			'sources' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::graph_sources_table_name() ),
			'edges' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::link_edges_table_name() ),
			'generations' => $this->generations(),
			'settings' => get_option( Settings::OPTION ),
			'schema' => get_option( Schema::VERSION_OPTION ),
		);
		$this->page( 'thin', $target );
		Site_Link_Audit::overview();
		$after = array(
			'content' => get_post_field( 'post_content', $source ),
			'posts' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ),
			'index' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table_name() ),
			'sources' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::graph_sources_table_name() ),
			'edges' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::link_edges_table_name() ),
			'generations' => $this->generations(),
			'settings' => get_option( Settings::OPTION ),
			'schema' => get_option( Schema::VERSION_OPTION ),
		);
		$this->assertSame( $before, $after );
	}

	public function test_bounded_page_performance_and_evidence_limits(): void {
		for ( $i = 0; $i < 55; ++$i ) {
			$this->post( 'Performance orphan ' . $i );
		}
		$result = Site_Link_Audit::page( $this->request( 'orphans', 50 ) );
		$metrics = $result['metrics'];
		fwrite( STDOUT, sprintf( "\n0.6 50-row page: %d queries, %.3f ms, %d bytes peak delta, %d rows\n", $metrics['query_count'], $metrics['elapsed_ms'], $metrics['memory_delta'], $metrics['result_count'] ) );
		$this->assertSame( 'current', $result['status'] );
		$this->assertCount( 50, $result['rows'] );
		$this->assertLessThanOrEqual( 14, $metrics['query_count'] );
		$this->assertLessThan( 1000, $metrics['elapsed_ms'] );
		$this->assertLessThan( 32 * MB_IN_BYTES, $metrics['memory_delta'] );
		foreach ( $result['rows'] as $row ) {
			$this->assertLessThanOrEqual( 2, count( $row['evidence'] ) );
		}
	}

	private function post( string $title, string $content = '<p>Audit fixture content.</p>', string $status = 'publish' ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_title' => $title,
				'post_name' => sanitize_title( $title ),
				'post_content' => $content,
				'post_status' => $status,
				'post_author' => $this->administrator_id,
			)
		);
		Indexer::refresh_post( $post_id );
		Link_Graph::refresh_post( $post_id );
		return $post_id;
	}

	/** @return array<string,string> */
	private function generations(): array {
		return array(
			'index' => (string) get_option( Schema::GENERATION_OPTION ),
			'graph' => (string) get_option( Schema::GRAPH_GENERATION_OPTION ),
		);
	}

	/** @return array<string,mixed> */
	private function request( string $category, int $size = 20, int $search = 0 ): array {
		$query = array(
			'page' => 'intertexere-site-link-audit',
			'category' => $category,
			'per_page' => (string) $size,
		);
		if ( $search > 0 ) {
			$query['search'] = (string) $search;
		}
		$request = Site_Link_Audit::parse_request( $query );
		$this->assertIsArray( $request );
		return $request;
	}

	/** @return array<string,mixed> */
	private function page( string $category, int $search = 0 ): array {
		return Site_Link_Audit::page( $this->request( $category, 20, $search ) );
	}
}
