<?php
/**
 * Pricing engine: gift management, the bundle discount line, and shipping.
 *
 * CHANGED IN 1.1.0 - bundle savings are now a named discount line instead of a
 * silent per-item price rewrite.
 *
 * Until 1.0.0 this called set_price() on every qualifying cart item, so a
 * customer saw Rs 799 on the product page and Rs 666.33 in the cart with
 * nothing saying why. The saving was real but invisible and unattributable: it
 * never appeared on the order, in an email, or in any WooCommerce report.
 *
 * Products now keep their own price and the saving is added as a negative fee
 * named after the tier, e.g. "AZAADI SALE: Any 3 Hand Bindis". WooCommerce
 * renders that natively on the cart, the checkout, the order screen and every
 * order email, with no display code of ours involved.
 *
 * Gifts are still set to 0 rather than discounted, because a gift is not a
 * saving on something the customer chose to buy.
 *
 * @package INMX\BMSM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Priority 5: Auto-manage tier-level gift products.
 *
 * For each offer:
 *   - Collects ALL gift IDs from all tiers (these might need removing).
 *   - Finds the active tier; if it has a gift, adds it.
 *   - Removes all other gift products for this offer from cart.
 *
 * Static $busy prevents re-entrant loop: remove_cart_item -> calculate_totals -> here.
 */
add_action( 'woocommerce_before_calculate_totals', 'inmx_manage_gifts', 5 );

/**
 * Add the active tier's gift and remove every other tier's gift.
 */
function inmx_manage_gifts( WC_Cart $cart ): void {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}
	static $busy = false;
	if ( $busy ) {
		return;
	}
	$busy = true;

	$map = inmx_qty_map( $cart );

	foreach ( inmx_get_offers() as $offer ) {
		$cat_id = (int) ( $offer['category_id'] ?? 0 );
		if ( ! $cat_id ) {
			continue;
		}

		$tiers  = $offer['tiers'] ?? [];
		$qty    = $map[ $cat_id ] ?? 0;
		$active = inmx_active_tier( $qty, $tiers );

		// All gift IDs configured in this offer's tiers.
		$offer_gift_ids = [];
		foreach ( $tiers as $t ) {
			$gid = (int) ( $t['free_gift_product_id'] ?? 0 );
			if ( $gid ) {
				$offer_gift_ids[] = $gid;
			}
		}
		$offer_gift_ids = array_unique( array_filter( $offer_gift_ids ) );

		// Gift that should be in cart right now (active tier's gift, if any).
		$wanted_gift = ( $active && ! empty( $active['free_gift_product_id'] ) )
						? (int) $active['free_gift_product_id'] : 0;

		// Remove all gifts for this offer that are NOT the wanted gift.
		foreach ( $offer_gift_ids as $gid ) {
			if ( $gid === $wanted_gift ) {
				continue;
			}
			$key = inmx_cart_key( $cart, $gid );
			if ( null !== $key ) {
				$cart->remove_cart_item( $key );
			}
		}

		// Add the wanted gift if not already in cart.
		if ( $wanted_gift ) {
			$key = inmx_cart_key( $cart, $wanted_gift );
			if ( null === $key ) {
				$cart->add_to_cart( $wanted_gift, 1 );
			}
		}
	}

	$busy = false;
}

/**
 * Priority 10: Zero the gift products.
 *
 * Only gifts are touched here. Bundle items keep their own price so that the
 * saving can show as its own line - see inmx_add_bundle_discount().
 */
add_action( 'woocommerce_before_calculate_totals', 'inmx_apply_prices', 10 );

/**
 * Set every gift product in the cart to zero.
 */
function inmx_apply_prices( WC_Cart $cart ): void {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}

	$gift_ids = inmx_gift_ids();
	if ( ! $gift_ids ) {
		return;
	}

	foreach ( $cart->get_cart() as $item ) {
		if ( in_array( (int) $item['product_id'], $gift_ids, true ) ) {
			$item['data']->set_price( 0 );
		}
	}
}

/**
 * Add one named discount line per active bundle.
 *
 * Runs on woocommerce_cart_calculate_fees, which fires after line subtotals
 * exist, so the saving is measured against what the customer would actually
 * have paid.
 */
add_action( 'woocommerce_cart_calculate_fees', 'inmx_add_bundle_discount', 20 );

/**
 * Apply the bundle savings as negative fees.
 */
function inmx_add_bundle_discount( WC_Cart $cart ): void {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}

	$bundles = inmx_active_bundles( $cart );
	if ( ! $bundles ) {
		return;
	}

	// A negative fee larger than the cart is how you produce a negative order
	// total, which WooCommerce will accept and no payment gateway will.
	$budget = (float) $cart->get_subtotal();

	foreach ( $bundles as $b ) {
		if ( $b['discount'] <= 0 ) {
			continue;
		}

		$amount = min( $b['discount'], $budget );
		if ( $amount <= 0 ) {
			break;
		}
		$budget -= $amount;

		$cart->add_fee( $b['label'], -$amount, false );
	}
}

/**
 * Decide shipping from the active tier rather than from the cart total.
 *
 * Two problems this solves.
 *
 * First, before 1.1.0 the per-tier "Free Delivery" checkbox drew a badge and
 * did nothing else - there was no shipping code in the plugin at all. On
 * bindiya.pk it looked correct only by coincidence, because the store's own
 * free-shipping threshold happened to fall between the 2-pack and the 3-pack.
 *
 * Second, moving the saving from the item price to a fee raises the cart
 * subtotal that WooCommerce's own free-shipping threshold is measured against.
 * That change alone would have started giving away free delivery a tier earlier
 * than the offer intends.
 *
 * So when a bundle is active the tier decides, and the store threshold is not
 * consulted for that cart.
 */
add_filter( 'woocommerce_shipping_free_shipping_is_available', 'inmx_bundle_free_shipping', 100, 3 );

/**
 * Decide free shipping availability from the active tier.
 *
 * This hooks the Free Shipping method's own availability check rather than
 * filtering the finished rate list, and that choice is deliberate.
 *
 * Measured on bindiya.pk: with the store's paid and free methods both reporting
 * available, only the free rate reaches `woocommerce_package_rates` once free
 * shipping qualifies. The paid rate is dropped during rate generation, upstream
 * of every filter, so a rate-list filter cannot restore it - it can only see
 * what is left. Deciding availability before rates are generated means the paid
 * method simply survives on its own.
 *
 * @param bool                     $is_available Method's own verdict.
 * @param array                    $package      Shipping package.
 * @param WC_Shipping_Free_Shipping $method      The method instance.
 * @return bool
 */
function inmx_bundle_free_shipping( $is_available, $package, $method ) {

	if ( ! WC()->cart instanceof WC_Cart ) {
		return $is_available;
	}

	$bundles = inmx_active_bundles( WC()->cart );
	if ( ! $bundles ) {
		return $is_available; // No offer active: the store's own rules stand.
	}

	$grants_free = false;
	foreach ( $bundles as $b ) {
		if ( ! empty( $b['tier']['free_delivery'] ) ) {
			$grants_free = true;
			break;
		}
	}

	/**
	 * Filter whether the active bundle grants free delivery.
	 *
	 * The rule is deliberately strict so the admin checkbox means exactly what
	 * it says, in both directions: a tier that grants free delivery gets it
	 * regardless of cart value, and a tier that does not cannot pick it up by
	 * accident from the store's own threshold.
	 *
	 * A store that wants its high-value threshold to keep applying on mixed
	 * carts can re-add it here, e.g. return true when the cart total minus the
	 * bundle discount still clears the threshold.
	 *
	 * @param bool  $grants_free  Whether free delivery applies.
	 * @param array $bundles      Active bundles.
	 * @param array $package      Shipping package.
	 * @param bool  $is_available The method's own verdict, before this filter.
	 */
	return (bool) apply_filters( 'inmx_bmsm_grants_free_delivery', $grants_free, $bundles, $package, $is_available );
}

/**
 * When the tier grants free delivery, do not also offer a paid rate.
 *
 * Belt and braces. On a store that does not already hide paid rates once free
 * shipping is available, this stops a customer paying for delivery the offer
 * has already promised them. It never removes the last remaining rate.
 */
add_filter( 'woocommerce_package_rates', 'inmx_bundle_hide_paid_rates', 100, 2 );

/**
 * Drop paid rates when an active tier grants free delivery.
 *
 * @param array $rates   Available rates.
 * @param array $package Shipping package.
 * @return array
 */
function inmx_bundle_hide_paid_rates( $rates, $package ) {

	if ( ! WC()->cart instanceof WC_Cart || count( $rates ) < 2 ) {
		return $rates;
	}

	$bundles = inmx_active_bundles( WC()->cart );
	if ( ! $bundles ) {
		return $rates;
	}

	$grants_free = false;
	foreach ( $bundles as $b ) {
		if ( ! empty( $b['tier']['free_delivery'] ) ) {
			$grants_free = true;
			break;
		}
	}
	if ( ! $grants_free ) {
		return $rates;
	}

	$free = array_filter(
		$rates,
		static fn( $r ) => 'free_shipping' === $r->get_method_id() || 0.0 === (float) $r->get_cost()
	);

	return $free ? $free : $rates;
}

/*
 * ---------------------------------------------------------------------
 * Cart strikethrough - regular price crossed out per item
 * ---------------------------------------------------------------------
 */

/**
 * Returns the tier's configured original (regular) price for a product,
 * if a bundle tier is currently active and a discount exists.
 * Statically cached per product per request.
 */
function inmx_get_original_price_for_item( int $pid ): float {
	static $cache = [];
	if ( array_key_exists( $pid, $cache ) ) {
		return $cache[ $pid ];
	}

	$map = inmx_qty_map();
	foreach ( inmx_get_offers() as $offer ) {
		$cat_id = (int) ( $offer['category_id'] ?? 0 );
		if ( ! $cat_id || ! inmx_in_cat( $pid, $cat_id ) ) {
			continue;
		}
		$tier = inmx_active_tier( $map[ $cat_id ] ?? 0, $offer['tiers'] ?? [] );
		if ( ! $tier ) {
			continue;
		}
		$orig  = (float) ( $tier['original_price'] ?? 0 );
		$price = (float) ( $tier['price_per_item'] ?? 0 );
		if ( $orig > 0 && $price > 0 && $orig > $price ) {
			return $cache[ $pid ] = $orig;
		}
	}
	return $cache[ $pid ] = 0.0;
}
