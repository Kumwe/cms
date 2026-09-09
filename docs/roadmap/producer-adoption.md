# Producer adoption

**Companion to** [`docs/studio-composition-authoring.md`](../studio-composition-authoring.md) and
phase S. **Findings** `V2-STU-005` – `V2-STU-007`; `V2-STU-002` closed with package `S-B` when the
pin, replay and verifiers below landed. This page records the delivered state of the adoption on
the library-adoption branch (kumwe/app#124) and names what still lies ahead; every claim below is
checkable against the tree it describes.

## The decision this records

The PHP half of the Studio integration has its own home:
[`kumwe/producer`](https://github.com/kumwe/producer) — a dependency-free Composer library that
implements the host-neutral mechanics of the Studio contract. Studio designs a composition,
Producer makes it real on a PHP host, and this application keeps every authoritative concern.
Producer's [charter](https://github.com/kumwe/producer/blob/main/CHARTER.md) forbids it authority,
storage, Node.js, render-time code generation, and runtime dependencies; its [host
agreement](https://github.com/kumwe/producer/blob/main/docs/host-agreement.md) records what a host
implements, what Producer promises, and the three-way pin protocol. Kumwe App is Producer's first
consumer, never its owner: nothing App-specific may enter that repository.

## The pin, as delivered

`composer.json` requires `kumwe/producer` at exactly `0.3.0`, beside `kumwe/extension-sdk` `0.2.4`
and `kumwe/conversion` `0.1.2`. `composer studio:dependencies` refuses any range, branch, alias or
foreign specifier for the three libraries or the eight `@kumwe/studio` packages, and
`tests/Architecture/StudioDependencyPinGateTest.php` proves each refusal. Producer 0.3.0 pins
Studio `0.1.0-beta.3` at protocol `0.1.0-draft.2`, so App's
[`resources/studio-contract/PIN.json`](../../resources/studio-contract/PIN.json) moved
deliberately from the interim `0.1.0-rc.1` snapshot to that coordinate: one chain, App → Producer
→ Studio, with the release record and all eight registry tarball digests and integrity values bound
to Producer's typed release and package provenance by `composer studio:corpus`. Producer 0.3.0 also
supplies the `Kumwe\Producer\Deployment` layer App composes for the contextual Content editor: the
browser-asset locator that resolves the pinned module to an integrity-bound URL at the configured
origin, the deployment emitter that proves each per-mount `studio-deployment` document against the
pinned schema, and the exact-origin grammar the response policy admits. Producer owns the vendored schema and testkit corpus — the 56-file
protocol schema tree and 301 testkit members in 14 groups; App copies neither.

## What this application delegates to Producer

| Concern | Delivered in this repository | Still forward |
| --- | --- | --- |
| Canonical JSON bytes and digests | `Kumwe\Producer\Canonical\CanonicalJson` wherever App canonicalizes or digests a Studio document; no App implementation remains | — |
| `studio.profile/schema-property` admission and instance validation | Producer's `Schema\SchemaPropertyProfile` and `SchemaPropertyValidator`; both App copies (`src/Studio/Domain/Contract/SchemaPropertyProfile.php` and `src/Extension/Domain/Internal/StudioProfile/SchemaPropertyProfile.php`) were deleted at the adoption, and the 62-vector schema-profile replay runs in Producer's own suite | — |
| Request envelope parsing, the closed operation registry, the error taxonomy | `AdministratorStudioHostHandler` hands `Kumwe\Producer\Wire\Dispatcher` a request-scoped `StudioProducerHost`, which implements `HostAdapterInterface` with the authorization and mutation-boundary authorities and all nine pinned operation ports — artifact, localization, media, model, permission, preview, recovery, resource, telemetry; the App-owned `StudioHostDispatcher`, `StudioHostRequestDecoder` and `StudioContractSchemas` are gone | The optional `studio.port/authoring` port is declined until the contextual ladder (`S-G`) serves a real one |
| Published-composition rendering across the full Studio block catalog | `CanonicalStudioPublishedContentRenderer` and `CanonicalStudioPreviewRenderer` call Producer's `Render\CompositionRenderer` with a fresh exact-coordinate `BlockRendererRegistry`; the four structural families `studio.core/{section,stack,grid,columns}` render through the App-owned `StudioLayoutBlockRenderer`, bound at the `core.renderer/layout` seam, because the site stylesheet and Studio canvas key on App's classed `data-studio-layout` element rather than Producer's neutral layout `div` | — |
| Design tokens and layout vocabulary to public CSS | Producer's complete opaque CSS arrives in `RenderResult::$css`; `StudioPublishedStylesheetHandler` serves it from a same-origin SHA-256-addressed URL after re-resolving publication and renderer authority, and the preview grant stores it with the generated theme variables | — |
| The public enhancement runtime | Absent by decision: `StudioRenderResultAdmission` refuses any render result whose enhancement inventory is non-empty, so requested behavior is never silently dropped or executed by a parallel runtime | A prebuilt, content-hashed Producer enhancement artifact that App serves; until it exists, enhancement-bearing blocks cannot render |

What never moves: authorization and the deny-by-default gateway, session authority and generation
invalidation, artifact persistence and revisions, idempotency storage, media storage, workflow,
audit, Twig templates, and every route. Producer returns a structured render result — HTML, the
complete CSS, the enhancement inventory — and this application's templates embed it like any other
value. `StudioArtifactHostPort` and `StudioRecoveryHostPort` implement Producer's
`ArtifactPortInterface` and `RecoveryPortInterface` over App's own DBAL storage; Producer's
vendored artifact and recovery host vectors replay through them in
`tests/Integration/Studio/StudioArtifactRecoveryVectorReplayIntegrationTest.php`.

## Adoption sequence, bound to the existing ladder

1. **Pin — delivered.** Producer entered `composer.json` at an exact version; a non-exact
   specifier for `kumwe/producer` or any `@kumwe/studio` artifact fails `composer
   studio:dependencies`. Producer's own pin names the Studio release it implements, so this
   application's release evidence records one coherent chain: App → Producer → Studio.
2. **Replace inward — delivered for every mechanic Producer 0.2.0 proves.** Envelope validation,
   schema-property, rendering and stylesheets are consumed from Producer, and the duplicated
   internals retired in the same change that adopted their replacement: `SchemaPropertyProfile`
   first, then the host dispatcher, request decoder and contract-schema registry. The rule stands
   for every mechanic Producer proves next.
3. **Prove here regardless — standing obligation.** Producer's corpus replay does not discharge
   this repository's own proof obligations: `composer studio:corpus` and `npm run
   check:studio-corpus` run over the adopted library on every build, and the App suites replay
   Producer's vendored media, preview and artifact/recovery host vectors through App's own ports
   on every supported engine — a regression in the pair App+Producer must fail this repository's
   lane, not only Producer's.
4. **Raise mismatches upstream — standing rule.** A need Producer's contract surface cannot
   express is a finding in `kumwe/producer` (or `kumwe/studio` when it is contractual), never an
   App-side fork or workaround; the pin advances only as a deliberate re-pin with its own
   evidence, as the rc.1 → beta.3 move was.

## What remains forward

- The enhancement runtime, above. Producer computes the per-page need signal today; nothing serves
  it.
- The contextual authoring port and the `S-G2`–`S-G9` ladder in
  [`docs/studio-composition-authoring.md`](../studio-composition-authoring.md): the pinned beta
  claims no contextual profile and App packages no contextual browser entry, so
  `PinnedStudioContextualAuthoringAvailability` stops closed at `browser-runtime-unavailable`.
- Media, resource and external-source hosting (`V2-STU-005`) and renderer-parity evidence for Gate
  B (`V2-STU-006`) continue under their own packages; neither is a Producer mechanic.

## What must not happen

- No composition mechanics re-implemented here once Producer proves them; duplication is drift.
  The App-owned layout renderer owns presentation vocabulary, not block semantics, and stays bound
  behind Producer's renderer seam.
- No Producer version range, ever, while Studio's contract is pre-release.
- No authoritative concern handed to Producer — its charter refuses them, and so does phase S.
