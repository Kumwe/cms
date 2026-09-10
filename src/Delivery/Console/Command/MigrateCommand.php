<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use Kumwe\App\Application\Authorization\SystemPrincipal;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Demo\Application\DemoProfileReconciler;
use Kumwe\App\Extension\Runtime\ExtensionRuntimeMapCompiler;
use Kumwe\App\Infrastructure\Persistence\Migration\MigrationRunner;
use Kumwe\Context\Value\SiteContext;

/**
 * Console command that applies the pending schema migrations and republishes the extension runtime.
 *
 * `database:migrate` is the deployment step run before any application process starts. It carries its
 * own `SystemIdentity::Migration` principal rather than an operator token, so migrating needs shell
 * access to the application account and nothing else. Every run finishes by reconciling and
 * materializing the extension runtime publication, including the run that found the schema already
 * current: a migration moves the registry the publication was signed over, and an interrupted
 * publisher leaves the two disagreeing, so republishing here is what stops replicas starting on a
 * generation the new schema no longer matches. Failures are not converted to an exit status; the
 * exception reaches the operator intact.
 *
 * @since  2.0.0
 */
final readonly class MigrateCommand implements Command
{
    /**
     * Wire the migration runner, the runtime publisher, and the authority both are called with.
     *
     * @param  MigrationRunner              $runner      Applies the forward-only migration plan.
     * @param  DemoProfileReconciler        $profiles    Reconciles the frozen demo selections after schema work.
     * @param  ExtensionRuntimeMapCompiler  $extensions  Republishes and materializes the runtime map after.
     * @param  SystemPrincipal              $system      Mints the migration context each run authorizes with.
     *
     * @since  2.0.0
     */
    public function __construct(
        private MigrationRunner $runner,
        private DemoProfileReconciler $profiles,
        private ExtensionRuntimeMapCompiler $extensions,
        private SystemPrincipal $system,
    ) {
    }

    /**
     * Name this command is invoked under on the console.
     *
     * @return  string  Always `database:migrate`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'database:migrate';
    }

    /**
     * Summary line `bin/kumwe list` prints beside the command name.
     *
     * @return  string  One-sentence statement of what the command applies.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.database_migrate.description';
    }

    /**
     * Apply every outstanding migration, then reconcile and materialize the extension runtime map.
     *
     * The runtime pass runs unconditionally, so an installation whose schema was already current still
     * converges its publication; only the lines printed ahead of it differ. A table the runner converged
     * on the database default collation is named on its own line, so a rewrite of physical tables never
     * happens silently. Each run mints a fresh request identifier, which is what ties the authorization
     * and audit records the run produces back to this one invocation.
     *
     * @param   list<string>  $arguments  Ignored; the command accepts no options.
     * @param   Output        $output     Sink for the migration lines and the materialized generation.
     *
     * @return  int  Always `0`; a failed run propagates its exception rather than returning a status.
     *
     * @throws  \RuntimeException  When a migration cannot be applied, the recorded checksums have
     *          drifted, or the runtime publication cannot be verified and materialized.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        $result = $this->runner->migrate($this->system->context(
            SiteContext::default(),
            'migration-' . bin2hex(random_bytes(16)),
        ));

        if (!$result->changed()) {
            $output->message('core.console.database_migrate.database_schema_is_current_reconciling_the');
        } else {
            foreach ($result->applied as $migration) {
                $output->message('core.console.database_migrate.applied', ['migration' => $migration]);
            }
        }
        foreach ($result->converged as $table) {
            $output->message('core.console.database_migrate.converged_collation', [
                'table' => $table,
                'collation' => (string) $result->collation,
            ]);
        }
        foreach ($this->profiles->reconcile() as $line) {
            $output->line($line);
        }
        $state = $this->extensions->reconcileAndMaterialize(true);
        $output->message('core.console.database_migrate.materialized_extension_runtime_generation', [
            'generation' => $state->generation,
        ]);

        return 0;
    }
}
