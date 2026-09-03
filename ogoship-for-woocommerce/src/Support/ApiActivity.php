<?php
/**
 * Record inbound OGOship API activity.
 *
 * @package OGOship\WooCommerce
 */

declare( strict_types=1 );

namespace OGOship\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a short log of REST requests made with a WooCommerce API key.
 *
 * OGOship integrates by calling this store's `wc/v3` endpoints with a consumer
 * key -- there is no outbound connection from the shop. That makes "is OGOship
 * connected?" surprisingly hard for a merchant to answer, because nothing in
 * WooCommerce surfaces it. This class watches the requests as they land, so
 * the status page can say when OGOship last called and what it asked for.
 *
 * Deliberately cheap: a bounded ring buffer in a single non-autoloaded option,
 * written at most once per authenticated REST request.
 */
final class ApiActivity {

	public const OPTION = 'ogoship_api_activity';

	/**
	 * How many entries to keep. Enough to see a sync pattern, small enough
	 * that the option stays a few kilobytes.
	 */
	private const LIMIT = 20;

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'rest_post_dispatch', array( $this, 'record' ), 10, 3 );

		// Let our own namespace authenticate with WooCommerce consumer keys.
		add_filter( 'woocommerce_rest_is_request_to_rest_api', array( $this, 'claim_namespace' ) );
	}

	/**
	 * Tell WooCommerce to run its key authentication for our namespace too.
	 *
	 * WooCommerce only applies consumer-key auth to routes it recognises as
	 * its own. Extending that check is the documented way for a companion
	 * plugin to reuse the merchant's existing credentials -- the same
	 * mechanism the Shipment Tracking plugin uses for its
	 * `wc-shipment-tracking/v3` namespace.
	 *
	 * @param bool $is_request_to_rest_api WooCommerce's own verdict.
	 */
	public function claim_namespace( $is_request_to_rest_api ): bool {
		if ( $is_request_to_rest_api ) {
			return true;
		}

		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		$request_uri = esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) );
		$rest_prefix = trailingslashit( rest_get_url_prefix() );

		return false !== strpos( $request_uri, $rest_prefix . 'ogoship/' );
	}

	/**
	 * Record a dispatched REST request if it looks like OGOship.
	 *
	 * @param \WP_HTTP_Response $response Response about to be sent.
	 * @param \WP_REST_Server   $server   REST server instance.
	 * @param \WP_REST_Request  $request  The request.
	 * @return \WP_HTTP_Response
	 */
	public function record( $response, $server, $request ) {
		if ( ! $request instanceof \WP_REST_Request ) {
			return $response;
		}

		$route = (string) $request->get_route();

		if ( ! $this->is_watched_route( $route ) ) {
			return $response;
		}

		// Only key-authenticated traffic is interesting. A logged-out request
		// that 401s tells us nothing, and admin-ajax browsing by the merchant
		// would drown out the signal.
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return $response;
		}

		$status = $response instanceof \WP_HTTP_Response ? (int) $response->get_status() : 0;

		$this->push(
			array(
				'time'   => time(),
				'route'  => $route,
				'method' => (string) $request->get_method(),
				'status' => $status,
				'user'   => $user_id,
			)
		);

		return $response;
	}

	/**
	 * Whether a route counts as OGOship traffic.
	 *
	 * @param string $route REST route, for example /wc/v3/orders.
	 */
	private function is_watched_route( string $route ): bool {
		return str_starts_with( $route, '/wc/v3/' )
			|| str_starts_with( $route, '/ogoship/' )
			|| str_starts_with( $route, '/wc-shipment-tracking/' );
	}

	/**
	 * Append an entry, trimming to the ring-buffer limit.
	 *
	 * @param array{time:int,route:string,method:string,status:int,user:int} $entry Entry to store.
	 */
	private function push( array $entry ): void {
		$log = self::entries();

		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, self::LIMIT );

		// Not autoloaded: this is read on one admin screen, never on the front end.
		update_option( self::OPTION, $log, false );
	}

	/**
	 * The recorded entries, newest first.
	 *
	 * @return list<array{time:int,route:string,method:string,status:int,user:int}>
	 */
	public static function entries(): array {
		$log = get_option( self::OPTION, array() );

		return is_array( $log ) ? array_values( $log ) : array();
	}

	/**
	 * The most recent entry, or null when nothing has been recorded.
	 *
	 * @return array{time:int,route:string,method:string,status:int,user:int}|null
	 */
	public static function latest(): ?array {
		$log = self::entries();

		return $log[0] ?? null;
	}
}
