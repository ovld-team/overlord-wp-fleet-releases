<?php
/**
 * /ovp/v1/security/* (2.1.0): the free security tools.
 *
 *   security/audit       T0  read-only checks; booleans and counts, never salt values or user lists
 *   security/integrity   T0  WordPress core files against api.wordpress.org checksums
 *   security/harden      T2  switch protections on or off (needs confirm=true)
 *   security/login-limit T2  failed-login lockout per client IP
 *
 * Protections are stored in two options (ovp_security_hardening, ovp_login_limit) and applied by
 * hooks registered at plugin load (Ovp_Module_Security::hooks). Everything is reversible by
 * calling the same route again; deleting the plugin removes the options (uninstall.php, ovp_*).
 * The client IP is REMOTE_ADDR only; X-Forwarded-For is never trusted.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ovp_Module_Security extends Ovp_Module_Base {

	/** Tier per tool, consumed by the route-table test. */
	public const OVP_TIER = array(
		'security_audit'  => 'T0',
		'file_integrity'  => 'T0',
		'security_harden' => 'T2',
		'login_limit_set' => 'T2',
	);

	public const HARDEN_OPTION = 'ovp_security_hardening';
	public const LIMIT_OPTION  = 'ovp_login_limit';
	public const HARDEN_KEYS   = array( 'xmlrpc', 'file_editor', 'author_enum', 'headers' );

	private const CHECKSUM_URL  = 'https://api.wordpress.org/core/checksums/1.0/';
	private const LIST_CAP      = 100;
	private const SCAN_CAP      = 30000;
	private const SALT_CONSTANTS = array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' );

	/* ---------- hooks (plugin load) ---------- */

	/** @return array<string,bool> */
	public static function hardening(): array {
		$v   = get_option( self::HARDEN_OPTION, array() );
		$v   = is_array( $v ) ? $v : array();
		$out = array();
		foreach ( self::HARDEN_KEYS as $k ) {
			$out[ $k ] = ! empty( $v[ $k ] );
		}
		return $out;
	}

	/** @return array{enabled:bool,max_attempts:int,lockout_minutes:int} */
	public static function login_limit(): array {
		$v = get_option( self::LIMIT_OPTION, array() );
		$v = is_array( $v ) ? $v : array();
		return array(
			'enabled'         => ! empty( $v['enabled'] ),
			'max_attempts'    => max( 3, min( 20, (int) ( $v['max_attempts'] ?? 5 ) ) ),
			'lockout_minutes' => max( 1, min( 1440, (int) ( $v['lockout_minutes'] ?? 15 ) ) ),
		);
	}

	public static function hooks(): void {
		$h = self::hardening();
		if ( $h['xmlrpc'] ) {
			add_filter( 'xmlrpc_enabled', static fn(): bool => false );
			// xmlrpc_enabled only refuses methods that need a login; pingback.ping and system.multicall stay open unless the method list is emptied too.
			add_filter( 'xmlrpc_methods', static fn(): array => array() );
		}
		if ( $h['file_editor'] ) {
			add_filter( 'map_meta_cap', array( __CLASS__, 'deny_file_editor' ), 10, 2 );
		}
		if ( $h['author_enum'] ) {
			add_action( 'template_redirect', array( __CLASS__, 'block_author_query' ), 0 );
			add_filter( 'rest_endpoints', array( __CLASS__, 'hide_user_endpoints' ) );
			add_filter( 'wp_sitemaps_add_provider', array( __CLASS__, 'drop_users_sitemap' ), 10, 2 );
		}
		if ( $h['headers'] ) {
			add_action( 'send_headers', array( __CLASS__, 'send_security_headers' ) );
		}
		if ( self::login_limit()['enabled'] ) {
			add_filter( 'authenticate', array( __CLASS__, 'login_gate' ), 100, 2 );
			add_action( 'wp_login_failed', array( __CLASS__, 'login_failed' ) );
			add_action( 'wp_login', array( __CLASS__, 'login_ok' ) );
		}
	}

	/** @param array<int,string> $caps */
	public static function deny_file_editor( $caps, $cap = '' ) {
		return in_array( $cap, array( 'edit_files', 'edit_plugins', 'edit_themes' ), true ) ? array( 'do_not_allow' ) : $caps;
	}

	/** ?author=N is the classic user-name probe: send anonymous visitors home. */
	public static function block_author_query(): void {
		if ( isset( $_GET['author'] ) && ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- only presence is tested.
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	}

	/** @param mixed $endpoints */
	public static function hide_user_endpoints( $endpoints ) {
		if ( is_array( $endpoints ) && ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) ) {
			foreach ( array_keys( $endpoints ) as $route ) {
				if ( is_string( $route ) && 1 === preg_match( '#^/wp/v2/users(/|$)#', $route ) ) {
					unset( $endpoints[ $route ] );
				}
			}
		}
		return $endpoints;
	}

	/** @param mixed $provider */
	public static function drop_users_sitemap( $provider, $name = '' ) {
		return 'users' === $name ? false : $provider;
	}

	public static function send_security_headers(): void {
		if ( headers_sent() ) {
			return;
		}
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'Permissions-Policy: camera=(), microphone=(), geolocation=()' );
	}

	/* ---------- login limiter ---------- */

	private static function ip_key(): string {
		$ip = Ovp_Net::remote_addr();
		return '' === $ip ? '' : substr( hash( 'sha256', $ip ), 0, 32 );
	}

	public static function is_locked( string $key ): bool {
		return '' !== $key && ! empty( get_transient( 'ovp_ll_lock_' . $key ) );
	}

	/**
	 * Runs after every other authenticate filter: a locked IP fails even with the right password.
	 *
	 * @param mixed $user
	 * @return mixed
	 */
	public static function login_gate( $user, $username = '' ) {
		$lim = self::login_limit();
		if ( $lim['enabled'] && self::is_locked( self::ip_key() ) ) {
			return new WP_Error( 'ovp_login_locked', 'Too many failed login attempts. Try again later.' );
		}
		return $user;
	}

	public static function login_failed( $username = '' ): void {
		$lim = self::login_limit();
		$key = self::ip_key();
		if ( ! $lim['enabled'] || '' === $key || self::is_locked( $key ) ) {
			return;
		}
		$ttl = $lim['lockout_minutes'] * 60;
		$n   = (int) get_transient( 'ovp_ll_cnt_' . $key ) + 1;
		if ( $n >= $lim['max_attempts'] ) {
			set_transient( 'ovp_ll_lock_' . $key, 1, $ttl );
			delete_transient( 'ovp_ll_cnt_' . $key );
			return;
		}
		set_transient( 'ovp_ll_cnt_' . $key, $n, $ttl );
	}

	public static function login_ok( $login = '' ): void {
		$key = self::ip_key();
		if ( '' !== $key ) {
			delete_transient( 'ovp_ll_cnt_' . $key );
		}
	}

	/* ---------- routes ---------- */

	public function register_routes(): void {
		$this->route( 'GET', 'security/audit', array( $this, 'audit' ) );
		$this->route( 'GET', 'security/integrity', array( $this, 'integrity' ) );
		$this->route( 'POST', 'security/harden', array( $this, 'harden' ) );
		$this->route( 'POST', 'security/login-limit', array( $this, 'login_limit_set' ) );
	}

	/**
	 * @param list<array<string,string>> $checks
	 */
	private function add( array &$checks, string $id, string $status, string $message ): void {
		$checks[] = array( 'id' => $id, 'status' => $status, 'message' => $message );
	}

	private static function perm( string $path ): ?int {
		if ( ! file_exists( $path ) ) {
			return null;
		}
		$p = @fileperms( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return false === $p ? null : $p & 0777;
	}

	/** Read-only. Every finding is a boolean, a count or a version; no salt value, user name or path outside ABSPATH is returned. */
	public function audit(): WP_REST_Response {
		global $wp_version;
		$c = array();

		$core = get_site_transient( 'update_core' );
		$upd  = is_object( $core ) && is_array( $core->updates ?? null ) ? $core->updates : array();
		$new  = '';
		foreach ( $upd as $u ) {
			if ( is_object( $u ) && 'upgrade' === ( $u->response ?? '' ) ) {
				$new = (string) ( $u->current ?? '' );
				break;
			}
		}
		'' === $new
			? $this->add( $c, 'wp_version', 'ok', 'WordPress ' . $wp_version . ' has no pending core update.' )
			: $this->add( $c, 'wp_version', 'warn', 'WordPress ' . $wp_version . ' is installed; ' . $new . ' is available.' );

		version_compare( PHP_VERSION, '8.1', '>=' )
			? $this->add( $c, 'php_version', 'ok', 'PHP ' . PHP_VERSION . ' is still supported.' )
			: $this->add( $c, 'php_version', 'fail', 'PHP ' . PHP_VERSION . ' no longer receives security fixes.' );

		$dbg  = defined( 'WP_DEBUG' ) && constant( 'WP_DEBUG' );
		$disp = $dbg && ( ! defined( 'WP_DEBUG_DISPLAY' ) || constant( 'WP_DEBUG_DISPLAY' ) );
		$dlog = $dbg && defined( 'WP_DEBUG_LOG' ) && true === constant( 'WP_DEBUG_LOG' );
		! $dbg
			? $this->add( $c, 'debug', 'ok', 'WP_DEBUG is off.' )
			: $this->add( $c, 'debug', $disp ? 'fail' : 'warn', 'WP_DEBUG is on' . ( $disp ? ' and errors are shown to visitors' : '' ) . ( $dlog ? '; wp-content/debug.log is written and may be publicly readable' : '' ) . '.' );
		defined( 'SCRIPT_DEBUG' ) && constant( 'SCRIPT_DEBUG' )
			? $this->add( $c, 'script_debug', 'warn', 'SCRIPT_DEBUG is on.' )
			: $this->add( $c, 'script_debug', 'ok', 'SCRIPT_DEBUG is off.' );

		$h        = self::hardening();
		$edit_off = ( defined( 'DISALLOW_FILE_EDIT' ) && constant( 'DISALLOW_FILE_EDIT' ) ) || $h['file_editor'];
		$edit_off
			? $this->add( $c, 'file_editor', 'ok', 'The theme and plugin file editor is disabled.' )
			: $this->add( $c, 'file_editor', 'warn', 'The theme and plugin file editor is enabled (security_harden can disable it).' );

		$xml = function_exists( 'apply_filters' ) ? (bool) apply_filters( 'xmlrpc_enabled', true ) : true;
		$xml
			? $this->add( $c, 'xmlrpc', 'warn', 'XML-RPC is enabled (security_harden can disable it).' )
			: $this->add( $c, 'xmlrpc', 'ok', 'XML-RPC is disabled.' );

		false !== get_user_by( 'login', 'admin' )
			? $this->add( $c, 'admin_user', 'warn', 'A user named "admin" exists; it is the first name attackers try.' )
			: $this->add( $c, 'admin_user', 'ok', 'No user is named "admin".' );

		$weak = 0;
		foreach ( self::SALT_CONSTANTS as $s ) {
			$v = defined( $s ) ? (string) constant( $s ) : '';
			if ( strlen( $v ) < 32 || str_contains( $v, 'put your unique phrase here' ) ) {
				++$weak;
			}
		}
		0 === $weak
			? $this->add( $c, 'salts', 'ok', 'All 8 authentication keys and salts are set and not the default.' )
			: $this->add( $c, 'salts', 'fail', $weak . ' of 8 authentication keys or salts are missing, short or the default text.' );

		$perm_bad  = array(); // World-writable: fail.
		$perm_warn = array(); // Group-writable or world-readable (0644 is the WordPress default): advice only.
		$cfg       = is_file( ABSPATH . 'wp-config.php' ) ? ABSPATH . 'wp-config.php' : dirname( ABSPATH ) . '/wp-config.php';
		$pc        = self::perm( $cfg );
		if ( null !== $pc && ( $pc & 0002 ) ) {
			$perm_bad[] = 'wp-config.php is ' . decoct( $pc ) . ' (world writable)';
		} elseif ( null !== $pc && ( $pc & 0020 ) ) {
			$perm_warn[] = 'wp-config.php is ' . decoct( $pc ) . ' (group writable)';
		}
		if ( null !== $pc && ( $pc & 0004 ) ) {
			$perm_warn[] = 'wp-config.php is ' . decoct( $pc ) . ' (world readable; 640 or 600 is safer where the host allows it)';
		}
		$pw = self::perm( WP_CONTENT_DIR );
		if ( null !== $pw && ( $pw & 0002 ) ) {
			$perm_bad[] = 'wp-content is ' . decoct( $pw ) . ' (world writable)';
		}
		$ph = self::perm( ABSPATH . '.htaccess' );
		if ( null !== $ph && ( $ph & 0002 ) ) {
			$perm_bad[] = '.htaccess is ' . decoct( $ph ) . ' (world writable)';
		}
		if ( array() !== $perm_bad ) {
			$this->add( $c, 'file_permissions', 'fail', implode( '; ', array_slice( array_merge( $perm_bad, $perm_warn ), 0, 4 ) ) . '.' );
		} elseif ( array() !== $perm_warn ) {
			$this->add( $c, 'file_permissions', 'warn', implode( '; ', array_slice( $perm_warn, 0, 4 ) ) . '.' );
		} else {
			$this->add( $c, 'file_permissions', 'ok', 'wp-config.php, wp-content and .htaccess have sane permissions.' );
		}

		str_starts_with( home_url(), 'https://' )
			? $this->add( $c, 'https', 'ok', 'The site address uses https.' )
			: $this->add( $c, 'https', 'warn', 'The site address does not use https.' );

		$out_pl = array();
		$ut     = get_site_transient( 'update_plugins' );
		if ( is_object( $ut ) && is_array( $ut->response ?? null ) ) {
			foreach ( $ut->response as $file => $info ) {
				$out_pl[] = array( 'plugin' => (string) $file, 'new_version' => is_object( $info ) ? (string) ( $info->new_version ?? '' ) : '' );
			}
		}
		array() === $out_pl
			? $this->add( $c, 'plugins', 'ok', 'No plugin update is pending.' )
			: $this->add( $c, 'plugins', 'warn', count( $out_pl ) . ' plugin(s) have an update available.' );

		$ll = self::login_limit();
		$ll['enabled']
			? $this->add( $c, 'login_limit', 'ok', 'Failed logins lock a client out after ' . $ll['max_attempts'] . ' attempts for ' . $ll['lockout_minutes'] . ' minutes.' )
			: $this->add( $c, 'login_limit', 'warn', 'Failed-login lockout is off (login_limit_set can turn it on).' );

		$n = array( 'ok' => 0, 'warn' => 0, 'fail' => 0 );
		foreach ( $c as $row ) {
			++$n[ $row['status'] ];
		}
		return $this->success(
			array(
				'summary'          => $n,
				'checks'           => $c,
				'outdated_plugins' => array_slice( $out_pl, 0, 50 ),
				'hardening'        => $h,
				'login_limit'      => $ll,
			)
		);
	}

	/**
	 * Core files against the official checksums. Reports modified, missing and unexpected files
	 * (lists cut at 100). wp-content is skipped on purpose. Needs outbound HTTPS to api.wordpress.org.
	 */
	public function integrity(): WP_REST_Response|WP_Error {
		global $wp_version;
		$locale = function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US';
		$locale = 1 === preg_match( '/^[A-Za-z_]{2,12}$/', $locale ) ? $locale : 'en_US';
		$url    = self::CHECKSUM_URL . '?version=' . rawurlencode( (string) $wp_version ) . '&locale=' . rawurlencode( $locale );
		$res    = wp_remote_get( $url, array( 'timeout' => 20, 'redirection' => 0 ) );
		if ( is_wp_error( $res ) ) {
			return $this->error( 'ovp_integrity_unavailable', Ovp_Errors::scrub( $res->get_error_message() ), 502 );
		}
		if ( 200 !== wp_remote_retrieve_response_code( $res ) ) {
			return $this->error( 'ovp_integrity_unavailable', 'api.wordpress.org answered HTTP ' . wp_remote_retrieve_response_code( $res ) . ' (an unreleased or custom WordPress version has no checksums).', 502 );
		}
		$json = json_decode( wp_remote_retrieve_body( $res ), true, 8 );
		$sums = is_array( $json ) && is_array( $json['checksums'] ?? null ) ? $json['checksums'] : null;
		if ( is_array( $sums ) && isset( $sums[ $wp_version ] ) && is_array( $sums[ $wp_version ] ) ) {
			$sums = $sums[ $wp_version ];
		}
		if ( ! is_array( $sums ) || array() === $sums ) {
			return $this->error( 'ovp_integrity_unavailable', 'No checksums for WordPress ' . $wp_version . '.', 502 );
		}

		$base     = rtrim( str_replace( '\\', '/', ABSPATH ), '/' ) . '/';
		$modified = array();
		$missing  = array();
		$known    = array();
		$checked  = 0;
		foreach ( $sums as $rel => $md5 ) {
			if ( ! is_string( $rel ) || ! is_string( $md5 ) || '' === $rel || str_contains( $rel, '..' ) || str_starts_with( $rel, '/' ) || str_starts_with( $rel, 'wp-content/' ) ) {
				continue;
			}
			$known[ $rel ] = true;
			$file          = $base . $rel;
			++$checked;
			if ( ! is_file( $file ) ) {
				$missing[] = $rel;
			} elseif ( ! hash_equals( strtolower( $md5 ), (string) md5_file( $file ) ) ) {
				$modified[] = $rel;
			}
		}

		$unexpected = array();
		$scanned    = 0;
		foreach ( array( 'wp-admin', 'wp-includes' ) as $dir ) {
			if ( ! is_dir( $base . $dir ) ) {
				continue;
			}
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base . $dir, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				if ( ++$scanned > self::SCAN_CAP ) {
					break 2;
				}
				if ( ! $f->isFile() || $f->isLink() ) {
					continue;
				}
				$rel = ltrim( substr( str_replace( '\\', '/', $f->getPathname() ), strlen( $base ) ), '/' );
				if ( ! isset( $known[ $rel ] ) ) {
					$unexpected[] = $rel;
				}
			}
		}
		$root = @scandir( $base ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		foreach ( is_array( $root ) ? $root : array() as $name ) {
			if ( str_ends_with( $name, '.php' ) && ! isset( $known[ $name ] ) && 'wp-config.php' !== $name && is_file( $base . $name ) ) {
				$unexpected[] = $name;
			}
		}

		return $this->success(
			array(
				'wp_version'       => (string) $wp_version,
				'locale'           => $locale,
				'checked'          => $checked,
				'clean'            => array() === $modified && array() === $missing && array() === $unexpected,
				'modified'         => array_slice( $modified, 0, self::LIST_CAP ),
				'missing'          => array_slice( $missing, 0, self::LIST_CAP ),
				'unexpected'       => array_slice( $unexpected, 0, self::LIST_CAP ),
				'modified_count'   => count( $modified ),
				'missing_count'    => count( $missing ),
				'unexpected_count' => count( $unexpected ),
				'scan_truncated'   => $scanned > self::SCAN_CAP,
			)
		);
	}

	/** Body: xmlrpc, file_editor, author_enum, headers (bool each; true = protect, false = back to default; omitted = unchanged), confirm (required). */
	public function harden( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = (array) $request->get_json_params();
		if ( empty( $body['confirm'] ) ) {
			return $this->error( 'mcp_bridge_confirm_required', 'security_harden needs confirm=true.', 403 );
		}
		$cur     = self::hardening();
		$changed = array();
		foreach ( self::HARDEN_KEYS as $k ) {
			if ( array_key_exists( $k, $body ) ) {
				if ( ! is_bool( $body[ $k ] ) ) {
					return $this->error( 'mcp_bridge_invalid_payload', $k . ' must be true or false.', 400 );
				}
				if ( $cur[ $k ] !== $body[ $k ] ) {
					$changed[]  = $k;
					$cur[ $k ] = $body[ $k ];
				}
			}
		}
		if ( array() === array_intersect( self::HARDEN_KEYS, array_keys( $body ) ) ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Provide at least one of: ' . implode( ', ', self::HARDEN_KEYS ) . '.', 400 );
		}
		update_option( self::HARDEN_OPTION, $cur, false );
		return $this->success(
			array(
				'hardening'          => $cur,
				'changed'            => $changed,
				'applies_from'       => 'the next request',
				'file_edit_constant' => defined( 'DISALLOW_FILE_EDIT' ) && constant( 'DISALLOW_FILE_EDIT' ),
			)
		);
	}

	/** Body: enabled (bool), max_attempts (3-20), lockout_minutes (1-1440). Omitted keys keep their value. */
	public function login_limit_set( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = (array) $request->get_json_params();
		$cur  = self::login_limit();
		if ( array_key_exists( 'enabled', $body ) ) {
			if ( ! is_bool( $body['enabled'] ) ) {
				return $this->error( 'mcp_bridge_invalid_payload', 'enabled must be true or false.', 400 );
			}
			$cur['enabled'] = $body['enabled'];
		}
		foreach ( array( 'max_attempts' => array( 3, 20 ), 'lockout_minutes' => array( 1, 1440 ) ) as $k => $range ) {
			if ( array_key_exists( $k, $body ) ) {
				if ( ! is_int( $body[ $k ] ) || $body[ $k ] < $range[0] || $body[ $k ] > $range[1] ) {
					return $this->error( 'mcp_bridge_invalid_payload', $k . ' must be an integer ' . $range[0] . '-' . $range[1] . '.', 400 );
				}
				$cur[ $k ] = $body[ $k ];
			}
		}
		if ( ! array_key_exists( 'enabled', $body ) && ! array_key_exists( 'max_attempts', $body ) && ! array_key_exists( 'lockout_minutes', $body ) ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Provide enabled, max_attempts or lockout_minutes.', 400 );
		}
		update_option( self::LIMIT_OPTION, $cur, false );
		return $this->success( $cur + array( 'applies_from' => 'the next request' ) );
	}
}
