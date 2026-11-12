<?php
/**
 * Generic meta layer (design section 7). One verb set for post|term|user|comment|option:
 * keys, get, prepare (dry-run diff), commit (snapshot + write), delete, rollback.
 *
 * Safety properties:
 *  - Stored values are read RAW from the DB and decoded here with allowed_classes=false;
 *    WordPress's own maybe_unserialize (which can instantiate objects) is never used on
 *    data we return. Object payloads are refused on read and on write.
 *  - Denylist: secrets (user_pass, session_tokens, application passwords, auth keys, the
 *    plugin's own ovp_/mcp_bridge_ keys) never leave and are never written. Structural keys
 *    (siteurl, home, active_plugins, capabilities...) need T3.
 *  - Optimistic concurrency via expected_hash; every write is preceded by a snapshot
 *    (7-day TTL, size capped) and can be rolled back.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Storage seam: WordPress in production, in-memory in tests. */
interface OVP_Meta_Backend {

	public function exists( string $type, int $id ): bool;

	public function post_type( int $id ): string;

	/**
	 * Raw DB-form values for a key (max 3 rows). Key '@post_content' reads the post field.
	 *
	 * @return array<int, string>
	 */
	public function get_raw( string $type, int $id, string $key ): array;

	/**
	 * @return array<int, array{key:string,count:int,size:int,head:string}>
	 */
	public function list_keys( string $type, int $id, string $prefix, int $limit ): array;

	/** Hook-friendly write of a PHP value (WP slashes/serializes). */
	public function write( string $type, int $id, string $key, mixed $value ): bool;

	/** Write an exact DB-form string (rollback). */
	public function set_raw( string $type, int $id, string $key, string $raw ): bool;

	public function delete( string $type, int $id, string $key ): bool;
}

final class OVP_Meta_Backend_WP implements OVP_Meta_Backend {

	/** @return array{0:string,1:string}|null table, id column */
	private function table( string $type ): ?array {
		global $wpdb;
		return match ( $type ) {
			'post'    => array( $wpdb->postmeta, 'post_id' ),
			'term'    => array( $wpdb->termmeta, 'term_id' ),
			'user'    => array( $wpdb->usermeta, 'user_id' ),
			'comment' => array( $wpdb->commentmeta, 'comment_id' ),
			default   => null,
		};
	}

	public function exists( string $type, int $id ): bool {
		return match ( $type ) {
			'post'    => null !== get_post( $id ),
			'term'    => ! is_wp_error( get_term( $id ) ) && null !== get_term( $id ),
			'user'    => false !== get_userdata( $id ),
			'comment' => null !== get_comment( $id ),
			'option'  => true,
			default   => false,
		};
	}

	public function post_type( int $id ): string {
		$t = get_post_type( $id );
		return is_string( $t ) ? $t : '';
	}

	public function get_raw( string $type, int $id, string $key ): array {
		global $wpdb;
		if ( '@post_content' === $key ) {
			$v = $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- table name is a core property.
			return null === $v ? array() : array( (string) $v );
		}
		if ( 'option' === $type ) {
			$v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			return null === $v ? array() : array( (string) $v );
		}
		$t = $this->table( $type );
		if ( null === $t ) {
			return array();
		}
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$t[0]} WHERE {$t[1]} = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 3", $id, $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array_map( 'strval', (array) $rows );
	}

	public function list_keys( string $type, int $id, string $prefix, int $limit ): array {
		global $wpdb;
		$limit = max( 1, min( 500, $limit ) );
		if ( 'option' === $type ) {
			$like = $wpdb->esc_like( $prefix ) . '%';
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name AS k, 1 AS c, LENGTH(option_value) AS s, SUBSTRING(option_value,1,24) AS h FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC LIMIT %d", $like, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		} else {
			$t = $this->table( $type );
			if ( null === $t ) {
				return array();
			}
			$like = $wpdb->esc_like( $prefix ) . '%';
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key AS k, COUNT(*) AS c, MAX(LENGTH(meta_value)) AS s, MIN(SUBSTRING(meta_value,1,24)) AS h FROM {$t[0]} WHERE {$t[1]} = %d AND meta_key LIKE %s GROUP BY meta_key ORDER BY meta_key ASC LIMIT %d", $id, $like, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array( 'key' => (string) $r['k'], 'count' => (int) $r['c'], 'size' => (int) $r['s'], 'head' => (string) $r['h'] );
		}
		return $out;
	}

	public function write( string $type, int $id, string $key, mixed $value ): bool {
		if ( '@post_content' === $key ) {
			$r = wp_update_post( wp_slash( array( 'ID' => $id, 'post_content' => (string) $value ) ), true );
			return ! is_wp_error( $r ) && 0 !== $r;
		}
		if ( 'option' === $type ) {
			if ( false === get_option( $key, false ) && ! $this->option_row_exists( $key ) ) {
				return add_option( $key, $value, '', false );
			}
			update_option( $key, $value );
			return true;
		}
		update_metadata( $type, $id, $key, wp_slash( $value ) );
		return true;
	}

	private function option_row_exists( string $name ): bool {
		global $wpdb;
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public function set_raw( string $type, int $id, string $key, string $raw ): bool {
		global $wpdb;
		if ( '@post_content' === $key ) {
			$ok = false !== $wpdb->update( $wpdb->posts, array( 'post_content' => $raw ), array( 'ID' => $id ), array( '%s' ), array( '%d' ) );
			clean_post_cache( $id );
			return $ok;
		}
		if ( 'option' === $type ) {
			$ok = $this->option_row_exists( $key )
				? false !== $wpdb->update( $wpdb->options, array( 'option_value' => $raw ), array( 'option_name' => $key ), array( '%s' ), array( '%s' ) )
				: false !== $wpdb->insert( $wpdb->options, array( 'option_name' => $key, 'option_value' => $raw, 'autoload' => 'no' ), array( '%s', '%s', '%s' ) );
			wp_cache_delete( $key, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			return $ok;
		}
		$t = $this->table( $type );
		if ( null === $t ) {
			return false;
		}
		$have = $this->get_raw( $type, $id, $key );
		if ( array() === $have ) {
			$ok = false !== $wpdb->insert( $t[0], array( $t[1] => $id, 'meta_key' => $key, 'meta_value' => $raw ), array( '%d', '%s', '%s' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		} else {
			$ok = false !== $wpdb->update( $t[0], array( 'meta_value' => $raw ), array( $t[1] => $id, 'meta_key' => $key ), array( '%s' ), array( '%d', '%s' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		wp_cache_delete( $id, $type . '_meta' );
		return $ok;
	}

	public function delete( string $type, int $id, string $key ): bool {
		if ( 'option' === $type ) {
			return delete_option( $key );
		}
		return delete_metadata( $type, $id, $key );
	}
}

/** File-backed snapshots taken before every write. */
final class OVP_Meta_Snapshots {

	private const TTL       = 604800;     // 7 days.
	private const MAX_ONE   = 16777216;   // 16MB per snapshot.
	private const MAX_TOTAL = 268435456;  // 256MB all snapshots.

	private static function dir(): string|WP_Error {
		return OVP_Upload::sub_dir( 'meta-snapshots' );
	}

	/** @param array<string, mixed> $data */
	public static function save( array $data ): string|WP_Error {
		$dir = self::dir();
		if ( $dir instanceof WP_Error ) {
			return $dir;
		}
		$id              = bin2hex( random_bytes( 16 ) );
		$data['id']      = $id;
		$data['ts']      = time();
		$data['expires'] = time() + self::TTL;
		$json            = json_encode( self::enc( $data ) );
		if ( false === $json ) {
			return new WP_Error( 'ovp_snapshot_failed', 'Cannot encode snapshot.', array( 'status' => 500 ) );
		}
		if ( strlen( $json ) > self::MAX_ONE ) {
			return new WP_Error( 'ovp_snapshot_too_large', 'Value too large to snapshot (16MB cap).', array( 'status' => 413 ) );
		}
		self::gc( strlen( $json ) );
		if ( false === file_put_contents( $dir . '/' . $id . '.json', $json, LOCK_EX ) ) {
			return new WP_Error( 'ovp_snapshot_failed', 'Cannot write snapshot.', array( 'status' => 500 ) );
		}
		@chmod( $dir . '/' . $id . '.json', 0600 );
		return $id;
	}

	/** @return array<string, mixed>|WP_Error */
	public static function load( string $id ): array|WP_Error {
		$dir = self::dir();
		if ( $dir instanceof WP_Error ) {
			return $dir;
		}
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $id ) || ! is_file( $dir . '/' . $id . '.json' ) ) {
			return new WP_Error( 'ovp_snapshot_unknown', 'Unknown snapshot.', array( 'status' => 404 ) );
		}
		$d = json_decode( (string) file_get_contents( $dir . '/' . $id . '.json' ), true );
		$d = is_array( $d ) ? self::dec( $d ) : null;
		if ( ! is_array( $d ) || (int) ( $d['expires'] ?? 0 ) < time() ) {
			return new WP_Error( 'ovp_snapshot_expired', 'Snapshot expired.', array( 'status' => 410 ) );
		}
		return $d;
	}

	/**
	 * Every string leaf is base64-wrapped so arbitrary bytes (latin1, binary, serialized
	 * data whose s:N: lengths must stay exact) survive JSON byte-for-byte.
	 */
	private static function enc( mixed $v ): mixed {
		if ( is_string( $v ) ) {
			return 'b64:' . base64_encode( $v );
		}
		if ( is_array( $v ) ) {
			foreach ( $v as $k => $x ) {
				$v[ $k ] = self::enc( $x );
			}
		}
		return $v;
	}

	private static function dec( mixed $v ): mixed {
		if ( is_string( $v ) ) {
			if ( str_starts_with( $v, 'b64:' ) ) {
				$d = base64_decode( substr( $v, 4 ), true );
				return false === $d ? '' : $d;
			}
			return $v;
		}
		if ( is_array( $v ) ) {
			foreach ( $v as $k => $x ) {
				$v[ $k ] = self::dec( $x );
			}
		}
		return $v;
	}

	public static function mark_rolled_back( string $id ): void {
		$dir = self::dir();
		if ( is_string( $dir ) ) {
			@unlink( $dir . '/' . $id . '.json' );
		}
	}

	public static function gc( int $incoming = 0 ): void {
		$dir = self::dir();
		if ( ! is_string( $dir ) ) {
			return;
		}
		$files = glob( $dir . '/*.json' );
		if ( ! is_array( $files ) ) {
			return;
		}
		$total = $incoming;
		$rows  = array();
		foreach ( $files as $f ) {
			$sz = (int) @filesize( $f );
			$mt = (int) @filemtime( $f );
			if ( time() - $mt > self::TTL ) {
				@unlink( $f );
				continue;
			}
			$rows[] = array( $f, $sz, $mt );
			$total += $sz;
		}
		usort( $rows, static fn( array $a, array $b ): int => $a[2] <=> $b[2] );
		foreach ( $rows as $r ) {
			if ( $total <= self::MAX_TOTAL ) {
				break;
			}
			@unlink( $r[0] );
			$total -= $r[1];
		}
	}
}

final class OVP_Meta {

	/** Tier per operation; write ops are escalated by required_tier(). */
	public const OVP_TIER = array(
		'meta_keys'     => 'T0',
		'meta_get'      => 'T0',
		'meta_prepare'  => 'T0',
		'meta_commit'   => 'T1',
		'meta_delete'   => 'T1',
		'meta_rollback' => 'T1',
	);

	public const TYPES = array( 'post', 'term', 'user', 'comment', 'option' );

	/** Never returned, never written, any tier. */
	private const SECRET_EXACT = array(
		'user_pass', 'user_activation_key', 'session_tokens', '_application_passwords', 'application_passwords',
		'auth_key', 'secure_auth_key', 'logged_in_key', 'nonce_key',
		'auth_salt', 'secure_auth_salt', 'logged_in_salt', 'nonce_salt',
	);
	private const HARD_PREFIX = array( 'auth_', 'secure_auth_', 'ovp_', '_ovp_', 'mcp_bridge_', '_mcp_bridge_' );
	private const REDACT_RE   = '/(password|passwd|pwd|secret|token|api[_-]?key|private[_-]?key|license[_-]?key|licence[_-]?key|secret[_-]?key|(?:^|[_-])pass(?:$|[_-])|[_-]pass$|salt|dsn|bearer|credential|signature)/i';
	/** Structural keys: T1/T2 refused, T3 allowed. */
	private const STRUCT_EXACT = array( '_edit_lock', 'siteurl', 'home', 'active_plugins', 'active_sitewide_plugins', 'template', 'stylesheet', 'cron', 'admin_email', 'default_role', 'users_can_register' );
	private const STRUCT_RE    = '/(capabilities|user_level|user_roles)$/i';

	private const OBJECT_RE = '/\b(?:[OC]:\+?\d+:"[A-Za-z_\\\\][A-Za-z0-9_\\\\]*":\+?\d+:\{|E:\d+:"[A-Za-z_\\\\][A-Za-z0-9_\\\\]*:[A-Za-z_][A-Za-z0-9_]*";)/';

	public const REDACTED = '***redacted***';

	private static ?OVP_Meta_Backend $backend = null;

	/** @var array<int, OVP_Adapter>|null */
	private static ?array $adapters = null;

	public static function set_backend( ?OVP_Meta_Backend $b ): void {
		self::$backend = $b;
	}

	private static function backend(): OVP_Meta_Backend {
		if ( null === self::$backend ) {
			self::$backend = new OVP_Meta_Backend_WP();
		}
		return self::$backend;
	}

	/** @return array<int, OVP_Adapter> */
	public static function adapters(): array {
		if ( null === self::$adapters ) {
			$dir = __DIR__ . '/adapters/';
			foreach ( array( 'base', 'elementor', 'woocommerce', 'acf', 'seo', 'content', 'generic' ) as $f ) {
				require_once $dir . 'class-ovp-adapter-' . $f . '.php';
			}
			self::$adapters = array(
				new OVP_Adapter_Elementor(),
				new OVP_Adapter_WooCommerce(),
				new OVP_Adapter_ACF(),
				new OVP_Adapter_Yoast(),
				new OVP_Adapter_RankMath(),
				new OVP_Adapter_SeoPress(),
				new OVP_Adapter_WPBakery(),
				new OVP_Adapter_Gutenberg(),
				new OVP_Adapter_Generic(),
			);
		}
		return self::$adapters;
	}

	/** @return array<int, array<string, mixed>> */
	public static function discovery(): array {
		return array_map( static fn( OVP_Adapter $a ): array => $a->describe(), self::adapters() );
	}

	/** @param array<string, mixed> $extra */
	private static function err( string $code, string $msg, int $status = 400, array $extra = array() ): WP_Error {
		return new WP_Error( $code, $msg, array_merge( array( 'status' => $status ), $extra ) );
	}

	// -- Policy ------------------------------------------------------------------------

	public static function is_hard_blocked( string $key ): bool {
		$k = strtolower( trim( $key ) );
		if ( in_array( $k, self::SECRET_EXACT, true ) ) {
			return true;
		}
		foreach ( self::HARD_PREFIX as $p ) {
			if ( str_starts_with( $k, $p ) ) {
				return true;
			}
		}
		// Prefixed variants such as wp_2_session_tokens do not exist, but {prefix}_application_passwords style keys do.
		return str_ends_with( $k, '_application_passwords' );
	}

	public static function is_redacted( string $key ): bool {
		return 1 === preg_match( self::REDACT_RE, trim( $key ) );
	}

	public static function is_structural( string $key ): bool {
		$k = strtolower( trim( $key ) );
		return in_array( $k, self::STRUCT_EXACT, true ) || 1 === preg_match( self::STRUCT_RE, $k );
	}

	/** Tier needed to write/delete, or null when no tier may. */
	public static function required_tier( string $op, string $type, string $key ): ?string {
		if ( 'read' === $op ) {
			return self::is_hard_blocked( $key ) ? null : 'T0';
		}
		if ( self::is_hard_blocked( $key ) ) {
			return null;
		}
		if ( self::is_structural( $key ) ) {
			return 'T3';
		}
		return in_array( $type, array( 'user', 'option' ), true ) ? 'T2' : 'T1';
	}

	public static function authorize( string $op, string $type, string $key, string $tier ): ?WP_Error {
		$need = self::required_tier( $op, $type, $key );
		if ( null === $need ) {
			return self::err( 'ovp_denylisted', 'This key is protected and cannot be accessed.', 403 );
		}
		if ( OVP_Upload::tier_rank( $tier ) < OVP_Upload::tier_rank( $need ) ) {
			return self::err( 'ovp_tier_denied', 'Tier ' . $need . ' required for this key.', 403, array( 'required_tier' => $need ) );
		}
		return null;
	}

	/**
	 * Effective tier of a REST request. Integration seam with class-ovp-tiers.php: it must
	 * expose OVP_Tiers::effective_tier( WP_REST_Request ): string. Fails closed to T0.
	 */
	public static function request_tier( mixed $request ): string {
		if ( class_exists( 'OVP_Tiers' ) && is_callable( array( 'OVP_Tiers', 'effective_tier' ) ) ) {
			$t = (string) call_user_func( array( 'OVP_Tiers', 'effective_tier' ), $request );
			if ( OVP_Upload::tier_rank( $t ) >= 0 ) {
				return strtoupper( $t );
			}
		}
		return 'T0';
	}

	/**
	 * Strip protected keys / mask secret-looking ones from a key=>value map (REST shaping).
	 *
	 * @param array<string, mixed> $meta
	 * @return array<string, mixed>
	 */
	public static function filter_array( array $meta ): array {
		$out = array();
		foreach ( $meta as $k => $v ) {
			$k = (string) $k;
			if ( self::is_hard_blocked( $k ) ) {
				continue;
			}
			$out[ $k ] = self::is_redacted( $k ) ? self::REDACTED : $v;
		}
		return $out;
	}

	// -- Value codec -------------------------------------------------------------------------

	public static function looks_serialized( string $s ): bool {
		if ( strlen( $s ) < 4 ) {
			return 'N;' === $s;
		}
		return 1 === preg_match( '/^(?:a:\d+:\{|O:\d+:"|C:\d+:"|E:\d+:"|s:\d+:"|i:-?\d+;|d:[-0-9.eE+INFA]+;|b:[01];)/', $s ) && ( str_ends_with( $s, ';' ) || str_ends_with( $s, '}' ) );
	}

	/**
	 * @return array{ok:bool,value:mixed,objects:bool}
	 */
	public static function safe_unserialize( string $s ): array {
		if ( strlen( $s ) > 8388608 ) {
			return array( 'ok' => false, 'value' => null, 'objects' => false );
		}
		set_error_handler( static fn(): bool => true );
		try {
			$v = unserialize( $s, array( 'allowed_classes' => false, 'max_depth' => 32 ) );
		} catch ( \Throwable $e ) {
			$v = false;
		} finally {
			restore_error_handler();
		}
		if ( false === $v && 'b:0;' !== $s ) {
			return array( 'ok' => false, 'value' => null, 'objects' => false );
		}
		return array( 'ok' => true, 'value' => $v, 'objects' => self::has_object( $v ) );
	}

	private static function has_object( mixed $v ): bool {
		if ( is_object( $v ) ) {
			return true;
		}
		if ( is_array( $v ) ) {
			foreach ( $v as $x ) {
				if ( self::has_object( $x ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** True if any string leaf (or key) carries a serialized object/enum payload. */
	public static function contains_object_payload( mixed $v ): bool {
		if ( is_object( $v ) ) {
			return true;
		}
		if ( is_string( $v ) ) {
			return 1 === preg_match( self::OBJECT_RE, $v );
		}
		if ( is_array( $v ) ) {
			foreach ( $v as $k => $x ) {
				if ( ( is_string( $k ) && 1 === preg_match( self::OBJECT_RE, $k ) ) || self::contains_object_payload( $x ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function contains_marker( mixed $v ): bool {
		if ( is_string( $v ) ) {
			return str_contains( $v, self::REDACTED );
		}
		if ( is_array( $v ) ) {
			foreach ( $v as $x ) {
				if ( self::contains_marker( $x ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Mirror of WordPress maybe_serialize for a PHP value. */
	public static function to_db_form( mixed $v ): string {
		if ( is_array( $v ) ) {
			return serialize( $v );
		}
		if ( is_string( $v ) ) {
			return self::looks_serialized( $v ) ? serialize( $v ) : $v;
		}
		if ( is_bool( $v ) ) {
			return $v ? '1' : '';
		}
		if ( null === $v ) {
			return '';
		}
		return (string) $v;
	}

	/**
	 * Describe a stored DB-form value without ever instantiating objects.
	 *
	 * @return array{value_type:string,value:mixed,note?:string}
	 */
	public static function decode_db( string $raw ): array {
		if ( '' === $raw ) {
			return array( 'value_type' => 'empty', 'value' => '' );
		}
		if ( self::looks_serialized( $raw ) ) {
			$r = self::safe_unserialize( $raw );
			if ( $r['ok'] ) {
				if ( $r['objects'] ) {
					return array( 'value_type' => 'serialized_object', 'value' => null, 'note' => 'Contains PHP objects. Not decoded or returned.' );
				}
				return array( 'value_type' => is_array( $r['value'] ) ? 'serialized' : 'serialized_scalar', 'value' => $r['value'] );
			}
		}
		$c = $raw[0];
		if ( ( '{' === $c || '[' === $c ) && strlen( $raw ) <= 4194304 ) {
			json_decode( $raw );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				return array( 'value_type' => 'json', 'value' => $raw );
			}
		}
		if ( 1 === preg_match( '/^-?\d{1,18}$/', $raw ) ) {
			return array( 'value_type' => 'int', 'value' => $raw );
		}
		if ( 1 === preg_match( '/^-?\d+\.\d+$/', $raw ) ) {
			return array( 'value_type' => 'float', 'value' => $raw );
		}
		return array( 'value_type' => 'string', 'value' => $raw );
	}

	/** Mask values stored under secret-looking keys anywhere inside a decoded structure. */
	public static function redact_nested( mixed $v, int &$hits = 0 ): mixed {
		if ( is_array( $v ) ) {
			foreach ( $v as $k => $x ) {
				if ( is_string( $k ) && self::is_redacted( $k ) && ( is_scalar( $x ) || is_array( $x ) ) && '' !== $x && array() !== $x ) {
					$v[ $k ] = self::REDACTED;
					++$hits;
				} else {
					$v[ $k ] = self::redact_nested( $x, $hits );
				}
			}
		}
		return $v;
	}

	/**
	 * Redact a raw stored cell (serialized array or JSON object) without instantiating
	 * anything. Returns the input unchanged when nothing needs masking.
	 */
	public static function redact_cell( string $raw ): string {
		if ( strlen( $raw ) > 262144 || '' === $raw ) {
			return $raw;
		}
		$hits = 0;
		if ( self::looks_serialized( $raw ) ) {
			$r = self::safe_unserialize( $raw );
			if ( $r['ok'] && ! $r['objects'] && is_array( $r['value'] ) ) {
				$new = self::redact_nested( $r['value'], $hits );
				return $hits > 0 ? serialize( $new ) : $raw;
			}
			return $raw;
		}
		if ( '{' === $raw[0] || '[' === $raw[0] ) {
			$j = json_decode( $raw, true, 64 );
			if ( is_array( $j ) ) {
				$new = self::redact_nested( $j, $hits );
				$enc = $hits > 0 ? json_encode( $new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : false;
				return false !== $enc ? $enc : $raw;
			}
		}
		return $raw;
	}

	/**
	 * Safe replacement for get_post_meta( $id ) (which unserializes objects): key => list
	 * of decoded values, protected keys dropped, secrets masked.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function map_for( string $type, int $id, int $limit = 300 ): array {
		$out = array();
		foreach ( self::backend()->list_keys( $type, $id, '', $limit ) as $r ) {
			$k = $r['key'];
			if ( self::is_hard_blocked( $k ) ) {
				continue;
			}
			if ( self::is_redacted( $k ) ) {
				$out[ $k ] = array( self::REDACTED );
				continue;
			}
			$vals = array();
			foreach ( self::backend()->get_raw( $type, $id, $k ) as $raw ) {
				$d = self::decode_db( $raw );
				if ( 'serialized_object' === $d['value_type'] ) {
					$vals[] = '[php object omitted]';
				} elseif ( in_array( $d['value_type'], array( 'serialized', 'serialized_scalar' ), true ) ) {
					$vals[] = self::redact_nested( $d['value'] );
				} else {
					$vals[] = strlen( $raw ) > 524288 ? substr( $raw, 0, 524288 ) : $raw;
				}
			}
			$out[ $k ] = $vals;
		}
		return $out;
	}

	/** @param array<string, array<int, string>> $key_rows key => raw rows */
	private static function hash_rows( array $key_rows ): string {
		ksort( $key_rows );
		$parts = array();
		foreach ( $key_rows as $k => $rows ) {
			$parts[] = $k . "\0" . ( array() === $rows ? '<absent>' : implode( "\x1f", $rows ) );
		}
		return hash( 'sha256', 'ovp-meta-state-v1|' . implode( "\n", $parts ) );
	}

	/**
	 * @param array<int, string> $keys
	 * @return array<string, array<int, string>>
	 */
	private static function read_rows( string $type, int $id, array $keys ): array {
		$out = array();
		foreach ( array_unique( $keys ) as $k ) {
			$out[ $k ] = self::backend()->get_raw( $type, $id, $k );
		}
		return $out;
	}

	// -- Validation / context ---------------------------------------------------------------------

	private static function validate_target( string $type, int $id, string $key ): ?WP_Error {
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return self::err( 'ovp_meta_type', 'type must be post, term, user, comment or option.' );
		}
		if ( 'option' !== $type && $id < 1 ) {
			return self::err( 'ovp_meta_id', 'A positive id is required.' );
		}
		if ( str_starts_with( $key, '@' ) ) {
			if ( 'post' !== $type || '@post_content' !== $key ) {
				return self::err( 'ovp_meta_key', 'Only @post_content is a valid pseudo key (post).' );
			}
		} elseif ( $key !== trim( $key ) || 1 !== preg_match( '/\A[A-Za-z0-9_\-.:\/ ]{1,191}\z/', $key ) ) {
			// Leading/trailing space is rejected: WP trims option names and PAD SPACE collations match
			// trailing blanks, so ' ovp_x' / 'siteurl ' would dodge the denylists yet hit the real row.
			return self::err( 'ovp_meta_key', 'Invalid meta key.' );
		}
		if ( 'option' !== $type && ! self::backend()->exists( $type, $id ) ) {
			return self::err( 'ovp_meta_not_found', 'Entity not found.', 404 );
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $opts
	 * @return array<string, mixed>
	 */
	private static function ctx( string $type, int $id, string $key, array $opts, mixed $value = null ): array {
		$ctx = array(
			'adapter'   => (string) ( $opts['adapter'] ?? '' ),
			'field_key' => isset( $opts['field_key'] ) ? (string) $opts['field_key'] : null,
			'value'     => $value,
			'post_type' => 'post' === $type ? self::backend()->post_type( $id ) : '',
		);
		if ( 'option' !== $type && ! str_starts_with( $key, '_' ) && ! str_starts_with( $key, '@' ) && null === $ctx['field_key'] ) {
			$ref = self::backend()->get_raw( $type, $id, '_' . $key );
			if ( isset( $ref[0] ) && 1 === preg_match( '/^field_[a-z0-9]{6,40}$/', $ref[0] ) ) {
				$ctx['ref'] = $ref[0];
			}
		}
		if ( 'post' === $type && in_array( $key, array( '_sale_price', '_regular_price', 'sale_price', 'regular_price' ), true ) ) {
			$r                    = self::backend()->get_raw( 'post', $id, '_regular_price' );
			$s                    = self::backend()->get_raw( 'post', $id, '_sale_price' );
			$ctx['regular_price'] = $r[0] ?? '';
			$ctx['sale_price']    = $s[0] ?? '';
		}
		return $ctx;
	}

	/**
	 * @param array<string, mixed> $ctx
	 */
	private static function resolve_adapter( string $type, string $key, array $ctx ): OVP_Adapter|WP_Error {
		$hint = (string) ( $ctx['adapter'] ?? '' );
		foreach ( self::adapters() as $a ) {
			if ( '' !== $hint ) {
				if ( $a->id() !== $hint ) {
					continue;
				}
				return $a->handles( $type, $key, $ctx ) ? $a : self::err( 'ovp_adapter_mismatch', 'Adapter ' . $hint . ' does not handle this key.' );
			}
			if ( $a->handles( $type, $key, $ctx ) ) {
				return $a;
			}
		}
		return '' !== $hint ? self::err( 'ovp_adapter_unknown', 'Unknown adapter.' ) : self::err( 'ovp_adapter_none', 'No adapter for this key.' );
	}

	// -- Read verbs ------------------------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $opts prefix, limit, tier
	 * @return array<string, mixed>|WP_Error
	 */
	public static function keys( string $type, int $id, array $opts = array() ): array|WP_Error {
		$err = self::validate_target( $type, $id, 'x' );
		if ( null !== $err ) {
			return $err;
		}
		$prefix = (string) ( $opts['prefix'] ?? '' );
		if ( 'option' === $type && strlen( $prefix ) < 3 ) {
			return self::err( 'ovp_meta_prefix', 'Options listing needs a prefix of at least 3 characters.' );
		}
		if ( 1 === preg_match( '/[\x00-\x1f]/', $prefix ) || strlen( $prefix ) > 100 ) {
			return self::err( 'ovp_meta_prefix', 'Invalid prefix.' );
		}
		$rows = self::backend()->list_keys( $type, $id, $prefix, (int) ( $opts['limit'] ?? 200 ) );
		$out  = array();
		foreach ( $rows as $r ) {
			if ( self::is_hard_blocked( $r['key'] ) ) {
				continue;
			}
			$head = $r['head'];
			$vt   = 'string';
			if ( str_starts_with( $head, 'O:' ) || str_starts_with( $head, 'C:' ) || str_starts_with( $head, 'E:' ) ) {
				$vt = 'serialized_object';
			} elseif ( self::looks_serialized( $head . ';' ) || 1 === preg_match( '/^(a:\d+:\{|s:\d+:"|i:\d+;|b:[01];)/', $head ) ) {
				$vt = 'serialized';
			} elseif ( '' !== $head && ( '{' === $head[0] || '[' === $head[0] ) ) {
				$vt = 'json';
			} elseif ( 1 === preg_match( '/^-?\d+$/', $head ) ) {
				$vt = 'int';
			}
			$out[] = array(
				'key'        => $r['key'],
				'count'      => $r['count'],
				'size'       => $r['size'],
				'value_type' => $vt,
				'protected'  => str_starts_with( $r['key'], '_' ),
				'redacted'   => self::is_redacted( $r['key'] ),
				'write_tier' => self::required_tier( 'write', $type, $r['key'] ),
			);
		}
		return array( 'type' => $type, 'id' => $id, 'keys' => $out, 'adapters' => array_map( static fn( OVP_Adapter $a ): string => $a->id(), self::adapters() ) );
	}

	/**
	 * @param array<string, mixed> $opts tier, adapter, field_key, offset, max_bytes
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get( string $type, int $id, string $key, array $opts = array() ): array|WP_Error {
		$err = self::validate_target( $type, $id, $key );
		if ( null !== $err ) {
			return $err;
		}
		$err = self::authorize( 'read', $type, $key, (string) ( $opts['tier'] ?? 'T0' ) );
		if ( null !== $err ) {
			return $err;
		}
		$ctx     = self::ctx( $type, $id, $key, $opts );
		$adapter = self::resolve_adapter( $type, $key, $ctx );
		if ( $adapter instanceof WP_Error ) {
			return $adapter;
		}
		$rows = self::backend()->get_raw( $type, $id, $key );
		$hash = self::hash_rows( self::read_rows( $type, $id, array_merge( array( $key ), $adapter->state_keys( $key, $ctx ) ) ) );
		$res  = array(
			'type'      => $type,
			'id'        => $id,
			'key'       => $key,
			'exists'    => array() !== $rows,
			'adapter'   => $adapter->id(),
			'protected' => str_starts_with( $key, '_' ),
			'hash'      => $hash,
		);
		if ( array() === $rows ) {
			return $res + array( 'value_type' => 'absent', 'value' => null );
		}
		if ( self::is_redacted( $key ) ) {
			return $res + array( 'value_type' => 'redacted', 'value' => '***redacted***', 'size' => strlen( $rows[0] ) );
		}
		$max    = max( 1024, min( 2097152, (int) ( $opts['max_bytes'] ?? 262144 ) ) );
		$offset = max( 0, (int) ( $opts['offset'] ?? 0 ) );
		$vals   = array();
		foreach ( array_slice( $rows, 0, 3 ) as $raw ) {
			$d = self::decode_db( $raw );
			if ( in_array( $d['value_type'], array( 'serialized', 'serialized_scalar' ), true ) ) {
				$hits       = 0;
				$d['value'] = self::redact_nested( $d['value'], $hits );
				if ( $hits > 0 ) {
					$d['redacted_nested'] = $hits;
				}
			} elseif ( 'json' === $d['value_type'] && in_array( $type, array( 'option', 'user' ), true ) ) {
				$red = self::redact_cell( $raw );
				if ( $red !== $raw ) {
					$d['value']           = $red;
					$d['redacted_nested'] = true;
				}
			}
			if ( is_string( $d['value'] ) && ! is_array( $d['value'] ) ) {
				$full = $d['value'];
				$slice = substr( $full, $offset, $max );
				if ( ! mb_check_encoding( $slice, 'UTF-8' ) ) {
					$d['value']    = base64_encode( $slice );
					$d['encoding'] = 'base64';
				} else {
					$d['value'] = $slice;
				}
				$d['size'] = strlen( $full );
				if ( $offset + strlen( $slice ) < strlen( $full ) ) {
					$d['truncated']   = true;
					$d['next_offset'] = $offset + strlen( $slice );
				}
			} elseif ( is_array( $d['value'] ) ) {
				$enc = json_encode( $d['value'], JSON_INVALID_UTF8_SUBSTITUTE );
				$d['size'] = strlen( (string) $enc );
				if ( strlen( (string) $enc ) > $max ) {
					$d['value']     = null;
					$d['truncated'] = true;
					$d['note']      = 'Serialized value larger than max_bytes.';
				}
			}
			$vals[] = $d;
		}
		if ( count( $rows ) > 1 ) {
			return $res + array( 'value_type' => 'multi', 'values' => $vals, 'value' => null, 'count' => count( $rows ) );
		}
		return $res + $vals[0];
	}

	// -- Write verbs ---------------------------------------------------------------------------------------

	/**
	 * Build the full change plan without writing.
	 *
	 * @param array<string, mixed> $opts tier, adapter, field_key, as, delete
	 * @return array<string, mixed>|WP_Error
	 */
	private static function plan_change( string $type, int $id, string $key, mixed $value, array $opts, bool $delete ): array|WP_Error {
		$err = self::validate_target( $type, $id, $key );
		if ( null !== $err ) {
			return $err;
		}
		$op   = $delete ? 'delete' : 'write';
		// A dry run writes nothing, so it needs read access only (the master runs it at T0 before a
		// T1 confirm). The tier the real write needs is reported in the plan, never enforced here.
		$err  = self::authorize( ! empty( $opts['plan_only'] ) ? 'read' : $op, $type, $key, (string) ( $opts['tier'] ?? 'T0' ) );
		if ( null !== $err ) {
			return $err;
		}
		if ( $delete && '@post_content' === $key ) {
			return self::err( 'ovp_meta_key', 'post_content cannot be deleted.' );
		}
		if ( ! $delete ) {
			if ( is_object( $value ) ) {
				return self::err( 'ovp_object_refused', 'Objects are not accepted.', 422 );
			}
			if ( 'string' === ( $opts['as'] ?? null ) && is_array( $value ) ) {
				$value = (string) json_encode( $value );
			}
			if ( self::contains_object_payload( $value ) ) {
				return self::err( 'ovp_object_refused', 'Serialized object payloads are refused.', 422 );
			}
			if ( self::contains_marker( $value ) ) {
				return self::err( 'ovp_redacted_content', 'Value contains the redaction marker from a masked read. Writing it back would destroy the secrets it replaced.', 422 );
			}
			if ( is_string( $value ) && self::looks_serialized( $value ) ) {
				$r = self::safe_unserialize( $value );
				if ( $r['ok'] && $r['objects'] ) {
					return self::err( 'ovp_object_refused', 'Serialized object payloads are refused.', 422 );
				}
			}
		}
		$ctx     = self::ctx( $type, $id, $key, $opts, $value );
		$adapter = self::resolve_adapter( $type, $key, $ctx );
		if ( $adapter instanceof WP_Error ) {
			return $adapter;
		}
		if ( $delete ) {
			$writes = array();
			foreach ( $adapter->state_keys( $key, $ctx ) as $k ) {
				$writes[] = array( 'key' => $k, 'value' => null, 'delete' => true );
			}
		} else {
			$plan = $adapter->plan( $type, $id, $key, $value, $ctx );
			if ( isset( $plan['error'] ) ) {
				return self::err( (string) $plan['error'], (string) $plan['message'], 422 );
			}
			$writes = $plan['writes'];
		}
		if ( array() === $writes || count( $writes ) > 8 ) {
			return self::err( 'ovp_adapter_invalid', 'Adapter produced no usable writes.', 422 );
		}
		$tier       = (string) ( $opts['tier'] ?? 'T0' );
		$max        = $adapter->max_bytes();
		$entries    = array();
		$write_keys = array();
		foreach ( $writes as $w ) {
			$k = (string) $w['key'];
			$e = self::validate_target( $type, $id, $k );
			if ( null !== $e ) {
				return $e;
			}
			$e = self::authorize( ! empty( $opts['plan_only'] ) ? 'read' : $op, $type, $k, $tier );
			if ( null !== $e ) {
				return $e;
			}
			if ( ! $delete && self::contains_object_payload( $w['value'] ) ) {
				return self::err( 'ovp_object_refused', 'Serialized object payloads are refused.', 422 );
			}
			$before = self::backend()->get_raw( $type, $id, $k );
			if ( count( $before ) > 1 ) {
				return self::err( 'ovp_meta_multiple', 'Key has multiple rows; refusing to write.', 409 );
			}
			$after = ! empty( $w['delete'] ) ? null : self::to_db_form( $w['value'] );
			if ( null !== $after && strlen( $after ) > $max ) {
				return self::err( 'ovp_meta_too_large', 'Value exceeds ' . $max . ' bytes for adapter ' . $adapter->id() . '.', 413 );
			}
			$entries[]    = array(
				'key'    => $k,
				'value'  => $w['value'] ?? null,
				'before' => $before[0] ?? null,
				'after'  => $after,
			);
			$write_keys[] = $k;
		}
		$hash_keys = array_merge( $write_keys, $adapter->state_keys( $key, $ctx ) );
		$current   = self::hash_rows( self::read_rows( $type, $id, $hash_keys ) );
		$bind      = array();
		foreach ( $entries as $e ) {
			$bind[] = array( $e['key'], $e['after'] );
		}
		$change_hash = hash( 'sha256', 'ovp-meta-change-v1|' . $type . '|' . $id . '|' . (string) json_encode( $bind, JSON_INVALID_UTF8_SUBSTITUTE ) . '|' . $current );
		$changed     = false;
		foreach ( $entries as $e ) {
			if ( $e['before'] !== $e['after'] ) {
				$changed = true;
			}
		}
		return compact( 'type', 'id', 'key', 'adapter', 'entries', 'current', 'change_hash', 'changed', 'hash_keys' );
	}

	/** Minimal changed-region diff, capped, redacted for secret-looking keys. */
	/** True when the stored cell has no nested secret-looking keys (and is small enough to have been checked). */
	private static function cell_plain( ?string $x ): bool {
		return null === $x || ( strlen( $x ) <= 262144 && self::redact_cell( $x ) === $x );
	}

	private static function diff_entry( array $e, bool $with_region = true ): array {
		$b = $e['before'];
		$a = $e['after'];
		$d = array(
			'key'     => $e['key'],
			'exists'  => null !== $b,
			'delete'  => null === $a,
			'changed' => $b !== $a,
			'before'  => null === $b ? null : array( 'size' => strlen( $b ), 'sha256' => hash( 'sha256', $b ), 'type' => self::decode_db( $b )['value_type'] ),
			'after'   => null === $a ? null : array( 'size' => strlen( $a ), 'sha256' => hash( 'sha256', $a ), 'type' => self::decode_db( $a )['value_type'] ),
		);
		// Callers below the tier the change needs (the master's T0 dry run) get the region text only when nothing in
		// either cell is secret-looking: exactly what a plain meta/get already shows them.
		$with_region = $with_region || ( self::cell_plain( $b ) && self::cell_plain( $a ) );
		if ( $with_region && $b !== $a && ! self::is_redacted( $e['key'] ) ) {
			$bs = (string) $b;
			$as = (string) $a;
			$p  = 0;
			$m  = min( strlen( $bs ), strlen( $as ) );
			while ( $p < $m && $bs[ $p ] === $as[ $p ] ) {
				++$p;
			}
			$s  = 0;
			while ( $s < $m - $p && $bs[ strlen( $bs ) - 1 - $s ] === $as[ strlen( $as ) - 1 - $s ] ) {
				++$s;
			}
			$cut = static fn( string $x ): string => mb_convert_encoding( substr( $x, 0, 500 ), 'UTF-8', 'UTF-8' );
			$d['region'] = array(
				'offset' => $p,
				'before' => $cut( substr( $bs, $p, strlen( $bs ) - $p - $s ) ),
				'after'  => $cut( substr( $as, $p, strlen( $as ) - $p - $s ) ),
			);
		}
		return $d;
	}

	/**
	 * Dry run: diff plus the hashes a confirm token can bind to. Writes nothing.
	 *
	 * @param array<string, mixed> $opts
	 * @return array<string, mixed>|WP_Error
	 */
	public static function prepare( string $type, int $id, string $key, mixed $value, array $opts = array() ): array|WP_Error {
		$opts['plan_only'] = true;
		$p = self::plan_change( $type, $id, $key, $value, $opts, ! empty( $opts['delete'] ) );
		if ( $p instanceof WP_Error ) {
			return $p;
		}
		$need = self::required_tier( ! empty( $opts['delete'] ) ? 'delete' : 'write', $type, $key );
		// Region text for callers that could make the change; below that, only when diff_entry finds no secrets in the cells.
		$may  = null !== $need && OVP_Upload::tier_rank( (string) ( $opts['tier'] ?? 'T0' ) ) >= OVP_Upload::tier_rank( $need );
		return array(
			'dry_run'       => true,
			'type'          => $type,
			'id'            => $id,
			'key'           => $key,
			'adapter'       => $p['adapter']->id(),
			'changed'       => $p['changed'],
			'current_hash'  => $p['current'],
			'change_hash'   => $p['change_hash'],
			'required_tier' => self::required_tier( ! empty( $opts['delete'] ) ? 'delete' : 'write', $type, $key ),
			'diff'          => array_map( static fn( array $e ): array => self::diff_entry( $e, $may ), $p['entries'] ),
		);
	}

	/**
	 * Snapshot then write. expected_hash (state) and change_hash (exact change) are checked
	 * when supplied; a mismatch returns 409 and writes nothing.
	 *
	 * @param array<string, mixed> $opts tier, adapter, field_key, as, expected_hash, change_hash, dry_run, actor, delete
	 * @return array<string, mixed>|WP_Error
	 */
	public static function commit( string $type, int $id, string $key, mixed $value, array $opts = array() ): array|WP_Error {
		$delete = ! empty( $opts['delete'] );
		$p      = self::plan_change( $type, $id, $key, $value, $opts, $delete );
		if ( $p instanceof WP_Error ) {
			return $p;
		}
		if ( isset( $opts['expected_hash'] ) && '' !== (string) $opts['expected_hash'] && ! hash_equals( $p['current'], strtolower( (string) $opts['expected_hash'] ) ) ) {
			return self::err( 'ovp_state_changed', 'State changed since the hash was taken.', 409, array( 'current_hash' => $p['current'] ) );
		}
		if ( isset( $opts['change_hash'] ) && '' !== (string) $opts['change_hash'] && ! hash_equals( $p['change_hash'], strtolower( (string) $opts['change_hash'] ) ) ) {
			return self::err( 'ovp_change_mismatch', 'change_hash does not match this exact change.', 409 );
		}
		if ( ! empty( $opts['dry_run'] ) ) {
			return self::prepare( $type, $id, $key, $value, $opts );
		}
		if ( ! $p['changed'] ) {
			return array( 'changed' => false, 'type' => $type, 'id' => $id, 'key' => $key, 'new_hash' => $p['current'], 'snapshot_id' => null );
		}
		$tier = (string) ( $opts['tier'] ?? 'T0' );
		$snap_entries  = array();
		$after_rows    = array();
		foreach ( $p['entries'] as $e ) {
			$snap_entries[]       = array( 'key' => $e['key'], 'existed' => null !== $e['before'], 'raw' => $e['before'] );
			$after_rows[ $e['key'] ] = null === $e['after'] ? array() : array( $e['after'] );
		}
		$snap = OVP_Meta_Snapshots::save(
			array(
				'type'       => $type,
				'entity_id'  => $id,
				'key'        => $key,
				'actor'      => substr( (string) ( $opts['actor'] ?? '' ), 0, 120 ),
				'tier'       => max( self::required_tier( $delete ? 'delete' : 'write', $type, $key ) ?? 'T3', 'T1' ),
				'entries'    => $snap_entries,
				'after_hash' => self::hash_rows( $after_rows ),
			)
		);
		if ( $snap instanceof WP_Error ) {
			return $snap;
		}
		$adapter = $p['adapter'];
		$failed  = null;
		foreach ( $p['entries'] as $e ) {
			if ( null === $e['after'] ) {
				self::backend()->delete( $type, $id, $e['key'] );
				continue;
			}
			if ( $e['before'] === $e['after'] ) {
				continue;
			}
			$done = $adapter->custom_write( $type, $id, $e['key'], $e['value'] );
			if ( true !== $done ) {
				self::backend()->write( $type, $id, $e['key'], $e['value'] );
			}
			$now = self::backend()->get_raw( $type, $id, $e['key'] );
			// kses / register_meta sanitizers may legitimately normalize post_content and CRUD
			// writers may normalize their fields: for those only require the value to exist.
			$lenient = true === $done || '@post_content' === $e['key'];
			if ( $lenient ? array() === $now : ( ! isset( $now[0] ) || $now[0] !== $e['after'] ) ) {
				$failed = $e['key'];
				break;
			}
		}
		if ( null !== $failed ) {
			self::restore_entries( $type, $id, $snap_entries );
			return self::err( 'ovp_write_failed', 'Write verification failed for ' . $failed . '; previous values restored.', 500, array( 'snapshot_id' => $snap ) );
		}
		try {
			$adapter->after_write( $type, $id, $key, self::backend() );
		} catch ( \Throwable $t ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			unset( $t );
		}
		$new_hash = self::hash_rows( self::read_rows( $type, $id, $p['hash_keys'] ) );
		return array(
			'changed'     => true,
			'type'        => $type,
			'id'          => $id,
			'key'         => $key,
			'adapter'     => $adapter->id(),
			'snapshot_id' => $snap,
			'new_hash'    => $new_hash,
			'keys_written'=> array_map( static fn( array $e ): string => $e['key'], $p['entries'] ),
		);
	}

	/**
	 * @param array<string, mixed> $opts tier, expected_hash, adapter, field_key, actor
	 * @return array<string, mixed>|WP_Error
	 */
	public static function delete( string $type, int $id, string $key, array $opts = array() ): array|WP_Error {
		return self::commit( $type, $id, $key, null, array_merge( $opts, array( 'delete' => true ) ) );
	}

	/** @param array<int, array<string, mixed>> $entries */
	private static function restore_entries( string $type, int $id, array $entries ): void {
		foreach ( $entries as $e ) {
			if ( ! empty( $e['existed'] ) && is_string( $e['raw'] ) ) {
				self::backend()->set_raw( $type, $id, (string) $e['key'], $e['raw'] );
			} else {
				self::backend()->delete( $type, $id, (string) $e['key'] );
			}
		}
	}

	/**
	 * Restore a snapshot. Refuses (409) if the value changed since the snapshotted write
	 * unless force=true with T3.
	 *
	 * @param array<string, mixed> $opts tier, force
	 * @return array<string, mixed>|WP_Error
	 */
	public static function rollback( string $snapshot_id, array $opts = array() ): array|WP_Error {
		$s = OVP_Meta_Snapshots::load( $snapshot_id );
		if ( $s instanceof WP_Error ) {
			return $s;
		}
		$type = (string) $s['type'];
		$id   = (int) $s['entity_id'];
		$tier = (string) ( $opts['tier'] ?? 'T0' );
		foreach ( (array) $s['entries'] as $e ) {
			$err = self::authorize( 'write', $type, (string) $e['key'], $tier );
			if ( null !== $err ) {
				return $err;
			}
		}
		$keys = array_map( static fn( array $e ): string => (string) $e['key'], (array) $s['entries'] );
		$now  = self::hash_rows( self::read_rows( $type, $id, $keys ) );
		if ( ! hash_equals( (string) $s['after_hash'], $now ) ) {
			if ( empty( $opts['force'] ) || OVP_Upload::tier_rank( $tier ) < 3 ) {
				return self::err( 'ovp_state_changed', 'Value changed after the snapshotted write. force=true needs T3.', 409, array( 'current_hash' => $now ) );
			}
		}
		self::restore_entries( $type, $id, (array) $s['entries'] );
		OVP_Meta_Snapshots::mark_rolled_back( $snapshot_id );
		return array( 'rolled_back' => true, 'type' => $type, 'id' => $id, 'keys' => $keys );
	}
}
