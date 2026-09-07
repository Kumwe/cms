# Legacy and non-PHP package test ownership audit

Audit date: 2026-09-07. App source inspected: `960ce8ec00cf724a7cae03e5ba09c4852c9ab54e`. This report distinguishes existing package evidence, newly pushed candidate evidence, and read-only inspection. It does not attest releases or App adoption.

| Repository | Audited main source | Implemented candidate | Package evidence |
| --- | --- | --- | --- |
| kumwe/conversion | `5ccea7f7dc4ebd11bc27b11da8573da42807c196` | [PR #5](https://github.com/kumwe/conversion/pull/5), patch 0.1.3 | 23 public types; 103 local tests, 1,891 assertions; 108 exact-decimal TSV vectors |
| kumwe/extension-sdk | `d0484b8733eaa57d076f567ffa5e997b9564b5fa` | [PR #9](https://github.com/kumwe/extension-sdk/pull/9), patch 0.2.5 | 182 public types; 143 tests, 2,097 assertions; 6 manifest generations, 4 SPI generations, 66 pinned resources |
| kumwe/producer | `e746b9f019482c78a34df04375f00c35ad5961af` | [PR #6](https://github.com/kumwe/producer/pull/6), patch 0.2.1 | 70 public types; 113 local tests, 1,467 assertions; existing Studio pin binds 55 schemas and 301 corpus files |

The three candidates add `tests/ownership.json`, real runner `--list-json` discovery, `tools/verify-test-ownership.php`, and `tools/test-ownership.schema.json`. Every public type maps to discovered local behavior and boundary test IDs. The common gate includes nine negative fixtures. The mapping records pure interface ownership honestly: interfaces have no runtime implementation; signature/consumer conformance belongs to the package, while real adapter behavior remains host-owned. Evidence references do not claim 100% line, branch or input coverage.

The candidates also use the shared strict release-heading parser (12 cases) and release-integrity predicates (31 isolated fixtures). The workflow requires protected main before tag/publication changes, then verifies exact stable published metadata with `immutable: true`. Maintainers must configure branch protection and immutable releases before merging. Existing mutable historical releases do not become independently verified by these patches. Separate fresh release verification remains required.

## Concrete gaps fixed

- Conversion already owned its extracted value/request/provider behavior. It lacked a reusable language-neutral exact-decimal corpus and an explicit layer/host boundary gate. `resources/conformance/decimal-v1.tsv` now owns 108 reviewed parse, compare, multiply and six-mode rounding vectors, including sign, tie, carry, zero, width and malformed-byte refusals. SHA-256: `635db251898707828e24f12b1abb672273552f5f633186a725cc9f50ac08140c`. The corpus is draft candidate evidence until the exact release passes independent verification. Engine can replay it during development without claiming a released semantic coordinate.
- SDK's custom runner previously reported success when the entire case directory was empty or a case had no test methods. It now fails in execution and inventory modes, with isolated subprocess regression fixtures. Portable record-query cursor/identity/projection/depth/relation/operation boundaries, forged executable-node refusal, zoned date-time calendar/offset boundaries, declaration schema/version bounds, per-usage disclosure isolation, safe preview values, closed custom-handler schemas, request scopes and canonical HTTP context extraction now have direct package tests. UTC spelling normalization is preserved. No SDK production code or draft framework dependencies are introduced.
- Producer's public typed money amount/currency and drawing-color parsers accepted a final LF because their regex used `$` without strict end matching. New direct tests reproduced the defect; `/D` now refuses that trailing byte while all existing Studio conformance remains required. Direct chart/table/drawing capacity and malformed-value tests are package-owned.
- Legacy Conversion and Producer tested exported archives as root packages; SDK only tested a no-dev source checkout. Each candidate now installs the built ZIP as a dependency of a fresh unrelated consumer with `--no-dev --classmap-authoritative`, checks that every runtime export resolves inside that installed dependency, and exercises actual behavior. The SDK's two optional author-PHPUnit bridges are explicitly excluded from runtime loading and required to exist as source; PHPUnit must be absent. SDK distribution now excludes development tests/tools/docs while retaining runtime `src`, author CLI and resource fixtures.

Final reviewed heads are Conversion `d0893f1261ab12adc97d01600081263c255c18ee` ([CI](https://github.com/kumwe/conversion/actions/runs/34118618213)), SDK `a8774305d82f14146926bec35d35dee90b6875a4` ([CI](https://github.com/kumwe/extension-sdk/actions/runs/34118763392)) and Producer `7830b582f72aa52a823cc34f29c1141406128457` ([CI](https://github.com/kumwe/producer/actions/runs/34118631145)). All three PRs are ready for review with green final-head package/static/audit/archive/consumer gates. Producer CI verified its exact lock across PHP 8.1–8.5. Local online advisory retrieval was unavailable; passing online CI supplies that evidence.

SDK also owns a reviewed corpus of all 65 public interfaces/enums, checked against the frozen main-source snapshot for signatures, defaults, by-reference/variadic flags, returns, inheritance and enum values. Its clean consumer loads 180 runtime exports and retains two optional PHPUnit bridge source files with PHPUnit absent.

## App ownership retained

No whole current App file is identified for deletion by these three patches: these legacy packages are already adopted and inspected current references exercise host responsibilities. `MoneyRateProviderContributionTest` and `UnitConversionProviderContributionTest` retain runtime contribution/entitlement behavior; `ExactValueCodecTest` and conversion surface architecture tests retain storage and provenance-delivery behavior. `BusinessRecordViewTest` covers App visibility/disclosure projection rather than SDK port implementations. `ExtensionDevelopmentSdkTest` covers App CLI command wiring. Studio host/preview/media/recovery tests retain authenticated authority, database/replay/lifecycle and browser composition.

SDK Capability and Contribution owner tests stay in SDK until successors consume their independently verified canonical packages. Do not delete them because draft Access/Contribution packages exist. New extractions must record exact pure test removals or mixed-file splits in their handoff; host acceptance remains required.

## Other implemented package repositories: read-only evidence

### Studio

Inspected main tree `42b149251a9f17a2ef8f32db0d9dd1ac2fcfec8a`. Eight implemented package directories have local tests: `packages/core`, `media`, `preview`, `protocol`, `renderer-web`, `rich-text`, `studio-lit`, and `testkit`. The repository inventory contains 146 TS/MJS test files, including tooling tests; no test execution was performed in this audit.

`packages/testkit` owns canonical/command/host/host-sequence/media/preview/schema-profile vectors and renderer/rich-text/authoring conformance corpora under its own source tree, with `corpus-manifest.json`. Package tests include boundary, refusal, fuzz, generated-model, canonical, transport-authority and renderer cases. `package.json` makes `npm run check` run formatting, lint, type checks, boundary/contract verification, Vitest and Node tests, then builds. `.github/workflows/ci.yml` adds accessibility and PHP reference-host lanes. App tests must exercise its real Studio implementation and cannot replace these package suites. This is observed source ownership and CI wiring, not fresh suite execution, release verification, or full behavioral coverage certification.

### Dart SDK

Inspected main tree `88f4c82d575d65005fc75e367bff9f4e0f65d056`; 40 package-owned Dart test files cover auth/context, transport, mutation/retry, exact values, business query/definition/value validation, contract validators, session/cache and abusive input. `.github/workflows/ci.yml` runs Dart 3.8.0 and stable: dependencies, analyzer, `dart test`, OpenAPI contract validation, and stable formatting. No tests were run in this audit.

`docs/quality-and-conformance.md` separates package behavior/contract evidence from released-core and actual profile parity. It explicitly leaves adopted-server authorization/lifecycle/mixed-context and some canonical-contract evidence unclaimed. Those are future integration gates, not evidence to duplicate pure Dart behavior in App. A machine-checked per-export ownership map equivalent to the PHP candidate gate was not added or verified here.

### Client

Inspected tree `43615a4e9e71706af4308b33d5fda8caa2943345`. `README.md` explicitly describes a documentation-first planned Flutter client with no Dart/Flutter application, generated client, runnable binary or implemented capability. The tree contains zero source/test files. Classify it as a planned product repository, not an implemented package missing unit tests. Its future concrete Flutter/platform/accessibility acceptance belongs to that client, separately from Dart SDK and App.

