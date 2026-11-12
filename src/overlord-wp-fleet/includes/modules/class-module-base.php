<?php
/**
 * Shared base for every capability module. One module = one REST sub-area under the
 * ovp/v1 namespace. Every route shares one permission_callback
 * (Ovp_Auth::authorize: signed envelope + replay + tier) and a visibility-only
 * request-log entry. T1 write routes additionally run the two-call confirm.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Ovp_Module_Base {

	/**
	 * Every route registered through route(): method, path pattern and declared tier. Read by
	 * the route-table test; carries no request data.
	 *
	 * @var array<int, array{method:string,path:string,declared:?int}>
	 */
	public static array $registry = array();

	/**
	 * Register this module's REST routes. Called on rest_api_init.
	 */
	abstract public function register_routes(): void;

	/**
	 * Thin wrapper around register_rest_route() with the shared namespace and signed auth.
	 *
	 * The tier a route needs comes from $args['ovp_tier'] ('T0'..'T3') when given, otherwise
	 * from Ovp_Auth::ROUTE_TIERS, otherwise T3 (fail closed). Modules may still do
	 * their own finer-grained checks on top.
	 *
	 * @param string   $method   GET|POST|PUT|DELETE (or WP_REST_Server::* constant).
	 * @param string   $path     Route path, relative to the ovp/v1 namespace.
	 * @param callable $callback function( WP_REST_Request $request ): WP_REST_Response|WP_Error
	 * @param array    $args     Extra register_rest_route args (args schema, etc.).
	 */
	protected function route( string $method, string $path, callable $callback, array $args = array() ): void {
		$declared = null;
		if ( isset( $args['ovp_tier'] ) ) {
			$declared = Ovp_Proto::parse_tier( (string) $args['ovp_tier'] );
			unset( $args['ovp_tier'] );
		}
		self::$registry[] = array( 'method' => $method, 'path' => $path, 'declared' => $declared );
		register_rest_route(
			OVP_FLEET_REST_NAMESPACE,
			$path,
			array_merge(
				array(
					'methods'             => $method,
					'permission_callback' => static function ( WP_REST_Request $request ) use ( $declared ) {
						return Ovp_Auth::authorize( $request, $declared );
					},
					'callback'            => function ( WP_REST_Request $request ) use ( $callback, $method, $path, $declared ) {
						try {
							// T1 writes: first call returns a plan + token and executes nothing.
							$gate = Ovp_Auth::confirm_gate( $request, $declared );
							$result = null !== $gate ? $gate : $callback( $request );
						} catch ( \Throwable $e ) {
							Ovp_Errors::report( $e );
							$result = new WP_Error(
								'ovp_exception',
								Ovp_Errors::scrub( $e->getMessage() ),
								array( 'status' => 500 )
							);
						}

						$edata  = $result instanceof WP_Error ? $result->get_error_data() : null;
						$status = $result instanceof WP_Error
							? ( is_array( $edata ) && isset( $edata['status'] ) ? (int) $edata['status'] : 500 )
							: ( $result instanceof WP_REST_Response ? $result->get_status() : 200 );
						$err_msg = $result instanceof WP_Error ? $result->get_error_message() : '';
						Ovp_Log::record( $method, '/' . $path, $status, $err_msg );

						return $result;
					},
				),
				$args
			)
		);
	}

	/**
	 * True when this request runs at effective tier T3: the master signed T3 AND the plugin cap, which
	 * follows the tier the human granted at pairing, is T3. The only place a module may lift a
	 * self-protection (the plugin's own folder, replacing or deleting the plugin, mu-plugins). Reads the
	 * same seam as OVP_Meta::request_tier and fails closed to false.
	 */
	protected function god(): bool {
		return class_exists( 'OVP_Tiers' ) && is_callable( array( 'OVP_Tiers', 'effective_tier' ) )
			&& 'T3' === strtoupper( (string) call_user_func( array( 'OVP_Tiers', 'effective_tier' ), null ) );
	}

	/** Scalar request value as a string; arrays/objects/null become '' (no "Array" paths or SQL). */
	protected function str( mixed $v ): string {
		return is_string( $v ) ? $v : ( is_int( $v ) || is_float( $v ) ? (string) $v : '' );
	}

	protected function success( mixed $data ): WP_REST_Response {
		return new WP_REST_Response( array( 'ok' => true, 'data' => $data ), 200 );
	}

	protected function error( string $code, string $message, int $status = 400 ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
