<?php
/**
 * OGOship fields on individual product variations.
 *
 * @package OGOship\WooCommerce
 */

declare( strict_types=1 );

namespace OGOship\WooCommerce\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Adds per-variation OGOship fields.
 *
 * A blank field means "inherit from the parent product", matching how
 * WooCommerce itself treats variation weight and dimensions. Only variations
 * that genuinely differ -- a different EAN per size, say -- need filling in.
 *
 * Note: the OGOship server currently reads these keys from the parent product
 * only. Variation-level values are stored correctly here and are visible over
 * the REST API, but they will not affect fulfillment until
 * WooCommerceProductInfoProvider (ECom and RapidWarehouse) is taught to read
 * the variation first and fall back to the parent.
 */
final class VariationFields {

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'woocommerce_variation_options_inventory', array( $this, 'render' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Render the fields inside a variation's inventory section.
	 *
	 * @param int                  $loop           Index of the variation in the form.
	 * @param array<string, mixed> $variation_data Legacy variation data (unused).
	 * @param \WP_Post             $variation      The variation post.
	 */
	public function render( int $loop, array $variation_data, \WP_Post $variation ): void {
		$product = wc_get_product( $variation->ID );
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$parent = wc_get_product( $product->get_parent_id() );
		$fields = Repository::fields();

		echo '<div class="ogoship-variation-fields">';
		printf(
			'<p class="form-row form-row-full"><strong>%s</strong></p>',
			esc_html__( 'OGOship', 'ogoship-for-woocommerce' )
		);

		$column = 0;

		foreach ( Repository::variation_keys() as $key ) {
			$field = $fields[ $key ];

			// Show the variation's OWN value. Rendering the inherited value
			// would silently turn it into an explicit override on save.
			$own = Repository::get_own( $product, $key );

			$inherited = $parent instanceof \WC_Product
				? (string) $parent->get_meta( $key, true )
				: '';

			$placeholder = '' !== $inherited
				/* translators: %s: value inherited from the parent product. */
				? sprintf( __( 'Inherited: %s', 'ogoship-for-woocommerce' ), $inherited )
				: __( 'Not set', 'ogoship-for-woocommerce' );

			// Alternate first/last so the fields pair into two columns, the way
			// WooCommerce lays out its own variation fields. Using form-row-first
			// throughout stacks them in one narrow column and makes the panel
			// twice as tall as it needs to be.
			$wrapper_class = 0 === $column % 2 ? 'form-row form-row-first' : 'form-row form-row-last';
			++$column;

			woocommerce_wp_text_input(
				array(
					'id'            => "ogoship_variation{$key}[{$loop}]",
					'name'          => "ogoship_variation{$key}[{$loop}]",
					'value'         => $own,
					'label'         => $field['label'],
					'placeholder'   => $placeholder,
					'desc_tip'      => true,
					'description'   => $field['description'],
					'wrapper_class' => $wrapper_class,
				)
			);
		}//end foreach

		// Clear the float so WooCommerce's following fields (weight, dimensions)
		// do not wrap alongside the last OGOship row.
		echo '<div class="clear"></div>';

		echo '</div>';
	}

	/**
	 * Save the submitted variation fields.
	 *
	 * WooCommerce has already verified the `save_variations` nonce and the
	 * user's capability before firing this hook.
	 *
	 * @param int $variation_id The variation being saved.
	 * @param int $loop         Index of the variation in the form.
	 */
	public function save( int $variation_id, int $loop ): void {
		$product = wc_get_product( $variation_id );
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		if ( ! current_user_can( 'edit_product', $variation_id ) ) {
			return;
		}

		foreach ( Repository::variation_keys() as $key ) {
			$input = "ogoship_variation{$key}";

			// The field arrives as an array keyed by the variation loop index, so
			// it has to be sanitized recursively. map_deep() with
			// sanitize_text_field() is what wc_clean() does internally, but
			// Plugin Check's ruleset does not know WooCommerce's helper and
			// reports the input as unsanitized. Repository::set then applies the
			// field's own type-aware sanitization on top.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by WooCommerce before this hook fires.
			$values = isset( $_POST[ $input ] ) ? map_deep( wp_unslash( $_POST[ $input ] ), 'sanitize_text_field' ) : array();

			if ( ! is_array( $values ) || ! isset( $values[ $loop ] ) ) {
				continue;
			}

			$raw = $values[ $loop ];
			Repository::set( $product, $key, is_scalar( $raw ) ? (string) $raw : '' );
		}

		$product->save();
	}
}
