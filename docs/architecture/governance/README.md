# Governance guide

Kumwe App is the composition root and the runtime authority. It installs Kumwe packages, binds their
services, enforces authorization and persistence, and delivers the result on five surfaces. It is not the
default home for reusable behaviour: portable behaviour lives in the `kumwe/*` package that owns exactly one
responsibility, and App composes it. This page is the single place that explains the machinery which makes
that policy executable — the capability index, the Core Growth gate, the governance records, their
identifiers and states, and the two-phase migration lifecycle.

The policy text is `AGENTS.md` section 7, items 11 and 12. The operator recipe is `AGENTS.md` section 5,
"Reuse before you build". The maintainer rulings that reconcile the Version 2 documents are in
[decisions.md](decisions.md) and are cited below as `D-GOV-n`. A maintainer who has never opened the
Version 2 documents should be able to run the gates, write a record and adopt a package from this page.

---

## 1. The capability index

### What it is

An inventory of every Kumwe package locked in `composer.lock`: what it is responsible for, what it exports,
what it registers in the container, what it requires natively and what it deprecates. It is generated from
the installed packages and never edited by hand. It is the answer to "does a package already own this?".

### Inputs

1. `composer.lock` — the locked `kumwe/*` packages, the only packages indexed: version, source and dist
   references, licence, PSR-4 roots.
2. `vendor/kumwe/<name>/` — `composer.json`; `CHARTER.md` (the first paragraph after the H1 is the
   responsibility summary); `README.md`; `MIGRATION-HANDOFF.md`; and the three manifests
   `resources/public-api/v1.json`, `resources/capabilities/v1.json` and `resources/service-map/v1.json`.
   A locked package whose vendor directory is missing fails the build: install locked dependencies first.
   The index reads only what a release archive is guaranteed to ship, so a git checkout and a dist
   zipball of the same lock produce the same digest: for a legacy entry that is `composer.json`,
   `resources/` and `src/` alone (its responsibility comes from the registry, its `documentation`
   records `charter: null` and `readme: null`); for a Version 2 package the charter, README, `docs/`,
   `resources/` and `MIGRATION-HANDOFF.md` are required, so a Version 2 release archive must ship them
   and must not `export-ignore` them — one that omits them fails this gate at adoption.
3. [`legacy-packages.json`](legacy-packages.json) — the legacy registry (section 1.6).
4. `docs/architecture/migrations/KUMWE-MIG-*.yaml` — the migration ledger: retired namespaces, removed
   symbols and recorded DI changes.

### Generated files

| File | Committed | Content |
|---|---|---|
| `build/capability-index/v1.json` | No — `build/` is ignored | The index document, `kumwe-capability-index/v1` |
| `build/capability-index/v1.sha256` | No | `<hex>  v1.json` — the index digest |
| `docs/architecture/capability-index.md` | Yes | The rendering; embeds the index digest and the lock digest |

The markdown is the committed authority. Its header names the generator and the check, then
`Index digest: sha256:<hex>` and `Composer lock digest: sha256:<hex>`. It carries one section per package
(coordinates, status, namespaces, responsibility and non-responsibilities, capabilities, dependency
injection, native requirements, deprecations, public symbol count with the manifest path, handoff
coordinates), then the "Extracted namespaces" and "Removed App symbols" tables drawn from the ledger.

### The digest

`sha256` of the `v1.json` bytes is the capability index digest. The same hex appears in the markdown
header, in a package handoff's `source.app.capability_index_sha256`, in a Core Growth Record's
`capability_index_sha256` and in the pull-request "Capability reuse review" section. It tells a reviewer
which inventory a decision was taken against. `php tools/generate-capability-index.php --digest` prints it.

### Commands

| Command | Does |
|---|---|
| `composer kumwe:capability-index` | Regenerates `build/capability-index/` and the markdown |
| `composer kumwe:capability-index-check` | Regenerates in memory; compares with the committed markdown byte for byte |

Run the first after any lockfile, re-pin or vendor manifest change and commit the markdown. The second is
in `composer qa` and CI and exits `1` on drift. Both fail closed on: a locked package without a vendor
directory; a `v2-manifested` package whose manifests or handoff do not validate; a `legacy-unmanifested`
package not registered at the exact locked version; two packages exporting the same FQCN ("duplicate FQCN
owner"), the same capability `id` or the same service or alias key; a service map with a provider but no
factories, or a `null` provider without a `provider_absence_reason`; a capability naming a symbol the
package does not export; a documentation link that does not exist inside the package; and a legacy package
named as the `package` of a migration ledger record. Every failure line starts with `Capability index: `.

### Package status

A package is `v2-manifested` when the three manifests and `MIGRATION-HANDOFF.md` all exist and validate
against the schemas in section 3. Its `release_gate_eligible` is `true`; it may be the `package` of a
migration ledger record. Anything else is `legacy-unmanifested`.

### Legacy-unmanifested entries and `legacy-packages.json`

A Kumwe package released before the Version 2 protocol has no handoff and at most one of the manifests. It
may appear in the index only as an explicitly approved transitional entry in
[`legacy-packages.json`](legacy-packages.json), validated by
[`schemas/legacy-packages.v1.schema.json`](schemas/legacy-packages.v1.schema.json). Each entry records the
exact locked version, the reason the package is legacy, its responsibility and non-responsibilities, its
canonical namespaces, the App namespaces its earlier extraction retired, the approver and the approval date.

- The locked version is recorded exactly. A re-pin re-approves the entry: change the registry in the same
  pull request, or `kumwe:capability-index-check` fails.
- A legacy entry has `release_gate_eligible: false`. It cannot be the `package` of any migration ledger
  record, so it can never satisfy a migration release gate. A package being adopted must ship Version 2
  manifests and a handoff or the gate fails closed (Kumwe-v2-10, D-GOV-10).
- Its public symbols come from its pre-Version-2 manifest when one exists, otherwise from a PSR-4 scan of
  non-`@internal` declarations; `public_symbols_source` records which.
- `verified_legacy_release` is `null` until a `VERIFIED-LEGACY-RELEASE.yaml` (section 3.10) exists for the
  package; then it is that file's path.
- Today's entries (D-GOV-10): `kumwe/conversion 0.1.2` and `kumwe/extension-sdk 0.2.4`. Each leaves the
  registry when its package adopts Version 2 manifests, as `kumwe/producer` did at `0.3.0`
  (`KUMWE-MIG-2026-032`).

---

## 2. The Core Growth gate

### What it detects

`composer kumwe:core-growth-check` (`tools/verify-core-growth.php`) scans every class-like declaration under
`src/` — production only — classifies each FQCN to a layer through `docs/architecture/layers.json`, digests
its public surface (kind, modifiers, parent, interfaces, public constants, public properties, public method
signatures) and compares the inventory with the committed baseline. It refuses:

1. **A stale baseline** — a symbol in the baseline no longer exists in `src/`. Re-record.
2. **A duplicate FQCN owner** — a new or changed FQCN that an installed package exports.
3. **A reintroduced extracted namespace** — the FQCN sits under an `old_namespace` a migration retired.
4. **A reintroduced removed symbol** — the FQCN equals an `old_fqcn` a migration removed; the message names
   the `new_fqcn` to use.
5. **A likely duplicate responsibility** — a package public symbol with the same short name, the same kind
   and at least half of the candidate's public method names (minimum two), unless an approved Core Growth
   Record names the FQCN and lists that package symbol under `overlap_reviewed`. This is why the detector is
   not name-only matching.
6. **Unrecorded portable growth** — a new FQCN or changed surface in `shared`, `domain` or `application`
   without an approved Core Growth Record and a baseline entry citing it.
7. **Unrecorded host growth** — a new FQCN or changed surface in `infrastructure`, `presentation`,
   `delivery` or `kernel` whose baseline entry is missing or carries different `implements`/`extends`
   facts than the declaration. Re-record.
8. **A duplicate service owner** — `src/Kernel/` registers (`Foo::class =>`) a service or alias FQCN a
   package service map owns, unless a migration ledger record's `di_changes[].service` names it.
9. **An old namespace reference** — an import, inline FQCN, `::class` or string literal under `src/`,
   `tests/`, `config/`, `bootstrap/`, `resources/` or `examples/` that names a retired namespace root.
10. **A broken Core Growth Record** — one naming no FQCN that exists in `src/`, two naming the same FQCN,
    or a `pending` or `rejected` record cited by the baseline.

Precondition: the capability index is current (the check runs the same comparison as
`kumwe:capability-index-check`; "stale capability index digest" means run `composer kumwe:capability-index`)
and every governance record validates.

### Portable and host layers

| Layers | Kind | What growth needs |
|---|---|---|
| `shared`, `domain`, `application` | portable | An approved Core Growth Record listing the FQCN in `symbols` |
| `infrastructure`, `presentation`, `delivery`, `kernel` | host | Adapter or composition evidence, recorded |

A portable-layer symbol is a candidate for a package; App has to say, on record, why it stays. The baseline
entry carries `growth.record`, the record's id. A host-layer symbol is presumed to be App's job — an
adapter, a handler, a factory, wiring — and only needs recording: the baseline entry carries
`growth.classification: host-<layer>` with the `implements` and `extends` facts the scanner derives, which
is why the gate can tell when they change.

### Commands and the baseline

| Command | Does |
|---|---|
| `composer kumwe:core-growth-check` | The check above; in `composer qa` and CI; exit `1` on any failure |
| `composer kumwe:core-growth-record` | Re-runs the check, then rewrites the baseline when nothing else fails |

`--record` refuses while a duplicate owner, a reintroduction, an overlap or a missing, pending or rejected
record remains; only "re-record" and "baseline stale" findings are cleared by it. It writes the baseline
deterministically (sorted FQCNs) and prints the added, removed, expanded and renamed symbols.

A rename is not growth. When a surface differs from its baseline entry only because a public signature,
parent or interface now spells a symbol as the package name an adopted migration ledger maps its retired App
name to (`symbols[].old_fqcn` → `new_fqcn`), the check reports "re-record: … differs from the baseline only by
the names KUMWE-MIG-YYYY-NNN retired", `--record` lists the FQCN as `renamed` and keeps the entry's growth
evidence, and any other difference in the same surface is judged as growth exactly as before.

The baseline is [`core-growth-baseline.json`](core-growth-baseline.json), validated by
[`schemas/core-growth-baseline.v1.schema.json`](schemas/core-growth-baseline.v1.schema.json). It holds every
production FQCN with its kind, layer, surface digest and `growth`: `null` for the bootstrap snapshot,
`{ "record": "KUMWE-CGR-YYYY-NNN" }` for recorded portable growth, `{ "classification": "host-<layer>", … }`
for recorded host growth. Commit it in the same pull request as the change that moved it.

### When a Core Growth Record is required

- A new class-like, or a widened public surface, in `shared`, `domain` or `application`.
- Any FQCN, in any layer, that the overlap rule flags against a package symbol.

### When it is not

- Host-layer adapters, handlers, factories and composition: `composer kumwe:core-growth-record` records
  them as host evidence.
- A private change that composes existing public APIs without adding an FQCN or changing a public
  signature: the surface digest does not move, so there is nothing to record.
- A signature that names a symbol a migration ledger moved to its package: the surface differs from the
  baseline only by the retired names, so re-record the baseline and the entry keeps its growth evidence.
- Deleting a symbol: re-record the baseline.

How to write one: [`../core-growth/README.md`](../core-growth/README.md).

---

## 3. Records

Every record has a JSON Schema (2020-12 subset) under [`schemas/`](schemas/) and a validated example under
[`examples/`](examples/). The tools validate every record against its schema before either gate runs; a
schema the validator cannot execute is itself a failure, because a schema that is not enforced is not a
gate. Every YAML record uses the strict subset in section 7. A record's file name equals its `id`; ids are
unique; year-sequence ids match `^[A-Z-]+-[0-9]{4}-[0-9]{3}$`.

### 3.1 Capability index

- Path: `build/capability-index/v1.json` (generated, not committed), rendered to
  `docs/architecture/capability-index.md` (committed).
- Schema: [`schemas/capability-index.v1.schema.json`](schemas/capability-index.v1.schema.json).
- Example: [`examples/capability-index.v1.example.json`](examples/capability-index.v1.example.json).
- Written by `composer kumwe:capability-index`, never by hand.

### 3.2 Core Growth Record

- Path: `docs/architecture/core-growth/KUMWE-CGR-YYYY-NNN.md` — YAML front matter and seven H2 sections.
- Schema: [`schemas/core-growth-record.v1.schema.json`](schemas/core-growth-record.v1.schema.json).
- Example: [`examples/core-growth-record.v1.example.md`](examples/core-growth-record.v1.example.md).
- Written by the agent that adds portable growth; `decision: approved` requires a non-empty `reviewer`.
  The reviewer's approving pull-request review sets it: the `Core growth approval` workflow approves every
  pending record naming that pull request, re-records the baseline and commits as the reviewer.
  Guide: [`../core-growth/README.md`](../core-growth/README.md).

### 3.3 Migration ledger record

- Path: `docs/architecture/migrations/KUMWE-MIG-YYYY-NNN.yaml`.
- Schema: [`schemas/migration-ledger.v1.schema.json`](schemas/migration-ledger.v1.schema.json).
- Example: [`examples/migration-ledger.v1.example.yaml`](examples/migration-ledger.v1.example.yaml).
- Written by Phase 2: old and new symbols, removed paths and tests, retained tests, DI changes,
  capability-index entries, release evidence, the App PR, roadmap and non-roadmap references, conflicts.
  `package` must be a locked `v2-manifested` package; `handoff_sha256` must equal the sha256 of the
  installed `vendor/kumwe/<name>/MIGRATION-HANDOFF.md`. The record feeds the "Extracted namespaces" and
  "Removed App symbols" tables of the index and rules 3, 4 and 8 of the growth gate.

### 3.4 Change set

- Path: `docs/architecture/migrations/change-sets/KUMWE-CS-YYYY-NNN.yaml`.
- Schema: [`schemas/change-set.v2.schema.json`](schemas/change-set.v2.schema.json).
- Example: [`examples/change-set.v2.example.yaml`](examples/change-set.v2.example.yaml).
- The cross-repository state of one migration: roadmap references by exact heading and ordinal, the
  Phase 1, release and Phase 2 coordinates, the evidence groups, `state` from the canonical enum
  (section 5), `completion_claim` and `accepted_by`.

### 3.5 Non-roadmap record

- Path: `docs/architecture/non-roadmap/NRM-YYYY-NNN.yaml`.
- Schema: [`schemas/non-roadmap-record.v1.schema.json`](schemas/non-roadmap-record.v1.schema.json).
- Example: [`examples/non-roadmap-record.v1.example.yaml`](examples/non-roadmap-record.v1.example.yaml).
- Governance, relocation, toolchain and dependency work with no roadmap criterion (D-GOV-12). The first is
  [`../non-roadmap/NRM-2026-001.yaml`](../non-roadmap/NRM-2026-001.yaml), this bootstrap.

### 3.6 Conflict ledger

- Path: `docs/architecture/migrations/conflicts/KUMWE-CONFLICT-YYYY-NNN.yaml`.
- Schema: [`schemas/conflict-ledger.v1.schema.json`](schemas/conflict-ledger.v1.schema.json).
- Example: [`examples/conflict-ledger.v1.example.yaml`](examples/conflict-ledger.v1.example.yaml).
- One per nontrivial semantic three-way resolution: base behaviour, both objectives, what was removed and
  preserved, regenerated files, focused and combined tests, ownership review, resolver and reviewer.

### 3.7 Integration train

- Path: `docs/architecture/migrations/trains/<id>.yaml`; the file name is the record id.
- Schema: [`schemas/integration-train.v1.schema.json`](schemas/integration-train.v1.schema.json).
- Example: [`examples/integration-train.v1.example.yaml`](examples/integration-train.v1.example.yaml).
- Written before an App PR is declared merge-ready, one per Phase 2 PR even under serial execution
  (D-GOV-8): included releases, dependency and merge order, shared-file custodian, hotspots, combined
  clean-install and test commands, conflict ledger location, the maintainer who serialises.

### 3.8 Engine candidate attestation

- Path: `docs/architecture/migrations/evidence/<MIG>/ENGINE-CANDIDATE-ATTESTATION.yaml`, where a native
  Engine candidate is involved; a copy of the external CI artifact, never committed into the candidate tree.
- Schema:
  [`schemas/engine-candidate-attestation.v1.schema.json`](schemas/engine-candidate-attestation.v1.schema.json).
- Example:
  [`examples/engine-candidate-attestation.v1.example.yaml`](examples/engine-candidate-attestation.v1.example.yaml).

### 3.9 Release attestation

- Path: `docs/architecture/migrations/evidence/<MIG>/RELEASE-ATTESTATION.yaml` with `status: verified`, or
  `RELEASE-VERIFICATION-FAILED.yaml` with `status: failed` and non-empty `known_gaps` (D-GOV-4).
- Schema: [`schemas/release-attestation.v2.schema.json`](schemas/release-attestation.v2.schema.json).
- Example: [`examples/release-attestation.v2.example.yaml`](examples/release-attestation.v2.example.yaml).
- Produced by the fresh verification session after publication (D-GOV-11) and committed by Phase 2. A
  passing handoff-plus-attestation pair is the only thing that permits Phase 2.

### 3.10 Verified-legacy release

- Path: `docs/architecture/migrations/evidence/legacy/<package>/VERIFIED-LEGACY-RELEASE.yaml`.
- Schema: [`schemas/verified-legacy-release.v1.schema.json`](schemas/verified-legacy-release.v1.schema.json).
- Example:
  [`examples/verified-legacy-release.v1.example.yaml`](examples/verified-legacy-release.v1.example.yaml).
- Only for an immutable pre-Version-2 upstream dependency, after explicit human approval. It never replaces
  the handoff or release attestation of the package currently being adopted.

### 3.11 Migration handoff (consumed from the package)

- Path: `vendor/kumwe/<name>/MIGRATION-HANDOFF.md` — written in the package repository by Phase 1 after the
  draft PR is opened (`state: draft_pr_open`), shipped in the release, read by App.
- Schema: [`schemas/migration-handoff.v2.schema.json`](schemas/migration-handoff.v2.schema.json) — the
  common front matter plus `oneOf` on `artifact_kind` (`framework_php`, `native_cpp`, `php_extension`).
- Example: [`examples/migration-handoff.v2.example.md`](examples/migration-handoff.v2.example.md).
- Phase 2 executes its `next_task` block; the ledger record pins its digest.

### 3.12 Package manifests (consumed from the package)

- Paths: `vendor/kumwe/<name>/resources/public-api/v1.json`, `resources/capabilities/v1.json` and
  `resources/service-map/v1.json`.
- Schemas: [`schemas/package-public-api.v1.schema.json`](schemas/package-public-api.v1.schema.json),
  [`schemas/package-capabilities.v1.schema.json`](schemas/package-capabilities.v1.schema.json),
  [`schemas/package-service-map.v1.schema.json`](schemas/package-service-map.v1.schema.json).
- Examples: [`examples/package-public-api.v1.example.json`](examples/package-public-api.v1.example.json),
  [`examples/package-capabilities.v1.example.json`](examples/package-capabilities.v1.example.json),
  [`examples/package-service-map.v1.example.json`](examples/package-service-map.v1.example.json).
- The service map must have factories when `config_provider` is set and a `provider_absence_reason` when
  it is `null`; every capability symbol must be an exported public symbol.

### 3.13 App registries

- [`legacy-packages.json`](legacy-packages.json) — schema
  [`schemas/legacy-packages.v1.schema.json`](schemas/legacy-packages.v1.schema.json), example
  [`examples/legacy-packages.v1.example.json`](examples/legacy-packages.v1.example.json). Maintained by hand,
  approved by the maintainer.
- [`core-growth-baseline.json`](core-growth-baseline.json) — schema
  [`schemas/core-growth-baseline.v1.schema.json`](schemas/core-growth-baseline.v1.schema.json), example
  [`examples/core-growth-baseline.v1.example.json`](examples/core-growth-baseline.v1.example.json). Written
  by `composer kumwe:core-growth-record` only.

---

## 4. Identifiers

**Allocation (D-GOV-3).** Identifiers are sequential per record type per calendar year: the next id is the
highest existing sequence in that record's directory plus one. There is no registry file. The bootstrap is
`NRM-2026-001`; the first Core Growth Record will be `KUMWE-CGR-2026-001`; the first migration will be
`KUMWE-CS-2026-001` and `KUMWE-MIG-2026-001`.

**One migration, two identifiers, two records (D-GOV-2).** `KUMWE-CS-YYYY-NNN` names the change set, the
cross-repository state; `KUMWE-MIG-YYYY-NNN` names the ledger record, the App adoption evidence. One
migration allocates the same `NNN` to both. Both are validated; neither replaces the other.

**Never renumber.** An identifier, once used in a branch, a handoff or a PR, keeps its number. Two records
with the same id and different meanings are a conflict to escalate, not to renumber away (Kumwe-v2-04).

---

## 5. Migration states (D-GOV-1)

A change set's `state` uses the eight Kumwe-v2-07 states, in this order. Nothing advances a state except
observed evidence; a green PR is not a release, a release is not adoption, adoption is not an objective.

| State | Meaning | Minimum evidence |
|---|---|---|
| `enabling-refactor` | Boundary approved; extraction started | source baseline, ownership, symbols, tests, refs |
| `package-implemented` | Phase 1 package PR complete and green | namespace, tests, manifests, handoff, PR head |
| `package-released` | Publication observed, not yet trusted | merged SHA, tag or version, published coordinate |
| `release-verified` | Artifact and handoff-attestation pair pass | checksums, manifests, clean consumer, attestation |
| `app-pr-ready` | Phase 2 branch and evidence green | exact release, lock, namespace/DI/removal diff, PR head |
| `core-integrated` | App PR merged and merged target green | App merge SHA and merged-branch evidence |
| `objective-verified` | A roadmap objective has executable App proof | criterion evidence against a released tuple |
| `gate-accepted` | Every criterion in a gate verified and accepted | all gate evidence, gaps and risks, approver |

Phase 1 cannot advance beyond `package-implemented`. Only `release-verified` permits dependent publication
or Phase 2. Only `objective-verified` permits a roadmap objective claim. The Kumwe-v2-04 ten-state machine
maps onto the canonical enum; use the canonical name in every record:

| Kumwe-v2-04 state | Canonical state |
|---|---|
| `planned`, `phase-1-active` | `enabling-refactor` |
| `package-pr-ready` | `package-implemented` |
| `package-merged` | `package-released` |
| `package-released` | `package-released` |
| `release-verified` | `release-verified` |
| `phase-2-active` | `release-verified` |
| `app-pr-ready` | `app-pr-ready` |
| `app-integrated` | `core-integrated` |
| `objective-verified` | `objective-verified` |

The handoff's own `state: draft_pr_open` is a handoff field, not a migration state.

---

## 6. The two-phase lifecycle

One migration is one package PR, one release, one verification and one App PR. Agents prepare and update
branches and PRs; the maintainer merges, tags, publishes and accepts (D-GOV-9). Package repositories release
from `main`; App merges to `master` (D-GOV-5).

| # | Step | Where | Who | May write | Must not |
|---|---|---|---|---|---|
| 1 | Phase 1 | package repository | agent | package source, tests, docs, manifests, handoff | anything in App |
| 2 | Human merge | package repository | maintainer | the merge to `main` | — |
| 3 | Release on record | package repository | automation | tag, artifact, registry publication | a hand-made tag |
| 4 | Verification | outside both trees | fresh session | the attestation file | package or App source |
| 5 | Phase 2 | `kumwe/app` | agent | dependency, code, tests, records, evidence | `vendor/`, `composer.lock` |
| 6 | Human merge | `kumwe/app` | maintainer | the merge to `master` | — |

Step notes:

1. **Phase 1** extracts one responsibility into its package: canonical namespace, tests moved upstream,
   `CHARTER.md`, `README.md`, the three manifests, the newest `## X.Y.Z` heading in the package CHANGELOG
   (that heading chooses the release version, D-GOV-6) and `MIGRATION-HANDOFF.md`, committed after the
   draft PR exists so it can cite the PR URL. It records the App baseline commit, the capability index
   digest it inspected and the exact `next_task` for Phase 2. It does not edit App, predict a tag, or mark
   the change set beyond `package-implemented`.
2. **Human merge** to the package's protected `main`.
3. **Release on record** builds from the merged SHA and publishes the immutable coordinate. Publication
   advances the change set only to `package-released`; it permits no consumption.
4. **Verification** is a fresh session with no prior context (D-GOV-11). It verifies the public artifact,
   manifests and a clean consumer against the released handoff and writes `RELEASE-ATTESTATION.yaml`
   (`verified`) or `RELEASE-VERIFICATION-FAILED.yaml` (`failed`, D-GOV-4). Only a passing pair advances the
   change set to `release-verified`.
5. **Phase 2** adopts the exact verified version in App: pin it in `composer.json`, regenerate `composer.lock`
   with Composer (never by hand), replace old FQCNs with the package symbols, delete the App classes and
   package-unit tests the handoff names, keep App composition, security, database and lifecycle tests, bind
   DI as the handoff prescribes, regenerate the capability index, re-record the Core Growth baseline, and
   write the ledger record, the change set at `app-pr-ready`, the train record, any conflict records, the
   attestation under the evidence path and the CHANGELOG entry citing `(#PR)`. Drift between the Phase 1
   baseline and current App is classified and ported upstream through a successor release before
   integration (D-GOV-7); a newer App implementation is never deleted because an older snapshot was
   extracted. Phase 2 never edits `vendor/`, adds an alias or fallback for the old namespace, or claims a
   roadmap objective.
6. **Human merge** to `master`, rebase-merged. Green merged-target evidence then advances the change set to
   `core-integrated`, recorded in a follow-up record change.

The governance records enter App with the Phase 2 PR; Phase 1 leaves App unchanged. The identifier pair is
allocated at Phase 1 start against the App `master` directories and cited in the handoff and package PR.
The bootstrap PR (`NRM-2026-001`) must be merged before any Phase 2 begins (Kumwe-v2-10).

### Test ownership applies to every package

Existing, legacy and future packages own their portable behavior, boundary/refusal and applicable semantic
conformance tests. Their CI maintains a test-ownership inventory linking each public type (or native ABI
capability) to tests discovered by its real runner. Newly exported types and stale references fail that
package's gate. A semantic owner ships versioned language-neutral corpora; a contract without executable
semantics records why an additional corpus is inapplicable. An inventory proves accountable test ownership,
not branch coverage or the completeness of assertions; reviewers still inspect behavior and hostile cases.

App runs its own composition, authorization, persistence, lifecycle, recovery and delivery tests. It does
not run dependency unit suites or credit vendor classes as App coverage. Binding conformance remains with
the binding; App conformance proves its composed contract. These are distinct subjects even when they use
the same upstream vectors.

At adoption, classify each source test as a complete transfer, a mixed-suite split or retained host evidence.
Delete transferred implementation tests with the corresponding old classes. For mixed suites, list the
exact moved methods and retained assertions in the handoff. The governance gates now check ledger
`removed_tests` paths are absent and `retained_tests` paths are actual PHP files under `tests/`, rejecting
duplicates, contradictory ownership, path traversal and symlinked evidence. Exact App test-file removals
named by the released handoff must also appear in the ledger; emptying the list cannot bypass removal.
Handoff prose describing method-level splits still requires review. When a retained host test is renamed later, update
its ledger path in the same reviewed change. This guard checks App files without installing or executing a
package's development test dependencies.

---

## 7. Evidence paths

```
docs/architecture/migrations/
  KUMWE-MIG-YYYY-NNN.yaml                         ledger record, one per adopted migration
  change-sets/KUMWE-CS-YYYY-NNN.yaml              cross-repository state
  conflicts/KUMWE-CONFLICT-YYYY-NNN.yaml          semantic three-way resolutions
  trains/<id>.yaml                                integration trains, one per Phase 2 PR
  evidence/<MIG>/RELEASE-ATTESTATION.yaml         status: verified
  evidence/<MIG>/RELEASE-VERIFICATION-FAILED.yaml status: failed, known_gaps non-empty
  evidence/<MIG>/ENGINE-CANDIDATE-ATTESTATION.yaml where a native Engine candidate is involved
  evidence/legacy/<package>/VERIFIED-LEGACY-RELEASE.yaml
```

`<MIG>` is the ledger identifier, `KUMWE-MIG-YYYY-NNN`. Layout and merge rules:
[`../migrations/README.md`](../migrations/README.md).

---

## 8. The YAML subset

Every YAML record is parsed by a strict reader that accepts exactly this and refuses everything else with
the offending line: UTF-8; two-space indentation, no tabs; `#` comments; block mappings `key: value` with
keys `[A-Za-z0-9_./-]+`; block sequences `- value` and `- key: value`; scalars `null`/`~`/empty, `true`,
`false`, integers, plain strings, double-quoted strings with `\"`, `\\` and `\n`, single-quoted strings with
`''`; empty flow collections `[]` and `{}`. No multi-line scalars (`|`, `>`), anchors, aliases, tags, flow
collections with content or multiple documents. Front matter is `---`, YAML, `---`, body. A value longer
than a 120-column line must be shortened, not folded.

---

## 9. Commands

```bash
composer kumwe:capability-index         # regenerate build/capability-index/ and the markdown
composer kumwe:capability-index-check   # refuse a stale index; in composer qa and CI
composer kumwe:core-growth-check        # refuse duplicate owners, reintroductions, unrecorded growth
composer kumwe:core-growth-record       # re-record core-growth-baseline.json when the check permits
php tools/generate-capability-index.php --digest   # print the current index digest
```

Both checks run in `composer qa` immediately after `studio:dependencies`, in the CI preflight step that
verifies recorded baselines, and in the quality job. They read files only; nothing here runs at runtime.
