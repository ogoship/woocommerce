<?php
/**
 * OGOship product data tab on the product edit screen.
 *
 * @package OGOship\WooCommerce
 */

declare( strict_types=1 );

namespace OGOship\WooCommerce\Product;

use OGOship\WooCommerce\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Adds an "OGOship" tab to the product data box and saves its fields.
 */
final class Fields {

	private const NONCE_ACTION = 'ogoship_save_product_meta';
	private const NONCE_NAME   = 'ogoship_product_meta_nonce';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add the tab.
	 *
	 * @param array<string, array<string, mixed>> $tabs Existing tabs.
	 * @return array<string, array<string, mixed>>
	 */
	public function add_tab( array $tabs ): array {
		$tabs['ogoship'] = array(
			'label'    => __( 'OGOship', 'ogoship-for-woocommerce' ),
			'target'   => 'ogoship_product_data',
			'class'    => array( 'show_if_simple', 'show_if_variable' ),
			'priority' => 65,
		);

		return $tabs;
	}

	/**
	 * Render the panel contents.
	 */
	public function render_panel(): void {
		global $post;

		$product = wc_get_product( $post->ID );
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		echo '<div id="ogoship_product_data" class="panel woocommerce_options_panel hidden">';

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		echo '<div class="options_group">';
		printf(
			'<p class="ogoship-panel-intro">%s</p>',
			esc_html__(
				'These fields are read by OGOship when it syncs your catalogue. They are never shown to customers.',
				'ogoship-for-woocommerce'
			)
		);
		echo '</div>';

		echo '<div class="options_group">';
		foreach ( Repository::fields() as $key => $field ) {
			$value = Repository::get_own( $product, $key );

			if ( 'yes_no' === $field['sanitize'] ) {
				woocommerce_wp_checkbox(
					array(
						'id'          => $key,
						'value'       => 'yes' === $value ? 'yes' : 'no',
						'cbvalue'     => 'yes',
						'label'       => $field['label'],
						'description' => $field['description'],
						'desc_tip'    => false,
					)
				);
				continue;
			}

			woocommerce_wp_text_input(
				array(
					'id'          => $key,
					'value'       => $value,
					'label'       => $field['label'],
					'description' => $field['description'],
					'desc_tip'    => true,
					// wc_input_price gives the price field WooCommerce's own
					// locale-aware decimal handling.
					'class'       => 'price' === $field['sanitize'] ? 'short wc_input_price' : 'short',
				)
			);
		}//end foreach
		echo '</div>';

		if ( $product->is_type( 'variable' ) ) {
			echo '<div class="options_group">';
			printf(
				'<p class="ogoship-panel-note">%s</p>',
				esc_html__(
					'Variations inherit these values. Open the Variations tab to give an individual variation its own EAN code or supplier details.',
					'ogoship-for-woocommerce'
				)
			);
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Persist the submitted fields onto the product object.
	 *
	 * Hooked to `woocommerce_admin_process_product_object`, so WooCommerce
	 * saves the product for us -- no explicit save() and no post-meta writes.
	 *
	 * @param \WC_Product $product Product being saved.
	 */
	public function save( \WC_Product $product ): void {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			// Product saved by something other than this form (a REST call, a
			// bulk edit); leave the meta untouched rather than clearing it.
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_NAME ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_product', $product->get_id() ) ) {
			return;
		}

		foreach ( array_keys( Repository::fields() ) as $key ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Repository::set sanitizes per field type.
			$raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
			Repository::set( $product, $key, is_scalar( $raw ) ? (string) $raw : '' );
		}
	}

	/**
	 * Enqueue the panel stylesheet, scoped to the product edit screen.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style(
			'ogoship-admin',
			Plugin::url( 'assets/css/admin.css' ),
			array(),
			\OGOship\WooCommerce\VERSION
		);
	}
}
