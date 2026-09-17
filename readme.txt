=== BlogLogistics Markdown for Agents ===
Contributors: bloglogistics
Tags: markdown, ai, agents, llms, discovery
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 2.1.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Advertises user-curated Markdown companion files and llms.txt for AI agents, with safe .htaccess compatibility for Markdown companion directories.

== Description ==

BlogLogistics Markdown for Agents connects WordPress posts and pages to carefully curated static Markdown companion files.

The plugin deliberately does not generate Markdown and does not create or rewrite llms.txt. Users retain full editorial control over all machine-readable content.

Typical file locations are:

* `/index.md` for the homepage.
* `/about-us/index.md` for `/about-us/`.
* `/notes/index.md` for `/notes/`.
* `/llms.txt` for the site's curated LLM guidance file.

An administrator explicitly runs a scan after Markdown files are added, removed, or moved. The scan checks the filesystem and stores each detected Markdown URL in the WordPress custom field `bloglogistics_markdown_url`.

Normal public page loads do not scan directories, check for files, probe URLs, generate Markdown, or make external requests. The plugin uses the already-stored WordPress metadata and outputs discovery markup once for eligible pages.

On Apache-compatible servers, static `/slug/index.md` companions create real directories that can otherwise intercept the corresponding WordPress `/slug/` permalink. Version 2.1.0 safely maintains a root `.htaccess` compatibility rule so the WordPress page and Markdown companion can coexist. Before every `.htaccess` write, the plugin creates a timestamped backup beside the live file. It then reads the file back to confirm the rule was saved and, when a non-homepage companion is available, performs an end-to-end HTTP check of both the WordPress page and its Markdown companion.

Pages that do not have a Markdown companion are automatically ignored.

A specific page or post can also be excluded even when its Markdown file exists. The exclusion can be controlled either from BlogLogistics > Markdown for Agents or from the Markdown for Agents panel in the WordPress editor.

== Editorial control ==

The plugin does not create, curate, modify, or replace:

* `llms.txt`;
* `/index.md`;
* any `/path/index.md` companion file.

Those files remain entirely under the site owner's control. This is intentional because machine-readable representations should be reviewed and curated rather than automatically generated from rendered WordPress HTML.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install the release ZIP through WordPress.
2. Activate BlogLogistics Markdown for Agents.
3. Create and upload your curated `llms.txt` and Markdown companion files.
4. Go to BlogLogistics > Markdown for Agents.
5. Click **Scan for Markdown Files**. The scan also verifies the `.htaccess` compatibility rule and runs a live coexistence check when a suitable Markdown companion is available.
6. Review the detected Markdown companions and optionally disable discovery for specific posts or pages.
7. If needed, use **Install / Repair and Verify .htaccess Rule** on the same screen.
8. Purge any WordPress/CDN page cache after scanning or changing per-page discovery settings.

== Frequently Asked Questions ==

= Does this plugin generate Markdown files? =
No. Users create and maintain all Markdown content themselves. The plugin only discovers and advertises existing files.

= Does this plugin generate llms.txt? =
No. llms.txt remains a manually curated file under the site owner's full control.

= Does the plugin check for Markdown files on every page load? =
No. Filesystem checks occur only when an administrator explicitly runs the scan from BlogLogistics > Markdown for Agents.

= What happens on a normal page load? =
For a WordPress post or page with a recorded Markdown companion, the plugin reads the stored post metadata and outputs the discovery markup once. It performs no Markdown filesystem check and makes no external request.

= What happens if a page has no Markdown file? =
The scan does not assign a Markdown URL to that page, so the public page outputs no Markdown discovery markup.

= Can I stop a specific page from advertising its Markdown file? =
Yes. Use the **Do not advertise Markdown or llms.txt from this page** option in the WordPress editor or on the BlogLogistics > Markdown for Agents admin screen.

= Where is the Markdown URL stored? =
The URL is stored in the WordPress custom field `bloglogistics_markdown_url`. The custom field is registered for posts and pages and is intentionally not hidden.

= Why does the plugin modify .htaccess? =
A physical `/slug/index.md` file requires a real `/slug/` directory. On Apache-compatible servers, that directory can intercept the normal WordPress `/slug/` permalink and return `Forbidden`. The compatibility rule sends the directory URL to WordPress while leaving `/slug/index.md` available as a static file.

= Does the plugin back up .htaccess before changing it? =
Yes. Before every write to an existing `.htaccess` file, the plugin creates a timestamped backup beside it, for example `.htaccess.bloglogistics-mfa-backup-20260917-110301`. If the new file cannot be verified after writing, the plugin attempts to restore the original automatically.

= Where is the compatibility rule inserted? =
If the standard `# BEGIN WordPress` marker exists, the plugin inserts the rule immediately before that WordPress-managed block. If the marker does not exist, the rule is placed at the top of the site's root `.htaccess` file.

= How does the plugin confirm the rule is live? =
It first reads the saved `.htaccess` file back and confirms that the managed block is present in the correct position. When a detected non-homepage Markdown companion is available, it then requests both the WordPress page and its `/index.md` companion with a cache-busting query parameter. Both must return HTTP 200 for the live coexistence check to pass.

= What happens on Nginx? =
Nginx does not use `.htaccess`. The plugin does not attempt to create an Apache rule when the server clearly identifies itself as Nginx. The equivalent rewrite must be configured at the Nginx server level.

= Does the plugin still use Accept: text/markdown content negotiation? =
No. Version 2.0.0 removes dynamic Markdown generation and content negotiation so static, curated Markdown files remain the single source of truth.

= Does this plugin manage robots.txt or AI training preferences? =
No. It does not modify robots.txt or define AI training permissions.

= What happens when a Markdown file is removed? =
Run the scan again. The stale `bloglogistics_markdown_url` field will be removed automatically. A page's explicit opt-out preference is preserved.

= What happens when the plugin is deleted? =
The plugin removes its own options and metadata. It does not delete llms.txt, Markdown files, WordPress pages, posts, or content.

== BlogLogistics Service Usage Notice ==

This plugin is licensed under GPL-3.0-or-later.

This plugin is provided by BlogLogistics as part of an active hosting, maintenance, or site-management service, unless a separate service arrangement has been granted. If the website is moved to another provider, continued BlogLogistics service use, support, updates, configuration assistance, or replacement work may require a separate agreement.

This notice does not restrict any rights granted under the GPL-3.0-or-later licence.

== Changelog ==

= 2.1.0 =
* Add automatic `.htaccess` compatibility for physical `/slug/index.md` companion directories on Apache-compatible servers.
* Insert the compatibility block immediately before `# BEGIN WordPress` when that marker exists, or at the top of the root `.htaccess` file otherwise.
* Back up the existing `.htaccess` file with a date-and-time suffix before every write.
* Read the saved `.htaccess` file back and verify that the rule is present in the correct position.
* Perform a live coexistence check against one detected WordPress page and its Markdown companion when possible.
* Add an admin status panel showing file verification, live HTTP status codes, the most recent backup, and the last compatibility check time.
* Add an **Install / Repair and Verify .htaccess Rule** administrator action.
* Re-check `.htaccess` compatibility after each manual Markdown scan.
* Leave `.htaccess` compatibility rules and backup files in place on uninstall so existing Markdown directories do not suddenly break WordPress permalinks.

= 2.0.0 =
* Replace dynamic Markdown generation with discovery of user-curated static Markdown files.
* Remove the dynamically generated `/index.md` endpoint.
* Remove `Accept: text/markdown` content negotiation.
* Remove automatic homepage-to-Markdown conversion and automatic Important Pages generation.
* Keep llms.txt and all Markdown companion files fully user-managed.
* Add an explicit administrator-run Markdown file scan.
* Store detected Markdown URLs in the visible `bloglogistics_markdown_url` post custom field.
* Add the `bloglogistics_markdown_disabled` per-post/page custom field.
* Add a per-page opt-out control to the WordPress post/page editor.
* Add a central per-page opt-out table under BlogLogistics > Markdown for Agents.
* Ignore pages that do not have matching Markdown companion files.
* Perform no Markdown filesystem checks, URL probes, external requests, or Markdown generation on public page loads.
* Advertise llms.txt only when it was detected during the most recent administrator scan.
* Preserve per-page opt-out choices when Markdown files are removed and later restored.

= 1.3.2 =
* Generate the update manifest Installation section from readme.txt.
* Generate the update manifest FAQ section from readme.txt.
* Remove stale hard-coded Installation and FAQ manifest content.

= 1.3.0 =
* Refactor the main plugin file into a bootstrap loader.
* Move the main plugin class into the includes directory.
* Add translation support and bundled language files.
* Add language files for English Australia, English Great Britain, French, German, Spanish, Norwegian Bokmål, Swedish, and Japanese.
* Add Domain Path metadata for bundled language files.
* Preserve update metadata, including icons, banners, Installation, FAQ, Author, and changelog support.

= 1.2.0 =
* Add BlogLogistics > Markdown for Agents settings page.
* Add configurable options for the Markdown homepage, content negotiation, discovery headers, homepage content, important pages, access links, and website-use preferences.
* Read the Content-Signal value from robots.txt when available so the Markdown output stays aligned with BlogLogistics Content Signals for Robots.txt.
* Add uninstall cleanup for this plugin's saved settings.

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
