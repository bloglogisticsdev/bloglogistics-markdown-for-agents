=== BlogLogistics Markdown for Agents ===
Contributors: bloglogistics
Tags: markdown, ai, agents, content negotiation
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 1.2.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Adds Markdown content negotiation for AI agents and serves a machine-readable homepage at /index.md.

== Description ==

BlogLogistics Markdown for Agents adds Markdown content negotiation for AI agents and serves a machine-readable homepage at /index.md using the current WordPress site's URLs and metadata.

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/.
2. Activate the plugin in WordPress.
3. Go to BlogLogistics > Markdown for Agents to review or change the recommended settings.
4. Visit /index.md on the site to confirm the Markdown endpoint works.
5. Request the homepage with an Accept: text/markdown header to confirm Markdown content negotiation works.


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
No. This plugin does not edit robots.txt. When the Markdown output includes website-use preferences, it reads the current Content-Signal value from the physical robots.txt file when available. This keeps it aligned with BlogLogistics Content Signals for Robots.txt instead of creating a second conflicting setting.

= Where are the settings? =
The settings are available under BlogLogistics > Markdown for Agents.

= What are the recommended defaults? =
The recommended defaults keep the Markdown homepage, Markdown content negotiation, discovery headers, homepage content, important pages, machine-readable access links, and robots.txt website-use preferences enabled.

= What happens when the plugin is deleted? =
The plugin removes its saved settings and version option. It does not delete pages, posts, or content.

== Changelog ==

= 1.2.0 =
* Add BlogLogistics > Markdown for Agents settings page.
* Add configurable options for the Markdown homepage, content negotiation, discovery headers, homepage content, important pages, access links, and website-use preferences.
* Read the Content-Signal value from robots.txt when available so the Markdown output stays aligned with BlogLogistics Content Signals for Robots.txt.
* Add uninstall cleanup for this plugin’s saved settings.

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