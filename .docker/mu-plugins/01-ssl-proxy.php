<?php
/**
 * Tell WordPress it is being served over TLS by the Caddy proxy.
 *
 * Must-use plugin for the local dev store only -- never shipped with the
 * plugin. Production stores behind a load balancer need the same three lines;
 * this is the standard reverse-proxy setup, not a workaround.
 *
 * Without it is_ssl() stays false, and WooCommerce then refuses to
 * authenticate REST consumer keys (WC_REST_Authentication::authenticate),
 * which is how OGOship talks to the store.
 *
 * @package OGOship\WooCommerce\Dev
 */

defined( 'ABSPATH' ) || exit;

if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === strtolower( (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) {
	$_SERVER['HTTPS'] = 'on';
}
