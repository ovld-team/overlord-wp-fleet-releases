<?php
/**
 * WooCommerce adapter. Writes go through the product CRUD class when WooCommerce is
 * loaded (price lookups, transients and stock sync stay consistent); otherwise the raw
 * `_price`-family meta keys are written and kept in sync.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-ovp-adapter-base.php';

final class OVP_Adapter_WooCommerce extends OVP_Adapter {

	/** meta key => CRUD setter */
	private const SETTERS = array(
		'_regular_price'  => 'set_regular_price',
		'_sale_price'     => 'set_sale_price',
		'_sku'            => 'set_sku',
		'_stock'          => 'set_stock_quantity',
		'_manage_stock'   => 'set_manage_stock',
		'_stock_status'   => 'set_stock_status',
		'_weight'         => 'set_weight',
		'_virtual'        => 'set_virtual',
		'_downloadable'   => 'set_downloadable',
		'_tax_status'     => 'set_tax_status',
		'_featured'       => 'set_featured',
	);

	public function id(): string {
		return 'woocommerce';
	}

	public function handles( string $type, string $key, array $ctx ): bool {
		if ( 'post' !== $type ) {
			return false;
		}
		if ( isset( self::SETTERS[ $key ] ) ) {
			return in_array( (string) ( $ctx['post_type'] ?? 'product' ), array( 'product', 'product_variation' ), true );
		}
		return ( $ctx['adapter'] ?? '' ) === 'woocommerce' && isset( $this->keymap()[ $key ] );
	}

	public function keymap(): array {
		return array(
			'regular_price' => '_regular_price',
			'sale_price'    => '_sale_price',
			'sku'           => '_sku',
			'stock'         => '_stock',
			'manage_stock'  => '_manage_stock',
			'stock_status'  => '_stock_status',
			'weight'        => '_weight',
			'virtual'       => '_virtual',
			'downloadable'  => '_downloadable',
			'tax_status'    => '_tax_status',
			'featured'      => '_featured',
		);
	}

	public function rules(): array {
		$yn = array( 't' => 'enum', 'values' => array( 'yes', 'no' ) );
		return array(
			'_regular_price' => array( 't' => 'numeric', 'min' => 0 ),
			'_sale_price'    => array( 't' => 'numeric', 'min' => 0 ),
			'_sku'           => array( 't' => 'string', 'max' => 100, 'line' => true ),
			'_stock'         => array( 't' => 'numeric', 'min' => 0 ),
			'_manage_stock'  => $yn,
			'_stock_status'  => array( 't' => 'enum', 'values' => array( 'instock', 'outofstock', 'onbackorder' ) ),
			'_weight'        => array( 't' => 'numeric', 'min' => 0 ),
			'_virtual'       => $yn,
			'_downloadable'  => $yn,
			'_tax_status'    => array( 't' => 'enum', 'values' => array( 'taxable', 'shipping', 'none' ) ),
			'_featured'      => $yn,
		);
	}

	public function state_keys( string $key, array $ctx ): array {
		$k = $this->resolve_key( $key );
		return in_array( $k, array( '_regular_price', '_sale_price' ), true ) ? array( '_regular_price', '_sale_price', '_price' ) : array( $k );
	}

	public function plan( string $type, int $id, string $key, mixed $value, array $ctx ): array {
		$res = parent::plan( $type, $id, $key, $value, $ctx );
		if ( isset( $res['error'] ) ) {
			return $res;
		}
		$actual = $this->resolve_key( $key );
		$v      = $res['writes'][0]['value'];
		if ( '_sale_price' === $actual && '' !== (string) $v && isset( $ctx['regular_price'] ) && (float) $v > (float) $ctx['regular_price'] ) {
			return self::fail( 'ovp_adapter_invalid', '_sale_price must not exceed the regular price.' );
		}
		if ( in_array( $actual, array( '_regular_price', '_sale_price' ), true ) ) {
			// Keep the effective _price in step when no sale is active.
			$sale    = '_sale_price' === $actual ? (string) $v : (string) ( $ctx['sale_price'] ?? '' );
			$regular = '_regular_price' === $actual ? (string) $v : (string) ( $ctx['regular_price'] ?? '' );
			if ( '_sale_price' === $actual && '' !== $sale && '' !== $regular && (float) $sale > (float) $regular ) {
				return self::fail( 'ovp_adapter_invalid', '_sale_price must not exceed the regular price.' );
			}
			$res['writes'][] = array( 'key' => '_price', 'value' => '' !== $sale ? $sale : $regular );
		}
		return $res;
	}

	public function custom_write( string $type, int $id, string $key, mixed $value ): ?bool {
		if ( ! function_exists( 'wc_get_product' ) || ! isset( self::SETTERS[ $key ] ) ) {
			return null;
		}
		$p = wc_get_product( $id );
		if ( ! is_object( $p ) ) {
			return null;
		}
		$setter = self::SETTERS[ $key ];
		if ( ! method_exists( $p, $setter ) ) {
			return null;
		}
		$v = $value;
		if ( in_array( $key, array( '_manage_stock', '_virtual', '_downloadable', '_featured' ), true ) ) {
			$v = 'yes' === $value;
		}
		$p->$setter( $v );
		$p->save();
		return true;
	}

	public function after_write( string $type, int $id, string $key, OVP_Meta_Backend $backend ): void {
		try {
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $id );
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			unset( $e );
		}
	}
}
