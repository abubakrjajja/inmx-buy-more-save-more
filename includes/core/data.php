<?php
/**
 * Data helpers: offers, widget registry, cart maths, category matching, perk text.
 *
 * Carried over unchanged from the WPCode snippet "INMX — Buy More Save More"
 * (Bundle Upsell System v6). Behaviour here is deliberately identical to the
 * snippet so that the plugin migration can be verified as a pure repackage.
 *
 * @package INMX\BMSM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All configured offers.
 *
 * Reads the same `inmx_offers` option the WPCode snippet used, so a site
 * migrating from the snippet keeps its configuration with no import step.
 */
function inmx_get_offers(): array {
	static $o = null;
	if ( null !== $o ) {
		return $o;
	}
	$o = (array) get_option( 'inmx_offers', [] );
	return $o;
}

/**
 * Single source of truth for all valid widget type slugs and their admin labels.
 * To add a new widget type, add one entry here. All other code reads from this registry.
 */
function inmx_widget_registry(): array {
	return [
		'widget3' => [
			'label'  => 'Goal Circles',
			'render' => 'inmx_render_w3',
		],
		'widget4' => [
			'label'  => 'Pricing Table',
			'render' => 'inmx_render_w4',
		],
	];
}

/**
 * All gift product IDs across all tiers of all offers.
 * Used to: exclude from qty count, set price=0, style gift rows.
 */
function inmx_gift_ids(): array {
	static $ids = null;
	if ( null !== $ids ) {
		return $ids;
	}
	$ids = [];
	foreach ( inmx_get_offers() as $offer ) {
		foreach ( $offer['tiers'] ?? [] as $tier ) {
			$gid = (int) ( $tier['free_gift_product_id'] ?? 0 );
			if ( $gid ) {
				$ids[] = $gid;
			}
		}
	}
	$ids = array_values( array_unique( array_filter( $ids ) ) );
	return $ids;
}

/**
 * Build the thumbnail array for a cart category widget.
 *
 * @param int  $cat_id     Category to filter items by.
 * @param int  $cap        Max entries to return. 0 = no cap.
 * @param bool $expand_qty If true, one slot is added per unit of quantity (widget3/widget4 mode).
 *                         If false, one slot per cart item regardless of quantity (full/mini mode).
 */
function inmx_build_thumbs( int $cat_id, int $cap = 0, bool $expand_qty = false ): array {
	$cart = WC()->cart;
	if ( ! $cart instanceof WC_Cart ) {
		return [];
	}

	$gift_ids = inmx_gift_ids();
	$thumbs   = [];

	foreach ( $cart->get_cart() as $item ) {
		$pid = (int) $item['product_id'];
		if ( in_array( $pid, $gift_ids, true ) ) {
			continue;
		}
		if ( ! inmx_in_cat( $pid, $cat_id ) ) {
			continue;
		}

		$src = get_the_post_thumbnail_url( $pid, 'woocommerce_thumbnail' );
		if ( ! $src ) {
			$src = wc_placeholder_img_src( 'woocommerce_thumbnail' );
		}

		$entry = [
			'url'  => esc_url( $src ),
			'name' => esc_attr( get_the_title( $pid ) ),
		];

		if ( $expand_qty ) {
			$remaining = ( $cap > 0 ) ? $cap - count( $thumbs ) : PHP_INT_MAX;
			$repeat    = min( (int) $item['quantity'], $remaining );
			for ( $r = 0; $r < $repeat; $r++ ) {
				$thumbs[] = $entry;
			}
		} else {
			$thumbs[] = $entry;
		}

		if ( $cap > 0 && count( $thumbs ) >= $cap ) {
			break;
		}
	}

	return $thumbs;
}

/**
 * Category membership check including ancestor categories.
 * Static cache: O(1) on repeated calls for the same (pid, cat_id) pair.
 */
function inmx_in_cat( int $pid, int $cat_id ): bool {
	static $cache = [];
	$k = "{$pid}_{$cat_id}";
	if ( isset( $cache[ $k ] ) ) {
		return $cache[ $k ];
	}
	if ( ! $cat_id ) {
		return $cache[ $k ] = false;
	}
	if ( has_term( $cat_id, 'product_cat', $pid ) ) {
		return $cache[ $k ] = true;
	}
	$terms = get_the_terms( $pid, 'product_cat' );
	if ( $terms && ! is_wp_error( $terms ) ) {
		foreach ( $terms as $t ) {
			if ( in_array( $cat_id, get_ancestors( $t->term_id, 'product_cat', 'taxonomy' ), true ) ) {
				return $cache[ $k ] = true;
			}
		}
	}
	return $cache[ $k ] = false;
}

/**
 * Single cart loop building category → qty map for every offer.
 * Gift products (from any tier) are excluded from counts.
 */
function inmx_qty_map( ?WC_Cart $cart = null ): array {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}

	$cart = $cart ?? WC()->cart;
	$map  = [];
	if ( ! $cart instanceof WC_Cart ) {
		return $map;
	}

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

	foreach ( $cart->get_cart() as $item ) {
		$pid = (int) $item['product_id'];
		if ( in_array( $pid, $gift_ids, true ) ) {
			continue;
		}
		foreach ( $cat_ids as $cat_id ) {
			if ( inmx_in_cat( $pid, $cat_id ) ) {
				$map[ $cat_id ] += (int) $item['quantity'];
			}
		}
	}
	return $map;
}

/**
 * Highest tier the given quantity already qualifies for.
 */
function inmx_active_tier( int $qty, array $tiers ): ?array {
	foreach ( array_reverse( $tiers ) as $t ) {
		if ( $qty >= (int) $t['qty'] ) {
			return $t;
		}
	}
	return null;
}

/**
 * Lowest tier the given quantity has not reached yet.
 */
function inmx_next_tier( int $qty, array $tiers ): ?array {
	foreach ( $tiers as $t ) {
		if ( (int) $t['qty'] > $qty ) {
			return $t;
		}
	}
	return null;
}

/**
 * Cart item key for a product ID, or null when it is not in the cart.
 */
function inmx_cart_key( WC_Cart $cart, int $pid ): ?string {
	foreach ( $cart->get_cart() as $key => $item ) {
		if ( (int) $item['product_id'] === $pid ) {
			return $key;
		}
	}
	return null;
}

/**
 * Build the perk message for a tier.
 *
 * $context  'next'   = upsell (customer needs to add more)
 *           'active' = success (this tier is already active)
 *
 * ── Quantity ────────────────────────────────────────────
 *   {needed}       items still needed to reach this tier
 *   {min_qty}      minimum quantity for this tier
 *   {current_qty}  how many are currently in cart
 *   {qty}          alias for {min_qty}
 *
 * ── Prices ──────────────────────────────────────────────
 *   {sale_price}    bundle/sale price per item
 *   {regular_price} original/regular price per item
 *   {sale_total}    total at bundle price  (min_qty × sale_price)
 *   {regular_total} total at regular price (min_qty × regular_price)
 *   {price}         alias for {sale_price}
 *   {total}         alias for {sale_total}
 *
 * ── Discounts ───────────────────────────────────────────
 *   {discount_percentage}  e.g. 38%
 *   {discount_amount}      saved per item (regular - sale)
 *   {discount_total}       total saved across min_qty items
 *
 * ── Other ───────────────────────────────────────────────
 *   {cat}       category name
 *   {gift}      gift product name
 *   {currency}  currency symbol (e.g. Rs)
 */
function inmx_build_perk_msg( array $tier, int $current_qty, string $cat_name, string $context ): string {
	$min_qty       = (int) ( $tier['qty'] ?? 1 );
	$sale_price    = (float) ( $tier['price_per_item'] ?? 0 );
	$regular_price = (float) ( $tier['original_price'] ?? 0 );
	$sale_total    = $sale_price > 0 ? $min_qty * $sale_price : 0;
	$regular_total = $regular_price > 0 ? $min_qty * $regular_price : 0;
	$needed        = max( 0, $min_qty - $current_qty );
	$currency      = get_woocommerce_currency_symbol();
	$gift_name     = esc_html( $tier['free_gift_product_name'] ?? '' );
	$delivery      = ! empty( $tier['free_delivery'] );
	$custom        = trim( $tier['custom_perk_text'] ?? '' );

	// Discount calculations.
	$discount_amount     = ( $regular_price > 0 && $sale_price > 0 )
							? max( 0.0, $regular_price - $sale_price ) : 0;
	$discount_total      = $discount_amount * $min_qty;
	$discount_percentage = ( $regular_price > 0 && $sale_price > 0 && $regular_price > $sale_price )
							? (int) round( ( 1 - $sale_price / $regular_price ) * 100 ) : 0;

	if ( $custom ) {
		$replaced = str_replace(
			[
				// Quantity.
				'{needed}',
				'{min_qty}',
				'{current_qty}',
				'{qty}',
				// Individual prices.
				'{sale_price}',
				'{regular_price}',
				'{price}',
				// Totals.
				'{sale_total}',
				'{regular_total}',
				'{total}',
				// Discounts.
				'{discount_percentage}',
				'{discount_amount}',
				'{discount_total}',
				// Other.
				'{cat}',
				'{gift}',
				'{currency}',
			],
			[
				// Quantity.
				$needed,
				$min_qty,
				$current_qty,
				$min_qty,
				// Individual prices.
				number_format( $sale_price ),
				number_format( $regular_price ),
				number_format( $sale_price ),
				// Totals.
				number_format( $sale_total ),
				number_format( $regular_total ),
				number_format( $sale_total ),
				// Discounts.
				$discount_percentage . '%',
				number_format( $discount_amount ),
				number_format( $discount_total ),
				// Other.
				$cat_name,
				$gift_name,
				$currency,
			],
			$custom
		);
		return wp_kses_post( $replaced );
	}

	// ── Auto-generated default ──────────────────────────
	if ( 'active' === $context ) {
		$parts = [];
		if ( $sale_price > 0 ) {
			$parts[] = esc_html( $currency . number_format( $sale_price ) ) . '/each';
		}
		if ( $delivery ) {
			$parts[] = 'Free Delivery';
		}
		if ( $gift_name ) {
			$parts[] = 'Free ' . $gift_name;
		}

		$line = $parts ? implode( ' &nbsp;+&nbsp; ', $parts ) : 'Best deal applied';

		return '💝 <strong class="inmx-perk-anim">Best deal applied!</strong> ' . $line . ' 🎉';
	}

	// 'next' context — high urgency format.
	$parts = [];
	if ( $sale_price > 0 ) {
		$parts[] = 'unlock <strong class="inmx-perk-anim">'
					. esc_html( $currency . number_format( $sale_price ) ) . '/each</strong>';
	}
	if ( $delivery ) {
		$parts[] = 'Free Delivery';
	}
	if ( $gift_name ) {
		$parts[] = 'Free ' . $gift_name;
	}

	$unlock = $parts ? implode( ' &nbsp;+&nbsp; ', $parts ) : '';

	return 'Add <strong>' . $needed . ' more</strong> &rarr; '
			. ( $unlock ? $unlock . ' 🔥' : 'unlock the bundle deal 🔥' );
}
