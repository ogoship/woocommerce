<?php
/**
 * Write tracking meta onto an order exactly the way the OGOship server does.
 *
 * This is the local stand-in for RapidWarehouse's CompleteOrderAsync, which
 * PUTs the order with `ogoship_tracking` + `ogoship_tracking_url` meta and
 * status `completed` (see RapidWarehouse/WooCommerceIntegration/WooCommerceAPI.cs).
 * Reproducing it here is what makes the tracking feature testable without
 * OGOship in the loop.
 *
 * Usage: wp eval-file /seed/tracking.php <order_id> [number] [url] [status]
 *
 * @package OGOship\WooCommerce\Dev
 */

defined( 'ABSPATH' ) || exit;

/** @var array $args Positional arguments passed after the filename. */
$order_id = isset( $args[0] ) ? (int) $args[0] : 0;
$number   = $args[1] ?? 'JJFI00000000000012345';
$url      = $args[2] ?? '';
$status   = $args[3] ?? 'Delivered';

if ( ! $order_id ) {
	WP_CLI::error( 'Usage: wp eval-file /seed/tracking.php <order_id> [number] [url] [status]' );
}

$order = wc_get_order( $order_id );
if ( ! $order ) {
	WP_CLI::error( "Order #{$order_id} not found." );
}

// An empty URL argument means "carrier gave us a number but no link" -- a real
// and common case that the display code has to handle.
if ( '' !== $url && '-' !== $url ) {
	$order->update_meta_data( 'ogoship_tracking_url', $url );
}
$order->update_meta_data( 'ogoship_tracking', $number );

// Written separately by the Ecom connector's InformTrackingChangeAsync.
$order->update_meta_data( '_ogoship_tracking_status', $status );

// RapidWarehouse flips the order to completed in the same call.
if ( ! $order->has_status( 'completed' ) ) {
	$order->update_status( 'completed', 'Shipped by OGOship (dev seed). ' );
} else {
	$order->save();
}

WP_CLI::success(
	sprintf(
		'Order #%d: tracking=%s url=%s status=%s',
		$order_id,
		$number,
		'' === $url ? '(none)' : $url,
		$status
	)
);
