<?php
/**
 * Widget renderers: the container, Goal Circles (w3) and Pricing Table (w4).
 *
 * Carried over unchanged from the WPCode snippet.
 *
 * @package INMX\BMSM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render every offer's widget for one display context.
 *
 * @param string $context       One of product|cart|checkout|mini.
 * @param int    $product_pg_id On a product page, the product being viewed. 0 elsewhere.
 */
function inmx_render_widget( string $context, int $product_pg_id = 0 ): void {

	$map      = inmx_qty_map();
	$registry = inmx_widget_registry();
	$currency = get_woocommerce_currency_symbol();

	printf( '<div class="inmx-upsell-wrap inmx-ctx-%s">', esc_attr( $context ) );

	foreach ( inmx_get_offers() as $offer ) {

		$cat_id = (int) ( $offer['category_id'] ?? 0 );
		if ( ! $cat_id ) {
			continue;
		}

		$widget_type = $offer[ 'widget_' . $context ] ?? '';
		if ( ! isset( $registry[ $widget_type ] ) ) {
			continue;
		}

		if ( $product_pg_id > 0 && ! inmx_in_cat( $product_pg_id, $cat_id ) ) {
			continue;
		}

		$qty   = $map[ $cat_id ] ?? 0;
		$tiers = $offer['tiers'] ?? [];
		$next  = inmx_next_tier( $qty, $tiers );
		$actv  = inmx_active_tier( $qty, $tiers );

		if ( 0 === $product_pg_id && $qty < 1 && 'widget4' !== $widget_type ) {
			continue;
		}
		if ( ! $next && ! $actv && 'widget4' !== $widget_type ) {
			continue;
		}

		$cat_term  = get_term( $cat_id, 'product_cat' );
		$cat_name  = ( $cat_term && ! is_wp_error( $cat_term ) )
					? esc_html( $cat_term->name ) : 'item';
		$term_link = get_term_link( $cat_id, 'product_cat' );
		$add_url   = ! empty( $offer['redirect_url'] )
					? esc_url( $offer['redirect_url'] )
					: ( ! is_wp_error( $term_link ) ? esc_url( $term_link ) : '#' );

		$ctx = [
			'qty'      => $qty,
			'tiers'    => $tiers,
			'cat_id'   => $cat_id,
			'cat_name' => $cat_name,
			'add_url'  => $add_url,
			'currency' => $currency,
			'offer'    => $offer,
		];

		$fn = $registry[ $widget_type ]['render'];
		if ( is_callable( $fn ) ) {
			$fn( $ctx );
		}
	}

	echo '</div>'; // .inmx-upsell-wrap
}

/**
 * Widget 3 - Goal Circles.
 *
 * A row of product thumbnails filling toward the highest tier, plus one perk line.
 */
function inmx_render_w3( array $ctx ): void {
	$qty      = (int) $ctx['qty'];
	$tiers    = $ctx['tiers'];
	$cat_id   = (int) $ctx['cat_id'];
	$cat_name = $ctx['cat_name'];
	$add_url  = $ctx['add_url'];

	$best = null;
	foreach ( $tiers as $t ) {
		if ( null === $best || (int) $t['qty'] > (int) $best['qty'] ) {
			$best = $t;
		}
	}
	if ( ! $best ) {
		return;
	}

	/**
	 * The tier widget 3 aims its circles and its one line at. Default: the highest tier.
	 * A shop can aim at a nearer one, for example the first tier that gives free delivery.
	 *
	 * @param array $best  The highest tier.
	 * @param array $tiers Every tier of the offer.
	 * @param int   $qty   Items of the category in the cart.
	 */
	$target = apply_filters( 'inmx_bmsm_w3_target_tier', $best, $tiers, $qty );
	if ( is_array( $target ) && isset( $target['qty'] ) ) {
		$best = $target;
	}

	$best_qty  = (int) $best['qty'];
	$reached   = $qty >= $best_qty;
	$cap       = 4;
	$filled    = min( $qty, $cap );
	$total_cap = min( $best_qty, $cap );
	$empty     = max( 0, $total_cap - $filled );
	$thumbs    = inmx_build_thumbs( $cat_id, $cap, true );
	$msg       = inmx_build_perk_msg( $best, $qty, $cat_name, $reached ? 'active' : 'next' );

	printf( '<div class="inmx-upsell-block inmx-w3-block%s">', $reached ? ' inmx-upsell-success' : '' );
	echo '<div class="inmx-w3-row">';
	echo '<div class="inmx-w3-circles">';

	if ( $reached ) {
		foreach ( $thumbs as $thumb ) {
			printf(
				'<div class="inmx-circle inmx-circle-filled" title="%s"><img src="%s" alt="%s" loading="lazy"></div>',
				esc_attr( $thumb['name'] ),
				esc_url( $thumb['url'] ),
				esc_attr( $thumb['name'] )
			);
		}
	} else {
		for ( $i = 0; $i < $filled; $i++ ) {
			if ( isset( $thumbs[ $i ] ) ) {
				printf(
					'<div class="inmx-circle inmx-circle-filled" title="%s"><img src="%s" alt="%s" loading="lazy"></div>',
					esc_attr( $thumbs[ $i ]['name'] ),
					esc_url( $thumbs[ $i ]['url'] ),
					esc_attr( $thumbs[ $i ]['name'] )
				);
			} else {
				echo '<div class="inmx-circle inmx-circle-filled"></div>';
			}
		}
		if ( $empty > 0 ) {
			printf(
				'<a href="%s" class="inmx-circle inmx-circle-cta" aria-label="Add more %s to unlock bundle"><span>+</span></a>',
				esc_url( $add_url ),
				esc_attr( $cat_name )
			);
			for ( $i = 1; $i < $empty; $i++ ) {
				echo '<div class="inmx-circle inmx-circle-empty"></div>';
			}
		}
	}

	echo '</div>'; // .inmx-w3-circles
	printf( '<p class="inmx-strip-msg">%s</p>', wp_kses_post( $msg ) );
	echo '</div></div>'; // .inmx-w3-row  .inmx-w3-block
}

/**
 * Widget 4 - Pricing Table.
 *
 * A vertical stepper of every tier, marking each done / next / upcoming.
 */
function inmx_render_w4( array $ctx ): void {
	$qty      = (int) $ctx['qty'];
	$tiers    = $ctx['tiers'];
	$cat_id   = (int) $ctx['cat_id'];
	$cat_name = $ctx['cat_name'];
	$add_url  = $ctx['add_url'];
	$currency = $ctx['currency'];
	$offer    = $ctx['offer'];

	usort( $tiers, fn( $a, $b ) => (int) $a['qty'] <=> (int) $b['qty'] );

	$found_next = false;
	$resolved   = [];
	foreach ( $tiers as $t ) {
		$t_qty  = (int) ( $t['qty'] ?? 1 );
		$needed = max( 0, $t_qty - $qty );
		if ( $qty >= $t_qty ) {
			$state = 'done';
		} elseif ( ! $found_next ) {
			$state      = 'next';
			$found_next = true;
		} else {
			$state = 'upcoming';
		}
		$resolved[] = array_merge(
			$t,
			[
				'state'  => $state,
				'needed' => $needed,
			]
		);
	}

	$auto_highlight = ! empty( $offer['auto_highlight'] );
	$offer_name     = ! empty( $offer['name'] ) ? esc_html( $offer['name'] ) : '';
	$thumbs         = inmx_build_thumbs( $cat_id, 4, true );
	$tier_count     = count( $resolved );

	$svg_lock   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
	$svg_unlock = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>';

	if ( $offer_name ) {
		echo '<span class="title">' . esc_html( $offer['name'] ) . '</span>';
	}

	echo '<div class="inmx-stepper">';

	foreach ( $resolved as $index => $tier ) {
		$state         = $tier['state'];
		$t_qty         = (int) ( $tier['qty'] ?? 1 );
		$sale_price    = (float) ( $tier['price_per_item'] ?? 0 );
		$free_delivery = ! empty( $tier['free_delivery'] );
		$gift_name     = trim( $tier['free_gift_product_name'] ?? '' );
		$gift_id       = (int) ( $tier['free_gift_product_id'] ?? 0 );
		$badge         = trim( $tier['badge_label'] ?? '' );
		$highlight     = ! empty( $tier['highlight'] );
		$needed        = (int) ( $tier['needed'] ?? 0 );
		$sale_total    = $sale_price > 0 ? $t_qty * $sale_price : 0;
		$is_last       = ( $index === $tier_count - 1 );

		$pack_label = trim( $tier['pack_label'] ?? '' );
		if ( empty( $pack_label ) ) {
			$pack_label = 'Any ' . $t_qty . ' ' . ucfirst( $cat_name );
		}

		if ( $gift_name ) {
			$gift_img = $gift_id > 0 ? get_the_post_thumbnail_url( $gift_id, [ 36, 36 ] ) : '';
			if ( ! $gift_img ) {
				$gift_img = wc_placeholder_img_src( 'thumbnail' );
			}
		} else {
			$gift_img = '';
		}

		echo '<div class="inmx-step">';

		// Left column: dot + connector.
		echo '<div class="inmx-step-left">';
		if ( 'done' === $state ) {
			echo '<div class="inmx-dot inmx-dot--done"><span class="inmx-dot-check">✓</span></div>';
		} elseif ( 'next' === $state ) {
			echo '<div class="inmx-dot inmx-dot--next"></div>';
		} else {
			echo '<div class="inmx-dot inmx-dot--upcoming"></div>';
		}
		if ( ! $is_last ) {
			echo '<div class="inmx-connector' . ( 'done' === $state ? ' inmx-connector--filled' : '' ) . '"></div>';
		}
		echo '</div>'; // .inmx-step-left

		// Right column: nudge + card.
		echo '<div class="inmx-step-body">';

		if ( 'next' === $state && $needed > 0 ) {
			echo '<a href="' . esc_url( $add_url ) . '" class="inmx-cta-inline">'
				. '<span class="inmx-lock-icon inmx-lock--next">' . $svg_lock . '</span>'
				. 'Add ' . (int) $needed . ' more ' . esc_html( inmx_item_label( $cat_name, (int) $needed ) ) . ' to unlock'
				. '</a>';
		} elseif ( 'next' === $state && 0 === $needed ) {
			echo '<div class="inmx-nudge inmx-nudge--ready">✓ Unlocked! Select below to claim 🎉</div>';
		}

		$is_featured = ( $highlight || ( $auto_highlight && 'next' === $state ) ) && 'done' !== $state;
		$card_class  = 'inmx-card inmx-card--' . $state . ( $is_featured ? ' inmx-card--featured' : '' );

		if ( 'upcoming' === $state && '#' !== $add_url ) {
			echo '<a href="' . esc_url( $add_url ) . '" class="inmx-card-link">';
		}

		echo '<div class="' . esc_attr( $card_class ) . '">';

		if ( 'done' === $state ) {
			echo '<div class="inmx-done-row">'
				. '<span class="inmx-done-label"><span class="inmx-unlock-icon">' . $svg_unlock . '</span>' . esc_html( $pack_label ) . '</span>';
			if ( $sale_total > 0 ) {
				echo '<span class="inmx-done-price">' . esc_html( $currency . number_format( $sale_total ) ) . '</span>';
			}
			echo '</div>';
		} else {
			$corner_badges = [];
			if ( $badge ) {
				$corner_badges[] = '<span class="inmx-badge inmx-badge--label">' . esc_html( $badge ) . '</span>';
			}
			if ( $free_delivery ) {
				$corner_badges[] = '<span class="inmx-badge inmx-badge--delivery">'
					. '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="13" height="13"><rect x="1" y="3" width="15" height="13" rx="2"/><path d="M16 8h4l3 5v4h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>'
					. ' Free Delivery</span>';
			}
			if ( $corner_badges ) {
				echo '<div class="inmx-corner-badges">' . implode( '', $corner_badges ) . '</div>';
			}

			$lock_class = 'inmx-lock-icon' . ( 'next' === $state ? ' inmx-lock--next' : '' );
			echo '<div class="inmx-tier-row">'
				. '<div class="inmx-tier-info">'
				. '<span class="inmx-tier-label"><span class="' . esc_attr( $lock_class ) . '">' . $svg_lock . '</span>' . esc_html( $pack_label ) . '</span>'
				. '</div>'
				. '<div class="inmx-tier-price">';
			if ( $sale_price > 0 ) {
				echo '<span class="inmx-total-line">Each</span>'
					. '<span class="inmx-sale"><span class="inmx-currency">' . esc_html( $currency ) . '</span>' . esc_html( number_format( $sale_price ) ) . '</span>';
				if ( $sale_total > 0 ) {
					echo '<span class="inmx-total-line">Total ' . esc_html( $currency . number_format( $sale_total ) ) . '</span>';
				}
			}
			echo '</div></div>'; // .inmx-tier-price  .inmx-tier-row

			// Circles row - shown only for the 'next' tier card.
			if ( 'next' === $state ) {
				$cap_c   = min( $t_qty, 4 );
				$filled  = min( $qty, $cap_c );
				$empty_c = max( 0, $cap_c - $filled );

				echo '<div class="inmx-circles-row">';
				for ( $i = 0; $i < $filled; $i++ ) {
					if ( isset( $thumbs[ $i ] ) ) {
						echo '<div class="inmx-circle inmx-circle-filled"><img src="' . esc_url( $thumbs[ $i ]['url'] ) . '" alt="' . esc_attr( $thumbs[ $i ]['name'] ) . '" loading="lazy"></div>';
					} else {
						echo '<div class="inmx-circle inmx-circle-filled"></div>';
					}
				}
				if ( $empty_c > 0 ) {
					echo '<a href="' . esc_url( $add_url ) . '" class="inmx-circle inmx-circle-cta" aria-label="Add more ' . esc_attr( $cat_name ) . '"><span>+</span></a>';
					for ( $i = 1; $i < $empty_c; $i++ ) {
						echo '<div class="inmx-circle inmx-circle-empty"></div>';
					}
				}
				echo '</div>'; // .inmx-circles-row
			}

			if ( $gift_name ) {
				// After the circles row, whose last circle is already a "+" button, a second "+" read as "+ +".
				$after_cta = 'next' === $state && min( $t_qty, 4 ) > min( $qty, min( $t_qty, 4 ) );
				echo '<div class="inmx-gift-row">'
					. ( $after_cta ? '' : '<span class="inmx-gift-plus">+</span>' )
					. '<img src="' . esc_url( $gift_img ) . '" alt="' . esc_attr( $gift_name ) . '" class="inmx-gift-img" loading="lazy">'
					. '<span class="inmx-gift-text">Free ' . esc_html( $gift_name ) . '</span>'
					. '</div>';
			}
		}

		echo '</div>'; // .inmx-card

		if ( 'upcoming' === $state && '#' !== $add_url ) {
			echo '</a>'; // .inmx-card-link
		}

		/**
		 * Runs under each tier's card, so a shop can add a line to one tier.
		 *
		 * @param array $tier  The tier, with its state ('done', 'next', 'upcoming') and how many are still needed.
		 * @param array $ctx   The widget's context: qty, tiers, category, offer.
		 */
		do_action( 'inmx_bmsm_after_tier_card', $tier, $ctx );

		echo '</div>'; // .inmx-step-body
		echo '</div>'; // .inmx-step
	}

	echo '</div>'; // .inmx-stepper
}
