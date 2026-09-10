<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Application\Authorization\SystemPrincipal;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use LogicException;

/**
 * Trusted registry of the narrowly-scoped principals allowed to execute global jobs.
 *
 * A site-scoped job runs under the worker's own principal against the site that owns it, but an
 * installation-global job has no owning site, so `Worker` asks this registry for the principal that
 * matches the job type. Only the two maintenance identities that global jobs need may be registered,
 * and each of them only once, which keeps a wider identity — `Worker` itself, or `Migration` — out of
 * the path a global handler runs under, even if the container wiring is later edited carelessly.
 *
 * @since  2.0.0
 */
final readonly class GlobalJobPrincipals
{
    /**
     * Accepted principals, keyed by the backing value of the system identity each one carries.
     *
     * @var    array<string, SystemPrincipal>
     * @since  2.0.0
     */
    private array $principals;

    /**
     * Index the principals that installation-global job types are allowed to run as.
     *
     * @param   SystemPrincipal  $principals  Principals to trust, at most one per system identity.
     *
     * @throws  InvalidArgumentException  When a principal carries an identity other than
     *          `ExtensionMaterializer` or `InstallationMaintenance`, or when one identity arrives twice.
     *
     * @since   2.0.0
     */
    public function __construct(SystemPrincipal ...$principals)
    {
        $indexed = [];
        foreach ($principals as $principal) {
            $identity = $principal->identity();
            if (
                !in_array(
                    $identity,
                    [SystemIdentity::ExtensionMaterializer, SystemIdentity::InstallationMaintenance],
                    true,
                )
            ) {
                throw new InvalidArgumentException('A global job principal uses an unsupported system identity.');
            }
            if (isset($indexed[$identity->value])) {
                throw new InvalidArgumentException('A global job system identity was registered more than once.');
            }
            $indexed[$identity->value] = $principal;
        }
        $this->principals = $indexed;
    }

    /**
     * Issue the execution context an installation-global job runs under.
     *
     * The context is bound to the default site, because the work spans the installation rather than
     * any one site's data. Resolution goes through the job type's declared identity, so a job can
     * only ever run as the principal its declaration names.
     *
     * @param   string             $jobType        Registered type of the job about to be executed.
     * @param   JobExecutionScope  $scope          Declaration table mapping the job type to its identity.
     * @param   string             $requestId      Identifier tying audit records to this one execution.
     * @param   string             $correlationId  Identifier tying this execution back to the run that caused it.
     *
     * @return  ExecutionContext  Context carrying the trusted principal for the job type.
     *
     * @throws  LogicException  When the job type is not declared installation-global, or no principal is
     *          registered for the identity it declares.
     *
     * @since   2.0.0
     */
    public function context(
        string $jobType,
        JobExecutionScope $scope,
        string $requestId,
        string $correlationId,
    ): ExecutionContext {
        $identity = $scope->systemIdentity($jobType);
        $principal = $this->principals[$identity->value]
            ?? throw new LogicException(sprintf(
                'No trusted system principal is registered for installation-global job type "%s".',
                $jobType,
            ));

        return $principal->context(SiteContext::default(), $requestId, $correlationId);
    }
}
