<?php
/**
 * Shared cursor-page budget for REST payloads. Keeps one request under Hostinger
 * time/memory and Cloudflare/JSON size limits; the proxy client loops.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ovp_Chunk {

	public const DEFAULT_BYTES   = 800000;
	public const DEFAULT_FILES   = 20;
	public const DEFAULT_SECONDS = 12.0;

	/** Raw bytes per upload chunk (design: 4MB; base64 body stays under the 5MB envelope cap). */
	public const UPLOAD_CHUNK_MAX = 4194304;

	/**
	 * Strict base64 decode for upload chunks. Returns null on any malformed input and
	 * on payloads whose decoded size would exceed UPLOAD_CHUNK_MAX.
	 */
	public static function decode_b64_strict( mixed $raw ): ?string {
		if ( ! is_string( $raw ) || '' === $raw || ( strlen( $raw ) * 3 ) / 4 > self::UPLOAD_CHUNK_MAX + 3 ) {
			return null;
		}
		$out = base64_decode( $raw, true );
		return false === $out ? null : $out;
	}

	public static function max_bytes( mixed $raw, int $default = self::DEFAULT_BYTES ): int {
		$n = (int) $raw;
		if ( $n <= 0 ) {
			return $default;
		}
		return min( 2000000, max( 32000, $n ) );
	}

	public static function max_items( mixed $raw, int $default ): int {
		$n = (int) $raw;
		if ( $n <= 0 ) {
			return $default;
		}
		return min( 200, max( 1, $n ) );
	}

	/**
	 * Cursor means "start at this key" (inclusive for path#offset, exclusive for a
	 * completed previous key when the next key is supplied). Skip keys that sort before it.
	 */
	public static function reached( string $key, string $cursor ): bool {
		return '' === $cursor || strcmp( $key, $cursor ) >= 0;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public static function envelope( array $payload, string $next_cursor ): array {
		$payload['next_cursor'] = $next_cursor;
		$payload['done']        = '' === $next_cursor;
		return $payload;
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @return array{0: array<int, array<string, mixed>>, 1: string}
	 */
	public static function slice_by_key( array $items, string $cursor, int $max, callable $key_of ): array {
		$out  = array();
		$next = '';
		foreach ( $items as $item ) {
			$key = (string) $key_of( $item );
			if ( '' === $key || ( '' !== $cursor && strcmp( $key, $cursor ) < 0 ) ) {
				continue;
			}
			if ( count( $out ) >= $max ) {
				$next = $key;
				break;
			}
			$out[] = $item;
		}
		return array( $out, $next );
	}
}
