# v0.0.2 — Focused charcoal design navigation

Recorded October 27, 2026. Schema remains 8.

## Delivered scope

Site design opts into a focused charcoal sidebar, following the supplied WordPress SiteEditor reference (`16314:43026`) and its actual Sidebar master (`15877:14447`). Full design context and the rendered Sidebar were inspected before implementation. The 288px rail, charcoal surface, neutral labels and active/hover treatments are scoped to this workspace.

The main administration retains its light navigation and Content, Media, Design and Settings groups. Back to admin returns to that shell; Code & files uses the ordinary administration view. The Post/Page visual builder is unchanged by this opt-in mode.

The contextual destinations are Theme, Styles, Navigation, Header, Footer, Templates and History. With JavaScript, selection reveals the relevant sections of the existing form without removing or disabling its other values. Native links and hashes retain deep linking. With JavaScript disabled, every section remains available as part of the complete native form.

Save draft and Publish design remain available in the sidebar and explicitly target the existing `design-form`. Structural menu edits, direct field edits and their reversals update the saved/unsaved indicator. Native validation reveals the section containing the first invalid control. Saved drafts expose the existing private Preview design action. No inline-preview endpoint, schema change, permission change or automatic publication was added.

Templates is a read-only inventory from `wp_curated_templates()`, describing choices for the currently published theme. The bundled themes expose Standard, Wide and Landing. Assignment remains in the Page editor; this workspace adds no template creation or template-source editing.

Patterns has no application navigation item or placeholder. [The Patterns inclusion review](PATTERNS_INCLUSION_REVIEW.md) records the deferred status, proposed unsynced first scope, unresolved decisions, asset and history boundaries, and the admission gate for a real reusable-pattern workflow.

## Implementation boundaries

- `admin-header.php` selects the focused sidebar only when the Site design controller sets its opt-in flag.
- `design-sidebar.php` renders contextual links and existing native form actions. Its view inputs have explicit defaults; no analysis suppression was added.
- `design-templates.php` renders the existing published-theme allowlist.
- `design-workspace.js` adapts section visibility, current-location semantics, first-invalid-field recovery and native-form state. It retains the original inputs and server contract.
- `design-workspace.css` scopes the charcoal treatment and responsive layout. The shared public theme tokens and global navigation model remain unchanged.

## Executed verification

All workflow suites install disposable sites and use separate fixture data. The existing local site's design and database are not changed by the test runs.

| Gate | Result |
| --- | --- |
| `composer check` | Pass: syntax, PHPStan level 5 with zero errors, configured PHPCS subset; 87 PHP assertions |
| `npm run check:js` | All 13 administration scripts pass |
| `composer test:design` | 45 HTTP checks pass |
| `npm run test:browser` | 115 checks pass; zero JavaScript page errors |
| `npm run test:widgets:browser` | 62 checks pass; zero JavaScript page errors |
| Source/documentation inspection | Clean diff whitespace, valid local links, no change to curated-template or design persistence logic |

Total: 309 passing PHP/HTTP/browser assertions, with syntax, analysis and formatting as additional gates. The final focused suite also verifies the mobile menu's readable hover/focus state, structural edit/reversal detection, value retention, hidden-field validation recovery, existing form association, curated-only inventory, absent Patterns destination, return to the original main groups, and native draft saving without JavaScript.

Screenshots were inspected for the curated-template view, the contextual section controls, and mobile navigation. Native preview, publication, shared-section editing and rollback remain covered by the general browser and design HTTP suites.

## First failures and corrections

| Finding | Correction |
| --- | --- |
| PHPStan identified implicit `$error` and `$draft_id` inputs in the new sidebar partial. | Normalize both view inputs through explicit defaults. The complete Composer gate passed; no suppression or baseline was used. |
| The preview link's decorative arrow changed its accessible name and the existing browser lookup could not locate it. | Mark only the arrow as decorative, preserving the existing Preview design accessible name and private destination. |
| A mobile browser check tried to choose History while the contextual menu was collapsed. | Update the navigation helper to open Menu before choosing a section, using the normal visible controls. |
| Screenshot review found dark Menu text on the charcoal hover surface, and the secondary action inherited the later generic submit style. | Strengthen the scoped hover/secondary selectors. The focused suite verifies the mobile text color after keyboard dismissal. |

Local evidence is retained outside the application in `.local-sync/2026-10-27-focused-design/`. Completed logs are `composer-final.log`, `design-http.log`, `browser-final.log` and `widgets-complete.log`; earlier failed runs remain separate. No production-host certification or new Patterns functionality is claimed.
