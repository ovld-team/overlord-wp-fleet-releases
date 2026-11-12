<?php
/**
 * /mcp-bridge/v1/content/* — CRUD for any post type, media, taxonomy terms, and nav
 * menus. This is the general-purpose content module; builder-specific page payloads
 * (Elementor/Gutenberg/WPBakery/WXR/SQL) go through class-module-builder-apply.php.
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

class Ovp_Module_Content extends Ovp_Module_Base {

	private const MEDIA_MAX_BYTES = 8388608;

	/** Tier per tool, consumed by class-ovp-tiers.php. Meta writes escalate per key (OVP_Meta::required_tier). */
	public const OVP_TIER = array(
		'content_list'    => 'T0',
		'content_get'     => 'T0',
		'content_create'  => 'T1',
		'content_update'  => 'T1',
		'content_delete'  => 'T1',
		'media_list'      => 'T0',
		'media_create'    => 'T1',
		'media_rename'    => 'T1',
		'media_delete'    => 'T1',
		'terms_list'      => 'T0',
		'terms_write'     => 'T1',
		'menus_list'      => 'T0',
		'menus_write'     => 'T1',
		'meta_adapters'   => 'T0',
		'meta_keys'       => 'T0',
		'meta_get'        => 'T0',
		'meta_prepare'    => 'T0',
		'meta_commit'     => 'T1',
		'meta_delete'     => 'T1',
		'meta_rollback'   => 'T1',
	);

	/** Fail-closed tier gate (central gate lives in OVP_Tiers; this is defense in depth). */
	private function gate( WP_REST_Request $request, string $need = 'T1' ): ?WP_Error {
		if ( OVP_Upload::tier_rank( OVP_Meta::request_tier( $request ) ) < OVP_Upload::tier_rank( $need ) ) {
			return $this->error( 'ovp_tier_denied', 'Tier ' . $need . ' required.', 403 );
		}
		return null;
	}

	public function register_routes(): void {
		$this->route( 'GET', 'meta/adapters', array( $this, 'meta_adapters' ) );
		$this->route( 'GET', 'meta/keys', array( $this, 'meta_keys' ) );
		$this->route( 'GET', 'meta/get', array( $this, 'meta_get' ) );
		$this->route( 'POST', 'meta/prepare', array( $this, 'meta_prepare' ) );
		$this->route( 'POST', 'meta/commit', array( $this, 'meta_commit' ) );
		$this->route( 'POST', 'meta/delete', array( $this, 'meta_delete' ) );
		$this->route( 'POST', 'meta/rollback', array( $this, 'meta_rollback' ) );
		$this->route( 'GET', 'content/posts', array( $this, 'list_posts' ) );
		$this->route( 'GET', 'content/posts/(?P<id>\d+)', array( $this, 'get_post' ) );
		$this->route( 'POST', 'content/posts', array( $this, 'create_post' ) );
		$this->route( 'PUT,PATCH', 'content/posts/(?P<id>\d+)', array( $this, 'update_post' ) );
		$this->route( 'DELETE', 'content/posts/(?P<id>\d+)', array( $this, 'delete_post' ) );

		$this->route( 'GET', 'content/media', array( $this, 'list_media' ) );
		$this->route( 'POST', 'content/media', array( $this, 'create_media' ) );
		$this->route( 'POST', 'content/media/rename', array( $this, 'rename_media' ) );
		$this->route( 'DELETE', 'content/media/(?P<id>\d+)', array( $this, 'delete_media' ) );

		$this->route( 'GET', 'content/terms', array( $this, 'list_terms' ) );
		$this->route( 'POST', 'content/terms', array( $this, 'create_term' ) );
		$this->route( 'PUT,PATCH', 'content/terms/(?P<id>\d+)', array( $this, 'update_term' ) );
		$this->route( 'DELETE', 'content/terms/(?P<id>\d+)', array( $this, 'delete_term' ) );

		$this->route( 'GET', 'content/menus', array( $this, 'list_menus' ) );
		$this->route( 'POST', 'content/menus', array( $this, 'create_menu' ) );
	}

	// -- Posts ---------------------------------------------------------------

	public function list_posts( WP_REST_Request $request ): WP_REST_Response {
		$page = max( 1, (int) ( $request->get_param( 'cursor' ) ?: $request->get_param( 'page' ) ?: 1 ) );
		$query = new WP_Query(
			array(
				'post_type'      => (string) $request->get_param( 'post_type' ) ?: 'any',
				'post_status'    => (string) $request->get_param( 'post_status' ) ?: 'any',
				's'              => (string) $request->get_param( 'search' ),
				'posts_per_page' => min( 100, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ),
				'paged'          => $page,
			)
		);
		$next = $page < (int) $query->max_num_pages ? (string) ( $page + 1 ) : '';

		return $this->success(
			Ovp_Chunk::envelope(
				array(
					'total' => (int) $query->found_posts,
					'posts' => array_map( array( $this, 'shape_post' ), $query->posts ),
				),
				$next
			)
		);
	}

	public function get_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post = get_post( (int) $request->get_param( 'id' ) );
		if ( ! $post ) {
			return $this->error( 'mcp_bridge_not_found', 'Post not found.', 404 );
		}
		return $this->success( $this->shape_post( $post, true ) );
	}

	public function create_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->gate( $request );
		if ( null !== $gate ) {
			return $gate;
		}
		$body = (array) $request->get_json_params();

		$postarr = array(
			'post_type'    => sanitize_key( $body['post_type'] ?? 'post' ),
			'post_title'   => wp_strip_all_tags( (string) ( $body['post_title'] ?? '' ) ),
			'post_content' => (string) ( $body['post_content'] ?? '' ),
			'post_excerpt' => (string) ( $body['post_excerpt'] ?? '' ),
			'post_status'  => sanitize_key( $body['post_status'] ?? 'draft' ),
			'post_name'    => isset( $body['post_name'] ) ? sanitize_title( (string) $body['post_name'] ) : '',
			'post_parent'  => (int) ( $body['post_parent'] ?? 0 ),
			'menu_order'   => (int) ( $body['menu_order'] ?? 0 ),
		);

		if ( isset( $body['page_template'] ) ) {
			$postarr['page_template'] = sanitize_text_field( (string) $body['page_template'] );
		}

		// D11: validate terms first; nothing is inserted when a tag/category does not resolve.
		$terms_plan = $this->plan_terms( $body );
		if ( $terms_plan instanceof WP_Error ) {
			return $terms_plan;
		}

		$post_id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $post_id ) ) {
			return $this->error( 'mcp_bridge_insert_failed', $post_id->get_error_message(), 400 );
		}

		$meta_errors = $this->apply_meta( $post_id, (array) ( $body['meta'] ?? array() ), $request );
		$terms_err   = $this->apply_terms_plan( $post_id, $terms_plan );
		if ( $terms_err instanceof WP_Error ) {
			wp_delete_post( $post_id, true ); // Roll back: no orphan draft after a failed create.
			return $terms_err;
		}

		$shaped = $this->shape_post( get_post( $post_id ), true );
		if ( array() !== $meta_errors ) {
			$shaped['meta_errors'] = $meta_errors;
			$shaped['warnings']    = $this->meta_warnings;
		}
		return $this->success( $shaped );
	}

	public function update_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->gate( $request );
		if ( null !== $gate ) {
			return $gate;
		}
		$post_id = (int) $request->get_param( 'id' );
		if ( ! get_post( $post_id ) ) {
			return $this->error( 'mcp_bridge_not_found', 'Post not found.', 404 );
		}

		$body    = (array) $request->get_json_params();
		$postarr = array( 'ID' => $post_id );

		foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'page_template' ) as $key ) {
			if ( array_key_exists( $key, $body ) ) {
				$postarr[ $key ] = (string) $body[ $key ];
			}
		}
		foreach ( array( 'post_parent', 'menu_order' ) as $key ) {
			if ( array_key_exists( $key, $body ) ) {
				$postarr[ $key ] = (int) $body[ $key ];
			}
		}

		$result = wp_update_post( $postarr, true );
		if ( is_wp_error( $result ) ) {
			return $this->error( 'mcp_bridge_update_failed', $result->get_error_message(), 400 );
		}

		$meta_errors = $this->apply_meta( $post_id, (array) ( $body['meta'] ?? array() ), $request );
		$terms_err   = $this->apply_terms( $post_id, $body );
		if ( $terms_err instanceof WP_Error ) {
			return $terms_err;
		}

		$shaped = $this->shape_post( get_post( $post_id ), true );
		if ( array() !== $meta_errors ) {
			$shaped['meta_errors'] = $meta_errors;
			$shaped['warnings']    = $this->meta_warnings;
		}
		return $this->success( $shaped );
	}

	public function delete_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->gate( $request );
		if ( null !== $gate ) {
			return $gate;
		}
		$post_id = (int) $request->get_param( 'id' );
		$force   = filter_var( $request->get_param( 'force' ), FILTER_VALIDATE_BOOLEAN );

		$result = wp_delete_post( $post_id, $force );
		if ( ! $result ) {
			return $this->error( 'mcp_bridge_delete_failed', 'Post not found or could not be deleted.', 400 );
		}

		return $this->success( array( 'deleted' => $post_id, 'trashed' => ! $force ) );
	}

	/**
	 * Per-key writes through the meta layer (adapter validation, denylist, snapshot).
	 * Failures are reported per key, never silently dropped.
	 *
	 * @param array<string, mixed> $meta
	 * @return array<string, string> key => error code
	 */
	/** @var string[] D21: human-readable notes for meta keys that were skipped, reset per request. */
	private array $meta_warnings = array();

	private function apply_meta( int $post_id, array $meta, WP_REST_Request $request ): array {
		$tier                = OVP_Meta::request_tier( $request );
		$errors              = array();
		$this->meta_warnings = array();
		foreach ( $meta as $key => $value ) {
			$res = OVP_Meta::commit( 'post', $post_id, (string) $key, $value, array( 'tier' => $tier ) );
			if ( is_wp_error( $res ) ) {
				$errors[ (string) $key ] = $res->get_error_code();
				$this->meta_warnings[]   = 'meta "' . (string) $key . '" was NOT written: ' . $res->get_error_code() . ' (' . $res->get_error_message() . ')';
			}
		}
		return $errors;
	}

	// -- Generic meta (post|term|user|comment|option) ---------------------------------------------

	public function meta_adapters(): WP_REST_Response {
		return $this->success( array( 'adapters' => OVP_Meta::discovery() ) );
	}

	public function meta_keys( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$res = OVP_Meta::keys(
			(string) $request->get_param( 'type' ),
			(int) $request->get_param( 'id' ),
			array( 'prefix' => (string) $request->get_param( 'prefix' ), 'limit' => (int) ( $request->get_param( 'limit' ) ?: 200 ) )
		);
		return is_wp_error( $res ) ? $res : $this->success( $res );
	}

	public function meta_get( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$res = OVP_Meta::get(
			(string) $request->get_param( 'type' ),
			(int) $request->get_param( 'id' ),
			(string) $request->get_param( 'key' ),
			array(
				'tier'      => OVP_Meta::request_tier( $request ),
				'adapter'   => (string) $request->get_param( 'adapter' ),
				'field_key' => $request->get_param( 'field_key' ),
				'offset'    => (int) $request->get_param( 'offset' ),
				'max_bytes' => (int) $request->get_param( 'max_bytes' ),
			)
		);
		return is_wp_error( $res ) ? $res : $this->success( $res );
	}

	/** @return array<string, mixed> */
	private function meta_opts( WP_REST_Request $request, array $body ): array {
		return array(
			'tier'          => OVP_Meta::request_tier( $request ),
			'adapter'       => (string) ( $body['adapter'] ?? '' ),
			'field_key'     => isset( $body['field_key'] ) ? (string) $body['field_key'] : null,
			'as'            => isset( $body['as'] ) ? (string) $body['as'] : null,
			'expected_hash' => (string) ( $body['expected_hash'] ?? '' ),
			'change_hash'   => (string) ( $body['change_hash'] ?? '' ),
			'dry_run'       => ! empty( $body['dry_run'] ),
			'confirm'       => ! empty( $body['confirm'] ),
		);
	}

	public function meta_prepare( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$b = (array) $request->get_json_params();
		if ( ! array_key_exists( 'value', $b ) && empty( $b['delete'] ) ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Provide "value" (or delete=true).', 400 );
		}
		$o = $this->meta_opts( $request, $b );
		if ( ! empty( $b['delete'] ) ) {
			$o['delete'] = true;
		}
		$res = OVP_Meta::prepare( (string) ( $b['type'] ?? '' ), (int) ( $b['id'] ?? 0 ), (string) ( $b['key'] ?? '' ), $b['value'] ?? null, $o );
		return is_wp_error( $res ) ? $res : $this->success( $res );
	}

	public function meta_commit( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$b = (array) $request->get_json_params();
		// D9: the master sends delete=true with value null on this route; route it to the delete path
		// so adapter validation never demands a string for a removal.
		if ( ! empty( $b['delete'] ) ) {
			$res = OVP_Meta::delete( (string) ( $b['type'] ?? '' ), (int) ( $b['id'] ?? 0 ), (string) ( $b['key'] ?? '' ), $this->meta_opts( $request, $b ) );
			return is_wp_error( $res ) ? $res : $this->success( $res );
		}
		if ( ! array_key_exists( 'value', $b ) ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Provide "value". Use meta/delete to remove a key.', 400 );
		}
		$res = OVP_Meta::commit( (string) ( $b['type'] ?? '' ), (int) ( $b['id'] ?? 0 ), (string) ( $b['key'] ?? '' ), $b['value'], $this->meta_opts( $request, $b ) );
		return is_wp_error( $res ) ? $res : $this->success( $res );
	}

	public function meta_delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$b   = (array) $request->get_json_params();
		$res = OVP_Meta::delete( (string) ( $b['type'] ?? '' ), (int) ( $b['id'] ?? 0 ), (string) ( $b['key'] ?? '' ), $this->meta_opts( $request, $b ) );
		return is_wp_error( $res ) ? $res : $this->success( $res );
	}

	public function meta_rollback( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$b   = (array) $request->get_json_params();
		$res = OVP_Meta::rollback( (string) ( $b['snapshot_id'] ?? '' ), array( 'tier' => OVP_Meta::request_tier( $request ), 'force' => ! empty( $b['force'] ), 'confirm' => ! empty( $b['confirm'] ) ) );
		return is_wp_error( $res ) ? $res : $this->success( $res );
	}

	/**
	 * Resolve every term reference in the body BEFORE anything is written (D11), so an unknown tag
	 * or category fails the request with nothing created or half-updated.
	 *
	 * @param array<string, mixed> $body
	 * @return array{category:?array<int,int>,post_tag:?array<int,int>,primary:?int,clear_primary:bool}|WP_Error
	 */
	private function plan_terms( array $body ): array|WP_Error {
		$plan = array( 'category' => null, 'post_tag' => null, 'primary' => null, 'clear_primary' => false );
		foreach ( array( 'categories' => 'category', 'tags' => 'post_tag' ) as $field => $tax ) {
			if ( array_key_exists( $field, $body ) ) {
				$ids = $this->resolve_term_ids( $body[ $field ], $tax );
				if ( $ids instanceof WP_Error ) {
					return $ids;
				}
				$plan[ $tax ] = $ids;
			}
		}
		if ( array_key_exists( 'primary_category', $body ) ) {
			$raw = $body['primary_category'];
			if ( null === $raw || '' === $raw || false === $raw ) {
				$plan['clear_primary'] = true;
			} else {
				$ids = $this->resolve_term_ids( array( $raw ), 'category' );
				if ( $ids instanceof WP_Error ) {
					return $ids;
				}
				if ( array() === $ids ) {
					return $this->error( 'mcp_bridge_invalid_term', 'primary_category did not resolve.', 400 );
				}
				$plan['primary'] = $ids[0];
			}
		}
		return $plan;
	}

	/**
	 * @param array{category:?array<int,int>,post_tag:?array<int,int>,primary:?int,clear_primary:bool} $plan
	 */
	private function apply_terms_plan( int $post_id, array $plan ): ?WP_Error {
		foreach ( array( 'category', 'post_tag' ) as $tax ) {
			if ( null !== $plan[ $tax ] ) {
				$set = wp_set_object_terms( $post_id, $plan[ $tax ], $tax, false );
				if ( is_wp_error( $set ) ) {
					return $this->error( 'mcp_bridge_set_terms_failed', $set->get_error_message(), 400 );
				}
			}
		}
		if ( $plan['clear_primary'] ) {
			delete_post_meta( $post_id, '_yoast_wpseo_primary_category' );
		} elseif ( null !== $plan['primary'] ) {
			update_post_meta( $post_id, '_yoast_wpseo_primary_category', (string) $plan['primary'] );
		}
		return null;
	}

	/**
	 * Assign native taxonomies from MCP body keys. Empty `categories` lets WP fall back
	 * to default_category. Unknown slugs/ids are a hard error so agents cannot silently
	 * drop a recategorize.
	 *
	 * @param array<string, mixed> $body
	 */
	private function apply_terms( int $post_id, array $body ): ?WP_Error {
		$plan = $this->plan_terms( $body );
		return $plan instanceof WP_Error ? $plan : $this->apply_terms_plan( $post_id, $plan );
	}

	/**
	 * @param mixed $raw int, slug, name, or list of those.
	 * @return int[]|WP_Error
	 */
	private function resolve_term_ids( mixed $raw, string $taxonomy ): array|WP_Error {
		if ( ! is_array( $raw ) ) {
			$raw = array( $raw );
		}
		$ids = array();
		foreach ( $raw as $item ) {
			if ( is_int( $item ) || ( is_string( $item ) && ctype_digit( $item ) ) ) {
				$term = get_term( (int) $item, $taxonomy );
			} else {
				$label = sanitize_text_field( (string) $item );
				$slug  = sanitize_title( $label );
				$term  = get_term_by( 'slug', $slug, $taxonomy );
				if ( ! $term instanceof WP_Term ) {
					$term = get_term_by( 'name', $label, $taxonomy );
				}
			}
			if ( ! $term instanceof WP_Term ) {
				return $this->error(
					'mcp_bridge_invalid_term',
					sprintf( 'Unknown %s term: %s', $taxonomy, wp_json_encode( $item ) ),
					400
				);
			}
			$ids[] = (int) $term->term_id;
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function shape_term( WP_Term $term ): array {
		return array(
			'id'          => $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'taxonomy'    => $term->taxonomy,
			'count'       => $term->count,
			'description' => $term->description,
		);
	}

	private function shape_post( WP_Post $post, bool $include_meta = false ): array {
		$cats = get_the_terms( $post, 'category' );
		$tags = get_the_terms( $post, 'post_tag' );
		$shaped = array(
			'id'          => $post->ID,
			'post_type'   => $post->post_type,
			'post_title'  => $post->post_title,
			'post_status' => $post->post_status,
			'post_name'   => $post->post_name,
			'post_parent' => $post->post_parent,
			'permalink'   => get_permalink( $post ),
			'modified'    => $post->post_modified,
			'categories'  => is_array( $cats ) ? array_map( array( $this, 'shape_term' ), $cats ) : array(),
			'tags'        => is_array( $tags ) ? array_map( array( $this, 'shape_term' ), $tags ) : array(),
		);
		if ( $include_meta ) {
			$shaped['post_content'] = $post->post_content;
			$shaped['post_excerpt'] = $post->post_excerpt;
			$shaped['meta']         = OVP_Meta::map_for( 'post', $post->ID );
		}
		return $shaped;
	}

	// -- Media ----------------------------------------------------------------

	public function list_media( WP_REST_Request $request ): WP_REST_Response {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => min( 100, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ),
			'paged'          => max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) ),
		);
		$search = trim( (string) ( $request->get_param( 'search' ) ?: '' ) );
		if ( '' !== $search ) {
			// D25: case-insensitive, and the slug form ("fleet-qa") matches the title form ("Fleet QA").
			$args['post__in'] = $this->media_search_ids( $search ) ?: array( 0 );
			$args['orderby']  = 'post__in';
		}
		$query = new WP_Query( $args );

		return $this->success(
			array_map(
				function ( WP_Post $p ): array {
					$abs = (string) get_attached_file( $p->ID );
					$rel = '';
					if ( '' !== $abs && str_starts_with( $abs, WP_CONTENT_DIR ) ) {
						$rel = ltrim( str_replace( WP_CONTENT_DIR, '', $abs ), '/\\' );
					}
					return array(
						'id'       => $p->ID,
						'title'    => $p->post_title,
						'url'      => wp_get_attachment_url( $p->ID ),
						'alt'      => get_post_meta( $p->ID, '_wp_attachment_image_alt', true ),
						'filename' => $rel ? wp_basename( $rel ) : wp_basename( (string) wp_get_attachment_url( $p->ID ) ),
						'file'     => $rel,
						'mime'     => $p->post_mime_type,
					);
				},
				$query->posts
			)
		);
	}

	/**
	 * LIKE needles for a media search: the text as typed, its slug form ("Fleet QA" -> "fleet-qa") and the
	 * spaced form ("fleet-qa" -> "fleet qa"), all lower case.
	 *
	 * @return string[]
	 */
	public static function media_search_needles( string $search ): array {
		$raw    = mb_strtolower( trim( $search ) );
		$slug   = function_exists( 'sanitize_title' ) ? sanitize_title( $raw ) : trim( (string) preg_replace( '/[^a-z0-9]+/', '-', $raw ), '-' );
		$spaced = str_replace( '-', ' ', $slug );
		return array_values( array_unique( array_filter( array( $raw, $slug, $spaced ), static fn( string $n ): bool => '' !== $n ) ) );
	}

	/** @return int[] attachment ids matching title, slug, caption or stored file name. */
	private function media_search_ids( string $search ): array {
		global $wpdb;
		$needles = self::media_search_needles( $search );
		if ( array() === $needles ) {
			return array();
		}
		$where = array();
		$args  = array();
		foreach ( $needles as $n ) {
			$like = '%' . $wpdb->esc_like( $n ) . '%';
			foreach ( array( 'LOWER(p.post_title)', 'LOWER(p.post_name)', 'LOWER(p.post_excerpt)', 'LOWER(m.meta_value)' ) as $col ) {
				$where[] = $col . ' LIKE %s';
				$args[]  = $like;
			}
		}
		$sql = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file' WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND (" . implode( ' OR ', $where ) . ') ORDER BY p.ID DESC LIMIT 500'; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fragments are constant; values bound below.
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, ...$args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * upload_dir filter: put imports in the year/month folder even when the site turned
	 * "organize uploads into month folders" off (D25). Leaves an already dated subdir alone.
	 *
	 * @param array<string, mixed> $dirs
	 * @return array<string, mixed>
	 */
	public static function yearmonth_upload_dir( array $dirs ): array {
		if ( '' !== (string) ( $dirs['subdir'] ?? '' ) || ! isset( $dirs['basedir'], $dirs['baseurl'] ) ) {
			return $dirs;
		}
		$sub          = '/' . gmdate( 'Y' ) . '/' . gmdate( 'm' );
		$dirs['subdir'] = $sub;
		$dirs['path']   = rtrim( (string) $dirs['basedir'], '/' ) . $sub;
		$dirs['url']    = rtrim( (string) $dirs['baseurl'], '/' ) . $sub;
		if ( function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( $dirs['path'] );
		}
		return $dirs;
	}

	public function create_media( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->gate( $request );
		if ( null !== $gate ) {
			return $gate;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$body     = (array) $request->get_json_params();
		$url      = isset( $body['url'] ) ? (string) $body['url'] : '';
		$content  = isset( $body['content'] ) ? (string) $body['content'] : '';
		$encoding = isset( $body['encoding'] ) ? (string) $body['encoding'] : 'base64';
		$filename = isset( $body['filename'] ) ? sanitize_file_name( (string) $body['filename'] ) : '';
		$skip     = ! isset( $body['skip_if_exists'] ) || (bool) $body['skip_if_exists'];

		$tmp = '';
		if ( '' !== $content ) {
			if ( '' === $filename ) {
				return $this->error( 'mcp_bridge_invalid_media', 'filename is required with content.', 400 );
			}
			if ( 'base64' !== $encoding ) {
				return $this->error( 'mcp_bridge_invalid_media', 'encoding must be base64.', 400 );
			}
			if ( strlen( $content ) > (int) ( self::MEDIA_MAX_BYTES * 4 / 3 ) + 64 ) {
				return $this->error( 'mcp_bridge_file_too_large', 'Media upload cap is 8MB.', 413 );
			}
			$raw = base64_decode( $content, true );
			if ( false === $raw || '' === $raw ) {
				return $this->error( 'mcp_bridge_invalid_payload', 'Invalid media content.', 400 );
			}
			if ( strlen( $raw ) > self::MEDIA_MAX_BYTES ) {
				return $this->error( 'mcp_bridge_file_too_large', 'Media upload cap is 8MB.', 413 );
			}
			$err = $this->assert_safe_media_name( $filename );
			if ( is_wp_error( $err ) ) {
				return $err;
			}
			if ( $skip ) {
				$existing = $this->attachment_by_basename( $filename );
				if ( $existing ) {
					return $this->success(
						array(
							'id'      => $existing,
							'url'     => wp_get_attachment_url( $existing ),
							'skipped' => true,
						)
					);
				}
			}
			$tmp = wp_tempnam( $filename );
			if ( ! is_string( $tmp ) || false === file_put_contents( $tmp, $raw ) ) {
				return $this->error( 'mcp_bridge_write_failed', 'Could not stage upload.', 502 );
			}
		} elseif ( '' !== $url ) {
			$safe = Ovp_Net::assert_public_https_url( $url );
			if ( is_wp_error( $safe ) ) {
				return $safe;
			}
			if ( '' === $filename ) {
				$filename = sanitize_file_name( wp_basename( (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?: 'download.jpg' ) ) );
			}
			$err = $this->assert_safe_media_name( $filename );
			if ( is_wp_error( $err ) ) {
				return $err;
			}
			if ( $skip ) {
				$existing = $this->attachment_by_basename( $filename );
				if ( $existing ) {
					return $this->success(
						array(
							'id'      => $existing,
							'url'     => wp_get_attachment_url( $existing ),
							'skipped' => true,
						)
					);
				}
			}
			$downloaded = Ovp_Net::with_safe_http(
				static function () use ( $url ) {
					return download_url( $url );
				}
			);
			if ( is_wp_error( $downloaded ) ) {
				return $this->error( 'mcp_bridge_download_failed', $downloaded->get_error_message(), 400 );
			}
			$tmp = (string) $downloaded;
			$size = is_file( $tmp ) ? filesize( $tmp ) : false;
			if ( false === $size || $size > self::MEDIA_MAX_BYTES ) {
				@unlink( $tmp );
				return $this->error( 'mcp_bridge_file_too_large', 'Media upload cap is 8MB.', 413 );
			}
		} else {
			return $this->error( 'mcp_bridge_invalid_media', 'Provide url or content+filename.', 400 );
		}

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);

		add_filter( 'upload_dir', array( self::class, 'yearmonth_upload_dir' ), 99 );
		try {
			$attachment_id = media_handle_sideload( $file_array, (int) ( $body['post_id'] ?? 0 ) );
		} finally {
			remove_filter( 'upload_dir', array( self::class, 'yearmonth_upload_dir' ), 99 );
		}
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			return $this->error( 'mcp_bridge_sideload_failed', $attachment_id->get_error_message(), 400 );
		}

		if ( isset( $body['alt'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $body['alt'] ) );
		}
		if ( isset( $body['title'] ) && '' !== (string) $body['title'] ) {
			wp_update_post(
				array(
					'ID'         => $attachment_id,
					'post_title' => sanitize_text_field( (string) $body['title'] ),
				)
			);
		}

		return $this->success(
			array(
				'id'      => $attachment_id,
				'url'     => wp_get_attachment_url( $attachment_id ),
				'skipped' => false,
			)
		);
	}

	private function assert_safe_media_name( string $filename ): true|WP_Error {
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$ok  = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'pdf' );
		if ( ! in_array( $ext, $ok, true ) ) {
			return $this->error( 'mcp_bridge_invalid_media', 'Allowed: jpg jpeg png gif webp avif pdf.', 400 );
		}
		return true;
	}

	private function attachment_by_basename( string $basename ): ?int {
		global $wpdb;
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND (meta_value = %s OR meta_value LIKE %s) ORDER BY post_id DESC LIMIT 1",
				$basename,
				'%/' . $wpdb->esc_like( $basename )
			)
		);
		if ( ! $id ) {
			return null;
		}
		$post = get_post( (int) $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return null;
		}
		return (int) $id;
	}

	public function rename_media( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->gate( $request );
		if ( null !== $gate ) {
			return $gate;
		}
		$result = Ovp_Media_Rename::run( $request );
		if ( $result instanceof WP_Error ) {
			return $result;
		}
		return $this->success( $result );
	}

	public function delete_media( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->gate( $request );
		if ( null !== $gate ) {
			return $gate;
		}
		$id     = (int) $request->get_param( 'id' );
		$result = wp_delete_attachment( $id, true );
		if ( ! $result ) {
			return $this->error( 'mcp_bridge_delete_failed', 'Attachment not found.', 404 );
		}
		return $this->success( array( 'deleted' => $id ) );
	}

	// -- Terms ------------------------------------------------------------------

	public function list_terms( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$taxonomy = sanitize_key( (string) ( $request->get_param( 'taxonomy' ) ?: 'category' ) );
		$terms    = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );

		if ( is_wp_error( $terms ) ) {
			return $this->error( 'mcp_bridge_invalid_taxonomy', $terms->get_error_message(), 400 );
		}

		return $this->success( array_map( array( $this, 'shape_term' ), $terms ) );
	}

	public function create_term( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->gate( $request );
		if ( null !== $gate ) {
			return $gate;
		}
		$body     = (array) $request->get_json_params();
		$taxonomy = sanitize_key( (string) ( $body['taxonomy'] ?? 'category' ) );
		$name     = sanitize_text_field( (string) ( $body['name'] ?? '' ) );

		if ( '' === $name ) {
			return $this->error( 'mcp_bridge_invalid_term', 'Provide a "name".', 400 );
		}

		$args = array( 'slug' => sanitize_title( (string) ( $body['slug'] ?? $name ) ) );
		if ( isset( $body['description'] ) ) {
			$args['description'] = sanitize_textarea_field( (string) $body['description'] );
		}

		$result = wp_insert_term( $name, $taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			return $this->error( 'mcp_bridge_insert_term_failed', $result->get_error_message(), 400 );
		}

		$term = get_term( (int) $result['term_id'], $taxonomy );
		if ( ! $term instanceof WP_Term ) {
			return $this->error( 'mcp_bridge_insert_term_failed', 'Term created but could not be loaded.', 500 );
		}
		return $this->success( $this->shape_term( $term ) );
	}

	public function update_term( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->gate( $request );
		if ( null !== $gate ) {
			return $gate;
		}
		$id       = (int) $request->get_param( 'id' );
		$body     = (array) $request->get_json_params();
		$taxonomy = sanitize_key( (string) ( $body['taxonomy'] ?? $request->get_param( 'taxonomy' ) ?? 'category' ) );
		if ( '' === $taxonomy ) {
			$taxonomy = 'category';
		}
		$term     = get_term( $id, $taxonomy );
		if ( ! $term instanceof WP_Term ) {
			return $this->error( 'mcp_bridge_not_found', 'Term not found.', 404 );
		}

		$args = array();
		if ( array_key_exists( 'name', $body ) ) {
			$args['name'] = sanitize_text_field( (string) $body['name'] );
		}
		if ( array_key_exists( 'slug', $body ) ) {
			$args['slug'] = sanitize_title( (string) $body['slug'] );
		}
		if ( array_key_exists( 'description', $body ) ) {
			$args['description'] = sanitize_textarea_field( (string) $body['description'] );
		}
		if ( array() === $args ) {
			return $this->success( $this->shape_term( $term ) );
		}

		$result = wp_update_term( $id, $taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			return $this->error( 'mcp_bridge_update_term_failed', $result->get_error_message(), 400 );
		}

		$fresh = get_term( (int) $result['term_id'], $taxonomy );
		if ( ! $fresh instanceof WP_Term ) {
			return $this->error( 'mcp_bridge_update_term_failed', 'Term updated but could not be loaded.', 500 );
		}
		return $this->success( $this->shape_term( $fresh ) );
	}

	public function delete_term( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->gate( $request );
		if ( null !== $gate ) {
			return $gate;
		}
		$id       = (int) $request->get_param( 'id' );
		$taxonomy = sanitize_key( (string) ( $request->get_param( 'taxonomy' ) ?: 'category' ) );
		$default  = (int) ( $request->get_param( 'default' ) ?: 0 );
		$term     = get_term( $id, $taxonomy );
		if ( ! $term instanceof WP_Term ) {
			return $this->error( 'mcp_bridge_not_found', 'Term not found.', 404 );
		}

		if ( 'category' === $taxonomy && (int) get_option( 'default_category' ) === $id ) {
			if ( $default <= 0 || $default === $id ) {
				return $this->error(
					'mcp_bridge_default_category',
					'Pass default=<other category id> before deleting the default category.',
					400
				);
			}
			$replacement = get_term( $default, 'category' );
			if ( ! $replacement instanceof WP_Term ) {
				return $this->error( 'mcp_bridge_invalid_term', 'default replacement category not found.', 400 );
			}
			update_option( 'default_category', $default );
		}

		$args = array();
		if ( $default > 0 ) {
			$args['default'] = $default;
		}
		$result = wp_delete_term( $id, $taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			return $this->error( 'mcp_bridge_delete_term_failed', $result->get_error_message(), 400 );
		}
		if ( false === $result || 0 === $result ) {
			return $this->error( 'mcp_bridge_delete_term_failed', 'Term could not be deleted.', 400 );
		}

		return $this->success( array( 'deleted' => $id, 'taxonomy' => $taxonomy ) );
	}

	// -- Menus --------------------------------------------------------------

	public function list_menus(): WP_REST_Response {
		$menus = wp_get_nav_menus();
		return $this->success(
			array_map(
				static fn ( $m ) => array( 'id' => $m->term_id, 'name' => $m->name, 'slug' => $m->slug ),
				$menus
			)
		);
	}

	public function create_menu( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->gate( $request );
		if ( null !== $gate ) {
			return $gate;
		}
		$body = (array) $request->get_json_params();
		$name = sanitize_text_field( (string) ( $body['name'] ?? '' ) );

		if ( '' === $name ) {
			return $this->error( 'mcp_bridge_invalid_menu', 'Provide a "name".', 400 );
		}

		$menu_id = wp_update_nav_menu_object( 0, array( 'menu-name' => $name ) );
		if ( is_wp_error( $menu_id ) ) {
			return $this->error( 'mcp_bridge_menu_failed', $menu_id->get_error_message(), 400 );
		}

		foreach ( (array) ( $body['items'] ?? array() ) as $item ) {
			wp_update_nav_menu_item(
				$menu_id,
				0,
				array(
					'menu-item-title'     => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
					'menu-item-url'       => sanitize_url( (string) ( $item['url'] ?? '' ) ),
					'menu-item-object-id' => (int) ( $item['object_id'] ?? 0 ),
					'menu-item-type'      => sanitize_key( (string) ( $item['type'] ?? 'custom' ) ),
					'menu-item-status'    => 'publish',
				)
			);
		}

		return $this->success( array( 'id' => $menu_id ) );
	}
}
