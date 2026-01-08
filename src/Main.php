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
	 * @var Main[] Instances, grouped by API URL and plugin file.
	 */
	private static $instances = array();

	/**
	 * @var Updater The current updater instance.
	 */
	public $updater;

	/**
	 * @var Helper The helper instance.
	 */
	public $helper;

	/**
	 * Base URL of your licensing server, e.g, https://my-plugin-site.com.
	 *
	 * @var string
	 */
	public $api_url;

	/**
	 * The prefix used to store the helper data.
	 */
	public $prefix;

	/**
	 * For plugins that use addons instead of a premium version.
	 */
	public $group;

	/**
	 * @var string Full path to the main plugin file, e.g, my-plugin/my-plugin.php.
	 */
	public $plugin_file;

	/**
	 * @var string GitHub repository of the plugin, e.g, organization/my-plugin.
	 */
	public $github_repo;

	/**
	 * @var string Admin page slug for managing licenses. Defaults to ''.
	 */
	public $manage_license_page;

	/**
	 * Initialize the updater with configuration.
	 *
	 * @return Main|null The instance or null on failure.
	 */
	public static function instance( $instance, $config = array() ) {
		// Check if we already have an instance.
		if ( isset( self::$instances[ $instance ] ) ) {
			return self::$instances[ $instance ];
		}

		foreach ( array( 'api_url', 'plugin_file', 'github_repo' ) as $required_key ) {
			if ( empty( $config[ $required_key ] ) ) {
				_doing_it_wrong( __METHOD__, sprintf( 'Missing required config key: %s', esc_html( $required_key ) ), '1.0.0' );
				return null;
			}
		}

		self::$instances[ $instance ] = new self( $config );

		return self::$instances[ $instance ];
	}

	/**
	 * Main singleton instance.
	 *
	 * @param array $config Configuration array.
	 */
	private function __construct( $config ) {
		$config['api_url'] = untrailingslashit( $config['api_url'] );

		foreach ( $config as $key => $value ) {
			if ( property_exists( $this, $key ) ) {
				$this->{$key} = $value;
			}
		}

		if ( empty( $this->prefix ) ) {
			if ( ! empty( $this->group ) ) {
				$this->prefix = $this->group;
			} else {
				$this->prefix = str_replace( '.', '_', wp_parse_url( $this->api_url, PHP_URL_HOST ) );
			}
		}

		$this->updater = new Updater( $this );
		$this->helper  = new Helper( $this );

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Checks if this is noptin.
	 */
	public function is_noptin() {
		return 'noptin' === $this->prefix;
	}

	/**
	 * Checks if this is our plugin.
	 *
	 * @param string $plugin_file The plugin file to check.
	 */
	public function is_our_plugin( $plugin_file ) {

		if ( $plugin_file === $this->plugin_file ) {
			return true;
		}

		return ! empty( $this->group ) && 0 === strpos( $plugin_file, $this->group . '-' );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_rest_routes() {
		$namespace = $this->prefix . '/v1';
		register_rest_route(
			$namespace,
			'/license/activate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_activate_license' ),
				'permission_callback' => array( $this, 'check_rest_permissions' ),
				'args'                => array(
					'license_key' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => 'The license key to activate.',
					),
					'plugin'      => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => 'The plugin slug (for multi-plugin licenses).',
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/license/deactivate',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'rest_deactivate_license' ),
				'permission_callback' => array( $this, 'check_rest_permissions' ),
				'args'                => array(
					'plugin' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => 'The plugin slug (for multi-plugin licenses).',
					),
				),
			)
		);
	}

	public function check_rest_permissions() {
		if ( $this->is_noptin() && function_exists( 'get_noptin_capability' ) ) {
			return current_user_can( get_noptin_capability() );
		}

		return current_user_can( 'manage_options' );
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
	public function get( $key, $default_value = false ) {
		$options = get_option( $this->prefix . '_helper_data', array() );
		$options = is_array( $options ) ? $options : array();

		return array_key_exists( $key, $options ) ? $options[ $key ] : $default_value;
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
	public function update( $key, $value ) {
		$options         = get_option( $this->prefix . '_helper_data', array() );
		$options         = is_array( $options ) ? $options : array();
		$options[ $key ] = $value;
		return update_option( $this->prefix . '_helper_data', $options, true );
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
	public function get_active_license_key( $include_details = false, $plugin = '' ) {

		// Fetch the license key.
		$option_key  = $this->is_noptin() ? 'license_key' : ( 'license_key_' . $plugin );
		$license_key = $this->get( $option_key );

		// If not set, try to fetch the old style license keys.
		if ( empty( $license_key ) && $this->is_noptin() ) {
			$licenses = $this->get( 'active_license_keys' );

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

		$details = $this->fetch_license_details( $license_key );

		if ( is_wp_error( $details ) ) {
			if ( in_array( 'hizzle_licenses_not_found', $details->get_error_codes(), true ) ) {
				$this->update( $option_key, '' );
			}

			return $details;
		}

		if ( empty( $details ) ) {
			return $details;
		}

		// Check if it was deactivated remotely.
		if ( empty( $details->is_active_on_site ) ) {
			$this->update( $option_key, '' );
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
	private function fetch_license_details( $license_key ) {
		$license_key = sanitize_text_field( $license_key );
		$cache_key   = sanitize_key( 'noptin_license_' . $license_key );
		$cached      = get_transient( $cache_key );

		// Abort early if details were cached.
		if ( false !== $cached ) {
			return $cached;
		}

		// Fetch details remotely.
		$license = self::process_api_response(
			wp_remote_get(
				"$this->api_url/wp-json/hizzle/v1/licenses/$license_key/?website=" . rawurlencode( home_url() ),
				array(
					'timeout' => 15,
					'headers' => array(
						'Accept'           => 'application/json',
						'X-Requested-With' => 'WPPluginUpdates',
					),
				)
			)
		);

		if ( is_wp_error( $license ) ) {
			return $license;
		}

		if ( empty( $license ) || empty( $license->license ) ) {
			return new \WP_Error( 'invalid_license', 'Error fetching your license key.' );
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
			return new \WP_Error( 'invalid_response', 'Invalid response from the server.' );
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
	public function get_installed_addons() {

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$our_plugins = array();

		foreach ( get_plugins() as $filename => $data ) {
			$slug = basename( dirname( $filename ) );

			if ( $this->is_our_plugin( $filename ) ) {
				$data['_filename']        = $filename;
				$data['slug']             = $slug;
				$data['_type']            = 'plugin';
				$data['github_repo']      = $filename === $this->plugin_file ? $this->github_repo : 'hizzle-co/' . $slug;
				$our_plugins[ $filename ] = $data;
			}
		}

		return $our_plugins;
	}
}
