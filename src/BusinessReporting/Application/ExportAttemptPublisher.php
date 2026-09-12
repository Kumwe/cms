<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Application;

use DateTimeImmutable;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\BusinessReporting\Domain\ExportArtifact;
use Kumwe\App\BusinessReporting\Domain\ExportArtifactStatus;
use Throwable;

/**
 * Fences one export generation attempt's private bytes against concurrent publishers and rollback.
 *
 * @since  2.0.0
 */
final readonly class ExportAttemptPublisher
{
    /**
     * Bind publication to the append-only metadata head and private immutable object store.
     *
     * @param  ExportArtifactRepository  $artifacts     Durable compare-and-set metadata ledger.
     * @param  ExportArtifactStorage     $storage       Attempt-fenced immutable byte store.
     * @param  TransactionManager        $transactions  Completion metadata and audit transaction owner.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ExportArtifactRepository $artifacts,
        private ExportArtifactStorage $storage,
        private TransactionManager $transactions,
    ) {
    }

    /**
     * Store one attempt and publish it only if its running metadata version still owns the head.
     *
     * A concurrent winner is returned idempotently. A losing attempt deletes only its own tokenized object;
     * a metadata read failure preserves the object because publication cannot then be disproved safely.
     *
     * @param   ExportArtifact                  $artifact     Running artifact version being completed.
     * @param   iterable<string>                $chunks       Deterministic CSV chunks for this attempt.
     * @param   DateTimeImmutable               $completedAt  Trusted completion instant.
     * @param   int                             $rowCount     Number of exported rows.
     * @param   string                          $queryDigest  Policy-filtered query digest.
     * @param   callable(ExportArtifact): void  $audit        Completion audit write in the same transaction.
     *
     * @return  ExportArtifact  This attempt's completion or the concurrently published completion.
     *
     * @throws  Throwable  When storage, metadata, audit, or cleanup fails without a completed winner.
     *
     * @since   2.0.0
     */
    public function publish(
        ExportArtifact $artifact,
        iterable $chunks,
        DateTimeImmutable $completedAt,
        int $rowCount,
        string $queryDigest,
        callable $audit,
    ): ExportArtifact {
        $stored = $this->storage->store($artifact->id, $chunks);
        try {
            $completed = $artifact->complete(
                $completedAt,
                $stored->key,
                $stored->size,
                $stored->checksum,
                $rowCount,
                $queryDigest,
            );
            $this->transactions->transactional(function () use ($artifact, $completed, $stored, $audit): void {
                $this->transactions->afterRollback(function () use ($stored): void {
                    $this->storage->delete($stored->key);
                });
                $this->artifacts->save($completed, $artifact->version);
                $audit($completed);
            });

            return $completed;
        } catch (Throwable $exception) {
            $winner = $this->reconcile($artifact->id, $stored);
            if ($winner !== null) {
                return $winner;
            }

            throw $exception;
        }
    }

    /**
     * Preserve a published object, or remove only this attempt's proven-unpublished bytes.
     *
     * @param   string                $artifactId  Stable metadata identity shared by racing workers.
     * @param   StoredExportArtifact  $stored      Bytes owned exclusively by the failing attempt.
     *
     * @return  ?ExportArtifact  Concurrent completed winner, or null when no winner can be established.
     *
     * @since   2.0.0
     */
    private function reconcile(string $artifactId, StoredExportArtifact $stored): ?ExportArtifact
    {
        try {
            $current = $this->artifacts->find($artifactId);
        } catch (Throwable) {
            return null;
        }
        if ($current !== null && $current->status === ExportArtifactStatus::Completed) {
            if ($current->storageKey !== $stored->key) {
                $this->storage->delete($stored->key);
            }

            return $current;
        }
        $this->storage->delete($stored->key);

        return null;
    }
}
