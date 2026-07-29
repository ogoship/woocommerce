<?php
/**
 * Keep guest order-confirmation pages readable on the dev store.
 *
 * Must-use plugin for the local dev store only -- never shipped with the
 * plugin, and never appropriate on a real store.
 *
 * WooCommerce shows a guest only the order *status* block on the
 * order-received page once the order is more than ten minutes old, and asks
 * them to confirm their email address before revealing the rest. That is
 * correct behaviour for a real shop, but it means a seeded fixture order stops
 * rendering its details shortly after setup.sh created it -- and the tracking
 * block lives in exactly the part that gets hidden.
 *
 * Widening the grace period keeps the fixtures usable without touching the
 * plugin's own logic or weakening anything in production.
 *
 * @package OGOship\WooCommerce\Dev
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'woocommerce_order_email_verification_grace_period',
	static fn(): int => YEAR_IN_SECONDS
);

// Belt and braces: some paths consult this flag rather than the grace period.
add_filter( 'woocommerce_order_email_verification_required', '__return_false' );
