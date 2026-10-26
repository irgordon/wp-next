# WAL connection guarantee — review

Route 1 as ruled: WAL initialization at the database layer, consistent upgrade
backups, focused verification, documentation. Gutenberg adoption and user
deletion are outside this update. No checkpoint scheduler was added.

## What the audit found first

Two starting assumptions did not survive contact with the tree:

- Installed sites already ran in WAL — but only because the *installer* set
  `PRAGMA journal_mode=WAL` once, at install time. `WP_SQLite_DB`, the class
  every serving and CLI connection actually uses, never requested or verified
  the mode; it inherited whatever the file header happened to say. A database
  created or restored by any other path would have served silently in rollback
  mode. (An existing probe database confirms the mechanism: it reports `wal`
  to connections that never ask, because WAL persists in the file header.)
- Upgrade backups were already consistent. `wp_create_backup()` snapshots via
  `VACUUM INTO`, never by copying the live file. No change was needed; the
  behavior was re-verified under load instead (below).

## Files modified

| File | Change | Behavior it fixes |
| --- | --- | --- |
| `wp-includes/class-wp-sqlite-db.php` | The constructor applies the existing pragmas first (foreign keys ON, `busy_timeout` 3000, cache size), then requests `PRAGMA journal_mode=WAL` for every file-backed database and verifies the returned mode. `:memory:` databases are exempt (they return `memory` and cannot use WAL). The explicit `synchronous` setting is unchanged. Failure contract, stated precisely: when the pragma *returns* a mode other than `wal`, the constructor throws `Database_Exception` ("SQLite could not enable WAL mode."); when the pragma query itself fails, PDO's exception mode surfaces a `PDOException` first. Both fail closed, with different types — no caller in the tree catches `Database_Exception` distinctly (callers catch `Throwable`), so no wrapper normalizes them. Applying `busy_timeout` first means the WAL request itself runs under the connection's explicit 3000 ms contention policy instead of the PDO SQLite driver's own default (60 s — verified against this PHP build: a fresh PDO connection reports `PRAGMA busy_timeout` = 60000). | Every connection — web, CLI, worker, backup — enforces WAL instead of inheriting install-time state; a filesystem that cannot do WAL fails closed at connection time rather than silently serving in rollback mode. |
| `wp-admin/class-wp-installer.php` | Removed the installer's own one-off `PRAGMA journal_mode=WAL` check, now subsumed by the database layer. | Single enforcement point. Error surface change: a WAL failure during install now surfaces as `install_failed` instead of the dedicated `wal_mode_failed` code (nothing else referenced that code); the server log carries the underlying message — "SQLite could not enable WAL mode." when the returned mode was wrong, or the `PDOException` message when the pragma query itself failed. |
| `tests/smoke.php` | New check: a database deliberately created in rollback mode (`journal_mode=DELETE`, with a row in it) is converted to WAL when opened through `WP_SQLite_DB`. | Regression coverage for the connection-level guarantee, next to the existing "WAL is enabled" assertion. Suite total 26 → 27 checks. |
| `docs/ARCHITECTURE.md` | "WAL is asserted during installation" → WAL is enabled and verified by the database layer on every connection; a file-backed database that cannot enter WAL fails closed. | Documentation matches the enforcement point. |
| `docs/INSTALL.md` | Installer checklist sentence updated the same way. | Same. |
| `docs/WAL_REVIEW.md` | This file. | Change summary. |

## Audited, no change needed

- **Upgrade backups** — `wp-includes/operations.php` already snapshots with
  `VACUUM INTO` and embeds that snapshot in the archive; the `maintain`
  command's passive checkpoint is housekeeping, not part of the backup path.
- **Job queue** — `claim_job` is a single atomic `UPDATE … WHERE id=(SELECT …)
  RETURNING`; outbox writes require the caller's content transaction, and
  `WP_SQLite_DB::transaction()` opens with `BEGIN IMMEDIATE`, so there is no
  deferred read-then-write upgrade window. Completion, release, and lease
  sweeps are single guarded updates. With WAL plus `busy_timeout` 3000, claims
  serialize on the one writer lock with bounded waiting. No gap found.
- **Deployment guidance** — the local-filesystem requirement was already
  specified (ARCHITECTURE: one host, reliable local storage, probes do not
  certify a filesystem; INSTALL: restore warnings against copying a live
  database). Only the two sentences above changed, for accuracy.

## Verification (PHP 8.4.23, SQLite 3.45.2, scratch copy of the updated tree)

- Focused WAL probe, 13/13, plus an ordering probe: with another connection
  holding an exclusive lock on a rollback database, construction waited ~1 s
  under the busy timeout and then converted to WAL — confirming `busy_timeout`
  is applied before the WAL request. (The probe demonstrates the wait under the
  explicit 3000 ms policy; it does not establish that the original ordering
  would have failed, since the WAL request then ran under the driver's 60 s
  default.) A new database is in WAL via `WP_SQLite_DB` alone;
  foreign keys, 3000 ms busy timeout, and synchronous NORMAL are retained; a
  rollback fixture database is converted on open with rows intact, and the
  conversion persists for later connections that never request it; a reader
  with `busy_timeout=0` completes during an open queue-write transaction and
  beside an exclusive writer, while the control read is refused once a
  rollback writer holds the exclusive lock; `:memory:` still constructs.
- Backup under WAL: with a committed post present *only* in the `-wal` sidecar
  (a copy of the main file alone showed zero marker rows), `bin/wp backup`
  produced an archive whose restored `site.sqlite` passes
  `PRAGMA integrity_check` and contains the marker post.
- Full suites: `composer check` exit 0 (smoke 27, hardening 18, PHPStan and
  PHPCS clean), HTTP 53, profiles/taxonomy 43, migration regressions pass,
  permalinks 96, upgrade suite pass set, browser 60.
- Server delivery suites: initially blocked in this sandbox (a VM replacement
  removed the apt-installed servers and the package manager was held by a
  system process at first verification). Once the servers were restored, both
  were run against the updated tree: **22 Apache delivery checks passed** and
  **26 nginx delivery/rate checks passed**.
