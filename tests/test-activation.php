<?php
/**
 * Activation and compatibility integration tests.
 */

use Intertexere\Indexer;
use Intertexere\Lifecycle;
use Intertexere\Schema;

class Intertexere_Activation_Test extends WP_UnitTestCase {
	public function tear_down(): void {
		Lifecycle::add_capability();
		wp_clear_scheduled_hook( Indexer::REBUILD_HOOK );
		parent::tear_down();
	}

	public function test_activation_installs_schema_capability_and_queues_initial_build_without_mutating_content(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Existing article',
				'post_content' => '<!-- wp:paragraph --><p>Existing article content.</p><!-- /wp:paragraph -->',
			)
		);
		$before = get_post_field( 'post_content', $post_id );

		wp_clear_scheduled_hook( Indexer::REBUILD_HOOK );
		Lifecycle::activate();

		$this->assertSame( Schema::VERSION, get_option( Schema::VERSION_OPTION ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'manage_intertexere' ) );
		$this->assertNotFalse( wp_next_scheduled( Indexer::REBUILD_HOOK ) );
		$this->assertSame( $before, get_post_field( 'post_content', $post_id ) );
	}

	public function test_deactivation_stops_background_work_without_changing_posts_or_index(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Portable native content.',
			)
		);
		Indexer::refresh_post( $post_id );
		$before_content = get_post_field( 'post_content', $post_id );
		$before_record  = Indexer::get_record( $post_id );
		Indexer::request_rebuild();

		Lifecycle::deactivate();

		$this->assertFalse( wp_next_scheduled( Indexer::REBUILD_HOOK ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( 'manage_intertexere' ) );
		$this->assertSame( $before_content, get_post_field( 'post_content', $post_id ) );
		$this->assertSame( $before_record['content_hash'], Indexer::get_record( $post_id )['content_hash'] );
	}

	public function test_unsupported_wordpress_version_fails_clearly(): void {
		global $wp_version;

		$actual_version = $wp_version;
		$wp_version     = '7.0.9';

		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'Intertexere requires WordPress 7.1 or newer.' );

		try {
			Lifecycle::activate();
		} finally {
			$wp_version = $actual_version;
		}
	}
}
