<?php
/**
 * Overlord MCP fatal probe: early oob dispatch. Loads first among mu-plugins so later parse errors never run.
 * Marker: MCP_BRIDGE_FATAL_DROPIN
 *
 * Deliberately NO "Plugin Name:" header. This file ships inside the plugin folder (dropins/), and WordPress'
 * get_plugins() scans one folder level deep: a header here makes it a second "plugin" of the package, and
 * Plugin_Upgrader::plugin_info() (what the legacy bridge's plugins/install reports as plugin_file) takes the
 * first of get_plugins() sorted by Name, so this file would be reported (and activated) instead of the main file.
 *
 * @package WP_MCP_Bridge
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

if ( ! defined( 'MCP_BRIDGE_FATAL_DROPIN' ) ) {
	define( 'MCP_BRIDGE_FATAL_DROPIN', '1.6.1' );
}

$mcp_bridge_oob = '';
if ( ! empty( $_SERVER['HTTP_X_MCP_BRIDGE_OOB'] ) && is_string( $_SERVER['HTTP_X_MCP_BRIDGE_OOB'] ) ) {
	$mcp_bridge_oob = (string) $_SERVER['HTTP_X_MCP_BRIDGE_OOB'];
}

if ( '' !== $mcp_bridge_oob && isset( $_SERVER['HTTP_X_MCP_BRIDGE_OOB'] ) ) {
	mcp_bridge_fatal_oob_respond( $mcp_bridge_oob );
}

register_shutdown_function( 'mcp_bridge_fatal_shutdown_backup' );

function mcp_bridge_fatal_store_dir(): string {
	$dir = WP_CONTENT_DIR . '/uploads/mcp-bridge';
	if ( ! is_dir( $dir ) ) {
		@mkdir( $dir, 0755, true );
	}
	return $dir;
}

/**
 * Out-of-band access is DISABLED in Overlord WP Fleet 2.0. The 1.x bearer-hash gate is gone,
 * so any request carrying the old header is refused, whether or not a stale hash file exists.
 */
function mcp_bridge_fatal_oob_respond( string $token ): void {
	unset( $token );
	http_response_code( 410 );
	header( 'Content-Type: application/json; charset=utf-8' );
	echo '{"ok":false,"error":"oob_disabled"}';
	exit;
}

function mcp_bridge_fatal_shutdown_backup(): void {
	$err = error_get_last();
	if ( ! is_array( $err ) ) {
		return;
	}
	$types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );
	if ( ! in_array( (int) $err['type'], $types, true ) ) {
		return;
	}
	$dir = mcp_bridge_fatal_store_dir();
	$row = array(
		'time'    => gmdate( 'c' ),
		'type'    => (int) $err['type'],
		'message' => (string) $err['message'],
		'file'    => (string) $err['file'],
		'line'    => (int) $err['line'],
	);
	@file_put_contents( $dir . '/fatal-latest.json', mcp_bridge_fatal_json( $row ), LOCK_EX );
}

/**
 * @param mixed $data
 */
function mcp_bridge_fatal_json( $data ): string {
	if ( function_exists( 'wp_json_encode' ) ) {
		$out = wp_json_encode( $data );
		return is_string( $out ) ? $out : '{}';
	}
	$out = json_encode( $data );
	return is_string( $out ) ? $out : '{}';
}
