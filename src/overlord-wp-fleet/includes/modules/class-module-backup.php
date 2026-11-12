<?php
/**
 * /ovp/v1/backup/* — RESTORE-GRADE site backup streams (1.7.0).
 *
 * - backup/plan   table list + sizes (for the proxy manifest).
 * - backup/sql    full mysqldump-equivalent, paginated by an opaque cursor. Own mysqli
 *                 connection (never $wpdb->dbh), values escaped with real_escape_string,
 *                 binary columns as 0x hex, NULL kept distinct, explicit column lists,
 *                 generated columns skipped, one INSERT per row, DROP + CREATE TABLE,
 *                 no row skipping: a single row larger than the page budget is split
 *                 across pages by byte offset (the row is regenerated on each page).
 * - backup/files  ustar tar stream of wp-content/{plugins,mu-plugins,themes}, paged by
 *                 (sorted path, byte offset) cursor. No temp files, no PharData.
 *
 * Every response stays under max_bytes raw (default 3MB, max 5MB; base64 + JSON < 8MB,
 * the proxy readCappedBody ceiling). The dump is NOT point-in-time consistent across
 * HTTP requests: writes that land between pages can appear or be missed. Quiesce the
 * site (maintenance window) when an exact snapshot matters, then compare CHECKSUM TABLE.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ovp_Module_Backup extends Ovp_Module_Base {

	public const BANNER = 'RESTORE-GRADE: all tables of the site DB, DROP+CREATE+one INSERT per row, binary-safe, no row skipping, wp-config/core not included. Not point-in-time consistent across HTTP pages.';

	private const DEFAULT_PAGE = 3000000;
	private const MAX_PAGE     = 5000000;
	private const MIN_PAGE     = 100000;
	private const TIME_BUDGET  = 20.0;
	private const FILE_TARGETS = array( 'plugins', 'mu-plugins', 'themes' );

	public function register_routes(): void {
		$this->route( 'GET', 'backup/plan', array( $this, 'plan' ) );
		$this->route( 'POST', 'backup/sql', array( $this, 'sql' ) );
		$this->route( 'POST', 'backup/files', array( $this, 'files' ) );
	}

	/**
	 * @param WP_REST_Request<array<string, mixed>> $request
	 */
	public function plan( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		unset( $request );
		$db = $this->connect();
		if ( $db instanceof WP_Error ) {
			return $db;
		}
		$tables = array();
		$res    = $db->query( 'SHOW TABLE STATUS' );
		if ( $res instanceof mysqli_result ) {
			while ( $row = $res->fetch_assoc() ) {
				if ( null !== ( $row['Engine'] ?? null ) ) {
					$tables[] = array(
						'name'   => (string) $row['Name'],
						'engine' => (string) $row['Engine'],
						'rows'   => (int) ( $row['Rows'] ?? 0 ),
						'bytes'  => (int) ( $row['Data_length'] ?? 0 ) + (int) ( $row['Index_length'] ?? 0 ),
					);
				}
			}
			$res->free();
		}
		$ver = $db->server_info;
		$db->close();
		global $wpdb;
		return $this->success(
			array(
				'banner'     => self::BANNER,
				'db_name'    => (string) $wpdb->dbname,
				'prefix'     => (string) $wpdb->prefix,
				'db_server'  => $ver,
				'wp_version' => get_bloginfo( 'version' ),
				'home'       => home_url(),
				'tables'     => $tables,
				'file_dirs'  => self::FILE_TARGETS,
			)
		);
	}

	// -- SQL ----------------------------------------------------------------

	/**
	 * Body: cursor, max_bytes, tables[] (only these), exclude_tables[], checksums (bool).
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request
	 */
	public function sql( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}
		$body   = (array) $request->get_json_params();
		$budget = $this->page_budget( $body['max_bytes'] ?? 0 );
		$cursor = $this->decode_cursor( (string) ( $body['cursor'] ?? '' ) );
		if ( null === $cursor ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Bad cursor.', 400 );
		}
		$only    = $this->string_list( $body['tables'] ?? null );
		$exclude = $this->string_list( $body['exclude_tables'] ?? null );
		$db      = $this->connect();
		if ( $db instanceof WP_Error ) {
			return $db;
		}
		try {
			$page = $this->dump_page( $db, $cursor, $budget, $only, $exclude, ! empty( $body['checksums'] ) );
		} catch ( \Throwable $e ) {
			$db->close();
			return $this->error( 'mcp_bridge_backup_failed', $e->getMessage(), 500 );
		}
		$db->close();
		return $this->success( $page );
	}

	private function page_budget( mixed $raw ): int {
		$n = (int) $raw;
		if ( $n <= 0 ) {
			return self::DEFAULT_PAGE;
		}
		return min( self::MAX_PAGE, max( self::MIN_PAGE, $n ) );
	}

	/**
	 * @return array<int, string>
	 */
	private function string_list( mixed $raw ): array {
		$out = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $item ) {
				if ( is_string( $item ) && '' !== $item ) {
					$out[] = $item;
				}
			}
		}
		return $out;
	}

	/**
	 * Dedicated connection: never the shared $wpdb handle (its charset, sql_mode and
	 * time_zone must not be touched).
	 */
	private function connect(): mysqli|WP_Error {
		global $wpdb;
		if ( ! class_exists( 'mysqli' ) ) {
			return $this->error( 'mcp_bridge_backup_failed', 'mysqli is not available.', 500 );
		}
		$parsed = $wpdb->parse_db_host( (string) $wpdb->dbhost );
		if ( ! is_array( $parsed ) ) {
			return $this->error( 'mcp_bridge_backup_failed', 'Cannot parse DB_HOST.', 500 );
		}
		list( $host, $port, $socket ) = $parsed;
		mysqli_report( MYSQLI_REPORT_OFF );
		$db = mysqli_init();
		if ( ! $db instanceof mysqli ) {
			return $this->error( 'mcp_bridge_backup_failed', 'mysqli_init failed.', 500 );
		}
		$ok = @$db->real_connect(
			(string) $host,
			(string) $wpdb->dbuser,
			(string) $this->wpdb_password(),
			(string) $wpdb->dbname,
			is_numeric( $port ) ? (int) $port : 3306,
			is_string( $socket ) && '' !== $socket ? $socket : null
		);
		if ( ! $ok ) {
			return $this->error( 'mcp_bridge_backup_failed', 'DB connect failed: ' . $db->connect_error, 500 );
		}
		$charset = defined( 'DB_CHARSET' ) && '' !== DB_CHARSET ? (string) DB_CHARSET : 'utf8mb4';
		$db->set_charset( $charset );
		$db->query( "SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'" );
		$db->query( "SET SESSION time_zone='+00:00'" );
		return $db;
	}

	private function wpdb_password(): string {
		return defined( 'DB_PASSWORD' ) ? (string) constant( 'DB_PASSWORD' ) : '';
	}

	/**
	 * @return array{t:string,r:int,k:array<int,string>,o:int,n:int,ro:int}|null
	 */
	private function decode_cursor( string $raw ): ?array {
		$blank = array( 't' => '', 'r' => 0, 'k' => array(), 'o' => 0, 'n' => 0, 'ro' => 0 );
		if ( '' === $raw ) {
			return $blank;
		}
		$json = base64_decode( strtr( $raw, '-_', '+/' ), true );
		$data = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $data ) || ! isset( $data['t'] ) || ! is_string( $data['t'] ) ) {
			return null;
		}
		$keys = array();
		foreach ( is_array( $data['k'] ?? null ) ? $data['k'] : array() as $k ) {
			$dec = is_string( $k ) ? base64_decode( $k, true ) : false;
			if ( false === $dec ) {
				return null;
			}
			$keys[] = $dec;
		}
		return array(
			't'  => $data['t'],
			'r'  => (int) ( $data['r'] ?? 0 ),
			'k'  => $keys,
			'o'  => max( 0, (int) ( $data['o'] ?? 0 ) ),
			'n'  => max( 0, (int) ( $data['n'] ?? 0 ) ),
			'ro' => max( 0, (int) ( $data['ro'] ?? 0 ) ),
		);
	}

	/**
	 * @param array{t:string,r:int,k:array<int,string>,o:int,n:int,ro:int} $c
	 */
	private function encode_cursor( array $c ): string {
		$keys = array();
		foreach ( $c['k'] as $k ) {
			$keys[] = base64_encode( $k );
		}
		$c['k'] = $keys;
		return rtrim( strtr( base64_encode( (string) wp_json_encode( $c ) ), '+/', '-_' ), '=' );
	}

	/**
	 * @param array{t:string,r:int,k:array<int,string>,o:int,n:int,ro:int} $cur
	 * @param array<int, string> $only
	 * @param array<int, string> $exclude
	 * @return array<string, mixed>
	 */
	private function dump_page( mysqli $db, array $cur, int $budget, array $only, array $exclude, bool $checksums ): array {
		$deadline = microtime( true ) + self::TIME_BUDGET;
		$tables   = $this->table_names( $db, $only, $exclude );
		$out      = '';
		$segments = array();
		$done_t   = array();

		$emit = static function ( string $table, string $bytes ) use ( &$out, &$segments ): void {
			if ( '' === $bytes ) {
				return;
			}
			$out .= $bytes;
			$last = count( $segments ) - 1;
			if ( $last >= 0 && $segments[ $last ]['table'] === $table ) {
				$segments[ $last ]['bytes'] += strlen( $bytes );
			} else {
				$segments[] = array( 'table' => $table, 'bytes' => strlen( $bytes ) );
			}
		};

		if ( '' === $cur['t'] ) {
			$emit(
				'',
				"-- " . self::BANNER . "\n-- Generated by Overlord WP Fleet " . OVP_FLEET_VERSION . ' on ' . gmdate( 'c' ) . "\n"
				. "/*!40101 SET NAMES " . $this->dump_charset() . " */;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\n"
				. "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET time_zone='+00:00';\n\n"
			);
			$first = $tables[0] ?? '';
			if ( '' === $first ) {
				$emit( '', "SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n-- end of dump (no tables)\n" );
				return $this->page_result( $out, $segments, $done_t, '', true );
			}
			$cur = array( 't' => $first, 'r' => 0, 'k' => array(), 'o' => 0, 'n' => 0, 'ro' => 0 );
		}

		$finished = false;
		while ( ! $finished ) {
			$table = $cur['t'];
			if ( ! in_array( $table, $tables, true ) ) {
				// Table vanished between pages: continue with the next name after it.
				$next = $this->next_table( $tables, $table );
				if ( '' === $next ) {
					$finished = true;
					break;
				}
				$cur = array( 't' => $next, 'r' => 0, 'k' => array(), 'o' => 0, 'n' => 0, 'ro' => 0 );
				continue;
			}
			$meta = $this->table_meta( $db, $table );

			if ( 0 === $cur['r'] ) {
				$create = $this->show_create( $db, $table );
				$emit(
					$table,
					"--\n-- Table structure for `{$table}`\n--\nDROP TABLE IF EXISTS `{$table}`;\n" . $create . ";\n\n"
				);
				$cur['r'] = 1;
			}

			while ( true ) {
				if ( strlen( $out ) >= $budget || microtime( true ) > $deadline ) {
					break 2;
				}
				$rows = $this->fetch_batch( $db, $table, $meta, $cur );
				if ( array() === $rows ) {
					break;
				}
				foreach ( $rows as $row ) {
					$stmt      = $this->insert_statement( $db, $table, $meta, $row );
					$remaining = strlen( $stmt ) - $cur['ro'];
					$room      = $budget - strlen( $out );
					if ( $remaining <= $room ) {
						$emit( $table, substr( $stmt, $cur['ro'] ) );
						$cur['ro'] = 0;
						$this->advance( $cur, $meta, $row );
					} elseif ( '' === $out ) {
						$emit( $table, substr( $stmt, $cur['ro'], $room ) );
						$cur['ro'] += $room;
						break 3;
					} else {
						break 3;
					}
					if ( strlen( $out ) >= $budget || microtime( true ) > $deadline ) {
						break 3;
					}
				}
			}

			$entry = array( 'table' => $table, 'rows' => $cur['n'] );
			if ( $checksums ) {
				$entry['checksum_table'] = $this->checksum_table( $db, $table );
			}
			$done_t[] = $entry;
			$emit( $table, "\n-- END TABLE `{$table}` rows={$cur['n']}\n\n" );
			$next = $this->next_table( $tables, $table );
			if ( '' === $next ) {
				$emit( '', "SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n-- end of dump\n" );
				$finished = true;
			} else {
				$cur = array( 't' => $next, 'r' => 0, 'k' => array(), 'o' => 0, 'n' => 0, 'ro' => 0 );
			}
		}

		return $this->page_result( $out, $segments, $done_t, $finished ? '' : $this->encode_cursor( $cur ), $finished );
	}

	private function dump_charset(): string {
		$charset = defined( 'DB_CHARSET' ) && '' !== DB_CHARSET ? (string) DB_CHARSET : 'utf8mb4';
		return preg_replace( '/[^a-z0-9_]/i', '', $charset ) ?: 'utf8mb4';
	}

	/**
	 * @param array<int, array{table:string,bytes:int}> $segments
	 * @param array<int, array<string, mixed>>          $done
	 * @return array<string, mixed>
	 */
	private function page_result( string $out, array $segments, array $done, string $next, bool $finished ): array {
		return array(
			'banner'      => self::BANNER,
			'encoding'    => 'base64',
			'content'     => base64_encode( $out ),
			'bytes'       => strlen( $out ),
			'sha256'      => hash( 'sha256', $out ),
			'segments'    => $segments,
			'tables_done' => $done,
			'next_cursor' => $finished ? '' : $next,
			'done'        => $finished,
		);
	}

	/**
	 * @param array<int, string> $only
	 * @param array<int, string> $exclude
	 * @return array<int, string>
	 */
	private function table_names( mysqli $db, array $only, array $exclude ): array {
		$names = array();
		$res   = $db->query( "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'" );
		if ( $res instanceof mysqli_result ) {
			while ( $row = $res->fetch_row() ) {
				$name = (string) $row[0];
				if ( array() !== $only && ! in_array( $name, $only, true ) ) {
					continue;
				}
				if ( in_array( $name, $exclude, true ) ) {
					continue;
				}
				$names[] = $name;
			}
			$res->free();
		}
		sort( $names, SORT_STRING );
		return $names;
	}

	/**
	 * @param array<int, string> $tables
	 */
	private function next_table( array $tables, string $current ): string {
		foreach ( $tables as $name ) {
			if ( strcmp( $name, $current ) > 0 ) {
				return $name;
			}
		}
		return '';
	}

	private function quote_ident( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	private function show_create( mysqli $db, string $table ): string {
		$res = $db->query( 'SHOW CREATE TABLE ' . $this->quote_ident( $table ) );
		if ( ! $res instanceof mysqli_result ) {
			throw new \RuntimeException( 'SHOW CREATE TABLE failed for ' . $table . ': ' . $db->error );
		}
		$row = $res->fetch_row();
		$res->free();
		return is_array( $row ) ? (string) ( $row[1] ?? '' ) : '';
	}

	private function checksum_table( mysqli $db, string $table ): ?string {
		$res = $db->query( 'CHECKSUM TABLE ' . $this->quote_ident( $table ) );
		if ( ! $res instanceof mysqli_result ) {
			return null;
		}
		$row = $res->fetch_row();
		$res->free();
		return is_array( $row ) && null !== ( $row[1] ?? null ) ? (string) $row[1] : null;
	}

	/**
	 * @return array{cols: array<int, array{name:string,kind:string,bit:bool}>, pk: array<int, string>, kinds: array<string,string>, heavy: bool}
	 */
	private function table_meta( mysqli $db, string $table ): array {
		static $cache = array();
		if ( isset( $cache[ $table ] ) ) {
			return $cache[ $table ];
		}
		$cols  = array();
		$kinds = array();
		$heavy = false;
		$res   = $db->query( 'SHOW FULL COLUMNS FROM ' . $this->quote_ident( $table ) );
		if ( ! $res instanceof mysqli_result ) {
			throw new \RuntimeException( 'SHOW COLUMNS failed for ' . $table );
		}
		while ( $row = $res->fetch_assoc() ) {
			$extra = (string) ( $row['Extra'] ?? '' );
			if ( preg_match( '/\b(VIRTUAL|STORED|PERSISTENT)\b/i', $extra ) ) {
				continue;
			}
			$type = strtolower( (string) $row['Type'] );
			$kind = 'string';
			$bit  = (bool) preg_match( '/^bit\b/', $type );
			if ( $bit ) {
				$kind = 'num'; // fetched as BIT_COLUMN+0 so the driver returns the integer, not bytes.
			} elseif ( preg_match( '/^(tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|float|double|real|year)\b/', $type ) ) {
				$kind = 'num';
			} elseif ( preg_match( '/^(tinyblob|blob|mediumblob|longblob|binary|varbinary|geometry|point|linestring|polygon|multipoint|multilinestring|multipolygon|geometrycollection)\b/', $type ) ) {
				$kind = 'bin';
			}
			if ( preg_match( '/^(medium|long)(text|blob)|^json/', $type ) ) {
				$heavy = true;
			}
			$name           = (string) $row['Field'];
			$cols[]         = array( 'name' => $name, 'kind' => $kind, 'bit' => $bit );
			$kinds[ $name ] = $kind;
		}
		$res->free();

		$pk  = array();
		$res = $db->query( 'SHOW KEYS FROM ' . $this->quote_ident( $table ) . " WHERE Key_name = 'PRIMARY'" );
		if ( $res instanceof mysqli_result ) {
			$seq = array();
			while ( $row = $res->fetch_assoc() ) {
				$seq[ (int) $row['Seq_in_index'] ] = (string) $row['Column_name'];
			}
			$res->free();
			ksort( $seq );
			$pk = array_values( $seq );
			foreach ( $pk as $p ) {
				if ( ! isset( $kinds[ $p ] ) ) {
					$pk = array(); // PK on a skipped column: fall back to offset paging.
					break;
				}
			}
		}
		$cache[ $table ] = array( 'cols' => $cols, 'pk' => $pk, 'kinds' => $kinds, 'heavy' => $heavy );
		return $cache[ $table ];
	}

	/**
	 * SQL literal for one raw string value.
	 */
	private function literal( mysqli $db, string $kind, ?string $value ): string {
		if ( null === $value ) {
			return 'NULL';
		}
		if ( 'bin' === $kind ) {
			return '' === $value ? "''" : '0x' . bin2hex( $value );
		}
		if ( 'num' === $kind && 1 === preg_match( '/^-?\d+(\.\d+)?([eE][-+]?\d+)?$/', $value ) ) {
			return $value;
		}
		return "'" . $db->real_escape_string( $value ) . "'";
	}

	/**
	 * @param array{cols: array<int, array{name:string,kind:string,bit:bool}>, pk: array<int, string>, kinds: array<string,string>, heavy: bool} $meta
	 * @param array{t:string,r:int,k:array<int,string>,o:int,n:int,ro:int} $cur
	 * @return array<int, array<string, string|null>>
	 */
	private function fetch_batch( mysqli $db, string $table, array $meta, array $cur ): array {
		$names = array();
		foreach ( $meta['cols'] as $col ) {
			$q       = $this->quote_ident( $col['name'] );
			$names[] = $col['bit'] ? $q . '+0 AS ' . $q : $q;
		}
		$limit = $meta['heavy'] ? 20 : 200;
		$sql   = 'SELECT ' . implode( ',', $names ) . ' FROM ' . $this->quote_ident( $table );
		global $wpdb;
		$conds = array();
		if ( $table === $wpdb->options && in_array( 'option_name', array_column( $meta['cols'], 'name' ), true ) ) {
			// Never stream this plugin's key store, connection or tier options (a restore must not clone keys).
			$conds[] = "option_name NOT LIKE 'ovp\\_%' AND option_name NOT LIKE '\\_ovp\\_%' AND option_name NOT LIKE 'mcp\\_bridge\\_%'";
		}
		if ( array() !== $meta['pk'] ) {
			$pk_cols = array();
			foreach ( $meta['pk'] as $p ) {
				$pk_cols[] = $this->quote_ident( $p );
			}
			if ( array() !== $cur['k'] && count( $cur['k'] ) === count( $meta['pk'] ) ) {
				$vals = array();
				foreach ( $meta['pk'] as $i => $p ) {
					$vals[] = $this->literal( $db, $meta['kinds'][ $p ], $cur['k'][ $i ] );
				}
				$conds[] = '(' . implode( ',', $pk_cols ) . ') > (' . implode( ',', $vals ) . ')';
			}
			if ( array() !== $conds ) {
				$sql .= ' WHERE ' . implode( ' AND ', $conds );
			}
			$sql .= ' ORDER BY ' . implode( ',', $pk_cols ) . ' LIMIT ' . $limit;
		} else {
			if ( array() !== $conds ) {
				$sql .= ' WHERE ' . implode( ' AND ', $conds );
			}
			$sql .= ' LIMIT ' . $limit . ' OFFSET ' . (int) $cur['o'];
		}
		$res = $db->query( $sql );
		if ( ! $res instanceof mysqli_result ) {
			throw new \RuntimeException( 'Row fetch failed for ' . $table . ': ' . $db->error );
		}
		$rows = array();
		while ( $row = $res->fetch_assoc() ) {
			$rows[] = $row;
		}
		$res->free();
		return $rows;
	}

	/**
	 * @param array{cols: array<int, array{name:string,kind:string,bit:bool}>, pk: array<int, string>, kinds: array<string,string>, heavy: bool} $meta
	 * @param array<string, string|null> $row
	 */
	private function insert_statement( mysqli $db, string $table, array $meta, array $row ): string {
		$names = array();
		$vals  = array();
		foreach ( $meta['cols'] as $col ) {
			$names[] = $this->quote_ident( $col['name'] );
			$vals[]  = $this->literal( $db, $col['kind'], $row[ $col['name'] ] ?? null );
		}
		return 'INSERT INTO ' . $this->quote_ident( $table ) . ' (' . implode( ',', $names ) . ') VALUES (' . implode( ',', $vals ) . ");\n";
	}

	/**
	 * @param array{t:string,r:int,k:array<int,string>,o:int,n:int,ro:int} $cur
	 * @param array{cols: array<int, array{name:string,kind:string,bit:bool}>, pk: array<int, string>, kinds: array<string,string>, heavy: bool} $meta
	 * @param array<string, string|null> $row
	 */
	private function advance( array &$cur, array $meta, array $row ): void {
		++$cur['n'];
		++$cur['o'];
		if ( array() !== $meta['pk'] ) {
			$keys = array();
			foreach ( $meta['pk'] as $p ) {
				$keys[] = (string) ( $row[ $p ] ?? '' );
			}
			$cur['k'] = $keys;
		}
	}

	// -- Files (ustar tar stream) ------------------------------------------

	/**
	 * Body: cursor, max_bytes, targets[] (subset of plugins, mu-plugins, themes).
	 * Cursor = base64url(json {p: last path, o: byte offset inside that entry, d: 1 when the
	 * end-of-archive blocks were already sent}). Tar entry paths are "wp-content/<dir>/...".
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request
	 */
	public function files( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}
		$body   = (array) $request->get_json_params();
		$budget = $this->page_budget( $body['max_bytes'] ?? 0 );
		$want   = array_values( array_intersect( $this->string_list( $body['targets'] ?? null ), self::FILE_TARGETS ) );
		if ( array() === $want ) {
			$want = self::FILE_TARGETS;
		}
		$cur_p = '';
		$cur_o = 0;
		$raw   = (string) ( $body['cursor'] ?? '' );
		if ( '' !== $raw ) {
			$json = base64_decode( strtr( $raw, '-_', '+/' ), true );
			$data = false === $json ? null : json_decode( $json, true );
			if ( ! is_array( $data ) || ! isset( $data['p'] ) || ! is_string( $data['p'] ) ) {
				return $this->error( 'mcp_bridge_invalid_payload', 'Bad cursor.', 400 );
			}
			$cur_p = $data['p'];
			$cur_o = max( 0, (int) ( $data['o'] ?? 0 ) );
		}

		$list = $this->list_tree( $want );
		$out  = '';
		$idx  = 0;
		$n    = count( $list );
		if ( '' !== $cur_p ) {
			while ( $idx < $n && strcmp( $list[ $idx ]['rel'], $cur_p ) < 0 ) {
				++$idx;
			}
		}
		$deadline = microtime( true ) + self::TIME_BUDGET;
		$next     = '';
		for ( ; $idx < $n; $idx++ ) {
			$entry  = $list[ $idx ];
			$offset = ( '' !== $cur_p && $entry['rel'] === $cur_p ) ? $cur_o : 0;
			$head   = $this->tar_header( $entry );
			$size   = $entry['size'];
			$total  = strlen( $head ) + $size + ( ( 512 - ( $size % 512 ) ) % 512 );
			$room   = $budget - strlen( $out );
			if ( $room <= 0 || microtime( true ) > $deadline ) {
				$next = $this->tar_cursor( $entry['rel'], $offset );
				break;
			}
			$want_bytes = min( $room, $total - $offset );
			$out       .= $this->tar_slice( $entry, $head, $offset, $want_bytes );
			if ( $offset + $want_bytes < $total ) {
				$next = $this->tar_cursor( $entry['rel'], $offset + $want_bytes );
				break;
			}
		}
		$finished = '' === $next;
		if ( $finished ) {
			$out .= str_repeat( "\0", 1024 );
		}
		return $this->success(
			array(
				'banner'      => self::BANNER,
				'format'      => 'tar',
				'encoding'    => 'base64',
				'content'     => base64_encode( $out ),
				'bytes'       => strlen( $out ),
				'sha256'      => hash( 'sha256', $out ),
				'files_total' => $n,
				'next_cursor' => $next,
				'done'        => $finished,
			)
		);
	}

	private function tar_cursor( string $rel, int $offset ): string {
		return rtrim( strtr( base64_encode( (string) wp_json_encode( array( 'p' => $rel, 'o' => $offset ) ) ), '+/', '-_' ), '=' );
	}

	/**
	 * @param array<int, string> $targets
	 * @return array<int, array{rel:string,abs:string,size:int,mtime:int,mode:int,dir:bool}>
	 */
	private function list_tree( array $targets ): array {
		$out  = array();
		$base = rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' );
		foreach ( $targets as $t ) {
			$root = $base . '/' . $t;
			if ( ! is_dir( $root ) || is_link( $root ) ) {
				continue;
			}
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);
			foreach ( $it as $info ) {
				if ( ! $info instanceof SplFileInfo || $info->isLink() ) {
					continue;
				}
				$abs = str_replace( '\\', '/', $info->getPathname() );
				$rel = 'wp-content/' . $t . substr( $abs, strlen( $root ) );
				if ( $info->isDir() ) {
					$out[] = array( 'rel' => $rel . '/', 'abs' => $abs, 'size' => 0, 'mtime' => (int) $info->getMTime(), 'mode' => 0755, 'dir' => true );
				} elseif ( $info->isFile() ) {
					$out[] = array( 'rel' => $rel, 'abs' => $abs, 'size' => (int) $info->getSize(), 'mtime' => (int) $info->getMTime(), 'mode' => 0644, 'dir' => false );
				}
			}
		}
		usort(
			$out,
			static function ( array $a, array $b ): int {
				return strcmp( $a['rel'], $b['rel'] );
			}
		);
		return $out;
	}

	/**
	 * ustar header (+ GNU ././@LongLink entry when the path is over 100 bytes).
	 *
	 * @param array{rel:string,abs:string,size:int,mtime:int,mode:int,dir:bool} $e
	 */
	private function tar_header( array $e ): string {
		$name = $e['rel'];
		$pre  = '';
		if ( strlen( $name ) > 100 ) {
			$pre = $this->tar_block( '././@LongLink', strlen( $name ) + 1, 0, 0644, 'L' ) . str_pad( $name . "\0", (int) ( ceil( ( strlen( $name ) + 1 ) / 512 ) * 512 ), "\0" );
		}
		return $pre . $this->tar_block( $name, $e['size'], $e['mtime'], $e['mode'], $e['dir'] ? '5' : '0' );
	}

	private function tar_block( string $name, int $size, int $mtime, int $mode, string $type ): string {
		$name100 = substr( $name, 0, 100 );
		$h       = str_pad( $name100, 100, "\0" )
			. sprintf( '%07o', $mode ) . "\0"
			. sprintf( '%07o', 0 ) . "\0"
			. sprintf( '%07o', 0 ) . "\0"
			. sprintf( '%011o', $size ) . "\0"
			. sprintf( '%011o', $mtime ) . "\0"
			. '        '
			. $type
			. str_repeat( "\0", 100 )
			. "ustar\0" . '00'
			. str_pad( '', 32, "\0" ) . str_pad( '', 32, "\0" )
			. str_repeat( "\0", 8 ) . str_repeat( "\0", 8 )
			. str_repeat( "\0", 155 );
		$h   = str_pad( $h, 512, "\0" );
		$sum = 0;
		for ( $i = 0; $i < 512; $i++ ) {
			$sum += ord( $h[ $i ] );
		}
		return substr( $h, 0, 148 ) . sprintf( '%06o', $sum ) . "\0 " . substr( $h, 156 );
	}

	/**
	 * Bytes [offset, offset+len) of the entry stream = header + data + padding.
	 *
	 * @param array{rel:string,abs:string,size:int,mtime:int,mode:int,dir:bool} $e
	 */
	private function tar_slice( array $e, string $head, int $offset, int $len ): string {
		$out  = '';
		$hlen = strlen( $head );
		if ( $offset < $hlen ) {
			$take    = min( $len, $hlen - $offset );
			$out    .= substr( $head, $offset, $take );
			$offset += $take;
			$len    -= $take;
		}
		if ( $len <= 0 ) {
			return $out;
		}
		$data_off = $offset - $hlen;
		$size     = $e['size'];
		if ( $data_off < $size && ! $e['dir'] ) {
			$take = min( $len, $size - $data_off );
			$buf  = '';
			$fh   = @fopen( $e['abs'], 'rb' );
			if ( false !== $fh ) {
				fseek( $fh, $data_off );
				$read = fread( $fh, $take );
				fclose( $fh );
				$buf = is_string( $read ) ? $read : '';
			}
			if ( strlen( $buf ) < $take ) {
				$buf .= str_repeat( "\0", $take - strlen( $buf ) ); // file shrank mid-backup: keep tar valid.
			}
			$out    .= $buf;
			$len    -= $take;
			$offset += $take;
		}
		if ( $len > 0 ) {
			$out .= str_repeat( "\0", $len ); // padding to the 512 boundary.
		}
		return $out;
	}
}
