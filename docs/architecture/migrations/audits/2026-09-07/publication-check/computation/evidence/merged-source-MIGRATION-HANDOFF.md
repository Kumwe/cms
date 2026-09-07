---
schema: "kumwe-migration-handoff/v2"
artifact_kind: "framework_php"
migration_id: "KUMWE-MIG-2026-008"
change_set: "KUMWE-CS-2026-008"
state: "draft_pr_open"
source:
  app:
    repository: "https://github.com/kumwe/app"
    baseline_commit: "960ce8ec00cf724a7cae03e5ba09c4852c9ab54e"
    examined_paths:
      - "src/BusinessDefinition/Domain"
      - "src/BusinessRecord/Application"
      - "tests/Unit/BusinessDefinition/Domain"
      - "tests/Unit/BusinessRecord/Application"
      - "composer.json"
      - "composer.lock"
      - "docs/architecture/capability-index.md"
    old_namespace_roots: []
    capability_index_sha256: "87ded886f35f74878ca9eb8db4c36e23d681c4a49891f76dfc3210f385a7ce39"
  semantic_inputs: []
  examined_dependencies:
    - "No semantic API/corpus selected in this contract_baseline phase."
    - "App retains existing Expression, DecimalValue and document validation implementations."
    - "No native extension, Canonical JSON or Conversion runtime dependency is introduced."
  active_related_pull_requests:
    - "https://github.com/kumwe/app/pull/135"
target:
  repository: "https://github.com/kumwe/computation"
  artifact_identity: "kumwe/computation"
  canonical_namespace_or_abi: "Kumwe\\Computation"
  branch: "agent/computation-contract-baseline-v2"
  pull_request: "https://github.com/kumwe/computation/pull/1"
ownership:
  responsibility: "Portable bounded execution transport, exact identities and compiler/executor contracts."
  non_responsibilities:
    - "Engine algorithms, decimal/expression semantics, document normalization and canonical JSON."
    - "C ABI implementation, Zend binding, extension provisioning and container services."
    - "Authority, persistence, transactions, cache lifetime, localization and App adoption."
  allowed_dependency_ceiling: []
  implementation_owner: "kumwe/computation"
  next_consumer: "kumwe/engine"
  public_manifests:
    - path: "resources/public-api/v1.json"
      sha256: "39f7ded46a09531a34e24284e88bbfb946713eee9a9a9562b3c8fc6e059072ad"
    - path: "resources/capabilities/v1.json"
      sha256: "15b01a0425a01ec2b76538e045e70ba67ffb61d058ecb61c9f674d2aa13c2522"
    - path: "resources/service-map/v1.json"
      sha256: "2e9e00f04d6add962be2ed381fb0b8db9772f78a5a4f8b33d10c1f2cec20a28c"
    - path: "resources/native-ownership/v1.json"
      sha256: "88e1cc4e8c0430b5770058f4a67fa10d0c000d533acd89c5a43c691ca0a459dc"
    - path: "resources/conformance/v1.json"
      sha256: "30a64cf682a46c38ca99544d5abcad43cda7071bb9702621226bf297411b8bfc"
  intentionally_excluded:
    - "No verbatim App extraction or App code/test removal in Phase 1A."
    - "No native classes, adapter, ConfigProvider, PHP executor, alias or fallback."
    - "No semantic input release/corpus selected; test profile is synthetic transport-only data."
framework_php:
  composer_package: "kumwe/computation"
  canonical_namespace: "Kumwe\\Computation"
  public_api_manifest: "resources/public-api/v1.json"
  capability_manifest: "resources/capabilities/v1.json"
  service_map: "resources/service-map/v1.json"
  extracted_symbols: []
  consumers:
    app_code: []
    configuration_and_di: []
    reflection_and_string_references: []
    fixtures_and_examples: []
    external:
      - "Future Engine implementation consumes the independently verified contract baseline."
      - "Future Computation Phase 1B adapter consumes verified native releases."
  dependency_injection:
    mode: "direct"
    provider: null
    factories: []
    aliases: []
    service_lifetimes:
      - "Immutable values are directly constructed; compiler/executor implementations are not supplied."
    configuration_keys: []
    provider_absence_reason: "Phase 1A supplies portable contracts without a native adapter or runtime service."
native_cpp: null
php_extension: null
tests:
  moved_or_added:
    - "tests/run.php"
    - "Package-owned contract invariants, wire round trips, identity vectors and hostile bounds."
    - "tools/test-release-record.sh"
    - "tools/verify-clean-consumer.php"
  remain_in_app_or_consumer:
    - "All existing App tests remain during Phase 1A."
    - "App authority, composition, DB/transaction, deployment, stale-generation and recovery coverage."
    - "Engine semantic algorithms, parity corpora, fuzzing, sanitizers and performance coverage."
    - "Native binding Zend lifecycle, cleanup, marshalling, PHPT and installer coverage."
  split_tests:
    - "At native cutover, refresh exact App test methods and separate portable invariants from integration."
    - "Do not delete an App test before its verified replacement implementation is adopted."
  prohibited_duplicates:
    - "Do not duplicate Computation contract unit tests inside App after adoption."
    - "No parallel App or package PHP executor to bypass ordered native cutover."
  corpora:
    - "resources/conformance/v1.json: transport-only vectors, not semantic owner/parity attestations."
documentation:
  charter: "CHARTER.md"
  readme: "README.md"
  public_api: "docs/public-api.md"
  architecture: "docs/architecture.md"
  integration_or_consumer: "docs/integration.md"
  examples:
    - "examples/typed-consumer.php"
  changelog_record: "CHANGELOG.md / 0.1.0 (proposed contract_baseline release record)"
release_expectations:
  version_policy: "SemVer; exact pre-1.0 pins; protected main and immutable release enabled before first merge."
  expected_artifact_types:
    - "Composer package ZIP"
    - "GitHub source archive"
  required_checks:
    - "composer check on 64-bit PHP 8.5"
    - "Real built ZIP as no-dev dependency in a fresh authoritative Composer consumer"
    - "Native-boundary ownership review without claiming an implemented/frozen Engine ABI"
    - "Independent release/source/artifact/manifest/Packagist verification"
  required_registry_or_installer: "Packagist + Composer"
  required_external_attestation: true
next_task:
  phase_name: "Verify contract_baseline release; then Engine and native binding implementation"
  permitted_only_when:
    - "Human merge and immutable release after main protection and release immutability are enabled."
    - "Independent external attestation verifies the exact artifact and full handoff."
    - "Engine selects exact independently verified semantic owner APIs and corpus digests."
  consumer_repository: "https://github.com/kumwe/engine"
  dependency_or_native_change: "Engine implements verified contracts; native binding and Phase 1B follow."
  namespace_or_api_replacements: []
  files_to_update:
    - "Future Engine ABI/header/implementation and semantic dependency records after native review."
    - "Future native binding lifecycle/marshalling/installer and joint ownership records."
  files_to_remove: []
  tests_to_remove: []
  tests_to_retain_or_add:
    - "Keep all App execution tests until actual verified native cutover."
    - "Add Engine algorithm parity, native ABI, fuzz, sanitizer and performance tests."
    - "Add binding Zend lifecycle, resource cleanup, PHPT and installer tests."
  di_or_provisioning_changes:
    - "None in Phase 1A; native provisioning must merge before later App execution cutover."
  capability_index_changes:
    - "None in App Phase 1A; regenerate only after verified dependency adoption."
  changelog_and_evidence_changes:
    - "KUMWE-MIG-2026-008 / KUMWE-CS-2026-008 / NRM-2026-010; no App completion claim."
  verification_commands:
    - "composer check"
    - "Independent exact release/archive/manifest/Packagist verification"
    - "Engine and binding native release gates before Phase 1B or App adoption"
concurrency:
  likely_conflict_files:
    - "Computation public API and native ownership manifests"
    - "Engine ABI headers, semantic input records and implementation plans"
    - "Native binding FQCN ownership, stubs/arginfo and lifecycle plans"
  related_migrations: []
  ownership_conflicts:
    - "Native declarations belong only to Engine binding; Composer must not autoload stubs."
    - "Semantic payload owners retain semantics and release/corpus authority."
  integration_train: null
  resolution_rule: "semantic-preservation"
governance:
  roadmap_source_sha256: "a202155ef1a65f5ab293d4f8397ebf4ac430db7f1e877c776bbe7851e6fe18d8"
  roadmap_refs: []
  non_roadmap_refs:
    - "NRM-2026-010"
  completion_claim: false
decisions:
  - "Phase 1A contract_baseline: 20 new portable types, zero immediate App removals."
  - "No semantic input selected; closed metadata encoding does not own semantic canonical JSON."
  - "No provider/native adapter/PHP fallback; exact native ownership is separately reviewed."
blockers:
  - "Immutable publication and independent attestation are future gates, not completed evidence."
  - "Native layout, platform/lifecycle implementation and semantic parity remain downstream work."
  - "App cutover waits for verified baseline/Engine/binding/adapter releases and prior provisioning merge."
---

# Computation migration handoff

## Migration/implementation summary

Phase 1A `contract_baseline` introduces 20 portable public types for bounded execution metadata, compatibility,
opaque transport, ordered findings/results and coarse compiler/executor ports. It removes zero App classes or
tests. Current expression, decimal, validation and execution implementation ownership remains unchanged.

## Public API and responsibility

`docs/public-api.md` documents all exported members and `resources/public-api/v1.json` reflects their signatures.
Four capability groups and an explicit no-provider service map describe the same surface. Internal guards are not
public API. The proposed native FQCNs appear only in ownership documents/manifests and are not PHP declarations.

## Capability reuse/semantic input review

No Conversion or Canonical JSON API/corpus is selected in this phase. Opaque payloads carry exact supplied semantic
coordinates; constructing a coordinate does not verify a release or authorize semantic processing. Synthetic
transport fixtures are explicitly not semantic owner corpora. Engine must select and verify its inputs separately.

## Consumer inventory

`docs/consumer-inventory.json` records zero immediate App consumers/removals and names the retained App owners.
Phase 1A has no App dependency, DI, namespace or test cutover. Future native/adapter consumers must review exact
public APIs and native ownership before implementation; a later App task refreshes exact production/test inventories.

## Test ownership

Computation owns contract invariants, strict wire round trips, bounds, identity/cache vectors, exact compatibility,
ordered batches/findings and the real no-dev consumer archive. Engine owns algorithms and native evidence. The
binding owns lifecycle and marshalling. App retains authority, composition, DB/transaction, provisioning and recovery.
Remove superseded package-unit App cases only when their verified implementation is actually adopted.

## Next-task execution notes

Maintainers enable protected main and release immutability before initial merge. After immutable baseline release,
a separate task independently verifies source/artifact/API evidence. Engine then settles native ABI/layout and exact
semantic inputs, implements and tests; binding/PIE verification and publication follow the native release protocol.
Computation Phase 1B adds the adapter after verified releases. Provisioning merges before later App execution cutover.

## Drift check

Compare App with commit `960ce8ec00cf724a7cae03e5ba09c4852c9ab54e`, refresh live capability/dependency inventories and
review concurrent package/native work. Do not infer semantic release correctness from a DTO coordinate or a draft
manifest. Reconcile public ownership and exact tuples before updating interfaces or corpus records. Never add aliases,
mutable dependencies or a parallel PHP executor to avoid release ordering.

## Validation recipe and observed local results

Run `composer check` on 64-bit PHP 8.5 for metadata/security, release parsing, lint/docs, architecture/manifests, smoke,
examples, PSR-12, max PHPStan, contract tests and the actual built ZIP dependency consumer. The PR records observed
results. Final tested commit, immutable release and artifact digests belong in independent external evidence after
publication. This handoff claims neither release verification nor App/native implementation completion.
