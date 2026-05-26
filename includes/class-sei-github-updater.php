<?php
/**
 * GitHub-backed auto-updater for Simple Export & Import.
 *
 * Mirrors the wp-loc pattern: hooks into WP's plugin update transient,
 * fetches the raw plugin header from GitHub, parses Version:, exposes
 * a zip from the archive endpoint. No external dependencies, no GitHub
 * API rate limit (raw.githubusercontent.com and archive zip are both
 * static endpoints).
 *
 * @package Simple_Export_Import
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEI_GitHub_Updater {

	private const REPOSITORY = 'vitaliikaplia/simple-export-import';
	private const CACHE_KEY  = 'sei_github_update_data';
	private const CACHE_TTL  = 12 * HOUR_IN_SECONDS;

	public function __construct() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'filter_update_plugins_transient' ) );
		add_filter( 'site_transient_update_plugins', array( $this, 'filter_update_plugins_transient' ) );
		add_filter( 'plugins_api', array( $this, 'filter_plugins_api' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'normalize_github_source_directory' ), 11, 4 );
		add_action( 'delete_site_transient_update_plugins', array( $this, 'clear_cached_update_data' ) );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache_after_update' ), 10, 2 );
	}

	/**
	 * Inject our plugin into WP's update_plugins transient so the admin
	 * Updates screen and the plugin row both surface the new version.
	 */
	public function filter_update_plugins_transient( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}

		if ( empty( $transient->checked ) || ! is_array( $transient->checked ) ) {
			return $transient;
		}

		$local_version = $transient->checked[ SEI_PLUGIN_BASENAME ] ?? SEI_VERSION;
		$remote_data   = $this->get_remote_update_data( $this->should_force_check() );

		if ( ! $remote_data || empty( $remote_data['version'] ) ) {
			return $transient;
		}

		if ( empty( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		if ( empty( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}

		$update = $this->build_update_response( $remote_data );

		if ( version_compare( $remote_data['version'], $local_version, '>' ) ) {
			$transient->response[ SEI_PLUGIN_BASENAME ] = $update;
			unset( $transient->no_update[ SEI_PLUGIN_BASENAME ] );
		} else {
			$transient->no_update[ SEI_PLUGIN_BASENAME ] = $update;
			unset( $transient->response[ SEI_PLUGIN_BASENAME ] );
		}

		return $transient;
	}

	/**
	 * Provide "View details" / "Install" metadata when WP queries
	 * plugins_api for our slug (e.g. when the user clicks the version
	 * link on the Updates screen).
	 */
	public function filter_plugins_api( $result, string $action, $args ) {
		if ( $action !== 'plugin_information' || empty( $args->slug ) || $args->slug !== $this->get_slug() ) {
			return $result;
		}

		$remote_data = $this->get_remote_update_data( $this->should_force_check() );
		$version     = $remote_data['version'] ?? SEI_VERSION;

		return (object) array(
			'name'          => 'Simple Export & Import',
			'slug'          => $this->get_slug(),
			'version'       => $version,
			'author'        => '<a href="https://vitaliikaplia.com/">Vitalii Kaplia</a>',
			'homepage'      => $this->get_repository_url(),
			'requires'      => '4.7',
			'requires_php'  => '7.4',
			'tested'        => get_bloginfo( 'version' ),
			'download_link' => $remote_data['package'] ?? $this->get_package_url(),
			'sections'      => array(
				'description' => '<p>Export & import WordPress posts as JSON with full Gutenberg / ACF / WPML / WP-LOC support, including optional base64 media embedding and per-language attachment translations.</p>',
				'changelog'   => '<p>Updates are pulled from the master branch of the public GitHub repository when the plugin header version is newer than the installed version.</p>',
			),
		);
	}

	/**
	 * GitHub's archive zip extracts to "<repo>-<branch>"; WP wants the
	 * folder named exactly like the slug. Rename in place during upgrade.
	 */
	public function normalize_github_source_directory( $source, string $remote_source, $upgrader, array $hook_extra = array() ) {
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== SEI_PLUGIN_BASENAME ) {
			return $source;
		}

		$source_path        = untrailingslashit( (string) $source );
		$expected_directory = $this->get_slug();

		if ( basename( $source_path ) === $expected_directory ) {
			return trailingslashit( $source_path );
		}

		if ( ! str_starts_with( basename( $source_path ), $expected_directory . '-' ) ) {
			return $source;
		}

		$target = trailingslashit( dirname( $source_path ) ) . $expected_directory;

		global $wp_filesystem;

		if ( $wp_filesystem && $wp_filesystem->exists( $target ) ) {
			$wp_filesystem->delete( $target, true );
		} elseif ( file_exists( $target ) ) {
			$this->delete_directory( $target );
		}

		if ( $wp_filesystem && $wp_filesystem->move( $source_path, $target, true ) ) {
			return trailingslashit( $target );
		}

		if ( @rename( $source_path, $target ) ) {
			return trailingslashit( $target );
		}

		return $source;
	}

	private function delete_directory( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		$items = scandir( $directory );
		if ( ! is_array( $items ) ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( $item === '.' || $item === '..' ) {
				continue;
			}
			$path = $directory . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) ) {
				$this->delete_directory( $path );
			} else {
				@unlink( $path );
			}
		}

		@rmdir( $directory );
	}

	public function clear_cache_after_update( $upgrader, array $hook_extra ): void {
		if ( empty( $hook_extra['action'] ) || $hook_extra['action'] !== 'update' ) {
			return;
		}
		if ( empty( $hook_extra['type'] ) || $hook_extra['type'] !== 'plugin' ) {
			return;
		}

		$plugins = isset( $hook_extra['plugins'] ) ? (array) $hook_extra['plugins'] : array( $hook_extra['plugin'] ?? '' );

		if ( in_array( SEI_PLUGIN_BASENAME, $plugins, true ) ) {
			$this->clear_cached_update_data();
		}
	}

	public function clear_cached_update_data(): void {
		delete_site_transient( self::CACHE_KEY );
	}

	/**
	 * Fetch the plugin header from GitHub raw and extract the Version line.
	 * Result cached 12h (1h on failure) to keep WP's update check snappy.
	 */
	private function get_remote_update_data( bool $force = false ): ?array {
		if ( ! $force ) {
			$cached = get_site_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return ! empty( $cached['version'] ) ? $cached : null;
			}
		}

		$response = wp_remote_get(
			$this->get_remote_plugin_file_url(),
			array(
				'timeout'     => 10,
				'redirection' => 3,
				'headers'     => array(
					'Accept'     => 'text/plain',
					'User-Agent' => 'Simple-Export-Import/' . SEI_VERSION . '; ' . home_url( '/' ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->cache_failed_check();
			return null;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			$this->cache_failed_check();
			return null;
		}

		$body    = (string) wp_remote_retrieve_body( $response );
		$version = $this->parse_plugin_version( $body );

		if ( ! $version ) {
			$this->cache_failed_check();
			return null;
		}

		$data = array(
			'version'      => $version,
			'package'      => $this->get_package_url(),
			'url'          => $this->get_repository_url(),
			'branch'       => $this->get_branch(),
			'last_checked' => time(),
		);

		set_site_transient( self::CACHE_KEY, $data, self::CACHE_TTL );

		return $data;
	}

	private function build_update_response( array $remote_data ): stdClass {
		return (object) array(
			'id'           => $this->get_repository_url(),
			'slug'         => $this->get_slug(),
			'plugin'       => SEI_PLUGIN_BASENAME,
			'new_version'  => $remote_data['version'],
			'url'          => $remote_data['url'],
			'package'      => $remote_data['package'],
			'requires'     => '4.7',
			'requires_php' => '7.4',
			'tested'       => get_bloginfo( 'version' ),
		);
	}

	private function parse_plugin_version( string $plugin_file_contents ): ?string {
		if ( ! preg_match( '/^[ \t\/*#@]*Version:\s*([^\r\n]+)/mi', $plugin_file_contents, $matches ) ) {
			return null;
		}

		$version = trim( $matches[1] );
		return $version !== '' ? $version : null;
	}

	private function should_force_check(): bool {
		$force_check = isset( $_GET['force-check'] ) ? sanitize_text_field( wp_unslash( $_GET['force-check'] ) ) : '';

		return is_admin()
			&& current_user_can( 'update_plugins' )
			&& $force_check === '1';
	}

	private function cache_failed_check(): void {
		set_site_transient(
			self::CACHE_KEY,
			array(
				'version'      => '',
				'last_checked' => time(),
			),
			HOUR_IN_SECONDS
		);
	}

	private function get_slug(): string {
		return dirname( SEI_PLUGIN_BASENAME );
	}

	private function get_branch(): string {
		$branch = defined( 'SEI_GITHUB_BRANCH' ) ? (string) SEI_GITHUB_BRANCH : 'master';
		$branch = trim( $branch );

		return $branch !== '' ? $branch : 'master';
	}

	private function get_remote_plugin_file_url(): string {
		return sprintf(
			'https://raw.githubusercontent.com/%s/%s/simple-export-import.php',
			self::REPOSITORY,
			$this->get_url_branch()
		);
	}

	private function get_package_url(): string {
		return sprintf(
			'https://github.com/%s/archive/refs/heads/%s.zip',
			self::REPOSITORY,
			$this->get_url_branch()
		);
	}

	private function get_repository_url(): string {
		return 'https://github.com/' . self::REPOSITORY;
	}

	private function get_url_branch(): string {
		return implode( '/', array_map( 'rawurlencode', explode( '/', $this->get_branch() ) ) );
	}
}
