// @ts-check
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const repoRoot = resolve( dirname( fileURLToPath( import.meta.url ) ), '../..' );

/**
 * Run a WP-CLI command in the dev store and return trimmed stdout.
 *
 * @param {string[]} args WP-CLI arguments.
 * @returns {string}
 */
export function wp( args ) {
	return execFileSync( resolve( repoRoot, 'bin/wp' ), args, {
		encoding: 'utf8',
		cwd: repoRoot,
	} ).trim();
}

/**
 * Evaluate PHP inside the store and return what it echoes.
 *
 * @param {string} php PHP snippet.
 * @returns {string}
 */
export function wpEval( php ) {
	return wp( [ 'eval', php ] );
}

/**
 * Write OGOship tracking meta onto an order, the way the server-side
 * integration does. Mirrors bin/seed-tracking.sh.
 *
 * @param {number} orderId Order to write to.
 * @param {{number?: string, url?: string, status?: string}} tracking Values.
 */
export function seedTracking( orderId, tracking = {} ) {
	const {
		number = 'JJFI00000000000012345',
		url = 'https://tracking.example.test/JJFI00000000000012345',
		status = 'Delivered',
	} = tracking;

	execFileSync(
		'docker',
		[
			'compose', 'exec', '-T', 'wpcli',
			'wp', '--path=/var/www/html',
			'eval-file', '/seed/tracking.php',
			String( orderId ), number, url, status,
		],
		{ encoding: 'utf8', cwd: resolve( repoRoot, '.docker' ) }
	);
}

/**
 * Remove every OGOship tracking key from an order, so a test starts clean.
 *
 * @param {number} orderId Order to clear.
 */
export function clearTracking( orderId ) {
	wpEval(
		`$o = wc_get_order( ${ orderId } );
		foreach ( [ 'ogoship_tracking', 'ogoship_tracking_url', 'nettivarasto_tracking', '_ogoship_tracking_status' ] as $k ) {
			$o->delete_meta_data( $k );
		}
		$o->save();`
	);
}

/**
 * Find a seeded order by the marker meta orders.php writes.
 *
 * @param {string} marker One of 'processing', 'completed', 'legacy-tracking'.
 * @returns {number}
 */
export function seededOrderId( marker ) {
	const id = wpEval(
		`$o = wc_get_orders( [ 'limit' => 1, 'meta_key' => '_ogoship_dev_seed', 'meta_value' => '${ marker }', 'return' => 'ids' ] );
		echo $o ? $o[0] : 0;`
	);
	return Number( id );
}

/**
 * The order-received URL for an order, which needs no login.
 *
 * @param {number} orderId Order id.
 * @returns {string}
 */
export function orderReceivedPath( orderId ) {
	const key = wpEval( `echo wc_get_order( ${ orderId } )->get_order_key();` );
	return `/checkout/order-received/${ orderId }/?key=${ key }`;
}

/**
 * Log into wp-admin.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 */
export async function loginAsAdmin( page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'admin' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
}

/**
 * Delete every message in Mailpit, so an assertion cannot match an old email.
 *
 * @param {string} mailpitURL Base URL of the Mailpit API.
 */
export async function clearMailbox( mailpitURL ) {
	await fetch( `${ mailpitURL }/api/v1/messages`, { method: 'DELETE' } );
}

/**
 * Trigger a WooCommerce order email and return its rendered parts.
 *
 * @param {string} mailpitURL Base URL of the Mailpit API.
 * @param {number} orderId    Order to send for.
 * @param {'html'|'plain'}   type Email format to render.
 * @returns {Promise<{html: string, text: string}>}
 */
export async function sendCompletedOrderEmail( mailpitURL, orderId, type = 'html' ) {
	await clearMailbox( mailpitURL );

	wp( [ 'option', 'patch', 'update', 'woocommerce_customer_completed_order_settings', 'email_type', type ] );
	wpEval( `WC()->mailer()->emails['WC_Email_Customer_Completed_Order']->trigger( ${ orderId } );` );

	// Mailpit accepts over SMTP asynchronously; give it a moment to land.
	for ( let attempt = 0; attempt < 20; attempt++ ) {
		const list = await ( await fetch( `${ mailpitURL }/api/v1/messages?limit=1` ) ).json();
		if ( list.messages?.length ) {
			const message = await ( await fetch( `${ mailpitURL }/api/v1/message/${ list.messages[ 0 ].ID }` ) ).json();
			return { html: message.HTML ?? '', text: message.Text ?? '' };
		}
		await new Promise( ( r ) => setTimeout( r, 250 ) );
	}

	throw new Error( 'No email arrived in Mailpit' );
}
