<?php

declare(strict_types=1);

namespace Kumwe\App\Content\Application;

use DateTimeImmutable;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Content\Domain\ContentTypeDefinition;
use Kumwe\App\Content\Domain\JsonSchemaValidator;
use Kumwe\App\Content\Domain\SchemaCompatibilityChecker;
use Kumwe\App\Content\Domain\VersionConflict;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Workflow\Domain\WorkflowDefinition;
use Kumwe\App\Workflow\Domain\WorkflowStateDefinition;
use Kumwe\App\Workflow\Domain\WorkflowTransitionDefinition;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * Application service that owns every read and every published change to a site's content model.
 *
 * Authoring a content type or workflow is not a plain write: it has to be authorized, the schema has
 * to be one the validator can actually enforce, a change that would strand existing entries has to be
 * refused unless the operator overrides it, and the new version, its ownership row and its audit event
 * have to land in one transaction. Concentrating that here is what lets the administrator screens, the
 * JSON API and the console command share identical policy, and lets `ContentModelRepository` stay a
 * plain store. Definitions are versioned rather than mutated, so publishing never disturbs entries
 * already pinned to an earlier version.
 *
 * @since  2.0.0
 */
final readonly class ContentModelService
{
    /**
     * Wire the service to the store and the policy collaborators it defers to.
     *
     * @param  ContentModelRepository       $repository     Store the definitions are read from and published to.
     * @param  JsonSchemaValidator          $schemas        Rejects schemas outside the enforceable subset.
     * @param  SchemaCompatibilityChecker   $compatibility  Names the changes that would strand stored entries.
     * @param  AuthorizationGateway         $authorization  Decides whether the actor may read or publish.
     * @param  ResourceSiteOwnershipWriter  $ownership      Binds each new definition to the acting site.
     * @param  AuditRecorder                $audit          Receives one event per published change.
     * @param  TransactionManager           $transactions   Makes publication, ownership and audit atomic.
     * @param  ClockInterface               $clock          Supplies the timestamps stamped onto versions.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ContentModelRepository $repository,
        private JsonSchemaValidator $schemas,
        private SchemaCompatibilityChecker $compatibility,
        private AuthorizationGateway $authorization,
        private ResourceSiteOwnershipWriter $ownership,
        private AuditRecorder $audit,
        private TransactionManager $transactions,
        private ClockInterface $clock,
    ) {
    }

    /**
     * List the content types the acting site publishes, for pickers and API collection responses.
     *
     * The capability is checked once against the collection rather than per definition, because the
     * content model describes the site's shape and is not itself owned row by row.
     *
     * @param   ExecutionContext  $context  Actor and site the listing is performed for.
     *
     * @return  list<ContentTypeDefinition>  Head versions only, ordered by handle.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor lacks `content.read`.
     *
     * @since   2.0.0
     */
    public function contentTypes(ExecutionContext $context): array
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('content.read'),
            AuthorizationResource::collection('content_type'),
        );
        return $this->repository->contentTypes($context->site());
    }

    /**
     * Resolve one content type by handle or UUID, failing loudly instead of returning null.
     *
     * The lookup runs before the capability check so that authorization can name the resolved
     * definition; a caller asking for something that does not exist therefore learns that first,
     * regardless of what it is allowed to read.
     *
     * @param   ExecutionContext  $context     Actor and site the lookup is performed for.
     * @param   string            $identifier  UUID or operator-facing handle of the content type.
     * @param   ?int              $version     Version to load, or null for the current head.
     *
     * @return  ContentTypeDefinition  The definition at the requested version.
     *
     * @throws  ContentModelNotFound  When the site publishes no such content type at that version.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor lacks `content.read`.
     *
     * @since   2.0.0
     */
    public function contentType(
        ExecutionContext $context,
        string $identifier,
        ?int $version = null,
    ): ContentTypeDefinition {
        $definition = $this->repository->contentType($context->site(), $identifier, $version)
            ?? throw new ContentModelNotFound('content type', $identifier, $version);
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('content.read'),
            AuthorizationResource::item('content_type', $definition->id),
        );

        return $definition;
    }

    /**
     * Publish a brand new content type at version one and bind it to the acting site.
     *
     * The named workflow is resolved first and its head version pinned into the definition, so the
     * content type keeps pointing at the workflow shape it was designed against even after that
     * workflow is republished. Storage, ownership and audit all happen inside one transaction.
     *
     * @param   ExecutionContext      $context             Actor and site the content type is created for.
     * @param   string                $handle              Stable operator-facing name, unique within the site.
     * @param   string                $name                Human-readable label shown in administrator screens.
     * @param   string                $workflowIdentifier  UUID or handle of the workflow that will govern entries.
     * @param   array<string, mixed>  $schema              Contract the entry data of this type must satisfy.
     *
     * @return  ContentTypeDefinition  The stored definition, at version one with its workflow pinned.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor lacks `content.update`.
     * @throws  \InvalidArgumentException  When the schema uses keywords the validator cannot enforce.
     * @throws  ContentModelNotFound  When the site publishes no workflow under that identifier.
     *
     * @since   2.0.0
     */
    public function createContentType(
        ExecutionContext $context,
        string $handle,
        string $name,
        string $workflowIdentifier,
        array $schema,
    ): ContentTypeDefinition {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('content.update'),
            AuthorizationResource::collection('content_type'),
        );
        $this->schemas->assertSupported($schema);
        $workflow = $this->repository->workflow($context->site(), $workflowIdentifier)
            ?? throw new ContentModelNotFound('workflow', $workflowIdentifier);
        $now = $this->clock->now();
        $definition = new ContentTypeDefinition(
            Uuid::uuid7()->toString(),
            $context->site(),
            $handle,
            $name,
            $workflow->id,
            $workflow->version,
            $schema,
            1,
            $now,
            $now,
        );
        return $this->transactions->transactional(function () use ($definition, $context, $now): ContentTypeDefinition {
            $this->repository->insertContentType($definition);
            $this->ownership->record(AuthorizationResource::item('content_type', $definition->id), $context->site());
            $this->audit($context, 'content_type.create', 'content_type', $definition->id, $definition->version, $now);
            return $definition;
        });
    }

    /**
     * Publish the next version of a content type, refusing changes that would strand stored entries.
     *
     * The compatibility checker names every breaking difference between the stored schema and the new
     * one; if there are any and the operator has not opted in, nothing is written. When the operator
     * does opt in, the breaking list is carried into the audit event so the decision is recoverable
     * later. The handle and creation timestamp are inherited, so only the label, workflow and schema
     * can move between versions.
     *
     * @param   ExecutionContext      $context             Actor and site the publication is performed for.
     * @param   string                $id                  UUID of the content type to publish a new version of.
     * @param   int                   $expectedVersion     Version the operator loaded into the editor.
     * @param   string                $name                Human-readable label for the new version.
     * @param   string                $workflowIdentifier  UUID or handle of the workflow the new version pins.
     * @param   array<string, mixed>  $schema              Contract entries of this type must satisfy from now on.
     * @param   bool                  $allowBreaking       Whether to publish despite stranding stored entries.
     *
     * @return  ContentTypeDefinition  The stored definition, one version past the expected one.
     *
     * @throws  ContentModelNotFound  When the content type, or the named workflow, is not published here.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor lacks `content.update`.
     * @throws  VersionConflict  When another operator published a version after this one was loaded.
     * @throws  \InvalidArgumentException  When the schema uses keywords the validator cannot enforce.
     * @throws  IncompatibleDefinition  When the change is breaking and the operator did not opt in.
     *
     * @since   2.0.0
     */
    public function updateContentType(
        ExecutionContext $context,
        string $id,
        int $expectedVersion,
        string $name,
        string $workflowIdentifier,
        array $schema,
        bool $allowBreaking = false,
    ): ContentTypeDefinition {
        $current = $this->repository->contentType($context->site(), $id)
            ?? throw new ContentModelNotFound('content type', $id);
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('content.update'),
            AuthorizationResource::item('content_type', $current->id),
        );
        if ($current->version !== $expectedVersion) {
            throw new VersionConflict($expectedVersion, $current->version);
        }
        $this->schemas->assertSupported($schema);
        $breaking = $this->compatibility->breakingChanges($current->schema(), $schema);
        if ($breaking !== [] && !$allowBreaking) {
            /** @var non-empty-list<string> $breaking */
            throw new IncompatibleDefinition($breaking);
        }
        $workflow = $this->repository->workflow($context->site(), $workflowIdentifier)
            ?? throw new ContentModelNotFound('workflow', $workflowIdentifier);
        $now = $this->clock->now();
        $definition = new ContentTypeDefinition(
            $current->id,
            $context->site(),
            $current->handle,
            $name,
            $workflow->id,
            $workflow->version,
            $schema,
            $expectedVersion + 1,
            $current->createdAt,
            $now,
        );
        return $this->transactions->transactional(function () use (
            $definition,
            $expectedVersion,
            $context,
            $now,
            $breaking,
        ): ContentTypeDefinition {
            $this->repository->publishContentType($definition, $expectedVersion);
            $this->audit(
                $context,
                'content_type.publish',
                'content_type',
                $definition->id,
                $definition->version,
                $now,
                ['breaking_changes' => $breaking],
            );
            return $definition;
        });
    }

    /**
     * List the workflows the acting site publishes, for pickers and API collection responses.
     *
     * @param   ExecutionContext  $context  Actor and site the listing is performed for.
     *
     * @return  list<WorkflowDefinition>  Head versions only, ordered by handle.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor lacks `content.read`.
     *
     * @since   2.0.0
     */
    public function workflows(ExecutionContext $context): array
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('content.read'),
            AuthorizationResource::collection('workflow'),
        );
        return $this->repository->workflows($context->site());
    }

    /**
     * Resolve one workflow by handle or UUID, failing loudly instead of returning null.
     *
     * Content entries pin the workflow version they were authored against, so passing an explicit
     * version is the normal way to reproduce the states and transitions an existing entry obeys.
     *
     * @param   ExecutionContext  $context     Actor and site the lookup is performed for.
     * @param   string            $identifier  UUID or operator-facing handle of the workflow.
     * @param   ?int              $version     Version to load, or null for the current head.
     *
     * @return  WorkflowDefinition  The definition at the requested version.
     *
     * @throws  ContentModelNotFound  When the site publishes no such workflow at that version.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor lacks `content.read`.
     *
     * @since   2.0.0
     */
    public function workflow(ExecutionContext $context, string $identifier, ?int $version = null): WorkflowDefinition
    {
        $definition = $this->repository->workflow($context->site(), $identifier, $version)
            ?? throw new ContentModelNotFound('workflow', $identifier, $version);
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('content.read'),
            AuthorizationResource::item('workflow', $definition->id),
        );

        return $definition;
    }

    /**
     * Publish a brand new workflow at version one and bind it to the acting site.
     *
     * The raw state and transition documents come straight from an editor form or an API payload, so
     * they are mapped defensively — unknown keys are ignored and a missing transition capability
     * defaults to `content.update`. `WorkflowDefinition` then enforces the structural rules: exactly
     * one non-public initial state, unique keys and edges, and the publish and unpublish capabilities
     * on the transitions that cross the public boundary.
     *
     * @param   ExecutionContext            $context      Actor and site the workflow is created for.
     * @param   string                      $handle       Stable operator-facing name, unique within the site.
     * @param   string                      $name         Human-readable label shown in administrator screens.
     * @param   list<array<string, mixed>>  $states       Documents keyed `key`, `name`, `initial`, `public`.
     * @param   list<array<string, mixed>>  $transitions  Documents keyed `from`, `to`, `required_capability`.
     *
     * @return  WorkflowDefinition  The stored definition, at version one.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor lacks `content.update`.
     * @throws  \InvalidArgumentException  When the documents break a structural or capability rule.
     *
     * @since   2.0.0
     */
    public function createWorkflow(
        ExecutionContext $context,
        string $handle,
        string $name,
        array $states,
        array $transitions,
    ): WorkflowDefinition {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('content.update'),
            AuthorizationResource::collection('workflow'),
        );
        $now = $this->clock->now();
        $definition = $this->buildWorkflow(
            Uuid::uuid7()->toString(),
            $context,
            $handle,
            $name,
            $states,
            $transitions,
            1,
            $now,
            $now,
        );
        return $this->transactions->transactional(function () use ($definition, $context, $now): WorkflowDefinition {
            $this->repository->insertWorkflow($definition);
            $this->ownership->record(AuthorizationResource::item('workflow', $definition->id), $context->site());
            $this->audit($context, 'workflow.create', 'workflow', $definition->id, $definition->version, $now);
            return $definition;
        });
    }

    /**
     * Publish the next version of a workflow, refusing changes that would strand entries mid-flow.
     *
     * Breaking here means something an entry already sitting in the workflow could trip over: a state
     * that disappeared, a state whose public or initial flag moved, a removed edge, or an edge whose
     * required capability changed. The new definition is built and structurally validated before that
     * comparison runs, so a malformed submission is rejected on its own terms rather than as a
     * compatibility problem.
     *
     * @param   ExecutionContext            $context          Actor and site the publication is performed for.
     * @param   string                      $id               UUID of the workflow to publish a new version of.
     * @param   int                         $expectedVersion  Version the operator loaded into the editor.
     * @param   string                      $name             Human-readable label for the new version.
     * @param   list<array<string, mixed>>  $states           Documents keyed `key`, `name`, `initial`, `public`.
     * @param   list<array<string, mixed>>  $transitions      Documents keyed `from`, `to`, `required_capability`.
     * @param   bool                        $allowBreaking    Whether to publish despite stranding stored entries.
     *
     * @return  WorkflowDefinition  The stored definition, one version past the expected one.
     *
     * @throws  ContentModelNotFound  When the site publishes no workflow under that identifier.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor lacks `content.update`.
     * @throws  VersionConflict  When another operator published a version after this one was loaded.
     * @throws  \InvalidArgumentException  When the documents break a structural or capability rule.
     * @throws  IncompatibleDefinition  When the change is breaking and the operator did not opt in.
     *
     * @since   2.0.0
     */
    public function updateWorkflow(
        ExecutionContext $context,
        string $id,
        int $expectedVersion,
        string $name,
        array $states,
        array $transitions,
        bool $allowBreaking = false,
    ): WorkflowDefinition {
        $current = $this->repository->workflow($context->site(), $id)
            ?? throw new ContentModelNotFound('workflow', $id);
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('content.update'),
            AuthorizationResource::item('workflow', $current->id),
        );
        if ($current->version !== $expectedVersion) {
            throw new VersionConflict($expectedVersion, $current->version);
        }
        $now = $this->clock->now();
        $definition = $this->buildWorkflow(
            $id,
            $context,
            $current->handle,
            $name,
            $states,
            $transitions,
            $expectedVersion + 1,
            $current->createdAt,
            $now,
        );
        $breaking = $this->workflowBreakingChanges($current, $definition);
        if ($breaking !== [] && !$allowBreaking) {
            /** @var non-empty-list<string> $breaking */
            throw new IncompatibleDefinition($breaking);
        }
        return $this->transactions->transactional(function () use (
            $definition,
            $expectedVersion,
            $context,
            $now,
            $breaking,
        ): WorkflowDefinition {
            $this->repository->publishWorkflow($definition, $expectedVersion);
            $this->audit(
                $context,
                'workflow.publish',
                'workflow',
                $definition->id,
                $definition->version,
                $now,
                ['breaking_changes' => $breaking],
            );
            return $definition;
        });
    }

    /**
     * Map untrusted state and transition documents into a validated workflow definition.
     *
     * Shared by creation and publication so both paths coerce operator input identically: a value of
     * the wrong type falls back to the default rather than aborting, and the structural rules are left
     * to `WorkflowDefinition` to enforce once the whole shape is assembled.
     *
     * @param   string                      $id           UUID the definition is stored under, new or inherited.
     * @param   ExecutionContext            $context      Actor and site the definition is built for.
     * @param   string                      $handle       Stable operator-facing name, unique within the site.
     * @param   string                      $name         Human-readable label for this version.
     * @param   list<array<string, mixed>>  $states       Documents keyed `key`, `name`, `initial`, `public`.
     * @param   list<array<string, mixed>>  $transitions  Documents keyed `from`, `to`, `required_capability`.
     * @param   int                         $version      Version number this definition will carry.
     * @param   DateTimeImmutable           $created      When the workflow was first published, carried across.
     * @param   DateTimeImmutable           $published    When this particular version is being published.
     *
     * @return  WorkflowDefinition  A definition that has already passed every structural rule.
     *
     * @throws  \InvalidArgumentException  When the documents break a structural or capability rule.
     *
     * @since   2.0.0
     */
    private function buildWorkflow(
        string $id,
        ExecutionContext $context,
        string $handle,
        string $name,
        array $states,
        array $transitions,
        int $version,
        DateTimeImmutable $created,
        DateTimeImmutable $published,
    ): WorkflowDefinition {
        $mappedStates = array_map(static fn (array $state): WorkflowStateDefinition => new WorkflowStateDefinition(
            self::documentString($state, 'key'),
            self::documentString($state, 'name'),
            self::documentBoolean($state, 'initial'),
            self::documentBoolean($state, 'public'),
        ), $states);
        $mappedTransitions = array_map(
            static fn (array $transition): WorkflowTransitionDefinition => new WorkflowTransitionDefinition(
                self::documentString($transition, 'from'),
                self::documentString($transition, 'to'),
                Capability::fromString(self::documentString(
                    $transition,
                    'required_capability',
                    'content.update',
                )),
            ),
            $transitions,
        );
        return new WorkflowDefinition(
            $id,
            $context->site(),
            $handle,
            $name,
            $mappedStates,
            $mappedTransitions,
            $version,
            $created,
            $published,
        );
    }

    /**
     * Read one string field out of an untrusted document, falling back rather than failing.
     *
     * @param   array<string, mixed>  $document  Decoded state or transition document from the caller.
     * @param   string                $key       Field to read.
     * @param   string                $default   Value to use when the field is absent or is not a string.
     *
     * @return  string  The stored string, or the default when the field is missing or mistyped.
     *
     * @since   2.0.0
     */
    private static function documentString(array $document, string $key, string $default = ''): string
    {
        $value = $document[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /**
     * Read one boolean flag out of an untrusted document, treating anything non-boolean as false.
     *
     * The strictness matters: `initial` and `public` decide workflow structure, so a string `"true"`
     * from a form post must not quietly enable a flag the operator did not set.
     *
     * @param   array<string, mixed>  $document  Decoded state document from the caller.
     * @param   string                $key       Flag to read.
     *
     * @return  bool  True only when the field is present and is boolean true.
     *
     * @since   2.0.0
     */
    private static function documentBoolean(array $document, string $key): bool
    {
        $value = $document[$key] ?? false;

        return is_bool($value) && $value;
    }

    /**
     * Name every difference between two workflow versions that an in-flight entry could trip over.
     *
     * Additions are never breaking, so only removals and semantic changes are reported: a dropped
     * state, a state whose public or initial flag moved, a dropped edge, or an edge whose required
     * capability changed. The result is sorted so that the same pair of versions always produces the
     * same audit metadata and the same operator warning.
     *
     * @param   WorkflowDefinition  $before  Version currently published.
     * @param   WorkflowDefinition  $after   Version about to be published.
     *
     * @return  list<string>  Sorted operator-readable phrases; empty when the change is safe.
     *
     * @since   2.0.0
     */
    private function workflowBreakingChanges(WorkflowDefinition $before, WorkflowDefinition $after): array
    {
        $beforeStates = [];
        foreach ($before->states() as $state) {
            $beforeStates[$state->key] = $state;
        }
        $afterStates = [];
        foreach ($after->states() as $state) {
            $afterStates[$state->key] = $state;
        }
        $changes = [];
        foreach ($beforeStates as $key => $state) {
            if (!isset($afterStates[$key])) {
                $changes[] = 'removed state ' . $key;
            } elseif ($state->public !== $afterStates[$key]->public) {
                $changes[] = 'changed public visibility of state ' . $key;
            } elseif ($state->initial !== $afterStates[$key]->initial) {
                $changes[] = 'changed initial-state assignment of ' . $key;
            }
        }
        $afterTransitions = [];
        foreach ($after->transitions() as $transition) {
            $afterTransitions[$transition->from . '>' . $transition->to] = $transition;
        }
        foreach ($before->transitions() as $transition) {
            $edge = $transition->from . '>' . $transition->to;
            if (!isset($afterTransitions[$edge])) {
                $changes[] = 'removed transition ' . $edge;
            } elseif (
                $transition->requiredCapability->value()
                !== $afterTransitions[$edge]->requiredCapability->value()
            ) {
                $changes[] = 'changed capability of transition ' . $edge;
            }
        }
        sort($changes, SORT_STRING);
        return $changes;
    }

    /**
     * Record one successful content-model change on the audit trail.
     *
     * Called from inside the publishing transaction, so an event only survives if the definition it
     * describes did. The published version is always merged into the metadata, which is what lets an
     * auditor line an event up against a specific stored definition version.
     *
     * @param   ExecutionContext      $context   Actor and site the change was performed for.
     * @param   string                $action    Audit action name, such as `content_type.publish`.
     * @param   string                $type      Resource type the change applies to.
     * @param   string                $id        UUID of the definition that changed.
     * @param   int                   $version   Version number the change produced.
     * @param   DateTimeImmutable     $now       Instant the change was applied.
     * @param   array<string, mixed>  $metadata  Extra detail to merge in, such as the breaking-change list.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function audit(
        ExecutionContext $context,
        string $action,
        string $type,
        string $id,
        int $version,
        DateTimeImmutable $now,
        array $metadata = [],
    ): void {
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $now,
            $context->actorId(),
            $action,
            $type,
            $id,
            'success',
            ['version' => $version, ...$metadata],
        ));
    }
}
