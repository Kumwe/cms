---
schema: kumwe-core-growth-record/v1
id: KUMWE-CGR-2026-004
title: "The App identities answer the package actor ports and the host adapts the context for extensions"
symbols:
  - Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal
  - Kumwe\App\Application\Authorization\SystemIdentity
  - Kumwe\App\Application\Authorization\ExecutionContextAttribute
  - Kumwe\App\Extension\Runtime\ExtensionExecutionContext
layer: application
capability_index_sha256: "aa9524c9573b2cceaa458498b97d050a19317dc64d302c0400391fe0b8d03ea4"
packages_reviewed:
  - package: kumwe/access-context
    version: 0.1.2
    symbols_inspected:
      - Kumwe\Context\Contract\Principal
      - Kumwe\Context\Contract\SystemActor
      - Kumwe\Context\Exception\InvalidContext
      - Kumwe\Context\Value\ExecutionContext
      - Kumwe\Context\Value\SiteContext
    source_inspected:
      - vendor/kumwe/access-context/src
    tests_inspected:
      - vendor/kumwe/access-context/docs/test-ownership.md
  - package: kumwe/extension-sdk
    version: 0.2.4
    symbols_inspected:
      - Kumwe\Extension\Spi\Application\ExecutionContext
      - Kumwe\Extension\Spi\Http\ExtensionRequest
    source_inspected:
      - vendor/kumwe/extension-sdk/src/Spi
    tests_inspected:
      - vendor/kumwe/extension-sdk/resources/PIN.json
search_terms:
  - "execution context"
  - "principal"
  - "system actor"
  - "request attribute"
  - "authenticated principal"
  - "provenance"
  - "step-up"
  - "extension context"
required_capability: "Let the package execution context name the App's own authenticated principal and system identities through the neutral Principal and SystemActor ports without copying either, keep the one PSR-7 request-attribute key every host middleware writes and every handler reads, and present the host context to extension code through the SDK's read-only contract while recovering the exact host context wherever package code hands the envelope back."
consumers:
  - src/Application/Authorization/DenyByDefaultAuthorizationGateway.php
  - src/Administrator/Http/Middleware/AdministratorSessionMiddleware.php
  - src/Portal/Http/Middleware/PortalSessionMiddleware.php
  - src/Http/Middleware/BearerAuthenticationMiddleware.php
  - src/Delivery/Http/Api/ApiExecutionContext.php
  - src/BusinessSurface/Application/BusinessSurfaceService.php
  - src/BusinessSurface/Application/Custom/CustomBusinessSurfaceDispatcher.php
  - src/BusinessSurface/Application/CustomBusinessActionExecutor.php
  - src/BusinessRecord/Application/PolicyBusinessRecordReader.php
  - src/BusinessIntegration/Application/ValidatedContributedJobHandler.php
  - src/BusinessIntegration/Application/IntegrationEventConsumerDispatcher.php
  - src/BusinessIntegration/Application/JobQueueIntegrationEventHandler.php
overlap_reviewed: []
decision: pending
decided_by: "eWɘyn"
reviewer: null
decided_on: "2026-09-10"
pull_request: "https://github.com/kumwe/app/pull/141"
---

## Capability required

Every App use case reads one immutable execution context, and that context now comes from
`kumwe/access-context`. The package names its actor through two neutral ports, `Principal` and `SystemActor`,
and its handoff excludes the App's `AuthenticatedPrincipal` and `SystemIdentity` from the extraction: the host
must implement the ports on the identities it already owns, add no historical alias, keep the request attribute
that carries the context through a PSR-7 pipeline, and keep the adapter that presents the context to extension
code typed against the SDK's `Kumwe\Extension\Spi\Application\ExecutionContext`.

## Why existing package APIs are insufficient

`kumwe/access-context` 0.1.2 owns the values and the ports only. Its charter forbids authentication, grants,
capabilities, sessions and membership lookup, so it cannot carry the App's grant list, capability checks,
re-issue of a context at multi-factor strength, or the request attribute a middleware pipeline needs; the
handoff's `intentionally_excluded` list names exactly these as App-owned. `kumwe/extension-sdk` 0.2.4 declares
the read-only context contract extensions consume and the `ExtensionRequest::CONTEXT` attribute they read it
from, but it must not depend on `kumwe/access-context`, and the package value implements no SDK interface, so
neither package can hand an extension a host context or recover the host context from an SDK envelope.

## Why extending the owning package is inappropriate

Implementing the ports on the package side would move the App's identity model into a package whose charter
excludes it; implementing the SDK contract on the package value would couple the access-context package to the
extension SDK, which the package's dependency ceiling (`[]`) forbids. The request-attribute key is host wiring.

## Why a new focused package is inappropriate

There is no portable bounded context in binding one host's principal, one host's closed set of system
identities and one host's request pipeline to the ports; a second host binds its own.

## App-specific responsibility

`AuthenticatedPrincipal` answers `Principal` with the subject, epoch, provenance and fingerprints it already
carried, and `AuthenticatedPrincipal::of()` is the one place the neutral port is narrowed back to the
grant-carrying class, so the gateway, the session store, the MCP guard, the export service and the step-up
handlers authorize nothing for a foreign implementation of the port. `SystemIdentity` answers `SystemActor`
with its backing `system:` token. `ExecutionContextAttribute` holds the PSR-7 key the package value no longer
carries. `ExtensionExecutionContext` wraps the host context at the session middleware, the custom business
view and action envelopes, contributed jobs and integration-event consumers, discloses exactly the seven SPI
coordinates, and `host()` recovers the wrapped context where package code hands the envelope back, refusing
any other implementation.

## Tests proving the boundary

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

## Decision

Pending the reviewer's approving review on the App pull request that adopts `kumwe/access-context` 0.1.2
(`KUMWE-MIG-2026-004`); the `Core growth approval` workflow records the reviewer and the date and re-records
the baseline. Revisit when `kumwe/access-context` ships a host-facing request or SDK adapter of its own, or when
the extension SDK consumes the package ports directly.
