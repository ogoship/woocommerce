<?php
/**
 * OGOship column on the admin orders list.
 *
 * @package OGOship\WooCommerce
 */

declare( strict_types=1 );

namespace OGOship\WooCommerce\Admin;

use OGOship\WooCommerce\Order\Tracking;

defined( 'ABSPATH' ) || exit;

/**
 * Adds an "OGOship" column showing fulfillment state at a glance.
 *
 * Registered against both the HPOS order list table and the legacy posts
 * table, because a store can be on either.
 */
final class OrderColumn {

	private const COLUMN = 'ogoship';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		// HPOS order list.
		add_filter( 'woocommerce_shop_order_list_table_columns', array( $this, 'add_column' ) );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'render_column' ), 10, 2 );

		// Legacy post-table order list.
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
	}

	/**
	 * Insert the column just before the order total.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public function add_column( array $columns ): array {
		$new = array();

		foreach ( $columns as $key => $label ) {
			if ( 'order_total' === $key ) {
				$new[ self::COLUMN ] = __( 'OGOship', 'ogoship-for-woocommerce' );
			}
			$new[ $key ] = $label;
		}

		// Column list did not contain order_total (filtered by another plugin).
		if ( ! isset( $new[ self::COLUMN ] ) ) {
			$new[ self::COLUMN ] = __( 'OGOship', 'ogoship-for-woocommerce' );
		}

		return $new;
	}

	/**
	 * Render the column body.
	 *
	 * The second argument is a WC_Order under HPOS and a post ID on the legacy
	 * screen; Tracking::for() accepts either.
	 *
	 * @param string        $column Column key being rendered.
	 * @param \WC_Order|int $order  Order object or post ID.
	 */
	public function render_column( string $column, $order ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$tracking = Tracking::for( $order );

		if ( ! $tracking instanceof Tracking ) {
			return;
		}

		$parts = array();

		if ( $tracking->has_tracking() ) {
			$url    = $tracking->url();
			$number = $tracking->link_text();

			$parts[] = '' !== $url
				? sprintf(
					'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
					esc_url( $url ),
					esc_html( $number )
				)
				: esc_html( $number );
		}

		$status = $tracking->status();
		if ( '' !== $status ) {
			$parts[] = sprintf(
				'<span class="ogoship-status">%s</span>',
				esc_html( $status )
			);
		}

		if ( $tracking->is_exchange() ) {
			$parts[] = sprintf(
				'<span class="ogoship-exchange">%s</span>',
				esc_html__( 'Exchange', 'ogoship-for-woocommerce' )
			);
		}

		if ( empty( $parts ) ) {
			echo '<span class="ogoship-none" aria-hidden="true">&ndash;</span>';
			echo '<span class="screen-reader-text">' . esc_html__( 'No OGOship tracking yet', 'ogoship-for-woocommerce' ) . '</span>';
			return;
		}

		// Each part is individually escaped above.
		echo wp_kses_post( implode( '<br>', $parts ) );
	}
}
