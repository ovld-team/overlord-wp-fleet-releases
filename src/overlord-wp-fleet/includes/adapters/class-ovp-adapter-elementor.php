<?php
/**
 * Elementor adapter. `_elementor_data` is validated as a JSON element list and stored
 * byte-for-byte (a string payload is never re-encoded: re-encoding kills __dynamic__ tags).
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-ovp-adapter-base.php';

final class OVP_Adapter_Elementor extends OVP_Adapter {

	public function id(): string {
		return 'elementor';
	}

	public function max_bytes(): int {
		return 4194304;
	}

	public function handles( string $type, string $key, array $ctx ): bool {
		return 'post' === $type && str_starts_with( $key, '_elementor_' );
	}

	public function rules(): array {
		return array(
			'_elementor_edit_mode'     => array( 't' => 'enum', 'values' => array( 'builder', '' ) ),
			'_elementor_template_type' => array( 't' => 'string', 'max' => 64, 'line' => true ),
			'_elementor_version'       => array( 't' => 'string', 'max' => 32, 'line' => true ),
		);
	}

	public function plan( string $type, int $id, string $key, mixed $value, array $ctx ): array {
		if ( '_elementor_data' === $key ) {
			return $this->plan_data( $value );
		}
		if ( '_elementor_page_settings' === $key ) {
			if ( ! is_array( $value ) ) {
				return self::fail( 'ovp_adapter_invalid', '_elementor_page_settings must be an object.' );
			}
			return self::writes( array( array( 'key' => $key, 'value' => $value ) ) );
		}
		if ( '_elementor_template_type' === $key && is_string( $value ) && 1 !== preg_match( '/^[a-z0-9_-]{1,64}$/', $value ) ) {
			return self::fail( 'ovp_adapter_invalid', '_elementor_template_type: invalid slug.' );
		}
		if ( '_elementor_version' === $key && is_string( $value ) && 1 !== preg_match( '/^\d+(\.\d+){1,3}([-.][A-Za-z0-9.]+)?$/', $value ) ) {
			return self::fail( 'ovp_adapter_invalid', '_elementor_version: invalid version.' );
		}
		return parent::plan( $type, $id, $key, $value, $ctx );
	}

	private function plan_data( mixed $value ): array {
		if ( is_array( $value ) ) {
			if ( self::has_dynamic( $value ) ) {
				return self::fail( 'ovp_adapter_invalid', 'Tree contains __dynamic__. Send the raw _elementor_data JSON string instead; re-encoding drops dynamic tags.' );
			}
			$raw = json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( false === $raw ) {
				return self::fail( 'ovp_adapter_invalid', 'Cannot encode _elementor_data.' );
			}
		} elseif ( is_string( $value ) ) {
			$raw = trim( $value );
		} else {
			return self::fail( 'ovp_adapter_invalid', '_elementor_data must be a JSON string or element list.' );
		}
		if ( strlen( $raw ) > $this->max_bytes() ) {
			return self::fail( 'ovp_adapter_invalid', '_elementor_data cap is 4MB. Split the tree.' );
		}
		$tree = json_decode( $raw, true, 128 );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $tree ) || ! array_is_list( $tree ) ) {
			return self::fail( 'ovp_adapter_invalid', '_elementor_data must be a JSON array of elements.' );
		}
		foreach ( $tree as $el ) {
			if ( ! is_array( $el ) || ! isset( $el['elType'] ) || ! is_string( $el['elType'] ) || ! isset( $el['id'] ) || ! is_scalar( $el['id'] ) ) {
				return self::fail( 'ovp_adapter_invalid', 'Each top-level element needs id and elType.' );
			}
		}
		$bad = self::validate_tree( $tree );
		if ( null !== $bad ) {
			return self::fail( 'ovp_adapter_invalid', $bad );
		}
		return self::writes( array( array( 'key' => '_elementor_data', 'value' => $raw ) ) );
	}

	/**
	 * Structural check of a decoded _elementor_data tree (D13). Returns the first problem or null.
	 * elType must be section|column|container|widget, ids hex-ish, widgets carry a widgetType,
	 * `elements` is a list, `settings` is an object (or an empty list).
	 *
	 * @param array<mixed> $tree
	 */
	public static function validate_tree( array $tree, string $path = '$', int $depth = 0 ): ?string {
		if ( $depth > 40 ) {
			return $path . ': tree nested deeper than 40 levels.';
		}
		if ( ! array_is_list( $tree ) ) {
			return $path . ': expected a list of elements.';
		}
		foreach ( $tree as $i => $el ) {
			$at = $path . '[' . $i . ']';
			if ( ! is_array( $el ) ) {
				return $at . ': element must be an object.';
			}
			$type = $el['elType'] ?? null;
			if ( ! is_string( $type ) || ! in_array( $type, array( 'section', 'column', 'container', 'widget' ), true ) ) {
				return $at . ': elType must be one of section, column, container, widget.';
			}
			$id = $el['id'] ?? null;
			if ( ! is_string( $id ) && ! is_int( $id ) ) {
				return $at . ': id is required.';
			}
			if ( 1 !== preg_match( '/^[0-9a-fA-F]{1,16}$/', (string) $id ) ) {
				return $at . ': id "' . substr( (string) $id, 0, 40 ) . '" is not a hex id (Elementor ids are 7-8 hex characters).';
			}
			if ( 'widget' === $type && ( ! isset( $el['widgetType'] ) || ! is_string( $el['widgetType'] ) || 1 !== preg_match( '/^[A-Za-z0-9_.-]{1,80}$/', $el['widgetType'] ) ) ) {
				return $at . ': a widget needs a widgetType string.';
			}
			if ( array_key_exists( 'settings', $el ) && ! is_array( $el['settings'] ) ) {
				return $at . ': settings must be an object.';
			}
			if ( array_key_exists( 'elements', $el ) ) {
				if ( ! is_array( $el['elements'] ) || ! array_is_list( $el['elements'] ) ) {
					return $at . ': elements must be an array.';
				}
				if ( 'widget' === $type && array() !== $el['elements'] ) {
					return $at . ': a widget cannot have child elements.';
				}
				$bad = self::validate_tree( $el['elements'], $at . '.elements', $depth + 1 );
				if ( null !== $bad ) {
					return $bad;
				}
			}
		}
		return null;
	}

	/** @param array<mixed> $node */
	private static function has_dynamic( array $node ): bool {
		if ( array_key_exists( '__dynamic__', $node ) ) {
			return true;
		}
		foreach ( $node as $child ) {
			if ( is_array( $child ) && self::has_dynamic( $child ) ) {
				return true;
			}
		}
		return false;
	}

	public function after_write( string $type, int $id, string $key, OVP_Meta_Backend $backend ): void {
		try {
			$backend->delete( 'post', $id, '_elementor_css' );
			if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
				\Elementor\Core\Files\CSS\Post::create( $id )->delete();
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			unset( $e );
		}
	}
}
