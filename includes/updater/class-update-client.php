<?php
/**
 * Wires an update source into the WordPress plugin update system.
 *
 * @package INMX\BMSM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Presents a source's releases to WordPress as plugin updates.
 */
class INMX_BMSM_Update_Client {

	private const CACHE_KEY = 'inmx_bmsm_update_check';
	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * How long a failed lookup is remembered before trying again.
	 *
	 * Without this, a site that cannot reach the release host retries on every
	 * single admin page load. On a rate-limited API that turns a transient
	 * failure into a permanent one, and it burns server CPU doing it.
	 */
	private const FAIL_TTL = 1 * HOUR_IN_SECONDS;

	private string $file;
	private string $basename;
	private string $slug;
	private string $version;
	private INMX_BMSM_Update_Source $source;

	/**
	 * @param string                   $file    Absolute path to the main plugin file.
	 * @param string                   $version Currently installed version.
	 * @param INMX_BMSM_Update_Source  $source  Where releases come from.
	 */
	public function __construct( string $file, string $version, INMX_BMSM_Update_Source $source ) {
		$this->file     = $file;
		$this->basename = plugin_basename( $file );
		$this->slug     = dirname( $this->basename );
		$this->version  = $version;
		$this->source   = $source;
	}

	/**
	 * Attach to WordPress.
	 */
	public function register(): void {
		add_filter( 'site_transient_update_plugins', [ $this, 'inject_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_details' ], 20, 3 );
		add_filter( 'upgrader_pre_download', [ $this, 'authenticated_download' ], 10, 3 );
		add_filter( 'upgrader_source_selection', [ $this, 'normalise_folder_name' ], 10, 4 );
		add_action( 'upgrader_process_complete', [ $this, 'flush_cache' ], 10, 0 );

		// "Check for updates" link on the Plugins screen, so an admin never has
		// to wait out the 12-hour cache to pull an urgent fix.
		add_filter( 'plugin_row_meta', [ $this, 'row_meta_link' ], 10, 2 );
		add_action( 'admin_init', [ $this, 'handle_manual_check' ] );
	}

	/*
	 * ─────────────────────────────────────────────────────────────────
	 * Update injection
	 * ─────────────────────────────────────────────────────────────────
	 */

	/**
	 * Add this plugin to WordPress's list of available updates.
	 *
	 * @param mixed $transient The update_plugins site transient.
	 * @return mixed
	 */
	public function inject_update( $transient ) {

		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->get_cached_release();
		if ( ! $release ) {
			return $transient;
		}

		$item = (object) [
			'id'            => $this->slug,
			'slug'          => $this->slug,
			'plugin'        => $this->basename,
			'new_version'   => $release['version'],
			'url'           => $release['homepage'] ?? '',
			'package'       => $release['package'],
			'tested'        => $release['tested'] ?? '',
			'requires_php'  => $release['requires_php'] ?? '',
			'icons'         => [],
			'banners'       => [],
			'banners_rtl'   => [],
			'compatibility' => new stdClass(),
		];

		if ( version_compare( $release['version'], $this->version, '>' ) ) {
			$transient->response[ $this->basename ] = $item;
			unset( $transient->no_update[ $this->basename ] );
		} else {
			// Listing it here is what makes WordPress show "auto-updates enabled"
			// controls for a plugin it has never heard of.
			$transient->no_update[ $this->basename ] = $item;
			unset( $transient->response[ $this->basename ] );
		}

		return $transient;
	}

	/**
	 * Populate the "View details" modal.
	 *
	 * @param mixed  $result Existing result.
	 * @param string $action plugins_api action.
	 * @param object $args   Request args.
	 * @return mixed
	 */
	public function plugin_details( $result, $action, $args ) {

		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->get_cached_release();
		if ( ! $release ) {
			return $result;
		}

		$data = get_plugin_data( $this->file, false, false );

		return (object) [
			'name'          => $data['Name'] ?? 'INMX Buy More Save More',
			'slug'          => $this->slug,
			'version'       => $release['version'],
			'author'        => $data['Author'] ?? '',
			'homepage'      => $release['homepage'] ?? '',
			'requires'      => $release['requires'] ?? '',
			'requires_php'  => $release['requires_php'] ?? '',
			'tested'        => $release['tested'] ?? '',
			'last_updated'  => $release['published_at'] ?? '',
			'download_link' => $release['package'],
			'trunk'         => $release['package'],
			'sections'      => [
				'description' => wp_kses_post( $data['Description'] ?? '' ),
				'changelog'   => $this->format_changelog( $release['changelog'] ?? '' ),
			],
		];
	}

	/*
	 * ─────────────────────────────────────────────────────────────────
	 * Download
	 * ─────────────────────────────────────────────────────────────────
	 */

	/**
	 * Hand the download to the source so it can authenticate.
	 *
	 * WordPress's own download_url() sends no custom headers, so a private
	 * release would 404 here with a message that looks like the release does
	 * not exist. Returning a local file path short-circuits that entirely.
	 *
	 * @param mixed  $reply   Short-circuit value.
	 * @param string $package Package URL.
	 * @param object $upgrader Upgrader instance.
	 * @return mixed
	 */
	public function authenticated_download( $reply, $package, $upgrader ) {

		$release = $this->get_cached_release();
		if ( ! $release || empty( $release['package'] ) || $package !== $release['package'] ) {
			return $reply;
		}

		if ( isset( $upgrader->skin ) ) {
			$upgrader->skin->feedback( sprintf( 'Downloading update from %s…', $this->source->get_label() ) );
		}

		$file = $this->source->download( $package );

		return is_wp_error( $file ) ? $file : $file;
	}

	/**
	 * Force the extracted folder to match the installed plugin folder.
	 *
	 * A release built by our own workflow already unpacks to the right name, so
	 * this is normally a no-op. It matters for the source-archive fallback:
	 * GitHub's zipball unpacks to "owner-repo-a1b2c3d", and WordPress would
	 * install that as a second, separate plugin rather than updating this one.
	 *
	 * @param string $source        Unpacked folder.
	 * @param string $remote_source Parent temp folder.
	 * @param object $upgrader      Upgrader instance.
	 * @param array  $hook_extra    Context.
	 * @return string|WP_Error
	 */
	public function normalise_folder_name( $source, $remote_source, $upgrader, $hook_extra = [] ) {

		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . $this->slug;

		if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
			return $source;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem instanceof WP_Filesystem_Base ) {
			return $source;
		}

		if ( $wp_filesystem->exists( $desired ) ) {
			$wp_filesystem->delete( $desired, true );
		}

		if ( ! $wp_filesystem->move( $source, $desired ) ) {
			return new WP_Error(
				'inmx_bmsm_rename_failed',
				sprintf(
					'Could not rename the downloaded folder to "%s". The update was not installed and the current version is untouched.',
					$this->slug
				)
			);
		}

		return trailingslashit( $desired );
	}

	/*
	 * ─────────────────────────────────────────────────────────────────
	 * Cache
	 * ─────────────────────────────────────────────────────────────────
	 */

	/**
	 * Release info, from cache when warm.
	 *
	 * @return array|null
	 */
	private function get_cached_release(): ?array {

		$cached = get_site_transient( self::CACHE_KEY );

		if ( is_array( $cached ) && isset( $cached['ok'] ) ) {
			return $cached['ok'] ? $cached['release'] : null;
		}

		// Only ever hit the network from admin context or WP-Cron. Without this
		// guard a cache miss on a busy shop makes a customer's page view wait on
		// an outbound API call.
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return null;
		}

		$release = $this->source->get_latest();

		if ( ! $release || empty( $release['version'] ) || empty( $release['package'] ) ) {
			set_site_transient( self::CACHE_KEY, [ 'ok' => false ], self::FAIL_TTL );
			return null;
		}

		set_site_transient(
			self::CACHE_KEY,
			[
				'ok'      => true,
				'release' => $release,
			],
			self::CACHE_TTL
		);

		return $release;
	}

	/**
	 * Drop the cached lookup.
	 */
	public function flush_cache(): void {
		delete_site_transient( self::CACHE_KEY );
	}

	/*
	 * ─────────────────────────────────────────────────────────────────
	 * Manual check
	 * ─────────────────────────────────────────────────────────────────
	 */

	/**
	 * Add a "Check for updates" link to the plugin's row.
	 *
	 * @param array  $links Existing row meta links.
	 * @param string $file  Plugin basename for the row being rendered.
	 * @return array
	 */
	public function row_meta_link( $links, $file ) {

		if ( $file !== $this->basename || ! current_user_can( 'update_plugins' ) ) {
			return $links;
		}

		$url = wp_nonce_url(
			add_query_arg( 'inmx_bmsm_check_updates', '1', admin_url( 'plugins.php' ) ),
			'inmx_bmsm_check_updates'
		);

		$links[] = '<a href="' . esc_url( $url ) . '">Check for updates</a>';

		return $links;
	}

	/**
	 * Handle the manual check link.
	 */
	public function handle_manual_check(): void {

		if ( empty( $_GET['inmx_bmsm_check_updates'] ) ) {
			return;
		}
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		if ( ! check_admin_referer( 'inmx_bmsm_check_updates' ) ) {
			return;
		}

		$this->flush_cache();
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}

	/*
	 * ─────────────────────────────────────────────────────────────────
	 * Helpers
	 * ─────────────────────────────────────────────────────────────────
	 */

	/**
	 * Render release notes for the details modal.
	 *
	 * Release bodies are Markdown. This covers the subset that actually appears
	 * in changelogs rather than pulling in a Markdown parser.
	 *
	 * @param string $text Raw release body.
	 */
	private function format_changelog( string $text ): string {

		if ( '' === trim( $text ) ) {
			return '<p>No release notes were provided for this version.</p>';
		}

		$html = esc_html( $text );

		// Headings.
		$html = preg_replace( '/^###\s*(.+)$/m', '<h4>$1</h4>', $html );
		$html = preg_replace( '/^##\s*(.+)$/m', '<h3>$1</h3>', $html );
		$html = preg_replace( '/^#\s*(.+)$/m', '<h3>$1</h3>', $html );

		// Bullets.
		$html = preg_replace( '/^\s*[-*]\s+(.+)$/m', '<li>$1</li>', $html );
		$html = preg_replace( '/(<li>.*<\/li>)/s', '<ul>$1</ul>', $html );

		// Inline code and emphasis.
		$html = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $html );
		$html = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $html );

		return wpautop( $html );
	}
}
