# Architectural invariants

| Invariant | Enforced boundary |
| --- | --- |
| PHP 8.4+, SQLite 3.45+, mandatory extensions | Configuration and installation checks |
| WAL with reliable local locking | Connection-level request and verification; deployment responsibility for filesystem suitability |
| Atomic content, revision history, and outbox | One BEGIN IMMEDIATE commit |
| Authoritative authorization | Re-read and recheck inside the transaction; permission gate before draft/editor reads |
| Separate Post/Page capabilities | One capability evaluator for both types |
| Monotonic revisions | WHERE id = :id AND revision = :expected_revision |
| Fenced workers | Active non-expired lease_token checked before side effects and completion |
| Fresh public materialization | rendered_revision === revision; otherwise sanitized live rendering |
| Safe HTML | DOM element/attribute/protocol allowlists, actual wrapper identity, escaped fallback without DOM |
| Login work admission | Atomic per-IP fixed-window reservations before password verification; forwarded headers are not trusted |
| Local uploaded images | Complete decoding and re-encoding, metadata removal, bounded memory, orientation preservation, animation rejection |
| Published image policy | Site-local content-addressed uploads only; enforced by sanitization and CSP |
| Local redirects | Reject backslashes, controls, protocol-relative URLs, malformed schemes, foreign host/port, and credentials |
| Request authenticity | Action/session-bound CSRF tokens on modifying POSTs; no GET logout |
| Revocable authentication | Hashed opaque tokens, server expiry, explicit deletion/revocation |
| Per-site secrets | Unique generated auth.key or operator secret; no hardcoded shared fallback |
| Non-executable runtime registry | Validated JSON only; no require of cache artifacts |
| Private state and source internals | Server denies hidden descendants, var, docs, vendor, tests, tools, bin, wp-includes, and PHP under wp-content |
| Blank distribution | No installed state, uploads, credentials, secrets, or development dependencies |

Public uploads are intentional. Theme PHP is trusted source, and the editor does not install executable code. Extension absence is a deployment error even though the sanitizer itself fails closed to escaped text.

Asset storage operations and backup file reads acquire the common storage lock before database write locks. Cleanup removes only canonical, regular files with no referencing media row and a 24-hour orphan grace period. Unknown files and symbolic links are preserved. Incomplete and non-image records are excluded from existing image consumers. Document upload and routed delivery are enabled under the asset state machine: only ready records are deliverable, authorization is rechecked on every request, and private documents answer with an indistinguishable 404. Image variants and edited copies are derived records pointing at their master: derivation grants no access and changes no attribution, an interrupted derivation is completed or removed by maintenance, and replacing an image moves only current featured, block and shared-section references after a full permission check — avatars, the site logo and all revision history keep the original.

Design/file publication rechecks administrator authority and expected live version inside the write transaction. Draft previews are session-bound and never mutate live configuration. A theme health failure leaves live design unchanged. Managed files use fixed names and fixed MIME; executable PHP, configuration, uploads and arbitrary paths are outside the editor. Shared-section/design-history image references are protected from media removal. Document references in content are validated for the author's access at save and again inside the write transaction; publication additionally requires a public document.
