<?php
/**
 * GET /ovp/v1/info, /inventory, /site-info, /logs — read-only (T0). `info` and `inventory`
 * are what the master polls; `inventory` always returns arrays (never omitted, [] when empty).
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ovp_Module_Site_Info extends Ovp_Module_Base {

	public function register_routes(): void {
		$this->route( 'GET', 'info', array( $this, 'get_info' ) );
		$this->route( 'GET', 'inventory', array( $this, 'get_inventory' ) );
		$this->route( 'GET', 'site-info', array( $this, 'get_site_info' ) );
		$this->route( 'GET', 'logs', array( $this, 'get_logs' ) );
		$this->route( 'GET', 'agent/connect', array( $this, 'get_agent_connect' ) );
	}

	public function get_agent_connect(): WP_REST_Response {
		return $this->success( self::agent_connect_info() );
	}

	/**
	 * How to point an AI agent at the Fleet master that manages this site. Read-only and secret-free:
	 * the bearer token is a placeholder (a person creates it in the Fleet panel), the relay URL and
	 * relay token are never used, and no new auth path exists: the agent talks to the master's /mcp.
	 *
	 * @return array<string,mixed>
	 */
	public static function agent_connect_info(): array {
		$cfg   = Ovp_Enroll::config();
		$base  = $cfg['master_url'];
		$known = '' !== $base;
		$url   = ( $known ? $base : '<MASTER_URL>' ) . '/mcp';
		$name  = 'overlord-wp-fleet';
		$hdr   = 'Authorization: Bearer YOUR_FLEET_API_TOKEN';
		$json  = array( 'url' => $url, 'headers' => array( 'Authorization' => 'Bearer YOUR_FLEET_API_TOKEN' ) );
		$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
		$notes = array(
			'Create a personal API token in the Fleet panel and replace YOUR_FLEET_API_TOKEN. This plugin never stores or shows it.',
			'Tools take a "site" argument: use the site id shown below (or the site name from the Fleet panel).',
			'What an agent can do on this site is limited by the tier cap you set in Settings > Overlord WP Fleet.',
		);
		if ( ! $known ) {
			$notes[] = 'No master URL is saved on this site (proxy mode keeps only the relay). Replace <MASTER_URL> with the public address of your Fleet master.';
		}
		return array(
			'mcp_url'     => $url,
			'url_known'   => $known,
			'transport'   => 'streamable-http',
			'auth'        => 'Authorization: Bearer <personal API token created in the Fleet panel>',
			'site'        => Ovp_Keys::is_bound() ? Ovp_Keys::site_id() : '',
			'paired'      => Ovp_Keys::is_bound(),
			'snippets'    => array(
				'claude_code' => 'claude mcp add --transport http ' . $name . ' ' . $url . ' --header "' . $hdr . '"',
				'cursor'      => (string) wp_json_encode( array( 'mcpServers' => array( $name => $json ) ), $flags ),
				'generic'     => (string) wp_json_encode( array( 'name' => $name, 'transport' => 'streamable-http', 'url' => $url, 'headers' => $json['headers'] ), $flags ),
			),
			'notes'       => $notes,
		);
	}

	public function get_info(): WP_REST_Response {
		return $this->success( Ovp_Fleet::info() );
	}

	/**
	 * `?kind=plugins|themes` returns that one list as {status,syncedAt,error,items:[...]} (the
	 * shape the master's extractItems reads from data.items). Without `kind` both lists are
	 * returned under `plugins` and `themes`. Any other kind is a 400, never an empty "ok".
	 */
	public function get_inventory( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$kind = $request->get_param( 'kind' );
		if ( null === $kind || '' === $kind ) {
			return $this->success( Ovp_Fleet::inventory() );
		}
		if ( 'plugins' === $kind ) {
			return $this->success( array( 'kind' => 'plugins' ) + Ovp_Fleet::inventory_plugins() );
		}
		if ( 'themes' === $kind ) {
			return $this->success( array( 'kind' => 'themes' ) + Ovp_Fleet::inventory_themes() );
		}
		return $this->error( 'ovp_bad_kind', 'kind must be plugins or themes.', 400 );
	}

	public function get_logs(): WP_REST_Response {
		return $this->success( Ovp_Log::recent() );
	}

	public function get_site_info( WP_REST_Request $request ): WP_REST_Response {
		global $wp_version;

		$active_plugins = (array) get_option( 'active_plugins', array() );
		$theme           = wp_get_theme();

		return $this->success(
			array(
				'wordpress_version'    => $wp_version,
				'php_version'          => PHP_VERSION,
				'site_url'             => site_url(),
				'home_url'             => home_url(),
				'is_multisite'         => is_multisite(),
				'permalink_structure'  => get_option( 'permalink_structure' ),
				'active_theme'         => array(
					'slug'    => $theme->get_stylesheet(),
					'name'    => $theme->get( 'Name' ),
					'version' => $theme->get( 'Version' ),
				),
				'active_plugins'       => $active_plugins,
				'timezone_string'      => get_option( 'timezone_string' ),
				'plugin_version'       => OVP_FLEET_VERSION,
				'php_error_log'        => (string) ini_get( 'error_log' ),
				'showing_fatals'       => Ovp_Fatal::is_showing(),
				'last_fatal'           => Ovp_Fatal::last_fatal(),
			)
		);
	}
}
