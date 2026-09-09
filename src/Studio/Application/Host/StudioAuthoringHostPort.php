<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringService;
use Kumwe\Producer\Schema\StudioDocumentSchemaRegistry;
use Kumwe\Producer\Wire\HostResult;
use Kumwe\Producer\Wire\Port\AuthoringPortInterface;
use Kumwe\Producer\Wire\RequestContext;
use stdClass;

/**
 * Studio contextual authoring port backed exclusively by the Content authoring service.
 *
 * Producer has already resolved the route, validated the envelope, authorized the exact operation
 * against the live host session and, for the mutating operations, opened the App's atomic
 * transaction/audit/replay boundary. This port proves each argument against exactly the pinned
 * authoring definition the wire names, hands the trusted session to the application service, and
 * proves the result the same way before it is serialized.
 *
 * @since  2.0.0
 */
final readonly class StudioAuthoringHostPort implements AuthoringPortInterface
{
    /**
     * Bind the port to the authoritative authoring service and the pinned schema interpreter.
     *
     * @param  ContentStudioAuthoringService        $authoring  PHP-authoritative authoring operations.
     * @param  StudioDocumentSchemaRegistry         $schemas    Producer's exact pinned schema interpreter.
     * @param  StudioProducerRequestAuthority|null  $authority  Authorized Producer request scope, when bound.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ContentStudioAuthoringService $authoring,
        private StudioDocumentSchemaRegistry $schemas,
        private ?StudioProducerRequestAuthority $authority = null,
    ) {
    }

    /**
     * Bind this App-owned port implementation to one successfully authorized Producer request.
     *
     * @param   StudioProducerRequestAuthority  $authority  Trusted evidence for one exact dispatch.
     *
     * @return  self  Request-scoped authoring port.
     *
     * @since   2.0.0
     */
    public function forRequest(StudioProducerRequestAuthority $authority): self
    {
        return new self($this->authoring, $this->schemas, $authority);
    }

    /**
     * Resolve `authoring.resolve-target`.
     *
     * @param   mixed           $arguments  Validated Producer operation arguments.
     * @param   RequestContext  $context    Validated Producer request context.
     *
     * @return  HostResult  Canonical target resolution.
     *
     * @since   2.0.0
     */
    public function resolveTarget(mixed $arguments, RequestContext $context): HostResult
    {
        $authority = $this->requestAuthority();
        $request = $this->argument($arguments, 'request', 'authoring-target', 'resolveRequest');

        return new HostResult($this->authoring->resolveTarget($authority->context(), $authority->snapshot(), $request));
    }

    /**
     * Resolve `authoring.list-types`.
     *
     * @param   mixed           $arguments  Validated Producer operation arguments.
     * @param   RequestContext  $context    Validated Producer request context.
     *
     * @return  HostResult  Canonical reusable-type page.
     *
     * @since   2.0.0
     */
    public function listTypes(mixed $arguments, RequestContext $context): HostResult
    {
        $authority = $this->requestAuthority();
        $query = $this->argument($arguments, 'query', 'reusable-content-type', 'listQuery');

        return new HostResult($this->authoring->listTypes($authority->context(), $authority->snapshot(), $query));
    }

    /**
     * Resolve `authoring.start`.
     *
     * @param   mixed           $arguments  Validated Producer operation arguments.
     * @param   RequestContext  $context    Validated Producer request context.
     *
     * @return  HostResult  Canonical session snapshot.
     *
     * @since   2.0.0
     */
    public function start(mixed $arguments, RequestContext $context): HostResult
    {
        $authority = $this->requestAuthority();
        $request = $this->argument($arguments, 'request', 'authoring-session', 'startRequest');

        return new HostResult($this->authoring->start($authority->context(), $authority->snapshot(), $request));
    }

    /**
     * Resolve `authoring.plan-save`.
     *
     * @param   mixed           $arguments  Validated Producer operation arguments.
     * @param   RequestContext  $context    Validated Producer request context.
     *
     * @return  HostResult  Canonical save plan.
     *
     * @since   2.0.0
     */
    public function planSave(mixed $arguments, RequestContext $context): HostResult
    {
        $authority = $this->requestAuthority();
        $intent = $this->argument($arguments, 'intent', 'authoring-save', 'saveIntent');

        return new HostResult($this->authoring->planSave($authority->context(), $authority->snapshot(), $intent));
    }

    /**
     * Resolve `authoring.save-item`.
     *
     * @param   mixed           $arguments  Validated Producer operation arguments.
     * @param   RequestContext  $context    Validated Producer request context.
     *
     * @return  HostResult  Canonical save result.
     *
     * @since   2.0.0
     */
    public function saveItem(mixed $arguments, RequestContext $context): HostResult
    {
        $authority = $this->requestAuthority();
        $request = $this->argument($arguments, 'request', 'authoring-save', 'saveItemRequest');

        return new HostResult($this->authoring->saveItem($authority->context(), $authority->snapshot(), $request));
    }

    /**
     * Resolve `authoring.save-new-type-version`.
     *
     * @param   mixed           $arguments  Validated Producer operation arguments.
     * @param   RequestContext  $context    Validated Producer request context.
     *
     * @return  HostResult  Canonical save result.
     *
     * @since   2.0.0
     */
    public function saveNewTypeVersion(mixed $arguments, RequestContext $context): HostResult
    {
        $authority = $this->requestAuthority();
        $request = $this->argument($arguments, 'request', 'authoring-save', 'saveNewTypeVersionRequest');

        return new HostResult(
            $this->authoring->saveNewTypeVersion($authority->context(), $authority->snapshot(), $request),
        );
    }

    /**
     * Resolve `authoring.save-as-new-type`.
     *
     * @param   mixed           $arguments  Validated Producer operation arguments.
     * @param   RequestContext  $context    Validated Producer request context.
     *
     * @return  HostResult  Canonical save result.
     *
     * @since   2.0.0
     */
    public function saveAsNewType(mixed $arguments, RequestContext $context): HostResult
    {
        $authority = $this->requestAuthority();
        $request = $this->argument($arguments, 'request', 'authoring-save', 'saveAsNewTypeRequest');

        return new HostResult($this->authoring->saveAsNewType($authority->context(), $authority->snapshot(), $request));
    }

    /**
     * Extract the single named argument and prove it against its pinned definition.
     *
     * @param   mixed   $arguments   Decoded operation arguments.
     * @param   string  $member      The one admitted argument member.
     * @param   string  $kind        Pinned schema kind.
     * @param   string  $definition  Named definition inside that schema.
     *
     * @return  stdClass  Schema-valid argument document.
     *
     * @since   2.0.0
     */
    private function argument(mixed $arguments, string $member, string $kind, string $definition): stdClass
    {
        if (!$arguments instanceof stdClass || array_keys(get_object_vars($arguments)) !== [$member]) {
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-arguments');
        }
        $document = $arguments->{$member};
        if (
            !$document instanceof stdClass
            || !$this->schemas->validateDefinition($kind, $definition, $document)->valid()
        ) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/schema-invalid');
        }

        return $document;
    }

    /**
     * Require the per-request authority installed by the Producer host factory.
     *
     * @return  StudioProducerRequestAuthority  Trusted evidence for this dispatch.
     *
     * @since   2.0.0
     */
    private function requestAuthority(): StudioProducerRequestAuthority
    {
        return $this->authority ?? throw new \LogicException('A Studio authoring port requires request authority.');
    }
}
