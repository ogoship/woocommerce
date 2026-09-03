// @ts-check
import { defineConfig, devices } from '@playwright/test';
import { readFileSync } from 'node:fs';

/**
 * Read a value out of .docker/.env, falling back to the documented default.
 * Keeps the suite in step with a developer who moved the store to another port.
 */
function envValue( key, fallback ) {
	try {
		const line = readFileSync( new URL( './.docker/.env', import.meta.url ), 'utf8' )
			.split( '\n' )
			.find( ( l ) => l.startsWith( `${ key }=` ) );
		return line ? line.slice( key.length + 1 ).replace( /^"|"$/g, '' ).trim() : fallback;
	} catch {
		return fallback;
	}
}

const wpPort = envValue( 'WP_PORT', '8443' );
const mailpitPort = envValue( 'MAILPIT_PORT', '8026' );

export default defineConfig( {
	testDir: './tests/e2e',
	// The dev store is a single shared WordPress install, so tests mutate the
	// same products and orders. Running them in parallel would have them step
	// on each other.
	fullyParallel: false,
	workers: 1,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? [ [ 'github' ], [ 'html', { open: 'never' } ] ] : 'list',

	use: {
		baseURL: `https://localhost:${ wpPort }`,
		// The store is served over TLS by Caddy's local CA -- see
		// .docker/Caddyfile for why plain HTTP is not an option.
		ignoreHTTPSErrors: true,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},

	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],

	// Exposed to the specs; see tests/e2e/helpers.js.
	metadata: {
		mailpitURL: `http://localhost:${ mailpitPort }`,
	},
} );
