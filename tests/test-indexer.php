<?php
/**
 * Content-index lifecycle integration tests.
 */

use Intertexere\Indexer;
use Intertexere\Settings;

class Intertexere_Indexer_Test extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		update_option( Settings::OPTION, Settings::defaults(), false );
		delete_option( Indexer::LOCK_OPTION );
		delete_option( Indexer::RERUN_OPTION );
		wp_clear_scheduled_hook( Indexer::REBUILD_HOOK );
		Indexer::reset();
	}

	public function tear_down(): void {
		delete_option( Indexer::LOCK_OPTION );
		delete_option( Indexer::RERUN_OPTION );
		wp_clear_scheduled_hook( Indexer::REBUILD_HOOK );
		parent::tear_down();
	}

	public function test_initial_build_indexes_only_explicitly_eligible_content(): void {
		$published_post = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Published post',
				'post_content' => '<h2>A useful heading</h2><p>Searchable body.</p>',
			)
		);
		$published_page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$private = self::factory()->post->create( array( 'post_status' => 'private' ) );
		$password = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);

		Indexer::reset();
		$this->assertTrue( Indexer::rebuild() );

		$this->assertSame( 2, Indexer::active_count() );
		$this->assertNotNull( Indexer::get_record( $published_post ) );
		$this->assertNotNull( Indexer::get_record( $published_page ) );
		$this->assertNull( Indexer::get_record( $draft ) );
		$this->assertNull( Indexer::get_record( $private ) );
		$this->assertNull( Indexer::get_record( $password ) );
		$this->assertSame( array( 'A useful heading' ), json_decode( Indexer::get_record( $published_post )['headings'], true ) );
	}

	public function test_refresh_is_idempotent_and_post_update_replaces_derived_record(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'First version.',
			)
		);

		Indexer::refresh_post( $post_id );
		Indexer::refresh_post( $post_id );
		$before = Indexer::get_record( $post_id );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Second version with changed content.',
			)
		);
		$after = Indexer::get_record( $post_id );

		$this->assertSame( 1, Indexer::active_count() );
		$this->assertNotSame( $before['content_hash'], $after['content_hash'] );
		$this->assertSame( 'Second version with changed content.', $after['normalized_content'] );
	}

	public function test_post_deletion_removes_derived_record(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->assertNotNull( Indexer::get_record( $post_id ) );

		wp_delete_post( $post_id, true );

		$this->assertNull( Indexer::get_record( $post_id ) );
		$this->assertSame( 0, Indexer::active_count() );
	}

	public function test_eligible_to_ineligible_and_back_transitions_update_index(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->assertNotNull( Indexer::get_record( $post_id ) );

		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
		$this->assertNull( Indexer::get_record( $post_id ) );

		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
		$this->assertNotNull( Indexer::get_record( $post_id ) );
	}

	public function test_eligibility_filter_cannot_promote_unpublished_content(): void {
		$draft_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		add_filter( 'intertexere_is_post_eligible', '__return_true' );

		try {
			$this->assertFalse( Indexer::refresh_post( $draft_id ) );
			$this->assertNull( Indexer::get_record( $draft_id ) );
		} finally {
			remove_filter( 'intertexere_is_post_eligible', '__return_true' );
		}
	}

	public function test_rebuild_is_repeatable_and_does_not_modify_post_content(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>Source of truth.</p><!-- /wp:paragraph -->',
			)
		);
		$source = get_post_field( 'post_content', $post_id );

		$this->assertTrue( Indexer::rebuild() );
		$first = Indexer::get_record( $post_id );
		$first_count = Indexer::active_count();
		$this->assertTrue( Indexer::rebuild() );
		$second = Indexer::get_record( $post_id );

		$this->assertSame( $first_count, Indexer::active_count() );
		$this->assertSame( $first['content_hash'], $second['content_hash'] );
		$this->assertSame( $source, get_post_field( 'post_content', $post_id ) );
	}

	public function test_derived_data_reset_and_recovery_rebuild_current_state(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->assertNotNull( Indexer::get_record( $post_id ) );

		Indexer::reset();
		$this->assertSame( 0, Indexer::active_count() );
		$this->assertNotNull( get_post( $post_id ) );

		$this->assertTrue( Indexer::rebuild() );
		$this->assertNotNull( Indexer::get_record( $post_id ) );
	}

	public function test_rebuild_lock_preserves_the_current_complete_generation(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->assertTrue( Indexer::rebuild() );
		$before = Indexer::get_record( $post_id );
		add_option( Indexer::LOCK_OPTION, time(), '', false );

		$result = Indexer::rebuild();

		$this->assertWPError( $result );
		$this->assertSame( 'intertexere_rebuild_locked', $result->get_error_code() );
		$this->assertSame( $before['content_hash'], Indexer::get_record( $post_id )['content_hash'] );
	}

	public function test_multi_batch_rebuild_does_not_skip_posts_when_traversed_posts_become_ineligible(): void {
		$post_ids = self::factory()->post->create_many(
			105,
			array(
				'post_status'  => 'publish',
				'post_content' => 'Eligible batch content.',
			)
		);
		$changed_ids       = array_slice( $post_ids, 0, 2 );
		$eligibility_count = 0;
		$callback          = static function ( bool $eligible ) use ( $changed_ids, &$eligibility_count ): bool {
			++$eligibility_count;

			if ( 100 === $eligibility_count ) {
				wp_delete_post( $changed_ids[0], true );
				wp_update_post( array( 'ID' => $changed_ids[1], 'post_status' => 'draft' ) );
			}

			return $eligible;
		};

		Indexer::reset();
		add_filter( 'intertexere_is_post_eligible', $callback );

		try {
			$result = Indexer::rebuild();
		} finally {
			remove_filter( 'intertexere_is_post_eligible', $callback );
		}

		$this->assertTrue( $result );
		$this->assertGreaterThan( 100, $eligibility_count );
		$this->assertSame( Indexer::active_count(), Indexer::diagnostics()['total_records'] );
		$this->assertNull( Indexer::get_record( $changed_ids[0] ) );
		$this->assertNull( Indexer::get_record( $changed_ids[1] ) );

		foreach ( array_diff( $post_ids, $changed_ids ) as $post_id ) {
			$this->assertNotNull( Indexer::get_record( $post_id ), 'Eligible post ' . $post_id . ' was skipped during rebuild.' );
		}
	}

	public function test_concurrent_mutations_and_second_request_survive_rebuild_cutover(): void {
		$updated_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Content before rebuild.',
			)
		);
		$deleted_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$unpublished_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$stable_id      = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$published_id   = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$request_result = null;
		$running_status = '';
		$callback_ran   = false;
		$callback       = static function ( bool $eligible ) use (
			$updated_id,
			$deleted_id,
			$unpublished_id,
			$published_id,
			&$request_result,
			&$running_status,
			&$callback_ran
		): bool {
			if ( $callback_ran ) {
				return $eligible;
			}

			$callback_ran   = true;
			$request_result = Indexer::request_rebuild();
			$running_status = (string) Indexer::diagnostics()['state']['status'];

			wp_update_post(
				array(
					'ID'           => $updated_id,
					'post_content' => 'Content updated during rebuild.',
				)
			);
			wp_update_post( array( 'ID' => $published_id, 'post_status' => 'publish' ) );
			wp_delete_post( $deleted_id, true );
			wp_update_post( array( 'ID' => $unpublished_id, 'post_status' => 'draft' ) );

			return $eligible;
		};

		Indexer::reset();
		add_filter( 'intertexere_is_post_eligible', $callback );

		try {
			$result = Indexer::rebuild();
		} finally {
			remove_filter( 'intertexere_is_post_eligible', $callback );
		}

		$this->assertTrue( $result );
		$this->assertTrue( $callback_ran );
		$this->assertTrue( $request_result );
		$this->assertSame( 'running', $running_status );
		$this->assertSame( 'Content updated during rebuild.', Indexer::get_record( $updated_id )['normalized_content'] );
		$this->assertNotNull( Indexer::get_record( $published_id ) );
		$this->assertNotNull( Indexer::get_record( $stable_id ) );
		$this->assertNull( Indexer::get_record( $deleted_id ) );
		$this->assertNull( Indexer::get_record( $unpublished_id ) );
		$this->assertSame( Indexer::active_count(), Indexer::diagnostics()['total_records'] );
		$this->assertNotFalse( wp_next_scheduled( Indexer::REBUILD_HOOK ) );
	}

	public function test_changing_slug_refreshes_the_indexed_permalink(): void {
		global $wp_rewrite;

		$original_structure = get_option( 'permalink_structure' );
		$wp_rewrite->set_permalink_structure( '/%postname%/' );

		try {
			$post_id = self::factory()->post->create(
				array(
					'post_status' => 'publish',
					'post_name'   => 'first-slug',
				)
			);
			$before = Indexer::get_record( $post_id );

			wp_update_post( array( 'ID' => $post_id, 'post_name' => 'second-slug' ) );
			$after = Indexer::get_record( $post_id );

			$this->assertNotSame( $before['permalink'], $after['permalink'] );
			$this->assertStringContainsString( 'second-slug', $after['permalink'] );
		} finally {
			$wp_rewrite->set_permalink_structure( $original_structure );
		}
	}
}
