<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Application\Authorization;

use DateTimeImmutable;
use DateTimeZone;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Authorization\OwnershipNarrowingRefused;
use Kumwe\App\Application\Authorization\OwnershipNarrowingUnbounded;
use Kumwe\App\Application\Authorization\OwnershipScope;
use Kumwe\App\Application\Authorization\OwnershipScopeChangeRejected;
use Kumwe\App\Application\Authorization\OwnershipScopeLevel;
use Kumwe\App\Application\Authorization\OwnershipScopeNotPermitted;
use Kumwe\App\Application\Authorization\ResourceOwnership;
use Kumwe\App\Application\Authorization\ResourceOwnershipReferences;
use Kumwe\App\Application\Authorization\ResourceOwnershipScopePolicy;
use Kumwe\App\Application\Authorization\ResourceOwnershipScopeService;
use Kumwe\App\Application\Authorization\ResourceSiteOwnership;
use Kumwe\App\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\App\Application\Authorization\SiteGroup;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Tests\Support\AllowingAuditAuthorization;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\ImmediateTransactionManager;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Pins the deliberate asymmetry between widening a scope and narrowing it again.
 *
 * @since  2.0.0
 */
#[CoversClass(ResourceOwnershipScopeService::class)]
#[CoversClass(OwnershipNarrowingRefused::class)]
#[CoversClass(OwnershipNarrowingUnbounded::class)]
#[CoversClass(OwnershipScopeChangeRejected::class)]
final class ResourceOwnershipScopeServiceTest extends TestCase
{
    /**
     * Identifier of the person record moved between scopes.
     *
     * @var    string
     * @since  2.0.0
     */
    private const PERSON = '018f22e2-7c8b-7ab0-8f3a-88e8026bb901';

    /**
     * Widening records the new owner against the old one and leaves an audit entry naming both.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testWideningMovesTheOwnerAndIsAudited(): void
    {
        $writer = $this->writer();
        $audit = $this->audit();
        $service = $this->service(
            OwnershipScope::site(SiteContext::fromString('assembly')),
            $writer,
            $audit,
        );

        $result = $service->widen(
            AuthorizationContext::human(['ownership.scope.manage'], site: 'assembly'),
            $this->person(),
            OwnershipScope::group($this->group()),
        );

        self::assertSame(OwnershipScopeLevel::Group, $result->level);
        self::assertCount(1, $writer->reassignments);
        self::assertSame('site:assembly', $writer->reassignments[0]['expected']->describe());
        self::assertSame('group:kumwe-group', $writer->reassignments[0]['owner']->scope->describe());
        self::assertCount(1, $audit->events);
        self::assertSame('ownership.scope.widen', $audit->events[0]->action());
        self::assertSame('site:assembly', $audit->events[0]->metadata()['from_scope'] ?? null);
        self::assertSame('group:kumwe-group', $audit->events[0]->metadata()['to_scope'] ?? null);
    }

    /**
     * A widening that would also take reach away is refused rather than guessed at.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testWideningIntoAGroupThatExcludesTheCurrentOwnerIsRefused(): void
    {
        $writer = $this->writer();
        $service = $this->service(
            OwnershipScope::site(SiteContext::fromString('logistics')),
            $writer,
            $this->audit(),
        );

        $this->expectException(OwnershipScopeChangeRejected::class);

        try {
            $service->widen(
                AuthorizationContext::human(['ownership.scope.manage'], site: 'logistics'),
                $this->person(),
                OwnershipScope::group($this->group()),
            );
        } finally {
            self::assertSame([], $writer->reassignments);
        }
    }

    /**
     * A category this build isolates cannot be widened, however the caller is authorized.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnIsolatedCategoryCannotBeWidenedAtAll(): void
    {
        $writer = $this->writer();
        $service = $this->service(
            OwnershipScope::site(SiteContext::fromString('assembly')),
            $writer,
            $this->audit(),
        );

        $this->expectException(OwnershipScopeNotPermitted::class);

        try {
            $service->widen(
                AuthorizationContext::human(['ownership.scope.manage'], site: 'assembly'),
                AuthorizationResource::item('ledger', self::PERSON),
                OwnershipScope::group($this->group()),
            );
        } finally {
            self::assertSame([], $writer->reassignments);
        }
    }

    /**
     * Narrowing is refused, with the stranded sites named, while another member still refers to it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNarrowingIsRefusedWhileAnotherMemberStillRefersToTheResource(): void
    {
        $writer = $this->writer();
        $audit = $this->audit();
        $service = $this->service(
            OwnershipScope::group($this->group()),
            $writer,
            $audit,
            ['retail'],
        );

        try {
            $service->narrow(
                AuthorizationContext::human(['ownership.scope.manage'], site: 'assembly'),
                $this->person(),
                OwnershipScope::site(SiteContext::fromString('assembly')),
            );
            self::fail('Narrowing must be refused while another member site still refers to the resource.');
        } catch (OwnershipNarrowingRefused $refused) {
            self::assertSame(['retail'], $refused->referencingSites);
            self::assertStringContainsString('retail', $refused->getMessage());
        }

        self::assertSame([], $writer->reassignments);
        self::assertSame([], $audit->events);
    }

    /**
     * Narrowing proceeds once nothing in the released sites refers to the resource.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNarrowingProceedsOnceNothingWouldBeStranded(): void
    {
        $writer = $this->writer();
        $audit = $this->audit();
        $service = $this->service(OwnershipScope::group($this->group()), $writer, $audit);

        $result = $service->narrow(
            AuthorizationContext::human(['ownership.scope.manage'], site: 'assembly'),
            $this->person(),
            OwnershipScope::site(SiteContext::fromString('assembly')),
        );

        self::assertSame(OwnershipScopeLevel::Site, $result->level);
        self::assertCount(1, $writer->reassignments);
        self::assertSame('ownership.scope.narrow', $audit->events[0]->action());
        self::assertSame(['retail'], $audit->events[0]->metadata()['released_sites'] ?? null);
    }

    /**
     * Narrowing to a scope that is not inside the current owner is refused as a direction error.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNarrowingToASiteOutsideTheCurrentOwnerIsRefused(): void
    {
        $service = $this->service(OwnershipScope::group($this->group()), $this->writer(), $this->audit());

        $this->expectException(OwnershipScopeChangeRejected::class);
        $service->narrow(
            AuthorizationContext::human(['ownership.scope.manage'], site: 'assembly'),
            $this->person(),
            OwnershipScope::site(SiteContext::fromString('logistics')),
        );
    }

    /**
     * A resource owned by the installation cannot be narrowed, because the losing set is unbounded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNarrowingOutOfTheInstallationScopeIsRefusedAsUnbounded(): void
    {
        $writer = $this->writer();
        $service = $this->service(OwnershipScope::installation(), $writer, $this->audit());

        $this->expectException(OwnershipNarrowingUnbounded::class);

        try {
            $service->narrow(
                AuthorizationContext::human(['ownership.scope.manage'], site: 'assembly'),
                AuthorizationResource::item('theme', 'assembly-theme'),
                OwnershipScope::group($this->group()),
            );
        } finally {
            self::assertSame([], $writer->reassignments);
        }
    }

    /**
     * The resource the scenario moves between scopes.
     *
     * @return  AuthorizationResource  A person record.
     *
     * @since   2.0.0
     */
    private function person(): AuthorizationResource
    {
        return AuthorizationResource::item('person', self::PERSON);
    }

    /**
     * The two-member group the person is shared through.
     *
     * @return  SiteGroup  Declaration naming both member sites.
     *
     * @since   2.0.0
     */
    private function group(): SiteGroup
    {
        return new SiteGroup('kumwe-group', 'Kumwe business group', ['assembly', 'retail']);
    }

    /**
     * Assemble the service around recording doubles.
     *
     * @param   OwnershipScope                     $current     Owner the registry currently reports.
     * @param   RecordingResourceOwnershipWriter   $writer      Recording ownership writer.
     * @param   RecordingOwnershipAudit            $audit       Recording audit sink.
     * @param   list<string>                       $references  Sites reported as still referring to it.
     *
     * @return  ResourceOwnershipScopeService  Service under test.
     *
     * @since   2.0.0
     */
    private function service(
        OwnershipScope $current,
        RecordingResourceOwnershipWriter $writer,
        RecordingOwnershipAudit $audit,
        array $references = [],
    ): ResourceOwnershipScopeService {
        return new ResourceOwnershipScopeService(
            new AllowingAuditAuthorization(),
            new class ($current) implements ResourceSiteOwnership {
                /**
                 * Hold the owner the registry reports.
                 *
                 * @param  OwnershipScope  $current  Owner every lookup answers with.
                 *
                 * @since  2.0.0
                 */
                public function __construct(private OwnershipScope $current)
                {
                }

                /**
                 * Report the configured owner.
                 *
                 * @param   AuthorizationResource  $resource  Target being resolved.
                 *
                 * @return  OwnershipScope  The configured owner.
                 *
                 * @since   2.0.0
                 */
                public function scopeFor(AuthorizationResource $resource): OwnershipScope
                {
                    return $this->current;
                }
            },
            $writer,
            new ResourceOwnershipScopePolicy(),
            new class ($references) implements ResourceOwnershipReferences {
                /**
                 * Hold the sites reported as still referring to the resource.
                 *
                 * @param  list<string>  $referencing  Sites this inspector reports.
                 *
                 * @since  2.0.0
                 */
                public function __construct(private array $referencing)
                {
                }

                /**
                 * Report the configured referencing sites.
                 *
                 * @param   AuthorizationResource  $resource  Resource whose scope is narrowing.
                 * @param   list<string>           $sites     Sites that would lose reach.
                 *
                 * @return  list<string>  The configured subset.
                 *
                 * @since   2.0.0
                 */
                public function sitesReferencing(AuthorizationResource $resource, array $sites): array
                {
                    return array_values(array_intersect($this->referencing, $sites));
                }
            },
            new ImmediateTransactionManager(),
            $audit,
            new class implements ClockInterface {
                /**
                 * Report a fixed instant so audit entries are deterministic.
                 *
                 * @return  DateTimeImmutable  A fixed UTC instant.
                 *
                 * @since   2.0.0
                 */
                public function now(): DateTimeImmutable
                {
                    return new DateTimeImmutable('2026-08-14T09:00:00', new DateTimeZone('UTC'));
                }
            },
        );
    }

    /**
     * A recording ownership writer.
     *
     * @return  RecordingResourceOwnershipWriter  Writer capturing every reassignment.
     *
     * @since   2.0.0
     */
    private function writer(): RecordingResourceOwnershipWriter
    {
        return new RecordingResourceOwnershipWriter();
    }

    /**
     * A recording audit sink.
     *
     * @return  RecordingOwnershipAudit  Sink capturing every event.
     *
     * @since   2.0.0
     */
    private function audit(): RecordingOwnershipAudit
    {
        return new RecordingOwnershipAudit();
    }
}

/**
 * Ownership writer that records what it was asked to do instead of writing it.
 *
 * @since  2.0.0
 */
final class RecordingResourceOwnershipWriter implements ResourceSiteOwnershipWriter
{
    /**
     * Reassignments this writer was asked to perform, in order.
     *
     * @var    list<array{owner: ResourceOwnership, expected: OwnershipScope}>
     * @since  2.0.0
     */
    public array $reassignments = [];

    /**
     * Ignore a creation; the scope-change tests never create a resource.
     *
     * @param   AuthorizationResource  $resource  Resource being created.
     * @param   SiteContext            $site      Site that would own it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function record(AuthorizationResource $resource, SiteContext $site): void
    {
    }

    /**
     * Ignore a withdrawal; the scope-change tests never delete a resource.
     *
     * @param   AuthorizationResource  $resource      Resource being deleted.
     * @param   SiteContext            $expectedSite  Site the caller believes owns it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function remove(AuthorizationResource $resource, SiteContext $expectedSite): void
    {
    }

    /**
     * Capture a reassignment.
     *
     * @param   ResourceOwnership  $owner     Proven pairing the resource moves to.
     * @param   OwnershipScope     $expected  Owner the caller believes holds it now.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function reassign(ResourceOwnership $owner, OwnershipScope $expected): void
    {
        $this->reassignments[] = ['owner' => $owner, 'expected' => $expected];
    }
}

/**
 * Audit sink that keeps the events it is handed.
 *
 * @since  2.0.0
 */
final class RecordingOwnershipAudit implements AuditRecorder
{
    /**
     * Events recorded through this sink, in order.
     *
     * @var    list<AuditEvent>
     * @since  2.0.0
     */
    public array $events = [];

    /**
     * Keep one audit event.
     *
     * @param   AuditEvent  $event  Event being recorded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function record(AuditEvent $event): void
    {
        $this->events[] = $event;
    }
}
