<?php
/**
 * WordPress fatal-error-handler drop-in. Replaces WP_Fatal_Error_Handler.
 * Marker: MCP_BRIDGE_FATAL_DROPIN
 *
 * Loaded from wp-settings.php before plugins. No plugin classes. Logs the
 * last fatal to uploads/mcp-bridge/fatal-latest.json. If show-fatals.flag
 * exists, prints the PHP error instead of the generic WP critical page.
 *
 * @package WP_MCP_Bridge
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	return null;
}

if ( ! defined( 'MCP_BRIDGE_FATAL_DROPIN' ) ) {
	define( 'MCP_BRIDGE_FATAL_DROPIN', '1.6.1' );
}

final class MCP_Bridge_Fatal_Error_Handler {

	public function handle(): void {
		if ( function_exists( 'error_get_last' ) ) {
			$error = error_get_last();
		} else {
			return;
		}
		if ( ! is_array( $error ) ) {
			return;
		}
		$types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );
		if ( ! in_array( (int) $error['type'], $types, true ) ) {
			return;
		}

		$this->write_json( $error );
		$show = is_file( WP_CONTENT_DIR . '/uploads/mcp-bridge/show-fatals.flag' );

		if ( $show ) {
			if ( ! headers_sent() ) {
				header( 'HTTP/1.1 500 Internal Server Error' );
				header( 'Content-Type: text/html; charset=utf-8' );
			}
			$msg  = (string) $error['message'];
			$file = (string) $error['file'];
			$line = (int) $error['line'];
			echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>PHP fatal</title></head><body>';
			echo '<h1>PHP fatal</h1><pre>';
			echo htmlspecialchars( $msg . "\n" . $file . ':' . $line, ENT_QUOTES, 'UTF-8' );
			echo '</pre></body></html>';
			return;
		}

		if ( function_exists( 'wp_die' ) ) {
			wp_die(
				'There has been a critical error on this website.',
				'',
				array( 'response' => 500 )
			);
		}
	}

	/**
	 * @param array<string, mixed> $error
	 */
	private function write_json( array $error ): void {
		$dir = WP_CONTENT_DIR . '/uploads/mcp-bridge';
		if ( ! is_dir( $dir ) ) {
			@mkdir( $dir, 0755, true );
		}
		$row = array(
			'time'    => gmdate( 'c' ),
			'type'    => (int) $error['type'],
			'message' => (string) $error['message'],
			'file'    => (string) $error['file'],
			'line'    => (int) $error['line'],
		);
		@file_put_contents( $dir . '/fatal-latest.json', wp_json_encode_safe( $row ), LOCK_EX );
	}
}

/**
 * @param mixed $data
 */
function wp_json_encode_safe( $data ): string {
	if ( function_exists( 'wp_json_encode' ) ) {
		$out = wp_json_encode( $data );
		return is_string( $out ) ? $out : '{}';
	}
	$out = json_encode( $data );
	return is_string( $out ) ? $out : '{}';
}

return new MCP_Bridge_Fatal_Error_Handler();
