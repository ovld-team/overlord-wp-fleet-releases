<?php
/**
 * Visibility-only request log (not a gate). Never records payloads, headers, or the
 * token itself — timestamp/method/route/status/IP, plus a short error message on 4xx/5xx.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ovp_Log {

	/**
	 * Append one entry to the ring buffer (option, autoload off, capped size).
	 */
	public static function record( string $method, string $route, int $status, string $message = '' ): void {
		$entry = array(
			'time'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'method' => $method,
			'route'  => $route,
			'status' => $status,
			'ip'     => self::client_ip(),
		);
		if ( $status >= 400 && '' !== $message ) {
			$entry['message'] = substr( Ovp_Errors::scrub( $message ), 0, 240 );
		}
		$log     = get_option( OVP_FLEET_LOG_OPTION, array() );
		$log[]   = $entry;
		$log     = array_slice( $log, -1 * OVP_FLEET_LOG_MAX );
		update_option( OVP_FLEET_LOG_OPTION, $log, false );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent(): array {
		$log = get_option( OVP_FLEET_LOG_OPTION, array() );
		return array_reverse( is_array( $log ) ? $log : array() );
	}

	public static function clear(): void {
		delete_option( OVP_FLEET_LOG_OPTION );
	}

	private static function client_ip(): string {
		return Ovp_Net::remote_addr();
	}
}
