<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

use Kumwe\Context\Contract\SystemActor;
use Kumwe\Context\Value\ExecutionContext;

/**
 * The closed set of unattended actors Kumwe will issue an execution context to.
 *
 * Work that runs with no operator present still has to name who is acting. A `SystemPrincipal` binds
 * one of these cases for the life of the process, `ExecutionContext::issueSystem()` stamps it on the
 * context through the package's `SystemActor` port this enum implements, and owner-bound resource-policy
 * definitions explicitly name the cases permitted to use each action/resource binding — unattended
 * authority is therefore registered as typed core data, never inferred from a stored grant. Keeping the
 * set small and each binding narrow is what stops one compromised background task from acting with the
 * authority of another; the backing value is also the actor identifier written into audit records, which
 * is why it is a stable `system:` token.
 *
 * @since  2.0.0
 */
enum SystemIdentity: string implements SystemActor
{
    /**
     * Creates the first administrator account on an installation that has no operator yet.
     *
     * Held only by the console command that bootstraps an account, and granted nothing beyond
     * `administrator.bootstrap`, so it cannot be reused to change identity records afterwards.
     *
     * @since  2.0.0
     */
    case Bootstrap = 'system:bootstrap';

    /**
     * Repairs the credentials of an installation that nobody can authenticate or step up as any more.
     *
     * Deliberately separate from `Bootstrap`, which is granted `administrator.bootstrap` alone so that
     * creating the first account can never become a way to edit identity records afterwards. The
     * break-glass repair genuinely does need `users.manage`, so it is given its own identity rather
     * than widening that one — which also means the audit trail names `system:credential-recovery` as
     * the actor, and an operator reviewing whether host authority was used to reach an account has one
     * exact string to look for.
     *
     * Held only by the console command that performs the repair. Reaching that console is the whole of
     * the authorization, so the identity is worth no more than the host it runs on is protected.
     *
     * @since  2.0.0
     */
    case CredentialRecovery = 'system:credential-recovery';

    /**
     * Console execution that carries no authority of its own.
     *
     * No resource policy names it, so a context issued under this identity is refused every action;
     * console work that must change something is given a purpose-built identity instead.
     *
     * @since  2.0.0
     */
    case CommandLine = 'system:cli';

    /**
     * Recompiles the extension runtime map, installation-wide rather than for one site.
     *
     * Granted `extensions.manage` and allowed to act on installation-global resources, which is what
     * lets the queued runtime rebuild touch state no single site owns.
     *
     * @since  2.0.0
     */
    case ExtensionMaterializer = 'system:extension-materializer';

    /**
     * Runs the housekeeping jobs that keep installation-wide tables from growing without bound.
     *
     * Granted `automation.manage` with installation-global reach, and used for the idempotency purges
     * that would otherwise have no site to run under.
     *
     * @since  2.0.0
     */
    case InstallationMaintenance = 'system:installation-maintenance';

    /**
     * Applies schema migrations and recovers an abandoned migration lock.
     *
     * Its single capability is `system.migrate`, so a migration process cannot reach content, identity
     * or automation operations even though it runs with the database open in front of it.
     *
     * @since  2.0.0
     */
    case Migration = 'system:migration';

    /**
     * Installs the explicitly selected, versioned demonstration profiles after schema migration.
     *
     * This identity is intentionally separate from `Migration`: profile installation changes ordinary
     * site content and selected business records, while schema migration owns only the platform schema.
     * Its resource-policy bindings are limited to the canonical content, definition, business-schema,
     * and record operations needed to apply an idempotent manifest; it receives no user-management,
     * extension-management, destructive-schema, or secret-management authority.
     *
     * @since  2.0.0
     */
    case ProfileInstaller = 'system:profile-installer';

    /**
     * Turns due schedules into queued jobs.
     *
     * Granted `system.scheduler.dispatch` alone: it decides that an occurrence is due and enqueues it,
     * while the work itself is later performed under `Worker`.
     *
     * @since  2.0.0
     */
    case Scheduler = 'system:scheduler';

    /**
     * Executes queued jobs on behalf of whoever enqueued them.
     *
     * Holds the widest system capability list, because a job handler may move content through its
     * lifecycle. Its reach is still site-local: an installation-global job is re-issued under the
     * dedicated identity `JobExecutionScope` declares for that job type before the handler runs.
     *
     * @since  2.0.0
     */
    case Worker = 'system:worker';

    /**
     * Name this actor in audit records and fingerprints, as the package port requires.
     *
     * @return  string  The backing `system:` token, unchanged from the value audit records already carry.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->value;
    }
}
