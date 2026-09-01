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
}
