<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Composition;

use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Studio\Application\Host\StudioArtifactAdmission;
use Kumwe\App\Studio\Application\Host\StudioArtifactRepository;
use Kumwe\App\Studio\Application\Host\StudioPersistenceRace;
use Kumwe\App\Studio\Application\Projection\ContentProjectionBindingRepository;
use Kumwe\App\Studio\Application\Projection\ContentStudioProjector;
use Kumwe\App\Studio\Application\Projection\StudioContentProjectionService;
use Kumwe\App\Studio\Domain\Projection\ContentBlueprintBinding;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use stdClass;

/**
 * Idempotently provisions the host-owned Blueprint for one authorized Content type version.
 *
 * @since  2.0.0
 */
final readonly class StudioContentCompositionService
{
    /**
     * Bind the exact projection, write stores, admission, lifecycle, audit, and contribution seams.
     *
     * @param  StudioContentProjectionService        $projection     Authorized AP-2 projection service.
     * @param  ContentProjectionBindingRepository    $bindings       Read-only host binding projection.
     * @param  ContentBlueprintBindingStore          $bindingStore   Write-only initial binding store.
     * @param  StudioArtifactAdmission               $admission      AP-4 canonical artifact admission.
     * @param  StudioArtifactRepository              $artifacts      AP-4 immutable artifact repository.
     * @param  TransactionManager                    $transactions   Atomic persistence coordinator.
     * @param  AuditRecorder                         $audit          Safe audit recorder.
     * @param  ClockInterface                        $clock          Audit event clock.
     * @param  StudioCompositionContributionCatalog  $contributions  Active trusted document catalogue.
     * @param  StudioPublishedTheme                  $theme          Exact published public-theme projection.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioContentProjectionService $projection,
        private ContentProjectionBindingRepository $bindings,
        private ContentBlueprintBindingStore $bindingStore,
        private StudioArtifactAdmission $admission,
        private StudioArtifactRepository $artifacts,
        private TransactionManager $transactions,
        private AuditRecorder $audit,
        private ClockInterface $clock,
        private StudioCompositionContributionCatalog $contributions,
        private StudioPublishedTheme $theme,
    ) {
    }

    /**
     * Find an already provisioned composition; this method performs no writes.
     *
     * @param   ExecutionContext  $context             Authorized actor and site context.
     * @param   string            $contentTypeId       Exact Content type UUID.
     * @param   int               $contentTypeVersion  Exact published Content type version.
     *
     * @return  ?StudioContentComposition  Current exact composition, or null when not provisioned.
     *
     * @throws  StudioCompositionModelMismatch  When the Blueprint model lock differs from the authorized model.
     * @throws  StudioCompositionThemeMismatch  When the Blueprint theme lock differs from the published theme.
     *
     * @since   2.0.0
     */
    public function find(
        ExecutionContext $context,
        string $contentTypeId,
        int $contentTypeVersion,
    ): ?StudioContentComposition {
        $model = $this->authorizedModel($context, $contentTypeId, $contentTypeVersion);
        $binding = $this->bindings->blueprint($context->site(), $contentTypeId, $contentTypeVersion);
        if ($binding === null) {
            return null;
        }
        $artifact = $binding->blueprintRevision === null
            ? $this->artifacts->current(
                $context->site()->identifier(),
                $binding->blueprintId,
                $binding->blueprintVersion,
            )
            : $this->artifacts->revision(
                $context->site()->identifier(),
                $binding->blueprintId,
                $binding->blueprintVersion,
                $binding->blueprintRevision,
            );
        if ($artifact === null || $artifact->kind !== 'blueprint') {
            throw new RuntimeException('The selected Studio Blueprint is unavailable.');
        }
        $document = $artifact->document();
        $lockedModel = $document->model ?? null;
        if (!self::matchesModel($model, $lockedModel)) {
            throw new StudioCompositionModelMismatch();
        }
        $dependencyLock = $document->dependencyLock ?? null;
        $lockedTheme = $dependencyLock instanceof stdClass ? $dependencyLock->theme ?? null : null;
        if (!$this->theme->reference($context->site())->matches($lockedTheme)) {
            throw new StudioCompositionThemeMismatch();
        }

        return new StudioContentComposition($model, $binding, $artifact);
    }

    /**
     * Provision an empty schema-valid draft and binding atomically, returning a concurrent winner.
     *
     * @param   ExecutionContext  $context             Authorized actor and site context.
     * @param   string            $contentTypeId       Exact Content type UUID.
     * @param   int               $contentTypeVersion  Exact published Content type version.
     * @param   list<string>      $renderers           Deployment-supported renderer capabilities.
     *
     * @return  StudioContentComposition  Newly admitted composition or the concurrent winner.
     *
     * @since   2.0.0
     */
    public function provision(
        ExecutionContext $context,
        string $contentTypeId,
        int $contentTypeVersion,
        array $renderers,
    ): StudioContentComposition {
        $existing = $this->find($context, $contentTypeId, $contentTypeVersion);
        if ($existing !== null) {
            return $existing;
        }
        $model = $this->authorizedModel($context, $contentTypeId, $contentTypeVersion);
        $binding = new ContentBlueprintBinding(
            $context->site(),
            strtolower($contentTypeId),
            $contentTypeVersion,
            self::blueprintId($contentTypeId, $contentTypeVersion),
            '1.0.0',
            null,
            1,
        );
        $blockLocks = $this->contributions->project([], $renderers)->blockLocks;
        $theme = $this->theme->reference($context->site());
        $artifact = $this->admission->admit(
            $context->site()->identifier(),
            self::initialBlueprint($model, $binding, $blockLocks, $theme),
        );

        try {
            $this->transactions->transactional(function () use ($context, $binding, $artifact): void {
                $winner = $this->bindings->blueprint(
                    $context->site(),
                    $binding->contentTypeId,
                    $binding->contentTypeVersion,
                );
                if ($winner !== null) {
                    throw new StudioPersistenceRace('A Content composition was concurrently provisioned.');
                }
                if (!$this->artifacts->store($artifact, null)) {
                    throw new StudioPersistenceRace('A Studio Blueprint was concurrently provisioned.');
                }
                $this->bindingStore->add($binding);
                $this->audit->record(new AuditEvent(
                    Uuid::uuid7()->toString(),
                    $this->clock->now(),
                    $context->actorId(),
                    'studio.composition.provision',
                    'content_type',
                    $binding->contentTypeId,
                    'success',
                    [
                        'binding_revision' => 1,
                        'blueprint_identity_digest' => hash('sha256', $binding->blueprintId),
                        'content_type_version' => $binding->contentTypeVersion,
                        'site_identifier' => $binding->site->identifier(),
                    ],
                ));
            });
        } catch (StudioPersistenceRace) {
            $winner = $this->find($context, $contentTypeId, $contentTypeVersion);
            if ($winner !== null) {
                return $winner;
            }
            throw new RuntimeException('The concurrent Studio composition could not be resolved.');
        }

        return new StudioContentComposition($model, $binding, $artifact);
    }

    /**
     * The locked Blueprint reference one Content type version resolves to, without writing anything.
     *
     * A provisioned composition answers with its exact current revision; a version that has never been
     * provisioned answers with the deterministic identity and revision `provision()` would mint for it,
     * so a catalogue can name the Blueprint a later start will find.
     *
     * @param   ExecutionContext  $context             Authorized actor and site context.
     * @param   string            $contentTypeId       Exact Content type UUID.
     * @param   int               $contentTypeVersion  Exact published Content type version.
     * @param   list<string>      $renderers           Deployment-supported renderer capabilities.
     *
     * @return  stdClass  `{id, version, revision}` locked Blueprint reference.
     *
     * @throws  StudioCompositionModelMismatch  When a provisioned Blueprint no longer locks the authorized model.
     * @throws  StudioCompositionThemeMismatch  When a provisioned Blueprint no longer locks the published theme.
     *
     * @since   2.0.0
     */
    public function reference(
        ExecutionContext $context,
        string $contentTypeId,
        int $contentTypeVersion,
        array $renderers,
    ): stdClass {
        $existing = $this->find($context, $contentTypeId, $contentTypeVersion);
        if ($existing !== null) {
            return (object) [
                'id' => $existing->binding->blueprintId,
                'version' => $existing->binding->blueprintVersion,
                'revision' => $existing->blueprint->revision,
            ];
        }
        $model = $this->authorizedModel($context, $contentTypeId, $contentTypeVersion);
        $binding = new ContentBlueprintBinding(
            $context->site(),
            strtolower($contentTypeId),
            $contentTypeVersion,
            self::blueprintId($contentTypeId, $contentTypeVersion),
            '1.0.0',
            null,
            1,
        );
        $initial = self::initialBlueprint(
            $model,
            $binding,
            $this->contributions->project([], $renderers)->blockLocks,
            $this->theme->reference($context->site()),
        );

        return (object) ['id' => $initial->id, 'version' => $initial->version, 'revision' => $initial->revision];
    }

    /**
     * Bind one authored Blueprint to a Content type version that has no composition yet.
     *
     * This is how a reusable-type save outcome persists the layout an author composed in Studio: the
     * document's identity, revision, lock and lifecycle status are rewritten to the host-owned
     * coordinates of the new type version, every locked block must be one this deployment renders, and
     * the artifact, binding and audit event commit together. An already-bound version is refused: a
     * published Blueprint revision never changes in place.
     *
     * @param   ExecutionContext  $context             Authorized actor and site context.
     * @param   string            $contentTypeId       Exact Content type UUID.
     * @param   int               $contentTypeVersion  Exact published Content type version.
     * @param   stdClass          $blueprint           Authored Blueprint document (roots and locks).
     * @param   list<stdClass>    $admittedLocks       Every exact `{type, version, revision}` lock the authoring
     *          session offered; the authored lock must be a subset at identical coordinates.
     * @param   string            $status              Lifecycle status to store, `draft` or `published`.
     *
     * @return  StudioContentComposition  Newly admitted composition.
     *
     * @throws  StudioCompositionLockMismatch  When a locked block is not renderable by this deployment.
     * @throws  \Kumwe\Producer\Error\HostRefusal  When the rewritten document fails artifact admission.
     * @throws  RuntimeException  When the version is already bound or the store refused the artifact.
     *
     * @since   2.0.0
     */
    public function adopt(
        ExecutionContext $context,
        string $contentTypeId,
        int $contentTypeVersion,
        stdClass $blueprint,
        array $admittedLocks,
        string $status,
    ): StudioContentComposition {
        if ($this->find($context, $contentTypeId, $contentTypeVersion) !== null) {
            throw new RuntimeException('The Content type version already binds a Studio Blueprint.');
        }
        $model = $this->authorizedModel($context, $contentTypeId, $contentTypeVersion);
        $binding = new ContentBlueprintBinding(
            $context->site(),
            strtolower($contentTypeId),
            $contentTypeVersion,
            self::blueprintId($contentTypeId, $contentTypeVersion),
            '1.0.0',
            null,
            1,
        );
        $theme = $this->theme->reference($context->site());
        $initial = self::initialBlueprint($model, $binding, $admittedLocks, $theme);
        $admitted = self::lockMap($initial);
        $document = json_decode(json_encode($blueprint, JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR);
        if (!$document instanceof stdClass) {
            throw new RuntimeException('The authored Studio Blueprint is not an object.');
        }
        $lock = $document->dependencyLock ?? null;
        $blocks = $lock instanceof stdClass ? ($lock->blocks ?? null) : null;
        if (!is_array($blocks)) {
            throw new StudioCompositionLockMismatch('dependencyLock');
        }
        foreach ($blocks as $block) {
            $type = $block instanceof stdClass ? ($block->type ?? null) : null;
            $version = $block instanceof stdClass ? ($block->version ?? null) : null;
            $revision = $block instanceof stdClass ? ($block->revision ?? null) : null;
            if (
                !is_string($type)
                || !is_string($version)
                || !is_string($revision)
                || ($admitted[$type] ?? null) !== [$version, $revision]
            ) {
                throw new StudioCompositionLockMismatch(is_string($type) ? $type : 'dependencyLock');
            }
        }
        $initialRevision = $initial->revision;
        if (!is_string($initialRevision)) {
            throw new RuntimeException('The initial Studio Blueprint revision is invalid.');
        }
        $document->contractVersion = '0.1-draft';
        $document->kind = 'blueprint';
        $document->id = $binding->blueprintId;
        $document->version = $binding->blueprintVersion;
        $document->revision = 'authored-' . hash('sha256', implode("\n", [
            $initialRevision,
            $status,
            json_encode($document->roots ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            json_encode($blocks, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]));
        $document->owner = $initial->owner;
        $document->status = $status;
        $document->model = $initial->model;
        $document->dependencyLock = (object) ['theme' => $theme->document(), 'blocks' => $blocks];
        $artifact = $this->admission->admit($context->site()->identifier(), $document);
        $bound = new ContentBlueprintBinding(
            $binding->site,
            $binding->contentTypeId,
            $binding->contentTypeVersion,
            $binding->blueprintId,
            $binding->blueprintVersion,
            $artifact->revision,
            1,
        );
        $this->transactions->transactional(function () use ($context, $bound, $artifact): void {
            $existing = $this->bindings->blueprint($context->site(), $bound->contentTypeId, $bound->contentTypeVersion);
            if ($existing !== null) {
                throw new StudioPersistenceRace('A Content composition was concurrently provisioned.');
            }
            if (!$this->artifacts->store($artifact, null)) {
                throw new StudioPersistenceRace('A Studio Blueprint was concurrently provisioned.');
            }
            $this->bindingStore->add($bound);
            $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $this->clock->now(),
                $context->actorId(),
                'studio.composition.adopt',
                'content_type',
                $bound->contentTypeId,
                'success',
                [
                    'binding_revision' => 1,
                    'blueprint_identity_digest' => hash('sha256', $bound->blueprintId),
                    'blueprint_revision' => $artifact->revision,
                    'content_type_version' => $bound->contentTypeVersion,
                    'site_identifier' => $bound->site->identifier(),
                    'status' => $artifact->status,
                ],
            ));
        });

        return new StudioContentComposition($model, $bound, $artifact);
    }

    /**
     * Index one Blueprint's admitted block locks by type for exact lock comparison.
     *
     * @param   stdClass  $blueprint  Blueprint whose dependency lock is authoritative.
     *
     * @return  array<string, array{0: string, 1: string}>  Admitted `[version, revision]` pairs by block type.
     *
     * @since   2.0.0
     */
    private static function lockMap(stdClass $blueprint): array
    {
        $lock = $blueprint->dependencyLock ?? null;
        $blocks = $lock instanceof stdClass ? ($lock->blocks ?? null) : null;
        $map = [];
        foreach (is_array($blocks) ? $blocks : [] as $block) {
            $type = $block instanceof stdClass ? ($block->type ?? null) : null;
            $version = $block instanceof stdClass ? ($block->version ?? null) : null;
            $revision = $block instanceof stdClass ? ($block->revision ?? null) : null;
            if (is_string($type) && is_string($version) && is_string($revision)) {
                $map[$type] = [$version, $revision];
            }
        }

        return $map;
    }

    /**
     * Derive the stable host-owned Blueprint identity for one Content type version.
     *
     * @param   string  $contentTypeId       Canonical Content type UUID.
     * @param   int     $contentTypeVersion  Exact published Content type version.
     *
     * @return  string  Stable Blueprint identity.
     *
     * @since   2.0.0
     */
    public static function blueprintId(string $contentTypeId, int $contentTypeVersion): string
    {
        return sprintf('content-blueprint:%s:v%d', strtolower($contentTypeId), $contentTypeVersion);
    }

    /**
     * Obtain the authorized exact AP-2 model projection for one type version.
     *
     * @param   ExecutionContext  $context             Authorized actor and site context.
     * @param   string            $contentTypeId       Canonical Content type UUID.
     * @param   int               $contentTypeVersion  Exact published Content type version.
     *
     * @return  stdClass  Authorized exact Content model projection.
     *
     * @since   2.0.0
     */
    private function authorizedModel(
        ExecutionContext $context,
        string $contentTypeId,
        int $contentTypeVersion,
    ): stdClass {
        return $this->projection->model(
            $context,
            ContentStudioProjector::modelId($contentTypeId),
            ContentStudioProjector::modelVersion($contentTypeVersion),
        );
    }

    /**
     * Compare the immutable Blueprint model lock with the live authorized projection.
     *
     * @param   stdClass  $model      Current authorized Content-model projection.
     * @param   mixed     $candidate  Blueprint's locked model reference.
     *
     * @return  bool  True only when identifier, version, and revision all match exactly.
     *
     * @since   2.0.0
     */
    private static function matchesModel(stdClass $model, mixed $candidate): bool
    {
        $id = $model->id ?? null;
        $version = $model->version ?? null;
        $revision = $model->revision ?? null;

        return $candidate instanceof stdClass
            && is_string($id)
            && is_string($version)
            && is_string($revision)
            && ($candidate->id ?? null) === $id
            && ($candidate->version ?? null) === $version
            && ($candidate->revision ?? null) === $revision;
    }

    /**
     * Build the empty schema-valid Blueprint with immutable model, block, and public-theme locks.
     *
     * @param   stdClass                       $model       Exact AP-2 Content model projection.
     * @param   ContentBlueprintBinding        $binding     Initial host-owned binding.
     * @param   list<stdClass>                 $blockLocks  Deployment-renderable exact block locks.
     * @param   StudioPublishedThemeReference  $theme       Exact active public-theme reference.
     *
     * @return  stdClass  Canonical initial Blueprint document.
     *
     * @throws  RuntimeException  When the authorized model projection lacks an exact coordinate.
     *
     * @since   2.0.0
     */
    private static function initialBlueprint(
        stdClass $model,
        ContentBlueprintBinding $binding,
        array $blockLocks,
        StudioPublishedThemeReference $theme,
    ): stdClass {
        $modelId = $model->id ?? null;
        $modelVersion = $model->version ?? null;
        $modelRevision = $model->revision ?? null;
        if (
            !is_string($modelId)
            || $modelId === ''
            || !is_string($modelVersion)
            || $modelVersion === ''
            || !is_string($modelRevision)
            || $modelRevision === ''
        ) {
            throw new RuntimeException('The Studio Content-model projection coordinate is invalid.');
        }
        $modelReference = (object) [
            'id' => $modelId,
            'version' => $modelVersion,
            'revision' => $modelRevision,
        ];
        $revision = 'initial-' . hash('sha256', implode("\n", [
            $binding->site->identifier(),
            $binding->blueprintId,
            $binding->blueprintVersion,
            $modelRevision,
            (string) json_encode($blockLocks, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $theme->revision,
        ]));

        return (object) [
            'contractVersion' => '0.1-draft',
            'kind' => 'blueprint',
            'id' => $binding->blueprintId,
            'version' => $binding->blueprintVersion,
            'revision' => $revision,
            'owner' => (object) ['id' => 'kumwe.app/content', 'version' => '2.0.0'],
            'status' => 'draft',
            'label' => (object) [
                'key' => 'kumwe.app/content-blueprint',
                'defaultMessage' => 'Content composition',
            ],
            'model' => $modelReference,
            'dependencyLock' => (object) [
                'theme' => $theme->document(),
                'blocks' => $blockLocks,
            ],
            'roots' => [],
        ];
    }
}
