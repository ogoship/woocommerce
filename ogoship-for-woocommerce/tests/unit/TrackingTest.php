<?php
/**
 * Unit tests for the tracking resolver.
 *
 * @package OGOship\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace OGOship\WooCommerce\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use OGOship\WooCommerce\Order\Tracking;
use PHPUnit\Framework\TestCase;

/**
 * Covers the precedence rules and URL validation that the 3.x plugin got wrong.
 *
 * @covers \OGOship\WooCommerce\Order\Tracking
 */
final class TrackingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// wp_parse_url is a thin wrapper around parse_url for our purposes.
		Functions\when( 'wp_parse_url' )->alias(
			static fn( string $url, int $component = -1 ) => parse_url( $url, $component )
		);
		Functions\when( 'wc_string_to_bool' )->alias(
			static fn( $value ): bool => is_bool( $value )
				? $value
				: ( 'yes' === $value || 1 === $value || 'true' === $value || '1' === $value )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * Build a Tracking instance over an order stub with the given meta.
	 *
	 * @param array<string, string> $meta Meta key => value.
	 */
	private function tracking( array $meta ): Tracking {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_meta' )
			->andReturnUsing( static fn( string $key ) => $meta[ $key ] ?? '' );

		return new Tracking( $order );
	}

	public function test_number_prefers_current_key_over_legacy(): void {
		$tracking = $this->tracking(
			array(
				Tracking::META_NUMBER        => 'JJFI-NEW',
				Tracking::META_LEGACY_NUMBER => 'JJFI-OLD',
			)
		);

		self::assertSame( 'JJFI-NEW', $tracking->number() );
	}

	/**
	 * The regression that motivated the rewrite: the 3.x plugin read only the
	 * legacy key on the My Account page, so stores on the server-side
	 * integration -- which write ogoship_tracking -- showed nothing.
	 */
	public function test_number_reads_the_server_side_key(): void {
		$tracking = $this->tracking( array( Tracking::META_NUMBER => 'JJFI-SERVER' ) );

		self::assertSame( 'JJFI-SERVER', $tracking->number() );
		self::assertTrue( $tracking->has_tracking() );
	}

	/**
	 * ...and the other half: historical orders carry only the legacy key and
	 * must keep rendering.
	 */
	public function test_number_falls_back_to_legacy_key(): void {
		$tracking = $this->tracking( array( Tracking::META_LEGACY_NUMBER => 'JJFI-LEGACY' ) );

		self::assertSame( 'JJFI-LEGACY', $tracking->number() );
		self::assertTrue( $tracking->has_tracking() );
	}

	public function test_no_meta_means_no_tracking(): void {
		$tracking = $this->tracking( array() );

		self::assertSame( '', $tracking->number() );
		self::assertSame( '', $tracking->url() );
		self::assertFalse( $tracking->has_tracking() );
	}

	public function test_values_are_trimmed(): void {
		$tracking = $this->tracking( array( Tracking::META_NUMBER => "  JJFI-PADDED \n" ) );

		self::assertSame( 'JJFI-PADDED', $tracking->number() );
	}

	/**
	 * @dataProvider provide_urls
	 */
	public function test_url_accepts_only_followable_http_urls( string $stored, string $expected ): void {
		$tracking = $this->tracking( array( Tracking::META_URL => $stored ) );

		self::assertSame( $expected, $tracking->url() );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function provide_urls(): array {
		return array(
			'https'                 => array( 'https://track.example.com/X1', 'https://track.example.com/X1' ),
			'http'                  => array( 'http://track.example.com/X1', 'http://track.example.com/X1' ),
			// esc_url() would neutralise these on output anyway, but they must
			// never reach the template as a "link" in the first place.
			'javascript scheme'     => array( 'javascript:alert(1)', '' ),
			'data scheme'           => array( 'data:text/html,<script>', '' ),
			'scheme with no host'   => array( 'https://', '' ),
			// A tracking code accidentally stored in the URL field is not a link.
			'bare tracking code'    => array( 'JJFI00000000000012345', '' ),
			'empty'                 => array( '', '' ),
		);
	}

	/**
	 * A host that does not resolve is still a perfectly good tracking link.
	 * Guards against reintroducing wp_http_validate_url(), whose DNS and
	 * private-range checks silently discarded these.
	 */
	public function test_url_does_not_require_the_host_to_resolve(): void {
		$tracking = $this->tracking(
			array( Tracking::META_URL => 'https://tracking.invalid-tld-that-does-not-exist/X1' )
		);

		self::assertSame( 'https://tracking.invalid-tld-that-does-not-exist/X1', $tracking->url() );
	}

	public function test_url_alone_counts_as_tracking(): void {
		$tracking = $this->tracking( array( Tracking::META_URL => 'https://track.example.com/X1' ) );

		self::assertTrue( $tracking->has_tracking() );
		// With no number, the link needs some text; the URL is the fallback.
		self::assertSame( 'https://track.example.com/X1', $tracking->link_text() );
	}

	public function test_link_text_prefers_the_number(): void {
		$tracking = $this->tracking(
			array(
				Tracking::META_NUMBER => 'JJFI-1',
				Tracking::META_URL    => 'https://track.example.com/X1',
			)
		);

		self::assertSame( 'JJFI-1', $tracking->link_text() );
	}

	public function test_status_is_read_from_the_ecom_connector_key(): void {
		$tracking = $this->tracking( array( Tracking::META_STATUS => 'Delivered' ) );

		self::assertSame( 'Delivered', $tracking->status() );
	}

	public function test_exchange_order_markers(): void {
		$tracking = $this->tracking(
			array(
				Tracking::META_EXCHANGE   => 'yes',
				Tracking::META_ORIGIN_REF => '12345',
			)
		);

		self::assertTrue( $tracking->is_exchange() );
		self::assertSame( '12345', $tracking->origin_reference() );
	}

	public function test_plain_order_is_not_an_exchange(): void {
		self::assertFalse( $this->tracking( array() )->is_exchange() );
	}
}
