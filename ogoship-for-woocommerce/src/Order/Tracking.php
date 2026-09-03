<?php
/**
 * Resolve OGOship shipment tracking from order meta.
 *
 * @package OGOship\WooCommerce
 */

declare( strict_types=1 );

namespace OGOship\WooCommerce\Order;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the tracking information the OGOship server writes onto an order.
 *
 * ---------------------------------------------------------------------------
 * This plugin never WRITES these keys. Fulfillment is server-side.
 * ---------------------------------------------------------------------------
 *
 *   ogoship_tracking       tracking number  -- RapidWarehouse, WooCommerceAPI.cs
 *   ogoship_tracking_url   tracking link    -- RapidWarehouse, WooCommerceAPI.cs
 *   nettivarasto_tracking  legacy number    -- the pre-4.0 plugin, still present
 *                                              on historical orders
 *   _ogoship_tracking_status  shipment status -- Ecom WooCommerceConnector,
 *                                              InformTrackingChangeAsync
 *
 * The pre-4.0 plugin read only `nettivarasto_tracking` on the My Account order
 * page, so on any store using the server-side integration -- which is all of
 * them now -- customers saw no tracking at all. Reading all three keys, in the
 * order below, is the fix.
 */
final class Tracking {

	public const META_NUMBER        = 'ogoship_tracking';
	public const META_URL           = 'ogoship_tracking_url';
	public const META_LEGACY_NUMBER = 'nettivarasto_tracking';
	public const META_STATUS        = '_ogoship_tracking_status';
	public const META_EXCHANGE      = '_ogoship_exchange_order';
	public const META_ORIGIN_REF    = '_ogoship_origin_reference';

	/**
	 * The order this instance describes.
	 *
	 * @var \WC_Order
	 */
	private \WC_Order $order;

	/**
	 * Constructor.
	 *
	 * @param \WC_Order $order Order to read tracking from.
	 */
	public function __construct( \WC_Order $order ) {
		$this->order = $order;
	}

	/**
	 * Build from an order or order ID, or null when it is not an order.
	 *
	 * Uses wc_get_order() rather than `new WC_Order()` so refunds and other
	 * order types resolve through the datastore instead of throwing -- the
	 * pre-4.0 plugin got this wrong and broke under HPOS.
	 *
	 * @param \WC_Order|int $order Order or order ID.
	 */
	public static function for( $order ): ?self {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order );

		return $order instanceof \WC_Order ? new self( $order ) : null;
	}

	/**
	 * The tracking number, preferring the current key over the legacy one.
	 */
	public function number(): string {
		$number = trim( (string) $this->order->get_meta( self::META_NUMBER, true ) );

		if ( '' === $number ) {
			$number = trim( (string) $this->order->get_meta( self::META_LEGACY_NUMBER, true ) );
		}

		return $number;
	}

	/**
	 * The tracking URL, or '' when the carrier gave us only a number.
	 *
	 * The value arrives from a remote system and ends up inside an href, so
	 * the scheme is checked here and the output is escaped with esc_url() at
	 * the point of use. Anything that is not http(s) -- javascript:, data:,
	 * a bare tracking code accidentally stored in the URL field -- is dropped.
	 *
	 * Note: deliberately NOT wp_http_validate_url(). That function exists to
	 * make outbound server-side requests safe and rejects hosts that do not
	 * resolve or resolve to a private range, which would silently discard
	 * perfectly good carrier links whenever DNS is unavailable.
	 */
	public function url(): string {
		$url = trim( (string) $this->order->get_meta( self::META_URL, true ) );

		if ( '' === $url ) {
			return '';
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );

		if ( ! is_string( $scheme ) || ! in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) ) {
			return '';
		}

		// A scheme with no host is not a link anyone can follow.
		$host = wp_parse_url( $url, PHP_URL_HOST );

		return is_string( $host ) && '' !== $host ? $url : '';
	}

	/**
	 * The shipment status reported by OGOship, for example "Delivered".
	 */
	public function status(): string {
		return trim( (string) $this->order->get_meta( self::META_STATUS, true ) );
	}

	/**
	 * Whether OGOship created this order as a return exchange.
	 */
	public function is_exchange(): bool {
		return wc_string_to_bool( (string) $this->order->get_meta( self::META_EXCHANGE, true ) );
	}

	/**
	 * The original order reference an exchange order was created from.
	 */
	public function origin_reference(): string {
		return trim( (string) $this->order->get_meta( self::META_ORIGIN_REF, true ) );
	}

	/**
	 * Whether there is anything worth showing.
	 */
	public function has_tracking(): bool {
		return '' !== $this->number() || '' !== $this->url();
	}

	/**
	 * Label for the tracking link.
	 *
	 * Falls back to the URL when only a link was supplied, so the customer
	 * always sees something clickable rather than an empty anchor.
	 */
	public function link_text(): string {
		$number = $this->number();

		return '' !== $number ? $number : $this->url();
	}

	/**
	 * The order being described.
	 */
	public function order(): \WC_Order {
		return $this->order;
	}
}
