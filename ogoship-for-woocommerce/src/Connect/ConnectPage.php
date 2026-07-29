<?php
/**
 * The "Connect to OGOship" screen.
 *
 * @package OGOship\WooCommerce
 */

declare( strict_types=1 );

namespace OGOship\WooCommerce\Connect;

use OGOship\WooCommerce\Support\ApiActivity;

defined( 'ABSPATH' ) || exit;

/**
 * One-click connect, started from inside WooCommerce.
 *
 * The merchant clicks Connect, approves the connection in this same admin, and is done — they never
 * generate or copy a REST key. All this class does is hand them off to OGOship with the store's own
 * URL; WooCommerce's built-in `wc-auth` handshake does the rest, and the credentials go straight
 * from WooCommerce to OGOship without passing through the browser.
 */
final class ConnectPage {

	private const MENU_SLUG    = 'ogoship-connect';
	private const START_ACTION = 'ogoship_start_connect';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::START_ACTION, array( $this, 'handle_start' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( \OGOship\WooCommerce\PLUGIN_FILE ), array( $this, 'add_plugin_action_link' ) );
	}

	/**
	 * Add the page under WooCommerce.
	 */
	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'OGOship', 'ogoship-for-woocommerce' ),
			__( 'OGOship', 'ogoship-for-woocommerce' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Surface the screen from the plugins list too, where merchants look first after installing.
	 *
	 * @param array<int, string> $links Existing action links.
	 * @return array<int, string>
	 */
	public function add_plugin_action_link( array $links ): array {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ),
				esc_html__( 'Connect', 'ogoship-for-woocommerce' )
			)
		);

		return $links;
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		$connected = self::looks_connected();

		echo '<div class="wrap ogoship-connect">';
		echo '<h1>' . esc_html__( 'OGOship', 'ogoship-for-woocommerce' ) . '</h1>';

		if ( $connected ) {
			$this->render_connected();
		} else {
			$this->render_disconnected();
		}

		echo '</div>';
	}

	/**
	 * The state before anything has been connected.
	 */
	private function render_disconnected(): void {
		printf(
			'<p>%s</p>',
			esc_html__(
				'Connect this store to OGOship so your orders are picked, packed and shipped from the warehouse.',
				'ogoship-for-woocommerce'
			)
		);

		printf(
			'<p>%s</p>',
			esc_html__(
				'You will be asked to sign in to OGOship and choose a warehouse, then to approve the connection here in your own store. There is no key to copy: WooCommerce creates it and hands it to OGOship directly.',
				'ogoship-for-woocommerce'
			)
		);

		if ( ! is_ssl() ) {
			// wc-auth refuses to hand over credentials unless the callback is https, and a store
			// served over plain http usually cannot be reached back either.
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html__(
					'This store is not served over HTTPS. WooCommerce will not release API credentials to an insecure connection, so the automatic setup cannot run. Enable HTTPS, or ask OGOship support to connect the store manually.',
					'ogoship-for-woocommerce'
				)
			);
		}

		printf(
			'<p><a href="%1$s" class="button button-primary button-hero"%3$s>%2$s</a></p>',
			esc_url( $this->start_url() ),
			esc_html__( 'Connect to OGOship', 'ogoship-for-woocommerce' ),
			is_ssl() ? '' : ' aria-disabled="true"'
		);
	}

	/**
	 * The state once OGOship is talking to the store.
	 */
	private function render_connected(): void {
		$latest = ApiActivity::latest();

		printf(
			'<div class="notice notice-success inline"><p><strong>%s</strong></p></div>',
			esc_html__( 'This store is connected to OGOship.', 'ogoship-for-woocommerce' )
		);

		echo '<table class="widefat striped" style="max-width:640px"><tbody>';

		if ( null !== $latest ) {
			printf(
				'<tr><td>%1$s</td><td>%2$s</td></tr>',
				esc_html__( 'Last contact from OGOship', 'ogoship-for-woocommerce' ),
				sprintf(
					/* translators: %s: human-readable time difference, e.g. "5 mins". */
					esc_html__( '%s ago', 'ogoship-for-woocommerce' ),
					esc_html( human_time_diff( $latest['time'] ) )
				)
			);
		}

		printf(
			'<tr><td>%1$s</td><td><code>%2$s</code></td></tr>',
			esc_html__( 'OGOship environment', 'ogoship-for-woocommerce' ),
			esc_html( Environment::name() )
		);

		echo '</tbody></table>';

		printf(
			'<p class="description">%s</p>',
			esc_html__(
				'Fulfillment settings live in OGOship. This screen only shows whether the connection is working — see WooCommerce → Status → OGOship for more detail.',
				'ogoship-for-woocommerce'
			)
		);

		printf(
			'<p><a href="%1$s" class="button">%2$s</a></p>',
			esc_url( $this->start_url() ),
			esc_html__( 'Reconnect', 'ogoship-for-woocommerce' )
		);
	}

	/**
	 * Nonce-protected URL that starts the handshake.
	 */
	private function start_url(): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::START_ACTION ),
			self::START_ACTION
		);
	}

	/**
	 * Send the merchant to OGOship to choose an account and warehouse.
	 */
	public function handle_start(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to connect this store.', 'ogoship-for-woocommerce' ) );
		}

		check_admin_referer( self::START_ACTION );

		$url = add_query_arg(
			array( 'store' => rawurlencode( home_url() ) ),
			Environment::api_base() . '/api/ecom/WooCommerceOAuth/app'
		);

		// Leaving wp-admin for OGOship, so wp_safe_redirect (same-host only) would refuse.
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- deliberate off-site redirect to the OGOship API.
		exit;
	}

	/**
	 * Best-effort "is OGOship reaching this store?".
	 *
	 * @remarks
	 * Deliberately inferred from observed inbound API calls rather than from a stored flag. OGOship
	 * connects by calling in, so its traffic is the only honest evidence — a flag would keep saying
	 * "connected" long after a merchant revoked the key.
	 */
	public static function looks_connected(): bool {
		return null !== ApiActivity::latest();
	}
}
