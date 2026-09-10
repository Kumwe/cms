<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application\Query;

use InvalidArgumentException;
use Kumwe\App\BusinessRecord\Application\RecordRequestGuard;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordQuerySpecification;

/**
 * Bounded request for policy-safe relationship or entity-reference choices.
 *
 * The source handle is significant: the record access plan carries an independently filtered target
 * plan for every relationship and entity-reference field. A caller may therefore browse a target only
 * through the exact source handle it is about to populate and the exact create, update, or relate operation.
 * Choice pages deliberately exclude lifecycle, includes and aggregates and cap the page at fifty rows before
 * the repository is reached.
 *
 * @since  2.0.0
 */
final readonly class BrowseRelatedRecordsQuery
{
    /**
     * Validate one selector request.
     *
     * @param   ExecutionContext          $context                 Authenticated actor and tenant context.
     * @param   string                    $definitionIdentifier    Source definition UUID or handle.
     * @param   string                    $relatedHandle           Relationship or reference-field handle.
     * @param   string                    $operation               Create, update, or relate policy operation.
     * @param   ?string                   $sourceRecordId          Source identity for update/relate; null on create.
     * @param   RecordQuerySpecification  $specification           Bounded target filter, search and cursor.
     * @param   ?string                   $organizationIdentifier  Authenticated organization assertion.
     *
     * @throws  InvalidArgumentException  When identifiers are malformed or the selector query requests
     *          lifecycle rows, includes, aggregates, or more than fifty choices.
     *
     * @since   2.0.0
     */
    public function __construct(
        public ExecutionContext $context,
        public string $definitionIdentifier,
        public string $relatedHandle,
        public string $operation,
        public ?string $sourceRecordId,
        public RecordQuerySpecification $specification,
        public ?string $organizationIdentifier = null,
    ) {
        RecordRequestGuard::definition($definitionIdentifier);
        RecordRequestGuard::handle($relatedHandle, 'related');
        RecordRequestGuard::organization($organizationIdentifier);
        if ($sourceRecordId !== null) {
            RecordRequestGuard::record($sourceRecordId);
        }
        if (
            !in_array($operation, [
                'business.record.create',
                'business.record.update',
                'business.record.relate',
            ], true)
            || (($operation === 'business.record.create') !== ($sourceRecordId === null))
            || $specification->pageSize > 50
            || $specification->includeArchived
            || $specification->includeDeleted
            || $specification->projection->includes !== []
            || $specification->projection->aggregates !== []
        ) {
            throw new InvalidArgumentException('A related-record selector query exceeds its safe bounds.');
        }
    }
}
