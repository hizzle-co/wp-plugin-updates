<?php
/**
 * The update helper for plugins.
 *
 */

namespace Hizzle\WP_Plugin_Updates;

defined( 'ABSPATH' ) || exit;

/**
 * Updater Class
 *
 * @since 1.5.0
 * @ignore
 */
class Updater {

	/**
	 * @var Main The main instance.
	 */
	private $main;

	/**
	 * Loads the class, runs on init.
	 *
	 * @param Main $main The main instance.
	 */
	public function __construct( $main ) {
		$this->main = $main;

		add_action( 'pre_set_site_transient_update_plugins', array( $this, 'transient_update_plugins' ), 21, 1 );
		add_action( 'upgrader_process_complete', array( $this, 'upgrader_process_complete' ) );
		add_filter( 'plugins_api', array( $this, 'plugins_api' ), 20, 3 );
		add_action( 'plugins_loaded', array( $this, 'add_notice_unlicensed_product' ), 10, 4 );
		add_filter( 'site_transient_update_plugins', array( $this, 'change_update_information' ) );
	}

	/**
	 * Runs in a cron thread, or in a visitor thread if triggered
	 * by _maybe_update_plugins(), or in an auto-update thread.
	 *
	 * @param object $transient The update_plugins transient object.
	 *
	 * @return object The same or a modified version of the transient.
	 */
	public function transient_update_plugins( $transient ) {
		$update_data = $this->get_update_data();

		foreach ( $this->main->get_installed_addons() as $plugin ) {

			$data = $update_data[ $plugin['github_repo'] ] ?? array();

			// Skip if the plugin is not ours
			if ( empty( $data['version'] ) ) {
				continue;
			}

			$filename = $plugin['_filename'];

			$item = array(
				'id'             => $this->main->prefix . '-com-' . $plugin['slug'],
				'slug'           => $plugin['slug'],
				'plugin'         => $filename,
				'new_version'    => $data['version'],
				'url'            => $this->main->api_url,
				'package'        => empty( $data['download_link'] ) ? '' : $data['download_link'],
				'requires_php'   => empty( $data['requires_php'] ) ? '7.0' : $data['requires_php'],
				'tested'         => wp_get_wp_version(),
				'upgrade_notice' => '',
			);

			if ( version_compare( $plugin['Version'], $data['version'], '<' ) ) {
				$transient->response[ $filename ] = (object) $item;
				unset( $transient->no_update[ $filename ] );
			} else {
				$transient->no_update[ $filename ] = (object) $item;
				unset( $transient->response[ $filename ] );
			}
		}

		return $transient;
	}

	/**
	 * Get update data for all installed extensions.
	 *
	 * Scans through all extensions and obtains update
	 * data for each product.
	 *
	 * @return array Update data {slug => data}
	 */
	public function get_update_data() {
		return $this->update_check(
			array_filter(
				array_unique(
					array_values( wp_list_pluck( $this->main->get_installed_addons(), 'github_repo' ) )
				)
			)
		);
	}

	/**
	 * Run an update check API call.
	 *
	 * The call is cached based on the payload (download slugs). If
	 * the payload changes, the cache is going to miss.
	 *
	 * @param array $payload Information about the plugin to update.
	 * @return array Update data for each requested product.
	 */
	private function update_check( $payload ) {

		// Abort if no downloads installed.
		if ( empty( $payload ) || ! is_array( $payload ) ) {
			return array();
		}

		sort( $payload );

		$license_key = $this->main->get_active_license_key();
		$hash        = md5( wp_json_encode( $payload ) . $license_key );
		$cache_key   = $this->main->prefix . '_update_check';
		$data        = get_transient( $cache_key );
		if ( false !== $data && hash_equals( $hash, $data['hash'] ) ) {
			return $data['downloads'];
		}

		$data = array(
			'hash'      => $hash,
			'updated'   => time(),
			'downloads' => array(),
			'errors'    => array(),
		);

		$response = $this->fetch_versions( $payload );

		if ( is_wp_error( $response ) ) {
			$data['errors'][] = $response->get_error_message();
		} else {
			$response = json_decode( wp_json_encode( $response ), true );
			foreach ( $payload as $git_url ) {
				if ( ! empty( $response[ $git_url ]['error'] ) ) {
					$data['errors'][] = $response[ $git_url ]['error'];
					continue;
				}

				$data['downloads'][ $git_url ] = $response[ $git_url ];
			}
		}

		delete_transient( $this->main->prefix . '_helper_updates_count' );
		$seconds = ! is_wp_error( $response ) ? DAY_IN_SECONDS : 30 * MINUTE_IN_SECONDS;
		set_transient( $cache_key, $data, $seconds );
		return $data['downloads'];
	}

	private function fetch_versions( $git_urls ) {

		// Abort if no downloads installed.
		if ( empty( $git_urls ) || ! is_array( $git_urls ) ) {
			return array();
		}

		return Main::process_api_response(
			wp_remote_get(
				add_query_arg(
					array(
						'hizzle_license_url' => rawurlencode( home_url() ),
						'hizzle_license'     => empty( $license_key ) ? false : rawurlencode( $license_key ),
						'downloads'          => rawurlencode( implode( ',', $git_urls ) ),
					),
					$this->main->api_url . '/wp-json/hizzle_download/v1/versions'
				),
				array(
					'timeout' => 15,
					'headers' => array(
						'Accept' => 'application/json',
					),
				)
			)
		);
	}

	/**
	 * Get the number of products that have updates.
	 *
	 * @return int The number of products with updates.
	 */
	public function get_updates_count() {
		$cache_key = $this->main->prefix . '_helper_updates_count';
		$count     = get_transient( $cache_key );
		if ( false !== $count ) {
			return $count;
		}

		if ( ! get_transient( $this->main->prefix . '_update_check' ) ) {
			return 0;
		}

		$count       = 0;
		$update_data = $this->get_update_data();

		if ( empty( $update_data ) ) {
			set_transient( $cache_key, $count, 12 * HOUR_IN_SECONDS );
			return $count;
		}

		// Scan local plugins.
		foreach ( $this->main->get_installed_addons() as $plugin ) {
			if ( empty( $update_data[ $plugin['github_repo'] ] ) ) {
				continue;
			}

			if ( version_compare( $plugin['Version'], $update_data[ $plugin['github_repo'] ]['version'], '<' ) ) {
				++$count;
			}
		}

		set_transient( $cache_key, $count, 12 * HOUR_IN_SECONDS );
		return $count;
	}

	/**
	 * Return the updates count markup.
	 *
	 * @return string Updates count markup, empty string if no updates available.
	 */
	public function get_updates_count_html() {
		$count = (int) $this->get_updates_count();
		if ( ! $count ) {
			return '';
		}

		$count_html = sprintf( '<span class="update-plugins count-%d"><span class="update-count">%d</span></span>', $count, number_format_i18n( $count ) );
		return $count_html;
	}

	/**
	 * Checks if a given extension has an update.
	 *
	 * @param string $slug The extension slug.
	 * @return bool
	 */
	public function has_extension_update( $slug ) {

		if ( ! get_transient( $this->main->prefix . '_update_check' ) ) {
			return false;
		}

		$update_data = $this->get_update_data();
		if ( empty( $update_data ) ) {
			return false;
		}

		// Fetch local plugin.
		$local_plugin = current( wp_list_filter( $this->main->get_installed_addons(), array( 'slug' => $slug ) ) );

		return ! empty( $local_plugin ) && isset( $update_data[ $local_plugin['github_repo'] ] ) && version_compare( $local_plugin['Version'], $update_data[ $local_plugin['github_repo'] ]['version'], '<' );
	}

	/**
	 * Flushes cached update data.
	 */
	public function flush_updates_cache() {
		delete_transient( $this->main->prefix . '_update_check' );
		delete_transient( $this->main->prefix . '_helper_updates_count' );
		delete_site_transient( 'update_plugins' );
	}

	/**
	 * Fires when a user successfully updated a plugin.
	 */
	public function upgrader_process_complete() {
		delete_transient( $this->main->prefix . '_helper_updates_count' );
	}

	/**
	 * Plugin information callback for our extensions.
	 *
	 * @param object $response The response core needs to display the modal.
	 * @param string $action The requested plugins_api() action.
	 * @param object $args Arguments passed to plugins_api().
	 *
	 * @return object An updated $response.
	 */
	public function plugins_api( $response, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
			return $response;
		}

		// Fetch local plugin.
		$local_plugin = current( wp_list_filter( $this->main->get_installed_addons(), array( 'slug' => $args->slug ) ) );
		$git_url      = $local_plugin ? $local_plugin['github_repo'] : '';

		// Abort if cannot get git url.
		if ( empty( $git_url ) ) {
			return $response;
		}

		$new_response = $this->fetch_versions( array( $git_url ) );

		if ( is_wp_error( $new_response ) ) {
			return new \WP_Error( 'plugins_api_failed', $new_response->get_error_message() );
		}

		$new_response = json_decode( wp_json_encode( $new_response ), true );
		if ( empty( $new_response[ $git_url ] ) ) {
			return new \WP_Error( 'plugins_api_failed', 'Error fetching downloadable file' );
		}

		if ( ! empty( $new_response[ $git_url ]['error'] ) ) {
			if ( ! empty( $new_response[ $git_url ]['error']['error_code'] ) && 'download_file_not_found' === $new_response[ $git_url ]['error']['error_code'] ) {
				return $response;
			}

			return new \WP_Error( 'plugins_api_failed', $new_response[ $git_url ]['error'] );
		}

		$new_response[ $git_url ]['slug'] = $args->slug;

		return (object) $new_response[ $git_url ];
	}

	/**
	 * Add action for queued products to display message for unlicensed products.
	 *
	 * @access  public
	 * @since   1.0.0
	 * @return  void
	 */
	public function add_notice_unlicensed_product() {
		if ( is_admin() && function_exists( 'get_plugins' ) && ! empty( $this->main->manage_license_page ) ) {
			foreach ( array_keys( $this->main->get_installed_addons() ) as $key ) {
				add_action( 'in_plugin_update_message-' . $key, array( $this, 'need_license_message' ), 10, 2 );
			}
		}
	}

	/**
	 * Message displayed if license not activated
	 *
	 * @param  array  $plugin_data The plugin data.
	 * @param  object $r The api response.
	 * @return void
	 */
	public function need_license_message( $plugin_data, $r ) {

		if ( empty( $r->package ) ) {
			printf(
				'<span style="display: block;margin-top: 10px;font-weight: 600; color: #a00;">%s</span>',
				sprintf(
					'To update, please <a href="%s">activate your license key</a>.',
					esc_url( $this->main->manage_license_page )
				)
			);
		}
	}

	/**
	 * Change the update information for unlicensed plugins.
	 *
	 * @param  object $transient The update-plugins transient.
	 * @return object
	 */
	public function change_update_information( $transient ) {

		// If we are on the update core page, change the update message for unlicensed products.
		global $pagenow;
		if ( ! empty( $this->main->manage_license_page ) && ( 'update-core.php' === $pagenow ) && $transient && isset( $transient->response ) && ! isset( $_GET['action'] ) ) {
			$notice = sprintf(
				'To update, please <a href="%s">activate your license key</a>.',
				$this->main->manage_license_page
			);

			foreach ( array_keys( $this->main->get_installed_addons() ) as $key ) {
				if ( isset( $transient->response[ $key ] ) && ( empty( $transient->response[ $key ]->package ) ) ) {
					$transient->response[ $key ]->upgrade_notice = $notice;
				}
			}
		}

		return $transient;
	}
}
