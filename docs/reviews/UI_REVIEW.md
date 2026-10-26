# WordPress Next UI refresh

Reference: the nine WordPress.org Design Library screenshots supplied by the user on 2026-10-24. The Figma URL could not be accessed; this implementation uses the supplied images, not unseen Figma frames. The reference describes the WordPress.org website design system, not a complete WordPress dashboard component specification.

## Core changes

| Area | Change | Files |
| --- | --- | --- |
| Shared foundation | Exact supplied blueberry, charcoal, secondary palette, borders, Inter and EB Garamond families | Added `wp-content/assets/design-tokens.css`, local regular/italic variable fonts and license files |
| Admin | Inter interface, consistent inputs/buttons/notices, charcoal navigation, blueberry selection, compact dashboard metrics, readable spacing | Revised `wp-admin/css/admin.css` |
| Navigation | Mobile menu, expanded state, Escape handling, focus preservation, desktop resize recovery; links remain available without JavaScript | Added `wp-admin/js/admin.js`; revised `wp-admin/admin-header.php` |
| Editor | Styled library controls, linked slug help, live visual alignment matches stored alignment | Revised `wp-admin/post-editor.php`, `wp-admin/js/page-builder.js` |
| Public themes | One shared layout stylesheet; theme-specific Studio treatment; Inter body text and selectable EB Garamond headings | Added `wp-content/assets/site.css`; revised both theme stylesheets, `wp-includes/theme.php`, `wp-admin/themes.php` |
| Verification | Menu keyboard/fallback checks, local font/palette check, live alignment, eight admin screens at 320px and 390px | Revised `tests/browser.cjs`, `package.json`, `docs/QUALITY.md` |

## Adaptation decisions

- Admin uses 16px Inter, 29px desktop page headings, 20px section headings, and 14px supporting text. Controls have a minimum height of 44px.
- Public editorial headings follow the supplied serif scale: desktop 70/50/36/30/26/22px. Narrow-screen sizes use 52/30/26px for the first three levels, with smaller lower levels suited to article content. Existing sans-serif and serif design choices remain available, now applied to headings while body text stays Inter. Studio uses EB Garamond for entry titles.
- Blueberry (#3858E9) is the default accent. Existing saved accent/background selections are preserved. Hover uses Dark Blueberry (#1D35B4). Charcoal (#1E1E1E), White, and Light Grey 2 form the admin surfaces.
- Red/green/yellow palette values are available as tokens. Notices use pale reference fills plus darker borders/text for legibility; meaning is also conveyed in text.
- The source image labels a white 15% border as #000000. The implementation uses white at 15% opacity, consistent with its name and swatch.
- Fonts are served locally. No CDN, external font request, CSS framework, or new production JavaScript dependency was introduced. Font outlines remain unchanged; sources and SIL licenses are included.
- The mobile menu is an in-flow disclosure. It is not a modal drawer and does not trap focus.

## Executed checks

Executed on native PHP 8.4.27 and SQLite with headless Chromium:

- PHP syntax, PHPStan level 5, and the existing formatting subset: pass.
- JavaScript syntax for both administration scripts: pass.
- PHP suite: 26 checks pass.
- HTTP suite: 53 checks pass.
- Browser suite: 42 checks pass, including editor alignment, media, draft recovery, keyboard menu controls, JavaScript-disabled navigation, resize recovery, and eight administration screens at both 320px and 390px.
- Visually inspected new editor desktop/mobile, overview, installer/login, and public theme captures. Screenshots use disposable test content; the black public featured image is the test suite's 1px upload, not a bundled theme asset.

No schema, session, authorization, queue, upload-validation, or persistence behavior was changed. PHP remains 8.4+ with SQLite. Passing checks do not establish full accessibility compliance. Safari, real touch devices, Apache, PHP-FPM, and TLS were not verified in this UI pass.

The distribution contains source, locked development tooling, fonts and licenses. It excludes vendor/node_modules, installed databases, credentials, user uploads, generated state, caches and screenshots.

## Complete file inventory relative to the previous blank install

11 files added, 11 revised, 0 removed.

| Status | File |
| --- | --- |
| Added | `docs/UI_REVIEW.md` |
| Added | `wp-admin/js/admin.js` |
| Added | `wp-content/assets/design-tokens.css` |
| Added | `wp-content/assets/fonts/EBGaramond-OFL.txt` |
| Added | `wp-content/assets/fonts/Inter-OFL.txt` |
| Added | `wp-content/assets/fonts/README.txt` |
| Added | `wp-content/assets/fonts/eb-garamond-italic.woff` |
| Added | `wp-content/assets/fonts/eb-garamond.woff` |
| Added | `wp-content/assets/fonts/inter-italic.woff` |
| Added | `wp-content/assets/fonts/inter.woff` |
| Added | `wp-content/assets/site.css` |
| Revised | `docs/QUALITY.md` |
| Revised | `package.json` |
| Revised | `tests/browser.cjs` |
| Revised | `wp-admin/admin-header.php` |
| Revised | `wp-admin/css/admin.css` |
| Revised | `wp-admin/js/page-builder.js` |
| Revised | `wp-admin/post-editor.php` |
| Revised | `wp-admin/themes.php` |
| Revised | `wp-content/themes/default/style.css` |
| Revised | `wp-content/themes/studio/style.css` |
| Revised | `wp-includes/theme.php` |
