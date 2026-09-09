<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use Kumwe\Producer\Wire\Port\ArtifactPortInterface;
use Kumwe\Producer\Wire\Port\AuthoringPortInterface;
use Kumwe\Producer\Wire\Port\AuthorizationInterface;
use Kumwe\Producer\Wire\Port\HostAdapterInterface;
use Kumwe\Producer\Wire\Port\LocalizationPortInterface;
use Kumwe\Producer\Wire\Port\MediaPortInterface;
use Kumwe\Producer\Wire\Port\ModelPortInterface;
use Kumwe\Producer\Wire\Port\MutationBoundaryInterface;
use Kumwe\Producer\Wire\Port\PermissionPortInterface;
use Kumwe\Producer\Wire\Port\PreviewPortInterface;
use Kumwe\Producer\Wire\Port\RecoveryPortInterface;
use Kumwe\Producer\Wire\Port\ResourcePortInterface;
use Kumwe\Producer\Wire\Port\TelemetryPortInterface;

/**
 * Complete request-scoped App host supplied directly to Producer's canonical dispatcher.
 *
 * @since  2.0.0
 */
final readonly class StudioProducerHost implements HostAdapterInterface
{
    /**
     * Bind Producer's authorities and all nine pinned operation ports for one App request.
     *
     * @param  AuthorizationInterface     $authorization  Trusted per-call App authority.
     * @param  MutationBoundaryInterface  $mutations      Host-atomic App mutation boundary.
     * @param  ArtifactPortInterface      $artifact       Required artifact port.
     * @param  LocalizationPortInterface  $localization   App localization port.
     * @param  MediaPortInterface         $media          App media port.
     * @param  ModelPortInterface         $model          App model port.
     * @param  PermissionPortInterface    $permission     App permission port.
     * @param  PreviewPortInterface       $preview        App preview port.
     * @param  RecoveryPortInterface      $recovery       App recovery port.
     * @param  ResourcePortInterface      $resource       App resource port.
     * @param  TelemetryPortInterface     $telemetry      App telemetry port.
     * @param  ?AuthoringPortInterface    $authoring      Contextual authoring port, served only to a
     *         Content authoring session; null keeps the port unavailable.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AuthorizationInterface $authorization,
        private MutationBoundaryInterface $mutations,
        private ArtifactPortInterface $artifact,
        private LocalizationPortInterface $localization,
        private MediaPortInterface $media,
        private ModelPortInterface $model,
        private PermissionPortInterface $permission,
        private PreviewPortInterface $preview,
        private RecoveryPortInterface $recovery,
        private ResourcePortInterface $resource,
        private TelemetryPortInterface $telemetry,
        private ?AuthoringPortInterface $authoring = null,
    ) {
    }

    /**
     * Return the trusted App authorization implementation for this request.
     *
     * @return  AuthorizationInterface  Exact per-call authority.
     *
     * @since   2.0.0
     */
    public function authorization(): AuthorizationInterface
    {
        return $this->authorization;
    }

    /**
     * Return the App's transaction, audit and protected replay boundary.
     *
     * @return  MutationBoundaryInterface  Exact per-request mutation boundary.
     *
     * @since   2.0.0
     */
    public function mutations(): MutationBoundaryInterface
    {
        return $this->mutations;
    }

    /**
     * Return the contextual authoring port when this request serves one.
     *
     * The factory binds the port only for a session whose resource is an opaque Content authoring
     * context; every other session keeps `studio.port/authoring` unavailable, exactly as its
     * advertised capabilities say.
     *
     * @return  AuthoringPortInterface|null  Request-scoped authoring port, or null when not served.
     *
     * @since   2.0.0
     */
    public function authoring(): ?AuthoringPortInterface
    {
        return $this->authoring;
    }

    /**
     * Return the required artifact port.
     *
     * @return  ArtifactPortInterface  App artifact implementation.
     *
     * @since   2.0.0
     */
    public function artifact(): ArtifactPortInterface
    {
        return $this->artifact;
    }

    /**
     * Return the localization port.
     *
     * @return  LocalizationPortInterface  App localization implementation.
     *
     * @since   2.0.0
     */
    public function localization(): LocalizationPortInterface
    {
        return $this->localization;
    }

    /**
     * Return the media port.
     *
     * @return  MediaPortInterface  App media implementation.
     *
     * @since   2.0.0
     */
    public function media(): MediaPortInterface
    {
        return $this->media;
    }

    /**
     * Return the model port.
     *
     * @return  ModelPortInterface  App model implementation.
     *
     * @since   2.0.0
     */
    public function model(): ModelPortInterface
    {
        return $this->model;
    }

    /**
     * Return the permission port.
     *
     * @return  PermissionPortInterface  App permission implementation.
     *
     * @since   2.0.0
     */
    public function permission(): PermissionPortInterface
    {
        return $this->permission;
    }

    /**
     * Return the preview port.
     *
     * @return  PreviewPortInterface  App preview implementation.
     *
     * @since   2.0.0
     */
    public function preview(): PreviewPortInterface
    {
        return $this->preview;
    }

    /**
     * Return the recovery port.
     *
     * @return  RecoveryPortInterface  App recovery implementation.
     *
     * @since   2.0.0
     */
    public function recovery(): RecoveryPortInterface
    {
        return $this->recovery;
    }

    /**
     * Return the resource port.
     *
     * @return  ResourcePortInterface  App resource implementation.
     *
     * @since   2.0.0
     */
    public function resource(): ResourcePortInterface
    {
        return $this->resource;
    }

    /**
     * Return the telemetry port.
     *
     * @return  TelemetryPortInterface  App telemetry implementation.
     *
     * @since   2.0.0
     */
    public function telemetry(): TelemetryPortInterface
    {
        return $this->telemetry;
    }
}
