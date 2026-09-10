<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

use InvalidArgumentException;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Port that moves stored record secrets onto the active key, one bounded pass at a time.
 *
 * A key ring makes rotation survivable; this is what makes it finish. Envelopes written under a retired
 * key stay readable indefinitely, but the retired key can only be destroyed once nothing references it,
 * and nothing re-seals stored rows on its own. An implementation reads the rows that still name another
 * key, opens each with the key it names, seals it again under the active key, and writes it back.
 *
 * Four properties are part of the contract rather than of any one implementation:
 *
 * - *Bounded.* A pass never runs longer than the row budget it was given, so it can be scheduled beside
 *   live traffic instead of during a maintenance window.
 * - *Resumable.* All the progress state is the data itself — a row that carries the active key is a row
 *   that is done — so a pass killed mid-run leaves committed work committed and the next pass picks up
 *   exactly what is left. There is no cursor to lose and no checkpoint to corrupt.
 * - *Idempotent.* Running a pass against an installation that is already rotated reads nothing and
 *   writes nothing.
 * - *Concurrency-safe.* An ordinary write that replaces a secret between the read and the write wins;
 *   the pass detects it and moves on rather than overwriting the newer value.
 *
 * And one prohibition: a plaintext secret exists only between the two calls inside the implementation. It
 * is never returned, logged, audited, or included in a failure message — re-encryption may not become a
 * disclosure path, which is the whole reason the write-only record property survives it.
 *
 * @since  2.0.0
 */
interface RecordSecretRotation
{
    /**
     * Re-seal up to `$batchSize` stored secrets under the active key.
     *
     * Call it repeatedly until the report says `complete`. Each call is authorized on its own, so a
     * rotation campaign leaves one audit entry per pass rather than one for the whole campaign.
     *
     * @param   ExecutionContext  $context    Actor the pass is authorized and audited under; the pass
     *          covers the installations of that actor's site only.
     * @param   int               $batchSize  Most rows this pass may read, from 1 to 1000.
     *
     * @return  RecordSecretRotationReport  Counts, skipped installations, and whether anything is left.
     *
     * @throws  InvalidArgumentException  When the batch size falls outside its range.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not re-key
     *          business-record secrets on this site.
     * @throws  \Kumwe\App\BusinessRecord\Domain\SecretKeyUnavailable  When a stored envelope names a key
     *          this deployment does not hold; the pass stops rather than skipping the row, because a row
     *          it cannot open is a row it would otherwise spin on forever.
     * @throws  \RuntimeException  When a stored envelope fails authentication, which is a data-integrity
     *          finding rather than a rotation failure and must not be passed over silently.
     *
     * @since   2.0.0
     */
    public function rotate(ExecutionContext $context, int $batchSize): RecordSecretRotationReport;
}
