<?php
/**
 * Network helpers: public-HTTPS URL checks (SSRF), path jail. REMOTE_ADDR is log-only,
 * never an auth factor (the IP allowlist was removed in 2.0).
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ovp_Net {

	/**
	 * Hosts a package download may be redirected TO (exact match, https, port 443). GitHub release assets answer
	 * github.com/.../releases/download/... with a 302 to release-assets.githubusercontent.com.
	 */
	public const REDIRECT_HOSTS = array( 'github.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com' );
	public const MAX_REDIRECTS  = 3;

	/** @var null|callable(string):array<int,string> Test seam for resolve_host_ips(); ignored unless OVP_TESTING is defined. */
	public static $dns_hook = null;

	/**
	 * TCP peer only. Never X-Forwarded-For / CF-Connecting-IP / X-Real-IP —
	 * those are attacker-controlled on a typical Hostinger/LiteSpeed vhost.
	 */
	public static function remote_addr(): string {
		$raw = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		return self::normalize_ip( $raw );
	}

	public static function normalize_ip( string $ip ): string {
		$ip = strtolower( trim( $ip ) );
		if ( '' === $ip ) {
			return '';
		}
		if ( str_starts_with( $ip, '::ffff:' ) ) {
			$v4 = substr( $ip, 7 );
			if ( false !== filter_var( $v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				return $v4;
			}
		}
		$packed = inet_pton( $ip );
		if ( false === $packed ) {
			return '';
		}
		$out = inet_ntop( $packed );
		return is_string( $out ) ? $out : '';
	}

	public static function is_blocked_ip( string $ip ): bool {
		$norm = self::normalize_ip( $ip );
		if ( '' === $norm ) {
			return true;
		}
		return false === filter_var(
			$norm,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	public static function is_blocked_host( string $host ): bool {
		$host = strtolower( rtrim( $host, '.' ) );
		if ( '' === $host ) {
			return true;
		}
		if ( in_array( $host, array( 'localhost', 'localhost.localdomain' ), true ) ) {
			return true;
		}
		if ( str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.local' ) || str_ends_with( $host, '.internal' ) ) {
			return true;
		}
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return self::is_blocked_ip( $host );
		}
		return false;
	}

	/**
	 * HTTPS only. Host not loopback/RFC1918/link-local. Every resolved A/AAAA
	 * must be a public unicast address (DNS-rebinding guard).
	 *
	 * @return true|WP_Error
	 */
	public static function assert_public_https_url( string $url ): true|WP_Error {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return new WP_Error( 'mcp_bridge_bad_url', 'Invalid URL.', array( 'status' => 400 ) );
		}
		if ( ( $parts['scheme'] ?? '' ) !== 'https' ) {
			return new WP_Error( 'mcp_bridge_https_required', 'Only https URLs are allowed.', array( 'status' => 400 ) );
		}
		$host = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		if ( '' === $host || self::is_blocked_host( $host ) ) {
			return new WP_Error( 'mcp_bridge_blocked_host', 'URL host is not allowed.', array( 'status' => 400 ) );
		}
		$user = $parts['user'] ?? '';
		if ( '' !== (string) $user ) {
			return new WP_Error( 'mcp_bridge_blocked_host', 'URL credentials are not allowed.', array( 'status' => 400 ) );
		}

		$ips = self::resolve_host_ips( $host );
		if ( array() === $ips ) {
			return new WP_Error( 'mcp_bridge_bad_url', 'URL host did not resolve.', array( 'status' => 400 ) );
		}
		foreach ( $ips as $ip ) {
			if ( self::is_blocked_ip( $ip ) ) {
				return new WP_Error( 'mcp_bridge_blocked_host', 'URL resolved to a private or reserved address.', array( 'status' => 400 ) );
			}
		}
		return true;
	}

	/**
	 * @return list<string>
	 */
	public static function resolve_host_ips( string $host ): array {
		if ( null !== self::$dns_hook && defined( 'OVP_TESTING' ) ) { // Test seam, honored only under the test suites (they define OVP_TESTING).
			return array_values( array_filter( array_map( static fn( $ip ): string => self::normalize_ip( (string) $ip ), (array) ( self::$dns_hook )( $host ) ), static fn( string $ip ): bool => '' !== $ip ) );
		}
		$found = array();
		$a     = @gethostbynamel( $host );
		if ( is_array( $a ) ) {
			foreach ( $a as $ip ) {
				$norm = self::normalize_ip( (string) $ip );
				if ( '' !== $norm ) {
					$found[ $norm ] = true;
				}
			}
		}
		if ( function_exists( 'dns_get_record' ) ) {
			$records = @dns_get_record( $host, DNS_AAAA );
			if ( is_array( $records ) ) {
				foreach ( $records as $row ) {
					if ( ! empty( $row['ipv6'] ) ) {
						$norm = self::normalize_ip( (string) $row['ipv6'] );
						if ( '' !== $norm ) {
							$found[ $norm ] = true;
						}
					}
				}
			}
		}
		return array_keys( $found );
	}

	/**
	 * Run $callback with HTTP redirects disabled and every request URL re-checked
	 * (covers download_url / Upgrader follow-up requests).
	 *
	 * @template T
	 * @param callable():T $callback
	 * @return T
	 */
	public static function with_safe_http( callable $callback ): mixed {
		$args_guard = static function ( $args, $req_url ) {
			if ( ! is_array( $args ) ) {
				$args = array();
			}
			$args['redirection'] = 0;
			$args['sslverify']   = true;
			unset( $req_url );
			return $args;
		};
		$pre_guard = static function ( $preempt, $args, $req_url ) {
			unset( $args );
			$check = self::assert_public_https_url( (string) $req_url );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			return $preempt;
		};
		$curl_pin = static function ( $handle, $r, $req_url ) {
			unset( $r );
			if ( ! is_resource( $handle ) && ! ( $handle instanceof \CurlHandle ) ) {
				return;
			}
			$host = wp_parse_url( (string) $req_url, PHP_URL_HOST );
			if ( ! is_string( $host ) || '' === $host ) {
				return;
			}
			$scheme = (string) wp_parse_url( (string) $req_url, PHP_URL_SCHEME );
			$port   = wp_parse_url( (string) $req_url, PHP_URL_PORT );
			$port   = is_int( $port ) ? $port : ( 'https' === $scheme ? 443 : 80 );
			$ips    = array();
			foreach ( self::resolve_host_ips( $host ) as $ip ) {
				if ( ! self::is_blocked_ip( $ip ) ) {
					$ips[] = $ip;
				}
			}
			if ( array() === $ips ) {
				curl_setopt( $handle, CURLOPT_URL, 'https://invalid.invalid/' );
				return;
			}
			$rules = array();
			foreach ( $ips as $ip ) {
				// CURLOPT_RESOLVE requires IPv6 in [brackets]. Unbracketed AAAA
				// is parsed as garbage and curl fails instantly (error 7, 0ms)
				// which is what ServBay/macOS PHP hits for AAAA-only hosts.
				if ( str_contains( $ip, ':' ) ) {
					$rules[] = $host . ':' . $port . ':[' . $ip . ']';
				} else {
					$rules[] = $host . ':' . $port . ':' . $ip;
				}
			}
			$v4 = array_values(
				array_filter(
					$rules,
					static fn( string $rule ): bool => ! str_contains( $rule, ':[' )
				)
			);
			curl_setopt( $handle, CURLOPT_RESOLVE, $v4 !== array() ? $v4 : $rules );
		};
		add_filter( 'http_request_args', $args_guard, PHP_INT_MAX, 2 );
		add_filter( 'pre_http_request', $pre_guard, PHP_INT_MAX, 3 );
		add_action( 'http_api_curl', $curl_pin, PHP_INT_MAX, 3 );
		try {
			return $callback();
		} finally {
			remove_filter( 'http_request_args', $args_guard, PHP_INT_MAX );
			remove_filter( 'pre_http_request', $pre_guard, PHP_INT_MAX );
			remove_action( 'http_api_curl', $curl_pin, PHP_INT_MAX );
		}
	}

	/**
	 * Download a package URL to a temp file, following at most MAX_REDIRECTS redirects, and only to
	 * REDIRECT_HOSTS. Used by plugins/install and themes/install (tier T3). Every hop: https, no
	 * credentials, default port, host resolves to public addresses only (assert_public_https_url), and
	 * the request carries no Authorization/cookie headers. Redirects are never delegated to the HTTP
	 * layer (`redirection` is 0 on every request), so a foreign Location is refused here.
	 *
	 * @return string|WP_Error Path of the downloaded temp file (caller unlinks it).
	 */
	public static function download_following_redirects( string $url, int $timeout = 60, int $max_bytes = 0 ): string|WP_Error {
		$current = $url;
		for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
			$safe = self::assert_public_https_url( $current );
			if ( is_wp_error( $safe ) ) {
				return $safe;
			}
			if ( $hop > 0 && ! self::redirect_target_ok( $current ) ) {
				return new WP_Error( 'mcp_bridge_redirect_blocked', 'Redirect target is not on the allowlist.', array( 'status' => 400 ) );
			}
			$tmp = function_exists( 'wp_tempnam' ) ? wp_tempnam( $current ) : tempnam( sys_get_temp_dir(), 'ovp' );
			if ( ! is_string( $tmp ) || '' === $tmp ) {
				return new WP_Error( 'mcp_bridge_install_failed', 'Could not create a temporary file.', array( 'status' => 500 ) );
			}
			$args = array(
				'timeout'     => $timeout,
				'redirection' => 0,
				'sslverify'   => true,
				'stream'      => true,
				'filename'    => $tmp,
				'headers'     => array(),
				'cookies'     => array(),
			);
			if ( $max_bytes > 0 ) {
				// One byte over the cap: a larger download arrives as max+1 bytes, so the caller's `>` size check refuses it with
				// ovp_zip_limits instead of installing a silently truncated archive that fails as a bad zip.
				$args['limit_response_size'] = $max_bytes + 1;
			}
			$r = self::with_safe_http(
				static function () use ( $current, $args ) {
					return wp_remote_get( $current, $args );
				}
			);
			if ( is_wp_error( $r ) ) {
				@unlink( $tmp );
				return $r;
			}
			$code = (int) wp_remote_retrieve_response_code( $r );
			if ( 200 === $code ) {
				return $tmp;
			}
			@unlink( $tmp );
			if ( ! in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
				return new WP_Error( 'mcp_bridge_install_failed', 'Download failed: HTTP ' . $code . '.', array( 'status' => 400 ) );
			}
			$loc = self::location_header( $r );
			if ( '' === $loc ) {
				return new WP_Error( 'mcp_bridge_redirect_blocked', 'Redirect without a Location header.', array( 'status' => 400 ) );
			}
			$next = self::absolute_location( $current, $loc );
			if ( '' === $next ) {
				return new WP_Error( 'mcp_bridge_redirect_blocked', 'Redirect target is not an absolute https URL.', array( 'status' => 400 ) );
			}
			$current = $next;
		}
		return new WP_Error( 'mcp_bridge_redirect_blocked', 'Too many redirects (max ' . self::MAX_REDIRECTS . ').', array( 'status' => 400 ) );
	}

	/** Exact-host allowlist, https only, default port, no credentials. */
	public static function redirect_target_ok( string $url ): bool {
		$p = wp_parse_url( $url );
		if ( ! is_array( $p ) || 'https' !== ( $p['scheme'] ?? '' ) || isset( $p['user'] ) || isset( $p['pass'] ) ) {
			return false;
		}
		if ( isset( $p['port'] ) && 443 !== (int) $p['port'] ) {
			return false;
		}
		return in_array( strtolower( (string) ( $p['host'] ?? '' ) ), self::REDIRECT_HOSTS, true );
	}

	/** @param mixed $r wp_remote_* response */
	private static function location_header( mixed $r ): string {
		$h = wp_remote_retrieve_headers( $r );
		$a = is_object( $h ) && method_exists( $h, 'getAll' ) ? $h->getAll() : (array) $h;
		foreach ( $a as $k => $v ) {
			if ( is_string( $k ) && 'location' === strtolower( $k ) ) {
				return trim( is_array( $v ) ? (string) reset( $v ) : (string) $v );
			}
		}
		return '';
	}

	/** Absolute https URL for a Location value, or '' (relative path references are refused). */
	private static function absolute_location( string $base, string $loc ): string {
		if ( str_starts_with( $loc, '//' ) ) {
			return 'https:' . $loc;
		}
		if ( 1 === preg_match( '#^[a-z][a-z0-9+.-]*://#i', $loc ) ) {
			return $loc;
		}
		if ( str_starts_with( $loc, '/' ) ) {
			$p = wp_parse_url( $base );
			return is_array( $p ) && isset( $p['host'] ) ? 'https://' . $p['host'] . $loc : '';
		}
		return '';
	}

	public static function path_inside( string $path, string $base ): bool {
		$base = rtrim( $base, '/' );
		return $path === $base || str_starts_with( $path, $base . '/' );
	}
}
