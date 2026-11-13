# v0.1.3 fixes — decisions and reasoning

Recorded 2026-11-13. The production code carries no comments for these
changes; this document is where the reasoning lives.

## Featured image metadata comes from the resolved master

Stored featured references are kept exactly as stored, including legacy
references to derived variant rows. Rendering already resolves such a
reference to its master (`wp_image_master()` inside `wp_image_tag()`),
but `the_featured_image()` read `alt_text` from the stored row, so a
legacy thumbnail reference rendered the master with the variant's
(typically empty) alt text. Decision: resolve the reference first, then
take the metadata from the resolved row. In-content Image blocks are
unaffected: they pass an explicitly authored alt to `wp_image_tag()`,
which is a separate path and keeps precedence.

## The shortened-summary notice is computed at the save boundary

The post mutation funnel caps a submitted summary at 60 words. Rather
than threading a flag out of the funnel, the save handlers compare the
sanitized submission with what the trim produces
(`wp_post_summary_result_query()` in post-publication.php). The
comparison is exact because `sanitize_text_field()` normalizes
whitespace first: any remaining difference is the trim (the 60-word cap
or the spaceless byte bound) having cut text, never formatting noise.
The result rides the existing save redirect next to `saved_as`, and the
editor appends one sentence to the save notice. The design-embedded
editor is excluded: it has its own notice surface and result query.

## A sparse API update must never rewrite a stored excerpt

The editorial API translator is sparse by design (omitted fields stay
untouched) and the funnel clamps only an excerpt that is actually
submitted. Together they imply that a title-only PATCH leaves even a
legacy over-length excerpt byte-identical. That implication was
previously unguarded; tests/api-http.py now pins it with a database-
seeded 100-word excerpt, so a future change to either side fails loudly
instead of silently shortening stored content on unrelated updates.
