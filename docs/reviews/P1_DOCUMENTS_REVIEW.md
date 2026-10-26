# P1 document bundle: implementation review

Date: 2026-10-25. Baseline: the adopted 145-file P3/P4 package (schema 6). Built against the P1 asset model packet, Revision 3, whose rule is that document intake stays disabled until the validator, delivery, backup and reference rules land together. This bundle lands them together; the schema does not change (the v5/v6 asset model already carries kind/format/visibility/state).

## Delivered scope

- **Intake** (`wp_handle_document_upload` in `wp-includes/assets.php`): PDF, text, Markdown and CSV up to 25 MB through the existing stage → register → promote pipeline. Canonical format comes from the filename extension; detected MIME is evidence only (PDF requires the `%PDF-` signature and `application/pdf`; text formats require valid UTF-8, no NUL bytes, and a text/plain or text/csv detection). Record reuse respects the requested format **and** visibility and only returns a record the uploader can access whose bytes verify in storage; an inaccessible match produces a new record over the same bytes without disclosure, missing bytes are repaired by the reupload itself, and corrupt bytes refuse the upload with an explicit integrity error (round 3, F3). A failed promotion leaves the pending record for maintenance recovery and is not reported as a usable upload.
- **Delivery** (`wp_asset_serve_document`, hooked in `index.php` beside managed-file delivery): `GET /documents/{id}` rechecks authorization on every request. Missing, non-document, incomplete or inaccessible assets all answer the same 404. Every response — success and refusal — carries `Cache-Control: no-store` and `nosniff`; successes add the format's media type, the blob length and an attachment disposition carrying the original filename. The precise guarantee stands: making a document private stops subsequent authorized delivery; it cannot revoke copies already downloaded.
- **Library** (`wp-admin/media.php`): a Documents tab with its own upload form (visibility choice, unscanned-download labeling), access-filtered listing (public, own, or media managers), per-document visibility toggle and reference-guarded removal. Images keep their existing tab, forms and behavior.
- **References** (`wp-includes/content.php`, mutation service): a `file` block type validated at save for any status (ready document the author can access) and rechecked in `verify_media`, where publication additionally requires a public document. The renderer emits the download link only for a viewer who can access the document, so the anonymous cached render never contains private links. Visibility changes invalidate referencing posts' cached renders and queue re-render jobs in the same transaction. Deletion protection (`wp_media_in_use`) already scans block media ids across posts, revisions and design sections.
- **Backups** (`wp-includes/operations.php`): ready document blobs are archived from the protected tree under `assets/`, recomputed from the hash and hash-verified; a missing, corrupt or non-canonical ready asset fails the backup loudly, naming the asset id. Incomplete registrations carry no deliverable bytes and travel in the database snapshot only; the manifest now records each asset's kind, format, visibility and state. Pre-v5 snapshots keep working (their rows are public ready images by construction).
- **Editor and upload UX**: the page builder gains a File block with a document picker fed by the access-filtered JSON endpoint (`media.php?format=json&kind=documents`); uploads on the media page are enhanced to one request per file with per-file results (D4(b)), navigating to the classic success URL when every file succeeds, and the classic form processes multiple files sequentially with no batch atomicity.

## Backup treatment of incomplete registrations (accepted with an explicit contract)

Non-ready assets are manifest-only in backups. Stated plainly: **a backup can lose an upload whose registration committed but whose promotion had not completed.** The contract that makes that acceptable, as ruled on review:

- The manifest identifies incomplete registrations in `incomplete_assets` (id and state) and counts only ready, archived assets in `recoverable_files`; incomplete records are excluded from that count.
- After a restore, `bin/wp maintain` removes an unrecoverable pending record, releases its registered quota and records the loss in the audit log (`asset_registration_failed`) — demonstrated by the restore test in `tests/documents-http.py`, which also confirms the ready document survives intact.
- Ready assets still fail the backup loudly on missing or corrupt bytes, naming the asset.

## Corrections after return for correction (2026-10-25)

The first delivery of this bundle was returned with three findings; all are fixed and covered by tests that did not exist in the first delivery:

1. **Document-picker parameter mismatch (P1).** The editor's document URL used `kind=document` while the library recognizes `kind=documents`; the picker silently fell into the Images branch. One parameter is now used consistently (`kind=documents`), and the browser suite opens the document picker, selects a document, saves the post and re-opens it to prove the File block persists with its reference (browser checks 73 → 76).
2. **No-JavaScript multiple uploads (P1).** Both file inputs carried `multiple` but scalar names, so PHP kept only the final file. The inputs are now array fields (`image[]`, `document[]`), the server-side per-file loop and the XHR client both operate on that shape, and the documents suite submits two files in one classic post — one valid, one rejected — and asserts the valid file is stored, the invalid one is not, and the reason is reported (no batch rollback).
3. **Split JSON Content-Type (P2).** The library's JSON header string spanned two source lines and contained a literal newline, which PHP refuses to send; body-only assertions could not see it. The header is now a single line, and the suite asserts the exact `Content-Type` response header on both the picker and the XHR upload responses.
4. The stale opening comment in `wp-includes/assets.php` ("document intake is not enabled") is corrected.

## Corrections round 2 (source-review findings, 2026-10-25)

Three further findings from source review, all reproduced and fixed; the documents suite grows
49 → 64 checks.

1. **Rendering re-ran save authorization (P1).** `wp_render_content()` normalized through the
   save-time validator, so one File block the viewer could not access replaced the whole post
   body with "Content could not be rendered." Save authorization and rendering are now separate:
   normalization takes a rendering mode in which the File block is structurally validated only,
   and the renderer omits just that block. The suite asserts the surrounding paragraphs survive
   a public → private flip both before and after queue processing, with no error substitution.
2. **A stale render could undo the invalidation (P1).** The flip set `rendered_revision = 0`
   without changing the post revision, so HTML prepared before the flip could commit after it.
   The flip now also bumps a render generation (`render_epoch` in post metadata, inside the
   same transaction); `Render_Post_Job` captures the generation at prepare time and its commit
   only applies while the generation still matches. The suite stages the exact sequence —
   prepare (link present), flip, commit — and asserts the stale commit is rejected, then that
   the fresh render keeps the body and omits the link. (The commit compares the generation as
   an integer: the JSON-extracted value has no column affinity, so a text-bound parameter would
   never match.)
3. **The download route collided with custom permalinks (P1).** `/documents/%post_id%` was a
   valid structure while the front controller claimed `/documents/{id}` first. The prefix is
   now reserved at save time: new structures claiming it are refused with the reservation
   message, never silently stored. A structure stored before the reservation is honored on
   read (malformed structures still fall back): posts keep their `/documents/...` URLs, the
   permalink history keeps resolving them, document delivery yields the pretty path and moves
   to the root query form (`/?document={id}`), and generated document URLs — library links and
   File blocks — use whichever form currently works. The suite covers the refusal, the legacy
   post resolution, the pretty URL no longer serving the document, the query-form download,
   and the library link. Documentation fix from the same review: the scope section above now
   names the picker endpoint as `kind=documents`, matching the code.

## Corrections round 3 (blank-install verification findings F1–F5, 2026-10-25)

An independent blank-install verification (522 supplied checks green, 46 adversarial probes)
held acceptance for five findings. All five are fixed; the documents suite grows 64 → 83
checks. The verifier's probes are the acceptance bar for each item.

1. **F1 (P1) — document delivery stole valid post URLs.** The conflict test only examined the
   first literal segment of the *current* structure, missing historical structures and dynamic
   expansions: a post kept under a historical `/documents/%post_id%` 404'd after the structure
   changed, and a post by an author named `documents` under `/%author%/%post_id%` 404'd
   outright. Ownership is now resolved, not pattern-matched: the document route yields any
   `/documents/{id}` path that `wp_permalink_lookup_post()` resolves to a post under the
   current structure, the retained history, or a dynamic tag expansion, and
   `wp_document_url()` applies the same test when choosing between the pretty and query
   forms. The suite asserts the resolved resource's identity (the post body) in both cases.
2. **F2 (P1) — draft saves did not recheck access under the write lock.** Normalization checks
   access before the transaction, so a staged public → private flip between validation and
   commit let another author's reference persist. `verify_media()` — already called inside
   both the insert and update transactions — now also rechecks `wp_asset_can_access_as()`
   for the acting author. The suite stages the exact interleaving through a dispatcher
   callback between normalization and commit, for insert and update, and asserts the save is
   refused and nothing persists. Download authorization was never bypassed; this closes the
   reference-persistence gap only.
3. **F3 (P2) — reuse reported success over missing or corrupt bytes.** The matching-record
   branch returned a ready ID without verifying storage. Reuse now verifies the blob
   (canonical path, present, SHA-256 match) before returning a record. Missing bytes fall
   through to the normal lifecycle, which re-registers and promotes the uploaded bytes —
   the reupload repairs and downloads. Corrupt bytes at a content-addressed path are never
   overwritten or reported as usable: the upload is refused with an explicit integrity
   error so the operator repairs storage first, and no record is registered. Both cases
   are tested.
4. **F4 (P2) — backup and delivery disagreed about symlinks.** Delivery validates through
   `wp_asset_blob_path()` (symlinked root, prefix or file refuses); backup rebuilt the path
   itself and checked only the final component, archiving through a symlinked prefix. Backup
   now uses the same canonical validator for documents and keeps the loud, asset-naming
   failure. The suite refuses backup for a symlinked prefix directory and for a symlinked
   blob file, then confirms backup succeeds once canonical paths are restored.
5. **F5 (P2) — documented deployment limits contradicted the 25 MiB contract.** INSTALL.md
   shipped `client_max_body_size 12m` and PHP minimums of 10M/12M. The documented values are
   now nginx `client_max_body_size 32m`, `upload_max_filesize=26M`, `post_max_size=32M` —
   the per-file ceiling plus multipart overhead — with an explicit note that the enhanced
   uploader sends one file per request while the no-JavaScript form submits a batch in one
   request, so multi-file batches need the POST allowances raised to the batch total.

## Corrections round 4 (R1: cached File links across routing changes, 2026-10-26)

The round-3 review confirmed all five findings fixed (46/46 probes) and raised one remaining
P1: a File block rendered while `/documents/{id}` was unambiguous kept that link after a
permalink change moved the path to a published post, so the stored link returned the other
post's HTML (HTTP 200) instead of the document. Fresh `wp_document_url()` output was already
correct; the cached render was never invalidated.

Document URLs are render inputs, so routing changes now invalidate File-block renders through
the same generation mechanism as visibility flips. A shared helper,
`wp_invalidate_file_link_renders()`, zeroes `rendered_revision`, bumps `render_epoch` (which
also fences any worker result prepared before the change) and enqueues a re-render for every
post whose stored content embeds a File block. It runs inside the changing write transaction
at every transition that can move route ownership:

- permalink settings saves that actually change the structure (history updates included);
- post inserts (a new post can take a `/documents` path);
- post updates (slug, author, category and status/publication changes);
- trash and restore transitions;
- category slug changes.

The regression replicates the probe on a blank install: documents are uploaded so the target
document shares its ID with a published post by an author named `documents`; a referencing
post is published and its render cached (the stored pretty link downloads the target bytes
with an attachment disposition); the administrator then saves `/%author%/%post_id%` through
the UI; the other post owns `/documents/{id}`, the referencing post's stored link is
regenerated in the query form, and following it downloads the exact bytes with attachment
headers — before and after the re-render drains. The documents suite grows 83 → 95 checks.

## Verification (scratch copy of the final tree, PHP 8.4.23)

- `tests/documents-http.py` (new, wired as `composer test:documents` and into CI): **95 checks** covering private/public delivery and header contract, cross-author isolation in library, picker, download and pending states, the intake validation matrix, dedupe reuse/visibility/format semantics with byte verification, storage-loss repair and corrupt-byte refusal, the staged save-time access race on insert and update, interrupted-upload recovery order via `bin/wp maintain`, save-time reference validation (drafts and publication), the visibility-flip render withdrawal with surrounding content intact before and after queue processing, the staged stale-render rejection, the permalink reservation, legacy-conflict fallback, route ownership for historical and dynamic-tag post URLs, and the cached-link lifetime across a routing change (stored link followed; bytes and attachment headers asserted), document ids in image-only fields, delete-while-referenced, the XHR JSON contract, quota rejection without partial records, backup archiving plus loud corruption failure naming the asset, and backup symlink refusal at the prefix and file components.
- No regressions: `composer check` (27 smoke + 18 hardening + 30 asset checks, PHPStan level 5 zero errors, PHPCS subset clean), design/file suite 45, HTTP 53, profiles/taxonomy 43, permalinks 96, asset integration 6, asset HTTP 9, migration 3, upgrade PASS set, browser 76 (including the XHR-enhanced media upload, the editor, and the document picker flow), `check:js`, Apache 22, nginx 26.
- One defect found and fixed during verification: the first backup implementation selected the v5 asset columns unconditionally and failed against pre-v5 snapshots exercised by the asset-integration suite; the select is now schema-tolerant with legacy defaults.

## Not in this bundle

Audio/video and SVG (packet exclusions), malware scanning (documents are labeled unscanned), chunked uploads, per-user quotas, and the remaining P4 account/integration areas. The intermittent http-smoke failure recorded at P3/P4 adoption did not recur in this build's runs.
