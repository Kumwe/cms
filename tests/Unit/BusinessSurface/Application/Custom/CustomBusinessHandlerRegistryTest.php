<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessSurface\Application\Custom;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessActionHandlerRegistry;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessHandlerFailed;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessSurfaceDispatcher;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessViewHandlerRegistry;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceOperation;
use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\Extension\Spi\Application\Automation\IdempotencyKey;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessActionCommand;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessActionDeclaration;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessActionHandler;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessActionResult;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessViewDeclaration;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessViewHandler;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessViewQuery;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessViewResult;
use LogicException;
use RuntimeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Kumwe\App\Extension\Runtime\ExtensionExecutionContext;

#[CoversClass(CustomBusinessActionHandlerRegistry::class)]
#[CoversClass(CustomBusinessHandlerFailed::class)]
#[CoversClass(CustomBusinessSurfaceDispatcher::class)]
#[CoversClass(CustomBusinessViewHandlerRegistry::class)]
/**
 * Exercises custom-handler schema validation, owner isolation, replay evidence, and lifecycle removal.
 *
 * @since  2.0.0
 */
final class CustomBusinessHandlerRegistryTest extends TestCase
{
    /**
     * Proves a view handler sees only schema-valid input and its output is validated before return.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testViewRegistryValidatesBothSidesOfTheHandlerBoundary(): void
    {
        $owner = DefinitionOwner::extension('acme/editor');
        $handler = new class implements CustomBusinessViewHandler {
            /**
             * Number of schema-valid invocations received.
             *
             * @var    int
             * @since  2.0.0
             */
            public int $calls = 0;

            /**
             * Return one bounded row carrying the validated search term.
             *
             * @param   CustomBusinessViewQuery  $query  Validated query from the registry.
             *
             * @return  CustomBusinessViewResult  One-row result matching the signed schema.
             *
             * @since   2.0.0
             */
            public function handle(CustomBusinessViewQuery $query): CustomBusinessViewResult
            {
                ++$this->calls;
                return new CustomBusinessViewResult([
                    'items' => [['label' => $query->parameters['term']]],
                ]);
            }
        };
        $registry = new CustomBusinessViewHandlerRegistry();
        $registry->register($owner, self::viewContract(), $handler);
        $query = new CustomBusinessViewQuery(
            self::context(),
            'acme.editor.asset',
            'summary',
            new RecordQuerySpecification(pageSize: 25),
            ['term' => 'north'],
        );

        $result = $registry->execute(
            $owner,
            'acme.editor.views.summary',
            'acme.editor.schemas.summary_v1',
            $query,
        );

        self::assertSame([['label' => 'north']], $result->data['items']);
        self::assertSame(1, $handler->calls);

        try {
            $registry->execute(
                $owner,
                'acme.editor.views.summary',
                'acme.editor.schemas.summary_v1',
                new CustomBusinessViewQuery(
                    self::context(),
                    'acme.editor.asset',
                    'summary',
                    new RecordQuerySpecification(),
                    ['unknown' => 'value'],
                ),
            );
            self::fail('Schema-invalid view parameters reached the handler.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('violates its contract', $exception->getMessage());
        }
        self::assertSame(1, $handler->calls);
    }

    /**
     * Proves action results remain bound to the caller's operation identity and owner.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testActionRegistryEnforcesOwnerSchemaAndOperationIdentity(): void
    {
        $owner = DefinitionOwner::extension('acme/editor');
        $wrongOperation = IdempotencyKey::fromString('operation:wrong-0001');
        $handler = new class ($wrongOperation) implements CustomBusinessActionHandler {
            /**
             * Build a handler that deliberately returns another operation identity.
             *
             * @param  IdempotencyKey  $operation  Wrong operation used to exercise the guard.
             *
             * @since  2.0.0
             */
            public function __construct(private readonly IdempotencyKey $operation)
            {
            }

            /**
             * Return a structurally valid result under the wrong operation.
             *
             * @param   CustomBusinessActionCommand  $command  Validated command, intentionally not echoed.
             *
             * @return  CustomBusinessActionResult  Result used to trigger operation binding failure.
             *
             * @since   2.0.0
             */
            public function handle(CustomBusinessActionCommand $command): CustomBusinessActionResult
            {
                return new CustomBusinessActionResult(['status' => 'done'], 2, $this->operation);
            }
        };
        $registry = new CustomBusinessActionHandlerRegistry();
        $registry->register($owner, self::actionContract(), $handler);
        $command = new CustomBusinessActionCommand(
            self::context(),
            'acme.editor.asset',
            'asset-1',
            1,
            'recalculate',
            IdempotencyKey::fromString('operation:valid-0001'),
            ['mode' => 'full'],
        );

        try {
            $registry->execute(
                $owner,
                'acme.editor.actions.recalculate',
                'acme.editor.schemas.recalculate_v1',
                $command,
            );
            self::fail('A result under another operation identity was accepted.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('another operation identity', $exception->getMessage());
        }

        self::assertNull($registry->contract(
            DefinitionOwner::extension('acme/other'),
            'acme.editor.actions.recalculate',
            'acme.editor.schemas.recalculate_v1',
        ));
        $registry->remove($owner);
        self::assertNull($registry->contract(
            $owner,
            'acme.editor.actions.recalculate',
            'acme.editor.schemas.recalculate_v1',
        ));
    }

    /**
     * Proves only extension invocation failures are replaced with fixed caller-safe text.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testHandlerFailuresAreChainedWithoutPublishingExtensionMessages(): void
    {
        $owner = DefinitionOwner::extension('acme/editor');
        $failure = new InvalidArgumentException('database-password=do-not-publish');
        $views = new CustomBusinessViewHandlerRegistry();
        $views->register($owner, self::viewContract(), new class ($failure) implements CustomBusinessViewHandler {
            /**
             * Capture the extension failure this handler will raise.
             *
             * @param  InvalidArgumentException  $failure  Secret-bearing uncontrolled handler failure.
             *
             * @since  2.0.0
             */
            public function __construct(private readonly InvalidArgumentException $failure)
            {
            }

            /**
             * Raise the uncontrolled extension failure.
             *
             * @param   CustomBusinessViewQuery  $query  Valid query whose contents are intentionally unused.
             *
             * @return  CustomBusinessViewResult  Never returned.
             *
             * @since   2.0.0
             */
            public function handle(CustomBusinessViewQuery $query): CustomBusinessViewResult
            {
                throw $this->failure;
            }
        });

        try {
            $views->execute(
                $owner,
                'acme.editor.views.summary',
                'acme.editor.schemas.summary_v1',
                new CustomBusinessViewQuery(
                    self::context(),
                    'acme.editor.asset',
                    'summary',
                    new RecordQuerySpecification(),
                    ['term' => 'north'],
                ),
            );
            self::fail('An uncontrolled custom handler failure escaped unchanged.');
        } catch (CustomBusinessHandlerFailed $exception) {
            self::assertSame('The custom business handler could not complete the request.', $exception->getMessage());
            self::assertSame($failure, $exception->getPrevious());
            self::assertStringNotContainsString('database-password', $exception->getMessage());
            self::assertStringNotContainsString('do-not-publish', $exception->getMessage());
        }

        try {
            $views->execute(
                $owner,
                'acme.editor.views.summary',
                'acme.editor.schemas.summary_v1',
                new CustomBusinessViewQuery(
                    self::context(),
                    'acme.editor.asset',
                    'summary',
                    new RecordQuerySpecification(),
                    ['unknown' => 'value'],
                ),
            );
            self::fail('A schema-invalid query was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('violates its contract', $exception->getMessage());
        }
    }

    /**
     * Proves published custom references reach typed handlers through the runtime dispatcher.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDispatcherInvokesPublishedViewAndActionContractsAtRuntime(): void
    {
        $owner = DefinitionOwner::extension('acme/editor');
        $views = new CustomBusinessViewHandlerRegistry();
        $views->register($owner, self::viewContract(), new class implements CustomBusinessViewHandler {
            /**
             * Return the admitted term through the signed result shape.
             *
             * @param   CustomBusinessViewQuery  $query  Schema-validated view query.
             *
             * @return  CustomBusinessViewResult  Bounded view data.
             *
             * @since   2.0.0
             */
            public function handle(CustomBusinessViewQuery $query): CustomBusinessViewResult
            {
                return new CustomBusinessViewResult([
                    'items' => [['label' => $query->parameters['term']]],
                ]);
            }
        });
        $actions = new CustomBusinessActionHandlerRegistry();
        $actions->register($owner, self::actionContract(), new class implements CustomBusinessActionHandler {
            /**
             * Return one operation-bound custom action result.
             *
             * @param   CustomBusinessActionCommand  $command  Schema-validated action command.
             *
             * @return  CustomBusinessActionResult  Versioned bounded action data.
             *
             * @since   2.0.0
             */
            public function handle(CustomBusinessActionCommand $command): CustomBusinessActionResult
            {
                return new CustomBusinessActionResult(
                    ['status' => 'done'],
                    2,
                    $command->idempotencyKey,
                    workflowState: 'ready',
                );
            }
        });
        $authorization = $this->createMock(AuthorizationGateway::class);
        $authorization->expects(self::once())->method('assertAllowed');
        $dispatcher = new CustomBusinessSurfaceDispatcher(
            $views,
            $actions,
            $authorization,
            $this->createStub(ExtensionExecutionGate::class),
        );
        $definition = self::definition();
        $viewQuery = new CustomBusinessViewQuery(
            self::context(),
            $definition->handle,
            'summary',
            new RecordQuerySpecification(pageSize: 10),
            ['term' => 'north'],
        );
        $operation = IdempotencyKey::fromString('operation:dispatch-0001');
        $actionCommand = new CustomBusinessActionCommand(
            self::context(),
            $definition->handle,
            'asset-1',
            1,
            'recalculate',
            $operation,
            ['mode' => 'full'],
        );

        self::assertSame(
            BusinessSurfaceOperation::Browse,
            $dispatcher->viewOperation($definition, 'summary'),
        );
        self::assertSame([
            'query_schema' => self::viewContract()->querySchema->toArray(),
            'result_schema' => self::viewContract()->resultSchema->toArray(),
        ], $dispatcher->viewContractSchemas($definition, 'summary'));
        self::assertSame([
            'command_schema' => self::actionContract()->commandSchema->toArray(),
            'result_schema' => self::actionContract()->resultSchema->toArray(),
        ], $dispatcher->actionContractSchemas($definition, 'recalculate'));
        self::assertSame('north', $dispatcher->view($definition, $viewQuery)->data['items'][0]['label']);
        self::assertTrue($dispatcher->handlesAction($definition, 'recalculate'));
        $result = $dispatcher->action($definition, $actionCommand);
        self::assertSame('done', $result->data['status']);
        self::assertSame('ready', $result->workflowState);
        self::assertTrue($result->operationId->equals($operation));

        $foreignContext = $this->createStub(\Kumwe\Extension\Spi\Application\ExecutionContext::class);
        try {
            $dispatcher->view($definition, new CustomBusinessViewQuery(
                $foreignContext,
                $definition->handle,
                'summary',
                new RecordQuerySpecification(pageSize: 10),
                ['term' => 'north'],
            ));
            self::fail('A foreign execution context entered a custom view handler.');
        } catch (BusinessRecordDefinitionUnavailable) {
            self::addToAssertionCount(1);
        }
        try {
            $dispatcher->action($definition, new CustomBusinessActionCommand(
                $foreignContext,
                $definition->handle,
                'asset-1',
                1,
                'recalculate',
                IdempotencyKey::fromString('operation:foreign-dispatch-0001'),
                ['mode' => 'full'],
            ));
            self::fail('A foreign execution context entered a custom action handler.');
        } catch (BusinessRecordDefinitionUnavailable) {
            self::addToAssertionCount(1);
        }

        $inactive = new CustomBusinessSurfaceDispatcher(
            new CustomBusinessViewHandlerRegistry(),
            new CustomBusinessActionHandlerRegistry(),
            $authorization,
            $this->createStub(ExtensionExecutionGate::class),
        );
        self::assertNull($inactive->viewContractSchemas($definition, 'summary'));
        self::assertNull($inactive->actionContractSchemas($definition, 'recalculate'));
    }

    /**
     * A superseded generation cannot enter either resident typed extension handler.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDispatcherRefusesStaleGenerationBeforeTypedExtensionCodeRuns(): void
    {
        $owner = DefinitionOwner::extension('acme/editor');
        $viewHandler = $this->createMock(CustomBusinessViewHandler::class);
        $viewHandler->expects(self::never())->method('handle');
        $views = new CustomBusinessViewHandlerRegistry();
        $views->register($owner, self::viewContract(), $viewHandler);
        $actionHandler = $this->createMock(CustomBusinessActionHandler::class);
        $actionHandler->expects(self::never())->method('handle');
        $actions = new CustomBusinessActionHandlerRegistry();
        $actions->register($owner, self::actionContract(), $actionHandler);
        $authorization = $this->createMock(AuthorizationGateway::class);
        $authorization->expects(self::never())->method('assertAllowed');
        $execution = $this->createMock(ExtensionExecutionGate::class);
        $execution->expects(self::exactly(2))
            ->method('assertCurrent')
            ->willThrowException(new RuntimeException('stale extension generation'));
        $dispatcher = new CustomBusinessSurfaceDispatcher($views, $actions, $authorization, $execution);
        $definition = self::definition();

        try {
            $dispatcher->view($definition, new CustomBusinessViewQuery(
                self::context(),
                $definition->handle,
                'summary',
                new RecordQuerySpecification(pageSize: 10),
                ['term' => 'north'],
            ));
            self::fail('A stale custom view handler was invoked.');
        } catch (RuntimeException $exception) {
            self::assertSame('stale extension generation', $exception->getMessage());
        }

        try {
            $dispatcher->action($definition, new CustomBusinessActionCommand(
                self::context(),
                $definition->handle,
                'asset-1',
                1,
                'recalculate',
                IdempotencyKey::fromString('operation:stale-dispatch-0001'),
                ['mode' => 'full'],
            ));
            self::fail('A stale custom action handler was invoked.');
        } catch (RuntimeException $exception) {
            self::assertSame('stale extension generation', $exception->getMessage());
        }
    }

    /**
     * Build the signed custom view contract used by registry tests.
     *
     * @return  CustomBusinessViewDeclaration  Closed query and result schema pair.
     *
     * @since   2.0.0
     */
    private static function viewContract(): CustomBusinessViewDeclaration
    {
        return CustomBusinessViewDeclaration::fromManifest([
            'handler' => 'acme.editor.views.summary',
            'schema' => 'acme.editor.schemas.summary_v1',
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
                    'items' => [
                        'type' => 'array',
                        'maxItems' => 20,
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
                'required' => ['items'],
            ],
        ]);
    }

    /**
     * Build the signed custom action contract used by registry tests.
     *
     * @return  CustomBusinessActionDeclaration  Closed command and result schema pair.
     *
     * @since   2.0.0
     */
    private static function actionContract(): CustomBusinessActionDeclaration
    {
        return CustomBusinessActionDeclaration::fromManifest([
            'handler' => 'acme.editor.actions.recalculate',
            'schema' => 'acme.editor.schemas.recalculate_v1',
            'command_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'mode' => ['type' => 'string', 'enum' => ['full', 'delta'], 'maxLength' => 5],
                ],
                'required' => ['mode'],
            ],
            'result_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['done'], 'maxLength' => 4],
                ],
                'required' => ['status'],
            ],
        ]);
    }

    /**
     * Build one installed-shape definition carrying both custom declaration references.
     *
     * @return  EntityTypeDefinition  Valid extension-owned draft definition.
     *
     * @since   2.0.0
     */
    private static function definition(): EntityTypeDefinition
    {
        return EntityTypeDefinition::fromArray([
            'id' => '018f4f24-98d8-7ad4-8f3f-38c909178b6b',
            'owner' => ['type' => 'extension', 'identifier' => 'acme/editor'],
            'site' => 'default',
            'handle' => 'acme.editor.asset',
            'singular_label' => 'Asset',
            'plural_label' => 'Assets',
            'status' => 'draft',
            'definition_version' => 0,
            'storage_mode' => 'relational',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => true,
            'fields' => [[
                'handle' => 'id',
                'label' => 'ID',
                'type' => 'core.uuid',
                'required' => true,
                'nullable' => false,
                'unique' => true,
                'indexed' => true,
                'immutable_after_create' => true,
                'server_only' => true,
                'read_only' => true,
            ]],
            'relationships' => [],
            'views' => [[
                'handle' => 'summary',
                'label' => 'Summary',
                'kind' => 'list',
                'fields' => ['id'],
                'administrator' => true,
                'handler' => 'acme.editor.views.summary',
                'schema' => 'acme.editor.schemas.summary_v1',
            ]],
            'actions' => [[
                'handle' => 'recalculate',
                'label' => 'Recalculate',
                'capability' => 'acme.editor.manage',
                'administrator' => true,
                'handler' => 'acme.editor.actions.recalculate',
                'schema' => 'acme.editor.schemas.recalculate_v1',
            ]],
            'workflow' => null,
            'compatibility_metadata' => [],
            'administrator_exposure' => true,
            'portal_exposure' => false,
            'public_exposure' => false,
        ]);
    }

    /**
     * Mint a trusted system context without introducing any delivery-layer dependency.
     *
     * @return  ExtensionExecutionContext  Background context, as typed handler input.
     *
     * @since   2.0.0
     */
    private static function context(): ExtensionExecutionContext
    {
        return ExtensionExecutionContext::of(ExecutionContext::issueSystem(
            new \stdClass(),
            SystemIdentity::Worker,
            SiteContext::default(),
            'custom-business-test',
        ));
    }
}
