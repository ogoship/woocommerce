<?php
/**
 * PHPUnit bootstrap for the unit suite.
 *
 * No WordPress is loaded. Brain Monkey stubs the WP/WC functions the classes
 * under test call, and Mockery stands in for WC_Product / WC_Order. That keeps
 * the suite fast and lets it run anywhere -- including in CI without a
 * database.
 *
 * @package OGOship\WooCommerce\Tests
 */

declare( strict_types=1 );

require_once __DIR__ . '/../vendor/autoload.php';

// The plugin's classes guard on ABSPATH so they cannot be loaded directly by a
// web request. Under test there is no WordPress, so define it.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Constants the plugin's main file would normally define.
if ( ! defined( 'OGOship\WooCommerce\VERSION' ) ) {
	define( 'OGOship\WooCommerce\VERSION', '1.0.0-test' );
}
if ( ! defined( 'OGOship\WooCommerce\PLUGIN_FILE' ) ) {
	define( 'OGOship\WooCommerce\PLUGIN_FILE', dirname( __DIR__ ) . '/ogoship-for-woocommerce.php' );
}
if ( ! defined( 'OGOship\WooCommerce\LEGACY_PLUGIN' ) ) {
	define( 'OGOship\WooCommerce\LEGACY_PLUGIN', 'woocommerce-nettivarasto-api/nettivarasto.php' );
}

/*
 * Mockery needs these classes to exist before it can mock them by name.
 * Declaring bare stubs is enough: every method the tests exercise is defined
 * by the mock, and this avoids dragging WooCommerce into a unit suite.
 */
if ( ! class_exists( 'WC_Product' ) ) {
	// phpcs:disable Squiz.Commenting.ClassComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound
	class WC_Product {}
	class WC_Product_Variation extends WC_Product {}
	class WC_Order {}
	// phpcs:enable
}

// PSR-4 autoloader for the plugin's own classes, mirroring what the plugin
// registers at runtime when Composer's autoloader is absent.
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'OGOship\\WooCommerce\\';
		if ( ! str_starts_with( $class_name, $prefix ) || str_starts_with( $class_name, $prefix . 'Tests\\' ) ) {
			return;
		}

		$file = dirname( __DIR__ ) . '/src/'
			. str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
