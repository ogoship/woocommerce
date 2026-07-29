<?php
/**
 * REST endpoint describing this plugin to the OGOship server.
 *
 * @package OGOship\WooCommerce
 */

declare( strict_types=1 );

namespace OGOship\WooCommerce\Rest;

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use OGOship\WooCommerce\Plugin;

use const OGOship\WooCommerce\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /wp-json/ogoship/v1/info`
 *
 * Lets the OGOship server discover what this store can do before it decides
 * how to talk to it. Authentication piggybacks on the merchant's existing
 * WooCommerce consumer key -- no second credential to configure (see
 * ApiActivity::claim_namespace).
 *
 * The response leads with a `capabilities` list rather than asking callers to
 * compare version strings: a server that wants to know whether variation-level
 * product meta is available should look for `variation_meta`, not parse
 * "1.2.0" and hope.
 */
final class InfoController {

	public const NAMESPACE_ = 'ogoship/v1';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_,
			'/info',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_info' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Only callers who could read WooCommerce data anyway.
	 *
	 * With WooCommerce key auth applied to this namespace, a valid read-scope
	 * consumer key resolves to its owning user, so this is the same gate the
	 * `wc/v3` endpoints use.
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'view_woocommerce_reports' );
	}

	/**
	 * Build the response.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_info(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'plugin'          => 'ogoship-for-woocommerce',
				'version'         => VERSION,
				'wp'              => get_bloginfo( 'version' ),
				'wc'              => defined( 'WC_VERSION' ) ? WC_VERSION : null,
				'php'             => PHP_VERSION,
				'hpos'            => self::hpos_enabled(),
				'blocks_checkout' => self::blocks_checkout(),
				'legacy_plugin'   => Plugin::legacy_plugin_active(),
				'capabilities'    => self::capabilities(),
			),
			200
		);
	}

	/**
	 * What this build of the plugin supports.
	 *
	 * Append to this list as features land; never repurpose an existing name.
	 *
	 * @return list<string>
	 */
	public static function capabilities(): array {
		return array(
			// Product-level _nettivarasto_* meta is editable in wp-admin.
			'product_meta',
			// The same fields exist per variation, with parent fallback.
			'variation_meta',
			// Tracking is shown to customers on account, thank-you and email.
			'tracking_display',
			// _ogoship_tracking_status is surfaced to merchant and customer.
			'tracking_status',
		);
	}

	/**
	 * Whether High-Performance Order Storage is in use.
	 */
	private static function hpos_enabled(): bool {
		if ( ! class_exists( CustomOrdersTableController::class ) || ! function_exists( 'wc_get_container' ) ) {
			return false;
		}

		return wc_get_container()
			->get( CustomOrdersTableController::class )
			->custom_orders_table_usage_is_enabled();
	}

	/**
	 * Whether the checkout page uses the Blocks checkout.
	 */
	private static function blocks_checkout(): bool {
		$checkout_id = (int) wc_get_page_id( 'checkout' );

		if ( $checkout_id <= 0 || ! function_exists( 'has_block' ) ) {
			return false;
		}

		return has_block( 'woocommerce/checkout', $checkout_id );
	}
}
