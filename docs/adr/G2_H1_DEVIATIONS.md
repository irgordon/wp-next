# Errors And Deviations — v0.0.3 (G2) And v0.0.4 (H1)

Recorded October 28, 2026, at Ian's direction: the changelog stays short;
the errors, deviations and verification detail for the G2 and H1 builds
live here. The governing contracts are unchanged — [the approved
scope](V1_SCOPE.md) §G2/§H1 and Ian's October 26 rulings stand as written.
Nothing in this record amends them.

## Deviations

1. **A baseline test probe changed (H1).** `tests/http-smoke.py` used
   `/feed/` as its unknown-route example. H1 makes `/feed/` a real route
   (200, correctly), so the probe now uses `/atom/`, a feed format this
   application does not provide. Verified as a one-line diff; no other
   assertion in the suite moved.
2. **Discovery routes take precedence over content (H1).** The `feed`,
   `sitemap.xml` and `robots.txt` branches in `index.php` match before
   post/permalink resolution — the WordPress behavior. A Page whose slug
   is exactly one of those names is shadowed at its pretty path and
   remains reachable by `?page_id=`. The alternative (content wins)
   would make the feed unreachable whenever such a Page exists.
3. **Social-image validation is split (H1).** Editor saves validate
   `social_image_id` strictly, exactly like the featured image. A
   `social_image_id` arriving inside imported or legacy post metadata
   is instead validated at read time (ready image records only), so a
   G2 import carrying foreign IDs degrades to the featured image
   instead of failing.
4. **Import intake reuses steps, not the upload handlers (G2).**
   `wp_handle_image_upload()` / `wp_handle_document_upload()` require
   `is_uploaded_file()`, which extracted archive members are not. Intake
   therefore reuses their exact steps (normalize → hash dedupe →
   quota-checked register → variants; validate → stage → register →
   promote) rather than calling them.
5. **Authorship and dates are corrected after insert (G2).** The post
   mutation service forces author = acting user and stamps its own
   dates, so the import run sets `author_id`, `published_at`,
   `created_at` and `updated_at` on the new record after
   `wp_insert_post`. Source authorship and dates are part of the format
   contract; this is deliberate and suite-asserted.
6. **Changelog structure.** The repo changelog carries per-version
   sections (v0.0.1–v0.0.4) above the consolidated v0.0.0 history, which
   deviates from the single-heading rollup rule Ian set on October 26.
   The v0.0.3 and v0.0.4 sections were shortened to concise entries in
   the v0.0.4 push; whether to consolidate the version sections into the
   single heading remains Ian's decision.

## Decisions recorded inside the rulings

- The sitemap lists the configured homepage once, at the home address,
  not also at its slug address (whose canonical is the home address).
- `og:type` is `article` for Posts and `website` for Pages. No Twitter
  Card tags were added; they are not named in the H1 scope.
- Discouraging search engines does not suppress the feed or the
  sitemap; it flips `robots.txt` and adds the robots meta, per the
  approved design.
- Native export archives include private document bytes (an
  administrator backup-class operation), recorded in
  `docs/INVARIANTS.md` at G2 delivery.

## Errors during verification, and their corrections

G2 build:

- First `composer check`: PHPStan reported 3 errors on ZipArchive
  `statIndex` handling (simplified); PHPCS reported 47 auto-fixable
  violations plus one manual Yoda fix in the new files. Re-run green.
- Uploaded WordPress exports named `.wxr` were treated as ZIP imports
  (only `.xml` was recognized as WXR). Both extensions are now accepted
  in the admin screen and the CLI.
- A quota-exhausted image surfaced as a generic registration failure
  because the intake wraps its errors; the quota outcome is now a
  distinct per-item result.
- The portability suite's completion line initially printed a count of
  0 (the counter was the helpers module's, not the suite's). Fixed;
  the true count is 66.
- The server fixtures were initially invoked with the wrong binary
  argument (they take their server binary, not PHP). The resulting
  nginx failure and Apache timeout were artifacts, superseded by the
  correct runs.
- The Apache fixture was first classified as sandbox environment drift.
  That classification was wrong: with the standing harness conditions
  (umask 022, a world-traversable TMPDIR) the fixture passes, 22
  checks, on the G2 tree. The failing runs had used a private TMPDIR
  that Apache's `nobody` user cannot traverse — the harness artifact
  first recorded on October 26.

H1 build:

- First `composer check`: 3 PHPCS findings in `post-editor.php`,
  corrected within the edited lines.
- The discovery suite's first runs failed on fixture facts, not
  product behavior: the installer's seeded "Hello World!" post took
  the newest feed slot (the suite now removes seeded content through
  the real trash → delete path); two byte-identical fixture PNGs
  correctly deduped into one media row (fixtures now differ); a
  refused-save assertion expected empty metadata where a form-created
  post stores `'[]'` (the assertion now checks that no
  `social_image_id` was recorded).
- The first nginx fixture run 404'd the discovery routes because the
  fixture docroot has no `index.php` for `try_files` to hand the
  request to; the positive control now installs the fixture front
  controller, as the endpoint controls already did. The Apache control
  needed no such step.

Both builds:

- The design-pages browser suite times out in this sandbox at the
  step immediately after the embedded editor's Save Draft → Back to
  page list, because the parent workspace's state updates only via
  asynchronous postMessage from the editor bridge while the suite
  waits only on the iframe's saved flag. A probe copy with a short
  wait passes the full suite on both trees (50 checks on the c6cbdb2
  baseline, 59 on the v0.0.3/v0.0.4 trees). The product and the suite
  were left untouched in both builds; the fix is Ian's call.
- The intermittent http-smoke theme-settings failure from October 25
  did not recur in either build and remains unresolved.

## v0.0.6 Corrections

The local review found four boundary failures beyond the original acceptance suites. [The v0.0.6 record](../reviews/V0_0_6_REVIEW.md) closes them with positive regressions. Imported social IDs now use the media map rather than relying on a ready-ID collision check. WXR attachment references are rewritten before normal sanitization. Generated import provenance is committed with each new Post so a failed later bookkeeping/checkpoint step can recover the original item. The separate source-author/date correction remains, is retryable, and preserves later operator edits. Export now refuses output outside the version-1 importer bounds. These repairs retain format version 1, schema 8, the supplied-media-only policy and the approved scope.
