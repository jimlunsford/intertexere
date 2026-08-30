<?php
/**
 * Derived, generation-scoped internal-link graph.
 *
 * @package Intertexere
 */

namespace Intertexere;

final class Link_Graph {
	public const REBUILD_HOOK = 'intertexere_rebuild_graph';
	public const STATE_OPTION = 'intertexere_graph_state';
	public const LOCK_OPTION = 'intertexere_graph_rebuild_lock';
	public const RERUN_OPTION = 'intertexere_graph_rebuild_rerun_requested';
	public const BATCH_SIZE = 100;
	private const LOCK_TTL = 3600;
	private const SOURCE_READY = 'ready';
	private const SOURCE_REMOVED = 'removed';

	/**
	 * Queue a graph rebuild outside ordinary editor and front-end requests.
	 *
	 * @return bool|\WP_Error
	 */
	public static function request_rebuild() {
		if ( self::has_live_rebuild_lock() ) {
			update_option( self::RERUN_OPTION, 1, false );

			if ( self::has_live_rebuild_lock() ) {
				return true;
			}

			delete_option( self::RERUN_OPTION );
			return self::request_rebuild();
		}

		if ( get_option( self::LOCK_OPTION ) ) {
			delete_option( self::LOCK_OPTION );
		}

		if ( wp_next_scheduled( self::REBUILD_HOOK ) ) {
			return true;
		}

		$result = wp_schedule_single_event( time() + 1, self::REBUILD_HOOK, array(), true );
		if ( ! is_wp_error( $result ) && $result ) {
			$state           = self::state();
			$state['status'] = 'pending';
			$state['error']  = '';
			update_option( self::STATE_OPTION, $state, false );
		}

		return $result;
	}

	/**
	 * Build and atomically activate one complete replacement generation.
	 *
	 * @return true|\WP_Error
	 */
	public static function rebuild() {
		Schema::maybe_upgrade();
		self::ensure_state_option();

		$lock = self::acquire_lock();
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$generation = wp_generate_uuid4();
		$started_at = current_time( 'mysql', true );

		try {
			self::discard_abandoned_generation();
			self::write_state(
				array(
					'status'      => 'running',
					'generation'  => $generation,
					'started_at'  => $started_at,
					'finished_at' => '',
					'cursor'      => 0,
					'sources'     => 0,
					'edges'       => 0,
					'error'       => '',
				)
			);

			self::build_generation( $generation );
			self::activate_generation( $generation, $started_at );
			self::delete_other_generations( $generation );
			self::finish_rebuild_request();

			return true;
		} catch ( \Throwable $error ) {
			self::write_state(
				array(
					'status'      => 'failed',
					'generation'  => (string) get_option( Schema::GRAPH_GENERATION_OPTION, '' ),
					'started_at'  => $started_at,
					'finished_at' => current_time( 'mysql', true ),
					'cursor'      => 0,
					'sources'     => self::active_source_count(),
					'edges'       => self::active_edge_count(),
					'error'       => sanitize_text_field( $error->getMessage() ),
				)
			);
			self::delete_generation( $generation );
			self::finish_rebuild_request();

			return new \WP_Error( 'intertexere_graph_rebuild_failed', $error->getMessage() );
		}
	}

	/**
	 * Refresh one source after WordPress has committed its saved state.
	 */
	public static function handle_post_saved( int $post_id, \WP_Post $post ): void {
		if ( 'revision' !== $post->post_type ) {
			self::refresh_post( $post_id );
		}
	}

	/**
	 * Mark a deleted source removed in every generation that may become active.
	 */
	public static function handle_post_deleted( int $post_id ): void {
		self::remove_post( $post_id );
	}

	/**
	 * Atomically refresh a source in the active and running replacement generations.
	 */
	public static function refresh_post( int $post_id ): bool {
		Schema::maybe_upgrade();
		self::ensure_state_option();
		self::begin_transaction();

		try {
			$state       = self::lock_state_row();
			$generations = self::writable_generations_locked( $state );
			clean_post_cache( $post_id );
			$post     = get_post( $post_id );
			$eligible = Eligibility::is_eligible( $post );

			foreach ( $generations as $generation ) {
				self::replace_source_in_transaction( $eligible ? $post : null, $post_id, $generation, true );
			}

			self::commit_transaction();
			return $eligible;
		} catch ( \Throwable $error ) {
			self::rollback_transaction();
			return false;
		}
	}

	/**
	 * Atomically remove a source from every generation that may become active.
	 */
	public static function remove_post( int $post_id ): void {
		Schema::maybe_upgrade();
		self::ensure_state_option();
		self::begin_transaction();

		try {
			$state = self::lock_state_row();
			foreach ( self::writable_generations_locked( $state ) as $generation ) {
				self::replace_source_in_transaction( null, $post_id, $generation, true );
			}
			self::commit_transaction();
		} catch ( \Throwable $error ) {
			self::rollback_transaction();
		}
	}

	/**
	 * Clear only graph-derived data and activate a valid empty generation.
	 *
	 * @return true|\WP_Error
	 */
	public static function reset() {
		global $wpdb;

		Schema::maybe_upgrade();
		self::ensure_state_option();
		$lock = self::acquire_lock();
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$generation = wp_generate_uuid4();
		wp_clear_scheduled_hook( self::REBUILD_HOOK );
		delete_option( self::RERUN_OPTION );
		self::begin_transaction();

		try {
			self::lock_state_row();
			if ( false === $wpdb->query( 'DELETE FROM ' . Schema::link_edges_table_name() )
				|| false === $wpdb->query( 'DELETE FROM ' . Schema::graph_sources_table_name() ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				throw new \RuntimeException( 'Unable to clear graph-derived data.' );
			}

			self::set_option_value( Schema::GRAPH_GENERATION_OPTION, $generation );
			self::set_option_value(
				self::STATE_OPTION,
				array(
					'status'      => 'empty',
					'generation'  => $generation,
					'started_at'  => '',
					'finished_at' => current_time( 'mysql', true ),
					'cursor'      => 0,
					'sources'     => 0,
					'edges'       => 0,
					'error'       => '',
				)
			);
			self::commit_transaction();
			self::clear_option_caches();
			delete_option( self::LOCK_OPTION );

			return true;
		} catch ( \Throwable $error ) {
			self::rollback_transaction();
			delete_option( self::LOCK_OPTION );

			return new \WP_Error( 'intertexere_graph_reset_failed', $error->getMessage() );
		}
	}

	/**
	 * Get active outbound relationships for one eligible source.
	 *
	 * By default this returns only relationships to currently eligible targets
	 * and excludes self-links. Set $active_only false to inspect inactive and
	 * unresolved observed relationships.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function outbound( int $source_post_id, bool $include_self = false, bool $active_only = true ): array {
		global $wpdb;

		if ( ! Eligibility::is_eligible( $source_post_id ) ) {
			return array();
		}

		$generation = (string) get_option( Schema::GRAPH_GENERATION_OPTION, '' );
		$rows       = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT e.* FROM ' . Schema::link_edges_table_name() . ' e
				INNER JOIN ' . Schema::graph_sources_table_name() . ' s
				ON s.generation = e.generation AND s.source_post_id = e.source_post_id
				WHERE e.generation = %s AND e.source_post_id = %d AND s.source_state = %s
				ORDER BY e.target_identity_hash ASC',
				$generation,
				$source_post_id,
				self::SOURCE_READY
			),
			ARRAY_A
		);

		return self::hydrate_edges( is_array( $rows ) ? $rows : array(), $include_self, $active_only );
	}

	/**
	 * Get unresolved internal URL evidence for one source.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function unresolved( int $source_post_id ): array {
		return array_values(
			array_filter(
				self::outbound( $source_post_id, true, false ),
				static function ( array $edge ): bool {
					return null === $edge['target_post_id'];
				}
			)
		);
	}

	/**
	 * Derive active inbound relationships from the authoritative edge rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function inbound( int $target_post_id, bool $include_self = false ): array {
		global $wpdb;

		if ( ! Eligibility::is_eligible( $target_post_id ) ) {
			return array();
		}

		$generation = (string) get_option( Schema::GRAPH_GENERATION_OPTION, '' );
		$rows       = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT e.* FROM ' . Schema::link_edges_table_name() . ' e
				INNER JOIN ' . Schema::graph_sources_table_name() . ' s
				ON s.generation = e.generation AND s.source_post_id = e.source_post_id
				WHERE e.generation = %s AND e.target_post_id = %d AND s.source_state = %s
				ORDER BY e.source_post_id ASC',
				$generation,
				$target_post_id,
				self::SOURCE_READY
			),
			ARRAY_A
		);

		$active = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! $include_self && ! empty( $row['is_self'] ) ) {
				continue;
			}
			if ( ! Eligibility::is_eligible( (int) $row['source_post_id'] ) ) {
				continue;
			}

			$row['source_title']     = get_the_title( (int) $row['source_post_id'] );
			$row['source_permalink'] = (string) get_permalink( (int) $row['source_post_id'] );
			$row['target_title']     = get_the_title( $target_post_id );
			$row['target_permalink'] = (string) get_permalink( $target_post_id );
			$row['target_active']    = true;
			$active[]                = self::normalize_edge_types( $row );
		}

		return $active;
	}

	/**
	 * Basic graph diagnostics for administrators and tests.
	 *
	 * @return array<string, mixed>
	 */
	public static function diagnostics(): array {
		global $wpdb;

		$sources_table = Schema::graph_sources_table_name();
		$edges_table   = Schema::link_edges_table_name();

		return array(
			'schema_version'    => (string) get_option( Schema::VERSION_OPTION, '' ),
			'active_generation' => (string) get_option( Schema::GRAPH_GENERATION_OPTION, '' ),
			'active_sources'    => self::active_source_count(),
			'observed_edges'    => self::active_edge_count(),
			'total_source_rows' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sources_table}" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'total_edge_rows'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$edges_table}" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'unresolved_edges'  => self::count_active_edges_where( 'target_post_id IS NULL' ),
			'self_edges'        => self::count_active_edges_where( 'is_self = 1' ),
			'state'             => self::state(),
			'rebuild_scheduled' => (bool) wp_next_scheduled( self::REBUILD_HOOK ),
		);
	}

	/**
	 * Return normalized graph rebuild state.
	 *
	 * @return array<string, mixed>
	 */
	public static function state(): array {
		$state = get_option( self::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();

		return self::normalize_state( $state );
	}

	private static function build_generation( string $generation ): void {
		global $wpdb;

		$last_id       = 0;
		$post_types    = Eligibility::post_types();
		$post_statuses = Eligibility::post_statuses();
		if ( empty( $post_types ) || empty( $post_statuses ) ) {
			return;
		}

		$type_placeholders   = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$status_placeholders = implode( ', ', array_fill( 0, count( $post_statuses ), '%s' ) );

		while ( true ) {
			$args = array_merge( array( $last_id ), $post_types, $post_statuses, array( self::BATCH_SIZE ) );
			$sql  = $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE ID > %d
				AND post_type IN ({$type_placeholders})
				AND post_status IN ({$status_placeholders})
				AND post_password = ''
				ORDER BY ID ASC
				LIMIT %d",
				$args
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = array_map( 'intval', $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if ( empty( $ids ) ) {
				break;
			}

			foreach ( $ids as $post_id ) {
				clean_post_cache( $post_id );
				$post = get_post( $post_id );
				if ( Eligibility::is_eligible( $post ) ) {
					self::write_rebuild_source( $post, $generation );
				}
			}

			$last_id = (int) end( $ids );
			self::update_running_cursor( $generation, $last_id );

			/**
			 * Fires after a real rebuild batch commits, primarily for observability
			 * and race-order integration tests.
			 */
			do_action( 'intertexere_graph_rebuild_batch_completed', $generation, $last_id, count( $ids ) );

			if ( self::BATCH_SIZE > count( $ids ) ) {
				break;
			}
		}
	}

	private static function write_rebuild_source( \WP_Post $post, string $generation ): void {
		self::begin_transaction();

		try {
			self::replace_source_in_transaction( $post, (int) $post->ID, $generation, false );
			self::commit_transaction();
		} catch ( \Throwable $error ) {
			self::rollback_transaction();
			throw $error;
		}
	}

	/**
	 * Replace one complete source edge set. The caller owns the transaction.
	 */
	private static function replace_source_in_transaction( ?\WP_Post $post, int $post_id, string $generation, bool $overwrite ): void {
		global $wpdb;

		$ready = $post instanceof \WP_Post && Eligibility::is_eligible( $post );
		$edges = $ready ? self::derive_edges( $post ) : array();
		$hash  = $ready ? self::source_hash( $post ) : hash( 'sha256', 'removed:' . $post_id );
		$data  = array(
			'generation'         => $generation,
			'source_post_id'     => $post_id,
			'source_content_hash'=> $hash,
			'source_state'       => $ready ? self::SOURCE_READY : self::SOURCE_REMOVED,
			'indexed_at_gmt'     => current_time( 'mysql', true ),
		);

		if ( $overwrite ) {
			$result = $wpdb->replace(
				Schema::graph_sources_table_name(),
				$data,
				array( '%s', '%d', '%s', '%s', '%s' )
			);
		} else {
			$suppress = $wpdb->suppress_errors();
			$result   = $wpdb->insert(
				Schema::graph_sources_table_name(),
				$data,
				array( '%s', '%d', '%s', '%s', '%s' )
			);
			$wpdb->suppress_errors( $suppress );

			if ( false === $result && self::source_marker_exists( $generation, $post_id ) ) {
				return;
			}
		}

		if ( false === $result ) {
			throw new \RuntimeException( 'Unable to write graph source state for post ' . $post_id . '.' );
		}

		if ( false === $wpdb->delete(
			Schema::link_edges_table_name(),
			array(
				'generation'     => $generation,
				'source_post_id' => $post_id,
			),
			array( '%s', '%d' )
		) ) {
			throw new \RuntimeException( 'Unable to replace graph edges for post ' . $post_id . '.' );
		}

		foreach ( $edges as $edge ) {
			$inserted = $wpdb->insert(
				Schema::link_edges_table_name(),
				array(
					'generation'          => $generation,
					'source_post_id'      => $post_id,
					'target_identity_hash'=> $edge['target_identity_hash'],
					'target_post_id'      => $edge['target_post_id'],
					'normalized_url'      => $edge['normalized_url'],
					'occurrence_count'    => $edge['occurrence_count'],
					'is_self'             => $edge['is_self'],
					'indexed_at_gmt'      => current_time( 'mysql', true ),
				),
				array( '%s', '%d', '%s', '%d', '%s', '%d', '%d', '%s' )
			);

			if ( false === $inserted ) {
				throw new \RuntimeException( 'Unable to write a graph edge for post ' . $post_id . '.' );
			}
		}
	}

	/**
	 * Parse literal saved anchor tags and aggregate duplicate target identities.
	 *
	 * @return array<int, array{normalized_url:string,target_post_id:?int,target_identity_hash:string,is_self:int,occurrence_count:int}>
	 */
	private static function derive_edges( \WP_Post $post ): array {
		$processor = new \WP_HTML_Tag_Processor( (string) $post->post_content );
		$edges     = array();

		while ( $processor->next_tag( 'A' ) ) {
			if ( $processor->is_tag_closer() ) {
				continue;
			}

			$href = $processor->get_attribute( 'href' );
			if ( ! is_string( $href ) ) {
				continue;
			}

			$resolved = Link_Resolver::resolve( $href, $post );
			if ( null === $resolved ) {
				continue;
			}

			$key = $resolved['target_identity_hash'];
			if ( isset( $edges[ $key ] ) ) {
				++$edges[ $key ]['occurrence_count'];
				continue;
			}

			$resolved['occurrence_count'] = 1;
			$edges[ $key ]                = $resolved;
		}

		ksort( $edges );

		return array_values( $edges );
	}

	private static function source_hash( \WP_Post $post ): string {
		return hash(
			'sha256',
			(string) wp_json_encode(
				array(
					'post_id'          => (int) $post->ID,
					'post_type'        => (string) $post->post_type,
					'post_status'      => (string) $post->post_status,
					'post_password'    => (string) $post->post_password,
					'post_content'     => (string) $post->post_content,
					'source_permalink' => (string) get_permalink( $post ),
					'home_url'         => home_url( '/' ),
					'site_url'         => site_url( '/' ),
				)
			)
		);
	}

	/**
	 * Add current target metadata without treating stored URLs as canonical.
	 *
	 * @param array<int, array<string, mixed>> $rows Edge rows.
	 * @return array<int, array<string, mixed>>
	 */
	private static function hydrate_edges( array $rows, bool $include_self, bool $active_only ): array {
		$hydrated = array();

		foreach ( $rows as $row ) {
			if ( ! $include_self && ! empty( $row['is_self'] ) ) {
				continue;
			}

			$target_id     = isset( $row['target_post_id'] ) ? (int) $row['target_post_id'] : 0;
			$target        = $target_id > 0 ? get_post( $target_id ) : null;
			$target_active = $target instanceof \WP_Post && Eligibility::is_eligible( $target );
			if ( $active_only && ! $target_active ) {
				continue;
			}

			$row['target_title']     = $target instanceof \WP_Post ? get_the_title( $target ) : '';
			$row['target_permalink'] = $target instanceof \WP_Post ? (string) get_permalink( $target ) : '';
			$row['target_active']    = $target_active;
			$hydrated[]              = self::normalize_edge_types( $row );
		}

		return $hydrated;
	}

	/**
	 * Normalize database scalar types at the read boundary.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	private static function normalize_edge_types( array $row ): array {
		$row['source_post_id']   = (int) $row['source_post_id'];
		$row['target_post_id']   = empty( $row['target_post_id'] ) ? null : (int) $row['target_post_id'];
		$row['occurrence_count'] = (int) $row['occurrence_count'];
		$row['is_self']          = (bool) $row['is_self'];
		$row['target_active']    = (bool) $row['target_active'];

		return $row;
	}

	private static function activate_generation( string $generation, string $started_at ): void {
		self::begin_transaction();

		try {
			self::lock_state_row();
			self::set_option_value( Schema::GRAPH_GENERATION_OPTION, $generation );
			self::set_option_value(
				self::STATE_OPTION,
				array(
					'status'      => 'ready',
					'generation'  => $generation,
					'started_at'  => $started_at,
					'finished_at' => current_time( 'mysql', true ),
					'cursor'      => 0,
					'sources'     => self::count_sources( $generation ),
					'edges'       => self::count_edges( $generation ),
					'error'       => '',
				)
			);
			self::commit_transaction();
			self::clear_option_caches();
		} catch ( \Throwable $error ) {
			self::rollback_transaction();
			throw $error;
		}
	}

	private static function update_running_cursor( string $generation, int $cursor ): void {
		self::begin_transaction();

		try {
			$state = self::lock_state_row();
			if ( 'running' === $state['status'] && $generation === (string) $state['generation'] ) {
				$state['cursor']  = $cursor;
				$state['sources'] = self::count_sources( $generation );
				$state['edges']   = self::count_edges( $generation );
				self::set_option_value( self::STATE_OPTION, $state );
			}
			self::commit_transaction();
			wp_cache_delete( self::STATE_OPTION, 'options' );
		} catch ( \Throwable $error ) {
			self::rollback_transaction();
			throw $error;
		}
	}

	/**
	 * Write state while serializing against incremental generation selection.
	 *
	 * @param array<string, mixed> $state State record.
	 */
	private static function write_state( array $state ): void {
		self::begin_transaction();

		try {
			self::lock_state_row();
			self::set_option_value( self::STATE_OPTION, self::normalize_state( $state ) );
			self::commit_transaction();
			wp_cache_delete( self::STATE_OPTION, 'options' );
		} catch ( \Throwable $error ) {
			self::rollback_transaction();
			throw $error;
		}
	}

	/**
	 * Lock and return state from the database, bypassing option cache races.
	 *
	 * @return array<string, mixed>
	 */
	private static function lock_state_row(): array {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s FOR UPDATE",
				self::STATE_OPTION
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$state = maybe_unserialize( $value );

		return self::normalize_state( is_array( $state ) ? $state : array() );
	}

	/**
	 * Read active and replacement generation IDs while the state row is locked.
	 *
	 * @param array<string, mixed> $state Locked graph state.
	 * @return string[]
	 */
	private static function writable_generations_locked( array $state ): array {
		$active      = (string) self::get_option_value( Schema::GRAPH_GENERATION_OPTION, '' );
		$generations = array_filter( array( $active ) );

		if ( 'running' === $state['status'] && ! empty( $state['generation'] ) ) {
			$generations[] = (string) $state['generation'];
		}

		return array_values( array_unique( $generations ) );
	}

	private static function ensure_state_option(): void {
		add_option( self::STATE_OPTION, self::normalize_state( array() ), '', false );
	}

	/**
	 * @param array<string, mixed> $state State record.
	 * @return array<string, mixed>
	 */
	private static function normalize_state( array $state ): array {
		return wp_parse_args(
			$state,
			array(
				'status'      => 'empty',
				'generation'  => (string) get_option( Schema::GRAPH_GENERATION_OPTION, '' ),
				'started_at'  => '',
				'finished_at' => '',
				'cursor'      => 0,
				'sources'     => 0,
				'edges'       => 0,
				'error'       => '',
			)
		);
	}

	private static function set_option_value( string $name, $value ): void {
		global $wpdb;

		$result = $wpdb->update(
			$wpdb->options,
			array( 'option_value' => maybe_serialize( $value ) ),
			array( 'option_name' => $name ),
			array( '%s' ),
			array( '%s' )
		);

		if ( false === $result ) {
			throw new \RuntimeException( 'Unable to update graph state option ' . $name . '.' );
		}
	}

	private static function get_option_value( string $name, $default = false ) {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				$name
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return null === $value ? $default : maybe_unserialize( $value );
	}

	private static function clear_option_caches(): void {
		wp_cache_delete( Schema::GRAPH_GENERATION_OPTION, 'options' );
		wp_cache_delete( self::STATE_OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	private static function begin_transaction(): void {
		global $wpdb;

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw new \RuntimeException( 'Unable to start a graph database transaction.' );
		}
	}

	private static function commit_transaction(): void {
		global $wpdb;

		if ( false === $wpdb->query( 'COMMIT' ) ) {
			throw new \RuntimeException( 'Unable to commit a graph database transaction.' );
		}
	}

	private static function rollback_transaction(): void {
		global $wpdb;

		$wpdb->query( 'ROLLBACK' );
	}

	private static function source_marker_exists( string $generation, int $post_id ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM ' . Schema::graph_sources_table_name() . ' WHERE generation = %s AND source_post_id = %d',
				$generation,
				$post_id
			)
		);
	}

	private static function active_source_count(): int {
		$generation = (string) get_option( Schema::GRAPH_GENERATION_OPTION, '' );

		return '' === $generation ? 0 : self::count_sources( $generation );
	}

	private static function active_edge_count(): int {
		$generation = (string) get_option( Schema::GRAPH_GENERATION_OPTION, '' );

		return '' === $generation ? 0 : self::count_edges( $generation );
	}

	private static function count_sources( string $generation ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Schema::graph_sources_table_name() . ' WHERE generation = %s AND source_state = %s',
				$generation,
				self::SOURCE_READY
			)
		);
	}

	private static function count_edges( string $generation ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Schema::link_edges_table_name() . ' WHERE generation = %s',
				$generation
			)
		);
	}

	private static function count_active_edges_where( string $where ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Schema::link_edges_table_name() . ' WHERE generation = %s AND ' . $where,
				(string) get_option( Schema::GRAPH_GENERATION_OPTION, '' )
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function has_live_rebuild_lock(): bool {
		$locked_at = (int) get_option( self::LOCK_OPTION, 0 );

		return $locked_at > 0 && ( time() - $locked_at ) <= self::LOCK_TTL;
	}

	/**
	 * @return true|\WP_Error
	 */
	private static function acquire_lock() {
		$now = time();
		if ( add_option( self::LOCK_OPTION, $now, '', false ) ) {
			return true;
		}

		$locked_at = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $locked_at && ( $now - $locked_at ) > self::LOCK_TTL ) {
			delete_option( self::LOCK_OPTION );
			if ( add_option( self::LOCK_OPTION, $now, '', false ) ) {
				return true;
			}
		}

		return new \WP_Error( 'intertexere_graph_rebuild_locked', 'An Intertexere graph rebuild is already running.' );
	}

	private static function finish_rebuild_request(): void {
		delete_option( self::LOCK_OPTION );

		if ( delete_option( self::RERUN_OPTION ) ) {
			self::request_rebuild();
		}
	}

	private static function discard_abandoned_generation(): void {
		$state  = self::state();
		$active = (string) get_option( Schema::GRAPH_GENERATION_OPTION, '' );

		if ( ! empty( $state['generation'] ) && $active !== (string) $state['generation'] ) {
			self::delete_generation( (string) $state['generation'] );
		}
	}

	private static function delete_generation( string $generation ): void {
		global $wpdb;

		$wpdb->delete( Schema::link_edges_table_name(), array( 'generation' => $generation ), array( '%s' ) );
		$wpdb->delete( Schema::graph_sources_table_name(), array( 'generation' => $generation ), array( '%s' ) );
	}

	private static function delete_other_generations( string $generation ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . Schema::link_edges_table_name() . ' WHERE generation <> %s',
				$generation
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . Schema::graph_sources_table_name() . ' WHERE generation <> %s',
				$generation
			)
		);
	}
}
