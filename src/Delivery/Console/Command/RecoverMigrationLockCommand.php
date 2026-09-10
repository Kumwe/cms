<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use Kumwe\App\Application\Authorization\SystemPrincipal;
use Kumwe\App\Application\Operations\MigrationLockRecoveryService;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\Context\Value\SiteContext;

/**
 * Break-glass console command that removes one expired pre-2.0 migration lock owner.
 *
 * Migrating holds a database-session advisory lock alongside the compatibility row that older builds
 * relied on by themselves. Upgrading from such a build leaves that row behind when an older process
 * crashed, and nothing clears it on its own, so `database:migrate` refuses to run. Removing it by
 * hand is the one operation that can let two deployments migrate the same schema at once, which is
 * why this command is only an argument parser: it insists on the exact `owner_token` the stuck row
 * carries and on a separate assertion that every older binary is stopped, then hands both to
 * `MigrationLockRecoveryService`, which owns the authorization and expiry checks.
 *
 * @since  2.0.0
 */
final readonly class RecoverMigrationLockCommand implements Command
{
    /**
     * Wire the guarded recovery service and the authority its removal is checked against.
     *
     * @param  MigrationLockRecoveryService  $recovery  Verifies the preconditions and clears the stuck row.
     * @param  SystemPrincipal               $system    Mints the migration context the removal authorizes with.
     *
     * @since  2.0.0
     */
    public function __construct(
        private MigrationLockRecoveryService $recovery,
        private SystemPrincipal $system,
    ) {
    }

    /**
     * Name this command is invoked under on the console.
     *
     * @return  string  Always `database:recover-lock`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'database:recover-lock';
    }

    /**
     * Summary line `bin/kumwe list` prints beside the command name.
     *
     * @return  string  One-sentence statement of what the command removes and what it assumes.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.database_recover_lock.description';
    }

    /**
     * Validate the recovery arguments, then compare-and-delete the owner row they name.
     *
     * Both arguments are mandatory and an unrecognised one is refused rather than ignored, because the
     * token is what narrows the deletion to a single row an operator has actually read out of
     * `migration_locks`. Whether that token is well formed, and whether the row it names has really
     * expired, is decided by the recovery service, so a rejection there arrives as an exception rather
     * than the usage status. Run `database:migrate` immediately once this succeeds.
     *
     * @param   list<string>  $arguments  `--expected-owner=<token>` and `--confirm-legacy-quiesced`, in
     *          either order.
     * @param   Output        $output     Sink for the usage or unknown-argument error, and the success line.
     *
     * @return  int  `0` once the owner row is gone, `64` when the arguments are missing or unrecognised.
     *
     * @throws  \RuntimeException  When the token is not 64 lowercase hex digits, or the stored row no
     *          longer matches it or has not expired.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        $expectedOwner = null;
        $confirmed = false;
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--expected-owner=')) {
                $expectedOwner = substr($argument, strlen('--expected-owner='));
            } elseif ($argument === '--confirm-legacy-quiesced') {
                $confirmed = true;
            } else {
                $output->failure('core.console.database_recover_lock.unknown_database_recover_lock_argument', [
                    'argument' => $argument,
                ]);

                return 64;
            }
        }
        if (!is_string($expectedOwner) || $expectedOwner === '' || !$confirmed) {
            $output->failure('core.console.database_recover_lock.usage');

            return 64;
        }

        $this->recovery->recover(
            $this->system->context(SiteContext::default(), 'migration-lock-recovery-' . bin2hex(random_bytes(16))),
            $expectedOwner,
            true,
        );
        $output->message('core.console.database_recover_lock.expired_legacy_migration_owner_removed_run');

        return 0;
    }
}
