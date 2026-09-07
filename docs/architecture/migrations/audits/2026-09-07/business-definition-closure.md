# Business Definition extraction closure — 2026-09-07

Read-only planning evidence; no package or App implementation changed and no upstream release is attested here.

Baseline: live App `master` is `960ce8ec00cf724a7cae03e5ba09c4852c9ab54e` (confirmed by root via GitHub). Local `342755a4743cfd61e9cee64fee7dc6ee2c7f0014` differs only in audit documentation. SDK source inspected is App's installed `kumwe/extension-sdk` 0.2.4. Package source inspected is the previously reviewed Contribution, Localization and Sequence checkouts; these reads establish candidate APIs, not release verification.

Completely read the Business Definition implementation brief and both Phase 1/2 prompts. Also inspected related Canonical JSON and Record Values/native boundaries and Session 2 ERP requirements. Machine-readable path inventory: `business-definition-closure.json` in this scratch directory.

## Decision

This package is feasible, but it is not ready for an unqualified bulk copy of the brief's historical “33 domain + approximately seven application” estimate. The current tree has exactly **33 domain and 14 application types**, 11,504 lines including comments/blank lines; 109 production PHP files outside `src/BusinessDefinition` import its namespace and 106 test/support files reference it. Three domain types already belong to Sequence, two are PHP execution/arithmetic implementations, and the validator has a previously undeclared SDK presentation dependency.

The defensible portable target is **28 existing domain types + eight existing application types = 36**, before any explicitly reviewed neutral ports or actual service factories. Two of those domain types must lose execution methods as an intentional versioned boundary change. Do not claim that 36 is a final release API count until the SDK profile and Contribution owner decisions below are settled.

Fresh independent release verification is required for every selected Kumwe dependency before implementation/publication relying on its API. A merged upstream PR is not that evidence.

## Exact existing type ownership

All paths below are relative to App.

The following 28 files under `src/BusinessDefinition/Domain/` form the portable definition closure:

```text
ActionDefinition.php
BuiltInFieldTypes.php
CanonicalDefinitionJson.php
CompatibilityChange.php
CompatibilityClassification.php
CompatibilityPlan.php
ComputationMode.php
DefinitionOwner.php
DefinitionOwnerType.php
DefinitionStatus.php
DeleteBehavior.php
DocumentViewDefinition.php
EntityTypeDefinition.php
Expression.php
FieldDefinition.php
FieldTypeDefinition.php
IdentityStrategy.php
InvalidBusinessDefinition.php
LocalizedDefinitionText.php
PortalOperation.php
RecordInvariantDefinition.php
RelationshipDefinition.php
RelationshipKind.php
ScopeMode.php
Sensitivity.php
StorageMode.php
ViewDefinition.php
WorkflowBinding.php
```

The following eight files under `src/BusinessDefinition/Application/` are portable values, structural validation and registries:

```text
BusinessDefinitionCompatibilityAnalyzer.php
BusinessDefinitionContributionRegistry.php
BusinessDefinitionValidator.php
DefinitionCatalogEntry.php
DefinitionDraft.php
DefinitionVersionRecord.php
FieldTypeDefinitionResolver.php
FieldTypeRegistry.php
```

`DefinitionDraft` and `DefinitionVersionRecord` check supplied revision/checksum/version consistency; moving these DTO invariants does not move publication authority. `DefinitionCatalogEntry` is a passive immutable snapshot with no authority; its constructor currently imposes no validation, which must be preserved or changed explicitly rather than accidentally inferred.

| Existing path | Disposition |
|---|---|
| `src/BusinessDefinition/Domain/NumberSequenceFormat.php` | Already owned by `Kumwe\Sequence\Value\NumberSequenceFormat`; use the verified release, no copy. |
| `src/BusinessDefinition/Domain/NumberSequenceReset.php` | Already owned by `Kumwe\Sequence\Value\NumberSequenceReset`. |
| `src/BusinessDefinition/Domain/NumberSequenceScope.php` | Already owned by `Kumwe\Sequence\Value\NumberSequenceScope`. |
| `src/BusinessDefinition/Domain/ExpressionEvaluator.php` | No Business Definition runtime export. A frozen renamed oracle may live only under non-distributed tests/tools. |
| `src/BusinessDefinition/Domain/DecimalValue.php` | No duplicate arithmetic runtime export. It supports the old executor and `src/BusinessReporting/Application/ReportService.php`; exact numeric semantics are Conversion-owned, execution is Engine-owned. Retain old App code until its actual execution cutover; use a non-distributed oracle for baseline corpus if needed. |
| `src/BusinessDefinition/Application/BusinessDefinitionService.php` | App: authorization, transactional draft/publish/supersede orchestration, audit and schema observation. |
| `src/BusinessDefinition/Application/BusinessDefinitionRepository.php` | Do not copy whole interface: exposes `SiteContext`, locking, `saveDraft`, `publish`, `changeStatus`, `setOwnerActive`. If a neutral read port is required, split a minimal read-only contract; App retains write authority. |
| `src/BusinessDefinition/Application/BusinessDefinitionContractAdmission.php` | App: site-scoped publication admission. |
| `src/BusinessDefinition/Application/PackageDefinitionSynchronizer.php` | App: site/trust/runtime generation reconciliation. |
| `src/BusinessDefinition/Application/BusinessDefinitionNotFound.php` | Keep with App's site-scoped lookup/service until a neutral read-port requirement proves otherwise. |
| `src/BusinessDefinition/Application/BusinessDefinitionRevisionConflict.php` | Keep with App's optimistic persistence/publication lifecycle. |

Also retain all current `src/BusinessDefinition/Infrastructure/`, `Administrator/`, `Delivery/`, the business definition migration and external App handlers. Do not migrate DBAL, transactions, site authorization, active-generation selection, trust, audit, cache invalidation, handlers, Twig/Lit, recovery or executable extension registries.

## Required dependency and ownership decisions

1. **Sequence is proven, not optional now.** `BusinessDefinitionValidator.php` directly uses format parsing, scope/reset enum cases and `MAXIMUM_LENGTH` at lines 16–18, 104, 638–674, 862 and 933. Runtime APIs needed: `NumberSequenceFormat::fromConfiguration()`, its public `scope`/`reset` values, `MAXIMUM_LENGTH`, `NumberSequenceScope::Organization` and `NumberSequenceReset::FiscalPeriod`. Package tests must cover definition-to-sequence coherence only, not repeat Sequence formatting/key/timezone matrices. Sequence's reviewed reserved `-` scope-key refusal is an upstream behavior fix; account for it when pinning the release.

2. **Localization is proven.** `EntityTypeDefinition.php`, `FieldDefinition.php` and `LocalizedDefinitionText.php` expose/use `LocaleTag|string`; `LocalizedDefinitionText` uses `LocaleTag::fromString()`, canonical value/fallbacks, and catches `InvalidLocaleTag`. Replace only those namespace imports with `Kumwe\Localization\Domain\*` from the verified release. Package tests own translated definition normalization, duplicate normalized tags, 64-locale/member bounds and checksum effects. They should not copy LocaleTag's complete language-tag parser test suite.

3. **Contribution is not a drop-in replacement for DefinitionOwner.** The present definition owner has Core, Extension and Site kinds. Site ownership serializes as `site.<identifier>`. `Kumwe\Contribution\ContributionOwner` supports only core/package, has a private constructor and bounds each package segment to 63 characters; old `DefinitionOwner::extension()` permits longer segments. Existing `src/Extension/Contribution/OwnedExtensionBindingRegistrar.php` explicitly translates an SDK contribution owner into a definition owner by string. Therefore preserve the domain-specific site dimension, avoid blindly substituting registry/owner types, and document any tighter extension grammar as a versioned admission change. A package dependency on Contribution is justified only if a concrete core/extension ownership operation delegates to its verified API; the ceiling does not require an unused dependency. Do not import SDK's old `Kumwe\Extension\Spi\Contribution\ContributionOwner` into the new package. An SDK successor adoption remains separately necessary for App's general Contribution migration.

4. **Undeclared SDK field-presentation dependency must be resolved before claiming a valid portable closure.** `BusinessDefinitionValidator.php:23,114` invokes `Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldPresentationConfiguration::fromArray()` from SDK 0.2.4. Its exact profile is: a shallow associative configuration object, 64 keys, key grammar `[a-z][a-z0-9_]{0,62}`, scalar/null or scalar/null lists of at most 256 values, strings at most 4,096 bytes, no floats/executable/nested values, valid UTF-8 JSON and at most 32,768 encoded bytes. This is not simply the existing canonical-definition guard: `CanonicalDefinitionJson` accepts nested objects/arrays and has different bounds. Business Definition's allowed Kumwe dependencies exclude SDK, and Business Surface Contract already depends downstream on Business Definition, so adding that downstream package back would make a cycle. Safe options require an explicit ownership decision: move the portable configuration profile to its sole upstream semantic owner with an SDK successor adoption, or introduce a mandatory neutral configuration-admission port and keep SDK-specific admission at App composition. The latter must not install a permissive/no-op default or silently weaken independently claimed validation. Do not copy SDK's validator under a second name, silently add SDK to the dependency ceiling, or omit the refusal tests.

5. **Third-party UUID dependency is real.** `EntityTypeDefinition.php` and `BusinessDefinitionValidator.php` call `Ramsey\Uuid\Uuid`. Preserve the approved UUID grammar through an explicit `ramsey/uuid` runtime dependency or a separately evidenced migration; no ad-hoc regex replacement. This is not a Kumwe dependency-cycle issue.

6. **Keep canonical profiles distinct.** `CanonicalDefinitionJson` is the definition-specific profile, not the generic `Shared\Domain\CanonicalJson` targeted by the Canonical JSON brief. Preserve its current depth 32, 512-entry collection budget, sorted string map keys, list order, UTF-8 and no-float/object/resource rules and exact JSON flags. Do not combine canonicalizers based on naming or add an unproven Canonical JSON dependency.

## Formula/native boundary that must be frozen first

`Expression::evaluate()` directly dispatches to the PHP evaluator; `RecordInvariantDefinition::isSatisfied()` calls it. Moving these methods unchanged would violate the brief. Keep the immutable AST, validation, dependency inspection and canonical serialization in Business Definition; declare removal of these two execution APIs and assign compile/execute/plan identity to Computation and native Engine/extension types in the joint FQCN manifest. No package runtime method may silently select the old executor or invoke PHP per expression node.

Freeze at least the following current semantics into a versioned, language-neutral corpus before changing the API boundary:

- 22 allowlisted operators including the `line_aggregate` leaf; its only reductions are `count` and numeric `sum`.
- AST depth 12, 128 nodes, 32,768 canonical bytes, strict property sets, arities, declared result types and explicit decimal division scale.
- Sorted/deduplicated record-field and owned-line dependencies; graph/cycle/type/invariant rejection; line aggregates excluded from computed fields/action conditions where the current definition forbids them.
- **Eager evaluation**, including `and`, `or`, `if`, `coalesce`: a failing unused branch currently fails the complete expression. A native implementation must not accidentally switch to short-circuit semantics.
- Missing field/ungathered collection is an error; null/absent handling, strict scalar types, float refusal, integer overflow and divide-by-zero; empty line sum/count and skipped missing/null line values.
- Exact decimal normalization, negative zero, scale, rounding and digit limits. The old `DecimalValue` has a 4,096-digit arithmetic budget; do not assume Conversion's accepted inputs/rounding are identical without corpus comparison.
- Canonical definition bytes/digests, compatibility classifications and deterministic ordered refusal/finding behavior. Current validator raises the first failure, not a collected report; input-order changes must be intentional and tested.

Current production execution consumers requiring later Computation/App cutover include:

```text
src/BusinessRecord/Application/BusinessRecordService.php
src/BusinessRecord/Application/RecordFieldVisibility.php
src/BusinessRecord/Application/RecordRuleValidator.php
src/BusinessSchema/Infrastructure/Schema/DoctrinePhysicalSchemaGateway.php
src/BusinessReporting/Application/ReportService.php
```

Do not delete the old App executor or its actual tests merely because semantic definitions have been extracted; the consuming runtime must first run against verified Computation/Engine/extension releases and fail readiness for missing/incompatible native execution. Package Phase 1 must not attempt that App cutover.

## Test ownership, exact current paths

There are **19 direct Business Definition unit-test files, containing 132 named test methods before data-provider expansion**. Fifteen files/110 named methods concern portable definition behavior or formula semantics and are candidates for package tests/corpus after the splits below. This is a static inventory, not a claimed PHPUnit execution count.

Move the pure constructor/validation/canonical/compatibility assertions from:

```text
tests/Unit/BusinessDefinition/Application/AllocatedNumberFieldRuleTest.php
tests/Unit/BusinessDefinition/Application/BoundedJsonDefaultBudgetTest.php
tests/Unit/BusinessDefinition/Application/BusinessDefinitionCompatibilityAnalyzerTest.php
tests/Unit/BusinessDefinition/Application/FiscalPeriodSequenceCoherenceTest.php
tests/Unit/BusinessDefinition/Application/NumberSequenceScopeCoherenceTest.php
tests/Unit/BusinessDefinition/Application/ReversalRelationshipValidationTest.php
tests/Unit/BusinessDefinition/Domain/AggregateInvariantDeclarationTest.php
tests/Unit/BusinessDefinition/Domain/CustomBusinessDefinitionReferenceTest.php
tests/Unit/BusinessDefinition/Domain/DocumentViewDefinitionTest.php
tests/Unit/BusinessDefinition/Domain/EntityTypeDefinitionTest.php
tests/Unit/BusinessDefinition/Domain/LocalizedDefinitionLabelTest.php
tests/Unit/BusinessDefinition/Domain/WorkflowBindingTest.php
```

The next three files contain **26 named test methods** mixing AST/canonical behavior and execution. Split AST assertions into Business Definition unit tests and execution expectations into the versioned corpus/non-distributed oracle; Engine/PHPT own execution parity. Do not leave a package runtime executor just to avoid splitting these tests:

```text
tests/Unit/BusinessDefinition/Domain/ExpressionTest.php
tests/Unit/BusinessDefinition/Domain/ExpressionPropertyTest.php
tests/Unit/BusinessDefinition/Domain/ExpressionLineAggregateTest.php
```

`EntityTypeDefinitionTest::testValidFieldPresentationConfigurationRoundTripsIntoTheSdkInput()` explicitly composes the SDK (`FieldPresentationInput`) and belongs with integration ownership, subject to the field-profile ownership decision. Its presentation budget method also needs careful separation so no upstream-owned validator is re-tested as though Business Definition implemented it. Tests referring to “publishes” but simply calling `validateGraph()` are package semantic tests; names alone do not make them database integration tests.

`tests/Unit/BusinessDefinition/Domain/NumberSequenceFormatTest.php` has 12 named methods already covered by the Sequence extraction. Its deletion belongs to Sequence App adoption, not Business Definition's package tests.

Retain these three App test files (10 named methods):

```text
tests/Unit/BusinessDefinition/Administrator/BusinessDefinitionFormMapperTest.php
tests/Unit/BusinessDefinition/Infrastructure/DoctrineBusinessDefinitionRepositoryTest.php
tests/Unit/BusinessDefinition/Infrastructure/DoctrinePersistedFieldTypeDefinitionResolverTest.php
```

The form mapper's formula execution assertion must later call the computation boundary when the App runtime does; the graphical adapter test itself remains App-owned.

Retain full App publication, trust, persistence and composed-runtime evidence, including:

```text
tests/Integration/BusinessDefinition/BusinessDefinitionRuntimeIntegrationTest.php
tests/Integration/BusinessDefinition/OpenApiDefinitionPublicationAdmissionIntegrationTest.php
tests/Integration/BusinessRecord/BusinessRecordEvolutionIntegrationTest.php
tests/Integration/BusinessRecord/BusinessRecordMutationGenerationIntegrationTest.php
tests/Integration/BusinessRecord/AggregateDocumentIntegrationTest.php
tests/Integration/BusinessRecord/AggregateDocumentConcurrencyIntegrationTest.php
tests/Integration/BusinessSchema/BusinessSchemaRuntimeIntegrationTest.php
tests/Integration/BusinessSchema/BusinessSchemaRecoveryIntegrationTest.php
tests/Support/BusinessRuntimeBackupAcceptance.php
```

These cover real MariaDB/MySQL/PostgreSQL publication/history, authorization, extension disable preservation, audit/transactions, schema backfill/recovery, definition-selected record execution, backup/restore and delivery. The 106-file reference inventory is not a deletion list: most files assert retained host behavior while using definitions as fixtures.

`tests/Architecture/BusinessDefinitionBoundaryTest.php` currently scans the entire App context for Content coupling, `eval` and EAV. Put the package dependency/algorithm/FQCN/public-API guard in the package. Retain/rewrite App's architecture assertion to verify the remaining host boundary and absence of old namespace implementations; do not leave a broken scan of an emptied directory.

## Suggested next action

Root can complete independent next packages now. For Business Definition, first record the SDK presentation-profile ownership and versioned execution-method removal decisions, obtain fresh exact-release attestations for proven dependencies, and freeze the corpus. Then implement the 36-type candidate with appropriate actual factories and neutral ports; open its own Phase 1 PR and stop before App adoption. This resolves concrete blockers rather than publishing an apparently complete package that duplicates Sequence, widens the allowed dependency graph or ships the prohibited PHP VM.
