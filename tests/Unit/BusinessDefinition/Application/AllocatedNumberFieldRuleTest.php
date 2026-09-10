<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessDefinition\Application;

use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionValidator;
use Kumwe\App\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\Sequence\Value\NumberSequenceFormat;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the declaration rules an allocated-number field has to satisfy before it can ever be published.
 *
 * Everything checked here is checked at publication rather than at allocation on purpose: a definition
 * that reached storage with a sequence field a caller could write, or one narrower than the numbers it
 * renders, would only fail at the moment an invoice is raised.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessDefinitionValidator::class)]
#[CoversClass(EntityTypeDefinition::class)]
final class AllocatedNumberFieldRuleTest extends TestCase
{
    public function testACloselyDeclaredAllocatedNumberFieldPublishes(): void
    {
        $this->expectNotToPerformAssertions();
        $this->validate([]);
    }

    /**
     * @return  list<array{array<string, mixed>, string}>  A field override and the phrase it must be refused with.
     */
    public static function refusedDeclarations(): array
    {
        return [
            'caller writable' => [['server_only' => false], 'server-only'],
            'caller editable' => [['read_only' => false], 'server-only'],
            'mutable' => [['immutable_after_create' => false], 'server-only'],
            'defaulted' => [['default' => 'INV-000001'], 'server-only'],
            'optional' => [['required' => false, 'nullable' => true], 'required, non-null and unique'],
            'not unique' => [['unique' => false], 'required, non-null and unique'],
            'formula driven' => [
                [
                    'formula' => ['op' => 'field', 'type' => 'string', 'field' => 'name'],
                    'computed' => true,
                    'computation_mode' => 'stored',
                ],
                'reserved by the allocator',
            ],
            'too narrow' => [['length' => NumberSequenceFormat::MAXIMUM_LENGTH - 1], 'at least'],
            'unknown reset' => [['configuration' => ['reset' => 'quarterly']], 'unusable number sequence'],
            'lower-case prefix' => [['configuration' => ['prefix' => 'inv-']], 'unusable number sequence'],
        ];
    }

    /**
     * @param   array<string, mixed>  $override  Field properties replacing the closed declaration's own.
     * @param   string                $phrase    Substring the refusal message must carry.
     */
    #[DataProvider('refusedDeclarations')]
    public function testAnOpenOrUnusableAllocatedNumberFieldIsRefused(array $override, string $phrase): void
    {
        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($phrase, '/') . '/');
        $this->validate($override);
    }

    /**
     * Validate a one-entity graph whose allocated-number field carries the given overrides.
     *
     * @param   array<string, mixed>  $override  Field properties replacing the closed declaration's own.
     */
    private function validate(array $override): void
    {
        (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([
            EntityTypeDefinition::fromArray([
                'id' => '0191574f-f0b8-7bf3-a9aa-91c6b8244f01',
                'owner' => ['type' => 'site', 'identifier' => 'default'],
                'site' => 'default',
                'handle' => 'site.default.allocated_number_rule',
                'singular_label' => 'Allocated number holder',
                'plural_label' => 'Allocated number holders',
                'status' => 'draft',
                'definition_version' => 0,
                'storage_mode' => 'relational',
                'identity_strategy' => 'uuid',
                'scope' => 'site',
                'fields' => [
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
                        'handle' => 'name',
                        'label' => 'Name',
                        'type' => 'core.text',
                        'required' => true,
                        'nullable' => false,
                        'length' => 160,
                    ],
                    [...$this->closedNumberField(), ...$override],
                ],
            ]),
        ]);
    }

    /**
     * The declaration an allocated number is meant to carry, before a test opens one hole in it.
     *
     * @return  array<string, mixed>  A closed, required, unique `core.sequence` field.
     */
    private function closedNumberField(): array
    {
        return [
            'handle' => 'document_number',
            'label' => 'Document number',
            'type' => 'core.sequence',
            'configuration' => ['scope' => 'site', 'reset' => 'yearly', 'prefix' => 'INV-', 'padding' => 6],
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
        ];
    }
}
