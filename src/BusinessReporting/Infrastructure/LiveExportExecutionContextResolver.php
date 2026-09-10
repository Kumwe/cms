<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Infrastructure;

use Kumwe\App\BusinessReporting\Application\ExportExecutionContextResolver;
use Kumwe\App\BusinessReporting\Application\ExportGenerationRejected;
use Kumwe\App\BusinessReporting\Domain\ExportArtifact;
use Kumwe\App\BusinessSecurity\Application\MembershipDirectory;
use Kumwe\App\Portal\Application\PortalPrincipalLoader;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;

/**
 * Rehydrates current identity state under the original human export request's exact grant ceiling.
 *
 * @since  2.0.0
 */
final readonly class LiveExportExecutionContextResolver implements ExportExecutionContextResolver
{
    /**
     * Wire live identity and membership stores.
     *
     * @param  PortalPrincipalLoader  $principals   Active-user and current-grant loader.
     * @param  MembershipDirectory    $memberships  Live organization/workspace resolver.
     *
     * @since  2.0.0
     */
    public function __construct(
        private PortalPrincipalLoader $principals,
        private MembershipDirectory $memberships,
    ) {
    }

    /**
     * Rebuild a credential-independent context and prove its authority digest matches the request.
     *
     * New artifacts carry the exact effective grants of their requesting credential. Those grants must
     * all remain live and form the returned principal's ceiling, so later additions cannot widen queued
     * work. Legacy artifacts retain their original full-live-principal fingerprint comparison.
     *
     * @param   ExportArtifact    $artifact       Stored original actor and scope.
     * @param   ExecutionContext  $workerContext  Queue worker trace context.
     *
     * @return  ExecutionContext  Fresh original-human context at the original surface.
     *
     * @throws  ExportGenerationRejected  When actor, membership, grants or epoch are no longer current.
     *
     * @since   2.0.0
     */
    public function resolve(ExportArtifact $artifact, ExecutionContext $workerContext): ExecutionContext
    {
        $site = SiteContext::fromString($artifact->siteIdentifier);
        if ($workerContext->site()->identifier() !== $site->identifier()) {
            throw new ExportGenerationRejected('The export worker site does not match the artifact.');
        }
        $membership = null;
        if ($artifact->organizationIdentifier !== null) {
            $membership = $this->memberships->resolve(
                $artifact->actorId,
                $site,
                $artifact->organizationIdentifier,
                $artifact->workspaceIdentifier,
            );
            if ($membership === null) {
                throw new ExportGenerationRejected('The export membership is no longer active.');
            }
        } elseif ($artifact->workspaceIdentifier !== null) {
            throw new ExportGenerationRejected('An export workspace cannot exist without an organization.');
        }
        $identity = $this->principals->load($artifact->actorId, 'report-export-rehydrated', $membership);
        if ($identity === null) {
            throw new ExportGenerationRejected('The export actor is no longer active.');
        }
        $principal = $identity->principal;
        if ($artifact->authorityGrantRows !== null) {
            $principal = $principal->restrictedToGrantRows($artifact->authorityGrantRows)
                ?? throw new ExportGenerationRejected('The export actor authority changed.');
        }
        $context = $principal->context(
            $site,
            AuthenticationStrength::BearerToken,
            'report-export-' . $artifact->id,
            $workerContext->correlationId(),
            $artifact->surface,
            $membership,
        );
        if (!hash_equals($artifact->authorityFingerprint, $context->approvalFingerprint())) {
            throw new ExportGenerationRejected('The export actor authority changed.');
        }

        return $context;
    }
}
