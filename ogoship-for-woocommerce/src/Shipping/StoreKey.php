<?php
/**
 * The store's OGOship REST key, as the shipping method signs with it.
 *
 * @package OGOship\WooCommerce
 */

declare( strict_types=1 );

namespace OGOship\WooCommerce\Shipping;

defined( 'ABSPATH' ) || exit;

/**
 * The consumer secret and key ending of the REST key OGOship connected with.
 *
 * OGOship already holds this key from the one-click connect, so it doubles as the shipping method's
 * credential: the rate request is signed with the consumer secret and names the key by its last
 * characters. WooCommerce stores the consumer key only hashed, but the secret and `truncated_key`
 * in plain text, which is exactly what that needs. No second secret to store or rotate.
 */
final class StoreKey {

	private const CACHE_KEY   = 'rate_key';
	private const CACHE_GROUP = 'ogoship';

	/**
	 * Matches the key description WooCommerce builds from the app name OGOship sends to `wc-auth`
	 * ("OGOship - API (...)"), the same marker the connect screen looks for.
	 */
	private const APP_NAME = 'ogoship';

	/**
	 * Constructor.
	 *
	 * @param string $consumer_secret The key's consumer secret ("cs_...").
	 * @param string $key_ending      WooCommerce's `truncated_key`: the consumer key's last 7 characters.
	 */
	public function __construct(
		public readonly string $consumer_secret,
		public readonly string $key_ending
	) {}

	/**
	 * The newest OGOship key with write access, or null when the store is not connected.
	 *
	 * @remarks
	 * Reconnecting creates a new key and OGOship keeps only the newest, so the newest row is the one
	 * that verifies. Cached briefly: checkout recalculates shipping on every cart change.
	 */
	public static function current(): ?self {
		global $wpdb;

		$row = wp_cache_get( self::CACHE_KEY, self::CACHE_GROUP );

		if ( false === $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- no WooCommerce API covers the key table.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT consumer_secret, truncated_key FROM {$wpdb->prefix}woocommerce_api_keys
					WHERE description LIKE %s AND permissions IN ('read_write', 'write')
					ORDER BY key_id DESC LIMIT 1",
					'%' . $wpdb->esc_like( self::APP_NAME ) . '%'
				),
				ARRAY_A
			);
			$row = is_array( $row ) ? $row : array();

			wp_cache_set( self::CACHE_KEY, $row, self::CACHE_GROUP, MINUTE_IN_SECONDS );
		}

		return self::from_row( $row );
	}

	/**
	 * Build from a `woocommerce_api_keys` row.
	 *
	 * @param array<string, mixed> $row The row's consumer_secret and truncated_key.
	 */
	public static function from_row( array $row ): ?self {
		$secret = isset( $row['consumer_secret'] ) ? (string) $row['consumer_secret'] : '';
		$ending = isset( $row['truncated_key'] ) ? (string) $row['truncated_key'] : '';

		if ( '' === $secret || strlen( $ending ) < 4 ) {
			return null;
		}

		return new self( $secret, $ending );
	}
}
