<?php
/**
 * Front-end asset loading.
 *
 * The WPCode snippet printed ~10 KB of CSS inline into wp_head and the mini-cart
 * script inline into wp_footer, on every page view. As real files they are
 * fetched once and then served from the browser and edge cache instead of being
 * re-sent inside every HTML response.
 *
 * The "only when offers exist" gate from the snippet is preserved exactly: a
 * store with no configured offers loads neither file.
 *
 * @package INMX\BMSM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_enqueue_scripts', 'inmx_bmsm_enqueue_assets' );

/**
 * Register and enqueue the front-end stylesheet and script.
 */
function inmx_bmsm_enqueue_assets(): void {

	// Same gate the snippet used on both wp_head and wp_footer.
	if ( empty( inmx_get_offers() ) ) {
		return;
	}

	wp_enqueue_style(
		'inmx-bmsm',
		INMX_BMSM_URL . 'assets/css/frontend.css',
		[],
		INMX_BMSM_VERSION
	);

	wp_enqueue_script(
		'inmx-bmsm',
		INMX_BMSM_URL . 'assets/js/frontend.js',
		[ 'jquery' ],
		INMX_BMSM_VERSION,
		true
	);
}
