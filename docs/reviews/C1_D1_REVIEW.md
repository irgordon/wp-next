# C1/D1 review — live visual editing, form menus, stylesheet drafts

Status: implemented and verified 2026-10-26 (v1 scope step 3). No schema change.

## Correction to the B1 report (read first)

The B1 delivery reported "HTTP 53". That was wrong as stated. During B1, the
http-smoke suite actually stopped at its raster-upload check: a pre-B1
expectation (`COUNT(*) = 1` over all media rows) that B1's variant rows
invalidated. The product behavior was correct (master + thumbnail register on
upload), and the remaining smoke checks — including design publishing — never
ran in that gate. My gate procedure counted PASS lines instead of checking
exit codes and completion lines, so the failure was invisible. The stale
expectation is fixed (the count now covers masters only), the suite runs to
completion (53 checks, exit 0), and every suite result in this record was
taken from exit codes plus completion lines. The B1 review document has been
amended with the same correction.

## C1 — live visual editing

- A Visual mode joins the form editor on the post/page editor. The canvas is
  rendered by a new authenticated endpoint (`wp-admin/block-preview.php`,
  editor capability + action nonce) that runs the document through the same
  normalization and the **same public renderer** (`wp_render_blocks`), one
  frame per block, so canvas, preview and public delivery share one rendering
  implementation. Columns render per child inside the frame.
- Selecting a frame (click or keyboard) moves that block's existing form card
  into a Block settings inspector — one field implementation for both modes.
  Inspector edits re-render the affected canvas from the server (debounced);
  insert/move/remove controls exist on frames and inside columns; the form
  editor remains fully available and both modes edit the same document model.
- Desktop/Mobile width toggle narrows the canvas. An editor state line reads
  the post status plus Saved / Unsaved changes honestly (compared against the
  last loaded document).
- The block builder is generalized into per-instance initialization and is
  now reused for the header/footer shared sections on the design screen
  (same cards, toolbars, media/document pickers; the raw document stays
  available beneath). Section edits still save into design drafts and publish
  only through the design publication path.
- Menus are form-based: label/address/level/order rows with add, remove and
  move controls (plus order numbers that work without JavaScript). The old
  line syntax is no longer the editing interface; `wp_design_menu_parse`
  remains only as an internal validation round-trip. The design suite's menu
  scenarios were converted to structured rows.
- One product bug was found and fixed in this step: the structured menu
  arrays initially flowed into a string-typed form-merge and made every
  design save fatal; the merge now skips the structured keys.

## D1 — managed CSS draft preview

- The stylesheet gains a private draft slot (validated by the same CSS
  subset). Save as draft / Preview draft / Publish draft / Discard draft in
  the file editor. Preview is session-bound: administrator + action nonce,
  `no-store` + `noindex`, anonymous requests 404, and the public site keeps
  serving published bytes until explicit publication. Publication uses the
  normal version-checked publish; a conflict keeps the draft and puts its
  text back in the editor (input preservation).
- File and design histories are labeled as independent in both screens, and
  restoring one never touches the other (already true; now stated in the UI).

## Verification (exit codes + completion lines)

- composer check exit 0: PHPStan zero, PHPCS zero; smoke 27, hardening 18,
  PHP asset checks 30.
- documents 103; HTTP smoke 53 (now runs to completion); permalinks 96;
  profiles 43; design 45; assets 6 + 9; images 47 — all exit 0.
- Browser 92 (was 76): visual canvas rendering, honest state line, inspector
  edit round-trip to the public page, mobile canvas, form-menu rows retained
  across draft reopen, block-built section in draft preview, and the full
  CSS draft lifecycle (save → live output unchanged → anonymous 404 →
  authorized preview serves draft bytes → publish → live color changes).
- Upgrade and migration suites PASS; JS syntax OK.
- BLOCKED (environment): Apache (22) and nginx (26) server suites could not
  run — a VM replacement removed both server binaries and the package lists
  are damaged in this image. They passed on this code lineage earlier the
  same day; the only delivery-path change since is the CSS preview branch,
  whose non-preview behavior is unchanged. They must be re-run when the
  servers are available again, and J1 will need them regardless.
- The intermittent theme-check failure did not recur; it remains unresolved.
