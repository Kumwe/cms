---
schema: "kumwe-core-growth-record/v1"
id: "KUMWE-CGR-2026-004"
title: "App context authority and existing consumers of canonical foundation package types"
symbols:
  - Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal
  - Kumwe\App\Application\Authorization\SystemIdentity
  - Kumwe\App\Application\Authorization\ExecutionContextAttribute
  - Kumwe\App\Extension\Runtime\ExtensionExecutionContext
  - Kumwe\App\Application\Authorization\ResourceOwnershipScopeService
  - Kumwe\App\Application\Authorization\SiteGroupAdministration
  - Kumwe\App\Application\Automation\AutomationManagementService
  - Kumwe\App\Application\Presentation\Preference\PresentationPreferenceManager
  - Kumwe\App\BusinessDefinition\Application\BusinessDefinitionService
  - Kumwe\App\BusinessIntegration\Application\IntegrationEventConsumerDispatcher
  - Kumwe\App\BusinessIntegration\Application\IntegrationOperationsService
  - Kumwe\App\BusinessIntegration\Application\ProcessWorkDispatcher
  - Kumwe\App\BusinessRecord\Application\BusinessRecordIdempotencyPurger
  - Kumwe\App\BusinessRecord\Application\PostingPeriodService
  - Kumwe\App\BusinessRecord\Application\RecordValueCodec
  - Kumwe\App\BusinessReporting\Application\ExportAttemptPublisher
  - Kumwe\App\BusinessReporting\Application\ExportGenerationService
  - Kumwe\App\BusinessReporting\Application\ExportService
  - Kumwe\App\BusinessSchema\Application\BusinessSchemaExecutor
  - Kumwe\App\BusinessSchema\Application\BusinessSchemaPlanner
  - Kumwe\App\BusinessSchema\Application\BusinessSchemaService
  - Kumwe\App\BusinessSecurity\Application\Administration\BusinessSecurityAdministrationService
  - Kumwe\App\BusinessSecurity\Application\Approval\ApprovalService
  - Kumwe\App\BusinessSurface\Application\BusinessMutationPlanService
  - Kumwe\App\BusinessSurface\Application\BusinessOperationStatusService
  - Kumwe\App\BusinessSurface\Application\BusinessSurfaceCatalog
  - Kumwe\App\BusinessSurface\Application\BusinessSurfaceService
  - Kumwe\App\BusinessSurface\Application\CustomBusinessActionExecutor
  - Kumwe\App\BusinessSurface\Application\GeneratedBusinessActionStepUp
  - Kumwe\App\BusinessSurface\Application\MutationPlanCipher
  - Kumwe\App\Content\Application\ContentModelService
  - Kumwe\App\Content\Application\TranslationGroupRepository
  - Kumwe\App\Extension\Application\Trust\TrustStore
  - Kumwe\App\Extension\Contribution\TranslationGroupDeclaration
  - Kumwe\App\Identity\Application\Administration\AccessControlService
  - Kumwe\App\Identity\Application\StepUp\TotpStepUpProvider
  - Kumwe\App\Localization\Application\MessageOverrideService
  - Kumwe\App\Localization\Application\SiteDefaultLocale
  - Kumwe\App\Media\Application\MediaService
  - Kumwe\App\Navigation\Application\NavigationService
  - Kumwe\App\Studio\Application\Host\StudioLocalizationHostPort
  - Kumwe\App\Studio\Application\Host\StudioProducerMutationBoundary
  - Kumwe\App\Studio\Application\Media\StudioMediaService
layer: "application"
capability_index_sha256: "da7334d1d81abce6b7bb5e69c6fc784706be08917a0d0c0ec29565832add116e"
packages_reviewed:
  -
    package: "kumwe/access-context"
    version: "0.1.2"
    symbols_inspected:
      - "Kumwe\\Context\\Contract\\Principal"
      - "Kumwe\\Context\\Contract\\SystemActor"
      - "Kumwe\\Context\\Exception\\InvalidContext"
      - "Kumwe\\Context\\Value\\ExecutionContext"
      - "Kumwe\\Context\\Value\\SiteContext"
    source_inspected:
      - "vendor/kumwe/access-context/src"
    tests_inspected:
      - "vendor/kumwe/access-context/docs/test-ownership.md"
  -
    package: "kumwe/extension-sdk"
    version: "0.2.4"
    symbols_inspected:
      - "Kumwe\\Extension\\Spi\\Application\\ExecutionContext"
      - "Kumwe\\Extension\\Spi\\Http\\ExtensionRequest"
    source_inspected:
      - "vendor/kumwe/extension-sdk/src/Spi"
    tests_inspected:
      - "vendor/kumwe/extension-sdk/resources/PIN.json"
  -
    package: "kumwe/transaction"
    version: "0.1.2"
    symbols_inspected:
      - "Kumwe\\Transaction\\Contract\\TransactionManager"
      - "Kumwe\\Transaction\\Contract\\TransactionState"
    source_inspected:
      - "vendor/kumwe/transaction/src"
    tests_inspected:
      - "vendor/kumwe/transaction/docs/test-ownership.md"
  -
    package: "kumwe/localization"
    version: "0.1.1"
    symbols_inspected:
      - "Kumwe\\Localization\\Domain\\LocaleTag"
      - "Kumwe\\Localization\\Application\\DefaultLocaleProvider"
      - "Kumwe\\Localization\\Application\\Translator"
    source_inspected:
      - "vendor/kumwe/localization/src"
    tests_inspected:
      - "vendor/kumwe/localization/docs/test-ownership.md"
  -
    package: "kumwe/secret-envelope"
    version: "0.1.1"
    symbols_inspected:
      - "Kumwe\\Secret\\Value\\EncryptedEnvelope"
      - "Kumwe\\Secret\\Contract\\EnvelopeCipher"
      - "Kumwe\\Secret\\Contract\\KeyProvider"
    source_inspected:
      - "vendor/kumwe/secret-envelope/src"
    tests_inspected:
      - "vendor/kumwe/secret-envelope/docs/test-ownership.md"
search_terms:
  - "execution context"
  - "principal"
  - "system actor"
  - "request attribute"
  - "authenticated principal"
  - "provenance"
  - "step-up"
  - "extension context"
  - "transaction boundary"
  - "afterCommit"
  - "language tag"
  - "site default locale"
  - "encrypted envelope"
  - "key rotation"
required_capability: "Retain host context authority and existing orchestration through canonical package types."
consumers:
  - "src/Application/Authorization/DenyByDefaultAuthorizationGateway.php"
  - "src/Administrator/Http/Middleware/AdministratorSessionMiddleware.php"
  - "src/Portal/Http/Middleware/PortalSessionMiddleware.php"
  - "src/Http/Middleware/BearerAuthenticationMiddleware.php"
  - "src/Delivery/Http/Api/ApiExecutionContext.php"
  - "src/BusinessSurface/Application/BusinessSurfaceService.php"
  - "src/BusinessSurface/Application/Custom/CustomBusinessSurfaceDispatcher.php"
  - "src/BusinessSurface/Application/CustomBusinessActionExecutor.php"
  - "src/BusinessRecord/Application/PolicyBusinessRecordReader.php"
  - "src/BusinessIntegration/Application/ValidatedContributedJobHandler.php"
  - "src/BusinessIntegration/Application/IntegrationEventConsumerDispatcher.php"
  - "src/BusinessIntegration/Application/JobQueueIntegrationEventHandler.php"
  - "src/Kernel/ContainerFactory.php"
  - "src/Localization/Application/SiteDefaultLocale.php"
  - "src/BusinessRecord/Infrastructure/Persistence/DoctrineRecordSecretRotation.php"
overlap_reviewed: []
decision: approved
decided_by: "Codex (PR #142 adoption coordinator; standing maintainer mandate)"
reviewer: "Codex (/root/integration_scope; independent architecture review)"
decided_on: "2026-09-12"
pull_request: "https://github.com/kumwe/app/pull/142"
---

This stable identifier was independently allocated by access-context adoption PR #141 and foundation
adoption PR #142. The September 12 reconciliation retains both bounded decisions under the existing ID;
no symbol, historical approval or package ownership is renumbered or discarded. Scope A covers the four
App identity/context adapters from master. Scope B covers the 39 existing foundation consumers and only
their documented package-type substitutions. These scopes do not authorize each other's additional behavior.

The paragraphs labelled historical below preserve their original decision and evidence. Their old test
results are historical evidence, not verification of the current rebased PR. Current combined validation
is tracked by PR #142 and `KUMWE-CONFLICT-2026-002`.

| Historical scope | Source PR | Decision authority | Independent review | Index digest |
|---|---|---|---|---|
| A: context adapters | #141 | eWɘyn, September 12 | Llewellynvdm, as recorded in master | `e41df6fb5673d5af3df2ae91ac5eec7b2b0cd28f28bfa3d78c180e0df74a572b` |
| B: foundation signatures | #142 | Delegated package-adoption instruction, September 10 | Codex, delegated by Llewellynvdm | `580034e9b11a5921bb9b00616d61396e8f004da036f5c9a6a0fb042a793bc602` |

The front matter identifies the present combined review and its current capability-index digest. The
human approval described under scope A applies to those four retained adapters only; the broader scope B
and this collision reconciliation are agent decisions under the standing maintainer mandate. None of
these recorded decisions represents a human GitHub review event.

## Capability required

### Scope A: historical access-context decision

Every App use case reads one immutable execution context, and that context now comes from
`kumwe/access-context`. The package names its actor through two neutral ports, `Principal` and `SystemActor`,
and its handoff excludes the App's `AuthenticatedPrincipal` and `SystemIdentity` from the extraction: the host
must implement the ports on the identities it already owns, add no historical alias, keep the request attribute
that carries the context through a PSR-7 pipeline, and keep the adapter that presents the context to extension
code typed against the SDK's `Kumwe\Extension\Spi\Application\ExecutionContext`.

### Scope B: historical foundation decision

Existing application consumers must continue accepting the same transaction, locale and encrypted-envelope values
after the owning classes move to the verified packages. This record covers signature substitutions only.

## Why existing package APIs are insufficient

### Scope A: historical access-context decision

`kumwe/access-context` 0.1.2 owns the values and the ports only. Its charter forbids authentication, grants,
capabilities, sessions and membership lookup, so it cannot carry the App's grant list, capability checks,
re-issue of a context at multi-factor strength, or the request attribute a middleware pipeline needs; the
handoff's `intentionally_excluded` list names exactly these as App-owned. `kumwe/extension-sdk` 0.2.4 declares
the read-only context contract extensions consume and the `ExtensionRequest::CONTEXT` attribute they read it
from, but it must not depend on `kumwe/access-context`, and the package value implements no SDK interface, so
neither package can hand an extension a host context or recover the host context from an SDK envelope.

### Scope B: historical foundation decision

The package APIs are sufficient for their portable responsibilities and are consumed directly. They do not
own the host services and persisted content or business-definition relationships whose signatures now name
those APIs. The growth gate detects the changed fully qualified parameter and return types.

## Why extending the owning package is inappropriate

### Scope A: historical access-context decision

Implementing the ports on the package side would move the App's identity model into a package whose charter
excludes it; implementing the SDK contract on the package value would couple the access-context package to the
extension SDK, which the package's dependency ceiling (`[]`) forbids. The request-attribute key is host wiring.

### Scope B: historical foundation decision

Transaction orchestration, configured site defaults, stored content relationships, authorization and key
custody remain the existing host responsibilities. This change adds no algorithm or portable fallback to App.

## Why a new focused package is inappropriate

### Scope A: historical access-context decision

There is no portable bounded context in binding one host's principal, one host's closed set of system
identities and one host's request pipeline to the ports; a second host binds its own.

### Scope B: historical foundation decision

There is no new capability to extract in this change. Existing App consumers continue their established
role while the three portable foundations become dependencies. Later planned package adoptions may remove
additional consumers; this record does not give those consumers permanent ownership of portable behavior.

## App-specific responsibility

### Scope A: historical access-context decision

`AuthenticatedPrincipal` answers `Principal` with the subject, epoch, provenance and fingerprints it already
carried, and `AuthenticatedPrincipal::of()` is the one place the neutral port is narrowed back to the
grant-carrying class, so the gateway, the session store, the MCP guard, the export service and the step-up
handlers authorize nothing for a foreign implementation of the port. `SystemIdentity` answers `SystemActor`
with its backing `system:` token. `ExecutionContextAttribute` holds the PSR-7 key the package value no longer
carries. `ExtensionExecutionContext` wraps the host context at the session middleware, the custom business
view and action envelopes, contributed jobs and integration-event consumers, discloses exactly the seven SPI
coordinates, and `host()` recovers the wrapped context where package code hands the envelope back, refusing
any other implementation.

### Scope B: historical foundation decision

Keep current application orchestration and stored models connected to the package contracts. SiteDefaultLocale
implements the package DefaultLocaleProvider and keeps its existing database lookup, source-locale fallback
and cache behavior. Ciphertext format, key derivation labels and trusted associated-data coordinates remain
unchanged. The record does not authorize new public methods or additional reusable behavior.

## Tests proving the boundary

### Scope A: historical access-context decision

- `tests/Unit/Identity/Application/Authentication/AuthenticatedPrincipalTest.php` pins that the principal issues
  a package context carrying its own authority.
- `tests/Unit/Application/Authorization/ApplicationAuthorizationTest.php` pins provenance, grant, fingerprint and
  system-authority decisions through the gateway on package contexts.
- `tests/Unit/Application/Automation/WorkerTest.php` pins the system actor the worker observes through the port.
- `tests/Unit/BusinessSurface/Application/Custom/CustomBusinessHandlerRegistryTest.php`,
  `CustomBusinessActionExecutorTest.php`, `tests/Unit/BusinessRecord/Application/PolicyBusinessRecordReaderTest.php`
  and `tests/Unit/BusinessIntegration/Application/JobQueueIntegrationEventHandlerTest.php` pin that the host
  adapter is unwrapped and a foreign SPI context is refused.
- `tests/Unit/BusinessIntegration/Application/ValidatedContributedJobHandlerTest.php` pins that a contributed job
  receives the adapter around the exact host context.
- `tests/Integration/BusinessRecord/SdkBusinessRecordReaderIntegrationTest.php` and
  `tests/Integration/Extension/AssetInspectionCustomViewIntegrationTest.php` pin the boundary on the real database.

### Scope B: historical foundation decision

The retained localization settings and middleware tests, host key lifecycle and ExactValueCodec tests,
Doctrine transaction tests, and ContainerTest exercise actual App composition. Focused verification on
PHP 8.5.10 passed 76 tests and 213 assertions. Independent clean consumers verify all three exact package
release archives. The combined change is rebased onto merged sequence PR #139 at master 32d6a6f3. All pre-test local QA gates
pass, and the complete unit/architecture suites pass 3,321 tests with 68,925 assertions on PHP 8.5.10.
Hosted database and browser validation remains required.

## Decision

### Current collision reconciliation

On 2026-09-12, the PR #142 adoption coordinator requested independent architecture review of the reused
identifier. Codex agent `/root/integration_scope` reviewed both index-stage records, their package and
symbol ownership, the current master delta and the governance schemas. The scopes are compatible: App
retains authority and composition, while the released packages retain portable values and contracts.
The combined record is approved for those two bounded scopes under the standing maintainer mandate.
It preserves master as authority and gives no new approval to copy package behavior into App.

The four master adapter symbols and all 39 existing foundation-consumer symbols remain listed once.
Both package-review inventories and both earlier decisions are retained. `KUMWE-TRAIN-2026-004` keeps
the access-context step and adds the foundation step after it. The complete rebase, regenerated index
and baselines, current ownership gates and final CI remain required; this decision approves ownership
and reconciliation only. Revisit either scope under the conditions recorded in its historical decision.

### Scope A: historical access-context decision

Codex's technical review on 2026-09-12 rechecked the four retained classes against `kumwe/access-context`
0.1.2 and `kumwe/extension-sdk` 0.2.4 and supports App ownership. The package's neutral actor ports cannot
grant App authority: the host rejects foreign principal and system-actor implementations. The SDK adapter
exposes only its seven context coordinates and recovers only the exact host context it wrapped. The request
attribute remains host pipeline wiring. The historical scope A digest in the table identifies the seven-package
index after the rebase onto #140.

The project maintainer, `Llewellynvdm`, approved retaining these four classes in the task conversation on 2026-09-12,
conditional on this being the correct architectural boundary and introducing no duplicate implementation.
The published handoff explicitly excludes these host responsibilities; the identities implement the package
ports, and the SDK adapter forwards to the same package context object rather than copying its behavior.
The technical review and the focused host-boundary tests establish those conditions. This approval is
recorded manually from that conversation, not attributed to a GitHub review event.

Revisit when `kumwe/access-context` ships a host-facing request or SDK adapter of its own, or when the
extension SDK consumes the package ports directly.

### Scope B: historical foundation decision

Approved on 2026-09-10 by Codex (review delegated by Llewellynvdm), under the user's instruction to complete
and merge this package-adoption work. The independent review compared all 39 listed source files with
App baseline 2d0a1199 and the exact released package types. After the documented namespace and cipher-type
substitutions, executable bodies are unchanged; imports and documentation name the canonical package APIs.
The only additional declaration is SiteDefaultLocale implementing the existing package DefaultLocaleProvider;
its method bodies, settings fallback and cache behavior are unchanged.

The review also verified the three released archives, public manifests, host test-ownership boundaries and
fresh no-dev consumers on PHP 8.5.10. The previously found missing CatalogueTranslator import in the test
container was corrected before this decision. Combined application QA and final PR evidence remain separate
required gates; this approval does not declare those checks complete.

Approval covers only these existing signatures consuming canonical package types and the unchanged host
responsibilities described above. It grants no approval for new public methods, new portable behavior,
future package upgrades or retaining behavior that a later package adoption must remove. Revisit this record
when a listed consumer gains behavior beyond these substitutions or moves to another extracted package.
