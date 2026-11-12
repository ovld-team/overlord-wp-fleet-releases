<?php
/**
 * /ovp/v1/media/push and content/media/regenerate (1.7.0).
 *
 * Chunked, sha256-verified upload of a media file into uploads/<yyyy>/<mm>/<name>, then
 * attachment creation (mode create) or in-place file swap of an existing attachment
 * that keeps its ID (mode replace_file, e.g. jpg -> webp). Chunks land in
 * uploads/mcp-bridge/push/*.part and are renamed into place only after the full-file
 * sha256 and size match.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ovp_Module_Media_Push extends Ovp_Module_Base {

	private const CHUNK_MAX_BYTES = 6291456;
	private const FILE_MAX_BYTES  = 134217728;
	private const EXTS            = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'pdf' );
	private const REGEN_BUDGET    = 20.0;

	public function register_routes(): void {
		$this->route( 'POST', 'media/push', array( $this, 'push' ) );
		$this->route( 'POST', 'content/media/regenerate', array( $this, 'regenerate' ) );
	}

	/**
	 * Body: rel_path "YYYY/MM/name.ext", offset, content (base64 chunk), total_size, sha256
	 * (whole file, required on the last chunk), chunk_sha256, mode create|replace_file,
	 * attachment_id (replace_file), overwrite, delete_old, file_only, dry_run (default true),
	 * confirm, attachment {title, alt, caption, description, post_parent, meta{}}.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request
	 */
	public function push( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$body = (array) $request->get_json_params();
		$dry  = ! array_key_exists( 'dry_run', $body ) || (bool) $body['dry_run'];
		$rel  = (string) ( $body['rel_path'] ?? '' );
		$mode = 'replace_file' === ( $body['mode'] ?? 'create' ) ? 'replace_file' : 'create';
		if ( ! preg_match( '#^\d{4}/\d{2}/[A-Za-z0-9][A-Za-z0-9._-]{0,180}$#', $rel ) || str_contains( $rel, '..' ) ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'rel_path must look like 2026/10/name.webp.', 400 );
		}
		$ext = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::EXTS, true ) ) {
			return $this->error( 'mcp_bridge_invalid_media', 'Allowed: ' . implode( ' ', self::EXTS ) . '.', 400 );
		}
		if ( ! $dry && empty( $body['confirm'] ) ) {
			return $this->error( 'mcp_bridge_confirm_required', 'media_push apply needs confirm=true (dry_run defaults true).', 403 );
		}
		$up = wp_get_upload_dir();
		if ( ! empty( $up['error'] ) ) {
			return $this->error( 'mcp_bridge_upload_dir', (string) $up['error'], 500 );
		}
		$base  = rtrim( str_replace( '\\', '/', (string) $up['basedir'] ), '/' );
		$final = $base . '/' . $rel;

		$attachment_id = (int) ( $body['attachment_id'] ?? 0 );
		$existing_id   = $this->attachment_for_rel( $rel );
		if ( 'replace_file' === $mode ) {
			$att = $attachment_id > 0 ? get_post( $attachment_id ) : null;
			if ( ! $att instanceof WP_Post || 'attachment' !== $att->post_type ) {
				return $this->error( 'mcp_bridge_invalid_payload', 'replace_file needs a valid attachment_id.', 400 );
			}
		}

		$total  = (int) ( $body['total_size'] ?? 0 );
		$sha    = strtolower( (string) ( $body['sha256'] ?? '' ) );
		$offset = max( 0, (int) ( $body['offset'] ?? 0 ) );
		$chunk  = null;
		if ( isset( $body['content'] ) && '' !== (string) $body['content'] ) {
			$chunk = base64_decode( (string) $body['content'], true );
			if ( false === $chunk ) {
				return $this->error( 'mcp_bridge_invalid_payload', 'content is not valid base64.', 400 );
			}
			if ( strlen( $chunk ) > self::CHUNK_MAX_BYTES ) {
				return $this->error( 'mcp_bridge_file_too_large', 'Chunk over 6MB; split it.', 413 );
			}
			$csha = strtolower( (string) ( $body['chunk_sha256'] ?? '' ) );
			if ( '' !== $csha && ! hash_equals( $csha, hash( 'sha256', $chunk ) ) ) {
				return $this->error( 'mcp_bridge_hash_mismatch', 'chunk_sha256 mismatch.', 422 );
			}
		}
		if ( $total < 1 || $total > self::FILE_MAX_BYTES ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'total_size required (max 128MB).', 400 );
		}

		$plan = array(
			'rel_path'      => $rel,
			'mode'          => $mode,
			'final_exists'  => is_file( $final ),
			'final_size'    => is_file( $final ) ? (int) filesize( $final ) : null,
			'existing_attachment_for_path' => $existing_id,
			'attachment_id' => $attachment_id > 0 ? $attachment_id : null,
		);
		if ( $dry ) {
			return $this->success( array( 'dry_run' => true, 'plan' => $plan, 'chunk_bytes' => null === $chunk ? 0 : strlen( $chunk ), 'offset' => $offset ) );
		}

		// Receive.
		$part_dir = $base . '/mcp-bridge/push';
		wp_mkdir_p( $part_dir );
		$part = $part_dir . '/' . sha1( $rel ) . '.part';
		if ( null !== $chunk ) {
			$have = is_file( $part ) ? (int) filesize( $part ) : 0;
			if ( 0 === $offset ) {
				@unlink( $part );
				$have = 0;
			}
			if ( $offset !== $have ) {
				return new WP_Error(
					'mcp_bridge_offset_mismatch',
					'offset ' . $offset . ' != received ' . $have,
					array( 'status' => 409, 'received' => $have )
				);
			}
			if ( $have + strlen( $chunk ) > $total ) {
				return $this->error( 'mcp_bridge_invalid_payload', 'Chunk runs past total_size.', 400 );
			}
			if ( false === file_put_contents( $part, $chunk, FILE_APPEND | LOCK_EX ) ) {
				return $this->error( 'mcp_bridge_write_failed', 'Could not write part file.', 500 );
			}
			clearstatcache( true, $part );
		}
		$received = is_file( $part ) ? (int) filesize( $part ) : 0;
		if ( $received < $total ) {
			return $this->success( array( 'dry_run' => false, 'complete' => false, 'received' => $received, 'total_size' => $total ) );
		}

		// Finalize.
		if ( $received !== $total ) {
			@unlink( $part );
			return $this->error( 'mcp_bridge_size_mismatch', 'Received size differs from total_size; restart at offset 0.', 422 );
		}
		$actual = hash_file( 'sha256', $part );
		if ( '' === $sha || false === $actual || ! hash_equals( $sha, $actual ) ) {
			@unlink( $part );
			return $this->error( 'mcp_bridge_hash_mismatch', 'Whole-file sha256 missing or mismatched; part discarded.', 422 );
		}
		$result = array( 'complete' => true, 'sha256' => $actual, 'bytes' => $total, 'rel_path' => $rel );

		wp_mkdir_p( dirname( $final ) );
		$real_dir  = realpath( dirname( $final ) );
		$real_base = realpath( $base );
		if ( false === $real_dir || false === $real_base || ! str_starts_with( $real_dir . '/', $real_base . '/' ) ) {
			@unlink( $part );
			return $this->error( 'mcp_bridge_path_jail', 'Target directory escapes uploads.', 400 );
		}
		if ( is_file( $final ) ) {
			$same = hash_file( 'sha256', $final ) === $actual;
			if ( $same ) {
				@unlink( $part );
				$result['file'] = 'unchanged';
			} elseif ( empty( $body['overwrite'] ) && 'replace_file' !== $mode ) {
				@unlink( $part );
				return $this->error( 'mcp_bridge_exists', 'Target exists with different content; pass overwrite=true.', 409 );
			} else {
				if ( ! @rename( $part, $final ) ) {
					return $this->error( 'mcp_bridge_write_failed', 'Could not move file into place.', 500 );
				}
				$result['file'] = 'overwritten';
			}
		} else {
			if ( ! @rename( $part, $final ) ) {
				if ( ! @copy( $part, $final ) ) {
					return $this->error( 'mcp_bridge_write_failed', 'Could not move file into place.', 500 );
				}
				@unlink( $part );
			}
			$result['file'] = 'created';
		}
		if ( ! empty( $body['file_only'] ) ) {
			return $this->success( $result );
		}

		$meta_in = is_array( $body['attachment'] ?? null ) ? $body['attachment'] : array();
		$gen     = ! array_key_exists( 'generate_metadata', $body ) || (bool) $body['generate_metadata'];

		if ( 'create' === $mode ) {
			if ( $existing_id > 0 && 'unchanged' === $result['file'] ) {
				$result['attachment_id'] = $existing_id;
				$result['reused']        = true;
				return $this->success( $result );
			}
			$mime = wp_check_filetype( basename( $final ) );
			$post = array(
				'guid'           => trailingslashit( (string) $up['baseurl'] ) . $rel,
				'post_mime_type' => $mime['type'] ?: 'application/octet-stream',
				'post_title'     => isset( $meta_in['title'] ) ? (string) $meta_in['title'] : pathinfo( $rel, PATHINFO_FILENAME ),
				'post_content'   => (string) ( $meta_in['description'] ?? '' ),
				'post_excerpt'   => (string) ( $meta_in['caption'] ?? '' ),
				'post_status'    => 'inherit',
			);
			$id   = wp_insert_attachment( wp_slash( $post ), $final, (int) ( $meta_in['post_parent'] ?? 0 ), true );
			if ( $id instanceof WP_Error ) {
				return $this->error( 'mcp_bridge_attachment_failed', $id->get_error_message(), 500 );
			}
			$id = (int) $id;
			$this->apply_attachment_fields( $id, $meta_in );
			if ( $gen ) {
				$result['sizes'] = $this->regen_one( $id, $final );
			}
			$result['attachment_id'] = $id;
			$result['url']           = wp_get_attachment_url( $id );
			return $this->success( $result );
		}

		// replace_file.
		global $wpdb;
		$before = array(
			'attached_file' => (string) get_post_meta( $attachment_id, '_wp_attached_file', true ),
			'metadata_raw'  => $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_wp_attachment_metadata' LIMIT 1", $attachment_id ) ),
			'mime'          => (string) get_post_field( 'post_mime_type', $attachment_id ),
			'guid'          => (string) get_post_field( 'guid', $attachment_id ),
		);
		Ovp_Migrate_Util::snapshot_append( 'media-' . gmdate( 'Ymd' ), array( 'kind' => 'attachment_before', 'id' => $attachment_id, 'data' => $before ) );
		update_attached_file( $attachment_id, $final );
		$mime = wp_check_filetype( basename( $final ) );
		$wpdb->update(
			$wpdb->posts,
			array( 'post_mime_type' => $mime['type'] ?: $before['mime'], 'guid' => trailingslashit( (string) $up['baseurl'] ) . $rel ),
			array( 'ID' => $attachment_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		clean_post_cache( $attachment_id );
		$this->apply_attachment_fields( $attachment_id, $meta_in );
		if ( $gen ) {
			$result['sizes'] = $this->regen_one( $attachment_id, $final );
		}
		if ( ! empty( $body['delete_old'] ) && '' !== $before['attached_file'] && $before['attached_file'] !== $rel ) {
			$result['deleted_old'] = $this->delete_old_files( $base, $before );
		}
		$result['attachment_id'] = $attachment_id;
		$result['before']        = $before;
		$result['url']           = wp_get_attachment_url( $attachment_id );
		return $this->success( $result );
	}

	/**
	 * @param array<string, mixed> $in
	 */
	private function apply_attachment_fields( int $id, array $in ): void {
		global $wpdb;
		$upd = array( 'ID' => $id );
		foreach ( array( 'title' => 'post_title', 'description' => 'post_content', 'caption' => 'post_excerpt' ) as $k => $col ) {
			if ( isset( $in[ $k ] ) ) {
				$upd[ $col ] = (string) $in[ $k ];
			}
		}
		if ( count( $upd ) > 1 ) {
			$wpdb->update( $wpdb->posts, array_diff_key( $upd, array( 'ID' => 1 ) ), array( 'ID' => $id ) );
			clean_post_cache( $id );
		}
		if ( isset( $in['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( (string) $in['alt'] ) ) );
		}
		foreach ( is_array( $in['meta'] ?? null ) ? $in['meta'] : array() as $key => $value ) {
			$key = preg_replace( '/[^\w\-\.:]/', '', (string) $key ) ?? '';
			if ( '' === $key || in_array( $key, array( '_wp_attached_file', '_wp_attachment_metadata' ), true ) || ! is_scalar( $value ) ) {
				continue;
			}
			update_post_meta( $id, $key, wp_slash( (string) $value ) );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function regen_one( int $id, string $file ): array {
		$old = wp_get_attachment_metadata( $id );
		$new = wp_generate_attachment_metadata( $id, $file );
		if ( array() === $new ) {
			return array( 'ok' => false, 'sizes' => 0 );
		}
		if ( is_array( $old ) ) {
			$new += $old;
		}
		wp_update_attachment_metadata( $id, $new );
		return array( 'ok' => true, 'sizes' => isset( $new['sizes'] ) && is_array( $new['sizes'] ) ? count( $new['sizes'] ) : 0 );
	}

	private function attachment_for_rel( string $rel ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s ORDER BY post_id ASC LIMIT 1",
				$rel
			)
		);
	}

	/**
	 * @param array<string, mixed> $before
	 * @return array<int, string>
	 */
	private function delete_old_files( string $base, array $before ): array {
		$gone = array();
		$old  = (string) $before['attached_file'];
		$dir  = dirname( $old );
		$raw  = $before['metadata_raw'];
		$meta = is_string( $raw ) ? maybe_unserialize( $raw ) : array();
		$list = array( $old );
		if ( is_array( $meta ) && is_array( $meta['sizes'] ?? null ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( is_array( $size ) && ! empty( $size['file'] ) ) {
					$list[] = ( '.' === $dir ? '' : $dir . '/' ) . $size['file'];
				}
			}
		}
		foreach ( array_unique( $list ) as $rel ) {
			if ( ! preg_match( '#^\d{4}/\d{2}/[A-Za-z0-9][A-Za-z0-9._-]*$#', $rel ) ) {
				continue;
			}
			$p = $base . '/' . $rel;
			if ( is_file( $p ) && @unlink( $p ) ) {
				$gone[] = $rel;
			}
		}
		return $gone;
	}

	/**
	 * Body: ids[], only_missing, dry_run (default true), confirm, cursor (index).
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request
	 */
	public function regenerate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}
		$body = (array) $request->get_json_params();
		$dry  = ! array_key_exists( 'dry_run', $body ) || (bool) $body['dry_run'];
		$ids  = array();
		foreach ( is_array( $body['ids'] ?? null ) ? $body['ids'] : array() as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$ids[] = (int) $id;
			}
		}
		if ( array() === $ids || count( $ids ) > 200 ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Provide ids[] (1-200).', 400 );
		}
		if ( ! $dry && empty( $body['confirm'] ) ) {
			return $this->error( 'mcp_bridge_confirm_required', 'media_regenerate apply needs confirm=true (dry_run defaults true).', 403 );
		}
		$only_missing = ! empty( $body['only_missing'] );
		$start        = max( 0, (int) ( $body['cursor'] ?? 0 ) );
		$deadline     = microtime( true ) + self::REGEN_BUDGET;
		$rows         = array();
		$next         = '';
		for ( $i = $start, $n = count( $ids ); $i < $n; $i++ ) {
			if ( microtime( true ) > $deadline ) {
				$next = (string) $i;
				break;
			}
			$id   = $ids[ $i ];
			$file = get_attached_file( $id );
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type ) {
				$rows[] = array( 'id' => $id, 'status' => 'not_attachment' );
				continue;
			}
			if ( ! is_string( $file ) || ! is_file( $file ) ) {
				$rows[] = array( 'id' => $id, 'status' => 'missing_file' );
				continue;
			}
			$meta = get_post_meta( $id, '_wp_attachment_metadata', true );
			if ( $only_missing && is_array( $meta ) && is_array( $meta['sizes'] ?? null ) && array() !== $meta['sizes'] ) {
				$dir     = dirname( $file );
				$missing = false;
				foreach ( $meta['sizes'] as $size ) {
					if ( is_array( $size ) && ! empty( $size['file'] ) && ! is_file( $dir . '/' . $size['file'] ) ) {
						$missing = true;
						break;
					}
				}
				if ( ! $missing ) {
					$rows[] = array( 'id' => $id, 'status' => 'ok_skipped' );
					continue;
				}
			}
			if ( $dry ) {
				$rows[] = array( 'id' => $id, 'status' => 'would_regenerate' );
				continue;
			}
			$res    = $this->regen_one( $id, $file );
			$rows[] = array( 'id' => $id, 'status' => $res['ok'] ? 'regenerated' : 'failed', 'sizes' => $res['sizes'] );
		}
		return $this->success( Ovp_Chunk::envelope( array( 'dry_run' => $dry, 'results' => $rows ), $next ) );
	}
}
