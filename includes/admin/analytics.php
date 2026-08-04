<?php
/**
 * Analytics: did the upsell work?
 *
 * REWRITTEN IN 1.2.0.
 *
 * The old report answered "how much money did we give away", leading with Total
 * Savings Given and Avg Savings / Order. That is a cost figure. This plugin
 * exists to move customers UP the quantity ladder, so the questions that matter
 * are how many got past the first rung, how many units they took, and which
 * orders came up short.
 *
 * Savings are still shown, small and last, because margin is real. They are not
 * the headline.
 *
 * Also fixes the cost problem noted in docs/ROADMAP.md: the old version called
 * wc_get_orders() with limit => -1 and return => objects, instantiating every
 * matching order. Everything shown here comes from one meta value per order, so
 * it is now a single indexed query against the order meta table.
 *
 * @package INMX\BMSM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the Analytics sub-tab.
 */
function inmx_render_analytics_section(): void {

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only period filter.
	$days = isset( $_GET['inmx_days'] ) ? (int) $_GET['inmx_days'] : 30;
	if ( ! in_array( $days, [ 7, 30, 90, 0 ], true ) ) {
		$days = 30;
	}

	$currency = get_woocommerce_currency_symbol();
	$base_url = admin_url( 'admin.php?page=wc-settings&tab=inmx_bundle&section=analytics' );
	$rows     = inmx_bmsm_fetch_bundle_rows( $days );

	echo '<h2>Analytics</h2>';
	echo '<p class="description" style="margin-bottom:16px">How far customers climbed the quantity ladder on '
		. '<strong>processing</strong>, <strong>on-hold</strong> and <strong>completed</strong> orders.</p>';

	echo '<div style="display:flex;gap:6px;margin-bottom:26px;align-items:center">'
		. '<span style="font-size:12px;font-weight:600;color:#555;margin-right:4px">Period:</span>';
	foreach (
		[
			7  => 'Last 7 days',
			30 => 'Last 30 days',
			90 => 'Last 90 days',
			0  => 'All time',
		] as $d => $label
	) {
		$on = $days === $d;
		printf(
			'<a href="%s" style="padding:5px 14px;border-radius:4px;font-size:12px;font-weight:600;text-decoration:none;background:%s;color:%s;border:1px solid %s">%s</a>',
			esc_url( add_query_arg( 'inmx_days', $d, $base_url ) ),
			$on ? '#0073aa' : '#f0f0f0',
			$on ? '#fff' : '#444',
			$on ? '#0073aa' : '#ddd',
			esc_html( $label )
		);
	}
	echo '</div>';

	if ( ! $rows ) {
		echo '<div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:28px">'
			. '<p style="margin:0 0 8px;font-weight:600">Nothing recorded for this period yet.</p>'
			. '<p style="margin:0;color:#777">From version 1.2.0 a row is written for every order containing an offer category, including orders that bought only one item. '
			. 'Orders placed before 1.2.0 are mostly absent, because the earlier release recorded an order only when it earned a discount.</p>'
			. '</div>';
		return;
	}

	$all = inmx_bmsm_summarise( $rows );

	// Everything in range predates 1.2.0. Showing four zeroes here would look
	// like the offer sold nothing, when it means the old release never recorded
	// what this report needs.
	if ( 0 === $all['orders'] ) {
		printf(
			'<div style="background:#fff;border:1px solid #e0e0e0;border-left:4px solid #dba617;border-radius:8px;padding:24px">
				<p style="margin:0 0 8px;font-weight:600">%d order(s) in this period, all recorded before version 1.2.0.</p>
				<p style="margin:0 0 8px;color:#777">Those records hold only a discount figure. They cannot show an upsell rate, because the old release
				recorded an order only when it earned a discount - every single-item order, which is exactly the upsell that did not work, is missing. Counting
				them would show a success rate near 100%% whatever actually happened.</p>
				<p style="margin:0;color:#777">Discount recorded across them, measured against the regular price rather than the price charged: <strong>%s</strong>.
				The ladder fills in from the next order placed.</p>
			</div>',
			(int) $all['legacy'],
			esc_html( $currency . number_format( $all['legacy_saving'] ) )
		);
		return;
	}

	inmx_bmsm_render_tiles( $all, $currency );

	foreach ( $all['offers'] as $name => $o ) {
		inmx_bmsm_render_offer( (string) $name, $o, $currency );
	}
}

/*
 * ---------------------------------------------------------------------
 * Data
 * ---------------------------------------------------------------------
 */

/**
 * Read every recorded bundle row in the period, in one query.
 *
 * Handles both HPOS and the legacy post-meta storage.
 *
 * @param int $days 0 for all time.
 * @return array<int,array> Flat list of bundle records.
 */
function inmx_bmsm_fetch_bundle_rows( int $days ): array {
	global $wpdb;

	$statuses = [ 'wc-completed', 'wc-processing', 'wc-on-hold' ];
	$since    = $days > 0 ? gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) ) : '';

	$hpos = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

	$in = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

	if ( $hpos ) {
		$sql    = "SELECT m.meta_value
			FROM {$wpdb->prefix}wc_orders_meta m
			INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = m.order_id
			WHERE m.meta_key = '_inmx_bundle_discounts'
			  AND o.type = 'shop_order'
			  AND o.status IN ({$in})";
		$params = $statuses;
		if ( $since ) {
			$sql     .= ' AND o.date_created_gmt >= %s';
			$params[] = $since;
		}
	} else {
		$sql    = "SELECT m.meta_value
			FROM {$wpdb->postmeta} m
			INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
			WHERE m.meta_key = '_inmx_bundle_discounts'
			  AND p.post_type = 'shop_order'
			  AND p.post_status IN ({$in})";
		$params = $statuses;
		if ( $since ) {
			$sql     .= ' AND p.post_date_gmt >= %s';
			$params[] = $since;
		}
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$values = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );

	$rows = [];
	foreach ( (array) $values as $raw ) {
		$decoded = maybe_unserialize( $raw );
		if ( ! is_array( $decoded ) ) {
			continue;
		}
		foreach ( $decoded as $b ) {
			if ( is_array( $b ) ) {
				$rows[] = $b;
			}
		}
	}

	return $rows;
}

/**
 * Turn raw records into the numbers the report shows.
 *
 * @param array $rows Bundle records.
 * @return array
 */
function inmx_bmsm_summarise( array $rows ): array {

	$out = [
		'orders'        => 0,
		'upsold'        => 0,
		'units'         => 0,
		'revenue'       => 0.0,
		'savings'       => 0.0,
		'nearmiss'      => 0,
		'legacy'        => 0,
		'legacy_saving' => 0.0,
		'offers'        => [],
	];

	foreach ( $rows as $b ) {

		$name = sanitize_text_field( (string) ( $b['offer_name'] ?? 'Unknown' ) );
		$unit = (int) ( $b['items_count'] ?? 0 );
		$disc = (float) ( $b['discount'] ?? 0 );

		if ( ! isset( $out['offers'][ $name ] ) ) {
			$out['offers'][ $name ] = [
				'orders'        => 0,
				'upsold'        => 0,
				'units'         => 0,
				'revenue'       => 0.0,
				'savings'       => 0.0,
				'tiers'         => [],
				'nearmiss'      => 0,
				'legacy'        => 0,
				'legacy_saving' => 0.0,
			];
		}

		/*
		 * Records written before 1.2.0 are NOT comparable and are kept entirely
		 * separate. Two reasons, both of which would produce a confidently wrong
		 * headline if the two shapes were pooled:
		 *
		 * 1. They were written only when an order earned a discount, so every
		 *    single-item order - exactly the upsell that failed - is missing.
		 *    An upsell rate computed over them approaches 100% by construction.
		 *
		 * 2. They carry no revenue field, so averaging them in drags average
		 *    order revenue toward zero.
		 *
		 * Their discount was also measured against the configured regular price
		 * rather than the price actually charged, so even the savings figure is
		 * on a different basis. They are reported as their own line, labelled.
		 */
		if ( ! isset( $b['tier_index'] ) ) {
			++$out['legacy'];
			++$out['offers'][ $name ]['legacy'];
			$out['legacy_saving'] += $disc;
			$out['offers'][ $name ]['legacy_saving'] += $disc;
			continue;
		}

		$idx  = (int) $b['tier_index'];
		$rev  = (float) ( $b['revenue'] ?? 0 );
		$togo = (int) ( $b['units_to_next'] ?? 0 );

		++$out['orders'];
		++$out['offers'][ $name ]['orders'];
		$out['units']   += $unit;
		$out['savings'] += $disc;
		$out['revenue'] += $rev;
		$out['offers'][ $name ]['units']   += $unit;
		$out['offers'][ $name ]['savings'] += $disc;
		$out['offers'][ $name ]['revenue'] += $rev;

		$out['offers'][ $name ]['tiers'][ $idx ] = ( $out['offers'][ $name ]['tiers'][ $idx ] ?? 0 ) + 1;

		if ( $idx >= 2 ) {
			++$out['offers'][ $name ]['upsold'];
			++$out['upsold'];
		}
		if ( 1 === $togo ) {
			++$out['offers'][ $name ]['nearmiss'];
			++$out['nearmiss'];
		}
	}

	uasort(
		$out['offers'],
		static fn( $a, $b ) => ( $b['orders'] + $b['legacy'] ) <=> ( $a['orders'] + $a['legacy'] )
	);

	return $out;
}

/*
 * ---------------------------------------------------------------------
 * Rendering
 * ---------------------------------------------------------------------
 */

/**
 * Headline tiles.
 *
 * @param array  $a        Summary.
 * @param string $currency Currency symbol.
 */
function inmx_bmsm_render_tiles( array $a, string $currency ): void {

	$rate  = $a['orders'] > 0 ? round( $a['upsold'] / $a['orders'] * 100 ) : 0;
	$avg_u = $a['orders'] > 0 ? round( $a['units'] / $a['orders'], 1 ) : 0;
	$avg_r = $a['orders'] > 0 ? $a['revenue'] / $a['orders'] : 0;

	$tiles = [
		[ 'Bundle orders', number_format( $a['orders'] ), '#0073aa', 'Orders containing an offer category' ],
		[ 'Upsold past tier 1', $rate . '%', '#22a85e', $a['upsold'] . ' of ' . $a['orders'] . ' climbed at least one rung' ],
		[ 'Avg units / order', (string) $avg_u, '#7b4bc0', 'The number this plugin exists to raise' ],
		[ 'Avg bundle revenue', $currency . number_format( $avg_r ), '#c0392b', 'Per order, after the discount' ],
	];

	echo '<div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:14px">';
	foreach ( $tiles as [$label, $value, $color, $hint] ) {
		printf(
			'<div style="flex:1;min-width:190px;background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:16px 20px;border-top:3px solid %1$s">
				<div style="font-size:10.5px;font-weight:700;color:#888;text-transform:uppercase;letter-spacing:.9px;margin-bottom:6px">%2$s</div>
				<div style="font-size:24px;font-weight:800;color:%1$s">%3$s</div>
				<div style="font-size:11px;color:#999;margin-top:5px;line-height:1.35">%4$s</div>
			</div>',
			esc_attr( $color ),
			esc_html( $label ),
			esc_html( $value ),
			esc_html( $hint )
		);
	}
	echo '</div>';

	if ( $a['nearmiss'] > 0 ) {
		printf(
			'<div style="background:#fff8e5;border:1px solid #f0d98c;border-left:4px solid #dba617;border-radius:6px;padding:12px 16px;margin-bottom:26px">
				<strong>%d order%s finished one item short of the next tier.</strong>
				<span style="color:#666">That is where a sharper nudge on the cart or checkout widget has the most to work with.</span>
			</div>',
			(int) $a['nearmiss'],
			1 === $a['nearmiss'] ? '' : 's'
		);
	}

	printf(
		'<p style="color:#888;font-size:12px;margin:0 0 6px">Discount given away in this period: <strong>%s</strong>. That is the cost of the offer, not its result.</p>',
		esc_html( $currency . number_format( $a['savings'] ) )
	);

	if ( $a['legacy'] > 0 ) {
		printf(
			'<p style="color:#aaa;font-size:11.5px;margin:0 0 26px;line-height:1.5">A further <strong>%d</strong> order(s) in this period were recorded before version 1.2.0
			and are excluded from every figure above. They hold no tier or revenue detail, and they were only written when an order earned a discount, so including them
			would overstate the upsell rate and understate revenue per order. Discount recorded across them, on the older regular-price basis: %s.</p>',
			(int) $a['legacy'],
			esc_html( $currency . number_format( $a['legacy_saving'] ) )
		);
	} else {
		echo '<div style="margin-bottom:26px"></div>';
	}
}

/**
 * Per-offer ladder.
 *
 * @param string $name     Offer name.
 * @param array  $o        Offer summary.
 * @param string $currency Currency symbol.
 */
function inmx_bmsm_render_offer( string $name, array $o, string $currency ): void {

	$rate  = $o['orders'] > 0 ? round( $o['upsold'] / $o['orders'] * 100 ) : 0;
	$avg_u = $o['orders'] > 0 ? round( $o['units'] / $o['orders'], 1 ) : 0;

	printf(
		'<h3 style="border-bottom:1px solid #ddd;padding-bottom:8px;margin-top:28px">%s
			<span style="font-weight:400;color:#888;font-size:13px"> &mdash; %d orders, %d%% upsold, %s avg units</span>
		</h3>',
		esc_html( $name ),
		(int) $o['orders'],
		(int) $rate,
		esc_html( (string) $avg_u )
	);

	if ( empty( $o['tiers'] ) ) {
		echo '<p style="color:#888">No tier detail recorded yet for this offer in this period.</p>';
		if ( $o['legacy'] > 0 ) {
			printf(
				'<p style="color:#aaa;font-size:12px">%d order(s) here predate version 1.2.0 and carry no tier information.</p>',
				(int) $o['legacy']
			);
		}
		return;
	}

	ksort( $o['tiers'] );
	$max = max( $o['tiers'] );

	echo '<table class="widefat striped" style="max-width:820px"><thead><tr style="background:#f6f7f7">'
		. '<th style="padding:10px 12px;width:110px">Reached</th>'
		. '<th style="padding:10px 12px">Share of orders</th>'
		. '<th style="padding:10px 12px;width:100px;text-align:center">Orders</th>'
		. '<th style="padding:10px 12px;width:90px;text-align:right">%</th>'
		. '</tr></thead><tbody>';

	foreach ( $o['tiers'] as $idx => $count ) {
		$pct   = $o['orders'] > 0 ? round( $count / $o['orders'] * 100 ) : 0;
		$width = $max > 0 ? max( 2, (int) round( $count / $max * 100 ) ) : 0;
		$label = 0 === $idx ? 'No tier' : 'Tier ' . $idx;
		$color = 0 === $idx ? '#c0392b' : ( 1 === $idx ? '#dba617' : '#22a85e' );

		printf(
			'<tr>
				<td style="padding:9px 12px;font-weight:700;color:%1$s">%2$s</td>
				<td style="padding:9px 12px"><div style="background:%1$s;height:14px;width:%3$d%%;border-radius:3px;min-width:3px"></div></td>
				<td style="padding:9px 12px;text-align:center">%4$d</td>
				<td style="padding:9px 12px;text-align:right;color:#666">%5$d%%</td>
			</tr>',
			esc_attr( $color ),
			esc_html( $label ),
			(int) $width,
			(int) $count,
			(int) $pct
		);
	}

	echo '</tbody></table>';

	$bits = [];
	if ( $o['nearmiss'] > 0 ) {
		$bits[] = sprintf( '<strong>%d</strong> finished one item short of the next tier', (int) $o['nearmiss'] );
	}
	$bits[] = 'revenue ' . esc_html( $currency . number_format( $o['revenue'] ) );
	$bits[] = 'discount given ' . esc_html( $currency . number_format( $o['savings'] ) );
	if ( $o['legacy'] > 0 ) {
		$bits[] = sprintf( '%d pre-1.2.0 order(s) excluded from the figures above', (int) $o['legacy'] );
	}

	echo '<p style="color:#777;font-size:12px;margin-top:8px">' . wp_kses_post( implode( ' &nbsp;&middot;&nbsp; ', $bits ) ) . '</p>';
}
