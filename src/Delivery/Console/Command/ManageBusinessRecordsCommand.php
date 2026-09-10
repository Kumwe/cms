<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Application\Automation\IdempotencyKey;
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
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordNotFound;
use Kumwe\App\BusinessRecord\Application\Query\BrowseRecordsQuery;
use Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordQueryPurpose;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\App\BusinessRecord\Application\Query\RecordHistoryQuery;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\App\BusinessSurface\Application\BusinessApprovalSurfaceService;
use Kumwe\App\BusinessSurface\Application\BusinessOperationStatusService;
use Kumwe\App\BusinessSurface\Application\BusinessRecordProjector;
use Kumwe\App\BusinessSurface\Application\BusinessRecordQueryFactory;
use Kumwe\App\BusinessSurface\Application\BusinessSurface;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceCatalog;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceOperation;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceService;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Throwable;

/**
 * Exposes the typed business-record runtime to an explicitly authorized host shell.
 *
 * This adapter owns no record rule or persistence behavior. It turns protected JSON documents into the
 * same application commands and bounded query tree used by every delivery surface, derives organization
 * scope only from the verified token context, and sends every result through one disclosure-safe presenter.
 * Stable JSON is written on both stdout and stderr so automation branches on codes instead of prose.
 *
 * @since  2.0.0
 */
final readonly class ManageBusinessRecordsCommand implements Command
{
    /**
     * Independent grants through which a scoped approval projection may be visible.
     *
     * @var    non-empty-list<string>
     * @since  2.0.0
     */
    private const APPROVAL_QUERY_CAPABILITIES = [
        'business.approval.request',
        'business.approval.approve',
        'business.approval.manage',
    ];

    /**
     * Closed operation grants a declared custom view kind can require.
     *
     * This is an authentication pre-flight only. `BusinessSurfaceService::customView()` derives the exact
     * operation from the signed view declaration and applies its exposure and record policy before dispatch.
     *
     * @var    non-empty-list<string>
     * @since  2.0.0
     */
    private const CUSTOM_VIEW_CAPABILITIES = [
        'business.record.browse',
        'business.record.read',
        'business.record.create',
        'business.record.update',
        'business.record.history',
        'business.record.relate',
    ];

    /**
     * Exact pre-flight capability required by each supported action.
     *
     * `BusinessRecordService` authorizes again against the canonical resource policy. Keeping this map at
     * the boundary prevents an under-scoped token from making definition or payload work happen first.
     *
     * @var    array<string, string|non-empty-list<string>>
     * @since  2.0.0
     */
    private const CAPABILITIES = [
        'entities' => 'business.record.browse',
        'schema' => 'business.record.read',
        'view' => self::CUSTOM_VIEW_CAPABILITIES,
        'list' => 'business.record.browse',
        'get' => 'business.record.read',
        'create' => 'business.record.create',
        'update' => 'business.record.update',
        'archive' => 'business.record.archive',
        'restore' => 'business.record.restore',
        'delete' => 'business.record.delete',
        'action' => 'business.record.action',
        'request-action' => 'business.record.action',
        'approvals' => self::APPROVAL_QUERY_CAPABILITIES,
        'approval' => self::APPROVAL_QUERY_CAPABILITIES,
        'history' => 'business.record.history',
        'relate' => 'business.record.relate',
        'unrelate' => 'business.record.relate',
        'reorder' => 'business.record.relate',
        'report' => 'business.record.report',
        'export' => 'business.record.export',
        'operation' => 'business.record.read',
    ];

    /**
     * Complete per-action option allow-list, excluding the two common authorization options.
     *
     * An option absent from this table is rejected rather than ignored. In particular there is no
     * `organization` option: organization authority comes only from `ExecutionContext::organization()`.
     *
     * @var    array<string, list<string>>
     * @since  2.0.0
     */
    private const ACTION_OPTIONS = [
        'entities' => [],
        'schema' => ['definition'],
        'view' => ['definition', 'view', 'query-file', 'parameters-file', 'record'],
        'list' => ['definition', 'query-file'],
        'get' => ['definition', 'record', 'query-file'],
        'create' => ['definition', 'values-file', 'operation-id', 'record'],
        'update' => ['definition', 'record', 'expected-version', 'values-file', 'operation-id'],
        'archive' => ['definition', 'record', 'expected-version', 'operation-id'],
        'restore' => ['definition', 'record', 'expected-version', 'operation-id'],
        'delete' => ['definition', 'record', 'expected-version', 'operation-id'],
        'action' => [
            'definition', 'record', 'expected-version', 'action', 'operation-id', 'input-file',
            'approval-request',
        ],
        'request-action' => [
            'definition', 'record', 'expected-version', 'action', 'operation-id', 'input-file',
        ],
        'approvals' => ['limit'],
        'approval' => ['approval-request'],
        'history' => ['definition', 'record', 'query-file'],
        'relate' => [
            'definition', 'record', 'expected-version', 'relationship', 'target-record', 'target-values-file',
            'position', 'operation-id',
        ],
        'unrelate' => [
            'definition', 'record', 'expected-version', 'relationship', 'target-record', 'operation-id',
        ],
        'reorder' => [
            'definition', 'record', 'expected-version', 'relationship', 'ordered-records-file', 'operation-id',
        ],
        'report' => ['definition', 'query-file'],
        'export' => ['definition', 'query-file'],
        'operation' => ['operation-id'],
    ];

    /**
     * Actions that must carry a caller-chosen replay identity.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const MUTATION_ACTIONS = [
        'create', 'update', 'archive', 'restore', 'delete', 'action', 'request-action', 'relate', 'unrelate',
        'reorder',
    ];

    /**
     * Actions bound to the optimistic version the operator observed.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const VERSIONED_ACTIONS = [
        'update', 'archive', 'restore', 'delete', 'action', 'request-action', 'relate', 'unrelate', 'reorder',
    ];

    /**
     * Wire the thin adapter to the canonical application runtime and its delivery mappers.
     *
     * @param  BusinessRecordService           $records        Shared transactional record service.
     * @param  BusinessSurfaceService          $surfaces       Shared custom-view and action dispatcher.
     * @param  BusinessSurfaceCatalog          $catalog        Policy-filtered generated metadata source.
     * @param  BusinessRecordQueryFactory      $queries        Transport-neutral bounded query compiler.
     * @param  BusinessRecordProjector         $projector      Shared omission-safe result projector.
     * @param  BusinessOperationStatusService  $operations     Caller-bound canonical operation lookup.
     * @param  BusinessApprovalSurfaceService  $approvals      Business-only live surface approval gate.
     * @param  ConsoleAuthorizer               $authorization  Protected-token verifier and pre-flight gate.
     * @param  BusinessRecordConsolePresenter  $presenter      Disclosure-safe stable JSON projector.
     * @param  BusinessConsoleFailureMapper    $failures       Stable error and exit-code mapper.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessRecordService $records,
        private BusinessSurfaceService $surfaces,
        private BusinessSurfaceCatalog $catalog,
        private BusinessRecordQueryFactory $queries,
        private BusinessRecordProjector $projector,
        private BusinessOperationStatusService $operations,
        private BusinessApprovalSurfaceService $approvals,
        private ConsoleAuthorizer $authorization,
        private BusinessRecordConsolePresenter $presenter,
        private BusinessConsoleFailureMapper $failures,
    ) {
    }

    /**
     * Name the console dispatcher registers this command under.
     *
     * @return  string  Always `business-record`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'business-record';
    }

    /**
     * Summarize the bounded record lifecycle this command exposes.
     *
     * @return  string  One-line command-list description.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.business_record.description';
    }

    /**
     * Parse, authorize and execute one business-record action.
     *
     * The action defaults to `entities`. Every option uses strict `--name=value` syntax, values and query
     * documents are read only from owner-protected files, and authorization happens before those business
     * documents are opened. Successful output goes to stdout; a mapped JSON failure goes to stderr with its
     * stable non-zero process status.
     *
     * @param   list<string>  $arguments  Action name followed by strict options.
     * @param   Output        $output     Separate stdout/stderr sink.
     *
     * @return  int  Zero on success, otherwise the stable mapped process exit code.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $action = array_shift($arguments) ?? 'entities';
            $capability = self::CAPABILITIES[$action]
                ?? throw new InvalidArgumentException('The business-record action is unsupported.');
            $options = CommandInput::options($arguments);
            $this->assertAllowedOptions($action, $options);
            $this->assertMutationContract($action, $options);
            $context = is_array($capability)
                ? $this->authorization->requireAny($options, $capability)
                : $this->authorization->require($options, $capability);
            $result = $this->dispatch($action, $options, $context);
            $output->line(CommandInput::render($this->presenter->success($action, $result)));

            return 0;
        } catch (Throwable $exception) {
            $failure = $this->failures->map($exception);
            $output->error(CommandInput::render($this->presenter->failure($failure)));

            return $failure->exitCode;
        }
    }

    /**
     * Route one authorized action to the shared application service.
     *
     * @param   string                 $action   Supported action selected by `execute()`.
     * @param   array<string, string>  $options  Strict option map already checked against its allow-list.
     * @param   ExecutionContext       $context  Verified actor, site and server-resolved membership scope.
     *
     * @return  array<string, mixed>  Disclosure-safe operation result ready for the success envelope.
     *
     * @throws  InvalidArgumentException  When an action-specific required option or protected document is invalid.
     *
     * @since   2.0.0
     */
    private function dispatch(string $action, array $options, ExecutionContext $context): array
    {
        if ($action === 'entities') {
            return ['items' => $this->catalog->definitions(
                $context,
                BusinessSurface::Cli,
                BusinessSurfaceOperation::Discover,
            )];
        }
        if ($action === 'operation') {
            return $this->operations->get($context, CommandInput::required($options, 'operation-id'));
        }
        if ($action === 'approvals') {
            $limit = isset($options['limit']) ? CommandInput::positiveInteger($options, 'limit') : 50;
            if ($limit > 100) {
                throw new InvalidArgumentException('The approval inbox limit exceeds one hundred.');
            }

            return $this->presenter->approvalInbox($this->approvals->businessInbox(
                $context,
                BusinessSurface::Cli,
                $limit,
            ));
        }
        if ($action === 'approval') {
            $approval = $this->approvals->businessDetail(
                $context,
                BusinessSurface::Cli,
                CommandInput::required($options, 'approval-request'),
            );
            if ($approval === null) {
                throw new BusinessRecordNotFound();
            }

            return $this->presenter->approvalDetail($approval);
        }

        $definition = CommandInput::required($options, 'definition');
        if ($action === 'view') {
            return $this->surfaces->customView(
                $context,
                BusinessSurface::Cli,
                $definition,
                CommandInput::required($options, 'view'),
                $this->protectedObject($options, 'query-file'),
                $this->protectedObject($options, 'parameters-file'),
                isset($options['record']) ? CommandInput::required($options, 'record') : null,
            );
        }
        $metadata = $this->catalog->definition(
            $context,
            BusinessSurface::Cli,
            $definition,
            self::surfaceOperation($action),
        );
        $organization = self::organization($context, $metadata);

        return match ($action) {
            'schema' => $metadata,
            'list' => $this->browse(
                $options,
                $context,
                $definition,
                $organization,
                BusinessRecordQueryPurpose::Browse,
            ),
            'report' => $this->browse(
                $options,
                $context,
                $definition,
                $organization,
                BusinessRecordQueryPurpose::Report,
            ),
            'export' => $this->browse(
                $options,
                $context,
                $definition,
                $organization,
                BusinessRecordQueryPurpose::Export,
            ),
            'get' => $this->read($options, $context, $definition, $organization),
            'create' => $this->projector->mutation($this->records->create(new CreateRecordCommand(
                $context,
                $definition,
                $this->protectedObject($options, 'values-file', true),
                $this->operationId($options),
                $organization,
                isset($options['record']) ? CommandInput::required($options, 'record') : null,
            ))),
            'update' => $this->projector->mutation($this->records->update(new UpdateRecordCommand(
                $context,
                $definition,
                CommandInput::required($options, 'record'),
                CommandInput::positiveInteger($options, 'expected-version'),
                $this->protectedObject($options, 'values-file', true),
                $this->operationId($options),
                $organization,
            ))),
            'archive' => $this->projector->mutation($this->records->archive(new ArchiveRecordCommand(
                $context,
                $definition,
                CommandInput::required($options, 'record'),
                CommandInput::positiveInteger($options, 'expected-version'),
                $this->operationId($options),
                $organization,
            ))),
            'restore' => $this->projector->mutation($this->records->restore(new RestoreRecordCommand(
                $context,
                $definition,
                CommandInput::required($options, 'record'),
                CommandInput::positiveInteger($options, 'expected-version'),
                $this->operationId($options),
                $organization,
            ))),
            'delete' => $this->projector->mutation($this->records->delete(new DeleteRecordCommand(
                $context,
                $definition,
                CommandInput::required($options, 'record'),
                CommandInput::positiveInteger($options, 'expected-version'),
                $this->operationId($options),
                $organization,
            ))),
            'action' => $this->surfaces->action(
                $context,
                BusinessSurface::Cli,
                $definition,
                CommandInput::required($options, 'record'),
                CommandInput::positiveInteger($options, 'expected-version'),
                CommandInput::required($options, 'action'),
                $this->operationId($options)->value(),
                $this->protectedObject($options, 'input-file'),
                isset($options['approval-request'])
                    ? CommandInput::required($options, 'approval-request')
                    : null,
            ),
            'request-action' => $this->presenter->approvalRequest(
                $this->surfaces->requestActionApproval(
                    $context,
                    BusinessSurface::Cli,
                    $definition,
                    CommandInput::required($options, 'record'),
                    CommandInput::positiveInteger($options, 'expected-version'),
                    CommandInput::required($options, 'action'),
                    $this->operationId($options)->value(),
                    $this->protectedObject($options, 'input-file'),
                )['approval_request_id'],
            ),
            'history' => $this->history($options, $context, $definition, $organization),
            'relate' => $this->projector->mutation($this->records->relate(new RelateRecordsCommand(
                $context,
                $definition,
                CommandInput::required($options, 'record'),
                CommandInput::positiveInteger($options, 'expected-version'),
                CommandInput::required($options, 'relationship'),
                CommandInput::required($options, 'target-record'),
                $this->operationId($options),
                isset($options['position'])
                    ? CommandInput::nonNegativeInteger($options, 'position', 1_000_000)
                    : null,
                $organization,
                $this->protectedObject($options, 'target-values-file'),
            ))),
            'unrelate' => $this->projector->mutation($this->records->unrelate(new UnrelateRecordsCommand(
                $context,
                $definition,
                CommandInput::required($options, 'record'),
                CommandInput::positiveInteger($options, 'expected-version'),
                CommandInput::required($options, 'relationship'),
                CommandInput::required($options, 'target-record'),
                $this->operationId($options),
                $organization,
            ))),
            'reorder' => $this->projector->mutation($this->records->reorder(new ReorderRecordLinesCommand(
                $context,
                $definition,
                CommandInput::required($options, 'record'),
                CommandInput::positiveInteger($options, 'expected-version'),
                CommandInput::required($options, 'relationship'),
                CommandInput::protectedJsonStringList(CommandInput::required($options, 'ordered-records-file')),
                $this->operationId($options),
                $organization,
            ))),
            default => throw new InvalidArgumentException('The business-record action is unsupported.'),
        };
    }

    /**
     * Execute one bounded browse under its exact disclosure purpose.
     *
     * @param   array<string, string>       $options       Validated option map.
     * @param   ExecutionContext            $context       Authorized execution context.
     * @param   string                      $definition    Policy-visible definition UUID or handle.
     * @param   ?string                     $organization  Server-resolved organization only.
     * @param   BusinessRecordQueryPurpose  $purpose       Browse, report or export disclosure path.
     *
     * @return  array<string, mixed>  Presented page, cursor and aggregates.
     *
     * @since   2.0.0
     */
    private function browse(
        array $options,
        ExecutionContext $context,
        string $definition,
        ?string $organization,
        BusinessRecordQueryPurpose $purpose,
    ): array {
        $document = $this->protectedObject($options, 'query-file');

        return $this->projector->browse($this->records->browse(new BrowseRecordsQuery(
            $context,
            $definition,
            $this->queries->create($document),
            $organization,
            $purpose,
        )));
    }

    /**
     * Execute one record read using an optional protected read-query document.
     *
     * @param   array<string, string>  $options       Validated option map.
     * @param   ExecutionContext       $context       Authorized execution context.
     * @param   string                 $definition    Policy-visible definition UUID or handle.
     * @param   ?string                $organization  Server-resolved organization only.
     *
     * @return  array<string, mixed>  Presented disclosure-safe record.
     *
     * @throws  InvalidArgumentException  When the read-query document has unknown or misshapen members.
     *
     * @since   2.0.0
     */
    private function read(
        array $options,
        ExecutionContext $context,
        string $definition,
        ?string $organization,
    ): array {
        $document = $this->protectedObject($options, 'query-file');
        $specification = $this->readSpecification($document);

        return $this->projector->record($this->records->read(new ReadRecordQuery(
            $context,
            $definition,
            CommandInput::required($options, 'record'),
            $organization,
            $specification->projection->fields,
            $specification->includeArchived,
            $specification->includeDeleted,
            $specification->projection->includes,
        )));
    }

    /**
     * Compile the single-record subset of the shared bounded query grammar.
     *
     * @param   array<string, mixed>  $document  Protected read-query document.
     *
     * @return  RecordQuerySpecification  Validated fields, includes and lifecycle switches.
     *
     * @throws  InvalidArgumentException  When browse-only or aggregate query members are supplied.
     *
     * @since   2.0.0
     */
    private function readSpecification(array $document): RecordQuerySpecification
    {
        self::assertDocumentKeys($document, ['projection', 'include_archived', 'include_deleted'], 'read query');
        $specification = $this->queries->create($document);
        if ($specification->projection->aggregates !== []) {
            throw new InvalidArgumentException('A single-record read cannot request aggregates.');
        }

        return $specification;
    }

    /**
     * Execute one bounded history read using an optional protected paging document.
     *
     * @param   array<string, string>  $options       Validated option map.
     * @param   ExecutionContext       $context       Authorized execution context.
     * @param   string                 $definition    Policy-visible definition UUID or handle.
     * @param   ?string                $organization  Server-resolved organization only.
     *
     * @return  array<string, mixed>  Presented revision page.
     *
     * @throws  InvalidArgumentException  When the history document has unknown or misshapen members.
     *
     * @since   2.0.0
     */
    private function history(
        array $options,
        ExecutionContext $context,
        string $definition,
        ?string $organization,
    ): array {
        $document = $this->protectedObject($options, 'query-file');
        self::assertDocumentKeys($document, ['limit', 'before_version'], 'history query');

        return $this->projector->history($this->records->history(new RecordHistoryQuery(
            $context,
            $definition,
            CommandInput::required($options, 'record'),
            $organization,
            self::positiveDocumentInteger($document, 'limit', 100, 200),
            array_key_exists('before_version', $document)
                ? self::positiveDocumentInteger($document, 'before_version', 1, PHP_INT_MAX)
                : null,
        )));
    }

    /**
     * Parse the mandatory replay identity carried by every mutation action.
     *
     * @param   array<string, string>  $options  Validated option map.
     *
     * @return  IdempotencyKey  Application-layer replay key.
     *
     * @since   2.0.0
     */
    private function operationId(array $options): IdempotencyKey
    {
        return IdempotencyKey::fromString(CommandInput::required($options, 'operation-id'));
    }

    /**
     * Derive the optional organization identifier only when the trusted definition scope requires it.
     *
     * A token may carry organization membership while operating on installation- or site-scoped records.
     * Passing that membership into those record commands is invalid, so metadata—not mere context presence—
     * decides whether the server-resolved identifier joins the command.
     *
     * @param   ExecutionContext      $context   Verified actor and optional server-resolved membership.
     * @param   array<string, mixed>  $metadata  Trusted policy-filtered definition metadata.
     *
     * @return  ?string  Context organization for organization scopes, otherwise null.
     *
     * @since   2.0.0
     */
    private static function organization(ExecutionContext $context, array $metadata): ?string
    {
        return in_array(
            $metadata['scope'] ?? null,
            [ScopeMode::Organization->value, ScopeMode::SiteOrganization->value],
            true,
        ) ? $context->organization()?->identifier() : null;
    }

    /**
     * Load an optional or required protected JSON object.
     *
     * @param   array<string, string>  $options   Validated option map.
     * @param   string                 $name      File option without the leading `--`.
     * @param   bool                   $required  Whether absence is an invocation error.
     *
     * @return  array<string, mixed>  Decoded object, or an empty object when optional and absent.
     *
     * @since   2.0.0
     */
    private function protectedObject(array $options, string $name, bool $required = false): array
    {
        if (!$required && !isset($options[$name])) {
            return [];
        }

        return CommandInput::protectedJsonObject(CommandInput::required($options, $name));
    }

    /**
     * Refuse any option the selected action does not explicitly understand.
     *
     * @param   string                 $action   Supported command action.
     * @param   array<string, string>  $options  Parsed option names and values.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When an unknown option is present.
     *
     * @since   2.0.0
     */
    private function assertAllowedOptions(string $action, array $options): void
    {
        $allowed = array_merge(['site', 'token-file'], self::ACTION_OPTIONS[$action] ?? []);
        if (array_diff(array_keys($options), $allowed) !== []) {
            throw new InvalidArgumentException('The business-record action contains an unsupported option.');
        }
    }

    /**
     * Require replay, status, and optimistic version identities before any business metadata is resolved.
     *
     * @param   string                 $action   Supported command action.
     * @param   array<string, string>  $options  Parsed option names and values.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a mutation or lookup omits its identity or observed version.
     *
     * @since   2.0.0
     */
    private function assertMutationContract(string $action, array $options): void
    {
        if (in_array($action, self::MUTATION_ACTIONS, true) || $action === 'operation') {
            CommandInput::required($options, 'operation-id');
        }
        if (in_array($action, self::VERSIONED_ACTIONS, true)) {
            CommandInput::positiveInteger($options, 'expected-version');
        }
    }

    /**
     * Bind each command action to the exact policy-filtered metadata operation it consumes.
     *
     * @param   string  $action  Supported command action.
     *
     * @return  BusinessSurfaceOperation  Shared generated-surface operation.
     *
     * @throws  InvalidArgumentException  When the action is outside the closed command vocabulary.
     *
     * @since   2.0.0
     */
    private static function surfaceOperation(string $action): BusinessSurfaceOperation
    {
        return match ($action) {
            'entities' => BusinessSurfaceOperation::Discover,
            'schema', 'get' => BusinessSurfaceOperation::Read,
            'list' => BusinessSurfaceOperation::Browse,
            'create' => BusinessSurfaceOperation::Create,
            'update' => BusinessSurfaceOperation::Update,
            'archive' => BusinessSurfaceOperation::Archive,
            'restore' => BusinessSurfaceOperation::Restore,
            'delete' => BusinessSurfaceOperation::Delete,
            'action' => BusinessSurfaceOperation::Action,
            'request-action' => BusinessSurfaceOperation::Approval,
            'history' => BusinessSurfaceOperation::History,
            'relate', 'unrelate' => BusinessSurfaceOperation::Relation,
            'reorder' => BusinessSurfaceOperation::Reorder,
            'report' => BusinessSurfaceOperation::Report,
            'export' => BusinessSurfaceOperation::Export,
            default => throw new InvalidArgumentException('The business-record action is unsupported.'),
        };
    }

    /**
     * Refuse unknown members in one command-specific protected document.
     *
     * @param   array<string, mixed>  $document  Decoded object being checked.
     * @param   list<string>          $allowed   Complete property allow-list.
     * @param   string                $kind      Safe document name used only by developer diagnostics.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When an unknown member is present.
     *
     * @since   2.0.0
     */
    private static function assertDocumentKeys(array $document, array $allowed, string $kind): void
    {
        if (array_diff(array_keys($document), $allowed) !== []) {
            throw new InvalidArgumentException('A business-record ' . $kind . ' contains an unknown property.');
        }
    }

    /**
     * Read a positive bounded integer from a protected document without coercion.
     *
     * @param   array<string, mixed>  $document  Decoded document.
     * @param   string                $key       Member name.
     * @param   int                   $default   Value used when the member is absent.
     * @param   int                   $maximum   Largest accepted value.
     *
     * @return  int  Declared or default integer within the bound.
     *
     * @throws  InvalidArgumentException  When the value is not an integer within the range.
     *
     * @since   2.0.0
     */
    private static function positiveDocumentInteger(
        array $document,
        string $key,
        int $default,
        int $maximum,
    ): int {
        $value = $document[$key] ?? $default;
        if (!is_int($value) || $value < 1 || $value > $maximum) {
            throw new InvalidArgumentException('A business-record document integer is outside its bound.');
        }

        return $value;
    }
}
