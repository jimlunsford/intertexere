<?php
/**
 * Privileged-action security tests.
 */

use Intertexere\Admin;

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
}
