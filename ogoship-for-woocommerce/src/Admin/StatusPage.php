<?php
/**
 * OGOship connection status panel.
 *
 * @package OGOship\WooCommerce
 */

declare( strict_types=1 );

namespace OGOship\WooCommerce\Admin;

use OGOship\WooCommerce\Order\Tracking;
use OGOship\WooCommerce\Plugin;
use OGOship\WooCommerce\Rest\InfoController;
use OGOship\WooCommerce\Support\ApiActivity;

use const OGOship\WooCommerce\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Adds WooCommerce -> Status -> OGOship.
 *
 * Read-only, and stores no credentials. It exists to answer, without a support
 * ticket, the two questions merchants actually ask: "is OGOship connected?"
 * and "why has this order not shipped?"
 */
final class StatusPage {

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'woocommerce_admin_status_tabs', array( $this, 'add_tab' ) );
		add_action( 'woocommerce_admin_status_content_ogoship', array( $this, 'render' ) );
	}

	/**
	 * Add the tab.
	 *
	 * @param array<string, string> $tabs Existing status tabs.
	 * @return array<string, string>
	 */
	public function add_tab( array $tabs ): array {
		$tabs['ogoship'] = __( 'OGOship', 'ogoship-for-woocommerce' );

		return $tabs;
	}

	/**
	 * Render the panel.
	 */
	public function render(): void {
		echo '<h2>' . esc_html__( 'OGOship connection', 'ogoship-for-woocommerce' ) . '</h2>';

		printf(
			'<p>%s</p>',
			esc_html__(
				'OGOship connects by calling this store, not the other way round. Everything below is observed from incoming requests, so it reflects what OGOship has actually done rather than what it is configured to do.',
				'ogoship-for-woocommerce'
			)
		);

		$this->render_activity();
		$this->render_fulfillment();
		$this->render_environment();
	}

	/**
	 * Recent inbound API activity.
	 */
	private function render_activity(): void {
		$latest  = ApiActivity::latest();
		$entries = ApiActivity::entries();

		echo '<table class="wc_status_table widefat" cellspacing="0"><tbody>';

		echo '<tr><th colspan="2"><strong>' . esc_html__( 'API activity', 'ogoship-for-woocommerce' ) . '</strong></th></tr>';

		if ( null === $latest ) {
			$this->row(
				__( 'Last request', 'ogoship-for-woocommerce' ),
				esc_html__( 'No API requests recorded yet.', 'ogoship-for-woocommerce' )
				. '<br><span class="description">'
				. esc_html__( 'If OGOship should already be connected, check that the REST API key has not been revoked in WooCommerce > Settings > Advanced > REST API.', 'ogoship-for-woocommerce' )
				. '</span>'
			);
		} else {
			$user = get_userdata( $latest['user'] );

			$this->row(
				__( 'Last request', 'ogoship-for-woocommerce' ),
				sprintf(
					/* translators: 1: human time difference, 2: HTTP method, 3: REST route, 4: HTTP status code. */
					esc_html__( '%1$s ago &mdash; %2$s %3$s (HTTP %4$d)', 'ogoship-for-woocommerce' ),
					esc_html( human_time_diff( $latest['time'] ) ),
					esc_html( $latest['method'] ),
					'<code>' . esc_html( $latest['route'] ) . '</code>',
					(int) $latest['status']
				)
			);

			$this->row(
				__( 'Authenticated as', 'ogoship-for-woocommerce' ),
				$user ? esc_html( $user->user_login ) : esc_html__( 'Unknown user', 'ogoship-for-woocommerce' )
			);

			$this->row(
				__( 'Requests recorded', 'ogoship-for-woocommerce' ),
				esc_html( (string) count( $entries ) )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * Evidence that fulfillment data is flowing back.
	 */
	private function render_fulfillment(): void {
		echo '<table class="wc_status_table widefat" cellspacing="0"><tbody>';
		echo '<tr><th colspan="2"><strong>' . esc_html__( 'Fulfillment', 'ogoship-for-woocommerce' ) . '</strong></th></tr>';

		$recent = wc_get_orders(
			array(
				'limit'        => 1,
				'orderby'      => 'date',
				'order'        => 'DESC',
				'meta_key'     => Tracking::META_NUMBER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare' => 'EXISTS',
				'return'       => 'objects',
			)
		);

		if ( empty( $recent ) ) {
			$this->row(
				__( 'Last tracked shipment', 'ogoship-for-woocommerce' ),
				esc_html__( 'No order has received tracking from OGOship yet.', 'ogoship-for-woocommerce' )
			);
		} else {
			$order    = $recent[0];
			$tracking = new Tracking( $order );
			$date     = $order->get_date_modified();

			$this->row(
				__( 'Last tracked shipment', 'ogoship-for-woocommerce' ),
				sprintf(
					'<a href="%1$s">#%2$s</a> &mdash; <code>%3$s</code>%4$s',
					esc_url( $order->get_edit_order_url() ),
					esc_html( (string) $order->get_order_number() ),
					esc_html( $tracking->number() ),
					$date ? ' &mdash; ' . esc_html( $date->date_i18n( get_option( 'date_format' ) ) ) : ''
				)
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * Environment facts support always ends up asking for.
	 */
	private function render_environment(): void {
		echo '<table class="wc_status_table widefat" cellspacing="0"><tbody>';
		echo '<tr><th colspan="2"><strong>' . esc_html__( 'Environment', 'ogoship-for-woocommerce' ) . '</strong></th></tr>';

		$this->row( __( 'Plugin version', 'ogoship-for-woocommerce' ), esc_html( VERSION ) );

		$this->row(
			__( 'Capabilities reported', 'ogoship-for-woocommerce' ),
			'<code>' . esc_html( implode( ', ', InfoController::capabilities() ) ) . '</code>'
		);

		$this->row(
			__( 'Info endpoint', 'ogoship-for-woocommerce' ),
			'<code>' . esc_html( rest_url( InfoController::NAMESPACE_ . '/info' ) ) . '</code>'
		);

		$this->row(
			__( 'Old OGOship plugin', 'ogoship-for-woocommerce' ),
			Plugin::legacy_plugin_active()
				? '<mark class="error">' . esc_html__( 'Still active &mdash; deactivate it so this plugin can take over.', 'ogoship-for-woocommerce' ) . '</mark>'
				: '<mark class="yes">' . esc_html__( 'Not active', 'ogoship-for-woocommerce' ) . '</mark>'
		);

		echo '</tbody></table>';
	}

	/**
	 * Render one label/value row.
	 *
	 * @param string $label Row label, plain text.
	 * @param string $value Row value, already escaped by the caller.
	 */
	private function row( string $label, string $value ): void {
		printf(
			'<tr><td>%1$s</td><td>%2$s</td></tr>',
			esc_html( $label ),
			wp_kses_post( $value )
		);
	}
}
