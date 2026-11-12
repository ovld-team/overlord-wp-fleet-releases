<?php
/**
 * /ovp/v1/users/* — list/create/update/delete/set-role. The hidden MCP Bridge
 * service account (see class-ovp-auth.php) is always excluded from list results.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ovp_Module_Users extends Ovp_Module_Base {

	/** Reads are T0 (emails masked below T2); every write is T2, except any write that grants or touches an administrator-level account (T3). */

	/** Capabilities that make an account/role administrator-level (code execution or account control). */
	private const PRIV_CAPS = array(
		'manage_options', 'promote_users', 'edit_users', 'create_users', 'delete_users', 'remove_users', 'list_users',
		'install_plugins', 'activate_plugins', 'edit_plugins', 'update_plugins', 'delete_plugins',
		'install_themes', 'switch_themes', 'edit_themes', 'update_themes', 'delete_themes',
		'edit_files', 'update_core', 'import', 'export', 'manage_network',
	);

	/** Fail closed: a role is privileged when it is unknown or carries any PRIV_CAPS capability. */
	private function role_is_privileged( string $role ): bool {
		if ( '' === $role ) {
			return false;
		}
		if ( 'administrator' === $role ) {
			return true;
		}
		$r = function_exists( 'get_role' ) ? get_role( $role ) : null;
		if ( ! is_object( $r ) ) {
			return true;
		}
		foreach ( self::PRIV_CAPS as $cap ) {
			if ( $r->has_cap( $cap ) ) {
				return true;
			}
		}
		return false;
	}

	private function user_is_privileged( WP_User $user ): bool {
		foreach ( (array) $user->roles as $role ) {
			if ( $this->role_is_privileged( (string) $role ) ) {
				return true;
			}
		}
		foreach ( self::PRIV_CAPS as $cap ) {
			if ( $user->has_cap( $cap ) ) {
				return true;
			}
		}
		return function_exists( 'is_super_admin' ) && is_super_admin( (int) $user->ID );
	}

	private function need_t3( WP_REST_Request $request ): ?WP_Error {
		if ( OVP_Upload::tier_rank( OVP_Meta::request_tier( $request ) ) < OVP_Upload::tier_rank( 'T3' ) ) {
			return $this->error( 'ovp_tier_denied', 'Tier T3 required to grant or touch an administrator-level account.', 403 );
		}
		return null;
	}

	public function register_routes(): void {
		$this->route( 'GET', 'users', array( $this, 'list_users' ) );
		$this->route( 'GET', 'users/(?P<id>\d+)', array( $this, 'get_user' ) );
		$this->route( 'POST', 'users', array( $this, 'create_user' ) );
		$this->route( 'PUT,PATCH', 'users/(?P<id>\d+)', array( $this, 'update_user' ) );
		$this->route( 'DELETE', 'users/(?P<id>\d+)', array( $this, 'delete_user' ) );
		$this->route( 'POST', 'users/(?P<id>\d+)/role', array( $this, 'set_role' ) );
	}

	public function list_users( WP_REST_Request $request ): WP_REST_Response {
		$per     = min( 200, (int) ( $request->get_param( 'per_page' ) ?: 50 ) );
		$offset  = max( 0, (int) ( $request->get_param( 'cursor' ) ?: 0 ) );
		$service = get_user_by( 'login', OVP_FLEET_SERVICE_USER_LOGIN );
		$args    = array(
			'number' => $per + 1,
			'offset' => $offset,
		);
		if ( $service instanceof WP_User ) {
			$args['exclude'] = array( $service->ID );
		}
		$users  = get_users( $args );
		$shaped = array();
		foreach ( $users as $user ) {
			$shaped[] = $this->shape_user( $user );
		}
		$next = '';
		if ( count( $shaped ) > $per ) {
			array_pop( $shaped );
			$next = (string) ( $offset + $per );
		}

		return $this->success( Ovp_Chunk::envelope( array( 'users' => $shaped ), $next ) );
	}

	public function get_user( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user = get_user_by( 'id', (int) $request->get_param( 'id' ) );
		if ( ! $user ) {
			return $this->error( 'mcp_bridge_not_found', 'User not found.', 404 );
		}
		if ( $this->is_service_account( $user ) ) {
			return $this->error( 'mcp_bridge_self_protected', 'Service account is not readable via REST.', 403 );
		}
		return $this->success( $this->shape_user( $user, true ) );
	}

	public function create_user( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = (array) $request->get_json_params();
		$login = sanitize_user( (string) ( $body['user_login'] ?? '' ) );
		if ( OVP_FLEET_SERVICE_USER_LOGIN === $login ) {
			return $this->error( 'mcp_bridge_self_protected', 'Cannot create the Overlord service login.', 403 );
		}
		$role = sanitize_key( (string) ( $body['role'] ?? 'subscriber' ) );
		if ( $this->role_is_privileged( $role ) ) {
			$gate = $this->need_t3( $request );
			if ( null !== $gate ) {
				return $gate;
			}
		}

		$user_id = wp_insert_user(
			array(
				'user_login' => $login,
				'user_email' => sanitize_email( (string) ( $body['user_email'] ?? '' ) ),
				'user_pass'  => (string) ( $body['user_pass'] ?? wp_generate_password( 20 ) ),
				'role'       => $role,
				'display_name' => sanitize_text_field( (string) ( $body['display_name'] ?? '' ) ),
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $this->error( 'mcp_bridge_create_user_failed', $user_id->get_error_message(), 400 );
		}

		return $this->success( $this->shape_user( get_user_by( 'id', $user_id ), true ) );
	}

	public function update_user( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id   = (int) $request->get_param( 'id' );
		$user = get_user_by( 'id', $id );
		if ( $user instanceof WP_User && $this->is_service_account( $user ) ) {
			return $this->error( 'mcp_bridge_self_protected', 'Cannot change the Overlord service account, at any tier. It is the one guard that tier T3 does not lift (deleting or altering it puts everything it authored at risk).', 403 );
		}
		if ( $user instanceof WP_User && $this->user_is_privileged( $user ) ) {
			$gate = $this->need_t3( $request );
			if ( null !== $gate ) {
				return $gate;
			}
		}
		$body = (array) $request->get_json_params();

		$userdata = array( 'ID' => $id );
		foreach ( array( 'user_email', 'display_name', 'first_name', 'last_name', 'user_pass' ) as $key ) {
			if ( array_key_exists( $key, $body ) ) {
				$userdata[ $key ] = (string) $body[ $key ];
			}
		}

		$result = wp_update_user( $userdata );
		if ( is_wp_error( $result ) ) {
			return $this->error( 'mcp_bridge_update_user_failed', $result->get_error_message(), 400 );
		}

		return $this->success( $this->shape_user( get_user_by( 'id', $id ), true ) );
	}

	public function delete_user( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$id       = (int) $request->get_param( 'id' );
		$user     = get_user_by( 'id', $id );
		if ( $user instanceof WP_User && $this->is_service_account( $user ) ) {
			return $this->error( 'mcp_bridge_self_protected', 'Cannot delete the Overlord service account, at any tier. WordPress deletes everything a user authored when that user is deleted without reassign; this is the one guard that tier T3 does not lift.', 403 );
		}
		if ( $user instanceof WP_User && $this->user_is_privileged( $user ) ) {
			$gate = $this->need_t3( $request );
			if ( null !== $gate ) {
				return $gate;
			}
		}
		$reassign = $request->get_param( 'reassign' ) ? (int) $request->get_param( 'reassign' ) : null;

		if ( ! wp_delete_user( $id, $reassign ) ) {
			return $this->error( 'mcp_bridge_delete_user_failed', 'Could not delete user.', 400 );
		}

		return $this->success( array( 'deleted' => $id ) );
	}

	public function set_role( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id   = (int) $request->get_param( 'id' );
		$body = (array) $request->get_json_params();
		$role = sanitize_key( (string) ( $body['role'] ?? '' ) );

		$user = get_user_by( 'id', $id );
		if ( ! $user ) {
			return $this->error( 'mcp_bridge_not_found', 'User not found.', 404 );
		}
		if ( $this->is_service_account( $user ) ) {
			return $this->error( 'mcp_bridge_self_protected', 'Cannot change the Overlord service account, at any tier. It is the one guard that tier T3 does not lift (deleting or altering it puts everything it authored at risk).', 403 );
		}
		if ( $this->role_is_privileged( $role ) || $this->user_is_privileged( $user ) ) {
			$gate = $this->need_t3( $request );
			if ( null !== $gate ) {
				return $gate;
			}
		}

		$user->set_role( $role );

		return $this->success( array( 'id' => $id, 'role' => $role ) );
	}

	/**
	 * The single guard that survives tier T3. Deleting the service account (login mcp_bridge_service) without
	 * a reassign makes WordPress delete every post, page, attachment and menu item it authored; that is how
	 * content was lost on 2026-11-10. Mutating it (password, role, email) is refused for the same reason: the
	 * plugin and the legacy bridge depend on it. Reading it and creating a second account with its login are
	 * refused too, which costs nothing and keeps the account out of every tool surface.
	 */
	private function is_service_account( WP_User $user ): bool {
		return OVP_FLEET_SERVICE_USER_LOGIN === $user->user_login
			|| (int) $user->get( OVP_FLEET_SERVICE_USER_META ) === 1;
	}

	/** Email addresses are personal data: reads below T2 get a masked address (a***@d***.tld). */
	public static function mask_email( string $email ): string {
		$at = strrpos( $email, '@' );
		if ( false === $at || 0 === $at ) {
			return '***';
		}
		$dom = substr( $email, $at + 1 );
		$dot = strrpos( $dom, '.' );
		$tld = false === $dot ? '' : substr( $dom, $dot );
		return substr( $email, 0, 1 ) . '***@' . substr( $dom, 0, 1 ) . '***' . $tld;
	}

	private function shape_user( WP_User $user, bool $include_meta = false ): array {
		$full   = OVP_Upload::tier_rank( OVP_Meta::request_tier( null ) ) >= OVP_Upload::tier_rank( 'T2' );
		$shaped = array(
			'id'           => $user->ID,
			'user_login'   => $user->user_login,
			'user_email'   => $full ? $user->user_email : self::mask_email( (string) $user->user_email ),
			'display_name' => $user->display_name,
			'roles'        => $user->roles,
		);
		if ( $include_meta ) {
			$shaped['registered'] = $user->user_registered;
		}
		return $shaped;
	}
}
