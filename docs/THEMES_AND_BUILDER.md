# Themes and authoring

## Shared editing workflow

Create Posts and Pages from their respective navigation items. Save Draft for private work, preview through the authenticated preview link, and publish when ready. Archived content leaves public delivery and can be restored to Draft/Published. Prior saves appear in revision history; restoring creates a new revision. Failed validation or a stale revision keeps the submitted form and asks the author to reload deliberately rather than overwriting another save.

The editor supports paragraph, heading (levels 2–6), list, quote, code, image, columns (two or three), HTML, and divider blocks. Rich text supports bold, italic, and links; paste inserts plain text. Library images carry alternative text and captions. Blocks can be reordered and removed with buttons; nested columns contain the same block types. The raw document editor and visual canvas synchronize in both directions. Invalid/unsupported JSON is retained and blocks saving until corrected. Browser-tab drafts offer explicit restore/discard after reload; they are local recovery aids, not server autosave or cross-device synchronization.

## Dedicated visual builder

Choose **Visual editor** from a Post or Page to open its full workspace. It also supports starting a new Post/Page directly. Click text on the page to edit it; select a block for additional settings. Use the block palette or drag blocks onto the page and into columns. The Layers tab selects nested blocks. Duplicate, Delete, Undo and Redo operate on the same document shown by the form editor.

The canvas loads the public renderer and published theme styles in a script-disabled iframe. Administration controls use a compact 12px base with 10–11px supporting text; the page preview retains its published typography. Desktop and Mobile select actual 960px and 390px preview widths. Narrow administration screens expose Blocks and Settings as drawers.

**Save changes** persists through the normal controller, selected publication status and expected revision. Switching editor views only transfers browser-tab draft state; it does not save or publish. After a reload, Restore draft/Discard draft remain explicit. Unsupported raw documents remain intact, and attempting to save reveals the correction field. Use the Form editor control to return to the standard administration layout.

Keyboard commands: Command/Control-S saves, Command/Control-Z undoes a builder change, and Command/Control-Shift-Z redoes it. Page settings retain status, template, featured image, taxonomy and excerpt controls. The standard editor retains revision history.

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
