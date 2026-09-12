---
schema: kumwe-package-release-record/v1
artifact_kind: framework_php
migration_id: KUMWE-MIG-2026-001
change_set: KUMWE-CS-2026-001
source:
  app:
    repository: https://github.com/kumwe/app
    baseline_commit: 45ab34fdc486a2242d51946625d980dcbd69c40f
    examined_paths:
      - src/Example/Describing
      - tests/Unit/Example/Describing
    old_namespace_roots:
      - Kumwe\App\Example\Describing\
    capability_index_sha256: null
  semantic_inputs: []
  examined_dependencies:
    - psr/container ^2.0
target:
  repository: https://github.com/kumwe/example-v2
  artifact_identity: kumwe/example-v2 (Composer library)
  canonical_namespace_or_abi: Kumwe\Example
ownership:
  responsibility: Describe a subject with a configured prefix through one stable contract.
  non_responsibilities:
    - Site settings, actor context and authorization.
    - Persistence of subjects or descriptions.
  allowed_dependency_ceiling:
    - psr/container ^2.0
  implementation_owner: kumwe/example-v2
  next_consumer: kumwe/app
  public_manifests:
    - path: resources/public-api/v1.json
      sha256: c190471c6a06c5f48dbae21498d8c4394257229962c457c869fdc0f46dea0218
    - path: resources/capabilities/v1.json
      sha256: b3a3432d2421166dd94b3b9232688819cf82ab5bb77f4416e50a22cbc5ccea7a
    - path: resources/service-map/v1.json
      sha256: 1e80b56250532416f66b069ac2326819d216d10657a3337524f2f305c1ad2a2a
  intentionally_excluded:
    - "Kumwe\\App\\Example\\Application\\DescribeSubject stays in App: it composes settings and authorization."
framework_php:
  composer_package: kumwe/example-v2
  canonical_namespace: Kumwe\Example
  public_api_manifest: resources/public-api/v1.json
  capability_manifest: resources/capabilities/v1.json
  service_map: resources/service-map/v1.json
  extracted_symbols:
    - old_fqcn: Kumwe\App\Example\Describing\Describer
      new_fqcn: Kumwe\Example\ExampleService
      source_path: src/Example/Describing/Describer.php
      target_path: src/ExampleService.php
      kind: class
      public_methods:
        - describe
      public_properties:
        - prefix
      public_constants:
        - DEFAULT_PREFIX
      exceptions: []
      serialization_contract: null
      compatibility: preserved
    - old_fqcn: Kumwe\App\Example\Describing\DescriberInterface
      new_fqcn: Kumwe\Example\Contract\ExampleServiceInterface
      source_path: src/Example/Describing/DescriberInterface.php
      target_path: src/Contract/ExampleServiceInterface.php
      kind: interface
      public_methods:
        - describe
      public_properties: []
      public_constants: []
      exceptions: []
      serialization_contract: null
      compatibility: preserved
  consumers:
    app_code:
      - src/Example/Application/DescribeSubject.php
    configuration_and_di:
      - src/Kernel/ContainerFactory.php
    reflection_and_string_references: []
    fixtures_and_examples: []
    external: []
  dependency_injection:
    mode: config-provider
    provider: Kumwe\Example\ConfigProvider
    factories:
      - Kumwe\Example\Container\ExampleServiceFactory
    aliases:
      - Kumwe\Example\Contract\ExampleServiceInterface => Kumwe\Example\ExampleService
    service_lifetimes:
      - "Kumwe\\Example\\ExampleService: shared"
    configuration_keys:
      - kumwe.example.prefix
    provider_absence_reason: null
native_cpp: null
php_extension: null
tests:
  moved_or_added:
    - tests/ExampleServiceTest.php
  remain_in_app_or_consumer:
    - tests/Integration/Example/DescribeSubjectIntegrationTest.php
  split_tests: []
  prohibited_duplicates:
    - tests/Unit/Example/Describing/DescriberTest.php
  corpora: []
documentation:
  charter: CHARTER.md
  readme: README.md
  public_api: docs/public-api.md
  architecture: docs/architecture.md
  integration_or_consumer: docs/integration.md
  examples: []
  changelog_record: "CHANGELOG.md ## 0.1.0"
release_expectations:
  version_policy: SemVer; exact pins while pre-1.0; the newest CHANGELOG heading is the release record
  expected_artifact_types:
    - Composer dist archive
  required_checks:
    - composer check
    - clean-consumer gate
  required_registry_or_installer: Packagist
  required_external_attestation: true
governance:
  completion_claim: false
decisions:
  - The prefix policy stays in App as host authority.
blockers: []
consumer_contract:
  permitted_only_when:
    - RELEASE-ATTESTATION.yaml with status verified exists for the 0.1.0 release
  consumer_repository: kumwe/app
  dependency_or_native_change: composer require kumwe/example-v2:0.1.0
  namespace_or_api_replacements:
    - Kumwe\App\Example\Describing\Describer -> Kumwe\Example\ExampleService
  files_to_update:
    - src/Example/Application/DescribeSubject.php
    - src/Kernel/ContainerFactory.php
  files_to_remove:
    - src/Example/Describing/Describer.php
    - src/Example/Describing/DescriberInterface.php
  tests_to_remove:
    - tests/Unit/Example/Describing/DescriberTest.php
  tests_to_retain_or_add:
    - tests/Integration/Example/DescribeSubjectIntegrationTest.php
  di_or_provisioning_changes:
    - Bind Kumwe\Example\Contract\ExampleServiceInterface to the App adapter in ContainerFactory
  capability_index_changes:
    - composer kumwe:capability-index adds the kumwe/example-v2 entry
  changelog_and_evidence_changes:
    - CHANGELOG.md Added entry; ledger record KUMWE-MIG-2026-001
  verification_commands:
    - composer qa
---

## Package contract

The describing contract and its default implementation moved to `kumwe/example-v2`; the App adapter and
the prefix policy stayed in App because they read site settings.

## Public API and responsibility

`ExampleServiceInterface`, `ExampleService`, `ConfigProvider` and `ExampleServiceFactory`; see
`docs/public-api.md`.

## Dependencies and semantic inputs

No installed package owned the describing contract; searches by responsibility found only the App classes
being extracted.

## Consumer contract

`src/Example/Application/DescribeSubject.php` and the kernel binding in `src/Kernel/ContainerFactory.php`.

## Test ownership

The unit test of the service moved with it; the App integration test stays and proves composition.

## Consumer verification

Require the verified release, replace the old names, delete the old classes and their unit test, bind the
adapter in the kernel, regenerate the capability index and record the ledger entry.

## Compatibility and drift

Compare `src/Example/Describing` at the baseline commit with the current tree before adopting.

## Validation

`composer check` in the package passed locally on the tested head; the clean-consumer gate passed.
