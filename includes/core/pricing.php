<?php
/**
 * Pricing engine: tier-level gift management and per-item tier prices.
 *
 * Carried over unchanged from the WPCode snippet.
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
 * Static $busy prevents re-entrant loop: remove_cart_item → calculate_totals → here.
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
 * Priority 10: Apply tier prices via set_price().
 * Never written to DB - recalculated fresh on every cart update.
 */
add_action( 'woocommerce_before_calculate_totals', 'inmx_apply_prices', 10 );

/**
 * Overwrite cart item prices with the active tier price, and zero the gifts.
 */
function inmx_apply_prices( WC_Cart $cart ): void {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}

	$map       = inmx_qty_map( $cart );
	$gift_ids  = inmx_gift_ids();
	$price_map = []; // cat_id => tier_price.

	foreach ( inmx_get_offers() as $offer ) {
		$cat_id = (int) ( $offer['category_id'] ?? 0 );
		if ( ! $cat_id ) {
			continue;
		}
		$tier = inmx_active_tier( $map[ $cat_id ] ?? 0, $offer['tiers'] ?? [] );
		if ( $tier && isset( $tier['price_per_item'] ) && $tier['price_per_item'] > 0 ) {
			$price_map[ $cat_id ] = (float) $tier['price_per_item'];
		}
	}

	foreach ( $cart->get_cart() as $item ) {
		$pid  = (int) $item['product_id'];
		$prod = $item['data'];

		if ( in_array( $pid, $gift_ids, true ) ) {
			$prod->set_price( 0 );
			continue;
		}
		foreach ( $price_map as $cat_id => $price ) {
			if ( inmx_in_cat( $pid, $cat_id ) ) {
				$prod->set_price( $price );
				break;
			}
		}
	}
}

/*
 * ─────────────────────────────────────────────────────────────────────
 * Cart strikethrough - original price crossed out per item
 * ─────────────────────────────────────────────────────────────────────
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
