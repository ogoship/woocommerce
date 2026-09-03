<?php
/**
 * Seed the dev store with orders to hang tracking data off.
 *
 * Run via `wp eval-file /seed/orders.php`. Idempotent -- keyed on a marker meta
 * so re-running setup does not pile up duplicates.
 *
 * @package OGOship\WooCommerce\Dev
 */

defined( 'ABSPATH' ) || exit;

const OGOSHIP_DEV_ORDER_MARKER = '_ogoship_dev_seed';

/**
 * Create one seeded order unless it already exists.
 *
 * @param string $marker Stable identifier for this seeded order.
 * @param array  $skus   SKUs to add, each with quantity 1.
 * @param string $status Order status to leave the order in.
 * @return WC_Order|null
 */
function ogoship_dev_order( string $marker, array $skus, string $status ): ?WC_Order {
	$found = wc_get_orders(
		array(
			'limit'      => 1,
			'meta_key'   => OGOSHIP_DEV_ORDER_MARKER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value' => $marker,                  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'return'     => 'objects',
		)
	);
	if ( ! empty( $found ) ) {
		WP_CLI::log( "Order '{$marker}' already seeded -> #{$found[0]->get_id()}" );
		return $found[0];
	}

	$order = wc_create_order();

	foreach ( $skus as $sku ) {
		$product_id = wc_get_product_id_by_sku( $sku );
		if ( ! $product_id ) {
			WP_CLI::warning( "SKU {$sku} not found -- run products.php first." );
			continue;
		}
		$order->add_product( wc_get_product( $product_id ), 1 );
	}

	$address = array(
		'first_name' => 'Testi',
		'last_name'  => 'Asiakas',
		'company'    => 'Testifirma Oy',
		'address_1'  => 'Testikatu 5 A 3',
		'city'       => 'Helsinki',
		'postcode'   => '00100',
		'country'    => 'FI',
	);
	$order->set_address( $address + array( 'email' => 'asiakas@example.test', 'phone' => '+358401234567' ), 'billing' );
	$order->set_address( $address, 'shipping' );

	$order->update_meta_data( OGOSHIP_DEV_ORDER_MARKER, $marker );
	$order->calculate_totals();
	$order->set_status( $status );
	$order->save();

	WP_CLI::log( "Order '{$marker}' -> #{$order->get_id()} ({$status})" );
	return $order;
}

// Awaiting fulfillment -- what OGOship would pick up for shipping.
ogoship_dev_order( 'processing', array( 'TEST-001', 'TEST-VAR-M' ), 'processing' );

// Already shipped -- the order bin/seed-tracking.sh is meant to be pointed at.
ogoship_dev_order( 'completed', array( 'TEST-002' ), 'completed' );

// Carries the legacy tracking key only, to prove the old-plugin fallback still
// renders for historical orders.
$legacy = ogoship_dev_order( 'legacy-tracking', array( 'TEST-001' ), 'completed' );
if ( $legacy && ! $legacy->get_meta( 'nettivarasto_tracking' ) ) {
	$legacy->update_meta_data( 'nettivarasto_tracking', 'JJFI00000000000LEGACY' );
	$legacy->save();
	WP_CLI::log( '  ...tagged with the legacy nettivarasto_tracking meta' );
}
