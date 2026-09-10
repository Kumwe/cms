<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use Kumwe\App\Application\Authorization\SystemPrincipal;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Infrastructure\Persistence\Migration\MigrationRunner;
use Kumwe\Context\Value\SiteContext;

/**
 * Console command that reports which migrations an installation has still to apply.
 *
 * `database:status` is the read-only counterpart to `database:migrate`: it applies nothing, and
 * reports outstanding work through its exit status rather than its output, so a deployment gate or a
 * container health check can branch on `2` without parsing lines. The same schema-compatibility
 * checks the migrate command runs happen here too, which means an installation whose ledger has
 * drifted fails this command before anyone reaches the one that would write.
 *
 * @since  2.0.0
 */
final readonly class MigrationStatusCommand implements Command
{
    /**
     * Wire the migration runner and the authority its read is authorized with.
     *
     * @param  MigrationRunner  $runner  Resolves the plan against the recorded migration ledger.
     * @param  SystemPrincipal  $system  Mints the migration context each check authorizes with.
     *
     * @since  2.0.0
     */
    public function __construct(private MigrationRunner $runner, private SystemPrincipal $system)
    {
    }

    /**
     * Name this command is invoked under on the console.
     *
     * @return  string  Always `database:status`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'database:status';
    }

    /**
     * Summary line `bin/kumwe list` prints beside the command name.
     *
     * @return  string  One-sentence statement of what the command reports.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.database_status.description';
    }

    /**
     * List the migrations recorded as not yet applied.
     *
     * The exit status is the machine-readable half of the answer, so callers that only need to know
     * whether a deployment must migrate can ignore the printed identifiers entirely. Resolving the plan
     * creates the ledger table when it is missing, which is the one side effect of an otherwise
     * read-only command.
     *
     * @param   list<string>  $arguments  Ignored; the command accepts no options.
     * @param   Output        $output     Sink for one `Pending <id>` line per outstanding migration.
     *
     * @return  int  `0` when the schema is current, `2` when at least one migration is outstanding.
     *
     * @throws  \RuntimeException  When the ledger belongs to an incompatible schema or a recorded
     *          migration checksum has drifted.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        $pending = $this->runner->pending($this->system->context(
            SiteContext::default(),
            'migration-status-' . bin2hex(random_bytes(16)),
        ));

        if ($pending === []) {
            $output->message('core.console.database_status.database_schema_is_current');

            return 0;
        }

        foreach ($pending as $migration) {
            $output->message('core.console.database_status.pending', ['id' => $migration->id()]);
        }

        return 2;
    }
}
