---
schema: "kumwe-migration-handoff/v2"
artifact_kind: "framework_php"
migration_id: "KUMWE-MIG-2026-007"
change_set: "KUMWE-CS-2026-007"
state: "draft_pr_open"
source:
  app:
    repository: "https://github.com/kumwe/app"
    baseline_commit: "960ce8ec00cf724a7cae03e5ba09c4852c9ab54e"
    examined_paths:
      - "src/Shared/Domain/CanonicalJson.php"
      - "src/BusinessDefinition/Domain/CanonicalDefinitionJson.php"
      - "src/Extension/Runtime/RuntimeCanonicalJson.php"
      - "src/OpenApi/Infrastructure/CanonicalOpenApiJson.php"
      - "src"
      - "tests"
      - "composer.json"
      - "composer.lock"
      - "AGENTS.md"
      - "docs/architecture/governance/decisions.md"
      - "build/capability-index/v1.json"
    old_namespace_roots: []
    capability_index_sha256: "87ded886f35f74878ca9eb8db4c36e23d681c4a49891f76dfc3210f385a7ce39"
  semantic_inputs:
    -
      owner: "kumwe/app"
      version_or_commit: "960ce8ec00cf724a7cae03e5ba09c4852c9ab54e"
      manifest_or_corpus: "src/Shared/Domain/CanonicalJson.php"
      sha256: "df80b60dd4cf382a443894b69971fb92fe31cdc62318e5d1b185571534af41a1"
  examined_dependencies:
    - "Producer 0.2.0 canonical profile is distinct: UTF-16, safe integers, objects, SRI digest."
    - "Extension SDK 0.2.4 has a distinct definition/manifest canonicalizer that rejects floats."
    - "Conversion 0.1.2 owns exact decimal values; no runtime dependency selected."
    - "No Kumwe runtime dependencies; inspected installed sources establish exclusions only."
  active_related_pull_requests:
    - "https://github.com/kumwe/app/pull/135"
target:
  repository: "https://github.com/kumwe/canonical-json"
  artifact_identity: "kumwe/canonical-json"
  canonical_namespace_or_abi: "Kumwe\\CanonicalJson"
  branch: "agent/extract-canonical-json-semantics-v2"
  pull_request: "https://github.com/kumwe/canonical-json/pull/1"
ownership:
  responsibility: "Generic canonical JSON semantic profile, budgets, findings and language-neutral corpus."
  non_responsibilities:
    - "Production encoding, hashing, native ABI implementation and binding."
    - "Definition, Runtime, Producer/Studio and OpenAPI canonical profiles."
    - "App authorization, persistence, transactions, crypto policy, delivery and readiness."
  allowed_dependency_ceiling: []
  implementation_owner: "kumwe/canonical-json"
  next_consumer: "kumwe/app"
  public_manifests:
    -
      path: "resources/public-api/v1.json"
      sha256: "6abe0873accaea2a0c517e451a6c9662d31dac2482090d7bdcc242dda5b2f72c"
    -
      path: "resources/capabilities/v1.json"
      sha256: "207db7763acd13900d810e406a56a52853096f59623f27cfbd24b11fc21521f6"
    -
      path: "resources/service-map/v1.json"
      sha256: "bb68285b5544c3c06e1d687c5ea453165d4743d6601e7f2b1c17feef25858a90"
    -
      path: "resources/semantics/v1.json"
      sha256: "621b7dfae136ce7635a234f047e7b744a06e2b7ad57c28ae09aeaa4a4308979c"
    -
      path: "resources/ownership/v1.json"
      sha256: "93b68f1268ad1f654295c80293f33d69601640f088d0e648b7e94cf28a3d828b"
    -
      path: "resources/corpus/v1.json"
      sha256: "84d21b12e7a2bfd752356d9a6e664bcb332e209d19017e7634e7485a4fa4e250"
  intentionally_excluded:
    - "src/Shared/Domain/CanonicalJson.php remains the App production executor."
    - "Definition, Runtime, OpenAPI and installed Producer/SDK canonicalizers retain separate ownership."
    - "tests/Oracle is test-only and excluded from every runtime archive."
    - "Native concrete signatures require a separate joint ownership agreement before native implementation."
framework_php:
  composer_package: "kumwe/canonical-json"
  canonical_namespace: "Kumwe\\CanonicalJson"
  public_api_manifest: "resources/public-api/v1.json"
  capability_manifest: "resources/capabilities/v1.json"
  service_map: "resources/service-map/v1.json"
  extracted_symbols: []
  consumers:
    app_code:
      - "src/Administrator/Http/Handler/AdministratorAccessControlHandler.php"
      - "src/Application/Automation/ChangePlan.php"
      - "src/Application/Automation/IdempotencyRecord.php"
      - "src/Application/Automation/IdempotencyResult.php"
      - "src/Application/Automation/JobEnvelope.php"
      - "src/Application/Automation/ScheduleOccurrenceKey.php"
      - "src/Audit/Domain/AuditAnchorDigest.php"
      - "src/Audit/Domain/AuditEventDigest.php"
      - "src/BusinessIntegration/Application/EventContractRegistry.php"
      - "src/BusinessIntegration/Domain/RecordedEventEnvelope.php"
      - "src/BusinessIntegration/Infrastructure/DoctrineOutboxStore.php"
      - "src/BusinessReporting/Infrastructure/DoctrineProjectionStore.php"
      - "src/Identity/Application/Administration/AccessControlService.php"
    configuration_and_di: []
    reflection_and_string_references: []
    fixtures_and_examples:
      - "tests/Unit/Application/Automation/CanonicalJsonTest.php"
      - "tests/Unit/Identity/Application/Administration/AccessControlServiceTest.php"
    external:
      - "kumwe/engine future C ABI corpus replay"
      - "kumwe/kumwe-engine future PHPT corpus replay"
      - "kumwe/computation future semantic adapter/readiness agreement"
  dependency_injection:
    mode: "direct"
    provider: null
    factories: []
    aliases: []
    service_lifetimes:
      - "Immutable semantic values are supplied explicitly per operation."
    configuration_keys: []
    provider_absence_reason: "No runtime service; Computation owns future native binding."
native_cpp: null
php_extension: null
tests:
  moved_or_added:
    - "tests/Case/CorpusTest.php"
    - "tests/Case/MetadataTest.php"
    - "tests/Case/ArchitectureTest.php"
    - "tests/Oracle/CanonicalJson.php frozen test-only semantic oracle"
    - "tests/Oracle/Replay.php test-only bounded replay"
  remain_in_app_or_consumer:
    - "tests/Unit/Application/Automation/CanonicalJsonTest.php"
    - "tests/Unit/Identity/Application/Administration/AccessControlServiceTest.php"
    - "App audit, job, idempotency, integration, projection, security, database, lifecycle and delivery tests."
    - "Definition/Runtime/OpenAPI/Producer/Studio executor tests remain with those distinct owners."
  split_tests:
    - "Corpus preserves generic source ordering/INF semantics; App still tests its active executor."
  prohibited_duplicates:
    - "Do not copy this package unit/corpus suite into App against vendor types."
    - "Do not remove active PHP executor tests during semantic-only adoption."
  corpora:
    - "resources/corpus/v1.json; digest in resources/corpus/v1.sha256; 79 exact vectors."
documentation:
  charter: "CHARTER.md"
  readme: "README.md"
  public_api: "docs/public-api.md"
  architecture: "docs/architecture.md"
  integration_or_consumer: "docs/integration.md"
  examples:
    - "examples/typed-consumer.php"
  changelog_record: "CHANGELOG.md / 0.1.0"
release_expectations:
  version_policy: "SemVer; pre-1.0 exact pins; profile changes need reviewed minor successor."
  expected_artifact_types:
    - "Composer source ZIP"
    - "GitHub immutable release and version tag"
  required_checks:
    - "composer check"
    - "Protected main before publication; GitHub immutable releases enabled beforehand."
    - "Independent source/tag/archive/API/corpus/registry verification after human merge."
  required_registry_or_installer: "Packagist / Composer"
  required_external_attestation: true
next_task:
  phase_name: "Canonical JSON Phase 2 semantic/API/corpus adoption only"
  permitted_only_when:
    - "Human merge and immutable release-on-record publication are observed."
    - "Fresh external RELEASE-ATTESTATION.yaml verifies the exact artifact, manifests, corpus and clean consumer."
    - "App source drift has been reviewed without deleting newer portable behavior."
  consumer_repository: "kumwe/app"
  dependency_or_native_change: "Exact-pin verified semantic package only; no extension requirement or runtime switch."
  namespace_or_api_replacements: []
  files_to_update:
    - "composer.json"
    - "composer.lock"
    - "build/capability-index/v1.json"
    - "build/capability-index/v1.sha256"
    - "docs/architecture/capability-index.md"
    - "CHANGELOG.md"
    - "docs/architecture/migrations/KUMWE-MIG-2026-007.yaml"
    - "docs/architecture/migrations/change-sets/KUMWE-CS-2026-007.yaml"
    - "docs/architecture/non-roadmap/NRM-2026-009.yaml"
  files_to_remove: []
  tests_to_remove: []
  tests_to_retain_or_add:
    - "Retain generic App executor tests and all host responsibility tests."
    - "Add exact installed semantic profile/corpus identity and capability-index integration proof."
  di_or_provisioning_changes:
    - "None; native provisioning and Computation cutover are separate later tasks."
  capability_index_changes:
    - "Record canonical-json.semantics from the exact installed package manifests."
  changelog_and_evidence_changes:
    - "Record semantic-only adoption; NRM-2026-009; no runtime or roadmap completion claim."
    - "Commit the external release attestation unchanged and record a serialized integration train."
  verification_commands:
    - "composer install"
    - "composer check-platform-reqs"
    - "composer kumwe:capability-index"
    - "composer kumwe:capability-index-check"
    - "composer kumwe:core-growth-check"
    - "composer qa"
    - "Run the App database/browser/deployment and affected acceptance CI matrix."
concurrency:
  likely_conflict_files:
    - "composer.json"
    - "composer.lock"
    - "CHANGELOG.md"
    - "build/capability-index/v1.json"
    - "docs/architecture/governance/core-growth-baseline.json"
    - "src/Shared/Domain/CanonicalJson.php"
  related_migrations: []
  ownership_conflicts: []
  integration_train: null
  resolution_rule: "semantic-preservation"
governance:
  roadmap_source_sha256: "a202155ef1a65f5ab293d4f8397ebf4ac430db7f1e877c776bbe7851e6fe18d8"
  roadmap_refs: []
  non_roadmap_refs:
    - "NRM-2026-009"
  completion_claim: false
decisions:
  - "Semantic extraction introduces three metadata types, not a moved PHP executor."
  - "Generic-v1 freezes finite binary64 rendering with serialize_precision=-1 behavior."
  - "Explicit input/depth/node/output limits are safety refinements requiring later App cutover proof."
  - "No ConfigProvider, aliases, native classes, execution interface or PHP fallback in this package."
  - "Empty extracted_symbols is intentional: the new DTO/enums have no historical App FQCN."
blockers:
  - "App adoption awaits independent immutable release verification."
  - "Native concrete FQCN/ABI agreement and Engine/extension releases remain separate work."
  - "Before initial publishing merge, maintainers must protect main and enable immutable releases."
---

# Canonical JSON Phase 1 handoff

## Migration/implementation summary

This package owns the generic canonical semantic profile, Limits, Profile and FindingCode metadata,
and a 79-vector language-neutral corpus. No production PHP canonicalizer moved. The exact frozen
App source is test-only, checksum-bound and excluded from runtime archives. The original App executor
and its unit tests continue running until the separate Computation-owned native runtime cutover.

## Public API and responsibility

See docs/public-api.md for every enum case, property, constructor parameter and refusal.
The standard manifests and resources/semantics/v1.json cover signatures, backed values and defaults.
docs/ownership.md records why Definition, Runtime, Producer/Studio, SDK and OpenAPI remain separate.
No ConfigProvider, alias, native FQCN or encoding service is supplied by this package.

## Capability reuse/semantic input review

The App baseline and source checksum are recorded above. Installed Producer 0.2.0, SDK 0.2.4 and
Conversion 0.1.2 were inspected by behavior and owner. None is a runtime input dependency here.
Producer UTF-16 ordering is explicitly distinguished by supplementary-plane corpus keys; its object,
numeric and digest rules cannot silently replace the generic profile. App's capability-index digest
records the inspected locked graph. This extraction needs no unverified Kumwe dependency coordinate.

## Consumer inventory

docs/consumer-inventory.json lists 13 direct production consumers and two test consumers. Phase 2
must retain their current static executor calls. There is no old-to-new executable FQCN map and no
package ConfigProvider to wire. Engine/extension will consume the released corpus as conformance data,
not Composer PHP algorithms. Native concrete signatures are agreed in their own ownership manifest.

## Test ownership

The package owns metadata behavior, limits/refusals, complete corpus, API/docs, architecture and archive
tests. tests/Unit/Application/Automation/CanonicalJsonTest.php stays in App during semantic adoption
because its PHP executor stays active. After the verified native cutover, Computation removes that
executor and its implementation-only tests. All host audit/idempotency, persistence, authorization,
job/integration, projection, lifecycle, recovery and delivery tests stay in App. Mixed tests must split
by responsibility; do not copy the package suite into App or delete tests solely by directory name.

## Next-task execution notes

Before publishing merge, enable protected main and GitHub immutable releases. The workflow refuses
unprotected release mutation and verifies immutable=true after publication; settings cannot be inferred
from package CI. After merge/release, a fresh session verifies the public artifact and registry.
Only its passing external attestation unlocks semantic-only App adoption. Exact-pin through Composer,
regenerate the capability index and record MIG/CS007, NRM009 and an integration train. Remove no PHP
executor or executor tests, provision no extension and claim no runtime acceleration in that PR.

## Drift check

Diff current App src/Shared/Domain/CanonicalJson.php and its listed consumers/tests against the exact
baseline above. New portable behavior requires a separate owning-package successor release; keep
host-specific changes. Compare the current three manifests and corpus digest with this released
handoff before adoption. Do not resolve shared Composer/evidence changes wholesale with ours/theirs.

## Validation recipe and observed local results

Run composer install, composer check and the no-dev autoload/example lane. The check includes all
79 vectors, frozen source byte parity for accepted cases, hostile transport, budget/refusal regressions,
enum/default agreement and negative architecture fixtures. The archive gate builds a ZIP, installs
that ZIP as a real dependency with Packagist disabled and no dev/plugins/scripts, then verifies all
shipped paths, documented types, corpus checksum and example through the consumer autoloader.
Final-head CI results and exact tested commit/archive identity belong to external evidence, not this file.
No App adoption, native execution, publication or roadmap acceptance is claimed by these package checks.

Observed locally on PHP 8.5.10: 9 tests and 568 assertions pass, including 79 corpus vectors; strict
PHPStan, PSR-12, documentation, manifests, architecture and the 28-file archive consumer pass. App's
actual strict PackageManifests loader accepts this package as v2-manifested. The local combined check
was stopped by the advisory endpoint timing out; its online security result is established by CI.
