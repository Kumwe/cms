<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application\Command;

use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Application\Automation\IdempotencyKey;
use Kumwe\App\BusinessRecord\Application\RecordRequestGuard;

/**
 * Request to run one definition-declared action against a business record.
 *
 * An action is the definition's own named operation — approve, publish, cancel — and it is the only
 * route to a workflow transition: `BusinessRecordService::action()` resolves the action, checks the
 * capability and precondition the definition attaches to it, and moves the record along the declared
 * transition rather than letting a caller set a state directly. Construction is where a
 * delivery-layer request stops being untrusted text, with `RecordRequestGuard` rejecting a malformed
 * definition identifier, record identity, version, action handle or organization scope. The expected
 * version pins the record the caller read, and the idempotency key makes a retry replay the first
 * outcome instead of transitioning twice.
 *
 * @since  2.0.0
 */
final readonly class ExecuteRecordActionCommand
{
    /**
     * Arguments supplied to the action, keyed by handle.
     *
     * Reserved for a typed action input that `ActionDefinition` cannot yet declare, so
     * `BusinessRecordService::action()` rejects the command outright whenever this is not empty.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    public array $input;

    /**
     * Validate an action request and freeze it as one command.
     *
     * @param   ExecutionContext      $context                 Actor, site and request the action runs under.
     * @param   string                $definitionIdentifier    UUID or handle of the record's entity type.
     * @param   string                $recordId                Public identity of the record to act on.
     * @param   int                   $expectedVersion         Version the caller read; the action is refused
     *          when the stored record has moved past it.
     * @param   string                $action                  Handle of the action as the definition declares
     *          it.
     * @param   IdempotencyKey        $idempotencyKey          Token a retry repeats to replay this outcome
     *          instead of running the action again.
     * @param   array<string, mixed>  $input                   Action arguments keyed by handle; the service
     *          rejects anything but an empty map.
     * @param   ?string               $organizationIdentifier  Organization the record is scoped to, or null for
     *          a type that is not organization-scoped.
     * @param   ?string               $approvalRequestId       Exact approved maker-checker request for a
     *          high-impact action, or null while requesting approval or for ordinary actions.
     *
     * @throws  \InvalidArgumentException  When the definition identifier, record identity, expected version,
     *          action handle or organization identifier fails its format rule, or the input is oversized or
     *          carries an invalid field handle or a value the domain cannot store.
     *
     * @since   2.0.0
     */
    public function __construct(
        public ExecutionContext $context,
        public string $definitionIdentifier,
        public string $recordId,
        public int $expectedVersion,
        public string $action,
        public IdempotencyKey $idempotencyKey,
        array $input = [],
        public ?string $organizationIdentifier = null,
        public ?string $approvalRequestId = null,
    ) {
        RecordRequestGuard::definition($definitionIdentifier);
        RecordRequestGuard::record($recordId);
        RecordRequestGuard::expectedVersion($expectedVersion);
        RecordRequestGuard::handle($action, 'action');
        RecordRequestGuard::organization($organizationIdentifier);
        RecordRequestGuard::values($input, true);
        if ($approvalRequestId !== null && !\Ramsey\Uuid\Uuid::isValid($approvalRequestId)) {
            throw new \InvalidArgumentException('The approval request identity must be a canonical UUID.');
        }
        $this->input = $input;
    }
}
