<?php
/**
 * Seed the dev store with products that exercise every code path in the plugin.
 *
 * Run via `wp eval-file /seed/products.php`. Idempotent -- products are matched
 * by SKU and reused.
 *
 * @package OGOship\WooCommerce\Dev
 */

defined( 'ABSPATH' ) || exit;

/**
 * Find an existing product by SKU or create it.
 *
 * @param string $sku  Product SKU.
 * @param string $type 'simple' or 'variable'.
 * @return WC_Product
 */
function ogoship_dev_product( string $sku, string $type ): WC_Product {
	$existing = wc_get_product_id_by_sku( $sku );
	if ( $existing ) {
		return wc_get_product( $existing );
	}
	return 'variable' === $type ? new WC_Product_Variable() : new WC_Product_Simple();
}

// ---------------------------------------------------------------------------
// 1. Simple product, no OGOship meta -- the "merchant has not filled anything
//    in yet" baseline.
// ---------------------------------------------------------------------------
$simple = ogoship_dev_product( 'TEST-001', 'simple' );
$simple->set_name( 'Test Mug' );
$simple->set_sku( 'TEST-001' );
$simple->set_regular_price( '19.90' );
$simple->set_manage_stock( true );
$simple->set_stock_quantity( 25 );
$simple->set_weight( '0.35' );
$simple->set_status( 'publish' );
$simple->save();
WP_CLI::log( "Simple product TEST-001 -> #{$simple->get_id()}" );

// ---------------------------------------------------------------------------
// 2. Simple product WITH OGOship meta already populated -- proves the plugin
//    reads pre-existing values written by the old plugin, unchanged.
// ---------------------------------------------------------------------------
$filled = ogoship_dev_product( 'TEST-002', 'simple' );
$filled->set_name( 'Test Kettle' );
$filled->set_sku( 'TEST-002' );
$filled->set_regular_price( '49.00' );
$filled->set_manage_stock( true );
$filled->set_stock_quantity( 10 );
$filled->set_status( 'publish' );
$filled->save();

// Written with the exact key names the OGOship server reads. Do not "tidy" these.
update_post_meta( $filled->get_id(), '_nettivarasto_supplier_name', 'Acme Oy' );
update_post_meta( $filled->get_id(), '_nettivarasto_supplier_code', 'SUP-1' );
update_post_meta( $filled->get_id(), '_nettivarasto_group', 'Kitchen' );
update_post_meta( $filled->get_id(), '_nettivarasto_purchase_price', '18.50' );
update_post_meta( $filled->get_id(), '_nettivarasto_eancode', '6412345678901' );
update_post_meta( $filled->get_id(), '_nettivarasto_customsdescription', 'Electric kettle' );
update_post_meta( $filled->get_id(), '_nettivarasto_countryoforigin', 'FI' );
update_post_meta( $filled->get_id(), '_nettivarasto_hscode', '851660' );
update_post_meta( $filled->get_id(), '_nettivarasto_no_export', 'no' );
WP_CLI::log( "Simple product TEST-002 (with OGOship meta) -> #{$filled->get_id()}" );

// ---------------------------------------------------------------------------
// 3. Variable product with three size variations -- the case the new
//    per-variation fields exist for.
// ---------------------------------------------------------------------------
$variable = ogoship_dev_product( 'TEST-VAR', 'variable' );
$variable->set_name( 'Test T-Shirt' );
$variable->set_sku( 'TEST-VAR' );
$variable->set_status( 'publish' );

$attribute = new WC_Product_Attribute();
$attribute->set_name( 'Size' );
$attribute->set_options( array( 'S', 'M', 'L' ) );
$attribute->set_visible( true );
$attribute->set_variation( true );
$variable->set_attributes( array( $attribute ) );
$variable->save();

// Parent-level OGOship meta; variations inherit anything they leave blank.
update_post_meta( $variable->get_id(), '_nettivarasto_supplier_name', 'Textile Ltd' );
update_post_meta( $variable->get_id(), '_nettivarasto_countryoforigin', 'PT' );
update_post_meta( $variable->get_id(), '_nettivarasto_hscode', '610910' );

foreach ( array( 'S', 'M', 'L' ) as $index => $size ) {
	$sku = 'TEST-VAR-' . $size;
	if ( wc_get_product_id_by_sku( $sku ) ) {
		continue;
	}
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $variable->get_id() );
	$variation->set_attributes( array( 'size' => $size ) );
	$variation->set_sku( $sku );
	$variation->set_regular_price( '29.90' );
	$variation->set_manage_stock( true );
	$variation->set_stock_quantity( 5 + $index );
	$variation->save();

	// Only the L variation gets its own EAN, so the inheritance path stays
	// covered by S and M.
	if ( 'L' === $size ) {
		update_post_meta( $variation->get_id(), '_nettivarasto_eancode', '6412345678999' );
	}
	WP_CLI::log( "  Variation {$sku} -> #{$variation->get_id()}" );
}

WC_Product_Variable::sync( $variable->get_id() );
WP_CLI::log( "Variable product TEST-VAR -> #{$variable->get_id()}" );
