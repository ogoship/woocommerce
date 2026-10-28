<?php
/**
 * The OGOship shipping method.
 *
 * @package OGOship\WooCommerce
 */

declare( strict_types=1 );

namespace OGOship\WooCommerce\Shipping;

defined( 'ABSPATH' ) || exit;

/**
 * Pickup points (and home delivery) at checkout, priced by the rules the merchant keeps in myOGO.
 *
 * Added to a shipping zone like any other method. At checkout it asks OGOship for the rates of the
 * package and adds each answer as its own shipping rate: one per nearby pickup point, then the home
 * delivery rates. Being plain rates, they work the same in the classic and the block checkout, with
 * no frontend code. The chosen rate's meta carries OGOship's delivery type and pickup point onto
 * the order's shipping line.
 */
class ShippingMethod extends \WC_Shipping_Method {

	public const ID = 'ogoship';

	/**
	 * Constructor.
	 *
	 * @param int $instance_id Shipping method instance.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = self::ID;
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'OGOship', 'ogoship-for-woocommerce' );
		$this->method_description = __( 'Pickup points and home delivery from OGOship, with the delivery methods and prices set in myOGO (Integrations → this store → Pickup points at checkout).', 'ogoship-for-woocommerce' );
		$this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );

		$this->init();
	}

	/**
	 * Load the settings.
	 */
	public function init(): void {
		$this->instance_form_fields = array(
			'title'      => array(
				'title'       => __( 'Name in the zone list', 'ogoship-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Only shown here in the zone settings: at checkout each rate shows its own name from myOGO.', 'ogoship-for-woocommerce' ),
				'default'     => __( 'OGOship', 'ogoship-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'tax_status' => array(
				'title'   => __( 'Tax status', 'ogoship-for-woocommerce' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'default' => 'taxable',
				'options' => array(
					'taxable' => __( 'Taxable (prices in myOGO exclude tax)', 'ogoship-for-woocommerce' ),
					'none'    => _x( 'None', 'Tax status', 'ogoship-for-woocommerce' ),
				),
			),
		);

		$this->title      = (string) $this->get_option( 'title', __( 'OGOship', 'ogoship-for-woocommerce' ) );
		$this->tax_status = (string) $this->get_option( 'tax_status', 'taxable' );

		add_action(
			'woocommerce_update_options_shipping_' . $this->id,
			function (): void {
				$this->process_admin_options();
			}
		);
	}

	/**
	 * Add OGOship's rates for the package.
	 *
	 * @param array<string, mixed> $package The package.
	 */
	public function calculate_shipping( $package = array() ): void {
		$key = StoreKey::current();
		if ( null === $key ) {
			// Not connected (or the key was revoked): OGOship could not tell who is asking.
			return;
		}

		$destination = isset( $package['destination'] ) && is_array( $package['destination'] ) ? $package['destination'] : array();
		if ( empty( $destination['country'] ) ) {
			return;
		}

		$zone   = \WC_Shipping_Zones::get_zone_matching_package( $package );
		$fields = RateRequest::build(
			$package,
			(int) $zone->get_id(),
			(int) $this->instance_id,
			home_url(),
			$key->key_ending,
			get_woocommerce_currency()
		);

		foreach ( ( new RatesClient() )->rates( $fields, $key ) as $rate ) {
			$this->add_rate(
				array(
					'id'          => $this->get_rate_id( $rate['id'] ),
					'label'       => $rate['label'],
					'cost'        => $rate['cost'],
					'package'     => $package,
					// WooCommerce copies a rate's meta onto the order's shipping line.
					'meta_data'   => $rate['meta_data'],
					// Shown under the rate name since WooCommerce 9.2; ignored before.
					'description' => $rate['description'],
				)
			);
		}
	}
}
