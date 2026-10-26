# Publishing release review and verification

This build delivers the focused administration/content/design scope in RELEASE_PLAN.md. It does not attempt to reproduce the WordPress feature catalogue or claim WordPress plugin/theme compatibility. All runtime checks used native PHP 8.4.27, SQLite 3.45.1, and libxml 2.9.14 without transaction shims or mbstring. The packaged PHP floor is 8.4, enforced by configuration, installation, and Composer.

## Core changes and revisions

| Area | Core change | Files added | Files revised |
| --- | --- | --- | --- |
| Runtime and upgrades | Enforced runtime/extension checks, schema version 2, transactional upgrade, unique secret generation | wp-includes/schema.php | wp-config.php, bootstrap.php, class-wp-sqlite-db.php, class-wp-installer.php, install.php |
| Authentication and request security | CSRF on modifying forms/login/logout; opaque hashed, expiring/revocable sessions; throttled login; production HTTPS and headers; safe redirects | wp-includes/security.php; wp-admin/profile.php, users.php | user.php, formatting.php, login.php, class-wp-authorization-service.php |
| Posts and Pages | Separate capabilities and read gates; Unicode unique slugs; retained failed input; snapshots/restore; excerpt, featured image, private preview | wp-includes/content.php; wp-admin/post-editor.php | class-wp-post-mutation-service.php, post-new.php, post.php, edit.php |
| Media | Inspected raster upload, immutable hashed files, searchable/paged library and picker, alt/caption metadata, reference-aware removal | wp-includes/media.php; wp-admin/media.php | page-builder.js, post-editor.php |
| Administration | Shared responsive shell; Overview, People, Profile, Activity; role-filtered navigation and accessible forms | admin-functions.php, css/admin.css, index.php, activity.php under wp-admin | admin.php, admin-header.php, admin-footer.php, options-general.php, content screens |
| Authoring | Nested versioned JSON, nine block types, inline formatting, raw/visual synchronization, browser draft recovery, full-library featured selection | Canonical content module above | wp-admin/js/page-builder.js, shared editor |
| Themes and public delivery | Two themes, template hierarchy, logo/design tokens, homepage/ordered navigation, pretty routes, search/pagination, real 404s | wp-admin/themes.php; default single/archive/search/404/functions; complete studio theme | wp-includes/theme.php, index.php, default theme templates/styles |
| Jobs and operations | Real render/transition handlers, fenced atomic job completion, operator worker, backup/maintenance/session commands, JSON registry | class-wp-job-worker.php, operations.php, bin/wp, bin/wp-cli.php, wp-cron.php | class-wp-job-queue.php, class-wp-admission-gate.php, class-wp-plugin-activator.php |
| Deployment and quality | Private-path denials and routing; PHPStan/formatting gates; browser and upgrade regression suites; current operational docs | package.json, package-lock.json, tests/browser.cjs, tests/upgrade-smoke.py, tools/router.php, docs/RELEASE_PLAN.md | .htaccess, Composer/configuration, CI workflow, existing tests, README, docs |
| Removed source files | None. Existing entrypoints and procedural facades remain present. | — | Obsolete stateless-session behavior, regex builder parser, shared salt fallback, and executable cache loading were replaced in their existing files. |

The detailed inventory below compares this release with the previous reviewed blank source build. Some foundational files changed only because the declared formatting subset was applied; a modified file is not necessarily a separate functional fix.

## Executed checks

| Check | Result |
| --- | --- |
| PHP syntax for project PHP and CLI launcher | Pass |
| PHPStan 2.3.2, level 5, PHP target 8.4 | Pass, zero findings |
| PHP_CodeSniffer 3.13.6 / WPCS 3.4.1 declared subset | Pass, zero errors/warnings |
| Native PHP domain/installation/sanitizer/content/queue checks | 26 passed |
| Real HTTP workflows and security boundaries | 53 passed |
| Chromium 153 headless / Playwright 1.62.1 authoring checks | 19 passed |
| Prior schema upgrade, repeatability, failed-migration seal, corrected retry | 4 passed |
| Missing-DOM installation rejection and escaped output | 2 passed |
| Nginx 1.24 documented static delivery rules | 19 passed |
| JavaScript syntax | Pass |
| Intentional wrong-type and bad-format negative controls | Both rejected |
| Composer manifest and lock validation | Pass; advisory notice about unspecified application license |

The HTTP suite checks unauthorized mutation refusal, draft read isolation, Page/Post capability separation, session hash storage, logout replay, server expiry, profile password/session changes, rate-limit HTTP 429, raw URL validation, slug routing, preserved conflicts, history restore, upload spoof refusal, actual raster delivery, site design, current rendering, and queue draining. It reads the backup archive into a restored SQLite file and verifies corresponding image bytes and the private key.

Browser checks cover installation/login, shared shell, inline bold, block reordering with focus, nested columns, media upload/metadata, block image and featured-image selection, public rendering, raw/visual round trips, unsupported block retention, theme/static-homepage selection, 390-pixel mobile layouts, and browser-tab draft recovery. Desktop and mobile screenshots were visually inspected; test image pixels are fixtures, not product content.

Upgrade checks create a prior schema with existing users and content, verify preservation plus automatic backup and session revocation, repeat the command, deliberately reject a migration to confirm sealed admission, and correct/retry it. Missing-DOM checks use a separate minimal PHP 8.4.27 binary. None of the runtime/browser binaries are distributed.

The source quality gate initially exposed a media-template syntax/whitespace regression introduced while formatting; it was corrected and both HTTP and browser workflows were rerun successfully. Passing formatter/static checks alone was not treated as runtime evidence.

## Distribution and deployment boundaries

The rebuilt ZIP is a blank source distribution. Only runtime directory scaffolding and var/.htaccess remain; no installed database/WAL/SHM, users, secrets, auth.key, sessions, uploaded files, maintenance flag, executable or JSON registry, generated caches, logs, screenshots, vendor packages, or Node modules are included. Composer/npm lockfiles and test sources are included for reproducibility. No legacy PHP-version target remains in application code or documentation.

The Nginx check uses static fixtures and a public stylesheet control. It does not test FastCGI, TLS, reverse-proxy trust, filesystem suitability, or load. Apache HTTP enforcement has not been verified here. The supplied GitHub Actions workflow has not been executed remotely. Screen-reader usability and a comprehensive accessibility/security audit were not performed. These remain deployment/release assurance work; the package is not a production certification.

Uploaded images are public. Removing unused library metadata does not erase old immutable bytes. Backups include registered images and a matching file-based key; external secrets, source/configuration, old unregistered assets, off-host retention, and restore operations remain the operator's responsibility. Cron must be configured for materialization/housekeeping. Schema upgrades require the native PHP CLI and Phar. Transition handlers record activity; they do not send external webhooks.

Comments, taxonomies, public registration, email delivery/password-reset infrastructure, general REST APIs, plugin marketplaces, arbitrary embeds, and WordPress binary compatibility remain deliberately outside scope. Localization helpers remain English passthrough shims. See INSTALL.md for HTTPS, ownership, backup/restore, maintenance recovery, and cron instructions.

## File inventory

39 files added, 53 revised, 0 removed, and 2 unchanged.

| Status | File |
| --- | --- |
| Added | `bin/wp` |
| Added | `bin/wp-cli.php` |
| Added | `docs/RELEASE_PLAN.md` |
| Added | `package-lock.json` |
| Added | `package.json` |
| Added | `tests/browser.cjs` |
| Added | `tests/upgrade-smoke.py` |
| Added | `tools/router.php` |
| Added | `wp-admin/activity.php` |
| Added | `wp-admin/admin-functions.php` |
| Added | `wp-admin/css/admin.css` |
| Added | `wp-admin/index.php` |
| Added | `wp-admin/media.php` |
| Added | `wp-admin/post-editor.php` |
| Added | `wp-admin/profile.php` |
| Added | `wp-admin/themes.php` |
| Added | `wp-admin/users.php` |
| Added | `wp-content/themes/default/404.php` |
| Added | `wp-content/themes/default/archive.php` |
| Added | `wp-content/themes/default/functions.php` |
| Added | `wp-content/themes/default/search.php` |
| Added | `wp-content/themes/default/single.php` |
| Added | `wp-content/themes/studio/404.php` |
| Added | `wp-content/themes/studio/archive.php` |
| Added | `wp-content/themes/studio/footer.php` |
| Added | `wp-content/themes/studio/functions.php` |
| Added | `wp-content/themes/studio/header.php` |
| Added | `wp-content/themes/studio/index.php` |
| Added | `wp-content/themes/studio/page.php` |
| Added | `wp-content/themes/studio/search.php` |
| Added | `wp-content/themes/studio/single.php` |
| Added | `wp-content/themes/studio/style.css` |
| Added | `wp-cron.php` |
| Added | `wp-includes/class-wp-job-worker.php` |
| Added | `wp-includes/content.php` |
| Added | `wp-includes/media.php` |
| Added | `wp-includes/operations.php` |
| Added | `wp-includes/schema.php` |
| Added | `wp-includes/security.php` |
| Revised | `.github/workflows/quality.yml` |
| Revised | `.gitignore` |
| Revised | `.htaccess` |
| Revised | `AGENTS.md` |
| Revised | `README.md` |
| Revised | `composer.json` |
| Revised | `composer.lock` |
| Revised | `docs/ARCHITECTURE.md` |
| Revised | `docs/INSTALL.md` |
| Revised | `docs/INVARIANTS.md` |
| Revised | `docs/QUALITY.md` |
| Revised | `docs/REVIEW.md` |
| Revised | `docs/THEMES_AND_BUILDER.md` |
| Revised | `index.php` |
| Revised | `phpcs.xml` |
| Revised | `phpstan.neon` |
| Revised | `tests/http-smoke.py` |
| Revised | `tests/server-hardening.py` |
| Revised | `tests/smoke.php` |
| Revised | `tools/lint.php` |
| Revised | `wp-admin/admin-footer.php` |
| Revised | `wp-admin/admin-header.php` |
| Revised | `wp-admin/admin.php` |
| Revised | `wp-admin/class-wp-installer.php` |
| Revised | `wp-admin/edit.php` |
| Revised | `wp-admin/install.php` |
| Revised | `wp-admin/js/page-builder.js` |
| Revised | `wp-admin/login.php` |
| Revised | `wp-admin/options-general.php` |
| Revised | `wp-admin/post-new.php` |
| Revised | `wp-admin/post.php` |
| Revised | `wp-config.php` |
| Revised | `wp-content/themes/default/footer.php` |
| Revised | `wp-content/themes/default/header.php` |
| Revised | `wp-content/themes/default/index.php` |
| Revised | `wp-content/themes/default/page.php` |
| Revised | `wp-content/themes/default/style.css` |
| Revised | `wp-includes/bootstrap.php` |
| Revised | `wp-includes/class-wp-admission-gate.php` |
| Revised | `wp-includes/class-wp-authorization-service.php` |
| Revised | `wp-includes/class-wp-error.php` |
| Revised | `wp-includes/class-wp-event-dispatcher.php` |
| Revised | `wp-includes/class-wp-job-queue.php` |
| Revised | `wp-includes/class-wp-plugin-activator.php` |
| Revised | `wp-includes/class-wp-post-mutation-service.php` |
| Revised | `wp-includes/class-wp-sqlite-db.php` |
| Revised | `wp-includes/formatting.php` |
| Revised | `wp-includes/l10n.php` |
| Revised | `wp-includes/post.php` |
| Revised | `wp-includes/psr/container-interface.php` |
| Revised | `wp-includes/psr/event-dispatcher-interfaces.php` |
| Revised | `wp-includes/theme.php` |
| Revised | `wp-includes/user.php` |
| Unchanged | `var/.htaccess` |
| Unchanged | `wp-includes/plugin.php` |
