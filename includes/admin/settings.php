<?php
/**
 * WooCommerce settings tab: Offers editor and save handler.
 *
 * Carried over from the WPCode snippet. The only structural change is that the
 * ~300-line admin editor script now loads from assets/js/admin.js instead of
 * being printed into admin_footer on every wp-admin page load.
 *
 * Nonce handling: this tab saves through WooCommerce's own settings pipeline.
 * WC_Admin_Settings::save() verifies the `woocommerce-settings` nonce before it
 * fires `woocommerce_settings_save_{tab}`, so the save handler below cannot be
 * reached without a valid nonce. The capability check is ours.
 *
 * @package INMX\BMSM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add the "Buy More Save More" tab to WooCommerce → Settings.
 */
add_filter(
	'woocommerce_settings_tabs_array',
	function ( array $tabs ): array {
		$tabs['inmx_bundle'] = 'Buy More Save More';
		return $tabs;
	},
	50
);

add_action( 'woocommerce_settings_tabs_inmx_bundle', 'inmx_render_settings' );

/**
 * Render the tab: sub-nav, then either the Offers editor or Analytics.
 */
function inmx_render_settings(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view routing.
	$section = sanitize_key( $_GET['section'] ?? '' );
	$base    = admin_url( 'admin.php?page=wc-settings&tab=inmx_bundle' );

	echo '<ul class="subsubsub" style="margin-bottom:16px">'
		. '<li><a href="' . esc_url( $base ) . '"'
		. ( 'analytics' !== $section ? ' class="current"' : '' ) . '>Offers</a> |</li>'
		. '<li><a href="' . esc_url( add_query_arg( 'section', 'analytics', $base ) ) . '"'
		. ( 'analytics' === $section ? ' class="current"' : '' ) . '>Analytics</a></li>'
		. '</ul>';

	if ( 'analytics' === $section ) {
		inmx_render_analytics_section();
		return;
	}

	$offers     = (array) get_option( 'inmx_offers', [] );
	$categories = get_terms(
		[
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'orderby'    => 'name',
		]
	);
	$cats       = [];
	if ( ! is_wp_error( $categories ) ) {
		foreach ( $categories as $c ) {
			$cats[] = [
				'id'   => $c->term_id,
				'name' => $c->name,
			];
		}
	}
	?>
	<h2>Bundle Offers</h2>

	<h3 style="border-bottom:1px solid #ddd;padding-bottom:8px;margin-top:20px">Offers</h3>
	<p class="description" style="margin-bottom:16px">
		Each offer links one product category to pricing tiers.
		Display locations, gift products, and perk text are all configured <strong>per offer / per tier</strong>.
	</p>
	<div id="inmx-offers-app" style="max-width:980px">
		<div id="inmx-offers-list"></div>
		<p><button type="button" class="button button-primary" id="inmx-add-offer">+ Add Offer</button></p>
	</div>
	<input type="hidden" name="inmx_offers_json" id="inmx-offers-json"
			value="<?php echo esc_attr( wp_json_encode( $offers ) ); ?>">
	<?php
}

add_action( 'woocommerce_settings_save_inmx_bundle', 'inmx_save_settings' );

/**
 * Validate and persist the offers posted from the editor.
 */
function inmx_save_settings(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WC_Admin_Settings::save() verified the nonce before firing this hook.
	if ( 'analytics' === sanitize_key( $_GET['section'] ?? '' ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- as above.
	$raw     = wp_unslash( $_POST['inmx_offers_json'] ?? '[]' );
	$decoded = json_decode( $raw, true );
	if ( ! is_array( $decoded ) ) {
		return;
	}

	$valid_widgets = array_keys( inmx_widget_registry() );

	$clean = [];
	foreach ( $decoded as $offer ) {
		$cat_id = absint( $offer['category_id'] ?? 0 );
		if ( ! $cat_id ) {
			continue;
		}

		$tiers = [];
		foreach ( (array) ( $offer['tiers'] ?? [] ) as $t ) {
			$qty = absint( $t['qty'] ?? 0 );
			if ( ! $qty ) {
				continue;
			}
			$tiers[] = [
				'qty'                    => $qty,
				'price_per_item'         => ( isset( $t['price_per_item'] ) && '' !== $t['price_per_item'] )
											? max( 0, (float) $t['price_per_item'] ) : null,
				'original_price'         => ( isset( $t['original_price'] ) && '' !== $t['original_price'] )
											? max( 0, (float) $t['original_price'] ) : null,
				'free_delivery'          => ! empty( $t['free_delivery'] ),
				'free_gift_product_id'   => absint( $t['free_gift_product_id'] ?? 0 ),
				'free_gift_product_name' => preg_replace( '/\s*\(#\d+\)\s*$/', '', sanitize_text_field( $t['free_gift_product_name'] ?? '' ) ),
				'custom_perk_text'       => sanitize_text_field( $t['custom_perk_text'] ?? '' ),
				'badge_label'            => sanitize_text_field( $t['badge_label'] ?? '' ),
				'pack_label'             => sanitize_text_field( $t['pack_label'] ?? '' ),
				'highlight'              => ! empty( $t['highlight'] ),
			];
		}
		usort( $tiers, fn( $a, $b ) => $a['qty'] <=> $b['qty'] );

		$clean[] = [
			'name'            => sanitize_text_field( $offer['name'] ?? '' ),
			'category_id'     => $cat_id,
			'redirect_url'    => esc_url_raw( $offer['redirect_url'] ?? '' ),
			'widget_product'  => in_array( sanitize_key( $offer['widget_product'] ?? '' ), $valid_widgets, true ) ? sanitize_key( $offer['widget_product'] ) : '',
			'widget_cart'     => in_array( sanitize_key( $offer['widget_cart'] ?? '' ), $valid_widgets, true ) ? sanitize_key( $offer['widget_cart'] ) : '',
			'widget_checkout' => in_array( sanitize_key( $offer['widget_checkout'] ?? '' ), $valid_widgets, true ) ? sanitize_key( $offer['widget_checkout'] ) : '',
			'widget_mini'     => in_array( sanitize_key( $offer['widget_mini'] ?? '' ), $valid_widgets, true ) ? sanitize_key( $offer['widget_mini'] ) : '',
			'auto_highlight'  => ! empty( $offer['auto_highlight'] ),
			'tiers'           => $tiers,
		];
	}
	update_option( 'inmx_offers', $clean );
}

/**
 * Load the editor assets, but only on this settings tab.
 */
add_action( 'admin_enqueue_scripts', 'inmx_bmsm_admin_assets' );

/**
 * Enqueue WooCommerce's select controls plus the offers editor script.
 *
 * @param string $hook Current admin page hook.
 */
function inmx_bmsm_admin_assets( string $hook ): void {
	if ( 'woocommerce_page_wc-settings' !== $hook ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
	if ( ! isset( $_GET['tab'] ) || 'inmx_bundle' !== sanitize_key( $_GET['tab'] ) ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
	if ( 'analytics' === sanitize_key( $_GET['section'] ?? '' ) ) {
		return;
	}

	wp_enqueue_script( 'wc-enhanced-select' );
	wp_enqueue_style( 'woocommerce_admin_styles' );

	wp_enqueue_script(
		'inmx-bmsm-admin',
		INMX_BMSM_URL . 'assets/js/admin.js',
		[ 'jquery', 'wc-enhanced-select' ],
		INMX_BMSM_VERSION,
		true
	);

	$categories = get_terms(
		[
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'orderby'    => 'name',
		]
	);
	$cats       = [];
	if ( ! is_wp_error( $categories ) ) {
		foreach ( $categories as $c ) {
			$cats[] = [
				'id'   => $c->term_id,
				'name' => $c->name,
			];
		}
	}

	// The editor script reads these two globals, exactly as the snippet's
	// inline <script> block declared them.
	wp_add_inline_script(
		'inmx-bmsm-admin',
		'var INMX_CATS = ' . wp_json_encode( $cats ) . ';'
		. 'var INMX_WIDGET_TYPES = ' . wp_json_encode( array_map( fn( $v ) => $v['label'], inmx_widget_registry() ) ) . ';',
		'before'
	);
}
