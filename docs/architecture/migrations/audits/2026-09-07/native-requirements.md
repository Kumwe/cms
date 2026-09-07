# Native and Computation document review

Read in full: all 11 Markdown files under `deliverables-v2/repositories/engine`, `kumwe-engine`, and `computation` (1,263 lines). This is a document-only review, not evidence of live repository compliance.

## Required ownership

| Repository | Permitted responsibility | Explicit boundaries |
| --- | --- | --- |
| `kumwe/engine` | Standalone C++20 native implementation: exact decimals, compiled definitions/formula VM, whole-document batch execution, report execution, canonical streaming/fingerprinting; shared immutable plans/cache/capabilities | No PHP/Zend/Composer/DI/App/database/network/authorization/transaction/rendering ownership. One stable C ABI `kumwe_engine_v1_*`, C++ namespace `kumwe::engine`. |
| `kumwe/kumwe-engine` | Thin Zend binding and PIE distribution of one exact verified Engine source release | Only `Kumwe\Engine\*` native classes; module `kumwe_engine`; platform `ext-kumwe_engine`; no algorithms, semantic duplicates, fallback, FFI, subprocess path, runtime-autoloadable stubs or userland-interface implementation during MINIT. |
| `kumwe/computation` | Portable program/plan/batch/finding/capability/limits/error/corpus contracts; later a semantics-free userland adapter and explicit Laminas provider/factory | Namespace `Kumwe\Computation\*`; only Conversion and Canonical JSON semantic dependencies where required; no Business Definition/Policy/Query/Reporting/App reverse dependency. No AST/domain algorithm ownership. |

`kumwe/conversion` retains exact decimal/money/quantity/rates/units/rounding/provenance semantics. Business Definition retains formula/condition/invariant AST and meaning. Canonical JSON retains normative canonicalization specification, public semantic API and corpora; Engine executes its released profile. Engine must not substitute a convenient new JSON algorithm or hash contract.

## Exact phase order and prerequisites

1. Computation Phase 1A creates its portable contract/corpus baseline, with no extension requirement, native binding, or native ConfigProvider. Joint ownership manifest covers every PHP FQCN once. Upstream Conversion/Canonical JSON inputs require exact verified releases (or permitted genuine pre-v2 verified-legacy record).
2. Human merge, automated immutable publication and separate external release attestation are distinct from the implementation PR. `package-released` is not `release-verified`.
3. Engine drafts can start early, but stable release-readiness requires immutable, verified baseline releases from Computation and each semantic/corpus owner actually implemented: Conversion, Canonical JSON, Definition, Reporting and relevant Record contracts.
4. Engine candidate PR contains a committed handoff with the real PR URL and deterministic candidate build recipe, never its own final commit/archive digest. Dedicated Native Agent 2 validates the exact candidate from a separate extension candidate branch/worktree. External `ENGINE-CANDIDATE-ATTESTATION.yaml` identifies both tested commits/trees and archive digest; do not commit it into either tested tree. Any tested-input/head change invalidates it.
5. Only passing current-head candidate evidence allows Engine review-ready. Human merge/publication and separate release verification follow. First App-eligible Engine release must be **1.0.0, C ABI 1**, with all five modules and full gates. 0.x releases cannot satisfy App production/roadmap completion.
6. Engine Phase 2 and extension Phase 1 are the **same combined task**, with duplicate entry-point prompts. Run one agent only. Extension embeds verified unmodified Engine source in its release archive; consumer compilation must be network-disabled and self-contained. Engine source vendoring here is an explicit ownership-preserving exception.
7. Verified extension publication permits two downstream branches: Computation Phase 1B successor adapter/provider work, and App extension Phase 2 provisioning/readiness. Each remains separately reviewed and released/integrated.
8. App extension Phase 2 only provisions the exact PIE extension before Composer and verifies module/version/ABI/capability/corpus/build readiness. It retains old business execution and associated unit tests. It must be human-merged and green first.
9. Computation Phase 2 alone performs App business cutover to the verified successor adapter and removes superseded implementations and package-owned class tests. It preserves App authority/orchestration/integration tests. No upstream library edits on this App branch.

## Consequences for choosing the next extraction batch

- These native tasks do not license immediate native App cutover. The useful unblocked preparatory work is semantic-contract extraction/corpora and, once its exact selected inputs and reviewed ABI draft are available, Computation Phase 1A.
- A Canonical JSON extraction is an upstream prerequisite when Engine implements canonical streaming/fingerprinting, and may also supply portable serialization/identity semantics to Computation. Its authoritative current PHP behavior, positive/boundary/hostile vectors, null/missing/list/map distinctions, ordering, bytes, limits and digest profile must be preserved and released first.
- Canonical JSON's semantic ownership and Engine's eventual executor ownership must be kept distinct. These documents prohibit a second production fallback when native ownership is adopted, but explicitly permit test-only frozen PHP oracle/corpus generation and require existing App execution to remain until the ordered cutover. The canonical-json-specific brief must decide its own extraction-phase implementation details; native language here does not authorize deleting it prematurely.
- A native candidate cannot be made release-ready merely by creating a wrapper/scaffold or claiming a future upstream version. Draft development is permitted; immutable attested dependencies block publication/adoption rather than all useful preparatory work.

## Required engineering evidence

- Engine: installed CMake package/public headers and C/C++ smoke consumer; coarse versioned ABI, opaque handles, fixed-width fields and struct sizes, one explicit output-buffer ownership convention, no exceptions across ABI, frozen statuses/findings/symbols; deterministic inputs independent of clock/locale/environment; bounded plans/cache/cancellation; unit/property/golden/differential/corpus/fault-seeding/fuzz/sanitizer/leak/lifecycle tests; benchmark end-to-end boundary cost and burst load, not only a 58 records/s daily-average target.
- Engine release: exact semantic version/corpus/API manifests, reproducible source archive, no production oracle/App source/cache/secrets/network fetches, compiler/platform evidence, license inventory, SBOM/checksums/provenance/security policy, candidate extension attestation and independent reproducibility.
- Extension: reviewed stubs/arginfo/reflection/API parity; strict types and bounded marshalling, deterministic exception/status/finding mapping, all allocation and Zend lifecycle cleanup, truthful PHP 8.5/NTS/ZTS/platform support, source PIE clean install and platform checks, lifecycle/hostile-input/refusal/leak/sanitizer/PHPT/cross-layer corpus evidence; exact Engine provenance and machine-readable full compatibility handshake. Revalidate stable PIE maintainer/usage contract at implementation time rather than blindly copying pinned guide 1.4.10.
- Computation: DTO/value/serialization/capability/plan/cache/finding invariants, hostile bounds, interface shape, disjoint FQCN negative checks, dependency architecture, API/capability/service manifests and clean consumer. Phase 1B adds adapter/provider/lifetime/readiness/extension-compatibility tests only after baseline + Engine + extension verified releases.
- App retains DI/provisioning/readiness, authorization, trusted generation/reference resolution, transactions/audit/persistence/database, lifecycle/delivery/recovery/deployment/capacity and acceptance evidence. No `CoversClass` on extracted package/native classes.

## Apparent conflicts resolved by explicit v2 controls

- Broad original phrases such as “required extension” and “no PHP implementation” do not override Computation Phase 1A's expressly extension-free baseline or the expressly retained App runtime before cutover.
- Some phase text mentions “construct/alias” or permitted service-map aliases. This means canonical interface-to-adapter DI service binding only, not `class_alias`, old App namespace remapping or duplicate native FQCN ownership (explicitly forbidden throughout).
- Generic completion/release prose mentions App integration and rollback, but extension section 21 expressly prohibits requiring a real App PR/deployment/business removal/production rollback before publishing the enabling extension. Minimal external consumer fixtures satisfy prepublication boundary evidence; App acceptance occurs later.
- Exact Engine release gating does not forbid the explicitly separate candidate cross-build against an unreleased Engine tree. The candidate stage cannot publish or be misreported as stable extension Phase 1.
- No hard unresolved architectural contradiction found within these 11 files. Several broad narrative statements require the more specific numbered v2 phase rules above to avoid circular release gates or premature removals.

Every PR must carry truthful handoff/evidence/changelog records. Functional completion belongs only to `objective-verified`/`gate-accepted`, not package existence, extraction, release, or PHPT success.
