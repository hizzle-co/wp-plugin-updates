<?php
/**
 * Main Helper class.
 */

namespace Hizzle\WP_Plugin_Updates;

defined( 'ABSPATH' ) || exit;

/**
 * Helper Class
 *
 * The main entry-point for all things related to the Helper.
 * This class provides optional UI functionality and can be skipped if you're handling
 * license management through your own interface.
 *
 * @since 1.5.0
 *
 */
class Helper {

	public static $temporary_key    = '';
	public static $activation_error = '';

	/**
	 * Loads the helper class, runs on init.
	 *
	 * @param array $args Optional arguments for configuring the helper.
	 *                    - 'rest_namespace' (string) REST API namespace. Default: 'wp-plugin-updates/v1'
	 *                    - 'permission_callback' (callable) Permission callback for REST routes. Default: 'manage_options'
	 *                    - 'enable_admin_notices' (bool) Whether to show admin notices. Default: false
	 *                    - 'notice_callback' (callable) Callback to display success/error notices.
	 */
	public static function load( $args = array() ) {
		$defaults = array(
			'rest_namespace'       => 'wp-plugin-updates/v1',
			'permission_callback'  => 'manage_options',
			'enable_admin_notices' => false,
			'notice_callback'      => null,
		);

		$args = wp_parse_args( $args, $defaults );

		// Store args for later use
		Main::init( array_merge( Main::get_config( '', array() ), array( 'helper_args' => $args ) ) );

		if ( ! empty( $args['rest_namespace'] ) ) {
			add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes_callback' ) );
		}

		if ( ! empty( $args['enable_admin_notices'] ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		}

		do_action( 'wp_plugin_updates_helper_loaded' );
	}

	/**
	 * Callback for REST API init that passes arguments.
	 */
	public static function register_rest_routes_callback() {
		$helper_args = Main::get_config( 'helper_args', array() );
		if ( ! empty( $helper_args ) ) {
			self::register_rest_routes( $helper_args );
		}
	}

	/**
	 * Register REST API routes.
	 *
	 * @param array $args Helper arguments.
	 */
	public static function register_rest_routes( $args ) {
		$namespace           = $args['rest_namespace'];
		$permission_callback = $args['permission_callback'];

		// If it's a string, convert it to a callable checking the capability
		if ( is_string( $permission_callback ) ) {
			$capability          = $permission_callback;
			$permission_callback = function () use ( $capability ) {
				return current_user_can( $capability );
			};
		}

		register_rest_route(
			$namespace,
			'/license/activate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_activate_license' ),
				'permission_callback' => $permission_callback,
				'args'                => array(
					'license_key' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'The license key to activate.', Main::get_config( 'text_domain' ) ),
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/license/deactivate',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'handle_license_deactivation' ),
				'permission_callback' => $permission_callback,
			)
		);
	}

	/**
	 * Saves a license key.
	 *
	 * @param string $license_key The license key to save.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private static function handle_license_save( $license_key ) {

		// Prepare license key.
		$license_key = sanitize_text_field( $license_key );

		if ( empty( $license_key ) ) {
			return false;
		}

		$option_prefix   = Main::get_config( 'option_name', 'wp_plugin_updates_data' );
		$license_api_url = Main::get_config( 'license_api_url' );

		if ( empty( $license_api_url ) ) {
			self::$activation_error = __( 'License API URL is not configured.', Main::get_config( 'text_domain' ) );
			return false;
		}

		// Delete cached details.
		delete_transient( sanitize_key( $option_prefix . '_license_' . $license_key ) );

		$headers = array_merge(
			array(
				'Accept' => 'application/json',
			),
			Main::get_config( 'api_headers', array() )
		);

		// Activate the license key remotely.
		$result = Main::process_api_response(
			wp_remote_post(
				trailingslashit( $license_api_url ) . $license_key . '/activate',
				array(
					'body'    => array(
						'website' => home_url(),
					),
					'headers' => $headers,
				)
			)
		);

		// Abort if there was an error.
		if ( is_wp_error( $result ) ) {
			self::$temporary_key    = $license_key;
			self::$activation_error = sprintf(
				/* translators: %s: Error message. */
				__( 'There was an error activating your license key: %s', Main::get_config( 'text_domain' ) ),
				$result->get_error_message()
			);
			return false;
		}

		// Save the license key.
		Main::update( 'license_key', $license_key );
		Main::update( 'active_license_keys', '' );

		Updater::flush_updates_cache();

		self::show_notice( __( 'Your license key has been activated successfully. You will now receive updates and support for this website.', Main::get_config( 'text_domain' ) ), 'success' );
		return $result;
	}

	/**
	 * Show a notice (uses callback if provided, otherwise does nothing).
	 *
	 * @param string $message The notice message.
	 * @param string $type The notice type (success, error, warning, info).
	 */
	private static function show_notice( $message, $type = 'info' ) {
		$helper_args     = Main::get_config( 'helper_args', array() );
		$notice_callback = isset( $helper_args['notice_callback'] ) ? $helper_args['notice_callback'] : null;

		if ( is_callable( $notice_callback ) ) {
			call_user_func( $notice_callback, $message, $type );
		}
	}

	/**
	 * REST API callback to activate a license key.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response|WP_Error The response or error.
	 */
	public static function rest_activate_license( $request ) {
		$result = self::handle_license_save( $request->get_param( 'license_key' ) );

		// Abort if there was an error.
		if ( empty( $result ) ) {
			if ( ! empty( self::$activation_error ) ) {
				return new \WP_Error(
					'activation_failed',
					self::$activation_error,
					array( 'status' => 400 )
				);
			}

			return new \WP_Error(
				'activation_failed',
				__( 'There was an error activating your license key.', Main::get_config( 'text_domain' ) ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Your license key has been activated successfully. You will now receive updates and support for this website.', Main::get_config( 'text_domain' ) ),
				'data'    => $result,
			)
		);
	}

	/**
	 * Handle license deactivation.
	 *
	 * @return array|WP_Error
	 */
	public static function handle_license_deactivation() {

		$license_key = Main::get_active_license_key();

		if ( empty( $license_key ) ) {
			return array(
				'success' => true,
				'message' => __( 'License key deactivated successfully. You will no longer receive product updates and support for this site.', Main::get_config( 'text_domain' ) ),
			);
		}

		$option_prefix   = Main::get_config( 'option_name', 'wp_plugin_updates_data' );
		$license_api_url = Main::get_config( 'license_api_url' );

		if ( empty( $license_api_url ) ) {
			return new \WP_Error( 'missing_config', __( 'License API URL is not configured.', Main::get_config( 'text_domain' ) ) );
		}

		// Delete cached details.
		delete_transient( sanitize_key( $option_prefix . '_license_' . $license_key ) );

		$headers = array_merge(
			array(
				'Accept' => 'application/json',
			),
			Main::get_config( 'api_headers', array() )
		);

		// Deactivate the license key remotely.
		$result = Main::process_api_response(
			wp_remote_post(
				trailingslashit( $license_api_url ) . $license_key . '/deactivate',
				array(
					'body'    => array(
						'website' => home_url(),
					),
					'headers' => $headers,
				)
			)
		);

		// Abort if there was an error.
		if ( is_wp_error( $result ) ) {
			self::show_notice(
				sprintf(
					/* translators: %s: Error message. */
					__( 'There was an error deactivating your license key: %s', Main::get_config( 'text_domain' ) ),
					$result->get_error_message()
				),
				'error'
			);

			return $result;
		}

		// Save the license key.
		Main::update( 'license_key', '' );

		Updater::flush_updates_cache();

		self::show_notice( __( 'License key deactivated successfully. You will no longer receive product updates and support for this site.', Main::get_config( 'text_domain' ) ), 'success' );
		return array(
			'success' => true,
			'message' => __( 'License key deactivated successfully. You will no longer receive product updates and support for this site.', Main::get_config( 'text_domain' ) ),
		);
	}

	/**
	 * Various Helper-related admin notices.
	 * This is a generic implementation that can be overridden via filters.
	 */
	public static function admin_notices() {
		// Allow custom implementations to handle this
		do_action( 'wp_plugin_updates_admin_notices' );
	}
}
