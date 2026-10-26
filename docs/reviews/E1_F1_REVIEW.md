# E1/F1 Review — Accounts, Email And User Deletion

Date: 2026-10-26. Baseline: the C1/D1 tree (155 files, schema 7) plus the
rollup documentation restructure. Implements the approved v1.0.0 scope items
E1 and F1, in the order Ian set. Schema advances to 8.

## What was built

### Mail transport (E1)

- `wp-includes/mail.php`: `wp_mail()` sends through a minimal SMTP client
  (plain, implicit TLS, or STARTTLS with the PHP 8.4 crypto signature; AUTH
  LOGIN when a username is configured) when an SMTP host is set, and falls
  back to the PHP mail transport otherwise. Every attempt records its real
  outcome in the `mail_last_status` option; failures return `WP_Error` and
  are never reported as sent.
- Configuration lives in Settings → General → Mail (host, port, encryption,
  username, set-only password with an explicit clear control, From address
  and name) or in `WP_MAIL_*` environment variables, which take precedence.
  A password saved in settings is stored in the site database and is
  therefore inside database backups; the settings screen says so and the
  environment variable is the way to keep it outside the database.
- The settings screen shows the last recorded delivery result and offers a
  test send to the administration address that reports the transport's real
  answer.

### Account tokens, invitations, recovery, magic links (E1)

- Schema 8 adds `account_tokens` (schema.php): purpose (`invite`, `reset`,
  `magic`), the SHA-256 hash of a 256-bit token (plaintext exists only in
  the emailed link), an optional user id, the invited role, expiry and a
  consumed stamp. Issuing a token invalidates earlier unconsumed tokens for
  the same email and purpose; issuance is rate-limited to five per email +
  purpose per hour (operator CLI issuance is exempt).
- Opening a link never consumes it. Reset and invitation landings render a
  form; the magic-link landing requires an explicit "Sign in" confirmation.
  Consumption happens inside the same transaction as the password change,
  the account creation, or the session creation. Reset and magic tokens
  whose account has since been disabled or deleted are refused.
- Invitations: People → "Invite by email" (address + role). The recipient
  accepts by choosing a username (prefilled with the derived suggestion)
  and a password, and is signed in. Inviting an existing address is refused
  in admin; a failed delivery deletes the new invitation and says so —
  a rate-limited reissue reports the limit instead of deleting anything.
- `wp_derive_username()` builds the suggestion from the email local part,
  normalized to lowercase letters, digits, dots, underscores and hyphens,
  with a numeric suffix on collision; the full address is never the default.
  Changing an account's email never changes its username (profile contract,
  now covered by the account suite).
- Recovery and magic-link requests always answer with the same neutral
  sentence whether or not the address has an account, and send nothing for
  unknown addresses. Delivery failures still surface to operators through
  the recorded status and an `account_link_delivery_failed` audit event.
- Magic links are opt-in (`magic_links_enabled`, default off). While off,
  the login page hides the option and the endpoint refuses.
- Operator recovery without mail: `php bin/wp user:reset-link
  <username-or-email>` mints a one-time reset link (documented in
  INSTALL.md).

### Disable and deletion (F1)

- Disable already existed and is unchanged: it blocks sign-in, existing
  sessions stop validating, and the last enabled administrator is
  protected. The account suite now covers the full disable/re-enable cycle.
- `wp_delete_user()` (user.php) is the separate, separately confirmed
  deletion flow Ian specified in `docs/adr/DELETION_FEATURE.md` and F1:
  People → Delete account opens a confirmation showing the account's real
  counts (posts, pages, media including variants, unpublished design
  drafts to be discarded, live sessions) and requires an existing enabled
  reassignment target plus an explicit confirmation checkbox. Inside one
  transaction the service rechecks that the actor is an enabled
  administrator, refuses self-deletion, refuses a missing/disabled target,
  and refuses deleting the last enabled administrator; it then reassigns
  posts and media (bytes are never cascade-deleted), discards the user's
  unpublished design drafts, deletes sessions and account tokens, deletes
  the user row, and writes the tombstone: an `user_deleted` audit event
  carrying only the user id, the username, the reassignment target and the
  counts — no email or profile fields. The confirmation text states that
  earlier backups may still contain the account until they expire.

## Tests

New suite `tests/accounts-http.py` (`composer test:accounts`, also in CI)
runs a blank install against an in-process SMTP sink: **60 checks** —
schema 8 and the token table, test-mail delivery through authenticated
SMTP, settings persistence, the invitation lifecycle (no account before
accept, GET does not consume, failed accept preserves the token, single
use, existing-address refusal, derivation `jane.doe-news` and the `sam-2`
collision suffix), neutral recovery for known and unknown addresses,
scanner-safe reset (old password still works after opening the link),
session revocation on completion, single use, the five-per-hour issuance
limit, magic links off by default and opt-in with an explicit confirmation
step, an outstanding magic link dying when the account is disabled, email
change without rename, disable/re-enable, the CLI reset link, and the
deletion contract (real counts, confirmation required, disabled target
refused, reassignment of posts and media with content staying public,
session loss, tombstone without contact fields, self-deletion refused, a
disabled administrator deletable while an enabled one remains), and a
dead-transport phase proving the failure is reported in settings, recorded
for operators, auditable, and never dressed as success while the public
answer stays neutral.

Three harness defects in the new suite were found and fixed during
bring-up (split guest clients losing the cookie-bound CSRF token, a wrong
editor field name, a reused port across a server restart); none was a
product defect. Two product defects were found and fixed: guest nonces on
the new login flows were minted after page output began, so their binding
cookie could not be set (they are now minted before the header renders),
and token consumption used the un-namespaced database class name (the
`use` import now matches schema.php). A third fix makes issuance errors
typed, so a rate-limited invitation reissue no longer deletes the pending
invitation, and outstanding reset/magic tokens are refused once the
account is disabled.

Full gate run on the finished tree is recorded in the delivery message for
this step; the corrected rollup supersedes the earlier packages
as the single deliverable.

## Not covered

- Real SMTP over STARTTLS/implicit TLS and a real mailbox: the client code
  paths are exercised only in plain mode against the local sink; TLS modes
  and certificate behavior remain host verification items under J1.
- PHP-FPM execution of the new flows (J1) is not certified by these
  suites; they run on the development router like the other HTTP suites.
