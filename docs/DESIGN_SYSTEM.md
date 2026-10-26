# WordPress design-system alignment

Administration follows the supplied [WordPress Design System file](https://www.figma.com/design/JFAsdPvtrs7rJhIJdlRu3g/WordPress-Design-System--Community-). Values were read through Figma design context and checked against rendered references. The application remains PHP, CSS and native JavaScript; it does not require React, Tailwind or a remote design runtime.

## Foundations

`wp-admin/css/wordpress-foundations.css` owns administration tokens. Existing component styles consume those tokens instead of changing the shared public-theme palette. The iframe page canvas loads public theme styles independently.

| Foundation | Verified values and use |
| --- | --- |
| Theme | `#3858e9`, darker-10 `#2145e6`, darker-20 `#183ad6`; 4% and 8% interaction tints |
| Grays | `#f0f0f0`, `#e0e0e0`, `#ddd`, `#ccc`, `#949494`, `#757575`, `#2f2f2f`, `#1e1e1e` |
| Feedback | Red `#cc1818`, yellow `#f0b849`, green `#4ab866`; foreground text retains readable contrast |
| Typography | Native system stack; regular 400 and medium 500; sizes 11, 12, 13, 15, 20 and 32px |
| Line heights | 16, 20, 24, 28, 32 and 40px |
| Spacing | A 4px baseline, with 4, 8, 12, 16, 24, 32, 40, 48 and 56px tokens |
| Radius | 1px nested actions, 2px controls, 4px menus, 8px cards/dialogs, 999px pills, 50% circles |
| Elevation | Four reference shadow recipes for frames, notifications, menus and dialogs |
| Layout | 240px navigation, 360px content/inspector frame, flexible content/preview areas |

The requested compact administration density uses the reference Body SM token (12px) for the base. Input values use Body MD (13px/20px), labels use the small heading treatment (11px/16px, medium, uppercase), and page headings use 20px/24px. Responsive layouts adapt the reference dimensions when there is insufficient space; the builder retains its existing mobile drawers. The public page's own text scale is unaffected.

## Component mapping

| Reference | Application use |
| --- | --- |
| Layout and BlockEditor | Navigation/content frames and the dedicated palette/canvas/inspector workspace |
| Card and DataForm | Panels, media cards, settings forms, compact field labels and help text |
| DataViews | Existing content tables, media grids, row-action visibility and selected/hover colors |
| Menu | Group labels, menu spacing, parent-page popup radius and elevation |
| InputControl and SearchControl | Native field borders, focus/error states, help text and local search icon |
| NumberControl | Plus/minus actions around the original number input; native min/max/step rules remain authoritative |
| RadioControl | Native radio/checkbox values, 16px controls, readable labels and themed selection |
| Toolbar | Compact editor actions and original 24px Bold/Italic assets |
| ConfirmDialog | Dialog radius/elevation and action spacing; existing explicit confirmation flows remain in use |

These mappings apply the system to existing product workflows. They do not add demonstration filters, bulk actions or view switches that the application's data model does not implement. Uploaded content remains dynamic rather than being replaced by reference photography. The established upload queue and charcoal code window retain their functions while adopting compatible foundation tokens.

Search and number enhancements keep the same native input, label, name, form and events. Number buttons use the browser's stepping API and dispatch ordinary input/change events. Read-only and disabled fields cannot be stepped. Inputs with `step="any"` retain their original control. Without JavaScript, the original forms remain usable.

## Supplied reference inventory

Each link targets the page supplied by the user; actual child frames/components were retrieved before implementation.

| Reference | Figma node |
| --- | --- |
| Color | `13482:72791` |
| Typography | `1224:64810` |
| Spacing | `2272:18367` |
| Radius | `2272:18499` |
| Elevation | `545:20983` |
| Layout | `15991:23086` |
| Card | `16471:137689` |
| ConfirmDialog | `16547:38990` |
| DataForm | `25478:29007` |
| DataViews | `16547:36679` |
| Menu | `991:34615` |
| InputControl | `991:34623` |
| NumberControl | `16471:138738` |
| RadioControl | `13532:31910` |
| SearchControl | `3025:46718` |
| Toolbar | `13919:17541` |
| BlockEditor | `15620:61349` |

The large DataViews response was expanded into its Table, Grid and List children for full design context. The documentation boards themselves are specifications rather than application screens; their cover artwork, labels and sample content are not implementation assets.
