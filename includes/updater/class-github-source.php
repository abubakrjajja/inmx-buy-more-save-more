<?php
/**
 * GitHub Releases as an update source.
 *
 * Works with a public repo using no credentials at all, and with a private repo
 * using a fine-grained token scoped to that single repository with Contents:
 * read-only.
 *
 * The token is read from wp-config.php. It is deliberately NOT stored in the
 * database and must never be committed to the repository:
 *
 *   define( 'INMX_BMSM_GH_TOKEN', 'github_pat_...' );
 *
 * A token committed to the repo is detected by GitHub secret scanning and
 * revoked automatically, which breaks updates on every site at once, silently.
 *
 * @package INMX\BMSM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the newest published release from the GitHub REST API.
 */
class INMX_BMSM_GitHub_Source implements INMX_BMSM_Update_Source {

	private const API = 'https://api.github.com';

	/** Kept short: a slow API must never hold up a wp-admin page load. */
	private const CHECK_TIMEOUT = 10;

	/** Downloads are user-initiated and may legitimately take a while. */
	private const DOWNLOAD_TIMEOUT = 120;

	private string $owner;
	private string $repo;

	/**
	 * @param string $owner GitHub user or organisation.
	 * @param string $repo  Repository name.
	 */
	public function __construct( string $owner, string $repo ) {
		$this->owner = $owner;
		$this->repo  = $repo;
	}

	/**
	 * Human-readable label for admin messages.
	 */
	public function get_label(): string {
		return sprintf( 'GitHub (%s/%s)', $this->owner, $this->repo );
	}

	/*
	 * ─────────────────────────────────────────────────────────────────
	 * Lookup
	 * ─────────────────────────────────────────────────────────────────
	 */

	/**
	 * Newest published, non-draft release.
	 *
	 * @return array|null
	 */
	public function get_latest(): ?array {

		$response = $this->request(
			self::API . "/repos/{$this->owner}/{$this->repo}/releases/latest",
			[ 'timeout' => self::CHECK_TIMEOUT ]
		);

		if ( is_wp_error( $response ) ) {
			$this->log( 'release lookup failed: ' . $response->get_error_message() );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			// 404 on a private repo almost always means the token is missing,
			// expired, or scoped to the wrong repository — not that the release
			// is absent. Say so, because the raw 404 sends people hunting the
			// wrong problem.
			$this->log(
				sprintf(
					'release lookup returned HTTP %d.%s',
					$code,
					404 === $code ? ' If this repository is private, check that INMX_BMSM_GH_TOKEN is defined in wp-config.php and still valid.' : ''
				)
			);
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			$this->log( 'release lookup returned an unexpected body.' );
			return null;
		}

		if ( ! empty( $body['draft'] ) || ! empty( $body['prerelease'] ) ) {
			return null;
		}

		$package = $this->resolve_package( $body );
		if ( ! $package ) {
			return null;
		}

		return [
			'version'      => ltrim( (string) $body['tag_name'], 'vV' ),
			'package'      => $package,
			'changelog'    => (string) ( $body['body'] ?? '' ),
			'published_at' => (string) ( $body['published_at'] ?? '' ),
			'homepage'     => (string) ( $body['html_url'] ?? '' ),
			'requires'     => '6.0',
			'requires_php' => '8.0',
			'tested'       => '',
		];
	}

	/**
	 * Decide which URL the upgrader should be given.
	 *
	 * Prefers a built release asset, because that zip contains only the plugin
	 * and unpacks to the correct folder name. Falls back to GitHub's generated
	 * source archive, which also carries development files and needs the
	 * client's folder rename to install correctly.
	 *
	 * @param array $release Decoded release payload.
	 * @return string|null
	 */
	private function resolve_package( array $release ): ?string {

		foreach ( (array) ( $release['assets'] ?? [] ) as $asset ) {
			$name = (string) ( $asset['name'] ?? '' );
			if ( ! str_ends_with( strtolower( $name ), '.zip' ) ) {
				continue;
			}
			// The asset API URL works for both public and private repos once the
			// right Accept header is sent; browser_download_url does not, on a
			// private repo. Always use the API URL and let download() handle it.
			if ( ! empty( $asset['url'] ) ) {
				return (string) $asset['url'];
			}
		}

		return ! empty( $release['zipball_url'] ) ? (string) $release['zipball_url'] : null;
	}

	/*
	 * ─────────────────────────────────────────────────────────────────
	 * Download
	 * ─────────────────────────────────────────────────────────────────
	 */

	/**
	 * Fetch a release package to a temp file.
	 *
	 * @param string $package Package URL from get_latest().
	 * @return string|WP_Error
	 */
	public function download( string $package ) {

		$is_asset = (bool) preg_match( '#/releases/assets/\d+$#', $package );

		$args = [
			'timeout'     => self::DOWNLOAD_TIMEOUT,
			'redirection' => 0, // Handled below, deliberately.
			'headers'     => $is_asset ? [ 'Accept' => 'application/octet-stream' ] : [],
		];

		$response = $this->request( $package, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		// GitHub answers an authenticated asset request with a redirect to
		// object storage that carries its own signature in the query string.
		//
		// Redirects are followed by hand here because WordPress's HTTP layer
		// re-sends request headers to the redirect target, and object storage
		// rejects a request that arrives with BOTH its own signature and an
		// Authorization header. Following it manually, with no headers, is what
		// makes private-repo downloads work at all.
		$hops = 0;
		while ( in_array( $code, [ 301, 302, 303, 307, 308 ], true ) && $hops < 5 ) {
			$location = wp_remote_retrieve_header( $response, 'location' );
			if ( ! $location ) {
				return new WP_Error( 'inmx_bmsm_bad_redirect', 'GitHub returned a redirect with no location header.' );
			}

			$response = wp_remote_get( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_wp_remote_get
				$location,
				[
					'timeout'     => self::DOWNLOAD_TIMEOUT,
					'redirection' => 0,
					'headers'     => [], // No Authorization. This is the point.
				]
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );
			++$hops;
		}

		if ( 200 !== $code ) {
			return new WP_Error(
				'inmx_bmsm_download_failed',
				sprintf( 'Downloading the update from GitHub failed with HTTP %d. Nothing was changed on this site.', $code )
			);
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return new WP_Error( 'inmx_bmsm_empty_package', 'GitHub returned an empty package. Nothing was changed on this site.' );
		}

		$tmp = wp_tempnam( 'inmx-bmsm-update.zip' );
		if ( ! $tmp ) {
			return new WP_Error( 'inmx_bmsm_no_tempfile', 'Could not create a temporary file for the update.' );
		}

		if ( false === file_put_contents( $tmp, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'inmx_bmsm_write_failed', 'Could not write the downloaded update to disk.' );
		}

		// A truncated or HTML-error response would otherwise be handed to the
		// upgrader, which unpacks it over the live plugin folder.
		if ( ! $this->looks_like_zip( $tmp ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'inmx_bmsm_not_a_zip', 'The downloaded update was not a valid zip archive. Nothing was changed on this site.' );
		}

		return $tmp;
	}

	/**
	 * Check the local zip magic number.
	 *
	 * @param string $path File to inspect.
	 */
	private function looks_like_zip( string $path ): bool {
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return false;
		}
		$magic = fread( $handle, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return 'PK' === substr( (string) $magic, 0, 2 );
	}

	/*
	 * ─────────────────────────────────────────────────────────────────
	 * HTTP
	 * ─────────────────────────────────────────────────────────────────
	 */

	/**
	 * GET with the standard GitHub headers and, when configured, auth.
	 *
	 * @param string $url  Target URL.
	 * @param array  $args Extra wp_remote_get args.
	 * @return array|WP_Error
	 */
	private function request( string $url, array $args = [] ) {

		$headers = array_merge(
			[
				'Accept'               => 'application/vnd.github+json',
				'X-GitHub-Api-Version' => '2022-11-28',
				'User-Agent'           => 'INMX-BMSM/' . INMX_BMSM_VERSION . '; ' . home_url( '/' ),
			],
			$args['headers'] ?? []
		);

		$token = $this->get_token();
		if ( $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$args['headers'] = $headers;

		return wp_remote_get( $url, $args ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_wp_remote_get
	}

	/**
	 * The access token, if this site has one.
	 *
	 * Only needed for a private repository.
	 */
	private function get_token(): string {

		$token = defined( 'INMX_BMSM_GH_TOKEN' ) ? (string) INMX_BMSM_GH_TOKEN : '';

		/**
		 * Filter the GitHub token.
		 *
		 * Lets a site fetch the token from a secrets manager instead of
		 * wp-config.php. Whatever supplies it, it must not end up in the
		 * database or in the repository.
		 *
		 * @param string $token Token from the constant, or empty.
		 */
		return (string) apply_filters( 'inmx_bmsm_github_token', $token );
	}

	/**
	 * Record a failure where an admin can actually find it.
	 *
	 * @param string $message What went wrong.
	 */
	private function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[INMX BMSM updater] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		set_site_transient( 'inmx_bmsm_update_error', $message, DAY_IN_SECONDS );
	}
}
