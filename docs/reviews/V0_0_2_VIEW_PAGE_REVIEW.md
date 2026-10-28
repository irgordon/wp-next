# v0.0.2 — View the live Page in a new tab

Recorded October 28, 2026. Release remains v0.0.2; schema remains 8.

## Delivered behavior

Published Pages now show View Page beside Update Page in the workspace visual editor. It is a native link styled as a button, using the saved record's public permalink. It opens in a new tab and remains available when reopening or updating a published Page. Drafts, archives and failed publication attempts do not expose the action.

The workspace keeps its current URL, Page selection, editor scroll, unsaved Page content and private design settings. Viewing does not submit the form or apply pending edits. The live tab shows saved public content with the published design. Preview remains a distinct action.

## Implementation boundaries

- Published-state eligibility is a pure policy function. The native view obtains the public URL through the existing `wp_post_url()` facade and escapes it at output; no preview or workspace token is included.
- The link uses `_blank`, `noopener` and `noreferrer`, with an accessible label announcing the new tab. The existing embedded-editor link handler already permits native new-tab links; no new navigation handler, mutation endpoint or permission relaxation is required.
- Toolbar placement is an optional DOM operation. Preview selection explicitly excludes the live-view link, so the two actions retain their separate destinations. The builder module's content version was refreshed.
- The new functions keep publication policy separate from DOM placement. No abstraction mismatch was found in the added functions. Existing Page/design save, nonce, revision and publication behavior is unchanged.

## Executed verification

Local macOS with PHP 8.4.27, SQLite 3.54.1, Node 26.12.0 and headless Microsoft Edge through Playwright 1.62.1. Web security remained enabled. Checks install disposable sites; no real local-site Page was created or published for verification.

| Gate | Result |
| --- | --- |
| `composer check` | Pass: syntax, PHPStan level 5 with zero errors, configured PHPCS subset and 87 PHP assertions |
| `npm run check:js` | All 16 administration scripts pass syntax checks |
| `python3 tests/design-pages-http.py /absolute/path/to/php` | 76 Page-workspace assertions pass |
| `npm run test:pages:browser` | 59 assertions pass; zero JavaScript page errors |
| `npm run test:browser` | 115 assertions pass; zero JavaScript page errors |

Total: 337 PHP/HTTP/browser assertions, with syntax, analysis and formatting as additional gates. The broader design, widget and preview results from prior changes remain attributed to their earlier records; this follow-up reruns the affected Page flow and shared-editor regression suite.

The HTTP checks prove published-only availability, absence after a failed publication, the actual public permalink, no preview tokens, new-tab attributes and the accessible label. Existing Page creation/publication, CSRF, permission, revision and rollback checks remain in that suite.

The browser check clicks View Page while the canvas has unsaved edits. It observes a real new tab at the public URL showing the earlier saved content, verifies a null opener and empty referrer, then closes that tab and confirms the original workspace/editor URLs, canvas content, scroll and design controls are retained. It checks unchanged Page revision and design persistence, no leave prompt, a separate Preview action, availability after updating and fit at 320px/390px. Desktop and mobile screenshots were inspected.

All affected checks passed on their first run. Evidence is outside the distribution in `.local-sync/2026-10-28-view-page/`: `composer.log`, `js.log`, `http.log`, `browser-first.log`, `general-browser.log` and `browser/` screenshots. The existing CI commands already run the extended Page suites. These results are local regression evidence for the stated scope.
