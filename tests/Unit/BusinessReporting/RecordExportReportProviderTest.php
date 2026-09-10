<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessReporting;

use DateTimeImmutable;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable;
use Kumwe\App\BusinessRecord\Application\ResolvedBusinessDefinition;
use Kumwe\App\BusinessReporting\Application\RecordExportReportProvider;
use Kumwe\App\BusinessReporting\Application\ReportUnavailable;
use Kumwe\App\BusinessReporting\Domain\ReportColumnDefinition;
use Kumwe\Extension\Spi\BusinessReporting\Domain\ReportValueType;
use Kumwe\App\BusinessSchema\Domain\PhysicalColumnBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalSchemaBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalTableBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalTableKind;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallation;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordExportReportProvider::class)]
final class RecordExportReportProviderTest extends TestCase
{
    public function testDerivedRecordSetExportReportIsDeterministicAndPolicyShaped(): void
    {
        $definition = $this->definition(portalExport: true);
        $provider = $this->provider($definition);
        $context = AuthorizationContext::human(['business.record.export']);

        $report = $provider->forDefinition($context, $definition->handle);
        $again = $provider->resolve($context, $report->identifier());

        self::assertSame(
            RecordExportReportProvider::IDENTIFIER_PREFIX . $definition->handle,
            $report->identifier(),
        );
        self::assertSame($definition->handle, $report->sourceDefinition);
        self::assertSame($definition->definitionVersion, $report->version);
        self::assertSame('business.record.export', $report->requiredCapability);
        self::assertSame([], $report->parameters);
        self::assertSame([], $report->filters);
        self::assertSame(
            ['name' => ReportValueType::String, 'amount' => ReportValueType::Decimal],
            array_combine(
                array_map(
                    static fn (ReportColumnDefinition $column): string => $column->alias,
                    $report->columns,
                ),
                array_map(
                    static fn (ReportColumnDefinition $column): ReportValueType => $column->type,
                    $report->columns,
                ),
            ),
        );
        self::assertTrue($report->administratorVisible);
        self::assertTrue($report->portalVisible);
        self::assertSame($report->checksum(), $again->checksum());
    }

    public function testPortalVisibilityRequiresTheDefinitionLevelExportOptIn(): void
    {
        $definition = $this->definition(portalExport: false);
        $provider = $this->provider($definition);

        $report = $provider->forDefinition(
            AuthorizationContext::human(['business.record.export']),
            $definition->handle,
        );

        self::assertTrue($report->administratorVisible);
        self::assertFalse($report->portalVisible);
    }

    public function testForeignAndMismatchedIdentifiersStayUnavailable(): void
    {
        $definition = $this->definition(portalExport: true);
        $provider = $this->provider($definition);
        $context = AuthorizationContext::human(['business.record.export']);

        $foreign = $this->unavailable(static fn () => $provider->resolve($context, 'acme.open_items'));
        $mismatched = $this->unavailable(static fn () => $provider->resolve(
            $context,
            RecordExportReportProvider::IDENTIFIER_PREFIX . $definition->id,
        ));

        self::assertSame('The report is unavailable.', $foreign->getMessage());
        self::assertSame($foreign->getMessage(), $mismatched->getMessage());
    }

    public function testUnavailableAndNonExportableDefinitionsStayUnavailable(): void
    {
        $context = AuthorizationContext::human(['business.record.export']);
        $missing = new class implements BusinessRecordDefinitionResolver {
            public function activeInstalled(ExecutionContext $context): array
            {
                return [];
            }

            public function forCreate(ExecutionContext $context, string $identifier): ResolvedBusinessDefinition
            {
                throw new BusinessRecordDefinitionUnavailable();
            }

            public function pinned(
                ExecutionContext $context,
                string $identifier,
                int $definitionVersion,
            ): ResolvedBusinessDefinition {
                throw new BusinessRecordDefinitionUnavailable();
            }

            public function forHistory(
                ExecutionContext $context,
                string $identifier,
                ?int $definitionVersion = null,
            ): ResolvedBusinessDefinition {
                throw new BusinessRecordDefinitionUnavailable();
            }
        };
        $unavailable = $this->unavailable(
            static fn () => (new RecordExportReportProvider($missing, new FieldTypeRegistry()))
                ->forDefinition($context, NeutralBusinessFixture::HANDLE),
        );

        $document = NeutralBusinessFixture::document('noexport', '0191574f-f0b8-7bf3-a9aa-91c6b8244e40');
        self::assertIsArray($document['fields']);
        foreach ($document['fields'] as &$field) {
            self::assertIsArray($field);
            unset($field['exportable']);
        }
        unset($field);
        $bare = EntityTypeDefinition::fromArray($document)->published(1);
        $nonExportable = $this->unavailable(
            fn () => $this->provider($bare)->forDefinition($context, $bare->handle),
        );

        self::assertSame('The report is unavailable.', $unavailable->getMessage());
        self::assertSame($unavailable->getMessage(), $nonExportable->getMessage());
    }

    private function definition(bool $portalExport): EntityTypeDefinition
    {
        $document = NeutralBusinessFixture::document('recexport', '0191574f-f0b8-7bf3-a9aa-91c6b8244e41');
        $document['portal_exposure'] = true;
        $document['portal_operations'] = $portalExport ? ['browse', 'export'] : ['browse'];

        return EntityTypeDefinition::fromArray($document)->published(1);
    }

    private function provider(EntityTypeDefinition $definition): RecordExportReportProvider
    {
        $resolved = $this->resolved($definition);
        $resolver = new class ($resolved) implements BusinessRecordDefinitionResolver {
            public function __construct(private readonly ResolvedBusinessDefinition $resolved)
            {
            }

            public function activeInstalled(ExecutionContext $context): array
            {
                return [$this->resolved];
            }

            public function forCreate(ExecutionContext $context, string $identifier): ResolvedBusinessDefinition
            {
                if (
                    $identifier !== $this->resolved->definition->handle
                    && $identifier !== $this->resolved->definition->id
                ) {
                    throw new BusinessRecordDefinitionUnavailable();
                }

                return $this->resolved;
            }

            public function pinned(
                ExecutionContext $context,
                string $identifier,
                int $definitionVersion,
            ): ResolvedBusinessDefinition {
                return $this->forCreate($context, $identifier);
            }

            public function forHistory(
                ExecutionContext $context,
                string $identifier,
                ?int $definitionVersion = null,
            ): ResolvedBusinessDefinition {
                return $this->forCreate($context, $identifier);
            }
        };

        return new RecordExportReportProvider($resolver, new FieldTypeRegistry());
    }

    private function resolved(EntityTypeDefinition $definition): ResolvedBusinessDefinition
    {
        $column = new PhysicalColumnBlueprint('record_id', 'c_record_id_12345678901234567890', 'guid');
        $table = new PhysicalTableBlueprint(
            'record',
            'kb_e_record_12345678901234567890',
            PhysicalTableKind::Entity,
            [$column],
            [$column->physicalName],
        );
        $blueprint = new PhysicalSchemaBlueprint(
            $definition->id,
            $definition->definitionVersion,
            $definition->checksum(),
            [$table],
        );
        $at = new DateTimeImmutable('2026-08-10T00:00:00+00:00');

        return new ResolvedBusinessDefinition($definition, new SchemaInstallation(
            $definition->id,
            $definition->siteIdentifier,
            'core',
            $definition->definitionVersion,
            $definition->checksum(),
            $blueprint->checksum(),
            $blueprint,
            SchemaInstallationStatus::Active,
            $at,
            $at,
        ));
    }

    /**
     * @param  callable(): mixed  $operation  Report derivation expected to fail without enumeration.
     */
    private function unavailable(callable $operation): ReportUnavailable
    {
        try {
            $operation();
            self::fail('An unavailable derived record export must not be returned.');
        } catch (ReportUnavailable $exception) {
            return $exception;
        }
    }
}
