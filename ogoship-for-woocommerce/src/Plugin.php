<?php
/**
 * Plugin bootstrap.
 *
 * @package OGOship\WooCommerce
 */

declare( strict_types=1 );

namespace OGOship\WooCommerce;

use OGOship\WooCommerce\Admin\LegacyNotice;
use OGOship\WooCommerce\Admin\OrderColumn;
use OGOship\WooCommerce\Admin\OrderPanel;
use OGOship\WooCommerce\Admin\StatusPage;
use OGOship\WooCommerce\Frontend\CustomerDisplay;
use OGOship\WooCommerce\Product\Fields;
use OGOship\WooCommerce\Product\VariationFields;
use OGOship\WooCommerce\Rest\InfoController;
use OGOship\WooCommerce\Support\ApiActivity;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's feature classes into WordPress.
 *
 * Deliberately thin: every feature registers its own hooks in `register()`, so
 * a feature can be disabled by not constructing it -- which is exactly what the
 * legacy-plugin guard below does.
 */
final class Plugin {

	/**
	 * Entry point, called on `plugins_loaded`.
	 */
	public static function boot(): void {
		if ( ! self::woocommerce_active() ) {
			// `Requires Plugins: woocommerce` covers fresh installs, but a
			// merchant can still deactivate WooCommerce underneath us.
			add_action( 'admin_notices', array( self::class, 'render_missing_woocommerce_notice' ) );
			return;
		}

		// Features that are safe -- and useful -- even alongside the old plugin.
		( new ApiActivity() )->register();
		( new InfoController() )->register();
		( new StatusPage() )->register();

		if ( self::legacy_plugin_active() ) {
			// The pre-4.0 plugin renders its own product tab and tracking
			// output against the same meta keys. Running both would double
			// every field and every tracking block, so we hold back the
			// overlapping features and ask the merchant to deactivate it.
			( new LegacyNotice() )->register();
			return;
		}

		( new Fields() )->register();
		( new VariationFields() )->register();
		( new CustomerDisplay() )->register();
		( new OrderColumn() )->register();
		( new OrderPanel() )->register();
	}

	/**
	 * Whether WooCommerce is loaded.
	 */
	public static function woocommerce_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Whether the pre-4.0 OGOship plugin is active.
	 */
	public static function legacy_plugin_active(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( LEGACY_PLUGIN );
	}

	/**
	 * Absolute path to a file inside the plugin directory.
	 *
	 * @param string $relative Path relative to the plugin root.
	 */
	public static function path( string $relative = '' ): string {
		return plugin_dir_path( PLUGIN_FILE ) . ltrim( $relative, '/' );
	}

	/**
	 * Public URL of a file inside the plugin directory.
	 *
	 * @param string $relative Path relative to the plugin root.
	 */
	public static function url( string $relative = '' ): string {
		return plugin_dir_url( PLUGIN_FILE ) . ltrim( $relative, '/' );
	}

	/**
	 * Admin notice shown when WooCommerce is missing.
	 */
	public static function render_missing_woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__(
				'OGOship for WooCommerce needs WooCommerce to be installed and active.',
				'ogoship-for-woocommerce'
			)
		);
	}
}
