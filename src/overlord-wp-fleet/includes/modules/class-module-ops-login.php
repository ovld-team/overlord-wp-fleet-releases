<?php
/**
 * One-time wp-admin cookie for the ops panel.
 * Mint requires a signed T3 (god) request + confirm=true. Consume is a 90s single-use GET.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ovp_Module_Ops_Login extends Ovp_Module_Base {

	private const OPTION = 'mcp_bridge_magic_login';
	private const TTL    = 90;

	public function __construct() {
		add_action( 'init', array( $this, 'consume' ), 1 );
	}

	public function register_routes(): void {
		$this->route( 'POST', 'auth/magic-login', array( $this, 'mint' ) );
	}

	public function mint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = (array) $request->get_json_params();
		if ( true !== ( $body['confirm'] ?? false ) && 'true' !== ( $body['confirm'] ?? '' ) ) {
			return $this->error( 'mcp_bridge_confirm_required', 'confirm=true required for magic login.', 400 );
		}

		$want = (int) ( $body['user_id'] ?? 1 );
		$user = $this->pick_admin( $want );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$raw = bin2hex( random_bytes( 32 ) );
		update_option(
			self::OPTION,
			array(
				'hash'    => hash( 'sha256', $raw ),
				'user_id' => (int) $user->ID,
				'exp'     => time() + self::TTL,
			),
			false
		);

		return $this->success(
			array(
				'url'         => add_query_arg( 'mcp_bridge_login', $raw, home_url( '/' ) ),
				'user_id'     => (int) $user->ID,
				'user_login'  => (string) $user->user_login,
				'expires_in'  => self::TTL,
				'requested'   => $want,
			)
		);
	}

	public function consume(): void {
		$raw = isset( $_GET['mcp_bridge_login'] ) && is_string( $_GET['mcp_bridge_login'] )
			? (string) $_GET['mcp_bridge_login']
			: '';
		if ( '' === $raw ) {
			return;
		}
		$raw = strtolower( preg_replace( '/[^a-f0-9]/', '', $raw ) ?? '' );
		if ( 64 !== strlen( $raw ) ) {
			return;
		}

		$row = get_option( self::OPTION, null );
		if ( ! is_array( $row ) ) {
			return;
		}
		$exp  = (int) ( $row['exp'] ?? 0 );
		$hash = (string) ( $row['hash'] ?? '' );
		$uid  = (int) ( $row['user_id'] ?? 0 );
		if ( $exp < time() || 64 !== strlen( $hash ) || $uid < 1 ) {
			return;
		}
		if ( ! hash_equals( $hash, hash( 'sha256', $raw ) ) ) {
			return;
		}

		global $wpdb;
		$gone = $wpdb->delete( $wpdb->options, array( 'option_name' => self::OPTION ), array( '%s' ) );
		wp_cache_delete( self::OPTION, 'options' );
		if ( 1 !== (int) $gone ) {
			return;
		}

		$user = get_userdata( $uid );
		if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
			return;
		}

		wp_set_current_user( $uid );
		wp_set_auth_cookie( $uid, false, is_ssl() );
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		wp_safe_redirect( admin_url() );
		exit;
	}

	private function pick_admin( int $want ): WP_User|WP_Error {
		$candidate = get_userdata( $want );
		if ( $candidate && $this->is_real_admin( $candidate ) ) {
			return $candidate;
		}
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 20,
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);
		foreach ( $admins as $user ) {
			if ( $this->is_real_admin( $user ) ) {
				return $user;
			}
		}
		return $this->error( 'mcp_bridge_no_admin', 'No administrator available for magic login.', 404 );
	}

	private function is_real_admin( WP_User $user ): bool {
		if ( $user->user_login === OVP_FLEET_SERVICE_USER_LOGIN ) {
			return false;
		}
		return user_can( $user, 'manage_options' );
	}
}
