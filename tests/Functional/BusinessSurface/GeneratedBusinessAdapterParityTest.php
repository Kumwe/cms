<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Functional\BusinessSurface;

use Doctrine\DBAL\Connection;
use Kumwe\App\Kernel\Container;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\BusinessDefinition\Domain\PortalOperation;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordNotFound;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordVersionConflict;
use Kumwe\App\BusinessSurface\Application\BusinessApprovalSurfaceService;
use Kumwe\App\BusinessSurface\Application\BusinessOperationStatusService;
use Kumwe\App\BusinessSurface\Application\BusinessRecordProjector;
use Kumwe\App\BusinessSurface\Application\BusinessRecordQueryFactory;
use Kumwe\App\BusinessSurface\Application\BusinessSurface;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceCatalog;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceService;
use Kumwe\App\BusinessSurface\Delivery\Browser\GeneratedBusinessBrowserController;
use Kumwe\App\Delivery\Console\Command\BusinessConsoleFailureMapper;
use Kumwe\App\Delivery\Console\Command\BusinessRecordConsolePresenter;
use Kumwe\App\Delivery\Console\Command\ConsoleAuthorizer;
use Kumwe\App\Delivery\Console\Command\ManageBusinessRecordsCommand;
use Kumwe\App\Delivery\Http\Api\Business\BusinessApprovalApiHandler;
use Kumwe\App\Delivery\Http\Api\Business\BusinessDefinitionDiscoveryApiHandler;
use Kumwe\App\Delivery\Http\Api\Business\BusinessRecordApiHandler;
use Kumwe\App\Delivery\Http\Api\Concurrency\IfMatch;
use Kumwe\App\Delivery\Http\Api\Concurrency\RequireIfMatchMiddleware;
use Kumwe\App\Delivery\Http\Api\Idempotency\IdempotencyKey as HttpIdempotencyKey;
use Kumwe\App\Delivery\Http\Api\Idempotency\RequireIdempotencyKeyMiddleware;
use Kumwe\App\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Infrastructure\Mcp\BusinessMcpHandlers;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\GeneratedBusinessParityOutput;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Ramsey\Uuid\Uuid;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;

#[CoversClass(BusinessSurfaceService::class)]
#[CoversClass(BusinessRecordService::class)]
#[CoversClass(BusinessRecordProjector::class)]
#[CoversClass(BusinessRecordApiHandler::class)]
#[CoversClass(BusinessRecordConsolePresenter::class)]
#[CoversClass(BusinessMcpHandlers::class)]
#[CoversClass(GeneratedBusinessBrowserController::class)]
/**
 * Proves one generated business lifecycle remains identical across every delivery adapter.
 *
 * @since  2.0.0
 */
final class GeneratedBusinessAdapterParityTest extends TestCase
{
    /**
     * Owner-protected temporary CLI input files.
     *
     * @var     list<string>
     *
     * @since   2.0.0
     */
    private array $files = [];

    /**
     * Remove every protected input document created by this test.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Proves the real composition root injects one shared approval surface gate into machine adapters.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testApprovalAdaptersUseTheSharedComposedSurfaceGate(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $gate = $this->service($container, BusinessApprovalSurfaceService::class);
        $api = $this->service($container, BusinessApprovalApiHandler::class);
        $cli = $this->service($container, ManageBusinessRecordsCommand::class);

        self::assertSame($gate, (new \ReflectionProperty($api, 'approvals'))->getValue($api));
        self::assertSame($gate, (new \ReflectionProperty($cli, 'approvals'))->getValue($cli));
    }

    /**
     * Run one versioned, idempotent lifecycle through REST, CLI, MCP, administrator and portal.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOneLifecycleHasExactCrossAdapterDataPolicyAndAuditParity(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $installer = TestKernelFactory::administratorContext($container);
        $principal = $installer->principal();
        self::assertInstanceOf(AuthenticatedPrincipal::class, $principal);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $records = $this->service($container, BusinessRecordService::class);
        $targetDocument = NeutralBusinessFixture::relationTargetDocument($suffix, Uuid::uuid7()->toString());
        $targetDocument['portal_exposure'] = true;
        $targetDocument['portal_operations'] = [
            PortalOperation::Browse->value,
            PortalOperation::Read->value,
            PortalOperation::Relation->value,
        ];
        $target = NeutralBusinessFixture::install($container, $installer, $targetDocument);
        $targetRecords = [];
        $targetLabels = [];
        foreach (
            [
                BusinessSurface::Api,
                BusinessSurface::Cli,
                BusinessSurface::Mcp,
                BusinessSurface::Administrator,
                BusinessSurface::Portal,
            ] as $surface
        ) {
            $targetRecords[$surface->value] = Uuid::uuid7()->toString();
            $targetLabels[$surface->value] = sprintf(
                'Adapter parity %s target %s',
                $surface->value,
                $suffix,
            );
            $records->create(new CreateRecordCommand(
                $installer,
                $target->handle,
                ['label' => $targetLabels[$surface->value]],
                NeutralBusinessFixture::idempotencyKey('parity-target-' . $surface->value . '-' . $suffix),
                recordId: $targetRecords[$surface->value],
            ));
        }
        $document = NeutralBusinessFixture::document('parity' . $suffix, Uuid::uuid7()->toString());
        $document['relationships'][] = [
            'handle' => 'related_targets',
            'label' => 'Related targets',
            'kind' => 'many_to_many',
            'target' => $target->handle,
            'ordered' => true,
            'on_delete' => 'restrict',
        ];
        $document['portal_exposure'] = true;
        $document['portal_operations'] = array_map(
            static fn (PortalOperation $operation): string => $operation->value,
            PortalOperation::cases(),
        );
        foreach ($document['views'] as &$view) {
            $view['portal'] = true;
        }
        unset($view);
        foreach ($document['actions'] as &$action) {
            $action['portal'] = true;
        }
        unset($action);
        $installed = NeutralBusinessFixture::install($container, $installer, $document);
        $definition = $installed->handle;
        $record = Uuid::uuid7()->toString();
        $values = NeutralBusinessFixture::recordValues('Adapter parity ' . $suffix);
        $contexts = $this->contexts($principal, $suffix);
        $api = $this->service($container, BusinessRecordApiHandler::class);
        $discovery = $this->service($container, BusinessDefinitionDiscoveryApiHandler::class);
        $browser = $this->service($container, GeneratedBusinessBrowserController::class);
        $mcp = $this->service($container, BusinessMcpHandlers::class);
        $cli = $this->console($container, $principal);
        $tokenFile = $this->file('generated-business-parity-token');
        $beforeAudit = $this->auditCounts($container, $principal->subject());

        $restOperation = 'parity-rest-create-' . $suffix;
        $firstRest = $api->handle($this->apiMutationRequest(
            $contexts[BusinessSurface::Api->value],
            $principal,
            BusinessRecordApiHandler::CREATE,
            $definition,
            ['values' => $values, 'record_id' => $record],
            $restOperation,
        ));
        $replayedRest = $api->handle($this->apiMutationRequest(
            $contexts[BusinessSurface::Api->value],
            $principal,
            BusinessRecordApiHandler::CREATE,
            $definition,
            ['values' => $values, 'record_id' => $record],
            $restOperation,
        ));
        self::assertSame(201, $firstRest->getStatusCode());
        self::assertSame(201, $replayedRest->getStatusCode());
        $firstRestResult = $this->response($firstRest);
        $replayedRestResult = $this->response($replayedRest);
        self::assertFalse($firstRestResult['replayed']);
        self::assertTrue($replayedRestResult['replayed']);
        self::assertSame(
            [...$firstRestResult, 'replayed' => true],
            $replayedRestResult,
        );
        self::assertSame(1, $firstRestResult['version']);
        self::assertSame('true', $replayedRest->getHeaderLine('Idempotency-Replayed'));

        $relationOperation = 'parity-rest-relate-' . $suffix;
        $firstRelationResponse = $api->handle($this->apiRelationMutationRequest(
            $contexts[BusinessSurface::Api->value],
            $principal,
            $definition,
            $record,
            'related_targets',
            $targetRecords[BusinessSurface::Api->value],
            1,
            $relationOperation,
        ));
        $replayedRelationResponse = $api->handle($this->apiRelationMutationRequest(
            $contexts[BusinessSurface::Api->value],
            $principal,
            $definition,
            $record,
            'related_targets',
            $targetRecords[BusinessSurface::Api->value],
            1,
            $relationOperation,
        ));
        self::assertSame(200, $firstRelationResponse->getStatusCode());
        self::assertSame(200, $replayedRelationResponse->getStatusCode());
        $firstRelation = $this->response($firstRelationResponse);
        $replayedRelation = $this->response($replayedRelationResponse);
        self::assertSame(2, $firstRelation['version']);
        self::assertFalse($firstRelation['replayed']);
        self::assertSame([...$firstRelation, 'replayed' => true], $replayedRelation);
        self::assertSame('true', $replayedRelationResponse->getHeaderLine('Idempotency-Replayed'));

        $cliRelation = $this->execute($cli, [
            'relate',
            '--site=default',
            '--token-file=' . $tokenFile,
            '--definition=' . $definition,
            '--record=' . $record,
            '--expected-version=2',
            '--relationship=related_targets',
            '--target-record=' . $targetRecords[BusinessSurface::Cli->value],
            '--position=1',
            '--operation-id=parity-cli-relate-' . $suffix,
        ])['data'];
        self::assertSame(3, $cliRelation['version']);
        self::assertFalse($cliRelation['replayed']);

        $mcpRelationOperation = 'parity-mcp-relate-' . $suffix;
        $mcpRelationPlan = $mcp->planMutation(
            $contexts[BusinessSurface::Mcp->value],
            $mcpRelationOperation,
            'relate',
            $definition,
            $record,
            3,
            relationship: 'related_targets',
            target: $targetRecords[BusinessSurface::Mcp->value],
            position: 2,
        );
        self::assertIsString($mcpRelationPlan['plan']);
        $mcpRelation = $mcp->relate(
            $contexts[BusinessSurface::Mcp->value],
            $mcpRelationOperation,
            $mcpRelationPlan['plan'],
            $definition,
            $record,
            3,
            'related_targets',
            $targetRecords[BusinessSurface::Mcp->value],
            2,
        );
        self::assertSame(4, $mcpRelation['version']);
        self::assertFalse($mcpRelation['replayed']);

        $adminRelation = $browser->dispatch(
            $contexts[BusinessSurface::Administrator->value],
            BusinessSurface::Administrator,
            '/administrator/business',
            'POST',
            $definition,
            $record,
            [],
            [
                'operation' => 'relate',
                'operation_id' => 'parity-admin-relate-' . $suffix,
                'expected_version' => '4',
                'relationship' => 'related_targets',
                'target_record_id' => $targetRecords[BusinessSurface::Administrator->value],
                'position' => '3',
            ],
        );
        self::assertNotNull($adminRelation->redirect);

        $portalRelation = $browser->dispatch(
            $contexts[BusinessSurface::Portal->value],
            BusinessSurface::Portal,
            '/portal/business',
            'POST',
            $definition,
            $record,
            [],
            [
                'operation' => 'relate',
                'operation_id' => 'parity-portal-relate-' . $suffix,
                'expected_version' => '5',
                'relationship' => 'related_targets',
                'target_record_id' => $targetRecords[BusinessSurface::Portal->value],
                'position' => '4',
            ],
        );
        self::assertNotNull($portalRelation->redirect);

        $updateValues = ['name' => 'Adapter parity updated ' . $suffix];
        $updateFile = $this->jsonFile($updateValues);
        $cliOperation = 'parity-cli-update-' . $suffix;
        $cliArguments = [
            'update',
            '--site=default',
            '--token-file=' . $tokenFile,
            '--definition=' . $definition,
            '--record=' . $record,
            '--expected-version=6',
            '--values-file=' . $updateFile,
            '--operation-id=' . $cliOperation,
        ];
        $firstCli = $this->execute($cli, $cliArguments);
        $replayedCli = $this->execute($cli, $cliArguments);
        self::assertSame(7, $firstCli['data']['version']);
        self::assertFalse($firstCli['data']['replayed']);
        self::assertTrue($replayedCli['data']['replayed']);
        self::assertSame([...$firstCli['data'], 'replayed' => true], $replayedCli['data']);

        $mcpOperation = 'parity-mcp-action-' . $suffix;
        $plan = $mcp->planMutation(
            $contexts[BusinessSurface::Mcp->value],
            $mcpOperation,
            'execute_action',
            $definition,
            $record,
            7,
            action: 'approve',
        );
        self::assertIsString($plan['plan']);
        $firstMcp = $mcp->executeAction(
            $contexts[BusinessSurface::Mcp->value],
            $mcpOperation,
            $plan['plan'],
            $definition,
            $record,
            7,
            'approve',
        );
        $replayedMcp = $mcp->executeAction(
            $contexts[BusinessSurface::Mcp->value],
            $mcpOperation,
            $plan['plan'],
            $definition,
            $record,
            7,
            'approve',
        );
        self::assertSame($firstMcp, $replayedMcp);
        self::assertSame(8, $firstMcp['version']);
        self::assertSame('approved', $firstMcp['workflow_state']);

        $adminOperation = 'parity-admin-archive-' . $suffix;
        $adminForm = [
            'operation' => 'archive',
            'operation_id' => $adminOperation,
            'expected_version' => '8',
            'confirmed' => '1',
        ];
        $firstAdmin = $browser->dispatch(
            $contexts[BusinessSurface::Administrator->value],
            BusinessSurface::Administrator,
            '/administrator/business',
            'POST',
            $definition,
            $record,
            [],
            $adminForm,
        );
        $replayedAdmin = $browser->dispatch(
            $contexts[BusinessSurface::Administrator->value],
            BusinessSurface::Administrator,
            '/administrator/business',
            'POST',
            $definition,
            $record,
            [],
            $adminForm,
        );
        self::assertSame($firstAdmin->redirect, $replayedAdmin->redirect);
        $archived = $browser->dispatch(
            $contexts[BusinessSurface::Administrator->value],
            BusinessSurface::Administrator,
            '/administrator/business',
            'GET',
            $definition,
            $record,
            ['archived' => '1'],
            [],
        );
        self::assertSame(9, $archived->data['record']['version']);

        $portalOperation = 'parity-portal-restore-' . $suffix;
        $portalForm = [
            'operation' => 'restore',
            'operation_id' => $portalOperation,
            'expected_version' => '9',
            'confirmed' => '1',
        ];
        $firstPortal = $browser->dispatch(
            $contexts[BusinessSurface::Portal->value],
            BusinessSurface::Portal,
            '/portal/business',
            'POST',
            $definition,
            $record,
            [],
            $portalForm,
        );
        $replayedPortal = $browser->dispatch(
            $contexts[BusinessSurface::Portal->value],
            BusinessSurface::Portal,
            '/portal/business',
            'POST',
            $definition,
            $record,
            [],
            $portalForm,
        );
        self::assertSame($firstPortal->redirect, $replayedPortal->redirect);

        $adminRead = $browser->dispatch(
            $contexts[BusinessSurface::Administrator->value],
            BusinessSurface::Administrator,
            '/administrator/business',
            'GET',
            $definition,
            $record,
            [],
            [],
        );
        $portalRead = $browser->dispatch(
            $contexts[BusinessSurface::Portal->value],
            BusinessSurface::Portal,
            '/portal/business',
            'GET',
            $definition,
            $record,
            [],
            [],
        );
        $apiBaseReadResponse = $api->handle($this->apiReadRequest(
            $contexts[BusinessSurface::Api->value],
            $principal,
            $definition,
            $record,
        ));
        self::assertSame(200, $apiBaseReadResponse->getStatusCode());
        $apiBaseRead = $this->response($apiBaseReadResponse);
        self::assertSame([], $apiBaseRead['includes']);
        $cliBaseRead = $this->execute($cli, [
            'get',
            '--site=default',
            '--token-file=' . $tokenFile,
            '--definition=' . $definition,
            '--record=' . $record,
        ])['data'];
        $mcpRead = $mcp->read($contexts[BusinessSurface::Mcp->value], $definition, $record);
        $baseReads = [$cliBaseRead, $mcpRead['record'], $adminRead->data['record'], $portalRead->data['record']];
        foreach ($baseReads as $actual) {
            self::assertSame($apiBaseRead, $actual);
        }

        $apiReadResponse = $api->handle($this->apiReadRequest(
            $contexts[BusinessSurface::Api->value],
            $principal,
            $definition,
            $record,
            ['related_targets'],
        ));
        self::assertSame(200, $apiReadResponse->getStatusCode());
        $apiRead = $this->response($apiReadResponse);
        $apiRelationResponse = $api->handle($this->apiRelationReadRequest(
            $contexts[BusinessSurface::Api->value],
            $principal,
            $definition,
            $record,
            'related_targets',
        ));
        self::assertSame(200, $apiRelationResponse->getStatusCode());
        $apiRelationRead = $this->response($apiRelationResponse);
        self::assertSame($apiRead, $apiRelationRead);
        $readQueryFile = $this->jsonFile([
            'projection' => ['includes' => ['related_targets']],
        ]);
        $cliRead = $this->execute($cli, [
            'get',
            '--site=default',
            '--token-file=' . $tokenFile,
            '--definition=' . $definition,
            '--record=' . $record,
            '--query-file=' . $readQueryFile,
        ])['data'];
        $mcpIncludedPage = $mcp->search($contexts[BusinessSurface::Mcp->value], $definition, [
            'page_size' => 1,
            'projection' => ['includes' => ['related_targets']],
        ]);
        self::assertCount(1, $mcpIncludedPage['items']);
        self::assertSame($record, $mcpIncludedPage['items'][0]['record_id']);
        $adminRelationRead = $browser->relationship(
            $contexts[BusinessSurface::Administrator->value],
            BusinessSurface::Administrator,
            $definition,
            $record,
            'related_targets',
            [],
        );
        $portalRelationRead = $browser->relationship(
            $contexts[BusinessSurface::Portal->value],
            BusinessSurface::Portal,
            $definition,
            $record,
            'related_targets',
            [],
        );
        $canonicalRelatedRows = static fn (array $rows): array => array_map(
            static fn (array $row): array => [
                'definition_version' => $row['definition_version'],
                'record_id' => $row['record_id'],
                'version' => $row['version'],
                'position' => $row['position'],
                'values' => $row['values'],
            ],
            $rows,
        );
        $relatedTargets = $apiRead['includes']['related_targets'];
        foreach (
            [
                $apiRelationRead['includes']['related_targets'],
                $cliRead['includes']['related_targets'],
                $mcpIncludedPage['items'][0]['includes']['related_targets'],
            ] as $actual
        ) {
            self::assertSame($relatedTargets, $actual);
        }
        foreach (
            [
                $adminRelationRead->data['record']['includes']['related_targets'],
                $portalRelationRead->data['record']['includes']['related_targets'],
            ] as $actual
        ) {
            self::assertSame($canonicalRelatedRows($relatedTargets), $canonicalRelatedRows($actual));
        }
        foreach (
            [
                $adminRelationRead->data['record']['includes']['related_targets'],
                $portalRelationRead->data['record']['includes']['related_targets'],
            ] as $browserTargets
        ) {
            self::assertCount(5, $browserTargets);
            foreach ($browserTargets as $position => $browserTarget) {
                self::assertSame(array_values($targetLabels)[$position], $browserTarget['label']);
                self::assertNotSame([], $browserTarget['fields']);
                self::assertContains(
                    array_values($targetLabels)[$position],
                    array_column($browserTarget['fields'], 'display'),
                );
                self::assertArrayNotHasKey('record_key', $browserTarget);
                self::assertArrayNotHasKey('definition_id', $browserTarget);
                foreach ($browserTarget['fields'] as $field) {
                    self::assertSame(['handle', 'label', 'display', 'provenance'], array_keys($field));
                }
            }
        }
        self::assertSame(
            array_column($adminRelationRead->data['record']['includes']['related_targets'], 'fields'),
            array_column($portalRelationRead->data['record']['includes']['related_targets'], 'fields'),
            'The two browser adapters must present a related row identically, conversion provenance included: '
                . 'a converted amount described on one surface and not the other is the audit defect, not a '
                . 'rendering difference.',
        );
        self::assertSame(10, $apiRead['version']);
        self::assertSame('approved', $apiRead['workflow_state']);
        self::assertSame($updateValues['name'], $apiRead['values']['name']);
        self::assertSame($values['amount'], $apiRead['values']['amount']);
        self::assertSame($values['price']['amount'], $apiRead['values']['price']['amount']);
        self::assertSame($values['quantity']['amount'], $apiRead['values']['quantity']['amount']);
        self::assertArrayNotHasKey('credential', $apiRead['values']);
        self::assertCount(5, $apiRead['includes']['related_targets']);
        foreach (array_values($targetRecords) as $position => $targetRecord) {
            $related = $apiRead['includes']['related_targets'][$position];
            self::assertSame($targetRecord, $related['record_id']);
            self::assertSame($position, $related['position']);
            self::assertSame(array_values($targetLabels)[$position], $related['values']['label']);
        }

        $apiMetadataResponse = $discovery->handle($this->apiDiscoveryRequest(
            $contexts[BusinessSurface::Api->value],
            $principal,
            $definition,
        ));
        self::assertSame(200, $apiMetadataResponse->getStatusCode());
        $apiMetadata = $this->response($apiMetadataResponse)['data'];
        $cliMetadata = $this->execute($cli, [
            'schema',
            '--site=default',
            '--token-file=' . $tokenFile,
            '--definition=' . $definition,
        ])['data'];
        $mcpMetadata = $mcp->inspect($contexts[BusinessSurface::Mcp->value], $definition)['definition'];
        foreach ([$cliMetadata, $mcpMetadata] as $actual) {
            self::assertSame($apiMetadata, $actual);
        }
        $lazyRelationshipDefaults = [
            'loaded' => false,
            'owned_line_form' => null,
            'choices' => [],
            'choice_available' => false,
        ];
        foreach ([$adminRead->data['definition'], $portalRead->data['definition']] as $actual) {
            self::assertIsArray($actual);
            self::assertIsArray($actual['relationships'] ?? null);
            foreach ($actual['relationships'] as $position => $relationship) {
                self::assertIsArray($relationship);
                foreach ($lazyRelationshipDefaults as $key => $expected) {
                    self::assertArrayHasKey($key, $relationship);
                    self::assertSame($expected, $relationship[$key]);
                    unset($relationship[$key]);
                }
                $actual['relationships'][$position] = $relationship;
            }
            self::assertSame($apiMetadata, $actual);
        }

        $apiHistoryResponse = $api->handle($this->apiHistoryRequest(
            $contexts[BusinessSurface::Api->value],
            $principal,
            $definition,
            $record,
        ));
        self::assertSame(200, $apiHistoryResponse->getStatusCode());
        $apiHistory = $this->response($apiHistoryResponse);
        $cliHistory = $this->execute($cli, [
            'history',
            '--site=default',
            '--token-file=' . $tokenFile,
            '--definition=' . $definition,
            '--record=' . $record,
        ])['data'];
        $mcpHistory = $mcp->history($contexts[BusinessSurface::Mcp->value], $definition, $record);
        $adminHistory = $this->browserHistory($browser->dispatch(
            $contexts[BusinessSurface::Administrator->value],
            BusinessSurface::Administrator,
            '/administrator/business',
            'GET',
            $definition,
            $record,
            ['history' => '1'],
            [],
        )->data);
        $portalHistory = $this->browserHistory($browser->dispatch(
            $contexts[BusinessSurface::Portal->value],
            BusinessSurface::Portal,
            '/portal/business',
            'GET',
            $definition,
            $record,
            ['history' => '1'],
            [],
        )->data);
        self::assertSame($apiHistory, $cliHistory);
        self::assertSame($apiHistory, $mcpHistory);
        self::assertSame($apiHistory, $adminHistory);
        self::assertSame($apiHistory, $portalHistory);
        self::assertSame([
            'restore',
            'archive',
            'action.approve',
            'update',
            'relate.related_targets',
            'relate.related_targets',
            'relate.related_targets',
            'relate.related_targets',
            'relate.related_targets',
            'create',
        ], array_column($apiHistory['items'], 'operation'));
        self::assertFalse($apiHistory['has_more']);
        self::assertNull($apiHistory['next_before_version']);

        $staleApi = $api->handle($this->apiLifecycleMutationRequest(
            $contexts[BusinessSurface::Api->value],
            $principal,
            BusinessRecordApiHandler::ARCHIVE,
            $definition,
            $record,
            1,
            'parity-rest-stale-' . $suffix,
        ));
        self::assertSame(412, $staleApi->getStatusCode());
        self::assertSame(
            'urn:kumwe:problem:precondition-failed',
            $this->response($staleApi)['type'],
        );
        $staleCli = $this->executeFailure($cli, [
            'archive',
            '--site=default',
            '--token-file=' . $tokenFile,
            '--definition=' . $definition,
            '--record=' . $record,
            '--expected-version=1',
            '--operation-id=parity-cli-stale-' . $suffix,
        ]);
        self::assertSame(BusinessConsoleFailureMapper::EXIT_CONFLICT, $staleCli['exit']);
        self::assertSame('business_record.version_conflict', $staleCli['body']['error']['code']);
        self::assertSame(
            ['expected_version' => 1, 'actual_version' => 10],
            $staleCli['body']['error']['details'],
        );
        $this->assertVersionConflict(fn (): array => $mcp->planMutation(
            $contexts[BusinessSurface::Mcp->value],
            'parity-mcp-stale-' . $suffix,
            'archive',
            $definition,
            $record,
            1,
        ));
        foreach ([BusinessSurface::Administrator, BusinessSurface::Portal] as $surface) {
            $this->assertVersionConflict(fn () => $browser->dispatch(
                $contexts[$surface->value],
                $surface,
                $surface === BusinessSurface::Administrator
                    ? '/administrator/business'
                    : '/portal/business',
                'POST',
                $definition,
                $record,
                [],
                [
                    'operation' => 'archive',
                    'operation_id' => 'parity-' . $surface->value . '-stale-' . $suffix,
                    'expected_version' => '1',
                    'confirmed' => '1',
                ],
            ));
        }
        self::assertSame($apiRead, $this->response($api->handle($this->apiReadRequest(
            $contexts[BusinessSurface::Api->value],
            $principal,
            $definition,
            $record,
            ['related_targets'],
        ))));

        $afterAudit = $this->auditCounts($container, $principal->subject());
        foreach (
            [
                'business.record.create' => 1,
                'business.record.relate.related_targets' => 5,
                'business.record.update' => 1,
                'business.record.action.approve' => 1,
                'business.record.archive' => 1,
                'business.record.restore' => 1,
            ] as $action => $expected
        ) {
            self::assertSame($expected, ($afterAudit[$action] ?? 0) - ($beforeAudit[$action] ?? 0), $action);
        }

        NeutralBusinessFixture::removeRecordAccess($container, $installed->id);
        $deniedApi = $api->handle($this->apiReadRequest(
            $contexts[BusinessSurface::Api->value],
            $principal,
            $definition,
            $record,
        ));
        self::assertSame(404, $deniedApi->getStatusCode());
        self::assertSame(
            'urn:kumwe:problem:business-record-not-found',
            $this->response($deniedApi)['type'],
        );
        $deniedCli = $this->executeFailure($cli, [
            'get',
            '--site=default',
            '--token-file=' . $tokenFile,
            '--definition=' . $definition,
            '--record=' . $record,
        ]);
        self::assertSame(BusinessConsoleFailureMapper::EXIT_NOT_FOUND, $deniedCli['exit']);
        self::assertSame('business_record.not_found', $deniedCli['body']['error']['code']);
        $this->assertNotFound(fn (): array => $mcp->read(
            $contexts[BusinessSurface::Mcp->value],
            $definition,
            $record,
        ));
        foreach ([BusinessSurface::Administrator, BusinessSurface::Portal] as $surface) {
            $this->assertNotFound(fn () => $browser->dispatch(
                $contexts[$surface->value],
                $surface,
                $surface === BusinessSurface::Administrator
                    ? '/administrator/business'
                    : '/portal/business',
                'GET',
                $definition,
                $record,
                [],
                [],
            ));
        }
    }

    /**
     * Mint delivery-specific contexts for the same authenticated actor and authority.
     *
     * @param   AuthenticatedPrincipal  $principal  Real authenticated integration administrator.
     * @param   string                  $suffix     Per-test request identity suffix.
     *
     * @return  array<string, ExecutionContext>  Context indexed by generated surface value.
     *
     * @since   2.0.0
     */
    private function contexts(AuthenticatedPrincipal $principal, string $suffix): array
    {
        $contexts = [];
        foreach (BusinessSurface::cases() as $surface) {
            $authenticated = match ($surface) {
                BusinessSurface::Administrator => AuthenticatedSurface::Administrator,
                BusinessSurface::Portal => AuthenticatedSurface::Portal,
                BusinessSurface::Api => AuthenticatedSurface::Api,
                BusinessSurface::Cli => AuthenticatedSurface::Cli,
                BusinessSurface::Mcp => AuthenticatedSurface::Mcp,
            };
            $strength = in_array($surface, [BusinessSurface::Administrator, BusinessSurface::Portal], true)
                ? AuthenticationStrength::Password
                : AuthenticationStrength::BearerToken;
            $contexts[$surface->value] = $principal->context(
                SiteContext::default(),
                $strength,
                'adapter-parity-' . $surface->value . '-' . $suffix,
                surface: $authenticated,
            );
        }

        return $contexts;
    }

    /**
     * Construct the real CLI adapter with a verifier returning the shared integration principal.
     *
     * @param   Container               $container  Real application container.
     * @param   AuthenticatedPrincipal  $principal  Principal a protected test token resolves to.
     *
     * @return  ManageBusinessRecordsCommand  Fully wired command adapter.
     *
     * @since   2.0.0
     */
    private function console(Container $container, AuthenticatedPrincipal $principal): ManageBusinessRecordsCommand
    {
        $tokens = $this->createStub(AccessTokenVerifier::class);
        $tokens->method('verify')->willReturn($principal);

        return new ManageBusinessRecordsCommand(
            $this->service($container, BusinessRecordService::class),
            $this->service($container, BusinessSurfaceService::class),
            $this->service($container, BusinessSurfaceCatalog::class),
            $this->service($container, BusinessRecordQueryFactory::class),
            $this->service($container, BusinessRecordProjector::class),
            $this->service($container, BusinessOperationStatusService::class),
            $this->service($container, BusinessApprovalSurfaceService::class),
            new ConsoleAuthorizer($tokens),
            $this->service($container, BusinessRecordConsolePresenter::class),
            $this->service($container, BusinessConsoleFailureMapper::class),
        );
    }

    /**
     * Execute one CLI invocation and decode its sole success envelope.
     *
     * @param   ManageBusinessRecordsCommand  $command    Real generated-business command.
     * @param   list<string>                  $arguments  Command action and options.
     *
     * @return  array<string, mixed>  Decoded stable success envelope.
     *
     * @since   2.0.0
     */
    private function execute(ManageBusinessRecordsCommand $command, array $arguments): array
    {
        $output = new GeneratedBusinessParityOutput();

        self::assertSame(0, $command->execute($arguments, $output), implode("\n", $output->errors));
        self::assertSame([], $output->errors);
        self::assertCount(1, $output->lines);

        return $this->json($output->lines[0]);
    }

    /**
     * Execute one CLI invocation and decode its sole stable failure envelope.
     *
     * @param   ManageBusinessRecordsCommand  $command    Real generated-business command.
     * @param   list<string>                  $arguments  Command action and options.
     *
     * @return  array{exit: int, body: array<string, mixed>}  Exit status and decoded failure envelope.
     *
     * @since   2.0.0
     */
    private function executeFailure(ManageBusinessRecordsCommand $command, array $arguments): array
    {
        $output = new GeneratedBusinessParityOutput();
        $exit = $command->execute($arguments, $output);

        self::assertNotSame(0, $exit);
        self::assertSame([], $output->lines);
        self::assertCount(1, $output->errors);

        return ['exit' => $exit, 'body' => $this->json($output->errors[0])];
    }

    /**
     * Build one authenticated REST mutation request with its parsed idempotency attribute.
     *
     * @param   ExecutionContext       $context      API-bound context.
     * @param   AuthenticatedPrincipal $principal    Matching request principal.
     * @param   string                 $operation    Closed route operation.
     * @param   string                 $definition   Installed definition handle.
     * @param   array<string, mixed>   $body         JSON request document.
     * @param   string                 $operationId  Parsed idempotency identity.
     *
     * @return  \Psr\Http\Message\ServerRequestInterface  Ready direct-handler request.
     *
     * @since   2.0.0
     */
    private function apiMutationRequest(
        ExecutionContext $context,
        AuthenticatedPrincipal $principal,
        string $operation,
        string $definition,
        array $body,
        string $operationId,
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/api/v1/business/records/' . $definition)
            ->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)))
            ->withAttribute(BusinessRecordApiHandler::OPERATION_ATTRIBUTE, $operation)
            ->withAttribute(BusinessRecordApiHandler::DEFINITION_ATTRIBUTE, $definition)
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContextAttribute::NAME, $context)
            ->withAttribute(
                RequireIdempotencyKeyMiddleware::ATTRIBUTE,
                HttpIdempotencyKey::fromHeader($operationId),
            );
    }

    /**
     * Build one authenticated REST relationship mutation with replay and version attributes.
     *
     * @param   ExecutionContext        $context          API-bound context.
     * @param   AuthenticatedPrincipal  $principal        Matching request principal.
     * @param   string                  $definition       Installed source definition handle.
     * @param   string                  $record           Public source record identity.
     * @param   string                  $relationship     Declared relationship handle.
     * @param   string                  $target           Public target record identity.
     * @param   int                     $expectedVersion  Source version the caller observed.
     * @param   string                  $operationId      Parsed idempotency identity.
     *
     * @return  \Psr\Http\Message\ServerRequestInterface  Ready direct-handler request.
     *
     * @since   2.0.0
     */
    private function apiRelationMutationRequest(
        ExecutionContext $context,
        AuthenticatedPrincipal $principal,
        string $definition,
        string $record,
        string $relationship,
        string $target,
        int $expectedVersion,
        string $operationId,
    ): \Psr\Http\Message\ServerRequestInterface {
        $body = ['target_record_id' => $target, 'position' => 0];

        return (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/api/v1/business/relation')
            ->withHeader('If-Match', '"v' . $expectedVersion . '"')
            ->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)))
            ->withAttribute(BusinessRecordApiHandler::OPERATION_ATTRIBUTE, BusinessRecordApiHandler::RELATE)
            ->withAttribute(BusinessRecordApiHandler::DEFINITION_ATTRIBUTE, $definition)
            ->withAttribute(BusinessRecordApiHandler::RECORD_ATTRIBUTE, $record)
            ->withAttribute(BusinessRecordApiHandler::RELATIONSHIP_ATTRIBUTE, $relationship)
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContextAttribute::NAME, $context)
            ->withAttribute(
                RequireIfMatchMiddleware::ATTRIBUTE,
                IfMatch::fromHeader('"v' . $expectedVersion . '"'),
            )
            ->withAttribute(
                RequireIdempotencyKeyMiddleware::ATTRIBUTE,
                HttpIdempotencyKey::fromHeader($operationId),
            );
    }

    /**
     * Build one authenticated REST lifecycle mutation with replay and version attributes.
     *
     * @param   ExecutionContext        $context          API-bound context.
     * @param   AuthenticatedPrincipal  $principal        Matching request principal.
     * @param   string                  $operation        Closed lifecycle route operation.
     * @param   string                  $definition       Installed definition handle.
     * @param   string                  $record           Public record identity.
     * @param   int                     $expectedVersion  Record version the caller observed.
     * @param   string                  $operationId      Parsed idempotency identity.
     *
     * @return  \Psr\Http\Message\ServerRequestInterface  Ready direct-handler request.
     *
     * @since   2.0.0
     */
    private function apiLifecycleMutationRequest(
        ExecutionContext $context,
        AuthenticatedPrincipal $principal,
        string $operation,
        string $definition,
        string $record,
        int $expectedVersion,
        string $operationId,
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/api/v1/business/lifecycle')
            ->withBody((new StreamFactory())->createStream(''))
            ->withAttribute(BusinessRecordApiHandler::OPERATION_ATTRIBUTE, $operation)
            ->withAttribute(BusinessRecordApiHandler::DEFINITION_ATTRIBUTE, $definition)
            ->withAttribute(BusinessRecordApiHandler::RECORD_ATTRIBUTE, $record)
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContextAttribute::NAME, $context)
            ->withAttribute(
                RequireIfMatchMiddleware::ATTRIBUTE,
                IfMatch::fromHeader('"v' . $expectedVersion . '"'),
            )
            ->withAttribute(
                RequireIdempotencyKeyMiddleware::ATTRIBUTE,
                HttpIdempotencyKey::fromHeader($operationId),
            );
    }

    /**
     * Build one authenticated REST record read request.
     *
     * @param   ExecutionContext        $context     API-bound context.
     * @param   AuthenticatedPrincipal  $principal   Matching request principal.
     * @param   string                  $definition  Installed definition handle.
     * @param   string                  $record      Public record identity.
     * @param   list<string>            $includes    Bounded relationship handles to hydrate.
     *
     * @return  \Psr\Http\Message\ServerRequestInterface  Ready direct-handler request.
     *
     * @since   2.0.0
     */
    private function apiReadRequest(
        ExecutionContext $context,
        AuthenticatedPrincipal $principal,
        string $definition,
        string $record,
        array $includes = [],
    ): \Psr\Http\Message\ServerRequestInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/api/v1/business/records/' . $definition . '/' . $record)
            ->withAttribute(BusinessRecordApiHandler::OPERATION_ATTRIBUTE, BusinessRecordApiHandler::READ)
            ->withAttribute(BusinessRecordApiHandler::DEFINITION_ATTRIBUTE, $definition)
            ->withAttribute(BusinessRecordApiHandler::RECORD_ATTRIBUTE, $record)
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContextAttribute::NAME, $context);

        return $includes === [] ? $request : $request->withQueryParams([
            'projection' => ['includes' => $includes],
        ]);
    }

    /**
     * Build one authenticated REST relationship read request.
     *
     * @param   ExecutionContext        $context       API-bound context.
     * @param   AuthenticatedPrincipal  $principal     Matching request principal.
     * @param   string                  $definition    Installed source definition handle.
     * @param   string                  $record        Public source record identity.
     * @param   string                  $relationship  Declared relationship handle.
     *
     * @return  \Psr\Http\Message\ServerRequestInterface  Ready direct-handler request.
     *
     * @since   2.0.0
     */
    private function apiRelationReadRequest(
        ExecutionContext $context,
        AuthenticatedPrincipal $principal,
        string $definition,
        string $record,
        string $relationship,
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/api/v1/business/relation')
            ->withAttribute(BusinessRecordApiHandler::OPERATION_ATTRIBUTE, BusinessRecordApiHandler::RELATION)
            ->withAttribute(BusinessRecordApiHandler::DEFINITION_ATTRIBUTE, $definition)
            ->withAttribute(BusinessRecordApiHandler::RECORD_ATTRIBUTE, $record)
            ->withAttribute(BusinessRecordApiHandler::RELATIONSHIP_ATTRIBUTE, $relationship)
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContextAttribute::NAME, $context);
    }

    /**
     * Build one authenticated REST history request.
     *
     * @param   ExecutionContext        $context     API-bound context.
     * @param   AuthenticatedPrincipal  $principal   Matching request principal.
     * @param   string                  $definition  Installed definition handle.
     * @param   string                  $record      Public record identity.
     *
     * @return  \Psr\Http\Message\ServerRequestInterface  Ready direct-handler request.
     *
     * @since   2.0.0
     */
    private function apiHistoryRequest(
        ExecutionContext $context,
        AuthenticatedPrincipal $principal,
        string $definition,
        string $record,
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/api/v1/business/history')
            ->withAttribute(BusinessRecordApiHandler::OPERATION_ATTRIBUTE, BusinessRecordApiHandler::HISTORY)
            ->withAttribute(BusinessRecordApiHandler::DEFINITION_ATTRIBUTE, $definition)
            ->withAttribute(BusinessRecordApiHandler::RECORD_ATTRIBUTE, $record)
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContextAttribute::NAME, $context);
    }

    /**
     * Build one authenticated exact-definition discovery request.
     *
     * @param   ExecutionContext        $context     API-bound context.
     * @param   AuthenticatedPrincipal  $principal   Matching request principal.
     * @param   string                  $definition  Installed definition handle.
     *
     * @return  \Psr\Http\Message\ServerRequestInterface  Ready direct-handler request.
     *
     * @since   2.0.0
     */
    private function apiDiscoveryRequest(
        ExecutionContext $context,
        AuthenticatedPrincipal $principal,
        string $definition,
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/api/v1/business/definitions/' . $definition)
            ->withAttribute('definition', $definition)
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContextAttribute::NAME, $context);
    }

    /**
     * Decode one JSON response body as an object.
     *
     * @param   ResponseInterface  $response  Direct handler response.
     *
     * @return  array<string, mixed>  Decoded response object.
     *
     * @since   2.0.0
     */
    private function response(ResponseInterface $response): array
    {
        return $this->json((string) $response->getBody());
    }

    /**
     * Select the shared history document from browser-only page metadata.
     *
     * @param   array<string, mixed>  $document  Browser history page model.
     *
     * @return  array<string, mixed>  Exact shared revision page.
     *
     * @since   2.0.0
     */
    private function browserHistory(array $document): array
    {
        self::assertIsArray($document['items'] ?? null);
        self::assertIsBool($document['has_more'] ?? null);
        self::assertTrue(array_key_exists('next_before_version', $document));

        return [
            'items' => $document['items'],
            'has_more' => $document['has_more'],
            'next_before_version' => $document['next_before_version'],
        ];
    }

    /**
     * Decode one bounded JSON object.
     *
     * @param   string  $encoded  JSON object bytes.
     *
     * @return  array<string, mixed>  Decoded object.
     *
     * @since   2.0.0
     */
    private function json(string $encoded): array
    {
        $value = json_decode($encoded, true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($value);
        self::assertFalse(array_is_list($value));

        return $value;
    }

    /**
     * Assert that one direct adapter preserves the shared optimistic-concurrency failure.
     *
     * @param   callable(): mixed  $operation  Stale adapter invocation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertVersionConflict(callable $operation): void
    {
        try {
            $operation();
            self::fail('A stale generated-business mutation must not succeed.');
        } catch (BusinessRecordVersionConflict $exception) {
            self::assertSame(1, $exception->expectedVersion);
            self::assertSame(10, $exception->actualVersion);
        }
    }

    /**
     * Assert that one direct adapter does not enumerate a policy-hidden record.
     *
     * @param   callable(): mixed  $operation  Policy-hidden adapter invocation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertNotFound(callable $operation): void
    {
        try {
            $operation();
            self::fail('A policy-hidden generated-business record must not be returned.');
        } catch (BusinessRecordNotFound $exception) {
            self::assertSame('business_record.not_found', $exception->stableCode());
        }
    }

    /**
     * Count record audit actions attributed to one actor.
     *
     * @param   Container  $container  Real application container.
     * @param   string     $actor      Authenticated actor UUID.
     *
     * @return  array<string, int>  Count indexed by exact audit action.
     *
     * @since   2.0.0
     */
    private function auditCounts(Container $container, string $actor): array
    {
        $database = $this->service($container, Connection::class);
        $tables = $this->service($container, TableNames::class);
        $rows = $database->fetchAllAssociative(sprintf(
            'SELECT action, COUNT(*) AS total FROM %s WHERE actor_id = ? AND subject_type = ? GROUP BY action',
            $tables->quoted('audit_events'),
        ), [$actor, 'business_record']);
        $counts = [];
        foreach ($rows as $row) {
            if (is_string($row['action'] ?? null) && is_numeric($row['total'] ?? null)) {
                $counts[$row['action']] = (int) $row['total'];
            }
        }

        return $counts;
    }

    /**
     * Create one owner-only temporary text file.
     *
     * @param   string  $contents  Exact file contents.
     *
     * @return  string  Absolute protected path.
     *
     * @since   2.0.0
     */
    private function file(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'kumwe-adapter-parity-');
        self::assertIsString($file);
        $this->files[] = $file;
        self::assertTrue(chmod($file, 0o600));
        self::assertNotFalse(file_put_contents($file, $contents));

        return $file;
    }

    /**
     * Create one owner-only temporary JSON object file.
     *
     * @param   array<string, mixed>  $document  Protected object to encode.
     *
     * @return  string  Absolute protected path.
     *
     * @since   2.0.0
     */
    private function jsonFile(array $document): string
    {
        return $this->file(json_encode($document, JSON_THROW_ON_ERROR));
    }

    /**
     * Resolve one strongly typed service from the real container.
     *
     * @template T of object
     *
     * @param   Container       $container  Real application container.
     * @param   class-string<T> $class      Requested service type.
     *
     * @return  T  Requested service.
     *
     * @since   2.0.0
     */
    private function service(Container $container, string $class): object
    {
        $service = $container->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
