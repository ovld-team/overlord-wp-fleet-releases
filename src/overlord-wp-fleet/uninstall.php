<?php
/**
 * Uninstall: remove only what this plugin itself created. Nothing is sent anywhere (the master is
 * told by revoking the site in its panel). Deactivation does not run this.
 *
 * HARD RULE: uninstall never deletes, trashes or reassigns a post, page, attachment, menu, user,
 * user meta, post meta or term. It never calls wp_delete_user(), wp_delete_post() or
 * wp_delete_attachment(), and it never runs SQL against a table other than wp_options and this
 * plugin's own replay table.
 *
 * Why: up to 2.0.5 this file ended with wp_delete_user( mcp_bridge_service ) with no reassign.
 * WordPress then deletes (pages: trashes, attachments: removes with their files) every post the
 * service account authored, which is all content and media created through the bridge. On
 * 2026-11-10 a migration deleted an older copy of this plugin on a production site and 13 pages
 * plus about 135 attachments were lost. The service account and everything it authored now stay.
 * The account has a random 64-character password that was never shown to anyone, and it only acted
 * while a verified signed request ran, which needs this plugin. After uninstall it is visible in
 * Users (the filter that hid it is gone); an administrator can remove it there by hand, and
 * WordPress then asks where to attribute its content.
 *
 * What is removed, and why each item is safe:
 *  - wp_options rows named ovp_*, _ovp_* (this plugin: keys, tier cap, schema, autopair state).
 *  - The replay table {prefix}ovp_nonces (this plugin's own; only nonce hashes and expiry times).
 *  - The cron events ovp_heartbeat, ovp_heartbeat_retry, ovp_gc (this plugin's own hooks).
 *  - Only while the legacy 1.x plugin (wp-mcp-bridge) is NOT active: wp_options rows named
 *    mcp_bridge_* (credentials and settings of the 1.x scheme this plugin replaced), the fatal-error
 *    drop-ins that carry the marker MCP_BRIDGE_FATAL_DROPIN (files only this plugin family writes;
 *    any other file at those paths is left alone), and the named state files in uploads/mcp-bridge
 *    (hash and flag files; the directory is removed only if it is then empty, so the content
 *    snapshots in uploads/mcp-bridge/backups and exports stay). While the legacy plugin is active it
 *    still needs all of these, and removing them would cut its live connection.
 *  - Regardless of the legacy plugin: the mu-plugin 000-ovp-update-guard.php, only when its first
 *    800 bytes carry the marker OVP_UPDATE_GUARD. It is a temporary load guard that only this plugin
 *    writes during its own update; any other file at that path is left alone.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Is the legacy 1.x plugin still active (site or network)? Then everything it shares with us stays.
$ovp_legacy_active = false;
$ovp_active_lists  = array( (array) get_option( 'active_plugins', array() ) );
if ( function_exists( 'is_multisite' ) && is_multisite() ) {
	$ovp_active_lists[] = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );
}
foreach ( $ovp_active_lists as $ovp_list ) {
	foreach ( $ovp_list as $ovp_file ) {
		$ovp_file = is_string( $ovp_file ) ? strtolower( str_replace( '\\', '/', $ovp_file ) ) : '';
		if ( str_starts_with( $ovp_file, 'wp-mcp-bridge/' ) || 'wp-mcp-bridge.php' === $ovp_file ) {
			$ovp_legacy_active = true;
		}
	}
}

// Options of this plugin (and, when the legacy plugin is gone, of its 1.x predecessor). wp_options only.
$ovp_prefixes = $ovp_legacy_active ? array( 'ovp_', '_ovp_' ) : array( 'ovp_', '_ovp_', 'mcp_bridge_' );
foreach ( $ovp_prefixes as $ovp_prefix ) {
	$ovp_like = $wpdb->esc_like( $ovp_prefix ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $ovp_like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ovp_nonces" ); // phpcs:ignore WordPress.DB

wp_clear_scheduled_hook( 'ovp_heartbeat' );
wp_clear_scheduled_hook( 'ovp_heartbeat_retry' );
wp_clear_scheduled_hook( 'ovp_gc' );

if ( ! $ovp_legacy_active ) {
	$ovp_unlink_ours = static function ( string $path ): void {
		if ( ! is_file( $path ) || is_link( $path ) ) {
			return;
		}
		$head = (string) file_get_contents( $path, false, null, 0, 800 );
		if ( str_contains( $head, 'MCP_BRIDGE_FATAL_DROPIN' ) ) {
			unlink( $path );
		}
	};
	$ovp_unlink_ours( trailingslashit( WP_CONTENT_DIR ) . 'fatal-error-handler.php' );
	$ovp_unlink_ours( trailingslashit( WP_CONTENT_DIR ) . 'mcp-bridge-oob.php' );
	$ovp_unlink_ours( trailingslashit( WP_CONTENT_DIR ) . 'mcp-bridge-oob-lib.php' );
	$ovp_mu = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
	$ovp_unlink_ours( trailingslashit( $ovp_mu ) . '000-mcp-bridge-fatal.php' );
	$ovp_store = trailingslashit( WP_CONTENT_DIR ) . 'uploads/mcp-bridge';
	foreach ( array( 'oob.sha256', 'oob-hop.sha256', 'show-fatals.flag', 'fatal-latest.json', 'index.html', 'index.php', '.htaccess' ) as $ovp_leaf ) {
		$ovp_p = $ovp_store . '/' . $ovp_leaf;
		if ( is_file( $ovp_p ) && ! is_link( $ovp_p ) ) {
			unlink( $ovp_p );
		}
	}
	if ( is_dir( $ovp_store ) && ! is_link( $ovp_store ) ) {
		@rmdir( $ovp_store ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- only succeeds when empty: backups/ and exports/ keep it.
	}
}

// The update guard is ours alone (written only during a self-update), so it goes whether or not the
// legacy plugin is active. Same rule as above: only a file that carries the marker.
$ovp_unlink_guard = static function ( string $path ): void {
	if ( ! is_file( $path ) || is_link( $path ) ) {
		return;
	}
	$head = (string) file_get_contents( $path, false, null, 0, 800 );
	if ( str_contains( $head, 'OVP_UPDATE_GUARD' ) ) {
		unlink( $path );
	}
};
$ovp_mu_dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
$ovp_unlink_guard( trailingslashit( $ovp_mu_dir ) . '000-ovp-update-guard.php' );

// The service account (login mcp_bridge_service) and every post, page, attachment and menu it
// authored are deliberately left untouched. See the header of this file.
