<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application\Query;

use InvalidArgumentException;
use Kumwe\App\BusinessRecord\Application\RecordRequestGuard;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Request to read a single record by its public identity, carrying the caller's context and projection.
 *
 * `BusinessRecordService::read()` accepts this instead of loose arguments so every identifier and every
 * projection handle is checked at construction, before authorization, fencing or schema resolution runs.
 * The two lifecycle switches are what make this more than a tuple: a plain read sees only live records,
 * and an archived or soft-deleted one is reachable only when the caller opts in explicitly, so no
 * routine read leaks a record its owner considers gone.
 *
 * @since  2.0.0
 */
final readonly class ReadRecordQuery
{
    /**
     * Field handles the returned view is narrowed to, de-duplicated and re-indexed.
     *
     * Empty means no narrowing: the view then carries every field the definition marks readable.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $projection;

    /**
     * Relationship handles to hydrate beside the record, de-duplicated and re-indexed.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $includes;

    /**
     * Assemble a read request and validate its identifiers and projection handles.
     *
     * @param   ExecutionContext  $context                 Actor and site the read runs as.
     * @param   string            $definitionIdentifier    Definition UUID or handle naming the record
     *          type to read.
     * @param   string            $recordId                Public identity of the record wanted, in the
     *          form the definition's identity field uses.
     * @param   ?string           $organizationIdentifier  Organization to scope the read to; required for
     *          an organization-scoped definition and null for any other.
     * @param   list<string>      $projection              Field handles to return; an empty list asks for
     *          every readable field.
     * @param   bool              $includeArchived         True to allow reading a record that has been
     *          archived.
     * @param   bool              $includeDeleted          True to allow reading a record that has been
     *          soft-deleted.
     * @param   list<string>      $includes                Relationship handles to hydrate, capped at four.
     *
     * @throws  InvalidArgumentException  When an identifier or projection handle is malformed, or the
     *          projection names more than 64 fields.
     *
     * @since   2.0.0
     */
    public function __construct(
        public ExecutionContext $context,
        public string $definitionIdentifier,
        public string $recordId,
        public ?string $organizationIdentifier = null,
        array $projection = [],
        public bool $includeArchived = false,
        public bool $includeDeleted = false,
        array $includes = [],
    ) {
        RecordRequestGuard::definition($definitionIdentifier);
        RecordRequestGuard::record($recordId);
        RecordRequestGuard::organization($organizationIdentifier);
        if (count($projection) > 64) {
            throw new InvalidArgumentException('A business-record read projection exceeds 64 fields.');
        }
        foreach ($projection as $field) {
            RecordRequestGuard::handle($field, 'projection');
        }
        if (count($includes) > 4) {
            throw new InvalidArgumentException('A business-record read projection exceeds 4 includes.');
        }
        foreach ($includes as $relationship) {
            RecordRequestGuard::handle($relationship, 'include');
        }
        $this->projection = array_values(array_unique($projection));
        $this->includes = array_values(array_unique($includes));
    }
}
