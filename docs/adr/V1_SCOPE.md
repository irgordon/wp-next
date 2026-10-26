# Approved v1.0.0 Scope

[Scope Index](../README.md) · [Deployment Requirements](../V1_DEPLOYMENT_REQUIREMENTS.md)

Decision date: October 26, 2026. Status: approved implementation scope. Approval establishes requirements; it does not assert that the features below have already shipped or passed verification.

Reference source: WordPress_Next_P1_Documents(3).zip, SHA-256 82e4621ece92a4b9f80ab59c42a3231bdf0893f4253f5286286202e008cf1c10. It contains 147 files and schema 6. The latest cache-invalidation changes require independent verification.

## Product Intent

WordPress Next is a spiritual successor to WordPress: a familiar publishing experience with a smaller, deliberate architecture. v1.0.0 must be usable by authors and operable by maintainers. Importability is a release requirement, not a post-release aspiration.

Preserve PHP 8.4+, SQLite with WAL, the procedural facades, supported block data, curated trusted themes, explicit publication, revision history and the approved media access policy. Preserve the supplied WordPress.org visual direction, including Inter, EB Garamond and the supplied palette. Native WordPress plugin/theme execution compatibility is outside this release.

## Approved Decisions

| ID | Approved Choice | Required Outcome |
| --- | --- | --- |
| A2 | Pretty document URLs with automatic query fallback | Documents and Posts resolve to the correct resource across current/historical/dynamic permalink changes; cached links and in-flight renders remain correct. |
| B1 | Bounded image editing and responsive variants | Crop, rotate, focal point and appropriate display sizes, with a sanitized master and immutable derived bytes. |
| C1 | Curated editing with Gutenberg-style live interaction | Posts, Pages and supported shared sections have live visual block editing, immediate styling feedback and a usable inspector. |
| D1 | Managed CSS draft preview | Inspect saved/in-progress styles privately before explicit publication; retain file history and rollback. |
| E1 | SMTP, invitations and password recovery, with optional magic links | Offer password and optional email-link sign-in; generate a customizable username from email. |
| F1 | Disable plus separately confirmed user deletion | Reassign content/media, revoke sessions and preserve a defined audit tombstone. |
| G2 | Native portability plus WordPress import | Native round trips and a documented WordPress WXR importer ship in v1. |
| H1 | Publishing metadata essentials | RSS, sitemap, canonical links, indexing controls, descriptions and social images. |
| I1 | Defer public API and webhooks | Browser, CLI, feeds and import/export remain the supported initial interfaces. |
| J1 | nginx and Apache with PHP-FPM | Certify actual application workflows through both documented server stacks. |

## A2: Document Addresses And Render Consistency

Keep /documents/{id} where it is unambiguous; use /?document={id} when a Post owns the pretty path. Check actual resource ownership across current structures, retained history and dynamic tag values. The clicked resource, not just the HTTP status, is the acceptance criterion.

Routing changes must invalidate every affected cached File reference and fence stale worker results. This includes permalink/history changes, Post publication and creation, slug/author/category changes, trash/restore, and relevant taxonomy changes. Test two changes before a worker commit, including a render that was already invalidated. Preserve unrelated body content when a document becomes inaccessible. Download authorization must still be evaluated on every request.

## B1: Image Tools

Support only the existing raster format set for this release. Retain a sanitized master; derived files are new immutable bytes. Editing must preserve existing media IDs/references unless the user explicitly applies a replacement. A replacement is a new asset by default, not an invisible rewrite of every published use.

Image dimensions, memory, format and quota limits apply to transformations and generated variants. Explain registered versus physical storage usage. Backups, recovery and reference checks must understand variant lineage. Handle interrupted processing without reporting an incomplete variant as usable.

## C1: Live Visual Editing

Live editing means an author edits the visible content canvas, selects a block, changes its content/style through contextual controls and an inspector, and sees the result immediately. Support keyboard-accessible insert, move and remove operations, nested supported layouts, desktop/mobile preview and honest saved/unsaved/published state.

Use the same supported block representation and rendering rules across the canvas, preview and public delivery. Reuse the block editor for shared sections. Provide form-based menu items and accessible ordering controls instead of requiring line syntax. Keep explicit design publication and private preview. The existing curated template scope remains in force.

This is Gutenberg-style interaction, not a promise of third-party Gutenberg block/plugin compatibility. Import handling below defines treatment of existing WordPress content.

## D1: CSS Draft Preview

Retain the two approved logical files and the documented CSS subset. Add private session-bound draft preview for the stylesheet, with explicit publication, input preservation on conflict and additive restore history. Preview must not alter anonymous output. Design and managed-file histories remain independently restorable, with that distinction visible in the UI.

## E1: Accounts, Email And Sign-In

Provide administrator-configured SMTP, invitations and password recovery. Provide optional magic-link sign-in alongside username/email plus password. Make enabled sign-in methods clear in site settings and the login form. A mail delivery failure must not be represented as a successful delivery; public account responses must not reveal whether an account exists.

Derive a suggested username from the email's local part, normalize it, resolve collisions and let the user or administrator customize it. Do not expose the full email address as a public username by default. An email change must not silently rename an account. Explicit username customization must observe uniqueness and applicable author-route rules.

Email possession must be verified for activation or email-link sign-in. Tokens must be purpose-bound, stored as hashes, single-use, expire and be rate-limited. Link-scanner GET requests must not silently consume a sign-in token before an intentional completion step. Define neutral public responses and useful private administrative diagnostics. Password reset and account disable/delete revoke applicable sessions atomically. Keep an operator recovery procedure for unavailable mail.

## F1: User Deletion

Keep Disable as the reversible action. Separately confirmed deletion requires an existing enabled reassignment target and a preview of affected content/media counts. Do not cascade-delete authored content or asset bytes. Prevent self-deletion and deletion of the final enabled administrator. Check permissions, target state and dependencies inside the transaction; revoke all deleted-user sessions in that transaction.

Retain a minimal audit tombstone with stable identity and the deletion/reassignment event. Do not retain unnecessary profile/contact fields merely to label audit rows. Backup retention is separate from live account deletion and must be explained.

## G2: Import And Export

Native export/import must support Posts, Pages, supported block/legacy HTML content, categories/tags, author mapping, publication dates, slugs, supported metadata and referenced media. Version the format and document its compatibility rules. Preserve relationships through explicit ID mapping rather than trusting source database IDs.

WordPress WXR import must cover Posts/Pages, statuses, dates, slugs, authors, categories/tags, excerpts and attachment references. Core content that cannot become an editable native block may use sanitized legacy HTML when that representation exists. Report unsupported custom blocks, shortcodes, plugin data and missing media; do not silently discard them or claim they migrated successfully. Do not execute imported PHP, shortcodes or plugins.

Provide a preflight report, user/author mapping, explicit duplicate/collision choices, progress and a final per-item result. Permit supplied media archives so migration does not require network access; controlled attachment fetching may resolve WXR URLs. Remote fetching must enforce destination validation, redirect checks, byte/time limits and the normal media validators. Import must not create privileged accounts from source role data. Failed or interrupted work must have a defined retry/resume path without duplicating completed items.

Native round-trip, a representative WordPress fixture, missing/unsupported content, hostile archive/XML inputs, quota exhaustion, slug collisions and interrupted retry are release tests. The user must know what will migrate before committing an import and what did not migrate afterwards.

## H1: Public Discovery And Metadata

Provide RSS, XML sitemap, canonical addresses, indexing controls and optional per-entry description/social image. Include only eligible published public content. Drafts, previews, private documents and account contact data must not enter feeds, sitemaps or social metadata. Preserve ordinary body content and correct escaping in metadata outputs.

## I1: Integration Scope

Do not add a general public REST API or webhook subsystem to v1.0.0. Keep the supported browser/CLI workflows and approved RSS/import/export interfaces documented. Internal browser endpoints are not a public compatibility promise.

## J1: Deployment And Release Evidence

Use [Deployment Requirements](../V1_DEPLOYMENT_REQUIREMENTS.md) for the operating contract. Verify real nginx-to-PHP-FPM and Apache-to-PHP-FPM requests, not just static denial fixtures. Exercise both direct HTTPS and the documented trusted-termination behavior where supported.

## Fixed Release Gates

- Resolve confirmed P1 failures; do not treat a green happy-path suite as closure of a failed boundary contract.
- Report PHP syntax, PHPStan, PHPCS, PHP suites, JavaScript syntax, browser tests, each server stack and each independent probe individually. Retain first results, causes, changes and reruns. Separate product, environment and harness failures.
- Preserve the previously unexplained intermittent theme-check failure as unresolved until evidence establishes a cause; retain response and process diagnostics if it recurs.
- Rehearse blank installation, supported-version upgrade and actual restoration with images, public/private documents, derived assets, design revisions and managed files.
- Reconcile stale documents that still claim document intake is disabled. Maintain one current feature-status index.
- Keep source packages free of databases, credentials, uploads, installed dependencies and runtime state.
- Use Title Case page headings and clear navigation. User pages explain tasks in plain language. Maintainer pages explain intent, architecture and why boundaries exist.

## Implementation Order

1. Verify the latest P1 document/routing corrections and establish the release evidence baseline.
2. Implement B1 media transformations and lineage, then integrate them with backup, portability and authoring.
3. Implement C1/D1 live editing and private preview together so shared content/design behavior stays consistent.
4. Implement E1 account delivery/authentication and F1 deletion/reassignment.
5. Implement G2 native portability and WordPress import against those settled media/account contracts.
6. Complete H1 metadata, documentation reconciliation and J1 deployment certification. I1 remains deferred.

This ordering is an execution dependency, not an additional approval queue. Routine implementation choices within the approved contract may proceed. Material changes to scope or semantics must be called out explicitly.
