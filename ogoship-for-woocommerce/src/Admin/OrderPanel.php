<?php
/**
 * OGOship metabox on the admin order edit screen.
 *
 * @package OGOship\WooCommerce
 */

declare( strict_types=1 );

namespace OGOship\WooCommerce\Admin;

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use OGOship\WooCommerce\Order\Tracking;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only panel showing what OGOship knows about this order.
 *
 * Purely informational: this plugin never writes fulfillment data, so there is
 * nothing here to edit. It exists so support can answer "has it shipped?"
 * without leaving WooCommerce.
 */
final class OrderPanel {

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 40 );
	}

	/**
	 * Register the metabox against whichever order screen is in use.
	 */
	public function add_meta_box(): void {
		$screen = class_exists( CustomOrdersTableController::class )
			&& wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
				? wc_get_page_screen_id( 'shop-order' )
				: 'shop_order';

		add_meta_box(
			'ogoship-order-panel',
			__( 'OGOship', 'ogoship-for-woocommerce' ),
			array( $this, 'render' ),
			$screen,
			'side',
			'default'
		);
	}

	/**
	 * Render the metabox.
	 *
	 * @param \WP_Post|\WC_Order $post_or_order Screen subject.
	 */
	public function render( $post_or_order ): void {
		$tracking = Tracking::for(
			$post_or_order instanceof \WC_Order ? $post_or_order : $post_or_order->ID
		);

		if ( ! $tracking instanceof Tracking ) {
			return;
		}

		if ( ! $tracking->has_tracking() && '' === $tracking->status() ) {
			printf(
				'<p class="ogoship-none">%s</p>',
				esc_html__( 'OGOship has not reported a shipment for this order yet.', 'ogoship-for-woocommerce' )
			);
			return;
		}

		echo '<ul class="ogoship-order-panel">';

		$number = $tracking->number();
		if ( '' !== $number ) {
			printf(
				'<li><strong>%1$s</strong><br><code>%2$s</code></li>',
				esc_html__( 'Tracking number', 'ogoship-for-woocommerce' ),
				esc_html( $number )
			);
		}

		$url = $tracking->url();
		if ( '' !== $url ) {
			printf(
				'<li><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></li>',
				esc_url( $url ),
				esc_html__( 'Open tracking page', 'ogoship-for-woocommerce' )
			);
		}

		$status = $tracking->status();
		if ( '' !== $status ) {
			printf(
				'<li><strong>%1$s</strong><br>%2$s</li>',
				esc_html__( 'Shipment status', 'ogoship-for-woocommerce' ),
				esc_html( $status )
			);
		}

		if ( $tracking->is_exchange() ) {
			$origin = $tracking->origin_reference();
			printf(
				'<li><strong>%1$s</strong><br>%2$s</li>',
				esc_html__( 'Exchange order', 'ogoship-for-woocommerce' ),
				'' !== $origin
					/* translators: %s: reference of the original order. */
					? esc_html( sprintf( __( 'Created from %s', 'ogoship-for-woocommerce' ), $origin ) )
					: esc_html__( 'Created by OGOship from a return', 'ogoship-for-woocommerce' )
			);
		}

		echo '</ul>';
	}
}
