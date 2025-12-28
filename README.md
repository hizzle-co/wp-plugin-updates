# WP Plugin Updates

A flexible WordPress plugin updater library for serving updates from any custom server. This library allows you to distribute WordPress plugins with automatic updates from your own licensing server.

## Features

- **Flexible Configuration**: Works with any licensing server, not just specific ones
- **License Management**: Built-in license activation/deactivation system
- **Automatic Updates**: Integrates seamlessly with WordPress's update system
- **REST API**: Optional REST endpoints for license management
- **Customizable**: Extensive hooks and filters for customization
- **Secure**: Follows WordPress best practices and security standards

## Installation

Install via Composer:

```bash
composer require hizzle/wp-plugin-updates
```

## Basic Usage

### 1. Initialize the Library

In your main plugin file, initialize the library with your server configuration:

```php
<?php
use Hizzle\WP_Plugin_Updates\Main;
use Hizzle\WP_Plugin_Updates\Updater;

// Initialize with your server configuration
Main::init( array(
    'api_url'            => 'https://your-licensing-server.com',
    'plugin_prefix'      => 'myplugin-',
    'text_domain'        => 'my-plugin',
    'product_url'        => 'https://your-site.com/pricing/',
    'manage_license_page' => 'my-plugin-license',
) );

// Load the updater
Updater::load();
```

### 2. Configuration Options

| Option | Type | Required | Description |
|--------|------|----------|-------------|
| `api_url` | string | Yes | Base URL of your licensing server |
| `license_api_url` | string | No | Full URL to license API endpoint. Defaults to `{api_url}/wp-json/hizzle/v1/licenses` |
| `versions_api_url` | string | No | Full URL to versions API endpoint. Defaults to `{api_url}/wp-json/hizzle_download/v1/versions` |
| `option_name` | string | No | WordPress option name for storing data. Default: `wp_plugin_updates_data` |
| `plugin_prefix` | string | No | Prefix for your plugins (e.g., `myplugin-`). If empty, all plugins are checked |
| `text_domain` | string | No | Text domain for translations. Default: `default` |
| `api_headers` | array | No | Additional HTTP headers to send with API requests |
| `product_url` | string | No | URL to your product/pricing page |
| `manage_license_page` | string | No | Admin page slug for managing licenses |

### 3. Optional: Enable License Management UI

If you want to use the built-in license management REST API:

```php
use Hizzle\WP_Plugin_Updates\Helper;

Helper::load( array(
    'rest_namespace'       => 'my-plugin/v1',
    'permission_callback'  => 'manage_options',
    'enable_admin_notices' => true,
    'notice_callback'      => function( $message, $type ) {
        // Your custom notice handler
        add_action( 'admin_notices', function() use ( $message, $type ) {
            printf(
                '<div class="notice notice-%s"><p>%s</p></div>',
                esc_attr( $type ),
                esc_html( $message )
            );
        } );
    },
) );
```

## Server API Requirements

Your licensing server needs to implement the following endpoints:

### 1. License Details Endpoint

**GET** `{license_api_url}/{license_key}/?website={site_url}`

Response should include:
```json
{
    "license": {
        "is_active_on_site": true,
        // ... other license details
    }
}
```

### 2. License Activation Endpoint

**POST** `{license_api_url}/{license_key}/activate`

Body:
```json
{
    "website": "https://example.com"
}
```

### 3. License Deactivation Endpoint

**POST** `{license_api_url}/{license_key}/deactivate`

Body:
```json
{
    "website": "https://example.com"
}
```

### 4. Version Check Endpoint

**GET** `{versions_api_url}?hizzle_license={key}&hizzle_license_url={site_url}&downloads={plugins}&hash={hash}`

Response should include version info for each plugin:
```json
{
    "plugin-identifier": {
        "version": "1.2.0",
        "download_link": "https://...",
        "requires_php": "5.6",
        "name": "Plugin Name",
        "description": "...",
        // ... other plugin info
    }
}
```

## Advanced Usage

### Custom Plugin Identifiers

By default, the library sends plugin slugs to your API. You can customize this:

```php
add_filter( 'wp_plugin_updates_identifiers', function( $slugs, $prefix ) {
    // Convert slugs to your custom format
    $identifiers = array();
    foreach ( $slugs as $slug ) {
        $identifiers[] = 'github-org/' . $slug;
    }
    return $identifiers;
}, 10, 2 );
```

### Custom Plugin Information

```php
add_filter( 'wp_plugin_updates_plugin_identifier', function( $slug, $prefix ) {
    // Return custom identifier for plugins_api
    return 'github-org/' . $slug;
}, 10, 2 );
```

## Programmatic License Management

### Activate a License

```php
use Hizzle\WP_Plugin_Updates\Main;

// Save license key
Main::update( 'license_key', 'your-license-key' );

// Verify activation
$license = Main::get_active_license_key( true );
if ( is_wp_error( $license ) ) {
    // Handle error
}
```

### Deactivate a License

```php
use Hizzle\WP_Plugin_Updates\Main;

Main::update( 'license_key', '' );
```

### Check License Status

```php
use Hizzle\WP_Plugin_Updates\Main;

$license_key = Main::get_active_license_key();
$license_details = Main::get_active_license_key( true );

if ( is_wp_error( $license_details ) ) {
    // Handle error
} elseif ( empty( $license_details ) ) {
    // No active license
} else {
    // License is active
}
```

## Hooks

### Actions

- `wp_plugin_updates_helper_loaded` - Fires when the helper is loaded

### Filters

- `wp_plugin_updates_identifiers` - Filter plugin identifiers before API request
- `wp_plugin_updates_plugin_identifier` - Filter single plugin identifier
- `wp_plugin_updates_admin_notices` - Custom admin notices implementation

## Security

- All API requests include proper authentication headers
- License keys are sanitized before storage
- Transient caching prevents excessive API calls
- Follows WordPress coding standards and security best practices

## Requirements

- PHP 5.6 or higher
- WordPress 5.0 or higher

## Translation Support

This library uses a configurable text domain for translations. If you're using this library in your plugin:

1. Set the `text_domain` config to match your plugin's text domain
2. Translation strings in this library are marked with dynamic text domains
3. For gettext tools to extract these strings, you may want to create wrapper functions in your plugin that use string literals

Example:
```php
// In your plugin
function my_plugin_translate_updater_strings() {
    // These will be picked up by gettext
    __( 'License API URL is not configured.', 'my-plugin' );
    __( 'Error fetching your license key.', 'my-plugin' );
    // ... etc
}
```

## License

GPL-3.0-or-later

## Support

For issues and feature requests, please use the [GitHub issue tracker](https://github.com/hizzle-co/wp-plugin-updates/issues).
