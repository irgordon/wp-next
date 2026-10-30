# Draft Editorial API Contract And Schema 9 Design

Status: **adopted by Ian's ruling, 2026-10-29, and implemented as v0.1.0** on the v0.0.9.1 (`e755e42`) base. The ruling adopted this packet (revision r3) as the v0.1.0 contract: the ten authenticated Post/Page operations and schema 9, credential-inert backup snapshots, the external epoch file with locking and a restore coordinator, the stated numerical defaults, mounting at `/wp-next/v1` under the trusted `WP_API_BASE_PATH` with activation-time and save-time route guards, and the API shipped fully built but **disabled** until operator initialization and activation checks pass. The historical [I1 deferral](V1_SCOPE.md#i1-integration-scope) for v1.0.0 is unchanged; this contract is a separate, later authorization.

Review together: [OpenAPI contract](../api/editorial-v1.openapi.json) (adopted at document version `0.1.0`; the packet's draft carried `0.1.0-draft.1`) and [schema-9 DDL blueprint](../api/schema-9.draft.sql), retained for the record. The implemented migration is `wp_migrate_api()` in `wp-includes/schema.php`; the blueprint is not an installed migration driver.

## Contract Boundary

An authenticated, server-to-server editorial API at the configured site base plus `/wp-next/v1`. Posts and Pages have list, item, create-draft, update-draft and publish-with-changes operations. All API operations require a named integration token; browser cookies do not authenticate them. Administration for credentials continues to use an HTTPS browser session and action-bound CSRF.

No anonymous API browsing, webhook delivery, media upload/download API, account management API, design/CSS API, scheduling, unpublish, Trash, deletion, revision restore or WordPress REST compatibility is included. Existing media identifiers may be referenced through the existing validators. Private document references remain permissible in an authorized draft and are refused on publication. Their normal browser download routes do not acquire Bearer authentication through this proposal.

Public v1 request fields are closed: title, canonical slug, version-1 block document, excerpt, featured/social image references, Post term IDs and the curated Page template. Arbitrary metadata, authorship, actor identity and import provenance cannot be supplied. The content normalizer remains authoritative for sanitization, the 2,000,000-byte normalized-document limit, 500 total blocks and eight nested layouts. Proposed API envelope ceiling: 3 MiB. List responses contain summaries, not every document body; default page size 20, maximum 100, with an opaque cursor and no total count.

`POST /posts` and `/pages` create Drafts, regardless of the site's default. `PATCH /posts/{id}` and its Page equivalent accept Drafts only. Each existing-resource mutation requires `expected_revision`. `POST /posts/{id}/publish` accepts a Draft or Published item and optional changes; it publishes the resulting content in one commit. An empty change set still constitutes an explicit publication/update and advances the revision. Archived items are readable according to the policy below but cannot be mutated through this first contract. Trash is excluded from reads and writes. There is no separate persisted working draft of an already-published item.

Mutation success returns a small committed-result envelope: resource type/ID, revision, status, actual slug, public URL or null, and a stable mutation ID. It does not return the full editable body. Clients with read scope obtain the current body separately. A successful write-only token can receive its own committed-result receipt without gaining general read scope.

## Five Behavior Pins

| Pin | Proposed Contract |
| --- | --- |
| Atomic publish with changes | Add an explicit `publish_with_changes` domain command with API authority, revision, slug intent and receipt context. It executes one transaction; do not call update and transition as separate operations. |
| Explicit slug collision | A supplied slug must already equal the existing canonical slug normalization, be nonempty and contain at most 120 characters. Invalid/reserved input is 422. A collision with another Post or Page is 409 `slug-conflict`; excluding the current item permits retaining its own slug. Never suffix supplied input. |
| Derived slug collision | Only creation with no slug derives from the title and uses the existing suffix behavior. Omitting a slug on update/publish preserves the current slug, even if the title changes. Both collision decisions are made under the write lock. |
| Plain HTTP | Any request in the API namespace, including development, returns 503 `https-required` unless actual or explicitly trusted server HTTPS state is present. Do this before credentials/body processing; do not redirect, authenticate or mutate. Untrusted forwarding headers cannot assert HTTPS. Token issue/display also requires HTTPS. |
| Browser-equivalent visibility | Editorial access is the same `current_user_can('edit_post', id)` decision used by `wp-admin/post.php`, intersected with token scope. It includes editors reading others' drafts and preserves separate Post/Page capabilities. Lists apply the same row policy before pagination. Public-site readability alone does not grant editorial API access. |
| Restore revocation | Require a separate external credential epoch, credential-inert application backup snapshots, and epoch rotation while restore traffic is sealed. The mechanism below does not rely solely on a cleanup command after reopening. |

Missing/unknown/inaccessible individual resources all return the same 404 problem. Missing resource-type scope returns 403 before resource lookup. A write requires its resource `write` scope; publication requires both `write` and `publish`, plus current browser edit authority and the account's `publish_posts`/`publish_pages` capability even for an already-published item. Failed changes leave the stored content, revision, publication state, audit and queue unchanged.

An explicit-slug conflict reports only that the slug is unavailable; it does not identify the other resource, owner or publication state. Token issuance requires at least one applicable grant and current account authority for the selected resource types; a publish grant without its matching write grant is rejected.

## Verified Seams And Qualifications

- [The existing `update_post()`](../../wp-includes/class-wp-post-mutation-service.php#L215) already accepts content and `post_status` together and commits them under one expected-revision check. The new publish command can share that atomic implementation. A second independent content-write engine is unnecessary; composing two public service calls would be wrong.
- [The database wrapper](../../wp-includes/class-wp-sqlite-db.php#L38) rejects nested transactions. API authority and receipt persistence must enter the existing mutation transaction, not an outer wrapper around `wp_update_post()`.
- Audit paths differ at `e755e42`: [`set_status()`](../../wp-includes/class-wp-post-mutation-service.php#L299) writes `post_trashed`/`post_restored` audit rows inside its transaction. `update_post()` queues render work and, when status changes, a [`Post_Transition_Job`](../../wp-includes/class-wp-job-worker.php#L41) whose `commit()` later writes the `content_status` audit row. Add a distinct API mutation audit event inside the content/receipt transaction, preserving the existing direct lifecycle audits and queued transition behavior. Use distinct audit event identifiers for these purposes.
- [Permalink validation](../../wp-includes/permalinks.php#L18) reserves a literal first segment beginning `wp-`, but [slug allocation](../../wp-includes/content.php#L167) does not reserve every such Page slug. Dynamic first segments are also allowed: `/%author%/v1/%postname%/` with author `wp-next` and slug `posts` can produce an API collection path. Preflight current and historical routing before activation and guard future route/author/category changes against claiming the mounted namespace. Report conflicts; do not silently move existing content. The namespace is proposed, not proven collision-free.
- Fresh schema 8 restricts `account_tokens.purpose` to invite/reset/magic; the legacy account-table migration helper has a different DDL shape. Neither is a suitable API credential store. Schema 9 adds separate tables without repurposing account tokens.

## Credential Model

Wire token: `wpn1.<32-lowercase-hex-public-id>.<43-base64url-characters>`. The secret represents 32 random bytes. Decode strictly and require a canonical re-encoding match. Store SHA-256 of the decoded secret, never the wire token or secret; compare in constant time. The public ID locates a credential and is not an authentication secret.

Named credentials belong to an existing enabled user. Only an administrator issues/revokes them through the browser or an operator command. Scope strings are exactly `posts:read`, `posts:write`, `posts:publish`, `pages:read`, `pages:write`, `pages:publish`. Proposed default expiry is 30 days, with a 90-day maximum; no refresh-token or OAuth authorization-server flow is introduced.

Every request verifies enabled API state, epoch binding, token expiry/revocation and current account state. Mutations repeat these checks inside their write transaction. Password change/reset, capability change, disable and deletion revoke affected API credentials in the same account-change transaction. Role changes do not restore credentials later. Single-token revocation records a timestamp and reason; API disable revokes every credential atomically with disabling the feature. Global epoch rotation has the fail-closed sequencing below. Audit credential events by public token ID and account ID, without raw credentials or contact fields.

Proposed admission limits: 60 authenticated reads and 20 mutations per minute per token; 60 authentication attempts per minute per direct peer IP. These are initial limits for measurement, not capacity guarantees. Invalid requests remain bounded. Forwarding headers do not alter peer identity. Retain rate buckets only until their windows expire; cap active buckets at 10,000 and refuse admission if another bucket cannot be safely stored. Do not update last-used time on every read; write a coarse timestamp at most once per hour. Missing/unwritable epoch state is 503 `credentials-unavailable`; missing, malformed, expired, revoked or wrong-epoch tokens receive a neutral 401 with a Bearer challenge.

## Schema 9 Migration Shape

The DDL blueprint introduces five tables:

| Table | Purpose And Important Constraints |
| --- | --- |
| `api_security_state` | Singleton, disabled by default; nullable database epoch binding. Enabled state requires a binding. |
| `api_tokens` | Random public ID, user FK, secret hash, name, epoch, expiry, revocation and coarse last-used timestamp. User deletion cascades; issuer ID is audit metadata, not a FK that prevents deletion. |
| `api_token_scopes` | One row per allowlisted grant; composite primary key and cascading token FK. |
| `api_mutation_receipts` | Token/key uniqueness, request fingerprint, committed result identity and small response body. No Post FK: later content deletion must not erase the retry key. |
| `api_rate_windows` | Hashed budget key, bounded counters and expiry. No IP, token secret or Authorization header stored. |

Proposed ceilings: 10 active tokens per user, 100 per site; 1,000 retained receipt keys per token, 10,000 per site; 4,096 bytes per stored response. Admission checks these ceilings under the write lock. Quota exhaustion is 429 `receipt-capacity-exceeded` or `token-capacity-exceeded`, never eviction of an unexpired retry guarantee. Physical storage remains an operator responsibility.

Migration sequence: seal admission and workers using the established coordinator; validate the source schema and existing table shapes; create the pre-upgrade backup; create/verify the five new tables and indexes; insert disabled state without creating an epoch file; verify foreign keys and integrity; stamp schema 9 only after success; apply existing session-revocation policy and reopen. No existing content, assets, account tokens or active browser behavior changes. If a partial earlier schema upgrade left matching API tables, validate their exact shape before resuming; refuse unexpected shapes rather than replacing them. Initial blank installs create the same schema-9 layout. Failure retains the maintenance barrier. Downgrading schema-9 source to schema 8 requires a matching pre-upgrade restore.

## Restore Epoch And Backup Mechanism

Proposed operator configuration: `WP_API_EPOCH_PATH`, an absolute path to a regular mode-0600 JSON file outside the source, main runtime, database, upload and protected-asset trees. It contains a version and a 256-bit random epoch. Reject symlinks and unsafe ownership/permissions. CLI and FPM must read the same file. It is excluded from application source archives, native portability and application backup payloads. Operator infrastructure must not roll it back with the application database.

Migration leaves the API disabled and unbound. An explicit initialization operation, allowed only with no active API credentials and sealed traffic, atomically creates the external epoch then binds the database. HTTP never creates a missing file or repairs a mismatch. Enable only after initialization, route-conflict checks and HTTPS checks.

An epoch alone does not detect a database rollback if the external value did not change. Therefore application backup creation must make the private SQLite snapshot credential-inert before archiving: revoke every API token in that snapshot, disable its API state and clear its epoch binding. Live database credentials remain unchanged. Verify the snapshot modifications and constraints before reporting backup success. Existing content/assets, browser session restore policy and matching `auth.key` treatment remain as documented. The snapshot retains revoked token records and bounded receipts as private historical data; native portability exports neither.

The proposed restore coordinator must seal and drain API requests, browser mutations and workers, take an exclusive API-epoch lock, replace the external epoch with a fresh value before swapping any database, then restore/upgrade. Revoke restored API rows again, bind the new epoch and leave the API disabled. Only after verification may the operator reopen traffic and issue fresh credentials. External-file replacement precedes database binding: a crash leaves a mismatch and refuses API access. File flush, atomic rename and parent-directory durability must be validated on the supported host.

Credential-bearing requests hold a shared epoch lock through read materialization or mutation commit; rotation requires the exclusive lock after admission drains. This prevents a stale FPM process from committing against a generation rotated mid-request. Re-read the epoch under that lock; never cache it indefinitely. The lock path/identity must be stable across atomic epoch-file replacement and outside restored application state.

Application-produced schema-9 backups are credential-inert even if post-restore cleanup is omitted. Supported restore also rotates the external epoch and starts disabled. Arbitrary raw database copies, full-machine rollback including the external epoch, or a simultaneously live clone are outside that guarantee; an external anti-rollback authority would be required to cover them. The existing CLI has no complete restore coordinator today: building it and the credential-inert backup step is part of this slice, not existing functionality.

## Publish Operation Transaction Boundary

Before the write lock: classify the request, enforce HTTPS and bounds, establish the explicit API actor, parse the supported command and prepare normalized candidate content with the existing validators/events. File-reference checks must use this actor, not a browser cookie that happened to accompany the request. No outbound network I/O is involved.

One `BEGIN IMMEDIATE`, owned by the mutation service:

1. Recheck enabled API state, the locked external epoch, token validity, required grants and enabled account.
2. Look up the token/key receipt. Enforce resource read authority and original operation scopes before replay. If valid and identical, return the stored committed result without any new mutation, revision, audit or jobs. If expired, return the explicit expired-key problem; different input is a key conflict.
3. For a new operation, read the current resource and apply the same browser editorial-read gate before revealing its revision or slug. Enforce resource type, expected revision and allowed source status. Publication additionally requires the account's publish capability and both token grants.
4. Admit a new receipt against its capacity ceilings only after the replay lookup. Recheck mutation permissions, media access/publication eligibility and curated templates; resolve explicit-versus-derived slug intent under the lock.
5. Preserve the prior revision; perform exactly one conditional content/status update and revision increment, or one draft insert. Assign terms and keep existing File-link invalidation and worker-generation fences.
6. Enqueue existing bounded jobs; insert one API audit event and the small completed receipt in this transaction. Receipt insertion failure rolls back content, revisions, audit and queue together.
7. Commit, release locks, and serialize/send the response from the committed result. A lost response is recoverable by an identical retry.

Request-level decoding still occurs on a retry. The authoritative receipt check must precede replaying mutation events or evaluating an old `expected_revision`; use an early checked-receipt fast path, then recheck under the write lock before a new commit. Events that prepare a new candidate must remain free of externally visible side effects. Do not make preflight success authority to skip transaction-time checks.

The command is an explicit new public/domain operation, provisionally `publish_with_changes(id, expected_revision, changes, api_context)`, sharing an internal transaction core with the existing update behavior. API context is typed trusted request state, not arbitrary callbacks supplied from HTTP. Controllers do not write SQL; pure decisions receive snapshots; infrastructure adapters own credential, receipt, epoch, query and encoding details. Existing browser/procedural callers retain their current behavior.

## Receipt Contract

Require a printable non-space ASCII `Idempotency-Key` of 16–128 characters on create, patch and publish. Store SHA-256 of its exact ASCII bytes, scoped uniquely to the public token ID across all operations. Fingerprint the exact UTF-8 request-body bytes plus method, operation, resource kind, target ID (or create marker) and API contract version with unambiguous length framing. Semantically equivalent JSON with different bytes is a different request and returns 409 if the key is reused. Reject unsupported write query parameters rather than omitting them from the fingerprint.

Successful writes alone create receipts. No independently committed pending/reserved row is needed: SQLite serializes the commit boundary. Concurrent identical requests may prepare, but only the first commits; the second finds its receipt before revision validation and adds no jobs, revisions or audit. Failed validation or a rolled-back commit consumes no key. A changed-body key reuse is 409 `idempotency-key-conflict`.

Only the server-built committed-result envelope may become a receipt. The DDL checks JSON size/type and its identity/revision/status/mutation fields against the receipt columns; the application also validates the response schema before persistence and replay. JSON validity alone is not a semantic receipt guarantee.

The exact small response body, original success status and optional relative Location are replayable for 24 hours. `Idempotency-Replayed` reports true or false; `X-Request-Id` identifies the current transport attempt and is never replayed from storage. The response's `mutation_id` identifies the original committed operation. A receipt is a historical acknowledgement, not a claim that the resource still has that revision. Current resource access is rechecked; deletion or lost visibility returns the same 404 as an ordinary read, and revocation returns 401.

After the replay window, return 409 `idempotency-window-expired`; never execute that key again while its credential can authenticate. Maintenance removes the response body/Location but retains a compact key/fingerprint tombstone until the token expires or is permanently deleted. At expiry the clock rule applies even before cleanup runs. Retained tombstones count toward quota. Retry under a newly issued token is a new identity and requires reconciliation; it is not duplicate-protected by the old token's key. Receipt fields are not an export/import provenance mechanism.

## Error And Compatibility Contract

The OpenAPI enumerates the stable problem codes and response shapes. Use 400 for malformed JSON/required headers or revision fields, 401 for invalid credentials, 403 for missing operation scope, 404 for missing/inaccessible resources, 405 for unsupported methods, 409 for revision/status/slug/key conflicts, 413 for request size, 415 for unsupported media type, 422 for semantic validation, 429 with Retry-After for admission limits, and 503 for HTTPS/epoch/maintenance/storage admission failure. API errors must remain problem JSON even when bootstrap would otherwise exit with HTML/text, including development HTTP. Add a configuration-only API classifier/TLS boundary before full bootstrap; reuse the shared trusted-HTTPS decision without creating a database, secret or session on refused HTTP requests. Server-generated errors before PHP are outside the application envelope and must be identified in server tests.

That early classifier needs a trusted mount path without a database lookup: propose operator `WP_API_BASE_PATH` (empty at host root; a normalized leading-slash subdirectory without trailing slash otherwise). Append `/wp-next/v1` and match the full segment boundary. Do not infer it from Host/forwarding headers or scan arbitrary path suffixes. Activation verifies this deployment value agrees with the site's configured base path and front-controller routing. The HTTPS decision uses actual server state or operator `WP_HTTPS=1`, never request forwarding headers.

Responses use private/no-store, nosniff, noindex and JSON media types, including refusal responses. Errors carry the current request ID and never echo credentials, source paths, SQL or private resource details. CORS is disabled; unexpected Origin requests cannot turn browser cookies into credentials. No authentication downgrade or redirect to the login form occurs. Normal browser security headers and HTTP behavior remain intact outside the API namespace. Verify Authorization reaches PHP on both server templates.

The `/v1` compatibility promise begins only after this draft is approved and shipped. Removing/renaming fields, changing types, visibility, publish effects or retry rules is breaking. Optional response additions must be tolerated by clients; new enum values require compatibility review. A new contract version does not authorize exposing internal browser endpoints.

## Review Gates Before Implementation And Release

- Contract: all ten operations resolve, example requests/results match their schemas, request fields are closed, and declared scopes match Posts versus Pages.
- Migration: blank schema 9 and schema 8→9 preserve content/assets/accounts; DDL/check/stamp failure remains sealed; repeated upgrade validates existing shapes; account-token purposes are not altered.
- Visibility: administrator/editor/author/Page-editor fixtures compare API-visible IDs with the same browser item-read gate, including others' drafts, archives and capability changes. Counts/cursors cannot reveal excluded rows. Trash remains outside this API.
- Mutation: create is private, patch refuses live items, publish-with-changes advances exactly one revision, two browser/API editors produce one winner, and private File references refuse publication without partial writes.
- Slugs/routing: explicit collision 409, derived collision suffix, preserve an omitted update slug, explicit current slug accepted, canonical invalid input 422, two concurrent explicit claims yield one winner, and dynamic/current/historical namespace conflicts block activation.
- Credentials: expiry, per-resource scopes, spoofed actor fields, cookie-only requests, revocation/capability reduction after preflight, and rotation while a request is held before commit. No commit using old credentials after completed revocation/rotation.
- Receipts: lost response, simultaneous identical requests, changed input/route/type with one key, receipt-write fault, stale revision after successful commit, revocation before replay, expired response plus retained tombstone, later resource deletion and capacity refusal.
- Restore: snapshot credential redaction does not revoke live tokens; actual restore starts disabled, old credentials fail even if cleanup is deliberately skipped, fresh epoch/init is required, missing/stale files fail closed, and interruption between file replacement and DB binding cannot re-enable tokens.
- Deployment: all API success/refusal paths, Authorization forwarding and plaintext refusal run through nginx and Apache/FPM in direct and trusted-termination modes. Extend J1 coverage rather than marking its other rows closed. Preserve full existing PHP/static/browser regressions and first-failure records.

Open ruling points are the strengthened backup/epoch mechanism and restore coordinator, the initial admission/storage ceilings, and the namespace activation guard. The five requested pins are specified above, not silently assumed approved. No implementation/release commit or patch-version increment is part of this draft.

## Draft Validation

Redocly CLI 2.72.1 validates the OpenAPI with zero errors and one `info-license` warning. No license metadata was chosen or warning suppressed; that separate ruling remains open. Structural checks resolve 252 local references, enumerate ten operations, check resource-specific scope declarations and closed request fields, and inspect 18 examples/header examples. This is contract validation, not evidence of running API endpoints.

The DDL blueprint passes 32 source-boundary/constraint assertions in a disposable schema-8 fixture using PHP 8.4.27 and its linked SQLite 3.54.1. Checks cover preserved source rows/schema version, disabled state, credential/grant constraints, receipt identity/size/window/tombstones, cascading deletion, admission limits, rollback, matching-DDL replay and the namespace counterexamples, including actual generation of `/wp-next/v1/posts/`. It does not implement or certify the future migration driver, restore coordinator or API concurrency behavior.

Evidence is local under `.local-sync/2026-10-29-i1-packet/`. An initial Python validator installation timed out reaching PyPI; validation used an isolated npm cache instead. An earlier invocation before that installation completed reported a missing validator module. Both are tool-setup failures, not specification failures. Project dependencies, runtime schema and application files are unchanged.

## External Contract References

- [OpenAPI 3.1.2](https://spec.openapis.org/oas/v3.1.2.html) for the reviewable machine-readable skeleton.
- [RFC 6750](https://www.rfc-editor.org/rfc/rfc6750.html) for Bearer transport and TLS protection, not an OAuth server claim.
- [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457.html) for problem JSON; the listed problem codes are this project's proposal.
- [SQLite STRICT tables](https://www.sqlite.org/stricttables.html) and the existing database wrapper for the DDL and transaction model.

## Adoption And Delivery Record (2026-10-29)

Implemented in v0.1.0 and verified by `tests/api-http.py` (94 checks: plain-HTTP refusal before any processing; disabled-by-default state; activation blocked by a planted route conflict and released by the operator remedy; scoped credential issuance and neutral 401s; browser-equivalent visibility; the revision, slug, idempotency and receipt contracts; rate and receipt-capacity limits; revocation on account change, expiry and epoch rotation; credential-inert backup snapshots; the restore coordinator; Integrations-screen issuance). The composer/OpenAPI license-metadata question is still open and recorded in the review material; no license metadata was added.

Implementation corrections made during the build, each caught by the suite:

- API requests now run with the credential's account as the current user after authentication. Block normalization performs save-time asset-access checks against the ambient user; without this, authorized private-document references were refused for every API actor. Transactional rechecks still use the explicit token context.
- Media-verification refusals inside API mutations (for example, publishing a draft that references a private document) are reported as 422 `validation-failed`, not as a generic storage fault.
- Backup creation now checkpoints the snapshot's write-ahead log before archiving. The inerting writes previously verified through the open snapshot connection but rode in a WAL sidecar the archive never contained, so the archived database was not the verified one.
- Deployment templates: the Apache virtual host gains `CGIPassAuth On` (mod_proxy_fcgi otherwise drops the `Authorization` header before PHP, and no Bearer credential can work), and the nginx PHP allowlist gains `integrations.php` (Settings → Integrations would 404). The deployment gate now asserts header delivery through both stacks; those assertions run in CI, since this build sandbox has no PHP-FPM.

One consequence of the historical-route guard, stated plainly: permalink history is checked at activation, so a conflicting structure keeps blocking activation after the structure is changed back, until the colliding account or content is removed (for the `%author%` case, deleting the `wp-next` account clears it). Conflicts are reported, never silently rerouted.
