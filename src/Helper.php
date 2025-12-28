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

	/**
	 * @var Main The main instance.
	 */
	private $main;

	public static $temporary_key = '';
	public static $error         = '';
	public static $success       = '';

	/**
	 * Loads the class, runs on init.
	 *
	 * @param Main $main The main instance.
	 */
	public function __construct( $main ) {
		$this->main = $main;

		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_rest_routes() {
		$namespace = $this->main->prefix . '/v1';

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
				),
			)
		);

		register_rest_route(
			$namespace,
			'/license/deactivate',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'handle_license_deactivation' ),
				'permission_callback' => array( $this, 'check_rest_permissions' ),
			)
		);
	}

	/**
	 * Checks capability.
	 */
	public function check_permissions() {
		if ( 'noptin' === $this->main->group && function_exists( 'get_noptin_capability' ) ) {
			return current_user_can( get_noptin_capability() );
		}

		return current_user_can( 'manage_options' );
	}

	/**
	 * Fires after admin screen inits.
	 */
	public function admin_init() {

		if ( ! $this->check_permissions() ) {
			return;
		}

		$prefix = $this->main->prefix;
		// Handle license deactivation.
		if ( isset( $_GET[ $prefix . '-deactivate-license-nonce' ] ) && wp_verify_nonce( rawurldecode( $_GET[ $prefix . '-deactivate-license-nonce' ] ), $prefix . '-deactivate-license' ) ) {
			$this->handle_license_deactivation();
		}

		// Handle license activation.
		if ( isset( $_POST[ $prefix . '-license' ] ) && isset( $_POST[ $prefix . '_save_license_key_nonce' ] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $prefix . '_save_license_key_nonce' ] ) ), $prefix . '_save_license_key' ) ) {
			self::handle_license_save( sanitize_text_field( wp_unslash( $_POST[ $prefix . '-license' ] ) ) );
		}
	}

	/**
	 * Saves a license key.
	 *
	 * @param string $license_key The license key to save.
	 */
	private function handle_license_save( $license_key ) {

		// Prepare license key.
		$license_key = sanitize_text_field( $license_key );

		if ( empty( $license_key ) ) {
			return;
		}

		// Delete cached details.
		delete_transient( sanitize_key( $this->main->prefix . '_license_' . $license_key ) );

		// Activate the license key remotely.
		$result = $this->main->process_api_response(
			wp_remote_post(
				$this->main->api_url . '/wp-json/hizzle/v1/licenses/' . $license_key . '/activate',
				array(
					'body'    => array(
						'website' => home_url(),
					),
					'headers' => array(
						'Accept' => 'application/json',
					),
				)
			)
		);

		// Abort if there was an error.
		if ( is_wp_error( $result ) ) {
			self::$temporary_key = $license_key;
			self::$error         = sprintf(
				'There was an error activating your license key: %s',
				$result->get_error_message()
			);
			return false;
		}

		// Save the license key.
		$this->main->update( 'license_key', $license_key );
		$this->main->updater->flush_updates_cache();

		self::$success = 'Your license key has been activated successfully. You will now receive updates and support for this website.';
		return $result;
	}

	/**
	 * REST API callback to activate a license key.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response|\WP_Error The response or error.
	 */
	public function rest_activate_license( $request ) {
		$result = $this->handle_license_save( $request->get_param( 'license_key' ) );

		// Abort if there was an error.
		if ( empty( $result ) ) {
			if ( ! empty( self::$error ) ) {
				return new \WP_Error(
					'activation_failed',
					self::$error,
					array( 'status' => 400 )
				);
			}

			return new \WP_Error(
				'activation_failed',
				'There was an error activating your license key.',
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => self::$success,
				'data'    => $result,
			)
		);
	}

	/**
	 * Handle license deactivation.
	 *
	 */
	public function handle_license_deactivation() {

		$license_key = $this->main->get_active_license_key();

		if ( empty( $license_key ) ) {
			return array(
				'success' => true,
				'message' => 'License key deactivated successfully. You will no longer receive product updates and support for this site.',
			);
		}

		// Delete cached details.
		delete_transient( sanitize_key( $this->main->prefix . '_license_' . $license_key ) );

		// Deactive the license key remotely.
		$result = $this->main->process_api_response(
			wp_remote_post(
				$this->main->api_url . '/wp-json/hizzle/v1/licenses/' . $license_key . '/deactivate',
				array(
					'body'    => array(
						'website' => home_url(),
					),
					'headers' => array(
						'Accept' => 'application/json',
					),
				)
			)
		);

		// Abort if there was an error.
		if ( is_wp_error( $result ) ) {
			self::$error = sprintf(
				'There was an error deactivating your license key: %s',
				$result->get_error_message()
			);

			return $result;
		}

		// Save the license key.
		$this->main->update( 'license_key', '' );
		$this->main->updater->flush_updates_cache();

		self::$success = 'License key deactivated successfully. You will no longer receive product updates and support for this site.';
		return array(
			'success' => true,
			'message' => self::$success,
		);
	}
}
