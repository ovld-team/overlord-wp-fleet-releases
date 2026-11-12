<?php
/**
 * Shared helpers for the 1.7.0 migration modules (export/import, menus, media push,
 * options sync): exact-bytes meta transport, attachment-reference discovery, id remap
 * inside Elementor JSON, host rewrite, server-side snapshot files.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ovp_Migrate_Util {

	/** Meta keys never exported/imported by default (regenerated or per-site state). */
	public const DEFAULT_META_EXCLUDE = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_elementor_css',
		'_elementor_element_cache',
		'_elementor_page_assets',
	);

	/** Keys holding one attachment id as a plain number string. */
	public const IMAGE_ID_KEYS = array(
		'_thumbnail_id',
		'_yoast_wpseo_opengraph-image-id',
		'_yoast_wpseo_twitter-image-id',
	);

	// -- transport -----------------------------------------------------------

	/**
	 * @return array<string, mixed> {raw} or {b64} when not valid UTF-8, {null:true} for SQL NULL.
	 */
	public static function pack( ?string $value ): array {
		if ( null === $value ) {
			return array( 'null' => true );
		}
		if ( ! mb_check_encoding( $value, 'UTF-8' ) ) {
			return array( 'b64' => base64_encode( $value ) );
		}
		return array( 'raw' => $value );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public static function unpack( array $item ): ?string {
		if ( ! empty( $item['null'] ) ) {
			return null;
		}
		if ( isset( $item['b64'] ) && is_string( $item['b64'] ) ) {
			$dec = base64_decode( $item['b64'], true );
			return false === $dec ? '' : $dec;
		}
		return isset( $item['raw'] ) ? (string) $item['raw'] : '';
	}

	/**
	 * Server-side store for before-snapshots and previous values.
	 */
	public static function backup_dir(): string {
		$dir = Ovp_Fatal::store_dir() . '/backups';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	/**
	 * @param array<string, mixed> $record
	 * @return string path relative to wp-content
	 */
	public static function snapshot_append( string $batch, array $record ): string {
		$batch = preg_replace( '/[^A-Za-z0-9_-]/', '', $batch ) ?: gmdate( 'Ymd\THis\Z' );
		$file  = self::backup_dir() . '/' . $batch . '.jsonl';
		$line  = wp_json_encode( $record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( is_string( $line ) ) {
			file_put_contents( $file, $line . "\n", FILE_APPEND | LOCK_EX );
		}
		return 'uploads/mcp-bridge/backups/' . basename( $file );
	}

	// -- attachment references ------------------------------------------------

	/**
	 * Decode Elementor JSON (objects stay stdClass so {} vs [] survives) and prove the
	 * round-trip is byte-exact for some flag set. Returns null when it is not.
	 *
	 * @return array{0: mixed, 1: int}|null decoded tree + json_encode flags
	 */
	public static function decode_exact( string $raw ): ?array {
		$tree = json_decode( $raw );
		if ( JSON_ERROR_NONE !== json_last_error() || ! ( is_array( $tree ) || is_object( $tree ) ) ) {
			return null;
		}
		foreach ( array( 0, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) as $base ) {
			foreach ( array( 0, JSON_PRESERVE_ZERO_FRACTION ) as $extra ) {
				$flags = $base | $extra;
				if ( json_encode( $tree, $flags ) === $raw ) {
					return array( $tree, $flags );
				}
			}
		}
		return null;
	}

	/**
	 * Visit every {id, url} media object in a decoded Elementor tree. The callback may
	 * change $node->id.
	 *
	 * @param callable(object): void $fn
	 */
	public static function walk_media( mixed $node, callable $fn ): void {
		if ( is_array( $node ) ) {
			foreach ( $node as $child ) {
				self::walk_media( $child, $fn );
			}
			return;
		}
		if ( ! is_object( $node ) ) {
			return;
		}
		if ( property_exists( $node, 'id' ) && property_exists( $node, 'url' ) && is_string( $node->url ) ) {
			$fn( $node );
		}
		foreach ( get_object_vars( $node ) as $child ) {
			self::walk_media( $child, $fn );
		}
	}

	/**
	 * @return array<int, int> attachment ids referenced by {id,url} objects.
	 */
	public static function elementor_ids( string $raw ): array {
		$tree = json_decode( $raw );
		if ( ! ( is_array( $tree ) || is_object( $tree ) ) ) {
			return array();
		}
		$ids = array();
		self::walk_media(
			$tree,
			static function ( object $node ) use ( &$ids ): void {
				$id = $node->id;
				if ( ( is_int( $id ) && $id > 0 ) || ( is_string( $id ) && ctype_digit( $id ) && (int) $id > 0 ) ) {
					$ids[] = (int) $id;
				}
			}
		);
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Remap {id,url} ids inside Elementor JSON. Keeps int vs string typing.
	 *
	 * @param array<int, int> $id_map
	 * @param array<int, int> $unmapped collected ids with no mapping
	 * @return string|WP_Error new JSON string
	 */
	public static function remap_elementor( string $raw, array $id_map, array &$unmapped ): string|WP_Error {
		$exact = self::decode_exact( $raw );
		if ( null === $exact ) {
			return new WP_Error( 'mcp_bridge_elementor_inexact', '_elementor_data does not round-trip byte-exact through PHP JSON; refusing to rewrite ids.' );
		}
		list( $tree, $flags ) = $exact;
		$changed              = false;
		self::walk_media(
			$tree,
			static function ( object $node ) use ( $id_map, &$unmapped, &$changed ): void {
				$id = $node->id;
				if ( is_int( $id ) && $id > 0 ) {
					$old = $id;
				} elseif ( is_string( $id ) && ctype_digit( $id ) && (int) $id > 0 ) {
					$old = (int) $id;
				} else {
					return;
				}
				if ( ! isset( $id_map[ $old ] ) ) {
					$unmapped[] = $old;
					return;
				}
				if ( $id_map[ $old ] !== $old ) {
					$node->id = is_int( $id ) ? $id_map[ $old ] : (string) $id_map[ $old ];
					$changed  = true;
				}
			}
		);
		if ( ! $changed ) {
			return $raw;
		}
		$out = json_encode( $tree, $flags );
		return false === $out ? new WP_Error( 'mcp_bridge_elementor_inexact', 'Could not re-encode _elementor_data.' ) : $out;
	}

	/**
	 * Attachment ids found in post_content (classic/blocks/gallery shortcode).
	 *
	 * @return array<int, int>
	 */
	public static function content_ids( string $content ): array {
		$ids = array();
		if ( preg_match_all( '/wp-image-(\d+)/', $content, $m ) ) {
			foreach ( $m[1] as $v ) {
				$ids[] = (int) $v;
			}
		}
		if ( preg_match_all( '/<!--\s*wp:image\s+\{[^}]*"id":(\d+)/', $content, $m ) ) {
			foreach ( $m[1] as $v ) {
				$ids[] = (int) $v;
			}
		}
		if ( preg_match_all( '/\[gallery[^\]]*\bids=["\']([\d,\s]+)["\']/', $content, $m ) ) {
			foreach ( $m[1] as $list ) {
				foreach ( explode( ',', $list ) as $v ) {
					if ( (int) trim( $v ) > 0 ) {
						$ids[] = (int) trim( $v );
					}
				}
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * @param array<int, int> $id_map
	 * @param array<int, int> $unmapped
	 */
	public static function remap_content( string $content, array $id_map, array &$unmapped ): string {
		$content = (string) preg_replace_callback(
			'/wp-image-(\d+)/',
			static function ( array $m ) use ( $id_map, &$unmapped ): string {
				$old = (int) $m[1];
				if ( isset( $id_map[ $old ] ) ) {
					return 'wp-image-' . $id_map[ $old ];
				}
				$unmapped[] = $old;
				return $m[0];
			},
			$content
		);
		$content = (string) preg_replace_callback(
			'/(<!--\s*wp:image\s+\{[^}]*"id":)(\d+)/',
			static function ( array $m ) use ( $id_map, &$unmapped ): string {
				$old = (int) $m[2];
				if ( isset( $id_map[ $old ] ) ) {
					return $m[1] . $id_map[ $old ];
				}
				$unmapped[] = $old;
				return $m[0];
			},
			$content
		);
		return (string) preg_replace_callback(
			'/(\[gallery[^\]]*\bids=["\'])([\d,\s]+)(["\'])/',
			static function ( array $m ) use ( $id_map, &$unmapped ): string {
				$parts = array();
				foreach ( explode( ',', $m[2] ) as $v ) {
					$old = (int) trim( $v );
					if ( $old < 1 ) {
						continue;
					}
					if ( isset( $id_map[ $old ] ) ) {
						$parts[] = (string) $id_map[ $old ];
					} else {
						$unmapped[] = $old;
						$parts[]    = (string) $old;
					}
				}
				return $m[1] . implode( ',', $parts ) . $m[3];
			},
			$content
		);
	}

	/**
	 * Replace host URL variants (https/http/protocol-relative, JSON-escaped too).
	 * Serialized values are not touched by callers (length prefixes).
	 *
	 * @param array<string, string> $hosts from host => to host
	 */
	public static function rewrite_hosts( string $value, array $hosts ): string {
		foreach ( $hosts as $from => $to ) {
			$from = trim( $from );
			$to   = trim( $to );
			if ( '' === $from || '' === $to ) {
				continue;
			}
			foreach ( array( 'https://', 'http://', '//' ) as $scheme ) {
				$value = str_replace( $scheme . $from, $scheme . $to, $value );
				$value = str_replace( str_replace( '/', '\\/', $scheme . $from ), str_replace( '/', '\\/', $scheme . $to ), $value );
			}
		}
		return $value;
	}

	/**
	 * @param mixed $raw
	 * @return array<string, string>
	 */
	public static function host_map( mixed $raw ): array {
		$out = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $from => $to ) {
				if ( is_string( $from ) && is_string( $to ) && '' !== $from && '' !== $to ) {
					$out[ strtolower( $from ) ] = strtolower( $to );
				}
			}
		}
		return $out;
	}

	/**
	 * @param mixed $raw
	 * @return array<int, int> old => new (positive ints only)
	 */
	public static function id_map( mixed $raw ): array {
		$out = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $old => $new ) {
				if ( is_numeric( $old ) && is_numeric( $new ) && (int) $old > 0 && (int) $new > 0 ) {
					$out[ (int) $old ] = (int) $new;
				}
			}
		}
		return $out;
	}

	// -- export ----------------------------------------------------------------

	/**
	 * Lossless export of one post: row, ALL postmeta raw (meta_id order, duplicates kept),
	 * every taxonomy term, referenced attachment ids, parent.
	 *
	 * @param array<int, string> $meta_exclude
	 * @param array<int, string> $include meta|terms|attachments
	 * @return array<string, mixed>|null
	 */
	public static function export_post( int $post_id, array $meta_exclude, array $include ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID = %d", $post_id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$post = array();
		$b64  = array();
		foreach ( $row as $col => $val ) {
			$val = null === $val ? '' : (string) $val;
			if ( mb_check_encoding( $val, 'UTF-8' ) ) {
				$post[ (string) $col ] = $val;
			} else {
				$b64[ (string) $col ] = base64_encode( $val );
			}
		}
		$out = array(
			'id'   => $post_id,
			'post' => $post,
		);
		if ( array() !== $b64 ) {
			$out['post_b64'] = $b64;
		}

		$refs = array();
		$meta = array();
		if ( in_array( 'meta', $include, true ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id ASC", $post_id ), ARRAY_A );
			foreach ( is_array( $rows ) ? $rows : array() as $m ) {
				$key = (string) $m['meta_key'];
				if ( in_array( $key, $meta_exclude, true ) ) {
					continue;
				}
				$val  = null === $m['meta_value'] ? null : (string) $m['meta_value'];
				$item = array( 'key' => $key ) + self::pack( $val );
				if ( null !== $val ) {
					$item['serialized']  = is_serialized( $val );
					$item['has_dynamic'] = str_contains( $val, '__dynamic__' );
				}
				$meta[] = $item;
				if ( null === $val ) {
					continue;
				}
				if ( in_array( $key, self::IMAGE_ID_KEYS, true ) && ctype_digit( $val ) ) {
					$refs[ (int) $val ][] = $key;
				} elseif ( '_product_image_gallery' === $key ) {
					foreach ( explode( ',', $val ) as $v ) {
						if ( (int) $v > 0 ) {
							$refs[ (int) $v ][] = $key;
						}
					}
				} elseif ( '_elementor_data' === $key ) {
					foreach ( self::elementor_ids( $val ) as $v ) {
						$refs[ $v ][] = '_elementor_data';
					}
				}
			}
			$out['meta'] = $meta;
		}
		foreach ( self::content_ids( (string) ( $row['post_content'] ?? '' ) ) as $v ) {
			$refs[ $v ][] = 'post_content';
		}
		unset( $refs[ $post_id ] );
		$out['refs'] = array();
		foreach ( $refs as $aid => $where ) {
			$out['refs'][] = array( 'id' => (int) $aid, 'where' => array_values( array_unique( $where ) ) );
		}

		if ( in_array( 'terms', $include, true ) ) {
			$terms = array();
			$taxes = get_object_taxonomies( (string) $row['post_type'] );
			foreach ( $taxes as $tax ) {
				$list = wp_get_object_terms( $post_id, $tax );
				if ( is_wp_error( $list ) ) {
					continue;
				}
				foreach ( $list as $t ) {
					$parent_slug = '';
					if ( $t->parent > 0 ) {
						$p = get_term( $t->parent, $tax );
						if ( $p instanceof WP_Term ) {
							$parent_slug = $p->slug;
						}
					}
					$terms[] = array(
						'taxonomy'    => $t->taxonomy,
						'slug'        => $t->slug,
						'name'        => $t->name,
						'description' => $t->description,
						'parent'      => $parent_slug,
						'term_id'     => (int) $t->term_id,
					);
				}
			}
			$out['terms'] = $terms;
		}

		$parent_id = (int) ( $row['post_parent'] ?? 0 );
		if ( $parent_id > 0 ) {
			$p = get_post( $parent_id );
			if ( $p instanceof WP_Post ) {
				$out['parent'] = array(
					'id'        => $parent_id,
					'post_name' => $p->post_name,
					'post_type' => $p->post_type,
				);
			}
		}

		if ( in_array( 'attachments', $include, true ) ) {
			$att = array();
			foreach ( $out['refs'] as $ref ) {
				$aid = (int) $ref['id'];
				$ap  = get_post( $aid );
				if ( ! $ap instanceof WP_Post || 'attachment' !== $ap->post_type ) {
					continue;
				}
				$ameta = array();
				$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id ASC", $aid ), ARRAY_A );
				foreach ( is_array( $rows ) ? $rows : array() as $m ) {
					$ameta[] = array( 'key' => (string) $m['meta_key'] ) + self::pack( null === $m['meta_value'] ? null : (string) $m['meta_value'] );
				}
				$att[ (string) $aid ] = array(
					'post_title'     => $ap->post_title,
					'post_name'      => $ap->post_name,
					'post_excerpt'   => $ap->post_excerpt,
					'post_content'   => $ap->post_content,
					'post_mime_type' => $ap->post_mime_type,
					'post_parent'    => (int) $ap->post_parent,
					'guid'           => $ap->guid,
					'attached_file'  => (string) get_post_meta( $aid, '_wp_attached_file', true ),
					'meta'           => $ameta,
				);
			}
			$out['attachments'] = $att;
		}
		return $out;
	}
}
