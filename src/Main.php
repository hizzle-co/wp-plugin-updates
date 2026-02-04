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
	 * @var Main[] Instances, grouped by host_name.
	 */
	private static $instances = array();

	/**
	 * The option name used to store the helper data.
	 *
	 * @var string
	 */
	private $option_name;

	/**
	 * @var string $host_name
	 */
	public $host_name;

	/**
	 * @var array $plugins $plugin_file => $git_url
	 */
	public $plugins = array();

	/**
	 * The prefix used to store the helper data.
	 */
	public $prefix;

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
	 * @param string $host_name The hostname, e.g, my.noptin.com
	 * @return Main|null The instance or null on failure.
	 */
	public static function instance( $host_name, $config = array() ) {
		// Check if we already have an instance.
		if ( isset( self::$instances[ $host_name ] ) ) {
			return self::$instances[ $host_name ];
		}

		$config['host_name']           = $host_name;
		self::$instances[ $host_name ] = new self( $config );

		return self::$instances[ $host_name ];
	}

	/**
	 * Main singleton instance.
	 *
	 * @param array $config Configuration array.
	 */
	private function __construct( $config ) {
		foreach ( $config as $key => $value ) {
			if ( property_exists( $this, $key ) ) {
				$this->{$key} = $value;
			}
		}

		if ( empty( $this->prefix ) ) {
			$this->prefix = str_replace( '.', '_', $this->host_name );
		}

		if ( empty( $this->option_name ) ) {
			$this->option_name = $this->prefix . '_helper_data';
		}

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_filter( "update_plugins_{$this->host_name}", array( $this, 'filter_update_plugins' ), 10, 4 );
		add_filter( 'plugins_api', array( $this, 'plugins_api' ), 20, 3 );
		add_action( 'plugins_loaded', array( $this, 'add_notice_unlicensed_product' ) );
		add_action( 'admin_notices', array( $this, 'maybe_print_expired_license_key_notice' ) );
	}

	/*
	|--------------------------------------------------------------------------
	| REST API routes
	|--------------------------------------------------------------------------
	|
	| Methods which activate/deactivate license keys via REST API.
	|
	*/

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
						'description'       => 'The plugin repo.',
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
						'description'       => 'The plugin repo.',
					),
				),
			)
		);
	}

	public function check_rest_permissions() {
		$cap = sprintf( 'get_%s_capability', $this->prefix );

		if ( function_exists( $cap ) ) {
			return current_user_can( call_user_func( $cap ) );
		}

		return current_user_can( 'manage_options' );
	}

	/**
	 * REST API callback to activate a license key.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response|WP_Error The response or error.
	 */
	public function rest_activate_license( $request ) {
		$license_key = $request->get_param( 'license_key' );
		$plugin      = $request->get_param( 'plugin' );

		// Delete cached details.
		delete_transient( sanitize_key( $this->prefix . '_license_' . $license_key ) );

		// Activate the license key remotely.
		$result = self::process_api_response(
			wp_remote_post(
				"https://{$this->host_name}/wp-json/hizzle/v1/licenses/{$license_key}/activate",
				array(
					'body'    => array(
						'website'   => home_url(),
						'downloads' => $plugin,
					),
					'headers' => array(
						'Accept' => 'application/json',
					),
				)
			)
		);

		// Abort if there was an error.
		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				$result->get_error_code(),
				sprintf(
					/* translators: %s: Error message. */
					__( 'There was an error activating your license key: %s', 'newsletter-optin-box' ),
					$result->get_error_message()
				),
				$result->get_error_data()
			);
		}

		// Save the license key.
		if ( ! empty( $plugin ) ) {
			$this->update( 'license_key_' . $plugin, $license_key );
		}

		// Global licenses.
		if ( ! empty( $result->is_membership ) && $result->is_membership ) {
			$this->update( 'license_key', $license_key );
		}

		// Clear update cache.
		delete_site_transient( 'update_plugins' );

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Your license key has been activated successfully. You will now receive updates and support for this website.', 'newsletter-optin-box' ),
				'data'    => $result,
			)
		);
	}

	/**
	 * Handle license deactivation.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 */
	public function rest_deactivate_license( $request ) {

		$plugin      = $request->get_param( 'plugin' );
		$license_key = $this->get_active_license_key( false, $plugin );

		if ( empty( $license_key ) ) {
			return array(
				'success' => true,
				'message' => __( 'License key deactivated successfully. You will no longer receive product updates and support for this site.', 'newsletter-optin-box' ),
			);
		}

		// Delete cached details.
		delete_transient( sanitize_key( $this->prefix . '_license_' . $license_key ) );

		// Deactive the license key remotely.
		$result = self::process_api_response(
			wp_remote_post(
				"https://{$this->host_name}/wp-json/hizzle/v1/licenses/{$license_key}/deactivate",
				array(
					'body'    => array(
						'website'   => home_url(),
						'downloads' => $plugin,
					),
					'headers' => array(
						'Accept' => 'application/json',
					),
				)
			)
		);

		// Abort if there was an error.
		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				$result->get_error_code(),
				sprintf(
					/* translators: %s: Error message. */
					__( 'There was an error deactivating your license key: %s', 'newsletter-optin-box' ),
					$result->get_error_message()
				),
				$result->get_error_data()
			);
		}

		// Save the license key.
		if ( ! empty( $plugin ) ) {
			$this->update( 'license_key_' . $plugin, '' );
		}

		// Global licenses.
		if ( ! empty( $result->is_membership ) && $result->is_membership ) {
			$this->update( 'license_key', '' );
		}

		// Clear update cache.
		delete_site_transient( 'update_plugins' );

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'License key deactivated successfully. You will no longer receive product updates and support for this site.', 'newsletter-optin-box' ),
			)
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Options
	|--------------------------------------------------------------------------
	|
	| Methods which get/update helper options.
	|
	*/

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
		$options = get_option( $this->option_name, array() );
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
		$options         = get_option( $this->option_name, array() );
		$options         = is_array( $options ) ? $options : array();
		$options[ $key ] = $value;
		return update_option( $this->option_name, $options, true );
	}

	/*
	|--------------------------------------------------------------------------
	| License keys
	|--------------------------------------------------------------------------
	|
	| Methods which get license key details locally and remotely.
	|
	*/

	/**
	 * Returns the active license key.
	 *
	 * @param bool $include_details
	 * @return object|\WP_Error|string|false
	 * @since 1.8.0
	 */
	public function get_active_license_key( $include_details = false, $plugin = '' ) {

		// Fetch the license key.
		$license_key = '';

		// Fetch plugin specific license key.
		if ( ! empty( $plugin ) ) {
			$license_key = $this->get( 'license_key_' . $plugin, '' );
		}

		// Fallback to global license key.
		if ( empty( $license_key ) ) {
			$license_key = $this->get( 'license_key' );
		}

		if ( empty( $license_key ) || ! $include_details ) {
			return $license_key;
		}

		$details = $this->fetch_license_details( $license_key, $plugin );

		if ( is_wp_error( $details ) ) {
			if ( in_array( 'hizzle_licenses_not_found', $details->get_error_codes(), true ) ) {
				if ( ! empty( $plugin ) ) {
					$this->update( 'license_key_' . $plugin, '' );
				}

				if ( $license_key === $this->get( 'license_key' ) ) {
					$this->update( 'license_key', '' );
				}
			}

			return $details;
		}

		if ( empty( $details ) ) {
			return $details;
		}

		// Check if it was deactivated remotely.
		if ( empty( $details->is_active_on_site ) ) {
			if ( ! empty( $plugin ) ) {
				$this->update( 'license_key_' . $plugin, '' );
			}

			if ( $license_key === $this->get( 'license_key' ) ) {
				$this->update( 'license_key', '' );
			}

			return false;
		}

		return $details;
	}

	/**
	 * Fetches license details from the cache or remotely.
	 *
	 * @param string $license_key
	 * @param string $plugin
	 * @return object|\WP_Error
	 * @since 1.7.0
	 */
	private function fetch_license_details( $license_key, $plugin = '' ) {
		$license_key = sanitize_text_field( $license_key );
		$cache_key   = sanitize_key( $this->prefix . '_license_' . $license_key );
		$cached      = get_transient( $cache_key );

		// Abort early if details were cached.
		if ( false !== $cached ) {
			return $cached;
		}

		// Fetch details remotely.
		$license = self::process_api_response(
			wp_remote_get(
				add_query_arg(
					array(
						'website'   => rawurlencode( home_url() ),
						'downloads' => rawurlencode( $plugin ),
					),
					"https://{$this->host_name}/wp-json/hizzle/v1/licenses/{$license_key}/"
				),
				array(
					'timeout' => 15,
					'headers' => array(
						'Accept' => 'application/json',
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
	 * Add action for queued plugins to display message for unlicensed plugins.
	 *
	 * @access  public
	 * @since   1.0.0
	 * @return  void
	 */
	public function add_notice_unlicensed_product() {
		if ( is_admin() && function_exists( 'get_plugins' ) ) {
			foreach ( array_keys( $this->plugins ) as $key ) {
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

		if ( empty( $r->package ) && isset( $this->plugins[ $plugin_data['plugin'] ]['admin'] ) ) {
			printf(
				'<span style="display: block;margin-top: 10px;font-weight: 600; color: #a00;">%s</span>',
				sprintf(
					wp_kses_post( 'To update, please <a href="%s">activate your license key</a>.' ),
					esc_url( $this->plugins[ $plugin_data['plugin'] ]['admin'] )
				)
			);
		}
	}

	/**
	 * Adds a plugin.
	 *
	 * @param string $plugin_path The full path to the plugin file.
	 * @param string $repo
	 * @param string $admin_url The admin URL for managing the license.
	 */
	public function add( $plugin_path, $repo, $admin_url = null ) {
		$this->plugins[ plugin_basename( $plugin_path ) ] = array(
			'repo'   => $repo,
			'slug'   => dirname( plugin_basename( $plugin_path ) ),
			'plugin' => plugin_basename( $plugin_path ),
			'path'   => $plugin_path,
			'admin'  => $admin_url,
		);
	}

	/**
	 * Get plugin by prop
	 *
	 * @param string $value
	 * @param string $property
	 */
	public function get_plugin_by_prop( $value, $property = 'plugin' ) {
		if ( 'plugin' === $property ) {
			return $this->plugins[ $value ] ?? null;
		}

		foreach ( $this->plugins as $plugin ) {
			if ( isset( $plugin[ $property ] ) && $plugin[ $property ] === $value ) {
				return $plugin;
			}
		}

		return null;
	}

	/**
	 * Plugin information callback for our plugins.
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

		$plugin = $this->get_plugin_by_prop( $args->slug, 'slug' );
		if ( empty( $plugin ) ) {
			return $response;
		}

		$response = $this->filter_update_plugins( false, array(), $plugin['plugin'], array(), true );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( $response && isset( $response->error ) && isset( $response->error_code ) ) {
			return new \WP_Error( $response->error_code, $response->error );
		}

		return $response;
	}

	/**
	 * Filters the update response for a given plugin hostname.
	 *
	 * The dynamic portion of the hook name, `$hostname`, refers to the hostname
	 * of the URI specified in the `Update URI` header field.
	 *
	 * @since 5.8.0
	 *
	 * @param array|false $update {
	 *     The plugin update data with the latest details. Default false.
	 *
	 *     @type string   $id           Optional. ID of the plugin for update purposes, should be a URI
	 *                                  specified in the `Update URI` header field.
	 *     @type string   $slug         Slug of the plugin.
	 *     @type string   $version      The version of the plugin.
	 *     @type string   $url          The URL for details of the plugin.
	 *     @type string   $package      Optional. The update ZIP for the plugin.
	 *     @type string   $tested       Optional. The version of WordPress the plugin is tested against.
	 *     @type string   $requires_php Optional. The version of PHP which the plugin requires.
	 *     @type bool     $autoupdate   Optional. Whether the plugin should automatically update.
	 *     @type string[] $icons        Optional. Array of plugin icons.
	 *     @type string[] $banners      Optional. Array of plugin banners.
	 *     @type string[] $banners_rtl  Optional. Array of plugin RTL banners.
	 *     @type array    $translations {
	 *         Optional. List of translation updates for the plugin.
	 *
	 *         @type string $language   The language the translation update is for.
	 *         @type string $version    The version of the plugin this translation is for.
	 *                                  This is not the version of the language file.
	 *         @type string $updated    The update timestamp of the translation file.
	 *                                  Should be a date in the `YYYY-MM-DD HH:MM:SS` format.
	 *         @type string $package    The ZIP location containing the translation update.
	 *         @type string $autoupdate Whether the translation should be automatically installed.
	 *     }
	 * }
	 * @param array       $plugin_data      Plugin headers.
	 * @param string      $plugin_file      Plugin filename.
	 * @param string[]    $locales          Installed locales to look up translations for.
	 * @param bool        $return_wp_error  Whether to return a WP_Error on failure.
	 * @return array|false|WP_Error The plugin update data or false if no update
	 */
	public function filter_update_plugins( $update, $plugin_data, $plugin_file, $locales, $return_wp_error = false ) {

		if ( is_array( $update ) || isset( $this->plugins[ $plugin_file ] ) ) {
			return $update;
		}

		// Prepare update URL.
		$url = add_query_arg(
			array(
				'hizzle_license_url' => rawurlencode( home_url() ),
				'hizzle_license'     => rawurlencode( $this->get_active_license_key( false, $this->plugins[ $plugin_file ]['repo'] ?? '' ) ),
				'downloads'          => rawurlencode( $this->plugins[ $plugin_file ]['repo'] ),
				'locales'            => rawurlencode( implode( ',', $locales ) ),
			),
			"https://{$this->host_name}/wp-json/hizzle_download/v1/versions"
		);

		$response = self::process_api_response(
			wp_remote_get(
				$url,
				array(
					'timeout' => 15,
					'headers' => array(
						'Accept' => 'application/json',
					),
				)
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_trigger_error(
				__FUNCTION__,
				sprintf(
					'An unexpected error occurred. Something may be wrong with %s or this server&#8217;s configuration. If you continue to have problems, contact support:- %s',
					sanitize_text_field( $this->host_name ),
					$response->get_error_message()
				),
				headers_sent() || WP_DEBUG ? E_USER_WARNING : E_USER_NOTICE
			);

			return $return_wp_error ? $response : false;
		}

		if ( ! isset( $response[ $this->plugins[ $plugin_file ]['repo'] ] ) ) {
			return $return_wp_error ? new \WP_Error(
				'hizzle_no_update_info',
				sprintf(
					'No update information found for %s. Please contact support if you believe this is an error.',
					sanitize_text_field( $plugin_data['Name'] ?? $plugin_file )
				)
			) : false;
		}

		$response = $response[ $this->plugins[ $plugin_file ]['repo'] ];

		if ( isset( $response->error ) && isset( $response->error_code ) ) {
			wp_trigger_error(
				__FUNCTION__,
				sprintf(
					'An unexpected error occurred. Something may be wrong with %s or this server&#8217;s configuration. If you continue to have problems, contact support:- %s',
					sanitize_text_field( $this->host_name ),
					sanitize_text_field( $response->error )
				),
				headers_sent() || WP_DEBUG ? E_USER_WARNING : E_USER_NOTICE
			);
		} else {
			if ( empty( $response->download_link ) ) {
				$response->upgrade_notice = sprintf(
					'To update, please <a href="%s">activate your license key</a>.',
					$this->plugins[ $plugin_file ]['admin'] ?? '#'
				);
			}

			$response->id          = $this->plugins[ $plugin_file ]['repo'];
			$response->slug        = $this->plugins[ $plugin_file ]['slug'];
			$response->plugin      = $plugin_file;
			$response->new_version = $response->version;
			$response->package     = $response->download_link;
			$response->tested      = $response->tested;
		}

		return $response;
	}

	/**
	 * Checks if we should print an expired license key notice.
	 *
	 */
	public function maybe_print_expired_license_key_notice() {

		// Fetch premium add-ons.
		$premium_addons = array();

		foreach ( $this->plugins as $plugin ) {
			$license_key = $this->get_active_license_key( false, $plugin['repo'] );
			if ( empty( $license_key ) ) {
				$premium_addons[] = $plugin['slug'];
			}
		}

		// Abort if none exists.
		if ( ! empty( $premium_addons ) ) {
			$notice  = '<strong>Your site is at RISK!</strong>. You need an active license key to keep using premium plugins.';

			// Add active addons.
			$notice .= "\n\n";
			$notice .= sprintf(
				// translators: %s: Plugin names.
				_n(
					'The following plugin is currently active: %s',
					'The following plugins are currently active: %s.',
					count( $premium_addons ),
					'newsletter-optin-box'
				),
				'<code>' . implode( '</code>, <code>', $premium_addons ) . '</code>'
			);

			// Add a link to the license page.
			$notice .= "\n\n" . '<a href="https://' . $this->host_name . '" class="button button-primary">' . __( 'Get a license key', 'newsletter-optin-box' ) . '</a>';

			printf(
				'<div class="error noptin-message">%s</div>',
				wp_kses_post( wpautop( $notice ) )
			);
		}
	}
}
