<?php
/**
 * Generic chunked upload pipeline the master drives (docs/API.md "Plugin REST the master calls"):
 *
 *   POST upload/begin     {kind:'zip',type:'plugin'|'theme',total_size,sha256}      T2
 *                         {kind:'file',total_size,sha256,dest,rel}                  T3
 *   POST upload/chunk     {upload_id,offset,chunk_sha256,data_b64}                  as begin
 *   POST upload/finalize  {upload_id,activate?}                                     as begin
 *   POST upload/abort     {upload_id}                                               T2
 *
 * Route floor is T2. Everything with real power is delegated to the existing guarded paths:
 * zips to Plugins_Themes (zip-slip/symlink/ratio checks, lint, staged swap, fatal rollback),
 * files to Files (realpath jail, denylists, atomic install). OVP_Upload re-checks the tier
 * stored at begin on every chunk and on finalize, so a T2 caller can never push a T3 file.
 *
 * Responses are flat (upload_id, rolled_back, error_msg, ... at the top level) because the
 * master reads them there; the same object is also under `data` like every other route.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ovp_Module_Upload extends Ovp_Module_Base {

	public function register_routes(): void {
		$this->route( 'POST', 'upload/begin', array( $this, 'begin' ) );
		$this->route( 'POST', 'upload/chunk', array( $this, 'chunk' ) );
		$this->route( 'POST', 'upload/finalize', array( $this, 'finalize' ) );
		$this->route( 'POST', 'upload/abort', array( $this, 'abort' ) );
	}

	/** Re-shape {ok,data:{...}} into {ok,...,data:{...}} so master-side top-level reads work. */
	private function flat( WP_REST_Response|WP_Error $r ): WP_REST_Response|WP_Error {
		if ( $r instanceof WP_Error ) {
			return $r;
		}
		$d = $r->get_data();
		$d = is_array($d) && is_array( $d['data'] ?? null ) ? $d['data'] : array();
		unset( $d['ok'], $d['data'] );
		return new WP_REST_Response( array_merge( array( 'ok' => true ), $d, array( 'data' => $d ) ), $r->get_status() );
	}

	public function begin( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$b    = (array) $request->get_json_params();
		$kind = (string) ( $b['kind'] ?? '' );
		if ( 'zip' === $kind ) {
			$type = (string) ( $b['type'] ?? '' );
			$pt   = new Ovp_Module_Plugins_Themes();
			if ( 'plugin' === $type ) {
				return $this->flat( $pt->upload_begin_plugin( $request ) );
			}
			if ( 'theme' === $type ) {
				return $this->flat( $pt->upload_begin_theme( $request ) );
			}
			return $this->error( 'ovp_upload_invalid', 'type must be plugin or theme.', 400 );
		}
		if ( 'file' === $kind ) {
			$dest = $b['dest'] ?? null;
			if ( ! is_string( $dest ) || '' === $dest || strlen( $dest ) > 500 || 1 === preg_match( '/[\x00-\x1f\x7f]/', $dest ) ) {
				return $this->error( 'ovp_upload_invalid', 'dest (wp-content/<path>) is required.', 400 );
			}
			// Containment: the master sends wp-content/<targetPath> (targetPath is relative to wp-content);
			// anything outside wp-content (wp-config.php, wp-admin, ...) is refused here, not just by the denylists.
			if ( 1 !== preg_match( '#^wp-content/[^/]#i', str_replace( '\\', '/', $dest ) ) ) {
				return $this->error( 'ovp_upload_invalid', 'dest must live under wp-content/ (targetPath is relative to wp-content).', 400 );
			}
			$files = new Ovp_Module_Files();
			return $this->flat( $files->begin_at( $files->from_site_root( $dest ), $b, $request ) );
		}
		return $this->error( 'ovp_upload_invalid', 'kind must be zip or file.', 400 );
	}

	public function chunk( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$b    = (array) $request->get_json_params();
		$raw  = $b['data_b64'] ?? null;
		$data = Ovp_Chunk::decode_b64_strict( $raw );
		if ( null === $data ) {
			return $this->error( 'ovp_chunk_encoding', 'data_b64 must be strict base64.', 400 );
		}
		$res = OVP_Upload::chunk(
			is_string( $b['upload_id'] ?? null ) ? $b['upload_id'] : '',
			is_int( $b['offset'] ?? null ) ? $b['offset'] : -1,
			$data,
			is_string( $b['chunk_sha256'] ?? null ) ? $b['chunk_sha256'] : '',
			OVP_Meta::request_tier( $request )
		);
		return is_wp_error( $res ) ? $res : $this->flat( $this->success( $res ) );
	}

	public function finalize( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$b    = (array) $request->get_json_params();
		$meta = OVP_Upload::meta( is_string( $b['upload_id'] ?? null ) ? $b['upload_id'] : '' );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}
		if ( 'file' === $meta['kind'] ) {
			return $this->flat( ( new Ovp_Module_Files() )->upload_finalize( $request ) );
		}
		$pt = new Ovp_Module_Plugins_Themes();
		if ( 'plugin' === ( $meta['type'] ?? '' ) ) {
			return $this->flat( $pt->upload_finalize_plugin( $request ) );
		}
		if ( 'theme' === ( $meta['type'] ?? '' ) ) {
			return $this->flat( $pt->upload_finalize_theme( $request ) );
		}
		return $this->error( 'ovp_upload_invalid', 'Unknown upload type.', 400 );
	}

	public function abort( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$b = (array) $request->get_json_params();
		return OVP_Upload::abort( is_string( $b['upload_id'] ?? null ) ? $b['upload_id'] : '' )
			? $this->flat( $this->success( array( 'aborted' => true ) ) )
			: $this->error( 'ovp_upload_unknown', 'Unknown upload.', 404 );
	}
}
