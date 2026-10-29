# v0.0.5 — Local Review Checkpoint

Historical snapshot: all four findings below were repaired in [v0.0.6](V0_0_6_REVIEW.md). The original fault-confirmation tool is preserved in commit `886a71f`; its current path forwards to the positive regression suite.

Recorded October 28, 2026. This patch publishes the local review of application code at v0.0.4 (`55ee2bb`). Schema remains 8. The four defects below remain unresolved.

**Verdict:** The supplied suites and GitHub CI are green, but four independent probes reproduce import/export defects. The WXR content-loss and interrupted-resume cases fail explicit G2 release requirements. I would not sign off G2 as complete until they are corrected. Application runtime code was unchanged by the review; v0.0.5 publishes its findings and manual reproduction tool.

## Snapshot

- Repository: `irgordon/wp-next`, branch `main`.
- Local baseline: `d62cf67a2a7fb6c7c153bfa759fdcf8ad27eae6d`.
- Fetched and fast-forwarded to `55ee2bbafa3d2bb09e39d7b0c594f857a1da92b1`.
- New commits: `1100f54` (G2 native portability/WXR, v0.0.3) and `55ee2bb` (H1 discovery/social metadata, v0.0.4).
- Thirty files changed, 2,904 insertions and 26 deletions. Schema remains 8. Composer adds ext-zip; the local PHP already provides it.
- A second fetch confirmed that the reviewed head was still current. The working tree is clean; installed site data and uploads were not used as test fixtures.
- [GitHub Quality run 40746957706](https://github.com/irgordon/wp-next/actions/runs/40746957706) completed successfully for this exact SHA. Its job includes the new portability/discovery tests and all four browser suites.

## Confirmed Findings

### P1 — WXR inline images disappear even when attachment bytes import

Location: `wp-includes/portability.php:1095–1099`, called from the import loop at line 1290. WXR supplies legacy HTML, so `wp_portability_remap_content()` returns it unchanged instead of rewriting attachment references. The normal sanitizer then removes the source-site image URL.

Probe: a standard `core/image` block referencing WXR attachment 501, with matching `source.png` supplied in the media ZIP. Media successfully mapped to target ID 3. The stored/rendered Page became `<figure class="wp-block-image"></figure>`: the inline image was lost. This is ordinary supported WordPress image content, not an unsupported custom block or missing file.

Required correction: retain the source attachment reference map and rewrite supplied attachment URLs/IDs to mapped local media before normalization. Report references that cannot be resolved. Preserve the existing media validators and remote-fetching policy.

### P1 — Interrupted checkpoints can duplicate completed collision imports

Locations: `wp-includes/portability.php:1349–1365` and `:911–918`. The content mutation commits before the run-state checkpoint. Recovery searches the original source slug at lines 1299–1304, even when the committed item had to use a suffixed slug.

Probe: pre-create `resume-collision`, then import a different item with that source slug. A fixture-only trigger interrupts the option checkpoint after the new item has committed. On retry, two identical imported Pages exist: `resume-collision-imported` and `resume-collision-imported-2`. The second run reports another import rather than recognizing the completed item.

Required correction: make recovery identify committed items by stable import provenance, or establish a genuinely atomic item/checkpoint boundary. An outer transaction around the current service is not a drop-in solution: the database wrapper deliberately rejects nested transactions.

### P2 — Imported social-image IDs can select an unrelated target image

Locations: `wp-includes/portability.php:1346–1347` and `wp-includes/discovery.php:119–128`. Raw imported metadata copies `social_image_id` unchanged. Read-time validation checks only whether that target ID names a ready image; it cannot establish that the image belongs to the imported reference.

Probe: source media ID 1 maps to target ID 3; target ID 1 already holds a different picture. The imported featured image correctly uses 3, but stored social metadata remains 1 and the social resolver returns the unrelated picture. The ADR's expected fallback does not happen when a foreign ID collides with a valid target ID.

Required correction: remap the supported social-image relationship through the media map, or clear an unresolved override so the featured-image fallback can operate.

### P2 — A successful export can be rejected by the same importer

Locations: `wp-includes/portability.php:360–408`, versus the import cap at `:174–176`. Export has no matching count/entry/byte preflight before returning success.

Probe: a valid local fixture with 2,008 Posts/Pages exports successfully. Parsing that exact output immediately fails with `The manifest section "posts" is missing or unreasonably large.` The parser's per-section cap is 2,000.

Required correction: validate exporter output against the format's import limits before reporting success, or add a compatible way to represent larger exports. A generated archive should not look like a usable native round trip when this implementation cannot read it.

## Abstraction Assessment

- **[High] `wp_portability_run()` line 1147:** 234 lines mix orchestration, author/media/term/content decisions, primitive transformations, raw SQL and checkpoint persistence. This violates the supplied High-level contract and the single-level/size rules.
- **[Mid] `wp_portability_preflight()` line 747:** 128 lines mix collision/quota decisions with database reads and filesystem sizes. Domain planning cannot be tested as pure logic from a supplied snapshot.
- **[Mid/Low] `wp_portability_parse_wxr()` line 433:** 259 lines mix archive/file access, XML primitives, content eligibility, status mapping and presentation notes.

The useful existing boundaries are the canonical mutation facade and image/document intake helpers. Keep those, split a short import orchestrator into domain phases, and isolate archive/database/checkpoint adapters. PHPStan and the configured formatting subset do not enforce this structural contract.

## Local Validation

Runtime: PHP 8.4.27, SQLite 3.54.1, Composer 2.10.3, Node 26.12.1, npm 11.20.0, Python 3.15.2, Playwright 1.62.1 with headless Microsoft Edge. Browser web security stayed enabled. `composer check-platform-reqs` passes, including ext-zip 1.22.8.

| Check | Result | Unique supplied assertions |
| --- | --- | ---: |
| `composer check` — syntax, PHPStan level 5, configured PHPCS subset and PHP tests | Pass | 87 |
| JS syntax + builder/widget model suites | Pass; all 16 admin scripts parse | 49 |
| Asset integration/HTTP | Pass | 15 |
| Design/file/preview/Page HTTP | Pass | 149 |
| Documents | Pass | 103 |
| Images | Pass | 47 |
| Accounts and boundary checks | Pass | 66 |
| General HTTP smoke | Pass | 59 |
| Permalinks | Pass | 96 |
| Profiles/taxonomy | Pass | 43 |
| Upgrade smoke | Pass | 4 |
| Migration regressions | Pass | 3 |
| New portability suite | Pass | 66 |
| New discovery suite | Pass | 76 |
| Native Apache routing/denial fixture | Pass with temporary Mac path adapter | 25 |
| General browser | Pass; zero JS page errors | 115 |
| Widgets browser | Pass; zero JS page errors | 62 |
| Private preview browser | Pass; zero JS page errors | 29 |
| Page workspace browser | Pass; zero JS page errors | 59 |
| **Total supplied assertions** | **Pass** | **1,153** |

The four additional review probes are **confirmed product failures**, not extra feature passes. Their script exits successfully because it asserts that the faulty behavior is reproducible; this must not be interpreted as application correctness.

## Environment, Harness And Unresolved Items

- `composer validate --strict --no-check-publish` exits 1 for the pre-existing missing Composer `license` field. The JSON/lock structure is valid; the same metadata omission exists in the baseline. No license interpretation or metadata change was made.
- Nginx is not installed locally, so its fixture was not run on this Mac. The exact-head GitHub run passes its Linux Nginx fixture.
- Native Apache is 2.4.67. The first attempt could not create its compiled-default proxy mutex under `/private/var/run`. An adapter changes only the generated fixture config: the MIME database becomes `/etc/apache2/mime.types`, and `DefaultRuntimeDir` becomes the fixture directory. It does not alter repository/system config or start a system service. With that adapter, all 25 checks pass. Discovery-route 503 controls prove handoff to the configured proxy, not successful PHP-FPM execution. No production PHP-FPM/TLS certification was performed.
- The Page-editor asynchronous-message timing failure documented by the remote author did not recur: the unchanged 59-check suite passes here. That observation does not establish that the intermittent issue is fixed. The older intermittent theme-check failure also did not recur and remains unresolved.
- Initial grouped commands streamed some successful output to the task terminal because only their final command was redirected. Documents, permalink, profile, upgrade and model checks were repeated solely to retain complete per-suite logs. The table counts each suite once. `documents-images.log` contains image checks; `routes-profiles-upgrade.log` contains migration-regression checks.

## Evidence And Reproduction

The portable manual reproduction tool is [tools/review/portability_v004_probes.py](../../tools/review/portability_v004_probes.py). From the repository root, run:

```bash
python3 tools/review/portability_v004_probes.py /absolute/path/to/php
```

It creates disposable installed sites and checks that each of the four faulty behaviors can be reproduced. A successful exit with four `CONFIRMED` lines means the known defects were reproduced, not that the application is correct. It is a review diagnostic for the v0.0.4 application snapshot, not part of the passing CI regression gates. Once fixes land, replace these fault-confirmation checks with normal regression assertions.

Full local execution evidence remains outside the source distribution in `.local-sync/2026-10-28-remote-review-55ee2bb/` in the parent project workspace:

- `review-probes.py` / `review-probes.log`: the original isolated reproductions.
- `assertion-summary.json`: machine-readable per-suite assertion counts.
- `composer-check.log`, `composer-validate.log`, `models-complete.log` and the named HTTP/browser logs.
- `apache-mac-adapter.py`, `apache-native.log` (first failure) and `apache-native-final.log` (passing rerun).
- Browser screenshots in the corresponding `*-browser/` directories.

The v0.0.5 checkpoint changes documentation and publishes the portable diagnostic. Runtime code remains the reviewed v0.0.4 implementation. The two P1 findings should be resolved before treating the G2 release gate as complete.
