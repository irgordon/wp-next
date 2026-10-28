# Patterns inclusion review

Recorded October 27, 2026. **Status: deferred.** This review records future design and implementation work; it does not introduce a pattern library, pattern block or Patterns navigation destination.

## Decision and reference

The approved focused Site design workspace adopts the charcoal navigation from the WordPress [SiteEditor reference](https://www.figma.com/design/JFAsdPvtrs7rJhIJdlRu3g/WordPress-Design-System--Community-?node-id=16314-43026). Its sample Patterns destination is deferred until reusable-pattern functionality exists. Templates continue to use the installed theme's existing curated allowlist and per-Page assignment.

A pattern would be a named, reusable composition of supported blocks that an author can discover and insert. This differs from a Page template, which selects a trusted theme layout, and from the current shared header/footer sections, which have their own site-design publication flow.

## Why defer the UI

The current application has a canonical block document and block insertion operations, but no pattern persistence, ownership model, library lifecycle, revision contract or pattern-use tracking. Adding a catalog or navigation item now would imply functionality the application cannot reliably provide. The design workspace therefore contains no Patterns link, disabled placeholder, creation button or sample catalog.

## Recommended first scope — proposal

Start with **unsynced insertion**: selecting a pattern copies its supported blocks into the current document. The inserted content becomes ordinary editable blocks. Later library edits must not silently alter existing Pages or Posts.

Keep the first catalog local to one site. Reuse the canonical document schema, existing media/document pickers, immutable insertion operations and public renderer. Include title, description and a private preview so authors can distinguish similar compositions. Provide honest empty, search-no-results, loading and failure states.

Do not imply support for WordPress pattern-directory downloads, third-party block types, executable code, arbitrary embeds, or full Gutenberg pattern compatibility. Remote ingestion, pattern import/export and synchronized patterns require separate scope decisions.

## Decisions required before implementation

| Area | Required decision |
| --- | --- |
| Ownership | Who may create, edit, publish, insert, archive and delete patterns? Define the actual capabilities and ownership checks. |
| Lifecycle | Define draft versus available state, history retention, archival and permanent-deletion rules. |
| Storage | Approve a versioned persistence model and migration, with atomic writes and stale-version refusal. |
| Insertion | Define insertion locations, multi-block selection, empty compositions and error behavior. Preserve the original document if insertion fails. |
| Asset access | Decide how pattern-owned references retain assets and how insertion rechecks the actor's access to images and private documents. |
| Publication | Define who can make a pattern available to other authors and which content is visible in previews. |
| History | Specify which versions remain restorable and how their asset references participate in retention and cleanup. |

The recommended unsynced model is not approval for live references. A synchronized pattern would need dependency tracking, cycle refusal, an explicit update policy, affected-content review, publication semantics, render invalidation and fenced background jobs. It should be assessed separately.

## Validation and integration boundaries

- Validate every composition with the existing supported-block, nesting, block-count, document-size and sanitization rules. Validate the combined target document after insertion; a valid pattern can still exceed the receiving document's limits.
- Use registered, ready asset IDs. Recheck authorization when opening a pattern, rendering its preview and inserting it. Never assume that access to the library grants access to every referenced private file.
- Retain asset references for all versions covered by the approved history policy. Archival or deletion must not leave saved content or retained revisions pointing at reclaimed bytes.
- Keep library persistence and authoring-document changes separate. Insertion must support the editor's undo/redo and local dirty state without publishing the document.
- Use existing request authentication and action-bound CSRF contracts. Every write must recheck permission and the expected revision at the authoritative mutation boundary.
- Generate previews through the public renderer with an explicit private-preview boundary. Avoid executing author-provided scripts or weakening site-wide frame protections.

## Admission gate for Patterns navigation

Add the destination only when an author can complete the real create → save → discover → preview → insert → edit workflow and when the approved archive/delete lifecycle works. Required evidence includes:

1. A reviewed persistence/migration contract with upgrade and rollback procedures.
2. Capability, ownership, private-preview and stale-write regression coverage.
3. Insertion tests for nested content, maximum document size/depth/count, asset preservation, undo/redo and failed-insertion atomicity.
4. Asset-reference, history, cleanup and backup/restore coverage.
5. Browser verification for keyboard operation, narrow layouts, retained edits and actionable error states.
6. Operator and author documentation that clearly distinguishes unsynced copies from synchronized references.

Until those conditions are met, Patterns remains documentation-only deferred work.
