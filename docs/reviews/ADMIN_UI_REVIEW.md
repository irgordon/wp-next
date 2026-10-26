# Admin UI changes — review notes

Implemented 2026-10-24 on Ian's direction: installer wizard, sortable list
columns with hover row actions (quick edit, trash), a full-height sidebar,
and icon account controls in the admin toolbar.

## Installer wizard (wp-admin/install.php, wp-admin/js/install.js)

- The installer is now two steps inside the same single form: **Step 1**
  is a welcome/overview (what WordPress Next is, the SQLite storage model,
  the hardened defaults, what happens next); **Step 2** is the existing
  site/administrator form, unchanged in fields, names, and nonce.
- A progress bar and a "Step N of 2" label sit above the steps. Next and
  Back buttons are wired by `js/install.js`; the Install button stays on
  step 2.
- No-JavaScript behavior: both steps render stacked on one page and the
  form submits exactly as before (the wizard only hides steps when JS is
  active). The POST contract is unchanged, so non-browser installs are
  unaffected.

## Content lists (wp-admin/edit.php, wp-admin/js/admin.js)

- **Sortable columns:** Title, Author, Status, Revision, and Updated are
  header links that toggle ascending/descending, with `aria-sort` on the
  active column and arrow indicators. Sorting is server-side against a
  fixed column whitelist; search, view, and pagination state persist.
- **View switcher:** All (n) / Trash (n) above the toolbar.
- **Hover row actions** (also revealed on keyboard focus-within, and always
  visible on touch devices):
  - All view: **Edit** (full editor), **Quick Edit** (inline panel:
    title, slug, status — saved through the same mutation service with
    revision-concurrency checks), **View** (published only), **Trash**.
  - Trash view: **Restore**, **Delete permanently** (opens an inline
    confirmation panel naming the item before anything is deleted).

## Trash lifecycle (schema v3)

Implements the content half of `docs/DELETION_FEATURE.md` (people
deletion is untouched and remains unimplemented):

- Schema v3 adds `trashed` to the posts status check. Existing databases
  are migrated by a one-time, transactional posts-table rebuild
  (`wp_migrate_posts_status_check()` in `wp-includes/schema.php`) after
  the normal upgrade pass; fresh installs create the new check directly.
- `WP_Post_Mutation_Service::trash_post()` records the pre-trash status in
  post metadata; `restore_post()` returns the item to exactly that status
  (the slug never leaves the unique index, so restore cannot collide).
  `delete_post()` refuses anything not in the Trash, deletes revisions
  with the post, clears a dangling `homepage_id`, and keeps referenced
  media. Every transition is permission-checked in-transaction and writes
  an audit event (`post_trashed` / `post_restored` / `post_deleted`).
- Trashed content leaves public delivery immediately (all public queries
  already gate on `published`) and leaves the default list view.
- The full editor shows a Trashed status for trashed items and refuses to
  save content changes until the item is restored.
- `tests/upgrade-smoke.py` now expects schema version 3 (its other
  assertions are unchanged).

## Sidebar (wp-admin/css/admin.css, wp-admin/admin-header.php)

- The admin shell is a flex row: the sidebar is a full-height column whose
  charcoal background now spans the entire document height (previously
  the fixed sidebar painted only one viewport height, leaving a grey gap
  below it on long pages and in full-page captures). The inner
  `.sidebar-stick` wrapper keeps the navigation pinned (sticky, viewport
  height) while scrolling; on mobile the sidebar stacks as before.

## Account icons (wp-admin/admin-header.php)

- The toolbar's username text and "Log out" text are replaced by
  user/logout icons sourced from SVG Repo, inlined as SVG (no external
  requests). Accessible names are preserved (`Your profile`, `Log out`),
  so assistive tech and name-based automation see the same actions.
  Sources (both CC0, SVG Repo "User Interface" collection; selected by
  Ian, superseding an earlier pair): profile —
  https://www.svgrepo.com/svg/115674/profile; logout —
  https://www.svgrepo.com/svg/150530/logout. The icon geometry is used
  verbatim (verified path-for-path against the downloaded files); only
  the fill is changed to `currentColor` so the icons follow the toolbar's
  text and hover colors.

## Verification

- `composer check` — lint, PHPStan level 5 (zero), PHPCS (zero).
- Suites: PHP 44, HTTP 53, permalinks 96, browser 49 (its install steps
  now click the wizard's Next first), upgrade 4 (including the v3
  migration of a legacy database), Apache 22, nginx 26.
- Live probes (Playwright, disposable install): wizard step flow and
  Back/Next behavior; title sorting A→Z; quick-edit rename with the
  success notice; trash → public 404 → Trash view → restore returns the
  post to Published and its URL to 200; permanent delete removes the post
  and leaves an audit trail of all four events.

## Addendum (2026-10-24, later): Summary and Revision history move to the sidebar

At Ian's direction, the editor's **Summary** (excerpt) panel and **Revision
history** panel moved from below the content column into the right-hand
sidebar, stacked under Publish: Publish → Summary → Revision history.

- `wp-admin/post-editor.php` — Summary is now a panel inside the `<aside>`;
  Revision history renders as a grid sibling in the same column. Because its
  restore controls are their own small forms and HTML forms cannot nest, the
  editor form is now a `display: contents` grid participant: DOM ownership
  (and therefore the POST contract, draft recovery, and FormData behavior) is
  unchanged, while the grid places the main column, the sidebar, and the
  revision panel. The Publish panel's `sticky` positioning was retired — a
  stacked sidebar that can hold 30 revision rows should scroll as one column.
- `wp-admin/css/admin.css` — `.editor-grid` now sits on the outer wrapper;
  added `.editor-form { display: contents; }`, column/row placement for
  `.editor-main`, `.editor-side`, and `.editor-revisions`, a full-width span
  for `#draft-recovery`, and the single-column resets at the 1100px breakpoint.

Verification: `composer check` exit 0 (the project's formatter normalized the
revision block's indentation; whitespace-only), HTTP 53, browser 60/60, and a
live restore from the relocated panel reverted a post's title and extended
its history. Screenshots: `wpnext-editor-sidebar-2026-10-24/` (edit post with
all three panels in the sidebar; new page with Publish + Summary).

## Correction (2026-10-25): desktop grid placement

Ian's measurement review of the sidebar move found two desktop regressions
in the grid placement above (functional checks all passed; positions did
not): with a long post, Revision history landed at the main column's bottom
(measured start 7,726px against Summary ending at 1,585px) because the main
column's two-row span let the shared grid rows absorb its height; and the
draft-recovery banner, placed by auto-placement alone, fell below the main
editor and displaced the sidebar on desktop.

Fix, `wp-admin/css/admin.css` only (no PHP change; the independent restore
forms are untouched): the grid now has explicit rows (`auto auto 1fr`) with
zero row gap — the banner is explicitly placed in row 1 spanning both
columns, the main column spans rows 2–3, the Publish/Summary stack sits in
row 2, and Revision history is explicitly placed in row 3 with `align-self:
start` and its own 24px top margin, so the stack's spacing is fixed rather
than derived from the main column's height. The single-column breakpoint
resets the row template and placements.

Re-measured with Playwright on a 12-section post (main column 17,909px):
Summary bottom 1,385px → Revision history top 1,433px (the stack's normal
48px panel rhythm) regardless of article length; with the recovery banner
shown, the banner spans 184–300px and both the main column and Publish start
at 324px; on mobile the banner remains above the editor. Gates re-run:
`composer check` exit 0, browser 60/60.
