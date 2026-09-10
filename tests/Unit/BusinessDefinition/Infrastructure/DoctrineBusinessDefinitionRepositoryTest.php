<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessDefinition\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionRevisionConflict;
use Kumwe\App\BusinessDefinition\Domain\CompatibilityPlan;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Infrastructure\Persistence\DoctrineBusinessDefinitionRepository;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\Context\Value\SiteContext;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineBusinessDefinitionRepository::class)]
/**
 * Proves derived contract-name admission uses a portable site-wide transaction lock.
 *
 * @since  2.0.0
 */
final class DoctrineBusinessDefinitionRepositoryTest extends TestCase
{
    /**
     * Lock the stable site row so an empty or disjoint definition catalog cannot escape serialization.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testContractNamespaceLockUsesSiteAuthorityRowForUpdate(): void
    {
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('isTransactionActive')->willReturn(true);
        $database->expects(self::once())->method('quoteSingleIdentifier')
            ->with('kumwe_sites')
            ->willReturn('`kumwe_sites`');
        $database->expects(self::once())->method('fetchOne')->with(
            self::callback(static fn (string $sql): bool => $sql
                === 'SELECT identifier FROM `kumwe_sites` WHERE identifier = ? FOR UPDATE'),
            [SiteContext::DEFAULT],
            [Types::STRING],
        )->willReturn(SiteContext::DEFAULT);
        $repository = new DoctrineBusinessDefinitionRepository(
            $database,
            new TableNames($database, 'kumwe_'),
        );

        $repository->lockContractNamespace(SiteContext::default());
    }

    /**
     * Refuse namespace locking outside the transaction that will perform admission and publication.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testContractNamespaceLockRequiresActiveTransaction(): void
    {
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('isTransactionActive')->willReturn(false);
        $database->expects(self::never())->method('fetchOne');
        $repository = new DoctrineBusinessDefinitionRepository(
            $database,
            new TableNames($database, 'kumwe_'),
        );

        $this->expectException(LogicException::class);
        $repository->lockContractNamespace(SiteContext::default());
    }

    /**
     * Refuse publication when the definition identity disappeared after compatibility analysis.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPublishReportsARevisionConflictWhenTheIdentityHeadIsMissing(): void
    {
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('isTransactionActive')->willReturn(true);
        $database->expects(self::once())->method('quoteSingleIdentifier')
            ->with('kumwe_business_definitions')
            ->willReturn('`kumwe_business_definitions`');
        $database->expects(self::once())->method('fetchAssociative')->with(
            self::callback(static fn (string $sql): bool => str_contains(
                $sql,
                'SELECT * FROM `kumwe_business_definitions` WHERE id = ?',
            )),
            [NeutralBusinessFixture::DEFINITION_ID],
            [Types::GUID],
        )->willReturn(false);
        $database->expects(self::never())->method('executeStatement');
        $definition = EntityTypeDefinition::fromArray(NeutralBusinessFixture::document())->published(1);
        $plan = new CompatibilityPlan(null, 1, null, $definition->checksum(), []);
        $repository = new DoctrineBusinessDefinitionRepository(
            $database,
            new TableNames($database, 'kumwe_'),
        );

        try {
            $repository->publish(
                $definition,
                $plan,
                '018f5200-0000-7000-8000-000000000099',
                new DateTimeImmutable('2026-08-22T12:00:00+00:00'),
                7,
            );
            self::fail('A missing publication identity head was accepted.');
        } catch (BusinessDefinitionRevisionConflict $failure) {
            self::assertSame(7, $failure->expected);
            self::assertSame(0, $failure->actual);
        }
    }
}
