<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Application\Authorization;

use DateTimeImmutable;
use Kumwe\App\Administrator\Http\Handler\AdministratorCreateContentHandler;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionService;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Application\Authorization\SystemPrincipal;
use Kumwe\App\Application\Automation\AutomationManagementService;
use Kumwe\App\Application\Automation\GlobalJobPrincipals;
use Kumwe\App\Application\Automation\JobExecutionScope;
use Kumwe\App\Application\Automation\JobHandlerRegistry;
use Kumwe\App\Application\Automation\JobQueue;
use Kumwe\App\Application\Automation\Scheduler;
use Kumwe\App\Application\Automation\Worker;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Application\ContentRepository;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Content\Domain\ContentEntry;
use Kumwe\App\Delivery\Console\Command\ConsoleAuthorizer;
use Kumwe\App\Delivery\Console\Command\ManageContentCommand;
use Kumwe\App\Delivery\Console\Command\ScheduleRunCommand;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Extension\Application\ExtensionManager;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Runtime\RestrictedExtensionContainer;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Infrastructure\Mcp\BusinessMcpHandlers;
use Kumwe\App\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\App\Infrastructure\Mcp\McpCapabilityCatalog;
use Kumwe\App\Infrastructure\Mcp\McpMutationGuard;
use Kumwe\App\Infrastructure\Mcp\ReportMcpHandlers;
use Kumwe\App\Navigation\Application\NavigationService;
use Kumwe\App\Site\Application\SiteSettings;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Workflow\Domain\Workflow;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use ReflectionClass;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;

#[CoversClass(AdministratorCreateContentHandler::class)]
#[CoversClass(ManageContentCommand::class)]
#[CoversClass(ConsoleAuthorizer::class)]
#[CoversClass(KumweMcpHandlers::class)]
#[CoversClass(Worker::class)]
#[CoversClass(ScheduleRunCommand::class)]
#[CoversClass(RestrictedExtensionContainer::class)]
#[CoversClass(ContentService::class)]
final class AdapterAuthorizationParityTest extends TestCase
{
    private const SUBJECT = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';
    private const PAGE = '018f22e2-7c8b-7ab0-8f3a-88e8026bb401';

    public function testAdministratorHandlerCannotBypassApplicationAuthorization(): void
    {
        $repository = $this->createMock(ContentRepository::class);
        $repository->expects(self::never())->method('insert');
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/administrator/content')
            ->withParsedBody(['title' => 'Denied', 'slug' => 'denied'])
            ->withAttribute(
                ExecutionContextAttribute::NAME,
                $this->context('content.create', 'site', 'another-site'),
            );

        $this->expectException(AuthorizationDenied::class);
        (new AdministratorCreateContentHandler($this->content($repository)))->handle($request);
    }

    public function testCliCapabilityPrecheckCannotWidenScopedGrant(): void
    {
        $repository = $this->createMock(ContentRepository::class);
        $repository->expects(self::never())->method('insert');
        $tokens = $this->createStub(AccessTokenVerifier::class);
        $tokens->method('verify')->willReturn(AuthorizationContext::principalFromGrantRows([[
            'capability' => 'content.create',
            'scope_type' => 'site',
            'scope_identifier' => 'another-site',
        ]], self::SUBJECT));
        $output = $this->createMock(Output::class);
        $output->expects(self::once())->method('error')->with(self::stringContains('not authorized'));
        $tokenFile = tempnam(sys_get_temp_dir(), 'kumwe-token-');
        if (!is_string($tokenFile)) {
            self::fail('A temporary token file could not be created.');
        }
        file_put_contents($tokenFile, 'opaque-test-token');
        chmod($tokenFile, 0600);

        try {
            $result = (new ManageContentCommand(
                $this->content($repository),
                new ConsoleAuthorizer($tokens),
            ))->execute([
                'create',
                '--site=default',
                '--token-file=' . $tokenFile,
                '--title=Denied',
                '--slug=denied',
            ], $output);
        } finally {
            unlink($tokenFile);
        }

        self::assertSame(1, $result);
    }

    public function testMcpRetainsContextProvenanceAndFiltersForeignContext(): void
    {
        $repository = $this->createStub(ContentRepository::class);
        $repository->method('all')->willReturn([$this->record()]);
        $context = AuthenticatedPrincipal::issueFromStrings(
            new \stdClass(),
            self::SUBJECT,
            ['content.read'],
        )->context(
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'foreign-mcp-request',
        );

        $result = $this->mcp($this->content($repository))->forContext($context)->listContent(true);

        self::assertSame([], $result['items']);
    }

    public function testMcpWrongScopeIsDeniedBeforeIdempotencyReservation(): void
    {
        $allowed = '018f22e2-7c8b-7ab0-8f3a-88e8026bb402';
        $denied = '018f22e2-7c8b-7ab0-8f3a-88e8026bb403';
        $repository = $this->createMock(ContentRepository::class);
        $repository->expects(self::never())->method('find');
        $repository->expects(self::never())->method('update');
        $context = $this->context('content.update', 'content', $allowed);

        $this->expectException(AuthorizationDenied::class);
        $this->mcp($this->content($repository))->forContext($context)->updateContent(
            'stable-operation-0001',
            $denied,
            1,
            'Denied',
            'denied',
            'Denied',
        );
    }

    public function testWorkerRejectsHumanContextBeforeTouchingQueue(): void
    {
        $queue = $this->createMock(JobQueue::class);
        $queue->expects(self::never())->method('heartbeat');
        $queue->expects(self::never())->method('claim');
        $ownership = AuthorizationContext::ownership();
        $worker = new Worker(
            $queue,
            new JobHandlerRegistry([]),
            AuthorizationContext::gateway(ownership: $ownership),
            $ownership,
            AuthorizationContext::system(SystemIdentity::Worker),
            new JobExecutionScope(),
            new GlobalJobPrincipals(
                AuthorizationContext::system(SystemIdentity::InstallationMaintenance),
                AuthorizationContext::system(SystemIdentity::ExtensionMaterializer),
            ),
        );

        $this->expectException(AuthorizationDenied::class);
        $worker->runOnce(AuthorizationContext::human(['automation.manage']), 'default', 'worker-test');
    }

    public function testSchedulerCommandCannotUseSystemPrincipalFromForeignAuthority(): void
    {
        $gateway = AuthorizationContext::gateway();
        $scheduler = new class ($gateway) implements Scheduler {
            public function __construct(private AuthorizationGateway $authorization)
            {
            }

            public function dispatchDue(ExecutionContext $context, int $limit = 100): int
            {
                $this->authorization->assertAllowed(
                    $context,
                    Capability::fromString('system.scheduler.dispatch'),
                    AuthorizationResource::collection('schedule'),
                );

                return 0;
            }
        };
        $output = $this->createMock(Output::class);
        $output->expects(self::once())->method('error')->with(self::stringContains('not authorized'));
        $command = new ScheduleRunCommand(
            $scheduler,
            SystemPrincipal::issue(new \stdClass(), SystemIdentity::Scheduler),
        );

        self::assertSame(1, $command->execute([], $output));
    }

    public function testExtensionDirectInvocationCannotForgeApplicationContext(): void
    {
        $repository = $this->createMock(ContentRepository::class);
        $repository->expects(self::never())->method('insert');
        $service = $this->content($repository);
        $container = new RestrictedExtensionContainer('kumwe/example', [ContentService::class => $service]);
        $context = AuthenticatedPrincipal::issueFromStrings(
            new \stdClass(),
            self::SUBJECT,
            ['content.create'],
        )->context(
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'forged-extension-request',
        );

        $this->expectException(AuthorizationDenied::class);
        $resolved = $container->get(ContentService::class);
        if (!$resolved instanceof ContentService) {
            self::fail('The allowlisted content service was not returned.');
        }
        $resolved->create($context, 'Denied', 'denied', []);
    }

    private function content(ContentRepository $repository): ContentService
    {
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $clock = $this->clock();

        return new ContentService(
            $repository,
            $this->createStub(AuditRecorder::class),
            $transactions,
            $clock,
            new Workflow(),
            AuthorizationContext::gateway(),
            AuthorizationContext::ownershipWriter(),
        );
    }

    private function mcp(ContentService $content): KumweMcpHandlers
    {
        return new KumweMcpHandlers(
            new McpCapabilityCatalog(),
            $content,
            $this->withoutConstructor(NavigationService::class),
            $this->withoutConstructor(AccessControlService::class),
            $this->createStub(SiteSettings::class),
            $this->createStub(ExtensionManager::class),
            $this->withoutConstructor(TrustStore::class),
            $this->withoutConstructor(AutomationManagementService::class),
            $this->withoutConstructor(BusinessDefinitionService::class),
            $this->withoutConstructor(BusinessSchemaService::class),
            $this->withoutConstructor(BusinessMcpHandlers::class),
            $this->withoutConstructor(ReportMcpHandlers::class),
            $this->withoutConstructor(McpMutationGuard::class),
            $this->clock(),
            AuthorizationContext::gateway(),
        );
    }

    private function context(string $capability, string $scopeType, ?string $scopeIdentifier): ExecutionContext
    {
        return AuthorizationContext::principalFromGrantRows([[
            'capability' => $capability,
            'scope_type' => $scopeType,
            'scope_identifier' => $scopeIdentifier,
        ]], self::SUBJECT)->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'adapter-parity-request',
        );
    }

    private function record(): ContentRecord
    {
        $at = new DateTimeImmutable('2026-08-05T10:00:00+00:00');

        return new ContentRecord(
            ContentEntry::create(self::PAGE, 'Page', 'page'),
            ContentService::CORE_PAGE_TYPE_ID,
            ContentService::CORE_WORKFLOW_ID,
            $at,
            $at,
        );
    }

    private function clock(): ClockInterface
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-05T10:00:00+00:00'));

        return $clock;
    }

    /** @template T of object @param class-string<T> $class @return T */
    private function withoutConstructor(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
