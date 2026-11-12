<?php
/**
 * /ovp/v1/sync/options, elementor/regen, cache/purge, yoast/reindex,
 * yoast/redirects (1.7.0). The post-import toolbox: allowlisted option sync with
 * path-level merge, Elementor CSS/conditions regeneration, cache purge, Yoast indexable
 * rebuild and Yoast Premium redirect manager access.
 *
 * Third-party classes (Elementor, Elementor Pro, Yoast, Yoast Premium, LiteSpeed) are
 * only reached through class_exists()/is_callable() guards so a site without them
 * degrades to a reported "unavailable" instead of a fatal.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ovp_Module_Maintenance extends Ovp_Module_Base {

	/** Option names (or prefix* patterns) options_sync may write without extra allow[]. */
	private const DEFAULT_ALLOW = array(
		'wpseo*',
		'blogname',
		'blogdescription',
		'elementor*',
		'category_base',
		'tag_base',
		'permalink_structure',
		'show_on_front',
		'page_on_front',
		'page_for_posts',
		'posts_per_page',
		'date_format',
		'time_format',
		'timezone_string',
		'gmt_offset',
		'start_of_week',
		'nav_menu_options',
		'widget_*',
		'sidebars_widgets',
		'theme_mods_*',
	);

	/** Hard denylist: prefixes (trailing *) and exact names. Checked before the allowlist. */
	private const DENY = array(
		'litespeed*',
		'lscwp*',
		'wordfence*',
		'wf*',
		'googlesitekit*',
		'wp_mail_smtp*',
		'mcp_bridge*',
		'ovp_*',
		'_ovp_*',
		'auth_*',
		'secure_auth_*',
		'logged_in_*',
		'nonce_*',
		'siteurl',
		'home',
		'blog_public',
		'admin_email',
		'default_role',
		'users_can_register',
		'template',
		'stylesheet',
		'cron',
		'db_version',
		'initial_db_version',
		'recently_activated',
		'fresh_site',
	);

	public function register_routes(): void {
		$this->route( 'POST', 'sync/options', array( $this, 'options_sync' ) );
		$this->route( 'POST', 'elementor/regen', array( $this, 'elementor_regen' ) );
		$this->route( 'POST', 'cache/purge', array( $this, 'cache_purge' ) );
		$this->route( 'POST', 'yoast/reindex', array( $this, 'yoast_reindex' ) );
		$this->route( 'GET', 'yoast/redirects', array( $this, 'redirects_get' ) );
		$this->route( 'POST', 'yoast/redirects', array( $this, 'redirects_set' ) );
	}

	// -- options_sync ---------------------------------------------------------------

	/**
	 * Body: items[] {name, op set|merge|delete, value | value_serialized, path (a.b.c or
	 * segment list), explicit (needed for active_plugins)}, allow[] (extra names/prefix*),
	 * dry_run (default true), confirm, rewrite_flush, batch.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request
	 */
	public function options_sync( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;
		$body  = (array) $request->get_json_params();
		$dry   = ! array_key_exists( 'dry_run', $body ) || (bool) $body['dry_run'];
		$items = is_array( $body['items'] ?? null ) ? $body['items'] : array();
		if ( array() === $items || count( $items ) > 100 ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Provide items[] (1-100).', 400 );
		}
		if ( ! $dry && empty( $body['confirm'] ) ) {
			return $this->error( 'mcp_bridge_confirm_required', 'options_sync apply needs confirm=true (dry_run defaults true).', 403 );
		}
		$req_tier = OVP_Meta::request_tier( $request );
		// A caller-supplied allow[] never widens to everything: below T3 bare/near-bare wildcards are dropped.
		$extra = array_values( array_filter(
			$this->strings( $body['allow'] ?? null ),
			static fn( string $p ): bool => OVP_Upload::tier_rank( $req_tier ) >= OVP_Upload::tier_rank( 'T3' ) || strlen( rtrim( $p, '*' ) ) >= 4
		) );
		$allow = array_merge( self::DEFAULT_ALLOW, $extra );
		$batch = isset( $body['batch'] ) ? (string) $body['batch'] : 'options-' . gmdate( 'Ymd\THis\Z' );
		$rows  = array();
		$flush = false;

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$name = (string) ( $item['name'] ?? '' );
			$op   = in_array( $item['op'] ?? 'set', array( 'set', 'merge', 'delete' ), true ) ? (string) ( $item['op'] ?? 'set' ) : 'set';
			$row  = array( 'name' => $name, 'op' => $op );
			if ( '' === $name || ! preg_match( '/^[A-Za-z0-9_\-.:]+$/', $name ) ) {
				$row['status'] = 'refused:bad_name';
				$rows[]        = $row;
				continue;
			}
			$deny = $this->deny_reason( $name );
			if ( '' !== $deny ) {
				$row['status'] = 'refused:denylist:' . $deny;
				$rows[]        = $row;
				continue;
			}
			// Structural options (active_plugins, siteurl, home, template, stylesheet, default_role,
			// users_can_register, *user_roles, ...) are T3-only everywhere; options_sync is T2 and must not bypass OVP_Meta.
			$need = OVP_Meta::required_tier( 'write', 'option', $name );
			if ( null === $need ) {
				$row['status'] = 'refused:denylist:protected';
				$rows[]        = $row;
				continue;
			}
			if ( OVP_Upload::tier_rank( $req_tier ) < OVP_Upload::tier_rank( $need ) ) {
				$row['status'] = 'refused:tier:' . $need . ' required';
				$rows[]        = $row;
				continue;
			}
			if ( 'active_plugins' === $name ) {
				if ( empty( $item['explicit'] ) ) {
					$row['status'] = 'refused:denylist:active_plugins (needs explicit=true)';
					$rows[]        = $row;
					continue;
				}
			} elseif ( ! $this->matches_any( $name, $allow ) ) {
				$row['status'] = 'refused:not_allowlisted';
				$rows[]        = $row;
				continue;
			}

			$raw_before = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			$exists     = null !== $raw_before;
			$current    = $exists ? maybe_unserialize( (string) $raw_before ) : null;
			$path       = $this->path_segments( $item['path'] ?? null );

			if ( 'delete' === $op && array() === $path ) {
				$new = null;
				$del = true;
			} else {
				$del = false;
				if ( array_key_exists( 'value_serialized', $item ) ) {
					$value = @unserialize( (string) $item['value_serialized'], array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- probe.
					if ( ( false === $value && 'b:0;' !== $item['value_serialized'] ) || $this->contains_object( $value ) ) {
						$row['status'] = 'refused:bad_value_serialized_or_objects';
						$rows[]        = $row;
						continue;
					}
				} else {
					$value = $item['value'] ?? null;
				}
				if ( is_object( $current ) ) {
					$row['status'] = 'refused:object_option';
					$rows[]        = $row;
					continue;
				}
				$new = $this->compute( $current, $op, $path, $value );
				if ( $new instanceof WP_Error ) {
					$row['status'] = 'refused:' . $new->get_error_code();
					$rows[]        = $row;
					continue;
				}
			}

			$same = $del ? ! $exists : ( $exists && maybe_serialize( $current ) === maybe_serialize( $new ) );
			if ( $same ) {
				$row['status'] = 'noop';
				$rows[]        = $row;
				continue;
			}
			$row['status']       = $dry ? 'would_change' : 'changed';
			$row['existed']      = $exists;
			$row['before_bytes'] = $exists ? strlen( (string) $raw_before ) : 0;
			$row['after_bytes']  = $del ? 0 : strlen( maybe_serialize( $new ) );
			if ( $exists && strlen( (string) $raw_before ) <= 20000 ) {
				$row['before'] = (string) $raw_before;
			}
			if ( ! $dry ) {
				Ovp_Migrate_Util::snapshot_append( $batch, array( 'kind' => 'option_before', 'name' => $name, 'existed' => $exists, 'raw' => $raw_before ) );
				if ( $del ) {
					delete_option( $name );
				} elseif ( $exists ) {
					update_option( $name, $new );
				} else {
					add_option( $name, $new );
				}
				if ( in_array( $name, array( 'permalink_structure', 'category_base', 'tag_base' ), true ) ) {
					$flush = true;
				}
			}
			$rows[] = $row;
		}
		if ( $flush && ! empty( $body['rewrite_flush'] ) ) {
			flush_rewrite_rules( false );
		}
		return $this->success(
			array(
				'dry_run'  => $dry,
				'batch'    => $batch,
				'snapshot' => $dry ? null : 'uploads/mcp-bridge/backups/' . preg_replace( '/[^A-Za-z0-9_-]/', '', $batch ) . '.jsonl',
				'items'    => $rows,
			)
		);
	}

	private function deny_reason( string $name ): string {
		global $wpdb;
		$lower = strtolower( $name );
		if ( 'active_plugins' === $lower ) {
			return '';
		}
		foreach ( self::DENY as $pat ) {
			if ( $this->pattern_match( $lower, $pat ) ) {
				return $pat;
			}
		}
		$prefix = strtolower( (string) $wpdb->prefix );
		if ( $lower === $prefix . 'user_roles' || str_ends_with( $lower, 'user_roles' ) || str_ends_with( $lower, '_capabilities' ) || str_ends_with( $lower, '_user_level' ) ) {
			return 'users_roles';
		}
		return '';
	}

	private function pattern_match( string $lower_name, string $pattern ): bool {
		if ( str_ends_with( $pattern, '*' ) ) {
			return str_starts_with( $lower_name, rtrim( strtolower( $pattern ), '*' ) );
		}
		return $lower_name === strtolower( $pattern );
	}

	/**
	 * @param array<int, string> $patterns
	 */
	private function matches_any( string $name, array $patterns ): bool {
		$lower = strtolower( $name );
		foreach ( $patterns as $p ) {
			if ( $this->pattern_match( $lower, $p ) ) {
				return true;
			}
		}
		return false;
	}

	private function contains_object( mixed $value ): bool {
		if ( is_object( $value ) ) {
			return true;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $child ) {
				if ( $this->contains_object( $child ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * @return array<int, string>
	 */
	private function path_segments( mixed $raw ): array {
		if ( is_string( $raw ) && '' !== $raw ) {
			return array_values( array_filter( explode( '.', $raw ), static fn( string $s ): bool => '' !== $s ) );
		}
		$out = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $seg ) {
				if ( is_string( $seg ) || is_int( $seg ) ) {
					$out[] = (string) $seg;
				}
			}
		}
		return $out;
	}

	/**
	 * @param array<int, string> $path
	 */
	private function compute( mixed $current, string $op, array $path, mixed $value ): mixed {
		if ( array() === $path ) {
			if ( 'merge' === $op && is_array( $current ) && is_array( $value ) ) {
				return array_replace_recursive( $current, $value );
			}
			return $value;
		}
		$root = is_array( $current ) ? $current : array();
		if ( null !== $current && ! is_array( $current ) ) {
			return new WP_Error( 'path_on_scalar', 'path given but the current value is not an array.' );
		}
		$ref = &$root;
		$last = count( $path ) - 1;
		foreach ( $path as $i => $seg ) {
			if ( $i === $last ) {
				break;
			}
			if ( ! isset( $ref[ $seg ] ) || ! is_array( $ref[ $seg ] ) ) {
				if ( 'delete' === $op ) {
					return $root;
				}
				$ref[ $seg ] = array();
			}
			$ref = &$ref[ $seg ];
		}
		$leaf = $path[ $last ];
		if ( 'delete' === $op ) {
			unset( $ref[ $leaf ] );
		} elseif ( 'merge' === $op && isset( $ref[ $leaf ] ) && is_array( $ref[ $leaf ] ) && is_array( $value ) ) {
			$ref[ $leaf ] = array_replace_recursive( $ref[ $leaf ], $value );
		} else {
			$ref[ $leaf ] = $value;
		}
		unset( $ref );
		return $root;
	}

	// -- Elementor ---------------------------------------------------------------------

	/**
	 * Body: post_ids[], all (bool), css (default true), files_cache (default true),
	 * conditions (default true).
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request
	 */
	public function elementor_regen( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;
		$body = (array) $request->get_json_params();
		$ids  = array();
		foreach ( is_array( $body['post_ids'] ?? null ) ? $body['post_ids'] : array() as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$ids[] = (int) $id;
			}
		}
		$all    = ! empty( $body['all'] );
		$report = array( 'css_meta_deleted' => 0, 'css_files_deleted' => 0, 'files_cache' => 'skipped', 'conditions' => 'skipped' );
		$do_css = ! array_key_exists( 'css', $body ) || (bool) $body['css'];
		if ( $do_css ) {
			$keys = "'_elementor_css','_elementor_element_cache','_elementor_page_assets'";
			if ( $all ) {
				$report['css_meta_deleted'] = (int) $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ({$keys})" ); // phpcs:ignore WordPress.DB.PreparedSQL -- static key list.
			} elseif ( array() !== $ids ) {
				$in                         = implode( ',', array_map( 'intval', $ids ) );
				$report['css_meta_deleted'] = (int) $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$in}) AND meta_key IN ({$keys})" ); // phpcs:ignore WordPress.DB.PreparedSQL -- ints only.
				$up                         = wp_get_upload_dir();
				foreach ( $ids as $id ) {
					$f = rtrim( (string) $up['basedir'], '/' ) . '/elementor/css/post-' . $id . '.css';
					if ( is_file( $f ) && @unlink( $f ) ) {
						++$report['css_files_deleted'];
					}
				}
			}
			wp_cache_flush();
		}
		$do_files = ! array_key_exists( 'files_cache', $body ) || (bool) $body['files_cache'];
		if ( $do_files ) {
			$report['files_cache'] = $this->elementor_files_clear() ? 'cleared' : 'unavailable';
		}
		$do_cond = ! array_key_exists( 'conditions', $body ) || (bool) $body['conditions'];
		if ( $do_cond ) {
			$report['conditions'] = $this->elementor_conditions_regen();
		}
		return $this->success( $report );
	}

	private function elementor_files_clear(): bool {
		$cls = '\\Elementor\\Plugin';
		if ( ! class_exists( $cls ) ) {
			return false;
		}
		$instance = $cls::$instance ?? null;
		if ( ! is_object( $instance ) || ! isset( $instance->files_manager ) || ! is_object( $instance->files_manager ) || ! is_callable( array( $instance->files_manager, 'clear_cache' ) ) ) {
			return false;
		}
		$instance->files_manager->clear_cache();
		return true;
	}

	private function elementor_conditions_regen(): string {
		$cls = '\\ElementorPro\\Plugin';
		if ( ! class_exists( $cls ) || ! is_callable( array( $cls, 'instance' ) ) ) {
			return 'unavailable';
		}
		try {
			$pro = call_user_func( array( $cls, 'instance' ) );
			if ( ! is_object( $pro ) || ! isset( $pro->modules_manager ) || ! is_object( $pro->modules_manager ) || ! is_callable( array( $pro->modules_manager, 'get_modules' ) ) ) {
				return 'unavailable';
			}
			$module = $pro->modules_manager->get_modules( 'theme-builder' );
			if ( ! is_object( $module ) || ! is_callable( array( $module, 'get_conditions_manager' ) ) ) {
				return 'unavailable';
			}
			$cache = $module->get_conditions_manager()->get_cache();
			if ( ! is_object( $cache ) || ! is_callable( array( $cache, 'regenerate' ) ) ) {
				return 'unavailable';
			}
			$cache->regenerate();
			return 'regenerated';
		} catch ( \Throwable $e ) {
			return 'error:' . $e->getMessage();
		}
	}

	// -- cache ---------------------------------------------------------------------------

	/**
	 * Body: litespeed (default true), elementor (default true), object (default true),
	 * rewrite_flush, opcache, urls[] (LiteSpeed purge_url).
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request
	 */
	public function cache_purge( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body   = (array) $request->get_json_params();
		$report = array();
		$flag   = static function ( string $k, bool $default ) use ( $body ): bool {
			return array_key_exists( $k, $body ) ? (bool) $body[ $k ] : $default;
		};
		if ( $flag( 'litespeed', true ) ) {
			$urls = $this->strings( $body['urls'] ?? null );
			// D21: URLs of another host are never purged here; say so instead of accepting them silently.
			$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
			$own       = array();
			$foreign   = array();
			foreach ( $urls as $u ) {
				$h = strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) );
				if ( '' === $h || $h === $home_host ) {
					$own[] = $u;
				} else {
					$foreign[] = $u;
				}
			}
			if ( array() !== $foreign ) {
				$report['skipped_foreign_urls'] = $foreign;
				$report['warnings'][]           = count( $foreign ) . ' URL(s) skipped: only URLs on this site (' . $home_host . ') or relative paths can be purged.';
			}
			if ( ! has_action( 'litespeed_purge_all' ) ) {
				$report['litespeed'] = 'inactive';
			} elseif ( array() !== $own ) {
				foreach ( $own as $u ) {
					do_action( 'litespeed_purge_url', $u );
				}
				$report['litespeed'] = 'purged_urls:' . count( $own );
			} elseif ( array() !== $urls ) {
				$report['litespeed'] = 'no_valid_urls'; // Only foreign URLs were given: never fall back to purge-all.
			} else {
				do_action( 'litespeed_purge_all' );
				$report['litespeed'] = 'purged_all';
			}
		}
		if ( $flag( 'elementor', true ) ) {
			$report['elementor'] = $this->elementor_files_clear() ? 'cleared' : 'unavailable';
		}
		if ( $flag( 'object', true ) ) {
			$report['object'] = wp_cache_flush() ? 'flushed' : 'failed';
		}
		if ( $flag( 'rewrite_flush', false ) ) {
			flush_rewrite_rules( false );
			$report['rewrite'] = 'flushed';
		}
		if ( $flag( 'opcache', false ) ) {
			$report['opcache'] = function_exists( 'opcache_reset' ) && @opcache_reset() ? 'reset' : 'unavailable'; // phpcs:ignore WordPress.PHP.NoSilencedErrors -- optional.
		}
		return $this->success( $report );
	}

	// -- Yoast -----------------------------------------------------------------------------

	/**
	 * Body: ids[] + type post|term, or full=true (indexation actions, max_batches).
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request
	 */
	public function yoast_reindex( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! function_exists( 'YoastSEO' ) ) {
			return $this->error( 'mcp_bridge_yoast_inactive', 'Yoast SEO is not active.', 404 );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 );
		}
		$body    = (array) $request->get_json_params();
		$yoast = $this->call_global( 'YoastSEO' );
		$classes = is_object( $yoast ) && isset( $yoast->classes ) ? $yoast->classes : null;
		if ( ! is_object( $classes ) || ! is_callable( array( $classes, 'get' ) ) ) {
			return $this->error( 'mcp_bridge_yoast_inactive', 'Yoast container unavailable.', 500 );
		}
		$report = array( 'rebuilt' => array(), 'failed' => array() );
		$ids    = array();
		foreach ( is_array( $body['ids'] ?? null ) ? $body['ids'] : array() as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$ids[] = (int) $id;
			}
		}
		$type = 'term' === ( $body['type'] ?? 'post' ) ? 'term' : 'post';
		if ( array() !== $ids ) {
			foreach ( $ids as $id ) {
				try {
					$r = Ovp_Yoast_Indexable::rebuild( $id, $type );
					if ( $r['ok'] ) {
						$report['rebuilt'][] = $id;
						$report['duplicates_removed'] = ( $report['duplicates_removed'] ?? 0 ) + $r['removed'];
					} else {
						$report['failed'][] = array( 'id' => $id, 'error' => 'rebuild failed' );
					}
				} catch ( \Throwable $e ) {
					$report['failed'][] = array( 'id' => $id, 'error' => $e->getMessage() );
				}
			}
		}
		if ( ! empty( $body['full'] ) ) {
			$max     = max( 1, min( 80, (int) ( $body['max_batches'] ?? 40 ) ) );
			$batches = 0;
			$action  = $classes->get( 'Yoast\\WP\\SEO\\Actions\\Indexing\\Indexable_Post_Indexation_Action' );
			for ( $i = 0; $i < $max; $i++ ) {
				$res = $action->index();
				++$batches;
				if ( ! is_countable( $res ) || 0 === count( $res ) ) {
					break;
				}
			}
			$report['full_batches'] = $batches;
			$report['duplicates_removed'] = ( $report['duplicates_removed'] ?? 0 ) + Ovp_Yoast_Indexable::dedupe_all();
		}
		return $this->success( $report );
	}

	/**
	 * @return object|null WPSEO_Redirect_Manager
	 */
	private function redirect_manager(): ?object {
		return $this->instantiate( 'WPSEO_Redirect_Manager', array() );
	}

	/**
	 * Construct a third-party class only when it exists.
	 *
	 * @param array<int, mixed> $args
	 */
	private function instantiate( string $class_name, array $args ): ?object {
		if ( ! class_exists( $class_name ) ) {
			return null;
		}
		return new $class_name( ...$args );
	}

	private function call_global( string $function_name ): mixed {
		return is_callable( $function_name ) ? $function_name() : null;
	}

	/**
	 * @param WP_REST_Request<array<string, mixed>> $request
	 */
	public function redirects_get( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		unset( $request );
		$mgr  = $this->redirect_manager();
		$rows = array();
		if ( null !== $mgr && is_callable( array( $mgr, 'get_all_redirects' ) ) ) {
			foreach ( (array) $mgr->get_all_redirects() as $r ) {
				if ( is_object( $r ) ) {
					$rows[] = array(
						'origin' => (string) $r->get_origin(),
						'url'    => (string) $r->get_target(),
						'type'   => (int) $r->get_type(),
						'format' => (string) $r->get_format(),
					);
				}
			}
		}
		return $this->success(
			array(
				'premium_active' => null !== $mgr,
				'count'          => count( $rows ),
				'redirects'      => $rows,
				'raw_base'       => null === $mgr ? get_option( 'wpseo-premium-redirects-base', null ) : null,
			)
		);
	}

	/**
	 * Body: items[] {origin, url, type (301), format plain|regex, op set|delete}, dry_run
	 * (default true), confirm, batch. Previous state is written to the backups store.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request
	 */
	public function redirects_set( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body  = (array) $request->get_json_params();
		$dry   = ! array_key_exists( 'dry_run', $body ) || (bool) $body['dry_run'];
		$items = is_array( $body['items'] ?? null ) ? $body['items'] : array();
		if ( array() === $items ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Provide items[].', 400 );
		}
		if ( ! $dry && empty( $body['confirm'] ) ) {
			return $this->error( 'mcp_bridge_confirm_required', 'redirects_set apply needs confirm=true.', 403 );
		}
		$mgr = $this->redirect_manager();
		if ( null === $mgr ) {
			return $this->error( 'mcp_bridge_yoast_premium_inactive', 'Yoast SEO Premium redirect manager is not available.', 404 );
		}
		$batch  = isset( $body['batch'] ) ? (string) $body['batch'] : 'redirects-' . gmdate( 'Ymd\THis\Z' );
		if ( ! $dry ) {
			Ovp_Migrate_Util::snapshot_append(
				$batch,
				array(
					'kind'    => 'redirects_before',
					'base'    => get_option( 'wpseo-premium-redirects-base', null ),
					'plain'   => get_option( 'wpseo-premium-redirects-export-plain', null ),
					'regex'   => get_option( 'wpseo-premium-redirects-export-regex', null ),
				)
			);
		}
		$rows = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['origin'] ) ) {
				continue;
			}
			$origin = (string) $item['origin'];
			$op     = 'delete' === ( $item['op'] ?? 'set' ) ? 'delete' : 'set';
			$url    = (string) ( $item['url'] ?? '' );
			$type   = (int) ( $item['type'] ?? 301 );
			$format = 'regex' === ( $item['format'] ?? 'plain' ) ? 'regex' : 'plain';
			$cur    = $mgr->get_redirect( $origin );
			$row    = array( 'origin' => $origin, 'op' => $op, 'existed' => is_object( $cur ) );
			if ( 'delete' === $op ) {
				$row['status'] = is_object( $cur ) ? ( $dry ? 'would_delete' : 'deleted' ) : 'noop';
				if ( ! $dry && is_object( $cur ) ) {
					$mgr->delete_redirects( array( $cur ) );
				}
			} else {
				if ( '' === $url ) {
					$row['status'] = 'refused:url_required';
					$rows[]        = $row;
					continue;
				}
				if ( is_object( $cur ) && (string) $cur->get_target() === $url && (int) $cur->get_type() === $type && (string) $cur->get_format() === $format ) {
					$row['status'] = 'noop';
				} elseif ( $dry ) {
					$row['status'] = is_object( $cur ) ? 'would_update' : 'would_create';
				} else {
					$new = $this->instantiate( 'WPSEO_Redirect', array( $origin, $url, $type, $format ) );
					$ok  = null !== $new && ( is_object( $cur ) ? $mgr->update_redirect( $cur, $new ) : $mgr->create_redirect( $new ) );
					$row['status'] = $ok ? ( is_object( $cur ) ? 'updated' : 'created' ) : 'failed';
				}
			}
			$rows[] = $row;
		}
		return $this->success( array( 'dry_run' => $dry, 'batch' => $batch, 'items' => $rows ) );
	}

	/**
	 * @return array<int, string>
	 */
	private function strings( mixed $raw ): array {
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
