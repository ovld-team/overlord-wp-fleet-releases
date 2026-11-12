<?php
/**
 * /mcp-bridge/v1/db/* — database access through OVP_Db only (parameterized, tokenized,
 * read-only by default):
 *   db/tables, db/describe          T0
 *   db/select (alias db/query)      T0  single SELECT, %s/%d/%f placeholders + params
 *   db/write                        T2  structured insert/update/delete with snapshot
 *   db/search-replace               T2  serialization-safe, dry-run by default
 *   db/rollback                     T2  undo a db/write or search-replace snapshot
 *   db/exec                         T3  tokenized DML/DDL script
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
require_once dirname( __DIR__ ) . '/class-ovp-db.php';

class Ovp_Module_Db extends Ovp_Module_Base {

	/** Tier per tool, consumed by class-ovp-tiers.php. */
	public const OVP_TIER = array(
		'db_tables'         => 'T0',
		'db_describe'       => 'T0',
		'db_select'         => 'T0',
		'db_write'          => 'T2',
		'db_search_replace' => 'T2',
		'db_rollback'       => 'T2',
		'db_exec'           => 'T3',
	);

	public function register_routes(): void {
		$this->route( 'GET', 'db/tables', array( $this, 'list_tables' ) );
		$this->route( 'GET', 'db/describe', array( $this, 'describe' ) );
		$this->route( 'POST', 'db/select', array( $this, 'select' ) );
		$this->route( 'POST', 'db/query', array( $this, 'select' ) );
		$this->route( 'POST', 'db/write', array( $this, 'write' ) );
		$this->route( 'POST', 'db/search-replace', array( $this, 'search_replace' ) );
		$this->route( 'POST', 'db/rollback', array( $this, 'rollback' ) );
		$this->route( 'POST', 'db/exec', array( $this, 'exec' ) );
	}

	private function tier( WP_REST_Request $request ): string {
		return OVP_Meta::request_tier( $request );
	}

	public function list_tables( WP_REST_Request $request ): WP_REST_Response {
		return $this->success( OVP_Db::tables( $this->tier( $request ) ) );
	}

	public function describe( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$res = OVP_Db::describe( (string) $request->get_param( 'table' ), $this->tier( $request ) );
		return is_wp_error( $res ) ? $res : $this->success( $res );
	}

	/**
	 * Body: { sql, params?: [], max_rows?, cursor? }. SELECT only; placeholders for values.
	 */
	public function select( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$b   = (array) $request->get_json_params();
		$sql = $this->str( $b['sql'] ?? '' );
		if ( '' === trim( $sql ) ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Provide "sql".', 400 );
		}
		$res = OVP_Db::select(
			$sql,
			is_array( $b['params'] ?? null ) ? $b['params'] : array(),
			array( 'tier' => $this->tier( $request ), 'max_rows' => (int) ( $b['max_rows'] ?? 0 ), 'cursor' => (int) ( $b['cursor'] ?? 0 ) )
		);
		return is_wp_error( $res ) ? $res : $this->success( $res );
	}

	/** Body: { op: insert|update|delete, table, data?, where?, dry_run? }. */
	public function write( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$b   = (array) $request->get_json_params();
		$res = OVP_Db::write(
			(string) ( $b['op'] ?? '' ),
			(string) ( $b['table'] ?? '' ),
			(array) ( $b['data'] ?? array() ),
			(array) ( $b['where'] ?? array() ),
			array( 'tier' => $this->tier( $request ), 'dry_run' => ! empty( $b['dry_run'] ) )
		);
		return is_wp_error( $res ) ? $res : $this->success( $res );
	}

	/** Body: { search, replace, tables?, dry_run? (default true), cursor?, max_rows?, include_guid? }. */
	public function search_replace( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$res = OVP_Db::search_replace( (array) $request->get_json_params(), $this->tier( $request ) );
		return is_wp_error( $res ) ? $res : $this->success( $res );
	}

	public function rollback( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$b   = (array) $request->get_json_params();
		$res = OVP_Db::rollback( (string) ( $b['snapshot_id'] ?? '' ), $this->tier( $request ) );
		return is_wp_error( $res ) ? $res : $this->success( $res );
	}

	/** Body: { sql, params?, dry_run? }. */
	public function exec( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$b = (array) $request->get_json_params();
		if ( '' === trim( $this->str( $b['sql'] ?? '' ) ) ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Provide "sql" as a string.', 400 );
		}
		$res = OVP_Db::exec( $this->str( $b['sql'] ), is_array( $b['params'] ?? null ) ? $b['params'] : array(), array( 'tier' => $this->tier( $request ), 'dry_run' => ! empty( $b['dry_run'] ) ) );
		return is_wp_error( $res ) ? $res : $this->success( $res );
	}
}
