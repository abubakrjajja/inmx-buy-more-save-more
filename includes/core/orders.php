<?php
/**
 * Order discount storage and display.
 *
 * On order creation: saves _inmx_bundle_discounts = [
 *   [ offer_name, discount, tier_qty, items_count ], ...
 * ]
 *
 * On display: injects "Discount: Offer Name  -Rs X" into the order totals table
 * used by front-end, My Account, admin, AND all emails.
 *
 * Carried over unchanged from the WPCode snippet.
 *
 * @package INMX\BMSM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build category→qty map from an existing WC_Order's line items.
 * Mirrors inmx_qty_map() but reads the order instead of the live cart.
 */
function inmx_order_qty_map( WC_Order $order ): array {
	$map      = [];
	$gift_ids = inmx_gift_ids();
	$cat_ids  = array_unique(
		array_map(
			'intval',
			array_filter( array_column( inmx_get_offers(), 'category_id' ) )
		)
	);
	foreach ( $cat_ids as $id ) {
		$map[ $id ] = 0;
	}

	foreach ( $order->get_items() as $item ) {
		$pid = (int) $item->get_product_id();
		if ( in_array( $pid, $gift_ids, true ) ) {
			continue;
		}
		foreach ( $cat_ids as $cat_id ) {
			if ( inmx_in_cat( $pid, $cat_id ) ) {
				$map[ $cat_id ] += (int) $item->get_quantity();
			}
		}
	}
	return $map;
}

/**
 * Calculate and save bundle discounts on the order at checkout.
 * Works for both classic checkout and WooCommerce Blocks checkout.
 */
function inmx_save_order_discounts( WC_Order $order ): void {
	$map      = inmx_order_qty_map( $order );
	$gift_ids = inmx_gift_ids();
	$bundles  = [];

	foreach ( inmx_get_offers() as $offer ) {
		$cat_id = (int) ( $offer['category_id'] ?? 0 );
		if ( ! $cat_id ) {
			continue;
		}

		$tier = inmx_active_tier( $map[ $cat_id ] ?? 0, $offer['tiers'] ?? [] );
		if ( ! $tier ) {
			continue;
		}

		$orig  = (float) ( $tier['original_price'] ?? 0 );
		$price = (float) ( $tier['price_per_item'] ?? 0 );
		if ( $orig <= 0 || $price <= 0 || $orig <= $price ) {
			continue;
		}

		$disc_each      = $orig - $price;
		$total_discount = 0.0;
		$items_count    = 0;

		foreach ( $order->get_items() as $item ) {
			$pid = (int) $item->get_product_id();
			if ( in_array( $pid, $gift_ids, true ) ) {
				continue;
			}
			if ( ! inmx_in_cat( $pid, $cat_id ) ) {
				continue;
			}
			$qty             = (int) $item->get_quantity();
			$total_discount += $disc_each * $qty;
			$items_count    += $qty;
		}

		if ( $total_discount > 0 ) {
			$bundles[] = [
				'offer_name'  => ! empty( $offer['name'] ) ? $offer['name'] : 'Bundle Offer',
				'discount'    => round( $total_discount, 2 ),
				'tier_qty'    => (int) $tier['qty'],
				'items_count' => $items_count,
			];
		}
	}

	if ( $bundles ) {
		$order->update_meta_data( '_inmx_bundle_discounts', $bundles );
		$order->save_meta_data();
	}
}

// Classic checkout + WooCommerce Blocks checkout.
add_action( 'woocommerce_checkout_order_created', 'inmx_save_order_discounts' );
add_action( 'woocommerce_store_api_checkout_order_processed', 'inmx_save_order_discounts' );

/**
 * Inject "Discount: Offer Name  -Rs X" rows into every order totals table.
 * One filter covers: front-end order detail, My Account, admin, AND all emails.
 * Rows appear immediately after the subtotal line.
 */
add_filter(
	'woocommerce_get_order_item_totals',
	function ( array $rows, WC_Order $order ): array {
		$bundles = $order->get_meta( '_inmx_bundle_discounts', true );
		if ( ! is_array( $bundles ) || empty( $bundles ) ) {
			return $rows;
		}

		$new_rows = [];
		foreach ( $rows as $key => $row ) {
			$new_rows[ $key ] = $row;
			if ( 'cart_subtotal' === $key ) {
				foreach ( $bundles as $b ) {
					$slug              = 'inmx_disc_' . sanitize_key( $b['offer_name'] ?? 'bundle' );
					$new_rows[ $slug ] = [
						'label' => esc_html( 'Discount: ' . ( $b['offer_name'] ?? 'Bundle' ) ) . ':',
						'value' => '<strong style="color:#22a85e">-' . wc_price( $b['discount'] ) . '</strong>',
					];
				}
			}
		}
		return $new_rows;
	},
	10,
	2
);
