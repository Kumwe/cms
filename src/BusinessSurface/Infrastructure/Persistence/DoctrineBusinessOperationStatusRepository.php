<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\BusinessRecord\Application\BusinessRecordIdempotencyRepository;
use Kumwe\App\BusinessRecord\Domain\BusinessRecordIdempotency;
use Kumwe\App\BusinessSurface\Application\BusinessOperationStatusRepository;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Finds a single actor-bound operation scope digest, then delegates integrity proof to the canonical ledger.
 *
 * @since  2.0.0
 */
final readonly class DoctrineBusinessOperationStatusRepository implements BusinessOperationStatusRepository
{
    /**
     * Configure scoped lookup over the authoritative business command ledger.
     *
     * @param  Connection                           $database  DBAL connection.
     * @param  TableNames                           $tables    Prefixed table resolver.
     * @param  BusinessRecordIdempotencyRepository  $entries   Canonical checksum-verifying ledger reader.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private BusinessRecordIdempotencyRepository $entries,
    ) {
    }

    /**
     * Find exactly one unexpired operation without disclosing an ambiguous or differently scoped match.
     *
     * @param   ExecutionContext   $context      Authenticated actor and scope.
     * @param   string             $operationId  Caller operation identity.
     * @param   DateTimeImmutable  $now          Expiry boundary.
     *
     * @return  BusinessRecordIdempotency|null  Verified entry, or null for no unique exact match.
     *
     * @since   2.0.0
     */
    public function find(
        ExecutionContext $context,
        string $operationId,
        DateTimeImmutable $now,
    ): ?BusinessRecordIdempotency {
        $organization = $context->organization()?->identifier();
        $query = $this->database->createQueryBuilder()
            ->select('scope_digest')
            ->from($this->tables->raw('business_command_idempotency'))
            ->where('site_identifier = :site')
            ->andWhere('actor_id = :actor')
            ->andWhere('operation_id = :operation_id')
            ->andWhere('expires_at > :now')
            ->setParameter('site', $context->site()->identifier())
            ->setParameter('actor', $context->actorId())
            ->setParameter('operation_id', $operationId)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->orderBy('created_at', 'DESC')
            ->setMaxResults(2);
        if ($organization === null) {
            $query->andWhere('organization_identifier IS NULL');
        } else {
            $query->andWhere('organization_identifier = :organization')
                ->setParameter('organization', $organization);
        }
        $digests = $query->executeQuery()->fetchFirstColumn();
        if (count($digests) !== 1 || !is_string($digests[0])) {
            return null;
        }

        return $this->entries->find($digests[0]);
    }
}
