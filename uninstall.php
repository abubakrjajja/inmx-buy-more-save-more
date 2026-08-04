<?php
/**
 * Uninstall handler.
 *
 * Runs when the plugin is DELETED, not when it is deactivated.
 *
 * DEFAULT BEHAVIOUR IS TO DELETE NOTHING, on purpose.
 *
 * `inmx_offers` is the store's live offer configuration: categories, tiers,
 * prices, gifts and perk copy that somebody built by hand. Deleting a plugin is
 * routinely part of reinstalling it, and a plugin that wipes its configuration
 * on delete turns a five-minute reinstall into a rebuild from memory.
 *
 * `_inmx_bundle_discounts` order meta is never touched under any setting. It
 * records what discount a customer was actually given on a specific order. That
 * is an accounting record attached to a real transaction, and it stays with the
 * order for as long as the order exists.
 *
 * To opt in to removing the configuration, add this to wp-config.php BEFORE
 * deleting the plugin:
 *
 *   define( 'INMX_BMSM_REMOVE_DATA_ON_UNINSTALL', true );
 *
 * @package INMX\BMSM
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Transients are pure cache and carry no configuration, so they always go.
delete_site_transient( 'inmx_bmsm_update_check' );
delete_site_transient( 'inmx_bmsm_update_error' );
delete_option( 'inmx_bmsm_activated_at' );

if ( ! defined( 'INMX_BMSM_REMOVE_DATA_ON_UNINSTALL' ) || ! INMX_BMSM_REMOVE_DATA_ON_UNINSTALL ) {
	return;
}

// Explicitly opted in: remove the offer configuration only.
delete_option( 'inmx_offers' );
