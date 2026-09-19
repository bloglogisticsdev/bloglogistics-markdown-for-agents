=== BlogLogistics Markdown for Agents ===
Contributors: bloglogistics
Tags: markdown, ai, agents, llms, discovery
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 2.6.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Advertises, validates, live-verifies, and safely edits user-curated Markdown companion files and llms.txt, with admin health checks and .htaccess compatibility.

== Description ==

BlogLogistics Markdown for Agents connects WordPress posts and pages to carefully curated static Markdown companion files.

The plugin deliberately does not generate Markdown or derive machine-readable content automatically from WordPress. Users retain full editorial control. Version 2.5.0 adds an optional plain text editor that saves only the existing Markdown or llms.txt file an administrator explicitly chooses to edit.

Typical file locations are:

* `/index.md` for the homepage.
* `/about-us/index.md` for `/about-us/`.
* `/notes/index.md` for `/notes/`.
* `/llms.txt` for the site's curated LLM guidance file.

An administrator explicitly runs a scan after Markdown files are added, removed, or moved. The scan checks the filesystem and stores each detected Markdown URL in the WordPress custom field `bloglogistics_markdown_url`. Version 2.3.0 makes this scan incremental by reusing previous encoding-validation results for unchanged companions while still checking file existence, timestamps, and sizes.

Normal public page loads do not scan directories, check for files, probe URLs, generate Markdown, or make external requests. The plugin uses the already-stored WordPress metadata and outputs discovery markup once for eligible pages.

On Apache-compatible servers, static `/slug/index.md` companions create real directories that can otherwise intercept the corresponding WordPress `/slug/` permalink. The plugin safely maintains a root `.htaccess` permalink compatibility rule so the WordPress page and Markdown companion can coexist. Version 2.3.1 also maintains a Markdown MIME rule so `.md` files are served as UTF-8 `text/markdown`. Before changing `.htaccess`, the plugin independently checks for equivalent rewrite and MIME directives anywhere in the file. Existing manually added directives are respected and never duplicated. When a write is actually required, it creates one timestamped backup beside the live file, reads the file back to confirm both required rules are present, and, when a non-homepage companion is available, performs an end-to-end HTTP check that also confirms the live Markdown Content-Type.

Pages that do not have a Markdown companion are automatically ignored.

Version 2.2.0 added the administrator-only Markdown Health Dashboard. Version 2.3.0 extended it with live endpoint verification, Markdown MIME-type checks, discovery-markup verification, incremental scanning, bulk management, server compatibility diagnostics, and `.htaccess` backup cleanup. Version 2.4.0 reorganized these tools into an easier tabbed interface. Version 2.4.1 separated Markdown file presence, live delivery, and HTML discovery. Version 2.4.2 fixed Posts-page discovery and reduced false severity from server-side cache/header discrepancies. Version 2.4.3 moved normal live endpoint verification into the administrator's web browser. Version 2.4.4 makes verification status conservative: saved results that need rechecking and browser/network request exceptions are shown as Not verified rather than false yellow Review findings, while successful redirects remain informational. Version 2.5.0 adds a deliberately simple text-only editor for existing Markdown companions and llms.txt, with backups, conflict detection, UTF-8 normalization, and atomic replacement. Version 2.6.0 adds WordPress-style pagination to the Pages and Posts lists, with 20, 50, or 100 items per page and list-position preservation after administrator actions. None of these tools run on normal public page loads.

A specific page or post can also be excluded even when its Markdown file exists. The exclusion can be controlled either from BlogLogistics > Markdown for Agents or from the Markdown for Agents panel in the WordPress editor.

== Editorial control ==

The plugin does not automatically generate, curate, or derive content for:

* `llms.txt`;
* `/index.md`;
* any `/path/index.md` companion file.

Those files remain entirely under the site owner's control. Version 2.5.0 provides an optional administrator-only plain text editor for existing files, but it saves only the text the administrator explicitly enters. It does not create missing curated files or convert rendered WordPress HTML into Markdown.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install the release ZIP through WordPress.
2. Activate BlogLogistics Markdown for Agents.
3. Create and upload your curated `llms.txt` and Markdown companion files.
4. Go to BlogLogistics > Markdown for Agents.
5. Click **Scan Changes and Refresh Health**. The incremental scan detects companion files, refreshes the Markdown Health Dashboard, validates changed files and llms.txt references, and reuses unchanged encoding-validation results.
6. Use **Force Full Rescan** when every companion should be re-read regardless of its saved scan signature.
7. Use **Run Live Endpoint Verification** to have your administrator browser check public HTTP status, redirects, Markdown MIME type, and discovery markup.
8. Use the **Pages** and **Posts** tabs to review health separately, filter items that need attention, paginate large lists, choose 20, 50, or 100 items per page, edit an existing Markdown file, view its public Markdown URL, and manage discovery or verification actions.
9. Use the **llms.txt** tab for focused validation, local Markdown-reference health, and the optional plain text llms.txt editor.
10. Use **Server & .htaccess** for server diagnostics, rule repair, live server-rule verification, and backup management.
11. Purge any WordPress/CDN page cache after changes that affect discovery markup or cached public file delivery.

== Frequently Asked Questions ==

= Does this plugin generate Markdown files? =
No. Users create and curate the Markdown content. Version 2.5.0 can edit an existing Markdown file when an administrator explicitly opens the plain text editor, but it does not generate content or create missing companion files.

= Does this plugin generate llms.txt? =
No. llms.txt remains manually curated. Version 2.5.0 can edit an existing llms.txt file through the plain text editor, but it does not generate or create the file automatically.

= What does the Markdown editor change? =
Only the selected existing `.md` file or `llms.txt`. It does not edit the WordPress Page or Post. Before a successful save, the plugin creates a timestamped backup under `wp-content/bloglogistics-markdown-backups`, checks that the file has not changed since the editor was opened, writes UTF-8 without a BOM using LF line endings, and replaces the file atomically when the server permits it.

= What Markdown should I use in the editor? =
Keep it simple: `#`, `##`, and `###` headings, `**bold**`, `*italic*`, bullet lists, ordinary Markdown links, and blank lines between paragraphs. The editor does not prevent other Markdown syntax, but the interface encourages a restrained AI-readable subset.

= Does the plugin check for Markdown files on every page load? =
No. Filesystem checks occur only when an administrator explicitly runs the scan from BlogLogistics > Markdown for Agents.

= What does the Markdown Health Dashboard check? =
The manual scan reports companion presence, potentially stale files based on timestamps, UTF-8 encoding problems, common mojibake patterns, and llms.txt validation results. It does not rewrite any content.

= How is a Markdown companion marked as possibly stale? =
If the WordPress post or page was modified more than 60 seconds after the Markdown file timestamp, the dashboard flags the companion for review. This is a heuristic because deployment tools can alter file timestamps.

= What does llms.txt validation check? =
It checks that llms.txt exists and is readable, validates UTF-8 encoding, reports common encoding corruption, checks for a recommended H1 heading, detects duplicate links, and verifies same-site Markdown links against local files. External links are not requested.

= Does the health scan make external HTTP requests? =
The local Markdown and llms.txt health scan reads local files only. Live Endpoint Verification is a separate administrator action that runs public requests from the administrator's browser and securely stores the observed results. The existing .htaccess coexistence verification is a separate server-side diagnostic and can still make a same-site request when a suitable companion is available.

= What does Live Endpoint Verification check? =
It asks the administrator's browser to check the WordPress page and Markdown companion HTTP status, redirects, Markdown Content-Type response header, and expected rel="alternate" Markdown and llms.txt rel="describedby" discovery links. When an initial browser request fails or returns an uncertain result, version 2.4.3 performs one fresh retry. If that retry succeeds, the successful result is used without a stale-cache warning. Pages with discovery disabled are checked to confirm those plugin discovery links are absent.

= What Markdown MIME type is preferred? =
`text/markdown` is preferred. `text/plain`, `text/x-markdown`, and `application/markdown` are reported as usable warnings rather than hard failures. A missing Content-Type on an otherwise reachable HTTP 200 Markdown file is a Review. An explicitly incorrect MIME type that persists in the browser after one fresh retry remains a Problem.

= What does incremental scanning mean? =
Every scan still checks expected file existence, timestamps, and sizes so additions and removals are detected. If a companion's scan signature is unchanged, the plugin reuses its previous encoding-validation result instead of reading and validating the full file again. Use **Force Full Rescan** to bypass that reuse.

= What bulk actions are available? =
For selected detected companions, administrators can enable discovery, disable discovery, force selected revalidation, or run live verification for the selected items.

= What server diagnostics are shown? =
The plugin reports the detected server family, whether Apache-compatible `.htaccess` automation is supported, `mod_rewrite` visibility when available, and whether the root `.htaccess` can be written. Nginx and IIS are reported as requiring manual server-level configuration.

= How are old .htaccess backups managed? =
The administrator screen lists timestamped backups created by this plugin. The cleanup action deletes only older plugin-created backups and always preserves the newest three.

= What happens on a normal page load? =
For a WordPress post or page with a recorded Markdown companion, the plugin reads the stored post metadata and outputs the discovery markup once. It performs no Markdown filesystem check and makes no external request.

= What happens if a page has no Markdown file? =
The scan does not assign a Markdown URL to that page, so the public page outputs no Markdown discovery markup.

= Can I stop a specific page from advertising its Markdown file? =
Yes. Use the **Do not advertise Markdown or llms.txt from this page** option in the WordPress editor, or use the Discovery controls in the separate Pages and Posts tabs under BlogLogistics > Markdown for Agents.

= Where is the Markdown URL stored? =
The URL is stored in the WordPress custom field `bloglogistics_markdown_url`. The custom field is registered for posts and pages and is intentionally not hidden.

= Why does the plugin modify .htaccess? =
A physical `/slug/index.md` file requires a real `/slug/` directory. On Apache-compatible servers, that directory can intercept the normal WordPress `/slug/` permalink and return `Forbidden`. The compatibility rule sends the directory URL to WordPress while leaving `/slug/index.md` available as a static file.

= Does the plugin back up .htaccess before changing it? =
Yes. Before every write to an existing `.htaccess` file, the plugin creates a timestamped backup beside it, for example `.htaccess.bloglogistics-mfa-backup-20260917-110301`. If the new file cannot be verified after writing, the plugin attempts to restore the original automatically.

= Where is the compatibility rule inserted? =
Before writing anything, the plugin checks whether the equivalent rewrite directives already exist anywhere in `.htaccess`. If they do, the existing rule is respected and no duplicate is added. Only when no equivalent rule exists does the plugin insert its managed block immediately before `# BEGIN WordPress`, or at the top of the site's root `.htaccess` file when that marker is absent.

= How does the plugin confirm the rule is live? =
It reads `.htaccess` and confirms that the required rewrite directives are present, whether they were installed by the plugin or already existed. When a detected non-homepage Markdown companion is available, it then requests both the WordPress page and its `/index.md` companion with a cache-busting query parameter. Both must return HTTP 200 for the live coexistence check to pass.

= What happens on Nginx? =
Nginx does not use `.htaccess`. The plugin does not attempt to create an Apache rule when the server clearly identifies itself as Nginx. The equivalent rewrite must be configured at the Nginx server level.

= Does the plugin still use Accept: text/markdown content negotiation? =
No. Version 2.0.0 removes dynamic Markdown generation and content negotiation so static, curated Markdown files remain the single source of truth.

= Does this plugin manage robots.txt or AI training preferences? =
No. It does not modify robots.txt or define AI training permissions.

= What happens when a Markdown file is removed? =
Run the scan again. The stale `bloglogistics_markdown_url` field will be removed automatically. A page's explicit opt-out preference is preserved.

= How are large Pages and Posts lists handled? =
Version 2.6.0 paginates the Pages and Posts administration tables. Administrators can show 20, 50, or 100 items per page. Health filters paginate independently, bulk actions apply only to selected rows on the current page, and the plugin preserves the current tab, filter, page number, and per-page choice after scans, verification, bulk actions, discovery changes, and Markdown editing.

= What happens when the plugin is deleted? =
The plugin removes its own options and metadata. It does not delete llms.txt, Markdown files, WordPress pages, posts, or content.

== BlogLogistics Service Usage Notice ==

This plugin is licensed under GPL-3.0-or-later.

This plugin is provided by BlogLogistics as part of an active hosting, maintenance, or site-management service, unless a separate service arrangement has been granted. If the website is moved to another provider, continued BlogLogistics service use, support, updates, configuration assistance, or replacement work may require a separate agreement.

This notice does not restrict any rights granted under the GPL-3.0-or-later licence.

== Changelog ==

= 2.6.0 =
* Add WordPress-style pagination to the Pages and Posts administration lists.
* Add administrator-selectable 20, 50, or 100 items per page and remember the choice per administrator.
* Remove the saved per-page administrator preference when the plugin is uninstalled.
* Show filtered result counts and First, Previous, Next, and Last page controls above and below the content table.
* Paginate each health filter independently and reset to page 1 when the filter changes.
* Preserve the current tab, filter, page number, and per-page choice after scans, live verification, row verification, bulk actions, discovery changes, and Markdown editing.
* Limit discovery-choice form management and bulk selection to the rows on the current page so saving one page cannot alter another page.
* Render only the current page of table rows, reducing browser-side table size on sites with many Pages or Posts.
* Add no pagination or list-management work to normal public page loads.

= 2.5.0 =
* Add an administrator-only plain text editor for existing Markdown companion files.
* Replace WordPress Edit/View Page/View Post row actions with Edit Markdown, View Markdown, and Verify.
* Add Edit llms.txt and View llms.txt actions to the llms.txt tab.
* Keep the editor deliberately simple and text-only, with a compact Markdown syntax guide.
* Never generate Markdown from WordPress content and never create missing curated Markdown or llms.txt files.
* Restrict editing to scanned same-site Markdown files and the existing root llms.txt file; arbitrary filesystem paths are never accepted.
* Create a timestamped backup before each successful file replacement.
* Detect on-disk changes after the editor is opened and stop the save instead of overwriting newer content.
* Normalize saved text to UTF-8 without a BOM and LF line endings.
* Write through a same-directory temporary file and atomically replace the original when possible.
* Refresh local health data after a successful save while reusing unchanged validation results for other files.
* Add no editor or filesystem work to normal public page loads.

= 2.4.4 =
* Fix false site-wide yellow Review states after verifier upgrades.
* Show saved verification that needs rechecking as light-grey Not verified rather than Review.
* Treat browser/network request exceptions as inconclusive Not verified results rather than health warnings.
* Record successful redirects without making Live Delivery yellow.
* Keep outdated/unverified results out of health-attention counts.
* Distinguish not-verified results in the live verification completion notice.

= 2.4.3 =
* Move normal Live Endpoint Verification from WordPress origin-server self-requests to the administrator's browser.
* Check public HTML and Markdown URLs as anonymous browser requests and securely save the observed results back to WordPress.
* Retry an initial HTTP, MIME, or discovery failure once with a fresh cache-busting browser request.
* Treat a successful fresh retry as the current Healthy result instead of creating a stale-cache Review warning.
* Recheck non-200 Markdown responses as well as MIME and discovery mismatches before confirming a Problem.
* Classify browser/network exceptions as Review because a request exception does not prove the public endpoint is broken.
* Keep Live Delivery and Discovery separate, with discovery mismatches remaining Review findings when the underlying page is reachable.
* Route all-site, single-item, and bulk live verification through the same browser-based verifier.
* Mark saved pre-2.4.3 live results for one fresh browser verification pass after upgrade.
* Add no browser-verification work to normal public page loads.

= 2.4.2 =
* Fixed discovery output for the configured WordPress Posts page, which is an `is_home()` archive rather than a singular Page request.
* Added cache-aware Markdown MIME rechecking when the canonical response is missing or returns an incorrect Content-Type.
* Downgraded an unconfirmed Content-Type on an otherwise reachable HTTP 200 Markdown file from Problem to Review.
* Discovery-only mismatches are now Review items rather than red file-delivery failures.
* Preserved older saved results while marking them for one fresh verification pass after upgrade.
* Normalized saved 2.4.0/2.4.1 MIME and discovery results to the new severity model.

= 2.4.1 =
* Rename the Pages/Posts **Companion** column to **Markdown File** and use clearer Markdown-file wording throughout the main health UI.
* Rename **Live Status** to **Live Delivery** and keep HTTP/MIME delivery results separate from discovery-markup results.
* Add a dedicated **Discovery** filter alongside Live delivery.
* Verify discovery against the canonical public HTML first, then perform one cache-busting recheck only when the expected discovery state is not found.
* Report stale cached HTML as **Review** when the cache-busting recheck contains the correct Markdown and llms.txt discovery links.
* Report genuinely missing or unexpected discovery markup as a Discovery **Problem** without incorrectly marking the reachable Markdown file itself as broken.
* Show Markdown discovery and llms.txt discovery details separately in the Discovery column.
* Preserve prior live-verification results across local rescans and mark affected results as **Review** until live verification is run again.
* Add separate Overview cards for **Live delivery** and **Discovery checks**.
* Keep all additional checks administrator-driven with no new filesystem or HTTP work on normal public page loads.

= 2.4.0 =
* Reorganize the administrator interface into Overview, Pages, Posts, llms.txt, and Server & .htaccess tabs.
* Separate Pages and Posts instead of mixing both content types in the same health and companion tables.
* Replace the duplicate Content Health and Detected Markdown Companions sections with one consolidated management table per content type.
* Add linked Markdown Health Dashboard cards that lead directly to relevant Pages, Posts, llms.txt, or server views.
* Add consistent status states and colours: Healthy (green), Problem (red), Review (yellow), and Not applicable (light grey).
* Add Pages and Posts filters for Needs attention, Missing, Stale, Encoding, Live issues, and Disabled.
* Add Edit, View Page, View Markdown, and single-item Verify row actions.
* Preserve the active tab and filter after scans, live verification, bulk actions, discovery changes, .htaccess actions, and backup cleanup.
* Move llms.txt validation into a dedicated tab and store same-site Markdown reference details for the new reference-health table.
* Move server diagnostics, .htaccess rule management, and backup management into a dedicated technical tab.
* Keep the existing administrator-driven validation architecture and add no new filesystem or HTTP work to normal public page loads.

= 2.3.1 =
* Add automatic Apache-compatible Markdown MIME configuration using `AddType text/markdown .md` and `AddCharset UTF-8 .md`.
* Detect equivalent existing MIME directives anywhere in `.htaccess` and leave manually added rules untouched.
* Never duplicate the Markdown MIME directives when they are already present.
* Add the MIME block beside the existing BlogLogistics `.htaccess` rules only when it is actually missing.
* Create a single timestamped `.htaccess` backup only when a write is required.
* Verify both the permalink compatibility rule and the Markdown MIME rule after any write.
* Extend the live `.htaccess` check to require HTTP 200 for the WordPress page and Markdown companion and the preferred `text/markdown` Content-Type.
* Add `mod_mime` visibility to server diagnostics and display both `.htaccess` rule states in the admin screen.

= 2.3.0 =
* Add live endpoint verification for detected WordPress pages and Markdown companions.
* Check live HTTP status codes and record whether page or Markdown endpoints redirect.
* Check Markdown Content-Type headers, preferring `text/markdown` while reporting common legacy/plain-text types as warnings.
* Verify live `rel="alternate"` Markdown discovery markup and `rel="describedby"` llms.txt discovery markup.
* Verify that plugin discovery markup remains absent when a page's discovery setting is disabled.
* Make local health scanning incremental by reusing encoding-validation results for unchanged companion files.
* Add a **Force Full Rescan** action for administrators who want every companion re-read.
* Add bulk actions to enable discovery, disable discovery, force selected revalidation, and live-verify selected companions.
* Add server compatibility diagnostics for Apache, LiteSpeed, Nginx, IIS, `.htaccess` writability, and `mod_rewrite` visibility when available.
* Add `.htaccess` backup management with safe cleanup that always preserves the newest three plugin-created backups.
* Keep all new live verification and diagnostic work out of normal public page requests.

= 2.2.0 =
* Add an administrator-only Markdown Health Dashboard.
* Flag Markdown companions as possibly stale when the WordPress post/page modification time is newer than the Markdown file timestamp by more than 60 seconds.
* Validate Markdown companion files as UTF-8 and detect replacement characters, common mojibake patterns, and UTF-8 BOM warnings.
* Validate the user-managed llms.txt file without modifying it.
* Check same-site Markdown links listed in llms.txt against local files without making external HTTP requests.
* Report broken local Markdown references, duplicate llms.txt links, and a missing recommended H1 heading.
* Store health results in a non-autoloaded WordPress option so public page requests perform no new filesystem or validation work.

= 2.1.1 =
* Detect an equivalent Markdown companion rewrite rule anywhere in `.htaccess`, regardless of the surrounding comments or whether it was added manually.
* Do not add, move, or duplicate the rule when the required directives already exist.
* Do not create an unnecessary `.htaccess` backup when no file change is required.
* Continue to run the live WordPress-page/Markdown-companion verification against an existing compatible rule.

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
