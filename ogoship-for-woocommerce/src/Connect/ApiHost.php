<?php
/**
 * Which OGOship API host this store talks to.
 *
 * @package OGOship\WooCommerce
 */

declare( strict_types=1 );

namespace OGOship\WooCommerce\Connect;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the OGOship API.
 */
final class ApiHost {

	private const BASE_API = 'https://api.ogoship.com';

	/**
	 * Base URL of the OGOship API.
	 */
	public static function base(): string {
		// A full URL is allowed so a developer can point at an ngrok tunnel while working on the
		// connect flow locally.
		if ( defined( 'OGOSHIP_API_BASE' ) && is_string( \OGOSHIP_API_BASE ) && '' !== \OGOSHIP_API_BASE ) {
			return untrailingslashit( \OGOSHIP_API_BASE );
		}

		return self::BASE_API;
	}
}
