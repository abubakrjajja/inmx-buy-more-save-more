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
 * Record what each bundle saved on this order, for the Analytics tab.
 *
 * Works for both classic checkout and WooCommerce Blocks checkout.
 *
 * CHANGED IN 1.1.0. This used to derive the figure from the tier's configured
 * regular price, which is a number an admin types and can get wrong. On
 * one live store it had pack totals typed into a per-item field, which would have
 * recorded Rs 13,988 of savings on a Rs 1,996 order.
 *
 * It is now measured the same way the cart fee is: the line subtotal the
 * customer would have paid, minus the tier price for that quantity. The number
 * stored here and the discount line on the order are therefore the same number
 * by construction, not by two calculations agreeing.
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

		$price = (float) ( $tier['price_per_item'] ?? 0 );

		$total_discount = 0.0;
		$items_count    = 0;
		$revenue        = 0.0;

		foreach ( $order->get_items() as $item ) {
			$pid = (int) $item->get_product_id();
			if ( in_array( $pid, $gift_ids, true ) ) {
				continue;
			}
			if ( ! inmx_in_cat( $pid, $cat_id ) ) {
				continue;
			}
			$qty          = (int) $item->get_quantity();
			$line         = (float) $item->get_subtotal();
			$items_count += $qty;
			$revenue     += $line;
			if ( $price > 0 ) {
				$total_discount += max( 0.0, $line - ( $price * $qty ) );
			}
		}

		// Nothing from this offer's category in the order: nothing to say about it.
		if ( $items_count < 1 ) {
			continue;
		}

		// Where in the ladder did this order land, and how close was the next rung?
		//
		// CHANGED IN 1.2.0: recorded for EVERY order containing the category, not
		// only ones that earned a discount. The old gate meant an order that
		// bought a single item - precisely the upsell that did NOT work - was
		// never recorded, so there was no denominator to measure success against
		// and the report could only ever show money given away.
		$tiers      = $offer['tiers'] ?? [];
		$tier_index = 0;
		foreach ( $tiers as $i => $t ) {
			if ( $items_count >= (int) ( $t['qty'] ?? 0 ) ) {
				$tier_index = $i + 1;
			}
		}
		$next          = inmx_next_tier( $items_count, $tiers );
		$next_tier_qty = $next ? (int) $next['qty'] : 0;

		$bundles[] = [
			'offer_name'    => ! empty( $offer['name'] ) ? $offer['name'] : 'Bundle Offer',
			'category_id'   => $cat_id,
			'discount'      => round( $total_discount, 2 ),
			'revenue'       => round( $revenue - $total_discount, 2 ),
			'tier_qty'      => $tier_index > 0 ? (int) $tier['qty'] : 0,
			'tier_index'    => $tier_index,
			'tier_count'    => count( $tiers ),
			'items_count'   => $items_count,
			'next_tier_qty' => $next_tier_qty,
			'units_to_next' => $next_tier_qty ? max( 0, $next_tier_qty - $items_count ) : 0,
		];
	}

	if ( $bundles ) {
		$order->update_meta_data( '_inmx_bundle_discounts', $bundles );
		$order->save_meta_data();
	}
}

// Classic checkout + WooCommerce Blocks checkout.
add_action( 'woocommerce_checkout_order_created', 'inmx_save_order_discounts' );
add_action( 'woocommerce_store_api_checkout_order_processed', 'inmx_save_order_discounts' );

/*
 * REMOVED IN 1.1.0: the woocommerce_get_order_item_totals filter that injected
 * "Discount: Offer Name  -Rs X" rows into order totals tables.
 *
 * From 1.1.0 the saving is a real WooCommerce fee created at cart level, so it
 * is already a line on the order, on My Account, in the admin order screen and
 * in every order email. Keeping the injection as well would have shown the same
 * discount twice on every one of those surfaces.
 *
 * The order meta _inmx_bundle_discounts is still written above, because the
 * Analytics tab reports per-offer performance and a generic fee line cannot be
 * attributed back to an offer and tier.
 */

/**
 * Tint the bundle discount line green wherever WooCommerce renders order totals.
 *
 * Cosmetic only. It matches a fee row to a bundle by its stored amount rather
 * than by parsing the label, so renaming an offer cannot break it.
 */
add_filter(
	'woocommerce_get_order_item_totals',
	function ( array $rows, WC_Order $order ): array {

		$bundles = $order->get_meta( '_inmx_bundle_discounts', true );
		if ( ! is_array( $bundles ) || ! $bundles ) {
			return $rows;
		}

		$amounts = array_map( static fn( $b ) => round( (float) ( $b['discount'] ?? 0 ), 2 ), $bundles );

		foreach ( $order->get_items( 'fee' ) as $fee_id => $fee ) {
			$total = round( (float) $fee->get_total(), 2 );
			if ( $total >= 0 || ! in_array( abs( $total ), $amounts, true ) ) {
				continue;
			}
			$key = 'fee_' . $fee_id;
			if ( isset( $rows[ $key ]['value'] ) ) {
				$rows[ $key ]['value'] = '<span class="inmx-order-discount" style="color:#22a85e;font-weight:700">'
					. $rows[ $key ]['value'] . '</span>';
			}
		}

		return $rows;
	},
	10,
	2
);
