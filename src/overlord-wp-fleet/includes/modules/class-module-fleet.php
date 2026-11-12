<?php
/**
 * Fleet control routes: fleet/status, revoke, cap, rotate-keys, fleet/legacy-retire, fleet/self-update and
 * fleet/self-rollback (both T3). The 1.x proxy-triggered self-update that took a URL is gone. Since 2.0.7 a
 * signed request can only ask for the latest release this site itself verified against its pinned keys
 * (Ovp_Release); it runs the native bulk upgrader (Ovp_Self_Update). Auto-update opt-in stays wp-admin only.
 * Only these signed routes and the automatic update are signature-checked; at effective tier T3 the master can
 * also replace the plugin with any ZIP through plugins/install (no signature check, version floor 2.0.6, human-requested).
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ovp_Module_Fleet extends Ovp_Module_Base {

	public function register_routes(): void {
		$this->route( 'GET', 'fleet/status', array( $this, 'status' ) );
		$this->route( 'POST', 'revoke', array( $this, 'revoke' ) );
		$this->route( 'POST', 'cap', array( $this, 'set_cap' ) );
		$this->route( 'POST', 'rotate-keys', array( $this, 'rotate_keys' ) );
		$this->route( 'POST', 'fleet/legacy-retire', array( $this, 'legacy_retire' ) );
		$this->route( 'POST', 'fleet/self-update', array( $this, 'self_update' ) );
		$this->route( 'POST', 'fleet/self-rollback', array( $this, 'self_rollback' ) );
	}

	public function status(): WP_REST_Response {
		$state = Ovp_Fleet::state();
		return $this->success(
			array_merge(
				Ovp_Fleet::info(),
				array(
					'paired'      => Ovp_Keys::is_bound(),
					'self_update' => Ovp_Self_Update::status_block(),
					'heartbeat' => array(
						'at'      => (int) ( $state['at'] ?? 0 ),
						'ok'      => ! empty( $state['ok'] ),
						'code'    => (string) ( $state['code'] ?? '' ),
						'last_ok' => (int) ( $state['last_ok'] ?? 0 ),
					),
				)
			)
		);
	}

	/**
	 * Signed revoke (PROTOCOL section 10): wipes every key and goes inert. The response is
	 * signed first, because the signing key disappears with the wipe.
	 */
	public function revoke( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$verified = Ovp_Auth::verified();
		if ( null === $verified ) {
			return $this->error( 'ovp_unauthorized', 'Unauthorized.', 401 );
		}
		$ok_json = wp_json_encode( array( 'ok' => true, 'data' => array( 'revoked' => true ) ) );
		if ( ! is_string( $ok_json ) ) {
			return $this->error( 'ovp_encode', 'Encoding failed.', 500 );
		}
		Ovp_Auth::presign( 200, $ok_json );
		$r = Ovp_Pair::handle_revoke( $verified, 'POST', '/' . OVP_FLEET_REST_NAMESPACE . '/revoke', (string) $request->get_body() );
		if ( ! $r['ok'] ) {
			Ovp_Auth::clear_presigned();
			return $this->error( 'ovp_' . $r['code'], 'Revoke refused.', 'bad_revoke' === $r['code'] ? 400 : 403 );
		}
		delete_option( Ovp_Tiers::CAP_OPTION );
		Ovp_Fleet::unschedule();
		return new WP_REST_Response( array( 'ok' => true, 'data' => array( 'revoked' => true ) ), 200 );
	}

	/**
	 * Remote cap change: lowers freely. A raise is accepted only for a request signed at T3, with the
	 * raise flag on, up to the ceiling stored at pairing (see Ovp_Tiers::remote_set_cap).
	 */
	public function set_cap( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$b = (array) $request->get_json_params();
		if ( ! isset( $b['cap'] ) || ! is_int( $b['cap'] ) ) {
			return $this->error( 'ovp_bad_cap', 'cap must be an integer 0..3.', 400 );
		}
		if ( ! Ovp_Tiers::remote_set_cap( $b['cap'], Ovp_Auth::signed_tier() ) ) {
			return $this->error( 'ovp_cap_refused', 'The cap can be lowered freely; it can be raised only up to the tier granted at pairing, and only when raising is on and the request is signed at T3. Otherwise change it in wp-admin.', 403 );
		}
		return $this->success( array( 'tier_cap' => Ovp_Tiers::name( Ovp_Tiers::cap_state()['cap'] ) ) );
	}

	/**
	 * Master asks the plugin to rotate its own signing key (T2). The body must name this site;
	 * the plugin then proposes the new key to the master itself and switches only after the
	 * master's signed ack verifies. The reply is signed by the NEW key when it succeeded.
	 */
	public function rotate_keys( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$b = (array) $request->get_json_params();
		if ( 'rotate_plugin_key' !== ( $b['cmd'] ?? null ) || ! is_string( $b['site_id'] ?? null ) || ! hash_equals( Ovp_Keys::site_id(), $b['site_id'] ) ) {
			return $this->error( 'ovp_bad_rotate', 'Body must be {"cmd":"rotate_plugin_key","site_id":"<this site>"}.', 400 );
		}
		$r = Ovp_Fleet::rotate_plugin_key();
		if ( ! $r['ok'] ) {
			return $this->error( 'ovp_rotate_' . $r['code'], 'Key rotation failed (' . $r['code'] . '). The old key is still in use.', 502 );
		}
		return $this->success( array( 'rotated' => true, 'kid' => $r['kid'] ?? '' ) );
	}

	/**
	 * Master asks the plugin to retire the legacy 1.x plugin (T3, docs/PROTOCOL.md section 13). Pairing
	 * never does this by itself. Body: {"cmd":"legacy_retire","site_id":"<this site>","delete_files":bool}.
	 * Files are deleted only when delete_files is exactly true. Idempotent.
	 */
	public function legacy_retire( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$b = (array) $request->get_json_params();
		if ( 'legacy_retire' !== ( $b['cmd'] ?? null ) || ! is_string( $b['site_id'] ?? null ) || ! hash_equals( Ovp_Keys::site_id(), $b['site_id'] ) ) {
			return $this->error( 'ovp_bad_retire', 'Body must be {"cmd":"legacy_retire","site_id":"<this site>","delete_files":false}.', 400 );
		}
		return $this->success( Ovp_Migration::retire( true === ( $b['delete_files'] ?? false ) ) );
	}

	/**
	 * Body: {"cmd":"self_update","site_id":"<this site>","version":"X.Y.Z","sha256":"<64 hex>"} (T3). The release is
	 * re-checked against this site's own verified index; the body cannot carry a URL or bytes.
	 */
	public function self_update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$b = (array) $request->get_json_params();
		if (
			'self_update' !== ( $b['cmd'] ?? null )
			|| ! is_string( $b['site_id'] ?? null ) || ! hash_equals( Ovp_Keys::site_id(), $b['site_id'] )
			|| ! is_string( $b['version'] ?? null ) || 1 !== preg_match( '/^\d{1,3}\.\d{1,3}\.\d{1,3}$/', $b['version'] )
			|| ! is_string( $b['sha256'] ?? null ) || 1 !== preg_match( '/^[0-9a-f]{64}$/', $b['sha256'] )
		) {
			return $this->error( 'ovp_bad_self_update', 'Body must be {"cmd":"self_update","site_id":"<this site>","version":"X.Y.Z","sha256":"<64 hex>"}.', 400 );
		}
		$r = Ovp_Self_Update::remote_update( $b );
		return is_wp_error( $r ) ? $this->error( $r->get_error_code(), $r->get_error_message(), 409 ) : $this->success( $r );
	}

	/** Body: {"cmd":"self_rollback","site_id":"<this site>","from_version":"X.Y.Z"} (T3). Restores a local backup only. */
	public function self_rollback( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$b = (array) $request->get_json_params();
		if (
			'self_rollback' !== ( $b['cmd'] ?? null )
			|| ! is_string( $b['site_id'] ?? null ) || ! hash_equals( Ovp_Keys::site_id(), $b['site_id'] )
			|| ! is_string( $b['from_version'] ?? null ) || 1 !== preg_match( '/^\d{1,3}\.\d{1,3}\.\d{1,3}$/', $b['from_version'] )
		) {
			return $this->error( 'ovp_bad_self_rollback', 'Body must be {"cmd":"self_rollback","site_id":"<this site>","from_version":"X.Y.Z"}.', 400 );
		}
		$r = Ovp_Self_Update::remote_rollback( $b );
		return is_wp_error( $r ) ? $this->error( $r->get_error_code(), $r->get_error_message(), 409 ) : $this->success( $r );
	}
}
