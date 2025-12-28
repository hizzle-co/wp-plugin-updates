# Changelog

## [Unreleased] - Generic Library Conversion

### Added
- Composer package configuration (`composer.json`)
- Comprehensive documentation (`README.md`)
- Example usage file (`example.php`) with 8 integration patterns
- `.gitignore` for composer library best practices
- Configurable API endpoints for any licensing server
- Plugin identifier filter hooks (`wp_plugin_updates_identifiers`, `wp_plugin_updates_plugin_identifier`)
- Optional Helper class with REST API for license management
- Flexible notice callback system

### Changed
- **BREAKING**: All hardcoded Noptin-specific URLs and values are now configurable
- `Main::init()` now required to configure the library before use
- All option names and transient keys now use configurable prefix
- Plugin detection is now generic with optional prefix filtering
- Helper class REST endpoints are now configurable
- Text domain is now configurable for translations

### Fixed
- Array mapping bug in plugin identifier filter
- Inconsistent default values across all files
- Missing sanitization on cache keys
- Hash validation now checks for empty values
- Replaced anonymous functions with static callbacks for better maintainability

### Removed
- Hardcoded `noptin.com` and `my.noptin.com` URLs
- Hardcoded option names (`noptin_helper_data`)
- Noptin-specific function calls (`noptin()`, `get_noptin_capability()`)
- `connections.json` file and related methods
- Noptin-specific email settings filter

### Security
- All transient/cache keys are properly sanitized with `sanitize_key()`
- All admin URLs properly sanitize user-provided slugs
- All user inputs properly sanitized
- All outputs properly escaped
- No XSS, SQL injection, or dangerous function vulnerabilities

## Migration Guide

### For Noptin

To continue using this library with Noptin:

```php
\Hizzle\WP_Plugin_Updates\Main::init( array(
    'api_url'            => 'https://my.noptin.com',
    'plugin_prefix'      => 'noptin-',
    'text_domain'        => 'newsletter-optin-box',
    'product_url'        => 'https://noptin.com/pricing/',
    'manage_license_page' => 'noptin-addons',
) );

\Hizzle\WP_Plugin_Updates\Updater::load();
```

### For Other Plugins

```php
\Hizzle\WP_Plugin_Updates\Main::init( array(
    'api_url'            => 'https://your-server.com',
    'plugin_prefix'      => 'yourplugin-',
    'text_domain'        => 'your-plugin',
    'product_url'        => 'https://your-site.com/pricing/',
    'manage_license_page' => 'your-license-page',
) );

\Hizzle\WP_Plugin_Updates\Updater::load();
```
