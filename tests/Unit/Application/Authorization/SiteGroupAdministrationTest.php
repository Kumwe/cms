<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Application\Authorization;

use DateTimeImmutable;
use DateTimeZone;
use Kumwe\App\Application\Authorization\OwnershipNarrowingRefused;
use Kumwe\App\Application\Authorization\SiteGroup;
use Kumwe\App\Application\Authorization\SiteGroupAdministration;
use Kumwe\App\Application\Authorization\SiteGroupRegistry;
use Kumwe\App\Application\Authorization\SiteGroupUnknown;
use Kumwe\App\Application\Authorization\SiteGroupWriter;
use Kumwe\App\Tests\Support\AllowingAuditAuthorization;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\ImmediateTransactionManager;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Pins that declaring a group and changing its membership are both gated and both audited.
 *
 * @since  2.0.0
 */
#[CoversClass(SiteGroupAdministration::class)]
#[CoversClass(SiteGroupUnknown::class)]
final class SiteGroupAdministrationTest extends TestCase
{
    /**
     * Declaring a group stores the membership and records what it ended with.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDeclaringAGroupStoresAndAuditsItsMembership(): void
    {
        $writer = new RecordingSiteGroupWriter();
        $audit = new RecordingOwnershipAudit();

        $group = $this->administration($writer, $audit)->define(
            AuthorizationContext::human(['sites.group.manage']),
            'kumwe-group',
            'Kumwe business group',
            ['retail', 'assembly'],
        );

        self::assertSame(['assembly', 'retail'], $group->members);
        self::assertCount(1, $writer->saved);
        self::assertSame('sites.group.define', $audit->events[0]->action());
        self::assertSame(['assembly', 'retail'], $audit->events[0]->metadata()['members'] ?? null);
    }

    /**
     * Inclusion and exclusion each leave their own entry, so neither happens quietly.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInclusionAndExclusionAreBothAudited(): void
    {
        $writer = new RecordingSiteGroupWriter();
        $audit = new RecordingOwnershipAudit();
        $administration = $this->administration($writer, $audit, new SiteGroup(
            'kumwe-group',
            'Kumwe business group',
            ['assembly', 'retail'],
        ));
        $operator = AuthorizationContext::human(['sites.group.manage']);

        $administration->addSite($operator, 'kumwe-group', SiteContext::fromString('freight'));
        $administration->removeSite($operator, 'kumwe-group', SiteContext::fromString('retail'));

        self::assertSame([['kumwe-group', 'freight']], $writer->included);
        self::assertSame([['kumwe-group', 'retail']], $writer->excluded);
        self::assertSame('sites.group.include', $audit->events[0]->action());
        self::assertSame('sites.group.exclude', $audit->events[1]->action());
    }

    /**
     * A group cannot be emptied, because everything it owns would become unreachable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAGroupCannotBeEmptiedOfItsLastMember(): void
    {
        $writer = new RecordingSiteGroupWriter();
        $administration = $this->administration($writer, new RecordingOwnershipAudit(), new SiteGroup(
            'kumwe-group',
            'Kumwe business group',
            ['assembly'],
        ));

        $this->expectException(OwnershipNarrowingRefused::class);

        try {
            $administration->removeSite(
                AuthorizationContext::human(['sites.group.manage']),
                'kumwe-group',
                SiteContext::fromString('assembly'),
            );
        } finally {
            self::assertSame([], $writer->excluded);
        }
    }

    /**
     * Assemble the service around recording doubles.
     *
     * @param   RecordingSiteGroupWriter  $writer    Recording declaration writer.
     * @param   RecordingOwnershipAudit   $audit     Recording audit sink.
     * @param   ?SiteGroup                $declared  Group the registry currently resolves, if any.
     *
     * @return  SiteGroupAdministration  Service under test.
     *
     * @since   2.0.0
     */
    private function administration(
        RecordingSiteGroupWriter $writer,
        RecordingOwnershipAudit $audit,
        ?SiteGroup $declared = null,
    ): SiteGroupAdministration {
        return new SiteGroupAdministration(
            new AllowingAuditAuthorization(),
            new class ($declared) implements SiteGroupRegistry {
                /**
                 * Hold the single declared group, when the scenario has one.
                 *
                 * @param  ?SiteGroup  $declared  Declaration this registry answers with.
                 *
                 * @since  2.0.0
                 */
                public function __construct(private ?SiteGroup $declared)
                {
                }

                /**
                 * Resolve the declared group.
                 *
                 * @param   string  $identifier  Group identifier being resolved.
                 *
                 * @return  SiteGroup  The declaration.
                 *
                 * @throws  SiteGroupUnknown  When the scenario declares nothing under that identifier.
                 *
                 * @since   2.0.0
                 */
                public function group(string $identifier): SiteGroup
                {
                    if ($this->declared === null || $this->declared->identifier !== $identifier) {
                        throw new SiteGroupUnknown($identifier);
                    }

                    return $this->declared;
                }

                /**
                 * List the declarations this registry holds.
                 *
                 * @return  list<SiteGroup>  The single declared group, or nothing.
                 *
                 * @since   2.0.0
                 */
                public function all(): array
                {
                    return $this->declared === null ? [] : [$this->declared];
                }
            },
            $writer,
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
}

/**
 * Declaration writer that records what it was asked to do instead of storing it.
 *
 * @since  2.0.0
 */
final class RecordingSiteGroupWriter implements SiteGroupWriter
{
    /**
     * Declarations this writer was asked to store, in order.
     *
     * @var    list<SiteGroup>
     * @since  2.0.0
     */
    public array $saved = [];

    /**
     * Inclusions this writer was asked to perform, as group and site pairs.
     *
     * @var    list<array{0: string, 1: string}>
     * @since  2.0.0
     */
    public array $included = [];

    /**
     * Exclusions this writer was asked to perform, as group and site pairs.
     *
     * @var    list<array{0: string, 1: string}>
     * @since  2.0.0
     */
    public array $excluded = [];

    /**
     * Capture a stored declaration.
     *
     * @param   SiteGroup  $group  Declaration being stored.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function save(SiteGroup $group): void
    {
        $this->saved[] = $group;
    }

    /**
     * Capture an inclusion.
     *
     * @param   string       $group  Identifier of the declared group.
     * @param   SiteContext  $site   Site being included.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function addSite(string $group, SiteContext $site): void
    {
        $this->included[] = [$group, $site->identifier()];
    }

    /**
     * Capture an exclusion.
     *
     * @param   string       $group  Identifier of the declared group.
     * @param   SiteContext  $site   Site being excluded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function removeSite(string $group, SiteContext $site): void
    {
        $this->excluded[] = [$group, $site->identifier()];
    }
}
