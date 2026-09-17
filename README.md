# BlogLogistics Markdown for Agents

BlogLogistics Markdown for Agents advertises and validates user-curated Markdown companion files and `llms.txt` for AI agents without generating Markdown or checking the filesystem on public page loads.

## Philosophy

The plugin deliberately keeps editorial control with the site owner. It does not create, rewrite, or curate `llms.txt`, `/index.md`, or any `/path/index.md` file.

Users create those files themselves. The plugin only discovers them during an explicit administrator-run scan, records the relationship in WordPress post metadata, and advertises eligible files on the corresponding HTML page.

## Workflow

1. Create a curated `/llms.txt`.
2. Create any Markdown companion files you want, for example `/about-us/index.md`.
3. In WordPress, open **BlogLogistics > Markdown for Agents**.
4. Click **Scan for Markdown Files and Refresh Health**.
5. The plugin refreshes the Markdown Health Dashboard, validates companion encoding and llms.txt references, confirms the Apache-compatible `.htaccess` rule, creates a timestamped backup before any write, and performs a live WordPress-page/Markdown-companion check when possible.
6. Review detected companions and disable discovery for any specific page or post if required.
7. Purge page/CDN caches after changes.


## Markdown Health Dashboard

Version 2.2.0 adds an administrator-only health snapshot that is refreshed during the manual Markdown scan. It reports:

- published posts and pages checked;
- detected and missing Markdown companions;
- companions that may be stale because the WordPress content was modified after the Markdown file timestamp;
- invalid UTF-8, Unicode replacement characters, common mojibake patterns, and UTF-8 BOM warnings;
- `llms.txt` presence and encoding;
- broken same-site Markdown links referenced by `llms.txt`;
- duplicate `llms.txt` links and a missing recommended H1 heading;
- per-page discovery exclusions.

The health checks read local files only and never rewrite Markdown or `llms.txt`. External links in `llms.txt` are not fetched. The stored health snapshot is administrator-only and does not add filesystem work to public page requests.

The stale-file indicator is deliberately labelled **possibly stale**. It compares timestamps rather than comparing semantic content, and deployment tools can change filesystem timestamps.

## Performance

Public requests do not scan directories, call `file_exists()`, probe Markdown URLs, make external requests, or generate Markdown.

The scan stores a matching companion URL in the `bloglogistics_markdown_url` custom field. The front end uses the stored WordPress metadata and outputs discovery markup once when appropriate.

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

Before writing anything, the plugin checks for the equivalent three rewrite directives anywhere in the root `.htaccess` file. If they are already present, including a manually added copy with different comments, the plugin leaves `.htaccess` unchanged and does not create another backup. Only when the directives are absent does it insert the managed block immediately before `# BEGIN WordPress`, or at the top of `.htaccess` when that marker is not present. When a write is required, the existing file is backed up with a timestamped filename. The plugin then verifies the rule and, when possible, performs an end-to-end HTTP check of one WordPress page and its Markdown companion.

The plugin does not remove this rule or its backups on uninstall because doing so while physical Markdown directories remain could immediately break the corresponding WordPress permalinks.

## Per-page control

A page or post can be excluded even if its Markdown companion exists. The setting is available both in the WordPress editor and on the central BlogLogistics > Markdown for Agents screen.

## Licence

GPL-3.0-or-later.
