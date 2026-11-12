<?php
/**
 * The site's icon for the Fleet site list: `{mime, b64, sha256}` or null.
 *
 * Read from disk, never over HTTP: the WordPress site icon (attachment file), then ABSPATH/favicon.ico,
 * then the active theme's favicon.ico / favicon.png. Only a real PNG, JPEG, WebP, GIF or ICO (magic bytes,
 * not the file name) of at most 20 KB is sent; a larger raster is downscaled to 64x64 PNG with the WordPress
 * image editor into a private temp file; SVG is never sent. Every error returns null, nothing here may break a site.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ovp_Site_Icon {

	/** Largest payload (raw bytes) that is sent. */
	public const MAX_BYTES = 20480;
	/** Largest source file that is read at all (a bigger one is not an icon). */
	public const MAX_SOURCE_BYTES = 2097152;
	public const THUMB_SIZE       = 64;
	/** Largest source dimension the image editor is asked to decode (a decompression bomb can exhaust memory, which is a fatal no try/catch survives). */
	public const MAX_SOURCE_DIM = 2048;
	public const CACHE_OPTION   = 'ovp_icon_cache';

	/** @return array{mime:string,b64:string,sha256:string}|null */
	public static function collect(): ?array {
		foreach ( array( 'candidate_site_icon', 'candidate_abspath_favicon', 'candidate_theme_ico', 'candidate_theme_png' ) as $source ) {
			try {
				$path = self::$source();
				if ( null === $path ) {
					continue;
				}
				$icon = self::from_file( $path );
				if ( null !== $icon ) {
					return $icon;
				}
			} catch ( \Throwable $e ) {
				continue;
			}
		}
		return null;
	}

	/** Image type from the first bytes, or null (SVG, HTML, anything unknown). */
	public static function detect_mime( string $b ): ?string {
		if ( str_starts_with( $b, "\x89PNG\r\n\x1a\n" ) ) {
			return 'image/png';
		}
		if ( str_starts_with( $b, "\xFF\xD8\xFF" ) ) {
			return 'image/jpeg';
		}
		if ( str_starts_with( $b, 'GIF87a' ) || str_starts_with( $b, 'GIF89a' ) ) {
			return 'image/gif';
		}
		if ( strlen( $b ) >= 12 && str_starts_with( $b, 'RIFF' ) && 'WEBP' === substr( $b, 8, 4 ) ) {
			return 'image/webp';
		}
		// ICO: reserved 0, type 1 (2 = CUR), at least one image.
		if ( strlen( $b ) >= 6 && str_starts_with( $b, "\x00\x00\x01\x00" ) && 0 !== ( ord( $b[4] ) | ord( $b[5] ) ) ) {
			return 'image/x-icon';
		}
		return null;
	}

	/**
	 * Validate and shape one candidate file.
	 *
	 * @return array{mime:string,b64:string,sha256:string}|null
	 */
	public static function from_file( string $path ): ?array {
		if ( ! is_file( $path ) || is_link( $path ) ) {
			return null;
		}
		$size = filesize( $path );
		if ( false === $size || $size < 8 || $size > self::MAX_SOURCE_BYTES ) {
			return null;
		}
		$bytes = file_get_contents( $path, false, null, 0, self::MAX_SOURCE_BYTES );
		if ( ! is_string( $bytes ) ) {
			return null;
		}
		$mime = self::detect_mime( $bytes );
		if ( null === $mime ) {
			return null; // SVG, a renamed text file, anything that is not a real raster/ICO.
		}
		if ( strlen( $bytes ) <= self::MAX_BYTES ) {
			return self::shape( $mime, $bytes );
		}
		if ( 'image/x-icon' === $mime ) {
			return null; // An ICO cannot be resized by the image editor.
		}
		$thumb = self::thumbnail( $path );
		if ( null === $thumb || strlen( $thumb ) > self::MAX_BYTES || 'image/png' !== self::detect_mime( $thumb ) ) {
			return null;
		}
		return self::shape( 'image/png', $thumb );
	}

	/** @return array{mime:string,b64:string,sha256:string} */
	private static function shape( string $mime, string $bytes ): array {
		return array(
			'mime'   => $mime,
			'b64'    => base64_encode( $bytes ),
			'sha256' => hash( 'sha256', $bytes ),
		);
	}

	/**
	 * 64x64 PNG bytes, or null. Guards, in order: the result cache (path+mtime+size, so the resize runs at most once per
	 * change; a failed or crashed attempt is remembered as null), the pixel-size cap via getimagesize() (no decode), the
	 * WordPress image memory limit.
	 */
	private static function thumbnail( string $path ): ?string {
		$mtime = filemtime( $path );
		$size  = filesize( $path );
		$key   = hash( 'sha256', $path . '|' . (int) $mtime . '|' . (int) $size );
		$c     = get_option( self::CACHE_OPTION, null );
		if ( is_array( $c ) && ( $c['key'] ?? '' ) === $key ) {
			if ( 'done' === ( $c['state'] ?? '' ) && is_string( $c['b64'] ?? null ) ) {
				$bin = base64_decode( $c['b64'], true );
				return is_string( $bin ) ? $bin : null;
			}
			return null; // 'failed', or 'pending' = a previous attempt died mid-resize (fatal): never try this file again.
		}
		$dim = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- header read only, no decode.
		if ( ! is_array( $dim ) || (int) $dim[0] < 1 || (int) $dim[1] < 1 || (int) $dim[0] > self::MAX_SOURCE_DIM || (int) $dim[1] > self::MAX_SOURCE_DIM ) {
			self::remember( $key, 'failed', '' );
			return null;
		}
		self::remember( $key, 'pending', '' ); // Survives a PHP fatal inside the editor.
		try {
			$out = self::resize( $path );
		} catch ( \Throwable $e ) {
			$out = null;
		}
		self::remember( $key, null === $out ? 'failed' : 'done', null === $out ? '' : base64_encode( $out ) );
		return $out;
	}

	private static function remember( string $key, string $state, string $b64 ): void {
		update_option( self::CACHE_OPTION, array( 'key' => $key, 'state' => $state, 'b64' => $b64 ), false );
	}

	private static function resize( string $path ): ?string {
		if ( ! function_exists( 'wp_get_image_editor' ) ) {
			return null;
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) || ! is_object( $editor ) ) {
			return null;
		}
		$base = tempnam( sys_get_temp_dir(), 'ovpico' );
		if ( false === $base ) {
			return null;
		}
		$out = $base . '.png';
		try {
			$r = $editor->resize( self::THUMB_SIZE, self::THUMB_SIZE, true );
			if ( is_wp_error( $r ) ) {
				return null;
			}
			$saved = $editor->save( $out, 'image/png' );
			if ( is_wp_error( $saved ) || ! is_array( $saved ) ) {
				return null;
			}
			$written = isset( $saved['path'] ) && is_string( $saved['path'] ) ? $saved['path'] : $out;
			$data    = is_file( $written ) && filesize( $written ) <= self::MAX_SOURCE_BYTES ? file_get_contents( $written ) : false;
			if ( $written !== $out && is_file( $written ) ) {
				@unlink( $written ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- private temp file.
			}
			return is_string( $data ) ? $data : null;
		} finally {
			@unlink( $base ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- private temp file.
			@unlink( $out ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- private temp file.
		}
	}

	/** On-disk file of the WordPress site icon: the 64px URL mapped into uploads, else the attachment file. */
	private static function candidate_site_icon(): ?string {
		if ( ! function_exists( 'get_site_icon_url' ) ) {
			return null;
		}
		$up      = function_exists( 'wp_get_upload_dir' ) ? wp_get_upload_dir() : array();
		$basedir = isset( $up['basedir'] ) && is_string( $up['basedir'] ) ? realpath( $up['basedir'] ) : false;
		if ( false === $basedir ) {
			return null;
		}
		$url     = (string) get_site_icon_url( self::THUMB_SIZE );
		$baseurl = isset( $up['baseurl'] ) && is_string( $up['baseurl'] ) ? rtrim( $up['baseurl'], '/' ) : '';
		$paths   = array();
		if ( '' !== $url && '' !== $baseurl && str_starts_with( $url, $baseurl . '/' ) ) {
			$rel = rawurldecode( (string) strtok( substr( $url, strlen( $baseurl ) + 1 ), '?#' ) );
			if ( ! str_contains( $rel, '..' ) && ! str_contains( $rel, "\0" ) ) {
				$paths[] = $basedir . '/' . $rel;
			}
		}
		$id = (int) get_option( 'site_icon', 0 );
		if ( $id > 0 && function_exists( 'get_attached_file' ) ) {
			$att = get_attached_file( $id );
			if ( is_string( $att ) && '' !== $att ) {
				$paths[] = $att;
			}
		}
		foreach ( $paths as $p ) {
			$real = realpath( $p );
			if ( false !== $real && Ovp_Net::path_inside( $real, $basedir ) ) {
				return $real;
			}
		}
		return null;
	}

	private static function candidate_abspath_favicon(): ?string {
		return self::inside( rtrim( ABSPATH, '/\\' ) . '/favicon.ico', ABSPATH );
	}

	private static function candidate_theme_ico(): ?string {
		return function_exists( 'get_stylesheet_directory' ) ? self::inside( get_stylesheet_directory() . '/favicon.ico', get_stylesheet_directory() ) : null;
	}

	private static function candidate_theme_png(): ?string {
		return function_exists( 'get_stylesheet_directory' ) ? self::inside( get_stylesheet_directory() . '/favicon.png', get_stylesheet_directory() ) : null;
	}

	private static function inside( string $file, string $root ): ?string {
		$real = realpath( $file );
		$base = realpath( $root );
		return false !== $real && false !== $base && Ovp_Net::path_inside( $real, $base ) ? $real : null;
	}
}
