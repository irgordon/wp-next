# Publishing release scope

The acceptance target is a coherent publishing product, not a percentage of WordPress's feature count. Keep the familiar administrative structure, procedural facades, and template naming conventions.

## Work in this release

1. Enforce PHP 8.4+, extension requirements, per-install secrets, expiring revocable sessions, CSRF verification, login throttling, read and mutation authorization, separate Page capabilities, safe errors, and security headers.
2. Add versioned schema upgrades, Unicode slugs with transactional collision handling, raw URL validation, revision snapshots and restores, draft previews, bounded routing/search/pagination, and preserved failed-save input.
3. Complete raster media upload and selection, a canonical nested block document/editor, shared administration layouts, theme selection, design tokens, homepage and navigation settings, and template fallbacks.
4. Implement a bounded outbox worker, render and transition handlers, maintenance CLI entrypoints, and JSON listener registries. Keep the SQLite single-writer and queue lease boundaries.
5. Verify with PHP 8.4, PHPStan, formatting, actual HTTP authorization and mutation tests, browser authoring checks, queue tests, and blank/archive upgrade checks. Package no installed state.

## Outside this release

WordPress binary/plugin compatibility, comments, custom taxonomy registration, custom post type frameworks, a general REST API, arbitrary embeds, email delivery/password reset infrastructure, and a plugin marketplace are not acceptance criteria. Built-in Post categories/tags and user profiles are implemented in schema 4. Backups and cron remain operator tasks with supplied commands and documentation.

## Completion evidence

Record actual commands, versions, results, limitations, and deployment assumptions in REVIEW.md. A gate passing is evidence about its configured checks, not a claim that no vulnerabilities exist. Test dependencies belong in Composer/npm development configuration, never runtime application bootstrapping.
