<?php
/**
 * /ovp/v1/cron/* — list, trigger, and delete WP-Cron events.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ovp_Module_Cron extends Ovp_Module_Base {

	public function register_routes(): void {
		$this->route( 'GET', 'cron', array( $this, 'list_events' ) );
		$this->route( 'POST', 'cron/run', array( $this, 'run_event' ) );
		$this->route( 'DELETE', 'cron', array( $this, 'delete_event' ) );
	}

	public function list_events(): WP_REST_Response {
		$crons  = _get_cron_array() ?: array();
		$shaped = array();

		foreach ( $crons as $timestamp => $hooks ) {
			foreach ( $hooks as $hook => $events ) {
				foreach ( $events as $sig => $event ) {
					$shaped[] = array(
						'hook'      => $hook,
						'timestamp' => $timestamp,
						'schedule'  => $event['schedule'] ?? null,
						'args'      => $event['args'] ?? array(),
					);
				}
			}
		}

		return $this->success( $shaped );
	}

	public function run_event( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = (array) $request->get_json_params();
		$hook = (string) ( $body['hook'] ?? '' );

		if ( '' === $hook ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Provide "hook".', 400 );
		}

		// Only hooks that are currently scheduled, with their stored args. Anything else (arbitrary
		// action hooks such as wp_ajax_* / admin_post_*, caller-chosen args) is T3.
		$stored = null;
		$want   = array_key_exists( 'args', $body ) ? (array) $body['args'] : null;
		foreach ( ( _get_cron_array() ?: array() ) as $hooks ) {
			foreach ( (array) ( $hooks[ $hook ] ?? array() ) as $event ) {
				$eargs = (array) ( $event['args'] ?? array() );
				if ( null === $stored || ( null !== $want && $eargs === $want ) ) {
					$stored = $eargs;
				}
			}
		}
		if ( null === $stored ) {
			if ( OVP_Upload::tier_rank( OVP_Meta::request_tier( $request ) ) < OVP_Upload::tier_rank( 'T3' ) ) {
				return $this->error( 'ovp_tier_denied', 'Tier T3 required to run a hook that is not scheduled.', 403 );
			}
			$stored = (array) ( $body['args'] ?? array() );
		}

		do_action_ref_array( $hook, $stored );

		return $this->success( array( 'ran' => $hook ) );
	}

	public function delete_event( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body      = (array) $request->get_json_params();
		$hook      = (string) ( $body['hook'] ?? '' );
		$timestamp = isset( $body['timestamp'] ) ? (int) $body['timestamp'] : null;
		$args      = (array) ( $body['args'] ?? array() );

		if ( '' === $hook ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Provide "hook".', 400 );
		}

		if ( null !== $timestamp ) {
			wp_unschedule_event( $timestamp, $hook, $args );
		} else {
			wp_clear_scheduled_hook( $hook, $args );
		}

		return $this->success( array( 'deleted' => $hook ) );
	}
}
