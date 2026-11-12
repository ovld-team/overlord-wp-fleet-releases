<?php
/**
 * Inventory snapshot + signed heartbeat sender.
 *
 * The plugin only ever talks to the master (or relay) the admin configured and paired with.
 * Heartbeats are OVP1-signed by the plugin key (tier T0); the response must be signed by a
 * pinned master key and bound to the request nonce, otherwise it is discarded. Nothing here
 * runs before pairing, and a heartbeat failure never un-pairs the site.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ovp_Fleet {

	public const HEARTBEAT_HOOK = 'ovp_heartbeat';
	public const RETRY_HOOK     = 'ovp_heartbeat_retry';
	public const POST_UPDATE_HOOK = 'ovp_heartbeat_post_update';
	public const SCHEDULE       = 'ovp_six_hours';
	public const STATE_OPTION   = 'ovp_heartbeat';
	public const MAX_ITEMS      = 1000;
	private const MAX_RESPONSE  = 65536;

	/* ---------- scheduling ---------- */

	/** @param array<string,array<string,mixed>> $schedules */
	public static function add_schedule( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => 'Every 6 hours (Overlord WP Fleet)',
		);
		return $schedules;
	}

	public static function hooks(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( self::HEARTBEAT_HOOK, array( __CLASS__, 'cron_heartbeat' ) );
		add_action( self::RETRY_HOOK, array( __CLASS__, 'cron_heartbeat' ) );
		add_action( self::POST_UPDATE_HOOK, array( __CLASS__, 'cron_heartbeat' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_upgrade' ), 20, 2 );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 100 );
	}

	public static function maybe_schedule(): void {
		if ( Ovp_Keys::is_bound() ) {
			self::schedule();
		} elseif ( wp_next_scheduled( self::HEARTBEAT_HOOK ) ) {
			self::unschedule(); // Unpaired or revoked: stop calling out.
		}
	}

	public static function schedule(): void {
		if ( Ovp_Keys::is_bound() && ! wp_next_scheduled( self::HEARTBEAT_HOOK ) ) {
			wp_schedule_event( time() + 300, self::SCHEDULE, self::HEARTBEAT_HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HEARTBEAT_HOOK );
		wp_clear_scheduled_hook( self::RETRY_HOOK );
		wp_clear_scheduled_hook( self::POST_UPDATE_HOOK );
	}

	/**
	 * One heartbeat shortly after this plugin was updated or activated, so the site icon and new
	 * version show up without waiting for the 6-hourly event. Never inline: a single cron event
	 * about 10 s later, once (WordPress dedupes identical events in a 10-minute window).
	 */
	public static function schedule_post_update_heartbeat(): void {
		if ( Ovp_Keys::is_bound() && ! wp_next_scheduled( self::POST_UPDATE_HOOK ) ) {
			wp_schedule_single_event( time() + 10, self::POST_UPDATE_HOOK );
		}
	}

	/**
	 * upgrader_process_complete: only for an update of this plugin. (On the very update that installs
	 * this code the old version is still the one running; Ovp_Bridge::maybe_upgrade covers that case.)
	 *
	 * @param mixed $upgrader
	 * @param mixed $hook_extra
	 */
	public static function after_upgrade( $upgrader = null, $hook_extra = array() ): void {
		unset( $upgrader );
		if ( is_array( $hook_extra ) && class_exists( 'Ovp_Updater' ) && Ovp_Updater::is_ours( $hook_extra ) ) {
			self::schedule_post_update_heartbeat();
		}
	}

	public static function schedule_retry( int $seconds ): void {
		if ( ! wp_next_scheduled( self::RETRY_HOOK ) ) {
			wp_schedule_single_event( time() + max( 10, $seconds ), self::RETRY_HOOK );
		}
	}

	public static function cron_heartbeat(): void {
		$r = self::heartbeat();
		if ( empty( $r['ok'] ) && Ovp_Keys::is_bound() ) {
			self::schedule_retry( 900 );
		}
	}

	/* ---------- inventory ---------- */

	/**
	 * Plugins and themes. `items` is ALWAYS an array. Zero plugins with status "ok" is a valid
	 * state, distinct from never_synced and error.
	 *
	 * @return array{plugins:array<string,mixed>,themes:array<string,mixed>}
	 */
	public static function inventory(): array {
		return array(
			'plugins' => self::inventory_plugins(),
			'themes'  => self::inventory_themes(),
		);
	}

	private static function iso_now(): string {
		return gmdate( 'Y-m-d\TH:i:s\Z' );
	}

	/** @return array<string,mixed> */
	private static function inv_error( \Throwable $e ): array {
		return array(
			'status'   => 'error',
			'syncedAt' => null,
			'error'    => Ovp_Errors::scrub( $e->getMessage() ),
			'items'    => array(),
		);
	}

	/** @return array<string,mixed> */
	public static function inventory_plugins(): array {
		try {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$all = get_plugins();
			if ( ! is_array( $all ) ) {
				throw new RuntimeException( 'get_plugins returned a non-array' );
			}
			$active  = (array) get_option( 'active_plugins', array() );
			$network = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', array() ) : array();
			$t       = get_site_transient( 'update_plugins' );
			$resp    = ( is_object( $t ) && isset( $t->response ) && is_array( $t->response ) ) ? $t->response : array();
			$items   = array();
			foreach ( $all as $file => $d ) {
				$file    = (string) $file;
				$dir     = dirname( $file );
				$items[] = array(
					'slug'            => '.' === $dir ? basename( $file, '.php' ) : $dir,
					'name'            => (string) ( is_array( $d ) ? ( $d['Name'] ?? $file ) : $file ),
					'version'         => (string) ( is_array( $d ) ? ( $d['Version'] ?? '' ) : '' ),
					'active'          => in_array( $file, $active, true ) || isset( $network[ $file ] ),
					'updateAvailable' => isset( $resp[ $file ] ),
				);
				if ( count( $items ) >= self::MAX_ITEMS ) {
					break;
				}
			}
			return array( 'status' => 'ok', 'syncedAt' => self::iso_now(), 'error' => null, 'items' => array_values( $items ) );
		} catch ( \Throwable $e ) {
			return self::inv_error( $e );
		}
	}

	/** @return array<string,mixed> */
	public static function inventory_themes(): array {
		try {
			$all = wp_get_themes();
			if ( ! is_array( $all ) ) {
				throw new RuntimeException( 'wp_get_themes returned a non-array' );
			}
			$cur   = (string) get_stylesheet();
			$t     = get_site_transient( 'update_themes' );
			$resp  = ( is_object( $t ) && isset( $t->response ) && is_array( $t->response ) ) ? $t->response : array();
			$items = array();
			foreach ( $all as $slug => $theme ) {
				$slug    = (string) $slug;
				$items[] = array(
					'slug'            => $slug,
					'name'            => (string) $theme->get( 'Name' ),
					'version'         => (string) $theme->get( 'Version' ),
					'active'          => $slug === $cur,
					'updateAvailable' => isset( $resp[ $slug ] ),
				);
				if ( count( $items ) >= self::MAX_ITEMS ) {
					break;
				}
			}
			return array( 'status' => 'ok', 'syncedAt' => self::iso_now(), 'error' => null, 'items' => array_values( $items ) );
		} catch ( \Throwable $e ) {
			return self::inv_error( $e );
		}
	}

	/** Site icon for the heartbeat; any failure is null and never reaches the heartbeat. @return array{mime:string,b64:string,sha256:string}|null */
	private static function icon(): ?array {
		try {
			return Ovp_Site_Icon::collect();
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/** Lean site facts shared by the info route and the heartbeat. @return array<string,mixed> */
	public static function info(): array {
		global $wp_version;
		$cap = Ovp_Tiers::cap_state();
		return array(
			'site_id'        => Ovp_Keys::site_id(),
			'protocol'       => Ovp_Proto::VERSION,
			'plugin_version' => OVP_FLEET_VERSION,
			'mode'           => Ovp_Keys::mode(),
			'wp_version'     => (string) $wp_version,
			'php_version'    => PHP_VERSION,
			'site_url'       => site_url(),
			'home_url'       => home_url(),
			'is_multisite'   => is_multisite(),
			'tier_cap'       => Ovp_Tiers::name( $cap['cap'] ),
			'tier_cap_now'   => Ovp_Tiers::name( Ovp_Tiers::cap_now() ),
			// true = bound but no cap stored (paired before the consent model): the effective tier is T1 until the admin sets it or re-pairs.
			'cap_unset'      => Ovp_Keys::is_bound() && Ovp_Tiers::cap_unset(),
			'god_until'      => $cap['god_until'] > time() ? $cap['god_until'] : 0,
			'flavor'         => 1 === Ovp_Tiers::max_cap() ? 'connector' : 'full',
			'capabilities'   => array( 'self-update' ),
		);
	}

	/* ---------- heartbeat ---------- */

	/** @return array<string,mixed> */
	public static function state(): array {
		$s = get_option( self::STATE_OPTION, array() );
		return is_array( $s ) ? $s : array();
	}

	/** @param array<string,mixed> $s */
	private static function save_state( array $s ): void {
		update_option( self::STATE_OPTION, $s, false );
	}

	/**
	 * Send one signed heartbeat now.
	 *
	 * @return array{ok:bool,code:string,http?:int}
	 */
	public static function heartbeat(): array {
		$state = self::state();
		$out   = self::send( $state );
		$state['at']   = time();
		$state['ok']   = $out['ok'];
		$state['code'] = $out['code'];
		$state['http'] = $out['http'] ?? 0;
		if ( $out['ok'] ) {
			$state['last_ok'] = time();
		}
		self::save_state( $state );
		return $out;
	}

	/**
	 * @param array<string,mixed> $state by reference semantics via return of rotation bookkeeping
	 * @return array{ok:bool,code:string,http?:int}
	 */
	private static function send( array &$state ): array {
		if ( ! Ovp_Keys::is_bound() ) {
			return array( 'ok' => false, 'code' => 'not_bound' );
		}
		$cfg = Ovp_Enroll::config();
		$url = Ovp_Enroll::heartbeat_url( $cfg );
		if ( '' === $url || ! Ovp_Pair::valid_pair_url( $url, Ovp_Keys::mode() ) ) {
			return array( 'ok' => false, 'code' => 'not_configured' );
		}
		$payload = array_merge(
			self::info(),
			array(
				'v'         => 1,
				'inventory' => self::inventory(),
				// {mime,b64,sha256} of the site icon (<= 20 KB, never SVG) or null. Heartbeat only, never the info route.
				'icon'      => self::icon(),
			)
		);
		if ( isset( $state['proposal'] ) && is_array( $state['proposal'] ) ) {
			$payload['plugin_rotation'] = $state['proposal'];
		}
		$body = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $body ) || strlen( $body ) > Ovp_Proto::MAX_BODY_JSON ) {
			return array( 'ok' => false, 'code' => 'payload_invalid' );
		}
		$t = self::post_signed( $url, $cfg, Ovp_Enroll::HEARTBEAT_PATH, $body );
		if ( ! $t['ok'] ) {
			return array_filter( array( 'ok' => false, 'code' => $t['code'], 'http' => $t['http'] ?? null ), static fn( $v ): bool => null !== $v );
		}
		$rb = $t['body'];
		self::apply_response( $rb, $state );
		return array( 'ok' => true, 'code' => 'ok', 'http' => $t['http'] ?? 200 );
	}

	/**
	 * One signed POST to the master (or relay) and signed-response verification. Shared by the
	 * heartbeat and the plugin key rotation. The caller's `Ovp_Keys::sign_request` key is the
	 * CURRENT plugin key.
	 *
	 * @param array<string,mixed> $cfg
	 * @return array{ok:bool,code:string,http?:int,body?:string,nonce?:string}
	 */
	private static function post_signed( string $url, array $cfg, string $logical_path, string $body ): array {
		$local = 'local' === Ovp_Keys::mode();
		try {
			$sig_headers = Ovp_Keys::sign_request( 'POST', $logical_path, '', $body, Ovp_Tiers::T0 );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'code' => 'sign_failed' );
		}
		$headers = array_merge( $sig_headers, array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ) );
		if ( 'proxy' === $cfg['mode'] && '' !== $cfg['relay_token'] ) {
			$headers['X-OVP-Relay-Token'] = $cfg['relay_token'];
		}
		$do = static function () use ( $url, $headers, $body ) {
			return wp_remote_post(
				$url,
				array(
					'timeout'             => 15,
					'redirection'         => 0,
					'sslverify'           => true,
					'limit_response_size' => self::MAX_RESPONSE,
					'headers'             => $headers,
					'body'                => $body,
				)
			);
		};
		if ( $local ) {
			$r = $do();
		} else {
			$safe = Ovp_Net::assert_public_https_url( $url );
			if ( is_wp_error( $safe ) ) {
				return array( 'ok' => false, 'code' => 'blocked_url' );
			}
			$r = Ovp_Net::with_safe_http( $do );
		}
		if ( is_wp_error( $r ) ) {
			return array( 'ok' => false, 'code' => 'network_error' );
		}
		$http = (int) wp_remote_retrieve_response_code( $r );
		$rb   = (string) wp_remote_retrieve_body( $r );
		if ( 200 !== $http || strlen( $rb ) > self::MAX_RESPONSE ) {
			return array( 'ok' => false, 'code' => 'master_http_' . $http, 'http' => $http );
		}
		// The response must be signed by a pinned master key and bound to our request nonce.
		$v = Ovp_Proto::verify_response(
			$http,
			$rb,
			self::flat_headers( $r ),
			(string) $sig_headers['X-OVP-Nonce'],
			Ovp_Keys::site_id(),
			static fn( string $kid, string $site ): ?string => Ovp_Keys::lookup_master( $kid, $site ),
			time(),
			'm'
		);
		if ( ! $v->ok ) {
			return array( 'ok' => false, 'code' => 'unverified_' . $v->reason, 'http' => $http );
		}
		return array( 'ok' => true, 'code' => 'ok', 'http' => $http, 'body' => $rb, 'nonce' => (string) $sig_headers['X-OVP-Nonce'] );
	}

	/**
	 * Plugin key rotation, run when the master asks over the signed rotate-keys route
	 * (PROTOCOL section 7). Propose a new key signed by the old one, POST it to
	 * /agent/v1/rotate-key under the OLD key, verify the signed reply and the master's
	 * OVP1-ROTP-ACK signature, and only then switch. Any failure keeps the old key.
	 *
	 * @return array{ok:bool,code:string,kid?:string}
	 */
	public static function rotate_plugin_key(): array {
		if ( ! Ovp_Keys::is_bound() ) {
			return array( 'ok' => false, 'code' => 'not_bound' );
		}
		$cfg  = Ovp_Enroll::config();
		$base = Ovp_Enroll::base_url( $cfg );
		$url  = '' === $base ? '' : $base . '/agent/v1/rotate-key';
		if ( '' === $url || ! Ovp_Pair::valid_pair_url( $url, Ovp_Keys::mode() ) ) {
			return array( 'ok' => false, 'code' => 'not_configured' );
		}
		try {
			$prop = Ovp_Pair::propose_plugin_rotation();
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'code' => 'propose_failed' );
		}
		$body = wp_json_encode( $prop, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $body ) ) {
			return array( 'ok' => false, 'code' => 'payload_invalid' );
		}
		$t = self::post_signed( $url, $cfg, '/agent/v1/rotate-key', $body );
		if ( ! $t['ok'] ) {
			return array( 'ok' => false, 'code' => $t['code'] );
		}
		$j = json_decode( (string) ( $t['body'] ?? '' ), true, 8 );
		if ( ! is_array( $j ) || true !== ( $j['ok'] ?? null ) || ! is_string( $j['kid'] ?? null ) || ! hash_equals( $prop['new_kid'], $j['kid'] ) || ! is_string( $j['ack'] ?? null ) || ! is_string( $j['master_kid'] ?? null ) || ! Ovp_Proto::valid_kid( $j['master_kid'] ) ) {
			return array( 'ok' => false, 'code' => 'bad_ack' );
		}
		$mpub = Ovp_Keys::lookup_master( $j['master_kid'], Ovp_Keys::site_id() );
		$ack  = Ovp_Proto::b64d( $j['ack'] );
		$canon = implode( "\n", array( 'OVP1-ROTP-ACK', Ovp_Keys::site_id(), $prop['old_kid'], $prop['new_kid'], $prop['new_pub'] ) );
		if ( null === $mpub || null === $ack || ! Ovp_Proto::verify_detached( $canon, $ack, $mpub ) ) {
			return array( 'ok' => false, 'code' => 'bad_ack_sig' );
		}
		if ( ! Ovp_Pair::commit_plugin_rotation( $prop['new_kid'] ) ) {
			return array( 'ok' => false, 'code' => 'commit_failed' );
		}
		return array( 'ok' => true, 'code' => 'rotated', 'kid' => $prop['new_kid'] );
	}

	/**
	 * Act on a verified heartbeat response: master key rotation, key retirement, plugin key
	 * rotation request/ack. Everything here is authenticated by the pinned active master key.
	 *
	 * @param array<string,mixed> $state
	 */
	private static function apply_response( string $rb, array &$state ): void {
		$j = json_decode( $rb, true, 8 );
		if ( ! is_array( $j ) ) {
			return;
		}
		$rot = $j['rotation'] ?? null;
		if ( is_array( $rot ) && is_string( $rot['old_kid'] ?? null ) && is_string( $rot['new_kid'] ?? null ) && is_string( $rot['new_pub'] ?? null ) && is_string( $rot['sig'] ?? null ) ) {
			Ovp_Pair::accept_master_rotation( $rot['old_kid'], $rot['new_kid'], $rot['new_pub'], $rot['sig'] );
		}
		if ( is_string( $j['retire_kid'] ?? null ) && Ovp_Proto::valid_kid( $j['retire_kid'] ) ) {
			Ovp_Keys::retire_master_key( $j['retire_kid'] );
		}
		if ( ! empty( $j['rotate_plugin_key'] ) && ! isset( $state['proposal'] ) ) {
			try {
				$state['proposal'] = Ovp_Pair::propose_plugin_rotation(); // Sent with the next heartbeat.
			} catch ( \Throwable $e ) {
				unset( $state['proposal'] );
			}
		}
		if ( is_string( $j['plugin_rotation_ack'] ?? null ) && isset( $state['proposal']['new_kid'] ) && hash_equals( (string) $state['proposal']['new_kid'], $j['plugin_rotation_ack'] ) ) {
			Ovp_Pair::commit_plugin_rotation( $j['plugin_rotation_ack'] );
			unset( $state['proposal'] );
		}
	}

	/**
	 * @param mixed $r wp_remote_* response
	 * @return array<string,string>
	 */
	private static function flat_headers( mixed $r ): array {
		$h   = wp_remote_retrieve_headers( $r );
		$arr = is_object( $h ) && method_exists( $h, 'getAll' ) ? $h->getAll() : (array) $h;
		$out = array();
		foreach ( $arr as $k => $v ) {
			if ( is_string( $k ) ) {
				$out[ $k ] = is_array( $v ) ? implode( ', ', array_map( 'strval', $v ) ) : (string) $v;
			}
		}
		return $out;
	}
}
