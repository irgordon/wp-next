# v1.0.0 Deployment Requirements

[Scope Index](README.md) · [Approved Scope](adr/V1_SCOPE.md)

Status: required deployment/verification contract for the approved v1.0.0 scope. Existing static fixtures are useful evidence but do not yet certify the complete production stacks.

## Supported Layout

One application host with reliable local persistent storage, SQLite WAL and working advisory locks. nginx and Apache with PHP-FPM are both release targets. The application may run multiple PHP workers; SQLite still serializes writes. Multi-host writable storage, network-filesystem behavior and horizontal database scaling are not certified by this release.

Use HTTPS. A trusted TLS terminator may be used only with explicit server/FastCGI configuration that the application recognizes. Do not trust arbitrary client forwarding headers to select the canonical host or assert HTTPS.

## PHP And SQLite

| Requirement | Purpose |
| --- | --- |
| PHP 8.4 or newer, PHP-FPM and matching CLI | Web execution plus upgrades, queue processing, maintenance and backup |
| SQLite 3.45 or newer, as actually linked to PHP PDO SQLite | Database semantics and WAL; a separate sqlite command's version is not sufficient |
| dom, libxml, pdo_sqlite, fileinfo, gd, exif, tokenizer | Content validation, persistence, media intake and trusted template syntax checks |
| GD with JPEG, PNG, WebP and GIF support | Existing raster processing and approved B1 transforms |
| Phar in the CLI | Backup archives |
| proc_open and a reachable PHP CLI | Isolated theme publication health check; set WP_PHP_CLI when the service PATH differs |
| OpenSSL/TLS support for SMTP | Approved email transport and optional magic-link delivery |

Use the same database, secret, runtime paths, relevant extensions and configuration for FPM and CLI. FPM pools commonly need explicit environment configuration. Additional runtime dependencies introduced by implementation must be declared and checked by installation; no hidden dependency assumptions.

## Upload And Processing Limits

- Set PHP upload_max_filesize to at least 26M and post_max_size to at least 32M.
- Set nginx client_max_body_size to at least 32m. Configure Apache/proxy request limits to admit the same body.
- The document application ceiling is 25 MiB per file. The larger server limits allow multipart overhead; they do not raise that application ceiling.
- The enhanced uploader submits one file per request. Without JavaScript, selected files share a single request. Raise total POST/proxy allowances for larger batches or clearly document the supported batch ceiling.
- Existing image limits are 10 MiB and 12 megapixels, with a 12,000-pixel side limit and additional memory checks. Derived variants must respect the approved processing limits.
- A 256 MiB PHP memory limit is an initial operating recommendation, not a measured capacity guarantee. Processing must reject inputs that exceed available memory. The trusted theme probe currently has a separate 128 MiB limit.
- Keep import/transform work bounded. Configure worker concurrency, total disk limits and timeouts from measured workload; do not solve timeouts solely by setting unbounded execution time.

## Permissions And Storage

The PHP service account needs write access to private runtime state, staging, the protected document tree, public image uploads and required temporary directories. Keep source, theme PHP and configuration read-only to that account where practical. The current default runtime is the in-tree var directory, not inherently outside the webroot; moving it outside the served tree is recommended. Otherwise the supplied denial rules are mandatory.

Keep public image storage separate from protected documents. A custom document root must be private or explicitly denied by the web server and must not overlap uploads. Preserve the application's restrictions on canonical storage paths and symlinks.

Provision disk space for registered assets, derived variants, staging, retained unreferenced bytes, WAL, snapshots and backup archives. Registered quota is not a physical disk limit. Monitor disk space separately and test refusal/recovery near capacity.

## Secrets And Mail

Persist a unique per-site secret through deployments. Use the existing private file or operator-managed secret configuration. Do not ship credentials in source archives. Protect SMTP credentials and use TLS with certificate validation.

E1 requires an administrator-configured SMTP host, port, authentication policy where needed, sender address and a working delivery path. Display mail configuration/delivery status to administrators. Verify invitations, reset and magic-link delivery in a controlled mailbox; keep a recovery method that does not depend on a functioning mail service.

## Worker, Maintenance And Backups

Schedule bounded queue processing and maintenance under the same service account/environment as FPM. Alert on failed/stalled work rather than letting it disappear from view. Define and document backup frequency, retention and operator ownership.

Back up the database and referenced ready image/document/variant bytes with the matching source/configuration and secret requirements. Keep a protected off-host copy. Incomplete registrations are explicitly not counted as recoverable files; restoration can lose an upload whose registration committed before promotion. Restore testing must verify cleanup, quota release and an audit event for unrecoverable pending records.

Restore with traffic and workers sealed. Restore the private document tree to its configured location, not into public uploads. Restore the matching site secret, apply the documented upgrade/session-revocation procedure, then verify authorization, content and asset integrity before reopening traffic.

## Web Server Configuration

### nginx With PHP-FPM

Use the explicit PHP entrypoint allowlist, sensitive-path denials, static image headers and front-controller routing from the shipped configuration. Include the approved file-editor endpoint. Protected assets, runtime state, dependencies, source internals, lock files, documentation and command scripts must not be downloadable or executable through HTTP.

### Apache With PHP-FPM

Enforce the equivalent rules in the virtual host with AllowOverride None; do not rely on an installation silently honoring .htaccess. Configure the PHP-FPM handler and required modules from the final documented configuration. Verify both refusal and allowed static delivery through that vhost.

## Required Certification Matrix

Run the following on both stacks, using actual PHP-FPM and documented HTTPS behavior:

1. Blank setup, invalid setup, login, logout and revoked-session replay.
2. Post/Page creation, editing, conflict handling, previews, trash/restore and permitted deletion.
3. Valid and invalid image/document upload, exact size boundaries, multipart overhead and no-JavaScript batch handling.
4. Public/private download, HEAD, missing/pending/inaccessible assets, fixed delivery headers and storage integrity failures.
5. Current/historical/dynamic permalinks and cached File links across routing/visibility changes, with stale-worker fencing.
6. Live editor behavior, design/CSS preview isolation, publication, rollback and health failure/timeout.
7. Invitations, email/password sign-in, optional magic-link completion/replay/expiry, reset and reassignment/deletion.
8. Native round-trip and WordPress import, hostile input, partial failure and retry behavior.
9. Feeds, sitemap and metadata exclusion of unpublished/private content.
10. Upgrades and real backup restoration with state/assets intact; maintenance sealing and error responses.

Record versions, commands, service identity, topology and resource settings. Report first failures individually before rerunning. Existing PHP development-server/browser tests and static server fixtures remain separate evidence; they do not substitute for this matrix.
