<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Application;

use InvalidArgumentException;
use Kumwe\App\Application\Automation\JobHandler;
use Kumwe\App\Application\Automation\PermanentFailure;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Durable queue handler for authorization-bound report export generation.
 *
 * @since  2.0.0
 */
final readonly class GenerateReportExportHandler implements JobHandler
{
    /**
     * Wire the job type to its idempotent generation use case.
     *
     * @param  ExportGenerationService  $exports  Queue-side generator.
     *
     * @since  2.0.0
     */
    public function __construct(private ExportGenerationService $exports)
    {
    }

    /**
     * Return the stable registered job type.
     *
     * @return  string  `business_reporting.export.generate`.
     *
     * @since   2.0.0
     */
    public function type(): string
    {
        return 'business_reporting.export.generate';
    }

    /**
     * Validate the closed payload and generate one export.
     *
     * @param   array<string, mixed>  $payload  Object containing only `artifact_id`.
     * @param   ExecutionContext      $context  Narrow queue-worker context.
     *
     * @return  void
     *
     * @throws  PermanentFailure  When payload or current export bindings are permanently invalid.
     *
     * @since   2.0.0
     */
    public function handle(array $payload, ExecutionContext $context): void
    {
        $artifactId = $payload['artifact_id'] ?? null;
        if (
            array_keys($payload) !== ['artifact_id'] || !is_string($artifactId)
            || preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di',
                $artifactId,
            ) !== 1
        ) {
            throw new PermanentFailure('The report export job payload is invalid.');
        }
        try {
            $this->exports->generate($artifactId, $context);
        } catch (ExportGenerationRejected | InvalidArgumentException $exception) {
            throw new PermanentFailure($exception->getMessage(), 0, $exception);
        }
    }
}
