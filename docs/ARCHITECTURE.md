# Architecture

## Runtime and boundaries

PHP 8.4+ and SQLite 3.45+ are enforced. DOM, libxml, PDO SQLite, fileinfo, GD, EXIF and tokenizer are mandatory; Phar is needed by CLI backups. The application uses request-scoped PHP-FPM/CLI processes. Idle memory depends on the host process pool, not an application zero-memory guarantee.

Configuration loads before localization and helpers, followed by PSR interfaces and dependent classes. A shared admission lock is acquired before database reads. Old installed schemas return a controlled 503 with upgrade instructions. Blank instances route to onboarding. Production HTTP requires trusted HTTPS configuration.

SQLite connections set foreign keys ON, busy timeout 3000 ms, synchronous NORMAL, and a cache target of approximately 16 MiB. WAL is enabled and verified by the database layer on every connection; a file-backed database that cannot enter WAL fails closed instead of silently serving in rollback mode. NORMAL protects consistency but does not promise durability of every latest commit across power loss; choose FULL where that durability is required. WAL and flock probes do not certify a filesystem: deployment requires one host and reliable local storage with SQLite-compatible locks.

## Content and transactions

The canonical content model is versioned nested JSON. Server validation sanitizes the HTML subset and validates image references. Legacy HTML and block-comment content is imported as an HTML block, without claiming full Gutenberg compatibility. Public reads use materialized HTML only when rendered_revision equals revision; otherwise they render and sanitize the current source.

Mutation preparation and domain events run before the write lock. BEGIN IMMEDIATE then re-reads current ownership/revision, rechecks permissions, stores the prior revision snapshot, applies a compare-and-swap update, and queues jobs in the same commit. Unicode slugs are normalized and made unique inside that transaction.

Users have explicit capability arrays selected through four built-in roles. Administrators manage site configuration and accounts; editors manage both content types; authors manage their own Posts; page editors manage their own Pages. Read gates use the same permission service. User IDs are never accepted from an HTTP form as the acting session identity.

## Sessions and request boundaries

Cookies contain random 256-bit opaque tokens; only hashes are stored. Expiry is enforced server-side. Logout, password changes, role changes, disabled accounts, and operator revocation invalidate sessions. Cookies are HttpOnly/SameSite=Lax and Secure in production. CSRF HMACs are bound to the session or anonymous cookie, action, and 15-minute tick (current/previous tick accepted). Every modifying administration form, login, installation, and logout verifies a POST token.

Login admission first reserves an atomic per-IP budget of 30 attempts per minute before password hashing. Failed login counts are also keyed by normalized username plus remote IP, with a 15-minute throttle after five failures. Missing users still perform password verification once admitted. This application throttle complements proxy rate limiting; it is not a distributed abuse service.

## Accounts and account email

Account links (invitations, password recovery, optional sign-in links) are purpose-bound tokens: only the SHA-256 hash is stored, each link works once, expires (one hour; thirty minutes for sign-in links), is rate-limited per address and purpose, and is never consumed by opening it — every flow completes through an explicit form or confirmation inside the transaction that applies it. Issuing a new link invalidates earlier ones for the same address and purpose, and completing a reset, disabling, or deleting an account revokes its sessions in the same transaction. Public recovery and sign-in-link answers are neutral about whether an account exists; delivery failures are recorded for administrators and audited, never reported as sent. User deletion is a separate confirmed flow: content and media are reassigned to an enabled account, bytes are never cascade-deleted, and a minimal audit tombstone (user id, username, reassignment) remains.

## Workers and maintenance

The fixed job allowlist handles rendering and post-transition audit events. Claims have expiring random lease tokens. Commit and acknowledgement share one transaction after active-lease verification; render publication also checks the exact target revision. Unknown tasks retry and eventually fail. No outbound webhook transport is claimed.

The maintenance coordinator explicitly releases its own reader handle, seals admission, drains readers, then upgrades. Failed upgrades retain the barrier for operator recovery. Schema version 2 adds sessions, attempts, revision history, media, and audit tables; version 3 adds content Trash; version 4 adds private-by-default profile fields, terms and post-term relationships. The final version stamp follows migration and foreign-key validation. Posts-table rebuilds preserve the deleted-ID high-water mark. Schema version 5 adds the asset model and its pending/ready state. Schema 7 adds image lineage (parent/variant/focal columns); schema 8 adds account tokens. No automatic HTTP schema migration occurs.

Compiled event registries are JSON data (`var/cache/listeners.json`), never executable PHP. Legacy listeners.php files are ignored. Theme PHP and plugin source remain trusted operator code; they are not uploaded through administration.

## Assets, backups, and trust

Uploads accept fully decoded and re-encoded raster images only, with MIME/signature agreement, size and dimension bounds, metadata stripping, and immutable SHA-256 filenames. Uploaded files are public. Documents (PDF, text, Markdown, CSV; at most 25 MB) are stored unmodified in the protected asset tree under bare-hash paths and are never web-accessible directly: a front-controller route rechecks authorization on every request, answers inaccessible or incomplete documents with an indistinguishable 404, and sends every response with no-store, an attachment disposition and nosniff. Document validation separates the canonical format (from the filename extension) from detected MIME evidence and content rules; record reuse respects the requested format and visibility. A file block references a document by id; saving validates the author's access at any status, publication additionally requires a public document, and the renderer emits a link only for a viewer who can access it. Changing a document's visibility invalidates the cached renders of referencing posts and queues their re-render. Media record removal refuses references from current content, revision history, the site logo or local profile avatars; it leaves immutable bytes until the maintenance sweep reclaims canonical unreferenced blobs after a 24-hour grace period. Storage changes and backup reads share a bounded storage lock; unknown files and symbolic links are excluded from cleanup.

Public profiles are opt-in. Author cards expose only display name, biography, validated website and registered local avatar; account email and security fields never enter the public author query. First/last names stay private unless deliberately used as the display name. Only administrators can edit another user's profile. Sensitive email/password changes verify the actor's current password and password changes revoke the target's sessions.

Categories and tags apply to Posts only. Term management requires edit-others-posts capability; Post assignment follows the existing content mutation authorization. Assignments, revision snapshots and queued work share the content write transaction. Categories form a bounded hierarchy, tags remain flat, and Uncategorized is protected as the fallback. Archive queries expose only published Posts; parent category archives include descendants.

Backups contain a VACUUM INTO database snapshot, registered immutable images and ready document blobs verified against their hashes (a missing or corrupt ready asset fails the backup loudly, naming the asset; incomplete registrations travel in the snapshot only), a manifest recording each asset's kind, format, visibility and state, and a file-based site secret when present. Environment secrets and application source/configuration must be backed up separately. Backup archives are private sensitive data and need off-host retention and restore rehearsal.

## Site composition and approved public files

Schema 6 adds immutable design revisions (including their base live version) and managed-file versions. Publishing stores an old live configuration snapshot and updates design options/version in one write transaction after validation and a separate render probe. Rollback publishes another live version. Saved drafts remain reopenable and discardable; discarding does not change live configuration. Preview overrides are request-local and require the author's administrator session and action-bound token. Design history and shared-section image references participate in media deletion protection.

Theme manifests use renderer contract 1 and map curated IDs to validated local files. The probe runs trusted deployed PHP against a private database snapshot, not against the live database; it is bounded and is not a sandbox for hostile PHP or a check of every possible runtime branch. Runtime template errors have a default fallback. Standard/wide/landing Page assignments live in existing metadata and revision snapshots. Shared sections affect current site composition only when published; historical Post/Page content snapshots are not rewritten by shared-section edits.

The approved file editor accepts only custom.css and site-notice.txt; both are public, UTF-8, at most 128 KB per version. The stylesheet supports the documented selector/declaration subset and prohibits imports, external resources, escapes, HTML and scripts. Source lives in database history, not writable theme PHP. Fixed front-controller routes deliver current versions with no-store; publication and rollback use administrator checks, CSRF and an expected version. No file-path parameter reaches a filesystem writer.
