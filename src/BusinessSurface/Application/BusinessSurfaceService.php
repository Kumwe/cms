<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Application;

use InvalidArgumentException;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Application\Automation\IdempotencyKey;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\BusinessDefinition\Application\FieldTypeDefinitionResolver;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\App\BusinessDefinition\Domain\IdentityStrategy;
use Kumwe\App\BusinessDefinition\Domain\ScopeMode;
use Kumwe\App\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\App\BusinessRecord\Application\BusinessRecordRelationView;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\ArchiveRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\DeleteRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\ExecuteRecordActionCommand;
use Kumwe\App\BusinessRecord\Application\Command\RelateRecordsCommand;
use Kumwe\App\BusinessRecord\Application\Command\ReorderRecordLinesCommand;
use Kumwe\App\BusinessRecord\Application\Command\RestoreRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\UnrelateRecordsCommand;
use Kumwe\App\BusinessRecord\Application\Command\UpdateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordNotFound;
use Kumwe\App\BusinessRecord\Application\Query\BrowseRecordsQuery;
use Kumwe\App\BusinessRecord\Application\Query\BrowseOwnedLineFieldChoicesQuery;
use Kumwe\App\BusinessRecord\Application\Query\BrowseRelatedRecordsQuery;
use Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordQueryPurpose;
use Kumwe\App\BusinessRecord\Application\Query\OwnedLineFormQuery;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\App\BusinessRecord\Application\Query\RecordHistoryQuery;
use Kumwe\App\BusinessRecord\Application\RecordExpressionValues;
use Kumwe\App\BusinessRecord\Application\RelatedRecordBrowseResult;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordProjection;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessSurfaceDispatcher;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessActionCommand;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessSchema;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessViewQuery;
use Kumwe\Localization\Application\ActiveLocale;
use Kumwe\App\Media\Application\MediaAsset;
use Kumwe\App\Media\Application\MediaService;
use Ramsey\Uuid\Uuid;
use Kumwe\App\Extension\Runtime\ExtensionExecutionContext;

/**
 * Shared generated-business use-case facade consumed by every delivery adapter.
 *
 * This service is the parity seam: it verifies surface exposure through `BusinessSurfaceCatalog`, derives
 * organization scope only from the authenticated context, maps bounded query documents into the canonical
 * AST, and delegates every read or mutation to `BusinessRecordService`. Browser field models are produced
 * through the application-owned `FieldModelPresenter` contract after policy and conditional rules are
 * known. Adapters never
 * receive a definition repository, policy evaluator, transaction, persistence connection, or raw record.
 *
 * @since  2.0.0
 */
final readonly class BusinessSurfaceService implements BusinessHistoryUseCase, BusinessSurfaceUseCases
{
    /**
     * Configure the shared generated-business facade.
     *
     * @param  BusinessSurfaceCatalog            $catalog         Policy-filtered metadata source.
     * @param  BusinessRecordService             $records         Canonical transactional record boundary.
     * @param  BusinessRecordDefinitionResolver  $definitions     Trusted installed definition resolver.
     * @param  FieldTypeDefinitionResolver       $fieldTypes      Immutable field-type metadata resolver.
     * @param  BusinessRecordQueryFactory        $queries         Shared bounded query grammar compiler.
     * @param  BusinessRecordProjector           $projector       Shared omission-safe result projector.
     * @param  CustomBusinessSurfaceDispatcher   $customBusiness  Signed custom view and action dispatcher.
     * @param  CustomBusinessActionExecutor      $customActions   Durable guarded custom-action executor.
     * @param  FieldModelPresenter               $presentations   Application-owned rendering contract for
     *         generated field models.
     * @param  MediaService                      $media           Authorized bounded media-choice service.
     * @param  TransactionManager                $transactions    Atomic boundary for bounded bulk mutations.
     * @param  ActiveLocale                      $active          Locale for user-facing definition labels.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessSurfaceCatalog $catalog,
        private BusinessRecordService $records,
        private BusinessRecordDefinitionResolver $definitions,
        private FieldTypeDefinitionResolver $fieldTypes,
        private BusinessRecordQueryFactory $queries,
        private BusinessRecordProjector $projector,
        private CustomBusinessSurfaceDispatcher $customBusiness,
        private CustomBusinessActionExecutor $customActions,
        private FieldModelPresenter $presentations,
        private MediaService $media,
        private TransactionManager $transactions,
        private ActiveLocale $active,
    ) {
    }

    /**
     * Discover entity workspaces visible for a surface.
     *
     * @param   ExecutionContext  $context  Authenticated actor, site and membership.
     * @param   BusinessSurface   $surface  Exact delivery boundary.
     *
     * @return  list<array<string, mixed>>  Policy-filtered workspace metadata.
     *
     * @since   2.0.0
     */
    public function discover(ExecutionContext $context, BusinessSurface $surface): array
    {
        return $this->catalog->definitions($context, $surface, BusinessSurfaceOperation::Discover);
    }

    /**
     * Execute one definition-declared custom view through its signed typed application contract.
     *
     * The declaration's kind selects the same catalog operation used by generated list, detail, form,
     * history, and relation screens. The policy-filtered metadata must still contain the requested view;
     * only then is a bounded record query and parameter object handed to the owner-aware dispatcher.
     *
     * @param   ExecutionContext      $context     Authenticated actor.
     * @param   BusinessSurface       $surface     Exact delivery boundary.
     * @param   string                $definition  Definition UUID or handle.
     * @param   string                $view        Custom view handle declared inside the definition.
     * @param   array<string, mixed>  $query       Shared bounded record-query document.
     * @param   array<string, mixed>  $parameters  Contract-specific view parameters.
     * @param   ?string               $record      Public record identity for detail-like views.
     *
     * @return  array<string, mixed>  Safe definition and view metadata plus contract-validated result data.
     *
     * @throws  BusinessRecordDefinitionUnavailable  When the view is absent, denied, unexposed, or inactive.
     *
     * @since   2.0.0
     */
    public function customView(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $view,
        array $query = [],
        array $parameters = [],
        ?string $record = null,
    ): array {
        $resolved = $this->definitions->forCreate($context, $definition);
        $operation = $this->customBusiness->viewOperation($resolved->definition, $view, $record);
        $model = $this->customViewMetadataFor(
            $context,
            $surface,
            $definition,
            $view,
            $record,
            $resolved->definition,
            $operation,
        );
        $specification = $this->customViewSpecification(
            $this->queries->create($query),
            $model['definition'],
            $resolved->definition,
            $view,
        );
        $result = $this->customBusiness->view($resolved->definition, new CustomBusinessViewQuery(
            ExtensionExecutionContext::of($context),
            $definition,
            $view,
            $specification,
            $parameters,
            $this->organization($context, $model['definition']),
            $record,
        ));

        return [
            ...$model,
            'data' => $result->data,
        ];
    }

    /**
     * Describe one executable custom view and its closed parameter schema before invocation.
     *
     * This is the browser form seam: it applies the view-kind operation, surface exposure, portal opt-in,
     * record policy and active owner-aware contract checks before returning safe declarative metadata.
     * Executable handler and schema references never leave the application boundary.
     *
     * @param   ExecutionContext  $context     Authenticated actor.
     * @param   BusinessSurface   $surface     Exact delivery boundary.
     * @param   string            $definition  Definition UUID or handle.
     * @param   string            $view        Custom view handle declared inside the definition.
     * @param   ?string           $record      Public record identity for detail-like views.
     *
     * @return  array{
     *              definition: array<string, mixed>,
     *              available_operations: array<string, true>,
     *              view: array<string, mixed>,
     *              parameter_schema: array<string, mixed>
     *          }  Safe definition, view, operations and closed parameter schema.
     *
     * @throws  BusinessRecordDefinitionUnavailable  When the view is absent, denied, unexposed, or inactive.
     *
     * @since   2.0.0
     */
    public function customViewMetadata(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $view,
        ?string $record = null,
    ): array {
        $entity = $this->definitions->forCreate($context, $definition)->definition;

        return $this->customViewMetadataFor(
            $context,
            $surface,
            $definition,
            $view,
            $record,
            $entity,
            $this->customBusiness->viewOperation($entity, $view, $record),
        );
    }

    /**
     * Assemble custom-view metadata under an already derived operation and definition generation.
     *
     * @param   ExecutionContext          $context     Authenticated actor.
     * @param   BusinessSurface           $surface     Exact delivery boundary.
     * @param   string                    $definition  Definition UUID or handle.
     * @param   string                    $view        Custom view handle.
     * @param   ?string                   $record      Optional record identity.
     * @param   EntityTypeDefinition      $entity      Resolved active entity definition.
     * @param   BusinessSurfaceOperation  $operation   View-kind operation derived by the dispatcher.
     *
     * @return  array{
     *              definition: array<string, mixed>,
     *              available_operations: array<string, true>,
     *              view: array<string, mixed>,
     *              parameter_schema: array<string, mixed>
     *          }  Safe definition, view, operations and closed parameter schema.
     *
     * @throws  BusinessRecordDefinitionUnavailable  When the view is absent, denied, unexposed, or inactive.
     *
     * @since   2.0.0
     */
    private function customViewMetadataFor(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $view,
        ?string $record,
        EntityTypeDefinition $entity,
        BusinessSurfaceOperation $operation,
    ): array {
        $metadata = $this->metadata($context, $surface, $definition, $operation);

        return [
            'definition' => $metadata,
            'available_operations' => $this->availableOperations($context, $surface, $definition),
            'view' => $this->metadataItem($metadata, 'views', $view),
            'parameter_schema' => $this->customBusiness->viewQuerySchema($entity, $view),
        ];
    }

    /**
     * Browse one bounded record page through the shared query grammar.
     *
     * @param   ExecutionContext            $context     Authenticated actor.
     * @param   BusinessSurface             $surface     Exact delivery boundary.
     * @param   string                      $definition  Definition UUID or handle.
     * @param   array<string, mixed>        $query       Shared bounded query document.
     * @param   BusinessRecordQueryPurpose  $purpose     Browse, report, or export disclosure purpose.
     *
     * @return  array<string, mixed>  Safe definition metadata and projected page.
     *
     * @since   2.0.0
     */
    public function browse(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        array $query = [],
        BusinessRecordQueryPurpose $purpose = BusinessRecordQueryPurpose::Browse,
    ): array {
        $operation = match ($purpose) {
            BusinessRecordQueryPurpose::Browse => BusinessSurfaceOperation::Browse,
            BusinessRecordQueryPurpose::Report => BusinessSurfaceOperation::Report,
            BusinessRecordQueryPurpose::Export => BusinessSurfaceOperation::Export,
        };
        $metadata = $this->catalog->definition($context, $surface, $definition, $operation);
        $specification = $this->surfaceSpecification($this->queries->create($query), $metadata);
        $result = $this->records->browse(new BrowseRecordsQuery(
            $context,
            $definition,
            $specification,
            $this->organization($context, $metadata),
            $purpose,
        ));
        $projected = $this->projector->browse($result);
        $items = self::objectDocuments(
            $projected['items'] ?? null,
            'A projected business-record page is invalid.',
        );
        foreach ($items as $index => $item) {
            $items[$index] = $this->metadataRecord($item, $metadata);
        }
        $resolved = $this->definitions->forCreate($context, $definition);
        foreach ($result->records as $index => $record) {
            if (!isset($items[$index])) {
                throw new InvalidArgumentException('A projected business-record page is inconsistent.');
            }
            $items[$index]['fields'] = $this->present(
                $resolved->definition,
                $metadata,
                FieldModelContext::List,
                $record->values,
            );
        }
        $projected['items'] = $items;

        return [
            'definition' => $metadata,
            'available_operations' => $this->availableOperations($context, $surface, $definition),
            ...$projected,
        ];
    }

    /**
     * Browse choices for one exact relationship or entity-reference field.
     *
     * Choice authorization is rooted in the source definition and handle rather than in a standalone target
     * browse. The record service applies the source access plan's nested target policy; this facade then sends
     * only disclosed values through the registered `relation` presenters and derives a bounded server label.
     *
     * @param   ExecutionContext          $context     Authenticated actor.
     * @param   BusinessSurface           $surface     Exact delivery boundary.
     * @param   string                    $definition  Source definition UUID or handle.
     * @param   string                    $related     Relationship or entity-reference field handle.
     * @param   ?string                   $record      Source identity for update/relationship choices.
     * @param   BusinessSurfaceOperation  $operation   Create, update, or relationship selector context.
     * @param   array<string, mixed>      $query       Bounded target filter, search, sort and cursor document.
     *
     * @return  array{items: list<array<string, mixed>>, next_cursor: ?string,
     *          search_fields: list<array{handle: string, label: string}>}  Safe selector choices and controls.
     *
     * @since   2.0.0
     */
    public function relationChoices(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $related,
        ?string $record = null,
        BusinessSurfaceOperation $operation = BusinessSurfaceOperation::Relation,
        array $query = [],
    ): array {
        $policyOperation = match ($operation) {
            BusinessSurfaceOperation::Create => 'business.record.create',
            BusinessSurfaceOperation::Update => 'business.record.update',
            BusinessSurfaceOperation::Relation => 'business.record.relate',
            default => throw new InvalidArgumentException(
                'Related choices require a create, update, or relationship context.',
            ),
        };
        $metadata = $this->metadata($context, $surface, $definition, $operation);
        if ($operation === BusinessSurfaceOperation::Relation) {
            $this->metadataItem($metadata, 'relationships', $related);
        } else {
            $field = $this->metadataItem($metadata, 'fields', $related);
            if (($field['type'] ?? null) !== 'core.entity_reference') {
                throw new BusinessRecordDefinitionUnavailable();
            }
        }
        $result = $this->records->browseRelated(new BrowseRelatedRecordsQuery(
            $context,
            $definition,
            $related,
            $policyOperation,
            $record,
            $this->queries->create($query),
            $this->organization($context, $metadata),
        ));

        return $this->relatedChoicePage($result);
    }

    /**
     * Browse choices for a reference or media field inside one authorized owned-line create form.
     *
     * Entity references traverse the source relation plan and the line field's nested plan inside the
     * record service. Media references re-prove that the field is in the same nested Create disclosure set
     * before using the normal authorized media catalogue. The source definition remains the policy root in
     * both cases; callers cannot turn the line target into a standalone create or browse surface.
     *
     * @param   ExecutionContext      $context       Authenticated actor and scope.
     * @param   BusinessSurface       $surface       Administrator or explicitly opted-in portal boundary.
     * @param   string                $definition    Existing source definition UUID or handle.
     * @param   string                $record        Existing source public identity.
     * @param   string                $relationship  Owned-line relationship handle.
     * @param   string                $field         Target reference or media field handle.
     * @param   array<string, mixed>  $query         Bounded entity query, or optional media `search` string.
     *
     * @return  array{items: list<array<string, mixed>>, next_cursor: ?string,
     *          search_fields: list<array{handle: string, label: string}>}  Safe selector choices and controls.
     *
     * @since   2.0.0
     */
    public function ownedLineFieldChoices(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $record,
        string $relationship,
        string $field,
        array $query = [],
    ): array {
        $metadata = $this->metadata($context, $surface, $definition, BusinessSurfaceOperation::Relation);
        $owned = $this->records->ownedLineForm(new OwnedLineFormQuery(
            $context,
            $definition,
            $record,
            $relationship,
            $this->organization($context, $metadata),
        ));
        $targetField = null;
        foreach ($owned->definition->fields() as $candidate) {
            if ($candidate->handle === $field && in_array($field, $owned->fieldHandles, true)) {
                $targetField = $candidate;
                break;
            }
        }
        if ($targetField?->type === 'core.media_reference') {
            if (array_diff(array_keys($query), ['search']) !== []) {
                throw new InvalidArgumentException('An owned-line media selector query contains an unknown key.');
            }
            $search = $query['search'] ?? '';
            if (!is_string($search) || strlen($search) > 200) {
                throw new InvalidArgumentException('An owned-line media selector search is invalid.');
            }

            return [
                'items' => array_map(
                    static fn (MediaAsset $asset): array => [
                        'id' => $asset->id,
                        'label' => self::choiceText($asset->name, 120),
                    ],
                    $this->media->choices($context, $search),
                ),
                'next_cursor' => null,
                'search_fields' => [],
            ];
        }
        if ($targetField?->type !== 'core.entity_reference') {
            throw new BusinessRecordDefinitionUnavailable();
        }
        $result = $this->records->browseOwnedLineFieldChoices(new BrowseOwnedLineFieldChoicesQuery(
            $context,
            $definition,
            $record,
            $relationship,
            $field,
            $this->queries->create($query),
            $this->organization($context, $metadata),
        ));

        return $this->relatedChoicePage($result);
    }

    /**
     * Project one related-record result into bounded selector labels and disclosed fields.
     *
     * @param   RelatedRecordBrowseResult  $result  Authorized target page.
     *
     * @return  array{items: list<array<string, mixed>>, next_cursor: ?string,
     *          search_fields: list<array{handle: string, label: string}>}  Safe selector choices and controls.
     *
     * @since   2.0.0
     */
    private function relatedChoicePage(RelatedRecordBrowseResult $result): array
    {
        $items = [];
        foreach ($result->page->records as $record) {
            $fields = $this->relationChoiceFields($result->definition, $record->values);
            $label = null;
            foreach ($fields as $field) {
                if ($field['display'] !== '' && $field['identity'] === false) {
                    $label = $field['display'];
                    break;
                }
            }
            $label ??= $record->recordId;
            $items[] = [
                'id' => $record->recordId,
                'label' => self::choiceText($label, 120),
                'fields' => array_map(
                    static fn (array $field): array => [
                        'handle' => $field['handle'],
                        'label' => $field['label'],
                        'display' => $field['display'],
                    ],
                    $fields,
                ),
            ];
        }

        return [
            'items' => $items,
            'next_cursor' => $result->page->nextCursor?->value(),
            'search_fields' => $result->searchFields,
        ];
    }

    /**
     * Build the graphical create form for one policy-visible owned-line relationship.
     *
     * @param   ExecutionContext             $context       Authenticated actor and scope.
     * @param   BusinessSurface              $surface       Administrator or explicitly opted-in portal boundary.
     * @param   string                       $definition    Source definition UUID or handle.
     * @param   string                       $record        Existing source public identity.
     * @param   string                       $relationship  Owned-line relationship handle.
     * @param   array<string, mixed>         $retained      Previously submitted target values.
     * @param   array<string, list<string>>  $errors        Caller-visible field errors.
     *
     * @return  array<string, mixed>  Safe target identity metadata and semantic relation-context fields.
     *
     * @since   2.0.0
     */
    public function ownedLineForm(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $record,
        string $relationship,
        array $retained = [],
        array $errors = [],
    ): array {
        $metadata = $this->metadata($context, $surface, $definition, BusinessSurfaceOperation::Relation);
        $result = $this->records->ownedLineForm(new OwnedLineFormQuery(
            $context,
            $definition,
            $record,
            $relationship,
            $this->organization($context, $metadata),
        ));
        $allowed = array_fill_keys($result->fieldHandles, true);
        $values = $retained;
        foreach ($result->definition->fields() as $field) {
            if (!array_key_exists($field->handle, $values) && $field->default !== null) {
                $values[$field->handle] = $field->default;
            }
        }
        $fields = [];
        $identity = null;
        $identityType = $result->definition->identityStrategy === IdentityStrategy::Uuid
            ? 'core.uuid'
            : 'core.reference_identity';
        foreach ($result->definition->fields() as $field) {
            if ($field->type === $identityType) {
                $identity = $field->handle;
            }
            if (!isset($allowed[$field->handle]) || !$this->conditionVisible($field, $values)) {
                continue;
            }
            $presentation = $this->presentations->present(
                $field,
                $this->fieldTypes->get($field->type),
                FieldModelContext::Relation,
                $values[$field->handle] ?? $field->default,
                errors: $errors[$field->handle] ?? [],
                editable: $this->conditionEditable($field, $values),
            )->model;
            $fields[] = [
                ...$presentation,
                'label' => $field->labelIn($this->active->locale()),
                'description' => $field->descriptionIn($this->active->locale()),
                'help_text' => $field->helpTextIn($this->active->locale()),
                'type' => $field->type,
                'schema' => $this->catalog->schema($field),
                'form_group' => $field->formGroup,
                'order' => $field->order,
                'placements' => $field->placements,
            ];
        }
        if ($identity === null) {
            throw new BusinessRecordDefinitionUnavailable();
        }
        usort(
            $fields,
            static fn (array $left, array $right): int => [$left['order'], $left['handle']]
                <=> [$right['order'], $right['handle']],
        );

        return [
            'relationship' => $relationship,
            'target_definition' => $result->definition->handle,
            'target_label' => $result->definition->singularLabelIn($this->active->locale()),
            'identity_field' => $identity,
            'identity_generated' => $result->definition->identityStrategy === IdentityStrategy::Uuid,
            'suggested_record_id' => $result->definition->identityStrategy === IdentityStrategy::Uuid
                ? Uuid::uuid7()->toString()
                : null,
            'fields' => $fields,
        ];
    }

    /**
     * Browse bounded media choices for one policy-visible create or update field.
     *
     * @param   ExecutionContext          $context     Authenticated actor.
     * @param   BusinessSurface           $surface     Exact delivery boundary.
     * @param   string                    $definition  Source definition UUID or handle.
     * @param   string                    $field       Media-reference field handle.
     * @param   BusinessSurfaceOperation  $operation   Create or update metadata context.
     * @param   string                    $query       Bounded display-name search.
     *
     * @return  array{items: list<array{id: string, label: string}>, next_cursor: null}  Safe media choices.
     *
     * @throws  BusinessRecordDefinitionUnavailable  When the field is unavailable or not a media reference.
     * @throws  InvalidArgumentException  When the operation is not an edit context.
     *
     * @since   2.0.0
     */
    public function mediaChoices(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $field,
        BusinessSurfaceOperation $operation,
        string $query = '',
    ): array {
        if (!in_array($operation, [BusinessSurfaceOperation::Create, BusinessSurfaceOperation::Update], true)) {
            throw new InvalidArgumentException('Media choices require a create or update field context.');
        }
        $metadata = $this->metadata($context, $surface, $definition, $operation);
        foreach (
            self::objectDocuments(
                $metadata['fields'] ?? null,
                'Generated business field metadata is invalid.',
            ) as $candidate
        ) {
            if (($candidate['handle'] ?? null) === $field && ($candidate['type'] ?? null) === 'core.media_reference') {
                return [
                    'items' => array_map(
                        static fn (MediaAsset $asset): array => [
                            'id' => $asset->id,
                            'label' => self::choiceText($asset->name, 120),
                        ],
                        $this->media->choices($context, $query),
                    ),
                    'next_cursor' => null,
                ];
            }
        }

        throw new BusinessRecordDefinitionUnavailable();
    }

    /**
     * Read and present one record detail.
     *
     * @param   ExecutionContext  $context     Authenticated actor.
     * @param   BusinessSurface   $surface     Exact delivery boundary.
     * @param   string            $definition  Definition UUID or handle.
     * @param   string            $record      Public record identity.
     * @param   bool              $archived    Whether archived records may be addressed.
     * @param   bool              $deleted     Whether soft-deleted records may be addressed.
     *
     * @return  array<string, mixed>  Safe definition, record and semantic fields.
     *
     * @throws  BusinessRecordNotFound  When the record or its surface definition cannot be addressed.
     *
     * @since   2.0.0
     */
    public function read(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $record,
        bool $archived = false,
        bool $deleted = false,
    ): array {
        try {
            $metadata = $this->catalog->definition(
                $context,
                $surface,
                $definition,
                BusinessSurfaceOperation::Read,
            );
            $relationships = self::objectDocuments(
                $metadata['relationships'] ?? null,
                'Generated business relationship metadata is invalid.',
            );
            foreach ($relationships as &$relationship) {
                $relationship['loaded'] = false;
            }
            unset($relationship);
            $metadata['relationships'] = $relationships;
            $includes = [];
            $view = $this->records->read(new ReadRecordQuery(
                $context,
                $definition,
                $record,
                $this->organization($context, $metadata),
                projection: $this->metadataHandles($metadata, 'fields'),
                includeArchived: $archived,
                includeDeleted: $deleted,
                includes: $includes,
            ));
            $resolved = $this->definitions->pinned($context, $definition, $view->definitionVersion);

            return [
                'definition' => $metadata,
                'available_operations' => $this->availableOperations($context, $surface, $definition),
                'record' => $this->metadataRecord($this->projector->record($view), $metadata),
                'fields' => $this->present(
                    $resolved->definition,
                    $metadata,
                    FieldModelContext::Detail,
                    $view->values,
                ),
            ];
        } catch (BusinessRecordDefinitionUnavailable) {
            throw new BusinessRecordNotFound();
        }
    }

    /**
     * Read one record for the generated detail page, honouring a declared document view when one exists.
     *
     * When the policy-filtered metadata carries a generated `document` view, the roles that view declares
     * are hydrated through the same bounded read the relationship route uses: the declared line collection
     * and party relationships become the only includes, each line row is projected onto the line
     * definition's stable list columns, and the surviving role metadata is attached as `document_view`.
     * Without such a view the model is exactly the ordinary `read()` model, so definitions that never
     * declared a document keep today's rendering and cost. No new read path exists either way — policy,
     * disclosure and the bounded include budget are enforced by the same services as every other page.
     *
     * @param   ExecutionContext  $context     Authenticated actor.
     * @param   BusinessSurface   $surface     Exact delivery boundary.
     * @param   string            $definition  Definition UUID or handle.
     * @param   string            $record      Public record identity.
     * @param   bool              $archived    Whether archived records may be addressed.
     * @param   bool              $deleted     Whether soft-deleted records may be addressed.
     *
     * @return  array<string, mixed>  Safe detail model, with `document_view` and hydrated role includes
     *          when the definition declares a generated document view for this surface.
     *
     * @throws  BusinessRecordNotFound  When the definition or record is unavailable for this caller.
     *
     * @since   2.0.0
     */
    public function document(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $record,
        bool $archived = false,
        bool $deleted = false,
    ): array {
        try {
            $metadata = $this->catalog->definition(
                $context,
                $surface,
                $definition,
                BusinessSurfaceOperation::Read,
            );
            $view = $this->declaredDocumentView($metadata);
            $roles = $view === null ? null : ($view['document'] ?? null);
            $roles = is_array($roles) ? self::objectDocument($roles, 'A document view block is invalid.') : null;
            $lines = null;
            $includes = [];
            if ($roles !== null) {
                $lines = is_string($roles['lines'] ?? null) ? $roles['lines'] : null;
                foreach (
                    self::objectDocuments(
                        $roles['parties'] ?? [],
                        'A document view party block is invalid.',
                    ) as $party
                ) {
                    $handle = $party['relationship'] ?? null;
                    if (is_string($handle) && !in_array($handle, $includes, true)) {
                        $includes[] = $handle;
                    }
                }
                if ($lines !== null && !in_array($lines, $includes, true)) {
                    $includes[] = $lines;
                }
            }
            $relationships = self::objectDocuments(
                $metadata['relationships'] ?? null,
                'Generated business relationship metadata is invalid.',
            );
            $targets = [];
            foreach ($relationships as &$relationship) {
                $handle = $relationship['handle'] ?? null;
                $relationship['loaded'] = is_string($handle) && in_array($handle, $includes, true);
                if (is_string($handle) && is_string($relationship['target'] ?? null)) {
                    $targets[$handle] = $relationship['target'];
                }
            }
            unset($relationship);
            $metadata['relationships'] = $relationships;
            $recordView = $this->records->read(new ReadRecordQuery(
                $context,
                $definition,
                $record,
                $this->organization($context, $metadata),
                projection: $this->metadataHandles($metadata, 'fields'),
                includeArchived: $archived,
                includeDeleted: $deleted,
                includes: $includes,
            ));
            $resolved = $this->definitions->pinned($context, $definition, $recordView->definitionVersion);
            $recordModel = $this->metadataRecord($this->projector->record($recordView), $metadata);
            $lineColumns = [];
            if ($includes !== []) {
                $recordIncludes = self::objectDocument(
                    $recordModel['includes'] ?? null,
                    'A generated document projection is invalid.',
                );
                foreach ($includes as $handle) {
                    $target = $targets[$handle] ?? null;
                    $included = $recordView->includes[$handle] ?? null;
                    if (!is_string($target) || !is_array($included) || !array_is_list($included)) {
                        throw new InvalidArgumentException('A generated document projection is invalid.');
                    }
                    $projectedIncluded = self::objectDocuments(
                        $recordIncludes[$handle] ?? null,
                        'A generated document projection is invalid.',
                    );
                    if ($handle === $lines) {
                        [$lineColumns, $recordIncludes[$handle]] = $this->presentDocumentLines(
                            $context,
                            $target,
                            $included,
                            $projectedIncluded,
                        );
                        continue;
                    }
                    $recordIncludes[$handle] = $this->presentIncludedRecords(
                        $context,
                        $target,
                        $included,
                        $projectedIncluded,
                    );
                }
                $recordModel['includes'] = $recordIncludes;
            }
            $model = [
                'definition' => $metadata,
                'available_operations' => $this->availableOperations($context, $surface, $definition),
                'record' => $recordModel,
                'fields' => $this->present(
                    $resolved->definition,
                    $metadata,
                    FieldModelContext::Detail,
                    $recordView->values,
                ),
            ];
            if ($view !== null) {
                $model['document_view'] = [...$view, 'line_columns' => $lineColumns];
            }

            return $model;
        } catch (BusinessRecordDefinitionUnavailable) {
            throw new BusinessRecordNotFound();
        }
    }

    /**
     * Find the first generated document view the policy-filtered metadata still discloses.
     *
     * @param   array<string, mixed>  $metadata  Safe definition metadata for the read operation.
     *
     * @return  ?array<string, mixed>  The view item, or null when no generated document view survives.
     *
     * @since   2.0.0
     */
    private function declaredDocumentView(array $metadata): ?array
    {
        $views = $metadata['views'] ?? [];
        if (!is_array($views) || !array_is_list($views)) {
            throw new InvalidArgumentException('Generated business view metadata is invalid.');
        }
        foreach ($views as $view) {
            if (
                is_array($view)
                && ($view['kind'] ?? null) === 'document'
                && ($view['custom'] ?? null) === false
            ) {
                /** @var array<string, mixed> $view */
                return $view;
            }
        }

        return null;
    }

    /**
     * Read one exact relationship section without widening the bounded detail include budget.
     *
     * The relationship must survive read disclosure; mutation controls are gated independently by the
     * available relation and reorder operations. Only that handle is hydrated, so every declared relationship
     * remains readable through its fixed route while the ordinary detail page performs no relationship query.
     *
     * @param   ExecutionContext  $context       Authenticated actor.
     * @param   BusinessSurface   $surface       Exact delivery boundary.
     * @param   string            $definition    Definition UUID or handle.
     * @param   string            $record        Public record identity.
     * @param   string            $relationship  Exact declared relationship handle.
     * @param   bool              $archived      Whether archived source records may be addressed.
     * @param   bool              $deleted       Whether soft-deleted source records may be addressed.
     *
     * @return  array<string, mixed>  Safe detail model with one hydrated relationship.
     *
     * @since   2.0.0
     */
    public function relationship(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $record,
        string $relationship,
        bool $archived = false,
        bool $deleted = false,
    ): array {
        $metadata = $this->catalog->definition(
            $context,
            $surface,
            $definition,
            BusinessSurfaceOperation::Read,
        );
        $visible = $this->metadataItem($metadata, 'relationships', $relationship);
        $metadata['relationships'] = [[...$visible, 'loaded' => true]];
        $view = $this->records->read(new ReadRecordQuery(
            $context,
            $definition,
            $record,
            $this->organization($context, $metadata),
            projection: $this->metadataHandles($metadata, 'fields'),
            includeArchived: $archived,
            includeDeleted: $deleted,
            includes: [$relationship],
        ));
        $resolved = $this->definitions->pinned($context, $definition, $view->definitionVersion);
        $recordModel = $this->metadataRecord($this->projector->record($view), $metadata);
        $target = $visible['target'] ?? null;
        $included = $view->includes[$relationship] ?? null;
        $recordIncludes = self::objectDocument(
            $recordModel['includes'] ?? null,
            'A generated relationship projection is invalid.',
        );
        $projectedIncluded = self::objectDocuments(
            $recordIncludes[$relationship] ?? null,
            'A generated relationship projection is invalid.',
        );
        if (
            !is_string($target)
            || !is_array($included)
            || !array_is_list($included)
        ) {
            throw new InvalidArgumentException('A generated relationship projection is invalid.');
        }
        $recordIncludes[$relationship] = $this->presentIncludedRecords(
            $context,
            $target,
            $included,
            $projectedIncluded,
        );
        $recordModel['includes'] = $recordIncludes;

        return [
            'definition' => $metadata,
            'available_operations' => $this->availableOperations($context, $surface, $definition),
            'record' => $recordModel,
            'fields' => $this->present(
                $resolved->definition,
                $metadata,
                FieldModelContext::Detail,
                $view->values,
            ),
            'relationship_focus' => $relationship,
        ];
    }

    /**
     * Build a create or update form model with retained input and field errors.
     *
     * @param   ExecutionContext             $context     Authenticated actor.
     * @param   BusinessSurface              $surface     Exact delivery boundary.
     * @param   string                       $definition  Definition UUID or handle.
     * @param   ?string                      $record      Public identity for update, null for create.
     * @param   array<string, mixed>         $retained    Previously submitted values after a failed attempt.
     * @param   array<string, list<string>>  $errors      Caller-visible messages keyed by field handle.
     *
     * @return  array<string, mixed>  Safe definition, optional record and semantic form fields.
     *
     * @since   2.0.0
     */
    public function form(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        ?string $record = null,
        array $retained = [],
        array $errors = [],
    ): array {
        $operation = $record === null ? BusinessSurfaceOperation::Create : BusinessSurfaceOperation::Update;
        $metadata = $this->catalog->definition($context, $surface, $definition, $operation);
        $view = null;
        $values = $retained;
        if ($record === null) {
            $resolved = $this->definitions->forCreate($context, $definition);
            foreach ($resolved->definition->fields() as $field) {
                if (!array_key_exists($field->handle, $values) && $field->default !== null) {
                    $values[$field->handle] = $field->default;
                }
            }
        } else {
            $view = $this->records->read(new ReadRecordQuery(
                $context,
                $definition,
                $record,
                $this->organization($context, $metadata),
                projection: $this->metadataHandles($metadata, 'fields'),
            ));
            $resolved = $this->definitions->pinned($context, $definition, $view->definitionVersion);
            $values = [...$view->values, ...$retained];
        }

        return [
            'definition' => $metadata,
            'available_operations' => $this->availableOperations($context, $surface, $definition),
            'record' => $view === null
                ? null
                : $this->metadataRecord($this->projector->record($view), $metadata),
            'fields' => $this->present(
                $resolved->definition,
                $metadata,
                $record === null ? FieldModelContext::Create : FieldModelContext::Update,
                $values,
                $errors,
            ),
        ];
    }

    /**
     * Create one record after enforcing surface exposure.
     *
     * @param   ExecutionContext      $context      Authenticated actor.
     * @param   BusinessSurface       $surface      Exact delivery boundary.
     * @param   string                $definition   Definition UUID or handle.
     * @param   array<string, mixed>  $values       Schema-authorized typed values.
     * @param   string                $operationId  Idempotency identity.
     * @param   ?string               $recordId     Optional explicit public identity.
     *
     * @return  array<string, mixed>  Safe mutation outcome.
     *
     * @since   2.0.0
     */
    public function create(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        array $values,
        string $operationId,
        ?string $recordId = null,
    ): array {
        $metadata = $this->catalog->definition(
            $context,
            $surface,
            $definition,
            BusinessSurfaceOperation::Create,
        );
        $this->assertMetadataReferenceInputs($context, $definition, $metadata, $values);

        return $this->projector->mutation($this->records->create(new CreateRecordCommand(
            $context,
            $definition,
            $values,
            IdempotencyKey::fromString($operationId),
            $this->organization($context, $metadata),
            $recordId,
        )));
    }

    /**
     * Update one record after enforcing surface exposure and optimistic concurrency.
     *
     * @param   ExecutionContext      $context          Authenticated actor.
     * @param   BusinessSurface       $surface          Exact delivery boundary.
     * @param   string                $definition       Definition UUID or handle.
     * @param   string                $record           Public record identity.
     * @param   int                   $expectedVersion  Required current record version.
     * @param   array<string, mixed>  $values           Schema-authorized typed values.
     * @param   string                $operationId      Idempotency identity.
     *
     * @return  array<string, mixed>  Safe mutation outcome.
     *
     * @since   2.0.0
     */
    public function update(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $record,
        int $expectedVersion,
        array $values,
        string $operationId,
    ): array {
        $metadata = $this->metadata($context, $surface, $definition, BusinessSurfaceOperation::Update);
        $this->assertMetadataReferenceInputs($context, $definition, $metadata, $values);

        return $this->projector->mutation($this->records->update(new UpdateRecordCommand(
            $context,
            $definition,
            $record,
            $expectedVersion,
            $values,
            IdempotencyKey::fromString($operationId),
            $this->organization($context, $metadata),
        )));
    }

    /**
     * Archive one record through the canonical application boundary.
     *
     * @param   ExecutionContext  $context          Authenticated actor.
     * @param   BusinessSurface   $surface          Exact delivery boundary.
     * @param   string            $definition       Definition UUID or handle.
     * @param   string            $record           Public record identity.
     * @param   int               $expectedVersion  Required current record version.
     * @param   string            $operationId      Idempotency identity.
     *
     * @return  array<string, mixed>  Safe mutation outcome.
     *
     * @since   2.0.0
     */
    public function archive(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $record,
        int $expectedVersion,
        string $operationId,
    ): array {
        $metadata = $this->metadata($context, $surface, $definition, BusinessSurfaceOperation::Archive);

        return $this->projector->mutation($this->records->archive(new ArchiveRecordCommand(
            $context,
            $definition,
            $record,
            $expectedVersion,
            IdempotencyKey::fromString($operationId),
            $this->organization($context, $metadata),
        )));
    }

    /**
     * Delete one record through the canonical application boundary.
     *
     * @param   ExecutionContext  $context          Authenticated actor.
     * @param   BusinessSurface   $surface          Exact delivery boundary.
     * @param   string            $definition       Definition UUID or handle.
     * @param   string            $record           Public record identity.
     * @param   int               $expectedVersion  Required current record version.
     * @param   string            $operationId      Idempotency identity.
     *
     * @return  array<string, mixed>  Safe mutation outcome.
     *
     * @since   2.0.0
     */
    public function delete(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $record,
        int $expectedVersion,
        string $operationId,
    ): array {
        $metadata = $this->metadata($context, $surface, $definition, BusinessSurfaceOperation::Delete);

        return $this->projector->mutation($this->records->delete(new DeleteRecordCommand(
            $context,
            $definition,
            $record,
            $expectedVersion,
            IdempotencyKey::fromString($operationId),
            $this->organization($context, $metadata),
        )));
    }

    /**
     * Restore one archived or soft-deleted record.
     *
     * @param   ExecutionContext  $context          Authenticated actor.
     * @param   BusinessSurface   $surface          Exact delivery boundary.
     * @param   string            $definition       Definition UUID or handle.
     * @param   string            $record           Public record identity.
     * @param   int               $expectedVersion  Required current record version.
     * @param   string            $operationId      Idempotency identity.
     *
     * @return  array<string, mixed>  Safe mutation outcome.
     *
     * @since   2.0.0
     */
    public function restore(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $record,
        int $expectedVersion,
        string $operationId,
    ): array {
        $metadata = $this->metadata($context, $surface, $definition, BusinessSurfaceOperation::Restore);

        return $this->projector->mutation($this->records->restore(new RestoreRecordCommand(
            $context,
            $definition,
            $record,
            $expectedVersion,
            IdempotencyKey::fromString($operationId),
            $this->organization($context, $metadata),
        )));
    }

    /**
     * Execute one declared action with optional typed input and approval binding.
     *
     * @param   ExecutionContext      $context            Authenticated actor.
     * @param   BusinessSurface       $surface            Exact delivery boundary.
     * @param   string                $definition         Definition UUID or handle.
     * @param   string                $record             Public record identity.
     * @param   int                   $expectedVersion    Required current record version.
     * @param   string                $action             Declared action handle.
     * @param   string                $operationId        Idempotency identity.
     * @param   array<string, mixed>  $input              Validated action input.
     * @param   ?string               $approvalRequestId  Optional approval request identity.
     *
     * @return  array<string, mixed>  Safe mutation outcome.
     *
     * @since   2.0.0
     */
    public function action(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $record,
        int $expectedVersion,
        string $action,
        string $operationId,
        array $input = [],
        ?string $approvalRequestId = null,
    ): array {
        $metadata = $this->metadata($context, $surface, $definition, BusinessSurfaceOperation::Action);
        $this->metadataItem($metadata, 'actions', $action);
        $resolved = $this->definitions->forCreate($context, $definition);
        if ($this->customBusiness->handlesAction($resolved->definition, $action)) {
            $result = $this->customActions->execute(new CustomBusinessActionCommand(
                ExtensionExecutionContext::of($context),
                $definition,
                $record,
                $expectedVersion,
                $action,
                IdempotencyKey::fromString($operationId),
                $input,
                $this->organization($context, $metadata),
                $approvalRequestId,
            ));

            return [
                'definition_version' => $resolved->definition->definitionVersion,
                'record_id' => $record,
                'version' => $result->recordVersion,
                'workflow_state' => $result->workflowState,
                'operation' => 'action',
                'deleted' => $result->deleted,
                'replayed' => $result->replayed,
                'result' => $result->data,
            ];
        }

        return $this->projector->mutation($this->records->action(new ExecuteRecordActionCommand(
            $context,
            $definition,
            $record,
            $expectedVersion,
            $action,
            IdempotencyKey::fromString($operationId),
            $input,
            $this->organization($context, $metadata),
            $approvalRequestId,
        )));
    }

    /**
     * Resolve the exact step-up purpose for one policy-visible high-impact action.
     *
     * Browser adapters call this before accepting a second-factor credential. The action handle is
     * first resolved through the same surface operation and policy-filtered metadata used by action
     * execution, so submitted text can never select an undeclared proof purpose. Ordinary actions
     * return null and continue under their existing password-authenticated context.
     *
     * @param   ExecutionContext  $context     Authenticated actor and exact authority scope.
     * @param   BusinessSurface   $surface     Administrator or portal browser boundary.
     * @param   string            $definition  Definition UUID or stable handle.
     * @param   string            $action      Submitted action handle to resolve against metadata.
     *
     * @return  string|null  Exact approval-binding purpose, or null for a non-high-impact action.
     *
     * @throws  BusinessRecordDefinitionUnavailable  When the definition or action is unavailable.
     *
     * @since   2.0.0
     */
    public function actionStepUpPurpose(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $action,
    ): ?string {
        $metadata = $this->metadata($context, $surface, $definition, BusinessSurfaceOperation::Action);
        $resolved = $this->metadataItem($metadata, 'actions', $action);

        if (($resolved['high_impact'] ?? null) !== true) {
            return null;
        }
        $handle = $resolved['handle'] ?? null;
        if (!is_string($handle)) {
            throw new BusinessRecordDefinitionUnavailable();
        }

        return 'business.record.action:' . $handle;
    }

    /**
     * Build a policy-filtered confirmation model for one bounded bulk attempt.
     *
     * Record identities are echoed only after their shape and count are validated; they are caller-supplied
     * values rather than record lookups, so a forged selection cannot use confirmation as an enumeration
     * oracle. The actual mutation re-resolves every record through the canonical policy and version boundary.
     *
     * @param   ExecutionContext            $context     Authenticated actor.
     * @param   BusinessSurface             $surface     Exact delivery boundary.
     * @param   string                      $definition  Definition UUID or handle.
     * @param   BusinessSurfaceOperation    $operation   Archive, restore, or bulk action.
     * @param   list<array<string, mixed>>  $items       Selected record identities and reviewed versions.
     * @param   ?string                     $action      Bulk-enabled declared action handle.
     *
     * @return  array<string, mixed>  Safe definition, available operations, and validated confirmation rows.
     *
     * @since   2.0.0
     */
    public function bulkConfirmation(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        BusinessSurfaceOperation $operation,
        array $items,
        ?string $action = null,
    ): array {
        $request = new BusinessBulkMutation($operation, $items, 'browser:bulk-plan', $action);
        $metadata = $this->metadata($context, $surface, $definition, $operation);
        $this->assertBulkAction($metadata, $request);

        return [
            'definition' => $metadata,
            'available_operations' => $this->availableOperations($context, $surface, $definition),
            'bulk' => [
                'operation' => $request->operation->value,
                'action' => $request->action,
                'items' => $request->items(),
            ],
        ];
    }

    /**
     * Apply at most 50 archive, restore, or declared bulk-action mutations atomically.
     *
     * The outer transaction composes with each canonical record-service transaction, so a policy denial,
     * stale reviewed version, approval failure, or storage fault rolls every member back. Every record uses
     * a deterministic child idempotency identity and therefore replays independently when the entire bulk
     * attempt is retried with the same caller identity.
     *
     * @param   ExecutionContext            $context      Authenticated actor.
     * @param   BusinessSurface             $surface      Exact delivery boundary.
     * @param   string                      $definition   Definition UUID or handle.
     * @param   BusinessSurfaceOperation    $operation    Archive, restore, or action operation.
     * @param   list<array<string, mixed>>  $items        Selected records and reviewed expected versions.
     * @param   string                      $operationId  Caller-owned bulk idempotency identity.
     * @param   ?string                     $action       Bulk-enabled declared action handle.
     * @param   array<string, mixed>        $input        Shared bounded action input.
     *
     * @return  array{operation: string, count: int, items: list<array<string, mixed>>}  Projected outcomes
     *          carrying their deterministic child operation identities.
     *
     * @since   2.0.0
     */
    public function bulk(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        BusinessSurfaceOperation $operation,
        array $items,
        string $operationId,
        ?string $action = null,
        array $input = [],
    ): array {
        $request = new BusinessBulkMutation($operation, $items, $operationId, $action, $input);

        return $this->transactions->transactional(function () use (
            $context,
            $surface,
            $definition,
            $request,
        ): array {
            $metadata = $this->metadata($context, $surface, $definition, $request->operation);
            $this->assertBulkAction($metadata, $request);
            $results = [];
            foreach ($request->items() as $item) {
                $childOperationId = $request->operationIdFor($item['record_id']);
                $result = match ($request->operation) {
                    BusinessSurfaceOperation::Archive => $this->archive(
                        $context,
                        $surface,
                        $definition,
                        $item['record_id'],
                        $item['expected_version'],
                        $childOperationId,
                    ),
                    BusinessSurfaceOperation::Restore => $this->restore(
                        $context,
                        $surface,
                        $definition,
                        $item['record_id'],
                        $item['expected_version'],
                        $childOperationId,
                    ),
                    BusinessSurfaceOperation::Action => $this->action(
                        $context,
                        $surface,
                        $definition,
                        $item['record_id'],
                        $item['expected_version'],
                        (string) $request->action,
                        $childOperationId,
                        $request->input,
                    ),
                    default => throw new InvalidArgumentException(
                        'A generated business bulk operation is unsupported.',
                    ),
                };
                $results[] = ['operation_id' => $childOperationId, ...$result];
            }

            return [
                'operation' => $request->operation->value,
                'count' => count($results),
                'items' => $results,
            ];
        });
    }

    /**
     * Request maker-checker approval for one exact high-impact action attempt.
     *
     * @param   ExecutionContext      $context          Authenticated actor.
     * @param   BusinessSurface       $surface          Exact delivery boundary.
     * @param   string                $definition       Definition UUID or handle.
     * @param   string                $record           Public record identity.
     * @param   int                   $expectedVersion  Required current record version.
     * @param   string                $action           Declared action handle.
     * @param   string                $operationId      Idempotency identity.
     * @param   array<string, mixed>  $input            Validated action input.
     *
     * @return  array{approval_request_id: ?string}  Approval identity, null when no rule requires one.
     *
     * @since   2.0.0
     */
    public function requestActionApproval(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $record,
        int $expectedVersion,
        string $action,
        string $operationId,
        array $input = [],
    ): array {
        $metadata = $this->metadata($context, $surface, $definition, BusinessSurfaceOperation::Approval);
        $this->metadataItem($metadata, 'actions', $action);
        $resolved = $this->definitions->forCreate($context, $definition);
        if ($this->customBusiness->handlesAction($resolved->definition, $action)) {
            $schemas = $this->customBusiness->actionContractSchemas($resolved->definition, $action)
                ?? throw new BusinessRecordDefinitionUnavailable();
            CustomBusinessSchema::fromArray($schemas['command_schema'])->assertValid(
                $input,
                'action command',
            );
        }
        $request = $this->records->requestActionApproval(new ExecuteRecordActionCommand(
            $context,
            $definition,
            $record,
            $expectedVersion,
            $action,
            IdempotencyKey::fromString($operationId),
            $input,
            $this->organization($context, $metadata),
        ));

        return ['approval_request_id' => $request];
    }

    /**
     * Relate a source record to an existing target or new owned line.
     *
     * @param   ExecutionContext      $context          Authenticated actor.
     * @param   BusinessSurface       $surface          Exact delivery boundary.
     * @param   string                $definition       Source definition UUID or handle.
     * @param   string                $record           Public source record identity.
     * @param   int                   $expectedVersion  Required current source version.
     * @param   string                $relationship     Declared relationship handle.
     * @param   string                $target           Public target record identity.
     * @param   string                $operationId      Idempotency identity.
     * @param   ?int                  $position         Optional zero-based relationship position.
     * @param   array<string, mixed>  $targetValues     Values for an owned target record.
     *
     * @return  array<string, mixed>  Safe mutation outcome.
     *
     * @since   2.0.0
     */
    public function relate(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $record,
        int $expectedVersion,
        string $relationship,
        string $target,
        string $operationId,
        ?int $position = null,
        array $targetValues = [],
    ): array {
        $metadata = $this->metadata($context, $surface, $definition, BusinessSurfaceOperation::Relation);
        $this->metadataItem($metadata, 'relationships', $relationship);

        return $this->projector->mutation($this->records->relate(new RelateRecordsCommand(
            $context,
            $definition,
            $record,
            $expectedVersion,
            $relationship,
            $target,
            IdempotencyKey::fromString($operationId),
            $position,
            $this->organization($context, $metadata),
            $targetValues,
        )));
    }

    /**
     * Remove one declared relationship link.
     *
     * @param   ExecutionContext  $context          Authenticated actor.
     * @param   BusinessSurface   $surface          Exact delivery boundary.
     * @param   string            $definition       Source definition UUID or handle.
     * @param   string            $record           Public source record identity.
     * @param   int               $expectedVersion  Required current source version.
     * @param   string            $relationship     Declared relationship handle.
     * @param   string            $target           Public target record identity.
     * @param   string            $operationId      Idempotency identity.
     *
     * @return  array<string, mixed>  Safe mutation outcome.
     *
     * @since   2.0.0
     */
    public function unrelate(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $record,
        int $expectedVersion,
        string $relationship,
        string $target,
        string $operationId,
    ): array {
        $metadata = $this->metadata($context, $surface, $definition, BusinessSurfaceOperation::Relation);
        $this->metadataItem($metadata, 'relationships', $relationship);

        return $this->projector->mutation($this->records->unrelate(new UnrelateRecordsCommand(
            $context,
            $definition,
            $record,
            $expectedVersion,
            $relationship,
            $target,
            IdempotencyKey::fromString($operationId),
            $this->organization($context, $metadata),
        )));
    }

    /**
     * Reorder every member of one ordered relationship exactly once.
     *
     * @param   ExecutionContext  $context           Authenticated actor.
     * @param   BusinessSurface   $surface           Exact delivery boundary.
     * @param   string            $definition        Source definition UUID or handle.
     * @param   string            $record            Public source record identity.
     * @param   int               $expectedVersion   Required current source version.
     * @param   string            $relationship      Declared relationship handle.
     * @param   list<string>      $orderedRecordIds  Complete ordered target identity list.
     * @param   string            $operationId       Idempotency identity.
     *
     * @return  array<string, mixed>  Safe mutation outcome.
     *
     * @since   2.0.0
     */
    public function reorder(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $record,
        int $expectedVersion,
        string $relationship,
        array $orderedRecordIds,
        string $operationId,
    ): array {
        $metadata = $this->metadata($context, $surface, $definition, BusinessSurfaceOperation::Reorder);
        $this->metadataItem($metadata, 'relationships', $relationship);

        return $this->projector->mutation($this->records->reorder(new ReorderRecordLinesCommand(
            $context,
            $definition,
            $record,
            $expectedVersion,
            $relationship,
            $orderedRecordIds,
            IdempotencyKey::fromString($operationId),
            $this->organization($context, $metadata),
        )));
    }

    /**
     * Read one bounded page of record history.
     *
     * @param   ExecutionContext  $context        Authenticated actor.
     * @param   BusinessSurface   $surface        Exact delivery boundary.
     * @param   string            $definition     Definition UUID or handle.
     * @param   string            $record         Public record identity.
     * @param   int               $limit          Maximum returned revisions.
     * @param   ?int              $beforeVersion  Exclusive version cursor.
     *
     * @return  array<string, mixed>  Safe revision page.
     *
     * @since   2.0.0
     */
    public function history(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $record,
        int $limit = 100,
        ?int $beforeVersion = null,
    ): array {
        $metadata = $this->metadata($context, $surface, $definition, BusinessSurfaceOperation::History);

        return $this->projector->history($this->records->history(new RecordHistoryQuery(
            $context,
            $definition,
            $record,
            $this->organization($context, $metadata),
            $limit,
            $beforeVersion,
        )));
    }

    /**
     * Resolve and return metadata, kept as a named helper for mutation methods.
     *
     * @param   ExecutionContext          $context     Authenticated actor.
     * @param   BusinessSurface           $surface     Exact delivery boundary.
     * @param   string                    $definition  Definition UUID or handle.
     * @param   BusinessSurfaceOperation  $operation   Required surface operation.
     *
     * @return  array<string, mixed>  Policy-filtered definition metadata.
     *
     * @since   2.0.0
     */
    private function metadata(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        BusinessSurfaceOperation $operation,
    ): array {
        return $this->catalog->definition($context, $surface, $definition, $operation);
    }

    /**
     * Refuse a declared entity-reference input omitted from the exact generated-surface metadata.
     *
     * Core record policy remains authoritative for every field. This surface ceiling covers the extra
     * target-definition exposure required by generated adapters: a caller cannot forge an entity-reference
     * handle hidden because its target is unavailable on this surface while continuing to submit ordinary
     * policy-visible values through the canonical record validator.
     *
     * @param   ExecutionContext      $context     Authenticated actor and site.
     * @param   string                $definition  Active source definition identifier.
     * @param   array<string, mixed>  $metadata    Exact create or update catalog document.
     * @param   array<string, mixed>  $values      Submitted mutation values.
     *
     * @return  void
     *
     * @throws  BusinessRecordDefinitionUnavailable  When a submitted reference was omitted from metadata.
     *
     * @since   2.0.0
     */
    private function assertMetadataReferenceInputs(
        ExecutionContext $context,
        string $definition,
        array $metadata,
        array $values,
    ): void {
        $allowed = array_fill_keys($this->metadataHandles($metadata, 'fields'), true);
        $resolved = $this->definitions->forCreate($context, $definition);
        foreach ($resolved->definition->fields() as $field) {
            if (
                $field->type === 'core.entity_reference'
                && array_key_exists($field->handle, $values)
                && !isset($allowed[$field->handle])
            ) {
                throw new BusinessRecordDefinitionUnavailable();
            }
        }
    }

    /**
     * Resolve one policy-visible view or action from a safe metadata document.
     *
     * @param   array<string, mixed>  $metadata    Policy-filtered definition metadata.
     * @param   string                $collection  `views` or `actions`.
     * @param   string                $handle      Requested declaration handle.
     *
     * @return  array<string, mixed>  Matching safe metadata item.
     *
     * @throws  BusinessRecordDefinitionUnavailable  When the requested item was omitted for any reason.
     *
     * @since   2.0.0
     */
    private function metadataItem(array $metadata, string $collection, string $handle): array
    {
        $items = $metadata[$collection] ?? [];
        if (!is_array($items)) {
            throw new BusinessRecordDefinitionUnavailable();
        }
        foreach ($items as $item) {
            if (is_array($item) && ($item['handle'] ?? null) === $handle) {
                /** @var array<string, mixed> $item */
                return $item;
            }
        }

        throw new BusinessRecordDefinitionUnavailable();
    }

    /**
     * Refuse relation includes omitted from the current surface catalog.
     *
     * The record query compiler still enforces row and field policy. This additional generated-surface
     * ceiling prevents a raw portal query from traversing a target definition whose portal exposure or
     * target-side operation opt-in caused the shared catalog to omit the relationship.
     *
     * @param   array<string, mixed>  $metadata  Policy- and surface-filtered source definition.
     * @param   list<string>          $includes  Relationship handles requested by the bounded query.
     *
     * @return  void
     *
     * @throws  BusinessRecordDefinitionUnavailable  When any include was omitted from surface metadata.
     *
     * @since   2.0.0
     */
    private function assertMetadataIncludes(array $metadata, array $includes): void
    {
        $allowed = array_fill_keys($this->metadataHandles($metadata, 'relationships'), true);
        foreach ($includes as $include) {
            if (!isset($allowed[$include])) {
                throw new BusinessRecordDefinitionUnavailable();
            }
        }
    }

    /**
     * Narrow a bounded record query to fields and includes present in generated surface metadata.
     *
     * An omitted field list means every catalog-visible field rather than every field admitted by the
     * delivery-neutral record policy. Explicit fields and includes must be a subset of that same catalog,
     * preventing raw portal JSON from reopening a target whose definition-level exposure was denied.
     *
     * @param   RecordQuerySpecification  $specification  Already bounded transport-neutral query.
     * @param   array<string, mixed>      $metadata       Exact policy- and surface-filtered definition.
     *
     * @return  RecordQuerySpecification  Equivalent query with a surface-safe projection.
     *
     * @throws  BusinessRecordDefinitionUnavailable  When an explicit field or include was omitted.
     *
     * @since   2.0.0
     */
    private function surfaceSpecification(
        RecordQuerySpecification $specification,
        array $metadata,
    ): RecordQuerySpecification {
        $allowedFields = $this->metadataHandles($metadata, 'fields');
        $requestedFields = $specification->projection->fields;
        if ($requestedFields !== [] && array_diff($requestedFields, $allowedFields) !== []) {
            throw new BusinessRecordDefinitionUnavailable();
        }
        $this->assertMetadataIncludes($metadata, $specification->projection->includes);

        return new RecordQuerySpecification(
            $specification->filter,
            $specification->search,
            $specification->sorts,
            $specification->after,
            $specification->pageSize,
            new RecordProjection(
                $requestedFields === [] ? $allowedFields : $requestedFields,
                $specification->projection->includes,
                $specification->projection->aggregates,
            ),
            $specification->includeArchived,
            $specification->includeDeleted,
        );
    }

    /**
     * Narrow a custom-view query to the exact fields in its signed view definition.
     *
     * Policy-visible definition metadata is still the first disclosure boundary. The signed view then
     * provides a stricter projection and filter/sort vocabulary: an extension cannot use its callback to
     * read or select on another otherwise visible field or relationship. Every immutable query coordinate,
     * including the opaque cursor, is carried into the final narrowed specification; the record boundary
     * verifies that cursor against this final specification's digest before executing it.
     *
     * @param   RecordQuerySpecification  $specification  Bounded caller query before signed-view narrowing.
     * @param   array<string, mixed>      $metadata       Policy- and surface-filtered definition metadata.
     * @param   EntityTypeDefinition      $definition     Exact active definition carrying the signed view.
     * @param   string                    $view           Custom view handle selected by the delivery use case.
     *
     * @return  RecordQuerySpecification  Equivalent query projected only onto signed, policy-visible fields.
     *
     * @throws  BusinessRecordDefinitionUnavailable  When the view is absent or requests disclosure outside it.
     *
     * @since   2.0.0
     */
    private function customViewSpecification(
        RecordQuerySpecification $specification,
        array $metadata,
        EntityTypeDefinition $definition,
        string $view,
    ): RecordQuerySpecification {
        $signedFields = null;
        $signedFilters = null;
        $signedSorts = null;
        foreach ($definition->views() as $candidate) {
            if ($candidate->handle === $view && $candidate->handler !== null && $candidate->schema !== null) {
                $signedFields = $candidate->fields;
                $signedFilters = $candidate->filters;
                $signedSorts = $candidate->sorts;
                break;
            }
        }
        if ($signedFields === null || $signedFilters === null || $signedSorts === null) {
            throw new BusinessRecordDefinitionUnavailable();
        }

        $requested = $specification->projection->fields;
        if (
            ($requested !== [] && array_diff($requested, $signedFields) !== [])
            || $specification->projection->includes !== []
        ) {
            throw new BusinessRecordDefinitionUnavailable();
        }
        foreach ($specification->projection->aggregates as $aggregate) {
            if ($aggregate->field !== null && !in_array($aggregate->field, $signedFields, true)) {
                throw new BusinessRecordDefinitionUnavailable();
            }
        }
        if (
            array_diff($this->customViewFilterFields($specification->filter?->toArray()), $signedFilters) !== []
            || ($specification->search !== null
                && array_diff($specification->search->fields, $signedFields) !== [])
        ) {
            throw new BusinessRecordDefinitionUnavailable();
        }
        foreach ($specification->sorts as $sort) {
            if (!in_array($sort->field, $signedSorts, true)) {
                throw new BusinessRecordDefinitionUnavailable();
            }
        }

        $policy = $this->surfaceSpecification($specification, $metadata);
        $fields = $requested === []
            ? array_values(array_intersect($signedFields, $policy->projection->fields))
            : $requested;
        if ($fields === []) {
            throw new BusinessRecordDefinitionUnavailable();
        }

        return new RecordQuerySpecification(
            $policy->filter,
            $policy->search,
            $policy->sorts,
            $policy->after,
            $policy->pageSize,
            new RecordProjection($fields, [], $policy->projection->aggregates),
            $policy->includeArchived,
            $policy->includeDeleted,
        );
    }

    /**
     * Read field handles from the closed custom-view filter tree.
     *
     * Relationship filters are refused: a view signs fields on its own definition, not a target-field
     * vocabulary for traversing another definition. The SDK query graph has no open node extension point.
     *
     * @param   array<mixed>|null  $node  Candidate canonical filter node, or null for no filter.
     *
     * @return  list<string>  Referenced root-definition field handles.
     *
     * @throws  BusinessRecordDefinitionUnavailable  When the filter node is malformed or traverses a relation.
     *
     * @since   2.0.0
     */
    private function customViewFilterFields(?array $node): array
    {
        if ($node === null) {
            return [];
        }
        $type = $node['type'] ?? null;
        if (in_array($type, ['comparison', 'text', 'set', 'null'], true)) {
            $field = $node['field'] ?? null;
            if (!is_string($field)) {
                throw new BusinessRecordDefinitionUnavailable();
            }

            return [$field];
        }
        if ($type !== 'boolean') {
            throw new BusinessRecordDefinitionUnavailable();
        }
        $children = $node['children'] ?? null;
        if (!is_array($children) || !array_is_list($children)) {
            throw new BusinessRecordDefinitionUnavailable();
        }
        $fields = [];
        foreach ($children as $child) {
            if (!is_array($child)) {
                throw new BusinessRecordDefinitionUnavailable();
            }
            $fields = [...$fields, ...$this->customViewFilterFields($child)];
        }

        return array_values(array_unique($fields));
    }

    /**
     * Intersect one projected record with its exact generated-surface metadata.
     *
     * Policy projection occurs first in the record layer. This final intersection handles definition-level
     * surface exposure, especially portal target opt-ins, without teaching the delivery-neutral record
     * repository about browser, CLI, REST, or MCP metadata.
     *
     * @param   array<string, mixed>  $record    Projected record document.
     * @param   array<string, mixed>  $metadata  Policy- and surface-filtered definition metadata.
     *
     * @return  array<string, mixed>  Record containing only catalog-visible values and includes.
     *
     * @since   2.0.0
     */
    private function metadataRecord(array $record, array $metadata): array
    {
        $values = $record['values'] ?? null;
        $includes = $record['includes'] ?? null;
        if (!is_array($values) || !is_array($includes)) {
            throw new InvalidArgumentException('A projected business-record document is invalid.');
        }
        $record['values'] = array_intersect_key(
            $values,
            array_fill_keys($this->metadataHandles($metadata, 'fields'), true),
        );
        $record['includes'] = array_intersect_key(
            $includes,
            array_fill_keys($this->metadataHandles($metadata, 'relationships'), true),
        );

        return $record;
    }

    /**
     * Read validated handles from one safe metadata collection.
     *
     * @param   array<string, mixed>  $metadata    Policy-filtered definition metadata.
     * @param   string                $collection  `fields` or `relationships`.
     *
     * @return  list<string>  Handles in canonical catalog order.
     *
     * @throws  InvalidArgumentException  When supposedly safe metadata has an invalid collection shape.
     *
     * @since   2.0.0
     */
    private function metadataHandles(array $metadata, string $collection): array
    {
        $items = $metadata[$collection] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            throw new InvalidArgumentException('A generated business metadata collection is invalid.');
        }
        $handles = [];
        foreach ($items as $item) {
            $handle = is_array($item) ? ($item['handle'] ?? null) : null;
            if (!is_string($handle)) {
                throw new InvalidArgumentException('A generated business metadata handle is invalid.');
            }
            $handles[] = $handle;
        }

        return $handles;
    }

    /**
     * List exact operations whose metadata survives exposure and policy filtering.
     *
     * @param   ExecutionContext  $context     Authenticated actor.
     * @param   BusinessSurface   $surface     Exact delivery boundary.
     * @param   string            $definition  Definition UUID or handle.
     *
     * @return  array<string, true>  Allowed operation values keyed to true for templates.
     *
     * @since   2.0.0
     */
    private function availableOperations(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
    ): array {
        return $this->catalog->operations($context, $surface, $definition);
    }

    /**
     * Require an action bulk attempt to name a policy-visible, directly executable bulk action.
     *
     * @param   array<string, mixed>  $metadata  Policy-filtered definition metadata for the action operation.
     * @param   BusinessBulkMutation  $request   Validated bulk request.
     *
     * @return  void
     *
     * @throws  BusinessRecordDefinitionUnavailable  When the action is absent, denied, not bulk-enabled,
     *          or requires a separate maker-checker approval for each record.
     *
     * @since   2.0.0
     */
    private function assertBulkAction(array $metadata, BusinessBulkMutation $request): void
    {
        if ($request->operation !== BusinessSurfaceOperation::Action) {
            return;
        }
        $actions = $metadata['actions'] ?? [];
        if (!is_array($actions)) {
            throw new BusinessRecordDefinitionUnavailable();
        }
        foreach ($actions as $action) {
            if (
                is_array($action)
                && ($action['handle'] ?? null) === $request->action
                && ($action['bulk'] ?? null) === true
                && ($action['high_impact'] ?? null) === false
            ) {
                return;
            }
        }

        throw new BusinessRecordDefinitionUnavailable();
    }

    /**
     * Derive organization scope only from the authenticated membership and declared scope mode.
     *
     * @param   ExecutionContext      $context   Authenticated actor and membership.
     * @param   array<string, mixed>  $metadata  Safe definition metadata.
     *
     * @return  string|null  Authenticated organization identifier when the definition requires one.
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

    /**
     * Produce semantic field presentations for one exact definition and context.
     *
     * @param   EntityTypeDefinition         $definition  Pinned declarative entity.
     * @param   array<string, mixed>         $metadata    Policy-filtered definition metadata.
     * @param   FieldModelContext            $context     Exact render or edit context.
     * @param   array<string, mixed>         $values      Disclosed or retained values.
     * @param   array<string, list<string>>  $errors      Caller-visible validation errors by handle.
     *
     * @return  list<array<string, mixed>>  Markup-free semantic field models.
     *
     * @since   2.0.0
     */
    private function present(
        EntityTypeDefinition $definition,
        array $metadata,
        FieldModelContext $context,
        array $values,
        array $errors = [],
    ): array {
        $allowed = [];
        foreach (
            self::objectDocuments(
                $metadata['fields'] ?? null,
                'Generated business field metadata is invalid.',
            ) as $fieldMetadata
        ) {
            $handle = $fieldMetadata['handle'] ?? null;
            if (!is_string($handle)) {
                throw new InvalidArgumentException('Generated business field metadata is invalid.');
            }
            $allowed[$handle] = $fieldMetadata;
        }
        $presented = [];
        foreach ($definition->fields() as $field) {
            if (!isset($allowed[$field->handle]) || !$this->conditionVisible($field, $values)) {
                continue;
            }
            $value = $values[$field->handle] ?? $field->default;
            $editable = $context->edits() && $this->conditionEditable($field, $values);
            $presentation = $this->presentations->present(
                $field,
                $this->fieldTypes->get($field->type),
                $context,
                $value,
                errors: $errors[$field->handle] ?? [],
                editable: $editable,
            )->model;
            $fieldMetadata = $allowed[$field->handle];
            $presented[] = [
                ...$presentation,
                'description' => $fieldMetadata['description'],
                'help_text' => $fieldMetadata['help_text'],
                'type' => $fieldMetadata['type'],
                'schema' => $fieldMetadata['schema'],
                'form_group' => $fieldMetadata['form_group'],
                'order' => $fieldMetadata['order'],
                'placements' => $fieldMetadata['placements'],
            ];
        }
        usort(
            $presented,
            static fn (array $left, array $right): int => [$left['order'], $left['handle']]
                <=> [$right['order'], $right['handle']],
        );

        return $presented;
    }

    /**
     * Present at most four disclosed target fields for a relationship selector.
     *
     * @param   EntityTypeDefinition  $definition  Target definition whose safe presenters are registered.
     * @param   array<string, mixed>  $values      Values already narrowed by related access.
     *
     * @return  list<array{handle: string, label: string, display: string, identity: bool,
     *          provenance: ?array<string, mixed>}>  Selector fields, each carrying conversion evidence when
     *          the value it shows is a converted amount and null when it is not.
     *
     * @since   2.0.0
     */
    private function relationChoiceFields(EntityTypeDefinition $definition, array $values): array
    {
        $fields = [];
        foreach ($definition->fields() as $field) {
            if (!array_key_exists($field->handle, $values)) {
                continue;
            }
            $presentation = $this->presentations->present(
                $field,
                $this->fieldTypes->get($field->type),
                FieldModelContext::Relation,
                $values[$field->handle],
                editable: false,
            );
            $fields[] = [
                'handle' => $field->handle,
                'label' => $field->labelIn($this->active->locale()),
                'display' => self::presentedText($presentation->display, $presentation->provenance),
                'identity' => in_array($field->type, ['core.uuid', 'core.reference_identity'], true),
                'provenance' => $presentation->provenance,
            ];
            if (count($fields) === 4) {
                break;
            }
        }

        return $fields;
    }

    /**
     * Add owner-aware human labels to one disclosure-filtered relationship page.
     *
     * The current target definition is resolved once for the focused section, never once per row. Each
     * already-disclosed value then passes through its registered relation presenter, so templates can offer
     * graphical order controls without exposing a free-text public-identity authoring surface.
     *
     * @param   ExecutionContext                  $context    Authenticated site and actor.
     * @param   string                            $target     Declared target definition handle.
     * @param   list<BusinessRecordRelationView>  $records    Disclosure-filtered included rows.
     * @param   list<array<string, mixed>>        $projected  Transport projections matching those rows.
     *
     * @return  list<array<string, mixed>>  Rows carrying a bounded label and relation-context fields.
     *
     * @since   2.0.0
     */
    private function presentIncludedRecords(
        ExecutionContext $context,
        string $target,
        array $records,
        array $projected,
    ): array {
        if (count($records) !== count($projected)) {
            throw new InvalidArgumentException('A generated relationship projection is inconsistent.');
        }
        if ($records === []) {
            return [];
        }
        $definition = $this->definitions->forCreate($context, $target)->definition;
        foreach ($records as $index => $record) {
            if (!($record instanceof BusinessRecordRelationView) || !isset($projected[$index])) {
                throw new InvalidArgumentException('A generated relationship row is invalid.');
            }
            $fields = $this->relationChoiceFields($definition, $record->values);
            $label = null;
            foreach ($fields as $field) {
                if ($field['display'] !== '' && $field['identity'] === false) {
                    $label = $field['display'];
                    break;
                }
            }
            $projected[$index]['label'] = self::choiceText($label ?? 'Unnamed record', 120);
            $projected[$index]['fields'] = array_map(
                static fn (array $field): array => [
                    'handle' => $field['handle'],
                    'label' => $field['label'],
                    'display' => $field['display'],
                    'provenance' => $field['provenance'],
                ],
                $fields,
            );
        }

        return array_values($projected);
    }

    /**
     * Project one hydrated owned-line collection onto the line definition's stable list columns.
     *
     * The columns come from the target definition's first generated list view, so a document body table
     * shows the same columns an operator curated for the line list rather than the first four declared
     * fields a selector would show. Every row carries a cell for every column: a value withheld by row
     * policy, or a column the caller cannot read, renders as an empty cell instead of collapsing the
     * table, which keeps one hundred rows scannable and column-stable.
     *
     * @param   ExecutionContext                  $context    Authenticated site and actor.
     * @param   string                            $target     Declared line definition handle.
     * @param   list<BusinessRecordRelationView>  $records    Disclosure-filtered included line rows.
     * @param   list<array<string, mixed>>        $projected  Transport projections matching those rows.
     *
     * @return  array{0: list<array{handle: string, label: string}>, 1: list<array<string, mixed>>}  Stable
     *          columns and the rows, each row carrying one presented cell per column under `cells`, and each
     *          cell carrying the conversion evidence behind its figure when that figure was converted.
     *
     * @since   2.0.0
     */
    private function presentDocumentLines(
        ExecutionContext $context,
        string $target,
        array $records,
        array $projected,
    ): array {
        if (count($records) !== count($projected)) {
            throw new InvalidArgumentException('A generated document line projection is inconsistent.');
        }
        $definition = $this->definitions->forCreate($context, $target)->definition;
        $fields = [];
        foreach ($definition->fields() as $field) {
            $fields[$field->handle] = $field;
        }
        $columns = [];
        foreach ($this->documentLineColumnHandles($definition) as $handle) {
            if (isset($fields[$handle]) && $fields[$handle]->type !== 'core.uuid') {
                $columns[] = [
                    'handle' => $handle,
                    'label' => $fields[$handle]->labelIn($this->active->locale()),
                ];
            }
        }
        foreach ($records as $index => $record) {
            if (!($record instanceof BusinessRecordRelationView) || !isset($projected[$index])) {
                throw new InvalidArgumentException('A generated document line row is invalid.');
            }
            $cells = [];
            foreach ($columns as $column) {
                $display = '';
                $provenance = null;
                if (array_key_exists($column['handle'], $record->values)) {
                    $presentation = $this->presentations->present(
                        $fields[$column['handle']],
                        $this->fieldTypes->get($fields[$column['handle']]->type),
                        FieldModelContext::Relation,
                        $record->values[$column['handle']],
                        editable: false,
                    );
                    $display = self::presentedText(
                        $presentation->display,
                        $presentation->provenance,
                    );
                    $provenance = $presentation->provenance;
                }
                $cells[] = [
                    'handle' => $column['handle'],
                    'display' => $display,
                    'provenance' => $provenance,
                ];
            }
            $projected[$index]['cells'] = $cells;
        }

        return [$columns, array_values($projected)];
    }

    /**
     * Choose the column handles a document line table projects for one line definition.
     *
     * @param   EntityTypeDefinition  $definition  Declared target line definition.
     *
     * @return  list<string>  The first generated list view's fields, or the first four declared non-UUID
     *          field handles when the definition declares no generated list view.
     *
     * @since   2.0.0
     */
    private function documentLineColumnHandles(EntityTypeDefinition $definition): array
    {
        foreach ($definition->views() as $view) {
            if ($view->kind === 'list' && $view->handler === null) {
                return $view->fields;
            }
        }
        $handles = [];
        foreach ($definition->fields() as $field) {
            if ($field->type === 'core.uuid') {
                continue;
            }
            $handles[] = $field->handle;
            if (count($handles) === 4) {
                break;
            }
        }

        return $handles;
    }

    /**
     * Read one presented value as the text a compact surface shows, without ever cutting provenance off.
     *
     * Ordinary presenter output is normalized and bounded, because a list cell and a selector line have
     * to stay scannable. A converted amount is exempt: its display is the whole of the evidence for the
     * figure, and an ellipsis through the middle of a rate or an as-at instant would turn an auditable
     * figure into an unverifiable one. Its portable form is already single-line canonical text, so
     * nothing is lost by passing it through unchanged.
     *
     * It takes the two values it actually reads rather than the presentation object they came from.
     * That keeps the application layer from naming a presentation type for the sake of a type hint,
     * which is a dependency pointing the wrong way for no benefit: this helper needs a string and the
     * presence or absence of provenance, and nothing else about how the value was presented.
     *
     * @param   string                 $display     Escaped text the presenter produced for the value.
     * @param   ?array<string, mixed>  $provenance  Conversion evidence when the figure was converted,
     *          or null when it was not; its mere presence is what exempts the text from truncation.
     *
     * @return  string  The bounded display text, or the untouched portable form of a converted amount.
     *
     * @since   2.0.0
     */
    private static function presentedText(string $display, ?array $provenance): string
    {
        if ($provenance !== null) {
            return $display;
        }

        return $display === '' ? '' : self::choiceText($display, 256);
    }

    /**
     * Normalize one human label to bounded, single-line valid UTF-8 text.
     *
     * @param   string  $value  Candidate label.
     * @param   int     $limit  Maximum byte length.
     *
     * @return  string  Single-line text no longer than the requested byte limit.
     *
     * @since   2.0.0
     */
    private static function choiceText(string $value, int $limit): string
    {
        $normalized = preg_replace('/[\x00-\x20\x7F]+/u', ' ', trim($value));
        $normalized = is_string($normalized) && $normalized !== '' ? $normalized : 'Unnamed record';
        if (strlen($normalized) <= $limit) {
            return $normalized;
        }
        $truncated = substr($normalized, 0, $limit - 3);
        while ($truncated !== '' && preg_match('//u', $truncated) !== 1) {
            $truncated = substr($truncated, 0, -1);
        }

        return rtrim($truncated) . '...';
    }

    /**
     * Prove one internal projection or catalog member is a string-keyed object.
     *
     * @param   mixed   $value    Candidate object value.
     * @param   string  $message  Stable exception message for a malformed trusted document.
     *
     * @return  array<string, mixed>  Validated object document.
     *
     * @throws  InvalidArgumentException  When the value is not an object map.
     *
     * @since   2.0.0
     */
    private static function objectDocument(mixed $value, string $message): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException($message);
        }
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException($message);
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Prove one internal projection or catalog collection is a list of object documents.
     *
     * @param   mixed   $value    Candidate list value.
     * @param   string  $message  Stable exception message for a malformed trusted collection.
     *
     * @return  list<array<string, mixed>>  Validated object documents.
     *
     * @throws  InvalidArgumentException  When the value is not a list of object maps.
     *
     * @since   2.0.0
     */
    private static function objectDocuments(mixed $value, string $message): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException($message);
        }
        $documents = [];
        foreach ($value as $document) {
            $documents[] = self::objectDocument($document, $message);
        }

        return $documents;
    }

    /**
     * Evaluate conditional visibility fail closed against currently known values.
     *
     * @param   FieldDefinition       $field   Candidate field.
     * @param   array<string, mixed>  $values  Current or retained values.
     *
     * @return  bool  True only when no condition exists or it evaluates exactly true.
     *
     * @since   2.0.0
     */
    private function conditionVisible(FieldDefinition $field, array $values): bool
    {
        if ($field->visibilityCondition === null) {
            return true;
        }
        try {
            return $field->visibilityCondition->evaluate(RecordExpressionValues::from($values)) === true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Evaluate conditional editability fail closed against currently known values.
     *
     * @param   FieldDefinition       $field   Candidate field.
     * @param   array<string, mixed>  $values  Current or retained values.
     *
     * @return  bool  True only when no condition exists or it evaluates exactly true.
     *
     * @since   2.0.0
     */
    private function conditionEditable(FieldDefinition $field, array $values): bool
    {
        if ($field->editabilityCondition === null) {
            return true;
        }
        try {
            return $field->editabilityCondition->evaluate(RecordExpressionValues::from($values)) === true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
