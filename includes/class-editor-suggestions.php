<?php
/**
 * Deterministic, read-only editor suggestion analysis.
 *
 * @package Intertexere
 */

namespace Intertexere;

final class Editor_Suggestions {
	public const CONTRACT_VERSION = 2;
	public const ALGORITHM_VERSION = 2;
	public const MAX_PAYLOAD_BYTES = 262144;
	public const MAX_UNITS = 500;
	public const MAX_UNIT_BYTES = 16384;
	public const MAX_TERMS = 64;
	public const MAX_PHRASES = 8;
	public const MAX_CANDIDATES = 100;
	public const MAX_RESULTS = 10;
	public const MAX_LOCATION_CANDIDATES = 8;
	public const MIN_SCORE = 25;
	private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS;

	/** @var array<string, mixed> */
	private static array $last_metrics = array();

	/** @var string[] */
	private const TEXT_BLOCKS = array(
		'core/paragraph',
		'core/heading',
		'core/list-item',
		'core/pullquote',
		'core/verse',
		'core/preformatted',
	);

	/** @var string[] */
	private const INSERTION_BLOCKS = array(
		'core/paragraph',
		'core/heading',
		'core/list-item',
	);

	/** @var string[] */
	private const TABLE_BLOCKS = array( 'core/table' );

	/** @var string[] */
	private const CAPTION_BLOCKS = array( 'core/image', 'core/audio', 'core/video' );

	/** @var string[] */
	private const STOPWORDS = array(
		'about', 'after', 'again', 'against', 'also', 'among', 'and', 'any', 'are', 'because',
		'been', 'before', 'being', 'between', 'both', 'but', 'can', 'could', 'did', 'does',
		'doing', 'each', 'for', 'from', 'had', 'has', 'have', 'her', 'here', 'hers', 'him',
		'his', 'how', 'into', 'its', 'itself', 'just', 'more', 'most', 'not', 'now', 'off',
		'once', 'only', 'other', 'our', 'ours', 'out', 'over', 'own', 'same', 'she', 'should',
		'some', 'such', 'than', 'that', 'the', 'their', 'theirs', 'them', 'then', 'there',
		'these', 'they', 'this', 'those', 'through', 'too', 'under', 'until', 'very', 'was',
		'were', 'what', 'when', 'where', 'which', 'while', 'who', 'why', 'will', 'with',
		'would', 'you', 'your', 'yours',
	);

	/**
	 * Analyze one validated unsaved draft without persisting it.
	 *
	 * @param mixed $payload Untrusted REST input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function analyze( $payload ) {
		$started_at    = microtime( true );
		$query_started = get_num_queries();
		$validated     = self::validate_payload( $payload );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$index_generation = (string) get_option( Schema::GENERATION_OPTION, '' );
		if ( '' === $index_generation ) {
			return new \WP_Error(
				'intertexere_index_unavailable',
				'Intertexere does not have an active content index.',
				array( 'status' => 503 )
			);
		}

		$graph_generation = (string) get_option( Schema::GRAPH_GENERATION_OPTION, '' );
		$parsed           = self::parse_units( $validated );
		$draft_hash       = self::draft_hash( $validated );
		$analysis_id      = hash(
			'sha256',
			self::canonical_json(
				array(
					'draft_hash'       => $draft_hash,
					'index_generation' => $index_generation,
					'graph_generation' => $graph_generation,
					'algorithm_version' => self::ALGORITHM_VERSION,
				)
			)
		);
		$draft_text       = trim( $validated['title'] . ' ' . implode( ' ', wp_list_pluck( $parsed['text_units'], 'text' ) ) );
		$draft_terms      = self::normalize_terms( $draft_text, self::MAX_TERMS );
		$draft_phrases    = self::extract_phrases( $validated['title'], $parsed['text_units'] );

		if ( self::string_length( implode( ' ', $draft_terms ) ) < 20 || count( $draft_terms ) < 3 ) {
			return self::response(
				$analysis_id,
				$draft_hash,
				$index_generation,
				$graph_generation,
				array(),
				0,
				0,
				false,
				$started_at,
				$query_started,
				0.0
			);
		}

		$retrieved  = self::retrieve_candidate_ids( $validated, $draft_terms, $draft_phrases, $parsed );
		$candidate_ids = $retrieved['ids'];
		$records    = Indexer::get_records( $candidate_ids );
		$current_targets = self::prime_current_targets( $candidate_ids );
		$graph_map  = self::candidate_graph_signals( $candidate_ids, $validated['post_id'], $parsed['resolved_target_ids'], $graph_generation );
		$suggestions = array();
		$already_linked_count = 0;

		foreach ( $candidate_ids as $candidate_id ) {
			if ( ! isset( $records[ $candidate_id ], $current_targets[ $candidate_id ] ) || $candidate_id === $validated['post_id'] ) {
				continue;
			}

			/**
			 * Fires immediately before current WordPress target revalidation.
			 *
			 * This read-boundary hook exists for observability and race-order tests.
			 */
			do_action( 'intertexere_editor_suggestions_before_target', $candidate_id, $validated );

			$target = get_post( $candidate_id );
			if ( ! $target instanceof \WP_Post || ! Eligibility::is_eligible( $target ) ) {
				continue;
			}

			if ( isset( $parsed['resolved_target_ids'][ $candidate_id ] )
				|| self::matches_unresolved_permalink( $target, $parsed['unresolved_urls'], $validated['base_url'] ) ) {
				++$already_linked_count;
				continue;
			}

			$scored = self::score_record(
				$records[ $candidate_id ],
				$target,
				$validated,
				$draft_text,
				$draft_terms,
				$graph_map[ $candidate_id ] ?? array()
			);
			if ( $scored['score'] < self::MIN_SCORE ) {
				continue;
			}

			$suggestions[] = array(
				'target_post_id'   => $candidate_id,
				'target_title'     => self::target_title_text( $target ),
				'target_permalink' => (string) get_permalink( $target ),
				'target_post_type' => (string) $target->post_type,
				'score'            => $scored['score'],
				'reason'           => $scored['reason'],
				'already_linked'   => false,
				'_target'          => $target,
				'_record'          => $records[ $candidate_id ],
			);
		}

		usort(
			$suggestions,
			static function ( array $left, array $right ): int {
				if ( $left['score'] !== $right['score'] ) {
					return $right['score'] <=> $left['score'];
				}

				$title_order = strcmp(
					self::normalize_phrase( (string) $left['target_title'] ),
					self::normalize_phrase( (string) $right['target_title'] )
				);
				return 0 !== $title_order ? $title_order : $left['target_post_id'] <=> $right['target_post_id'];
			}
		);

		$suggestions = array_slice( $suggestions, 0, self::MAX_RESULTS );
		$location_started = microtime( true );
		$title_context = array_merge( array( $validated['title'] ), array_column( $suggestions, 'target_title' ) );
		foreach ( $suggestions as &$suggestion ) {
			$location_result = self::find_locations(
				$suggestion['_target'],
				$suggestion['_record'],
				$parsed['text_units'],
				$draft_hash,
				$analysis_id,
				$title_context
			);
			$suggestion['location']            = $location_result['candidates'][0] ?? null;
			$suggestion['location_candidates'] = $location_result['candidates'];
			$suggestion['location_status']     = $location_result['status'];
			unset( $suggestion['_target'], $suggestion['_record'] );
		}
		unset( $suggestion );
		$location_ms = round( ( microtime( true ) - $location_started ) * 1000, 3 );

		return self::response(
			$analysis_id,
			$draft_hash,
			$index_generation,
			$graph_generation,
			$suggestions,
			$already_linked_count,
			count( $candidate_ids ),
			$retrieved['truncated'],
			$started_at,
			$query_started,
			$location_ms
		);
	}

	/**
	 * Prime current WordPress target objects with one bounded query.
	 *
	 * Each target is still fetched again after the race-observation hook. Normal
	 * WordPress mutations invalidate its object cache, so a changed target is
	 * reloaded while unchanged targets do not turn into an N+1 query pattern.
	 *
	 * @param int[] $candidate_ids Candidate IDs.
	 * @return array<int, true>
	 */
	private static function prime_current_targets( array $candidate_ids ): array {
		$candidate_ids = array_values( array_unique( array_filter( array_map( 'absint', $candidate_ids ) ) ) );
		if ( empty( $candidate_ids ) ) {
			return array();
		}

		$query = new \WP_Query(
			array(
				'post_type'              => Eligibility::post_types(),
				'post_status'            => array_keys( get_post_stati() ),
				'post__in'               => $candidate_ids,
				'posts_per_page'         => count( $candidate_ids ),
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'orderby'                => 'post__in',
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		$present = array();
		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$present[ (int) $post->ID ] = true;
			}
		}

		return $present;
	}

	/**
	 * Validate and canonicalize an untrusted draft payload.
	 *
	 * @param mixed $payload Untrusted input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function validate_payload( $payload ) {
		if ( ! is_array( $payload ) ) {
			return self::invalid( 'The draft analysis payload must be an object.' );
		}

		$allowed = array( 'post_id', 'post_type', 'title', 'taxonomies', 'units' );
		if ( ! self::has_exact_keys( $payload, $allowed ) ) {
			return self::invalid( 'The draft analysis payload contains missing or unknown fields.' );
		}

		$encoded = wp_json_encode( $payload, self::JSON_FLAGS );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_PAYLOAD_BYTES ) {
			return self::invalid( 'The draft analysis payload exceeds the 256 KiB limit.', 413, 'intertexere_payload_too_large' );
		}

		if ( ! is_int( $payload['post_id'] ) || $payload['post_id'] < 0 ) {
			return self::invalid( 'The post ID is invalid.' );
		}

		if ( ! is_string( $payload['post_type'] ) || sanitize_key( $payload['post_type'] ) !== $payload['post_type']
			|| ! self::is_supported_source_type( $payload['post_type'] ) ) {
			return self::invalid( 'The post type is not supported for editor analysis.' );
		}

		if ( ! is_string( $payload['title'] ) || ! self::valid_utf8( $payload['title'] ) || strlen( $payload['title'] ) > 10000 ) {
			return self::invalid( 'The edited title is invalid.' );
		}

		if ( $payload['post_id'] > 0 ) {
			$post = get_post( $payload['post_id'] );
			if ( ! $post instanceof \WP_Post || $post->post_type !== $payload['post_type'] ) {
				return self::invalid( 'The post ID and post type do not identify the same source.' );
			}
		}

		$taxonomies = self::validate_taxonomies( $payload['taxonomies'], $payload['post_type'] );
		if ( is_wp_error( $taxonomies ) ) {
			return $taxonomies;
		}

		if ( ! is_array( $payload['units'] ) || count( $payload['units'] ) > self::MAX_UNITS ) {
			return self::invalid( 'The draft contains too many analysis units.', 413, 'intertexere_too_many_units' );
		}

		$units = array();
		foreach ( $payload['units'] as $unit ) {
			if ( ! is_array( $unit ) || ! self::has_exact_keys( $unit, array( 'client_id', 'block_name', 'markup' ) ) ) {
				return self::invalid( 'An analysis unit contains missing or unknown fields.' );
			}
			if ( ! is_string( $unit['client_id'] ) || '' === $unit['client_id'] || strlen( $unit['client_id'] ) > 128
				|| ! preg_match( '/^[A-Za-z0-9_.:-]+$/', $unit['client_id'] ) ) {
				return self::invalid( 'An analysis unit has an invalid block client ID.' );
			}
			if ( ! is_string( $unit['block_name'] ) || ! preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $unit['block_name'] )
				|| ! self::is_supported_block( $unit['block_name'] ) ) {
				return self::invalid( 'An analysis unit has an unsupported block name.' );
			}
			if ( ! is_string( $unit['markup'] ) || strlen( $unit['markup'] ) > self::MAX_UNIT_BYTES || ! self::valid_utf8( $unit['markup'] ) ) {
				return self::invalid( 'An analysis unit has invalid or oversized markup.', 413, 'intertexere_unit_too_large' );
			}

			$blocks = array_values(
				array_filter(
					parse_blocks( $unit['markup'] ),
					static function ( array $block ): bool {
						return ! empty( $block['blockName'] ) || '' !== trim( (string) $block['innerHTML'] );
					}
				)
			);
			if ( 1 !== count( $blocks ) || $blocks[0]['blockName'] !== $unit['block_name'] ) {
				return self::invalid( 'An analysis unit does not contain exactly one matching block.' );
			}

			$units[] = array(
				'client_id' => $unit['client_id'],
				'block_name'=> $unit['block_name'],
				'markup'    => $unit['markup'],
			);
		}

		$base_url = $payload['post_id'] > 0 ? (string) get_permalink( $payload['post_id'] ) : home_url( '/' );

		return array(
			'post_id'    => $payload['post_id'],
			'post_type'  => $payload['post_type'],
			'title'      => $payload['title'],
			'taxonomies' => $taxonomies,
			'units'      => $units,
			'base_url'   => $base_url,
		);
	}

	/**
	 * Return whether a post type may be an editor-analysis source.
	 */
	public static function is_supported_source_type( string $post_type ): bool {
		$object = get_post_type_object( $post_type );
		if ( ! $object || empty( $object->show_in_rest ) || ! post_type_supports( $post_type, 'editor' )
			|| ! in_array( $post_type, Eligibility::post_types(), true ) ) {
			return false;
		}

		if ( ! function_exists( 'use_block_editor_for_post_type' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}

		return function_exists( 'use_block_editor_for_post_type' ) && use_block_editor_for_post_type( $post_type );
	}

	/**
	 * Return the canonical server-computed draft hash.
	 *
	 * @param array<string, mixed> $validated Validated payload.
	 */
	public static function draft_hash( array $validated ): string {
		return hash( 'sha256', self::draft_hash_input( $validated ) );
	}

	/**
	 * Return the exact UTF-8 JSON input shared with ECMAScript JSON.stringify().
	 *
	 * The taxonomy map is an object even when empty. Units remain an ordered
	 * array. Permitted strings use literal Unicode, including U+2028 and U+2029,
	 * while JSON syntax characters and controls retain normal JSON escaping.
	 *
	 * @param array<string, mixed> $validated Validated payload.
	 */
	public static function draft_hash_input( array $validated ): string {
		return self::canonical_json(
			array(
				'algorithm_version' => self::ALGORITHM_VERSION,
				'post_id'           => (int) $validated['post_id'],
				'post_type'         => (string) $validated['post_type'],
				'title'             => (string) $validated['title'],
				'taxonomies'        => (object) $validated['taxonomies'],
				'units'             => $validated['units'],
			)
		);
	}

	/**
	 * Normalize unique meaningful terms in first-seen order.
	 *
	 * @return string[]
	 */
	public static function normalize_terms( string $text, int $limit = self::MAX_TERMS ): array {
		$phrase = self::normalize_phrase( $text );
		if ( '' === $phrase ) {
			return array();
		}

		$terms = array();
		foreach ( explode( ' ', $phrase ) as $term ) {
			if ( self::string_length( $term ) < 3 || in_array( $term, self::STOPWORDS, true ) || isset( $terms[ $term ] ) ) {
				continue;
			}
			$terms[ $term ] = true;
			if ( count( $terms ) >= max( 0, $limit ) ) {
				break;
			}
		}

		return array_keys( $terms );
	}

	/**
	 * Extract a bounded, deterministic list of ordered search phrases.
	 *
	 * @param array<int, array<string, mixed>> $text_units Parsed visible-text units.
	 * @return string[]
	 */
	public static function extract_phrases( string $title, array $text_units ): array {
		$phrases = array();
		$values  = array_merge( array( $title ), wp_list_pluck( $text_units, 'text' ) );

		foreach ( $values as $value ) {
			$terms = self::normalize_terms( (string) $value, 8 );
			if ( count( $terms ) < 2 ) {
				continue;
			}

			$phrase = implode( ' ', $terms );
			if ( ! isset( $phrases[ $phrase ] ) ) {
				$phrases[ $phrase ] = true;
			}
			if ( count( $phrases ) >= self::MAX_PHRASES ) {
				break;
			}
		}

		return array_keys( $phrases );
	}

	/**
	 * Request-scoped performance diagnostics for tests and PR reporting.
	 *
	 * @return array<string, mixed>
	 */
	public static function last_metrics(): array {
		return self::$last_metrics;
	}

	/**
	 * Return validated literal draft units for the bounded AI service.
	 *
	 * This is the only server-side bridge from the 0.3 snapshot parser to 0.4.
	 * It performs no persistence, rendering, shortcode execution, or retrieval.
	 *
	 * @param mixed $payload Untrusted draft snapshot.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function context_for_ai( $payload ) {
		$validated = self::validate_payload( $payload );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$parsed = self::parse_units( $validated );

		return array(
			'validated'  => $validated,
			'text_units' => $parsed['text_units'],
			'draft_hash' => self::draft_hash( $validated ),
		);
	}

	/**
	 * Parse validated markup into literal visible text and link identities.
	 *
	 * @param array<string, mixed> $validated Validated payload.
	 * @return array<string, mixed>
	 */
	private static function parse_units( array $validated ): array {
		$text_units          = array();
		$resolved_target_ids = array();
		$unresolved_urls     = array();

		foreach ( $validated['units'] as $order => $unit ) {
			$blocks    = parse_blocks( $unit['markup'] );
			$block     = reset( $blocks );
			$inner_html = is_array( $block ) ? (string) $block['innerHTML'] : '';
			$pieces    = self::extract_text_pieces( $unit['block_name'], $inner_html );
			$insertion_mapping = self::insertion_text_mapping( $unit['block_name'], $inner_html );

			foreach ( $pieces as $piece_order => $piece ) {
				$text = self::visible_text( $piece );
				if ( '' === $text ) {
					continue;
				}
				$text_units[] = array(
					'client_id' => $unit['client_id'],
					'block_name'=> $unit['block_name'],
					'order'     => $order,
					'piece'     => $piece_order,
					'text'      => $text,
					'insertable'=> null !== $insertion_mapping && 0 === $piece_order,
					'insertion_text' => null !== $insertion_mapping && 0 === $piece_order ? $insertion_mapping['text'] : null,
				);
			}

			$processor = new \WP_HTML_Tag_Processor( $inner_html );
			while ( $processor->next_tag( 'A' ) ) {
				if ( $processor->is_tag_closer() ) {
					continue;
				}
				$href = $processor->get_attribute( 'href' );
				if ( ! is_string( $href ) ) {
					continue;
				}
				$resolved = Link_Resolver::resolve_from_base( $href, $validated['base_url'], $validated['post_id'] );
				if ( null === $resolved ) {
					continue;
				}
				if ( null === $resolved['target_post_id'] ) {
					$unresolved_urls[ $resolved['normalized_url'] ] = true;
				} else {
					$resolved_target_ids[ (int) $resolved['target_post_id'] ] = true;
				}
			}
		}

		return array(
			'text_units'          => $text_units,
			'resolved_target_ids' => $resolved_target_ids,
			'unresolved_urls'     => $unresolved_urls,
		);
	}

	/**
	 * @return string[]
	 */
	private static function extract_text_pieces( string $block_name, string $inner_html ): array {
		if ( in_array( $block_name, self::TEXT_BLOCKS, true ) ) {
			return array( $inner_html );
		}

		if ( in_array( $block_name, self::TABLE_BLOCKS, true ) ) {
			return self::matching_inner_html( $inner_html, 't[dh]' );
		}

		if ( in_array( $block_name, self::CAPTION_BLOCKS, true ) ) {
			return self::matching_inner_html( $inner_html, 'figcaption' );
		}

		return array();
	}

	/**
	 * Extract literal element contents without executing or rendering markup.
	 *
	 * @return string[]
	 */
	private static function matching_inner_html( string $html, string $tag_pattern ): array {
		if ( ! preg_match_all( '#<' . $tag_pattern . '\\b[^>]*>(.*?)</' . $tag_pattern . '>#is', $html, $matches ) ) {
			return array();
		}

		return array_values( $matches[1] );
	}

	private static function visible_text( string $html ): string {
		$text = wp_strip_all_tags( $html, true );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );

		return trim( is_string( $text ) ? $text : '' );
	}

	/**
	 * Map one supported direct RichText attribute to the exact PHP plaintext
	 * representation shared with insertion validation. The browser still owns
	 * final UTF-16 range and serialization authority.
	 *
	 * @return array{content_html:string,text:string}|null
	 */
	public static function insertion_text_mapping( string $block_name, string $inner_html ): ?array {
		$patterns = array(
			'core/paragraph' => '#^\s*<p\b[^>]*>(.*)</p>\s*$#is',
			'core/heading'   => '#^\s*<h[1-6]\b[^>]*>(.*)</h[1-6]>\s*$#is',
			'core/list-item' => '#^\s*<li\b[^>]*>(.*)</li>\s*$#is',
		);
		if ( ! isset( $patterns[ $block_name ] ) || 1 !== preg_match( $patterns[ $block_name ], $inner_html, $match ) ) {
			return null;
		}

		$content_html = $match[1];
		$line_mapped  = preg_replace( '#<br\s*/?>#i', "\n", $content_html );
		if ( ! is_string( $line_mapped ) ) {
			return null;
		}

		return array(
			'content_html' => $content_html,
			'text'         => html_entity_decode( wp_strip_all_tags( $line_mapped, false ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' ),
		);
	}

	/**
	 * @param array<string, mixed> $validated Validated payload.
	 * @param string[]             $draft_terms Draft terms.
	 * @param array<string, mixed> $parsed Parsed draft.
	 * @return array{ids:int[],truncated:bool}
	 */
	private static function retrieve_candidate_ids( array $validated, array $draft_terms, array $draft_phrases, array $parsed ): array {
		$search_ids = self::search_candidates( $draft_phrases, $draft_terms );
		$taxonomy_ids = self::taxonomy_candidates( $validated );
		$graph_ids = self::graph_candidates( $validated['post_id'], array_keys( $parsed['resolved_target_ids'] ) );
		$all = array();

		foreach ( array_merge( $search_ids, $taxonomy_ids, $graph_ids ) as $post_id ) {
			$post_id = (int) $post_id;
			if ( $post_id > 0 ) {
				$all[ $post_id ] = true;
			}
		}

		$ids       = array_keys( $all );
		$truncated = count( $ids ) > self::MAX_CANDIDATES;

		return array(
			'ids'       => array_slice( $ids, 0, self::MAX_CANDIDATES ),
			'truncated' => $truncated,
		);
	}

	/** @return int[] */
	private static function search_candidates( array $draft_phrases, array $draft_terms ): array {
		$search = ! empty( $draft_phrases ) ? $draft_phrases[0] : implode( ' ', array_slice( $draft_terms, 0, 8 ) );
		if ( '' === $search ) {
			return array();
		}

		$query = new \WP_Query(
			array(
				'post_type'              => Eligibility::post_types(),
				'post_status'            => Eligibility::post_statuses(),
				'has_password'           => false,
				's'                      => $search,
				'fields'                 => 'ids',
				'posts_per_page'         => 50,
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'orderby'                => array( 'relevance' => 'DESC', 'ID' => 'ASC' ),
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return array_map( 'intval', $query->posts );
	}

	/** @return int[] */
	private static function taxonomy_candidates( array $validated ): array {
		$tax_query = array( 'relation' => 'OR' );
		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			if ( ! empty( $validated['taxonomies'][ $taxonomy ] ) ) {
				$tax_query[] = array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $validated['taxonomies'][ $taxonomy ],
				);
			}
		}

		if ( 1 === count( $tax_query ) ) {
			return array();
		}

		$query = new \WP_Query(
			array(
				'post_type'              => Eligibility::post_types(),
				'post_status'            => Eligibility::post_statuses(),
				'has_password'           => false,
				'tax_query'              => $tax_query,
				'fields'                 => 'ids',
				'posts_per_page'         => 50,
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return array_map( 'intval', $query->posts );
	}

	/** @return int[] */
	private static function graph_candidates( int $current_post_id, array $linked_target_ids ): array {
		global $wpdb;

		$generation = (string) get_option( Schema::GRAPH_GENERATION_OPTION, '' );
		if ( '' === $generation ) {
			return array();
		}

		$target_ids = array_values( array_unique( array_filter( array_map( 'absint', $linked_target_ids ) ) ) );
		if ( $current_post_id > 0 && Eligibility::is_eligible( $current_post_id ) ) {
			$target_ids[] = $current_post_id;
		}
		$target_ids = array_values( array_unique( $target_ids ) );
		if ( empty( $target_ids ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $target_ids ), '%d' ) );
		$args         = array_merge( array( $generation, 'ready' ), $target_ids, array( 50 ) );
		$sql          = $wpdb->prepare(
			'SELECT DISTINCT e.source_post_id FROM ' . Schema::link_edges_table_name() . ' e'
			. ' INNER JOIN ' . Schema::graph_sources_table_name() . ' s'
			. ' ON s.generation = e.generation AND s.source_post_id = e.source_post_id'
			. " WHERE e.generation = %s AND s.source_state = %s AND e.target_post_id IN ({$placeholders})"
			. ' ORDER BY e.source_post_id ASC LIMIT %d',
			$args
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( 'intval', $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Return graph scoring flags keyed by candidate post ID.
	 *
	 * @param int[] $candidate_ids Candidate IDs.
	 * @param int[] $draft_target_ids Resolved targets linked in the draft.
	 * @return array<int, array<string, mixed>>
	 */
	private static function candidate_graph_signals( array $candidate_ids, int $current_post_id, array $draft_target_ids, string $generation ): array {
		global $wpdb;

		$candidate_ids   = array_values( array_unique( array_filter( array_map( 'absint', $candidate_ids ) ) ) );
		$draft_target_ids = array_values( array_unique( array_filter( array_map( 'absint', array_keys( $draft_target_ids ) ) ) ) );
		$interesting      = $draft_target_ids;
		if ( $current_post_id > 0 ) {
			$interesting[] = $current_post_id;
		}

		if ( '' === $generation || empty( $candidate_ids ) || empty( $interesting ) ) {
			return array();
		}

		$source_placeholders = implode( ', ', array_fill( 0, count( $candidate_ids ), '%d' ) );
		$target_placeholders = implode( ', ', array_fill( 0, count( $interesting ), '%d' ) );
		$args = array_merge( array( $generation, 'ready' ), $candidate_ids, $interesting, array( 1000 ) );
		$sql  = $wpdb->prepare(
			'SELECT e.source_post_id, e.target_post_id FROM ' . Schema::link_edges_table_name() . ' e'
			. ' INNER JOIN ' . Schema::graph_sources_table_name() . ' s'
			. ' ON s.generation = e.generation AND s.source_post_id = e.source_post_id'
			. " WHERE e.generation = %s AND s.source_state = %s AND e.source_post_id IN ({$source_placeholders})"
			. " AND e.target_post_id IN ({$target_placeholders}) ORDER BY e.source_post_id ASC, e.target_post_id ASC LIMIT %d",
			$args
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$map  = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$source_id = (int) $row['source_post_id'];
			$target_id = (int) $row['target_post_id'];
			if ( ! isset( $map[ $source_id ] ) ) {
				$map[ $source_id ] = array( 'links_to_source' => false, 'shared_destinations' => 0 );
			}
			if ( $current_post_id > 0 && $target_id === $current_post_id ) {
				$map[ $source_id ]['links_to_source'] = true;
			}
			if ( in_array( $target_id, $draft_target_ids, true ) ) {
				++$map[ $source_id ]['shared_destinations'];
			}
		}

		return $map;
	}

	/**
	 * @param array<string, mixed> $record Index record.
	 * @param array<string, mixed> $validated Validated draft.
	 * @param string[]             $draft_terms Draft terms.
	 * @param array<string, mixed> $graph Graph flags.
	 * @return array{score:int,reason:array<string,mixed>}
	 */
	private static function score_record( array $record, \WP_Post $target, array $validated, string $draft_text, array $draft_terms, array $graph ): array {
		$title_phrase = self::normalize_phrase( (string) $record['title'] );
		$contributions = array(
			'title-phrase'      => '' !== $title_phrase && self::contains_phrase( self::normalize_phrase( $draft_text ), $title_phrase ) ? 30 : 0,
			'title-overlap'     => self::coverage_score( $draft_terms, self::normalize_terms( (string) $record['title'] ), 40 ),
			'heading-overlap'   => self::coverage_score( $draft_terms, self::terms_from_json_strings( (string) $record['headings'] ), 20 ),
			'taxonomy-overlap'  => min( 30, 15 * self::shared_taxonomy_count( $validated['taxonomies'], (string) $record['taxonomies'] ) ),
			'excerpt-overlap'   => self::coverage_score( $draft_terms, self::normalize_terms( (string) $record['excerpt'] ), 15 ),
			'content-overlap'   => self::coverage_score( $draft_terms, self::normalize_terms( (string) $record['normalized_content'] ), 10 ),
			'links-to-source'   => ! empty( $graph['links_to_source'] ) ? 8 : 0,
			'shared-destination'=> min( 6, 2 * (int) ( $graph['shared_destinations'] ?? 0 ) ),
			'same-post-type'    => $target->post_type === $validated['post_type'] ? 2 : 0,
		);

		$score   = array_sum( $contributions );
		$signals = array_keys( array_filter( $contributions ) );
		$priority = array_keys( $contributions );
		usort(
			$signals,
			static function ( string $left, string $right ) use ( $contributions, $priority ): int {
				if ( $contributions[ $left ] !== $contributions[ $right ] ) {
					return $contributions[ $right ] <=> $contributions[ $left ];
				}
				return array_search( $left, $priority, true ) <=> array_search( $right, $priority, true );
			}
		);
		$code = empty( $signals ) ? 'no-signal' : $signals[0];

		return array(
			'score'  => $score,
			'reason' => array(
				'code'    => $code,
				'label'   => self::reason_label( $code ),
				'signals' => array_keys( array_filter( $contributions ) ),
			),
		);
	}

	/**
	 * @param string[] $draft_terms Draft terms.
	 * @param string[] $candidate_terms Candidate terms.
	 */
	private static function coverage_score( array $draft_terms, array $candidate_terms, int $maximum ): int {
		$candidate_terms = array_values( array_unique( $candidate_terms ) );
		if ( empty( $candidate_terms ) ) {
			return 0;
		}

		return min(
			$maximum,
			(int) floor( $maximum * count( array_intersect( $candidate_terms, array_unique( $draft_terms ) ) ) / count( $candidate_terms ) )
		);
	}

	/** @return string[] */
	private static function terms_from_json_strings( string $json ): array {
		$values = json_decode( $json, true );
		return is_array( $values ) ? self::normalize_terms( implode( ' ', array_map( 'strval', $values ) ) ) : array();
	}

	private static function shared_taxonomy_count( array $draft_taxonomies, string $candidate_json ): int {
		$candidate = json_decode( $candidate_json, true );
		if ( ! is_array( $candidate ) ) {
			return 0;
		}

		$shared = array();
		foreach ( $draft_taxonomies as $taxonomy => $ids ) {
			if ( ! isset( $candidate[ $taxonomy ] ) || ! is_array( $candidate[ $taxonomy ] ) ) {
				continue;
			}
			$candidate_ids = array_map(
				static function ( array $term ): int {
					return isset( $term['id'] ) ? (int) $term['id'] : 0;
				},
				$candidate[ $taxonomy ]
			);
			foreach ( array_intersect( $ids, $candidate_ids ) as $term_id ) {
				$shared[ $taxonomy . ':' . $term_id ] = true;
			}
		}

		return count( $shared );
	}

	private static function reason_label( string $code ): string {
		$labels = array(
			'title-phrase'       => "The draft contains this article's title phrase.",
			'title-overlap'      => "The draft uses terms from this article's title.",
			'heading-overlap'    => "The draft overlaps with this article's headings.",
			'taxonomy-overlap'   => 'The draft and destination share categories or tags.',
			'excerpt-overlap'    => "The draft overlaps with this article's excerpt.",
			'content-overlap'    => "The draft overlaps with this article's indexed content.",
			'links-to-source'    => 'This destination already links to the saved source article.',
			'shared-destination' => 'The draft and destination link to related site content.',
			'same-post-type'     => 'The destination uses the same WordPress post type.',
			'no-signal'          => 'No deterministic relationship was found.',
		);

		return $labels[ $code ] ?? $labels['no-signal'];
	}

	/**
	 * @param array<string, mixed> $record Candidate index record.
	 * @param array<int, array<string, mixed>> $text_units Parsed text units.
	 * @return array<string, mixed>|null
	 */
	private static function find_locations( \WP_Post $target, array $record, array $text_units, string $draft_hash, string $analysis_id, array $title_context ): array {
		$title         = self::target_title_text( $target );
		$generic_terms = self::shared_leading_title_terms( $title, $title_context );
		$phrases       = array();
		self::add_location_phrase( $phrases, $title );

		$title_parts = preg_split( '/\s*(?:[:|]|\x{2013}|\x{2014})\s*|\s+-\s+/u', $title, 2 );
		if ( is_array( $title_parts ) && 2 === count( $title_parts ) ) {
			self::add_location_phrase( $phrases, $title_parts[1] );
		}

		$headings = json_decode( (string) $record['headings'], true );
		foreach ( is_array( $headings ) ? $headings : array() as $heading ) {
			$heading = is_string( $heading ) ? trim( $heading ) : '';
			$heading_terms = array_values( array_diff( self::normalize_terms( $heading ), $generic_terms ) );
			if ( ! empty( $heading_terms ) ) {
				self::add_location_phrase( $phrases, $heading );
			}
		}

		$specific_terms = array_values(
			array_diff(
				array_unique(
					array_merge(
						self::normalize_terms( (string) $record['title'] ),
						self::terms_from_json_strings( (string) $record['headings'] )
					)
				),
				$generic_terms
			)
		);
		foreach ( $text_units as $unit ) {
			$search_text = ! empty( $unit['insertable'] ) && is_string( $unit['insertion_text'] ) ? $unit['insertion_text'] : $unit['text'];
			$anchor = self::overlap_anchor( $search_text, $specific_terms );
			if ( null !== $anchor ) {
				self::add_location_phrase( $phrases, $anchor['text'] );
			}
		}

		$candidates        = array();
		$seen              = array();
		$unsupported_match = false;
		foreach ( $phrases as $phrase ) {
			foreach ( $text_units as $unit ) {
				$search_text = ! empty( $unit['insertable'] ) && is_string( $unit['insertion_text'] ) ? $unit['insertion_text'] : $unit['text'];
				$matches = self::case_insensitive_occurrences( $search_text, $phrase );
				if ( empty( $matches ) ) {
					continue;
				}
				if ( empty( $unit['insertable'] ) || ! is_string( $unit['insertion_text'] ) ) {
					$unsupported_match = true;
					continue;
				}

				foreach ( $matches as $match ) {
					$identity = $unit['client_id'] . "\0" . $match['text'] . "\0" . $match['occurrence'];
					if ( isset( $seen[ $identity ] ) ) {
						continue;
					}
					$seen[ $identity ] = true;
					$candidates[] = self::location_value( $unit, $match['text'], $match['offset'], $match['occurrence'], $draft_hash, $analysis_id );
					if ( count( $candidates ) >= self::MAX_LOCATION_CANDIDATES ) {
						break 3;
					}
				}
			}
		}

		return array(
			'candidates' => $candidates,
			'status'     => ! empty( $candidates ) ? 'candidates' : ( $unsupported_match ? 'unsupported-block' : 'no-specific-phrase' ),
		);
	}

	private static function add_location_phrase( array &$phrases, string $phrase ): void {
		$phrase = trim( $phrase );
		$key    = self::lower( $phrase );
		if ( '' !== $key && ! isset( $phrases[ $key ] ) ) {
			$phrases[ $key ] = $phrase;
		}
	}

	/** @return string[] */
	private static function shared_leading_title_terms( string $title, array $title_context ): array {
		$target_terms = self::normalize_terms( $title );
		$longest      = 0;
		$skipped_self = false;
		foreach ( $title_context as $other_title ) {
			if ( ! is_string( $other_title ) ) {
				continue;
			}
			if ( ! $skipped_self && self::normalize_phrase( $other_title ) === self::normalize_phrase( $title ) ) {
				$skipped_self = true;
				continue;
			}
			$other_terms = self::normalize_terms( $other_title );
			$shared      = 0;
			while ( isset( $target_terms[ $shared ], $other_terms[ $shared ] ) && $target_terms[ $shared ] === $other_terms[ $shared ] ) {
				++$shared;
			}
			$longest = max( $longest, $shared );
		}

		return $longest > 0 ? array_slice( $target_terms, 0, $longest ) : array();
	}

	/** @return array<int,array{text:string,offset:int,occurrence:int}> */
	private static function case_insensitive_occurrences( string $text, string $needle ): array {
		$count = '' === $needle ? 0 : preg_match_all( '/' . preg_quote( $needle, '/' ) . '/iu', $text, $captured, PREG_OFFSET_CAPTURE );
		if ( false === $count || 0 === $count ) {
			return array();
		}

		$matches = array();
		foreach ( $captured[0] as $match ) {
			$exact       = (string) $match[0];
			$byte_offset = (int) $match[1];
			$before      = substr( $text, 0, $byte_offset );
			$matches[]   = array(
				'text'       => $exact,
				'offset'     => self::byte_to_character_offset( $text, $byte_offset ),
				'occurrence' => substr_count( $before, $exact ),
			);
		}
		return $matches;
	}

	/** @return array{text:string,offset:int}|null */
	private static function overlap_anchor( string $text, array $candidate_terms ): ?array {
		if ( empty( $candidate_terms ) || ! preg_match_all( '/[\p{L}\p{N}]+/u', $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$tokens = $matches[0];
		$best   = null;
		$start  = null;
		$matches_in_run = 0;
		$last_match_index = null;

		foreach ( $tokens as $index => $token ) {
			$normalized = self::normalize_terms( $token[0], 1 );
			$is_stopword = empty( $normalized );
			$is_match = ! $is_stopword && in_array( $normalized[0], $candidate_terms, true );

			if ( $is_match ) {
				if ( null === $start ) {
					$start = $index;
					$matches_in_run = 0;
				}
				++$matches_in_run;
				$last_match_index = $index;
			} elseif ( ! $is_stopword && null !== $start ) {
				$best = self::choose_anchor_run( $tokens, $start, $last_match_index, $matches_in_run, $best, $text );
				$start = null;
				$last_match_index = null;
				$matches_in_run = 0;
			}
		}

		if ( null !== $start ) {
			$best = self::choose_anchor_run( $tokens, $start, $last_match_index, $matches_in_run, $best, $text );
		}

		return $best;
	}

	/** @return array{text:string,offset:int,matches:int,bytes:int}|null */
	private static function choose_anchor_run( array $tokens, int $start, ?int $end, int $matches, ?array $best, string $text ): ?array {
		if ( $matches < 2 || null === $end ) {
			return $best;
		}

		$byte_start = (int) $tokens[ $start ][1];
		$byte_end   = (int) $tokens[ $end ][1] + strlen( $tokens[ $end ][0] );
		$anchor     = substr( $text, $byte_start, $byte_end - $byte_start );
		$candidate  = array( 'text' => $anchor, 'offset' => self::byte_to_character_offset( $text, $byte_start ), 'matches' => $matches, 'bytes' => strlen( $anchor ) );

		if ( null === $best || $candidate['matches'] > $best['matches']
			|| ( $candidate['matches'] === $best['matches'] && $candidate['bytes'] < $best['bytes'] ) ) {
			return $candidate;
		}

		return $best;
	}

	/** @return array<string, mixed> */
	private static function location_value( array $unit, string $anchor, int $offset, int $occurrence, string $draft_hash, string $analysis_id ): array {
		$excerpt = self::string_slice( (string) $unit['insertion_text'], 0, 180 );
		return array(
			'block_client_id' => $unit['client_id'],
			'block_name'      => $unit['block_name'],
			'block_text_hash' => hash( 'sha256', (string) $unit['insertion_text'] ),
			'excerpt'         => $excerpt,
			'context'         => $excerpt,
			'anchor_text'     => $anchor,
			'start'           => $offset,
			'length'          => self::string_length( $anchor ),
			'occurrence'      => $occurrence,
			'draft_hash'      => $draft_hash,
			'analysis_id'     => $analysis_id,
		);
	}

	private static function matches_unresolved_permalink( \WP_Post $target, array $unresolved_urls, string $base_url ): bool {
		if ( empty( $unresolved_urls ) ) {
			return false;
		}

		$normalized = Link_Resolver::normalize_reference( (string) get_permalink( $target ), $base_url );
		return null !== $normalized && isset( $unresolved_urls[ $normalized ] );
	}

	/** @return array<string, mixed> */
	private static function response( string $analysis_id, string $draft_hash, string $index_generation, string $graph_generation, array $suggestions, int $already_linked, int $candidate_count, bool $truncated, float $started_at, int $query_started, float $location_ms ): array {
		$response = array(
			'contract_version' => self::CONTRACT_VERSION,
			'algorithm_version' => self::ALGORITHM_VERSION,
			'analysis_id'      => $analysis_id,
			'draft_hash'       => $draft_hash,
			'index_generation' => $index_generation,
			'graph_generation' => $graph_generation,
			'suggestions'      => array_values( $suggestions ),
			'excluded_already_linked_count' => $already_linked,
			'limits'           => array(
				'candidate_count' => $candidate_count,
				'truncated'       => $truncated,
				'max_location_candidates' => self::MAX_LOCATION_CANDIDATES,
			),
		);
		$encoded = wp_json_encode( $response );
		$maximum_locations = 0;
		foreach ( $suggestions as $suggestion ) {
			$maximum_locations = max( $maximum_locations, count( $suggestion['location_candidates'] ?? array() ) );
		}
		self::$last_metrics = array(
			'query_count'       => get_num_queries() - $query_started,
			'candidate_count'   => $candidate_count,
			'suggestion_count'  => count( $suggestions ),
			'payload_limit'     => self::MAX_PAYLOAD_BYTES,
			'location_selection_ms' => $location_ms,
			'response_bytes'    => is_string( $encoded ) ? strlen( $encoded ) : 0,
			'maximum_location_candidates' => $maximum_locations,
			'elapsed_ms'        => round( ( microtime( true ) - $started_at ) * 1000, 3 ),
		);

		return $response;
	}

	/** @return array<string, int[]>|\WP_Error */
	private static function validate_taxonomies( $value, string $post_type ) {
		if ( ! is_array( $value ) ) {
			return self::invalid( 'The edited taxonomies must be an object.' );
		}

		$clean = array();
		foreach ( $value as $taxonomy => $ids ) {
			if ( ! is_string( $taxonomy ) || sanitize_key( $taxonomy ) !== $taxonomy || ! is_array( $ids ) ) {
				return self::invalid( 'An edited taxonomy value is invalid.' );
			}
			$object = get_taxonomy( $taxonomy );
			if ( ! $object || ! in_array( $post_type, $object->object_type, true ) ) {
				return self::invalid( 'An edited taxonomy does not belong to the source post type.' );
			}
			$term_ids = array();
			foreach ( $ids as $term_id ) {
				if ( ! is_int( $term_id ) || $term_id <= 0 || ! term_exists( $term_id, $taxonomy ) ) {
					return self::invalid( 'An edited taxonomy contains an invalid term ID.' );
				}
				$term_ids[] = $term_id;
			}
			$term_ids = array_values( array_unique( $term_ids ) );
			sort( $term_ids, SORT_NUMERIC );
			$clean[ $taxonomy ] = $term_ids;
		}

		ksort( $clean );
		return $clean;
	}

	private static function is_supported_block( string $block_name ): bool {
		return in_array( $block_name, array_merge( self::TEXT_BLOCKS, self::TABLE_BLOCKS, self::CAPTION_BLOCKS ), true );
	}

	private static function has_exact_keys( array $value, array $keys ): bool {
		$actual = array_keys( $value );
		sort( $actual );
		sort( $keys );
		return $actual === $keys;
	}

	private static function invalid( string $message, int $status = 400, string $code = 'intertexere_invalid_draft' ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	private static function canonical_json( array $value ): string {
		$json = wp_json_encode( $value, self::JSON_FLAGS );
		return is_string( $json ) ? $json : '';
	}

	private static function normalize_phrase( string $text ): string {
		$text = remove_accents( self::lower( $text ) );
		$text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );
		if ( ! is_string( $text ) ) {
			return '';
		}

		$normalized = preg_replace( '/\s+/u', ' ', $text );
		return is_string( $normalized ) ? trim( $normalized ) : '';
	}

	private static function contains_phrase( string $haystack, string $needle ): bool {
		return false !== strpos( ' ' . $haystack . ' ', ' ' . $needle . ' ' );
	}

	private static function target_title_text( \WP_Post $target ): string {
		return trim(
			html_entity_decode(
				wp_strip_all_tags( (string) get_the_title( $target ), false ),
				ENT_QUOTES | ENT_HTML5,
				get_bloginfo( 'charset' ) ?: 'UTF-8'
			)
		);
	}

	private static function valid_utf8( string $value ): bool {
		return 1 === preg_match( '//u', $value );
	}

	private static function lower( string $value ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}

	private static function string_length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}

	private static function string_slice( string $value, int $start, ?int $length = null ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return null === $length ? mb_substr( $value, $start, null, 'UTF-8' ) : mb_substr( $value, $start, $length, 'UTF-8' );
		}
		return null === $length ? substr( $value, $start ) : substr( $value, $start, $length );
	}

	private static function byte_to_character_offset( string $text, int $byte_offset ): int {
		return self::string_length( substr( $text, 0, $byte_offset ) );
	}
}
