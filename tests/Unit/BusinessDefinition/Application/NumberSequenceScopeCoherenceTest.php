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
 * Pins that a declared sequence scope must be composable from the tenancy its entity actually carries.
 *
 * A counter's identity is the record's own resolved coordinates, so a per-organization run on an entity
 * whose scope mode has no organization dimension has nothing to key its counter on. Before this rule the
 * declaration published cleanly and the very first create failed with a raw argument error — the exact
 * worst-possible-time failure the publication validator exists to prevent.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessDefinitionValidator::class)]
final class NumberSequenceScopeCoherenceTest extends TestCase
{
    /**
     * A per-organization sequence on a site-partitioned entity is refused at publication, not at create.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPerOrganizationSequenceOnASitePartitionedEntityIsRefusedAtPublication(): void
    {
        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessageMatches('/carries no organization dimension/');
        $this->validate('site');
    }

    /**
     * The same declaration publishes cleanly once the entity's records really carry an organization.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheSameSequencePublishesWhenTheEntityCarriesAnOrganizationDimension(): void
    {
        $this->expectNotToPerformAssertions();
        $this->validate('site_organization');
    }

    /**
     * Validate a one-entity graph declaring a per-organization sequence under the given scope mode.
     *
     * @param   string  $scopeMode  Tenancy mode the entity partitions its records by.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function validate(string $scopeMode): void
    {
        (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([
            EntityTypeDefinition::fromArray([
                'id' => '0191574f-f0b8-7bf3-a9aa-91c6b8244f02',
                'owner' => ['type' => 'site', 'identifier' => 'default'],
                'site' => 'default',
                'handle' => 'site.default.sequence_scope_coherence',
                'singular_label' => 'Sequence scope holder',
                'plural_label' => 'Sequence scope holders',
                'status' => 'draft',
                'definition_version' => 0,
                'storage_mode' => 'relational',
                'identity_strategy' => 'uuid',
                'scope' => $scopeMode,
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
                        'handle' => 'branch_number',
                        'label' => 'Branch number',
                        'type' => 'core.sequence',
                        'configuration' => ['scope' => 'organization', 'reset' => 'yearly', 'prefix' => 'BR-'],
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
                ],
            ]),
        ]);
    }
}
