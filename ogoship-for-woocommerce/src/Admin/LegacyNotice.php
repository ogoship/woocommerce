<?php
/**
 * Notice shown while the pre-4.0 OGOship plugin is still active.
 *
 * @package OGOship\WooCommerce
 */

declare( strict_types=1 );

namespace OGOship\WooCommerce\Admin;

use OGOship\WooCommerce\Connect\ConnectPage;
use const OGOship\WooCommerce\LEGACY_PLUGIN;

defined( 'ABSPATH' ) || exit;

/**
 * Asks the merchant to deactivate the old plugin, and offers to do it.
 *
 * The two plugins own the same product meta keys and both render tracking, so
 * running them together produces a duplicated product tab and a duplicated
 * tracking block. Rather than guess, this plugin stands down (see
 * Plugin::boot) until the old one is gone.
 *
 * No data migration is involved: the meta keys are identical, so deactivating
 * the old plugin and letting this one take over is lossless.
 */
final class LegacyNotice {

	private const ACTION = 'ogoship_deactivate_legacy';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_deactivate' ) );
	}

	/**
	 * Render the notice.
	 *
	 * Only on our own Connect screen: the merchant meets it where they went to
	 * set the plugin up, rather than on every admin page they happen to open.
	 */
	public function render(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen || 'woocommerce_page_' . ConnectPage::MENU_SLUG !== $screen->id ) {
			return;
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION ),
			self::ACTION
		);

		echo '<div class="notice notice-warning">';
		printf(
			'<p><strong>%s</strong></p>',
			esc_html__( 'OGOship for WooCommerce is waiting for the old plugin to be removed.', 'ogoship-for-woocommerce' )
		);
		printf(
			'<p>%s</p>',
			esc_html__(
				'The previous "OGOship API for WooCommerce" plugin is still active. Both plugins manage the same product fields and both show tracking, so this one is holding back to avoid duplicate settings. Your product data is shared between them and nothing will be lost.',
				'ogoship-for-woocommerce'
			)
		);
		printf(
			'<p><a href="%1$s" class="button button-primary">%2$s</a></p>',
			esc_url( $url ),
			esc_html__( 'Deactivate the old OGOship plugin', 'ogoship-for-woocommerce' )
		);
		echo '</div>';
	}

	/**
	 * Deactivate the legacy plugin and return to where the merchant was.
	 */
	public function handle_deactivate(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to deactivate plugins.', 'ogoship-for-woocommerce' ) );
		}

		check_admin_referer( self::ACTION );

		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		deactivate_plugins( LEGACY_PLUGIN );

		// Leave nothing of the old plugin's hourly poll behind: the event is
		// stored in the cron option and would otherwise linger forever.
		wp_clear_scheduled_hook( 'get_latest_changes_hook' );

		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}
}
