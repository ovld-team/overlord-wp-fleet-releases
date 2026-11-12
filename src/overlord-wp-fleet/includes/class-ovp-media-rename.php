<?php
/**
 * Rename media files in place. Keeps the attachment ID, copies every size,
 * rewrites basename tokens (serialized-aware), then 301s the old basenames.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ovp_Media_Rename {

	public const MAP_OPTION = 'mcp_bridge_media_rename_map';

	private const MAX_ITEMS = 30;

	private const SKIP_OPTIONS = array( 'wcn_backup_status' );

	public static function maybe_redirect(): void {
		if ( is_admin() ) {
			return;
		}
		$uri  = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		if ( ! str_contains( $uri, '/wp-content/uploads/' ) ) {
			return;
		}
		$base = wp_basename( $uri );
		$map  = get_option( self::MAP_OPTION, array() );
		if ( ! is_array( $map ) || ! isset( $map[ $base ] ) || ! is_string( $map[ $base ] ) || '' === $map[ $base ] ) {
			return;
		}
		wp_safe_redirect( content_url( 'uploads/' . $map[ $base ] ), 301 );
		exit;
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function run( WP_REST_Request $request ): array|WP_Error {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}

		$body     = (array) $request->get_json_params();
		$dry_run  = ! array_key_exists( 'dry_run', $body ) || (bool) $body['dry_run'];
		$confirm  = ! empty( $body['confirm'] );
		$cleanup      = ! empty( $body['cleanup'] );
		$rewrite_only = ! empty( $body['rewrite_only'] );
		$items        = $body['items'] ?? array();
		$extra_pairs  = $body['extra_pairs'] ?? array();

		if ( ! $dry_run && ! $confirm ) {
			return new WP_Error( 'mcp_bridge_confirm_required', 'media rename apply needs confirm=true (dry_run defaults true).', array( 'status' => 403 ) );
		}
		if ( $rewrite_only ) {
			if ( ! is_array( $extra_pairs ) || array() === $extra_pairs ) {
				return new WP_Error( 'mcp_bridge_invalid_payload', 'rewrite_only needs extra_pairs.', array( 'status' => 400 ) );
			}
			$pairs = array();
			foreach ( $extra_pairs as $from => $to ) {
				$from = (string) $from;
				$to   = (string) $to;
				if ( ! self::pair_name_ok( $from ) || ! self::pair_name_ok( $to ) ) {
					return new WP_Error( 'mcp_bridge_invalid_media', 'Bad rewrite pair.', array( 'status' => 400 ) );
				}
				$pairs[ $from ] = $to;
			}
			$rewriter = new Ovp_Module_Search_Replace();
			$db       = $rewriter->rewrite_filename_pairs( $pairs, $dry_run, self::SKIP_OPTIONS, ! $dry_run );
			if ( ! $dry_run ) {
				self::remember_map( $pairs );
				do_action( 'litespeed_purge_all' );
				if ( function_exists( 'wp_cache_flush' ) ) {
					wp_cache_flush();
				}
			}
			return array(
				'dry_run'           => $dry_run,
				'rewrite_only'      => true,
				'replacements'      => $db['replacements'],
				'rows'              => $db['rows'],
				'skipped'           => $db['skipped'],
				'tables'            => $db['tables'],
				'elementor_cleared' => $db['elementor_cleared'],
			);
		}
		if ( ! is_array( $items ) || array() === $items ) {
			return new WP_Error( 'mcp_bridge_invalid_payload', 'Provide items.', array( 'status' => 400 ) );
		}
		if ( count( $items ) > self::MAX_ITEMS ) {
			return new WP_Error( 'mcp_bridge_invalid_payload', 'Max ' . self::MAX_ITEMS . ' items per call.', array( 'status' => 400 ) );
		}

		if ( $cleanup && ! $dry_run ) {
			$ids = array();
			foreach ( $items as $raw ) {
				$row   = (array) $raw;
				$ids[] = (int) ( $row['id'] ?? 0 );
			}
			return array(
				'dry_run' => false,
				'cleanup' => true,
				'removed' => self::cleanup_ids( $ids ),
			);
		}

		$plans = array();
		$pairs = array();
		foreach ( $items as $raw ) {
			$plan = self::plan_item( (array) $raw );
			if ( $plan instanceof WP_Error ) {
				return $plan;
			}
			$plans[] = $plan;
			foreach ( $plan['pairs'] as $from => $to ) {
				if ( isset( $pairs[ $from ] ) && $pairs[ $from ] !== $to ) {
					return new WP_Error( 'mcp_bridge_media_collision', 'Two items map ' . $from . ' differently.', array( 'status' => 400 ) );
				}
				$pairs[ $from ] = $to;
			}
		}

		$rewriter = new Ovp_Module_Search_Replace();
		$db       = $rewriter->rewrite_filename_pairs( $pairs, true, self::SKIP_OPTIONS, false );
		if ( $dry_run ) {
			return array(
				'dry_run'       => true,
				'items'         => self::public_plans( $plans ),
				'replacements'  => $db['replacements'],
				'rows'          => $db['rows'],
				'skipped'       => $db['skipped'],
				'tables'        => $db['tables'],
				'files'         => $db['files'],
			);
		}
		if ( (int) $db['skipped'] > 0 ) {
			return new WP_Error(
				'mcp_bridge_serialized_skipped',
				'Dry-run skipped serialized rows. Nothing was written.',
				array( 'status' => 409, 'skipped' => $db['skipped'] )
			);
		}

		$copied = array();
		foreach ( $plans as $plan ) {
			$err = self::copy_plan( $plan );
			if ( $err instanceof WP_Error ) {
				return $err;
			}
			$copied[] = $plan;
			self::update_attachment( $plan );
		}

		$applied = $rewriter->rewrite_filename_pairs( $pairs, false, self::SKIP_OPTIONS, true );
		self::remember_map( $pairs );
		if ( function_exists( 'do_action' ) ) {
			do_action( 'litespeed_purge_all' );
		}
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		return array(
			'dry_run'           => false,
			'items'             => self::public_plans( $copied ),
			'replacements'      => $applied['replacements'],
			'rows'              => $applied['rows'],
			'skipped'           => $applied['skipped'],
			'tables'            => $applied['tables'],
			'files'             => $applied['files'],
			'elementor_cleared' => $applied['elementor_cleared'],
			'old_files_kept'    => true,
		);
	}

	/**
	 * @param array<string, mixed> $raw
	 * @return array<string, mixed>|WP_Error
	 */
	private static function plan_item( array $raw ): array|WP_Error {
		$id = (int) ( $raw['id'] ?? 0 );
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'mcp_bridge_not_found', 'Attachment ' . $id . ' not found.', array( 'status' => 404 ) );
		}

		$rel = (string) get_post_meta( $id, '_wp_attached_file', true );
		$old_base = wp_basename( $rel );
		if ( '' === $old_base ) {
			return new WP_Error( 'mcp_bridge_invalid_media', 'Attachment ' . $id . ' has no file.', array( 'status' => 400 ) );
		}
		$ext = strtolower( (string) pathinfo( $old_base, PATHINFO_EXTENSION ) );
		// D23: filename and title are optional per item. A missing filename keeps the current file name,
		// a missing title keeps the current title (title-only / alt-only edits).
		$has_filename = isset( $raw['filename'] ) && '' !== trim( (string) $raw['filename'] );
		$has_title    = isset( $raw['title'] ) && '' !== trim( (string) $raw['title'] );
		if ( $has_filename ) {
			$filename = strtolower( sanitize_file_name( (string) $raw['filename'] ) );
			if ( ! self::valid_filename( $filename, $ext ) ) {
				return new WP_Error( 'mcp_bridge_invalid_media', 'Bad filename for attachment ' . $id . '.', array( 'status' => 400 ) );
			}
			if ( self::has_vendor( $filename ) ) {
				return new WP_Error( 'mcp_bridge_invalid_media', 'New filename still has a vendor token: ' . $filename, array( 'status' => 400 ) );
			}
		} else {
			$filename = $old_base;
		}

		$title = null;
		if ( $has_title ) {
			$title = sanitize_text_field( (string) $raw['title'] );
			if ( '' === $title || self::has_vendor( $title ) ) {
				return new WP_Error( 'mcp_bridge_invalid_media', 'Title for attachment ' . $id . ' is empty or still names a vendor.', array( 'status' => 400 ) );
			}
		}

		$keep_alt = ! empty( $raw['keep_alt'] ) || ! array_key_exists( 'alt', $raw ); // No alt sent: keep the current one.
		$alt      = sanitize_text_field( (string) ( $raw['alt'] ?? '' ) );
		if ( ! $keep_alt && self::has_vendor( $alt ) ) {
			return new WP_Error( 'mcp_bridge_invalid_media', 'Alt for attachment ' . $id . ' still names a vendor.', array( 'status' => 400 ) );
		}

		$abs = (string) get_attached_file( $id );
		$dir = '' !== $abs ? dirname( $abs ) : '';
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return new WP_Error( 'mcp_bridge_invalid_media', 'Upload directory missing for attachment ' . $id . '.', array( 'status' => 400 ) );
		}

		$old_stem = (string) pathinfo( $old_base, PATHINFO_FILENAME );
		$new_stem = (string) pathinfo( $filename, PATHINFO_FILENAME );
		$meta     = wp_get_attachment_metadata( $id );
		$meta     = is_array( $meta ) ? $meta : array();
		$old_names = array( $old_base );
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			if ( is_array( $size ) && ! empty( $size['file'] ) ) {
				$old_names[] = wp_basename( (string) $size['file'] );
			}
		}
		if ( ! empty( $meta['original_image'] ) ) {
			$old_names[] = wp_basename( (string) $meta['original_image'] );
		}
		$sidecar = $old_base . '.webp';
		if ( is_file( $dir . '/' . $sidecar ) ) {
			$old_names[] = $sidecar;
		}
		$old_names = array_values( array_unique( $old_names ) );

		$pairs = array();
		foreach ( $old_names as $name ) {
			if ( ! str_starts_with( $name, $old_stem ) ) {
				return new WP_Error( 'mcp_bridge_invalid_media', 'Size file ' . $name . ' does not belong to attachment ' . $id . '.', array( 'status' => 400 ) );
			}
			$dest_name = $new_stem . substr( $name, strlen( $old_stem ) );
			if ( $name !== $old_base && is_file( $dir . '/' . $dest_name ) ) {
				return new WP_Error( 'mcp_bridge_media_collision', 'Target already exists: ' . $dest_name, array( 'status' => 409 ) );
			}
			$pairs[ $name ] = $dest_name;
		}
		$dest_abs = $dir . '/' . $filename;
		$src_abs  = $dir . '/' . $old_base;
		if ( $filename !== $old_base && is_file( $dest_abs ) ) {
			$same = is_file( $src_abs ) && filesize( $src_abs ) === filesize( $dest_abs );
			if ( ! $same ) {
				return new WP_Error( 'mcp_bridge_media_collision', 'Target already exists: ' . $filename, array( 'status' => 409 ) );
			}
		}
		if ( $filename !== $old_base && self::attachment_id_for_basename( $filename, $id ) ) {
			return new WP_Error( 'mcp_bridge_media_collision', 'Target already exists: ' . $filename, array( 'status' => 409 ) );
		}

		$rel_dir = dirname( $rel );
		$new_rel = ( '.' === $rel_dir || '' === $rel_dir ) ? $filename : $rel_dir . '/' . $filename;

		return array(
			'id'       => $id,
			'dir'      => $dir,
			'rel'      => $new_rel,
			'old_base' => $old_base,
			'filename' => $filename,
			'title'    => $title,
			'alt'      => $alt,
			'keep_alt' => $keep_alt,
			'meta'     => $meta,
			'pairs'    => $pairs,
		);
	}

	/**
	 * @param array<string, mixed> $plan
	 */
	private static function copy_plan( array $plan ): true|WP_Error {
		$dir = (string) $plan['dir'];
		foreach ( (array) $plan['pairs'] as $from => $to ) {
			$src = $dir . '/' . $from;
			$dst = $dir . '/' . $to;
			if ( $from === $to ) {
				continue;
			}
			if ( ! is_file( $src ) ) {
				if ( $from === $plan['old_base'] ) {
					return new WP_Error( 'mcp_bridge_invalid_media', 'Missing original file ' . $from, array( 'status' => 400 ) );
				}
				continue;
			}
			if ( is_file( $dst ) ) {
				$src_size = filesize( $src );
				$dst_size = filesize( $dst );
				if ( false !== $src_size && $src_size === $dst_size ) {
					continue;
				}
				return new WP_Error( 'mcp_bridge_media_collision', 'Target already exists: ' . $to, array( 'status' => 409 ) );
			}
			if ( ! copy( $src, $dst ) ) {
				return new WP_Error( 'mcp_bridge_write_failed', 'Could not copy ' . $from, array( 'status' => 500 ) );
			}
		}
		return true;
	}

	/**
	 * @param array<string, mixed> $plan
	 */
	private static function update_attachment( array $plan ): void {
		$id   = (int) $plan['id'];
		$meta = (array) $plan['meta'];
		$pairs = (array) $plan['pairs'];
		if ( isset( $meta['file'] ) ) {
			$meta['file'] = self::swap_base( (string) $meta['file'], $pairs );
		}
		if ( isset( $meta['original_image'] ) ) {
			$meta['original_image'] = self::swap_base( (string) $meta['original_image'], $pairs );
		}
		if ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $key => $size ) {
				if ( is_array( $size ) && isset( $size['file'] ) ) {
					$meta['sizes'][ $key ]['file'] = self::swap_base( (string) $size['file'], $pairs );
				}
			}
		}
		update_attached_file( $id, (string) $plan['rel'] );
		if ( array() !== $meta ) {
			wp_update_attachment_metadata( $id, $meta );
		}
		if ( null !== $plan['title'] ) {
			wp_update_post(
				array(
					'ID'         => $id,
					'post_title' => (string) $plan['title'],
					'post_name'  => sanitize_title( (string) $plan['title'] ),
				)
			);
		}
		if ( empty( $plan['keep_alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', (string) $plan['alt'] );
		}
	}

	/**
	 * @param array<string, string> $pairs
	 */
	private static function swap_base( string $path, array $pairs ): string {
		$base = wp_basename( $path );
		if ( ! isset( $pairs[ $base ] ) ) {
			return $path;
		}
		$dir = dirname( $path );
		return ( '.' === $dir || '' === $dir ) ? $pairs[ $base ] : $dir . '/' . $pairs[ $base ];
	}

	/**
	 * Drop old basenames once the attachment already points at the new file.
	 *
	 * @param array<int, int> $ids
	 * @return array<int, string>
	 */
	private static function cleanup_ids( array $ids ): array {
		$map = get_option( self::MAP_OPTION, array() );
		if ( ! is_array( $map ) ) {
			return array();
		}
		$removed = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id < 1 ) {
				continue;
			}
			$abs = (string) get_attached_file( $id );
			if ( '' === $abs || ! is_file( $abs ) ) {
				continue;
			}
			$dir     = dirname( $abs );
			$current = wp_basename( $abs );
			$stem    = (string) pathinfo( $current, PATHINFO_FILENAME );
			$ext     = (string) pathinfo( $current, PATHINFO_EXTENSION );
			$quoted  = preg_quote( $stem, '/' );
			$extq    = preg_quote( $ext, '/' );
			$pattern = '/^' . $quoted . '(?:-\d+x\d+|-scaled)?\.' . $extq . '(?:\.webp)?$/';
			foreach ( $map as $from => $to ) {
				if ( ! is_string( $from ) || ! is_string( $to ) || 1 !== preg_match( $pattern, $to ) ) {
					continue;
				}
				$src = $dir . '/' . $from;
				$dst = $dir . '/' . $to;
				if ( is_file( $src ) && is_file( $dst ) && unlink( $src ) ) {
					$removed[] = $from;
				}
			}
		}
		return $removed;
	}

	/**
	 * @param array<string, string> $pairs
	 */
	private static function remember_map( array $pairs ): void {
		$map = get_option( self::MAP_OPTION, array() );
		if ( ! is_array( $map ) ) {
			$map = array();
		}
		foreach ( $pairs as $from => $to ) {
			if ( $from !== $to ) {
				$map[ $from ] = $to;
			}
		}
		update_option( self::MAP_OPTION, $map, false );
	}

	/**
	 * @param array<int, array<string, mixed>> $plans
	 * @return array<int, array<string, mixed>>
	 */
	private static function public_plans( array $plans ): array {
		$out = array();
		foreach ( $plans as $plan ) {
			$out[] = array(
				'id'       => $plan['id'],
				'from'     => $plan['old_base'],
				'to'       => $plan['filename'],
				'title'    => $plan['title'],
				'alt'      => $plan['keep_alt'] ? null : $plan['alt'],
				'keep_alt' => (bool) $plan['keep_alt'],
				'files'    => $plan['pairs'],
			);
		}
		return $out;
	}

	private static function pair_name_ok( string $name ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]{2,180}$/', $name )
			&& ! str_contains( $name, '..' )
			&& ! str_contains( $name, '/' );
	}

	private static function valid_filename( string $filename, string $ext ): bool {
		if ( '' === $ext || strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) ) !== $ext ) {
			return false;
		}
		$ok = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' );
		if ( ! in_array( $ext, $ok, true ) ) {
			return false;
		}
		$stem = (string) pathinfo( $filename, PATHINFO_FILENAME );
		if ( strlen( $stem ) < 3 || strlen( $stem ) > 80 ) {
			return false;
		}
		return 1 === preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $stem );
	}

	private static function has_vendor( string $value ): bool {
		return 1 === preg_match( '/magnific|chatgpt|openai|dall-?e/i', $value );
	}

	private static function attachment_id_for_basename( string $basename, int $except ): bool {
		global $wpdb;
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND (meta_value = %s OR meta_value LIKE %s) AND post_id <> %d LIMIT 1",
				$basename,
				'%/' . $wpdb->esc_like( $basename ),
				$except
			)
		);
		return (bool) $id;
	}
}
