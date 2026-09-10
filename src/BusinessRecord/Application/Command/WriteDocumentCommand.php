<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application\Command;

use InvalidArgumentException;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Application\Automation\IdempotencyKey;
use Kumwe\App\BusinessRecord\Application\RecordRequestGuard;
use Kumwe\App\BusinessRecord\Domain\ClientAssertedInstant;

/**
 * Request to write one whole document — a header and the owned lines belonging to it — as a single thing.
 *
 * This is the vertical-neutral primitive every document-shaped business object is built on: an invoice, a
 * purchase order, an attendance batch, a job card, a pay run. Core owns the write and none of the rules;
 * what makes something an invoice is an extension's definition, not a branch in here.
 *
 * The line list is declarative rather than a set of instructions. It is the collection as the caller wants
 * it to end up: a line naming an identity the document already holds is amended, a line naming none is
 * added, a stored line the list does not name is removed, and every line's position is its index in the
 * list. That is what makes ordering and identity properties of the model instead of conventions — no two
 * lines can occupy one slot, and no caller can leave a gap.
 *
 * `$expectedVersion` is the aggregate's version, not a line's. Two callers amending the same document
 * therefore contend for one value: the second one to commit is refused as stale rather than interleaved
 * into a document neither of them wrote.
 *
 * **Late and out-of-order arrival is part of this contract, not an exception to it.** A caller that
 * captured the document while disconnected submits it whenever it reconnects, optionally asserting when
 * the work happened through `$capturedAt`. That assertion is recorded beside the server's own instant
 * and is never substituted for it: the document is validated, numbered, sequenced and audited at the
 * moment it arrives, so arrival order remains the order of record and a client's clock decides nothing.
 *
 * @since  2.0.0
 */
final readonly class WriteDocumentCommand
{
    /**
     * Largest collection one command may carry, matching the thousand-line document the platform sizes for.
     *
     * @var    int
     * @since  2.0.0
     */
    public const MAXIMUM_LINES = 1000;

    /**
     * Header values submitted for the document, keyed by field handle.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    public array $values;

    /**
     * The document's whole line collection, in the order it is to be stored.
     *
     * @var    list<DocumentLineInput>
     * @since  2.0.0
     */
    public array $lines;

    /**
     * Validate a document write and freeze it as one command.
     *
     * @param   ExecutionContext         $context                 Actor, site and request the write runs
     *          under.
     * @param   string                   $definitionIdentifier    UUID or handle of the header's entity
     *          type.
     * @param   string                   $relationship            Handle of the owned-line collection this
     *          command writes, as the header definition declares it.
     * @param   array<string, mixed>     $values                  Header values keyed by field handle; a
     *          create takes the whole header, an amend takes the patch.
     * @param   list<DocumentLineInput>  $lines                   The document's whole line collection, in
     *          position order; an empty list is a legitimate document with no lines.
     * @param   IdempotencyKey           $idempotencyKey          Token a retry repeats to replay this
     *          document's outcome instead of writing it a second time.
     * @param   DocumentWriteIntent      $intent                  Whether this creates the document or
     *          amends one that exists.
     * @param   ?int                     $expectedVersion         Aggregate version the caller read;
     *          required to amend, and refused on a create, which always starts at version one.
     * @param   ?string                  $recordId                Identity to give the header, or the
     *          identity of the document being amended; required to amend.
     * @param   ?string                  $organizationIdentifier  Organization the document is scoped to,
     *          or null for a type that is not organization-scoped.
     * @param   ?ClientAssertedInstant   $capturedAt              When the caller says the work happened,
     *          recorded beside the server's instant and never used for ordering, expiry, period
     *          assignment or numbering; null when the caller asserts nothing.
     *
     * @throws  InvalidArgumentException  When the definition identifier, relationship handle, record
     *          identity or organization identifier fails its format rule, when the intent and the expected
     *          version or record identity contradict each other, when the collection exceeds
     *          `MAXIMUM_LINES`, when two lines claim one identity, or when a submitted value is one the
     *          record layer refuses to store.
     *
     * @since   2.0.0
     */
    public function __construct(
        public ExecutionContext $context,
        public string $definitionIdentifier,
        public string $relationship,
        array $values,
        array $lines,
        public IdempotencyKey $idempotencyKey,
        public DocumentWriteIntent $intent = DocumentWriteIntent::Create,
        public ?int $expectedVersion = null,
        public ?string $recordId = null,
        public ?string $organizationIdentifier = null,
        public ?ClientAssertedInstant $capturedAt = null,
    ) {
        RecordRequestGuard::definition($definitionIdentifier);
        RecordRequestGuard::handle($relationship, 'relationship');
        RecordRequestGuard::organization($organizationIdentifier);
        RecordRequestGuard::values($values, true);
        if ($recordId !== null) {
            RecordRequestGuard::record($recordId);
        }
        if ($intent === DocumentWriteIntent::Amend) {
            if ($expectedVersion === null || $recordId === null) {
                throw new InvalidArgumentException(
                    'Amending a document requires the identity and the aggregate version the caller read.',
                );
            }
            RecordRequestGuard::expectedVersion($expectedVersion);
        } elseif ($expectedVersion !== null) {
            throw new InvalidArgumentException('Creating a document cannot expect an earlier aggregate version.');
        }
        if (count($lines) > self::MAXIMUM_LINES) {
            throw new InvalidArgumentException('A document carries more lines than one command may write.');
        }
        $seen = [];
        foreach ($lines as $line) {
            if (!$line instanceof DocumentLineInput) {
                throw new InvalidArgumentException('A document line list accepts only submitted line inputs.');
            }
            if ($line->recordId === null) {
                continue;
            }
            if (isset($seen[$line->recordId])) {
                throw new InvalidArgumentException('A document names one line identity more than once.');
            }
            $seen[$line->recordId] = true;
        }
        $this->values = $values;
        $this->lines = array_values($lines);
    }
}
