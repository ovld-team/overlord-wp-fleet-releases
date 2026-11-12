<?php
/**
 * GET /ovp/v1/updates — pending core/plugin/theme updates from site transients.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ovp_Module_Updates extends Ovp_Module_Base {

	public function register_routes(): void {
		$this->route(
			'GET',
			'updates',
			array( $this, 'get_updates' ),
			array(
				'args' => array(
					'refresh' => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			)
		);
	}

	public function get_updates( WP_REST_Request $request ): WP_REST_Response {
		$refresh = (int) $request->get_param( 'refresh' );
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'wp_update_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		if ( $refresh > 0 ) {
			wp_update_plugins();
			wp_update_themes();
			wp_version_check();
		}

		$all_plugins = get_plugins();
		$auto_plugins = (array) get_site_option( 'auto_update_plugins', array() );
		$plugin_t     = get_site_transient( 'update_plugins' );
		$plugin_resp  = ( is_object( $plugin_t ) && isset( $plugin_t->response ) && is_array( $plugin_t->response ) )
			? $plugin_t->response
			: array();

		$plugins = array();
		foreach ( $all_plugins as $file => $data ) {
			$update      = $plugin_resp[ $file ] ?? null;
			$new_version = ( is_object( $update ) && isset( $update->new_version ) ) ? (string) $update->new_version : null;
			$slug        = ( is_object( $update ) && isset( $update->slug ) ) ? (string) $update->slug : dirname( $file );
			if ( '.' === $slug ) {
				$slug = basename( $file, '.php' );
			}
			$plugins[] = array(
				'file'              => $file,
				'slug'              => $slug,
				'version'           => (string) ( $data['Version'] ?? '' ),
				'new_version'       => $new_version,
				'update_available'  => is_string( $new_version ) && '' !== $new_version,
				'auto_update'       => in_array( $file, $auto_plugins, true ),
			);
		}

		$all_themes  = wp_get_themes();
		$auto_themes = (array) get_site_option( 'auto_update_themes', array() );
		$theme_t     = get_site_transient( 'update_themes' );
		$theme_resp  = ( is_object( $theme_t ) && isset( $theme_t->response ) && is_array( $theme_t->response ) )
			? $theme_t->response
			: array();

		$themes = array();
		foreach ( $all_themes as $theme ) {
			$stylesheet  = $theme->get_stylesheet();
			$tupdate     = $theme_resp[ $stylesheet ] ?? null;
			$new_version = null;
			if ( is_array( $tupdate ) && isset( $tupdate['new_version'] ) ) {
				$new_version = (string) $tupdate['new_version'];
			} elseif ( is_object( $tupdate ) && isset( $tupdate->new_version ) ) {
				$new_version = (string) $tupdate->new_version;
			}
			$themes[] = array(
				'stylesheet'       => $stylesheet,
				'slug'             => $stylesheet,
				'version'          => (string) $theme->get( 'Version' ),
				'new_version'      => $new_version,
				'update_available' => is_string( $new_version ) && '' !== $new_version,
				'auto_update'      => in_array( $stylesheet, $auto_themes, true ),
			);
		}

		global $wp_version;
		$current = (string) $wp_version;
		$latest  = $current;
		$core_t  = get_site_transient( 'update_core' );
		if ( is_object( $core_t ) && isset( $core_t->updates ) && is_array( $core_t->updates ) ) {
			foreach ( $core_t->updates as $update ) {
				$response = '';
				$offered  = '';
				if ( is_object( $update ) ) {
					$response = isset( $update->response ) ? (string) $update->response : '';
					$offered  = isset( $update->current ) ? (string) $update->current : '';
				}
				if ( 'upgrade' === $response && '' !== $offered ) {
					$latest = $offered;
					break;
				}
			}
		}

		$plugin_count = 0;
		foreach ( $plugins as $row ) {
			if ( $row['update_available'] ) {
				++$plugin_count;
			}
		}
		$theme_count = 0;
		foreach ( $themes as $row ) {
			if ( $row['update_available'] ) {
				++$theme_count;
			}
		}
		$core_available = version_compare( $latest, $current, '>' );

		return $this->success(
			array(
				'checked_at' => time(),
				'core'       => array(
					'current'          => $current,
					'latest'           => $latest,
					'update_available' => $core_available,
				),
				'plugins'    => $plugins,
				'themes'     => $themes,
				'counts'     => array(
					'core'    => $core_available ? 1 : 0,
					'plugins' => $plugin_count,
					'themes'  => $theme_count,
				),
			)
		);
	}
}
