<?php

declare(strict_types=1);

namespace Kumwe\App\Presentation\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Identity\Application\Authorization\InsufficientCapability;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Presentation\Application\ThemeMutationAuthorizer;
use Kumwe\App\Extension\Domain\ThemeSurface;

/**
 * Authorizes a theme mutation for one surface, confirming the decision against a freshly read grant row.
 *
 * The authorization gateway answers from whatever view of a principal's grants it was built with, which
 * may predate the transaction a theme change runs in. Because activating a theme rewrites what every
 * operator sees, this authorizer does not settle for that: after the gateway allows
 * `themes.<surface>.manage`, it re-reads the grant straight from the role tables, and takes `FOR UPDATE`
 * on that row when it is already inside a transaction on an engine that supports it. A revocation
 * committed between the gateway's decision and this read therefore wins, instead of racing it. System
 * identities carry no principal to look up and are authorized by the gateway alone.
 *
 * @since  2.0.0
 */
final readonly class DoctrineThemeMutationAuthorizer implements ThemeMutationAuthorizer
{
    /**
     * Bind the authorizer to the grant tables and the gateway it double-checks.
     *
     * @param  Connection            $database       DBAL connection the live grant row is read from.
     * @param  TableNames            $tables         Resolver applying the configured prefix to the user,
     *         role, and grant tables.
     * @param  AuthorizationGateway  $authorization  Gateway consulted for the first, policy-level
     *         decision.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private AuthorizationGateway $authorization,
    ) {
    }

    /**
     * Asserts the caller may mutate themes on a surface, and that the grant behind it is still live.
     *
     * The confirming read accepts a global grant or one scoped to the context's own site, and requires
     * the user row to still be active, so a suspended operator loses theme management immediately.
     *
     * @param   ExecutionContext  $context  Caller's context, supplying the principal and the site the
     *          site-scoped grant is matched against.
     * @param   ThemeSurface      $surface  Surface being mutated, which selects the
     *          `themes.<surface>.manage` capability.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the gateway refuses the
     *          capability for this context and resource.
     * @throws  InsufficientCapability  When the gateway allowed it but no live grant backs it, because
     *          the grant was revoked or the user is no longer active.
     *
     * @since   2.0.0
     */
    public function assertSurface(ExecutionContext $context, ThemeSurface $surface): void
    {
        $capability = 'themes.' . $surface->value . '.manage';
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString($capability),
            AuthorizationResource::item('theme', $surface->value),
        );
        $principal = $context->principal();
        if ($principal === null) {
            return;
        }
        $lock = $this->database->isTransactionActive()
            && !($this->database->getDatabasePlatform() instanceof SQLitePlatform)
            ? ' FOR UPDATE'
            : '';
        $allowed = $this->database->fetchOne(sprintf(
            'SELECT g.id FROM %s u INNER JOIN %s ur ON ur.user_id = u.id '
            . 'INNER JOIN %s g ON g.role_id = ur.role_id '
            . "WHERE u.id = ? AND u.status = 'active' AND g.capability_code = ? "
            . "AND (g.scope_type = 'global' OR (g.scope_type = 'site' AND g.scope_identifier = ?)) "
            . 'ORDER BY g.id LIMIT 1%s',
            $this->tables->quoted('users'),
            $this->tables->quoted('user_roles'),
            $this->tables->quoted('role_capability_grants'),
            $lock,
        ), [$principal->subject(), $capability, $context->site()->identifier()]);
        if (!is_string($allowed) || $allowed === '') {
            throw new InsufficientCapability($capability);
        }
    }
}
