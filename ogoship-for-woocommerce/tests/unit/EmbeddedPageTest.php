<?php
/**
 * Unit tests for the session token of the OGOship page on the connect screen.
 *
 * @package OGOship\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace OGOship\WooCommerce\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OGOship\WooCommerce\Connect\EmbeddedPage;
use OGOship\WooCommerce\Shipping\StoreKey;
use PHPUnit\Framework\TestCase;

/**
 * The token OGOship checks: its exact bytes, and the origin the frame is told about.
 *
 * @covers \OGOship\WooCommerce\Connect\EmbeddedPage
 */
final class EmbeddedPageTest extends TestCase {

	private const SECRET = 'cs_0123456789abcdef0123456789abcdef01234567';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) );
		Functions\when( 'wp_parse_url' )->alias( static fn( $url ) => parse_url( $url ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_token_matches_the_format_ogoship_checks(): void {
		// The same vector is accepted by Ecom's WooSessionTokenTests.
		$token = EmbeddedPage::session_token( new StoreKey( self::SECRET, 'b1c2d3' ), 'https://shop.example.com', 7, 1800000000 );

		$this->assertSame(
			'v1.eyJzdG9yZSI6Imh0dHBzOlwvXC9zaG9wLmV4YW1wbGUuY29tIiwia2V5IjoiYjFjMmQzIiwidWlkIjo3LCJpYXQiOjE4MDAwMDAwMDAsImV4cCI6MTgwMDAwMDEyMH0.3JqGYlX_h9gxEtNg3UxszwH0xlx76C8DBKPIyRw-HX8',
			$token
		);
	}

	public function test_token_payload_lives_two_minutes(): void {
		$token   = EmbeddedPage::session_token( new StoreKey( self::SECRET, 'b1c2d3' ), 'https://shop.example.com', 7, 1000 );
		$parts   = explode( '.', $token );
		$payload = json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reading the token's payload.

		$this->assertCount( 3, $parts );
		$this->assertSame( 1000, $payload['iat'] );
		$this->assertSame( 1000 + EmbeddedPage::TOKEN_TTL, $payload['exp'] );
		$this->assertStringNotContainsString( '=', $token );
		$this->assertStringNotContainsString( self::SECRET, $token );
	}

	public function test_origin_of(): void {
		$this->assertSame( 'https://shop.example.com', EmbeddedPage::origin_of( 'https://Shop.Example.com/wp-admin/' ) );
		$this->assertSame( 'http://localhost:8080', EmbeddedPage::origin_of( 'http://localhost:8080/wp-admin/admin.php' ) );
		$this->assertNull( EmbeddedPage::origin_of( '/wp-admin/' ) );
	}
}
