<?php
/**
 * Route all outbound mail to the Mailpit container.
 *
 * Must-use plugin for the local dev store only -- never shipped with the
 * plugin. Without this WordPress hands mail to the container's non-existent
 * sendmail and the e2e email assertions have nothing to read.
 *
 * @package OGOship\WooCommerce\Dev
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'phpmailer_init',
	static function ( $phpmailer ) {
		$phpmailer->isSMTP();
		$phpmailer->Host       = 'mailpit';
		$phpmailer->Port       = 1025;
		$phpmailer->SMTPAuth   = false;
		$phpmailer->SMTPSecure = '';
		$phpmailer->SMTPAutoTLS = false;
	}
);
