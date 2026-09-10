<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Extension;

use Closure;
use DateTimeImmutable;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\PolicyBusinessRecordReader;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordReadRepository;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordProjection;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\App\BusinessSecurity\Application\Administration\BusinessSecurityAdministrationRepository;
use Kumwe\App\BusinessSecurity\Infrastructure\Persistence\DoctrineBusinessRecordAccessController;
use Kumwe\Extension\Spi\BusinessSecurity\Policy\RecordPolicyComparison;
use Kumwe\Extension\Spi\BusinessSecurity\Policy\RecordPolicyComparisonOperator;
use Kumwe\Extension\Spi\BusinessSecurity\Policy\RecordPolicyValueType;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessViewQuery;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use KumweExample\AssetInspection\Application\InspectionSummaryViewHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Kumwe\App\Extension\Runtime\ExtensionExecutionContext;

/**
 * Exercises the example custom view against the real record service and persisted row/field policies.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessRecordService::class)]
#[CoversClass(DoctrineBusinessRecordAccessController::class)]
#[CoversClass(DoctrineBusinessRecordReadRepository::class)]
final class AssetInspectionCustomViewIntegrationTest extends TestCase
{
    /**
     * Example-namespace autoloader installed only while this test class runs.
     *
     * @var    ?Closure(string):void
     * @since  2.0.0
     */
    private static ?Closure $exampleLoader = null;

    /**
     * Register the package source without widening the application's production autoload map.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $source = dirname(__DIR__, 3) . '/examples/extensions/asset-inspection/src/';
        self::$exampleLoader = static function (string $class) use ($source): void {
            $prefix = 'KumweExample\\AssetInspection\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $file = $source . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        };
        spl_autoload_register(self::$exampleLoader, true, true);
    }

    /**
     * Remove the temporary example autoloader after this class completes.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public static function tearDownAfterClass(): void
    {
        if (self::$exampleLoader instanceof Closure) {
            spl_autoload_unregister(self::$exampleLoader);
            self::$exampleLoader = null;
        }
        parent::tearDownAfterClass();
    }

    /**
     * Prove the handler honors the bounded row selector and never exposes a restricted field.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testSummaryUsesCanonicalBrowsePolicyAndOmitsRestrictedFields(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $administrator = TestKernelFactory::administratorContext($container);
        $definitionId = Uuid::uuid7()->toString();
        // The marker keys every identity this test mints to this run. Idempotency keys live in the
        // installation-global mutation ledger, so a fixed key would meet the entry a previous suite run
        // recorded for a definition that no longer exists and be refused as a conflicting replay. It is
        // cut from the UUID's random tail, because the head is a timestamp two close runs can share.
        $marker = substr(str_replace('-', '', $definitionId), -10);
        $suffix = 'inspection' . substr(str_replace('-', '', $definitionId), 0, 10);
        $document = NeutralBusinessFixture::document($suffix, $definitionId);
        $document['fields'][] = [
            'handle' => 'reference',
            'label' => 'Reference',
            'type' => 'core.text',
            'required' => true,
            'nullable' => false,
            'length' => 80,
            'unique' => true,
            'indexed' => true,
            'filterable' => true,
            'sortable' => true,
        ];
        $document['fields'][] = [
            'handle' => 'risk_score',
            'label' => 'Risk score',
            'type' => 'core.integer',
            'required' => true,
            'nullable' => false,
            'indexed' => true,
            'filterable' => true,
            'sortable' => true,
        ];
        $document['fields'][] = [
            'handle' => 'internal_note',
            'label' => 'Restricted internal note',
            'type' => 'core.text',
            'required' => false,
            'nullable' => true,
            'length' => 500,
            'sensitivity' => 'restricted',
        ];
        $definition = NeutralBusinessFixture::install($container, $administrator, $document);
        $records = $container->get(BusinessRecordService::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);

        for ($row = 1; $row <= 11; ++$row) {
            $records->create(new CreateRecordCommand(
                $administrator,
                $definition->handle,
                [
                    ...NeutralBusinessFixture::recordValues('Visible inspection ' . $row),
                    'reference' => sprintf('VISIBLE-INSPECTION-%02d', $row),
                    'risk_score' => 70 + $row,
                    'internal_note' => 'restricted inspection note ' . $row,
                ],
                NeutralBusinessFixture::idempotencyKey('inspection-summary-visible-' . $row . '-' . $marker),
            ));
        }
        $records->create(new CreateRecordCommand(
            $administrator,
            $definition->handle,
            [
                ...NeutralBusinessFixture::recordValues('Denied inspection'),
                'reference' => 'ROW-POLICY-DENIED',
                'risk_score' => 69,
                'internal_note' => 'denied restricted inspection note',
            ],
            NeutralBusinessFixture::idempotencyKey('inspection-summary-denied-' . $marker),
        ));

        NeutralBusinessFixture::removeRecordAccess($container, $definition->id);
        $predicate = (new RecordPolicyComparison(
            'risk_score',
            RecordPolicyComparisonOperator::GreaterThanOrEqual,
            RecordPolicyValueType::Integer,
            70,
        ))->toArray();
        $fieldRules = [
            'list' => ['reference', 'risk_score'],
            'actions' => [],
        ];
        $repository = $container->get(BusinessSecurityAdministrationRepository::class);
        self::assertInstanceOf(BusinessSecurityAdministrationRepository::class, $repository);
        $repository->insertResourcePolicy(
            Uuid::uuid7()->toString(),
            'test.asset-inspection.summary.' . Uuid::uuid7()->toString(),
            'business.record.browse',
            'business.record.browse',
            'allow',
            null,
            $definition->id,
            $predicate,
            $fieldRules,
            CanonicalDefinitionJson::checksum(['ast' => $predicate, 'fields' => $fieldRules]),
            100,
            $administrator->actorId(),
            $administrator->site()->identifier(),
            new DateTimeImmutable(),
        );
        $viewer = TestKernelFactory::contextFromGrantRows($container, [
            [
                'capability' => 'business.record.browse',
                'scope_type' => 'site',
                'scope_identifier' => $administrator->site()->identifier(),
            ],
        ]);
        $principal = $viewer->principal();
        self::assertNotNull($principal);
        self::assertSame(
            ['business.record.browse'],
            array_map(static fn (Capability $capability): string => $capability->value(), $principal->capabilities()),
        );
        $handler = new InspectionSummaryViewHandler(new PolicyBusinessRecordReader($records));

        $result = $handler->handle(new CustomBusinessViewQuery(
            ExtensionExecutionContext::of($viewer),
            $definition->handle,
            'inspection_risk_summary',
            new RecordQuerySpecification(
                pageSize: 200,
                projection: new RecordProjection(['reference', 'risk_score', 'internal_note']),
            ),
        ));

        self::assertCount(11, $result->data['inspections']);
        self::assertFalse($result->data['restricted_fields_disclosed']);
        $encoded = json_encode($result->data, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('VISIBLE-INSPECTION-', $encoded);
        self::assertStringNotContainsString('ROW-POLICY-DENIED', $encoded);
        self::assertStringNotContainsString('internal_note', $encoded);
        self::assertStringNotContainsString('restricted inspection note', $encoded);
        foreach ($result->data['inspections'] as $inspection) {
            self::assertSame(['reference', 'risk_score'], array_keys($inspection));
            self::assertGreaterThanOrEqual(70, $inspection['risk_score']);
        }
    }
}
