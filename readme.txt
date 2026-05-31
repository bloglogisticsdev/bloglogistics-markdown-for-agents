=== BlogLogistics Markdown for Agents ===
Contributors: bloglogistics
Tags: markdown, ai, agents, content negotiation
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 1.1.10
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Adds Markdown content negotiation for AI agents and serves a machine-readable homepage at /index.md.

== Description ==

BlogLogistics Markdown for Agents adds Markdown content negotiation for AI agents and serves a machine-readable homepage at /index.md using the current WordPress site's URLs and metadata.

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/.
2. Activate the plugin in WordPress.
3. Visit /index.md on the site to confirm the Markdown endpoint works.
4. Request the homepage with an Accept: text/markdown header to confirm Markdown content negotiation works.


== Frequently Asked Questions ==

= What does this plugin do? =
It adds a Markdown-friendly version of the site's homepage at /index.md and can return Markdown when the homepage is requested by software that prefers text/markdown.

= Does this replace the normal website homepage? =
No. Normal visitors continue to see the regular HTML homepage. The Markdown output is available for agents, tools, and other clients that request it.

= Does this create or edit WordPress pages? =
No. The plugin serves Markdown output dynamically using the current site's public URLs and metadata.

= Where is the Markdown version of the homepage? =
The Markdown version is available at /index.md.

= Does this plugin manage robots.txt or AI training preferences? =
No. This plugin only provides Markdown content negotiation and a machine-readable homepage. Robots.txt content preferences are handled by a separate BlogLogistics plugin.

== Changelog ==

= 1.1.10 =
* Add Installation and FAQ tabs plus linked BlogLogistics author metadata to the plugin details modal.

= 1.1.9 =
* Add BlogLogistics plugin banner assets and update manifest banner metadata.

= 1.1.8 =
* Add BlogLogistics plugin icon assets and update manifest icon metadata.

= 1.1.7 =
* Generate the update manifest changelog from readme.txt so WordPress displays the full changelog.

= 1.1.6 =
* Automate update manifest generation and upload from GitHub Actions.

= 1.1.5 =
* Fix manifest updater initialization so WordPress shows update controls after installation.
* Add missing update manifest URL constant.

= 1.1.2 =
* Fix GitHub updater class name to prevent conflicts with other BlogLogistics plugins.

= 1.1.1 =
* Test GitHub release update detection.

= 1.1.0 =
* Initial GitHub-updatable test release.