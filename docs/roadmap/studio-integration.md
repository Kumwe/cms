# Studio visual composition integration

**Companion to** decision D16, [ADR 0007](decisions/0007-studio-visual-composition-integration.md),
phase S in [`README.md`](README.md) section 9, Gate A criterion 13, and Gate B criterion 12.
**Ledger entries** `V2-STU-001` through `V2-STU-007`.

This is a detailed phase-S component and evidence record, not a second product definition or an author guide. The
sole normative product outcome is Studio's
[`docs/product-contract.md`](https://github.com/kumwe/studio/blob/main/docs/product-contract.md). The single App-side
mapping, current-gap statement, and implementation sequence are
[`docs/studio-composition-authoring.md`](../studio-composition-authoring.md). If this evidence record appears to
describe a different authoring workflow, those two authorities win and this record must be corrected.

---

## What Studio is

Studio is a standalone, schema-aware page builder developed in its own repository,
[`kumwe/studio`](https://github.com/kumwe/studio). Authors compose pages and reusable structures from
typed, theme-bounded building blocks; the result is a canonical, portable JSON document. Policy-sanitized
HTML fragments and policy-scoped CSS rules may be represented as bounded typed values, but raw editor state,
authored JavaScript, templates, database expressions, and ungoverned markup/styles are never stored. Its
architecture is contract-first: a language-neutral
protocol of JSON Schemas and canonical fixtures, a deterministic browser-independent command engine with
byte-invertible history, a 45-block production catalog, an accessibility-complete authoring shell, a portable
semantic-web renderer, and a host-port boundary through
which every authoritative concern — identity, policy, persistence, media, preview rendering,
dynamic data, localization, telemetry — remains owned by the host platform. Kumwe is such a host. The division of
labour is fixed in ADR 0007: Studio owns the authoring experience and the protocol; Kumwe owns the host
adapter and every authoritative service behind it. [ADR 0018](decisions/0018-studio-inline-content-editor-boundary.md)
fixes the inline-content boundary: Studio owns the pinned Editor.js implementation behind its editor-neutral
API and exposes no Editor.js JSON or configuration to App; App owns media custody, dynamic resource
authorization, persistence, preview, Twig delivery and CSP enforcement. Editor.js edits content inside a
Studio block; it is not the page model, layout engine, renderer, host API, or persistence format.

In App, the required product presentation is contextual rather than catalogue-first: one generic resolver makes
Studio available from authorized core and extension content create/edit targets, inline or expanded, with exact
type/Model/Blueprint/Entry hydration and explicit item/type-version/new-type save outcomes. App does not add Studio
as a top-level navigation workspace; a full-screen route remains an expanded state of the originating content
context. The current Blueprint-only route does not yet satisfy that journey; detailed component evidence below
must not be read as an end-to-end completion claim.

Studio's programme runs its own two-gate discipline in its repository —
[`docs/roadmap/`](https://github.com/kumwe/studio/tree/main/docs/roadmap) there — with machine-checked
registries for its requirements and threats, a canonical fixture corpus, and an evidence model. Its
contract is `0.1-draft` until its own first gate ratifies it; consumers pin exact prerelease versions
until then, which is why every version below is exact.

## Current App pin and next release family

The App currently consumes the eight-package `0.1.0-beta.3` coordinated beta family — the Studio
coordinate `kumwe/producer` 0.3.0 pins, adopted deliberately in place of the interim `0.1.0-rc.1`
snapshot so that App → Producer → Studio is one chain. No package bytes are committed: the eight
packages resolve from the public npm registry at their exact versions, and
[`resources/studio-contract/PIN.json`](../../resources/studio-contract/PIN.json) is the authoritative
record of the registry, each official tarball URL, its SHA-256 and its SHA-512 integrity, which
`composer studio:corpus` binds to Producer's package provenance and `npm run check:studio-release` binds
to the lockfile. Producer owns the PHP schema registry, the testkit resources and the browser-asset
manifest; App does not copy any of them. The one Studio-derived record App materializes itself is
[`resources/studio-contract/core-catalog.json`](../../resources/studio-contract/core-catalog.json), the
exact first-party block and pattern coordinates the pinned browser module compiles in, regenerated from
the installed exact `@kumwe/studio-core` and proven against it by `npm run check:studio-catalog`.

At run time the Content editor loads the pinned Studio browser module, and published pages load the
enhancement runtime, from the configured `KUMWE_STUDIO_BROWSER_BASE_URL` (the public npm CDN by default,
or a mirror keeping the npm package layout) with the subresource-integrity values Producer publishes; the
App's own contributor build compiles only its start module and administrator bundle. Production
installation and operation never run npm, Node.js, Vite, a development server, or server-side JavaScript.

| Package | Version | What it carries |
|---|---|---|
| `@kumwe/studio-protocol` | `0.1.0-beta.3`, vendored and pinned | The wire types, guards, and the complete JSON Schema corpus with its digest manifest |
| `@kumwe/studio-core` | `0.1.0-beta.3`, vendored and pinned | The deterministic command engine, session, contribution runtime, migrations, URL policy |
| `@kumwe/studio-preview` | `0.1.0-beta.3`, vendored and pinned | Both ends of the origin-pinned preview channel: client, host responder, geometry |
| `@kumwe/studio-media` | `0.1.0-beta.3`, vendored and pinned | Upload orchestration over the canonical media session state machine |
| `@kumwe/studio-renderer-web` | `0.1.0-beta.3`, vendored and pinned | The portable semantic web renderer with scoped CSS, safe markup, and the published conformance vector runner |
| `@kumwe/studio-rich-text` | `0.1.0-beta.3`, vendored and pinned | The bounded rich-text grammar, parser and renderer projection |
| `@kumwe/studio` | `0.1.0-beta.3`, vendored and pinned | The authoring shell as a web component, keyboard-complete and catalog-localized |
| `@kumwe/studio-testkit` | `0.1.0-beta.3`, vendored and pinned | The canonical fixture corpus and a deterministic in-memory reference host |

The host adapter's server side needs none of these at runtime — the protocol is JSON over the wire.
The packages matter in two places: the administrator build consumes `@kumwe/studio` (and transitively
the runtime packages) through the existing Vite entry points, and the test suite consumes the corpus
shipped inside `@kumwe/studio-testkit` and `@kumwe/studio-protocol`.

The pin identifies one coordinated beta family; it is not evidence that App's contextual journey or
Studio's/App's gate acceptance has passed. Package publication, provenance, conformance, and gate status remain
separate claims under Studio's release record and roadmap status. No App documentation may infer integrated product
maturity from the `beta.3` label alone.

| Coordinated package | Release-unit responsibility |
|---|---|
| `@kumwe/studio-protocol` | Schemas, values, host operations, release record and guards |
| `@kumwe/studio-core` | Deterministic engine, 45 block definitions, ten patterns and insertion defaults |
| `@kumwe/studio-preview` | Origin-pinned preview client/host, marker identity and geometry |
| `@kumwe/studio-media` | Portable media authoring state over a host-injected provider/transport |
| `@kumwe/studio-rich-text` | Canonical rich-text grammar and the private Editor.js 2.31.6 adapter |
| `@kumwe/studio-renderer-web` | Semantic HTML, deterministic scoped CSS and disposable trusted enhancement |
| `@kumwe/studio` | Standalone web authoring shell and editor-neutral control registry |
| `@kumwe/studio-testkit` | Language-neutral fixtures, vectors, reference host and conformance runners |

No package is upgraded alone. Chart.js 4.5.1, Mermaid 11.17.1 and KaTeX 0.18.4 remain exact optional,
lazy renderer adapters; they do not enter a Blueprint or become App-facing APIs.

### Coordinated adoption sequence

Every npm command in this adoption and verification record is a contributor/release-build or CI command. None is
an installation, startup, authoring, preview, publication, or production-server operation.

1. The Studio implementation merges first. Its fixed Changesets family sets all eight manifests, lock data,
   generated release records, package manifests, notices and tarball contents to one exact coordinated version.
2. Studio runs its required schema, unit, integration, browser, accessibility, security, conformance, package
   and clean-consumer lanes. A correction creates a new immutable prerelease; a published version is never
   rebuilt in place. Beta/RC promotion remains blocked until M1-04 and evidence acceptance.
3. Studio publishes all eight integrity-addressed packages to the registry and the byte-identical release
   record. A partial family is not a release and App does not integrate from a branch, workspace link or
   locally packed substitute.
4. The eight-package re-pin updates `package.json`, the npm lock, `PIN.json` (registry tarball URLs,
   digests and integrity), the copied Studio release record, the materialized `core-catalog.json`, the
   Producer dependency and the reviewed host qualification in `ContainerFactory` together, exactly as
   decision D16 requires.
5. App replays the host, media, rich-text, authoring-web and renderer-web corpus, runs the Twig adapter parity
   lane, builds the real administrator assets, and runs the database/browser/security/qualification matrix.
   Only the exact resulting App candidate may enter Gate B assessment.

Rollback selects the last complete compatible family and its matching corpus. Mixing any two Studio versions,
or completing Core against unreleased Studio bytes, is prohibited.

## Studio-owned production capability

The coordinated catalog is Studio functionality, not App-local palette code. Every definition has a closed property
schema, declared slots, authoring metadata, accessibility obligations, semantic renderer requirements and a
schema-valid insertion default. The catalog contains exactly 45 first-party block types:

| Family | Studio block types |
|---|---|
| Layout | `section`, `stack`, `grid`, `columns` |
| Content and semantics | `heading`, `rich-text`, `article`, `card`, `call-to-action`, `callout`, `badge`, `label`, `divider`, `description-list`, `description-item` |
| Media and visual | `image`, `gallery`, `video`, `audio`, `attachment`, `cover`, `drawing`, `icon` |
| Data and source | `chart`, `diagram`, `math`, `code`, `table`, `money`, `content-reference`, `content-collection`, `embed` |
| Interactive and status | `accordion`, `accordion-item`, `tabs`, `tab`, `dialog`, `popover`, `notice`, `navigation`, `navigation-item`, `countdown`, `progress`, `search`, `spinner` |

The fixed starter set is `article`, `collection-index`, `document-header`, `faq`, `feature-grid`, `hero`,
`media-gallery`, `pricing`, `product` and `tabbed-content`. The stable advanced authoring controls are
`chart`, `drawing`, `media-collection`, `media-reference`, `money`, `presentation`, `rich-text`, `scoped-css`,
`source` and `table` in the `studio.control/*` namespace. App may register namespaced host controls, resource
families and extension definitions, but it does not fork these first-party definitions or expose Editor.js,
Chart.js, Mermaid or KaTeX configuration through them.

Progressive behavior belongs to Studio's trusted renderer layer. Tabs, dialog, notice, popover, countdown,
lightbox, nested navigation, slideshow and renderer-owned motion are disposable enhancements over useful
semantic fallbacks. The closed presentation intent expresses alignment, width/height, spacing, inverse color,
markers, position, print, scrolling, responsive visibility and renderer-owned motion without persisting CSS or
JavaScript. Gallery owns both grid and slideshow presentations; navigation owns its closed presentation family;
dialog and popover own their modal/offcanvas/overlay and dropdown/dropbar/tooltip forms. App supplies host data
and media, not behavior implementations.

## The contract corpus, usable from PHPUnit

Every contract artifact is language-neutral JSON, so the host side proves conformance without executing
any Studio code. The corpus at the currently pinned beta.3 versions, as Producer vendors it and
`npm run check:studio-corpus` replays it, is:

| Corpus | Where it ships | Count |
|---|---|---|
| Positive schema documents | coordinated protocol/testkit corpus | 234 |
| Command vectors (initial document, command, expected result or failure code, inverse) | `@kumwe/studio-testkit` `vectors/command/` | 60 |
| Canonical serializations | coordinated protocol/testkit corpus | 12 |
| Preview identities | coordinated protocol/testkit corpus | 2 |
| Negative fixtures the schemas must reject | coordinated protocol/testkit corpus | 60 |
| Rich-text renderer conformance projections | `@kumwe/studio-testkit` `conformance/rich-text/` | 8 |
| Renderer-web conformance vectors | `@kumwe/studio-testkit` `conformance/renderer-web/` | 8 |
| Host-port and host-sequence vectors, replayed by the PHP suites | `@kumwe/studio-testkit` `vectors/host/`, `vectors/host-sequence/` | 31 and 9 |

What the host test suite asserts with them:

- every response the adapter emits validates against the relevant schema, and every error it emits
  satisfies the closed twelve-category shape in `host-error.schema.json` — non-disclosing messages,
  stable categories, no raw internals;
- documents the adapter persists and returns survive the canonical serialization rules (sorted members,
  canonical scalars, the digest manifest's checksums recompute);
- where the adapter touches command processing at all, the command vectors replay to identical results —
  the engine itself runs in the browser, so the server-side obligation is storage fidelity and revision
  discipline, not reduction.

The eight-package family adds a renderer-web conformance corpus. Its candidate baseline must cover all 45
first-party block types, all nine progressive-behavior families, all ten presentation axes and the five
security-fallback classes in eight language-neutral vectors. Those numbers describe the acceptance target
for the coordinated prerelease. The App's corpus lane replays all eight vectors through the published
`runRendererWebVector` runner against the exact installed renderer. There is no App Twig renderer to replay
them against any more: preview and publication call Producer's `CompositionRenderer`, so renderer conformance
is Producer's obligation at its pinned coordinate, and the App proves only its own seams — the structural
layout renderer, contributed block binding and render-result admission — under its unit and integration suites.

### App verification paths

The integration is protected at each App boundary; no single green suite substitutes for the complete release
matrix:

| Boundary | Authoritative evidence path | Direct local command |
|---|---|---|
| Exact Studio family, schemas and corpus | Producer's typed registry/resources, App `PIN.json` and npm tarballs, `tools/verify-studio-{release,corpus}.*` | `composer studio:corpus && npm run check:studio-release && npm run check:studio-corpus` |
| PHP contracts, policy and host ports | `tests/Unit/Studio/` plus Studio-named administrator and extension tests | `composer test:unit -- --filter Studio` |
| Persistence and executable host integration | `tests/Integration/Studio/` | `composer test:integration -- --filter Studio` |
| Layer and dependency ownership | `tests/Architecture/Studio*BoundaryTest.php` | `composer test -- --testsuite architecture --filter Studio` |
| Built authoring, preview, lifecycle and public output | `tests/Browser/studio-composition.spec.ts`, `tests/Browser/studio-published-responsive.spec.ts` | `npm run test:browser -- tests/Browser/studio-composition.spec.ts tests/Browser/studio-published-responsive.spec.ts` |

The merge lane repeats the relational integration and browser paths on MariaDB, MySQL and PostgreSQL; the
breadth lane repeats the browser journeys on Firefox and WebKit desktop/mobile; the Security workflow retains
the Studio preview negative-path JUnit artifact. Final App adoption additionally requires `composer qa`,
`npm run check`, the committed production build, deployed-artifact verification and the exact Studio candidate's
renderer-web/Twig conformance replay described above.

## The host contract

Studio's product contract defines which authoring outcome these ports must enable; this section only maps the
currently implemented protocol boundary. The normative host protocol contract is
[`docs/contracts/host-adapter.md`](https://github.com/kumwe/studio/blob/main/docs/contracts/host-adapter.md)
in the Studio repository, with the port types in `@kumwe/studio-protocol`. Typed asynchronous ports
share one request envelope. App advertises only artifact, permission, recovery, model, media, resource,
preview, localization and telemetry operations it actually composes. `resource.search` is a read-only
discovery boundary: a provider owns one qualified resource type; duplicate ownership is invalid; the exact
query carries that resource type, a limit from 1 through 100, optional search text of at most 160 characters
and an optional opaque host cursor. Results expose only a stable identifier, localized label and qualified
resource type, plus another opaque cursor when more results exist. It does not admit writes, SQL, filter
languages, entity payloads or client-selected repositories.
Capability negotiation is fail-closed: no common wire version or a missing required port means no
editable session; missing optional ports degrade with diagnostics.

The mapping to mechanisms Kumwe already has:

| Studio port concern | Existing Kumwe mechanism it maps onto |
|---|---|
| Identity and session generations | Administrator sessions and the security epoch; a permission change invalidates the Studio session generation |
| Policy | Capability checks through the existing authorization gateway; deny-by-default |
| Persistence and optimistic concurrency | Versioned artifacts with expected-revision writes; a stale write returns the safe current revision, matching the platform's version-conflict contract |
| Idempotent replay | The `Idempotency-Key` contract already on every API mutation |
| Media | The media module behind the canonical upload session state machine; the media policy vectors fix the rejection behaviour |
| Resource discovery | Provider-owned `resource.search` over existing authorized application services; the first Content provider owns `kumwe.app/content-entry` and exposes only stable entry references and localized labels |
| Dynamic data | Typed model/entry reads and binding descriptors; App resolves them through its existing Content or business application service at preview/publication time after policy, scope and field-disclosure checks |
| External media and embed sources | Studio's lexical URL policy plus the host runtime obligations its threat registry records: fetch hardening, redirect re-validation, response verification |
| Preview | Producer's exact-coordinate renderer behind the AP-6 authenticated render, single-use document and grant-bound combined stylesheet endpoints |
| Localization | The Studio shell's host-overridable message catalog fed from the XLIFF-compiled catalogue chain, so wording and terminology overrides apply to the composition surface like any other |
| Telemetry | The observability contract; Studio's telemetry port carries primitive-only attributes within the established cardinality discipline |

### Implemented S-C authority and S-D persistence boundaries

The App now opens Studio authority through `POST /administrator/studio/session` and dispatches the
normative protocol binding through
`POST /administrator/studio/ports/{port}/{operation}`. Both routes use the authenticated administrator
context and CSRF middleware. The client cannot assert actor, grant, site, organization, workspace,
surface, or browser-session identity. An opaque context key is bound to those trusted coordinates and
to one of all five canonical modes over the Content or Blueprint resource family.

`permission.explain` and `permission.refresh` resolve effective permissions through the existing
deny-by-default authorization gateway on every call. The same advertised host now implements artifact
`dependencies`, `load`, `save`, `publish`, and `unpublish`, plus recovery `load`, `store`, and `discard`.
A publication target requires `content.publish`, while return to draft requires `content.unpublish`.
The Studio protocol's shared lifecycle permission is only a compatibility projection: private live
`canPublish` and `canUnpublish` decisions authorize the corresponding mutation independently and both bind
the generation, so retaining one capability cannot conceal revocation of the other behind an unchanged
permission list.
A generation binds grants, the security epoch, membership policy, mode, effective permissions, host
capabilities, resource context, and authenticated browser session. Every operation still crosses the same
dispatcher and therefore returns `invalid-request` with `studio.host/stale-session-generation` before its
port can act under stale authority.

Artifact reads accept the published `{ "reference": … }` wrapper and save accepts
`{ "document": … }`; recovery store accepts `{ "envelope": … }` while load and discard require an empty
object. Save, publish, and unpublish require `expectedRevision`. The store appends an immutable revision and
moves its head only by compare-and-set, so a conflict cannot overwrite and reveals only the safe current
revision. Completed mutations replay by an atomic digest over actor, authenticated session, resource,
operation and caller key; request and trace IDs do not alter intent, while semantic arguments, protocol,
locale and expected revision do. A changed intent is refused.

Schema validation and a separate lexical stored-content policy run before persistence. Accepted canonical
bytes are stored as text and returned unchanged. Raw HTML/CSS strings, executable syntax, unsafe members and
unsafe or out-of-schema URLs are rejected rather than sanitized. A future coordinated safe-markup value is admitted
only as the named-policy structural fragment Studio produces; a scoped stylesheet remains a separately
authorized host artifact and is never embedded in the Blueprint. Recovery bytes are scoped by
actor, authenticated session and resource context, with canonical-number fidelity and bounded size and
fixed-window writes. Successful artifact and recovery mutations record one disclosure-safe audit event in
the same transaction. The audit carries operation and trusted coordinates/digests, never document or
envelope bytes, session material or raw idempotency keys; completed replay does not audit twice.

AP-1 replay accounting for this package names these exact vendored vector IDs:

- `vector.host-vector.permission.explain.withheld`
- `vector.host-vector.permission.refresh.snapshot`
- `vector.host-vector.envelope.malformed-context`
- `vector.host-vector.envelope.protocol-version`
- `vector.host-vector.envelope.stale-generation`

The migration and architecture decision are recorded in
[ADR 0014](decisions/0014-studio-host-session-authority.md). Versioned artifact and recovery persistence,
its replay/audit boundary and migration are recorded in
[ADR 0015](decisions/0015-studio-artifact-and-recovery-persistence.md). AP-5 media, AP-6 preview, and
AP-7 model reads, localization, telemetry and embedded Blueprint shell now cross this same
authority/dispatcher boundary. Model mutation remains deliberately absent. `resource.search` is advertised
only when exactly one authorized provider owns the requested qualified type; an absent provider remains
canonically unavailable without weakening the stale fence.
Every composition lookup reprojects the authorized exact Content model and compares its identifier,
version and revision with the immutable Blueprint model lock before returning the artifact. Drift in any
coordinate is a typed migration-required refusal rather than an opportunity to open the Blueprint against
a different schema.

These are low-level host primitives. App currently opens them only for a Blueprint session reached from an
already-created Content-type version. Its model/Entry projection is read-only and `artifact.save` updates an
existing draft; the ordinary Content forms still own item and type writes. The open integration must add one generic
core/extension Studio target resolver, exact type/Model/Blueprint/Entry hydration, PHP-authoritative item and type
operations, the three explicit save outcomes, state-preserving contextual presentation, and the canonical
end-to-end acceptance journey. It must not turn this dispatcher or read projector into an ungoverned generic save
path. The authoritative gap and commit ladder are in
[`docs/studio-composition-authoring.md`](../studio-composition-authoring.md).

AP-1 replay accounting for S-D additionally covers these exact vendored IDs:

- `vector.host-vector.artifact.dependencies`
- `vector.host-vector.artifact.load.stored`
- `vector.host-vector.artifact.load.unknown`
- `vector.host-vector.artifact.publish.accepted`
- `vector.host-vector.artifact.publish.forbidden`
- `vector.host-vector.artifact.publish.stale`
- `vector.host-vector.artifact.save.accepted`
- `vector.host-vector.artifact.save.forbidden`
- `vector.host-vector.artifact.save.stale`
- `vector.host-vector.artifact.unpublish.stale`
- `vector.host-vector.recovery.load.absent`
- `vector.host-sequence.artifact.publish.changed-intent`
- `vector.host-sequence.artifact.publish.idempotent-replay`
- `vector.host-sequence.recovery.store.canonical-number`
- `vector.host-sequence.recovery.store.changed-context`
- `vector.host-sequence.recovery.store.rate-limited`
- `vector.host-sequence.recovery.store.resource-scope`
- `vector.host-sequence.recovery.store.wrong-operation-id`

### Resource, media and dynamic-data authority

Studio owns the portable control and canonical reference shapes; App owns every authoritative lookup and
byte. An Editor.js media tool therefore calls the Studio media control, which calls the injected Studio media
provider, which crosses the App media host port. It cannot upload directly, retain an App upload URL or make
Editor.js part of the host protocol. Documents retain an immutable media reference. Preview and delivery ask
App to resolve that reference after live authorization. Local upload preview may accept only a resolver-issued
`blob:https?://...` URL under an explicit media-only authority that defaults off; `data:`, SVG/HTML payloads,
resource URLs, embeds and actions are not widened, and the host revokes each object URL.

Resource discovery is similarly only an authoring convenience. `resource.search` selects from one
provider-owned qualified resource family and returns small references; it is not the delivery query engine.
A resource/reference, collection or other data-aware block stores a closed binding descriptor. At preview and
public delivery App resolves that descriptor through the existing application service for the bounded context,
reapplying site, actor or public-purpose policy, field disclosure, locale, publication and pagination rules.
No database connection, repository, SQL/expression language or unrestricted record JSON crosses into Studio.
The Content entry provider is the first family; BusinessRecord discovery and projection remain unavailable
until their purpose-specific BusinessSecurity adapter exists.

### Renderer-web and Twig conformance

`@kumwe/studio-renderer-web` is Studio's portable reference implementation of the production rendering
contract. It turns a canonical document plus already-authorized host values into semantic HTML and
deterministic scoped CSS. The renderer performs no fetch or database access. Safe markup is a structural,
allowlisted tree rather than a trusted HTML string; scoped CSS is a structured, host-authorized sheet passed
separately from the Blueprint; and authored JavaScript is never an input. Tabs, dialogs, popovers, notices,
navigation, countdowns, slideshows, lightboxes and motion retain useful HTML semantics without enhancement.
Studio's self-hosted enhancer may add disposable behavior under the host CSP.

App owns server delivery but does not own a second rendering engine. It calls Producer's
`CompositionRenderer` with a fresh exact-coordinate `BlockRendererRegistry`; Twig supplies only the outer
site document. Producer returns the complete semantic HTML, opaque complete CSS and enhancement inventory.
Preview and publication both require registered type/version/revision coordinates. App currently refuses any
non-empty enhancement inventory until one canonical Producer enhancement runtime exists. A Twig-only block
interpretation, draft fallback in an exact render, or locally copied renderer is conformance failure.

### Implemented S-F authenticated preview boundary

The App implements `preview.render` and `preview.cancel` through the common AP-3 dispatcher and exposes
the rendered document at authenticated `GET /administrator/studio/preview`. The HTTP binding is closed:
render accepts exactly `{payload}`, cancel accepts exactly `{draftDigest}`, and session opening returns
only `{channelId, documentPath, origin, sourceId}` as preview metadata. After render succeeds, the browser
navigates the same-origin frame to `documentPath` with the resource context, render request, session
generation, channel, source and the next independent document sequence. The GET atomically claims that
exact staged result once; it is neither a stable unpublished URL nor a bearer-token document store.

The renderer reads the immutable canonical draft from S-D's `studio_artifact_revisions`, resolves Content
bindings through the existing AP-2 authorization/projection service, and presents only that authorized
projection. Content stays authoritative. The host registry truthfully renders the four structural block
types `studio.core/{section,stack,grid,columns}` and these core-owned field blocks:
`core/field-text`, `core/field-rich-text`, `core/field-integer`, `core/field-decimal`,
`core/field-boolean`, `core/field-date`, `core/field-date-time`, `core/field-media`, and
`core/field-resource`. Media and resource fields render bounded labels or identifiers, never a client URL.
Unknown, ambiguous or unregistered coordinates refuse the render before HTML exists.

Schema-6 extension blocks use that same registry seam without treating their manifest renderer string as
code. A verified provider must explicitly share a Producer `BlockRenderer` under the restricted
container identifier `extension.<owner-namespaced renderer binding>`. Core then binds that instance to the
reconciled canonical block's exact type, version and revision and to the exact package/runtime publication
that loaded it. Current-generation and live-trust checks run before every extension call; missing or
incorrect services, dependency-lock drift, foreign document owners, package replacement, trust withdrawal,
duplicate coordinate claims and renderer exceptions all fail closed. The runtime contribution keeps the
owner-local service binding separate from the canonical `preview` renderer capability: an authoring
catalog advertises `kumwe.contract-manifest-six/grid`, never
`kumwe.contract-manifest-six.renderer.grid`, and only while the exact executable entry reports support.
Installing and activating the committed signed manifest-6 fixture is the deterministic non-core renderer
used by P7-F and the AP-7 browser qualification catalog.

For the four structural types, the render request's semantic viewport resolves an exact responsive override
before the node's base property and Studio's runtime default. The renderer emits only the closed
`data-studio-layout-{alignment,collapse,columns,direction,spacing,visibility}` vocabulary: alignment,
collapse, direction, spacing and visibility use their published finite choices, while columns is an integer
from one through twelve. Attribute names are deterministically ordered and values are escaped. An arbitrary
data attribute, style attribute, malformed responsive container or out-of-vocabulary value is refused before
HTML exists; the draft never supplies CSS.

Published and unpublished output share `ContentPageRenderService`, the canonical site template and exact
validated theme values. Preview supplies Producer HTML as an already-presented entry, suppresses the public
theme-variable attribute, and stores Producer's complete CSS plus the generated theme variables as one exact
stylesheet with the grant. Claiming the document activates one authenticated same-origin no-store link;
the subresource rechecks live authority and exact grant/channel/source coordinates without advancing either
protocol sequence. Immediately before markup exists, the canonical renderer re-resolves the trusted
published theme for the session's `SiteContext` and compares its exact identifier, version and revision
with the immutable draft lock. Theme activation or presentation drift abandons the pending grant and returns
the stable `conflict` / `studio.preview/theme-lock-mismatch` refusal. `preview.render` creates a
60-second grant bound to actor, site, optional organization/workspace, authenticated browser session,
resource context, session generation, origin, channel, source, draft identity and port sequence.
Cancellation and a newer render supersede matching pending work; completed HTML is no-store and can be
claimed once. Separate monotonic `port` and `document` ledgers reject replay or out-of-order traffic on
both endpoints. Migration `20260824040000_studio_preview_grants` creates the portable
`studio_preview_grants` and `studio_preview_sequences` stores repeatably.

The only CSP delta is selected by the exact preview path: `frame-src 'self'`,
`frame-ancestors 'self'`, `style-src-attr 'none'`, `X-Frame-Options: SAMEORIGIN`, and
`Referrer-Policy: no-referrer`. Style elements and attributes remain forbidden; the exact theme is fetched
under the unchanged `style-src(-elem) 'self'` policy. Script remains self-only and no inline script/style,
`eval`, remote frame or remote connection source is admitted. A policy comparison test holds every other
administrator directive byte-identical. Authentication, live session-generation resolution, same-origin
Origin/Referer evidence, no-store delivery, Cross-Origin-Resource-Policy and the single-use claim are all
rechecked on the document request rather than inferred from a successful render call.

The PHPUnit contract suite replays all four directly applicable published vectors by ID:

- `vector.preview-identity.canonical-preorder`
- `vector.preview-identity.empty-draft`
- `vector.host-sequence.preview.cancel.cross-context`
- `vector.host-sequence.preview.render.cancelled`

It also distinguishes foreign origin, wrong channel, wrong source, replayed sequence and out-of-order
sequence refusals on both transport lanes; holds the exact HTTP wrappers; validates the emitted rendered
payload against `preview-message`; and covers draft identity, expiry, cancellation, supersession,
cross-context isolation and single claim. The Security workflow runs the boundary's negative-path suite
as an explicit P7-C qualification step and retains `build/security/studio-preview-junit.xml` inside the
commit-bound `security-evidence-${GITHUB_SHA}` artifact.

Preview staging is ephemeral presentation activity, not an authoritative Content or Blueprint mutation,
so it does not manufacture a business audit event. The authorization gateway continues to write its
ordinary accountable authorization evidence. A separate fail-closed structured activity recorder emits
only actor, site, resource kind, a resource fingerprint, request/correlation IDs, closed action/outcome,
and stable reason. Its allowlist excludes canonical draft bytes and digest, HTML, grants/context keys,
channel/source identifiers, sequences, markers and marker maps; a test holds that observability boundary.

[ADR 0017](decisions/0017-authenticated-studio-preview.md) records the security and rendering decisions.
Local SQLite conformance is directly runnable. S-F is not evidence for S-G's built browser surface or for
the phase-7 human qualification: CI must still prove the migration and replay ledger on MariaDB, MySQL and
PostgreSQL, the built administrator bundle must exercise the real `PreviewBinding`/iframe sequence in the
browser matrix, and an independent security run must retain the P7-C artifact before Gate B assessment.

### Implemented published Content composition runtime

The public Content handlers now ask one optional `StudioPublishedContentRenderer` after the existing
publication-aware locator has selected a record. The lookup is exact by site, Content-type identity, and
the record's pinned definition version. No binding, or a bound Blueprint in `draft` or `retired`, preserves
the legacy layout and presenter. A `published` artifact is rendered only after its binding identity,
App-owned kind and schema, projected model ID/version/revision, historical Content definition, active public
theme, and every exact block renderer lock have been re-resolved. Once configured, absence or drift is a
typed fail-closed error; it never becomes an accidental legacy page.

`StudioPublishedCompositionGuard::assertCompatible()` owns the reusable schema/owner/model/theme/live-renderer
decision so `artifact.publish` can execute the same guard before accepting a lifecycle transition. The
public request path runs it again to cover package withdrawal or theme/model change after publication.
The decision reconstructs each exact canonical block definition from the current owner-aware registry,
verifies its optional SHA-256 integrity lock and live renderer binding, and interprets the published
property schema over both base properties and every viewport's effective responsive overlay. It also
enforces unique node identities, declared slots, slot cardinality, accepted child types and the exact
Content fields named by entry bindings. A stored artifact can therefore remain readable after a package
change without becoming executable under a different declaration.
`ContentStudioProjector::publishedValues()` reuses the schema-governed lossless value conversion without
fabricating an authenticated actor, because the public Content boundary already made the publication
decision. Producer shares preview traversal, escaping and the live core/signed-extension registry while
omitting authoring markers. Both the ordinary page route and nominated homepage place its HTML in the
internal canonical `page` template. Producer's complete CSS is served from a same-origin SHA-256-addressed
URL. That handler re-resolves the published record, site, locale, canonical path, artifact and live renderer
authority before returning bytes or a 304, so publication or trust withdrawal invalidates an already-held
URL. Navigation, languages, canonical URLs, theme, cache, indexing and site assets remain on the existing
`ContentPageRenderService` path; CSS is never copied into Twig or emitted inline.

## What an extension contributes, and why it is frozen first

Studio models an extension's contributions as a manifest of typed declarations: composition blocks
with a bounded property schema, declared slots and a renderer binding; patterns as reusable composition
structures; inspectors and field controls that edit a contributed field type; design vocabulary
including tokens, recipes and the size roles a theme remaps; and migrations for documents a contributed
block appears in. Its own runtime activates them into an immutable generation with owner rules,
lifecycle states and unresolved-node handling, and its authoring surface validates a declaration
against exactly the rules that runtime enforces, so an author's mistake surfaces at declaration time
rather than at activation.

Kumwe's extension contract carries these as its own classified contribution surfaces — `S-A`, the Gate
A half of phase S. The declaration shapes are the published composition schemas rather than a
paraphrase of them, so the contract an author reads and the contract the runtime enforces cannot drift.
A property schema is bounded by the published schema profile, which is what keeps a contributed block
from smuggling unbounded structure into a stored document. Gate A admitted only inert declarations.
The implemented owner-aware activation primitive resolves all six canonical Studio document kinds,
locks only exact host-renderable blocks, and withdraws disabled or distrusted owners. An
owner-specific executable renderer is required before an extension block enters the palette or preview.
The runtime and signed manifest-6 proof now implement that boundary, including distinct public capability
and owner-local service identifiers; P7-F remains open only until the authoritative browser and
database-backed lifecycle runs retain their CI evidence.

Activation is not yet contextual authoring. The open product integration uses one generic target declaration for
core and extension-owned content areas and filters this same immutable generation for that resolved target, surface,
mode, capability, and permission. An extension does not open a private Studio, configure Editor.js, or copy data
between its own editor and Studio.

The behaviour those declarations get at Gate B is the same lifecycle the platform already guarantees:
a disabled or untrusted owner's blocks stop executing while documents that used them stay readable and
diagnosable, and an unresolved block is represented rather than dropped.

## Contract documents to read before implementing

Start with Studio's sole product authority,
[`docs/product-contract.md`](https://github.com/kumwe/studio/blob/main/docs/product-contract.md), and the App host
mapping in [`docs/studio-composition-authoring.md`](../studio-composition-authoring.md). Then use the protocol
documents in the Studio repository under
[`docs/contracts/`](https://github.com/kumwe/studio/tree/main/docs/contracts):
`commands.md` (the sixteen-command subset, canonical vectors, failure taxonomy),
`blueprint.md` and `content-and-entries.md` (the composition and content artifacts),
`host-adapter.md` (ports, envelope, error categories), `preview.md` (handshake, markers, geometry,
reload and teardown), `media.md` (upload sessions, external sources, policy vectors), `rich-text.md`
(bounded grammar, renderer conformance), `theme.md` (tokens, recipes, size roles), `security.md` and
`security-threats.md` (the enforced threat registry and the pinned content-security baseline),
`versioning-and-migrations.md`, `extension-lifecycle.md`, `localization.md`, and `accessibility.md`.
Decision records live in
[`docs/decisions/`](https://github.com/kumwe/studio/tree/main/docs/decisions); programme state in
[`docs/roadmap/STATUS.md`](https://github.com/kumwe/studio/blob/main/docs/roadmap/STATUS.md).

## Alignment with standing Kumwe rules

The integration is consistent with the covenant and the interface standard, and phase S proves rather
than assumes each point:

- **Stored compositions hold structure, never code.** Studio documents are closed-schema JSON;
  raw markup and styles are rejected by its negative fixtures. This is the same rule the platform
  already applies to stored preferences and expressions.
- **The dashboard remains what the interface standard says it is.** Studio opens from the resolved content
  create/edit target, inline or through a context-preserving route; it does not turn the dashboard into a page
  builder or add a top-level Studio navigation workspace.
- **The current form is a transitional fallback.** Essential navigation, recovery, and declared fallback operations
  retain their server-rendered paths, but the separate Content form must not redefine the completed authoring
  target. Studio itself provides accessible non-drag operation parity, and durable effects always cross PHP host
  authority.
- **Content security tightens rather than relaxes.** The Studio shell is proven in its own repository
  under `default-src 'none'` with a bare self script source and enforced Trusted Types; embedding it
  keeps the administrator policy strict, and the one deliberate change — a same-origin frame for
  authenticated preview — is recorded in ADR 0007.
- **Accessibility is contractual on both sides.** Studio's requirement registry binds keyboard
  completeness, reflow, reduced motion and zero-violation scans to executable checks; the embedded
  surface still passes the platform's own browser, accessibility and locale matrix.
