<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application\Query;

use InvalidArgumentException;
use Kumwe\App\BusinessRecord\Application\RecordRequestGuard;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Request for one bounded window of a business record's revision log.
 *
 * `BusinessRecordService::history()` accepts nothing else, so a malformed identifier or an unbounded
 * window is refused here, at construction, before a fence is taken and before a revision row is read.
 * The window walks backwards: revisions come back newest first, and a caller pages on by re-issuing
 * the query with `$beforeVersion` set from the oldest revision it already holds.
 *
 * @since  2.0.0
 */
final readonly class RecordHistoryQuery
{
    /**
     * Capture and validate one revision-window request.
     *
     * @param   ExecutionContext  $context                 Actor, site and request this history read runs under.
     * @param   string            $definitionIdentifier    UUID or handle of the definition the record belongs to.
     * @param   string            $recordId                Caller-facing identity of the record whose revisions
     *          are wanted.
     * @param   ?string           $organizationIdentifier  Organization the record is scoped to, or null when the
     *          definition carries no organization scope.
     * @param   int               $limit                   Most revisions to return, from 1 to 200.
     * @param   ?int              $beforeVersion           Return only revisions below this record version, or
     *          null to start at the newest.
     *
     * @throws  InvalidArgumentException  When an identifier is malformed, the limit falls outside 1 to 200, or
     *          `$beforeVersion` is not positive.
     *
     * @since   2.0.0
     */
    public function __construct(
        public ExecutionContext $context,
        public string $definitionIdentifier,
        public string $recordId,
        public ?string $organizationIdentifier = null,
        public int $limit = 100,
        public ?int $beforeVersion = null,
    ) {
        RecordRequestGuard::definition($definitionIdentifier);
        RecordRequestGuard::record($recordId);
        RecordRequestGuard::organization($organizationIdentifier);
        if ($limit < 1 || $limit > 200 || ($beforeVersion !== null && $beforeVersion < 1)) {
            throw new InvalidArgumentException('A record-history window is outside its safe bound.');
        }
    }
}
