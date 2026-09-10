<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Http\Api\Business;

use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Domain\ScopeMode;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\ArchiveRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\DeleteRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\RelateRecordsCommand;
use Kumwe\App\BusinessRecord\Application\Command\ReorderRecordLinesCommand;
use Kumwe\App\BusinessRecord\Application\Command\RestoreRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\UnrelateRecordsCommand;
use Kumwe\App\BusinessRecord\Application\Command\UpdateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable;
use Kumwe\App\BusinessRecord\Application\Query\BrowseRecordsQuery;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\App\BusinessRecord\Application\Query\RecordHistoryQuery;
use Kumwe\App\BusinessSurface\Application\BusinessRecordQueryFactory;
use Kumwe\App\BusinessSurface\Application\BusinessSurface;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceCatalog;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceOperation;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceUseCases;
use Kumwe\App\Delivery\Http\Api\ApiExecutionContext;
use Kumwe\Context\Value\ExecutionContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Thin PSR-15 adapter for every generic business-record REST operation.
 *
 * One handler fronts the collection, item, lifecycle, action, approval-request and relationship routes.
 * A route may publish the explicit operation attribute declared below; otherwise the handler recognizes
 * the documented `/api/v1/business/records` method/path pairs. Resource identifiers always come from route
 * attributes. The handler performs transport validation, requires API exposure through the shared surface
 * catalog, and extracts the expected version from the parsed `If-Match` condition. It then creates the existing
 * application command or query and lets `BusinessRecordService` own ordinary record semantics. Custom views and
 * every action cross `BusinessSurfaceUseCases`, whose ordinary-action branch still delegates to that canonical
 * record service while its custom branch resolves the signed owner/handler/schema tuple.
 *
 * @since  2.0.0
 */
final readonly class BusinessRecordApiHandler implements RequestHandlerInterface
{
    /**
     * Request attribute carrying an explicit operation token.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string OPERATION_ATTRIBUTE = 'kumwe.business_record.operation';

    /**
     * Route attribute carrying a definition UUID or handle.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string DEFINITION_ATTRIBUTE = 'definition';

    /**
     * Route attribute carrying the definition-declared public record identity.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string RECORD_ATTRIBUTE = 'record';

    /**
     * Route attribute carrying a relationship handle.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string RELATIONSHIP_ATTRIBUTE = 'relation';

    /**
     * Route attribute carrying an action handle.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ACTION_ATTRIBUTE = 'action';

    /**
     * Route attribute carrying a custom view handle.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string VIEW_ATTRIBUTE = 'view';

    /**
     * Route attribute carrying a related record's public identity.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string TARGET_ATTRIBUTE = 'target';

    /**
     * Operation token selecting a bounded collection browse.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string BROWSE = 'records.browse';

    /**
     * Operation token selecting a JSON-AST collection search.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string SEARCH = 'records.search';

    /**
     * Operation token selecting record creation.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string CREATE = 'records.create';

    /**
     * Operation token selecting one-record reading.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string READ = 'records.read';

    /**
     * Operation token selecting an exact field patch.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string UPDATE = 'records.update';

    /**
     * Operation token selecting record archival.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ARCHIVE = 'records.archive';

    /**
     * Operation token selecting definition-governed deletion.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string DELETE = 'records.delete';

    /**
     * Operation token selecting archive or soft-delete restoration.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string RESTORE = 'records.restore';

    /**
     * Operation token selecting action execution.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ACTION = 'records.action';

    /**
     * Operation token selecting one definition-declared custom view.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string CUSTOM_VIEW = 'records.custom_view';

    /**
     * Operation token selecting a high-impact action approval request.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string APPROVAL = 'records.approval';

    /**
     * Operation token selecting bounded record history.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string HISTORY = 'records.history';

    /**
     * Operation token selecting one bounded relationship projection.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string RELATION = 'records.relation';

    /**
     * Operation token selecting relationship creation.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string RELATE = 'records.relate';

    /**
     * Operation token selecting relationship removal.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string UNRELATE = 'records.unrelate';

    /**
     * Operation token selecting complete ordered-relationship replacement.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string REORDER = 'records.reorder';

    /**
     * Wire the HTTP adapter to the one record application boundary and shared query/result grammar.
     *
     * @param  BusinessRecordService       $records    Owns every record use case, transaction and policy check.
     * @param  BusinessRecordQueryFactory  $queries    Builds the bounded shared query AST from decoded input.
     * @param  BusinessRecordApiResponder  $responder  Renders public results and stable problem documents.
     * @param  BusinessSurfaceCatalog      $catalog    Enforces API exposure before a definition can be used.
     * @param  BusinessSurfaceUseCases     $surfaces   Dispatches custom views and every declared action.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessRecordService $records,
        private BusinessRecordQueryFactory $queries,
        private BusinessRecordApiResponder $responder,
        private BusinessSurfaceCatalog $catalog,
        private BusinessSurfaceUseCases $surfaces,
    ) {
    }

    /**
     * Dispatch one matched generic business-record route.
     *
     * All modeled failures, including malformed transport input, are offered to the business responder.
     * Unknown throwables are rethrown by that responder for the global problem-details middleware. Each
     * branch reuses the execution context already minted by bearer authentication and never accepts site or
     * organization scope from a path, query string or body.
     *
     * @param   ServerRequestInterface  $request  Authenticated request with matched route attributes.
     *
     * @return  ResponseInterface  Public JSON result or stable RFC 9457 problem response.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return match ($this->operation($request)) {
                self::BROWSE => $this->browse($request),
                self::SEARCH => $this->search($request),
                self::CREATE => $this->create($request),
                self::READ => $this->read($request),
                self::UPDATE => $this->update($request),
                self::ARCHIVE => $this->archive($request),
                self::DELETE => $this->delete($request),
                self::RESTORE => $this->restore($request),
                self::ACTION => $this->action($request),
                self::CUSTOM_VIEW => $this->customView($request),
                self::APPROVAL => $this->approval($request),
                self::HISTORY => $this->history($request),
                self::RELATION => $this->relation($request),
                self::RELATE => $this->relate($request),
                self::UNRELATE => $this->unrelate($request),
                self::REORDER => $this->reorder($request),
                default => throw new InvalidArgumentException('The business-record operation is not supported.'),
            };
        } catch (Throwable $exception) {
            return $this->responder->problem($exception, (string) $request->getUri());
        }
    }

    /**
     * Browse one definition through the bounded query-string grammar.
     *
     * @param   ServerRequestInterface  $request  Collection GET request.
     *
     * @return  ResponseInterface  Policy-filtered page, cursor and requested aggregates.
     *
     * @since   2.0.0
     */
    private function browse(ServerRequestInterface $request): ResponseInterface
    {
        $context = ApiExecutionContext::fromRequest($request);
        $definition = $this->definition($request);
        $specification = $this->queries->create(BusinessRecordApiRequest::query($request));
        $metadata = $this->metadata($context, $definition, BusinessSurfaceOperation::Browse);

        return $this->responder->browse($this->records->browse(new BrowseRecordsQuery(
            $context,
            $definition,
            $specification,
            $this->organization($context, $metadata),
        )));
    }

    /**
     * Search one definition through the full bounded JSON query grammar.
     *
     * This route is read-only even though POST carries the typed AST; it uses no idempotency ledger.
     *
     * @param   ServerRequestInterface  $request  Search POST request carrying a query document.
     *
     * @return  ResponseInterface  Policy-filtered page, cursor and requested aggregates.
     *
     * @since   2.0.0
     */
    private function search(ServerRequestInterface $request): ResponseInterface
    {
        $context = ApiExecutionContext::fromRequest($request);
        $definition = $this->definition($request);
        $document = BusinessRecordApiRequest::queryDocument(BusinessRecordApiRequest::json($request));
        $specification = $this->queries->create($document);
        $metadata = $this->metadata($context, $definition, BusinessSurfaceOperation::Browse);

        return $this->responder->browse($this->records->browse(new BrowseRecordsQuery(
            $context,
            $definition,
            $specification,
            $this->organization($context, $metadata),
        )));
    }

    /**
     * Create one record from an exact value object and optional public identity.
     *
     * @param   ServerRequestInterface  $request  Collection POST request after idempotency middleware.
     *
     * @return  ResponseInterface  201 mutation result, version tag and resource location.
     *
     * @since   2.0.0
     */
    private function create(ServerRequestInterface $request): ResponseInterface
    {
        $context = ApiExecutionContext::fromRequest($request);
        $definition = $this->definition($request);
        $idempotencyKey = BusinessRecordApiRequest::idempotencyKey($request);
        $body = BusinessRecordApiRequest::json($request);
        BusinessRecordApiRequest::keys($body, ['values', 'record_id'], 'create body');
        $values = BusinessRecordApiRequest::object($body, 'values');
        $recordId = BusinessRecordApiRequest::optionalString($body, 'record_id');
        $metadata = $this->metadata($context, $definition, BusinessSurfaceOperation::Create);
        $result = $this->records->create(new CreateRecordCommand(
            $context,
            $definition,
            $values,
            $idempotencyKey,
            $this->organization($context, $metadata),
            $recordId,
        ));

        return $this->responder->mutation($result, 201, [
            'Location' => '/api/v1/business/records/' . rawurlencode($definition) . '/'
                . rawurlencode($result->recordId),
        ]);
    }

    /**
     * Read one record with a strict projection and lifecycle-visibility query.
     *
     * @param   ServerRequestInterface  $request  Item GET request.
     *
     * @return  ResponseInterface  Public record and strong version tag.
     *
     * @since   2.0.0
     */
    private function read(ServerRequestInterface $request): ResponseInterface
    {
        $context = ApiExecutionContext::fromRequest($request);
        $definition = $this->definition($request);
        $document = $this->readDocument($request);
        $specification = $this->queries->create($document);
        $metadata = $this->metadata($context, $definition, BusinessSurfaceOperation::Read);

        return $this->responder->record($this->records->read(new ReadRecordQuery(
            $context,
            $definition,
            $this->recordId($request),
            $this->organization($context, $metadata),
            $specification->projection->fields,
            $specification->includeArchived,
            $specification->includeDeleted,
            $specification->projection->includes,
        )));
    }

    /**
     * Apply an exact field patch under idempotency and optimistic concurrency.
     *
     * @param   ServerRequestInterface  $request  Item PATCH request after retry and precondition middleware.
     *
     * @return  ResponseInterface  Canonical mutation outcome and new version tag.
     *
     * @since   2.0.0
     */
    private function update(ServerRequestInterface $request): ResponseInterface
    {
        $context = ApiExecutionContext::fromRequest($request);
        $definition = $this->definition($request);
        $recordId = $this->recordId($request);
        $idempotencyKey = BusinessRecordApiRequest::idempotencyKey($request);
        $expected = BusinessRecordApiRequest::expectedVersion($request);
        $body = BusinessRecordApiRequest::json($request);
        BusinessRecordApiRequest::keys($body, ['values'], 'update body');
        $values = BusinessRecordApiRequest::object($body, 'values');
        $metadata = $this->metadata($context, $definition, BusinessSurfaceOperation::Update);

        return $this->responder->mutation($this->records->update(new UpdateRecordCommand(
            $context,
            $definition,
            $recordId,
            $expected,
            $values,
            $idempotencyKey,
            $this->organization($context, $metadata),
        )));
    }

    /**
     * Archive one live record under its current entity tag.
     *
     * @param   ServerRequestInterface  $request  Archive POST request.
     *
     * @return  ResponseInterface  Canonical mutation outcome and new version tag.
     *
     * @since   2.0.0
     */
    private function archive(ServerRequestInterface $request): ResponseInterface
    {
        $context = ApiExecutionContext::fromRequest($request);
        $definition = $this->definition($request);
        $recordId = $this->recordId($request);
        $idempotencyKey = BusinessRecordApiRequest::idempotencyKey($request);
        $expected = BusinessRecordApiRequest::expectedVersion($request);
        $this->emptyBody($request);
        $metadata = $this->metadata($context, $definition, BusinessSurfaceOperation::Archive);

        return $this->responder->mutation($this->records->archive(new ArchiveRecordCommand(
            $context,
            $definition,
            $recordId,
            $expected,
            $idempotencyKey,
            $this->organization($context, $metadata),
        )));
    }

    /**
     * Delete one live or archived record under its current entity tag.
     *
     * @param   ServerRequestInterface  $request  Item DELETE request.
     *
     * @return  ResponseInterface  Canonical soft- or hard-delete outcome.
     *
     * @since   2.0.0
     */
    private function delete(ServerRequestInterface $request): ResponseInterface
    {
        $context = ApiExecutionContext::fromRequest($request);
        $definition = $this->definition($request);
        $recordId = $this->recordId($request);
        $idempotencyKey = BusinessRecordApiRequest::idempotencyKey($request);
        $expected = BusinessRecordApiRequest::expectedVersion($request);
        $this->emptyBody($request);
        $metadata = $this->metadata($context, $definition, BusinessSurfaceOperation::Delete);

        return $this->responder->mutation($this->records->delete(new DeleteRecordCommand(
            $context,
            $definition,
            $recordId,
            $expected,
            $idempotencyKey,
            $this->organization($context, $metadata),
        )));
    }

    /**
     * Restore one archived or soft-deleted record under its current entity tag.
     *
     * @param   ServerRequestInterface  $request  Restore POST request.
     *
     * @return  ResponseInterface  Canonical restore outcome and new version tag.
     *
     * @since   2.0.0
     */
    private function restore(ServerRequestInterface $request): ResponseInterface
    {
        $context = ApiExecutionContext::fromRequest($request);
        $definition = $this->definition($request);
        $recordId = $this->recordId($request);
        $idempotencyKey = BusinessRecordApiRequest::idempotencyKey($request);
        $expected = BusinessRecordApiRequest::expectedVersion($request);
        $this->emptyBody($request);
        $metadata = $this->metadata($context, $definition, BusinessSurfaceOperation::Restore);

        return $this->responder->mutation($this->records->restore(new RestoreRecordCommand(
            $context,
            $definition,
            $recordId,
            $expected,
            $idempotencyKey,
            $this->organization($context, $metadata),
        )));
    }

    /**
     * Execute one definition-declared action under the current version and optional approval binding.
     *
     * @param   ServerRequestInterface  $request  Action POST request.
     *
     * @return  ResponseInterface  Canonical action outcome and new version tag.
     *
     * @since   2.0.0
     */
    private function action(ServerRequestInterface $request): ResponseInterface
    {
        $context = ApiExecutionContext::fromRequest($request);
        $definition = $this->definition($request);
        $recordId = $this->recordId($request);
        $idempotencyKey = BusinessRecordApiRequest::idempotencyKey($request);
        $expected = BusinessRecordApiRequest::expectedVersion($request);
        $body = BusinessRecordApiRequest::json($request, true);
        BusinessRecordApiRequest::keys($body, ['input', 'approval_request_id'], 'action body');
        $input = BusinessRecordApiRequest::optionalObject($body, 'input');
        $approvalRequestId = BusinessRecordApiRequest::optionalString($body, 'approval_request_id');
        return $this->responder->surfaceMutation($this->surfaces->action(
            $context,
            BusinessSurface::Api,
            $definition,
            $recordId,
            $expected,
            $this->actionHandle($request),
            $idempotencyKey->value(),
            $input,
            $approvalRequestId,
        ));
    }

    /**
     * Execute one fixed-route custom view through its policy-filtered signed application contract.
     *
     * POST is read-only here: it carries a bounded query AST and custom parameter object but creates no
     * idempotency entry. Collection and record routes share this branch; record identity is present only on
     * the latter. The REST representation omits the facade's repeated definition/operation metadata.
     *
     * @param   ServerRequestInterface  $request  Custom-view request carrying fixed route attributes.
     *
     * @return  ResponseInterface  Caller-visible view metadata and contract-validated data.
     *
     * @since   2.0.0
     */
    private function customView(ServerRequestInterface $request): ResponseInterface
    {
        $body = BusinessRecordApiRequest::json($request, true);
        BusinessRecordApiRequest::keys($body, ['query', 'parameters'], 'custom-view body');
        $result = $this->surfaces->customView(
            ApiExecutionContext::fromRequest($request),
            BusinessSurface::Api,
            $this->definition($request),
            $this->viewHandle($request),
            BusinessRecordApiRequest::optionalObject($body, 'query'),
            BusinessRecordApiRequest::optionalObject($body, 'parameters'),
            $this->optionalRecordId($request),
        );
        $view = $result['view'] ?? null;
        $data = $result['data'] ?? null;
        if (!is_array($view) || array_is_list($view) || !is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('The custom business view returned an invalid document.');
        }

        $view = array_intersect_key($view, array_fill_keys([
            'handle',
            'label',
            'kind',
            'fields',
            'filters',
            'sorts',
        ], true));
        if (array_keys($view) !== ['handle', 'label', 'kind', 'fields', 'filters', 'sorts']) {
            throw new InvalidArgumentException('The custom business view returned invalid metadata.');
        }

        return $this->responder->document(['view' => $view, 'data' => $data]);
    }

    /**
     * Request maker-checker approval for one exact high-impact action attempt.
     *
     * @param   ServerRequestInterface  $request  Approval POST with optional typed custom action input.
     *
     * @return  ResponseInterface  Whether approval is required and the new request identity when it is.
     *
     * @since   2.0.0
     */
    private function approval(ServerRequestInterface $request): ResponseInterface
    {
        $context = ApiExecutionContext::fromRequest($request);
        $definition = $this->definition($request);
        $recordId = $this->recordId($request);
        $idempotencyKey = BusinessRecordApiRequest::idempotencyKey($request);
        $expected = BusinessRecordApiRequest::expectedVersion($request);
        $body = BusinessRecordApiRequest::json($request, true);
        BusinessRecordApiRequest::keys($body, ['input'], 'action approval body');
        $result = $this->surfaces->requestActionApproval(
            $context,
            BusinessSurface::Api,
            $definition,
            $recordId,
            $expected,
            $this->actionHandle($request),
            $idempotencyKey->value(),
            BusinessRecordApiRequest::optionalObject($body, 'input'),
        );
        $approvalRequestId = $result['approval_request_id'] ?? null;
        if ($approvalRequestId !== null && !is_string($approvalRequestId)) {
            throw new InvalidArgumentException('The action approval result is invalid.');
        }

        return $this->responder->approval($approvalRequestId);
    }

    /**
     * Read one bounded page of policy-filtered record history.
     *
     * @param   ServerRequestInterface  $request  History GET request.
     *
     * @return  ResponseInterface  Public revision page and next version boundary.
     *
     * @since   2.0.0
     */
    private function history(ServerRequestInterface $request): ResponseInterface
    {
        $context = ApiExecutionContext::fromRequest($request);
        $query = BusinessRecordApiRequest::query($request);
        BusinessRecordApiRequest::keys($query, ['limit', 'before_version'], 'history query');
        $limit = $query['limit'] ?? 100;
        $before = $query['before_version'] ?? null;
        if (!is_int($limit) || ($before !== null && !is_int($before))) {
            throw new InvalidArgumentException('The business-record history window is invalid.');
        }
        $definition = $this->definition($request);
        $metadata = $this->metadata($context, $definition, BusinessSurfaceOperation::History);

        return $this->responder->history($this->records->history(new RecordHistoryQuery(
            $context,
            $definition,
            $this->recordId($request),
            $this->organization($context, $metadata),
            $limit,
            $before,
        )));
    }

    /**
     * Read one declared relationship through the bounded include projection.
     *
     * The relationship read uses read metadata and read authority, matching the route and an item read with
     * the same include. It first requires the handle to survive the policy-filtered metadata document, then
     * lets the canonical read repository apply its nested target plan and fixed include budget. Returning the
     * ordinary record envelope keeps the source version and entity tag beside the included rows a later relate,
     * unrelate, or reorder command must match.
     *
     * @param   ServerRequestInterface  $request  Relationship GET request.
     *
     * @return  ResponseInterface  Source record with exactly one policy-filtered relationship include.
     *
     * @since   2.0.0
     */
    private function relation(ServerRequestInterface $request): ResponseInterface
    {
        $context = ApiExecutionContext::fromRequest($request);
        $definition = $this->definition($request);
        $relationship = $this->relationship($request);
        $metadata = $this->metadata($context, $definition, BusinessSurfaceOperation::Read);
        $this->metadataHandle($metadata, 'relationships', $relationship);

        return $this->responder->record($this->records->read(new ReadRecordQuery(
            $context,
            $definition,
            $this->recordId($request),
            $this->organization($context, $metadata),
            includes: [$relationship],
        )));
    }

    /**
     * Link an existing record or create an owned line through a declared relationship.
     *
     * @param   ServerRequestInterface  $request  Relationship POST request.
     *
     * @return  ResponseInterface  Canonical relationship mutation and new source version.
     *
     * @since   2.0.0
     */
    private function relate(ServerRequestInterface $request): ResponseInterface
    {
        $context = ApiExecutionContext::fromRequest($request);
        $definition = $this->definition($request);
        $recordId = $this->recordId($request);
        $idempotencyKey = BusinessRecordApiRequest::idempotencyKey($request);
        $expected = BusinessRecordApiRequest::expectedVersion($request);
        $body = BusinessRecordApiRequest::json($request);
        BusinessRecordApiRequest::keys(
            $body,
            ['target_record_id', 'position', 'target_values'],
            'relationship body',
        );
        $targetRecordId = BusinessRecordApiRequest::string($body, 'target_record_id');
        $position = BusinessRecordApiRequest::optionalInteger($body, 'position');
        $targetValues = BusinessRecordApiRequest::optionalObject($body, 'target_values');
        $metadata = $this->metadata($context, $definition, BusinessSurfaceOperation::Relation);

        return $this->responder->mutation($this->records->relate(new RelateRecordsCommand(
            $context,
            $definition,
            $recordId,
            $expected,
            $this->relationship($request),
            $targetRecordId,
            $idempotencyKey,
            $position,
            $this->organization($context, $metadata),
            $targetValues,
        )));
    }

    /**
     * Remove one exact related public identity through a declared relationship.
     *
     * @param   ServerRequestInterface  $request  Relationship DELETE request.
     *
     * @return  ResponseInterface  Canonical unrelate result and new source version.
     *
     * @since   2.0.0
     */
    private function unrelate(ServerRequestInterface $request): ResponseInterface
    {
        $context = ApiExecutionContext::fromRequest($request);
        $definition = $this->definition($request);
        $recordId = $this->recordId($request);
        $idempotencyKey = BusinessRecordApiRequest::idempotencyKey($request);
        $expected = BusinessRecordApiRequest::expectedVersion($request);
        $this->emptyBody($request);
        $target = BusinessRecordApiRequest::route($request, self::TARGET_ATTRIBUTE, 'target');
        $metadata = $this->metadata($context, $definition, BusinessSurfaceOperation::Relation);

        return $this->responder->mutation($this->records->unrelate(new UnrelateRecordsCommand(
            $context,
            $definition,
            $recordId,
            $expected,
            $this->relationship($request),
            $target,
            $idempotencyKey,
            $this->organization($context, $metadata),
        )));
    }

    /**
     * Replace the complete order of one ordered relationship.
     *
     * @param   ServerRequestInterface  $request  Relationship-order PUT request.
     *
     * @return  ResponseInterface  Canonical reorder result and new source version.
     *
     * @since   2.0.0
     */
    private function reorder(ServerRequestInterface $request): ResponseInterface
    {
        $context = ApiExecutionContext::fromRequest($request);
        $definition = $this->definition($request);
        $recordId = $this->recordId($request);
        $idempotencyKey = BusinessRecordApiRequest::idempotencyKey($request);
        $expected = BusinessRecordApiRequest::expectedVersion($request);
        $body = BusinessRecordApiRequest::json($request);
        BusinessRecordApiRequest::keys($body, ['ordered_record_ids'], 'relationship-order body');
        $orderedRecordIds = BusinessRecordApiRequest::stringList($body, 'ordered_record_ids');
        $metadata = $this->metadata($context, $definition, BusinessSurfaceOperation::Reorder);

        return $this->responder->mutation($this->records->reorder(new ReorderRecordLinesCommand(
            $context,
            $definition,
            $recordId,
            $expected,
            $this->relationship($request),
            $orderedRecordIds,
            $idempotencyKey,
            $this->organization($context, $metadata),
        )));
    }

    /**
     * Resolve the explicit operation attribute, or the documented method/path pair as a compatibility path.
     *
     * Publishing the attribute is preferred because it avoids coupling dispatch to a deployment prefix.
     * The fallback lets ordinary Mezzio routes mount this handler directly without another middleware.
     *
     * @param   ServerRequestInterface  $request  Matched request.
     *
     * @return  string  One closed operation token matched by `handle()`.
     *
     * @throws  InvalidArgumentException  When neither an explicit token nor a known method/path pair exists.
     *
     * @since   2.0.0
     */
    private function operation(ServerRequestInterface $request): string
    {
        $operation = $request->getAttribute(self::OPERATION_ATTRIBUTE);
        if ($operation !== null) {
            if (!is_string($operation) || $operation === '') {
                throw new InvalidArgumentException('The business-record route operation is invalid.');
            }

            return $operation;
        }

        $method = strtoupper($request->getMethod());
        $path = rtrim($request->getUri()->getPath(), '/');

        return match (true) {
            preg_match('#^/api/v1/business/views/[^/]+/[^/]+$#D', $path) === 1 && $method === 'POST'
                => self::CUSTOM_VIEW,
            preg_match('#^/api/v1/business/views/[^/]+/[^/]+/[^/]+$#D', $path) === 1
                && $method === 'POST' => self::CUSTOM_VIEW,
            preg_match('#^/api/v1/business/records/[^/]+/search$#D', $path) === 1 && $method === 'POST'
                => self::SEARCH,
            preg_match('#^/api/v1/business/records/[^/]+$#D', $path) === 1 && $method === 'GET'
                => self::BROWSE,
            preg_match('#^/api/v1/business/records/[^/]+$#D', $path) === 1 && $method === 'POST'
                => self::CREATE,
            preg_match('#^/api/v1/business/records/[^/]+/[^/]+/archive$#D', $path) === 1 && $method === 'POST'
                => self::ARCHIVE,
            preg_match('#^/api/v1/business/records/[^/]+/[^/]+/restore$#D', $path) === 1 && $method === 'POST'
                => self::RESTORE,
            preg_match('#^/api/v1/business/records/[^/]+/[^/]+/history$#D', $path) === 1 && $method === 'GET'
                => self::HISTORY,
            preg_match('#^/api/v1/business/records/[^/]+/[^/]+/actions/[^/]+/approval$#D', $path) === 1
                && $method === 'POST' => self::APPROVAL,
            preg_match('#^/api/v1/business/records/[^/]+/[^/]+/actions/[^/]+$#D', $path) === 1
                && $method === 'POST' => self::ACTION,
            preg_match('#^/api/v1/business/records/[^/]+/[^/]+/relations/[^/]+/order$#D', $path) === 1
                && $method === 'PUT' => self::REORDER,
            preg_match('#^/api/v1/business/records/[^/]+/[^/]+/relations/[^/]+/[^/]+$#D', $path) === 1
                && $method === 'DELETE' => self::UNRELATE,
            preg_match('#^/api/v1/business/records/[^/]+/[^/]+/relations/[^/]+$#D', $path) === 1
                && $method === 'GET' => self::RELATION,
            preg_match('#^/api/v1/business/records/[^/]+/[^/]+/relations/[^/]+$#D', $path) === 1
                && $method === 'POST' => self::RELATE,
            preg_match('#^/api/v1/business/records/[^/]+/[^/]+$#D', $path) === 1 && $method === 'GET'
                => self::READ,
            preg_match('#^/api/v1/business/records/[^/]+/[^/]+$#D', $path) === 1 && $method === 'PATCH'
                => self::UPDATE,
            preg_match('#^/api/v1/business/records/[^/]+/[^/]+$#D', $path) === 1 && $method === 'DELETE'
                => self::DELETE,
            default => throw new InvalidArgumentException('The business-record operation is not supported.'),
        };
    }

    /**
     * Build the strict single-record read query document.
     *
     * A single read accepts field and bounded relationship projection plus archive/delete visibility.
     * Aggregates belong to collection queries and are rejected rather than silently ignored here.
     *
     * @param   ServerRequestInterface  $request  Item GET request.
     *
     * @return  array<string, mixed>  Query factory document containing only read-supported members.
     *
     * @throws  InvalidArgumentException  When an unsupported read query or projection member is present.
     *
     * @since   2.0.0
     */
    private function readDocument(ServerRequestInterface $request): array
    {
        $document = BusinessRecordApiRequest::query($request);
        BusinessRecordApiRequest::keys(
            $document,
            ['projection', 'include_archived', 'include_deleted'],
            'read query',
        );
        $projection = $document['projection'] ?? [];
        if (!is_array($projection) || ($projection !== [] && array_is_list($projection))) {
            throw new InvalidArgumentException('The business-record read projection must be an object.');
        }
        /** @var array<string, mixed> $projection */
        BusinessRecordApiRequest::keys($projection, ['fields', 'includes'], 'read projection');

        return $document;
    }

    /**
     * Require an empty or absent request body for a no-argument mutation.
     *
     * @param   ServerRequestInterface  $request  Lifecycle or relationship-removal request.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the body is not an empty JSON object.
     *
     * @since   2.0.0
     */
    private function emptyBody(ServerRequestInterface $request): void
    {
        BusinessRecordApiRequest::keys(
            BusinessRecordApiRequest::json($request, true),
            [],
            'operation body',
        );
    }

    /**
     * Read the definition route attribute.
     *
     * @param   ServerRequestInterface  $request  Matched business-record request.
     *
     * @return  string  Definition UUID or handle as routed.
     *
     * @since   2.0.0
     */
    private function definition(ServerRequestInterface $request): string
    {
        return BusinessRecordApiRequest::route($request, self::DEFINITION_ATTRIBUTE, 'definition');
    }

    /**
     * Read the public record route attribute.
     *
     * @param   ServerRequestInterface  $request  Matched item request.
     *
     * @return  string  Definition-declared public identity as routed.
     *
     * @since   2.0.0
     */
    private function recordId(ServerRequestInterface $request): string
    {
        return BusinessRecordApiRequest::route($request, self::RECORD_ATTRIBUTE, 'identity');
    }

    /**
     * Read an optional public record identity from a collection-or-record custom-view route.
     *
     * @param   ServerRequestInterface  $request  Matched custom-view request.
     *
     * @return  string|null  Record identity on the item route, otherwise null.
     *
     * @since   2.0.0
     */
    private function optionalRecordId(ServerRequestInterface $request): ?string
    {
        return $request->getAttribute(self::RECORD_ATTRIBUTE) === null ? null : $this->recordId($request);
    }

    /**
     * Read the relationship route attribute.
     *
     * @param   ServerRequestInterface  $request  Matched relationship request.
     *
     * @return  string  Relationship handle as routed.
     *
     * @since   2.0.0
     */
    private function relationship(ServerRequestInterface $request): string
    {
        return BusinessRecordApiRequest::route($request, self::RELATIONSHIP_ATTRIBUTE, 'relationship');
    }

    /**
     * Read the action route attribute.
     *
     * @param   ServerRequestInterface  $request  Matched action request.
     *
     * @return  string  Action handle as routed.
     *
     * @since   2.0.0
     */
    private function actionHandle(ServerRequestInterface $request): string
    {
        return BusinessRecordApiRequest::route($request, self::ACTION_ATTRIBUTE, 'action');
    }

    /**
     * Read the custom view route attribute.
     *
     * @param   ServerRequestInterface  $request  Matched custom-view request.
     *
     * @return  string  Definition-declared custom view handle.
     *
     * @since   2.0.0
     */
    private function viewHandle(ServerRequestInterface $request): string
    {
        return BusinessRecordApiRequest::route($request, self::VIEW_ATTRIBUTE, 'view');
    }

    /**
     * Require a caller-supplied handle to survive one policy-filtered metadata collection.
     *
     * @param   array<string, mixed>  $metadata    Safe definition document returned by the shared catalog.
     * @param   string                $collection  Metadata collection that must contain the handle.
     * @param   string                $handle      Caller-supplied relation, action, view, or field handle.
     *
     * @return  void
     *
     * @throws  BusinessRecordDefinitionUnavailable  When the collection is malformed or omits the handle.
     *
     * @since   2.0.0
     */
    private function metadataHandle(array $metadata, string $collection, string $handle): void
    {
        $items = $metadata[$collection] ?? null;
        if (is_array($items)) {
            foreach ($items as $item) {
                if (is_array($item) && ($item['handle'] ?? null) === $handle) {
                    return;
                }
            }
        }

        throw new BusinessRecordDefinitionUnavailable();
    }

    /**
     * Require one definition to be exposed for this exact API operation.
     *
     * The shared catalog collapses an absent, disabled, surface-hidden or policy-denied definition onto
     * `BusinessRecordDefinitionUnavailable`, which the responder renders as the same non-enumerating 404
     * used for a missing record. Its safe document also supplies the definition's declared scope mode.
     *
     * @param   ExecutionContext          $context     Authenticated API actor and membership.
     * @param   string                    $definition  Definition UUID or handle from the route.
     * @param   BusinessSurfaceOperation  $operation   Exact record operation this route performs.
     *
     * @return  array<string, mixed>  Policy-filtered API metadata for an exposed definition.
     *
     * @since   2.0.0
     */
    private function metadata(
        ExecutionContext $context,
        string $definition,
        BusinessSurfaceOperation $operation,
    ): array {
        return $this->catalog->definition($context, BusinessSurface::Api, $definition, $operation);
    }

    /**
     * Return authenticated organization scope only when the exposed definition declares that dimension.
     *
     * A caller cannot select this value. Global and site-scoped definitions receive null even when the actor
     * has an organization membership, while an organization-scoped definition fails closed if authentication
     * did not bind one.
     *
     * @param   ExecutionContext      $context   Authenticated actor and server-resolved membership.
     * @param   array<string, mixed>  $metadata  Policy-filtered catalog document carrying declared scope.
     *
     * @return  string|null  Exact authenticated organization identifier only for organization scope.
     *
     * @throws  InvalidArgumentException  When scope metadata is invalid or required membership is absent.
     *
     * @since   2.0.0
     */
    private function organization(ExecutionContext $context, array $metadata): ?string
    {
        $scopeValue = $metadata['scope'] ?? null;
        if (!is_string($scopeValue)) {
            throw new InvalidArgumentException('Business-surface scope metadata is invalid.');
        }
        $scope = ScopeMode::tryFrom($scopeValue)
            ?? throw new InvalidArgumentException('Business-surface scope metadata is invalid.');
        if (!in_array($scope, [ScopeMode::Organization, ScopeMode::SiteOrganization], true)) {
            return null;
        }

        return $context->organization()?->identifier()
            ?? throw new InvalidArgumentException('This business definition requires organization membership.');
    }
}
