# WordPress-style permalink settings

Historical release note: schema 4 subsequently adds `%category%`, hierarchical category archives, tag archives and opt-in author archives. See PROFILES_TAXONOMY_REVIEW.md for current behavior; the exclusions below describe the original permalink-only release.

This release replaces the previous month/day alias-only routing change with configurable canonical post URLs. PHP 8.4+ and SQLite remain required. No schema migration or production dependency was added.

## Using the feature

Open Settings → Permalinks. Choose a preset or Custom structure, then save. Existing installations and blank installations retain Post name unless an administrator selects another format.

| Setting | Example |
| --- | --- |
| Plain | `/?p=123` |
| Day and name | `/2026/10/24/sample-post/` |
| Month and name | `/2026/10/sample-post/` |
| Numeric | `/archives/123` |
| Post name | `/sample-post/` |
| Custom month/day | `/%monthnum%/%day%/%postname%/` → `/10/24/sample-post/` |
| Custom author | `/journal/%author%/%postname%/` |
| Almost pretty | `/index.php/%year%/%postname%/` |

Supported tags: `%year%`, `%monthnum%`, `%day%`, `%hour%`, `%minute%`, `%second%`, `%post_id%`, `%postname%`, `%author%`. Values use UTC, matching the current publication-date display. Author uses the sanitized username. Custom structures begin with `/`, end in `%postname%` or `%post_id%`, optionally followed by `.html`/`.htm` and a trailing slash. Each tag may appear once. Static portions use lowercase ASCII letters, numbers, hyphens, underscores, and slashes. External URLs, query strings, fragments, traversal, unknown tags, duplicate tags, and reserved literal application prefixes are rejected.

Custom formats support the current content model. Categories, tags, hierarchical Pages, date/author/category archives, and a configurable site timezone are not implemented by this change. `%category%` is rejected explicitly. This is not full WordPress rewrite API or plugin compatibility.

## Routing behavior

- The chosen structure controls template links, editor public links, examples, and canonical links through `wp_post_url()`.
- Plain uses `?p=` for Posts and `?page_id=` for Pages. Pretty Page URLs otherwise remain `/<slug>/`. A Page whose slug collides with a matching numeric/custom Post address uses `?page_id=` so both remain accessible.
- Published content alone is available through public permalink and query routes. Existing authenticated previews retain their authorization check.
- Old plain/slug routes and the prior month/day alias redirect with HTTP 301 to the selected canonical URL. Previously selected structures are retained in options, up to 50 unique structures. A full history refuses another change rather than silently dropping earlier formats.
- Old-structure redirects resolve the current slug, publication date, and author. This release does not introduce a redirect registry for past slug changes, author renames, publication-date edits, or site moves.
- Every captured structure value is checked against the resolved Post. Wrong dates, time, author, unknown IDs, drafts, and Page IDs under Post query routes produce real 404 responses. Noncanonical slash and zero-padding variants redirect to the selected form.
- Identity queries are accepted at the site root only. They cannot override invalid date/path routes. Conflicting identity parameters are rejected.
- The site base path is included in generated URLs and removed once for lookup, supporting subdirectory installs.
- Existing Apache/Nginx front-controller rules are sufficient for pretty paths. PATH_INFO (`/index.php/...`) support still depends on the deployment server configuration. The PHP development router was used for live tests; Apache, PHP-FPM and TLS were not retested here.

## Core file changes

| Area | Change | Files |
| --- | --- | --- |
| Routing | Shared validation, indexed lookup by slug/ID, tag verification, canonical generation and redirects | Added `wp-includes/permalinks.php`; revised `index.php`, `wp-includes/theme.php` |
| Settings | Administrator-only format selection, examples, custom structure validation, atomic history and audit writes | Added `wp-admin/options-permalink.php`; revised General settings and admin CSS |
| Verification | Preset/custom HTTP matrix and real settings interactions | Added `tests/permalinks-http.py`; revised browser tests, Composer scripts, CI |
| Documentation | Usage, scope, evidence, inventory and supersession of alias-only note | This note; revised date-routing note, installation and quality docs |

## Verification performed

- Native PHP 8.4.27: syntax, PHPStan level 5 and formatting subset pass.
- PHP regression suite: 26 checks pass.
- Existing HTTP workflow suite: 53 checks pass.
- New permalink HTTP suite: 72 checks pass. It covers every preset, generated archive links, canonical links and redirects, old structures, dates/time/author validation, drafts, malformed identity parameters, invalid custom structures, Unicode, numeric Page collision, subdirectory URLs, access control, CSRF rejection, and audit persistence.
- Chromium browser suite: 47 checks pass, including preset/custom save, current example updates, invalid form preservation, and 320px settings layout. Desktop/mobile settings screenshots were inspected.
- Upgrade/recovery suite: four checks pass.
- Both administration JavaScript files pass syntax checking.

Reference: https://wordpress.org/documentation/article/customize-permalinks/ . WordPress's Day and name and Month and name presets include the year. The earlier `/month/day/` request is implemented as a selectable custom format.

This is a blank install distribution. Databases, credentials, uploads, vendor packages, node modules, caches, logs and screenshots are excluded.

## Complete file inventory relative to the supplied date-routing ZIP

4 files added, 10 revised, 0 removed.

| Status | File |
| --- | --- |
| Added | `docs/PERMALINK_REVIEW.md` |
| Added | `tests/permalinks-http.py` |
| Added | `wp-admin/options-permalink.php` |
| Added | `wp-includes/permalinks.php` |
| Revised | `.github/workflows/quality.yml` |
| Revised | `composer.json` |
| Revised | `docs/DATE_ROUTING_REVIEW.md` |
| Revised | `docs/INSTALL.md` |
| Revised | `docs/QUALITY.md` |
| Revised | `index.php` |
| Revised | `tests/browser.cjs` |
| Revised | `wp-admin/css/admin.css` |
| Revised | `wp-admin/options-general.php` |
| Revised | `wp-includes/theme.php` |
