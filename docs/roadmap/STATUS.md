# Programme status

Read this first. Then read [`README.md`](README.md) for the phase you are in.

**Exact machine-evidence candidate** [`67cf6c02`](https://github.com/kumwe/app/commit/67cf6c02360f8af4220f8bde7c24297854d45dad)

**Reproducible-baseline measured source** [`a4ded133`](https://github.com/kumwe/app/commit/a4ded13341d41dfbb2b7f69ff072b077510d2338) — candidate `67cf6c02` changes only [`docs/quality/baseline.json`](../quality/baseline.json) from that source

This evidence record names the immutable workflow subject above; the documentation-only commit carrying the
record is not thereby a new machine-evidence candidate.

> **Live open work is indexed here and in [`findings.json`](findings.json). Finished work is in
> [`CHANGELOG.md`](../../CHANGELOG.md); durable package definitions remain in [`README.md`](README.md).**
> Planned work leaves this page's open-work table or the findings ledger and enters the changelog in the
> same pull request that completes it. Unplanned work goes directly to the changelog. See
> [How this document moves](README.md#how-this-document-moves). An identifier in either live index is
> outstanding: `findings.json` admits no `closed` state, the open-work table admits no completion marker,
> and `composer roadmap:check` fails if either appears.

---

## Where we are

| | |
|---|---|
| **Current phase** | Gate A passed. Runtime implementation and Gate B preparation proceed. |
| **In flight** | All thirteen executable Gate A criteria are verified at exact candidate `67cf6c02` by terminal-green CI, Nightly, Security and Development Compose runs. Its reproducible baseline names measured source `a4ded133`; criterion 12's released-artifact evidence remains `2adb2ebe` and `v2.0.0-alpha.4`. Remaining open work continues without reopening Gate A. |
| **Next** | Land the contextual Studio page builder train: Producer publishes `0.3.0` (the Deployment layer and contextual-document validation the App now composes) through its release workflow with a verified attestation; the App branch then adds the Phase 2 adoption records (`KUMWE-MIG`/`KUMWE-CS`/train/attestation) so `composer kumwe:core-growth-check` and the capability index pass, records the Core Growth Record for the new application-layer authoring services, and runs the packaged `STUDIO-PROD-015` browser journey. Goals `S-G2`–`S-G8` are implemented in that branch: contextual Content launch, PHP-authoritative item and type saves, reusable-type and blank creation, workspace continuity, contributions and CDN delivery. |
| **Open decisions** | `V2-QA-014` needs a real-Safari appearance-switch result before the stale WebKit background can be classified as a product defect or a Playwright-only emulation defect. It belongs to Gate B's accountable human-interface acceptance and does not block Gate A. The offline-numbering question was decided — allocation at synchronisation time, [ADR 0008](decisions/0008-numbering-under-disconnection.md) — and implemented, which met Gate A criterion 11. |
| **Gate A** | Passed on 2026-08-22. All 13 executable criteria are met; acceptance is recorded in [ADR 0010](decisions/0010-gate-a-assessment.md). |
| **Gate B** | Not assessed. Gate A is passed; the remaining Gate B runtime and qualification work is open. |

## Phase board

| Phase | Gate | State | Blocked on |
|---|---|---|---|
| 0 — Truth, contracts and decisions | A | In progress — `P0-C` complete; `P0-A`, `P0-B`, `P0-D` and `P0-E` open | — |
| 1 — Correctness, security, data entry | A | Delivered — every package complete, including resident extension withdrawal and stale-generation fencing | — |
| 2 — Truthful gates | A | In progress — `P2-A`, `P2-F`, `P2-G` and `P2-I` complete; the Gate A slices of `P2-D` and `P2-E` are delivered; broader `P2-B`, `P2-C`, `P2-D`, `P2-E` and `P2-H` work remains | Phase 0 decisions 1, 7, 8 |
| 3 — Seams and the ownership model | A | Delivered — transaction proof, delivery boundaries, the two aggregate seams, business-group ownership, and the `P3-D` domain-and-application reconciliation recorded in ADR 0012 | Phases 1 and 2 |
| 4 — Atomic aggregate documents | A | Delivered — `P4-A` … `P4-D` complete: the command, the bulk persistence mechanics, the numbering proof set with ADR 0011 and the bounded invariant | Phase 3; phase 0 decision 2 |
| E — Enterprise document primitives | A | Delivered — every package and follow-up finding complete | — |
| L — Language, locale and multilingual content | A, with a B tail | Gate A half delivered — `PL-A` … `PL-F` complete; only the `PL-G` Gate B translation tail remains open | `PL-G` needs phase 2's broader `P2-E` matrix; otherwise parallel to 3, 4 and E |
| **Gate A** | | **Passed — 13/13 executable criteria met** | — |
| 5 — Enterprise scale | B | Not started | — (`P2-I` delivered; the harness reproduces a stable breakpoint report nightly) |
| 6 — Continuity and introspection | B | Not started | Phase 2 gates (may run parallel to 3–5) |
| 7 — Qualification | B | Not started | Phases 5 and 6, and phase L's `PL-G` |
| S — Studio contextual Content authoring | A, with a B integration | Implemented, qualification pending — Content New/Edit mounts the pinned Studio contextual shell: the editor opens an opaque exact-target authoring context and a hybrid host session per mount, Producer 0.3.0 proves and emits the per-mount `studio-deployment` document, the browser loads the pinned module from the configured npm CDN (or a mirror keeping the package layout) with the manifest integrity under a `script-src` widened by that one exact origin, and a same-origin start module mounts it beside the structured form with a swap control. The seven authoring operations run through the PHP authoring port behind Producer's wire: target resolution, the reusable-type catalogue, blank/from-type/existing starts, deterministic save plans, `save-item` (persisting the entry under the identity the session promised at launch), `save-as-new-type` and `save-new-type-version` (adopting the entry to the successor). Published pages defer the pinned enhancement runtime only when a rendered block needs it. No Studio package bytes are vendored: the eight packages resolve from the registry at exact versions and `PIN.json` binds their integrity to Producer's provenance. Integration proves the create, save-as-new-type and save-new-type-version journeys through the real container and database | Producer `0.3.0` is pinned at its verified tagged release and the Version 2 adoption records (`KUMWE-MIG-2026-032`, its change set, train and release attestation) are committed; `KUMWE-CGR-2026-001` and `KUMWE-CGR-2026-002` await reviewer approval before the core-growth baseline is re-recorded; `composer.json` pins Producer as the exact alias `0.3.0 as 0.2.99` so `kumwe/extension-sdk` 0.2.4 keeps resolving until App adopts the extension-sdk release train that selects Producer 0.3.0; the packaged `STUDIO-PROD-015` browser journey evidence is the remaining Gate B item (`V2-STU-007`). |
| **Gate B** | | **Not assessed** | **Phases 7 and S** |
| M — Maintainability | — | Not started | Phase 3 seams settled. Blocks nothing. |
| N — Native client platform contracts | — | Version 3 seed — not started | Nothing in Version 2; blocks nothing. Decision D17, [ADR 0009](decisions/0009-native-client-platform-and-the-authentication-link.md). |

## Open work packages by phase

This table holds only what is outstanding, so every package listed here is open. A package leaves this
table when it completes, in the same change that writes it into the changelog; its normative definition
remains in README.

| Phase | Packages | Findings |
|---|---|---|
| 0 | `P0-A`, `P0-B`, `P0-D`, `P0-E` | `V2-DOC-002`, `V2-ERP-007` |
| 2 | `P2-B`, `P2-C`, `P2-D`, `P2-E`, `P2-H` | `V2-DEMO-001`, `V2-REL-001`, `V2-REL-002`, `GM-SUP-09` |
| L | `PL-G` | `V2-LNG-010` |
| 5 | `P5-A` … `P5-I` | `V2-SCL-001`, `V2-SCL-002`, `V2-SCL-004` – `V2-SCL-008` |
| 6 | `P6-A` … `P6-D` | `V2-DR-001` – `V2-DR-004`, `V2-OPS-001`, `GM-BAK-04`, `GM-BAK-08` |
| S | `S-E` … `S-G` | `V2-STU-005` – `V2-STU-007` |
| 7 | `P7-A` … `P7-I` | `V2-UX-001`, `V2-UX-002`, `V2-QA-014`, `GM-AUD-08`, `GM-IDN-04` – `GM-IDN-07`, `GM-SUP-05`, `GM-SUP-08`, `GM-OBS-05`, `V2-UX-003` |
| M | Lane M maintainability backlog | `V2-ARC-002`, `V2-QA-010` |
| N | Lane N, no packages assigned yet | `V3-NC-001` – `V3-NC-004` |

## Decisions

Eighteen, all recorded in [`README.md`](README.md) section 2. Ten carry a full decision record; ADR 0020 is the
product-owner correction to D16 rather than a nineteenth decision.

| | Decision | Record |
|---|---|---|
| D1 | Scale target is 5,000,000 documents per day | [`capacity-contract.json`](capacity-contract.json) |
| D2 | Two gates, not one | README section 8 |
| D3 | Point-in-time recovery is platform-supported, operator-configured | README section 2 |
| D4 | The backup artifact is reshaped for deduplication | README section 2 |
| D5 | `BusinessRecordService` decomposition leaves the critical path | README section 2 |
| D6 | Runtime operational introspection is a distinct deliverable | README section 2 |
| D7 | A business-group installation is supported, through ownership scopes | [ADR 0001](decisions/0001-resource-ownership-scope.md) |
| D8 | The atomic aggregate contract is designed before it is built | [ADR 0005](decisions/0005-atomic-aggregate-document-contract.md) |
| D9 | Capabilities are described on their own merits | README section 2 |
| D10 | Multi-currency is core: the type and the conversion contract | [ADR 0004](decisions/0004-money-conversion-contract.md) |
| D11 | The interface is multilingual, with a decided architecture | [ADR 0002](decisions/0002-interface-translation-architecture.md) |
| D12 | Content is multilingual too, including extension-contributed content | [ADR 0002](decisions/0002-interface-translation-architecture.md) |
| D13 | The seven enterprise-primitive boundary questions are decided | README section 2; [ADR 0003](decisions/0003-immutable-correction-by-reversal.md) for D13.2 |
| D14 | Point of sale is deferred but not foreclosed | README section 2 |
| D15 | Role-specific dashboards compose the unified contribution runtime | [ADR 0006](decisions/0006-unified-dashboard-composition.md) |
| D16 | Studio is contextual Content authoring, integrated at Gate B | [ADR 0007](decisions/0007-studio-visual-composition-integration.md); product-surface correction in [ADR 0020](decisions/0020-studio-contextual-content-authoring.md) |
| D17 | The native client platform is a Version 3 programme; its sign-in is the authentication link | [ADR 0009](decisions/0009-native-client-platform-and-the-authentication-link.md) |
| D18 | Gate A is accepted on its thirteen executable criteria | [ADR 0010](decisions/0010-gate-a-assessment.md) |
| — | The remaining `P0-E` decisions | Not yet written |

## Ledger snapshot

**43 open findings** in [`findings.json`](findings.json). The ledger holds open work only.

| State | Count |
|---|---|
| `accepted_for_implementation` | 6 |
| `reproduced` | 7 |
| `open` | 20 |
| `conditional` | 6 |
| `decision_required` | 1 |
| `in_progress` | 3 |
| `verified` | 0 |
| `external` | 0 |
| `closed` | **not an allowed state** — see [`CHANGELOG.md`](../../CHANGELOG.md) |

| Phase | Findings |
|---|---|
| 0 | 2 |
| 1 | 0 |
| 2 | 4 |
| 3 | 0 |
| 4 | 0 |
| L | 1 |
| 5 | 7 |
| 6 | 7 |
| 7 | 12 |
| S | 3 |
| M | 2 |
| N | 4 |
| evidence (`GM-AUD-02`, conditional residual) | 1 |

| Gate | Findings |
|---|---|
| A | 0 |
| B | 20 |
| none | 23 |

By severity: 1 critical, 16 high, 17 medium, 9 low.
By origin: 12 from the independent review, 12 still-open entries from the executed gap matrix, 19 discovered
while verifying this roadmap, during the qualification programme, or from decisions D7, D10 through D14,
D16 through D18.

The 56 findings that were closed when this roadmap was consolidated have left the ledger. Their substance —
the tamper-evident audit work, the record-secret key ring and rotation, the credential lifecycle, the
supply-chain controls, the contention proofs, the failure drills, the observability contract, the restore
drill and the four production-only defects — is in [`CHANGELOG.md`](../../CHANGELOG.md) with the commits
that closed it. Further findings have left since, including the machine surface's credential transport and risk
taxonomy,
the MySQL/MariaDB schema-global foreign-key names, the extension trust posture, the root locale-addressing
defect, the unreachable catalogue-refusal seam and the Studio contract pin with its corpus replay (`V2-STU-002`,
package `S-B`). Their completed substance is recorded in the changelog.
PostgreSQL's separate schema-global non-primary-index namespace is closed: migration `20260823010000` renames every literal non-primary index to the digest derivation, proven by installing two complete prefixed core plans into one PostgreSQL schema.

## Gate A criteria

| # | Criterion | Met | Findings |
|---|---|---|---|
| 1 | Extension contract frozen with passing compatibility fixtures | Yes | — (recorded in [`CHANGELOG.md`](../../CHANGELOG.md)) |
| 2 | Atomic aggregate command exists and matches the recorded shape | Yes | Recorded in [`CHANGELOG.md`](../../CHANGELOG.md); [ADR 0005](decisions/0005-atomic-aggregate-document-contract.md) |
| 3 | Data-entry integrity holds on all three browser surfaces | Yes | — (recorded in [`CHANGELOG.md`](../../CHANGELOG.md)) |
| 4 | Correctness and security contradictions fixed | Yes | — (recorded in [`CHANGELOG.md`](../../CHANGELOG.md)) |
| 5 | Quality gates are truthful | Yes — one manifest defines local, CI, nightly and release execution; semantic dependency checking, coverage and dependency ratchets, retained-contract parity and the deployed-artifact lane all fail closed. Exact candidate [`67cf6c02`](https://github.com/kumwe/app/commit/67cf6c02360f8af4220f8bde7c24297854d45dad), whose reproducible baseline records measured source [`a4ded133`](https://github.com/kumwe/app/commit/a4ded13341d41dfbb2b7f69ff072b077510d2338), passed [CI run 32582207163](https://github.com/kumwe/app/actions/runs/32582207163) with 3,144-test ordinary suites plus 381-test repeat and reverse-order passes on MariaDB, MySQL and PostgreSQL, zero recorded idempotency failures, 66.90% canonical line coverage with every ratchet holding, bounded fixture withdrawal, 160 three-engine Chromium journeys first attempt and deployment acceptance; [Nightly run 32582207042](https://github.com/kumwe/app/actions/runs/32582207042) passed 142 Firefox/WebKit desktop/mobile journeys first attempt, including all 20 critical journeys plus keyboard/focus, touch, forced colours, 200% text zoom and reflow. [Security run 32582206983](https://github.com/kumwe/app/actions/runs/32582206983) and [Development Compose run 32582206967](https://github.com/kumwe/app/actions/runs/32582206967) passed on the same commit | — |
| 6 | Aggregate seams are clean | Yes — verified at [`67cf6c02`](https://github.com/kumwe/app/commit/67cf6c02360f8af4220f8bde7c24297854d45dad) by [CI run 32582207163](https://github.com/kumwe/app/actions/runs/32582207163). The transaction abstraction is inward with its three-engine proof, the automation adapters sit behind ports, and `P3-C`'s three leaks are closed with boundary tests enforcing each. Relationship/owned-line policy is centralized in `BusinessRecordRelationshipCoordinator`; revision/audit/event publication is centralized in `BusinessRecordMutationPublication`; the facade retains the one transaction and no duplicate policy copy. Recorded exemptions fell 115 → 99 | — |
| 7 | Business-group ownership model in place | Yes — the three-engine proof landed with the ERP-primitives wave, demand by demand against the four-business installation, and it caught and fixed a real PostgreSQL narrowing crash | — |
| 8 | Enterprise document primitives exist and are enforced | Yes — immutable correction by linked reversal, the posting-period lock, the proven counter identity with its fiscal-period reset, the aggregate invariant and the unit-conversion contract are delivered. Definition/catalogue coordinates are immutable so a non-site sequence identity cannot move, and a hard-delete set-null sweep evaluates every source record's posting period and atomically rolls back the entire delete when any source is closed | — |
| 9 | Multi-currency contract holds, with conversion provenance everywhere | Yes — contract, port, pipeline, reports, exports and the rendering half all delivered and recorded in [`CHANGELOG.md`](../../CHANGELOG.md) | — |
| 10 | Language contract and machinery in place, `en-GB` extracted | Yes — every template, all 44 currently registered console commands and the user-facing error paths resolve from a 2,102-message catalogue; the hardcoded-string gate covers all three surfaces with reasoned exemptions; the direction gate scans every Vite-consumed stylesheet and enforces logical properties; corrected right-to-left baselines are committed; and extension-contributed items bind to declared translation sets. `V2-LNG-010` is the non-gating Gate B translation tail | — |
| 11 | Point of sale not foreclosed | Yes — the replay window, the client-asserted instant, late arrival, the deferrable-validation split and now the synchronisation-time numbering decision ([ADR 0008](decisions/0008-numbering-under-disconnection.md)) with its client-reference uniqueness are delivered and recorded in [`CHANGELOG.md`](../../CHANGELOG.md) | — |
| 12 | Nothing regressed on three engines | Yes — exact machine-candidate regression proof is [`67cf6c02`](https://github.com/kumwe/app/commit/67cf6c02360f8af4220f8bde7c24297854d45dad): [CI run 32582207163](https://github.com/kumwe/app/actions/runs/32582207163), [Nightly run 32582207042](https://github.com/kumwe/app/actions/runs/32582207042), [Security run 32582206983](https://github.com/kumwe/app/actions/runs/32582206983) and [Development Compose run 32582206967](https://github.com/kumwe/app/actions/runs/32582206967). `regression_matrix` in [`docs/quality/contract.json`](../quality/contract.json) names the three engines, four suites and commands, and `composer quality:contract` fails when the merge workflow stops running the complete suite on any engine. Released-artifact proof remains commit [`2adb2ebe`](https://github.com/kumwe/app/commit/2adb2ebe0cfa95a1aa2953db944479aaa65c30a7), [merge run 32469278190](https://github.com/kumwe/app/actions/runs/32469278190), green Security run 32469277904 and Development Compose run 32469277903, continuous-release run 32472051532 that cut [`v2.0.0-alpha.4`](https://github.com/kumwe/app/releases/tag/v2.0.0-alpha.4), and release run 32472065990 that built, signed/attested and published its checksums, SBOMs and signed checksum bundle | — |
| 13 | Composition contribution contract frozen with a passing compatibility fixture | Yes — nine classified public types in one additive generation, validated at admission and install, with a signed fixture proving the full lifecycle | — |

## Gate B criteria that moved

Gate B's ten original criteria are unchanged and are listed in [`README.md`](README.md) section 8. Two
were added:

| # | Criterion | Met | Findings |
|---|---|---|---|
| 11 | All nine languages ship and each is qualified in its own right | No | `V2-LNG-010` |
| 12 | Studio contextual Content authoring ships and passes `STUDIO-PROD-015`, through PHP and with zero production Node.js/npm | Implemented, not yet qualified — the contextual shell mounts on Content New/Edit through Producer 0.3.0 and the seven PHP authoring operations are proven end to end in integration; the packaged browser journey and the Producer 0.3.0 release adoption records remain | `V2-STU-005` – `V2-STU-007` |

## Baseline health at `7a83c295`

This is a historical snapshot, kept because it is the last full-programme measurement recorded here, and
it no longer describes the head: the ledger above holds 44 findings, recorded dependency
exemptions have fallen from 115 to 99, and the message catalogue has grown from 117 to 2,102. Read it as
the record of that revision and nothing else. Current programme figures are in the ledger above;
exact machine-candidate workflow evidence is `67cf6c02` / run 32582207163 above, while run 32469278190 remains
historical released-candidate evidence.

**Verified at `7a83c295bce6c23f250384ba787dd5e4595fff0e`.** CI run `31902616995`, security run
`31902616730` and Development Compose run `31902616751` all completed successfully.

- The dependency gate reported 115 recorded exemptions and no new violation. The quality contract verified 26
  checks, 16 local checks and three engines; the extension contract verified four manifest generations, two SPI
  generations, 101 public types and two withdrawn types. The interface programme reported 43 surfaces, 86
  templates, 24 navigation entries, 19 generated instances, 16 actors, 28 tasks, 13 journeys, 60 work items,
  eight findings and three verification reports. The roadmap held exactly 44 open findings. Coverage attribution
  reported nine reasoned rules and 43 tests still owing attribution.
- OpenAPI was current. One compiled catalogue contained 117 messages; 76 templates were checked, 28 enforced and
  48 remained pending, with all 117 identifiers resolving. Eight stylesheets and 96 direction-sensitive
  declarations passed. PHPStan reported no error. Coding-standard normalization inspected 1,300 files and changed
  none. Documentation verification inspected 1,263 files plus 37 immutable migrations: classes were
  1,263/1,263, constants 351/351, enum cases 466/466, methods 6,747/6,747 and properties 371/371, with zero
  violation.
- The unit suite passed 1,978 tests and 26,471 assertions with 23 notices; the architecture suite passed 193 tests
  and 23,228 assertions. The complete relational suite passed on MariaDB (2,514 tests, 54,763 assertions, 23
  notices, two skipped), MySQL (2,514, 54,755, 23, four skipped) and PostgreSQL (2,514, 54,597, 23, 28 skipped).
  Each reused-database run executed 323 integration tests. PostgreSQL reported 4,466 assertions, five expected
  errors, one expected failure and 28 skips; MariaDB 4,632, five, one and two; MySQL 4,624, five, one and four.
  Each verifier matched exactly the six recorded non-idempotent tests and found nothing new. Schema verification,
  signed backup, clean restore and the 16-refusal tamper drill passed on every engine.
- The canonical MariaDB run covered 115 of 1,011 classes (11.37%), 2,110 of 6,607 methods (31.93%) and 47,936 of
  86,459 executable lines (55.44%). The measured global baseline held exactly, and 855 of 950 changed executable
  lines were covered (90.00%) against the 90% floor. Branch coverage remains declared but unenforced because
  `pcov` does not report it.
- MariaDB, MySQL and PostgreSQL each passed 146 of 146 browser tests on the first attempt, with no retry-only pass
  and no failure. The frontend installed 38 packages, audited 39 with no vulnerability, validated 36 sound and 34
  adversarial schemas, and passed type-check and build. The six-case deployed-artifact lane passed. Reproducible
  Composer/ZIP installation and complete production deployment acceptance passed in jobs `95057813111`,
  `95057813132`, `95057813149` and `95057813188` across PostgreSQL, MariaDB and MySQL. The documented Development
  Compose install, migration, topology, readiness, asset and teardown contract passed on its custom port.
- Composer audit reported no advisory. Gitleaks scanned 455 commits and 25.86 MB with no secret. Trivy reported no
  source, lock-file, image or Dockerfile high/critical finding across the Alpine 3.24 production image, its 49 OS
  packages, Composer dependencies and Node dependencies. The source SBOM and security evidence were produced.

---

## How to update this file

It is derivable from [`findings.json`](findings.json) plus the phase board. When a phase or gate moves,
change the tables at the top, the affected phase row, the open-package table, and the ledger snapshot
counts. Do not add narrative here — narrative belongs in [`README.md`](README.md).

When a work package finishes, delete its findings from `findings.json`, remove its row from the open-package
table, write what changed into [`CHANGELOG.md`](../../CHANGELOG.md), and lower the counts here in the same
change. Work that was never planned skips the first two steps and goes straight to the changelog.
`composer roadmap:check` fails if a finished finding is left behind as `closed`.
