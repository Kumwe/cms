<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Identity;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Identity\Domain\UserStatus;
use Kumwe\App\Identity\Infrastructure\Administration\DoctrineAccessControlRepository;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

#[CoversClass(AccessControlService::class)]
#[CoversClass(DoctrineAccessControlRepository::class)]
final class AccessControlIntegrationTest extends TestCase
{
    public function testCreatesIdentityWithPortableDoctrineDateTimeBindings(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $access = $container->get(AccessControlService::class);
        self::assertInstanceOf(AccessControlService::class, $access);
        $marker = Uuid::uuid7()->toString();
        $context = TestKernelFactory::administratorContext($container);

        $id = $access->createUser(
            $context,
            sprintf('integration-%s@example.test', $marker),
            'Database Matrix Editor',
            'correct horse battery staple',
        );

        $created = array_values(array_filter(
            $access->users($context),
            static fn (array $user): bool => ($user['id'] ?? null) === $id,
        ));
        self::assertCount(1, $created);
        self::assertSame('Database Matrix Editor', $created[0]['display_name']);
        self::assertSame('active', $created[0]['status']);
    }

    public function testRemovingAGrantInvalidatesAnAlreadyIssuedTokenImmediately(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $access = $container->get(AccessControlService::class);
        $identities = $container->get(AdministratorIdentityGateway::class);
        $tokens = $container->get(AccessTokenVerifier::class);
        self::assertInstanceOf(AccessControlService::class, $access);
        self::assertInstanceOf(AdministratorIdentityGateway::class, $identities);
        self::assertInstanceOf(AccessTokenVerifier::class, $tokens);
        $marker = Uuid::uuid7()->toString();
        $context = TestKernelFactory::administratorContext($container);
        $email = sprintf('token-revocation-%s@example.test', $marker);
        $userId = $access->createUser($context, $email, 'Revocation Test User', 'correct horse battery staple');
        $roleId = $access->createRole($context, 'token-test-' . $marker, 'Token revocation test');
        $contentGrant = $access->grant($context, $roleId, 'content.read');
        $access->grant($context, $roleId, 'users.manage');
        $access->assignRole($context, $userId, $roleId);
        $issued = $identities->issueAccessToken(
            $context,
            $email,
            'Immediate revocation proof',
            ['content.read'],
        );
        $principal = $tokens->verify($issued['token']);
        self::assertNotNull($principal);
        self::assertTrue($principal->hasCapability(Capability::fromString('content.read')));

        $access->revokeGrant($context, $contentGrant);

        self::assertNull($tokens->verify($issued['token']));
    }

    /**
     * Proves unscoped quota lookup uses SQL null predicates rather than untyped null placeholders.
     *
     * PostgreSQL cannot infer the type of a standalone `? IS NULL` parameter. Issuing two tokens in the
     * site/global context exercises both nullable tenant columns and also proves they share one quota
     * partition without relying on database-specific null-safe equality syntax.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnscopedTokenQuotaLookupIsPortable(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $access = $container->get(AccessControlService::class);
        $identities = $container->get(AdministratorIdentityGateway::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(AccessControlService::class, $access);
        self::assertInstanceOf(AdministratorIdentityGateway::class, $identities);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $context = TestKernelFactory::administratorContext($container);
        $marker = Uuid::uuid7()->toString();
        $email = sprintf('null-quota-%s@example.test', $marker);
        $userId = $access->createUser($context, $email, 'Null quota user', 'correct horse battery staple');
        $roleId = $access->createRole($context, 'null-quota-' . $marker, 'Null quota test');
        $access->grant($context, $roleId, 'content.read');
        $access->assignRole($context, $userId, $roleId);

        $first = $identities->issueAccessToken($context, $email, 'Null quota one', ['content.read']);
        $second = $identities->issueAccessToken($context, $email, 'Null quota two', ['content.read']);

        foreach ([$first['token_id'], $second['token_id']] as $tokenId) {
            $scope = $database->fetchAssociative(sprintf(
                'SELECT organization_identifier, workspace_identifier FROM %s WHERE id = ?',
                $tables->quoted('api_tokens'),
            ), [$tokenId]);
            self::assertIsArray($scope);
            self::assertNull($scope['organization_identifier']);
            self::assertNull($scope['workspace_identifier']);
        }
    }

    /**
     * Proves grant changes invalidate every distinct direct or membership role attachment.
     *
     * Inactive memberships remain in the affected set so reactivation cannot revive an old credential,
     * while a user attached through both paths advances only once and an unrelated user is untouched.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRoleGrantAndRevocationInvalidateDistinctMembershipRoleUsers(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $access = $container->get(AccessControlService::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(AccessControlService::class, $access);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $context = TestKernelFactory::administratorContext($container);
        $marker = Uuid::uuid7()->toString();
        $roleId = $access->createRole($context, 'membership-epoch-' . $marker, 'Membership epoch test');
        $directAndMembershipUser = $access->createUser(
            $context,
            sprintf('membership-epoch-both-%s@example.test', $marker),
            'Direct and membership role user',
            'correct horse battery staple',
        );
        $membershipOnlyUser = $access->createUser(
            $context,
            sprintf('membership-epoch-only-%s@example.test', $marker),
            'Membership-only role user',
            'correct horse battery staple',
        );
        $inactiveMembershipUser = $access->createUser(
            $context,
            sprintf('membership-epoch-inactive-%s@example.test', $marker),
            'Inactive membership role user',
            'correct horse battery staple',
            UserStatus::Suspended,
        );
        $unrelatedUser = $access->createUser(
            $context,
            sprintf('membership-epoch-unrelated-%s@example.test', $marker),
            'Unrelated role user',
            'correct horse battery staple',
        );
        $access->assignRole($context, $directAndMembershipUser, $roleId);

        $organizationId = Uuid::uuid7()->toString();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $database->insert($tables->raw('organizations'), [
            'id' => $organizationId,
            'site_identifier' => 'default',
            'identifier' => 'membership-epoch-' . $marker,
            'name' => 'Membership epoch organization',
            'status' => 'active',
            'policy_generation' => 1,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            'created_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
        foreach (
            [
            [$directAndMembershipUser, 'active'],
            [$membershipOnlyUser, 'active'],
            [$inactiveMembershipUser, 'suspended'],
            ] as [$userId, $status]
        ) {
            $membershipId = Uuid::uuid7()->toString();
            $database->insert($tables->raw('organization_memberships'), [
                'id' => $membershipId,
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'status' => $status,
                'version' => 1,
                'valid_from' => $now->modify('-1 day'),
                'valid_until' => null,
                'created_by' => $context->actorId(),
                'created_at' => $now,
                'updated_at' => $now,
            ], [
                'valid_from' => Types::DATETIME_IMMUTABLE,
                'valid_until' => Types::DATETIME_IMMUTABLE,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'updated_at' => Types::DATETIME_IMMUTABLE,
            ]);
            $database->insert($tables->raw('membership_roles'), [
                'membership_id' => $membershipId,
                'role_id' => $roleId,
                'assigned_by' => $context->actorId(),
                'assigned_at' => $now,
            ], ['assigned_at' => Types::DATETIME_IMMUTABLE]);
        }
        $epoch = static function (string $userId) use ($database, $tables): int {
            $stored = $database->fetchOne(sprintf(
                'SELECT security_epoch FROM %s WHERE id = ?',
                $tables->quoted('users'),
            ), [$userId]);
            self::assertTrue(is_int($stored) || is_string($stored));

            return (int) $stored;
        };
        $before = [
            $directAndMembershipUser => $epoch($directAndMembershipUser),
            $membershipOnlyUser => $epoch($membershipOnlyUser),
            $inactiveMembershipUser => $epoch($inactiveMembershipUser),
            $unrelatedUser => $epoch($unrelatedUser),
        ];

        $grantId = $access->grant($context, $roleId, 'content.read');

        self::assertSame($before[$directAndMembershipUser] + 1, $epoch($directAndMembershipUser));
        self::assertSame($before[$membershipOnlyUser] + 1, $epoch($membershipOnlyUser));
        self::assertSame($before[$inactiveMembershipUser] + 1, $epoch($inactiveMembershipUser));
        self::assertSame($before[$unrelatedUser], $epoch($unrelatedUser));

        $access->revokeGrant($context, $grantId);

        self::assertSame($before[$directAndMembershipUser] + 2, $epoch($directAndMembershipUser));
        self::assertSame($before[$membershipOnlyUser] + 2, $epoch($membershipOnlyUser));
        self::assertSame($before[$inactiveMembershipUser] + 2, $epoch($inactiveMembershipUser));
        self::assertSame($before[$unrelatedUser], $epoch($unrelatedUser));
    }

    public function testSiteScopedRevocationCannotReadOrRevokeAnotherSiteToken(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $access = $container->get(AccessControlService::class);
        $identities = $container->get(AdministratorIdentityGateway::class);
        $tokens = $container->get(AccessTokenVerifier::class);
        self::assertInstanceOf(AccessControlService::class, $access);
        self::assertInstanceOf(AdministratorIdentityGateway::class, $identities);
        self::assertInstanceOf(AccessTokenVerifier::class, $tokens);
        $context = TestKernelFactory::administratorContext($container);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        if (
            $database->fetchOne(sprintf(
                'SELECT identifier FROM %s WHERE identifier = ?',
                $tables->quoted('sites'),
            ), ['other-site']) === false
        ) {
            $database->insert($tables->raw('sites'), [
                'identifier' => 'other-site',
                'name' => 'Other integration site',
                'created_at' => new \DateTimeImmutable(),
            ], ['created_at' => Types::DATETIME_IMMUTABLE]);
        }
        $principal = $context->principal();
        self::assertNotNull($principal);
        $other = $principal->context(
            SiteContext::fromString('other-site'),
            AuthenticationStrength::Password,
            'integration-other-site-' . bin2hex(random_bytes(8)),
        );
        $marker = Uuid::uuid7()->toString();
        $email = sprintf('site-token-%s@example.test', $marker);
        $userId = $access->createUser($context, $email, 'Site Token User', 'correct horse battery staple');
        $roleId = $access->createRole($context, 'site-token-' . $marker, 'Site token test');
        $access->grant($context, $roleId, 'content.read');
        $access->assignRole($context, $userId, $roleId);
        $defaultToken = $identities->issueAccessToken($context, $email, 'Default site', ['content.read']);
        $otherToken = $identities->issueAccessToken($other, $email, 'Other site', ['content.read']);
        self::assertNull($tokens->verify($otherToken['token'], 'kumwe-http', 'api', 'default'));
        self::assertNull($tokens->verify($defaultToken['token'], 'kumwe-mcp', 'mcp', 'default'));
        $siteContext = TestKernelFactory::contextFromGrantRows($container, [[
            'capability' => 'users.manage',
            'scope_type' => 'site',
            'scope_identifier' => 'default',
        ]]);

        $subjectTokens = array_values(array_filter(
            $access->tokens($siteContext),
            static fn (array $token): bool => ($token['subject_id'] ?? null) === $userId,
        ));
        self::assertSame([$defaultToken['token_id']], array_column($subjectTokens, 'id'));
        try {
            $access->revokeToken($siteContext, $otherToken['token_id']);
            self::fail('Cross-site token revocation must be rejected.');
        } catch (AuthorizationDenied | \InvalidArgumentException) {
        }
        self::assertSame(1, $access->revokeSubjectTokens($siteContext, $userId, 'site access removed'));
        self::assertNull($tokens->verify($defaultToken['token'], 'kumwe-http', 'api', 'default'));
        self::assertNotNull($tokens->verify($otherToken['token'], 'kumwe-http', 'api', 'other-site'));
        try {
            $access->emergencyRevokeAllSubjectTokens(
                TestKernelFactory::contextFromGrantRows($container, [[
                    'capability' => 'users.manage',
                    'scope_type' => 'site',
                    'scope_identifier' => 'default',
                ]]),
                $userId,
                'attempted site escalation',
            );
            self::fail('A site-scoped grant cannot perform global emergency revocation.');
        } catch (AuthorizationDenied) {
        }
        self::assertNotNull($tokens->verify($otherToken['token'], 'kumwe-http', 'api', 'other-site'));
        self::assertSame(1, $access->emergencyRevokeAllSubjectTokens($context, $userId, 'global compromise'));
        self::assertNull($tokens->verify($otherToken['token'], 'kumwe-http', 'api', 'other-site'));
    }

    public function testConcurrentIssuersCannotExceedTokenQuota(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            self::markTestSkipped('The process-control extension is required for the quota race test.');
        }
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $access = $container->get(AccessControlService::class);
        $identities = $container->get(AdministratorIdentityGateway::class);
        self::assertInstanceOf(AccessControlService::class, $access);
        self::assertInstanceOf(AdministratorIdentityGateway::class, $identities);
        $context = TestKernelFactory::administratorContext($container);
        $marker = Uuid::uuid7()->toString();
        $email = sprintf('quota-race-%s@example.test', $marker);
        $userId = $access->createUser($context, $email, 'Quota Race User', 'correct horse battery staple');
        $roleId = $access->createRole($context, 'quota-race-' . $marker, 'Quota race test');
        $access->grant($context, $roleId, 'content.read');
        $access->assignRole($context, $userId, $roleId);
        for ($index = 0; $index < 24; ++$index) {
            $identities->issueAccessToken($context, $email, 'Quota seed ' . $index, ['content.read']);
        }
        $directory = sys_get_temp_dir() . '/kumwe-quota-race-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $start = $directory . '/start';
        $children = [];
        for ($index = 0; $index < 2; ++$index) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                while (!is_file($start)) {
                    usleep(1_000);
                }
                try {
                    $childContainer = TestKernelFactory::create(Environment::fromGlobals());
                    $childGateway = $childContainer->get(AdministratorIdentityGateway::class);
                    if (!$childGateway instanceof AdministratorIdentityGateway) {
                        throw new \RuntimeException('Child identity gateway is unavailable.');
                    }
                    $childGateway->issueAccessToken(
                        TestKernelFactory::administratorContext($childContainer),
                        $email,
                        'Concurrent issuer ' . $index,
                        ['content.read'],
                    );
                    file_put_contents($directory . '/result-' . $index, 'issued');
                } catch (\Throwable $exception) {
                    file_put_contents($directory . '/result-' . $index, 'rejected:' . $exception->getMessage());
                }
                exit(0);
            }
            self::assertGreaterThan(0, $pid);
            $children[] = $pid;
        }
        touch($start);
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
        }
        $results = [
            (string) file_get_contents($directory . '/result-0'),
            (string) file_get_contents($directory . '/result-1'),
        ];
        sort($results);
        self::assertSame('issued', $results[0]);
        self::assertStringStartsWith('rejected:', $results[1]);
    }

    public function testInvalidatedEpochTokensDoNotPreventReplacementIssuanceAtQuota(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $access = $container->get(AccessControlService::class);
        $identities = $container->get(AdministratorIdentityGateway::class);
        self::assertInstanceOf(AccessControlService::class, $access);
        self::assertInstanceOf(AdministratorIdentityGateway::class, $identities);
        $context = TestKernelFactory::administratorContext($container);
        $marker = Uuid::uuid7()->toString();
        $email = sprintf('epoch-quota-%s@example.test', $marker);
        $userId = $access->createUser($context, $email, 'Epoch quota user', 'correct horse battery staple');
        $roleId = $access->createRole($context, 'epoch-quota-' . $marker, 'Epoch quota test');
        $access->grant($context, $roleId, 'content.read');
        $access->assignRole($context, $userId, $roleId);
        for ($index = 0; $index < 25; ++$index) {
            $identities->issueAccessToken($context, $email, 'Old epoch ' . $index, ['content.read']);
        }

        $access->grant($context, $roleId, 'content.update');

        $replacement = $identities->issueAccessToken(
            $context,
            $email,
            'Replacement after policy change',
            ['content.read'],
        );
        self::assertNotSame('', $replacement['token']);
    }

    public function testTokenSiteIdentifierSupportsTheSiteContextBoundary(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $access = $container->get(AccessControlService::class);
        $identities = $container->get(AdministratorIdentityGateway::class);
        $verifier = $container->get(AccessTokenVerifier::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(AccessControlService::class, $access);
        self::assertInstanceOf(AdministratorIdentityGateway::class, $identities);
        self::assertInstanceOf(AccessTokenVerifier::class, $verifier);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $marker = Uuid::uuid7()->toString();
        $siteIdentifier = substr('s-' . $marker . '-' . str_repeat('s', 191), 0, 191);
        $database->insert($tables->raw('sites'), [
            'identifier' => $siteIdentifier,
            'name' => 'Maximum-length site',
            'created_at' => new \DateTimeImmutable(),
        ], ['created_at' => Types::DATETIME_IMMUTABLE]);
        $administrator = TestKernelFactory::administratorContext($container);
        $principal = $administrator->principal();
        self::assertNotNull($principal);
        $siteContext = $principal->context(
            SiteContext::fromString($siteIdentifier),
            AuthenticationStrength::Password,
            'site-boundary-' . bin2hex(random_bytes(8)),
        );
        $email = sprintf('site-boundary-%s@example.test', $marker);
        $userId = $access->createUser($administrator, $email, 'Site boundary user', 'correct horse battery staple');
        $roleId = $access->createRole($administrator, 'site-boundary-' . $marker, 'Site boundary test');
        $access->grant($administrator, $roleId, 'content.read');
        $access->assignRole($administrator, $userId, $roleId);

        $issued = $identities->issueAccessToken($siteContext, $email, 'Boundary site', ['content.read']);

        self::assertSame($siteIdentifier, $database->fetchOne(sprintf(
            'SELECT site_identifier FROM %s WHERE id = ?',
            $tables->quoted('api_tokens'),
        ), [$issued['token_id']]));
        self::assertNotNull($verifier->verify($issued['token'], 'kumwe-http', 'api', $siteIdentifier));
        self::assertNull($verifier->verify($issued['token'], 'kumwe-http', 'api', 'default'));

        $database->update(
            $tables->raw('sites'),
            ['enabled' => false],
            ['identifier' => $siteIdentifier],
            ['enabled' => Types::BOOLEAN],
        );
        self::assertNull($verifier->verify($issued['token'], 'kumwe-http', 'api', $siteIdentifier));
    }

    public function testCrossUserTokenIssuanceRequiresGlobalManagementAndDelegatedCapability(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $access = $container->get(AccessControlService::class);
        $identities = $container->get(AdministratorIdentityGateway::class);
        self::assertInstanceOf(AccessControlService::class, $access);
        self::assertInstanceOf(AdministratorIdentityGateway::class, $identities);
        $marker = Uuid::uuid7()->toString();
        $setup = TestKernelFactory::administratorContext($container);
        $email = sprintf('delegation-%s@example.test', $marker);
        $userId = $access->createUser($setup, $email, 'Delegation Target', 'correct horse battery staple');
        $roleId = $access->createRole($setup, 'delegation-' . $marker, 'Delegation target');
        $access->grant($setup, $roleId, 'content.read');
        $access->assignRole($setup, $userId, $roleId);

        foreach (
            [
                TestKernelFactory::contextFromGrantRows($container, [[
                    'capability' => 'users.manage',
                    'scope_type' => 'global',
                    'scope_identifier' => null,
                ]]),
                TestKernelFactory::contextFromGrantRows($container, [[
                    'capability' => 'users.manage',
                    'scope_type' => 'site',
                    'scope_identifier' => 'default',
                ]]),
            ] as $underPrivileged
        ) {
            try {
                $identities->issueAccessToken(
                    $underPrivileged,
                    $email,
                    'Escalation attempt',
                    ['content.read'],
                );
                self::fail('A token issuer cannot delegate a capability or global authority it does not hold.');
            } catch (AuthorizationDenied) {
            }
        }
    }

    public function testSiteRevocationSerializesWithIssuanceOnTheUserFence(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            self::markTestSkipped('The process-control extension is required for the revocation race test.');
        }
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $access = $container->get(AccessControlService::class);
        self::assertInstanceOf(AccessControlService::class, $access);
        $context = TestKernelFactory::administratorContext($container);
        $marker = Uuid::uuid7()->toString();
        $userId = $access->createUser(
            $context,
            sprintf('revoke-race-%s@example.test', $marker),
            'Revoke Race User',
            'correct horse battery staple',
        );
        $tokenId = Uuid::uuid7()->toString();
        $directory = sys_get_temp_dir() . '/kumwe-revoke-race-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);

        $issuer = pcntl_fork();
        if ($issuer === 0) {
            try {
                $child = TestKernelFactory::create(Environment::fromGlobals());
                $database = $child->get(Connection::class);
                $tables = $child->get(TableNames::class);
                if (!$database instanceof Connection || !$tables instanceof TableNames) {
                    throw new \RuntimeException('Issuer database services are unavailable.');
                }
                $database->beginTransaction();
                $epoch = $database->fetchOne(sprintf(
                    'SELECT security_epoch FROM %s WHERE id = ? FOR UPDATE',
                    $tables->quoted('users'),
                ), [$userId]);
                file_put_contents($directory . '/issuer-locked', '1');
                $this->waitForFile($directory . '/release-issuer');
                $now = new \DateTimeImmutable();
                $database->insert($tables->raw('api_tokens'), [
                    'id' => $tokenId,
                    'subject_id' => $userId,
                    // Derived from this run's token ID: the digest column is uniquely indexed, so a
                    // constant here makes the test pass only against a database it has never seen.
                    'token_digest' => hash('sha256', 'race-secret:' . $tokenId),
                    'name' => 'Race token',
                    'capabilities' => ['users.manage'],
                    'security_epoch' => (int) $epoch,
                    'audience' => 'kumwe-http',
                    'purpose' => 'api',
                    'site_identifier' => 'default',
                    'rotated_from' => null,
                    'expires_at' => $now->modify('+1 day'),
                    'revoked_at' => null,
                    'revocation_reason' => null,
                    'created_at' => $now,
                    'last_used_at' => null,
                ], [
                    'capabilities' => Types::JSON,
                    'expires_at' => Types::DATETIME_IMMUTABLE,
                    'created_at' => Types::DATETIME_IMMUTABLE,
                ]);
                $database->commit();
                file_put_contents($directory . '/issuer-result', 'committed');
            } catch (\Throwable $exception) {
                file_put_contents($directory . '/issuer-result', 'failed:' . $exception->getMessage());
            }
            exit(0);
        }
        self::assertGreaterThan(0, $issuer);
        $this->waitForFile($directory . '/issuer-locked');

        $revoker = pcntl_fork();
        if ($revoker === 0) {
            try {
                file_put_contents($directory . '/revoker-started', '1');
                $childContainer = TestKernelFactory::create(Environment::fromGlobals());
                $childAccess = $childContainer->get(AccessControlService::class);
                if (!$childAccess instanceof AccessControlService) {
                    throw new \RuntimeException('Revoker service is unavailable.');
                }
                $count = $childAccess->revokeSubjectTokens(
                    TestKernelFactory::contextFromGrantRows($childContainer, [[
                        'capability' => 'users.manage',
                        'scope_type' => 'site',
                        'scope_identifier' => 'default',
                    ]]),
                    $userId,
                    'serialized site revocation',
                );
                file_put_contents($directory . '/revoker-result', (string) $count);
            } catch (\Throwable $exception) {
                file_put_contents($directory . '/revoker-result', 'failed:' . $exception->getMessage());
            }
            exit(0);
        }
        self::assertGreaterThan(0, $revoker);
        $this->waitForFile($directory . '/revoker-started');
        usleep(50_000);
        touch($directory . '/release-issuer');
        foreach ([$issuer, $revoker] as $child) {
            pcntl_waitpid($child, $status);
            self::assertTrue(pcntl_wifexited($status));
        }
        self::assertSame('committed', (string) file_get_contents($directory . '/issuer-result'));
        self::assertSame('1', (string) file_get_contents($directory . '/revoker-result'));
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $database->close();
        $revokedAt = $database->fetchOne(sprintf(
            'SELECT revoked_at FROM %s WHERE id = ?',
            $tables->quoted('api_tokens'),
        ), [$tokenId]);
        self::assertIsString($revokedAt);
    }

    private function waitForFile(string $path): void
    {
        $deadline = microtime(true) + 10.0;
        while (!is_file($path)) {
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException('Timed out waiting for race-test synchronization.');
            }
            usleep(1_000);
        }
    }
}
