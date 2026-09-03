<?php
/**
 * Unit tests for the product meta repository.
 *
 * @package OGOship\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace OGOship\WooCommerce\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use OGOship\WooCommerce\Product\Repository;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OGOship\WooCommerce\Product\Repository
 */
final class RepositoryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// The field definitions are wrapped in __(), so the suite needs the
		// translation functions to exist even though it asserts on key names.
		Functions\stubTranslationFunctions();

		Functions\when( 'wc_clean' )->alias(
			static fn( $value ) => is_string( $value ) ? trim( strip_tags( $value ) ) : $value
		);
		/*
		 * Mirrors wc_format_decimal's real semantics, which are easy to get
		 * wrong: it converts the *store's* configured decimal separator and
		 * strips everything else as a thousands separator. On a store using
		 * '.' as the decimal separator -- WooCommerce's default, regardless of
		 * currency -- "18,50" therefore means 1850, not 18.50.
		 *
		 * An earlier, naive stub that simply swapped ',' for '.' made this
		 * suite pass while the real behaviour differed.
		 */
		Functions\when( 'wc_format_decimal' )->alias(
			static function ( $value ) {
				$value = preg_replace( '/[^0-9.\-]/', '', (string) $value );
				return is_numeric( $value ) ? $value : '';
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * The key names are a wire contract with WooCommerceProductInfoProvider in
	 * both ECom and RapidWarehouse. If this test has to be updated, something
	 * has gone wrong: existing stores would silently stop syncing.
	 */
	public function test_meta_keys_match_the_server_side_contract(): void {
		self::assertSame(
			array(
				'_nettivarasto_eancode',
				'_nettivarasto_supplier_name',
				'_nettivarasto_supplier_code',
				'_nettivarasto_purchase_price',
				'_nettivarasto_group',
				'_nettivarasto_customsdescription',
				'_nettivarasto_countryoforigin',
				'_nettivarasto_hscode',
				'_nettivarasto_no_export',
			),
			array_keys( Repository::fields() )
		);
	}

	/**
	 * @dataProvider provide_prices
	 */
	public function test_price_sanitization( string $raw, string $expected ): void {
		self::assertSame( $expected, Repository::sanitize( 'price', $raw ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function provide_prices(): array {
		return array(
			'plain decimal' => array( '18.50', '18.50' ),
			'integer'       => array( '19', '19' ),
			// A comma is the store's thousands separator by default, so it is
			// stripped rather than treated as a decimal point. This matches
			// every other price field in WooCommerce.
			'comma'         => array( '18,50', '1850' ),
			'currency sign' => array( '18.50 EUR', '18.50' ),
			'not a number'  => array( 'about twenty', '' ),
			'empty'         => array( '', '' ),
		);
	}

	/**
	 * @dataProvider provide_countries
	 */
	public function test_country_sanitization( string $raw, string $expected ): void {
		self::assertSame( $expected, Repository::sanitize( 'country', $raw ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function provide_countries(): array {
		return array(
			'uppercase'   => array( 'FI', 'FI' ),
			'lowercase'   => array( 'fi', 'FI' ),
			'padded'      => array( '  fi  ', 'FI' ),
			'three chars' => array( 'FIN', '' ),
			'country name' => array( 'Finland', '' ),
			'empty'       => array( '', '' ),
		);
	}

	/**
	 * Only the literal 'yes' excludes a product.
	 *
	 * The 3.x plugin wrote 'no' unconditionally on every product save, so
	 * historical databases are full of 'no' rows. Treating anything
	 * non-'yes' as "included" is what keeps those products syncing.
	 */
	public function test_no_export_only_yes_excludes(): void {
		self::assertSame( 'yes', Repository::sanitize( 'yes_no', 'yes' ) );
		self::assertSame( 'yes', Repository::sanitize( 'yes_no', 'on' ) );
		self::assertSame( '', Repository::sanitize( 'yes_no', 'no' ) );
		self::assertSame( '', Repository::sanitize( 'yes_no', '' ) );
	}

	public function test_text_sanitization_strips_markup(): void {
		self::assertSame( 'Acme Oy', Repository::sanitize( 'text', '  <b>Acme Oy</b> ' ) );
	}

	/**
	 * A variation with no value of its own inherits the parent's.
	 */
	public function test_variation_inherits_from_parent(): void {
		$parent = Mockery::mock( 'WC_Product' );
		$parent->shouldReceive( 'get_meta' )->with( Repository::HS_CODE, true )->andReturn( '610910' );

		$variation = Mockery::mock( 'WC_Product_Variation' );
		$variation->shouldReceive( 'get_meta' )->with( Repository::HS_CODE, true )->andReturn( '' );
		$variation->shouldReceive( 'get_parent_id' )->andReturn( 12 );

		Functions\when( 'wc_get_product' )->justReturn( $parent );

		self::assertSame( '610910', Repository::get( $variation, Repository::HS_CODE ) );
	}

	public function test_variation_own_value_wins_over_parent(): void {
		$parent = Mockery::mock( 'WC_Product' );
		$parent->shouldReceive( 'get_meta' )->andReturn( '6412345678901' );

		$variation = Mockery::mock( 'WC_Product_Variation' );
		$variation->shouldReceive( 'get_meta' )->with( Repository::EAN_CODE, true )->andReturn( '6412345678999' );

		Functions\when( 'wc_get_product' )->justReturn( $parent );

		self::assertSame( '6412345678999', Repository::get( $variation, Repository::EAN_CODE ) );
	}

	/**
	 * get_own() must NOT inherit -- rendering an inherited value into a
	 * variation input would silently promote it to an explicit override the
	 * next time the form is saved.
	 */
	public function test_get_own_does_not_inherit(): void {
		$variation = Mockery::mock( 'WC_Product_Variation' );
		$variation->shouldReceive( 'get_meta' )->with( Repository::EAN_CODE, true )->andReturn( '' );

		self::assertSame( '', Repository::get_own( $variation, Repository::EAN_CODE ) );
	}

	/**
	 * Clearing a field deletes the meta rather than storing '', so variations
	 * fall back to the parent again and the server sees the key as absent.
	 */
	public function test_emptying_a_field_deletes_the_meta(): void {
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'delete_meta_data' )->once()->with( Repository::EAN_CODE );
		$product->shouldNotReceive( 'update_meta_data' );

		Repository::set( $product, Repository::EAN_CODE, '' );
	}

	public function test_setting_a_field_writes_the_sanitized_value(): void {
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'update_meta_data' )->once()->with( Repository::COUNTRY_OF_ORIGIN, 'FI' );

		Repository::set( $product, Repository::COUNTRY_OF_ORIGIN, ' fi ' );
	}

	public function test_unknown_keys_are_ignored(): void {
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldNotReceive( 'update_meta_data' );
		$product->shouldNotReceive( 'delete_meta_data' );

		Repository::set( $product, '_something_else', 'value' );
	}

	/**
	 * Group and the no-export flag are product-wide concepts, so they stay off
	 * the variation form.
	 */
	public function test_variation_keys_exclude_product_wide_fields(): void {
		$keys = Repository::variation_keys();

		self::assertContains( Repository::EAN_CODE, $keys );
		self::assertNotContains( Repository::GROUP, $keys );
		self::assertNotContains( Repository::NO_EXPORT, $keys );
	}
}
