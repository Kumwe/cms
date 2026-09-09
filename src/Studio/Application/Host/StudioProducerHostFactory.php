<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Application\Persistence\TransactionState;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Studio\Application\Media\StudioMediaHostPort;
use Kumwe\App\Studio\Application\Media\StudioMediaOperations;
use Kumwe\App\Studio\Application\Preview\StudioPreviewHostPort;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewTransport;
use Psr\Clock\ClockInterface;

/**
 * Builds the complete direct Producer host with fresh authority for each authenticated App request.
 *
 * @since  2.0.0
 */
final readonly class StudioProducerHostFactory
{
    /**
     * Compose stable App services and request-scopable direct Producer port implementations.
     *
     * @param  StudioHostSessionAuthority      $sessions          Canonical Studio session authority.
     * @param  TransactionManager              $transactions      Authoritative App transaction manager.
     * @param  TransactionState                $transactionState  Live connection transaction state.
     * @param  StudioMutationReplayRepository  $replays           Durable keyed replay store.
     * @param  StudioMutationOutcomeCodec      $outcomes          Authenticated outcome protection.
     * @param  AuditRecorder                   $audit             Transactional audit sink.
     * @param  ClockInterface                  $clock             Trusted audit and expiry clock.
     * @param  StudioMediaOperations           $media             Complete media use case.
     * @param  StudioArtifactHostPort          $artifact          App artifact port prototype.
     * @param  StudioLocalizationHostPort      $localization      App localization port prototype.
     * @param  StudioMediaHostPort             $mediaPort         App media port prototype.
     * @param  StudioModelHostPort             $model             App model port prototype.
     * @param  StudioPreviewHostPort           $preview           App preview port prototype.
     * @param  StudioRecoveryHostPort          $recovery          App recovery port prototype.
     * @param  StudioResourceHostPort          $resource          App resource port prototype.
     * @param  StudioTelemetryHostPort         $telemetry         App telemetry port prototype.
     * @param  ?StudioAuthoringHostPort        $authoring         App contextual authoring port prototype.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioHostSessionAuthority $sessions,
        private TransactionManager $transactions,
        private TransactionState $transactionState,
        private StudioMutationReplayRepository $replays,
        private StudioMutationOutcomeCodec $outcomes,
        private AuditRecorder $audit,
        private ClockInterface $clock,
        private StudioMediaOperations $media,
        private StudioArtifactHostPort $artifact,
        private StudioLocalizationHostPort $localization,
        private StudioMediaHostPort $mediaPort,
        private StudioModelHostPort $model,
        private StudioPreviewHostPort $preview,
        private StudioRecoveryHostPort $recovery,
        private StudioResourceHostPort $resource,
        private StudioTelemetryHostPort $telemetry,
        private ?StudioAuthoringHostPort $authoring = null,
    ) {
    }

    /**
     * Build one complete host from trusted delivery evidence, with no state shared across dispatches.
     *
     * @param   ExecutionContext             $context           Fresh authenticated App execution context.
     * @param   StudioPreviewTransport|null  $previewTransport  Browser-only preview evidence, when present.
     *
     * @return  StudioProducerHost  Complete request-scoped host for Producer's dispatcher.
     *
     * @since   2.0.0
     */
    public function create(
        ExecutionContext $context,
        ?StudioPreviewTransport $previewTransport = null,
    ): StudioProducerHost {
        $authority = new StudioProducerRequestAuthority(
            $context,
            $this->sessions,
            $previewTransport,
        );
        $mutations = new StudioProducerMutationBoundary(
            $this->transactions,
            $this->transactionState,
            $this->replays,
            $this->outcomes,
            $this->audit,
            $this->clock,
            $this->media,
            $authority,
        );

        return new StudioProducerHost(
            $authority,
            $mutations,
            $this->artifact->forRequest($authority),
            $this->localization->forRequest($authority),
            $this->mediaPort->forRequest($authority),
            $this->model->forRequest($authority),
            new StudioPermissionHostPort($authority),
            $this->preview->forRequest($authority),
            $this->recovery->forRequest($authority),
            $this->resource->forRequest($authority),
            $this->telemetry->forRequest($authority),
            $this->authoring?->forRequest($authority),
        );
    }
}
