<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Support;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\App\BusinessDefinition\Application\DefinitionVersionRecord;
use Kumwe\App\BusinessDefinition\Domain\CompatibilityPlan;
use Kumwe\App\BusinessDefinition\Domain\DefinitionStatus;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaInstallationRepository;
use Kumwe\App\BusinessSchema\Domain\PhysicalColumnBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalSchemaBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalTableBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalTableKind;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallation;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TransientBusinessDefinitionFixtureScope;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Proves process-scoped definition fixtures leave one coherent inactive lifecycle behind.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class TransientBusinessDefinitionFixtureScopeTest extends TestCase
{
    /**
     * A new active installation is disabled in the same transaction that rejects its definition.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testWithdrawalPreservesTheBaselineAndRetiresACompleteLiveFixture(): void
    {
        $baselineId = '018f4f24-98d8-7ad4-8f3f-38c909178b6b';
        $fixtureId = '018f4f24-98d8-7ad4-8f3f-38c909178b6c';
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())
            ->method('fetchFirstColumn')
            ->with('SELECT id FROM kumwe_business_definitions ORDER BY id')
            ->willReturn([$baselineId]);
        $database->expects(self::once())->method('close');
        $database->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                'SELECT id, handle, site_identifier, published_version, publication_state '
                . 'FROM kumwe_business_definitions ORDER BY site_identifier, id',
            )
            ->willReturn([
                [
                    'id' => $baselineId,
                    'handle' => 'site.default.baseline',
                    'site_identifier' => 'default',
                    'published_version' => 4,
                    'publication_state' => DefinitionStatus::Published->value,
                ],
                [
                    'id' => $fixtureId,
                    'handle' => 'site.default.transient_fixture',
                    'site_identifier' => 'default',
                    'published_version' => '1',
                    'publication_state' => DefinitionStatus::Published->value,
                ],
                [
                    'id' => NeutralBusinessFixture::DEFINITION_ID,
                    'handle' => NeutralBusinessFixture::HANDLE,
                    'site_identifier' => 'default',
                    'published_version' => 1,
                    'publication_state' => DefinitionStatus::Published->value,
                ],
            ]);

        $at = new DateTimeImmutable('2026-08-21T10:00:00+00:00');
        $installation = self::installation($fixtureId, $at);
        $installations = $this->createMock(BusinessSchemaInstallationRepository::class);
        $installations->expects(self::once())->method('find')->with($fixtureId)->willReturn($installation);
        $installations->expects(self::once())->method('save')->with(self::callback(
            static fn (SchemaInstallation $saved): bool =>
                $saved->definitionId === $fixtureId
                && $saved->status === SchemaInstallationStatus::Disabled
                && $saved->updatedAt === $at,
        ));

        $definitions = $this->createMock(BusinessDefinitionRepository::class);
        $record = self::record($fixtureId, $at);
        $definitions->expects(self::once())->method('changeStatus')->with(
            SiteContext::default(),
            $fixtureId,
            1,
            DefinitionStatus::Rejected,
            $at,
        )->willReturn($record);
        $transactions = $this->createMock(TransactionManager::class);
        $transactions->expects(self::once())->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $clock = $this->createMock(ClockInterface::class);
        $clock->expects(self::once())->method('now')->willReturn($at);

        $scope = new TransientBusinessDefinitionFixtureScope(
            $database,
            new TableNames($database, 'kumwe_'),
            $definitions,
            $installations,
            $transactions,
            $clock,
        );

        $scope->withdraw();
    }

    /**
     * Build one valid active installation whose status transition the scope can exercise.
     *
     * @param   string             $definitionId  Fixture definition identity.
     * @param   DateTimeImmutable  $at            Install and update timestamp.
     *
     * @return  SchemaInstallation  Active installation bound to a minimal physical blueprint.
     *
     * @since  2.0.0
     */
    private static function installation(string $definitionId, DateTimeImmutable $at): SchemaInstallation
    {
        $definitionChecksum = str_repeat('a', 64);
        $id = new PhysicalColumnBlueprint('record_id', 'c_record_id_12345678901234567890', 'guid');
        $table = new PhysicalTableBlueprint(
            'record',
            'kb_e_record_12345678901234567890',
            PhysicalTableKind::Entity,
            [$id],
            [$id->physicalName],
        );
        $blueprint = new PhysicalSchemaBlueprint($definitionId, 1, $definitionChecksum, [$table]);

        return new SchemaInstallation(
            $definitionId,
            'default',
            'default',
            1,
            $definitionChecksum,
            $blueprint->checksum(),
            $blueprint,
            SchemaInstallationStatus::Active,
            $at,
            $at,
        );
    }

    /**
     * Build the rejected version record returned by the mocked lifecycle repository.
     *
     * @param   string             $definitionId  Fixture definition identity.
     * @param   DateTimeImmutable  $at            Publication timestamp.
     *
     * @return  DefinitionVersionRecord  Version-one record in the withdrawal state.
     *
     * @since  2.0.0
     */
    private static function record(string $definitionId, DateTimeImmutable $at): DefinitionVersionRecord
    {
        $document = NeutralBusinessFixture::document('scopefixture', $definitionId);
        $definition = EntityTypeDefinition::fromArray($document)->published(1);

        return new DefinitionVersionRecord(
            $definition,
            new CompatibilityPlan(null, 1, null, $definition->checksum(), []),
            DefinitionStatus::Rejected,
            'integration-fixture-scope',
            $at,
        );
    }
}
