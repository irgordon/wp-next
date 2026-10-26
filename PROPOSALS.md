# WordPress Next — Current State And Proposed Changes

Accompanies the rollup of the current application state (October 26, 2026).
This document began as packaging-level proposals. The October 26 rulings below
are now approved implementation choices for the remaining scope. G2 and H1
are not implemented by this verification update. Delivered fixes are recorded
in `CHANGELOG.md`; the approved scope remains in `docs/adr/V1_SCOPE.md`.
The earlier gate counts below describe the supplied baseline, not the new
verification run. See `docs/reviews/V0_0_1_VERIFICATION.md` for new evidence.

## Current state (delivered)

- Schema 8. Publishing (Posts/Pages, blocks + legacy HTML, revisions, trash),
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
- Gates on this tree, all by exit code and completion line: composer check 0
  (PHP smoke 27, hardening 18, PHP assets 30; PHPStan and PHPCS clean),
  accounts 60, HTTP 53, permalinks 96, profiles 43, design 45, documents
  complete, images complete, assets 6 + 9, upgrade PASS, migration PASS,
  JavaScript syntax OK, browser 92, nginx fixture 28, Apache fixture 22.
  34 local Markdown links, none broken.

Open items carried in `CHANGELOG.md` (Open Findings): the intermittent
theme-settings failure remains unresolved; real PHP-FPM + HTTPS certification
(J1) is outstanding; G2 and H1 below are approved scope, not yet built.

## Proposed: G2 — native export/import + WordPress WXR importer

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
by default. These are implementation requirements, not delivered features.

## Proposed: H1 — feeds, sitemap, metadata

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
images. Per-entry noindex is outside v1. These features remain to be built.

## Proposed: J1 — PHP-FPM certification on both stacks

Per §J1 and `docs/V1_DEPLOYMENT_REQUIREMENTS.md` (10-item matrix). Current
evidence is fixtures only: the nginx fixture proves allowlisted endpoints
reach the configured FastCGI handler (it has no live backend), and the
Apache fixture proves the documented vhost's delivery/denial rules. Neither
certifies FPM.

Proposed execution:

- Install PHP-FPM 8.4 in this environment (the ondrej PPA route repaired on
  October 24 provides `php8.4-fpm`; fallback: certify on your host instead).
  Configure nginx → FPM and Apache → FPM exactly as `docs/INSTALL.md`
  documents, with a locally generated TLS certificate for the documented
  HTTPS behavior.
- Run the full 10-item matrix on **both** stacks through real FPM requests —
  including the authenticated visual-editor POST (your R1 follow-up), valid
  and invalid uploads at exact size boundaries, public/private document
  delivery, permalink/File-link behavior, account email flows against the
  local SMTP sink, the G2 round-trip, and H1 exclusion checks once built.
- Record versions, commands, service identity, topology, and resource
  settings with the results; first failures reported individually before any
  rerun, per the fixed gates.

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
