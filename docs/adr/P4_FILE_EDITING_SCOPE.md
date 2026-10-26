# P4: first slice, controlled public file editing

Status: implemented. The user selected controlled file editing as the first P4 area. This does not close the other P4 account-lifecycle or publishing-integration work.

## Approved files and behavior

- custom.css: a global public stylesheet, automatically linked by both bundled themes after its first publication.
- site-notice.txt: a public UTF-8 text download at /site-assets/site-notice.txt.
- Both are logical, database-backed managed files with immutable version rows and a current-version pointer. They are not writes into deployed theme source. The existing database snapshot backup includes their complete history.
- Only administrators can publish or restore. POST/CSRF and an expected current version protect each change. Conflicts preserve submitted text without advancing the expected version. Restore adds a version; it does not remove history.
- Each version is limited to 128 KB of valid UTF-8 without NUL bytes. Delivery uses a fixed MIME, nosniff and no-store. The files are public; do not put secrets in the notice.

## Stylesheet contract

Allowed selectors: body, .entry-title, .entry-content, .site-header, .site-footer, .site-navigation and .shared-section; optional :hover/:focus-visible. Comma-separated combinations are supported. Allowed declarations are color/background-color, font size/weight, line height, letter spacing, text alignment, margin and padding. Values are bounded simple colors, lengths and named alignment values as described in the editor. CSS comments are allowed; unfinished comments, imports, escapes, external resources, scripts, HTML, arbitrary selectors and unsupported declarations fail validation.

The stylesheet is an independent layer across themes. Design rollback does not roll back managed files; use File history for that. Custom styles can override design choices, so preview the public result and use history when needed. This is a deliberately restricted stylesheet editor, not full CSS language support.

## Excluded boundaries

No request parameter becomes a filesystem path. Theme PHP, plugin PHP, configuration, secrets, uploads, directories, arbitrary source CSS and arbitrary text files cannot be edited. There is no package installer or general filesystem browser in this slice. P1 document uploads/downloads remain disabled.

## Remaining P4 areas

Account invitations, password recovery, user deletion with content reassignment; content import/export; feeds, sitemaps and social metadata; API/webhook contracts. These need their own scoped acceptance requirements. The current delivery implements the user-selected first slice only.
