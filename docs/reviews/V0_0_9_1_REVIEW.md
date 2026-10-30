# v0.0.9.1 — Page-Publication Browser Synchronization

Recorded October 29, 2026. Based on `5fd33e5` (v0.0.9). Schema remains 8.

Meta Muse reported that the raw Page-browser suite passed the repaired Save
Draft → Back to list flow, then read the parent's publication status before
the child editor's postMessage arrived. Its condition-waiting probe passed all
62 checks. That sandbox finding is reported evidence, not an independently
reproduced Mac failure.

Inspection confirms the assertion waited for the child's Update Page button,
then immediately read the parent status. The suite now waits for the actual
parent status to begin with `Published.` before evaluating the original
confirmation assertion. It uses the existing timeout, introduces no sleep and
changes no application code. The controlled delayed-bridge navigation
regression remains intact; this is a separate test synchronization correction.

Local validation on the final test change: Page browser 62/62, zero JavaScript
page errors; Page HTTP 76/76; 16 administration scripts passed syntax checks;
the changed browser script passed `node --check`; `git diff --check` passed.
These use disposable sites, PHP 8.4.27 and headless Microsoft Edge with web
security enabled. Logs are outside the distribution in
`.local-sync/2026-10-29-publication-assertion/`.

The previously completed v0.0.9 Mac/CI deployment evidence remains separate.
This follow-up changes neither J1's remaining target-host certification work
nor the unresolved intermittent theme-settings observation. The v0.0.9.1 push
runs the full existing CI workflow; its exact-commit outcome must be checked
separately from the local results above.
