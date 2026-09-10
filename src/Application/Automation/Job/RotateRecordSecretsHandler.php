<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation\Job;

use InvalidArgumentException;
use Kumwe\App\Application\Automation\JobHandler;
use Kumwe\App\BusinessRecord\Application\RecordSecretRotation;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Queued driver for record-secret re-encryption, so a rotation finishes without an operator watching.
 *
 * The console command is the right tool for a small installation and for the first pass an operator wants
 * to watch. Anything larger wants this: enqueue or schedule the job for the site being rotated and every
 * run moves another bounded batch, with the worker's lease, retry and failure handling around it. The job
 * is site-scoped, so it runs as the worker identity against the site that durably owns it and can never
 * reach another site's records.
 *
 * Nothing is seeded to run on a timer. A rotation is a campaign with an end, not a nightly chore, and a
 * schedule that re-keys forever would keep an installation permanently mid-rotation. An operator enables
 * one for the duration and removes it when `complete` first comes back true.
 *
 * @since  2.0.0
 */
final readonly class RotateRecordSecretsHandler implements JobHandler
{
    /**
     * Bind the handler to the pass it drives.
     *
     * @param  RecordSecretRotation  $rotation  Bounded, resumable re-encryption pass.
     *
     * @since  2.0.0
     */
    public function __construct(private RecordSecretRotation $rotation)
    {
    }

    /**
     * Report the job type a schedule or queued job names this handler by.
     *
     * @return  string  The constant `business.record.secret.rekey`.
     *
     * @since   2.0.0
     */
    public function type(): string
    {
        return 'business.record.secret.rekey';
    }

    /**
     * Move one bounded batch of stored secrets onto the active key.
     *
     * The handler deliberately returns rather than looping until the installation is finished: a job that
     * ran to completion would hold its lease for an unbounded time and would have to be killed to stop,
     * whereas one bounded batch per run is interruptible at every point and leaves consistent state.
     *
     * @param   array<string, mixed>  $payload  Optional integer `batch_size` from 1 to 1000; absent takes
     *          200, which is the same default the console command uses.
     * @param   ExecutionContext      $context  Site-scoped worker context the pass is authorized and
     *          audited under.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the configured batch size is not a positive integer.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the job context may not
     *          re-key business-record secrets on its site.
     *
     * @since   2.0.0
     */
    public function handle(array $payload, ExecutionContext $context): void
    {
        $batchSize = $payload['batch_size'] ?? 200;
        if (!is_int($batchSize) || $batchSize < 1) {
            throw new InvalidArgumentException('The record re-keying batch size must be a positive integer.');
        }
        $this->rotation->rotate($context, $batchSize);
    }
}
