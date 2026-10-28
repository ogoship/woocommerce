<?php
/**
 * Stand in for OGOship's checkout rates endpoint on the dev store.
 *
 * Must-use plugin for the local dev store only -- never shipped with the
 * plugin. Off unless the `ogoship_dev_rates_stub` option is "yes" (the e2e
 * suite turns it on), so a developer pointing OGOSHIP_API_BASE at a real Ecom
 * still reaches it.
 *
 * Answers the OGOship shipping method's `POST /api/ecom/WooCommerceCheckoutRates/rates`
 * the way Ecom does: refuses a request whose signature does not verify with
 * the store's OGOship key (401), and otherwise answers two pickup points and a
 * home delivery rate for Finland, nothing elsewhere. The last request it
 * accepted is kept in `ogoship_dev_rates_last_request` for the tests to read.
 *
 * @package OGOship\WooCommerce\Dev
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'pre_http_request',
	static function ( $preempt, array $args, string $url ) {
		if ( 'yes' !== get_option( 'ogoship_dev_rates_stub' ) || ! str_ends_with( wp_parse_url( $url, PHP_URL_PATH ) ?? '', '/api/ecom/WooCommerceCheckoutRates/rates' ) ) {
			return $preempt;
		}

		global $wpdb;
		$body   = (string) ( $args['body'] ?? '' );
		$given  = (string) ( $args['headers']['X-OGOship-Signature'] ?? '' );
		$secret = (string) $wpdb->get_var(
			"SELECT consumer_secret FROM {$wpdb->prefix}woocommerce_api_keys WHERE description LIKE '%ogoship%' ORDER BY key_id DESC LIMIT 1"
		);

		$expected = base64_encode( hash_hmac( 'sha256', $body, $secret, true ) );
		$request  = json_decode( $body, true );

		if ( '' === $secret || ! hash_equals( $expected, $given ) || ! is_array( $request ) || abs( time() - (int) ( $request['timestamp'] ?? 0 ) ) > 300 ) {
			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array( 'code' => 401, 'message' => 'Unauthorized' ),
				'cookies'  => array(),
			);
		}

		update_option( 'ogoship_dev_rates_last_request', $request, false );

		$rates = array();
		if ( 'FI' === ( $request['destination']['country'] ?? '' ) ) {
			$point = static fn( string $code, string $name, string $address ): array => array(
				'id'          => "ogo|POSTI_SP|{$code}",
				'label'       => "Posti – {$name}",
				'description' => "{$address}, 00100 Helsinki. 2–4 business days",
				'cost'        => 5.9,
				'meta_data'   => array(
					'ogoship_rate'              => "ogo|POSTI_SP|{$code}",
					'ogoship_pickup_point'      => $code,
					'ogoship_pickup_point_name' => $name,
				),
			);

			$rates = array(
				$point( '904050', 'K-market Etu-Töölö', 'Runeberginkatu 1' ),
				$point( '904051', 'Postin Automaatti, Lidl', 'Mannerheimintie 5' ),
				array(
					'id'          => 'ogo|POSTI_HOME',
					'label'       => 'Home delivery',
					'description' => '',
					'cost'        => 9.9,
					'meta_data'   => array( 'ogoship_rate' => 'ogo|POSTI_HOME' ),
				),
			);
		}

		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'rates' => $rates ) ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
		);
	},
	10,
	3
);
