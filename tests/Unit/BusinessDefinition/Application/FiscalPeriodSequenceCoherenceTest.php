<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessDefinition\Application;

use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionValidator;
use Kumwe\App\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\Sequence\Value\NumberSequenceFormat;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins that a fiscal-period sequence is only publishable beside the posting date it is keyed on.
 *
 * A `fiscal-period` counter's period key is the declared posting period containing the record's posting
 * date, so a definition that declares the reset without declaring a `posting_date` field would publish
 * cleanly and then fail on its very first create — the exact deferral the publication validator exists
 * to prevent, and the same coherence rule already applied to a per-organization sequence on an entity
 * whose scope carries no organization.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessDefinitionValidator::class)]
final class FiscalPeriodSequenceCoherenceTest extends TestCase
{
    /**
     * A fiscal-period sequence without a posting-date field is refused at publication, not at create.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAFiscalPeriodSequenceWithoutAPostingDateFieldIsRefusedAtPublication(): void
    {
        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessageMatches('/declares no posting date field/');
        $this->validate(false);
    }

    /**
     * The same declaration publishes cleanly once the entity declares the posting date it keys on.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheSameSequencePublishesOnceAPostingDateFieldIsDeclared(): void
    {
        $this->expectNotToPerformAssertions();
        $this->validate(true);
    }

    /**
     * Validate a one-entity graph declaring a fiscal-period sequence, with or without a posting date.
     *
     * @param   bool  $withPostingDate  Whether the entity also declares a `posting_date` field.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function validate(bool $withPostingDate): void
    {
        $fields = [
            [
                'handle' => 'id',
                'label' => 'ID',
                'type' => 'core.uuid',
                'required' => true,
                'nullable' => false,
                'unique' => true,
                'indexed' => true,
                'immutable_after_create' => true,
                'server_only' => true,
                'read_only' => true,
            ],
            [
                'handle' => 'fiscal_number',
                'label' => 'Fiscal number',
                'type' => 'core.sequence',
                'configuration' => ['reset' => 'fiscal-period', 'prefix' => 'FIS-'],
                'required' => true,
                'nullable' => false,
                'length' => NumberSequenceFormat::MAXIMUM_LENGTH,
                'unique' => true,
                'indexed' => true,
                'immutable_after_create' => true,
                'server_only' => true,
                'read_only' => true,
                'create_visible' => false,
                'update_visible' => false,
            ],
        ];
        if ($withPostingDate) {
            $fields[] = [
                'handle' => 'posted_on',
                'label' => 'Posted on',
                'type' => 'core.date',
                'required' => false,
                'nullable' => true,
                'filterable' => true,
                'configuration' => ['posting_date' => true],
            ];
        }
        (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([
            EntityTypeDefinition::fromArray([
                'id' => '0191574f-f0b8-7bf3-a9aa-91c6b8244f03',
                'owner' => ['type' => 'site', 'identifier' => 'default'],
                'site' => 'default',
                'handle' => 'site.default.fiscal_sequence_coherence',
                'singular_label' => 'Fiscal sequence holder',
                'plural_label' => 'Fiscal sequence holders',
                'status' => 'draft',
                'definition_version' => 0,
                'storage_mode' => 'relational',
                'identity_strategy' => 'uuid',
                'scope' => 'site',
                'fields' => $fields,
            ]),
        ]);
    }
}
