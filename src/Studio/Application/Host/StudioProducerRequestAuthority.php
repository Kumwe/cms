<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use LogicException;
use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewTransport;
use Kumwe\Producer\Error\HostError;
use Kumwe\Producer\Wire\Operation;
use Kumwe\Producer\Wire\Port\AuthorizationInterface;
use Kumwe\Producer\Wire\RequestEnvelope;

/**
 * Direct Producer authorization implementation backed by the App's trusted Studio session authority.
 *
 * @since  2.0.0
 */
final class StudioProducerRequestAuthority implements AuthorizationInterface
{
    /**
     * The live authority snapshot established for the current request.
     *
     * @var    ?StudioHostSessionSnapshot
     * @since  2.0.0
     */
    private ?StudioHostSessionSnapshot $snapshot = null;

    /**
     * Bind one Producer dispatch to trusted App request evidence.
     *
     * @param  ExecutionContext             $context           Fresh authenticated App execution context.
     * @param  StudioHostSessionAuthority   $sessions          Canonical Studio session and policy authority.
     * @param  StudioPreviewTransport|null  $previewTransport  HTTP-only evidence for preview operations.
     *
     * @since  2.0.0
     */
    public function __construct(
        private readonly ExecutionContext $context,
        private readonly StudioHostSessionAuthority $sessions,
        private readonly ?StudioPreviewTransport $previewTransport = null,
    ) {
    }

    /**
     * Re-resolve live authority and permit only the exact operation addressed by this request.
     *
     * Authorization precedes keyed replay, so revoked authority can never recover an earlier result. The
     * accepted snapshot is retained only for the port and mutation boundary serving this same dispatch.
     *
     * @param   Operation        $operation  Closed Producer registry row.
     * @param   RequestEnvelope  $request    Fully validated canonical request.
     *
     * @return  HostError|null  Null when this exact call is allowed; canonical refusal otherwise.
     *
     * @since   2.0.0
     */
    public function authorize(Operation $operation, RequestEnvelope $request): ?HostError
    {
        $this->snapshot = null;
        $requestContext = $request->context();
        try {
            $snapshot = $this->sessions->resolve($this->context, $requestContext->resourceContextKey);
        } catch (StudioHostAccessRefused $refused) {
            return StudioProducerError::error($refused->category, $refused->diagnosticCode);
        }
        if (!hash_equals($operation->capability, $requestContext->operationId)) {
            return StudioProducerError::error(
                'invalid-request',
                'studio.host/operation-mismatch',
            );
        }
        if (
            !hash_equals($snapshot->session->sessionGeneration, $requestContext->sessionGeneration)
            || !hash_equals($snapshot->generation, $requestContext->sessionGeneration)
        ) {
            return StudioProducerError::error(
                'invalid-request',
                'studio.host/stale-session-generation',
            );
        }
        if (!$snapshot->modeAllowed || !$this->permitsOperation($operation, $snapshot)) {
            return StudioProducerError::error('forbidden', 'studio.host/session-refused');
        }

        $this->snapshot = $snapshot;

        return null;
    }

    /**
     * Return the trusted App execution context bound to this dispatch.
     *
     * @return  ExecutionContext  Fresh authenticated request context.
     *
     * @since   2.0.0
     */
    public function context(): ExecutionContext
    {
        return $this->context;
    }

    /**
     * Return the live snapshot proved by the successful authorization stage.
     *
     * @return  StudioHostSessionSnapshot  Snapshot for this same Producer dispatch.
     *
     * @throws  LogicException  When a port attempts work before authorization succeeds.
     *
     * @since   2.0.0
     */
    public function snapshot(): StudioHostSessionSnapshot
    {
        return $this->snapshot ?? throw new LogicException('Producer must authorize before invoking an App host port.');
    }

    /**
     * Return the HTTP-only preview evidence attached by the authenticated delivery handler.
     *
     * @return  StudioPreviewTransport|null  Preview transport evidence, or null outside preview delivery.
     *
     * @since   2.0.0
     */
    public function previewTransport(): ?StudioPreviewTransport
    {
        return $this->previewTransport;
    }

    /**
     * Explain one canonical Studio permission against this dispatch's live authorized snapshot.
     *
     * @param   string  $permission  Canonical Studio permission candidate.
     *
     * @return  bool  True only when the current App authority positively grants it.
     *
     * @since   2.0.0
     */
    public function permits(string $permission): bool
    {
        return $this->sessions->permits($this->snapshot(), $permission);
    }

    /**
     * Apply the App's exact operation-level permission policy to a live Studio snapshot.
     *
     * @param   Operation                  $operation  Closed Producer registry row.
     * @param   StudioHostSessionSnapshot  $snapshot   Fresh trusted App authority snapshot.
     *
     * @return  bool  True only when the exact operation is permitted now.
     *
     * @since   2.0.0
     */
    private function permitsOperation(Operation $operation, StudioHostSessionSnapshot $snapshot): bool
    {
        if ($operation->port === 'authoring') {
            return $snapshot->session->resourceKind === StudioResourceKind::ContentAuthoring
                && $this->sessions->permits(
                    $snapshot,
                    $operation->mutating ? 'studio.permission/save' : 'studio.permission/read',
                );
        }

        return match ($operation->capability) {
            'studio.operation/artifact.publish' => $snapshot->canPublish,
            'studio.operation/artifact.unpublish' => $snapshot->canUnpublish,
            'studio.operation/artifact.save',
            'studio.operation/recovery.discard',
            'studio.operation/recovery.store' => $this->sessions->permits($snapshot, 'studio.permission/save'),
            'studio.operation/media.abort-upload',
            'studio.operation/media.authorize-upload',
            'studio.operation/media.complete-upload',
            'studio.operation/media.import-external' => $this->sessions->permits(
                $snapshot,
                'studio.permission/upload-media',
            ),
            default => $this->sessions->permits($snapshot, 'studio.permission/read'),
        };
    }
}
