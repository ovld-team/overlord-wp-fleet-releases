<?php
/**
 * /mcp-bridge/v1/plugins/* and /themes/* — list/install/activate/deactivate/delete,
 * driving the same core Plugin_Upgrader/Theme_Upgrader + WP_Filesystem code paths the
 * wp-admin UI uses (via the impersonated service account, see class-ovp-auth.php).
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/class-ovp-upload.php';
require_once dirname( __DIR__ ) . '/class-ovp-meta.php';

class Ovp_Module_Plugins_Themes extends Ovp_Module_Base {

	/** Tier per tool, consumed by class-ovp-tiers.php. */
	public const OVP_TIER = array(
		'plugins_list'            => 'T0',
		'plugins_export'          => 'T2',
		'plugins_install'         => 'T3',
		'plugins_activate'        => 'T3',
		'plugins_deactivate'      => 'T2',
		'plugins_delete'          => 'T2',
		'plugins_upload_begin'    => 'T3',
		'plugins_upload_finalize' => 'T3',
		'themes_list'             => 'T0',
		'themes_export'           => 'T2',
		'themes_install'          => 'T3',
		'themes_activate'         => 'T3',
		'themes_delete'           => 'T2',
		'themes_upload_begin'     => 'T3',
		'themes_upload_finalize'  => 'T3',
	);

	/** Fail-closed tier gate (central gate lives in OVP_Tiers; this is defense in depth). */
	private function gate( WP_REST_Request $request, string $need = 'T2' ): ?WP_Error {
		$have = OVP_Meta::request_tier( $request );
		if ( OVP_Upload::tier_rank( $have ) < OVP_Upload::tier_rank( $need ) ) {
			return $this->error( 'ovp_tier_denied', 'Tier ' . $need . ' required.', 403 );
		}
		return null;
	}

	public function register_routes(): void {
		// Crash recovery (OVP_Upload::recover_pending) is NOT run here: register_routes() fires on every
		// request. See Ovp_Bridge::recover_pending(): activation, admin_init (throttled) and the daily GC.
		$this->route( 'POST', 'plugins/upload/begin', array( $this, 'upload_begin_plugin' ) );
		$this->route( 'POST', 'plugins/upload/finalize', array( $this, 'upload_finalize_plugin' ) );
		$this->route( 'POST', 'themes/upload/begin', array( $this, 'upload_begin_theme' ) );
		$this->route( 'POST', 'themes/upload/finalize', array( $this, 'upload_finalize_theme' ) );
		$this->route( 'GET', 'plugins', array( $this, 'list_plugins' ) );
		$this->route( 'POST', 'plugins/install', array( $this, 'install_plugin' ) );
		$this->route( 'POST', 'plugins/activate', array( $this, 'activate_plugin' ) );
		$this->route( 'POST', 'plugins/deactivate', array( $this, 'deactivate_plugin' ) );
		$this->route( 'POST', 'plugins/delete', array( $this, 'delete_plugin' ) );
		$this->route(
			'GET',
			'plugins/export',
			array( $this, 'export_plugin' ),
			array(
				'args' => array(
					'plugin'    => array(
						'required' => true,
						'type'     => 'string',
					),
					'cursor'    => array(
						'type' => 'string',
					),
					'max_bytes' => array(
						'type' => 'integer',
					),
					'max_files' => array(
						'type' => 'integer',
					),
				),
			)
		);

		$this->route( 'GET', 'themes', array( $this, 'list_themes' ) );
		$this->route( 'POST', 'themes/install', array( $this, 'install_theme' ) );
		$this->route( 'POST', 'themes/activate', array( $this, 'activate_theme' ) );
		$this->route( 'POST', 'themes/delete', array( $this, 'delete_theme' ) );
		$this->route(
			'GET',
			'themes/export',
			array( $this, 'export_theme' ),
			array(
				'args' => array(
					'stylesheet' => array(
						'required' => true,
						'type'     => 'string',
					),
					'cursor'     => array(
						'type' => 'string',
					),
					'max_bytes'  => array(
						'type' => 'integer',
					),
					'max_files'  => array(
						'type' => 'integer',
					),
				),
			)
		);
	}

	// -- Plugins --------------------------------------------------------------

	public function list_plugins(): WP_REST_Response {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$all    = get_plugins();
		$active = (array) get_option( 'active_plugins', array() );

		$shaped = array();
		foreach ( $all as $file => $data ) {
			$shaped[] = array(
				'file'    => $file,
				'slug'    => $this->plugin_slug( $file ),
				'name'    => $data['Name'] ?? $file,
				'version' => $data['Version'] ?? '',
				'active'  => in_array( $file, $active, true ),
			);
		}

		return $this->success( $shaped );
	}

	public function install_plugin( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->gate( $request, 'T3' );
		if ( null !== $gate ) {
			return $gate;
		}
		$body = (array) $request->get_json_params();
		$inst = $this->install_from_url( (string) ( $body['zip_url'] ?? '' ), 'plugin', WP_PLUGIN_DIR);
		if ( is_wp_error( $inst ) ) {
			return $inst;
		}
		$out = array( 'plugin_file' => $this->find_plugin_file( (string) $inst['slug'] ), 'install' => $inst );
		if ( ! empty( $body['activate'] ) && ! $this->is_self_install( $inst ) ) {
			$out += $this->guarded_plugin_activation( $inst, $out['plugin_file'] );
		}
		return $this->success( $out );
	}

	/**
	 * Download through the SSRF-pinned fetcher, then run the same validated pipeline as
	 * an upload (zip-slip/ratio/symlink checks, PHP lint, staged swap with backup).
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private function install_from_url( string $zip_url, string $type, string $root ): array|WP_Error {
		if ( '' === $zip_url ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Provide "zip_url".', 400 );
		}
		$safe = Ovp_Net::assert_public_https_url( $zip_url );
		if ( is_wp_error( $safe ) ) {
			return $safe;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		// Tier T3 only (both install routes gate T3): follows up to 3 redirects, only to GitHub's release hosts.
		$tmp = Ovp_Net::download_following_redirects( $zip_url, 60, (int) OVP_Upload::cfg( 'max_total' ) );
		if ( is_wp_error( $tmp ) ) {
			return $this->error( 'mcp_bridge_install_failed', $tmp->get_error_message(), 400 );
		}
		$tmp = (string) $tmp;
		try {
			if ( (int) filesize( $tmp ) > (int) OVP_Upload::cfg( 'max_total' ) ) {
				return $this->error( 'ovp_zip_limits', 'Download exceeds the size cap.', 413 );
			}
			$base = OVP_Upload::sub_dir( 'tmp' );
			if ( is_wp_error( $base ) ) {
				return $base;
			}
			$work = $base . '/u-' . bin2hex( random_bytes( 8 ) );
			$res  = OVP_Upload::install_zip_file( $tmp, $work, $type, $root, $this->god() && 'plugin' === $type );
			OVP_Upload::rrmdir( $work );
			return $res;
		} finally {
			@unlink( $tmp );
		}
	}

	/** True when an install result is the in-place replacement of this plugin (it stays active; there is nothing to activate). */
	private function is_self_install( array $inst ): bool {
		return isset( $inst['self_update'] );
	}

	private function find_plugin_file( string $slug ): ?string {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		wp_cache_delete( 'plugins', 'plugins' );
		foreach ( array_keys( get_plugins() ) as $file ) {
			if ( str_starts_with( (string) $file, $slug . '/' ) ) {
				return (string) $file;
			}
		}
		return null;
	}

	/**
	 * Activate under the fatal guard. Always returns the full outcome envelope.
	 *
	 * @param array<string, mixed> $inst install result (target/backup) or minimal ctx
	 * @return array<string, mixed>
	 */
	private function guarded_plugin_activation( array $inst, ?string $plugin_file ): array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( null === $plugin_file ) {
			return array( 'activated' => false, 'rolled_back' => false, 'error_file' => '', 'error_line' => 0, 'error_msg' => 'No plugin header file found after install.' );
		}
		$probe_state = 'skipped';
		$res         = OVP_Upload::activate_guarded(
			array( 'kind' => 'plugin', 'slug' => (string) $inst['slug'], 'target' => (string) $inst['target'], 'backup' => $inst['backup'] ?? null, 'fresh' => ! empty( $inst['fresh'] ), 'plugin_file' => $plugin_file ),
			static function () use ( $plugin_file ) {
				$r = activate_plugin( $plugin_file, '', false, false );
				return $r;
			},
			function () use ( &$probe_state ): ?array {
				return $this->health_probe( $probe_state );
			},
			static function () use ( $plugin_file ): void {
				deactivate_plugins( $plugin_file, true );
				$active = (array) get_option( 'active_plugins', array() );
				$clean  = array_values( array_diff( $active, array( $plugin_file ) ) );
				if ( $clean !== $active ) {
					update_option( 'active_plugins', $clean );
				}
			},
			array( 'Ovp_Fatal', 'fatal_since' )
		);
		return array_merge( array( 'error_file' => '', 'error_line' => 0, 'error_msg' => '', 'probe' => $probe_state ), $res );
	}

	/**
	 * Loopback health check of the front page and the plugin REST namespace. A transport
	 * error (loopback blocked) is inconclusive, not a failure.
	 *
	 * @param-out string $state
	 * @return array<string, mixed>|null error info when unhealthy
	 */
	private function health_probe( string &$state ): ?array {
		$urls = array( home_url( '/' ), rest_url( ( defined( 'OVP_FLEET_REST_NAMESPACE' ) ? (string) OVP_FLEET_REST_NAMESPACE : 'ovp/v1' ) . '/site-info' ) );
		$ok   = 0;
		foreach ( $urls as $u ) {
			$r = wp_remote_get( $u, array( 'timeout' => 10, 'redirection' => 0, 'sslverify' => true, 'headers' => array( 'Cache-Control' => 'no-cache' ), 'user-agent' => 'ovp-health-probe' ) );
			if ( is_wp_error( $r ) ) {
				continue;
			}
			++$ok;
			$code = (int) wp_remote_retrieve_response_code( $r );
			if ( $code >= 500 ) {
				$state = 'failed';
				return array( 'error_msg' => 'Health probe got HTTP ' . $code . ' from ' . wp_parse_url( $u, PHP_URL_PATH ), 'error_file' => '', 'error_line' => 0 );
			}
		}
		$state = $ok > 0 ? 'ok' : 'inconclusive';
		return null;
	}

	public function activate_plugin( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->gate( $request, 'T3' );
		if ( null !== $gate ) {
			return $gate;
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugin = $this->clean_plugin_file( (string) ( $request->get_json_params()['plugin'] ?? '' ) );
		if ( null === $plugin || ! file_exists( WP_PLUGIN_DIR . '/' . $plugin ) ) {
			return $this->error( 'mcp_bridge_not_found', 'Plugin file not found.', 404 );
		}
		if ( $this->is_self_plugin( $plugin ) || $this->is_legacy_plugin( $plugin ) ) {
			return $this->success( array( 'activated' => true, 'rolled_back' => false, 'error_msg' => '', 'note' => 'Overlord plugin is managed separately.' ) );
		}
		$slug = explode( '/', $plugin )[0];
		$res  = $this->guarded_plugin_activation(
			array( 'slug' => $slug, 'target' => WP_PLUGIN_DIR . '/' . $slug, 'backup' => null ),
			$plugin
		);
		return $this->success( $res + array( 'plugin' => $plugin ) );
	}

	/** Plugin file path relative to WP_PLUGIN_DIR, or null if it is not a plain relative path. */
	private function clean_plugin_file( string $plugin ): ?string {
		$plugin = str_replace( '\\', '/', $plugin );
		if ( '' === $plugin || str_starts_with( $plugin, '/' ) || str_contains( $plugin, '..' ) || str_contains( $plugin, "\0" ) || 1 !== preg_match( '#^[A-Za-z0-9._/-]{1,200}\.php$#', $plugin ) ) {
			return null;
		}
		return $plugin;
	}

	public function upload_begin_plugin( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->upload_begin( $request, 'plugin' );
	}

	public function upload_begin_theme( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->upload_begin( $request, 'theme' );
	}

	private function upload_begin( WP_REST_Request $request, string $type ): WP_REST_Response|WP_Error {
		$gate = $this->gate( $request, 'T3' );
		if ( null !== $gate ) {
			return $gate;
		}
		$body = (array) $request->get_json_params();
		$res  = OVP_Upload::begin(
			array( 'kind' => 'zip', 'type' => $type, 'total_size' => (int) ( $body['total_size'] ?? 0 ), 'sha256' => (string) ( $body['sha256'] ?? '' ) ),
			OVP_Meta::request_tier( $request )
		);
		return is_wp_error( $res ) ? $res : $this->success( $res );
	}

	public function upload_finalize_plugin( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->gate( $request, 'T3' );
		if ( null !== $gate ) {
			return $gate;
		}
		$body = (array) $request->get_json_params();
		$inst = OVP_Upload::finalize_zip( (string) ( $body['upload_id'] ?? '' ), OVP_Meta::request_tier( $request ), WP_PLUGIN_DIR, $this->god() );
		if ( is_wp_error( $inst ) ) {
			return $inst;
		}
		$file = $this->find_plugin_file( (string) $inst['slug'] );
		$out  = array( 'installed' => true, 'plugin_file' => $file ) + $inst;
		$out['activated']   = false;
		$out['rolled_back'] = false;
		$out['error_file']  = '';
		$out['error_line']  = 0;
		$out['error_msg']   = '';
		if ( ! empty( $body['activate'] ) && ! $this->is_self_install( $inst ) ) {
			$out = array_merge( $out, $this->guarded_plugin_activation( $inst, $file ) );
		}
		return $this->success( $out );
	}

	public function upload_finalize_theme( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->gate( $request, 'T3' );
		if ( null !== $gate ) {
			return $gate;
		}
		$body = (array) $request->get_json_params();
		$inst = OVP_Upload::finalize_zip( (string) ( $body['upload_id'] ?? '' ), OVP_Meta::request_tier( $request ), get_theme_root() );
		if ( is_wp_error( $inst ) ) {
			return $inst;
		}
		$out = array( 'installed' => true, 'stylesheet' => $inst['slug'], 'activated' => false, 'rolled_back' => false, 'error_file' => '', 'error_line' => 0, 'error_msg' => '' ) + $inst;
		if ( ! empty( $body['activate'] ) ) {
			$out = array_merge( $out, $this->guarded_theme_activation( $inst ) );
		}
		return $this->success( $out );
	}

	/**
	 * @param array<string, mixed> $inst
	 * @return array<string, mixed>
	 */
	private function guarded_theme_activation( array $inst ): array {
		$slug        = (string) $inst['slug'];
		$previous    = get_stylesheet();
		$probe_state = 'skipped';
		wp_clean_themes_cache();
		$res = OVP_Upload::activate_guarded(
			array( 'kind' => 'theme', 'slug' => $slug, 'target' => (string) $inst['target'], 'backup' => $inst['backup'] ?? null, 'fresh' => ! empty( $inst['fresh'] ) ),
			static function () use ( $slug ) {
				switch_theme( $slug );
				return true;
			},
			function () use ( &$probe_state ): ?array {
				return $this->health_probe( $probe_state );
			},
			static function () use ( $previous ): void {
				switch_theme( $previous );
			},
			array( 'Ovp_Fatal', 'fatal_since' )
		);
		return array_merge( array( 'probe' => $probe_state ), $res );
	}

	public function deactivate_plugin( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->gate( $request );
		if ( null !== $gate ) {
			return $gate;
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugin = (string) ( $request->get_json_params()['plugin'] ?? '' );
		$bad    = $this->plugin_file_error( $plugin, false );
		if ( null !== $bad ) {
			return $bad;
		}
		if ( $this->is_self_plugin( $plugin ) && ! $this->god() ) {
			return $this->error( 'mcp_bridge_self_protected', 'Deactivating the Overlord plugin needs effective tier T3.', 403 );
		}
		deactivate_plugins( $plugin );

		return $this->success( array( 'deactivated' => $plugin ) );
	}

	public function delete_plugin( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->gate( $request );
		if ( null !== $gate ) {
			return $gate;
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$plugin = (string) ( $request->get_json_params()['plugin'] ?? '' );
		$bad    = $this->plugin_file_error( $plugin, true );
		if ( null !== $bad ) {
			return $bad;
		}
		if ( $this->is_self_plugin( $plugin ) || $this->has_text_domain( $plugin, 'overlord-wp-fleet' ) ) {
			if ( ! $this->god() ) {
				return $this->error( 'mcp_bridge_self_protected', 'Deleting the Overlord plugin needs effective tier T3.', 403 );
			}
			return $this->delete_self_files( $plugin );
		}
		// delete_plugins() runs the plugin's uninstall.php. The legacy 1.x uninstall.php deletes the
		// mcp_bridge_service user without reassigning its content, which removes every page and
		// attachment the bridge created. Its files are removed only through fleet/legacy-retire
		// (delete_files), which deletes the folder without running uninstall.php.
		if ( $this->is_legacy_plugin( $plugin ) || $this->has_text_domain( $plugin, 'wp-mcp-bridge' ) ) {
			return $this->error( 'ovp_legacy_delete_unsafe', 'Deleting the legacy wp-mcp-bridge plugin through plugins/delete would run its uninstall.php, which deletes the mcp_bridge_service user and all content it authored. Use fleet_site_migrate_finalize {deleteLegacyFiles:true} (route fleet/legacy-retire), which removes the files without running uninstall.php.', 409 );
		}
		deactivate_plugins( $plugin );
		$result = delete_plugins( array( $plugin ) );

		if ( is_wp_error( $result ) ) {
			return $this->error( 'mcp_bridge_delete_failed', $result->get_error_message(), 400 );
		}

		return $this->success( array( 'deleted' => $plugin ) );
	}

	/**
	 * T3 only: delete this plugin's folder (or a stray copy of it) by removing the directory.
	 *
	 * This deliberately does NOT call delete_plugins(): that runs the copy's uninstall.php, and copies
	 * up to 2.0.5 end that file with wp_delete_user( mcp_bridge_service ) and no reassign, which takes
	 * every post, page and attachment the service account created with it. Nothing here deletes a
	 * user, post or term. The options (keys, pairing) stay in the database, so a later reinstall of the
	 * plugin resumes the pairing; unpair in wp-admin or revoke in the master to remove it.
	 */
	private function delete_self_files( string $plugin ): WP_REST_Response|WP_Error {
		$plugin = str_replace( '\\', '/', $plugin );
		$seg    = explode( '/', $plugin );
		if ( count( $seg ) < 2 || '' === $seg[0] || '.' === $seg[0] || '..' === $seg[0] ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Provide the plugin file inside its folder.', 400 );
		}
		$base = realpath( WP_PLUGIN_DIR );
		$dir  = WP_PLUGIN_DIR . '/' . $seg[0];
		$real = realpath( $dir );
		if ( false === $base || false === $real || is_link( $dir ) || $real === $base || dirname( $real ) !== $base || ! is_dir( $real ) ) {
			return $this->error( 'mcp_bridge_not_found', 'Plugin folder not found, or not a direct child of the plugins directory.', 404 );
		}
		deactivate_plugins( $plugin, true );
		OVP_Upload::rrmdir( $real );
		if ( is_dir( $real ) ) {
			return $this->error( 'mcp_bridge_delete_failed', 'The plugin folder could not be removed completely.', 500 );
		}
		return $this->success( array( 'deleted' => $plugin, 'uninstall_php_run' => false ) );
	}

	// -- Themes ---------------------------------------------------------------

	public function list_themes(): WP_REST_Response {
		$active = get_stylesheet();
		$shaped = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$shaped[] = array(
				'stylesheet' => $stylesheet,
				'slug'       => $stylesheet,
				'name'       => $theme->get( 'Name' ),
				'version'    => $theme->get( 'Version' ),
				'active'     => $stylesheet === $active,
			);
		}
		return $this->success( $shaped );
	}

	public function install_theme( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->gate( $request, 'T3' );
		if ( null !== $gate ) {
			return $gate;
		}
		$body = (array) $request->get_json_params();
		$inst = $this->install_from_url( (string) ( $body['zip_url'] ?? '' ), 'theme', get_theme_root() );
		if ( is_wp_error( $inst ) ) {
			return $inst;
		}
		$out = array( 'stylesheet' => $inst['slug'], 'install' => $inst );
		if ( ! empty( $body['activate'] ) ) {
			$out += $this->guarded_theme_activation( $inst );
		}
		return $this->success( $out );
	}

	public function activate_theme( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->gate( $request, 'T3' );
		if ( null !== $gate ) {
			return $gate;
		}
		$stylesheet = sanitize_key( (string) ( $request->get_json_params()['stylesheet'] ?? '' ) );
		if ( '' === $stylesheet || ! wp_get_theme( $stylesheet )->exists() ) {
			return $this->error( 'mcp_bridge_not_found', 'Theme not found.', 404 );
		}
		$res = $this->guarded_theme_activation( array( 'slug' => $stylesheet, 'target' => get_theme_root() . '/' . $stylesheet, 'backup' => null ) );
		return $this->success( $res + array( 'stylesheet' => $stylesheet ) );
	}

	public function delete_theme( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->gate( $request );
		if ( null !== $gate ) {
			return $gate;
		}
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$stylesheet = (string) ( $request->get_json_params()['stylesheet'] ?? '' );
		if ( 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.\-]*$/', $stylesheet ) || str_contains( $stylesheet, '..' ) ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Invalid theme stylesheet.', 400 );
		}
		if ( function_exists( 'wp_get_theme' ) && ! wp_get_theme( $stylesheet )->exists() ) {
			return $this->error( 'mcp_bridge_not_found', 'Theme not found.', 404 );
		}
		$root = function_exists( 'get_theme_root' ) ? realpath( (string) get_theme_root( $stylesheet ) ) : false;
		$dir  = false === $root ? false : realpath( $root . '/' . $stylesheet );
		if ( false !== $root && ( false === $dir || dirname( $dir ) !== $root ) ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Theme directory is not a direct child of the themes root.', 400 );
		}
		$result     = delete_theme( $stylesheet );

		if ( is_wp_error( $result ) ) {
			return $this->error( 'mcp_bridge_delete_failed', $result->get_error_message(), 400 );
		}

		return $this->success( array( 'deleted' => $stylesheet ) );
	}

	public function export_plugin( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->gate( $request );
		if ( null !== $gate ) {
			return $gate;
		}
		$plugin = (string) $request->get_param( 'plugin' );
		if ( '' === $plugin || $this->is_self_plugin( $plugin ) || $this->is_legacy_plugin( $plugin ) ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Provide a plugin file that is not Overlord itself.', 400 );
		}
		$plugin = str_replace( '\\', '/', $plugin );
		if ( str_contains( $plugin, '..' ) || str_starts_with( $plugin, '/' ) || '.' === $plugin || '/' === $plugin ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Invalid plugin path.', 400 );
		}
		$slug = dirname( $plugin );
		if ( '.' === $slug || '' === $slug ) {
			$slug = basename( $plugin, '.php' );
		}
		if ( '' === $slug || '.' === $slug || str_contains( $slug, '/' ) || str_contains( $slug, '..' ) ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Invalid plugin slug.', 400 );
		}
		$root = realpath( WP_PLUGIN_DIR . '/' . $slug );
		$base = realpath( WP_PLUGIN_DIR );
		if ( false === $root || false === $base || $root === $base || ! is_dir( $root ) || ! Ovp_Net::path_inside( $root, $base ) ) {
			return $this->error( 'mcp_bridge_not_found', 'Plugin directory not found.', 404 );
		}
		return $this->export_tree( $root, $slug . '.zip', $request );
	}

	public function export_theme( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->gate( $request );
		if ( null !== $gate ) {
			return $gate;
		}
		$stylesheet = sanitize_key( (string) $request->get_param( 'stylesheet' ) );
		if ( '' === $stylesheet ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Provide stylesheet.', 400 );
		}
		$root = realpath( get_theme_root() . '/' . $stylesheet );
		$base = realpath( get_theme_root() );
		if ( false === $root || false === $base || $root === $base || ! is_dir( $root ) || ! Ovp_Net::path_inside( $root, $base ) ) {
			return $this->error( 'mcp_bridge_not_found', 'Theme directory not found.', 404 );
		}
		return $this->export_tree( $root, $stylesheet . '.zip', $request );
	}

	private function export_tree( string $root, string $name, WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$listed = $this->collect_export_files( $root );
		if ( $listed instanceof WP_Error ) {
			return $listed;
		}
		if ( array() === $listed ) {
			return $this->success( Ovp_Chunk::envelope( array( 'mode' => 'files', 'filename' => $name, 'files' => array() ), '' ) );
		}

		$max_bytes = Ovp_Chunk::max_bytes( $request->get_param( 'max_bytes' ) );
		$max_files = Ovp_Chunk::max_items( $request->get_param( 'max_files' ), Ovp_Chunk::DEFAULT_FILES );
		[ $cur_path, $cur_off ] = $this->parse_export_cursor( (string) $request->get_param( 'cursor' ) );
		if ( $cur_off < 0 ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Export cursor must be JSON {"p":path,"o":offset}.', 400 );
		}

		$out   = array();
		$used  = 0;
		$next  = '';
		$start = microtime( true );
		foreach ( $listed as $row ) {
			$rel  = $row['rel'];
			$full = $row['full'];
			$size = $row['size'];
			if ( '' !== $cur_path && strcmp( $rel, $cur_path ) < 0 ) {
				continue;
			}
			$offset = ( $rel === $cur_path ) ? $cur_off : 0;
			if ( $offset >= $size ) {
				continue;
			}
			$remain = $size - $offset;
			if ( array() !== $out && ( $used + min( $remain, $max_bytes ) > $max_bytes || count( $out ) >= $max_files || ( microtime( true ) - $start ) > Ovp_Chunk::DEFAULT_SECONDS ) ) {
				$next = $this->export_cursor( $rel, $offset );
				break;
			}
			$take = min( $remain, $max_bytes - $used );
			if ( $take < 1 ) {
				$next = $this->export_cursor( $rel, $offset );
				break;
			}
			$fh = fopen( $full, 'rb' );
			if ( false === $fh ) {
				return $this->error( 'mcp_bridge_zip_failed', 'Could not read ' . $rel, 502 );
			}
			if ( $offset > 0 ) {
				fseek( $fh, $offset );
			}
			$raw = (string) fread( $fh, $take );
			fclose( $fh );
			$out[]   = array(
				'path'     => $rel,
				'offset'   => $offset,
				'total'    => $size,
				'bytes'    => strlen( $raw ),
				'encoding' => 'base64',
				'content'  => base64_encode( $raw ),
			);
			$used   += strlen( $raw );
			$new_off = $offset + strlen( $raw );
			if ( $new_off < $size ) {
				$next = $this->export_cursor( $rel, $new_off );
				break;
			}
		}

		return $this->success(
			Ovp_Chunk::envelope(
				array(
					'mode'     => 'files',
					'filename' => $name,
					'files'    => $out,
				),
				$next
			)
		);
	}

	/**
	 * @return array{0: string, 1: int}
	 */
	private function parse_export_cursor( string $cursor ): array {
		if ( '' === $cursor ) {
			return array( '', 0 );
		}
		$decoded = json_decode( $cursor, true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['p'] ) || ! is_string( $decoded['p'] ) ) {
			return array( '', -1 );
		}
		return array( $decoded['p'], max( 0, (int) ( $decoded['o'] ?? 0 ) ) );
	}

	private function export_cursor( string $rel, int $offset ): string {
		$encoded = wp_json_encode( array( 'p' => $rel, 'o' => $offset ) );
		return is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * @return array<int, array{rel: string, full: string, size: int}>|WP_Error
	 */
	private function collect_export_files( string $root ): array|WP_Error {
		$self_dir  = defined( 'OVP_FLEET_DIR' ) ? realpath( OVP_FLEET_DIR ) : false;
		$out       = array();
		$bytes     = 0;
		$max_bytes = 256 * 1024 * 1024;
		$max_files = 20000;
		$iter      = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iter as $file ) {
			if ( ! $file instanceof SplFileInfo || ! $file->isFile() || $file->isLink() ) {
				continue;
			}
			$resolved = realpath( $file->getPathname() );
			if ( false === $resolved || ! Ovp_Net::path_inside( $resolved, $root ) ) {
				continue;
			}
			if ( is_string( $self_dir ) && Ovp_Net::path_inside( $resolved, $self_dir ) ) {
				continue;
			}
			$rel = ltrim( substr( $resolved, strlen( $root ) ), '/' );
			if ( '' === $rel || str_contains( $rel, '.git/' ) ) {
				continue;
			}
			$size = (int) $file->getSize();
			if ( $size > 80 * 1024 * 1024 ) {
				return $this->error( 'mcp_bridge_zip_too_large', 'Export file exceeds 80MB.', 413 );
			}
			$bytes += $size;
			if ( count( $out ) + 1 > $max_files || $bytes > $max_bytes ) {
				return $this->error( 'mcp_bridge_zip_too_large', 'Export exceeds 256MB or 20000 files.', 413 );
			}
			$out[] = array(
				'rel'  => $rel,
				'full' => $resolved,
				'size' => $size,
			);
		}
		usort(
			$out,
			static function ( array $a, array $b ): int {
				return strcmp( $a['rel'], $b['rel'] );
			}
		);
		return $out;
	}

	private function plugin_slug( string $plugin ): string {
		$plugin = str_replace( '\\', '/', $plugin );
		$dir    = dirname( $plugin );
		if ( '.' === $dir || '' === $dir ) {
			return basename( $plugin, '.php' );
		}
		return $dir;
	}

	/** Installed plugin whose header declares this text domain (a renamed folder of a known plugin). */
	private function has_text_domain( string $plugin, string $domain ): bool {
		$all = function_exists( 'get_plugins' ) ? (array) get_plugins() : array();
		return is_array( $all[ $plugin ] ?? null ) && $domain === (string) ( $all[ $plugin ]['TextDomain'] ?? '' );
	}

	/**
	 * The legacy 1.x plugin: never activated or exported from here (it would bring the bearer token
	 * back). Deactivating it is allowed so the master can finish a migration; deleting it through
	 * plugins/delete is refused (its uninstall.php deletes the service user and its content), the
	 * files go through fleet/legacy-retire instead.
	 */
	private function is_legacy_plugin( string $plugin ): bool {
		return str_contains( strtolower( str_replace( '\\', '/', $plugin ) ), 'wp-mcp-bridge' );
	}

	private function is_self_plugin( string $plugin ): bool {
		$plugin = strtolower( str_replace( '\\', '/', $plugin ) );
		if ( str_contains( $plugin, 'overlord-wp-fleet' ) ) {
			return true;
		}
		// Realpath-based: the resolved plugin directory must not be this plugin's own directory.
		$self = realpath( defined( 'OVP_FLEET_DIR' ) ? (string) OVP_FLEET_DIR : dirname( __DIR__, 2 ) );
		$segs = explode( '/', $plugin );
		if ( false !== $self && count( $segs ) >= 2 ) {
			$cand = realpath( WP_PLUGIN_DIR . '/' . $segs[0] );
			if ( false !== $cand && $cand === $self ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A plugin file reference must be "file.php" or "dir/file.php": no traversal, absolute, backslash,
	 * NUL or dot segments, and its directory must be a direct child of WP_PLUGIN_DIR (after realpath).
	 * Core delete_plugins() does no validate_file() and recursively deletes dirname(path), so this is the gate.
	 */
	private function plugin_file_error( string $plugin, bool $must_be_installed ): ?WP_Error {
		$segs = explode( '/', $plugin );
		$ok   = '' !== $plugin && strlen( $plugin ) <= 255 && count( $segs ) <= 2 && str_ends_with( strtolower( $plugin ), '.php' )
			&& ! str_contains( $plugin, "\0" ) && ! str_contains( $plugin, '\\' );
		if ( $ok ) {
			foreach ( $segs as $seg ) {
				if ( '' === $seg || '.' === $seg || '..' === $seg || str_contains( $seg, '..' ) || str_starts_with( $seg, '.' ) ) {
					$ok = false;
					break;
				}
			}
		}
		if ( ! $ok ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Invalid plugin file reference.', 400 );
		}
		if ( $must_be_installed && ! array_key_exists( $plugin, (array) get_plugins() ) ) {
			return $this->error( 'mcp_bridge_not_found', 'Plugin is not installed.', 404 );
		}
		if ( 2 === count( $segs ) ) {
			$root = realpath( WP_PLUGIN_DIR );
			$dir  = realpath( WP_PLUGIN_DIR . '/' . $segs[0] );
			if ( false !== $root && ( false === $dir || dirname( $dir ) !== $root ) && ( $must_be_installed || false !== $dir ) ) {
				return $this->error( 'mcp_bridge_invalid_payload', 'Plugin directory is not a direct child of the plugins directory.', 400 );
			}
		}
		return null;
	}
}
