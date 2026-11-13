# v0.1.4 first-run guidance — decisions and reasoning

Recorded 2026-11-13, from Ian's usability rulings U1:B, U2:A, U3:A.
The production code carries no comments for these changes; this
document is where the reasoning lives.

## The install-complete screen is the guidance surface

Ruling U2:A puts the next steps on the install-complete screen rather
than a persistent dashboard panel: it is the moment of highest intent,
and it is shown once. Ruling U1:B keeps the starter content published
(seeding drafts, U1:A, would change what a fresh site is) and instead
names it on that screen. The panel links "View your site" directly,
and routes the sign-in-dependent actions — editing the starter items,
writing a new post, changing the design — through
`login.php?redirect_to=`, so signing in lands on the task instead of
the dashboard.

Two truthfulness constraints shaped the implementation:

- The starter items are looked up live by slug and only listed when
  they still exist. The same screen renders on later visits to
  install.php, and naming deleted content would be a lie.
- On the install request itself the global `$wpdb` is still null —
  bootstrap connects only when the database file already exists — so
  the panel opens the same `WP_SQLite_DB` connection bootstrap would,
  falling back to the global when it is present.

## The contrast refusal names the direction

The design save already refused accent/background pairs under 4.5:1;
the error just did not say how to fix it (U3:A). The message now keys
on the luminance comparison: an accent at or above the background's
luminance is called too light ("try a darker accent or a lighter
background"), below it, too dark (mirror wording). The `>=` boundary
guarantees each branch names at least one direction that can actually
work — with equal luminances, darkening the accent always works.
A live in-form hint (U3:B) was not taken; the save-side guard plus a
directional message covers the failure without new form machinery.
