<?php
/**
 * Contract between the update client and wherever releases actually live.
 *
 * The client handles WordPress plumbing: transients, the Plugins screen row,
 * the details modal, and handing the downloaded zip to the upgrader. It knows
 * nothing about GitHub, or HTTP, or authentication.
 *
 * A source knows exactly one thing: how to answer "what is the newest release,
 * and how do I fetch it". Moving off GitHub to a self-hosted release endpoint
 * means writing one new class against this interface. No other file changes.
 *
 * @package INMX\BMSM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface INMX_BMSM_Update_Source {

	/**
	 * Describe the newest available release.
	 *
	 * Must not throw. Network problems, rate limits and malformed responses all
	 * return null, which the client treats as "no update information", leaving
	 * the site exactly as it is. An update mechanism that white-screens wp-admin
	 * when the release host is down is worse than no update mechanism.
	 *
	 * @return array|null {
	 *     @type string $version      Release version, no leading "v". Required.
	 *     @type string $package      URL the client should pass to the upgrader. Required.
	 *     @type string $changelog    Release notes, may be Markdown or HTML.
	 *     @type string $published_at ISO-8601 or any strtotime-parseable date.
	 *     @type string $homepage     Human-facing page for this release.
	 *     @type string $requires     Minimum WordPress version.
	 *     @type string $requires_php Minimum PHP version.
	 *     @type string $tested       WordPress version tested up to.
	 * }
	 */
	public function get_latest(): ?array;

	/**
	 * Fetch a package to a local temporary file.
	 *
	 * The source owns this because only the source knows what credentials the
	 * URL needs. Implementations must return a path WordPress may delete.
	 *
	 * @param string $package Package URL, as returned in get_latest()['package'].
	 * @return string|WP_Error Absolute path to a temp file, or an error.
	 */
	public function download( string $package );

	/**
	 * Short human-readable name, used in error messages shown to admins.
	 */
	public function get_label(): string;
}
