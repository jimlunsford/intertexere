<?php
/**
 * Minimal 0.1 diagnostics and protected administrative actions.
 *
 * @package Intertexere
 */

namespace Intertexere;

final class Admin {
	public const CAPABILITY = 'manage_intertexere';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'register_page' ) );
		add_action( 'admin_post_intertexere_rebuild_index', array( self::class, 'handle_rebuild' ) );
		add_action( 'admin_post_intertexere_rebuild_graph', array( self::class, 'handle_graph_rebuild' ) );
		add_action( 'admin_post_intertexere_reset_graph', array( self::class, 'handle_graph_reset' ) );
		add_action( 'admin_post_intertexere_save_settings', array( self::class, 'handle_settings' ) );
	}

	public static function register_page(): void {
		add_management_page(
			esc_html__( 'Intertexere', 'intertexere' ),
			esc_html__( 'Intertexere', 'intertexere' ),
			self::CAPABILITY,
			'intertexere',
			array( self::class, 'render_page' )
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Intertexere.', 'intertexere' ), '', array( 'response' => 403 ) );
		}

		$diagnostics       = Indexer::diagnostics();
		$state             = $diagnostics['state'];
		$graph_diagnostics = Link_Graph::diagnostics();
		$graph_state       = $graph_diagnostics['state'];
		$settings          = Settings::get();
		$post_types        = Settings::available_post_types();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Intertexere', 'intertexere' ); ?></h1>

			<?php if ( isset( $_GET['intertexere-updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Intertexere settings were saved and a rebuild was queued.', 'intertexere' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['intertexere-rebuild'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'The content-index rebuild was queued.', 'intertexere' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['intertexere-graph-rebuild'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'The internal-link graph rebuild was queued.', 'intertexere' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['intertexere-graph-reset'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'The derived internal-link graph was reset.', 'intertexere' ); ?></p></div>
			<?php endif; ?>

			<h2><?php echo esc_html__( 'Content eligibility', 'intertexere' ); ?></h2>
			<p><?php echo esc_html__( 'Intertexere 0.1 indexes published, public, non-password-protected content only.', 'intertexere' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="intertexere_save_settings">
				<?php wp_nonce_field( 'intertexere_save_settings' ); ?>
				<fieldset>
					<legend class="screen-reader-text"><?php echo esc_html__( 'Eligible post types', 'intertexere' ); ?></legend>
					<?php foreach ( $post_types as $name => $object ) : ?>
						<label style="display:block;margin-bottom:6px;">
							<input type="checkbox" name="eligible_post_types[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, $settings['eligible_post_types'], true ) ); ?>>
							<?php echo esc_html( $object->labels->name ); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>
				<?php submit_button( esc_html__( 'Save eligibility', 'intertexere' ) ); ?>
			</form>

			<h2><?php echo esc_html__( 'Index diagnostics', 'intertexere' ); ?></h2>
			<table class="widefat striped" style="max-width:760px;">
				<tbody>
					<tr><th scope="row"><?php echo esc_html__( 'State', 'intertexere' ); ?></th><td><?php echo esc_html( (string) $state['status'] ); ?></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Active records', 'intertexere' ); ?></th><td><?php echo esc_html( number_format_i18n( $diagnostics['active_records'] ) ); ?></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Total derived rows', 'intertexere' ); ?></th><td><?php echo esc_html( number_format_i18n( $diagnostics['total_records'] ) ); ?></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Schema version', 'intertexere' ); ?></th><td><?php echo esc_html( $diagnostics['schema_version'] ); ?></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Last completed', 'intertexere' ); ?></th><td><?php echo esc_html( $state['finished_at'] ?: esc_html__( 'Not yet', 'intertexere' ) ); ?></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Rebuild scheduled', 'intertexere' ); ?></th><td><?php echo esc_html( $diagnostics['rebuild_scheduled'] ? esc_html__( 'Yes', 'intertexere' ) : esc_html__( 'No', 'intertexere' ) ); ?></td></tr>
					<?php if ( ! empty( $state['error'] ) ) : ?>
						<tr><th scope="row"><?php echo esc_html__( 'Last error', 'intertexere' ); ?></th><td><?php echo esc_html( $state['error'] ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="intertexere_rebuild_index">
				<?php wp_nonce_field( 'intertexere_rebuild_index' ); ?>
				<?php submit_button( esc_html__( 'Rebuild content index', 'intertexere' ), 'secondary' ); ?>
			</form>

			<h2><?php echo esc_html__( 'Internal-link graph diagnostics', 'intertexere' ); ?></h2>
			<table class="widefat striped" style="max-width:760px;">
				<tbody>
					<tr><th scope="row"><?php echo esc_html__( 'State', 'intertexere' ); ?></th><td><?php echo esc_html( (string) $graph_state['status'] ); ?></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Eligible sources', 'intertexere' ); ?></th><td><?php echo esc_html( number_format_i18n( $graph_diagnostics['active_sources'] ) ); ?></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Observed internal edges', 'intertexere' ); ?></th><td><?php echo esc_html( number_format_i18n( $graph_diagnostics['observed_edges'] ) ); ?></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Unresolved internal edges', 'intertexere' ); ?></th><td><?php echo esc_html( number_format_i18n( $graph_diagnostics['unresolved_edges'] ) ); ?></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Self-links', 'intertexere' ); ?></th><td><?php echo esc_html( number_format_i18n( $graph_diagnostics['self_edges'] ) ); ?></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Last completed', 'intertexere' ); ?></th><td><?php echo esc_html( $graph_state['finished_at'] ?: esc_html__( 'Not yet', 'intertexere' ) ); ?></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Rebuild scheduled', 'intertexere' ); ?></th><td><?php echo esc_html( $graph_diagnostics['rebuild_scheduled'] ? esc_html__( 'Yes', 'intertexere' ) : esc_html__( 'No', 'intertexere' ) ); ?></td></tr>
					<?php if ( ! empty( $graph_state['error'] ) ) : ?>
						<tr><th scope="row"><?php echo esc_html__( 'Last error', 'intertexere' ); ?></th><td><?php echo esc_html( $graph_state['error'] ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="intertexere_rebuild_graph">
				<?php wp_nonce_field( 'intertexere_rebuild_graph' ); ?>
				<?php submit_button( esc_html__( 'Rebuild internal-link graph', 'intertexere' ), 'secondary' ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="intertexere_reset_graph">
				<?php wp_nonce_field( 'intertexere_reset_graph' ); ?>
				<?php submit_button( esc_html__( 'Reset derived graph data', 'intertexere' ), 'delete' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Queue a rebuild after capability and intent checks.
	 */
	public static function handle_rebuild(): void {
		self::authorize( 'intertexere_rebuild_index' );

		$result = Indexer::request_rebuild();
		if ( is_wp_error( $result ) || ! $result ) {
			wp_die( esc_html__( 'WordPress could not schedule the Intertexere rebuild.', 'intertexere' ), '', array( 'response' => 500 ) );
		}

		wp_safe_redirect( add_query_arg( 'intertexere-rebuild', '1', admin_url( 'tools.php?page=intertexere' ) ) );
		exit;
	}

	/**
	 * Queue a graph rebuild after capability and intent checks.
	 */
	public static function handle_graph_rebuild(): void {
		self::authorize( 'intertexere_rebuild_graph' );

		$result = Link_Graph::request_rebuild();
		if ( is_wp_error( $result ) || ! $result ) {
			wp_die( esc_html__( 'WordPress could not schedule the Intertexere graph rebuild.', 'intertexere' ), '', array( 'response' => 500 ) );
		}

		wp_safe_redirect( add_query_arg( 'intertexere-graph-rebuild', '1', admin_url( 'tools.php?page=intertexere' ) ) );
		exit;
	}

	/**
	 * Clear only graph-derived data after capability and intent checks.
	 */
	public static function handle_graph_reset(): void {
		self::authorize( 'intertexere_reset_graph' );

		$result = Link_Graph::reset();
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 409 ) );
		}

		wp_safe_redirect( add_query_arg( 'intertexere-graph-reset', '1', admin_url( 'tools.php?page=intertexere' ) ) );
		exit;
	}

	/**
	 * Save validated eligibility settings and queue a rebuild.
	 */
	public static function handle_settings(): void {
		self::authorize( 'intertexere_save_settings' );

		$post_types = isset( $_POST['eligible_post_types'] ) && is_array( $_POST['eligible_post_types'] )
			? wp_unslash( $_POST['eligible_post_types'] )
			: array();

		update_option(
			Settings::OPTION,
			Settings::sanitize( array( 'eligible_post_types' => $post_types ) ),
			false
		);

		wp_safe_redirect( add_query_arg( 'intertexere-updated', '1', admin_url( 'tools.php?page=intertexere' ) ) );
		exit;
	}

	private static function authorize( string $nonce_action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Intertexere.', 'intertexere' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( $nonce_action );
	}
}
