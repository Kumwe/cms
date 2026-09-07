# Changelog

Everything this programme has **finished** is recorded here. Its durable objectives, gates and package
contracts live in [`docs/roadmap/README.md`](docs/roadmap/README.md); its **still-to-do** identifiers live in
the [`STATUS.md`](docs/roadmap/STATUS.md) open-work table and open findings ledger. When planned work
completes, its live-index entry leaves those indexes and arrives here in the same pull request, while its
normative package definition remains. If you want to know what comes next, read STATUS; if you want to know
what already shipped, read this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Entries cite the commits that carried them. Version 2.0.0 is not released, so everything below sits under
`Unreleased`; the release simply renames that heading and opens a new one.

---

## [Unreleased]

Kumwe 2.0 built from scratch: a content management system and a business application platform served by one
set of application services, one composition root, one authoritative relational transaction, and one
authorization decision in front of every read and every write. This block covers the whole of the 2.0
development programme, from the architecture decision that opened it to the current head of `master`.

> **Pre-stable extension ownership reset.** The extension manifest, package, runtime and author-facing SPI
> contracts now belong directly to `kumwe/extension-sdk`; the App no longer owns or loads historical
> `Kumwe\\App\\Extension` copies. This deliberately changes the self-checksums of
> `20260805040000_token_and_trust_lifecycles`, `20260809010000_business_security_portal`,
> `20260816010000_resource_ownership_scope`, `20260817010000_interface_message_overrides`,
> `20260821010000_period_posting_lock`, and `20260824020000_studio_host_sessions`. Development installations
> created before the 2.0 alpha baseline must reinstall from a clean schema and establish a new migration
> ledger; there is no alias, remapping layer, historical-checksum allowlist, or compatibility loader.

### Added

- **2026-09-07 — Extraction audit and next independent batch (`NRM-2026-008`).** Reviewed all 104 Version 2
  documents, the complete ERP roadmap and current governance rulings; recorded gaps in the four started
  packages and the Localization/Contribution extraction boundaries. Follow-up review inventories all 38
  repositories, package test ownership across all eleven existing PHP packages and the Studio/Dart families,
  fresh publication failures, and Access Control, Business Definition and native Engine draft PRs. Package
  fixes, release verification and App test-removal enforcement remain separate PRs and phases. App runtime,
  dependencies and roadmap acceptance are unchanged. (#135)
- **2026-09-02 — The Core Growth gate completes `NRM-2026-001`: App production growth is recorded and
  duplicates no installed Kumwe package.** `composer kumwe:core-growth-check` scans every class-like under
  `src/`, classifies it through `docs/architecture/layers.json` by the same first-segment rule the dependency
  gate enforces, digests its public surface and compares the inventory with
  `docs/architecture/governance/core-growth-baseline.json`, the capability index, the migration ledger and the
  Core Growth Records. It refuses a stale capability index or baseline, a duplicate FQCN or service owner, a
  reintroduced extracted namespace or removed App symbol, a likely duplicate responsibility of a package
  symbol, new or widened shared, domain or application growth without an approved
  `docs/architecture/core-growth/KUMWE-CGR-YYYY-NNN.md`, unrecorded host-layer adapters and composition, a
  reference to a retired namespace, and a broken record. `composer kumwe:core-growth-record` re-derives the
  baseline and refuses to record a violation. The bootstrap snapshot records the 1,413 existing production
  symbols with no growth claim; the check joins `composer qa`, the quality contract and both CI record steps.
  No runtime behaviour changes. (#131)
- **2026-09-02 — The library-first Core policy is executable: `NRM-2026-001` bootstraps App governance.**
  `AGENTS.md` now carries the policy verbatim and the Capability Reuse Review an agent runs before writing
  anything reusable. The capability index, `docs/architecture/capability-index.md`, is generated from
  `composer.lock` and the installed Kumwe packages' manifests — today three legacy-unmanifested transitional
  entries, `kumwe/conversion`, `kumwe/extension-sdk` and `kumwe/producer`, which cannot satisfy a migration
  release gate — reads only what a release archive ships so a git checkout and a dist zipball of one lock
  agree, and `composer kumwe:capability-index-check` refuses a stale index or digest. Every governance record
  — capability index, Core Growth Record, migration ledger, change set, non-roadmap record, conflict ledger,
  integration train, Engine candidate attestation, release attestation, verified-legacy release, migration
  handoff and the three package manifests — has a versioned JSON Schema and a validated example under
  `docs/architecture/governance/`, the dependency-free classes under `tools/Governance/` read them, and
  `.github/pull_request_template.md` requires the reuse evidence a reviewer can reproduce without chat
  history. The Core Growth gate those documents describe followed in its own pull request. No runtime
  behaviour changes. (#130)
- **2026-09-02 — Contributed job handlers run behind the same per-call trust and boot-generation fence as
  extension routes and Studio preview renderers.** A job implementation is bound once while the runtime
  loads and then executed by the worker for as long as the process lives, so the worker alone could not
  express that the package had been superseded, deactivated or had its signing key revoked afterwards.
  The binding registrar now refuses a job binding without exact signed runtime provenance and wraps every
  contributed handler in `TrustEnforcingJobHandler`, which checks the boot generation before and inside
  the installation-wide lifecycle lock and re-runs package trust against the exact signed runtime entry
  before the delegate runs; the worker's payload validation against the signed schema stays outermost, so
  an undeclared payload member is refused without taking the lock. A refusal quarantines an untrusted
  release and fails the job closed. (#124)
- **2026-09-02 — Phase S package `S-B` is complete: the Studio contract is pinned exactly, replayed and
  verified on every build, and `V2-STU-002` leaves the ledger.** Every acceptance clause of the finding holds
  in the tree. The administrator build declares all eight `@kumwe/studio` packages as exact `0.1.0-beta.3`
  tarballs whose versions and SHA-256 digests `resources/studio-contract/PIN.json` records;
  `npm run check:studio-release` proves eight exact packages, three identical release records, pinned tarball
  bytes and a matching lockfile. The published schema and fixture corpus is vendored at that exact release —
  once, inside the exactly pinned `kumwe/producer` 0.2.0, rather than as a second copy under `tests/` — and
  `composer studio:corpus` binds App's release record and tarball bytes to Producer's typed release, compiles
  Producer's closed schema registry and resolves all 301 testkit members in 14 groups, failing when any of the
  three disagree. `npm run check:studio-corpus` validates 234 positive documents against their schemas, refuses
  60 negative fixtures and replays 60 command, 12 canonical, 2 preview-identity, 8 rich-text and 8 renderer-web
  vectors against the installed packages. `composer studio:dependencies` holds the three Composer pins and eight
  Studio pins to exact immutable coordinates, and `StudioDependencyPinGateTest` proves that a range, branch,
  alias or foreign specifier fails. Host-port vectors replay through App's own Producer ports in the PHP suites
  — media and preview today, artifact and recovery in
  `tests/Integration/Studio/StudioArtifactRecoveryVectorReplayIntegrationTest.php`. The pin is evidence of a
  frozen dependency, not of integrated maturity: Gate B criterion 12 still turns on `V2-STU-005` –
  `V2-STU-007`. (#124)
- **2026-09-02 — The four structural block families render through an App-owned layout renderer behind
  Producer's seam.** Producer renders `studio.core/{section,stack,grid,columns}` as a neutral layout `div`
  with scoped column custom properties; the App's site stylesheet, Studio canvas and published pages key on
  the contract the App owned before the extraction — one classed structural element carrying the closed
  `data-studio-layout` intent, resolved for the requested width in a preview and retained for every bounded
  width in public markup. `StudioLayoutBlockRenderer` now owns that vocabulary and is bound at the
  `core.renderer/layout` seam of `StudioBlockRendererRuntime`, so grids keep their columns, empty sections keep
  their editor placeholder height and measured canvas regions have a size again; the stylesheet returns to its
  class vocabulary, the built assets follow, and the published-content and block-runtime proofs assert the App
  element. Block semantics, wrappers, hidden state, scope and traversal stay Producer's. (#124)
- **P4-B — Bulk persistence mechanics: a document's cost is decided up front, bounded by declaration, and
  visible afterwards.** Four paths in the aggregate document command grew with the collection instead of
  with the work. Entity references resolved per line — every reference field of every line took a
  mutation-fence lock, a definition resolution and its own identity `SELECT`, thousands of avoidable round
  trips at a thousand lines; targets are now locked once, in the order of the fence's own lock key, and
  distinct values resolve in bounded batches through `BusinessRecordReadRepository::identities()`, which
  carries every predicate `identity()` applies so asking for a hundred ids can never see a row asking for
  one would have hidden. Reordering rewrote every surviving line's full column list for one server-derived
  integer — one drag on a thousand-line document issued 1,001 statements; lines that only moved are now
  renumbered by chunked `CASE` statements with the per-line optimistic version check in the predicate, 15
  statements, and the regression test was proven by forcing the old path back. Encoding remade every
  per-field decision per row; the codec now compiles a `RecordColumnEncodingPlan` once per (definition,
  table) pair — `encodeColumns()` delegates through it so the single-row and bulk paths cannot drift — and
  the write repository compiles an `OwnedLineWritePlan` once per command, with value-changed lines
  rewritten through one prepared statement per column shape. Five ceilings are declared and refused rather
  than outgrown: statement parameters and SQL bytes where statements are built, and payload, memory and
  transaction time in `DocumentWriteBudget`, each refusal a `budget` validation violation rolled back
  whole. The declared lock order — own fence, idempotency key, line-type fence, reference fences sorted by
  target handle, sequence rows, header row, line rows, ledger appends — is stated in `applyDocument()` and
  now actually held: the line path sorted reference fences by field handle and the header path not at all,
  so two documents naming the same targets through different fields could deadlock; both now sort on the
  lock key, with the one inversion no command can order away — a cross-definition reference cycle — named
  as engine-detected and retried. The commit exposes validation, lock-wait, write, revision, audit, event
  and total durations through a shared `DocumentCommitTimingRecorder`, armed only by the document command.
  Proven at the service, not the repository: the container's own shared connection is wrapped in place
  with Doctrine's logging decorators, and a thousand-line create stays under one hundred statements,
  within a bounded delta of the hundred-line cost, while amending three lines of a thousand costs
  statements proportional to three — on all three engines through the ordinary integration matrix. The
  scope-index gap this exposed is under the P2-I entry below. (#118)
- **P2-I — Performance harness and deterministic budgets, delivered in full.** The deterministic plan
  generator and workload driver existed; the package's remaining obligations land together. Query-plan
  capture: `docs/quality/hot-plans.json` declares the four statements the interactive workload leans on,
  and the regression suite runs the real operation, captures the SQL the runtime actually compiled with
  its bound parameters, records the engine's own plan under `build/perf/`, and refuses a driving table
  with no indexed access path — deliberately the *path*, not the optimizer's volume-dependent choice. The
  gate failed on its first run: a definition declaring no indexed field compiled a record table with only
  its primary key, leaving every policy-filtered page over it a full scan the moment the installation
  grew. The schema compiler now guarantees a bare scope index whenever nothing else leads with the scope,
  emits nothing new for already-indexed definitions so their installations compile byte-identically, and
  an existing unindexed installation picks the index up when its definition next republishes. Metric
  collection: write amplification is measured the way the capacity contract counts it — physical row
  mutations per logical business transaction, from real row-count deltas across the header, line,
  revision, audit, idempotency, outbox and projection-source tables around exactly one commit; a 100-line
  document is 106 physical rows, a 1000-line document 1,006. Result schema:
  `docs/quality/perf-report.schema.json` is enforced by the harness itself, which refuses to write a
  non-conforming report, and `tests/Unit/Performance` holds the tool and the schema to each other. The
  breakpoint is a mode: `composer perf:breakpoint` ramps the document line axis twice, prices each size by
  interpolating the two declared commit objectives, records where measured p95 first crosses that line,
  fails when the two passes disagree — the exit gate asks for a *stable* report — and runs nightly through
  the quality contract lane; its document names itself a baseline fact, never a capacity claim. And the
  eight deterministic per-change budgets all enforce now: the three that had no enforcement gained it — a
  measured thousand-line memory and payload proof, a declared 120-second fresh-migration runtime budget in
  the lane that migrates from scratch, and byte ceilings for the production images and the release archive
  in `docs/quality/artifact-budget.json`, checked where each artifact is built —
  and `DeterministicBudgetEnforcementTest` pins every budget to its enforcement point by the load-bearing
  string, so an enforcement that quietly disappears fails the architecture suite under the budget's own
  name. (#118)
- **The App consumes the published Studio `0.1.0-rc.1` release-candidate family — all eight packages,
  renderer included.** The atomic re-pin replaces the seven-package alpha.11 bootstrap with the exact
  eight-package family Studio published through its governed promotion pipeline: eight vendored tarballs
  whose bytes match the public registry, a `PIN.json` naming every digest, three byte-identical release
  records, the complete 304-file testkit corpus, and the 47-schema protocol tree. The corpus lane now
  also replays the eight published renderer-web conformance vectors through the package's own
  `runRendererWebVector` runner against the exact installed renderer, and both release verifiers hold the
  family closed at eight names. The 160-key Studio authoring message corpus is synchronized into App
  XLIFF. The App Twig adapter's replay of the renderer-web vectors remains open work in STATUS.

- **Content-model versions now have a first-party Studio Blueprint composition surface.** The
  capability-gated administrator route provisions an exact, deterministic, version-bound Blueprint
  only through a CSRF-protected POST and then opens the pinned alpha.11 Studio runtime on GET. Provisioning
  atomically admits the initial artifact and its separate binding, locks the authorized Content model,
  exact deployable block catalogue and active published theme, and is idempotent under concurrent first
  use. The browser adapter implements the exact model, localization, telemetry, artifact and preview
  envelopes; saves are serialized and revision-rebased, conflicts quarantine mutation, and canonical
  publish/unpublish operations preserve the read-only lifecycle. All six contribution document kinds use
  the owned runtime registry, while the palette and patterns intersect with the immutable artifact lock and
  truthful renderer capability set. The same-origin, scriptless preview slot now has sequenced cancellation,
  validated single-use navigation, exact theme delivery, responsive/RTL layout classes, fragment geometry,
  viewport/scroll/reflow observation and keyboard-equivalent direct manipulation. The coordinated 160-key
  Studio message corpus, compiled en-GB catalogue, split production assets, AP7 protocol vectors,
  architecture and lifecycle tests, declared KIS surface, and accountable-human journey evidence plumbing
  ship with the surface. Published Content records now resolve their exact site/type/version Blueprint
  binding through a reusable schema/model/theme/live-renderer guard, project complete public values without
  fabricating actor authority, and render marker-free safe composition markup through the same themed page,
  navigation, language, canonical, cache and indexing path as legacy Content. No binding, draft and retired
  compositions retain the legacy layout; a configured missing, incompatible or dependency-drifted published
  artifact fails closed. Publication and every public read re-resolve each canonical block's exact live owner,
  revision, integrity lock, renderer, property schema, responsive effective properties, slot/cardinality rules,
  accepted child types and projected Content-field binding; withdrawal or semantic drift cannot turn stored
  JSON into executable output. Public structural blocks preserve the same compact, medium and expanded layout
  intent through a fixed data-attribute vocabulary and bounded media queries in both light and dark themes.
  The browser qualification journey installs and activates the signed manifest-6 fixture, authors an exact
  field-bound extension composition, previews and publishes it, verifies the real marker-free public route,
  proves disabled code fails closed, reactivates it, and returns the Content record to legacy output. Human
  journey execution remains explicit release-qualification work rather than fabricated evidence. (`b456c484`)

- **Studio artifacts and recovery now have a production versioned persistence boundary.** The AP-3
  dispatcher delegates the exact published artifact and recovery HTTP wrappers behind its trusted
  generation fence. Blueprint, content-model and entry artifacts validate against the vendored schemas,
  pass a fail-closed no-markup/style/code/unsafe-URL policy, and retain byte-identical canonical JSON in
  immutable revision history. Save, publish and unpublish require the expected revision; compare-and-set
  conflicts return only the safe current revision and never overwrite. Actor/session/resource/operation/key
  idempotency claims are atomic, reject changed intent, and replay the exact completed result. Recovery
  store/load/discard is actor-, session- and resource-scoped, canonically preserves numeric values, and
  enforces operation, size, write-rate and replay limits. Every successful mutation writes one redacted
  audit event in the same transaction, while replay does not duplicate it and audit failure rolls back the
  artifact or envelope and its claim. Portable DBAL migration `20260824030000_studio_artifact_recovery`,
  all 11 applicable single-operation host vectors, all seven applicable host-sequence vectors, SQLite
  integration proof and the three-engine CI contract complete S-D and remove `V2-STU-004` from the live
  ledger. (`b456c484`)

- **Studio now has an authenticated, replay-resistant unpublished preview host.** The exact released
  `preview.render({payload})` and `preview.cancel({draftDigest})` bindings run behind the common live
  session-generation dispatcher, resolve immutable AP-4 drafts and AP-2-authorized Content bindings, and
  emit the canonical marker payload for the four `studio.core` structures and all nine core-owned field
  blocks. Structural output projects only the closed, viewport-resolved core layout vocabulary through
  deterministic data attributes — including bounded column counts — and refuses malformed, arbitrary, or
  style-bearing attributes. Published pages and preview now share `ContentPageRenderService`, so templates
  and themes cannot drift; unknown blocks remain inert diagnostics and media/resource values never become
  client-supplied URLs. A portable migration persists 60-second grants and independent port/document replay
  ledgers;
  cancellation, supersession, cross-context use, expiry and a second claim all fail closed. The
  authenticated same-origin iframe endpoint rechecks actor, browser session, resource, generation,
  origin, channel, source and sequence, returns no-store content once, and is the only route whose CSP
  permits same-origin framing — while removing style attributes and admitting no inline script/style or
  eval. All four preview/host-sequence corpus vectors replay from JSON, distinct refusal tests cover both
  endpoints, bounded structured activity excludes draft and transport secrets, and the Security workflow
  publishes the negative-path suite as P7-C JUnit evidence. ADR 0017 records the boundary and explicitly
  leaves three-engine, built-browser and independent qualification evidence to Gate B rather than claiming
  it from local SQLite. (`b456c484`)

- **Studio host sessions now have a production trusted-identity, policy, and generation boundary.**
  The administrator opens an opaque Content or Blueprint context under one of the five canonical modes;
  actor, site, organization, workspace, surface, and browser-session identity come only from the
  authenticated App request and are rechecked on every call. Effective permissions resolve through the
  audited deny-by-default gateway, with canonical `permission.explain` and `permission.refresh` results
  and no policy-detail disclosure. One normative dispatcher validates the pinned envelope and protocol
  and fences all 24 registered host operations: a grant, capability, security-epoch, membership, session,
  mode, permission, resource-context, or host-capability change returns `invalid-request` with
  `studio.host/stale-session-generation` before any later port runs. The CSRF-protected routes, opaque
  binding repository, replay-safe capability migration, all five relevant vendored permission/envelope
  vectors, adversarial cross-scope tests, and ADR 0014 complete S-C and remove `V2-STU-003` from the live
  ledger without implementing S-D write behavior. (`b456c484`)

- **The App has a read-only, fail-closed Studio Content projection foundation.** Canonical JSON and
  schema-profile interpretation now come directly from the reusable Producer dependency. Authorized Content
  models and entries project to schema-valid Studio documents with reversible identity, version,
  locale, workflow, status, recursive-field and exact-string mappings; unsupported or lossy shapes
  stop with typed non-disclosing diagnostics, and field policy can omit both descriptions and values.
  Optional, tenant-bound Blueprint coordinates and canonical per-entry composition overrides have
  read-only domain, repository and replay-safe migration foundations without opening a Studio session
  or Content write path. Content and BusinessRecord remain parallel contexts, the latter adapter is
  explicitly deferred to its own policy and exact-value contract, and `V2-STU-002` remains open for
  the separate Studio release-pin and corpus-replay evidence. (`b456c484`)

- **The delivery pipeline is immune to the two failure classes the first rebase-merge surfaced.**
  A GitHub API outage can no longer fail the dependency-fetching workflows: the production image
  builds seed the runner's lock-keyed Composer cache into the vendor stage through an optional
  `composer-cache` build context, and the development-compose install shares the same cache with
  the bounded retry the image build already had — on a warm key every locked dist archive is a
  local read (the `V2-REL-002` exposure, narrowed). A rebase can no longer dangle changelog
  evidence: `roadmap:check` accepts a merged pull-request citation as the merge-stable form while
  still refusing any commit hash that does not resolve unambiguously from HEAD, with a lifecycle
  probe pinning both properties. And the gating workflows carry the `merge_group` trigger, so
  enabling the repository's merge queue runs the complete merge-lane evidence set against the
  rebased candidate before master moves. (#109)
- **Package P3-D is complete: no Domain type imports an Application type, and the mechanism is
  recorded in ADR 0012.** The fifteen `V2-ARC-003` Domain-to-Application edges are reconciled
  without changing one byte of any frozen artifact: `SiteContext` and `AuthenticatedSurface` are
  classified shared and `ContributionOwner` and `ContributionDefinition` domain via exact
  class-name prefixes in the layer graph (a published byte-immutable migration and the hash-pinned
  public SPI fixture rule out physical moves); `PortalContext` moves beside its resolvers in
  `Portal\Application`, where its pairing of a site with a versioned membership proof belongs; and
  the manifest's admission-time contribution parse becomes the one decision-approved exact
  interface, encoded in the dependency baseline's new `approved_interfaces` section that the
  checker validates without expiry but with delete-when-stale hygiene. The checker also stops
  recording a file's own namespace declaration as a dependency, a latent false self-edge that
  class-level classification exposed. Thirteen baseline entries are deleted, the exemption count
  falls from 91 to 76 with none in the Domain-to-Application family, and phase 3 is delivered.
  (`cc872c3a`)
- **Package P4-C is complete: the numbering machinery's remaining demands are proven on the hot
  paths, and the transition model is recorded.** ADR 0011 pins that the create command is the
  platform's numbering transition — deriving from ADR 0008's accepted no-second-entry-point
  constraint — that gapless is the single declared gap policy, and that un-numbered authoring is
  modelled by the client-reference pattern rather than a provisional number.
  `BusinessNumberSequenceHotPathIntegrationTest`, green on MariaDB and PostgreSQL, proves the three
  demands that had no test: a thousand-line sequenced posting holds its counter for a bounded
  statement span (a warm allocation costs at most three statements and the collection writes inside
  the pinned twelve-statement budget) and provably releases it at commit; one hot counter sustains
  a forty-create contiguous run through the real command across two interleaved kernels; and two
  legal-entity sites number independently end to end, one site's held counter provably unable to
  delay the other's commit. The capacity harness gains the `hot_sequence_commit` operation class
  under capacity-contract amendment 1.2.0 — sustained creates serialized on one gapless counter
  row, the single-sequence worst case decision D1 requires the envelope to disclose — and a smoke
  run reports it within objective. Only `P4-B` remains open in phase 4. (`a54f61c2`)
- **Manifest schema 6 with contribution SPI 4 carries canonical Studio documents beside the frozen
  generations.** The generation kumwe/app#104 requires and finding `V2-STU-008` recorded is
  delivered whole. A schema-6 package declares each composition contribution as the exact canonical
  JSON bytes of one published Studio document — block-definition with its complete Draft 2020-12
  `propertySchema`, pattern, field-adapter, inspector, design-vocabulary or migration — validated at
  admission and again at install against the pinned `@kumwe/studio-protocol` release (now vendored
  in the shipped tree under `resources/studio-contract/`) and, for a block's `propertySchema`,
  against the complete `studio.profile/schema-property` grammar delivered at
  [`e92bacb6`](https://github.com/kumwe/app/commit/e92bacb6e1a2fe7c2d3c131f7c9dc1b5201cda44) over
  the corpus pinned at
  [`25c26883`](https://github.com/kumwe/app/commit/25c2688357f22e967511c75e4b8508210d4affe5); one
  invalid artifact rejects the owning contribution atomically. Renderer bindings, authority and
  host references live in the separate bounded `host_bindings` section, never inside the portable
  document. The canonical SDK manifest graph is the single declaration authority; provider code can
  bind only the exact executable renderer identifiers required by that signed graph and cannot submit
  another document. Manifest 6 accepts only SPI 4,
  earlier manifests refuse SPI 4. The pre-stable App-owned generation fixtures and schema-5 translation
  adapter were deliberately removed during the canonical SDK reset; affected pre-alpha installations
  must reinstall and rebaseline against the package-owned contract; no prior App-owned execution path
  remains. The signed
  `kumwe/contract-manifest-six` fixture declares every canonical kind and passes the complete
  install, activate, upgrade, disable, reactivate and uninstall lifecycle, and
  `classification.json` and `generations.json` record the generation with recomputed surface
  digests: 6 manifest generations, 4 SPI generations and, in the App-owned classification of that
  commit, 117 classified public types. That classification has since moved to `kumwe/extension-sdk`;
  at the pinned 0.2.4 it records 182 public types — 117 classes, 43 interfaces and 22 enums across its
  five public namespaces — beside the same six manifest and four SPI generations.

- **The Studio schema-property profile has its independent PHP implementation.** Under
  `src/Extension/Domain/Internal/StudioProfile/`, `CanonicalJson` produces the portability
  contract's canonical UTF-8 form — code-unit member ordering, minimal ECMA-404 escaping, the
  deterministic ECMAScript number grammar, refusal of over-deep nesting and forbidden member
  names — and `SchemaPropertyProfile` admits contributed block property schemas under the complete
  `studio.profile/schema-property` grammar: the closed keyword set, every published complexity
  ceiling measured on canonical bytes before untrusted maps are sorted, the portable local-reference
  grammar with position-typed resolution, strongly-connected-component recursion refusal, the closed
  object root, and first-diagnostic precedence in the published path order. The admitted schema is
  interpreted by `SchemaPropertyValidator` — eval-free, memoized per instance location, with sorted
  name-array checks and exact base-10 `multipleOf` comparison using string arithmetic rather than
  binary division. Conformance is proven the way issue kumwe/app#104 demands: the committed test
  replays all 12 canonical vectors byte-for-byte and all 62 schema-profile vectors of the pinned
  corpus — rejection codes, schema pointers, instance verdicts and first diagnostics — and pins the
  implementation's limits to `$defs/limits` in the vendored meta-schema (`V2-STU-008` progress
  toward the manifest 6 / SPI 4 generation).

- **The capacity contract has its first measuring instrument.** `tools/perf-harness.php` is the
  `P2-I` seed: a deterministic plan generator (one seed always derives one plan, held to that by a
  unit test) and a workload driver that boots the same kernel the integration suite uses and
  measures the contract's interactive operation classes through the real `BusinessRecordService` —
  bounded primary-key reads, policy-filtered page browses, ordinary small mutations, and 100- and
  1000-line document commits — reporting p50/p95/p99, coefficient of variation and per-class SLO
  verdicts to `build/perf/report.json` in the contract's own vocabulary, bound to commit, engine,
  seed and sample counts. `composer perf:plan` and `composer perf:run` are the entry points. The
  first recorded characterisation run measured every class within its objective with variation
  inside the contract's 0.10 budget. The report's limitations block names what the seed does not
  yet measure — concurrency and contention, write amplification, breakpoints and plan capture —
  which are the remaining `P2-I` stages.

- **The Studio contract is consumed at one exact released pin and digest-verified on every build.**
  Producer owns the complete released schema and testkit corpus; App's `PIN.json` records the exact
  coordinated release and checksums of the eight npm tarballs its browser build consumes. The
  `composer studio:corpus` gate — a member of `composer qa`, the quality contract and the CI quality
  and preflight jobs — compiles Producer's closed registry, resolves every released testkit member,
  and binds the App release record and tarballs to Producer's typed release. Composition work therefore
  builds against frozen dependency bytes without copying a second contract tree (S-B progress toward
  `V2-STU-002`, decision D16). Finding `V2-STU-008` records the manifest-6 / SPI-4 canonical-generation
  requirement from kumwe/app#104, and the Studio integration input document names `PIN.json` as the
  version authority for App's packaged browser dependencies.

- **One vendor-neutral environment bootstrap provisions every agent sandbox.**
  `tools/agent-setup.sh` is the single setup entry point for any coding agent or human
  in any sandbox: it deepens a shallow clone, installs Composer and npm dependencies with
  graceful fallbacks, provisions the CI-identical MariaDB/Redis test lane where the
  platform allows, writes the test environment to `.agent-env`, and ends with a
  capability report naming exactly which verification tiers run locally. Vendor files
  only point at it — the Claude Code cloud SessionStart hook (`.claude/`), the
  devcontainer (`.devcontainer/`), and other platforms' environment setup fields all
  invoke the same script — and `tools/agent-egress.txt` is the one canonical outbound
  allowlist a sandbox needs. `AGENTS.md` section 0 makes running it the first action in
  any fresh environment, so changes are validated locally before they are pushed instead
  of discovered red in CI. Where the sandbox's network policy admits the PHP package source,
  the bootstrap installs the real PHP 8.5 with the `pcov` coverage driver and selects it,
  closing the last platform delta between a sandbox and CI; where it cannot, it degrades to
  the platform override and names the exact egress line that closes the gap. The database lane is prepared to CI parity: the bootstrap pins
  the server to one collation story before the first table exists, installs the immutable
  parent schema, runs the migrations, and `tools/agent-collation-normalize.php` converges
  every utf8mb4 table on the database's default collation — dropping and faithfully
  re-creating the foreign keys that block a conversion — because the parent schema's bare
  `CHARACTER SET utf8mb4` DDL takes each server's own charset default, and a sandbox whose
  default differs from CI's engine image otherwise fails on "Illegal mix of collations".

- **The operator checklist is the one road for changing this repository.** `AGENTS.md` is now the
  checklist any agent or person follows: where code lives, which gate watches a given change, the
  recipes that keep `composer qa` green, and the dual homes that new work must not make worse.
  [`docs/architecture/map.md`](docs/architecture/map.md) is the folder map. `CONTRIBUTING.md` and
  `CLAUDE.md` no longer understate the local lane or point at the removed `docs/product/` path.
  Existing programme contracts, quality manifests, and frozen surfaces are unchanged; this is the
  missing index, not a second constitution.

- **Gate A's executable quality evidence is complete at one exact machine candidate.** Exact candidate
  [`67cf6c02`](https://github.com/kumwe/app/commit/67cf6c02360f8af4220f8bde7c24297854d45dad),
  whose reproducible baseline records measured source
  [`a4ded133`](https://github.com/kumwe/app/commit/a4ded13341d41dfbb2b7f69ff072b077510d2338)
  and whose only tree change from that source is `docs/quality/baseline.json`, passed
  [CI run 32582207163](https://github.com/kumwe/app/actions/runs/32582207163),
  [Nightly run 32582207042](https://github.com/kumwe/app/actions/runs/32582207042),
  [Security run 32582206983](https://github.com/kumwe/app/actions/runs/32582206983) and
  [Development Compose run 32582206967](https://github.com/kumwe/app/actions/runs/32582206967). CI's main
  quality suite passed 2,473 tests / 30,843 assertions and its test-documentation baseline passed 258 tests /
  29,430 assertions. MariaDB LTS, MySQL 8.4 and PostgreSQL 17 each passed a 3,144-test ordinary suite and
  complete 381-test same-database repeat and reverse-class-order passes with zero recorded idempotency
  failures; MariaDB's canonical coverage run measured 15.30% classes, 39.47% methods and 66.90% lines and
  every ratchet held. Process-scoped fixture shutdown withdraws each run's transient definitions and schema
  installations while retaining a bounded explicit replay set, so the third pass proves reuse instead of
  consuming an ever-growing global catalogue. PostgreSQL disables PDO's prepared-statement reuse at the
  connection boundary, and the formerly skipped nine-allocation identity proof now runs beside a minimal
  PDO-only regression. Merge passed 160 desktop/mobile Chromium journeys first attempt on each database;
  Nightly passed 142 Firefox/WebKit journeys first attempt, including all 20 critical journeys and keyboard,
  touch, forced-colour, 200%-zoom and reflow breadth. This record names the immutable workflow subject; the
  later documentation-only commit carrying it is not a new machine-evidence candidate. Closes `V2-QA-004`,
  `V2-QA-007`, `V2-QA-008` and `V2-DB-001`; completes `P2-G` and the Gate A slices of `P2-D` and `P2-E`, and
  completes Gate A's machine criterion 5. All thirteen executable criteria are met and Gate A was accepted
  by the product owner on 2026-08-22; [ADR 0010](docs/roadmap/decisions/0010-gate-a-assessment.md) records the
  decision. (#102)
- **REST, CLI and MCP are retained machine contracts rather than generated documentation snapshots.** Each
  surface now owns an immutable generation with bounded inputs, schemas, stable errors, risk and intentional
  exclusions; incompatible changes require an additive successor and generators refuse to replace retained
  bytes. Executable parity walks the live router, console application and MCP catalogue in both directions.
  Extension manifest key inventories are derived from the parser authority and compared with every frozen
  generation, so a parser change cannot silently widen old grammar. Closes `V2-DOC-003` and completes `P0-C`
  and `P2-F`. (`798f896b`; #102)
- **The last enterprise-document follow-ups preserve their invariants under indirect mutation.** A definition's
  immutable catalogue coordinate now owns a non-site sequence's identity, so widening or moving ownership
  cannot restart its counter. A hard-delete `set_null` sweep evaluates the posting period of every referencing
  source record; one closed source refuses and rolls back the target deletion and every induced version/audit
  write atomically. Closes `V2-ERP-008` and `V2-ERP-009`. (`798f896b`; #102)
- **The right-to-left gate covers every stylesheet the production build consumes.** Direction verification now
  follows the Vite input set, the formerly physical portal and administrator declarations use logical
  properties, and corrected `he`/`ar` baselines prove the brand, borders and spacing resolve on the proper
  inline side. Closes `V2-LNG-013`. (`798f896b`; #102)
- **The roadmap is the sole live programme authority.** The executed gap matrix is retained explicitly as
  historical evidence, while current sequencing, open findings and gate state live only under
  `docs/roadmap/`; the changelog remains the completed-work authority. The roadmap verifier accepts a ready
  Gate A summary only when all thirteen criteria are met, rejects completion vocabulary in either live open
  index, checks both package and finding cells for lifecycle consistency, and distinguishes decimal
  workflow-run identifiers from hexadecimal commit citations. Closes `V2-DOC-001`. (`0bdbdcfe`,
  `a4ded133`; #102)
- **Resident extension code is withdrawn and fenced with its signed generation.** At commit
  [`798f896b`](https://github.com/kumwe/app/commit/798f896b55da76f19cb4d01aee05cf74196bb44b),
  disable, quarantine,
  emergency trust revocation and runtime rematerialization withdraw providers, contribution registries,
  theme/view/template paths and catalogues from the resident graph while preserving published definitions
  and extension-owned data. Every execution entry checks the live generation authority fail closed, so a
  superseded synchronous listener cannot enter extension code, and recovery composition exposes neither
  extension PHP nor an extension template namespace. Lifecycle integration and registry/generation unit
  evidence complete `P1-F`. (#102)
- **The transaction and aggregate seams required by Gate A are extracted and proved.** Candidate
  [`798f896b`](https://github.com/kumwe/app/commit/798f896b55da76f19cb4d01aee05cf74196bb44b)
  proved `TransactionBoundaryEngineIntegrationTest` across commit, rollback, translated retryable and
  non-retryable failures, nesting, audit and outbox atomicity on MariaDB, MySQL and PostgreSQL. The stable
  `BusinessRecordService` facade now delegates typed relationship/owned-line decisions to
  `BusinessRecordRelationshipCoordinator` and coherent revision/audit/event publication to
  `BusinessRecordMutationPublication`, while retaining the one authoritative transaction and all public
  surface behavior. Commit
  [`8455b376`](https://github.com/kumwe/app/commit/8455b3769f166077e492a415e888b3c510c74fbd)
  removes the facade's final duplicate portal/reference-policy copies so the relationship seam is the single
  decision owner; exact machine candidate
  [`67cf6c02`](https://github.com/kumwe/app/commit/67cf6c02360f8af4220f8bde7c24297854d45dad)
  passed the focused refusal/publication proof and complete gates with that final shape. Completes `P3-A` and
  `P3-E`. (#102)
- **The business-group ownership contract has its complete three-engine proof.** At commit
  [`798f896b`](https://github.com/kumwe/app/commit/798f896b55da76f19cb4d01aee05cf74196bb44b),
  a four-business fixture
  exercises migration replay, contained and overlapping group visibility, unchanged site isolation,
  site-only resource refusal, member disablement, audited and reversible widening, guarded narrowing,
  exact consolidated reporting and the no-extra-query authorization path on MariaDB, MySQL and PostgreSQL.
  Completes `P3-F`. (#102)
- **Gate A's three-engine regression criterion has exact machine-candidate and released-artifact evidence.**
  Candidate [`67cf6c02`](https://github.com/kumwe/app/commit/67cf6c02360f8af4220f8bde7c24297854d45dad)
  passed exact-candidate CI, Nightly, Security and Development Compose. Released-evidence commit
  [`2adb2ebe`](https://github.com/kumwe/app/commit/2adb2ebe0cfa95a1aa2953db944479aaa65c30a7)
  passed the complete quality, MariaDB LTS, MySQL 8.4, PostgreSQL 17, browser, deployment, security and
  Development Compose workflows. Continuous-release run
  [run 32472051532](https://github.com/kumwe/app/actions/runs/32472051532) cut
  [`v2.0.0-alpha.4`](https://github.com/kumwe/app/releases/tag/v2.0.0-alpha.4), and release run
  [run 32472065990](https://github.com/kumwe/app/actions/runs/32472065990) built, signed/attested and published
  the Composer-project archive, checksums, SBOMs and signed checksum bundle from those exact bytes. This is
  the retained released-artifact evidence Gate A criterion 12 requires. The alpha label does not turn Gate A
  into a production-release claim; Gate A acceptance is recorded separately in ADR 0010.
- **The documentation rule for `tests/` is enforced, not merely written down.** `docs/coding-standard.md`
  has asked for a documentation block on every test class and test method since it was written, and
  `composer docs:api` scanned `src/` only, so the rule had never once been checked. `composer docs:tests`
  now holds `tests/` to every rule the verifier has — a missing block on a class or a method, a block with
  no summary, a missing `@since`, `@param` or `@return` — against a record of 3,829 entries that only ever
  shrinks. Line width is the one rule left out, because `phpcs.xml` already holds `tests/` to the same 120
  characters. Entries are keyed by file, code and **class-qualified** member: a test file may declare
  several classes, so a bare method name is not unique inside one, and a record keyed on the name alone
  hands every collision a free pass. Anonymous classes carry an identity of their own rather than
  borrowing the enclosing class's, and a parameter finding names its parameter, because one method can
  owe several. A violation the record does not carry fails; an entry that no longer matches anything fails
  and must be deleted; and the record is refused outright when an entry lacks an owner, the finding that
  removes it, a justification or a well-formed expiry, when two entries share one key, or when the
  declared count disagrees with the entries — so the count is a burn-down rather than a number that can
  be edited down. Seven cases in `tests/Unit/DocumentationBaseline` pin each of those promises, including
  the collision itself. Two parser defects surfaced on the way and are fixed: `const array NAME` was read
  as having no name at all, and the line-width fallback for a missing `ext-mbstring` counted bytes, which
  would have failed compliant lines carrying em-dashes. Progress on `V2-QA-010`. (#95)
- **`composer roadmap:check` reads `docs/roadmap/STATUS.md`.** The page states the same facts three times
  — a phase board, a table of open work packages, and the Gate A criteria — and nothing checked that the
  three agreed. A phase may no longer open by calling itself delivered while its packages are still listed
  as open, a package may not be recorded as open and complete in the same row, and the gate may not read
  ready while its own criteria table carries an unmet criterion. All three had happened. (#95)
- **Every user-facing string is looked up, and a gate keeps it that way.** The forty-eight remaining
  templates, all forty-eight console commands and the user-facing error paths of `src/` now resolve their
  wording from the message catalogue, which grew from 117 messages to 2,099 with the pending-extraction
  register empty. The translator binds into the console once, through the output surface every command
  already receives, rather than through forty-eight constructors. `composer translation:strings` now scans
  console output and error paths beside the templates — 79 templates enforced, 1,355 source files checked,
  18 exemptions each naming its category and reason — so a stable machine error code, an audit action name,
  a log line and a developer exception stay inline by rule rather than by luck. Closes `V2-LNG-001`,
  `V2-LNG-007` and `V2-LNG-008`. (#92)
- **The composition contribution contract is frozen before the surface that consumes it exists.** An
  extension declares a block with its bounded property schema, slots and renderer binding, a pattern, an
  inspector or field control, design vocabulary including size roles, and a composition migration — nine
  public types in one additive generation (`manifest-5`, SPI 3) beside the four frozen manifest generations,
  which do not move. Declarations are validated at admission and at install: an unbounded property schema, a
  renderer naming another namespace, a pattern referencing an undeclared block, a migration targeting a
  revision a block never reached are all refused before any runtime exists. A signed compatibility fixture
  declaring every kind installs, activates, upgrades, disables, reactivates and uninstalls to its declared
  contract. Extension authors get their stable surface now; the Gate B runtime consumes it later. Closes
  `V2-STU-001` and meets Gate A criterion 13. (#92)
- **Extension-contributed content items bind to their declared translation sets.** An additive,
  generation-one association links a package owner and declared set to each contributed item, closed at both
  ends: the owner must be a real package and the set identifier must sit inside that owner's namespace, so
  another package's set cannot be spelled, let alone claimed. The runtime group is derived rather than
  allocated — one name-based UUID per generation, site, owner and set — so the association needs no second
  storage surface and resolves identically across restarts and reinstalls. Core resolves it before storage,
  enforces the declaration's locale and fallback bounds, and refuses an undeclared locale, an inactive set
  or a foreign owner. A signed fixture installs a provider, stores two variants through the public
  application path, renders both through real locale negotiation, and proves every refusal. Closes
  `V2-LNG-012`. (#92)
- **Right-to-left has the visual baselines its axis was built for.** Twelve committed screenshots across the
  four `he` and `ar` projects, each surface additionally asserted for zero horizontal overflow and for every
  critical control being visible and keyboard-focusable. Closes `V2-LNG-009`. (#92)


- **An approved document can no longer be edited — it is corrected by a linked reversal.** A workflow
  binding may declare `immutable_states`; entering one closes the record, and every mutation of its fields
  and owned lines refuses on every surface with the stable error `business_record.immutable` and its own
  409 problem type, while workflow transitions still move the state machine. Correction is a new record of
  the same definition carrying the typed `reversal` relationship to the one it corrects, committing through
  the existing aggregate document command with its own approval path; the original is never rewritten and
  never suppressed, and both directions of the link are declared queries. An architecture test enumerates
  every write site in `BusinessRecordService` and fails the build on an unclassified new one, so a future
  write path cannot bypass the rule. Definitions that declare nothing are untouched. Implements
  [ADR 0003](docs/roadmap/decisions/0003-immutable-correction-by-reversal.md). Closes `V2-ERP-005`. (#90)
- **A declarative posting-period lock, with no fiscal calendar in core.** A definition names which of its
  date fields is the posting date; closed ranges per site (with optional organization scope, the narrower
  winning) refuse every mutation of a record dated inside them — including creation, so backdating by
  omission is closed — with the stable error `business_record.posting_period_closed` and its own problem
  type, evaluated before the mutation fence so the refusal costs no lock. What a period is, when it closes,
  who closes it and what re-opening means stay with the extension, expressed through capability-gated,
  audited close and re-open commands on the console and the management API. Pure workflow transitions stay
  open on closed-period records, custom actions are guarded, and a correction issued after its original's
  period closed succeeds because of its date in an open period — neither primitive special-cases the other,
  and a test proves it. Closes `V2-ERP-003`. (#90)
- **The number-sequence identity proven, policed at publication, and given a fiscal-period reset.** The
  counter identity was already the five coordinates site, definition, field, scope key and period key —
  document type and legal entity included — so instead of widening it, the wave proves each coordinate
  isolates its own contiguous run, certifies with the sequence-identity migration that every existing counter
  maps forward identity-intact while any malformed row fails the upgrade loudly, and refuses at publication
  the per-organization sequence on a definition with no organization dimension that previously published
  and then threw on first create. The reset vocabulary gains `fiscal-period`: the period key becomes the
  stable key of the declared posting period containing the record's posting date, resolved through the
  period calendar; a fiscal reset without a posting-date declaration refuses at publication, and a posting
  date no declared period contains refuses at allocation with `business_record.posting_period_undeclared`
  and no number burned. The existing gapless concurrency tests ran unmodified. Closes `V2-ERP-002`. (#90)
- **Numbering under disconnection is decided and implemented: allocation at synchronisation time.**
  [ADR 0008](docs/roadmap/decisions/0008-numbering-under-disconnection.md) records the product owner's
  choice and rejects per-terminal reserved blocks because they forfeit the shipped gapless guarantee. An
  offline terminal carries its client reference — an ordinary unique, immutable-after-create declared
  field — and the human document number is allocated by the receiving command at synchronisation time by
  the unchanged allocator. A duplicate sync refuses with the unique conflict and burns no number; the next
  document still takes the next contiguous value. Closes `V2-POS-002`. (#90)
- **The integration suite's idempotency record is empty, and the reverse-order pass has finally run.** The
  six recorded non-idempotent tests are fixed at their demonstrated mechanisms — fixed idempotency keys and
  fixed definition identities now minted per run, and the Redis outage drill scoped to the installation's
  own namespace and rolled back — so the repeat pass is judged against an empty record, and anything new
  fails as new. The corrected reverse-order pass, never before executed in a form that measured the stated
  property, ran green: the full integration suite in reversed class order against the emptied record.
  Shrinks `V2-QA-004` to enforcing the reverse pass in CI on all three engines. (#90)


- **The administrator and portal home pages are now access-aware dashboards instead of fixed content
  pages.** One `DashboardComposer` projects the navigation that the existing contribution registries have
  already filtered for owner, extension trust and lifecycle, delivery area and actor capability into
  selectable workflow widgets and quick links. Extensions therefore appear by contributing their ordinary
  owned KIS surface and navigation item; no parallel widget registry or new frozen extension SPI was added.
  Typed core summary, activity and access-context widgets use the same bounded semantic view contract, and
  an administrator without `content.read` no longer receives irrelevant content queries or an empty
  content-centric page. The existing `dashboard-cards` and `navigation-shortcuts` KIS preferences now drive
  both dashboards: canonical identity roles are projected read-only as `role:<uuid>` access groups, multiple
  assigned group lists form a deterministic union, and a personal list replaces that result. Writes remain
  CSRF-protected, capability-bound, compare-and-swap audited mutations with a reset path and no JavaScript
  dependency; stored values contain identifiers and order, never markup or URLs. The shared translated Twig
  contract, responsive widget grid and visible content-search control repair the broken shortcut selection
  and unreadable search presentation while keeping templates free of policy decisions. [KIS decision
  0001](docs/interface-standard/decisions/0001-dashboard-customization-compatibility.md) retains `kis-1.0` by
  correcting the already-documented scope ceiling and keeping every schema-one card identifier readable while
  admitting existing dotted contribution identifiers; roadmap [ADR
  0006](docs/roadmap/decisions/0006-unified-dashboard-composition.md) records why the dashboard composes that
  unified catalogue instead of adding a parallel extension SPI. Closes `V2-ERP-006`. (`34da274`, `ee6bfda`, `7e8d460`)
- **One quality contract, and every lane held to it.** What this repository checks used to be written in
  four places: `composer qa` carried a hand-assembled list, the merge workflow reassembled its own sequence
  and left out the interface programme, the roadmap ledger and the documentation gate, the release job
  carried a third and shorter list of four checks out of thirteen, and two security commands were defined
  and excluded with no recorded reason. [`docs/quality/contract.json`](docs/quality/contract.json) is now the
  single definition — every check with its owner, its purpose, the artifact it produces, the lane it runs in,
  the workflow and job that carries it, and a stated reason for every deliberate exclusion. `composer
  quality:contract` closes the loop in both directions: it fails when a check declared for the local lane is
  missing from `qa`, when `qa` runs a check the contract does not declare, when a check names a workflow or
  job that does not exist, when the job declared to carry a command no longer contains it, and when a check
  claims an engine its lane does not run on. Nightly and release execute the contract instead of restating
  it, so neither can quietly shrink again. Adding a gate now means declaring it, and the build says so if you
  forget. Closes `V2-QA-003`. (`b5375b7`)
- **A nightly lane that exists rather than being mentioned.** [`.github/workflows/nightly.yml`](.github/workflows/nightly.yml)
  runs the contract's nightly lane on a schedule and adds the browser breadth a merge budget cannot hold:
  desktop Firefox and desktop WebKit. Both declare `ignoreSnapshots`, and that is deliberate rather than a
  weakening — a pixel baseline belongs to the browser that recorded it, so comparing a Firefox render against
  a Chromium baseline reports font hinting instead of the product. Behaviour and accessibility are asserted
  identically; only the pixel comparison stays with the browser that owns the baselines. (`b5375b7`)
- **A deployed-artifact lane that reproduces the four defects nothing cheaper caught.** Four defects in the
  last programme were found only in production deployment acceptance, after a full deployment had already
  been stood up, and every cheaper job missed all four for one reason: they run under the development
  autoloader, with development dependencies present, in a writable tree. `composer test:artifact` builds what
  a release builds — the exported selection, `composer install --no-dev --classmap-authoritative`, the drill
  directory copied in the way deployment acceptance mounts it, the tree sealed with storage left writable —
  and runs each defect as a regression case inside it. The archive memory ceiling is pinned under the image's
  256 MiB limit rather than a development runner's. The autoloader case asserts both halves in order: the
  production classmap resolves the application and refuses the test namespace, and the shared drill loader
  closes the gap. The drill case executes each entry point and walks the whole class graph it reaches,
  including same-namespace collaborators that carry no `use` statement, which is the shape the class that
  broke actually had. The key case proves a rotation opens what the retired key sealed, that a stranded
  envelope is byte-identical to a readable one so no digest could have caught it, that a missing key is a
  named refusal, and that the rotation reverses through the same supported operation. A case declared and not
  executed fails the lane, because a leg that never ran inside the image is itself one of the four defects.
  The lane needs no database and no containers, so it fails in minutes rather than after a deployment is up.
  Closes `V2-QA-005`. (`9a15c84`)
- **A machine-readable layer graph and a dependency baseline that only shrinks.**
  [`docs/architecture/layers.json`](docs/architecture/layers.json) states which namespace belongs to which
  layer and which layers each may depend on;
  [`docs/architecture/dependency-baseline.json`](docs/architecture/dependency-baseline.json) records the 157
  edges that already pointed the wrong way, each with the finding that removes it, an owner and an expiry.
  (`c72707b`)
- **One package-owned extension contract instead of an App-owned compatibility surface.**
  `kumwe/extension-sdk` now owns the canonical `Kumwe\Extension` classifications, manifest generations,
  signed fixtures, scaffold, build/sign/inspection tools, and their resource PIN. The App verifies those
  installed bytes through `composer extension:contract` and pins one immutable SDK release; it carries no
  translated class-shape fixtures, historical namespace aliases, or second public-API ledger. App lifecycle
  proof consumes the package-owned fixtures directly, while admission policy, authorization, persistence,
  trust enforcement, and signed-manifest interpretation remain host responsibilities.

- **A business-group installation: several businesses on one Kumwe, sharing what they choose to share.**
  A resource's owner is now held at a *level* — one site, a declared group of sites, or the installation —
  rather than always at a single site. Every resource still has exactly one owner, so "who owns this?" keeps
  one answer and every denial and audit entry still names it. Groups are declared, never inferred: an
  operator writes down which sites are in one, groups may overlap freely, and both inclusion and exclusion
  are gated on the installation-wide `sites.group.manage` capability and audited. A group cannot be emptied
  of its last member, because everything it owns would become unreachable. An installation that never
  declares a group behaves exactly as it did before. (`944652a`)
- **Accounting isolation that an operator cannot switch off.** Which ownership levels each kind of record may
  be held at is fixed in the build, not in configuration: accounting documents, ledgers and pay runs are
  site-owned only, while clients, people, price lists and products may be widened to a group. The table
  covers every category this build carries, an undeclared category falls back to isolation, and an extension
  may declare its own once but may not restate a reserved one. An owner is assembled through
  `ResourceOwnership::of()`, which refuses an impermissible pairing at construction, so no code path exists
  that could write one; on the engines that support a table check constraint the row itself refuses to spell
  no owner or two. (`944652a`)
- **Widening and narrowing a record's owner, deliberately asymmetric.** Sharing changes after the fact with
  no rewiring and no data movement — the record stays where it is and only the scope that owns it changes.
  Widening costs an ownership change and an audit entry. Narrowing first proves that nothing in the sites
  about to lose access still refers to the record and **refuses with those sites named** when something does,
  rather than silently orphaning them. Both directions are gated on `ownership.scope.manage`, both are
  audited, and both write through a compare-and-set on the current owner so two operators changing the same
  record produce one change and one refusal. Leaving the installation scope is refused outright, because
  its membership is every site there is and an unbounded guard would answer that nothing is stranded for
  the wrong reason. Extensions contribute their own reference inspectors, so a narrowing is judged against
  every kind of reference the installation actually holds. (`944652a`)
- **Consolidated group reporting as a distinct read capability.** `reports.consolidated.read` is bound to the
  group and to nothing else, so holding it lets a report read across a group's member sites and authorizes no
  write anywhere, in any business, of any kind. Isolation stays at the write layer and unification happens at
  the read layer; no transaction spans sites, and a transfer between two businesses of a group remains two
  transactions coordinated by a durable event. (`944652a`)
- **[Business groups](docs/business-groups.md),** explaining the model to an operator, stating the widening
  and narrowing asymmetry plainly, and telling an extension author the four things to do to make a new record
  category take part. (`944652a`)
- **A business document is written as one thing: a header and its owned lines, in one command.** Nearly
  every demanding business object is a document — an invoice, a purchase order, an attendance batch, a job
  card, a stock movement, a pay run — and every one of them is a header plus the lines that belong to it.
  Until now the platform could store that shape but could not write it: a header plus a hundred lines was a
  hundred and one separate commands, each with its own transaction, version, revision, audit entry and
  event, and an invoice whose header said one total while its lines said another was reachable in between.
  `BusinessRecordService::writeDocument()` writes the whole document at once. There is no instant at which a
  reader sees a header without its lines, or lines whose header has already moved on, and a refusal
  anywhere — a field rule, a stale version, a unique collision on the nine hundredth line — takes the whole
  document with it and leaves no row, no revision, no audit entry and no idempotency claim behind. The
  submitted line list is the collection as it is to end up rather than a set of edits, so a line's slot is
  simply its place in the list: two lines can never claim one position, a caller can never leave a hole, and
  a line's identity is meaningful only inside the document it belongs to. Deleting the header still takes
  the whole collection with it. The single-line relate, unrelate and reorder commands are unchanged and
  remain supported. (`772e523`)
- **A rule may now state something about a whole document, not just about one row.** A definition can say
  that its total equals the sum of its lines, or that its line count stays within a bound, and the platform
  enforces it. That is the most fundamental document rule there is, and until now no definition could
  express it at all — so every extension would have reimplemented it differently and none of them provably.
  The expression vocabulary gains exactly one leaf for this: one owned-line collection the entity declares,
  one reduction from a closed set, one line field. What a definition may declare is settled before it is
  ever published — the collection must exist, the summed field must exist on the line entity, must hold an
  exact number, and must not be one the line keeps restricted or secret — so a rule that could never be
  judged can never be published. The arithmetic stays exact: a thousand decimal line values fold through the
  same exact decimal type the columns store and never through a float, and a total is compared by value
  rather than by how many trailing digits its column happens to spell. The rule is judged once for the
  command over the collection the write is about to store, never once per line, and a violation names the
  rule and carries the definition author's own wording so an operator is told what to fix. Because the rule
  belongs to the document, every command that can break it enforces it: the document write, an ordinary
  header edit, and a single-line link or unlink. (`772e523`)
- **An extension declares all of that without a core edit.** An extension contributes an entity definition
  through the ordinary package path, and if that definition declares a document rule the platform enforces
  it without having heard of the rule, the vertical or the document. Proven by an integration test that
  registers the definition from outside core and watches core refuse a document that breaks it. The
  contract is recorded in
  [ADR 0005](docs/roadmap/decisions/0005-atomic-aggregate-document-contract.md) and documented for
  extension authors in [the business runtime guide](docs/business-runtime.md). (`772e523`)
- **A consolidated programme roadmap with a machine-readable findings ledger.** Six competing plans became
  one authority for sequencing: two gates, ten decisions, an enterprise capacity contract, a per-primitive
  judgement of what an enterprise resource planning system needs against what the code actually provides, and
  an architecture decision record for resource-ownership scope. (`fa86362`)
- **This changelog, and the lifecycle rule that keeps programme state unambiguous.** The STATUS open-work
  table and `findings.json` hold forward-work identifiers only; a completed identifier leaves its live index
  and lands here in the same pull request, while its normative package definition remains in README. The
  rule is stated at the top of the roadmap and beside the pointer in `AGENTS.md`, and enforced by
  `tools/verify-roadmap.php` — `composer roadmap:check`, inside `composer qa`, and again from the architecture
  suite — which fails when `findings.json` carries an entry in state `closed` or the STATUS open-work table
  carries a completion marker, and names what to move where.
  The fifty-six findings already closed at consolidation were moved out of the ledger and into the entries
  that follow.
- **A structured observability contract that the runtime is obliged to honour.** `config/observability.php`
  had declared a JSON format, a level, a required context set and a redaction list that nothing read.
  `ObservabilityContract` is now the single reader of that file, loaded once at composition, and the logger,
  the metric catalogue and the endpoint policy are all built from it — so a declaration the runtime cannot
  honour stops the boot rather than quietly meaning nothing. (`309db88`)
- **JSON logging with nested redaction, correlation, causation and W3C trace context.** Every record carries
  release, runtime surface, derived outcome and the request, correlation and trace identifiers; redaction runs
  last, is key-based, and applies at every nesting level. An attached throwable is reduced to class, file, line
  and a message scrubbed of URI credentials, with no stack trace, because frame arguments carry exactly the
  values the key rule removes. A well-formed `traceparent` joins the log stream to an upstream trace without a
  vendor dependency. (`309db88`)
- **A protected Prometheus metrics endpoint with enforced cardinality.** `GET /metrics`, off by default,
  exposing counters and histograms held in Redis plus twenty-four gauges recomputed from durable rows per
  scrape. Every label value is enumerated and anything else folds to `other`; undeclared labels are dropped and
  a forbidden label fails at boot. There is no path, route, site, user or record label. The endpoint is
  fail-closed in three steps: absent unless enabled, `404` rather than `401` when enabled without a token so a
  misconfigured deployment does not advertise itself, and a constant-time bearer comparison otherwise.
  (`309db88`)
- **Machine-readable alert rules with runbook references.** Each rule names the runbook section that says what
  to do, and states the concrete failure it would have caught. (`309db88`)
- **`KUMWE_LOG_LEVEL`, so log verbosity no longer rides on the debug disclosure flag,** together with direct
  tests for the readiness, liveness and health-command contracts a load balancer and a start-up probe branch
  on. (`309db88`)
- **Real failure and recovery drills that take something away.** A killable relay sits on the network path to
  Redis and to the database and is `SIGKILL`ed, so established connections are reset and reconnection is
  refused — the state a stopped server leaves a client in, which a throwing stub cannot reproduce. A live
  worker holding a real lease on a real job is killed mid-handler and a successor is refused until the dead
  holder's lease expires on the wall clock. A server-side session termination covers failover. A real `SIGALRM`
  aborts a handler that would never return. A restore that has begun publishing targets is killed and re-run.
  (`f8b856e`)
- **A restore completion manifest and a target claim,** so re-running an interrupted restore recovers it with
  no manual cleanup while a target no claim names is refused untouched. (`f8b856e`)
- **A wall-clock deadline on the integration worker,** drawn from four fifths of its dispatch lease rather than
  all of it, so an effect cut short is still recorded under a fence that is still its own instead of racing a
  sibling's re-delivery. (`f8b856e`)
- **Gapless-on-commit business document numbering.** `core.sequence` is a server-allocated field type whose
  declaration is closed by construction — the definition validator refuses one that is not server-only,
  read-only, immutable after create, required and unique, that declares a default or a formula, or that is
  narrower than the widest number its format can render. Its configuration chooses tenancy scope, reset period,
  prefix, padding and the timezone the period boundary is judged in, so `INV-2026-000001` rolls over when the
  business's year does rather than when UTC's does. The allocator opens no transaction of its own: it joins the
  record command's, behind the mutation fence and after the access plan, so the number, the row, the revision
  and the audit entry commit together and a rolled-back command hands its number straight back. (`256888f`)
- **Contention drills that force real cross-connection races on a real engine.** Competing approvers, deadlocks
  built across two operating-system processes, worker termination between an external effect and its fenced
  settlement, and the idempotency first-claim race, each with the interleaving forced rather than hoped for,
  usually by bounding the rival's lock wait so a blocked session says so within a second. (`c0bd0a1`,
  `6e0d2e2`, `8bb8cc4`)
- **A signed bill of materials and a provenance statement inside every extension package.** `extension:build`
  embeds a CycloneDX 1.6 inventory at `kumwe.sbom.json` and a SLSA-shaped statement at `kumwe.provenance.json`
  as ordinary archive entries, so the package digest covers both and the existing detached Ed25519 signature
  vouches for them without a second signature format. Neither document carries a timestamp, so byte
  reproducibility is unchanged and proven so. (`5bf08c2`)
- **Admission-time package scanning.** `PackageAdmissionScanner` runs one bounded pass over the staged snapshot
  between the trust decision and extraction: every entry is digested and reconciled against the bill of
  materials, the provenance statement is bound to that inventory and to the manifest, and the packaged PHP is
  checked by the same conformance rules the SDK runner uses, so `extension:conformance` and admission produce
  identical findings. A package carrying neither attestation installs and records them absent, so packages
  built earlier keep working. (`5bf08c2`)
- **An upstream trust-revocation feed.** An operator pins an issuer origin and Ed25519 public key, and
  `extensions.trust.revocations.synchronize` consumes a signed list with a monotonic sequence and a freshness
  window, emergency-revoking each withdrawn key still trusted locally. The verification key is pinned in
  configuration and never read from the trust store, because the store is what the feed revokes. (`5bf08c2`)
- **A tamper-evident audit trail.** Every `audit_events` row carries a canonical SHA-256 digest of its own
  fields, so a mutated row fails its own recomputation, and a `previous_digest` witness link to whichever row
  was head when it was written, so deleting a row breaks the link that named it. The link is read with a plain
  snapshot select rather than a lock, because chaining under a lock would put every mutating transaction in the
  installation behind one row. (`05ff831`)
- **Monotonic audit positions and a sealed anchor ledger.** The database allocates the position — auto-increment
  on MySQL and MariaDB, an identity column on PostgreSQL — and a scheduled job seals settled position ranges
  into a chain of their own, fixing each range's row count and rolling digest, which is what makes an insertion,
  a deletion or a reordering inside a sealed range evident. Only rows older than a settle window are sealed,
  because a position allocated inside a still-open transaction can commit after a range that already covers it.
  (`05ff831`)
- **`audit:verify` and `audit:export`.** The verifier walks the whole chain and reports the first divergence in
  its exit status, and runs nightly as a job that fails loudly rather than logging. The exporter preserves a
  range as a checksummed, redacted NDJSON archive in private `0600` storage and records the export in the trail
  itself, so incident preservation no longer needs raw database access. (`05ff831`, `1e8bc12`)
- **Audit retention that archives, anchors and only then prunes whole aged anchored ranges,** shipping disabled
  with a zero window so an installation that never configures one keeps its trail forever. (`05ff831`)
- **A record-secret key ring with retired keys and a key-provider port.** One active key that new writes use,
  plus every retired key still needed to open what it sealed. Resolution is by the identifier the envelope has
  always recorded, never by trial, so a key the deployment no longer holds fails as `SecretKeyUnavailable`
  rather than as a decryption error — the difference between "restore the key" and "investigate tampering".
  Key acquisition is the `SecretKeyProvider` port, with the local ring as its production-capable default and the
  guarantees an external KMS or HSM adapter owes written on the port itself. (`a669846`)
- **`business-record-rekey` and the matching background job,** which re-seal stored envelopes under the active
  key. A pass is bounded, so it schedules beside live traffic; resumable without state, because the selection
  predicate is `key_id <> active` and a re-sealed row stops matching it; idempotent; and safe under concurrency,
  since an ordinary write that replaces a secret between the read and the update has already sealed it under the
  active key and the guarded update matches no row. The row's optimistic version is deliberately neither checked
  nor bumped, because re-keying changes no business value and must not manufacture a conflict. (`8706736`)
- **A full credential lifecycle.** A person replaces their own password by re-proving the current one through
  the existing high-impact credential guard, under the throttle that already guards sign-in. An administrator
  resets somebody else's without that proof, but only under `users.manage` on the exact record, with a mandatory
  reason and an audit event whose actor is not its subject — and never on their own account. The console carries
  both forms for runbooks. (`4bc5c74`)
- **Second-factor retirement and recovery-code reissue.** Retiring destroys the unspent recovery digests, keeps
  the consumed ones as evidence, records the reason on the row and lifts the block on re-enrollment. Reissue
  replaces the whole set and accepts an authenticator code alone, because one leaked recovery code must not be
  able to mint ten replacements. (`4bc5c74`)
- **A break-glass console credential-recovery path.** Every in-application reset is step-up-gated, so a total
  lockout has no in-application answer; the answer is the console, where reaching the host is the authorization.
  It acts as a dedicated credential-recovery system identity rather than widening the bootstrap one, takes the
  same locks, advances the same epoch and writes the same audit events as the screen. (`4bc5c74`)
- **`composer security:secrets`,** the same pinned secret scan the security workflow runs, over the branch's
  whole history rather than its working tree, so a literal introduced by an earlier commit is caught before the
  push instead of by CI. It prints each finding with the fingerprint the allowlist takes, and separates leaks
  found from a scanner that never started. (`c9bc5fe`)
- **A `document` view kind for business records.** The view vocabulary gains a sixth kind and an optional typed
  `document` block naming which declared parts play which documentary role: the field carrying the human number,
  labelled groups of meta fields, party relationships, the owned-line collection that becomes the body table,
  and the fields shown as totals. Every role is proven against the owning entity at construction, the block is
  omitted from canonical output when absent so existing published checksums are untouched, and a document view
  may not bind a custom handler. A print stylesheet drops navigation, tabs and actions so browser print yields
  the document alone. (`72126b0`, `45e1963`)
- **Queued CSV export from generated list views.** One deterministic record-set export report is derived per
  installed business definition from its declared exportable scalar fields, and request, authorization, scope
  resolution, policy snapshot, queueing, generation, status, download and audit all flow through the existing
  shared pipeline. The development server now supervises an exports worker beside the HTTP server, restarting it
  whenever a runtime generation change retires it, so a developer clicking export watches it complete.
  (`46c6b6c`)
- **A per-package map of where every extension contribution surfaces.** Administrator screens and reports linked
  at the exact paths the route registries mount them on, portal views below `/portal/extensions`, background
  listeners, consumers, jobs and schedules naming their queue or worker, theme surfaces reporting activation
  state, record and field types linked to their workspaces, and contributed capabilities pointing at the screen
  where somebody must still grant them. The route registries became the single public path authority, so the
  summary keeps no second copy. `extension:install`, `extension:activate` and the demonstration installer print
  the same lines. (`0635614`)
- **The production-qualification gap matrix,** opening the campaign with a threat, control and gap analysis of
  the merged runtime across eight control domains, each gap carrying its severity, evidence, fix shape and
  effort. (`734c62b`)
- **One-command demonstration install.** `demo:install` runs the access cast and the example extensions behind
  one authentication and one execution context. The credentials file keeps an owner-only exclusive-create
  contract and is written only when the run actually generated a new password, so a re-run confirms every
  account and example, reports that existing sign-ins remain valid, and touches no file. Closing output names
  the credentials file, both sign-in surfaces and the migrate-time datasets. (`53fa34c`, `31a45d5`, `d44c7f5`)
- **`demo:export-profile`, which projects a running installation back into an installable profile.** Site
  content, the business dataset with its definition documents in dependency order, and the access cast, all
  walked through the authorized application services rather than the tables, reusing ledgered fixture keys,
  idempotency keys and whole applied operation requests byte for byte while a resource is still at the version
  the installer left it — so an exported installed dataset stays diffable against its source manifest. The
  access export withholds every identity outside the reserved documentation zone and never exports credentials.
  (`ef3f49c`, `af15020`)
- **Selectable demonstration datasets.** Independently selectable documentation, placeholder, blank and business
  demo profiles with durable provenance, upgrade-safe reconciliation and deployment configuration, grown into a
  six-organization commerce graph of products, quotations, invoices, subscriptions and domains with ordered
  owned lines, per-definition record-access modes, and a demonstration staff and portal cast. Acceptance
  evidence pins the dataset counts, so an expansion that forgets to update them fails rather than drifts.
  (`6a9e57f`, `a3c4240`, `9f92a82`, `fc45992`, `31a45d5`, `9bb91c1`, `dda882b`, `abe2d74`, `7e52280`,
  `d4cc2fb`, `8d77f98`, `255ca20`, `7cb48f9`, `6e23c49`, `1749d42`, `bf6eee2`, `9d3f6e5`, `e3c394f`,
  `324c51a`)
- **Six document-driven public content layouts** — document, guide, reference, FAQ, landing and article — each
  with its own closed JSON schema, selected from the record's content type through a layout catalog. Document,
  guide and reference share a sticky section navigation with scroll-spy highlighting; unknown and historical
  types keep the general page layout. (`1f3c42e`, `8f1e602`)
- **Per-menu template and colour-scheme binding.** A menu item may override either for the page it links, both
  validated as handles on the way in and degrading to the defaults when they name a layout or scheme that no
  longer exists, so a stale binding can never fail a page. (`dfb64d2`)
- **Repeatable-group editing in the administrator content editor.** Structured layout fields such as document
  sections, guide steps and reference entries were previously authorable only through the API; the editor now
  renders them as repeatable rows with add-and-remove controls, discovers submitted rows from the body, closes
  the gaps removal leaves, and drops untouched blank rows rather than storing them. (`d87bab3`)
- **A branded site theme example.** A complete installable template package with its own palette, typography,
  layout, page and home surfaces and stylesheet, proving the template override boundary and passing static
  conformance with no violations. The example installer became type-aware: a template package installs so it is
  selectable but is never activated onto the site, because restyling the public site stays an operator decision.
  (`25951aa`, `fb6b41f`)
- **Kumwe Interface Standard 1.0 as an enforced runtime contract,** not a style guide: typed surface
  declarations, a shared server-rendered component and token layer with focused enhancement, durable
  presentation preferences, versioned template compatibility, and template conformance checked for both site and
  administrator packages. A surface declares the earliest interface version it needs and a declaration requiring
  an unsupported version is rejected rather than rendered approximately. (`a2de9ff`, `1954ffd`, `ba1258b`,
  `4c0ee7f`, `1f670c2`, `8e07f48`, `276ee32`, `e5f212c`, `a00a1db`, `4ca2043`)
- **An interface-programme evidence gate,** `composer interface:programme`, dependency-free so surface,
  navigation, template, manifest, generated-definition, actor, journey, phase and evidence coverage stays
  enforceable before Composer dependencies exist, with waivers bound to findings. (`8253bf8`, `a0b40af`,
  `c7b4e9e`, `0b2b380`, `ac47f06`)
- **The business workspaces and the generated business surfaces migrated onto that standard,** with parity
  manifests asserting the migration rather than asserting screenshots, and qualification evidence bound to
  executable test bodies with explicit browser-evidence boundaries instead of narrative checkpoints.
  (`74241a0`, `9c5c669`, `a58411b`, `4fe3715`, `a39bb3a`, `17c3780`, `c214424`, `fb10b0c`, `452a96f`,
  `519b428`, `cb7ccbf`, `b05d82a`, `64d2dfa`, `a8483b0`, `3b9a785`, `233fec9`, `6d4d33a`, `5c52139`,
  `99663fe`, `13972f9`, `4221df9`, `6b2af4f`, `08eb0c7`)
- **Browser interface-integrity diagnostics** run as part of the browser suite. (`116eb17`)
- **Policy-aware generated business surfaces.** List, detail, form, history and relation views generated from a
  published definition, on the administrator and on an opt-in portal, with row, field and action policy applied
  before results, the same operation replay semantics as every other surface, approval and read-denial parity
  across both shells, and mobile choice controls that behave. (`adbffc7`, `c1877d6`, `e836cb7`, `d8e0bcc`,
  `3a968d5`, `6cdef9c`, `4d3494d`, `7e132b9`, `2f9bb65`)
- **Business security and an external portal.** Record-scoped policy, membership-derived portal principals,
  approvals and maker-checker, step-up re-authentication bound to purpose, site, organization, session and
  epoch, and portal isolation proven separately from administrator isolation, documented for operators and
  hardened across all three engines. (`725400d`, `c0870ce`, `ec143ff`, `4a23204`, `1ba19a2`, `fb9e033`,
  `1066ad8`, `70327bf`)
- **Durable integration and reporting contracts** — an outbox with source events and a sequenced journal, an
  inbox with consumer receipts and checkpoints, reports and read projections with bound authorization, and
  exports as queued, stored, checksummed artifacts. (`754572c`, `c79ff8d`, `3204b7b`, `303106e`, `c529a42`,
  `2e993a6`, `e382ead`, `14df3fe`)
- **A trusted integration runtime and an extension SDK,** including a scaffolder that generates a complete
  component package, a conformance runner, and ordered inspection relations materialised through the manifest
  contribution set. (`dafffec`, `0163613`, `625e1b4`, `a83d9be`)
- **A typed, transactional business record runtime.** Optimistic concurrency on every mutation, idempotent
  command replay with `key_reused`, `in_progress` and `corrupt` outcomes, revisions and history, exact-value
  field types, a bounded typed query compiler, and one authoritative transaction enclosing record, revision,
  audit and event effects. (`53bba97`)
- **A deterministic transactional business schema runtime.** Plan, approve, execute, recover and destructive are
  five independently grantable stages; approval binds to the plan checksum the operator inspected, so a plan
  that changed underneath fails instead of being applied. (`accb3b2`, `f79c938`)
- **A typed business definition catalogue** with immutable published definitions, a canonical-JSON checksum,
  twenty-six built-in field types, relationship kinds including relationally stored owned-line collections, and
  administrator screens for the whole lifecycle, with the graphical definition editor rendering new definitions
  safely and reaching its accessibility and keyboard-publication gates. (`b96058c`, `07dcf30`, `1cb29eb`,
  `5333cb0`, `a0b2c05`, `b419af9`, `bfeccb9`, `4b493ef`, `ffc2f7e`, `4b99ac1`, `71d1120`, `e8ab1b3`)
- **Four-surface parity for business definitions and schema plans** — administrator, REST, console and
  model-context tooling all driving the same application services, each schema stage naming only its own
  capability. Two operations are deliberately absent from the machine surface, because composing a destructive
  purge plan and approving a high-impact plan both require re-proving the caller's current password, which an
  agent surface must not be able to satisfy. (`f1c00ea`, `22cf0b3`, `52f3ab6`)
- **Typed extension contribution declarations wired at runtime,** with lifecycle and boundary verification, so a
  contribution kind cannot be discoverable while remaining un-removable on disable or trust revocation.
  (`3bb3bad`, `baa3eda`, `99e21ff`, `2f807a0`, `4b4b35b`, `aecd692`, `bfac85a`, `7710635`, `bdc6f98`,
  `43e7a57`, `fbd43e4`)
- **A graphical administrator experience,** server-rendered with focused enhancement, keyboard-operable
  throughout, responsive on small viewports, and covered by reviewed visual baselines and accessibility gates
  whose data is stabilised so a rerun compares like with like. (`bd13c94`, `080801d`, `78ed736`, `e0586a1`,
  `12f67b5`, `c9e7de3`, `875b424`, `e520645`, `95743d1`, `7996e14`)
- **Database-driven public pages and navigation,** replacing the file-shaped presentation with content,
  navigation and media the administrator owns, including sparse pages, an exact logo locator and portable
  primary-menu ownership validation. (`45e64c1`, `ef2f90b`, `1393f63`, `4189fd6`, `6fc3b3f`, `8ec241c`,
  `ca23b0c`, `9813859`, `b1df9a6`, `bf65ff2`)
- **Repeatable administrator provisioning,** isolated from the extension runtime so the bootstrap cannot depend
  on installed code, and proven for multiple administrators and for the recovery container. (`2144553`,
  `fda2c3e`, `6a1599e`, `48b5660`, `e150cc2`, `229f3fc`, `92da754`, `c914371`)
- **The unified coding standard and its enforcement.** `docs/coding-standard.md` became the single normative
  source for documentation blocks, tag order and alignment, type declarations, naming, structure and dependency
  direction, error handling and test expectations; `AGENTS.md` and `CLAUDE.md` delegate to it rather than
  restating it. (`49d28f7`)
- **Dependency-free documentation-block tooling.** `tools/verify-docblocks.php` fails when a documentable member
  lacks a block, a description, an `@since`, a `@param` or a `@return`, documents a parameter that does not
  exist, or exceeds the line limit; `tools/format-docblocks.php` applies the alignment rules mechanically.
  Neither needs Composer, so both run before `composer install` and inside minimal images, and both refuse to
  touch a file that pins its own bytes with a self-checksum. (`3852208`, `0b1dce1`)
- **A documented public API across the whole codebase** — every class, method, property, class constant and enum
  case carrying a block that says why the member exists, what its parameters mean to it, and under which
  condition it throws. (`4971aeb`, `cc0d884`, `d232743`, `7a035e2`)
- **A single-container application kernel.** One PSR-15 HTTP stack, one console entry point, Doctrine DBAL
  persistence and one composition root, replacing the inherited front controllers. (`65de712`)
- **Identity, authorization and audit as first-class domains,** followed by content, workflow and navigation,
  the secure extension platform, structured presentation and site building, and safe automation planning with
  jobs and schedules. (`c3f77ad`, `e49d722`, `b82fdd0`, `8cd6c97`, `4b0cd40`)
- **Scoped bearer tokens for API routes,** with delegation ceilings that acceptance exercises rather than
  assumes. (`f113028`, `1a4bbd2`, `477d41f`)
- **Programmable delivery surfaces** — REST with a generated OpenAPI document, a console with stable JSON and
  exit codes, and model-context tooling — all over the same application services. (`e719a9b`, `84f7336`)
- **A portable management runtime on three engines.** MariaDB, MySQL and PostgreSQL are all supported, with the
  same migration, recovery and acceptance contract on each, closed out against real engines rather than against
  a single development database. (`bb9206a`, `c723c7e`, `a850cc6`, `8b6c48c`, `bd96d0d`, `330a9b3`, `0286827`,
  `fdf0b88`, `f8dd569`, `2931deb`, `c3cc34e`, `5d75dc2`, `678413e`, `05a44e4`, `ef1ec93`, `1633a5b`, `dec321b`,
  `158e7ea`, `4517a35`, `4147d6c`, `0a61f3f`, `ecd8150`)
- **A quality gate wired into Composer and CI from the first week.** The architecture policy check, the coding
  standard, strict static analysis, deterministic lock validation and committed normalization evidence all run
  as named Composer scripts that CI consumes, rather than as instructions in a document. (`d88f8df`, `d2bcf11`,
  `dfaaa07`, `e83eeba`, `778c6e1`, `3dd3b1b`, `601f936`, `77594f1`, `6a5657f`, `c618741`, `7b0f5d9`, `0e5bcf7`,
  `469ae52`)
- **Production delivery: images, compose topology, release and security workflows, and operator
  documentation,** including deployment acceptance against tagged distribution installs that quiesces the
  system, refreshes its tokens, validates schema execution outcomes and confirms high-impact installation plans
  before it reports success. (`9924989`, `538b6ef`, `c8f916e`, `2ab472d`, `44b7fcd`, `fdd3019`, `f7b4e1b`,
  `e6d7b97`, `c2335bd`, `a81f83e`, `42a139b`)
- **Verified backups with real restore drills,** restoring into a clean database, comparing the recovery
  manifest, and verifying restored schemas semantically rather than by byte comparison, with cross-engine
  recovery and backup acceptance executed on each supported engine. (`72d5012`, `e5bdbe8`, `a2ee79a`,
  `15d3a5f`, `c07242b`, `bb7dd1c`, `273aa7b`, `f15185b`, `48640fe`, `53eb64a`, `5069db3`, `46c27c0`, `e198a79`,
  `079227c`, `f2e312a`)
- **Operator and extension documentation, and a task-focused project guide,** covering architecture, delivery,
  persistence, extensions, automation, administration, operations and the recorded architecture decisions.
  (`0f494eb`, `a37eca9`)
- **A price stored in one currency can be presented in another, and the presented figure says so.** Kumwe now
  owns the money conversion contract: what a conversion asks for, what it returns, and the rules it obeys. A
  converted amount is a different kind of thing from a stored one and cannot be mistaken for it — it carries
  the amount and currency it came from, the rate applied, the instant that rate was as at, the identity of the
  provider that supplied the rate, the rounding rule applied and the exact unrounded product that rule was
  applied to. It is not possible to build one without all of that, so an operator reading a figure can always
  tell whether they are looking at what was agreed or at what it is worth today, and reproduce the second from
  the first. The arithmetic is exact from end to end; no conversion passes through a floating-point number, and
  rounding is a declared step with a named mode rather than something that happens on the way past.
  Conversion is presentation and reporting only: it never writes back, and a converted amount offered where a
  stored money value belongs is refused. (`72cc3e6`)
- **A converted price says what produced it on every screen, document, response and export that shows
  it.** The conversion contract already guaranteed that a converted amount could not exist without its
  rate, as-at instant, provider and rounding; what was missing was anything that rendered one, which meant
  the rule held only until somebody wrote the first renderer. A converted figure now renders as its own
  self-describing sentence — `EUR 1234.56 converted from ZAR 25000.00 at 0.04938240 as at ... by
  acme.rates.ecb rounded half_up from ...` — so a surface showing nothing but the figure still shows the
  evidence, and somebody holding a printed page can reproduce the number without the system that made it.
  The structured evidence travels beside it wherever there is room to lay it out: the generated
  administrator and portal record screens, the document view kind's meta blocks, line cells and totals,
  and the one record projection that REST, the model-context tools and the console all serialize through.
  A converted amount is never offered as an editor on any surface, which is the presentation-side half of
  the rule that no write path stores one. (`04e0d86`)
- **The rule is held by the build rather than by whoever writes the next renderer.** Three things enforce
  it. A field presentation whose provenance and display have come apart is refused at construction. Any
  presenter handed a converted amount — core's or an extension's — is refused if it hands back a figure
  without the evidence, so a package cannot reduce one to a bare number by writing its own renderer. And
  one table enumerates the surfaces a converted amount can reach, with the files each is made of; its
  coverage test walks every entry, exercises it with a real converted figure, and fails the build when a
  file under `src/` reads a converted amount without appearing in the table. A surface added later without
  provenance is a red build, not an audit finding. (`04e0d86`)
- **The published REST contract describes a converted amount, and refuses one as an input.** The generated
  OpenAPI document gains `GeneratedBusinessConvertedMoney`, a closed schema with every member required, and
  a money field's read schema admits either the stored amount-and-currency pair or that. The create and
  update schemas admit only the stored pair, so a client validating against the contract refuses a
  converted figure exactly where the write path does. The report column vocabulary is now enumerated from
  the value types the report engine actually emits instead of being repeated by hand — which is what the
  hand-kept list had already got wrong, omitting `converted_money` from the contract while the runtime
  emitted it. (`04e0d86`)
- **A terminal that was disconnected for a week can submit its work without creating it twice.** An
  idempotency claim used to be constructed with a fixed one-day interval, so a client that captured work
  offline and reconnected after that found nothing, took a fresh claim and produced a second effect nobody
  was told about. The fixed day becomes two declared horizons: how long a claim replays its recorded
  outcome, and how long it is then remembered so a later repeat is **refused by name** —
  `business_record.idempotency_replay_window_elapsed` — rather than applied again. Refusal is the point: a
  duplicate that is announced can be reconciled, and one that is not becomes a document nobody knows
  about. Seven days of replay behind thirty days of memory by default, bounded at ninety days and one year,
  set with `BUSINESS_IDEMPOTENCY_REPLAY_SECONDS` and `BUSINESS_IDEMPOTENCY_RETENTION_SECONDS`. A
  configuration whose memory would run out before its replay window does is refused rather than accepted.
  (`51a97cb`)
- **A client's clock has somewhere to live and decides nothing.** The aggregate document command accepts an
  optional instant saying when the caller believes the work happened. It is recorded in the audit trail
  beside the server's own instant, marked as the client's, and never substituted for it. It is never read
  to decide ordering, expiry, period assignment or numbering, and that is proved rather than intended: an
  architecture test enumerates the paths that make each of those four decisions and fails the build if any
  of them can reach the type. Late and out-of-order arrival is therefore ordinary rather than exceptional —
  a document captured on Friday and submitted on Monday is validated, numbered, sequenced and audited where
  it arrives, and the capture instant explains the gap instead of reopening the sequence. (`51a97cb`)
- **Which checks an extension may leave until later is written down, and which it may never leave is
  enforced.** A client that was offline could not have consulted live stock or a live price, so a platform
  that assumes it did cannot accept the sale. `docs/business-runtime.md` now states the split as a table an
  extension author reads: stock, price and discount, credit limits and outside enrichment are deferrable to
  reconciliation; authorization, row and field policy, definition-shape validity and the idempotency claim
  itself never are. The non-deferrable half is not advice — an architecture test proves every mutation
  entry point demands its capability before anything else, that policy is planned for every operation, that
  the rule validator offers no way to defer a declared rule, and that stored record state has exactly one
  door. An extension may accept a sale and reconcile it; it may not accept one from an actor who was
  refused. (`51a97cb`)
- **Exchange rates come from extensions, and Kumwe ships none.** A package declares the currencies it prices
  and its place in the resolution order in its signed manifest, implements one port, and registers it through
  the same contribution registrar every other extension surface uses. An external rate service, a manually
  administered table, a bank feed and a contractual fixed rate are all that same port, and none of them is
  wired into the product. A package cannot price a currency it did not declare, cannot attribute a rate to
  another package, and cannot supply a rate dated after the moment that was asked about. Rates disappear with
  their package on disable, uninstall or trust revocation, in the same sweep as everything else it
  contributed. With no rate package installed, a conversion is refused rather than guessed. (`72cc3e6`)
- **A quantity counted in one unit can be expressed in another, and the expressed figure says so.** The
  unit-of-measure half of the conversion contract now takes exactly the shape the money half already took,
  and for the reason the shape exists: a stock extension and a sales extension that each invent their own
  conversion cannot exchange data, and they will disagree about what a case of a product is. Kumwe owns the
  typed quantity-with-unit — it already did — and now owns the contract as well: what a conversion asks for,
  what it returns, and the rules it obeys. A converted quantity is a different kind of thing from a counted
  one and cannot be mistaken for it. It carries the quantity and unit it came from, the factor applied, the
  instant that factor was as at, the identity of the provider that supplied it, the rounding rule applied
  and the exact unrounded product that rule was applied to, and it cannot be built without all of them. The
  arithmetic is exact from end to end, no conversion passes through a floating-point number, and rounding is
  a declared step with a named mode. Conversion is presentation and reporting only: it never writes back,
  and a converted quantity offered where a stored one belongs is refused by both the record value guard and
  the quantity codec. Report and export columns carry the whole story in the cell, so a downloaded artifact
  is still readable, and reproducible, by someone who has never seen the installation that produced it.
  (`46561c2`)
- **Unit conversion tables come from extensions, and Kumwe ships none.** A package declares the units it
  relates and its place in the resolution order in its signed manifest, implements one port, and registers
  it through the same contribution registrar every other extension surface uses — with its own additive
  registrar interface, so the frozen contribution service-provider interface is untouched. A metric
  standards table, a hand-administered trade-unit table, a supplier feed and a contractual case size are all
  that same port, and none of them is wired into the product. A package cannot relate a unit it did not
  declare, cannot attribute a factor to another package, and cannot supply a factor dated after the moment
  that was asked about — which matters more for packaging than for currency, because a case size is a
  commercial term that genuinely changes. Conversions disappear with their package on disable, uninstall or
  trust revocation, in the same sweep as everything else it contributed. With no conversion package
  installed a conversion is refused rather than guessed, and an architecture test fails the build if a
  conversion provider ever appears in the product itself. (`46561c2`)
- **A translation layer, so the interface can be presented in a language other than English.** Until now
  there was none at all: no catalogue, no translator, and no localizable helper on any of the three
  rendering surfaces. Interface text is now authored as XLIFF 2.0 — the format every professional
  translation tool and platform reads, so a translator never opens a source file and an external
  translation service plugs in through a format it already speaks — and compiled at build time into plain
  PHP arrays the opcode cache holds. A lookup is an array access: the request path parses no XML, reads no
  file per message and warms no cache. `composer translation:compile` produces the compiled catalogue and
  `composer translation:check` fails the build when it drifts from its source, in the same shape
  `composer openapi:check` already guards the API contract. (`0fa700d`)
- **Messages that are correct in every language's grammar, not only in English's.** Plurals, gender and
  other selections, ordinals, numbers, currencies and dates go through ICU MessageFormat via the
  already-required `ext-intl`. The reason is arithmetic rather than preference: the nine languages in scope
  span one plural category, two, three and six, and Arabic alone distinguishes zero, one, two, few, many and
  other. A whole sentence is one message, so a translator is never handed two halves of a sentence to
  reassemble in a language that orders it differently. Without `ext-intl` the formatter refuses to start and
  says why; it never degrades to a substituting formatter, because substitution is wrong rather than
  approximate. (`0fa700d`)
- **A stable message identifier, frozen before translation starts.** A message is looked up by a
  namespaced, lowercase, dotted identifier — `core.administrator.settings.save_action` — and never by its
  own English text. If the text were the key, correcting a typographical error in English would orphan that
  message in every other language and every translator would redo work for a change that altered no
  meaning. The grammar refuses source text by name, refuses an identifier an extension may not claim,
  refuses fewer than three segments, and admits only lowercase, so two identifiers can never differ from
  each other only by case. It is the same namespacing rule every other contributed identifier already
  follows, and it is written down for extension authors in `docs/interface-translation.md`. (`0fa700d`)
- **A four-step override chain, which is also how a vertical speaks its own language.** Lookup resolves
  core, then extension, then site, then organization, most specific first and **per identifier rather than
  per file** — so changing one word leaves every other message in that catalogue alone, and a later release
  still improves the ones nobody touched. That is what lets a health vertical relabel "Client" as
  "Patient", an education vertical as "Learner" and a hospitality vertical as "Guest", in one language or
  in all nine, without forking core and without an extension shipping a parallel string table. A page
  resolving several hundred messages performs one catalogue load, not several hundred. A message no layer
  carries comes back as its own identifier and never as an empty string: a visibly untranslated interface
  is a defect anyone can report, and a silently blank one is a defect nobody notices until a customer does.
  (`0fa700d`)
- **The language of a page is now decided per request, and `default_locale` finally decides something.**
  The site setting has existed, been validated and been administered since 2.0.0 while nothing consumed it.
  Negotiation now takes an explicit `locale` choice, then the client's accepted languages with their
  quality values, then that setting, then the source language — so an installation that changes nothing
  renders exactly as it did, and a site set to Hebrew renders in Hebrew with no further configuration. The
  resolved locale is published on the request and on a request-scoped holder that is closed when the
  request ends, and it is always an argument to a call rather than process state, so two jobs in one
  long-lived worker cannot end up sharing a language. (`0fa700d`)
- **Right-to-left presentation, finished rather than begun.** Every remaining physical inline-axis
  declaration across the stylesheets is now a logical property — margins, padding, borders, offsets,
  alignment and corner radii — so there is no second right-to-left stylesheet to keep in step: the whole
  mirroring follows from the `dir` attribute the three layouts now emit from the resolved locale.
  `composer assets:direction` fails the build on a new physical declaration, with an allowlist that ships
  empty. A browser journey opens the public, administrator and portal entry surfaces in Hebrew and in
  Arabic and asserts both the direction and the absence of horizontal overflow. (`0fa700d`)
- **A gate that stops hardcoded interface text coming back.** `composer translation:strings` walks every
  Twig template, refuses user-facing text nodes, translatable attributes and prose written into a Twig
  expression, and proves both directions of the catalogue contract — no template may reference an
  identifier the catalogue does not carry, and no catalogue entry may sit there unreferenced. What is
  deliberately not translated is stated rather than guessed: machine error codes, audit action names, log
  lines, developer exceptions and the product name, each with its reason recorded in
  `tools/translation-extraction.json`. A template that appears in neither the enforced set nor the register
  of work still to do is enforced, so a new template cannot quietly reintroduce inline text, and a
  registered template that becomes clean must leave the register. The gate is proven in both directions:
  green on this tree, and red with a useful message on a tree that puts back what it forbids. (`0fa700d`)
- **`en-GB` extracted across the public and shared surfaces:** 89 messages covering the whole public site,
  the eleven shared interface-standard partials, and the chrome, sign-in and access-denied surfaces of the
  administrator and the portal. Extraction of the remaining record surfaces, the console and the
  user-facing error paths stays in the roadmap with the register naming every template still to do.
  (`0fa700d`)
- **An operator changes the wording their people read, from a screen, without a deployment.** The override
  chain resolved core, then extension, then site, then organization, and was proven to resolve in that
  order — but its two upper steps were served from a map held in memory, so nothing an operator did could
  reach them. Site and organization wording is now stored, and **Administrator → Wording** is where it is
  changed: pick the language, pick whether the change applies to the whole site or only to your
  organization, search the shipped catalogue by what a message currently says, and write what it should say
  instead. It takes effect on the next page. This is the mechanism a vertical relabels core terminology
  with — "Client" as "Patient", "Learner" or "Guest" — so it is deliberately per message rather than per
  catalogue: every word nobody changed keeps improving with each release. `localization.overrides.manage`
  guards it and every change is written to the audit trail with its identifier, layer and locale.
  (`055f332`)
- **Three bounds on stored wording, each protecting something specific.** Only a message some shipped
  catalogue actually declares may be overridden, so the store cannot fill with wording nothing looks up and
  a mistyped identifier is refused rather than silently ignored. Only a language the installation carries
  may be written, so no override is stranded in a locale nothing resolves to. And a scope carries at most
  500 overrides per language, because the whole map is read once per unit of work on the render path and an
  unbounded map would make every page pay for one bulk import. Saving empty wording is refused — withdrawing
  the override is how the shipped text comes back, and it is one action rather than a trick. (`055f332`)
- **One stored row per layer, scope, language and message, enforced by the schema.** The whole identity
  carries a unique index, so resolution can never depend on which of two rows an engine happened to return
  first. A site-level row spells its absent organization as the empty string rather than as null, because
  all three engines treat two nulls in a unique index as distinct and a nullable column in that identity
  would have permitted exactly the duplicate the index exists to refuse; null stays the shape the
  application speaks and the adapter translates at the boundary. An installation whose schema predates the
  table answers "no overrides" rather than failing, so the recovery surfaces still render before
  `database:migrate` has run. (`055f332`)
- **An extension contributes wording through the ordinary package path.** A package ships
  `localization/messages/en-GB.xlf` for its translator and `localization/compiled/en-GB.php` for the
  runtime, beside the template directories the runtime loader already discovers. There is nothing to
  declare in the manifest and no second registration path; the compiled directory joins the extension layer
  of the chain in runtime-map order, so which package wins a shared identifier is a property of the signed
  map rather than of filesystem enumeration. A symbolic link in place of the catalogue root is refused, on
  the same reasoning as the template roots beside it. (`055f332`)
- **The browser matrix has a language axis, so right-to-left compares against itself.** Screenshot baselines
  were stored per device only, which meant a Hebrew or Arabic page had nothing to be compared against
  except a left-to-right one — a comparison that is either a false failure or a green run that checked
  nothing. The right-to-left journeys now run under their own projects and file their baselines under those
  names, and the source-language projects keep their original names so their committed baselines stay
  attached to them. The right-to-left journeys take their language from the project they run under rather
  than looping over both inside one cell, which is what makes the language an axis of the matrix rather
  than a detail of one test. (`f1a2366`)
- **Content is multilingual: one logical item, one entry per language, published one language at a time.**
  A translated item is a **translation group**, and each language in it is a real content entry with **its
  own slug, its own workflow state and its own publication window**. English can be live while another
  language is still drafting, because publication was never a property of the item — it is a property of
  the entry, and each language has its own. The group holds the one fact no member can hold: the **declared
  fallback**, the language a reader is served when the one they asked for is missing or not published yet.
  A reader whose language the item does not carry gets the fallback rather than a miss, after falling
  through their own language's chain first, so somebody asking for `pt-BR` is offered `pt` before another
  language entirely. Where nothing in a group is published, nothing is served: a fallback that is still
  drafting is not a page anybody may read. (`cb5f482`)
- **Two properties of a translation group are the database's, not the application's.** A unique index over
  the group and the locale means one item can never carry two entries for one language, and the site-wide
  slug index already in place means two languages of one item can never collide on a route segment. Both
  are proven by watching the engine refuse the write, on every supported database, rather than by trusting
  the application to have checked first. Both new columns are nullable and nothing is backfilled, so an
  entry authored before content carried a language dimension is untouched: its stored revision checksums
  stay valid, because an entry that declares no language snapshots to exactly the keys it always did.
  (`cb5f482`)
- **`hreflang` and a front-end language selector, shipped by default rather than added later.** The public
  layout emits one `alternate` link per **published** language and never for a drafting one, plus the
  declared fallback as `hreflang="x-default"` — which is precisely what that value means. The selector
  offers exactly the same set, each choice **named in its own language**, because the reader reaching for a
  language selector is the reader who cannot read the current one; that also means it needs no message
  identifier and no translation of its own. Both come from one calculation, and a page whose item publishes
  fewer than two languages renders neither, so an untranslated site looks exactly as it did. Which language
  a reader is served follows the locale the interface already negotiated: a URL that names a language is
  honoured as written, and the site root — the one public entry point that names no language — resolves the
  reader's locale within the group. (`cb5f482`)
- **Business definition labels carry locales, without invalidating a single published definition.**
  `EntityTypeDefinition`'s singular and plural labels and `FieldDefinition`'s label, description and help
  text can each be declared in more than one language, read back through `singularLabelIn()`,
  `pluralLabelIn()`, `labelIn()`, `descriptionIn()` and `helpTextIn()`. A published definition version is
  immutable and identified by a SHA-256 over its canonical bytes, so the dimension is shaped to be
  invisible until it is used: translations stand **beside** the declared wording and are written into the
  canonical document **only when non-empty**, exactly as `soft_delete_enabled`, `record_invariants`,
  `portal_operations` and `computation_mode` already are. An untranslated definition therefore encodes to
  the bytes it always encoded to and keeps its checksum — asserted against a hand-written pre-dimension
  document rather than against anything derived from the new code. Locale keys are normalised and both
  dimensions are sorted, so `pt_br` and `PT-BR` cannot become two translations of one thing and declaration
  order cannot move a published checksum. (`cb5f482`)
- **An extension can declare its translation-set intent at admission time.** A package declares
  `contributions.content.translation_groups` in its manifest — the content set, the languages it intends
  to publish in, and the language it falls back to. The canonical SDK manifest graph is activated directly;
  provider code cannot add or widen this declarative inventory. The language list is a **closed admission
  claim** an operator can inspect before installing, and a fallback naming a language the package never
  publishes is refused during manifest admission. A
  package declaring no content set exports no `content` section at all, so its bytes are unchanged. The
  manifest section and declaration members are pinned in the package-owned SDK contract. This declaration
  is inventory, not yet the runtime link
  between an extension-owned item and a set; that additive frozen association remains `V2-LNG-012` in the
  roadmap rather than being claimed here as completed delivery. (`cb5f482`, `b539161`)
- **[Content translation](docs/content-translation.md),** explaining the model to an editor, stating what
  the database guarantees and why the definition document had to stay byte-stable, and stating precisely
  where an extension's admitted translation-set inventory stops and the still-open runtime association begins.
  (`cb5f482`, `b539161`, `e4aa755`)

### Changed

- **NRM-2026-014 — Package test ownership at adoption.** Migration governance now rejects duplicate
  package tests still present in App, missing retained host test files, contradictory ownership and
  non-canonical paths. Extraction guidance and the PR template require existing, legacy and future
  packages to own runnable behavior, boundary/refusal and applicable conformance tests, while App keeps
  composition, authorization, persistence, lifecycle and delivery evidence. Seven negative ownership
  scenarios exercise the gate; production code and dependency pins are unchanged. (#136)

- **2026-09-02 — `composer qa` no longer depends on the machine finishing the suite in five minutes.**
  Composer kills a script after its default `process-timeout` of 300 seconds, and the complete PHPUnit lane
  now carries 3,618 tests against a real database. The CI runners finish inside that window, so the gate
  stayed green there, while the documented `docker compose run --rm app composer qa` on a laptop, or any
  contended sandbox, was killed at "The process "phpunit --colors=always" exceeded the timeout of 300
  seconds" — a red result that names no test. The manifest now sets `process-timeout` to one hour, so the
  local gate reports what the suite found rather than how fast the machine was. (#128)

- **2026-09-02 — `kumwe/extension-sdk` is pinned at 0.2.4, the release that makes the scaffold executable
  end to end.** The 0.2.3 scaffold generated handlers calling declaration members the SDK never defined —
  the listener passed the event object to `DomainListenerDefinition::accepts()`, the job handler called a
  `type()` the job definition does not have, and the consumer called an `accepts()` the consumer definition
  does not have — so an installed component crashed on its first event, job and durable delivery; the
  generation-lifecycle proof had only ever loaded them. 0.2.4 corrects the generated and fixture handlers,
  executes them in the SDK suite against manifest-built declarations, repairs the `kumwe-extension` command
  line that fatalled on every real command, removes the two declaration mirrors that imported five
  non-existent `Spi` types together with the dead `Spi\Presentation` and `Spi\Runtime` registrar families
  (182 classified public types), adds `Capability` grammar coverage and reconciles the migration map
  against this branch. The App's generated-extension lifecycle proof now drives every executable the
  provider binds through the host after activation: the domain listener through the App dispatcher against
  the App's event contracts, the job through the worker registry's validated and trust-fenced handler, and
  both graphical routes through the HTTP pipeline under a real administrator session and a real portal
  member session, then observes the pipeline drain with 503 once the package is disabled. The layer map
  drops the `Spi\Presentation` rule with the namespace. (#124)
- **2026-09-01 — Recorded decision: the App's Studio pin moves from `0.1.0-rc.1` to `0.1.0-beta.3`, the
  coordinate `kumwe/producer` 0.2.0 pins.** This is a deliberate re-pin with its own evidence, not a silent
  one, and the lower prerelease label is accepted on purpose: Producer's host agreement makes the pin a
  three-way chain, App → Producer → Studio, and Producer's first released coordinate set
  (`kumwe/extension-sdk` 0.2.0, since advanced to 0.2.4, `kumwe/producer` 0.2.0, `kumwe/conversion` 0.1.2)
  implements Studio `0.1.0-beta.3` at protocol `0.1.0-draft.2`. Holding the interim `0.1.0-rc.1` snapshot
  would have left App and Producer disagreeing about the contract they share. The release record and all
  eight npm tarballs were replaced atomically with the provenance-backed beta.3 bytes, `PIN.json` names every
  digest, the browser build points at the same tarballs, and the alignment verifier now requires the Producer
  pin to be a provenance-backed npm release anchored to its exact source commit.
  `PinnedStudioContextualAuthoringAvailabilityTest` records what the new pin does and does not enable: the
  readiness gate passes its protocol boundary —
  Producer's digest-verified corpus manifests the four contextual document schemas and its operation registry
  carries the seven authoring capability/route pairs — and stops closed at `browser-runtime-unavailable`,
  because beta.3 claims no contextual profile and App packages no contextual browser entry. No maturity claim
  follows from the label in either direction. (#124)

- **Extension installation now consumes the Extension SDK 0.2 package and security authorities directly.**
  One private, bounded `InspectedPackage` snapshot supplies archive identity, checksum, manifest, neutral
  findings, attestations and extraction bytes; App policy maps coded facts without recreating SDK package
  types. Mandatory archive, manifest/reference, PHP syntax, trust/signature and invalid attestation evidence
  fail closed in both modes, while `scan` alone adds advisory author-quality checks and `off` remains a
  non-production package-only inspection. Package signatures use the SDK's domain-separated message and
  public-key verifier, oversized caller archives are capped before private copying, and the pre-stable token
  migration no longer carries the removed legacy package transition. (#124)

- **Kumwe 2.0 relicenses from GPL-2.0-only to Apache-2.0.** Both contributors work for Vast Development
  Method and agreed to the change; the copyright is held by the company's legal entity, so the line
  reads `Copyright 2022-2026 Vast Development Method Trading Pty Ltd`. LICENSE now carries the
  official Apache License 2.0 text unchanged, a NOTICE file names the project, the copyright, and
  where third-party notices live, and the composer and npm manifests declare `Apache-2.0`. The removal of the Joomla packages was the last copyleft constraint in the dependency
  tree — production dependencies are MIT, BSD-3-Clause and Apache-2.0 throughout — and the README now
  says plainly what the license means here: proprietary products and proprietary extensions are allowed
  and welcome, a hello or a patch is appreciated and neither is owed, the software comes without
  warranty or liability, and the Kumwe name and logos stay with the project. Per-file license headers
  are deliberately not stamped; the root LICENSE and NOTICE travel with the distribution. (#115)

- **The platform runs Joomla-free: Laminas ServiceManager and EventManager carry composition and domain
  events behind Kumwe-owned seams.** The four never-referenced Joomla packages — archive, filesystem,
  filter and registry — left the dependency set with no replacement, since the hardened native ZipArchive
  package boundary, typed `ApplicationConfiguration` plus the plain-array `config` service, and native
  PHP filesystem use already covered their ground. The composition root now builds
  `Kumwe\App\Kernel\Container`, a final PSR-11 wrapper over ServiceManager 4 that keeps the `share()` and
  `alias()` registration vocabulary and turns overwrite protection into the container-wide
  `allowOverride=false` stance, while domain events dispatch through EventManager 3 behind the
  vendor-free `ExtensionEvent` contract — the same `getName`/`getArgument`/`isStopped`/`stopPropagation`
  vocabulary, the same eight `onKumweExtension*` names and argument maps — so the extension SPI never
  again leaks an engine type. The new public `ExtensionEvent` interface is byte-pinned as an
  additive compatibility fixture with reflection parity, leaving every frozen contract generation
  untouched; ADR 0019 records the decision, the preservation covenant, contribution policy
  and architecture documents name the Laminas engines; the forty-seven container-typed test
  suites, the boundary pins and new kernel-container, extension-event and registrar unit suites hold the
  seam; and a permanent architecture-policy gate refuses any returning `Joomla\` reference or
  `joomla/*` requirement. (#115)

- **The operator map and public documentation index now expose the complete App-owned Studio host seam.**
  They route changes to authority, artifact/recovery, media/resource, preview and published-composition code,
  identify the administrator shell and exact vendored package family, and distinguish those host adapters from
  Studio's portable page builder, 45-block catalog, private Editor.js adapter and renderer-web. The integration
  guide now maps exact package/corpus, unit, integration, architecture, browser, cross-engine and security
  evidence paths to their direct commands, so an App re-pin cannot be mistaken for a source-only dependency
  update. (#114)

- **Studio resource discovery now has a production App host port.** The capability- and
  generation-fenced dispatcher accepts the canonical `resource/search` operation, validates its exact
  qualified resource type, bounded search text, page size and opaque cursor, and delegates only to an
  App-owned provider. The first provider projects authorized Content entries through `ContentService`
  into stable opaque references and deterministic title order; Studio receives neither database details
  nor Editor.js state. Exact-shape, malformed-cursor, overproduction, duplicate-identity and pagination
  refusal tests keep the portable Studio seam fail closed. (#114)

- **Studio's page-builder and inline-content boundaries are now explicit and portable.** ADRs 0007 and 0018
  record that Studio owns the standalone page builder, all 45 first-party block types, ten patterns, portable
  semantic renderer, progressive behavior and the pinned Editor.js implementation behind an editor-neutral
  factory. App sees and stores only canonical Studio values; Editor.js output/configuration never crosses the
  seam. Block-declared profiles govern Markdown, structural safe HTML, media and advanced content tools;
  scoped CSS is a separately authorized host artifact, while authored JavaScript and client-side database
  expressions remain forbidden. App retains media custody, provider-owned read-only resource discovery,
  delivery-time data authorization, persistence, preview, publication, Twig delivery and CSP. The browser
  renderer and Twig adapter must replay one language-neutral conformance corpus rather than grow separate block
  meanings. App remains on its current seven-package alpha.11 pin; Studio's next eight-package set is expected
  as alpha.9, but adoption uses only the exact `<coordinated-version>` in its published release record and
  advances dependencies, lock, tarballs, pin, notices and corpus together. Beta/RC promotion stays disabled
  until Studio M1-04 and evidence acceptance. The Apache-2.0 Editor.js versus GPL-2.0-only App distribution
  question remains an owner/legal release condition, not a compatibility conclusion or unilateral relicense.
  (#114)

- **Reordering an ordered collection renumbers set-based instead of one statement per link.** The
  write repository's reorder now writes the new positions in bounded `CASE` statements of at most a
  hundred links each — three bound parameters per link, inside the same parameter and packet
  ceilings the owned-line batch reasons from — so a thousand-line document renumbers in ten
  statements rather than a thousand (`P4-B`). The negative-flip first pass, the unique
  source-and-position index guarantee, the exact-permutation refusal and the moved-link detection
  are unchanged: a statement that renumbers fewer rows than its chunk carries still refuses the
  reorder.

- **The merge lane keeps all its evidence; the pull-request lane stops paying for it twice.** A new
  `preflight` job runs every record gate — baseline, quality contract, frozen contracts, documentation
  records, roadmap, interface programme, OpenAPI, translations, coverage attribution, coding standard and
  architecture policy — in minutes, and the database, browser, artifact and deployment jobs only start
  after it passes, so a push that would fail on a record is refused before the expensive jobs spend
  runner-hours. On pull-request pushes the database job keeps its complete single-pass suite on all three
  engines but defers the repeat/reverse idempotency passes and the signed backup/restore/tamper drill to
  the merge lane, and complete deployment acceptance runs at merge and release rather than a third time
  per push. Every push to `master` still runs the full evidence set on all three engines, so Gate A
  criterion 12 and every `docs/quality/contract.json` binding are unchanged. The quality job additionally
  persists PHPStan's result cache between runs.

- **The remaining live core identity now reads Kumwe App.** Fresh clones enter the canonical `app`
  checkout directory, quality-fixture metadata uses the `Kumwe.App` test namespace, and the default Redis
  key prefix changes from `kumwe.cms` to `kumwe.app` through `REDIS_NAMESPACE`. Existing deployments must
  pin `REDIS_NAMESPACE=kumwe.cms` throughout a rolling upgrade while old processes and leases drain. To
  adopt the new default later, stop the remaining processes, deliberately migrate only ephemeral state that
  must survive or flush/expire the old rate-limit and cache keys, then restart every process with
  `REDIS_NAMESPACE=kumwe.app`. Running both prefixes concurrently would split coordination rather than rename it.
- **The core is called App.** The PSR-4 namespace moved from `Kumwe\CMS\` to `Kumwe\App\` across 2,021
  files, and the product name moved from "Kumwe CMS" to "Kumwe App" everywhere it is written: the
  documentation headings, the composer description, the OpenAPI `info.title`, the MCP server name and
  capability catalogue, the site footer, the demo content, and the release artifact, which is now
  `kumwe-app-${version}.zip`. Three frozen SPI generation digests are re-recorded, because the public
  type names are part of the frozen surface and they moved; every published migration checksum is
  regenerated, and so are the two runtime pins in `DoctrineNonTransactionalMigrationRecovery`.
  **This was free only because nothing has been tagged yet.** A published migration's checksum is the
  hash of its own file bytes, so the same rename after a release reads as tampering on every existing
  installation and refuses to migrate — demonstrated here, when a database migrated before the rename
  answered `Migration checksum drift detected` until it was rebuilt. It is a decision that had to be
  taken before the first version or not at all. Progress on `V2-DOC-002`; the wire identity the SDK
  parses is emitted but not yet coordinated, which is what that finding now tracks. (#97)
- **Quality evidence now has to prove the lane and pass it claims.** The quality-contract runner distinguishes
  checks it can execute generically from checks delegated to a named provisioned job, and the contract verifier
  proves that each workflow, job and command binding still exists. Dependency analysis recognizes grouped imports;
  the roadmap verifier requires every changelog citation to be reachable from `HEAD`; and the idempotency verifier
  rejects a missing, truncated or runner-failed JUnit report, requires evidence for every enforced pass, and checks
  independent collection and runner totals before accepting its shrinking baseline. The browser scripts and
  nightly/release bindings now select locale projects only in the cadence that declares that locale contract.
  Reverse class-order idempotency remains an explicitly pending measurement rather than being presented as an
  enforced property. (`b539161`)
- **The global coverage ratchet now starts from a measured baseline.** The canonical MariaDB run measured 47,935
  of 86,459 executable lines, or 55.44%, across 2,513 tests and 54,760 assertions. That result arms the rule that
  refuses a global fall greater than a quarter of a percentage point instead of inventing a starting value or
  reporting an unarmed rule as a pass. A focused declaration-list case also proves that the MCP catalogue validator
  refuses an input schema whose root type is not an object. (`7a83c29`)
- **Production database-account guidance now describes the supplied topology rather than the recommended one.**
  The shipped Compose topology gives the migration task and the long-lived app, worker and scheduler the same
  database credential, so it does not provide DDL-versus-DML privilege separation. Deployment and monitoring
  guidance now say that plainly and require a production overlay or platform secret injection to give `migrate`
  a distinct DDL-capable account while withholding it from the runtime services. (`b539161`)
- **The architecture gate judges dependency edges instead of describing the direction.** It was four grep
  predicates — a product-name spelling, two forbidden import prefixes and two static-locator symbols — and it
  printed "Kumwe architecture policy verified." without resolving a single dependency edge. A file could
  import Doctrine into the application layer, or reach from the domain into a delivery adapter, and nothing
  said so. `composer architecture:policy` now also resolves each file under `src/` to its layer, extracts
  every first-party symbol the file actually references from the token stream — imports, grouped imports,
  aliased imports and inline fully qualified names — and fails on any edge the layer graph forbids. A
  namespace no rule classifies is itself a failure, because an unclassified namespace is one nothing governs.
  The 157 edges that already pointed the wrong way are recorded rather than permitted: a new violation fails
  immediately, an entry that no longer violates fails as stale so it must be deleted, and an entry past its
  expiry fails outright. The textual predicates stay, because in those four cases the source text is the
  contract; they are simply no longer the whole check. Closes `V2-ARC-001` and `V2-QA-002`. (`c72707b`)
- **The browser journeys run on the engines the product runs on.** They ran against one PostgreSQL service
  while MariaDB and MySQL are the primary engines, so the surfaces an operator actually uses were only ever
  driven on the engine fewest installations run. They now run on MariaDB, MySQL and PostgreSQL at merge,
  desktop and mobile Chromium, and a run reports its first-attempt results separately from its retried ones —
  a journey that only passes on a retry is not a passing journey, and reporting the two together hides the
  difference the acceptance figure is about. (`b5375b7`)
- **Coverage is measured on the primary engine, attributed honestly, and ratcheted on the change.** It was
  collected on the PostgreSQL leg alone and published with the words "No threshold is enforced yet" beside
  it, while 148 `#[CoversNothing]` attributes across 74 files — 39 of them on integration tests driving real
  behaviour against real engines — made the report describe a smaller product than the one being tested. The
  canonical measurement is now MariaDB. `composer coverage:attribution` holds `#[CoversNothing]` to a
  reasoned allowlist, for tests whose subject is not a class under `src/`, and to a pending list that carries
  an owner and an expiry, only ever shrinks, and cannot admit a new behavioural test. `composer
  coverage:ratchet` requires at least 90% of the executable lines a change adds or edits under `src/` to be
  covered, and refuses a global fall beyond a quarter of a point once a baseline is recorded. The declared
  branch floor is reported as *not enforced*, with the reason — `pcov` reports executed lines and no branches
  — because a rule the tooling cannot execute is worth stating and is not worth counting as enforcement.
  (`21c4fef`)
- **The integration suite is now asked to be idempotent rather than assumed to be, and it answered.** The
  database job runs it a second time against the database the first run left behind, on all three engines.
  The first execution reproduced the defect exactly: six tests across four classes fail on the second run,
  identically on MySQL and PostgreSQL. Three `GeneratedBusinessBrowserIntegrationTest` methods meet a schema
  installation the previous run left behind; `AssetInspectionCustomViewIntegrationTest` meets its own
  contribution; `ExtensionContributionLifecycleIntegrationTest` meets an extension it left installed and
  trusted; and one Redis-outage assertion turns out not to be database state at all — a process-global cache
  diagnostic is never cleared, so "a healthy cache records no degradation" is only true the first time. Those
  six are recorded in [`docs/quality/idempotency-baseline.json`](docs/quality/idempotency-baseline.json) with
  an owner, an expiry and what removing each one takes, and the step fails on anything outside the record: a
  test that starts failing, an entry whose test now passes, or an entry past its expiry. A permanently red
  gate would have been worse than the defect it reports and an advisory one would not be a gate, so the
  record takes the same shape as the dependency baseline and shrinks the same way. (`b5375b7`)
- **The same check says out loud which half of its property it does not yet enforce.** The suite's behaviour
  under a different class order is the other half of the same acceptance, and the first attempt at measuring
  it was wrong: PHPUnit's `--order-by=reverse` reverses the tests *inside* each class as well as the classes,
  so a class whose methods are written to run in declaration order fails for a reason that has nothing to do
  with a reused database. It reported 38 failures across roughly 21 classes — seven methods of one class,
  five of another — which is the signature of intra-class ordering rather than database residue. Recording
  those 21 classes would have been a blanket permission wearing a baseline's clothes. The pass is instead
  declared unenforced in the baseline, with the reason, the owner and the finding, and the mechanism is
  corrected: the tool generates a configuration that lists the integration classes in reverse and leaves
  method order alone, verified to collect the same 283 tests. A gate may narrow what it claims; it may not
  narrow it quietly, and an architecture test refuses an unenforced pass that carries no reason. (`b5375b7`)
- **The release job runs the release lane of the contract instead of its own shorter list.** It carried four
  checks where a contributor runs thirteen, so a release could pass with a gate never having been executed
  against the tag. (`b5375b7`)

- **The layer that decides a transaction now owns the contract for one.** Deciding that a set of writes must
  settle together is a use-case decision, but the contract expressing it — `TransactionManager` — was
  declared in Infrastructure and imported inward by thirty-three application services, so no use case could
  say "these settle together" without naming the persistence package that happens to implement it. The
  contract moves to `Kumwe\App\Application\Persistence\TransactionManager`; `DoctrineTransactionManager`
  stays exactly where it was and implements it. Nothing about the abstraction changed: the same three
  methods, the same rule that a nested call joins the scope already open, the same guarantee that a commit
  hook waits for the outermost commit while a rollback hook fires as soon as its own scope is discarded.
  The aggregate document command commits through the identical adapter, and every existing test passes
  unmodified. Outside the two files, the only edit is the import each caller declares. (`5a15f43`)
- **The Doctrine automation adapters are filed where the adapters live.** `DoctrineJobQueue`,
  `DoctrineScheduler` and `DoctrineQueueRuntimeOperations` sat under `src/Application` while opening
  connections, branching on the PostgreSQL platform and writing `FOR UPDATE SKIP LOCKED` claim scans — two
  and a half thousand lines of driver knowledge in the layer that is meant to hold none. They move to
  `Kumwe\App\Infrastructure\Automation`, beside the Doctrine adapters for authorization, security and
  persistence, while the ports they answer stay in Application. No SQL, no lease token, no claim scan, no
  engine branch and no concurrency semantic is touched: the queue-slot redesign is later work and would be
  unreviewable stacked on a move. (`4f4770d`)
- **The architecture gate enforces the layering instead of describing it.** Both corrections above are the
  kind that regress from one misplaced import or one file created in the wrong directory, and nothing in
  the build would have noticed. `composer architecture:policy` gains two predicates: application code —
  the shared `src/Application` root and every module's own `Application` directory alike — cannot import
  Doctrine or `Kumwe\App\Infrastructure`, and a class named for the technology it binds to cannot sit
  inside an application layer. The extension migration SPI is the single admitted exception, because a
  contributed migration is handed the connection it runs its own DDL on; it is named file by file, so a
  fourth offender fails rather than inheriting a directory-wide waiver. Because a grep cannot see a fully
  qualified `\Doctrine\DBAL\Connection` written inline, a companion architecture test checks the same
  constraint by type — reflecting every application signature and walking the token stream past
  documentation blocks — and pins the transaction contract to Application, its adapter and the three
  automation adapters to Infrastructure, and each adapter to the port it answers. Both rules were proven to
  fail on a deliberately reintroduced violation before being committed. (`991600d`)
- **Cross-site isolation is decided by containment instead of string equality, and is provably no wider.**
  The authorization gateway used to compare the owning site identifier with the caller's; it now asks whether
  the caller's site is inside the owning scope. For a resource owned by one site — every resource on an
  installation that declares no group, and every accounting resource on one that does — the containment test
  is that comparison, on the same single value. A test enumerates every ordered owner, caller and grant
  combination over a set of sites and compares the gateway's verdict and its stated reason against the rule
  the change replaced, written out as a reference; a single disagreement fails the build. The existing
  isolation tests were not rewritten. An instance owned at installation level now requires an
  installation-wide human grant, which is the same requirement the type-level `installationGlobal` flag
  already expresses — the two are reconciled into one rule rather than left as two mechanisms. (`944652a`)
- **The ownership registry stores a scope.** `resource_site_ownership` gains the level and the owning group
  beside the site it already carried; the primary key is unchanged, so one owner per resource stays
  structurally enforced. The forward migration gives every stored row the site scope it already meant, keeps
  the foreign key and cascade on the site column, re-derives that column's character definition from
  `sites.identifier` so the portability pin survives the alteration, and derives the same definition for the
  new group column and the group tables — because MariaDB and MySQL otherwise resolve a new table's character
  set from the database default and a correct-looking join fails as an illegal mix of collations. Group
  membership is resolved once per process from a bounded declared set, so the containment test issues no
  extra query on the authorization hot path. Reading stays fail-closed on exactly the old terms: no row means
  unowned, a disabled site's resources stop resolving, and a group whose members are all disabled resolves to
  nothing rather than to an empty owner. (`944652a`)

- **The restore drill stops comparing bytes and starts using the restored system.** Every check the backup
  acceptance manifest performed was satisfiable by a restore booted with the wrong keys, because ciphertext,
  nonce and row digests are key-independent — an installation whose `APP_SECRET` was lost passed the whole drill
  and failed weeks later at the first sign-in with a second factor. The drill now decrypts a stored
  `core.secret` envelope through the production cipher, signs a restored limited operator in with its restored
  password hash, allows the one operation it holds and denies the one it does not, ages its session and refuses
  it, decrypts the restored TOTP credential through the step-up cipher, passes a live challenge and refuses the
  replay of that code and of a spent recovery code, then materializes the extension runtime, dispatches the
  schedule the backup carried and drains the job in fresh processes. (`687707c`)
- **Backup and restore documentation gained declared recovery objectives and a key-restoration order,** with the
  consequence of each wrong key stated, and the drill now records its measured quiesce and restore seconds so a
  declared objective can be replaced by a figure from real hardware. (`687707c`)
- **`audit:verify` has three verdicts instead of two,** because an intact trail means materially different
  things under the two enforcement postures: exit 0 is chain verified with the database refusing rewrites, exit
  2 is chain verified with no enforcement installed on this server, and exit 1 stays reserved for an actual
  divergence. Nothing that lacks the triggers can present the clean verdict any more. The nightly job
  deliberately does not fail on absent enforcement, because that is a standing property of the server and
  dead-lettering a job every night would train operators to ignore the one signal that is an incident.
  (`ae4b92b`)
- **Audit digests are taken over a canonical form on both sides.** Storage engines do not hand back the bytes
  they were given — one engine's native JSON column reorders object keys and restyles whitespace, another keeps
  JSON as text, and `occurred_at` is a `datetime(6)` on some engines — so digesting driver output would have made
  `audit:verify` report tampering on an untouched trail. (`f78bc3c`)
- **Record encryption has its own configuration, independent of `APP_SECRET`.** `RECORD_ENCRYPTION_KEY`, its
  identifier and its retired keys each have a `_FILE` companion resolved by the application rather than by the
  container entrypoint, so bare-metal deployments get the same mounted-secret discipline, and the two secrets
  can finally rotate on separate schedules. (`a669846`)
- **Mutation-plan tokens get their own key ring, label, identifier and injected type,** so a record rotation
  cannot re-key live browser-held tokens and a move to a managed key service cannot drag them along.
  (`a669846`)
- **Administrator browser sessions honour the security epoch.** The session row carries its issuing epoch,
  backfilled from its owner, and lookup compares it, so a revocation reaches a browser on its next request
  instead of at expiry — and break-glass gained the terminate-all-sessions operation it was missing.
  (`4bc5c74`)
- **Any published layout may lead the site.** The homepage invariant and both administrator pickers assumed the
  one-layout era; all three now accept any published content entry while keeping the published-within-window
  rule, and the landing layout gained an optional media-backed logo. (`cf1c5cb`)
- **The README is organised around the one-command demonstration** — what Kumwe is, a quick start that actually
  finishes, a clean-start path, how to run the workers queued exports depend on, the test gates, and how to
  contribute and extend. (`5ad13b3`, `a35f362`, `9f91a75`)
- **Demonstration profile releases are append-only,** with persisted checkpoints validated and policy provenance
  re-checked on every reconciliation, so an installed-then-customised site cannot be silently rewritten by a
  later profile version. (`cbde170`, `4fd54fd`, `7f176fb`, `eb0900b`)
- **Delivery parity is asserted against the live router rather than against source text.** The previous check
  read the composition root as a string and asserted substrings inside a fixed character window around each
  route name, so it could not see routes built by concatenation and passed on a class that imported a guard
  without calling it. It now checks only what the OpenAPI document can prove about itself, and whether a path is
  really routed and which capability it demands is asserted against a booted container. (`f1c00ea`)
- **Test toolchain moved to PHPUnit 13 and PHP_CodeSniffer 4.** `Assert::isType()` call sites now name the type
  they always meant, two stubs that constrained their arguments became mocks because that is an interaction
  assertion, every `with()` site states the invocation count it expects, and doubles with no expectations are
  created as stubs. (`0aeca85`)
- **Frontend build dependencies moved to vite 8.2.1 and the current Node typings,** with the committed bundles
  as the evidence: rebuilding reproduces every file under `public/assets/build` byte for byte, hashed names
  included, so no rendered page and no screenshot baseline can have shifted underneath. (`15f48cf`)
- **PHP 8.5 images use the bundled OPcache** rather than a separately built extension, verified at image build
  time. (`3d4c806`, `1cebcb6`, `57ecd52`)
- **Restore tooling matches the supported PostgreSQL major version,** installing verified client packages rather
  than whatever the base image happens to carry. (`6f2735d`, `549bd64`, `7c4885c`, `ef4582c`)
- **Copyright is attributed to Vast Development Method** across the source tree. (`dc35a63`)
- **A report column or an export artifact holding a converted amount now carries the evidence for it.** An
  export outlives the request that made it and is the record the recipient keeps, so a converted figure sent
  out as a bare number is provenance lost permanently. A report column declared as a converted amount carries
  the whole story in the cell — the presented figure, the amount and currency it came from, the rate, the
  as-at instant, the provider and the rounding applied — written so that somebody reading the downloaded file,
  with no access to the installation that produced it, can tell a converted figure from an agreed one and
  reproduce it. A bare number in such a column fails the column's own declared type, so it is a refused report
  rather than a quietly weaker artifact. This widens the export payload for that column type; existing report
  and export payloads are unchanged. (`72cc3e6`)

### Fixed

- **2026-09-02 — `demo:export-profile` writes a business package that installs under the profile name it
  was exported as.** Exporting a documentation-plus-VDM installation as `--profile=fork` produced a package
  the catalog re-validated and the command reported as success, which `database:migrate` then refused three
  ways: the definitions kept their `site.default.vdm_*` handles under the new name ("contradicts the default
  site template"), `installation_order` and `depends_on` were derived from relationships alone, so
  `invoice_line` was declared before the `product` it references through an entity-reference field
  ("targets an unavailable definition"), and every document was written in its source's published shape,
  which installed once and then made every later migration die with "Only a draft definition can be
  published to a positive version". A pure `DemoBusinessTemplateProjector` now re-namespaces every handle
  to `site.default.<profile>_<tail>` and rewrites every reference through one exact-value map — relationship
  targets, entity-reference and ordered-lines field targets, and the definition each record, relation,
  action and archive names; installation order and `depends_on` come from the full reference graph the
  validator closes over, and a cycle is refused by name; documents leave in the released-draft shape.
  `VdmBusinessManifestProjector` normalises any projected document to that shape, so a package installed
  from an older export re-migrates cleanly, and `FilesystemDemoManifestCatalog` refuses a handle outside the
  profile's namespace, an entry that contradicts its document and an unsatisfiable order, so the command can
  no longer report success for an uninstallable package. `DemoBusinessProfileExportInstallIntegrationTest`
  exports a fresh documentation-plus-VDM installation under a minted name, installs the package into a
  second empty installation, asserts the same 12 definitions, 80 records, 130 relations, 65 actions,
  2 archives and 221 policy rows arrive, and reconciles a second time proving nothing changes, on MariaDB and
  PostgreSQL. (#128)

- **2026-09-02 — The App-owned proofs pull request #124 dropped beside its SDK migration are pinned again.**
  The deletions removed tests whose subjects still live under `src/`: the refusal of system identities on
  extension resource policies, the owner check a policy's capability must pass, the typed capability and
  system-policy metadata core publishes, the ambiguous-namespace refusal in `ExtensionContributionRegistrySet`,
  the owner-bound `ContributionDefinitionChecksum`, the core administrator icon sprite contract, the
  audit-failure rollback of `StudioProducerMutationBoundary`, and the `api_tokens` backfill in
  `TokenAndTrustLifecycleMigration`. Each assertion is restored against the SDK-canonical types in
  `ResourcePolicyDefinitionTest`, `ResourcePolicyRegistryTest`, `ContributionDefinitionChecksumTest`,
  `ExtensionContributionRegistrySetTest`, a new `AdministratorNavigationRegistryTest`,
  `StudioProducerMutationBoundaryTest` — a throwing audit recorder now proves zero commits, one rollback and
  no completed replay claim for keyed and unkeyed saves — and `MigrationIntegrationTest`, whose slimmed
  token-lifecycle proof seeds the pre-lifecycle row shape under a random prefix and asserts the stamps, the
  copied security epoch and the bounded expiry backfill on MariaDB and PostgreSQL. (#128)

- **2026-09-02 — A refused demonstration example leaves no enabled signing key behind, `demo:install`
  names the example that failed and reports the credentials file before any example installs, and the
  extension guide stops documenting the retired event registrar and App migration types.**
  `DemoExampleExtensionInstaller::install()` registered a one-year single-package key before the package was
  admitted, and a refusal unlinked only the archive, so every refused attempt left another live row in the
  trust store. The installer now revokes the key, with a reason naming the example, when the manager refuses
  the package; a key that signed an admitted release is kept on purpose, because the release names it and
  runtime trust enforcement verifies against it on every request. `DemoInstallCommand` prints the
  credentials-file line the moment the file is written, ahead of the example loop, and an example step that
  throws reports which example failed. The "Events" section of `docs/extensions.md` now describes
  manifest-declared `domain_listeners` bound through `ExtensionBindingRegistrar::domainListener()` and
  dispatched inside the authoritative transaction, states plainly that the `onKumweExtension*` lifecycle
  events are host-internal under the SDK contract, and cites the `audit-listener` example; "Database
  migrations" names the SDK's `ExtensionMigration` and `ExtensionTableNames`. (#128)

- **2026-09-02 — The shipped example set is pinned, every default example conforms, and a dead-lettered
  job names a failure type that exists.** `ShippedExampleSetTest` holds both demo commands to one default
  list, requires every default to be a shipped directory with a manifest and the README the conformance
  gate checks, and requires every other example directory to be named as a reasoned exclusion, so an
  extraction can no longer drop an example without a test saying so. The announcements example gains the
  README it lacked, so `extension:conformance` reports it conforming like its siblings. The job queue wrote
  `Kumwe\App\Application\Automation\ExpiredJobLease` into a failed-job row when a final worker lease
  expired, a class that never existed; it now exists, documented as the failure the queue records when a
  lease lapses on the last attempt, and the row names it by `::class`. (#128)

- **2026-09-02 — The asset-inspection deployment acceptance emits the profile checksum it verified instead
  of dereferencing a variable the extraction removed.** The SDK migration replaced
  `InspectionPolicyProfile::fromPackage()` with a request loader that pins the profile's canonical checksum,
  but the policy step's evidence document still read `$profile->checksum()`, so the "Complete deployment
  acceptance" job failed on every engine with "Call to a member function checksum() on null" the moment the
  merge reached `master`. The document now carries the pinned checksum the loader already proved equal to the
  file. (#128)

- **2026-09-02 — A migration pass leaves every application table on one collation, whatever the server
  defaulted to.** DBAL writes `DEFAULT CHARACTER SET utf8mb4` and no `COLLATE` for a table it creates
  through a schema configuration, which MariaDB and MySQL resolve to the *character set's* default
  collation, while a table created without the clause inherits the *database's* default. The two agree on
  the `mariadb:lts` and `mysql:8.4` images with a database the image created, which is why every CI lane
  was green, and disagree the moment an operator creates the database with the explicit
  `COLLATE utf8mb4_unicode_ci` the restore runbook shows, or runs a server with a `collation-server` of
  its own: eighteen tables, `kumwe_sites` among them, landed on `utf8mb4_general_ci` while
  `kumwe_api_tokens` and the rest took `utf8mb4_unicode_ci`, and every bearer-token surface — the REST
  API, MCP, every token-authenticated console command — failed with "Illegal mix of collations". The
  sandbox bootstrap had been papering over exactly this with a normalizer of its own. The repair now ships
  in the product: `SchemaCollationConvergence` runs inside `MigrationRunner` after the plan, still under
  the migration lock, converts every prefixed table whose own or column collation disagrees with the
  database default, drops and faithfully re-creates the foreign keys that would block the conversion, and
  `database:migrate` names each converted table. A consistent schema is read twice and left untouched, and
  PostgreSQL is never touched. `SchemaCollationConvergenceIntegrationTest` plants the split with a foreign
  key across it and proves the conversion, the surviving key, the now-legal join and the no-op second pass
  on MariaDB, and the no-op on PostgreSQL. (#128)

- **2026-09-02 — The audit-listener example is shipped again, on the SDK contract.** The library adoption
  deleted `examples/extensions/audit-listener` and dropped it from both demo commands' default set while
  `docs/demonstration.md` and `tools/production-demo.sh` kept promising it, so `demo:install-examples
  --extensions=audit-listener` refused with "not shipped". The SDK retired the imperative runtime event
  registrar the old plugin used, so the example is rebuilt as the smallest schema-4 component: one
  manifest-declared `domain_listeners` entry on the platform's `core.business_record.mutated` event, bound
  to an executable `MutationAuditListener` through the canonical registrar, with a bounded in-memory
  ledger. It builds, inspects and passes conformance with no findings, installs and activates through the
  signed pipeline, and is back in the default example set of `demo:install` and `demo:install-examples`. (#128)

- **2026-09-02 — The console bootstrap's recovery list names the theme recovery command correctly.**
  `bootstrap/console.php` listed `administrator:theme:recover`, a name no command answers to, so the real
  break-glass command `theme:administrator:recover` booted the full container instead of the reduced
  recovery one — the file's own reason for existing — and would have failed in exactly the incident it is
  for. `ConsoleRecoveryBootstrapTest` now holds every entry of that list to the retained CLI contract. (#128)

- **2026-09-02 — Three administrator and MCP requests answer with the status they mean instead of a 500.**
  A signed-in administrator's mistyped URL matched the public site's catch-all route, which declares no
  administrator policy, and `AdministratorAuthorizationMiddleware` reported that as an undeclared screen;
  it now forwards a match outside the administrator tree to the not-found answer. A sign-in posted with an
  empty or over-long address escaped the gateway's address validation as an internal error; the form now
  answers it at 401 exactly as a wrong password. And `OPTIONS /mcp`, the unauthenticated CORS preflight
  that runs no bearer middleware by design, was refused by the handler as unauthenticated; it is now
  answered with the transport's own allowances before any principal is required. (#128)

- **2026-09-02 — Documentation rewritten during the extraction cites files that exist.** The
  content-translation guide pointed at an SDK pin that was never published and at two App tests the
  adoption deleted; it now names the association pin, the SDK contract generations and the library suite
  that carries those proofs. The qualification gap matrix's evidence paths for the signature document and
  the event envelope point at their SDK homes. (#128)

- **2026-09-02 — An installation configured before the admission-mode rename boots again.** The library
  adoption reduced `EXTENSIONS_CONFORMANCE_ADMISSION` from `enforce`, `warn` and `off` to `scan` and `off`
  and rewrote `.env.example` in the same commit, so every fresh-install lane passed while every existing
  `.env` — written from the example that shipped `enforce` as its default — stopped `bin/kumwe database:migrate`
  and every other command at configuration time with "must be scan or off". `ConfigurationFactory` now reads
  `enforce` and `warn` as `scan`, the mode that keeps their meaning now that mandatory package evidence
  fails closed in every mode and authoring findings are always advisory; an undocumented spelling still
  refuses, because a typo must not select a default. `ShippedDotenvCompatibilityTest` pins the proof the
  suite lacked: the current `.env.example` and the Gate A example, kept byte for byte as a fixture, must
  both produce a configuration through the factory with no process overlay, so a variable whose vocabulary
  moves has to keep reading the spellings it once documented. The agent bootstrap no longer treats a
  present `vendor/` as current: a snapshot carrying a tree from an older lockfile lacked the service manager
  and the three libraries, so schema preparation failed before the first test; the script now reinstalls
  when Composer's dry run reports drift. (#128)

- **2026-09-01 — The administrator extensions screen renders composition-only extensions instead of failing
  with HTTP 500.** The canonical SDK contribution graph carries only the sections a manifest declares, so a
  schema-6 package with no administrator section reached the extensions screen without one and the screen
  failed; the browser lanes saw it on every asset-inspection and Horizon listing. `DoctrineExtensionManager`'s
  live diagnostics projection now fills every section the screen reads, empty when undeclared, before stamping
  its live flags, and the generation-lifecycle walk and the manifest-six preview-renderer proof both render
  the real extensions screen against their fresh kernel. (#124)

- **The Studio host now consumes one release coordinate instead of four copied literals.** PHP bootstrap,
  the Vite build assertion and localization generation all derive from the canonical vendored
  `studio-release.json`; the release and corpus verifiers additionally recompute the exact corpus-manifest
  digest, and the accountable journey pins the canonical record bytes. Browser qualification registers and
  awaits the exact cancellation response and the complete post-reload session, palette, status and lifecycle
  readiness, accepts the session endpoint's canonical creation status, and establishes an editable draft before
  every mutating or private-authority journey. This removes the cross-engine lifecycle and boundary races without
  weakening measured-canvas assertions. (#114)

- **Studio preview authoring is deterministic across supported browser engines.** Live preview cancellation
  retains the authenticated page request identity and enables unload keepalive only while the document is
  actually leaving, with cancelled-navigation and back/forward-cache restoration resetting that state. The
  browser qualification now reveals both drag endpoints inside the preview document before refreshing
  stage-owned geometry, and its fixed, testing-only real redirect seam lets WebKit exercise the same-origin
  path refusal without an unsupported synthetic redirect. The signed-renderer withdrawal journey verifies the
  public route's exact 500 status and marker-free response bytes through the browser context request boundary,
  avoiding Firefox's engine-owned `NS_ERROR_NET_ERROR_RESPONSE` page while preserving the fail-closed product
  assertion. Lifecycle qualification now waits for the accepted reload, ready state and enabled symmetric action
  before issuing a follow-up mutation, so Firefox cannot race a correctly disabled pre-navigation control.
  Canvas qualification also establishes a shared visible inline band for raw-pointer endpoints and compares preview
  layout against stroke-independent SVG fill geometry, preserving the two-pixel alignment contract on Firefox. (#114)

- **Generic Studio artifact saves cannot bypass lifecycle or Blueprint identity controls.** Save now updates
  only an existing draft at its exact resource/version coordinate, preserves lifecycle status, and refuses
  published heads until the canonical publish-permissioned unpublish operation returns them to draft, while
  retired heads remain closed. Blueprint owner, model and dependency locks remain canonical-byte immutable
  across saves, closing alternate-version and lock-drift paths without widening AP-4 into Content writes or
  rendering behavior.

- **The operator checklist maps the gates only CI can measure.** The first tiered run of the new
  pull-request lane surfaced three traps no document named: the coverage ratchet's changed-line and
  changed-refusal floors credit only tests that name the executed class in `#[CoversClass]` and are
  measurable only on the canonical CI leg; persistence SQL validated on one engine can still be
  refused by another (PostgreSQL types a `CASE` by its THEN operands where MariaDB coerces); and a
  PHP 8.4 sandbox cannot see 8.5-only deprecations that fail both PHPStan and the suite in CI. The
  "Add a PHP class" recipe and the watcher table now carry all three, plus the browser-baseline
  refresh rule for visible UI changes, so the flow of what must line up is written where every
  change starts instead of being discovered forty minutes into a red build.

- **The contributor documentation no longer contradicts the gates it describes.** `README.md`'s
  testing section carried a drifted ten-item copy of the `composer qa` member set that omitted the
  gates that most often fail pushes (the baseline, documentation, contract and roadmap checks); it
  now names `docs/quality/contract.json` and `AGENTS.md` section 6 as the only authorities instead
  of restating them. `CONTRIBUTING.md` wrongly required a PostgreSQL service for integration tests
  (the development Compose file defaults to MariaDB) and restated an incomplete
  `baseline:record` trigger list; both now point at the watcher table. `CLAUDE.md`'s trigger
  summary gained the lockfile and OpenAPI triggers it was missing. `AGENTS.md`'s "Add an HTTP
  route" recipe now includes the surface-inventory registration that `composer interface:programme`
  enforces and the handler's own class recipe, the watcher table carries the corresponding row, and
  the CLI watcher row no longer undercounts where the pinned command count lives.

- **Browser lifecycle tests now converge on the authenticated runtime without hiding product failures.** A
  shared bounded helper probes readiness with the Playwright context's authenticated request client, accepts
  only HTTP 200 or the exact no-store 503 convergence contract, disposes every probe response, and then makes
  one browser navigation that must return 200. Administrator, portal and theme lifecycles therefore survive
  Firefox's `NS_ERROR_NET_ERROR_RESPONSE` during expected container convergence without broad transport
  retries or relaxed assertions; candidate
  [`798f896b`](https://github.com/kumwe/app/commit/798f896b55da76f19cb4d01aee05cf74196bb44b)
  passed every Chromium, Firefox and WebKit lifecycle on first attempt. (#102)
- **The Firefox/WebKit breadth gate now tests the contracts each browser can actually render.** A generated
  business denial negotiates a themed, non-enumerating HTML 403 for browser navigation while preserving the
  problem document for machine callers; one-column stacks explicitly clamp their track, access form controls
  cannot inherit a native select's intrinsic width, the desktop shell cannot resurrect its responsive
  navigation toggle through a later selector, and the two
  user-task links carry 32-pixel targets. The
  presentation journey proves dark colour and reduced motion before entering Firefox's independent forced-colour
  phase, and WebKit's dark gallery is loaded under the emulation instead of scanning its stale live-override
  state. Environment-owned baseline refreshes cover both source-language Chromium projects and every locale-owned
  RTL project together, so a shared stylesheet change cannot update one visual axis while leaving another stale.
  Closes `V2-QA-012` and `V2-QA-013`; the real-Safari live-switch question in `V2-QA-014` remains open. (#102)
- **A plaintext origin no longer tells the browser to upgrade what it cannot serve.**
  `upgrade-insecure-requests` sat in the Content-Security-Policy unconditionally while HSTS, immediately
  below it, was already gated on production HTTPS. On an origin not served over TLS the directive hardens
  nothing — the document itself arrived in the clear — while instructing the browser to fetch every
  stylesheet and script, and to submit every form, over an `https://` port nothing is listening on.
  Chromium and Firefox mask that by exempting loopback; WebKit honours it, so an HTTP-only deployment lost
  its stylesheets, its scripts and its ability to sign anyone in, in Safari and in no other browser. The
  directive is now gated on the transport, separately from HSTS, because the two answer different
  questions: HSTS asks whether this deployment may pin a browser to TLS for a year, which only production
  should answer yes to, while the upgrade directive asks only whether this response arrived over TLS — so
  a staging site served over HTTPS now receives it where it previously did not. The whole 66-test security
  suite passed with the directive wrong; `SecurityHeadersTest` and `SecurityMiddlewareTest` now pin both
  directions, at the policy builder and at the middleware.
- **The rich-text editor no longer discards text Firefox and WebKit leave unwrapped.** `toSource()` walked
  `editor.children`, so only elements were serialized. Chromium wraps text typed into an empty
  `contenteditable` in a `<div>`; Firefox and WebKit leave a bare text node, which was dropped. The
  `required` backing textarea stayed empty, native validation refused the form, and Create draft did
  nothing while the editor looked full — no page could be authored in Safari or Firefox. The walk is now
  over `childNodes`, gathering runs of top-level inline nodes into the one implicit block they render as.
  `data-entry-integrity.spec.ts` forces that DOM shape rather than relying on an engine to produce it, so
  every engine exercises the path.
- **The reproducible baseline describes the tree it was generated from, and its remedy runs.** The record
  landed describing commit `c07e1798` while sitting on a tree five commits later: both branches were cut
  from that base, and the rebase moved the document rather than the figures inside it. Because the check
  carries the `local`, `ci`, `nightly` and `release` cadences, one stale document failed the merge lane,
  failed the nightly and would have failed the release — which is why nothing shipped. Provenance is no
  longer inherited: `--emit` demands `--commit` and `--recorded-at` and validates their shape, and writes
  through a temporary file and a rename so an interrupted run cannot truncate the record. `composer
  baseline:record` supplies both from the checkout, and a test asks the generator what it requires and
  holds that published command to it — the tool had tests, its entry point had none, which is how a
  remedy that exits immediately was published as the fix.
- **The local gate asks the question the merge lane asks.** CI runs the documentation formatter over
  `src/` and refuses any diff it produces, but no local check did, so a complete block in non-canonical
  alignment passed a full local gate and failed CI on whitespace. The formatter's `--dry-run` could not
  fail anything because its report always returned zero; it now exits non-zero when a check run still
  finds work, and `composer docs:format:check` runs inside `composer qa`.
- **Pre-releases are published as pre-releases.** `gh release create` was called without `--prerelease`
  or `--latest`, and GitHub infers neither from the tag: the API defaults `prerelease` to false and marks
  whatever it published most recently as Latest, so the first `v2.0.0-alpha.N` would have sat on the
  repository front page as the release to download. Both flags are now stated explicitly, in both
  directions, decided by the same stability test that already governs the moving `latest`, `2` and minor
  image tags.
- **Deployment acceptance no longer re-contacts the registry on every lifecycle recreation.** The
  acceptance environment set `KUMWE_INFRASTRUCTURE_PULL_POLICY=always`, so each of the several
  `--force-recreate` cycles that prove restart, persistence and restore asked Docker Hub for the manifest
  again; a single registry `500` mid-run failed all three database jobs within two seconds of each other,
  after the images had already been pulled successfully. The images are now fetched once, with bounded
  retries, and the deployment uses what is cached. Only the acceptance environment overrides the policy —
  `compose.production.yaml` keeps `always`, which is the right default for a real deployment.
- **Two browser projects no longer compete for one approval identity.** The maker-checker journey keyed
  its fixture accounts on device family, so `desktop-firefox` and `desktop-webkit` — which the nightly
  runs in one invocation, against one server and one database seeded once before the run — both resolved
  to the same maker and approver. TOTP enrollment is a once-per-account operation, so whichever project
  ran second could not enroll; the refused enrollment renders a notice and no provisioning element, and
  the journey waited on an element that would never appear until its ninety-second budget expired,
  reporting nothing but a bare timeout. Identities are keyed on the project now and seeded per project,
  the device family stays a separate value because it still drives mobile emulation, and the enrollment
  helper asserts the panel it needs so a refusal fails in ten seconds naming the step that refused. The
  matrix itself is now declared once, in `tests/Browser/projects.json`: the Playwright configuration maps
  over it and the seeder provisions from it, so a project cannot exist in one and be missing from the
  other, and an unknown project throws rather than running with no emulation at all. Both sides read that
  file through a validating reader — `tests/Browser/manifest.mjs` and its PHP twin
  `BrowserProjectManifest` — which refuses rather than interprets a `specs` outside `all | right-to-left`,
  a duplicated or blank project name, a retry budget that is not a whole number from 0 to 100, an empty
  project list or a document that is not an object. Unchecked reads were the whole exposure: Playwright
  treated every `specs` that was not `right-to-left` as "run everything" while the seeder provisioned only
  for exactly `all`, so one misspelled word ran the maker-checker journey on a project with no approval
  identity and every guard stayed green. The two readers are held to one corpus,
  `tests/Browser/manifest-cases.json`, which carries **raw sources** rather than structured documents, and
  states the reading each accepted document must produce as well as the verdict. That is what caught the
  last disagreement: a budget written `1.0` was accepted by JavaScript, where `Number.isInteger` sees the
  parsed value, and refused by PHP, where `json_decode` yields a float — two hand-copied case lists could
  not see it. The rule is now keyed on the value rather than the spelling, because JSON has one number
  type and only one of the two languages can tell `1` from `1.0` after parsing; the ceiling of 100 is what
  keeps that agreement total, since every magnitude the two read differently, or hold at different
  precision, sits above it and is refused before the difference can be observed.
- **A correct credential that may not administer is now refused on the sign-in form, not as a raw
  document.** `/administrator/login` is exempt from both the session and the authorization middleware,
  so the themed denial those render could never fire for it and the handler owns every refusal on that
  route. It answered two of the three — a wrong credential at 401 and a throttled address at 429 — while
  the session store's documented `AuthorizationDenied` escaped uncaught and became a bare
  `application/problem+json` body. Firefox will not render that as a page, so an identity that
  authenticated but lacked `administrator.access` was handed the browser's own "there's a problem with
  this site" screen instead of being told anything. The refusal is now caught and re-renders the form at
  403, with the status and the absent cookie unchanged.
- **Every behavioural test now names what it exercises.** The forty-three classes that carried
  `#[CoversNothing]` while driving real behaviour gained 124 honest attributions across 67 classes, emptying
  the pending list so only the reasoned allowlist remains. Global line coverage rose from 55.44% to 64.51%
  without a single new test — the measurement had simply been lying by omission. The `changed-branch-floor`,
  declared but unenforceable because the canonical leg reports lines and not branches, is replaced by an
  enforced `changed-refusal-floor`: at least 80% of the refusal lines a change adds must have executed, which
  is line-level proof that the refusing branch ran. Closes `V2-QA-001`. (#92)
- **The last three layering leaks are closed and enforced.** The idempotency middlewares ask an application
  port instead of writing the store from the HTTP layer, business-surface application code hands a rendering
  contract to a presenter instead of rendering, and Twig theme validation sits behind an application port
  instead of being imported inward. Recorded dependency exemptions fall from 115 to 99, and three boundary
  tests make each seam a build failure rather than a review hope. Completes `P3-C` and Gate A criterion 6. (#92)
- **Integration tests read configuration where the container reads it.** Four suites that called raw
  `getenv()` — invisible under `.env` configuration — resolved their fallbacks to shared defaults, letting
  unrelated local installations meet each other's residue in one Redis namespace. They now resolve through
  `ApplicationConfiguration` and `Environment`, and a new boundary test refuses a named raw read, so the
  pattern cannot return. Closes `V2-QA-009`. (#92)
- **The configuration boundary now covers the functional tree, not just the integration one.** The kernel
  case read `getenv()` directly and fell back to a PostgreSQL at `127.0.0.1:5432`, so on a host running any
  other engine three of its tests errored on a server nothing had asked for. It resolves through
  `Environment` like the integration suite does, and the boundary test scans `tests/Functional` beside
  `tests/Integration` — a gate that reads one tree only leaves the defect free to live in the next one.
  Closes `V2-QA-011`. (#92)
- **`demo:export-profile` can no longer write a package its own catalogue refuses.** The projector
  answered with every published business definition the site owns, while the catalogue and the installer
  both bound an `installation_order` at 64. Past that bound the command wrote the whole package, printed
  `Exported 84 content entries and 2 menus as profile …`, re-validated what it had just written, and
  failed with `definition order is invalid` — an operator left holding a half-announced export and a
  message about a file they had not written. The command now asks before it writes: on a site publishing
  124 definitions it says `The site publishes 124 business definitions, which exceeds the demo-profile
  envelope of 64; nothing was written.`, and nothing is. The envelope's five bounds — 64 definitions, 64
  staff, 32 organizations, 16 members, 32 roles — were five literals repeated across three classes and
  are now named constants on the catalogue that the installer and the exporter both read, so the writer
  and the reader cannot drift apart again. Recorded in `docs/demo-profiles.md`; the residual projection
  bound is `V2-DEMO-001`. (#92)
- **The console wording cases stop depending on what the rest of the suite installed.** Six assertions in
  the new functional case were pinned to accumulated fixture state rather than to behaviour, and one of
  them could hang a lane: the queue worker was driven with `--max-jobs=1` and no `--once`, and that budget
  only stops the loop *after* a job has been handled, so on an idle queue the worker sleeps and loops
  forever — the case terminated only while some other test happened to leave work behind. It asks for
  `--once` now. The rest pin the shape of the catalogue sentence instead of a count the installation
  decides, and the two branches that were guarded behind an exit status are both asserted, so a failure
  is loud rather than skipped. A magic `assertCount(44, …)` over the test's own hardcoded list is replaced
  by a check that derives the command set from the source tree, so a new command cannot escape the
  name and description contracts by being left out of a list. (#92)


- **Every non-primary index name a second prefixed installation could collide with is isolated on
  PostgreSQL.** Index names there are schema-global, and the shipped self-checksumming migrations create
  around a hundred and ten literals, so a second prefixed core plan entering an occupied schema failed at
  `CREATE UNIQUE INDEX`. The index-isolation migration renames what they created to the published
  stem-plus-digest derivation — the shipped bytes stay immutable, already-unique names are left alone, and
  the rename is a no-op where names are table-scoped. An integration test installs two complete prefixed
  core schemas into one PostgreSQL schema and proves every non-primary index isolated. Closes `V2-DB-004`. (#90)
- **The nightly's migration test no longer gambles on suite order.** Its upgrade-path epoch claim looked
  the legacy administrator up by email, and the harness re-bootstraps an administrator under the same email
  when mid-suite authentication fails, so whichever order let a destructive test run first failed the claim
  against a legitimately fresh user. The claim is now addressed to the seeded row's fixed identifier. The
  same file's scratch-schema prefixes came from a UUIDv7's leading characters — timestamp bits identical
  for about sixty-five seconds — and now come from random bytes, so quick re-runs stop colliding with their
  predecessor's leftovers. (#90)
- **CI stopped failing on other people's infrastructure.** Every installing workflow now restores a
  Composer download cache keyed on the lock file, after a lane died mid-install on a rate-limited archive
  host; the development image's sources are pinned by digest — the last mutable tags in the repository —
  and the compose lane builds the image explicitly with backed-off retries, after a lane died on a registry
  gateway error. (#90)


- **A caught nested transaction failure can no longer commit the enclosing transaction.** Only the outermost
  application scope now opens the physical DBAL transaction; inner scopes join it and retain the first failure as
  the reason the whole unit must roll back even when application code catches that exception. Rollback hooks still
  run for every discarded frame, a failing hook cannot replace the operation's failure or prevent later hooks, and
  an independent exception raised by the outer operation remains the caller-visible one. The behavior is exercised
  against the configured relational engine with real writes, not a connection double. (`b539161`)
- **Converted values are accepted only when their exact provenance reconstructs.** Money and quantity imports now
  require exactly `mode`, integer `scale` and `unrounded_amount` in the rounding document, refuse extra or missing
  members, and require the declared scale to agree with the converted amount. Report values no longer pass on JSON
  grammar alone: they rebuild `ConvertedMoneyValue` or `ConvertedQuantityValue`, which rechecks the arithmetic,
  denomination, factor or rate, instant and rounding invariants before the cell is accepted. (`b539161`)
- **A language selected from the site root now remains the selected language.** The language-neutral `/` route
  gives every published alternate an explicit `?locale=` address, including the nominated homepage's own locale,
  and names the rendered locale's explicit address as canonical. Following a selector or `hreflang` link therefore
  cannot re-enter `/` and negotiate back to the reader's previous preference. Closes `V2-LNG-011`. (`b539161`)
- **Translation groups enforce their site boundary and member ceiling under concurrency.** An append-only migration
  (`20260819020000_translation_group_site_ownership`) adds a composite group-owner foreign key and a direct
  owner-pair `CHECK` without changing the published multilingual migration. Group declaration locks and verifies
  the durable owner and fallback; attachment locks the group and refuses a sixty-fifth live member; restore repeats
  that check before making a deleted member live again. Existing cross-site contradictions stop the migration
  instead of being normalized into apparently valid ownership. MariaDB, MySQL and PostgreSQL must each report the
  exact nullable-pair and same-site predicate as enforced and validated. The composite relationship uses
  `ON DELETE RESTRICT`; once that replacement is proved, the migration removes the exact overlapping one-column
  `SET NULL` predecessor. A group cannot be deleted until every member explicitly clears both group columns. No
  generated column or trigger is installed, and no trigger privilege is required. (`b539161`, `ffa4b14`,
  `e4aa755`)
- **Localized wording now stays coherent from storage to the rendered response.** Authenticated organization scope
  reaches early administrator and portal responses; locale-bearing content aligns interface language before
  rendering; generated business catalogues and forms resolve localized definition labels; and localized HTML and
  redirects carry `Content-Language`, adding `Accept-Language` to `Vary` only when a response is publicly cacheable.
  Catalogue memoization is bounded to one active locale unit of work, so a long-lived process cannot keep an
  administered override from an earlier request. (`b539161`)
- **The published foreign-key constraint-name migration has an upgrade-safe compatibility handoff.** Its
  original source bytes and ledger identity `20260818010000_schema_global_constraint_names` remain unchanged.
  A compatibility
  implementation occupies that plan slot for fresh or interrupted installations, while an already-applied row is
  accepted only when it carries the exact published checksum
  `0edbe48d080c481f70ba07e54b4de1d2e8852407d9eec4b11e3fb9a70f348d5a`; those installations skip the slot and reach
  the later append-only `20260820010000_constraint_name_isolation_portability` migration. MySQL-family recovery
  grants the same narrow exception only to that ID/checksum pair. A different checksum, a wrong-shape replay target
  or an overlapping initialized neighbour fails closed, and an interruption after create-before-drop resumes
  without losing the referential action. No down migration rewrites the released ledger. This handoff repairs
  foreign-key names only; PostgreSQL schema-wide non-primary index names remain open as `V2-DB-004`. (`b539161`)
- **MySQL and MariaDB foreign-key names are isolated between prefixed installations.** A foreign-key constraint
  name is schema-global on MySQL and MariaDB rather than scoped to its table. Fifty-four of the shipped
  constraints were named literally. All fifty-four are distinct, so nothing collided inside one installation
  and every one of them collided with a second prefixed installation beside it: building the second installation's
  `organizations` table failed outright, with errno 121 on MariaDB. Every installed foreign key is now
  renamed to a name derived from the physical table it sits on, which is what makes it differ between two
  installations, and from the original name, which is what keeps two constraints on one table apart after
  the longest of them has been trimmed to fit the portable sixty-three-byte identifier limit. A name already
  carrying the derived digest suffix is left exactly as it is, so the rename is a no-operation on every
  upgrade after the first. The collision is reproduced with the exact `sites` and `organizations` foreign-key
  shape. Two prefixes are repaired in order on MariaDB and MySQL; PostgreSQL exercises the same focused
  foreign-key shape and replay contract with test-only isolated index names so its unrelated index namespace
  does not mask that evidence. This is not proof that two complete prefixed migration plans coexist in one
  PostgreSQL schema; `V2-DB-004` records the append-only index-name work still required. (`c70da04`, `b539161`,
  `ffa4b14`)
- **On the MySQL family, the rename is the operation that frees the old foreign-key names for a later prefixed
  installation.** The literal names cannot be changed where they are written: the core migrations publish their
  own file digests as an immutability contract, and editing their bytes would break the upgrade path of
  every installed site. So they go on creating the literal names and the rename runs afterwards and renames
  what they created — which is exactly what releases each name for the next installation to take. The
  consequence is worth knowing rather than discovering: **with respect to the MySQL/MariaDB foreign-key
  namespace, a later prefixed installation can proceed only once the earlier one has migrated past this
  point.** That is not a whole-schema coexistence guarantee. On PostgreSQL, where a foreign-key constraint name
  was never schema-global, the operation retains the same focused validation; on the MySQL family, which has no
  rename for a foreign key, each constraint is created under its new name and the old one is then dropped, with
  the referential action, match type and deferrability
  carried across explicitly. That order is deliberate: where DDL commits implicitly, an interruption between
  the two statements leaves the table holding both names, which enforces the same rule twice and loses
  nothing, where dropping first would have left it holding neither. (`c70da04`, `e161425`, `b539161`)
- **A refused save no longer empties the form.** Filling in a long document and losing every value to a
  failure you could have recovered from was the single most expensive defect an operator met. Two gaps
  caused it. On the generated administrator and portal surfaces a validation failure already came back with
  the submitted values, but a stale-version conflict — somebody else saved the same record while your form
  was open — escaped as an error page and took the whole submission with it. The CMS content editor had no
  retention at all: both failures discarded the work, and for a new draft that meant everything typed was
  gone, because the draft existed nowhere else. Both surfaces now return you to your own form with every
  value still in it. A conflict says plainly that the record changed underneath you, names the version it
  is at now, and offers three things you can actually do: save again to apply your entries on top of that
  newer version, reload it and start over, or look at what changed first. Nothing is written on the way
  through, so the newer record is never silently overwritten and a hundred-line document loses no line. A
  write-only secret is still never echoed back, so that one field is re-entered; everything else survives.
  Operations that carry nothing typed — archive, delete, restore, an action confirmation — still fail
  closed, because there is nothing to keep and no form to return to. (`4a3ad85`)
- **Record history could show one record's past under a reference another record had since taken over.** A
  business reference such as an invoice number can be used again once the record holding it has been deleted
  outright, and the revision log deliberately outlives the record, so a single reference can name more than
  one past. Kumwe already refused to merge two of them — but it decided how many there were by looking only
  at the page of history it had just read. Ask for a small page, or page back far enough, and the second
  record simply was not in view: the request succeeded and returned one record's history under a reference
  two records had held, with nothing on the page to say so. How many records a reference covers is now
  settled across the whole site and organization before any page is read, so the refusal is the same answer
  at every page size and every position in the log. Paging itself was tightened at the same time, because
  two records under one reference number their versions independently: history is now ordered on a key that
  can never tie, and a page boundary that lands between two entries agreeing on version repeats neither and
  skips neither. A new index carries that order, so the stricter guarantee costs a history page nothing.
  (`57e79a0`)
- **A freshly created database could refuse to schedule work.** Site ownership is recorded in its own table,
  and the column naming the owning site was never tied to the site table's own identifier column. On MariaDB
  and MySQL that tie is only ever enforced by a foreign key — one a partially recovered installation may
  never have gained — and on PostgreSQL it is not enforced at all. Where a database had been created with a
  different default text collation, the two columns compared under different rules and the engine refused
  the comparison outright, which took the scheduler's dispatch pass down with it: due work simply stopped
  being queued. The ownership column now copies the site identifier's exact character definition, the
  migration proves the two agree before it finishes, and the check runs on every supported engine rather
  than being a MariaDB special case. The same repair gives the ownership constraint the per-installation
  name the recovery path already used, so the two routes that create it no longer disagree about what it is
  called. (`57e79a0`)
- **A broken record rule said only that "one or more submitted fields are unavailable".** A rule spanning
  several fields is named and carries wording its author wrote for an operator to read, and none of that
  reached anybody: because a rule's name is not a field name, every breach of one was collapsed into the
  same generic refusal used to avoid disclosing a field the caller may not see. A rule describes a rule, not
  a value, so it discloses nothing about the record and is now reported as itself — the operator is told
  which rule was broken, in the words the definition author chose. (`772e523`)
- **Two exact decimals could be judged unequal for spelling the same number differently.** An equality or
  set-membership test between decimal values compared their text, while an ordering test compared their
  value, so a figure stored at one scale disagreed with the same figure at another — `30.750` was not
  `30.75`, and only the greater-or-equal spelling of the same comparison got it right. Both now compare by
  value. (`772e523`)
- **The deployment drills could not load their own classes in the production image.** Production acceptance died
  on all three engines inside the restore drill's seed leg with a class-not-found error: the image installs with
  `--no-dev` and dumps an authoritative classmap, so nothing under the test namespace is loadable there even
  though CI bind-mounts the support directory. The entry point compensated with a hand-maintained require list,
  and the wave that made the drill decrypt, authenticate and execute added three classes to the harness without
  adding three lines to that list. Every cheaper job kept passing because they all run under the development
  autoloader, which is exactly why the break surfaced only after a full deployment was already up and why it
  looked engine-specific when it never was. The mapping is registered with the loader instead, and an
  architecture assertion refuses a hand-maintained list that grows back. (`26a7b39`)
- **A site-wide record-key rotation stranded the shared fixture database.** A rotation pass covers every
  installation of the caller's site, so the rotation drill moved every stored `core.secret` envelope in the test
  database onto a key whose material existed only inside that test's own process and was dropped at teardown.
  Everything that ran afterwards inherited a database whose secrets nothing could open — invisible while the
  backup drill only hashed ciphertext, because a stranded envelope hashes exactly like a readable one. The
  rotation is now rolled back through the same supported operation in the other direction, which is also
  precisely the shape an operator needs to abandon a rotation part way through. (`3fdb4e9`)
- **Package admission charged the memory ceiling per entry.** The zip reader asked the extension for the maximum
  entry size plus one on every entry, meaning to bound what an under-reporting header could make the process
  expand — but the call allocates exactly the length it is asked for and only shortens the returned string's
  recorded length when the entry turns out smaller, so every entry cost a full 64 MiB that was never given back.
  Admission holds two entries for the whole scan by design, so three files weighing a few kilobytes cost 192 MiB
  against a 256 MiB image limit and deployment acceptance failed identically on all three engines. Entries are
  now streamed in 256 KiB chunks with the ceiling enforced against the bytes that actually come out of the
  decompressor — a stronger guarantee than the old read, since an over-reporting header is now refused before a
  stream is opened at all. A regression test pins the cost: retaining every entry of a seven-entry package must
  stay under 8 MiB, where the previous implementation reports 448 MiB. (`cfaf840`)
- **The sequence counter column was a reserved word on MySQL.** `last_value` is reserved for the window function
  of that name, and Doctrine quotes reserved identifiers in generated DDL, so the table was created without
  complaint on every engine — but the statements the allocator writes by hand carry the column as bare SQL text,
  and the first record to ask for a number took the whole profile install down with a parse error before PHPUnit
  even started. Renaming it `current_value` keeps the property every other column in the schema already had.
  (`38e431c`)
- **One held outbox row stalled every other dispatcher.** The claim selects with `FOR UPDATE SKIP LOCKED`
  precisely so two dispatchers route around each other, but the quarantine pass running just before it in the
  same transaction compared two columns, which the claim index cannot serve, so the engine scanned the table and
  locked what it read. The pass now gathers its candidates under the same `SKIP LOCKED` clause, bounded, and
  updates them by primary key. This was invisible until the store was tested somewhere other than an in-memory
  database, where the lock clause compiles to an empty string and every assertion about arbitration is vacuously
  true. (`6e0d2e2`)
- **A duplicate scheduler occurrence crashed the scheduler on PostgreSQL.** Two schedulers reaching the same
  occurrence is designed for — the unique index refuses the second insert, the refusal is swallowed, and the
  schedule still advances — and that is what happens on the other engines. On PostgreSQL a constraint violation
  marks the whole transaction aborted, so the swallowed duplicate left the schedule advance and every remaining
  schedule in that pass unable to run, the pass died, and the schedule re-emitted the same occurrence forever.
  The insert now runs inside a savepoint and the swallow rolls back only the refused insert. (`8bb8cc4`)
- **A replica-lease heartbeat inside the caller's transaction produced intermittent failures on MariaDB.** The
  lease row is shared by every process in a container, and the consumer dispatcher re-asserted the runtime
  generation inside the transaction that also ran the handler and settled the inbox — but that transaction's
  read view was already open, and snapshot isolation refuses a write against a row another transaction committed
  after the view opened. A peer renewing the very same lease microseconds earlier therefore turned the
  consumer's own bookkeeping into a read-conflict error, the event went back to pending behind a retry delay,
  and the drain loop exhausted its budget. The lease write is now suppressed while a caller's transaction is
  open — it was wrong inside regardless, because a rolled-back handler rolled the renewal back with it — and the
  row is claimed with one upsert per driver rather than an update, an insert and an update. (`155a8a9`)
- **Four of five demonstration staff roles hit a raw 403 immediately after signing in.** Sign-in always
  redirected to the dashboard, but the dashboard route demanded `content.read`. The route and the navigation
  entry now require only `administrator.access`, the handler skips the content reads for an actor without
  `content.read` and degrades to its permission-reduced state, and a denied browser navigation is answered with
  the themed access-denied page — the shell, the actor's own capability-filtered navigation, a notice naming the
  missing capability, and a way back — while mutations, API accepts and callers without a renderer keep the
  problem document unchanged. (`f274e35`)
- **The business-security screen was unreachable for the entire demonstration cast,** because no role held the
  capability. Declared roles are now reconciled additively on every provisioning run, so a manifest revision can
  reach an already provisioned deployment, while grants an operator added by hand are left untouched.
  (`d3fd648`)
- **The Export control on generated list views promised an artifact that never existed** — it was a plain link
  that re-rendered the same list under the export disclosure policy, with no job, no artifact and no download
  behind it. (`46c6b6c`)
- **Portal screenshots were reproducible only on the machine that generated them,** because the portal left
  `code` on the generic `monospace` keyword while the administrator surface named a concrete family: a record
  identifier renders inline beside proportional text, so the line box grows to the union of both leaded boxes
  and the page height moved by two pixels between hosts. (`980cc79`)
- **Installing the append-only audit triggers made the platform uninstallable on managed MySQL services.** The
  privilege they need is withheld by default when binary logging is on, the exception escaped the migration, and
  `database:migrate` died. Trigger installation now reports a refusal as a state and the migration carries on,
  with the refusal recognised on driver error codes and SQLSTATEs rather than on message text or exception class
  — which is not a usable signal here, since one driver maps three refusals of a single kind onto two unrelated
  types. The degraded state is observed from the server's own catalog on every verification pass rather than
  remembered, so the answer stays true after a dump is restored onto a server that never accepted the triggers,
  after a DBA grants the missing privilege, and after someone drops them. (`ae4b92b`)
- **An audit tamper probe left its own tampering behind.** The harness proves refusal by performing the real
  update or delete and reporting whether it threw; on an unguarded server the statement succeeds, and the probe
  left it that way, so one probe mutated a row permanently and every later test in the class failed with a
  digest mismatch it had no part in. Both probes now put back what they wrote. (`f78bc3c`)
- **The step-up purpose digest committed the submitted plaintext password.** The purpose is a canonical digest
  of the change set, stored on the proof row and repeated in audit metadata, and the user-creation form's
  password was folded into it; every credential-bearing field is now stripped before the digest is taken, which
  keeps the payload binding and drops the offline-guessable commitment. (`4bc5c74`)
- **Three defects the failure drills exposed, fixed rather than accommodated.** The Redis boundary let the
  driver's own exception escape a wrapper whose every documented failure is a `RuntimeException`, and let a dead
  server turn a readiness question into a raised error instead of a not-ready answer. The settings cache turned
  its own outage into a failed public read, which is unnecessary when SQL is the source of truth; it now
  degrades and records why, while the sign-in budget keeps failing closed, because that asymmetry is the control
  rather than an inconsistency. The media and export write paths emitted a raw PHP diagnostic beside the typed
  refusal they already reported properly, one line per refused write on an unwritable volume. (`f8b856e`)
- **A severed database session does not crash a worker,** which the recovery posture had assumed it did: the
  driver converts the loss, closes the connection, and the next statement opens a new one, so the attempt is
  recorded on a fresh session and the process drains cleanly. Crash-to-exit is what happens when the server is
  gone rather than merely disturbed. Both are now stated and both are tested. (`f8b856e`)
- **The exported package overflowed the catalog's profile envelope on a well-populated database,** because the
  shared integration database accumulates published definitions from the whole suite; round-trip validation now
  writes a package filtered to the test's own definition and records. (`bd3ffa5`)
- **The branded theme broke the public menu the moment it was activated.** Its stylesheet flattened every nested
  list into the header's flex row so submenus rendered permanently expanded, it offered no small-viewport
  toggle, and it reused host-owned shell class names while the mandatory host stylesheet still loads — handing
  the shell over to rules the theme never wrote. The theme keeps rendering the host menu tree through the shared
  navigation macro so nested items, canonical hrefs and current state stay in lockstep with the platform, and
  every shell class now carries a theme prefix. (`fb6b41f`)
- **Seeding the six layout types silently changed the content editor's default type,** because the unqualified
  new-content route built its form from the first handle alphabetically; the core page type is now preferred
  whenever it exists and no type was requested. (`d10dc8b`)
- **The master-detail embed dropped the new per-item template and colour-scheme selects,** because the embed
  strips outer context and the new variables were not named in its context map. The sticky catalog aside also
  gained keyboard scrolling, and the subtle text token darkened to clear the contrast ratio on striped rows.
  (`80d20d6`)
- **Reference terms were set in small tinted pills that made section headings genuinely hard to read;** they now
  render at heading scale in full-contrast ink. (`7d6ef3f`)
- **`@throws` accuracy across the business modules turned up two real defects.** Entries had been invented — one
  exception was claimed seventeen times in a file whose code never names it — and one entry was incomplete,
  since the query compiler propagated an exception it did not declare, which made its caller's catch read as
  dead code. Fifty-three level-max errors surfaced on the first run of the pass. (`7a035e2`)
- **The integration suite was not re-runnable against a reused database.** Three consecutive runs now report
  identical results; previously the second run collapsed from 1,318 to 280 assertions as roughly sixty-three
  tests silently stopped verifying anything. (`115cc3c`)
- **The business-record idempotency ledger grew unbounded,** because its purger was container-registered with no
  caller; it is now an installation-global job with a seeded schedule, mirroring the core ledger. (`115cc3c`)
- **The access-control service bypassed the user aggregate's status lifecycle** by writing the status directly.
  (`115cc3c`)
- **Interface tabs lost their state after a cancelled submission,** and public navigation stopped working
  entirely without JavaScript. (`89cf4b8`, `e4df5c9`, `9ed6b2b`)
- **Schema operation states rendered outside their embed scope,** and export status controls were not
  touch-safe. (`15108eb`, `7d30ed6`)
- **Residual browser accessibility and lifecycle regressions,** including authenticator enrollment rendering,
  responsive access management, zoomed content-model controls, zoomed layout checks and the guest portal's
  layout and security icon. (`b9280fb`, `3c565b4`, `dae61ff`, `fd3cc06`, `ea55b99`, `7984fee`, `ee6d8b6`,
  `1837af2`)
- **Idempotent replay returned a reconstructed body rather than the exact original,** and a crashed job or
  in-flight idempotent request had no recovery path. (`25446d7`, `4da98bf`)
- **Requests from trusted proxies were not normalised,** so client address and scheme could be read from the
  wrong hop. (`3d8eab3`)
- **Queued export policy was not rehydrated when the job ran,** so a queued export could be generated under a
  policy snapshot it no longer held. (`b76ca76`)
- **Export authority failures were indistinguishable from ordinary failures,** and contribution and export
  runtime fences did not hold across a generation change. (`0969ae9`, `f34d27b`)
- **Production route caches were shared between installations in one image.** (`79431eb`)
- **Contributed page failures were swallowed by deployment acceptance instead of being captured.** (`412160e`)
- **Cross-engine portability defects on freshly created databases** — collations not copied from the site
  identifier column in the security migration, content identifier collations, generated lifecycle table quoting,
  non-portable schema indexes, a deprecated schema API, non-portable migration joins, unbounded DBAL
  affected-row results, non-portable identity timestamps, and worker heartbeats that were not MariaDB-safe.
  (`8dda466`, `370d594`, `0137f2c`, `b84e4e1`, `287744f`, `8a23094`, `a546aba`, `9531303`, `34a6423`,
  `186702d`, `35e1b86`)
- **Runtime key-ring loading refused legitimate configurations,** rejecting an empty key-ring object and losing
  the ring's map type; key validation now happens where the ring is built. (`e585f7e`, `867c6d4`, `c62a9ef`,
  `427cd17`)
- **The PHP-FPM worker lifecycle did not survive an unprivileged start-up,** and the unprivileged start-up
  itself needed a writable path it had been denied. (`bf95c4c`, `d6016e9`)
- **Permanent job failures were instantiated incorrectly,** and empty JSON objects were refused at console
  boundaries. (`ba9df5d`, `6d22830`)
- **Runtime concurrency tests shared one connection,** which made their arbitration assertions meaningless.
  (`1230ba3`)
- **A PHPUnit 13 addition made a private test helper a fatal error at class-load time,** taking the whole
  integration suite down before a single test ran, on every branch. (`1726ee1`)

### Security

- **Studio lifecycle authority now preserves the platform's publish/unpublish distinction end to end.**
  `artifact.publish` requires a live `content.publish` decision and `artifact.unpublish` requires a live
  `content.unpublish` decision; neither transition can borrow the other's grant through Studio's shared
  lifecycle permission. Both private decisions bind the session generation, the administrator projection
  exposes truthful target-specific controls, and the artifact port still enforces the exact decision before
  revision lookup, persistence, idempotency or audit side effects. (`b456c484`)
- **The release web image no longer carries the stale Alpine package set that failed the high/critical gate.**
  The web stage moves from the floating, retired `nginx:1.28-alpine` line to the current stable
  `nginx:1.30.4-alpine3.24` image, refreshes installed packages as part of the web stage, and makes both
  release builds pull their declared bases. The regular security workflow now builds and scans the web target
  beside the PHP runtime, so the release is no longer the first gate to inspect it. The Trivy threshold stays
  unchanged at fixed high and critical findings, so the release succeeds by removing the vulnerable packages
  rather than weakening the scan.
- **Administered wording is validated before it can become stored executable markup or a render-time failure.**
  Every override must compile as ICU MessageFormat for its locale and may contain only balanced, attribute-free
  `code`, `em`, `span` and `strong` markup; active elements, attributes, malformed nesting and markup inside ICU
  branch constructs are refused. The 500-entry site/organization quota, mutation and audit record now share one
  transaction under a durable site-row lock, and a no-op update is distinguished from an absent row before insert,
  so concurrent writers cannot both consume the last slot or turn an unchanged value into a uniqueness failure.
  (`b539161`)
- **MCP discovery publishes the policy needed to use the machine surface safely.** The capabilities resource and
  discovery tool now include, for every published tool, its required capability, risk class and non-MCP alternative
  while withholding handler names and schemas. Input and output object schemas make an explicit membership decision,
  required-property lists are validated, and schema properties and handler parameters must bind in both directions.
  (`b539161`)
- **No step-up password is declared or accepted by an extension-lifecycle machine tool.** Three lifecycle
  tools published a `currentPassword` property in their input schemas and accepted it as a handler
  parameter, marked `writeOnly` as though that were a control — it describes an output property and
  prevents nothing on the way in. The property and all three parameters are gone, and the extension
  manager is now always called with no step-up proof, so the one lifecycle change that demands one —
  taking over, disabling or removing the live administrator theme — fails closed rather than being offered
  a credential it should never have been able to accept. The browser and protected REST path remain the
  human step-up routes; the console can restore the built-in administrator theme for break-glass recovery
  but cannot step up to disable the live one. Every other activation, disable and uninstall proceeds under
  the caller's existing `extensions.manage` authorization exactly as before. (`c6ce286`, `b539161`)
- **The machine surface now says what each published tool costs, and the claim is enforced rather
  than reviewed.** Every published tool carries one risk class from a closed vocabulary — read, scoped
  write, destructive, credential, trust, installation-global — together with the non-MCP route an operator
  takes instead. The classes are not a severity ladder: each names a different question an operator has to
  answer before allowing a call, and a tool raising more than one is classified by the first that applies,
  so revoking every token a person holds across the installation is classified by its reach and disabling
  an extension by the fact that it changes which code runs. Misclassifications are corrected rather than
  exempted: deleting a menu item removes state and says so, while moving content into recoverable trash is
  a scoped write because the same surface can restore it. (`c6ce286`, `b539161`)
- **A server cannot be built from a catalogue that breaks its own rules.** The classification, the
  annotations and the schemas are checked in full before the first tool is registered, so an incoherent
  surface is a boot failure naming the offending entries instead of a tool a client discovers and misuses.
  It refuses duplicate or malformed names, a handler that does not exist or cannot receive a property the
  schema requires, an annotation that contradicts the declared class, an object schema whose membership
  nobody decided, a mutation without an operation identity, an elevated class with no declared capability,
  an optional or required schema property with no handler parameter, a required handler parameter marked optional,
  an invalid required-property list, and — the rule the credential removal rests on — any declared property at any
  depth of any schema shaped like a credential or a host path, and any handler parameter that is credential-shaped
  or marked `#[\SensitiveParameter]`. The aggregate validation seam accepts declaration lists directly, so the
  empty, duplicate-name and malformed-name refusals are exercised without weakening the immutable production
  catalogue. Closes `V2-QA-006`. (`c6ce286`, `b539161`)
- **The extension boundary is described the same honest way everywhere, because the risk was drift rather
  than dishonesty.** The supported tier has one name on every surface — trusted in-process extension code —
  and the boundary is stated by what it is and what it is not, side by side: `RestrictedExtensionContainer`
  is an API compatibility boundary that decides which host services an extension may resolve, and it is not
  a security sandbox, because curating service resolution constrains what an extension is handed and
  constrains nothing about what admitted code can do once it is running. Signature verification, the trust
  store, the revocation feed and install-time admission answer who published a package and whether it is
  still vouched for; no combination of them answers what its code may do. The administrator install screen
  now says so where the decision is actually made. The ambient authority admitted code inherits —
  filesystem, network, environment, database and process — is inventoried beside the deployment control
  that bounds each part of it, stated as the operator's control rather than the application's, with five of
  them added to the deployment checklist. Untrusted and marketplace PHP stays unsupported until an isolated
  runtime exists, and the out-of-process route it belongs on is named rather than implied. No sandbox is
  built here and none is promised; what is added is a test that reads the wording as source text and fails
  when a surface drifts, and a proof that recovery composition — which runs while an extension is
  installed, active and trusted — executes none of its PHP and exposes none of its templates.
  (`f8422e9`, `2011e9e`)
- **A legal entity's books cannot be jointly owned, by construction rather than by discipline.** There is no
  setting, environment variable, manifest key or contribution that makes an accounting document, a ledger or
  a pay run shareable; the refusal is a property of the type system and, where the engine supports it, of the
  schema. A group-scoped ownership row for any of them cannot be assembled, so it never reaches storage to be
  rejected there. (`944652a`)
- **Reading across a group buys no write across it.** The consolidated reporting capability is bound to the
  group resource alone. A caller holding it and nothing else is refused every write on a group-owned record
  and on another business's records alike, and the suite asserts both. Group membership also does not pool
  grants: a caller working in one member site cannot exercise a grant scoped at another member site, so
  widening a record's owner never widens anybody's authority. (`944652a`)

- **Production refuses to boot with unsigned local extensions permitted.** Pairing the production environment
  with the unsigned-local flag now throws at configuration time, beside the existing HTTPS and
  secret-independence rules, with a message naming the commands to register and use a trust key instead.
  Development and testing keep the unsigned local workflow unchanged. (`5bf08c2`)
- **The conformance-admission mode cannot become the bypass the signature flag was:** production refuses the
  off mode outright, and only two findings block admission — PHP that does not parse, and a manifest naming a
  class, asset or template the package does not carry — both of which describe a package already broken in a way
  that would otherwise surface as a fatal error on a live request. (`5bf08c2`)
- **Revocation-feed integrity fails closed while availability does not.** A served list that fails verification,
  freshness or the sequence check is refused, audited and buried as a permanent failure; an unreachable origin
  leaves the last applied list in force and reports staleness loudly, because failing closed there would let a
  vendor outage act as a remote kill switch over installations the issuer does not run. (`5bf08c2`)
- **Content-Security-Policy splits `style-src`,** so an injected style element is refused outright while the
  style attribute three shipped templates use stays admitted — which removes the exfiltration class and leaves a
  named, narrower residual. (`5bf08c2`)
- **Vulnerability policy gained CVSS-banded remediation windows and a 90-day disclosure ceiling,** grouped
  weekly dependency updates with security updates ungrouped, and five repository-specific secret-scanning rules
  over the retained default ruleset, each verified by constructing a synthetic positive, confirming it fired,
  and removing it. (`5bf08c2`)
- **Append-only enforcement on the audit trail stops being a convention.** Per-driver triggers refuse `UPDATE`
  outright and refuse `DELETE` unless the session has opened the retention window through the sanctioned
  removal path. The control cannot stop an account that may drop triggers, which is why the operations runbook
  pairs it with least-privilege database accounts and states the exact grant, what running without it costs,
  and how to close the gap afterwards. (`05ff831`, `1e8bc12`, `ae4b92b`)
- **Audit-trail properties are documented honestly, including the two an operator would otherwise get wrong:**
  position gaps are not evidence of tampering, because a rolled-back transaction consumes a value; and the
  triggers stop mistakes and casual tampering but not an account that may drop them. Incident response gained
  the two commands it was missing, so preserving audit evidence no longer means raw database access.
  (`1e8bc12`)
- **Record secrets no longer depend on `APP_SECRET`,** which had made both secrets un-rotatable in practice:
  rotating the application secret stranded every stored envelope, and a single hard-coded key could not be
  replaced without making everything it sealed unreadable. (`a669846`)
- **Key material keeps its bytes private,** marks its constructor parameter sensitive so a stack trace redacts
  it, and redacts itself from debug output; a sweep proves no message or stack trace carries the plaintext or
  the key in raw, hex or base64 spelling. (`a669846`)
- **There is deliberately no authorized decrypt path for a stored record secret, and that is the control.**
  Because no reveal exists, no compromise of a session, token, delegation or field-visibility rule can produce
  one. What a reveal would have to carry, if a real integration ever needs stored credentials back, is written
  down so the question is answered in advance rather than argued about under pressure. (`9c4d744`)
- **A record-key compromise procedure that opens with the uncomfortable part:** re-encryption stops future reads
  under the old key, it does not undo a copy already taken, so the credentials the secret fields hold have to be
  rotated on their own systems too — then the order that matters, ending with retiring the old key last, because
  revision history still names it. (`9c4d744`)
- **The re-keying pass discloses nothing about which records moved.** Plaintext exists between one decrypt and
  the encrypt on the same line and nowhere else; the audit entry carries counts and key names only, because a
  rotation is a property of the installation and naming a particular record's secret would disclose something
  the record itself protects. (`8706736`)
- **Password credentials could be written once and never again,** so a compromised or ageing password had no
  retirement path short of suspending the account and the platform's own credential-change invalidation could
  never fire for passwords. A lost authenticator with spent recovery codes was permanent, because every
  operation that could have reset it was gated on the step-up the holder could no longer pass. (`4bc5c74`)
- **One security-epoch advance now retires API tokens, portal sessions, administrator sessions and every
  outstanding step-up proof together,** rather than leaving a live administrator cookie outliving the
  break-glass revocation that killed the same person's tokens. (`4bc5c74`)
- **Advisories taken as they were published.** A model-context SDK advisory — an unbounded server-sent-event
  buffer that lets a hostile or merely broken endpoint exhaust the process — was closed by taking the fixed
  release with no constraint widened. Two frontend advisories, an indefinite loop in an identifier generator
  and a regular-expression denial of service in a schema validator, were closed by lockfile moves inside ranges
  already declared, with the rebuilt bundles reproducing byte for byte as the evidence nothing user-visible
  moved. (`d57b680`, `b288711`, `a63ce9c`)
- **Every workflow action is pinned to a verified release commit.** One proposed pin was the tip of an
  upstream repository's main branch and carried no tag at all, as did the pin it replaced — a branch tip is not
  a release and buys none of the immutability the pinning is for. Each pin now carries the tag it resolves to
  in a comment, so the next reviewer can check the claim without leaving the diff, and each action's inputs were
  read at that commit to confirm the workflows' keys and outputs still exist there. (`05fe279`)
- **Authorization is enforced in the application use cases,** not at the delivery edge, so every surface
  inherits one deny-by-default decision. (`0b0fbdf`, `5020325`, `d16675d`, `96e0eef`, `4258406`)
- **Protected portal extension roots are canonicalised** before they are resolved. (`a1f5345`)
- **Export grants are credential-scoped,** so an artifact cannot be downloaded under authority the requester
  never held. (`463a4f5`)
- **Canonical-host protection on the machine surface** is preserved and exercised against acceptance hosts
  rather than assumed. (`9bd81e7`, `1ca8b24`, `e0bf660`)
- **Containers run unprivileged,** including the development image, the Redis entrypoint, the PHP-FPM start-up
  path and deployment secret creation. (`8220dc3`, `7b19591`, `b4ac2f0`, `59434da`, `010d920`, `6549cc3`,
  `7f9dcc8`)
- **Access-token bootstrap is secure by construction,** and acceptance binds tokens to an explicit site and
  exercises closed token contexts and delegation ceilings. (`6549cc3`, `aaef585`, `9fb67ac`, `dd03b2c`,
  `620c4d1`, `0b0e1c8`, `4ddcd6c`)
- **Security headers and trusted-host matching were the first two things the clean baseline shipped.**
  (`b40d2af`)

### Deprecated

- **The `APP_SECRET`-derived record encryption key identified as `application-secret-v1`.** Configuring
  nothing keeps it active with its original derivation reproduced byte for byte, because those bytes are in
  production databases and are not ours to change; configuring dedicated key material makes it retired rather
  than absent, and `RECORD_ENCRYPTION_LEGACY_SECRET` pins the old derivation to the outgoing application
  secret so an installation can finish the move. A test asserts the derivation literally rather than through
  the class, so if it ever needs changing the failure says what it really means. (`a669846`, `8706736`)
- **Per-entry ceiling reads of archive contents, and hand-maintained autoload lists in the drill entry
  points.** Both patterns are retired in favour of streamed reads bounded against the bytes that actually
  arrive, and a registered loader mapping; an architecture assertion refuses a hand-maintained list that grows
  back. (`cfaf840`, `26a7b39`)

### Removed

- **The App-owned custom-business extension SPI copies.** Runtime registries, generated surfaces, examples,
  delivery tests and contribution admission now consume the canonical command, query, declaration, handler,
  result, payload, reference and schema types from `kumwe/extension-sdk` directly, with no alias, adapter or
  namespace remapping. (#124)
- **The `kumwe_token_rotate` MCP tool and its secret-once replay mode.** A machine tool that returns a newly
  issued credential contradicts the surface's rule that authentication secrets cross neither direction, even if
  its idempotency record redacts the replay. Token rotation remains available through the administrator, protected
  CLI and REST paths with their explicit safeguards; removing the MCP route does not revoke that lifecycle.
  (`b539161`)
- **The inherited 1.x-era front controllers, MVC libraries and installation tree** — 155 files — replaced by a
  clean 2.0 baseline whose first commits are security headers, trusted-host matching, typed configuration and an
  architecture policy check. (`b40d2af`, `b20c5b5`)
- **Dead public API on the trust store and the extension platform.** Four uncalled trust-store entry points, an
  unfenced Redis lock pair superseded by leases, two unreachable domain transitions, the `ExtensionLifecycle`
  and `ExtensionRegistry` interfaces which had no implementers, and an unwired administrator boundary handler.
  A test-only authorizer that lived in `src/` moved to the test support namespace. The one operational need
  behind a removed wrapper became an explicit `--repair` flag on the runtime materialisation command.
  (`115cc3c`)
- **The record-detail export link,** because the pipeline exports record sets: the honest list-level control is
  one step away, and a detail-level link would keep promising a single-record export the pipeline does not
  provide. (`46c6b6c`)
- **Session narrative from the user documentation,** which described how the work was done rather than what the
  product does. (`115cc3c`)
- **Abandoned web-root artifacts** left over from the inherited layout. (`b20c5b5`)

---

## What is not here

This file records completed work. Open objectives, gates, work packages and findings live in
[`docs/roadmap/`](docs/roadmap/README.md), and the executed evidence of the eight production-qualification waves
is retained in [`docs/qualification/gap-matrix.md`](docs/qualification/gap-matrix.md) as a historical record
rather than a plan.
