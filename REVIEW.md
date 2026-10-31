# Release Evidence Index

Recorded October 31, 2026 against `0dae001`, schema 9. J1 target certification remains open; this index does not certify v1.0.0.

The release path is **J1 target certification → evidence rollup → v1.0.0 release decision**. This file links existing records; executed results and first failures remain in their original records and CI artifacts.

## Governing Records

- [Approved scope](docs/adr/V1_SCOPE.md) and [current feature status](PROPOSALS.md).
- [Release plan](docs/RELEASE_PLAN.md), [deployment requirements and ten-row matrix](docs/V1_DEPLOYMENT_REQUIREMENTS.md), and [installation commands and assumptions](docs/INSTALL.md).
- [Changelog and open findings](CHANGELOG.md#open-findings-and-verification-limits), [quality commands](docs/QUALITY.md), and [recorded metadata/UI rulings](docs/adr/G2_H1_DEVIATIONS.md#rulings-recorded-october-31-2026).

## Evidence By Area

| Area | Existing records |
| --- | --- |
| Publishing baseline and documents | [Initial review](docs/reviews/REVIEW.md), [P1 documents](docs/reviews/P1_DOCUMENTS_REVIEW.md), [integration](docs/reviews/P1_INTEGRATION_REVIEW.md) |
| Image tools | [B1 review](docs/reviews/B1_IMAGE_TOOLS_REVIEW.md) |
| Live editing and private CSS/design changes | [C1/D1 review](docs/reviews/C1_D1_REVIEW.md), [Page navigation correction](docs/reviews/V0_0_8_REVIEW.md), [publication assertion synchronization](docs/reviews/V0_0_9_1_REVIEW.md), [CLI-selection repair](https://github.com/irgordon/wp-next/commit/0dae00111eed4ffbfe177290be36b5596bf03700) |
| Accounts and deletion | [E1/F1 review](docs/reviews/E1_F1_REVIEW.md), [boundary verification](docs/reviews/V0_0_1_VERIFICATION.md), [SMTP TLS gate](docs/QUALITY.md#smtp-tls-verification) |
| Portability and discovery | [G2/H1 deviations](docs/adr/G2_H1_DEVIATIONS.md), [portability corrections](docs/reviews/V0_0_6_REVIEW.md) |
| Authenticated editorial API | [Adopted contract and delivery record](docs/adr/I1_EDITORIAL_API_PACKET.md), [OpenAPI](docs/api/editorial-v1.openapi.json), [API verification](docs/QUALITY.md#v010-editorial-api) |
| Local FPM/HTTPS and target handoff | [Local review, versions and first failures](docs/reviews/V0_0_9_REVIEW.md), [current gate](tests/deployment-https.py), [J1 execution and dependencies](PROPOSALS.md#j1--php-fpm-verification-and-host-certification) |

Dated reviews describe their tested snapshots; their counts, scope and environment limitations remain historical. Later corrective records and exact-commit CI establish the corresponding later evidence.

## Exact-Commit CI

- [`164078f`: five API/restore/deployment corrections](https://github.com/irgordon/wp-next/actions/runs/41146451271).
- [`8cf1b9b`: SMTP TLS verification](https://github.com/irgordon/wp-next/actions/runs/41156346370).
- [`9828745`: closeout-verification batch](https://github.com/irgordon/wp-next/actions/runs/41162384645).
- [`0c21c86`: content-level refusal snapshots](https://github.com/irgordon/wp-next/actions/runs/41164913264).
- [`2e8b3b5`: metadata/UI rulings, green CI with 1,832 checks including 422 real FPM/HTTPS assertions](https://github.com/irgordon/wp-next/actions/runs/41168167076).

## Remaining Release Evidence

All target closeouts C-01–C-10 and P-01–P-07 remain open until their target records are filed under the deployment evidence standard. Local/CI passes do not close these rows. The detailed closeout pack remains in the goal documents.

T1 can start identity/permission and public-certificate checks independently of T2. T3 can start provider mailbox verification; P-01 closes with T1 + T3. P-06 requires T1 + T2 + T4. P-07 also needs Safari and a physical touch device. See [the input map](PROPOSALS.md#j1--php-fpm-verification-and-host-certification).

The October 25 theme-settings observation remains unattributed. The separately reproduced missing-PATH failure is repaired in `0dae001`; the real HTTP regression covers publication without PATH PHP and refusal of an invalid explicit override. This does not establish the historical cause. Preserve the first failed response and available process diagnostics before any rerun.

Optional features, GPG identity repair, historical import auditing and preserved Mac-draft disposition are separate tracks and do not block certification.
