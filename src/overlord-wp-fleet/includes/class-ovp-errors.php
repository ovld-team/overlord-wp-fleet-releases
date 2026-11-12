<?php
/**
 * Local error trail (scrubbed). No remote reporting.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ovp_Errors {

	private const THROTTLE   = 'ovp_error_sent';
	private const LOG_OPTION = 'ovp_error_log';

	public static function hooks(): void {
		register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );
	}

	public static function on_shutdown(): void {
		$last = error_get_last();
		if ( ! is_array( $last ) ) {
			return;
		}
		$type = (int) $last['type'];
		if ( ! in_array( $type, array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
			return;
		}
		self::report_payload(
			(string) $last['message'],
			(string) $last['file'],
			(int) $last['line']
		);
	}

	public static function report( \Throwable $error ): void {
		self::report_payload( $error->getMessage(), $error->getFile(), $error->getLine() );
	}

	public static function report_http( string $code, string $message, string $route = '' ): void {
		$suffix = '' !== $route ? ' @' . $route : '';
		self::report_payload( $code . ': ' . $message . $suffix, 'REST', 0 );
	}

	/**
	 * Local-only error trail. Nothing leaves the site: no crash reporting endpoint, no shared
	 * token. The last few scrubbed entries are kept in an option for the settings page.
	 */
	private static function report_payload( string $message, string $file, int $line ): void {
		$key  = md5( $message . '|' . $file . '|' . $line );
		$sent = get_transient( self::THROTTLE );
		if ( is_array( $sent ) && isset( $sent[ $key ] ) ) {
			return;
		}
		if ( ! is_array( $sent ) ) {
			$sent = array();
		}
		$sent[ $key ] = 1;
		set_transient( self::THROTTLE, $sent, 300 );

		$log   = get_option( self::LOG_OPTION, array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'time'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'message' => self::scrub( $message ),
			'file'    => self::scrub_path( $file ),
			'line'    => $line,
		);
		update_option( self::LOG_OPTION, array_slice( $log, -20 ), false );
	}

	/** @return list<array<string,mixed>> */
	public static function recent(): array {
		$log = get_option( self::LOG_OPTION, array() );
		return is_array( $log ) ? array_values( array_reverse( $log ) ) : array();
	}

	public static function scrub( string $text ): string {
		$text = preg_replace( '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[redacted-email]', $text ) ?? $text;
		$text = preg_replace( '/(Bearer\s+)[A-Za-z0-9._~+\/-]{8,}/i', '$1[redacted]', $text ) ?? $text;
		$text = preg_replace( '/[?&](token|hop|key|pass|password|secret|dsn)=[^&\s]*/i', '', $text ) ?? $text;
		$text = preg_replace( '/https?:\/\/[^\/\s]+:[^@\/\s]+@/i', 'https://[redacted]@', $text ) ?? $text;
		$text = preg_replace( '/\b(define\s*\(\s*[\'"]DB_(?:PASSWORD|USER|NAME|HOST)[\'"]\s*,\s*)([\'"]).+?\2/i', '$1$2[redacted]$2', $text ) ?? $text;
		$text = preg_replace( '/([\'"]password[\'"]\s*=>\s*)([\'"]).+?\2/i', '$1$2[redacted]$2', $text ) ?? $text;
		return substr( $text, 0, 500 );
	}

	private static function scrub_path( string $path ): string {
		$path = str_replace( '\\', '/', $path );
		$base = str_replace( '\\', '/', (string) ( defined( 'ABSPATH' ) ? ABSPATH : '' ) );
		if ( '' !== $base && str_starts_with( $path, $base ) ) {
			$path = 'ABSPATH/' . ltrim( substr( $path, strlen( $base ) ), '/' );
		}
		return substr( $path, 0, 240 );
	}
}
