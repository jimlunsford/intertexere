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

		add_management_page(
			esc_html__( 'Intertexere Site Link Audit', 'intertexere' ),
			esc_html__( 'Intertexere Site Link Audit', 'intertexere' ),
			self::CAPABILITY,
			'intertexere-site-link-audit',
			array( self::class, 'render_audit_page' )
		);
	}

	/**
	 * Render the request-scoped, read-only Site Link Audit.
	 */
	public static function render_audit_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to view the Intertexere Site Link Audit.', 'intertexere' ), '', array( 'response' => 403 ) );
		}

		$request = Site_Link_Audit::parse_request( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Intertexere Site Link Audit', 'intertexere' ); ?></h1>
			<p><?php echo esc_html__( 'Read-only findings from literal links in saved post content. Navigation, templates, template parts, widgets, theme chrome, shortcode output, dynamically rendered links, browser-rendered content, and external sites are not inspected.', 'intertexere' ); ?></p>
			<p><?php echo esc_html__( 'Runtime eligibility filters are materialized when content is refreshed or rebuilt. Rebuild the index and graph after changing an arbitrary eligibility filter.', 'intertexere' ); ?></p>
			<?php
			if ( is_wp_error( $request ) ) {
				self::render_audit_notice( 'error', $request->get_error_message() );
				echo '</div>';
				return;
			}

			self::render_audit_tabs( (string) $request['category'] );
			if ( '' === $request['category'] ) {
				self::render_audit_overview( Site_Link_Audit::overview() );
				echo '</div>';
				return;
			}

			self::render_audit_filters( $request );
			self::render_audit_results( Site_Link_Audit::page( $request ), $request );
			?>
		</div>
		<?php
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
				<div class="notice notice-success is-dismissible"><p>
					<?php
					echo esc_html(
						'ai' === $_GET['intertexere-updated'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
							? __( 'AI enhancement preference saved. No content-index or graph rebuild was needed.', 'intertexere' )
							: __( 'Content eligibility saved and the required rebuilds were queued.', 'intertexere' )
					);
					?>
				</p></div>
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
				<input type="hidden" name="settings_scope" value="eligibility">
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

			<h2><?php echo esc_html__( 'AI enhancement', 'intertexere' ); ?></h2>
			<p><?php echo esc_html__( 'AI enhancement is optional and disabled by default. Deterministic suggestions remain available without it.', 'intertexere' ); ?></p>
			<p><?php echo esc_html__( 'When explicitly requested in the editor, bounded unsaved draft excerpts and candidate context may be sent through the provider configured in WordPress. Provider processing and retention are governed by that provider.', 'intertexere' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="intertexere_save_settings">
				<input type="hidden" name="settings_scope" value="ai">
				<input type="hidden" name="ai_setting_present" value="1">
				<?php wp_nonce_field( 'intertexere_save_settings' ); ?>
				<label>
					<input type="checkbox" name="enable_ai_enhancement" value="1" <?php checked( ! empty( $settings['enable_ai_enhancement'] ) ); ?>>
					<?php echo esc_html__( 'Enable explicit AI enhancement in the editor', 'intertexere' ); ?>
				</label>
				<?php submit_button( esc_html__( 'Save AI preference', 'intertexere' ) ); ?>
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
	 * Save one validated settings group without resetting unrelated values.
	 */
	public static function handle_settings(): void {
		self::authorize( 'intertexere_save_settings' );

		$scope   = isset( $_POST['settings_scope'] ) && is_string( $_POST['settings_scope'] )
			? sanitize_key( wp_unslash( $_POST['settings_scope'] ) )
			: '';
		$changes = array();

		if ( 'eligibility' === $scope ) {
			$changes['eligible_post_types'] = isset( $_POST['eligible_post_types'] ) && is_array( $_POST['eligible_post_types'] )
				? wp_unslash( $_POST['eligible_post_types'] )
				: array();
		} elseif ( 'ai' === $scope && isset( $_POST['ai_setting_present'] ) && '1' === (string) $_POST['ai_setting_present'] ) {
			$changes['enable_ai_enhancement'] = isset( $_POST['enable_ai_enhancement'] ) && '1' === (string) $_POST['enable_ai_enhancement'];
		} else {
			wp_die( esc_html__( 'The Intertexere settings request was invalid.', 'intertexere' ), '', array( 'response' => 400 ) );
		}

		update_option( Settings::OPTION, Settings::merge( $changes ), false );

		wp_safe_redirect( add_query_arg( 'intertexere-updated', $scope, admin_url( 'tools.php?page=intertexere' ) ) );
		exit;
	}

	/** @return array<string,string> */
	private static function audit_categories(): array {
		return array(
			''             => __( 'Overview', 'intertexere' ),
			'orphans'      => __( 'Content-body orphans', 'intertexere' ),
			'thin'         => __( 'Thin inbound coverage', 'intertexere' ),
			'unavailable'  => __( 'Unresolved and unavailable', 'intertexere' ),
			'repeated'     => __( 'Repeated target links', 'intertexere' ),
			'self'         => __( 'Self-links', 'intertexere' ),
			'noncanonical' => __( 'Noncanonical opportunities', 'intertexere' ),
		);
	}

	private static function render_audit_tabs( string $current ): void {
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Audit categories', 'intertexere' ) . '">';
		foreach ( self::audit_categories() as $category => $label ) {
			$url = add_query_arg(
				array_filter(
					array(
						'page'     => 'intertexere-site-link-audit',
						'category' => $category,
					),
					static function ( $value ): bool { return '' !== $value; }
				),
				admin_url( 'tools.php' )
			);
			$class = 'nav-tab' . ( $category === $current ? ' nav-tab-active' : '' );
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '"' . ( $category === $current ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	/** @param array<string,mixed> $result Audit overview. */
	private static function render_audit_overview( array $result ): void {
		if ( 'current' !== $result['status'] ) {
			self::render_audit_notice( 'stale' === $result['status'] ? 'warning' : 'error', (string) $result['message'] );
			return;
		}

		echo '<h2>' . esc_html__( 'Active-generation findings', 'intertexere' ) . '</h2>';
		echo '<p><a href="' . esc_url( admin_url( 'tools.php?page=intertexere' ) ) . '">' . esc_html__( 'View index and graph generation diagnostics', 'intertexere' ) . '</a></p>';
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th scope="col">' . esc_html__( 'Category', 'intertexere' ) . '</th><th scope="col">' . esc_html__( 'Findings', 'intertexere' ) . '</th></tr></thead><tbody>';
		foreach ( self::audit_categories() as $category => $label ) {
			if ( '' === $category ) {
				continue;
			}
			$url = add_query_arg( array( 'page' => 'intertexere-site-link-audit', 'category' => $category ), admin_url( 'tools.php' ) );
			echo '<tr><th scope="row"><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></th><td>' . esc_html( number_format_i18n( (int) $result['counts'][ $category ] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** @param array<string,mixed> $request Validated request. */
	private static function render_audit_filters( array $request ): void {
		$post_types = Settings::available_post_types();
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'tools.php' ) ); ?>" style="margin:16px 0;">
			<input type="hidden" name="page" value="intertexere-site-link-audit">
			<input type="hidden" name="category" value="<?php echo esc_attr( $request['category'] ); ?>">
			<label for="intertexere-audit-post-type"><?php echo esc_html__( 'Post type', 'intertexere' ); ?></label>
			<select id="intertexere-audit-post-type" name="post_type">
				<option value=""><?php echo esc_html__( 'All eligible types', 'intertexere' ); ?></option>
				<?php foreach ( Eligibility::post_types() as $post_type ) : ?>
					<option value="<?php echo esc_attr( $post_type ); ?>" <?php selected( $request['post_type'], $post_type ); ?>><?php echo esc_html( isset( $post_types[ $post_type ] ) ? $post_types[ $post_type ]->labels->singular_name : $post_type ); ?></option>
				<?php endforeach; ?>
			</select>
			<label for="intertexere-audit-search"><?php echo esc_html__( 'Exact post ID or slug', 'intertexere' ); ?></label>
			<input id="intertexere-audit-search" name="search" type="search" maxlength="64" value="<?php echo esc_attr( $request['search'] ); ?>">
			<label for="intertexere-audit-page-size"><?php echo esc_html__( 'Rows', 'intertexere' ); ?></label>
			<select id="intertexere-audit-page-size" name="per_page">
				<?php foreach ( array( 20, 50 ) as $size ) : ?>
					<option value="<?php echo esc_attr( (string) $size ); ?>" <?php selected( (int) $request['page_size'], $size ); ?>><?php echo esc_html( (string) $size ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( esc_html__( 'Filter audit', 'intertexere' ), 'secondary', '', false ); ?>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'intertexere-site-link-audit', 'category' => $request['category'] ), admin_url( 'tools.php' ) ) ); ?>"><?php echo esc_html__( 'Refresh audit', 'intertexere' ); ?></a>
		</form>
		<?php
	}

	/**
	 * @param array<string,mixed> $result Audit result.
	 * @param array<string,mixed> $request Validated request.
	 */
	private static function render_audit_results( array $result, array $request ): void {
		if ( 'current' !== $result['status'] ) {
			self::render_audit_notice( 'stale' === $result['status'] ? 'warning' : 'error', (string) $result['message'] );
			return;
		}
		if ( empty( $result['rows'] ) ) {
			self::render_audit_notice( 'info', __( 'No current findings match this category and filter.', 'intertexere' ) );
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th scope="col">' . esc_html__( 'Finding', 'intertexere' ) . '</th><th scope="col">' . esc_html__( 'Source and target', 'intertexere' ) . '</th><th scope="col">' . esc_html__( 'Evidence', 'intertexere' ) . '</th><th scope="col">' . esc_html__( 'Actions', 'intertexere' ) . '</th></tr></thead><tbody>';
		foreach ( $result['rows'] as $row ) {
			self::render_audit_row( $row, (string) $request['category'] );
		}
		echo '</tbody></table>';

		if ( ! empty( $result['next_cursor'] ) ) {
			$url = add_query_arg(
				array(
					'page'      => 'intertexere-site-link-audit',
					'category'  => $request['category'],
					'post_type' => $request['post_type'],
					'search'    => $request['search'],
					'per_page'  => $request['page_size'],
					'cursor'    => $result['next_cursor'],
				),
				admin_url( 'tools.php' )
			);
			echo '<p class="tablenav"><a class="button" rel="next" href="' . esc_url( $url ) . '">' . esc_html__( 'Next page', 'intertexere' ) . '</a></p>';
		}
	}

	/** @param array<string,mixed> $row Finding row. */
	private static function render_audit_row( array $row, string $category ): void {
		$post_category = in_array( $category, array( 'orphans', 'thin' ), true );
		if ( $post_category ) {
			$title = (string) $row['current_title'];
			$reason = 'orphans' === $category
				? __( 'Zero qualifying inbound sources were found in saved eligible content.', 'intertexere' )
				: __( 'Exactly one qualifying inbound source was found in saved eligible content.', 'intertexere' );
			$evidence = esc_html( 'orphans' === $category ? __( 'Saturated inbound class: 0', 'intertexere' ) : __( 'Saturated inbound class: 1', 'intertexere' ) );
			$identity = esc_html( $title );
			$actions = self::audit_post_actions( (int) $row['post_id'], $title, (string) $row['current_permalink'], ! empty( $row['can_view'] ), ! empty( $row['can_edit'] ) );
		} else {
			$title = (string) $row['source_title'];
			$reason = (string) $row['finding_reason'];
			$evidence = esc_html__( 'Observed URL:', 'intertexere' ) . ' <code>' . esc_html( (string) $row['normalized_url'] ) . '</code>';
			if ( 'repeated' === $category ) {
				$evidence .= '<br>' . esc_html( sprintf( _n( '%d occurrence', '%d occurrences', (int) $row['occurrence_count'], 'intertexere' ), (int) $row['occurrence_count'] ) );
			}
			$identity = '<strong>' . esc_html__( 'Source:', 'intertexere' ) . '</strong> ' . esc_html( $title );
			$source_actions = self::audit_post_actions( (int) $row['source_post_id'], $title, (string) $row['source_permalink'], ! empty( $row['source_can_view'] ), ! empty( $row['source_can_edit'] ), __( 'source', 'intertexere' ) );
			$target_id = (int) ( $row['target_post_id'] ?? 0 );
			if ( 0 === $target_id ) {
				$identity .= '<br><strong>' . esc_html__( 'Target:', 'intertexere' ) . '</strong> ' . esc_html__( 'Unknown (unresolved)', 'intertexere' );
				$target_actions = esc_html__( 'No target actions', 'intertexere' );
			} elseif ( ! empty( $row['target_current']['exists'] ) ) {
				$target = $row['target_current'];
				$target_title = '' !== (string) $target['title'] ? (string) $target['title'] : sprintf( __( 'Post %d', 'intertexere' ), $target_id );
				$identity .= '<br><strong>' . esc_html__( 'Target:', 'intertexere' ) . '</strong> ' . esc_html( $target_title ) . ' <span class="description">(' . esc_html( sprintf( __( 'ID %d', 'intertexere' ), $target_id ) ) . ')</span>';
				$current_target = ! empty( $target['can_view'] )
					? '<a href="' . esc_url( (string) $target['permalink'] ) . '">' . esc_html( (string) $target['permalink'] ) . '</a>'
					: '<code>' . esc_html( (string) $target['permalink'] ) . '</code>';
				$identity .= '<br><span class="description">' . esc_html__( 'Current target:', 'intertexere' ) . ' ' . $current_target . '</span>';
				$target_actions = self::audit_post_actions( $target_id, $target_title, (string) $target['permalink'], ! empty( $target['can_view'] ), ! empty( $target['can_edit'] ), __( 'target', 'intertexere' ) );
			} else {
				$identity .= '<br><strong>' . esc_html__( 'Target:', 'intertexere' ) . '</strong> ' . esc_html( sprintf( __( 'Known post ID %d, currently unavailable', 'intertexere' ), $target_id ) );
				$target_actions = esc_html__( 'No target actions', 'intertexere' );
			}
			$actions = '<strong>' . esc_html__( 'Source:', 'intertexere' ) . '</strong> ' . $source_actions . '<br><strong>' . esc_html__( 'Target:', 'intertexere' ) . '</strong> ' . $target_actions;
		}

		echo '<tr><td>' . esc_html( $reason ) . '</td><th scope="row">' . $identity . '</th><td>' . $evidence . '</td><td>' . $actions . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private static function audit_post_actions( int $post_id, string $title, string $permalink, bool $can_view, bool $can_edit, string $context = '' ): string {
		$actions = array();
		$label_title = '' === $context ? $title : $context . ' ' . $title;
		if ( $can_view ) {
			$actions[] = '<a href="' . esc_url( $permalink ) . '">' . esc_html( sprintf( __( 'View %s', 'intertexere' ), $label_title ) ) . '</a>';
		}
		if ( $can_edit ) {
			$edit = get_edit_post_link( $post_id, 'raw' );
			if ( is_string( $edit ) && '' !== $edit ) {
				$actions[] = '<a href="' . esc_url( $edit ) . '">' . esc_html( sprintf( __( 'Edit %s', 'intertexere' ), $label_title ) ) . '</a>';
			}
		}
		return empty( $actions ) ? esc_html__( 'No permitted actions', 'intertexere' ) : implode( ' | ', $actions );
	}

	private static function render_audit_notice( string $type, string $message ): void {
		$allowed = array( 'error', 'warning', 'success', 'info' );
		$type = in_array( $type, $allowed, true ) ? $type : 'info';
		echo '<div class="notice notice-' . esc_attr( $type ) . '"><p role="status">' . esc_html( $message ) . '</p></div>';
	}

	private static function authorize( string $nonce_action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Intertexere.', 'intertexere' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( $nonce_action );
	}
}
