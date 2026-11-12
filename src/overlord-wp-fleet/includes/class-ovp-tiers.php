<?php
/**
 * Tier model + plugin-side cap + T1 two-call confirm.
 *
 * T0 read, T1 safe content/media writes (two-call confirm), T2 code/files/plugins/DB
 * write, T3 god (no limit except the service-account guard). Effective tier =
 * min(signed tier, plugin cap).
 *
 * The cap is set by the human at pairing: the consent screen shows the tier being granted and
 * pairing stores it as both the cap and the ceiling. After that:
 *  - wp-admin can set any cap, open a timed god window, or unpair at any time. A cap lowered in
 *    wp-admin also lowers the ceiling, so a human lower holds against the master.
 *  - a signed remote `set_cap` may always lower the cap, and may raise it only when `raise` is on
 *    and the target is within the ceiling stored at pairing (never above it).
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ovp_Tiers {

	public const T0 = 0;
	public const T1 = 1;
	public const T2 = 2;
	public const T3 = 3;

	public const CAP_OPTION   = 'ovp_tier_cap';
	public const CONFIRM_TTL  = 300;
	public const DEFAULT_CAP  = 1;

	/** Tool => tier. Unknown tools are T3 (fail closed). */
	public const TOOLS = array(
		// T0 read.
		'site_info' => 0, 'plugins_list' => 0, 'themes_list' => 0, 'content_get' => 0, 'content_list' => 0,
		'media_list' => 0, 'meta_get' => 0, 'meta_keys' => 0, 'db_select' => 0, 'logs_php' => 0,
		'updates_status' => 0, 'inventory' => 0, 'heartbeat' => 0,
		// T1 safe content writes (two-call confirm).
		'content_create' => 1, 'content_update' => 1, 'content_delete' => 1, 'media_upload' => 1,
		'media_rename' => 1, 'media_delete' => 1, 'meta_set' => 1, 'meta_commit' => 1, 'meta_rollback' => 1,
		'apply_elementor' => 1, 'apply_gutenberg' => 1, 'apply_wpbakery' => 1, 'seo_set' => 1,
		// T2 structural.
		'options_set' => 2, 'plugin_deactivate' => 2,
		'plugin_delete' => 2, 'theme_delete' => 2,
		'users_create' => 2, 'users_update' => 2, 'users_delete' => 2, 'cron_set' => 2, 'cron_delete' => 2,
		'search_replace' => 2, 'redirects_set' => 2, 'cache_purge' => 2, 'files_read' => 2, 'rotate_keys' => 2,
		// T3 raw.
		'db_exec' => 3, 'files_write' => 3, 'files_delete' => 3, 'apply_sql' => 3, 'apply_wxr' => 3,
		'magic_login' => 3, 'oob' => 3, 'set_cap' => 3, 'self_update' => 3, 'self_rollback' => 3,
		// Installing/activating uploaded code runs arbitrary PHP in the web request: T3 (UM-3).
		'plugin_install' => 3, 'plugin_activate' => 3, 'theme_install' => 3, 'theme_activate' => 3,
		'zip_upload' => 3, 'upload_begin' => 3, 'upload_chunk' => 3, 'upload_finalize' => 3,
	);

	/** Test hook only. */
	public static bool $skip_admin_nonce = false;

	public static function name( int $tier ): string {
		return 'T' . max( 0, min( 3, $tier ) );
	}

	public static function parse( string $s ): ?int {
		return Ovp_Proto::parse_tier( $s );
	}

	public static function tool_tier( string $tool ): int {
		return self::TOOLS[ $tool ] ?? self::T3;
	}

	/**
	 * Effective tier name ("T0".."T3") of the verified request: min(signed tier, plugin cap).
	 * Integration seam for OVP_Meta::request_tier(). Fails closed to T0 when no request
	 * was verified in this process. The signed tier lives inside the Ed25519 signature, so a
	 * header edited after signing never reaches this point.
	 */
	public static function effective_tier( mixed $request = null ): string {
		return Ovp_Auth::effective_tier_name( $request );
	}

	/** The connector (wordpress.org) flavor never exceeds T1. */
	public static function max_cap(): int {
		return defined( 'OVP_FLAVOR' ) && 'connector' === constant( 'OVP_FLAVOR' ) ? self::T1 : self::T3;
	}

	/* ---------- cap state ---------- */

	/** @return array{cap:int,god_until:int,raise:bool,ceiling:int} */
	public static function cap_state(): array {
		$v     = get_option( self::CAP_OPTION, array() );
		$v     = is_array( $v ) ? $v : array();
		$cap   = isset( $v['cap'] ) ? max( 0, min( self::max_cap(), (int) $v['cap'] ) ) : self::DEFAULT_CAP;
		$raise = ! empty( $v['raise'] );
		// A state saved before the ceiling existed (2.0.6): the stored cap is the ceiling, with the raise
		// flag on or off. Raising back to T3 then needs a re-pair or a wp-admin set (never an implicit
		// "up to the build maximum").
		$ceiling = isset( $v['ceiling'] ) ? max( 0, min( self::max_cap(), (int) $v['ceiling'] ) ) : $cap;
		return array(
			'cap'       => $cap,
			'god_until' => (int) ( $v['god_until'] ?? 0 ),
			'raise'     => $raise,
			'ceiling'   => max( $cap, $ceiling ),
		);
	}

	/**
	 * True when no cap was ever stored (a site paired by a plugin before 2.0.7, before the consent model): cap_state() then
	 * falls back to DEFAULT_CAP (T1) whatever the master's policy cap says. Read-only; nothing here writes the option, and
	 * nothing may raise the cap implicitly because of it.
	 */
	public static function cap_unset(): bool {
		$v = get_option( self::CAP_OPTION, null );
		return ! is_array( $v ) || ! isset( $v['cap'] );
	}

	/** Why the effective tier is T1 on a site with no stored cap. Used by the refusal message and the settings page. */
	public const CAP_UNSET_TEXT = 'This site was paired before the consent model, so no tier cap is stored here and the effective tier falls back to T1: set the cap in wp-admin (Settings > Overlord WP Fleet) or re-pair to grant T3.';

	/**
	 * Record the tier the human granted at pairing (consent screen) as cap and ceiling. With a
	 * grant, `raise` is on so a signed master request can come back up to the grant after a lower;
	 * without one (migration autopair, an older master) the safe default applies and nothing can
	 * be raised remotely. Called from Ovp_Pair only, after the master transcript verified.
	 */
	public static function grant_at_pairing( ?int $tier ): void {
		$t = null === $tier ? self::DEFAULT_CAP : max( 0, min( self::max_cap(), $tier ) );
		self::save_cap( array( 'cap' => $t, 'god_until' => 0, 'raise' => null !== $tier, 'ceiling' => $t ) );
	}

	/** Human-readable meaning of a tier for the consent screen and the settings page. */
	public static function describe( int $tier ): string {
		return match ( max( 0, min( 3, $tier ) ) ) {
			0 => 'T0: read only',
			1 => 'T1: safe content and media edits, each confirmed in two steps',
			2 => 'T2: structural changes (plugins, themes, users, options, database writes)',
			default => 'T3: full control of this site (raw SQL, any file including this plugin, login links)',
		};
	}

	/** @param array{cap:int,god_until:int,raise:bool,ceiling:int} $s */
	private static function save_cap( array $s ): void {
		update_option( self::CAP_OPTION, $s, false );
	}

	/** Cap in force right now (a live god window lifts it to T3, within max_cap). */
	public static function cap_now( ?int $now = null ): int {
		$s   = self::cap_state();
		$cap = $s['cap'];
		if ( $s['god_until'] > ( $now ?? time() ) ) {
			$cap = max( $cap, self::max_cap() );
		}
		return min( $cap, self::max_cap() );
	}

	/** wp-admin only, never from a signed remote request. Caller passes the nonce action it verifies. */
	private static function admin_ok( string $nonce, string $action ): bool {
		if ( Ovp_Proto::remote_seen() || ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( self::$skip_admin_nonce ) {
			return true;
		}
		return function_exists( 'wp_verify_nonce' ) && false !== wp_verify_nonce( $nonce, $action );
	}

	public static function admin_set_cap( int $cap, string $nonce ): bool {
		if ( $cap < 0 || $cap > self::max_cap() || ! self::admin_ok( $nonce, 'ovp_set_cap' ) ) {
			return false;
		}
		$s = self::cap_state();
		// The human's last word wins, whatever the master did in between: every wp-admin set makes the cap
		// the ceiling, so the master cannot raise above what a person last chose (it can still lower and
		// come back up to it).
		$s['ceiling'] = $cap;
		$s['cap']     = $cap;
		self::save_cap( $s );
		return true;
	}

	public static function admin_god_window( int $minutes, string $nonce, ?int $now = null ): bool {
		if ( $minutes < 1 || $minutes > 1440 || self::T3 > self::max_cap() || ! self::admin_ok( $nonce, 'ovp_set_cap' ) ) {
			return false;
		}
		$s              = self::cap_state();
		$s['god_until'] = ( $now ?? time() ) + $minutes * 60;
		self::save_cap( $s );
		return true;
	}

	public static function admin_set_remote_raise( bool $on, string $nonce ): bool {
		if ( ! self::admin_ok( $nonce, 'ovp_set_cap' ) ) {
			return false;
		}
		$s          = self::cap_state();
		$s['raise'] = $on;
		self::save_cap( $s );
		return true;
	}

	/**
	 * Remote `set_cap`: lowering is always accepted. A raise needs a request signed at T3 by the master and one of:
	 *  - the `raise` flag (set at pairing when a tier was granted, or by wp-admin) and a target within the ceiling stored
	 *    at pairing; or
	 *  - `$human_override`: the master states that a T3 token, human_requested and its two-call confirm all passed
	 *    (T3 is god mode, no limit). It lifts both the `raise` flag and the ceiling, up to the build maximum, and the
	 *    ceiling follows the new cap. It is honoured only together with a T3 signature.
	 * There is no default for $signed_tier: the caller passes Ovp_Auth::signed_tier() so a forgotten argument cannot grant a raise.
	 */
	public static function remote_set_cap( int $new, int $signed_tier, bool $human_override = false ): bool {
		$s = self::cap_state();
		if ( $new < 0 || $new > self::max_cap() ) {
			return false;
		}
		if ( $new > $s['cap'] ) {
			if ( $signed_tier < self::T3 ) {
				return false;
			}
			$within = $s['raise'] && $new <= $s['ceiling'];
			if ( ! $within && ! $human_override ) {
				return false;
			}
			if ( $human_override ) {
				$s['ceiling'] = max( $s['ceiling'], $new );
				$s['raise']   = true;
			}
		}
		if ( $new <= $s['cap'] ) {
			$s['god_until'] = 0; // Lowering also ends any god window.
		}
		$s['cap'] = $new;
		self::save_cap( $s );
		return true;
	}

	/* ---------- authorisation ---------- */

	public static function effective( int $signed_tier, ?int $now = null ): int {
		return min( max( 0, min( 3, $signed_tier ) ), self::cap_now( $now ) );
	}

	/**
	 * @return array{ok:bool,status:int,code:string,effective:int,required:int,needs_confirm:bool}
	 */
	public static function authorize( string $tool, int $signed_tier, ?int $now = null ): array {
		$eff      = self::effective( $signed_tier, $now );
		$required = self::tool_tier( $tool );
		$ok       = $required <= $eff;
		return array(
			'ok'            => $ok,
			'status'        => $ok ? 200 : 403,
			'code'          => $ok ? 'ok' : 'tier_denied',
			'effective'     => $eff,
			'required'      => $required,
			'needs_confirm' => $ok && self::T1 === $required,
		);
	}

	/* ---------- T1 two-call confirm ---------- */

	/** Deterministic JSON: assoc keys sorted byte-wise, lists keep order. */
	public static function canonical_json( mixed $v ): string {
		$norm = static function ( mixed $x ) use ( &$norm ): mixed {
			if ( is_array( $x ) ) {
				$is_list = array_is_list( $x );
				$out     = array();
				foreach ( $x as $k => $val ) {
					$out[ $k ] = $norm( $val );
				}
				if ( ! $is_list ) {
					uksort( $out, static fn( $a, $b ): int => strcmp( (string) $a, (string) $b ) );
				}
				return $out;
			}
			if ( is_float( $x ) && ! is_finite( $x ) ) {
				throw new InvalidArgumentException( 'non-finite float' );
			}
			return $x;
		};
		$s = json_encode( $norm( $v ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
		if ( false === $s ) {
			throw new InvalidArgumentException( 'unencodable change' );
		}
		return $s;
	}

	/** Hash binding site + tool + exact args + optional current-state hash. */
	public static function change_hash( string $site_id, string $tool, array $args, string $state_hash = '' ): string {
		return hash( 'sha256', self::canonical_json( array( 'site' => $site_id, 'tool' => $tool, 'args' => $args, 'state' => $state_hash ) ) );
	}

	private static function mac( string $payload_b64 ): string {
		return hash_hmac( 'sha256', "ovp-confirm-v1\n" . $payload_b64, Ovp_Keys::confirm_key(), true );
	}

	/**
	 * First/second call gate for T1 tools.
	 *
	 * No token: returns a plan + token (nothing executed). Token: verifies MAC, expiry,
	 * hash and single use; on success returns action=execute.
	 *
	 * @param array<string,mixed> $args
	 * @param array<string,mixed> $plan caller-built plan/diff preview, echoed back to the agent
	 * @return array<string,mixed> action: plan|execute|error, status, code, ...
	 */
	public static function t1_gate( string $site_id, string $tool, array $args, ?string $token, Ovp_Nonce_Store $store, string $state_hash = '', array $plan = array(), ?int $now = null ): array {
		$now  = $now ?? time();
		$hash = self::change_hash( $site_id, $tool, $args, $state_hash );
		if ( null === $token || '' === $token ) {
			$payload = Ovp_Proto::b64url_e( (string) json_encode( array( 'h' => $hash, 's' => $site_id, 't' => $tool, 'e' => $now + self::CONFIRM_TTL, 'j' => Ovp_Proto::b64url_e( random_bytes( 12 ) ) ), JSON_UNESCAPED_SLASHES ) );
			return array(
				'action'        => 'plan',
				'status'        => 200,
				'code'          => 'confirm_required',
				'change_hash'   => $hash,
				'plan'          => $plan,
				'confirm_token' => 'v1.' . $payload . '.' . Ovp_Proto::b64url_e( self::mac( $payload ) ),
				'expires_at'    => $now + self::CONFIRM_TTL,
			);
		}
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) || 'v1' !== $parts[0] || strlen( $token ) > 1024 ) {
			return array( 'action' => 'error', 'status' => 403, 'code' => 'confirm_invalid' );
		}
		$mac = Ovp_Proto::b64url_d( $parts[2] );
		if ( null === $mac || ! hash_equals( self::mac( $parts[1] ), $mac ) ) {
			return array( 'action' => 'error', 'status' => 403, 'code' => 'confirm_invalid' );
		}
		$p = json_decode( (string) Ovp_Proto::b64url_d( $parts[1] ), true, 4 );
		if ( ! is_array( $p ) || ! isset( $p['h'], $p['s'], $p['t'], $p['e'], $p['j'] ) || ! is_string( $p['h'] ) || ! is_int( $p['e'] ) || ! is_string( $p['j'] ) ) {
			return array( 'action' => 'error', 'status' => 403, 'code' => 'confirm_invalid' );
		}
		if ( $p['e'] < $now ) {
			return array( 'action' => 'error', 'status' => 410, 'code' => 'confirm_expired' );
		}
		if ( ! hash_equals( (string) $p['s'], $site_id ) || ! hash_equals( (string) $p['t'], $tool ) || ! hash_equals( $p['h'], $hash ) ) {
			return array( 'action' => 'error', 'status' => 409, 'code' => 'change_hash_mismatch' );
		}
		if ( ! $store->claim( 'confirm:' . $p['j'], self::CONFIRM_TTL + 60, $now ) ) {
			return array( 'action' => 'error', 'status' => 409, 'code' => 'confirm_already_used' );
		}
		return array( 'action' => 'execute', 'status' => 200, 'code' => 'ok', 'change_hash' => $hash );
	}
}
