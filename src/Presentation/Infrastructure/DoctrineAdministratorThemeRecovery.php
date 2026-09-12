<?php

declare(strict_types=1);

namespace Kumwe\App\Presentation\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Extension\Runtime\ExtensionRuntimeMapCompiler;
use Kumwe\App\Extension\Application\ExtensionRegistryLease;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Host-only break-glass service. It is wired only to the confirmed recovery CLI command.
 *
 * When a third-party administrator theme is broken, the back office that would let an operator switch it
 * off is exactly what will not render. This service clears the administrator theme activation back to
 * the built-in core theme over the database directly, in one transaction: it drops the activation row's
 * extension reference, disables the displaced template when no other surface still uses it, records the
 * audit event, and stages the recovered runtime map. Every step re-checks the caller's fence against the
 * registry fence row, so a lease that has been superseded by a concurrent registry operation aborts the
 * whole recovery rather than publishing a map built on stale state.
 *
 * @since  2.0.0
 */
final readonly class DoctrineAdministratorThemeRecovery
{
    /**
     * Bind the recovery to the database, the audit trail, and the runtime map it republishes.
     *
     * @param  Connection                   $database            DBAL connection the activation and
     *         extension rows are rewritten on.
     * @param  TableNames                   $tables              Resolver applying the configured prefix
     *         to each table named here.
     * @param  TransactionManager           $transactions        Manager owning the single transaction the
     *         whole recovery runs in.
     * @param  AuditRecorder                $audit               Recorder the recovery event is written
     *         through, inside that transaction.
     * @param  ClockInterface               $clock               Source of the timestamp stamped on every
     *         row the recovery touches.
     * @param  ExtensionRuntimeMapCompiler  $runtime             Compiler that stages the recovered
     *         runtime map for publication.
     * @param  object                       $recoveryCapability  Break-glass token; only a caller holding
     *         this exact instance may recover.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private TransactionManager $transactions,
        private AuditRecorder $audit,
        private ClockInterface $clock,
        private ExtensionRuntimeMapCompiler $runtime,
        private object $recoveryCapability,
    ) {
    }

    /**
     * Clears the administrator theme activation and stages the built-in theme as the runtime map.
     *
     * The lease is renewed before the transaction, on entry to it, and again after the map is staged, so
     * a long-running recovery does not lose its cross-process lock mid-flight. Recovery is refused
     * outright unless the caller presents the capability instance this service was constructed with,
     * which keeps the entry point to the confirmed console command.
     *
     * @param   object                  $capability  Break-glass token; compared by identity against the
     *          instance held at construction.
     * @param   ExtensionRegistryLease  $lease       Held registry lease whose fence must still match the
     *          stored registry fence.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the capability does not match, the lease has lost its fence, the
     *          administrator activation row is missing or not updated exactly once, the active
     *          assignment count is unreadable, no theme was active, or the displaced theme is no longer
     *          installed.
     *
     * @since   2.0.0
     */
    public function recover(object $capability, ExtensionRegistryLease $lease): void
    {
        if ($capability !== $this->recoveryCapability) {
            throw new RuntimeException('Administrator recovery requires the console break-glass capability.');
        }
        $lease->renew();
        $this->transactions->transactional(function () use ($lease): void {
            $lease->renew();
            $fence = $this->database->fetchOne(sprintf(
                'SELECT fence FROM %s WHERE singleton_key = 1',
                $this->tables->quoted('extension_registry_fence'),
            ));
            if (!is_numeric($fence) || (int) $fence !== $lease->fence()) {
                throw new RuntimeException('Administrator recovery lost its database fence.');
            }
            $activation = $this->database->fetchAssociative(sprintf(
                "SELECT extension_id FROM %s WHERE surface = 'administrator'",
                $this->tables->quoted('theme_activations'),
            ));
            if ($activation === false) {
                throw new RuntimeException('The administrator theme activation record is missing.');
            }
            $extensionId = $activation['extension_id'] ?? null;
            $now = $this->clock->now();
            $affected = $this->database->executeStatement(sprintf(
                'UPDATE %s SET extension_id = NULL, version = version + 1, activated_by = ?, activated_at = ? '
                . "WHERE surface = 'administrator'",
                $this->tables->quoted('theme_activations'),
            ), ['system:break-glass-theme-recovery', $now], [Types::STRING, Types::DATETIME_IMMUTABLE]);
            if ($affected !== 1) {
                throw new RuntimeException('The administrator theme could not be recovered.');
            }
            if (is_string($extensionId)) {
                $active = $this->database->fetchOne(sprintf(
                    'SELECT (SELECT COUNT(*) FROM %s WHERE extension_id = ?) '
                    . '+ (SELECT COUNT(*) FROM %s WHERE extension_id = ?)',
                    $this->tables->quoted('theme_activations'),
                    $this->tables->quoted('site_theme_activations'),
                ), [$extensionId, $extensionId]);
                if (!is_int($active) && (!is_string($active) || preg_match('/^[0-9]+$/D', $active) !== 1)) {
                    throw new RuntimeException('The active theme assignment count is invalid.');
                }
                if ((int) $active === 0) {
                    $this->database->executeStatement(sprintf(
                        "UPDATE %s SET status = 'disabled', registry_version = registry_version + 1, updated_at = ? "
                        . "WHERE id = ? AND extension_type = 'template'",
                        $this->tables->quoted('extensions'),
                    ), [$now, $extensionId], [Types::DATETIME_IMMUTABLE, Types::GUID]);
                }
            }
            $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $now,
                'system:break-glass-theme-recovery',
                'theme.administrator.recover',
                'extension',
                'core:administrator',
                'success',
            ));
            if (!is_string($extensionId) || $extensionId === '') {
                throw new RuntimeException('No administrator theme is active for recovery.');
            }
            $identifier = $this->database->fetchOne(sprintf(
                'SELECT identifier FROM %s WHERE id = ?',
                $this->tables->quoted('extensions'),
            ), [$extensionId]);
            if (!is_string($identifier) || $identifier === '') {
                throw new RuntimeException('The recovered administrator theme is not installed.');
            }
            $this->runtime->stageAdministratorRecovery('theme.administrator.recover', $identifier);
            $lease->renew();
        });
    }
}
