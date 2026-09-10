<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation\Job;

use InvalidArgumentException;
use Kumwe\App\Application\Automation\JobHandler;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextPurger;

/**
 * Drains expired Studio Content authoring contexts as installation-wide maintenance.
 *
 * Contexts from every site share one physical table, so one globally authorized job handles the
 * backlog in bounded batches. The job never receives a site, actor, target, or context key and cannot
 * turn retention into a resource-discovery surface.
 *
 * @since  2.0.0
 */
final readonly class PurgeStudioContentAuthoringContextsHandler implements JobHandler
{
    /**
     * Stable job type used by the scheduler, queue, and execution-scope authority.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string JOB_TYPE = 'studio.content-authoring-context.purge';

    /**
     * Largest batch supported by the persistence adapter.
     *
     * Validating it before the loop prevents a malformed schedule from deleting an earlier batch and
     * failing only when a later pass reaches the adapter.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int MAXIMUM_BATCH_SIZE = 10_000;

    /**
     * Bind the maintenance loop to context retention and global automation authorization.
     *
     * @param  ContentStudioAuthoringContextPurger  $contexts       Bounded expired-row collector.
     * @param  AuthorizationGateway                 $authorization  Gateway rechecking unattended authority.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ContentStudioAuthoringContextPurger $contexts,
        private AuthorizationGateway $authorization,
    ) {
    }

    /**
     * Name the installation-wide retention job.
     *
     * @return  string  Stable scheduler and queue type.
     *
     * @since   2.0.0
     */
    public function type(): string
    {
        return self::JOB_TYPE;
    }

    /**
     * Purge expired contexts until the observed backlog drains or the run budget is exhausted.
     *
     * @param   array<string, mixed>  $payload  Optional `batch_size` and `maximum_batches` integer limits.
     * @param   ExecutionContext      $context  Installation-maintenance identity supplied by the worker.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a limit is malformed or outside its supported range.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When maintenance authority is absent.
     *
     * @since   2.0.0
     */
    public function handle(array $payload, ExecutionContext $context): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('automation.manage'),
            AuthorizationResource::item('automation_installation', self::JOB_TYPE),
        );
        $batchSize = $payload['batch_size'] ?? 1_000;
        $maximumBatches = $payload['maximum_batches'] ?? 10;
        if (
            !is_int($batchSize)
            || !is_int($maximumBatches)
            || $batchSize < 1
            || $batchSize > self::MAXIMUM_BATCH_SIZE
            || $maximumBatches < 1
            || $maximumBatches > 100
        ) {
            throw new InvalidArgumentException('Studio Content authoring context purge limits are invalid.');
        }
        for ($batch = 0; $batch < $maximumBatches; $batch++) {
            if ($this->contexts->purgeExpired($batchSize) < $batchSize) {
                return;
            }
        }
    }
}
