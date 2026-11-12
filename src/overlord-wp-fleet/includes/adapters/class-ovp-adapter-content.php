<?php
/**
 * Content adapters on the pseudo-key `@post_content`: Gutenberg/FSE block markup
 * (incl. wp_global_styles / theme.json user data) and WPBakery shortcodes.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-ovp-adapter-base.php';

final class OVP_Adapter_WPBakery extends OVP_Adapter {

	public function id(): string {
		return 'wpbakery';
	}

	public function handles( string $type, string $key, array $ctx ): bool {
		if ( 'post' !== $type ) {
			return false;
		}
		if ( in_array( $key, array( '_wpb_vc_js_status', '_wpb_shortcodes_custom_css', '_wpb_post_custom_css' ), true ) ) {
			return true;
		}
		return '@post_content' === $key && ( ( $ctx['adapter'] ?? '' ) === 'wpbakery' || ( isset( $ctx['value'] ) && is_string( $ctx['value'] ) && str_contains( $ctx['value'], '[vc_' ) ) );
	}

	public function rules(): array {
		return array(
			'_wpb_vc_js_status'          => array( 't' => 'enum', 'values' => array( 'true', 'false' ) ),
			'_wpb_shortcodes_custom_css' => array( 't' => 'any' ),
			'_wpb_post_custom_css'       => array( 't' => 'any' ),
		);
	}

	public function plan( string $type, int $id, string $key, mixed $value, array $ctx ): array {
		if ( '@post_content' !== $key ) {
			if ( in_array( $key, array( '_wpb_shortcodes_custom_css', '_wpb_post_custom_css' ), true ) ) {
				if ( ! is_string( $value ) || strlen( $value ) > 262144 || preg_match( '/<\/?style|<script|expression\s*\(|javascript:/i', $value ) ) {
					return self::fail( 'ovp_adapter_invalid', $key . ': CSS must be a plain string without tags or script URLs (max 256KB).' );
				}
				return self::writes( array( array( 'key' => $key, 'value' => $value ) ) );
			}
			return parent::plan( $type, $id, $key, $value, $ctx );
		}
		if ( ! is_string( $value ) || strlen( $value ) > 4194304 ) {
			return self::fail( 'ovp_adapter_invalid', 'post_content must be a string up to 4MB.' );
		}
		$err = self::check_shortcodes( $value );
		if ( null !== $err ) {
			return self::fail( 'ovp_adapter_invalid', $err );
		}
		return self::writes( array( array( 'key' => '@post_content', 'value' => $value ) ) );
	}

	/** Balanced check of vc_* shortcodes. Returns error text or null. */
	public static function check_shortcodes( string $c ): ?string {
		$re = '/\[(\/)?(vc_[a-z0-9_]+)((?:"[^"]*"|\'[^\']*\'|[^\]"\'])*?)(\/)?\]/i';
		if ( false === preg_match_all( $re, $c, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return 'Shortcode scan failed.';
		}
		$stack = array();
		foreach ( $m as $hit ) {
			$closing = '' !== $hit[1][0];
			$name    = strtolower( $hit[2][0] );
			$self    = isset( $hit[4] ) && '' !== $hit[4][0];
			if ( $closing ) {
				$top = array_pop( $stack );
				if ( $top !== $name ) {
					return 'Unbalanced shortcode: unexpected [/' . $name . '].';
				}
			} elseif ( ! $self ) {
				$stack[] = $name;
			}
		}
		return array() === $stack ? null : 'Unclosed shortcode: [' . end( $stack ) . '].';
	}
}

final class OVP_Adapter_Gutenberg extends OVP_Adapter {

	private const FSE_TYPES = array( 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_block' );

	public function id(): string {
		return 'gutenberg';
	}

	public function max_bytes(): int {
		return 4194304;
	}

	public function handles( string $type, string $key, array $ctx ): bool {
		return 'post' === $type && '@post_content' === $key;
	}

	public function plan( string $type, int $id, string $key, mixed $value, array $ctx ): array {
		if ( ! is_string( $value ) || strlen( $value ) > $this->max_bytes() ) {
			return self::fail( 'ovp_adapter_invalid', 'post_content must be a string up to 4MB.' );
		}
		$pt = (string) ( $ctx['post_type'] ?? '' );
		if ( 'wp_global_styles' === $pt ) {
			$err = self::check_theme_json( $value );
			return null === $err ? self::writes( array( array( 'key' => '@post_content', 'value' => $value ) ) ) : self::fail( 'ovp_adapter_invalid', $err );
		}
		$err = self::check_blocks( $value );
		if ( null !== $err ) {
			return self::fail( 'ovp_adapter_invalid', $err );
		}
		if ( in_array( $pt, self::FSE_TYPES, true ) && '' === trim( $value ) ) {
			return self::fail( 'ovp_adapter_invalid', 'FSE template content must not be empty.' );
		}
		return self::writes( array( array( 'key' => '@post_content', 'value' => $value ) ) );
	}

	/** Delimiter balance + attribute JSON + (when WP is loaded) parse round-trip. */
	public static function check_blocks( string $c ): ?string {
		$re = '/<!--\s+(\/)?wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)((?:\s+\{.*?\})?)\s+(\/)?-->/s';
		if ( false === preg_match_all( $re, $c, $m, PREG_SET_ORDER ) ) {
			return 'Block scan failed.';
		}
		$stack = array();
		foreach ( $m as $hit ) {
			$closing = '' !== $hit[1];
			$name    = $hit[2];
			$attrs   = trim( $hit[3] );
			$self    = isset( $hit[4] ) && '' !== $hit[4];
			if ( $closing ) {
				if ( '' !== $attrs || $self ) {
					return 'Closing block comment must not carry attributes.';
				}
				$top = array_pop( $stack );
				if ( $top !== $name ) {
					return 'Unbalanced block markup near wp:' . $name . '.';
				}
				continue;
			}
			if ( '' !== $attrs ) {
				json_decode( $attrs, true, 64 );
				if ( JSON_ERROR_NONE !== json_last_error() ) {
					return 'Invalid block attribute JSON in wp:' . $name . '.';
				}
			}
			if ( ! $self ) {
				$stack[] = $name;
			}
			if ( count( $stack ) > 64 ) {
				return 'Blocks nested too deeply.';
			}
		}
		if ( array() !== $stack ) {
			return 'Unclosed block: wp:' . end( $stack ) . '.';
		}
		if ( function_exists( 'parse_blocks' ) && function_exists( 'serialize_blocks' ) ) {
			$a = self::names( parse_blocks( $c ) );
			$b = self::names( parse_blocks( serialize_blocks( parse_blocks( $c ) ) ) );
			if ( $a !== $b ) {
				return 'Block markup does not round-trip through parse_blocks/serialize_blocks.';
			}
		}
		return null;
	}

	/** @param array<int, array<string, mixed>> $blocks */
	private static function names( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $b ) {
			$out[] = array( $b['blockName'] ?? null, self::names( (array) ( $b['innerBlocks'] ?? array() ) ) );
		}
		return $out;
	}

	/** wp_global_styles content: theme.json user data. */
	public static function check_theme_json( string $c ): ?string {
		$j = json_decode( $c, true, 64 );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $j ) || array_is_list( $j ) ) {
			return 'theme.json data must be a JSON object.';
		}
		if ( ! isset( $j['version'] ) || ! is_int( $j['version'] ) || $j['version'] < 1 || $j['version'] > 3 ) {
			return 'theme.json "version" must be 1, 2 or 3.';
		}
		$allowed = array( 'version', 'settings', 'styles', 'isGlobalStylesUserThemeJSON', 'title', 'description', '$schema' );
		foreach ( array_keys( $j ) as $k ) {
			if ( ! in_array( $k, $allowed, true ) ) {
				return 'Unknown top-level theme.json key: ' . $k . '.';
			}
		}
		foreach ( array( 'settings', 'styles' ) as $k ) {
			if ( isset( $j[ $k ] ) && ! is_array( $j[ $k ] ) ) {
				return 'theme.json "' . $k . '" must be an object.';
			}
		}
		if ( preg_match( '/<script|javascript:/i', $c ) ) {
			return 'theme.json must not contain script content.';
		}
		return null;
	}
}
