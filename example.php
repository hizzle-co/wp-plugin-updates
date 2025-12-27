<?php
/**
 * Example usage of the WP Plugin Updates library
 *
 * This file demonstrates how to integrate the library into your WordPress plugin.
 */

// Don't access this file directly.
defined( 'ABSPATH' ) || exit;

/**
 * Example 1: Basic Setup with Hizzle Licensing Server
 *
 * This is the simplest setup for plugins using the Hizzle licensing system.
 */
function example_basic_setup() {
	// Initialize the updater
	\Hizzle\WP_Plugin_Updates\Main::init( array(
		'api_url'            => 'https://my.noptin.com',
		'plugin_prefix'      => 'myplugin-',
		'text_domain'        => 'my-plugin',
		'product_url'        => 'https://my-site.com/pricing/',
		'manage_license_page' => 'my-plugin-license',
	) );

	// Load the updater
	\Hizzle\WP_Plugin_Updates\Updater::load();
}

/**
 * Example 2: Custom Licensing Server
 *
 * Setup for a completely custom licensing server with different API endpoints.
 */
function example_custom_server() {
	\Hizzle\WP_Plugin_Updates\Main::init( array(
		'api_url'            => 'https://my-custom-server.com',
		'license_api_url'    => 'https://my-custom-server.com/api/licenses',
		'versions_api_url'   => 'https://my-custom-server.com/api/versions',
		'option_name'        => 'my_plugin_updates',
		'plugin_prefix'      => 'my-custom-',
		'text_domain'        => 'my-plugin',
		'api_headers'        => array(
			'X-API-Key'        => 'your-api-key',
			'X-Requested-With' => 'MyPlugin',
		),
		'product_url'        => 'https://my-site.com/buy/',
		'manage_license_page' => 'my-license-settings',
	) );

	\Hizzle\WP_Plugin_Updates\Updater::load();
}

/**
 * Example 3: With License Management UI
 *
 * Includes the optional Helper class for REST API license management.
 */
function example_with_ui() {
	// Basic init
	\Hizzle\WP_Plugin_Updates\Main::init( array(
		'api_url'            => 'https://my.noptin.com',
		'plugin_prefix'      => 'myplugin-',
		'text_domain'        => 'my-plugin',
		'product_url'        => 'https://my-site.com/pricing/',
		'manage_license_page' => 'my-plugin-license',
	) );

	// Load updater
	\Hizzle\WP_Plugin_Updates\Updater::load();

	// Load helper with REST API
	\Hizzle\WP_Plugin_Updates\Helper::load( array(
		'rest_namespace'       => 'my-plugin/v1',
		'permission_callback'  => 'manage_options',
		'enable_admin_notices' => true,
		'notice_callback'      => function( $message, $type ) {
			// Store in transient to display on next page load
			set_transient( 'my_plugin_notice', array(
				'message' => $message,
				'type'    => $type,
			), 30 );
		},
	) );

	// Display stored notices
	add_action( 'admin_notices', function() {
		$notice = get_transient( 'my_plugin_notice' );
		if ( $notice ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $notice['type'] ),
				esc_html( $notice['message'] )
			);
			delete_transient( 'my_plugin_notice' );
		}
	} );
}

/**
 * Example 4: GitHub-based Plugin Distribution
 *
 * Use custom filters to work with GitHub releases.
 */
function example_github_integration() {
	\Hizzle\WP_Plugin_Updates\Main::init( array(
		'api_url'            => 'https://my-licensing-server.com',
		'plugin_prefix'      => 'my-github-',
		'text_domain'        => 'my-plugin',
		'product_url'        => 'https://github.com/my-org',
	) );

	// Convert plugin slugs to GitHub repo identifiers
	add_filter( 'wp_plugin_updates_identifiers', function( $slugs, $prefix ) {
		$identifiers = array();
		foreach ( $slugs as $slug ) {
			// Remove prefix and create GitHub identifier
			$repo = str_replace( $prefix, '', $slug );
			$identifiers[] = 'my-org/' . $repo;
		}
		return $identifiers;
	}, 10, 2 );

	// Same for individual plugin info
	add_filter( 'wp_plugin_updates_plugin_identifier', function( $slug, $prefix ) {
		$repo = str_replace( $prefix, '', $slug );
		return 'my-org/' . $repo;
	}, 10, 2 );

	\Hizzle\WP_Plugin_Updates\Updater::load();
}

/**
 * Example 5: Programmatic License Activation
 *
 * Activate a license key programmatically (e.g., during plugin setup).
 */
function example_activate_license( $license_key ) {
	// First, ensure the library is initialized
	\Hizzle\WP_Plugin_Updates\Main::init( array(
		'api_url'       => 'https://my.noptin.com',
		'plugin_prefix' => 'myplugin-',
		'text_domain'   => 'my-plugin',
	) );

	// Save the license key
	\Hizzle\WP_Plugin_Updates\Main::update( 'license_key', $license_key );

	// Verify it was activated successfully
	$license_details = \Hizzle\WP_Plugin_Updates\Main::get_active_license_key( true );

	if ( is_wp_error( $license_details ) ) {
		return array(
			'success' => false,
			'message' => $license_details->get_error_message(),
		);
	}

	if ( empty( $license_details ) || empty( $license_details->is_active_on_site ) ) {
		return array(
			'success' => false,
			'message' => __( 'License key is not active on this site.', 'my-plugin' ),
		);
	}

	// Flush update cache to check for updates immediately
	\Hizzle\WP_Plugin_Updates\Updater::flush_updates_cache();

	return array(
		'success' => true,
		'message' => __( 'License activated successfully!', 'my-plugin' ),
		'license' => $license_details,
	);
}

/**
 * Example 6: Check for Updates Programmatically
 *
 * Useful for displaying update notifications in your plugin's admin interface.
 */
function example_check_updates() {
	// Get the number of available updates
	$updates_count = \Hizzle\WP_Plugin_Updates\Updater::get_updates_count();

	if ( $updates_count > 0 ) {
		// Display update badge HTML
		echo \Hizzle\WP_Plugin_Updates\Updater::get_updates_count_html();
	}

	// Check if a specific plugin has an update
	$has_update = \Hizzle\WP_Plugin_Updates\Updater::has_extension_update( 'myplugin-premium' );

	if ( $has_update ) {
		echo '<div class="notice notice-warning">';
		echo '<p>' . esc_html__( 'A new version of MyPlugin Premium is available!', 'my-plugin' ) . '</p>';
		echo '</div>';
	}
}

/**
 * Example 7: Custom Admin Notices
 *
 * Implement your own admin notice logic.
 */
function example_custom_notices() {
	\Hizzle\WP_Plugin_Updates\Main::init( array(
		'api_url'       => 'https://my.noptin.com',
		'plugin_prefix' => 'myplugin-',
		'text_domain'   => 'my-plugin',
	) );

	\Hizzle\WP_Plugin_Updates\Updater::load();

	\Hizzle\WP_Plugin_Updates\Helper::load( array(
		'enable_admin_notices' => true,
	) );

	// Implement custom notice display
	add_action( 'wp_plugin_updates_admin_notices', function() {
		// Check if license is active
		$license = \Hizzle\WP_Plugin_Updates\Main::get_active_license_key( true );

		if ( is_wp_error( $license ) || empty( $license ) ) {
			?>
			<div class="notice notice-error">
				<p>
					<?php esc_html_e( 'Your license key is not active.', 'my-plugin' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=my-plugin-license' ) ); ?>">
						<?php esc_html_e( 'Activate License', 'my-plugin' ); ?>
					</a>
				</p>
			</div>
			<?php
		}
	} );
}

/**
 * Example 8: Complete Integration in a Plugin
 *
 * This shows how you might integrate everything in your main plugin file.
 */
class My_Plugin {

	public function __construct() {
		// Initialize on plugins_loaded
		add_action( 'plugins_loaded', array( $this, 'init_updater' ) );
	}

	public function init_updater() {
		// Check if the library is available
		if ( ! class_exists( '\Hizzle\WP_Plugin_Updates\Main' ) ) {
			return;
		}

		// Initialize
		\Hizzle\WP_Plugin_Updates\Main::init( array(
			'api_url'            => 'https://my.noptin.com',
			'plugin_prefix'      => 'myplugin-',
			'text_domain'        => 'my-plugin',
			'product_url'        => 'https://my-site.com/pricing/',
			'manage_license_page' => 'my-plugin-settings',
		) );

		// Load updater
		\Hizzle\WP_Plugin_Updates\Updater::load();

		// Only load helper in admin
		if ( is_admin() ) {
			$this->init_helper();
		}
	}

	private function init_helper() {
		\Hizzle\WP_Plugin_Updates\Helper::load( array(
			'rest_namespace'       => 'my-plugin/v1',
			'permission_callback'  => array( $this, 'can_manage_license' ),
			'enable_admin_notices' => true,
			'notice_callback'      => array( $this, 'show_notice' ),
		) );
	}

	public function can_manage_license() {
		return current_user_can( 'manage_options' );
	}

	public function show_notice( $message, $type ) {
		add_action( 'admin_notices', function() use ( $message, $type ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $type ),
				wp_kses_post( $message )
			);
		} );
	}
}

// Initialize the plugin
new My_Plugin();
