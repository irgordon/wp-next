# Local Schema 9 Upgrade And Bakery Walkthrough

Recorded October 31, 2026 against `73ab182`. This records an installed local upgrade and a separate demonstration; it does not close J1 target-host certification.

## Environment And Commands

macOS; PHP CLI/development server 8.4.27; PDO-linked SQLite 3.54.1; Node 26.13.0; Playwright 1.62.1; Microsoft Edge 156.0.4314.50. Desktop viewport: 1440 × 1000; mobile viewport: 390 × 844. The mobile captures are Chromium viewport checks, not Safari or physical-device evidence.

The original site used loopback port 8080; the isolated bakery used 8081. A separate source copy and runtime/database paths kept all bakery authoring out of the original database. No product or test source changed.

Below, `php` denotes `/opt/homebrew/opt/php@8.4/bin/php`, invoked from the repository root:

```sh
php -r 'define("WP_MAINTENANCE_COMMAND",true); require "wp-includes/bootstrap.php"; $wp_admission_gate->enter_maintenance(); try { echo wp_create_backup($wpdb,"manual-before-schema9"),PHP_EOL; $wp_admission_gate->exit_maintenance(); } catch(Throwable $e) { $wp_admission_gate->abandon_maintenance_sealed(); throw $e; }'
php bin/wp upgrade
php bin/wp status
```

Before changing the database, Python's SQLite backup API also created a consistent schema-8 snapshot. The application backup ran through the existing maintenance/admission gate because ordinary bootstrap refused the outdated schema. The archive's database passed `PRAGMA integrity_check`; its manifest reported schema 8 and its site secret matched. The upgrade command additionally created its normal before-upgrade backup.

## Original Site Preservation

| Check | Result |
| --- | --- |
| Schema | 8 → 9 |
| Existing Posts / Pages / users | 1 / 1 / 1, preserved |
| Registered media | 0 before and after; no existing-media restore claim |
| Existing table values | Compared all original columns and rows; unchanged except schema-version value and intentional session revocation |
| Existing sessions | 1 → 0, as required by upgrade |
| SQLite integrity / foreign keys | `ok` / no violations |
| Editorial API | Disabled and unbound after migration |
| Maintenance | Released after successful upgrade |
| Actual HTTP checks | Homepage, login, `/hello-world/` and `/sample-page/`: 200 |

Preservation was checked again after the bakery walkthrough. Existing content, account fields, revisions, relationships and settings still matched the pre-upgrade snapshot, with the same two expected exceptions.

## Bakery Workflow And Captures

The fictional **Hearth & Crumb Bakery** was installed from blank through the real two-step installer and browser login. All content and design edits used the application's interfaces. The demonstration contains five published Pages, three published journal Posts, three base images and nine derived images.

The 37 full-resolution PNG screenshots cover installation, login, overview, empty/populated media library, real upload progress, image metadata, Page creation, visual editing, blog creation/update, private design preview/drafts, explicit design publication, embedded Page editing/publication, unsaved-change protection, image selection, inventories, public desktop/mobile views and a private stylesheet draft.

- Food photographs were AI-generated: croissants, sourdough and berry tarts. Interface screenshots were captured from the running application.
- All nine bakery content routes returned HTTP 200. The edited homepage heading and updated journal text were present in public output.
- All 12 delivered image files matched the registered SHA-256 values. SQLite integrity and foreign-key checks passed.
- Browser capture recorded zero JavaScript page errors; the demo PHP log contained no fatal/uncaught errors.
- The stylesheet draft existed in `options`; the published-version table and public stylesheet remained unchanged.

## Capture Corrections And Limits

The initial browser launch was blocked by the local sandbox; an approved desktop-permission launch succeeded using the existing browser/dependency. The persistent tool's ESM import failed; loading the installed package through its CommonJS entry succeeded without installation or source edits.

Three capture waits used the wrong UI state or locator: an editable heading exposes a textbox role, the existing unsaved-Page dialog needed a recorded discard action, and image-picker buttons use image filenames. Inspection supplied the correct interactions; no product fix was inferred from those waits.

An initial ad hoc validation expected a private CSS draft in the published-version table. The corrected check required the private draft option and verified that published versions/public CSS were unchanged. No application test was weakened or skipped.

The raw backup, preservation records and screenshot manifest remain outside source distribution. The delivered `WP_Next_Bakery_Walkthrough_2026-10-31.zip` contains 37 screenshots, three food images, an HTML guide and validation metadata; it excludes databases, credentials, cookies and site secrets. Local preview ports and filesystem artifacts are not portable public evidence URLs.

The source baseline's [CI run on `73ab182`](https://github.com/irgordon/wp-next/actions/runs/41207356127) passed 1,836 checks, including 422 real FPM/HTTPS assertions. That CI run verifies its disposable fixtures, not this installed Mac database or the bakery's manual capture session. No production target was provisioned; all 17 J1 target closeouts remain open.
