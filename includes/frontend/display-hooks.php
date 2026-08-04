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
 * Mini cart widget — Woodmart side-cart drawer
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
 * only replaces the exact location's wrapper — never bleeds into other contexts.
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

add_filter(
	'woocommerce_cart_item_name',
	function ( $name, array $item ) {
		return in_array( (int) $item['product_id'], inmx_gift_ids(), true )
				? '<span class="inmx-gift-label">&#127873; Free Gift</span>' : $name;
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
