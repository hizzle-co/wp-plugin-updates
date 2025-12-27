<?php
/**
 * Plugin Installation and Communications.
 */

namespace Hizzle\WP_Plugin_Updates;

defined( 'ABSPATH' ) || exit;

/**
 * Main Class
 *
 * Main class for managing plugin updates and licenses.
 * @ignore
 */
class Main {

	/**
	 * Configuration options.
	 *
	 * @var array
	 */
	private static $config = array();

	/**
	 * The option name used to store the helper data.
	 *
	 * @var string
	 */
	private static $option_name = 'wp_plugin_updates_data';

	/**
	 * Initialize the updater with configuration.
	 *
	 * @param array $config Configuration array with the following keys:
	 *                      - 'api_url' (string) Required. Base URL of your licensing server.
	 *                      - 'license_api_url' (string) Optional. URL for license API. Defaults to {api_url}/wp-json/hizzle/v1/licenses.
	 *                      - 'versions_api_url' (string) Optional. URL for versions API. Defaults to {api_url}/wp-json/hizzle_download/v1/versions.
	 *                      - 'option_name' (string) Optional. WordPress option name to store data. Defaults to 'wp_plugin_updates_data'.
	 *                      - 'plugin_prefix' (string) Optional. Prefix for your plugins. Defaults to ''.
	 *                      - 'text_domain' (string) Optional. Text domain for translations. Defaults to 'default'.
	 *                      - 'api_headers' (array) Optional. Additional headers to send with API requests. Defaults to array().
	 *                      - 'product_url' (string) Optional. URL to your product/pricing page. Defaults to ''.
	 *                      - 'manage_license_page' (string) Optional. Admin page slug for managing licenses. Defaults to ''.
	 */
	public static function init( $config = array() ) {
		$defaults = array(
			'api_url'            => '',
			'license_api_url'    => '',
			'versions_api_url'   => '',
			'option_name'        => 'wp_plugin_updates_data',
			'plugin_prefix'      => '',
			'text_domain'        => 'default',
			'api_headers'        => array(),
			'product_url'        => '',
			'manage_license_page' => '',
		);

		self::$config = wp_parse_args( $config, $defaults );

		// Set derived URLs if not provided
		if ( empty( self::$config['license_api_url'] ) && ! empty( self::$config['api_url'] ) ) {
			self::$config['license_api_url'] = trailingslashit( self::$config['api_url'] ) . 'wp-json/hizzle/v1/licenses';
		}

		if ( empty( self::$config['versions_api_url'] ) && ! empty( self::$config['api_url'] ) ) {
			self::$config['versions_api_url'] = trailingslashit( self::$config['api_url'] ) . 'wp-json/hizzle_download/v1/versions';
		}

		// Update option name
		self::$option_name = self::$config['option_name'];
	}

	/**
	 * Get a configuration value.
	 *
	 * @param string $key Configuration key.
	 * @param mixed  $default Default value if key doesn't exist.
	 * @return mixed Configuration value.
	 */
	public static function get_config( $key, $default = '' ) {
		return isset( self::$config[ $key ] ) ? self::$config[ $key ] : $default;
	}

	/**
	 * Get an option by key
	 *
	 * @see self::update
	 *
	 * @param string $key The key to fetch.
	 * @param mixed  $default The default option to return if the key does not exist.
	 *
	 * @return mixed An option or the default.
	 */
	public static function get( $key, $default = false ) {
		$options = get_option( self::$option_name, array() );
		$options = is_array( $options ) ? $options : array();

		return array_key_exists( $key, $options ) ? $options[ $key ] : $default;
	}

	/**
	 * Update an option by key
	 *
	 * All helper options are grouped in a single options entry. This method
	 * is not thread-safe, use with caution.
	 *
	 * @param string $key The key to update.
	 * @param mixed  $value The new option value.
	 *
	 * @return bool True if the option has been updated.
	 */
	public static function update( $key, $value ) {
		$options         = get_option( self::$option_name, array() );
		$options         = is_array( $options ) ? $options : array();
		$options[ $key ] = $value;
		return update_option( self::$option_name, $options, true );
	}

	/*
	|--------------------------------------------------------------------------
	| License keys
	|--------------------------------------------------------------------------
	|
	| Methods which activate/deactivate license keys locally and remotely.
	|
	*/

	/**
	 * Returns the active license key.
	 *
	 * @param bool $include_details
	 * @return object|WP_Error|string|false
	 * @since 1.8.0
	 */
	public static function get_active_license_key( $include_details = false ) {

		// Fetch the license key.
		$license_key = self::get( 'license_key' );

		// If not set, try to fetch the old style license keys.
		if ( empty( $license_key ) ) {
			$licenses = self::get( 'active_license_keys' );

			if ( is_array( $licenses ) && ! empty( $licenses ) ) {
				$license_key = array_pop( $licenses );
			}
		}

		if ( empty( $license_key ) ) {
			return false;
		}

		if ( ! $include_details ) {
			return $license_key;
		}

		$details = self::fetch_license_details( $license_key );

		if ( is_wp_error( $details ) ) {
			if ( in_array( 'hizzle_licenses_not_found', $details->get_error_codes(), true ) ) {
				self::update( 'license_key', '' );
			}

			return $details;
		}

		if ( empty( $details ) ) {
			return $details;
		}

		// Check if it was deactivated remotely.
		if ( empty( $details->is_active_on_site ) ) {
			self::update( 'license_key', '' );
			return false;
		}

		return $details;
	}

	/**
	 * Fetches license details from the cache or remotely.
	 *
	 * @param string $license_key
	 * @return object|WP_Error
	 * @since 1.7.0
	 */
	private static function fetch_license_details( $license_key ) {
		$license_key = sanitize_text_field( $license_key );
		$cache_key   = sanitize_key( self::get_config( 'option_name', 'wp_plugin_updates' ) . '_license_' . $license_key );
		$cached      = get_transient( $cache_key );

		// Abort early if details were cached.
		if ( false !== $cached ) {
			return $cached;
		}

		$license_api_url = self::get_config( 'license_api_url' );
		if ( empty( $license_api_url ) ) {
			return new \WP_Error( 'missing_config', __( 'License API URL is not configured.', self::get_config( 'text_domain' ) ) );
		}

		$headers = array_merge(
			array(
				'Accept' => 'application/json',
			),
			self::get_config( 'api_headers', array() )
		);

		// Fetch details remotely.
		$license = self::process_api_response(
			wp_remote_get(
				trailingslashit( $license_api_url ) . $license_key . '/?website=' . rawurlencode( home_url() ),
				array(
					'timeout' => 15,
					'headers' => $headers,
				)
			)
		);

		if ( is_wp_error( $license ) ) {
			return $license;
		}

		if ( empty( $license ) || empty( $license->license ) ) {
			return new \WP_Error( 'invalid_license', __( 'Error fetching your license key.', self::get_config( 'text_domain' ) ) );
		}

		$license = $license->license;

		// Cache for an hour.
		set_transient( $cache_key, $license, HOUR_IN_SECONDS );

		return $license;
	}

	/**
	 * Processes API responses
	 *
	 * @param mixed $response WP_HTTP Response.
	 * @return \WP_Error|object
	 */
	public static function process_api_response( $response ) {

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$res = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $res ) ) {
			return new \WP_Error( 'invalid_response', __( 'Invalid response from the server.', self::get_config( 'text_domain' ) ) );
		}

		if ( isset( $res->code ) && isset( $res->message ) ) {
			return new \WP_Error( $res->code, $res->message, (array) $res->data );
		}

		return $res;
	}

	/**
	 * Retrieves a list of installed extensions.
	 *
	 * @return array
	 */
	public static function get_installed_addons() {

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$prefix  = self::get_config( 'plugin_prefix', '' );
		$plugins = array();

		foreach ( get_plugins() as $filename => $data ) {
			$slug = basename( dirname( $filename ) );

			// If no prefix is set, include all plugins
			if ( empty( $prefix ) || 0 === strpos( $slug, $prefix ) ) {
				$data['_filename']       = $filename;
				$data['slug']            = $slug;
				$data['_type']           = 'plugin';
				$plugins[ $filename ]    = $data;
			}
		}

		return $plugins;
	}
}
