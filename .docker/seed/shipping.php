<?php
/**
 * A Finland shipping zone with the OGOship shipping method in it.
 *
 * Idempotent. Run via `wp eval-file /seed/shipping.php`.
 *
 * @package OGOship\WooCommerce\Dev
 */

defined( 'ABSPATH' ) || exit;

$zone = null;
foreach ( WC_Shipping_Zones::get_zones() as $existing ) {
	if ( 'Finland (OGOship)' === $existing['zone_name'] ) {
		$zone = new WC_Shipping_Zone( (int) $existing['id'] );
	}
}

if ( null === $zone ) {
	$zone = new WC_Shipping_Zone();
	$zone->set_zone_name( 'Finland (OGOship)' );
	$zone->add_location( 'FI', 'country' );
	$zone->save();
}

$has_method = false;
foreach ( $zone->get_shipping_methods() as $method ) {
	if ( 'ogoship' === $method->id ) {
		$has_method = true;
	}
}

if ( ! $has_method ) {
	$zone->add_shipping_method( 'ogoship' );
}

echo 'Shipping zone ' . (int) $zone->get_id() . " has the OGOship method.\n";
