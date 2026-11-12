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

	public static function path_inside( string $path, string $base ): bool {
		$base = rtrim( $base, '/' );
		return $path === $base || str_starts_with( $path, $base . '/' );
	}
}
