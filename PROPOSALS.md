# WordPress Next — Current State And Proposed Changes

Accompanies the rollup of the current application state (October 26, 2026).
This document began as packaging-level proposals. The October 26 rulings below
are now approved implementation choices for the remaining scope. G2 was
implemented on October 28, 2026 (see its section below); H1 was
implemented the same day (see its section below). Delivered fixes are recorded
in `CHANGELOG.md`; the approved scope remains in `docs/adr/V1_SCOPE.md`.
The earlier gate counts below describe the supplied baseline, not the new
verification run. See `docs/reviews/V0_0_1_VERIFICATION.md` for new evidence.

## Current state (delivered)

- Schema 9. Publishing (Posts/Pages, blocks + legacy HTML, revisions, trash),
  configurable permalinks, categories/tags, profiles and public author pages.
- Media: asset lifecycle (staged → pending → ready), protected document
  library with per-request authorization, image tools with lineage and fixed
  variants (150×150 focal, 600/1200 widths), explicit reference replacement.
- Design: theme manifests, page templates, versioned design drafts with
  private preview and health-checked publication, shared sections, form-based
  menus, managed `custom.css` / `site-notice.txt` with draft preview.
- Visual block editor using the same public renderer, with a Block settings
  inspector and desktop/mobile canvas widths.
- Accounts (E1/F1): SMTP mail (settings or `WP_MAIL_*` environment),
  invitations with derived/customizable usernames, neutral password recovery,
  optional single-use sign-in links (off by default), disable, and separately
  confirmed deletion with reassignment and a minimal audit tombstone.
  Operator recovery via `bin/wp user:reset-link`.
- Editorial API (I1 Option B, v0.1.0): authenticated Post/Page operations
  with scoped credentials, idempotency receipts and an external credential
  epoch; shipped disabled until operator initialization and activation.
- Portability (G2): native ZIP + versioned JSON manifest export/import and
  a WordPress WXR importer on one pipeline — preflight before writes,
  explicit ID mapping, media only from the supplied archive through the
  normal intake, unmapped authors as ordinary accounts with unusable
  passwords, resumable runs. Admin screen plus `bin/wp export` /
  `bin/wp import`.
- Gates on this tree, all by exit code and completion line: composer check 0
  (PHP smoke 27, hardening 18, PHP assets 30; PHPStan and PHPCS clean),
  accounts 60, HTTP 53, permalinks 96, profiles 43, design 45, documents
  complete, images complete, assets 6 + 9, upgrade PASS, migration PASS,
  JavaScript syntax OK, browser 92, nginx fixture 28, Apache fixture 22.
  34 local Markdown links, none broken.

Open items carried in `CHANGELOG.md` (Open Findings): the intermittent
theme-settings failure remains unresolved; real PHP-FPM + HTTPS certification
(J1) is outstanding.

## v0.0.6 — Closed Import Review Findings

The four v0.0.5 findings are repaired: supplied legacy attachments and social
images use explicit media maps; generated Post provenance supports interrupted
retry without duplicate collision items; exports enforce native format bounds.
The 29 positive regressions run in the portability CI gate. See the
[verification and compatibility record](docs/reviews/V0_0_6_REVIEW.md).
Previously created duplicates and old uncheckpointed imports without receipts
still require operator review; this patch does not modify them automatically.

## Delivered: G2 — native export/import + WordPress WXR importer

Delivered October 28, 2026: the engine in `wp-includes/portability.php`, the
administrator screen at `wp-admin/portability.php`, `bin/wp export` /
`bin/wp import`, the format contract in `docs/PORTABILITY_FORMAT.md`, and
the release-test suite `tests/portability-http.py`
(`composer test:portability`). The design below is the proposal it was built
from; where they differ, the format document and the changelog govern. One
deviation from the proposal text: remote fetching was not built at all (off
is the only behavior), per the October 26 ruling.

Per `docs/adr/V1_SCOPE.md` §G2. Proposed design:

- **Native format:** a versioned ZIP containing `manifest.json` (format
  version, site metadata, and one record per item) plus the referenced media
  bytes under content-addressed paths. Records cover Posts, Pages, block and
  legacy-HTML content, categories/tags, authors (by username/email, never by
  source ID), publication dates, slugs, supported metadata, and referenced
  media (images with lineage/variants, documents with visibility).
- **Import:** preflight first — parse and validate everything, then show a
  report (counts, author mapping table, slug collisions, missing media,
  unsupported items) before anything is written. Commit applies an explicit
  source→new ID map; relationships are rebuilt from the map, never from
  source database IDs. Media enters through the normal asset lifecycle
  (validators, quota, dedupe by bytes), so an import cannot smuggle in
  unvalidated files.
- **WXR importer:** same pipeline after a WXR reader (Posts/Pages, statuses,
  dates, slugs, authors, categories/tags, excerpts, attachment references).
  Content that cannot become an editable native block falls back to sanitized
  legacy HTML where that representation exists. Unsupported custom blocks,
  shortcodes, plugin data and missing media are **reported per item**, never
  silently discarded and never claimed as migrated. Imported PHP, shortcodes
  and plugins are never executed.
- **Authors:** source role data never creates privileged accounts. Unmapped
  authors become ordinary local accounts with an unusable random password
  (content attributed correctly; sign-in only after an operator reset), or
  the operator maps them to existing accounts in preflight.
- **Media sources:** a supplied media archive is the primary path (no network
  required). Optional URL fetching is off by default and, when enabled,
  enforces destination validation, redirect checks, byte/time limits, and the
  normal media validators (SSRF-guarded).
- **Collisions and duplicates:** explicit per-run choices in preflight
  (skip / import with suffixed slug / map onto existing). Defaults proposed:
  skip exact duplicates, suffix colliding slugs, and list both in the report.
- **Retry/resume:** the import records completed item mappings as it goes;
  an interrupted run resumes from that record without duplicating completed
  items. Quota exhaustion stops the run with a per-item report, not a
  partial silent state.
- **Release tests (named in the scope, all to be built as a new suite):**
  native round-trip, a representative WordPress WXR fixture,
  missing/unsupported content reporting, hostile archive/XML inputs, quota
  exhaustion, slug collisions, interrupted retry.

Approved October 26: ZIP plus a versioned JSON manifest; preflight before
writes; ordinary unmapped-author accounts with unusable passwords; skip exact
duplicates and suffix colliding slugs by default. Remote fetching stays off
by default. These were the implementation requirements for the delivery
recorded above.

## Delivered: H1 — feeds, sitemap, metadata

Delivered October 28, 2026: the engine in `wp-includes/discovery.php` with
front-controller routes for `/feed`, `/sitemap.xml` and `/robots.txt`, the
site-wide indexing option in General settings, Open Graph tags in the public
head, and the per-entry social image picker in the editor Summary panel.
Canonical addresses and the meta description already shipped and were
audited, not rebuilt. The suite is `tests/discovery-http.py`
(`composer test:discovery`, also in CI), and the server fixtures assert the
three routes reach PHP on both stacks.

Per §H1. Proposed design:

- **RSS:** a feed of the latest published Posts (proposed: 20) with title,
  link, publication date, author display name, and excerpt/summary only —
  no private or draft content, correct XML escaping throughout.
- **Sitemap:** XML sitemap listing public URLs for published Posts and
  Pages (canonical addresses under the active permalink structure), with
  last-modified dates. No admin, preview, document-download, or private URLs.
- **Canonical + metadata:** every public page emits its canonical URL;
  optional per-entry description and social image (falling back to the
  featured image) render as meta/Open Graph tags with strict escaping.
  Account contact data never appears in feeds, sitemaps, or social metadata.
- **Indexing controls:** a site-wide "discourage search engines" option
  (robots meta + a restrictive `robots.txt` response) — proposed as the v1
  control, with per-entry noindex left out unless you want it.
- **Tests:** extend the HTTP suite — feed/sitemap contain published public
  items only; a post made private/draft disappears from both; description
  and social image escaping; discouragement option flips the robots output.

Approved October 26: latest 20 published public Posts, excerpt-only RSS,
site-wide indexing control only for v1, and featured-image fallback for social
images. Per-entry noindex is outside v1.

## Delivered: I1 Option B — authenticated editorial API (v0.1.0)

Delivered October 29, 2026 under Ian's ruling adopting the revised Option B
packet as the v0.1.0 contract: ten authenticated Post/Page operations at
`/wp-next/v1` (list, item, create-draft, update-draft, publish-with-changes),
scoped integration credentials under Settings → Integrations, schema 9,
idempotency receipts committed inside the mutation transaction, the external
credential epoch with credential-inert backups and a `bin/wp restore`
coordinator. The API ships disabled until operator initialization and
activation checks pass. The contract and delivery record live in
`docs/adr/I1_EDITORIAL_API_PACKET.md` and `docs/api/editorial-v1.openapi.json`;
the suite is `tests/api-http.py` (`composer test:api`, 94 checks). Still
outside the contract: anonymous browsing, webhooks, media upload, account
management, design settings, bulk operations and WordPress REST
compatibility. The composer/OpenAPI license-metadata question remains open.

## J1 — PHP-FPM Verification And Host Certification

Per §J1 and `docs/V1_DEPLOYMENT_REQUIREMENTS.md` (10-item matrix). v0.0.9
adds a repeatable real FPM/HTTPS gate on both stacks, alongside the static
fixtures. [The local verification](docs/reviews/V0_0_9_REVIEW.md) records
direct TLS, explicit trusted termination, strict certificate verification,
application workflows and actual backup restoration. CI now includes the
same gate; local success alone does not establish its remote result.

Remaining execution:

- Complete the uncovered portions of the ten-row matrix on both deployment
  targets, including browser/live editing, full routing/stale-worker cases,
  account mail transport and delivery, fresh-target native asset remapping,
  derived-asset/managed-file restoration and resource-failure coverage.
- Verify dedicated production identities, source/storage permissions,
  production certificates, backend isolation and intended service topology.
- Retain host configuration, versions, limits, first failures and reruns;
  rehearse scheduled work, monitoring, off-host retention and recovery.

Limits that will remain stated, not smoothed over: a locally generated
certificate proves the TLS plumbing, not a production CA chain; Safari and
real touch devices stay host-only and unverified; production alerting,
backup retention, and worker scheduling remain operator configuration, not
application certification.

Approved October 26: hybrid verification. Run the supported real FPM/TLS
checks here and finish production service identity, certificate chain, host
operations and remaining release coverage on the target host. Local results
do not substitute for those host checks. The verification review includes
the handoff checklist; J1 stays open until both evidence sets are complete.
