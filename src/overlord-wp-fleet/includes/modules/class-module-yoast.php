<?php
/**
 * /ovp/v1/yoast/* — Yoast-aware option + per-object meta.
 * Uses WPSEO_Options::set / WPSEO_Meta::set_value so sanitizers run.
 * 404 JSON when Yoast is inactive.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ovp_Module_Yoast extends Ovp_Module_Base {

	/**
	 * WPSEO_Meta internal keys (no _yoast_wpseo_ prefix) agents may read/write.
	 *
	 * @var string[]
	 */
	private const POST_KEYS = array(
		'focuskw',
		'title',
		'metadesc',
		'is_cornerstone',
		'canonical',
		'bctitle',
		'meta-robots-noindex',
		'meta-robots-nofollow',
		'meta-robots-adv',
		'schema_page_type',
		'schema_article_type',
		'opengraph-title',
		'opengraph-description',
		'opengraph-image',
		'opengraph-image-id',
		'twitter-title',
		'twitter-description',
		'twitter-image',
		'twitter-image-id',
	);

	/**
	 * WPSEO_Taxonomy_Meta short keys (no wpseo_ prefix) agents may read/write.
	 *
	 * @var string[]
	 */
	private const TERM_KEYS = array(
		'title',
		'desc',
		'focuskw',
		'canonical',
		'bctitle',
		'noindex',
		'is_cornerstone',
		'opengraph-title',
		'opengraph-description',
		'opengraph-image',
		'opengraph-image-id',
		'twitter-title',
		'twitter-description',
		'twitter-image',
		'twitter-image-id',
	);

	public function register_routes(): void {
		$this->route( 'GET', 'yoast/status', array( $this, 'status' ) );
		$this->route( 'GET', 'yoast/option', array( $this, 'get_option' ) );
		$this->route( 'POST', 'yoast/option', array( $this, 'set_option' ) );
		$this->route( 'GET', 'yoast/titles', array( $this, 'titles' ) );
		$this->route( 'GET', 'yoast/post/(?P<id>\d+)', array( $this, 'get_post' ) );
		$this->route( 'POST', 'yoast/post/(?P<id>\d+)', array( $this, 'set_post' ) );
		$this->route( 'GET', 'yoast/term/(?P<id>\d+)', array( $this, 'get_term' ) );
		$this->route( 'POST', 'yoast/term/(?P<id>\d+)', array( $this, 'set_term' ) );
		$this->route( 'POST', 'yoast/index', array( $this, 'run_index' ) );
	}

	private function yoast_ready(): bool {
		return class_exists( 'WPSEO_Options' );
	}

	public function status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		unset( $request );
		if ( ! $this->yoast_ready() ) {
			return $this->error( 'mcp_bridge_yoast_inactive', 'Yoast SEO is not active.', 404 );
		}
		$local = get_option( 'wpseo_local', array() );
		$get   = static function ( string $key, mixed $default = null ): mixed {
			return WPSEO_Options::get( $key, $default );
		};

		$sitemap_on = (bool) $get( 'enable_xml_sitemap', false );
		$llms_on    = (bool) $get( 'enable_llms_txt', false );
		$company    = (string) $get( 'company_or_person', 'company' );
		$org_name   = (string) $get( 'company_name', '' );

		$gaps = array();
		if ( ! $sitemap_on ) {
			$gaps[] = 'xml_sitemap_disabled';
		}
		if ( ! $llms_on ) {
			$gaps[] = 'llms_txt_disabled';
		}
		if ( 'company' === $company && '' === $org_name ) {
			$gaps[] = 'company_name_empty';
		}
		if ( '' === (string) $get( 'title-page', '' ) ) {
			$gaps[] = 'title_template_page_empty';
		}
		if ( '' === (string) $get( 'metadesc-page', '' ) ) {
			$gaps[] = 'metadesc_template_page_empty';
		}
		if ( '' === (string) $get( 'title-post', '' ) ) {
			$gaps[] = 'title_template_post_empty';
		}
		if ( '' === (string) $get( 'metadesc-post', '' ) ) {
			$gaps[] = 'metadesc_template_post_empty';
		}

		return $this->success(
			array(
				'yoast_version'                 => defined( 'WPSEO_VERSION' ) ? WPSEO_VERSION : '',
				'premium'                       => defined( 'WPSEO_PREMIUM_FILE' ),
				'company_or_person'             => $company,
				'company_name'                  => $org_name,
				'company_logo'                  => (string) $get( 'company_logo', '' ),
				'website_name'                  => (string) $get( 'website_name', '' ),
				'org_description'               => (string) $get( 'org-description', '' ),
				'environment_type'              => $get( 'environment_type' ),
				'enable_xml_sitemap'            => $sitemap_on,
				'enable_llms_txt'               => $llms_on,
				'llms_txt_selection_mode'       => (string) $get( 'llms_txt_selection_mode', '' ),
				'llms_about_us_page'            => (int) $get( 'about_us_page', 0 ),
				'llms_contact_page'             => (int) $get( 'contact_page', 0 ),
				'llms_terms_page'               => (int) $get( 'terms_page', 0 ),
				'llms_privacy_policy_page'      => (int) $get( 'privacy_policy_page', 0 ),
				'indexables_indexing_completed' => (bool) $get( 'indexables_indexing_completed', false ),
				'multiple_locations'            => is_array( $local ) ? ( $local['use_multiple_locations'] ?? '' ) : '',
				'separator'                     => (string) $get( 'separator', '' ),
				'title_home'                    => (string) $get( 'title-home-wpseo', '' ),
				'metadesc_home'                 => (string) $get( 'metadesc-home-wpseo', '' ),
				'facebook_site'                 => (string) $get( 'facebook_site', '' ),
				'twitter_site'                  => (string) $get( 'twitter_site', '' ),
				'og_default_image'              => (string) $get( 'og_default_image', '' ),
				'gaps'                          => $gaps,
				'post_allowlist'                => self::POST_KEYS,
				'term_allowlist'                => self::TERM_KEYS,
			)
		);
	}

	public function get_option( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! $this->yoast_ready() ) {
			return $this->error( 'mcp_bridge_yoast_inactive', 'Yoast SEO is not active.', 404 );
		}
		$key   = (string) $request->get_param( 'key' );
		$group = (string) ( $request->get_param( 'group' ) ?? '' );
		if ( '' === $key ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Provide "key".', 400 );
		}
		if ( ! $this->yoast_key_is_persistable( $key, $group ) ) {
			return $this->error(
				'mcp_bridge_yoast_unknown_key',
				'Key is not in the Yoast lookup/pattern table.',
				400
			);
		}
		return $this->success(
			array(
				'key'   => $key,
				'group' => $group,
				'value' => WPSEO_Options::get( $key ),
			)
		);
	}

	public function set_option( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! $this->yoast_ready() ) {
			return $this->error( 'mcp_bridge_yoast_inactive', 'Yoast SEO is not active.', 404 );
		}
		$body  = (array) $request->get_json_params();
		$key   = (string) ( $body['key'] ?? '' );
		$group = (string) ( $body['group'] ?? '' );
		if ( '' === $key ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Provide "key".', 400 );
		}
		$value = $body['value'] ?? null;
		if ( ! $this->yoast_key_is_persistable( $key, $group ) ) {
			return $this->error(
				'mcp_bridge_yoast_unknown_key',
				'Key is not in the Yoast lookup/pattern table; refusing in-memory-only write.',
				400
			);
		}
		WPSEO_Options::set( $key, $value, $group );
		WPSEO_Options::clear_cache();
		return $this->success(
			array(
				'key'   => $key,
				'group' => $group,
				'value' => WPSEO_Options::get( $key ),
			)
		);
	}

	public function titles( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		unset( $request );
		if ( ! $this->yoast_ready() ) {
			return $this->error( 'mcp_bridge_yoast_inactive', 'Yoast SEO is not active.', 404 );
		}

		$post_types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
			$name = $pt->name;
			$row  = array(
				'name'               => $name,
				'label'              => $pt->label,
				'has_archive'        => (bool) $pt->has_archive,
				'title'              => (string) WPSEO_Options::get( 'title-' . $name, '' ),
				'metadesc'           => (string) WPSEO_Options::get( 'metadesc-' . $name, '' ),
				'noindex'            => (bool) WPSEO_Options::get( 'noindex-' . $name, false ),
				'schema_page_type'   => (string) WPSEO_Options::get( 'schema-page-type-' . $name, '' ),
				'schema_article_type'=> (string) WPSEO_Options::get( 'schema-article-type-' . $name, '' ),
				'display_metabox'    => (bool) WPSEO_Options::get( 'display-metabox-pt-' . $name, true ),
			);
			if ( $pt->has_archive ) {
				$row['archive_title']    = (string) WPSEO_Options::get( 'title-ptarchive-' . $name, '' );
				$row['archive_metadesc'] = (string) WPSEO_Options::get( 'metadesc-ptarchive-' . $name, '' );
				$row['archive_noindex']  = (bool) WPSEO_Options::get( 'noindex-ptarchive-' . $name, false );
			}
			$row['gaps'] = array();
			if ( '' === $row['title'] ) {
				$row['gaps'][] = 'title';
			}
			if ( '' === $row['metadesc'] ) {
				$row['gaps'][] = 'metadesc';
			}
			$post_types[] = $row;
		}

		$taxonomies = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			$name = $tax->name;
			$row  = array(
				'name'            => $name,
				'label'           => $tax->label,
				'title'           => (string) WPSEO_Options::get( 'title-tax-' . $name, '' ),
				'metadesc'        => (string) WPSEO_Options::get( 'metadesc-tax-' . $name, '' ),
				'noindex'         => (bool) WPSEO_Options::get( 'noindex-tax-' . $name, false ),
				'display_metabox' => (bool) WPSEO_Options::get( 'display-metabox-tax-' . $name, true ),
				'gaps'            => array(),
			);
			if ( '' === $row['title'] ) {
				$row['gaps'][] = 'title';
			}
			if ( '' === $row['metadesc'] ) {
				$row['gaps'][] = 'metadesc';
			}
			$taxonomies[] = $row;
		}

		return $this->success(
			array(
				'separator'  => (string) WPSEO_Options::get( 'separator', '' ),
				'home'       => array(
					'title'    => (string) WPSEO_Options::get( 'title-home-wpseo', '' ),
					'metadesc' => (string) WPSEO_Options::get( 'metadesc-home-wpseo', '' ),
				),
				'post_types' => $post_types,
				'taxonomies' => $taxonomies,
			)
		);
	}

	public function get_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! $this->yoast_ready() || ! class_exists( 'WPSEO_Meta' ) ) {
			return $this->error( 'mcp_bridge_yoast_inactive', 'Yoast SEO is not active.', 404 );
		}
		$id = (int) $request->get_param( 'id' );
		$post = get_post( $id );
		if ( ! $post ) {
			return $this->error( 'mcp_bridge_not_found', 'Post not found.', 404 );
		}
		$fields = array();
		foreach ( self::POST_KEYS as $key ) {
			$fields[ $key ] = WPSEO_Meta::get_value( $key, $id );
		}
		return $this->success( $this->post_payload( $post, $fields ) );
	}

	public function set_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! $this->yoast_ready() || ! class_exists( 'WPSEO_Meta' ) ) {
			return $this->error( 'mcp_bridge_yoast_inactive', 'Yoast SEO is not active.', 404 );
		}
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );
		if ( ! $post ) {
			return $this->error( 'mcp_bridge_not_found', 'Post not found.', 404 );
		}
		$body   = (array) $request->get_json_params();
		$incoming = $this->incoming_fields( $body );
		if ( $incoming instanceof WP_Error ) {
			return $incoming;
		}
		$updated = array();
		$refused = array();
		foreach ( $incoming as $raw_key => $value ) {
			$key = $this->normalize_post_key( (string) $raw_key );
			if ( ! in_array( $key, self::POST_KEYS, true ) ) {
				$refused[] = $key;
				continue;
			}
			if ( ! $this->is_scalar_or_null( $value ) ) {
				$refused[] = $key;
				continue;
			}
			WPSEO_Meta::set_value( $key, $this->coerce_post_value( $key, $value ), $id );
			$updated[] = $key;
		}
		if ( array() === $updated && array() !== $refused ) {
			return $this->error(
				'mcp_bridge_yoast_unknown_key',
				'No allowlisted keys written. Refused: ' . implode( ', ', $refused ),
				400
			);
		}
		$fields = array();
		foreach ( self::POST_KEYS as $key ) {
			$fields[ $key ] = WPSEO_Meta::get_value( $key, $id );
		}
		$payload                 = $this->post_payload( $post, $fields );
		$payload['updated']      = $updated;
		$payload['refused']      = $refused;
		$payload['reindexed']    = $this->rebuild_indexable( $id, 'post' );
		return $this->success( $payload );
	}

	public function get_term( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! $this->yoast_ready() || ! class_exists( 'WPSEO_Taxonomy_Meta' ) ) {
			return $this->error( 'mcp_bridge_yoast_inactive', 'Yoast SEO is not active.', 404 );
		}
		$id       = (int) $request->get_param( 'id' );
		$taxonomy = (string) ( $request->get_param( 'taxonomy' ) ?? '' );
		$term     = $this->resolve_term( $id, $taxonomy );
		if ( $term instanceof WP_Error ) {
			return $term;
		}
		$raw    = WPSEO_Taxonomy_Meta::get_term_meta( $term->term_id, $term->taxonomy );
		$fields = $this->normalize_term_fields( is_array( $raw ) ? $raw : array() );
		return $this->success( $this->term_payload( $term, $fields ) );
	}

	public function set_term( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! $this->yoast_ready() || ! class_exists( 'WPSEO_Taxonomy_Meta' ) ) {
			return $this->error( 'mcp_bridge_yoast_inactive', 'Yoast SEO is not active.', 404 );
		}
		$id       = (int) $request->get_param( 'id' );
		$body     = (array) $request->get_json_params();
		$taxonomy = (string) ( $body['taxonomy'] ?? $request->get_param( 'taxonomy' ) ?? '' );
		$term     = $this->resolve_term( $id, $taxonomy );
		if ( $term instanceof WP_Error ) {
			return $term;
		}
		$incoming = $this->incoming_fields( $body );
		if ( $incoming instanceof WP_Error ) {
			return $incoming;
		}
		$existing = WPSEO_Taxonomy_Meta::get_term_meta( $term->term_id, $term->taxonomy );
		$merged   = is_array( $existing ) ? $existing : array();
		$updated  = array();
		$refused  = array();
		foreach ( $incoming as $raw_key => $value ) {
			$key = $this->normalize_term_key( (string) $raw_key );
			if ( ! in_array( $key, self::TERM_KEYS, true ) ) {
				$refused[] = $key;
				continue;
			}
			if ( ! $this->is_scalar_or_null( $value ) ) {
				$refused[] = $key;
				continue;
			}
			$merged[ 'wpseo_' . $key ] = $this->coerce_term_value( $key, $value );
			$updated[]                 = $key;
		}
		if ( array() === $updated && array() !== $refused ) {
			return $this->error(
				'mcp_bridge_yoast_unknown_key',
				'No allowlisted keys written. Refused: ' . implode( ', ', $refused ),
				400
			);
		}
		if ( array() !== $updated ) {
			WPSEO_Taxonomy_Meta::set_values( $term->term_id, $term->taxonomy, $merged );
		}
		$raw    = WPSEO_Taxonomy_Meta::get_term_meta( $term->term_id, $term->taxonomy );
		$fields = $this->normalize_term_fields( is_array( $raw ) ? $raw : array() );
		$payload              = $this->term_payload( $term, $fields );
		$payload['updated']   = $updated;
		$payload['refused']   = $refused;
		$payload['reindexed'] = $this->rebuild_indexable( $term->term_id, 'term' );
		return $this->success( $payload );
	}

	public function run_index( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! $this->yoast_ready() || ! function_exists( 'YoastSEO' ) ) {
			return $this->error( 'mcp_bridge_yoast_inactive', 'Yoast SEO is not active.', 404 );
		}
		$body = (array) $request->get_json_params();
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 180 );
		}
		$max   = (int) ( $body['max_batches'] ?? 40 );
		$max   = max( 1, min( 80, $max ) );
		$class = \Yoast\WP\SEO\Actions\Indexing\Indexable_Post_Indexation_Action::class;
		try {
			$action = YoastSEO()->classes->get( $class );
		} catch ( \Throwable $e ) {
			return $this->error( 'mcp_bridge_yoast_index', $e->getMessage(), 500 );
		}
		$batches = 0;
		$last    = 0;
		for ( $i = 0; $i < $max; $i++ ) {
			$result = $action->index();
			$n      = is_countable( $result ) ? count( $result ) : 0;
			$last   = $n;
			$batches++;
			if ( 0 === $n ) {
				break;
			}
		}
		$drained = ( 0 === $last );
		if ( $drained ) {
			WPSEO_Options::set( 'indexables_indexing_completed', true, 'wpseo' );
		}
		return $this->success(
			array(
				'batches'    => $batches,
				'last_batch' => $last,
				'completed'  => $drained,
				'note'       => $drained ? 'post indexables drained' : 'more post indexables remain; global flag not set',
			)
		);
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	private function incoming_fields( array $body ): array|WP_Error {
		$fields = $body['fields'] ?? null;
		if ( ! is_array( $fields ) || array() === $fields ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Provide a non-empty "fields" object.', 400 );
		}
		return $fields;
	}

	private function normalize_post_key( string $key ): string {
		if ( str_starts_with( $key, '_yoast_wpseo_' ) ) {
			return substr( $key, strlen( '_yoast_wpseo_' ) );
		}
		if ( str_starts_with( $key, 'yoast_wpseo_' ) ) {
			return substr( $key, strlen( 'yoast_wpseo_' ) );
		}
		return $key;
	}

	private function normalize_term_key( string $key ): string {
		if ( str_starts_with( $key, 'wpseo_' ) ) {
			$key = substr( $key, 6 );
		}
		if ( 'metadesc' === $key || 'description' === $key ) {
			return 'desc';
		}
		return $key;
	}

	/**
	 * @param array<string, mixed> $raw
	 * @return array<string, string>
	 */
	private function normalize_term_fields( array $raw ): array {
		$out = array();
		foreach ( self::TERM_KEYS as $key ) {
			$prefixed     = 'wpseo_' . $key;
			$out[ $key ] = isset( $raw[ $prefixed ] ) ? (string) $raw[ $prefixed ] : '';
		}
		return $out;
	}

	private function resolve_term( int $id, string $taxonomy ): WP_Term|WP_Error {
		if ( $id < 1 ) {
			return $this->error( 'mcp_bridge_not_found', 'Term not found.', 404 );
		}
		if ( '' === $taxonomy ) {
			$term = get_term( $id );
		} else {
			$term = get_term( $id, $taxonomy );
		}
		if ( ! $term || is_wp_error( $term ) ) {
			return $this->error( 'mcp_bridge_not_found', 'Term not found.', 404 );
		}
		return $term;
	}

	/**
	 * @param array<string, string> $fields
	 * @return array<string, mixed>
	 */
	private function post_payload( WP_Post $post, array $fields ): array {
		$required = array( 'focuskw', 'title', 'metadesc' );
		$gaps     = array();
		foreach ( $required as $key ) {
			if ( '' === (string) ( $fields[ $key ] ?? '' ) ) {
				$gaps[] = $key;
			}
		}
		return array(
			'id'          => (int) $post->ID,
			'post_type'   => $post->post_type,
			'post_status' => $post->post_status,
			'post_title'  => $post->post_title,
			'permalink'   => (string) get_permalink( $post ),
			'fields'      => $fields,
			'gaps'        => $gaps,
		);
	}

	/**
	 * @param array<string, string> $fields
	 * @return array<string, mixed>
	 */
	private function term_payload( WP_Term $term, array $fields ): array {
		$gaps = array();
		foreach ( array( 'focuskw', 'title', 'desc' ) as $key ) {
			if ( '' === (string) ( $fields[ $key ] ?? '' ) ) {
				$gaps[] = $key;
			}
		}
		return array(
			'id'       => (int) $term->term_id,
			'taxonomy' => $term->taxonomy,
			'name'     => $term->name,
			'slug'     => $term->slug,
			'fields'   => $fields,
			'gaps'     => $gaps,
		);
	}

	private function rebuild_indexable( int $object_id, string $object_type ): bool {
		return Ovp_Yoast_Indexable::rebuild( $object_id, $object_type )['ok'];
	}

	private function is_scalar_or_null( mixed $value ): bool {
		return null === $value || is_scalar( $value );
	}

	private function stringify_value( mixed $value ): string {
		if ( null === $value ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		return (string) $value;
	}

	private function coerce_post_value( string $key, mixed $value ): string {
		if ( 'is_cornerstone' === $key ) {
			return $this->as_flag_1( $value );
		}
		if ( 'meta-robots-noindex' === $key || 'meta-robots-nofollow' === $key ) {
			return $this->as_robots_digit( $key, $value );
		}
		return $this->stringify_value( $value );
	}

	private function coerce_term_value( string $key, mixed $value ): string {
		if ( 'is_cornerstone' === $key ) {
			return $this->as_flag_1( $value );
		}
		if ( 'noindex' === $key ) {
			return $this->as_term_noindex( $value );
		}
		return $this->stringify_value( $value );
	}

	private function as_flag_1( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		$s = strtolower( trim( (string) $value ) );
		return in_array( $s, array( '1', 'true', 'on', 'yes' ), true ) ? '1' : '0';
	}

	private function as_robots_digit( string $key, mixed $value ): string {
		if ( is_bool( $value ) ) {
			if ( 'meta-robots-noindex' === $key ) {
				return $value ? '1' : '2';
			}
			return $value ? '1' : '0';
		}
		return trim( (string) $value );
	}

	private function as_term_noindex( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'noindex' : 'index';
		}
		$s   = strtolower( trim( (string) $value ) );
		$map = array(
			'0'       => 'default',
			'default' => 'default',
			'2'       => 'index',
			'index'   => 'index',
			'1'       => 'noindex',
			'noindex' => 'noindex',
			'true'    => 'noindex',
			'false'   => 'index',
		);
		return $map[ $s ] ?? 'default';
	}

	private function yoast_key_is_persistable( string $key, string $group ): bool {
		if ( 'wpseo_taxonomy_meta' === $group ) {
			return false;
		}
		if ( '' !== $group && false === WPSEO_Options::get_option_instance( $group ) ) {
			return false;
		}
		$ref      = new ReflectionClass( 'WPSEO_Options' );
		$lookup_m = $ref->getMethod( 'get_lookup_table' );
		$lookup_m->setAccessible( true );
		$lookup = $lookup_m->invoke( null, $group );
		if ( is_array( $lookup ) && isset( $lookup[ $key ] ) ) {
			return 'wpseo_taxonomy_meta' !== $lookup[ $key ];
		}
		$pattern_m = $ref->getMethod( 'get_pattern_table' );
		$pattern_m->setAccessible( true );
		$patterns = $pattern_m->invoke( null );
		if ( ! is_array( $patterns ) ) {
			return false;
		}
		foreach ( $patterns as $pattern => $option ) {
			unset( $option );
			if ( strpos( $key, (string) $pattern ) === 0 ) {
				return true;
			}
		}
		return false;
	}
}

/**
 * Yoast indexable upkeep (D8). One object has ONE row in wp_yoast_indexable. Writes and reindexes
 * must find that row and update it, and any duplicate rows an earlier run left behind are removed
 * (newest row wins).
 */
final class Ovp_Yoast_Indexable {

	/** Remove duplicate rows for one object. Returns how many rows were deleted. */
	public static function dedupe( int $object_id, string $object_type ): int {
		global $wpdb;
		if ( ! is_object( $wpdb ) || $object_id < 1 || ! in_array( $object_type, array( 'post', 'term', 'user' ), true ) ) {
			return 0;
		}
		$table = $wpdb->prefix . 'yoast_indexable';
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE object_id = %d AND object_type = %s ORDER BY id DESC", $object_id, $object_type ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is prefix + constant.
		if ( ! is_array( $ids ) || count( $ids ) < 2 ) {
			return 0;
		}
		$drop = array_map( 'intval', array_slice( $ids, 1 ) ); // Keep the newest (highest id).
		$list = implode( ',', $drop );
		$wpdb->query( "DELETE FROM {$table}_hierarchy WHERE indexable_id IN ({$list})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ints only.
		$wpdb->query( "DELETE FROM {$table} WHERE id IN ({$list})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ints only.
		return count( $drop );
	}

	/** Remove duplicates site-wide (one row per object_id + object_type + sub type). Returns rows deleted. */
	public static function dedupe_all(): int {
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return 0;
		}
		$table = $wpdb->prefix . 'yoast_indexable';
		$n     = $wpdb->query( "DELETE a FROM {$table} a INNER JOIN {$table} b ON a.object_id = b.object_id AND a.object_type = b.object_type AND a.object_sub_type <=> b.object_sub_type AND a.id < b.id WHERE a.object_id IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is prefix + constant.
		return is_int( $n ) ? $n : 0;
	}

	/**
	 * Update the single indexable of an object (passes the existing row to the builder so it updates
	 * instead of inserting), then drop any duplicates.
	 *
	 * @return array{ok:bool,removed:int}
	 */
	public static function rebuild( int $object_id, string $object_type ): array {
		if ( ! function_exists( 'YoastSEO' ) ) {
			return array( 'ok' => false, 'removed' => 0 );
		}
		$removed = self::dedupe( $object_id, $object_type );
		try {
			$classes  = YoastSEO()->classes;
			$existing = false;
			try {
				$repo     = $classes->get( \Yoast\WP\SEO\Repositories\Indexable_Repository::class );
				$existing = $repo->find_by_id_and_type( $object_id, $object_type, false );
			} catch ( \Throwable $e ) {
				$existing = false; // No repository: the builder still looks the row up itself.
			}
			$builder = $classes->get( \Yoast\WP\SEO\Builders\Indexable_Builder::class );
			if ( is_object( $existing ) ) {
				$builder->build_for_id_and_type( $object_id, $object_type, $existing );
			} else {
				$builder->build_for_id_and_type( $object_id, $object_type );
			}
			$removed += self::dedupe( $object_id, $object_type );
			return array( 'ok' => true, 'removed' => $removed );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'removed' => $removed );
		}
	}
}
