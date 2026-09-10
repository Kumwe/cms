<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessIntegration\Application;

use InvalidArgumentException;
use Kumwe\App\BusinessIntegration\Application\ValidatedContributedJobHandler;
use Kumwe\App\Extension\Runtime\ExtensionExecutionContext;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext as HostExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Extension\Spi\Application\Automation\JobHandler;
use Kumwe\Extension\Spi\Application\ExecutionContext;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\JobContributionDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ValidatedContributedJobHandler::class)]
/**
 * Proves a contributed SDK job implementation executes under its signed declaration and payload schema.
 *
 * @since  2.0.0
 */
final class ValidatedContributedJobHandlerTest extends TestCase
{
    /**
     * Prove the worker-facing type is the signed identifier and a valid payload reaches the implementation
     * together with that same declaration.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testASignedPayloadReachesTheImplementationWithItsDeclaration(): void
    {
        $definition = self::definition();
        $implementation = new class implements JobHandler {
            /**
             * Declaration, payload and context observed on the last run.
             *
             * @var    array{JobContributionDefinition, array<string, mixed>, ExecutionContext}|null
             * @since  2.0.0
             */
            public ?array $observed = null;

            /**
             * Record the exact arguments the host hands over.
             *
             * @param   JobContributionDefinition  $definition  Signed job declaration.
             * @param   array<string, mixed>       $payload     Validated payload.
             * @param   ExecutionContext           $context     Host execution context.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function handle(
                JobContributionDefinition $definition,
                array $payload,
                ExecutionContext $context,
            ): void {
                $this->observed = [$definition, $payload, $context];
            }
        };
        $context = self::context();
        $handler = new ValidatedContributedJobHandler($definition, $implementation);

        self::assertSame('acme.sample.review', $handler->type());

        $handler->handle(['site_identifier' => 'default'], $context);

        self::assertNotNull($implementation->observed);
        self::assertSame([$definition, ['site_identifier' => 'default']], array_slice($implementation->observed, 0, 2));
        self::assertSame($context, ExtensionExecutionContext::host($implementation->observed[2]));
    }

    /**
     * Prove a payload outside the signed schema never reaches the implementation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPayloadOutsideTheSignedSchemaNeverReachesTheImplementation(): void
    {
        $implementation = new class implements JobHandler {
            /**
             * Refuse every invocation: this double must never run.
             *
             * @param   JobContributionDefinition  $definition  Signed job declaration.
             * @param   array<string, mixed>       $payload     Validated payload.
             * @param   ExecutionContext           $context     Host execution context.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function handle(
                JobContributionDefinition $definition,
                array $payload,
                ExecutionContext $context,
            ): void {
                unset($definition, $payload, $context);

                throw new \LogicException('The implementation must not run on an invalid payload.');
            }
        };
        $handler = new ValidatedContributedJobHandler(self::definition(), $implementation);

        $this->expectException(InvalidArgumentException::class);

        $handler->handle(['site_identifier' => 'default', 'unexpected' => true], self::context());
    }

    /**
     * Build one signed job declaration with a closed single-field payload schema.
     *
     * @return  JobContributionDefinition  Signed sample job declaration.
     *
     * @since   2.0.0
     */
    private static function definition(): JobContributionDefinition
    {
        return JobContributionDefinition::fromArray([
            'job_type' => 'acme.sample.review',
            'schema_version' => 1,
            'handler_version' => '1.0.0',
            'payload_schema' => [
                'type' => 'object',
                'required' => ['site_identifier'],
                'properties' => ['site_identifier' => ['type' => 'string']],
                'additionalProperties' => false,
            ],
            'queue' => 'acme.sample',
            'maximum_attempts' => 3,
            'installation_wide' => false,
        ]);
    }

    /**
     * Mint one host-issued execution context for the worker run.
     *
     * @return  HostExecutionContext  Host-issued context.
     *
     * @since   2.0.0
     */
    private static function context(): HostExecutionContext
    {
        return AuthorizationContext::principal(['content.read'])->context(
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'contributed-job-request',
        );
    }
}
