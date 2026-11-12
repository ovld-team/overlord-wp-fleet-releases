<?php
/**
 * /mcp-bridge/v1/options/* — wp_options read/write through the generic meta layer
 * (OVP_Meta, type "option"): denylist (secrets, auth keys, the plugin's own ovp_* /
 * mcp_bridge_* options), tier by key (T2 normal, T3 structural like siteurl/home/
 * active_plugins), snapshot before every write, optimistic concurrency via expected_hash,
 * values decoded with allowed_classes=false.
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

class Ovp_Module_Options extends Ovp_Module_Base {

	/** Tier per tool; set/delete escalate to T3 for structural keys (see OVP_Meta::required_tier). */
	public const OVP_TIER = array(
		'options_get'    => 'T0',
		'options_keys'   => 'T0',
		'options_set'    => 'T2',
		'options_delete' => 'T2',
	);

	public function register_routes(): void {
		$this->route( 'GET', 'options/(?P<name>[a-zA-Z0-9_\-.:\/]+)', array( $this, 'get_option_value' ) );
		$this->route( 'POST', 'options/(?P<name>[a-zA-Z0-9_\-.:\/]+)', array( $this, 'set_option_value' ) );
		$this->route( 'DELETE', 'options/(?P<name>[a-zA-Z0-9_\-.:\/]+)', array( $this, 'delete_option_value' ) );
		$this->route( 'GET', 'options', array( $this, 'list_options' ) );
	}

	public function list_options( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$res = OVP_Meta::keys( 'option', 0, array( 'prefix' => (string) $request->get_param( 'prefix' ), 'limit' => (int) ( $request->get_param( 'limit' ) ?: 200 ) ) );
		return is_wp_error( $res ) ? $res : $this->success( $res );
	}

	public function get_option_value( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$res = OVP_Meta::get(
			'option',
			0,
			(string) $request->get_param( 'name' ),
			array(
				'tier'      => OVP_Meta::request_tier( $request ),
				'offset'    => (int) $request->get_param( 'offset' ),
				'max_bytes' => (int) $request->get_param( 'max_bytes' ),
			)
		);
		return is_wp_error( $res ) ? $res : $this->success( $res );
	}

	public function set_option_value( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$name = (string) $request->get_param( 'name' );
		$body = (array) $request->get_json_params();
		if ( ! array_key_exists( 'value', $body ) ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Provide "value".', 400 );
		}
		$res = OVP_Meta::commit(
			'option',
			0,
			$name,
			$body['value'],
			array(
				'tier'          => OVP_Meta::request_tier( $request ),
				'expected_hash' => (string) ( $body['expected_hash'] ?? '' ),
				'change_hash'   => (string) ( $body['change_hash'] ?? '' ),
				'dry_run'       => ! empty( $body['dry_run'] ),
				'as'            => isset( $body['as'] ) ? (string) $body['as'] : null,
			)
		);
		return is_wp_error( $res ) ? $res : $this->success( $res );
	}

	public function delete_option_value( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = (array) $request->get_json_params();
		$res  = OVP_Meta::delete(
			'option',
			0,
			(string) $request->get_param( 'name' ),
			array(
				'tier'          => OVP_Meta::request_tier( $request ),
				'expected_hash' => (string) ( $body['expected_hash'] ?? $request->get_param( 'expected_hash' ) ?? '' ),
			)
		);
		return is_wp_error( $res ) ? $res : $this->success( $res );
	}
}
