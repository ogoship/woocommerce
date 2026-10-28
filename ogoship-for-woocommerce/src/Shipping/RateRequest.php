<?php
/**
 * The rate request the shipping method sends to OGOship.
 *
 * @package OGOship\WooCommerce
 */

declare( strict_types=1 );

namespace OGOship\WooCommerce\Shipping;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the body of `POST /api/ecom/WooCommerceCheckoutRates/rates` from a WooCommerce shipping
 * package.
 *
 * The field names and meaning are OGOship's contract. Weight is sent in grams whatever the store's
 * unit, and the value is the package's contents cost in the store currency.
 */
final class RateRequest {

	/**
	 * The request fields, without `timestamp` (the client adds it when signing, so the cache key
	 * does not change every second).
	 *
	 * @param array<string, mixed> $package     The shipping package WooCommerce passes to `calculate_shipping()`.
	 * @param int                  $zone_id     The shipping zone WooCommerce matched the package to.
	 * @param int                  $instance_id The OGOship method's instance in that zone.
	 * @param string               $store       The store's home URL.
	 * @param string               $key_ending  The OGOship key's `truncated_key`.
	 * @param string               $currency    The store currency.
	 * @return array<string, mixed>
	 */
	public static function build( array $package, int $zone_id, int $instance_id, string $store, string $key_ending, string $currency ): array {
		$destination = isset( $package['destination'] ) && is_array( $package['destination'] ) ? $package['destination'] : array();

		return array(
			'store'                 => $store,
			'key_ending'            => $key_ending,
			'zone_id'               => $zone_id,
			'instance_id'           => $instance_id,
			'destination'           => array(
				'country'  => strtoupper( self::text( $destination, 'country' ) ),
				'postcode' => self::text( $destination, 'postcode' ),
				'city'     => self::text( $destination, 'city' ),
				'address'  => self::text( $destination, 'address' ),
			),
			'contents_weight_grams' => self::weight_grams( $package ),
			'contents_value'        => round( (float) ( $package['contents_cost'] ?? 0 ), 2 ),
			'currency'              => $currency,
		);
	}

	/**
	 * Weight of the items that need shipping, in grams.
	 *
	 * @param array<string, mixed> $package The shipping package.
	 */
	public static function weight_grams( array $package ): int {
		$contents = isset( $package['contents'] ) && is_array( $package['contents'] ) ? $package['contents'] : array();
		$total    = 0.0;

		foreach ( $contents as $item ) {
			$product  = is_array( $item ) ? ( $item['data'] ?? null ) : null;
			$quantity = is_array( $item ) ? (int) ( $item['quantity'] ?? 0 ) : 0;

			if ( ! $product instanceof \WC_Product || $quantity <= 0 || ! $product->needs_shipping() ) {
				continue;
			}

			$weight = (float) $product->get_weight();
			if ( $weight > 0 ) {
				// wc_get_weight converts from the store's weight unit to the one asked for.
				$total += (float) wc_get_weight( $weight, 'g' ) * $quantity;
			}
		}

		return (int) round( $total );
	}

	/**
	 * A trimmed string field of the destination.
	 *
	 * @param array<string, mixed> $destination The package destination.
	 * @param string               $key         Field name.
	 */
	private static function text( array $destination, string $key ): string {
		return isset( $destination[ $key ] ) && is_scalar( $destination[ $key ] ) ? trim( (string) $destination[ $key ] ) : '';
	}
}
