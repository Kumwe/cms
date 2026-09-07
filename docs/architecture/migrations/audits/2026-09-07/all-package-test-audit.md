# Test ownership across existing and future packages

The maintainer's requirement applies to every package: its repository owns portable behavior, boundary
refusals and applicable conformance tests. App retains tests of host composition, authority, adapters,
persistence, lifecycle, recovery and delivery. Extracting a class does not justify deleting its active
App tests before the verified adoption or native execution cutover.

The [repository inventory](repository-inventory.json) covers all 38 accessible Kumwe repositories. Eleven
PHP package repositories already contain implementations, alongside Studio's eight npm packages and the
Dart SDK. Twenty-two planned package default branches contain only README and LICENSE; Access Control,
Business Definition and Engine now have substantive draft extraction PRs. App and website are applications;
client is a documented future-client scope with no Flutter/Dart implementation.

## Existing PHP packages

Every implemented PHP package now has a proposed package-local public-API/test ownership gate and a
reviewed inventory. Public types must map to test IDs discovered by the actual runner, with behavior and
boundary evidence, applicable conformance evidence or a justified inapplicability statement, architecture
gates, and host transfer/retention decisions. The gate refuses stale/unmapped types, missing tests,
fabricated discovery commands and symlinked evidence. It checks ownership and discoverability; it does not
pretend that a mapping proves assertion completeness or branch coverage.

| Package | Public types | Change and package-owned evidence |
| --- | ---: | --- |
| [Conversion #5](https://github.com/kumwe/conversion/pull/5) | 23 | Exact decimal behavior plus 108 language-neutral positive, rounding, boundary and refusal vectors, available for native replay. |
| [Extension SDK #9](https://github.com/kumwe/extension-sdk/pull/9) | 182 | Reject empty test discovery; add portable query, cursor, temporal, value, request and disclosure cases. Interfaces retain explicit signature/consumer conformance ownership. |
| [Producer #6](https://github.com/kumwe/producer/pull/6) | 70 | Direct production-value bounds and terminal-newline regressions; stricter whole-input money/currency/color grammars. Existing render/canonical corpora stay upstream. |
| [Transaction #3](https://github.com/kumwe/transaction/pull/3) | 3 | Existing transaction-port, callback and test-double behavior remains in the package; complete ownership inventory. |
| [Sequence #3](https://github.com/kumwe/sequence/pull/3) | 5 | Existing formatting, scope, fiscal/temporal, bounded allocation and refusal tests remain in the package. |
| [Secret Envelope #3](https://github.com/kumwe/secret-envelope/pull/3) | 22 | Add rare platform/encryption failure tests proving typed, unchained, redacted failure; preserve envelope/key/cipher boundary suites. |
| [Access Context #4](https://github.com/kumwe/access-context/pull/4) | 11 | Existing context/proof/identifier boundaries remain upstream; host authority and concrete security composition remain App. |
| [Localization #3](https://github.com/kumwe/localization/pull/3) | 27 | Add direct write-time ICU validation, ActiveLocale adoption/lifecycle and message override storage-port contracts. |
| [Contribution #3](https://github.com/kumwe/contribution/pull/3) | 6 | Per-type grammar, policy, collision, deterministic registry and detached/reentrant snapshot evidence. Host executable activation remains App. |
| [Canonical JSON #2](https://github.com/kumwe/canonical-json/pull/2) | 3 | Semantic metadata and normative language-neutral corpus; no PHP runtime executor. |
| [Computation #2](https://github.com/kumwe/computation/pull/2) | 20 | Bounded transport/plan/finding contracts, malformed source/semantic coordinates and unsupported metadata; no native adapter or runtime algorithm. |

The first three legacy packages also receive actual archive-as-dependency, no-development authoritative
consumer checks. The PRs align all eleven package publication paths with protected main and immutable published
releases; this is separate from passing source/test checks. No PR creates, moves or rewrites a release tag.

## Current extraction batch

- [Access Control #1](https://github.com/kumwe/access-control/pull/1): neutral authorization decisions,
  policies, scopes, registries and ports. Four-state precedence, raw-byte/numeric identifiers, bounded
  iterables, malformed port inputs and owner removal are package test subjects. App keeps concrete
  authorization, trusted activation, built-in category tables, identity/session/persistence and delivery.
- [Business Definition #1](https://github.com/kumwe/business-definition/pull/1): portable definition/AST
  validation, compatibility and registries, with a mandatory host configuration-admission port. It reuses
  Sequence and Localization. Formula execution expectations live in a versioned corpus; frozen PHP
  oracle code is development-only and excluded from the consumer artifact. No PHP executor is exported.
- [Engine #1](https://github.com/kumwe/engine/pull/1): a real initial C++20 exact-decimal kernel and coarse
  bounded C ABI, with C/C++ consumers, shared Conversion corpus replay, lifecycle/property tests,
  sanitizer/fuzz checks, arithmetic fault seeding and reproducible archive/install checks. This is an
  E0/E1 development slice, not full formula/document execution, the five-kernel candidate or ABI freeze.

Access Control and Business Definition use explicitly unverified exact versions for development and keep
publication blocked. Engine also records draft semantic inputs. None is App adoption or a passing release
attestation. The [fresh publication check](publication-check/README.md) explains the current upstream gate.

## Other implemented package families

[Studio](https://github.com/kumwe/studio) already keeps its eight-package behavior, protocol/canonical
testkit, accessibility and browser assertions in its own repository. [Dart SDK](https://github.com/kumwe/dart-sdk)
keeps its analyzer, wire/OpenAPI and transport/model tests upstream. The initial inventory found 146
Studio TS/MJS test files and 40 Dart SDK test files. Those are source counts, not a claimed execution
result. The [legacy and other-family review](legacy-test-ownership-report.md) records exact source baselines,
inspected paths, retained host boundaries and any remaining qualification limits.

## Enforced adoption and future extraction rule

[App #136](https://github.com/kumwe/app/pull/136) makes migration governance reject duplicate tests still
present after claimed removal, missing retained host tests, contradictory/path-traversal/symlink evidence,
and omitted exact file removals required by the released handoff. It updates the extraction guide and PR
template so the same requirement applies to future packages, including native and binding repositories.

Whole-file transfers and mixed-suite splits are different evidence. Record the exact moved methods and
the host assertions retained from a mixed suite. Existing source/test inventories and the first seven
whole-file transfers are in [test-ownership.md](test-ownership.md). App has not yet removed those tests or
changed dependencies. Binding PHPT and cross-layer tests remain with the binding; App conformance tests
prove the composed application. No package-only CI pass closes an ERP roadmap objective.
