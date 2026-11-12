<?php
/**
 * Plugin bootstrap singleton.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ovp_Bridge {

	public const SCHEMA_OPTION = 'ovp_schema';
	public const LEGACY_FLAG   = 'ovp_legacy_detected';
	public const GC_HOOK       = 'ovp_gc';

	/** Options written by the removed 1.x bearer-token scheme. */
	private const LEGACY_OPTIONS = array( 'mcp_bridge_token', 'mcp_bridge_hop', 'mcp_bridge_enroll', 'mcp_bridge_enroll_pending', 'mcp_bridge_update_retry' );

	private static ?Ovp_Bridge $instance = null;

	/** @var Ovp_Module_Base[] */
	private array $modules = array();

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->modules = array(
			new Ovp_Module_Site_Info(),
			new Ovp_Module_Content(),
			new Ovp_Module_Builder_Apply(),
			new Ovp_Module_Plugins_Themes(),
			new Ovp_Module_Users(),
			new Ovp_Module_Options(),
			new Ovp_Module_Files(),
			new Ovp_Module_Upload(),
			new Ovp_Module_Db(),
			new Ovp_Module_Cron(),
			new Ovp_Module_Catalog(),
			new Ovp_Module_Fleet(),
			new Ovp_Module_Yoast(),
			new Ovp_Module_Search_Replace(),
			new Ovp_Module_Debug(),
			new Ovp_Module_Site_Clone(),
			new Ovp_Module_Ops_Login(),
			new Ovp_Module_Updates(),
			new Ovp_Module_Backup(),
			new Ovp_Module_Migrate(),
			new Ovp_Module_Media_Push(),
			new Ovp_Module_Maintenance(),
		);

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'template_redirect', array( 'Ovp_Media_Rename', 'maybe_redirect' ), 0 );
		add_action( 'admin_menu', array( 'Ovp_Settings_Page', 'register_menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'legacy_notice' ) );
		add_action( 'pre_user_query', array( __CLASS__, 'hide_service_account_from_user_queries' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ), 5 );
		add_action( 'init', array( 'Ovp_Fatal', 'ensure_installed' ), 1 );
		add_action( self::GC_HOOK, array( 'OVP_Upload', 'gc' ) );
		add_action( self::GC_HOOK, array( __CLASS__, 'recover_pending' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_recover_pending' ) );
		add_action( 'init', array( __CLASS__, 'schedule_gc' ), 100 );
		Ovp_Auth::hooks();
		Ovp_Fleet::hooks();
		Ovp_Errors::hooks();
		Ovp_Updater::hooks();
		Ovp_Self_Update::hooks();
		Ovp_Migration::hooks();
	}

	public function register_routes(): void {
		foreach ( $this->modules as $module ) {
			$module->register_routes();
		}
		// Migration shim: only when the 1.x token scheme was found and removed on this site.
		if ( false !== get_option( self::LEGACY_FLAG, false ) ) {
			register_rest_route(
				'mcp-bridge/v1',
				'/(?P<rest>.*)',
				array(
					'methods'             => 'GET, POST, PUT, PATCH, DELETE',
					'permission_callback' => '__return_true', // Public on purpose: returns a fixed message, no data.
					'callback'            => static function () {
						return new WP_REST_Response(
							array(
								'ok'      => false,
								'error'   => 'legacy_endpoint_removed',
								'message' => 'This site now runs Overlord WP Fleet 2.0. The old token API is gone. An administrator must pair the site again in wp-admin (Settings > Overlord WP Fleet).',
							),
							410
						);
					},
				)
			);
		}
	}

	/**
	 * Schema/version gate (autoloaded option, so one cheap read per request): installs the
	 * replay table and runs the one-time legacy credential purge after an upgrade from 1.x.
	 */
	public static function maybe_upgrade(): void {
		if ( OVP_FLEET_VERSION === get_option( self::SCHEMA_OPTION, '' ) ) {
			return;
		}
		Ovp_Nonce_Store_Wpdb::install();
		self::purge_legacy();
		update_option( self::SCHEMA_OPTION, OVP_FLEET_VERSION, true );
		Ovp_Fleet::schedule_post_update_heartbeat(); // First request on new code after an update.
	}

	/**
	 * Detect leftovers of the 1.x scheme. The old bearer token, hop and enroll state are
	 * deleted (the credential is revoked), the old out-of-band hash files are removed, and a
	 * flag makes the admin re-pair.
	 */
	public static function purge_legacy( bool $force = false, bool $flag = true ): bool {
		// While the legacy plugin is still running it needs these credentials: the migration retires it
		// first (Ovp_Migration::retire_legacy forces the purge afterwards).
		if ( ! $force && Ovp_Migration::legacy_active() ) {
			return false;
		}
		$found = self::wipe_legacy_options() > 0;
		if ( $found ) {
			$dir = trailingslashit( WP_CONTENT_DIR ) . 'uploads/mcp-bridge';
			foreach ( array( 'oob.sha256', 'oob-hop.sha256' ) as $leaf ) {
				if ( is_file( $dir . '/' . $leaf ) ) {
					@unlink( $dir . '/' . $leaf ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
			}
			if ( $flag ) {
				update_option( self::LEGACY_FLAG, time(), false );
			}
		}
		return $found;
	}

	/**
	 * Delete the 1.x credential options (token, hop, enroll state). Does not look at whether the legacy
	 * plugin runs: callers decide (purge_legacy guards, Ovp_Migration::retire forces).
	 *
	 * @return int number of options that existed and were deleted
	 */
	public static function wipe_legacy_options(): int {
		$n = 0;
		foreach ( self::LEGACY_OPTIONS as $name ) {
			if ( false !== get_option( $name, false ) ) {
				++$n;
				delete_option( $name );
			}
		}
		return $n;
	}

	public static function legacy_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || Ovp_Keys::is_bound() || false === get_option( self::LEGACY_FLAG, false ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Overlord WP Fleet 2.0:', 'overlord-wp-fleet' ) . '</strong> ';
		echo esc_html__( 'the old access token was removed and the old API no longer works. Pair this site again to reconnect it.', 'overlord-wp-fleet' );
		echo ' <a href="' . esc_url( admin_url( 'options-general.php?page=' . Ovp_Settings_Page::SLUG ) ) . '">' . esc_html__( 'Open the pairing page', 'overlord-wp-fleet' ) . '</a></p></div>';
	}

	/** Option holding the time of the last crash-recovery sweep (throttle for admin_init). */
	public const RECOVER_OPTION = 'ovp_recover_at';
	public const RECOVER_EVERY  = 300;

	/**
	 * Finish plugin/theme activations that a hard-killed process could not roll back
	 * (OVP_Upload::recover_pending, markers older than 30 s). Runs on activation, from admin_init
	 * at most every RECOVER_EVERY seconds, and from the daily GC event; never per REST request.
	 */
	public static function recover_pending(): int {
		return OVP_Upload::recover_pending(
			static function ( array $ctx ): void {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
				if ( 'plugin' === ( $ctx['kind'] ?? '' ) && ! empty( $ctx['plugin_file'] ) ) {
					deactivate_plugins( (string) $ctx['plugin_file'], true );
				}
			}
		);
	}

	public static function maybe_recover_pending(): void {
		$last = (int) get_option( self::RECOVER_OPTION, 0 );
		if ( time() - $last < self::RECOVER_EVERY ) {
			return;
		}
		update_option( self::RECOVER_OPTION, time(), false );
		self::recover_pending();
	}

	public static function schedule_gc(): void {
		if ( ! wp_next_scheduled( self::GC_HOOK ) ) {
			wp_schedule_event( time() + 600, 'daily', self::GC_HOOK );
		}
	}

	/**
	 * Machine-only activation: create the replay table and drop-ins. Nothing contacts any
	 * server; the site stays unpaired (and inert) until an admin pairs it.
	 */
	public static function activate(): void {
		Ovp_Nonce_Store_Wpdb::install();
		self::purge_legacy();
		update_option( self::SCHEMA_OPTION, OVP_FLEET_VERSION, true );
		self::recover_pending();
		Ovp_Fatal::install();
		Ovp_Fleet::schedule();
		Ovp_Fleet::schedule_post_update_heartbeat();
		Ovp_Migration::run( 'activate' );
	}

	/**
	 * Deactivation stops outbound calls. Keys stay (re-activating should not silently drop
	 * the pairing); use Unpair or uninstall for that.
	 */
	public static function deactivate(): void {
		Ovp_Fleet::unschedule();
		Ovp_Updater::unschedule_tick();
		wp_clear_scheduled_hook( self::GC_HOOK );
	}

	/**
	 * Keep the internal service account out of Users list / wp_list_users() results so
	 * it never confuses a human admin auditing real users.
	 *
	 * @param WP_User_Query $query
	 */
	public static function hide_service_account_from_user_queries( WP_User_Query $query ): void {
		global $wpdb;

		if ( ! is_admin() ) {
			return;
		}

		$query->query_where .= $wpdb->prepare(
			" AND {$wpdb->users}.user_login != %s",
			OVP_FLEET_SERVICE_USER_LOGIN
		);
	}
}
