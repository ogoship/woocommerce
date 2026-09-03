<?php
/**
 * Put the dev store into a presentable state for wordpress.org screenshots.
 *
 * Run via `wp eval-file /seed/screenshot-state.php`. Fills the OGOship fields
 * with plausible values, gives one variation its own EAN so the inheritance
 * placeholder is visible, and puts tracking on an order.
 *
 * @package OGOship\WooCommerce\Dev
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Simple product: every OGOship field populated.
// ---------------------------------------------------------------------------
$product_id = wc_get_product_id_by_sku( 'TEST-002' );
$product    = wc_get_product( $product_id );
$product->set_name( 'Ceramic Pour-Over Kettle' );
$product->set_regular_price( '49.00' );
$product->save();

$values = array(
	'_nettivarasto_eancode'            => '6412345678901',
	'_nettivarasto_supplier_name'      => 'Nordic Homeware Oy',
	'_nettivarasto_supplier_code'      => 'NH-4471',
	'_nettivarasto_purchase_price'     => '21.40',
	'_nettivarasto_group'              => 'Kitchen',
	'_nettivarasto_customsdescription' => 'Ceramic kettle for household use',
	'_nettivarasto_countryoforigin'    => 'PT',
	'_nettivarasto_hscode'             => '691200',
);
foreach ( $values as $key => $value ) {
	update_post_meta( $product_id, $key, $value );
}
delete_post_meta( $product_id, '_nettivarasto_no_export' );
WP_CLI::log( "Product #{$product_id} populated." );

// ---------------------------------------------------------------------------
// Variable product: parent values set, one variation overriding its EAN so the
// "Inherited: ..." placeholder is visible on the others.
// ---------------------------------------------------------------------------
$parent_id = wc_get_product_id_by_sku( 'TEST-VAR' );
$parent    = wc_get_product( $parent_id );
$parent->set_name( 'Merino Crew Sweater' );
$parent->save();

// NOTE: the HS code and the L variation's EAN below are asserted by
// tests/e2e/product-fields.spec.js, so they must match what products.php seeds.
// Only cosmetic fields (names, supplier details) are safe to change here.
update_post_meta( $parent_id, '_nettivarasto_supplier_name', 'Textile Mills Ltd' );
update_post_meta( $parent_id, '_nettivarasto_supplier_code', 'TM-8820' );
update_post_meta( $parent_id, '_nettivarasto_countryoforigin', 'PT' );
update_post_meta( $parent_id, '_nettivarasto_hscode', '610910' );
update_post_meta( $parent_id, '_nettivarasto_eancode', '6412345670000' );
update_post_meta( $parent_id, '_nettivarasto_group', 'Knitwear' );

foreach ( $parent->get_children() as $variation_id ) {
	$variation = wc_get_product( $variation_id );
	// Only the L variation carries its own barcode; S and M inherit.
	if ( str_ends_with( (string) $variation->get_sku(), '-L' ) ) {
		update_post_meta( $variation_id, '_nettivarasto_eancode', '6412345678999' );
	} else {
		delete_post_meta( $variation_id, '_nettivarasto_eancode' );
	}
}
WP_CLI::log( "Variable product #{$parent_id} populated." );

// ---------------------------------------------------------------------------
// A shipped order with tracking, owned by the admin user so the My Account
// order page can be photographed while logged in.
//
// Deliberately its OWN order rather than one of the test fixtures. Attaching a
// customer to an order makes WooCommerce hide it from anonymous visitors, which
// would break the guest order-received tests in tracking.spec.js.
// ---------------------------------------------------------------------------
$marker = 'screenshot';

$existing = wc_get_orders(
	array(
		'limit'      => 1,
		'meta_key'   => '_ogoship_dev_seed', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'meta_value' => $marker,             // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		'return'     => 'objects',
	)
);

$order = $existing ? $existing[0] : wc_create_order();

if ( ! $existing ) {
	$order->add_product( wc_get_product( $product_id ), 1 );
	$address = array(
		'first_name' => 'Anna',
		'last_name'  => 'Virtanen',
		'address_1'  => 'Mannerheimintie 12 B',
		'city'       => 'Helsinki',
		'postcode'   => '00100',
		'country'    => 'FI',
	);
	$order->set_address( $address + array( 'email' => 'anna@example.com' ), 'billing' );
	$order->set_address( $address, 'shipping' );
	$order->update_meta_data( '_ogoship_dev_seed', $marker );
	$order->calculate_totals();
}

$order->set_customer_id( 1 );
$order->update_meta_data( 'ogoship_tracking', 'JJFI64123456789012' );
$order->update_meta_data( 'ogoship_tracking_url', 'https://tracking.example.com/JJFI64123456789012' );
$order->update_meta_data( '_ogoship_tracking_status', 'Out for delivery' );
$order->set_status( 'completed' );
$order->save();

WP_CLI::log( "Screenshot order #{$order->get_id()} ready." );
