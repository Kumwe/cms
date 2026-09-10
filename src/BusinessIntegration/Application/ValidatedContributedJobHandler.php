<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Application;

use Kumwe\App\Application\Automation\JobHandler;
use Kumwe\App\Extension\Runtime\ExtensionExecutionContext;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Application\Automation\JobHandler as ContributedJobHandler;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\JobContributionDefinition;

/**
 * Enforces a signed payload contract before invoking one active extension job implementation.
 *
 * The contributed implementation carries no identity of its own: the binding registrar bound it to the
 * signed declaration, so the declaration alone names the job type the worker registry executes, and the
 * implementation receives that same declaration with every validated payload.
 *
 * @since  2.0.0
 */
final readonly class ValidatedContributedJobHandler implements JobHandler
{
    /**
     * Create the validated contributed job handler.
     *
     * @param  JobContributionDefinition  $definition  Signed contribution definition governing the operation.
     * @param  ContributedJobHandler      $handler     Runtime implementation bound to the signed contribution.
     * @param  PayloadSchemaValidator     $payloads    Bounded payload-schema validator for contributed data.
     *
     * @since  2.0.0
     */
    public function __construct(
        private JobContributionDefinition $definition,
        private ContributedJobHandler $handler,
        private PayloadSchemaValidator $payloads = new PayloadSchemaValidator(),
    ) {
        $this->payloads->assertSchema($definition->payloadSchema());
    }

    /**
     * Return the contributed job type named by the signed declaration.
     *
     * @return  string  Signed contributed job type the wrapped implementation executes.
     *
     * @since   2.0.0
     */
    public function type(): string
    {
        return $this->definition->identifier();
    }

    /**
     * Validate the payload against the signed schema, then hand it and the declaration to the implementation.
     *
     * @param   array<string, mixed>  $payload  Decoded job payload validated against the signed contribution schema.
     * @param   ExecutionContext      $context  Authenticated execution context for authorization and audit.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function handle(array $payload, ExecutionContext $context): void
    {
        $this->payloads->assertPayload($this->definition->payloadSchema(), $payload);
        $this->handler->handle($this->definition, $payload, ExtensionExecutionContext::of($context));
    }
}
