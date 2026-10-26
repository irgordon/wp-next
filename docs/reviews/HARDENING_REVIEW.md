# Security and delivery hardening review

This release addresses findings 1–4 from the supplied deployment review, adopts a local-only published-image policy, and tightens request handling. It preserves PHP 8.4+, SQLite, the existing schema, transaction boundaries, capabilities and outbox architecture. It does not add deletion behavior.

## What changed and why

| Area | Core change | Why |
| --- | --- | --- |
| Login work | Atomic per-IP reservation before password verification, shared across usernames; 30 attempts per fixed 60-second window. Existing account/IP failures remain capped at five per 15 minutes. Retry headers distinguish the windows; old counters are pruned in bounded batches. | Rotating usernames bypassed the previous account/IP-only throttle and consumed password hashing capacity. A reservation counts admitted work before it starts and is not cleared on successful login. |
| Apache | Complete vhost deny and rewrite rules with `AllowOverride None`; deny source/config/state, executable content files and unexpected admin PATH_INFO; upload headers in vhost. `.htaccess` remains secondary only. | Ignored per-directory rules previously removed essential protections. The deployment must remain protected without reading `.htaccess`. |
| Nginx | Login rate limiting before PHP, protected upload location, MIME declarations, `nosniff` and immutable caching; deny rules retain precedence. | Application checks alone cannot protect PHP worker admission. Static responses never pass through PHP's security-header function. Correct MIME types are required when nosniff is enabled. |
| Image processing | Full GD decode and re-encode; reject decoder warnings, header-only images, unsupported animation, resource-exhausting dimensions or output sizes. Preserve JPEG orientation before removing metadata. Hash and register normalized bytes. | MIME/dimension checks alone accepted damaged images and preserved GPS/text payloads. Content-addressed URLs must describe the bytes actually stored. |
| Runtime | Require GD codecs for JPEG/PNG/GIF/WebP and EXIF in bootstrap, installation and Composer metadata. | Privacy and integrity checks must not silently fall back to storing originals when the decoder is unavailable. |
| Published content | Only site-local content-addressed upload URLs survive image sanitization; CSP restricts images to self. Link relationships are normalized and new-window links gain noopener. | Prevent external tracking pixels, data-image bypasses and arbitrary application URL image requests. Prevent author-supplied `rel=opener` from reversing new-window protections. |
| URLs | Parse origin-form paths without treating `//` as a hostname. Reject malformed encodings, encoded separators, parent traversal and admin PHP path suffixes. Redirect raw encoding/slash variants to canonical URLs. Strictly validate public pagination. | Duplicate/encoded URLs previously returned 200, malformed page input silently became page one, and some handlers accepted unexpected PATH_INFO. |
| Deletion | Separate proposed specification only, in DELETION_FEATURE.md. | Trash, restore, reassignment and permanent deletion require their own lifecycle and integrity rules. |

## Deployment changes and policy

Install PHP GD with JPEG, PNG, GIF read/write and WebP codecs, plus EXIF. The installer/bootstrap reject missing extensions/codecs. Update both CLI and web runtimes. No database migration is required for this release.

Install the updated Apache or nginx rules from INSTALL.md; a source ZIP swap alone does not install server protections. The nginx zone belongs in `http {}`. Apache users should configure equivalent rate controls at a trusted proxy/WAF; the application IP budget works independently. Configure proxy trust explicitly at the web server. The application does not trust forwarded client-IP headers. Shared NAT users share an IP budget.

New images are limited to 10 MB input/output, 12000px per side and 12 megapixels, further reduced by available PHP memory. Animated GIF/WebP/APNG uploads are rejected rather than silently flattened. Static GIF/WebP remain supported. JPEG is re-encoded at quality 90, PNG and WebP losslessly; JPEG orientation is baked into pixels. No original upload bytes are registered or published by the new handler.

Existing upload files and backup archives are not automatically rewritten. Review and replace older originals containing sensitive metadata before making them public. Content-addressed files must not be overwritten under immutable URLs. Existing remote images in legacy content are removed during rendering, including materialized content, because public output passes through the same sanitizer. Import replacements manually through Media; no server-side URL fetcher was added.

## Verification executed

Runtime: native PHP 8.4.27, bundled GD with required codecs, EXIF and SQLite; nginx 1.24 and Apache 2.4.58; headless Chromium. Browser web security remained enabled. The container requires Chromium's `--no-sandbox` flag.

- Composer check: PHP syntax, PHPStan level 5, formatting subset, 26 original PHP checks and 18 new image/content/path checks pass.
- Existing HTTP suite: 53 checks pass, including upload/backup integrity against stored normalized bytes.
- Permalink/request/login HTTP suite: 96 checks pass. Rotating 40 usernames admits the first 30 attempts and rejects the next ten with HTTP 429/Retry-After. Twelve simultaneous PHP processes competing for the final slot admit exactly one. Expired windows admit again. Encoding/slash variants redirect, malformed pagination and admin path suffixes return 404. Existing formats, old-link redirects, Unicode and subdirectory addressing continue to pass.
- Browser suite: 49 checks pass, including publishing content containing a remote image, observing zero tracker requests, and checking explicit noopener. The suite now refuses an invocation containing `--disable-web-security`.
- Upgrade/recovery: four checks pass.
- Apache: 22 live HTTP delivery checks pass with `AllowOverride None` and a deliberately invalid `.htaccess` proving overrides are ignored. Private/code paths are denied. Hashed upload MIME/cache/nosniff and stylesheet MIME are checked.
- Nginx: 26 delivery/rate checks pass. They check sensitive-path denial, upload MIME/cache/nosniff, stylesheet MIME, and HTTP 429 before any PHP backend is available.
- Both admin JavaScript files pass syntax checking.

The server fixtures run actual HTTP/static handling and the documented security rules. They do not establish PHP-FPM, TLS, trusted-proxy integration or production throughput. No new load-capacity or power-loss durability claim is made. The application does not claim immunity to distributed denial of service.

Composer metadata validates; its existing missing-license warning remains unrelated to these changes. Dependency versions were not updated. No broad analyzer suppressions or baseline were added.

## Distribution

Blank install only: no databases, credentials, user uploads, logs, vendor packages, node modules, cache contents or runtime state. Required empty directories and `var/.htaccess` remain.

## Complete file inventory relative to the permalink build

5 files added, 25 revised, 0 removed.

| Status | File |
| --- | --- |
| Added | `docs/DELETION_FEATURE.md` |
| Added | `docs/HARDENING_REVIEW.md` |
| Added | `tests/apache-hardening.py` |
| Added | `tests/hardening.php` |
| Added | `wp-includes/image.php` |
| Revised | `.github/workflows/quality.yml` |
| Revised | `.htaccess` |
| Revised | `README.md` |
| Revised | `composer.json` |
| Revised | `composer.lock` |
| Revised | `docs/INSTALL.md` |
| Revised | `docs/INVARIANTS.md` |
| Revised | `docs/QUALITY.md` |
| Revised | `index.php` |
| Revised | `tests/browser.cjs` |
| Revised | `tests/http-smoke.py` |
| Revised | `tests/permalinks-http.py` |
| Revised | `tests/server-hardening.py` |
| Revised | `tests/smoke.php` |
| Revised | `tools/router.php` |
| Revised | `wp-admin/class-wp-installer.php` |
| Revised | `wp-admin/login.php` |
| Revised | `wp-admin/media.php` |
| Revised | `wp-includes/bootstrap.php` |
| Revised | `wp-includes/formatting.php` |
| Revised | `wp-includes/media.php` |
| Revised | `wp-includes/permalinks.php` |
| Revised | `wp-includes/security.php` |
| Revised | `wp-includes/theme.php` |
| Revised | `wp-includes/user.php` |
