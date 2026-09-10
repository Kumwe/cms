<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Extension;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use FilesystemIterator;
use InvalidArgumentException;
use Kumwe\App\Administrator\Http\Middleware\AdministratorSessionMiddleware;
use Kumwe\App\Application\Automation\JobHandlerRegistry;
use Kumwe\App\BusinessIntegration\Application\DomainEventDispatcher;
use Kumwe\App\BusinessIntegration\Application\EventContractRegistry;
use Kumwe\App\BusinessIntegration\Application\ValidatedContributedJobHandler;
use Kumwe\App\BusinessIntegration\Domain\RecordedDomainEvent;
use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Extension\Application\ExtensionManager;
use Kumwe\App\Extension\Application\Migration\ScopedExtensionTableNames;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Contribution\AdministratorRouteRegistry;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Contribution\OwnedExtensionBindingRegistrar;
use Kumwe\App\Extension\Infrastructure\DoctrineExtensionManager;
use Kumwe\App\Extension\Runtime\ActiveExtensionSet;
use Kumwe\App\Extension\Runtime\TrustEnforcingRequestHandler;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Identity\Application\Administration\AdministratorSessionStore;
use Kumwe\App\Identity\Domain\UserStatus;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Portal\Application\PortalAuthenticator;
use Kumwe\App\Portal\Application\PortalContext;
use Kumwe\App\Portal\Application\PortalPasswordIdentity;
use Kumwe\App\Portal\Application\PortalSessionStore;
use Kumwe\App\Portal\Contribution\PortalRouteRegistry;
use Kumwe\App\Portal\Http\Middleware\PortalSessionMiddleware;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Extension\Manifest\ExtensionIdentifier;
use Kumwe\Extension\Spi\BusinessIntegration\Application\DomainEventHandler;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\DomainListenerDefinition;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\EventSensitivity;
use Kumwe\Extension\Spi\BusinessReporting\Application\ProjectionBuilder;
use Kumwe\Extension\Spi\BusinessReporting\Application\ProjectionEvent;
use Kumwe\Extension\Spi\BusinessReporting\Application\ProjectionWriter;
use Kumwe\Extension\Spi\BusinessReporting\Domain\ProjectionDefinition;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\Extension\Toolchain\ComponentScaffolder;
use Kumwe\Extension\Toolchain\DeterministicPackageBuilder;
use Kumwe\Extension\Toolchain\PackageInspector;
use Kumwe\Extension\Toolchain\PackageSigner;
use Kumwe\Extension\Toolchain\ProtectedSigningKeyReader;
use Kumwe\Extension\Toolchain\ScaffoldRequest;
use Kumwe\Extension\Toolchain\StaticConformanceRunner;
use Laminas\Diactoros\ServerRequestFactory;
use Mezzio\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Proves one SDK scaffold crosses the complete production extension lifecycle, executables included.
 *
 * The scaffold is packaged exactly as generated except for one edit: its `php` floor is lowered
 * inside the manifest so the same signed bytes admit on the sandbox PHP as well as on the CI
 * platform, the accommodation `ManifestGenerationLifecycleIntegrationTest` makes for its fixtures.
 * After activation every executable the provider binds is driven by the host rather than by the
 * test: the domain listener through the App's dispatcher against the App's event contracts, the
 * job through the worker registry's validated contributed handler, and both graphical routes through
 * the App HTTP pipeline under a real administrator session and a real portal member session. The
 * projection keeps its direct canonical-port execution.
 *
 * @since  2.0.0
 */
#[CoversClass(OwnedExtensionBindingRegistrar::class)]
#[CoversClass(DoctrineExtensionManager::class)]
#[CoversClass(DomainEventDispatcher::class)]
#[CoversClass(ValidatedContributedJobHandler::class)]
#[CoversClass(AdministratorRouteRegistry::class)]
#[CoversClass(PortalRouteRegistry::class)]
#[CoversClass(TrustEnforcingRequestHandler::class)]
final class GeneratedExtensionLifecycleIntegrationTest extends TestCase
{
    /**
     * Browser identity every session in this test is minted for and every request presents.
     *
     * @var    string
     * @since  2.0.0
     */
    private const USER_AGENT = 'kumwe-generated-lifecycle/2.0';

    /**
     * Payload the generated event schema admits and the generated projection derives its row from.
     *
     * @var    array{item_id: string, title: string}
     * @since  2.0.0
     */
    private const ITEM = ['item_id' => 'generated-item', 'title' => 'Canonical lifecycle execution'];

    /**
     * Scaffold, build, sign, install, activate, execute, disable and uninstall the same package.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCanonicalSdkScaffoldCompletesTheRealApplicationLifecycle(): void
    {
        $environment = Environment::fromGlobals();
        $container = TestKernelFactory::create($environment);
        $manager = $container->get(ExtensionManager::class);
        $trust = $container->get(TrustStore::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        $access = $container->get(AccessControlService::class);
        self::assertInstanceOf(ExtensionManager::class, $manager);
        self::assertInstanceOf(TrustStore::class, $trust);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        self::assertInstanceOf(AccessControlService::class, $access);
        $context = TestKernelFactory::administratorContext($container);

        $marker = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $identifier = 'integration/generated-' . $marker;
        $dotted = str_replace('/', '.', $identifier);
        $keyId = 'integration.generated.' . $marker;
        $temporary = sys_get_temp_dir() . '/kumwe-generated-lifecycle-' . $marker;
        $source = $temporary . '/component';
        $archive = $temporary . '/component.zip';
        $keyFile = $temporary . '/signing.seed';
        $portalEmail = 'generated-portal-' . $marker . '@example.test';
        $portalName = 'Generated portal member ' . $marker;
        $portalPassword = 'generated portal passphrase ' . $marker;
        $administratorEmail = 'generated-administrator-' . $marker . '@example.test';
        $administratorName = 'Generated administrator ' . $marker;
        $administratorPassword = 'generated administrator passphrase ' . $marker;
        $installed = false;
        $trusted = false;
        $componentTable = null;
        $administratorUser = null;
        $administratorRole = null;
        $administratorSessionId = null;
        $portalMember = null;
        $portalSessionId = null;

        self::assertTrue(mkdir($temporary, 0700));
        try {
            $scaffold = (new ComponentScaffolder())->scaffold(new ScaffoldRequest(
                $identifier,
                'Integration\\Generated' . ucfirst($marker),
                $source,
                'Generated lifecycle ' . $marker,
            ));
            self::assertSame($source, $scaffold->directory);
            self::lowerPhpFloor($source . '/kumwe.json');

            $inspector = new PackageInspector();
            $build = (new DeterministicPackageBuilder($inspector))->build($source, $archive);
            $report = (new StaticConformanceRunner($inspector))->run($build->archive);
            self::assertTrue($report->conforms());

            $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
            self::assertSame(64, file_put_contents($keyFile, bin2hex($seed), LOCK_EX));
            self::assertTrue(chmod($keyFile, 0600));
            $signature = (new PackageSigner(new ProtectedSigningKeyReader(), $inspector))->sign(
                $build->archive,
                $keyId,
                $keyFile,
            );
            $publicKey = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed));
            $trust->add(
                $context,
                $keyId,
                base64_encode($publicKey),
                'integration',
                'generated-' . $marker,
                new DateTimeImmutable('+1 year'),
            );
            $trusted = true;

            $result = $manager->install($build->archive, $context, $keyId, $signature->base64Signature);
            $installed = true;
            self::assertSame('disabled', $result['status']);
            $componentTable = (new ScopedExtensionTableNames(
                $tables->raw(...),
                static fn (string $part): string => $database->getDatabasePlatform()->quoteSingleIdentifier($part),
                ExtensionIdentifier::fromString($identifier),
            ))->raw('component_records');
            self::assertTrue($database->createSchemaManager()->tablesExist([$componentTable]));

            $manager->activate($identifier, $context);
            $trust->synchronizeRuntimeMaterialization();
            $runtime = TestKernelFactory::create($environment);
            $active = $runtime->get(ActiveExtensionSet::class);
            $registries = $runtime->get(ExtensionContributionRegistrySet::class);
            self::assertInstanceOf(ActiveExtensionSet::class, $active);
            self::assertInstanceOf(ExtensionContributionRegistrySet::class, $registries);
            self::assertGreaterThanOrEqual(1, $active->count());
            // An execution context is provenance-bound and carries no authority in another kernel, so
            // everything the fresh kernel authorizes runs under a context that kernel issued.
            $runtimeContext = TestKernelFactory::administratorContext($runtime);

            $owner = ContributionOwner::extension($identifier);
            $projectionIdentifier = $dotted . '.item_projection';
            $definition = $registries->projections()->definition($owner, $projectionIdentifier);
            $projection = $registries->projections()->implementation($owner, $projectionIdentifier);
            self::assertInstanceOf(ProjectionDefinition::class, $definition);
            self::assertInstanceOf(ProjectionBuilder::class, $projection);
            $writer = new GeneratedLifecycleProjectionWriter();
            $projection->apply(
                $definition,
                new GeneratedLifecycleProjectionEvent($dotted . '.item_observed'),
                $writer,
            );
            self::assertSame(
                [['key' => ['item_id' => self::ITEM['item_id']], 'values' => self::ITEM]],
                $writer->writes,
            );

            // The scaffold guards both routes with the capability it declares. An installation
            // administrator holding `extensions.manage` may bootstrap the first grant of an
            // extension-owned capability, which is how a freshly activated package ever gains a holder:
            // once for a generated administrator, once for an ordinary portal member who holds nothing
            // else. Both actors belong to this run: a role assignment advances its subject's security
            // epoch, which retires every session and token minted under the previous authority, so the
            // seeded integration administrator, whose epoch other proofs assert, is never the subject.
            $runtimeAccess = $runtime->get(AccessControlService::class);
            $identities = $runtime->get(AdministratorIdentityGateway::class);
            self::assertInstanceOf(AccessControlService::class, $runtimeAccess);
            self::assertInstanceOf(AdministratorIdentityGateway::class, $identities);
            $administratorUser = $runtimeAccess->createUser(
                $runtimeContext,
                $administratorEmail,
                $administratorName,
                $administratorPassword,
            );
            $administratorRole = $runtimeAccess->createRole(
                $runtimeContext,
                'generated-' . $marker,
                'Generated lifecycle ' . $marker,
            );
            $runtimeAccess->grant($runtimeContext, $administratorRole, 'administrator.access');
            $runtimeAccess->grant($runtimeContext, $administratorRole, $dotted . '.access');
            $runtimeAccess->assignRole($runtimeContext, $administratorUser, $administratorRole);
            $portalMember = $runtimeAccess->createUser($runtimeContext, $portalEmail, $portalName, $portalPassword);
            $portalRole = $runtimeAccess->createRole($runtimeContext, 'generated-' . $marker . '-portal', $portalName);
            $runtimeAccess->grant($runtimeContext, $portalRole, 'portal.access', 'site', SiteContext::DEFAULT);
            $runtimeAccess->grant($runtimeContext, $portalRole, $dotted . '.access', 'site', SiteContext::DEFAULT);
            $runtimeAccess->assignRole($runtimeContext, $portalMember, $portalRole);
            // The generated administrator authenticates through the identity gateway, exactly as a
            // login does, before it can hold a browser session that carries the new capability.
            $administratorPrincipal = $identities->authenticate(
                $administratorEmail,
                $administratorPassword,
                'integration-tests',
            );
            self::assertNotNull($administratorPrincipal);
            self::assertTrue($administratorPrincipal->hasCapability(Capability::fromString($dotted . '.access')));
            $administratorContext = $administratorPrincipal->context(
                SiteContext::default(),
                AuthenticationStrength::Password,
                'integration-generated-' . $marker,
            );

            // Both routes are mounted where the App-composed registries say they are, behind the
            // App's own session boundaries: an anonymous navigation never reaches extension code.
            $application = $runtime->get(Application::class);
            $sessions = $runtime->get(AdministratorSessionStore::class);
            self::assertInstanceOf(Application::class, $application);
            self::assertInstanceOf(AdministratorSessionStore::class, $sessions);
            $administratorPath = $registries->routes()->ownedBy($owner)[0]['registered_path'] ?? null;
            $portalPath = $registries->portalRoutes()->ownedBy($owner)[0]['registered_path'] ?? null;
            self::assertSame('/administrator/extensions/' . $identifier, $administratorPath);
            self::assertSame('/portal/extensions/' . $identifier, $portalPath);
            $anonymous = $application->handle(self::request($administratorPath));
            self::assertSame(303, $anonymous->getStatusCode());
            self::assertSame('/administrator/login', $anonymous->getHeaderLine('Location'));
            $anonymous = $application->handle(self::request($portalPath));
            self::assertSame(303, $anonymous->getStatusCode());
            self::assertSame('/portal/login', $anonymous->getHeaderLine('Location'));

            $administratorSession = $sessions->create($administratorContext, self::USER_AGENT);
            $administratorSessionId = $administratorSession->session->id;
            $administratorCookie = [AdministratorSessionMiddleware::COOKIE_NAME => $administratorSession->token];
            $before = $application->handle(self::request($administratorPath, $administratorCookie));
            self::assertSame(200, $before->getStatusCode());
            $body = (string) $before->getBody();
            self::assertStringContainsString('data-kis-surface="' . $dotted . '.administrator.index"', $body);
            self::assertStringContainsString('<dt>Domain events observed</dt><dd>0</dd>', $body);
            self::assertStringContainsString(
                '<dt>Latest job digest</dt><dd>No job observed in this process</dd>',
                $body,
            );

            // The listener runs only through the App's dispatcher, composed exactly as the record
            // mutation publisher composes it: the kernel's event contracts and the owner-bound listener
            // entries of the kernel's registries. The contracts validate the event before any listener
            // sees it, so an undeclared payload member is refused at the App boundary.
            $contracts = $runtime->get(EventContractRegistry::class);
            $execution = $runtime->get(ExtensionExecutionGate::class);
            self::assertInstanceOf(EventContractRegistry::class, $contracts);
            self::assertInstanceOf(ExtensionExecutionGate::class, $execution);
            self::assertNotNull($registries->domainListeners()->definition($owner, $dotted . '.item_listener'));
            $execution->assertCurrent();
            $dispatcher = new DomainEventDispatcher($contracts, self::domainListeners($registries));
            $dispatcher->dispatch(self::domainEvent($dotted, $runtimeContext, self::ITEM));
            self::assertRefused(static fn () => $dispatcher->dispatch(
                self::domainEvent($dotted, $runtimeContext, self::ITEM + ['undeclared' => true]),
            ));

            // The job runs only through the worker registry the kernel composed, where the signed
            // declaration wraps the implementation in the App's payload-validating handler.
            $jobs = $runtime->get(JobHandlerRegistry::class);
            self::assertInstanceOf(JobHandlerRegistry::class, $jobs);
            $job = $jobs->find($dotted . '.digest');
            self::assertInstanceOf(ValidatedContributedJobHandler::class, $job);
            self::assertSame($dotted . '.digest', $job->type());
            $workerContext = TestKernelFactory::workerContext($runtime);
            $message = 'generated-digest-' . $marker;
            $job->handle(['message' => $message], $workerContext);
            self::assertRefused(static fn () => $job->handle(
                ['message' => $message, 'undeclared' => true],
                $workerContext,
            ));

            // The administrator route is the App-visible effect of both: the scaffold's ledger is
            // process-local and its overview renders exactly what the listener and the job recorded.
            $after = $application->handle(self::request($administratorPath, $administratorCookie));
            self::assertSame(200, $after->getStatusCode());
            $body = (string) $after->getBody();
            self::assertStringContainsString('data-kis-surface="' . $dotted . '.administrator.index"', $body);
            self::assertStringContainsString('<dt>Domain events observed</dt><dd>1</dd>', $body);
            self::assertStringContainsString('<dt>Durable events observed</dt><dd>0</dd>', $body);
            self::assertStringContainsString(
                '<dt>Latest job digest</dt><dd>' . hash('sha256', $message) . '</dd>',
                $body,
            );

            // The portal route answers a portal member who authenticated through the shared password
            // gateway and holds only `portal.access` and the scaffold's capability: a site-wide member
            // without an organization membership, the session shape the identity loader resolves on
            // every request.
            $authenticator = $runtime->get(PortalAuthenticator::class);
            $portalSessions = $runtime->get(PortalSessionStore::class);
            self::assertInstanceOf(PortalAuthenticator::class, $authenticator);
            self::assertInstanceOf(PortalSessionStore::class, $portalSessions);
            $portalIdentity = $authenticator->authenticate($portalEmail, $portalPassword, 'integration-tests');
            self::assertInstanceOf(PortalPasswordIdentity::class, $portalIdentity);
            self::assertTrue($portalIdentity->principal->hasCapability(Capability::fromString($dotted . '.access')));
            self::assertFalse(
                $portalIdentity->principal->hasCapability(Capability::fromString('administrator.access')),
            );
            $portalSession = $portalSessions->create(
                $portalIdentity,
                new PortalContext(SiteContext::default(), null),
                self::USER_AGENT,
            );
            $portalSessionId = $portalSession->session->id;
            $portal = $application->handle(self::request(
                $portalPath,
                [PortalSessionMiddleware::COOKIE_NAME => $portalSession->cookieToken],
            ));
            self::assertSame(200, $portal->getStatusCode());
            $body = (string) $portal->getBody();
            self::assertStringContainsString('data-kis-surface="' . $dotted . '.portal.index"', $body);
            self::assertStringContainsString('<p>Observed 0 durable events in this runtime process.</p>', $body);

            // Withdrawal is a per-process contract: the process that performs a lifecycle mutation
            // observes its resident graph go stale and withdraws it, so the lifecycle tail runs
            // through the runtime container's own manager, and the HTTP pipeline of that same process
            // drains rather than serve resident extension code again.
            $runtimeManager = $runtime->get(ExtensionManager::class);
            self::assertInstanceOf(ExtensionManager::class, $runtimeManager);
            $runtimeManager->disable($identifier, $runtimeContext);
            $disabled = array_values(array_filter(
                $runtimeManager->installed($runtimeContext),
                static fn (array $extension): bool => ($extension['identifier'] ?? null) === $identifier,
            ));
            self::assertCount(1, $disabled);
            self::assertSame('disabled', $disabled[0]['status'] ?? null);
            self::assertNull($registries->projections()->definition($owner, $projectionIdentifier));
            $drained = $application->handle(self::request($administratorPath, $administratorCookie));
            self::assertSame(503, $drained->getStatusCode());
            self::assertStringNotContainsString($dotted, (string) $drained->getBody());
            $runtimeManager->uninstall($identifier, $runtimeContext);
            $installed = false;
            self::assertSame([], array_values(array_filter(
                $runtimeManager->installed($runtimeContext),
                static fn (array $extension): bool => ($extension['identifier'] ?? null) === $identifier,
            )));
            $trust->revoke($context, $keyId, 'Generated lifecycle acceptance completed.');
            $trusted = false;
        } finally {
            if ($installed) {
                self::quietly(static fn () => $manager->disable($identifier, $context));
                self::quietly(static fn () => $manager->uninstall($identifier, $context));
            }
            if ($trusted) {
                self::quietly(
                    static fn () => $trust->revoke($context, $keyId, 'Generated lifecycle acceptance cleanup.'),
                );
            }
            if ($administratorSessionId !== null) {
                self::quietly(static function () use ($container, $context, $administratorSessionId): void {
                    $store = $container->get(AdministratorSessionStore::class);
                    if ($store instanceof AdministratorSessionStore) {
                        $store->delete($context, $administratorSessionId);
                    }
                });
            }
            if ($portalSessionId !== null && $portalMember !== null) {
                self::quietly(static function () use ($container, $portalSessionId, $portalMember): void {
                    $store = $container->get(PortalSessionStore::class);
                    if ($store instanceof PortalSessionStore) {
                        $store->delete($portalSessionId, $portalMember);
                    }
                });
            }
            if ($administratorRole !== null && $administratorUser !== null) {
                self::quietly(static fn () => $access->revokeRole($context, $administratorUser, $administratorRole));
            }
            if ($administratorUser !== null) {
                self::quietly(static fn () => $access->updateUser(
                    $context,
                    $administratorUser,
                    $administratorEmail,
                    $administratorName,
                    UserStatus::Disabled,
                    1,
                ));
            }
            if ($portalMember !== null) {
                self::quietly(static fn () => $access->updateUser(
                    $context,
                    $portalMember,
                    $portalEmail,
                    $portalName,
                    UserStatus::Disabled,
                    1,
                ));
            }
            if (
                is_string($componentTable)
                && $database->createSchemaManager()->tablesExist([$componentTable])
            ) {
                $database->createSchemaManager()->dropTable($componentTable);
            }
            self::removeTree($temporary);
        }
    }

    /**
     * Lower the scaffold's PHP floor inside the manifest so the package admits on the sandbox platform.
     *
     * The scaffold pins `php ^8.5.0`, the platform CI runs on; a sandbox may run an older PHP, where the
     * host correctly refuses the package before any lifecycle step. Only the floor changes, and the
     * rewrite must hit exactly once so a template that moves the constraint is noticed here.
     *
     * @param   string  $manifest  Absolute path of the scaffolded `kumwe.json`.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function lowerPhpFloor(string $manifest): void
    {
        $json = file_get_contents($manifest);
        self::assertIsString($json);
        $lowered = str_replace('"php": "^8.5.0"', '"php": "^8.3.0"', $json, $replaced);
        self::assertSame(1, $replaced);
        self::assertNotFalse(file_put_contents($manifest, $lowered, LOCK_EX));
    }

    /**
     * Build one browser navigation to a mounted route, optionally presenting a session cookie.
     *
     * @param   string                 $path     Absolute mounted route path.
     * @param   array<string, string>  $cookies  Cookie name and opaque token, empty for an anonymous request.
     *
     * @return  ServerRequestInterface  GET request for the trusted host under the test's browser identity.
     *
     * @since   2.0.0
     */
    private static function request(string $path, array $cookies = []): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test' . $path)
            ->withHeader('Host', 'kumwe.test')
            ->withHeader('User-Agent', self::USER_AGENT)
            ->withHeader('Accept', 'text/html')
            ->withCookieParams($cookies);
    }

    /**
     * Pair every executable domain listener of the loaded registries with its signed declaration.
     *
     * This is the exact composition `BusinessRecordMutationEventPublisher` performs before dispatching.
     *
     * @param   ExtensionContributionRegistrySet  $registries  Loaded runtime contribution registries.
     *
     * @return  list<array{definition: DomainListenerDefinition, handler: DomainEventHandler}>  Dispatch entries.
     *
     * @since   2.0.0
     */
    private static function domainListeners(ExtensionContributionRegistrySet $registries): array
    {
        $listeners = [];
        foreach ($registries->domainListeners()->executableEntries() as $entry) {
            if (
                $entry['definition'] instanceof DomainListenerDefinition
                && $entry['implementation'] instanceof DomainEventHandler
            ) {
                $listeners[] = ['definition' => $entry['definition'], 'handler' => $entry['implementation']];
            }
        }

        return $listeners;
    }

    /**
     * Record one item-observed fact of the generated package under the supplied actor and site.
     *
     * @param   string                $dotted   Dotted package namespace owning the event type.
     * @param   ExecutionContext      $context  Actor, site and trace the fact is attributed to.
     * @param   array<string, mixed>  $payload  Event payload as offered to the App's event contracts.
     *
     * @return  RecordedDomainEvent  Transaction-local fact for the App's dispatcher.
     *
     * @since   2.0.0
     */
    private static function domainEvent(string $dotted, ExecutionContext $context, array $payload): RecordedDomainEvent
    {
        return new RecordedDomainEvent(
            $dotted . '.item_observed',
            1,
            Uuid::uuid7()->toString(),
            new DateTimeImmutable('2026-08-29T00:00:00+00:00'),
            $context->actorId(),
            null,
            $context->site()->identifier(),
            null,
            $dotted . '.item',
            self::ITEM['item_id'],
            1,
            $context->correlationId(),
            $context->requestId(),
            EventSensitivity::INTERNAL,
            $payload,
        );
    }

    /**
     * Assert an operation is refused by an App contract rather than reaching extension code.
     *
     * @param   Closure  $operation  Operation expected to raise `InvalidArgumentException`.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertRefused(Closure $operation): void
    {
        try {
            $operation();
        } catch (InvalidArgumentException) {
            return;
        }
        self::fail('The App contract admitted input outside the signed declaration.');
    }

    /**
     * Run one cleanup step, letting nothing it raises mask the outcome of the test itself.
     *
     * @param   Closure  $operation  Cleanup step.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function quietly(Closure $operation): void
    {
        try {
            $operation();
        } catch (Throwable) {
        }
    }

    /**
     * Remove only the private generated-lifecycle directory created by this test.
     *
     * @param   string  $directory  Absolute test-owned directory under the system temporary root.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the target is outside the expected test-owned prefix.
     *
     * @since   2.0.0
     */
    private static function removeTree(string $directory): void
    {
        $prefix = rtrim(sys_get_temp_dir(), '/') . '/kumwe-generated-lifecycle-';
        if (!str_starts_with($directory, $prefix)) {
            throw new RuntimeException('The generated lifecycle cleanup target is outside its private prefix.');
        }
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($directory);
    }
}

/**
 * Immutable host-issued event used to execute the generated projection through its canonical SDK contract.
 *
 * @since  2.0.0
 */
final readonly class GeneratedLifecycleProjectionEvent implements ProjectionEvent
{
    /**
     * Retain the exact package-owned event type generated by the scaffold.
     *
     * @param  string  $type  Manifest-declared event type.
     *
     * @since  2.0.0
     */
    public function __construct(private string $type)
    {
    }

    /**
     * Return the first ordered source position.
     *
     * @return  int  Stable positive event sequence.
     *
     * @since   2.0.0
     */
    public function sequence(): int
    {
        return 1;
    }

    /**
     * Return the immutable test event identity.
     *
     * @return  string  Stable event identifier.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return 'generated-lifecycle-event';
    }

    /**
     * Return the package-owned event type from the generated manifest.
     *
     * @return  string  Exact signed event type.
     *
     * @since   2.0.0
     */
    public function type(): string
    {
        return $this->type;
    }

    /**
     * Return the signed schema generation.
     *
     * @return  int  Schema version one.
     *
     * @since   2.0.0
     */
    public function schemaVersion(): int
    {
        return 1;
    }

    /**
     * Return a deterministic event time.
     *
     * @return  DateTimeImmutable  Fixed UTC occurrence time.
     *
     * @since   2.0.0
     */
    public function occurredAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-29T00:00:00+00:00');
    }

    /**
     * Return the exact payload admitted by the generated event schema.
     *
     * @return  array{item_id: string, title: string}  One bounded item projection input.
     *
     * @since   2.0.0
     */
    public function payload(): array
    {
        return ['item_id' => 'generated-item', 'title' => 'Canonical lifecycle execution'];
    }

    /**
     * Fingerprint the immutable projection input.
     *
     * @return  string  Lowercase SHA-256 of the event payload.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        return hash('sha256', (string) json_encode($this->payload(), JSON_THROW_ON_ERROR));
    }
}

/**
 * Bounded test-owned writer exposing only the rows emitted through the canonical projection port.
 *
 * @since  2.0.0
 */
final class GeneratedLifecycleProjectionWriter implements ProjectionWriter
{
    /**
     * Rows written by the generated projection.
     *
     * @var    list<array{key: array<string, bool|int|string>, values: array<string, bool|int|string|null>}>
     * @since  2.0.0
     */
    public array $writes = [];

    /**
     * Capture one deterministic projection upsert.
     *
     * @param   array<string, bool|int|string>       $key     Exact derived-row key.
     * @param   array<string, bool|int|string|null>  $values  Exact derived-row values.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function put(array $key, array $values): void
    {
        $this->writes[] = ['key' => $key, 'values' => $values];
    }

    /**
     * Refuse an unexpected delete from the generated upsert-only projection.
     *
     * @param   array<string, bool|int|string>  $key  Candidate derived-row key.
     *
     * @return  void
     *
     * @throws  RuntimeException  Always; this acceptance event must produce one upsert.
     *
     * @since   2.0.0
     */
    public function remove(array $key): void
    {
        throw new RuntimeException('The generated lifecycle projection unexpectedly removed a row.');
    }
}
