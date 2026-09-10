<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Identity\Domain\GrantScope;
use Kumwe\Context\Value\ExecutionContext;

/**
 * The one port application code asks before it changes or reveals anything.
 *
 * Every service, handler, console command, and MCP tool routes its permission question through this
 * interface rather than reading grants itself, which is what keeps the delivery adapters at parity: a
 * capability that is refused over REST cannot be reached through the administrator or the CLI. An
 * implementation owes three guarantees — it denies by default, it records every decision it reaches
 * before acting on it, and it answers the delegation question separately from the access question so
 * that an actor can never grant authority it does not itself hold.
 *
 * @since  2.0.0
 */
interface AuthorizationGateway
{
    /**
     * Evaluate whether an actor may perform an action on a resource, without raising on refusal.
     *
     * Use this where a denial is an expected branch — hiding a menu entry, filtering a listing — and
     * `assertAllowed()` where it must stop the operation. The decision is audited either way.
     *
     * @param   ExecutionContext       $context   Actor, site, and provenance the action runs under.
     * @param   Capability             $action    Capability being exercised.
     * @param   AuthorizationResource  $resource  Resource the action is aimed at.
     *
     * @return  AuthorizationDecision  The outcome, plus the policy and reason behind it.
     *
     * @since   2.0.0
     */
    public function decide(
        ExecutionContext $context,
        Capability $action,
        AuthorizationResource $resource,
    ): AuthorizationDecision;

    /**
     * Require that an actor may perform an action on a resource, and stop the operation otherwise.
     *
     * Call it before the first side effect of an operation. The decision is recorded before the refusal
     * is raised, so a denial still leaves a trail even though nothing else happened.
     *
     * @param   ExecutionContext       $context   Actor, site, and provenance the action runs under.
     * @param   Capability             $action    Capability being exercised.
     * @param   AuthorizationResource  $resource  Resource the action is aimed at.
     *
     * @return  void
     *
     * @throws  AuthorizationDenied  When policy refuses the actor this action on this resource.
     *
     * @since   2.0.0
     */
    public function assertAllowed(
        ExecutionContext $context,
        Capability $action,
        AuthorizationResource $resource,
    ): void;

    /**
     * Require that an actor may hand an action onward to others within a scope.
     *
     * Granting a capability to a role, or minting a token that carries one, is authority transfer
     * rather than use, so it is checked against the actor's own ceiling: nobody may delegate wider than
     * they hold, and some actions are not delegatable at any scope. The sole bootstrap exception is an
     * explicitly trusted extension capability: a global `extensions.manage` holder may make its first
     * human grant after activation, because no principal can hold an owner-new capability before that
     * grant exists. Core and system-only capabilities never enter that exception. Callers issuing a
     * grant assert this before and inside the write transaction.
     *
     * @param   ExecutionContext  $context  Actor, site, and provenance the delegation runs under.
     * @param   Capability        $action   Capability the actor proposes to grant onward.
     * @param   GrantScope        $scope    Scope the grant would be written at, global or named.
     *
     * @return  void
     *
     * @throws  AuthorizationDenied  When the delegation would exceed the actor's effective authority.
     *
     * @since   2.0.0
     */
    public function assertCanDelegate(
        ExecutionContext $context,
        Capability $action,
        GrantScope $scope,
    ): void;
}
