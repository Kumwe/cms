---
schema: kumwe-core-growth-record/v1
id: KUMWE-CGR-2026-003
title: "The record service composes the package numbering port inside its own transaction"
symbols:
  - Kumwe\App\BusinessRecord\Application\BusinessRecordService
layer: application
capability_index_sha256: "7fc302c1b938fd4d62a18ad63cf5c3c1df58153c25faadd2ff6fbf51944970d3"
packages_reviewed:
  - package: kumwe/sequence
    version: 0.2.1
    symbols_inspected:
      - Kumwe\Sequence\Contract\NumberSequenceAllocator
      - Kumwe\Sequence\Exception\NumberSequenceUnavailable
      - Kumwe\Sequence\Value\NumberSequenceFormat
      - Kumwe\Sequence\Value\NumberSequenceReset
      - Kumwe\Sequence\Value\NumberSequenceScope
    source_inspected:
      - vendor/kumwe/sequence/src
    tests_inspected:
      - vendor/kumwe/sequence/docs/test-ownership.md
  - package: kumwe/conversion
    version: 0.1.2
    symbols_inspected:
      - Kumwe\Conversion\Contract\MoneyConverter
    source_inspected:
      - vendor/kumwe/conversion/src
    tests_inspected:
      - vendor/kumwe/conversion/resources/public-api/v1.json
search_terms:
  - "number sequence"
  - "allocated number"
  - "document number"
  - "gapless"
  - "counter"
  - "allocator"
  - "temporarily unavailable"
  - "fiscal period"
required_capability: "Allocate every allocated-number field of a record create inside the command's own authorized transaction, through the package port the host binds its Doctrine adapter to, and report a held counter as the record vocabulary's transient refusal so the idempotent command is replayed rather than guessed at."
consumers:
  - src/Kernel/ContainerFactory.php
  - src/Delivery/Http/Api/Business/BusinessRecordApiHandler.php
  - src/Delivery/Console/Command/ManageBusinessRecordsCommand.php
  - src/BusinessSurface/Application/BusinessSurfaceService.php
  - src/BusinessSurface/Application/BusinessMutationPlanService.php
  - src/BusinessReporting/Infrastructure/BusinessRecordServiceReportReader.php
overlap_reviewed: []
decision: pending
decided_by: "eWɘyn"
reviewer: null
decided_on: "2026-09-10"
pull_request: "https://github.com/kumwe/app/pull/139"
---

## Capability required

A record create must hand every `core.sequence` field a gapless document number that commits with the row,
the revision, the idempotency claim and the audit entry, or not at all. The number is drawn from the counter
the field's declaration names, inside the transaction the command already holds, after authorization and
validation and before the write. When another allocator holds the counter, the command must be replayed as a
whole rather than guess at a value, and the caller must keep seeing the record vocabulary's one transient
refusal, with the driver failure reachable underneath it, so the retry policy and the 503 with `Retry-After`
behave exactly as before the package existed.

## Why existing package APIs are insufficient

`kumwe/sequence` 0.2.1 owns what a numbered field declares (`NumberSequenceFormat`, `NumberSequenceReset`,
`NumberSequenceScope`), the storage-neutral allocator port (`NumberSequenceAllocator`) and the port's one
refusal (`NumberSequenceUnavailable`). Its charter and handoff exclude the allocator implementation, the
transaction, the locks, the retry policy, the fiscal-period calendar and the host's record scope; the
package composes nothing and ships no service. `kumwe/conversion` 0.1.2 owns exact decimal values and
converters and has no numbering vocabulary. Neither package can decide when, in which transaction and under
which resolved scope a number is allocated, nor translate the port's refusal into the host's own vocabulary.

## Why extending the owning package is inappropriate

The composition is the host's authority: the record scope the counter is keyed by, the posting-period
calendar that answers a fiscal-period key, the command transaction the allocation joins and the record
vocabulary the refusal is translated into all exist only in App. The package's handoff prescribes exactly
this split (the host binds its adapter to the port and `allocateNumbers()` catches `NumberSequenceUnavailable`
and rethrows `BusinessRecordTemporarilyUnavailable`), and moving any of it upstream would put App
authorization, transaction and storage concerns into a package whose charter forbids them.

## Why a new focused package is inappropriate

There is no portable bounded context in composing one host's record command with one host's counter
adapter; a second host composes its own store, its own scope and its own refusal vocabulary against the same
package port.

## App-specific responsibility

Orchestration and persistence: `BusinessRecordService` orders the allocation inside the command's authorized
transaction, resolves the fiscal-period key through the App's posting-period calendar, keys the counter by the
record's resolved scope, and translates the port's refusal into `BusinessRecordTemporarilyUnavailable` so the
bounded retry at `idempotent()` and the delivery layer stay unchanged. Outside App the service would have no
transaction, no scope, no calendar and no refusal vocabulary to translate into. The public surface moved only
because its constructor now names the package port instead of the retired App port.

## Tests proving the boundary

- `tests/Integration/BusinessRecord/BusinessNumberSequenceContentionIntegrationTest.php` pins that the adapter
  raises the package refusal with the driver failure chained, that the record service translates it into
  `BusinessRecordTemporarilyUnavailable` when another transaction holds the counter, and that neither side
  commits a number.
- `tests/Integration/BusinessRecord/BusinessRecordDeadlockIntegrationTest.php` pins the retryable driver failure
  under the package refusal.
- `tests/Integration/BusinessRecord/FiscalPeriodSequenceIntegrationTest.php` pins the fiscal-period key resolved
  through the App calendar.
- `tests/Integration/BusinessRecord/BusinessNumberSequenceIdentityIntegrationTest.php` pins the five-coordinate
  counter identity through the real create command.
- `tests/Integration/Persistence/TransactionBoundaryEngineIntegrationTest.php` pins that the allocation joins
  the command transaction on every engine.

## Decision

Pending the reviewer's approving review on the App pull request that adopts `kumwe/sequence` 0.2.1
(`KUMWE-MIG-2026-002`); the `Core growth approval` workflow records the reviewer and the date and re-records
the baseline. Revisit when `kumwe/sequence` next releases a port whose refusal the host no longer needs to
translate, or when a package owns the record command's transaction boundary.
