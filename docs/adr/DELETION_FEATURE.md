# Separate deletion feature specification

Status: the subsequent admin UI build implements content Trash, Restore and permanent deletion. The profiles/taxonomy review preserves those operations and strengthens revision fencing and locked transition checks. The user-deletion flow below remains a separately specified future feature; Disable is still the supported user operation. The content section records the original design requirements.

## Content

Add Trash and Restore before permanent deletion. Trashed Posts/Pages must leave public delivery, search, home-page choices and navigation. Preserve revision history while content is in Trash. Restore must recheck permissions, slug collisions and revision conflicts. Permanent deletion must be explicit, separately confirmed, and authorized inside the write transaction. It must remove or safely detach dependent revisions and queued jobs while retaining an audit event. Referenced media must not be deleted as a side effect.

## People

Keep Disable as the reversible action. A separate deletion flow must let an administrator reassign authored content/media to an existing enabled account. Do not implicitly cascade-delete content. Prevent self-deletion and deletion of the last enabled administrator. Revoke every session atomically. Preserve stable attribution for retained audit events; define the account tombstone/anonymization policy before implementation.

## Acceptance

Require action/session-bound CSRF checks and POST for every mutation, in-transaction permission checks, clear confirmation showing affected content counts, recovery from conflicts without losing input, and audit records. Focus verification on public disappearance/restoration, authorization boundaries, reassignment, dependency integrity, and last-administrator protection. Physical file cleanup and backup retention are distinct operator policies.
