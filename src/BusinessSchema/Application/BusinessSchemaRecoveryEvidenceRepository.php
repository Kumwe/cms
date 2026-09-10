<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Application;

use Kumwe\App\BusinessSchema\Domain\SchemaRecoveryEvidence;
use Kumwe\Context\Value\SiteContext;

/**
 * Store of the tested-restore evidence a high-risk schema plan must be approved and executed against.
 *
 * Before Kumwe applies a plan that rebuilds or destroys data it demands proof that a restore of exactly
 * the source schema was performed and verified on this engine, this release and this site.
 * `BusinessSchemaService` reads that proof back through this port while approving the plan, and
 * `BusinessSchemaExecutor` reads it again as the run begins, so evidence that went stale between the
 * two decisions still stops the run. Records are site-scoped and, once written, immutable: an
 * implementation must refuse a second write under an existing identifier whose content differs,
 * because an approval already cites the identifier and would otherwise no longer mean what it said.
 *
 * @since  2.0.0
 */
interface BusinessSchemaRecoveryEvidenceRepository
{
    /**
     * Look up one recovery-evidence record within a site.
     *
     * @param   SiteContext  $site        Site the evidence must belong to; evidence never crosses sites.
     * @param   string       $evidenceId  UUID the verified drill was recorded under.
     *
     * @return  ?SchemaRecoveryEvidence  The record, or null when this site holds none under that identifier.
     *
     * @since   2.0.0
     */
    public function find(SiteContext $site, string $evidenceId): ?SchemaRecoveryEvidence;

    /**
     * Persist a recovery-evidence record so a later approval can cite it.
     *
     * Storage is append-only. Re-saving byte-identical evidence is accepted, which keeps a retried
     * submission harmless, but differing evidence under an identifier already in use must be rejected
     * rather than overwritten.
     *
     * @param   SchemaRecoveryEvidence  $evidence  Verified drill result, already self-validated on construction.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function save(SchemaRecoveryEvidence $evidence): void;
}
