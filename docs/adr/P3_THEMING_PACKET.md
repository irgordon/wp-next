# P3 preparation: theme customization and site composition

> **Status note (2026-10-26):** the statement below that document intake/delivery remains disabled is superseded. Documents shipped in the P1 document library (see `docs/P1_DOCUMENTS_REVIEW.md`) and are carried into the v1.0.0 scope (see `docs/V1_SCOPE.md`). This document is retained as the delivery record for its milestone.


Status: implemented for the curated-template scope in schema 6. The user authorized closing P3 and selected controlled file editing as the first P4 slice. Accepted defaults: standard/wide/landing Pages, explicitly published shared sections, header/footer menus with at most three levels. See P3_P4_REVIEW.md for the final contract and executed evidence. P3 means the theming phase in the P0–P5 capability assessment, not step 3 of the P1 asset implementation order.

## Starting point

The current app discovers operator-deployed themes, validates the required template files, selects an active theme, and falls back through a small template hierarchy. The Site design screen supports a logo, accent/background, width, heading font, homepage and ordered published-Page navigation. Themes remain trusted PHP source. The corrected editor layout and schema 5 asset foundation are retained.

## Recommended scope

Implement curated templates with versioned design settings and a preview/publish workflow. Keep PHP theme source deployed by the operator. A package installer, executable file editor and unrestricted template builder are separately scoped work.

| Choice | Benefit | Cost or limitation |
| --- | --- | --- |
| Expand design tokens only | Smallest implementation; improves consistent typography, color and spacing. | Does not provide page templates, reusable sections or richer navigation. |
| Curated templates and reusable sections, with preview/publish (recommended) | Authors can compose supported layouts while preserving predictable rendering and recovery. | Requires a defined template contract, settings revisions and a preview renderer. |
| General visual template editor | Maximum composition flexibility. | Much broader editing, validation, migration and accessibility surface. Defer beyond initial P3. |

## Proposed implementation slices

1. Theme manifest and template registry: validated slug, name/version, application requirements, supported tokens and approved template IDs. Discover only trusted deployed directories; reject traversal and symlink escapes. Existing bundled themes receive manifests and preserve their current presentation.
2. Versioned design configuration: typed palette, spacing, typography and width values. Changes produce a draft revision; publication is explicit and conditional on the expected current revision. Preserve input on conflict. Recheck administrator capability and CSRF inside the mutation boundary. Never serialize executable PHP in the database.
3. Private preview: preview an unpublished theme/settings revision without changing the live theme. Tie access to the authorized session and requested revision; do not cache preview output. Use the same validation and rendering code as publication. Preview failure must leave the current site intact.
4. Page templates and reusable sections: select approved templates per Page. Introduce controlled header/footer/section definitions using supported block data. Define how later edits to shared sections affect published Pages and revisions before implementation; do not silently rewrite historical content snapshots.
5. Navigation: multiple named locations, nested items and validated external links, with explicit ordering and accessible keyboard/mobile behavior. Preserve current navigation during migration.
6. Publish and recovery: activate only a validated deployed theme; record design history and offer rollback. Add an isolated health check for activation and retain a known-working fallback. A failed publish must not replace the live configuration.

## Asset dependencies and remaining P1 work

- Initial P3 can use existing ready, public images for logos and layout imagery. It does not require enabling document downloads.
- Document intake, the image-pipeline retrofit, routed downloads with no-store, general-asset backups/restore, the file block and the document library UI remain unfinished P1 work. Complete these together before accepting real document uploads.
- P2 asset transforms/versioning are not prerequisites for curated templates. P3 must preserve existing asset IDs and avoid introducing replacement/versioning implicitly.
- New schema numbers must follow the actual release sequence; this packet does not reserve schema 6.

## Decisions adopted for this implementation

Curated templates are standard, wide and landing in both bundled themes. Shared sections are global top/bottom content sections and require explicit design publication. Menus have header/footer locations and a maximum of three levels. Existing flat Page navigation remains the fallback. Sections accept the existing supported HTML/version 1 block document format; menus use one Label | address | level entry per line. This is a curated composition workflow, not a general visual theme builder.

## Acceptance evidence required

Fresh install and upgrade preserve existing output and Page assignments. Previewing cannot change anonymous output. Publication and rollback remain atomic under concurrent edits. Broken manifests/templates fail without taking down the live site. All template IDs and asset references are validated server-side. Page templates, long documents, navigation and recovery work at desktop and mobile widths and by keyboard. Existing publishing, routing, privacy and delivery gates remain green.

## Original preparation and subsequent authorization

The earlier delivery prepared this packet only. The subsequent request authorized implementing P3 and moving to P4; the user selected controlled file editing as the first P4 area. The delivered scope follows those decisions. Remaining P1 document intake/delivery is still disabled.
