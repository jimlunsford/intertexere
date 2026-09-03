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
	private string $original_permalink_structure;

	public function set_up(): void {
		parent::set_up();
		$this->original_permalink_structure = (string) get_option( 'permalink_structure' );
		update_option( Settings::OPTION, Settings::defaults(), false );
		delete_option( Indexer::LOCK_OPTION );
		delete_option( Indexer::RERUN_OPTION );
		delete_option( Link_Graph::LOCK_OPTION );
		delete_option( Link_Graph::RERUN_OPTION );
		wp_clear_scheduled_hook( Indexer::REBUILD_HOOK );
		wp_clear_scheduled_hook( Link_Graph::REBUILD_HOOK );
		Indexer::reset();
		Link_Graph::reset();
		$role = get_role( 'administrator' );
		$role->add_cap( Admin::CAPABILITY );
		$this->administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->administrator_id );
	}

	public function tear_down(): void {
		$this->set_permalink_structure( $this->original_permalink_structure );
		remove_all_actions( 'intertexere_audit_after_overview_read' );
		remove_all_actions( 'intertexere_audit_before_final_derived_evidence' );
		remove_all_actions( 'intertexere_audit_before_final_object_authority' );
		remove_all_filters( 'intertexere_is_post_eligible' );
		delete_option( Indexer::LOCK_OPTION );
		delete_option( Indexer::RERUN_OPTION );
		delete_option( Link_Graph::LOCK_OPTION );
		delete_option( Link_Graph::RERUN_OPTION );
		wp_clear_scheduled_hook( Indexer::REBUILD_HOOK );
		wp_clear_scheduled_hook( Link_Graph::REBUILD_HOOK );
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

	public function test_noncanonical_requires_current_query_identity_and_omits_unproved_path_aliases(): void {
		global $wpdb;
		$this->set_permalink_structure( '/%postname%/' );

		$target = $this->post( 'Current URL target' );
		$canonical = (string) get_permalink( $target );
		$canonical_source = $this->post( 'Canonical URL source', '<a href="' . esc_url( $canonical ) . '">Target</a>' );
		$this->assertCount( 0, $this->page( 'noncanonical', $canonical_source )['rows'] );

		$relative = (string) wp_parse_url( $canonical, PHP_URL_PATH );
		$query = (string) wp_parse_url( $canonical, PHP_URL_QUERY );
		$relative .= '' !== $query ? '?' . $query : '';
		$relative_source = $this->post( 'Relative canonical source', '<a href="' . esc_attr( $relative ) . '">Target</a>' );
		$this->assertCount( 0, $this->page( 'noncanonical', $relative_source )['rows'] );

		$query_source = $this->post( 'Query identity source', '<a href="/?p=' . $target . '&audit=1">Target</a>' );
		$query_result = $this->page( 'noncanonical', $query_source );
		$this->assertSame( 'current', $query_result['status'] );
		$this->assertCount( 1, $query_result['rows'] );
		$this->assertSame( $target, $query_result['rows'][0]['target_post_id'] );
		$page_target = $this->page_post( 'Explicit page identity target' );
		$page_source = $this->post( 'Explicit page identity source', '<a href="/?page_id=' . $page_target . '&audit=1">Page target</a>' );
		$page_result = $this->page( 'noncanonical', $page_source );
		$this->assertSame( 'current', $page_result['status'] );
		$this->assertCount( 1, $page_result['rows'] );
		$this->assertSame( $page_target, $page_result['rows'][0]['target_post_id'] );

		$other = $this->post( 'Other query identity' );
		$mismatch_source = $this->post( 'Historical mismatch source', '<a href="/?p=' . $other . '">Other</a>' );
		$wpdb->update(
			Schema::link_edges_table_name(),
			array( 'target_post_id' => $target, 'target_identity_hash' => hash( 'sha256', 'post:' . $target ) ),
			array( 'generation' => get_option( Schema::GRAPH_GENERATION_OPTION ), 'source_post_id' => $mismatch_source ),
			array( '%d', '%s' ),
			array( '%s', '%d' )
		);
		$this->assertCount( 0, $this->page( 'noncanonical', $mismatch_source )['rows'], 'Historical target identity must not override the representative query ID.' );

		$old_target = $this->post( 'Old alias target' );
		$old_url = (string) get_permalink( $old_target );
		$old_source = $this->post( 'Old alias source', '<a href="' . esc_url( $old_url ) . '">Old target</a>' );
		wp_update_post( array( 'ID' => $old_target, 'post_name' => 'new-alias-target' ) );
		add_post_meta( $old_target, '_wp_old_slug', 'old-alias-target' );
		$this->assertCount( 0, $this->page( 'noncanonical', $old_source )['rows'], 'Schema 2 does not set-wise prove retained old-slug ownership, so valid aliases are conservatively omitted.' );

		$claimant = self::factory()->post->create(
			array(
				'post_title' => 'Old alias path claimant',
				'post_name' => 'old-alias-target',
				'post_status' => 'publish',
				'post_author' => $this->administrator_id,
			)
		);
		Indexer::refresh_post( $claimant );
		Link_Graph::refresh_post( $claimant );
		$this->assertCount( 0, $this->page( 'noncanonical', $old_source )['rows'], 'A path now claimed by another post must not be reported for the historical target.' );
		delete_post_meta( $old_target, '_wp_old_slug', 'old-alias-target' );
		$this->assertCount( 0, $this->page( 'noncanonical', $old_source )['rows'], 'An unproved old path remains omitted without being called broken.' );

		$overview = Site_Link_Audit::overview();
		$this->assertSame( 'current', $overview['status'] );
		$this->assertSame( 2, $overview['counts']['noncanonical'] );
		$this->assertCount( 1, $this->page( 'noncanonical', $query_source )['rows'] );
	}

	public function test_noncanonical_query_parser_uses_first_delimiter_and_separator_boundaries(): void {
		$this->set_permalink_structure( '/%postname%/' );

		$target = $this->post( 'Query parser target' );
		$page_target = $this->page_post( 'Query parser page target' );
		$other = $this->post( 'Query parser other target' );
		$cases = array(
			'p first' => array( $target, '/?p=' . $target, true ),
			'p before unrelated parameter' => array( $target, '/?p=' . $target . '&foo=bar', true ),
			'p after unrelated parameter' => array( $target, '/?foo=bar&p=' . $target, true ),
			'page identity' => array( $page_target, '/?page_id=' . $page_target, true ),
			'literal question mark before real p parameter' => array( $target, '/?next=?something&p=' . $target, true ),
			'attachment identity' => array( $target, '/?attachment_id=' . $target, true ),
			'embedded p' => array( $target, '/?next=?p=' . $target, false ),
			'embedded page identity' => array( $target, '/?next=?page_id=' . $target, false ),
			'embedded attachment identity' => array( $target, '/?next=?attachment_id=' . $target, false ),
			'embedded unseparated p' => array( $target, '/?foo=p=' . $target, false ),
			'historical target mismatch' => array( $target, '/?p=' . $other, false ),
			'two identity keys' => array( $target, '/?p=' . $target . '&page_id=' . $target, false ),
			'duplicate matching p' => array( $target, '/?p=' . $target . '&p=' . $target, false ),
			'duplicate conflicting p' => array( $target, '/?p=' . $target . '&p=' . $other, false ),
		);

		$valid_sources = array();
		foreach ( $cases as $label => $case ) {
			list( $case_target, $url, $valid ) = $case;
			$source = $this->post( 'Query parser source ' . $label, '<a href="' . esc_url( get_permalink( $case_target ) ) . '">Target</a>' );
			$this->rewrite_edge_identity_url( $source, $case_target, home_url( $url ) );
			$result = $this->page( 'noncanonical', $source );
			$this->assertSame( 'current', $result['status'], $label );
			$this->assertCount( $valid ? 1 : 0, $result['rows'], $label );
			if ( $valid ) {
				$valid_sources[] = $source;
			}
		}

		$overview = Site_Link_Audit::overview();
		$this->assertSame( 'current', $overview['status'] );
		$this->assertSame( count( $valid_sources ), $overview['counts']['noncanonical'], 'Overview must use the same first-delimiter predicate as category selection.' );
		$category = $this->page( 'noncanonical' );
		$this->assertSame( 'current', $category['status'] );
		$this->assertCount( count( $valid_sources ), $category['rows'] );
	}

	public function test_noncanonical_final_reread_rechecks_current_representative_query_identity(): void {
		global $wpdb;

		$target = $this->post( 'Representative identity target' );
		$source = $this->post( 'Representative identity source', '<a href="/?p=' . $target . '&audit=before">Target</a>' );
		add_action(
			'intertexere_audit_before_final_derived_evidence',
			static function () use ( $wpdb, $source, $target ): void {
				$wpdb->update(
					Schema::link_edges_table_name(),
					array( 'normalized_url' => home_url( '/?next=?p=' . $target ) ),
					array( 'generation' => get_option( Schema::GRAPH_GENERATION_OPTION ), 'source_post_id' => $source ),
					array( '%s' ),
					array( '%s', '%d' )
				);
			},
			10,
			0
		);
		$result = $this->page( 'noncanonical', $source );
		$this->assertSame( 'stale', $result['status'] );
		$this->assertArrayNotHasKey( 'rows', $result );
	}

	public function test_noncanonical_newly_valid_identity_does_not_enter_selected_page_during_request(): void {
		global $wpdb;

		$target = $this->post( 'New identity target' );
		$source = $this->post( 'New identity source', '<a href="' . esc_url( get_permalink( $target ) ) . '">Target</a>' );
		$this->rewrite_edge_identity_url( $source, $target, home_url( '/?next=?p=' . $target ) );
		add_action(
			'intertexere_audit_before_final_derived_evidence',
			static function () use ( $wpdb, $source, $target ): void {
				$wpdb->update(
					Schema::link_edges_table_name(),
					array( 'normalized_url' => home_url( '/?next=?something&p=' . $target ) ),
					array( 'generation' => get_option( Schema::GRAPH_GENERATION_OPTION ), 'source_post_id' => $source ),
					array( '%s' ),
					array( '%s', '%d' )
				);
			},
			10,
			0
		);

		$result = $this->page( 'noncanonical', $source );
		$this->assertSame( 'current', $result['status'] );
		$this->assertCount( 0, $result['rows'], 'A finding created after selection must wait for a later request.' );
		remove_all_actions( 'intertexere_audit_before_final_derived_evidence' );

		$refreshed = $this->page( 'noncanonical', $source );
		$this->assertSame( 'current', $refreshed['status'] );
		$this->assertCount( 1, $refreshed['rows'] );
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
		$link = '<a href="' . esc_url( get_permalink( $target ) ) . '">Target</a>';
		$source_a = $this->post( 'Filter source A', $link );
		$source_b = $this->post( 'Filter source B', $link );
		$this->assertSame( 2, Site_Link_Audit::classify_targets( array( $target ), $this->generations() )[ $target ]['class'] );

		$excluded = array( $source_b );
		$exclude = static function ( bool $eligible, WP_Post $post ) use ( &$excluded ): bool {
			return in_array( $post->ID, $excluded, true ) ? false : $eligible;
		};
		add_filter( 'intertexere_is_post_eligible', $exclude, 10, 2 );
		$this->assertSame( 2, Site_Link_Audit::classify_targets( array( $target ), $this->generations() )[ $target ]['class'] );
		Indexer::refresh_post( $source_b );
		Link_Graph::refresh_post( $source_b );
		$this->assertSame( 1, Site_Link_Audit::classify_targets( array( $target ), $this->generations() )[ $target ]['class'] );

		$excluded[] = $source_a;
		$this->assertSame( 'stale', $this->page( 'thin', $target )['status'] );
		Indexer::refresh_post( $source_a );
		Link_Graph::refresh_post( $source_a );
		$this->assertSame( 0, Site_Link_Audit::classify_targets( array( $target ), $this->generations() )[ $target ]['class'] );
		remove_filter( 'intertexere_is_post_eligible', $exclude, 10 );
	}

	public function test_full_rebuild_exclusion_is_absence_not_a_fabricated_removed_marker(): void {
		global $wpdb;

		$target = $this->post( 'Rebuild filter target' );
		$source = $this->post( 'Rebuild filter source', '<a href="' . esc_url( get_permalink( $target ) ) . '">Target</a>' );
		$exclude = static function ( bool $eligible, WP_Post $post ) use ( $source ): bool {
			return $post->ID === $source ? false : $eligible;
		};
		add_filter( 'intertexere_is_post_eligible', $exclude, 10, 2 );
		$this->assertTrue( Indexer::rebuild() );
		$this->assertTrue( Link_Graph::rebuild() );
		$generations = $this->generations();
		$this->assertNull(
			$wpdb->get_var(
				$wpdb->prepare(
					'SELECT source_state FROM ' . Schema::graph_sources_table_name() . ' WHERE generation = %s AND source_post_id = %d',
					$generations['graph'],
					$source
				)
			)
		);
		$this->assertNull(
			$wpdb->get_var(
				$wpdb->prepare(
					'SELECT post_id FROM ' . Schema::table_name() . ' WHERE generation = %s AND post_id = %d',
					$generations['index'],
					$source
				)
			)
		);
		$this->assertSame( 0, Site_Link_Audit::classify_targets( array( $target ), $generations )[ $target ]['class'] );
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

	public function test_orphan_zero_to_two_and_two_plus_reductions_are_saturated_and_detected(): void {
		$target = $this->post( 'Saturated race target' );
		$source_a = $this->post( 'Saturated source A', '<p>No link.</p>' );
		$source_b = $this->post( 'Saturated source B', '<p>No link.</p>' );
		add_action(
			'intertexere_audit_before_final_derived_evidence',
			static function () use ( $source_a, $source_b, $target ): void {
				$link = '<a href="' . esc_url( get_permalink( $target ) ) . '">Target</a>';
				wp_update_post( array( 'ID' => $source_a, 'post_content' => $link ) );
				wp_update_post( array( 'ID' => $source_b, 'post_content' => $link ) );
			},
			10,
			0
		);
		$this->assertSame( 'stale', $this->page( 'orphans', $target )['status'] );
		remove_all_actions( 'intertexere_audit_before_final_derived_evidence' );

		$two_plus = Site_Link_Audit::classify_targets( array( $target ), $this->generations() );
		$this->assertSame( 2, $two_plus[ $target ]['class'] );
		$this->assertCount( 2, $two_plus[ $target ]['evidence'] );
		wp_update_post( array( 'ID' => $source_b, 'post_content' => '<p>Removed.</p>' ) );
		$this->assertSame( 1, Site_Link_Audit::classify_targets( array( $target ), $this->generations() )[ $target ]['class'] );
		wp_update_post( array( 'ID' => $source_a, 'post_content' => '<p>Removed.</p>' ) );
		$this->assertSame( 0, Site_Link_Audit::classify_targets( array( $target ), $this->generations() )[ $target ]['class'] );
	}

	public function test_ready_to_removed_and_noncanonical_url_replacement_races_fail_stale(): void {
		global $wpdb;

		$target = $this->post( 'Removed race target' );
		$source = $this->post( 'Removed race source', '<a href="' . esc_url( get_permalink( $target ) ) . '">Target</a>' );
		add_action(
			'intertexere_audit_before_final_derived_evidence',
			static function () use ( $wpdb, $source ): void {
				$wpdb->update(
					Schema::graph_sources_table_name(),
					array( 'source_state' => 'removed' ),
					array( 'generation' => get_option( Schema::GRAPH_GENERATION_OPTION ), 'source_post_id' => $source ),
					array( '%s' ),
					array( '%s', '%d' )
				);
			},
			10,
			0
		);
		$this->assertSame( 'stale', $this->page( 'thin', $target )['status'] );
		remove_all_actions( 'intertexere_audit_before_final_derived_evidence' );
		Link_Graph::refresh_post( $source );

		wp_update_post( array( 'ID' => $source, 'post_content' => '<a href="/?p=' . $target . '&form=one">Target</a>' ) );
		add_action(
			'intertexere_audit_before_final_derived_evidence',
			static function () use ( $source, $target ): void {
				wp_update_post( array( 'ID' => $source, 'post_content' => '<a href="/?p=' . $target . '&form=two">Target</a>' ) );
			},
			10,
			0
		);
		$this->assertSame( 'stale', $this->page( 'noncanonical', $source )['status'] );
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
		foreach ( array( Schema::GENERATION_OPTION, Schema::GRAPH_GENERATION_OPTION ) as $option ) {
			$original = get_option( $option );
			add_action(
				'intertexere_audit_before_final_object_authority',
				static function () use ( $option ): void {
					update_option( $option, 'audit-race-generation', false );
				},
				10,
				0
			);
			$this->assertSame( 'stale', $this->page( 'orphans', $target )['status'], $option );
			remove_all_actions( 'intertexere_audit_before_final_object_authority' );
			update_option( $option, $original, false );
		}

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

	public function test_deletion_status_password_type_and_runtime_eligibility_races_fail_closed(): void {
		global $wpdb;

		$mutations = array(
			'deleted' => static function ( int $target ) use ( $wpdb ): void {
				$wpdb->delete( $wpdb->posts, array( 'ID' => $target ), array( '%d' ) );
				clean_post_cache( $target );
			},
			'status' => static function ( int $target ): void {
				wp_update_post( array( 'ID' => $target, 'post_status' => 'draft' ) );
			},
			'password' => static function ( int $target ): void {
				wp_update_post( array( 'ID' => $target, 'post_password' => 'changed' ) );
			},
			'type' => static function ( int $target ) use ( $wpdb ): void {
				$wpdb->update( $wpdb->posts, array( 'post_type' => 'attachment' ), array( 'ID' => $target ), array( '%s' ), array( '%d' ) );
				clean_post_cache( $target );
			},
		);
		foreach ( $mutations as $label => $mutation ) {
			$target = $this->post( 'Object race ' . $label );
			add_action(
				'intertexere_audit_before_final_object_authority',
				static function () use ( $mutation, $target ): void { $mutation( $target ); },
				10,
				0
			);
			$this->assertSame( 'stale', $this->page( 'orphans', $target )['status'], $label );
			remove_all_actions( 'intertexere_audit_before_final_object_authority' );
		}

		$target = $this->post( 'Runtime race target' );
		$exclude = static function ( bool $eligible, WP_Post $post ) use ( $target ): bool {
			return $post->ID === $target ? false : $eligible;
		};
		add_action(
			'intertexere_audit_before_final_object_authority',
			static function () use ( $exclude ): void { add_filter( 'intertexere_is_post_eligible', $exclude, 10, 2 ); },
			10,
			0
		);
		$this->assertSame( 'stale', $this->page( 'orphans', $target )['status'] );
		remove_filter( 'intertexere_is_post_eligible', $exclude, 10 );
	}

	public function test_source_deletion_and_permalink_races_fail_closed(): void {
		global $wpdb;

		$source = $this->post( 'Deleted source race', '<a href="/audit-source-delete-race/">Unknown</a>' );
		add_action(
			'intertexere_audit_before_final_object_authority',
			static function () use ( $wpdb, $source ): void {
				$wpdb->delete( $wpdb->posts, array( 'ID' => $source ), array( '%d' ) );
				clean_post_cache( $source );
			},
			10,
			0
		);
		$this->assertSame( 'stale', $this->page( 'unavailable', $source )['status'] );
		remove_all_actions( 'intertexere_audit_before_final_object_authority' );

		$target = $this->post( 'Permalink race target' );
		add_action(
			'intertexere_audit_before_final_object_authority',
			static function () use ( $target ): void {
				wp_update_post( array( 'ID' => $target, 'post_name' => 'permalink-race-changed' ) );
			},
			10,
			0
		);
		$this->assertSame( 'stale', $this->page( 'orphans', $target )['status'] );
	}

	public function test_preexisting_target_permalink_mismatch_requires_derived_refresh(): void {
		$target = $this->post( 'Preexisting permalink target' );
		$source = $this->post( 'Preexisting permalink source', '<a href="/?p=' . $target . '&audit=old">Target</a>' );
		$changed_permalink = static function ( string $permalink, WP_Post $post ) use ( $target ): string {
			return $post->ID === $target ? add_query_arg( 'current-authority', 'changed', $permalink ) : $permalink;
		};
		add_filter( 'post_link', $changed_permalink, 10, 2 );
		$result = $this->page( 'noncanonical', $source );
		remove_filter( 'post_link', $changed_permalink, 10 );
		$this->assertSame( 'stale', $result['status'] );
		$this->assertTrue( $result['rebuild_required'] );
	}

	public function test_concurrent_new_finding_waits_for_refresh_and_nested_read_shares_no_state(): void {
		$target = $this->post( 'Stable selected orphan' );
		$new_target = 0;
		add_action(
			'intertexere_audit_before_final_derived_evidence',
			function () use ( &$new_target ): void {
				$new_target = $this->post( 'Concurrent new orphan' );
			},
			10,
			0
		);
		$result = $this->page( 'orphans', $target );
		$this->assertSame( 'current', $result['status'] );
		$this->assertSame( array( $target ), wp_list_pluck( $result['rows'], 'post_id' ) );
		remove_all_actions( 'intertexere_audit_before_final_derived_evidence' );
		$this->assertSame( 'current', $this->page( 'orphans', $new_target )['status'] );

		$nested = null;
		add_action(
			'intertexere_audit_before_final_derived_evidence',
			static function () use ( &$nested ): void {
				$nested = Site_Link_Audit::overview();
			},
			10,
			0
		);
		$outer = $this->page( 'orphans', $target );
		$this->assertSame( 'current', $outer['status'] );
		$this->assertIsArray( $nested );
		$this->assertSame( 'current', $nested['status'] );
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
		fwrite( STDOUT, sprintf( "\n0.6 overview: %d queries, %.3f ms, %d bytes peak delta\n", $result['metrics']['query_count'], $result['metrics']['elapsed_ms'], $result['metrics']['memory_delta'] ) );

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
		$this->assertWPError( Site_Link_Audit::parse_request( array( 'page' => 'intertexere-site-link-audit', 'post_type' => 'attachment' ) ) );
		$this->assertWPError( Site_Link_Audit::parse_request( array( 'page' => 'intertexere-site-link-audit', 'search' => '%unbounded%' ) ) );
		$this->assertWPError( Site_Link_Audit::parse_request( array( 'page' => 'intertexere-site-link-audit', 'category' => array( 'orphans' ) ) ) );
		$this->assertWPError( Site_Link_Audit::parse_request( array( 'page' => 'intertexere-site-link-audit', 'unknown' => '1' ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 'forbidden', Site_Link_Audit::overview()['status'] );
		$this->expectException( WPDieException::class );
		Admin::render_audit_page();
	}

	public function test_authorized_read_only_role_sees_only_current_permitted_row_actions(): void {
		$target = $this->post( 'Capability-filtered target' );
		add_role(
			'intertexere_audit_viewer',
			'Audit Viewer',
			array(
				'read' => true,
				Admin::CAPABILITY => true,
			)
		);
		$user = self::factory()->user->create( array( 'role' => 'intertexere_audit_viewer' ) );
		wp_set_current_user( $user );
		$result = $this->page( 'orphans', $target );
		$this->assertSame( 'current', $result['status'] );
		$this->assertTrue( $result['rows'][0]['can_view'] );
		$this->assertFalse( $result['rows'][0]['can_edit'] );
		remove_role( 'intertexere_audit_viewer' );
	}

	public function test_resolved_edge_rows_render_source_target_current_permalink_and_capability_filtered_actions(): void {
		$target = $this->post( 'Rendered edge target' );
		$source = $this->post( 'Rendered edge source', '<a href="' . esc_url( get_permalink( $target ) ) . '">One</a><a href="' . esc_url( get_permalink( $target ) ) . '">Two</a>' );
		$repeated = $this->page( 'repeated', $source );
		$this->assertSame( 'current', $repeated['status'] );
		$this->assertSame( $target, $repeated['rows'][0]['target_post_id'] );
		$this->assertSame( get_permalink( $target ), $repeated['rows'][0]['target_current']['permalink'] );

		$_GET = array( 'page' => 'intertexere-site-link-audit', 'category' => 'repeated', 'search' => (string) $source );
		ob_start();
		Admin::render_audit_page();
		$html = (string) ob_get_clean();
		$_GET = array();
		$this->assertStringContainsString( '<strong>Source:</strong> Rendered edge source', $html );
		$this->assertStringContainsString( '<strong>Target:</strong> Rendered edge target', $html );
		$this->assertStringContainsString( 'Current target:', $html );
		$this->assertStringContainsString( esc_url( (string) get_permalink( $target ) ), $html );
		$this->assertStringContainsString( 'View target Rendered edge target', $html );
		$this->assertStringContainsString( 'Edit target Rendered edge target', $html );

		wp_update_post( array( 'ID' => $source, 'post_content' => '<a href="' . esc_url( get_permalink( $source ) ) . '">Self</a>' ) );
		$self = $this->page( 'self', $source );
		$this->assertSame( $source, $self['rows'][0]['source_post_id'] );
		$this->assertSame( $source, $self['rows'][0]['target_post_id'] );

		wp_update_post( array( 'ID' => $source, 'post_content' => '<a href="/unknown-rendered-target/">Unknown</a>' ) );
		$unresolved = $this->page( 'unavailable', $source );
		$this->assertNull( $unresolved['rows'][0]['target_post_id'] );
		$this->assertArrayNotHasKey( 'target_current', $unresolved['rows'][0] );
		$_GET = array( 'page' => 'intertexere-site-link-audit', 'category' => 'unavailable', 'search' => (string) $source );
		ob_start();
		Admin::render_audit_page();
		$unresolved_html = (string) ob_get_clean();
		$_GET = array();
		$this->assertStringContainsString( 'Unknown (unresolved)', $unresolved_html );
		$this->assertStringNotContainsString( 'View target', $unresolved_html );

		wp_update_post( array( 'ID' => $source, 'post_content' => '<a href="' . esc_url( get_permalink( $target ) ) . '">Target</a>' ) );
		wp_update_post( array( 'ID' => $target, 'post_status' => 'private' ) );
		$unavailable = $this->page( 'unavailable', $source );
		$this->assertStringContainsString( 'private', $unavailable['rows'][0]['finding_reason'] );
		$this->assertSame( $target, $unavailable['rows'][0]['target_current']['id'] );

		wp_update_post( array( 'ID' => $target, 'post_status' => 'publish' ) );
		wp_update_post( array( 'ID' => $source, 'post_content' => '<a href="' . esc_url( get_permalink( $target ) ) . '">One</a><a href="' . esc_url( get_permalink( $target ) ) . '">Two</a>' ) );
		add_role( 'intertexere_edge_viewer', 'Edge Audit Viewer', array( 'read' => true, Admin::CAPABILITY => true ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'intertexere_edge_viewer' ) ) );
		$readonly = $this->page( 'repeated', $source );
		$this->assertTrue( $readonly['rows'][0]['target_current']['can_view'] );
		$this->assertFalse( $readonly['rows'][0]['target_current']['can_edit'] );
		$this->assertFalse( $readonly['rows'][0]['source_can_edit'] );
		remove_role( 'intertexere_edge_viewer' );
	}

	public function test_database_failure_returns_safe_unavailable_state(): void {
		$this->post( 'Database failure target' );
		$break_classification = static function ( string $query ): string {
			return false !== strpos( $query, 'AS evidence_one' ) ? 'SELECT this is not valid SQL' : $query;
		};
		add_filter( 'query', $break_classification );
		$result = $this->page( 'orphans' );
		remove_filter( 'query', $break_classification );
		$this->assertSame( 'unavailable', $result['status'] );
		$this->assertArrayNotHasKey( 'rows', $result );
	}

	public function test_audit_is_read_only_and_changes_no_plugin_or_wordpress_state(): void {
		global $wpdb;

		$target = $this->post( 'Mutation target' );
		$source = $this->post( 'Mutation source', '<a href="' . esc_url( get_permalink( $target ) ) . '">Target</a>' );
		$before = array(
			'content' => get_post_field( 'post_content', $source ),
			'posts' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ),
			'revisions' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" ),
			'autosaves' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_name LIKE '%-autosave-%'" ),
			'index' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table_name() ),
			'sources' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::graph_sources_table_name() ),
			'edges' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::link_edges_table_name() ),
			'metadata' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ),
			'taxonomy' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_relationships}" ),
			'transients' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'" ),
			'generations' => $this->generations(),
			'settings' => get_option( Settings::OPTION ),
			'schema' => get_option( Schema::VERSION_OPTION ),
		);
		$this->page( 'thin', $target );
		Site_Link_Audit::overview();
		$after = array(
			'content' => get_post_field( 'post_content', $source ),
			'posts' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ),
			'revisions' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" ),
			'autosaves' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_name LIKE '%-autosave-%'" ),
			'index' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table_name() ),
			'sources' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::graph_sources_table_name() ),
			'edges' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::link_edges_table_name() ),
			'metadata' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ),
			'taxonomy' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_relationships}" ),
			'transients' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'" ),
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
		$twenty = Site_Link_Audit::page( $this->request( 'orphans', 20 ) );
		$this->assertSame( 'current', $twenty['status'] );
		$this->assertCount( 20, $twenty['rows'] );
		$this->assertLessThanOrEqual( 12, $twenty['metrics']['query_count'] );
		$this->assertLessThan( 750, $twenty['metrics']['elapsed_ms'] );
		fwrite( STDOUT, sprintf( "\n0.6 20-row page: %d queries, %.3f ms, %d bytes peak delta, %d rows\n", $twenty['metrics']['query_count'], $twenty['metrics']['elapsed_ms'], $twenty['metrics']['memory_delta'], $twenty['metrics']['result_count'] ) );

		$result = Site_Link_Audit::page( $this->request( 'orphans', 50 ) );
		$metrics = $result['metrics'];
		fwrite( STDOUT, sprintf( "\n0.6 50-row page: %d queries, %.3f ms, %d bytes peak delta, %d bytes live delta, %d rows\n", $metrics['query_count'], $metrics['elapsed_ms'], $metrics['memory_delta'], $metrics['memory_usage_delta'], $metrics['result_count'] ) );
		$this->assertSame( 'current', $result['status'] );
		$this->assertCount( 50, $result['rows'] );
		$this->assertLessThanOrEqual( 14, $metrics['query_count'] );
		$this->assertLessThan( 1000, $metrics['elapsed_ms'] );
		$this->assertLessThan( 32 * MB_IN_BYTES, $metrics['memory_delta'] );
		foreach ( $result['rows'] as $row ) {
			$this->assertLessThanOrEqual( 2, count( $row['evidence'] ) );
		}
	}

	public function test_hierarchical_page_permalink_dependencies_are_bulk_primed_and_bounded(): void {
		$this->set_permalink_structure( '/%postname%/' );
		for ( $i = 0; $i < 19; ++$i ) {
			$root = $this->page_post( 'Hierarchy root ' . $i );
			$parent = $this->page_post( 'Hierarchy parent ' . $i, $root );
			$this->page_post( 'Hierarchy child ' . $i, $parent );
		}

		$twenty = Site_Link_Audit::page( $this->request( 'orphans', 20 ) );
		$this->assertSame( 'current', $twenty['status'] );
		$this->assertCount( 20, $twenty['rows'] );
		$this->assertLessThanOrEqual( 12, $twenty['metrics']['query_count'] );
		$this->assertLessThan( 750, $twenty['metrics']['elapsed_ms'] );
		foreach ( $twenty['rows'] as $row ) {
			$this->assertSame( get_permalink( $row['post_id'] ), $row['current_permalink'] );
		}

		$fifty = Site_Link_Audit::page( $this->request( 'orphans', 50 ) );
		$this->assertSame( 'current', $fifty['status'] );
		$this->assertCount( 50, $fifty['rows'] );
		$this->assertLessThanOrEqual( 14, $fifty['metrics']['query_count'] );
		$this->assertLessThan( 1000, $fifty['metrics']['elapsed_ms'] );
		$this->assertLessThan( 32 * MB_IN_BYTES, $fifty['metrics']['memory_delta'] );
		fwrite( STDOUT, sprintf( "\n0.6 hierarchical pages: 20 rows %d queries %.3f ms; 50 rows %d queries %.3f ms, %d bytes peak delta, %d bytes live delta\n", $twenty['metrics']['query_count'], $twenty['metrics']['elapsed_ms'], $fifty['metrics']['query_count'], $fifty['metrics']['elapsed_ms'], $fifty['metrics']['memory_delta'], $fifty['metrics']['memory_usage_delta'] ) );
	}

	public function test_category_and_author_permalink_dependencies_are_bulk_primed(): void {
		$this->set_permalink_structure( '/%author%/%category%/%postname%/' );
		for ( $i = 0; $i < 22; ++$i ) {
			$author = self::factory()->user->create(
				array(
					'user_login' => 'audit_author_' . $i,
					'user_nicename' => 'audit-author-' . $i,
					'role' => 'author',
				)
			);
			$parent = self::factory()->category->create( array( 'name' => 'Audit parent ' . $i, 'slug' => 'audit-parent-' . $i ) );
			$child = self::factory()->category->create( array( 'name' => 'Audit child ' . $i, 'slug' => 'audit-child-' . $i, 'parent' => $parent ) );
			$post_id = self::factory()->post->create(
				array(
					'post_title' => 'Token permalink post ' . $i,
					'post_name' => 'token-permalink-post-' . $i,
					'post_content' => '<p>Permalink dependency fixture.</p>',
					'post_status' => 'publish',
					'post_author' => $author,
				)
			);
			wp_set_post_categories( $post_id, array( $child ) );
			Indexer::refresh_post( $post_id );
			Link_Graph::refresh_post( $post_id );
		}

		$result = Site_Link_Audit::page( $this->request( 'orphans', 20 ) );
		$this->assertSame( 'current', $result['status'] );
		$this->assertCount( 20, $result['rows'] );
		$this->assertLessThanOrEqual( 12, $result['metrics']['query_count'] );
		$this->assertLessThan( 750, $result['metrics']['elapsed_ms'] );
		foreach ( $result['rows'] as $row ) {
			$this->assertSame( get_permalink( $row['post_id'] ), $row['current_permalink'] );
			$this->assertStringContainsString( '/audit-author-', $row['current_permalink'] );
			$this->assertStringContainsString( '/audit-parent-', $row['current_permalink'] );
		}
		fwrite( STDOUT, sprintf( "\n0.6 category/author permalinks: 20 rows %d queries, %.3f ms\n", $result['metrics']['query_count'], $result['metrics']['elapsed_ms'] ) );
	}

	public function test_hierarchical_parent_slug_race_invalidates_child_permalink_authority(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$root = $this->page_post( 'Hierarchy race root' );
		$parent = $this->page_post( 'Hierarchy race parent', $root );
		$child = $this->page_post( 'Hierarchy race child', $parent );
		add_action(
			'intertexere_audit_before_final_object_authority',
			static function () use ( $root ): void {
				wp_update_post( array( 'ID' => $root, 'post_name' => 'hierarchy-race-root-changed' ) );
			},
			10,
			0
		);
		$result = $this->page( 'orphans', $child );
		$this->assertSame( 'stale', $result['status'] );
		$this->assertArrayNotHasKey( 'rows', $result );
	}

	public function test_representative_125_post_500_edge_fixture_contains_every_initial_category(): void {
		global $wpdb;

		$targets = array();
		for ( $i = 0; $i < 7; ++$i ) {
			$targets[] = $this->post( 'Representative target ' . $i );
		}
		$orphan = $targets[0];
		$thin = $targets[1];
		$dense = array_slice( $targets, 2, 4 );
		$unavailable = $targets[6];
		$sources = $this->bulk_sources( 125, '<p>Representative saved source content.</p>' );
		$generation = (string) get_option( Schema::GRAPH_GENERATION_OPTION );
		$now = current_time( 'mysql', true );
		$edge_rows = array();
		foreach ( $sources as $position => $source_id ) {
			$destinations = $dense;
			if ( 0 === $position ) {
				$destinations[2] = $source_id;
				$destinations[3] = null;
			} elseif ( 1 === $position ) {
				$destinations[2] = $unavailable;
				$destinations[3] = $thin;
			}
			foreach ( $destinations as $slot => $destination ) {
				if ( null === $destination ) {
					$identity = hash( 'sha256', 'url:/representative-unresolved/' );
					$url = home_url( '/representative-unresolved/' );
					$is_self = 0;
				} else {
					$identity = hash( 'sha256', 'post:' . $destination );
					$url = ( 0 === $position && 1 === $slot )
						? home_url( '/?p=' . $destination . '&representative=1' )
						: (string) get_permalink( $destination );
					$is_self = $destination === $source_id ? 1 : 0;
				}
				$edge_rows[] = array(
					$generation,
					$source_id,
					$identity,
					$destination,
					$url,
					( 0 === $position && 0 === $slot ) ? 2 : 1,
					$is_self,
					$now,
				);
			}
		}
		$this->bulk_insert(
			Schema::link_edges_table_name(),
			array( 'generation', 'source_post_id', 'target_identity_hash', 'target_post_id', 'normalized_url', 'occurrence_count', 'is_self', 'indexed_at_gmt' ),
			array( '%s', '%d', '%s', '%d', '%s', '%d', '%d', '%s' ),
			$edge_rows
		);
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Schema::link_edges_table_name() . ' SET target_post_id = NULL WHERE generation = %s AND source_post_id = %d AND target_identity_hash = %s',
				$generation,
				$sources[0],
				hash( 'sha256', 'url:/representative-unresolved/' )
			)
		);
		wp_update_post( array( 'ID' => $unavailable, 'post_status' => 'trash' ) );

		$this->assertCount( 125, $sources );
		$this->assertCount( 500, $edge_rows );
		$this->assertSame( 0, Site_Link_Audit::classify_targets( array( $orphan ), $this->generations() )[ $orphan ]['class'] );
		$this->assertSame( 1, Site_Link_Audit::classify_targets( array( $thin ), $this->generations() )[ $thin ]['class'] );
		$this->assertSame( 'current', $this->page( 'repeated', $sources[0] )['status'] );
		$this->assertCount( 1, $this->page( 'repeated', $sources[0] )['rows'] );
		$this->assertCount( 1, $this->page( 'self', $sources[0] )['rows'] );
		$this->assertCount( 1, $this->page( 'unavailable', $sources[0] )['rows'] );
		$this->assertCount( 1, $this->page( 'unavailable', $sources[1] )['rows'] );
		$this->assertCount( 1, $this->page( 'noncanonical', $sources[0] )['rows'] );
		fwrite( STDOUT, "\n0.6 representative fixture: 125 source posts, 500 edges, all six category families proven\n" );
	}

	public function test_two_thousand_inbound_edges_saturate_at_two_and_final_race_stays_bounded(): void {
		global $wpdb;

		$target = $this->post( 'High degree target' );
		$target_url = (string) get_permalink( $target );
		$sources = $this->bulk_sources( 2000, '<a href="' . esc_url( $target_url ) . '">High degree target</a>' );
		$generation = (string) get_option( Schema::GRAPH_GENERATION_OPTION );
		$edge_rows = array();
		foreach ( $sources as $source_id ) {
			$edge_rows[] = array(
				$generation,
				$source_id,
				hash( 'sha256', 'post:' . $target ),
				$target,
				$target_url,
				1,
				0,
				current_time( 'mysql', true ),
			);
		}
		$this->bulk_insert(
			Schema::link_edges_table_name(),
			array( 'generation', 'source_post_id', 'target_identity_hash', 'target_post_id', 'normalized_url', 'occurrence_count', 'is_self', 'indexed_at_gmt' ),
			array( '%s', '%d', '%s', '%d', '%s', '%d', '%d', '%s' ),
			$edge_rows
		);

		$filter_calls = 0;
		$counter = static function ( bool $eligible ) use ( &$filter_calls ): bool {
			++$filter_calls;
			return $eligible;
		};
		add_filter( 'intertexere_is_post_eligible', $counter );
		$query_start = get_num_queries();
		$time_start = microtime( true );
		$memory_start = memory_get_usage( false );
		$initial = Site_Link_Audit::classify_targets( array( $target ), $this->generations() );
		$this->assertSame( 2, $initial[ $target ]['class'] );
		$this->assertCount( 2, $initial[ $target ]['evidence'] );
		$this->assertSame( 0, $filter_calls );

		$keep = (int) end( $sources );
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . Schema::link_edges_table_name() . ' WHERE generation = %s AND target_post_id = %d AND source_post_id <> %d',
				$generation,
				$target,
				$keep
			)
		);
		$final = Site_Link_Audit::classify_targets( array( $target ), $this->generations() );
		$elapsed = round( ( microtime( true ) - $time_start ) * 1000, 3 );
		$query_count = get_num_queries() - $query_start;
		$memory_delta = max( 0, memory_get_usage( false ) - $memory_start );
		remove_filter( 'intertexere_is_post_eligible', $counter );

		$this->assertSame( 1, $final[ $target ]['class'] );
		$this->assertCount( 1, $final[ $target ]['evidence'] );
		$this->assertSame( 0, $filter_calls );
		$this->assertLessThanOrEqual( 3, $query_count );
		$this->assertLessThan( 1000, $elapsed );
		$this->assertLessThan( 32 * MB_IN_BYTES, $memory_delta );
		$ready = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Schema::graph_sources_table_name() . ' WHERE generation = %s AND source_state = %s AND source_post_id BETWEEN %d AND %d',
				$generation,
				'ready',
				min( $sources ),
				max( $sources )
			)
		);
		$this->assertSame( 2000, $ready, 'The edge-removal race must leave every source ready.' );
		fwrite( STDOUT, sprintf( "\n0.6 2,000-inbound fixture: %d audit queries, %.3f ms, %d bytes, 2 then 1 evidence class\n", $query_count, $elapsed, $memory_delta ) );
	}

	public function test_one_thousand_posts_four_thousand_edges_use_keyset_pages_without_materialization(): void {
		$targets = array();
		for ( $i = 0; $i < 4; ++$i ) {
			$targets[] = $this->post( 'Large target ' . $i );
		}
		$content = '';
		foreach ( $targets as $target ) {
			$content .= '<a href="/?p=' . $target . '&audit=1">Target</a>';
		}
		$sources = $this->bulk_sources( 1000, $content );
		$generation = (string) get_option( Schema::GRAPH_GENERATION_OPTION );
		$edge_rows = array();
		foreach ( $sources as $source_id ) {
			foreach ( $targets as $target ) {
				$url = home_url( '/?p=' . $target . '&audit=1' );
				$edge_rows[] = array( $generation, $source_id, hash( 'sha256', 'post:' . $target ), $target, $url, 1, 0, current_time( 'mysql', true ) );
			}
		}
		$this->bulk_insert(
			Schema::link_edges_table_name(),
			array( 'generation', 'source_post_id', 'target_identity_hash', 'target_post_id', 'normalized_url', 'occurrence_count', 'is_self', 'indexed_at_gmt' ),
			array( '%s', '%d', '%s', '%d', '%s', '%d', '%d', '%s' ),
			$edge_rows
		);

		$first_request = $this->request( 'noncanonical', 50 );
		$first = Site_Link_Audit::page( $first_request );
		$this->assertSame( 'current', $first['status'] );
		$this->assertCount( 50, $first['rows'] );
		$this->assertNotEmpty( $first['next_cursor'] );
		$this->assertLessThanOrEqual( 14, $first['metrics']['query_count'] );
		$this->assertLessThan( 1000, $first['metrics']['elapsed_ms'] );
		$this->assertLessThan( 32 * MB_IN_BYTES, $first['metrics']['memory_delta'] );

		$second_request = Site_Link_Audit::parse_request(
			array(
				'page' => 'intertexere-site-link-audit',
				'category' => 'noncanonical',
				'per_page' => '50',
				'cursor' => $first['next_cursor'],
			)
		);
		$second = Site_Link_Audit::page( $second_request );
		$this->assertSame( 'current', $second['status'] );
		$this->assertCount( 50, $second['rows'] );
		$first_keys = array_map( static function ( array $row ): string { return $row['source_post_id'] . ':' . $row['target_identity_hash']; }, $first['rows'] );
		$second_keys = array_map( static function ( array $row ): string { return $row['source_post_id'] . ':' . $row['target_identity_hash']; }, $second['rows'] );
		$this->assertSame( array(), array_intersect( $first_keys, $second_keys ) );
		fwrite( STDOUT, sprintf( "\n0.6 1,000-post/4,000-edge fixture: page 1 %d queries %.3f ms, page 2 %d queries %.3f ms\n", $first['metrics']['query_count'], $first['metrics']['elapsed_ms'], $second['metrics']['query_count'], $second['metrics']['elapsed_ms'] ) );
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

	private function page_post( string $title, int $parent = 0 ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_title' => $title,
				'post_name' => sanitize_title( $title ),
				'post_content' => '<p>Hierarchical permalink fixture.</p>',
				'post_status' => 'publish',
				'post_author' => $this->administrator_id,
				'post_parent' => $parent,
				'post_type' => 'page',
			)
		);
		Indexer::refresh_post( $post_id );
		Link_Graph::refresh_post( $post_id );
		return $post_id;
	}

	private function rewrite_edge_identity_url( int $source_id, int $target_id, string $normalized_url ): void {
		global $wpdb;

		$updated = $wpdb->update(
			Schema::link_edges_table_name(),
			array(
				'target_post_id' => $target_id,
				'target_identity_hash' => hash( 'sha256', 'post:' . $target_id ),
				'normalized_url' => $normalized_url,
			),
			array(
				'generation' => get_option( Schema::GRAPH_GENERATION_OPTION ),
				'source_post_id' => $source_id,
			),
			array( '%d', '%s', '%s' ),
			array( '%s', '%d' )
		);
		$this->assertSame( 1, $updated );
	}

	/**
	 * Materialize a large saved-post/index/source fixture without exercising the
	 * unrelated per-post lifecycle inside the measured audit request.
	 *
	 * @return int[]
	 */
	private function bulk_sources( int $count, string $content ): array {
		global $wpdb;

		$first_id = (int) $wpdb->get_var( "SELECT COALESCE(MAX(ID), 0) + 100 FROM {$wpdb->posts}" );
		$ids = range( $first_id, $first_id + $count - 1 );
		$now = current_time( 'mysql', true );
		$post_rows = array();
		foreach ( $ids as $id ) {
			$post_rows[] = array(
				$id, $this->administrator_id, $now, $now, $content, 'Bulk audit source ' . $id, '', 'publish',
				'closed', 'closed', '', 'bulk-audit-source-' . $id, '', '', $now, $now, '', 0,
				home_url( '/?p=' . $id ), 0, 'post', '', 0,
			);
		}
		$this->bulk_insert(
			$wpdb->posts,
			array( 'ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_password', 'post_name', 'to_ping', 'pinged', 'post_modified', 'post_modified_gmt', 'post_content_filtered', 'post_parent', 'guid', 'menu_order', 'post_type', 'post_mime_type', 'comment_count' ),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%d' ),
			$post_rows
		);
		_prime_post_caches( $ids, false, false );

		$index_generation = (string) get_option( Schema::GENERATION_OPTION );
		$graph_generation = (string) get_option( Schema::GRAPH_GENERATION_OPTION );
		$index_rows = array();
		$source_rows = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			$permalink = (string) get_permalink( $post );
			$index_rows[] = array(
				$id, $index_generation, 'post', 'publish', $now, $permalink, 'Bulk audit source ' . $id,
				'', 'Bulk audit source content', '[]', '[]', hash( 'sha256', 'index:' . $id ), $now,
			);
			$source_rows[] = array( $graph_generation, $id, Link_Graph::current_source_hash( $post ), 'ready', $now );
		}
		$this->bulk_insert(
			Schema::table_name(),
			array( 'post_id', 'generation', 'post_type', 'post_status', 'post_modified_gmt', 'permalink', 'title', 'excerpt', 'normalized_content', 'headings', 'taxonomies', 'content_hash', 'indexed_at_gmt' ),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
			$index_rows
		);
		$this->bulk_insert(
			Schema::graph_sources_table_name(),
			array( 'generation', 'source_post_id', 'source_content_hash', 'source_state', 'indexed_at_gmt' ),
			array( '%s', '%d', '%s', '%s', '%s' ),
			$source_rows
		);
		return $ids;
	}

	/**
	 * @param string[]                 $columns Column names controlled by tests.
	 * @param string[]                 $formats wpdb placeholders.
	 * @param array<int,array<int,mixed>> $rows Values.
	 */
	private function bulk_insert( string $table, array $columns, array $formats, array $rows ): void {
		global $wpdb;

		foreach ( array_chunk( $rows, 100 ) as $chunk ) {
			$tuples = array();
			$args = array();
			foreach ( $chunk as $row ) {
				$tuples[] = '(' . implode( ', ', $formats ) . ')';
				$args = array_merge( $args, $row );
			}
			$sql = 'INSERT INTO ' . $table . ' (`' . implode( '`, `', $columns ) . '`) VALUES ' . implode( ', ', $tuples );
			$result = $wpdb->query( $wpdb->prepare( $sql, $args ) );
			$this->assertNotFalse( $result, $wpdb->last_error );
		}
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
