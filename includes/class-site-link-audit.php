<?php
/**
 * Request-scoped, read-only site link audit.
 *
 * @package Intertexere
 */

namespace Intertexere;

final class Site_Link_Audit {
	public const CONTRACT_VERSION = 1;
	public const DEFAULT_PAGE_SIZE = 20;
	public const MAX_PAGE_SIZE = 50;
	private const MAX_PERMALINK_ANCESTOR_DEPTH = 32;

	/** @var string[] */
	private const CATEGORIES = array(
		'orphans',
		'thin',
		'unavailable',
		'repeated',
		'self',
		'noncanonical',
	);

	/** @var array<string, mixed> */
	private static array $last_metrics = array();
	private static int $memory_peak_start = 0;
	private static int $memory_usage_start = 0;

	/**
	 * Validate a native wp-admin query without accepting unknown audit fields.
	 *
	 * @param array<string, mixed> $query Query parameters.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function parse_request( array $query ) {
		$allowed = array( 'page', 'category', 'post_type', 'search', 'per_page', 'cursor' );
		foreach ( $query as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				return self::invalid( 'The audit request contains an unknown field.' );
			}
			if ( ! is_string( $value ) ) {
				return self::invalid( 'The audit request contains a non-scalar field.' );
			}
		}
		if ( isset( $query['page'] ) && 'intertexere-site-link-audit' !== wp_unslash( $query['page'] ) ) {
			return self::invalid( 'The audit page identity is invalid.' );
		}

		$category = isset( $query['category'] ) ? sanitize_key( self::scalar( $query['category'] ) ) : '';
		if ( '' !== $category && ! in_array( $category, self::CATEGORIES, true ) ) {
			return self::invalid( 'The audit category is invalid.' );
		}

		$post_type = isset( $query['post_type'] ) ? sanitize_key( self::scalar( $query['post_type'] ) ) : '';
		if ( '' !== $post_type && ! in_array( $post_type, Eligibility::post_types(), true ) ) {
			return self::invalid( 'The audit post type is invalid.' );
		}

		$search = isset( $query['search'] ) ? trim( self::scalar( $query['search'] ) ) : '';
		if ( strlen( $search ) > 64 || ( '' !== $search && 1 !== preg_match( '/^(?:[1-9][0-9]*|[A-Za-z0-9_-]+)$/', $search ) ) ) {
			return self::invalid( 'Search accepts only one exact post ID or slug.' );
		}

		$page_size = self::DEFAULT_PAGE_SIZE;
		if ( isset( $query['per_page'] ) ) {
			$value = self::scalar( $query['per_page'] );
			if ( 1 !== preg_match( '/^[1-9][0-9]*$/', $value ) ) {
				return self::invalid( 'The audit page size is invalid.' );
			}
			$page_size = (int) $value;
			if ( $page_size > self::MAX_PAGE_SIZE ) {
				return self::invalid( 'The audit page size exceeds the limit.' );
			}
		}

		$cursor = null;
		if ( isset( $query['cursor'] ) && '' !== self::scalar( $query['cursor'] ) ) {
			if ( '' === $category ) {
				return self::invalid( 'An overview request cannot carry a page cursor.' );
			}
			$cursor = self::decode_cursor( self::scalar( $query['cursor'] ) );
			if ( is_wp_error( $cursor ) ) {
				return $cursor;
			}
			if ( $category !== $cursor['category'] || $post_type !== $cursor['post_type'] || $search !== $cursor['search'] ) {
				return self::invalid( 'The audit cursor does not match the requested filters.' );
			}
		}

		return array(
			'category'  => $category,
			'post_type' => $post_type,
			'search'    => $search,
			'page_size' => $page_size,
			'cursor'    => $cursor,
		);
	}

	/**
	 * Return one coherent, exact active-generation overview.
	 *
	 * @return array<string, mixed>
	 */
	public static function overview(): array {
		$started = microtime( true );
		$queries = get_num_queries();
		self::$memory_peak_start = memory_get_peak_usage( true );
		self::$memory_usage_start = memory_get_usage( false );
		$generations = self::capture_generations();
		if ( ! current_user_can( 'manage_intertexere' ) ) {
			return self::finish( array( 'status' => 'forbidden', 'generations' => $generations, 'message' => 'You are not allowed to view the site link audit.' ), $started, $queries );
		}

		if ( ! self::has_generations( $generations ) || ! self::tables_are_statement_coherent() ) {
			return self::finish( self::unavailable( $generations, 'The active audit data cannot provide a coherent read.' ), $started, $queries );
		}

		$row = self::overview_row( $generations );
		if ( ! is_array( $row ) ) {
			return self::finish( self::unavailable( $generations, 'The audit overview could not be calculated.' ), $started, $queries );
		}

		/**
		 * Fires after the single overview aggregate statement and before final
		 * generation authority is checked. Tests use this real boundary.
		 */
		do_action( 'intertexere_audit_after_overview_read', $generations, $row );

		if ( $generations !== self::capture_generations() ) {
			return self::finish( self::stale( $generations ), $started, $queries );
		}

		$counts = array();
		foreach ( self::CATEGORIES as $category ) {
			$counts[ $category ] = isset( $row[ $category ] ) ? (int) $row[ $category ] : 0;
		}

		return self::finish(
			array(
				'status'      => 'current',
				'generations' => $generations,
				'counts'      => $counts,
			),
			$started,
			$queries
		);
	}

	/**
	 * Return one bounded category page with final derived and object authority.
	 *
	 * @param array<string, mixed> $request Validated request.
	 * @return array<string, mixed>
	 */
	public static function page( array $request ): array {
		$started = microtime( true );
		$queries = get_num_queries();
		self::$memory_peak_start = memory_get_peak_usage( true );
		self::$memory_usage_start = memory_get_usage( false );
		$generations = self::capture_generations();
		if ( ! current_user_can( 'manage_intertexere' ) ) {
			return self::finish( array( 'status' => 'forbidden', 'generations' => $generations, 'message' => 'You are not allowed to view the site link audit.' ), $started, $queries );
		}

		if ( ! self::has_generations( $generations ) ) {
			return self::finish( self::unavailable( $generations, 'The active index and graph are not available.' ), $started, $queries );
		}
		if ( ! empty( $request['cursor'] ) && ( $request['cursor']['index_generation'] !== $generations['index'] || $request['cursor']['graph_generation'] !== $generations['graph'] ) ) {
			return self::finish( self::stale( $generations ), $started, $queries );
		}

		$category = (string) $request['category'];
		$is_post_category = in_array( $category, array( 'orphans', 'thin' ), true );
		$selection = $is_post_category
			? self::select_post_findings( $request, $generations )
			: self::select_edge_findings( $request, $generations );

		if ( ! is_array( $selection ) ) {
			return self::finish( self::unavailable( $generations, 'The audit findings could not be read.' ), $started, $queries );
		}

		$has_more = count( $selection ) > (int) $request['page_size'];
		$rows = array_slice( $selection, 0, (int) $request['page_size'] );
		$object_ids = self::object_ids( $rows, $is_post_category );
		$objects_before = self::snapshot_objects( $object_ids );
		if ( false === $objects_before ) {
			return self::finish( self::unavailable( $generations, 'Current permalink authority could not be calculated within the bounded audit contract.' ), $started, $queries );
		}

		if ( ! self::rows_match_current_objects( $rows, $objects_before, $is_post_category ) ) {
			return self::finish( self::stale( $generations, true ), $started, $queries );
		}

		/**
		 * Fires at the real boundary between initial finding/object selection and
		 * final derived-evidence reread. It is observability, not a decision path.
		 */
		do_action( 'intertexere_audit_before_final_derived_evidence', $category, $rows, $generations );

		if ( $is_post_category ) {
			$target_ids = array_map( static function ( array $row ): int { return (int) $row['post_id']; }, $rows );
			$final = self::classify_targets( $target_ids, $generations );
			if ( ! is_array( $final ) || ! self::post_evidence_matches( $rows, $final ) ) {
				return self::finish( self::stale( $generations, true ), $started, $queries );
			}
		} else {
			$final = self::reread_edges( $rows, $generations, $category );
			if ( ! is_array( $final ) || ! self::edge_evidence_matches( $rows, $final ) ) {
				return self::finish( self::stale( $generations, true ), $started, $queries );
			}
		}

		/** Fires before the separate final WordPress authority snapshot. */
		do_action( 'intertexere_audit_before_final_object_authority', $category, $rows, $generations );

		$objects_after = self::snapshot_objects( $object_ids );
		if ( false === $objects_after ) {
			return self::finish( self::unavailable( $generations, 'Current permalink authority could not be calculated within the bounded audit contract.' ), $started, $queries );
		}
		if ( $objects_before !== $objects_after || ! self::rows_match_current_objects( $rows, $objects_after, $is_post_category ) ) {
			return self::finish( self::stale( $generations, true ), $started, $queries );
		}
		if ( $generations !== self::capture_generations() ) {
			return self::finish( self::stale( $generations ), $started, $queries );
		}

		$rows = self::hydrate_rows( $rows, $objects_after, $is_post_category );
		$next_cursor = null;
		if ( $has_more && ! empty( $rows ) ) {
			$next_cursor = self::encode_cursor( $request, $generations, end( $rows ), $is_post_category );
		}

		return self::finish(
			array(
				'status'       => 'current',
				'generations'  => $generations,
				'category'     => $category,
				'rows'         => $rows,
				'next_cursor'  => $next_cursor,
				'page_size'    => (int) $request['page_size'],
			),
			$started,
			$queries
		);
	}

	/** @return array<string, mixed> */
	public static function last_metrics(): array {
		return self::$last_metrics;
	}

	/**
	 * Saturate qualifying inbound evidence at two for all bounded targets.
	 * This method is public to make the query and evidence bound testable.
	 *
	 * @param int[]                $target_ids Target IDs.
	 * @param array<string,string> $generations Captured generations.
	 * @return array<int, array<string, mixed>>|false
	 */
	public static function classify_targets( array $target_ids, array $generations ) {
		global $wpdb;

		$target_ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $target_ids ) ) ) ), 0, self::MAX_PAGE_SIZE );
		if ( empty( $target_ids ) || empty( Eligibility::post_types() ) || empty( Eligibility::post_statuses() ) ) {
			return array();
		}

		$parts = self::classification_select_parts( $generations );
		$placeholders = implode( ', ', array_fill( 0, count( $target_ids ), '%d' ) );
		$types = Eligibility::post_types();
		$statuses = Eligibility::post_statuses();
		$type_marks = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$status_marks = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$sql = 'SELECT ti.post_id, ti.content_hash AS target_index_hash, ti.permalink AS target_index_permalink, '
			. $parts['sql']
			. ' FROM ' . Schema::table_name() . ' ti INNER JOIN ' . $wpdb->posts . ' tp ON tp.ID = ti.post_id'
			. " WHERE ti.generation = %s AND ti.post_type IN ({$type_marks}) AND ti.post_status IN ({$status_marks})"
			. " AND ti.post_id IN ({$placeholders})";
		$args = array_merge( $parts['args'], array( $generations['index'] ), $types, $statuses, $target_ids );
		$rows = self::get_results( $sql, $args );
		if ( false === $rows ) {
			return false;
		}

		$result = array();
		foreach ( $rows as $row ) {
			$normalized = self::normalize_classification( $row );
			$result[ (int) $row['post_id'] ] = $normalized;
		}
		return $result;
	}

	/** @return array<string,string> */
	private static function capture_generations(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN (%s, %s)",
				Schema::GENERATION_OPTION,
				Schema::GRAPH_GENERATION_OPTION
			),
			ARRAY_A
		);
		$values = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$values[ $row['option_name'] ] = (string) maybe_unserialize( $row['option_value'] );
		}
		return array(
			'index' => $values[ Schema::GENERATION_OPTION ] ?? '',
			'graph' => $values[ Schema::GRAPH_GENERATION_OPTION ] ?? '',
		);
	}

	private static function has_generations( array $generations ): bool {
		return '' !== (string) $generations['index'] && '' !== (string) $generations['graph'];
	}

	private static function tables_are_statement_coherent(): bool {
		global $wpdb;

		$tables = array( $wpdb->posts, Schema::table_name(), Schema::graph_sources_table_name(), Schema::link_edges_table_name() );
		$placeholders = implode( ', ', array_fill( 0, count( $tables ), '%s' ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})",
				$tables
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || count( $rows ) !== count( $tables ) ) {
			return false;
		}
		foreach ( $rows as $row ) {
			if ( 'INNODB' !== strtoupper( (string) $row['ENGINE'] ) ) {
				return false;
			}
		}
		return true;
	}

	/** @return array<string, mixed>|null */
	private static function overview_row( array $generations ): ?array {
		global $wpdb;

		$types = Eligibility::post_types();
		$statuses = Eligibility::post_statuses();
		if ( empty( $types ) || empty( $statuses ) ) {
			return array_fill_keys( self::CATEGORIES, 0 );
		}
		$type_marks = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$status_marks = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		$structural = 'SELECT ti.post_id, COUNT(DISTINCT CASE WHEN e.is_self = 0 AND e.target_post_id = ti.post_id AND gs.source_state = %s'
			. " AND si.post_id IS NOT NULL AND sp.ID IS NOT NULL THEN e.source_post_id END) AS inbound_count"
			. ' FROM ' . Schema::table_name() . ' ti'
			. ' INNER JOIN ' . $wpdb->posts . " tp ON tp.ID = ti.post_id AND tp.post_type IN ({$type_marks}) AND tp.post_status IN ({$status_marks}) AND tp.post_password = ''"
			. ' LEFT JOIN ' . Schema::link_edges_table_name() . ' e ON e.generation = %s AND e.target_post_id = ti.post_id'
			. ' LEFT JOIN ' . Schema::graph_sources_table_name() . ' gs ON gs.generation = e.generation AND gs.source_post_id = e.source_post_id'
			. ' LEFT JOIN ' . Schema::table_name() . " si ON si.generation = %s AND si.post_id = e.source_post_id AND si.post_type IN ({$type_marks}) AND si.post_status IN ({$status_marks})"
			. ' LEFT JOIN ' . $wpdb->posts . " sp ON sp.ID = e.source_post_id AND sp.post_type IN ({$type_marks}) AND sp.post_status IN ({$status_marks}) AND sp.post_password = ''"
			. " WHERE ti.generation = %s AND ti.post_type IN ({$type_marks}) AND ti.post_status IN ({$status_marks}) GROUP BY ti.post_id";
		$structural_args = array_merge( array( 'ready' ), $types, $statuses, array( $generations['graph'], $generations['index'] ), $types, $statuses, $types, $statuses, array( $generations['index'] ), $types, $statuses );

		$edge_base = ' FROM ' . Schema::link_edges_table_name() . ' e INNER JOIN ' . Schema::graph_sources_table_name()
			. ' gs ON gs.generation = e.generation AND gs.source_post_id = e.source_post_id AND gs.source_state = %s'
			. ' INNER JOIN ' . Schema::table_name() . " si ON si.generation = %s AND si.post_id = e.source_post_id AND si.post_type IN ({$type_marks}) AND si.post_status IN ({$status_marks})"
			. ' INNER JOIN ' . $wpdb->posts . " sp ON sp.ID = e.source_post_id AND sp.post_type IN ({$type_marks}) AND sp.post_status IN ({$status_marks}) AND sp.post_password = ''"
			. ' LEFT JOIN ' . $wpdb->posts . ' tp ON tp.ID = e.target_post_id'
			. ' LEFT JOIN ' . Schema::table_name() . ' ti ON ti.generation = %s AND ti.post_id = e.target_post_id'
			. ' WHERE e.generation = %s AND ';
		$edge_args = array_merge( array( 'ready', $generations['index'] ), $types, $statuses, $types, $statuses, array( $generations['index'], $generations['graph'] ) );
		$target_invalid = "(e.target_post_id IS NULL OR tp.ID IS NULL OR tp.post_type NOT IN ({$type_marks}) OR tp.post_status NOT IN ({$status_marks}) OR tp.post_password <> '' OR ti.post_id IS NULL)";

		$sql = 'SELECT COALESCE(SUM(a.inbound_count = 0), 0) AS orphans, COALESCE(SUM(a.inbound_count = 1), 0) AS thin,'
			. ' (SELECT COUNT(*)' . $edge_base . $target_invalid . ') AS unavailable,'
			. ' (SELECT COUNT(*)' . $edge_base . 'e.target_post_id IS NOT NULL AND e.occurrence_count > 1) AS repeated,'
			. ' (SELECT COUNT(*)' . $edge_base . 'e.target_post_id IS NOT NULL AND e.is_self = 1) AS self,'
			. ' (SELECT COUNT(*)' . $edge_base . self::noncanonical_condition( $type_marks, $status_marks ) . ') AS noncanonical'
			. ' FROM (' . $structural . ') a';
		$args = array_merge(
			$edge_args,
			$types,
			$statuses,
			$edge_args,
			$edge_args,
			array_merge( $edge_args, $types, $statuses ),
			$structural_args
		);
		$row = self::get_row( $sql, $args );
		return is_array( $row ) ? $row : null;
	}

	/** @return array<int, array<string,mixed>>|false */
	private static function select_post_findings( array $request, array $generations ) {
		global $wpdb;

		$parts = self::classification_select_parts( $generations );
		$types = Eligibility::post_types();
		$statuses = Eligibility::post_statuses();
		if ( empty( $types ) || empty( $statuses ) ) {
			return array();
		}
		$type_marks = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$status_marks = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$sql = 'SELECT ti.post_id, ti.post_type, ti.title, ti.permalink, ti.content_hash AS target_index_hash, ti.permalink AS target_index_permalink, '
			. $parts['sql']
			. ' FROM ' . Schema::table_name() . ' ti INNER JOIN ' . $wpdb->posts . ' tp ON tp.ID = ti.post_id'
			. " WHERE ti.generation = %s AND ti.post_type IN ({$type_marks}) AND ti.post_status IN ({$status_marks})"
			. " AND tp.post_type IN ({$type_marks}) AND tp.post_status IN ({$status_marks}) AND tp.post_password = ''";
		$args = array_merge( $parts['args'], array( $generations['index'] ), $types, $statuses, $types, $statuses );
		self::append_filters( $sql, $args, $request, 'tp', 'ti.post_id' );
		$sql .= ' HAVING ' . ( 'orphans' === $request['category'] ? 'evidence_one IS NULL' : 'evidence_one IS NOT NULL AND evidence_two IS NULL' );
		$sql .= ' ORDER BY ti.post_id ASC LIMIT %d';
		$args[] = (int) $request['page_size'] + 1;

		$rows = self::get_results( $sql, $args );
		if ( false === $rows ) {
			return false;
		}
		return array_map( array( self::class, 'normalize_classification' ), $rows );
	}

	/**
	 * Four bounded correlated probes retain only the first two source IDs and
	 * their source-version hashes. The application never receives a full list.
	 *
	 * @return array{sql:string,args:array<int,mixed>}
	 */
	private static function classification_select_parts( array $generations ): array {
		$first_id = self::source_probe( $generations, 'e.source_post_id' );
		$first_hash = self::source_probe( $generations, 'gs.source_content_hash' );
		$second_id = self::source_probe( $generations, 'e.source_post_id', $first_id );
		$second_hash = self::source_probe( $generations, 'gs.source_content_hash', $first_id );

		return array(
			'sql' => '(' . $first_id['sql'] . ') AS evidence_one, (' . $second_id['sql'] . ') AS evidence_two, ('
				. $first_hash['sql'] . ') AS evidence_one_hash, (' . $second_hash['sql'] . ') AS evidence_two_hash',
			'args' => array_merge( $first_id['args'], $second_id['args'], $first_hash['args'], $second_hash['args'] ),
		);
	}

	/** @return array{sql:string,args:array<int,mixed>} */
	private static function source_probe( array $generations, string $select, ?array $after_probe = null ): array {
		global $wpdb;

		$types = Eligibility::post_types();
		$statuses = Eligibility::post_statuses();
		$type_marks = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$status_marks = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$sql = 'SELECT ' . $select . ' FROM ' . Schema::link_edges_table_name() . ' e'
			. ' INNER JOIN ' . Schema::graph_sources_table_name() . ' gs ON gs.generation = e.generation AND gs.source_post_id = e.source_post_id AND gs.source_state = %s'
			. ' INNER JOIN ' . Schema::table_name() . " si ON si.generation = %s AND si.post_id = e.source_post_id AND si.post_type IN ({$type_marks}) AND si.post_status IN ({$status_marks})"
			. ' INNER JOIN ' . $wpdb->posts . " sp ON sp.ID = e.source_post_id AND sp.post_type IN ({$type_marks}) AND sp.post_status IN ({$status_marks}) AND sp.post_password = ''"
			. ' WHERE e.generation = %s AND e.target_post_id = ti.post_id AND e.is_self = 0';
		$args = array_merge( array( 'ready', $generations['index'] ), $types, $statuses, $types, $statuses, array( $generations['graph'] ) );
		if ( null !== $after_probe ) {
			$sql .= ' AND e.source_post_id > (' . $after_probe['sql'] . ')';
			$args = array_merge( $args, $after_probe['args'] );
		}
		$sql .= ' ORDER BY e.source_post_id ASC LIMIT 1';
		return array( 'sql' => $sql, 'args' => $args );
	}

	/** @return array<string,mixed> */
	private static function normalize_classification( array $row ): array {
		$one = isset( $row['evidence_one'] ) ? (int) $row['evidence_one'] : 0;
		$two = isset( $row['evidence_two'] ) ? (int) $row['evidence_two'] : 0;
		$row['post_id'] = (int) $row['post_id'];
		$row['class'] = 0 === $one ? 0 : ( 0 === $two ? 1 : 2 );
		$row['evidence'] = array();
		if ( $one > 0 ) {
			$row['evidence'][] = array( 'source_post_id' => $one, 'source_content_hash' => (string) $row['evidence_one_hash'] );
		}
		if ( $two > 0 ) {
			$row['evidence'][] = array( 'source_post_id' => $two, 'source_content_hash' => (string) $row['evidence_two_hash'] );
		}
		unset( $row['evidence_one'], $row['evidence_two'], $row['evidence_one_hash'], $row['evidence_two_hash'] );
		return $row;
	}

	/** @return array<int,array<string,mixed>>|false */
	private static function select_edge_findings( array $request, array $generations ) {
		if ( empty( Eligibility::post_types() ) || empty( Eligibility::post_statuses() ) ) {
			return array();
		}
		$sql_data = self::edge_query_base( $generations );
		$sql = $sql_data['select'] . $sql_data['from'] . ' WHERE ' . $sql_data['where'];
		$args = $sql_data['args'];
		$sql .= ' AND ' . self::edge_category_condition( (string) $request['category'], $sql_data['type_marks'], $sql_data['status_marks'] );
		if ( 'unavailable' === $request['category'] ) {
			$args = array_merge( $args, $sql_data['types'], $sql_data['statuses'] );
		} elseif ( 'noncanonical' === $request['category'] ) {
			$args = array_merge( $args, $sql_data['types'], $sql_data['statuses'] );
		}
		self::append_filters( $sql, $args, $request, 'sp', 'e.source_post_id' );
		if ( ! empty( $request['cursor'] ) ) {
			$sql .= ' AND (e.source_post_id > %d OR (e.source_post_id = %d AND e.target_identity_hash > %s))';
			$args[] = (int) $request['cursor']['source_post_id'];
			$args[] = (int) $request['cursor']['source_post_id'];
			$args[] = (string) $request['cursor']['target_identity_hash'];
		}
		$sql .= ' ORDER BY e.source_post_id ASC, e.target_identity_hash ASC LIMIT %d';
		$args[] = (int) $request['page_size'] + 1;
		$rows = self::get_results( $sql, $args );
		if ( false === $rows ) {
			return false;
		}
		$category = (string) $request['category'];
		return array_map(
			static function ( array $row ) use ( $category ): array {
				$row = Site_Link_Audit::normalize_edge( $row );
				$row['finding_category'] = $category;
				return $row;
			},
			$rows
		);
	}

	/** @return array<string,mixed> */
	private static function edge_query_base( array $generations ): array {
		global $wpdb;

		$types = Eligibility::post_types();
		$statuses = Eligibility::post_statuses();
		$type_marks = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$status_marks = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$select = 'SELECT e.generation, e.source_post_id, e.target_identity_hash, e.target_post_id, e.normalized_url, e.occurrence_count, e.is_self,'
			. ' gs.source_content_hash, gs.source_state, si.content_hash AS source_index_hash, sp.post_title AS source_title, sp.post_type AS source_post_type, sp.post_status AS source_post_status,'
			. ' tp.post_title AS target_title, tp.post_type AS target_post_type, tp.post_status AS target_post_status, tp.post_password AS target_post_password,'
			. ' ti.post_id AS target_index_post_id, ti.permalink AS target_index_permalink, ti.content_hash AS target_index_hash';
		$from = ' FROM ' . Schema::link_edges_table_name() . ' e'
			. ' INNER JOIN ' . Schema::graph_sources_table_name() . ' gs ON gs.generation = e.generation AND gs.source_post_id = e.source_post_id'
			. ' INNER JOIN ' . Schema::table_name() . " si ON si.generation = %s AND si.post_id = e.source_post_id AND si.post_type IN ({$type_marks}) AND si.post_status IN ({$status_marks})"
			. ' INNER JOIN ' . $wpdb->posts . " sp ON sp.ID = e.source_post_id AND sp.post_type IN ({$type_marks}) AND sp.post_status IN ({$status_marks}) AND sp.post_password = ''"
			. ' LEFT JOIN ' . $wpdb->posts . ' tp ON tp.ID = e.target_post_id'
			. ' LEFT JOIN ' . Schema::table_name() . ' ti ON ti.generation = %s AND ti.post_id = e.target_post_id';
		$where = 'e.generation = %s AND gs.source_state = %s';
		$args = array_merge( array( $generations['index'] ), $types, $statuses, $types, $statuses, array( $generations['index'], $generations['graph'], 'ready' ) );
		return compact( 'select', 'from', 'where', 'args', 'types', 'statuses', 'type_marks', 'status_marks' );
	}

	private static function edge_category_condition( string $category, string $type_marks, string $status_marks ): string {
		if ( 'unavailable' === $category ) {
			return "(e.target_post_id IS NULL OR tp.ID IS NULL OR tp.post_type NOT IN ({$type_marks}) OR tp.post_status NOT IN ({$status_marks}) OR tp.post_password <> '' OR ti.post_id IS NULL)";
		}
		if ( 'repeated' === $category ) {
			return 'e.target_post_id IS NOT NULL AND e.occurrence_count > 1';
		}
		if ( 'self' === $category ) {
			return 'e.target_post_id IS NOT NULL AND e.is_self = 1';
		}
		return self::noncanonical_condition( $type_marks, $status_marks );
	}

	/**
	 * Prove current representative-URL identity without resolving one URL per row.
	 *
	 * Schema 2 can prove Core's explicit query-ID forms set-wise. Retained old
	 * slugs and other path aliases are intentionally omitted because historical
	 * edge identity does not prove that the representative path is still owned
	 * by the same current post.
	 */
	private static function noncanonical_condition( string $type_marks, string $status_marks ): string {
		$query = "CONCAT('&', SUBSTRING_INDEX(e.normalized_url, '?', -1), '&')";
		$p_count = "((CHAR_LENGTH({$query}) - CHAR_LENGTH(REPLACE({$query}, '&p=', ''))) / 3)";
		$page_count = "((CHAR_LENGTH({$query}) - CHAR_LENGTH(REPLACE({$query}, '&page_id=', ''))) / 9)";
		$attachment_count = "((CHAR_LENGTH({$query}) - CHAR_LENGTH(REPLACE({$query}, '&attachment_id=', ''))) / 15)";
		$one_identity = "({$p_count} + {$page_count} + {$attachment_count}) = 1";
		$matches = array();
		foreach ( array( 'p', 'page_id', 'attachment_id' ) as $key ) {
			$value = "SUBSTRING_INDEX(SUBSTRING_INDEX({$query}, '&{$key}=', -1), '&', 1)";
			$matches[] = "({$query} REGEXP '&{$key}=[0-9]+&' AND CAST({$value} AS UNSIGNED) = e.target_post_id)";
		}

		return 'e.target_post_id IS NOT NULL AND ti.post_id IS NOT NULL'
			. " AND tp.ID = e.target_post_id AND tp.post_type IN ({$type_marks}) AND tp.post_status IN ({$status_marks}) AND tp.post_password = ''"
			. ' AND e.normalized_url <> ti.permalink AND e.normalized_url LIKE \'%?%\''
			. ' AND ' . $one_identity . ' AND (' . implode( ' OR ', $matches ) . ')';
	}

	/** @return array<string,mixed> */
	private static function normalize_edge( array $row ): array {
		$row['source_post_id'] = (int) $row['source_post_id'];
		$row['target_post_id'] = empty( $row['target_post_id'] ) ? null : (int) $row['target_post_id'];
		$row['occurrence_count'] = (int) $row['occurrence_count'];
		$row['is_self'] = (bool) $row['is_self'];
		$row['target_index_post_id'] = empty( $row['target_index_post_id'] ) ? null : (int) $row['target_index_post_id'];
		return $row;
	}

	/** @return array<int,array<string,mixed>>|false */
	private static function reread_edges( array $rows, array $generations, string $category = '' ) {
		if ( empty( $rows ) ) {
			return array();
		}
		$sql_data = self::edge_query_base( $generations );
		$clauses = array();
		$args = $sql_data['args'];
		foreach ( $rows as $row ) {
			$clauses[] = '(e.source_post_id = %d AND e.target_identity_hash = %s)';
			$args[] = (int) $row['source_post_id'];
			$args[] = (string) $row['target_identity_hash'];
		}
		$sql = $sql_data['select'] . $sql_data['from'] . ' WHERE ' . $sql_data['where'] . ' AND (' . implode( ' OR ', $clauses ) . ')';
		if ( '' !== $category ) {
			$sql .= ' AND ' . self::edge_category_condition( $category, $sql_data['type_marks'], $sql_data['status_marks'] );
			if ( 'unavailable' === $category || 'noncanonical' === $category ) {
				$args = array_merge( $args, $sql_data['types'], $sql_data['statuses'] );
			}
		}
		$final = self::get_results( $sql, $args );
		return false === $final ? false : array_map( array( self::class, 'normalize_edge' ), $final );
	}

	private static function post_evidence_matches( array $initial, array $final ): bool {
		foreach ( $initial as $row ) {
			$id = (int) $row['post_id'];
			if ( ! isset( $final[ $id ] ) ) {
				return false;
			}
			$check = $final[ $id ];
			if ( (int) $row['class'] !== (int) $check['class'] || $row['evidence'] !== $check['evidence']
				|| (string) $row['target_index_hash'] !== (string) $check['target_index_hash']
				|| (string) $row['target_index_permalink'] !== (string) $check['target_index_permalink'] ) {
				return false;
			}
		}
		return count( $initial ) === count( $final );
	}

	private static function edge_evidence_matches( array $initial, array $final ): bool {
		$map = array();
		foreach ( $final as $row ) {
			$map[ self::edge_key( $row ) ] = self::edge_signature( $row );
		}
		foreach ( $initial as $row ) {
			$key = self::edge_key( $row );
			if ( ! isset( $map[ $key ] ) || self::edge_signature( $row ) !== $map[ $key ] ) {
				return false;
			}
		}
		return count( $initial ) === count( $map );
	}

	private static function edge_key( array $row ): string {
		return (int) $row['source_post_id'] . ':' . (string) $row['target_identity_hash'];
	}

	private static function edge_signature( array $row ): string {
		$fields = array(
			'generation', 'source_post_id', 'target_identity_hash', 'target_post_id', 'normalized_url',
			'occurrence_count', 'is_self', 'source_content_hash', 'source_state', 'source_index_hash', 'source_post_type',
			'source_post_status', 'target_post_type', 'target_post_status', 'target_post_password',
			'target_index_post_id', 'target_index_permalink', 'target_index_hash',
		);
		$value = array();
		foreach ( $fields as $field ) {
			$value[ $field ] = $row[ $field ] ?? null;
		}
		return hash( 'sha256', (string) wp_json_encode( $value ) );
	}

	/** @return int[] */
	private static function object_ids( array $rows, bool $post_category ): array {
		$ids = array();
		foreach ( $rows as $row ) {
			if ( $post_category ) {
				$ids[] = (int) $row['post_id'];
				foreach ( $row['evidence'] as $evidence ) {
					$ids[] = (int) $evidence['source_post_id'];
				}
			} else {
				$ids[] = (int) $row['source_post_id'];
				if ( ! empty( $row['target_post_id'] ) ) {
					$ids[] = (int) $row['target_post_id'];
				}
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/** @return array<int,array<string,mixed>>|false */
	private static function snapshot_objects( array $ids ) {
		global $wpdb;

		if ( empty( $ids ) ) {
			return array();
		}
		$marks = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$records = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID IN ({$marks})", $ids ) );
		$posts = array();
		foreach ( is_array( $records ) ? $records : array() as $record ) {
			$post = new \WP_Post( $record );
			$posts[ (int) $post->ID ] = $post;
		}
		foreach ( $ids as $id ) {
			clean_post_cache( $id );
		}
		if ( function_exists( 'update_post_cache' ) ) {
			$posts_to_cache = array_values( $posts );
			update_post_cache( $posts_to_cache );
		}
		$permalink_context = self::prime_permalink_dependencies( $posts );
		if ( false === $permalink_context ) {
			return false;
		}

		$snapshots = array();
		foreach ( $ids as $id ) {
			if ( ! isset( $posts[ $id ] ) ) {
				$snapshots[ $id ] = array( 'exists' => false );
				continue;
			}
			$post = $posts[ $id ];
			$permalink_query_start = get_num_queries();
			$permalink = (string) get_permalink( $post );
			$graph_source_hash = Link_Graph::current_source_hash( $post );
			$permalink_dependencies = self::permalink_dependency_signature( $post, $permalink_context );
			if ( get_num_queries() !== $permalink_query_start ) {
				return false;
			}
			$snapshots[ $id ] = array(
				'exists'    => true,
				'id'        => (int) $post->ID,
				'post_type' => (string) $post->post_type,
				'status'    => (string) $post->post_status,
				'password'  => (string) $post->post_password,
				'title'     => (string) get_the_title( $post ),
				'permalink' => $permalink,
				'permalink_dependencies' => $permalink_dependencies,
				'eligible'  => Eligibility::is_eligible( $post ),
				'graph_source_hash' => $graph_source_hash,
				'can_view'  => current_user_can( 'read_post', $id ),
				'can_edit'  => current_user_can( 'edit_post', $id ),
			);
		}
		ksort( $snapshots );
		return $snapshots;
	}

	/**
	 * Prime Core permalink dependencies in bounded batches before per-object use.
	 *
	 * @param array<int,\WP_Post> $posts Posts keyed by ID.
	 * @return array<string,mixed>|false
	 */
	private static function prime_permalink_dependencies( array $posts ) {
		global $wpdb, $wp_rewrite;

		$structure = isset( $wp_rewrite->permalink_structure ) ? (string) $wp_rewrite->permalink_structure : (string) get_option( 'permalink_structure' );
		$hierarchical = array();
		$standard_posts = array();
		$authors = array();
		foreach ( $posts as $post ) {
			$authors[] = (int) $post->post_author;
			if ( is_post_type_hierarchical( $post->post_type ) ) {
				$hierarchical[] = (int) $post->ID;
			}
			if ( 'post' === $post->post_type ) {
				$standard_posts[] = (int) $post->ID;
			}
		}

		$hierarchy = array();
		if ( ! empty( $hierarchical ) ) {
			$select = array( 'p0.ID AS root_id', 'p0.post_parent AS root_parent' );
			$joins = '';
			for ( $level = 1; $level <= self::MAX_PERMALINK_ANCESTOR_DEPTH; ++$level ) {
				$previous = 0 === $level - 1 ? 'p0' : 'p' . ( $level - 1 );
				$alias = 'p' . $level;
				$joins .= " LEFT JOIN {$wpdb->posts} {$alias} ON {$alias}.ID = {$previous}.post_parent";
				$select[] = "{$alias}.ID AS ancestor_{$level}";
				$select[] = "{$alias}.post_parent AS ancestor_{$level}_parent";
			}
			$marks = implode( ', ', array_fill( 0, count( $hierarchical ), '%d' ) );
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . implode( ', ', $select ) . " FROM {$wpdb->posts} p0{$joins} WHERE p0.ID IN ({$marks})", $hierarchical ), ARRAY_A );
			if ( ! is_array( $rows ) ) {
				return false;
			}
			$ancestor_ids = array();
			foreach ( $rows as $row ) {
				$root = (int) $row['root_id'];
				$hierarchy[ $root ] = array();
				for ( $level = 1; $level <= self::MAX_PERMALINK_ANCESTOR_DEPTH; ++$level ) {
					$ancestor = (int) ( $row[ 'ancestor_' . $level ] ?? 0 );
					if ( $ancestor <= 0 ) {
						break;
					}
					$hierarchy[ $root ][] = $ancestor;
					$ancestor_ids[] = $ancestor;
					if ( self::MAX_PERMALINK_ANCESTOR_DEPTH === $level && (int) ( $row[ 'ancestor_' . $level . '_parent' ] ?? 0 ) > 0 ) {
						return false;
					}
				}
			}
			$ancestor_ids = array_values( array_unique( $ancestor_ids ) );
			foreach ( $ancestor_ids as $ancestor_id ) {
				clean_post_cache( $ancestor_id );
			}
			if ( ! empty( $ancestor_ids ) ) {
				_prime_post_caches( $ancestor_ids, false, false );
			}
		}

		if ( false !== strpos( $structure, '%category%' ) && ! empty( $standard_posts ) ) {
			clean_object_term_cache( $standard_posts, 'post' );
			update_object_term_cache( $standard_posts, 'post' );
			$term_ids = array( absint( get_option( 'default_category' ) ) );
			foreach ( $standard_posts as $post_id ) {
				$terms = get_object_term_cache( $post_id, 'category' );
				foreach ( is_array( $terms ) ? $terms : array() as $term ) {
					$term_ids[] = (int) $term->term_id;
				}
			}
			$pending = array_values( array_unique( array_filter( $term_ids ) ) );
			for ( $level = 0; ! empty( $pending ) && $level < self::MAX_PERMALINK_ANCESTOR_DEPTH; ++$level ) {
				_prime_term_caches( $pending, false );
				$next = array();
				foreach ( $pending as $term_id ) {
					$term = get_term( $term_id, 'category' );
					if ( $term instanceof \WP_Term && $term->parent > 0 ) {
						$next[] = (int) $term->parent;
					}
				}
				$pending = array_values( array_unique( $next ) );
			}
			if ( ! empty( $pending ) ) {
				return false;
			}
		}

		$authors = array_values( array_unique( array_filter( $authors ) ) );
		if ( false !== strpos( $structure, '%author%' ) && ! empty( $authors ) ) {
			cache_users( $authors );
		}

		return array(
			'structure' => $structure,
			'hierarchy' => $hierarchy,
		);
	}

	/** @param array<string,mixed> $context Primed permalink context. */
	private static function permalink_dependency_signature( \WP_Post $post, array $context ): string {
		$data = array(
			'permalink_structure' => (string) $context['structure'],
			'home_url' => home_url( '/' ),
			'post_type' => (string) $post->post_type,
			'post_parent' => (int) $post->post_parent,
		);
		foreach ( $context['hierarchy'][ (int) $post->ID ] ?? array() as $ancestor_id ) {
			$ancestor = get_post( $ancestor_id );
			$data['ancestors'][] = $ancestor instanceof \WP_Post
				? array( (int) $ancestor->ID, (int) $ancestor->post_parent, (string) $ancestor->post_name, (string) $ancestor->post_type )
				: array( (int) $ancestor_id, null );
		}
		if ( 'post' === $post->post_type && false !== strpos( (string) $context['structure'], '%category%' ) ) {
			$terms = get_the_category( $post->ID );
			foreach ( $terms as $term ) {
				$chain = array();
				$current = $term;
				for ( $level = 0; $current instanceof \WP_Term && $level < self::MAX_PERMALINK_ANCESTOR_DEPTH; ++$level ) {
					$chain[] = array( (int) $current->term_id, (int) $current->parent, (string) $current->slug );
					$current = $current->parent > 0 ? get_term( $current->parent, 'category' ) : null;
				}
				$data['categories'][] = $chain;
			}
		}
		if ( false !== strpos( (string) $context['structure'], '%author%' ) ) {
			$author = get_userdata( (int) $post->post_author );
			$data['author'] = $author instanceof \WP_User ? array( (int) $author->ID, (string) $author->user_nicename ) : null;
		}

		return hash( 'sha256', (string) wp_json_encode( $data ) );
	}

	private static function rows_match_current_objects( array $rows, array $objects, bool $post_category ): bool {
		foreach ( $rows as $row ) {
			if ( $post_category ) {
				$id = (int) $row['post_id'];
				if ( empty( $objects[ $id ]['exists'] ) || empty( $objects[ $id ]['eligible'] ) || (string) $objects[ $id ]['permalink'] !== (string) $row['target_index_permalink'] ) {
					return false;
				}
				foreach ( $row['evidence'] as $evidence ) {
					$source_id = (int) $evidence['source_post_id'];
					if ( empty( $objects[ $source_id ]['exists'] ) || empty( $objects[ $source_id ]['eligible'] )
						|| (string) $objects[ $source_id ]['graph_source_hash'] !== (string) $evidence['source_content_hash'] ) {
						return false;
					}
				}
				continue;
			}

			$source_id = (int) $row['source_post_id'];
			if ( empty( $objects[ $source_id ]['exists'] ) || empty( $objects[ $source_id ]['eligible'] )
				|| (string) $objects[ $source_id ]['graph_source_hash'] !== (string) $row['source_content_hash'] ) {
				return false;
			}
			$target_id = (int) ( $row['target_post_id'] ?? 0 );
			if ( $target_id > 0 && 'unavailable' === (string) ( $row['finding_category'] ?? '' )
				&& empty( $row['target_index_post_id'] ) && ! empty( $objects[ $target_id ]['eligible'] ) ) {
				return false;
			}
			if ( $target_id > 0 && 'unavailable' !== (string) ( $row['finding_category'] ?? '' ) ) {
				if ( empty( $objects[ $target_id ]['exists'] ) || empty( $objects[ $target_id ]['eligible'] ) ) {
					return false;
				}
				if ( isset( $row['target_index_permalink'] )
					&& (string) $objects[ $target_id ]['permalink'] !== (string) $row['target_index_permalink'] ) {
					return false;
				}
			}
		}
		return true;
	}

	/** @return array<int,array<string,mixed>> */
	private static function hydrate_rows( array $rows, array $objects, bool $post_category ): array {
		foreach ( $rows as &$row ) {
			if ( $post_category ) {
				$target = $objects[ (int) $row['post_id'] ];
				$row['current_title'] = $target['title'];
				$row['current_permalink'] = $target['permalink'];
				$row['can_view'] = $target['can_view'];
				$row['can_edit'] = $target['can_edit'];
				continue;
			}
			$source = $objects[ (int) $row['source_post_id'] ];
			$row['source_title'] = $source['title'];
			$row['source_permalink'] = $source['permalink'];
			$row['source_can_view'] = $source['can_view'];
			$row['source_can_edit'] = $source['can_edit'];
			$target_id = (int) ( $row['target_post_id'] ?? 0 );
			if ( $target_id > 0 && isset( $objects[ $target_id ] ) ) {
				$row['target_current'] = $objects[ $target_id ];
			}
			$row['finding_reason'] = self::edge_reason( $row, $objects );
		}
		unset( $row );
		return $rows;
	}

	private static function edge_category( array $row ): string {
		if ( isset( $row['finding_category'] ) ) {
			return (string) $row['finding_category'];
		}
		if ( null === ( $row['target_post_id'] ?? null ) || empty( $row['target_index_post_id'] ) ) {
			return 'unavailable';
		}
		if ( ! empty( $row['is_self'] ) ) {
			return 'self';
		}
		if ( (int) $row['occurrence_count'] > 1 ) {
			return 'repeated';
		}
		return 'noncanonical';
	}

	private static function edge_reason( array $row, array $objects ): string {
		$category = self::edge_category( $row );
		$target_id = (int) ( $row['target_post_id'] ?? 0 );
		if ( 0 === $target_id ) {
			return 'Unresolved internal URL. No durable WordPress post identity was found.';
		}
		$target = $objects[ $target_id ] ?? array( 'exists' => false );
		if ( empty( $target['exists'] ) ) {
			return 'Resolved target is missing or deleted.';
		}
		if ( 'trash' === $target['status'] ) {
			return 'Resolved target is in the Trash.';
		}
		if ( 'private' === $target['status'] ) {
			return 'Resolved target is private.';
		}
		if ( 'publish' !== $target['status'] ) {
			return 'Resolved target is not published.';
		}
		if ( '' !== $target['password'] ) {
			return 'Resolved target is password protected.';
		}
		if ( ! in_array( $target['post_type'], Eligibility::post_types(), true ) ) {
			return 'Resolved target post type is not eligible for Intertexere.';
		}
		if ( empty( $target['eligible'] ) ) {
			return 'Resolved target is currently ineligible for Intertexere.';
		}
		if ( 'unavailable' === $category ) {
			return 'Resolved target is absent from the active eligible derived membership. Rebuild is required.';
		}
		if ( 'self' === $category ) {
			return 'Saved content links to its own post identity.';
		}
		if ( 'repeated' === $category ) {
			return 'Saved content links to this target more than once. Review may be useful.';
		}
		return 'The stored representative URL differs from the current canonical permalink.';
	}

	private static function append_filters( string &$sql, array &$args, array $request, string $post_alias, string $id_expression ): void {
		if ( '' !== (string) $request['post_type'] ) {
			$sql .= " AND {$post_alias}.post_type = %s";
			$args[] = $request['post_type'];
		}
		if ( '' !== (string) $request['search'] ) {
			if ( ctype_digit( (string) $request['search'] ) ) {
				$sql .= " AND {$id_expression} = %d";
				$args[] = (int) $request['search'];
			} else {
				$sql .= " AND {$post_alias}.post_name = %s";
				$args[] = $request['search'];
			}
		}
		if ( ! empty( $request['cursor'] ) && isset( $request['cursor']['post_id'] ) ) {
			$sql .= " AND {$id_expression} > %d";
			$args[] = (int) $request['cursor']['post_id'];
		}
	}

	private static function encode_cursor( array $request, array $generations, array $last, bool $post_category ): string {
		$data = array(
			'contract_version' => self::CONTRACT_VERSION,
			'category' => $request['category'],
			'post_type' => $request['post_type'],
			'search' => $request['search'],
			'index_generation' => $generations['index'],
			'graph_generation' => $generations['graph'],
		);
		if ( $post_category ) {
			$data['post_id'] = (int) $last['post_id'];
		} else {
			$data['source_post_id'] = (int) $last['source_post_id'];
			$data['target_identity_hash'] = (string) $last['target_identity_hash'];
		}
		$payload = self::base64url_encode( (string) wp_json_encode( $data ) );
		return $payload . '.' . hash_hmac( 'sha256', $payload, wp_salt( 'nonce' ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function decode_cursor( string $cursor ) {
		if ( strlen( $cursor ) > 1024 || 1 !== preg_match( '/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/', $cursor, $matches ) ) {
			return self::invalid( 'The audit cursor is invalid.' );
		}
		if ( ! hash_equals( hash_hmac( 'sha256', $matches[1], wp_salt( 'nonce' ) ), $matches[2] ) ) {
			return self::invalid( 'The audit cursor signature is invalid.' );
		}
		$json = self::base64url_decode( $matches[1] );
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) || (int) ( $data['contract_version'] ?? 0 ) !== self::CONTRACT_VERSION ) {
			return self::invalid( 'The audit cursor contract is invalid.' );
		}
		$base_keys = array( 'contract_version', 'category', 'post_type', 'search', 'index_generation', 'graph_generation' );
		$post_keys = array_merge( $base_keys, array( 'post_id' ) );
		$edge_keys = array_merge( $base_keys, array( 'source_post_id', 'target_identity_hash' ) );
		$keys = array_keys( $data );
		sort( $keys );
		sort( $post_keys );
		sort( $edge_keys );
		if ( $keys !== $post_keys && $keys !== $edge_keys ) {
			return self::invalid( 'The audit cursor fields are invalid.' );
		}
		if ( ! in_array( $data['category'], self::CATEGORIES, true ) || ! is_string( $data['index_generation'] ) || ! is_string( $data['graph_generation'] ) ) {
			return self::invalid( 'The audit cursor values are invalid.' );
		}
		if ( isset( $data['post_id'] ) && ( ! is_int( $data['post_id'] ) || $data['post_id'] <= 0 ) ) {
			return self::invalid( 'The audit post cursor is invalid.' );
		}
		if ( isset( $data['source_post_id'] ) && ( ! is_int( $data['source_post_id'] ) || $data['source_post_id'] <= 0 || ! is_string( $data['target_identity_hash'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $data['target_identity_hash'] ) ) ) {
			return self::invalid( 'The audit edge cursor is invalid.' );
		}
		return $data;
	}

	private static function base64url_encode( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	private static function base64url_decode( string $value ) {
		$padding = strlen( $value ) % 4;
		if ( $padding ) {
			$value .= str_repeat( '=', 4 - $padding );
		}
		return base64_decode( strtr( $value, '-_', '+/' ), true );
	}

	private static function scalar( $value ): string {
		return is_string( $value ) ? wp_unslash( $value ) : '';
	}

	/** @return array<int,array<string,mixed>>|false */
	private static function get_results( string $sql, array $args ) {
		global $wpdb;
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		return '' === $wpdb->last_error && is_array( $rows ) ? $rows : false;
	}

	/** @return array<string,mixed>|false */
	private static function get_row( string $sql, array $args ) {
		global $wpdb;
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A );
		return '' === $wpdb->last_error && is_array( $row ) ? $row : false;
	}

	/** @return array<string,mixed> */
	private static function stale( array $generations, bool $rebuild = false ): array {
		return array(
			'status' => 'stale',
			'generations' => $generations,
			'rebuild_required' => $rebuild,
			'message' => $rebuild
				? 'Audit evidence changed or no longer matches current eligibility. Refresh derived data and try again.'
				: 'Active audit data changed during this request. Refresh the audit and try again.',
		);
	}

	/** @return array<string,mixed> */
	private static function unavailable( array $generations, string $message ): array {
		return array( 'status' => 'unavailable', 'generations' => $generations, 'message' => $message );
	}

	/** @return \WP_Error */
	private static function invalid( string $message ): \WP_Error {
		return new \WP_Error( 'intertexere_audit_invalid_request', $message, array( 'status' => 400 ) );
	}

	/** @return array<string,mixed> */
	private static function finish( array $result, float $started, int $queries ): array {
		self::$last_metrics = array(
			'query_count' => get_num_queries() - $queries,
			'elapsed_ms' => round( ( microtime( true ) - $started ) * 1000, 3 ),
			'memory_delta' => max( 0, memory_get_peak_usage( true ) - self::$memory_peak_start ),
			'memory_usage_delta' => max( 0, memory_get_usage( false ) - self::$memory_usage_start ),
			'result_count' => isset( $result['rows'] ) ? count( $result['rows'] ) : 0,
		);
		$result['metrics'] = self::$last_metrics;
		return $result;
	}
}
