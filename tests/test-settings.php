<?php
/**
 * Complete settings and eligibility-only rebuild regressions.
 */

use Intertexere\Indexer;
use Intertexere\Link_Graph;
use Intertexere\Plugin;
use Intertexere\Settings;

class Intertexere_Settings_Test extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		update_option( Settings::OPTION, Settings::defaults(), false );
		wp_clear_scheduled_hook( Indexer::REBUILD_HOOK );
		wp_clear_scheduled_hook( Link_Graph::REBUILD_HOOK );
		delete_option( Indexer::LOCK_OPTION );
		delete_option( Indexer::RERUN_OPTION );
		delete_option( Link_Graph::LOCK_OPTION );
		delete_option( Link_Graph::RERUN_OPTION );
	}

	public function test_ai_defaults_off_and_complete_sanitization_preserves_schema_shape(): void {
		$defaults = Settings::defaults();
		$this->assertFalse( $defaults['enable_ai_enhancement'] );
		$this->assertSame( array( 'post', 'page' ), $defaults['eligible_post_types'] );

		$clean = Settings::sanitize( array( 'eligible_post_types' => array( 'page' ) ) );
		$this->assertFalse( $clean['enable_ai_enhancement'] );
		$this->assertSame( array( 'page' ), $clean['eligible_post_types'] );
	}

	public function test_focused_merges_preserve_unrelated_settings(): void {
		$current = Settings::merge( array( 'enable_ai_enhancement' => true ), Settings::defaults() );
		$this->assertTrue( $current['enable_ai_enhancement'] );
		$this->assertSame( array( 'page', 'post' ), $current['eligible_post_types'] );

		$eligibility = Settings::merge( array( 'eligible_post_types' => array( 'page' ) ), $current );
		$this->assertTrue( $eligibility['enable_ai_enhancement'] );
		$this->assertSame( array( 'page' ), $eligibility['eligible_post_types'] );

		$ai = Settings::merge( array( 'enable_ai_enhancement' => false ), $eligibility );
		$this->assertFalse( $ai['enable_ai_enhancement'] );
		$this->assertSame( array( 'page' ), $ai['eligible_post_types'] );

		$this->assertSame( $ai, Settings::merge( array( 'unknown' => 'ignored' ), $ai ) );
	}

	public function test_only_eligibility_changes_request_derived_rebuilds(): void {
		$old = Settings::defaults();
		$ai  = Settings::merge( array( 'enable_ai_enhancement' => true ), $old );
		Plugin::settings_changed( $old, $ai );
		$this->assertFalse( wp_next_scheduled( Indexer::REBUILD_HOOK ) );
		$this->assertFalse( wp_next_scheduled( Link_Graph::REBUILD_HOOK ) );

		Plugin::settings_changed( $ai, $ai );
		$this->assertFalse( wp_next_scheduled( Indexer::REBUILD_HOOK ) );
		$this->assertFalse( wp_next_scheduled( Link_Graph::REBUILD_HOOK ) );

		$both = Settings::merge( array( 'eligible_post_types' => array( 'page' ), 'enable_ai_enhancement' => false ), $ai );
		Plugin::settings_changed( $ai, $both );
		$this->assertNotFalse( wp_next_scheduled( Indexer::REBUILD_HOOK ) );
		$this->assertNotFalse( wp_next_scheduled( Link_Graph::REBUILD_HOOK ) );
	}
}
