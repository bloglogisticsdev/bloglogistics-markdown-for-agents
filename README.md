# BlogLogistics Markdown for Agents

BlogLogistics Markdown for Agents advertises user-curated Markdown companion files and `llms.txt` for AI agents without generating Markdown or checking the filesystem on public page loads.

## Philosophy

The plugin deliberately keeps editorial control with the site owner. It does not create, rewrite, or curate `llms.txt`, `/index.md`, or any `/path/index.md` file.

Users create those files themselves. The plugin only discovers them during an explicit administrator-run scan, records the relationship in WordPress post metadata, and advertises eligible files on the corresponding HTML page.

## Workflow

1. Create a curated `/llms.txt`.
2. Create any Markdown companion files you want, for example `/about-us/index.md`.
3. In WordPress, open **BlogLogistics > Markdown for Agents**.
4. Click **Scan for Markdown Files**.
5. Review detected companions and disable discovery for any specific page or post if required.
6. Purge page/CDN caches after changes.

## Performance

Public requests do not scan directories, call `file_exists()`, probe Markdown URLs, make external requests, or generate Markdown.

The scan stores a matching companion URL in the `bloglogistics_markdown_url` custom field. The front end uses the stored WordPress metadata and outputs discovery markup once when appropriate.

## Per-page control

A page or post can be excluded even if its Markdown companion exists. The setting is available both in the WordPress editor and on the central BlogLogistics > Markdown for Agents screen.

## Licence

GPL-3.0-or-later.
