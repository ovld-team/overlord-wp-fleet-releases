<?php
/**
 * /ovp/v1/blocks/* (2.1.0): Gutenberg helpers. parse, validate and patterns are read-only (T0);
 * insert is T1 and writes through the same OVP_Meta commit path as apply/gutenberg (adapter
 * "gutenberg": block markup is checked first and the old content is snapshotted for rollback).
 *
 * WordPress block functions and registries are reached only through function_exists() /
 * class_exists() guards, so a site or a test without them answers a clean error instead of a fatal.
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

class Ovp_Module_Blocks extends Ovp_Module_Base {

	/** Tier per tool, consumed by the route-table test. */
	public const OVP_TIER = array(
		'gutenberg_parse'        => 'T0',
		'gutenberg_validate'     => 'T0',
		'gutenberg_patterns'     => 'T0',
		'gutenberg_insert_block' => 'T1',
	);

	private const MAX_BYTES = 4194304;
	private const MAX_NODES = 2000;
	private const MAX_DEPTH = 20;

	/** Attributes WordPress adds to every block outside the registered attribute list. */
	private const GENERIC_ATTRS = array( 'lock', 'metadata', 'className', 'anchor' );

	public function register_routes(): void {
		$this->route( 'POST', 'blocks/parse', array( $this, 'parse' ) );
		$this->route( 'POST', 'blocks/validate', array( $this, 'validate' ) );
		$this->route( 'GET', 'blocks/patterns', array( $this, 'patterns' ) );
		$this->route( 'POST', 'blocks/insert', array( $this, 'insert' ) );
	}

	private function gate( WP_REST_Request $request, string $need ): ?WP_Error {
		if ( OVP_Upload::tier_rank( OVP_Meta::request_tier( $request ) ) < OVP_Upload::tier_rank( $need ) ) {
			return $this->error( 'ovp_tier_denied', 'Tier ' . $need . ' required.', 403 );
		}
		return null;
	}

	private function unavailable(): ?WP_Error {
		return function_exists( 'parse_blocks' ) ? null : $this->error( 'ovp_blocks_unavailable', 'This WordPress has no block parser.', 501 );
	}

	/** Markup from body.content, or from the post given by body.post_id (never a password-protected one). */
	private function source( array $body ): string|WP_Error {
		if ( isset( $body['content'] ) ) {
			if ( ! is_string( $body['content'] ) || strlen( $body['content'] ) > self::MAX_BYTES ) {
				return $this->error( 'mcp_bridge_invalid_payload', 'content must be a string up to 4MB.', 400 );
			}
			return $body['content'];
		}
		if ( isset( $body['post_id'] ) && is_int( $body['post_id'] ) && $body['post_id'] > 0 && function_exists( 'get_post' ) ) {
			$p = get_post( $body['post_id'] );
			if ( ! is_object( $p ) ) {
				return $this->error( 'mcp_bridge_not_found', 'No such post.', 404 );
			}
			if ( '' !== (string) ( $p->post_password ?? '' ) ) {
				return $this->error( 'ovp_post_protected', 'The post is password-protected.', 403 );
			}
			return (string) $p->post_content;
		}
		return $this->error( 'mcp_bridge_invalid_payload', 'Provide "content" (block markup) or "post_id".', 400 );
	}

	/** True for the empty freeform block the parser puts between real blocks. */
	private static function is_filler( array $b ): bool {
		return null === ( $b['blockName'] ?? null ) && '' === trim( (string) ( $b['innerHTML'] ?? '' ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $blocks
	 * @return list<array<string,mixed>>
	 */
	private function tree( array $blocks, int $depth, int &$nodes, bool &$truncated ): array {
		$out = array();
		foreach ( $blocks as $b ) {
			if ( self::is_filler( $b ) ) {
				continue;
			}
			if ( $nodes >= self::MAX_NODES || $depth > self::MAX_DEPTH ) {
				$truncated = true;
				break;
			}
			++$nodes;
			$html  = (string) ( $b['innerHTML'] ?? '' );
			$out[] = array(
				'name'  => $b['blockName'] ?? null,
				'attrs' => is_array( $b['attrs'] ?? null ) ? $b['attrs'] : (object) array(),
				'html'  => strlen( $html ) > 500 ? substr( $html, 0, 500 ) . '...' : $html,
				'inner' => $this->tree( is_array( $b['innerBlocks'] ?? null ) ? $b['innerBlocks'] : array(), $depth + 1, $nodes, $truncated ),
			);
		}
		return $out;
	}

	/** Body: content | post_id. Returns the block tree (innerHTML cut at 500 bytes, at most 2000 blocks). */
	public function parse( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$na = $this->unavailable();
		if ( null !== $na ) {
			return $na;
		}
		$src = $this->source( (array) $request->get_json_params() );
		if ( $src instanceof WP_Error ) {
			return $src;
		}
		$nodes     = 0;
		$truncated = false;
		$tree      = $this->tree( parse_blocks( $src ), 0, $nodes, $truncated );
		return $this->success( array( 'blocks' => $tree, 'count' => $nodes, 'truncated' => $truncated ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $blocks
	 * @param list<string>                   $errors
	 * @param list<string>                   $warnings
	 */
	private function check_tree( array $blocks, array &$errors, array &$warnings, int &$seen ): void {
		$reg = WP_Block_Type_Registry::get_instance();
		foreach ( $blocks as $b ) {
			if ( self::is_filler( $b ) ) {
				continue;
			}
			if ( ++$seen > self::MAX_NODES ) {
				$warnings[] = 'Stopped after ' . self::MAX_NODES . ' blocks.';
				return;
			}
			$name = $b['blockName'] ?? null;
			if ( is_string( $name ) ) {
				$type = $reg->get_registered( $name );
				if ( null === $type ) {
					$errors[] = 'Block type not registered: ' . $name;
				} elseif ( is_array( $b['attrs'] ?? null ) && is_array( $type->attributes ?? null ) ) {
					foreach ( array_keys( $b['attrs'] ) as $attr ) {
						if ( ! array_key_exists( $attr, $type->attributes ) && ! in_array( $attr, self::GENERIC_ATTRS, true ) ) {
							$warnings[] = $name . ': unknown attribute ' . $attr;
						}
					}
				}
			} else {
				$warnings[] = 'Freeform (classic) content found outside any block.';
			}
			$this->check_tree( is_array( $b['innerBlocks'] ?? null ) ? $b['innerBlocks'] : array(), $errors, $warnings, $seen );
		}
	}

	/** Body: content | post_id. Structure check plus every block name against the registered block types. */
	public function validate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$na = $this->unavailable();
		if ( null !== $na ) {
			return $na;
		}
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			return $this->error( 'ovp_blocks_unavailable', 'This WordPress has no block type registry.', 501 );
		}
		$src = $this->source( (array) $request->get_json_params() );
		if ( $src instanceof WP_Error ) {
			return $src;
		}
		OVP_Meta::adapters();
		$errors   = array();
		$warnings = array();
		$seen     = 0;
		$struct   = OVP_Adapter_Gutenberg::check_blocks( $src );
		if ( null !== $struct ) {
			$errors[] = $struct;
		}
		$this->check_tree( parse_blocks( $src ), $errors, $warnings, $seen );
		$errors   = array_slice( array_values( array_unique( $errors ) ), 0, 100 );
		$warnings = array_slice( array_values( array_unique( $warnings ) ), 0, 100 );
		return $this->success( array( 'valid' => array() === $errors, 'errors' => $errors, 'warnings' => $warnings, 'blocks_checked' => $seen ) );
	}

	/** Query: search, category, limit (1-100, default 50), include_content (true: content cut at 5000 bytes). */
	public function patterns( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! class_exists( 'WP_Block_Patterns_Registry' ) ) {
			return $this->error( 'ovp_blocks_unavailable', 'This WordPress has no block pattern registry.', 501 );
		}
		$search  = strtolower( $this->str( $request->get_param( 'search' ) ) );
		$cat     = $this->str( $request->get_param( 'category' ) );
		$limit   = max( 1, min( 100, (int) ( $request->get_param( 'limit' ) ?: 50 ) ) );
		$content = in_array( $request->get_param( 'include_content' ), array( true, 1, '1', 'true' ), true );
		$out     = array();
		$total   = 0;
		foreach ( WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $p ) {
			$name  = (string) ( $p['name'] ?? '' );
			$title = (string) ( $p['title'] ?? '' );
			$cats  = is_array( $p['categories'] ?? null ) ? array_map( 'strval', $p['categories'] ) : array();
			if ( '' !== $cat && ! in_array( $cat, $cats, true ) ) {
				continue;
			}
			if ( '' !== $search && ! str_contains( strtolower( $name . ' ' . $title . ' ' . (string) ( $p['description'] ?? '' ) ), $search ) ) {
				continue;
			}
			++$total;
			if ( count( $out ) >= $limit ) {
				continue;
			}
			$row = array( 'name' => $name, 'title' => $title, 'description' => (string) ( $p['description'] ?? '' ), 'categories' => $cats );
			if ( $content ) {
				$row['content'] = substr( (string) ( $p['content'] ?? '' ), 0, 5000 );
			}
			$out[] = $row;
		}
		return $this->success( array( 'patterns' => $out, 'total' => $total, 'returned' => count( $out ) ) );
	}

	/**
	 * Body: post_id, content (block markup to insert), position start|end|<top-level index> (default end), dry_run.
	 * The merged markup goes through OVP_Meta::commit with the gutenberg adapter, like apply/gutenberg.
	 */
	public function insert( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->gate( $request, 'T1' );
		if ( null !== $gate ) {
			return $gate;
		}
		$na = $this->unavailable();
		if ( null !== $na ) {
			return $na;
		}
		if ( ! function_exists( 'serialize_blocks' ) || ! function_exists( 'get_post' ) ) {
			return $this->error( 'ovp_blocks_unavailable', 'This WordPress has no block serializer.', 501 );
		}
		$body = (array) $request->get_json_params();
		$id   = isset( $body['post_id'] ) && is_int( $body['post_id'] ) ? $body['post_id'] : 0;
		$new  = $body['content'] ?? null;
		if ( $id < 1 || ! is_string( $new ) || '' === trim( $new ) || strlen( $new ) > self::MAX_BYTES ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Provide post_id and "content" (the block markup to insert, up to 4MB).', 400 );
		}
		$post = get_post( $id );
		if ( ! is_object( $post ) || 'wp_global_styles' === (string) $post->post_type ) {
			return $this->error( 'mcp_bridge_not_found', 'No such editable post.', 404 );
		}
		OVP_Meta::adapters();
		$check = OVP_Adapter_Gutenberg::check_blocks( $new );
		if ( null !== $check ) {
			return $this->error( 'ovp_adapter_invalid', $check, 422 );
		}
		$add = array_values( array_filter( parse_blocks( $new ), static fn( $b ): bool => is_array( $b ) && ! self::is_filler( $b ) ) );
		if ( array() === $add ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'content holds no block.', 400 );
		}
		// Keep the existing array untouched (fillers and classic content included); only map the caller's index
		// (counted over real blocks) onto it, so inserting never rewrites the spacing of the rest of the post.
		$have = array_values( array_filter( parse_blocks( (string) $post->post_content ), static fn( $b ): bool => is_array( $b ) ) );
		$real = array();
		foreach ( $have as $i => $b ) {
			if ( ! self::is_filler( $b ) ) {
				$real[] = $i;
			}
		}
		$pos = $body['position'] ?? 'end';
		if ( 'start' === $pos ) {
			$at = 0;
		} elseif ( 'end' === $pos ) {
			$at = count( $have );
		} elseif ( is_int( $pos ) && $pos >= 0 && $pos <= count( $real ) ) {
			$at = $pos < count( $real ) ? $real[ $pos ] : count( $have );
		} else {
			return $this->error( 'mcp_bridge_invalid_payload', 'position must be start, end or an index 0-' . count( $real ) . '.', 400 );
		}
		array_splice( $have, $at, 0, $add );
		$merged = serialize_blocks( $have );

		$res = OVP_Meta::commit(
			'post',
			$id,
			'@post_content',
			$merged,
			array(
				'tier'    => OVP_Meta::request_tier( $request ),
				'adapter' => 'gutenberg',
				'dry_run' => ! empty( $body['dry_run'] ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return $this->success( array( 'post_id' => $id, 'inserted' => count( $add ), 'position' => 'start' === $pos ? 0 : ( 'end' === $pos ? count( $real ) : $pos ), 'snapshot_id' => $res['snapshot_id'] ?? null, 'dry_run' => ! empty( $body['dry_run'] ) ) );
	}
}
