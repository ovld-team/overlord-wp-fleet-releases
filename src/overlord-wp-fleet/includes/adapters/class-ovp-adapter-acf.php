<?php
/**
 * ACF adapter. ACF stores `name` plus a hidden `_name` => field_key reference; both must
 * be written or the field renders as empty in the editor. field_key comes from the
 * caller or from the existing `_name` reference.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-ovp-adapter-base.php';

final class OVP_Adapter_ACF extends OVP_Adapter {

	public function id(): string {
		return 'acf';
	}

	public function handles( string $type, string $key, array $ctx ): bool {
		if ( str_starts_with( $key, '_' ) || str_starts_with( $key, '@' ) ) {
			return false;
		}
		// D10: ACF options pages keep each field in the option `options_<name>` plus the hidden
		// `_options_<name>` field-key reference, so the adapter accepts option keys with that prefix.
		if ( 'option' === $type ) {
			return str_starts_with( $key, 'options_' ) && strlen( $key ) > 8
				&& ( ( $ctx['adapter'] ?? '' ) === 'acf' || 1 === preg_match( '/^field_[a-z0-9]{6,40}$/', (string) ( $ctx['field_key'] ?? '' ) ) );
		}
		if ( ! in_array( $type, array( 'post', 'term', 'user', 'comment' ), true ) ) {
			return false;
		}
		if ( ( $ctx['adapter'] ?? '' ) === 'acf' ) {
			return true;
		}
		$ref = $ctx['field_key'] ?? ( $ctx['ref'] ?? null );
		return is_string( $ref ) && 1 === preg_match( '/^field_[a-z0-9]{6,40}$/', $ref );
	}

	public function state_keys( string $key, array $ctx ): array {
		return array( $key, '_' . $key );
	}

	public function plan( string $type, int $id, string $key, mixed $value, array $ctx ): array {
		$fk = $ctx['field_key'] ?? ( $ctx['ref'] ?? null );
		if ( ! is_string( $fk ) || 1 !== preg_match( '/^field_[a-z0-9]{6,40}$/', $fk ) ) {
			return self::fail( 'ovp_acf_field_key', 'ACF needs field_key (field_xxxxxxxx)' . ( 'option' === $type ? ' for an options-page field (option key options_<name>).' : ' or an existing _' . $key . ' reference.' ) );
		}
		if ( is_string( $value ) || is_int( $value ) || is_float( $value ) || is_bool( $value ) || is_array( $value ) || null === $value ) {
			return self::writes(
				array(
					array( 'key' => $key, 'value' => $value ),
					array( 'key' => '_' . $key, 'value' => $fk ),
				)
			);
		}
		return self::fail( 'ovp_adapter_invalid', 'Unsupported ACF value.' );
	}
}
