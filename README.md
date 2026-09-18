# BlogLogistics Markdown for Agents

BlogLogistics Markdown for Agents advertises, validates, and live-verifies user-curated Markdown companion files and `llms.txt` for AI agents without generating Markdown or checking the filesystem on public page loads.

## Philosophy

The plugin deliberately keeps editorial control with the site owner. It does not create, rewrite, or curate `llms.txt`, `/index.md`, or any `/path/index.md` file.

Users create those files themselves. The plugin discovers them during an administrator-run scan, records the relationship in WordPress post metadata, validates local file health, and advertises eligible files on the corresponding HTML page.

## Workflow

1. Create a curated `/llms.txt`.
2. Create any Markdown companion files you want, for example `/about-us/index.md`.
3. In WordPress, open **BlogLogistics > Markdown for Agents**.
4. Click **Scan Changes and Refresh Health**. The incremental scan checks file existence, timestamps, and sizes, while reusing previous encoding validation for unchanged files.
5. Use **Force Full Rescan** when every companion should be re-read regardless of its saved scan signature.
6. Use **Run Live Endpoint Verification** to check public HTTP status, redirects, Markdown MIME type, and discovery markup.
7. Use the **Pages** and **Posts** tabs to review content health separately, apply filters, manage discovery, and verify selected content.
8. Use the **llms.txt** tab for focused validation and local Markdown-reference health.
9. Use **Server & .htaccess** for server compatibility, Markdown MIME delivery, rule repair, and backup management.
10. Purge page/CDN caches after changes that affect discovery markup or public file delivery.

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
- live endpoint results when live verification has been run;
- Markdown MIME type findings;
- live discovery-link verification.

The stale-file indicator is deliberately labelled **possibly stale**. It compares timestamps rather than semantic content, and deployment tools can change filesystem timestamps.

## Administrator interface

Version 2.4.0 reorganizes the plugin into five WordPress-style tabs:

- **Overview** provides linked Markdown Health Dashboard cards and the main scan/live-verification actions.
- **Pages** shows only WordPress pages in one consolidated health and discovery table.
- **Posts** shows only WordPress posts in the same focused interface.
- **llms.txt** provides dedicated validation, warnings, and stored same-site Markdown-reference results.
- **Server & .htaccess** contains technical server diagnostics, rule management, live server-rule checks, and backup cleanup.

Pages and Posts can be filtered by **Needs attention**, **Missing**, **Stale**, **Encoding**, **Live issues**, or **Disabled**. Row actions provide direct Edit, View Page/View Post, View Markdown, and Verify shortcuts when available. The plugin preserves the active tab and filter after administrator actions.

Status presentation is consistent across the interface: **Healthy** is green, **Problem** is red, **Review** is yellow, and **Not applicable** is light grey. Text labels accompany colour so status is not communicated by colour alone.

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

Live verification is separate from the local scan because it makes public same-site HTTP requests. It checks up to 75 detected companions per run and records:

- WordPress page HTTP status;
- Markdown companion HTTP status;
- whether either endpoint redirected;
- the Markdown `Content-Type` response header;
- whether `text/markdown` is returned;
- whether the expected `rel="alternate"` Markdown link is present on eligible pages;
- whether the expected `rel="describedby"` link to `llms.txt` is present when appropriate;
- whether discovery markup remains absent on pages where discovery is disabled.

`text/markdown` is treated as the preferred Markdown MIME type. `text/plain`, `text/x-markdown`, and `application/markdown` are reported as usable warnings rather than hard failures.

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
