/**
 * Capture the wordpress.org listing screenshots from the dev store.
 *
 *   ./bin/setup.sh
 *   ./bin/wp eval-file /seed/screenshot-state.php
 *   node bin/screenshots.mjs
 *
 * Output goes to .wordpress-org/, which is the directory copied into the
 * plugin's SVN `assets/` folder at release time. The filenames matter:
 * wordpress.org pairs screenshot-N.png with the Nth line of the readme's
 * == Screenshots == section.
 */

import { chromium } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );
const outDir = resolve( repoRoot, '.wordpress-org' );
const baseURL = 'https://localhost:8443';

const wpEval = ( php ) =>
	execFileSync( resolve( repoRoot, 'bin/wp' ), [ 'eval', php ], {
		encoding: 'utf8',
		cwd: repoRoot,
	} ).trim();

mkdirSync( outDir, { recursive: true } );

const browser = await chromium.launch();
const context = await browser.newContext( {
	baseURL,
	ignoreHTTPSErrors: true,
	viewport: { width: 1440, height: 900 },
	// wordpress.org serves these at up to 1280px wide; 2x keeps them crisp on
	// the retina displays most people browse the directory on.
	deviceScaleFactor: 2,
} );
const page = await context.newPage();

/** Log into wp-admin once; the session is reused for the admin shots. */
async function login() {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'admin' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
}

/** Hide chrome that adds noise without telling the reader anything. */
async function tidyAdmin() {
	await page.addStyleTag( {
		content: `
			#wpadminbar, #adminmenumain, .notice, .update-nag,
			#wpfooter, .woocommerce-layout__header, #wpbody-content > .wrap > h1 + .notice { display: none !important; }
			#wpcontent, #wpbody-content { margin-left: 0 !important; padding-left: 0 !important; }
			html.wp-toolbar { padding-top: 0 !important; }
		`,
	} );
}

async function shot( n, locator, description ) {
	const path = resolve( outDir, `screenshot-${ n }.png` );
	await locator.screenshot( { path } );
	console.log( `screenshot-${ n }.png  ${ description }` );
}

await login();

// -- 1. The OGOship tab on the product edit screen ---------------------------
const productId = wpEval( "echo wc_get_product_id_by_sku( 'TEST-002' );" );
await page.goto( `/wp-admin/post.php?post=${ productId }&action=edit` );
await tidyAdmin();
await page.click( 'a[href="#ogoship_product_data"]' );
await page.waitForSelector( '#ogoship_product_data', { state: 'visible' } );
await shot( 1, page.locator( '#woocommerce-product-data' ), 'product data box, OGOship tab' );

// -- 2. OGOship fields on a variation, showing the inherited value -----------
const parentId = wpEval( "echo wc_get_product_id_by_sku( 'TEST-VAR' );" );
await page.goto( `/wp-admin/post.php?post=${ parentId }&action=edit` );
await tidyAdmin();
await page.click( 'a[href="#variable_product_options"]' );
await page.waitForSelector( '.woocommerce_variations .woocommerce_variation' );
await page.locator( '#variable_product_options .expand_all' ).first().click();
// The full variation row is enormous; crop to the OGOship block, which is what
// the caption is about.
const variationFields = page.locator( '.ogoship-variation-fields' ).first();
await variationFields.waitFor( { state: 'visible' } );
await variationFields.scrollIntoViewIfNeeded();
await shot( 2, variationFields, 'variation with inherited placeholders' );

// -- 3. Tracking as the customer sees it -------------------------------------
const orderId = wpEval(
	"$o = wc_get_orders( [ 'limit' => 1, 'meta_key' => '_ogoship_dev_seed', 'meta_value' => 'screenshot', 'return' => 'ids' ] ); echo $o ? $o[0] : 0;"
);
await page.goto( `/my-account/view-order/${ orderId }/` );
await page.locator( '.ogoship-tracking' ).waitFor( { state: 'visible' } );
await page.locator( '.ogoship-tracking' ).scrollIntoViewIfNeeded();
await shot( 3, page.locator( '.ogoship-tracking' ), 'customer tracking block' );

// -- 4. The connection status panel ------------------------------------------
// Make a few authenticated calls first, the way OGOship would, so the panel
// shows its populated state rather than "No API requests recorded yet".
{
	const keys = Object.fromEntries(
		readFileSync( resolve( repoRoot, '.docker/.api-keys' ), 'utf8' )
			.split( '\n' )
			.filter( Boolean )
			.map( ( line ) => line.split( '=' ) )
	);
	const auth = Buffer.from( `${ keys.WC_CONSUMER_KEY }:${ keys.WC_CONSUMER_SECRET }` ).toString( 'base64' );

	// Node rejects Caddy's self-signed certificate otherwise.
	process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
	for ( const path of [ 'products?per_page=10', 'orders?per_page=10', 'products?per_page=10' ] ) {
		await fetch( `${ baseURL }/wp-json/wc/v3/${ path }`, {
			headers: { Authorization: `Basic ${ auth }` },
		} );
	}
}

await page.goto( '/wp-admin/admin.php?page=wc-status&tab=ogoship' );
await tidyAdmin();
await page.waitForSelector( '.wc_status_table' );
await shot( 4, page.locator( '#wpbody-content .wrap' ), 'WooCommerce > Status > OGOship' );

await browser.close();
console.log( `\nWritten to ${ outDir }` );
