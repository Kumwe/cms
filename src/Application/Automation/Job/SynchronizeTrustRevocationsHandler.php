<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation\Job;

use Kumwe\App\Application\Automation\JobHandler;
use Kumwe\App\Application\Automation\PermanentFailure;
use Kumwe\App\Extension\Application\Trust\RevocationFeedSynchronizer;
use Kumwe\App\Extension\Application\Trust\RevocationListRefused;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Queued driver that brings the upstream signing-key revocation list into the local trust store.
 *
 * The work is installation-wide rather than site-scoped — a withdrawn signing key stops being trusted
 * everywhere at once — so the type is declared installation-global and runs as the extension
 * materializer, the one internal identity already permitted to manage trust keys. An installation with
 * no feed configured makes every run a cheap no-op, which is why seeding a schedule for it is safe even
 * where nobody has pinned an issuer.
 *
 * The handler is safe to run more than once. Applying a list is keyed on its sequence being strictly
 * newer than the one already recorded, so a re-run after a crash between the revocation and the state
 * write re-applies the same list and finds the keys already revoked, revoking nothing further.
 *
 * Failure classification follows the synchronizer's split rather than inventing a second one. An
 * unreachable origin returns normally: the last applied list stays in force and the condition is
 * recorded and logged, because retry-storming a vendor outage helps nobody and the next scheduled
 * occurrence will try again. A served document that fails verification is a `PermanentFailure`, so the
 * occurrence is buried where an operator will see it instead of being retried against an origin that is
 * either misconfigured or hostile.
 *
 * @since  2.0.0
 */
final readonly class SynchronizeTrustRevocationsHandler implements JobHandler
{
    /**
     * Bind the handler to the synchronizer it drives.
     *
     * @param  RevocationFeedSynchronizer  $revocations  Fetch, verification and application of one list.
     *
     * @since  2.0.0
     */
    public function __construct(private RevocationFeedSynchronizer $revocations)
    {
    }

    /**
     * Report the job type a schedule or queued job names this handler by.
     *
     * @return  string  The constant `extensions.trust.revocations.synchronize`.
     *
     * @since   2.0.0
     */
    public function type(): string
    {
        return 'extensions.trust.revocations.synchronize';
    }

    /**
     * Synchronize the configured revocation feed once.
     *
     * @param   array<string, mixed>  $payload  Unused; the feed is configuration rather than job input,
     *          so that a queued row can never redirect an installation at another origin.
     * @param   ExecutionContext      $context  Installation-global context carrying the extension
     *          materializer identity.
     *
     * @return  void
     *
     * @throws  PermanentFailure  When a served list failed verification, freshness, or the sequence check.
     *
     * @since   2.0.0
     */
    public function handle(array $payload, ExecutionContext $context): void
    {
        try {
            $this->revocations->synchronize($context);
        } catch (RevocationListRefused $failure) {
            throw new PermanentFailure($failure->getMessage(), 0, $failure);
        }
    }
}
