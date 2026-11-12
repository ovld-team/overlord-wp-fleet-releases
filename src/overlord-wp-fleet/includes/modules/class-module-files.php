<?php
/**
 * /mcp-bridge/v1/files/* — read/write/delete under wp-content, or ABSPATH
 * (wp-config.php, .htaccess, wp-admin, wp-includes). Prefix abspath/ or use
 * a known root name (wp-config.php). Jail is the site root, never above it.
 *
 * Writes never touch the live path in place: small writes lint then tmp+rename, large
 * files go through files/upload/{begin,chunk,finalize} (see OVP_Upload). The legacy
 * `offset` chunk write (non-truncating, unlinted, non-atomic) is removed; it was the
 * root cause of "upload via MCP triggers PHP syntax error".
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

class Ovp_Module_Files extends Ovp_Module_Base {

	/** Tier per tool (documentation; the enforced table is Ovp_Auth::ROUTE_TIERS, checked by tests/routes). */
	public const OVP_TIER = array(
		'files_read'            => 'T2',
		'files_write'           => 'T3',
		'files_delete'          => 'T3',
		'files_restore'         => 'T3',
		'files_upload_begin'    => 'T3',
		'files_upload_chunk'    => 'T3',
		'files_upload_finalize' => 'T3',
		'files_upload_status'   => 'T0',
		'files_upload_abort'    => 'T2',
	);

	public function register_routes(): void {
		$this->route( 'GET', 'files', array( $this, 'read_path' ) );
		$this->route( 'POST', 'files', array( $this, 'write_file' ) );
		$this->route( 'DELETE', 'files', array( $this, 'delete_path' ) );
		$this->route( 'POST', 'files/restore', array( $this, 'restore_file' ) );
		$this->route( 'POST', 'files/upload/begin', array( $this, 'upload_begin' ) );
		$this->route( 'POST', 'files/upload/chunk', array( $this, 'upload_chunk' ) );
		$this->route( 'POST', 'files/upload/finalize', array( $this, 'upload_finalize' ) );
		$this->route( 'GET', 'files/upload/status', array( $this, 'upload_status' ) );
		$this->route( 'POST', 'files/upload/abort', array( $this, 'upload_abort' ) );
	}

	/** Fail-closed tier gate (central gate lives in OVP_Tiers; this is defense in depth). */
	private function gate( WP_REST_Request $request, string $need ): ?WP_Error {
		$have = OVP_Meta::request_tier( $request );
		if ( OVP_Upload::tier_rank( $have ) < OVP_Upload::tier_rank( $need ) ) {
			return $this->error( 'ovp_tier_denied', 'Tier ' . $need . ' required.', 403 );
		}
		return null;
	}

	/**
	 * @return array{0: 'content'|'abspath', 1: string}
	 */
	private function split_scope( string $relative ): array {
		$relative = $this->normalize_rel( $relative );
		$lower    = strtolower( $relative );
		if ( str_starts_with( $lower, 'abspath/' ) ) {
			return array( 'abspath', substr( $relative, 8 ) );
		}
		$root = array(
			'wp-config.php',
			'wp-config-sample.php',
			'.htaccess',
			'.user.ini',
			'php.ini',
			'index.php',
			'wp-load.php',
			'wp-settings.php',
			'wp-blog-header.php',
			'wp-cron.php',
			'wp-login.php',
			'xmlrpc.php',
			'.maintenance',
			'wordfence-waf.php',
		);
		if ( in_array( $lower, $root, true ) || str_starts_with( $lower, 'wp-admin/' ) || str_starts_with( $lower, 'wp-includes/' ) ) {
			return array( 'abspath', $relative );
		}
		return array( 'content', $relative );
	}

	/**
	 * Jail to WP_CONTENT_DIR or ABSPATH. Never above the site root.
	 *
	 * With $for_write a missing parent chain is accepted: the target is resolved through the deepest
	 * EXISTING ancestor (realpath, so a symlink out of the root is still refused) plus the missing plain
	 * segments (normalize_rel() already rejected every `..`). Nothing is created here; the writers call
	 * ensure_parent_dir() after every check passed.
	 */
	private function resolve( string $relative, bool $for_write = false ): string|WP_Error {
		if ( str_contains( $relative, "\0" ) ) {
			return new WP_Error( 'mcp_bridge_path_escape', 'Path escapes site root.', array( 'status' => 400 ) );
		}

		[ $scope, $relative ] = $this->split_scope( $relative );
		if ( '' === $relative ) {
			return new WP_Error( 'mcp_bridge_path_escape', 'Path escapes site root.', array( 'status' => 400 ) );
		}
		$lower_rel = strtolower( $relative );
		$god = $this->god();
		if ( str_contains( $lower_rel, 'wp-mcp-bridge' ) || ( ! $god && str_contains( $lower_rel, 'overlord-wp-fleet' ) ) || str_contains( $lower_rel, 'ovp-stage' ) || str_contains( $lower_rel, '.ovp-data' ) ) {
			return new WP_Error( 'mcp_bridge_self_protected', 'Cannot touch this directory. The Overlord plugin folder needs effective tier T3; the legacy plugin and the staging area are never reachable here.', array( 'status' => 403 ) );
		}

		$root = 'abspath' === $scope ? realpath( ABSPATH ) : realpath( WP_CONTENT_DIR );
		$base_fs = 'abspath' === $scope ? rtrim( ABSPATH, '/\\' ) : WP_CONTENT_DIR;
		if ( false === $root ) {
			return new WP_Error( 'mcp_bridge_path_escape', 'Path escapes site root.', array( 'status' => 400 ) );
		}

		if ( '' === $relative || '.' === $relative ) {
			return new WP_Error( 'mcp_bridge_self_protected', 'Cannot operate on the scope root via REST.', array( 'status' => 403 ) );
		}

		$target = realpath( $base_fs . '/' . $relative );
		$parent = false;

		if ( false === $target ) {
			$parent = realpath( dirname( $base_fs . '/' . $relative ) );
			if ( false === $parent && $for_write ) {
				$parent = $this->deepest_existing_parent( dirname( $base_fs . '/' . $relative ), $root );
			}
			if ( false === $parent || ! Ovp_Net::path_inside( $parent, $root ) ) {
				return new WP_Error( 'mcp_bridge_path_escape', 'Path escapes site root.', array( 'status' => 400 ) );
			}
			$target = $parent . '/' . basename( $relative );
		} elseif ( ! Ovp_Net::path_inside( $target, $root ) ) {
			return new WP_Error( 'mcp_bridge_path_escape', 'Path escapes site root.', array( 'status' => 400 ) );
		}

		$plugin_root = defined( 'OVP_FLEET_DIR' ) ? realpath( OVP_FLEET_DIR ) : false;
		if ( is_string( $plugin_root ) && ! $god ) {
			$compare = is_string( $parent ) ? $parent : $target;
			if ( self::deny_inside( $compare, $plugin_root ) || self::deny_inside( $plugin_root, $target ) ) {
				return new WP_Error( 'mcp_bridge_self_protected', 'Cannot touch the Overlord plugin directory below effective tier T3.', array( 'status' => 403 ) );
			}
		}

		return $target;
	}

	/**
	 * Real path of a not-yet-existing directory: realpath of its deepest existing ancestor (inside $root)
	 * plus the missing segments. False when the chain hits a symlink with no target, leaves $root, or runs out.
	 */
	private function deepest_existing_parent( string $dir, string $root ): string|false {
		$tail = array();
		$cur  = $dir;
		for ( $i = 0; $i < 64; $i++ ) {
			if ( is_link( $cur ) ) {
				return false; // A dangling link in the chain: never create through it.
			}
			$real = realpath( $cur );
			if ( false !== $real ) {
				if ( ! Ovp_Net::path_inside( $real, $root ) || ! is_dir( $real ) ) {
					return false;
				}
				return array() === $tail ? $real : $real . '/' . implode( '/', array_reverse( $tail ) );
			}
			$up = dirname( $cur );
			if ( $up === $cur ) {
				return false;
			}
			$tail[] = basename( $cur );
			$cur    = $up;
		}
		return false;
	}

	/**
	 * Create the missing parent directories of a resolved target ONE SEGMENT AT A TIME (non-recursive mkdir), re-checking
	 * after each that the new directory is a real directory (not a link) whose realpath is still inside the root. Stops
	 * on the first failure and removes what it created. $created lists the directories made by this call, outermost first,
	 * so the caller can remove them again (rmdir_created) when the write that follows is refused.
	 *
	 * @param list<string> $created
	 */
	private function ensure_parent_dir( string $path, string $rel, array &$created ): ?WP_Error {
		$created = array();
		$dir     = dirname( $path );
		if ( is_dir( $dir ) ) {
			return null;
		}
		[ $scope ] = $this->split_scope( $rel );
		$root      = realpath( 'abspath' === $scope ? ABSPATH : WP_CONTENT_DIR );
		$escape    = new WP_Error( 'mcp_bridge_path_escape', 'Path escapes site root.', array( 'status' => 400 ) );
		if ( false === $root ) {
			return $escape;
		}
		$missing = array();
		$cur     = $dir;
		for ( $i = 0; $i < 64 && ! is_dir( $cur ); $i++ ) {
			$missing[] = $cur;
			$up        = dirname( $cur );
			if ( $up === $cur ) {
				return $escape;
			}
			$cur = $up;
		}
		$fail = function ( WP_Error $e ) use ( &$created ): WP_Error {
			$this->rmdir_created( $created );
			$created = array();
			return $e;
		};
		foreach ( array_reverse( $missing ) as $d ) {
			if ( is_link( $d ) ) {
				return $fail( $escape );
			}
			if ( ! @mkdir( $d, 0755 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return $fail( $this->error( 'ovp_write_failed', 'Cannot create the destination directory.', 500 ) );
			}
			$created[] = $d;
			$real      = realpath( $d );
			if ( is_link( $d ) || false === $real || ! Ovp_Net::path_inside( $real, $root ) ) {
				return $fail( $escape );
			}
		}
		return null;
	}

	/** Remove directories this request created, innermost first, only while they are empty (rmdir refuses otherwise). @param list<string> $created */
	private function rmdir_created( array $created ): void {
		foreach ( array_reverse( $created ) as $d ) {
			if ( is_dir( $d ) && ! is_link( $d ) ) {
				@rmdir( $d ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- only succeeds when empty.
			}
		}
	}

	/** Prefix check for the protected-path denylists: case-insensitive on case-insensitive filesystems (macOS, Windows). */
	private static function deny_inside( string $path, string $base ): bool {
		if ( in_array( PHP_OS_FAMILY, array( 'Darwin', 'Windows' ), true ) ) {
			return Ovp_Net::path_inside( strtolower( $path ), strtolower( $base ) );
		}
		return Ovp_Net::path_inside( $path, $base );
	}

	private function public_path( string $path ): string {
		$abs = realpath( ABSPATH );
		$abs = is_string( $abs ) ? $abs : rtrim( ABSPATH, '/\\' );
		if ( Ovp_Net::path_inside( $path, $abs ) ) {
			$rel = ltrim( substr( $path, strlen( $abs ) ), '/\\' );
			if ( ! str_starts_with( str_replace( '\\', '/', $rel ), 'wp-content/' ) ) {
				return 'abspath/' . str_replace( '\\', '/', $rel );
			}
		}
		return str_replace( WP_CONTENT_DIR, 'wp-content', $path );
	}

	private function filesystem(): WP_Filesystem_Direct {
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		if ( ! defined( 'FS_CHMOD_FILE' ) ) {
			define( 'FS_CHMOD_FILE', 0644 );
		}
		if ( ! defined( 'FS_CHMOD_DIR' ) ) {
			define( 'FS_CHMOD_DIR', 0755 );
		}
		return new WP_Filesystem_Direct( array() );
	}

	/**
	 * D18: the two scope roots can be LISTED. '', '.', '/', 'abspath' and 'abspath/' name the WordPress root
	 * (ABSPATH); 'wp-content' names WP_CONTENT_DIR (unless a real wp-content/wp-content folder exists). Only
	 * directory listings use this: names, no file bytes. Writes and deletes never resolve a scope root.
	 * Any '..', ':' or NUL still fails in resolve().
	 */
	public function root_listing_path( string $raw ): ?string {
		if ( str_contains( $raw, "\0" ) || str_contains( $raw, ':' ) || str_contains( str_replace( '\\', '/', $raw ), '..' ) ) {
			return null;
		}
		$rel = strtolower( $this->normalize_rel( $raw ) );
		if ( '' === $rel || 'abspath' === $rel ) {
			$root = realpath( ABSPATH );
			return false === $root ? null : $root;
		}
		if ( 'wp-content' === $rel && false === realpath( WP_CONTENT_DIR . '/wp-content' ) ) {
			$root = realpath( WP_CONTENT_DIR );
			return false === $root ? null : $root;
		}
		return null;
	}

	public function read_path( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$raw_path = $this->str( $request->get_param( 'path' ) );
		$path     = $this->root_listing_path( $raw_path ) ?? $this->resolve( $raw_path );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$deny = $this->read_blocked_resolved( $path );
		if ( null !== $deny ) {
			return $deny;
		}

		$fs = $this->filesystem();

		if ( $fs->is_dir( $path ) ) {
			// PHP turns a numeric folder name ("2024") into an int array key: force strings (D17).
			$list    = array_map( 'strval', array_keys( (array) $fs->dirlist( $path ) ) );
			sort( $list, SORT_STRING );
			$cursor  = (string) $request->get_param( 'cursor' );
			$max     = Ovp_Chunk::max_items( $request->get_param( 'max_items' ), 200 );
			$page    = array();
			$next    = '';
			foreach ( $list as $name ) {
				if ( '' !== $cursor && strcmp( (string) $name, $cursor ) < 0 ) {
					continue;
				}
				if ( count( $page ) >= $max ) {
					$next = (string) $name;
					break;
				}
				$page[] = $name;
			}
			return $this->success(
				Ovp_Chunk::envelope(
					array(
						'type'    => 'dir',
						'entries' => $page,
						'total'   => count( $list ),
					),
					$next
				)
			);
		}

		if ( ! $fs->exists( $path ) ) {
			return $this->error( 'mcp_bridge_not_found', 'Path does not exist.', 404 );
		}

		$size = (int) $fs->size( $path );
		if ( $size < 0 ) {
			return $this->error( 'mcp_bridge_file_too_large', 'filesize overflow. Use 64-bit PHP for files >2GB.', 413 );
		}
		if ( 1 === preg_match( '/^wp-config[^\/]*\.php$/i', basename( $path ) ) ) {
			return $this->read_wp_config( $path, $size );
		}
		$offset = max( 0, (int) ( $request->get_param( 'offset' ) ?: 0 ) );
		$max    = Ovp_Chunk::max_bytes( $request->get_param( 'max_bytes' ), 512000 );
		$take   = min( $max, max( 0, $size - $offset ) );
		$fh = fopen( $path, 'rb' );
		if ( false === $fh ) {
			return $this->error( 'mcp_bridge_read_failed', 'Could not read file.', 502 );
		}
		if ( $offset > 0 ) {
			fseek( $fh, $offset );
		}
		$raw = $take > 0 ? (string) fread( $fh, $take ) : '';
		fclose( $fh );
		$next = ( $offset + strlen( $raw ) ) < $size ? (string) ( $offset + strlen( $raw ) ) : '';
		return $this->success(
			Ovp_Chunk::envelope(
				array(
					'type'     => 'file',
					'size'     => $size,
					'offset'   => $offset,
					'bytes'    => strlen( $raw ),
					'content'  => base64_encode( $raw ),
					'encoding' => 'base64',
				),
				$next
			)
		);
	}

	/**
	 * wp-config is readable but secrets never leave: salts, DB password and any
	 * *_SECRET/*_TOKEN/*_PASSWORD/*_API_KEY constant are masked.
	 */
	private function read_wp_config( string $path, int $size ): WP_REST_Response|WP_Error {
		if ( $size > 65536 ) {
			return $this->error( 'mcp_bridge_file_too_large', 'wp-config larger than 64KB is not served.', 413 );
		}
		$raw = (string) file_get_contents( $path );
		$re  = '/(define\s*\(\s*[\'"](?:AUTH_KEY|SECURE_AUTH_KEY|LOGGED_IN_KEY|NONCE_KEY|AUTH_SALT|SECURE_AUTH_SALT|LOGGED_IN_SALT|NONCE_SALT|DB_PASSWORD|[A-Z0-9_]*(?:SECRET|TOKEN|API_KEY|PASSWORD)[A-Z0-9_]*)[\'"]\s*,\s*)(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/i';
		$out = preg_replace( $re, "$1'***redacted***'", $raw );
		$out = is_string( $out ) ? $out : '';
		return $this->success(
			Ovp_Chunk::envelope(
				array(
					'type'     => 'file',
					'size'     => strlen( $out ),
					'offset'   => 0,
					'bytes'    => strlen( $out ),
					'content'  => base64_encode( $out ),
					'encoding' => 'base64',
					'redacted' => $out !== $raw,
				),
				''
			)
		);
	}

	public function write_file( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->gate( $request, 'T3' );
		if ( null !== $gate ) {
			return $gate;
		}
		$body    = (array) $request->get_json_params();
		$confirm = ! empty( $body['confirm'] );
		$rel     = $this->normalize_rel( $this->str( $body['path'] ?? '' ) );
		if ( '' === $rel ) {
			return $this->error( 'mcp_bridge_path_escape', 'Path escapes site root.', 400 );
		}
		$blocked = $this->write_blocked_reason( $rel, $confirm );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$path = $this->resolve( $rel, true );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$blocked = $this->write_blocked_resolved( $path );
		if ( null !== $blocked ) {
			return $blocked;
		}
		if ( array_key_exists( 'offset', $body ) && null !== $body['offset'] ) {
			return $this->error(
				'ovp_use_upload_pipeline',
				'Offset writes are removed. Use files/upload/begin, files/upload/chunk (sha256 per chunk) and files/upload/finalize.',
				400
			);
		}

		$encoding = (string) ( $body['encoding'] ?? 'text' );
		$content  = $this->str( $body['content'] ?? '' );
		if ( array_key_exists( 'patch', $body ) && null !== $body['patch'] ) {
			// D14: patch mode edits the UNREDACTED file on the server, so the redacted read never has to be
			// written back. Backup + php lint then run in atomic_write like any other write.
			if ( '' !== $content ) {
				return $this->error( 'mcp_bridge_invalid_payload', 'Send either content or patch, not both.', 400 );
			}
			if ( 1 !== preg_match( '/^wp-config[^\/]*\.php$/i', basename( $path ) ) ) {
				return $this->error( 'ovp_patch_unsupported', 'patch mode only edits wp-config.php; use content for other files.', 400 );
			}
			$src = is_file( $path ) ? file_get_contents( $path ) : false;
			if ( false === $src ) {
				return $this->error( 'mcp_bridge_not_found', 'wp-config file does not exist.', 404 );
			}
			$patched = self::patch_wp_config( $src, is_array( $body['patch'] ) ? $body['patch'] : array() );
			if ( is_wp_error( $patched ) ) {
				return $patched;
			}
			$content = $patched;
			$encoding = 'text';
		}
		if ( 'base64' === $encoding ) {
			if ( ( strlen( $content ) * 3 ) / 4 > 8 * 1024 * 1024 ) {
				return $this->error( 'mcp_bridge_file_too_large', 'Per-request write cap is 8MB. Use files/upload/* for larger files.', 413 );
			}
			$decoded = base64_decode( $content, true );
			if ( false === $decoded ) {
				return $this->error( 'mcp_bridge_invalid_payload', 'Invalid base64.', 400 );
			}
			$content = $decoded;
		} elseif ( 'text' !== $encoding ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'encoding must be text or base64.', 400 );
		}
		if ( strlen( $content ) > 8 * 1024 * 1024 ) {
			return $this->error( 'mcp_bridge_file_too_large', 'Per-request write cap is 8MB. Use files/upload/* for larger files.', 413 );
		}
		if ( 1 === preg_match( '/^wp-config[^\/]*\.php$/i', basename( $path ) ) && str_contains( $content, '***redacted***' ) ) {
			return $this->error( 'ovp_redacted_content', 'Content contains the redaction marker from a wp-config read. Refusing to write masked secrets.', 400 );
		}
		if ( ! empty( $body['sha256'] ) && ! hash_equals( strtolower( (string) $body['sha256'] ), hash( 'sha256', $content ) ) ) {
			return $this->error( 'ovp_upload_hash', 'sha256 mismatch.', 422 );
		}

		// Missing parent directories are created only now, after the jail, self-protection, confirm and payload checks
		// above, one segment at a time with the jail re-checked on each. If the lint or the write then refuses, the
		// folders created here are removed again (only while empty).
		$created = array();
		$mk      = $this->ensure_parent_dir( $path, $rel, $created );
		if ( null !== $mk ) {
			return $mk;
		}
		// Lint (php files) happens inside atomic_write BEFORE the live file is touched.
		$res = OVP_Upload::atomic_write( $path, $content, array( 'rel' => $rel ) );
		if ( is_wp_error( $res ) ) {
			$this->rmdir_created( $created ); // A refused lint or failed write leaves no new empty folders behind.
			return $res;
		}
		$out = array(
			'path'   => $this->public_path( $path ),
			'bytes'  => $res['bytes'],
			'sha256' => $res['sha256'],
			'backup' => $res['backup'],
		);
		if ( isset( $body['patch'] ) && is_array( $body['patch'] ) ) {
			$out['patched'] = array( 'op' => (string) ( $body['patch']['op'] ?? '' ), 'name' => (string) ( $body['patch']['name'] ?? '' ) );
		}
		return $this->success( $out );
	}

	/**
	 * Pure wp-config patcher (D14). ops: append_define (add a define() that does not exist yet, placed
	 * before the "stop editing" marker or the wp-settings.php require) and replace_define (change the
	 * literal value of exactly one existing define()). Value: string|int|float|bool|null.
	 *
	 * @param array<string, mixed> $patch op, name, value
	 */
	public static function patch_wp_config( string $src, array $patch ): string|WP_Error {
		$fail = static fn( string $code, string $msg, int $status = 400 ): WP_Error => new WP_Error( $code, $msg, array( 'status' => $status ) );
		$op   = (string) ( $patch['op'] ?? '' );
		$name = (string) ( $patch['name'] ?? '' );
		if ( ! in_array( $op, array( 'append_define', 'replace_define', 'remove_define' ), true ) ) {
			return $fail( 'ovp_patch_op', 'patch.op must be append_define, replace_define or remove_define.' );
		}
		if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name ) ) {
			return $fail( 'ovp_patch_name', 'patch.name must be a PHP constant name.' );
		}
		if ( 'remove_define' === $op ) {
			return self::remove_define( $src, $name );
		}
		if ( ! array_key_exists( 'value', $patch ) || ! ( is_scalar( $patch['value'] ) || null === $patch['value'] ) ) {
			return $fail( 'ovp_patch_value', 'patch.value must be a string, number, boolean or null.' );
		}
		$v = $patch['value'];
		if ( is_string( $v ) ) {
			if ( str_contains( $v, "\0" ) || strlen( $v ) > 4096 ) {
				return $fail( 'ovp_patch_value', 'patch.value is not acceptable (NUL byte or longer than 4096).' );
			}
			$lit = "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $v ) . "'";
		} elseif ( is_bool( $v ) ) {
			$lit = $v ? 'true' : 'false';
		} elseif ( null === $v ) {
			$lit = 'null';
		} elseif ( is_float( $v ) && ! is_finite( $v ) ) {
			return $fail( 'ovp_patch_value', 'patch.value must be finite.' );
		} else {
			$lit = (string) $v;
		}
		$q    = preg_quote( $name, '/' );
		$find = '/define\s*\(\s*([\'"])' . $q . '\1\s*,\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|[^,)]*?)(\s*(?:,[^)]*)?\))/';
		// Match against a copy where every PHP comment is blanked out (same length, newlines kept), so a commented-out define()
		// is neither replaced nor counted; the offsets still point into the real source.
		$n = preg_match_all( $find, self::mask_php_comments( $src ), $m, PREG_OFFSET_CAPTURE );
		if ( 'append_define' === $op ) {
			if ( $n > 0 ) {
				return $fail( 'ovp_patch_exists', $name . ' is already defined in wp-config; use replace_define.', 409 );
			}
			$line   = "define( '" . $name . "', " . $lit . " );\n";
			$marker = '/^[ \t]*\/\*\s*That\'s all, stop editing.*$/mi';
			if ( 1 === preg_match( $marker, $src, $mm, PREG_OFFSET_CAPTURE ) ) {
				$at = (int) $mm[0][1];
			} elseif ( 1 === preg_match( '/^[ \t]*require(?:_once)?\s*\(?\s*ABSPATH\s*\.\s*[\'"]wp-settings\.php[\'"]/mi', $src, $mm, PREG_OFFSET_CAPTURE ) ) {
				$at = (int) $mm[0][1];
			} else {
				return $fail( 'ovp_patch_anchor', 'Could not find the stop-editing marker or the wp-settings.php require to insert before.', 422 );
			}
			return substr( $src, 0, $at ) . $line . "\n" . substr( $src, $at );
		}
		if ( 0 === $n ) {
			return $fail( 'mcp_bridge_not_found', $name . ' is not defined in wp-config; use append_define.', 404 );
		}
		if ( $n > 1 ) {
			return $fail( 'ovp_patch_ambiguous', $name . ' is defined ' . $n . ' times (conditional blocks?); edit the file by hand.', 409 );
		}
		// Only a plain literal / constant value is replaced in place; an expression (getenv( 'X' ) ?: false) cannot be spliced safely.
		if ( 1 !== preg_match( '/^(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|-?[0-9][0-9.]*|true|false|null|[A-Z_][A-Z0-9_]*)$/i', trim( (string) $m[2][0][0] ) ) ) {
			return $fail( 'ovp_patch_complex', $name . ' is defined with an expression; edit the file by hand.', 409 );
		}
		$off = (int) $m[2][0][1];
		$len = strlen( (string) $m[2][0][0] );
		return substr( $src, 0, $off ) . $lit . substr( $src, $off + $len );
	}

	/** remove_define: delete the one live define( 'NAME', ... ); statement (comments ignored); 404 if absent. */
	private static function remove_define( string $src, string $name ): string|WP_Error {
		$fail = static fn( string $code, string $msg, int $status ): WP_Error => new WP_Error( $code, $msg, array( 'status' => $status ) );
		$q    = preg_quote( $name, '/' );
		$find = '/define\s*\(\s*([\'"])' . $q . '\1\s*,\s*(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|[^,)]*?)(?:\s*,[^)]*)?\)[ \t]*;/';
		$n    = preg_match_all( $find, self::mask_php_comments( $src ), $m, PREG_OFFSET_CAPTURE );
		if ( 0 === $n ) {
			return $fail( 'mcp_bridge_not_found', $name . ' is not defined in wp-config.', 404 );
		}
		if ( $n > 1 ) {
			return $fail( 'ovp_patch_ambiguous', $name . ' is defined ' . $n . ' times (conditional blocks?); edit the file by hand.', 409 );
		}
		$start = (int) $m[0][0][1];
		$end   = $start + strlen( (string) $m[0][0][0] );
		// Drop the whole line when the define is alone on it.
		$ls = strrpos( substr( $src, 0, $start ), "\n" );
		$ls = false === $ls ? 0 : $ls + 1;
		$le = strpos( $src, "\n", $end );
		$le = false === $le ? strlen( $src ) : $le + 1;
		if ( '' === trim( substr( $src, $ls, $start - $ls ) ) && '' === trim( substr( $src, $end, $le - $end ) ) ) {
			return substr( $src, 0, $ls ) . substr( $src, $le );
		}
		return substr( $src, 0, $start ) . substr( $src, $end );
	}

	/** $src with every PHP comment token replaced by spaces of the same length (newlines kept). */
	private static function mask_php_comments( string $src ): string {
		if ( ! function_exists( 'token_get_all' ) ) {
			return $src;
		}
		$out = '';
		foreach ( token_get_all( $src ) as $tok ) {
			if ( is_array( $tok ) ) {
				$out .= in_array( $tok[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? (string) preg_replace( '/[^\r\n]/', ' ', $tok[1] ) : $tok[1];
			} else {
				$out .= $tok;
			}
		}
		return strlen( $out ) === strlen( $src ) ? $out : $src;
	}

	public function restore_file( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->gate( $request, 'T3' );
		if ( null !== $gate ) {
			return $gate;
		}
		$body = (array) $request->get_json_params();
		$rel  = $this->normalize_rel( $this->str( $body['path'] ?? '' ) );
		if ( '' === $rel ) {
			return $this->error( 'mcp_bridge_path_escape', 'Path escapes site root.', 400 );
		}
		$blocked = $this->write_blocked_reason( $rel, ! empty( $body['confirm'] ) );
		if ( null !== $blocked ) {
			return $blocked;
		}
		$path = $this->resolve( $rel, true );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$blocked = $this->write_blocked_resolved( $path );
		if ( null !== $blocked ) {
			return $blocked;
		}
		$created = array();
		$mk      = $this->ensure_parent_dir( $path, $rel, $created );
		if ( null !== $mk ) {
			return $mk;
		}
		$res = OVP_Upload::restore_file_backup( (string) ( $body['backup'] ?? '' ), $path, $rel );
		if ( is_wp_error( $res ) ) {
			$this->rmdir_created( $created );
			return $res;
		}
		return $this->success( array( 'path' => $this->public_path( $path ), 'restored' => true ) + $res );
	}

	// -- Upload pipeline -------------------------------------------------------------------------

	public function upload_begin( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = (array) $request->get_json_params();
		return $this->begin_at( $this->str( $body['path'] ?? '' ), $body, $request );
	}

	/**
	 * Map a path relative to the WordPress root (what the master sends) to this module's scope
	 * syntax: "wp-content/x" -> content scope "x"; anything else -> explicit "abspath/".
	 * The result still goes through normalize_rel(), the denylists and the realpath jail.
	 */
	public function from_site_root( string $path ): string {
		$p = ltrim( str_replace( '\\', '/', $path ), '/' );
		if ( 1 === preg_match( '#^wp-content/(.+)$#i', $p, $m ) ) {
			return $m[1];
		}
		return 'abspath/' . $p;
	}

	/**
	 * Start a staged file upload at $path (module scope syntax). T3. Used by files/upload/begin
	 * and by the generic upload/begin route.
	 *
	 * @param array<string,mixed> $body total_size, sha256, confirm
	 */
	public function begin_at( string $path, array $body, WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->gate( $request, 'T3' );
		if ( null !== $gate ) {
			return $gate;
		}
		$rel = $this->normalize_rel( $path );
		if ( '' === $rel ) {
			return $this->error( 'mcp_bridge_path_escape', 'Path escapes site root.', 400 );
		}
		if ( str_ends_with( $path, '/' ) || str_ends_with( $path, '\\' ) ) {
			return $this->error( 'ovp_dest_is_dir', 'The destination must be a file path, not a directory.', 400 );
		}
		$blocked = $this->write_blocked_reason( $rel, ! empty( $body['confirm'] ) );
		if ( null !== $blocked ) {
			return $blocked;
		}
		$dest = $this->resolve( $rel, true );
		if ( is_wp_error( $dest ) ) {
			return $dest;
		}
		$blocked = $this->write_blocked_resolved( $dest );
		if ( null !== $blocked ) {
			return $blocked;
		}
		if ( is_dir( $dest ) ) {
			return $this->error( 'ovp_dest_is_dir', 'The destination is an existing directory.', 400 );
		}
		$res = OVP_Upload::begin(
			array(
				'kind'       => 'file',
				'total_size' => (int) ( $body['total_size'] ?? 0 ),
				'sha256'     => (string) ( $body['sha256'] ?? '' ),
				'dest'       => $dest,
				'rel'        => $rel,
			),
			OVP_Meta::request_tier( $request )
		);
		return is_wp_error( $res ) ? $res : $this->success( $res + array( 'path' => $this->public_path( $dest ) ) );
	}

	public function upload_chunk( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = (array) $request->get_json_params();
		$data = Ovp_Chunk::decode_b64_strict( $body['data'] ?? null );
		if ( null === $data ) {
			return $this->error( 'ovp_chunk_encoding', 'data must be strict base64.', 400 );
		}
		if ( ( $body['encoding'] ?? 'base64' ) !== 'base64' ) {
			return $this->error( 'ovp_chunk_encoding', 'Chunks must be base64.', 400 );
		}
		$res = OVP_Upload::chunk(
			(string) ( $body['upload_id'] ?? '' ),
			(int) ( $body['offset'] ?? -1 ),
			$data,
			(string) ( $body['chunk_sha256'] ?? '' ),
			OVP_Meta::request_tier( $request )
		);
		return is_wp_error( $res ) ? $res : $this->success( $res );
	}

	public function upload_finalize( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = (array) $request->get_json_params();
		$id   = (string) ( $body['upload_id'] ?? '' );
		$meta = OVP_Upload::meta( $id );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}
		if ( 'file' !== $meta['kind'] ) {
			return $this->error( 'ovp_upload_invalid', 'Use plugins/upload/finalize or themes/upload/finalize for ZIPs.', 400 );
		}
		// Re-resolve now: the jail and blocklists are re-checked at commit time.
		$rel = (string) $meta['rel'];
		$blocked = $this->write_blocked_reason( $rel, ! empty( $body['confirm'] ) );
		if ( null !== $blocked ) {
			return $blocked;
		}
		$dest = $this->resolve( $rel, true );
		if ( is_wp_error( $dest ) ) {
			return $dest;
		}
		$blocked = $this->write_blocked_resolved( $dest );
		if ( null !== $blocked ) {
			return $blocked;
		}
		$created = array();
		$mk      = $this->ensure_parent_dir( $dest, $rel, $created );
		if ( null !== $mk ) {
			return $mk;
		}
		$res = OVP_Upload::finalize_file( $id, OVP_Meta::request_tier( $request ), array( 'dest' => $dest ) );
		if ( is_wp_error( $res ) ) {
			$this->rmdir_created( $created );
			return $res;
		}
		return $this->success( $res + array( 'path' => $this->public_path( $dest ) ) );
	}

	public function upload_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$res = OVP_Upload::status( (string) $request->get_param( 'upload_id' ) );
		return is_wp_error( $res ) ? $res : $this->success( $res );
	}

	public function upload_abort( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->gate( $request, 'T2' );
		if ( null !== $gate ) {
			return $gate;
		}
		$body = (array) $request->get_json_params();
		return OVP_Upload::abort( (string) ( $body['upload_id'] ?? '' ) )
			? $this->success( array( 'aborted' => true ) )
			: $this->error( 'ovp_upload_unknown', 'Unknown upload.', 404 );
	}

	public function delete_path( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->gate( $request, 'T3' );
		if ( null !== $gate ) {
			return $gate;
		}
		$rel     = $this->normalize_rel( $this->str( $request->get_param( 'path' ) ) );
		$confirm = (bool) $request->get_param( 'confirm' );
		[ $scope, $inner ] = $this->split_scope( $rel );
		$il = strtolower( $inner );
		if ( in_array( basename( $il ), array( '000-mcp-bridge-fatal.php', 'mcp-bridge-oob.php', 'mcp-bridge-oob-lib.php' ), true ) ) {
			return $this->error( 'mcp_bridge_protected', 'Refusing to delete oob / 000-mcp-bridge-fatal. That removes the dead-site recovery path.', 403 );
		}
		if ( 'abspath' === $scope && in_array( $il, array( 'wp-config.php', 'wp-load.php', 'wp-settings.php', 'index.php' ), true ) && ! $confirm ) {
			return $this->error( 'mcp_bridge_confirm_required', 'Deleting ABSPATH core files needs confirm=true.', 403 );
		}

		$path = $this->resolve( $rel );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$fs = $this->filesystem();
		if ( ! $fs->exists( $path ) ) {
			return $this->error( 'mcp_bridge_not_found', 'Path does not exist.', 404 );
		}

		if ( is_dir( $path ) && ! $confirm ) {
			return $this->error( 'mcp_bridge_confirm_required', 'Recursive directory delete needs confirm=true.', 403 );
		}

		$blocked = $this->write_blocked_resolved( $path );
		if ( null !== $blocked ) {
			return $blocked;
		}
		// The Overlord plugin's own folder (or a folder that contains it): effective T3 only (resolve() already refused
		// below T3), and never without the confirm the master sends after a consumed two-call confirm_token.
		$touches_self = $this->touches_own_plugin( $path );
		if ( $touches_self && ! $confirm ) {
			return $this->error( 'mcp_bridge_confirm_required', 'This path is, or contains, the Overlord plugin folder. Removing it needs the two-call confirm (confirm=true after a consumed confirm_token) and effective tier T3.', 403 );
		}
		if ( is_dir( $path ) && OVP_Upload::base_inside( $path ) ) {
			return $this->error( 'ovp_backup_inside_target', 'This folder contains the Overlord stage/backup directory, so a backup taken first would be deleted with it. Nothing was removed. Delete a sub-folder that does not contain it, or move the stage directory outside (OVP_STAGE_DIR).', 409 );
		}
		if ( is_dir( $path ) ) {
			// An empty folder holds nothing to lose: no backup (an empty zip would be pointless and fails to build).
			$backup = self::dir_is_empty( $path ) ? null : OVP_Upload::backup_dir( $path, $rel );
			if ( null === $backup && ! self::dir_is_empty( $path ) ) {
				return $this->error( 'ovp_backup_failed', 'A directory is never deleted without a backup, and the backup could not be taken (more than 20000 files or 256 MiB, ZipArchive missing, or the backup area is not writable). Delete smaller sub-folders, or free space and retry.', 409 );
			}
		} else {
			$backup = is_file( $path ) ? OVP_Upload::backup_file( $path, $rel ) : null;
			if ( $touches_self && null === $backup ) {
				return $this->error( 'ovp_backup_failed', 'A file of the Overlord plugin is never deleted without a backup, and the backup could not be taken.', 409 );
			}
		}
		$fs->delete( $path, true );
		$out = array( 'deleted' => $this->public_path( $path ), 'backup' => $backup );
		if ( $touches_self ) {
			$out['note'] = 'The Overlord plugin was removed from disk. Removing or replacing the plugin never touches site content (posts, pages, media, uploads) or users. The backup holds the removed files; the site needs the plugin re-installed to be managed by Fleet again.';
		}
		return $this->success( $out );
	}

	private static function dir_is_empty( string $dir ): bool {
		$h = @opendir( $dir );
		if ( false === $h ) {
			return false;
		}
		while ( false !== ( $e = readdir( $h ) ) ) {
			if ( '.' !== $e && '..' !== $e ) {
				closedir( $h );
				return false;
			}
		}
		closedir( $h );
		return true;
	}

	/** True when $path is inside the Overlord plugin folder, is that folder, or is a folder that contains it. */
	private function touches_own_plugin( string $path ): bool {
		$root = defined( 'OVP_FLEET_DIR' ) ? realpath( OVP_FLEET_DIR ) : false;
		if ( ! is_string( $root ) ) {
			return false;
		}
		return self::deny_inside( $path, $root ) || self::deny_inside( $root, $path );
	}

	private function normalize_rel( string $relative ): string {
		$relative = str_replace( '\\', '/', $relative );
		// ':' names a Windows alternate data stream ("wp-config.php::$DATA") or a drive; no legitimate WordPress path needs it.
		if ( str_contains( $relative, ':' ) ) {
			return '';
		}
		$relative = ltrim( $relative, '/' );
		$parts    = array();
		foreach ( explode( '/', $relative ) as $seg ) {
			if ( '' === $seg || '.' === $seg ) {
				continue;
			}
			if ( '..' === $seg ) {
				return '';
			}
			$parts[] = $seg;
		}
		return implode( '/', $parts );
	}

	private function write_blocked_reason( string $rel, bool $confirm ): ?WP_Error {
		[ $scope, $inner ] = $this->split_scope( $rel );
		$lower             = strtolower( $inner );
		if ( 'abspath' === $scope && in_array( $lower, array( 'wp-config.php', '.htaccess', '.user.ini', 'wp-load.php', 'index.php' ), true ) && ! $confirm ) {
			return $this->error(
				'mcp_bridge_confirm_required',
				'Writing wp-config / .htaccess / core index needs confirm=true. A bad edit is a WSOD.',
				403
			);
		}
		if ( 'abspath' === $scope && ( str_starts_with( $lower, 'wp-admin/' ) || str_starts_with( $lower, 'wp-includes/' ) ) && ! $confirm ) {
			return $this->error(
				'mcp_bridge_confirm_required',
				'Writing wp-admin or wp-includes needs confirm=true. A parse error there is a WSOD. Backup is written first.',
				403
			);
		}
		// Executable code dropped where uploads are served from, and server-config files below the site root, are
		// the classic persistence spots: they need the two-call confirm at every tier (the master sends confirm=true
		// only after a consumed confirm_token).
		if ( ! $confirm && 1 === preg_match( '#(?:^|/)uploads/.*\.(?:php[0-9]?|phtml|phar|pht|phps)$#', $lower ) ) {
			return $this->error( 'mcp_bridge_confirm_required', 'Writing a PHP file into uploads needs the two-call confirm (confirm=true after a consumed confirm_token).', 403 );
		}
		if ( ! $confirm && '.htaccess' === basename( $lower ) && ( 'content' === $scope || str_contains( $lower, '/' ) ) ) {
			return $this->error( 'mcp_bridge_confirm_required', 'Writing a .htaccess below the site root needs the two-call confirm (confirm=true after a consumed confirm_token).', 403 );
		}
		if ( ! $confirm && '.user.ini' === basename( $lower ) && ( 'content' === $scope || str_contains( $lower, '/' ) ) ) {
			return $this->error( 'mcp_bridge_confirm_required', 'Writing a .user.ini below the site root needs the two-call confirm (confirm=true after a consumed confirm_token).', 403 );
		}
		if ( ! $this->god() && ( 'mu-plugins' === $lower || str_starts_with( $lower, 'mu-plugins/' ) || str_contains( $lower, 'wp-content/mu-plugins/' ) ) ) {
			return $this->error(
				'mcp_bridge_mu_plugin_denied',
				'Writing mu-plugins needs effective tier T3. A syntax error there 500s the whole site including MCP (PHP is linted on whole-file writes). Below T3 use debug_fatals for 000-mcp-bridge-fatal.php; files_delete is still allowed to recover.',
				403
			);
		}
		$blocked_root = array(
			'fatal-error-handler.php',
			'mcp-bridge-oob.php',
			'mcp-bridge-oob-lib.php',
			'object-cache.php',
			'advanced-cache.php',
			'db.php',
			'sunrise.php',
		);
		if ( in_array( basename( $lower ), $blocked_root, true ) ) {
			return $this->error(
				'mcp_bridge_dropin_denied',
				'Refusing to write wp-content drop-ins via files_write. A syntax error there 500s REST. Use debug_fatals for fatal probes.',
				403
			);
		}
		foreach ( array( 'uploads/mcp-bridge', 'wp-content/uploads/mcp-bridge' ) as $store_rel ) {
			if ( $lower !== $store_rel && ! str_starts_with( $lower, $store_rel . '/' ) ) {
				continue;
			}
			return $this->error(
				'mcp_bridge_dropin_denied',
				'Refusing to write uploads/mcp-bridge via files_write. Use debug_fatals.',
				403
			);
		}
		return null;
	}

	/** Inside wp-content/uploads/mcp-bridge, whether or not the folder exists yet (the real path AND the unresolved one). */
	private function in_plugin_store( string $path ): bool {
		$store = realpath( WP_CONTENT_DIR . '/uploads/mcp-bridge' );
		if ( is_string( $store ) && self::deny_inside( $path, $store ) ) {
			return true;
		}
		$wc = realpath( WP_CONTENT_DIR );
		if ( is_string( $wc ) && self::deny_inside( $path, $wc . '/uploads/mcp-bridge' ) ) {
			return true;
		}
		return self::deny_inside( $path, rtrim( WP_CONTENT_DIR, '/\\' ) . '/uploads/mcp-bridge' );
	}

	private function write_blocked_resolved( string $path ): ?WP_Error {
		$mu = realpath( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' );
		if ( ! $this->god() && is_string( $mu ) && self::deny_inside( $path, $mu ) ) {
			return $this->error(
				'mcp_bridge_mu_plugin_denied',
				'Writing mu-plugins needs effective tier T3. Use debug_fatals / files_delete below T3.',
				403
			);
		}
		if ( $this->in_plugin_store( $path ) ) {
			return $this->error(
				'mcp_bridge_dropin_denied',
				'Refusing to write uploads/mcp-bridge via files_write.',
				403
			);
		}
		$base = OVP_Upload::base_dir();
		if ( is_string( $base ) && self::deny_inside( $path, $base ) ) {
			return $this->error( 'ovp_protected_path', 'Refusing to write the plugin staging area.', 403 );
		}
		return null;
	}

	private function read_blocked_resolved( string $path ): ?WP_Error {
		$base = OVP_Upload::base_dir();
		if ( is_string( $base ) && self::deny_inside( $path, $base ) ) {
			return $this->error( 'ovp_protected_path', 'The plugin staging and backup area is not readable.', 403 );
		}
		if ( $this->in_plugin_store( $path ) && 'fatal-latest.json' !== basename( $path ) ) {
			return $this->error( 'ovp_protected_path', 'The plugin store is not readable.', 403 );
		}
		return null;
	}
}
