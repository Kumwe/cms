<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessReporting;

use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\App\BusinessReporting\Application\ReportDefinitionRegistry;
use Kumwe\App\BusinessReporting\Application\ReportExecutionResult;
use Kumwe\App\BusinessReporting\Delivery\Api\ReportApiPresenter;
use Kumwe\App\BusinessReporting\Domain\ReportColumnDefinition;
use Kumwe\App\BusinessReporting\Domain\ReportDefinition;
use Kumwe\App\BusinessReporting\Domain\ReportDrillDownDefinition;
use Kumwe\App\BusinessReporting\Domain\ReportParameterDefinition;
use Kumwe\Extension\Spi\BusinessReporting\Domain\ReportValueType;
use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ReportApiPresenter::class)]
#[CoversClass(ReportDefinitionRegistry::class)]
final class ReportDeliveryPresenterTest extends TestCase
{
    public function testActiveReportAppearsWithTypedParametersAndDisappearsAfterReconciliation(): void
    {
        $report = new ReportDefinition(
            'acme.open_items',
            1,
            'Open items',
            'acme.item',
            'acme.reports.read',
            [new ReportParameterDefinition('owner', ReportValueType::Identifier, required: true)],
            [],
            [new ReportColumnDefinition('number', 'Number', 'number', ReportValueType::String)],
            portalVisible: true,
        );
        $active = new ReportDefinitionRegistry([$report]);
        $presenter = new ReportApiPresenter();

        $document = $presenter->definition($active->all()[0], '/api/v1/business/reports', true);

        self::assertSame('acme.open_items', $document['id']);
        self::assertSame('/api/v1/business/reports/acme.open_items', $document['execute_url']);
        self::assertSame('/api/v1/business/reports/acme.open_items/exports', $document['export_url']);
        self::assertSame([
            'name' => 'owner',
            'type' => 'identifier',
            'required' => true,
            'multiple' => false,
            'default' => null,
        ], $document['parameters'][0]);
        self::assertSame([], (new ReportDefinitionRegistry([]))->all());
    }

    /**
     * Refuse a contributed report snapshot from an old generation before it reaches a delivery presenter.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStaleGenerationCannotExposeResidentReportContribution(): void
    {
        $execution = $this->createMock(ExtensionExecutionGate::class);
        $execution->expects(self::once())
            ->method('assertCurrent')
            ->willThrowException(new RuntimeException('stale extension generation'));
        $registry = new ReportDefinitionRegistry([
            new ReportDefinition(
                'acme.open_items',
                1,
                'Open items',
                'acme.item',
                'acme.reports.read',
                [],
                [],
                [new ReportColumnDefinition('number', 'Number', 'number', ReportValueType::String)],
            ),
        ], $execution);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('stale extension generation');
        $registry->all();
    }

    public function testResultDrillDownsUseOnlyGeneratedSurfaceRoutes(): void
    {
        $result = new ReportExecutionResult(
            'acme.open_items',
            str_repeat('a', 64),
            str_repeat('b', 64),
            ['record' => 'Record'],
            ['record' => ReportValueType::Identifier],
            [['record' => 'record-7']],
            [new ReportDrillDownDefinition('record', 'acme.item', 'acme.item.detail')],
        );
        $presenter = new ReportApiPresenter();

        $administrator = $presenter->report($result, AuthenticatedSurface::Administrator);
        $api = $presenter->report($result);

        self::assertTrue($administrator['has_drill_downs']);
        self::assertSame(
            '/administrator/business/acme.item/record-7/views/acme.item.detail',
            $administrator['drill_downs'][0][0]['url'],
        );
        self::assertSame(
            '/api/v1/business/views/acme.item/record-7/acme.item.detail',
            $api['drill_downs'][0][0]['url'],
        );
    }
}
