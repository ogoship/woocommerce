<?php
/**
 * Plugin Name:       OGOship for WooCommerce
 * Plugin URI:        https://github.com/ogoship/woocommerce
 * Description:       Companion plugin for the OGOship fulfillment integration. Adds the OGOship product fields (EAN, HS code, country of origin, supplier, customs data) and shows shipment tracking to your customers.
 * Version:           1.0.0
 * Author:            OGOship
 * Author URI:        https://www.ogoship.com
 * License:           GPL v3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       ogoship-for-woocommerce
 * Domain Path:       /languages
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   10.8
 *
 * @package OGOship\WooCommerce
 *
 * OGOship for WooCommerce is free software: you can redistribute it and/or
 * modify it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or (at your
 * option) any later version.
 */

declare( strict_types=1 );

namespace OGOship\WooCommerce;

defined( 'ABSPATH' ) || exit;

const VERSION     = '1.0.0';
const PLUGIN_FILE = __FILE__;

/**
 * The pre-4.0 OGOship plugin, which owns the same product meta and renders its
 * own tracking output. Both being active means duplicate UI, so we stand down.
 */
const LEGACY_PLUGIN = 'woocommerce-nettivarasto-api/nettivarasto.php';

/*
 * Autoloading.
 *
 * Composer is a development dependency only (phpcs, phpstan, phpunit) -- the
 * plugin itself has no runtime packages, so it ships with a plain PSR-4 loader
 * rather than requiring a vendor/ directory to exist. If Composer's autoloader
 * happens to be present it is preferred, which keeps the dev container and the
 * released build on the same code path.
 */
if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix = __NAMESPACE__ . '\\';
			if ( ! str_starts_with( $class, $prefix ) ) {
				return;
			}

			$relative = substr( $class, strlen( $prefix ) );
			$file     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';

			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

// HPOS has to be declared before WooCommerce boots, and the declaration must be
// honest -- this plugin never touches the posts table for orders.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				PLUGIN_FILE,
				true
			);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'cart_checkout_blocks',
				PLUGIN_FILE,
				true
			);
		}
	}
);

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ), 20 );
