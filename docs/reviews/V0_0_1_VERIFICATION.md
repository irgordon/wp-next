# v0.0.1 Verification And Host Handoff

[Documentation Index](../README.md) · [Changelog](../../CHANGELOG.md) · [Approved Scope](../adr/V1_SCOPE.md)

Recorded October 26, 2026. This review concerns the supplied 160-file schema 8 rollup after changelog consolidation. Tests used new isolated installations. Existing user data was not tested or modified. Schema remains 8.

## Outcome

Five product boundary defects were independently reproduced and corrected. A stale maintenance instruction was also corrected. The final run passed 817 explicitly counted checks, plus PHP syntax, PHPStan level 5, the configured PHPCS subset and both JavaScript syntax checks. This is an assertion count across several suites, not a coverage percentage or proof that every possible input works.

The original package fails five of the six new regression checks with exit 1. The corrected code passes all six. This negative control establishes that the regression suite detects the original defects, rather than merely accepting the revised implementation.

G2 and H1 remain unimplemented. J1 remains open under the approved hybrid approach. Their approved choices are recorded in PROPOSALS.md and the scope ADR.

## Product Findings And Corrections

| ID | Reproduced Failure | Correction And Why |
| --- | --- | --- |
| F1 | A previously issued magic link still created a session after the feature was turned off. The response redirected to the administration area. | Check the feature setting when displaying/completing a magic link, and again while consuming it under the write lock. Turning off the sign-in method now applies to outstanding links. |
| F2 | A token expired after lookup was still consumed. | Require a future expiry in the consuming SQL update. The initial read is not authority for a later transaction. This is a deterministic transaction-boundary test, not a claimed live HTTP exploit. |
| F3 | A token whose account was disabled after lookup was still consumed. | Require an existing enabled user in the consuming update. Magic-link consumption and session insertion now share a transaction so disabling cannot interleave between them. The original failing test established the missing consumption check. |
| F4 | Deleting an author and reassigning their content to an account named `documents` changed an author-based permalink. An existing cached File link then returned another Post with HTTP 200 instead of the document. | Invalidate File-block renders and advance their render fence inside the reassignment transaction. The rerun regenerates `/?document=1` and downloads the intended bytes. |
| F5 | Eight synchronized token requests each read the old count and issued eight links against a five-per-hour budget. | Count under the same immediate transaction as issuance. Eight separate PHP workers now issue exactly five tokens and refuse three. A test-only barrier forces the old unsafe ordering without changing production source. |
| D1 | INSTALL.md said no upload garbage collector existed, contradicting the implemented maintenance sweep and the later asset section. | Describe pending recovery, canonical unreferenced-blob/staging cleanup and the 24-hour grace period. Referenced bytes, unknown files and symlinks remain protected. Off-host retention is still an operator responsibility. |

All product changes were made after the failing behavior was reported to the user. No PHPStan baseline, error suppression, weakened assertion or web-security bypass was introduced.

## Executed Final Gates

| Check | Result |
| --- | --- |
| `composer check` | Exit 0: PHP syntax, PHPStan zero errors, configured PHPCS subset, PHP smoke 27, hardening 18, asset lifecycle 30 |
| Accounts | 60 original account checks plus 6 new boundary checks |
| General HTTP | 53 |
| Documents | 103 |
| Images | 47 |
| Permalinks | 96 |
| Profiles And Taxonomy | 43 |
| Design And Managed Files | 45 |
| Asset Integration And HTTP | 6 + 9 |
| Upgrade And Migration | 4 + 3 |
| Browser | 92, with web security enabled; includes installation, publishing, editing, visual editor, design/file drafts, document selection, revisions and mobile workflows |
| JavaScript Syntax | Both administration scripts, exit 0 |
| nginx Static/Handler Fixture | 28 |
| Apache Static Fixture | 22 with AllowOverride None |
| Independent Adversarial Probes | 40 |
| Concurrency And Crash Injection | 7 |
| Local nginx/Apache → PHP-FPM Over TLS | 78, 39 per stack |

The 817 total sums the explicitly counted assertions above and excludes static-analysis results and deliberate negative-control failures. Repeated passes are not added to that total.

The 40 independent probes include invalid installation, installer write refusal after installation, private-document download/picker isolation, unauthorized references, action-bound CSRF, malformed intake, quota failure, missing-byte dedupe repair, commit-time document-access withdrawal, historical document-prefix routing, theme health timeout and cleanup, backup symlink refusal, body preservation around inaccessible File blocks, oversized content, excessive nesting, permissions and the complete Trash/restore/permanent-delete lifecycle.

The concurrency probes used 12 PHP processes creating the same title, then 10 editors submitting the same expected revision. All creates committed with unique slugs; exactly one editor won. Three separate SIGKILL injections stopped a writer after queue insertion but before commit. Both the Post and queue insertion rolled back each time. SQLite integrity, foreign keys and immediate public service remained intact. These are short fault tests, not a long-running soak.

The browser suite was executed on a blank installation through PHP's development router. It is separate from the live FPM HTTP matrix. No claim is made that the entire browser suite ran against both FPM stacks.

## Live Deployment Evidence And Limits

PHP CLI and PHP-FPM: 8.4.27. Linked SQLite: 3.45.1. nginx: 1.24.0. Apache: 2.4.58. PHPStan: 2.3.2, level 5. Installed development package versions matched composer.lock. PHPCS listed WordPress, WordPress-Core, WordPress-Docs and WordPress-Extra as installed; the project still checks only its configured formatting subset.

Topology: HTTPS client → nginx or Apache → real PHP-FPM over a loopback TCP FastCGI listener, with four FPM workers. Each stack used its own blank source/database/runtime copy. Configurations derive from INSTALL.md, adapted for local paths, ephemeral ports, a locally generated certificate and TCP instead of a Unix socket. nginx received explicit standard FastCGI request parameters because its extracted runtime did not contain the usual include file. Clients trusted that certificate explicitly and checked the localhost name. Certificate trust checks were not globally disabled.

Both stacks exercised invalid/valid installation, login, Secure/HttpOnly cookies, anonymous account refusal, missing CSRF, short Post creation and canonical redirect, long Page/custom slug, drafts, public/private documents and denial headers, invalid UTF-8/PDF/PHP uploads, authenticated visual preview, sensitive-path denial, exact 25 MiB intake, rejection one byte over, raster intake/static headers, editing, stale-write refusal, Trash, restore and permanent deletion. Database integrity passed on both.

The process environment only maps the root identity. nginx and FPM therefore ran as root for this isolated test. Apache single-process mode logged that it could not change to GID 65534. This does not verify production least-privilege execution, ownership or ordinary worker privilege changes. The test certificate is not evidence of a publicly trusted CA chain. Apache also warned that the test certificate carried CA constraints and that a TLS session cache was not configured. Neither warning is hidden or represented as a production-ready TLS configuration.

The nginx static fixture intentionally reaches a nonexistent FastCGI backend and accepts a 500/502 as routing evidence for allowlisted endpoints. Those responses are not application failures or successful FPM responses. Only the separate real-FPM matrix establishes successful application execution.

## Individual Environment And Harness Failures

These remain recorded even though corrected runs passed.

1. Inspection attempted nonexistent `wp-includes/accounts.php`; sed failed. The relevant files are account-tokens.php, user.php and mail.php.
2. A subsequent search used nonexistent `wp-includes/account.php`; rg reported an I/O error. This was another inspection mistake.
3. Inspection attempted nonexistent `wp-admin/account.php`; sed failed. Account flows are handled by the actual login/users endpoints.
4. Inspection attempted nonexistent `wp-includes/profile.php`; rg failed. The source file is profiles.php.
5. The usual `nginx/etc/nginx/fastcgi_params` path was absent in the extracted runtime; ls failed. The live harness supplies explicit standard parameters.
6. The first reassignment probe omitted expected_revision from wp_update_post. The facade rejected it with “Content ID and expected revision are required.” Adding the correct expected revision exposed F4; the rejected setup itself was not a product defect.
7. The first live nginx startup attempted chown to UID 65534 and failed with Invalid argument. The container cannot map that identity. The isolated rerun uses root and retains the certification limitation.
8. The next nginx startup attempted its absent `/var/lib/nginx/proxy` directory and failed. The harness now assigns all temporary paths within the test directory.
9. Reading the nginx error.log after that startup failure also failed because the log did not exist. The startup log contains the original error.
10. The invalid-install assertion assumed a users table existed. SQLite reported “no such table: users” because validation had correctly stopped before schema installation. The corrected assertion permits no table or an empty table.
11. The first plain-alias assertion used hardcoded ID 2, which belonged to Sample Page. Short post was ID 3. The corrected test resolves its created Post and asserts both 301 and its exact canonical Location.
12. One live run returned exit 0 but its saved evidence stopped at 45 assertions without the completion line. Server logs showed later requests, but that did not establish their assertion results. This incomplete run was not counted as a full pass. A completed synchronous capture subsequently recorded 58/58, then the expanded final run recorded 78/78. The capture discrepancy's underlying cause was not established.
13. Early exploratory scripts could print FAIL while returning exit 0. Their results were assessed from the individual assertions, not that exit code. The committed regression suite exits 1 on a failed check, and the original-package negative control verified that behavior.

The final application gate runs did not produce a new tool failure. Expected validation refusals and fault-injection error messages remain in logs; they are asserted test outcomes, not silently ignored failures.

## Hybrid J1 Target-Host Checklist

J1 stays open. Complete the deployment document's full ten-row matrix on both target stacks after G2 and H1 are implemented. The local 39-per-stack sample covers portions of that matrix, not all ten rows.

| Required Host Work | Evidence To Retain |
| --- | --- |
| Run PHP-FPM and both web servers under actual non-root service identities, with deployed source read-only and correct private/public storage ownership. | Service unit/pool identity, permission checks and failed unauthorized reads/writes. |
| Use the production FastCGI transport and intended proxy topology. | Final nginx/Apache/FPM configuration, peer-address handling and denial of direct backend access. |
| Validate the trusted certificate chain, hostname, redirects and HTTPS detection. | Client verification and rejection of spoofed Host/forwarded headers. Do not use insecure client flags as a pass condition. |
| Repeat the complete setup/authentication and content lifecycle rows. | Valid/invalid setup, logout, revoked-cookie replay, previews, stale editors, permissions and deletion. |
| Complete upload/delivery coverage. | Exact limits and multipart overhead, no-JS mixed batches, HEAD, pending/missing/corrupt assets, owner/other-user denial and static headers. |
| Complete routing and stale-worker coverage. | Current/history/dynamic structures, visibility withdrawal and author-reassignment File links. |
| Complete browser/design/CSS coverage on the deployed stacks. | Live preview POST, private previews, publication, rollback, timeout cleanup and mobile checks. Safari and real touch-device checks remain host work. |
| Test the actual SMTP TLS transport and account flows. | Certificate verification, invitation/reset/magic replay/expiry, disabled-feature refusal, failure reporting and reassignment. The local SMTP sink tests establish application behavior, not real mail-provider delivery. |
| Build and test G2 and H1 before release. | Native round-trip, WXR, hostile archives/XML, retry/quota/unsupported results; feed/sitemap/metadata eligibility and indexing controls. These features cannot be certified before implementation. |
| Exercise operational recovery and monitoring. | Real backup restore with assets/key intact, upgrades/maintenance sealing, scheduled workers/maintenance, off-host retention, alerts, storage limits and an appropriate soak run. |

For each row, retain versions, commands, service identities, topology, expected/actual results, first failures and rerun evidence. A passed local sample does not waive a remaining host test. The earlier intermittent theme-settings failure did not recur here; its original cause remains unresolved.

## File Changes And Why

Against the supplied 160-file package: **2 added, 10 revised, 0 removed**, resulting in 162 packaged files. No schema migration or dependency-lock change is needed.

| File | Change And Purpose |
| --- | --- |
| wp-includes/account-tokens.php | Atomic link budget and commit-time expiry/account/policy checks. |
| wp-admin/login.php | Enforce the disabled magic-link setting and create magic sessions inside consumption transactions; report unavailable links accurately. |
| wp-includes/user.php | Invalidate cached File URLs during author reassignment. |
| tests/account-boundaries-http.py | New six-check regression suite, including the token race. |
| composer.json | Include the regression suite in the existing account test command and CI path. |
| CHANGELOG.md | Preserve v0.0.0 and add v0.0.1 with the fixes, reasons and approved pending choices. |
| PROPOSALS.md | Record the user's G2/H1/J1 rulings and distinguish proposal, baseline evidence and new verification. |
| docs/adr/V1_SCOPE.md | Preserve approved implementation choices in the governing scope. |
| docs/README.md | Link this review and identify subsequent changelog entries. |
| docs/INSTALL.md | Correct the maintenance cleanup instruction. |
| docs/QUALITY.md | Describe the new regression gate and limited local FPM evidence. |
| docs/reviews/V0_0_1_VERIFICATION.md | This evidence, failure ledger, inventory and host handoff. |

The application archive excludes installed dependencies, test databases, uploaded fixtures, secrets, certificates/private keys, runtime files, logs and analysis caches. Detailed diagnostic logs and session harnesses are supplied separately as evidence. They retain session paths and require adjustment before reuse elsewhere.
