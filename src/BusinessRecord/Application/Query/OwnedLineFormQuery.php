<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application\Query;

use Kumwe\App\BusinessRecord\Application\RecordRequestGuard;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Request for the policy-authorized create fields of one owned-line relationship.
 *
 * @since  2.0.0
 */
final readonly class OwnedLineFormQuery
{
    /**
     * Bind the form request to an exact existing source record and authenticated organization assertion.
     *
     * @param  ExecutionContext  $context                 Authenticated actor and scope.
     * @param  string            $definitionIdentifier    Source definition UUID or handle.
     * @param  string            $sourceRecordId          Existing source public identity.
     * @param  string            $relationship            Owned-line relationship handle.
     * @param  ?string           $organizationIdentifier  Authenticated organization assertion.
     *
     * @since  2.0.0
     */
    public function __construct(
        public ExecutionContext $context,
        public string $definitionIdentifier,
        public string $sourceRecordId,
        public string $relationship,
        public ?string $organizationIdentifier = null,
    ) {
        RecordRequestGuard::definition($definitionIdentifier);
        RecordRequestGuard::record($sourceRecordId);
        RecordRequestGuard::handle($relationship, 'relationship');
        RecordRequestGuard::organization($organizationIdentifier);
    }
}
