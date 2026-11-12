<?php
/**
 * Plugin Name:       Overlord WP Fleet
 * Plugin URI:        https://overlord.team
 * Description:       Signed (Ed25519), tiered remote management for WordPress. Pair this site with an
 *                    Overlord WP Fleet master (or local agent) using a one-time code. Every request is
 *                    cryptographically signed, replay-protected and limited by a tier cap you control.
 * Version:           2.0.10
 * Update URI:        https://github.com/ovld-team/overlord-wp-fleet-releases
 * Requires at least: 6.0
 * Requires PHP:      8.3
 * Author:            Overlord Team
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       overlord-wp-fleet
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

/*
 * PHP guard. PHP 7-COMPATIBLE SYNTAX ONLY from here to the end of this block: no scalar or return
 * types, no arrow functions, no declare(strict_types). Shared hosts may run PHP 8.1/8.2 (or older)
 * and WordPress can be told to ignore the "Requires PHP" header; a parse error or fatal would take
 * the whole site down, so show an admin notice and load nothing instead. Everything that needs 8.3
 * lives below this block and is never parsed on an older PHP.
 *
 * OVP_TEST_PHP_VERSION_ID (tests only) can LOWER the detected version to simulate an old PHP; it
 * can never raise it.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

$ovp_php_id = PHP_VERSION_ID;
if ( defined( 'OVP_TEST_PHP_VERSION_ID' ) && (int) OVP_TEST_PHP_VERSION_ID < $ovp_php_id ) {
	$ovp_php_id = (int) OVP_TEST_PHP_VERSION_ID;
}
if ( $ovp_php_id < 80300 ) {
	unset( $ovp_php_id );
	if ( ! function_exists( 'ovp_fleet_php_notice' ) ) {
		function ovp_fleet_php_notice() {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Overlord WP Fleet requires PHP 8.3 or newer. It has been left inactive and nothing was loaded. Ask your host to switch this site to PHP 8.3, then activate the plugin again.', 'overlord-wp-fleet' );
			echo '</p></div>';
		}
	}
	add_action( 'admin_notices', 'ovp_fleet_php_notice' );
	add_action( 'network_admin_notices', 'ovp_fleet_php_notice' );
	return;
}
unset( $ovp_php_id );

/*
 * Coexistence with the legacy 1.x plugin (wp-mcp-bridge, "Overlord Team Management interface").
 * Every global symbol of this plugin is Ovp_* / OVP_* and is disjoint from the legacy MCP_Bridge_* /
 * WP_MCP_BRIDGE_* symbols (proved by tests/glue/coexist.php against the real 1.7.0 symbol list), so
 * both can be active at the same time, in either load order, until the site is migrated. Migration
 * (autopair, legacy deactivation and credential wipe) is Ovp_Migration.
 */

define( 'OVP_FLEET_VERSION', '2.0.10' );
define( 'OVP_FLEET_FILE', __FILE__ );

/*
 * Values that belonged to the removed bearer-token scheme (TOKEN/HOP) are mapped to option names
 * that are never written, so nothing can resurrect a token. The service-user login/meta keep their
 * 1.x values on purpose: it is the same hidden administrator account, so the migration reuses it.
 */
define( 'OVP_FLEET_PLUGIN_NAME', 'Overlord WP Fleet' );
define( 'OVP_FLEET_DIR', plugin_dir_path( __FILE__ ) );
define( 'OVP_FLEET_URL', plugin_dir_url( __FILE__ ) );
define( 'OVP_FLEET_REST_NAMESPACE', 'ovp/v1' );
define( 'OVP_FLEET_LOG_OPTION', 'ovp_request_log' );
define( 'OVP_FLEET_LOG_MAX', 100 );
define( 'OVP_FLEET_SERVICE_USER_LOGIN', 'mcp_bridge_service' );
define( 'OVP_FLEET_SERVICE_USER_META', 'mcp_bridge_service_account' );
define( 'OVP_FLEET_TOKEN_OPTION', 'ovp_removed_legacy_token' );
define( 'OVP_FLEET_HOP_OPTION', 'ovp_removed_legacy_hop' );

// Protocol, keys, tiers and the data layers.
require_once OVP_FLEET_DIR . 'includes/class-ovp-proto.php';
require_once OVP_FLEET_DIR . 'includes/class-ovp-keys.php';
require_once OVP_FLEET_DIR . 'includes/class-ovp-pair.php';
require_once OVP_FLEET_DIR . 'includes/class-ovp-tiers.php';
require_once OVP_FLEET_DIR . 'includes/class-ovp-upload.php';
require_once OVP_FLEET_DIR . 'includes/class-ovp-meta.php';
require_once OVP_FLEET_DIR . 'includes/class-ovp-db.php';

require_once OVP_FLEET_DIR . 'includes/class-ovp-log.php';
require_once OVP_FLEET_DIR . 'includes/class-ovp-net.php';
require_once OVP_FLEET_DIR . 'includes/class-ovp-auth.php';
require_once OVP_FLEET_DIR . 'includes/class-ovp-enroll.php';
require_once OVP_FLEET_DIR . 'includes/class-ovp-site-icon.php';
require_once OVP_FLEET_DIR . 'includes/class-ovp-fleet.php';
require_once OVP_FLEET_DIR . 'includes/class-ovp-errors.php';
require_once OVP_FLEET_DIR . 'includes/class-ovp-chunk.php';
require_once OVP_FLEET_DIR . 'includes/class-ovp-migrate-util.php';
require_once OVP_FLEET_DIR . 'includes/class-ovp-fatal.php';
require_once OVP_FLEET_DIR . 'includes/class-ovp-release.php';
require_once OVP_FLEET_DIR . 'includes/class-ovp-self-update.php';
require_once OVP_FLEET_DIR . 'includes/class-ovp-updater.php';
require_once OVP_FLEET_DIR . 'includes/class-ovp-settings-page.php';
require_once OVP_FLEET_DIR . 'includes/modules/class-module-base.php';
require_once OVP_FLEET_DIR . 'includes/modules/class-module-site-info.php';
require_once OVP_FLEET_DIR . 'includes/modules/class-module-content.php';
require_once OVP_FLEET_DIR . 'includes/modules/class-module-builder-apply.php';
require_once OVP_FLEET_DIR . 'includes/modules/class-module-plugins-themes.php';
require_once OVP_FLEET_DIR . 'includes/modules/class-module-users.php';
require_once OVP_FLEET_DIR . 'includes/modules/class-module-options.php';
require_once OVP_FLEET_DIR . 'includes/modules/class-module-files.php';
require_once OVP_FLEET_DIR . 'includes/modules/class-module-upload.php';
require_once OVP_FLEET_DIR . 'includes/modules/class-module-db.php';
require_once OVP_FLEET_DIR . 'includes/modules/class-module-cron.php';
require_once OVP_FLEET_DIR . 'includes/modules/class-module-catalog.php';
require_once OVP_FLEET_DIR . 'includes/modules/class-module-fleet.php';
require_once OVP_FLEET_DIR . 'includes/modules/class-module-yoast.php';
require_once OVP_FLEET_DIR . 'includes/modules/class-module-search-replace.php';
require_once OVP_FLEET_DIR . 'includes/modules/class-module-debug.php';
require_once OVP_FLEET_DIR . 'includes/modules/class-module-site-clone.php';
require_once OVP_FLEET_DIR . 'includes/modules/class-module-ops-login.php';
require_once OVP_FLEET_DIR . 'includes/modules/class-module-updates.php';
require_once OVP_FLEET_DIR . 'includes/modules/class-module-backup.php';
require_once OVP_FLEET_DIR . 'includes/modules/class-module-migrate.php';
require_once OVP_FLEET_DIR . 'includes/modules/class-module-media-push.php';
require_once OVP_FLEET_DIR . 'includes/modules/class-module-maintenance.php';
require_once OVP_FLEET_DIR . 'includes/class-ovp-media-rename.php';
require_once OVP_FLEET_DIR . 'includes/class-ovp-migration.php';
require_once OVP_FLEET_DIR . 'includes/class-ovp-bridge.php';

register_activation_hook( __FILE__, array( 'Ovp_Bridge', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Ovp_Bridge', 'deactivate' ) );

Ovp_Bridge::instance();
