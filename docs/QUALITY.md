# Quality gates and regression checks

Run checks with PHP 8.4+ and the mandatory extensions. Runtime code has no vendor dependency; Composer and npm tools are development-only.

```bash
composer install
composer check
composer test:http
python3 tests/upgrade-smoke.py
npm ci
npx playwright install chromium
npm run check:js
npm run test:builder
npm run test:widgets
npm run test:browser
npm run test:widgets:browser
npm run test:preview:browser
npm run test:pages:browser
python3 tests/server-hardening.py /absolute/path/to/nginx
python3 tests/apache-hardening.py /absolute/path/to/apache2 /absolute/path/to/apache/modules
```

composer check runs syntax checks, PHPStan level 5 (language target 8.4), the explicit WordPress formatting subset, and PHP smoke checks. PHPStan does not execute HTTP bootstrap or connect to a database. No broad error ignores or generated error baseline conceal current findings. Mutable loop and transaction readers are marked impure; deployment flags are configured as dynamic constants.

The formatting gate covers whitespace/indentation, closing braces, arrays, Yoda comparisons, control/function signatures, multiple statements, and line endings. It does not claim full WPCS documentation/naming/compatibility certification or JavaScript lint coverage. composer format:fix changes files; rerun checks after reviewing the diff. Tool versions are locked in composer.lock and package-lock.json.

The PHP suite covers installation, DOM/protocol sanitization, nested content, revision fencing, capabilities revoked during events, atomic outbox, and lease ownership. A PHP runtime without DOM exercises dependency refusal and escaped fallback. The HTTP suite creates a disposable site and checks installation, session/CSRF boundaries, roles, read isolation, content, media, settings, routing, worker materialization, backups, and maintenance. Upgrade checks exercise prior-schema preservation and repeatability.

The Playwright suite exercises actual editing, inline formatting, nested layouts, uploads, image selection, raw document edits, theme selection, mobile fit, and draft recovery. Set PHP_BINARY if php is not on PATH. Optional BROWSER_PATH and BROWSER_ARGS select a local browser; the default uses Playwright's installed Chromium. QA_DIR saves local screenshots for inspection. Browser binaries and generated screenshots are not distributed.

The Nginx fixture suite loads the server block from INSTALL.md and exercises denial rules plus a public stylesheet control. It does not test PHP-FPM, TLS, or Apache. Production deployment must verify those integrations. The supplied GitHub workflow includes PHP, HTTP, upgrade, JavaScript, and browser jobs; it has not been executed remotely during this work.

Passing these checks supplies scoped regression evidence, not security certification, load testing, or proof that any hosting configuration is safe. See reviews/REVIEW.md for executed results.

## UI refresh checks

The browser suite also checks mobile menu state and Escape focus, navigation without JavaScript, administration system typography and neutral surfaces, immediate text alignment, resize recovery, and eight administration screens at 320px and 390px. Public themes retain their local font assets. See reviews/UI_REVIEW.md for the earlier interface pass and reviews/V0_0_2_WORDPRESS_DESIGN_REVIEW.md for the Figma alignment.

Run `composer test:permalinks` (or `python3 tests/permalinks-http.py /absolute/path/to/php`) for the canonical-routing HTTP matrix. The browser suite also exercises the Permalinks settings screen. See reviews/PERMALINK_REVIEW.md for current execution evidence and format constraints.

## Hardening regression checks

`composer check` now includes 18 concrete image/content/address checks alongside the original smoke checks. The permalink HTTP suite includes IP admission tests, a 40-username flood, concurrent reservation processes, retry headers, expiry, raw URL variants, and invalid pagination. The browser suite requires web security enabled; it rejects `--disable-web-security` and verifies that published remote image markup cannot issue a tracking request. The local Chromium runtime still requires `--no-sandbox` in this container.

The Apache fixture runs the complete documented vhost security rules with `AllowOverride None` and deliberately invalid `.htaccess` content. It checks actual HTTP denial, static MIME and upload headers. The nginx fixture checks denial rules, static headers/MIME and login rate limiting before any PHP backend is available. Neither fixture represents PHP-FPM or TLS integration coverage. See reviews/HARDENING_REVIEW.md for the executed results and remaining deployment checks.

## Asset foundation

`composer check` includes 30 asset lifecycle/cleanup checks in addition to the 27 smoke and 18 hardening checks. `composer test:assets` runs six migration/concurrency checks in real PHP processes and nine HTTP checks. Document intake/delivery is covered end-to-end by `composer test:documents`, image lineage, edits, replacement and backup-family behavior by `composer test:images`, and the account/email/deletion contracts by `composer test:accounts`; CI runs all three suites alongside the design and HTTP suites. The nginx fixture additionally asserts that allowlisted PHP endpoints — including the visual editor's `block-preview.php` — reach the configured FastCGI handler; the fixture has no live FPM backend, so that assertion proves routing, not FPM success.

## P3 and P4 file editing

`composer test:design` runs the design/file HTTP boundary suite, including v5 upgrade, preview isolation, health failure, concurrent-version rejection, draft recovery/discard, template assignment, shared-image references, managed CSS validation/rollback and database backup retention. The browser suite now includes design draft/preview/publication, reopening saved drafts, Page templates, visible managed CSS changes and rollback, and mobile file editing. CI runs this additional suite. PHPStan level 5 and the existing formatting subset remain unchanged.


## v0.0.3 Portability

`composer test:portability` runs `tests/portability-http.py` against three
disposable sites plus a CLI-only target. It covers the named G2 release tests
individually: a native round-trip (content, terms with hierarchy, media with
visibility, authorship through the ID map, featured relationships, dates),
a representative WXR fixture with a supplied media archive, per-item
reporting of missing media and unsupported content (custom post types,
shortcodes, plugin meta, status downgrades), hostile inputs (zip-slip, XXE,
absurd manifest counts, non-ZIP uploads — all refused without writes), quota
exhaustion, exact-duplicate skipping, slug-collision suffixing, and resume
of a completed archive without duplication. CI runs the suite alongside the
other HTTP suites.

## v0.0.1 Boundary Verification

`composer test:accounts` now runs the original account suite and six additional
boundary checks. They cover disabled magic-link policy, commit-time expiry and
account status, File-link correctness after author reassignment, and concurrent
account-link issuance. The original package fails five of those six checks.

[The verification review](reviews/V0_0_1_VERIFICATION.md) records the complete
blank-install rerun, each discovered defect, first failures, and the 78 local
HTTPS checks through real nginx/Apache and PHP-FPM. The local run uses root
identities and a test certificate. It supplements the fixture checks; it does
not close J1 production certification.

## v0.0.2 Visual Builder

`npm run test:builder` checks the pure document operations without a browser or database. It covers immutable updates, nested movement, ordering, descendant-cycle refusal, invalid positions, maximum depth/block count, independent copies and media-reference preservation. CI runs it alongside JavaScript syntax and browser checks.

The browser suite exercises direct canvas text/caption editing, selection formatting, caret retention across the preview debounce, unsaved handoff in both directions, drag-and-drop into a column, duplication, undo/redo, image selection, new-page creation, saving, raw-input recovery and responsive drawers at 320px/390px. It verifies that page styles retain their public scale and that iframe scripts stay disabled.

The HTTP smoke suite includes builder read isolation, capability and CSRF checks, view-preserving form actions and rendering at every nested column boundary. [The verification record](reviews/V0_0_2_BUILDER_REVIEW.md) retains the initial iframe, drawer and resize-timing failures.

## v0.0.2 Administration Widgets

`composer check` includes 12 pure navigation checks for capability filtering, accessible group destinations, active items and breadcrumb paths. `npm run check:js` discovers every administration script rather than maintaining a fixed list.

`npm run test:widgets` checks file validation, real progress calculation, server acknowledgement, partial success, cancellation, retry-attempt fencing, lossless inert tokenization and invalid-JSON preservation. It requires no browser or database.

`npm run test:widgets:browser` installs a disposable site and exercises the actual upload, picker, breadcrumb and code-window workflows. Network throttling verifies an intermediate byte-transfer percentage; a delayed server response verifies that 100% transferred is still processing. It covers server refusal and retry, no-results and connection recovery, native form association while expanded, inert code display, desktop/mobile fit and classic upload without JavaScript. Clipboard output is replaced by a test-local stub. The suite shares PHP_BINARY, BROWSER_PATH, BROWSER_ARGS and QA_DIR with the existing browser suite. CI includes both new JavaScript suites.

[The verification record](reviews/V0_0_2_ADMIN_WIDGETS_REVIEW.md) retains the first failures, corrections, screenshot review and final counts. These are local results; the updated remote CI workflow has not been run during this change.

## WordPress Figma Alignment

The current browser suites additionally check the 240px navigation area, native system type, local 24px reference icons, numeric min/max boundaries, read-only controls, retained form values, single-border numeric fields, focused search geometry and card radii. The general suite uses a 1x viewport and the focused suite uses a 2x viewport. Focus assertions account for Chromium's fractional-outline quantization. [The alignment review](reviews/V0_0_2_WORDPRESS_DESIGN_REVIEW.md) records the 210 model/browser assertions, initial failures and corrections. [The design-system map](DESIGN_SYSTEM.md) records the full reference inventory and intentional compact-density mapping.

The focused Site design follow-up verifies the scoped 288px charcoal rail, unchanged main groups, section switching without value loss, structural saved/unsaved state, first-invalid-field recovery, curated-only template inventory, absence of Patterns UI, responsive menu contrast and native sidebar submission without JavaScript. The existing design HTTP and browser suites retain preview, publication, stale-version refusal and rollback coverage. [The focused navigation review](reviews/V0_0_2_FOCUSED_DESIGN_REVIEW.md) records the 309 executed assertions and first failures.

## Private live design preview

`composer test:design` now also runs `tests/design-preview-http.py`. It checks unsaved rendering without persistence, selected-theme function loading, homepage read isolation, token/menu/section output, private cache headers, retained global frame protections, stripped active content, separate preview and persistence nonces, capability revocation, invalid settings, stale versions and explicit publication.

`npm run test:preview:browser` verifies the actual rendered site beside the controls, live changes, read-only frame behavior, font-stable scroll restoration, desktop/mobile viewport sizes, visible width changes, response ordering even when cancellation is ignored, retained controls, actionable errors, refresh recovery and public output changing only after Publish design. It uses disposable state and the same PHP_BINARY, BROWSER_PATH, BROWSER_ARGS and QA_DIR options as the other browser suites. CI runs this suite with web security enabled.

## Pages in Site design

`composer test:design` also runs `tests/design-pages-http.py`: bounded inventory/search/status filtering, literal SQL wildcards, native GET fallback, private draft/archive selection, unchanged public output, Page-specific signed editor links, default DENY versus scoped SAMEORIGIN framing, capability revocation, original save CSRF, context-preserving redirects and stale-revision retention.

`npm run test:pages:browser` covers list pagination, empty results, selection without changing the homepage, embedded editing, current unsaved typography, keep/discard behavior, independent Page/design saves, refreshed titles/previews, retained controls and desktop/mobile fit. CI runs this suite using the same disposable-fixture and browser options as the other UI suites. [The verification record](reviews/V0_0_2_DESIGN_PAGES_REVIEW.md) records executed results and initial failures.

The Page HTTP/browser suites also cover **New Page**: fixed private-draft defaults despite a Published site default or forged input, action-bound creation CSRF, capability checks, real mutation-service refusal, unique slugs, list-position recovery, pending-button duplicate prevention, new-Page editing/saving, retained design state and recovery after a lost acknowledgement. [The creation verification record](reviews/V0_0_2_NEW_PAGE_REVIEW.md) records the follow-up results.

The Page suites also verify explicit **Save Draft / Publish Page / Update Page** actions: submitted-status overrides, stale publication and repeat-update fencing, original CSRF/authentication checks, transactional publication failure, retained content and actual saved status after refusal, positive anonymous delivery after publication, the save shortcut remaining private for drafts, responsive controls and unchanged design configuration. [The publication verification record](reviews/V0_0_2_PUBLISH_PAGE_REVIEW.md) records the follow-up results.

The Page suites also check **View Page**: published-only availability, canonical public URLs, a real new tab showing saved content, no opener/admin referrer, retained workspace URL/editor scroll/unsaved content/design controls, no implicit save, distinct Preview behavior and toolbar fit at 320px/390px. [The live-view record](reviews/V0_0_2_VIEW_PAGE_REVIEW.md) records the follow-up evidence.
