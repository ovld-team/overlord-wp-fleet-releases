<?php
/**
 * Connection settings and one-shot pairing orchestration.
 *
 * There is no shared enroll token and nothing phones home before an admin pairs the site
 * with a one-time code in wp-admin (see Ovp_Pair). This class only stores the chosen
 * connection mode/URLs, builds transport URLs and drives Ovp_Pair.
 *
 * Modes: local (agent on this machine, http loopback allowed), remote (master calls the site,
 * the site calls the master), direct (like remote, no relay), proxy (site talks to a relay).
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ovp_Enroll {

	public const OPTION   = 'ovp_conn';
	public const PAIR_PATH      = '/agent/v1/pair';
	public const HEARTBEAT_PATH = '/agent/v1/heartbeat';

	/** @return array{mode:string,master_url:string,relay_url:string,relay_token:string} */
	public static function config(): array {
		$v = get_option( self::OPTION, array() );
		$v = is_array( $v ) ? $v : array();
		$m = (string) ( $v['mode'] ?? 'remote' );
		return array(
			'mode'        => in_array( $m, Ovp_Keys::MODES, true ) ? $m : 'remote',
			'master_url'  => (string) ( $v['master_url'] ?? '' ),
			'relay_url'   => (string) ( $v['relay_url'] ?? '' ),
			'relay_token' => (string) ( $v['relay_token'] ?? '' ),
		);
	}

	/**
	 * Validate and store the connection. An empty relay_token keeps the stored one.
	 *
	 * @return true|WP_Error
	 */
	public static function save_config( string $mode, string $master_url, string $relay_url, string $relay_token ): true|WP_Error {
		if ( ! in_array( $mode, Ovp_Keys::MODES, true ) ) {
			return new WP_Error( 'ovp_bad_mode', 'Unknown connection mode.' );
		}
		if ( Ovp_Keys::is_bound() ) {
			return new WP_Error( 'ovp_already_bound', 'Unpair this site before changing the connection.' );
		}
		$master_url  = self::clean_url( $master_url );
		$relay_url   = self::clean_url( $relay_url );
		$relay_token = trim( $relay_token );
		if ( '' !== $master_url && ! Ovp_Pair::valid_pair_url( $master_url, $mode ) ) {
			return new WP_Error( 'ovp_bad_master_url', 'The master URL must be https (http only for local mode on loopback) with no credentials.' );
		}
		if ( '' !== $relay_url && ! Ovp_Pair::valid_pair_url( $relay_url, 'proxy' ) ) {
			return new WP_Error( 'ovp_bad_relay_url', 'The relay URL must be https with no credentials.' );
		}
		if ( 'proxy' === $mode && '' === $relay_url ) {
			return new WP_Error( 'ovp_relay_required', 'Proxy mode needs a relay URL.' );
		}
		if ( 'proxy' !== $mode && '' === $master_url ) {
			return new WP_Error( 'ovp_master_required', 'A master URL is required.' );
		}
		if ( strlen( $relay_token ) > 256 || 1 === preg_match( '/[^\x21-\x7e]/', $relay_token ) ) {
			return new WP_Error( 'ovp_bad_relay_token', 'The relay token has invalid characters.' );
		}
		$old = self::config();
		update_option(
			self::OPTION,
			array(
				'mode'        => $mode,
				'master_url'  => $master_url,
				'relay_url'   => $relay_url,
				'relay_token' => '' !== $relay_token ? $relay_token : $old['relay_token'],
			),
			false
		);
		return true;
	}

	private static function clean_url( string $u ): string {
		$u = trim( $u );
		if ( '' === $u || strlen( $u ) > 512 || str_contains( $u, '?' ) ) {
			return '';
		}
		return rtrim( $u, '/' );
	}

	/** Base URL the plugin talks to for the stored mode, or ''. */
	public static function base_url( ?array $cfg = null ): string {
		$cfg = $cfg ?? self::config();
		return 'proxy' === $cfg['mode'] ? $cfg['relay_url'] : $cfg['master_url'];
	}

	public static function pair_url( ?array $cfg = null ): string {
		$base = self::base_url( $cfg );
		return '' === $base ? '' : $base . self::PAIR_PATH;
	}

	public static function heartbeat_url( ?array $cfg = null ): string {
		$base = self::base_url( $cfg );
		return '' === $base ? '' : $base . self::HEARTBEAT_PATH;
	}

	/** @return array{site_url:string,plugin_version:string,php:string,wp:string} */
	public static function meta(): array {
		global $wp_version;
		return array(
			'site_url'       => home_url( '/' ),
			'plugin_version' => OVP_FLEET_VERSION,
			'php'            => PHP_VERSION,
			'wp'             => (string) $wp_version,
		);
	}

	/**
	 * What the consent screen must show for the current config and pairing string tier.
	 *
	 * @param int|null $announced Tier named by the pairing string (null: none).
	 */
	public static function consent( ?int $announced = null ): array {
		$cfg = self::config();
		return Ovp_Pair::consent_summary( self::pair_url( $cfg ), $cfg['mode'], self::meta(), $announced );
	}

	/**
	 * Transport for Ovp_Pair::pair. Non-proxy modes use Ovp_Pair::default_transport; proxy mode
	 * adds the relay's outer-hop token (never part of the signed transcript).
	 *
	 * @return callable(string,string):array{status:int,body:string}
	 */
	public static function transport(): callable {
		$cfg = self::config();
		if ( 'proxy' !== $cfg['mode'] || '' === $cfg['relay_token'] ) {
			return array( 'Ovp_Pair', 'default_transport' );
		}
		$token = $cfg['relay_token'];
		return static function ( string $url, string $json ) use ( $token ): array {
			$r = wp_remote_post(
				$url,
				array(
					'timeout'             => 15,
					'redirection'         => 0,
					'sslverify'           => true,
					'limit_response_size' => Ovp_Pair::MAX_RESPONSE,
					'headers'             => array(
						'Content-Type'       => 'application/json',
						'Accept'             => 'application/json',
						'X-OVP-Relay-Token'  => $token,
					),
					'body'                => $json,
				)
			);
			if ( is_wp_error( $r ) ) {
				return array( 'status' => 0, 'body' => '' );
			}
			return array( 'status' => (int) wp_remote_retrieve_response_code( $r ), 'body' => (string) wp_remote_retrieve_body( $r ) );
		};
	}

	/**
	 * Pair with the master. The consent hash must be the hash of the summary the admin saw, which
	 * includes the tier being granted. On success Ovp_Pair has stored that tier as the cap and the
	 * ceiling (a pairing string without a tier gets the safe default T1 and no remote raise), and an
	 * immediate heartbeat is attempted; a heartbeat failure never undoes the pairing.
	 *
	 * @return array{ok:bool,code:string,site_id?:string,heartbeat?:array<string,mixed>}
	 */
	public static function pair( string $pairing_string, string $consent_hash, bool $implied_admin = false ): array {
		$cfg = self::config();
		$url = self::pair_url( $cfg );
		if ( '' === $url ) {
			return array( 'ok' => false, 'code' => 'not_configured' );
		}
		// Safe default before any key exists: a stale god cap from an earlier pairing must not survive. Never while a
		// key is bound: a refused re-pair (already_bound) used to wipe the stored cap and silently drop the site to T1.
		if ( ! Ovp_Keys::is_bound() ) {
			delete_option( Ovp_Tiers::CAP_OPTION );
		}
		$res = Ovp_Pair::pair( $pairing_string, $url, $cfg['mode'], $consent_hash, self::meta(), self::transport(), null, $implied_admin );
		if ( ! $res['ok'] ) {
			return $res;
		}
		// The cap was stored by Ovp_Pair from the granted tier; do not touch it here.
		delete_option( 'ovp_legacy_detected' );
		delete_option( Ovp_Fleet::STATE_OPTION );
		Ovp_Fleet::schedule();
		$res['heartbeat'] = Ovp_Fleet::heartbeat();
		if ( empty( $res['heartbeat']['ok'] ) ) {
			Ovp_Fleet::schedule_retry( 30 );
		}
		return $res;
	}

	/** wp-admin Unpair. The caller verified the nonce; the master must also Revoke before re-pairing. */
	public static function unpair(): bool {
		if ( ! Ovp_Pair::unpair() ) {
			return false;
		}
		delete_option( Ovp_Tiers::CAP_OPTION );
		delete_option( Ovp_Fleet::STATE_OPTION );
		Ovp_Fleet::unschedule();
		return true;
	}
}
