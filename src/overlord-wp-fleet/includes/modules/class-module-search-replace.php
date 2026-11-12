<?php
/**
 * /ovp/v1/search-replace — serialized-aware host rewrite (Better Search Replace
 * style) plus a bounded wp-content file pass. JSON blobs are str_replaced only —
 * never json_decode/re-encode (that kills Elementor `__dynamic__`).
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ovp_Module_Search_Replace extends Ovp_Module_Base {

	private const SKIP_OPTION_PREFIX = 'mcp_bridge_';

	private const TEXT_TYPES = array(
		'char',
		'varchar',
		'tinytext',
		'text',
		'mediumtext',
		'longtext',
		'tinyblob',
		'blob',
		'mediumblob',
		'longblob',
	);

	private const FILE_EXTS = array( 'php', 'css', 'js', 'json', 'html', 'htm', 'svg', 'txt', 'xml' );

	private const FILE_CAP = 2097152;

	private bool $filename_tokens = false;

	/**
	 * Optional walk restriction (1.7.0): only these post ids / tables / option names.
	 *
	 * @var array{post_ids: array<int, int>, tables: array<int, string>, option_names: array<int, string>}|null
	 */
	private ?array $scope = null;

	/** @var array<int, string> */
	private array $extra_skip_options = array();

	/**
	 * Replace full filenames (not host prefixes). Basenames must be bounded so
	 * `file.png` does not hit `file.png.webp` or `file-1.png`.
	 *
	 * @param array<string, string> $pairs old basename => new basename
	 * @param array<int, string>    $extra_skip_options option_name values to leave untouched
	 * @return array{tables: array<string, int>, rows: int, replacements: int, skipped: int, files: array<string, int>, elementor_cleared: bool}
	 */
	public function rewrite_filename_pairs( array $pairs, bool $dry_run, array $extra_skip_options = array(), bool $clear_elementor = false ): array {
		uksort(
			$pairs,
			static function ( string $a, string $b ): int {
				return strlen( $b ) <=> strlen( $a );
			}
		);
		$this->filename_tokens    = true;
		$this->extra_skip_options = array_values( $extra_skip_options );
		$db                       = array(
			'tables'       => array(),
			'rows'         => 0,
			'replacements' => 0,
			'skipped'      => 0,
		);
		$files                    = array();
		$cleared                  = false;
		try {
			$db    = $this->walk_database( $pairs, $dry_run );
			$files = $this->walk_files(
				$pairs,
				$dry_run,
				array( WP_CONTENT_DIR . '/uploads/elementor/css' )
			);
			$cleared = false;
			if ( ! $dry_run && $clear_elementor ) {
				$cleared = $this->clear_elementor_cache();
			}
		} finally {
			$this->filename_tokens    = false;
			$this->extra_skip_options = array();
		}
		return array(
			'tables'            => $db['tables'],
			'rows'              => $db['rows'],
			'replacements'      => $db['replacements'],
			'skipped'           => $db['skipped'],
			'files'             => $files,
			'elementor_cleared' => $cleared,
		);
	}

	public function register_routes(): void {
		$this->route( 'POST', 'search-replace', array( $this, 'run' ) );
	}

	public function run( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}

		$body          = (array) $request->get_json_params();
		$searches      = $this->normalize_searches( $body['searches'] ?? null );
		$replace_host  = strtolower( trim( (string) ( $body['replace_host'] ?? '' ) ) );
		$dry_run       = ! array_key_exists( 'dry_run', $body ) || (bool) $body['dry_run'];
		$include_files = (bool) ( $body['include_files'] ?? false );
		$clear_el      = (bool) ( $body['clear_elementor'] ?? false );
		$confirm       = ! empty( $body['confirm'] );

		$scope = $this->parse_scope( $body['scope'] ?? null );
		if ( $scope instanceof WP_Error ) {
			return $scope;
		}
		if ( null !== $scope && $include_files ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'scope cannot be combined with include_files.', 400 );
		}

		if ( ! $dry_run && ! $confirm ) {
			return $this->error( 'mcp_bridge_confirm_required', 'search_replace apply needs confirm=true (dry_run defaults true).', 403 );
		}

		if ( array() === $searches ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Provide non-empty "searches" hostnames.', 400 );
		}
		if ( '' === $replace_host || ! $this->is_hostname( $replace_host ) ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Provide a valid "replace_host" hostname.', 400 );
		}

		$home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		if ( str_contains( $replace_host, '.dev' ) && $home_host !== $replace_host ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'replace_host .dev only when it already is the site home host (clone dest).', 400 );
		}
		if ( $home_host !== $replace_host ) {
			return $this->error(
				'mcp_bridge_host_mismatch',
				'replace_host must match the site home host (' . $home_host . ').',
				400
			);
		}

		usort(
			$searches,
			static function ( string $a, string $b ): int {
				return strlen( $b ) <=> strlen( $a );
			}
		);

		$pairs = $this->build_pairs( $searches, $replace_host );
		if ( array() === $pairs ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'No URL variants to replace.', 400 );
		}

		$this->scope = $scope;
		try {
			$db = $this->walk_database( $pairs, $dry_run );
		} finally {
			$this->scope = null;
		}
		$files = $include_files ? $this->walk_files( $pairs, $dry_run ) : array();

		$elementor_cleared = false;
		if ( ! $dry_run ) {
			if ( $clear_el ) {
				$elementor_cleared = $this->clear_elementor_cache();
			}
			if ( function_exists( 'wp_cache_flush' ) ) {
				wp_cache_flush();
			}
		}

		return $this->success(
			array(
				'dry_run'            => $dry_run,
				'replace_host'       => $replace_host,
				'searches'           => $searches,
				'variants'           => array_keys( $pairs ),
				'tables'             => $db['tables'],
				'rows_changed'       => $db['rows'],
				'replacements'       => $db['replacements'],
				'skipped_serialized' => $db['skipped'],
				'files'              => $files,
				'elementor_cleared'  => $elementor_cleared,
				'scope'              => $scope,
			)
		);
	}

	/**
	 * @return array{post_ids: array<int, int>, tables: array<int, string>, option_names: array<int, string>}|WP_Error|null
	 */
	private function parse_scope( mixed $raw ): array|WP_Error|null {
		global $wpdb;
		if ( null === $raw ) {
			return null;
		}
		if ( ! is_array( $raw ) ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'scope must be an object.', 400 );
		}
		$ids = array();
		foreach ( is_array( $raw['post_ids'] ?? null ) ? $raw['post_ids'] : array() as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$ids[] = (int) $id;
			}
		}
		$tables = array();
		foreach ( is_array( $raw['tables'] ?? null ) ? $raw['tables'] : array() as $t ) {
			$t = (string) $t;
			if ( ! $this->safe_ident( $t ) || ! str_starts_with( $t, (string) $wpdb->prefix ) ) {
				return $this->error( 'mcp_bridge_invalid_payload', 'scope.tables must be prefixed table names: ' . $t, 400 );
			}
			$tables[] = $t;
		}
		$names = array();
		foreach ( is_array( $raw['option_names'] ?? null ) ? $raw['option_names'] : array() as $n ) {
			if ( is_string( $n ) && '' !== $n ) {
				$names[] = $n;
			}
		}
		if ( array() === $ids && array() === $tables && array() === $names ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'scope is present but empty; give post_ids, tables or option_names.', 400 );
		}
		return array(
			'post_ids'     => array_values( array_unique( $ids ) ),
			'tables'       => array_values( array_unique( $tables ) ),
			'option_names' => array_values( array_unique( $names ) ),
		);
	}

	/**
	 * Row filter for the current scope. null = table not walked, '' = whole table.
	 *
	 * @param array<int, string> $args appended prepare() args
	 */
	private function scope_clause( string $table, array &$args ): ?string {
		global $wpdb;
		if ( null === $this->scope ) {
			return '';
		}
		if ( in_array( $table, $this->scope['tables'], true ) ) {
			return '';
		}
		$ids = $this->scope['post_ids'];
		if ( array() !== $ids && $table === $wpdb->posts ) {
			return '`ID` IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')';
		}
		if ( array() !== $ids && $table === $wpdb->postmeta ) {
			return '`post_id` IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')';
		}
		$names = $this->scope['option_names'];
		if ( array() !== $names && $table === $wpdb->options ) {
			foreach ( $names as $n ) {
				$args[] = $n;
			}
			return '`option_name` IN (' . implode( ',', array_fill( 0, count( $names ), '%s' ) ) . ')';
		}
		return null;
	}

	/**
	 * @param mixed $raw
	 * @return array<int, string>
	 */
	private function normalize_searches( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $item ) {
			$host = strtolower( trim( (string) $item ) );
			$host = preg_replace( '#^https?://#', '', $host ) ?? $host;
			$host = rtrim( $host, '/' );
			if ( '' === $host || ! $this->is_hostname( $host ) ) {
				continue;
			}
			if ( $host === strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) ) {
				continue;
			}
			$out[] = $host;
		}
		return array_values( array_unique( $out ) );
	}

	private function is_hostname( string $host ): bool {
		return (bool) preg_match( '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/i', $host );
	}

	/**
	 * URL-prefix variants only — never bare host (keeps emails like info@example.com).
	 *
	 * @param array<int, string> $hosts
	 * @return array<string, string>
	 */
	private function build_pairs( array $hosts, string $replace_host ): array {
		$pairs = array();
		foreach ( $hosts as $host ) {
			$plain = array(
				'https://' . $host => 'https://' . $replace_host,
				'http://' . $host  => 'http://' . $replace_host,
				'//' . $host       => '//' . $replace_host,
			);
			foreach ( $plain as $from => $to ) {
				$pairs[ $from ] = $to;
				$pairs[ str_replace( '/', '\\/', $from ) ] = str_replace( '/', '\\/', $to );
			}
		}
		return $pairs;
	}

	/**
	 * @param array<string, string> $pairs
	 * @return array{tables: array<string, int>, rows: int, replacements: int, skipped: int}
	 */
	private function walk_database( array $pairs, bool $dry_run ): array {
		global $wpdb;

		$schema = $this->text_columns();
		$pks    = $this->primary_keys();
		$tables = array();
		$rows   = 0;
		$reps   = 0;
		$skip   = 0;

		foreach ( $schema as $table => $columns ) {
			if ( ! isset( $pks[ $table ] ) || array() === $pks[ $table ] ) {
				continue;
			}
			$probe = array();
			if ( null === $this->scope_clause( $table, $probe ) ) {
				continue;
			}
			$table_count = 0;
			foreach ( $columns as $column ) {
				$result = $this->rewrite_column( $table, $column, $pks[ $table ], $pairs, $dry_run );
				$table_count += $result['replacements'];
				$rows        += $result['rows'];
				$reps        += $result['replacements'];
				$skip        += $result['skipped'];
			}
			if ( $table_count > 0 ) {
				$tables[ $table ] = $table_count;
			}
		}

		unset( $wpdb );
		return array(
			'tables'       => $tables,
			'rows'         => $rows,
			'replacements' => $reps,
			'skipped'      => $skip,
		);
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	private function text_columns(): array {
		global $wpdb;
		$db  = $wpdb->dbname;
		$sql = $wpdb->prepare(
			'SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
			WHERE TABLE_SCHEMA = %s AND DATA_TYPE IN (' . implode( ',', array_fill( 0, count( self::TEXT_TYPES ), '%s' ) ) . ')',
			$db,
			...self::TEXT_TYPES
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- identifiers from INFORMATION_SCHEMA, values prepared.
		$out  = array();
		$prefix = (string) $wpdb->prefix;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$table = (string) ( $row['TABLE_NAME'] ?? '' );
			$col   = (string) ( $row['COLUMN_NAME'] ?? '' );
			if ( ! $this->safe_ident( $table ) || ! $this->safe_ident( $col ) ) {
				continue;
			}
			if ( '' === $prefix || ! str_starts_with( $table, $prefix ) ) {
				continue;
			}
			$out[ $table ][] = $col;
		}
		return $out;
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	private function primary_keys(): array {
		global $wpdb;
		$sql  = $wpdb->prepare(
			'SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
			WHERE TABLE_SCHEMA = %s AND CONSTRAINT_NAME = %s ORDER BY TABLE_NAME, ORDINAL_POSITION',
			$wpdb->dbname,
			'PRIMARY'
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- prepared schema lookup.
		$out  = array();
		$prefix = (string) $wpdb->prefix;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$table = (string) ( $row['TABLE_NAME'] ?? '' );
			$col   = (string) ( $row['COLUMN_NAME'] ?? '' );
			if ( ! $this->safe_ident( $table ) || ! $this->safe_ident( $col ) ) {
				continue;
			}
			if ( '' === $prefix || ! str_starts_with( $table, $prefix ) ) {
				continue;
			}
			$out[ $table ][] = $col;
		}
		return $out;
	}

	/**
	 * @param array<int, string>    $pk_cols
	 * @param array<string, string> $pairs
	 * @return array{rows: int, replacements: int, skipped: int}
	 */
	private function rewrite_column( string $table, string $column, array $pk_cols, array $pairs, bool $dry_run ): array {
		global $wpdb;

		$likes = array();
		$args  = array();
		foreach ( array_keys( $pairs ) as $from ) {
			$likes[] = '`' . $column . '` LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $from ) . '%';
		}
		$select_cols = array_unique( array_merge( $pk_cols, array( $column ) ) );
		if ( $wpdb->options === $table ) {
			$select_cols[] = 'option_name';
		}
		$quoted = array();
		foreach ( $select_cols as $c ) {
			$quoted[] = '`' . $c . '`';
		}

		$scope_args   = array();
		$scope_clause = $this->scope_clause( $table, $scope_args );
		$where_sql    = '(' . implode( ' OR ', $likes ) . ')';
		if ( null !== $scope_clause && '' !== $scope_clause ) {
			$where_sql .= ' AND ' . $scope_clause;
			$args       = array_merge( $args, $scope_args );
		}
		$sql  = 'SELECT ' . implode( ',', $quoted ) . ' FROM `' . $table . '` WHERE ' . $where_sql;
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- table/column ident-checked; LIKE values prepared.

		$changed = 0;
		$reps    = 0;
		$skip    = 0;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( $wpdb->options === $table ) {
				$name = (string) ( $row['option_name'] ?? '' );
				if ( str_starts_with( $name, self::SKIP_OPTION_PREFIX ) ) {
					continue;
				}
				if ( in_array( $name, $this->extra_skip_options, true ) ) {
					continue;
				}
			}
			$original = (string) ( $row[ $column ] ?? '' );
			$count    = 0;
			$skipped  = false;
			$updated  = $this->replace_in_value( $original, $pairs, $count, $skipped );
			if ( $skipped ) {
				++$skip;
			}
			if ( $count < 1 || $updated === $original ) {
				continue;
			}
			++$changed;
			$reps += $count;
			if ( $dry_run ) {
				continue;
			}
			$where = array();
			foreach ( $pk_cols as $pk ) {
				$where[ $pk ] = $row[ $pk ];
			}
			$wpdb->update( $table, array( $column => $updated ), $where );
		}

		return array(
			'rows'         => $changed,
			'replacements' => $reps,
			'skipped'      => $skip,
		);
	}

	/**
	 * @param array<string, string> $pairs
	 */
	private function replace_in_value( mixed $value, array $pairs, int &$count, bool &$skipped ): mixed {
		if ( is_string( $value ) ) {
			if ( is_serialized( $value ) ) {
				if ( preg_match( '/(?:^|;|{)[OC]:/', $value ) ) {
					$skipped = true;
					return $value;
				}
				$un = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- intentional probe.
				if ( $un instanceof \__PHP_Incomplete_Class ) {
					$skipped = true;
					return $value;
				}
				if ( false !== $un || 'b:0;' === $value ) {
					$child_skip = false;
					$un         = $this->replace_in_value( $un, $pairs, $count, $child_skip );
					if ( $child_skip ) {
						$skipped = true;
						return $value;
					}
					return serialize( $un );
				}
				$skipped = true;
				return $value;
			}
			if ( $this->filename_tokens ) {
				return $this->replace_filename_token( $value, $pairs, $count );
			}
			return $this->replace_delimited( $value, $pairs, $count );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->replace_in_value( $item, $pairs, $count, $skipped );
				if ( $skipped ) {
					return $value;
				}
			}
			return $value;
		}
		if ( is_object( $value ) ) {
			if ( $value instanceof \__PHP_Incomplete_Class ) {
				$skipped = true;
				return $value;
			}
			foreach ( get_object_vars( $value ) as $key => $item ) {
				$value->{$key} = $this->replace_in_value( $item, $pairs, $count, $skipped );
				if ( $skipped ) {
					return $value;
				}
			}
			return $value;
		}
		return $value;
	}

	/**
	 * Replace URL prefixes only when the host ends at a delimiter so
	 * `https://example` cannot smash `https://example.com`.
	 *
	 * @param array<string, string> $pairs
	 */
	private function replace_delimited( string $value, array $pairs, int &$count ): string {
		$delims = array( '/', ':', '?', '#', '"', "'", '\\' );
		foreach ( $pairs as $from => $to ) {
			$offset = 0;
			while ( false !== ( $pos = strpos( $value, $from, $offset ) ) ) {
				$end  = $pos + strlen( $from );
				$next = $end < strlen( $value ) ? $value[ $end ] : '';
				if ( '' !== $next && ! in_array( $next, $delims, true ) ) {
					$offset = $end;
					continue;
				}
				$value = substr_replace( $value, $to, $pos, strlen( $from ) );
				++$count;
				$offset = $pos + strlen( $to );
			}
		}
		return $value;
	}

	/**
	 * Full-basename swap. A following `.` / `-` / `_` keeps `file.png.webp` and
	 * `file-1.png` intact when the pair is only `file.png`.
	 *
	 * @param array<string, string> $pairs
	 */
	private function replace_filename_token( string $value, array $pairs, int &$count ): string {
		foreach ( $pairs as $from => $to ) {
			if ( '' === $from || $from === $to ) {
				continue;
			}
			$offset = 0;
			$length = strlen( $from );
			while ( false !== ( $pos = strpos( $value, $from, $offset ) ) ) {
				$end  = $pos + $length;
				$next = $end < strlen( $value ) ? $value[ $end ] : '';
				if ( $this->is_filename_char( $next ) || ! $this->filename_boundary_before( $value, $pos ) ) {
					$offset = $end;
					continue;
				}
				$value = substr_replace( $value, $to, $pos, $length );
				++$count;
				$offset = $pos + strlen( $to );
			}
		}
		return $value;
	}

	private function is_filename_char( string $ch ): bool {
		if ( '' === $ch ) {
			return false;
		}
		return 1 === preg_match( '/[A-Za-z0-9_.-]/', $ch );
	}

	/**
	 * True when the basename is not glued to a longer filename.
	 * `%2Fname.png` counts as a boundary even though the previous char is `F`.
	 */
	private function filename_boundary_before( string $value, int $pos ): bool {
		if ( $pos <= 0 ) {
			return true;
		}
		$prev = $value[ $pos - 1 ];
		if ( ! $this->is_filename_char( $prev ) ) {
			return true;
		}
		return $pos >= 3 && '%2F' === substr( $value, $pos - 3, 3 );
	}

	/**
	 * @param array<string, string>   $pairs
	 * @param array<int, string>|null $roots
	 * @return array<string, int>
	 */
	private function walk_files( array $pairs, bool $dry_run, ?array $roots = null ): array {
		$roots = $roots ?? array(
			WP_CONTENT_DIR . '/uploads',
			WP_CONTENT_DIR . '/themes',
			WP_CONTENT_DIR . '/plugins',
		);
		$out   = array();
		foreach ( $roots as $root ) {
			if ( ! is_dir( $root ) ) {
				continue;
			}
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $file ) {
				if ( ! $file instanceof \SplFileInfo || ! $file->isFile() || $file->isLink() ) {
					continue;
				}
				$path         = $file->getPathname();
				$real         = realpath( $path );
				$content_root = realpath( WP_CONTENT_DIR );
				if ( ! is_string( $real ) || ! is_string( $content_root ) || ! Ovp_Net::path_inside( $real, $content_root ) ) {
					continue;
				}
				$path = $real;
				if ( str_contains( str_replace( '\\', '/', $path ), '/wp-mcp-bridge/' ) ) {
					continue;
				}
				if ( str_contains( $path, '/vendor/' ) || str_contains( $path, '/node_modules/' ) ) {
					continue;
				}
				$ext = strtolower( (string) $file->getExtension() );
				if ( ! in_array( $ext, self::FILE_EXTS, true ) ) {
					continue;
				}
				$size = (int) $file->getSize();
				if ( $size < 1 || $size > self::FILE_CAP ) {
					continue;
				}
				$bytes = file_get_contents( $path );
				if ( ! is_string( $bytes ) || str_contains( $bytes, "\0" ) ) {
					continue;
				}
				$count   = 0;
				$skipped = false;
				$updated = $this->replace_in_value( $bytes, $pairs, $count, $skipped );
				if ( $skipped || $count < 1 || ! is_string( $updated ) || $updated === $bytes ) {
					continue;
				}
				$rel         = ltrim( str_replace( WP_CONTENT_DIR, '', $path ), '/\\' );
				$out[ $rel ] = $count;
				if ( ! $dry_run ) {
					file_put_contents( $path, $updated );
				}
			}
		}
		return $out;
	}

	private function clear_elementor_cache(): bool {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}
		$plugin = \Elementor\Plugin::$instance;
		if ( ! isset( $plugin->files_manager ) || ! is_object( $plugin->files_manager ) ) {
			return false;
		}
		if ( ! method_exists( $plugin->files_manager, 'clear_cache' ) ) {
			return false;
		}
		$plugin->files_manager->clear_cache();
		return true;
	}

	private function safe_ident( string $name ): bool {
		return (bool) preg_match( '/^[A-Za-z0-9_]+$/', $name );
	}
}
