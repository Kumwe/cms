<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Localization\Application;

use Kumwe\App\Tests\Support\InterfaceTranslation;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\Localization\Application\MessageCatalogueRepository;
use Kumwe\Localization\Application\MessageFormattingFailed;
use Kumwe\Localization\Application\MessageOverrideRecord;
use Kumwe\App\Localization\Application\MessageOverrideService;
use Kumwe\Localization\Application\MessageOverrideStore;
use Kumwe\Localization\Application\SupportedLocales;
use Kumwe\Localization\Domain\InvalidMessageIdentifier;
use Kumwe\Localization\Domain\LocaleTag;
use Kumwe\Localization\Domain\MessageCatalogue;
use Kumwe\Localization\Domain\MessageCatalogueLayer;
use Kumwe\Localization\Infrastructure\IntlMessagePatternFormatter;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Transaction\Testing\ImmediateTransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Pins the rules that stand between an operator and the wording the render path reads.
 *
 * The chain's ordering is proven elsewhere. What is proven here is everything the administered layers
 * had no owner for while they were served from memory: who may write, what may be written, which
 * identifiers exist to be overridden, and that a change is recorded rather than merely applied.
 *
 * @since  2.0.0
 */
#[CoversClass(MessageOverrideService::class)]
final class MessageOverrideServiceTest extends TestCase
{
    /**
     * Identifier the shipped catalogue in this test declares.
     *
     * @var    string
     * @since  2.0.0
     */
    private const CLIENT = 'core.business.client.label';

    /**
     * Studio shell message whose canonical parameter set includes a hyphenated local name.
     *
     * @var    string
     * @since  2.0.0
     */
    private const STUDIO_BINDING_ACCEPTS = 'core.studio.shell.inspector-binding-accepts';

    /**
     * Storing an override records the wording, the layer and the instant, and audits the change.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testChangingOneWordIsStoredAgainstItsLayerAndRecorded(): void
    {
        $store = $this->store();
        $events = [];
        $service = $this->service($store, $events);

        $written = $service->override(
            $this->actor(),
            MessageCatalogueLayer::Site,
            'en-GB',
            self::CLIENT,
            'Patient',
        );

        self::assertSame('Patient', $written->pattern);
        self::assertSame(MessageCatalogueLayer::Site, $written->layer);
        self::assertNull($written->organization);
        self::assertSame('en-GB', $written->locale);
        self::assertSame(
            ['core.business.client.label' => 'Patient'],
            $store->siteOverrides('default', LocaleTag::fromString('en-GB')),
        );
        self::assertCount(1, $events);
        self::assertSame('localization.override.write', $events[0]->action());
        self::assertSame('message_override', $events[0]->subjectType());
    }

    /**
     * Withdrawing an override removes it and reports whether there was anything to remove.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testWithdrawingAnOverrideLetsTheShippedWordingAnswerAgain(): void
    {
        $store = $this->store();
        $events = [];
        $service = $this->service($store, $events);
        $actor = $this->actor();
        $service->override($actor, MessageCatalogueLayer::Site, 'en-GB', self::CLIENT, 'Patient');

        self::assertTrue($service->withdraw($actor, MessageCatalogueLayer::Site, 'en-GB', self::CLIENT));
        self::assertSame([], $store->siteOverrides('default', LocaleTag::fromString('en-GB')));
        self::assertFalse($service->withdraw($actor, MessageCatalogueLayer::Site, 'en-GB', self::CLIENT));
        self::assertCount(2, $events);
        self::assertSame('localization.override.withdraw', $events[1]->action());
    }

    /**
     * An actor without the capability changes nothing, and is refused rather than ignored.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnActorWithoutTheCapabilityIsRefused(): void
    {
        $store = $this->store();
        $events = [];
        $service = $this->service($store, $events);

        $this->expectException(AuthorizationDenied::class);
        $service->override(
            AuthorizationContext::human(['content.read']),
            MessageCatalogueLayer::Site,
            'en-GB',
            self::CLIENT,
            'Patient',
        );
    }

    /**
     * Wording may only replace an identifier some shipped catalogue actually declares.
     *
     * Without this the store fills with entries nothing ever looks up, and an operator who mistyped an
     * identifier believes they changed a word that never changes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnIdentifierNoShippedCatalogueDeclaresIsRefused(): void
    {
        $store = $this->store();
        $events = [];
        $service = $this->service($store, $events);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('nothing to override');
        $service->override(
            $this->actor(),
            MessageCatalogueLayer::Site,
            'en-GB',
            'core.business.invented.label',
            'Patient',
        );
    }

    /**
     * The frozen identifier grammar is enforced here too, not only where a message is looked up.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnIdentifierOutsideTheFrozenGrammarIsRefused(): void
    {
        $store = $this->store();
        $events = [];
        $service = $this->service($store, $events);

        $this->expectException(InvalidMessageIdentifier::class);
        $service->override($this->actor(), MessageCatalogueLayer::Site, 'en-GB', 'Save settings', 'Patient');
    }

    /**
     * A locale this installation does not carry cannot be written, so no override is stranded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testALocaleThisInstallationDoesNotCarryIsRefused(): void
    {
        $store = $this->store();
        $events = [];
        $service = $this->service($store, $events);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not carry that locale');
        $service->override($this->actor(), MessageCatalogueLayer::Site, 'ja-JP', self::CLIENT, 'Patient');
    }

    /**
     * The file-shipped layers cannot be administered, because they are not stored anywhere writable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheCoreAndExtensionLayersCannotBeAdministered(): void
    {
        $store = $this->store();
        $events = [];
        $service = $this->service($store, $events);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ships in files');
        $service->override($this->actor(), MessageCatalogueLayer::Core, 'en-GB', self::CLIENT, 'Patient');
    }

    /**
     * An organization change made outside an organization is refused rather than silently widened.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnOrganizationChangeOutsideAnOrganizationIsRefused(): void
    {
        $store = $this->store();
        $events = [];
        $service = $this->service($store, $events);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('inside the organization');
        $service->override($this->actor(), MessageCatalogueLayer::Organization, 'en-GB', self::CLIENT, 'Patient');
    }

    /**
     * Blank wording is refused, because withdrawing an override is the way to restore the original.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBlankWordingIsRefusedRatherThanStoredAsAnEmptyMessage(): void
    {
        $store = $this->store();
        $events = [];
        $service = $this->service($store, $events);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('withdraw the override');
        $service->override($this->actor(), MessageCatalogueLayer::Site, 'en-GB', self::CLIENT, '   ');
    }

    /**
     * Active markup and attributes are refused before an override reaches storage.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAdministeredWordingRefusesActiveMarkupAndAttributes(): void
    {
        $store = $this->store();
        $events = [];
        $service = $this->service($store, $events);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('element or attribute');
        $service->override(
            $this->actor(),
            MessageCatalogueLayer::Site,
            'en-GB',
            self::CLIENT,
            '<span onclick="alert(1)">Patient</span>',
        );
    }

    /**
     * Markup balanced only by opening in one ICU branch and closing in another is refused.
     *
     * Each select arm renders independently, so a stack walked across the raw pattern is not evidence
     * that either possible result is balanced HTML.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAdministeredWordingRefusesMarkupAcrossIcuBranches(): void
    {
        $store = $this->store();
        $events = [];
        $service = $this->service($store, $events);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('branching ICU pattern');
        $service->override(
            $this->actor(),
            MessageCatalogueLayer::Site,
            'en-GB',
            self::CLIENT,
            '{kind, select, patient {<strong>Patient} other {Client</strong>}}',
        );
    }

    /**
     * Malformed ICU is rejected at administration time instead of failing a later render.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAdministeredWordingRefusesMalformedIcu(): void
    {
        $store = $this->store();
        $events = [];
        $service = $this->service($store, $events);

        $this->expectException(MessageFormattingFailed::class);
        $service->override(
            $this->actor(),
            MessageCatalogueLayer::Site,
            'en-GB',
            self::CLIENT,
            '{count, plural, one {One} other {Many}',
        );
    }

    /**
     * Studio's canonical named interpolation remains writable at both administered override layers.
     *
     * The shell owns a deliberately smaller grammar than App ICU and publishes `value-type` as one
     * of its parameter names. Site and organization terminology therefore preserve that exact name
     * instead of being refused by an ICU parser the Studio runtime never invokes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStudioNamedInterpolationCanBeOverriddenAtSiteAndOrganizationLayers(): void
    {
        $store = $this->store();
        $events = [];
        $service = $this->service($store, $events);
        $sitePattern = 'Supports {value-type} with {cardinality} cardinality';
        $organizationPattern = '{cardinality} values of type {value-type}';

        $service->override(
            $this->actor(),
            MessageCatalogueLayer::Site,
            'en-GB',
            self::STUDIO_BINDING_ACCEPTS,
            $sitePattern,
        );
        $organization = AuthorizationContext::human(
            ['localization.overrides.manage'],
            membership: AuthorizationContext::membership('acme'),
        );
        $service->override(
            $organization,
            MessageCatalogueLayer::Organization,
            'en-GB',
            self::STUDIO_BINDING_ACCEPTS,
            $organizationPattern,
        );

        $locale = LocaleTag::fromString('en-GB');
        self::assertSame(
            [self::STUDIO_BINDING_ACCEPTS => $sitePattern],
            $store->siteOverrides('default', $locale),
        );
        self::assertSame(
            [self::STUDIO_BINDING_ACCEPTS => $organizationPattern],
            $store->organizationOverrides('default', 'acme', $locale),
        );
    }

    /**
     * Studio overrides cannot add parameters or smuggle ICU branching into the named subset.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStudioOverridesRefuseParameterDriftAndNonNamedExpressions(): void
    {
        $store = $this->store();
        $events = [];
        $service = $this->service($store, $events);

        foreach (
            [
                'Supports {value-type} with {unknown}',
                '{value-type, select, text {Text} other {Other}} and {cardinality}',
                'Supports {value-type with {cardinality}',
            ] as $pattern
        ) {
            try {
                $service->override(
                    $this->actor(),
                    MessageCatalogueLayer::Site,
                    'en-GB',
                    self::STUDIO_BINDING_ACCEPTS,
                    $pattern,
                );
                self::fail('The service stored a Studio pattern outside named interpolation: ' . $pattern);
            } catch (MessageFormattingFailed $refused) {
                self::assertStringContainsString('cannot be formatted for locale en-GB', $refused->getMessage());
            }
        }
    }

    /**
     * A neighbouring namespace still passes through App ICU and receives no Studio exception.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStudioNamedInterpolationExceptionIsRestrictedToTheExactShellNamespace(): void
    {
        $store = $this->store();
        $events = [];
        $service = $this->service($store, $events);

        $this->expectException(MessageFormattingFailed::class);
        $service->override(
            $this->actor(),
            MessageCatalogueLayer::Site,
            'en-GB',
            'core.studio.shellish.inspector-binding-accepts',
            'Supports {value-type} with {cardinality} cardinality',
        );
    }

    /**
     * Quota observation occurs only after the site serialization lock is held.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAWriteLocksTheSiteBeforeReadingItsQuota(): void
    {
        $locked = false;
        $store = $this->createMock(MessageOverrideStore::class);
        $store->expects(self::once())->method('lockSite')->with(SiteContext::DEFAULT)->willReturnCallback(
            static function () use (&$locked): void {
                $locked = true;
            },
        );
        $store->expects(self::once())->method('overrides')->willReturnCallback(
            static function () use (&$locked): array {
                self::assertTrue($locked, 'The quota read must occur under the durable site row lock.');

                return [];
            },
        );
        $store->expects(self::once())->method('put');
        $events = [];

        $this->service($store, $events)->override(
            $this->actor(),
            MessageCatalogueLayer::Site,
            'en-GB',
            self::CLIENT,
            'Patient',
        );
    }

    /**
     * Searching the shipped catalogue finds a message by its wording as well as by its identifier.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheCatalogueSearchFindsAMessageByWhatItSaysAndByItsIdentifier(): void
    {
        $store = $this->store();
        $events = [];
        $service = $this->service($store, $events);
        $actor = $this->actor();

        $byWording = $service->searchCatalogue($actor, 'en-GB', 'client');
        self::assertSame([self::CLIENT], array_column($byWording, 'identifier'));
        self::assertSame('core', $byWording[0]['layer']);

        $byIdentifier = $service->searchCatalogue($actor, 'en-GB', 'business.client');
        self::assertSame([self::CLIENT], array_column($byIdentifier, 'identifier'));
        self::assertSame([], $service->searchCatalogue($actor, 'en-GB', 'nothing-matches-this'));
    }

    /**
     * A context that carries the wording capability for the default site.
     *
     * @return  ExecutionContext  Actor holding `localization.overrides.manage`.
     *
     * @since   2.0.0
     */
    private function actor(): ExecutionContext
    {
        return AuthorizationContext::human(['localization.overrides.manage']);
    }

    /**
     * Build the service over an in-memory store and a catalogue carrying one shipped message.
     *
     * @param   MessageOverrideStore    $store   Store the service writes through.
     * @param   list<AuditEvent>        $events  Sink capturing every recorded audit event, by reference.
     *
     * @return  MessageOverrideService  The service under test.
     *
     * @since   2.0.0
     */
    private function service(MessageOverrideStore $store, array &$events): MessageOverrideService
    {
        $recorder = new class ($events) implements AuditRecorder {
            /**
             * Capture events into the test's own list.
             *
             * @param  list<AuditEvent>  $events  Sink held by reference.
             *
             * @since  2.0.0
             */
            public function __construct(private array &$events)
            {
            }

            /**
             * Append one event to the captured list.
             *
             * @param   AuditEvent  $event  Event the service recorded.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function record(AuditEvent $event): void
            {
                $this->events[] = $event;
            }
        };

        return new MessageOverrideService(
            $store,
            $this->catalogues(),
            new SupportedLocales(),
            AuthorizationContext::gateway(),
            new ImmediateTransactionManager(),
            new IntlMessagePatternFormatter(),
            InterfaceTranslation::translator(),
            $recorder,
            $this->clock(),
        );
    }

    /**
     * A catalogue repository carrying one core message and no extension messages.
     *
     * @return  MessageCatalogueRepository  Read-only stand-in for the compiled catalogues.
     *
     * @since   2.0.0
     */
    private function catalogues(): MessageCatalogueRepository
    {
        return new class implements MessageCatalogueRepository {
            /**
             * Answer the core layer with one message and every other layer with nothing.
             *
             * @param   MessageCatalogueLayer  $layer   Layer being read.
             * @param   LocaleTag              $locale  Locale being read.
             *
             * @return  MessageCatalogue  The stand-in catalogue for that layer.
             *
             * @since   2.0.0
             */
            public function catalogue(MessageCatalogueLayer $layer, LocaleTag $locale): MessageCatalogue
            {
                if ($layer !== MessageCatalogueLayer::Core || $locale->toString() !== 'en-GB') {
                    return MessageCatalogue::empty($locale, $layer);
                }

                return new MessageCatalogue($locale, $layer, [
                    'core.business.client.label' => 'Client',
                    'core.studio.shell.inspector-binding-accepts' =>
                        'Accepts {cardinality} {value-type} value',
                    'core.studio.shellish.inspector-binding-accepts' =>
                        'Accepts {cardinality} value type',
                ]);
            }
        };
    }

    /**
     * A store that keeps overrides in memory and satisfies both faces of the contract.
     *
     * @return  MessageOverrideStore&\Kumwe\Localization\Application\MessageOverrideRepository  The store.
     *
     * @since   2.0.0
     */
    private function store(): MessageOverrideStore&\Kumwe\Localization\Application\MessageOverrideRepository
    {
        return new class implements
            MessageOverrideStore,
            \Kumwe\Localization\Application\MessageOverrideRepository
        {
            /**
             * Stored overrides, keyed by the identity the store enforces uniqueness on.
             *
             * @var    array<string, MessageOverrideRecord>
             * @since  2.0.0
             */
            private array $records = [];

            /**
             * Satisfy the serialization port; one in-memory test thread already runs serially.
             *
             * @param   string  $site  Site the mutation belongs to.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function lockSite(string $site): void
            {
            }

            /**
             * List one scope's overrides in the order a screen renders them.
             *
             * @param   MessageCatalogueLayer  $layer         Administered layer to list.
             * @param   string                 $site          Site the scope belongs to.
             * @param   ?string                $organization  Organization within that site, or null.
             * @param   ?LocaleTag             $locale        Locale to restrict to, or null for all.
             *
             * @return  list<MessageOverrideRecord>  Matching overrides.
             *
             * @since   2.0.0
             */
            public function overrides(
                MessageCatalogueLayer $layer,
                string $site,
                ?string $organization = null,
                ?LocaleTag $locale = null,
            ): array {
                $matches = [];
                foreach ($this->records as $record) {
                    if (
                        $record->layer !== $layer
                        || $record->site !== $site
                        || $record->organization !== $organization
                        || ($locale instanceof LocaleTag && $record->locale !== $locale->toString())
                    ) {
                        continue;
                    }
                    $matches[] = $record;
                }

                return $matches;
            }

            /**
             * Store or replace one override.
             *
             * @param   MessageOverrideRecord  $override  Override to write.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function put(MessageOverrideRecord $override): void
            {
                $this->records[$this->key(
                    $override->layer,
                    $override->site,
                    $override->organization,
                    $override->locale,
                    $override->identifier,
                )] = $override;
            }

            /**
             * Remove one override.
             *
             * @param   MessageCatalogueLayer  $layer         Administered layer the override sits in.
             * @param   string                 $site          Site the scope belongs to.
             * @param   ?string                $organization  Organization within that site, or null.
             * @param   LocaleTag              $locale        Locale the override applies to.
             * @param   string                 $identifier    Identifier to stop overriding.
             *
             * @return  bool  True when a record was removed.
             *
             * @since   2.0.0
             */
            public function remove(
                MessageCatalogueLayer $layer,
                string $site,
                ?string $organization,
                LocaleTag $locale,
                string $identifier,
            ): bool {
                $key = $this->key($layer, $site, $organization, $locale->toString(), $identifier);
                if (!isset($this->records[$key])) {
                    return false;
                }
                unset($this->records[$key]);

                return true;
            }

            /**
             * Read the site layer the way the render path does.
             *
             * @param   string     $site    Site the overrides belong to.
             * @param   LocaleTag  $locale  Locale to read.
             *
             * @return  array<string, string>  Patterns keyed by identifier.
             *
             * @since   2.0.0
             */
            public function siteOverrides(string $site, LocaleTag $locale): array
            {
                return $this->map(MessageCatalogueLayer::Site, $site, null, $locale);
            }

            /**
             * Read the organization layer the way the render path does.
             *
             * @param   string     $site          Site the organization belongs to.
             * @param   string     $organization  Organization the overrides belong to.
             * @param   LocaleTag  $locale        Locale to read.
             *
             * @return  array<string, string>  Patterns keyed by identifier.
             *
             * @since   2.0.0
             */
            public function organizationOverrides(string $site, string $organization, LocaleTag $locale): array
            {
                return $this->map(MessageCatalogueLayer::Organization, $site, $organization, $locale);
            }

            /**
             * Flatten one scope to the bounded map the chain is assembled from.
             *
             * @param   MessageCatalogueLayer  $layer         Layer to read.
             * @param   string                 $site          Site the scope belongs to.
             * @param   ?string                $organization  Organization within that site, or null.
             * @param   LocaleTag              $locale        Locale to read.
             *
             * @return  array<string, string>  Patterns keyed by identifier.
             *
             * @since   2.0.0
             */
            private function map(
                MessageCatalogueLayer $layer,
                string $site,
                ?string $organization,
                LocaleTag $locale,
            ): array {
                $map = [];
                foreach ($this->overrides($layer, $site, $organization, $locale) as $record) {
                    $map[$record->identifier] = $record->pattern;
                }

                return $map;
            }

            /**
             * Spell the identity two writes of the same message share.
             *
             * @param   MessageCatalogueLayer  $layer         Layer the override sits in.
             * @param   string                 $site          Site the scope belongs to.
             * @param   ?string                $organization  Organization within that site, or null.
             * @param   string                 $locale        Canonical locale tag.
             * @param   string                 $identifier    Message identifier.
             *
             * @return  string  A key unique to that combination.
             *
             * @since   2.0.0
             */
            private function key(
                MessageCatalogueLayer $layer,
                string $site,
                ?string $organization,
                string $locale,
                string $identifier,
            ): string {
                return implode('|', [$layer->value, $site, $organization ?? '', $locale, $identifier]);
            }
        };
    }

    /**
     * A clock fixed to one instant, so a recorded change is compared rather than merely present.
     *
     * @return  ClockInterface  Clock answering a fixed UTC instant.
     *
     * @since   2.0.0
     */
    private function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            /**
             * Answer the fixed instant every write in this test is stamped with.
             *
             * @return  DateTimeImmutable  A fixed UTC instant.
             *
             * @since   2.0.0
             */
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-08-17T09:00:00', new DateTimeZone('UTC'));
            }
        };
    }

    /**
     * A replacement longer than the stored ceiling is refused before it reaches the store.
     *
     * The ceiling exists because the whole override map is read once per unit of work on the render
     * path; a pattern nobody bounded would make every page pay for one operator's paste.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAReplacementBeyondTheStoredCeilingIsRefused(): void
    {
        $store = $this->store();
        $events = [];
        $service = $this->service($store, $events);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('limited to 4000 bytes');
        $service->override(
            $this->actor(),
            MessageCatalogueLayer::Site,
            'en-GB',
            self::CLIENT,
            str_repeat('a', MessageOverrideService::MAXIMUM_PATTERN_BYTES + 1),
        );
    }

    /**
     * A locale tag that is not even well formed is refused as an uncarried locale, not as a crash.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMalformedLocaleTagIsRefusedAsAnUncarriedLocale(): void
    {
        $store = $this->store();
        $events = [];
        $service = $this->service($store, $events);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not carry that locale');
        $service->override($this->actor(), MessageCatalogueLayer::Site, 'not a locale', self::CLIENT, 'Patient');
    }

    /**
     * Inline markup that closes the wrong element is refused, both ways round.
     *
     * Balance has to be proven rather than assumed: a mismatched close and a tag left open are two
     * different ways to store markup that would break the page it is rendered into.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAdministeredWordingRefusesMarkupThatDoesNotBalance(): void
    {
        $store = $this->store();
        $events = [];
        $service = $this->service($store, $events);

        foreach (['<em>Patient</strong>', '<em>Patient', 'Patient</em>'] as $pattern) {
            try {
                $service->override($this->actor(), MessageCatalogueLayer::Site, 'en-GB', self::CLIENT, $pattern);
                self::fail('The service stored unbalanced markup: ' . $pattern);
            } catch (InvalidArgumentException $refused) {
                self::assertStringContainsString('unbalanced inline markup', $refused->getMessage());
            }
        }
    }

    /**
     * A scope that already carries its whole quota refuses one more identifier.
     *
     * The quota is read inside the same transaction as the write, so this also pins that the refusal
     * happens after the durable lock rather than on a stale count.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAScopeAtItsQuotaRefusesAFurtherIdentifier(): void
    {
        $store = $this->store();
        $events = [];
        $service = $this->service($store, $events);
        $at = $this->clock()->now();
        for ($index = 0; $index < MessageOverrideService::MAXIMUM_PER_SCOPE; $index++) {
            $store->put(new MessageOverrideRecord(
                MessageCatalogueLayer::Site,
                'default',
                null,
                'en-GB',
                sprintf('core.business.client.label_%d', $index),
                'Patient',
                $at,
            ));
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maximum of 500 overrides');
        $service->override($this->actor(), MessageCatalogueLayer::Site, 'en-GB', self::CLIENT, 'Patient');
    }
}
