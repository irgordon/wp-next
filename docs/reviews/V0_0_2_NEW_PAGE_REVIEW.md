# v0.0.2 — New Page inside Site design

Recorded October 28, 2026. Release remains v0.0.2; schema remains 8.

## Delivered behavior

The Pages heading now has a New Page button. One activation creates an Untitled Page as a private draft and opens its visual editor inside the existing design workspace. The application supplies an empty version-1 block document and the curated Standard template. The authenticated actor owns the draft. The site default status and caller-supplied content/type/author/status fields cannot override these creation defaults.

The original design form stays mounted, retaining its unsaved values. Creation clears Page-list search/status filters and reveals the new draft on its actual page in the existing title/ID sort. The embedded editor reports the saved Page's current list position so an unfiltered list follows a rename. Renaming, block insertion and Save Page use the existing content controller, nonce, revision checks and status selector.

Creating draft feedback disables the button and ignores repeat activation while the request is pending. Refusals show an error and retain the workspace. If a response is lost or malformed, the UI explains that creation could not be confirmed and directs the user to check for an Untitled Page before trying again. It never retries a mutation automatically. This pending-operation guard is not durable cross-request idempotency; a separate intentional activation creates a separate draft with the normal unique slug.

Returning without editing leaves the created draft in the library. Discard Page edits discards unsaved edits, not the persisted draft. Page saves follow their selected status; design saving/publication stays separate. Without JavaScript, the existing full-page creation form remains reachable through an explicitly labelled fallback link.

## Boundaries and structure

- `themes.php` handles a separate `create_page` POST before the design-persistence controller. Authentication, current administrator/edit-pages permission and the `design-create-page` action nonce are required. GET and inventory/preview nonces cannot create a draft; the creation nonce cannot publish a design.
- `wp_insert_post()` remains the mutation facade. Normal service validation, transactional authorization, slug uniqueness, outbox handling and rollback stay in use. There is no direct SQL insert or schema change in the workspace adapter.
- The creation response uses private/no-store, no-index and default DENY headers. The returned Page-specific signed editor URL uses the previously verified scoped SAMEORIGIN policy. No frame privilege was added to the public-content canvas or live preview.
- The new orchestration reads authorize → create draft → reply. Fixed draft defaults live in a named policy constant. In the initial implementation, `wp_design_read_created_page()` combined its SQL query with translation of a missing row into a Page error. That domain decision now belongs to `wp_design_create_page_draft()`; the row reader, insertion facade, response presenter and JSON writer remain implementation adapters.
- Creation transport/acknowledgement parsing is separate from opening the editor and showing feedback. The existing same-origin/source/Page message checks remain in use. The changed Page stylesheet import has a content version so cached styles do not conceal the new heading layout.

## Executed verification

Local macOS with PHP 8.4.27, SQLite 3.54.1, Node 26.12.0, Playwright 1.62.1 and headless Microsoft Edge with web security enabled. HTTP/browser checks install disposable sites with their own runtime, database and credentials. They do not create Pages in the installed local site.

| Gate | Result |
| --- | --- |
| `composer check` | Pass: syntax, PHPStan level 5 with zero errors, configured PHPCS subset and 87 PHP assertions |
| `npm run check:js` | All 16 administration scripts pass |
| `composer test:design` | 129 assertions: 45 design/file, 28 live-preview and 56 Page-workspace checks |
| `npm run test:pages:browser` | 38 assertions pass; zero JavaScript page errors |
| `npm run test:browser` | 115 assertions pass; zero JavaScript page errors |
| `npm run test:preview:browser` | 29 assertions pass; zero JavaScript page errors |
| `npm run test:widgets:browser` | 62 assertions pass; zero JavaScript page errors |

Total: 460 PHP/HTTP/browser assertions, with static analysis, formatting and syntax as additional gates. The final domain-error extraction was verified by Composer, all design HTTP suites and the Page browser suite. Existing general, preview and widget browser suites passed before that behavior-preserving extraction.

HTTP coverage additionally proves fixed draft defaults against a Published site default and forged content fields, the authenticated owner, empty editable content, curated template, sorted list position, a working signed editor URL, private response headers, no public Page/homepage change, unchanged design options/revisions, unique slugs and revoked-permission refusal. A temporary SQLite insert-refusal trigger exercises the real service error response and transaction rollback; the trigger is confined to the disposable database and removed before checking the unchanged records.

The browser suite verifies the actual button, progress state, synthetic repeat activation, refused-creation feedback, direct embedded opening, retained design values, revealing the new draft in a paginated list, canvas renaming/block editing, native draft saving and correct list position after the rename. It also commits a real creation through a test-local route, then deliberately drops its acknowledgement: one draft exists, no automatic retry occurs, and the draft can be found and reopened. Desktop and mobile Page-list/editor screenshots were inspected.

## First failures and corrections

| Observation | Correction |
| --- | --- |
| The edit check searched for a button named Paragraph while the palette exposes Add paragraph block. | Use the actual accessible name and keep the real canvas edit/save assertions. |
| Initial fixture string quoting failed Python compilation. | Correct the fixture source and validate it before rerunning. No application code or assertion was weakened. |
| A runtime theme-listener fixture did not produce the intended mutation refusal. | Replace it with a deterministic temporary SQLite trigger, proving the actual insert failure, protected error response and complete rollback. |

Evidence is outside the distribution in `.local-sync/2026-10-28-new-page/`. Final logs include `composer-delivery.log`, `design-http-delivery.log`, `browser-delivery.log`, `general-browser.log`, `preview-browser.log`, `widgets-browser.log` and `js-final.log`. Earlier failed/intermediate runs remain separate. Screenshots are in `browser/`.

The existing CI commands already include the extended HTTP and Page browser suites. This record supplies scoped local regression evidence; it does not certify production server configuration or other browsers/devices.
