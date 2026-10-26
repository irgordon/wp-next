# B1 Image Tools — Review

Status: in implementation (v1.0.0 scope step 2). Baseline for this work is the
release evidence baseline of 2026-10-26: composer check exit 0 — PHP smoke 27,
hardening 18, **PHP asset checks 30** (recorded explicitly; an earlier summary
listed only the Python asset suites, 6 + 9), documents 95, HTTP 53, permalinks
96, profiles 43, design 45, browser 76, Apache 22, nginx 26, upgrade and
migration PASS, JS syntax OK. The one intermittent theme-check failure remains
unresolved; A2 remains implemented and under test, not closed by suite counts.

## Approved boundaries (Ian, 2026-10-26)

- Replacement updates explicitly selected **current** featured-image,
  image-block and shared-section references only. Avatars and the site logo
  are never touched. Permission is checked for **every** affected content
  item. Historical post revisions and design revisions are preserved.
  Shared-section changes follow the existing design publication workflow
  (a staged draft, published through the normal path — never a silent live
  change).
- Fixed variants: a 150 × 150 cropped thumbnail, plus 600 px and 1200 px
  widths preserving aspect ratio. Never upscale. The focal point drives
  cropping. Smaller masters produce valid smaller outputs: the thumbnail of a
  master smaller than 150 px on a side is the largest focal square that fits
  (side = min(150, width, height)), and width variants are produced only when
  the master is wider than the target.

## Implementation requirements (Ian, 2026-10-26)

1. **Lineage distinguishes derivation from ownership.** A variant row points
   at its master (`parent_id`, `variant` name) and carries the master's
   author. Generating a variant grants no access, changes no attribution and
   never permits deleting a referenced master; deletion rules evaluate the
   master, and the family is removed only when the master itself may be.
2. **Pending means recoverable.** Variant registration records its source and
   transformation (parent + variant name; sizes are deterministic from the
   name and the master's focal point), so maintenance can regenerate the
   bytes from the master. Maintenance either completes an interrupted
   registration (bytes present and verified, or regenerated) or removes the
   unusable registration and releases its quota, with the loss audited.
3. **Replacement is reviewable.** The impact (affected posts, reference
   kinds, shared sections) is computed and shown before applying. Any
   unauthorized target rejects the whole operation; nothing is silently
   skipped while success is reported.
4. **Backups restore the family.** Master, edited copy and variants are
   archived and restored together; a missing or corrupt ready file fails the
   backup loudly, naming the asset; interrupted generation follows the
   incomplete-registration contract (not counted recoverable; restore +
   maintenance removes the record, releases quota and audits the loss).

## Design

- Schema 7 adds `parent_id`, `variant`, `focal_x`, `focal_y` to `media`.
  Edited copies are new masters (`variant=''`) whose `parent_id` names the
  source; variants are rows with `variant` in {thumbnail, medium, large}.
- Transforms (`wp_image_transform`) reuse the intake limits (byte, pixel and
  memory ceilings) and encoders. Generation registers each variant pending,
  places its bytes at the canonical content-addressed path, verifies the
  hash, then flips it ready — the same shape as document promotion.
- Rendering emits `srcset`/`sizes` for image blocks and featured images from
  ready variants plus the master; the sanitizer allows both attributes only
  with site-local upload URLs.
- Registered versus physical usage extends `wp_asset_usage()` with a
  masters / variants / documents split; variants count as registered bytes,
  and the physical figure counts each stored file once, so shared or
  not-yet-cleaned bytes explain the difference.

## A2 retained case

The documents suite gains the two-change fencing case: a render prepared,
then **two** routing changes before it commits — including a commit attempt
from a render that was already invalidated by the first change. Both stale
commits must be rejected and the final stored link must follow the current
routing to the document bytes.

## Results (2026-10-26)

Schema 7 (migration + fresh-install shape; the upgrade suite's expectation moved
6 → 7, as did the blank-schema assertions in the PHP asset suite, assets
integration, migration regressions, profiles and design suites — expectation
updates only, no assertion weakened). New suite `tests/images-http.py`
(`composer test:images`), 47 checks: variant set and dimensions on upload,
small-master outputs, srcset on blocks and featured images, master-scoped
dedupe, focal-driven thumbnail (pixel-probed), crop/rotate copies with their
own variants, replacement preview/apply/rejection, logo and revision
preservation, pending-variant regeneration and quota-releasing removal,
master deletion removing derived rows, and the backup family (missing ready
variant fails loudly naming the asset; interrupted variant restores as an
incomplete registration and is regenerated by maintenance after restore).

While adding Ian's retained A2 case (two routing changes, one render already
invalidated), the documents suite caught a real fencing gap:
`wp_invalidate_file_link_renders()` only bumped the render generation for
posts with a cached render, so an in-flight render of an already-invalidated
post escaped the second change and could still commit. The helper now bumps
the generation for every File-block post and enqueues re-renders only where a
cached render exists. Documents suite 95 → 103, all passing.

Full gates on the finished tree: composer check exit 0 (PHPStan zero, PHPCS
zero, smoke 27, hardening 18, PHP asset checks 30); documents 103; HTTP 53;
permalinks 96; profiles 43; design 45; assets 6 + 9; images 47; browser 76;
upgrade and migration PASS; JS syntax OK; Apache 22; nginx 26. The
intermittent theme-check failure did not recur and remains unresolved.

## Correction (2026-10-26, recorded during C1/D1)

The results paragraph above reports the HTTP smoke suite among the passing
gates ("HTTP 53"). As delivered, that claim was not established: the suite in
fact stopped at its raster-upload check, whose pre-B1 media-count expectation
B1's variant rows had invalidated, and my gate procedure counted PASS lines
instead of exit codes and completion lines. The product behavior was correct;
the stale expectation has been fixed (masters only) and the suite now runs to
completion at 53 checks, exit 0. See docs/C1_D1_REVIEW.md.
