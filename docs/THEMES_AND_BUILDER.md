# Themes and authoring

## Shared editing workflow

Create Posts and Pages from their respective navigation items. Save Draft for private work, preview through the authenticated preview link, and publish when ready. Archived content leaves public delivery and can be restored to Draft/Published. Prior saves appear in revision history; restoring creates a new revision. Failed validation or a stale revision keeps the submitted form and asks the author to reload deliberately rather than overwriting another save.

The editor supports paragraph, heading (levels 2–6), list, quote, code, image, columns (two or three), HTML, and divider blocks. Rich text supports bold, italic, and links; paste inserts plain text. Library images carry alternative text and captions. Blocks can be reordered and removed with buttons; nested columns contain the same block types. The raw document editor and visual canvas synchronize in both directions. Invalid/unsupported JSON is retained and blocks saving until corrected. Browser-tab drafts offer explicit restore/discard after reload; they are local recovery aids, not server autosave or cross-device synchronization.

## Canonical document

```json
{"version":1,"blocks":[{"type":"heading","level":2,"html":"Welcome"},{"type":"paragraph","html":"Read <strong>our story</strong>."},{"type":"columns","columns":[[{"type":"paragraph","html":"Left"}],[{"type":"paragraph","html":"Right"}]]}]}
```

Server limits: 2 MB document, 500 blocks, eight nested layouts. Unknown block types fail validation. Legacy HTML/Gutenberg-comment content imports as one sanitized HTML block to avoid silent structural loss. Gutenberg plugins, arbitrary embeds, and round-tripping every Gutenberg AST are not supported.

Media accepts JPEG, PNG, WebP, and GIF (10 MB, 40 megapixels, 12000-pixel maximum side). SVG and arbitrary files are rejected. Images are public, content-addressed immutable files. Library metadata is editable; referenced images cannot be removed from the library. Removing an unused record does not erase its public bytes.

## Site design

Administrators choose the Default or Studio theme, logo, accent/background, width (640–1400 px), and sans/serif typography. Accent/background text contrast must reach 4.5:1. Choose latest Posts or a published Page as the homepage. Select up to 20 published Pages and assign their navigation order. Publication status is rechecked during public navigation rendering.

Only installed themes with valid confined real paths and required header/footer/index files are selectable. WP_DEFAULT_THEME supplies the fallback. Theme functions.php is loaded as trusted source. Templates share get_header(), get_footer(), wp_head(), the loop, featured images, permalinks, and pagination helpers.

| Route | Template fallback |
| --- | --- |
| Published Page | page.php → index.php |
| Published Post | single.php → index.php |
| Blog archive | archive.php → index.php |
| Search | search.php → index.php |
| Missing route | 404.php → index.php, HTTP 404 |

Paths use configured site_url, including a deployment subdirectory. Legacy ?slug= requests redirect to canonical pretty URLs. Search and archives paginate ten published Posts at a time. Draft previews require edit permission and use no-store headers. There is no general REST endpoint, feed, taxonomy router, or WordPress theme compatibility layer.
