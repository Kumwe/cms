# Access Control extraction closure — implementation input only

Read-only investigation on 2026-09-07. No production repository was modified.

## Exact observed inputs and release gate

- App source baseline: `960ce8ec00cf724a7cae03e5ba09c4852c9ab54e`.
- SDK installed/released input: `kumwe/extension-sdk v0.2.4`, source
  `d0484b8733eaa57d076f567ffa5e997b9564b5fa`.
- Access Context published input: `kumwe/access-context v0.1.0`, source
  `34241cbd0cc67934536d2921eca14b063be6fb81`.
- The dependency ceiling is **Access Context only**, plus justified platform/PSR dependencies.
- Access Context's fresh external passing release attestation is required before dependent implementation.
  This closure investigation does not substitute for that independent verification.
- App `AGENTS.md`, governance rulings, the Access Control v2 brief and existing source/tests were read.

## Refreshed inventory and namespace map

The App authorization directory currently contains 48 types. Eight are already owned by Access Context;
seven contain host authority/orchestration. This leaves **33 existing portable or separable types**.
`GrantScope` adds one App source and SDK `Capability` adds one upstream source: **35 existing canonical
types**, with the second App AuthorizationDecision merged into the same canonical decision rather than
preserved as a parallel model. Additional decision-state/combiner API should be counted explicitly if added.

The map below proposes the canonical root namespace `Kumwe\Access\<name>`; a final implementation may
choose coherent subnamespaces, but the handoff must freeze its exact chosen map.

For every first-column basename below, the existing FQCN is
`Kumwe\App\Application\Authorization\<basename>` and source is
`src/Application/Authorization/<basename>.php`.

| Existing basename | Proposed canonical FQCN | Extraction treatment |
|---|---|---|
| AuthorizationDecision | Kumwe\Access\AuthorizationDecision | Merge the two existing decision models; stable reason/policy codes; explicitly represent allow/deny/step-up/not-applicable. |
| AuthorizationDecisionRecorder | Kumwe\Access\AuthorizationDecisionRecorder | Neutral port; substitute Context ExecutionContext and canonical Capability. |
| AuthorizationDefinitionLifecycle | Kumwe\Access\AuthorizationDefinitionLifecycle | Preserve active/deprecated enforceable; disabled/retired inert. |
| AuthorizationDenied | Kumwe\Access\AuthorizationDenied | Portable refusal data; preserve policy/reason metadata. |
| AuthorizationGateway | Kumwe\Access\AuthorizationGateway | Port only; Context ExecutionContext, canonical Capability and GrantScope. |
| AuthorizationPolicyRegistry | Kumwe\Access\AuthorizationPolicyRegistry | Neutral registry/lookup/delegation mechanics; remove embedded host membership-category policy. |
| AuthorizationResource | Kumwe\Access\AuthorizationResource | Portable resource identity; harden raw controls before trimming. |
| AuthorizationResourceOwnershipUnknown | Kumwe\Access\AuthorizationResourceOwnershipUnknown | Neutral ownership-port failure. |
| CapabilityDefinition | Kumwe\Access\CapabilityDefinition | Canonical Capability/GrantScope, owner namespace and delegation metadata. |
| CapabilityDefinitionRegistry | Kumwe\Access\CapabilityDefinitionRegistry | Neutral registration/collision/sorted owner removal. |
| CompositeResourceOwnershipReferences | Kumwe\Access\CompositeResourceOwnershipReferences | Neutral port composition, restrict returned sites to requested sites. |
| MembershipContextValidator | Kumwe\Access\MembershipContextValidator | Existing freshness port; Context SiteContext/MembershipContext types. |
| OwnershipNarrowingRefused | Kumwe\Access\OwnershipNarrowingRefused | Portable refusal type; host orchestration remains outside. |
| OwnershipNarrowingUnbounded | Kumwe\Access\OwnershipNarrowingUnbounded | Portable refusal type. |
| OwnershipScope | Kumwe\Access\OwnershipScope | Context SiteContext; identity equality deliberately ignores changed member snapshots. |
| OwnershipScopeChangeRejected | Kumwe\Access\OwnershipScopeChangeRejected | Portable refusal type. |
| OwnershipScopeLevel | Kumwe\Access\OwnershipScopeLevel | Site/group/installation reach ordering. |
| OwnershipScopeNotPermitted | Kumwe\Access\OwnershipScopeNotPermitted | Portable refusal type. |
| OwnershipScopeNotSiteBound | Kumwe\Access\OwnershipScopeNotSiteBound | Reject a group/installation where one site is required. |
| OwnershipScopeRule | Kumwe\Access\OwnershipScopeRule | Portable allowed-level matrix. |
| ResourceOwnership | Kumwe\Access\ResourceOwnership | Reject collection ownership; enforce supplied ownership policy. |
| ResourceOwnershipReferences | Kumwe\Access\ResourceOwnershipReferences | Neutral bounded-reference lookup port. |
| ResourceOwnershipScopePolicy | Kumwe\Access\ResourceOwnershipScopePolicy | Registry mechanics only; App passes its reserved category matrix explicitly. |
| ResourcePolicyDefinition | Kumwe\Access\ResourcePolicyDefinition | Canonical Capability; replace App SystemIdentity dependency with validated neutral identity data. |
| ResourcePolicyRegistry | Kumwe\Access\ResourcePolicyRegistry | Exact capability ownership; target overlap rejection; no trust activation. |
| ResourcePolicyTarget | Kumwe\Access\ResourcePolicyTarget | Preserve string identifiers, wildcard and overlap semantics; numeric-key defect must be fixed. |
| ResourceSiteOwnership | Kumwe\Access\ResourceSiteOwnership | Neutral lookup port. |
| ResourceSiteOwnershipConflict | Kumwe\Access\ResourceSiteOwnershipConflict | Neutral compare-and-swap conflict contract. |
| ResourceSiteOwnershipWriter | Kumwe\Access\ResourceSiteOwnershipWriter | Neutral writer/CAS port; no database implementation. |
| SiteGroup | Kumwe\Access\SiteGroup | Context SiteContext normalization; nonempty sorted string member snapshot. |
| SiteGroupRegistry | Kumwe\Access\SiteGroupRegistry | Neutral group lookup port. |
| SiteGroupUnknown | Kumwe\Access\SiteGroupUnknown | Neutral unavailable-group failure. |
| SiteGroupWriter | Kumwe\Access\SiteGroupWriter | Neutral writer port; administration/audit policy remains App. |

Additional exact source maps:

| Existing FQCN / source | Proposed canonical FQCN | Treatment |
|---|---|---|
| Kumwe\App\Identity\Domain\GrantScope — src/Identity/Domain/GrantScope.php | Kumwe\Access\GrantScope | Global/named exact coverage; no User or role dependency. |
| Kumwe\Extension\Spi\Identity\Domain\Capability — SDK src/Spi/Identity/Domain/Capability.php | Kumwe\Access\Capability | Transfer canonical ownership; do not depend upward on SDK. |
| Kumwe\App\Identity\Domain\AuthorizationDecision — src/Identity/Domain/AuthorizationDecision.php | Kumwe\Access\AuthorizationDecision | Merge validated reason vocabulary and allow/deny behavior into the first row; no second decision class. |

The existing decision models do not have step-up or not-applicable states. The Identity policy interface
uses null for abstention; the Application decision exposes an unvalidated boolean/policy/reason triple.
Do not claim a namespace-only extraction satisfies the explicit four-state brief. Design the neutral
decision algebra and denial precedence, keep concrete user/role evaluation in App, and document the
intentional pre-1.0 API change and adapters required at adoption.

## Required seams, dependency closure and DI

1. `AuthorizationGateway` and `AuthorizationDecisionRecorder` can reference
   `Kumwe\Context\Value\ExecutionContext` directly. Its Principal port deliberately has no `allows()`;
   therefore the concrete App gateway cannot be transplanted by simply changing imports.
2. `MembershipContextValidator`, site/group/ownership models and writer ports use Context values.
   Do not bring the eight already-extracted context classes into Access Control.
3. `ResourcePolicyDefinition` currently holds App `SystemIdentity` enum cases. Keep the closed enum and
   creation authority App-owned. Use a reviewed neutral string/identity representation and snapshot it;
   do not hold arbitrary mutable external actor objects in a supposedly immutable definition.
   Extension owners must still be rejected if they declare any system identity.
4. `AuthorizationPolicyRegistry::requiresMembershipContext()` hardcodes approval_request,
   business_record, organization, organization_membership, resource_policy, separation_duty_rule and
   workspace. Those are App security decisions. A neutral registry may receive the relevant target set
   explicitly, but App must supply the identical set; missing configuration must not silently widen access.
5. `ResourceOwnershipScopePolicy::RESERVED` is a 44-category App policy table, including accounting,
   content, Studio/identity/extension and business resource decisions. Preserve this table in App and
   inject it into the portable rule mechanism. Preserve isolation-by-default for unknown categories,
   reserved-category rejection and idempotent same-rule registration.
6. The App `AuthorizationPolicy` interface depends on `User` and `CapabilityGrant`; the App
   `AuthorizationService` checks `User::canAuthenticate()` before evaluating policies. Both remain App.
   Extract only neutral decision aggregation if needed for package-owned denial-precedence behavior.
7. Pure values/exceptions/contracts do not need DI. Real registries and composed inspectors need explicit
   final factories and a ConfigProvider when exported as injected services. No gateway alias should point
   to App's concrete policy; no container/provenance object should become globally accessible configuration.
8. Runtime source must not import App, SDK, Contribution, Doctrine, clock/UUID/audit infrastructure,
   HTTP/session/role/User classes or native execution. Any PSR container usage belongs to factories only.

## SDK successor and extension contributions

SDK v0.2.4 owns `Kumwe\Extension\Spi\Identity\Domain\Capability`. SDK references it directly in:

- `src/Spi/Contribution/AdministratorNavigationDefinition.php`
- `src/Spi/Contribution/AdministratorRouteDefinition.php`
- `src/Spi/Portal/Contribution/PortalNavigationDefinition.php`
- `src/Spi/Portal/Contribution/PortalRouteDefinition.php`

SDK `src/Manifest/ManifestContributions.php` also repeats capability/resource-policy/lifecycle/target
validation in `fromArray()`; `ManifestContributionGraphValidator.php` owns manifest graph cross-references.
The manifest graph and wire grammar remain SDK responsibilities, while canonical authorization
definition invariants must compose Access Control in a separately released SDK successor.

Required order: Access Context release verification -> Access Control Phase 1/release/verification ->
SDK successor adopts canonical Capability and definition semantics, updates its public API/generation,
conformance/resource pins and its consumers -> verified SDK successor -> App adoption. No SDK dependency
from Access Control, no historical alias, and no coexistence of two independently maintained Capability owners.
Contribution's separately required SDK adoption must be coordinated into this successor, not bypassed.

App extension wrappers currently include:

- `src/Extension/Contribution/CapabilityDefinition.php`
- `src/Extension/Contribution/ResourcePolicyDefinition.php`
- `src/Extension/Contribution/CapabilityDefinitionRegistry.php`
- `src/Extension/Contribution/ResourcePolicyDefinitionRegistry.php`

Their host activation/ownership/composition remains App; at adoption they compose the canonical package
model rather than reproduce invariant checks. Capability labels/descriptions are declaration metadata;
do not make the Access Control package depend on Localization or Contribution to move these wrappers.
`CanonicalManifestInterpreter.php`, `CanonicalManifestActivator.php`, `CoreExtensionContributions.php`,
`CoreContributionRegistrar.php` and `ExtensionContributionRegistrySet.php` remain App authority/composition.

## Production code that stays App

Exact retained types in the authorization directory:

- `DenyByDefaultAuthorizationGateway`: provenance checks, `AuthenticatedPrincipal::allows()`, global
  extension-manager bootstrap, collection/queue/report ownership exceptions, membership authority,
  audit fail-closed behavior and concrete decisions.
- `AuthorizationAuditUnavailable`, `StructuredLogAuthorizationDecisionRecorder`: host audit/logging coupling.
- `ResourceOwnershipScopeService`, `SiteGroupAdministration`: transactions, AuditRecorder/AuditEvent,
  permission checks, clock, UUID and concurrent mutation orchestration.
- `SystemIdentity`, `SystemPrincipal`: closed host system-actor list and issuance authority.

The eight Context types are excluded because their owner is already `kumwe/access-context`, not because
they should remain duplicated permanently. Their removal belongs to the separate Context App adoption.

Also retain `Identity/Application/Authentication/{AuthenticatedPrincipal,PrincipalGrant}.php`,
`Identity/Domain/{User,CapabilityGrant}.php`, `Identity/Application/Authorization/{AuthorizationPolicy,
AuthorizationService,RoleGrantPolicy}.php`, all Identity administration/authentication code,
`Infrastructure/Authorization/Doctrine*.php`, audit/persistence/migrations, route middleware, business
query/policy compilation and active/trusted extension runtime composition.

A string-FQCN/import scan found **155 non-candidate App production consumer files** for these 35 existing
portable types. This is an initial consumer inventory, not a promise that same-namespace references,
configuration strings and public SDK downstream consumers are exhausted; implementation must generate
and freeze its full handoff inventory after its final public map is settled.

## Test ownership — move behavior, retain App responsibility

Move whole portable suites after adapting to canonical APIs:

- `tests/Unit/Identity/Domain/GrantScopeTest.php`
- `tests/Unit/Identity/Domain/AuthorizationDecisionTest.php` (merge canonical decision semantics)
- `tests/Unit/Application/Authorization/ResourcePolicyDefinitionTest.php` (neutral system identity fixtures)
- `tests/Unit/Application/Authorization/ResourcePolicyRegistryTest.php`
- SDK Capability's unit suite belongs with the transferred canonical source in the SDK successor train.

Split `tests/Unit/Application/Authorization/OwnershipScopeModelTest.php`:

- Package: site/group containment, empty/deduplicated/sorted members, requireSite refusal, reach ordering,
  generic injected category rules, unknown-isolation fallback, fixed registration, collection refusal,
  same-group identity equality when membership changes.
- App: exact accounting categories cannot widen; exact shared-master categories can use a group;
  the complete built-in reserved category configuration and trusted contribution registration.

Split `AuthorizationPolicyRegistryTest.php`:

- Package: disabled policy refusal, target-based behavior from explicit configuration.
- App: business_record's membership requirement and the exact sensitive target set supplied at wiring.

Keep these whole App suites (remove CoversClass attribution to vendor-owned values at adoption):

- `AdapterAuthorizationParityTest.php`, `ApplicationAuthorizationTest.php`,
  `BusinessGroupOwnershipTest.php`, `KernelAuthorityBoundaryTest.php`,
  `SiteScopeContainmentIsNotAWideningTest.php`.
- `DoctrineResourceSiteOwnershipTest.php`, `DoctrineResourceSiteOwnershipWriterTest.php`,
  `ResourceOwnershipScopeServiceTest.php`, `SiteGroupAdministrationTest.php`,
  `StructuredLogAuthorizationDecisionRecorderTest.php`.
- Identity `AuthorizationServiceTest.php`, `RoleGrantPolicyTest.php`, `CapabilityGrantTest.php`:
  inactive users, role grants and concrete policy dispatch remain App. A package decision-combiner test
  may separately own pure denial precedence, but do not delete the inactive-user/boundary tests.
- All `tests/Integration/Authorization/`, `tests/Integration/Identity/AccessControlIntegrationTest.php`,
  business-record policy enforcement, generated related-policy, trust revocation, direct invocation,
  token audience and delivery parity/security tests.
- Extension `CoreContributionActivationTest`, `CanonicalManifestInterpreterDriftTest`, active-set,
  canonical-binding, runtime trust/lease and restricted-container tests remain host conformance tests.

Phase 1 writes this move/retain manifest but does not delete App tests yet. Phase 2 removes duplicate
package-class unit tests simultaneously with their old production classes and regenerates App test,
coverage attribution, dependency, capability and governance inventories.

## Confirmed defects and regression candidates

Read-only PHP 8.5 probes against the current App autoloader confirmed:

1. `new ResourcePolicyTarget('record', ['123'])` exports `identifiers: [123]` and returns false for
   `matches(AuthorizationResource::item('record', '123'))`. PHP coerces numeric string array keys.
2. `new SiteGroup('group', 'Group', ['123'])` exports integer member 123 and `contains(SiteContext::fromString('123'))`
   is false for the same reason. `CompositeResourceOwnershipReferences` has the same string-key collection
   pattern; retain exact string values independently from lookup keys.
3. `GrantScope::named('site', "\0abc")->identifier()` returns `abc`: trim erases forbidden raw controls.
   `AuthorizationResource::item()` and SDK `Capability::fromString()` also trim before validating;
   SiteGroup normalizes its identifier before checking. Test raw leading/trailing NUL, tabs, CR/LF/DEL,
   embedded controls, numeric strings, case and valid whitespace normalization deliberately.

Further necessary hostile cases:

- Source bounds limit unique results after consuming entire iterables. Huge duplicate iterables can
  bypass allocation/work limits; enforce consumed-entry bounds incrementally as a documented change.
- `SiteGroup` validates a trimmed local name but stores untrimmed promoted `$name`; decide and test the
  exact exported name contract rather than assuming it was normalized.
- ResourcePolicyTarget empty identifier list means all identifiers; '*' cannot be an explicit target
  identifier. AuthorizationResource item('*') intentionally reaches collection semantics; do not
  accidentally change App's collection handling when fixing target validation.
- Capability owner grammar and dotted owner-prefix checks; core versus extension ownership; duplicate
  exact IDs; foreign capability reference; overlap irrespective of lifecycle; deterministic ordering;
  disabling must not permit future re-registration collisions or silently retain active policy authority.
- `CapabilityDefinitionRegistry` and `ResourcePolicyRegistry` expose independent removal operations;
  test that removing a capability cannot leave a queryable authorization policy that the composed
  registry interprets as authority. Public direct-registry semantics must be documented and fail closed.
- Missing/stale/unverifiable membership must never create additional authority; concrete App gateway
  deliberately retains base grants on membership infrastructure failure. Package freshness-port tests
  and App gateway tests have different subjects and both remain necessary.
- ResourceOwnershipScopePolicy defaults unknown types to SiteOnly; group equality is identity equality,
  not a membership snapshot/CAS guarantee. Keep mutation/version/concurrency checks at the host writer.
- Missing ownership, disabled group/site, nonenumerable installation narrowing, foreign authority
  provenance, system-only actions, extension system identity declarations and audit failure all deny.
- Four-state decisions require bounded stable machine codes and deterministic refusal precedence;
  never allow an abstention or a step-up request to become a boolean true through conversion.

