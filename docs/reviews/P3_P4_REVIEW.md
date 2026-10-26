# P3 closure and first P4 slice: implementation review

> **Status note (2026-10-26):** the statement below that document intake/delivery remains disabled is superseded. Documents shipped in the P1 document library (see `docs/P1_DOCUMENTS_REVIEW.md`) and are carried into the v1.0.0 scope (see `docs/V1_SCOPE.md`). This document is retained as the delivery record for its milestone.


Date: 2026-10-25. Comparison baseline: the adopted 132-file WordPress_Next_P1_Phase1.zip, not Build 6 or another external tree. The user authorized closing P3 and selected controlled file editing as the first P4 area.

## Delivered scope

P3's curated composition scope is implemented: trusted deployed theme manifests, standard/wide/landing Pages, versioned design drafts, private preview, explicit publication, history/rollback, shared top/bottom content sections, nested header/footer navigation and broader typed typography/spacing. The first P4 slice implements approved public CSS/text files with independent history and rollback. The remaining P4 account/integration work is not implemented.

Schema 6 adds design_revisions and managed_file_versions. Existing theme settings, media IDs/URLs, Posts/Pages and classification data remain in place. Page template assignments use existing content metadata and revision snapshots. P1 document intake/download remains disabled; this release does not bypass its outstanding validators, delivery, reference and backup work.

## What changed and why

| Files / subsystem | Change | Why |
| --- | --- | --- |
| schema.php | Add schema 6 design and managed-file revision tables. | Drafts and previous published states must survive reloads and be included in existing snapshot backups. |
| design.php, theme.php, bootstrap.php, index.php and bundled manifests/templates | Validate renderer contract 1, local template IDs/files, required tokens and PHP syntax; render approved Page templates; use fallback behavior. | Keep template selection within trusted deployed source and prevent supplied paths from selecting PHP. |
| design.php, tools/theme-health.php | Validate a candidate with an isolated PHP process and private database snapshot before publication; recheck live version, authority and source fingerprint in the write transaction. | A failed candidate must leave live design unchanged; probe database writes must not reach live state. |
| themes.php | Save/reopen/discard drafts, session-bound preview links, publish, identify the live revision and restore history. | Authors need explicit separation between editing a design and changing the public site. |
| Page controllers/editor and mutation service | Store approved template IDs in metadata; validate direct fields and metadata input, including rechecks before save. | Template selection must follow the same permissions, conflict and revision paths as content. |
| Theme headers/footers and shared site CSS | Render explicitly published shared sections and nested menus; retain legacy flat navigation as fallback. | Site composition needs reusable sections and more than one menu location without a new general visual template editor. |
| media.php | Protect logo/shared-section images referenced by design drafts or history. | New reference paths must not allow removal of images required for preview or rollback. |
| managed-files.php and file-editor.php | Edit only custom.css/site-notice.txt, using fixed types, validation, optimistic version checks and additive rollback. | Provide useful file editing without giving request paths access to theme PHP, configuration, uploads or the filesystem. |
| Composer/CI/tests/install docs | Add tokenizer requirement, design suite, new PHP entrypoint allowlist and PHP CLI health instructions. | Deployment requirements and executable verification must match the delivered code. |

## Publication and recovery contract

Design drafts are immutable records tied to an actor and a base live version. Reopening a draft retains that version; a stale edit must be reworked against the live configuration rather than silently overwrite it. Discard only removes an owned unpublished draft. Publishing consumes the draft, retains initial live settings for rollback, appends a new published revision and advances the live pointer/version atomically. Restoring history appends another published state. The current revision is identified in the UI.

Preview access requires the saved draft's administrator session and action-bound nonce. Overrides are request-local; preview responses use private/no-store and noindex. They do not publish configuration. Shared sections use existing supported HTML or version-1 block documents; header/footer menus use Label | address | level lines, at most 40 entries per location and three levels. Saving/publishing validates colors, contrast, typography/spacing bounds, Pages, images, links and template identifiers. Reference colors are retained where readable; body/muted/button text receives a readable black/white fallback when needed.

The health probe renders each registered Page template in a separate PHP process with a 128 MB limit, bounded output and a five-second process deadline. It uses a private VACUUM INTO database snapshot and cleans private probe state after normal completion/failure. The snapshot probe cannot update the live database. It is not an OS sandbox for hostile PHP; theme source remains trusted operator code. It is also not proof of every runtime branch. Source fingerprints reject a deployment change detected during publication. Invalid active manifests fall back to the default theme; runtime template errors have a default fallback, with a minimal content fallback if the default manifest itself is invalid.

Shared sections affect current site composition on explicit design publication; they do not rewrite historical Post/Page content. Template assignment snapshots remain part of content revisions. Document uploads are still unavailable. Managed-file publication is independent of design publication: design rollback does not roll back custom.css; use File history for that.

## Controlled P4 editor contract

The first P4 slice manages public custom.css and site-notice.txt as database-backed logical files, not deployed source files. Each version is at most 128 KB of valid UTF-8 without NULs. Only administrators may publish/restore, with POST/CSRF and expected-version checks. Supported CSS is a documented subset of public selectors and simple declarations; imports, resource URLs, escapes, HTML/scripts and arbitrary selectors are rejected. GET delivery uses fixed MIME, nosniff and no-store. The editor never receives an arbitrary filesystem path. See P4_FILE_EDITING_SCOPE.md for the exact scope and remaining P4 areas.

## Executed evidence

Native PHP 8.4.27; PHPStan 2.3.2 at level 5 with PHP target 8.4; Playwright 1.62.1 and Chromium 153.0.8010.0. Browser web security remained enabled; this container requires no-sandbox. Fixtures use disposable local sites/accounts.

| Gate | Result |
| --- | --- |
| composer check | Syntax, PHPStan zero errors, configured PHPCS subset, 27 smoke + 18 hardening + 30 asset checks pass. |
| composer test:design | 45 HTTP checks pass: schema 5 upgrade, draft isolation/reopening/discard, signed preview, explicit publication and rollback, stale writes, a real two-process publication race, unsafe menus/templates, health failure and snapshot isolation, shared-image protection, Page template rendering, managed CSS/text validation, version conflicts, rollback and backup history. |
| tests/browser.cjs | 73 pass, including the retained 60 checks plus design draft/preview/publication/reopening/rollback, Page templates, visible CSS publication/rollback and mobile file editing. No JavaScript page errors. |
| Existing HTTP workflows | 53 pass; the theme-setting check now uses explicit Publish design. |
| Profile/taxonomy HTTP | 43 pass. |
| Permalink HTTP | 96 pass. |
| Asset integration / asset HTTP | 6 + 9 pass. |
| Upgrade / migration regressions | 4 + 3 pass. |
| Apache / nginx fixtures | 22 + 26 pass. |
| npm run check:js | Both admin scripts parse. |

Desktop design draft/preview, wide Page and file editor screens and the 390px file editor were captured and inspected. The retained suite also checks admin layouts at 320/390px. Screenshots remain internal QA evidence and are not in the source package. Delivery fixtures do not certify production PHP-FPM/TLS/proxy integration; no remote CI, long soak or network filesystem result is claimed.

## Operator requirements

Back up source/configuration and external secrets, deploy, then run php bin/wp upgrade to apply schema 6 before reopening traffic. Existing sessions are revoked by the established upgrade policy; failed upgrades stay sealed for recovery. The existing snapshot backup includes design and managed-file history. Keep source/theme packages and operator secrets backed up separately.

Tokenizer is now an explicit runtime extension requirement. Theme publication requires proc_open and PHP 8.4+ CLI; set WP_PHP_CLI to the CLI binary if it is not on the service PATH. The updated nginx execution allowlist admits wp-admin/file-editor.php; managed-file delivery uses the front controller. Existing runtime storage and backup permissions remain required. See INSTALL.md.

## Hash-verified inventory

13 added; 34 revised; 0 removed versus the adopted Phase 1 archive. All other file bytes match. This review is counted as added.

### Added

- `docs/P3_P4_REVIEW.md`
- `docs/P4_FILE_EDITING_SCOPE.md`
- `tests/design-files-http.py`
- `tools/theme-health.php`
- `wp-admin/file-editor.php`
- `wp-content/themes/default/page-landing.php`
- `wp-content/themes/default/page-wide.php`
- `wp-content/themes/default/theme.json`
- `wp-content/themes/studio/page-landing.php`
- `wp-content/themes/studio/page-wide.php`
- `wp-content/themes/studio/theme.json`
- `wp-includes/design.php`
- `wp-includes/managed-files.php`

### Revised

- `.github/workflows/quality.yml`
- `README.md`
- `composer.json`
- `composer.lock`
- `docs/ARCHITECTURE.md`
- `docs/INSTALL.md`
- `docs/INVARIANTS.md`
- `docs/P3_THEMING_PACKET.md`
- `docs/QUALITY.md`
- `index.php`
- `tests/assets-integration.py`
- `tests/assets.php`
- `tests/browser.cjs`
- `tests/http-smoke.py`
- `tests/migration-regressions.py`
- `tests/profiles-taxonomy-http.py`
- `tests/upgrade-smoke.py`
- `wp-admin/class-wp-installer.php`
- `wp-admin/post-editor.php`
- `wp-admin/post-new.php`
- `wp-admin/post.php`
- `wp-admin/themes.php`
- `wp-content/assets/site.css`
- `wp-content/themes/default/footer.php`
- `wp-content/themes/default/header.php`
- `wp-content/themes/studio/footer.php`
- `wp-content/themes/studio/header.php`
- `wp-includes/bootstrap.php`
- `wp-includes/class-wp-post-mutation-service.php`
- `wp-includes/content.php`
- `wp-includes/media.php`
- `wp-includes/permalinks.php`
- `wp-includes/schema.php`
- `wp-includes/theme.php`

### Removed

None.

