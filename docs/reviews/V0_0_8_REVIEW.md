# v0.0.8 — Page Save/Return Acknowledgement

Recorded October 29, 2026. Repairs the CI failure on `0d35269` after Save Draft → Back to list. Schema remains 8.

## Cause And Change

The iframe's server-rendered `data-saved=1` appears before the replacement document's JavaScript has notified the parent. The parent previously guarded navigation using its cached dirty flag. A quick Back click could therefore open a stale unsaved-edits dialog, leaving the Page panel hidden while the suite waited for its renamed row.

Navigation now requests an explicit state snapshot from the active trusted editor. The child answers only after initialization and while not submitting. A requested action waits during a save or module load; the replacement document's ready message reconciles saved title/status/list position before evaluating the pending navigation. Genuine dirty state still opens the existing keep/discard dialog.

Per-document identifiers and retired-document fencing prevent older state/snapshot/close messages from overriding a replacement document. Same-origin, source-window, channel and Page-ID checks remain. The unavailable-editor path reports its error and permits guarded return without waiting for a missing bridge. No save, revision, publication or permission controller was changed.

## Deterministic Regression

The Page suite intercepts the replacement bridge module and holds it behind a test-controlled promise. It waits for the original server saved flag, clicks Back, asserts no stale dialog and a still-mounted editor, then releases the bridge and requires the renamed row to become visible. This fails on an isolated `0d35269` snapshot with the original stale-dialog assertion and passes on the repaired code. No arbitrary sleep or increased timeout was added.

The existing dirty-edit, discard/keep, Page publication, private design, live-tab, responsive and persistence checks remain. A refused editor load additionally proves the user can return rather than wait forever.

## Local Verification

PHP 8.4.27, Node 26.12.1, Playwright 1.62.1 and headless Microsoft Edge with web security enabled. All HTTP/browser cases use disposable installed sites.

| Gate | Result |
| --- | --- |
| Composer syntax/PHPStan level 5/configured PHPCS subset/PHP tests | Pass; zero analysis errors; 87 assertions |
| JS syntax + document/widget models | Pass; 16 scripts parse; 49 assertions |
| Design/file/private-preview/Page HTTP | Pass; 149 assertions |
| Portability acceptance + regressions | Pass; 95 assertions |
| General browser | Pass; 115 assertions; zero page errors |
| Widgets browser | Pass; 62 assertions; zero page errors |
| Private preview browser | Pass; 29 assertions; zero page errors |
| Page workspace browser | Pass; 62 assertions; zero page errors |

Total: 648 positive assertions. The final unavailable-editor branch and document-ID implementation were verified by the Page suite and JS syntax after the other gates passed. The regression also passes on a separate earlier repaired run and fails against the original snapshot.

The first controlled-route test released and unregistered its handler simultaneously, causing a harness “Route is already handled” error. It now waits for the continued route before unregistering; the behavioral assertions remain unchanged. Evidence is outside the distribution in `.local-sync/2026-10-29-page-return-fix/`, including `baseline.log`, `pages-delivery.log` and the named suite logs.

This closes the demonstrated save/return race. It does not claim to resolve the separately documented intermittent theme-settings observation or J1 host-side PHP-FPM/HTTPS certification.
