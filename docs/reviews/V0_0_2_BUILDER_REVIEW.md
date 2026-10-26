# v0.0.2 — Compact Admin And Direct Canvas Editing

Recorded October 26, 2026. Schema remains 8.

## Result

Administration now uses a 12px body scale with 10–11px supporting text and compact controls. Headings retain their hierarchy. The visual editor opens as a dedicated full-width administration view at the existing authorized Post/Page controller with editor=visual.

The page canvas supports direct title/text/caption/document-label editing, selection formatting, nested composition, drag-and-drop placement, duplication, removal, undo/redo and explicit saving. Blocks and Page settings become drawers on narrow screens. The canvas uses a script-disabled same-origin iframe, the public renderer and the selected theme's styles. Public page typography retains its configured size.

Interaction reference: [GrapesJS](https://grapesjs.com/). The implementation uses the application's canonical block document, asset library and server rendering contract.

## Abstraction Findings In The Previous Build

- Low-level frameFor() in v0.0.1 page-builder.js, line 316, constructed DOM and directly mutated document collections for movement/removal. Document operations now live in a pure module with immutable changes and structural validation.
- refreshCanvas(), line 291, combined request construction, network I/O, sequence policy and whole-canvas replacement. The workspace separates the preview request adapter from rendering and editing state, and defers replacement while an editable holds focus.
- setMode(), line 367, only hid/showed sections inside the form. It could not provide an independent builder workspace. Controller mode handling now selects a dedicated page view and retains that mode after create/update.
- The administration stylesheet's 16px root and 14px supporting text caused the oversized chrome reported by the user. These now use the requested compact scale.

The legacy form editor and shared-section controls continue to use their existing adapters. This change extracts the visual workspace and document operations rather than claiming a repository-wide architectural rewrite.

## Responsibility Hierarchy

| Layer | Function | Responsibility | Calls | Allowed To Call |
| --- | --- | --- | --- | --- |
| High | mountVisualWorkspace | Establish the workspace lifecycle | createWorkspaceContext, arrangeWorkspace, connectWorkspace, initializeCanvas, workspaceLifecycle | Mid |
| High | insertBlock | Prepare and validate insertion | prepareInsertion, validateStructure | Mid |
| High | moveBlock | Prepare, validate and commit movement | prepareMove, validateMoveLocation, validateMoveHierarchy, validateMovePosition, commitMove, validateStructure | Mid |
| Mid | createBlock | Choose supported block defaults | definitionFor, cloneValue, namedError | Low |
| Mid | validateStructure | Enforce count and nesting limits | treeMetrics, namedError | Low |
| Mid | commitMove | Apply the validated move and identify its selection | arrayMoveOffset, removeAt, insertAt, findBlockPath | Low |
| Low | cloneValue | Copy a serializable value | structuredClone | Platform API |
| Low | collectionAt | Traverse the nested data shape | path-shape checks | Low |
| Low | requestBlockPreview | Request server-rendered preview data | fetch and request/response adapters | Low/platform APIs |

New document-model and workspace functions are ordered from public operations toward their helpers. Each newly introduced named function is at most 15 lines. Document rules can be tested without a browser, database or filesystem.

## Executed Verification

Environment: macOS, PHP 8.4.27, SQLite 3.54.1, Node 26.12.0, PHPStan 2.3.2 at level 5. Browser checks use a separate headless Microsoft Edge profile with web security enabled.

| Command / Suite | Result |
| --- | --- |
| composer check | Exit 0; PHP syntax, PHPStan zero errors, configured PHPCS subset; 27 smoke + 18 hardening + 30 asset checks |
| npm run check:js | Exit 0; all four administration modules |
| npm run test:builder | 14 passing document-operation checks |
| composer test:http | 59 passing checks |
| composer test:design | 45 passing design/file checks |
| composer test:documents | 103 passing document checks |
| npm run test:browser | 113 passing checks; zero JavaScript page errors |

Total: 409 explicitly counted passing checks. Static-analysis and syntax results are additional gates. Failed and intermediate runs are not added to this count.

The new browser coverage verifies unsaved handoff without publication, direct content edits, caret retention across the preview debounce, rich-text formatting, nested editing, exactly-one drag insertion, duplicate/undo/redo, image picking/captions, actual desktop/mobile frame widths, 320px/390px administration fit, drawers, full visual creation/saving, view-preserving redirects and invalid-raw-input recovery.

The additional HTTP checks verify draft isolation, Post/Page capability separation, action-bound CSRF, dedicated save actions, preview nonce refusal and recursive public rendering at nested column boundaries.

## First Failures And Corrections

| ID | First Observation | Cause | Correction / Rerun |
| --- | --- | --- | --- |
| V002-1 | The initial browser run completed the interaction flows, then failed its zero-page-errors assertion with three null contentEditable errors. | The iframe could emit its initial empty-document load before the editable srcdoc existed. | Set srcdoc before attaching the frame and ignore loads without the expected canvas element. Subsequent completed runs reported zero page errors. |
| V002-2 | At 320px, opening Blocks made its own toggle unclickable; Playwright reported the drawer intercepting pointer events. | A fixed drawer top offset did not account for a wrapped toolbar. | Place drawers in the workspace's canvas grid row. The final run opens/closes Blocks and Settings and reaches Page controls at 320px. |
| V002-3 | A later run reached the existing desktop-navigation resize assertion before navigation was visible. An earlier completed run passed the same assertion. | The assertion used immediate isVisible() while admin.js adapts navigation in an asynchronous matchMedia change listener. Playwright's bundled API documentation states isVisible() returns immediately. | Wait for the required visible navigation state, then retain the visibility assertion. Application navigation code and the expected result remain unchanged. The completed run passes. |

The invalid-raw-content recovery path was also reviewed before completion: a full workspace must reveal its Page/raw-document controls when saving is refused. The new regression verifies that rejected input remains intact and the correction field becomes visible.

Detailed local logs are retained beside the application under .local-sync/2026-10-26-v0.0.2/. First runs are browser-first.log, browser-rerun.log and browser-final.log; the final completed browser evidence is browser-verified.log. Screenshots are in browser-screenshots/.

## Scope And Verification Limits

Validation uses isolated, blank application copies and disposable credentials. Existing local content is not modified by those runs. Saving and permissions continue through the existing controllers, server validators and revision fencing.

This run does not repeat real nginx/Apache to PHP-FPM over HTTPS, production mail delivery, Safari or physical touch-device certification. G2 export/import, H1 feeds/metadata and hybrid J1 target-host work remain pending.

The distributable contains complete application source, tests and documentation. Installed databases, uploads, credentials, runtime state, logs, caches, vendor packages and Node modules are excluded.
