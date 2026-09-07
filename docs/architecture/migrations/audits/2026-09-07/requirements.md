# Extraction document audit — 2026-09-07

This is a requirements and dependency review. Repository implementation findings belong in the separate live code audits; this document does not claim that a package, release, App migration or ERP gate passed.

## Evidence and complete document coverage

- Uploaded ZIP: `Kumwe-Extraction-Acceleration-v2-Complete-Document-Set.zip`, SHA-256 `6774856c532647c4d2b5bef0a5fa1c301c835fb04616bd00fcae3a29f69503e7`.
- ZIP inventory: 104 Markdown files: 12 shared controls plus 92 repository documents covering all 30 targets. Each ordinary repository has its brief, Phase 1 prompt and Phase 2 prompt; Engine and Computation each also have a special gated prompt.
- Uploaded ERP roadmap SHA-256: `a202155ef1a65f5ab293d4f8397ebf4ac430db7f1e877c776bbe7851e6fe18d8`, matching the exact digest in v2 governance. Full roadmap read, including all seven sessions, operating protocol and final 16 checks.
- Current App governance rulings D-GOV-1 through D-GOV-12 read from `docs/architecture/governance/decisions.md` at App `960ce8ec00cf724a7cae03e5ba09c4852c9ab54e`. These govern where the older ZIP wording differs.
- Lead document reviewer read the 12 shared controls and all 45 files for Access Context, Access Control, Approval, Audit, Business Policy, Canonical JSON, Contribution, Conversion Extension, Idempotency, Interface Standard, Localization, Record Values, Secret Envelope, Sequence and Transaction. Identical repeated paragraphs were compared across files and read once; every distinct paragraph was reviewed.
- Native reader read all 11 Engine/Kumwe Engine/Computation files (1,263 lines), summarized in `native-requirements.md`.
- Downstream reader read all 36 Business Definition/Schema, Record Query/Model, Reporting, Content, Navigation, Administrator/Portal/Business Surface, Automation and Integration documents; completion recorded in `downstream-requirements.md`.

## Governing decisions that materially affect execution

1. App is the composition root and final authority. Keep infrastructure adapters, Doctrine and migrations, transaction orchestration, trusted extension generation, authorization enforcement, delivery, operations and recovery in App. Move portable semantics, values, ports, genuine reusable services, their documentation and behavior tests into their sole packages.
2. Recompute import closure, public-signature closure, configuration/reflection references and strongly connected components against the current App. Planning counts are candidate estimates, not permission to extract a directory wholesale. Ambiguous dependencies require an ownership decision rather than silent widening.
3. One package Phase 1 PR changes only that package. It opens a draft PR, commits a schema-valid handoff with the real PR URL, reruns final-head gates and stops for maintainer merge. No agent tags, publishes, enables auto-merge or makes first Packagist submission.
4. Immutable publication and independent verification are distinct. A fresh verifier with no Phase 1 chat history produces the external release attestation. Only verified releases permit dependent publication or App Phase 2. Keep source-identifying hashes external to the artifacts they identify.
5. Phase 2 consumes an exact version while pre-1.0, regenerates the lock through Composer, adopts public APIs, removes old implementations/aliases/remaps/package-unit tests, and preserves App integration/security/database/delivery/recovery tests. Compare the extracted paths and tests against their baseline first; do not erase newer portable changes.
6. App governance bootstrap already exists; inspect and use it rather than recreate it. Package branches target `main`, App PRs target `master`; App changelog cites `(#PR)` because App uses rebase merges.
7. D-GOV-12 requires extraction/governance work to use `NRM-YYYY-NNN`, changelog and PR evidence. Do not create a roadmap finding or STATUS row for extraction. Roadmap relations belong only in change-set evidence and cannot imply acceptance.
8. Canonical change-set states are `enabling-refactor`, `package-implemented`, `package-released`, `release-verified`, `app-pr-ready`, `core-integrated`, `objective-verified`, `gate-accepted`. Handoff `draft_pr_open` is separate. App ledger MIG and central CS records share a sequence; allocate from current directory contents, never invent/reuse a number.
9. Phase 2 writes a serialized integration train even for one PR. Preserve additive migration evidence, provider order and dependent objectives; never hand-edit Composer lock or resolve whole conflicts with ours/theirs.
10. Existing approved legacy-unmanifested App entries are Conversion 0.1.2, Extension SDK 0.2.4 and Producer 0.2.0. This transitional inventory is not a verified extraction release. A genuine legacy upstream without a handoff needs the separate verification record and named maintainer approval; never apply that exception to the package being migrated.

## Immediate next extraction batch

**Recommended concrete batch: Localization and Contribution, alongside repairs to the four started packages.** Localization has no Kumwe runtime dependency. Contribution can also be an independent leaf if the refreshed neutral closure proves no Canonical JSON dependency. The early batch minimizes release dependencies and unlocks Business Definition, conversion contributions and all interface/surface packages.

| Candidate | Portable extraction | Exclusions and dependency test |
| --- | --- | --- |
| `kumwe/localization` / `Kumwe\Localization` | Eight domain types plus portable catalogue, translator, formatter, negotiation, record/value types and narrow provider/storage ports; planned approximately 21 types, actual closure decides | Leave site-default/override authorization, settings mutation, Doctrine, cache policy, PSR middleware, Twig and delivery in App. Preserve locale normalization, fallback/collision order, placeholders, formatting errors, Unicode and bounded inputs. Use provider/factories only for real injected services; truthfully declare intl/Unicode requirements. |
| `kumwe/contribution` / `Kumwe\Contribution` | Neutral definition/identifier/owner/surface policy/owned registry/refusal vocabulary; approximately 5–6 types | No App, SDK, Studio or delivery framework dependency. Remove surface-specific branching through explicit bounded policies. Leave trusted registry set, admission, lifecycle, manifest reconciliation, generation and route registration in App. Prove owner isolation, collision and deterministic removal/order. Canonical JSON only if signatures/semantics require it. |
| `kumwe/canonical-json` / `Kumwe\CanonicalJson` | Normative semantic specification, accepted values/UTF-8/numeric/map/array/escape/limit/error rules, contracts/DTOs and language-neutral corpus | No production PHP executor and no native implementation/provider in this package. Do not merge differently owned canonicalizers merely by name. Native FQCN ownership map required. Semantic-only Phase 2 retains current App PHP executor/wiring/tests until Computation cutover. Strong parallel preparatory target, but requires careful semantic freeze rather than mechanical class copy. |
| `kumwe/record-values` / `Kumwe\Record\Value` | Narrow portable normalization/temporal values, approximately three types | Existing Conversion owns numeric semantics; exact verified upstream or governed legacy verification required. No copied arithmetic, floats in exact domains, DBAL, field-definition policy or reference lookup. Dependency evidence makes this less independent than Localization. |
| `kumwe/computation` / `Kumwe\Computation` Phase 1A | Program/plan/batch/finding/error/capability/limit/corpus contract baseline | No extension requirement or native adapter/provider yet. Requires reviewed ABI draft and exact selected Conversion/Canonical JSON inputs; not an immediate App/native cutover. |

No evidence in the documents authorizes skipping immutable releases to wire unpublished branches directly into App.

## Four started-package discrepancy checklist

These are exact acceptance criteria to check against live code, not asserted defects.

| Package | Canonical scope and dependency ceiling | Important failure cases and exclusions |
| --- | --- | --- |
| Transaction | `Kumwe\Transaction`; stable neutral transaction port only; no runtime Kumwe/DB dependency; no provider | Preserve callback result and original exception behavior. No production transaction implementation. App retains nesting, retries/deadlocks, Doctrine, audit/outbox and real DB rollback/concurrency tests. A test-only in-memory implementation must remain explicitly test-scoped. |
| Sequence | `Kumwe\Sequence`; pattern/reset/scope/allocator port and canonical refusal; no Kumwe dependency/provider unless genuine service | Grammar, padding/prefix limits, reset boundaries, stable format, scope equality, overflow and allocator return contract. No invoice/stock-specific policy; no portable claim of gaplessness unless actual App adapter proves it. Locks/fencing/unique constraints/retries/repair remain App. |
| Secret Envelope | `Kumwe\Secret`, not a mechanically generated SecretEnvelope root; vetted Sodium, explicit ext-sodium requirement | Versioned authenticated metadata, key ID/purpose binding, random nonces, wrong key/purpose/version, every field tamper, truncation/oversize/provider failure, secret-free errors. No general raw-key getter or accidental serialization. Document zeroization limitations. App retains key custody/file/KMS/HSM providers, authorization, rotation jobs, persistence and restore ordering. Configured cipher services need explicit provider/factories. |
| Access Context | `Kumwe\Context`, not AccessContext; immutable principal/capability/grant/execution/site/org/workspace/strength facts; no dependencies/provider/global context | No App user/session/token/HTTP/Doctrine dependency or authentication/authorization. Explicit actor/system facts, absence handling, strength/freshness boundaries, redaction, impossible combinations and site/org separation. Break Identity↔Authorization cycle through neutral principal contract, not copied App User or credential fields. |

For each verify: complete source baseline map, public signatures/members/docs, dependency ceiling, no dual canonical owner, charter/non-goals, runnable example, correct provider or reason for none, generated/verified public-api/capabilities/service-map, package-owned behavior tests, architecture/API drift checks, truthful PHP matrix, supported security/static/coding checks, `composer check`, built-archive no-dev authoritative consumer, changelog release-on-record workflow with reviewed action SHA pins, real PR-linked handoff, and distinct release verification state. A green source checkout or existing tag alone is insufficient.

## Full downstream dependency and ownership map

Ceilings are maximum allowed inputs, not mandatory Composer requirements. Selected pre-1.0 dependencies must be exact verified releases.

| Package | Upstream ceiling / ordering | App authority or excluded ownership |
| --- | --- | --- |
| Access Control | Access Context | Policy decisions/scopes/neutral registries move; final identity/session/trust/authorization and query enforcement stay. |
| Idempotency | Canonical JSON | Key/fingerprint/state/result/ledger ports move; transactional persistence, retention, command dispatch and ambiguous external-effect reconciliation stay. |
| Audit | Context + Canonical JSON | Records/redaction/digest/finding/ports move; transaction/sequence/ledger/anchors/retention/export authorization and operations stay. |
| Business Policy | Context + Canonical JSON | Bounded policy AST/disclosure plans move; SDK depends downward and deletes duplicates; active policy and query predicates stay. |
| Approval | Context + Access + Audit + Transaction | Generic maker-checker state/bindings/services move; credentials/step-up/action execution/DB concurrency stay. MembershipDirectory is Access-owned. |
| Interface Standard | Contribution + Context + Access | Portable interface vocabulary only; no Twig/Lit/rendering/routes/host registry/preferences or competing surface model. |
| Conversion Extension | Conversion + Contribution | Exactly four audited money/unit provider definition/registrar bridges subject to refreshed closure; no native extension code or numeric algorithms. |
| Automation | Context + Access + Canonical JSON; Contribution only if proven | Portable job/queue/schedule/coordinator contracts; no host scheduling/leases/trust persistence authority. |
| Integration | Automation + Context + Contribution + Canonical JSON | Portable event/inbox/outbox/process/transport ports; atomic persistence, dispatch, transport and workflow authority stay. |
| Business Definition | Contribution + Localization; Sequence if proven | Versioned definitions/formula/condition/relationship semantics; publish/trust/schema execution/record persistence stay. |
| Business Schema | Business Definition | Deterministic plans/blueprints; DBAL/DDL/approval/locks/migrations stay. |
| Record Query | Record Values + Business Definition + Conversion | Bounded query AST/portable validation; SQL/policy predicates/reference/data access stay. |
| Record Model | Context + Business Definition | Neutral commands/queries/results/ports; App record service remains transaction/authorization/orchestration owner. |
| Reporting | Contribution + Definition + Integration + Context + Access + Conversion; narrow Computation contract if proven | Semantic report/projection/plans; SQL, policy enforcement, export authority/artifact storage and delivery stay. |
| Content Model | Localization + Context + Access | Content semantics remain distinct from business records; preserve historical database-driven presentation behavior. |
| Navigation | Content + Context + Access | Neutral tree/build/registry behavior; trusted active contributions, policy enforcement, routes and rendering stay. |
| Administrator Contract | Contribution + Context + Access + Interface Standard | Neutral administrator declaration vocabulary; graphical services/shell/recovery remain host-owned. |
| Portal Contract | Contribution + Context + Access | Explicit opt-in declarations; sessions/identity/portal-admin isolation remain App-owned. |
| Business Surface Contract | Contribution + Context + Access + Definition + Query | Business action/view declarations; generated handlers/renderers/authorization/data services remain App-owned. |

## Native ordering and no-premature-cutover rule

The approved Engine is one C++20 repository with five cohesive kernels: exact decimal, definition/formula VM, document batch, report and canonical streaming. The thin `kumwe/kumwe-engine` PIE/Zend binding exposes `Kumwe\Engine`, module `kumwe_engine`, platform `ext-kumwe_engine`; no FFI/subprocess/fallback/alias path exists.

Semantic/corpus releases and Computation Phase 1A precede Engine stable. Engine candidate cross-build runs in a separate binding worktree and returns an external attestation tied to both exact trees; any change invalidates it. First App-eligible Engine release is 1.0.0 / ABI 1 with all five modules. Engine Phase 2 and binding Phase 1 are two prompts for one task, never two concurrent implementations. The binding embeds unchanged digest-verified Engine source for network-free compilation. After binding release, Computation Phase 1B adapter and App extension provisioning may proceed separately; provisioning must merge green before Computation Phase 2 alone switches business runtime and removes prior PHP code/tests. PHP native classes do not implement late-loaded Composer interfaces during MINIT; the semantic package owns the thin userland adapter and fails readiness closed.

No extraction proves the ERP roadmap gates. The seven feature sessions remain predecessor-merge ordered; final qualification requires all 16 released-artifact exercises, real three-database evidence, security/query non-leakage, lifecycle, delivery parity, restore/key rotation and full deployment evidence. Preserve the current App equivalent of historical CMS PR #17 instead of duplicating its presentation model.

## Wording tensions requiring deliberate boundaries

- Automation's generic DI text mentions workers, while its specific exclusion keeps host worker loops, daemons and trusted-generation execution in App. The specific boundary controls.
- Administrator's narrative discusses a conditional Navigation signature edge, but its catalog ceiling omits it. Reporting/Query language similarly suggests a cross-edge absent from Reporting's ceiling. Prefer App composition; never add either Composer dependency without a reconciled ownership decision.
- Documented DI aliases mean canonical interface-to-implementation service bindings only; they do not authorize historical namespaces, `class_alias`, remaps or bridge classes.
- Generic “extract implementation” in Canonical JSON's Phase 1 prompt does not override its specific semantic/corpus-only brief. Likewise “required native extension” in broad native narrative does not override Computation's explicit extension-free contract baseline or retained pre-cutover App runtime.
- Package implementation, native source candidate, published release, verified release, App adoption and roadmap acceptance remain distinct. This resolves broad prose that would otherwise create circular prerequisites or require premature App removal.

Complete review finished for all 104 ZIP files, the external roadmap and all twelve current App governance rulings. Per-file SHA-256 coverage inventory is recorded in `document-coverage.sha256`; native and downstream detailed summaries supplement the condensed map above.
