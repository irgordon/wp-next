# P1 Build Review — Phase 1: Schema v5 and the Asset State Machine

Implements the first step of the P1 build order in
`P1_ASSET_MODEL_PACKET.md` (Revision 3): the record model and the
staged → pending → ready intake state machine. Document intake, the
download route, backup changes, library UX and the file block are later
phases and are not in this change set.

## Files changed

- `wp-includes/schema.php` — `WP_SCHEMA_VERSION` 5. The media table gains
  `kind` (`image`/`document`), `format` (validated canonical format),
  `visibility` (`public`/`private`) and `state` (`pending`/`ready`);
  `width`/`height` become nullable; a combination CHECK requires images
  to carry dimensions and public visibility, and documents to carry NULL
  dimensions. `sha256` uniqueness is relaxed to the index
  `idx_media_sha256`, so one blob can carry several records.
  `wp_migrate_media_asset_model()` rebuilds pre-v5 media tables in the
  established pattern (foreign keys off, legacy alter table, copy,
  restore): every existing row becomes a public, ready image with its
  format derived from its filename; ids, filenames and hashes are
  preserved.
- `wp-includes/assets.php` (new) — the state machine and policy
  primitives: `wp_asset_stage()` (bytes into `WP_VAR_DIR/staging` under
  their hash), `wp_asset_register()` (one immediate transaction: atomic
  quota check against the `assets_quota_bytes` option plus the pending
  insert), `wp_asset_promote()` (rename to the content-addressed home —
  uploads tree for images, protected asset tree for documents under the
  bare hash — hash-verify, mark ready), `wp_asset_fail()` (unwind a
  pending registration, releasing quota), `wp_asset_reconcile()` (the
  recovery sweep in contractual order: reconcile pending records first,
  then age out orphaned staging files after 24 h, then remove blobs no
  record references; ready records with missing files are audited and
  reported, never silently dropped), `wp_asset_usage()` (registered vs
  physical bytes and the pending count), `wp_asset_can_access()` (the
  approved policy: public for everyone; private for the owner and
  `manage_media`), `wp_asset_is_ready()`, `wp_asset_blob_path()`.
- `wp-includes/bootstrap.php` — loads `assets.php` after `media.php`.
- `wp-config.php` — `WP_ASSETS_DIR` (environment-overridable, default
  `WP_VAR_DIR . '/assets'`).
- `bin/wp-cli.php` — `maintain` runs the reconcile sweep and reports it;
  `status` reports asset usage (registered bytes, physical bytes,
  pending count).
- `wp-includes/media.php` — the legacy image insert drops
  `ON CONFLICT(sha256) DO NOTHING`: the clause requires a unique index
  the new model deliberately removes. The handler's existing hash
  pre-check still dedupes the normal path; a lost race now yields a
  second record over the same bytes, which the v5 model permits.
- Tests (expectations only): schema version 4 → 5 in
  `tests/profiles-taxonomy-http.py`, `tests/migration-regressions.py`,
  `tests/upgrade-smoke.py`.

One deliberate behavior change follows from the packet: unreferenced
files in the uploads and asset trees are now reclaimed by
`bin/wp maintain` (the recovery sweep), where Build 6 left them for
manual operator cleanup. Referenced bytes are never removed.

## Verification (PHP 8.4.23, scratch copy of the updated tree)

- Focused state-machine probe, 21/21: fresh install records schema 5;
  the combination checks reject a document with dimensions, a private
  image and an unknown state; staging, registration (pending, quota
  consumed), promotion (blob in the protected tree under its bare hash),
  access policy (owner yes, another author no, public yes), atomic quota
  rejection, and recovery — a sweep promoted one waiting record, unwound
  a crashed one (staged bytes deleted), removed its record and released
  its quota.
- Migration probe, 6/6, against a database built by the Build 6 tree:
  upgrade records schema 5; image id, filename, hash, dimensions and
  size preserved; the migrated row is a public, ready image with format
  `jpg`; re-running the upgrade is idempotent.
- Full gates: `composer check` exit 0 (smoke 27, hardening 18, PHPStan
  and formatting clean), HTTP 53, profiles/taxonomy 43, permalinks 96,
  migration regressions 3, upgrade PASS set, browser 60, Apache 22,
  nginx 26.
