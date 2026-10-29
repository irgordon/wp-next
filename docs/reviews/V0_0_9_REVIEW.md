# v0.0.9 — Local PHP-FPM And HTTPS Verification

Recorded October 29, 2026. Based on remote/local `61197da` (v0.0.8), including
the earlier `0d35269` cache corrections. No application behavior, schema,
installed database, uploads or credentials changed. Schema remains 8.

## Outcome

The repeatable deployment gate runs actual application requests through both
documented server templates and PHP-FPM, using direct HTTPS and explicitly
trusted TLS termination. Each topology starts with a blank installation, then
restores its actual backup into another fresh site before checking HTTPS
delivery again. This supplies current local J1 evidence; the hybrid target-host
certification contract remains open.

## Runtime And Topology

- macOS, ordinary user UID 501/GID 20. nginx 1.31.8; Homebrew Apache 2.4.69;
  PHP CLI/FPM 8.4.27; PHP's linked SQLite 3.54.1; Python 3.15.2;
  client/OpenSSL command 4.1.0. Node 26.12.1, Playwright 1.62.1 and Microsoft
  Edge provided the separate browser regression run.
- Direct mode: verified HTTPS client → nginx or Apache → private Unix FPM
  socket. A second loopback HTTP listener must return production HTTP 503,
  including with forged forwarding headers. `WP_HTTPS` is absent.
- Terminated mode: verified HTTPS client → disposable nginx TLS proxy →
  loopback nginx or Apache origin → private Unix FPM socket. The pool explicitly
  sets `WP_HTTPS=1`; forwarding headers do not control that value. The origin is
  trusted local infrastructure, not a production network-isolation test.
- FPM has four static workers, a mode-0600 socket, `clear_env=yes`, explicit
  production/runtime/database/CLI environment, 26M upload and 32M POST limits,
  256M memory and 30-second execution limits. nginx admits 32m request bodies.
  Apache uses `AllowOverride None` and the supplied denial/routing rules; an
  invalid fixture `.htaccess` confirms it is ignored.
- Separate temporary CA and non-CA localhost leaf certificate. Clients trust
  only the supplied CA for these requests and check the hostname. Negative
  controls reject both an untrusted CA and a hostname mismatch. No insecure
  client flags, system trust changes or global service starts were used.

The exact Mac command is documented in [Installation](../INSTALL.md#real-php-fpm-and-https-verification).
`--stack` and `--mode` can select a failing topology. Every normal/exceptional
exit stops its processes and deletes temporary site state and private keys.
Retained `.conf`/`.log` files include configuration validation, server/PHP
errors and actual master/worker identities. They contain no site databases,
uploaded bytes, archive files, certificate private keys or site secrets.

## Local Gates

| Gate | Result |
| --- | --- |
| Composer syntax, PHPStan level 5, configured PHPCS subset and PHP tests | Pass, zero analysis errors; 87 assertions |
| General HTTP, assets, documents, images, accounts, portability, discovery, permalinks and profiles | Pass; 600 assertions |
| Design/file/private-preview/Page HTTP | Pass; 149 assertions |
| Prior-schema upgrade and migration regressions | Pass; 7 assertions |
| nginx static/handler/rate fixture | Pass; 32 assertions |
| Apache static fixture with overrides disabled | Pass; 25 assertions |
| JavaScript syntax and document/widget models | Pass; 16 scripts parse; 49 model assertions |
| General/widgets/private-preview/Page browser suites | Pass; 115/62/29/62 assertions, zero JavaScript page errors |
| Real FPM/HTTPS and restoration | Pass; nginx direct 89, terminated 87; Apache direct 89, terminated 87; 352 assertions |

Total: 1,569 positive assertions across the final local gates. The earlier
328-assertion deployment pass predates the added controlled health, rollback
and maintenance checks and is not counted again.

The development-router/browser results are separate from real FPM evidence.
Repeated intermediate passes are not added to the final assertion total.
CI now uses setup-php's matching FPM and runs the same deployment gate, retaining diagnostic
artifacts even on failure. A local pass does not establish a remote CI result.

## Certification Coverage And Remaining Host Work

| Deployment Matrix Row | Local FPM Evidence | Remaining Production Coverage |
| --- | --- | --- |
| 1. Setup/authentication | Invalid/blank setup, Secure/HttpOnly sessions, logout and revoked-cookie replay | Production account recovery and complete admission/identity policy |
| 2. Content lifecycle | Post/Page creation, public/draft delivery, updates and stale-write conflict | Complete role, preview, Trash/restore/delete and concurrent editor matrix |
| 3. Intake | Images, invalid text/PDF/PHP, exact 25 MiB and one-byte-over document boundaries with multipart overhead; native mixed batch | Server/PHP total-body limits, larger batches, resource exhaustion and recovery |
| 4. Delivery | Public/private/other-author refusal, document HEAD, attachment delivery and immutable raster digest/headers | Pending/missing/corrupt storage and complete delivery fault matrix |
| 5. Routing | Pretty Post/Page and canonical redirect ignoring forged Host/forwarding host | Historical/dynamic routes, cached File links and stale-worker fencing |
| 6. Design | Visual-preview POST, private live preview, publication, health refusal/timeout, history rollback | Browser/live editing, CSS, mobile, Safari and real touch-device checks through deployed stacks |
| 7. Accounts/mail | Restricted-author creation/login and settings/private-document denial | Production SMTP TLS/delivery, invitations/reset/magic links and reassignment/deletion matrix |
| 8. Portability | Native export/import/checkpoint retry, WXR and hostile XML refusal | Fresh-target asset remapping, full archive/media boundary and partial-failure matrix |
| 9. Discovery | Feed/sitemap/robots routing and draft exclusion | Complete indexing/privacy/metadata combinations on target configuration |
| 10. Operations | Queue/maintenance, backups, actual offline restoration, upgrade/session revocation, content/image/public/private document/design/key verification and SQLite integrity | Dedicated service identities, source read-only permissions, prior-version FPM migration, derived assets/managed files, disk limits, scheduling, alerts, off-host retention and soak |

The local source copies remain writable to the current user. This does not prove
least-privilege production ownership. The local CA does not prove a publicly
trusted chain. Neither the local matrix nor CI substitutes for target-host
certification. The earlier intermittent theme-settings observation remains
unresolved; controlled health failures are deliberate probes, not its diagnosis.

## First Failures And Corrections

1. The first nginx configuration extraction split at `server {}` inside a
   documentation comment. nginx reported `unknown directive "."`. Extraction
   now anchors the actual newline-delimited server block. The first capture
   omitted configuration-test stderr; subsequent checks retain it before exit.
   Socket paths were also shortened to `/private/tmp` for portability.
2. Strict Python TLS verification rejected the initial test leaf's missing
   Authority Key Identifier, then the CA's missing key-usage extension. The
   generated certificates now contain those extensions; verification was not
   disabled or relaxed.
3. An initial assertion expected anonymous visual preview to return only 403.
   The existing authentication contract redirects unauthenticated requests.
   The test now accepts 302/403 and still requires no private preview content.
4. An initial native same-site import incorrectly assumed the original rows
   already carried import provenance. The test now checks initial preflight/
   import, then requires an unchanged content count on the identical retry.
5. Bundled macOS Apache 2.4.67 failed the TLS handshake with
   `TLSV1_ALERT_DECODE_ERROR` before PHP executed, including after matching the
   vhost hostname. Homebrew Apache 2.4.69 passed. The exact interoperability
   cause is unestablished; the bundled binary is not claimed as validated.
6. Two inspection commands referenced nonexistent historical backup/config
   paths; subsequent inspection used the actual operations/formatting modules.
   These were inspection mistakes, not application failures.
7. The first rollback assertion assumed the earliest published revision was
   the new design. It is the initial baseline. The test now verifies rollback
   removes the new header, then restores the later revision and requires its
   header to return. The application had correctly restored the baseline.
8. The first CI run on `6df0afa` ([run 40960438417](https://github.com/irgordon/wp-next/actions/runs/40960438417))
   passed the application checks, then failed package setup: Ubuntu 26.04's
   configured apt sources could not locate `php8.4-fpm`. The workflow now
   verifies and uses the matching FPM supplied by setup-php instead of trying
   to reinstall it from unrelated OS sources. setup-php's [runtime setup](https://github.com/shivammathur/setup-php/blob/main/src/scripts/linux.sh)
   and [package inventory](https://github.com/shivammathur/setup-php/blob/main/src/configs/php_packages)
   include FPM. This failure occurred before the HTTPS gate, not in application
   execution. The follow-up commit retains the v0.0.9 version and failure record.

Evidence is outside the source distribution in
`.local-sync/2026-10-29-j1/`: numbered first-run logs, native/Homebrew Apache
results, final deployment configurations/process identities and individual
regression logs. No application repair was needed for the demonstrated paths.
