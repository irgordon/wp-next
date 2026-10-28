# v0.0.2 — Publish Page inside Site design

Recorded October 28, 2026. Release remains v0.0.2; schema remains 8.

## Delivered behavior

An unpublished Page in the workspace visual editor now has separate native Save Draft and Publish Page buttons. Save Draft persists the working content as a private draft. Publish Page saves the current content and makes the Page public without navigating the parent workspace. New Pages still begin as drafts.

After publication, Update Page replaces those actions. Typing remains local until that action applies edits to the public Page. The save shortcut targets Save Draft for unpublished Pages and Update Page for published Pages; it never targets Publish Page. This change does not add private revisions of already published Pages. Other status administration remains in the normal Content screens.

The focused editor uses explicit actions in place of manual status selection. Its header/footer and toolbar availability reflect the persisted record. Draft saved, Page published and Page updated have distinct success feedback. Failed saves retain submitted content and restore the actual saved status; the unpublished Page keeps its draft/publication actions for correction or retry. A reused result URL cannot label a draft as published.

The Page list and private preview refresh after a successful save/publication. The original design form stays mounted and keeps its unsaved settings. Public visitors see the Page with the published site design; the workspace continues previewing its private design. Page content publication never publishes design options or creates design revisions.

## Boundaries and structure

- Native submitters stay associated with the original `post-editor` form. The visual toolbar moves those same elements; it does not clone them or rewrite their actions with JavaScript.
- The existing `post.php` controller requires read/edit access, the signed workspace scope and the Page-specific content nonce before applying intent. A pure workspace-scoped action map fixes Save Draft, Publish Page and Update Page to Draft, Published and Published. A submitted hidden status cannot reverse those actions.
- `wp_update_post()` and the original mutation service remain authoritative for current permission, expected revisions, media/content validation and transactional writes. There is no new publication endpoint, direct SQL write or permission bypass. Ordinary editors retain their original status behavior.
- Publication failures preserve Page content and the original expected revision. A stale or repeated submission cannot silently replace a newer revision. A failed transaction leaves its Page and revision snapshot unchanged.
- Result parameters are restricted to known actions. Success messages must match the actual saved status. The existing same-origin/source/Page bridge checks and frame protections remain in place; no preview sandbox privilege was added.
- The original `bindEditorCanvas()` mixed canvas lifecycle work with a hard-coded Save Page label. Labels and action selection now come from the pure Page policy and native view. Toolbar placement is separated from canvas setup. State reporting uses persisted status rather than an uncommitted status field.
- The shared builder modules have fresh content/file versions so a reload receives the new native-action placement and state handling. No runtime package or dependency was added.

## Executed verification

Local macOS, PHP 8.4.27, SQLite 3.54.1, Node 26.12.0 and headless Microsoft Edge through Playwright 1.62.1, with web security enabled. HTTP/browser suites install disposable sites with their own databases, credentials and runtime directories. They do not publish Pages in the installed local site.

| Gate | Result |
| --- | --- |
| `composer check` | Pass: syntax, PHPStan level 5 with zero errors, configured PHPCS subset and 87 PHP assertions |
| `npm run check:js` | All 16 administration scripts pass syntax checks |
| `composer test:design` | 145 assertions: 45 design/file, 28 live-preview and 72 Page-workspace checks |
| `npm run test:pages:browser` | 50 assertions pass; zero JavaScript page errors |
| `npm run test:browser` | 115 assertions pass; zero JavaScript page errors |
| `npm run test:widgets:browser` | 62 assertions pass; zero JavaScript page errors |
| `npm run test:preview:browser` | 29 assertions pass; zero JavaScript page errors |

Total: 488 PHP/HTTP/browser assertions, with syntax, static analysis and formatting as additional gates. The final notice/result-parameter policy was checked with the full design HTTP suite and Composer. Existing general, Page, widget and preview browser suites passed with the new native controls and module versions.

HTTP checks prove private Save Draft despite a submitted Published status; positive publication despite a Draft field; original nonce, login and capability refusal; stale publication; rollback of a forced update failure; retained content and actual Draft actions; Page-specific signed redirects; actual anonymous content after publication; published Update Page despite a Draft field; duplicate-update revision fencing; stale-result notice refusal; and unchanged design configuration/homepage output. A temporary SQLite trigger affects only the disposable fixture and is removed after the failed transaction.

The browser suite uses the real buttons and save shortcut. It forces a publication failure, keeps the submitted canvas content, exercises the unsaved-edits guard, retries successfully, observes publication feedback and Update Page, checks anonymous delivery and confirms that later typing does not change public content until Update Page. It verifies retained unsaved typography/design history, list status refresh and desktop/mobile controls. Draft-mobile and published-desktop/mobile screenshots were inspected.

## First failure and correction

PHPStan could not establish the new action partial's inherited `$post` variable. The partial now explicitly declares its runtime global binding, matching the shared editor's convention. No baseline, suppression or widened type was added. The adjusted browser assertions use the new Save Draft label while retaining the actual mutation/privacy checks.

Evidence is outside the distribution in `.local-sync/2026-10-28-publish-page/`. Completed logs include `composer-final.log`, `design-http-final.log`, `browser-publish-first.log`, `general-browser.log`, `widgets-browser.log`, `preview-browser.log` and `js-final.log`; the initial analysis failure remains separate. Screenshots are in `browser/`.

Existing CI commands run the extended suites. These are scoped local results, not production hosting or physical-device/browser certification. Templates remain curated-only and Patterns remains deferred.
