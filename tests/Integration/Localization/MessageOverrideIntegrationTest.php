<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Localization;

use Kumwe\App\Tests\Support\InterfaceTranslation;
use DateTimeImmutable;
use DateTimeZone;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Infrastructure\Persistence\Migration\InterfaceMessageOverrideMigration;
use Kumwe\Localization\Application\ActiveLocale;
use Kumwe\Localization\Application\CatalogueTranslator;
use Kumwe\Localization\Application\MessageCatalogueRepository;
use Kumwe\Localization\Application\MessageOverrideRecord;
use Kumwe\App\Localization\Application\MessageOverrideService;
use Kumwe\Localization\Application\MessagePatternFormatter;
use Kumwe\Localization\Application\MessagePatternValidator;
use Kumwe\Localization\Application\SupportedLocales;
use Kumwe\Localization\Application\TranslationScope;
use Kumwe\Localization\Application\Translator;
use Kumwe\Localization\Domain\LocaleTag;
use Kumwe\Localization\Domain\MessageCatalogueLayer;
use Kumwe\App\Localization\Infrastructure\DoctrineMessageOverrideRepository;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Proves the administered half of the override chain against the engine the suite is pointed at.
 *
 * The chain's ordering is pinned in memory by `CatalogueTranslatorTest`, which is the right place for
 * it, because ordering is a property of the resolver. What could not be proven there is everything
 * that only exists once there is a store: that a change an operator makes survives being written
 * down, that it changes exactly one message and leaves the rest of the catalogue answering as the
 * release ships it, that writing the same message twice replaces the wording instead of accumulating
 * a second row the resolver would have to choose between, and that an organization's wording beats
 * the site's when both name the same message. This runs on MariaDB, MySQL and PostgreSQL in turn,
 * because a scope column that may be absent and a row that may not exist are exactly where the three
 * engines differ.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineMessageOverrideRepository::class)]
#[CoversClass(InterfaceMessageOverrideMigration::class)]
#[CoversClass(MessageOverrideService::class)]
final class MessageOverrideIntegrationTest extends TestCase
{
    /**
     * Message the test relabels; it is one the shipped catalogue really declares.
     *
     * @var    string
     * @since  2.0.0
     */
    private const RELABELLED = 'core.administrator.wording.layer_site';

    /**
     * Message the test never touches, used to prove the rest of the catalogue is left alone.
     *
     * @var    string
     * @since  2.0.0
     */
    private const UNTOUCHED = 'core.administrator.wording.layer_organization';

    /**
     * Organization the organization-layer row in this test belongs to.
     *
     * @var    string
     * @since  2.0.0
     */
    private const ORGANIZATION = 'integration-wording';

    /**
     * A stored site override changes one word and leaves every other message alone.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStoredSiteOverrideChangesOneWordAndNothingElse(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $service = $container->get(MessageOverrideService::class);
        self::assertInstanceOf(MessageOverrideService::class, $service);
        $context = TestKernelFactory::administratorContext($container);

        $shipped = $this->translator($container)->translate(self::RELABELLED);
        $untouched = $this->translator($container)->translate(self::UNTOUCHED);
        self::assertNotSame('Everyone here', $shipped);

        try {
            $service->override($context, MessageCatalogueLayer::Site, 'en-GB', self::RELABELLED, 'Everyone here');

            self::assertSame('Everyone here', $this->translator($container)->translate(self::RELABELLED));
            self::assertSame($untouched, $this->translator($container)->translate(self::UNTOUCHED));
        } finally {
            $service->withdraw($context, MessageCatalogueLayer::Site, 'en-GB', self::RELABELLED);
        }

        self::assertSame($shipped, $this->translator($container)->translate(self::RELABELLED));
    }

    /**
     * Writing the same message twice replaces the wording rather than storing a second row.
     *
     * The identity index is what guarantees this, and it is the one property a resolver cannot repair:
     * two stored rows for one identifier would make the answer depend on which the engine returned.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testWritingTheSameMessageTwiceReplacesItRatherThanAccumulating(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $service = $container->get(MessageOverrideService::class);
        self::assertInstanceOf(MessageOverrideService::class, $service);
        $context = TestKernelFactory::administratorContext($container);

        try {
            $service->override($context, MessageCatalogueLayer::Site, 'en-GB', self::RELABELLED, 'First wording');
            $service->override($context, MessageCatalogueLayer::Site, 'en-GB', self::RELABELLED, 'Second wording');

            $mine = array_values(array_filter(
                $service->overrides($context, MessageCatalogueLayer::Site, 'en-GB'),
                static fn (MessageOverrideRecord $record): bool => $record->identifier === self::RELABELLED,
            ));

            self::assertCount(1, $mine);
            self::assertSame('Second wording', $mine[0]->pattern);
        } finally {
            $service->withdraw($context, MessageCatalogueLayer::Site, 'en-GB', self::RELABELLED);
        }
    }

    /**
     * Repeating the exact value at the exact instant remains a no-op instead of attempting an insert.
     *
     * MySQL and MariaDB report zero affected rows when an update changes neither persisted value. The
     * repository must distinguish that result from a missing identity while the service still holds its
     * portable site-row lock; otherwise the second call attempts a duplicate insert. The fixed clock makes
     * the two writes byte-for-byte identical and the matrix runs this case on every supported engine.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testWritingTheSameValueAtTheSameInstantDoesNotInsertADuplicate(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $store = $container->get(DoctrineMessageOverrideRepository::class);
        $catalogues = $container->get(MessageCatalogueRepository::class);
        $supported = $container->get(SupportedLocales::class);
        $authorization = $container->get(AuthorizationGateway::class);
        $transactions = $container->get(TransactionManager::class);
        $patterns = $container->get(MessagePatternValidator::class);
        $audit = $container->get(AuditRecorder::class);
        self::assertInstanceOf(DoctrineMessageOverrideRepository::class, $store);
        self::assertInstanceOf(MessageCatalogueRepository::class, $catalogues);
        self::assertInstanceOf(SupportedLocales::class, $supported);
        self::assertInstanceOf(AuthorizationGateway::class, $authorization);
        self::assertInstanceOf(TransactionManager::class, $transactions);
        self::assertInstanceOf(MessagePatternValidator::class, $patterns);
        self::assertInstanceOf(AuditRecorder::class, $audit);
        $at = new DateTimeImmutable('2026-08-15 12:34:56', new DateTimeZone('UTC'));
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn($at);
        $service = new MessageOverrideService(
            $store,
            $catalogues,
            $supported,
            $authorization,
            $transactions,
            $patterns,
            InterfaceTranslation::translator(),
            $audit,
            $clock,
        );
        $context = TestKernelFactory::administratorContext($container);
        $tag = LocaleTag::fromString('en-GB');
        $store->remove(MessageCatalogueLayer::Site, SiteContext::DEFAULT, null, $tag, self::RELABELLED);

        try {
            $service->override(
                $context,
                MessageCatalogueLayer::Site,
                'en-GB',
                self::RELABELLED,
                'Same wording and instant',
            );
            $service->override(
                $context,
                MessageCatalogueLayer::Site,
                'en-GB',
                self::RELABELLED,
                'Same wording and instant',
            );

            $mine = array_values(array_filter(
                $store->overrides(MessageCatalogueLayer::Site, SiteContext::DEFAULT, null, $tag),
                static fn (MessageOverrideRecord $record): bool => $record->identifier === self::RELABELLED,
            ));
            self::assertCount(1, $mine);
            self::assertSame('Same wording and instant', $mine[0]->pattern);
            self::assertSame($at->getTimestamp(), $mine[0]->updatedAt->getTimestamp());
        } finally {
            $service->withdraw($context, MessageCatalogueLayer::Site, 'en-GB', self::RELABELLED);
        }
    }

    /**
     * A shared translator discards its request snapshot before the next request begins.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheSharedTranslatorSeesAChangeOnTheNextUnitOfWork(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $service = $container->get(MessageOverrideService::class);
        $translator = $container->get(Translator::class);
        $active = $container->get(ActiveLocale::class);
        self::assertInstanceOf(MessageOverrideService::class, $service);
        self::assertInstanceOf(Translator::class, $translator);
        self::assertInstanceOf(ActiveLocale::class, $active);
        $context = TestKernelFactory::administratorContext($container);

        $service->withdraw($context, MessageCatalogueLayer::Site, 'en-GB', self::RELABELLED);
        $active->begin(LocaleTag::fromString('en-GB'), TranslationScope::default());
        $shipped = $translator->translate(self::RELABELLED);
        $active->end();

        try {
            $service->override(
                $context,
                MessageCatalogueLayer::Site,
                'en-GB',
                self::RELABELLED,
                'Visible next request',
            );
            $active->begin(LocaleTag::fromString('en-GB'), TranslationScope::default());
            self::assertSame('Visible next request', $translator->translate(self::RELABELLED));
            $active->end();
        } finally {
            $active->end();
            $service->withdraw($context, MessageCatalogueLayer::Site, 'en-GB', self::RELABELLED);
        }

        $active->begin(LocaleTag::fromString('en-GB'), TranslationScope::default());
        try {
            self::assertSame($shipped, $translator->translate(self::RELABELLED));
        } finally {
            $active->end();
        }
    }

    /**
     * An audit failure rolls the wording row back with the rest of the mutation transaction.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnAuditFailureRollsBackTheOverrideWrite(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $store = $container->get(DoctrineMessageOverrideRepository::class);
        self::assertInstanceOf(DoctrineMessageOverrideRepository::class, $store);
        $tag = LocaleTag::fromString('en-GB');
        $store->remove(
            MessageCatalogueLayer::Site,
            SiteContext::DEFAULT,
            null,
            $tag,
            self::RELABELLED,
        );
        $catalogues = $container->get(MessageCatalogueRepository::class);
        $supported = $container->get(SupportedLocales::class);
        $authorization = $container->get(AuthorizationGateway::class);
        $transactions = $container->get(TransactionManager::class);
        $patterns = $container->get(MessagePatternValidator::class);
        $clock = $container->get(ClockInterface::class);
        self::assertInstanceOf(MessageCatalogueRepository::class, $catalogues);
        self::assertInstanceOf(SupportedLocales::class, $supported);
        self::assertInstanceOf(AuthorizationGateway::class, $authorization);
        self::assertInstanceOf(TransactionManager::class, $transactions);
        self::assertInstanceOf(MessagePatternValidator::class, $patterns);
        self::assertInstanceOf(ClockInterface::class, $clock);
        $service = new MessageOverrideService(
            $store,
            $catalogues,
            $supported,
            $authorization,
            $transactions,
            $patterns,
            InterfaceTranslation::translator(),
            new class implements AuditRecorder {
                /**
                 * Simulate the durable audit sink refusing the mutation.
                 *
                 * @param   AuditEvent  $event  Event the mutation tried to record.
                 *
                 * @return  void
                 *
                 * @throws  RuntimeException  Always, to force transaction rollback.
                 *
                 * @since   2.0.0
                 */
                public function record(AuditEvent $event): void
                {
                    throw new RuntimeException('Synthetic audit failure.');
                }
            },
            $clock,
        );

        try {
            $service->override(
                TestKernelFactory::administratorContext($container),
                MessageCatalogueLayer::Site,
                'en-GB',
                self::RELABELLED,
                'Must roll back',
            );
            self::fail('An audit failure must abort the wording mutation.');
        } catch (RuntimeException $failure) {
            self::assertSame('Synthetic audit failure.', $failure->getMessage());
        }

        self::assertArrayNotHasKey(
            self::RELABELLED,
            $store->siteOverrides(SiteContext::DEFAULT, $tag),
        );
    }

    /**
     * An organization's stored wording beats the site's for the same message.
     *
     * The organization row is written through the store rather than through the service, because the
     * service deliberately refuses an organization write from outside that organization and this test
     * is about which stored row wins, not about who may write one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnOrganizationOverrideBeatsTheSiteOverrideForTheSameMessage(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $store = $container->get(DoctrineMessageOverrideRepository::class);
        self::assertInstanceOf(DoctrineMessageOverrideRepository::class, $store);
        $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $store->put(new MessageOverrideRecord(
            MessageCatalogueLayer::Site,
            SiteContext::DEFAULT,
            null,
            'en-GB',
            self::RELABELLED,
            'Site wording',
            $at,
        ));
        $store->put(new MessageOverrideRecord(
            MessageCatalogueLayer::Organization,
            SiteContext::DEFAULT,
            self::ORGANIZATION,
            'en-GB',
            self::RELABELLED,
            'Organization wording',
            $at,
        ));

        try {
            self::assertSame(
                'Site wording',
                $this->translator($container)->translate(self::RELABELLED),
            );
            self::assertSame(
                'Organization wording',
                $this->translator($container, self::ORGANIZATION)->translate(self::RELABELLED),
            );
        } finally {
            $store->remove(
                MessageCatalogueLayer::Organization,
                SiteContext::DEFAULT,
                self::ORGANIZATION,
                LocaleTag::fromString('en-GB'),
                self::RELABELLED,
            );
            $store->remove(
                MessageCatalogueLayer::Site,
                SiteContext::DEFAULT,
                null,
                LocaleTag::fromString('en-GB'),
                self::RELABELLED,
            );
        }
    }

    /**
     * Build a translator over a chain assembled now, the way the operator's next page assembles one.
     *
     * The shared translator memoises its chain for the life of the unit of work, which is exactly what
     * keeps a page that resolves hundreds of messages to one read per scope. A test that has just
     * changed wording therefore has to ask for a new unit of work rather than the one it already used.
     *
     * @param   Container  $container     Container the kernel composed.
     * @param   ?string    $organization  Organization the scope names, or null for site scope.
     *
     * @return  Translator  A translator bound to a freshly assembled chain.
     *
     * @since   2.0.0
     */
    private function translator(Container $container, ?string $organization = null): Translator
    {
        $catalogues = $container->get(MessageCatalogueRepository::class);
        $overrides = $container->get(DoctrineMessageOverrideRepository::class);
        $formatter = $container->get(MessagePatternFormatter::class);
        $supported = $container->get(SupportedLocales::class);
        self::assertInstanceOf(MessageCatalogueRepository::class, $catalogues);
        self::assertInstanceOf(DoctrineMessageOverrideRepository::class, $overrides);
        self::assertInstanceOf(MessagePatternFormatter::class, $formatter);
        self::assertInstanceOf(SupportedLocales::class, $supported);

        $active = new ActiveLocale($supported);
        $active->begin(
            LocaleTag::fromString('en-GB'),
            new TranslationScope(SiteContext::DEFAULT, $organization),
        );

        return new CatalogueTranslator($catalogues, $overrides, $formatter, $active, $supported);
    }
}
