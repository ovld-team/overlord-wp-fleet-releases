<?php
/**
 * Request gate for every ovp/v1 route: OVP1 signed envelope (Ed25519, replay-protected),
 * tier authorisation against the plugin cap, T1 two-call confirm, signed responses, and
 * service-account impersonation.
 *
 * The legacy bearer token, hop header and IP allowlist no longer exist. REMOTE_ADDR is
 * never an auth factor.
 *
 * Service account: WordPress core code paths (wp_insert_post, upgraders, ...) check
 * current_user_can(). After a request is cryptographically verified AND tier-authorised
 * we impersonate a hidden administrator for the rest of that request only.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ovp_Auth {

	/**
	 * Route -> tier. Pattern is matched against the logical route without the namespace
	 * ("content/posts/12"). Tiers are [read (GET/HEAD), write (everything else)]. First match
	 * wins; no match = T3 (fail closed). Modules may pass args['ovp_tier'] to override.
	 */
	public const ROUTE_TIERS = array(
		array( '#^(info|inventory|site-info|logs|updates|fleet/status)$#', 0, 0 ),
		array( '#^revoke$#', 0, 0 ),
		// The cap route is open to any master-signed request: lowering is always accepted. A raise is
		// decided inside Ovp_Tiers::remote_set_cap (raise flag, ceiling from pairing, request signed at T3), because
		// gating it on the effective tier would make a lowered cap impossible to raise back.
		array( '#^cap$#', 0, 0 ),
		array( '#^rotate-keys$#', 2, 2 ),
		array( '#^fleet/(self-update|self-rollback)$#', 3, 3 ), // Runs the native plugin upgrader on a signed, site-verified release.
		array( '#^fleet/legacy-retire$#', 3, 3 ), // Deactivates another plugin, wipes its credentials, optionally deletes its files.
		// Generic upload pipeline. begin/chunk/finalize are T3 for both kinds (zip installs run uploaded
		// PHP); OVP_Upload re-checks the tier stored at begin on every chunk and on finalize.
		array( '#^upload/abort$#', 2, 2 ),
		array( '#^upload/(begin|chunk|finalize)$#', 3, 3 ), // Zip installs run uploaded PHP: T3 (UM-3).
		array( '#^auth/magic-login$#', 3, 3 ),
		array( '#^files/upload/status$#', 0, 0 ),
		array( '#^files/upload/abort$#', 2, 2 ),
		array( '#^files/upload/(begin|chunk|finalize)$#', 3, 3 ),
		array( '#^files/restore$#', 3, 3 ),
		array( '#^files$#', 2, 3 ), // Reading files (wp-config, logs, backups) is structural, not a plain read.
		array( '#^(plugins|themes)$#', 0, 2 ),
		array( '#^(plugins|themes)/(install|activate|upload/(begin|finalize))$#', 3, 3 ),
		array( '#^(plugins|themes)/#', 2, 2 ),
		array( '#^options(/.*)?$#', 0, 2 ),
		array( '#^db/(tables|describe|select|query)$#', 0, 0 ),
		array( '#^db/(write|search-replace|rollback)$#', 2, 2 ),
		array( '#^db/exec$#', 3, 3 ),
		array( '#^apply/(elementor|gutenberg|wpbakery)$#', 1, 1 ),
		array( '#^apply/#', 3, 3 ),
		array( '#^meta/(adapters|keys|get|prepare)$#', 0, 0 ),
		array( '#^meta/#', 1, 1 ),
		array( '#^content/media/regenerate$#', 2, 2 ),
		array( '#^content/export$#', 2, 2 ), // Raw wp_posts rows + all postmeta (post_password, unredacted).
		array( '#^menus/export$#', 0, 0 ),
		array( '#^(content|menus)/(import)$#', 2, 2 ),
		array( '#^content(/.*)?$#', 0, 1 ),
		array( '#^media/push$#', 2, 2 ), // Fetches remote URLs into the library.
		array( '#^catalog/#', 0, 0 ),
		array( '#^users(/\d+)?$#', 0, 2 ), // Reads are T0 with emails masked below T2 (see Users::shape_user); writes T2.
		array( '#^users(/.*)?$#', 2, 2 ),
		array( '#^cron/run$#', 2, 2 ),
		array( '#^cron$#', 0, 2 ),
		array( '#^debug/php-logs$#', 0, 0 ),
		array( '#^debug/fatals$#', 3, 3 ), // Installs/removes the fatal-error drop-in (code that runs on every fatal).
		array( '#^search-replace$#', 2, 2 ),
		array( '#^(sync/options|elementor/regen|cache/purge|yoast/reindex|yoast/index|yoast/option|yoast/redirects)$#', 0, 2 ),
		array( '#^yoast/(post|term)/\d+$#', 0, 1 ),
		array( '#^yoast/#', 0, 0 ),
		array( '#^backup/plan$#', 0, 0 ),
		array( '#^backup/(sql|files)$#', 3, 3 ),
		array( '#^site/clone/(plan|sql|files)$#', 3, 3 ),
	);

	/** Routes whose request body may reach the 5MB upload cap. */
	private const UPLOAD_PATH_RE = '#/(upload|media/push)(/|$)#';

	private static ?Ovp_Nonce_Store $store = null;
	private static ?Ovp_Result $verified   = null;
	/** Fingerprint of the exact request $verified belongs to; non-null only while the request is in flight. */
	private static ?string $verified_fp = null;
	private static string $last_reject     = '';
	/** @var array{body:string,headers:array<string,string>,nonce:string}|null */
	private static ?array $presigned = null;
	/** @var callable|null */
	private static $header_sink = null;

	/* ---------- test seams ---------- */

	public static function set_nonce_store( ?Ovp_Nonce_Store $s ): void {
		self::$store = $s;
	}

	public static function set_header_sink( ?callable $sink ): void {
		self::$header_sink = $sink;
	}

	public static function reset(): void {
		self::$verified    = null;
		self::$verified_fp = null;
		self::$last_reject = '';
		self::$presigned   = null;
	}

	public static function last_reject(): string {
		return self::$last_reject;
	}

	public static function verified(): ?Ovp_Result {
		return self::$verified;
	}

	public static function nonce_store(): Ovp_Nonce_Store {
		if ( null === self::$store ) {
			self::$store = new Ovp_Nonce_Store_Wpdb();
		}
		return self::$store;
	}

	public static function hooks(): void {
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'sign_response' ), 10, 4 );
	}

	/* ---------- routing helpers ---------- */

	/**
	 * The logical API path the master signed, percent-encoded exactly as sent
	 * ("/ovp/v1/content/posts/12"). Derived from the raw request URI, never from the
	 * decoded route alone, and cross-checked against the route WordPress matched so a
	 * signature for one path cannot authorise another.
	 */
	public static function logical_path( WP_REST_Request $request ): ?string {
		$ns    = '/' . OVP_FLEET_REST_NAMESPACE . '/';
		$route = rtrim( (string) $request->get_route(), '/' );
		if ( ! str_starts_with( $route . '/', $ns ) ) {
			return null;
		}
		$uri   = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
		$qpos  = strpos( $uri, '?' );
		$upath = false === $qpos ? $uri : substr( $uri, 0, $qpos );
		$cands = array();
		$pre   = '/' . ( function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json' ) . '/';
		$i     = strpos( $upath, $pre );
		if ( false !== $i ) {
			$cands[] = rtrim( substr( $upath, $i + strlen( $pre ) - 1 ), '/' );
		}
		foreach ( explode( '&', self::raw_query() ) as $seg ) {
			if ( str_starts_with( $seg, 'rest_route=' ) ) {
				$cands[] = rtrim( rawurldecode( substr( $seg, 11 ) ), '/' );
				break;
			}
		}
		foreach ( $cands as $c ) {
			if ( rawurldecode( $c ) === $route && Ovp_Proto::valid_path( $c ) ) {
				return $c;
			}
		}
		return null;
	}

	/** A signed-request attempt is in flight: Ovp_Proto closes the admin-only setters for this process. */
	private static function mark_remote(): void {
		Ovp_Proto::verify_request( array( 'method' => '', 'path' => '', 'query' => '', 'body' => '', 'headers' => array() ), static fn(): ?string => null, new Ovp_Nonce_Store_Memory(), time(), 0, '', 'm' );
	}

	private static function raw_query(): string {
		return isset( $_SERVER['QUERY_STRING'] ) && is_string( $_SERVER['QUERY_STRING'] ) ? $_SERVER['QUERY_STRING'] : '';
	}

	/** Tier number a route needs. */
	public static function required_tier( string $method, string $logical_path, ?int $declared = null ): int {
		if ( null !== $declared ) {
			return $declared;
		}
		$ns  = '/' . OVP_FLEET_REST_NAMESPACE . '/';
		$rel = ltrim( rawurldecode( substr( $logical_path, strlen( $ns ) - 1 ) ), '/' );
		$get = in_array( strtoupper( $method ), array( 'GET', 'HEAD' ), true );
		foreach ( self::ROUTE_TIERS as $row ) {
			if ( 1 === preg_match( $row[0], $rel ) ) {
				return $get ? $row[1] : $row[2];
			}
		}
		return 3;
	}

	/** Tier number the master signed for the verified request (before the plugin cap), 0 when none. */
	public static function signed_tier(): int {
		return null === self::$verified || ! self::$verified->ok ? 0 : (int) self::$verified->tier;
	}

	/** Effective tier name of the verified request, for OVP_Meta::request_tier (integration seam). */
	public static function effective_tier_name( mixed $request = null ): string {
		unset( $request );
		if ( null === self::$verified || ! self::$verified->ok ) {
			return 'T0';
		}
		return Ovp_Tiers::name( Ovp_Tiers::effective( self::$verified->tier ) );
	}

	/* ---------- permission callback ---------- */

	/**
	 * @return true|WP_Error
	 */
	public static function authorize( WP_REST_Request $request, ?int $declared = null ): bool|WP_Error {
		// Idempotent per request. WordPress core re-runs permission callbacks after dispatch
		// (rest_send_allow_header builds the Allow header from them) with the SAME request. That
		// second call must not touch the replay store and must not clear the verified state, or the
		// response leaves unsigned. The shortcut applies only when this request, byte for byte
		// (method, path, query, body, every OVP header), was already verified in THIS PHP request;
		// the crypto verdict is reused, the tier gate and impersonation are evaluated again.
		if ( null !== self::$verified && self::$verified->ok && null !== self::$verified_fp ) {
			$again = self::fingerprint( $request );
			if ( null !== $again && hash_equals( self::$verified_fp, $again ) ) {
				return self::tier_gate( self::$verified, (string) self::logical_path( $request ), strtoupper( (string) $request->get_method() ), $declared );
			}
		}
		self::reset();
		$deny = static function ( string $reason, int $status = 401 ): WP_Error {
			self::$last_reject = $reason;
			// Generic body on purpose; the reason is for tests and local debugging only.
			return new WP_Error( 'ovp_unauthorized', 'Unauthorized.', array( 'status' => $status ) );
		};

		if ( ! Ovp_Keys::is_bound() ) {
			self::mark_remote();
			return $deny( 'unbound' );
		}
		$method = strtoupper( (string) $request->get_method() );
		$path   = self::logical_path( $request );
		if ( null === $path ) {
			self::mark_remote();
			return $deny( 'bad_path' );
		}

		$headers = array();
		foreach ( array( 'sig', 'kid', 'ts', 'nonce', 'tier', 'site' ) as $h ) {
			$v = $request->get_header( 'x_ovp_' . $h );
			if ( is_string( $v ) ) {
				$headers[ 'x-ovp-' . $h ] = $v;
			}
		}
		$max = 1 === preg_match( self::UPLOAD_PATH_RE, $path ) ? Ovp_Proto::MAX_BODY_UPLOAD : Ovp_Proto::MAX_BODY_JSON;
		$res = Ovp_Proto::verify_request(
			array(
				'method'  => $method,
				'path'    => $path,
				'query'   => self::raw_query(),
				'body'    => (string) $request->get_body(),
				'headers' => $headers,
			),
			static fn( string $kid, string $site ): ?string => Ovp_Keys::lookup_master( $kid, $site ),
			self::nonce_store(),
			time(),
			$max,
			Ovp_Keys::site_id(),
			'm'
		);
		if ( ! $res->ok ) {
			return $deny( $res->reason, $res->status );
		}
		self::$verified    = $res;
		self::$verified_fp = self::fingerprint( $request );
		return self::tier_gate( $res, $path, $method, $declared );
	}

	/**
	 * Tier authorisation and service-account impersonation for a cryptographically verified request.
	 * Safe to run more than once per request; it never changes the verified state.
	 *
	 * @return true|WP_Error
	 */
	private static function tier_gate( Ovp_Result $res, string $path, string $method, ?int $declared ): bool|WP_Error {
		$is_revoke = '/' . OVP_FLEET_REST_NAMESPACE . '/revoke' === $path;
		$required  = $is_revoke ? 0 : self::required_tier( $method, $path, $declared );
		$eff       = Ovp_Tiers::effective( $res->tier );
		if ( $required > $eff ) {
			return new WP_Error(
				'ovp_tier_denied',
				'This operation needs ' . Ovp_Tiers::name( $required ) . ' but the effective tier is ' . Ovp_Tiers::name( $eff ) . '. The site admin controls the cap in wp-admin.',
				array(
					'status'    => 403,
					'code'      => 'tier_denied',
					'required'  => Ovp_Tiers::name( $required ),
					'effective' => Ovp_Tiers::name( $eff ),
				)
			);
		}

		if ( ! $is_revoke ) {
			self::impersonate();
		}
		return true;
	}

	/**
	 * Identity of the signed request as received: method, logical path, raw query, body hash, the six
	 * OVP headers and the PHP request start time. Null when the path does not resolve.
	 */
	private static function fingerprint( WP_REST_Request $request ): ?string {
		$path = self::logical_path( $request );
		if ( null === $path ) {
			return null;
		}
		$parts = array(
			strtoupper( (string) $request->get_method() ),
			$path,
			self::raw_query(),
			hash( 'sha256', (string) $request->get_body() ),
			isset( $_SERVER['REQUEST_TIME_FLOAT'] ) && is_scalar( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (string) $_SERVER['REQUEST_TIME_FLOAT'] : '',
		);
		foreach ( array( 'sig', 'kid', 'ts', 'nonce', 'tier', 'site' ) as $h ) {
			$v       = $request->get_header( 'x_ovp_' . $h );
			$parts[] = is_string( $v ) ? $v : '';
		}
		return hash( 'sha256', implode( "\n", $parts ) );
	}

	/* ---------- T1 two-call confirm ---------- */

	/**
	 * Run before a route callback. Returns null to proceed, or the plan/error response.
	 *
	 * @return WP_REST_Response|WP_Error|null
	 */
	public static function confirm_gate( WP_REST_Request $request, ?int $declared = null ): WP_REST_Response|WP_Error|null {
		$res = self::$verified;
		if ( null === $res || ! $res->ok ) {
			return new WP_Error( 'ovp_unauthorized', 'Unauthorized.', array( 'status' => 401 ) );
		}
		$method = strtoupper( (string) $request->get_method() );
		$path   = self::logical_path( $request );
		if ( null === $path ) {
			return new WP_Error( 'ovp_unauthorized', 'Unauthorized.', array( 'status' => 401 ) );
		}
		if ( 1 !== self::required_tier( $method, $path, $declared ) || in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return null;
		}

		// Token sources, first hit wins: X-OVP-Confirm header, JSON body field, query param confirm_token.
		// All three travel inside the signed envelope; none is part of the change hash.
		$token = $request->get_header( 'x_ovp_confirm' );
		$token = is_string( $token ) && '' !== $token ? $token : null;
		if ( null === $token ) {
			$token = self::query_token();
		}
		$body  = (string) $request->get_body();
		$json  = json_decode( $body, true, 32 );
		$args  = array();
		if ( is_array( $json ) ) {
			if ( null === $token && isset( $json['confirm_token'] ) && is_string( $json['confirm_token'] ) && '' !== $json['confirm_token'] ) {
				$token = $json['confirm_token'];
			}
			unset( $json['confirm_token'] );
			$args['body'] = $json;
		} elseif ( '' !== $body ) {
			$args['body_sha256'] = hash( 'sha256', $body );
		}
		$query = array();
		foreach ( explode( '&', self::raw_query() ) as $seg ) {
			if ( '' === $seg || str_starts_with( $seg, 'rest_route=' ) || str_starts_with( $seg, 'confirm_token=' ) ) {
				continue;
			}
			$query[] = $seg;
		}
		sort( $query, SORT_STRING );
		$args['query'] = $query;

		$brief = (string) json_encode( $args['body'] ?? array(), JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR );
		$plan  = array(
			'request' => $method . ' ' . $path,
			'summary' => 'Nothing has been executed. Repeat the same request with the confirm token to apply it.',
			'args'    => strlen( $brief ) <= 8192 ? $args : array( 'keys' => is_array( $json ) ? array_slice( array_keys( $json ), 0, 50 ) : array(), 'bytes' => strlen( $body ) ),
		);

		try {
			$g = Ovp_Tiers::t1_gate( $res->site_id, $method . ' ' . $path, $args, $token, self::nonce_store(), '', $plan );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'ovp_confirm_failed', 'Could not build the confirmation plan.', array( 'status' => 400 ) );
		}
		if ( 'execute' === $g['action'] ) {
			return null;
		}
		if ( 'plan' === $g['action'] ) {
			unset( $g['status'] );
			return new WP_REST_Response( $g, 200 );
		}
		return new WP_Error( (string) $g['code'], 'Confirmation failed: ' . (string) $g['code'] . '.', array( 'status' => (int) $g['status'] ) );
	}

	/** Value of the confirm_token query parameter (raw query, percent-decoded once), or null. */
	private static function query_token(): ?string {
		foreach ( explode( '&', self::raw_query() ) as $seg ) {
			if ( str_starts_with( $seg, 'confirm_token=' ) ) {
				$v = rawurldecode( substr( $seg, 14 ) );
				return '' !== $v && strlen( $v ) <= 1024 ? $v : null;
			}
		}
		return null;
	}

	/* ---------- signed responses ---------- */

	private static function emit( string $line ): void {
		if ( null !== self::$header_sink ) {
			( self::$header_sink )( $line );
			return;
		}
		if ( ! headers_sent() ) {
			header( $line );
		}
	}

	/**
	 * Compute the response signature headers (bound to the request nonce). Used directly by the
	 * revoke handler, which must sign before it wipes the keys.
	 *
	 * @return array<string,string>
	 */
	public static function response_headers( int $status, string $json, string $nonce ): array {
		$kid  = Ovp_Keys::plugin_kid();
		$site = Ovp_Keys::site_id();
		$ts   = time();
		$sig  = Ovp_Keys::sign( Ovp_Proto::canonical_response( $status, $json, $ts, $nonce, $site, $kid ) );
		return array(
			'X-OVP-Sig' => Ovp_Proto::b64e( $sig ),
			'X-OVP-Kid' => $kid,
			'X-OVP-Ts'  => (string) $ts,
		);
	}

	/** Called by the revoke handler before wiping keys; the filter then emits exactly this. */
	public static function presign( int $status, string $json ): void {
		if ( null === self::$verified ) {
			return;
		}
		self::$presigned = array(
			'body'    => $json,
			'headers' => self::response_headers( $status, $json, self::$verified->nonce ),
			'nonce'   => self::$verified->nonce,
		);
	}

	public static function clear_presigned(): void {
		self::$presigned = null;
	}

	/**
	 * rest_pre_serve_request: serve verified requests ourselves so the body is signed.
	 *
	 * @param mixed $served
	 * @param mixed $result
	 * @return mixed
	 */
	public static function sign_response( $served, $result, $request, $server ) {
		if ( $served || null === self::$verified || ! self::$verified->ok || ! ( $request instanceof WP_REST_Request ) || ! is_object( $result ) || ! method_exists( $result, 'get_status' ) ) {
			return $served;
		}
		if ( 'HEAD' === strtoupper( (string) $request->get_method() ) || (string) $request->get_header( 'x_ovp_nonce' ) !== self::$verified->nonce ) {
			return $served;
		}
		try {
			$status = (int) $result->get_status();
			if ( null !== self::$presigned && self::$presigned['nonce'] === self::$verified->nonce ) {
				$json    = self::$presigned['body'];
				$headers = self::$presigned['headers'];
			} else {
				$data = is_object( $server ) && method_exists( $server, 'response_to_data' ) ? $server->response_to_data( $result, false ) : $result->get_data();
				$json = wp_json_encode( $data );
				if ( ! is_string( $json ) ) {
					$status = 500;
					$json   = '{"ok":false,"error":"encode_failed"}';
					if ( function_exists( 'http_response_code' ) && ! headers_sent() ) {
						http_response_code( 500 );
					}
				}
				$headers = self::response_headers( $status, $json, self::$verified->nonce );
			}
		} catch ( \Throwable $e ) {
			return $served; // Unsigned response: the master treats it as a failure.
		}
		foreach ( $headers as $k => $v ) {
			self::emit( $k . ': ' . $v );
		}
		self::emit( 'Cache-Control: no-store' );
		self::emit( 'X-Content-Type-Options: nosniff' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput -- signed JSON body, byte-exact.
		// The response is out: close the idempotency window so nothing later in a long-lived PHP
		// worker can re-enter authorize() with this envelope and skip the replay store.
		self::$verified_fp = null;
		return true;
	}

	/* ---------- service account ---------- */

	public static function impersonate(): void {
		$user_id = self::get_or_create_service_account();
		if ( $user_id > 0 ) {
			wp_set_current_user( $user_id );
		}
	}

	/**
	 * Hidden internal administrator used only for capability-check impersonation during
	 * authenticated REST requests. Excluded from Users lists via pre_user_query.
	 */
	public static function get_or_create_service_account(): int {
		$existing = get_user_by( 'login', OVP_FLEET_SERVICE_USER_LOGIN );
		if ( $existing instanceof WP_User ) {
			return $existing->ID;
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => OVP_FLEET_SERVICE_USER_LOGIN,
				'user_pass'    => wp_generate_password( 64, true, true ),
				'user_email'   => 'mcp-bridge-service@' . self::site_email_domain(),
				'display_name' => 'Overlord WP Fleet (service account)',
				'role'         => 'administrator',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return 0;
		}

		update_user_meta( $user_id, OVP_FLEET_SERVICE_USER_META, 1 );
		update_user_meta( $user_id, 'mcp_bridge_hidden', 1 );

		return (int) $user_id;
	}

	private static function site_email_domain(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return is_string( $host ) && '' !== $host ? $host : 'example.invalid';
	}
}
