<?php
/**
 * Rebuildable derived content index.
 *
 * @package Intertexere
 */

namespace Intertexere;

final class Indexer {
	public const REBUILD_HOOK = 'intertexere_rebuild_index';
	public const STATE_OPTION = 'intertexere_index_state';
	public const LOCK_OPTION = 'intertexere_rebuild_lock';
	public const RERUN_OPTION = 'intertexere_rebuild_rerun_requested';
	private const BATCH_SIZE = 100;
	private const LOCK_TTL = 3600;
	private const TOMBSTONE_STATUS = '__removed';

	/**
	 * Queue a rebuild away from ordinary editor and front-end requests.
	 *
	 * @return bool|\WP_Error
	 */
	public static function request_rebuild() {
		if ( self::has_live_rebuild_lock() ) {
			update_option( self::RERUN_OPTION, 1, false );

			// Close the race where the active rebuild finishes between the first
			// lock check and recording the follow-up request.
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
	 * Build a complete generation and switch to it only after success.
	 *
	 * @return true|\WP_Error
	 */
	public static function rebuild() {
		Schema::maybe_upgrade();

		$lock = self::acquire_lock();
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$generation = wp_generate_uuid4();
		$started_at = current_time( 'mysql', true );

		self::discard_abandoned_generation();
		update_option(
			self::STATE_OPTION,
			array(
				'status'     => 'running',
				'generation' => $generation,
				'started_at' => $started_at,
				'finished_at'=> '',
				'indexed'    => 0,
				'error'      => '',
			),
			false
		);

		try {
			self::build_generation( $generation );

			// This option update is the index cutover. Until it succeeds, readers
			// continue using the prior complete generation.
			if ( ! update_option( Schema::GENERATION_OPTION, $generation, false )
				&& $generation !== get_option( Schema::GENERATION_OPTION ) ) {
				throw new \RuntimeException( 'Unable to activate the rebuilt index generation.' );
			}

			$count = self::count_generation( $generation );

			update_option(
				self::STATE_OPTION,
				array(
					'status'      => 'ready',
					'generation'  => $generation,
					'started_at'  => $started_at,
					'finished_at' => current_time( 'mysql', true ),
					'indexed'     => $count,
					'error'       => '',
				),
				false
			);

			self::delete_other_generations( $generation );
			self::delete_tombstones( $generation );
			self::finish_rebuild_request();

			return true;
		} catch ( \Throwable $error ) {
			update_option(
				self::STATE_OPTION,
				array(
					'status'      => 'failed',
					'generation'  => get_option( Schema::GENERATION_OPTION, '' ),
					'started_at'  => $started_at,
					'finished_at' => current_time( 'mysql', true ),
					'indexed'     => self::active_count(),
					'error'       => sanitize_text_field( $error->getMessage() ),
				),
				false
			);
			self::delete_generation( $generation );
			self::finish_rebuild_request();

			return new \WP_Error( 'intertexere_rebuild_failed', $error->getMessage() );
		}
	}

	/**
	 * Refresh or remove one post after WordPress has saved it and its terms.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Saved post.
	 */
	public static function handle_post_saved( int $post_id, \WP_Post $post ): void {
		if ( 'revision' === $post->post_type ) {
			return;
		}

		self::refresh_post( $post_id );
	}

	/**
	 * Remove all derived rows when WordPress deletes a post.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function handle_post_deleted( int $post_id ): void {
		self::remove_post( $post_id );
	}

	/**
	 * Refresh one post in every generation that may become active.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True when indexed, false when removed as ineligible.
	 */
	public static function refresh_post( int $post_id ): bool {
		$post = get_post( $post_id );

		if ( ! Eligibility::is_eligible( $post ) ) {
			self::remove_post( $post_id );
			return false;
		}

		foreach ( self::writable_generations() as $generation ) {
			self::upsert( $post, $generation );
		}

		return true;
	}

	/**
	 * Delete derived data for a post without touching WordPress content.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function remove_post( int $post_id ): void {
		global $wpdb;

		$wpdb->delete( Schema::table_name(), array( 'post_id' => $post_id ), array( '%d' ) );

		$state      = self::state();
		$generation = 'running' === $state['status'] ? (string) $state['generation'] : '';
		$active     = (string) get_option( Schema::GENERATION_OPTION, '' );

		if ( '' === $generation || $generation === $active ) {
			return;
		}

		self::write_tombstone( $post_id, $generation );

		// If completion raced the tombstone write, no traversal write can now
		// resurrect this post, so the temporary marker is no longer needed.
		$current_state = self::state();
		if ( $generation === (string) get_option( Schema::GENERATION_OPTION, '' )
			|| 'running' !== $current_state['status']
			|| $generation !== (string) $current_state['generation'] ) {
			self::delete_tombstone( $post_id, $generation );
		}
	}

	/**
	 * Clear derived data and create a valid empty generation.
	 *
	 * WordPress posts and plugin settings are intentionally untouched.
	 */
	public static function reset(): void {
		global $wpdb;

		Schema::maybe_upgrade();
		$wpdb->query( 'TRUNCATE TABLE ' . Schema::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$generation = wp_generate_uuid4();
		update_option( Schema::GENERATION_OPTION, $generation, false );
		update_option(
			self::STATE_OPTION,
			array(
				'status'      => 'empty',
				'generation'  => $generation,
				'started_at'  => '',
				'finished_at' => current_time( 'mysql', true ),
				'indexed'     => 0,
				'error'       => '',
			),
			false
		);
	}

	/**
	 * Basic index diagnostics for administrators and tests.
	 *
	 * @return array<string, mixed>
	 */
	public static function diagnostics(): array {
		global $wpdb;

		$generation = (string) get_option( Schema::GENERATION_OPTION, '' );
		$table      = Schema::table_name();
		$total      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'schema_version'    => (string) get_option( Schema::VERSION_OPTION, '' ),
			'active_generation' => $generation,
			'active_records'    => self::active_count(),
			'total_records'     => $total,
			'state'             => self::state(),
			'post_types'        => Eligibility::post_types(),
			'post_statuses'     => Eligibility::post_statuses(),
			'rebuild_scheduled' => (bool) wp_next_scheduled( self::REBUILD_HOOK ),
		);
	}

	/**
	 * Count records in the active generation.
	 */
	public static function active_count(): int {
		global $wpdb;

		$generation = (string) get_option( Schema::GENERATION_OPTION, '' );
		if ( '' === $generation ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Schema::table_name() . ' WHERE generation = %s AND post_status <> %s',
				$generation,
				self::TOMBSTONE_STATUS
			)
		);
	}

	/**
	 * Return one active derived record for tests and later milestones.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>|null
	 */
	public static function get_record( int $post_id ): ?array {
		global $wpdb;

		$record = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table_name() . ' WHERE generation = %s AND post_id = %d AND post_status <> %s',
				(string) get_option( Schema::GENERATION_OPTION, '' ),
				$post_id,
				self::TOMBSTONE_STATUS
			),
			ARRAY_A
		);

		return is_array( $record ) ? $record : null;
	}

	/**
	 * Build one isolated generation.
	 *
	 * @throws \RuntimeException When a database write fails.
	 */
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
			$query_args = array_merge(
				array( $last_id ),
				$post_types,
				$post_statuses,
				array( self::BATCH_SIZE )
			);
			$sql        = $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE ID > %d
				AND post_type IN ({$type_placeholders})
				AND post_status IN ({$status_placeholders})
				AND post_password = ''
				ORDER BY ID ASC
				LIMIT %d",
				$query_args
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids        = array_map( 'intval', $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if ( empty( $ids ) ) {
				break;
			}

			foreach ( $ids as $post_id ) {
				$post = get_post( $post_id );
				if ( Eligibility::is_eligible( $post ) ) {
					self::upsert( $post, $generation, false );
				}
			}

			$last_id = (int) end( $ids );

			if ( self::BATCH_SIZE > count( $ids ) ) {
				break;
			}
		}
	}

	/**
	 * Store the derived representation for one post.
	 *
	 * @throws \RuntimeException When the database write fails.
	 */
	private static function upsert( \WP_Post $post, string $generation, bool $overwrite = true ): void {
		global $wpdb;

		$derived = self::derive( $post );
		$data    = array(
			'post_id'            => $post->ID,
			'generation'         => $generation,
			'post_type'          => $post->post_type,
			'post_status'        => $post->post_status,
			'post_modified_gmt'  => '0000-00-00 00:00:00' === $post->post_modified_gmt ? current_time( 'mysql', true ) : $post->post_modified_gmt,
			'permalink'          => $derived['permalink'],
			'title'              => $derived['title'],
			'excerpt'            => $derived['excerpt'],
			'normalized_content' => $derived['content'],
			'headings'           => wp_json_encode( $derived['headings'] ),
			'taxonomies'         => wp_json_encode( $derived['taxonomies'] ),
			'content_hash'       => $derived['hash'],
			'indexed_at_gmt'     => current_time( 'mysql', true ),
		);
		$formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		if ( $overwrite ) {
			$result = $wpdb->replace( Schema::table_name(), $data, $formats );
		} else {
			$suppress_errors = $wpdb->suppress_errors();
			$result          = $wpdb->insert( Schema::table_name(), $data, $formats );
			$wpdb->suppress_errors( $suppress_errors );

			if ( false === $result && self::generation_has_post( $generation, $post->ID ) ) {
				return;
			}
		}

		if ( false === $result ) {
			throw new \RuntimeException( 'Unable to write the derived index record for post ' . $post->ID . '.' );
		}
	}

	/**
	 * Create a deterministic, non-executable representation of post data.
	 *
	 * @return array{permalink:string,title:string,excerpt:string,content:string,headings:string[],taxonomies:array<string,array<int,array<string,mixed>>>,hash:string}
	 */
	private static function derive( \WP_Post $post ): array {
		$content    = self::normalize_text( strip_shortcodes( $post->post_content ) );
		$title      = self::normalize_text( $post->post_title );
		$excerpt    = self::normalize_text( $post->post_excerpt );
		$headings   = self::extract_headings( $post->post_content );
		$taxonomies = self::extract_taxonomies( $post );
		$permalink  = (string) get_permalink( $post );

		if ( '' === $excerpt ) {
			$excerpt = wp_trim_words( $content, 55, '' );
		}

		$hash_source = array(
			'post_id'       => $post->ID,
			'post_type'     => $post->post_type,
			'post_status'   => $post->post_status,
			'permalink'     => $permalink,
			'title'         => $title,
			'excerpt'       => $excerpt,
			'content'       => $content,
			'headings'      => $headings,
			'taxonomies'    => $taxonomies,
		);

		return array(
			'permalink' => $permalink,
			'title'      => $title,
			'excerpt'    => $excerpt,
			'content'    => $content,
			'headings'   => $headings,
			'taxonomies' => $taxonomies,
			'hash'       => hash( 'sha256', (string) wp_json_encode( $hash_source ) ),
		);
	}

	/**
	 * Normalize content to safe searchable text without executing it.
	 */
	private static function normalize_text( string $text ): string {
		$text = html_entity_decode( wp_strip_all_tags( $text, true ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );

		return trim( is_string( $text ) ? $text : '' );
	}

	/**
	 * Extract saved heading markup as plain text.
	 *
	 * @return string[]
	 */
	private static function extract_headings( string $content ): array {
		if ( ! preg_match_all( '/<h[1-6]\b[^>]*>(.*?)<\/h[1-6]>/is', $content, $matches ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( array( self::class, 'normalize_text' ), $matches[1] )
			)
		);
	}

	/**
	 * Extract stable term identifiers and labels for deterministic retrieval.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function extract_taxonomies( \WP_Post $post ): array {
		$indexed = array();

		foreach ( get_object_taxonomies( $post->post_type, 'names' ) as $taxonomy ) {
			$terms = wp_get_object_terms( $post->ID, $taxonomy );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			$indexed[ $taxonomy ] = array_map(
				static function ( \WP_Term $term ): array {
					return array(
						'id'   => $term->term_id,
						'name' => $term->name,
						'slug' => $term->slug,
					);
				},
				$terms
			);
		}

		ksort( $indexed );

		return $indexed;
	}

	/**
	 * Current complete generation plus an in-progress replacement generation.
	 *
	 * @return string[]
	 */
	private static function writable_generations(): array {
		$generations = array_filter( array( (string) get_option( Schema::GENERATION_OPTION, '' ) ) );
		$state       = self::state();

		if ( 'running' === $state['status'] && ! empty( $state['generation'] ) ) {
			$generations[] = (string) $state['generation'];
		}

		return array_values( array_unique( $generations ) );
	}

	/**
	 * Get a normalized state record.
	 *
	 * @return array<string, mixed>
	 */
	private static function state(): array {
		$state = get_option( self::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();

		return wp_parse_args(
			$state,
			array(
				'status'      => 'empty',
				'generation'  => (string) get_option( Schema::GENERATION_OPTION, '' ),
				'started_at'  => '',
				'finished_at' => '',
				'indexed'     => 0,
				'error'       => '',
			)
		);
	}

	/**
	 * Determine whether a rebuild lock still represents active work.
	 */
	private static function has_live_rebuild_lock(): bool {
		$locked_at = (int) get_option( self::LOCK_OPTION, 0 );

		return $locked_at > 0 && ( time() - $locked_at ) <= self::LOCK_TTL;
	}

	/**
	 * Release the current rebuild and schedule one coalesced follow-up request.
	 */
	private static function finish_rebuild_request(): void {
		delete_option( self::LOCK_OPTION );

		if ( delete_option( self::RERUN_OPTION ) ) {
			self::request_rebuild();
		}
	}

	/**
	 * Count records in one generation after concurrent lifecycle updates settle.
	 */
	private static function count_generation( string $generation ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Schema::table_name() . ' WHERE generation = %s AND post_status <> %s',
				$generation,
				self::TOMBSTONE_STATUS
			)
		);
	}

	/**
	 * Record an incremental removal so an older traversal snapshot cannot win.
	 */
	private static function write_tombstone( int $post_id, string $generation ): void {
		global $wpdb;

		$now = current_time( 'mysql', true );
		$wpdb->replace(
			Schema::table_name(),
			array(
				'post_id'            => $post_id,
				'generation'         => $generation,
				'post_type'          => '',
				'post_status'        => self::TOMBSTONE_STATUS,
				'post_modified_gmt'  => $now,
				'permalink'          => '',
				'title'              => '',
				'excerpt'            => '',
				'normalized_content' => '',
				'headings'           => '[]',
				'taxonomies'         => '[]',
				'content_hash'       => hash( 'sha256', '' ),
				'indexed_at_gmt'     => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	private static function generation_has_post( string $generation, int $post_id ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM ' . Schema::table_name() . ' WHERE generation = %s AND post_id = %d',
				$generation,
				$post_id
			)
		);
	}

	private static function delete_tombstone( int $post_id, string $generation ): void {
		global $wpdb;

		$wpdb->delete(
			Schema::table_name(),
			array(
				'post_id'     => $post_id,
				'generation'  => $generation,
				'post_status' => self::TOMBSTONE_STATUS,
			),
			array( '%d', '%s', '%s' )
		);
	}

	private static function delete_tombstones( string $generation ): void {
		global $wpdb;

		$wpdb->delete(
			Schema::table_name(),
			array(
				'generation'  => $generation,
				'post_status' => self::TOMBSTONE_STATUS,
			),
			array( '%s', '%s' )
		);
	}

	/**
	 * Acquire a recoverable rebuild lock.
	 *
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

		return new \WP_Error( 'intertexere_rebuild_locked', 'An Intertexere index rebuild is already running.' );
	}

	/**
	 * Remove an incomplete generation left by an interrupted prior rebuild.
	 */
	private static function discard_abandoned_generation(): void {
		$state  = self::state();
		$active = (string) get_option( Schema::GENERATION_OPTION, '' );

		if ( ! empty( $state['generation'] ) && $active !== $state['generation'] ) {
			self::delete_generation( (string) $state['generation'] );
		}
	}

	private static function delete_generation( string $generation ): void {
		global $wpdb;

		$wpdb->delete( Schema::table_name(), array( 'generation' => $generation ), array( '%s' ) );
	}

	private static function delete_other_generations( string $generation ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . Schema::table_name() . ' WHERE generation <> %s',
				$generation
			)
		);
	}
}
