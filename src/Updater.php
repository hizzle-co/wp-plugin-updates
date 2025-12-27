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
 * Contains the logic to fetch available updates and hook into Core's update
 * routines to serve updates for noptin.com-provided packages.
 *
 * @since 1.5.0
 * @ignore
 */
class Updater {

	/**
	 * Loads the class, runs on init.
	 */
	public static function load() {
		add_action( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'transient_update_plugins' ), 21, 1 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'upgrader_process_complete' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugins_api' ), 20, 3 );
		add_action( 'plugins_loaded', array( __CLASS__, 'add_notice_unlicensed_product' ), 10, 4 );
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'change_update_information' ) );
	}

	/**
	 * Runs in a cron thread, or in a visitor thread if triggered
	 * by _maybe_update_plugins(), or in an auto-update thread.
	 *
	 * @param object $transient The update_plugins transient object.
	 *
	 * @return object The same or a modified version of the transient.
	 */
	public static function transient_update_plugins( $transient ) {
		$update_data = self::get_update_data();
		$product_url = Main::get_config( 'product_url', '' );
		$prefix      = Main::get_config( 'plugin_prefix', '' );

		foreach ( Main::get_installed_addons() as $plugin ) {

			// Skip if the plugin doesn't have update data.
			if ( empty( $update_data[ $plugin['slug'] ] ) || empty( $update_data[ $plugin['slug'] ]['version'] ) ) {
				continue;
			}

			$data     = $update_data[ $plugin['slug'] ];
			$filename = $plugin['_filename'];

			$item = array(
				'id'             => sanitize_key( $prefix . '-' . $plugin['slug'] ),
				'slug'           => $plugin['slug'],
				'plugin'         => $filename,
				'new_version'    => $data['version'],
				'url'            => $product_url,
				'package'        => empty( $data['download_link'] ) ? '' : $data['download_link'],
				'requires_php'   => empty( $data['requires_php'] ) ? '5.6' : $data['requires_php'],
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
	public static function get_update_data() {
		$payload = wp_list_pluck( Main::get_installed_addons(), 'slug' );
		return self::update_check( array_filter( array_unique( array_values( $payload ) ) ) );
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
	private static function update_check( $payload ) {

		// Abort if no downloads installed.
		if ( empty( $payload ) || ! is_array( $payload ) ) {
			return array();
		}

		$versions_api_url = Main::get_config( 'versions_api_url' );
		if ( empty( $versions_api_url ) ) {
			return array();
		}

		sort( $payload );

		$option_prefix = Main::get_config( 'option_name', 'wp_plugin_updates_data' );
		$hash          = md5( wp_json_encode( $payload ) . Main::get_active_license_key() );
		$cache_key     = sanitize_key( '_' . $option_prefix . '_update_check' );
		$data          = get_transient( $cache_key );
		
		if ( false !== $data && isset( $data['hash'] ) && hash_equals( $hash, $data['hash'] ) ) {
			return $data['downloads'];
		}

		$data = array(
			'hash'      => $hash,
			'updated'   => time(),
			'downloads' => array(),
			'errors'    => array(),
		);

		// Allow filtering of plugin identifiers before making API request
		$plugin_identifiers = apply_filters( 'wp_plugin_updates_identifiers', $payload, Main::get_config( 'plugin_prefix', '' ) );

		$license_key = Main::get_active_license_key();
		$endpoint    = add_query_arg(
			array(
				'hizzle_license_url' => empty( $license_key ) ? false : rawurlencode( home_url() ),
				'hizzle_license'     => empty( $license_key ) ? false : rawurlencode( $license_key ),
				'downloads'          => rawurlencode( implode( ',', $plugin_identifiers ) ),
				'hash'               => $hash,
			),
			$versions_api_url
		);

		$headers = array_merge(
			array(
				'Accept' => 'application/json',
			),
			Main::get_config( 'api_headers', array() )
		);

		$response = Main::process_api_response(
			wp_remote_get(
				$endpoint,
				array(
					'timeout' => 15,
					'headers' => $headers,
				)
			)
		);

		if ( is_wp_error( $response ) ) {
			$data['errors'][] = $response->get_error_message();
		} else {
			$response = json_decode( wp_json_encode( $response ), true );
			
			// Map plugin identifiers back to slugs
			$identifier_to_slug = array_combine( $plugin_identifiers, $payload );
			
			foreach ( $plugin_identifiers as $identifier ) {
				$slug = isset( $identifier_to_slug[ $identifier ] ) ? $identifier_to_slug[ $identifier ] : '';
				
				if ( empty( $slug ) ) {
					continue;
				}
				
				if ( ! empty( $response[ $identifier ]['error'] ) ) {
					$data['errors'][] = $response[ $identifier ]['error'];
					continue;
				}

				if ( ! empty( $response[ $identifier ] ) ) {
					$data['downloads'][ $slug ]         = $response[ $identifier ];
					$data['downloads'][ $slug ]['slug'] = $slug;
				}
			}
		}

		delete_transient( '_' . $option_prefix . '_helper_updates_count' );
		$seconds = empty( $data['errors'] ) ? DAY_IN_SECONDS : 30 * MINUTE_IN_SECONDS;
		set_transient( $cache_key, $data, $seconds );
		return $data['downloads'];
	}

	/**
	 * Get the number of products that have updates.
	 *
	 * @return int The number of products with updates.
	 */
	public static function get_updates_count() {
		$option_prefix = Main::get_config( 'option_name', 'wp_plugin_updates_data' );
		$cache_key     = sanitize_key( '_' . $option_prefix . '_helper_updates_count' );
		$count         = get_transient( $cache_key );
		
		if ( false !== $count ) {
			return $count;
		}

		if ( ! get_transient( '_' . $option_prefix . '_update_check' ) ) {
			return 0;
		}

		$count       = 0;
		$update_data = self::get_update_data();

		if ( empty( $update_data ) ) {
			set_transient( $cache_key, $count, 12 * HOUR_IN_SECONDS );
			return $count;
		}

		// Scan local plugins.
		foreach ( Main::get_installed_addons() as $plugin ) {
			if ( empty( $update_data[ $plugin['slug'] ] ) ) {
				continue;
			}

			if ( version_compare( $plugin['Version'], $update_data[ $plugin['slug'] ]['version'], '<' ) ) {
				++$count;
			}
		}

		set_transient( $cache_key, $count, 12 * HOUR_IN_SECONDS );
		return $count;
	}

	/**
	 * Return the updates count markup.
	 *
	 * @return string Updates count markup, empty string if no updates avairable.
	 */
	public static function get_updates_count_html() {
		$count = (int) self::get_updates_count();
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
	public static function has_extension_update( $slug ) {
		$option_prefix = Main::get_config( 'option_name', 'wp_plugin_updates_data' );

		if ( ! get_transient( '_' . $option_prefix . '_update_check' ) ) {
			return false;
		}

		$update_data = self::get_update_data();

		if ( empty( $update_data ) || empty( $update_data[ $slug ] ) ) {
			return false;
		}

		// Fetch local plugin.
		$local_plugin = current( wp_list_filter( Main::get_installed_addons(), array( 'slug' => $slug ) ) );

		return ! empty( $local_plugin ) && version_compare( $local_plugin['Version'], $update_data[ $slug ]['version'], '<' );
	}

	/**
	 * Flushes cached update data.
	 */
	public static function flush_updates_cache() {
		$option_prefix = Main::get_config( 'option_name', 'wp_plugin_updates_data' );
		delete_transient( '_' . $option_prefix . '_update_check' );
		delete_transient( '_' . $option_prefix . '_helper_updates_count' );
		delete_site_transient( 'update_plugins' );
	}

	/**
	 * Fires when a user successfully updated a plugin.
	 */
	public static function upgrader_process_complete() {
		$option_prefix = Main::get_config( 'option_name', 'wp_plugin_updates_data' );
		delete_transient( '_' . $option_prefix . '_helper_updates_count' );
	}

	/**
	 * Plugin information callback for extensions.
	 *
	 * @param object $response The response core needs to display the modal.
	 * @param string $action The requested plugins_api() action.
	 * @param object $args Arguments passed to plugins_api().
	 *
	 * @return object An updated $response.
	 */
	public static function plugins_api( $response, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
			return $response;
		}

		$prefix = Main::get_config( 'plugin_prefix', '' );

		// Only for slugs that match our prefix
		if ( ! empty( $prefix ) && 0 !== strpos( $args->slug, $prefix ) ) {
			return $response;
		}

		$versions_api_url = Main::get_config( 'versions_api_url' );
		if ( empty( $versions_api_url ) ) {
			return $response;
		}

		// Allow filtering to get the plugin identifier for the API
		$plugin_identifier = apply_filters( 'wp_plugin_updates_plugin_identifier', $args->slug, $prefix );

		// Abort if cannot get plugin identifier.
		if ( empty( $plugin_identifier ) ) {
			return $response;
		}

		$license_key = Main::get_active_license_key();
		$endpoint    = add_query_arg(
			array(
				'hizzle_license_url' => empty( $license_key ) ? false : rawurlencode( home_url() ),
				'hizzle_license'     => empty( $license_key ) ? false : rawurlencode( $license_key ),
				'downloads'          => rawurlencode( $plugin_identifier ),
			),
			$versions_api_url
		);

		$option_prefix = Main::get_config( 'option_name', 'wp_plugin_updates_data' );
		$key           = sanitize_key( $option_prefix . '_versions_' . md5( $endpoint ) );
		$new_response  = get_transient( $key );

		if ( false === $new_response ) {
			$headers = array_merge(
				array(
					'Accept' => 'application/json',
				),
				Main::get_config( 'api_headers', array() )
			);

			$new_response = Main::process_api_response(
				wp_remote_get(
					$endpoint,
					array(
						'timeout' => 15,
						'headers' => $headers,
					)
				)
			);

			if ( ! is_wp_error( $new_response ) ) {
				set_transient( $key, $new_response, 5 * MINUTE_IN_SECONDS );
			}
		}

		if ( is_wp_error( $new_response ) ) {
			return new \WP_Error( 'plugins_api_failed', $new_response->get_error_message() );
		}

		$new_response = json_decode( wp_json_encode( $new_response ), true );
		if ( empty( $new_response[ $plugin_identifier ] ) ) {
			return new \WP_Error( 'plugins_api_failed', __( 'Error fetching downloadable file', Main::get_config( 'text_domain' ) ) );
		}

		if ( ! empty( $new_response[ $plugin_identifier ]['error'] ) ) {
			if ( ! empty( $new_response[ $plugin_identifier ]['error']['error_code'] ) && 'download_file_not_found' === $new_response[ $plugin_identifier ]['error']['error_code'] ) {
				return $response;
			}

			return new \WP_Error( 'plugins_api_failed', $new_response[ $plugin_identifier ]['error'] );
		}

		$new_response[ $plugin_identifier ]['slug'] = $args->slug;

		return (object) $new_response[ $plugin_identifier ];
	}

	/**
	 * Add action for queued products to display message for unlicensed products.
	 *
	 * @access  public
	 * @since   1.0.0
	 * @return  void
	 */
	public static function add_notice_unlicensed_product() {
		if ( is_admin() && function_exists( 'get_plugins' ) ) {
			foreach ( array_keys( Main::get_installed_addons() ) as $key ) {
				add_action( 'in_plugin_update_message-' . $key, array( __CLASS__, 'need_license_message' ), 10, 2 );
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
	public static function need_license_message( $plugin_data, $r ) {
		$manage_license_page = Main::get_config( 'manage_license_page', '' );

		if ( empty( $r->package ) && ! empty( $manage_license_page ) ) {
			printf(
				'<span style="display: block;margin-top: 10px;font-weight: 600; color: #a00;">%s</span>',
				sprintf(
					/* translators: %s: updates page URL. */
					wp_kses_post( __( 'To update, please <a href="%s">activate your license key</a>.', Main::get_config( 'text_domain' ) ) ),
					esc_url( admin_url( 'admin.php?page=' . sanitize_key( $manage_license_page ) ) )
				)
			);
		}
	}

	/**
	 * Change the update information for unlicensed products
	 *
	 * @param  object $transient The update-plugins transient.
	 * @return object
	 */
	public static function change_update_information( $transient ) {
		$manage_license_page = Main::get_config( 'manage_license_page', '' );

		if ( empty( $manage_license_page ) ) {
			return $transient;
		}

		// If we are on the update core page, change the update message for unlicensed products.
		global $pagenow;
		if ( ( 'update-core.php' === $pagenow ) && $transient && isset( $transient->response ) && ! isset( $_GET['action'] ) ) {
			$notice = sprintf(
				/* translators: %s: updates page URL. */
				__( 'To update, please <a href="%s">activate your license key</a>.', Main::get_config( 'text_domain' ) ),
				admin_url( 'admin.php?page=' . sanitize_key( $manage_license_page ) )
			);

			foreach ( array_keys( Main::get_installed_addons() ) as $key ) {
				if ( isset( $transient->response[ $key ] ) && ( empty( $transient->response[ $key ]->package ) ) ) {
					$transient->response[ $key ]->upgrade_notice = $notice;
				}
			}
		}

		return $transient;
	}
}
