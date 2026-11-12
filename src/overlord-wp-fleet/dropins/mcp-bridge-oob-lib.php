<?php
/**
 * Emergency actions with no WordPress boot. Marker: MCP_BRIDGE_FATAL_DROPIN
 *
 * Visibility first (status / lint / logs), then files / SQL / package fix.
 *
 * @package WP_MCP_Bridge
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'MCP_BRIDGE_OOB_LIB' ) ) {
	define( 'MCP_BRIDGE_OOB_LIB', '1.6.1' );
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function mcp_bridge_oob_dispatch( string $wp_content, array $input, string $via ): array {
	$action = isset( $input['action'] ) ? (string) $input['action'] : 'status';
	$result = match ( $action ) {
		'status'      => mcp_bridge_oob_status( $wp_content ),
		'logs'        => mcp_bridge_oob_logs( $wp_content, $input ),
		'files_list'  => mcp_bridge_oob_files_list( $wp_content, $input ),
		'files_read'  => mcp_bridge_oob_files_read( $wp_content, $input ),
		'files_write' => mcp_bridge_oob_files_write( $wp_content, $input ),
		'files_delete'=> mcp_bridge_oob_files_delete( $wp_content, $input ),
		'sql'         => mcp_bridge_oob_sql( $wp_content, $input ),
		'sql_backup'  => mcp_bridge_oob_sql_backup( $wp_content, $input ),
		'pkg_list'    => mcp_bridge_oob_pkg_list( $wp_content ),
		'pkg_zip'     => mcp_bridge_oob_pkg_zip( $wp_content, $input ),
		'pkg_replace' => mcp_bridge_oob_pkg_replace( $wp_content, $input ),
		default       => array( 'ok' => false, 'error' => 'unknown_action', 'action' => $action ),
	};
	$result['via']     = $via;
	$result['action']  = $action;
	$result['php']     = PHP_VERSION;
	$result['ok']      = $result['ok'] ?? true;
	return $result;
}

/**
 * @return array<string, mixed>
 */
function mcp_bridge_oob_request_input(): array {
	$json = array();
	$raw  = file_get_contents( 'php://input' );
	if ( is_string( $raw ) && '' !== $raw ) {
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			$json = $decoded;
		}
	}
	$get = $_GET;
	return array_merge( is_array( $get ) ? $get : array(), $json );
}

/**
 * @return array<string, mixed>
 */
function mcp_bridge_oob_status( string $wp_content ): array {
	$store  = $wp_content . '/uploads/mcp-bridge';
	$fatal  = null;
	$fp     = $store . '/fatal-latest.json';
	if ( is_readable( $fp ) ) {
		$decoded = json_decode( (string) file_get_contents( $fp ), true );
		$fatal   = is_array( $decoded ) ? $decoded : null;
	}

	$mu_lint     = mcp_bridge_oob_lint_dir( $wp_content . '/mu-plugins', $wp_content, 1 );
	$dropin_lint = array();
	$cfg = dirname( $wp_content ) . '/wp-config.php';
	if ( is_file( $cfg ) ) {
		$dropin_lint[] = mcp_bridge_oob_lint_file( $cfg, dirname( $wp_content ) );
	}
	foreach ( array( 'fatal-error-handler.php', 'object-cache.php', 'advanced-cache.php', 'db.php', 'sunrise.php', 'mcp-bridge-oob.php', 'mcp-bridge-oob-lib.php' ) as $leaf ) {
		$path = $wp_content . '/' . $leaf;
		if ( ! is_file( $path ) ) {
			continue;
		}
		$dropin_lint[] = mcp_bridge_oob_lint_file( $path, $wp_content );
	}

	$broken = array();
	foreach ( array_merge( $mu_lint, $dropin_lint ) as $row ) {
		if ( empty( $row['ok'] ) ) {
			$broken[] = $row;
		}
	}

	$hint = 'No parse errors in mu-plugins or wp-content drop-ins.';
	if ( is_array( $fatal ) && isset( $fatal['message'] ) ) {
		$hint = 'Last PHP fatal: ' . (string) $fatal['message'];
		if ( isset( $fatal['file'], $fatal['line'] ) ) {
			$hint .= ' @ ' . (string) $fatal['file'] . ':' . (string) $fatal['line'];
		}
	}
	if ( array() !== $broken ) {
		$first = $broken[0];
		$hint  = 'Parse error in ' . (string) ( $first['rel'] ?? $first['path'] ?? '?' ) . ': ' . (string) ( $first['error'] ?? 'syntax' );
	}

	return array(
		'ok'      => true,
		'nagging' => array(
			'hint'         => $hint,
			'fatal'        => $fatal,
			'showing'      => is_file( $store . '/show-fatals.flag' ),
			'wp_debug'     => mcp_bridge_oob_wp_debug_flags( $wp_content ),
			'broken_files' => $broken,
			'mu_lint'      => $mu_lint,
			'dropin_lint'  => $dropin_lint,
			'logs'         => mcp_bridge_oob_log_candidates( $wp_content ),
		),
	);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function mcp_bridge_oob_logs( string $wp_content, array $input ): array {
	$bytes = isset( $input['bytes'] ) ? (int) $input['bytes'] : 32000;
	$want  = isset( $input['path'] ) ? (string) $input['path'] : '';
	$rows  = mcp_bridge_oob_log_candidates( $wp_content );
	$pick  = '';
	if ( '' !== $want ) {
		foreach ( $rows as $row ) {
			if ( $row['path'] === $want && $row['exists'] ) {
				$pick = $want;
				break;
			}
		}
		if ( '' === $pick ) {
			return array( 'ok' => false, 'error' => 'log_denied', 'message' => 'Path is not a known PHP log.' );
		}
	} else {
		foreach ( $rows as $row ) {
			if ( $row['exists'] && $row['bytes'] > 0 && ! str_ends_with( $row['path'], 'fatal-latest.json' ) ) {
				$pick = $row['path'];
				break;
			}
		}
	}
	if ( '' === $pick ) {
		return array( 'ok' => true, 'candidates' => $rows, 'tail' => null );
	}
	return array(
		'ok'         => true,
		'candidates' => $rows,
		'tail'       => mcp_bridge_oob_tail( $pick, $bytes ),
	);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function mcp_bridge_oob_files_list( string $wp_content, array $input ): array {
	$rel  = isset( $input['path'] ) ? (string) $input['path'] : '';
	$path = mcp_bridge_oob_resolve( $wp_content, $rel, false );
	if ( false === $path || ! is_dir( $path ) ) {
		return array( 'ok' => false, 'error' => 'not_found', 'message' => 'Directory not found.' );
	}
	$list = @scandir( $path );
	if ( ! is_array( $list ) ) {
		return array( 'ok' => false, 'error' => 'read_failed' );
	}
	$entries = array();
	foreach ( $list as $name ) {
		if ( '.' === $name || '..' === $name ) {
			continue;
		}
		$entries[] = $name;
	}
	sort( $entries );
	return array( 'ok' => true, 'path' => $rel, 'entries' => $entries );
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function mcp_bridge_oob_files_read( string $wp_content, array $input ): array {
	$rel  = isset( $input['path'] ) ? (string) $input['path'] : '';
	$path = mcp_bridge_oob_resolve( $wp_content, $rel, false );
	if ( false === $path || ! is_file( $path ) ) {
		return array( 'ok' => false, 'error' => 'not_found', 'message' => 'File not found.' );
	}
	$size = (int) filesize( $path );
	if ( $size > 512000 ) {
		return array( 'ok' => false, 'error' => 'too_large', 'message' => 'OOB read cap is 512KB. Use REST files_read after boot.' );
	}
	$raw = (string) file_get_contents( $path );
	return array(
		'ok'       => true,
		'path'     => $rel,
		'size'     => $size,
		'encoding' => 'base64',
		'content'  => base64_encode( $raw ),
	);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function mcp_bridge_oob_files_write( string $wp_content, array $input ): array {
	$rel = isset( $input['path'] ) ? (string) $input['path'] : '';
	if ( mcp_bridge_oob_is_protected( $rel ) ) {
		return array( 'ok' => false, 'error' => 'protected', 'message' => 'Refusing to overwrite the oob probe itself.' );
	}
	$denied = mcp_bridge_oob_write_denied( $rel, ! empty( $input['confirm'] ) );
	if ( null !== $denied ) {
		return $denied;
	}
	[ $scope, $inner ] = mcp_bridge_oob_split_scope( $rel );
	$il                = strtolower( $inner );
	if ( 'abspath' === $scope && ( str_starts_with( $il, 'wp-admin/' ) || str_starts_with( $il, 'wp-includes/' ) ) && empty( $input['confirm'] ) ) {
		return array( 'ok' => false, 'error' => 'confirm_required', 'message' => 'Writing wp-admin or wp-includes needs confirm=true.' );
	}
	$path = mcp_bridge_oob_resolve( $wp_content, $rel, true );
	if ( false === $path ) {
		return array( 'ok' => false, 'error' => 'path_escape' );
	}
	$content  = (string) ( $input['content'] ?? '' );
	$encoding = (string) ( $input['encoding'] ?? 'text' );
	if ( 'base64' === $encoding ) {
		$decoded = base64_decode( $content, true );
		if ( false === $decoded ) {
			return array( 'ok' => false, 'error' => 'invalid_base64' );
		}
		$content = $decoded;
	}
	if ( strlen( $content ) > 2 * 1024 * 1024 ) {
		return array( 'ok' => false, 'error' => 'too_large', 'message' => 'OOB write cap is 2MB.' );
	}
	if ( str_ends_with( strtolower( $rel ), '.php' ) ) {
		$lint = mcp_bridge_oob_lint_string( $content );
		if ( null !== $lint ) {
			return array( 'ok' => false, 'error' => 'php_lint', 'message' => $lint );
		}
	}
	$backup = null;
	if ( is_file( $path ) ) {
		$backup = mcp_bridge_oob_backup_file( $wp_content, $path, $rel );
	}
	$dir = dirname( $path );
	if ( ! is_dir( $dir ) ) {
		@mkdir( $dir, 0755, true );
	}
	$ok = false !== file_put_contents( $path, $content );
	return array( 'ok' => $ok, 'path' => $rel, 'bytes' => strlen( $content ), 'backup' => $backup );
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function mcp_bridge_oob_files_delete( string $wp_content, array $input ): array {
	$rel = isset( $input['path'] ) ? (string) $input['path'] : '';
	if ( mcp_bridge_oob_is_protected( $rel ) ) {
		return array( 'ok' => false, 'error' => 'protected', 'message' => 'Refusing to delete the oob probe. That would lock you out again.' );
	}
	$path = mcp_bridge_oob_resolve( $wp_content, $rel, false );
	if ( false === $path || ( ! is_file( $path ) && ! is_dir( $path ) ) ) {
		return array( 'ok' => false, 'error' => 'not_found' );
	}
	$backup = is_file( $path ) ? mcp_bridge_oob_backup_file( $wp_content, $path, $rel ) : null;
	if ( is_dir( $path ) ) {
		return array( 'ok' => false, 'error' => 'is_dir', 'message' => 'OOB delete is files only. Zip-replace a package or boot WP for recursive delete.' );
	}
	$ok = @unlink( $path );
	return array( 'ok' => $ok, 'deleted' => $rel, 'backup' => $backup );
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function mcp_bridge_oob_sql( string $wp_content, array $input ): array {
	$sql     = trim( (string) ( $input['sql'] ?? '' ) );
	$confirm = ! empty( $input['confirm'] );
	if ( '' === $sql ) {
		return array( 'ok' => false, 'error' => 'missing_sql' );
	}
	$write = (bool) preg_match( '/^\s*(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|REPLACE|CREATE|GRANT|REVOKE|RENAME|CALL)\b/i', $sql );
	if ( $write && ! $confirm ) {
		return array(
			'ok'      => false,
			'error'   => 'confirm_required',
			'message' => 'Destructive SQL needs confirm=true. Run sql_backup first.',
			'write'   => true,
		);
	}
	$db = mcp_bridge_oob_mysqli( $wp_content );
	if ( is_array( $db ) && isset( $db['error'] ) ) {
		return $db;
	}
	if ( ! $db instanceof mysqli ) {
		return array( 'ok' => false, 'error' => 'db_connect' );
	}
	$result = $db->query( $sql );
	if ( false === $result ) {
		$err = $db->error;
		$db->close();
		return array( 'ok' => false, 'error' => 'sql_failed', 'message' => $err );
	}
	if ( true === $result ) {
		$affected = $db->affected_rows;
		$db->close();
		return array( 'ok' => true, 'write' => true, 'affected' => $affected );
	}
	$rows  = array();
	$limit = 200;
	while ( count( $rows ) < $limit && ( $row = $result->fetch_assoc() ) ) {
		$rows[] = $row;
	}
	$more = $result->num_rows > count( $rows );
	$result->free();
	$db->close();
	return array( 'ok' => true, 'write' => false, 'rows' => $rows, 'truncated' => $more );
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function mcp_bridge_oob_sql_backup( string $wp_content, array $input ): array {
	$table = preg_replace( '/[^A-Za-z0-9_]/', '', (string) ( $input['table'] ?? '' ) ) ?? '';
	if ( '' === $table ) {
		return array( 'ok' => false, 'error' => 'missing_table' );
	}
	$db = mcp_bridge_oob_mysqli( $wp_content );
	if ( is_array( $db ) && isset( $db['error'] ) ) {
		return $db;
	}
	if ( ! $db instanceof mysqli ) {
		return array( 'ok' => false, 'error' => 'db_connect' );
	}
	$create = $db->query( 'SHOW CREATE TABLE `' . $db->real_escape_string( $table ) . '`' );
	if ( false === $create ) {
		$err = $db->error;
		$db->close();
		return array( 'ok' => false, 'error' => 'sql_failed', 'message' => $err );
	}
	$crow   = $create->fetch_array();
	$ddl    = is_array( $crow ) ? (string) ( $crow[1] ?? '' ) : '';
	$create->free();
	$dump   = "-- oob backup {$table} " . gmdate( 'c' ) . "\nSET NAMES utf8mb4;\n{$ddl};\n";
	$sel    = $db->query( 'SELECT * FROM `' . str_replace( '`', '', $table ) . '`' );
	if ( false === $sel ) {
		$err = $db->error;
		$db->close();
		return array( 'ok' => false, 'error' => 'sql_failed', 'message' => $err );
	}
	$rows = 0;
	while ( $row = $sel->fetch_assoc() ) {
		$vals = array();
		foreach ( $row as $v ) {
			$vals[] = null === $v ? 'NULL' : "'" . $db->real_escape_string( (string) $v ) . "'";
		}
		$dump .= 'INSERT INTO `' . $table . '` VALUES (' . implode( ',', $vals ) . ");\n";
		++$rows;
		if ( strlen( $dump ) > 8 * 1024 * 1024 ) {
			break;
		}
	}
	$sel->free();
	$db->close();
	$dir = $wp_content . '/uploads/mcp-bridge/backups';
	if ( ! is_dir( $dir ) ) {
		@mkdir( $dir, 0755, true );
	}
	$name = $table . '-' . gmdate( 'Ymd-His' ) . '.sql';
	$ok   = false !== file_put_contents( $dir . '/' . $name, $dump );
	return array( 'ok' => $ok, 'table' => $table, 'rows' => $rows, 'file' => 'uploads/mcp-bridge/backups/' . $name, 'bytes' => strlen( $dump ) );
}

/**
 * @return array<string, mixed>
 */
function mcp_bridge_oob_pkg_list( string $wp_content ): array {
	$plugins = mcp_bridge_oob_scandir_names( $wp_content . '/plugins' );
	$themes  = mcp_bridge_oob_scandir_names( $wp_content . '/themes' );
	return array( 'ok' => true, 'plugins' => $plugins, 'themes' => $themes );
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function mcp_bridge_oob_pkg_zip( string $wp_content, array $input ): array {
	$kind = (string) ( $input['kind'] ?? 'plugin' );
	$slug = (string) ( $input['slug'] ?? '' );
	$root = mcp_bridge_oob_pkg_root( $wp_content, $kind, $slug );
	if ( false === $root ) {
		return array( 'ok' => false, 'error' => 'not_found' );
	}
	if ( ! class_exists( 'ZipArchive' ) ) {
		return array( 'ok' => false, 'error' => 'no_zip', 'message' => 'ZipArchive not available on this PHP.' );
	}
	$tmp = rtrim( sys_get_temp_dir(), '/' ) . '/mcp-oob-' . bin2hex( random_bytes( 6 ) ) . '.zip';
	$zip = new ZipArchive();
	if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		return array( 'ok' => false, 'error' => 'zip_open' );
	}
	mcp_bridge_oob_zip_add( $zip, $root, $slug );
	$zip->close();
	$raw = (string) file_get_contents( $tmp );
	@unlink( $tmp );
	if ( strlen( $raw ) > 20 * 1024 * 1024 ) {
		return array( 'ok' => false, 'error' => 'too_large', 'message' => 'OOB zip cap is 20MB.' );
	}
	return array(
		'ok'       => true,
		'kind'     => $kind,
		'slug'     => $slug,
		'bytes'    => strlen( $raw ),
		'encoding' => 'base64',
		'content'  => base64_encode( $raw ),
	);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function mcp_bridge_oob_pkg_replace( string $wp_content, array $input ): array {
	if ( empty( $input['confirm'] ) ) {
		return array( 'ok' => false, 'error' => 'confirm_required', 'message' => 'pkg_replace needs confirm=true. Run pkg_zip first as backup.' );
	}
	$kind = (string) ( $input['kind'] ?? 'plugin' );
	$slug = (string) ( $input['slug'] ?? '' );
	// Refused for these two slugs at every tier, on purpose. This code runs with no WordPress boot, so there is no
	// signed envelope, no plugin cap and no effective tier to consult: it cannot know whether the caller holds T3 (and
	// the entry file refuses every request in 2.0 anyway). Tier T3 replaces the Overlord plugin through plugins/install
	// or the upload route instead, which keep the previous version OUTSIDE the plugins directory; pkg_replace leaves a
	// <slug>.bak-<time> copy inside it, which WordPress would list as a second copy of the plugin.
	if ( in_array( $slug, array( 'wp-mcp-bridge', 'overlord-wp-fleet' ), true ) ) {
		return array( 'ok' => false, 'error' => 'protected' );
	}
	$root = mcp_bridge_oob_pkg_root( $wp_content, $kind, $slug );
	$dest_parent = 'plugin' === $kind ? $wp_content . '/plugins' : $wp_content . '/themes';
	$dest        = $dest_parent . '/' . $slug;
	if ( false === mcp_bridge_oob_safe_slug( $slug ) ) {
		return array( 'ok' => false, 'error' => 'bad_slug' );
	}
	$raw = base64_decode( (string) ( $input['content'] ?? '' ), true );
	if ( false === $raw || '' === $raw ) {
		return array( 'ok' => false, 'error' => 'invalid_base64' );
	}
	if ( strlen( $raw ) > 20 * 1024 * 1024 ) {
		return array( 'ok' => false, 'error' => 'too_large' );
	}
	if ( ! class_exists( 'ZipArchive' ) ) {
		return array( 'ok' => false, 'error' => 'no_zip' );
	}
	$tmp = rtrim( sys_get_temp_dir(), '/' ) . '/mcp-oob-in-' . bin2hex( random_bytes( 6 ) );
	@mkdir( $tmp, 0755, true );
	$zip_path = $tmp . '/in.zip';
	file_put_contents( $zip_path, $raw );
	$zip = new ZipArchive();
	if ( true !== $zip->open( $zip_path ) ) {
		mcp_bridge_oob_rmdir( $tmp );
		return array( 'ok' => false, 'error' => 'zip_open' );
	}
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$name = (string) $zip->getNameIndex( $i );
		if ( str_contains( $name, '..' ) || str_starts_with( $name, '/' ) ) {
			$zip->close();
			mcp_bridge_oob_rmdir( $tmp );
			return array( 'ok' => false, 'error' => 'zip_slip' );
		}
	}
	$zip->extractTo( $tmp . '/unpacked' );
	$zip->close();
	$unpacked = $tmp . '/unpacked';
	$lint     = mcp_bridge_oob_lint_tree( $unpacked, $unpacked );
	$bad      = array_values( array_filter( $lint, static fn( array $r ): bool => empty( $r['ok'] ) ) );
	if ( array() !== $bad ) {
		mcp_bridge_oob_rmdir( $tmp );
		return array( 'ok' => false, 'error' => 'php_lint', 'broken_files' => $bad );
	}
	$bak = null;
	if ( is_dir( $dest ) ) {
		$bak = $dest . '.bak-' . gmdate( 'YmdHis' );
		if ( ! @rename( $dest, $bak ) ) {
			mcp_bridge_oob_rmdir( $tmp );
			return array( 'ok' => false, 'error' => 'rename_failed' );
		}
	}
	$source = $unpacked;
	$kids   = array_values( array_filter( (array) @scandir( $unpacked ), static fn( $n ): bool => is_string( $n ) && '.' !== $n && '..' !== $n ) );
	if ( 1 === count( $kids ) && is_dir( $unpacked . '/' . $kids[0] ) ) {
		$source = $unpacked . '/' . $kids[0];
	}
	if ( ! @rename( $source, $dest ) ) {
		mcp_bridge_oob_copy_tree( $source, $dest );
	}
	mcp_bridge_oob_rmdir( $tmp );
	return array(
		'ok'     => is_dir( $dest ),
		'kind'   => $kind,
		'slug'   => $slug,
		'backup' => is_string( $bak ) ? str_replace( $wp_content, 'wp-content', $bak ) : null,
		'root'   => is_string( $root ) ? 'replaced' : 'created',
	);
}

/**
 * @return array<string, mixed>|mysqli
 */
function mcp_bridge_oob_mysqli( string $wp_content ): array|mysqli {
	if ( ! class_exists( 'mysqli' ) ) {
		return array( 'ok' => false, 'error' => 'no_mysqli' );
	}
	$cfg = mcp_bridge_oob_parse_wp_config( $wp_content );
	if ( isset( $cfg['error'] ) ) {
		return $cfg;
	}
	mysqli_report( MYSQLI_REPORT_OFF );
	$db = @new mysqli( $cfg['host'], $cfg['user'], $cfg['password'], $cfg['name'] );
	if ( $db->connect_errno ) {
		return array( 'ok' => false, 'error' => 'db_connect', 'message' => $db->connect_error );
	}
	if ( '' !== $cfg['charset'] ) {
		$db->set_charset( $cfg['charset'] );
	}
	return $db;
}

/**
 * @return array<string, mixed>
 */
function mcp_bridge_oob_parse_wp_config( string $wp_content ): array {
	$path = dirname( $wp_content ) . '/wp-config.php';
	if ( ! is_readable( $path ) ) {
		return array( 'ok' => false, 'error' => 'no_wp_config', 'message' => 'Cannot read wp-config.php without loading WordPress.' );
	}
	$src = (string) file_get_contents( $path );
	$get = static function ( string $key ) use ( $src ): ?string {
		if ( preg_match( "/define\s*\(\s*['\"]" . preg_quote( $key, '/' ) . "['\"]\s*,\s*['\"]([^'\"]*)['\"]/i", $src, $m ) ) {
			return $m[1];
		}
		return null;
	};
	$name = $get( 'DB_NAME' );
	$user = $get( 'DB_USER' );
	$pass = $get( 'DB_PASSWORD' );
	$host = $get( 'DB_HOST' ) ?? 'localhost';
	if ( null === $name || null === $user || null === $pass ) {
		return array( 'ok' => false, 'error' => 'db_parse', 'message' => 'DB_* defines are not plain strings. OOB SQL cannot run.' );
	}
	return array(
		'name'     => $name,
		'user'     => $user,
		'password' => $pass,
		'host'     => $host,
		'charset'  => $get( 'DB_CHARSET' ) ?? 'utf8mb4',
	);
}

/**
 * @return array<string, mixed>
 */
function mcp_bridge_oob_wp_debug_flags( string $wp_content ): array {
	$path = dirname( $wp_content ) . '/wp-config.php';
	$out  = array( 'readable' => is_readable( $path ) );
	if ( ! $out['readable'] ) {
		return $out;
	}
	$src = (string) file_get_contents( $path );
	foreach ( array( 'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG', 'WP_DISABLE_FATAL_ERROR_HANDLER' ) as $key ) {
		if ( preg_match( "/define\s*\(\s*['\"]" . preg_quote( $key, '/' ) . "['\"]\s*,\s*(true|false|1|0)/i", $src, $m ) ) {
			$out[ $key ] = in_array( strtolower( $m[1] ), array( 'true', '1' ), true );
		} else {
			$out[ $key ] = null;
		}
	}
	return $out;
}

/**
 * @return array<int, array{path: string, exists: bool, bytes: int}>
 */
function mcp_bridge_oob_log_candidates( string $wp_content ): array {
	$abspath = dirname( $wp_content );
	$ini     = (string) ini_get( 'error_log' );
	$paths   = array(
		$wp_content . '/debug.log',
		$wp_content . '/uploads/mcp-bridge/fatal-latest.json',
		$abspath . '/error_log',
		dirname( $abspath ) . '/logs/error.log',
		dirname( $abspath ) . '/logs/error_log',
	);
	if ( '' !== $ini && 'syslog' !== $ini ) {
		array_unshift( $paths, $ini );
	}
	$out  = array();
	$seen = array();
	foreach ( $paths as $path ) {
		if ( isset( $seen[ $path ] ) ) {
			continue;
		}
		$seen[ $path ] = true;
		$exists        = is_readable( $path );
		$out[]         = array(
			'path'   => $path,
			'exists' => $exists,
			'bytes'  => $exists ? (int) filesize( $path ) : 0,
		);
	}
	return $out;
}

/**
 * @return array{path: string, size: int, content: string}
 */
function mcp_bridge_oob_tail( string $path, int $bytes ): array {
	$bytes  = max( 256, min( 256000, $bytes ) );
	$size   = (int) filesize( $path );
	$offset = max( 0, $size - $bytes );
	$fh     = fopen( $path, 'rb' );
	$raw    = '';
	if ( false !== $fh ) {
		if ( $offset > 0 ) {
			fseek( $fh, $offset );
		}
		$raw = (string) stream_get_contents( $fh );
		fclose( $fh );
	}
	return array( 'path' => $path, 'size' => $size, 'content' => $raw );
}

/**
 * @return array<int, array<string, mixed>>
 */
function mcp_bridge_oob_lint_dir( string $dir, string $root, int $depth ): array {
	if ( ! is_dir( $dir ) || $depth < 0 ) {
		return array();
	}
	$out  = array();
	$list = @scandir( $dir );
	if ( ! is_array( $list ) ) {
		return array();
	}
	foreach ( $list as $name ) {
		if ( '.' === $name || '..' === $name ) {
			continue;
		}
		$path = $dir . '/' . $name;
		if ( is_dir( $path ) && $depth > 0 ) {
			$out = array_merge( $out, mcp_bridge_oob_lint_dir( $path, $root, $depth - 1 ) );
			continue;
		}
		if ( str_ends_with( strtolower( $name ), '.php' ) ) {
			$out[] = mcp_bridge_oob_lint_file( $path, $root );
		}
	}
	return $out;
}

/**
 * @return array<int, array<string, mixed>>
 */
function mcp_bridge_oob_lint_tree( string $dir, string $root ): array {
	$out = array();
	$it  = @scandir( $dir );
	if ( ! is_array( $it ) ) {
		return $out;
	}
	foreach ( $it as $name ) {
		if ( '.' === $name || '..' === $name ) {
			continue;
		}
		$path = $dir . '/' . $name;
		if ( is_dir( $path ) ) {
			$out = array_merge( $out, mcp_bridge_oob_lint_tree( $path, $root ) );
			continue;
		}
		if ( str_ends_with( strtolower( $name ), '.php' ) ) {
			$out[] = mcp_bridge_oob_lint_file( $path, $root );
		}
	}
	return $out;
}

/**
 * @return array<string, mixed>
 */
function mcp_bridge_oob_lint_file( string $path, string $root ): array {
	$rel = $path;
	if ( str_starts_with( $path, $root ) ) {
		$rel = ltrim( substr( $path, strlen( $root ) ), '/' );
	}
	$src = (string) file_get_contents( $path );
	$err = mcp_bridge_oob_lint_string( $src );
	return array(
		'path'  => $path,
		'rel'   => $rel,
		'ok'    => null === $err,
		'error' => $err,
	);
}

function mcp_bridge_oob_lint_string( string $content ): ?string {
	try {
		$tokens = token_get_all( $content, TOKEN_PARSE );
		if ( array() === $tokens && '' !== trim( $content ) ) {
			return 'PHP parse produced no tokens.';
		}
	} catch ( ParseError $e ) {
		return $e->getMessage();
	}
	return null;
}

/**
 * @return array{0: 'content'|'abspath', 1: string}
 */
function mcp_bridge_oob_split_scope( string $rel ): array {
	$rel   = str_replace( '\\', '/', $rel );
	$rel   = ltrim( $rel, '/' );
	$parts = array();
	foreach ( explode( '/', $rel ) as $seg ) {
		if ( '' === $seg || '.' === $seg ) {
			continue;
		}
		if ( '..' === $seg ) {
			return array( 'content', '' );
		}
		$parts[] = $seg;
	}
	$rel   = implode( '/', $parts );
	$lower = strtolower( $rel );
	if ( str_starts_with( $lower, 'abspath/' ) ) {
		return array( 'abspath', substr( $rel, 8 ) );
	}
	$root = array( 'wp-config.php', 'wp-config-sample.php', '.htaccess', '.user.ini', 'php.ini', 'index.php', 'wp-load.php', 'wp-settings.php', 'wp-blog-header.php', 'wp-cron.php', 'wp-login.php', 'xmlrpc.php', '.maintenance', 'wordfence-waf.php' );
	if ( in_array( $lower, $root, true ) || str_starts_with( $lower, 'wp-admin/' ) || str_starts_with( $lower, 'wp-includes/' ) ) {
		return array( 'abspath', $rel );
	}
	return array( 'content', $rel );
}

function mcp_bridge_oob_resolve( string $wp_content, string $rel, bool $for_write ): string|false {
	[ $scope, $rel ] = mcp_bridge_oob_split_scope( $rel );
	if ( '' === $rel ) {
		return false;
	}
	$abspath = dirname( $wp_content );
	$base_fs = 'abspath' === $scope ? $abspath : $wp_content;
	$jail    = realpath( $base_fs );
	if ( false === $jail ) {
		return false;
	}
	$target = $base_fs . '/' . $rel;
	if ( is_file( $target ) || is_dir( $target ) ) {
		$real = realpath( $target );
		if ( false === $real || ! str_starts_with( $real, $jail ) ) {
			return false;
		}
		return $real;
	}
	if ( ! $for_write ) {
		return false;
	}
	$parent = realpath( dirname( $target ) );
	if ( false === $parent ) {
		if ( '.' === dirname( $rel ) || '' === dirname( $rel ) ) {
			$parent = $jail;
		} else {
			return false;
		}
	}
	if ( ! str_starts_with( $parent, $jail ) ) {
		return false;
	}
	return $parent . '/' . basename( $target );
}

/**
 * @return array<string, mixed>|null
 */
function mcp_bridge_oob_write_denied( string $rel, bool $confirm ): ?array {
	[ $scope, $inner ] = mcp_bridge_oob_split_scope( $rel );
	$il                = strtolower( $inner );
	if ( 'mu-plugins' === $il || str_starts_with( $il, 'mu-plugins/' ) ) {
		return array(
			'ok'      => false,
			'error'   => 'mu_plugin_denied',
			'message' => 'OOB will not write mu-plugins. A parse error there 500s every request. Delete the broken file or use debug_fatals.',
		);
	}
	$dropins = array(
		'fatal-error-handler.php',
		'object-cache.php',
		'advanced-cache.php',
		'db.php',
		'sunrise.php',
		'mcp-bridge-oob.php',
		'mcp-bridge-oob-lib.php',
	);
	if ( in_array( basename( $il ), $dropins, true ) ) {
		return array(
			'ok'      => false,
			'error'   => 'dropin_denied',
			'message' => 'OOB will not write drop-ins. Same WSOD class as the Hostinger mu-plugin fatal.',
		);
	}
	if ( 'abspath' === $scope && in_array( $il, array( 'wp-config.php', '.htaccess', '.user.ini', 'wp-load.php', 'index.php' ), true ) && ! $confirm ) {
		return array(
			'ok'      => false,
			'error'   => 'confirm_required',
			'message' => 'Writing wp-config / .htaccess / core index needs confirm=true.',
		);
	}
	return null;
}

function mcp_bridge_oob_is_protected( string $rel ): bool {
	$rel   = strtolower( ltrim( str_replace( '\\', '/', $rel ), '/' ) );
	$rel   = str_starts_with( $rel, 'abspath/' ) ? substr( $rel, 8 ) : $rel;
	$rel   = preg_replace( '#^wp-content/#', '', $rel ) ?? $rel;
	$block = array(
		'mcp-bridge-oob.php',
		'mcp-bridge-oob-lib.php',
		'mu-plugins/000-mcp-bridge-fatal.php',
	);
	return in_array( $rel, $block, true ) || str_contains( $rel, 'wp-mcp-bridge' ) || str_contains( $rel, 'overlord-wp-fleet' );
}

function mcp_bridge_oob_backup_file( string $wp_content, string $path, string $rel ): ?string {
	$dir = $wp_content . '/uploads/mcp-bridge/backups';
	if ( ! is_dir( $dir ) ) {
		@mkdir( $dir, 0755, true );
	}
	$name = str_replace( array( '/', '\\' ), '__', $rel ) . '-' . gmdate( 'YmdHis' );
	$dest = $dir . '/' . $name;
	if ( @copy( $path, $dest ) ) {
		return 'uploads/mcp-bridge/backups/' . $name;
	}
	return null;
}

function mcp_bridge_oob_safe_slug( string $slug ): bool {
	return 1 === preg_match( '/^[A-Za-z0-9._-]+$/', $slug ) && ! str_contains( $slug, '..' );
}

function mcp_bridge_oob_pkg_root( string $wp_content, string $kind, string $slug ): string|false {
	if ( ! mcp_bridge_oob_safe_slug( $slug ) ) {
		return false;
	}
	$parent = 'theme' === $kind ? $wp_content . '/themes' : $wp_content . '/plugins';
	$path   = $parent . '/' . $slug;
	return is_dir( $path ) ? $path : false;
}

/**
 * @return list<string>
 */
function mcp_bridge_oob_scandir_names( string $dir ): array {
	if ( ! is_dir( $dir ) ) {
		return array();
	}
	$list = @scandir( $dir );
	if ( ! is_array( $list ) ) {
		return array();
	}
	$out = array();
	foreach ( $list as $name ) {
		if ( '.' === $name || '..' === $name || str_starts_with( $name, '.' ) ) {
			continue;
		}
		if ( is_dir( $dir . '/' . $name ) ) {
			$out[] = $name;
		}
	}
	sort( $out );
	return $out;
}

function mcp_bridge_oob_zip_add( ZipArchive $zip, string $dir, string $prefix ): void {
	$it = @scandir( $dir );
	if ( ! is_array( $it ) ) {
		return;
	}
	foreach ( $it as $name ) {
		if ( '.' === $name || '..' === $name ) {
			continue;
		}
		$path = $dir . '/' . $name;
		$rel  = $prefix . '/' . $name;
		if ( is_dir( $path ) ) {
			$zip->addEmptyDir( $rel );
			mcp_bridge_oob_zip_add( $zip, $path, $rel );
			continue;
		}
		$zip->addFile( $path, $rel );
	}
}

function mcp_bridge_oob_copy_tree( string $src, string $dest ): void {
	@mkdir( $dest, 0755, true );
	$it = @scandir( $src );
	if ( ! is_array( $it ) ) {
		return;
	}
	foreach ( $it as $name ) {
		if ( '.' === $name || '..' === $name ) {
			continue;
		}
		$from = $src . '/' . $name;
		$to   = $dest . '/' . $name;
		if ( is_dir( $from ) ) {
			mcp_bridge_oob_copy_tree( $from, $to );
			continue;
		}
		@copy( $from, $to );
	}
}

function mcp_bridge_oob_rmdir( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$it = @scandir( $dir );
	if ( ! is_array( $it ) ) {
		return;
	}
	foreach ( $it as $name ) {
		if ( '.' === $name || '..' === $name ) {
			continue;
		}
		$path = $dir . '/' . $name;
		if ( is_dir( $path ) ) {
			mcp_bridge_oob_rmdir( $path );
			continue;
		}
		@unlink( $path );
	}
	@rmdir( $dir );
}
