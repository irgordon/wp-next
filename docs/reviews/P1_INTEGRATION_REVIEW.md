# P1 Phase 1 integration review and P3 preparation

Date: 2026-10-25. Baseline: the supplied WordPress_Next_Blank_Install-6.zip. Integrated the received schema.php and assets.php as reviewed source, with the corrections below. P1_BUILD_REVIEW.md preserves the received author's build report; the independently executed evidence in this file describes this delivered package.

## Result and scope

Schema v5 and the internal asset lifecycle are integrated. The existing editor sidebar correction, profiles, classifications, routing and WAL connection enforcement are preserved. P3_THEMING_PACKET.md prepares the theming phase for a design ruling; no P3 runtime feature is added. P3 is the theming milestone in the capability assessment, not step 3 of P1 intake/delivery work.

Document intake, document download routes, general-asset backup/restore, upload UX and file blocks remain unfinished P1 work. Existing HTTP upload/library surfaces remain image-only. The asset primitives are internal application functions, not an upload API or proof that a PDF is safe. Do not enable document intake before its validator, delivery, backup and reference rules land together. No new production dependency is introduced.

## File changes and why

| Area / files | Result | Why |
| --- | --- | --- |
| wp-includes/schema.php | Schema 5 adds kind/format/visibility/state, relaxes hash uniqueness, preserves legacy media IDs/filenames and adds kind/dimension/format/nonnegative-size constraints. | Multiple logical records can share bytes; invalid image/document combinations must fail structurally. |
| wp-includes/assets.php | Serialized staging, quota registration, promotion, recovery, usage and access primitives; checked content hashes/canonical paths, private document modes and bounded storage locking. | The received sweep used stale reference lists and broad recursive deletion; concurrent writers and unrelated files needed explicit protection. |
| wp-includes/media.php | Legacy image upload shares the storage lock, records format and checks quota inside the registration transaction; dedupe sees ready images only. | Removing hash uniqueness invalidates ON CONFLICT; a document/pending match must not satisfy an image upload, and current uploads must not bypass the new quota. Full image-pipeline retrofit remains later P1 work. |
| wp-includes/operations.php | Backups hold the same storage lock while snapshotting and reading immutable image bytes. | Cleanup must not remove a file required by the backup snapshot. General document backup support is not claimed. |
| bootstrap.php, wp-config.php, bin/wp-cli.php | Load assets; configure WP_ASSETS_DIR; wire maintain and status to reconciliation and asset usage. | The supplied attachments did not include those integration files. |
| Content mutation/block validation; media, profile, theme and editor queries | Require ready image records at current image consumers and hide documents/incomplete records from current pickers. | The new schema must not turn a private document or pending record into a logo, avatar, featured image or public image selection. |
| Tests, Composer, CI | Add persistent lifecycle, migration/concurrency and HTTP isolation checks; update three schema expectations 4 to 5. | The received probe counts alone were not executable regression coverage in the package. |
| Documentation | Describe schema 5/cleanup accurately, include this inventory and prepare the P3 theming decisions. | Operators need the actual scope, upgrade procedure and remaining work. |

## Recovery and cleanup contract

All application storage writers, registration/promotion/failure cleanup and backup reads acquire one reentrant process-level flock before database write transactions. Waiting is bounded at three seconds. SQLite quota checks and record inserts share the immediate transaction. File identity is validated independently from original names; documents use protected bare-hash paths and images preserve their legacy URLs. Staging verifies the supplied digest; registration also verifies available byte count/hash.

Recovery processes pending records before deleting any files. It promotes valid bytes; when both staging and destination are absent, it removes the pending record and releases registered quota. If bytes exist but promotion cannot finish, it reports/audits a deferred recovery and retains the record and quota for retry/investigation rather than discarding potentially recoverable work. Ready missing/corrupt blobs are reported and audited without deleting their records. Pending, failed or corrupt work is not a successful user upload.

Cleanup removes only regular canonical blobs with no media-record references and an orphan age greater than 24 hours. It preserves unknown files, symlinks, referenced shared blobs and fresh orphans. Staging cleanup preserves hashes still referenced by pending records. This grace period is an integration correction to immediate unreferenced-blob removal. Registered quota counts records, including pending/shared-byte records; physical_bytes counts managed blobs and staging, not total filesystem usage or backups. Corrupt deferred registrations keep consuming quota until recovered or explicitly discarded.

The current image handler still uses rename-before-registration under the common lock. A failed registration can leave an orphan, which cleanup can later reclaim. It is not described as an atomic filesystem/database commit. XHR cancellation and browser-visible upload-state semantics belong to the later intake phase.

## Executed evidence

Native PHP 8.4.27, Playwright 1.62.1, Chromium 153.0.8010.0. Browser web security enabled; the container requires no-sandbox. Disposable fixtures only.

| Gate | Result |
| --- | --- |
| composer check | Syntax, PHPStan level 5 zero errors, configured PHPCS subset, 27 smoke + 18 hardening + 30 asset checks pass. |
| tests/assets-integration.py | 6 pass: legacy v4 image migration/reference preservation, idempotence/backups, status, four-process quota race, shared blob retention and cleanup/write serialization. |
| tests/assets-http.py | 9 pass: current image surfaces hide documents/pending metadata; crafted featured/image-block submissions reject those IDs. |
| tests/http-smoke.py | 53 pass. |
| tests/profiles-taxonomy-http.py | 43 pass. |
| tests/permalinks-http.py | 96 pass. |
| tests/upgrade-smoke.py | 4 pass. |
| tests/migration-regressions.py | 3 pass. |
| npm run check:js | Both admin scripts parse. |
| Playwright existing suite + extended authoring/layout checks | 95 pass; no JavaScript page errors. |
| Apache delivery fixture | 22 pass with AllowOverride None. |
| nginx delivery/rate fixture | 26 pass. |

Server fixtures validate documented delivery rules, not a production PHP-FPM/TLS/proxy deployment. Document upload/download and general-asset restore are not implemented and are not certified by these counts. No long soak or network filesystem result is claimed. Browser screenshots remain internal evidence and are not included in this source package.

## Upgrade and package

Back up source/configuration and external secrets; deploy source and run php bin/wp upgrade with native PHP 8.4+ before reopening traffic. The existing coordinator makes its pre-upgrade backup, migrates with foreign-key validation, records schema 5 and revokes sessions. A failed upgrade remains sealed. Schema 4 images retain IDs, URLs, hashes, dimensions, size and public/ready status. New record fields use the actual stored normalized image format.

The package contains no installed database, credentials, uploads, dependencies, logs, caches or runtime state. var contains only its baseline .htaccess and empty scaffold directories. All file bytes outside the inventory below match Build 6.

## Hash-verified inventory versus Build 6

7 added; 22 revised; 0 removed. This note is included in the added count.

### Added

- `docs/P1_BUILD_REVIEW.md`
- `docs/P1_INTEGRATION_REVIEW.md`
- `docs/P3_THEMING_PACKET.md`
- `tests/assets-http.py`
- `tests/assets-integration.py`
- `tests/assets.php`
- `wp-includes/assets.php`

### Revised

- `.github/workflows/quality.yml`
- `README.md`
- `bin/wp-cli.php`
- `composer.json`
- `docs/ARCHITECTURE.md`
- `docs/INSTALL.md`
- `docs/INVARIANTS.md`
- `docs/QUALITY.md`
- `tests/migration-regressions.py`
- `tests/profiles-taxonomy-http.py`
- `tests/upgrade-smoke.py`
- `wp-admin/media.php`
- `wp-admin/post-editor.php`
- `wp-admin/profile.php`
- `wp-admin/themes.php`
- `wp-config.php`
- `wp-includes/bootstrap.php`
- `wp-includes/class-wp-post-mutation-service.php`
- `wp-includes/content.php`
- `wp-includes/media.php`
- `wp-includes/operations.php`
- `wp-includes/schema.php`

### Removed

None.

