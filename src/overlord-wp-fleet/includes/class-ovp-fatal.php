<?php
/**
 * Install/remove fatal drop-ins and the oob token hash. Hostinger has no cPanel
 * error_log; these files are the probe that still works when REST is 500.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ovp_Fatal {

	public const MARKER     = 'MCP_BRIDGE_FATAL_DROPIN';
	public const SHOW_OPT   = 'mcp_bridge_show_fatals';
	public const STORE_REL  = 'uploads/mcp-bridge';

	public static function store_dir(): string {
		$dir = trailingslashit( WP_CONTENT_DIR ) . self::STORE_REL;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$index_html = $dir . '/index.html';
		if ( ! is_file( $index_html ) ) {
			file_put_contents( $index_html, '' );
		}
		$index_php = $dir . '/index.php';
		if ( ! is_file( $index_php ) ) {
			file_put_contents( $index_php, "<?php\nhttp_response_code( 403 );\nexit;\n" );
		}
		$htaccess = $dir . '/.htaccess';
		if ( ! is_file( $htaccess ) ) {
			file_put_contents(
				$htaccess,
				"<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n<IfModule LiteSpeed>\nRewriteEngine On\nRewriteRule .* - [F,L]\n</IfModule>\nOptions -Indexes\n"
			);
		}
		return $dir;
	}

	public static function ensure_installed(): void {
		$ver = (string) get_option( 'mcp_bridge_fatal_dropin', '' );
		if ( OVP_FLEET_VERSION !== $ver ) {
			self::install();
			return;
		}
		self::write_oob_hash();
	}

	public static function install(): array {
		// The legacy 1.x plugin owns the same drop-in files (same names, same marker). Overwriting them
		// while it runs would flip-flop with its own installer; Ovp_Migration::retire installs ours.
		if ( Ovp_Migration::legacy_active() ) {
			return array(
				'installed' => array( 'skipped' => 'legacy-active' ),
				'store'     => 'wp-content/' . self::STORE_REL,
				'showing'   => false,
			);
		}
		$dir = self::store_dir();
		self::write_oob_hash();

		$copied = array();
		$root = trailingslashit( dirname( __DIR__ ) );
		$copied['fatal-error-handler'] = self::copy_dropin(
			$root . 'dropins/fatal-error-handler.php',
			trailingslashit( WP_CONTENT_DIR ) . 'fatal-error-handler.php'
		);
		$copied['oob'] = self::copy_dropin(
			$root . 'dropins/mcp-bridge-oob.php',
			trailingslashit( WP_CONTENT_DIR ) . 'mcp-bridge-oob.php'
		);
		$copied['oob-lib'] = self::copy_dropin(
			$root . 'dropins/mcp-bridge-oob-lib.php',
			trailingslashit( WP_CONTENT_DIR ) . 'mcp-bridge-oob-lib.php'
		);

		$mu = trailingslashit( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' );
		if ( ! is_dir( $mu ) ) {
			wp_mkdir_p( $mu );
		}
		$copied['mu'] = self::copy_dropin(
			$root . 'dropins/000-mcp-bridge-fatal.php',
			$mu . '000-mcp-bridge-fatal.php'
		);

		$failed = array_filter(
			$copied,
			static fn( string $state ): bool => in_array( $state, array( 'copy-failed', 'missing-src' ), true )
		);
		if ( array() === $failed ) {
			update_option( 'mcp_bridge_fatal_dropin', OVP_FLEET_VERSION, false );
		}

		return array(
			'installed' => $copied,
			'store'     => str_replace( WP_CONTENT_DIR, 'wp-content', $dir ),
			'showing'   => self::is_showing(),
		);
	}

	public static function write_oob_hash(): void {
		$token = (string) get_option( OVP_FLEET_TOKEN_OPTION, '' );
		if ( '' === $token ) {
			return;
		}
		$next = hash( 'sha256', $token );
		$path = self::store_dir() . '/oob.sha256';
		$cur  = is_readable( $path ) ? trim( (string) file_get_contents( $path ) ) : '';
		if ( '' === $cur || ! hash_equals( $cur, $next ) ) {
			file_put_contents( $path, $next );
		}
		$hop = (string) get_option( OVP_FLEET_HOP_OPTION, '' );
		if ( '' === $hop ) {
			return;
		}
		$hop_next = hash( 'sha256', $hop );
		$hop_path = self::store_dir() . '/oob-hop.sha256';
		$hop_cur  = is_readable( $hop_path ) ? trim( (string) file_get_contents( $hop_path ) ) : '';
		if ( '' === $hop_cur || ! hash_equals( $hop_cur, $hop_next ) ) {
			file_put_contents( $hop_path, $hop_next );
		}
	}

	public static function set_showing( bool $on ): void {
		$flag = self::store_dir() . '/show-fatals.flag';
		if ( $on ) {
			file_put_contents( $flag, '1' );
			update_option( self::SHOW_OPT, '1', false );
		} else {
			if ( is_file( $flag ) ) {
				unlink( $flag );
			}
			delete_option( self::SHOW_OPT );
		}
	}

	public static function is_showing(): bool {
		return is_file( self::store_dir() . '/show-fatals.flag' );
	}

	public static function last_fatal(): ?array {
		$path = self::store_dir() . '/fatal-latest.json';
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$decoded = json_decode( (string) file_get_contents( $path ), true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Last fatal record if it was written at or after $ts (unix seconds). Used by the
	 * guarded plugin/theme activation to detect a fatal that happened in a request the
	 * guard itself could not observe (loopback probe, shutdown after output).
	 *
	 * @return array{time:string,type:int,message:string,file:string,line:int}|null
	 */
	public static function fatal_since( float $ts ): ?array {
		$row = self::last_fatal();
		if ( null === $row || ! isset( $row['time'] ) ) {
			return null;
		}
		$at = strtotime( (string) $row['time'] );
		if ( false === $at || (float) $at < floor( $ts ) ) {
			return null;
		}
		return array(
			'time'    => (string) $row['time'],
			'type'    => (int) ( $row['type'] ?? 0 ),
			'message' => (string) ( $row['message'] ?? '' ),
			'file'    => (string) ( $row['file'] ?? '' ),
			'line'    => (int) ( $row['line'] ?? 0 ),
		);
	}

	/**
	 * Candidate PHP log paths Hostinger / generic shared hosting actually use.
	 *
	 * @return array<int, array{path: string, exists: bool, bytes: int}>
	 */
	public static function candidate_logs(): array {
		$ini = (string) ini_get( 'error_log' );
		$paths = array(
			trailingslashit( WP_CONTENT_DIR ) . 'debug.log',
			self::store_dir() . '/fatal-latest.json',
			trailingslashit( ABSPATH ) . 'error_log',
			trailingslashit( dirname( ABSPATH ) ) . 'logs/error.log',
			trailingslashit( dirname( ABSPATH ) ) . 'logs/error_log',
		);
		if ( '' !== $ini && 'syslog' !== $ini ) {
			array_unshift( $paths, $ini );
		}

		$out = array();
		$seen = array();
		foreach ( $paths as $path ) {
			$real = $path;
			if ( isset( $seen[ $real ] ) ) {
				continue;
			}
			$seen[ $real ] = true;
			$exists        = is_readable( $path );
			$out[]         = array(
				'path'   => $path,
				'exists' => $exists,
				'bytes'  => $exists ? (int) filesize( $path ) : 0,
			);
		}
		return $out;
	}

	public static function tail_file( string $path, int $bytes ): array|WP_Error {
		$allowed = array();
		foreach ( self::candidate_logs() as $row ) {
			$allowed[ $row['path'] ] = true;
		}
		if ( ! isset( $allowed[ $path ] ) ) {
			return new WP_Error( 'mcp_bridge_log_denied', 'Path is not a known PHP log.', array( 'status' => 403 ) );
		}
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'mcp_bridge_not_found', 'Log file not readable.', array( 'status' => 404 ) );
		}
		$size   = (int) filesize( $path );
		$bytes  = max( 256, min( 256000, $bytes ) );
		$offset = max( 0, $size - $bytes );
		$fh     = fopen( $path, 'rb' );
		if ( false === $fh ) {
			return new WP_Error( 'mcp_bridge_read_failed', 'Could not read log.', array( 'status' => 502 ) );
		}
		if ( $offset > 0 ) {
			fseek( $fh, $offset );
		}
		$raw = (string) stream_get_contents( $fh );
		fclose( $fh );
		return array(
			'path'    => $path,
			'size'    => $size,
			'offset'  => $offset,
			'bytes'   => strlen( $raw ),
			'content' => $raw,
		);
	}

	public static function remove_dropins(): void {
		self::unlink_if_ours( trailingslashit( WP_CONTENT_DIR ) . 'fatal-error-handler.php' );
		self::unlink_if_ours( trailingslashit( WP_CONTENT_DIR ) . 'mcp-bridge-oob.php' );
		self::unlink_if_ours( trailingslashit( WP_CONTENT_DIR ) . 'mcp-bridge-oob-lib.php' );
		$mu = trailingslashit( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' );
		self::unlink_if_ours( $mu . '000-mcp-bridge-fatal.php' );
		$flag = self::store_dir() . '/show-fatals.flag';
		if ( is_file( $flag ) ) {
			unlink( $flag );
		}
		delete_option( self::SHOW_OPT );
	}

	private static function copy_dropin( string $src, string $dest ): string {
		if ( ! is_readable( $src ) ) {
			return 'missing-src';
		}
		if ( is_file( $dest ) && ! self::is_ours( $dest ) ) {
			return 'skipped-foreign';
		}
		$ok = @copy( $src, $dest );
		return $ok ? 'copied' : 'copy-failed';
	}

	private static function is_ours( string $path ): bool {
		if ( ! is_readable( $path ) ) {
			return false;
		}
		$head = (string) file_get_contents( $path, false, null, 0, 800 );
		return str_contains( $head, self::MARKER );
	}

	private static function unlink_if_ours( string $path ): void {
		if ( is_file( $path ) && self::is_ours( $path ) ) {
			unlink( $path );
		}
	}
}
