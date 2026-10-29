// @ts-check
import { test, expect } from '@playwright/test';
import { orderReceivedPath, sendCompletedOrderEmail, wpEval } from './helpers.js';

const ADDRESS = {
	first_name: 'Ada',
	last_name: 'Lovelace',
	address_1: 'Mannerheimintie 1',
	city: 'Helsinki',
	postcode: '00100',
	country: 'FI',
};

/**
 * The OGOship shipping method at checkout, against the dev store's stand-in
 * for OGOship's rates endpoint (.docker/mu-plugins/03-ogoship-rates-stub.php).
 * The stub verifies the request signature with the store's OGOship key, as
 * OGOship does, so a rate that shows up here was asked for correctly.
 *
 * Driven through the Store API, which is what the block checkout itself uses.
 */
test.describe( 'OGOship shipping rates at checkout', () => {
	let productId;

	test.beforeAll( () => {
		wpEval( `update_option( 'ogoship_dev_rates_stub', 'yes' ); delete_option( 'ogoship_dev_rates_last_request' );` );
		// Answers are cached for a few minutes; start every run from OGOship's answer.
		wpEval( `global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient%ogoship_rates_%'" );` );
		productId = Number( wpEval( `echo wc_get_product_id_by_sku( 'TEST-001' );` ) );
		expect( productId ).toBeGreaterThan( 0 );
	} );

	test.afterAll( () => {
		wpEval( `delete_option( 'ogoship_dev_rates_stub' );` );
	} );

	/**
	 * A Store API call carrying the cart nonce.
	 *
	 * @param {import('@playwright/test').APIRequestContext} request Shares the page's cookies.
	 * @param {{nonce: string}} session Current nonce, updated from each response.
	 * @param {string} path Below /wp-json/wc/store/v1/.
	 * @param {object} [data] JSON body; GET when absent.
	 */
	async function store( request, session, path, data ) {
		const url = `/wp-json/wc/store/v1/${ path }`;
		const response = data
			? await request.post( url, { data, headers: { Nonce: session.nonce } } )
			: await request.get( url );
		session.nonce = response.headers().nonce ?? session.nonce;
		expect( response.ok(), `${ path }: ${ await response.text() }` ).toBeTruthy();
		return response.json();
	}

	test( 'offers one rate per pickup point, then home delivery, and puts the chosen one on the order', async ( { page }, testInfo ) => {
		const session = { nonce: '' };
		await store( page.request, session, 'cart' );
		await store( page.request, session, 'cart/add-item', { id: productId, quantity: 2 } );
		const cart = await store( page.request, session, 'cart/update-customer', {
			shipping_address: ADDRESS,
			billing_address: { ...ADDRESS, email: 'ada@example.test', phone: '+358401234567' },
		} );

		// Only OGOship's options: the zone may offer the store's other methods too.
		const rates = cart.shipping_rates[ 0 ].shipping_rates.filter( ( r ) => r.method_id === 'ogoship' );
		expect( rates.map( ( r ) => r.name ) ).toEqual( [
			'Posti – K-market Etu-Töölö',
			'Posti – Postin Automaatti, Lidl',
			'Home delivery',
		] );
		// Store API prices are in minor units.
		expect( rates[ 0 ].price ).toBe( '590' );
		// The pickup point's address shows under its name.
		expect( rates[ 0 ].description ).toContain( 'Runeberginkatu 1' );

		// What the plugin asked: the matched zone, grams, the cart value, the store's key.
		const asked = JSON.parse( wpEval( `echo wp_json_encode( get_option( 'ogoship_dev_rates_last_request' ) );` ) );
		expect( asked.destination ).toMatchObject( { country: 'FI', postcode: '00100', city: 'Helsinki' } );
		expect( asked.zone_id ).toBeGreaterThan( 0 );
		// Two of the product, converted from the store's weight unit to grams.
		const grams = Number( wpEval( `echo round( 2 * wc_get_weight( (float) wc_get_product( ${ productId } )->get_weight(), 'g' ) );` ) );
		expect( grams ).toBeGreaterThan( 0 );
		expect( asked.contents_weight_grams ).toBe( grams );
		expect( asked.currency ).toBe( 'EUR' );
		expect( asked.key_ending ).toHaveLength( 7 );

		// The block checkout lists them too.
		await page.goto( '/checkout/' );
		await expect( page.getByText( 'Posti – K-market Etu-Töölö' ).first() ).toBeVisible();

		await store( page.request, session, 'cart/select-shipping-rate', { package_id: 0, rate_id: rates[ 1 ].rate_id } );
		const order = await store( page.request, session, 'checkout', {
			shipping_address: ADDRESS,
			billing_address: { ...ADDRESS, email: 'ada@example.test', phone: '+358401234567' },
			payment_method: 'cod',
		} );
		expect( order.order_id ).toBeGreaterThan( 0 );

		// The shipping line carries the code OGOship's order import reads.
		const line = JSON.parse(
			wpEval(
				`$item = current( wc_get_order( ${ order.order_id } )->get_items( 'shipping' ) );
				echo wp_json_encode( [ 'title' => $item->get_method_title(), 'method' => $item->get_method_id(), 'rate' => $item->get_meta( 'ogoship_rate' ), 'point' => $item->get_meta( 'ogoship_pickup_point' ) ] );`
			)
		);
		expect( line ).toEqual( {
			title: 'Posti – Postin Automaatti, Lidl',
			method: 'ogoship',
			rate: 'ogo|POSTI_SP|904051',
			point: '904051',
		} );

		// The bookkeeping meta stays out of the customer's view: the order-received page (whose block
		// template shows the shipping total without the method name) and the email, which names the
		// chosen pickup point through the shipping line's title.
		await page.goto( orderReceivedPath( order.order_id ) );
		await expect( page.getByRole( 'heading', { name: 'Order received' } ) ).toBeVisible();
		await expect( page.locator( 'body' ) ).not.toContainText( 'ogo|' );

		const { html } = await sendCompletedOrderEmail( testInfo.config.metadata.mailpitURL, order.order_id, 'html' );
		expect( html ).toContain( 'Posti – Postin Automaatti, Lidl' );
		expect( html ).not.toContain( 'ogo|' );
		expect( html ).not.toContain( 'ogoship_' );
	} );

	test( 'does not ask OGOship about a half-filled address', async ( { page } ) => {
		const session = { nonce: '' };
		const lastRequest = () => wpEval( `echo wp_json_encode( get_option( 'ogoship_dev_rates_last_request', null ) );` );
		await store( page.request, session, 'cart' );
		await store( page.request, session, 'cart/add-item', { id: productId, quantity: 1 } );

		// The country alone (the block checkout's first update): nothing to search near. The Store API
		// rejects an invalid postcode itself, so a half-typed one never gets this far from the block
		// checkout; the unit tests cover it for the classic checkout.
		wpEval( `delete_option( 'ogoship_dev_rates_last_request' );` );
		const partial = await store( page.request, session, 'cart/update-customer', {
			shipping_address: { ...ADDRESS, postcode: '', city: '', address_1: '' },
		} );
		expect( lastRequest() ).toBe( 'null' );
		expect( ( partial.shipping_rates[ 0 ]?.shipping_rates ?? [] ).filter( ( r ) => r.method_id === 'ogoship' ) ).toEqual( [] );

		// A city is enough: asked, by city only.
		await store( page.request, session, 'cart/update-customer', {
			shipping_address: { ...ADDRESS, postcode: '', address_1: '' },
		} );
		const asked = JSON.parse( lastRequest() );
		expect( asked.destination ).toMatchObject( { country: 'FI', postcode: '', city: 'Helsinki' } );
	} );

	test( 'offers nothing where OGOship has no rates', async ( { page } ) => {
		const session = { nonce: '' };
		await store( page.request, session, 'cart' );
		await store( page.request, session, 'cart/add-item', { id: productId, quantity: 1 } );
		const cart = await store( page.request, session, 'cart/update-customer', {
			shipping_address: { ...ADDRESS, country: 'SE', postcode: '11455', city: 'Stockholm' },
		} );

		const names = ( cart.shipping_rates[ 0 ]?.shipping_rates ?? [] ).map( ( r ) => r.name );
		expect( names.filter( ( n ) => n.startsWith( 'Posti' ) ) ).toEqual( [] );
	} );
} );
