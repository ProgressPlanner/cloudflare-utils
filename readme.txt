=== Cloudflare utils by Progress Planner ===
Contributors: joostdevalk, aristath, filipi, progressplanner
Tags: cloudflare, cache, purge, performance, abilities
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Purge the Cloudflare cache from WordPress and send the cache headers Cloudflare needs to cache your pages.

== Description ==

Cloudflare utils by Progress Planner makes a WordPress site work well behind Cloudflare's full-page cache.

= What the plugin does =

* Sends cache headers and cache tags, so Cloudflare can cache public pages and skips private ones.
* Purges a URL from the Cloudflare cache when its post is updated or a comment on it is added or approved.
* Adds a toolbar menu to clear the full cache, or the cache for the page you are viewing.
* Keeps comment forms safe to cache by filling in commenter details in the browser.
* Asks users to reset their password when Cloudflare reports that their login credentials were exposed.
* Registers WordPress abilities for reading and updating settings and for purging the cache, when the Abilities API is available (WordPress 6.9 and later).

= WordPress abilities =

Every ability requires the `manage_options` capability.

* `cloudflare-utils/get-settings`: read the zone ID, the email, and whether a token is set. The token itself is never returned.
* `cloudflare-utils/update-settings`: update the zone ID, email, or API token.
* `cloudflare-utils/get-behavior`: describe what the plugin does automatically.
* `cloudflare-utils/purge-url`: clear one URL on this site from the cache.
* `cloudflare-utils/purge-all`: clear all cached content in the zone.

== Installation ==

1. Download `cloudflare-utils.zip` from the [latest GitHub release](https://github.com/ProgressPlanner/cloudflare-utils/releases/latest) and upload it through **Plugins > Add New > Upload Plugin**, or extract it to `/wp-content/plugins/cloudflare-utils`.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Settings > Cloudflare** and enter your zone ID, email, and an API token with Cache Purge permission for that zone. You can also define `CLOUDFLARE_ZONE_ID`, `CLOUDFLARE_EMAIL`, and `CLOUDFLARE_API_TOKEN` as constants.
4. In Cloudflare, set a cache rule to "Use cache-control header if present, bypass cache if not" and set the edge TTL to respect the origin.

== Frequently Asked Questions ==

= Do I need a Cloudflare account to use this plugin? =

Yes. You need a Cloudflare zone for your site and an API token with Cache Purge permission for that zone.

= Does the plugin work without the Abilities API? =

Yes. The abilities are only registered when the Abilities API is available. Everything else works without it.

== Changelog ==

= 1.1.0 =

* Added WordPress abilities for reading and updating settings and for purging the cache.
* Added clearing the cache for the front page and blog home from the toolbar.
* Changed purge failures to log Cloudflare's error details to the error log.
* Removed logging of Cloudflare API requests and credentials to `cloudflare-api.log`.

= 1.0.0 =

* Initial release of Cloudflare Utils by Progress Planner.
* Introduced functions for purging cache.
