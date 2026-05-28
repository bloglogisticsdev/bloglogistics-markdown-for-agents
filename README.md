=== BlogLogistics Markdown for Agents ===
Contributors: bloglogistics
Tags: markdown, ai, agents, content negotiation
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 1.1.4
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Adds Markdown content negotiation for AI agents and serves a machine-readable homepage at /index.md.

== Description ==

BlogLogistics Markdown for Agents adds Markdown content negotiation for AI agents and serves a machine-readable homepage at /index.md using the current WordPress site's URLs and metadata.

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/.
2. Activate the plugin in WordPress.
3. Visit /index.md on the site to confirm the Markdown endpoint works.

== Changelog ==

= 1.1.4 =
* Switch update checks to the BlogLogistics update manifest endpoint.
* Avoid GitHub API update checks to reduce rate-limit errors.

= 1.1.3 =
* Fix Plugin Update Checker loading to prevent conflicts when multiple BlogLogistics plugins are active.

= 1.1.2 =
* Fix GitHub updater class name to prevent conflicts with other BlogLogistics plugins.

= 1.1.1 =
* Test GitHub release update detection.

= 1.1.0 =
* Initial GitHub-updatable test release.