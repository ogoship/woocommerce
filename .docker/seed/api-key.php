<?php
/**
 * Create (or reuse) a WooCommerce REST API key for the simulated OGOship server.
 *
 * WooCommerce stores only a hash of the consumer key, so the plaintext is
 * printed exactly once, at creation. Run via `wp eval-file /seed/api-key.php`.
 *
 * @package OGOship\WooCommerce\Dev
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;

$description = 'OGOship (dev)';
$user_id     = 1;

$existing = $wpdb->get_var(
	$wpdb->prepare(
		"SELECT key_id FROM {$wpdb->prefix}woocommerce_api_keys WHERE description = %s",
		$description
	)
);

if ( $existing ) {
	// The plaintext key is unrecoverable, so re-issue rather than leave the
	// caller without usable credentials.
	$wpdb->delete( $wpdb->prefix . 'woocommerce_api_keys', array( 'key_id' => $existing ), array( '%d' ) );
}

$consumer_key    = 'ck_' . wc_rand_hash();
$consumer_secret = 'cs_' . wc_rand_hash();

$wpdb->insert(
	$wpdb->prefix . 'woocommerce_api_keys',
	array(
		'user_id'         => $user_id,
		'description'     => $description,
		'permissions'     => 'read_write',
		'consumer_key'    => wc_api_hash( $consumer_key ),
		'consumer_secret' => $consumer_secret,
		'truncated_key'   => substr( $consumer_key, -7 ),
	),
	array( '%d', '%s', '%s', '%s', '%s', '%s' )
);

// Printed to stdout so bin/setup.sh can capture it into .docker/.api-keys.
echo "WC_CONSUMER_KEY={$consumer_key}\n";
echo "WC_CONSUMER_SECRET={$consumer_secret}\n";
