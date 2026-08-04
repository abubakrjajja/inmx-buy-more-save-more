<?php
/**
 * Analytics sub-tab: bundle discount totals and per-offer breakdown.
 *
 * Carried over unchanged from the WPCode snippet.
 *
 * KNOWN COST, unchanged from the snippet and deliberately not touched in 1.0.0:
 * the query below loads every matching order as a full object with limit => -1.
 * On a store with a large order history that is a heavy page. It only runs when
 * an admin opens this sub-tab, never on the front end. Scheduled to become an
 * aggregate query in a later release; see docs/ROADMAP.md.
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

	$args = [
		'meta_key'     => '_inmx_bundle_discounts', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'meta_compare' => 'EXISTS',
		'limit'        => -1,
		'status'       => [ 'wc-completed', 'wc-processing', 'wc-on-hold' ],
		'return'       => 'objects',
		'orderby'      => 'date',
		'order'        => 'DESC',
	];
	if ( $days > 0 ) {
		$args['date_created'] = '>' . gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
	}

	$orders         = wc_get_orders( $args );
	$total_orders   = count( $orders );
	$total_discount = 0.0;
	$by_offer       = [];

	foreach ( $orders as $order ) {
		$bundles = $order->get_meta( '_inmx_bundle_discounts', true );
		if ( ! is_array( $bundles ) ) {
			continue;
		}
		foreach ( $bundles as $b ) {
			$name            = sanitize_text_field( $b['offer_name'] ?? 'Unknown' );
			$disc            = (float) ( $b['discount'] ?? 0 );
			$tq              = (int) ( $b['tier_qty'] ?? 0 );
			$ic              = (int) ( $b['items_count'] ?? 0 );
			$total_discount += $disc;
			if ( ! isset( $by_offer[ $name ] ) ) {
				$by_offer[ $name ] = [
					'orders'   => 0,
					'discount' => 0.0,
					'items'    => 0,
					'tiers'    => [],
				];
			}
			++$by_offer[ $name ]['orders'];
			$by_offer[ $name ]['discount'] += $disc;
			$by_offer[ $name ]['items']    += $ic;
			if ( $tq ) {
				$by_offer[ $name ]['tiers'][ $tq ] = ( $by_offer[ $name ]['tiers'][ $tq ] ?? 0 ) + 1;
			}
		}
	}

	$avg = $total_orders > 0 ? $total_discount / $total_orders : 0;
	?>
	<h2>Analytics</h2>
	<p class="description" style="margin-bottom:16px">
		Counts <strong>processing</strong>, <strong>on-hold</strong>, and <strong>completed</strong>
		orders where a bundle discount was applied at checkout.
	</p>
	<div style="display:flex;gap:6px;margin-bottom:26px;align-items:center">
		<span style="font-size:12px;font-weight:600;color:#555;margin-right:4px">Period:</span>
		<?php
		foreach (
			[
				7  => 'Last 7 days',
				30 => 'Last 30 days',
				90 => 'Last 90 days',
				0  => 'All time',
			] as $d => $label
		) :
			$active = $days === $d;
			?>
		<a href="<?php echo esc_url( add_query_arg( 'inmx_days', $d, $base_url ) ); ?>"
			style="padding:5px 14px;border-radius:4px;font-size:12px;font-weight:600;text-decoration:none;
					background:<?php echo $active ? '#0073aa' : '#f0f0f0'; ?>;
					color:<?php echo $active ? '#fff' : '#444'; ?>;
					border:1px solid <?php echo $active ? '#0073aa' : '#ddd'; ?>">
			<?php echo esc_html( $label ); ?>
		</a>
		<?php endforeach; ?>
	</div>
	<div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:30px">
		<?php
		foreach (
			[
				[ 'Buy More Save More Orders', (string) $total_orders, '#0073aa' ],
				[ 'Total Savings Given', $currency . number_format( $total_discount ), '#22a85e' ],
				[ 'Avg Savings / Order', $currency . number_format( $avg ), '#c0392b' ],
			] as [ $label, $value, $color ]
		) :
			?>
		<div style="flex:1;min-width:170px;max-width:240px;background:#fff;border:1px solid #e0e0e0;
					border-radius:8px;padding:16px 20px;border-top:3px solid <?php echo esc_attr( $color ); ?>">
			<div style="font-size:10.5px;font-weight:700;color:#888;text-transform:uppercase;letter-spacing:.9px;margin-bottom:6px">
				<?php echo esc_html( $label ); ?>
			</div>
			<div style="font-size:22px;font-weight:800;color:<?php echo esc_attr( $color ); ?>">
				<?php echo esc_html( $value ); ?>
			</div>
		</div>
		<?php endforeach; ?>
	</div>
	<?php if ( ! empty( $by_offer ) ) : ?>
	<h3 style="border-bottom:1px solid #ddd;padding-bottom:8px;margin-top:0">Per-Offer Breakdown</h3>
	<table class="widefat striped" style="max-width:900px">
		<thead><tr style="background:#f6f7f7">
			<th style="padding:10px 12px">Offer</th>
			<th style="padding:10px 12px;width:90px;text-align:center">Orders</th>
			<th style="padding:10px 12px;width:100px;text-align:center">Items Sold</th>
			<th style="padding:10px 12px;width:150px;text-align:right">Total Savings Given</th>
			<th style="padding:10px 12px;width:170px;text-align:center">Most Used Tier</th>
		</tr></thead>
		<tbody>
		<?php
		foreach ( $by_offer as $name => $data ) :
			$top_tier  = 0;
			$top_count = 0;
			foreach ( $data['tiers'] as $tq => $cnt ) {
				if ( $cnt > $top_count ) {
					$top_count = $cnt;
					$top_tier  = $tq;
				}
			}
			arsort( $data['tiers'] );
			?>
		<tr>
			<td style="padding:10px 12px;font-weight:600"><?php echo esc_html( $name ); ?></td>
			<td style="padding:10px 12px;text-align:center"><?php echo (int) $data['orders']; ?></td>
			<td style="padding:10px 12px;text-align:center"><?php echo (int) $data['items']; ?></td>
			<td style="padding:10px 12px;text-align:right;font-weight:700;color:#22a85e">
				<?php echo esc_html( $currency . number_format( $data['discount'] ) ); ?>
			</td>
			<td style="padding:10px 12px;text-align:center">
				<?php if ( $top_tier ) : ?>
					<span style="background:#e8f4ff;color:#0073aa;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700">
						<?php echo (int) $top_tier; ?>+ items
					</span>
					<span style="font-size:11px;color:#aaa;margin-left:3px"><?php echo (int) $top_count; ?>&times;</span>
				<?php else : ?>
					<span style="color:#ccc">-</span>
				<?php endif; ?>
			</td>
		</tr>
			<?php if ( count( $data['tiers'] ) > 1 ) : ?>
		<tr>
			<td colspan="5" style="padding:4px 12px 10px;background:#fafafa">
				<span style="font-size:10.5px;color:#888;margin-right:8px">Tier distribution:</span>
				<?php foreach ( $data['tiers'] as $tq => $cnt ) : ?>
				<span style="display:inline-block;background:#f0f0f0;color:#555;padding:2px 8px;border-radius:20px;font-size:10.5px;font-weight:600;margin-right:4px">
					<?php echo (int) $tq; ?>+ items &mdash; <?php echo (int) $cnt; ?>&times;
				</span>
				<?php endforeach; ?>
			</td>
		</tr>
				<?php endif; ?>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php else : ?>
	<p style="color:#888;padding:24px 0">No orders found for this period. Stats appear here once customers complete checkout with an active deal applied.</p>
		<?php
	endif;
}
