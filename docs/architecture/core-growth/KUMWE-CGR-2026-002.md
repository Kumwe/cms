---
schema: kumwe-core-growth-record/v1
id: KUMWE-CGR-2026-002
title: "The contextual Content authoring resource kind of a Studio host session"
symbols:
  - Kumwe\App\Studio\Domain\Host\StudioResourceKind
layer: domain
capability_index_sha256: "17ed90eb256da0068179d9b1028b86b13b85558eb9762d0bfc511bb4f8f07693"
packages_reviewed:
  - package: kumwe/producer
    version: 0.3.0
    symbols_inspected:
      - Kumwe\Producer\Wire\RequestContext
      - Kumwe\Producer\Wire\OperationRegistry
      - Kumwe\Producer\Schema\StudioDocumentSchemaRegistry
      - Kumwe\Producer\Deployment\StudioDeploymentEmitter
    source_inspected:
      - vendor/kumwe/producer/src/Wire
      - vendor/kumwe/producer/src/Schema
      - vendor/kumwe/producer/src/Deployment
    tests_inspected:
      - vendor/kumwe/producer/tests
  - package: kumwe/extension-sdk
    version: 0.2.4
    symbols_inspected:
      - Kumwe\Extension\Spi\Contribution\CompositionHostBinding
      - Kumwe\Extension\Spi\Contribution\ContributionOwner
    source_inspected:
      - vendor/kumwe/extension-sdk/src/Spi/Contribution
    tests_inspected:
      - vendor/kumwe/extension-sdk/tests
search_terms:
  - "resource kind"
  - "host session resource"
  - "authoring context"
  - "content authoring"
  - "session binding"
  - "resource context"
required_capability: "Name the third host-owned resource a Studio host session may address: one opaque contextual Content authoring context, the exact create or edit target the administrator Content editor opened, so that the host session, its authorization and its refusals are bound to that context and to nothing else."
consumers:
  - src/Administrator/Http/Handler/AdministratorStudioSessionHandler.php
  - src/Studio/Application/Authoring/HostedContentStudioAuthoringConfigurationProvider.php
  - src/Studio/Application/Host/StudioHostSessionAuthority.php
  - src/Studio/Application/Host/StudioProducerHostFactory.php
overlap_reviewed: []
decision: pending
decided_by: "eWɘyn"
reviewer: ""
decided_on: "2026-09-09"
pull_request: "https://github.com/kumwe/app/pull/137"
---

## Capability required

A Studio host session is bound to exactly one host-owned resource, and every operation the session may
perform is authorized against that resource. The Content editor mount adds a third resource: an opaque
contextual Content authoring context, keyed server-side, that stands for the exact create or edit target the
administrator opened. A session opened for that context must be distinguishable from a session opened for a
stored Content entry or a Studio artifact, so that the generic session handler refuses it, the contextual
host port accepts only it, and the refusal category and the audit record name the kind precisely.

## Why existing package APIs are insufficient

`kumwe/producer` 0.3.0 carries the wire (`RequestContext`, `OperationRegistry`) and proves documents
(`StudioDocumentSchemaRegistry`, `StudioDeploymentEmitter`), but it has no notion of what a host session is
bound to: its charter excludes authority and storage, and its `RequestContext` transports opaque session
evidence the host interprets. `kumwe/extension-sdk` 0.2.4 names composition host bindings and contribution
owners for extension authors; it does not enumerate the host's own session resources.

## Why extending the owning package is inappropriate

The resource kinds a host session may address are the host's authority model. Producer deliberately treats
session evidence as opaque, and adding a host-specific enumeration to it would move an App authorization
concept into a package whose charter forbids authority. The extension SDK enumerates author-facing
contribution kinds, not the administrator surfaces of one host.

## Why a new focused package is inappropriate

One enum case with no behaviour beyond naming an App-owned resource is not a bounded context. No second host
would reuse a kind that is defined by App's Content editor and App's authoring-context storage.

## App-specific responsibility

Authority: the kind is the discriminator the session authority, the generic session handler and the
contextual host port use to bind a session to the exact resource it may act on. Outside App the kind would
have no referent, because the authoring context it names exists only in App's persistence.

## Tests proving the boundary

- `tests/Unit/Administrator/Http/Handler/AdministratorStudioSessionHandlerTest.php` pins that the generic
  session handler refuses the contextual kind with `invalid-request`.
- `tests/Unit/Studio/Application/Authoring/HostedContentStudioAuthoringConfigurationProviderTest.php` pins
  that the Content editor mount opens a host session of exactly this kind.
- `tests/Unit/Studio/Application/Authoring/ContentStudioAuthoringContextAuthorityTest.php` pins the
  authorization of the context the kind names.
- `tests/Integration/Studio/ContentStudioAuthoringJourneyIntegrationTest.php` drives the seven authoring
  operations against a session bound to this kind through Producer's dispatcher.

## Decision

Pending human review at the App pull request that adopts Producer 0.3.0, together with
`KUMWE-CGR-2026-001`: the author recorded the review above; the reviewer confirms the boundary, sets
`decision: approved` and names themselves, and re-records the core-growth baseline in the same change.
Revisit when Producer's host agreement gains a host-neutral resource-kind vocabulary.
