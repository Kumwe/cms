<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Delivery\Browser;

use InvalidArgumentException;
use JsonException;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Application\Automation\IdempotencyKey;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordNotFound;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordValidationFailed;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordVersionConflict;
use Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordQueryPurpose;
use Kumwe\App\BusinessSurface\Application\BusinessOperationNotFound;
use Kumwe\App\BusinessSurface\Application\BusinessOperationStatusService;
use Kumwe\App\BusinessSurface\Application\BusinessSurface;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceOperation;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceService;
use Kumwe\App\BusinessReporting\Application\RecordExportReportProvider;
use Kumwe\App\BusinessReporting\Application\ReportService;
use Kumwe\App\BusinessReporting\Application\ReportUnavailable;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessHandlerFailed;
use Kumwe\App\Localization\Application\Translator;
use Ramsey\Uuid\Uuid;

/**
 * Shared progressive-enhancement browser controller for administrator and portal record workspaces.
 *
 * Both adapters submit the same closed form operations here and receive the same view models. Essential
 * list, detail, create, edit, relation, history, workflow, approval, archive, restore and delete paths are
 * therefore ordinary GET/POST requests; JavaScript may enhance controls but is never required to complete
 * a lifecycle. High-impact operations have an explicit GET confirmation state before their POST.
 *
 * @since  2.0.0
 */
final readonly class GeneratedBusinessBrowserController
{
    /**
     * Configure the shared controller.
     *
     * @param  BusinessSurfaceService          $business       Generated-business application facade.
     * @param  BusinessFormInputMapper         $forms          Schema-authorized nested input mapper.
     * @param  BusinessOperationStatusService  $operations     Caller-bound operation-status lookup.
     * @param  BusinessCustomViewPresenter     $customViews    Safe generic custom-result projector.
     * @param  BusinessDocumentPresenter       $documents      Document-view arrangement of safe read models.
     * @param  ReportService                   $reports        Shared report discovery and execution seam.
     * @param  RecordExportReportProvider      $recordExports  Derived record-set export reports.
     * @param  Translator                      $translator     Resolves browser wording for the locale in flight.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessSurfaceService $business,
        private BusinessFormInputMapper $forms,
        private BusinessOperationStatusService $operations,
        private BusinessCustomViewPresenter $customViews,
        private BusinessDocumentPresenter $documents,
        private ReportService $reports,
        private RecordExportReportProvider $recordExports,
        private Translator $translator,
    ) {
    }

    /**
     * Dispatch one decoded browser request.
     *
     * @param   ExecutionContext      $context     Authenticated actor and scope.
     * @param   BusinessSurface       $surface     Administrator or portal boundary.
     * @param   string                $basePath    Surface root, used for same-origin redirects.
     * @param   string                $method      HTTP method.
     * @param   ?string               $definition  Optional route definition identifier.
     * @param   ?string               $record      Optional route record identity.
     * @param   array<string, mixed>  $query       Decoded query parameters.
     * @param   array<string, mixed>  $form        Decoded form body.
     *
     * @return  BusinessBrowserResult  Page model or 303 redirect.
     *
     * @throws  InvalidArgumentException  When method, query, or form operation is unsupported.
     *
     * @since   2.0.0
     */
    public function dispatch(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $basePath,
        string $method,
        ?string $definition,
        ?string $record,
        array $query,
        array $form,
    ): BusinessBrowserResult {
        $result = match (strtoupper($method)) {
            'GET' => $this->read($context, $surface, $definition, $record, $query),
            'POST' => $this->write($context, $surface, $basePath, $definition, $record, $form),
            default => throw new InvalidArgumentException('Generated business pages support only GET and POST.'),
        };
        if ($result->redirect !== null) {
            return $result;
        }

        return new BusinessBrowserResult($result->template, [
            ...$result->data,
            'operation_id' => 'browser:' . $context->requestId(),
            'completed_operation_id' => $this->completedOperation($query),
            'completed_bulk_count' => $this->completedBulkCount($query),
        ], status: $result->status);
    }

    /**
     * Resolve one caller-bound operation-status page without revealing why a lookup failed.
     *
     * @param   ExecutionContext  $context      Authenticated actor and exact surface.
     * @param   string            $operationId  Route operation identity.
     *
     * @return  BusinessBrowserResult  Completed status view, or the same generic 404 for every failure.
     *
     * @since   2.0.0
     */
    public function operationStatus(ExecutionContext $context, string $operationId): BusinessBrowserResult
    {
        try {
            return new BusinessBrowserResult('business-status', [
                'operation_status' => $this->operations->getWithDefinitionReference($context, $operationId),
            ]);
        } catch (BusinessOperationNotFound | InvalidArgumentException) {
            return new BusinessBrowserResult('business-status', ['operation_status' => null], status: 404);
        }
    }

    /**
     * Resolve the exact proof purpose for a policy-visible high-impact browser action.
     *
     * Surface handlers call this before reading a second-factor credential. Resolution remains behind
     * the shared application facade, so a submitted action name cannot mint a proof for an undeclared,
     * hidden, or portal-unexposed operation.
     *
     * @param   ExecutionContext  $context     Authenticated browser actor and scope.
     * @param   BusinessSurface   $surface     Administrator or portal boundary.
     * @param   string            $definition  Definition UUID or stable handle.
     * @param   string            $action      Candidate action handle from the confirmation form.
     *
     * @return  string|null  Exact approval-binding purpose, or null for an ordinary action.
     *
     * @since   2.0.0
     */
    public function actionStepUpPurpose(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $action,
    ): ?string {
        return $this->business->actionStepUpPurpose($context, $surface, $definition, $action);
    }

    /**
     * Render one fixed-route relationship section with exactly that relationship hydrated.
     *
     * @param   ExecutionContext      $context       Authenticated actor and scope.
     * @param   BusinessSurface       $surface       Administrator or portal boundary.
     * @param   string                $definition    Source definition UUID or handle.
     * @param   string                $record        Source record identity.
     * @param   string                $relationship  Exact declared relationship handle.
     * @param   array<string, mixed>  $query         Selector return and lifecycle controls.
     *
     * @return  BusinessBrowserResult  Detail template focused on one bounded relationship.
     *
     * @since   2.0.0
     */
    public function relationship(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $record,
        string $relationship,
        array $query,
    ): BusinessBrowserResult {
        $model = $this->business->relationship(
            $context,
            $surface,
            $definition,
            $record,
            $relationship,
            ($query['archived'] ?? null) === '1',
            ($query['deleted'] ?? null) === '1',
        );
        $model = $this->relationshipChoices(
            $context,
            $surface,
            $definition,
            $record,
            $model,
            $query,
            $relationship,
        );

        return new BusinessBrowserResult('business-detail', [
            ...$model,
            'record_task' => 'relations',
            'operation_id' => 'browser:' . $context->requestId(),
            'completed_operation_id' => $this->completedOperation($query),
            'completed_bulk_count' => $this->completedBulkCount($query),
        ]);
    }

    /**
     * Render one fixed-route bounded entity, relationship, or media choice page.
     *
     * @param   ExecutionContext      $context     Authenticated actor and scope.
     * @param   BusinessSurface       $surface     Administrator or portal boundary.
     * @param   string                $basePath    Generated business surface root.
     * @param   string                $definition  Source definition UUID or handle.
     * @param   ?string               $record      Source record for update or relation selectors.
     * @param   ?string               $related     Relationship or entity-reference field handle.
     * @param   ?string               $media       Media-reference field handle.
     * @param   array<string, mixed>  $query       Bounded native search and cursor controls.
     *
     * @return  BusinessBrowserResult  Core-owned selector page.
     *
     * @since   2.0.0
     */
    public function choices(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $basePath,
        string $definition,
        ?string $record,
        ?string $related,
        ?string $media,
        array $query,
    ): BusinessBrowserResult {
        if (($related === null) === ($media === null)) {
            throw new InvalidArgumentException('A generated business selector requires one exact handle.');
        }
        $operation = match ($query['operation'] ?? null) {
            'create' => BusinessSurfaceOperation::Create,
            'update' => BusinessSurfaceOperation::Update,
            'relation' => BusinessSurfaceOperation::Relation,
            default => throw new InvalidArgumentException('A generated business selector context is invalid.'),
        };
        if (($operation === BusinessSurfaceOperation::Create) !== ($record === null)) {
            throw new InvalidArgumentException('A generated business selector route does not match its context.');
        }
        $handle = $related ?? (string) $media;
        $browserQuery = null;
        if ($related !== null) {
            if (($query['include_archived'] ?? null) !== null || ($query['include_deleted'] ?? null) !== null) {
                throw new InvalidArgumentException('A generated relationship selector cannot widen lifecycle scope.');
            }
            $browserQuery = BusinessBrowserQuery::fromQuery($query);
            $pageSize = $browserQuery->document()['page_size'] ?? 50;
            if (!is_int($pageSize) || $pageSize < 1 || $pageSize > 50) {
                throw new InvalidArgumentException('A generated selector page size must be between 1 and 50.');
            }
            $choices = $this->business->relationChoices(
                $context,
                $surface,
                $definition,
                $related,
                $record,
                $operation,
                $browserQuery->document(),
            );
        } else {
            if ($operation === BusinessSurfaceOperation::Relation) {
                throw new InvalidArgumentException('A media selector requires a create or update context.');
            }
            $choices = $this->business->mediaChoices(
                $context,
                $surface,
                $definition,
                (string) $media,
                $operation,
                $this->selectorSearch($query['search'] ?? ''),
            );
        }
        $nextCursor = $choices['next_cursor'] ?? null;

        return new BusinessBrowserResult('business-choices', [
            'choice_kind' => $related === null ? 'media' : 'related',
            'choice_handle' => $handle,
            'choice_operation' => $operation->value,
            'definition_handle' => $definition,
            'record_id' => $record,
            'choices' => $choices['items'] ?? [],
            'choice_search_fields' => $choices['search_fields'] ?? [],
            'choice_search' => is_string($query['search'] ?? null) ? $query['search'] : '',
            'choice_query_state' => $browserQuery?->formState(),
            'next_query' => $browserQuery !== null && is_string($nextCursor)
                ? $browserQuery->next($nextCursor)
                : null,
            'choice_return_path' => $this->choiceReturnPath(
                $basePath,
                $definition,
                $record,
                $handle,
                $operation,
            ),
        ]);
    }

    /**
     * Render one source-bound selector for a reference field on a new owned line.
     *
     * @param   ExecutionContext      $context       Authenticated actor and scope.
     * @param   BusinessSurface       $surface       Administrator or portal boundary.
     * @param   string                $basePath      Generated business surface root.
     * @param   string                $definition    Source definition UUID or handle.
     * @param   string                $record        Existing source record identity.
     * @param   string                $relationship  Owned-line relationship handle.
     * @param   string                $field         Reference field on the owned target.
     * @param   string                $kind          Fixed route kind, `relations` or `media`.
     * @param   array<string, mixed>  $query         Bounded native selector controls.
     *
     * @return  BusinessBrowserResult  Core-owned selector page using the nested target policy plan.
     *
     * @since   2.0.0
     */
    public function ownedLineChoices(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $basePath,
        string $definition,
        string $record,
        string $relationship,
        string $field,
        string $kind,
        array $query,
    ): BusinessBrowserResult {
        if (!in_array($kind, ['relations', 'media'], true)) {
            throw new InvalidArgumentException('An owned-line selector kind is invalid.');
        }
        if (($query['operation'] ?? null) !== 'relation') {
            throw new InvalidArgumentException('An owned-line selector context is invalid.');
        }
        unset($query['operation']);
        $browserQuery = null;
        if ($kind === 'relations') {
            if (($query['include_archived'] ?? null) !== null || ($query['include_deleted'] ?? null) !== null) {
                throw new InvalidArgumentException('An owned-line selector cannot widen lifecycle scope.');
            }
            $browserQuery = BusinessBrowserQuery::fromQuery($query);
            $document = $browserQuery->document();
            $pageSize = $document['page_size'] ?? 50;
            if (!is_int($pageSize) || $pageSize < 1 || $pageSize > 50) {
                throw new InvalidArgumentException('An owned-line selector page size must be between 1 and 50.');
            }
        } else {
            if (array_diff(array_keys($query), ['search']) !== []) {
                throw new InvalidArgumentException('An owned-line media selector query is invalid.');
            }
            $document = ['search' => $this->selectorSearch($query['search'] ?? '')];
        }
        $choices = $this->business->ownedLineFieldChoices(
            $context,
            $surface,
            $definition,
            $record,
            $relationship,
            $field,
            $document,
        );
        $nextCursor = $choices['next_cursor'] ?? null;

        return new BusinessBrowserResult('business-choices', [
            'choice_kind' => $kind === 'media' ? 'media' : 'related',
            'choice_handle' => $field,
            'choice_operation' => 'relation',
            'definition_handle' => $definition,
            'record_id' => $record,
            'choices' => $choices['items'] ?? [],
            'choice_search_fields' => $choices['search_fields'] ?? [],
            'choice_search' => is_string($query['search'] ?? null) ? $query['search'] : '',
            'choice_query_state' => $browserQuery?->formState(),
            'next_query' => $browserQuery !== null && is_string($nextCursor)
                ? $browserQuery->next($nextCursor)
                : null,
            'choice_return_path' => $basePath . '/' . rawurlencode($definition) . '/'
                . rawurlencode($record) . '/relationships/' . rawurlencode($relationship)
                . '?' . http_build_query([
                    'choice_relationship' => $relationship,
                    'choice_handle' => $field,
                ]),
        ]);
    }

    /**
     * Execute one fixed-route custom view through native, policy-filtered browser controls.
     *
     * Collection and record routes converge here, but the declared view kind must match the route shape.
     * Parameter controls come from the active signed contract, record controls are restricted to the
     * policy-visible view declaration, and arbitrary result data is converted to a core-owned semantic tree.
     *
     * @param   ExecutionContext      $context     Authenticated actor and scope.
     * @param   BusinessSurface       $surface     Administrator or explicitly opted-in portal boundary.
     * @param   string                $definition  Route definition identifier.
     * @param   string                $view        Fixed-route declared custom view handle.
     * @param   ?string               $record      Optional record identity for item-like views.
     * @param   array<string, mixed>  $query       Native query and schema-driven parameter controls.
     *
     * @return  BusinessBrowserResult  Generic custom-view page and optional contract-validated result.
     *
     * @throws  BusinessRecordDefinitionUnavailable  When the view kind does not match the fixed route.
     *
     * @since   2.0.0
     */
    public function customView(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $view,
        ?string $record,
        array $query,
    ): BusinessBrowserResult {
        $metadata = $this->business->customViewMetadata($context, $surface, $definition, $view, $record);
        $viewMetadata = $this->metadataObject(
            $metadata['view'] ?? null,
            'A generated custom view is unavailable.',
        );
        $kind = $viewMetadata['kind'] ?? null;
        if (
            !is_string($kind)
            || ($record === null && in_array($kind, ['detail', 'history', 'relation'], true))
            || ($record !== null && $kind === 'list')
        ) {
            throw new BusinessRecordDefinitionUnavailable();
        }
        $definitionMetadata = $this->metadataObject(
            $metadata['definition'] ?? null,
            'A generated custom view definition is unavailable.',
        );
        $fields = $this->metadataList(
            $definitionMetadata['fields'] ?? null,
            'A generated custom view field list is unavailable.',
        );
        $schema = $this->metadataObject(
            $metadata['parameter_schema'] ?? null,
            'A generated custom view schema is unavailable.',
        );

        $wording = $this->translator;
        try {
            $request = BusinessCustomViewRequest::fromQuery($wording, $query, $viewMetadata, $fields, $schema);
        } catch (InvalidArgumentException $exception) {
            $retainedQuery = $query;
            unset($retainedQuery['run']);
            $retainedQuery['configure'] = '1';
            try {
                $retained = BusinessCustomViewRequest::fromQuery(
                    $wording,
                    $retainedQuery,
                    $viewMetadata,
                    $fields,
                    $schema,
                );
            } catch (InvalidArgumentException) {
                $retained = BusinessCustomViewRequest::fromQuery($wording, [], $viewMetadata, $fields, $schema);
            }

            return $this->customViewError($metadata, $record, $retained, $exception->getMessage());
        }
        $execute = $request->fields === [] || $request->submitted;
        if (!$execute) {
            return new BusinessBrowserResult('business-custom-view', [
                ...$metadata,
                'record_id' => $record,
                'parameter_fields' => $request->fields,
                'parameter_supported' => true,
                'custom_view_submitted' => false,
                'query_state' => $request->records->formState(),
                'data_projection' => null,
            ]);
        }

        try {
            $result = $this->business->customView(
                $context,
                $surface,
                $definition,
                $view,
                $request->records->document(),
                $request->parameters,
                $record,
            );
            $candidateData = $result['data'] ?? null;
            $data = is_array($candidateData) && !array_is_list($candidateData)
                ? $this->objectValue($candidateData, 'A custom view result is malformed.')
                : null;
            $projection = $data === null ? null : $this->customViews->present($data);
        } catch (InvalidArgumentException | CustomBusinessHandlerFailed) {
            return $this->customViewError(
                $metadata,
                $record,
                $request,
                $this->translator->translate('core.business.browser.custom_view_failed'),
            );
        }

        return new BusinessBrowserResult('business-custom-view', [
            ...$metadata,
            'record_id' => $record,
            'parameter_fields' => $request->fields,
            'parameter_supported' => true,
            'custom_view_submitted' => true,
            'query_state' => $request->records->formState(),
            'data_projection' => $projection,
        ]);
    }

    /**
     * Rebuild a custom-view form from safe retained controls and a caller-safe message.
     *
     * @param   array<string, mixed>       $metadata  Policy-filtered definition and view metadata.
     * @param   string|null                $record    Optional record identity for detail-like views.
     * @param   BusinessCustomViewRequest  $request   Already decoded retained controls.
     * @param   string                     $message   Fixed or request-decoder-owned validation summary.
     *
     * @return  BusinessBrowserResult  Accessible 422 form without extension exception text.
     *
     * @since   2.0.0
     */
    private function customViewError(
        array $metadata,
        ?string $record,
        BusinessCustomViewRequest $request,
        string $message,
    ): BusinessBrowserResult {
        return new BusinessBrowserResult('business-custom-view', [
            ...$metadata,
            'record_id' => $record,
            'parameter_fields' => $request->fields,
            'parameter_supported' => true,
            'custom_view_submitted' => false,
            'query_state' => $request->records->formState(),
            'data_projection' => null,
            'error_summary' => $message,
        ], status: 422);
    }

    /**
     * Render one discover, list, form, detail, history, report, export, or confirmation page.
     *
     * @param   ExecutionContext      $context     Authenticated actor and scope.
     * @param   BusinessSurface       $surface     Administrator or portal boundary.
     * @param   ?string               $definition  Optional route definition identifier.
     * @param   ?string               $record      Optional route record identity.
     * @param   array<string, mixed>  $query       Decoded query parameters.
     *
     * @return  BusinessBrowserResult  Safe page model.
     *
     * @since   2.0.0
     */
    private function read(
        ExecutionContext $context,
        BusinessSurface $surface,
        ?string $definition,
        ?string $record,
        array $query,
    ): BusinessBrowserResult {
        if ($definition === null) {
            return new BusinessBrowserResult('business-index', [
                'definitions' => $this->business->discover($context, $surface),
            ]);
        }
        if ($record === null && ($query['new'] ?? null) === '1') {
            $model = $this->business->form(
                $context,
                $surface,
                $definition,
            );
            return new BusinessBrowserResult('business-form', $this->formChoices(
                $context,
                $surface,
                $definition,
                null,
                $model,
                $query,
            ));
        }
        if ($record === null && isset($query['bulk_operation'])) {
            return $this->bulkConfirmation($context, $surface, $definition, $query);
        }
        if ($record === null) {
            $purpose = match ($query['purpose'] ?? 'browse') {
                'browse' => BusinessRecordQueryPurpose::Browse,
                'report' => BusinessRecordQueryPurpose::Report,
                'export' => BusinessRecordQueryPurpose::Export,
                default => throw new InvalidArgumentException('A generated business query purpose is invalid.'),
            };
            $browserQuery = BusinessBrowserQuery::fromQuery($query);
            $model = $this->business->browse(
                $context,
                $surface,
                $definition,
                $browserQuery->document(),
                $purpose,
            );
            $definitionMetadata = $this->metadataMap(
                $model['definition'] ?? null,
                'Generated collection definition metadata is unavailable.',
            );
            $fields = $this->metadataList(
                $definitionMetadata['fields'] ?? null,
                'Generated collection field metadata is unavailable.',
            );
            $presentation = BusinessCollectionPresentation::fromQuery(
                $query,
                $fields,
                $this->defaultCollectionColumns($definitionMetadata),
            );
            $nextCursor = $model['next_cursor'] ?? null;
            return new BusinessBrowserResult('business-list', [
                ...$model,
                'visible_columns' => $this->visibleColumns($fields, $presentation->columns),
                'collection_presentation' => [
                    'columns' => $presentation->columns,
                    'density' => $presentation->density,
                    'representation' => $presentation->representation,
                ],
                'presentation_query' => http_build_query($presentation->query()),
                'query_state' => $browserQuery->formState(),
                'query_purpose' => $purpose->value,
                'next_query' => is_string($nextCursor) ? $browserQuery->next($nextCursor) : null,
                'record_export_report' => $this->recordExportReport($context, $definitionMetadata, $model),
            ]);
        }
        if (($query['edit'] ?? null) === '1') {
            $model = $this->business->form(
                $context,
                $surface,
                $definition,
                $record,
            );
            return new BusinessBrowserResult('business-form', $this->formChoices(
                $context,
                $surface,
                $definition,
                $record,
                $model,
                $query,
            ));
        }
        $recordTask = $this->recordTask($query);
        if (($query['history'] ?? null) === '1' || $recordTask === 'history') {
            return new BusinessBrowserResult('business-history', [
                'definition_handle' => $definition,
                'record_id' => $record,
                'record_task' => 'history',
                ...$this->business->history(
                    $context,
                    $surface,
                    $definition,
                    $record,
                    $this->positive($query['limit'] ?? 100, 200),
                    $this->optionalPositive($query['before_version'] ?? null),
                ),
            ]);
        }
        $confirmation = $query['confirm'] ?? null;
        if (is_string($confirmation) && in_array($confirmation, ['archive', 'delete', 'restore', 'action'], true)) {
            if ($confirmation === 'action') {
                return $this->actionConfirmation(
                    $context,
                    $surface,
                    $definition,
                    $record,
                    $query,
                );
            }
            return new BusinessBrowserResult('business-confirm', [
                ...$this->business->read($context, $surface, $definition, $record, true, true),
                'confirmation' => $confirmation,
                'action_handle' => null,
                'action_fields' => [],
                'approval_request' => false,
            ]);
        }

        if ($recordTask === 'summary') {
            $model = $this->business->document(
                $context,
                $surface,
                $definition,
                $record,
                ($query['archived'] ?? null) === '1',
                ($query['deleted'] ?? null) === '1',
            );
            if (isset($model['document_view'])) {
                return new BusinessBrowserResult('business-document', [
                    ...$model,
                    'record_task' => $recordTask,
                    'document' => $this->documents->present($model),
                ]);
            }
        } else {
            $model = $this->business->read(
                $context,
                $surface,
                $definition,
                $record,
                ($query['archived'] ?? null) === '1',
                ($query['deleted'] ?? null) === '1',
            );
        }
        return new BusinessBrowserResult('business-detail', [
            ...$this->relationshipChoices(
                $context,
                $surface,
                $definition,
                $record,
                $model,
                $query,
            ),
            'record_task' => $recordTask,
        ]);
    }

    /**
     * Resolve the queueable record-set CSV export report for one policy-visible collection.
     *
     * The affordance is honest by construction: it appears only when the export operation survived the
     * shared catalog's policy filter and the derived report passes the exact availability decision the
     * report surfaces use, so a rendered control always maps onto a queueable export.
     *
     * @param   ExecutionContext      $context     Authenticated actor and scope.
     * @param   array<string, mixed>  $definition  Policy-filtered definition metadata.
     * @param   array<string, mixed>  $model       Browse model carrying available operations.
     *
     * @return  string|null  Derived report identifier, or null when no queueable export exists.
     *
     * @since   2.0.0
     */
    private function recordExportReport(
        ExecutionContext $context,
        array $definition,
        array $model,
    ): ?string {
        $operations = $model['available_operations'] ?? null;
        $handle = $definition['handle'] ?? null;
        if (!is_array($operations) || !isset($operations['export']) || !is_string($handle)) {
            return null;
        }
        try {
            $report = $this->recordExports->forDefinition($context, $handle);
        } catch (ReportUnavailable) {
            return null;
        }

        return $this->reports->isAvailable($context, $report, BusinessRecordQueryPurpose::Export)
            ? $report->identifier()
            : null;
    }

    /**
     * Select the first generated list view as a policy-filtered collection default.
     *
     * @param   array<string, mixed>  $definition  Catalog metadata after surface and field policy.
     *
     * @return  list<string>  Ordered disclosed handles, or an empty list when no generated list view exists.
     *
     * @since   2.0.0
     */
    private function defaultCollectionColumns(array $definition): array
    {
        $views = $definition['views'] ?? [];
        if (!is_array($views) || !array_is_list($views)) {
            throw new InvalidArgumentException('Generated collection view metadata is unavailable.');
        }
        foreach ($views as $view) {
            if (
                is_array($view)
                && ($view['kind'] ?? null) === 'list'
                && ($view['custom'] ?? null) === false
            ) {
                return $this->stringMetadataList(
                    $view['fields'] ?? null,
                    'Generated collection view fields are unavailable.',
                );
            }
        }

        return [];
    }

    /**
     * Reorder policy-filtered field metadata without inventing or revealing a field.
     *
     * @param   list<array<string, mixed>>  $fields   Catalog fields visible for this operation.
     * @param   list<string>                $columns  Validated presentation order.
     *
     * @return  list<array<string, mixed>>  Exact metadata rendered by table and mobile cards.
     *
     * @since   2.0.0
     */
    private function visibleColumns(array $fields, array $columns): array
    {
        $indexed = [];
        foreach ($fields as $field) {
            $handle = $field['handle'] ?? null;
            if (is_string($handle)) {
                $indexed[$handle] = $field;
            }
        }

        return array_map(
            static fn (string $column): array => $indexed[$column],
            $columns,
        );
    }

    /**
     * Decode one server-addressable record task while retaining the legacy history link.
     *
     * @param   array<string, mixed>  $query  Browser query carrying an optional task.
     *
     * @return  string  Summary, relations, history, or actions task handle.
     *
     * @since   2.0.0
     */
    private function recordTask(array $query): string
    {
        $task = $query['task'] ?? 'summary';
        if (!is_string($task) || !in_array($task, ['summary', 'relations', 'history', 'actions'], true)) {
            throw new InvalidArgumentException('A generated business record task is invalid.');
        }

        return $task;
    }

    /**
     * Validate string metadata lists produced by the trusted catalog.
     *
     * @param   mixed   $value    Candidate list.
     * @param   string  $message  Stable failure message.
     *
     * @return  list<string>  Exact ordered strings.
     *
     * @since   2.0.0
     */
    private function stringMetadataList(mixed $value, string $message): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException($message);
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new InvalidArgumentException($message);
            }
        }

        return $value;
    }

    /**
     * Validate one metadata object received from the application facade.
     *
     * @param   mixed   $value    Candidate object.
     * @param   string  $message  Stable failure message.
     *
     * @return  array<string, mixed>  Valid object map.
     *
     * @since   2.0.0
     */
    private function metadataMap(mixed $value, string $message): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException($message);
        }
        $metadata = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new InvalidArgumentException($message);
            }
            $metadata[$key] = $item;
        }

        return $metadata;
    }

    /**
     * Apply one form operation and return a same-origin redirect.
     *
     * The two failures an operator can recover from without leaving the screen never reach the error
     * page. A rejected value re-renders the same form at 422 with every submitted value retained and
     * the offending fields marked, and a lost optimistic-concurrency race re-renders it at 409 with the
     * same retained values beside the version the record now carries. Both keep the typed document
     * intact; nothing is written on either path, so the newer record is never silently overwritten.
     *
     * @param   ExecutionContext      $context     Authenticated actor and scope.
     * @param   BusinessSurface       $surface     Administrator or portal boundary.
     * @param   string                $basePath    Surface root used for redirects.
     * @param   ?string               $definition  Optional route definition identifier.
     * @param   ?string               $record      Optional route record identity.
     * @param   array<string, mixed>  $form        Decoded form body.
     *
     * @return  BusinessBrowserResult  303 redirect, a 422 form carrying field errors, or a 409 form
     *          carrying the stale-version notice; every failing page retains the submitted values.
     *
     * @throws  BusinessRecordVersionConflict  When an operation carrying no typed input lost the race,
     *          leaving nothing to retain and no form to return to.
     *
     * @since   2.0.0
     */
    private function write(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $basePath,
        ?string $definition,
        ?string $record,
        array $form,
    ): BusinessBrowserResult {
        if ($definition === null) {
            throw new InvalidArgumentException('A generated business mutation requires a definition.');
        }
        $operation = $this->required($form, 'operation');
        $operationId = $this->required($form, 'operation_id');
        if ($operation === 'bulk' && ($form['prepare_bulk_input'] ?? null) === '1') {
            return $this->bulkConfirmation($context, $surface, $definition, $form);
        }
        if (($form['prepare_structure'] ?? null) === '1') {
            if ($operation === 'relate' && $record !== null) {
                $relationship = $this->required($form, 'relationship');
                $model = $this->business->relationship(
                    $context,
                    $surface,
                    $definition,
                    $record,
                    $relationship,
                );
                $model = $this->relationshipChoices(
                    $context,
                    $surface,
                    $definition,
                    $record,
                    $model,
                    [],
                    $relationship,
                    $this->nestedObject($form, 'target_values'),
                    [],
                    $this->nestedObject($form, 'target_structured'),
                    $this->nestedObject($form, 'target_choice_labels'),
                );

                return new BusinessBrowserResult('business-detail', [
                    ...$model,
                    'record_task' => 'relations',
                ]);
            }
            if (
                !in_array($operation, ['create', 'update'], true)
                || (($operation === 'create') !== ($record === null))
            ) {
                throw new InvalidArgumentException('A structured-field configuration request has invalid context.');
            }
            $model = $this->business->form(
                $context,
                $surface,
                $definition,
                $record,
                $this->values($form),
            );
            return new BusinessBrowserResult('business-form', $this->formChoices(
                $context,
                $surface,
                $definition,
                $record,
                $model,
                [],
                $this->nestedObject($form, 'structured'),
            ));
        }
        if ($operation === 'action_prepare') {
            if ($record === null) {
                throw new InvalidArgumentException('An action confirmation requires a record.');
            }
            return $this->actionConfirmation($context, $surface, $definition, $record, $form);
        }
        if (
            in_array($operation, ['archive', 'delete', 'restore', 'action', 'approval', 'bulk'], true)
            && ($form['confirmed'] ?? null) !== '1'
        ) {
            throw new InvalidArgumentException('This generated business operation requires explicit confirmation.');
        }
        $target = $basePath . '/' . rawurlencode($definition);
        try {
            if ($operation === 'create') {
                $model = $this->business->form($context, $surface, $definition);
                $fields = $this->metadataList(
                    $model['fields'] ?? null,
                    'A generated business form field list is unavailable.',
                );
                $values = $this->mapFormValues($form, $fields);
                $result = $this->business->create(
                    $context,
                    $surface,
                    $definition,
                    $values,
                    $operationId,
                    $this->optionalString($form, 'record_id'),
                );
                return BusinessBrowserResult::redirect(
                    $this->completedTarget(
                        $target . '/' . rawurlencode($this->required($result, 'record_id')),
                        $operationId,
                    ),
                );
            }
            if ($operation === 'bulk') {
                [$bulkOperation, $action] = $this->bulkOperation($form);
                try {
                    $bulkInput = $this->bulkActionInput(
                        $context,
                        $surface,
                        $definition,
                        $bulkOperation,
                        $action,
                        $form,
                    );
                } catch (InvalidArgumentException $exception) {
                    return $this->bulkConfirmation(
                        $context,
                        $surface,
                        $definition,
                        $form,
                        $exception->getMessage(),
                        422,
                    );
                }
                $result = $this->business->bulk(
                    $context,
                    $surface,
                    $definition,
                    $bulkOperation,
                    $this->bulkItems($form),
                    $operationId,
                    $action,
                    $bulkInput,
                );

                return BusinessBrowserResult::redirect($target . '?' . http_build_query([
                    'saved' => '1',
                    'bulk_count' => $result['count'],
                ]));
            }
            if ($record === null) {
                throw new InvalidArgumentException('This generated business mutation requires a record.');
            }
            $expected = $this->positive($form['expected_version'] ?? null);
            $recordTarget = $target . '/' . rawurlencode($record);
            $actionInput = [];
            if (in_array($operation, ['action', 'approval'], true)) {
                try {
                    $actionInput = $this->actionInput(
                        $context,
                        $surface,
                        $definition,
                        $record,
                        $this->required($form, 'action'),
                        $form,
                    );
                } catch (InvalidArgumentException $exception) {
                    return $this->actionConfirmation(
                        $context,
                        $surface,
                        $definition,
                        $record,
                        $form,
                        $exception->getMessage(),
                        422,
                    );
                }
            }
            $relationInput = $operation === 'relate'
                ? $this->relationInput($context, $surface, $definition, $record, $form)
                : [null, []];
            $approvalRequestId = $operation === 'action' ? $this->approvalRequestId($form) : null;
            $mutation = match ($operation) {
                'update' => $this->update($context, $surface, $definition, $record, $expected, $operationId, $form),
                'archive' => $this->business->archive(
                    $context,
                    $surface, $definition, $record, $expected, $operationId,
                ),
                'delete' => $this->business->delete(
                    $context,
                    $surface, $definition, $record, $expected, $operationId,
                ),
                'restore' => $this->business->restore(
                    $context,
                    $surface, $definition, $record, $expected, $operationId,
                ),
                'action' => $this->business->action(
                    $context,
                    $surface,
                    $definition,
                    $record,
                    $expected,
                    $this->required($form, 'action'),
                    $operationId,
                    $actionInput,
                    $approvalRequestId,
                ),
                'approval' => $this->business->requestActionApproval(
                    $context,
                    $surface,
                    $definition,
                    $record,
                    $expected,
                    $this->required($form, 'action'),
                    $operationId,
                    $actionInput,
                ),
                'relate' => $this->business->relate(
                    $context,
                    $surface,
                    $definition,
                    $record,
                    $expected,
                    $this->required($form, 'relationship'),
                    (string) $relationInput[0],
                    $operationId,
                    $this->optionalPositive($form['position'] ?? null, true),
                    $relationInput[1],
                ),
                'unrelate' => $this->business->unrelate(
                    $context,
                    $surface,
                    $definition,
                    $record,
                    $expected,
                    $this->required($form, 'relationship'),
                    $this->required($form, 'target_record_id'),
                    $operationId,
                ),
                'reorder' => $this->business->reorder(
                    $context,
                    $surface,
                    $definition,
                    $record,
                    $expected,
                    $this->required($form, 'relationship'),
                    $this->stringList($form, 'ordered_record_ids'),
                    $operationId,
                ),
                default => throw new InvalidArgumentException('A generated business form operation is invalid.'),
            };

            $redirect = match ($operation) {
                'archive' => $this->completedTarget($recordTarget, $operationId, ['archived' => '1']),
                'delete' => $this->completedTarget($target, $operationId),
                'approval' => $recordTarget . '?' . http_build_query([
                    'confirm' => 'action',
                    'action' => $this->required($form, 'action'),
                    'approval_request_id' => $mutation['approval_request_id'] ?? '',
                    'approval_requested' => '1',
                ]),
                'relate', 'unrelate', 'reorder' => $this->completedTarget(
                    $recordTarget . '/relationships/' . rawurlencode($this->required($form, 'relationship')),
                    $operationId,
                ),
                default => $this->completedTarget($recordTarget, $operationId),
            };

            return BusinessBrowserResult::redirect($redirect);
        } catch (BusinessRecordValidationFailed $exception) {
            $errors = [];
            foreach ($exception->violations as $violation) {
                $errors[$violation->field][] = $violation->message;
            }
            if ($operation === 'relate' && $record !== null) {
                $relationship = $this->required($form, 'relationship');
                $model = $this->business->relationship(
                    $context,
                    $surface,
                    $definition,
                    $record,
                    $relationship,
                );
                $model = $this->relationshipChoices(
                    $context,
                    $surface,
                    $definition,
                    $record,
                    $model,
                    [],
                    $relationship,
                    $this->nestedObject($form, 'target_values'),
                    $errors,
                    $this->nestedObject($form, 'target_structured'),
                    $this->nestedObject($form, 'target_choice_labels'),
                );
                return new BusinessBrowserResult('business-detail', [
                    ...$model,
                    'record_task' => 'relations',
                    'error_summary' => $this->translator
                        ->translate('core.business.browser.related_record_failed_validation'),
                ], status: 422);
            }
            $retained = $this->values($form);
            $model = $this->business->form($context, $surface, $definition, $record, $retained, $errors);
            $model = $this->formChoices(
                $context,
                $surface,
                $definition,
                $record,
                $model,
                [],
                $this->nestedObject($form, 'structured'),
            );
            return new BusinessBrowserResult('business-form', [
                ...$model,
                'error_summary' => $this->translator->translate('core.business.browser.record_failed_validation'),
            ], status: 422);
        } catch (BusinessRecordVersionConflict $exception) {
            if ($record === null || !in_array($operation, ['update', 'relate'], true)) {
                throw $exception;
            }

            return $this->conflicted(
                $context,
                $surface,
                $definition,
                $record,
                $operation,
                $form,
                $exception->expectedVersion,
            );
        }
    }

    /**
     * Re-render the submitted form after another writer moved the record on first.
     *
     * The re-read carries the version the record holds now, so the returned form quotes it and the
     * operator's next submission applies their retained values on top of the newer record instead of
     * failing again. Nothing was written when this ran, so choosing to leave the screen — or to reload
     * it — discards the attempt rather than the stored record.
     *
     * @param   ExecutionContext      $context          Authenticated actor and scope.
     * @param   BusinessSurface       $surface          Administrator or portal boundary.
     * @param   string                $definition       Definition UUID or handle.
     * @param   string                $record           Public record identity.
     * @param   string                $operation        `update` or `relate`; both carry typed input.
     * @param   array<string, mixed>  $form             Decoded form body retained for the re-render.
     * @param   int                   $expectedVersion  Version the refused submission was composed against.
     *
     * @return  BusinessBrowserResult  409 form or relations page carrying the submitted values.
     *
     * @since   2.0.0
     */
    private function conflicted(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $record,
        string $operation,
        array $form,
        int $expectedVersion,
    ): BusinessBrowserResult {
        $conflict = [
            'expected_version' => $expectedVersion,
            'summary' => $this->translator->translate('core.business.browser.version_conflict'),
        ];
        if ($operation === 'relate') {
            $relationship = $this->required($form, 'relationship');
            $model = $this->business->relationship($context, $surface, $definition, $record, $relationship);
            $model = $this->relationshipChoices(
                $context,
                $surface,
                $definition,
                $record,
                $model,
                [],
                $relationship,
                $this->nestedObject($form, 'target_values'),
                [],
                $this->nestedObject($form, 'target_structured'),
                $this->nestedObject($form, 'target_choice_labels'),
            );

            return new BusinessBrowserResult('business-detail', [
                ...$model,
                'record_task' => 'relations',
                'version_conflict' => $conflict,
            ], status: 409);
        }
        $model = $this->business->form($context, $surface, $definition, $record, $this->values($form));
        $model = $this->formChoices(
            $context,
            $surface,
            $definition,
            $record,
            $model,
            [],
            $this->nestedObject($form, 'structured'),
        );

        return new BusinessBrowserResult('business-form', [
            ...$model,
            'version_conflict' => $conflict,
        ], status: 409);
    }

    /**
     * Apply one form update after deriving its schema from the current record.
     *
     * @param   ExecutionContext      $context      Authenticated actor and scope.
     * @param   BusinessSurface       $surface      Administrator or portal boundary.
     * @param   string                $definition   Definition UUID or handle.
     * @param   string                $record       Public record identity.
     * @param   int                   $expected     Required current record version.
     * @param   string                $operationId  Idempotency identity.
     * @param   array<string, mixed>  $form         Decoded form body.
     *
     * @return  array<string, mixed>  Safe mutation outcome.
     *
     * @since   2.0.0
     */
    private function update(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $record,
        int $expected,
        string $operationId,
        array $form,
    ): array {
        $model = $this->business->form($context, $surface, $definition, $record);
        $fields = $this->metadataList(
            $model['fields'] ?? null,
            'A generated business form field list is unavailable.',
        );
        $values = $this->mapFormValues($form, $fields);

        return $this->business->update(
            $context,
            $surface,
            $definition,
            $record,
            $expected,
            $values,
            $operationId,
        );
    }

    /**
     * Resolve an existing target choice or map a new owned-line target form.
     *
     * @param   ExecutionContext      $context     Authenticated actor and scope.
     * @param   BusinessSurface       $surface     Administrator or portal boundary.
     * @param   string                $definition  Source definition UUID or handle.
     * @param   string                $record      Existing source identity.
     * @param   array<string, mixed>  $form        Parsed relationship form body.
     *
     * @return  array{string, array<string, mixed>}  Target identity and authorized owned values.
     *
     * @since   2.0.0
     */
    private function relationInput(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $record,
        array $form,
    ): array {
        $relationship = $this->required($form, 'relationship');
        try {
            $owned = $this->business->ownedLineForm(
                $context,
                $surface,
                $definition,
                $record,
                $relationship,
            );
        } catch (BusinessRecordDefinitionUnavailable | BusinessRecordNotFound) {
            if ($this->nestedObject($form, 'target_values') !== []) {
                throw new InvalidArgumentException('An existing-record relationship cannot submit target values.');
            }
            return [$this->required($form, 'target_record_id'), []];
        }
        $fields = $this->metadataList(
            $owned['fields'] ?? null,
            'An owned-line field list is unavailable.',
        );
        $values = $this->decodeStructuredValues(
            $this->nestedObject($form, 'target_values'),
            $this->nestedObject($form, 'target_structured'),
            $fields,
            'target_structured',
        );
        $values = $this->forms->mapSurface($values, $fields);
        $identity = $owned['identity_field'] ?? null;
        if (!is_string($identity)) {
            throw new BusinessRecordDefinitionUnavailable();
        }
        $target = ($owned['identity_generated'] ?? null) === true
            ? $this->required($form, 'target_record_id')
            : $this->required($values, $identity);

        return [$target, $values];
    }

    /**
     * Build a schema-driven custom-action confirmation with retained nested input.
     *
     * @param   ExecutionContext      $context     Authenticated actor and scope.
     * @param   BusinessSurface       $surface     Administrator or portal boundary.
     * @param   string                $definition  Definition UUID or handle.
     * @param   string                $record      Public record identity.
     * @param   array<string, mixed>  $input       GET or POST controls to retain.
     * @param   ?string               $error       Optional caller-visible validation summary.
     * @param   int                   $status      HTTP status for the confirmation page.
     *
     * @return  BusinessBrowserResult  Confirmation model using only core-owned semantic controls.
     *
     * @since   2.0.0
     */
    private function actionConfirmation(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $record,
        array $input,
        ?string $error = null,
        int $status = 200,
    ): BusinessBrowserResult {
        $action = $this->required($input, 'action');
        $model = $this->business->read($context, $surface, $definition, $record, true, true);
        $form = $this->actionSchemaForm($model, $action, $input, false);
        $metadata = $this->actionMetadata($model, $action);

        return new BusinessBrowserResult('business-confirm', [
            ...$model,
            'confirmation' => 'action',
            'action_handle' => $action,
            'action_fields' => $form->fields,
            'action_high_impact' => ($metadata['high_impact'] ?? null) === true,
            'approval_request' => ($input['approval'] ?? null) === '1',
            'approval_request_id' => $this->approvalRequestId($input),
            'approval_requested' => ($input['approval_requested'] ?? null) === '1',
            'error_summary' => $error,
        ], status: $status);
    }

    /**
     * Build one bounded bulk confirmation and its shared custom-action input editor.
     *
     * @param   ExecutionContext      $context     Authenticated actor and scope.
     * @param   BusinessSurface       $surface     Administrator or portal boundary.
     * @param   string                $definition  Source definition UUID or handle.
     * @param   array<string, mixed>  $input       GET or POST bulk and schema controls.
     * @param   string|null           $error       Optional typed-input validation summary.
     * @param   int                   $status      HTTP status for the confirmation page.
     *
     * @return  BusinessBrowserResult  Core confirmation with recursive semantic action controls.
     *
     * @since   2.0.0
     */
    private function bulkConfirmation(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        array $input,
        ?string $error = null,
        int $status = 200,
    ): BusinessBrowserResult {
        [$operation, $action] = $this->bulkOperation($input);
        $model = $this->business->bulkConfirmation(
            $context,
            $surface,
            $definition,
            $operation,
            $this->bulkItems($input),
            $action,
        );
        $fields = $action === null ? [] : $this->actionSchemaForm($model, $action, $input, false)->fields;

        return new BusinessBrowserResult('business-bulk-confirm', [
            ...$model,
            'bulk_action_fields' => $fields,
            'error_summary' => $error,
        ], status: $status);
    }

    /**
     * Decode one shared bulk-action input against its exact active command schema.
     *
     * @param   ExecutionContext          $context     Authenticated actor and scope.
     * @param   BusinessSurface           $surface     Administrator or portal boundary.
     * @param   string                    $definition  Source definition UUID or handle.
     * @param   BusinessSurfaceOperation  $operation   Validated bulk operation.
     * @param   string|null               $action      Optional action handle.
     * @param   array<string, mixed>      $input       Submitted native schema controls.
     *
     * @return  array<string, mixed>  Typed input applied identically to every selected record.
     *
     * @since   2.0.0
     */
    private function bulkActionInput(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        BusinessSurfaceOperation $operation,
        ?string $action,
        array $input,
    ): array {
        if ($operation !== BusinessSurfaceOperation::Action || $action === null) {
            return [];
        }
        $model = $this->business->bulkConfirmation(
            $context,
            $surface,
            $definition,
            $operation,
            $this->bulkItems($input),
            $action,
        );

        return $this->actionSchemaForm($model, $action, $input, true)->value;
    }

    /**
     * Decode one custom action input against the active policy-visible command schema.
     *
     * @param   ExecutionContext      $context     Authenticated actor and scope.
     * @param   BusinessSurface       $surface     Administrator or portal boundary.
     * @param   string                $definition  Definition UUID or handle.
     * @param   string                $record      Public record identity.
     * @param   string                $action      Policy-visible action handle.
     * @param   array<string, mixed>  $input       Parsed action confirmation body.
     *
     * @return  array<string, mixed>  Typed closed command input.
     *
     * @since   2.0.0
     */
    private function actionInput(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $record,
        string $action,
        array $input,
    ): array {
        $model = $this->business->read($context, $surface, $definition, $record, true, true);
        return $this->actionSchemaForm($model, $action, $input, true)->value;
    }

    /**
     * Build or submit the exact command-schema form for one policy-visible action.
     *
     * @param   array<string, mixed>  $model      Safe record detail model.
     * @param   string                $action     Requested action handle.
     * @param   array<string, mixed>  $input      Parsed confirmation controls.
     * @param   bool                  $submitted  Whether typed coercion is required.
     *
     * @return  BusinessSchemaForm  Recursive semantic controls and optional typed command object.
     *
     * @throws  BusinessRecordDefinitionUnavailable  When the action is absent from policy metadata.
     *
     * @since   2.0.0
     */
    private function actionSchemaForm(
        array $model,
        string $action,
        array $input,
        bool $submitted,
    ): BusinessSchemaForm {
        $candidate = $this->actionMetadata($model, $action);
        $contract = $candidate['custom_contract'] ?? null;
        if ($contract === null) {
            $schema = self::emptyObjectSchema();
        } else {
            $contract = $this->metadataObject(
                $contract,
                'A generated custom action contract is unavailable.',
            );
            $schema = $this->metadataObject(
                $contract['command_schema'] ?? null,
                'A generated custom action command schema is unavailable.',
            );
        }

        return BusinessSchemaForm::fromInput(
            $schema,
            $this->translator,
            'input',
            $this->nestedObject($input, 'input'),
            $this->nestedObject($input, 'schema_counts'),
            $this->nestedObject($input, 'schema_presence'),
            $submitted,
        );
    }

    /**
     * Resolve one exact policy-visible action metadata item.
     *
     * @param   array<string, mixed>  $model   Safe record detail model.
     * @param   string                $action  Requested action handle.
     *
     * @return  array<string, mixed>  Exact action metadata.
     *
     * @throws  BusinessRecordDefinitionUnavailable  When the action is not currently visible.
     *
     * @since   2.0.0
     */
    private function actionMetadata(array $model, string $action): array
    {
        $definition = $this->metadataObject(
            $model['definition'] ?? null,
            'A generated action definition is unavailable.',
        );
        $actions = $this->metadataList(
            $definition['actions'] ?? null,
            'A generated action list is unavailable.',
        );
        foreach ($actions as $candidate) {
            if (($candidate['handle'] ?? null) === $action) {
                return $candidate;
            }
        }

        throw new BusinessRecordDefinitionUnavailable();
    }

    /**
     * Read one optional maker-checker request UUID from an action confirmation.
     *
     * @param   array<string, mixed>  $input  GET or POST confirmation controls.
     *
     * @return  string|null  Valid request UUID, or null when no approved request is being consumed.
     *
     * @since   2.0.0
     */
    private function approvalRequestId(array $input): ?string
    {
        $request = $this->optionalString($input, 'approval_request_id');
        if ($request !== null && !Uuid::isValid($request)) {
            throw new InvalidArgumentException('An approval request identity must be a valid UUID.');
        }

        return $request;
    }

    /**
     * Return the closed empty command schema used by ordinary declarative actions.
     *
     * @return  array<string, mixed>  Empty exact object schema.
     *
     * @since   2.0.0
     */
    private static function emptyObjectSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [],
            'required' => [],
        ];
    }

    /**
     * Attach fixed selector links and only the already disclosed or returned reference option.
     *
     * Choice catalogues are intentionally not preloaded while iterating definition fields. The current
     * update value came from the policy-filtered form model; a selector return is bounded and its target is
     * revalidated at the application mutation boundary. Every actual browse remains on a fixed selector route.
     *
     * @param   ExecutionContext       $context     Authenticated actor and scope.
     * @param   BusinessSurface        $surface     Administrator or portal boundary.
     * @param   string                 $definition  Source definition UUID or handle.
     * @param   ?string                $record      Source record for update choices.
     * @param   array<string, mixed>   $model       Generated create or update form model.
     * @param   array<string, mixed>   $query       Optional selector return values.
     * @param   ?array<string, mixed>  $structured  Optional retained graphical structure controls.
     *
     * @return  array<string, mixed>  Form model carrying closed selector options.
     *
     * @since   2.0.0
     */
    private function formChoices(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        ?string $record,
        array $model,
        array $query,
        ?array $structured = null,
    ): array {
        $operation = $record === null ? BusinessSurfaceOperation::Create : BusinessSurfaceOperation::Update;
        $fields = $this->metadataList(
            $model['fields'] ?? null,
            'A generated business form field list is invalid.',
        );
        $selected = $this->selectedChoice($query);
        foreach ($fields as &$field) {
            $widget = $field['widget'] ?? null;
            $handle = $field['handle'] ?? null;
            if (!is_string($handle) || !in_array($widget, ['entity_reference', 'media_reference'], true)) {
                continue;
            }
            $field['options'] = [];
            $current = $field['input_value'] ?? null;
            if (is_string($current) && $current !== '') {
                $field['options'][] = [
                    'value' => $current,
                    'label' => $this->translator->translate('core.business.browser.current_selection'),
                ];
            }
            $field['choice_kind'] = $widget === 'entity_reference' ? 'relations' : 'media';
            $field['choice_path'] = $field['choice_kind'] . '/' . $handle;
            $field['choice_operation'] = $operation->value;
            if (
                $selected !== null
                && $selected['relationship'] === null
                && $selected['handle'] === $handle
            ) {
                $field['options'] = [[
                    'value' => $selected['value'],
                    'label' => $this->translator->translate('core.business.browser.selected_option'),
                ]];
                $field['input_value'] = $selected['value'];
            }
        }
        unset($field);
        $model['fields'] = $fields;

        return $this->structuredFields($model, $structured);
    }

    /**
     * Attach recursive key/value and row editors to open structured fields.
     *
     * @param   array<string, mixed>   $model       Generated create or update model.
     * @param   ?array<string, mixed>  $structured  Retained graphical controls, null on first render.
     *
     * @return  array<string, mixed>  Form model with bounded semantic structured editors.
     *
     * @since   2.0.0
     */
    private function structuredFields(array $model, ?array $structured): array
    {
        $fields = $this->metadataList(
            $model['fields'] ?? null,
            'A generated business structured field list is invalid.',
        );
        $controls = $structured ?? [];
        $allowed = [];
        foreach ($fields as &$field) {
            $handle = $field['handle'] ?? null;
            $widget = $field['widget'] ?? null;
            if (
                !is_string($handle)
                || !is_bool($field['editable'] ?? null)
                || !$field['editable']
                || !in_array($widget, ['json', 'collection'], true)
            ) {
                continue;
            }
            if (($field['type'] ?? null) === 'core.ordered_lines') {
                $field['structured_managed_by_relationship'] = true;
                continue;
            }
            $allowed[$handle] = true;
            $input = array_key_exists($handle, $controls) ? $controls[$handle] : null;
            $form = BusinessStructuredFieldForm::fromInput(
                $this->translator,
                $widget === 'collection' ? 'array' : 'object',
                'structured[' . $handle . ']',
                $input,
                $field['input_value'] ?? null,
                $this->structuredMaximum($field, $widget),
                false,
            );
            $field['structured'] = $form->model;
        }
        unset($field);
        if ($structured !== null && array_diff(array_keys($controls), array_keys($allowed)) !== []) {
            throw new InvalidArgumentException('A generated business form contains an unavailable structure.');
        }
        $model['fields'] = $fields;

        return $model;
    }

    /**
     * Attach source-bound selectors and graphical structured controls to an owned-target form.
     *
     * @param array<string, mixed> $owned Safe owned-line form returned by the shared facade.
     * @param string $relationship Source relationship handle.
     * @param   array{handle: string, value: string, label: string, relationship: ?string}|null  $selected
     *          Optional choice returned from the fixed owned-line selector.
     * @param array<string, mixed> $structured Retained graphical structured controls.
     * @param array<string, mixed> $labels Retained disclosed labels by reference field handle.
     *
     * @return  array<string, mixed>  Owned-line form ready for core Twig rendering.
     *
     * @since   2.0.0
     */
    private function ownedLineFields(
        array $owned,
        string $relationship,
        ?array $selected,
        array $structured,
        array $labels,
    ): array {
        $fields = $this->metadataList(
            $owned['fields'] ?? null,
            'An owned-line form field list is unavailable.',
        );
        $allowedStructured = [];
        $allowedLabels = [];
        foreach ($fields as &$field) {
            $handle = $field['handle'] ?? null;
            $widget = $field['widget'] ?? null;
            if (!is_string($handle)) {
                throw new BusinessRecordDefinitionUnavailable();
            }
            if (in_array($widget, ['entity_reference', 'media_reference'], true)) {
                $allowedLabels[$handle] = true;
                $kind = $widget === 'entity_reference' ? 'relations' : 'media';
                $field['options'] = [];
                $field['choice_kind'] = $kind;
                $field['choice_path'] = 'owned-lines/' . $relationship . '/' . $kind . '/' . $handle;
                $field['choice_operation'] = 'relation';
                if (
                    $selected !== null
                    && $selected['relationship'] === $relationship
                    && $selected['handle'] === $handle
                ) {
                    $field['options'][] = [
                        'value' => $selected['value'],
                        'label' => $selected['label'],
                    ];
                    $field['input_value'] = $selected['value'];
                    $field['choice_selected_label'] = $selected['label'];
                } elseif (is_string($field['input_value'] ?? null) && $field['input_value'] !== '') {
                    $label = $labels[$handle] ?? null;
                    if (!is_string($label) || $label === '' || strlen($label) > 120) {
                        throw new InvalidArgumentException('An owned-line reference label is invalid.');
                    }
                    $field['options'][] = ['value' => $field['input_value'], 'label' => $label];
                    $field['choice_selected_label'] = $label;
                }
                $field['choice_label_name'] = 'target_choice_labels[' . $handle . ']';
            }
            if (
                !is_bool($field['editable'] ?? null)
                || !$field['editable']
                || !in_array($widget, ['json', 'collection'], true)
            ) {
                continue;
            }
            if (($field['type'] ?? null) === 'core.ordered_lines') {
                $field['structured_managed_by_relationship'] = true;
                continue;
            }
            $allowedStructured[$handle] = true;
            $form = BusinessStructuredFieldForm::fromInput(
                $this->translator,
                $widget === 'collection' ? 'array' : 'object',
                'target_structured[' . $handle . ']',
                $structured[$handle] ?? null,
                $field['input_value'] ?? null,
                $this->structuredMaximum($field, $widget),
                false,
            );
            $field['structured'] = $form->model;
        }
        unset($field);
        if (array_diff(array_keys($structured), array_keys($allowedStructured)) !== []) {
            throw new InvalidArgumentException('An owned-line form contains an unavailable structure.');
        }
        if (array_diff(array_keys($labels), array_keys($allowedLabels)) !== []) {
            throw new InvalidArgumentException('An owned-line form contains an unavailable reference label.');
        }
        $owned['fields'] = $fields;

        return $owned;
    }

    /**
     * Decode graphical structured controls and map every field through the authorized presentation.
     *
     * @param   array<string, mixed>        $form    Parsed browser body.
     * @param   list<array<string, mixed>>  $fields  Server-produced exact form field models.
     *
     * @return  array<string, mixed>  Typed values ready for the shared record application service.
     *
     * @since   2.0.0
     */
    private function mapFormValues(array $form, array $fields): array
    {
        $values = $this->values($form);
        $values = $this->decodeStructuredValues(
            $values,
            $this->nestedObject($form, 'structured'),
            $fields,
            'structured',
        );

        return $this->forms->mapSurface($values, $fields);
    }

    /**
     * Decode every graphical structured value against its exact semantic field model.
     *
     * @param   array<string, mixed>        $values    Native scalar and composite values.
     * @param   array<string, mixed>        $controls  Recursive structure controls by field handle.
     * @param   list<array<string, mixed>>  $fields    Server-produced exact form field models.
     * @param   string                      $prefix    `structured` or `target_structured` input root.
     *
     * @return  array<string, mixed>  Values with structured members decoded to typed arrays.
     *
     * @since   2.0.0
     */
    private function decodeStructuredValues(
        array $values,
        array $controls,
        array $fields,
        string $prefix,
    ): array {
        if (!in_array($prefix, ['structured', 'target_structured'], true)) {
            throw new InvalidArgumentException('A generated structured field prefix is invalid.');
        }
        $allowed = [];
        foreach ($fields as $field) {
            $handle = $field['handle'] ?? null;
            $widget = $field['widget'] ?? null;
            if (
                !is_string($handle)
                || !is_bool($field['editable'] ?? null)
                || !$field['editable']
                || !in_array($widget, ['json', 'collection'], true)
                || ($field['type'] ?? null) === 'core.ordered_lines'
            ) {
                continue;
            }
            $allowed[$handle] = true;
            if (!array_key_exists($handle, $controls)) {
                throw new InvalidArgumentException('A generated structured field is missing its graphical controls.');
            }
            $structured = BusinessStructuredFieldForm::fromInput(
                $this->translator,
                $widget === 'collection' ? 'array' : 'object',
                $prefix . '[' . $handle . ']',
                $controls[$handle],
                $field['input_value'] ?? null,
                $this->structuredMaximum($field, $widget),
                true,
            );
            $values[$handle] = $structured->value;
        }
        if (array_diff(array_keys($controls), array_keys($allowed)) !== []) {
            throw new InvalidArgumentException('A generated business form contains an unavailable structure.');
        }

        return $values;
    }

    /**
     * Read one signed root member bound from the policy-filtered field schema.
     *
     * @param   array<string, mixed>  $field   Server-produced field model.
     * @param   string                $widget  `json` or `collection`.
     *
     * @return  int  Root member maximum accepted by the graphical editor.
     *
     * @since   2.0.0
     */
    private function structuredMaximum(array $field, string $widget): int
    {
        $schema = $field['schema'] ?? null;
        if (!is_array($schema) || array_is_list($schema)) {
            throw new InvalidArgumentException('A generated structured field schema is unavailable.');
        }
        $member = $widget === 'collection' ? 'maxItems' : 'maxProperties';
        $default = $widget === 'collection' ? 1000 : 128;
        $maximum = $schema[$member] ?? $default;
        if (!is_int($maximum) || $maximum < 0 || $maximum > 1000) {
            throw new InvalidArgumentException('A generated structured field schema has an invalid member bound.');
        }
        return $maximum;
    }

    /**
     * Attach one focused relationship editor without preloading definition-wide catalogues.
     *
     * The ordinary detail model is presentation-only. A fixed relationship route passes its exact handle,
     * at which point one owned-line form may be loaded; ordinary target browsing remains on its fixed choice
     * route and contributes only the returned selected option to this native POST form.
     *
     * @param   ExecutionContext             $context     Authenticated actor and scope.
     * @param   BusinessSurface              $surface     Administrator or portal boundary.
     * @param   string                       $definition  Source definition UUID or handle.
     * @param   string                       $record      Source record identity.
     * @param   array<string, mixed>         $model       Generated record detail model.
     * @param   array<string, mixed>         $query       Optional selector return values.
     * @param   ?string                      $ownedLine   Owned relationship retaining a failed submission.
     * @param   array<string, mixed>         $retained    Retained owned-target values.
     * @param   array<string, list<string>>  $errors      Owned-target validation errors.
     * @param   array<string, mixed>         $structured  Retained owned-target structured controls.
     * @param   array<string, mixed>         $labels      Retained disclosed reference labels.
     *
     * @return  array<string, mixed>  Detail model carrying selector choices per relationship.
     *
     * @since   2.0.0
     */
    private function relationshipChoices(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $record,
        array $model,
        array $query,
        ?string $ownedLine = null,
        array $retained = [],
        array $errors = [],
        array $structured = [],
        array $labels = [],
    ): array {
        $definitionMetadata = $this->metadataObject(
            $model['definition'] ?? null,
            'A generated relationship definition is invalid.',
        );
        $relationships = $this->metadataList(
            $definitionMetadata['relationships'] ?? null,
            'A generated relationship metadata list is invalid.',
        );
        $availableOperations = $this->objectValue(
            $model['available_operations'] ?? [],
            'A generated relationship operation map is invalid.',
        );
        $selected = $this->selectedChoice($query);
        $focus = $ownedLine ?? ($model['relationship_focus'] ?? null);
        if ($focus !== null && !is_string($focus)) {
            throw new InvalidArgumentException('A generated relationship focus is invalid.');
        }
        foreach ($relationships as &$relationship) {
            $handle = $relationship['handle'] ?? null;
            if (!is_string($handle)) {
                throw new InvalidArgumentException('A generated relationship handle is invalid.');
            }
            $relationship['owned_line_form'] = null;
            $relationship['choices'] = [];
            $relationship['choice_available'] = false;
            if ($focus !== $handle) {
                continue;
            }
            if (!isset($availableOperations['relation'])) {
                continue;
            }
            if (($relationship['kind'] ?? null) === 'owned_line_collection') {
                try {
                    $ownedForm = $this->business->ownedLineForm(
                        $context,
                        $surface,
                        $definition,
                        $record,
                        $handle,
                        $ownedLine === $handle ? $retained : [],
                        $ownedLine === $handle ? $errors : [],
                    );
                    $relationship['owned_line_form'] = $this->ownedLineFields(
                        $ownedForm,
                        $handle,
                        $selected,
                        $ownedLine === $handle ? $structured : [],
                        $ownedLine === $handle ? $labels : [],
                    );
                } catch (BusinessRecordDefinitionUnavailable | InvalidArgumentException) {
                    $relationship['owned_line_form'] = null;
                }
                continue;
            }
            $relationship['choice_available'] = true;
            if (
                $selected !== null
                && $selected['relationship'] === null
                && $selected['handle'] === $handle
            ) {
                $relationship['choices'][] = [
                    'value' => $selected['value'],
                    'label' => $this->translator->translate('core.business.browser.selected_record'),
                ];
                $relationship['selected_choice'] = $selected['value'];
            }
        }
        unset($relationship);
        $definitionMetadata['relationships'] = $relationships;
        $model['definition'] = $definitionMetadata;

        return $model;
    }

    /**
     * Read one safe selector return value from a generated choice link.
     *
     * @param   array<string, mixed>  $query  Decoded form-page query.
     *
     * @return  array{handle: string, value: string, label: string, relationship: ?string}|null
     *          Selected option or null.
     *
     * @since   2.0.0
     */
    private function selectedChoice(array $query): ?array
    {
        $handle = $query['choice_handle'] ?? null;
        $value = $query['choice_value'] ?? null;
        $label = $query['choice_label'] ?? null;
        $relationship = $query['choice_relationship'] ?? null;
        if ($value === null && $label === null) {
            return null;
        }
        if (
            !is_string($handle)
            || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $handle) !== 1
            || !is_string($value)
            || $value === ''
            || strlen($value) > 191
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            || !is_string($label)
            || $label === ''
            || strlen($label) > 120
            || (
                $relationship !== null
                && (
                    !is_string($relationship)
                    || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $relationship) !== 1
                )
            )
        ) {
            throw new InvalidArgumentException('A generated business selected choice is malformed.');
        }
        return [
            'handle' => $handle,
            'value' => $value,
            'label' => $label,
            'relationship' => $relationship,
        ];
    }

    /**
     * Build the form or relation return target for one fixed selector route.
     *
     * @param   string                    $basePath    Surface business root.
     * @param   string                    $definition  Source definition identifier.
     * @param   ?string                   $record      Optional source record identity.
     * @param   string                    $handle      Field or relationship handle.
     * @param   BusinessSurfaceOperation  $operation   Create, update or relation context.
     *
     * @return  string  Same-origin path with a closed selector handle query.
     *
     * @since   2.0.0
     */
    private function choiceReturnPath(
        string $basePath,
        string $definition,
        ?string $record,
        string $handle,
        BusinessSurfaceOperation $operation,
    ): string {
        $target = $basePath . '/' . rawurlencode($definition);
        if ($record !== null) {
            $target .= '/' . rawurlencode($record);
        }
        $query = match ($operation) {
            BusinessSurfaceOperation::Create => ['new' => '1', 'choice_handle' => $handle],
            BusinessSurfaceOperation::Update => ['edit' => '1', 'choice_handle' => $handle],
            BusinessSurfaceOperation::Relation => ['choice_handle' => $handle],
            default => throw new InvalidArgumentException('A generated selector return context is invalid.'),
        };
        if ($operation === BusinessSurfaceOperation::Relation) {
            if ($record === null) {
                throw new InvalidArgumentException('A relationship selector return requires a source record.');
            }
            $target .= '/relationships/' . rawurlencode($handle);
        }
        return $target . '?' . http_build_query($query);
    }

    /**
     * Read one bounded media-name search control.
     *
     * @param   mixed  $value  Candidate search text.
     *
     * @return  string  Trimmed text, empty for no search.
     *
     * @since   2.0.0
     */
    private function selectorSearch(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 200) {
            throw new InvalidArgumentException('A generated media selector search is invalid or unbounded.');
        }
        return trim($value);
    }

    /**
     * Build a saved-operation redirect with a stable caller-owned status reference.
     *
     * @param   string                 $target       Same-origin destination without a query string.
     * @param   string                 $operationId  Completed idempotency identity.
     * @param   array<string, string>  $query        Additional lifecycle flags.
     *
     * @return  string  Redirect target carrying success and operation-status state.
     *
     * @since   2.0.0
     */
    private function completedTarget(string $target, string $operationId, array $query = []): string
    {
        return $target . '?' . http_build_query([
            ...$query,
            'saved' => '1',
            'completed_operation' => $operationId,
        ]);
    }

    /**
     * Read one completed operation identity only from an explicit saved redirect.
     *
     * @param   array<string, mixed>  $query  Decoded query string.
     *
     * @return  string|null  Valid status identity, or null for arbitrary query input.
     *
     * @since   2.0.0
     */
    private function completedOperation(array $query): ?string
    {
        if (($query['saved'] ?? null) !== '1' || !is_string($query['completed_operation'] ?? null)) {
            return null;
        }
        try {
            return IdempotencyKey::fromString($query['completed_operation'])->value();
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Read a bounded completed bulk count only from an explicit saved redirect.
     *
     * @param   array<string, mixed>  $query  Decoded query string.
     *
     * @return  int|null  Count between 1 and 50, or null when absent or malformed.
     *
     * @since   2.0.0
     */
    private function completedBulkCount(array $query): ?int
    {
        if (($query['saved'] ?? null) !== '1' || !isset($query['bulk_count'])) {
            return null;
        }
        try {
            return $this->positive($query['bulk_count'], 50);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Decode the closed bulk-operation selector used by native confirmation forms.
     *
     * @param   array<string, mixed>  $input  Query or form input.
     *
     * @return  array{BusinessSurfaceOperation, ?string}  Operation and optional action handle.
     *
     * @since   2.0.0
     */
    private function bulkOperation(array $input): array
    {
        $value = $this->required($input, 'bulk_operation');
        if ($value === 'archive') {
            return [BusinessSurfaceOperation::Archive, null];
        }
        if ($value === 'restore') {
            return [BusinessSurfaceOperation::Restore, null];
        }
        if (str_starts_with($value, 'action:')) {
            $action = substr($value, 7);
            if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $action) === 1) {
                return [BusinessSurfaceOperation::Action, $action];
            }
        }

        throw new InvalidArgumentException('A generated business bulk operation is invalid.');
    }

    /**
     * Decode at most 50 selected record/version tokens emitted by the generated list.
     *
     * Each checkbox carries one compact JSON object rather than placing a caller-facing record identity
     * in an HTML form key, so PHP form parsing cannot reinterpret identity punctuation as nesting syntax.
     *
     * @param   array<string, mixed>  $input  Query or form input.
     *
     * @return  list<array{record_id: string, expected_version: int}>  Decoded selection.
     *
     * @since   2.0.0
     */
    private function bulkItems(array $input): array
    {
        $encoded = $input['bulk_records'] ?? null;
        if (!is_array($encoded) || !array_is_list($encoded) || $encoded === [] || count($encoded) > 50) {
            throw new InvalidArgumentException('A generated business bulk selection must contain 1 to 50 records.');
        }
        $items = [];
        foreach ($encoded as $item) {
            if (!is_string($item) || strlen($item) > 1024) {
                throw new InvalidArgumentException('A generated business bulk selection token is invalid.');
            }
            try {
                $decoded = json_decode($item, true, 4, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException(
                    'A generated business bulk selection token is invalid.',
                    0,
                    $exception,
                );
            }
            if (!is_array($decoded) || array_is_list($decoded)) {
                throw new InvalidArgumentException('A generated business bulk selection token is malformed.');
            }
            if (
                array_diff(array_keys($decoded), ['record_id', 'expected_version']) !== []
                || !array_key_exists('record_id', $decoded)
                || !array_key_exists('expected_version', $decoded)
            ) {
                throw new InvalidArgumentException('A generated business bulk selection token is malformed.');
            }
            $recordId = $decoded['record_id'];
            $expectedVersion = $decoded['expected_version'];
            if (
                !is_string($recordId)
                || $recordId === ''
                || strlen($recordId) > 191
                || !is_int($expectedVersion)
                || $expectedVersion < 1
                || $expectedVersion > 2_147_483_647
            ) {
                throw new InvalidArgumentException('A generated business bulk selection token is malformed.');
            }
            $items[] = ['record_id' => $recordId, 'expected_version' => $expectedVersion];
        }

        return $items;
    }

    /**
     * Read a values object exclusively from native nested form input.
     *
     * @param   array<string, mixed>  $form  Decoded form body.
     *
     * @return  array<string, mixed>  Parsed values object.
     *
     * @since   2.0.0
     */
    private function values(array $form): array
    {
        $values = $form['values'] ?? [];
        if (!is_array($values) || ($values !== [] && array_is_list($values))) {
            throw new InvalidArgumentException('Generated business form values must be a nested object.');
        }
        return $this->objectValue($values, 'Generated business form values must be a nested object.');
    }

    /**
     * Read a nested native object without accepting a JSON text fallback.
     *
     * @param   array<string, mixed>  $input  Parsed browser controls.
     * @param   string                $key    Nested object member.
     *
     * @return  array<string, mixed>  Parsed object, empty when absent.
     *
     * @throws  InvalidArgumentException  When a present member is list-shaped or non-array.
     *
     * @since   2.0.0
     */
    private function nestedObject(array $input, string $key): array
    {
        $value = $input[$key] ?? [];
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException('A generated business nested object is malformed.');
        }
        return $this->objectValue($value, 'A generated business nested object is malformed.');
    }

    /**
     * Read a required non-empty bounded string member.
     *
     * @param   array<string, mixed>  $form  Decoded form body.
     * @param   string                $key   Form member name.
     *
     * @return  string  Trimmed value.
     *
     * @since   2.0.0
     */
    private function required(array $form, string $key): string
    {
        $value = $form[$key] ?? null;
        if (!is_string($value) || trim($value) === '' || strlen($value) > 191) {
            throw new InvalidArgumentException('Generated business form property ' . $key . ' is required.');
        }

        return trim($value);
    }

    /**
     * Read an optional bounded string member.
     *
     * @param   array<string, mixed>  $form  Decoded form body.
     * @param   string                $key   Form member name.
     *
     * @return  string|null  Trimmed value, or null when absent.
     *
     * @since   2.0.0
     */
    private function optionalString(array $form, string $key): ?string
    {
        $value = $form[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        return $this->required($form, $key);
    }

    /**
     * Parse a positive integer within an optional ceiling.
     *
     * @param   mixed  $value    Parsed form or query value.
     * @param   int    $maximum  Inclusive ceiling.
     *
     * @return  int  Parsed positive integer.
     *
     * @since   2.0.0
     */
    private function positive(mixed $value, int $maximum = 2_147_483_647): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/D', $value) === 1) {
            $integer = (int) $value;
        } else {
            throw new InvalidArgumentException('A generated business positive integer is invalid.');
        }
        if ($integer < 1 || $integer > $maximum) {
            throw new InvalidArgumentException('A generated business positive integer is outside its bound.');
        }

        return $integer;
    }

    /**
     * Parse an optional positive integer, optionally permitting zero.
     *
     * @param   mixed  $value      Parsed form or query value.
     * @param   bool   $allowZero  Whether zero is accepted.
     *
     * @return  int|null  Parsed integer, or null when absent.
     *
     * @since   2.0.0
     */
    private function optionalPositive(mixed $value, bool $allowZero = false): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($allowZero && ($value === 0 || $value === '0')) {
            return 0;
        }

        return $this->positive($value, 1_000_000);
    }

    /**
     * Read a bounded string list from an array or comma-separated fallback.
     *
     * @param   array<string, mixed>  $form  Decoded form body.
     * @param   string                $key   Form member name.
     *
     * @return  list<string>  At most one thousand unique identities.
     *
     * @since   2.0.0
     */
    private function stringList(array $form, string $key): array
    {
        $value = $form[$key] ?? null;
        $values = [];
        if (is_array($value) && array_is_list($value)) {
            foreach ($value as $item) {
                if (!is_string($item)) {
                    throw new InvalidArgumentException(
                        'A generated business identity list contains an invalid value.',
                    );
                }
                $values[] = $item;
            }
        } elseif (is_string($value)) {
            foreach (explode(',', $value) as $item) {
                $item = trim($item);
                if ($item !== '') {
                    $values[] = $item;
                }
            }
        }
        if ($values === [] || count($values) > 1000 || count($values) !== count(array_unique($values))) {
            throw new InvalidArgumentException('A generated business identity list is invalid or unbounded.');
        }
        foreach ($values as $item) {
            if ($item === '' || strlen($item) > 191) {
                throw new InvalidArgumentException('A generated business identity list contains an invalid value.');
            }
        }

        return $values;
    }

    /**
     * Narrow one trusted facade metadata object or fail closed.
     *
     * @param   mixed   $value    Candidate metadata object.
     * @param   string  $message  Safe failure description.
     *
     * @return  array<string, mixed>  String-keyed metadata.
     *
     * @throws  BusinessRecordDefinitionUnavailable  When the facade result is malformed.
     *
     * @since   2.0.0
     */
    private function metadataObject(mixed $value, string $message): array
    {
        try {
            return $this->objectValue($value, $message);
        } catch (InvalidArgumentException) {
            throw new BusinessRecordDefinitionUnavailable();
        }
    }

    /**
     * Narrow one trusted facade metadata list or fail closed.
     *
     * @param   mixed   $value    Candidate metadata list.
     * @param   string  $message  Safe failure description.
     *
     * @return  list<array<string, mixed>>  Validated metadata objects.
     *
     * @throws  BusinessRecordDefinitionUnavailable  When the facade result is malformed.
     *
     * @since   2.0.0
     */
    private function metadataList(mixed $value, string $message): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new BusinessRecordDefinitionUnavailable();
        }
        $items = [];
        foreach ($value as $item) {
            $items[] = $this->metadataObject($item, $message);
        }

        return $items;
    }

    /**
     * Validate one decoded browser object without coercing numeric keys.
     *
     * @param   mixed   $value    Candidate object.
     * @param   string  $message  Safe validation failure.
     *
     * @return  array<string, mixed>  String-keyed object.
     *
     * @since   2.0.0
     */
    private function objectValue(mixed $value, string $message): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException($message);
        }
        $object = [];
        foreach ($value as $key => $member) {
            if (!is_string($key)) {
                throw new InvalidArgumentException($message);
            }
            $object[$key] = $member;
        }

        return $object;
    }
}
