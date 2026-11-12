<?php
/**
 * Signed release index: validation, Ed25519 verification, GitHub fetch.
 *
 * A release is described by release-index.json (docs/AUTO-UPDATE.md section 3). The index is
 * trusted only when an Ed25519 signature over the OVP2-RELEASE message verifies under a pinned,
 * non-revoked public key. The zip URL is never taken from the index: it is built here from the
 * version, and the index must agree with it.
 *
 * The pure methods (zip_url, message, validate, verify, offer_decision) call no WordPress
 * function, so the tests and the cross-stack e2e can run them standalone.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ovp_Release {

	public const REPO     = 'ovld-team/overlord-wp-fleet-releases';
	public const SLUG     = 'overlord-wp-fleet';
	public const INDEX_URL = 'https://github.com/ovld-team/overlord-wp-fleet-releases/releases/latest/download/release-index.json';
	public const DL_PREFIX = 'https://github.com/ovld-team/overlord-wp-fleet-releases/releases/download/';

	public const ALLOWED_HOSTS = array( 'github.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com' );

	public const MAX_INDEX_BYTES = 16384;
	public const MAX_ZIP_BYTES   = 67108864; // 64 MB.
	public const MAX_REDIRECTS   = 4;
	public const CACHE_OPTION    = 'ovp_release_cache';
	public const CACHE_TTL       = 21600; // 6 h.

	/**
	 * Pinned release-signing PUBLIC keys: key_id => standard base64 of the raw 32-byte Ed25519 key.
	 * Public keys only. They are committed to the source, never injected at build time (an injected
	 * key would make the CI zip differ from the zip built elsewhere, which breaks the sha256 parity
	 * rule). An empty list fails closed: every index and package is refused. Release CI refuses to
	 * cut a tag while this list is empty. server/src/release-keys.ts must hold the same values.
	 */
	public const RELEASE_KEYS = array(
		'ovp-rel-2026a' => '5wCiFaRvCyHDtn4TV93j9M3dmEA+504GiZAvGDJPn0g=',
		'ovp-rel-2026b' => 'dF8TOp/fROAXolgKryiqwKvIP2iQfBExO+T3HxV0vs4=',
	);

	/** Key ids that no longer count, even when they are still in RELEASE_KEYS. */
	public const REVOKED_KEY_IDS = array();

	/** @var array<string,string>|null Test-only pinned list; honoured only while OVP_TESTING is defined. */
	private static ?array $test_keys = null;
	/** @var list<string>|null */
	private static ?array $test_revoked = null;

	/**
	 * Tests only: replace the pinned lists for callers that cannot pass them (current(), the updater).
	 * A no-op unless OVP_TESTING is defined, so production code can never swap its trust anchors.
	 *
	 * @param array<string,string>|null $keys
	 * @param list<string>|null         $revoked
	 */
	public static function use_test_keys( ?array $keys, ?array $revoked = null ): void {
		if ( defined( 'OVP_TESTING' ) ) {
			self::$test_keys    = $keys;
			self::$test_revoked = $revoked;
		}
	}

	/* ------------------------------------------------------------------ pure logic */

	public static function zip_url( string $version ): string {
		return self::DL_PREFIX . 'v' . $version . '/' . self::SLUG . '-' . $version . '.zip';
	}

	/**
	 * Exact signed bytes (design 3.2): seven ASCII lines joined with LF, no trailing newline.
	 * Call validate() first; this only formats.
	 *
	 * @param array<string,mixed> $idx
	 */
	public static function message( array $idx ): string {
		return implode(
			"\n",
			array(
				'OVP2-RELEASE',
				self::SLUG,
				(string) ( $idx['version'] ?? '' ),
				(string) ( $idx['sha256'] ?? '' ),
				(string) ( $idx['size'] ?? '' ),
				(string) ( $idx['requires_php'] ?? '' ),
				(string) ( $idx['requires_wp'] ?? '' ),
			)
		);
	}

	/**
	 * Structure and field rules (design 3.1). Unknown fields are ignored.
	 *
	 * @param array<string,mixed> $idx
	 * @return true|WP_Error
	 */
	public static function validate( array $idx ): true|WP_Error {
		$bad = static fn( string $why ) => new WP_Error( 'ovp_release_invalid', 'Release index rejected: ' . $why . '.' );

		if ( ! isset( $idx['schema'] ) || 1 !== $idx['schema'] ) {
			return $bad( 'unsupported schema' );
		}
		if ( ! isset( $idx['slug'] ) || self::SLUG !== $idx['slug'] ) {
			return $bad( 'wrong slug' );
		}
		$version = $idx['version'] ?? null;
		if ( ! is_string( $version ) || 1 !== preg_match( '/^\d+\.\d+\.\d+$/D', $version ) ) {
			return $bad( 'bad version' );
		}
		$sha = $idx['sha256'] ?? null;
		if ( ! is_string( $sha ) || 1 !== preg_match( '/^[0-9a-f]{64}$/D', $sha ) ) {
			return $bad( 'bad sha256' );
		}
		$size = $idx['size'] ?? null;
		if ( ! is_int( $size ) || $size < 1 || $size > self::MAX_ZIP_BYTES ) {
			return $bad( 'bad size' );
		}
		foreach ( array( 'requires_php', 'requires_wp' ) as $f ) {
			$v = $idx[ $f ] ?? null;
			if ( ! is_string( $v ) || 1 !== preg_match( '/^\d+(\.\d+){0,2}$/D', $v ) ) {
				return $bad( 'bad ' . $f );
			}
		}
		if ( isset( $idx['changelog'] ) && ( ! is_string( $idx['changelog'] ) || strlen( $idx['changelog'] ) > 4000 ) ) {
			return $bad( 'changelog too long' );
		}
		$sigs = $idx['signatures'] ?? null;
		if ( ! is_array( $sigs ) || ! array_is_list( $sigs ) || count( $sigs ) < 1 || count( $sigs ) > 4 ) {
			return $bad( 'bad signatures' );
		}
		foreach ( $sigs as $s ) {
			if ( ! is_array( $s ) || ! is_string( $s['key_id'] ?? null ) || ! is_string( $s['sig'] ?? null ) ) {
				return $bad( 'bad signature entry' );
			}
			if ( 1 !== preg_match( '/^[a-z0-9-]{3,32}$/D', $s['key_id'] ) ) {
				return $bad( 'bad key_id' );
			}
			$raw = Ovp_Proto::b64d( $s['sig'] );
			if ( null === $raw || 64 !== strlen( $raw ) ) {
				return $bad( 'bad signature encoding' );
			}
		}
		// The URL is built here. An index cannot send a site anywhere else.
		if ( ! isset( $idx['zip_url'] ) || ! is_string( $idx['zip_url'] ) || self::zip_url( $version ) !== $idx['zip_url'] ) {
			return $bad( 'zip_url is not the official release URL' );
		}
		return true;
	}

	/**
	 * Verify an index and return the id of the key that vouched for it.
	 *
	 * $keys / $revoked replace the pinned lists only while OVP_TESTING is defined.
	 *
	 * @param array<string,mixed>        $idx
	 * @param array<string,string>|null  $keys    key_id => base64 raw public key.
	 * @param list<string>|null          $revoked
	 * @return string|WP_Error key id on success.
	 */
	public static function verified_key_id( array $idx, ?array $keys = null, ?array $revoked = null ): string|WP_Error {
		if ( ! Ovp_Proto::sodium_available() ) {
			return new WP_Error( 'ovp_update_sodium', 'ext-sodium is required to verify releases.' );
		}
		$testing = defined( 'OVP_TESTING' );
		$keys    = $testing ? ( $keys ?? self::$test_keys ) : null;
		$revoked = $testing ? ( $revoked ?? self::$test_revoked ) : null;
		$pinned  = null !== $keys ? $keys : self::RELEASE_KEYS;
		$revoked = null !== $revoked ? $revoked : self::REVOKED_KEY_IDS;
		if ( array() === $pinned ) {
			return new WP_Error( 'ovp_update_no_key', 'This build has no pinned release key, so no package can be trusted.' );
		}
		$valid = self::validate( $idx );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$msg = self::message( $idx );
		foreach ( $idx['signatures'] as $s ) {
			$kid = $s['key_id'];
			if ( in_array( $kid, $revoked, true ) || ! isset( $pinned[ $kid ] ) || ! is_string( $pinned[ $kid ] ) ) {
				continue;
			}
			$pub = Ovp_Proto::b64d( $pinned[ $kid ] );
			$sig = Ovp_Proto::b64d( $s['sig'] );
			if ( null === $pub || 32 !== strlen( $pub ) || null === $sig ) {
				continue;
			}
			if ( Ovp_Proto::verify_detached( $msg, $sig, $pub ) ) {
				return $kid;
			}
		}
		return new WP_Error( 'ovp_update_bad_sig', 'Release signature does not verify under any pinned key. Do not install it.' );
	}

	/**
	 * @param array<string,mixed>       $idx
	 * @param array<string,string>|null $keys
	 * @param list<string>|null         $revoked
	 * @return true|WP_Error
	 */
	public static function verify( array $idx, ?array $keys = null, ?array $revoked = null ): true|WP_Error {
		$r = self::verified_key_id( $idx, $keys, $revoked );
		return is_wp_error( $r ) ? $r : true;
	}

	/**
	 * Should a verified index be offered to this site?
	 *
	 * @param array<string,mixed> $idx Verified index.
	 * @return array{ok:bool,reason:string}
	 */
	public static function offer_decision( array $idx, string $installed, string $php, string $wp ): array {
		$v = (string) ( $idx['version'] ?? '' );
		if ( ! version_compare( $v, $installed, '>' ) ) {
			return array( 'ok' => false, 'reason' => 'not_newer' );
		}
		if ( version_compare( $php, (string) ( $idx['requires_php'] ?? '0' ), '<' ) ) {
			return array( 'ok' => false, 'reason' => 'php_too_old' );
		}
		if ( version_compare( $wp, (string) ( $idx['requires_wp'] ?? '0' ), '<' ) ) {
			return array( 'ok' => false, 'reason' => 'wp_too_old' );
		}
		return array( 'ok' => true, 'reason' => '' );
	}

	/* ------------------------------------------------------------------ network */

	/**
	 * Fetch (no verification) the latest index. 404 means no release yet.
	 *
	 * @return array{status:string,index?:array<string,mixed>,error?:string,checked_at:int}
	 */
	public static function fetch_index( bool $force = false ): array {
		unset( $force ); // Never cached here; current() decides when to call.
		$now = time();
		$r   = self::http_get(
			self::INDEX_URL,
			array(
				'timeout'             => 15,
				'limit_response_size' => self::MAX_INDEX_BYTES + 1,
			)
		);
		if ( is_wp_error( $r ) ) {
			return array( 'status' => 'error', 'error' => $r->get_error_message(), 'checked_at' => $now );
		}
		if ( 404 === $r['code'] ) {
			return array( 'status' => 'unavailable', 'error' => 'No release has been published yet.', 'checked_at' => $now );
		}
		if ( 200 !== $r['code'] ) {
			return array( 'status' => 'error', 'error' => 'Unexpected HTTP status ' . $r['code'] . ' from GitHub.', 'checked_at' => $now );
		}
		if ( strlen( $r['body'] ) > self::MAX_INDEX_BYTES ) {
			return array( 'status' => 'error', 'error' => 'Release index is larger than the allowed 16 KB.', 'checked_at' => $now );
		}
		$j = json_decode( $r['body'], true, 4 );
		if ( ! is_array( $j ) || array_is_list( $j ) ) {
			return array( 'status' => 'error', 'error' => 'Release index is not a JSON object.', 'checked_at' => $now );
		}
		return array( 'status' => 'ok', 'index' => $j, 'checked_at' => $now );
	}

	/**
	 * Cached, verified view of the latest release. Refetches when older than CACHE_TTL or forced.
	 *
	 * @return array{status:string,index:?array<string,mixed>,verified:bool,error:string,checked_at:int,key_id:string}
	 */
	public static function current( bool $force = false ): array {
		$cache = get_option( self::CACHE_OPTION, null );
		$fresh = is_array( $cache ) && isset( $cache['checked_at'] ) && is_int( $cache['checked_at'] )
			&& ( time() - $cache['checked_at'] ) < self::CACHE_TTL && ( time() - $cache['checked_at'] ) >= 0;
		if ( ! $force && $fresh ) {
			return self::normalize_cache( $cache );
		}
		$prev  = is_array( $cache ) ? self::normalize_cache( $cache ) : null;
		$f     = self::fetch_index();
		$entry = array(
			'status'     => $f['status'],
			'index'      => null,
			'verified'   => false,
			'error'      => $f['error'] ?? '',
			'checked_at' => $f['checked_at'],
			'key_id'     => '',
		);
		if ( 'ok' === $f['status'] ) {
			$kid = self::verified_key_id( $f['index'] );
			if ( is_wp_error( $kid ) ) {
				$entry['error'] = $kid->get_error_message();
			} else {
				$entry['index']    = $f['index'];
				$entry['verified'] = true;
				$entry['key_id']   = $kid;
			}
		} elseif ( 'error' === $f['status'] && null !== $prev && $prev['verified'] ) {
			// Network trouble: keep the last verified index, record the error and the retry time.
			$entry['index']    = $prev['index'];
			$entry['verified'] = true;
			$entry['key_id']   = $prev['key_id'];
		}
		update_option( self::CACHE_OPTION, $entry, false );
		return $entry;
	}

	/**
	 * The stored answer, re-verified, with no network access (for admin screens).
	 *
	 * @return array{status:string,index:?array<string,mixed>,verified:bool,error:string,checked_at:int,key_id:string}
	 */
	public static function cached(): array {
		$cache = get_option( self::CACHE_OPTION, null );
		return self::normalize_cache( is_array( $cache ) ? $cache : array() );
	}

	/**
	 * Download a release zip to $dest through the same redirect rules. The caller still has to
	 * check size, sha256 and signature against a verified index.
	 *
	 * @return true|WP_Error
	 */
	public static function download_zip( string $url, string $dest ): true|WP_Error {
		if ( ! str_starts_with( $url, self::DL_PREFIX ) ) {
			return new WP_Error( 'ovp_update_bad_url', 'Package URL is not the official release URL.' );
		}
		$r = self::http_get(
			$url,
			array(
				'timeout'             => 120,
				'stream'              => true,
				'filename'            => $dest,
				'limit_response_size' => self::MAX_ZIP_BYTES + 1,
			)
		);
		if ( is_wp_error( $r ) ) {
			self::unlink_quiet( $dest );
			return $r;
		}
		if ( 200 !== $r['code'] ) {
			self::unlink_quiet( $dest );
			return new WP_Error( 'ovp_update_download', 'Download failed with HTTP status ' . $r['code'] . '.' );
		}
		$size = is_file( $dest ) ? filesize( $dest ) : false;
		if ( false === $size || $size < 1 || $size > self::MAX_ZIP_BYTES ) {
			self::unlink_quiet( $dest );
			return new WP_Error( 'ovp_update_size', 'Downloaded package is empty or larger than the allowed 64 MB.' );
		}
		return true;
	}

	private static function unlink_quiet( string $path ): void {
		if ( is_file( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- own temp file.
		}
	}

	/** Every hop: https, allow-listed host, default port, no credentials, public address. */
	private static function check_hop( string $url ): true|WP_Error {
		$p = wp_parse_url( $url );
		if ( ! is_array( $p ) || ( $p['scheme'] ?? '' ) !== 'https' ) {
			return new WP_Error( 'ovp_release_host', 'Only https release URLs are allowed.' );
		}
		$host = strtolower( (string) ( $p['host'] ?? '' ) );
		if ( ! in_array( $host, self::ALLOWED_HOSTS, true ) ) {
			return new WP_Error( 'ovp_release_host', 'Release URL host is not allowed.' );
		}
		if ( isset( $p['user'] ) || isset( $p['pass'] ) || ( isset( $p['port'] ) && 443 !== (int) $p['port'] ) ) {
			return new WP_Error( 'ovp_release_host', 'Release URL must not carry credentials or a custom port.' );
		}
		return Ovp_Net::assert_public_https_url( $url );
	}

	/**
	 * GET with manual redirects (max 4 hops), each hop checked.
	 *
	 * @param array<string,mixed> $args
	 * @return array{code:int,body:string}|WP_Error
	 */
	private static function http_get( string $url, array $args ): array|WP_Error {
		$args += array(
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => array( 'User-Agent' => 'overlord-wp-fleet/' . ( defined( 'OVP_FLEET_VERSION' ) ? OVP_FLEET_VERSION : '0' ) ),
		);
		for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
			$ok = self::check_hop( $url );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			$target = $url;
			$resp   = Ovp_Net::with_safe_http(
				static function () use ( $target, $args ) {
					return wp_remote_get( $target, $args );
				}
			);
			if ( is_wp_error( $resp ) ) {
				return new WP_Error( 'ovp_release_http', 'Could not reach GitHub.' );
			}
			$code = (int) wp_remote_retrieve_response_code( $resp );
			if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
				if ( $hop >= self::MAX_REDIRECTS ) {
					return new WP_Error( 'ovp_release_redirects', 'Too many redirects.' );
				}
				$loc = wp_remote_retrieve_header( $resp, 'location' );
				$loc = is_array( $loc ) ? (string) reset( $loc ) : (string) $loc;
				if ( '' === $loc || str_starts_with( $loc, '//' ) ) {
					return new WP_Error( 'ovp_release_redirect', 'Bad redirect target.' );
				}
				if ( str_starts_with( $loc, '/' ) ) {
					$loc = 'https://' . strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) . $loc;
				}
				$url = $loc;
				continue;
			}
			return array( 'code' => $code, 'body' => (string) wp_remote_retrieve_body( $resp ) );
		}
		return new WP_Error( 'ovp_release_redirects', 'Too many redirects.' );
	}

	/**
	 * @param array<mixed> $c
	 * @return array{status:string,index:?array<string,mixed>,verified:bool,error:string,checked_at:int,key_id:string}
	 */
	private static function normalize_cache( array $c ): array {
		$index = is_array( $c['index'] ?? null ) ? $c['index'] : null;
		$kid   = '';
		$ok    = false;
		if ( null !== $index ) {
			// Never trust a stored "verified" flag: the option row is plain data. Check again.
			$k = self::verified_key_id( $index );
			if ( ! is_wp_error( $k ) ) {
				$kid = $k;
				$ok  = true;
			} else {
				$index = null;
			}
		}
		return array(
			'status'     => is_string( $c['status'] ?? null ) ? $c['status'] : 'error',
			'index'      => $index,
			'verified'   => $ok,
			'error'      => is_string( $c['error'] ?? null ) ? $c['error'] : '',
			'checked_at' => is_int( $c['checked_at'] ?? null ) ? $c['checked_at'] : 0,
			'key_id'     => $kid,
		);
	}
}
