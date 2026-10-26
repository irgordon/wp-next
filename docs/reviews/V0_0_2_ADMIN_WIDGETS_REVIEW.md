# v0.0.2 — Uploads, Navigation And Code Windows

Recorded October 26, 2026. Schema remains 8.

## Result

Image and document uploads now use a rounded drop zone and persistent per-file queue. Each row reports actual transferred bytes as a percentage, then Processing until a successful server response confirms an asset ID. Reaching 100% transferred alone never marks a file Uploaded. Unknown lengths remain indeterminate. Server refusals and connection failures remain visible; retries start a new attempt, and late events cannot change a newer attempt. Cancel stops an in-flight transfer; Remove removes a queue row rather than deleting a library asset.

Submitting no files reports an explicit error. Library pages and editor pickers distinguish loading, an empty library, no matching search results and connection failure. Clear search, refresh and retry actions offer a recovery path. Successful batches refresh the library and storage summary while retaining per-file results. Original multipart forms remain usable without JavaScript.

The sidebar groups destinations under Content, Media, Design and Settings. Existing capabilities determine visible links and group destinations. Breadcrumbs follow that hierarchy and expose intermediate parents in an overflow menu on narrow screens and in the dedicated builder. Native links/details retain keyboard behavior; Escape closes the parent menu. Media lists the implemented Images and Documents libraries. This update adds no video intake support.

Raw-document, HTML/code-block and managed-file fields use editable charcoal code windows with the existing blue accent. They provide syntax colors, line numbers, active-line/caret position, Wrap, Copy feedback, JSON formatting where applicable and an expanded native dialog. The same native textarea retains its name, value, form association and input events. Invalid JSON is preserved; code tokens are inert text. Large inputs fall back to plain editing above 200,000 characters or 3,000 lines. Motion respects reduced-motion preferences.

Interaction references supplied by the user: [file upload](https://beui.dev/components/blocks/file-upload), [Percent loader](https://beui.dev/components/motion/loader), [breadcrumb](https://beui.dev/components/motion/breadcrumb) and [code block](https://beui.dev/components/agents/code-block). These informed the native presentation. No React, animation-library or remotely hosted runtime dependency was added.

## Abstraction Findings In The Previous Build

- The anonymous upload submit handler in v0.0.2 `wp-admin/js/admin.js`, line 57, combined queue decisions, DOM construction, multipart serialization, HTTP transport, response parsing and redirection. The new upload model isolates state decisions from the XHR adapter and DOM presentation.
- `loadMedia()` in v0.0.2 `wp-admin/js/page-builder.js`, line 211, combined HTTP parsing and picker presentation without distinguishing an empty library from an empty search. The existing picker adapter now renders explicit states and fences stale responses. Dialogs open before loading starts, making progress and failures visible.
- The raw textareas had no separate source-presentation adapter. The new code model handles inert tokens and formatting; code-editor.js handles the DOM while preserving the existing editor/controller contract.

Existing PHP request controllers and legacy editor adapters retain their established procedural structure. This is a scoped extraction and interface improvement, not a claim that every legacy function meets the supplied abstraction rules.

## Responsibility Hierarchy

| Layer | Function Name | Responsibility | Calls | Allowed to Call |
| :--- | :--- | :--- | :--- | :--- |
| High | `finishTransfer` | Coordinate response interpretation and state application | `interpretUploadResult`, `applyUploadResult` | Mid |
| Mid | `validateSelection` | Apply file-selection rules | `positiveFileSize`, `acceptedType` | Low |
| Mid | `chooseUploadOutcome` | Choose acknowledgement or failure state | `acceptedResponse`, `responseMessage` | Low |
| Low | `createUploadRequest` | Adapt one multipart XHR request | request/event adapters, XMLHttpRequest | Infrastructure wrappers/platform API |
| Low | `tokenizeCode` | Produce inert source tokens | `scanCodeTokens`, RegExp | Text-processing wrappers/platform API |

All six newly added JavaScript modules keep named functions short and place lifecycle operations before their helpers. Upload and tokenization decisions are independently exercised without a browser, database or filesystem.

## Executed Verification

Environment: macOS, PHP 8.4.27, SQLite 3.54.1, Node 26.12.0 and PHPStan 2.3.2 at level 5. Browser suites use a separate headless Microsoft Edge profile with web security enabled. Sites, credentials and uploaded fixtures are disposable; the existing local site's database is not used by these suites.

| Command / Suite | Result |
| --- | --- |
| `composer check` | Exit 0; syntax, zero PHPStan errors, configured PHPCS subset; 27 smoke + 18 hardening + 30 asset + 12 navigation checks |
| `npm run check:js` | Exit 0; all 11 administration scripts |
| `npm run test:builder` | 14 passing document-operation checks |
| `npm run test:widgets` | 35 passing upload/tokenization checks |
| `composer test:http` | 59 passing checks |
| `composer test:assets` | 6 integration + 9 HTTP checks |
| `composer test:documents` | 103 passing checks |
| `composer test:images` | 47 passing checks |
| `composer test:design` | 45 passing checks |
| `npm run test:browser` | 113 passing checks; zero JavaScript page errors |
| `npm run test:widgets:browser` | 35 passing checks; zero JavaScript page errors |

Total: 653 explicitly counted passing assertions. Static-analysis, formatting and syntax gates are additional. Intermediate reruns are not added to the total. The final two presentation fixes were verified by the focused browser suite after the broader browser suite passed.

The focused browser suite throttles an actual upload to observe an intermediate percentage and delays processing to distinguish transfer completion from acknowledgement. It exercises real server rejection, partial success, retry, endpoint resolution, search recovery, picker failure/retry, invalid JSON, inert code, expanded form association, mobile overflow and classic upload with JavaScript disabled. Its clipboard stub verifies exact bytes without changing the user's clipboard.

Screenshots were reviewed for the real transfer, partial failure, desktop code window, mobile code window and mobile library. Review caught the narrow desktop upload card and stale code-tool feedback; both were corrected and covered by assertions.

## First Failures And Corrections

| ID | First observation | Cause | Correction and evidence |
| --- | --- | --- | --- |
| V002-W1 | Initial PHPStan execution failed while loading a PHAR stub under the old import path. | A generated reflection cache retained a directory name from the imported project. | Removed only `var/cache/phpstan`. The repeated and final complete Composer gates passed without source suppressions or a baseline. |
| V002-W2 | The existing browser suite could not complete a valid image upload. | The hidden input named `action` shadows the native `form.action` property. | Resolve the action attribute against location.href. The focused suite asserts the actual request path; the broader 113-check suite passed. |
| V002-W3 | A picker no-results assertion ran before the response was rendered. | An immediate visibility check did not wait for asynchronous search completion. Saved failure HTML already contained the expected state. | Wait for the expected heading, then retain the visibility assertion. No production change was needed for this timing failure. |
| V002-W4 | Opening breadcrumb parents by pointer left the menu closed. | Pointer hover opened details, then its native summary click immediately toggled it closed. | Preserve the first click on a hover-opened menu while retaining subsequent clicks and keyboard activation. Pointer open and Escape checks passed. |
| V002-W5 | Screenshot review showed a squeezed upload card and an obsolete JSON formatting error after editing. | The later inline-form rule won the display cascade; tool feedback persisted across input changes. | Increase the form selector specificity and clear code-tool feedback on input. Both new assertions passed; new screenshots were inspected. |
| V002-WE1 | A resumed browser run aborted before any page opened; the unprivileged HTTP runner also stalled before test output. | Local execution restrictions on browser/process startup. | Reran the same suites with local process permission and isolated profiles/sites. All completed. No application code or expected results were relaxed. |

Local evidence is retained beside the application in `.local-sync/2026-10-26-v0.0.3/`. Final logs are `composer-final.log`, `javascript-final.log`, `browser-permitted.log`, `widgets-browser-final.log` and the `*-permitted.log` HTTP-suite logs. Earlier failed runs remain recorded separately. Screenshots are in `widget-screenshots/` and `browser-screenshots/`.

## Scope And Packaging

The runtime remains self-hosted and uses the existing authentication, CSRF, asset validation, ownership, visibility and revision contracts. No schema change was required. The 12px administration scale and public page typography remain as established earlier in this release.

This work does not repeat production PHP-FPM/TLS, real mail delivery, Safari or physical touch-device certification. G2 export/import, H1 feeds/metadata and J1 production-host certification remain pending. The updated CI configuration has not been run remotely during this work.

The rollup includes the full application source, tests, lockfiles and documentation. Installed databases, credentials, uploads, runtime state, logs, caches, vendor packages and Node modules are excluded.
