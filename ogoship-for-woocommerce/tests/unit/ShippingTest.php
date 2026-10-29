<?php
/**
 * Unit tests for the OGOship shipping method's request, signature and answer handling.
 *
 * @package OGOship\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace OGOship\WooCommerce\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use OGOship\WooCommerce\Shipping\RateRequest;
use OGOship\WooCommerce\Shipping\RatesClient;
use OGOship\WooCommerce\Shipping\Shipping;
use OGOship\WooCommerce\Shipping\StoreKey;
use PHPUnit\Framework\TestCase;

/**
 * Covers what OGOship's rate endpoint relies on: the request fields, the signature, and which
 * rates of an answer reach the checkout.
 *
 * @covers \OGOship\WooCommerce\Shipping\RateRequest
 * @covers \OGOship\WooCommerce\Shipping\RatesClient
 * @covers \OGOship\WooCommerce\Shipping\Shipping
 * @covers \OGOship\WooCommerce\Shipping\StoreKey
 */
final class ShippingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) );
		// The store's weight unit is kilograms in these tests.
		Functions\when( 'wc_get_weight' )->alias( static fn( $weight, $unit ) => 'g' === $unit ? (float) $weight * 1000 : (float) $weight );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * A cart item for the package.
	 *
	 * @param float $kilograms      Weight of one.
	 * @param int   $quantity       How many.
	 * @param bool  $needs_shipping Whether the product ships.
	 * @return array<string, mixed>
	 */
	private function item( float $kilograms, int $quantity, bool $needs_shipping = true ): array {
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'needs_shipping' )->andReturn( $needs_shipping );
		$product->shouldReceive( 'get_weight' )->andReturn( (string) $kilograms );

		return array(
			'data'     => $product,
			'quantity' => $quantity,
		);
	}

	public function test_request_carries_destination_weight_and_value(): void {
		$package = array(
			'contents'      => array( $this->item( 0.4, 2 ), $this->item( 1.5, 1, false ), $this->item( 0.25, 1 ) ),
			'contents_cost' => 39.904,
			'destination'   => array(
				'country'  => 'fi',
				'postcode' => ' 00100 ',
				'city'     => 'Helsinki',
				'address'  => 'Mannerheimintie 1',
			),
		);

		$destination = RateRequest::destination( $package, self::finnish_postcodes() );
		$this->assertNotNull( $destination );
		$fields = RateRequest::build( $package, $destination, 3, 7, 'https://shop.example.com', '1a2b3c4', 'EUR' );

		$this->assertSame( 'https://shop.example.com', $fields['store'] );
		$this->assertSame( '1a2b3c4', $fields['key_ending'] );
		$this->assertSame( 3, $fields['zone_id'] );
		$this->assertSame( 7, $fields['instance_id'] );
		$this->assertSame( 'FI', $fields['destination']['country'] );
		$this->assertSame( '00100', $fields['destination']['postcode'] );
		// 2 × 400 g + 250 g; the virtual item does not count.
		$this->assertSame( 1050, $fields['contents_weight_grams'] );
		$this->assertSame( 39.9, $fields['contents_value'] );
		$this->assertSame( 'EUR', $fields['currency'] );
		$this->assertArrayNotHasKey( 'timestamp', $fields );
	}

	/**
	 * Stand-in for WC_Validation::is_postcode: five digits is a Finnish postcode.
	 *
	 * @return callable(string, string): bool
	 */
	private static function finnish_postcodes(): callable {
		return static fn( string $postcode, string $country ): bool => 'FI' === $country && 1 === preg_match( '/^\d{5}$/', $postcode );
	}

	/**
	 * The destination check for one address.
	 *
	 * @param array<string, string> $destination Package destination.
	 * @return array<string, string>|null
	 */
	private static function destination( array $destination ): ?array {
		return RateRequest::destination( array( 'destination' => $destination ), self::finnish_postcodes() );
	}

	public function test_a_half_filled_address_is_not_searched(): void {
		// Country only, a half-typed postcode, a one-letter city: WooCommerce recalculates on each.
		$this->assertNull( self::destination( array( 'country' => 'FI' ) ) );
		$this->assertNull(
			self::destination(
				array(
					'country' => 'FI',
					'postcode' => '001',
				)
			)
		);
		$this->assertNull(
			self::destination(
				array(
					'country' => 'FI',
					'city' => 'H',
				)
			)
		);
		$this->assertNull(
			self::destination(
				array(
					'postcode' => '00100',
					'city' => 'Helsinki',
				)
			)
		);
	}

	public function test_a_valid_postcode_or_a_city_is_enough(): void {
		$this->assertSame(
			'00100',
			self::destination(
				array(
					'country' => 'FI',
					'postcode' => '00100',
				)
			)['postcode'] ?? null
		);
		$this->assertSame(
			'Helsinki',
			self::destination(
				array(
					'country' => 'FI',
					'city' => 'Helsinki',
				)
			)['city'] ?? null
		);
	}

	public function test_an_invalid_postcode_is_dropped_so_the_city_still_searches(): void {
		$destination = self::destination(
			array(
				'country'  => 'FI',
				'postcode' => '0010',
				'city'     => 'Helsinki',
			)
		);

		$this->assertNotNull( $destination );
		$this->assertSame( '', $destination['postcode'] );
		$this->assertSame( 'Helsinki', $destination['city'] );
	}

	public function test_signature_matches_what_ogoship_verifies(): void {
		$body = '{"store":"https://shop.example.com","zone_id":1,"timestamp":1793188800}';

		// HMAC-SHA256 of the body with the consumer secret, base64: computed independently.
		$this->assertSame( 'W3GB0hE6/l1qiFnwsT98nwxK5Uzvsw5MSOBqks55G8E=', RatesClient::sign( $body, 'cs_0123456789abcdef' ) );
	}

	public function test_body_adds_the_timestamp_and_cache_key_ignores_it(): void {
		$fields = array(
			'store'   => 'https://shop.example.com',
			'zone_id' => 1,
		);

		$this->assertSame( '{"store":"https:\/\/shop.example.com","zone_id":1,"timestamp":1793188800}', RatesClient::body( $fields, 1793188800 ) );
		$this->assertSame( RatesClient::cache_key( $fields ), RatesClient::cache_key( $fields ) );
		$this->assertNotSame( RatesClient::cache_key( $fields ), RatesClient::cache_key( array( 'zone_id' => 2 ) + $fields ) );
	}

	public function test_parse_keeps_well_formed_rates_with_the_ogoship_code(): void {
		$rates = RatesClient::parse(
			array(
				'rates' => array(
					array(
						'id'          => 'ogo|POSTI_SP|123456',
						'label'       => 'Posti – K-market Etu-Töölö',
						'description' => 'Mannerheimintie 1, 00100 Helsinki',
						'cost'        => 5.9,
						'meta_data'   => array(
							'ogoship_rate'              => 'ogo|POSTI_SP|123456',
							'ogoship_pickup_point'      => '123456',
							'ogoship_pickup_point_name' => 'K-market Etu-Töölö',
						),
					),
					array(
						'id'        => 'ogo|POSTI_HOME',
						'label'     => 'Home delivery',
						'cost'      => '9.90',
						'meta_data' => array( 'ogoship_rate' => 'ogo|POSTI_HOME' ),
					),
					// No rate code: the order could not tell OGOship the delivery type.
					array(
						'id'    => 'x',
						'label' => 'X',
						'cost'  => 1,
					),
					// Negative or missing cost, missing label, duplicate id.
					array(
						'id'        => 'ogo|A',
						'label'     => 'A',
						'cost'      => -1,
						'meta_data' => array( 'ogoship_rate' => 'ogo|A' ),
					),
					array(
						'id'        => 'ogo|B',
						'cost'      => 1,
						'meta_data' => array( 'ogoship_rate' => 'ogo|B' ),
					),
					array(
						'id'        => 'ogo|POSTI_HOME',
						'label'     => 'Again',
						'cost'      => 1,
						'meta_data' => array( 'ogoship_rate' => 'ogo|POSTI_HOME' ),
					),
					'not a rate',
				),
			)
		);

		$this->assertSame( array( 'ogo|POSTI_SP|123456', 'ogo|POSTI_HOME' ), array_column( $rates, 'id' ) );
		$this->assertSame( 5.9, $rates[0]['cost'] );
		$this->assertSame( '123456', $rates[0]['meta_data']['ogoship_pickup_point'] );
		$this->assertSame( 9.9, $rates[1]['cost'] );
		$this->assertSame( '', $rates[1]['description'] );
	}

	public function test_parse_of_an_unexpected_answer_is_empty(): void {
		$this->assertSame( array(), RatesClient::parse( array() ) );
		$this->assertSame( array(), RatesClient::parse( array( 'rates' => 'nope' ) ) );
	}

	public function test_store_key_needs_a_secret_and_key_ending(): void {
		$key = StoreKey::from_row(
			array(
				'consumer_secret' => 'cs_abc',
				'truncated_key'   => '1a2b3c4',
			)
		);

		$this->assertNotNull( $key );
		$this->assertSame( 'cs_abc', $key->consumer_secret );
		$this->assertSame( '1a2b3c4', $key->key_ending );
		$this->assertNull( StoreKey::from_row( array() ) );
		$this->assertNull( StoreKey::from_row( array( 'consumer_secret' => 'cs_abc' ) ) );
	}

	public function test_ogoship_meta_is_hidden_from_order_displays(): void {
		$shipping  = new Shipping();
		$formatted = array(
			1 => (object) array( 'key' => 'ogoship_rate' ),
			2 => (object) array( 'key' => 'ogoship_pickup_point_name' ),
			3 => (object) array( 'key' => 'Items' ),
		);

		$this->assertSame( array( 3 ), array_keys( $shipping->hide_formatted_meta( $formatted ) ) );
		$this->assertContains( 'ogoship_rate', $shipping->hide_admin_meta( array( '_reduced_stock' ) ) );
		$this->assertContains( '_reduced_stock', $shipping->hide_admin_meta( array( '_reduced_stock' ) ) );
	}

	public function test_method_is_offered_in_the_zones(): void {
		$methods = ( new Shipping() )->add_method( array( 'flat_rate' => 'WC_Shipping_Flat_Rate' ) );

		$this->assertSame( 'OGOship\WooCommerce\Shipping\ShippingMethod', $methods['ogoship'] );
		$this->assertArrayHasKey( 'flat_rate', $methods );
	}
}
