<?php
/**
 * Asks OGOship for the checkout rates of a package.
 *
 * @package OGOship\WooCommerce
 */

declare( strict_types=1 );

namespace OGOship\WooCommerce\Shipping;

use OGOship\WooCommerce\Connect\ApiHost;

defined( 'ABSPATH' ) || exit;

/**
 * Signs a rate request, posts it to OGOship and returns the rates, cached briefly.
 *
 * Never throws and never blocks checkout for long: any failure (no answer, a refusal, an
 * unreadable answer) is logged and answers no rates, so the zone's other shipping methods still
 * show. Only a successful answer is cached.
 */
final class RatesClient {

	public const PATH = '/api/ecom/WooCommerceCheckoutRates/rates';

	public const SIGNATURE_HEADER = 'X-OGOship-Signature';

	/**
	 * WooCommerce recalculates shipping on every cart and checkout update; the pickup points near an
	 * address do not change that fast.
	 */
	private const CACHE_FOR = 5 * MINUTE_IN_SECONDS;

	/**
	 * OGOship gives its pickup point lookup 4 s and then answers with home-delivery rates.
	 */
	private const TIMEOUT = 8;

	/**
	 * The rates for a request built by {@see RateRequest::build()}.
	 *
	 * @param array<string, mixed> $fields The request fields, without timestamp.
	 * @param StoreKey             $key    The key to sign with.
	 * @return list<array{id: string, label: string, description: string, cost: float, meta_data: array<string, string>}>
	 */
	public function rates( array $fields, StoreKey $key ): array {
		$cache_key = self::cache_key( $fields );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			/** The cache only ever holds a parsed answer. @var list<array{id: string, label: string, description: string, cost: float, meta_data: array<string, string>}> $cached */
			return $cached;
		}

		$body     = self::body( $fields, time() );
		$response = wp_remote_post(
			ApiHost::base() . self::PATH,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Content-Type'         => 'application/json',
					self::SIGNATURE_HEADER => self::sign( $body, $key->consumer_secret ),
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			self::log( 'OGOship rates request failed: ' . $response->get_error_message() );
			return array();
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			self::log(
				401 === $status
					? 'OGOship refused the rates request (401): reconnect the store to OGOship if this persists.'
					: "OGOship rates request answered {$status}."
			);
			return array();
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$rates   = self::parse( is_array( $decoded ) ? $decoded : array() );

		set_transient( $cache_key, $rates, self::CACHE_FOR );

		return $rates;
	}

	/**
	 * The exact bytes sent and signed.
	 *
	 * @param array<string, mixed> $fields    The request fields.
	 * @param int                  $timestamp Unix seconds; OGOship refuses more than five minutes of skew.
	 */
	public static function body( array $fields, int $timestamp ): string {
		$fields['timestamp'] = $timestamp;
		return (string) wp_json_encode( $fields );
	}

	/**
	 * Base64 HMAC-SHA256 of the body with the consumer secret: what OGOship verifies.
	 *
	 * @param string $body   The request body.
	 * @param string $secret The consumer secret.
	 */
	public static function sign( string $body, string $secret ): string {
		return base64_encode( hash_hmac( 'sha256', $body, $secret, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the signature header is base64 by contract.
	}

	/**
	 * Transient key for a request: the same cart to the same address in the same zone.
	 *
	 * @param array<string, mixed> $fields The request fields, without timestamp.
	 */
	public static function cache_key( array $fields ): string {
		return 'ogoship_rates_' . md5( (string) wp_json_encode( $fields ) );
	}

	/**
	 * The well-formed rates of an answer. A rate without an id, a label or OGOship's rate code is
	 * dropped: without the code the order could not tell OGOship which delivery it is.
	 *
	 * @param array<mixed> $answer The decoded answer.
	 * @return list<array{id: string, label: string, description: string, cost: float, meta_data: array<string, string>}>
	 */
	public static function parse( array $answer ): array {
		$rates = isset( $answer['rates'] ) && is_array( $answer['rates'] ) ? $answer['rates'] : array();
		$out   = array();

		foreach ( $rates as $rate ) {
			$parsed = is_array( $rate ) ? self::parse_rate( $rate ) : null;
			if ( null !== $parsed && ! isset( $out[ $parsed['id'] ] ) ) {
				$out[ $parsed['id'] ] = $parsed;
			}
		}

		return array_values( $out );
	}

	/**
	 * One rate of an answer, or null when it is not usable.
	 *
	 * @param array<mixed> $rate The rate as decoded.
	 * @return array{id: string, label: string, description: string, cost: float, meta_data: array<string, string>}|null
	 */
	private static function parse_rate( array $rate ): ?array {
		$id    = isset( $rate['id'] ) && is_string( $rate['id'] ) ? trim( $rate['id'] ) : '';
		$label = isset( $rate['label'] ) && is_string( $rate['label'] ) ? trim( $rate['label'] ) : '';
		$cost  = isset( $rate['cost'] ) && is_numeric( $rate['cost'] ) ? (float) $rate['cost'] : -1.0;
		$meta  = array();

		foreach ( isset( $rate['meta_data'] ) && is_array( $rate['meta_data'] ) ? $rate['meta_data'] : array() as $key => $value ) {
			if ( is_string( $key ) && is_scalar( $value ) ) {
				$meta[ $key ] = (string) $value;
			}
		}

		if ( '' === $id || '' === $label || $cost < 0 || ! str_starts_with( $meta[ Shipping::RATE_META ] ?? '', 'ogo|' ) ) {
			return null;
		}

		return array(
			'id'          => $id,
			'label'       => $label,
			'description' => isset( $rate['description'] ) && is_string( $rate['description'] ) ? trim( $rate['description'] ) : '',
			'cost'        => $cost,
			'meta_data'   => $meta,
		);
	}

	/**
	 * Log to WooCommerce → Status → Logs, source "ogoship".
	 *
	 * @param string $message What went wrong.
	 */
	private static function log( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( $message, array( 'source' => 'ogoship' ) );
		}
	}
}
