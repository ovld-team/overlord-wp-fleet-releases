<?php
/**
 * OVP1 signed-envelope protocol (Ed25519). Byte-level spec: docs/PROTOCOL.md.
 *
 * Pure logic: no WordPress calls except the optional wpdb nonce store, so it is
 * testable standalone. Verification is fail-closed and constant-time where it matters.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Outcome of a verification. `reason` is a stable machine code (see PROTOCOL.md §7). */
final class Ovp_Result {
	public function __construct(
		public readonly bool $ok,
		public readonly int $status,
		public readonly string $reason,
		public readonly string $kid = '',
		public readonly string $site_id = '',
		public readonly int $tier = 0,
		public readonly string $nonce = ''
	) {}
}

/** Atomic one-shot claim store (replay protection). */
interface Ovp_Nonce_Store {
	/** @return bool true when the key was fresh (claimed now), false when already seen. */
	public function claim( string $key, int $ttl, int $now ): bool;
}

/** In-memory store (tests, single-process agents). */
final class Ovp_Nonce_Store_Memory implements Ovp_Nonce_Store {
	/** @var array<string,int> */
	private array $seen = array();

	public function claim( string $key, int $ttl, int $now ): bool {
		foreach ( $this->seen as $k => $exp ) {
			if ( $exp <= $now ) {
				unset( $this->seen[ $k ] );
			}
		}
		if ( isset( $this->seen[ $key ] ) ) {
			return false;
		}
		$this->seen[ $key ] = $now + $ttl;
		return true;
	}
}

/** Custom table `{prefix}ovp_nonces`; PRIMARY KEY makes the INSERT the atomic claim. */
final class Ovp_Nonce_Store_Wpdb implements Ovp_Nonce_Store {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ovp_nonces';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			nonce_hash CHAR(64) NOT NULL,
			expires_at BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY  (nonce_hash),
			KEY expires_at (expires_at)
		) {$charset};" );
	}

	public function claim( string $key, int $ttl, int $now ): bool {
		global $wpdb;
		$table = self::table();
		if ( 0 === random_int( 0, 24 ) ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires_at <= %d LIMIT 500", $now ) ); // phpcs:ignore WordPress.DB
		}
		$hash = hash( 'sha256', $key );
		$rows = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (nonce_hash, expires_at) VALUES (%s, %d)", $hash, $now + $ttl ) ); // phpcs:ignore WordPress.DB
		if ( 1 === $rows ) {
			return true;
		}
		// Row exists: it may be an expired leftover not yet purged.
		$replaced = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET expires_at = %d WHERE nonce_hash = %s AND expires_at <= %d", $now + $ttl, $hash, $now ) ); // phpcs:ignore WordPress.DB
		return 1 === $replaced;
	}
}

final class Ovp_Proto {

	public const VERSION         = 'OVP1';
	public const SKEW            = 60;
	public const NONCE_TTL       = 180;
	public const MAX_BODY_JSON   = 2097152;
	public const MAX_BODY_UPLOAD = 5242880;
	public const MAX_PATH        = 1024;
	public const MAX_QUERY       = 4096;

	/** Set once any inbound signed request is being processed; admin-only setters refuse. */
	private static bool $remote_seen = false;

	public static function remote_seen(): bool {
		return self::$remote_seen;
	}

	/** Test helper only. */
	public static function reset_remote_flag(): void {
		self::$remote_seen = false;
	}

	public static function sodium_available(): bool {
		return function_exists( 'sodium_crypto_sign_detached' ) && function_exists( 'sodium_crypto_sign_verify_detached' );
	}

	public static function require_sodium(): void {
		if ( ! self::sodium_available() ) {
			throw new RuntimeException( 'Overlord WP Fleet needs the PHP sodium extension (ext-sodium). Enable it in your PHP configuration to use signed connections.' );
		}
	}

	/* ---------- encoding helpers ---------- */

	public static function b64e( string $raw ): string {
		return base64_encode( $raw );
	}

	/** Strict standard base64 (padded, canonical). Returns null if not canonical. */
	public static function b64d( string $s ): ?string {
		if ( '' === $s || strlen( $s ) > 16384 ) {
			return null;
		}
		$raw = base64_decode( $s, true );
		if ( false === $raw || base64_encode( $raw ) !== $s ) {
			return null;
		}
		return $raw;
	}

	public static function b64url_e( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	public static function b64url_d( string $s ): ?string {
		if ( '' === $s || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $s ) ) {
			return null;
		}
		$raw = base64_decode( strtr( $s, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ), true );
		return false === $raw ? null : $raw;
	}

	public static function new_nonce(): string {
		return self::b64url_e( random_bytes( 16 ) );
	}

	/* ---------- field validators ---------- */

	public static function valid_site_id( string $s ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9._-]{3,64}$/', $s );
	}

	public static function valid_kid( string $s ): bool {
		return 1 === preg_match( '/^[mp][0-9]{1,6}$/', $s );
	}

	public static function valid_nonce( string $s ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $s );
	}

	public static function parse_tier( string $s ): ?int {
		return 1 === preg_match( '/^T([0-3])$/', $s, $m ) ? (int) $m[1] : null;
	}

	public static function valid_path( string $p ): bool {
		return strlen( $p ) <= self::MAX_PATH && 1 === preg_match( '/^\/[\x21-\x7e]*$/D', $p ) && false === strpbrk( $p, '?#' );
	}

	/**
	 * Canonicalise the query: drop `rest_route`, drop empty segments, sort byte-wise,
	 * join with '&'. Returns null when invalid (non-ASCII, control chars, '#', too long).
	 */
	public static function normalize_query( string $raw ): ?string {
		if ( '' !== $raw && '?' === $raw[0] ) {
			$raw = substr( $raw, 1 );
		}
		if ( strlen( $raw ) > self::MAX_QUERY ) {
			return null;
		}
		if ( '' === $raw ) {
			return '';
		}
		if ( 1 !== preg_match( '/^[\x21-\x7e]+$/D', $raw ) || str_contains( $raw, '#' ) ) {
			return null;
		}
		$pairs = array();
		foreach ( explode( '&', $raw ) as $seg ) {
			if ( '' === $seg ) {
				continue;
			}
			$eq  = strpos( $seg, '=' );
			$key = false === $eq ? $seg : substr( $seg, 0, $eq );
			if ( 'rest_route' === $key ) {
				continue;
			}
			$pairs[] = $seg;
		}
		sort( $pairs, SORT_STRING );
		return implode( '&', $pairs );
	}

	/* ---------- canonical strings ---------- */

	/**
	 * @return string|null null when any field is invalid (never sign/verify garbage).
	 */
	public static function canonical_request( string $method, string $path, string $raw_query, string $body, int $ts, string $nonce, string $site_id, int $tier, string $kid ): ?string {
		if ( 1 !== preg_match( '/^(GET|POST|PUT|PATCH|DELETE)$/D', $method ) || ! self::valid_path( $path ) || $ts < 0 || $tier < 0 || $tier > 3
			|| ! self::valid_nonce( $nonce ) || ! self::valid_site_id( $site_id ) || ! self::valid_kid( $kid ) ) {
			return null;
		}
		$q = self::normalize_query( $raw_query );
		if ( null === $q ) {
			return null;
		}
		$target = '' === $q ? $path : $path . '?' . $q;
		return implode( "\n", array( self::VERSION, $method, $target, hash( 'sha256', $body ), (string) $ts, $nonce, $site_id, 'T' . $tier, $kid ) );
	}

	public static function canonical_response( int $status, string $body, int $ts, string $req_nonce, string $site_id, string $kid ): string {
		return implode( "\n", array( 'OVP1R', (string) $status, hash( 'sha256', $body ), (string) $ts, $req_nonce, $site_id, $kid ) );
	}

	/* ---------- sign / verify primitives ---------- */

	public static function sign_detached( string $msg, string $sk ): string {
		self::require_sodium();
		return sodium_crypto_sign_detached( $msg, $sk );
	}

	public static function verify_detached( string $msg, string $sig, string $pub ): bool {
		self::require_sodium();
		if ( 64 !== strlen( $sig ) || 32 !== strlen( $pub ) ) {
			return false;
		}
		return sodium_crypto_sign_verify_detached( $sig, $msg, $pub );
	}

	/**
	 * Produce the X-OVP-* headers for an outbound request.
	 *
	 * @return array<string,string>
	 */
	public static function sign_request( string $method, string $path, string $raw_query, string $body, int $ts, string $nonce, string $site_id, int $tier, string $kid, string $sk ): array {
		$canon = self::canonical_request( $method, $path, $raw_query, $body, $ts, $nonce, $site_id, $tier, $kid );
		if ( null === $canon ) {
			throw new InvalidArgumentException( 'ovp: invalid envelope fields' );
		}
		return array(
			'X-OVP-Sig'   => self::b64e( self::sign_detached( $canon, $sk ) ),
			'X-OVP-Kid'   => $kid,
			'X-OVP-Ts'    => (string) $ts,
			'X-OVP-Nonce' => $nonce,
			'X-OVP-Tier'  => 'T' . $tier,
			'X-OVP-Site'  => $site_id,
		);
	}

	/**
	 * Verify an inbound request.
	 *
	 * @param array{method:string,path:string,query:string,body:string,headers:array<string,mixed>} $req
	 * @param callable(string,string):?string $lookup fn(kid, site_id) => raw 32-byte public key or null
	 * @param string $expect_site_id '' = accept header value (master side), else must match (plugin side)
	 * @param string $kid_prefix     'm' (plugin verifying master) or 'p' (master verifying plugin)
	 */
	public static function verify_request( array $req, callable $lookup, Ovp_Nonce_Store $store, int $now, int $max_body, string $expect_site_id, string $kid_prefix ): Ovp_Result {
		self::$remote_seen = true;
		if ( ! self::sodium_available() ) {
			return new Ovp_Result( false, 500, 'sodium_missing' );
		}
		$h = array();
		foreach ( (array) ( $req['headers'] ?? array() ) as $k => $v ) {
			if ( is_string( $k ) && is_string( $v ) && strlen( $v ) <= 512 ) {
				$h[ strtolower( $k ) ] = $v;
			}
		}
		foreach ( array( 'x-ovp-sig', 'x-ovp-kid', 'x-ovp-ts', 'x-ovp-nonce', 'x-ovp-tier', 'x-ovp-site' ) as $need ) {
			if ( ! isset( $h[ $need ] ) ) {
				return new Ovp_Result( false, 401, 'bad_headers' );
			}
		}
		$body = (string) ( $req['body'] ?? '' );
		if ( strlen( $body ) > $max_body ) {
			return new Ovp_Result( false, 413, 'body_too_large' );
		}
		$kid     = $h['x-ovp-kid'];
		$nonce   = $h['x-ovp-nonce'];
		$site_id = $h['x-ovp-site'];
		$tier    = self::parse_tier( $h['x-ovp-tier'] );
		if ( null === $tier || 1 !== preg_match( '/^[0-9]{1,12}$/D', $h['x-ovp-ts'] ) || ! self::valid_kid( $kid )
			|| ! self::valid_nonce( $nonce ) || ! self::valid_site_id( $site_id ) || $kid[0] !== $kid_prefix ) {
			return new Ovp_Result( false, 401, 'bad_headers' );
		}
		$ts = (int) $h['x-ovp-ts'];
		if ( abs( $now - $ts ) > self::SKEW ) {
			return new Ovp_Result( false, 401, 'skew' );
		}
		if ( '' !== $expect_site_id && ! hash_equals( $expect_site_id, $site_id ) ) {
			return new Ovp_Result( false, 401, 'wrong_site' );
		}
		$sig = self::b64d( $h['x-ovp-sig'] );
		if ( null === $sig || 64 !== strlen( $sig ) ) {
			return new Ovp_Result( false, 401, 'bad_headers' );
		}
		$canon = self::canonical_request( (string) ( $req['method'] ?? '' ), (string) ( $req['path'] ?? '' ), (string) ( $req['query'] ?? '' ), $body, $ts, $nonce, $site_id, $tier, $kid );
		if ( null === $canon ) {
			return new Ovp_Result( false, 401, 'bad_headers' );
		}
		$pub = $lookup( $kid, $site_id );
		if ( ! is_string( $pub ) || 32 !== strlen( $pub ) ) {
			return new Ovp_Result( false, 401, 'unknown_kid' );
		}
		if ( ! self::verify_detached( $canon, $sig, $pub ) ) {
			return new Ovp_Result( false, 401, 'bad_sig' );
		}
		// Only authenticated nonces are stored, so unsigned floods cannot fill the table.
		if ( ! $store->claim( $kid . "\n" . $nonce, self::NONCE_TTL, $now ) ) {
			return new Ovp_Result( false, 401, 'replay' );
		}
		return new Ovp_Result( true, 200, 'ok', $kid, $site_id, $tier, $nonce );
	}

	/**
	 * Sign a response bound to the request nonce.
	 *
	 * @return array<string,string>
	 */
	public static function sign_response( int $status, string $body, int $ts, string $req_nonce, string $site_id, string $kid, string $sk ): array {
		$canon = self::canonical_response( $status, $body, $ts, $req_nonce, $site_id, $kid );
		return array(
			'X-OVP-Sig' => self::b64e( self::sign_detached( $canon, $sk ) ),
			'X-OVP-Kid' => $kid,
			'X-OVP-Ts'  => (string) $ts,
		);
	}

	/**
	 * @param array<string,mixed> $headers
	 * @param callable(string,string):?string $lookup
	 */
	public static function verify_response( int $status, string $body, array $headers, string $req_nonce, string $site_id, callable $lookup, int $now, string $kid_prefix ): Ovp_Result {
		if ( ! self::sodium_available() ) {
			return new Ovp_Result( false, 500, 'sodium_missing' );
		}
		$h = array();
		foreach ( $headers as $k => $v ) {
			if ( is_string( $k ) && is_string( $v ) && strlen( $v ) <= 512 ) {
				$h[ strtolower( $k ) ] = $v;
			}
		}
		foreach ( array( 'x-ovp-sig', 'x-ovp-kid', 'x-ovp-ts' ) as $need ) {
			if ( ! isset( $h[ $need ] ) ) {
				return new Ovp_Result( false, 401, 'bad_headers' );
			}
		}
		$kid = $h['x-ovp-kid'];
		$sig = self::b64d( $h['x-ovp-sig'] );
		if ( ! self::valid_kid( $kid ) || $kid[0] !== $kid_prefix || 1 !== preg_match( '/^[0-9]{1,12}$/D', $h['x-ovp-ts'] ) || null === $sig || 64 !== strlen( $sig ) ) {
			return new Ovp_Result( false, 401, 'bad_headers' );
		}
		$ts = (int) $h['x-ovp-ts'];
		if ( abs( $now - $ts ) > self::SKEW ) {
			return new Ovp_Result( false, 401, 'skew' );
		}
		$pub = $lookup( $kid, $site_id );
		if ( ! is_string( $pub ) || 32 !== strlen( $pub ) ) {
			return new Ovp_Result( false, 401, 'unknown_kid' );
		}
		if ( ! self::verify_detached( self::canonical_response( $status, $body, $ts, $req_nonce, $site_id, $kid ), $sig, $pub ) ) {
			return new Ovp_Result( false, 401, 'bad_sig' );
		}
		return new Ovp_Result( true, 200, 'ok', $kid, $site_id );
	}
}
