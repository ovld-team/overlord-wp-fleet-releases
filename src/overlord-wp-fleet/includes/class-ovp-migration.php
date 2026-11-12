<?php
/**
 * Migration from the legacy 1.x plugin (wp-mcp-bridge): unattended pairing ("autopair") and
 * retirement of the old plugin on request (docs/PROTOCOL.md section 13).
 *
 * Autopair. A master that already manages the site through the legacy bridge installs this plugin
 * and writes ONE option, `ovp_autopair` (JSON string or array):
 *
 *   { code, fp, master_url, expires, mode?, relay_url?, relay_token?, created? }
 *
 * On plugin activation and on admin_init (throttled) the option is consumed: it is deleted FIRST
 * (before any network call, so it is one-shot and the code never outlives the attempt, whatever the
 * outcome), then the normal Ovp_Pair flow runs with consent implied. Writing that option already
 * needs an administrator-level write on the site (the legacy options API, wp-admin, WP-CLI), which
 * is a strictly stronger capability than the consent screen protects, so the screen is skipped; the
 * pairing proofs themselves are unchanged (fingerprint pin, proof of possession, master transcript
 * signature, refusal to re-bind). The pairing code is never persisted or logged. Only a status
 * record without secrets is kept (transient) and shown in the settings page status panel.
 *
 * Pairing NEVER touches the legacy plugin: harnesses keep using the old bridge until the operator
 * finalizes the move, and deactivating it at pairing time would cut a live connection. Retirement
 * happens only through the signed T3 route `fleet/legacy-retire` (Ovp_Module_Fleet), which calls
 * Ovp_Migration::retire().
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ovp_Migration {

	public const OPTION       = 'ovp_autopair';
	public const STATUS       = 'ovp_autopair_status'; // Transient: secret-free outcome for the status panel.
	public const STATUS_OPT   = 'ovp_autopair_result'; // Same record as a plain option: a persistent object cache can drop or never share the transient.
	public const CHECK_OPTION = 'ovp_autopair_chk';    // Autoloaded timestamp that throttles admin_init.
	public const THROTTLE     = 60;                    // Seconds between admin_init looks at the option.
	public const MAX_LIFETIME = 900;                   // 15 minutes: a code is never honoured longer.
	public const CLOCK_SKEW   = 120;                   // Tolerated master/site clock difference.
	public const STATUS_TTL   = 86400;

	private const LEGACY_DIR  = 'wp-mcp-bridge';
	private const LEGACY_TEXT = 'wp-mcp-bridge';

	/** True once this request deactivated the legacy plugin (its classes stay loaded until the request ends). */
	private static bool $retired = false;

	public static function hooks(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybe_run' ) );
	}

	/** admin_init entry: at most one look per THROTTLE seconds, plus a credential sweep once the legacy plugin is gone. */
	public static function maybe_run(): void {
		$now = time();
		if ( $now - (int) get_option( self::CHECK_OPTION, 0 ) < self::THROTTLE ) {
			return;
		}
		update_option( self::CHECK_OPTION, $now, true );
		self::run( 'admin_init', $now );
		if ( ! self::legacy_active() ) {
			Ovp_Bridge::purge_legacy(); // Legacy plugin removed by hand: its token/hop/enroll must not linger.
		}
	}

	/**
	 * Consume the autopair option if present.
	 *
	 * @return array{ran:bool,ok?:bool,code?:string,site_id?:string,legacy?:string}
	 */
	public static function run( string $source, ?int $now = null ): array {
		$now = $now ?? time();
		$raw = get_option( self::OPTION, false );
		if ( false === $raw ) {
			return array( 'ran' => false );
		}
		// One-shot: claim by deleting BEFORE anything else. Losing the race (already gone) means another request took it.
		if ( ! delete_option( self::OPTION ) ) {
			return array( 'ran' => false );
		}
		try {
			$res = self::attempt( $raw, $now );
		} catch ( Throwable $e ) {
			$res = array( 'ok' => false, 'code' => 'error' ); // Message withheld: it could carry request data.
		}
		unset( $raw );
		self::record( $res, $source, $now );
		return array( 'ran' => true ) + $res;
	}

	/**
	 * @param mixed $raw
	 * @return array{ok:bool,code:string,site_id?:string,legacy?:string}
	 */
	private static function attempt( mixed $raw, int $now ): array {
		$fail = static fn( string $code ): array => array( 'ok' => false, 'code' => $code );
		if ( Ovp_Keys::is_bound() ) {
			return $fail( 'already_paired' );
		}
		$opt = self::parse( $raw );
		if ( null === $opt ) {
			return $fail( 'bad_option' );
		}
		if ( $opt['expires'] <= $now || ( $opt['created'] > 0 && $now - $opt['created'] > self::MAX_LIFETIME ) ) {
			return $fail( 'expired' );
		}
		if ( $opt['expires'] - $now > self::MAX_LIFETIME + self::CLOCK_SKEW ) {
			return $fail( 'bad_expiry' ); // A longer-lived code than the protocol allows is not ours.
		}
		$saved = Ovp_Enroll::save_config( $opt['mode'], $opt['master_url'], $opt['relay_url'], $opt['relay_token'] );
		if ( is_wp_error( $saved ) ) {
			return $fail( (string) $saved->get_error_code() );
		}
		$consent = Ovp_Enroll::consent();
		$res     = Ovp_Enroll::pair( $opt['code'] . '.' . $opt['fp'], $consent['hash'], true );
		unset( $opt );
		if ( empty( $res['ok'] ) ) {
			return $fail( (string) ( $res['code'] ?? 'pair_failed' ) );
		}
		return array( 'ok' => true, 'code' => 'paired', 'site_id' => (string) ( $res['site_id'] ?? '' ) );
	}

	/**
	 * Validate the option payload. Never returns anything but the exact fields it uses.
	 *
	 * @return array{code:string,fp:string,master_url:string,expires:int,created:int,mode:string,relay_url:string,relay_token:string}|null
	 */
	private static function parse( mixed $raw ): ?array {
		if ( is_string( $raw ) ) {
			if ( strlen( $raw ) > 4096 ) {
				return null;
			}
			$raw = json_decode( $raw, true, 4 );
		}
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$str  = static fn( string $k ): string => is_string( $raw[ $k ] ?? null ) ? trim( $raw[ $k ] ) : '';
		$code = strtolower( str_replace( array( '-', ' ' ), '', $str( 'code' ) ) );
		$fp   = strtolower( $str( 'fp' ) );
		if ( 1 !== preg_match( '/^[0-9a-f]{32}$/D', $code ) || 1 !== preg_match( '/^[0-9a-f]{16}$/D', $fp ) ) {
			return null;
		}
		$expires = $raw['expires'] ?? null;
		if ( ! is_int( $expires ) && ! ( is_string( $expires ) && 1 === preg_match( '/^[0-9]{1,12}$/D', $expires ) ) ) {
			return null;
		}
		$created = $raw['created'] ?? 0;
		$mode    = '' !== $str( 'mode' ) ? $str( 'mode' ) : 'remote';
		if ( ! in_array( $mode, Ovp_Keys::MODES, true ) ) {
			return null;
		}
		return array(
			'code'        => $code,
			'fp'          => $fp,
			'master_url'  => $str( 'master_url' ),
			'expires'     => (int) $expires,
			'created'     => is_numeric( $created ) ? (int) $created : 0,
			'mode'        => $mode,
			'relay_url'   => $str( 'relay_url' ),
			'relay_token' => $str( 'relay_token' ),
		);
	}

	/** @param array{ok:bool,code:string,site_id?:string,legacy?:string} $res */
	private static function record( array $res, string $source, int $now ): void {
		$rec = array(
			'at'      => $now,
			'ok'      => (bool) $res['ok'],
			'code'    => (string) $res['code'],
			'site_id' => (string) ( $res['site_id'] ?? '' ),
			'legacy'  => (string) ( $res['legacy'] ?? '' ),
			'source'  => $source,
		);
		set_transient( self::STATUS, $rec, self::STATUS_TTL );
		update_option( self::STATUS_OPT, $rec, false );
	}

	/** @return array{at:int,ok:bool,code:string,site_id:string,legacy:string,source:string}|null */
	public static function status(): ?array {
		$v = get_transient( self::STATUS );
		if ( ! is_array( $v ) ) {
			$v = get_option( self::STATUS_OPT, false );
			if ( is_array( $v ) && time() - (int) ( $v['at'] ?? 0 ) > self::STATUS_TTL ) {
				$v = false;
			}
		}
		return is_array( $v ) && isset( $v['at'], $v['ok'], $v['code'] ) ? $v + array( 'site_id' => '', 'legacy' => '', 'source' => '' ) : null;
	}

	/* ---------- legacy plugin detection ---------- */

	/** Legacy classes/constants are present in THIS process (it was loaded before or after us). */
	public static function legacy_loaded(): bool {
		return defined( 'WP_MCP_BRIDGE_VERSION' ) || class_exists( 'MCP_Bridge', false );
	}

	private static function is_legacy_path( string $file ): bool {
		$file = strtolower( str_replace( '\\', '/', $file ) );
		return str_starts_with( $file, self::LEGACY_DIR . '/' ) || 'wp-mcp-bridge.php' === $file;
	}

	/** @return string[] active plugin files (site and network) */
	private static function active_files(): array {
		$files = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $f ) {
			if ( is_string( $f ) ) {
				$files[] = $f;
			}
		}
		if ( is_multisite() ) {
			foreach ( array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) as $f ) {
				if ( is_string( $f ) ) {
					$files[] = $f;
				}
			}
		}
		return $files;
	}

	/**
	 * Is the legacy plugin active (or running in this request)? Cheap: option reads only, no plugin
	 * header scan, because Ovp_Fatal calls it on a front-end hook.
	 */
	public static function legacy_active(): bool {
		if ( self::$retired ) {
			return false;
		}
		foreach ( self::active_files() as $f ) {
			if ( self::is_legacy_path( $f ) ) {
				return true;
			}
		}
		return self::legacy_loaded(); // Running but not recognisable by path (renamed folder): stay conservative.
	}

	/**
	 * Active legacy plugin files, found by path or by the 1.x text domain (renamed folder).
	 *
	 * @return string[]
	 */
	private static function legacy_active_files(): array {
		$active = self::active_files();
		$found  = array();
		foreach ( $active as $f ) {
			if ( self::is_legacy_path( $f ) ) {
				$found[] = $f;
			}
		}
		if ( array() === $found ) {
			if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) && is_file( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$all = function_exists( 'get_plugins' ) ? get_plugins() : array();
			foreach ( $active as $f ) {
				if ( isset( $all[ $f ] ) && self::LEGACY_TEXT === (string) ( $all[ $f ]['TextDomain'] ?? '' ) ) {
					$found[] = $f;
				}
			}
		}
		return array_values( array_unique( $found ) );
	}

	/**
	 * Retire the legacy plugin on the master's request: deactivate it, wipe its credentials, and delete
	 * its files only when asked. Idempotent: a second call finds nothing to do and still answers success.
	 *
	 * The caller (signed T3 route) guarantees the site is paired with the requesting master, so nothing
	 * is left without a working control path. This plugin's own files are never touched.
	 *
	 * @return array{retired:bool,deactivated:bool,deactivated_files:string[],options_wiped:int,files_deleted:bool,purged:bool,deleted_files:bool,delete_refused?:string}
	 */
	public static function retire( bool $delete_files = false ): array {
		$files = array_values( array_filter( self::legacy_active_files(), static fn( string $f ): bool => ! self::is_self_file( $f ) ) );
		if ( array() !== $files ) {
			if ( defined( 'ABSPATH' ) && is_file( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$network = false;
			if ( is_multisite() ) {
				$sitewide = (array) get_site_option( 'active_sitewide_plugins', array() );
				foreach ( $files as $f ) {
					$network = $network || isset( $sitewide[ $f ] );
				}
			}
			deactivate_plugins( $files, true, $network );
		}
		self::$retired = true;
		$wiped = Ovp_Bridge::wipe_legacy_options();
		self::remove_legacy_oob_hashes();

		$deleted = false;
		$refused = '';
		if ( $delete_files ) {
			$r       = self::delete_legacy_files();
			$deleted = $r['deleted'];
			$refused = $r['refused'];
		}
		Ovp_Fatal::install(); // The legacy drop-ins are replaced now that nothing else owns them.

		$out = array(
			'retired'           => true,
			'deactivated'       => array() !== $files,
			'deactivated_files' => $files,
			'options_wiped'     => $wiped,
			'files_deleted'     => $deleted,
			'purged'            => true, // Credentials are gone after this call, whether or not any were left.
			'deleted_files'     => $deleted,
		);
		if ( '' !== $refused ) {
			$out['delete_refused'] = $refused;
		}
		return $out;
	}

	private static function remove_legacy_oob_hashes(): void {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			return;
		}
		$dir = trailingslashit( WP_CONTENT_DIR ) . 'uploads/mcp-bridge';
		foreach ( array( 'oob.sha256', 'oob-hop.sha256' ) as $leaf ) {
			if ( is_file( $dir . '/' . $leaf ) ) {
				@unlink( $dir . '/' . $leaf ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
	}

	/** Is this plugin file (relative to the plugins dir) this plugin itself? Never deactivated or deleted here. */
	private static function is_self_file( string $file ): bool {
		$norm = strtolower( str_replace( '\\', '/', $file ) );
		if ( str_starts_with( $norm, 'overlord-wp-fleet/' ) ) {
			return true;
		}
		$self = defined( 'OVP_FLEET_DIR' ) ? realpath( (string) OVP_FLEET_DIR ) : false;
		if ( false === $self || ! defined( 'WP_PLUGIN_DIR' ) ) {
			return false;
		}
		$seg  = explode( '/', str_replace( '\\', '/', $file ) )[0];
		$cand = '' === $seg ? false : realpath( WP_PLUGIN_DIR . '/' . $seg );
		return false !== $cand && ( $cand === $self || str_starts_with( $self, $cand . DIRECTORY_SEPARATOR ) );
	}

	/**
	 * Top-level entries of the plugins dir that belong to the legacy plugin (folder or single file),
	 * installed or active: by path, by the 1.x text domain (renamed folder), or the stock folder.
	 * Entries that turn out to be this plugin are listed on purpose: delete_legacy_files() refuses them.
	 *
	 * @return string[] names relative to WP_PLUGIN_DIR, one segment each
	 */
	private static function legacy_entries(): array {
		$entries = array();
		foreach ( self::legacy_active_files() as $f ) {
			$entries[] = $f;
		}
		if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) && is_file( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = function_exists( 'get_plugins' ) ? get_plugins() : array();
		foreach ( $all as $f => $hdr ) {
			if ( is_string( $f ) && ( self::is_legacy_path( $f ) || self::LEGACY_TEXT === (string) ( $hdr['TextDomain'] ?? '' ) ) ) {
				$entries[] = $f;
			}
		}
		$entries[] = self::LEGACY_DIR;
		$out       = array();
		foreach ( $entries as $f ) {
			$seg = explode( '/', str_replace( '\\', '/', $f ) )[0];
			if ( '' !== $seg && '.' !== $seg && '..' !== $seg ) {
				$out[ $seg ] = $seg;
			}
		}
		return array_values( $out );
	}

	/**
	 * Delete the legacy plugin's files. Each target must resolve (realpath) strictly inside WP_PLUGIN_DIR
	 * and must not be, contain or point at this plugin's own directory; symlinks are removed, never followed.
	 *
	 * @return array{deleted:bool,refused:string} deleted = nothing of the legacy plugin is left and nothing was refused
	 */
	private static function delete_legacy_files(): array {
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			return array( 'deleted' => false, 'refused' => 'no_plugin_dir' );
		}
		$root = realpath( WP_PLUGIN_DIR );
		$self = defined( 'OVP_FLEET_DIR' ) ? realpath( (string) OVP_FLEET_DIR ) : false;
		if ( false === $root ) {
			return array( 'deleted' => false, 'refused' => 'no_plugin_dir' );
		}
		$refused = '';
		foreach ( self::legacy_entries() as $seg ) {
			$path = $root . DIRECTORY_SEPARATOR . $seg;
			if ( ! file_exists( $path ) && ! is_link( $path ) ) {
				continue; // Already gone: idempotent.
			}
			$real = realpath( $path );
			if ( false !== $real && false !== $self && ( $real === $self || str_starts_with( $self, $real . DIRECTORY_SEPARATOR ) || $self === dirname( $real ) ) ) {
				$refused = 'self';
				continue;
			}
			if ( is_link( $path ) ) {
				if ( ! @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					$refused = '' !== $refused ? $refused : 'unlink_failed';
				}
				continue;
			}
			if ( false === $real || ! str_starts_with( $real, $root . DIRECTORY_SEPARATOR ) ) {
				$refused = '' !== $refused ? $refused : 'outside_plugin_dir';
				continue;
			}
			if ( ! self::remove_tree( $real ) ) {
				$refused = '' !== $refused ? $refused : 'delete_failed';
			}
		}
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( true );
		}
		return array( 'deleted' => '' === $refused, 'refused' => $refused );
	}

	/** Recursive delete that unlinks symlinks instead of following them. */
	private static function remove_tree( string $path ): bool {
		if ( is_link( $path ) || is_file( $path ) ) {
			return @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		if ( ! is_dir( $path ) ) {
			return true;
		}
		$ok = true;
		foreach ( new FilesystemIterator( $path, FilesystemIterator::SKIP_DOTS ) as $item ) {
			$ok = self::remove_tree( $item->getPathname() ) && $ok;
		}
		return @rmdir( $path ) && $ok; // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
}
