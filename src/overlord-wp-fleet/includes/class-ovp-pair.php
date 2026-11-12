<?php
/**
 * One-shot pairing, master/plugin key rotation, signed revoke.
 *
 * Pairing is trust-on-first-use hardened by: out-of-band master fingerprint in the
 * pairing string, proof-of-possession of the plugin key, a master signature over the
 * whole transcript, and a hard refusal to re-bind once a key exists.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ovp_Pair {

	public const LOCK_OPTION = 'ovp_pair_lock';
	public const MAX_RESPONSE = 65536;

	/* ---------- canonical transcripts (shared with TS via docs/proto-vectors.json) ---------- */

	public static function canon_pop( string $code_sha256, string $site_url, string $plugin_kid, string $plugin_pub_b64 ): string {
		return implode( "\n", array( 'OVP1-PAIR', $code_sha256, $site_url, $plugin_kid, $plugin_pub_b64 ) );
	}

	/**
	 * Pairing-code possession proof. The raw code never goes on the wire: only code_id (sha256 of
	 * the code) and code_mac = HMAC-SHA256(verifier, canon_pop). verifier = sha256hex("OVP1-PAIRK\n" . code).
	 */
	public static function pair_verifier( string $code ): string {
		return hash( 'sha256', "OVP1-PAIRK\n" . $code );
	}

	public static function pair_mac( string $verifier_hex, string $canon_pop ): string {
		return hash_hmac( 'sha256', $canon_pop, (string) hex2bin( $verifier_hex ) );
	}

	public static function canon_paired( string $code_sha256, string $site_id, string $plugin_kid, string $plugin_pub_b64, string $master_kid, string $master_pub_b64, string $mode ): string {
		return implode( "\n", array( 'OVP1-PAIRED', $code_sha256, $site_id, $plugin_kid, $plugin_pub_b64, $master_kid, $master_pub_b64, $mode ) );
	}

	public static function canon_rot_master( string $site_id, string $old_kid, string $new_kid, string $new_pub_b64 ): string {
		return implode( "\n", array( 'OVP1-ROTM', $site_id, $old_kid, $new_kid, $new_pub_b64 ) );
	}

	public static function canon_rot_plugin( string $site_id, string $old_kid, string $new_kid, string $new_pub_b64 ): string {
		return implode( "\n", array( 'OVP1-ROTP', $site_id, $old_kid, $new_kid, $new_pub_b64 ) );
	}

	/**
	 * Separate signed statement of the tier a pairing grants the master. OVP1-PAIRED stays untouched
	 * so a plugin or master from before the grant still agrees on that transcript.
	 */
	public static function canon_paired_tier( string $code_sha256, string $site_id, string $plugin_kid, string $plugin_pub_b64, string $master_kid, string $tier_name ): string {
		return implode( "\n", array( 'OVP1-PAIRED-TIER', $code_sha256, $site_id, $plugin_kid, $plugin_pub_b64, $master_kid, $tier_name ) );
	}

	public static function fingerprint( string $master_pub_raw ): string {
		return substr( hash( 'sha256', $master_pub_raw ), 0, 16 );
	}

	/* ---------- pairing string ---------- */

	/**
	 * Accepts "<32 hex>.<16 hex fingerprint>" or "<32 hex>.<16 hex fingerprint>.t<0-3>" (dashes/spaces in
	 * the code part tolerated). The optional last part is the tier the master grants; it travels out of
	 * band with the code so the consent screen can show it before anything is sent, and the master's
	 * reply must repeat it under its signature.
	 *
	 * @return array{code:string,fp:string,tier:?int}|null
	 */
	public static function parse_pairing_string( string $in ): ?array {
		$in = strtolower( trim( $in ) );
		if ( strlen( $in ) > 128 ) {
			return null;
		}
		$parts = explode( '.', $in );
		if ( 2 !== count( $parts ) && 3 !== count( $parts ) ) {
			return null;
		}
		$code = str_replace( array( '-', ' ' ), '', $parts[0] );
		if ( 1 !== preg_match( '/^[0-9a-f]{32}$/D', $code ) || 1 !== preg_match( '/^[0-9a-f]{16}$/D', $parts[1] ) ) {
			return null;
		}
		$tier = null;
		if ( 3 === count( $parts ) ) {
			if ( 1 !== preg_match( '/^t([0-3])$/D', $parts[2], $m ) ) {
				return null;
			}
			$tier = (int) $m[1];
		}
		return array( 'code' => $code, 'fp' => $parts[1], 'tier' => $tier );
	}

	/**
	 * Exactly what the consent screen must show; its hash must be echoed to pair().
	 *
	 * @param array{site_url:string,plugin_version:string,php:string,wp:string} $meta
	 * @param int|null $announced Tier from the pairing string, null when it carries none.
	 * @return array{summary:array<string,string>,hash:string}
	 */
	public static function consent_summary( string $pair_url, string $mode, array $meta, ?int $announced = null ): array {
		$grant   = self::grant_for( $announced );
		$summary = array(
			'send_to'        => $pair_url,
			'mode'           => $mode,
			'site_url'       => $meta['site_url'],
			'plugin_version' => $meta['plugin_version'],
			'php_version'    => $meta['php'],
			'wp_version'     => $meta['wp'],
			'plugin_public_key' => 'a newly generated Ed25519 public key (the secret key never leaves this site)',
			'pairing_code'   => 'the one-time code you entered',
			'tier_granted'   => Ovp_Tiers::describe( $grant ) . ( null === $announced ? ' (the pairing string names no tier, so the safe default applies)' : ( $grant < $announced ? ' (limited by this build; the master offered ' . Ovp_Tiers::name( $announced ) . ')' : '' ) ),
		);
		return array( 'summary' => $summary, 'hash' => hash( 'sha256', (string) json_encode( $summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
	}

	/** Tier this site will accept for a pairing string: the announced one, never above this build's maximum. */
	public static function grant_for( ?int $announced ): int {
		return null === $announced ? Ovp_Tiers::DEFAULT_CAP : max( 0, min( Ovp_Tiers::max_cap(), $announced ) );
	}

	/* ---------- HTTP ---------- */

	/**
	 * Default transport (WordPress only): no redirects, 64KB cap, TLS verified.
	 *
	 * @return array{status:int,body:string}
	 */
	public static function default_transport( string $url, string $json ): array {
		$r = wp_remote_post(
			$url,
			array(
				'timeout'             => 15,
				'redirection'         => 0,
				'sslverify'           => true,
				'limit_response_size' => self::MAX_RESPONSE,
				'headers'             => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
				'body'                => $json,
			)
		);
		if ( is_wp_error( $r ) ) {
			return array( 'status' => 0, 'body' => '' );
		}
		return array( 'status' => (int) wp_remote_retrieve_response_code( $r ), 'body' => (string) wp_remote_retrieve_body( $r ) );
	}

	public static function valid_pair_url( string $url, string $mode ): bool {
		$p = parse_url( $url );
		if ( ! is_array( $p ) || empty( $p['host'] ) || isset( $p['user'] ) || isset( $p['pass'] ) || isset( $p['fragment'] ) || strlen( $url ) > 512 ) {
			return false;
		}
		$scheme = strtolower( (string) ( $p['scheme'] ?? '' ) );
		if ( 'https' === $scheme ) {
			return true;
		}
		$host = strtolower( (string) $p['host'] );
		return 'http' === $scheme && 'local' === $mode && in_array( $host, array( 'localhost', '127.0.0.1', '[::1]' ), true );
	}

	/* ---------- pairing ---------- */

	/**
	 * Run the one-shot pairing.
	 *
	 * @param array{site_url:string,plugin_version:string,php:string,wp:string} $meta
	 * @param callable(string,string):array{status:int,body:string} $transport
	 * @param bool $implied_admin Skip the manage_options check (autopair only, see Ovp_Migration).
	 * @return array{ok:bool,code:string,site_id?:string,tier?:int}
	 */
	public static function pair( string $pairing_string, string $pair_url, string $mode, string $consent_hash, array $meta, callable $transport, ?int $now = null, bool $implied_admin = false ): array {
		$fail = static fn( string $code ): array => array( 'ok' => false, 'code' => $code );
		if ( ! Ovp_Proto::sodium_available() ) {
			return $fail( 'sodium_missing' );
		}
		// $implied_admin is set only by Ovp_Migration (autopair): the one-shot option it consumes can only
		// be written with administrator-level access already. A signed remote request never pairs.
		if ( Ovp_Proto::remote_seen() || ( ! $implied_admin && ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) ) ) {
			return $fail( 'forbidden' );
		}
		if ( ! in_array( $mode, Ovp_Keys::MODES, true ) ) {
			return $fail( 'bad_mode' );
		}
		$ps = self::parse_pairing_string( $pairing_string );
		if ( null === $ps ) {
			return $fail( 'bad_code' );
		}
		if ( ! self::valid_pair_url( $pair_url, $mode ) ) {
			return $fail( 'bad_url' );
		}
		$consent = self::consent_summary( $pair_url, $mode, $meta, $ps['tier'] );
		if ( ! hash_equals( $consent['hash'], $consent_hash ) ) {
			return $fail( 'consent_required' );
		}
		if ( Ovp_Keys::is_bound() ) {
			return $fail( 'already_bound' ); // Re-bind refused: wp-admin Unpair + master Revoke first.
		}
		if ( ! self::lock( $now ?? time() ) ) {
			return $fail( 'busy' );
		}
		try {
			return self::do_pair( $ps, $pair_url, $mode, $meta, $transport );
		} catch ( Throwable $e ) {
			Ovp_Keys::wipe();
			return $fail( 'pair_error' );
		} finally {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * @param array{code:string,fp:string,tier:?int} $ps
	 * @param array{site_url:string,plugin_version:string,php:string,wp:string} $meta
	 * @return array{ok:bool,code:string,site_id?:string,tier?:int}
	 */
	private static function do_pair( array $ps, string $pair_url, string $mode, array $meta, callable $transport ): array {
		$fail = static function ( string $code ): array {
			Ovp_Keys::wipe();
			return array( 'ok' => false, 'code' => $code );
		};
		$key        = Ovp_Keys::generate_pending();
		$pub_b64    = Ovp_Proto::b64e( $key['pub'] );
		$code_hash  = hash( 'sha256', $ps['code'] );
		$canon_pop  = self::canon_pop( $code_hash, $meta['site_url'], $key['kid'], $pub_b64 );
		$pop        = Ovp_Proto::b64e( Ovp_Keys::sign( $canon_pop ) );
		$req        = array(
			'v'              => 1,
			'code_id'        => $code_hash,
			'code_mac'       => self::pair_mac( self::pair_verifier( $ps['code'] ), $canon_pop ),
			'site_url'       => $meta['site_url'],
			'plugin_kid'     => $key['kid'],
			'plugin_pub'     => $pub_b64,
			'pop'            => $pop,
			'mode'           => $mode,
			'plugin_version' => $meta['plugin_version'],
			'php'            => $meta['php'],
			'wp'             => $meta['wp'],
		);
		$res = $transport( $pair_url, (string) json_encode( $req, JSON_UNESCAPED_SLASHES ) );
		if ( 200 !== ( $res['status'] ?? 0 ) || strlen( (string) ( $res['body'] ?? '' ) ) > self::MAX_RESPONSE ) {
			return $fail( 'master_rejected' );
		}
		$r = json_decode( (string) $res['body'], true, 8 );
		if ( ! is_array( $r ) || 1 !== ( $r['v'] ?? null ) || ! is_array( $r['master'] ?? null ) ) {
			return $fail( 'bad_response' );
		}
		foreach ( array( 'site_id', 'mode', 'plugin_kid', 'plugin_pub', 'code_sha256', 'sig' ) as $f ) {
			if ( ! isset( $r[ $f ] ) || ! is_string( $r[ $f ] ) ) {
				return $fail( 'bad_response' );
			}
		}
		$mk_kid = (string) ( $r['master']['kid'] ?? '' );
		$mk_pub = Ovp_Proto::b64d( (string) ( $r['master']['pub'] ?? '' ) );
		$sig    = Ovp_Proto::b64d( $r['sig'] );
		if ( null === $mk_pub || 32 !== strlen( $mk_pub ) || null === $sig || ! Ovp_Proto::valid_kid( $mk_kid ) || 'm' !== $mk_kid[0] || ! Ovp_Proto::valid_site_id( $r['site_id'] ) ) {
			return $fail( 'bad_response' );
		}
		// Out-of-band fingerprint must match the pinned master key.
		if ( ! hash_equals( $ps['fp'], self::fingerprint( $mk_pub ) ) ) {
			return $fail( 'fingerprint_mismatch' );
		}
		// Echo checks: a relay that swapped our key/code cannot make these match.
		if ( ! hash_equals( $r['mode'], $mode ) || ! hash_equals( $r['plugin_kid'], $key['kid'] ) || ! hash_equals( $r['plugin_pub'], $pub_b64 ) || ! hash_equals( $r['code_sha256'], hash( 'sha256', $ps['code'] ) ) ) {
			return $fail( 'transcript_mismatch' );
		}
		$canon = self::canon_paired( $r['code_sha256'], $r['site_id'], $r['plugin_kid'], $r['plugin_pub'], $mk_kid, (string) $r['master']['pub'], $r['mode'] );
		if ( ! Ovp_Proto::verify_detached( $canon, $sig, $mk_pub ) ) {
			return $fail( 'bad_master_signature' );
		}
		// The tier the admin read on the consent screen must be repeated by the master under its own
		// signature. Nothing above what the screen showed is ever stored; a string without a tier grants the default.
		if ( null !== $ps['tier'] ) {
			$tn  = $r['tier_cap'] ?? null;
			$tsg = isset( $r['tier_sig'] ) && is_string( $r['tier_sig'] ) ? Ovp_Proto::b64d( $r['tier_sig'] ) : null;
			if ( ! is_string( $tn ) || null === $tsg || 1 !== preg_match( '/^T[0-3]$/D', $tn ) ) {
				return $fail( 'tier_mismatch' );
			}
			if ( ! Ovp_Proto::verify_detached( self::canon_paired_tier( $r['code_sha256'], $r['site_id'], $r['plugin_kid'], $r['plugin_pub'], $mk_kid, $tn ), $tsg, $mk_pub ) ) {
				return $fail( 'bad_tier_signature' );
			}
			if ( (int) $tn[1] !== $ps['tier'] ) {
				return $fail( 'tier_mismatch' );
			}
		}
		$grant = self::grant_for( $ps['tier'] );
		Ovp_Keys::finalize_bind( $r['site_id'], $mode, array( 'kid' => $mk_kid, 'pub' => $mk_pub ) );
		Ovp_Tiers::grant_at_pairing( null === $ps['tier'] ? null : $grant );
		return array( 'ok' => true, 'code' => 'paired', 'site_id' => $r['site_id'], 'tier' => $grant );
	}

	private static function lock( int $now ): bool {
		if ( add_option( self::LOCK_OPTION, $now + 60, '', false ) ) {
			return true;
		}
		if ( (int) get_option( self::LOCK_OPTION, 0 ) <= $now ) {
			update_option( self::LOCK_OPTION, $now + 60, false );
			return true;
		}
		return false;
	}

	/** wp-admin "Unpair". Caller verifies the nonce. Master must also Revoke before re-pair is accepted there. */
	public static function unpair(): bool {
		if ( Ovp_Proto::remote_seen() || ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		Ovp_Keys::wipe( 'unpaired_by_admin' );
		return true;
	}

	/* ---------- master key rotation ---------- */

	/**
	 * Master announced key n+1, signed by the active key n (arrives in a heartbeat response
	 * that is itself envelope-verified; this check makes the rotation self-authenticating).
	 *
	 * @return array{ok:bool,code:string}
	 */
	public static function accept_master_rotation( string $old_kid, string $new_kid, string $new_pub_b64, string $sig_b64, ?int $now = null ): array {
		if ( ! Ovp_Keys::is_bound() || ! hash_equals( Ovp_Keys::active_master_kid( $now ), $old_kid ) ) {
			return array( 'ok' => false, 'code' => 'old_kid_not_active' );
		}
		$new_pub = Ovp_Proto::b64d( $new_pub_b64 );
		$sig     = Ovp_Proto::b64d( $sig_b64 );
		$old_pub = Ovp_Keys::lookup_master( $old_kid, '', $now );
		if ( null === $new_pub || 32 !== strlen( $new_pub ) || null === $sig || null === $old_pub || ! Ovp_Proto::valid_kid( $new_kid ) || 'm' !== $new_kid[0] ) {
			return array( 'ok' => false, 'code' => 'bad_rotation' );
		}
		if ( ! Ovp_Proto::verify_detached( self::canon_rot_master( Ovp_Keys::site_id(), $old_kid, $new_kid, $new_pub_b64 ), $sig, $old_pub ) ) {
			return array( 'ok' => false, 'code' => 'bad_rotation_sig' );
		}
		try {
			Ovp_Keys::add_master_key( $new_kid, $new_pub, $now );
		} catch ( RuntimeException $e ) {
			return array( 'ok' => false, 'code' => $e->getMessage() );
		}
		return array( 'ok' => true, 'code' => 'rotated' );
	}

	/* ---------- plugin key rotation ---------- */

	/**
	 * Propose a new plugin key signed by the current one. Send the result to the master.
	 *
	 * @return array{old_kid:string,new_kid:string,new_pub:string,sig:string}
	 */
	public static function propose_plugin_rotation(): array {
		$old_kid = Ovp_Keys::plugin_kid();
		$next    = Ovp_Keys::stage_next_plugin_key();
		$pub_b64 = Ovp_Proto::b64e( $next['pub'] );
		$sig     = Ovp_Keys::sign( self::canon_rot_plugin( Ovp_Keys::site_id(), $old_kid, $next['kid'], $pub_b64 ) );
		return array( 'old_kid' => $old_kid, 'new_kid' => $next['kid'], 'new_pub' => $pub_b64, 'sig' => Ovp_Proto::b64e( $sig ) );
	}

	/** Call after a verified master ack. */
	public static function commit_plugin_rotation( string $new_kid ): bool {
		return Ovp_Keys::commit_next_plugin_key( $new_kid );
	}

	/* ---------- revoke ---------- */

	/**
	 * Execute a signed revoke. The envelope must already be verified (Ovp_Result ok) against
	 * a master key. Wipes all keys; the plugin is inert until paired again by an admin.
	 *
	 * @return array{ok:bool,code:string}
	 */
	public static function handle_revoke( Ovp_Result $verified, string $method, string $path, string $body ): array {
		if ( ! $verified->ok || ! Ovp_Keys::is_bound() || 'POST' !== $method || '/ovp/v1/revoke' !== $path || $verified->kid[0] !== 'm' ) {
			return array( 'ok' => false, 'code' => 'forbidden' );
		}
		$j = json_decode( $body, true, 4 );
		if ( ! is_array( $j ) || 'revoke' !== ( $j['cmd'] ?? null ) || ! hash_equals( Ovp_Keys::site_id(), (string) ( $j['site_id'] ?? '' ) ) || ! hash_equals( Ovp_Keys::site_id(), $verified->site_id ) ) {
			return array( 'ok' => false, 'code' => 'bad_revoke' );
		}
		Ovp_Keys::wipe( 'revoked_by_master' );
		return array( 'ok' => true, 'code' => 'revoked' );
	}
}
