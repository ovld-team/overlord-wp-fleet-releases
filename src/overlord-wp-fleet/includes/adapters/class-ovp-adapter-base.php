<?php
/**
 * Base class for meta adapters (design section 7). An adapter owns a family of keys
 * (Elementor, Yoast, ...), validates a write and expands it into one or more concrete
 * meta writes. Reads never go through adapters except to pick state keys for hashing.
 *
 * plan() result:
 *   array( 'writes' => array( array( 'key' => string, 'value' => mixed ), ... ) )
 *   array( 'error' => string code, 'message' => string )
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class OVP_Adapter {

	/** Stable adapter id used in the `adapter` request hint. */
	abstract public function id(): string;

	/**
	 * Does this adapter govern (type, key)?
	 *
	 * @param array<string, mixed> $ctx adapter (hint), post_type, ref (value of "_key" sibling), field_key
	 */
	abstract public function handles( string $type, string $key, array $ctx ): bool;

	/** @return array<string, string> logical alias => actual meta key */
	public function keymap(): array {
		return array();
	}

	/**
	 * Declarative validation: actual key => spec. Types: string(max,line), url, enum(values),
	 * int(min,max), bool01, yesno, numeric(min), json, list_enum(values), any.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function rules(): array {
		return array();
	}

	/** Max serialized/raw size accepted for one value. */
	public function max_bytes(): int {
		return 1048576;
	}

	/** Meta keys whose combined state forms the concurrency hash for $key. */
	public function state_keys( string $key, array $ctx ): array {
		return array( $this->resolve_key( $key ) );
	}

	/**
	 * Optional adapter-native writer (e.g. WooCommerce CRUD). Return true when handled,
	 * false/null to fall back to the generic meta write.
	 *
	 * @param mixed $value
	 */
	public function custom_write( string $type, int $id, string $key, mixed $value ): ?bool {
		return null;
	}

	/** Hook after a successful write (cache regeneration etc.). Must never throw. */
	public function after_write( string $type, int $id, string $key, OVP_Meta_Backend $backend ): void {}

	/** Discovery info for tools. */
	public function describe(): array {
		return array(
			'id'    => $this->id(),
			'keys'  => array_values( array_unique( array_merge( array_keys( $this->rules() ), array_values( $this->keymap() ) ) ) ),
			'alias' => $this->keymap(),
		);
	}

	public function resolve_key( string $key ): string {
		$map = $this->keymap();
		return $map[ $key ] ?? $key;
	}

	/**
	 * @param mixed                $value
	 * @param array<string, mixed> $ctx
	 * @return array<string, mixed>
	 */
	public function plan( string $type, int $id, string $key, mixed $value, array $ctx ): array {
		$actual = $this->resolve_key( $key );
		$rules  = $this->rules();
		if ( ! isset( $rules[ $actual ] ) ) {
			return self::fail( 'ovp_adapter_key', 'Key is not managed by adapter ' . $this->id() . '.' );
		}
		$res = self::coerce( $rules[ $actual ], $value );
		if ( ! $res['ok'] ) {
			return self::fail( 'ovp_adapter_invalid', $actual . ': ' . $res['message'] );
		}
		return self::writes( array( array( 'key' => $actual, 'value' => $res['value'] ) ) );
	}

	/** @param array<int, array{key:string,value:mixed}> $w */
	protected static function writes( array $w ): array {
		return array( 'writes' => $w );
	}

	protected static function fail( string $code, string $message ): array {
		return array( 'error' => $code, 'message' => $message );
	}

	/**
	 * @param array<string, mixed> $spec
	 * @return array{ok:bool,value?:mixed,message?:string}
	 */
	public static function coerce( array $spec, mixed $v ): array {
		$t = (string) ( $spec['t'] ?? 'any' );
		switch ( $t ) {
			case 'string':
				if ( ! is_scalar( $v ) ) {
					return array( 'ok' => false, 'message' => 'expected string' );
				}
				$s = self::strip_tags( (string) $v );
				if ( ! empty( $spec['line'] ) ) {
					$s = trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
				}
				if ( 1 === preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $s ) ) {
					return array( 'ok' => false, 'message' => 'control characters not allowed' );
				}
				if ( mb_strlen( $s ) > (int) ( $spec['max'] ?? 1000 ) ) {
					return array( 'ok' => false, 'message' => 'too long (max ' . (int) ( $spec['max'] ?? 1000 ) . ')' );
				}
				return array( 'ok' => true, 'value' => $s );
			case 'url':
				if ( ! is_string( $v ) ) {
					return array( 'ok' => false, 'message' => 'expected URL string' );
				}
				$v = trim( $v );
				if ( '' === $v ) {
					return array( 'ok' => true, 'value' => '' );
				}
				if ( strlen( $v ) > 2048 || 1 !== preg_match( '#^https?://[^\s<>"\']+$#i', $v ) ) {
					return array( 'ok' => false, 'message' => 'must be an http(s) URL' );
				}
				return array( 'ok' => true, 'value' => $v );
			case 'enum':
				$vals = array_map( 'strval', (array) ( $spec['values'] ?? array() ) );
				if ( ! is_scalar( $v ) || ! in_array( (string) $v, $vals, true ) ) {
					return array( 'ok' => false, 'message' => 'must be one of: ' . implode( ', ', $vals ) );
				}
				return array( 'ok' => true, 'value' => (string) $v );
			case 'int':
				if ( ! is_int( $v ) && ! ( is_string( $v ) && 1 === preg_match( '/^-?\d{1,15}$/', $v ) ) ) {
					return array( 'ok' => false, 'message' => 'expected integer' );
				}
				$n = (int) $v;
				if ( $n < (int) ( $spec['min'] ?? PHP_INT_MIN ) || $n > (int) ( $spec['max'] ?? PHP_INT_MAX ) ) {
					return array( 'ok' => false, 'message' => 'integer out of range' );
				}
				return array( 'ok' => true, 'value' => $n );
			case 'bool01':
				if ( in_array( $v, array( true, 1, '1', 'true' ), true ) ) {
					return array( 'ok' => true, 'value' => '1' );
				}
				if ( in_array( $v, array( false, 0, '0', 'false', '' ), true ) ) {
					return array( 'ok' => true, 'value' => '0' );
				}
				return array( 'ok' => false, 'message' => 'expected boolean' );
			case 'yesno':
				if ( in_array( $v, array( true, 1, '1', 'true', 'yes' ), true ) ) {
					return array( 'ok' => true, 'value' => 'yes' );
				}
				if ( in_array( $v, array( false, 0, '0', 'false', 'no', '' ), true ) ) {
					return array( 'ok' => true, 'value' => '' );
				}
				return array( 'ok' => false, 'message' => 'expected yes/no' );
			case 'numeric':
				if ( ! is_int( $v ) && ! is_float( $v ) && ! ( is_string( $v ) && 1 === preg_match( '/^\d{1,12}(\.\d{1,6})?$/', $v ) ) ) {
					return array( 'ok' => false, 'message' => 'expected non-negative decimal number' );
				}
				if ( (float) $v < (float) ( $spec['min'] ?? 0 ) ) {
					return array( 'ok' => false, 'message' => 'number below minimum' );
				}
				return array( 'ok' => true, 'value' => (string) $v );
			case 'json':
				if ( is_array( $v ) ) {
					$enc = json_encode( $v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
					return false === $enc ? array( 'ok' => false, 'message' => 'not JSON encodable' ) : array( 'ok' => true, 'value' => $enc );
				}
				if ( ! is_string( $v ) ) {
					return array( 'ok' => false, 'message' => 'expected JSON' );
				}
				json_decode( $v );
				return JSON_ERROR_NONE === json_last_error() ? array( 'ok' => true, 'value' => $v ) : array( 'ok' => false, 'message' => 'invalid JSON' );
			case 'list_enum':
				$vals = array_map( 'strval', (array) ( $spec['values'] ?? array() ) );
				if ( ! is_array( $v ) || ! array_is_list( $v ) || count( $v ) > 16 ) {
					return array( 'ok' => false, 'message' => 'expected list' );
				}
				foreach ( $v as $x ) {
					if ( ! is_string( $x ) || ! in_array( $x, $vals, true ) ) {
						return array( 'ok' => false, 'message' => 'list values must be one of: ' . implode( ', ', $vals ) );
					}
				}
				return array( 'ok' => true, 'value' => array_values( array_unique( $v ) ) );
			default:
				return array( 'ok' => true, 'value' => $v );
		}
	}

	protected static function strip_tags( string $s ): string {
		if ( function_exists( 'wp_strip_all_tags' ) ) {
			return (string) wp_strip_all_tags( $s );
		}
		return strip_tags( $s );
	}
}
