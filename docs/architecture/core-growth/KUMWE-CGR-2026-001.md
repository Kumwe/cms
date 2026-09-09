---
schema: kumwe-core-growth-record/v1
id: KUMWE-CGR-2026-001
title: "Contextual Content authoring host behind Producer's Studio wire and deployment layer"
symbols:
  - Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringCatalog
  - Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringDocuments
  - Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringService
  - Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringSession
  - Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringState
  - Kumwe\App\Studio\Application\Authoring\HostedContentStudioAuthoringConfigurationProvider
  - Kumwe\App\Studio\Application\Authoring\StudioHostedDeploymentConfiguration
  - Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextAuthority
  - Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextRepository
  - Kumwe\App\Studio\Application\Composition\StudioContentCompositionService
  - Kumwe\App\Studio\Application\Composition\StudioPublishedEnhancementRuntime
  - Kumwe\App\Studio\Application\Host\StudioAuthoringHostPort
  - Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority
  - Kumwe\App\Studio\Application\Host\StudioProducerError
  - Kumwe\App\Studio\Application\Host\StudioProducerHost
  - Kumwe\App\Studio\Application\Host\StudioProducerHostFactory
  - Kumwe\App\Studio\Application\Release\StudioCoreCatalog
  - Kumwe\App\Studio\Application\Rendering\StudioBlockRendererRuntime
  - Kumwe\App\Studio\Application\Rendering\StudioRenderResultAdmission
  - Kumwe\App\Content\Application\ContentService
layer: application
capability_index_sha256: "17ed90eb256da0068179d9b1028b86b13b85558eb9762d0bfc511bb4f8f07693"
packages_reviewed:
  - package: kumwe/producer
    version: 0.3.0
    symbols_inspected:
      - Kumwe\Producer\Deployment\StudioBrowserAssetLocator
      - Kumwe\Producer\Deployment\StudioBrowserAssetLocation
      - Kumwe\Producer\Deployment\StudioDeploymentEmitter
      - Kumwe\Producer\Deployment\StudioContentSecurityPolicy
      - Kumwe\Producer\Deployment\SameOriginFetchMetadataPolicy
      - Kumwe\Producer\Schema\StudioDocumentSchemaRegistry
      - Kumwe\Producer\Schema\StudioContractResources
      - Kumwe\Producer\Schema\StudioContractRelease
      - Kumwe\Producer\Wire\Dispatcher
      - Kumwe\Producer\Wire\OperationRegistry
      - Kumwe\Producer\Wire\Port\AuthoringPortInterface
      - Kumwe\Producer\Render\BlockRendererRegistry
      - Kumwe\Producer\Render\RenderResult
    source_inspected:
      - vendor/kumwe/producer/src/Deployment
      - vendor/kumwe/producer/src/Schema
      - vendor/kumwe/producer/src/Wire
      - vendor/kumwe/producer/src/Render
    tests_inspected:
      - vendor/kumwe/producer/tests
  - package: kumwe/extension-sdk
    version: 0.2.4
    symbols_inspected:
      - Kumwe\Extension\Spi\Contribution\CanonicalCompositionDocument
      - Kumwe\Extension\Spi\Contribution\CompositionHostBinding
      - Kumwe\Extension\Spi\Contribution\ContributionOwner
    source_inspected:
      - vendor/kumwe/extension-sdk/src/Spi/Contribution
    tests_inspected:
      - vendor/kumwe/extension-sdk/tests
search_terms:
  - "authoring target"
  - "resolve-target"
  - "reusable content type"
  - "save plan"
  - "successor context"
  - "studio-deployment"
  - "browser asset locator"
  - "enhancement runtime"
  - "block lock"
  - "content entry projection"
required_capability: "Open a contextual Studio authoring session for one exact Content create/edit target, emit its proven browser deployment, answer the seven authoring operations with PHP-authoritative Content saves, and defer the pinned enhancement runtime for published compositions."
consumers:
  - src/Kernel/ContainerFactory.php
  - src/Administrator/Http/Handler/AdministratorContentEditorHandler.php
  - src/Administrator/Http/Handler/AdministratorStudioHostHandler.php
  - src/Http/Handler/PublishedContentHandler.php
  - src/Http/Handler/HomePageHandler.php
  - src/Studio/Infrastructure/Release/PinnedStudioContextualAuthoringAvailability.php
  - templates/administrator/content-form.twig
overlap_reviewed: []
decision: approved
decided_by: "eWɘyn"
reviewer: "eWɘyn"
decided_on: "2026-09-09"
pull_request: "https://github.com/kumwe/app/pull/137"
---

## Capability required

The Content editor must open the pinned Studio contextual shell for exactly the item being created or
edited, and every durable effect of that session must terminate in an authorized PHP application
service: resolving the authoring target, listing the reusable Content types, starting from a blank
canvas, a reusable type or the stored entry, planning a save deterministically, and applying
`save-item`, `save-new-type-version` and `save-as-new-type` so that the entry, the Content type version
and the type's Blueprint move together. The session's resource context, session identity, generation and
contribution catalog must be identical in the deployment document, the start snapshot and every save
result, and the block catalog the session offers must be exactly what the pinned browser module compiles
in plus the App's own Content-field blocks. Published pages must defer the pinned enhancement runtime
only when a rendered block needs it.

## Why existing package APIs are insufficient

`kumwe/producer` 0.3.0 owns the wire (`Dispatcher`, `OperationRegistry`, `AuthoringPortInterface`), the
pinned schema interpreter, the release record, the browser-asset locator, the deployment emitter and the
published policies, and this growth composes all of them without re-implementing any. Producer
deliberately carries no authority, storage or application services: it cannot know which Content type
version an entry pins, how a Content entry's title, slug and data map to a Studio entry document, which
opaque context a host session is bound to, or what a save means for App's audited, transactional Content
and model services. `AuthoringPortInterface` is a port to implement, not an implementation.
`kumwe/extension-sdk` owns the contribution SPI the App's canonical documents are registered through; it
has no notion of a Studio session, a block lock union or a deployment document.

## Why extending the owning package is inappropriate

Every symbol here reads App state (Content records, model definitions, host sessions, authoring contexts,
site settings, the administrator locale, the theme) and takes App decisions (authorization, audit,
transactions, entry identity, return paths). The Producer charter excludes authority, storage and
host-specific composition, and the host agreement makes exactly this the host's responsibility.
`StudioCoreCatalog` materializes coordinates Studio does not publish as data; publishing them belongs to
Studio's release record, which is recorded as the sanctioned successor in the Studio playbook.

## Why a new focused package is inappropriate

There is no portable bounded context: the behaviour is the composition of Producer's contract with App's
Content domain, persistence and administrator surface. A second host would compose Producer with its own
services the same way; nothing here would be reused unchanged.

## App-specific responsibility

Authority and composition: open and re-authorize the opaque context and host session per mount, derive
the deployment from App state and hand it to Producer for proof, map Studio documents to Content records
and back, persist through the audited Content and model services, keep the session invariants Studio
reconciles, and admit only the enhancement families the pinned runtime publishes.

## Tests proving the boundary

- `tests/Integration/Studio/ContentStudioAuthoringJourneyIntegrationTest.php` drives resolve-target,
  list-types, start, plan-save, save-item, save-as-new-type and save-new-type-version through Producer's
  dispatcher against the real container and database.
- `tests/Unit/Studio/Application/Authoring/HostedContentStudioAuthoringConfigurationProviderTest.php`
  proves the emitted deployment validates against Producer's pinned schema and binds both opened
  sessions.
- `tests/Unit/Studio/Application/Authoring/ContentStudioAuthoringDocumentsTest.php`,
  `tests/Unit/Studio/Application/Release/StudioCoreCatalogTest.php`,
  `tests/Unit/Studio/Application/Composition/StudioPublishedEnhancementRuntimeTest.php` and
  `tests/Unit/Studio/Infrastructure/Release/PinnedStudioContextualAuthoringAvailabilityTest.php` prove
  the projections, the materialized catalog, the enhancement admission and the readiness gate.

## Decision

Approved on 2026-09-09 by the maintainer, eWɘyn, reviewing at the App pull request that adopts Producer
0.3.0 (`pull_request` above): the boundary recorded above stands, App owns the contextual Content
authoring host and Producer owns the wire, schema, release record, asset locator, deployment emitter and
policies it composes. The core-growth baseline was re-recorded in the same change. The record should be
revisited when Studio publishes the first-party coordinate record (retiring `StudioCoreCatalog`) or when
Producer gains a host-neutral authoring application service.
