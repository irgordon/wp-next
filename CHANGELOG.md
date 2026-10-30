# Changelog

## v0.0.9.1

Updated October 29, 2026. Page-publication browser assertion synchronization. Schema remains 8.

- Wait for the design workspace's actual Published confirmation before asserting it. The child editor's Update Page button can appear before its message reaches the parent; no sleep, timeout increase or application change is required. [Verification](docs/reviews/V0_0_9_1_REVIEW.md).

## v0.0.9

Updated October 29, 2026. Repeatable local J1 deployment verification. Schema remains 8.

- Add real nginx/Apache → PHP-FPM tests for direct HTTPS and trusted TLS termination, strict certificate/hostname validation, production request boundaries and application workflows.
- Restore actual backup data, assets and the matching secret into fresh sites; run upgrade/session revocation before checking HTTPS delivery again. Include the deployment gate and retained diagnostics in CI, using setup-php's matching FPM runtime.
- Make the Apache static fixture accept the host MIME-types path and keep its runtime files local. [Verification and remaining J1 host checks](docs/reviews/V0_0_9_REVIEW.md).

## v0.0.8

Updated October 29, 2026. Page-editor save/return acknowledgement. Schema remains 8.

- Queue workspace navigation until the active editor acknowledges its current state; reconcile saved title/status/list position before returning. Ignore retired editor-document messages and preserve real unsaved-edit guards.
- Added a controlled delayed-bridge regression and unavailable-editor recovery coverage. [Verification](docs/reviews/V0_0_8_REVIEW.md).

## v0.0.7.1

### Fixed

- Refreshed the cache-version tokens for the UI assets changed in v0.0.7: the visual-builder module import now carries its current content digest, and the admin-widgets stylesheet import is versioned like its sibling imports — warm caches pick up the updated builder label and breadcrumb size after upgrade.

## v0.0.7

Updated October 29, 2026. Installer welcome copy, breadcrumb and heading polish. Schema remains 8.

### Changed

- Installer step 1 now opens with a benefit-led welcome — zero setup, secure from day one, ready in 60 seconds — and its advance button reads "Let's get started".
- The breadcrumb root crumb is labeled Home, and the visual builder's compact breadcrumb size is unified with the standard breadcrumb at 11px.
- Admin page titles, navigation labels and headings use Title Case consistently (for example Site Design, Code & Files, General Settings and Visual Builder).

## v0.0.6

Updated October 28, 2026. Import/export boundary fixes. Schema remains 8; native format remains version 1.

- Preserve supplied WXR inline images and attachment links, remap social-image overrides, recover committed imports without duplicate content, and refuse exports exceeding native format limits.
- Added 29 positive regression checks to the portability CI gate. [Verification and compatibility notes](docs/reviews/V0_0_6_REVIEW.md).

## v0.0.5

Updated October 28, 2026. Local review checkpoint. Schema remains 8.

- Recorded 1,153 passing local assertions and four unresolved import/export defects in [the review](docs/reviews/V0_0_5_REVIEW.md), with a portable manual reproduction tool. Runtime fixes remain pending.

## v0.0.4

Updated October 28, 2026. H1 public discovery and metadata. Schema remains 8.

### Added

- RSS feed at `/feed`: the latest 20 published Posts, excerpt-only, canonical link/guid, RFC-822 dates, author display name (never an email address); a feed-discovery link in every public head.
- XML sitemap at `/sitemap.xml`: home plus every published Post and Page at its canonical address, `lastmod` from the last update; the configured homepage is listed once, at the home address.
- `robots.txt` response and a site-wide "Discourage search engines" setting in General settings: while on, public pages carry a `noindex, nofollow` robots meta and `robots.txt` disallows all crawling. Feed and sitemap remain available in both states.
- Open Graph metadata on single published Posts and Pages (title, description, canonical URL, type, and image when one resolves), plus a per-entry social image override in the editor's Summary panel (ready images only; unset means the featured image is used).
- Release-test suite `tests/discovery-http.py` (`composer test:discovery`, also in CI); the nginx and Apache fixtures gain positive controls for the three discovery routes.

### Changed

- Canonical addresses and the meta description already shipped; they were audited and are now pinned by regression checks rather than rebuilt. The excerpt remains the per-entry description source.
- The discovery routes are front-controller routes in `index.php`, not new PHP entry points, so the nginx endpoint allowlist is unchanged.

Errors and deviations for this version are recorded in [the G2/H1 deviations ADR](docs/adr/G2_H1_DEVIATIONS.md).

## v0.0.3

Updated October 28, 2026. G2 portability: native export/import and the WordPress WXR importer. Schema remains 8.

### Added

- Native export/import in a versioned ZIP archive with a JSON manifest (`wpnext-portability`, format version 1): Posts, Pages, block and legacy-HTML content, categories/tags, authors, dates, slugs, metadata, featured and referenced media, with media bytes content-addressed and deduplicated. Format contract: [the portability format document](docs/PORTABILITY_FORMAT.md).
- A WordPress WXR importer on the same pipeline, with attachment bytes supplied in a separate media archive matched by file name; WordPress-private items import as drafts with the downgrade reported; non-block content is kept as sanitized legacy HTML.
- Preflight before any import writes (inventory, author mapping, duplicate/collision outcomes, missing media, unsupported elements, quota projection); import runs only after a separately-nonced confirm. Per-item results and resumable runs keyed by archive digest plus author mapping; exact duplicates are skipped and colliding slugs are suffixed.
- The administrator screen at `wp-admin/portability.php` and the `bin/wp export` / `bin/wp import` operator commands (preflight by default, `--commit` to run).
- Release-test suite `tests/portability-http.py` (`composer test:portability`, also in CI) covering the named G2 release tests individually.

### Changed

- Unmapped authors become ordinary accounts with an unusable password; source role data is never carried into the target, and authorship applies only through the explicit ID map.
- Media enters only from the supplied archive through the normal intake validators, dedupe and quota; there is no remote fetching code path. Image masters are exported without derived variants, which regenerate on import.
- The nginx allowlist in [the installation document](docs/INSTALL.md) includes `portability.php`; `composer.json` declares the `ext-zip` requirement (lock hash refreshed, no dependency changes).

Errors and deviations for this version are recorded in [the G2/H1 deviations ADR](docs/adr/G2_H1_DEVIATIONS.md).

## v0.0.2

Updated October 28, 2026. Compact administration typography, a dedicated visual page builder, media upload feedback, grouped navigation and editable code windows. Schema remains 8.

### Changed

- Added focused charcoal navigation to Site design, with section selection, retained form values, persistent native Save draft / Publish design actions and a route back to the main administration groups. Templates remains restricted to the published theme's curated choices. Patterns is deferred in a [dedicated inclusion review](docs/reviews/PATTERNS_INCLUSION_REVIEW.md).
- Aligned administration foundations and component treatments with the supplied WordPress Figma design system: system typography, neutral frames, theme/feedback colors, a 4px spacing grid, semantic radii and elevation. Compact base text remains 12px; public-theme typography retains its own scale.
- Applied the reference navigation/content-frame dimensions, card and menu treatments, form labels, input focus/error states and editor toolbar icons. Search and numeric controls use locally stored 24px WordPress assets; number stepping preserves the original native field and its bounds.
- Reduced the administration base type from 16px to 12px, with 10–11px supporting text and controls. Headings retain their hierarchy, and mobile controls retain usable hit areas. Public page typography remains controlled by the selected theme.
- Opening Visual editor now navigates to a full administration workspace for both new and existing Posts/Pages. The workspace contains a block palette, layers, an isolated page canvas, Block/Page settings, preview, save controls and an explicit return to the form editor.
- Browser-tab handoff carries unsaved content and settings between the form editor and builder without saving or publishing. Save redirects retain the selected builder view; revision checks and action-bound CSRF remain in the existing controllers.

### Added

- Added View Page beside Update Page after publication. It opens the saved public permalink in a separate tab, retaining workspace location, editor scroll, unsaved Page edits and private design settings. Drafts and failed publications do not expose the live-view action; Preview remains available separately.
- Added explicit Save Draft and Publish Page actions in the workspace visual editor. Draft saving stays private; publication saves the current Page content through the existing revision-checked controller. Published Pages show Update Page. Failures retain edits and report the actual saved status, while Page publication leaves unsaved site-design settings private.
- Added New Page to the Site design workspace. It creates an Untitled Page as a private draft and opens the embedded visual editor, retaining unsaved design settings. Pending creation disables repeat activation; failures retain the workspace and a lost acknowledgement gives recovery guidance without an automatic retry. The new or renamed Page is revealed on its actual list page.
- Added searchable, paginated Pages beside the private Site design preview. Select published, draft or archived Pages without changing the homepage; open the visual editor inside the workspace, retain unsaved design controls, and save Page content through the existing protected controller. Unsaved Page edits require a keep/discard choice before leaving. Page content and site-design publication remain separate.
- Added private live site preview beside Site design controls, using the actual theme, homepage, navigation and shared-section renderer. Unsaved changes are rendered without storing a draft or changing published options. The read-only frame supports desktop/mobile views, scroll retention, stale-response fencing, actionable errors and refresh recovery; publication remains explicit.
- Direct canvas editing for page titles, paragraphs, headings, lists, quotes, code, captions, document labels and legacy HTML. Public renderer output and published theme styles supply the preview. Canvas typing retains focus across preview refreshes.
- Drag-and-drop insertion and movement, nested columns, duplication, removal, undo/redo and keyboard save/history commands. Image/document selection uses the existing media library. Desktop/mobile previews use real 960px/390px layout viewports.
- Pure document operations with regression coverage for invalid drag locations, descendant cycles, maximum depth/block count, immutable edits and retained media references. CI runs the new document-model suite.
- A reachable raw-document correction path in the builder when unsupported input prevents saving.
- [Builder verification record](docs/reviews/V0_0_2_BUILDER_REVIEW.md), including first failures and corrections.
- A rounded drag-and-drop upload card with per-file queue rows, animated byte-transfer percentages, processing state, server-confirmed completion, cancel and retry controls. The image and document libraries refresh after a batch without discarding its results.
- Separate empty-library, no-search-results, loading and connection-error states. Library pickers offer clear-search, refresh and retry actions; submitting no files produces an explicit error.
- Capability-filtered Content, Media, Design and Settings navigation groups, with page breadcrumbs and a keyboard-accessible parent menu on narrow screens. Media links expose the supported Images and Documents libraries.
- Editable charcoal code windows with syntax colors, line numbers, active-line and caret position, wrap, copy feedback, JSON formatting and an expanded native dialog. The original textareas, form associations and validation remain in use.
- Pure upload/tokenization checks, navigation access checks and a focused browser suite, included in CI. [Verification record](docs/reviews/V0_0_2_ADMIN_WIDGETS_REVIEW.md) records actual results, initial failures and their corrections.

### Fixed During Verification

- Prevented canvas startup from handling the initial empty iframe document before its editable content exists.
- Anchored mobile drawers to the workspace grid so a wrapped toolbar cannot be covered by its own drawer.
- Synchronized the existing desktop-navigation resize assertion with the asynchronous media-query handler before checking visibility.
- Resolved the upload endpoint from the form attribute because a hidden field named action shadows the browser's form.action property.
- Kept breadcrumb hover and pointer activation from immediately closing the same parent menu.
- Prevented inherited inline-form layout from squeezing the desktop upload card, and cleared obsolete code-tool feedback when input changes.

## v0.0.1

Recorded October 26, 2026. Verification and boundary fixes against the supplied schema 8 rollup. The initial development history remains under v0.0.0.

### Fixed

- Disabling email sign-in now refuses already issued magic links at display and completion. Previously, those links still created sessions.
- Token consumption rechecks expiry and enabled-account status inside the write transaction. A stale lookup can no longer authorize completion after either state changes. Magic-link policy is checked again under the same lock, and its session is created in that transaction.
- Account-link issuance checks its hourly budget under the write lock. Eight synchronized requests previously issued eight tokens against a five-token limit; they now issue five and refuse three.
- Corrected the operator guide: maintenance automatically reclaims eligible unreferenced assets after the documented grace period. It does not remove referenced blobs or provide off-host retention.
- User deletion with content reassignment invalidates File-block renders and fences stale jobs. An author-based permalink change can no longer leave a cached document link serving a Post instead of the document.

### Added

- Six boundary regression checks, included in `composer test:accounts` and therefore the existing CI account gate. The original package fails five of them; the corrected tree passes all six.
- [Independent verification and host handoff](docs/reviews/V0_0_1_VERIFICATION.md), including individual product, environment and harness failures, evidence limits and file-change rationale.

### Decisions

- Recorded the approved G2 ZIP/manifest, preflight, ordinary unusable-password authors, skip/suffix defaults and remote-fetching-off choices.
- Recorded H1 excerpt-only RSS at 20 entries, site-wide indexing only for v1, and featured-image fallback.
- Adopted hybrid J1 verification. Local real nginx/Apache to PHP-FPM over TLS is supplementary evidence; production service identity, certificate chain, operations and pending G2/H1 coverage remain open. G2 and H1 are not implemented by this update.

## v0.0.0

Consolidated development history from the initial application recorded on October 24, 2026 through the schema 8 account build recorded on October 26, 2026. All work below belongs to v0.0.0. Dates identify recorded build activity, not separate version releases. The approved v1.0.0 scope remains a delivery target; approval does not mean every scoped feature is implemented or certified.

This file replaces the previously split current/history changelogs and their retrospectively assigned intermediate versions. Detailed rationale, evidence and corrections remain in [the review records](docs/reviews/) and [approved decisions](docs/adr/). Verification figures in those records retain their original attribution; this consolidation does not represent a new execution of application tests. [Current state and proposals](PROPOSALS.md) separates the delivered build from proposed implementation work.

### October 26, 2026

#### Added

- Image tools and media lineage in schema 7. Edited copies and derived variants have their own records, retain the source owner's attribution and do not grant additional access.
- Fixed image variants: a focal-point-driven thumbnail up to 150 × 150 pixels and aspect-preserving widths of 600 and 1200 pixels. Generation never upscales; smaller masters produce valid smaller outputs.
- Crop and rotate operations that create a new sanitized master and its variants. Existing media and references remain unchanged until an explicit replacement is applied.
- Reviewable image replacement with affected-content counts and permission checks for every target. Current featured images and image blocks may be updated; shared-section replacements become design drafts for normal publication. Avatars, the site logo and historical revisions retain their selected assets.
- Responsive `srcset` and `sizes` output for ready image variants and masters, with site-local URL validation in the sanitizer.
- Separate reporting of registered master, variant and document bytes alongside physical storage usage in the media library and CLI status.
- Visual mode in the Post/Page block editor, using the public block renderer through an authenticated, action-nonce-protected preview endpoint. Canvas selection works with mouse and keyboard; the Block settings inspector reuses existing editing fields.
- Canvas insertion, movement and removal, including nested columns; desktop/mobile preview widths; visible saved, unsaved, draft and published state.
- Block editing for shared header/footer sections, retaining design drafts and explicit publication.
- Structured menu forms with labels, addresses, nesting levels, ordering and add/remove/move controls. Order fields remain usable without JavaScript.
- Private stylesheet drafts with save, session-bound preview, explicit publication, discard and retained input on publication conflict. Design and file histories remain independently restorable.
- Approved scope and deployment requirements covering media tools, live editing, CSS drafts, account lifecycle, native/WordPress imports, publishing metadata and nginx/Apache with PHP-FPM. Remaining approved work is tracked as scope, not listed here as delivered functionality.
- Image regression coverage for variants, dimensions, focal cropping, edited copies, replacement boundaries, interrupted generation, quota release and backup restoration of related assets.
- Account email in schema 8: administrator-configured SMTP (settings or `WP_MAIL_*` environment variables) with the recorded delivery result shown to administrators, email invitations, neutral password recovery, and optional single-use sign-in links that stay off until enabled. Links are purpose-bound, hashed at rest, expiring, rate-limited, and never consumed by opening them; completion is always an explicit step. Usernames are suggested from the email local part, collisions get a numeric suffix, and the suggestion can be customized when accepting. `bin/wp user:reset-link` provides operator recovery when mail is unavailable.
- Separately confirmed user deletion: a dedicated confirmation shows the account's content counts and requires an existing enabled reassignment target. Posts and media are reassigned, never cascade-deleted; sessions and account tokens are revoked in the same transaction; a minimal audit tombstone (user id, username, reassignment target, counts) remains, without contact or profile fields.
- Account regression coverage: 60 checks against a local SMTP sink covering invitations, derivation collisions, neutral recovery, scanner-safe links, session revocation, rate limiting, disable/re-enable, deletion/reassignment, and honest delivery-failure reporting.

#### Changed

- Organized governing/operator documentation at `docs/`, decision records at `docs/adr/`, and build/review records at `docs/reviews/`.
- Consolidated all application change history into this single v0.0.0 changelog and updated the documentation index accordingly.
- Extended backups, reference checks and deletion handling to understand image families. Incomplete variant generation is recovered from its retained source/instructions or removed with quota release and an audit event.
- Reconciled the maintained status documentation (root README, documentation index, INSTALL, QUALITY, ARCHITECTURE) to the current schema 8 build, including the enabled document library and account features. Historical review records are unchanged.

#### Fixed

- Cached File links are invalidated when routing changes can transfer a `/documents/{id}` address between a document and a Post. Relevant changes include permalink settings/history, publication, creation, author/category/slug changes, trash/restore and category slug changes.
- Render-generation checks reject work prepared before a routing change. A second change also fences work for a Post whose cached render was already invalidated.
- Structured menu arrays no longer pass through a string-only form merge that made design saves fail.
- The HTTP smoke test now counts master images rather than all media records after variants were introduced.
- The documented nginx PHP allowlist now includes `wp-admin/block-preview.php`; the visual editor's preview endpoint previously returned 404 before PHP could execute. The nginx fixture asserts that allowlisted endpoints reach the configured FastCGI handler, with media.php as the positive control; the fixture has no live FPM backend, so this proves routing, not FPM success.
- CI now invokes `composer test:images` and `composer test:accounts`; both suites previously ran only locally.

#### Verification Corrections

- The original B1 report incorrectly listed the complete HTTP smoke suite as passing. It had stopped at the outdated media-count assertion; later checks had not run. The assertion was corrected, and the full 53-check suite subsequently completed with exit 0. The correction remains recorded in the B1 and C1/D1 review documents.
- Preserve individual tool results, initial failures, causes and reruns. A successful rerun does not erase an earlier tool, environment or harness failure.

### October 25, 2026

#### Added

- Asset lifecycle foundation in schema 5: stage bytes, register a pending record, promote verified bytes and mark the record ready.
- Protected, content-addressed document storage. Private access is restricted to the owner and media managers; inaccessible document requests receive a uniform 404.
- Shared reentrant storage locking for storage writers and backup reads, atomic registration quota checks, deferred recovery, and cleanup restricted to canonical unreferenced blobs after a 24-hour grace period. Unknown files and symlinks are preserved during cleanup.
- Document intake for PDF, TXT, Markdown and CSV up to 25 MiB. Canonical format comes from the extension; detected MIME, PDF signature and UTF-8/no-NUL checks provide the accepted validation evidence.
- Document library with access-filtered listing/search/picker results, visibility controls, reference-guarded removal and explicit unscanned-download labels.
- Routed document delivery with per-request authorization, `no-store`, `nosniff` and attachment disposition for successful downloads. Missing, incomplete and inaccessible documents share the refusal contract.
- File blocks with document-access checks at save and again inside insert/update transactions. Publication requires public documents; rendering omits inaccessible File blocks while retaining surrounding content.
- Per-file XHR upload results and a multiple-file classic form fallback. A rejected file does not roll back files that succeeded.
- Backups containing hash-verified ready documents from protected storage. Missing, corrupt or invalid ready paths fail loudly and identify the asset.
- Explicit incomplete-backup semantics: pending registrations are identified in the manifest and excluded from recoverable-file counts. Restore can lose bytes from an upload registered before promotion; maintenance removes unrecoverable records, releases quota and audits the loss.
- Theme manifests, renderer contracts and standard/wide/landing Page templates in schema 6.
- Versioned design drafts, session-bound private previews, explicit publication, immutable published history and rollback. A separate trusted-theme health process renders against a private database snapshot before publication, followed by version, authority and source-fingerprint rechecks.
- Shared header/footer sections and nested header/footer menus, bounded to 40 entries and three levels, with the legacy Page-navigation fallback retained.
- Administrator-managed `custom.css` and `site-notice.txt`, stored as versioned logical files rather than deployed source. Fixed types, a documented CSS subset, expected-version checks, rollback and `no-store` delivery protect the editing contract.

#### Changed

- Image intake uses the serialized asset pipeline; dedupe and image consumers require ready image records.
- File-backed SQLite connections request and verify WAL. The explicit 3000 ms busy timeout is applied before requesting WAL, making that request follow the application's policy rather than the PDO driver's prior timeout. The order itself is not claimed to be what makes backups capture WAL commits.
- Theme publication explicitly requires tokenizer and a reachable PHP CLI through `proc_open`; `WP_PHP_CLI` selects the executable when needed.
- New permalink structures cannot reserve the literal `documents` application prefix. Compatible historical structures retain resolution, with document links using the query fallback where the pretty route belongs to a Post.
- Document and image quotas account for registered bytes; physical storage, staging and retained unreferenced bytes are separate quantities.

#### Fixed

- Editor sidebar placement on long content and the layout impact of draft-recovery notices. Summary and revision history sit with publishing controls while restore forms retain valid form ownership.
- Recovery retains a pending record and its quota when promotion is temporarily blocked, then retries when storage becomes available. It unwinds only unrecoverable bytes-absent registrations.
- Image reuse cannot be satisfied by a document record over the same bytes.
- Document picker parameters consistently select the Documents branch.
- Array upload field names preserve multiple selected files without JavaScript; both form and XHR paths use the correct shape.
- JSON Content-Type is emitted as a valid single-line header; regression tests check response headers as well as bodies.
- Making a File reference inaccessible no longer substitutes an error for the entire Post body.
- Visibility changes invalidate dependent renders and fence stale worker results in the same transaction.
- Document routing recognizes Post ownership under historical and dynamic permalink structures instead of relying only on a literal current prefix.
- Draft and published writes recheck document access for the acting author under the write lock, closing the preflight-to-commit reference race.
- Document dedupe verifies canonical stored bytes before reporting success. Reupload can repair missing bytes through the lifecycle; corrupt content-addressed bytes produce an explicit integrity refusal.
- Backup and document delivery share canonical document-path validation, including symlinked root, prefix and final-file refusal.
- Deployment upload allowances match the 25 MiB per-file document ceiling: PHP 26M per file/32M POST and nginx 32m, with larger total allowances needed for large classic-form batches.

### October 24, 2026

#### Added

- Initial PHP 8.4+ and SQLite publishing application: Posts, Pages, block content, revisions, raster media, search, a blog front end and web installation.
- Transactional schema upgrades, initially progressing through schema 2; Studio theme; a durable outbox/job queue, bounded worker and `bin/wp` operator commands.
- Per-install random authentication secret, opaque hashed sessions, expiry and server-side revocation. The runtime directory is configurable and defaults to in-tree `var/`; an external private directory is recommended rather than assumed.
- WordPress.org-inspired administration styling using the supplied Blueberry/charcoal palette, local Inter and EB Garamond fonts, shared styles and collapsible mobile navigation.
- Configurable permalink presets and custom structures, retained structure history, canonical redirects and a post-name default. These replace the earlier validated month/day alias-only change; Pages retain their separate routing rules.
- Image decoding/re-encoding to strip metadata, preserve supported orientation and reject unsupported or incomplete raster inputs. Published image content uses local uploads.
- Content Trash in schema 3, restore to the prior status and separately confirmed permanent deletion from Trash, with in-transaction permissions and dependent revision/job handling.
- Sortable content lists, All/Trash counts, touch/hover actions, Quick Edit with conflict checks and inline deletion confirmation.
- Stepped installation wizard and a full-height administration sidebar with accessible profile/logout controls.
- User profiles in schema 4: display names, biographies, websites, local avatars and opt-in public author archives that exclude private account data.
- Hierarchical categories, flat tags, public archives and category-based permalinks. Assignments participate in revisions and draft recovery.
- Locked development checks for PHP syntax, PHPStan level 5, the configured PHPCS subset, actual HTTP workflows, browser authoring and server rules, with CI configuration.

#### Fixed

- Initial authorization and persistence boundaries, including draft isolation, separate Page capabilities, CSRF enforcement, session revocation, transactional slug collisions, real 404 behavior and durable queued work.
- Migration completion, deleted-ID reuse and Trash revision handling.

#### Security

- Enforced the PHP version floor; action-bound CSRF on mutations; consistent security headers and safe error handling.
- Added an atomic per-IP login budget reserved before expensive password verification, alongside account/IP controls.
- Added virtual-host protections that do not depend on Apache honoring `.htaccess`, static upload headers, nginx login rate limiting, strict URL handling and content sanitization.

### Review Follow-Through

- Recorded as corrected in the subsequent build: the nginx allowlist omission, stale status documentation, and missing CI suites. Details remain in the October 26 Fixed and Changed entries.
- Recorded as delivered: SMTP, invitations, password recovery, optional sign-in links, and user deletion/reassignment (E1/F1). Details remain in the October 26 Added entries.

### Open Findings And Verification Limits

The following remain open in the supplied package. Proposed work is not a delivered change.
- An intermittent theme-settings HTTP check failed once in an earlier run. Later passes have not established its cause; the failure remains unresolved.
- Static Apache/nginx fixture passes and PHP development-server/browser passes do not certify the required PHP-FPM, TLS and production proxy integration under J1.
- Publishing metadata (H1) is delivered in v0.0.4 above. Native and WordPress import/export (G2) is delivered in v0.0.3 above.
