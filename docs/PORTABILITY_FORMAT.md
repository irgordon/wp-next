# Portability Archive Format

The native export format produced and consumed by Export / Import
(`wp-admin/portability.php`, `bin/wp export`, `bin/wp import`). This is the
compatibility contract for the format; the implementation is
`wp-includes/portability.php`.

## Container

A ZIP archive with two kinds of entries:

- `manifest.json` at the archive root (required).
- `files/<sha256>.<format>` — media bytes, content-addressed and deduplicated
  by digest. Image entries hold the sanitized master only; derived sizes are
  not archived and regenerate on import. Document entries hold the exact
  stored bytes, including private documents: an export archive is a
  backup-class artifact and must be handled like one.

Entry names must be plain relative paths. Archives with absolute paths,
parent traversals, duplicate entries, more than 2,000 entries, a single entry
over 64 MiB, a total over 512 MiB, or a manifest over 8 MiB are refused
before anything is written.

## Manifest

JSON object:

| Field | Meaning |
| --- | --- |
| `format` | Always `wpnext-portability`. Anything else is refused. |
| `version` | Format version, currently `1`. Unsupported versions are refused, never partially imported. |
| `generator` | Producing application name. Informational. |
| `exported_at` | ISO 8601 export timestamp (UTC). |
| `site` | `{name, home}` of the source site. Informational; never applied to the target. |
| `counts` | `{authors, terms, media, posts}`; must match the record arrays exactly or the archive is refused. |
| `authors` | `source_id`, `username`, `email`, `display_name`, `first_name`, `last_name`, `biography`, `website`. No password hashes, capabilities or session data are ever exported. |
| `terms` | `source_id`, `taxonomy` (`category` or `post_tag`), `slug`, `name`, `description`, `parent_source_id`. |
| `media` | `source_id`, `kind` (`image` or `document`), `format`, `visibility`, `original_name`, `alt_text`, `caption`, `sha256`, `path`. |
| `posts` | `source_id`, `type` (`post` or `page`), `status` (`draft`, `published` or `archived`), `author_source_id`, `slug`, `title`, `content_raw` exactly as stored, `excerpt`, `metadata`, `featured_source_id`, `term_source_ids`, `published_at`, `created_at`, `updated_at` (Unix timestamps). Trashed content is not exported. The internal `render_epoch` metadata key is stripped on export. |

## Compatibility rules

- `source_id` values are meaningful only inside one archive. Import rebuilds
  every relationship (authorship, featured media, term assignment, term
  parents, block media references) through an explicit map built during the
  run; a target ID is never copied from a source ID.
- An importer of format version 1 must refuse archives whose `version` it
  does not support and archives whose `counts` disagree with their contents.
- Media bytes whose digest does not match the manifest are reported per item
  as missing/corrupt; they never abort unrelated items and never enter the
  library.
- Import is idempotent per archive plus author mapping: completed items are
  recorded in a site option and reported as already imported on a re-run.
  Independently of that record, an item identical in type, slug, title and
  normalized content to an existing one is skipped as an exact duplicate, and
  a slug that collides with different content is suffixed `-imported`, then
  `-imported-2`, `-imported-3`, and so on.
- Authors map to existing accounts only by explicit operator mapping or by
  email address. Any other author becomes a new ordinary account with an
  unusable password; source role data is not carried into the target at all.
