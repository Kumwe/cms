<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation\Job;

use Kumwe\App\Application\Automation\JobHandler;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Identity\Application\Administration\AdministratorSessionStore;

/**
 * Scheduled job that removes the administrator sessions of one site once they have expired.
 *
 * An administrator session stops being usable when its expiry passes, but nothing deletes the row at
 * that moment, so without a recurring purge the session table keeps every session the installation
 * has ever issued. The job type is site-local, so the worker resolves the site that owns the queued
 * job and runs this handler under a system context for that site alone.
 *
 * @since  2.0.0
 */
final readonly class PurgeAdministratorSessionsHandler implements JobHandler
{
    /**
     * Bind the handler to the session store whose expired rows it removes.
     *
     * @param  AdministratorSessionStore  $sessions  Store that owns both the expiry rule and the deletion.
     *
     * @since  2.0.0
     */
    public function __construct(private AdministratorSessionStore $sessions)
    {
    }

    /**
     * Report the job type a schedule or queued job names this handler by.
     *
     * @return  string  The constant `system.sessions.purge`.
     *
     * @since   2.0.0
     */
    public function type(): string
    {
        return 'system.sessions.purge';
    }

    /**
     * Purge the expired administrator sessions of the site the job runs for.
     *
     * The work is entirely delegated: the store supplies the cutoff from its own clock and limits the
     * deletion to the context's site. How many rows went is discarded, so the job reports success on
     * an empty table exactly as it does after a large sweep.
     *
     * @param   array<string, mixed>  $payload  Scheduled payload; this handler reads no key from it.
     * @param   ExecutionContext      $context  System context naming the site whose sessions are purged.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the job context may not
     *          manage automation for that site.
     *
     * @since   2.0.0
     */
    public function handle(array $payload, ExecutionContext $context): void
    {
        $this->sessions->purgeExpired($context);
    }
}
