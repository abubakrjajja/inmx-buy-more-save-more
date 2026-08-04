<?php
/**
 * Plugin Name:       INMX Buy More Save More
 * Plugin URI:        https://inmixio.com/plugins/buy-more-save-more
 * Description:       Quantity-tier bundle offers for WooCommerce. Links a product category to pricing tiers with per-tier gifts, free delivery and custom perk text, and renders upsell widgets on the product page, cart, checkout and side cart.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * Author:            INMIXIO
 * Author URI:        https://inmixio.com
 * Update URI:        https://inmixio.com/plugins/buy-more-save-more
 * Text Domain:       inmx-bmsm
 *
 * @package INMX\BMSM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * ─────────────────────────────────────────────────────────────────────
 * Constants
 * ─────────────────────────────────────────────────────────────────────
 */

define( 'INMX_BMSM_VERSION', '1.1.0' );
define( 'INMX_BMSM_FILE', __FILE__ );
define( 'INMX_BMSM_DIR', plugin_dir_path( __FILE__ ) );
define( 'INMX_BMSM_URL', plugin_dir_url( __FILE__ ) );
define( 'INMX_BMSM_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Where updates come from.
 *
 * Both are overridable from wp-config.php so a site can be pinned to a fork or
 * a staging repo without editing plugin files. See docs/UPDATES.md.
 */
if ( ! defined( 'INMX_BMSM_GH_OWNER' ) ) {
	define( 'INMX_BMSM_GH_OWNER', 'abubakrjajja' );
}
if ( ! defined( 'INMX_BMSM_GH_REPO' ) ) {
	define( 'INMX_BMSM_GH_REPO', 'inmx-buy-more-save-more' );
}

/*
 * ─────────────────────────────────────────────────────────────────────
 * Dependency gate
 * ─────────────────────────────────────────────────────────────────────
 *
 * Every part of this plugin calls WooCommerce functions at load time or on a
 * WooCommerce hook. Loading without WooCommerce is an instant fatal, so the
 * gate runs before anything else is required.
 *
 * `Requires Plugins` (WP 6.5+) stops activation without WooCommerce, but it
 * does not stop WooCommerce being deactivated afterwards. This is the guard
 * that actually holds.
 */
add_action( 'plugins_loaded', 'inmx_bmsm_bootstrap', 20 );

/**
 * Load the plugin, or explain why it cannot load.
 */
function inmx_bmsm_bootstrap(): void {

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'inmx_bmsm_missing_wc_notice' );
		return;
	}

	// Core: options, cart maths, category matching, perk text.
	require_once INMX_BMSM_DIR . 'includes/core/data.php';

	// Pricing engine: gift management and tier prices on the cart.
	require_once INMX_BMSM_DIR . 'includes/core/pricing.php';

	// Orders: discount capture at checkout and totals-table display.
	require_once INMX_BMSM_DIR . 'includes/core/orders.php';

	// Front end: widget renderers, display hooks, assets.
	require_once INMX_BMSM_DIR . 'includes/frontend/widgets.php';
	require_once INMX_BMSM_DIR . 'includes/frontend/display-hooks.php';
	require_once INMX_BMSM_DIR . 'includes/frontend/assets.php';

	// Admin: WooCommerce settings tab and analytics.
	if ( is_admin() ) {
		require_once INMX_BMSM_DIR . 'includes/admin/settings.php';
		require_once INMX_BMSM_DIR . 'includes/admin/analytics.php';
	}

	inmx_bmsm_init_updater();
}

/**
 * Admin notice shown when WooCommerce is absent.
 */
function inmx_bmsm_missing_wc_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p><strong>INMX Buy More Save More</strong> is inactive because WooCommerce is not active. Bundle pricing and all upsell widgets are switched off until WooCommerce is enabled.</p></div>';
}

/*
 * ─────────────────────────────────────────────────────────────────────
 * Updater
 * ─────────────────────────────────────────────────────────────────────
 */

/**
 * Wire the update client to its source.
 *
 * The client knows nothing about GitHub. Swapping to a self-hosted release
 * endpoint later means writing one new source class and changing the line
 * below, with no change to the plugin's update behaviour.
 */
function inmx_bmsm_init_updater(): void {

	require_once INMX_BMSM_DIR . 'includes/updater/interface-update-source.php';
	require_once INMX_BMSM_DIR . 'includes/updater/class-update-client.php';
	require_once INMX_BMSM_DIR . 'includes/updater/class-github-source.php';

	/**
	 * Filter the update source.
	 *
	 * Return any INMX_BMSM_Update_Source implementation to change where updates
	 * are fetched from. Return null to disable update checks entirely, which is
	 * what a staging clone of a production site should do.
	 *
	 * @param INMX_BMSM_Update_Source|null $source Default GitHub source.
	 */
	$source = apply_filters(
		'inmx_bmsm_update_source',
		new INMX_BMSM_GitHub_Source( INMX_BMSM_GH_OWNER, INMX_BMSM_GH_REPO )
	);

	if ( $source instanceof INMX_BMSM_Update_Source ) {
		( new INMX_BMSM_Update_Client( INMX_BMSM_FILE, INMX_BMSM_VERSION, $source ) )->register();
	}
}

/*
 * ─────────────────────────────────────────────────────────────────────
 * HPOS compatibility
 * ─────────────────────────────────────────────────────────────────────
 *
 * This plugin reads and writes order meta through the WC_Order CRUD API only
 * (get_meta / update_meta_data), never a direct postmeta query, so it is
 * already HPOS-safe. Declaring it stops WooCommerce showing the site as
 * incompatible, which otherwise blocks the store from enabling HPOS at all.
 *
 * Must run on before_woocommerce_init, which fires earlier than the
 * plugins_loaded bootstrap above.
 */
add_action(
	'before_woocommerce_init',
	function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

/*
 * ─────────────────────────────────────────────────────────────────────
 * Activation / deactivation
 * ─────────────────────────────────────────────────────────────────────
 */

register_activation_hook(
	__FILE__,
	function (): void {
		// Deliberately does NOT create, seed or migrate inmx_offers.
		//
		// Sites migrating off the WPCode snippet already hold a configured
		// inmx_offers row, and the plugin reads exactly the same option key.
		// Writing a default here would overwrite a live offer configuration on
		// every activation. Absent option means "no offers", which every
		// renderer already handles.
		add_option( 'inmx_bmsm_activated_at', time(), '', false );
	}
);

register_deactivation_hook(
	__FILE__,
	function (): void {
		// Drop the cached update check so a reactivated plugin re-checks
		// immediately rather than trusting a stale 12-hour window.
		delete_site_transient( 'inmx_bmsm_update_check' );
	}
);
