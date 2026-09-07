# Package and App test ownership

The maintainer confirmed on 2026-09-07 that extraction continues before App dependency adoption. Each
framework package owns its implementation tests. App keeps tests of its own composition, authority,
adapters, persistence, orchestration and delivery. Package release gates run in the package repository;
App does not rerun a vendor package's unit suite.

Remove an App implementation and its duplicate tests together in the adoption PR, after independently
verifying the exact package release and checking extraction drift. Until then, the old implementation
still runs in App and needs its tests. A test's directory name alone does not decide ownership: an App
adapter tested with a stub is still an App responsibility.

## First six packages

These decisions come from the released handoffs. Counts refer to named test methods at App baseline
`960ce8ec00cf724a7cae03e5ba09c4852c9ab54e`, not data-provider executions or expected CI time savings.

| Package | Package-owned tests removed from App on adoption | App tests retained or split |
| --- | --- | --- |
| Transaction | Contract-shape assertions; duplicated immediate transaction test doubles | Keep real transaction, rollback, nesting, deadlock, audit/outbox and consumer tests. Split the seam architecture test. |
| Sequence | `NumberSequenceFormatTest.php`: 12 methods | Keep contention, fiscal-calendar integration, persistence, identity and rollback tests. Remove coverage attribution to package values. |
| Secret Envelope | `EncryptedEnvelopeTest.php` and `SodiumSecretCipherTest.php`: 9 methods | Split key-lifecycle redaction/duplicate-key assertions. Preserve host-purpose derivation, configured keys, cross-record coordinates, rotation and restore coverage. |
| Access Context | Pure identifier, proof and context invariants | Existing consumers mostly test App security and composition. Split actual duplicate assertions individually; no blanket file deletion. |
| Localization | LocaleTag, MessageIdentifier, CatalogueTranslator and IntlMessagePatternFormatter class tests: 29 methods | Split negotiation from settings/cache behavior. Keep override authorization, catalogue compilation, middleware, Twig, persistence and delivery tests. |
| Contribution | Generic owner, policy, snapshot, collision and registry invariants | SDK moves generic owner assertions in its successor release. App keeps executable registries, trust, activation, manifest reconciliation, recovery and delivery tests. |

The first adoption batch therefore identifies seven complete class-test files containing 50 named
methods for removal, plus specific mixed-test splits. This is not a target for arbitrary deletion or a
claim that App's suite has already shrunk. Later semantic packages own their broader unit and conformance
suites, while App retains the evidence that those packages compose into a working product.

## Required evidence in every following handoff

1. Name each moved source and original test path, its package replacement, and its extraction baseline.
2. List complete App test files to remove and mixed test methods to split. Name the host behavior that
   remains; do not use a broad module path as deletion authority.
3. Exercise portable invariants, boundaries, malformed/hostile inputs, serialization and public contracts
   in the package. Put package-specific API, archive, security and clean-consumer gates there as well.
4. Preserve App composition, authorization, transaction, database, lifecycle, recovery and delivery
   assertions. App tests can invoke package APIs through App services without testing vendor internals.
5. Remove obsolete package-class `CoversClass` declarations from App and update App test/coverage
   inventories truthfully when adoption changes them. Preserve meaningful assertions in split tests.
6. Verify that App neither includes vendor tests nor copies package fixtures, implementation tests or
   semantic corpora. Keep a host-focused integration regression when a boundary's observable behavior
   could change.

Canonical JSON and Computation have a special staged boundary: semantic/corpus ownership can move before
native execution. Current App executor tests remain until the authorized Computation runtime cutover
removes that executor. The semantic package, Engine, PHP extension and App each test their own layer.
