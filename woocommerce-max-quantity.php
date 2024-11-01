<?php
/**
 * Plugin Name: Maximum Quantity for WooCommerce Shops
 * Plugin URI:
 * Description: Set a limit for the maximum quantity that can be added to the WooCommerce cart, globally or per product.
 * Version: 2.2.1
 * Author: PT Woo Plugins (by Webdados)
 * Author URI: https://ptwooplugins.com
 * Text Domain: woocommerce-max-quantity
 * Requires at least: 5.6
 * Tested up to: 6.7
 * Requires PHP: 7.0
 * WC requires at least: 5.0
 * WC tested up to: 9.4
 * Requires Plugins: woocommerce
 * License: GPLv3
 */

namespace PTWooPlugins\MaxQuantityWC;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


/**
 * Load plugin
 *
 * @since 2.0
 * @return void
 */
function init() {
	// Load textdomain
	load_plugin_textdomain( 'woocommerce-max-quantity' );
	// Check for: WooCommerce (maybe later also check for required version)
	if ( class_exists( 'WooCommerce' ) ) {
		add_filter( 'woocommerce_inventory_settings', __NAMESPACE__ . '\wc_max_qty_options' );
		add_filter( 'woocommerce_quantity_input_args', __NAMESPACE__ . '\wc_max_qty_input_args', 10, 2 );
		add_filter( 'woocommerce_available_variation', __NAMESPACE__ . '\wc_max_qty_variation_input_qty_max', 10, 3 );
		add_filter( 'woocommerce_add_to_cart_validation', __NAMESPACE__ . '\wc_max_qty_add_to_cart_validation', 1, 4 );
		add_filter( 'woocommerce_update_cart_validation', __NAMESPACE__ . '\wc_max_qty_update_cart_validation', 1, 4 );
		add_action( 'woocommerce_product_options_inventory_product_data', __NAMESPACE__ . '\wc_max_qty_add_product_field' );
		add_action( 'woocommerce_process_product_meta', __NAMESPACE__ . '\wc_max_qty_save_product_field' );
		add_filter( 'woocommerce_store_api_product_quantity_limit', __NAMESPACE__ . '\blocks_cart_max_qty', 10, 2 );
	}
}
add_action( 'init', __NAMESPACE__ . '\init' );


/**
 * Add the option to WooCommerce products tab
 *
 * @param array $settings The current settings.
 */
function wc_max_qty_options( $settings ) {
	$updated_settings = array();
	foreach ( $settings as $section ) {
		// At the bottom of the Inventory Options section
		if ( isset( $section['id'] ) && 'product_inventory_options' === $section['id'] && isset( $section['type'] ) && 'sectionend' === $section['type'] ) {
				$updated_settings[] = array(
					'title'             => __( 'Maximum quantity per product', 'woocommerce-max-quantity' ),
					'desc'              => __( 'This is the default maximum quantity that can be added to the cart per product. To overide this for a specific product, set it on the "Max quantity per order" field on the product Inventory tab.', 'woocommerce-max-quantity' ),
					'id'                => 'isa_woocommerce_max_qty_limit',
					'css'               => 'width: 75px;',
					'type'              => 'number',
					'custom_attributes' => array(
						'min'  => 0,
						'step' => 1,
					),
					'default'           => '',
					'autoload'          => false,
					'desc_tip'          => false,
				);
		}
		$updated_settings[] = $section;
	}
	return $updated_settings;
}


/**
 * Display the product's "Max quantity per order" field in the Product Data metabox
 *
 * @since 1.4
 */
function wc_max_qty_add_product_field() {
	$default_max = (int) get_option( 'isa_woocommerce_max_qty_limit' );
	echo '<div class="options_group">';
	woocommerce_wp_text_input(
		array(
			'id'          => '_isa_wc_max_qty_product_max',
			'label'       => __( 'Max quantity per order', 'woocommerce-max-quantity' ),
			'placeholder' => $default_max > 0 ? sprintf(
				/* translators: %d: Default maximum quantity */
				esc_attr__( 'Store-wide threshold (%d)', 'woocommerce-max-quantity' ),
				$default_max
			) : '',
			'description' => __( 'Optional. Set a maximum quantity limit allowed per order. Enter a number, 1 or greater.', 'woocommerce-max-quantity' ),
		)
	);
	echo '</div>';
}


/**
 * Save product's Max Quantity field
 *
 * @param int $post_id WP post id.
 * @since 1.4
 */
function wc_max_qty_save_product_field( $post_id ) {
	$product = wc_get_product( $post_id );
	$val     = $product->get_meta( '_isa_wc_max_qty_product_max' );
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$new = isset( $_POST['_isa_wc_max_qty_product_max'] ) ? sanitize_text_field( wp_unslash( $_POST['_isa_wc_max_qty_product_max'] ) ) : ''; // Nonce verification is already taken care by WooCommerce
	if ( $val !== $new ) {
		$product->update_meta_data( '_isa_wc_max_qty_product_max', $new );
		$product->save();
	}
}


/**
 * Get the individual product max limit
 *
 * @param int $product_id The product ID.
 * @return int|bool $limit The max limit number for this product, if set, otherwise false.
 * @since 1.4
 */
function wc_get_product_max_limit( $product_id ) {
	$product = wc_get_product( $product_id );
	$qty     = $product->get_meta( '_isa_wc_max_qty_product_max' );
	if ( empty( $qty ) ) {
		// Honor the Sold individually setting
		$limit = $product->is_sold_individually() ? 1 : false;
	} else {
		$limit = (int) $qty;
	}
	return $limit;
}


/**
 * Set the max attribute value for the quantity input field for Add to cart forms.
 * This applies to Simple product Add To Cart forms, and ALL (simple and variable) products on the Cart page quantity field.
 *
 * @param array  $args The current arguments.
 * @param object $product The product.
 * @return array $args
 * @since 1.1.6
 */
function wc_max_qty_input_args( $args, $product ) {
	$default_max = (int) get_option( 'isa_woocommerce_max_qty_limit' );
	$product_id  = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
	$product_max = wc_get_product_max_limit( $product_id );
	// Set product max or default max
	if ( ! empty( $product_max ) ) {
		$args['max_value'] = $product_max;
	} elseif ( ! empty( $default_max ) ) {
		$args['max_value'] = $default_max;
	}
	// Limit our max by the available stock, if stock is lower
	if ( ! empty( $args['max_value'] ) ) {
		if ( $product->managing_stock() && ! $product->backorders_allowed() ) {
			$stock             = $product->get_stock_quantity();
			$args['max_value'] = min( $stock, $args['max_value'] );
		}
	}
	return $args;
}


/**
 * Filter the available variation to enforce the max on the quantity input field
 * on Add to cart forms for Variable Products.
 *
 * @param array  $args The current arguments.
 * @param object $product The product.
 * @param object $variation The product variation.
 */
function wc_max_qty_variation_input_qty_max( $args, $product, $variation ) {
	if ( is_admin() && ! is_ajax() ) {
		return $args;
	}
	$default_max = (int) get_option( 'isa_woocommerce_max_qty_limit' );
	$product_max = wc_get_product_max_limit( $variation->get_parent_id() );
	// Set product max or default max
	if ( ! empty( $product_max ) ) {
		$args['max_qty'] = $product_max;
	} elseif ( ! empty( $default_max ) ) {
		$args['max_qty'] = $default_max;
	}
	// Limit our max by the available stock, if stock is lower
	if ( ! empty( $args['max_qty'] ) ) {
		if ( $variation->managing_stock() && ! $variation->backorders_allowed() ) {
			$stock           = $variation->get_stock_quantity();
			$args['max_qty'] = min( $stock, $args['max_qty'] );
		}
	}
	return $args;
}


/**
 * Find out how many of this product are already in the cart
 *
 * @param mixed  $product_id ID of the product in question.
 * @param string $cart_item_key The cart key for this item in case of Updating cart.
 *
 * @return integer $running_qty The total quantity of this item, parent item in case of variations, in cart
 * @since 1.1.6
 */
function wc_max_qty_get_cart_qty( $product_id, $cart_item_key = '' ) {
	$running_qty = 0; // Keep a running total to count variations
	// Search the cart for the product in question
	foreach ( WC()->cart->get_cart() as $other_cart_item_keys => $values ) {
		if ( intval( $product_id ) === intval( $values['product_id'] ) ) {
			/*
			 * In case of updating the cart quantity, don't count this cart item key
			otherwise they won't be able to REDUCE the number of items in cart because it will think it is adding the new quantity on top of the existing quantity, when in fact it is reducing the existing quantity to the new quantity.
			 */
			if ( $cart_item_key === $other_cart_item_keys ) {
				continue;
			}
			// Add that quantity to our running total qty for this product
			$running_qty += (int) $values['quantity'];
		}
	}
	return $running_qty;
}


/**
 * Validate product quantity when Added to cart
 *
 * @param bool $passed If the validation passed.
 * @param int  $product_id The product ID.
 * @param int  $quantity The quantity added to cart.
 * @param int  $variation_id The product variation ID.
 *
 * @since 1.1.6
 */
function wc_max_qty_add_to_cart_validation( $passed, $product_id, $quantity, $variation_id = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	// If it hasn't passed, we don't need to bother validating further
	if ( $passed ) {
		$default_max = (int) get_option( 'isa_woocommerce_max_qty_limit' );
		$product_max = wc_get_product_max_limit( $product_id );
		// Set product max or default max
		if ( ! empty( $product_max ) ) {
			$new_max = $product_max;
		} elseif ( ! empty( $default_max ) ) {
			$new_max = $default_max;
		} else {
			return $passed;
		}
		$already_in_cart = wc_max_qty_get_cart_qty( $product_id );
		$product         = wc_get_product( $product_id );
		$product_title   = $product->get_title();
		if ( ! empty( $already_in_cart ) ) {
			// There was already a quantity of this item in cart prior to this addition.
			// Check if the total of already_in_cart + current addition quantity is more than our max.
			if ( ( $already_in_cart + $quantity ) > $new_max ) {
				// oops. too much.
				$passed = false;
				// Add compatibility with WooCommerce Direct Checkout
				if ( class_exists( 'WooCommerce_Direct_Checkout' ) ) {
					$direct_checkout     = get_option( 'direct_checkout_enabled' );
					$direct_checkout_url = get_option( 'direct_checkout_cart_redirect_url' );
					if ( $direct_checkout && $direct_checkout_url ) {
						// Redirect to submit page
						wp_safe_redirect( esc_url_raw( $direct_checkout_url ) );
						exit;
					}
				}
				wc_add_notice(
					apply_filters(
						'isa_wc_max_qty_error_message_already_had',
						sprintf(
							/* translators: %1$s maximum items, %2$s product name, %3$s: cart link, %4$s number of items in cart */
							__( 'You can add a maximum of %1$s %2$s’s to %3$s. You already have %4$s.', 'woocommerce-max-quantity' ),
							$new_max,
							$product_title,
							'<a href="' . esc_url( wc_get_cart_url() ) . '">' . __( 'your cart', 'woocommerce-max-quantity' ) . '</a>',
							$already_in_cart
						),
						$new_max,
						$already_in_cart
					),
					'error'
				);
			}
		} else { // phpcs:ignore Universal.ControlStructures.DisallowLonelyIf.Found
			// none were in cart previously
			// just in case they manually type in an amount greater than we allow, check the input number here too
			if ( $quantity > $new_max ) {
				// oops. too much.
				wc_add_notice(
					apply_filters(
						'isa_wc_max_qty_error_message',
						sprintf(
							/* translators: %1$s maximum items, %2$s product name, %3$s: cart link */
							__( 'You can add a maximum of %1$s %2$s’s to %3$s.', 'woocommerce-max-quantity' ),
							$new_max,
							$product_title,
							'<a href="' . esc_url( wc_get_cart_url() ) . '">' . __( 'your cart', 'woocommerce-max-quantity' ) . '</a>'
						),
						$new_max
					),
					'error'
				);
				$passed = false;
			}
		}
	}
	return $passed;
}

/**
 * Validate product quantity when cart is UPDATED - Not working when updating quantity inside the block-based cart
 * Just in case they manually type in an amount greater than we allow and the HTML5 Constraint validation doesn't work.
 *
 * @param bool   $passed If the validation passed.
 * @param string $cart_item_key The key of the updated cart item.
 * @param array  $values Cart item values.
 * @param int    $quantity The quantity updated to cart.
 * @since 1.1.9
 */
function wc_max_qty_update_cart_validation( $passed, $cart_item_key, $values, $quantity ) {
	// If it hasn't passed, we don't need to bother validating further
	if ( $passed ) {
		$default_max = (int) get_option( 'isa_woocommerce_max_qty_limit' );
		$product_max = wc_get_product_max_limit( $values['product_id'] );
		// Set product max or default max
		if ( ! empty( $product_max ) ) {
			$new_max = $product_max;
		} elseif ( ! empty( $default_max ) ) {
			$new_max = $default_max;
		} else {
			return $passed;
		}
		$already_in_cart = wc_max_qty_get_cart_qty( $values['product_id'], $cart_item_key );
		$product         = wc_get_product( $values['product_id'] );
		if ( ( $already_in_cart + $quantity ) > $new_max ) {
			wc_add_notice(
				apply_filters(
					'isa_wc_max_qty_error_message',
					sprintf(
						/* translators: %1$s maximum items, %2$s product name, %3$s: cart link */
						__( 'You can add a maximum of %1$s %2$s’s to %3$s.', 'woocommerce-max-quantity' ),
						$new_max,
						$product->get_name(),
						'<a href="' . esc_url( wc_get_cart_url() ) . '">' . __( 'your cart', 'woocommerce-max-quantity' ) . '</a>'
					),
					$new_max
				),
				'error'
			);
			$passed = false;
		}
	}
	return $passed;
}


/**
 * Set maximum quantity on the block-based cart
 *
 * @since 2.0
 * @param int    $cart_max Current maximum quantity.
 * @param object $product Current product.
 * @return int
 */
function blocks_cart_max_qty( $cart_max, $product ) {
	if ( ! empty( $cart_max ) ) {
		$default_max = (int) get_option( 'isa_woocommerce_max_qty_limit' );
		$product_max = wc_get_product_max_limit( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() );
		// Set product max or default max
		if ( ! empty( $product_max ) ) {
			$new_max = $product_max;
		} elseif ( ! empty( $default_max ) ) {
			$new_max = $default_max;
		}
		if ( ! empty( $new_max ) ) {
			$cart_max = min( $cart_max, $new_max );
		}
	}
	return $cart_max;
}


/* Declare WooCommerce HPOS Compatibility */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);
