<?php
/**
 * PHP fatal display + Hostinger-style log tail (no cPanel error_log UI).
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ovp_Module_Debug extends Ovp_Module_Base {

	public function register_routes(): void {
		$this->route( 'GET', 'debug/php-logs', array( $this, 'php_logs' ) );
		$this->route( 'POST', 'debug/fatals', array( $this, 'set_fatals' ) );
	}

	public function php_logs( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$tail_path = (string) $request->get_param( 'path' );
		$bytes     = (int) ( $request->get_param( 'bytes' ) ?: 32000 );

		$payload = array(
			'php'            => PHP_VERSION,
			'error_log_ini'  => (string) ini_get( 'error_log' ),
			'log_errors'     => (string) ini_get( 'log_errors' ),
			'display_errors' => (string) ini_get( 'display_errors' ),
			'showing_fatals' => Ovp_Fatal::is_showing(),
			'last_fatal'     => Ovp_Fatal::last_fatal(),
			'candidates'     => Ovp_Fatal::candidate_logs(),
			'tail'           => null,
		);

		if ( '' !== $tail_path ) {
			$tail = Ovp_Fatal::tail_file( $tail_path, $bytes );
			if ( is_wp_error( $tail ) ) {
				return $tail;
			}
			$payload['tail'] = $tail;
		} else {
			foreach ( Ovp_Fatal::candidate_logs() as $row ) {
				if ( $row['exists'] && $row['bytes'] > 0 && ! str_ends_with( $row['path'], 'fatal-latest.json' ) ) {
					$tail = Ovp_Fatal::tail_file( $row['path'], $bytes );
					if ( ! is_wp_error( $tail ) ) {
						$payload['tail'] = $tail;
						break;
					}
				}
			}
		}

		return $this->success( $payload );
	}

	public function set_fatals( WP_REST_Request $request ): WP_REST_Response {
		$body    = (array) $request->get_json_params();
		$install = array_key_exists( 'install', $body ) ? (bool) $body['install'] : true;
		$show    = array_key_exists( 'show', $body ) ? (bool) $body['show'] : null;

		$result = array();
		if ( $install ) {
			$result['install'] = Ovp_Fatal::install();
		}
		if ( null !== $show ) {
			Ovp_Fatal::set_showing( $show );
			$result['showing'] = Ovp_Fatal::is_showing();
		} else {
			$result['showing'] = Ovp_Fatal::is_showing();
		}

		return $this->success( $result );
	}
}
