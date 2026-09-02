<?php
/**
 * Deterministic internal URL classification and WordPress target resolution.
 *
 * @package Intertexere
 */

namespace Intertexere;

final class Link_Resolver {
	/**
	 * Resolve one href attribute value already decoded by WordPress Core.
	 *
	 * Production callers pass the value returned by
	 * WP_HTML_Tag_Processor::get_attribute(). This method must not receive raw
	 * HTML attribute source or decode character references a second time.
	 *
	 * External, fragment-only, empty, malformed, and non-web references return
	 * null. Internal references remain useful even when WordPress cannot map them
	 * to a post, so unresolved results have a null target_post_id.
	 *
	 * @return array{normalized_url:string,target_post_id:?int,target_identity_hash:string,is_self:int}|null
	 */
	public static function resolve( string $href, \WP_Post $source ): ?array {
		return self::resolve_from_base( $href, (string) get_permalink( $source ), (int) $source->ID );
	}

	/**
	 * Resolve an already-decoded href against an explicit trusted base URL.
	 *
	 * This is used for unsaved editor drafts, including new posts that do not
	 * yet have a WordPress permalink. Callers must pass the attribute value
	 * returned by WordPress' HTML parser without decoding it again.
	 *
	 * @return array{normalized_url:string,target_post_id:?int,target_identity_hash:string,is_self:int}|null
	 */
	public static function resolve_from_base( string $href, string $base, int $source_post_id = 0 ): ?array {
		$href = trim( $href );

		if ( '' === $href || '#' === substr( $href, 0, 1 ) ) {
			return null;
		}

		if ( preg_match( '/^([a-z][a-z0-9+.-]*):/i', $href, $scheme_match )
			&& ! in_array( strtolower( $scheme_match[1] ), array( 'http', 'https' ), true ) ) {
			return null;
		}

		$normalized = self::normalize_reference( $href, $base );
		if ( null === $normalized || ! self::is_internal_url( $normalized ) ) {
			return null;
		}

		$target_id = self::resolve_query_post_id( $normalized );
		if ( 0 === $target_id ) {
			$target_id = (int) url_to_postid( $normalized );
		}
		if ( $target_id > 0 && ! self::is_resolvable_post( $target_id ) ) {
			$target_id = 0;
		}

		if ( 0 === $target_id ) {
			$target_id = self::resolve_old_slug( $normalized );
		}

		$identity = $target_id > 0 ? 'post:' . $target_id : 'url:' . $normalized;

		return array(
			'normalized_url'      => $normalized,
			'target_post_id'      => $target_id > 0 ? $target_id : null,
			'target_identity_hash'=> hash( 'sha256', $identity ),
			'is_self'             => $source_post_id > 0 && $target_id === $source_post_id ? 1 : 0,
		);
	}

	/**
	 * Normalize an already-decoded URL reference without resolving post identity.
	 */
	public static function normalize_reference( string $reference, string $base ): ?string {
		return self::resolve_reference( trim( $reference ), $base );
	}

	/**
	 * Determine whether an absolute normalized URL belongs to this WordPress site.
	 */
	public static function is_internal_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
			return false;
		}

		$origin = self::origin_key( $parts );
		foreach ( array( home_url( '/' ), site_url( '/' ) ) as $site_url ) {
			$site_parts = wp_parse_url( $site_url );
			if ( is_array( $site_parts ) && $origin === self::origin_key( $site_parts ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve an RFC 3986-style URL reference against the source permalink.
	 */
	private static function resolve_reference( string $reference, string $base ): ?string {
		$hash_position = strpos( $reference, '#' );
		if ( false !== $hash_position ) {
			$reference = substr( $reference, 0, $hash_position );
		}

		if ( '' === $reference ) {
			return null;
		}

		if ( preg_match( '#^https?://#i', $reference ) ) {
			return self::normalize_absolute_url( $reference );
		}

		$base_parts = wp_parse_url( $base );
		if ( ! is_array( $base_parts ) || empty( $base_parts['scheme'] ) || empty( $base_parts['host'] ) ) {
			return null;
		}

		if ( 0 === strpos( $reference, '//' ) ) {
			return self::normalize_absolute_url( strtolower( (string) $base_parts['scheme'] ) . ':' . $reference );
		}

		$query         = null;
		$query_position = strpos( $reference, '?' );
		if ( false !== $query_position ) {
			$query     = substr( $reference, $query_position + 1 );
			$reference = substr( $reference, 0, $query_position );
		}

		$base_path = isset( $base_parts['path'] ) && '' !== $base_parts['path'] ? (string) $base_parts['path'] : '/';
		if ( '' === $reference ) {
			$path = $base_path;
			if ( null === $query && isset( $base_parts['query'] ) ) {
				$query = (string) $base_parts['query'];
			}
		} elseif ( '/' === substr( $reference, 0, 1 ) ) {
			$path = $reference;
		} else {
			$last_slash = strrpos( $base_path, '/' );
			$directory  = false === $last_slash ? '/' : substr( $base_path, 0, $last_slash + 1 );
			$path       = $directory . $reference;
		}

		$authority = strtolower( (string) $base_parts['host'] );
		if ( isset( $base_parts['port'] ) && ! self::is_default_port( strtolower( (string) $base_parts['scheme'] ), (int) $base_parts['port'] ) ) {
			$authority .= ':' . (int) $base_parts['port'];
		}

		$url = strtolower( (string) $base_parts['scheme'] ) . '://' . $authority . self::remove_dot_segments( $path );
		if ( null !== $query ) {
			$url .= '?' . $query;
		}

		return self::normalize_absolute_url( $url );
	}

	/**
	 * Normalize only URL components whose equivalence is safe for graph identity.
	 */
	private static function normalize_absolute_url( string $url ): ?string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
			return null;
		}

		$normalized = $scheme . '://' . strtolower( (string) $parts['host'] );
		if ( isset( $parts['port'] ) && ! self::is_default_port( $scheme, (int) $parts['port'] ) ) {
			$normalized .= ':' . (int) $parts['port'];
		}

		$path        = isset( $parts['path'] ) && '' !== $parts['path'] ? (string) $parts['path'] : '/';
		$normalized .= self::remove_dot_segments( $path );
		if ( array_key_exists( 'query', $parts ) ) {
			$normalized .= '?' . (string) $parts['query'];
		}

		return $normalized;
	}

	/**
	 * Use the retained Core old-slug alias only for one exact path candidate.
	 */
	private static function resolve_old_slug( string $url ): int {
		global $wpdb;

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$slug = rawurldecode( basename( untrailingslashit( $path ) ) );
		if ( '' === $slug ) {
			return 0;
		}

		$ids = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT p.ID
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
					WHERE pm.meta_key = '_wp_old_slug'
					AND pm.meta_value = %s
					AND p.post_status NOT IN ('trash', 'auto-draft')",
					$slug
				)
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$candidates = array();
		foreach ( $ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post || is_post_type_hierarchical( $post->post_type ) || ! self::is_resolvable_post( $post_id ) ) {
				continue;
			}

			$current_path = (string) wp_parse_url( get_permalink( $post ), PHP_URL_PATH );
			$current_slug = basename( untrailingslashit( $current_path ) );
			$old_path     = substr( untrailingslashit( $current_path ), 0, -strlen( $current_slug ) ) . rawurlencode( $slug );

			if ( untrailingslashit( $path ) === untrailingslashit( $old_path ) ) {
				$candidates[] = $post_id;
			}
		}

		$candidates = array_values( array_unique( $candidates ) );

		return 1 === count( $candidates ) ? (int) $candidates[0] : 0;
	}

	/**
	 * Resolve Core's explicit query-style post identifiers deterministically.
	 *
	 * Core checks p, page_id, and attachment_id before rewrite rules. Doing the
	 * same explicitly also keeps these URLs resolvable when a site uses plain
	 * permalinks and has no rewrite rules to consult.
	 */
	private static function resolve_query_post_id( string $url ): int {
		if ( ! preg_match( '#[?&](p|page_id|attachment_id)=(\d+)#', $url, $matches ) ) {
			return 0;
		}

		$post_id = absint( $matches[2] );

		return $post_id > 0 && self::is_resolvable_post( $post_id ) ? $post_id : 0;
	}

	private static function is_resolvable_post( int $post_id ): bool {
		$post = get_post( $post_id );

		return $post instanceof \WP_Post
			&& ! wp_is_post_revision( $post_id )
			&& ! wp_is_post_autosave( $post_id );
	}

	/**
	 * Treat scheme and default-port differences as the same configured origin.
	 * Non-default ports remain part of the origin identity.
	 *
	 * @param array<string, mixed> $parts Parsed URL parts.
	 */
	private static function origin_key( array $parts ): string {
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		if ( $port > 0 && self::is_default_port( $scheme, $port ) ) {
			$port = 0;
		}

		return $host . ( $port > 0 ? ':' . $port : '' );
	}

	private static function is_default_port( string $scheme, int $port ): bool {
		return ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port );
	}

	/**
	 * RFC 3986 remove-dot-segments algorithm without changing other path bytes.
	 */
	private static function remove_dot_segments( string $path ): string {
		$input  = '' === $path ? '/' : $path;
		$output = '';

		while ( '' !== $input ) {
			if ( 0 === strpos( $input, '../' ) ) {
				$input = substr( $input, 3 );
			} elseif ( 0 === strpos( $input, './' ) ) {
				$input = substr( $input, 2 );
			} elseif ( 0 === strpos( $input, '/./' ) ) {
				$input = '/' . substr( $input, 3 );
			} elseif ( '/.' === $input ) {
				$input = '/';
			} elseif ( 0 === strpos( $input, '/../' ) ) {
				$input  = '/' . substr( $input, 4 );
				$output = preg_replace( '#/[^/]*$#', '', $output );
				$output = is_string( $output ) ? $output : '';
			} elseif ( '/..' === $input ) {
				$input  = '/';
				$output = preg_replace( '#/[^/]*$#', '', $output );
				$output = is_string( $output ) ? $output : '';
			} elseif ( '.' === $input || '..' === $input ) {
				$input = '';
			} else {
				$length = 0 === strpos( $input, '/' ) ? strpos( $input, '/', 1 ) : strpos( $input, '/' );
				if ( false === $length ) {
					$output .= $input;
					$input   = '';
				} else {
					$output .= substr( $input, 0, $length );
					$input   = substr( $input, $length );
				}
			}
		}

		return '' === $output ? '/' : $output;
	}
}
