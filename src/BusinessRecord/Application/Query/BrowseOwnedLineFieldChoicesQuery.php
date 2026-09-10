<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application\Query;

use InvalidArgumentException;
use Kumwe\App\BusinessRecord\Application\RecordRequestGuard;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordQuerySpecification;

/**
 * Bounded selector request for an entity-reference field inside a new owned-line target.
 *
 * The source record and owned relationship remain part of the request so authorization can traverse
 * the exact source relation plan and then the exact target-field plan. A direct browse of the line
 * definition would lose that first policy hop and could widen the choices offered to the caller.
 *
 * @since  2.0.0
 */
final readonly class BrowseOwnedLineFieldChoicesQuery
{
    /**
     * Validate one two-hop selector request before policy planning or repository access.
     *
     * @param   ExecutionContext          $context                 Authenticated actor and scope.
     * @param   string                    $definitionIdentifier    Existing source definition UUID or handle.
     * @param   string                    $sourceRecordId          Existing source public identity.
     * @param   string                    $relationship            Owned-line relationship handle.
     * @param   string                    $field                   Target entity-reference field handle.
     * @param   RecordQuerySpecification  $specification           Bounded choice filter/search/sort and cursor.
     * @param   ?string                   $organizationIdentifier  Authenticated organization assertion.
     *
     * @throws  InvalidArgumentException  When an identifier is malformed or the selector asks for lifecycle
     *          rows, includes, aggregates, or more than fifty choices.
     *
     * @since   2.0.0
     */
    public function __construct(
        public ExecutionContext $context,
        public string $definitionIdentifier,
        public string $sourceRecordId,
        public string $relationship,
        public string $field,
        public RecordQuerySpecification $specification,
        public ?string $organizationIdentifier = null,
    ) {
        RecordRequestGuard::definition($definitionIdentifier);
        RecordRequestGuard::record($sourceRecordId);
        RecordRequestGuard::handle($relationship, 'relationship');
        RecordRequestGuard::handle($field, 'field');
        RecordRequestGuard::organization($organizationIdentifier);
        if (
            $specification->pageSize > 50
            || $specification->includeArchived
            || $specification->includeDeleted
            || $specification->projection->includes !== []
            || $specification->projection->aggregates !== []
        ) {
            throw new InvalidArgumentException('An owned-line field selector query exceeds its safe bounds.');
        }
    }
}
