# Extraction audit and next batch — 2026-09-07

The first batch completed **Localization and Contribution** and corrected the four started packages.
The maintainer has also merged **Canonical JSON and Computation Phase 1A**. Current development PRs extract
**Access Control and Business Definition** and implement the first **C++20 Engine decimal** slice. All
eleven implemented PHP packages are receiving explicit test ownership gates and any missing boundary tests.
See the [published release review and next boundaries](release-review.md) for the upstream verification
gaps that must be resolved before dependent extraction. App has already merged the governance bootstrap in
[#130](https://github.com/kumwe/app/pull/130) and [#131](https://github.com/kumwe/app/pull/131).
Recreating that bootstrap or installing unpublished package branches would delay the existing plan.

This report is a source and requirements audit, not App adoption or ERP gate acceptance. The App baseline
examined is `960ce8ec00cf724a7cae03e5ba09c4852c9ab54e`. Its lock still consumes only Conversion `0.1.2`,
Extension SDK `0.2.4` and Producer `0.2.0` from Kumwe. The new extraction packages are not installed in App.

## Document coverage

All 104 Markdown documents in the supplied Version 2 ZIP and the entire separately supplied ERP roadmap
were reviewed, together with the twelve current App governance rulings. The ZIP SHA-256 is
`6774856c532647c4d2b5bef0a5fa1c301c835fb04616bd00fcae3a29f69503e7`; the roadmap SHA-256 is
`a202155ef1a65f5ab293d4f8397ebf4ac430db7f1e877c776bbe7851e6fe18d8`, matching the controlling documents.

- [Requirements and all thirty package boundaries](requirements.md)
- [Native ordering and semantic ownership](native-requirements.md)
- [Downstream package dependency review](downstream-requirements.md)
- [Per-file document coverage digests](document-coverage.sha256)
- [Package and App test ownership, including the first adoption batch](test-ownership.md)
- [Published releases, verification results and the following extraction batch](release-review.md)
- [All-package test ownership audit and current implementation PRs](all-package-test-audit.md)
- [Complete repository inventory](repository-inventory.json)
- [Fresh publication checks after the latest merges](publication-check/README.md)

## State observed before corrections

| Repository | Observed state | Audit finding and required correction |
| --- | --- | --- |
| `kumwe/transaction` | `v0.1.0` published; no App adoption | The archive check installs the package as the root project, which does not prove dependency installation. Release record parsing differs between current and tagged changelogs. Correct both checks and rerun package gates. |
| `kumwe/sequence` | `v0.1.0` published; no App adoption | Same consumer/release gaps. Organization key `-` collides with the reserved no-organization scope representation; refuse it and record the observable pre-1.0 API change. |
| `kumwe/secret-envelope` | Source/tests committed; no release | Required tools, manifests, release workflow, public documentation and handoff are absent. Complete the package boundary and test numeric-looking key identifiers and encoded envelope bounds. |
| `kumwe/access-context` | Source/tools/manifests committed; no release | Null coalescing in verification rejects intentionally null manifest fields. Required charter, documentation, handoff and workflow are missing. Repair verification and finish release/consumer evidence. |

These are findings against the repositories as observed. Publication alone does not establish independent
release verification. Corrections are isolated in [Transaction #2](https://github.com/kumwe/transaction/pull/2),
[Sequence #2](https://github.com/kumwe/sequence/pull/2),
[Secret Envelope #2](https://github.com/kumwe/secret-envelope/pull/2) and
[Access Context #2](https://github.com/kumwe/access-context/pull/2). Each PR carries its own final-head checks,
successor handoff and release record. Releases remain maintainer-controlled.

The Transaction production port was sound and did not drift from its extraction baseline; its correction
is release assurance. Sequence records the reserved-key refusal as the observable pre-1.0 change `0.2.0`.
Secret Envelope retains App-owned key-purpose labels and authenticated-data coordinates rather than
mistaking those authority-specific wrappers for duplicate package implementation. Access Context adds
the missing impossible-combination check for a workspace with no organization and refuses control bytes
before scope normalization can silently erase them.

## Next batch and ownership

| Package | Reason for this order | Boundary that must remain in App |
| --- | --- | --- |
| Localization | Independent leaf; unlocks Business Definition and Content. Extract locale/catalogue/translation/formatting/negotiation values and services with narrow provider ports. | Authorized override/settings services, site-default persistence, cache/storage/build adapters, middleware, Twig and delivery. A default-locale provider port replaces the direct settings-service dependency. |
| Contribution | Neutral owner/definition/surface policy/registry contracts unlock several dependent families. The current owner and definition live in Extension SDK and must be audited there. | Trusted active registry, runtime generation, admission, manifest reconciliation, lifecycle and executable registration. Surface policies replace hard-coded Studio/graphical branches; the package cannot depend on the SDK. |

Localization's package boundary contains 27 public types: 23 existing portable types, the new default-locale
port, a ConfigProvider and two explicit factories. Translation contexts are supplied per operation; the
translator is not a globally shared holder of an actor, site or organization. Its Phase 1 implementation
is [Localization #1](https://github.com/kumwe/localization/pull/1).

Contribution's six public types in [Contribution #1](https://github.com/kumwe/contribution/pull/1) separate
owner identity, definition, surface, bounded identifier policy, owned data registry and typed refusal.
The SDK owns the current owner/definition types, so a verified
Contribution release must first be adopted and released by the SDK before App can consume the canonical
types without aliases or incompatible parallel ownership. The new registry must also recheck duplicate
and capacity limits after calling a definition's serialization callback, because a callback can re-enter
the registry before the outer registration completes. The implementation and adversarial regressions
cover both reentrant overwrite and capacity bypass.

Canonical JSON is a semantic/corpus-only package, not a move of the current PHP executor. Record Values
depends on verified Conversion input. Computation first releases its extension-free contract baseline.
These are the following dependency-aware candidates; the native cutover cannot precede the released
semantic barrier, Engine candidate verification, extension release, provisioning and Computation successor.

## Review and completion sequence

1. Complete separate Phase 1 package PRs and final-head package checks, with actual PR-linked handoffs.
2. Maintainer reviews and merges each package PR; release-on-record publishes from the merged branch.
3. A fresh verifier checks immutable source, archive, manifests, supported platform and clean dependency
   installation, and produces the external release attestation. A failed verification stays failed.
4. A separate App Phase 2 task compares each handoff baseline with current source, consumes exact verified
   versions, updates canonical imports and DI, removes old implementations and package-owned unit tests,
   and preserves App composition, database, security, lifecycle and delivery tests.
5. Serialize App merge-ready verification through the integration train. Maintain the capability index,
   paired migration/change-set records, non-roadmap evidence and changelog. Only merged green App evidence
   establishes core integration; the ERP roadmap retains its separate acceptance requirements.

No runtime source, Composer dependency, lockfile or service registration is changed by this audit PR.

## Evidence identity and validation

This audit uses `NRM-2026-008`. The existing Transaction and Sequence handoffs already reserve
`NRM-2026-002` and `NRM-2026-003`; the concurrent Secret Envelope, Access Context, Localization and
Contribution handoffs continue that sequence through `NRM-2026-007`. This record preserves those reserved
identities and does not create App migration/adoption records before the release boundary.

The review uses App's actual `PackageManifests` loader to validate each completed package against its
strict YAML reader, handoff schema, source/public API inventory, capability documents and service-map
rules. Local package checks run on PHP 8.5.10 and include archive installation as a dependency in a fresh
no-dev authoritative Composer project. Repository Actions remain the evidence for the exact published
PR heads, including dependency audits and supported-platform lanes. The App audit has no runtime diff;
its unchanged capability digest is
`87ded886f35f74878ca9eb8db4c36e23d681c4a49891f76dfc3210f385a7ce39` and its Core Growth inventory remains
1,413 production symbols with no duplicate owners or new growth entries.
