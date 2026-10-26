# v0.0.2 — WordPress Figma Alignment

Recorded October 26, 2026. Schema remains 8.

## Result and scope

Read all 17 supplied Figma reference pages in file `JFAsdPvtrs7rJhIJdlRu3g`. Page metadata identified the actual specification frames and component masters. Implementation used full design context with rendered screenshots; the sparse DataViews master was expanded into its Table, Grid and List children before use.

Administration now uses verified system typography, the WordPress neutral/theme/feedback palette, a 4px spacing scale, semantic radii and reference elevation recipes. The shell follows the sidebar/content-frame arrangement, with a 240px navigation area and a 360px editor content/inspector frame when space permits. Form labels, native controls, cards, menus, tables and the visual-builder toolbar consume those foundations.

The existing compact-density preference selects the reference's 12px Body SM base. Standard input values use 13px Body MD; labels use the 11px small-heading treatment. Responsive layouts retain the existing drawers and navigation fallback. Public-theme tokens, fonts and the page canvas's published text scale remain independently controlled.

Search and numeric controls retain the original input, label, name, form association and events. Number buttons use native stepping, preserving min/max/step behavior; read-only and disabled fields cannot be changed by these buttons. Search and number enhancements apply to dynamically added fields as well as initial forms. With JavaScript disabled, the original forms remain usable.

Five unmodified SVGs were downloaded from design-context asset responses and are served locally at their original 24 × 24 size: Search, Plus, Minus, Bold and Italic. Each file's root dimensions, nonempty content, application callsite and effective browser geometry were verified. No temporary Figma asset URLs remain in application source. [Asset provenance](../../wp-admin/assets/wordpress/README.md) records the reference and attribution.

This adapts the design system to the application's existing workflows. It does not reproduce the documentation boards, their sample photography, or unsupported sample filters and bulk actions. The existing upload queue, code window and page/document data remain functional.

See [the design-system map](../DESIGN_SYSTEM.md) for exact tokens, component mappings and every supplied node ID.

## Abstraction findings

- The previous `wp-admin/css/admin.css` root, lines 5–17, combined administration palette aliases with a shared Inter-based foundation. The new administration-only foundation isolates the verified reference values from public-theme tokens.
- Existing input and panel selectors used independent padding, weight and radius literals. Shared named tokens now supply the matched component values; the public renderer and content model are unchanged.
- Numeric stepping belongs to the native-input adapter rather than the content or design controllers. `stepNativeInput()` calls the browser's stepping API and emits the same form events consumed by existing editors. No new business rule or persistence path was introduced.

The legacy controllers and editor adapters retain their established structure. This record does not certify a repository-wide abstraction rewrite.

## Executed verification

Environment: macOS, PHP 8.4.27, Node 26.12.0, Playwright 1.62.1 and headless Microsoft Edge with web security enabled. Both browser suites install disposable sites with separate databases, credentials and fixtures. The general suite uses a 1x viewport; the focused suite also exercises Retina rendering at 2x.

| Gate | Result |
| --- | --- |
| `npm run check:js` | All 12 administration scripts pass syntax checks |
| `npm run test:builder` | 14 passing document-model checks |
| `npm run test:widgets` | 35 passing upload/tokenization checks |
| `npm run test:browser` | 115 passing checks, zero JavaScript page errors |
| `npm run test:widgets:browser` | 46 passing checks, zero JavaScript page errors |
| SVG inspection | Five nonempty, original 24 × 24 exports |
| Source inspection | Valid local documentation links; no temporary Figma runtime URLs; no public-theme source changes |
| `git diff --check` | Pass |

Total: 210 passing model/browser assertions, with syntax and asset/source inspection as additional gates. The final number-control CSS correction and unrestricted-precision guard were verified by the focused suite after the general suite passed. PHP business logic and schema were not changed, so this pass did not repeat the backend integration matrix or production-host certification.

The suites cover direct canvas editing and saving, unsaved handoff, theme/draft publication and rollback, uploads and retry, code dialogs, native numeric min/max bounds, original form values, read-only behavior, local icon geometry, 320px/390px reflow, keyboard navigation and JavaScript-disabled forms. The public page canvas retains its 16px fixture text scale. Desktop and mobile screenshots were inspected for the administration shell, form controls, media library, code window and visual builder.

## First failures and corrections

| Observation | Resolution |
| --- | --- |
| Requesting design context for the Color page returned an empty-selection message. | Metadata identified its actual frame `15448:6992`; full context and a screenshot were read from that frame. Other page links were likewise resolved to concrete frames/components. |
| The first general run reached the theme form, then an imprecise label locator matched the input and its newly accessible Increase/Decrease actions. | Keep the descriptive button labels and use exact matching for the native field in the test. The 115-check run passed. |
| A new focus assertion expected Chromium's computed outline width to remain 1.5px. | Diagnostics showed the correct 40px height, 2px radius and blue focus, with the declared 1.5px outline quantized to 1px. Correct the assertion for browser pixel rounding while still requiring the active blue outline and exact field geometry. |
| A new toolbar asset assertion ran while the form editor was still open. | Move it after entering the dedicated builder and waiting for the canvas and images. Both toolbar icons passed original-size checks. |
| Screenshot review showed a duplicate inner border in NumberControl. | The later base-input selector overrode the enhanced input reset. Increase that reset's specificity; the final focused run asserts one border and a 40px outer height. |

Detailed context responses, first logs and final logs are retained outside the application under `.local-sync/2026-10-26-figma-alignment/`. Completed browser evidence is `browser-verified.log` and `widgets-complete.log`; screenshots are in `browser-screenshots/` and `widget-screenshots/`.
