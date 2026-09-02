<?php
/**
 * Privileged-action security tests.
 */

use Intertexere\Admin;
use Intertexere\Settings;

class Intertexere_Admin_Test extends WP_UnitTestCase {
	public function test_rebuild_action_rejects_user_without_plugin_capability(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'You are not allowed to manage Intertexere.' );

		Admin::handle_rebuild();
	}

	public function test_settings_action_rejects_user_without_plugin_capability(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'You are not allowed to manage Intertexere.' );

		Admin::handle_settings();
	}

	public function test_graph_rebuild_action_rejects_user_without_plugin_capability(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'You are not allowed to manage Intertexere.' );

		Admin::handle_graph_rebuild();
	}

	public function test_graph_reset_action_rejects_user_without_plugin_capability(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'You are not allowed to manage Intertexere.' );

		Admin::handle_graph_reset();
	}

	public function test_graph_rebuild_action_requires_a_valid_nonce(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$_REQUEST['_wpnonce'] = 'invalid';

		$this->expectException( WPDieException::class );

		try {
			Admin::handle_graph_rebuild();
		} finally {
			unset( $_REQUEST['_wpnonce'] );
		}
	}

	public function test_graph_reset_action_requires_a_valid_nonce(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$_REQUEST['_wpnonce'] = 'invalid';

		$this->expectException( WPDieException::class );

		try {
			Admin::handle_graph_reset();
		} finally {
			unset( $_REQUEST['_wpnonce'] );
		}
	}

	public function test_settings_page_uses_separate_scopes_and_discloses_ai_provider_processing(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION, Settings::merge( array( 'enable_ai_enhancement' => true ), Settings::defaults() ), false );
		ob_start();
		Admin::render_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="settings_scope" value="eligibility"', $html );
		$this->assertStringContainsString( 'name="settings_scope" value="ai"', $html );
		$this->assertStringContainsString( 'name="enable_ai_enhancement"', $html );
		$this->assertStringContainsString( 'Provider processing and retention are governed by that provider.', $html );
	}

	public function test_malformed_settings_scope_cannot_reset_existing_values(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'intertexere_save_settings' );
		$_POST['settings_scope'] = 'unknown';
		$before = Settings::merge( array( 'enable_ai_enhancement' => true ), Settings::defaults() );
		update_option( Settings::OPTION, $before, false );

		$this->expectException( WPDieException::class );
		try {
			Admin::handle_settings();
		} finally {
			$this->assertSame( $before, Settings::get() );
			unset( $_REQUEST['_wpnonce'], $_POST['settings_scope'] );
		}
	}
}
