<?php
/**
 * Upload pipeline (design W6). Root-cause fix for "upload via MCP triggers PHP syntax
 * error": chunks never touch the live path. They go to a staging dir (outside the web
 * root when possible), every chunk and the whole file are sha256-checked, PHP is linted
 * before anything is moved, the final move is tmp + rename, and plugin/theme ZIPs are
 * validated, extracted to a staged tree and swapped in with a restorable backup.
 * Activation runs under a guard that rolls back on fatal.
 *
 * Deliberately WP-light: only WP_Error / is_wp_error are required, so the pipeline is
 * testable with a tiny stub. No secrets are ever written to logs or error messages.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OVP_Upload {

	/** Tier per operation, consumed by class-ovp-tiers.php. */
	public const OVP_TIER = array(
		'upload_begin_file'    => 'T3',
		'upload_chunk_file'    => 'T3',
		'upload_finalize_file' => 'T3',
		// Zip install/activate executes arbitrary PHP in the web request, so it is T3 (UM-3).
		'upload_begin_zip'     => 'T3',
		'upload_chunk_zip'     => 'T3',
		'upload_finalize_zip'  => 'T3',
		'upload_status'        => 'T0',
		'upload_abort'         => 'T2',
		'files_restore'        => 'T3',
	);

	public const TIER_RANK = array(
		'T0' => 0,
		'T1' => 1,
		'T2' => 2,
		'T3' => 3,
	);

	private const PHP_EXT_RE  = '/\.(php[0-9]?|phtml|inc)$/i';
	private const SELF_SLUGS  = array( 'overlord-wp-fleet', 'wp-mcp-bridge' );
	/** Oldest release the T3 in-place replace accepts: 2.0.0-2.0.5 carry an uninstall.php that deletes the service user without reassigning its content. */
	public const SELF_MIN_VERSION = '2.0.6';
	private const FATAL_TYPES = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );

	/** @var array<string, mixed> */
	private static array $cfg = array(
		'base_dir'          => null,
		'keep_backups'      => 5,
		'max_total'         => 268435456, // 256MB per upload.
		'chunk_max'         => 4194304,   // 4MB raw per chunk (design).
		'max_stages'        => 16,
		'stage_ttl'         => 86400,
		'zip_max_entries'   => 20000,
		'zip_max_unpacked'  => 268435456,
		'zip_max_entry'     => 83886080,  // 80MB.
		'zip_ratio'         => 200,
		'use_php_l'         => true,
		'php_l_max_files'   => 400,
		'lint_timeout'      => 10,
		'lint_max_bytes'    => 33554432,
	);

	/** @var array<string, string|null> */
	private static array $php_cli_cache = array();

	/**
	 * Override config (tests, wp-config constants). Unknown keys are ignored.
	 *
	 * @param array<string, mixed> $over
	 */
	public static function configure( array $over ): void {
		foreach ( $over as $k => $v ) {
			if ( array_key_exists( $k, self::$cfg ) ) {
				self::$cfg[ $k ] = $v;
			}
		}
		self::$php_cli_cache = array();
	}

	public static function cfg( string $key ): mixed {
		return self::$cfg[ $key ] ?? null;
	}

	public static function tier_rank( string $tier ): int {
		return self::TIER_RANK[ strtoupper( $tier ) ] ?? -1;
	}

	/** @param array<string, mixed> $extra */
	private static function err( string $code, string $message, int $status = 400, array $extra = array() ): WP_Error {
		return new WP_Error( $code, $message, array_merge( array( 'status' => $status ), $extra ) );
	}

	// -- Directories ------------------------------------------------------------------

	/**
	 * Staging/backup root. Prefers a dir outside the document root; otherwise
	 * wp-content/uploads/ovp-stage with deny rules. Always 0700.
	 */
	public static function base_dir(): string|WP_Error {
		$cfg = self::$cfg['base_dir'];
		if ( is_string( $cfg ) && '' !== $cfg ) {
			return self::prepare_dir( $cfg );
		}
		if ( defined( 'OVP_STAGE_DIR' ) && is_string( OVP_STAGE_DIR ) && '' !== OVP_STAGE_DIR ) {
			return self::prepare_dir( OVP_STAGE_DIR );
		}
		$abs    = rtrim( (string) ABSPATH, '/\\' );
		$parent = dirname( $abs );
		$docroot = isset( $_SERVER['DOCUMENT_ROOT'] ) ? realpath( (string) $_SERVER['DOCUMENT_ROOT'] ) : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// WP-CLI and system cron have no DOCUMENT_ROOT and would pick a different root than web requests,
		// hiding backups and the guard result. Reuse the root a web request resolved (own option).
		if ( false === $docroot && function_exists( 'get_option' ) ) {
			$saved = get_option( 'ovp_stage_root', '' );
			if ( is_string( $saved ) && '' !== $saved && is_dir( $saved ) && ! is_link( $saved ) ) {
				$dir = self::prepare_dir( $saved );
				if ( is_string( $dir ) ) {
					return $dir;
				}
			}
		}
		$real_parent = realpath( $parent );
		if (
			false !== $real_parent && false !== $docroot && is_dir( $real_parent ) && is_writable( $real_parent )
			&& ! self::path_inside( $real_parent, $docroot ) && ! self::path_inside( $docroot, $real_parent . '/__none__' )
		) {
			$dir = self::prepare_dir( $real_parent . '/.ovp-data' );
			if ( is_string( $dir ) ) {
				self::remember_root( $dir, $docroot );
				return $dir;
			}
		}
		$content = defined( 'WP_CONTENT_DIR' ) ? (string) WP_CONTENT_DIR : $abs . '/wp-content';
		$dir     = self::prepare_dir( $content . '/uploads/ovp-stage' );
		if ( is_string( $dir ) ) {
			self::remember_root( $dir, $docroot );
		}
		return $dir;
	}

	/** Remember the staging root a real web request resolved (ovp_ option only), for CLI and cron. */
	private static function remember_root( string $dir, string|false $docroot ): void {
		if ( false === $docroot || ! function_exists( 'update_option' ) || ! function_exists( 'get_option' ) ) {
			return;
		}
		if ( get_option( 'ovp_stage_root', '' ) !== $dir ) {
			update_option( 'ovp_stage_root', $dir, false );
		}
	}

	private static function prepare_dir( string $dir ): string|WP_Error {
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
			return self::err( 'ovp_stage_unavailable', 'Cannot create staging directory.', 500 );
		}
		if ( is_link( $dir ) ) {
			return self::err( 'ovp_stage_unavailable', 'Staging directory must not be a symlink.', 500 );
		}
		@chmod( $dir, 0700 );
		$real = realpath( $dir );
		if ( false === $real || ! is_writable( $real ) ) {
			return self::err( 'ovp_stage_unavailable', 'Staging directory is not writable.', 500 );
		}
		// Defense in depth when the dir is (or may become) web-served.
		$ht = $real . '/.htaccess';
		if ( ! is_file( $ht ) ) {
			@file_put_contents( $ht, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\nOptions -Indexes\n" );
		}
		$ix = $real . '/index.php';
		if ( ! is_file( $ix ) ) {
			@file_put_contents( $ix, "<?php\nhttp_response_code( 403 );\nexit;\n" );
		}
		return $real;
	}

	public static function path_inside( string $path, string $base ): bool {
		$base = rtrim( $base, '/\\' );
		return $path === $base || str_starts_with( $path, $base . '/' );
	}

	public static function sub_dir( string $name ): string|WP_Error {
		$base = self::base_dir();
		if ( $base instanceof WP_Error ) {
			return $base;
		}
		$dir = $base . '/' . $name;
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
			return self::err( 'ovp_stage_unavailable', 'Cannot create ' . $name . ' directory.', 500 );
		}
		return $dir;
	}

	public static function valid_id( string $id ): bool {
		return 1 === preg_match( '/^[a-f0-9]{32}$/', $id );
	}

	// -- begin / chunk / status / abort ---------------------------------------------------

	/**
	 * @param array<string, mixed> $in kind(file|zip), total_size, sha256, dest+rel (file), type(plugin|theme) (zip)
	 * @return array<string, mixed>|WP_Error
	 */
	public static function begin( array $in, string $tier ): array|WP_Error {
		$kind = (string) ( $in['kind'] ?? '' );
		if ( ! in_array( $kind, array( 'file', 'zip' ), true ) ) {
			return self::err( 'ovp_upload_invalid', 'kind must be file or zip.' );
		}
		$need = 'zip' === $kind ? self::OVP_TIER['upload_begin_zip'] : self::OVP_TIER['upload_begin_file'];
		if ( self::tier_rank( $tier ) < self::tier_rank( $need ) ) {
			return self::err( 'ovp_tier_denied', 'Tier ' . $need . ' required.', 403 );
		}
		$total = (int) ( $in['total_size'] ?? 0 );
		$sha   = strtolower( (string) ( $in['sha256'] ?? '' ) );
		if ( $total < 1 || $total > (int) self::$cfg['max_total'] ) {
			return self::err( 'ovp_upload_invalid', 'total_size out of range.', 413 );
		}
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $sha ) ) {
			return self::err( 'ovp_upload_invalid', 'sha256 (64 hex) is required.' );
		}
		$meta = array(
			'kind'       => $kind,
			'total_size' => $total,
			'sha256'     => $sha,
			'tier'       => $need,
			'created'    => time(),
		);
		if ( 'file' === $kind ) {
			$dest = (string) ( $in['dest'] ?? '' );
			if ( '' === $dest || str_contains( $dest, "\0" ) ) {
				return self::err( 'ovp_upload_invalid', 'dest required.' );
			}
			$meta['dest'] = $dest;
			$meta['rel']  = (string) ( $in['rel'] ?? basename( $dest ) );
		} else {
			$type = (string) ( $in['type'] ?? '' );
			if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
				return self::err( 'ovp_upload_invalid', 'type must be plugin or theme.' );
			}
			$meta['type'] = $type;
		}

		$stages = self::sub_dir( 'stage' );
		if ( $stages instanceof WP_Error ) {
			return $stages;
		}
		self::gc();
		$existing = glob( $stages . '/*', GLOB_ONLYDIR );
		if ( is_array( $existing ) && count( $existing ) >= (int) self::$cfg['max_stages'] ) {
			return self::err( 'ovp_upload_busy', 'Too many staged uploads. Abort or finish one first.', 429 );
		}
		$id  = bin2hex( random_bytes( 16 ) );
		$dir = $stages . '/' . $id;
		if ( ! @mkdir( $dir, 0700 ) ) {
			return self::err( 'ovp_stage_unavailable', 'Cannot create stage.', 500 );
		}
		$meta['id'] = $id;
		if ( false === file_put_contents( $dir . '/meta.json', (string) json_encode( $meta ), LOCK_EX ) ) {
			self::rrmdir( $dir );
			return self::err( 'ovp_stage_unavailable', 'Cannot write stage meta.', 500 );
		}
		@chmod( $dir . '/meta.json', 0600 );
		return array(
			'upload_id'      => $id,
			'chunk_max'      => (int) self::$cfg['chunk_max'],
			'received_bytes' => 0,
			'total_size'     => $total,
		);
	}

	/** @return array<string, mixed>|WP_Error */
	public static function meta( string $id ): array|WP_Error {
		if ( ! self::valid_id( $id ) ) {
			return self::err( 'ovp_upload_unknown', 'Unknown upload.', 404 );
		}
		$stages = self::sub_dir( 'stage' );
		if ( $stages instanceof WP_Error ) {
			return $stages;
		}
		$path = $stages . '/' . $id . '/meta.json';
		if ( ! is_file( $path ) ) {
			return self::err( 'ovp_upload_unknown', 'Unknown upload.', 404 );
		}
		$meta = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $meta ) || ( $meta['id'] ?? '' ) !== $id ) {
			return self::err( 'ovp_upload_unknown', 'Unknown upload.', 404 );
		}
		$meta['_dir'] = $stages . '/' . $id;
		return $meta;
	}

	private static function received( string $dir ): int {
		clearstatcache( true, $dir . '/data.part' );
		return is_file( $dir . '/data.part' ) ? (int) filesize( $dir . '/data.part' ) : 0;
	}

	/** @return array<string, mixed>|WP_Error */
	public static function status( string $id ): array|WP_Error {
		$meta = self::meta( $id );
		if ( $meta instanceof WP_Error ) {
			return $meta;
		}
		return array(
			'upload_id'      => $id,
			'kind'           => $meta['kind'],
			'total_size'     => $meta['total_size'],
			'received_bytes' => self::received( (string) $meta['_dir'] ),
		);
	}

	public static function abort( string $id ): bool {
		$meta = self::meta( $id );
		if ( $meta instanceof WP_Error ) {
			return false;
		}
		self::rrmdir( (string) $meta['_dir'] );
		return true;
	}

	/**
	 * Append one chunk at exactly the current end of the staged file. Retries are
	 * idempotent: re-sending bytes already stored (same hash) succeeds without writing.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function chunk( string $id, int $offset, string $data, string $chunk_sha256, string $tier ): array|WP_Error {
		$meta = self::meta( $id );
		if ( $meta instanceof WP_Error ) {
			return $meta;
		}
		if ( self::tier_rank( $tier ) < self::tier_rank( (string) $meta['tier'] ) ) {
			return self::err( 'ovp_tier_denied', 'Tier ' . $meta['tier'] . ' required.', 403 );
		}
		$len = strlen( $data );
		if ( $len < 1 || $len > (int) self::$cfg['chunk_max'] ) {
			return self::err( 'ovp_chunk_size', 'Chunk must be 1..' . (int) self::$cfg['chunk_max'] . ' bytes.', 413 );
		}
		if ( $offset < 0 || $offset + $len > (int) $meta['total_size'] ) {
			return self::err( 'ovp_chunk_overflow', 'Chunk exceeds declared total_size.', 413 );
		}
		$chunk_sha256 = strtolower( $chunk_sha256 );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $chunk_sha256 ) ) {
			return self::err( 'ovp_chunk_hash', 'chunk_sha256 (64 hex) is required.', 422 );
		}
		$actual = hash( 'sha256', $data );
		if ( ! hash_equals( $actual, $chunk_sha256 ) ) {
			return self::err( 'ovp_chunk_hash', 'Chunk sha256 mismatch.', 422 );
		}

		$part = (string) $meta['_dir'] . '/data.part';
		$fh   = @fopen( $part, 'c+b' );
		if ( false === $fh ) {
			return self::err( 'ovp_stage_unavailable', 'Cannot open stage.', 500 );
		}
		try {
			if ( ! flock( $fh, LOCK_EX ) ) {
				return self::err( 'ovp_stage_locked', 'Stage is busy.', 409 );
			}
			$st   = fstat( $fh );
			$size = is_array( $st ) ? (int) $st['size'] : 0;
			if ( $offset > $size ) {
				return self::err( 'ovp_chunk_gap', 'Offset beyond received bytes.', 409, array( 'received_bytes' => $size ) );
			}
			if ( $offset < $size ) {
				if ( $offset + $len > $size ) {
					return self::err( 'ovp_chunk_conflict', 'Chunk partially overlaps stored bytes.', 409, array( 'received_bytes' => $size ) );
				}
				fseek( $fh, $offset );
				$stored = (string) fread( $fh, $len );
				if ( ! hash_equals( hash( 'sha256', $stored ), $actual ) ) {
					return self::err( 'ovp_chunk_conflict', 'Different bytes already stored at this offset.', 409, array( 'received_bytes' => $size ) );
				}
				return array( 'received_bytes' => $size, 'duplicate' => true );
			}
			fseek( $fh, $offset );
			$written = 0;
			while ( $written < $len ) {
				$n = fwrite( $fh, substr( $data, $written ) );
				if ( false === $n || 0 === $n ) {
					// Roll back the partial write so the stage stays contiguous.
					ftruncate( $fh, $offset );
					return self::err( 'ovp_stage_write_failed', 'Stage write failed (disk full?).', 507 );
				}
				$written += $n;
			}
			fflush( $fh );
			@chmod( $part, 0600 );
			return array( 'received_bytes' => $offset + $len, 'duplicate' => false );
		} finally {
			flock( $fh, LOCK_UN );
			fclose( $fh );
		}
	}

	/**
	 * Check size and whole-file hash. On mismatch the stage is deleted (422).
	 *
	 * @param array<string, mixed> $meta
	 */
	private static function verify_complete( array $meta ): true|WP_Error {
		$dir  = (string) $meta['_dir'];
		$have = self::received( $dir );
		if ( $have !== (int) $meta['total_size'] ) {
			return self::err( 'ovp_upload_incomplete', 'Received ' . $have . ' of ' . (int) $meta['total_size'] . ' bytes.', 409, array( 'received_bytes' => $have ) );
		}
		$h = hash_file( 'sha256', $dir . '/data.part' );
		if ( false === $h || ! hash_equals( (string) $meta['sha256'], $h ) ) {
			self::rrmdir( $dir );
			return self::err( 'ovp_upload_hash', 'Whole-file sha256 mismatch. Stage deleted.', 422 );
		}
		return true;
	}

	// -- finalize: single file ---------------------------------------------------------

	/**
	 * @param array<string, mixed> $opts dest (re-resolved by caller, must equal begin dest)
	 * @return array<string, mixed>|WP_Error
	 */
	public static function finalize_file( string $id, string $tier, array $opts = array() ): array|WP_Error {
		$meta = self::meta( $id );
		if ( $meta instanceof WP_Error ) {
			return $meta;
		}
		if ( 'file' !== $meta['kind'] ) {
			return self::err( 'ovp_upload_invalid', 'Not a file upload.' );
		}
		if ( self::tier_rank( $tier ) < self::tier_rank( (string) $meta['tier'] ) ) {
			return self::err( 'ovp_tier_denied', 'Tier ' . $meta['tier'] . ' required.', 403 );
		}
		$dest = (string) $meta['dest'];
		if ( isset( $opts['dest'] ) && (string) $opts['dest'] !== $dest ) {
			return self::err( 'ovp_upload_invalid', 'Destination changed since begin.', 409 );
		}
		$ok = self::verify_complete( $meta );
		if ( $ok instanceof WP_Error ) {
			return $ok;
		}
		$part = (string) $meta['_dir'] . '/data.part';
		if ( 1 === preg_match( self::PHP_EXT_RE, $dest ) ) {
			$lint = self::lint_file( $part );
			if ( true !== $lint ) {
				self::rrmdir( (string) $meta['_dir'] );
				return self::lint_error( $lint, (string) $meta['rel'] );
			}
		}
		$res = self::install_file_from( $part, $dest, (string) $meta['rel'], (string) $meta['sha256'] );
		self::rrmdir( (string) $meta['_dir'] );
		return $res;
	}

	/**
	 * Atomically install $src over $dest: backup existing, copy to tmp beside dest,
	 * verify hash, rename. Never writes into the live path in place.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function install_file_from( string $src, string $dest, string $rel, string $expected_sha = '' ): array|WP_Error {
		if ( is_dir( $dest ) ) {
			return self::err( 'ovp_dest_is_dir', 'Destination is a directory.' );
		}
		if ( is_link( $dest ) ) {
			return self::err( 'ovp_dest_is_link', 'Refusing to replace a symlink.', 403 );
		}
		$dir = dirname( $dest );
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
			return self::err( 'ovp_write_failed', 'Cannot create destination directory.', 500 );
		}
		$sha = hash_file( 'sha256', $src );
		if ( false === $sha || ( '' !== $expected_sha && ! hash_equals( $expected_sha, $sha ) ) ) {
			return self::err( 'ovp_upload_hash', 'Source hash mismatch.', 422 );
		}
		$backup = null;
		$mode   = 0644;
		if ( is_file( $dest ) ) {
			$perm = @fileperms( $dest );
			if ( false !== $perm ) {
				$mode = $perm & 0777;
			}
			$backup = self::backup_file( $dest, $rel );
		}
		$tmp = $dir . '/.ovp-tmp-' . bin2hex( random_bytes( 6 ) ) . '.tmp';
		if ( ! @copy( $src, $tmp ) ) {
			@unlink( $tmp );
			return self::err( 'ovp_write_failed', 'Cannot write temp file.', 500 );
		}
		$tmp_sha = hash_file( 'sha256', $tmp );
		if ( false === $tmp_sha || ! hash_equals( $sha, $tmp_sha ) ) {
			@unlink( $tmp );
			return self::err( 'ovp_write_failed', 'Temp file verification failed.', 500 );
		}
		@chmod( $tmp, $mode );
		if ( ! @rename( $tmp, $dest ) ) {
			@unlink( $tmp );
			return self::err( 'ovp_write_failed', 'Atomic rename failed.', 500 );
		}
		self::opcache_invalidate( $dest );
		return array(
			'bytes'  => (int) filesize( $dest ),
			'sha256' => $sha,
			'backup' => $backup,
		);
	}

	/**
	 * Direct (non-chunked) atomic write with optional PHP lint BEFORE touching dest.
	 *
	 * @param array<string, mixed> $opts lint(bool, default true for php), rel(string)
	 * @return array<string, mixed>|WP_Error
	 */
	public static function atomic_write( string $dest, string $content, array $opts = array() ): array|WP_Error {
		$rel  = (string) ( $opts['rel'] ?? basename( $dest ) );
		$lint = array_key_exists( 'lint', $opts ) ? (bool) $opts['lint'] : true;
		if ( $lint && 1 === preg_match( self::PHP_EXT_RE, $dest ) ) {
			$res = self::lint_php( $content );
			if ( true !== $res ) {
				return self::lint_error( $res, $rel );
			}
		}
		$base = self::sub_dir( 'tmp' );
		if ( $base instanceof WP_Error ) {
			return $base;
		}
		$src = $base . '/w-' . bin2hex( random_bytes( 8 ) );
		if ( false === file_put_contents( $src, $content, LOCK_EX ) ) {
			return self::err( 'ovp_write_failed', 'Cannot stage content.', 500 );
		}
		@chmod( $src, 0600 );
		$res = self::install_file_from( $src, $dest, $rel );
		@unlink( $src );
		return $res;
	}

	/** @param array{line:int,message:string,file?:string} $lint */
	private static function lint_error( array $lint, string $rel ): WP_Error {
		$file = isset( $lint['file'] ) && '' !== $lint['file'] ? $lint['file'] : $rel;
		return self::err(
			'ovp_lint_failed',
			'PHP lint failed: ' . $lint['message'] . ' in ' . $file . ' on line ' . $lint['line'],
			422,
			array( 'error_file' => $file, 'error_line' => $lint['line'], 'error_msg' => $lint['message'] )
		);
	}

	// -- Lint ---------------------------------------------------------------------------

	/**
	 * In-process parse on the target PHP version.
	 *
	 * @return true|array{line:int,message:string}
	 */
	public static function lint_php( string $code ): true|array {
		try {
			token_get_all( $code, TOKEN_PARSE );
		} catch ( \ParseError $e ) {
			return array( 'line' => $e->getLine(), 'message' => $e->getMessage() );
		}
		return true;
	}

	/**
	 * token_get_all(TOKEN_PARSE) plus `php -l` via proc_open when a matching CLI exists.
	 *
	 * @return true|array{line:int,message:string}
	 */
	public static function lint_file( string $path, bool $use_cli = true ): true|array {
		$size = @filesize( $path );
		if ( false === $size || $size > (int) self::$cfg['lint_max_bytes'] ) {
			return array( 'line' => 0, 'message' => 'File too large to lint.' );
		}
		$code = file_get_contents( $path );
		if ( false === $code ) {
			return array( 'line' => 0, 'message' => 'Cannot read file for lint.' );
		}
		$res = self::lint_php( $code );
		if ( true !== $res ) {
			return $res;
		}
		if ( $use_cli ) {
			$cli = self::php_cli();
			if ( null !== $cli ) {
				$run = self::run_proc( array( $cli, '-n', '-d', 'display_errors=1', '-d', 'log_errors=0', '-l', $path ), (int) self::$cfg['lint_timeout'] );
				if ( $run['timeout'] ) {
					return array( 'line' => 0, 'message' => 'php -l timed out.' );
				}
				if ( 0 !== $run['rc'] ) {
					$text = $run['out'] . "\n" . $run['err'];
					if ( 1 === preg_match( '/(?:Parse|Fatal) error:\s+(.*?) in .*? on line (\d+)/s', $text, $m ) ) {
						return array( 'line' => (int) $m[2], 'message' => trim( $m[1] ) );
					}
					return array( 'line' => 0, 'message' => 'php -l reported errors.' );
				}
			}
		}
		return true;
	}

	/** Path to a CLI php whose major.minor equals the running one, or null. */
	private static function php_cli(): ?string {
		if ( array_key_exists( 'cli', self::$php_cli_cache ) ) {
			return self::$php_cli_cache['cli'];
		}
		self::$php_cli_cache['cli'] = null;
		if ( ! (bool) self::$cfg['use_php_l'] || ! function_exists( 'proc_open' ) ) {
			return null;
		}
		$cands = array();
		if ( '' !== PHP_BINARY && 1 === preg_match( '/^php[0-9.\-]*(\.exe)?$/i', basename( PHP_BINARY ) ) ) {
			$cands[] = PHP_BINARY;
		}
		if ( defined( 'PHP_BINDIR' ) ) {
			$cands[] = PHP_BINDIR . '/php';
		}
		$want = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		foreach ( $cands as $c ) {
			if ( is_file( $c ) && is_executable( $c ) ) {
				$r = self::run_proc( array( $c, '-n', '-r', 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' ), 5 );
				if ( ! $r['timeout'] && 0 === $r['rc'] && trim( $r['out'] ) === $want ) {
					self::$php_cli_cache['cli'] = $c;
					break;
				}
			}
		}
		return self::$php_cli_cache['cli'];
	}

	/**
	 * Argv-array exec (no shell), minimal env, hard timeout.
	 *
	 * @param array<int, string> $argv
	 * @return array{rc:int,out:string,err:string,timeout:bool}
	 */
	private static function run_proc( array $argv, int $timeout ): array {
		$out = '';
		$err = '';
		$p   = @proc_open( $argv, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, array( 'PATH' => '/usr/bin:/bin:/usr/local/bin' ) );
		if ( ! is_resource( $p ) ) {
			return array( 'rc' => -1, 'out' => '', 'err' => '', 'timeout' => false );
		}
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );
		$deadline = microtime( true ) + max( 1, $timeout );
		$rc       = -1;
		$timeout_hit = false;
		while ( true ) {
			$out .= (string) stream_get_contents( $pipes[1] );
			$err .= (string) stream_get_contents( $pipes[2] );
			if ( strlen( $out ) > 65536 || strlen( $err ) > 65536 ) {
				$out = substr( $out, 0, 65536 );
				$err = substr( $err, 0, 65536 );
			}
			$st = proc_get_status( $p );
			if ( ! $st['running'] ) {
				$rc = (int) $st['exitcode'];
				$out .= (string) stream_get_contents( $pipes[1] );
				$err .= (string) stream_get_contents( $pipes[2] );
				break;
			}
			if ( microtime( true ) > $deadline ) {
				proc_terminate( $p, 9 );
				$timeout_hit = true;
				break;
			}
			usleep( 20000 );
		}
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$close = proc_close( $p );
		if ( -1 === $rc && ! $timeout_hit ) {
			$rc = $close;
		}
		return array( 'rc' => $rc, 'out' => $out, 'err' => $err, 'timeout' => $timeout_hit );
	}

	// -- Backups --------------------------------------------------------------------------

	/**
	 * Copy $path to backups/files/<key>/<ts>-<rand>.bak and keep N versions.
	 */
	public static function backup_file( string $path, string $rel ): ?string {
		$root = self::sub_dir( 'backups/files' );
		if ( $root instanceof WP_Error ) {
			return null;
		}
		$key = substr( hash( 'sha256', $rel ), 0, 16 ) . '-' . preg_replace( '/[^A-Za-z0-9._-]/', '_', substr( basename( $rel ), 0, 60 ) );
		$dir = $root . '/' . $key;
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
			return null;
		}
		$name = gmdate( 'YmdHis' ) . '-' . bin2hex( random_bytes( 3 ) ) . '.bak';
		if ( ! @copy( $path, $dir . '/' . $name ) ) {
			return null;
		}
		@chmod( $dir . '/' . $name, 0600 );
		@file_put_contents( $dir . '/' . $name . '.json', (string) json_encode( array( 'rel' => $rel, 'ts' => time(), 'sha256' => hash_file( 'sha256', $path ) ) ) );
		self::prune( $dir, '.bak', (int) self::$cfg['keep_backups'] );
		return 'files/' . $key . '/' . $name;
	}

	/**
	 * Zip a directory tree into backups/dirs/<key>/<ts>-<rand>.zip before a recursive delete. Symlinks are not followed
	 * (stored as nothing), the backup area itself is skipped, and the tree is capped (20000 files, 256 MiB) so a huge
	 * folder cannot exhaust the disk: past the cap, or without ZipArchive, the result is null and the caller must refuse
	 * the delete (a directory is never deleted without a backup).
	 *
	 * @return string|null backup id "dirs/<key>/<name>.zip", or null when no backup could be taken
	 */
	public static function backup_dir( string $path, string $rel ): ?string {
		if ( ! is_dir( $path ) || is_link( $path ) || ! class_exists( 'ZipArchive' ) ) {
			return null;
		}
		$root = self::sub_dir( 'backups/dirs' );
		if ( $root instanceof WP_Error ) {
			return null;
		}
		$base = self::base_dir();
		$real = realpath( $path );
		if ( false === $real || self::base_inside( $real ) ) {
			return null;
		}
		$key = substr( hash( 'sha256', $rel ), 0, 16 ) . '-' . preg_replace( '/[^A-Za-z0-9._-]/', '_', substr( basename( $rel ), 0, 60 ) );
		$dir = $root . '/' . $key;
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
			return null;
		}
		$name = gmdate( 'YmdHis' ) . '-' . bin2hex( random_bytes( 3 ) ) . '.zip';
		$zip  = new ZipArchive();
		if ( true !== $zip->open( $dir . '/' . $name, ZipArchive::CREATE | ZipArchive::EXCL ) ) {
			return null;
		}
		$files = 0;
		$bytes = 0;
		$ok    = true;
		try {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $real, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
			foreach ( $it as $f ) {
				$fp = $f->getPathname();
				if ( $f->isLink() || ( is_string( $base ) && ( $fp === $base || str_starts_with( $fp, rtrim( $base, '/\\' ) . '/' ) ) ) ) {
					continue;
				}
				$local = substr( $fp, strlen( $real ) + 1 );
				if ( $f->isDir() ) {
					$zip->addEmptyDir( $local );
					continue;
				}
				$files++;
				$bytes += (int) $f->getSize();
				if ( $files > 20000 || $bytes > 256 * 1024 * 1024 || ! $zip->addFile( $fp, $local ) ) {
					$ok = false;
					break;
				}
			}
		} catch ( Throwable $e ) {
			$ok = false;
		}
		$closed = $zip->close();
		if ( ! $ok || ! $closed || ! is_file( $dir . '/' . $name ) ) {
			@unlink( $dir . '/' . $name );
			return null;
		}
		@chmod( $dir . '/' . $name, 0600 );
		@file_put_contents( $dir . '/' . $name . '.json', (string) json_encode( array( 'rel' => $rel, 'ts' => time(), 'files' => $files, 'bytes' => $bytes ) ) );
		self::prune( $dir, '.zip', (int) self::$cfg['keep_backups'] );
		return 'dirs/' . $key . '/' . $name;
	}

	/**
	 * True when the stage/backup base dir lies inside (or is) $dir: a backup written there would be deleted together with $dir,
	 * so no backup of $dir can be kept and the caller must refuse to remove it.
	 */
	public static function base_inside( string $dir ): bool {
		$base = self::base_dir();
		if ( ! is_string( $base ) ) {
			return false;
		}
		$rb = realpath( $base );
		$rd = realpath( $dir );
		if ( false === $rb || false === $rd ) {
			return false;
		}
		if ( in_array( PHP_OS_FAMILY, array( 'Darwin', 'Windows' ), true ) ) {
			$rb = strtolower( $rb );
			$rd = strtolower( $rd );
		}
		return self::path_inside( $rb, $rd );
	}

	/**
	 * Restore a file backup id (as returned by backup_file) over $dest, atomically.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function restore_file_backup( string $backup_id, string $dest, string $rel ): array|WP_Error {
		if ( 1 !== preg_match( '#^files/[a-f0-9]{16}-[A-Za-z0-9._-]{1,60}/[0-9]{14}-[a-f0-9]{6}\.bak$#', $backup_id ) ) {
			return self::err( 'ovp_backup_invalid', 'Invalid backup id.' );
		}
		$root = self::sub_dir( 'backups' );
		if ( $root instanceof WP_Error ) {
			return $root;
		}
		$src = $root . '/' . $backup_id;
		if ( ! is_file( $src ) ) {
			return self::err( 'ovp_backup_missing', 'Backup not found.', 404 );
		}
		return self::install_file_from( $src, $dest, $rel );
	}

	/** Keep the newest $keep entries (names sort by timestamp prefix) ending with $suffix. */
	private static function prune( string $dir, string $suffix, int $keep ): void {
		$keep = max( 1, $keep );
		$all  = glob( $dir . '/*' . $suffix );
		if ( ! is_array( $all ) || count( $all ) <= $keep ) {
			return;
		}
		sort( $all, SORT_STRING );
		foreach ( array_slice( $all, 0, count( $all ) - $keep ) as $old ) {
			if ( is_dir( $old ) && ! is_link( $old ) ) {
				self::rrmdir( $old );
			} else {
				@unlink( $old );
				@unlink( $old . '.json' );
			}
		}
	}

	// -- Filesystem helpers -----------------------------------------------------------------

	/** Recursive delete; never follows symlinks. */
	public static function rrmdir( string $path ): void {
		if ( '' === $path || '/' === $path || strlen( $path ) < 4 ) {
			return;
		}
		if ( is_link( $path ) || is_file( $path ) ) {
			@unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		$items = scandir( $path );
		if ( is_array( $items ) ) {
			foreach ( $items as $it ) {
				if ( '.' === $it || '..' === $it ) {
					continue;
				}
				self::rrmdir( $path . '/' . $it );
			}
		}
		@rmdir( $path );
	}

	/** Recursive copy; symlinks are skipped, never followed. */
	public static function copy_tree( string $src, string $dst ): bool {
		if ( ! is_dir( $src ) || is_link( $src ) ) {
			return false;
		}
		if ( ! is_dir( $dst ) && ! @mkdir( $dst, 0755, true ) && ! is_dir( $dst ) ) {
			return false;
		}
		$items = scandir( $src );
		if ( ! is_array( $items ) ) {
			return false;
		}
		foreach ( $items as $it ) {
			if ( '.' === $it || '..' === $it ) {
				continue;
			}
			$s = $src . '/' . $it;
			$d = $dst . '/' . $it;
			if ( is_link( $s ) ) {
				continue;
			}
			if ( is_dir( $s ) ) {
				if ( ! self::copy_tree( $s, $d ) ) {
					return false;
				}
			} elseif ( ! @copy( $s, $d ) ) {
				return false;
			}
		}
		return true;
	}

	/** rename, falling back to copy + delete across devices. */
	private static function move_dir( string $src, string $dst ): bool {
		if ( @rename( $src, $dst ) ) {
			return true;
		}
		if ( self::copy_tree( $src, $dst ) ) {
			self::rrmdir( $src );
			return true;
		}
		self::rrmdir( $dst );
		return false;
	}

	private static function opcache_invalidate( string $file ): void {
		if ( function_exists( 'opcache_invalidate' ) && 1 === preg_match( self::PHP_EXT_RE, $file ) ) {
			@opcache_invalidate( $file, true );
		}
	}

	private static function opcache_invalidate_tree( string $dir ): void {
		if ( ! function_exists( 'opcache_invalidate' ) || ! is_dir( $dir ) ) {
			return;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( $f instanceof SplFileInfo && $f->isFile() ) {
				self::opcache_invalidate( $f->getPathname() );
			}
		}
	}

	/** Public wrapper for callers outside this class (self-update restore). */
	public static function opcache_invalidate_dir( string $dir ): void {
		self::opcache_invalidate_tree( $dir );
	}

	// -- ZIP ----------------------------------------------------------------------------------

	/**
	 * Strict entry-name check. Returns normalized name or null if unsafe.
	 */
	public static function safe_zip_name( string $name ): ?string {
		if ( '' === $name || strlen( $name ) > 1024 || str_contains( $name, "\0" ) || str_contains( $name, '\\' ) ) {
			return null;
		}
		if ( 1 === preg_match( '/[\x00-\x1f\x7f]/', $name ) ) {
			return null;
		}
		if ( str_starts_with( $name, '/' ) || 1 === preg_match( '/^[A-Za-z]:/', $name ) ) {
			return null;
		}
		$segs = explode( '/', rtrim( $name, '/' ) );
		foreach ( $segs as $s ) {
			if ( '' === $s || '.' === $s || '..' === $s || strlen( $s ) > 255 ) {
				return null;
			}
		}
		return $name;
	}

	/**
	 * Validate and extract a ZIP into $dest (must be empty/new). Rejects zip-slip,
	 * symlinks/special files, size or ratio bombs, duplicate names, multiple roots,
	 * .phar, and lints every PHP file.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	/**
	 * True when a ZIP root folder could replace the running fleet plugin: known slugs, any name
	 * containing them (GitHub release folders such as overlord-wp-fleet-main), or the actual
	 * install folder of this plugin whatever it is called.
	 */
	public static function is_self_root( string $root ): bool {
		$l = strtolower( $root );
		if ( in_array( $l, self::SELF_SLUGS, true ) ) {
			return true;
		}
		foreach ( self::SELF_SLUGS as $s ) {
			if ( str_contains( $l, $s ) ) {
				return true;
			}
		}
		if ( defined( 'OVP_FLEET_FILE' ) ) {
			$own = strtolower( basename( dirname( (string) constant( 'OVP_FLEET_FILE' ) ) ) );
			if ( '' !== $own && $own === $l ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Any top-level PHP file of the ZIP root carrying the fleet plugin header/constants is the
	 * fleet plugin, whatever the folder is named.
	 *
	 * @param array<int, array{i:int,name:string,dir:bool,size:int}> $entries
	 */
	private static function zip_has_self_header( ZipArchive $za, array $entries, string $root ): bool {
		foreach ( $entries as $e ) {
			if ( $e['dir'] || $e['size'] > 4194304 || 1 !== preg_match( '#^' . preg_quote( $root, '#' ) . '/[^/]+\.php$#i', $e['name'] ) ) {
				continue;
			}
			$head = $za->getFromIndex( $e['i'], 16384 );
			if ( is_string( $head ) && 1 === preg_match( '/Plugin Name:\s*Overlord WP Fleet|Text Domain:\s*overlord-wp-fleet|OVP_FLEET_VERSION|OVP_FLEET_PLUGIN_NAME/i', $head ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * True when a plugin ZIP root may replace the running plugin in place: exactly the installed
	 * folder, which must itself be the slug folder (the self-update backup and guard are built on it).
	 * A root such as overlord-wp-fleet-main would land a second copy beside the live one.
	 */
	public static function is_own_folder_root( string $root ): bool {
		if ( ! defined( 'OVP_FLEET_FILE' ) ) {
			return false;
		}
		$own = basename( dirname( (string) constant( 'OVP_FLEET_FILE' ) ) );
		// Byte-exact on purpose: on a case-sensitive filesystem Overlord-WP-Fleet is a different folder, and the swap would
		// put a second copy beside the live one without the guard.
		return 'overlord-wp-fleet' === $own && $root === $own;
	}

	/**
	 * @param bool $allow_self Effective tier T3 only: a plugin ZIP whose root is exactly the installed
	 *                         folder may be unpacked (it replaces this plugin in place). Every other
	 *                         self-looking ZIP stays refused.
	 */
	public static function unpack_zip( string $zip_path, string $dest, string $type, bool $allow_self = false ): array|WP_Error {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return self::err( 'ovp_zip_unavailable', 'ZipArchive is not available.', 501 );
		}
		$za = new ZipArchive();
		$rc = $za->open( $zip_path, ZipArchive::CHECKCONS );
		if ( true !== $rc ) {
			return self::err( 'ovp_zip_corrupt', 'ZIP failed integrity check (code ' . (string) $rc . ').', 422 );
		}
		try {
			$n = $za->numFiles;
			if ( $n < 1 || $n > (int) self::$cfg['zip_max_entries'] ) {
				return self::err( 'ovp_zip_limits', 'ZIP entry count out of range.', 422 );
			}
			$root     = null;
			$entries  = array();
			$seen     = array();
			$declared = 0;
			$zip_size = (int) @filesize( $zip_path );
			$ratio    = (int) self::$cfg['zip_ratio'];
			for ( $i = 0; $i < $n; $i++ ) {
				$st = $za->statIndex( $i );
				if ( ! is_array( $st ) ) {
					return self::err( 'ovp_zip_corrupt', 'Unreadable ZIP entry.', 422 );
				}
				$name = self::safe_zip_name( (string) $st['name'] );
				if ( null === $name ) {
					return self::err( 'ovp_zip_slip', 'Unsafe path in ZIP entry ' . ( $i + 1 ) . '.', 422 );
				}
				$is_dir = str_ends_with( $name, '/' );
				$first  = explode( '/', rtrim( $name, '/' ) )[0];
				if ( '__MACOSX' === $first ) {
					continue;
				}
				if ( ! $is_dir && ! str_contains( $name, '/' ) ) {
					return self::err( 'ovp_zip_root', 'ZIP must contain a single top-level folder.', 422 );
				}
				if ( null === $root ) {
					$root = $first;
				} elseif ( $first !== $root ) {
					return self::err( 'ovp_zip_root', 'ZIP must contain a single top-level folder.', 422 );
				}
				// Symlinks and special files.
				$opsys = 0;
				$attr  = 0;
				if ( $za->getExternalAttributesIndex( $i, $opsys, $attr ) && ZipArchive::OPSYS_UNIX === $opsys ) {
					$mode = ( $attr >> 16 ) & 0xF000;
					if ( 0 !== $mode && 0x8000 !== $mode && 0x4000 !== $mode ) {
						return self::err( 'ovp_zip_symlink', 'ZIP contains a symlink or special file.', 422 );
					}
				}
				$key = strtolower( rtrim( $name, '/' ) );
				if ( isset( $seen[ $key ] ) ) {
					return self::err( 'ovp_zip_duplicate', 'ZIP contains duplicate paths.', 422 );
				}
				$seen[ $key ] = true;
				$size = (int) $st['size'];
				$comp = (int) $st['comp_size'];
				if ( ! $is_dir ) {
					if ( $size > (int) self::$cfg['zip_max_entry'] ) {
						return self::err( 'ovp_zip_limits', 'ZIP entry too large.', 422 );
					}
					if ( $size > 65536 && ( $comp < 1 || intdiv( $size, max( 1, $comp ) ) > $ratio ) ) {
						return self::err( 'ovp_zip_bomb', 'ZIP compression ratio too high.', 422 );
					}
					if ( 1 === preg_match( '/\.phar$/i', $name ) ) {
						return self::err( 'ovp_zip_forbidden', 'ZIP contains a .phar archive.', 422 );
					}
					if ( 1 === preg_match( '#(^|/)(\.user\.ini|php\.ini)$#i', $name ) ) {
						return self::err( 'ovp_zip_forbidden', 'ZIP contains a PHP config override.', 422 );
					}
				}
				$declared += $size;
				if ( $declared > (int) self::$cfg['zip_max_unpacked'] ) {
					return self::err( 'ovp_zip_limits', 'ZIP unpacked size too large.', 422 );
				}
				$entries[] = array( 'i' => $i, 'name' => $name, 'dir' => $is_dir, 'size' => $size );
			}
			if ( null === $root || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $root ) ) {
				return self::err( 'ovp_zip_root', 'Invalid top-level folder name.', 422 );
			}
			if ( self::is_self_root( $root ) || ( 'plugin' === $type && self::zip_has_self_header( $za, $entries, $root ) ) ) {
				if ( ! $allow_self || 'plugin' !== $type ) {
					return self::err( 'ovp_self_protected', 'Replacing the Overlord plugin needs effective tier T3.', 403 );
				}
				if ( ! self::is_own_folder_root( $root ) ) {
					return self::err( 'ovp_self_root_mismatch', 'To replace the Overlord plugin in place the ZIP must have exactly one top-level folder named like the installed one (overlord-wp-fleet). Another name would install a second copy.', 422 );
				}
			}
			if ( $zip_size > 0 && $declared > $zip_size * $ratio * 4 && $declared > 4194304 ) {
				return self::err( 'ovp_zip_bomb', 'ZIP overall ratio too high.', 422 );
			}

			if ( ! is_dir( $dest ) && ! @mkdir( $dest, 0700, true ) ) {
				return self::err( 'ovp_stage_unavailable', 'Cannot create extraction dir.', 500 );
			}
			$real_dest = realpath( $dest );
			if ( false === $real_dest ) {
				return self::err( 'ovp_stage_unavailable', 'Cannot resolve extraction dir.', 500 );
			}
			$total = 0;
			foreach ( $entries as $e ) {
				$target = $real_dest . '/' . rtrim( $e['name'], '/' );
				if ( $e['dir'] ) {
					if ( ! is_dir( $target ) && ! @mkdir( $target, 0755, true ) ) {
						return self::err( 'ovp_stage_unavailable', 'Cannot create directory.', 500 );
					}
					continue;
				}
				$pdir = dirname( $target );
				if ( ! is_dir( $pdir ) && ! @mkdir( $pdir, 0755, true ) ) {
					return self::err( 'ovp_stage_unavailable', 'Cannot create directory.', 500 );
				}
				$rp = realpath( $pdir );
				if ( false === $rp || ! self::path_inside( $rp, $real_dest ) ) {
					return self::err( 'ovp_zip_slip', 'Entry escapes extraction root.', 422 );
				}
				$in = $za->getStream( $e['name'] );
				if ( false === $in ) {
					return self::err( 'ovp_zip_corrupt', 'Cannot read ZIP entry.', 422 );
				}
				$out = @fopen( $target, 'xb' );
				if ( false === $out ) {
					fclose( $in );
					return self::err( 'ovp_stage_unavailable', 'Cannot create file.', 500 );
				}
				$written = 0;
				while ( ! feof( $in ) ) {
					$buf = fread( $in, 65536 );
					if ( false === $buf || '' === $buf ) {
						break;
					}
					$written += strlen( $buf );
					if ( $written > $e['size'] || $written > (int) self::$cfg['zip_max_entry'] ) {
						fclose( $in );
						fclose( $out );
						return self::err( 'ovp_zip_bomb', 'ZIP entry larger than declared.', 422 );
					}
					fwrite( $out, $buf );
				}
				fclose( $in );
				fclose( $out );
				if ( $written !== $e['size'] ) {
					return self::err( 'ovp_zip_corrupt', 'ZIP entry size mismatch.', 422 );
				}
				@chmod( $target, 0644 );
				$total += $written;
			}
		} finally {
			$za->close();
		}

		$tree = $real_dest . '/' . $root;
		if ( ! is_dir( $tree ) ) {
			return self::err( 'ovp_zip_root', 'ZIP has no content.', 422 );
		}

		// Walk the real tree: no symlinks, lint every PHP file, header sanity.
		$php      = array();
		$warnings = array();
		$all      = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tree, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $all as $f ) {
			if ( ! $f instanceof SplFileInfo ) {
				continue;
			}
			if ( $f->isLink() ) {
				return self::err( 'ovp_zip_symlink', 'Extracted tree contains a symlink.', 422 );
			}
			if ( $f->isFile() ) {
				$rel = ltrim( substr( $f->getPathname(), strlen( $tree ) ), '/' );
				if ( 1 === preg_match( self::PHP_EXT_RE, $rel ) ) {
					$php[] = array( 'full' => $f->getPathname(), 'rel' => $root . '/' . $rel );
				}
				if ( '.htaccess' === strtolower( $f->getFilename() ) ) {
					$warnings[] = 'contains .htaccess: ' . $rel;
				}
			}
		}
		$cli_ok = count( $php ) <= (int) self::$cfg['php_l_max_files'];
		foreach ( $php as $p ) {
			$res = self::lint_file( $p['full'], $cli_ok );
			if ( true !== $res ) {
				$res['file'] = $p['rel'];
				return self::lint_error( $res, $p['rel'] );
			}
		}
		if ( 'plugin' === $type ) {
			if ( ! self::has_plugin_header( $tree ) ) {
				return self::err( 'ovp_zip_not_plugin', 'No plugin header found in top-level PHP files.', 422 );
			}
		} elseif ( ! is_file( $tree . '/style.css' ) || 1 !== preg_match( '/^\s*(?:\/\*)?\s*Theme Name\s*:/mi', (string) file_get_contents( $tree . '/style.css', false, null, 0, 8192 ) ) ) {
			return self::err( 'ovp_zip_not_theme', 'style.css with Theme Name not found.', 422 );
		}
		return array(
			'slug'      => $root,
			'tree'      => $tree,
			'files'     => count( $entries ),
			'unpacked'  => $total,
			'php_files' => count( $php ),
			'lint_mode' => $cli_ok && null !== self::php_cli() ? 'tokens+php-l' : 'tokens',
			'warnings'  => $warnings,
		);
	}

	private static function has_plugin_header( string $tree ): bool {
		$files = glob( $tree . '/*.php' );
		if ( ! is_array( $files ) ) {
			return false;
		}
		foreach ( $files as $f ) {
			$head = (string) file_get_contents( $f, false, null, 0, 8192 );
			if ( 1 === preg_match( '/^[ \t\/*#@]*Plugin Name\s*:/mi', $head ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Verify, unpack to a staged tree and swap into $install_root/<slug> keeping a
	 * restorable backup. Does NOT activate.
	 *
	 * @param string $install_root WP_PLUGIN_DIR or theme root
	 * @return array<string, mixed>|WP_Error
	 */
	public static function finalize_zip( string $id, string $tier, string $install_root, bool $allow_self = false ): array|WP_Error {
		$meta = self::meta( $id );
		if ( $meta instanceof WP_Error ) {
			return $meta;
		}
		if ( 'zip' !== $meta['kind'] ) {
			return self::err( 'ovp_upload_invalid', 'Not a ZIP upload.' );
		}
		if ( self::tier_rank( $tier ) < self::tier_rank( (string) $meta['tier'] ) ) {
			return self::err( 'ovp_tier_denied', 'Tier ' . $meta['tier'] . ' required.', 403 );
		}
		$ok = self::verify_complete( $meta );
		if ( $ok instanceof WP_Error ) {
			return $ok;
		}
		$dir = (string) $meta['_dir'];
		$res = self::install_zip_file( $dir . '/data.part', $dir . '/x', (string) $meta['type'], $install_root, $allow_self );
		self::rrmdir( $dir );
		if ( ! $res instanceof WP_Error ) {
			$res['sha256'] = $meta['sha256'];
		}
		return $res;
	}

	/**
	 * Validate, unpack ($work must not exist yet) and swap a ZIP that is already on disk
	 * (upload stage or a downloaded URL). Does NOT activate and does not delete $work.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function install_zip_file( string $zip, string $work, string $type, string $install_root, bool $allow_self = false ): array|WP_Error {
		$un = self::unpack_zip( $zip, $work, $type, $allow_self );
		if ( $un instanceof WP_Error ) {
			return $un;
		}
		$root = realpath( $install_root );
		if ( false === $root || ! is_dir( $root ) ) {
			return self::err( 'ovp_install_root', 'Install root not found.', 500 );
		}
		if ( 'plugin' === $type && self::is_self_root( (string) $un['slug'] ) ) {
			// Anything that looks like this plugin takes the guarded path or none: never an unguarded swap.
			if ( ! $allow_self || ! self::is_own_folder_root( (string) $un['slug'] ) || ! is_dir( $root . '/' . $un['slug'] ) ) {
				return self::err( 'ovp_self_protected', 'The Overlord plugin can only be replaced in place, at tier T3, by a ZIP whose folder is exactly the installed one.', 403 );
			}
			$bad = self::check_self_replacement( (string) $un['tree'], (string) $un['slug'] );
			if ( $bad instanceof WP_Error ) {
				return $bad;
			}
			return self::replace_self_in_place( $un, $root, $type );
		}
		$swap = self::swap_dir( (string) $un['tree'], $root . '/' . $un['slug'], $type, (string) $un['slug'] );
		if ( $swap instanceof WP_Error ) {
			return $swap;
		}
		self::opcache_invalidate_tree( $root . '/' . $un['slug'] );
		return array(
			'type'      => $type,
			'slug'      => $un['slug'],
			'target'    => $root . '/' . $un['slug'],
			'backup'    => $swap['backup'],
			'fresh'     => null === $swap['backup'],
			'files'     => $un['files'],
			'php_files' => $un['php_files'],
			'lint_mode' => $un['lint_mode'],
			'warnings'  => $un['warnings'],
		);
	}

	/**
	 * Gate for the T3 in-place replace of this plugin (no signature check on that path): the
	 * Version header of the main file must be at least SELF_MIN_VERSION, and uninstall.php (if any)
	 * must not call wp_delete_user() or wp_delete_post() (comments and docblocks are ignored).
	 * Fails closed on an unreadable version or an uninstall.php that does not tokenize.
	 *
	 * @param string $tree Unpacked plugin folder.
	 * @param string $slug Folder name; the main file is <slug>/<slug>.php, the only file read for the version.
	 */
	public static function check_self_replacement( string $tree, string $slug = 'overlord-wp-fleet' ): ?WP_Error {
		$version = null;
		$main    = $tree . '/' . $slug . '.php';
		if ( is_file( $main ) ) {
			$head = (string) file_get_contents( $main, false, null, 0, 8192 );
			if ( 1 === preg_match( '/^[ \t\/*#@]*Plugin Name\s*:/mi', $head ) && 1 === preg_match( '/^[ \t\/*#@]*Version\s*:\s*([0-9]+(?:\.[0-9]+){1,3})\s*$/mi', $head, $m ) ) {
				$version = $m[1];
			}
		}
		if ( null === $version || version_compare( $version, self::SELF_MIN_VERSION, '<' ) ) {
			return self::err( 'ovp_self_version_floor', 'The Overlord plugin can only be replaced by version ' . self::SELF_MIN_VERSION . ' or newer (this ZIP: ' . ( $version ?? 'no readable Version header' ) . ').', 422 );
		}
		$un = $tree . '/uninstall.php';
		if ( is_file( $un ) ) {
			$hit = self::uninstall_deletes_content( (string) file_get_contents( $un ) );
			if ( null !== $hit ) {
				return self::err( 'ovp_self_uninstall_unsafe', 'uninstall.php calls ' . $hit . '(); a ZIP that can delete users or content on uninstall is refused.', 422 );
			}
		}
		return null;
	}

	/** Name of the first wp_delete_user/wp_delete_post call (or string reference) in PHP source, ignoring comments; null if none. Unparseable source counts as a hit. */
	private static function uninstall_deletes_content( string $code ): ?string {
		try {
			$tokens = token_get_all( $code, TOKEN_PARSE );
		} catch ( \Throwable $e ) {
			return 'unparseable code';
		}
		$bad = array( 'wp_delete_user', 'wp_delete_post' );
		$n   = count( $tokens );
		for ( $i = 0; $i < $n; $i++ ) {
			$t = $tokens[ $i ];
			if ( ! is_array( $t ) ) {
				continue;
			}
			if ( T_CONSTANT_ENCAPSED_STRING === $t[0] || T_ENCAPSED_AND_WHITESPACE === $t[0] ) {
				$lit = strtolower( trim( $t[1], '\'"' ) );
				if ( in_array( $lit, $bad, true ) ) {
					return $lit;
				}
				continue;
			}
			$name = strtolower( ltrim( $t[1], '\\' ) );
			if ( in_array( $name, $bad, true ) && ( T_STRING === $t[0] || ( defined( 'T_NAME_FULLY_QUALIFIED' ) && T_NAME_FULLY_QUALIFIED === $t[0] ) ) ) {
				for ( $j = $i + 1; $j < $n; $j++ ) {
					$u = $tokens[ $j ];
					if ( is_array( $u ) && in_array( $u[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
						continue;
					}
					if ( '(' === $u ) {
						return $name;
					}
					break;
				}
			}
		}
		return null;
	}

	/**
	 * Replace the running plugin with an already validated tree, in place, at T3.
	 *
	 * A plain directory swap (swap_dir): the old folder is renamed aside, the new one renamed in, and
	 * the old copy is kept under the staging area, outside the plugins directory. It never runs the old
	 * copy's uninstall.php and never calls delete_plugins(), wp_delete_user() or wp_delete_post(), so no
	 * content or user can go with it. The whole swap runs inside the self-update guard: the previous
	 * version is also copied aside, a must-use guard restores it if the new code fatals on load, and a
	 * loopback probe of the new code restores it if it does not answer.
	 *
	 * @param array<string, mixed> $un Result of unpack_zip.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function replace_self_in_place( array $un, string $root, string $type ): array|WP_Error {
		$target = $root . '/' . $un['slug'];
		$swap   = null;
		$state  = Ovp_Self_Update::guarded_replace(
			static function () use ( $un, $target, $type, &$swap ): true|WP_Error {
				$r = self::swap_dir( (string) $un['tree'], $target, $type, (string) $un['slug'] );
				if ( $r instanceof WP_Error ) {
					return $r;
				}
				$swap = $r;
				self::opcache_invalidate_tree( $target );
				return true;
			}
		);
		if ( $state instanceof WP_Error ) {
			return $state;
		}
		$ok = 'ok' === ( $state['state'] ?? '' ) || 'installed_inactive' === ( $state['state'] ?? '' );
		if ( ! $ok ) {
			return self::err(
				'ovp_self_replace_rolled_back',
				'The new copy of the Overlord plugin did not come up (' . (string) ( $state['reason'] ?? 'unknown' ) . '); the previous version was put back. State: ' . (string) ( $state['state'] ?? '' ) . '.',
				500,
				array( 'self_update' => $state )
			);
		}
		return array(
			'type'        => $type,
			'slug'        => $un['slug'],
			'target'      => $target,
			'backup'      => $swap['backup'] ?? null,
			'fresh'       => false,
			'files'       => $un['files'],
			'php_files'   => $un['php_files'],
			'lint_mode'   => $un['lint_mode'],
			'warnings'    => $un['warnings'],
			'self_update' => $state,
		);
	}

	/**
	 * Swap $new_tree into $target. Existing target is moved aside (same parent, so the
	 * rename is atomic), the new tree renamed in, and the old copy moved to the backup
	 * area (N versions kept). Any failure restores the old dir.
	 *
	 * @return array{backup:?string}|WP_Error
	 */
	public static function swap_dir( string $new_tree, string $target, string $kind, string $slug ): array|WP_Error {
		if ( is_link( $target ) ) {
			return self::err( 'ovp_dest_is_link', 'Refusing to replace a symlinked directory.', 403 );
		}
		$parent = dirname( $target );
		$rand   = bin2hex( random_bytes( 5 ) );
		$staged = $parent . '/.ovp-new-' . $rand;
		if ( ! self::move_dir( $new_tree, $staged ) ) {
			return self::err( 'ovp_swap_failed', 'Cannot stage new tree beside target.', 500 );
		}
		$aside = null;
		if ( is_dir( $target ) ) {
			$aside = $parent . '/.ovp-old-' . $rand;
			if ( ! @rename( $target, $aside ) ) {
				self::rrmdir( $staged );
				return self::err( 'ovp_swap_failed', 'Cannot move current copy aside.', 500 );
			}
		} elseif ( file_exists( $target ) ) {
			self::rrmdir( $staged );
			return self::err( 'ovp_swap_failed', 'Target exists and is not a directory.', 409 );
		}
		if ( ! @rename( $staged, $target ) ) {
			if ( null !== $aside ) {
				@rename( $aside, $target );
			}
			self::rrmdir( $staged );
			return self::err( 'ovp_swap_failed', 'Atomic swap failed; previous copy restored.', 500 );
		}
		$backup = null;
		if ( null !== $aside ) {
			$broot = self::sub_dir( 'backups/' . $kind . '/' . $slug );
			if ( is_string( $broot ) ) {
				$name = gmdate( 'YmdHis' ) . '-' . $rand;
				if ( self::move_dir( $aside, $broot . '/' . $name ) ) {
					$backup = $broot . '/' . $name;
					self::prune( $broot, '', (int) self::$cfg['keep_backups'] );
				}
			}
			if ( null === $backup ) {
				// Keep the old copy beside the target rather than lose it.
				$backup = $aside;
			}
		}
		return array( 'backup' => $backup );
	}

	/**
	 * Put the previous copy back (or remove a fresh install). The failed copy is kept
	 * under backups/failed for forensics.
	 *
	 * @param array<string, mixed> $ctx target, backup(?string), kind, slug, fresh(bool: remove target when there is no backup)
	 */
	public static function restore_swap( array $ctx ): bool {
		$target = (string) ( $ctx['target'] ?? '' );
		$backup = isset( $ctx['backup'] ) && is_string( $ctx['backup'] ) && '' !== $ctx['backup'] ? $ctx['backup'] : null;
		if ( '' === $target || strlen( $target ) < 4 ) {
			return false;
		}
		if ( null === $backup && empty( $ctx['fresh'] ) ) {
			// Activation of a pre-existing copy: nothing to restore, only deactivate.
			return true;
		}
		$parent = dirname( $target );
		$rand   = bin2hex( random_bytes( 4 ) );
		$failed = null;
		if ( is_dir( $target ) ) {
			$failed = $parent . '/.ovp-failed-' . $rand;
			if ( ! @rename( $target, $failed ) ) {
				return false;
			}
		}
		$ok = true;
		if ( null !== $backup && is_dir( $backup ) ) {
			$ok = self::move_dir( $backup, $target );
			if ( ! $ok && null !== $failed ) {
				@rename( $failed, $target );
				return false;
			}
		}
		if ( null !== $failed ) {
			$froot = self::sub_dir( 'backups/failed' );
			if ( is_string( $froot ) && self::move_dir( $failed, $froot . '/' . gmdate( 'YmdHis' ) . '-' . $rand ) ) {
				self::prune( $froot, '', 3 );
			} else {
				self::rrmdir( $failed );
			}
		}
		self::opcache_invalidate_tree( $target );
		return $ok;
	}

	// -- Guarded activation ---------------------------------------------------------------------

	/**
	 * Run $activate under a fatal guard. On exception, WP_Error, failed probe, new fatal
	 * record, exit() or a real PHP fatal (shutdown handler) the previous copy is restored,
	 * the item is deactivated and the result carries rolled_back=true plus the real error.
	 *
	 * @param array<string, mixed> $ctx kind, slug, target, backup, plugin_file
	 * @param callable():mixed $activate returns true/WP_Error/anything
	 * @param null|callable():?array $probe returns null when healthy, else array(error_msg,...)
	 * @param null|callable():void $deactivate called before restoring files
	 * @param null|callable(float):?array $fatal_since returns last fatal record newer than ts
	 * @return array<string, mixed>
	 */
	public static function activate_guarded( array $ctx, callable $activate, ?callable $probe = null, ?callable $deactivate = null, ?callable $fatal_since = null ): array {
		$started = microtime( true );
		$marker  = self::write_marker( $ctx );
		$state   = new stdClass();
		$state->done = false;

		$finish = static function ( array $res ) use ( $marker, $state ): array {
			$state->done = true;
			if ( null !== $marker ) {
				@unlink( $marker );
			}
			return $res;
		};
		$rollback = static function ( array $err ) use ( $ctx, $deactivate, $state, $marker ): array {
			$state->done = true;
			if ( null !== $deactivate ) {
				try {
					$deactivate();
				} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
					unset( $e );
				}
			}
			$restored = self::restore_swap( $ctx );
			if ( null !== $marker ) {
				@unlink( $marker );
			}
			return array_merge(
				array(
					'activated'   => false,
					'rolled_back' => true,
					'restored'    => $restored,
					'had_backup'  => ! empty( $ctx['backup'] ),
				),
				$err
			);
		};

		register_shutdown_function(
			static function () use ( $state, $rollback ): void {
				if ( $state->done ) {
					return;
				}
				$e = error_get_last();
				if ( is_array( $e ) && in_array( (int) $e['type'], self::FATAL_TYPES, true ) ) {
					$err = array(
						'error_file' => self::redact_path( (string) $e['file'] ),
						'error_line' => (int) $e['line'],
						'error_msg'  => (string) $e['message'],
					);
				} else {
					$err = array( 'error_file' => '', 'error_line' => 0, 'error_msg' => 'Process exited during activation.' );
				}
				$res = $rollback( $err );
				while ( ob_get_level() > 0 ) {
					@ob_end_clean();
				}
				if ( ! headers_sent() ) {
					http_response_code( 200 );
					header( 'Content-Type: application/json; charset=utf-8' );
				}
				echo (string) json_encode( array( 'ok' => true, 'data' => $res ) );
			}
		);

		try {
			$r = $activate();
		} catch ( \Throwable $e ) {
			return $finish(
				$rollback(
					array(
						'error_file' => self::redact_path( $e->getFile() ),
						'error_line' => $e->getLine(),
						'error_msg'  => $e->getMessage(),
					)
				)
			);
		}
		if ( $r instanceof WP_Error ) {
			return $finish( $rollback( array( 'error_file' => '', 'error_line' => 0, 'error_msg' => $r->get_error_message() ) ) );
		}
		if ( null !== $probe ) {
			try {
				$bad = $probe();
			} catch ( \Throwable $e ) {
				$bad = array( 'error_msg' => $e->getMessage(), 'error_file' => self::redact_path( $e->getFile() ), 'error_line' => $e->getLine() );
			}
			if ( is_array( $bad ) ) {
				return $finish( $rollback( array_merge( array( 'error_file' => '', 'error_line' => 0, 'error_msg' => 'Health probe failed.' ), $bad ) ) );
			}
		}
		if ( null !== $fatal_since ) {
			$fatal = $fatal_since( $started - 1.0 );
			if ( is_array( $fatal ) ) {
				return $finish(
					$rollback(
						array(
							'error_file' => self::redact_path( (string) ( $fatal['file'] ?? '' ) ),
							'error_line' => (int) ( $fatal['line'] ?? 0 ),
							'error_msg'  => (string) ( $fatal['message'] ?? 'Fatal recorded after activation.' ),
						)
					)
				);
			}
		}
		return $finish( array( 'activated' => true, 'rolled_back' => false ) );
	}

	/** Strip the filesystem prefix so absolute server paths are not echoed back. */
	private static function redact_path( string $path ): string {
		$roots = array();
		if ( defined( 'ABSPATH' ) ) {
			$roots[] = rtrim( (string) ABSPATH, '/\\' ) . '/';
		}
		foreach ( $roots as $r ) {
			if ( str_starts_with( $path, $r ) ) {
				return substr( $path, strlen( $r ) );
			}
		}
		return $path;
	}

	/** @param array<string, mixed> $ctx */
	private static function write_marker( array $ctx ): ?string {
		$dir = self::sub_dir( 'pending' );
		if ( $dir instanceof WP_Error ) {
			return null;
		}
		$path = $dir . '/act-' . bin2hex( random_bytes( 8 ) ) . '.json';
		$ctx['started'] = time();
		return false !== file_put_contents( $path, (string) json_encode( $ctx ), LOCK_EX ) ? $path : null;
	}

	/**
	 * Finish rollbacks that a dead process could not (hard kill between fatal and
	 * shutdown). Markers older than $min_age seconds are processed. Call on later requests.
	 *
	 * @param null|callable(array):void $deactivate receives the marker ctx
	 * @return int number of rollbacks performed
	 */
	public static function recover_pending( ?callable $deactivate = null, int $min_age = 30 ): int {
		$dir = self::sub_dir( 'pending' );
		if ( $dir instanceof WP_Error ) {
			return 0;
		}
		$n = 0;
		foreach ( (array) glob( $dir . '/act-*.json' ) as $f ) {
			$age = time() - (int) @filemtime( $f );
			if ( $age < $min_age ) {
				continue;
			}
			$ctx = json_decode( (string) file_get_contents( $f ), true );
			@unlink( $f );
			if ( ! is_array( $ctx ) ) {
				continue;
			}
			if ( null !== $deactivate ) {
				try {
					$deactivate( $ctx );
				} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
					unset( $e );
				}
			}
			self::restore_swap( $ctx );
			++$n;
		}
		return $n;
	}

	// -- GC -------------------------------------------------------------------------------------

	public static function gc(): void {
		$stages = self::sub_dir( 'stage' );
		if ( is_string( $stages ) ) {
			foreach ( (array) glob( $stages . '/*', GLOB_ONLYDIR ) as $d ) {
				$m = max( (int) @filemtime( $d . '/data.part' ), (int) @filemtime( $d . '/meta.json' ) );
				if ( time() - $m > (int) self::$cfg['stage_ttl'] ) {
					self::rrmdir( $d );
				}
			}
		}
		$tmp = self::sub_dir( 'tmp' );
		if ( is_string( $tmp ) ) {
			foreach ( (array) glob( $tmp . '/w-*' ) as $f ) {
				if ( time() - (int) @filemtime( $f ) > 3600 ) {
					@unlink( $f );
				}
			}
		}
	}
}
