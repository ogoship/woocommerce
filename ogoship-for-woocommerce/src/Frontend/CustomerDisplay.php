<?php
/**
 * Customer-facing shipment tracking display.
 *
 * @package OGOship\WooCommerce
 */

declare( strict_types=1 );

namespace OGOship\WooCommerce\Frontend;

use OGOship\WooCommerce\Order\Tracking;

defined( 'ABSPATH' ) || exit;

/**
 * Shows OGOship tracking to the customer everywhere they look for it.
 *
 * The pre-4.0 plugin rendered tracking in two places, both broken:
 *
 *  - My Account -> order read only the legacy `nettivarasto_tracking` key, so
 *    stores on the server-side integration showed nothing at all.
 *  - The plain-text email branch tested `$tracking_url`, a variable never
 *    assigned in that branch, so it always fell through to the wrong value.
 *
 * Neither escaped its output, despite the values arriving from a remote system
 * and being printed into an href.
 */
final class CustomerDisplay {

	/**
	 * Register hooks.
	 */
	public function register(): void {
		// My Account -> Orders -> View order.
		add_action( 'woocommerce_view_order', array( $this, 'render_for_order_id' ), 5 );

		// Order received / thank-you page, including the Blocks order
		// confirmation, which fires the same hook.
		add_action( 'woocommerce_thankyou', array( $this, 'render_for_order_id' ), 5 );

		// Order emails, HTML and plain text.
		add_action( 'woocommerce_email_order_meta', array( $this, 'render_for_email' ), 10, 3 );
	}

	/**
	 * Render for a hook that passes an order ID.
	 *
	 * @param int $order_id Order ID.
	 */
	public function render_for_order_id( int $order_id ): void {
		$tracking = Tracking::for( $order_id );

		if ( ! $tracking instanceof Tracking || ! $tracking->has_tracking() ) {
			return;
		}

		$this->render_html( $tracking );
	}

	/**
	 * Render inside an order email.
	 *
	 * @param \WC_Order $order         The order.
	 * @param bool      $sent_to_admin Whether this copy goes to an admin.
	 * @param bool      $plain_text    Whether this is the plain-text part.
	 */
	public function render_for_email( \WC_Order $order, bool $sent_to_admin = false, bool $plain_text = false ): void {
		$tracking = Tracking::for( $order );

		if ( ! $tracking instanceof Tracking || ! $tracking->has_tracking() ) {
			return;
		}

		if ( $plain_text ) {
			$this->render_plain( $tracking );
			return;
		}

		$this->render_html( $tracking );
	}

	/**
	 * HTML rendering, shared by the account page, thank-you page and emails.
	 *
	 * @param Tracking $tracking Resolved tracking data.
	 */
	private function render_html( Tracking $tracking ): void {
		$number = $tracking->number();
		$url    = $tracking->url();
		$status = $tracking->status();

		echo '<section class="ogoship-tracking woocommerce-order-tracking">';

		printf(
			'<h2 class="woocommerce-column__title">%s</h2>',
			esc_html__( 'Track your delivery', 'ogoship-for-woocommerce' )
		);

		echo '<ul class="ogoship-tracking__list woocommerce-order-overview">';

		if ( '' !== $number ) {
			printf(
				'<li class="ogoship-tracking__number">%1$s <strong>%2$s</strong></li>',
				esc_html__( 'Tracking number:', 'ogoship-for-woocommerce' ),
				esc_html( $number )
			);
		}

		if ( '' !== $url ) {
			printf(
				'<li class="ogoship-tracking__link"><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></li>',
				esc_url( $url ),
				esc_html__( 'Follow your shipment', 'ogoship-for-woocommerce' )
			);
		}

		if ( '' !== $status ) {
			printf(
				'<li class="ogoship-tracking__status">%1$s <strong>%2$s</strong></li>',
				esc_html__( 'Status:', 'ogoship-for-woocommerce' ),
				esc_html( $status )
			);
		}

		echo '</ul>';
		echo '</section>';
	}

	/**
	 * Plain-text rendering for the text part of an email.
	 *
	 * @param Tracking $tracking Resolved tracking data.
	 */
	private function render_plain( Tracking $tracking ): void {
		$number = $tracking->number();
		$url    = $tracking->url();
		$status = $tracking->status();

		echo "\n\n" . esc_html( wp_strip_all_tags( __( 'Track your delivery', 'ogoship-for-woocommerce' ) ) ) . "\n";

		if ( '' !== $number ) {
			echo esc_html__( 'Tracking number:', 'ogoship-for-woocommerce' ) . ' ' . esc_html( $number ) . "\n";
		}

		if ( '' !== $url ) {
			echo esc_html__( 'Follow your shipment:', 'ogoship-for-woocommerce' ) . ' ' . esc_url_raw( $url ) . "\n";
		}

		if ( '' !== $status ) {
			echo esc_html__( 'Status:', 'ogoship-for-woocommerce' ) . ' ' . esc_html( $status ) . "\n";
		}

		echo "\n";
	}
}
