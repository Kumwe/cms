<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Application\Custom;

use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\BusinessDefinition\Domain\ActionDefinition;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\App\BusinessDefinition\Domain\DefinitionOwnerType;
use Kumwe\App\BusinessDefinition\Domain\ViewDefinition;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceOperation;
use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessActionCommand;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessActionResult;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessViewQuery;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessViewResult;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Extension\Runtime\ExtensionExecutionContext;

/**
 * Resolves custom declarations from one installed definition and dispatches their typed handlers.
 *
 * The delivery-neutral facade is the only runtime bridge from immutable definition references to the
 * owner-aware registries. It never accepts a request object or executable callback. Missing definitions,
 * inactive handlers, wrong owners, and mismatched schemas all collapse to the same non-enumerating
 * definition failure. The action's own capability is asserted before extension application code runs.
 *
 * @since  2.0.0
 */
final readonly class CustomBusinessSurfaceDispatcher
{
    /**
     * Bind the dispatcher to live owner-aware registries and the canonical authorization gateway.
     *
     * @param  CustomBusinessViewHandlerRegistry    $views          Active typed view handlers.
     * @param  CustomBusinessActionHandlerRegistry  $actions        Active typed action handlers.
     * @param  AuthorizationGateway                 $authorization  Shared audited authorization boundary.
     * @param  ExtensionExecutionGate               $execution      Live generation authority for extension code.
     *
     * @since  2.0.0
     */
    public function __construct(
        private CustomBusinessViewHandlerRegistry $views,
        private CustomBusinessActionHandlerRegistry $actions,
        private AuthorizationGateway $authorization,
        private ExtensionExecutionGate $execution,
    ) {
    }

    /**
     * Report whether one definition action delegates to a typed custom handler.
     *
     * @param   EntityTypeDefinition  $definition  Active installed definition.
     * @param   string                $handle      Action handle requested by a shared surface.
     *
     * @return  bool  True only for a declared action carrying a complete custom reference pair.
     *
     * @since   2.0.0
     */
    public function handlesAction(EntityTypeDefinition $definition, string $handle): bool
    {
        foreach ($definition->actions() as $action) {
            if ($action->handle === $handle) {
                return $action->handler !== null && $action->schema !== null;
            }
        }

        return false;
    }

    /**
     * Publish only the caller-visible schemas for one active custom view contract.
     *
     * Handler and schema references remain private activation details. Returning null for every absent,
     * inactive, wrong-owner, or mismatched tuple lets catalogs omit the declaration without revealing
     * which part of an installed extension is unavailable.
     *
     * @param   EntityTypeDefinition  $definition  Active installed definition carrying the view.
     * @param   string                $handle      Definition-declared custom view handle.
     *
     * @return  array{query_schema: array<string, mixed>, result_schema: array<string, mixed>}|null
     *          Canonical safe contract schemas, or null when the exact tuple is not active.
     *
     * @since   2.0.0
     */
    public function viewContractSchemas(EntityTypeDefinition $definition, string $handle): ?array
    {
        $this->assertCurrentOwner($definition->owner);
        foreach ($definition->views() as $view) {
            if ($view->handle !== $handle || $view->handler === null || $view->schema === null) {
                continue;
            }
            $contract = $this->views->contract($definition->owner, $view->handler, $view->schema);
            if ($contract === null) {
                return null;
            }

            return [
                'query_schema' => $contract->querySchema->toArray(),
                'result_schema' => $contract->resultSchema->toArray(),
            ];
        }

        return null;
    }

    /**
     * Publish only the caller-visible schemas for one active custom action contract.
     *
     * @param   EntityTypeDefinition  $definition  Active installed definition carrying the action.
     * @param   string                $handle      Definition-declared custom action handle.
     *
     * @return  array{command_schema: array<string, mixed>, result_schema: array<string, mixed>}|null
     *          Canonical safe contract schemas, or null when the exact tuple is not active.
     *
     * @since   2.0.0
     */
    public function actionContractSchemas(EntityTypeDefinition $definition, string $handle): ?array
    {
        $this->assertCurrentOwner($definition->owner);
        foreach ($definition->actions() as $action) {
            if ($action->handle !== $handle || $action->handler === null || $action->schema === null) {
                continue;
            }
            $contract = $this->actions->contract($definition->owner, $action->handler, $action->schema);
            if ($contract === null) {
                return null;
            }

            return [
                'command_schema' => $contract->commandSchema->toArray(),
                'result_schema' => $contract->resultSchema->toArray(),
            ];
        }

        return null;
    }

    /**
     * Derive the exact generated-surface policy operation for a custom view.
     *
     * @param   EntityTypeDefinition  $definition  Active installed definition.
     * @param   string                $handle      Custom view handle.
     * @param   ?string               $recordId    Detail target, or null for collection/create views.
     *
     * @return  BusinessSurfaceOperation  Operation whose exposure and field policy must be checked.
     *
     * @throws  BusinessRecordDefinitionUnavailable  When the custom view does not exist or is inactive.
     *
     * @since   2.0.0
     */
    public function viewOperation(
        EntityTypeDefinition $definition,
        string $handle,
        ?string $recordId = null,
    ): BusinessSurfaceOperation {
        $view = $this->viewDefinition($definition, $handle);

        return match ($view->kind) {
            'list' => BusinessSurfaceOperation::Browse,
            'detail' => BusinessSurfaceOperation::Read,
            'form' => $recordId === null ? BusinessSurfaceOperation::Create : BusinessSurfaceOperation::Update,
            'history' => BusinessSurfaceOperation::History,
            'relation' => BusinessSurfaceOperation::Relation,
            default => throw new BusinessRecordDefinitionUnavailable(),
        };
    }

    /**
     * Resolve the closed query schema for one active custom view contract.
     *
     * Delivery adapters receive only the contract's declarative schema, never its executable handler or
     * owner-scoped registry entry. The same exact owner and reference tuple required for execution must be
     * active, so a browser cannot render a stale parameter form after its provider has been withdrawn.
     *
     * @param   EntityTypeDefinition  $definition  Active installed definition carrying the view.
     * @param   string                $handle      Custom view handle.
     *
     * @return  array<string, mixed>  Canonical closed object schema for query parameters.
     *
     * @throws  BusinessRecordDefinitionUnavailable  When the declaration or live contract is unavailable.
     *
     * @since   2.0.0
     */
    public function viewQuerySchema(EntityTypeDefinition $definition, string $handle): array
    {
        $this->assertCurrentOwner($definition->owner);
        $view = $this->viewDefinition($definition, $handle);
        $contract = $this->views->contract(
            $definition->owner,
            (string) $view->handler,
            (string) $view->schema,
        );
        if ($contract === null) {
            throw new BusinessRecordDefinitionUnavailable();
        }

        return $contract->querySchema->toArray();
    }

    /**
     * Execute one schema-validated custom view under its exact published owner and references.
     *
     * @param   EntityTypeDefinition     $definition  Active installed definition carrying the view.
     * @param   CustomBusinessViewQuery  $query       Delivery-neutral bounded query and context.
     *
     * @return  CustomBusinessViewResult  Contract-validated bounded result.
     *
     * @throws  BusinessRecordDefinitionUnavailable  When the declaration or live contract is unavailable.
     * @throws  \InvalidArgumentException  When query parameters violate the signed contract.
     * @throws  CustomBusinessHandlerFailed  When extension application code raises any throwable.
     *
     * @since   2.0.0
     */
    public function view(
        EntityTypeDefinition $definition,
        CustomBusinessViewQuery $query,
    ): CustomBusinessViewResult {
        $this->assertCurrentOwner($definition->owner);
        self::context($query->context);
        $view = $this->viewDefinition($definition, $query->view);
        $handler = (string) $view->handler;
        $schema = (string) $view->schema;
        if ($this->views->contract($definition->owner, $handler, $schema) === null) {
            throw new BusinessRecordDefinitionUnavailable();
        }

        return $this->views->execute($definition->owner, $handler, $schema, $query);
    }

    /**
     * Execute one schema-validated custom action under its declared capability and exact references.
     *
     * The handler remains application code: it composes authorized, audited, transactional services for
     * its domain mutation and uses the command's version, approval, and idempotency values. This dispatcher
     * guarantees that no delivery adapter can select executable code outside the signed definition tuple.
     *
     * @param   EntityTypeDefinition         $definition  Active installed definition carrying the action.
     * @param   CustomBusinessActionCommand  $command     Delivery-neutral guarded command and context.
     *
     * @return  CustomBusinessActionResult  Contract-validated, operation-bound result.
     *
     * @throws  BusinessRecordDefinitionUnavailable  When the declaration or live contract is unavailable.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the action capability is denied.
     * @throws  \InvalidArgumentException  When command input or result data violates the signed contract.
     * @throws  CustomBusinessHandlerFailed  When extension application code raises any throwable.
     *
     * @since   2.0.0
     */
    public function action(
        EntityTypeDefinition $definition,
        CustomBusinessActionCommand $command,
    ): CustomBusinessActionResult {
        $this->assertCurrentOwner($definition->owner);
        $context = self::context($command->context);
        $action = $this->actionDefinition($definition, $command->action);
        $handler = (string) $action->handler;
        $schema = (string) $action->schema;
        if ($this->actions->contract($definition->owner, $handler, $schema) === null) {
            throw new BusinessRecordDefinitionUnavailable();
        }
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString($action->capability),
            AuthorizationResource::collection('business_record'),
        );

        return $this->actions->execute($definition->owner, $handler, $schema, $command);
    }

    /**
     * Require the reusable SDK envelope to carry the App authority that minted the invocation.
     *
     * @param   \Kumwe\Extension\Spi\Application\ExecutionContext  $context  Canonical invocation identity.
     *
     * @return  ExecutionContext  Exact host authorization context.
     *
     * @throws  BusinessRecordDefinitionUnavailable  When a caller supplies a foreign context implementation.
     *
     * @since   2.0.0
     */
    private static function context(
        \Kumwe\Extension\Spi\Application\ExecutionContext $context,
    ): ExecutionContext {
        return ExtensionExecutionContext::host($context) ?? throw new BusinessRecordDefinitionUnavailable();
    }

    /**
     * Fence extension-owned contracts and handlers against the live runtime generation.
     *
     * Core and site-authored definitions do not execute package code and therefore do not depend on an
     * extension publication. An extension owner does, so even schema discovery fails closed once a
     * disable, uninstall, replacement or trust withdrawal supersedes the resident graph.
     *
     * @param   DefinitionOwner  $owner  Owner of the definition selecting the custom contract.
     *
     * @return  void
     *
     * @throws  \RuntimeException  When an extension-owned surface belongs to a stale runtime generation.
     *
     * @since   2.0.0
     */
    private function assertCurrentOwner(DefinitionOwner $owner): void
    {
        if ($owner->type === DefinitionOwnerType::Extension) {
            $this->execution->assertCurrent();
        }
    }

    /**
     * Resolve a custom view without disclosing why an exact executable tuple is unavailable.
     *
     * @param   EntityTypeDefinition  $definition  Active installed definition.
     * @param   string                $handle      Requested view handle.
     *
     * @return  ViewDefinition  Exact custom view declaration.
     *
     * @throws  BusinessRecordDefinitionUnavailable  When no complete custom declaration matches.
     *
     * @since   2.0.0
     */
    private function viewDefinition(EntityTypeDefinition $definition, string $handle): ViewDefinition
    {
        foreach ($definition->views() as $view) {
            if ($view->handle === $handle && $view->handler !== null && $view->schema !== null) {
                return $view;
            }
        }

        throw new BusinessRecordDefinitionUnavailable();
    }

    /**
     * Resolve a custom action without disclosing why an exact executable tuple is unavailable.
     *
     * @param   EntityTypeDefinition  $definition  Active installed definition.
     * @param   string                $handle      Requested action handle.
     *
     * @return  ActionDefinition  Exact custom action declaration.
     *
     * @throws  BusinessRecordDefinitionUnavailable  When no complete custom declaration matches.
     *
     * @since   2.0.0
     */
    private function actionDefinition(EntityTypeDefinition $definition, string $handle): ActionDefinition
    {
        foreach ($definition->actions() as $action) {
            if ($action->handle === $handle && $action->handler !== null && $action->schema !== null) {
                return $action;
            }
        }

        throw new BusinessRecordDefinitionUnavailable();
    }
}
