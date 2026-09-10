<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessDefinition\Administrator;

use Kumwe\App\BusinessDefinition\Administrator\BusinessDefinitionFormMapper;
use Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BusinessDefinitionFormMapper::class)]
final class BusinessDefinitionFormMapperTest extends TestCase
{
    public function testGraphicalControlsProduceACompleteTypedDraftWithoutExecutableInput(): void
    {
        $definition = (new BusinessDefinitionFormMapper())->definition([
            'handle' => 'site.default.invoice',
            'singular_label' => 'Invoice',
            'plural_label' => 'Invoices',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'field_0_handle' => 'id',
            'field_0_label' => 'ID',
            'field_0_type' => 'core.uuid',
            'field_0_required' => '1',
            'field_0_unique' => '1',
            'field_0_indexed' => '1',
            'field_0_order' => '0',
            'field_1_handle' => 'net',
            'field_1_label' => 'Net',
            'field_1_type' => 'core.decimal',
            'field_1_precision' => '30',
            'field_1_scale' => '6',
            'field_1_order' => '10',
            'field_2_handle' => 'tax',
            'field_2_label' => 'Tax',
            'field_2_type' => 'core.decimal',
            'field_2_precision' => '30',
            'field_2_scale' => '6',
            'field_2_order' => '20',
            'field_3_handle' => 'gross',
            'field_3_label' => 'Gross',
            'field_3_type' => 'core.computed',
            'field_3_computed' => '1',
            'field_3_formula_type' => 'decimal',
            'field_3_formula_left' => 'net',
            'field_3_formula_operator' => 'add',
            'field_3_formula_right' => 'tax',
            'field_3_order' => '30',
            'view_0_handle' => 'detail',
            'view_0_label' => 'Invoice detail',
            'view_0_kind' => 'detail',
            'view_0_fields' => 'net, tax, gross',
        ], SiteContext::default());

        self::assertSame('site.default.invoice', $definition->handle);
        self::assertSame(['net', 'tax'], $definition->fields()[3]->formula?->dependencies());
        self::assertSame('12.5', $definition->fields()[3]->formula?->evaluate(['net' => '10', 'tax' => '2.5']));
        self::assertStringNotContainsString('eval', json_encode($definition->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testExactNumericGraphicalControlsRejectMissingPrecisionBeforePersistence(): void
    {
        $this->expectException(InvalidBusinessDefinition::class);
        (new BusinessDefinitionFormMapper())->definition([
            'handle' => 'site.default.invalid',
            'singular_label' => 'Invalid',
            'plural_label' => 'Invalids',
            'field_0_handle' => 'id', 'field_0_label' => 'ID', 'field_0_type' => 'core.uuid',
            'field_0_required' => '1',
            'field_1_handle' => 'amount', 'field_1_label' => 'Amount', 'field_1_type' => 'core.money',
        ], SiteContext::default());
    }

    public function testGraphicalRoundTripPreservesBoundedExtensionConfigurationAndValidators(): void
    {
        $definition = (new BusinessDefinitionFormMapper())->definition([
            'handle' => 'site.default.configured',
            'singular_label' => 'Configured entity',
            'plural_label' => 'Configured entities',
            'field_0_handle' => 'id',
            'field_0_label' => 'ID',
            'field_0_type' => 'core.uuid',
            'field_0_required' => '1',
            'field_1_handle' => 'code',
            'field_1_label' => 'Code',
            'field_1_type' => 'site.default.custom_type',
            'field_1_configuration_preserved' => '{"widget":"compact"}',
            'field_1_validators_preserved' => '[{"rule":"pattern","value":"^[A-Z]+$"}]',
            'field_1_hide_update' => '1',
        ], SiteContext::default());

        self::assertSame(['widget' => 'compact'], $definition->fields()[1]->configuration);
        self::assertSame('pattern', $definition->fields()[1]->validators[0]['rule']);
        self::assertFalse($definition->fields()[1]->updateVisible);
    }
}
