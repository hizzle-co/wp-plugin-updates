# WP Plugin Updates

This library allows you to distribute WordPress plugins with automatic updates from your own Hizzle Licenses licensing server.

## Features

- **Flexible Configuration**: Works with any custom licensing server in addition to Hizzle Licenses
- **License Management**: Built-in license activation/deactivation system
- **Automatic Updates**: Integrates seamlessly with WordPress's update system
- **REST API**: Optional REST endpoints for license management
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

/**
 * Plugin Name: My Plugin
 * Plugin URI: https://myplugin.com
 * Description: Example plugin using Hizzle Licenses updater library
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://yourwebsite.com
 * Text Domain: my-plugin
 * Domain Path: /languages/
 * Requires at least: 5.5
 * Requires PHP: 7.0
 * Update URI: your-licensing-server.com/your-plugin-slug
 */

// Update URI above is required for WordPress to recognize this plugin as updateable from your server. It should match the hostname of your licensing server.

defined( 'ABSPATH' ) || exit;

// Include the auto loader.
require 'vendor/autoload.php';

// Initialize with your server configuration
$updater = \Hizzle\WP_Plugin_Updates\Main::instance(
    'your-licensing-server.com', // Hostname of your licensing server.
    array(
        'prefix' => 'my_prefix', // Optional prefix for options and transients (default: 'your_licensing_server_com').
    )
);

// Register your plugin.
$updater->add( __FILE__, 'github-org/your-plugin-slug', admin_url( 'admin.php?page=my-plugin-license' ) );
```

## Using with custom Licensing Servers (other than Hizzle Licenses)

Your licensing server needs to implement the following endpoints:

### 1. License Details Endpoint

**GET** `your-licensing-server.com/wp-json/hizzle/v1/licenses/{license_key}/?website={site_url}&downloads={plugins}`

Where `downloads` is a comma-separated list of plugin identifiers (e.g. `github-org/your-plugin-slug,github-org/another-plugin`).

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

**POST** `your-licensing-server.com/wp-json/hizzle/v1/licenses/{license_key}/activate`

Body:
```json
{
    "website": "https://example.com",
    "downloads": "github-org/your-plugin-slug,github-org/another-plugin"
}
```

As usual, downloads is a comma-separated list of plugin identifiers that the license should be activated for.

### 3. License Deactivation Endpoint

**POST** `your-licensing-server.com/wp-json/hizzle/v1/licenses/{license_key}/deactivate`

Body:
```json
{
    "website": "https://example.com",
    "downloads": "github-org/your-plugin-slug,github-org/another-plugin"
}
```

### 4. Version Check Endpoint

**GET** `your-licensing-server.com/wp-json/hizzle_download/v1/versions?hizzle_license={key}&hizzle_license_url={home_url}&downloads={plugins}`

Where `downloads` is a comma-separated list of plugin identifiers (e.g. `github-org/your-plugin-slug,github-org/another-plugin`).

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

- Leave download_link empty if the license is not valid or does not have access to updates.
- If you have both the Hizzle Downloads and Hizzle Licenses plugins installed, it will automatically handle the version check and license validation for you.

### Custom Plugin Identifiers

The library sends the plugin identifiers you provide (e.g. `github-org/your-plugin-slug`) to the licensing server for license validation and version checks. This is useful if you sell multiple plugins and want to manage them under the same license server.

## Security

- All API requests include proper authentication headers
- License keys are sanitized before storage
- Transient caching prevents excessive API calls
- Follows WordPress coding standards and security best practices

## Requirements

- PHP 5.6 or higher
- WordPress 5.0 or higher

## License

GPL-3.0-or-later

## Support

For issues and feature requests, please use the [GitHub issue tracker](https://github.com/hizzle-co/wp-plugin-updates/issues).
