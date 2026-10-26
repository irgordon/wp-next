# WordPress Next Documentation

This is the main index for the current build: a pre-1.0.0 development line
(schema 8) implementing the approved v1.0.0 scope in order — the P1 document
library, B1 image tools, C1 live visual editing, D1 stylesheet drafts, and
E1/F1 account email and user deletion are built and covered by the suites
below. G2 (export/import) and H1 (feeds and metadata) are not built yet, and
J1 production certification (real nginx/Apache → PHP-FPM over HTTPS) is
still outstanding; the server suites in this repository are fixtures, not
that certification.

## Start here

- [Installation and operations](INSTALL.md): blank installation, server
  templates for Apache and nginx, workers, backups, upgrades, mail, and
  operator recovery.
- [Architecture](ARCHITECTURE.md): runtime boundaries, content and
  transaction model, assets, backups, and site composition.
- [Invariants](INVARIANTS.md): the persistence and security boundaries the
  implementation must keep.
- [Quality gates](QUALITY.md): the check suites, what each one covers, and
  what passing them does and does not establish.
- [Themes and the builder](THEMES_AND_BUILDER.md): theme manifests,
  templates, shared sections, and the design workflow.

## Decisions and requirements

- [Approved v1.0.0 Scope](adr/V1_SCOPE.md): decisions A2, B1, C1, D1, E1,
  F1, G2, H1, I1 and J1; required behavior, release gates and
  implementation order.
- [v1.0.0 Deployment Requirements](V1_DEPLOYMENT_REQUIREMENTS.md):
  PHP/FPM, servers, storage, mail, background work and the production
  verification matrix.
- [Release plan](RELEASE_PLAN.md): sequencing and deliberately excluded
  compatibility features.

## Current build records

- [E1/F1 — accounts, email and user deletion](reviews/E1_F1_REVIEW.md)
- [C1/D1 — live visual editing and stylesheet drafts](reviews/C1_D1_REVIEW.md)
- [B1 — image tools](reviews/B1_IMAGE_TOOLS_REVIEW.md)
- [P1 — document library](reviews/P1_DOCUMENTS_REVIEW.md)
- Earlier build and correction records live under [reviews/](reviews/).

## Documentation layout

- `docs/` — governing and operator documents: architecture, invariants,
  installation, quality gates, the release plan and deployment requirements.
- `docs/adr/` — decision records and approved scope documents.
- `docs/reviews/` — build and review summaries, including the per-step
  verification records.
- [CHANGELOG.md](../CHANGELOG.md) at the project root consolidates the
  complete development history under **v0.0.0**, including recorded fixes
  and open findings.
