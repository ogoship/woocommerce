<?php
/**
 * Read and write the OGOship product meta.
 *
 * @package OGOship\WooCommerce
 */

declare( strict_types=1 );

namespace OGOship\WooCommerce\Product;

defined( 'ABSPATH' ) || exit;

/**
 * The single source of truth for the OGOship product meta keys.
 *
 * ---------------------------------------------------------------------------
 * DO NOT RENAME THESE KEYS.
 * ---------------------------------------------------------------------------
 * They are a wire contract with the OGOship server, which reads them straight
 * out of the WooCommerce REST API's `meta_data`:
 *
 *   ECom/WooCommerceConnector/WooCommerceProductInfoProvider.cs   (constants)
 *   RapidWarehouse/WooCommerce.Models/Const/WooSettings.cs        (constants)
 *
 * The `_nettivarasto_` prefix is a legacy of the pre-2018 product name. It is
 * kept verbatim so a store can swap from the old plugin to this one with no
 * data migration and no server-side change.
 */
final class Repository {

	public const SUPPLIER_NAME       = '_nettivarasto_supplier_name';
	public const SUPPLIER_CODE       = '_nettivarasto_supplier_code';
	public const GROUP               = '_nettivarasto_group';
	public const PURCHASE_PRICE      = '_nettivarasto_purchase_price';
	public const EAN_CODE            = '_nettivarasto_eancode';
	public const CUSTOMS_DESCRIPTION = '_nettivarasto_customsdescription';
	public const COUNTRY_OF_ORIGIN   = '_nettivarasto_countryoforigin';
	public const HS_CODE             = '_nettivarasto_hscode';
	public const NO_EXPORT           = '_nettivarasto_no_export';

	/**
	 * Field definitions, in display order.
	 *
	 * `sanitize` names the strategy applied on save; `variation` marks the
	 * fields that also appear on individual variations.
	 *
	 * @return array<string, array{label: string, description: string, sanitize: string, variation: bool}>
	 */
	public static function fields(): array {
		return array(
			self::EAN_CODE            => array(
				'label'       => __( 'EAN code', 'ogoship-for-woocommerce' ),
				'description' => __( 'Barcode used by the warehouse to identify this item when picking.', 'ogoship-for-woocommerce' ),
				'sanitize'    => 'text',
				'variation'   => true,
			),
			self::SUPPLIER_NAME       => array(
				'label'       => __( 'Supplier name', 'ogoship-for-woocommerce' ),
				'description' => __( 'Who you buy this product from. Shown on purchase orders.', 'ogoship-for-woocommerce' ),
				'sanitize'    => 'text',
				'variation'   => true,
			),
			self::SUPPLIER_CODE       => array(
				'label'       => __( 'Supplier code', 'ogoship-for-woocommerce' ),
				'description' => __( "The supplier's own article number for this product.", 'ogoship-for-woocommerce' ),
				'sanitize'    => 'text',
				'variation'   => true,
			),
			self::PURCHASE_PRICE      => array(
				'label'       => __( 'Purchase price', 'ogoship-for-woocommerce' ),
				'description' => __( 'What the product costs you. Used for stock valuation, never shown to customers.', 'ogoship-for-woocommerce' ),
				'sanitize'    => 'price',
				'variation'   => true,
			),
			self::GROUP               => array(
				'label'       => __( 'Product group', 'ogoship-for-woocommerce' ),
				'description' => __( 'Free-text grouping used for warehouse reporting.', 'ogoship-for-woocommerce' ),
				'sanitize'    => 'text',
				'variation'   => false,
			),
			self::CUSTOMS_DESCRIPTION => array(
				'label'       => __( 'Customs description', 'ogoship-for-woocommerce' ),
				'description' => __( 'Plain description of the goods for customs declarations on non-EU shipments.', 'ogoship-for-woocommerce' ),
				'sanitize'    => 'text',
				'variation'   => true,
			),
			self::COUNTRY_OF_ORIGIN   => array(
				'label'       => __( 'Country of origin', 'ogoship-for-woocommerce' ),
				'description' => __( 'Two-letter country code where the product was manufactured, for example FI or CN.', 'ogoship-for-woocommerce' ),
				'sanitize'    => 'country',
				'variation'   => true,
			),
			self::HS_CODE             => array(
				'label'       => __( 'HS code', 'ogoship-for-woocommerce' ),
				'description' => __( 'Harmonised System tariff code used in customs declarations.', 'ogoship-for-woocommerce' ),
				'sanitize'    => 'text',
				'variation'   => true,
			),
			self::NO_EXPORT           => array(
				'label'       => __( 'Do not send to OGOship', 'ogoship-for-woocommerce' ),
				'description' => __( 'Exclude this product from OGOship. Use for digital goods and services that are never physically shipped.', 'ogoship-for-woocommerce' ),
				'sanitize'    => 'yes_no',
				'variation'   => false,
			),
		);
	}

	/**
	 * Keys that also appear on variations.
	 *
	 * @return list<string>
	 */
	public static function variation_keys(): array {
		return array_keys( array_filter( self::fields(), static fn( array $f ): bool => $f['variation'] ) );
	}

	/**
	 * Read one field from a product.
	 *
	 * For a variation, an empty own value falls back to the parent -- so a
	 * merchant only overrides the variations that genuinely differ. This
	 * mirrors how WooCommerce treats variation weight and dimensions.
	 *
	 * @param \WC_Product|int $product Product or product ID.
	 * @param string          $key     One of the class constants.
	 */
	public static function get( $product, string $key ): string {
		$product = is_numeric( $product ) ? wc_get_product( $product ) : $product;

		if ( ! $product instanceof \WC_Product ) {
			return '';
		}

		$value = (string) $product->get_meta( $key, true );

		if ( '' === $value && $product instanceof \WC_Product_Variation ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent instanceof \WC_Product ) {
				$value = (string) $parent->get_meta( $key, true );
			}
		}

		return $value;
	}

	/**
	 * Read the field stored directly on this product, with no parent fallback.
	 *
	 * Used when rendering variation inputs: the field must show what the
	 * variation itself holds, otherwise saving the form would silently promote
	 * an inherited value into an explicit override.
	 *
	 * @param \WC_Product|int $product Product or product ID.
	 * @param string          $key     One of the class constants.
	 */
	public static function get_own( $product, string $key ): string {
		$product = is_numeric( $product ) ? wc_get_product( $product ) : $product;

		return $product instanceof \WC_Product ? (string) $product->get_meta( $key, true ) : '';
	}

	/**
	 * Write one field to a product, sanitizing according to its definition.
	 *
	 * Does not save the product -- the caller batches writes and saves once.
	 *
	 * @param \WC_Product $product   Product to write to.
	 * @param string      $key       One of the class constants.
	 * @param string      $raw_value Unsanitized submitted value.
	 */
	public static function set( \WC_Product $product, string $key, string $raw_value ): void {
		$fields = self::fields();
		if ( ! isset( $fields[ $key ] ) ) {
			return;
		}

		$value = self::sanitize( $fields[ $key ]['sanitize'], $raw_value );

		// An emptied field is deleted rather than stored as '', so that
		// variations fall back to the parent again and the server sees the
		// key as genuinely absent.
		if ( '' === $value ) {
			$product->delete_meta_data( $key );
			return;
		}

		$product->update_meta_data( $key, $value );
	}

	/**
	 * Apply a named sanitization strategy.
	 *
	 * @param string $strategy One of text|price|country|yes_no.
	 * @param string $raw      Unsanitized value.
	 */
	public static function sanitize( string $strategy, string $raw ): string {
		switch ( $strategy ) {
			case 'price':
				// Accepts both "18,50" and "18.50"; wc_format_decimal returns
				// '' for anything that is not a number.
				$decimal = wc_format_decimal( $raw );
				return '' === $decimal || null === $decimal ? '' : (string) $decimal;

			case 'country':
				$code = strtoupper( trim( wc_clean( $raw ) ) );
				return preg_match( '/^[A-Z]{2}$/', $code ) ? $code : '';

			case 'yes_no':
				// WooCommerce's checkbox convention. 'yes' means excluded.
				return in_array( strtolower( trim( $raw ) ), array( 'yes', '1', 'on', 'true' ), true ) ? 'yes' : '';

			case 'text':
			default:
				return (string) wc_clean( $raw );
		}
	}

	/**
	 * Whether the product is flagged as never shipped by OGOship.
	 *
	 * Only the literal 'yes' excludes. Historical databases are full of rows
	 * holding 'no', written unconditionally by the pre-4.0 plugin on every
	 * product save, so anything that is not 'yes' has to mean "included".
	 *
	 * @param \WC_Product|int $product Product or product ID.
	 */
	public static function is_excluded( $product ): bool {
		return 'yes' === self::get( $product, self::NO_EXPORT );
	}
}
