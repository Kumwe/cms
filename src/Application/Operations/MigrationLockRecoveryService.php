<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Operations;

use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use RuntimeException;

/**
 * Guard that stands between an operator and the break-glass removal of a stuck migration lock.
 *
 * Clearing a migration lock by hand is the one operation that can let two deployments migrate the same
 * schema at once, so the port is only reached after three separate things hold: the caller holds
 * `system.migrate` over the schema, the caller has explicitly asserted that every pre-2.0 process is
 * stopped, and the caller can name the exact owner token the stuck row carries. Keeping all three here
 * rather than in the adapter is what lets `database:recover-lock` stay a thin argument parser, and what
 * makes the refusal auditable through the ordinary authorization path.
 *
 * @since  2.0.0
 */
final readonly class MigrationLockRecoveryService
{
    /**
     * Wire the guard to the lock store it clears and the gateway that authorizes clearing it.
     *
     * @param  ExpiredMigrationLockRecovery  $recovery       Store holding the legacy compatibility row.
     * @param  AuthorizationGateway          $authorization  Decides whether the caller may migrate.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ExpiredMigrationLockRecovery $recovery,
        private AuthorizationGateway $authorization,
    ) {
    }

    /**
     * Clear the expired legacy lock row, once every precondition for doing so safely is satisfied.
     *
     * All three checks run before the port is touched, so a refused attempt leaves the row exactly as
     * it was. The quiescence flag is a human assertion nothing here can verify: passing it while an
     * older binary is still running is precisely what would allow two concurrent migrations, which is
     * why the console command spells it as its own explicit flag rather than defaulting it.
     *
     * @param   ExecutionContext  $context                     Caller the migrate capability is checked against.
     * @param   string            $expectedOwnerToken          Owner token the stuck row must still carry.
     * @param   bool              $legacyProcessesAreQuiesced  Assertion that every pre-2.0 process is stopped.
     *
     * @return  void
     *
     * @throws  RuntimeException  When quiescence has not been asserted, when the token is not 64
     *          lowercase hex digits, or when the row no longer matches the token or has not expired.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the caller may not
     *          migrate the schema.
     *
     * @since   2.0.0
     */
    public function recover(
        ExecutionContext $context,
        string $expectedOwnerToken,
        bool $legacyProcessesAreQuiesced,
    ): void {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('system.migrate'),
            AuthorizationResource::collection('database_schema'),
        );
        if (!$legacyProcessesAreQuiesced) {
            throw new RuntimeException('Migration-lock recovery requires a confirmed quiesced legacy deployment.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedOwnerToken) !== 1) {
            throw new RuntimeException('The expected legacy migration owner token is invalid.');
        }

        $this->recovery->recoverExpiredLegacyOwner($expectedOwnerToken);
    }
}
