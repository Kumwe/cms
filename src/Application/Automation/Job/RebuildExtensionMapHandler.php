<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation\Job;

use Kumwe\App\Application\Automation\JobHandler;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Extension\Runtime\ExtensionRuntimeMapCompiler;
use Kumwe\Extension\Spi\Identity\Domain\Capability;

/**
 * Scheduled job that recompiles the extension runtime map this replica serves requests from.
 *
 * The compiler is the only writer of the runtime map, and a replica that missed a publication — it was
 * offline during an install, or its local copy was discarded as untrusted — keeps serving a stale map
 * until something recompiles it. Scheduling this job gives operators that recovery path without an
 * HTTP request. The job type is installation-global, so the worker runs it once per installation under
 * the extension-materializer system principal rather than per site.
 *
 * @since  2.0.0
 */
final readonly class RebuildExtensionMapHandler implements JobHandler
{
    /**
     * Bind the handler to the compiler it drives and the gateway that guards it.
     *
     * @param  ExtensionRuntimeMapCompiler  $compiler       Sole writer of the compiled runtime map.
     * @param  AuthorizationGateway         $authorization  Decides whether the job context may rebuild.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ExtensionRuntimeMapCompiler $compiler,
        private AuthorizationGateway $authorization,
    ) {
    }

    /**
     * Report the job type a schedule or queued job names this handler by.
     *
     * @return  string  The constant `extensions.runtime.rebuild`.
     *
     * @since   2.0.0
     */
    public function type(): string
    {
        return 'extensions.runtime.rebuild';
    }

    /**
     * Reconcile the registry into a runtime map and publish it to this replica.
     *
     * The capability is re-asserted here rather than trusted from whoever created the schedule, because
     * a queued job outlives the request that scheduled it. The generation the compiler returns is
     * discarded: a job carries no result, and a run that finds the replica already current is a
     * success, not a no-op worth reporting.
     *
     * @param   array<string, mixed>  $payload  Scheduled payload; this handler reads no key from it.
     * @param   ExecutionContext      $context  System context the extension capability is checked against.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the job context may not
     *          manage extensions.
     * @throws  \RuntimeException  When the authoritative publication is missing or fails verification, or
     *          the replica-local map cannot be written.
     *
     * @since   2.0.0
     */
    public function handle(array $payload, ExecutionContext $context): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('extensions.manage'),
            AuthorizationResource::collection('extension_runtime_map'),
        );
        $this->compiler->materialize();
    }
}
