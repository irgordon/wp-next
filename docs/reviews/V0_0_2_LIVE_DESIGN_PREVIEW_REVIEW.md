# v0.0.2 — Private live site preview

Recorded October 27, 2026. Schema remains 8.

## Delivered behavior

Site design now presents the current controls beside a live rendering of the actual site homepage on wide screens. Theme, color, typography, width, spacing, logo, homepage, navigation and shared-section changes are submitted after a 300ms pause. This rendering path does not create a design revision or update published options.

The selected installed theme's PHP functions load before rendering. The public template hierarchy, Page-template allowlist, content renderer, navigation helpers, logo and published managed CSS supply the page. Desktop uses a 1440px viewport and Mobile uses 390px, scaled to the available preview area. Narrow screens offer Design controls / Live preview views without replacing the form. Selecting a design section returns to its controls.

The frame is read-only: scripts, forms, popups and top navigation have no sandbox permissions. Parent-side handlers prevent navigation, dropping content and submission; preview form fields are read-only. Refresh retains scroll for the same theme/homepage after fonts and layout settle. Switching theme or homepage starts at the top.

Queued changes immediately mark the previous rendering as not current. Invalid settings and failed requests retain it with an explanatory alert, while controls keep their values. Refresh retries. Response sequence and render-ID checks prevent older or canceled requests from replacing the latest result, including when transport cancellation is ignored.

Save draft and Publish design remain separate, explicit native actions. Saving a draft adds one private revision; publication uses the existing version-checked controller and theme-health process. The main admin groups, curated-template scope and deferred Patterns decision remain intact.

## Privacy and architecture boundaries

- The existing `themes.php` route handles the read-only `live_preview` POST action. It uses its own `design-live-preview` nonce; preview and persistence nonces cannot authorize each other's actions.
- Authentication, administrator capability, request size and expected live version are checked before loading the submitted theme's PHP functions. Version/capability and actual rendered-theme checks are repeated before returning the rendering.
- The effective configuration exists only in request-local option/design overrides. It is not stored in a URL, a draft row, a public file or a reusable preview token.
- The response is JSON with private/no-store, cookie variation and no-index headers. Existing global `X-Frame-Options: DENY` and CSP frame-ancestor restrictions remain unchanged. The browser uses `srcdoc` rather than embedding the public route.
- Active HTML content and refresh directives are removed. The generated document receives a restrictive policy that forbids scripts, connections, forms, objects and nested frames; styles, fonts and images remain site-local.
- Workspace script and administration stylesheet URLs include source modification versions so a reload does not retain the old change-notification code.
- A shared input adapter normalizes preview and persistence from the same native form shape. Existing authoritative validation and publication contracts remain in use. The legacy value-retention code in the form controller remains responsible for redisplaying submitted fields.
- Privacy covers the unsaved configuration and rendered preview. Existing media visibility rules are unchanged. Published custom CSS participates in the actual cascade; file-editor CSS drafts retain their independent workflow. Installed theme PHP remains trusted operator code, as elsewhere in the application.

## Executed verification

Environment: macOS, PHP 8.4.27, Node 26.12.0 and headless Microsoft Edge via Playwright 1.62.1, with web security enabled. HTTP/browser suites install disposable sites, use their own databases and credentials, and remove those fixtures after completion. The real local site's content/design is not used by these tests.

| Gate | Result |
| --- | --- |
| `composer check` | Pass: syntax, PHPStan level 5 with zero errors, configured PHPCS subset; 87 PHP assertions |
| `npm run check:js` | All 14 administration scripts pass |
| `composer test:design` | Existing 45 design/file checks and 28 new live-preview HTTP checks pass |
| `npm run test:browser` | 115 checks pass; zero JavaScript page errors |
| `npm run test:widgets:browser` | 62 checks pass; zero JavaScript page errors |
| `npm run test:preview:browser` | 29 checks pass; zero JavaScript page errors |
| Source/documentation inspection | Valid local links, clean diff whitespace, no new web-server endpoint or global frame-policy relaxation |

Total: 366 passing PHP/HTTP/browser assertions, with static analysis, formatting and syntax as additional gates. The final viewport, copy, read-only-field and font-stable scroll changes were verified by the dedicated preview suite after the two existing browser suites passed.

The new HTTP suite proves theme-function loading, actual template/token/homepage/menu/section output, published-homepage isolation, response headers and active-content removal. It compares options, design revisions, posts and media before/after valid and rejected previews and checks byte-identical anonymous output. It also covers missing/wrong-action nonces, nonce misuse for publication, stale versions, traversal, contrast and shared-section limits, capability revocation, then positive explicit publication.

The browser suite exercises changes through the real controls and checks the frame's rendered CSS and content. It verifies read-only behavior, navigation refusal, scroll retention, error/retry recovery, actual viewport widths, visible content-width changes, responsive control retention and a deliberately delayed older response with cancellation disabled. Database and anonymous-output checks prove no persistence until explicit draft saving/publication. Desktop and mobile screenshots were inspected.

## First failures and corrections

| Observation | Correction |
| --- | --- |
| The first HTTP fixture tried to revoke a nonexistent `role` column. | Use the application's actual JSON capabilities field, restore it after the check, and retain the valid-nonce capability-revocation assertion. No application permission code was weakened. |
| A browser predicate read `documentElement` while `srcdoc` temporarily had an empty document. | Guard the test against that intermediate state and wait for the real render. The application already checks its render marker. |
| A 960px desktop preview hid useful changes near the upper content-width range. | Use 1440px for the site-wide desktop viewport and verify that width changes alter actual layout. The separate Post/Page builder retains its existing dimensions. |
| The scroll check measured 284px after restoring 250px because a late font swap moved the layout. | Wait for the frame's font set and an animation frame before restoring scroll and marking it current. The original scroll assertion passes. |

Evidence is retained outside the application in `.local-sync/2026-10-27-live-preview/`. Completed logs include `composer-complete.log`, `design-final.log`, `http-verified.log`, `browser-existing.log`, `widgets-existing.log` and `browser-delivery.log`; earlier failed/intermediate runs remain separate. Screenshot evidence is in `browser-preview/` and the existing-suite directories.

The updated CI commands include both new suites. These local results do not certify production PHP-FPM/TLS, physical touch devices, Safari or third-party theme/plugin compatibility. Patterns remains deferred in its existing review.
