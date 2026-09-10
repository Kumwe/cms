<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessSurface;

use ArrayObject;
use DateTimeImmutable;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordVersionConflict;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\App\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\App\BusinessSurface\Application\BusinessBulkMutation;
use Kumwe\App\BusinessSurface\Application\BusinessOperationStatusService;
use Kumwe\App\BusinessSurface\Application\BusinessSurface;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceOperation;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceService;
use Kumwe\App\BusinessSurface\Delivery\Administrator\AdministratorBusinessSurfaceHandler;
use Kumwe\App\BusinessSurface\Delivery\Browser\GeneratedBusinessBrowserController;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Identity\Application\Administration\AdministratorSession;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Localization\Application\ActiveLocale;
use Kumwe\App\Localization\Domain\LocaleTag;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessActionCommand;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessActionDeclaration;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessActionHandler;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessActionResult;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessViewDeclaration;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessViewHandler;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessViewQuery;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessViewResult;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;

/**
 * Exercises generated browser delivery against the installed business-record runtime.
 *
 * @since  2.0.0
 */
#[CoversClass(GeneratedBusinessBrowserController::class)]
#[CoversClass(AdministratorBusinessSurfaceHandler::class)]
#[CoversClass(BusinessSurfaceService::class)]
#[CoversClass(BusinessOperationStatusService::class)]
final class GeneratedBusinessBrowserIntegrationTest extends TestCase
{
    /**
     * A real generated administrator response renders definition wording in the active locale.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGeneratedAdministratorHtmlRendersLocalizedDefinitionLabels(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $principal = $context->principal();
        self::assertInstanceOf(AuthenticatedPrincipal::class, $principal);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $document = NeutralBusinessFixture::document('locale' . $suffix, Uuid::uuid7()->toString());
        $document['label_translations'] = [
            'singular_label' => ['de' => 'Übersetzter Datensatz'],
            'plural_label' => ['de' => 'Übersetzte Datensätze'],
        ];
        $document['fields'][1]['text_translations'] = [
            'label' => ['de' => 'Übersetzter Name'],
        ];
        $definition = NeutralBusinessFixture::install($container, $context, $document);
        $handler = $container->get(AdministratorBusinessSurfaceHandler::class);
        $active = $container->get(ActiveLocale::class);
        self::assertInstanceOf(AdministratorBusinessSurfaceHandler::class, $handler);
        self::assertInstanceOf(ActiveLocale::class, $active);
        $request = (new ServerRequestFactory())
            ->createServerRequest(
                'GET',
                'https://kumwe.test/administrator/business/' . rawurlencode($definition->handle),
            )
            ->withAttribute('definition', $definition->handle)
            ->withAttribute(ExecutionContextAttribute::NAME, $context)
            ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, new AdministratorSession(
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb398',
                $principal,
                'generated-localization-csrf',
                new DateTimeImmutable('+1 hour'),
            ));

        $active->begin(LocaleTag::fromString('de'));
        try {
            $response = $handler->handle($request);
        } finally {
            $active->end();
        }

        $body = (string) $response->getBody();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<html lang="de" dir="ltr">', $body);
        self::assertStringContainsString('Übersetzte Datensätze', $body);
        self::assertStringContainsString('Übersetzter Name', $body);
    }

    /**
     * A browser denial remains a page while a machine caller keeps the non-enumerating problem document.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnavailableGeneratedAdministratorDefinitionNegotiatesTheDenialRepresentation(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $administrator = TestKernelFactory::administratorContext($container);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $definition = NeutralBusinessFixture::install(
            $container,
            $administrator,
            NeutralBusinessFixture::document('denial' . $suffix, Uuid::uuid7()->toString()),
        );
        $limitedSource = TestKernelFactory::contextFromGrantRows($container, [[
            'capability' => 'administrator.access',
            'scope_type' => 'global',
            'scope_identifier' => null,
        ]]);
        $principal = $limitedSource->principal();
        self::assertInstanceOf(AuthenticatedPrincipal::class, $principal);
        $limited = $principal->context(
            $limitedSource->site(),
            AuthenticationStrength::Password,
            'integration-generated-denial-' . bin2hex(random_bytes(8)),
            surface: AuthenticatedSurface::Administrator,
            sessionId: '018f22e2-7c8b-7ab0-8f3a-88e8026bb399',
        );
        $handler = $container->get(AdministratorBusinessSurfaceHandler::class);
        self::assertInstanceOf(AdministratorBusinessSurfaceHandler::class, $handler);
        $request = (new ServerRequestFactory())
            ->createServerRequest(
                'GET',
                'https://kumwe.test/administrator/business/' . rawurlencode($definition->handle),
            )
            ->withHeader('Accept', 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8')
            ->withAttribute('definition', $definition->handle)
            ->withAttribute(ExecutionContextAttribute::NAME, $limited)
            ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, new AdministratorSession(
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb399',
                $principal,
                'generated-denial-csrf',
                new DateTimeImmutable('+1 hour'),
            ));

        $page = $handler->handle($request);
        $pageBody = (string) $page->getBody();
        self::assertSame(403, $page->getStatusCode());
        self::assertStringContainsString('text/html', $page->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $page->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('The requested business workspace does not exist', $pageBody);
        self::assertStringNotContainsString($definition->handle, $pageBody);

        $problem = $handler->handle($request->withHeader('Accept', 'application/json'));
        $problemBody = json_decode((string) $problem->getBody(), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame(403, $problem->getStatusCode());
        self::assertSame('application/problem+json', $problem->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $problem->getHeaderLine('Cache-Control'));
        self::assertSame('urn:kumwe:problem:authorization-denied', $problemBody['type'] ?? null);
        self::assertStringNotContainsString($definition->handle, (string) $problem->getBody());
    }

    /**
     * Proves lifecycle mutations redirect only to records their resulting state can disclose.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testArchiveAndDeleteRedirectToLifecycleReadableDestinations(): void
    {
        [$context, $definition, $records, $browser, $suffix] = $this->runtime('redirects');
        $recordId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $definition,
            NeutralBusinessFixture::recordValues('Browser lifecycle redirect'),
            NeutralBusinessFixture::idempotencyKey('browser-redirect-create-' . $suffix),
            recordId: $recordId,
        ));

        $archive = $browser->dispatch(
            $context,
            BusinessSurface::Administrator,
            '/administrator/business',
            'POST',
            $definition,
            $recordId,
            [],
            [
                'operation' => 'archive',
                'operation_id' => 'browser:redirect-archive-' . $suffix,
                'expected_version' => '1',
                'confirmed' => '1',
            ],
        );
        self::assertSame(
            '/administrator/business/' . $definition . '/' . $recordId
                . '?archived=1&saved=1&completed_operation=browser%3Aredirect-archive-' . $suffix,
            $archive->redirect,
        );
        $archived = $browser->dispatch(
            $context,
            BusinessSurface::Administrator,
            '/administrator/business',
            'GET',
            $definition,
            $recordId,
            ['archived' => '1'],
            [],
        );
        self::assertSame('business-detail', $archived->template);
        self::assertSame(2, $archived->data['record']['version']);
        self::assertSame('summary', $archived->data['record_task']);
        $actions = $browser->dispatch(
            $context,
            BusinessSurface::Administrator,
            '/administrator/business',
            'GET',
            $definition,
            $recordId,
            ['archived' => '1', 'task' => 'actions'],
            [],
        );
        self::assertSame('actions', $actions->data['record_task']);
        $status = $browser->operationStatus($context, 'browser:redirect-archive-' . $suffix);
        self::assertSame(200, $status->status);
        self::assertSame($definition, $status->data['operation_status']['definition_reference']);

        $restore = $browser->dispatch(
            $context,
            BusinessSurface::Administrator,
            '/administrator/business',
            'POST',
            $definition,
            $recordId,
            [],
            [
                'operation' => 'restore',
                'operation_id' => 'browser:redirect-restore-' . $suffix,
                'expected_version' => '2',
                'confirmed' => '1',
            ],
        );
        self::assertStringStartsWith(
            '/administrator/business/' . $definition . '/' . $recordId . '?saved=1',
            (string) $restore->redirect,
        );

        $delete = $browser->dispatch(
            $context,
            BusinessSurface::Administrator,
            '/administrator/business',
            'POST',
            $definition,
            $recordId,
            [],
            [
                'operation' => 'delete',
                'operation_id' => 'browser:redirect-delete-' . $suffix,
                'expected_version' => '3',
                'confirmed' => '1',
            ],
        );
        self::assertSame(
            '/administrator/business/' . $definition
                . '?saved=1&completed_operation=browser%3Aredirect-delete-' . $suffix,
            $delete->redirect,
        );
        self::assertSame('business-list', $browser->dispatch(
            $context,
            BusinessSurface::Administrator,
            '/administrator/business',
            'GET',
            $definition,
            null,
            ['saved' => '1', 'completed_operation' => 'browser:redirect-delete-' . $suffix],
            [],
        )->template);
    }

    /**
     * Proves graphical filters and sorts remain encoded in every opaque cursor link.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testGraphicalQueryControlsSurviveOpaqueCursorPagination(): void
    {
        [$context, $definition, $records, $browser, $suffix] = $this->runtime('query');
        foreach (['Browser query alpha', 'Browser query beta', 'Browser query gamma'] as $index => $name) {
            $records->create(new CreateRecordCommand(
                $context,
                $definition,
                NeutralBusinessFixture::recordValues($name),
                NeutralBusinessFixture::idempotencyKey('browser-query-create-' . $index . '-' . $suffix),
                recordId: Uuid::uuid7()->toString(),
            ));
        }

        $first = $browser->dispatch(
            $context,
            BusinessSurface::Administrator,
            '/administrator/business',
            'GET',
            $definition,
            null,
            [
                'filters' => ['status' => 'draft'],
                'sort_field' => 'name',
                'sort_direction' => 'asc',
                'search_term' => 'Browser query',
                'search_fields' => ['name'],
                'page_size' => '1',
                'columns' => ['status', 'name'],
                'density' => 'compact',
                'representation' => 'cards',
            ],
            [],
        );
        self::assertSame('business-list', $first->template);
        self::assertCount(1, $first->data['items']);
        self::assertIsString($first->data['next_query']);
        $nextDocument = json_decode($first->data['next_query'], true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('draft', $nextDocument['filter']['value']);
        self::assertSame('Browser query', $nextDocument['search']['term']);
        self::assertSame('asc', $nextDocument['sorts'][0]['direction']);
        self::assertArrayHasKey('after', $nextDocument);
        self::assertSame(['status', 'name'], $first->data['collection_presentation']['columns']);
        self::assertSame('compact', $first->data['collection_presentation']['density']);
        self::assertSame('cards', $first->data['collection_presentation']['representation']);
        self::assertSame(
            ['status', 'name'],
            array_column($first->data['visible_columns'], 'handle'),
        );
        self::assertStringContainsString('density=compact', $first->data['presentation_query']);

        $second = $browser->dispatch(
            $context,
            BusinessSurface::Administrator,
            '/administrator/business',
            'GET',
            $definition,
            null,
            [
                'query' => $first->data['next_query'],
                'columns' => ['status', 'name'],
                'density' => 'compact',
                'representation' => 'cards',
            ],
            [],
        );
        self::assertCount(1, $second->data['items']);
        self::assertNotSame($first->data['items'][0]['record_id'], $second->data['items'][0]['record_id']);
        self::assertSame(['status' => 'draft'], $second->data['query_state']['filters']);
    }

    /**
     * Proves a declared workflow action is confirmed and executed through the native browser lifecycle.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testWorkflowActionConfirmationExecutesWithoutJavascript(): void
    {
        [$context, $definition, $records, $browser, $suffix] = $this->runtime('action');
        $recordId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $definition,
            NeutralBusinessFixture::recordValues('Browser action'),
            NeutralBusinessFixture::idempotencyKey('browser-action-create-' . $suffix),
            recordId: $recordId,
        ));

        $confirmation = $browser->dispatch(
            $context,
            BusinessSurface::Administrator,
            '/administrator/business',
            'GET',
            $definition,
            $recordId,
            ['confirm' => 'action', 'action' => 'approve'],
            [],
        );
        self::assertSame('business-confirm', $confirmation->template);
        self::assertSame('approve', $confirmation->data['action_handle']);
        self::assertSame([], $confirmation->data['action_fields']);

        $result = $browser->dispatch(
            $context,
            BusinessSurface::Administrator,
            '/administrator/business',
            'POST',
            $definition,
            $recordId,
            [],
            [
                'operation' => 'action',
                'operation_id' => 'browser:workflow-action-' . $suffix,
                'expected_version' => '1',
                'confirmed' => '1',
                'action' => 'approve',
            ],
        );
        self::assertNotNull($result->redirect);
        self::assertSame('approved', $records->read(new ReadRecordQuery(
            $context,
            $definition,
            $recordId,
        ))->workflowState);
    }

    /**
     * Proves a declared custom view executes with schema-driven native browser parameters.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testCustomViewExecutesThroughSchemaDrivenFixedBrowserModel(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $handlerReference = 'site.default.views.browser_summary_' . $suffix;
        $schemaReference = 'site.default.schemas.browser_summary_' . $suffix;
        $contract = CustomBusinessViewDeclaration::fromManifest([
            'handler' => $handlerReference,
            'schema' => $schemaReference,
            'query_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'term' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 40],
                ],
                'required' => ['term'],
            ],
            'result_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'title' => ['type' => 'string', 'maxLength' => 120],
                    'items' => [
                        'type' => 'array',
                        'maxItems' => 5,
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'label' => ['type' => 'string', 'maxLength' => 120],
                            ],
                            'required' => ['label'],
                        ],
                    ],
                ],
                'required' => ['title', 'items'],
            ],
        ]);
        $registries = $container->get(ExtensionContributionRegistrySet::class);
        self::assertInstanceOf(ExtensionContributionRegistrySet::class, $registries);
        $observedQueries = new ArrayObject();
        $registries->customBusinessViewHandlers()->register(
            DefinitionOwner::site('default'),
            $contract,
            new class ($observedQueries) implements CustomBusinessViewHandler {
                /**
                 * Retain the exact immutable queries that reached extension code.
                 *
                 * @param  ArrayObject<int, mixed>  $observed  Callback-capture ledger.
                 *
                 * @since  2.0.0
                 */
                public function __construct(private ArrayObject $observed)
                {
                }

                /**
                 * Project one submitted term through the registered custom view contract.
                 *
                 * @param   CustomBusinessViewQuery  $query  Typed custom view query.
                 *
                 * @return  CustomBusinessViewResult  Schema-valid fixed browser result.
                 *
                 * @throws  \InvalidArgumentException  When the test asks the handler to expose a secret.
                 *
                 * @since  2.0.0
                 */
                public function handle(CustomBusinessViewQuery $query): CustomBusinessViewResult
                {
                    $this->observed->append($query->records);
                    if ($query->parameters['term'] === 'trigger-failure') {
                        throw new \InvalidArgumentException(
                            'Extension database password: should-never-reach-the-browser.',
                        );
                    }

                    return new CustomBusinessViewResult([
                        'title' => 'Browser summary',
                        'items' => [['label' => 'Matched ' . $query->parameters['term']]],
                    ]);
                }
            },
        );
        $document = NeutralBusinessFixture::document('custom' . $suffix, Uuid::uuid7()->toString());
        $document['views'][] = [
            'handle' => 'browser_summary',
            'label' => 'Browser summary',
            'kind' => 'list',
            'fields' => ['name', 'status'],
            'filters' => ['status'],
            'sorts' => ['name'],
            'administrator' => true,
            'portal' => false,
            'public' => false,
            'handler' => $handlerReference,
            'schema' => $schemaReference,
        ];
        $definition = NeutralBusinessFixture::install($container, $context, $document);
        $browser = $container->get(GeneratedBusinessBrowserController::class);
        self::assertInstanceOf(GeneratedBusinessBrowserController::class, $browser);

        $form = $browser->customView(
            $context,
            BusinessSurface::Administrator,
            $definition->handle,
            'browser_summary',
            null,
            [],
        );
        self::assertSame('business-custom-view', $form->template);
        self::assertFalse($form->data['custom_view_submitted']);
        self::assertSame('term', $form->data['parameter_fields'][0]['handle']);

        $result = $browser->customView(
            $context,
            BusinessSurface::Administrator,
            $definition->handle,
            'browser_summary',
            null,
            [
                'run' => '1',
                'parameters' => ['term' => 'north'],
                'filters' => ['status' => 'draft'],
                'sort_field' => 'name',
                'sort_direction' => 'asc',
            ],
        );
        self::assertSame(200, $result->status);
        self::assertTrue($result->data['custom_view_submitted']);
        self::assertSame('Browser summary', $result->data['data_projection']['entries'][0]['value']['value']);
        self::assertSame(
            'Matched north',
            $result->data['data_projection']['entries'][1]['value']['items'][0]
                ['value']['entries'][0]['value']['value'],
        );
        self::assertCount(1, $observedQueries);
        $observedQuery = $observedQueries[0] ?? null;
        self::assertInstanceOf(RecordQuerySpecification::class, $observedQuery);
        self::assertSame(['name', 'status'], $observedQuery->projection->fields);

        $surface = $container->get(BusinessSurfaceService::class);
        self::assertInstanceOf(BusinessSurfaceService::class, $surface);
        $metadata = $surface->customViewMetadata(
            $context,
            BusinessSurface::Administrator,
            $definition->handle,
            'browser_summary',
        );
        self::assertContains(
            'amount',
            array_column($metadata['definition']['fields'], 'handle'),
            'The hostile field must otherwise be policy-visible for this test to prove signed-view narrowing.',
        );
        $hostileQueries = [
            ['projection' => ['fields' => ['name', 'amount']]],
            ['filter' => [
                'type' => 'comparison',
                'field' => 'enabled',
                'operator' => 'eq',
                'value' => true,
            ]],
            ['search' => ['term' => 'north', 'fields' => ['enabled']]],
            ['sorts' => [['field' => 'enabled', 'direction' => 'asc']]],
            ['projection' => ['aggregates' => [['alias' => 'total', 'function' => 'sum', 'field' => 'amount']]]],
            ['filter' => [
                'type' => 'relation',
                'relationship' => 'parent',
                'quantifier' => 'any',
                'target' => ['type' => 'comparison', 'field' => 'name', 'operator' => 'eq', 'value' => 'north'],
            ]],
        ];
        foreach ($hostileQueries as $hostileQuery) {
            try {
                $surface->customView(
                    $context,
                    BusinessSurface::Administrator,
                    $definition->handle,
                    'browser_summary',
                    $hostileQuery,
                    ['term' => 'south'],
                );
                self::fail('An undeclared custom-view query field reached extension code.');
            } catch (BusinessRecordDefinitionUnavailable) {
                self::assertCount(1, $observedQueries);
            }
        }

        $narrowed = $surface->customView(
            $context,
            BusinessSurface::Administrator,
            $definition->handle,
            'browser_summary',
            [
                'projection' => ['fields' => ['name']],
                'filter' => ['type' => 'boolean', 'operator' => 'all', 'children' => [
                    ['type' => 'comparison', 'field' => 'status', 'operator' => 'eq', 'value' => 'draft'],
                    ['type' => 'null', 'field' => 'status', 'is_null' => false],
                ]],
            ],
            ['term' => 'south'],
        );
        self::assertArrayHasKey('data', $narrowed);
        self::assertCount(2, $observedQueries);
        $narrowedQuery = $observedQueries[1] ?? null;
        self::assertInstanceOf(RecordQuerySpecification::class, $narrowedQuery);
        self::assertSame(['name'], $narrowedQuery->projection->fields);

        $invalidTerm = str_repeat('n', 41);
        $invalid = $browser->customView(
            $context,
            BusinessSurface::Administrator,
            $definition->handle,
            'browser_summary',
            null,
            ['run' => '1', 'parameters' => ['term' => $invalidTerm]],
        );
        self::assertSame(422, $invalid->status);
        self::assertSame($invalidTerm, $invalid->data['parameter_fields'][0]['input_value']);

        $failed = $browser->customView(
            $context,
            BusinessSurface::Administrator,
            $definition->handle,
            'browser_summary',
            null,
            ['run' => '1', 'parameters' => ['term' => 'trigger-failure']],
        );
        $failedModel = json_encode($failed->data, JSON_THROW_ON_ERROR);
        self::assertSame(422, $failed->status);
        self::assertSame(
            'The custom view could not be completed safely.',
            $failed->data['error_summary'],
        );
        self::assertStringNotContainsString('should-never-reach-the-browser', $failedModel);
    }

    /**
     * Proves nested custom-action command controls reach the typed handler without JSON authoring.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCustomActionExecutesSchemaDrivenNestedBrowserInput(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $handlerReference = 'site.default.actions.browser_nested_' . $suffix;
        $schemaReference = 'site.default.schemas.browser_nested_' . $suffix;
        $contract = CustomBusinessActionDeclaration::fromManifest([
            'handler' => $handlerReference,
            'schema' => $schemaReference,
            'command_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'criteria' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'rows' => [
                                'type' => 'array',
                                'maxItems' => 2,
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'properties' => [
                                        'code' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20],
                                    ],
                                    'required' => ['code'],
                                ],
                            ],
                        ],
                        'required' => ['rows'],
                    ],
                ],
                'required' => ['criteria'],
            ],
            'result_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'received' => ['type' => 'string', 'maxLength' => 20],
                ],
                'required' => ['received'],
            ],
        ]);
        $registries = $container->get(ExtensionContributionRegistrySet::class);
        self::assertInstanceOf(ExtensionContributionRegistrySet::class, $registries);
        $registries->customBusinessActionHandlers()->register(
            DefinitionOwner::site('default'),
            $contract,
            new class implements CustomBusinessActionHandler {
                /**
                 * Echo the nested browser input into a schema-validated result.
                 *
                 * @param   CustomBusinessActionCommand  $command  Typed custom action command.
                 *
                 * @return  CustomBusinessActionResult  Result carrying the first submitted code.
                 *
                 * @since   2.0.0
                 */
                public function handle(CustomBusinessActionCommand $command): CustomBusinessActionResult
                {
                    return new CustomBusinessActionResult(
                        ['received' => $command->input['criteria']['rows'][0]['code']],
                        $command->expectedVersion,
                        $command->idempotencyKey,
                    );
                }
            },
        );
        $document = NeutralBusinessFixture::document('action' . $suffix, Uuid::uuid7()->toString());
        $document['actions'][] = [
            'handle' => 'browser_nested',
            'label' => 'Browser nested action',
            'capability' => 'business.record.action',
            'administrator' => true,
            'portal' => false,
            'public' => false,
            'bulk' => true,
            'handler' => $handlerReference,
            'schema' => $schemaReference,
        ];
        $definition = NeutralBusinessFixture::install($container, $context, $document);
        $records = $container->get(BusinessRecordService::class);
        $browser = $container->get(GeneratedBusinessBrowserController::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(GeneratedBusinessBrowserController::class, $browser);
        $recordId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $definition->handle,
            NeutralBusinessFixture::recordValues('Nested browser action'),
            NeutralBusinessFixture::idempotencyKey('browser-nested-create-' . $suffix),
            recordId: $recordId,
        ));
        $confirmation = $browser->dispatch(
            $context,
            BusinessSurface::Administrator,
            '/administrator/business',
            'GET',
            $definition->handle,
            $recordId,
            ['confirm' => 'action', 'action' => 'browser_nested'],
            [],
        );
        $rows = $confirmation->data['action_fields'][0]['children'][0];
        self::assertSame('array', $rows['kind']);
        $operationId = 'browser:nested-action-' . $suffix;
        $result = $browser->dispatch(
            $context,
            BusinessSurface::Administrator,
            '/administrator/business',
            'POST',
            $definition->handle,
            $recordId,
            [],
            [
                'operation' => 'action',
                'operation_id' => $operationId,
                'expected_version' => '1',
                'confirmed' => '1',
                'action' => 'browser_nested',
                'schema_counts' => [$rows['path_token'] => '1'],
                'input' => ['criteria' => ['rows' => [['code' => 'north']]]],
            ],
        );
        self::assertNotNull($result->redirect);
        $status = $browser->operationStatus($context, $operationId);
        self::assertSame('north', $status->data['operation_status']['result']['result']['received']);

        $secondRecordId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $definition->handle,
            NeutralBusinessFixture::recordValues('Second nested browser action'),
            NeutralBusinessFixture::idempotencyKey('browser-nested-second-' . $suffix),
            recordId: $secondRecordId,
        ));
        $items = [
            ['record_id' => $recordId, 'expected_version' => 1],
            ['record_id' => $secondRecordId, 'expected_version' => 1],
        ];
        $encodedItems = array_map(
            static fn (array $item): string => json_encode($item, JSON_THROW_ON_ERROR),
            $items,
        );
        $bulkConfirmation = $browser->dispatch(
            $context,
            BusinessSurface::Administrator,
            '/administrator/business',
            'GET',
            $definition->handle,
            null,
            ['bulk_operation' => 'action:browser_nested', 'bulk_records' => $encodedItems],
            [],
        );
        $bulkRows = $bulkConfirmation->data['bulk_action_fields'][0]['children'][0];
        self::assertSame('array', $bulkRows['kind']);

        $invalidBulk = $browser->dispatch(
            $context,
            BusinessSurface::Administrator,
            '/administrator/business',
            'POST',
            $definition->handle,
            null,
            [],
            [
                'operation' => 'bulk',
                'operation_id' => 'browser:nested-bulk-invalid-' . $suffix,
                'confirmed' => '1',
                'bulk_operation' => 'action:browser_nested',
                'bulk_records' => $encodedItems,
                'schema_counts' => [$bulkRows['path_token'] => '1'],
            ],
        );
        self::assertSame(422, $invalidBulk->status);
        self::assertSame('business-bulk-confirm', $invalidBulk->template);
        self::assertNotSame([], $invalidBulk->data['bulk_action_fields']);

        $bulkOperationId = 'browser:nested-bulk-' . $suffix;
        $bulkResult = $browser->dispatch(
            $context,
            BusinessSurface::Administrator,
            '/administrator/business',
            'POST',
            $definition->handle,
            null,
            [],
            [
                'operation' => 'bulk',
                'operation_id' => $bulkOperationId,
                'confirmed' => '1',
                'bulk_operation' => 'action:browser_nested',
                'bulk_records' => $encodedItems,
                'schema_counts' => [$bulkRows['path_token'] => '1'],
                'input' => ['criteria' => ['rows' => [['code' => 'south']]]],
            ],
        );
        self::assertSame(
            '/administrator/business/' . $definition->handle . '?saved=1&bulk_count=2',
            $bulkResult->redirect,
        );
        $child = (new BusinessBulkMutation(
            BusinessSurfaceOperation::Action,
            $items,
            $bulkOperationId,
            'browser_nested',
            ['criteria' => ['rows' => [['code' => 'south']]]],
        ))->operationIdFor($secondRecordId);
        $bulkStatus = $browser->operationStatus($context, $child);
        self::assertSame('south', $bulkStatus->data['operation_status']['result']['result']['received']);
    }

    /**
     * Proves bounded bulk mutations are atomic and derive queryable child operation identities.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testBulkMutationsAreAtomicVersionedAndUseQueryableChildOperationIds(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $document = NeutralBusinessFixture::document('bulk' . $suffix, Uuid::uuid7()->toString());
        $document['actions'][0]['bulk'] = true;
        $installed = NeutralBusinessFixture::install($container, $context, $document);
        $definition = $installed->handle;
        $records = $container->get(BusinessRecordService::class);
        $surface = $container->get(BusinessSurfaceService::class);
        $browser = $container->get(GeneratedBusinessBrowserController::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(BusinessSurfaceService::class, $surface);
        self::assertInstanceOf(GeneratedBusinessBrowserController::class, $browser);
        $recordIds = [Uuid::uuid7()->toString(), Uuid::uuid7()->toString()];
        foreach ($recordIds as $index => $recordId) {
            $records->create(new CreateRecordCommand(
                $context,
                $definition,
                NeutralBusinessFixture::recordValues('Bulk record ' . $index . ' ' . $suffix),
                NeutralBusinessFixture::idempotencyKey('bulk-create-' . $suffix . '-' . $index),
                recordId: $recordId,
            ));
        }

        $archiveItems = array_map(
            static fn (string $recordId): array => ['record_id' => $recordId, 'expected_version' => 1],
            $recordIds,
        );
        $encodedItems = array_map(
            static fn (array $item): string => json_encode($item, JSON_THROW_ON_ERROR),
            $archiveItems,
        );
        $confirmation = $browser->dispatch(
            $context,
            BusinessSurface::Administrator,
            '/administrator/business',
            'GET',
            $definition,
            null,
            ['bulk_operation' => 'archive', 'bulk_records' => $encodedItems],
            [],
        );
        self::assertSame('business-bulk-confirm', $confirmation->template);
        self::assertCount(2, $confirmation->data['bulk']['items']);

        $archiveOperationId = 'browser:bulk-archive-' . $suffix;
        $archived = $browser->dispatch(
            $context,
            BusinessSurface::Administrator,
            '/administrator/business',
            'POST',
            $definition,
            null,
            [],
            [
                'operation' => 'bulk',
                'operation_id' => $archiveOperationId,
                'confirmed' => '1',
                'bulk_operation' => 'archive',
                'bulk_records' => $encodedItems,
            ],
        );
        self::assertSame(
            '/administrator/business/' . $definition . '?saved=1&bulk_count=2',
            $archived->redirect,
        );
        $childOperationId = (new BusinessBulkMutation(
            BusinessSurfaceOperation::Archive,
            $archiveItems,
            $archiveOperationId,
        ))->operationIdFor($recordIds[0]);
        self::assertMatchesRegularExpression('/^bulk:[a-f0-9]{64}$/D', $childOperationId);
        $operations = $container->get(BusinessOperationStatusService::class);
        self::assertInstanceOf(BusinessOperationStatusService::class, $operations);
        self::assertSame(
            $recordIds[0],
            $operations->get($context, $childOperationId)['result']['record_id'],
        );

        try {
            $surface->bulk(
                $context,
                BusinessSurface::Administrator,
                $definition,
                BusinessSurfaceOperation::Restore,
                [
                    ['record_id' => $recordIds[0], 'expected_version' => 2],
                    ['record_id' => $recordIds[1], 'expected_version' => 1],
                ],
                'browser:bulk-stale-' . $suffix,
            );
            self::fail('One stale member must roll the entire bulk transaction back.');
        } catch (BusinessRecordVersionConflict) {
            self::assertSame(2, $records->read(new ReadRecordQuery(
                $context,
                $definition,
                $recordIds[0],
                includeArchived: true,
            ))->version);
        }

        $restored = $surface->bulk(
            $context,
            BusinessSurface::Administrator,
            $definition,
            BusinessSurfaceOperation::Restore,
            array_map(
                static fn (string $recordId): array => ['record_id' => $recordId, 'expected_version' => 2],
                $recordIds,
            ),
            'browser:bulk-restore-' . $suffix,
        );
        self::assertSame(2, $restored['count']);

        $acted = $surface->bulk(
            $context,
            BusinessSurface::Administrator,
            $definition,
            BusinessSurfaceOperation::Action,
            array_map(
                static fn (string $recordId): array => ['record_id' => $recordId, 'expected_version' => 3],
                $recordIds,
            ),
            'browser:bulk-action-' . $suffix,
            'approve',
        );
        self::assertSame(2, $acted['count']);
        self::assertSame('approved', $acted['items'][0]['workflow_state']);
        self::assertSame(4, $acted['items'][0]['version']);
    }

    /**
     * Proves selector-backed relations and graphical owned-line forms persist through the browser facade.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRelationshipChoicesAndOwnedLineFormsPersistWithoutRawIdentifiersOrJson(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $target = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::relationTargetDocument($suffix, Uuid::uuid7()->toString()),
        );
        $lineDocument = NeutralBusinessFixture::ownedLineDocument($suffix, Uuid::uuid7()->toString());
        $lineDocument['fields'][] = [
            'handle' => 'target_ref',
            'label' => 'Target reference',
            'type' => 'core.entity_reference',
            'required' => true,
            'nullable' => false,
            'configuration' => ['target' => $target->handle],
        ];
        $lineDocument['fields'][] = [
            'handle' => 'metadata',
            'label' => 'Metadata',
            'type' => 'core.bounded_json',
            'required' => true,
            'nullable' => false,
            'configuration' => ['max_bytes' => 4096],
        ];
        $line = NeutralBusinessFixture::install(
            $container,
            $context,
            $lineDocument,
        );
        $ownerDocument = NeutralBusinessFixture::relationshipOwnerDocument(
            $suffix,
            Uuid::uuid7()->toString(),
            $target->handle,
            $line->handle,
        );
        $ownerDocument['fields'] = array_values(array_filter(
            $ownerDocument['fields'],
            static fn (array $field): bool => ($field['type'] ?? null) !== 'core.ordered_lines',
        ));
        $owner = NeutralBusinessFixture::install($container, $context, $ownerDocument);
        $records = $container->get(BusinessRecordService::class);
        $browser = $container->get(GeneratedBusinessBrowserController::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(GeneratedBusinessBrowserController::class, $browser);
        $ownerId = Uuid::uuid7()->toString();
        $targetId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $target->handle,
            ['label' => 'Selector target'],
            NeutralBusinessFixture::idempotencyKey('browser-selector-target-' . $suffix),
            recordId: $targetId,
        ));
        $records->create(new CreateRecordCommand(
            $context,
            $owner->handle,
            ['title' => 'Browser relationship owner'],
            NeutralBusinessFixture::idempotencyKey('browser-selector-owner-' . $suffix),
            recordId: $ownerId,
        ));

        $choices = $browser->choices(
            $context,
            BusinessSurface::Administrator,
            '/administrator/business',
            $owner->handle,
            $ownerId,
            'tags',
            null,
            ['operation' => 'relation', 'page_size' => '50'],
        );
        self::assertSame('business-choices', $choices->template);
        self::assertSame($targetId, $choices->data['choices'][0]['id']);
        self::assertSame('Selector target', $choices->data['choices'][0]['label']);
        self::assertStringContainsString('/relationships/tags?', $choices->data['choice_return_path']);

        $related = $browser->dispatch(
            $context,
            BusinessSurface::Administrator,
            '/administrator/business',
            'POST',
            $owner->handle,
            $ownerId,
            [],
            [
                'operation' => 'relate',
                'operation_id' => 'browser:selector-relate-' . $suffix,
                'expected_version' => '1',
                'relationship' => 'tags',
                'target_record_id' => $targetId,
            ],
        );
        self::assertNotNull($related->redirect);

        $detail = $browser->dispatch(
            $context,
            BusinessSurface::Administrator,
            '/administrator/business',
            'GET',
            $owner->handle,
            $ownerId,
            [],
            [],
        );
        $relationships = array_column($detail->data['definition']['relationships'], null, 'handle');
        self::assertFalse($relationships['tags']['loaded']);
        self::assertFalse($relationships['lines']['loaded']);
        self::assertNull($relationships['lines']['owned_line_form']);
        self::assertArrayNotHasKey('tags', $detail->data['record']['includes']);
        self::assertArrayNotHasKey('lines', $detail->data['record']['includes']);

        $tagDetail = $browser->relationship(
            $context,
            BusinessSurface::Administrator,
            $owner->handle,
            $ownerId,
            'tags',
            [],
        );
        self::assertSame($targetId, $tagDetail->data['record']['includes']['tags'][0]['record_id']);
        self::assertSame('Selector target', $tagDetail->data['record']['includes']['tags'][0]['label']);

        $lineDetail = $browser->relationship(
            $context,
            BusinessSurface::Administrator,
            $owner->handle,
            $ownerId,
            'lines',
            [],
        );
        $relationships = array_column($lineDetail->data['definition']['relationships'], null, 'handle');
        self::assertSame('lines', $lineDetail->data['relationship_focus']);
        self::assertSame('Neutral line', $relationships['lines']['owned_line_form']['target_label']);
        $suggested = $relationships['lines']['owned_line_form']['suggested_record_id'];
        self::assertIsString($suggested);

        $ownedChoices = $browser->ownedLineChoices(
            $context,
            BusinessSurface::Administrator,
            '/administrator/business',
            $owner->handle,
            $ownerId,
            'lines',
            'target_ref',
            'relations',
            ['operation' => 'relation', 'page_size' => '50'],
        );
        self::assertSame($targetId, $ownedChoices->data['choices'][0]['id']);
        $selectedLine = $browser->relationship(
            $context,
            BusinessSurface::Administrator,
            $owner->handle,
            $ownerId,
            'lines',
            [
                'choice_relationship' => 'lines',
                'choice_handle' => 'target_ref',
                'choice_value' => $targetId,
                'choice_label' => 'Selector target',
            ],
        );
        $selectedRelationships = array_column(
            $selectedLine->data['definition']['relationships'],
            null,
            'handle',
        );
        $selectedFields = array_column(
            $selectedRelationships['lines']['owned_line_form']['fields'],
            null,
            'handle',
        );
        self::assertSame($targetId, $selectedFields['target_ref']['input_value']);
        self::assertSame('object', $selectedFields['metadata']['structured']['kind']);

        $lineResult = $browser->dispatch(
            $context,
            BusinessSurface::Administrator,
            '/administrator/business',
            'POST',
            $owner->handle,
            $ownerId,
            [],
            [
                'operation' => 'relate',
                'operation_id' => 'browser:owned-line-' . $suffix,
                'expected_version' => '2',
                'relationship' => 'lines',
                'target_record_id' => $suggested,
                'target_values' => [
                    'description' => 'Browser line',
                    'units' => '3.500',
                    'target_ref' => $targetId,
                ],
                'target_structured' => [
                    'metadata' => [
                        'kind' => 'object',
                        'count' => '1',
                        'entries' => [[
                            'key' => 'priority',
                            'node' => ['kind' => 'integer', 'value' => '7'],
                        ]],
                    ],
                ],
            ],
        );
        self::assertNotNull($lineResult->redirect);
        $afterLine = $browser->relationship(
            $context,
            BusinessSurface::Administrator,
            $owner->handle,
            $ownerId,
            'lines',
            [],
        );
        self::assertSame($suggested, $afterLine->data['record']['includes']['lines'][0]['record_id']);
        self::assertSame(
            'Browser line',
            $afterLine->data['record']['includes']['lines'][0]['values']['description'],
        );
        self::assertSame('Browser line', $afterLine->data['record']['includes']['lines'][0]['label']);
        self::assertSame(
            ['priority' => 7],
            $afterLine->data['record']['includes']['lines'][0]['values']['metadata'],
        );
        $afterLineRelationships = array_column(
            $afterLine->data['definition']['relationships'],
            null,
            'handle',
        );
        $secondLineId = $afterLineRelationships['lines']['owned_line_form']['suggested_record_id'];
        self::assertIsString($secondLineId);

        $secondLine = $browser->dispatch(
            $context,
            BusinessSurface::Administrator,
            '/administrator/business',
            'POST',
            $owner->handle,
            $ownerId,
            [],
            [
                'operation' => 'relate',
                'operation_id' => 'browser:owned-line-second-' . $suffix,
                'expected_version' => '3',
                'relationship' => 'lines',
                'target_record_id' => $secondLineId,
                'target_values' => [
                    'description' => 'Browser line two',
                    'units' => '1.250',
                    'target_ref' => $targetId,
                ],
                'target_structured' => [
                    'metadata' => [
                        'kind' => 'object',
                        'count' => '1',
                        'entries' => [[
                            'key' => 'priority',
                            'node' => ['kind' => 'integer', 'value' => '8'],
                        ]],
                    ],
                ],
            ],
        );
        self::assertNotNull($secondLine->redirect);

        $reordered = $browser->dispatch(
            $context,
            BusinessSurface::Administrator,
            '/administrator/business',
            'POST',
            $owner->handle,
            $ownerId,
            [],
            [
                'operation' => 'reorder',
                'operation_id' => 'browser:owned-lines-reorder-' . $suffix,
                'expected_version' => '4',
                'relationship' => 'lines',
                'ordered_record_ids' => [$secondLineId, $suggested],
            ],
        );
        self::assertNotNull($reordered->redirect);
        $afterReorder = $browser->relationship(
            $context,
            BusinessSurface::Administrator,
            $owner->handle,
            $ownerId,
            'lines',
            [],
        );
        self::assertSame(
            [$secondLineId, $suggested],
            array_column($afterReorder->data['record']['includes']['lines'], 'record_id'),
        );
    }

    /**
     * Proves only policy-visible high-impact metadata resolves an approval-consumption proof purpose.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testActionStepUpPurposeComesFromPolicyVisibleMetadata(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $document = NeutralBusinessFixture::document('stepup' . $suffix, Uuid::uuid7()->toString());
        $document['actions'][0]['high_impact'] = true;
        $definition = NeutralBusinessFixture::install($container, $context, $document);
        $browser = $container->get(GeneratedBusinessBrowserController::class);
        self::assertInstanceOf(GeneratedBusinessBrowserController::class, $browser);

        self::assertSame(
            'business.record.action:approve',
            $browser->actionStepUpPurpose(
                $context,
                BusinessSurface::Administrator,
                $definition->handle,
                'approve',
            ),
        );

        $this->expectException(BusinessRecordDefinitionUnavailable::class);
        $browser->actionStepUpPurpose(
            $context,
            BusinessSurface::Administrator,
            $definition->handle,
            'submitted-but-undeclared',
        );
    }

    /**
     * Proves malformed and unavailable operation status requests share one response.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testOperationStatusFailuresAreNonEnumerating(): void
    {
        [$context, , , $browser] = $this->runtime('status');

        $malformed = $browser->operationStatus($context, 'invalid');
        $missing = $browser->operationStatus($context, 'browser:operation-does-not-exist');

        self::assertSame(404, $malformed->status);
        self::assertSame(404, $missing->status);
        self::assertSame($malformed->template, $missing->template);
        self::assertSame($malformed->data, $missing->data);
    }

    /**
     * Install one isolated administrator-exposed definition and return its generated runtime.
     *
     * The suffix is part of the runtime on purpose: idempotency keys and operation identifiers live in
     * the installation-global mutation ledger, so a caller that reuses a fixed one meets the entry a
     * previous suite run recorded for a definition that no longer matches, and the replay is refused as
     * a conflict. Every identity a test mints must therefore carry this run-unique suffix.
     *
     * @param   string  $label  Short uniqueness label.
     *
     * @return  array{\Kumwe\Context\Value\ExecutionContext, string,
     *          BusinessRecordService, GeneratedBusinessBrowserController, string}
     *
     * @since   2.0.0
     */
    private function runtime(string $label): array
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $document = NeutralBusinessFixture::document($label . $suffix, Uuid::uuid7()->toString());
        $definition = NeutralBusinessFixture::install($container, $context, $document);
        $records = $container->get(BusinessRecordService::class);
        $browser = $container->get(GeneratedBusinessBrowserController::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(GeneratedBusinessBrowserController::class, $browser);

        return [$context, $definition->handle, $records, $browser, $suffix];
    }
}
