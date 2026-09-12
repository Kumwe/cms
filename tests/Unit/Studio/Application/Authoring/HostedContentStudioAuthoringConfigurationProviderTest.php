<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Authoring;

use DateTimeImmutable;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Content\Application\ContentModelService;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Content\Application\ContentModelRepository;
use Kumwe\App\Content\Application\ContentRepository;
use Kumwe\App\Content\Domain\JsonSchemaValidator;
use Kumwe\App\Content\Domain\SchemaCompatibilityChecker;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Runtime\ActiveExtensionSet;
use Kumwe\Localization\Application\ActiveLocale;
use Kumwe\Localization\Application\SupportedLocales;
use Kumwe\App\Presentation\Application\SitePresentation;
use Kumwe\App\Site\Application\SiteSettings;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringCatalog;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextAuthority;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextBinding;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextRepository;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringDocuments;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringTarget;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringTargetResolver;
use Kumwe\App\Studio\Application\Authoring\HostedContentStudioAuthoringConfigurationProvider;
use Kumwe\App\Studio\Application\Authoring\StudioHostedDeploymentConfiguration;
use Kumwe\App\Studio\Application\Composition\StudioBuiltInThemeRelease;
use Kumwe\App\Studio\Application\Composition\StudioCompositionContributionCatalog;
use Kumwe\App\Studio\Application\Composition\StudioPublishedTheme;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Host\StudioHostSessionRepository;
use Kumwe\App\Studio\Application\Host\StudioResourceContextKeyFactory;
use Kumwe\App\Studio\Application\Release\StudioCoreCatalog;
use Kumwe\App\Studio\Application\Rendering\StudioBlockRendererRuntime;
use Kumwe\App\Studio\Application\Rendering\StudioContentFieldBlockRenderer;
use Kumwe\App\Studio\Domain\Authoring\StudioAuthoringIntent;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Transaction\Testing\ImmediateTransactionManager;
use Kumwe\App\Workflow\Domain\Workflow;
use Kumwe\Producer\Deployment\StudioBrowserAssetLocator;
use Kumwe\Producer\Deployment\StudioDeploymentEmitter;
use Kumwe\Producer\Schema\StudioContractResources;
use Kumwe\Producer\Schema\StudioDocumentSchemaRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use stdClass;

/**
 * Proves one Content editor mount opens its PHP bindings and emits a Producer-proven deployment.
 *
 * @since  2.0.0
 */
#[CoversClass(HostedContentStudioAuthoringConfigurationProvider::class)]
#[CoversClass(ContentStudioAuthoringCatalog::class)]
final class HostedContentStudioAuthoringConfigurationProviderTest extends TestCase
{
    /**
     * A blank create mount yields a deployment the pinned schema admits and both authorities can resolve.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testABlankCreateMountEmitsAProvenDeploymentBoundToItsOpenedSessions(): void
    {
        $registry = StudioDocumentSchemaRegistry::fromVendoredCorpus();
        [$provider, $contexts, $sessions, $catalog] = $this->provider();
        $context = self::context(['content.create', 'content.read', 'studio.mode.hybrid']);
        $target = (new ContentStudioAuthoringTargetResolver(AuthorizationContext::gateway()))->create($context);

        $configuration = $provider->forMount($context, $target, 'csrf-token-value');

        self::assertInstanceOf(StudioHostedDeploymentConfiguration::class, $configuration);
        self::assertSame(HostedContentStudioAuthoringConfigurationProvider::MOUNT_ID, $configuration->mountId);
        self::assertSame('https://cdn.jsdelivr.net', $configuration->scriptOrigin);
        self::assertStringStartsWith(
            'https://cdn.jsdelivr.net/npm/@kumwe/studio@0.1.0-beta.3/dist/browser/assets/studio-browser-',
            $configuration->moduleUrl,
        );
        self::assertSame(
            StudioContractResources::browserAsset('browser-module')->integrity(),
            $configuration->moduleIntegrity,
        );
        self::assertSame('/administrator/content/new', $configuration->returnPath);
        self::assertStringNotContainsString('<', $configuration->configurationJson);

        $document = json_decode($configuration->configurationJson, false, 32, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $document);
        self::assertTrue($registry->validate('studio-deployment', $document)->valid());
        self::assertSame('#' . HostedContentStudioAuthoringConfigurationProvider::MOUNT_ID, $document->mount);
        self::assertSame('0.1.0-beta.3', $document->release->version);
        self::assertSame('create', $document->launch->intent);
        self::assertSame('blank', $document->launch->start->kind);
        self::assertEquals($document->launch->resourceContext, $document->session->resourceContext);

        $session = $document->session;
        $key = $session->resourceContext->key;
        self::assertIsString($key);
        $host = $sessions->find($key);
        self::assertInstanceOf(StudioHostSession::class, $host);
        self::assertSame(StudioResourceKind::ContentAuthoring, $host->resourceKind);
        self::assertSame($host->sessionGeneration, $session->sessionGeneration);
        self::assertSame(ContentStudioAuthoringDocuments::sessionId($key), $session->sessionId);
        self::assertInstanceOf(ContentStudioAuthoringContextBinding::class, $contexts->find($host->resourceId));
        self::assertSame($target->toArray(), $contexts->find($host->resourceId)?->target->toArray());
        self::assertSame(ContentStudioAuthoringDocuments::draftEntryId($key), $session->resourceContext->resource->id);
        self::assertSame('content', $session->mode);
        self::assertSame('hybrid', $session->composite);
        self::assertFalse($session->preview->enabled);
        self::assertContains('studio.permission/save', $session->permissions);

        $locks = array_map(static fn (stdClass $lock): string => $lock->type, $session->blocks);
        self::assertContains('studio.core/heading', $locks);
        self::assertContains('studio.core/section', $locks);
        self::assertContains('core/field-text', $locks);
        self::assertSame($locks, array_values(array_unique($locks)));
        self::assertEquals($catalog->blockLocks(), $session->blocks);

        $advertised = [];
        foreach ($session->hostCapabilities->ports as $port) {
            foreach ($port->operations as $operation) {
                $advertised[] = substr(str_replace('.', '/', substr($operation, strlen('studio.operation/'))), 0);
            }
        }
        sort($advertised);
        $routes = array_keys(get_object_vars($document->transport->routing->endpoints));
        sort($routes);
        self::assertSame($advertised, $routes);
        self::assertSame(
            '/administrator/studio/ports/authoring/start',
            $document->transport->routing->endpoints->{'authoring/start'},
        );
        self::assertSame('X-CSRF-Token', $document->transport->authentication->csrf->headerName);
        self::assertSame('csrf-token-value', $document->transport->authentication->csrf->token);

        self::assertSame($catalog->contributionGeneration(), $document->contributions->generation);
        self::assertEquals($catalog->contributionPayloads(), $document->contributions->payloads);
        self::assertCount(count($document->contributions->payloads), $catalog->contributionDependencies());
        self::assertEquals($catalog->declaration(), ContentStudioAuthoringDocuments::declaration(
            $catalog->contributionDependencies(),
        ));
        foreach ($document->contributions->payloads as $payload) {
            self::assertNotSame('studio.core/section', $payload->type ?? null);
        }
    }

    /**
     * An actor the context authority refuses gets no deployment and no host session is minted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARefusedContextYieldsNoDeployment(): void
    {
        [$provider, , $sessions] = $this->provider();
        $target = new ContentStudioAuthoringTarget(
            StudioAuthoringIntent::Create,
            null,
            null,
            null,
            null,
            null,
            '/administrator/content/new',
        );

        $configuration = $provider->forMount(self::context(['content.read']), $target, 'csrf-token-value');

        self::assertNull($configuration);
        self::assertNull($sessions->find('contexts/test-1'));
    }

    /**
     * An empty CSRF token never reaches the authorities.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnEmptyCsrfTokenYieldsNoDeployment(): void
    {
        [$provider, $contexts] = $this->provider();
        $context = self::context(['content.create', 'content.read', 'studio.mode.hybrid']);
        $target = (new ContentStudioAuthoringTargetResolver(AuthorizationContext::gateway()))->create($context);

        self::assertNull($provider->forMount($context, $target, ''));
        self::assertNull($contexts->find('contexts/' . hash('sha256', 'content-authoring-1')));
    }

    /**
     * Compose one provider over in-memory context and host-session stores.
     *
     * @return  array{
     *     HostedContentStudioAuthoringConfigurationProvider,
     *     ContentStudioAuthoringContextRepository,
     *     StudioHostSessionRepository,
     *     ContentStudioAuthoringCatalog
     * }  Provider, both stores, and the catalog it locks.
     *
     * @since   2.0.0
     */
    private function provider(): array
    {
        $contexts = new class implements ContentStudioAuthoringContextRepository {
            /**
             * In-memory bindings by key.
             *
             * @var    array<string, ContentStudioAuthoringContextBinding>
             * @since  2.0.0
             */
            private array $bindings = [];

            /**
             * Retain one binding.
             *
             * @param   ContentStudioAuthoringContextBinding  $binding  Binding to retain.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function add(ContentStudioAuthoringContextBinding $binding): void
            {
                $this->bindings[$binding->contextKey] = $binding;
            }

            /**
             * Find one binding.
             *
             * @param   string  $contextKey  Opaque key.
             *
             * @return  ContentStudioAuthoringContextBinding|null  Binding or null.
             *
             * @since   2.0.0
             */
            public function find(string $contextKey): ?ContentStudioAuthoringContextBinding
            {
                return $this->bindings[$contextKey] ?? null;
            }

            /**
             * Replace one binding.
             *
             * @param   ContentStudioAuthoringContextBinding  $binding  Successor binding.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function advance(ContentStudioAuthoringContextBinding $binding): void
            {
                $this->bindings[$binding->contextKey] = $binding;
            }
        };
        $sessions = new class implements StudioHostSessionRepository {
            /**
             * In-memory sessions by key.
             *
             * @var    array<string, StudioHostSession>
             * @since  2.0.0
             */
            private array $sessions = [];

            /**
             * Retain one session.
             *
             * @param   StudioHostSession  $session  Session to retain.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function add(StudioHostSession $session): void
            {
                $this->sessions[$session->resourceContextKey] = $session;
            }

            /**
             * Find one session.
             *
             * @param   string  $resourceContextKey  Opaque key.
             *
             * @return  StudioHostSession|null  Session or null.
             *
             * @since   2.0.0
             */
            public function find(string $resourceContextKey): ?StudioHostSession
            {
                return $this->sessions[$resourceContextKey] ?? null;
            }
        };
        $keys = new class implements StudioResourceContextKeyFactory {
            /**
             * Next key suffix.
             *
             * @var    int
             * @since  2.0.0
             */
            private int $next = 1;

            /**
             * Allocate one deterministic 64-hex key.
             *
             * @return  string  Next key.
             *
             * @since   2.0.0
             */
            public function create(): string
            {
                return 'contexts/' . hash('sha256', 'content-authoring-' . $this->next++);
            }
        };
        $gateway = AuthorizationContext::gateway();
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-09-09T00:00:00+00:00'));
        $transactions = new ImmediateTransactionManager();
        $audit = $this->createStub(AuditRecorder::class);
        $contextAuthority = new ContentStudioAuthoringContextAuthority(
            $contexts,
            $keys,
            new ContentStudioAuthoringTargetResolver($gateway),
            new ContentModelService(
                $this->createStub(ContentModelRepository::class),
                new JsonSchemaValidator(),
                new SchemaCompatibilityChecker(),
                $gateway,
                AuthorizationContext::ownershipWriter(),
                $audit,
                $transactions,
                $clock,
            ),
            new ContentService(
                $this->createStub(ContentRepository::class),
                $audit,
                $transactions,
                $clock,
                new Workflow(),
                $gateway,
                AuthorizationContext::ownershipWriter(),
            ),
            $clock,
            28_800,
        );
        $settings = $this->createStub(SiteSettings::class);
        $settings->method('current')->willReturn([
            'site_name' => 'Kumwe',
            'timezone' => 'Africa/Windhoek',
            'presentation' => SitePresentation::defaults(),
        ]);
        $registries = new ExtensionContributionRegistrySet();
        $theme = new StudioPublishedTheme(
            $settings,
            new ActiveExtensionSet($registries),
            new StudioBuiltInThemeRelease(str_repeat('a', 64)),
        );
        $root = dirname(__DIR__, 5);
        $catalog = new ContentStudioAuthoringCatalog(
            new StudioCompositionContributionCatalog(
                $registries,
                new StudioBlockRendererRuntime($registries, new StudioContentFieldBlockRenderer()),
            ),
            StudioCoreCatalog::fromFile($root . '/resources/studio-contract/core-catalog.json', '0.1.0-beta.3'),
        );
        $provider = new HostedContentStudioAuthoringConfigurationProvider(
            $contextAuthority,
            new StudioHostSessionAuthority($gateway, $sessions, $keys, $theme),
            $catalog,
            $theme,
            new ActiveLocale(new SupportedLocales()),
            $settings,
            new StudioDeploymentEmitter(
                StudioDocumentSchemaRegistry::fromVendoredCorpus(),
                StudioContractResources::releaseRecord(),
            ),
            StudioBrowserAssetLocator::npmPackages('https://cdn.jsdelivr.net/npm'),
            new NullLogger(),
        );

        return [$provider, $contexts, $sessions, $catalog];
    }

    /**
     * Build one administrator execution context for the default site.
     *
     * @param   list<string>  $capabilities  Granted capabilities.
     *
     * @return  ExecutionContext  Administrator context.
     *
     * @since   2.0.0
     */
    private static function context(array $capabilities): ExecutionContext
    {
        return AuthorizationContext::principal($capabilities)->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'hosted-provider-test',
            surface: AuthenticatedSurface::Administrator,
            sessionId: 'administrator-hosted-provider',
        );
    }
}
