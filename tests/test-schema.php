<?php
/**
 * Schema version 2 migration integration tests.
 */

use Intertexere\Indexer;
use Intertexere\Link_Graph;
use Intertexere\Schema;

class Intertexere_Schema_Test extends WP_UnitTestCase {
	public function test_version_one_upgrade_is_repeatable_and_preserves_index_data_and_posts(): void {
		global $wpdb;

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Version one source content.',
			)
		);
		Indexer::refresh_post( $post_id );
		$before_record  = Indexer::get_record( $post_id );
		$before_content = get_post_field( 'post_content', $post_id );

		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::link_edges_table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::graph_sources_table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		update_option( Schema::VERSION_OPTION, '1', false );

		Schema::maybe_upgrade();

		$this->assertSame( '2', get_option( Schema::VERSION_OPTION ) );
		$this->assertSame( Schema::VERSION, get_option( Schema::VERSION_OPTION ) );
		$this->assertSame( $before_record['content_hash'], Indexer::get_record( $post_id )['content_hash'] );
		$this->assertSame( $before_content, get_post_field( 'post_content', $post_id ) );
		$this->assertSame( Schema::graph_sources_table_name(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::graph_sources_table_name() ) ) );
		$this->assertSame( Schema::link_edges_table_name(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::link_edges_table_name() ) ) );

		Schema::maybe_upgrade();
		Schema::install();
		$this->assertSame( Schema::VERSION, get_option( Schema::VERSION_OPTION ) );
		$this->assertSame( $before_record['content_hash'], Indexer::get_record( $post_id )['content_hash'] );
		$this->assertSame( $before_content, get_post_field( 'post_content', $post_id ) );
		$this->assertNotEmpty( get_option( Schema::GRAPH_GENERATION_OPTION ) );
	}

	public function test_fresh_schema_graph_data_is_disposable_and_rebuildable(): void {
		$target = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$source = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<a href="' . esc_url( get_permalink( $target ) ) . '">Target</a>',
			)
		);
		$before = get_post_field( 'post_content', $source );

		$this->assertTrue( Link_Graph::reset() );
		$this->assertSame( 0, Link_Graph::diagnostics()['observed_edges'] );
		$this->assertTrue( Link_Graph::rebuild() );
		$this->assertCount( 1, Link_Graph::outbound( $source ) );
		$this->assertSame( $before, get_post_field( 'post_content', $source ) );
	}
}
