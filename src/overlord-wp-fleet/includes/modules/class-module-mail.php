<?php
/**
 * /ovp/v1/mail/* (2.1.0): SMTP settings and an email template manager.
 *
 * SMTP: the settings live in the option ovp_mail_smtp (never the password). The password is
 * encrypted (libsodium secretbox, key derived from the wp-config salts, the way Ovp_Keys wraps the
 * site key) and stored in its own option ovp_mail_smtp_pass. No route, log line or error message
 * returns it. smtp_set is T2 and needs confirm=true; the master adds the two-call confirm on top.
 * The phpmailer_init hook is registered at plugin load (Ovp_Module_Mail::hooks) and only acts when
 * SMTP is enabled with a host.
 *
 * Templates: stored in the option ovp_mail_templates, bodies sanitised with wp_kses_post, subjects
 * stripped of tags and line breaks. Placeholders are {{name}}; values are HTML-escaped. wp_mail is
 * never hooked: templates are only used by preview and send_test.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ovp_Module_Mail extends Ovp_Module_Base {

	/** Tier per tool, consumed by the route-table test. */
	public const OVP_TIER = array(
		'smtp_get'                => 'T0',
		'smtp_set'                => 'T2',
		'smtp_test'               => 'T2',
		'email_template_list'     => 'T0',
		'email_template_get'      => 'T0',
		'email_template_set'      => 'T2',
		'email_template_delete'   => 'T2',
		'email_template_preview'  => 'T0',
		'email_template_send_test' => 'T2',
	);

	public const SMTP_OPTION      = 'ovp_mail_smtp';
	public const PASS_OPTION      = 'ovp_mail_smtp_pass';
	public const TEMPLATES_OPTION = 'ovp_mail_templates';
	public const THROTTLE_KEY     = 'ovp_mail_test_last';

	private const MAX_TEMPLATES  = 50;
	private const MAX_BODY_BYTES = 100000;
	private const ID_RE          = '[a-z0-9][a-z0-9_-]{0,63}';

	/** Registered at plugin load, not on rest_api_init: mail is sent on any request. */
	public static function hooks(): void {
		add_action( 'phpmailer_init', array( __CLASS__, 'configure_phpmailer' ) );
	}

	public function register_routes(): void {
		$this->route( 'GET', 'mail/smtp', array( $this, 'smtp_get' ) );
		$this->route( 'POST', 'mail/smtp', array( $this, 'smtp_set' ) );
		$this->route( 'POST', 'mail/smtp/test', array( $this, 'smtp_test' ) );
		$this->route( 'GET', 'mail/templates', array( $this, 'template_list' ) );
		$this->route( 'GET', 'mail/templates/(?P<id>' . self::ID_RE . ')', array( $this, 'template_get' ) );
		$this->route( 'POST', 'mail/templates/(?P<id>' . self::ID_RE . ')', array( $this, 'template_set' ) );
		$this->route( 'DELETE', 'mail/templates/(?P<id>' . self::ID_RE . ')', array( $this, 'template_delete' ) );
		$this->route( 'POST', 'mail/templates/(?P<id>' . self::ID_RE . ')/preview', array( $this, 'template_preview' ) );
		$this->route( 'POST', 'mail/templates/(?P<id>' . self::ID_RE . ')/send-test', array( $this, 'template_send_test' ) );
	}

	/* ---------- SMTP ---------- */

	/** @return array{enabled:bool,host:string,port:int,encryption:string,auth:bool,username:string,from_email:string,from_name:string} */
	public static function smtp_config(): array {
		$v = get_option( self::SMTP_OPTION, array() );
		$v = is_array( $v ) ? $v : array();
		$e = (string) ( $v['encryption'] ?? 'tls' );
		return array(
			'enabled'    => ! empty( $v['enabled'] ),
			'host'       => (string) ( $v['host'] ?? '' ),
			'port'       => max( 1, min( 65535, (int) ( $v['port'] ?? 587 ) ) ),
			'encryption' => in_array( $e, array( 'none', 'ssl', 'tls' ), true ) ? $e : 'tls',
			'auth'       => ! array_key_exists( 'auth', $v ) || ! empty( $v['auth'] ),
			'username'   => (string) ( $v['username'] ?? '' ),
			'from_email' => (string) ( $v['from_email'] ?? '' ),
			'from_name'  => (string) ( $v['from_name'] ?? '' ),
		);
	}

	public static function has_password(): bool {
		return '' !== (string) get_option( self::PASS_OPTION, '' );
	}

	/** 32-byte key from the wp-config secrets (same material rule as Ovp_Keys); '' when none is strong enough. */
	private static function secret_key(): string {
		$material = '';
		if ( defined( 'OVP_KEY_WRAP_SECRET' ) ) {
			$material = (string) constant( 'OVP_KEY_WRAP_SECRET' );
		} else {
			foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT' ) as $c ) {
				$material .= defined( $c ) ? (string) constant( $c ) : '';
			}
		}
		if ( strlen( $material ) < 32 || str_contains( $material, 'put your unique phrase here' ) ) {
			return '';
		}
		return hash_hmac( 'sha256', 'ovp-mail-smtp-v1', $material, true );
	}

	/** Encrypt for storage; null when no strong secret or no sodium is available (the caller refuses). */
	private static function seal( string $plain ): ?string {
		$key = self::secret_key();
		if ( '' === $key || ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return null;
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 'v1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $key ) );
	}

	private static function unseal( string $stored ): string {
		$key = self::secret_key();
		if ( '' === $key || ! str_starts_with( $stored, 'v1:' ) || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 3 ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$out = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $key );
		return false === $out ? '' : $out;
	}

	/**
	 * phpmailer_init: route mail through the configured SMTP server. Never turns on SMTPDebug.
	 *
	 * @param object $phpmailer PHPMailer instance (passed by reference by WordPress; objects need no &).
	 */
	public static function configure_phpmailer( $phpmailer ): void {
		$c = self::smtp_config();
		if ( ! $c['enabled'] || '' === $c['host'] || ! is_object( $phpmailer ) ) {
			return;
		}
		$phpmailer->isSMTP();
		$phpmailer->Host       = $c['host'];
		$phpmailer->Port       = $c['port'];
		$phpmailer->SMTPSecure = 'none' === $c['encryption'] ? '' : $c['encryption'];
		$phpmailer->SMTPAutoTLS = 'none' !== $c['encryption'];
		$phpmailer->SMTPDebug  = 0;
		if ( $c['auth'] && '' !== $c['username'] ) {
			$phpmailer->SMTPAuth = true;
			$phpmailer->Username = $c['username'];
			$phpmailer->Password = self::unseal( (string) get_option( self::PASS_OPTION, '' ) );
		} else {
			$phpmailer->SMTPAuth = false;
		}
		if ( '' !== $c['from_email'] && ( ! function_exists( 'is_email' ) || is_email( $c['from_email'] ) ) ) {
			try {
				$phpmailer->setFrom( $c['from_email'], $c['from_name'], false );
			} catch ( \Throwable $e ) {
				unset( $e ); // Keep WordPress's own From.
			}
		}
	}

	/** @return array<string,mixed> What smtp_get returns: the settings and has_password, never the password. */
	private function smtp_view(): array {
		return self::smtp_config() + array( 'has_password' => self::has_password() );
	}

	public function smtp_get(): WP_REST_Response {
		return $this->success( $this->smtp_view() );
	}

	/**
	 * Body: enabled, host, port, encryption none|ssl|tls, auth, username, password (empty or missing keeps
	 * the stored one), clear_password, from_email, from_name, confirm (required). Only given keys change.
	 */
	public function smtp_set( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = (array) $request->get_json_params();
		if ( empty( $body['confirm'] ) ) {
			return $this->error( 'mcp_bridge_confirm_required', 'smtp_set needs confirm=true.', 403 );
		}
		$cur = self::smtp_config();
		$new = $cur;
		if ( array_key_exists( 'host', $body ) ) {
			$host = strtolower( trim( $this->str( $body['host'] ) ) );
			if ( '' !== $host && 1 !== preg_match( '/^[a-z0-9]([a-z0-9.\-]{0,251}[a-z0-9])?$/', $host ) && false === filter_var( $host, FILTER_VALIDATE_IP ) ) {
				return $this->error( 'mcp_bridge_invalid_payload', 'host must be a hostname or an IP address.', 400 );
			}
			$new['host'] = $host;
		}
		if ( array_key_exists( 'port', $body ) ) {
			$port = is_int( $body['port'] ) ? $body['port'] : ( is_string( $body['port'] ) && ctype_digit( $body['port'] ) ? (int) $body['port'] : 0 );
			if ( $port < 1 || $port > 65535 ) {
				return $this->error( 'mcp_bridge_invalid_payload', 'port must be 1-65535.', 400 );
			}
			$new['port'] = $port;
		}
		if ( array_key_exists( 'encryption', $body ) ) {
			$enc = $this->str( $body['encryption'] );
			if ( ! in_array( $enc, array( 'none', 'ssl', 'tls' ), true ) ) {
				return $this->error( 'mcp_bridge_invalid_payload', 'encryption must be none, ssl or tls.', 400 );
			}
			$new['encryption'] = $enc;
		}
		if ( array_key_exists( 'auth', $body ) ) {
			$new['auth'] = (bool) $body['auth'];
		}
		if ( array_key_exists( 'username', $body ) ) {
			$user = $this->str( $body['username'] );
			if ( strlen( $user ) > 255 || 1 === preg_match( '/[\x00-\x1f\x7f]/', $user ) ) {
				return $this->error( 'mcp_bridge_invalid_payload', 'username is too long or has control characters.', 400 );
			}
			$new['username'] = $user;
		}
		if ( array_key_exists( 'from_email', $body ) ) {
			$fe = trim( $this->str( $body['from_email'] ) );
			if ( '' !== $fe && ( ! function_exists( 'is_email' ) || ! is_email( $fe ) || 1 === preg_match( '/[\r\n]/', $fe ) ) ) {
				return $this->error( 'mcp_bridge_invalid_payload', 'from_email is not a valid address.', 400 );
			}
			$new['from_email'] = $fe;
		}
		if ( array_key_exists( 'from_name', $body ) ) {
			$new['from_name'] = substr( trim( preg_replace( '/[\x00-\x1f\x7f]/', '', strip_tags( $this->str( $body['from_name'] ) ) ) ?? '' ), 0, 100 );
		}
		if ( array_key_exists( 'enabled', $body ) ) {
			$new['enabled'] = (bool) $body['enabled'];
		}
		if ( $new['enabled'] && '' === $new['host'] ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Set a host before enabling SMTP.', 400 );
		}

		$pass_change = null;
		if ( ! empty( $body['clear_password'] ) ) {
			$pass_change = '';
		} elseif ( isset( $body['password'] ) && '' !== $this->str( $body['password'] ) ) {
			$pw = $this->str( $body['password'] );
			if ( strlen( $pw ) > 512 || 1 === preg_match( '/[\x00-\x1f\x7f]/', $pw ) ) {
				return $this->error( 'mcp_bridge_invalid_payload', 'password is too long or has control characters.', 400 );
			}
			$sealed = self::seal( $pw );
			if ( null === $sealed ) {
				return $this->error( 'ovp_mail_no_secret_store', 'Cannot encrypt the password: wp-config.php salts are missing or default, or libsodium is unavailable.', 500 );
			}
			$pass_change = $sealed;
		}

		update_option( self::SMTP_OPTION, $new, false );
		if ( null !== $pass_change ) {
			update_option( self::PASS_OPTION, $pass_change, false );
		}
		return $this->success( $this->smtp_view() );
	}

	/** Remaining throttle seconds for outgoing test mail (shared by smtp_test and send-test); 0 = go. */
	private function throttled(): int {
		$last = (int) get_transient( self::THROTTLE_KEY );
		$wait = $last > 0 ? 10 - ( time() - $last ) : 0;
		if ( $wait > 0 ) {
			return $wait;
		}
		set_transient( self::THROTTLE_KEY, time(), 60 );
		return 0;
	}

	/**
	 * Send one mail and report. The error text has the SMTP password and e-mail addresses removed.
	 *
	 * @param array<int,string> $headers
	 * @return array{sent:bool,error:string}
	 */
	private function deliver( string $to, string $subject, string $message, array $headers ): array {
		$err = '';
		$cb  = static function ( $e ) use ( &$err ): void {
			$err = $e instanceof WP_Error ? $e->get_error_message() : 'unknown';
		};
		add_action( 'wp_mail_failed', $cb );
		try {
			$sent = (bool) wp_mail( $to, $subject, $message, $headers );
		} catch ( \Throwable $e ) {
			$sent = false;
			$err  = $e->getMessage();
		}
		if ( function_exists( 'remove_action' ) ) {
			remove_action( 'wp_mail_failed', $cb );
		}
		$pw = self::unseal( (string) get_option( self::PASS_OPTION, '' ) );
		if ( '' !== $pw ) {
			$err = str_replace( $pw, '[redacted]', $err );
		}
		return array( 'sent' => $sent, 'error' => $sent ? '' : ( '' !== $err ? Ovp_Errors::scrub( $err ) : 'wp_mail returned false.' ) );
	}

	/** Body: to (required). Sends a fixed plain-text message through the current mail settings. */
	public function smtp_test( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = (array) $request->get_json_params();
		$to   = trim( $this->str( $body['to'] ?? '' ) );
		if ( '' === $to || ! function_exists( 'is_email' ) || ! is_email( $to ) ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Provide "to" as one valid e-mail address.', 400 );
		}
		$wait = $this->throttled();
		if ( $wait > 0 ) {
			return $this->error( 'ovp_mail_throttled', 'Wait ' . $wait . ' seconds before the next test mail.', 429 );
		}
		$res = $this->deliver( $to, 'Overlord WP Fleet SMTP test', "This is a test message from Overlord WP Fleet.\nIf you can read it, outgoing mail works.", array() );
		return $this->success( $res + array( 'smtp_enabled' => self::smtp_config()['enabled'] ) );
	}

	/* ---------- templates ---------- */

	/** @return array<string,array{subject:string,body:string,updated:int}> */
	private function templates(): array {
		$t = get_option( self::TEMPLATES_OPTION, array() );
		return is_array( $t ) ? $t : array();
	}

	private function template_id( WP_REST_Request $request ): string {
		return $this->str( $request->get_param( 'id' ) );
	}

	public function template_list(): WP_REST_Response {
		$out = array();
		foreach ( $this->templates() as $id => $t ) {
			$out[] = array( 'id' => (string) $id, 'subject' => (string) ( $t['subject'] ?? '' ), 'bytes' => strlen( (string) ( $t['body'] ?? '' ) ), 'updated' => (int) ( $t['updated'] ?? 0 ) );
		}
		return $this->success( array( 'templates' => $out, 'count' => count( $out ) ) );
	}

	public function template_get( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = $this->template_id( $request );
		$t  = $this->templates()[ $id ] ?? null;
		if ( ! is_array( $t ) ) {
			return $this->error( 'ovp_template_not_found', 'No such template.', 404 );
		}
		return $this->success( array( 'id' => $id, 'subject' => (string) $t['subject'], 'body' => (string) $t['body'], 'updated' => (int) ( $t['updated'] ?? 0 ) ) );
	}

	/** Body: subject, body (HTML, sanitised with wp_kses_post). */
	public function template_set( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id   = $this->template_id( $request );
		$body = (array) $request->get_json_params();
		if ( ! isset( $body['subject'], $body['body'] ) || ! is_string( $body['subject'] ) || ! is_string( $body['body'] ) ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Provide "subject" and "body" as strings.', 400 );
		}
		if ( strlen( $body['body'] ) > self::MAX_BODY_BYTES ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'body is larger than ' . self::MAX_BODY_BYTES . ' bytes.', 413 );
		}
		$subject = $this->clean_subject( $body['subject'] );
		if ( '' === $subject ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'subject must not be empty.', 400 );
		}
		$all = $this->templates();
		if ( ! isset( $all[ $id ] ) && count( $all ) >= self::MAX_TEMPLATES ) {
			return $this->error( 'ovp_template_limit', 'At most ' . self::MAX_TEMPLATES . ' templates.', 409 );
		}
		$all[ $id ] = array( 'subject' => $subject, 'body' => wp_kses_post( $body['body'] ), 'updated' => time() );
		update_option( self::TEMPLATES_OPTION, $all, false );
		return $this->success( array( 'id' => $id, 'subject' => $all[ $id ]['subject'], 'bytes' => strlen( $all[ $id ]['body'] ), 'updated' => $all[ $id ]['updated'] ) );
	}

	public function template_delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id  = $this->template_id( $request );
		$all = $this->templates();
		if ( ! isset( $all[ $id ] ) ) {
			return $this->error( 'ovp_template_not_found', 'No such template.', 404 );
		}
		unset( $all[ $id ] );
		update_option( self::TEMPLATES_OPTION, $all, false );
		return $this->success( array( 'id' => $id, 'deleted' => true ) );
	}

	private function clean_subject( string $s ): string {
		return substr( trim( (string) preg_replace( '/[\x00-\x1f\x7f]+/', ' ', strip_tags( $s ) ) ), 0, 200 );
	}

	/**
	 * Fill {{name}} placeholders. Values are HTML-escaped in the body and plain in the subject.
	 *
	 * @param array<string,mixed> $vars
	 * @return array{subject:string,html:string,unresolved:list<string>}
	 */
	private function render( array $t, array $vars ): array {
		$clean = array(
			'site_name' => (string) get_option( 'blogname', '' ),
			'site_url'  => home_url(),
		);
		$n = 0;
		foreach ( $vars as $k => $v ) {
			if ( ++$n > 30 ) {
				break;
			}
			if ( is_string( $k ) && 1 === preg_match( '/^[a-z0-9_]{1,40}$/', $k ) && ( is_string( $v ) || is_int( $v ) || is_float( $v ) ) ) {
				$clean[ $k ] = substr( (string) $v, 0, 1000 );
			}
		}
		$unresolved = array();
		$fill       = static function ( string $text, bool $html ) use ( $clean, &$unresolved ): string {
			return (string) preg_replace_callback(
				'/\{\{\s*([a-z0-9_]{1,40})\s*\}\}/',
				static function ( array $m ) use ( $clean, $html, &$unresolved ): string {
					if ( ! array_key_exists( $m[1], $clean ) ) {
						$unresolved[ $m[1] ] = true;
						return $m[0];
					}
					return $html ? esc_html( $clean[ $m[1] ] ) : $clean[ $m[1] ];
				},
				$text
			);
		};
		return array(
			'subject'    => $this->clean_subject( $fill( (string) $t['subject'], false ) ),
			'html'       => wp_kses_post( $fill( (string) $t['body'], true ) ),
			'unresolved' => array_keys( $unresolved ),
		);
	}

	/** Body: vars (object of scalar placeholder values). Renders only; sends nothing. */
	public function template_preview( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$t = $this->templates()[ $this->template_id( $request ) ] ?? null;
		if ( ! is_array( $t ) ) {
			return $this->error( 'ovp_template_not_found', 'No such template.', 404 );
		}
		$body = (array) $request->get_json_params();
		return $this->success( $this->render( $t, is_array( $body['vars'] ?? null ) ? $body['vars'] : array() ) );
	}

	/** Body: to (required), vars. Sends the rendered template as HTML mail to one address. */
	public function template_send_test( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$t = $this->templates()[ $this->template_id( $request ) ] ?? null;
		if ( ! is_array( $t ) ) {
			return $this->error( 'ovp_template_not_found', 'No such template.', 404 );
		}
		$body = (array) $request->get_json_params();
		$to   = trim( $this->str( $body['to'] ?? '' ) );
		if ( '' === $to || ! function_exists( 'is_email' ) || ! is_email( $to ) ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Provide "to" as one valid e-mail address.', 400 );
		}
		$wait = $this->throttled();
		if ( $wait > 0 ) {
			return $this->error( 'ovp_mail_throttled', 'Wait ' . $wait . ' seconds before the next test mail.', 429 );
		}
		$r   = $this->render( $t, is_array( $body['vars'] ?? null ) ? $body['vars'] : array() );
		$res = $this->deliver( $to, $r['subject'], $r['html'], array( 'Content-Type: text/html; charset=UTF-8' ) );
		return $this->success( $res + array( 'unresolved' => $r['unresolved'] ) );
	}
}
