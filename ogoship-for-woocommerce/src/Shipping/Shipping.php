<?php
/**
 * Registers the OGOship shipping method.
 *
 * @package OGOship\WooCommerce
 */

declare( strict_types=1 );

namespace OGOship\WooCommerce\Shipping;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the shipping method into WooCommerce and keeps OGOship's bookkeeping meta out of sight.
 */
final class Shipping {

	/**
	 * Shipping line meta that tells OGOship's order import the delivery type and pickup point
	 * ("ogo|{delivery type}|{pickup point}", or "ogo|{delivery type}" for home delivery).
	 */
	public const RATE_META = 'ogoship_rate';

	/**
	 * Our shipping line meta. The rate's name already says which pickup point it is, so none of it
	 * is shown again under the shipping line: it stays on the order for OGOship and for reference.
	 */
	private const HIDDEN_META = array( self::RATE_META, 'ogoship_pickup_point', 'ogoship_pickup_point_name' );

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'woocommerce_shipping_methods', array( $this, 'add_method' ) );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hide_admin_meta' ) );
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( $this, 'hide_formatted_meta' ), 10, 1 );
	}

	/**
	 * Offer the OGOship method in the shipping zones.
	 *
	 * @param array<string, string|object> $methods Method id => class name or instance.
	 * @return array<string, string|object>
	 */
	public function add_method( $methods ): array {
		$methods                       = is_array( $methods ) ? $methods : array();
		$methods[ ShippingMethod::ID ] = ShippingMethod::class;
		return $methods;
	}

	/**
	 * Hide the meta in the order edit screen's item list.
	 *
	 * @param array<int, string> $hidden Meta keys WooCommerce hides.
	 * @return array<int, string>
	 */
	public function hide_admin_meta( $hidden ): array {
		return array_merge( is_array( $hidden ) ? $hidden : array(), self::HIDDEN_META );
	}

	/**
	 * Hide the meta wherever an order item's meta is listed for people: emails, the thank-you page,
	 * My account.
	 *
	 * @param array<int|string, object> $formatted Formatted meta, each with a `key`.
	 * @return array<int|string, object>
	 */
	public function hide_formatted_meta( $formatted ): array {
		if ( ! is_array( $formatted ) ) {
			return array();
		}

		return array_filter(
			$formatted,
			static fn( $meta ): bool => ! ( is_object( $meta ) && isset( $meta->key ) && in_array( $meta->key, self::HIDDEN_META, true ) )
		);
	}
}
