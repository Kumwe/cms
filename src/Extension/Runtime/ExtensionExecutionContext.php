<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Runtime;

use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Application\ExecutionContext as ExtensionContext;

/**
 * Presents the host's execution context to extension code through the SDK's read-only contract.
 *
 * `Kumwe\Context\Value\ExecutionContext` is the package value every App use case reads, and it deliberately
 * implements no host or SDK interface. Extension handlers, custom business views and actions, contributed jobs
 * and integration-event consumers are typed against `Kumwe\Extension\Spi\Application\ExecutionContext`, so the
 * host wraps its context in this adapter at every boundary where control passes to package code, and unwraps
 * it again where package code hands the same envelope back. The adapter discloses exactly the seven scope
 * coordinates the SPI names and nothing else: no provenance, principal, session, proof or fingerprint reaches
 * an extension, and a foreign implementation of the SPI can never be mistaken for a host-issued context,
 * because only this class carries one.
 *
 * @since  2.0.0
 */
final readonly class ExtensionExecutionContext implements ExtensionContext
{
    /**
     * Wrap the host context; callers use `of()` so the intent reads at the boundary.
     *
     * @param  ExecutionContext  $host  Context the host issued for the current unit of work.
     *
     * @since  2.0.0
     */
    private function __construct(private ExecutionContext $host)
    {
    }

    /**
     * Present a host-issued context to extension code.
     *
     * @param   ExecutionContext  $host  Context the host issued for the current unit of work.
     *
     * @return  self  Adapter disclosing the SPI coordinates of that context.
     *
     * @since   2.0.0
     */
    public static function of(ExecutionContext $host): self
    {
        return new self($host);
    }

    /**
     * Recover the exact host context an SDK envelope carries, when the host issued it.
     *
     * @param   ExtensionContext  $context  Context received through an SDK value or handler signature.
     *
     * @return  ?ExecutionContext  The wrapped host context, or null for any other implementation of the SPI.
     *
     * @since   2.0.0
     */
    public static function host(ExtensionContext $context): ?ExecutionContext
    {
        return $context instanceof self ? $context->host : null;
    }

    /**
     * Return the site this execution is scoped to.
     *
     * @return  string  Canonical site identifier.
     *
     * @since   2.0.0
     */
    public function siteIdentifier(): string
    {
        return $this->host->siteIdentifier();
    }

    /**
     * Name the actor that denials and audit records are attributed to.
     *
     * @return  string  The principal's subject for a human context, otherwise the system actor's identifier.
     *
     * @since   2.0.0
     */
    public function actorId(): string
    {
        return $this->host->actorId();
    }

    /**
     * Return the organization scope of this execution, when one is active.
     *
     * @return  ?string  Canonical organization identifier, or null outside an organization scope.
     *
     * @since   2.0.0
     */
    public function organizationIdentifier(): ?string
    {
        return $this->host->organizationIdentifier();
    }

    /**
     * Return the workspace scope of this execution, when one is active.
     *
     * @return  ?string  Canonical workspace identifier, or null outside a workspace scope.
     *
     * @since   2.0.0
     */
    public function workspaceIdentifier(): ?string
    {
        return $this->host->workspaceIdentifier();
    }

    /**
     * Report the identifier of this single unit of work.
     *
     * @return  string  Distinct per operation.
     *
     * @since   2.0.0
     */
    public function requestId(): string
    {
        return $this->host->requestId();
    }

    /**
     * Report the identifier shared by every unit of work in the same trace.
     *
     * @return  string  Carried unchanged into nested operations.
     *
     * @since   2.0.0
     */
    public function correlationId(): string
    {
        return $this->host->correlationId();
    }

    /**
     * Return the delivery surface this execution entered through.
     *
     * @return  string  Backing value of the authenticated surface.
     *
     * @since   2.0.0
     */
    public function deliverySurface(): string
    {
        return $this->host->deliverySurface();
    }
}
