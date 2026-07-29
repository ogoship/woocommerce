<?php
/**
 * Which OGOship environment this store connects to.
 *
 * @package OGOship\WooCommerce
 */

declare( strict_types=1 );

namespace OGOship\WooCommerce\Connect;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the OGOship API host.
 *
 * Production for every real merchant. The other environments exist so OGOship's own developers can
 * point a test store at dev or beta, which is selected by defining `OGOSHIP_ENVIRONMENT` in
 * wp-config.php rather than by a setting — merchants should never see the choice, and it must not be
 * changeable from wp-admin.
 */
final class Environment {

	private const HOSTS = array(
		'production' => 'https://api.ogoship.com',
		'beta'       => 'https://betaapi.ogoship.com',
		'dev'        => 'https://devapi.ogoship.com',
	);

	/**
	 * Base URL of the OGOship API.
	 */
	public static function api_base(): string {
		// A full URL is allowed so a developer can point at an ngrok tunnel while working on the
		// connect flow locally.
		if ( defined( 'OGOSHIP_API_BASE' ) && is_string( \OGOSHIP_API_BASE ) && '' !== \OGOSHIP_API_BASE ) {
			return untrailingslashit( \OGOSHIP_API_BASE );
		}

		$name = defined( 'OGOSHIP_ENVIRONMENT' ) ? (string) \OGOSHIP_ENVIRONMENT : 'production';

		return self::HOSTS[ $name ] ?? self::HOSTS['production'];
	}

	/**
	 * The name of the current environment, for display on the status panel.
	 */
	public static function name(): string {
		if ( defined( 'OGOSHIP_API_BASE' ) ) {
			return 'custom';
		}

		$name = defined( 'OGOSHIP_ENVIRONMENT' ) ? (string) \OGOSHIP_ENVIRONMENT : 'production';

		return isset( self::HOSTS[ $name ] ) ? $name : 'production';
	}

	/**
	 * Whether this store talks to production.
	 */
	public static function is_production(): bool {
		return 'production' === self::name();
	}
}
