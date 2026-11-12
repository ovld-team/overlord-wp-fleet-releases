<?php
/**
 * Key store: plugin Ed25519 keypair (secret wrapped with a wp-config-derived key, so a
 * database-only leak cannot forge requests) and pinned master public keys (max 2).
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ovp_Keys {

	public const OPTION          = 'ovp_keystore';
	public const MAX_MASTER_KEYS = 2;
	public const RETIRE_SECONDS  = 2592000; // 30 days.
	public const MODES           = array( 'local', 'remote', 'proxy', 'direct' );

	/* ---------- raw store ---------- */

	/** @return array<string,mixed> */
	private static function load(): array {
		$v = get_option( self::OPTION, array() );
		return is_array( $v ) ? $v : array();
	}

	/** @param array<string,mixed> $d */
	private static function save( array $d ): void {
		$d['v'] = 1;
		update_option( self::OPTION, $d, false );
	}

	public static function state(): string {
		$s = self::load()['state'] ?? 'unbound';
		return in_array( $s, array( 'pending', 'bound' ), true ) ? $s : 'unbound';
	}

	public static function is_bound(): bool {
		return 'bound' === self::state();
	}

	public static function site_id(): string {
		$d = self::load();
		return self::is_bound() ? (string) ( $d['site_id'] ?? '' ) : '';
	}

	public static function mode(): string {
		$d = self::load();
		$m = (string) ( $d['mode'] ?? '' );
		return in_array( $m, self::MODES, true ) ? $m : '';
	}

	/* ---------- wrapping ---------- */

	/**
	 * 32-byte key derived from wp-config secrets that never live in the database.
	 *
	 * @throws RuntimeException when no strong secret is available.
	 */
	private static function wrap_key(): string {
		$material = '';
		if ( defined( 'OVP_KEY_WRAP_SECRET' ) ) {
			$material = (string) constant( 'OVP_KEY_WRAP_SECRET' );
		} else {
			foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT' ) as $c ) {
				$material .= defined( $c ) ? (string) constant( $c ) : '';
			}
		}
		if ( strlen( $material ) < 32 || str_contains( $material, 'put your unique phrase here' ) ) {
			throw new RuntimeException( 'Overlord WP Fleet refuses to store keys: wp-config.php salts are missing or default. Generate fresh salts first.' );
		}
		return hash_hmac( 'sha256', 'ovp-keywrap-v1', $material, true );
	}

	private static function wrap( string $secret ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return Ovp_Proto::b64e( $nonce . sodium_crypto_secretbox( $secret, $nonce, self::wrap_key() ) );
	}

	private static function unwrap( string $b64 ): ?string {
		$raw = Ovp_Proto::b64d( $b64 );
		if ( null === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$out = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::wrap_key() );
		return false === $out ? null : $out;
	}

	/** HMAC key for T1 confirm tokens: wp-config secret mixed with a stored random. */
	public static function confirm_key(): string {
		$d = self::load();
		if ( ! isset( $d['confirm_rand'] ) || ! is_string( $d['confirm_rand'] ) ) {
			$d['confirm_rand'] = Ovp_Proto::b64e( random_bytes( 32 ) );
			self::save( $d );
		}
		return hash_hmac( 'sha256', 'ovp-confirm-v1' . (string) Ovp_Proto::b64d( $d['confirm_rand'] ), self::wrap_key(), true );
	}

	/* ---------- plugin keypair ---------- */

	/**
	 * Create a fresh keypair for an upcoming pairing. Refuses when already bound.
	 *
	 * @return array{kid:string,pub:string} pub is raw 32 bytes.
	 */
	public static function generate_pending(): array {
		Ovp_Proto::require_sodium();
		if ( self::is_bound() ) {
			throw new RuntimeException( 'already_bound' );
		}
		$kp  = sodium_crypto_sign_keypair();
		$sk  = sodium_crypto_sign_secretkey( $kp );
		$pub = sodium_crypto_sign_publickey( $kp );
		self::save(
			array(
				'state'  => 'pending',
				'plugin' => array(
					'kid' => 'p1',
					'pub' => Ovp_Proto::b64e( $pub ),
					'sk'  => self::wrap( $sk ),
				),
			)
		);
		self::zero( $kp );
		self::zero( $sk );
		return array( 'kid' => 'p1', 'pub' => $pub );
	}

	/**
	 * Best-effort wipe of a secret held in a PHP string. Without ext-sodium WordPress ships the sodium_compat polyfill,
	 * whose sodium_memzero() throws SodiumException (PHP cannot wipe memory); that must not abort pairing or signing.
	 */
	private static function zero( string &$buf ): void {
		try {
			sodium_memzero( $buf );
		} catch ( Throwable $e ) {
			$buf = ''; // The polyfill cannot overwrite in place; at least drop our reference.
		}
	}

	/** Sign with a pending (pairing proof) or active plugin key. */
	public static function sign( string $msg, ?string $kid = null ): string {
		$d    = self::load();
		$slot = $d['plugin'] ?? null;
		if ( null !== $kid && isset( $d['plugin_next']['kid'] ) && hash_equals( (string) $d['plugin_next']['kid'], $kid ) ) {
			$slot = $d['plugin_next'];
		}
		if ( ! is_array( $slot ) || ! isset( $slot['sk'] ) ) {
			throw new RuntimeException( 'no_key' );
		}
		$sk = self::unwrap( (string) $slot['sk'] );
		if ( null === $sk ) {
			throw new RuntimeException( 'key_unreadable' );
		}
		$sig = Ovp_Proto::sign_detached( $msg, $sk );
		self::zero( $sk );
		return $sig;
	}

	public static function plugin_kid(): string {
		return (string) ( self::load()['plugin']['kid'] ?? '' );
	}

	/** Raw 32-byte plugin public key or ''. */
	public static function plugin_pub(): string {
		return (string) Ovp_Proto::b64d( (string) ( self::load()['plugin']['pub'] ?? '' ) );
	}

	/**
	 * Outbound signed request headers (plugin -> master/relay).
	 *
	 * @return array<string,string>
	 */
	public static function sign_request( string $method, string $path, string $raw_query, string $body, int $tier, ?int $now = null ): array {
		if ( ! self::is_bound() ) {
			throw new RuntimeException( 'not_bound' );
		}
		$d  = self::load();
		$sk = self::unwrap( (string) ( $d['plugin']['sk'] ?? '' ) );
		if ( null === $sk ) {
			throw new RuntimeException( 'key_unreadable' );
		}
		$h = Ovp_Proto::sign_request( $method, $path, $raw_query, $body, $now ?? time(), Ovp_Proto::new_nonce(), (string) $d['site_id'], $tier, (string) $d['plugin']['kid'], $sk );
		self::zero( $sk );
		return $h;
	}

	/* ---------- bind / unbind ---------- */

	/**
	 * Promote the pending key to bound. Refuses re-bind.
	 *
	 * @param array{kid:string,pub:string} $master raw pub.
	 */
	public static function finalize_bind( string $site_id, string $mode, array $master ): void {
		$d = self::load();
		if ( self::is_bound() ) {
			throw new RuntimeException( 'already_bound' );
		}
		if ( 'pending' !== ( $d['state'] ?? '' ) || ! in_array( $mode, self::MODES, true ) || ! Ovp_Proto::valid_site_id( $site_id )
			|| ! Ovp_Proto::valid_kid( $master['kid'] ) || 'm' !== $master['kid'][0] || 32 !== strlen( $master['pub'] ) ) {
			throw new RuntimeException( 'bad_bind' );
		}
		$d['state']   = 'bound';
		$d['site_id'] = $site_id;
		$d['mode']    = $mode;
		$d['bound_at'] = time();
		$d['master']  = array(
			array(
				'kid'       => $master['kid'],
				'pub'       => Ovp_Proto::b64e( $master['pub'] ),
				'status'    => 'active',
				'added'     => time(),
				'retire_by' => 0,
			),
		);
		self::save( $d );
	}

	/** Drop everything (wp-admin Unpair, signed revoke, failed pairing). */
	public static function wipe( string $tombstone = '' ): void {
		delete_option( self::OPTION );
		if ( '' !== $tombstone ) {
			update_option( self::OPTION, array( 'v' => 1, 'state' => 'unbound', 'tombstone' => $tombstone, 'at' => time() ), false );
		}
	}

	/* ---------- master keys ---------- */

	/** @return list<array{kid:string,pub:string,status:string,added:int,retire_by:int}> */
	public static function master_keys( ?int $now = null ): array {
		$now = $now ?? time();
		$d   = self::load();
		$out = array();
		foreach ( (array) ( $d['master'] ?? array() ) as $k ) {
			if ( ! is_array( $k ) || ! isset( $k['kid'], $k['pub'] ) ) {
				continue;
			}
			if ( 'retiring' === ( $k['status'] ?? '' ) && (int) ( $k['retire_by'] ?? 0 ) <= $now ) {
				continue; // Past the 30-day retirement window.
			}
			$out[] = array(
				'kid'       => (string) $k['kid'],
				'pub'       => (string) $k['pub'],
				'status'    => (string) ( $k['status'] ?? 'active' ),
				'added'     => (int) ( $k['added'] ?? 0 ),
				'retire_by' => (int) ( $k['retire_by'] ?? 0 ),
			);
		}
		return $out;
	}

	public static function active_master_kid( ?int $now = null ): string {
		foreach ( self::master_keys( $now ) as $k ) {
			if ( 'active' === $k['status'] ) {
				return $k['kid'];
			}
		}
		return '';
	}

	/** Lookup callback for Ovp_Proto::verify_request on the plugin. Constant-time over all keys. */
	public static function lookup_master( string $kid, string $site_id = '', ?int $now = null ): ?string {
		if ( ! self::is_bound() ) {
			return null;
		}
		$found = null;
		foreach ( self::master_keys( $now ) as $k ) {
			if ( hash_equals( $k['kid'], $kid ) ) {
				$pub = Ovp_Proto::b64d( $k['pub'] );
				if ( null !== $pub && 32 === strlen( $pub ) ) {
					$found = $pub;
				}
			}
		}
		return $found;
	}

	/** Pin master key n+1 (already verified as signed by the active key n). Keeps at most 2 keys. */
	public static function add_master_key( string $kid, string $pub, ?int $now = null ): void {
		$now = $now ?? time();
		$d   = self::load();
		if ( ! self::is_bound() || ! Ovp_Proto::valid_kid( $kid ) || 'm' !== $kid[0] || 32 !== strlen( $pub ) ) {
			throw new RuntimeException( 'bad_master_key' );
		}
		$keys = self::master_keys( $now );
		foreach ( $keys as $k ) {
			if ( hash_equals( $k['kid'], $kid ) ) {
				throw new RuntimeException( 'duplicate_kid' );
			}
		}
		$max = 0;
		foreach ( $keys as $k ) {
			$max = max( $max, (int) substr( $k['kid'], 1 ) );
		}
		if ( (int) substr( $kid, 1 ) <= $max ) {
			throw new RuntimeException( 'kid_not_monotonic' );
		}
		$next = array();
		foreach ( $keys as $k ) {
			if ( 'active' === $k['status'] ) {
				$k['status']    = 'retiring';
				$k['retire_by'] = $now + self::RETIRE_SECONDS;
			}
			$next[] = $k;
		}
		$next[] = array( 'kid' => $kid, 'pub' => Ovp_Proto::b64e( $pub ), 'status' => 'active', 'added' => $now, 'retire_by' => 0 );
		usort( $next, static fn( array $a, array $b ): int => (int) substr( $a['kid'], 1 ) <=> (int) substr( $b['kid'], 1 ) );
		$next = array_slice( $next, -self::MAX_MASTER_KEYS );
		$d['master'] = $next;
		self::save( $d );
	}

	/** Remove a retiring master key (master says all sites acked). The active key cannot be removed. */
	public static function retire_master_key( string $kid, ?int $now = null ): bool {
		$d    = self::load();
		$keep = array();
		$hit  = false;
		foreach ( self::master_keys( $now ) as $k ) {
			if ( hash_equals( $k['kid'], $kid ) && 'active' !== $k['status'] ) {
				$hit = true;
				continue;
			}
			$keep[] = $k;
		}
		if ( $hit ) {
			$d['master'] = $keep;
			self::save( $d );
		}
		return $hit;
	}

	/* ---------- plugin key rotation ---------- */

	/** @return array{kid:string,pub:string} raw pub of the proposed key. */
	public static function stage_next_plugin_key(): array {
		Ovp_Proto::require_sodium();
		$d = self::load();
		if ( ! self::is_bound() ) {
			throw new RuntimeException( 'not_bound' );
		}
		$n   = (int) substr( (string) $d['plugin']['kid'], 1 ) + 1;
		$kp  = sodium_crypto_sign_keypair();
		$sk  = sodium_crypto_sign_secretkey( $kp );
		$pub = sodium_crypto_sign_publickey( $kp );
		$d['plugin_next'] = array( 'kid' => 'p' . $n, 'pub' => Ovp_Proto::b64e( $pub ), 'sk' => self::wrap( $sk ) );
		self::save( $d );
		self::zero( $kp );
		self::zero( $sk );
		return array( 'kid' => 'p' . $n, 'pub' => $pub );
	}

	/** Master acked: switch signing to the staged key and drop the old one. */
	public static function commit_next_plugin_key( string $kid ): bool {
		$d = self::load();
		if ( ! isset( $d['plugin_next']['kid'] ) || ! hash_equals( (string) $d['plugin_next']['kid'], $kid ) ) {
			return false;
		}
		$d['plugin'] = $d['plugin_next'];
		unset( $d['plugin_next'] );
		self::save( $d );
		return true;
	}
}
