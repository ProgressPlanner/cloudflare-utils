# Cloudflare Utils

A WordPress plugin for Cloudflare cache purging, cache headers and tags, cache-safe comment forms, and exposed credential protection.

## WordPress abilities

When the WordPress Abilities API is available, the plugin registers the `cloudflare-utils` category and these abilities. Every ability requires `manage_options`, is exposed through the Abilities REST API, and is marked for discovery by the WordPress MCP Adapter. The plugin continues to work without the Abilities API.

| Ability | Functionality | Input |
| --- | --- | --- |
| `cloudflare-utils/get-settings` | Effective zone ID, email, token presence, configuration presence and constant-controlled fields. Never returns the token. | None |
| `cloudflare-utils/update-settings` | Partially update connection settings; omitted fields stay intact. | One or more of `zone-id`, `email`, `api-token` |
| `cloudflare-utils/get-behavior` | Describe cache headers and tags, private response exclusions, redirects, automatic purges, comment cookies and credential protection. | None |
| `cloudflare-utils/purge-url` | Clear one URL belonging to this site's hostname. | `url`: absolute HTTP/HTTPS URL without credentials or fragment |
| `cloudflare-utils/purge-all` | Clear all cached content in the configured zone, including other sites sharing the zone. | `confirm`: `true` |

Settings controlled by `CLOUDFLARE_ZONE_ID`, `CLOUDFLARE_EMAIL`, or `CLOUDFLARE_API_TOKEN` cannot be changed by an ability. Empty strings clear editable settings. Zone IDs must contain 32 hexadecimal characters; nonempty email addresses must be valid. Tokens are write-only. Settings reads report local configuration, not verified connectivity.

Purge abilities reuse the same Cloudflare service as the toolbar and automatic hooks. Success requires HTTP 200 and Cloudflare's JSON `success: true`. Failures return `WP_Error`; responses never include credentials or raw provider errors. Use a token with Cache Purge permission for the configured zone. Requests and credentials are no longer written to `cloudflare-api.log`.

Cache headers, tags, comment handling and credential protection run automatically in their original WordPress request context. The behavior ability describes these hooks; it does not emit headers, simulate logins, change comments, or introduce new configuration switches. Cloudflare caching rules and custom cache keys remain Cloudflare configuration.

```php
$ability = wp_get_ability( 'cloudflare-utils/purge-url' );
$result  = $ability->execute( [ 'url' => home_url( '/example/' ) ] );
if ( is_wp_error( $result ) ) {
    // Handle the failure.
}
```

REST discovery and execution use WordPress authentication and the same administrator permission checks; an MCP transport is provided by a separate adapter, not by this plugin. See the [WordPress Abilities API reference](https://developer.wordpress.org/apis/abilities-api/php-reference/) and [Cloudflare purge API](https://developers.cloudflare.com/api/resources/cache/methods/purge/).

## Development checks

```sh
composer install
composer lint
composer check-cs
composer phpstan
WP_CORE_DIR=/path/to/wordpress composer test
```

Tests load the actual WordPress core Abilities API and JSON Schema validator from a WordPress 6.9+ source directory. Only environment services such as options, authorization and Cloudflare HTTP requests are mocked. No WordPress database, site configuration, or live Cloudflare cache is changed. CI checks WordPress 6.9 and the latest release.
