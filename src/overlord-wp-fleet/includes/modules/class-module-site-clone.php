<?php
/**
 * /ovp/v1/site/clone/* — plan + chunked SQL + chunked wp-content for
 * pull-to-ServBay. Fresh WP core is installed locally; this never ships wp-config
 * or remote DB credentials. MCP tokens/hops are omitted from the dump.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ovp_Module_Site_Clone extends Ovp_Module_Base {

	private const SQL_BYTES = 800000;

	/** @var array<string, string> */
	private array $order_sql = array();

	public function register_routes(): void {
		$this->route( 'GET', 'site/clone/plan', array( $this, 'plan' ) );
		$this->route( 'GET', 'site/clone/sql', array( $this, 'sql' ) );
		$this->route( 'GET', 'site/clone/files', array( $this, 'files' ) );
	}

	public function plan( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb, $wp_version;

		if ( is_multisite() ) {
			return $this->error( 'mcp_bridge_clone_multisite', 'Multisite clone is not supported in v1.', 400 );
		}

		$include_uploads = ! array_key_exists( 'include_uploads', $request->get_query_params() )
			|| $this->truthy( $request->get_param( 'include_uploads' ) );

		$host      = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$dest_slug = $this->suggest_dest_domain( $host );
		$needs_dest = $dest_slug === $host;
		if ( $needs_dest ) {
			$dest_slug = '';
		}
		$tables    = $this->prefixed_tables();
		$stats     = array();
		$sql_rows  = 0;
		$sql_bytes = 0;
		foreach ( $tables as $table ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- table name from SHOW TABLES + prefix.
			$rows  = (int) ( $row['Rows'] ?? 0 );
			$bytes = (int) ( $row['Data_length'] ?? 0 ) + (int) ( $row['Index_length'] ?? 0 );
			$sql_rows  += $rows;
			$sql_bytes += $bytes;
			$stats[]    = array(
				'table' => $table,
				'rows'  => $rows,
				'bytes' => $bytes,
			);
		}

		$files = $this->collect_content_files( $include_uploads );
		if ( $files instanceof WP_Error ) {
			return $files;
		}

		return $this->success(
			array(
				'home_host'          => $host,
				'site_url'           => site_url(),
				'home_url'           => home_url(),
				'table_prefix'       => $wpdb->prefix,
				'wordpress_version'  => $wp_version,
				'php_version'        => PHP_VERSION,
				'plugin_version'     => OVP_FLEET_VERSION,
				'dest_domain'        => $dest_slug,
				'needs_dest_override'=> $needs_dest,
				'include_uploads'    => $include_uploads,
				'skip_ephemeral'     => true,
				'tables'             => $stats,
				'sql_rows'           => $sql_rows,
				'sql_bytes'          => $sql_bytes,
				'file_count'         => count( $files ),
				'file_bytes'         => array_sum( array_column( $files, 'size' ) ),
				'excludes'           => $this->exclude_notes(),
				'omitted_options'    => $this->secret_option_names(),
				'next'               => array(
					'sql'   => 'GET site/clone/sql (cursor loop; CLI tools/clone-to-servbay.py)',
					'files' => 'GET site/clone/files',
					'rewire'=> 'After dest boots with WP_HOME=.dev: search_replace searches=[home_host] replace_host=dest_domain',
				),
			)
		);
	}

	public function sql( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 20 );
		}

		$tables = $this->prefixed_tables();
		if ( array() === $tables ) {
			return $this->error( 'mcp_bridge_clone_empty', 'No prefixed tables.', 500 );
		}

		[ $cur_table, $cur_off ] = $this->parse_sql_cursor( (string) ( $request->get_param( 'cursor' ) ?: '' ) );
		if ( $cur_off < 0 ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'SQL cursor must be JSON {"t":table,"o":offset}.', 400 );
		}
		if ( '' === $cur_table ) {
			$cur_table = $tables[0];
			$cur_off   = 0;
		}
		if ( ! in_array( $cur_table, $tables, true ) ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Unknown table in cursor.', 400 );
		}

		$sql   = '';
		$start = microtime( true );
		if ( 0 === $cur_off && $cur_table === $tables[0] && '' === (string) $request->get_param( 'cursor' ) ) {
			$sql .= "-- mcp-bridge site clone\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n";
		}

		$idx = array_search( $cur_table, $tables, true );
		if ( false === $idx ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Unknown table in cursor.', 400 );
		}

		$table  = $cur_table;
		$offset = $cur_off;
		$next   = '';

		while ( $idx < count( $tables ) ) {
			$table = $tables[ $idx ];
			if ( 0 === $offset ) {
				$create = $wpdb->get_row( 'SHOW CREATE TABLE `' . $this->ident( $table ) . '`', ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL -- ident() from allowlist.
				if ( ! is_array( $create ) || empty( $create[1] ) ) {
					return $this->error( 'mcp_bridge_query_failed', 'SHOW CREATE failed for ' . $table, 500 );
				}
				$sql .= 'DROP TABLE IF EXISTS `' . $this->ident( $table ) . "`;\n" . $create[1] . ";\n";
			}

			$where = $this->ephemeral_where( $table );
			$order = $this->order_by_sql( $table );
			$batch = 80;
			while ( ( microtime( true ) - $start ) < Ovp_Chunk::DEFAULT_SECONDS && strlen( $sql ) < self::SQL_BYTES ) {
				$query = 'SELECT * FROM `' . $this->ident( $table ) . '` ' . $where . $order . $wpdb->prepare( ' LIMIT %d OFFSET %d', $batch, $offset ); // phpcs:ignore WordPress.DB.PreparedSQL -- ident + ORDER from SHOW KEYS.
				$rows  = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
				if ( null === $rows && $wpdb->last_error ) {
					return $this->error( 'mcp_bridge_query_failed', $wpdb->last_error, 400 );
				}
				if ( ! is_array( $rows ) || array() === $rows ) {
					$offset = 0;
					++$idx;
					break;
				}
				foreach ( $rows as $row ) {
					$sql   .= $this->insert_sql( $table, $row );
					++$offset;
					if ( strlen( $sql ) >= self::SQL_BYTES ) {
						break 2;
					}
				}
				if ( count( $rows ) < $batch ) {
					$offset = 0;
					++$idx;
					break;
				}
			}

			if ( strlen( $sql ) >= self::SQL_BYTES || ( microtime( true ) - $start ) >= Ovp_Chunk::DEFAULT_SECONDS ) {
				break;
			}
		}

		if ( $idx >= count( $tables ) ) {
			$sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
			$next = '';
		} else {
			$next = $this->sql_cursor( $tables[ $idx ], $offset );
		}

		return $this->success(
			Ovp_Chunk::envelope(
				array(
					'table'    => $table,
					'offset'   => $offset,
					'bytes'    => strlen( $sql ),
					'encoding' => 'base64',
					'content'  => base64_encode( $sql ),
				),
				$next
			)
		);
	}

	public function files( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$include_uploads = ! array_key_exists( 'include_uploads', $request->get_query_params() )
			|| $this->truthy( $request->get_param( 'include_uploads' ) );
		$listed            = $this->collect_content_files( $include_uploads );
		if ( $listed instanceof WP_Error ) {
			return $listed;
		}
		$name = 'wp-content.zip';
		if ( array() === $listed ) {
			return $this->success( Ovp_Chunk::envelope( array( 'mode' => 'files', 'filename' => $name, 'files' => array() ), '' ) );
		}

		$max_bytes = Ovp_Chunk::max_bytes( $request->get_param( 'max_bytes' ) );
		$max_files = Ovp_Chunk::max_items( $request->get_param( 'max_files' ), Ovp_Chunk::DEFAULT_FILES );
		[ $cur_path, $cur_off ] = $this->parse_file_cursor( (string) ( $request->get_param( 'cursor' ) ?: '' ) );
		if ( $cur_off < 0 ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'File cursor must be JSON {"p":path,"o":offset}.', 400 );
		}

		$out   = array();
		$used  = 0;
		$next  = '';
		$start = microtime( true );
		foreach ( $listed as $row ) {
			$rel  = $row['rel'];
			$full = $row['full'];
			$size = $row['size'];
			if ( '' !== $cur_path && strcmp( $rel, $cur_path ) < 0 ) {
				continue;
			}
			$offset = ( $rel === $cur_path ) ? $cur_off : 0;
			if ( $offset >= $size ) {
				continue;
			}
			$remain = $size - $offset;
			if ( array() !== $out && ( $used + min( $remain, $max_bytes ) > $max_bytes || count( $out ) >= $max_files || ( microtime( true ) - $start ) > Ovp_Chunk::DEFAULT_SECONDS ) ) {
				$next = $this->file_cursor( $rel, $offset );
				break;
			}
			$take = min( $remain, $max_bytes - $used );
			if ( $take < 1 ) {
				$next = $this->file_cursor( $rel, $offset );
				break;
			}
			$fh = fopen( $full, 'rb' );
			if ( false === $fh ) {
				return $this->error( 'mcp_bridge_read_failed', 'Could not read ' . $rel, 502 );
			}
			if ( $offset > 0 ) {
				fseek( $fh, $offset );
			}
			$raw = (string) fread( $fh, $take );
			fclose( $fh );
			$out[]   = array(
				'path'     => $rel,
				'offset'   => $offset,
				'total'    => $size,
				'bytes'    => strlen( $raw ),
				'encoding' => 'base64',
				'content'  => base64_encode( $raw ),
			);
			$used   += strlen( $raw );
			$new_off = $offset + strlen( $raw );
			if ( $new_off < $size ) {
				$next = $this->file_cursor( $rel, $new_off );
				break;
			}
		}

		return $this->success(
			Ovp_Chunk::envelope(
				array(
					'mode'     => 'files',
					'filename' => $name,
					'files'    => $out,
				),
				$next
			)
		);
	}

	/**
	 * @return list<string>
	 */
	private function prefixed_tables(): array {
		global $wpdb;
		$all = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.PreparedSQL -- static.
		$out = array();
		foreach ( is_array( $all ) ? $all : array() as $table ) {
			$table = (string) $table;
			if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
				continue;
			}
			if ( ! str_starts_with( $table, $wpdb->prefix ) ) {
				continue;
			}
			$out[] = $table;
		}
		sort( $out );
		return $out;
	}

	private function ident( string $table ): string {
		return str_replace( '`', '', $table );
	}

	private function order_by_sql( string $table ): string {
		if ( isset( $this->order_sql[ $table ] ) ) {
			return $this->order_sql[ $table ];
		}
		global $wpdb;
		$keys = $wpdb->get_results( 'SHOW KEYS FROM `' . $this->ident( $table ) . '` WHERE Key_name = \'PRIMARY\'', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- ident from allowlist.
		$cols = array();
		foreach ( is_array( $keys ) ? $keys : array() as $key ) {
			$col = (string) ( $key['Column_name'] ?? '' );
			if ( preg_match( '/^[A-Za-z0-9_]+$/', $col ) ) {
				$cols[] = '`' . $col . '`';
			}
		}
		if ( array() === $cols ) {
			$first = $wpdb->get_row( 'SHOW COLUMNS FROM `' . $this->ident( $table ) . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
			$col   = is_array( $first ) ? (string) ( $first['Field'] ?? '' ) : '';
			if ( preg_match( '/^[A-Za-z0-9_]+$/', $col ) ) {
				$cols[] = '`' . $col . '`';
			}
		}
		$sql = array() === $cols ? '' : 'ORDER BY ' . implode( ',', $cols ) . ' ';
		$this->order_sql[ $table ] = $sql;
		return $sql;
	}

	private function ephemeral_where( string $table ): string {
		global $wpdb;
		if ( $table === $wpdb->options ) {
			$skip = $this->secret_option_names();
			$in   = array();
			foreach ( $skip as $name ) {
				$in[] = $wpdb->prepare( '%s', $name );
			}
			return 'WHERE option_name NOT LIKE \'_transient_%\' AND option_name NOT LIKE \'_site_transient_%\' AND option_name NOT IN (' . implode( ',', $in ) . ') ';
		}
		return '';
	}

	/**
	 * @return list<string>
	 */
	private function secret_option_names(): array {
		return array(
			'mcp_bridge_token',
			'mcp_bridge_hop',
			'mcp_bridge_enroll',
			'mcp_bridge_enroll_pending',
			'mcp_bridge_request_log',
			// 2.0: key store (wrapped secret + pinned master keys), tier cap, relay token, replay state.
			'ovp_keystore',
			'ovp_tier_cap',
			'ovp_conn',
			'ovp_pair_lock',
			'ovp_heartbeat',
			'ovp_request_log',
			'ovp_error_log',
			'ovp_legacy_detected',
			'ovp_schema',
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function insert_sql( string $table, array $row ): string {
		global $wpdb;
		$cols = array();
		$vals = array();
		foreach ( $row as $col => $val ) {
			$col = (string) $col;
			if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $col ) ) {
				continue;
			}
			$cols[] = '`' . $col . '`';
			if ( null === $val ) {
				$vals[] = 'NULL';
			} else {
				$vals[] = "'" . $wpdb->_real_escape( (string) $val ) . "'";
			}
		}
		if ( array() === $cols ) {
			return '';
		}
		$line = 'INSERT INTO `' . $this->ident( $table ) . '` (' . implode( ',', $cols ) . ') VALUES (' . implode( ',', $vals ) . ");\n";
		if ( strlen( $line ) > 4 * 1024 * 1024 ) {
			return '-- SKIP oversized row in `' . $this->ident( $table ) . "` (>" . '4MB). Use files/clone for blobs, never hex-CONCAT meta.' . "\n";
		}
		return $line;
	}

	/**
	 * @return array{0: string, 1: int}
	 */
	private function parse_sql_cursor( string $cursor ): array {
		if ( '' === $cursor ) {
			return array( '', 0 );
		}
		$decoded = json_decode( $cursor, true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['t'] ) || ! is_string( $decoded['t'] ) ) {
			return array( '', -1 );
		}
		return array( $decoded['t'], max( 0, (int) ( $decoded['o'] ?? 0 ) ) );
	}

	private function sql_cursor( string $table, int $offset ): string {
		$encoded = wp_json_encode( array( 't' => $table, 'o' => $offset ) );
		return is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * @return array{0: string, 1: int}
	 */
	private function parse_file_cursor( string $cursor ): array {
		if ( '' === $cursor ) {
			return array( '', 0 );
		}
		$decoded = json_decode( $cursor, true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['p'] ) || ! is_string( $decoded['p'] ) ) {
			return array( '', -1 );
		}
		return array( $decoded['p'], max( 0, (int) ( $decoded['o'] ?? 0 ) ) );
	}

	private function file_cursor( string $rel, int $offset ): string {
		$encoded = wp_json_encode( array( 'p' => $rel, 'o' => $offset ) );
		return is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * @return array<int, array{rel: string, full: string, size: int}>|WP_Error
	 */
	private function collect_content_files( bool $include_uploads ): array|WP_Error {
		$root = realpath( WP_CONTENT_DIR );
		if ( false === $root || ! is_dir( $root ) ) {
			return $this->error( 'mcp_bridge_not_found', 'wp-content missing.', 404 );
		}
		$out       = array();
		$bytes     = 0;
		$max_bytes = 16 * 1024 * 1024 * 1024;
		$max_files = 80000;
		$iter      = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iter as $file ) {
			if ( ! $file instanceof SplFileInfo || ! $file->isFile() || $file->isLink() ) {
				continue;
			}
			$resolved = realpath( $file->getPathname() );
			if ( false === $resolved || ! Ovp_Net::path_inside( $resolved, $root ) ) {
				continue;
			}
			$rel = ltrim( str_replace( $root, '', $resolved ), '/\\' );
			$rel = str_replace( '\\', '/', $rel );
			if ( $this->skip_rel( $rel, $include_uploads ) ) {
				continue;
			}
			$size = (int) $file->getSize();
			if ( $size < 0 ) {
				continue;
			}
			$bytes += $size;
			if ( $bytes > $max_bytes || count( $out ) >= $max_files ) {
				return $this->error( 'mcp_bridge_file_too_large', 'wp-content export exceeds 16GB or 80k files. Use include_uploads=0.', 413 );
			}
			$out[] = array(
				'rel'  => $rel,
				'full' => $resolved,
				'size' => $size,
			);
		}
		usort(
			$out,
			static function ( array $a, array $b ): int {
				return strcmp( $a['rel'], $b['rel'] );
			}
		);
		return $out;
	}

	private function skip_rel( string $rel, bool $include_uploads ): bool {
		$rel   = strtolower( $rel );
		$parts = explode( '/', $rel );
		if ( in_array( 'node_modules', $parts, true ) || in_array( '.git', $parts, true ) ) {
			return true;
		}
		$prefixes = array(
			'cache/',
			'upgrade/',
			'upgrade-temp-backup/',
			'uploads/wc-logs/',
			'uploads/wc-status/',
			'uploads/mcp-bridge/backups/',
			'uploads/mcp-bridge/exports/',
			'uploads/cache/',
			'uploads/wflogs/',
			'uploads/wp-rocket/',
			'uploads/litespeed/',
		);
		foreach ( $prefixes as $prefix ) {
			if ( str_starts_with( $rel, $prefix ) || $rel === rtrim( $prefix, '/' ) ) {
				return true;
			}
		}
		$base = basename( $rel );
		if ( str_ends_with( $base, '.log' ) || str_ends_with( $base, '.sql' ) || in_array( $base, array( '.ds_store', 'thumbs.db', 'fatal-latest.json', 'wp-config.php' ), true ) ) {
			return true;
		}
		if ( ! $include_uploads && str_starts_with( $rel, 'uploads/' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * @return list<string>
	 */
	private function exclude_notes(): array {
		return array(
			'wp-config.php / remote DB creds (never exported)',
			'wp-admin + wp-includes (fresh core on dest)',
			'cache/ upgrade/ logs / mcp-bridge backups',
			'transients + mcp_bridge token/hop/enroll/log options + ovp_ key store, tier cap and connection options',
			'per-request chunks only — 4GB files stream; SQL rows >4MB skipped',
		);
	}

	private function suggest_dest_domain( string $home_host ): string {
		$first = explode( '.', $home_host )[0];
		$first = preg_replace( '/[^a-z0-9-]/', '', $first ) ?? '';
		if ( '' === $first ) {
			$first = 'clone';
		}
		return $first . '.dev';
	}

	private function truthy( mixed $raw ): bool {
		if ( is_bool( $raw ) ) {
			return $raw;
		}
		$s = strtolower( (string) $raw );
		return in_array( $s, array( '1', 'true', 'yes', 'on' ), true );
	}
}
