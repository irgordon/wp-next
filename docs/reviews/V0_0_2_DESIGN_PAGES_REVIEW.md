# v0.0.2 — Pages inside Site design

Recorded October 27, 2026. Schema remains 8.

## Delivered behavior

Pages is a section in the focused charcoal workspace. Title search, status filtering and pagination show at most 20 editable, nontrashed Pages per response. Published, Draft and Archived are supported; Posts and Trash are excluded. Empty results have an explanation. Failed list requests retain the rows and show recovery guidance. Native GET forms also support search/filter/pagination without JavaScript.

Selecting a Page renders its current content with the unsaved design in the adjacent private preview. It never assigns the homepage. Preview homepage restores that target. Existing preview cancellation, response ordering, validation, read-only isolation and cache boundaries remain in place; the selected Page is part of the request identity and scroll target.

Open visual editor expands the existing Page builder inside the workspace content area. The charcoal navigation and original design form stay mounted. The editable canvas receives the private preview's site-local styles and body classes, retaining the canvas's script-disabled editing boundary. Back to page list returns to the selection. Keyboard focus moves to the return action on open and back to the selected Page on return.

Unsaved Page content opens a Keep editing / Discard Page edits dialog before leaving the editor, changing workspace sections or submitting the design. Discarding removes the Page's tab draft and retains design controls. Failed Page saves remain dirty, and original revision-conflict handling retains the submitted content. A browser-level warning also protects unsaved Page content when leaving the workspace.

Save Page uses the existing content controller and follows the Page's selected Status. Saving a draft does not expose it publicly; saving a published Page updates public content. Saving content never saves or publishes the site design. The Page list/title and selected preview refresh after a successful save. Design publication retains its separate explicit native action.

Templates stays curated-only and Patterns remains deferred in its existing review.

## Boundaries and structure

- The existing administrator-only `themes.php` route handles the bounded `list_pages` action using a separate session/action nonce. Search parameters are bound; wildcard characters are escaped literally. No new executable endpoint is required.
- Private preview accepts an explicit Page ID, validates a nontrashed Page with current edit access, and renders it without changing homepage/design options. Drafts and archives are allowed only through that authenticated explicit selection.
- Default responses still emit DENY and frame-ancestors none. Only a current administrator opening an existing editable, nontrashed Page in visual workspace mode, with a session/action/Page-bound nonce, receives SAMEORIGIN and frame-ancestors self. Missing/invalid scope and ordinary editors retain DENY. Reverse proxies must preserve this scoped response policy.
- The outer embedded editor is trusted authenticated application code. It is distinct from the script-disabled public-content canvas and read-only live preview. Parent/child messages require the same origin, expected source window, channel and Page ID. The public preview receives no new sandbox privileges.
- Native content-save CSRF, capability checks, optimistic revisions and publication status remain authoritative. Save actions and redirects retain the authorized workspace context.
- The initial inventory implementation mixed query execution/search escaping with pagination decisions (`wp_design_pages_inventory`, initial L24–26). It now reads as criteria → window → collection. Status normalization and pagination are separate from bound query/count/row wrappers. This scoped change does not claim a repository-wide SLAP audit.

## Executed verification

macOS with PHP 8.4.27, SQLite 3.54.1, Node 26.12.0 and headless Microsoft Edge through Playwright 1.62.1. Web security remained enabled. Fixtures install disposable sites with separate databases, credentials and runtime directories, then remove them. The installed local site's data is not modified by verification.

| Gate | Result |
| --- | --- |
| `composer check` | Pass: syntax, PHPStan level 5 with zero errors, configured PHPCS subset, 87 PHP assertions |
| `npm run check:js` | All 16 administration scripts pass syntax checks |
| `npm run test:builder` | 14 document-model assertions pass |
| `npm run test:widgets` | 35 widget-model assertions pass |
| `composer test:design` | 112 assertions pass: existing 45 design/file, 28 live-preview and 39 Page-workspace checks |
| `npm run test:browser` | 115 assertions pass; zero JavaScript page errors |
| `npm run test:widgets:browser` | 62 assertions pass; zero JavaScript page errors |
| `npm run test:preview:browser` | 29 assertions pass; zero JavaScript page errors |
| `npm run test:pages:browser` | 23 assertions pass; zero JavaScript page errors |

Total: 477 PHP/HTTP/model/browser assertions, with syntax, analysis and formatting as additional gates. The final inventory extraction and presentation changes were checked by Composer and all three design HTTP suites plus the Page browser suite. The existing general and private-preview browser suites passed before that behavior-preserving extraction; the widgets suite passed alongside the final checks. Final declaration ordering and changed-line whitespace cleanup also passed JavaScript syntax, the Page browser suite and diff checks.

The HTTP coverage includes literal wildcard search, pagination clamps, status/type/Trash exclusion, native GET rendering, draft/archive private rendering, invalid IDs, unchanged persistence and anonymous output, default versus scoped frame policy, invalid nonce/mode/Page scopes, login and capability revocation, save nonce refusal, embedded redirects, retained draft status and optimistic-conflict refusal.

The Page browser suite exercises actual search/filter/pagination, empty results, current unsaved typography in the embedded canvas, keep/discard behavior, protected design submission, saving and returning, retained design values, refreshed preview/title, homepage reset and mobile fit. Desktop Page-list/preview and editor screenshots, plus mobile preview and editor screenshots, were inspected.

## First failures and corrections

| Observation | Correction |
| --- | --- |
| The first browser fixture inserted legacy HTML while expecting a paragraph textbox. | Seed a real version-1 paragraph block. Preserve the existing legacy HTML behavior. |
| The guest inventory assertion expected 403 but the shared admin boundary redirects unauthenticated requests to login. | Accept the real login redirect or refusal and assert that no private inventory is returned. No permission code was weakened. |
| A mobile navigation click raced the existing asynchronous resize handler. | Wait for the menu's narrow-screen state before opening it. The real click/visibility assertions remain. |
| An early mobile screenshot captured the intentionally dimmed previous rendering while selection loaded. | Wait for the selected Page and current marker, then capture the actual 390px preview. |

Evidence is outside the distribution in `.local-sync/2026-10-27-design-pages/`. Completed logs include `composer-final.log`, `design-http-final.log`, `general-browser.log`, `widgets-browser.log`, `preview-browser.log`, `pages-browser-delivery.log`, `pages-browser-final-source.log` and `js-final.log`. Earlier failures remain separate. Screenshots are in `browser/` and the existing-suite directories.

CI includes the new HTTP and Page browser checks. These local results do not establish physical-device/Safari or production reverse-proxy compatibility; source packaging excludes installed state and development dependencies.
