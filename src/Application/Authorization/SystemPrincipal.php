<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;

/**
 * The capability that lets unattended code mint a trusted execution context for one system identity.
 *
 * Background work has no request and no signed-in operator to derive authority from, so it is handed a
 * principal instead. Each instance closes over the composition root's kernel proof and a single
 * `SystemIdentity`, and stamps both onto every context `context()` hands out. That is what makes the
 * context usable: `DenyByDefaultAuthorizationGateway` refuses any context whose provenance is not the
 * proof it was itself built with, and the composition root never publishes that object into the
 * container, so an extension or delivery adapter can call `ExecutionContext::issueSystem()` all it
 * likes and still be denied. Holding a principal is therefore the whole of a background collaborator's
 * authority, and the identity is fixed when it is issued — needing different authority means being
 * handed a different principal, not widening this one.
 *
 * @since  2.0.0
 */
final readonly class SystemPrincipal
{
    /**
     * Bind a system identity to the authority that vouches for it.
     *
     * @param  object          $provenance  Composition-root proof the gateway compares contexts against.
     * @param  SystemIdentity  $identity    Unattended actor every context issued here will name.
     *
     * @since  2.0.0
     */
    private function __construct(private object $provenance, private SystemIdentity $identity)
    {
    }

    /**
     * Issue a principal for one system identity, backed by the caller's proof object.
     *
     * Nothing is validated here, because the guarantee is one of reachability rather than of checking:
     * a principal is only useful when its proof is the object the gateway was constructed with, so this
     * is called from the composition root while that object is still private to it.
     *
     * @param   object          $provenance  Kernel proof to stamp on every context this principal issues.
     * @param   SystemIdentity  $identity    Unattended actor the principal acts as, fixed for its life.
     *
     * @return  self  Principal that can issue system contexts for that identity.
     *
     * @since   2.0.0
     */
    public static function issue(object $provenance, SystemIdentity $identity): self
    {
        return new self($provenance, $identity);
    }

    /**
     * Report which unattended actor this principal acts as.
     *
     * `GlobalJobPrincipals` reads it to index the principals it was given and to reject one whose
     * identity is not allowed to run installation-global work.
     *
     * @return  SystemIdentity  Identity fixed when the principal was issued.
     *
     * @since   2.0.0
     */
    public function identity(): SystemIdentity
    {
        return $this->identity;
    }

    /**
     * Mint an execution context for one unit of unattended work.
     *
     * Call it per operation rather than caching the result, since the request and correlation
     * identifiers are what tie the resulting authorization and audit records back to the run that
     * caused them. The context always declares `AuthenticationStrength::System` and carries no human
     * principal.
     *
     * @param   SiteContext  $site           Site the work acts within; installation-wide work uses the default.
     * @param   string       $requestId      Identifier for this unit of work, non-empty and at most 191 bytes.
     * @param   ?string      $correlationId  Identifier tying this work to what triggered it; defaults to
     *          `$requestId` when the work starts a chain of its own.
     *
     * @return  ExecutionContext  Authorized system context for the principal's identity.
     *
     * @throws  \InvalidArgumentException  When either identifier is empty, longer than 191 bytes, or
     *          holds a control character.
     *
     * @since   2.0.0
     */
    public function context(
        SiteContext $site,
        string $requestId,
        ?string $correlationId = null,
    ): ExecutionContext {
        return ExecutionContext::issueSystem(
            $this->provenance,
            $this->identity,
            $site,
            $requestId,
            $correlationId,
        );
    }
}
