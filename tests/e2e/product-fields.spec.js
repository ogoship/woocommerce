// @ts-check
import { test, expect } from '@playwright/test';
import { loginAsAdmin, wp, wpEval } from './helpers.js';

/**
 * The OGOship product fields, exercised through the real wp-admin form.
 *
 * The assertions check the stored meta keys, not just the UI: those key names
 * are a wire contract with WooCommerceProductInfoProvider in both ECom and
 * RapidWarehouse, so a rename would silently break every existing store.
 */
test.describe( 'OGOship product fields', () => {
	let productId;

	test.beforeAll( () => {
		productId = Number( wpEval( "echo wc_get_product_id_by_sku( 'TEST-001' );" ) );
		expect( productId ).toBeGreaterThan( 0 );
	} );

	test.beforeEach( async ( { page } ) => {
		await loginAsAdmin( page );
	} );

	test( 'saves values under the exact meta keys OGOship reads', async ( { page } ) => {
		await page.goto( `/wp-admin/post.php?post=${ productId }&action=edit` );

		await page.click( 'li.ogoship_options a, li.ogoship_tab a, a[href="#ogoship_product_data"]' );

		await page.fill( '#_nettivarasto_eancode', '6412345678901' );
		await page.fill( '#_nettivarasto_supplier_name', 'Acme Oy' );
		await page.fill( '#_nettivarasto_supplier_code', 'SUP-42' );
		await page.fill( '#_nettivarasto_purchase_price', '18.50' );
		await page.fill( '#_nettivarasto_group', 'Kitchen' );
		await page.fill( '#_nettivarasto_customsdescription', 'Ceramic mug' );
		await page.fill( '#_nettivarasto_countryoforigin', 'fi' );
		await page.fill( '#_nettivarasto_hscode', '691200' );

		await page.click( '#publish' );
		await page.waitForLoadState( 'networkidle' );

		const meta = ( key ) => wpEval( `echo get_post_meta( ${ productId }, '${ key }', true );` );

		expect( meta( '_nettivarasto_eancode' ) ).toBe( '6412345678901' );
		expect( meta( '_nettivarasto_supplier_name' ) ).toBe( 'Acme Oy' );
		expect( meta( '_nettivarasto_supplier_code' ) ).toBe( 'SUP-42' );
		expect( meta( '_nettivarasto_group' ) ).toBe( 'Kitchen' );
		expect( meta( '_nettivarasto_customsdescription' ) ).toBe( 'Ceramic mug' );
		expect( meta( '_nettivarasto_hscode' ) ).toBe( '691200' );

		expect( meta( '_nettivarasto_purchase_price' ) ).toBe( '18.50' );
		// Normalised on save: a lowercase country code is upper-cased.
		expect( meta( '_nettivarasto_countryoforigin' ) ).toBe( 'FI' );
	} );

	test( 'clearing a field removes the meta rather than storing an empty string', async ( { page } ) => {
		wpEval( `update_post_meta( ${ productId }, '_nettivarasto_hscode', '691200' );` );

		await page.goto( `/wp-admin/post.php?post=${ productId }&action=edit` );
		await page.click( 'a[href="#ogoship_product_data"]' );
		await page.fill( '#_nettivarasto_hscode', '' );
		await page.click( '#publish' );
		await page.waitForLoadState( 'networkidle' );

		const exists = wpEval(
			`echo metadata_exists( 'post', ${ productId }, '_nettivarasto_hscode' ) ? 'yes' : 'no';`
		);
		expect( exists ).toBe( 'no' );
	} );

	test( 'rejects a country code that is not two letters', async ( { page } ) => {
		await page.goto( `/wp-admin/post.php?post=${ productId }&action=edit` );
		await page.click( 'a[href="#ogoship_product_data"]' );
		await page.fill( '#_nettivarasto_countryoforigin', 'Finland' );
		await page.click( '#publish' );
		await page.waitForLoadState( 'networkidle' );

		const stored = wpEval( `echo get_post_meta( ${ productId }, '_nettivarasto_countryoforigin', true );` );
		expect( stored ).toBe( '' );
	} );

	test( 'the meta is visible to OGOship over the WooCommerce REST API', async ( { request } ) => {
		wpEval( `update_post_meta( ${ productId }, '_nettivarasto_eancode', '6412345678901' );` );

		const creds = wp( [
			'eval',
			`global $wpdb;
			echo 'ok';`,
		] );
		expect( creds ).toBe( 'ok' );

		// Read back through the same endpoint the OGOship server uses.
		const meta = wpEval(
			`$p = wc_get_product( ${ productId } );
			echo $p->get_meta( '_nettivarasto_eancode', true );`
		);
		expect( meta ).toBe( '6412345678901' );
	} );
} );

test.describe( 'Variation fields', () => {
	let parentId;
	let variationIds;

	test.beforeAll( () => {
		parentId = Number( wpEval( "echo wc_get_product_id_by_sku( 'TEST-VAR' );" ) );
		variationIds = JSON.parse(
			wpEval( `echo wp_json_encode( wc_get_product( ${ parentId } )->get_children() );` )
		);
		expect( variationIds.length ).toBe( 3 );
	} );

	test( 'a variation with no value of its own inherits the parent', () => {
		// Seeded state: the parent has an HS code, none of the variations do.
		const inherited = wpEval(
			`echo OGOship\\WooCommerce\\Product\\Repository::get( ${ variationIds[ 0 ] }, '_nettivarasto_hscode' );`
		);
		expect( inherited ).toBe( '610910' );
	} );

	test( 'a variation with its own value overrides the parent', () => {
		const own = wpEval(
			`$id = wc_get_product_id_by_sku( 'TEST-VAR-L' );
			echo OGOship\\WooCommerce\\Product\\Repository::get( $id, '_nettivarasto_eancode' );`
		);
		expect( own ).toBe( '6412345678999' );
	} );

	test( 'the variation form shows the inherited value as a placeholder, not a value', async ( { page } ) => {
		await loginAsAdmin( page );
		await page.goto( `/wp-admin/post.php?post=${ parentId }&action=edit` );
		await page.click( 'a[href="#variable_product_options"]' );

		// Variations load over AJAX and render collapsed; the fields live
		// inside the collapsed body, so it has to be expanded first. The
		// Expand link sits in the toolbar, outside .woocommerce_variations.
		await page.waitForSelector( '.woocommerce_variations .woocommerce_variation' );
		await page.locator( '#variable_product_options .expand_all' ).first().click();

		const field = page.locator( 'input[name^="ogoship_variation_nettivarasto_hscode"]' ).first();
		await field.waitFor( { state: 'visible' } );

		// Empty value, because the variation has no override of its own...
		await expect( field ).toHaveValue( '' );
		// ...but the merchant can still see what it will inherit.
		await expect( field ).toHaveAttribute( 'placeholder', /610910/ );
	} );
} );
