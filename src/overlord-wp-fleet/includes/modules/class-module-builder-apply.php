<?php
/**
 * /mcp-bridge/v1/apply/* — the "close the loop" module. Accepts the exact artifacts
 * wordpress-mode already produces (outputs/<slug>/elementor.json content array,
 * gutenberg-content.html, wpbakery-content.txt, wxr.xml, sql/insert.sql) and applies
 * them directly to this site, instead of the human hand-importing a file.
 *
 * Meta-key contracts here intentionally mirror .agents/skills/wp-sql-meta/SKILL.md and
 * .agents/skills/wxr-export/SKILL.md exactly — keep the three in sync.
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

class Ovp_Module_Builder_Apply extends Ovp_Module_Base {

	/** Tier per tool, consumed by class-ovp-tiers.php. */
	public const OVP_TIER = array(
		'apply_elementor' => 'T1',
		'apply_gutenberg' => 'T1',
		'apply_wpbakery'  => 'T1',
		'apply_wxr'       => 'T3',
		'apply_sql'       => 'T3',
	);

	private const WXR_MAX_BYTES = 8388608;
	private const WXR_MAX_ITEMS = 500;

	/** Fail-closed tier gate (central gate lives in OVP_Tiers; this is defense in depth). */
	private function gate( WP_REST_Request $request, string $need ): ?WP_Error {
		if ( OVP_Upload::tier_rank( OVP_Meta::request_tier( $request ) ) < OVP_Upload::tier_rank( $need ) ) {
			return $this->error( 'ovp_tier_denied', 'Tier ' . $need . ' required.', 403 );
		}
		return null;
	}

	public function register_routes(): void {
		$this->route( 'POST', 'apply/elementor', array( $this, 'apply_elementor' ) );
		$this->route( 'POST', 'apply/gutenberg', array( $this, 'apply_gutenberg' ) );
		$this->route( 'POST', 'apply/wpbakery', array( $this, 'apply_wpbakery' ) );
		$this->route( 'POST', 'apply/wxr', array( $this, 'apply_wxr' ) );
		$this->route( 'POST', 'apply/sql', array( $this, 'apply_sql' ) );
	}

	/**
	 * Body: { post_id?, post_title, post_status?, post_type?, elementor_data
	 * (raw `_elementor_data` JSON string — required when the tree has `__dynamic__` —
	 * or a content array with no `__dynamic__`), elementor_version?, page_template? }
	 *
	 * Never `wp_json_encode` a tree that contains `__dynamic__` (kills tag bytes).
	 * Never rewrite `_elementor_page_settings` (PHP serialize `hide_title=yes`).
	 */
	public function apply_elementor( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->gate( $request, 'T1' );
		if ( null !== $gate ) {
			return $gate;
		}
		$body = (array) $request->get_json_params();
		$raw  = $this->normalize_elementor_data( $body['elementor_data'] ?? null );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		if ( strlen( $raw ) > 4 * 1024 * 1024 ) {
			return $this->error( 'mcp_bridge_file_too_large', 'elementor_data cap is 4MB. Split the tree; never hex-CONCAT _elementor_data.', 413 );
		}

		$existing = ! empty( $body['post_id'] );
		$post_id  = $this->upsert_post( $body, '', false );
		if ( is_wp_error( $post_id ) ) {
			return $this->error( 'mcp_bridge_upsert_failed', $post_id->get_error_message(), 400 );
		}

		$tier    = OVP_Meta::request_tier( $request );
		$version = (string) ( $body['elementor_version'] ?? '4.2.0' );
		$writes  = array(
			'_elementor_data'          => $raw,
			'_elementor_edit_mode'     => 'builder',
			'_elementor_template_type' => (string) ( $body['elementor_template_type'] ?? 'wp-page' ),
			'_elementor_version'       => $version,
		);
		if ( isset( $body['page_template'] ) ) {
			$writes['_wp_page_template'] = (string) $body['page_template'];
		}
		$snapshots = array();
		foreach ( $writes as $key => $value ) {
			$res = OVP_Meta::commit( 'post', $post_id, $key, $value, array( 'tier' => $tier ) );
			if ( is_wp_error( $res ) ) {
				if ( ! $existing ) {
					wp_delete_post( $post_id, true );
				}
				return $res;
			}
			if ( ! empty( $res['snapshot_id'] ) ) {
				$snapshots[ $key ] = $res['snapshot_id'];
			}
		}

		return $this->success( $this->applied_result( $post_id ) + array( 'snapshots' => $snapshots ) );
	}

	/**
	 * Body: { post_id?, post_title, post_status?, post_type?, content (block markup
	 * string), page_template? }
	 */
	public function apply_gutenberg( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->apply_content( $request, 'gutenberg' );
	}

	/**
	 * Body: { post_id?, post_title, post_status?, post_type?, content (shortcode
	 * markup string), page_template? } — same shape as Gutenberg, different content model.
	 */
	public function apply_wpbakery( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->apply_content( $request, 'wpbakery' );
	}

	/**
	 * Shared by gutenberg/wpbakery. Markup is validated by the adapter BEFORE the post is
	 * created or touched; an existing post's content is snapshotted for rollback.
	 */
	private function apply_content( WP_REST_Request $request, string $adapter ): WP_REST_Response|WP_Error {
		$gate = $this->gate( $request, 'T1' );
		if ( null !== $gate ) {
			return $gate;
		}
		$body = (array) $request->get_json_params();

		if ( empty( $body['content'] ) || ! is_string( $body['content'] ) ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Provide "content" as the serialized block markup string.', 400 );
		}
		OVP_Meta::adapters();
		$check = 'wpbakery' === $adapter ? OVP_Adapter_WPBakery::check_shortcodes( $body['content'] ) : OVP_Adapter_Gutenberg::check_blocks( $body['content'] );
		if ( null !== $check ) {
			return $this->error( 'ovp_adapter_invalid', $check, 422 );
		}

		$snapshot = null;
		if ( ! empty( $body['post_id'] ) ) {
			$res = OVP_Meta::commit( 'post', (int) $body['post_id'], '@post_content', $body['content'], array( 'tier' => OVP_Meta::request_tier( $request ), 'adapter' => $adapter ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$snapshot = $res['snapshot_id'] ?? null;
			$post_id  = $this->upsert_post( $body, '', false );
		} else {
			$post_id = $this->upsert_post( $body, (string) $body['content'], true );
		}
		if ( is_wp_error( $post_id ) ) {
			return $this->error( 'mcp_bridge_upsert_failed', $post_id->get_error_message(), 400 );
		}

		if ( isset( $body['page_template'] ) ) {
			OVP_Meta::commit( 'post', $post_id, '_wp_page_template', (string) $body['page_template'], array( 'tier' => OVP_Meta::request_tier( $request ) ) );
		}

		return $this->success( $this->applied_result( $post_id ) + array( 'snapshot_id' => $snapshot ) );
	}

	/**
	 * Body: { wxr_xml: string }. Minimal WXR importer scoped to this project's own
	 * export shape (wxr-export SKILL.md) — one or more <item>, optional <wp:postmeta>.
	 * Not a general-purpose replacement for the core WordPress Importer plugin.
	 * DOCTYPE/entities are refused (XXE, billion laughs); meta goes through OVP_Meta.
	 */
	public function apply_wxr( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->gate( $request, 'T3' );
		if ( null !== $gate ) {
			return $gate;
		}
		$body = (array) $request->get_json_params();
		$xml  = (string) ( $body['wxr_xml'] ?? '' );

		if ( '' === $xml ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Provide "wxr_xml".', 400 );
		}
		if ( strlen( $xml ) > self::WXR_MAX_BYTES ) {
			return $this->error( 'mcp_bridge_file_too_large', 'wxr_xml cap is 8MB.', 413 );
		}
		if ( false !== stripos( $xml, '<!DOCTYPE' ) || false !== stripos( $xml, '<!ENTITY' ) ) {
			return $this->error( 'mcp_bridge_invalid_xml', 'DOCTYPE and entity declarations are not allowed.', 400 );
		}

		$previous = libxml_use_internal_errors( true );
		$doc      = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
		libxml_use_internal_errors( $previous );

		if ( false === $doc || ! isset( $doc->channel ) ) {
			return $this->error( 'mcp_bridge_invalid_xml', 'Could not parse wxr_xml.', 400 );
		}

		$ns = $doc->getNamespaces( true );
		$wp = $ns['wp'] ?? 'http://wordpress.org/export/1.2/';

		$tier    = OVP_Meta::request_tier( $request );
		$applied = array();
		$skipped = array();
		$count   = 0;
		foreach ( $doc->channel->item as $item ) {
			if ( ++$count > self::WXR_MAX_ITEMS ) {
				return $this->error( 'mcp_bridge_file_too_large', 'WXR import cap is ' . self::WXR_MAX_ITEMS . ' items.', 413 );
			}
			$wp_fields    = $item->children( $wp );
			$post_type    = sanitize_key( (string) $wp_fields->post_type );
			$post_name    = sanitize_title( (string) $wp_fields->post_name );
			$title        = (string) $item->title;
			$content_node = $item->children( 'http://purl.org/rss/1.0/modules/content/' );
			$content      = (string) ( $content_node->encoded ?? '' );
			$status       = sanitize_key( (string) $wp_fields->status ) ?: 'publish';

			$existing = get_page_by_path( $post_name, OBJECT, $post_type ?: 'page' );
			$postarr  = array(
				'post_title'   => $title,
				'post_name'    => $post_name,
				'post_content' => $content,
				'post_status'  => $status,
				'post_type'    => $post_type ?: 'page',
			);

			if ( $existing ) {
				$postarr['ID'] = $existing->ID;
				$post_id       = wp_update_post( $postarr, true );
			} else {
				$post_id = wp_insert_post( $postarr, true );
			}

			if ( is_wp_error( $post_id ) ) {
				continue;
			}

			foreach ( $wp_fields->postmeta as $meta ) {
				$key   = (string) $meta->meta_key;
				$value = (string) $meta->meta_value;
				if ( '' === $key ) {
					continue;
				}
				// WXR carries DB-form values. Decode serialized safely (no objects) and
				// write the native value so WP serializes once.
				if ( OVP_Meta::looks_serialized( $value ) ) {
					$dec = OVP_Meta::safe_unserialize( $value );
					if ( ! $dec['ok'] || $dec['objects'] ) {
						$skipped[] = array( 'post_id' => $post_id, 'key' => $key, 'reason' => 'serialized_objects_or_invalid' );
						continue;
					}
					$value = $dec['value'];
				}
				$res = OVP_Meta::commit( 'post', (int) $post_id, $key, $value, array( 'tier' => $tier ) );
				if ( is_wp_error( $res ) ) {
					$skipped[] = array( 'post_id' => $post_id, 'key' => $key, 'reason' => $res->get_error_code() );
				}
			}

			$applied[] = $this->applied_result( (int) $post_id );
		}

		return $this->success( array( 'applied' => $applied, 'skipped_meta' => $skipped ) );
	}

	/**
	 * Body: { sql: string }. HIGH RISK (T3). Statements are split by the SQL tokenizer
	 * (a ; inside a string no longer breaks the script), restricted to DML/DDL on
	 * {prefix} tables, never touch plugin-owned keys, and a DML-only script runs in one
	 * transaction (all-or-nothing).
	 */
	public function apply_sql( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = (array) $request->get_json_params();
		$sql  = (string) ( $body['sql'] ?? '' );

		if ( '' === trim( $sql ) ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Provide "sql".', 400 );
		}
		if ( strlen( $sql ) > 2 * 1024 * 1024 ) {
			return $this->error( 'mcp_bridge_file_too_large', 'apply_sql cap is 2MB.', 413 );
		}
		$res = OVP_Db::exec( $sql, array(), array( 'tier' => OVP_Meta::request_tier( $request ), 'dry_run' => ! empty( $body['dry_run'] ) ) );
		return is_wp_error( $res ) ? $res : $this->success( $res );
	}

	/**
	 * Raw `_elementor_data` JSON string, or WP_Error.
	 *
	 * String path: store bytes as sent (after JSON-body unescape) via `wp_slash`.
	 * Array path: only trees with no `__dynamic__` — new generated pages.
	 *
	 * @param mixed $data
	 */
	private function normalize_elementor_data( mixed $data ): string|WP_Error {
		if ( is_string( $data ) ) {
			$raw = trim( $data );
			if ( '' === $raw ) {
				return $this->error( 'mcp_bridge_invalid_payload', 'elementor_data string is empty.', 400 );
			}
			$first = $raw[0];
			if ( '[' !== $first && '{' !== $first ) {
				return $this->error( 'mcp_bridge_invalid_payload', 'elementor_data string must be raw _elementor_data JSON (array or object).', 400 );
			}
			json_decode( $raw );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return $this->error( 'mcp_bridge_invalid_payload', 'elementor_data string is not valid JSON.', 400 );
			}
			return $raw;
		}

		if ( is_array( $data ) ) {
			if ( $this->elementor_tree_has_dynamic( $data ) ) {
				return $this->error(
					'mcp_bridge_invalid_payload',
					'elementor_data array contains __dynamic__. Pass the raw _elementor_data JSON string instead — wp_json_encode kills dynamic tags.',
					400
				);
			}
			$encoded = wp_json_encode( $data );
			if ( ! is_string( $encoded ) || '' === $encoded ) {
				return $this->error( 'mcp_bridge_invalid_payload', 'Could not encode elementor_data array.', 400 );
			}
			return $encoded;
		}

		return $this->error(
			'mcp_bridge_invalid_payload',
			'Provide elementor_data as a raw _elementor_data JSON string (required when the tree has __dynamic__). Array only allowed for new trees without __dynamic__.',
			400
		);
	}

	/**
	 * @param mixed $node
	 */
	private function elementor_tree_has_dynamic( mixed $node ): bool {
		if ( ! is_array( $node ) ) {
			return false;
		}
		if ( array_key_exists( '__dynamic__', $node ) ) {
			return true;
		}
		foreach ( $node as $child ) {
			if ( $this->elementor_tree_has_dynamic( $child ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Shared create-or-update for the three page-content apply endpoints. When $with_content is
	 * false the existing post_content is left alone (it was written via OVP_Meta).
	 *
	 * @param array<string, mixed> $body
	 */
	private function upsert_post( array $body, string $post_content, bool $with_content ): int|WP_Error {
		$postarr = array(
			'post_title'  => wp_strip_all_tags( (string) ( $body['post_title'] ?? '' ) ),
			'post_status' => sanitize_key( (string) ( $body['post_status'] ?? 'publish' ) ),
			'post_type'   => sanitize_key( (string) ( $body['post_type'] ?? 'page' ) ),
		);
		if ( $with_content ) {
			$postarr['post_content'] = $post_content;
		}

		if ( isset( $body['post_name'] ) ) {
			$postarr['post_name'] = sanitize_title( (string) $body['post_name'] );
		}

		if ( ! empty( $body['post_id'] ) ) {
			$postarr['ID'] = (int) $body['post_id'];
			return wp_update_post( $postarr, true );
		}

		return wp_insert_post( $postarr, true );
	}

	private function applied_result( int $post_id ): array {
		return array(
			'post_id'   => $post_id,
			'permalink' => get_permalink( $post_id ),
			'edit_url'  => get_edit_post_link( $post_id, 'raw' ),
		);
	}
}
