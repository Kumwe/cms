# Architecture map

This is the folder map. Rules live in [principles.md](principles.md) and
[`layers.json`](layers.json). The operator checklist is [`AGENTS.md`](../../AGENTS.md).

Kumwe is a content-management application and an extension host, and also a typed
business-record runtime. Both halves share one composition root, one authorization
decision, and one relational transaction. They are not the same module.

---

## Composition root

There is one. Do not add a second pipeline, a static container, or a service locator.

| Path | Role |
|---|---|
| `src/Kernel/ContainerFactory.php` | Wires the Laminas ServiceManager-backed kernel container, the Mezzio pipeline, routes, jobs, MCP, extension boot |
| `src/Kernel/Configuration/` | Env-validated process configuration |
| `bootstrap/container.php` | Full container for HTTP and tests |
| `bootstrap/console.php` | Full vs recovery container for CLI |
| `public/index.php` | HTTP front controller. Recovery container for `/health/*` and the trust-key API |
| `bin/kumwe` | CLI front controller |

`config/` is not a settings tree. It currently holds observability only. User-visible
settings go through `Site\Application\SiteSettings`. Secrets stay in the environment.

---

## Layers

Enforced by `composer architecture:policy` against `layers.json`. A namespace the
graph cannot classify is itself a failure. This includes dependencies crossing into
the extracted `Kumwe\Conversion`, `Kumwe\Extension`, and `Kumwe\Producer`
packages: a `Kumwe\*` target is never treated as an opaque vendor dependency.

```
shared → domain → application → {infrastructure, presentation} → delivery
kernel may see every layer
```

| Layer | May depend on | Owns |
|---|---|---|
| shared | nothing | Framework-free types used in more than one layer |
| domain | shared | State, invariants, value objects |
| application | shared, domain | Use cases, authorization, transactions, audit |
| infrastructure | shared, domain, application | Persistence, rendering, protocol adapters, clocks, logs |
| presentation | shared, domain, application | View models, presenters, form mappers |
| delivery | shared, domain, application, presentation | HTTP, console, worker, machine-surface entry |
| kernel | everything | The composition root |

App-owned classification is the first namespace segment (`Domain`, `Application`, …)
unless `layers.json` `namespace_prefixes` overrides it. Extracted packages never
inherit that shorthand: each public namespace App imports needs an explicit
longest-prefix rule. Rules that surprise people:

- extracted Conversion contracts, decimals, and values are shared while its provider
  pipelines and registries are application mechanics; Extension SDK SPI types retain
  their declared domain, application, or presentation role instead of gaining one
  blanket permission. Automation/idempotency, BusinessRecord queries, custom-business
  handlers, bindings, and host callbacks are application contracts, while its package
  and author-toolchain services are application mechanics. Producer canonical and
  schema types are shared while its error taxonomy, CSS, render, and wire engines are
  application mechanics;
- `Kumwe\App\Http` → delivery (public site + shared middleware)
- `Kumwe\App\InterfaceStandard` → domain
- `Kumwe\App\Extension\{Contribution,Runtime,Development}` → application
- `Kumwe\App\BusinessSecurity\Policy` → domain
- `Kumwe\Context\{Contract,Exception,Value}` → shared (`kumwe/access-context` owns the site,
  organization, workspace, membership, surface, strength, step-up and execution-context values the
  ADR 0012 in-place classification of `SiteContext` and `AuthenticatedSurface` anticipated)
- `ContributionOwner` and `ContributionDefinition` → domain (ADR 0012);
  `ContributionDefinitionChecksum` explicitly stays application

Wrong-way edges that already existed sit in [`dependency-baseline.json`](dependency-baseline.json)
with an owner, a finding, and an expiry. The baseline only shrinks. `P3-D` emptied its
Domain-to-Application family: thirteen entries resolved, and the manifest's contribution parse is
the one decision-approved exact interface (`approved_interfaces`, ADR 0012). The remaining
recorded families keep their owner, finding, and expiry and belong to the seams that own them.

---

## Governance

Portable behaviour belongs to Kumwe packages; App composes them. The policy is `AGENTS.md`
section 7, items 11 and 12; the guide is [governance/README.md](governance/README.md); two
gates in `composer qa`, `kumwe:capability-index-check` and `kumwe:core-growth-check`, enforce it.

| Path | Role |
|---|---|
| `docs/architecture/governance/` | Guide, rulings, record schemas and examples, legacy registry, Core Growth baseline |
| `docs/architecture/capability-index.md` | Generated: what every locked Kumwe package owns. Never hand-edited |
| `docs/architecture/core-growth/` | Core Growth Records: why approved portable-layer growth stays in App |
| `docs/architecture/migrations/` | Ledger, change sets, conflicts, trains and release evidence of package adoption |
| `docs/architecture/non-roadmap/` | Non-roadmap records (`NRM-YYYY-NNN.yaml`) for governance and relocation work |
| `tools/Governance/` | Dependency-free classes behind both gates: YAML subset, schemas, scanner, index, gate |
| `tools/generate-capability-index.php` | `--write` regenerates, `--check` refuses drift, `--digest` prints it |
| `tools/verify-core-growth.php` | Compares `src/` with the baseline and the index; `--record` re-records the baseline |

---

## Request flow

```
HTTP    public/index.php
          recovery? → ContainerFactory::createRecovery()   (no extension PHP)
          else      → bootstrap/container.php → ContainerFactory::create()
            → Mezzio
            → RequestId → LocaleNegotiation → Metrics → ProblemDetails
              → TrustedProxy → TrustedHost → BodyLimit → SecurityHeaders
              → ExtensionRuntimeGeneration (full boot)
              → Route
              → AdministratorSession + Authorization    (admin)
              → PortalSession + Authorization           (portal)
              → BearerAuthentication                    (REST / MCP HTTP)
            → PSR-15 handler
                 /                 src/Http/Handler + Presentation\SiteRenderer
                 /administrator/*  Administrator\Http\Handler
                                   or <Module>\Delivery\Administrator
                 /portal/*         Portal\Http\Handler
                                   or <Module>\Delivery\Portal
                 /api/v1/*         Delivery\Http\Api
                                   or <Module>\Delivery\Api
                 /mcp              Delivery\Http\Mcp\McpHttpHandler

CLI     bin/kumwe → bootstrap/console.php → Delivery\Console\ConsoleApplication
MCP     HTTP /mcp  (audience kumwe-mcp)
        stdio `kumwe mcp:serve` → Infrastructure\Mcp\KumweMcpServerFactory
Jobs    Application\Automation\Worker / Scheduler  (DoctrineJobQueue)
```

All of the above call the same application services.

Authorization is application-layer. `Application\Authorization\AuthorizationGateway`
is the port every service, command, REST handler, and MCP tool uses. Hiding a
navigation link is not authorization.

---

## Generated business path

```
any surface
  → BusinessSurfaceCatalog          omit unauthorized entities, fields, operations
  → BusinessSurfaceService          map UI/MCP operations onto record commands
  → BusinessRecordQueryFactory      closed filter/sort/projection AST
  → BusinessRecordProjector         omit withheld handles
  → BusinessRecordService           the sole transaction boundary
       authorize → validate → CAS write → relations → workflow → approval
       → revision → audit → idempotency
```

`BusinessRecordService` (`src/BusinessRecord/Application/BusinessRecordService.php`)
is the public write/read API for typed business records. CMS content is
`ContentService`. Architecture tests forbid them mixing.

When adding a use case: application service + capability + administrator route if
end users manage it + CLI if operators need it + REST/OpenAPI if integrations need
it + MCP only when the AI interaction is safe. Add a parity test.

---

## Bounded contexts under `src/`

Two axes at once: **layer** (above) and **context** (folder). Cross-cutting
application code lives in `src/Application/` with no context prefix. Contexts that
grew later keep their own `Application/Domain/Infrastructure/Delivery` trees.

| Context | What it is | Key type |
|---|---|---|
| `Kernel` | Composition root | `ContainerFactory` |
| `Shared` | Framework-free kernel types | `CanonicalJson`, `DatabaseTablePrefix` |
| `Application` | Cross-cutting use cases | `AuthorizationGateway`, `TransactionManager`, `Worker` |
| `Infrastructure` | Cross-cutting adapters | Doctrine, Redis, MCP protocol, observability |
| `Delivery` | CLI, REST, MCP HTTP, dashboard decoders | `ConsoleApplication` |
| `Http` | Public site + shared PSR-15 middleware | `ReadinessHandler`, `BearerAuthentication` |
| `Administrator` | CMS admin delivery and presentation | `AdministratorRenderer` |
| `Portal` | Ordinary-user surface | isolated session, membership, CSRF |
| `Identity` | Users, roles, tokens, sessions, TOTP | `AuthorizationService` (grant combiner — not the gateway) |
| `Content` | CMS entries, models, revisions | `ContentService` |
| `Studio` | Host-side Studio authority, artifacts/recovery, media/resources, preview and published composition | Producer `Dispatcher` with App `HostAdapterInterface` implementation |
| `Workflow` | Content-type workflow definitions | `ContentTransitionAuthorizer` |
| `Navigation` | Menus | `NavigationService` |
| `Media` | Media library | filesystem storage |
| `Site` | Public page locator, site settings | `SiteSettings` |
| `BusinessDefinition` | Immutable definition versions | `BusinessDefinitionService` |
| `BusinessSchema` | Plan → approve → execute DDL | `BusinessSchemaService` |
| `BusinessRecord` | Typed rows, documents, money, secrets | `BusinessRecordService` |
| `BusinessSecurity` | Field/record policy, approvals | `ApprovalService` |
| `BusinessSurface` | Generated adapters + custom handlers | `BusinessSurfaceService` |
| `BusinessReporting` | Reports, projections, exports | `ReportService`, `ExportService` |
| `BusinessIntegration` | Events, outbox/inbox, contributed jobs | `OutboxDispatcher` |
| `Extension` | Package host | `ExtensionManager` |
| `Audit` | Append-only ledger | anchors, export, verify, retention |
| `Localization` | Catalogues, locale negotiation, Twig `t()` | compiled catalogues |
| `OpenApi` | Compile caller-specific contracts | `OpenApiHandler` |
| `Presentation` | Site Twig, dashboard composer, Vite | not the admin renderer |
| `InterfaceStandard` | KIS surface declarations | domain |
| `Demo` | VDM / content profile install | `resources/demo/` |
| `Automation` | `CronExpression` only | the engine is `Application\Automation` |

`src/Studio/` is App's authoritative PHP host adapter, not a second page builder. The portable command engine,
45-block catalog, patterns, private Editor.js adapter and renderer-web stay in `kumwe/studio`; App supplies trusted
PHP ports, Content operations, and Twig delivery. Browser assets are compiled before deployment, and production has
no Node.js, npm, Vite, development-server, or server-side JavaScript dependency.

The product target is contextual: one generic core/extension target resolver opens the same resource-bound Studio
session from an authorized create/edit surface, inline or expanded. App does not add a top-level Studio navigation
workspace; a full-screen route remains bound to its originating resource. The current administrator integration
does not yet implement that target: it composes a
Blueprint-only route for an existing Content-type version through `src/Administrator/Http/Handler/`,
`assets/administrator/components/studio-*.ts`, and `templates/administrator/studio-composition.twig`; Content model
and Entry writes remain separate. Exact released package and corpus bytes live in `resources/studio-contract/` and
move only as one coordinated family. Product/status authority and the implementation ladder are linked from the
single App host record, [`docs/studio-composition-authoring.md`](../studio-composition-authoring.md).

---

## Five HTTP trees

A new HTTP handler has no single home. Use this convention; do not add a sixth tree.

| Tree | What it actually is |
|---|---|
| `src/Http/` | Public site + shared middleware |
| `src/Delivery/Http/` | REST + MCP HTTP + dashboard decoders |
| `src/Administrator/Http/` | CMS admin handlers |
| `src/Portal/Http/` | Portal handlers |
| `<Module>/Delivery/{Administrator,Api,Portal,Console,Browser}/` | Context-owned adapters |

- CMS admin page → `Administrator\Http\Handler`
- Generated business UI → `BusinessSurface\Delivery\{Administrator,Portal,Browser}`
- Definition / schema / report admin → that module's `Delivery\Administrator`
- REST for a later context → module `Delivery/Api`
- REST for older CMS surfaces → `Delivery\Http\Api`

---

## Two authorization stacks

Touch the right one.

| Question | Owner |
|---|---|
| May this actor do X on this surface? | `Application\Authorization\AuthorizationGateway` |
| How are grants stored on a user? | `Identity\Application\Authorization` |
| Which rows and fields may this membership see? | `BusinessSecurity\Policy` |

---

## Extensions

1. ZIP with `kumwe.json` at the root.
2. Inspect → signature / trust → migrations → activate. The database is
   authoritative; there is no per-request directory scan.
3. A signed runtime publication is materialized at process start
   (`extension:runtime:materialize`). Stale workers exit.
4. Provider implements `ExtensionServiceProvider`. The signed manifest owns contribution declarations;
   an optional `ExtensionBindingProvider` binds owner-scoped executable implementations through the SDK
   `ExtensionBindingRegistrar` during a closed activation phase.
5. Custom business views and actions are typed handlers. No PSR request, no DBAL,
   no container. Dispatched by `CustomBusinessSurfaceDispatcher`.
6. The public PHP surface is published by `kumwe/extension-sdk` and installed at
   `vendor/kumwe/extension-sdk/resources/contract/{classification,generations}.json`,
   pinned by `composer extension:contract`. Everything under `Kumwe\App\` is internal.

No `Kumwe\App\` service is extension-author API. An extension receives only neutral SDK ports and may bind its
own executable implementations to the exact owner-scoped declarations admitted from its signed manifest. That
public boundary is **not a sandbox**: admitted PHP still has full process authority.

Runtime volume: `extensions/` (empty in git). Examples: `examples/extensions/`.
Scaffold templates and conformance toolchain: the `kumwe/extension-sdk` package.

---

## Surrounding trees

| Path | Role |
|---|---|
| `public/` | The only tree the web server should serve |
| `templates/{site,administrator,portal,interface-standard}/` | Twig |
| `assets/` | Vite sources |
| `api/openapi/` | Checked-in OpenAPI + generations |
| `resources/localization/` | XLIFF + compiled PHP catalogues |
| `resources/demo/` | VDM and content demo profiles |
| `storage/` | Runtime state, not source |
| `tests/Architecture/` | Boundary and gate-truth tests |
| `vendor/kumwe/extension-sdk/resources/fixtures/` | Canonical signed extension generation fixtures |

---

## Known dual homes

These are documented so new work does not make them worse. Collapsing them is
Lane M / `P3-D` / `V2-ARC-002` work, in its own pull request.

1. **Five HTTP trees** — convention in the table above.
2. **Two Application homes** — `src/Application/` is cross-cutting; `<Context>/Application/`
   is that context. AuthZ types (`SiteContext`) live in the cross-cutting tree.
3. **Two presentation stacks** — `src/Presentation/` (site Twig, dashboard, theme
   recovery) vs `Administrator\Presentation` vs `Portal\Presentation` vs
   `BusinessSurface\Presentation` vs `InterfaceStandard`.
4. **Automation split** — `src/Automation/Domain/CronExpression.php` vs the engine
   in `src/Application/Automation`.
5. **MCP** — protocol adapter in `Infrastructure\Mcp` (correct layer); HTTP wrapper
   in `Delivery\Http\Mcp`.
6. **CMS vs business** — deliberate product split, not accidental duplication.
   Do not unify `Content` and `BusinessRecord`.

If a change would add a dependency the graph forbids, invert it rather than growing
the baseline.
