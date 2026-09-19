# BlogLogistics Markdown for Agents

BlogLogistics Markdown for Agents advertises, validates, live-verifies, and safely edits user-curated Markdown companion files and `llms.txt` for AI agents without generating Markdown or checking the filesystem on public page loads.

## Philosophy

The plugin deliberately keeps editorial control with the site owner. It never generates Markdown or derives machine-readable content automatically from WordPress.

Users create and curate those files themselves. Version 2.5.0 adds an optional administrator-only plain text editor for existing `llms.txt`, `/index.md`, and `/path/index.md` files. The editor saves only the text the administrator explicitly enters; it does not create missing curated files or convert WordPress content into Markdown. The plugin discovers existing files during an administrator-run scan, records the relationship in WordPress post metadata, validates local file health, and advertises eligible files on the corresponding HTML page.

## Workflow

1. Create a curated `/llms.txt`.
2. Create any Markdown companion files you want, for example `/about-us/index.md`.
3. In WordPress, open **BlogLogistics > Markdown for Agents**.
4. Click **Scan Changes and Refresh Health**. The incremental scan checks file existence, timestamps, and sizes, while reusing previous encoding validation for unchanged files.
5. Use **Force Full Rescan** when every companion should be re-read regardless of its saved scan signature.
6. Use **Run Live Endpoint Verification** to have your administrator browser check public HTML/Markdown delivery, Markdown MIME type, and discovery markup. When an initial result fails or is uncertain, one fresh browser retry is used, and a successful retry becomes the current result without a stale-cache warning.
7. Use the **Pages** and **Posts** tabs to review content health separately, apply filters, paginate larger lists with 20/50/100 items per page, edit an existing Markdown file, view the public Markdown file, manage discovery, and verify selected content.
8. Use the **llms.txt** tab for focused validation, local Markdown-reference health, and the optional llms.txt editor.
9. Use **Server & .htaccess** for server compatibility, Markdown MIME delivery, rule repair, and backup management.
10. Purge page/CDN caches after changes that affect discovery markup or cached public file delivery.

## Markdown Health Dashboard

The administrator-only health snapshot reports:

- published posts and pages checked;
- detected and missing Markdown companions;
- companions that may be stale because the WordPress content was modified after the Markdown file timestamp;
- invalid UTF-8, Unicode replacement characters, common mojibake patterns, and UTF-8 BOM warnings;
- `llms.txt` presence and encoding;
- broken same-site Markdown links referenced by `llms.txt`;
- duplicate `llms.txt` links and a missing recommended H1 heading;
- per-page discovery exclusions;
- live delivery results when live verification has been run;
- Markdown MIME type findings;
- discovery-link verification kept separate from file reachability;
- browser-verified public delivery and discovery results, with one fresh retry when an initial result is uncertain;
- saved live results that may need re-verification after a later scan detects relevant changes.

The stale-file indicator is deliberately labelled **possibly stale**. It compares timestamps rather than semantic content, and deployment tools can change filesystem timestamps.

## Administrator interface

Version 2.4.0 reorganized the plugin into five WordPress-style tabs. Version 2.4.1 refined the Pages and Posts health tables so file presence, public delivery, and HTML discovery are easier to understand. Version 2.4.2 improved Posts-page discovery and severity handling. Version 2.4.3 moved normal live endpoint verification into the administrator's browser. Version 2.4.4 prevents unverified or inconclusive checks from being presented as site-health warnings. Version 2.5.0 adds a plain text editor for existing curated files. Version 2.6.0 adds WordPress-style pagination and per-page controls for larger Pages and Posts libraries:

- **Overview** provides linked Markdown Health Dashboard cards and the main scan/live-verification actions.
- **Pages** shows only WordPress pages in one consolidated health and discovery table.
- **Posts** shows only WordPress posts in the same focused interface.
- **llms.txt** provides dedicated validation, warnings, and stored same-site Markdown-reference results.
- **Server & .htaccess** contains technical server diagnostics, rule management, live server-rule checks, and backup cleanup.

Pages and Posts can be filtered by **Needs attention**, **Missing**, **Stale**, **Encoding**, **Live delivery**, **Discovery**, or **Disabled**. Version 2.6.0 paginates each filtered list with WordPress-style First, Previous, Next, and Last controls, plus a 20/50/100 items-per-page selector remembered for each administrator. Row actions are focused on the Markdown layer: **Edit Markdown**, **View Markdown**, and **Verify**. WordPress Page/Post editing is intentionally left to the normal WordPress content screens. The plugin preserves the active tab, filter, page number, and per-page choice after administrator actions.

Status presentation is consistent across the interface: **Healthy** is green, **Problem** is red, **Review** is yellow, and **Not applicable** is light grey. Text labels accompany colour so status is not communicated by colour alone.

## Pages and Posts pagination

Version 2.6.0 paginates the Pages and Posts administration tables instead of rendering every matching row at once. Each health filter has its own result count and page set. Administrators can choose 20, 50, or 100 items per page, and the choice is remembered per administrator.

The pagination controls follow familiar WordPress list-table behaviour with First, Previous, Next, and Last navigation. Changing a health filter resets that list to page 1. Scans, live verification, row verification, bulk actions, discovery changes, and Markdown editing return the administrator to the same tab, filter, page number, and per-page setting where practical.

Bulk actions and **Save Discovery Choices** operate only on the rows shown on the current page. This prevents a save on one page of results from changing discovery settings on rows that are not currently displayed.

Only the current page of table rows is rendered into the browser, making large Posts and Pages libraries easier to navigate and reducing the size of the administration page.

## Plain text Markdown editor

Version 2.5.0 adds a deliberately simple administrator-only editor for existing curated files. It is not Gutenberg, TinyMCE, or a Markdown generator. It is a monospaced text area for directly editing the actual `.md` file or root `llms.txt`.

The editor encourages a restrained, AI-readable subset such as:

```markdown
# Main heading
## Section heading
### Subsection heading

**Bold text**
*Italic text*

- Bullet item
- Another item

[Link text](https://example.com/)
```

Safety behaviour includes:

- editing only a scanned same-site Markdown file or the existing root `llms.txt`;
- never accepting an arbitrary filesystem path from the request;
- administrator capability and nonce checks;
- a timestamped backup before each successful replacement;
- conflict detection when the file changed after the editor was opened;
- UTF-8 validation, BOM removal, and LF line-ending normalization;
- a same-directory temporary file followed by atomic replacement when supported;
- an incremental local health refresh after saving.

The backup copies are stored under `wp-content/bloglogistics-markdown-backups`. The plugin creates an `index.php` and Apache deny rule in that backup directory as a defence-in-depth measure.

## Incremental scanning

Version 2.3.0 adds incremental validation. Every administrator scan still checks whether expected companion files exist and records their timestamp and size so additions and removals are detected. If a companion's relevant scan signature has not changed, the plugin reuses the previous encoding-validation result instead of reading and validating the full file again.

A **Force Full Rescan** option is available for situations where timestamps cannot be trusted or the administrator wants every companion re-read.

## Apache Markdown MIME handling

Version 2.3.1 adds safe `.htaccess` management for Markdown delivery as well as permalink compatibility. On Apache-compatible servers the plugin checks for these MIME directives:

```apache
<IfModule mod_mime.c>
    AddType text/markdown .md
    AddCharset UTF-8 .md
</IfModule>
```

Equivalent existing directives are detected regardless of surrounding comments or placement, including manually added rules. If the required `AddType` and `AddCharset` directives for `.md` are already present, the plugin does not add another copy, does not move them, and does not create an unnecessary backup.

When either the permalink compatibility rule or the Markdown MIME rule is missing, the plugin creates one timestamped `.htaccess` backup, adds only the missing rule or rules before `# BEGIN WordPress` when available, reads the file back to verify both rules, and can confirm the result with a live Markdown request.

## Live endpoint verification

Live verification is separate from the local scan. Version 2.4.3 runs the normal endpoint checks from the administrator's web browser rather than asking the WordPress origin server to request its own public URLs. This better reflects the request path used by a real browser when Cloudflare, a CDN, page caching, or other edge infrastructure sits in front of WordPress. Version 2.4.4 treats outdated saved results and browser/network request exceptions as Not verified instead of Review, and a successful redirect is recorded without changing a healthy result to yellow.

The verifier checks up to 75 detected Markdown files per run and records:

- WordPress page HTTP status;
- Markdown companion HTTP status;
- whether either canonical endpoint redirected;
- the Markdown `Content-Type` response header;
- whether `text/markdown` is returned;
- whether the expected `rel="alternate"` Markdown link is present on eligible pages;
- whether the expected `rel="describedby"` link to `llms.txt` is present when appropriate;
- whether discovery markup remains absent on pages where discovery is disabled.

The browser first checks the normal public URL anonymously. If the initial HTML or Markdown request fails, if Markdown does not return the preferred MIME type, or if discovery markup does not match the expected state, the browser performs one fresh retry with a unique query string and no-cache request options. **A successful fresh retry wins.** The row is stored as Healthy when the fresh result verifies correctly; the plugin does not create a Review warning merely because the first request saw stale cache state.

Confirmed non-200 responses and persistent incorrect MIME types remain Problems. A browser/network exception is a Review because an exception does not prove the public endpoint itself is unavailable. `text/plain`, `text/x-markdown`, and `application/markdown` remain usable-but-non-preferred MIME warnings. A missing Content-Type on an otherwise successful HTTP 200 Markdown response is also a Review.

**Live Delivery** and **Discovery** remain separate. Discovery mismatches on an otherwise reachable HTML page are Review findings rather than file-delivery failures. The configured WordPress Posts page is supported even though WordPress exposes it as an `is_home()` archive rather than a singular Page request.

All-site verification, single-row **Verify**, and the bulk **Live verify selected** action use the same browser-based verifier. Results are sent back to WordPress through a nonce-protected administrator AJAX action and stored in the health snapshot. None of this verification runs during normal public page loads.

A later local scan does not discard saved live results. When a relevant page/Markdown scan signature or the presence of `llms.txt` changes, the previous live result is retained and marked **Review** until live verification is run again.

## Bulk management

The separate Pages and Posts tables include bulk actions for selected content:

- enable discovery;
- disable discovery;
- force revalidation of selected companions;
- live-verify selected companions.

The individual discovery setting remains available in the WordPress editor, while the Pages and Posts tabs provide per-row controls and bulk actions.

## Server compatibility diagnostics

The plugin reports the detected web-server family, `.htaccess` support, `mod_rewrite` visibility when available, and whether the site's root `.htaccess` can be written.

Apache and LiteSpeed can use the plugin-managed compatibility rule. Nginx and IIS do not use Apache `.htaccess`, so the plugin reports that equivalent server-level configuration is required and does not try to install an Apache rule.

## WordPress permalink compatibility

A physical `/about-us/index.md` companion creates a real `/about-us/` directory. On Apache-compatible servers, that directory can prevent WordPress from receiving the normal `/about-us/` request and can produce a plain `Forbidden` response.

The plugin maintains this rule in the site's root `.htaccess` file:

```apache
# ======================================================================
# Allow WordPress pages to coexist with Markdown companion directories
# Added by the plugin: BlogLogistics Markdown for Agents
# ======================================================================
<IfModule mod_rewrite.c>
    RewriteEngine On

    RewriteCond %{REQUEST_FILENAME} -d
    RewriteCond %{REQUEST_FILENAME}/index.md -f
    RewriteRule ^.+/?$ index.php [L]
</IfModule>
# ======================================================================
```

Before writing anything, the plugin checks for the equivalent three rewrite directives anywhere in the root `.htaccess` file. If they are already present, including a manually added copy with different comments, the plugin leaves `.htaccess` unchanged and does not create another backup. Only when the directives are absent does it insert the managed block immediately before `# BEGIN WordPress`, or at the top of `.htaccess` when that marker is not present.

When a write is required, the existing file is backed up with a timestamped filename. The plugin reads the saved file back to verify the rule and, when possible, performs an end-to-end check of one WordPress page and its Markdown companion.

## .htaccess backup management

Plugin-created backups are listed in the administrator screen. A cleanup action removes older plugin-created backups while always preserving the newest three. The cleanup routine only targets files that match the plugin's timestamped backup naming convention.

The plugin does not remove the compatibility rule or its backups on uninstall because doing so while physical Markdown directories remain could immediately break the corresponding WordPress permalinks.

## Performance

Normal public requests do not scan directories, call `file_exists()`, validate Markdown, probe Markdown URLs, make live-verification requests, or generate Markdown.

The scan stores a matching companion URL in the `bloglogistics_markdown_url` custom field. The front end uses the stored WordPress metadata and outputs discovery markup once when appropriate.

## Licence

GPL-3.0-or-later.
