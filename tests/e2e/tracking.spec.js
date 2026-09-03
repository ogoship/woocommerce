// @ts-check
import { test, expect } from '@playwright/test';
import {
	clearTracking,
	loginAsAdmin,
	orderReceivedPath,
	seedTracking,
	seededOrderId,
	sendCompletedOrderEmail,
	wpEval,
} from './helpers.js';

/**
 * The regression this plugin exists to fix.
 *
 * The 3.x plugin read only the legacy `nettivarasto_tracking` key on the
 * customer-facing pages, and never `ogoship_tracking` / `ogoship_tracking_url`
 * -- the keys RapidWarehouse actually writes. So every store on the
 * server-side integration showed its customers no tracking at all.
 */
test.describe( 'Customer-facing tracking', () => {
	let orderId;

	test.beforeAll( () => {
		orderId = seededOrderId( 'completed' );
		expect( orderId ).toBeGreaterThan( 0 );
	} );

	test.beforeEach( () => {
		clearTracking( orderId );
	} );

	test( 'shows the number, link and status written by the server-side integration', async ( { page } ) => {
		seedTracking( orderId, {
			number: 'JJFI-SERVER-SIDE',
			url: 'https://tracking.example.test/JJFI-SERVER-SIDE',
			status: 'Delivered',
		} );

		await page.goto( orderReceivedPath( orderId ) );

		const block = page.locator( '.ogoship-tracking' );
		await expect( block ).toBeVisible();
		await expect( block ).toContainText( 'JJFI-SERVER-SIDE' );
		await expect( block ).toContainText( 'Delivered' );
		await expect( block.locator( 'a' ) ).toHaveAttribute(
			'href',
			'https://tracking.example.test/JJFI-SERVER-SIDE'
		);
	} );

	test( 'falls back to the legacy 3.x meta key on historical orders', async ( { page } ) => {
		// No ogoship_* keys at all -- only what the old plugin would have left.
		wpEval(
			`$o = wc_get_order( ${ orderId } );
			$o->update_meta_data( 'nettivarasto_tracking', 'JJFI-LEGACY-ONLY' );
			$o->save();`
		);

		await page.goto( orderReceivedPath( orderId ) );

		await expect( page.locator( '.ogoship-tracking' ) ).toContainText( 'JJFI-LEGACY-ONLY' );
	} );

	test( 'renders nothing when the order has no tracking', async ( { page } ) => {
		await page.goto( orderReceivedPath( orderId ) );

		await expect( page.locator( '.ogoship-tracking' ) ).toHaveCount( 0 );
	} );

	test( 'renders a tracking number with no link', async ( { page } ) => {
		seedTracking( orderId, { number: 'JJFI-NO-LINK', url: '-', status: '' } );

		await page.goto( orderReceivedPath( orderId ) );

		const block = page.locator( '.ogoship-tracking' );
		await expect( block ).toContainText( 'JJFI-NO-LINK' );
		await expect( block.locator( 'a' ) ).toHaveCount( 0 );
	} );

	test( 'refuses to render a javascript: URL as a link', async ( { page } ) => {
		seedTracking( orderId, { number: 'JJFI-XSS', url: 'javascript:alert(1)', status: '' } );

		await page.goto( orderReceivedPath( orderId ) );

		const block = page.locator( '.ogoship-tracking' );
		await expect( block ).toContainText( 'JJFI-XSS' );
		await expect( block.locator( 'a' ) ).toHaveCount( 0 );
		expect( await page.content() ).not.toContain( 'javascript:alert(1)' );
	} );

	test( 'escapes markup in a tracking number', async ( { page } ) => {
		seedTracking( orderId, { number: '<img src=x onerror=alert(1)>', url: '-', status: '' } );

		await page.goto( orderReceivedPath( orderId ) );

		// The value must appear as text, never as a live element.
		await expect( page.locator( '.ogoship-tracking' ) ).toContainText( '<img src=x onerror=alert(1)>' );
		await expect( page.locator( '.ogoship-tracking img' ) ).toHaveCount( 0 );
	} );

} );

/**
 * My Account needs the order to belong to a logged-in customer, which the
 * order-received tests above need it NOT to: WooCommerce hides a customer's
 * order from an anonymous visitor even with a valid order key. Keeping the
 * reassignment in its own describe, with an afterAll that puts it back, stops
 * the two from interfering.
 */
test.describe( 'Tracking on the My Account order page', () => {
	let orderId;

	test.beforeAll( () => {
		orderId = seededOrderId( 'completed' );
		wpEval( `$o = wc_get_order( ${ orderId } ); $o->set_customer_id( 1 ); $o->save();` );
	} );

	test.afterAll( () => {
		wpEval( `$o = wc_get_order( ${ orderId } ); $o->set_customer_id( 0 ); $o->save();` );
		clearTracking( orderId );
	} );

	test( 'shows the tracking number and status', async ( { page } ) => {
		clearTracking( orderId );
		seedTracking( orderId, { number: 'JJFI-ACCOUNT', status: 'In transit' } );

		await loginAsAdmin( page );
		await page.goto( `/my-account/view-order/${ orderId }/` );

		const block = page.locator( '.ogoship-tracking' );
		await expect( block ).toBeVisible();
		await expect( block ).toContainText( 'JJFI-ACCOUNT' );
		await expect( block ).toContainText( 'In transit' );
	} );
} );

test.describe( 'Tracking in order emails', () => {
	let orderId;
	let mailpitURL;

	test.beforeAll( ( { }, testInfo ) => {
		orderId = seededOrderId( 'completed' );
		mailpitURL = testInfo.config.metadata.mailpitURL;
	} );

	test.beforeEach( () => {
		clearTracking( orderId );
		seedTracking( orderId, {
			number: 'JJFI-EMAIL',
			url: 'https://tracking.example.test/JJFI-EMAIL',
			status: 'Delivered',
		} );
	} );

	test( 'HTML email carries the number, link and status', async () => {
		const { html } = await sendCompletedOrderEmail( mailpitURL, orderId, 'html' );

		expect( html ).toContain( 'JJFI-EMAIL' );
		expect( html ).toContain( 'https://tracking.example.test/JJFI-EMAIL' );
		expect( html ).toContain( 'Delivered' );
	} );

	/**
	 * The 3.x plugin tested `$tracking_url` in its plain-text branch, a
	 * variable that branch never assigned, so plain-text emails always fell
	 * through to the wrong value.
	 */
	test( 'plain-text email carries the number, link and status', async () => {
		const { text } = await sendCompletedOrderEmail( mailpitURL, orderId, 'plain' );

		expect( text ).toContain( 'JJFI-EMAIL' );
		expect( text ).toContain( 'https://tracking.example.test/JJFI-EMAIL' );
		expect( text ).toContain( 'Delivered' );
		// The literal escape sequence the old plugin printed by using '\n'
		// inside single quotes.
		expect( text ).not.toContain( '\\n' );
	} );
} );
