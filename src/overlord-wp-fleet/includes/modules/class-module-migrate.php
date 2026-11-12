<?php
/**
 * /ovp/v1/content/export, content/import, menus/export, menus/import (1.7.0).
 * Selective, lossless post + menu migration between two sites.
 *
 * Meta is exported as the exact stored string and written back with $wpdb (not
 * update_post_meta): maybe_serialize() would double-serialize a serialized string and
 * sanitize_key()/wp_unslash() would mangle keys and backslashes. Unmapped attachment
 * ids block a post instead of silently pointing at the wrong image.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ovp_Module_Migrate extends Ovp_Module_Base {

	private const EXPORT_MAX_IDS   = 25;
	private const EXPORT_PAGE_JSON = 3000000;
	private const SKIP_TYPES       = array( 'attachment', 'revision', 'nav_menu_item', 'auto-draft' );

	public function register_routes(): void {
		$this->route( 'POST', 'content/export', array( $this, 'export_posts' ) );
		$this->route( 'POST', 'content/import', array( $this, 'import_posts' ) );
		$this->route( 'POST', 'menus/export', array( $this, 'export_menus' ) );
		$this->route( 'POST', 'menus/import', array( $this, 'import_menus' ) );
	}

	// -- export posts -------------------------------------------------------------

	/**
	 * Body: ids[] (max 25), include[] (meta, terms, attachments; default all),
	 * meta_exclude[] (default Ovp_Migrate_Util::DEFAULT_META_EXCLUDE), cursor.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request
	 */
	public function export_posts( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = (array) $request->get_json_params();
		$ids  = array();
		foreach ( is_array( $body['ids'] ?? null ) ? $body['ids'] : array() as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$ids[] = (int) $id;
			}
		}
		$ids = array_values( array_unique( $ids ) );
		if ( array() === $ids || count( $ids ) > self::EXPORT_MAX_IDS ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Provide ids[] (1-' . self::EXPORT_MAX_IDS . ').', 400 );
		}
		$include = $this->str_list( $body['include'] ?? null );
		if ( array() === $include ) {
			$include = array( 'meta', 'terms', 'attachments' );
		}
		$exclude = array_key_exists( 'meta_exclude', $body )
			? $this->str_list( $body['meta_exclude'] )
			: Ovp_Migrate_Util::DEFAULT_META_EXCLUDE;

		$start = max( 0, (int) ( $body['cursor'] ?? 0 ) );
		$posts = array();
		$miss  = array();
		$size  = 0;
		$next  = '';
		for ( $i = $start, $n = count( $ids ); $i < $n; $i++ ) {
			$one = Ovp_Migrate_Util::export_post( $ids[ $i ], $exclude, $include );
			if ( null === $one ) {
				$miss[] = $ids[ $i ];
				continue;
			}
			$len = strlen( (string) wp_json_encode( $one ) );
			if ( array() !== $posts && $size + $len > self::EXPORT_PAGE_JSON ) {
				$next = (string) $i;
				break;
			}
			$posts[] = $one;
			$size   += $len;
		}
		return $this->success(
			Ovp_Chunk::envelope(
				array(
					'exported_at_gmt' => gmdate( 'Y-m-d H:i:s' ),
					'site'            => home_url(),
					'posts'           => $posts,
					'missing'         => $miss,
				),
				$next
			)
		);
	}

	// -- import posts -------------------------------------------------------------

	/**
	 * Body: posts[] (export shape), id_map {old: new}, dry_run (default true), confirm,
	 * options {match_by id|slug, create_missing, preserve_ids, protected_ids[],
	 * modified_since 'Y-m-d H:i:s' GMT, drift_guard skip|abort|off, force, status_override,
	 * meta_mode merge|replace, meta_exclude[], rewrite_hosts {from: to}, allow_unmapped,
	 * author_id, create_terms, preserve_modified, batch}.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request
	 */
	public function import_posts( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}
		$body    = (array) $request->get_json_params();
		$dry     = ! array_key_exists( 'dry_run', $body ) || (bool) $body['dry_run'];
		$posts   = is_array( $body['posts'] ?? null ) ? $body['posts'] : array();
		$options = is_array( $body['options'] ?? null ) ? $body['options'] : array();
		if ( array() === $posts ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Provide posts[].', 400 );
		}
		$id_map = Ovp_Migrate_Util::id_map( $body['id_map'] ?? null );

		$opt = array(
			'match_by'          => 'id' === ( $options['match_by'] ?? 'slug' ) ? 'id' : 'slug',
			'create_missing'    => ! empty( $options['create_missing'] ),
			'preserve_ids'      => ! empty( $options['preserve_ids'] ),
			'protected'         => array_map( 'intval', is_array( $options['protected_ids'] ?? null ) ? $options['protected_ids'] : array() ),
			'modified_since'    => isset( $options['modified_since'] ) ? (string) $options['modified_since'] : '',
			'drift_guard'       => in_array( $options['drift_guard'] ?? 'skip', array( 'skip', 'abort', 'off' ), true ) ? (string) ( $options['drift_guard'] ?? 'skip' ) : 'skip',
			'force'             => ! empty( $options['force'] ),
			'status_override'   => isset( $options['status_override'] ) ? sanitize_key( (string) $options['status_override'] ) : '',
			'meta_mode'         => 'replace' === ( $options['meta_mode'] ?? 'merge' ) ? 'replace' : 'merge',
			'meta_exclude'      => array_key_exists( 'meta_exclude', $options ) ? $this->str_list( $options['meta_exclude'] ) : Ovp_Migrate_Util::DEFAULT_META_EXCLUDE,
			'hosts'             => Ovp_Migrate_Util::host_map( $options['rewrite_hosts'] ?? null ),
			'allow_unmapped'    => ! empty( $options['allow_unmapped'] ),
			'author_id'         => (int) ( $options['author_id'] ?? 0 ),
			'create_terms'      => ! array_key_exists( 'create_terms', $options ) || (bool) $options['create_terms'],
			'preserve_modified' => ! array_key_exists( 'preserve_modified', $options ) || (bool) $options['preserve_modified'],
			'batch'             => isset( $options['batch'] ) ? (string) $options['batch'] : 'import-' . gmdate( 'Ymd\THis\Z' ),
				'tier'              => OVP_Meta::request_tier( $request ),
		);
		if ( ! $dry && empty( $body['confirm'] ) ) {
			return $this->error( 'mcp_bridge_confirm_required', 'content_import_posts apply needs confirm=true (dry_run defaults true).', 403 );
		}

		// Plan every post first so drift_guard=abort can refuse before any write.
		$plans = array();
		foreach ( $posts as $payload ) {
			if ( is_array( $payload ) ) {
				$plans[] = $this->plan_post( $payload, $id_map, $opt );
			}
		}
		if ( 'abort' === $opt['drift_guard'] && ! $opt['force'] ) {
			foreach ( $plans as $plan ) {
				if ( 'drift' === ( $plan['block'] ?? '' ) ) {
					return $this->error( 'mcp_bridge_drift', 'Target post ' . (int) $plan['target_id'] . ' was modified after modified_since; nothing written.', 409 );
				}
			}
		}

		$results = array();
		$new_map = array();
		foreach ( $plans as $plan ) {
			$result = array(
				'old_id'    => $plan['old_id'],
				'post_type' => $plan['type'],
				'slug'      => $plan['slug'],
				'action'    => $plan['action'],
				'target_id' => $plan['target_id'],
				'blocked'   => $plan['block'],
				'warnings'  => $plan['warnings'],
				'unmapped'  => $plan['unmapped'],
				'meta_keys' => count( $plan['meta'] ),
				'remapped'  => $plan['remapped'],
			);
			if ( '' !== $plan['block'] || 'skip' === $plan['action'] ) {
				$results[] = $result;
				continue;
			}
			if ( $dry ) {
				$results[] = $result;
				continue;
			}
			$applied = $this->apply_plan( $plan, $opt );
			if ( $applied instanceof WP_Error ) {
				$result['action'] = 'error';
				$result['error']  = $applied->get_error_message();
				$results[]        = $result;
				continue;
			}
			$result['new_id']   = $applied['id'];
			$result['snapshot'] = $applied['snapshot'];
			$result['before']   = $applied['before_inline'];
			$new_map[ (string) $plan['old_id'] ] = $applied['id'];
			$results[]                          = $result;
		}
		return $this->success(
			array(
				'dry_run'  => $dry,
				'batch'    => $opt['batch'],
				'results'  => $results,
				'id_map'   => $new_map,
				'snapshot' => $dry ? null : 'uploads/mcp-bridge/backups/' . preg_replace( '/[^A-Za-z0-9_-]/', '', $opt['batch'] ) . '.jsonl',
			)
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<int, int>      $id_map
	 * @param array<string, mixed> $opt
	 * @return array<string, mixed>
	 */
	private function plan_post( array $payload, array $id_map, array $opt ): array {
		global $wpdb;
		$fields = is_array( $payload['post'] ?? null ) ? $payload['post'] : array();
		foreach ( is_array( $payload['post_b64'] ?? null ) ? $payload['post_b64'] : array() as $col => $b ) {
			$dec = is_string( $b ) ? base64_decode( $b, true ) : false;
			if ( false !== $dec ) {
				$fields[ (string) $col ] = $dec;
			}
		}
		$old_id = (int) ( $payload['id'] ?? $fields['ID'] ?? 0 );
		$type   = (string) ( $fields['post_type'] ?? '' );
		$slug   = (string) ( $fields['post_name'] ?? '' );
		$plan   = array(
			'old_id'    => $old_id,
			'type'      => $type,
			'slug'      => $slug,
			'fields'    => $fields,
			'payload'   => $payload,
			'action'    => 'skip',
			'target_id' => 0,
			'block'     => '',
			'warnings'  => array(),
			'unmapped'  => array(),
			'remapped'  => 0,
			'meta'      => array(),
			'terms'     => is_array( $payload['terms'] ?? null ) ? $payload['terms'] : array(),
			'parent_id' => 0,
		);

		if ( '' === $type || in_array( $type, self::SKIP_TYPES, true ) || ! post_type_exists( $type ) ) {
			$plan['block'] = 'unsupported_post_type:' . $type;
			return $plan;
		}

		// Target lookup.
		$target = 0;
		if ( 'id' === $opt['match_by'] ) {
			$existing = get_post( $old_id );
			if ( $existing instanceof WP_Post ) {
				if ( $existing->post_type !== $type ) {
					$plan['block'] = 'id_type_mismatch:' . $existing->post_type;
					return $plan;
				}
				$target = (int) $existing->ID;
			}
		} elseif ( '' !== $slug ) {
			$found = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s AND post_status NOT IN ('auto-draft','inherit') ORDER BY ID ASC LIMIT 3",
					$slug,
					$type
				)
			);
			if ( is_array( $found ) && count( $found ) > 1 ) {
				$plan['block'] = 'ambiguous_slug';
				return $plan;
			}
			$target = is_array( $found ) && isset( $found[0] ) ? (int) $found[0] : 0;
		}
		$plan['target_id'] = $target;

		if ( $target > 0 && in_array( $target, $opt['protected'], true ) ) {
			$plan['block'] = 'protected_id';
			return $plan;
		}
		if ( $target > 0 && 'off' !== $opt['drift_guard'] && '' !== $opt['modified_since'] ) {
			$tp = get_post( $target );
			$tgt_gmt = $tp instanceof WP_Post ? $this->modified_gmt( $tp->post_modified, $tp->post_modified_gmt ) : '';
			if ( $tp instanceof WP_Post && $tgt_gmt > $opt['modified_since'] && ! $opt['force'] ) {
				$plan['block']      = 'drift';
				$plan['warnings'][] = 'target modified (GMT) ' . $tgt_gmt . ' > ' . $opt['modified_since'];
				return $plan;
			}
		}
		if ( 0 === $target ) {
			if ( ! $opt['create_missing'] ) {
				$plan['block'] = 'missing_target';
				return $plan;
			}
			$plan['action'] = 'create';
		} else {
			$plan['action'] = 'update';
		}

		// Auto-resolve attachment ids from payload.attachments by _wp_attached_file.
		$id_map = $this->auto_map( $payload, $id_map, $plan );

		// Parent.
		$parent_old = (int) ( $fields['post_parent'] ?? 0 );
		if ( $parent_old > 0 ) {
			if ( isset( $id_map[ $parent_old ] ) ) {
				$plan['parent_id'] = $id_map[ $parent_old ];
			} else {
				$par = is_array( $payload['parent'] ?? null ) ? $payload['parent'] : array();
				$pid = 0;
				if ( isset( $par['post_name'], $par['post_type'] ) ) {
					$pid = (int) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s AND post_status NOT IN ('auto-draft','inherit') ORDER BY ID ASC LIMIT 1",
							(string) $par['post_name'],
							(string) $par['post_type']
						)
					);
				}
				if ( $pid > 0 ) {
					$plan['parent_id']  = $pid;
					$plan['warnings'][] = 'post_parent resolved by slug ' . $par['post_name'] . ' -> ' . $pid;
				} else {
					$plan['unmapped'][] = $parent_old;
				}
			}
		}

		// Meta.
		$unmapped = array();
		$remapped = 0;
		foreach ( is_array( $payload['meta'] ?? null ) ? $payload['meta'] : array() as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['key'] ) ) {
				continue;
			}
			$key = (string) $item['key'];
			if ( in_array( $key, $opt['meta_exclude'], true ) ) {
				continue;
			}
			// Imported postmeta obeys the OVP_Meta policy: hard-blocked keys are dropped, structural keys need T3.
			if ( OVP_Meta::is_hard_blocked( $key ) ) {
				$plan['warnings'][] = 'meta ' . $key . ' is protected; dropped';
				continue;
			}
			if ( OVP_Meta::is_structural( $key ) && OVP_Upload::tier_rank( (string) ( $opt['tier'] ?? 'T0' ) ) < OVP_Upload::tier_rank( 'T3' ) ) {
				$plan['warnings'][] = 'meta ' . $key . ' is structural (T3); dropped';
				continue;
			}
			$val = Ovp_Migrate_Util::unpack( $item );
			if ( null !== $val ) {
				if ( in_array( $key, Ovp_Migrate_Util::IMAGE_ID_KEYS, true ) && ctype_digit( $val ) && (int) $val > 0 ) {
					$old = (int) $val;
					if ( isset( $id_map[ $old ] ) ) {
						$val = (string) $id_map[ $old ];
						++$remapped;
					} else {
						$unmapped[] = $old;
					}
				} elseif ( '_product_image_gallery' === $key ) {
					$parts = array();
					foreach ( explode( ',', $val ) as $v ) {
						$old = (int) $v;
						if ( $old < 1 ) {
							continue;
						}
						if ( isset( $id_map[ $old ] ) ) {
							$parts[] = (string) $id_map[ $old ];
							++$remapped;
						} else {
							$unmapped[] = $old;
							$parts[]    = (string) $old;
						}
					}
					$val = implode( ',', $parts );
				} elseif ( '_elementor_data' === $key ) {
					$un  = array();
					$new = Ovp_Migrate_Util::remap_elementor( $val, $id_map, $un );
					if ( $new instanceof WP_Error ) {
						$ids = Ovp_Migrate_Util::elementor_ids( $val );
						if ( array() !== $ids ) {
							$plan['block'] = 'elementor_inexact';
							return $plan;
						}
					} else {
						if ( $new !== $val ) {
							++$remapped;
						}
						$val = $new;
					}
					$unmapped = array_merge( $unmapped, $un );
				}
				if ( array() !== $opt['hosts'] ) {
					if ( is_serialized( $val ) ) {
						if ( preg_match( '/' . implode( '|', array_map( static fn( string $h ): string => preg_quote( $h, '/' ), array_keys( $opt['hosts'] ) ) ) . '/i', $val ) ) {
							$plan['warnings'][] = 'serialized meta ' . $key . ' contains a source host; not rewritten';
						}
					} else {
						$val = Ovp_Migrate_Util::rewrite_hosts( $val, $opt['hosts'] );
					}
				}
			}
			// get_post_meta() runs maybe_unserialize: refuse serialized object payloads (PHP object injection).
			if ( null !== $val && ( OVP_Meta::contains_object_payload( $val ) || ( OVP_Meta::looks_serialized( $val ) && OVP_Meta::safe_unserialize( $val )['objects'] ) ) ) {
				$plan['block'] = 'meta_object_payload:' . $key;
				return $plan;
			}
			$plan['meta'][] = array( 'key' => $key, 'value' => $val );
		}

		// Post content ids + hosts.
		$content = (string) ( $fields['post_content'] ?? '' );
		$un      = array();
		$new     = Ovp_Migrate_Util::remap_content( $content, $id_map, $un );
		if ( $new !== $content ) {
			++$remapped;
		}
		$unmapped = array_merge( $unmapped, $un );
		if ( array() !== $opt['hosts'] ) {
			$new = Ovp_Migrate_Util::rewrite_hosts( $new, $opt['hosts'] );
		}
		$plan['fields']['post_content'] = $new;
		foreach ( array( 'post_excerpt', 'post_title' ) as $f ) {
			if ( isset( $plan['fields'][ $f ] ) && array() !== $opt['hosts'] ) {
				$plan['fields'][ $f ] = Ovp_Migrate_Util::rewrite_hosts( (string) $plan['fields'][ $f ], $opt['hosts'] );
			}
		}

		$plan['unmapped'] = array_values( array_unique( array_merge( $plan['unmapped'], $unmapped ) ) );
		$plan['remapped'] = $remapped;
		$plan['id_map']   = $id_map;

		// Mapped targets must exist on this site.
		foreach ( array_unique( $this->mapped_in_use( $plan ) ) as $used ) {
			if ( ! get_post( $used ) instanceof WP_Post ) {
				$plan['warnings'][] = 'mapped target id ' . $used . ' does not exist on this site';
				$plan['block']      = 'missing_mapped_target';
			}
		}
		if ( array() !== $plan['unmapped'] && ! $opt['allow_unmapped'] && '' === $plan['block'] ) {
			$plan['block'] = 'unmapped_refs';
		}
		return $plan;
	}

	/**
	 * Ids written into this plan (post_parent + remapped refs) for existence checking.
	 *
	 * @param array<string, mixed> $plan
	 * @return array<int, int>
	 */
	private function mapped_in_use( array $plan ): array {
		$used = array();
		foreach ( $plan['meta'] as $m ) {
			if ( in_array( $m['key'], Ovp_Migrate_Util::IMAGE_ID_KEYS, true ) && is_string( $m['value'] ) && ctype_digit( $m['value'] ) ) {
				$used[] = (int) $m['value'];
			}
		}
		if ( $plan['parent_id'] > 0 ) {
			$used[] = (int) $plan['parent_id'];
		}
		return $used;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<int, int>      $id_map
	 * @param array<string, mixed> $plan
	 * @return array<int, int>
	 */
	private function auto_map( array $payload, array $id_map, array &$plan ): array {
		global $wpdb;
		$atts = is_array( $payload['attachments'] ?? null ) ? $payload['attachments'] : array();
		foreach ( $atts as $old => $att ) {
			$old = (int) $old;
			if ( isset( $id_map[ $old ] ) || ! is_array( $att ) ) {
				continue;
			}
			$file = (string) ( $att['attached_file'] ?? '' );
			if ( '' === $file ) {
				continue;
			}
			$hit = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s ORDER BY post_id ASC LIMIT 1",
					$file
				)
			);
			if ( $hit > 0 ) {
				$id_map[ $old ]     = $hit;
				$plan['warnings'][] = 'attachment ' . $old . ' auto-mapped to ' . $hit . ' by _wp_attached_file ' . $file;
			}
		}
		return $id_map;
	}

	/**
	 * @param array<string, mixed> $plan
	 * @param array<string, mixed> $opt
	 * @return array{id:int,snapshot:string,before_inline:mixed}|WP_Error
	 */
	private function apply_plan( array $plan, array $opt ): array|WP_Error {
		global $wpdb;
		$fields    = $plan['fields'];
		$target    = (int) $plan['target_id'];
		$snapshot  = '';
		$before_in = null;

		if ( $target > 0 ) {
			$before = Ovp_Migrate_Util::export_post( $target, array(), array( 'meta', 'terms' ) );
			if ( null !== $before ) {
				$snapshot = Ovp_Migrate_Util::snapshot_append( (string) $opt['batch'], array( 'kind' => 'post_before', 'id' => $target, 'old_id' => $plan['old_id'], 'data' => $before ) );
				$len      = strlen( (string) wp_json_encode( $before ) );
				if ( $len <= 200000 ) {
					$before_in = $before;
				}
			}
		}

		$status = '' !== $opt['status_override'] ? $opt['status_override'] : (string) ( $fields['post_status'] ?? 'draft' );
		$arr    = array(
			'post_type'             => $plan['type'],
			'post_title'            => (string) ( $fields['post_title'] ?? '' ),
			'post_content'          => (string) ( $fields['post_content'] ?? '' ),
			'post_excerpt'          => (string) ( $fields['post_excerpt'] ?? '' ),
			'post_status'           => $status,
			'post_name'             => (string) ( $fields['post_name'] ?? '' ),
			'post_parent'           => (int) $plan['parent_id'],
			'menu_order'            => (int) ( $fields['menu_order'] ?? 0 ),
			'comment_status'        => (string) ( $fields['comment_status'] ?? 'closed' ),
			'ping_status'           => (string) ( $fields['ping_status'] ?? 'closed' ),
			'post_password'         => (string) ( $fields['post_password'] ?? '' ),
			'post_content_filtered' => (string) ( $fields['post_content_filtered'] ?? '' ),
		);
		if ( ! empty( $fields['post_date'] ) && '0000-00-00 00:00:00' !== $fields['post_date'] ) {
			$arr['post_date']     = (string) $fields['post_date'];
			$arr['post_date_gmt'] = (string) ( $fields['post_date_gmt'] ?? '' );
			$arr['edit_date']     = true;
		}
		if ( $target > 0 ) {
			$arr['ID'] = $target;
		} else {
			$author = (int) $opt['author_id'];
			if ( $author < 1 ) {
				$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'fields' => 'ID', 'exclude' => array( (int) get_current_user_id() ) ) );
				$author = isset( $admins[0] ) ? (int) $admins[0] : 1;
			}
			$arr['post_author'] = $author;
			if ( $opt['preserve_ids'] && $plan['old_id'] > 0 && ! get_post( (int) $plan['old_id'] ) instanceof WP_Post ) {
				$arr['import_id'] = (int) $plan['old_id'];
			}
		}

		// Only god mode (T3) may bypass kses; T2 imports go through the normal content filters.
		$unfiltered = OVP_Upload::tier_rank( (string) ( $opt['tier'] ?? 'T0' ) ) >= OVP_Upload::tier_rank( 'T3' );
		if ( $unfiltered ) {
			kses_remove_filters();
		}
		add_filter( 'wp_revisions_to_keep', '__return_zero' );
		$new_id = wp_insert_post( wp_slash( $arr ), true );
		remove_filter( 'wp_revisions_to_keep', '__return_zero' );
		if ( $unfiltered ) {
			kses_init_filters();
		}
		if ( $new_id instanceof WP_Error ) {
			return $new_id;
		}
		$new_id = (int) $new_id;

		if ( $opt['preserve_modified'] && ! empty( $fields['post_modified'] ) ) {
			$wpdb->update(
				$wpdb->posts,
				array( 'post_modified' => (string) $fields['post_modified'], 'post_modified_gmt' => $this->modified_gmt( (string) $fields['post_modified'], (string) ( $fields['post_modified_gmt'] ?? '' ) ) ),
				array( 'ID' => $new_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}

		// Meta: exact rows via $wpdb.
		$keys = array();
		foreach ( $plan['meta'] as $m ) {
			$keys[ $m['key'] ] = true;
		}
		if ( 'replace' === $opt['meta_mode'] ) {
			$keep = array_merge( Ovp_Migrate_Util::DEFAULT_META_EXCLUDE, $opt['meta_exclude'] );
			$have = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE post_id = %d", $new_id ) );
			foreach ( is_array( $have ) ? $have : array() as $hk ) {
				if ( ! isset( $keys[ (string) $hk ] ) && ! in_array( (string) $hk, $keep, true ) ) {
					$wpdb->delete( $wpdb->postmeta, array( 'post_id' => $new_id, 'meta_key' => (string) $hk ), array( '%d', '%s' ) );
				}
			}
		}
		foreach ( array_keys( $keys ) as $k ) {
			$wpdb->delete( $wpdb->postmeta, array( 'post_id' => $new_id, 'meta_key' => (string) $k ), array( '%d', '%s' ) );
		}
		foreach ( $plan['meta'] as $m ) {
			$wpdb->insert(
				$wpdb->postmeta,
				array( 'post_id' => $new_id, 'meta_key' => $m['key'], 'meta_value' => $m['value'] ),
				array( '%d', '%s', '%s' )
			);
		}
		foreach ( Ovp_Migrate_Util::DEFAULT_META_EXCLUDE as $derived ) {
			if ( str_starts_with( $derived, '_elementor' ) ) {
				$wpdb->delete( $wpdb->postmeta, array( 'post_id' => $new_id, 'meta_key' => $derived ), array( '%d', '%s' ) );
			}
		}
		wp_cache_delete( $new_id, 'post_meta' );
		clean_post_cache( $new_id );

		// Terms.
		$by_tax = array();
		foreach ( $plan['terms'] as $t ) {
			if ( is_array( $t ) && isset( $t['taxonomy'], $t['slug'] ) && 'nav_menu' !== $t['taxonomy'] ) {
				$by_tax[ (string) $t['taxonomy'] ][] = $t;
			}
		}
		foreach ( $by_tax as $tax => $list ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
			$ids = array();
			foreach ( $list as $t ) {
				$tid = $this->ensure_term( (string) $tax, $t, (bool) $opt['create_terms'] );
				if ( $tid > 0 ) {
					$ids[] = $tid;
				}
			}
			wp_set_object_terms( $new_id, $ids, (string) $tax, false );
		}
		return array( 'id' => $new_id, 'snapshot' => $snapshot, 'before_inline' => $before_in );
	}

	/**
	 * GMT modified time; drafts can carry a 0000 GMT, so derive it from the local time.
	 */
	private function modified_gmt( string $local, string $gmt ): string {
		if ( '' !== $gmt && '0000-00-00 00:00:00' !== $gmt ) {
			return $gmt;
		}
		return get_gmt_from_date( $local );
	}

	/**
	 * @param array<string, mixed> $t
	 */
	private function ensure_term( string $tax, array $t, bool $create ): int {
		$slug = (string) $t['slug'];
		$term = get_term_by( 'slug', $slug, $tax );
		if ( $term instanceof WP_Term ) {
			return (int) $term->term_id;
		}
		if ( ! $create ) {
			return 0;
		}
		$args = array( 'slug' => $slug, 'description' => (string) ( $t['description'] ?? '' ) );
		$pslug = (string) ( $t['parent'] ?? '' );
		if ( '' !== $pslug && is_taxonomy_hierarchical( $tax ) ) {
			$parent = get_term_by( 'slug', $pslug, $tax );
			if ( $parent instanceof WP_Term ) {
				$args['parent'] = (int) $parent->term_id;
			}
		}
		$res = wp_insert_term( (string) ( $t['name'] ?? $slug ), $tax, $args );
		return is_array( $res ) ? (int) $res['term_id'] : 0;
	}

	// -- menus ---------------------------------------------------------------------

	/**
	 * Body: menus[] (slugs; default all).
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request
	 */
	public function export_menus( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body  = (array) $request->get_json_params();
		$slugs = $this->str_list( $body['menus'] ?? null );
		$menus = wp_get_nav_menus();
		$out   = array();
		foreach ( $menus as $menu ) {
			if ( array() !== $slugs && ! in_array( $menu->slug, $slugs, true ) ) {
				continue;
			}
			$out[] = $this->export_menu( $menu );
		}
		$locations = array();
		$assigned  = get_nav_menu_locations();
		foreach ( $assigned as $loc => $menu_id ) {
			$m = wp_get_nav_menu_object( (int) $menu_id );
			if ( $m instanceof WP_Term ) {
				$locations[ (string) $loc ] = $m->slug;
			}
		}
		return $this->success( array( 'menus' => $out, 'locations' => $locations ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function export_menu( WP_Term $menu ): array {
		global $wpdb;
		$items = wp_get_nav_menu_items( $menu->term_id, array( 'post_status' => 'any' ) );
		$list  = array();
		foreach ( is_array( $items ) ? $items : array() as $it ) {
			$meta = array();
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id ASC", (int) $it->ID ), ARRAY_A );
			foreach ( is_array( $rows ) ? $rows : array() as $m ) {
				$meta[] = array( 'key' => (string) $m['meta_key'] ) + Ovp_Migrate_Util::pack( null === $m['meta_value'] ? null : (string) $m['meta_value'] );
			}
			$object = array( 'type' => (string) $it->type, 'object' => (string) $it->object, 'id' => (int) $it->object_id );
			if ( 'post_type' === $it->type ) {
				$op = get_post( (int) $it->object_id );
				if ( $op instanceof WP_Post ) {
					$object['slug']      = $op->post_name;
					$object['post_type'] = $op->post_type;
				}
			} elseif ( 'taxonomy' === $it->type ) {
				$ot = get_term( (int) $it->object_id, (string) $it->object );
				if ( $ot instanceof WP_Term ) {
					$object['slug'] = $ot->slug;
				}
			}
			$list[] = array(
				'id'          => (int) $it->ID,
				'parent'      => (int) $it->menu_item_parent,
				'position'    => (int) $it->menu_order,
				'title'       => (string) $it->title,
				'url'         => (string) $it->url,
				'target'      => (string) $it->target,
				'attr_title'  => (string) $it->attr_title,
				'description' => (string) $it->description,
				'classes'     => is_array( $it->classes ) ? array_values( array_filter( array_map( 'strval', $it->classes ) ) ) : array(),
				'xfn'         => (string) $it->xfn,
				'status'      => (string) $it->post_status,
				'object'      => $object,
				'meta'        => $meta,
			);
		}
		return array(
			'slug'        => $menu->slug,
			'name'        => $menu->name,
			'description' => $menu->description,
			'items'       => $list,
		);
	}

	/**
	 * Body: menus[] (export shape), locations {location: menu slug}, id_map, dry_run (default
	 * true), confirm, options {rewrite_hosts, set_locations (default false), replace (default
	 * true), batch}. Items rebuilt through wp_update_nav_menu_item; objects resolved by
	 * id_map, then by slug (+post_type/taxonomy). Unresolvable objects are reported and skipped.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request
	 */
	public function import_menus( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body    = (array) $request->get_json_params();
		$dry     = ! array_key_exists( 'dry_run', $body ) || (bool) $body['dry_run'];
		$menus   = is_array( $body['menus'] ?? null ) ? $body['menus'] : array();
		$options = is_array( $body['options'] ?? null ) ? $body['options'] : array();
		if ( array() === $menus ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Provide menus[].', 400 );
		}
		if ( ! $dry && empty( $body['confirm'] ) ) {
			return $this->error( 'mcp_bridge_confirm_required', 'menus_import apply needs confirm=true.', 403 );
		}
		require_once ABSPATH . 'wp-admin/includes/nav-menu.php';
		$id_map = Ovp_Migrate_Util::id_map( $body['id_map'] ?? null );
		$hosts  = Ovp_Migrate_Util::host_map( $options['rewrite_hosts'] ?? null );
		$batch  = isset( $options['batch'] ) ? (string) $options['batch'] : 'menus-' . gmdate( 'Ymd\THis\Z' );
		$report = array();

		foreach ( $menus as $menu ) {
			if ( ! is_array( $menu ) || empty( $menu['slug'] ) ) {
				continue;
			}
			$slug     = (string) $menu['slug'];
			$existing = wp_get_nav_menu_object( $slug );
			$row      = array(
				'slug'      => $slug,
				'action'    => $existing instanceof WP_Term ? 'replace_items' : 'create',
				'items_in'  => is_array( $menu['items'] ?? null ) ? count( $menu['items'] ) : 0,
				'created'   => 0,
				'unresolved' => array(),
			);
			if ( ! $dry ) {
				if ( $existing instanceof WP_Term ) {
					$snap = $this->export_menu( $existing );
					Ovp_Migrate_Util::snapshot_append( $batch, array( 'kind' => 'menu_before', 'slug' => $slug, 'data' => $snap ) );
					$menu_id = (int) $existing->term_id;
					foreach ( wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'any' ) ) ?: array() as $old_item ) {
						wp_delete_post( (int) $old_item->ID, true );
					}
				} else {
					$created = wp_update_nav_menu_object( 0, array( 'menu-name' => (string) ( $menu['name'] ?? $slug ), 'description' => (string) ( $menu['description'] ?? '' ), 'slug' => $slug ) );
					if ( is_wp_error( $created ) ) {
						$row['error'] = $created->get_error_message();
						$report[]     = $row;
						continue;
					}
					$menu_id = (int) $created;
				}
			} else {
				$menu_id = 0;
			}

			$items   = is_array( $menu['items'] ?? null ) ? $menu['items'] : array();
			$new_ids = array();
			$pending = $items;
			for ( $pass = 0; $pass < 10 && array() !== $pending; $pass++ ) {
				$left = array();
				foreach ( $pending as $it ) {
					if ( ! is_array( $it ) ) {
						continue;
					}
					$old_parent = (int) ( $it['parent'] ?? 0 );
					if ( $old_parent > 0 && ! isset( $new_ids[ $old_parent ] ) ) {
						$left[] = $it;
						continue;
					}
					$args = $this->menu_item_args( $it, $id_map, $hosts, $new_ids );
					if ( null === $args ) {
						$row['unresolved'][] = array( 'id' => (int) ( $it['id'] ?? 0 ), 'title' => (string) ( $it['title'] ?? '' ) );
						$new_ids[ (int) ( $it['id'] ?? 0 ) ] = -1;
						continue;
					}
					if ( $dry ) {
						$new_ids[ (int) $it['id'] ] = 1;
						++$row['created'];
						continue;
					}
					$new = wp_update_nav_menu_item( $menu_id, 0, $args );
					if ( is_wp_error( $new ) ) {
						$row['unresolved'][] = array( 'id' => (int) $it['id'], 'error' => $new->get_error_message() );
						continue;
					}
					$new_ids[ (int) $it['id'] ] = (int) $new;
					++$row['created'];
					$this->copy_custom_menu_meta( (int) $new, $it );
				}
				if ( count( $left ) === count( $pending ) ) {
					foreach ( $left as $it ) {
						$row['unresolved'][] = array( 'id' => (int) ( $it['id'] ?? 0 ), 'error' => 'parent never resolved' );
					}
					break;
				}
				$pending = $left;
			}
			$report[] = $row;
		}

		$loc_report = array();
		if ( ! empty( $options['set_locations'] ) && is_array( $body['locations'] ?? null ) ) {
			$locs = get_nav_menu_locations();
			foreach ( $body['locations'] as $loc => $slug ) {
				$m = wp_get_nav_menu_object( (string) $slug );
				if ( $m instanceof WP_Term ) {
					$locs[ (string) $loc ]       = (int) $m->term_id;
					$loc_report[ (string) $loc ] = (string) $slug;
				}
			}
			if ( ! $dry ) {
				set_theme_mod( 'nav_menu_locations', $locs );
			}
		}
		return $this->success( array( 'dry_run' => $dry, 'batch' => $batch, 'menus' => $report, 'locations_set' => $loc_report ) );
	}

	/**
	 * @param array<string, mixed> $it
	 * @param array<int, int>      $id_map
	 * @param array<string, string> $hosts
	 * @param array<int, int>      $new_ids
	 * @return array<string, mixed>|null
	 */
	private function menu_item_args( array $it, array $id_map, array $hosts, array $new_ids ): ?array {
		global $wpdb;
		$obj  = is_array( $it['object'] ?? null ) ? $it['object'] : array();
		$type = (string) ( $obj['type'] ?? 'custom' );
		$args = array(
			'menu-item-title'       => (string) ( $it['title'] ?? '' ),
			'menu-item-attr-title'  => (string) ( $it['attr_title'] ?? '' ),
			'menu-item-description' => (string) ( $it['description'] ?? '' ),
			'menu-item-target'      => (string) ( $it['target'] ?? '' ),
			'menu-item-classes'     => implode( ' ', is_array( $it['classes'] ?? null ) ? $it['classes'] : array() ),
			'menu-item-xfn'         => (string) ( $it['xfn'] ?? '' ),
			'menu-item-position'    => (int) ( $it['position'] ?? 0 ),
			'menu-item-status'      => (string) ( $it['status'] ?? 'publish' ),
			'menu-item-type'        => $type,
			'menu-item-object'      => (string) ( $obj['object'] ?? 'custom' ),
		);
		$old_parent = (int) ( $it['parent'] ?? 0 );
		if ( $old_parent > 0 ) {
			if ( ! isset( $new_ids[ $old_parent ] ) || $new_ids[ $old_parent ] < 1 ) {
				return null;
			}
			$args['menu-item-parent-id'] = $new_ids[ $old_parent ];
		}
		if ( 'custom' === $type ) {
			$args['menu-item-url'] = Ovp_Migrate_Util::rewrite_hosts( (string) ( $it['url'] ?? '' ), $hosts );
			return $args;
		}
		$old_obj = (int) ( $obj['id'] ?? 0 );
		$target  = 0;
		if ( isset( $id_map[ $old_obj ] ) ) {
			$target = $id_map[ $old_obj ];
		} elseif ( 'post_type' === $type && ! empty( $obj['slug'] ) ) {
			$target = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s AND post_status NOT IN ('auto-draft','inherit') ORDER BY ID ASC LIMIT 1",
					(string) $obj['slug'],
					(string) ( $obj['post_type'] ?? $obj['object'] ?? 'page' )
				)
			);
		} elseif ( 'taxonomy' === $type && ! empty( $obj['slug'] ) ) {
			$t      = get_term_by( 'slug', (string) $obj['slug'], (string) ( $obj['object'] ?? '' ) );
			$target = $t instanceof WP_Term ? (int) $t->term_id : 0;
		}
		if ( $target < 1 ) {
			return null;
		}
		$args['menu-item-object-id'] = $target;
		return $args;
	}

	/**
	 * Copy non-core menu item meta (core _menu_item_* is handled by wp_update_nav_menu_item).
	 *
	 * @param array<string, mixed> $it
	 */
	private function copy_custom_menu_meta( int $new_id, array $it ): void {
		global $wpdb;
		foreach ( is_array( $it['meta'] ?? null ) ? $it['meta'] : array() as $m ) {
			if ( ! is_array( $m ) || ! isset( $m['key'] ) || str_starts_with( (string) $m['key'], '_menu_item_' ) ) {
				continue;
			}
			$wpdb->insert(
				$wpdb->postmeta,
				array( 'post_id' => $new_id, 'meta_key' => (string) $m['key'], 'meta_value' => Ovp_Migrate_Util::unpack( $m ) ),
				array( '%d', '%s', '%s' )
			);
		}
		wp_cache_delete( $new_id, 'post_meta' );
	}

	/**
	 * @return array<int, string>
	 */
	private function str_list( mixed $raw ): array {
		$out = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $v ) {
				if ( is_string( $v ) && '' !== $v ) {
					$out[] = $v;
				}
			}
		}
		return $out;
	}
}
