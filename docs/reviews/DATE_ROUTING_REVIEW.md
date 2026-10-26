> Historical note: the alias-only implementation below is superseded by PERMALINK_REVIEW.md. Month/day paths now redirect to the configured canonical format unless that format is selected.

# Date-based routing — review note

Adds an optional `/month/day/` URL prefix for posts, per the owner's ruling
on 2026-10-24 after a live demonstration showed that
`/10/24/designing-desktop-apps-with-rust-and-tauri/` returned 404 while the
post itself rendered at its slug URL.

## Change

`index.php` (front controller) only. Before the slug lookup, the request
path is matched against `~^(\d{1,2})/(\d{1,2})/(.+)$~`. When a prefix is
present, the remainder is used as the slug, and the resolved item is served
only if every condition holds:

- the item exists and is published;
- its `post_type` is `post` (pages are not date-addressable);
- the prefix month and day equal the item's publish date, computed with
  `gmdate( 'n' / 'j', published_at )` (falling back to `created_at`),
  the same UTC convention the themes use to print the post date.

Any mismatch — wrong month, wrong day, a page, or an unknown slug — falls
through to the existing real 404. The canonical URL is unchanged:
`wp_post_url()` still generates `/<slug>/`, the canonical link on a
date-URL response points at the slug URL, and the legacy `?slug=` form
still 301-redirects to the slug URL. The date form is an alias, not a new
permalink structure, so no stored data, settings, or generated links
change.

## Why

- The requested `/10/24/post-title/` form mirrors the classic WordPress
  month/day permalink convention and makes post URLs self-describing.
- Validating the prefix against the publish date (instead of ignoring it)
  prevents the same post from being served under arbitrary dates, which
  would create unbounded duplicate routes.
- Restricting the alias to posts matches the permalink convention's
  meaning; pages keep their single canonical slug route.
- Implementing it in the front controller keeps the change to one file,
  reuses the existing published/slug query, and inherits the existing
  template, canonical, and 404 handling without touching themes or the
  mutation layer.

## Verification (2026-10-24)

- `composer check` passes: PHP syntax, PHPStan 2.3.2 level 5 zero
  findings, WPCS formatting subset zero errors/warnings (the first pass
  failed the gate on two Yoda-condition findings; the comparisons were
  flipped and the full check re-ran clean), PHP suite 26 checks.
- HTTP suite: 53 checks pass. Browser suite: 42 checks pass. Upgrade
  suite: 4 checks pass. No existing expectations changed.
- Live instance matrix: matching date URL 200 with the post rendered and
  the canonical link at the slug URL; the seeded `hello-world` post also
  resolves under its publish date; wrong month 404; wrong day 404; date
  prefix on a page 404; unknown slug under a valid date 404; plain slug
  URL unchanged at 200.

## Inventory relative to Blank Install -3

1 file revised, 1 file added, none removed.

| Status | File |
| --- | --- |
| Revised | `index.php` |
| Added | `docs/DATE_ROUTING_REVIEW.md` |
