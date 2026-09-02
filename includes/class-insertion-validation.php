<?php
/**
 * Read-only authority check for one explicit local editor insertion.
 *
 * @package Intertexere
 */

namespace Intertexere;

final class Insertion_Validation {
	public const CONTRACT_VERSION = 1;
	public const MAX_ANCHOR_CHARACTERS = 200;
	public const MAX_ANCHOR_OCCURRENCE = 16383;
	public const MAX_DRAFT_LINKS = 200;
	public const MAX_DRAFT_LINK_BYTES = 2048;

	/** @var string[] */
	private const INSERTION_BLOCKS = array(
		'core/paragraph',
		'core/heading',
		'core/list-item',
	);

	/** @var array<string, mixed> */
	private static array $last_metrics = array();

	/**
	 * Validate one insertion request without mutating WordPress or plugin state.
	 *
	 * @param mixed $payload Untrusted REST input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function validate( $payload ) {
		$started_at    = microtime( true );
		$query_started = get_num_queries();
		$request       = self::validate_request( $payload );
		if ( is_wp_error( $request ) ) {
			return $request;
		}

		if ( ! self::current_user_can_edit_source( $request['draft'] ) ) {
			return self::error( 'intertexere_insertion_forbidden', 'You are not allowed to validate an insertion for this draft.', 403 );
		}

		$context = self::unit_context( $request['validated_draft'], $request['anchor'] );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		if ( 'ai' === $request['source_kind'] ) {
			$ai_anchor = self::validate_ai_unit( $request, $context );
			if ( is_wp_error( $ai_anchor ) ) {
				return $ai_anchor;
			}
		}

		$anchor_start = self::occurrence_offset(
			$context['text'],
			$request['anchor']['exact_text'],
			$request['anchor']['occurrence']
		);
		if ( false === $anchor_start ) {
			return self::stale( 'The exact anchor occurrence is no longer present.' );
		}
		$anchor_end = $anchor_start + self::length( $request['anchor']['exact_text'] );

		$target_before = self::target_state( $request['target_post_id'] );
		if ( is_wp_error( $target_before ) ) {
			return $target_before;
		}

		do_action( 'intertexere_insertion_validation_after_target_snapshot', $request['target_post_id'], $request );

		$range_state = self::range_link_state(
			$context['content_html'],
			$anchor_start,
			$anchor_end,
			$request['validated_draft']['base_url'],
			$request['validated_draft']['post_id'],
			$request['target_post_id'],
			$target_before['canonical_permalink']
		);
		if ( 'already-linked' === $range_state ) {
			return self::error( 'intertexere_insertion_already_linked', 'This exact phrase already links to the current target.', 409 );
		}
		if ( 'overlap' === $range_state ) {
			return self::error( 'intertexere_insertion_link_overlap', 'The exact phrase overlaps an existing link.', 409 );
		}

		if ( self::draft_has_target( $request['draft_links'], $request['validated_draft'], $request['target_post_id'], $target_before['canonical_permalink'] ) ) {
			return self::error( 'intertexere_insertion_duplicate', 'This draft already contains a link to that target.', 409 );
		}

		$analysis_started = microtime( true );
		$analysis         = Editor_Suggestions::analyze( $request['draft'] );
		$analysis_ms      = round( ( microtime( true ) - $analysis_started ) * 1000, 3 );
		if ( is_wp_error( $analysis ) ) {
			return $analysis;
		}
		if ( ! hash_equals( $analysis['analysis_id'], $request['analysis_id'] ) ) {
			return self::stale( 'The deterministic analysis identity is no longer current.' );
		}

		$member = null;
		foreach ( $analysis['suggestions'] as $suggestion ) {
			if ( (int) $suggestion['target_post_id'] === $request['target_post_id'] ) {
				$member = $suggestion;
				break;
			}
		}
		if ( ! is_array( $member ) ) {
			return self::stale( 'The target is no longer a current deterministic suggestion.' );
		}
		if ( 'deterministic' === $request['source_kind'] && ! self::matches_deterministic_location( $member, $request['anchor'], $anchor_start ) ) {
			return self::stale( 'The exact anchor no longer matches the current deterministic location.' );
		}

		do_action( 'intertexere_insertion_validation_before_final_target', $request['target_post_id'], $request );

		$target_after = self::target_state( $request['target_post_id'] );
		if ( is_wp_error( $target_after ) || $target_before !== $target_after ) {
			return self::stale( 'The target changed during insertion validation.' );
		}

		$analysis_metrics = Editor_Suggestions::last_metrics();
		$total_queries    = get_num_queries() - $query_started;
		$total_ms         = round( ( microtime( true ) - $started_at ) * 1000, 3 );
		self::$last_metrics = array(
			'total_query_count'         => $total_queries,
			'total_ms'                  => $total_ms,
			'deterministic_query_count' => (int) ( $analysis_metrics['query_count'] ?? 0 ),
			'deterministic_ms'          => $analysis_ms,
			'insertion_query_count'     => max( 0, $total_queries - (int) ( $analysis_metrics['query_count'] ?? 0 ) ),
			'insertion_ms'              => max( 0.0, round( $total_ms - $analysis_ms, 3 ) ),
		);

		return array(
			'contract_version' => self::CONTRACT_VERSION,
			'analysis_id'      => $analysis['analysis_id'],
			'draft_hash'       => $analysis['draft_hash'],
			'target_post_id'   => $request['target_post_id'],
			'current_permalink'=> $target_after['canonical_permalink'],
			'source_kind'      => $request['source_kind'],
			'block'            => array(
				'client_id'       => $request['anchor']['block_client_id'],
				'name'            => $request['anchor']['block_name'],
				'attribute'       => 'content',
				'markup_identity' => hash( 'sha256', $context['markup'] ),
				'text_identity'   => hash( 'sha256', $context['text'] ),
			),
			'anchor'           => array(
				'exact_text' => $request['anchor']['exact_text'],
				'occurrence' => $request['anchor']['occurrence'],
				'start'      => $anchor_start,
				'length'     => self::length( $request['anchor']['exact_text'] ),
			),
		);
	}

	/** @return array<string, mixed> */
	public static function last_metrics(): array {
		return self::$last_metrics;
	}

	/** @return array<string, mixed>|\WP_Error */
	private static function validate_request( $payload ) {
		if ( ! is_array( $payload ) || ! self::has_exact_keys( $payload, array( 'draft', 'analysis_id', 'target_post_id', 'source_kind', 'anchor', 'draft_links' ) ) ) {
			return self::invalid( 'The insertion validation request contains missing or unknown fields.' );
		}
		if ( ! is_array( $payload['draft'] ) ) {
			return self::invalid( 'The insertion draft is invalid.' );
		}

		$validated_draft = Editor_Suggestions::validate_payload( $payload['draft'] );
		if ( is_wp_error( $validated_draft ) ) {
			return $validated_draft;
		}
		if ( ! is_string( $payload['analysis_id'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $payload['analysis_id'] ) ) {
			return self::invalid( 'The deterministic analysis identity is invalid.' );
		}
		if ( ! is_int( $payload['target_post_id'] ) || $payload['target_post_id'] <= 0 ) {
			return self::invalid( 'The target post ID must be a positive integer.' );
		}
		if ( $payload['target_post_id'] === $validated_draft['post_id'] ) {
			return self::invalid( 'A source post cannot link to itself.', 409, 'intertexere_insertion_self_target' );
		}
		if ( ! is_string( $payload['source_kind'] ) || ! in_array( $payload['source_kind'], array( 'deterministic', 'ai' ), true ) ) {
			return self::invalid( 'The insertion source kind is invalid.' );
		}

		$anchor = self::validate_anchor( $payload['anchor'], $payload['source_kind'] );
		if ( is_wp_error( $anchor ) ) {
			return $anchor;
		}
		$draft_links = self::validate_draft_links( $payload['draft_links'] );
		if ( is_wp_error( $draft_links ) ) {
			return $draft_links;
		}

		return array(
			'draft'           => $payload['draft'],
			'validated_draft' => $validated_draft,
			'analysis_id'     => $payload['analysis_id'],
			'target_post_id'  => $payload['target_post_id'],
			'source_kind'     => $payload['source_kind'],
			'anchor'          => $anchor,
			'draft_links'     => $draft_links,
		);
	}

	/** @return array<string, mixed>|\WP_Error */
	private static function validate_anchor( $anchor, string $source_kind ) {
		if ( ! is_array( $anchor ) || ! self::has_exact_keys( $anchor, array( 'block_client_id', 'block_name', 'exact_text', 'occurrence', 'unit_key' ) ) ) {
			return self::invalid( 'The exact anchor evidence contains missing or unknown fields.' );
		}
		if ( ! is_string( $anchor['block_client_id'] ) || '' === $anchor['block_client_id'] || strlen( $anchor['block_client_id'] ) > 128
			|| 1 !== preg_match( '/^[A-Za-z0-9_.:-]+$/', $anchor['block_client_id'] ) ) {
			return self::invalid( 'The anchor block client ID is invalid.' );
		}
		if ( ! is_string( $anchor['block_name'] ) || ! in_array( $anchor['block_name'], self::INSERTION_BLOCKS, true ) ) {
			return self::invalid( 'This block is not supported for insertion.', 400, 'intertexere_insertion_unsupported' );
		}
		if ( ! is_string( $anchor['exact_text'] ) || '' === $anchor['exact_text'] || ! self::valid_utf8( $anchor['exact_text'] )
			|| self::length( $anchor['exact_text'] ) > self::MAX_ANCHOR_CHARACTERS ) {
			return self::invalid( 'The exact anchor text is invalid or exceeds the limit.' );
		}
		if ( ! is_int( $anchor['occurrence'] ) || $anchor['occurrence'] < 0 || $anchor['occurrence'] > self::MAX_ANCHOR_OCCURRENCE ) {
			return self::invalid( 'The exact anchor occurrence is invalid.' );
		}
		if ( 'ai' === $source_kind ) {
			if ( ! is_string( $anchor['unit_key'] ) || 1 !== preg_match( '/^u[1-8]$/', $anchor['unit_key'] ) ) {
				return self::invalid( 'The AI unit key is invalid.' );
			}
		} elseif ( null !== $anchor['unit_key'] ) {
			return self::invalid( 'A deterministic anchor cannot claim an AI unit key.' );
		}

		return $anchor;
	}

	/** @return string[]|\WP_Error */
	private static function validate_draft_links( $links ) {
		if ( ! is_array( $links ) || array_values( $links ) !== $links ) {
			return self::invalid( 'The draft link references must be a JSON list.' );
		}
		if ( count( $links ) > self::MAX_DRAFT_LINKS ) {
			return self::invalid( 'The draft contains too many link references.', 413, 'intertexere_insertion_link_limit' );
		}
		$validated = array();
		foreach ( $links as $link ) {
			if ( ! is_string( $link ) || '' === trim( $link ) || strlen( $link ) > self::MAX_DRAFT_LINK_BYTES || ! self::valid_utf8( $link ) ) {
				return self::invalid( 'A draft link reference is invalid or oversized.' );
			}
			$validated[] = $link;
		}
		return $validated;
	}

	/** @return array<string, mixed>|\WP_Error */
	private static function unit_context( array $draft, array $anchor ) {
		$matches = array_values(
			array_filter(
				$draft['units'],
				static function ( array $unit ) use ( $anchor ): bool {
					return $unit['client_id'] === $anchor['block_client_id'];
				}
			)
		);
		if ( 1 !== count( $matches ) || $matches[0]['block_name'] !== $anchor['block_name'] ) {
			return self::stale( 'The exact insertion block is no longer current.' );
		}

		$blocks = parse_blocks( $matches[0]['markup'] );
		$block  = reset( $blocks );
		if ( ! is_array( $block ) || $block['blockName'] !== $anchor['block_name'] ) {
			return self::stale( 'The insertion block cannot be verified.' );
		}
		$content_html = self::direct_content_html( $anchor['block_name'], (string) $block['innerHTML'] );
		if ( null === $content_html ) {
			return self::error( 'intertexere_insertion_malformed_block', 'The insertion block has malformed RichText content.', 409 );
		}

		return array(
			'markup'       => $matches[0]['markup'],
			'content_html' => $content_html,
			'text'         => self::plain_text( $content_html ),
		);
	}

	/** @return true|\WP_Error */
	private static function validate_ai_unit( array $request, array $context ) {
		$ai_context = Editor_Suggestions::context_for_ai( $request['draft'] );
		if ( is_wp_error( $ai_context ) ) {
			return $ai_context;
		}
		$index = (int) substr( $request['anchor']['unit_key'], 1 ) - 1;
		$unit  = $ai_context['text_units'][ $index ] ?? null;
		if ( ! is_array( $unit )
			|| $unit['client_id'] !== $request['anchor']['block_client_id']
			|| $unit['block_name'] !== $request['anchor']['block_name']
			|| $unit['text'] !== $context['text'] ) {
			return self::stale( 'The AI anchor no longer maps to the current deterministic unit.' );
		}
		return true;
	}

	/** @return array<string, mixed>|\WP_Error */
	private static function target_state( int $target_post_id ) {
		$post = get_post( $target_post_id );
		if ( ! $post instanceof \WP_Post ) {
			return self::error( 'intertexere_insertion_target_unavailable', 'The link target is no longer available.', 409 );
		}
		$permalink = get_permalink( $post );
		$eligible  = Eligibility::is_eligible( $post );
		if ( ! $eligible || ! is_string( $permalink ) || '' === $permalink || ! self::valid_utf8( $permalink )
			|| strlen( $permalink ) > self::MAX_DRAFT_LINK_BYTES || ! Link_Resolver::is_internal_url( $permalink ) ) {
			return self::error( 'intertexere_insertion_target_unavailable', 'The link target is no longer eligible or public.', 409 );
		}

		return array(
			'post_id'             => (int) $post->ID,
			'title'               => (string) get_the_title( $post ),
			'canonical_permalink' => $permalink,
			'post_type'           => (string) $post->post_type,
			'post_status'         => (string) $post->post_status,
			'password'            => (string) $post->post_password,
			'post_name'           => (string) $post->post_name,
			'post_parent'         => (int) $post->post_parent,
			'eligible'            => true,
		);
	}

	private static function draft_has_target( array $links, array $draft, int $target_post_id, string $target_permalink ): bool {
		foreach ( $links as $href ) {
			if ( self::reference_targets_post( $href, $draft['base_url'], $draft['post_id'], $target_post_id, $target_permalink ) ) {
				return true;
			}
		}
		return false;
	}

	private static function matches_deterministic_location( array $suggestion, array $anchor, int $anchor_start ): bool {
		$location = $suggestion['location'] ?? null;
		return is_array( $location )
			&& ( $location['block_client_id'] ?? null ) === $anchor['block_client_id']
			&& ( $location['block_name'] ?? null ) === $anchor['block_name']
			&& ( $location['anchor_text'] ?? null ) === $anchor['exact_text']
			&& ( $location['start'] ?? null ) === $anchor_start;
	}

	private static function range_link_state( string $html, int $start, int $end, string $base_url, int $source_post_id, int $target_post_id, string $target_permalink ): string {
		if ( ! preg_match_all( '#<a\b[^>]*>(.*?)</a>#is', $html, $matches, PREG_OFFSET_CAPTURE ) ) {
			return 'clear';
		}

		$cursor      = 0;
		$text_cursor = 0;
		foreach ( $matches[0] as $index => $match ) {
			$text_cursor += self::length( self::plain_text( substr( $html, $cursor, $match[1] - $cursor ) ) );
			$link_text   = self::plain_text( $matches[1][ $index ][0] );
			$link_start  = $text_cursor;
			$link_end    = $link_start + self::length( $link_text );
			$cursor      = $match[1] + strlen( $match[0] );
			$text_cursor = $link_end;

			if ( $start >= $link_end || $end <= $link_start ) {
				continue;
			}

			$processor = new \WP_HTML_Tag_Processor( $match[0] );
			$href      = $processor->next_tag( array( 'tag_name' => 'A' ) ) ? $processor->get_attribute( 'href' ) : null;
			if ( $start === $link_start && $end === $link_end && is_string( $href )
				&& self::reference_targets_post( $href, $base_url, $source_post_id, $target_post_id, $target_permalink ) ) {
				return 'already-linked';
			}
			return 'overlap';
		}

		return 'clear';
	}

	private static function reference_targets_post( string $href, string $base_url, int $source_post_id, int $target_post_id, string $target_permalink ): bool {
		if ( hash_equals( $target_permalink, $href ) ) {
			return true;
		}
		$resolved = Link_Resolver::resolve_from_base( $href, $base_url, $source_post_id );
		if ( null === $resolved ) {
			return false;
		}
		$canonical = Link_Resolver::normalize_reference( $target_permalink, $base_url );
		if ( null !== $canonical && hash_equals( $canonical, $resolved['normalized_url'] ) ) {
			return true;
		}
		if ( null !== $resolved['target_post_id'] ) {
			return (int) $resolved['target_post_id'] === $target_post_id;
		}
		return false;
	}

	private static function direct_content_html( string $block_name, string $inner_html ): ?string {
		$patterns = array(
			'core/paragraph' => '#^\s*<p\b[^>]*>(.*)</p>\s*$#is',
			'core/heading'   => '#^\s*<h[1-6]\b[^>]*>(.*)</h[1-6]>\s*$#is',
			'core/list-item' => '#^\s*<li\b[^>]*>(.*)</li>\s*$#is',
		);
		if ( ! isset( $patterns[ $block_name ] ) || 1 !== preg_match( $patterns[ $block_name ], $inner_html, $match ) ) {
			return null;
		}
		return $match[1];
	}

	private static function plain_text( string $html ): string {
		return html_entity_decode( wp_strip_all_tags( $html, true ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
	}

	/** @return int|false */
	private static function occurrence_offset( string $text, string $needle, int $occurrence ) {
		$byte_offset = 0;
		for ( $index = 0; $index <= $occurrence; ++$index ) {
			$found = strpos( $text, $needle, $byte_offset );
			if ( false === $found ) {
				return false;
			}
			if ( $index === $occurrence ) {
				return self::length( substr( $text, 0, $found ) );
			}
			$byte_offset = $found + strlen( $needle );
		}
		return false;
	}

	private static function current_user_can_edit_source( array $draft ): bool {
		if ( ! Editor_Suggestions::is_supported_source_type( $draft['post_type'] ) ) {
			return false;
		}
		if ( $draft['post_id'] > 0 ) {
			return current_user_can( 'edit_post', $draft['post_id'] );
		}
		$object = get_post_type_object( $draft['post_type'] );
		return $object && current_user_can( $object->cap->edit_posts );
	}

	private static function has_exact_keys( array $value, array $keys ): bool {
		$actual = array_keys( $value );
		sort( $actual );
		sort( $keys );
		return $actual === $keys;
	}

	private static function valid_utf8( string $value ): bool {
		return 1 === preg_match( '//u', $value );
	}

	private static function length( string $value ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $value, 'UTF-8' );
		}
		$length = preg_match_all( '/./us', $value, $matches );
		return false === $length ? strlen( $value ) : $length;
	}

	private static function invalid( string $message, int $status = 400, string $code = 'intertexere_insertion_invalid_request' ): \WP_Error {
		return self::error( $code, $message, $status );
	}

	private static function stale( string $message ): \WP_Error {
		return self::error( 'intertexere_insertion_stale', $message, 409 );
	}

	private static function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
