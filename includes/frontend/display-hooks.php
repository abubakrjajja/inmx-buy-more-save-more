<?php
/**
 * Where the widgets appear, AJAX fragments, and gift item display filters.
 *
 * Carried over unchanged from the WPCode snippet.
 *
 * @package INMX\BMSM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * ─────────────────────────────────────────────────────────────────────
 * Display hooks
 * ─────────────────────────────────────────────────────────────────────
 */

// Product page: priority 25 places it between description and add-to-cart.
add_action( 'woocommerce_single_product_summary', 'inmx_hook_product_widget', 25 );

/**
 * Product page widget.
 */
function inmx_hook_product_widget(): void {
	$pid = (int) get_the_ID();
	inmx_render_widget( 'product', $pid );
}

add_action( 'woocommerce_before_cart_table', 'inmx_hook_cart_widget' );

/**
 * Cart page widget.
 */
function inmx_hook_cart_widget(): void {
	if ( ! is_cart() ) {
		return;
	}
	inmx_render_widget( 'cart' );
}

add_action( 'woocommerce_before_checkout_form', 'inmx_hook_checkout_widget', 5 );

/**
 * Checkout widget.
 */
function inmx_hook_checkout_widget(): void {
	if ( ! is_checkout() ) {
		return;
	}
	inmx_render_widget( 'checkout' );
}

/*
 * ─────────────────────────────────────────────────────────────────────
 * Mini cart widget - Woodmart side-cart drawer
 * ─────────────────────────────────────────────────────────────────────
 *
 * Neither WooCommerce hook fires inside Woodmart's .wd-scroll container.
 * woocommerce_before_mini_cart fires before .wd-scroll (renders visibly but
 * steals height). JS then appends the widget inside .wd-scroll so it scrolls
 * with the cart items. See assets/js/frontend.js.
 */
add_action(
	'woocommerce_before_mini_cart',
	function (): void {
		inmx_render_widget( 'mini' );
	},
	5
);

/*
 * Combined fragment: updates widgets on every AJAX cart event.
 * Each location gets its own compound selector as the fragment key, so jQuery
 * only replaces the exact location's wrapper - never bleeds into other contexts.
 */
add_filter(
	'woocommerce_add_to_cart_fragments',
	function ( array $fragments ): array {
		foreach ( [ 'cart', 'mini', 'product' ] as $loc ) {
			ob_start();
			inmx_render_widget( $loc );
			$html = ob_get_clean();

			$fragments[ '.inmx-upsell-wrap.inmx-ctx-' . $loc ] = $html;
		}

		return $fragments;
	}
);

/*
 * ─────────────────────────────────────────────────────────────────────
 * Gift item display filters
 * ─────────────────────────────────────────────────────────────────────
 */

add_filter(
	'woocommerce_cart_item_class',
	function ( string $class, array $item ): string {
		return in_array( (int) $item['product_id'], inmx_gift_ids(), true ) ? $class . ' inmx-gift-item' : $class;
	},
	10,
	2
);

/**
 * Gift rows show the product's own name, with a "Free Gift" line beneath it.
 *
 * Until 1.1.0 this replaced the name outright, so both the cart and the side
 * cart showed a row reading "Free Gift" over "1 x FREE" with no indication of
 * what the customer was actually being given.
 */
add_filter(
	'woocommerce_cart_item_name',
	function ( $name, array $item ) {

		$pid = (int) $item['product_id'];
		if ( ! in_array( $pid, inmx_gift_ids(), true ) ) {
			return $name;
		}

		$title = get_the_title( $pid );
		if ( ! $title ) {
			$title = wp_strip_all_tags( (string) $name );
		}

		return '<span class="inmx-gift-name">' . esc_html( $title ) . '</span>'
			. '<span class="inmx-gift-label">&#127873; Free Gift</span>';
	},
	10,
	2
);

/**
 * Same treatment inside the Woodmart / WooCommerce side cart, which renders
 * item names through its own filter rather than woocommerce_cart_item_name.
 */
add_filter(
	'woocommerce_widget_cart_item_quantity',
	function ( $html, array $item ) {

		if ( ! in_array( (int) $item['product_id'], inmx_gift_ids(), true ) ) {
			return $html;
		}

		return '<span class="quantity">' . (int) $item['quantity']
			. ' &times; <span class="inmx-free-tag">FREE</span></span>';
	},
	10,
	2
);

add_filter(
	'woocommerce_cart_item_price',
	function ( $html, array $item ) {
		$pid = (int) $item['product_id'];
		// Gift items always show FREE.
		if ( in_array( $pid, inmx_gift_ids(), true ) ) {
			return '<span class="inmx-free-tag">FREE</span>';
		}
		// Bundle items: show ~~original~~ sale price.
		$orig = inmx_get_original_price_for_item( $pid );
		if ( $orig > 0 ) {
			return '<del class="inmx-orig-price">' . wc_price( $orig ) . '</del>&nbsp;' . $html;
		}
		return $html;
	},
	10,
	2
);

add_filter(
	'woocommerce_cart_item_subtotal',
	function ( $html, array $item ) {
		$pid = (int) $item['product_id'];
		if ( in_array( $pid, inmx_gift_ids(), true ) ) {
			return '<span class="inmx-free-tag">FREE</span>';
		}
		$orig = inmx_get_original_price_for_item( $pid );
		if ( $orig > 0 ) {
			$qty = (int) $item['quantity'];
			return '<del class="inmx-orig-price">' . wc_price( $orig * $qty ) . '</del>&nbsp;' . $html;
		}
		return $html;
	},
	10,
	2
);

/*
 * Side cart total.
 *
 * Since 1.1.0 the saving is a fee, and WooCommerce's side cart prints only
 * the subtotal, which is the price before fees. So a buyer with 3 bandanas saw
 * "Any 3 Bandana Rs 1,707" ticked in the widget and "Subtotal: Rs 2,097" right
 * under it. When a bundle saving applies, the side cart now shows the subtotal,
 * each saving line, and what the items cost after the saving. Delivery is
 * still added at checkout, as before.
 */
add_action( 'woocommerce_widget_shopping_cart_total', 'inmx_mini_cart_bundle_total', 5 );

/**
 * Replace the side cart's bare subtotal with subtotal, saving and the price after it.
 */
function inmx_mini_cart_bundle_total(): void {
	$cart = WC()->cart;
	if ( ! $cart instanceof WC_Cart ) {
		return;
	}

	$lines = inmx_bundle_discount_lines( $cart );
	if ( ! $lines ) {
		return;
	}

	remove_action( 'woocommerce_widget_shopping_cart_total', 'woocommerce_widget_shopping_cart_subtotal', 10 );

	$subtotal = (float) $cart->get_subtotal();
	$saving   = 0.0;

	echo '<span class="inmx-mini-row"><strong>' . esc_html__( 'Subtotal', 'woocommerce' ) . ':</strong> ' . wp_kses_post( wc_price( $subtotal ) ) . '</span>';
	foreach ( $lines as $line ) {
		$saving += $line['amount'];
		echo '<span class="inmx-mini-row inmx-mini-saving"><span>' . esc_html( $line['label'] ) . '</span> <span>' . wp_kses_post( wc_price( -$line['amount'] ) ) . '</span></span>';
	}
	echo '<span class="inmx-mini-row inmx-mini-after"><strong>' . esc_html__( 'Total', 'woocommerce' ) . ':</strong> ' . wp_kses_post( wc_price( max( 0.0, $subtotal - $saving ) ) ) . '</span>';
}

/*
 * Woodmart header cart ("3 / Rs 2,097"): the theme prints the cart subtotal,
 * which is the price before the bundle saving. Woodmart declares this function
 * only if nobody has, and plugins load before the theme, so this one wins.
 * On any other theme nothing calls it. (1.3.3)
 */
if ( ! function_exists( 'woodmart_cart_subtotal' ) ) {
	function woodmart_cart_subtotal(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart instanceof WC_Cart ) {
			return;
		}
		$cart   = WC()->cart;
		$saving = 0.0;
		foreach ( inmx_bundle_discount_lines( $cart ) as $line ) {
			$saving += $line['amount'];
		}
		$shown = $saving > 0
			? wc_price( max( 0.0, (float) $cart->get_subtotal() - $saving ) )
			: $cart->get_cart_subtotal();
		echo '<span class="wd-cart-subtotal">' . wp_kses_post( $shown ) . '</span>';
	}
}
