<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use Kumwe\App\Application\Automation\JobHandler;
use Kumwe\Context\Value\ExecutionContext;

/**
 * A job handler that starts its work, says so on disk, and then waits for a drill to decide how it ends.
 *
 * Left alone it never returns, which is the state a `SIGKILL` drill needs: a worker genuinely in the
 * middle of a job, holding a real lease, with nothing settled. Released by a `resume` file it returns
 * normally and hands the process straight into the worker's settlement, which is the one place a
 * database that went away while the job was in flight actually surfaces. One handler serves both
 * drills because the difference between them is what the drill does to the world, not what the
 * handler does.
 *
 * @since  2.0.0
 */
final readonly class DrillDirectedJobHandler implements JobHandler
{
    /**
     * Bind the handler to the directory it announces itself in and takes its release from.
     *
     * @param  string  $directory  Handshake directory shared with the drill that spawned this worker.
     *
     * @since  2.0.0
     */
    public function __construct(private string $directory)
    {
    }

    /**
     * Answer for a real registered job type, so nothing about the claim is a fixture.
     *
     * @return  string  The job type this handler is registered under.
     *
     * @since   2.0.0
     */
    public function type(): string
    {
        return 'system.sessions.purge';
    }

    /**
     * Announce that the work has started, then block until the drill releases it or ends the process.
     *
     * @param   array<string, mixed>  $payload  Stored job payload.
     * @param   ExecutionContext      $context  Context the job runs under.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function handle(array $payload, ExecutionContext $context): void
    {
        file_put_contents($this->directory . '/handler-entered', (string) getmypid());
        while (true) {
            clearstatcache(true, $this->directory . '/resume');
            if (is_file($this->directory . '/resume')) {
                return;
            }
            sleep(1);
        }
    }
}
