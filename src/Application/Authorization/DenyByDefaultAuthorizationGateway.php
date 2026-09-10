<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Identity\Domain\GrantScope;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\MembershipContext;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;

/**
 * The authorization gateway every guarded operation in Kumwe runs through, refusing whatever it cannot
 * positively justify.
 *
 * A decision is allowed only when four independent checks agree: the execution context was minted by this
 * installation's own authority, the policy registry declares the action legal on that resource type, the
 * site the caller is executing in is inside the scope that owns the resource, and the caller's authority
 * covers the request — a principal's scoped grants, or the system identities explicitly named by the
 * matching typed resource policy. For a resource owned by a single site — which is every resource on an
 * installation that declares no group — the containment test is the identifier equality it replaced,
 * evaluated on the same one value, so nothing an installation could reach before or after has changed.
 * Every other outcome denies, and each decision names the policy and reason that settled it, so the audit
 * trail explains itself rather than merely recording a verdict. Recording happens before the decision is
 * acted on, and an allowed decision whose audit record cannot be written is escalated rather than
 * proceeding unlogged.
 *
 * @since  2.0.0
 */
final readonly class DenyByDefaultAuthorizationGateway implements AuthorizationGateway
{
    /**
     * Wire the gateway to the authority, policy table, ownership registry and audit sink it decides with.
     *
     * @param  object                         $provenance   Authority object contexts must have been minted
     *         with; anything else is untrusted.
     * @param  AuthorizationPolicyRegistry    $policies     Owner-aware typed capability and resource-policy
     *         registry shared with the contribution runtime.
     * @param  MembershipContextValidator     $memberships  Live authority that revalidates contextual
     *         organization and workspace scopes.
     * @param  ResourceSiteOwnership          $ownership    Resolver for the scope owning a given resource.
     * @param  AuthorizationDecisionRecorder  $decisions    Sink every decision is written to before it is acted on.
     *
     * @since  2.0.0
     */
    public function __construct(
        private object $provenance,
        private AuthorizationPolicyRegistry $policies,
        private MembershipContextValidator $memberships,
        private ResourceSiteOwnership $ownership,
        private AuthorizationDecisionRecorder $decisions,
    ) {
    }

    /**
     * Evaluate an action against a resource and record the outcome before handing it back.
     *
     * The decision is audited whether or not it was allowed, so a caller that only wants to know whether
     * an operation would be permitted — deciding what to render, say — still leaves a trail. Use
     * `assertAllowed()` instead wherever a denial must stop the caller.
     *
     * @param   ExecutionContext       $context   Caller identity, site and provenance the work runs under.
     * @param   Capability             $action    Capability being exercised.
     * @param   AuthorizationResource  $resource  Target the action is aimed at.
     *
     * @return  AuthorizationDecision  The verdict together with the policy and reason that produced it.
     *
     * @throws  AuthorizationAuditUnavailable  When an allowed decision could not be recorded.
     *
     * @since   2.0.0
     */
    public function decide(
        ExecutionContext $context,
        Capability $action,
        AuthorizationResource $resource,
    ): AuthorizationDecision {
        $decision = $this->evaluate($context, $action, $resource);
        $this->record($context, $action, $resource, $decision);

        return $decision;
    }

    /**
     * Evaluate an action and stop the caller unless it is permitted.
     *
     * This is the guard to place ahead of any state change: it returns normally only on an allow, and the
     * decision is recorded either way.
     *
     * @param   ExecutionContext       $context   Caller identity, site and provenance the work runs under.
     * @param   Capability             $action    Capability being exercised.
     * @param   AuthorizationResource  $resource  Target the action is aimed at.
     *
     * @return  void
     *
     * @throws  AuthorizationDenied  When the decision is a denial, carrying its policy and reason.
     * @throws  AuthorizationAuditUnavailable  When an allowed decision could not be recorded.
     *
     * @since   2.0.0
     */
    public function assertAllowed(
        ExecutionContext $context,
        Capability $action,
        AuthorizationResource $resource,
    ): void {
        $decision = $this->decide($context, $action, $resource);

        if (!$decision->allowed) {
            $this->deny($context, $action, $resource, $decision);
        }
    }

    /**
     * Assert that the caller may hand a capability on to someone else within a scope.
     *
     * Delegation is capped by the caller's own effective authority, so a principal can only hand on what
     * its own grants already cover. A grant over the requested scope satisfies that ceiling, and so does
     * one over the site the caller is executing in, since a site-wide holder may already act on every
     * resource the scope could name. A scope naming a concrete resource must additionally be owned by
     * that same site. Organization and workspace grants enter the ceiling only after the context's exact
     * membership snapshot is revalidated; a global or site grant remains sufficient independently.
     * An installation administrator holding global `extensions.manage` may explicitly bootstrap the
     * first grant of an active, human-delegatable extension capability. That narrow exception is needed
     * because an owner-new capability has no possible holder before its first grant; it never applies to
     * core or system-only actions. System identities never delegate: they carry no grants to draw a ceiling from.
     *
     * @param   ExecutionContext  $context  Caller identity, site and provenance the work runs under.
     * @param   Capability        $action   Capability the caller wants to grant onward.
     * @param   GrantScope        $scope    Scope the new grant would be issued in.
     *
     * @return  void
     *
     * @throws  AuthorizationDenied  When the delegation exceeds the caller's authority, the registry
     *          forbids delegating this action in this scope, or the scope's resource has no recorded owner.
     * @throws  AuthorizationAuditUnavailable  When an allowed decision could not be recorded.
     *
     * @since   2.0.0
     */
    public function assertCanDelegate(
        ExecutionContext $context,
        Capability $action,
        GrantScope $scope,
    ): void {
        $identifier = $scope->identifier() ?? '*';
        $type = $scope->isGlobal() ? 'capability' : $scope->type();
        $resource = AuthorizationResource::item($type, $identifier);
        $principal = AuthenticatedPrincipal::of($context);
        $requested = [$scope];

        if (!$scope->isGlobal() && $scope->type() !== 'site') {
            $requested[] = GrantScope::named('site', $context->site()->identifier());
        }
        if (!$scope->isGlobal() && in_array($scope->type(), ['organization', 'workspace'], true)) {
            $requested = [GrantScope::named('site', $context->site()->identifier())];
            if ($context->hasProvenance($this->provenance) && $principal !== null) {
                foreach ($this->currentMembershipScopes($context, $resource) as $membershipScope) {
                    if (
                        $membershipScope->type() === $scope->type()
                        || ($scope->type() === 'workspace' && $membershipScope->type() === 'organization')
                    ) {
                        $requested[] = $membershipScope;
                    }
                }
            }
        }

        try {
            $siteMatches = $scope->isGlobal()
                || $scope->type() === 'site'
                || $this->scopeFor($context, $resource)->contains($context->site());
        } catch (AuthorizationResourceOwnershipUnknown) {
            $decision = new AuthorizationDecision(false, 'core.site-ownership.v1', 'resource_site_unknown');
            $this->record($context, $action, $resource, $decision);
            $this->deny($context, $action, $resource, $decision);
        }

        $withinEffectiveAuthority = $principal?->allows($action, $requested) ?? false;
        $definition = $this->policies->capability($action);
        $extensionBootstrap = $definition !== null
            && $definition->owner !== 'core'
            && $principal?->allows(
                Capability::fromString('extensions.manage'),
                [GrantScope::global()],
            ) === true;
        $allowed = $context->hasProvenance($this->provenance)
            && $this->policies->supportsDelegation($action, $scope)
            && $siteMatches
            && $principal !== null
            && ($withinEffectiveAuthority || $extensionBootstrap);
        $decision = new AuthorizationDecision(
            $allowed,
            $extensionBootstrap && !$withinEffectiveAuthority
                ? 'core.extension-delegation-bootstrap.v1'
                : 'core.delegation-ceiling.v1',
            $allowed
                ? ($withinEffectiveAuthority
                    ? 'delegation_within_effective_authority'
                    : 'trusted_extension_capability_bootstrap')
                : 'delegation_exceeds_effective_authority',
        );
        $this->record($context, $action, $resource, $decision);

        if (!$allowed) {
            $this->deny($context, $action, $resource, $decision);
        }
    }

    /**
     * Run the checks a request must survive, without recording the outcome.
     *
     * The order is deliberate and every step short-circuits: an untrusted context is rejected before
     * anything else is consulted, then an action that is not legal on this resource type at all, then
     * ownership, and only last the caller's own authority. Where the registry marks a resource type
     * installation-global, or the resource instance is owned at installation scope, a human needs a grant
     * that is itself global — a site-wide grant does not reach it, and the two express the same
     * requirement rather than two overlapping ones. A system-only capability is refused to any human
     * principal, and each unattended identity is confined to the exact action/resource bindings that
     * name it.
     *
     * @param   ExecutionContext       $context   Caller identity, site and provenance the work runs under.
     * @param   Capability             $action    Capability being exercised.
     * @param   AuthorizationResource  $resource  Target the action is aimed at.
     *
     * @return  AuthorizationDecision  The verdict, naming the check that settled it as policy and reason.
     *
     * @since   2.0.0
     */
    private function evaluate(
        ExecutionContext $context,
        Capability $action,
        AuthorizationResource $resource,
    ): AuthorizationDecision {
        if (!$context->hasProvenance($this->provenance)) {
            return new AuthorizationDecision(false, 'core.provenance.v1', 'untrusted_execution_context');
        }

        $resourcePolicy = $this->policies->resourcePolicy($action, $resource);
        if ($resourcePolicy === null) {
            return new AuthorizationDecision(false, 'core.registry.v1', 'unsupported_action_resource');
        }

        try {
            $owner = $this->scopeFor($context, $resource);
        } catch (AuthorizationResourceOwnershipUnknown) {
            return new AuthorizationDecision(false, 'core.site-ownership.v1', 'resource_site_unknown');
        }
        $globalGrantRequired = $resourcePolicy->installationGlobal || $owner->isInstallation();
        if (!$globalGrantRequired && !$owner->contains($context->site())) {
            return new AuthorizationDecision(false, 'core.site-ownership.v1', 'resource_site_mismatch');
        }

        if ($context->principal() !== null && !$this->policies->allowsHumanGrant($action)) {
            return new AuthorizationDecision(false, 'core.system-identity.v1', 'system_identity_required');
        }
        $principal = AuthenticatedPrincipal::of($context);
        $actor = $context->systemActor();
        $allowed = $principal !== null
            ? $principal->allows(
                $action,
                $globalGrantRequired
                    ? [GrantScope::global()]
                    : $this->effectiveScopes($context, $resource),
            )
            : $actor instanceof SystemIdentity && $resourcePolicy->allowsSystemIdentity($actor);

        return new AuthorizationDecision(
            $allowed,
            'core.scoped-grants.v1',
            $allowed
                ? ($globalGrantRequired ? 'matching_global_grant' : 'matching_effective_grant')
                : ($globalGrantRequired ? 'global_grant_required' : 'no_matching_effective_grant'),
        );
    }

    /**
     * Build the exact grant scopes a human principal may satisfy for one decision.
     *
     * Site and concrete-resource scopes always remain available. Organization and workspace scopes are
     * added only after the context's full membership snapshot is revalidated against live state. A
     * freshness-store failure therefore removes only those contextual scopes instead of turning a global,
     * site, or exact-resource grant into an infrastructure failure. Direct organization and workspace
     * targets must also equal the selected context, preventing a valid membership from being reused to
     * name another tenant's target.
     *
     * The site scope offered is the caller's own. This is only reached once containment has passed, so
     * for a resource owned by a single site the caller's site *is* the owning site and the value is the
     * one the owner supplied before. For a group-owned resource it is the member site the caller actually
     * holds grants at, which is the only site whose authority the caller can honestly claim.
     *
     * @param   ExecutionContext       $context   Human decision context carrying the optional membership.
     * @param   AuthorizationResource  $resource  Exact resource the action is aimed at.
     *
     * @return  list<GrantScope>  Site and exact scopes, plus revalidated contextual scopes when available.
     *
     * @since   2.0.0
     */
    private function effectiveScopes(
        ExecutionContext $context,
        AuthorizationResource $resource,
    ): array {
        $scopes = [
            GrantScope::named('site', $context->site()->identifier()),
            GrantScope::named($resource->type(), $resource->identifier()),
        ];
        foreach ($this->currentMembershipScopes($context, $resource) as $membershipScope) {
            $scopes[] = $membershipScope;
        }

        return $scopes;
    }

    /**
     * Resolve contextual grant scopes only from an exact live membership snapshot.
     *
     * A validator failure is deliberately converted to an empty list. Membership is an optional
     * expansion of authority, so unavailable live state must remove organization/workspace reach while
     * leaving independently held global, site, and exact-resource grants available to the caller.
     *
     * @param   ExecutionContext       $context   Human decision or delegation context.
     * @param   AuthorizationResource  $resource  Target used to reject a contradictory tenant identifier.
     *
     * @return  list<GrantScope>  Live organization and optional workspace scopes, or an empty list.
     *
     * @since   2.0.0
     */
    private function currentMembershipScopes(
        ExecutionContext $context,
        AuthorizationResource $resource,
    ): array {
        $membership = $context->membership();
        if ($membership === null) {
            return [];
        }

        try {
            $current = $this->memberships->current(
                $context->actorId(),
                $context->site(),
                $membership,
                false,
            );
        } catch (\Throwable) {
            // This is the boundary between durable membership state and optional contextual authority;
            // any infrastructure failure must be indistinguishable from a stale membership.
            return [];
        }
        if (!$current || !$this->membershipTargetMatches($membership, $resource)) {
            return [];
        }

        $scopes = [GrantScope::named('organization', $membership->organization()->identifier())];
        if ($membership->workspace() !== null) {
            $scopes[] = GrantScope::named('workspace', $membership->workspace()->identifier());
        }

        return $scopes;
    }

    /**
     * Refuse contextual scope expansion when a direct tenant target contradicts the live selection.
     *
     * Other resource types are bound to organization/workspace state by their owning application
     * repository. The two tenant resources themselves are self-describing, so the gateway can and must
     * compare them directly rather than allowing the context to authorize a differently named target.
     * Collection identifiers remain valid because downstream queries still filter their returned rows.
     *
     * @param   MembershipContext      $membership  Revalidated tenant selection.
     * @param   AuthorizationResource  $resource    Requested resource whose direct tenant name is checked.
     *
     * @return  bool  False only for a mismatched concrete organization or workspace target.
     *
     * @since   2.0.0
     */
    private function membershipTargetMatches(
        MembershipContext $membership,
        AuthorizationResource $resource,
    ): bool {
        if ($resource->identifier() === '*') {
            return true;
        }
        if ($resource->type() === 'organization') {
            return $resource->identifier() === $membership->organization()->identifier();
        }
        if ($resource->type() === 'workspace') {
            return $resource->identifier() === $membership->workspace()?->identifier();
        }

        return true;
    }

    /**
     * Resolve the scope owning a resource, short-circuiting the targets that have no ownership record.
     *
     * A collection has no single owner, and a queue is a configured transport partition shared between
     * sites rather than a durable business resource, so both are treated as belonging to the calling site;
     * for queued work the ownership that matters travels with the durable job. Everything else is answered
     * by the ownership registry, which is authoritative and never guesses.
     *
     * @param   ExecutionContext       $context   Caller's context, used as the owner for the two shortcuts.
     * @param   AuthorizationResource  $resource  Target whose owning scope is being established.
     *
     * @return  OwnershipScope  The scope the resource belongs to.
     *
     * @throws  AuthorizationResourceOwnershipUnknown  When the registry records no owner for the resource.
     *
     * @since   2.0.0
     */
    private function scopeFor(ExecutionContext $context, AuthorizationResource $resource): OwnershipScope
    {
        // Collections are created/listed within the caller's site. Queues are configured transport
        // partitions shared by sites, while contributed report definitions are immutable runtime
        // declarations resolved inside the caller's active site; their durable jobs and artifacts carry
        // their own recorded ownership.
        if (
            $resource->identifier() === '*'
            || in_array($resource->type(), ['business_report', 'queue'], true)
        ) {
            return OwnershipScope::site($context->site());
        }

        return $this->ownership->scopeFor($resource);
    }

    /**
     * Write a decision to the audit sink, failing the operation when an allow cannot be recorded.
     *
     * For a permitted action the record is part of the guarantee, not a side effect, so a recorder failure
     * there is escalated rather than swallowed and the caller never proceeds unlogged. A denial that
     * cannot be recorded is deliberately left alone: the caller is being refused in any case, and raising
     * an infrastructure error would replace a precise denial with a misleading one.
     *
     * @param   ExecutionContext       $context   Caller identity, site and provenance the work runs under.
     * @param   Capability             $action    Capability that was evaluated.
     * @param   AuthorizationResource  $resource  Target the action was aimed at.
     * @param   AuthorizationDecision  $decision  Verdict to record.
     *
     * @return  void
     *
     * @throws  AuthorizationAuditUnavailable  When the recorder fails while writing an allowed decision.
     *
     * @since   2.0.0
     */
    private function record(
        ExecutionContext $context,
        Capability $action,
        AuthorizationResource $resource,
        AuthorizationDecision $decision,
    ): void {
        try {
            $this->decisions->record($context, $action, $resource, $decision);
        } catch (\Throwable $failure) {
            if ($decision->allowed) {
                throw new AuthorizationAuditUnavailable($failure);
            }
        }
    }

    /**
     * Turn a refused decision into the exception callers see, carrying the reasoning with it.
     *
     * The actor, action, resource, site, policy and reason all travel on the exception so that a handler
     * can report or classify the refusal without re-running the decision.
     *
     * @param   ExecutionContext       $context   Caller identity and site quoted in the refusal.
     * @param   Capability             $action    Capability that was refused.
     * @param   AuthorizationResource  $resource  Target the action was aimed at.
     * @param   AuthorizationDecision  $decision  Verdict supplying the policy and reason.
     *
     * @return  never
     *
     * @throws  AuthorizationDenied  Always; raising it is the whole purpose of the method.
     *
     * @since   2.0.0
     */
    private function deny(
        ExecutionContext $context,
        Capability $action,
        AuthorizationResource $resource,
        AuthorizationDecision $decision,
    ): never {
        throw new AuthorizationDenied(
            $context->actorId(),
            $action->value(),
            $resource->type(),
            $resource->identifier(),
            $context->site()->identifier(),
            $decision->policy,
            $decision->reason,
        );
    }
}
