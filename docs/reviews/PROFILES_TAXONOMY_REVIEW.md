# Profiles, categories and tags: review and implementation

Date: 2026-10-24. Baseline: the attached `WordPress_Next_Blank_Install(1).zip`, compared by SHA-256 per file. Runtime remains PHP 8.4+ and SQLite; no production dependency or architecture replacement was added.

## Review outcome and why changes were made

The attached build's installer wizard, admin layout, quick edit, sortable columns and content Trash/Restore/permanent deletion are retained. These are input features, not features newly introduced by this change. The existing hardening for login admission, server configuration, upload normalization, same-origin images and canonical URL handling is also retained.

| Files | Change | Why |
| --- | --- | --- |
| `wp-includes/schema.php`, migration regression tests | Stamp schema 4 only after migration and foreign-key validation; preserve sqlite_sequence across the legacy Posts rebuild. | A failed migration must not appear complete, and deleted IDs must not be reused by future Posts while queued references can still exist. |
| `wp-includes/class-wp-post-mutation-service.php`, `wp-admin/post.php` | Recheck Trash/Restore conditions under the write lock, advance revision and snapshot classifications on transitions; reject ordinary saves of trashed rows. | Prevent stale editors from bypassing Trash and fence queued rendering by the current revision. |
| `wp-admin/profile.php`, `users.php`, `class-wp-installer.php`; `wp-includes/profiles.php`, `user.php`, `schema.php`, `media.php` | Add profile fields, private defaults, local avatars, display names and opt-in public author archives; protect referenced avatar media and concurrent edits. | Complete the profile workflow without exposing email/security data, silently publishing existing users or introducing remote avatar tracking. |
| `wp-includes/taxonomy.php`, `schema.php`; `wp-admin/edit-tags.php`, editor forms and navigation | Add hierarchical categories, flat tags, protected Uncategorized fallback and Post assignments. | Provide familiar publishing organization with capability checks, cycle prevention and atomic persistence. |
| Mutation service, revision restore, `wp-admin/js/page-builder.js` | Save assignments with Post writes and revision snapshots; preserve them in draft recovery and quick edit. | Classifications must survive the same recovery, conflict and rollback paths as content. |
| `index.php`, `wp-includes/permalinks.php`, permalink settings and content slug reservations | Add published-only category/tag/author archives, descendant category queries, `%category%` hierarchy paths and reserved archive namespaces. | Make classifications useful publicly and avoid route collisions while reusing canonical URL handling. |
| Both themes, `wp-includes/theme.php`, shared site/admin styles | Display author cards, bylines, linked terms and archive titles/descriptions with responsive forms. | Connect the new data to visible publishing workflows using the existing UI language. |
| CI, Composer scripts, tests and documentation | Add focused coverage and update the supported feature and upgrade scope. | Make the boundaries reproducible and avoid stale claims that categories/profiles are unavailable. |

## Supported behavior and boundaries

- Profile fields: display name, first/last names, biography, HTTP/S website and a registered local avatar. Public profiles are opt-in and expose only display name, biography, website and avatar. Account email and security fields never enter the public author query. First/last names remain private unless deliberately included in the display name.
- Users edit their own profiles; administrators can edit another account through Users. Contact/password changes require the acting user's current password; password changes revoke the target's sessions. Profile revision conflicts preserve submitted input. Profile forms cannot change roles or select their target by posted data.
- Administrators/editors manage categories and tags. Authors select existing terms when editing their permitted Posts. Pages do not accept classification assignments. Category parenting rejects cycles and excessive depth; tags cannot have parents. Uncategorized cannot be deleted or moved and supplies the fallback after empty assignments or category deletion.
- Post content, term assignments, revision snapshots and outbox work are committed together. Quick edit preserves classifications; restore replays snapshot term IDs. Missing/deleted historical terms produce a failed save rather than a partial restore. Legacy revisions without term IDs preserve the current classifications.
- Category archives include published Posts assigned to the category or descendants; tag and author archives include only published Posts. Private author archives return a real 404. Author URLs use a sanitized immutable username plus numeric ID to avoid collisions.
- `%category%` uses the first assigned category sorted by name, then ID, with its parent path. Another assigned category path redirects to the selected canonical URL. Term names and descriptions are escaped on output. Custom taxonomy registration, configurable archive bases and hierarchical Pages are not included.
- Content deletion in the attached build is retained. User deletion remains a separately specified feature; Disable remains available. No remote-image policy changes were needed in this feature release.

## Upgrade and blank installation

Schema 4 adds profile columns to users, STRICT terms/post_terms tables, foreign keys and indexes. Existing display names start as the username; public profiles start disabled. Existing Posts receive Uncategorized if they have no category. New installations seed the same default and classify their sample Post. No database, credentials, uploaded image, dependencies or runtime state are distributed.

Back up source/configuration and operator secrets, deploy the source, then run `php bin/wp upgrade` with native PHP 8.4+ before reopening traffic. The coordinator creates its pre-upgrade backup, migrates under maintenance admission, validates foreign keys and stamps the final version. Existing sessions are revoked by the existing upgrade policy. Failed upgrades retain maintenance mode for operator recovery. See INSTALL.md for recovery and server configuration; the nginx PHP entrypoint allowlist now includes `edit-tags.php`.

## Verification evidence

Executed with native PHP 8.4.27, SQLite, GD/EXIF and a real Chromium process. Browser launch uses an environment-required no-sandbox option; web security was enabled. Disposable test copies and accounts were used.

| Gate | Result |
| --- | --- |
| `composer check` | Syntax, PHPStan level 5 (no errors), configured PHPCS subset, 26 PHP checks and 18 hardening checks pass. |
| `python3 tests/http-smoke.py /path/to/php` | 53 HTTP workflow checks pass. |
| `python3 tests/permalinks-http.py /path/to/php` | 96 permalink/login HTTP checks pass. |
| `python3 tests/profiles-taxonomy-http.py /path/to/php` | 43 privacy, capability, profile, taxonomy, archive, rollback and Trash/revision checks pass. |
| `python3 tests/upgrade-smoke.py /path/to/php` | 4 upgrade checks pass, including sealed failure and recovery. |
| `python3 tests/migration-regressions.py /path/to/php` | 3 migration regressions pass: no premature schema stamp, no deleted-ID reuse and correct legacy defaults. |
| `npm run check:js` | Both checked admin scripts parse. |
| `tests/browser.cjs` with native PHP and Chromium | 60 browser checks pass, including local profile avatars, public privacy, parent/child category and tag selection, draft recovery, archives and category permalinks. |
| `tests/server-hardening.py` with nginx | 26 static delivery/rate-limit checks pass. |
| `tests/apache-hardening.py` with Apache | 22 delivery checks pass with AllowOverride None. |

Desktop public/profile/taxonomy screens and 320px admin layouts were inspected. Server configuration fixtures verify delivery/denial behavior; they do not establish a production PHP-FPM, TLS, proxy-topology or hosting deployment result. No long soak or network-filesystem test is claimed. Formatting is the configured subset, not full WPCS certification.

## Hash-verified file inventory versus the attached build

7 added; 37 revised; 0 removed. All other file bytes match the attached archive. This review note is included in the added count.

### Added

- `docs/PROFILES_TAXONOMY_REVIEW.md`
- `tests/http_helpers.py`
- `tests/migration-regressions.py`
- `tests/profiles-taxonomy-http.py`
- `wp-admin/edit-tags.php`
- `wp-includes/profiles.php`
- `wp-includes/taxonomy.php`

### Revised

- `.github/workflows/quality.yml`
- `README.md`
- `composer.json`
- `docs/ARCHITECTURE.md`
- `docs/DELETION_FEATURE.md`
- `docs/INSTALL.md`
- `docs/PERMALINK_REVIEW.md`
- `docs/RELEASE_PLAN.md`
- `index.php`
- `tests/browser.cjs`
- `tests/permalinks-http.py`
- `tests/upgrade-smoke.py`
- `wp-admin/admin-header.php`
- `wp-admin/class-wp-installer.php`
- `wp-admin/css/admin.css`
- `wp-admin/edit.php`
- `wp-admin/js/page-builder.js`
- `wp-admin/options-permalink.php`
- `wp-admin/post-editor.php`
- `wp-admin/post-new.php`
- `wp-admin/post.php`
- `wp-admin/profile.php`
- `wp-admin/users.php`
- `wp-content/assets/site.css`
- `wp-content/themes/default/header.php`
- `wp-content/themes/default/index.php`
- `wp-content/themes/default/single.php`
- `wp-content/themes/studio/header.php`
- `wp-content/themes/studio/index.php`
- `wp-content/themes/studio/single.php`
- `wp-includes/class-wp-post-mutation-service.php`
- `wp-includes/content.php`
- `wp-includes/media.php`
- `wp-includes/permalinks.php`
- `wp-includes/schema.php`
- `wp-includes/theme.php`
- `wp-includes/user.php`

### Removed

None.

