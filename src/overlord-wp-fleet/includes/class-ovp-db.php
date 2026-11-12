<?php
/**
 * Database access layer (design section 7). Parameterized access only:
 *  - db_select (T0): read-only, single SELECT, validated with a real SQL tokenizer (no
 *    comments, no string literals, no ;-stacking, no INTO/FOR/LOCK, no schema qualifiers,
 *    no SLEEP/BENCHMARK/LOAD_FILE...), {prefix} tables only, sensitive tables blocked,
 *    row cap 5000, 15s timeout, runs inside START TRANSACTION READ ONLY.
 *  - db_write (T2): structured insert/update/delete on allowlisted tables, row capped,
 *    with a rollbackable snapshot.
 *  - db_exec (T3): tokenized multi-statement DML/DDL (replaces naive explode(';')).
 *  - search_replace (T2): serialization-safe (never instantiates objects), JSON-aware,
 *    batched with a cursor, dry-run by default, snapshot before apply.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface OVP_Db_Backend {

	public function prefix(): string;

	/** @return array<int, string> all tables of this blog (prefixed). */
	public function tables(): array;

	/** @return array<int, array{name:string,type:string}> */
	public function columns( string $table ): array;

	/** Single-column integer primary key name, or null. */
	public function primary_key( string $table ): ?string;

	/** Substitute %s/%d/%f safely. */
	public function prepare( string $sql, array $params ): string;

	/** @return array<int, array<string, mixed>>|null null on error */
	public function select( string $sql ): ?array;

	/** @return int|false rows affected */
	public function exec( string $sql ): int|false;

	public function begin( bool $read_only ): void;

	public function commit(): void;

	public function rollback(): void;

	public function last_error(): string;

	/**
	 * @param array<int, string> $cols
	 * @return array<int, array<string, mixed>>
	 */
	public function fetch_page( string $table, string $pk, array $cols, int $after, int $limit ): array;

	public function update_cell( string $table, string $pk, int $pk_val, string $col, string $value ): bool;

	/** @param array<string, mixed> $data @return int|false insert id (0 if none) */
	public function insert_row( string $table, array $data ): int|false;

	/** @param array<string, mixed> $data @param array<string, mixed> $where */
	public function update_rows( string $table, array $data, array $where ): int|false;

	/** @param array<string, mixed> $where */
	public function delete_rows( string $table, array $where ): int|false;

	/** @param array<string, mixed> $where */
	public function count_where( string $table, array $where ): int;

	/**
	 * @param array<string, mixed> $where
	 * @return array<int, array<string, mixed>>
	 */
	public function select_where( string $table, array $where, int $limit ): array;
}

final class OVP_Db_Backend_WP implements OVP_Db_Backend {

	public function prefix(): string {
		global $wpdb;
		return (string) $wpdb->prefix;
	}

	public function tables(): array {
		global $wpdb;
		return array_map( 'strval', (array) $wpdb->get_col( 'SHOW TABLES' ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- static.
	}

	private function known( string $table ): bool {
		return in_array( $table, $this->tables(), true );
	}

	public function columns( string $table ): array {
		global $wpdb;
		if ( ! $this->known( $table ) ) {
			return array();
		}
		$rows = $wpdb->get_results( 'DESCRIBE `' . str_replace( '`', '', $table ) . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- name verified against SHOW TABLES.
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array( 'name' => (string) $r['Field'], 'type' => strtolower( (string) $r['Type'] ) );
		}
		return $out;
	}

	public function primary_key( string $table ): ?string {
		global $wpdb;
		if ( ! $this->known( $table ) ) {
			return null;
		}
		$keys = $wpdb->get_results( 'SHOW KEYS FROM `' . str_replace( '`', '', $table ) . "` WHERE Key_name = 'PRIMARY'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! is_array( $keys ) || 1 !== count( $keys ) ) {
			return null;
		}
		$col = (string) $keys[0]['Column_name'];
		foreach ( $this->columns( $table ) as $c ) {
			if ( $c['name'] === $col && 1 === preg_match( '/int/', $c['type'] ) ) {
				return $col;
			}
		}
		return null;
	}

	public function prepare( string $sql, array $params ): string {
		global $wpdb;
		return array() === $params ? $sql : (string) $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public function select( string $sql ): ?array {
		global $wpdb;
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- tokenizer-validated, prepared.
		return is_array( $rows ) ? $rows : null;
	}

	public function exec( string $sql ): int|false {
		global $wpdb;
		$r = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL -- tokenizer-validated, prepared.
		// wpdb::query() returns int rows for DML, true for DDL (CREATE/DROP/ALTER) and false/null on failure.
		// Declared int|false: DDL succeeded with 0 rows affected.
		if ( false === $r || null === $r ) {
			return false;
		}
		return is_int( $r ) ? $r : 0;
	}

	public function begin( bool $read_only ): void {
		global $wpdb;
		$wpdb->query( $read_only ? 'START TRANSACTION READ ONLY' : 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $read_only ) {
			$wpdb->query( 'SET SESSION max_execution_time = 15000' ); // phpcs:ignore WordPress.DB.PreparedSQL
			$wpdb->query( 'SET SESSION max_statement_time = 15' ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
	}

	public function commit(): void {
		global $wpdb;
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public function rollback(): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public function last_error(): string {
		global $wpdb;
		return (string) $wpdb->last_error;
	}

	public function fetch_page( string $table, string $pk, array $cols, int $after, int $limit ): array {
		global $wpdb;
		$known = array_column( $this->columns( $table ), 'name' );
		$use   = array_values( array_intersect( array_merge( array( $pk ), $cols ), $known ) );
		if ( array() === $use || ! in_array( $pk, $known, true ) ) {
			return array();
		}
		$list = implode( ',', array_map( static fn( string $c ): string => '`' . str_replace( '`', '', $c ) . '`', $use ) );
		$t    = '`' . str_replace( '`', '', $table ) . '`';
		$p    = '`' . str_replace( '`', '', $pk ) . '`';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$list} FROM {$t} WHERE {$p} > %d ORDER BY {$p} ASC LIMIT %d", $after, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- identifiers verified against DESCRIBE.
		return is_array( $rows ) ? $rows : array();
	}

	public function update_cell( string $table, string $pk, int $pk_val, string $col, string $value ): bool {
		global $wpdb;
		return false !== $wpdb->update( $table, array( $col => $value ), array( $pk => $pk_val ), array( '%s' ), array( '%d' ) );
	}

	/** @param array<string, mixed> $data */
	private function formats( array $data ): array {
		return array_map( static fn( $v ): string => is_int( $v ) ? '%d' : ( is_float( $v ) ? '%f' : '%s' ), array_values( $data ) );
	}

	public function insert_row( string $table, array $data ): int|false {
		global $wpdb;
		$ok = $wpdb->insert( $table, $data, $this->formats( $data ) );
		return false === $ok ? false : (int) $wpdb->insert_id;
	}

	public function update_rows( string $table, array $data, array $where ): int|false {
		global $wpdb;
		return $wpdb->update( $table, $data, $where, $this->formats( $data ), $this->formats( $where ) );
	}

	public function delete_rows( string $table, array $where ): int|false {
		global $wpdb;
		return $wpdb->delete( $table, $where, $this->formats( $where ) );
	}

	public function count_where( string $table, array $where ): int {
		global $wpdb;
		[ $cond, $vals ] = $this->where_sql( $where );
		$t               = '`' . str_replace( '`', '', $table ) . '`';
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE {$cond}", $vals ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public function select_where( string $table, array $where, int $limit ): array {
		global $wpdb;
		[ $cond, $vals ] = $this->where_sql( $where );
		$t               = '`' . str_replace( '`', '', $table ) . '`';
		$vals[]          = $limit;
		$rows            = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$cond} LIMIT %d", $vals ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return is_array( $rows ) ? $rows : array();
	}

	/** @param array<string, mixed> $where @return array{0:string,1:array<int,mixed>} */
	private function where_sql( array $where ): array {
		$parts = array();
		$vals  = array();
		foreach ( $where as $c => $v ) {
			$parts[] = '`' . str_replace( '`', '', (string) $c ) . '` = ' . ( is_int( $v ) ? '%d' : ( is_float( $v ) ? '%f' : '%s' ) );
			$vals[]  = $v;
		}
		return array( implode( ' AND ', $parts ), $vals );
	}
}

final class OVP_Db {

	public const OVP_TIER = array(
		'db_tables'         => 'T0',
		'db_describe'       => 'T0',
		'db_select'         => 'T0',
		'db_write'          => 'T2',
		'db_search_replace' => 'T2',
		'db_rollback'       => 'T2',
		'db_exec'           => 'T3',
	);

	public const ROW_CAP     = 5000;
	public const DEFAULT_ROWS = 200;

	/** Tables never reachable through db_select / db_write below T3. */
	private const SENSITIVE_TABLES = array( 'users', 'usermeta', 'options', 'signups', 'registration_log', 'sitemeta', ...self::CREDENTIAL_TABLES );

	/** Known plugin tables that hold credentials (2FA seeds, API keys, sessions, security config). Below T3 never readable or rewritable. */
	private const CREDENTIAL_TABLES = array(
		'wfls_2fa_secrets', 'wfls_settings', 'wfconfig', 'wflogins', 'woocommerce_api_keys', 'woocommerce_sessions', 'wc_webhooks',
		'wc_rate_limits', 'wpmailsmtp_debug_events', 'itsec_lockouts', 'itsec_temp', 'itsec_opaque_tokens', 'two_factor_secrets',
	);

	private const FORBIDDEN_WORDS = array(
		'into', 'outfile', 'dumpfile', 'load_file', 'sleep', 'benchmark', 'get_lock', 'release_lock', 'release_all_locks',
		'is_free_lock', 'is_used_lock', 'master_pos_wait', 'sys_exec', 'sys_eval', 'updatexml', 'extractvalue',
		'load_extension', 'handler', 'procedure', 'analyse', 'for', 'lock', 'with', 'wait', 'nowait', 'skip',
	);
	private const FORBIDDEN_SCHEMAS = array( 'information_schema', 'performance_schema', 'mysql', 'sys' );
	private const EXEC_FIRST        = array( 'insert', 'replace', 'update', 'delete', 'create', 'alter', 'drop', 'truncate' );
	private const MASK_COLUMNS      = array( 'user_pass', 'user_activation_key', 'session_tokens' );

	private static ?OVP_Db_Backend $backend = null;

	public static function set_backend( ?OVP_Db_Backend $b ): void {
		self::$backend = $b;
	}

	private static function be(): OVP_Db_Backend {
		if ( null === self::$backend ) {
			self::$backend = new OVP_Db_Backend_WP();
		}
		return self::$backend;
	}

	/** @param array<string, mixed> $extra */
	private static function err( string $code, string $msg, int $status = 400, array $extra = array() ): WP_Error {
		return new WP_Error( $code, $msg, array_merge( array( 'status' => $status ), $extra ) );
	}

	private static function rank( string $tier ): int {
		return OVP_Upload::tier_rank( $tier );
	}

	// -- Tokenizer ---------------------------------------------------------------------

	/**
	 * Real SQL lexer: strings, backtick identifiers, comments, numbers, placeholders.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function tokenize( string $sql ): array|WP_Error {
		$n   = strlen( $sql );
		$i   = 0;
		$out = array();
		$start = 0;
		if ( $n > 2097152 ) {
			return self::err( 'ovp_sql_too_large', 'SQL exceeds 2MB.', 413 );
		}
		while ( $i < $n ) {
			$c = $sql[ $i ];
			if ( ctype_space( $c ) ) {
				++$i;
				continue;
			}
			$start = $i;
			// Comments.
			if ( '#' === $c || ( '-' === $c && $i + 1 < $n && '-' === $sql[ $i + 1 ] && ( $i + 2 >= $n || ctype_space( $sql[ $i + 2 ] ) || ord( $sql[ $i + 2 ] ) < 32 ) ) ) {
				$e = strpos( $sql, "\n", $i );
				$e = false === $e ? $n : $e;
				$out[] = array( 'o' => $start, 't' => 'comment', 's' => substr( $sql, $i, $e - $i ) );
				$i = $e;
				continue;
			}
			if ( '/' === $c && $i + 1 < $n && '*' === $sql[ $i + 1 ] ) {
				$e = strpos( $sql, '*/', $i + 2 );
				if ( false === $e ) {
					return self::err( 'ovp_sql_syntax', 'Unterminated block comment.' );
				}
				$out[] = array( 'o' => $start, 't' => 'comment', 's' => substr( $sql, $i, $e + 2 - $i ) );
				$i = $e + 2;
				continue;
			}
			// Quoted strings.
			if ( "'" === $c || '"' === $c ) {
				$j = $i + 1;
				while ( true ) {
					if ( $j >= $n ) {
						return self::err( 'ovp_sql_syntax', 'Unterminated string literal.' );
					}
					if ( '\\' === $sql[ $j ] ) {
						$j += 2;
						continue;
					}
					if ( $sql[ $j ] === $c ) {
						if ( $j + 1 < $n && $sql[ $j + 1 ] === $c ) {
							$j += 2;
							continue;
						}
						break;
					}
					++$j;
				}
				$out[] = array( 'o' => $start, 't' => 'string', 's' => substr( $sql, $i + 1, $j - $i - 1 ) );
				$i = $j + 1;
				continue;
			}
			if ( '`' === $c ) {
				$j = $i + 1;
				while ( true ) {
					if ( $j >= $n ) {
						return self::err( 'ovp_sql_syntax', 'Unterminated identifier.' );
					}
					if ( '`' === $sql[ $j ] ) {
						if ( $j + 1 < $n && '`' === $sql[ $j + 1 ] ) {
							$j += 2;
							continue;
						}
						break;
					}
					++$j;
				}
				$out[] = array( 'o' => $start, 't' => 'ident', 's' => str_replace( '``', '`', substr( $sql, $i + 1, $j - $i - 1 ) ) );
				$i = $j + 1;
				continue;
			}
			if ( '%' === $c && $i + 1 < $n && in_array( $sql[ $i + 1 ], array( 's', 'd', 'f' ), true ) ) {
				$out[] = array( 'o' => $start, 't' => 'ph', 's' => '%' . $sql[ $i + 1 ] );
				$i += 2;
				continue;
			}
			if ( '%' === $c && $i + 1 < $n && '%' === $sql[ $i + 1 ] ) {
				$out[] = array( 'o' => $start, 't' => 'pct', 's' => '%%' );
				$i += 2;
				continue;
			}
			if ( '@' === $c ) {
				$j = $i + 1;
				while ( $j < $n && ( ctype_alnum( $sql[ $j ] ) || '_' === $sql[ $j ] || '@' === $sql[ $j ] || '.' === $sql[ $j ] || '$' === $sql[ $j ] ) ) {
					++$j;
				}
				$out[] = array( 'o' => $start, 't' => 'var', 's' => substr( $sql, $i, $j - $i ) );
				$i = $j;
				continue;
			}
			if ( ctype_digit( $c ) || ( '.' === $c && $i + 1 < $n && ctype_digit( $sql[ $i + 1 ] ) ) ) {
				if ( '0' === $c && $i + 1 < $n && in_array( $sql[ $i + 1 ], array( 'x', 'X', 'b', 'B' ), true ) && $i + 2 < $n && ctype_xdigit( $sql[ $i + 2 ] ) ) {
					$j = $i + 2;
					while ( $j < $n && ctype_xdigit( $sql[ $j ] ) ) {
						++$j;
					}
					$out[] = array( 'o' => $start, 't' => 'hex', 's' => substr( $sql, $i, $j - $i ) );
					$i = $j;
					continue;
				}
				if ( 1 === preg_match( '/\G\d*\.?\d+(?:[eE][+-]?\d+)?/', $sql, $m, 0, $i ) ) {
					$j = $i + strlen( $m[0] );
					if ( $j < $n && ( ctype_alpha( $sql[ $j ] ) || '_' === $sql[ $j ] ) ) {
						// Identifier that starts with digits (legal in MySQL).
						while ( $j < $n && ( ctype_alnum( $sql[ $j ] ) || '_' === $sql[ $j ] || '$' === $sql[ $j ] ) ) {
							++$j;
						}
						$out[] = array( 'o' => $start, 't' => 'word', 's' => substr( $sql, $i, $j - $i ) );
					} else {
						$out[] = array( 'o' => $start, 't' => 'num', 's' => $m[0] );
					}
					$i = $j;
					continue;
				}
			}
			if ( ctype_alpha( $c ) || '_' === $c || '$' === $c || ord( $c ) > 127 ) {
				$j = $i + 1;
				while ( $j < $n && ( ctype_alnum( $sql[ $j ] ) || '_' === $sql[ $j ] || '$' === $sql[ $j ] || ord( $sql[ $j ] ) > 127 ) ) {
					++$j;
				}
				$w = substr( $sql, $i, $j - $i );
				// Charset introducers / x'..' b'..' n'..' prefixes before a quote are strings.
				if ( $j < $n && ( "'" === $sql[ $j ] || '"' === $sql[ $j ] ) && 1 === preg_match( '/^(?:_[a-z0-9]+|[xXbBnN])$/', $w ) ) {
					$out[] = array( 'o' => $start, 't' => 'intro', 's' => $w );
				} else {
					$out[] = array( 'o' => $start, 't' => 'word', 's' => $w );
				}
				$i = $j;
				continue;
			}
			$out[] = array( 'o' => $start, 't' => 'punct', 's' => $c );
			++$i;
		}
		return $out;
	}

	/**
	 * Split on top-level semicolons. Each entry keeps its exact source text.
	 *
	 * @param array<int, array<string, mixed>> $toks
	 * @return array<int, array{toks:array<int, array<string, mixed>>,text:string}>
	 */
	private static function statements( array $toks, string $sql ): array {
		$out = array();
		$cur = array();
		$push = static function ( array $cur, int $end ) use ( &$out, $sql ): void {
			if ( array() === $cur ) {
				return;
			}
			$o     = (int) $cur[0]['o'];
			$out[] = array( 'toks' => $cur, 'text' => trim( substr( $sql, $o, $end - $o ) ) );
		};
		foreach ( $toks as $t ) {
			if ( 'punct' === $t['t'] && ';' === $t['s'] ) {
				$push( $cur, (int) $t['o'] );
				$cur = array();
				continue;
			}
			$cur[] = $t;
		}
		$push( $cur, strlen( $sql ) );
		return $out;
	}

	private static function kw( array $t ): string {
		return 'word' === $t['t'] ? strtolower( $t['s'] ) : '';
	}

	/**
	 * Extract referenced tables. Returns names or WP_Error for schema-qualified tables.
	 *
	 * @param array<int, array{t:string,s:string}> $toks
	 * @return array<int, string>|WP_Error
	 */
	private static function tables_in( array $toks ): array|WP_Error {
		$tables = array();
		$stack  = array( array( 'list' => false, 'nofrom' => false ) );
		$expect = false;
		$skip   = array( 'if', 'not', 'exists', 'only', 'temporary', 'ignore', 'low_priority', 'quick', 'delayed', 'high_priority', 'table', 'lateral' );
		$prev   = '';
		$count  = count( $toks );
		for ( $i = 0; $i < $count; $i++ ) {
			$t = $toks[ $i ];
			if ( 'punct' === $t['t'] ) {
				if ( '(' === $t['s'] ) {
					// A '(' right where a table is expected is a parenthesised table reference
					// (FROM (t), JOIN (t), FROM (a, b)): keep expecting and inherit the list flag.
					$was_expect = $expect;
					$was_list   = $stack[ count( $stack ) - 1 ]['list'];
					$stack[]    = array( 'list' => $was_expect && $was_list, 'nofrom' => in_array( $prev, array( 'extract', 'trim', 'substring', 'substr', 'position' ), true ) );
					$prev       = '';
					continue;
				} elseif ( ')' === $t['s'] ) {
					if ( count( $stack ) > 1 ) {
						array_pop( $stack );
					}
				} elseif ( ',' === $t['s'] && $stack[ count( $stack ) - 1 ]['list'] ) {
					$expect = true;
				}
				$prev = '';
				continue;
			}
			$k = self::kw( $t );
			if ( $expect && 'word' === $t['t'] && in_array( $k, array( 'select', 'values', 'with' ), true ) ) {
				$expect                                = false;
				$stack[ count( $stack ) - 1 ]['list'] = false;
				$prev                                  = $k;
				continue;
			}
			if ( $expect && in_array( $t['t'], array( 'word', 'ident' ), true ) ) {
				if ( '' !== $k && in_array( $k, $skip, true ) ) {
					$prev = $k;
					continue;
				}
				if ( isset( $toks[ $i + 1 ] ) && 'punct' === $toks[ $i + 1 ]['t'] && '.' === $toks[ $i + 1 ]['s'] ) {
					return self::err( 'ovp_sql_schema', 'Schema-qualified table names are not allowed.', 403 );
				}
				$tables[] = $t['s'];
				$expect   = false;
				$prev     = $k;
				continue;
			}
			$top =& $stack[ count( $stack ) - 1 ];
			if ( 'from' === $k && $top['nofrom'] ) {
				$prev = $k;
				continue;
			}
			if ( in_array( $k, array( 'from', 'join', 'into', 'update', 'truncate', 'table' ), true ) && ! ( 'update' === $k && 'key' === $prev ) ) {
				$expect      = true;
				$top['list'] = in_array( $k, array( 'from', 'update', 'table' ), true );
			} elseif ( in_array( $k, array( 'where', 'set', 'values', 'value', 'group', 'order', 'having', 'limit', 'on', 'using', 'select', 'union', 'window', 'partition' ), true ) ) {
				$top['list'] = false;
				$expect      = false;
			}
			unset( $top );
			$prev = $k;
		}
		return $tables;
	}

	/**
	 * Fail-closed backstop: any identifier anywhere (not only in a parsed table position) that names
	 * a plugin table, a sensitive core table, or a masked secret column is denied. Catches
	 * parenthesised/STRAIGHT_JOIN table refs and column aliasing (user_pass AS x).
	 *
	 * @param array<int, array{t:string,s:string}> $toks
	 */
	private static function check_idents( array $toks, string $tier, bool $columns ): ?WP_Error {
		$prefix = self::be()->prefix();
		$pl     = strlen( $prefix );
		foreach ( $toks as $t ) {
			if ( ! in_array( $t['t'], array( 'word', 'ident' ), true ) ) {
				continue;
			}
			$l = strtolower( trim( $t['s'] ) );
			if ( $columns && self::rank( $tier ) < 3 && ( in_array( $l, self::MASK_COLUMNS, true ) || OVP_Meta::is_redacted( $l ) ) ) {
				return self::err( 'ovp_sql_table', 'Column ' . $l . ' is not readable below T3.', 403 );
			}
			if ( 0 !== strncasecmp( $l, $prefix, $pl ) ) {
				continue;
			}
			$bare = substr( $l, $pl );
			if ( str_starts_with( $bare, 'ovp_' ) || str_starts_with( $bare, 'mcp_bridge' ) ) {
				return self::err( 'ovp_sql_table', 'Plugin tables are not accessible.', 403 );
			}
			if ( in_array( $bare, self::SENSITIVE_TABLES, true ) && self::rank( $tier ) < 3 ) {
				return self::err( 'ovp_sql_table', 'Table ' . $bare . ' is sensitive (use the options/users tools; T3 can query it).', 403 );
			}
		}
		return null;
	}

	/**
	 * @param array<int, string> $tables
	 */
	private static function check_tables( array $tables, string $tier, bool $allow_sensitive_ok ): ?WP_Error {
		$prefix = self::be()->prefix();
		foreach ( $tables as $t ) {
			$l = strtolower( $t );
			if ( 'dual' === $l ) {
				continue;
			}
			if ( 0 !== strncasecmp( $t, $prefix, strlen( $prefix ) ) ) {
				return self::err( 'ovp_sql_table', 'Only tables with prefix ' . $prefix . ' are allowed.', 403 );
			}
			$bare = substr( $l, strlen( $prefix ) );
			if ( str_starts_with( $bare, 'ovp_' ) || str_starts_with( $bare, 'mcp_bridge' ) ) {
				return self::err( 'ovp_sql_table', 'Plugin tables are not accessible.', 403 );
			}
			if ( in_array( $bare, self::SENSITIVE_TABLES, true ) && ! ( $allow_sensitive_ok && self::rank( $tier ) >= 3 ) ) {
				return self::err( 'ovp_sql_table', 'Table ' . $bare . ' is sensitive (use the options/users tools; T3 can query it).', 403 );
			}
		}
		return null;
	}

	/** Words/tokens every statement kind must avoid. */
	private static function check_common( array $toks ): ?WP_Error {
		foreach ( $toks as $t ) {
			if ( 'comment' === $t['t'] ) {
				return self::err( 'ovp_sql_comment', 'SQL comments are not allowed.' );
			}
			if ( 'var' === $t['t'] ) {
				return self::err( 'ovp_sql_variable', 'SQL variables are not allowed.' );
			}
			if ( 'hex' === $t['t'] ) {
				return self::err( 'ovp_sql_literal', 'Hex literals are not allowed. Use placeholders.' );
			}
			$low = strtolower( $t['s'] );
			if ( in_array( $t['t'], array( 'word', 'ident' ), true ) && in_array( $low, self::FORBIDDEN_SCHEMAS, true ) ) {
				return self::err( 'ovp_sql_schema', 'System schemas are not accessible.', 403 );
			}
		}
		return null;
	}

	/**
	 * @param array<int, array{t:string,s:string}> $toks
	 * @return array{0:int,1:array<int,string>}|WP_Error placeholder count + order
	 */
	private static function placeholders( array $toks ): array|WP_Error {
		$ph = array();
		foreach ( $toks as $t ) {
			if ( 'ph' === $t['t'] ) {
				$ph[] = $t['s'];
			}
		}
		return array( count( $ph ), $ph );
	}

	/**
	 * Validate and normalize a read-only SELECT. Public for tests and the REST layer.
	 *
	 * @param array<int, mixed> $params
	 * @return array{sql:string,tables:array<int,string>,limit:?int}|WP_Error
	 */
	public static function validate_select( string $sql, array $params, string $tier = 'T0' ): array|WP_Error {
		$toks = self::tokenize( $sql );
		if ( $toks instanceof WP_Error ) {
			return $toks;
		}
		$err = self::check_common( $toks );
		if ( null !== $err ) {
			return $err;
		}
		$stmts = self::statements( $toks, $sql );
		if ( 1 !== count( $stmts ) ) {
			return self::err( 'ovp_sql_multi', 'Exactly one statement is allowed.' );
		}
		// A semicolon anywhere but the very end means stacking.
		foreach ( $toks as $i => $t ) {
			if ( 'punct' === $t['t'] && ';' === $t['s'] && $i !== count( $toks ) - 1 ) {
				return self::err( 'ovp_sql_multi', 'Statement stacking is not allowed.' );
			}
		}
		$st = $stmts[0]['toks'];
		if ( 'select' !== self::kw( $st[0] ) ) {
			return self::err( 'ovp_sql_readonly', 'Only SELECT statements are allowed here.', 403 );
		}
		$depth = 0;
		$limit = null;
		$n     = count( $st );
		foreach ( $st as $i => $t ) {
			if ( in_array( $t['t'], array( 'string', 'intro' ), true ) ) {
				return self::err( 'ovp_sql_literal', 'String literals are not allowed. Use %s placeholders with params.' );
			}
			$k = self::kw( $t );
			if ( '' !== $k && in_array( $k, self::FORBIDDEN_WORDS, true ) ) {
				return self::err( 'ovp_sql_forbidden', 'Keyword/function not allowed: ' . strtoupper( $k ) . '.', 403 );
			}
			if ( 'punct' === $t['t'] ) {
				if ( '(' === $t['s'] ) {
					++$depth;
				} elseif ( ')' === $t['s'] ) {
					--$depth;
				}
			}
			if ( 0 === $depth && 'limit' === $k ) {
				$a = $st[ $i + 1 ] ?? null;
				$b = $st[ $i + 2 ] ?? null;
				$c = $st[ $i + 3 ] ?? null;
				if ( null === $a || 'num' !== $a['t'] ) {
					return self::err( 'ovp_sql_limit', 'LIMIT must use numeric literals.' );
				}
				$limit = (int) $a['s'];
				if ( null !== $b && 'punct' === $b['t'] && ',' === $b['s'] ) {
					if ( null === $c || 'num' !== $c['t'] ) {
						return self::err( 'ovp_sql_limit', 'LIMIT must use numeric literals.' );
					}
					$limit = (int) $c['s'];
				}
			}
		}
		unset( $n );
		if ( 0 !== $depth ) {
			return self::err( 'ovp_sql_syntax', 'Unbalanced parentheses.' );
		}
		if ( null !== $limit && $limit > self::ROW_CAP ) {
			return self::err( 'ovp_sql_limit', 'LIMIT exceeds ' . self::ROW_CAP . ' rows.', 413 );
		}
		[ $count ] = self::placeholders( $st );
		if ( $count !== count( $params ) ) {
			return self::err( 'ovp_sql_params', 'Placeholder count (' . $count . ') does not match params (' . count( $params ) . ').' );
		}
		foreach ( $params as $p ) {
			if ( ! is_scalar( $p ) || ( is_string( $p ) && strlen( $p ) > 65536 ) ) {
				return self::err( 'ovp_sql_params', 'Params must be scalars up to 64KB.' );
			}
		}
		$tables = self::tables_in( $st );
		if ( $tables instanceof WP_Error ) {
			return $tables;
		}
		$err = self::check_tables( $tables, $tier, true ) ?? self::check_idents( $st, $tier, true );
		if ( null !== $err ) {
			return $err;
		}
		return array( 'sql' => $stmts[0]['text'], 'tables' => $tables, 'limit' => $limit );
	}

	// -- db_select ----------------------------------------------------------------------------

	/**
	 * @param array<int, mixed>    $params
	 * @param array<string, mixed> $opts tier, max_rows, cursor
	 * @return array<string, mixed>|WP_Error
	 */
	public static function select( string $sql, array $params = array(), array $opts = array() ): array|WP_Error {
		$tier = (string) ( $opts['tier'] ?? 'T0' );
		$v    = self::validate_select( $sql, array_values( $params ), $tier );
		if ( $v instanceof WP_Error ) {
			return $v;
		}
		$max    = max( 1, min( self::ROW_CAP, (int) ( $opts['max_rows'] ?? self::DEFAULT_ROWS ) ) );
		$cursor = max( 0, (int) ( $opts['cursor'] ?? 0 ) );
		$final  = self::be()->prepare( $v['sql'], array_values( $params ) );
		$paged  = null === $v['limit'];
		if ( $paged ) {
			$final .= ' LIMIT ' . ( $max + 1 ) . ' OFFSET ' . $cursor;
		}
		self::be()->begin( true );
		$rows = self::be()->select( $final );
		$e    = self::be()->last_error();
		self::be()->rollback();
		if ( null === $rows ) {
			return self::err( 'ovp_sql_failed', '' !== $e ? self::short_error( $e ) : 'Query failed.', 400 );
		}
		$more = $paged && count( $rows ) > $max;
		if ( $more ) {
			$rows = array_slice( $rows, 0, $max );
		}
		$rows = array_map( static fn( array $r ): array => self::mask_row( $r, $tier ), $rows );
		return array(
			'rows'        => $rows,
			'count'       => count( $rows ),
			'tables'      => $v['tables'],
			'next_cursor' => $more ? (string) ( $cursor + $max ) : '',
			'done'        => ! $more,
		);
	}

	private static function short_error( string $e ): string {
		return substr( preg_replace( '/\s+/', ' ', $e ) ?? $e, 0, 300 );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function mask_row( array $row, string $tier = 'T0' ): array {
		$name = (string) ( $row['option_name'] ?? $row['meta_key'] ?? '' );
		$hide = '' !== $name && ( OVP_Meta::is_hard_blocked( $name ) || OVP_Meta::is_redacted( $name ) );
		foreach ( $row as $c => $v ) {
			$lc = strtolower( (string) $c );
			// Columns whose NAME looks secret (secret, token, api_key, password, salt, ...) are masked below T3 in any table.
			if ( in_array( $lc, self::MASK_COLUMNS, true ) || ( self::rank( $tier ) < 3 && OVP_Meta::is_redacted( $lc ) ) || ( $hide && in_array( $lc, array( 'option_value', 'meta_value' ), true ) ) ) {
				$row[ $c ] = '***';
			} elseif ( is_string( $v ) ) {
				$v = OVP_Meta::redact_cell( $v );
				$row[ $c ] = strlen( $v ) > 65536 ? substr( $v, 0, 65536 ) . '...[truncated]' : $v;
			}
		}
		return $row;
	}

	/** @return array<int, string> */
	public static function tables( string $tier = 'T0' ): array {
		$out    = array();
		$prefix = self::be()->prefix();
		foreach ( self::be()->tables() as $t ) {
			$bare = strtolower( substr( $t, strlen( $prefix ) ) );
			if ( 0 !== strncasecmp( $t, $prefix, strlen( $prefix ) ) || str_starts_with( $bare, 'ovp_' ) || str_starts_with( $bare, 'mcp_bridge' ) ) {
				continue;
			}
			if ( in_array( $bare, self::SENSITIVE_TABLES, true ) && self::rank( $tier ) < 3 ) {
				continue;
			}
			$out[] = $t;
		}
		return $out;
	}

	/** Tables search_replace may touch: options is allowed (siteurl moves), identity tables are not. */
	private static function sr_tables(): array {
		$prefix = self::be()->prefix();
		$out    = array();
		foreach ( self::tables( 'T3' ) as $t ) {
			$bare = strtolower( substr( $t, strlen( $prefix ) ) );
			if ( ! in_array( $bare, array( 'users', 'usermeta', 'signups', 'registration_log', 'sitemeta', ...self::CREDENTIAL_TABLES ), true ) ) {
				$out[] = $t;
			}
		}
		return $out;
	}

	/** @return array<int, array{name:string,type:string}>|WP_Error */
	public static function describe( string $table, string $tier = 'T0' ): array|WP_Error {
		if ( ! in_array( $table, self::tables( $tier ), true ) ) {
			return self::err( 'ovp_sql_table', 'Table not available.', 404 );
		}
		return self::be()->columns( $table );
	}

	// -- db_exec (T3) -----------------------------------------------------------------------------

	/**
	 * Validate a multi-statement DML/DDL script. Strings are allowed here (SQL artifacts
	 * carry content) but plugin-owned names are not.
	 *
	 * @return array<int, string>|WP_Error list of statements (original text slices)
	 */
	public static function validate_exec( string $sql, string $tier ): array|WP_Error {
		if ( self::rank( $tier ) < 3 ) {
			return self::err( 'ovp_tier_denied', 'db_exec needs T3.', 403 );
		}
		$toks = self::tokenize( $sql );
		if ( $toks instanceof WP_Error ) {
			return $toks;
		}
		$err = self::check_common( $toks );
		if ( null !== $err ) {
			return $err;
		}
		$stmts = self::statements( $toks, $sql );
		if ( array() === $stmts || count( $stmts ) > 500 ) {
			return self::err( 'ovp_sql_multi', '1..500 statements are allowed.' );
		}
		$bad_words = array_diff( self::FORBIDDEN_WORDS, array( 'into', 'for', 'with', 'lock', 'wait', 'nowait', 'skip', 'procedure' ) );
		$out       = array();
		foreach ( $stmts as $stmt ) {
			$st    = $stmt['toks'];
			$first = self::kw( $st[0] );
			if ( ! in_array( $first, self::EXEC_FIRST, true ) ) {
				return self::err( 'ovp_sql_forbidden', 'Statement type not allowed: ' . strtoupper( '' !== $first ? $first : 'unknown' ) . '.', 403 );
			}
			foreach ( $st as $i => $t ) {
				$k = self::kw( $t );
				if ( '' !== $k && in_array( $k, $bad_words, true ) ) {
					return self::err( 'ovp_sql_forbidden', 'Keyword/function not allowed: ' . strtoupper( $k ) . '.', 403 );
				}
				if ( 'string' === $t['t'] && 1 === preg_match( '/(?:^|[^a-z0-9])(?:_?ovp_|mcp_bridge_)/i', $t['s'] ) ) {
					return self::err( 'ovp_sql_forbidden', 'Plugin-owned keys are not touchable.', 403 );
				}
				if ( 'string' === $t['t'] && OVP_Meta::is_hard_blocked( $t['s'] ) && ! ctype_space( $t['s'] ) && strlen( $t['s'] ) < 64 && 1 === preg_match( '/^[a-z0-9_]+$/i', $t['s'] ) ) {
					return self::err( 'ovp_sql_forbidden', 'Protected key names are not touchable via SQL.', 403 );
				}
				if ( 'drop' === $first && 'word' === $t['t'] && in_array( $k, array( 'database', 'schema', 'user', 'role' ), true ) ) {
					return self::err( 'ovp_sql_forbidden', 'Not allowed.', 403 );
				}
				if ( 'create' === $first && 'word' === $t['t'] && in_array( $k, array( 'database', 'schema', 'user', 'role', 'trigger', 'function', 'procedure', 'event', 'server' ), true ) ) {
					return self::err( 'ovp_sql_forbidden', 'Not allowed.', 403 );
				}
				unset( $i );
			}
			$tables = self::tables_in( $st );
			if ( $tables instanceof WP_Error ) {
				return $tables;
			}
			$err = self::check_tables( $tables, $tier, true ) ?? self::check_idents( $st, $tier, false );
			if ( null !== $err ) {
				return $err;
			}
			if ( array() === $tables ) {
				return self::err( 'ovp_sql_table', 'Statement references no prefixed table.', 403 );
			}
			if ( in_array( $first, array( 'drop', 'truncate', 'alter', 'create' ), true ) ) {
				$prefix = self::be()->prefix();
				foreach ( $tables as $t ) {
					if ( in_array( strtolower( substr( $t, strlen( $prefix ) ) ), self::SENSITIVE_TABLES, true ) ) {
						return self::err( 'ovp_sql_forbidden', 'DDL on core identity/options tables is not allowed.', 403 );
					}
				}
			}
			$out[] = $stmt['text'];
		}
		return $out;
	}

	/**
	 * @param array<int, mixed>    $params
	 * @param array<string, mixed> $opts tier, dry_run
	 * @return array<string, mixed>|WP_Error
	 */
	public static function exec( string $sql, array $params = array(), array $opts = array() ): array|WP_Error {
		$tier  = (string) ( $opts['tier'] ?? 'T0' );
		$stmts = self::validate_exec( $sql, $tier );
		if ( $stmts instanceof WP_Error ) {
			return $stmts;
		}
		if ( ! empty( $opts['dry_run'] ) ) {
			return array( 'dry_run' => true, 'statements' => count( $stmts ) );
		}
		$dml = true;
		foreach ( $stmts as $s ) {
			if ( ! preg_match( '/^(insert|replace|update|delete)\b/i', $s ) ) {
				$dml = false;
			}
		}
		$be = self::be();
		if ( $dml ) {
			$be->begin( false );
		}
		$before  = self::own_rows_fingerprint();
		$results = array();
		foreach ( $stmts as $i => $s ) {
			$n = preg_match_all( '/(?<!%)%[sdf]/', $s );
			if ( $n > 0 && 1 === count( $stmts ) ) {
				$s = $be->prepare( $s, array_values( $params ) );
			}
			$r = $be->exec( $s );
			if ( false === $r ) {
				if ( $dml ) {
					$be->rollback();
				}
				return self::err( 'ovp_sql_failed', 'Statement ' . ( $i + 1 ) . ' failed: ' . self::short_error( $be->last_error() ), 400, array( 'failed_index' => $i, 'rolled_back' => $dml ) );
			}
			$results[] = array( 'index' => $i, 'rows_affected' => (int) $r );
		}
		// Hard invariant: plugin-owned and auth-key options must not change, however the SQL
		// was constructed (CONCAT/CHAR tricks defeat any literal scan).
		if ( self::own_rows_fingerprint() !== $before ) {
			if ( $dml ) {
				$be->rollback();
			}
			return self::err(
				'ovp_invariant_violation',
				'Script would modify plugin-owned or auth-key options. ' . ( $dml ? 'Rolled back.' : 'Not atomic: already applied, restore from backup.' ),
				403,
				array( 'rolled_back' => $dml )
			);
		}
		if ( $dml ) {
			$be->commit();
		}
		return array( 'results' => $results, 'atomic' => $dml );
	}

	/** Digest of options rows the SQL tools must never change. */
	private static function own_rows_fingerprint(): string {
		$be   = self::be();
		$t    = '`' . str_replace( '`', '', $be->prefix() ) . 'options`';
		$names = "'" . implode( "','", array( 'auth_key', 'secure_auth_key', 'logged_in_key', 'nonce_key', 'auth_salt', 'secure_auth_salt', 'logged_in_salt', 'nonce_salt' ) ) . "'";
		$rows = $be->select( "SELECT option_name, MD5(option_value) AS h FROM {$t} WHERE option_name LIKE 'ovp\\_%' OR option_name LIKE 'mcp\\_bridge\\_%' OR option_name IN ({$names}) ORDER BY option_name" );
		return hash( 'sha256', (string) json_encode( $rows ) );
	}

	// -- db_write (T2) -------------------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $where
	 * @param array<string, mixed> $opts tier, dry_run, actor
	 * @return array<string, mixed>|WP_Error
	 */
	public static function write( string $op, string $table, array $data, array $where, array $opts = array() ): array|WP_Error {
		$tier = (string) ( $opts['tier'] ?? 'T0' );
		if ( self::rank( $tier ) < 2 ) {
			return self::err( 'ovp_tier_denied', 'db_write needs T2.', 403 );
		}
		if ( ! in_array( $op, array( 'insert', 'update', 'delete' ), true ) ) {
			return self::err( 'ovp_sql_forbidden', 'op must be insert, update or delete.' );
		}
		$allowed = self::tables( 'T2' );
		if ( ! in_array( $table, $allowed, true ) ) {
			return self::err( 'ovp_sql_table', 'Table not writable.', 403 );
		}
		$cols = array_column( self::be()->columns( $table ), 'name' );
		foreach ( array_merge( array_keys( $data ), array_keys( $where ) ) as $c ) {
			if ( ! in_array( (string) $c, $cols, true ) ) {
				return self::err( 'ovp_sql_column', 'Unknown column.' );
			}
		}
		foreach ( array_merge( $data, $where ) as $v ) {
			if ( ! ( is_scalar( $v ) || null === $v ) || ( is_string( $v ) && strlen( $v ) > 4194304 ) ) {
				return self::err( 'ovp_sql_params', 'Values must be scalars up to 4MB.' );
			}
			if ( is_string( $v ) && OVP_Meta::contains_object_payload( $v ) ) {
				return self::err( 'ovp_object_refused', 'Serialized object payloads are refused.', 422 );
			}
		}
		if ( 'insert' !== $op && ( array() === $where || count( $where ) > 5 ) ) {
			return self::err( 'ovp_sql_where', 'A WHERE of 1..5 equality columns is required.' );
		}
		foreach ( $where as $v ) {
			if ( null === $v ) {
				return self::err( 'ovp_sql_where', 'NULL is not allowed in WHERE.' );
			}
		}
		if ( in_array( $op, array( 'insert', 'update' ), true ) && array() === $data ) {
			return self::err( 'ovp_sql_params', 'data is required.' );
		}
		$be = self::be();
		$pk = $be->primary_key( $table );
		if ( 'insert' !== $op ) {
			$cnt = $be->count_where( $table, $where );
			if ( $cnt > 1000 ) {
				return self::err( 'ovp_sql_rowcap', 'Would affect ' . $cnt . ' rows (cap 1000).', 413 );
			}
			if ( ! empty( $opts['dry_run'] ) ) {
				return array( 'dry_run' => true, 'would_affect' => $cnt );
			}
			if ( null === $pk && $cnt > 0 ) {
				return self::err( 'ovp_sql_pk', 'Table has no single integer primary key; cannot snapshot.', 409 );
			}
			$before = $cnt > 0 ? $be->select_where( $table, $where, 1000 ) : array();
			$snap   = array( 'op' => $op, 'table' => $table, 'pk' => $pk, 'rows' => $before, 'actor' => substr( (string) ( $opts['actor'] ?? '' ), 0, 120 ), 'tier' => 'T2' );
		} else {
			if ( ! empty( $opts['dry_run'] ) ) {
				return array( 'dry_run' => true, 'would_affect' => 1 );
			}
			$snap = array( 'op' => 'insert', 'table' => $table, 'pk' => $pk, 'rows' => array(), 'actor' => substr( (string) ( $opts['actor'] ?? '' ), 0, 120 ), 'tier' => 'T2' );
		}
		if ( 'insert' !== $op ) {
			$sid = OVP_Meta_Snapshots::save( $snap );
			if ( $sid instanceof WP_Error ) {
				return $sid;
			}
		}
		if ( 'insert' === $op ) {
			$id = $be->insert_row( $table, $data );
			if ( false === $id ) {
				return self::err( 'ovp_sql_failed', self::short_error( $be->last_error() ), 400 );
			}
			$sid = null;
			if ( null !== $pk && $id > 0 ) {
				$snap['inserted_pk'] = $id;
				$sid                 = OVP_Meta_Snapshots::save( $snap );
				if ( $sid instanceof WP_Error ) {
					$sid = null;
				}
			}
			return array( 'insert_id' => $id, 'rows_affected' => 1, 'snapshot_id' => $sid );
		}
		$n = 'update' === $op ? $be->update_rows( $table, $data, $where ) : $be->delete_rows( $table, $where );
		if ( false === $n ) {
			return self::err( 'ovp_sql_failed', self::short_error( $be->last_error() ), 400, array( 'snapshot_id' => $sid ) );
		}
		return array( 'rows_affected' => (int) $n, 'snapshot_id' => $sid );
	}

	/**
	 * Undo a db_write or search_replace snapshot.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function rollback( string $snapshot_id, string $tier ): array|WP_Error {
		if ( self::rank( $tier ) < 2 ) {
			return self::err( 'ovp_tier_denied', 'db_rollback needs T2.', 403 );
		}
		$s = OVP_Meta_Snapshots::load( $snapshot_id );
		if ( $s instanceof WP_Error ) {
			return $s;
		}
		$be = self::be();
		if ( 'search_replace' === ( $s['op'] ?? '' ) ) {
			$n = 0;
			foreach ( (array) $s['cells'] as $c ) {
				if ( in_array( (string) $c['table'], self::sr_tables(), true ) && $be->update_cell( (string) $c['table'], (string) $c['pk'], (int) $c['id'], (string) $c['col'], (string) $c['old'] ) ) {
					++$n;
				}
			}
			OVP_Meta_Snapshots::mark_rolled_back( $snapshot_id );
			return array( 'rolled_back' => true, 'cells_restored' => $n );
		}
		$table = (string) ( $s['table'] ?? '' );
		if ( ! in_array( $table, self::tables( 'T2' ), true ) ) {
			return self::err( 'ovp_sql_table', 'Table not writable.', 403 );
		}
		$pk = $s['pk'] ?? null;
		$n  = 0;
		if ( 'insert' === $s['op'] ) {
			if ( null !== $pk && isset( $s['inserted_pk'] ) ) {
				$r = $be->delete_rows( $table, array( (string) $pk => (int) $s['inserted_pk'] ) );
				$n = false === $r ? 0 : (int) $r;
			}
		} elseif ( null !== $pk ) {
			foreach ( (array) $s['rows'] as $row ) {
				if ( 'delete' === $s['op'] ) {
					$n += false === $be->insert_row( $table, $row ) ? 0 : 1;
				} else {
					$key  = array( (string) $pk => $row[ (string) $pk ] );
					$data = $row;
					unset( $data[ (string) $pk ] );
					$r = $be->update_rows( $table, $data, $key );
					$n += false === $r ? 0 : 1;
				}
			}
		}
		OVP_Meta_Snapshots::mark_rolled_back( $snapshot_id );
		return array( 'rolled_back' => true, 'rows_restored' => $n );
	}

	// -- search / replace -------------------------------------------------------------------------------

	/**
	 * Replace $search with $replace in one stored value without breaking serialization.
	 *
	 * @return array{changed:bool,value:string,skipped?:string,count:int}
	 */
	public static function replace_value( string $value, string $search, string $replace ): array {
		if ( '' === $search || ! str_contains( $value, $search ) && ! self::json_variant_present( $value, $search ) && ! OVP_Meta::looks_serialized( $value ) ) {
			return array( 'changed' => false, 'value' => $value, 'count' => 0 );
		}
		if ( OVP_Meta::looks_serialized( $value ) ) {
			$r = OVP_Meta::safe_unserialize( $value );
			if ( ! $r['ok'] ) {
				return str_contains( $value, $search )
					? array( 'changed' => false, 'value' => $value, 'skipped' => 'unparseable_serialized', 'count' => 0 )
					: array( 'changed' => false, 'value' => $value, 'count' => 0 );
			}
			if ( $r['objects'] ) {
				return str_contains( $value, $search )
					? array( 'changed' => false, 'value' => $value, 'skipped' => 'contains_objects', 'count' => 0 )
					: array( 'changed' => false, 'value' => $value, 'count' => 0 );
			}
			$count = 0;
			$new   = self::walk_replace( $r['value'], $search, $replace, $count );
			if ( 0 === $count ) {
				return array( 'changed' => false, 'value' => $value, 'count' => 0 );
			}
			return array( 'changed' => true, 'value' => serialize( $new ), 'count' => $count );
		}
		$c0 = $value[0] ?? '';
		if ( '{' === $c0 || '[' === $c0 ) {
			json_decode( $value );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				$new   = $value;
				$count = 0;
				foreach ( self::json_variants( $search, $replace ) as [ $s, $r ] ) {
					$new = str_replace( $s, $r, $new, $c );
					$count += $c;
				}
				if ( 0 === $count ) {
					return array( 'changed' => false, 'value' => $value, 'count' => 0 );
				}
				json_decode( $new );
				if ( JSON_ERROR_NONE !== json_last_error() ) {
					return array( 'changed' => false, 'value' => $value, 'skipped' => 'json_invalid_after', 'count' => 0 );
				}
				return array( 'changed' => $new !== $value, 'value' => $new, 'count' => $count );
			}
		}
		$new = str_replace( $search, $replace, $value, $count );
		return array( 'changed' => $new !== $value, 'value' => $new, 'count' => $count );
	}

	/** @return array<int, array{0:string,1:string}> */
	private static function json_variants( string $search, string $replace ): array {
		$enc = static function ( string $s ): string {
			$j = json_encode( $s );
			return false === $j ? $s : substr( $j, 1, -1 );
		};
		$enc_u = static function ( string $s ): string {
			$j = json_encode( $s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			return false === $j ? $s : substr( $j, 1, -1 );
		};
		$out = array();
		foreach ( array( array( $enc( $search ), $enc( $replace ) ), array( $enc_u( $search ), $enc_u( $replace ) ) ) as $pair ) {
			if ( ! in_array( $pair, $out, true ) ) {
				$out[] = $pair;
			}
		}
		return $out;
	}

	private static function json_variant_present( string $value, string $search ): bool {
		foreach ( self::json_variants( $search, '' ) as [ $s ] ) {
			if ( '' !== $s && str_contains( $value, $s ) ) {
				return true;
			}
		}
		return false;
	}

	private static function walk_replace( mixed $v, string $search, string $replace, int &$count ): mixed {
		if ( is_string( $v ) ) {
			$r = self::replace_value( $v, $search, $replace );
			if ( $r['changed'] ) {
				$count += max( 1, $r['count'] );
				return $r['value'];
			}
			return $v;
		}
		if ( is_array( $v ) ) {
			foreach ( $v as $k => $x ) {
				$v[ $k ] = self::walk_replace( $x, $search, $replace, $count );
			}
		}
		return $v;
	}

	/**
	 * Batched, dry-run-by-default search/replace.
	 *
	 * @param array<string, mixed> $args search, replace, tables[], dry_run(default true), cursor(json), max_rows, include_guid
	 * @return array<string, mixed>|WP_Error
	 */
	public static function search_replace( array $args, string $tier ): array|WP_Error {
		if ( self::rank( $tier ) < 2 ) {
			return self::err( 'ovp_tier_denied', 'search_replace needs T2.', 403 );
		}
		$search  = (string) ( $args['search'] ?? '' );
		$replace = (string) ( $args['replace'] ?? '' );
		if ( strlen( $search ) < 3 && empty( $args['allow_short'] ) ) {
			return self::err( 'ovp_sr_short', 'search must be at least 3 characters.' );
		}
		if ( $search === $replace ) {
			return self::err( 'ovp_sr_noop', 'search equals replace.' );
		}
		if ( strlen( $search ) > 4096 || strlen( $replace ) > 65536 || OVP_Meta::contains_object_payload( $replace ) ) {
			return self::err( 'ovp_sr_invalid', 'search/replace too large or contains an object payload.' );
		}
		$dry   = ! array_key_exists( 'dry_run', $args ) || ! empty( $args['dry_run'] );
		$avail = self::sr_tables();
		$want  = isset( $args['tables'] ) && is_array( $args['tables'] ) ? array_map( 'strval', $args['tables'] ) : $avail;
		foreach ( $want as $t ) {
			if ( ! in_array( $t, $avail, true ) ) {
				return self::err( 'ovp_sql_table', 'Table not available: ' . substr( $t, 0, 64 ), 403 );
			}
		}
		sort( $want );
		$cur = array( 't' => '', 'p' => 0 );
		if ( ! empty( $args['cursor'] ) ) {
			$d = json_decode( (string) $args['cursor'], true );
			if ( ! is_array( $d ) || ! isset( $d['t'], $d['p'] ) ) {
				return self::err( 'ovp_sr_cursor', 'Invalid cursor.' );
			}
			$cur = array( 't' => (string) $d['t'], 'p' => (int) $d['p'] );
		}
		$budget  = max( 100, min( 50000, (int) ( $args['max_rows'] ?? 5000 ) ) );
		$be      = self::be();
		$scanned = 0;
		$cells   = array();
		$bytes   = 0;
		$report  = array();
		$skipped = array();
		$next    = '';
		$prefix  = $be->prefix();
		foreach ( $want as $table ) {
			if ( '' !== $cur['t'] && strcmp( $table, $cur['t'] ) < 0 ) {
				continue;
			}
			$pk = $be->primary_key( $table );
			if ( null === $pk ) {
				$skipped[] = array( 'table' => $table, 'reason' => 'no_single_int_pk' );
				continue;
			}
			$cols = array();
			foreach ( $be->columns( $table ) as $c ) {
				if ( $c['name'] === $pk || ( 'guid' === $c['name'] && empty( $args['include_guid'] ) ) ) {
					continue;
				}
				if ( 1 === preg_match( '/^(?:varchar|char|tinytext|text|mediumtext|longtext|json)\b/', $c['type'] ) ) {
					$cols[] = $c['name'];
				}
			}
			if ( array() === $cols ) {
				continue;
			}
			$is_options = strtolower( $table ) === strtolower( $prefix . 'options' );
			$after      = $table === $cur['t'] ? $cur['p'] : 0;
			$stop       = false;
			while ( true ) {
				$page = $be->fetch_page( $table, $pk, $cols, $after, 200 );
				if ( array() === $page ) {
					break;
				}
				foreach ( $page as $row ) {
					$id = (int) $row[ $pk ];
					++$scanned;
					$after = $id;
					if ( $is_options ) {
						// Same policy as OVP_Meta: hard-blocked rows never, structural rows (active_plugins, siteurl, home,
						// template, stylesheet, default_role, users_can_register, *user_roles, ...) only at T3.
						$oname = (string) ( $row['option_name'] ?? '' );
						$need  = OVP_Meta::required_tier( 'write', 'option', $oname );
						if ( null === $need ) {
							continue;
						}
						if ( self::rank( $need ) > self::rank( $tier ) ) {
							$skipped[] = array( 'table' => $table, 'id' => $id, 'reason' => 'tier:' . $need );
							continue;
						}
					}
					foreach ( $cols as $col ) {
						$val = $row[ $col ] ?? null;
						if ( ! is_string( $val ) || '' === $val ) {
							continue;
						}
						$r = self::replace_value( $val, $search, $replace );
						if ( isset( $r['skipped'] ) ) {
							$skipped[] = array( 'table' => $table, 'id' => $id, 'col' => $col, 'reason' => $r['skipped'] );
							continue;
						}
						if ( $r['changed'] ) {
							$bytes += strlen( $val );
							$cells[] = array( 'table' => $table, 'pk' => $pk, 'id' => $id, 'col' => $col, 'old' => $val, 'new' => $r['value'] );
							$report[ $table ] = ( $report[ $table ] ?? 0 ) + max( 1, $r['count'] );
						}
					}
					if ( $scanned >= $budget || $bytes > 8388608 ) {
						$next = (string) json_encode( array( 't' => $table, 'p' => $after ) );
						$stop = true;
						break;
					}
				}
				if ( $stop || count( $page ) < 200 ) {
					break;
				}
			}
			if ( $stop ) {
				break;
			}
		}
		$out = array(
			'dry_run'      => $dry,
			'rows_scanned' => $scanned,
			'cells'        => count( $cells ),
			'per_table'    => $report,
			'skipped'      => array_slice( $skipped, 0, 100 ),
			'next_cursor'  => $next,
			'done'         => '' === $next,
			'snapshot_id'  => null,
		);
		if ( $dry || array() === $cells ) {
			return $out;
		}
		$snap = array(
			'op'    => 'search_replace',
			'cells' => array_map( static fn( array $c ): array => array( 'table' => $c['table'], 'pk' => $c['pk'], 'id' => $c['id'], 'col' => $c['col'], 'old' => $c['old'] ), $cells ),
			'tier'  => 'T2',
			'actor' => substr( (string) ( $args['actor'] ?? '' ), 0, 120 ),
		);
		$sid = OVP_Meta_Snapshots::save( $snap );
		if ( $sid instanceof WP_Error ) {
			return $sid;
		}
		$ok = 0;
		foreach ( $cells as $c ) {
			if ( $be->update_cell( $c['table'], $c['pk'], $c['id'], $c['col'], $c['new'] ) ) {
				++$ok;
			}
		}
		$out['snapshot_id']   = $sid;
		$out['cells_applied'] = $ok;
		return $out;
	}
}
