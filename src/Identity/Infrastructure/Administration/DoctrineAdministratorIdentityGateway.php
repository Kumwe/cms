<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Infrastructure\Administration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationPolicyRegistry;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Identity\Application\Administration\AuthenticationRateLimiter;
use Kumwe\App\Identity\Application\Administration\TokenDelegationPreauthorizer;
use Kumwe\App\Identity\Application\Administration\TokenRotationPreauthorizer;
use Kumwe\App\Identity\Application\Administration\AccessTokenQuotaPolicy;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Identity\Application\Authentication\AccessTokenContext;
use Kumwe\App\Identity\Application\Security\PasswordHasher;
use Kumwe\App\Identity\Domain\EmailAddress;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Doctrine implementation of the four identity operations whose output is itself a credential.
 *
 * Everything is read from and written to the prefixed `users`, `password_credentials`, `roles`,
 * `user_roles`, `role_capability_grants` and `api_tokens` tables over one DBAL connection. Only
 * derivations of the secrets are stored: a password as a `PasswordHasher` hash, an API token as its
 * SHA-256, and the throttle is handed HMACs of the address and the request origin rather than either
 * value. Each write runs inside `TransactionManager::transactional()` together with its ownership rows
 * and its audit event, so a rejected write leaves neither an unowned resource nor an unrecorded change.
 * The two paths that can escalate authority guard against a mid-request change: bootstrap locks the
 * default site row before it provisions, and issuance repeats the delegation check with the subject's
 * user row locked, so a grant revoked between the checks cannot be captured in a token.
 *
 * @since  2.0.0
 */
final readonly class DoctrineAdministratorIdentityGateway implements AdministratorIdentityGateway
{
    /**
     * Lifetime a token is given when the caller names no expiry, as a `DateTimeImmutable::modify()` step.
     *
     * @var    string
     * @since  2.0.0
     */
    private const DEFAULT_TOKEN_LIFETIME = '+30 days';
    /**
     * Furthest expiry an issuance may ask for, measured from the moment the token is minted.
     *
     * @var    string
     * @since  2.0.0
     */
    private const MAXIMUM_TOKEN_LIFETIME = '+90 days';
    /**
     * Wire the gateway to the connection, throttle, authorization and audit collaborators it works through.
     *
     * @param  Connection                    $database               DBAL connection every identity table is
     *         read and written through.
     * @param  TableNames                    $tables                 Resolver applying the configured prefix to
     *         those tables.
     * @param  PasswordHasher                $passwords              Hasher that produces the stored password
     *         hash and performs the sign-in comparison.
     * @param  TransactionManager            $transactions           Scope each provisioning, issuance and
     *         rotation write runs inside.
     * @param  ClockInterface                $clock                  Source of the timestamps stamped on rows,
     *         audit events and token expiries.
     * @param  AuthenticationRateLimiter     $rateLimiter            Throttle counting sign-in attempts per
     *         account and origin.
     * @param  AuditRecorder                 $audit                  Sink the provisioning, issuance and
     *         rotation events are recorded to.
     * @param  AccessTokenQuotaPolicy        $quota                  Ceiling on live tokens per subject, site,
     *         audience and purpose.
     * @param  string                        $applicationSecret      Installation secret keying the HMACs that
     *         stand in for the address and the origin at the throttle.
     * @param  AuthorizationGateway          $authorization          Judge of the bootstrap authority the
     *         administrator provisioning path demands.
     * @param  AuthorizationPolicyRegistry   $authorizationPolicies  Live typed catalog from which the
     *         administrator role derives enforceable human core capabilities.
     * @param  TokenDelegationPreauthorizer  $tokenDelegation        Delegation check every issuance clears,
     *         once before the transaction and once inside it.
     * @param  TokenRotationPreauthorizer    $tokenRotation          Rotation check resolving the superseded
     *         token's subject, scope and capabilities.
     * @param  ResourceSiteOwnershipWriter   $ownership              Writer recording which site owns each
     *         user, role, grant and token created here.
     * @param  object                        $provenance             Composition-root authority every principal
     *         minted here is stamped with; anything else is untrusted.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private PasswordHasher $passwords,
        private TransactionManager $transactions,
        private ClockInterface $clock,
        private AuthenticationRateLimiter $rateLimiter,
        private AuditRecorder $audit,
        private AccessTokenQuotaPolicy $quota,
        private string $applicationSecret,
        private AuthorizationGateway $authorization,
        private AuthorizationPolicyRegistry $authorizationPolicies,
        private TokenDelegationPreauthorizer $tokenDelegation,
        private TokenRotationPreauthorizer $tokenRotation,
        private ResourceSiteOwnershipWriter $ownership,
        private object $provenance,
    ) {
    }

    /**
     * Verify an address and password against the credential table and describe who they belong to.
     *
     * The throttle is asked before the stored hash is read and told the outcome once it is compared, so
     * a run of wrong passwords is stopped before another hash comparison is paid for. An unknown
     * address, a non-active account and a wrong password are indistinguishable in the answer: each is
     * reported to the throttle as a failed attempt and returns null, so nothing here tells a caller
     * whether the account exists. A principal that is returned carries the grants at the reach they were
     * recorded with, and the security epoch stored on the user row.
     *
     * @param   string  $email     Address the sign-in was attempted with, in any casing; normalised
     *          before it is looked up and only its keyed digest reaches the throttle.
     * @param   string  $password  Plaintext password as submitted, compared against the stored hash.
     * @param   string  $source    Origin the attempt came from; an empty or blank value is counted as
     *          `unknown`, and only its keyed digest reaches the throttle.
     *
     * @return  ?AuthenticatedPrincipal  The actor with its scoped grants and security epoch, or null when
     *          the credential names no active account.
     *
     * @throws  \Kumwe\App\Identity\Application\Administration\AuthenticationThrottled  When this account
     *          and origin pair has already spent its attempt budget.
     * @throws  InvalidArgumentException  When the address is not syntactically valid, or the stored
     *          security epoch or grant rows do not assemble into a principal.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the credential or grant lookup.
     *
     * @since   2.0.0
     */
    public function authenticate(string $email, string $password, string $source): ?AuthenticatedPrincipal
    {
        $normalized = EmailAddress::fromString($email)->value();
        $sourceDigest = hash_hmac('sha256', trim($source) === '' ? 'unknown' : $source, $this->applicationSecret);
        $subjectDigest = hash_hmac('sha256', $normalized, $this->applicationSecret);
        $this->rateLimiter->assertAllowed($subjectDigest, $sourceDigest);

        $row = $this->database->fetchAssociative(sprintf(
            'SELECT u.id, u.security_epoch, p.password_hash FROM %s u INNER JOIN %s p ON p.user_id = u.id '
            . "WHERE u.email_normalized = ? AND u.status = 'active'",
            $this->tables->quoted('users'),
            $this->tables->quoted('password_credentials'),
        ), [$normalized]);
        $valid = $row !== false
            && is_string($row['password_hash'] ?? null)
            && $this->passwords->verify($password, $row['password_hash']);
        $this->rateLimiter->record($subjectDigest, $sourceDigest, $valid);

        if (!$valid || !is_string($row['id'] ?? null)) {
            return null;
        }

        return AuthenticatedPrincipal::issueFromGrantRows(
            $this->provenance,
            $row['id'],
            $this->grantsFor($row['id']),
            'password:' . $row['id'],
            $this->positiveInteger($row['security_epoch'] ?? null),
        );
    }

    /**
     * Provision an administrator on the host bootstrap authority, holding the full administrator role.
     *
     * Despite the name this is not limited to the first account: every call after the first reuses the
     * canonical `administrator` role rather than creating another. The whole provisioning runs in one
     * transaction that opens by locking the default site row, so two concurrent bootstraps serialise
     * instead of both inserting, and it refuses before the first write when the capability catalogue is
     * missing anything the role must grant — which turns an un-migrated database into a clear message
     * rather than a half-granted account. Each created user, role and grant gets a default-site
     * ownership row, and the whole operation is audited as `administrator.create`.
     *
     * @param   ExecutionContext  $context      Bootstrap authority; must carry `administrator.bootstrap`
     *          rather than an ordinary administrator's grants.
     * @param   string            $email        Address the new administrator signs in with; refused when
     *          a user already holds it.
     * @param   string            $displayName  Human-readable name, trimmed, of 1 to 191 characters.
     * @param   string            $password     Plaintext password, stored only as a hash.
     *
     * @return  string  UUID of the created user, already assigned the administrator role.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the context does not carry
     *          the bootstrap capability.
     * @throws  InvalidArgumentException  When the display name is empty or over 191 characters, the
     *          address is malformed or already taken, or the password cannot be hashed.
     * @throws  RuntimeException  When the default site row is absent, the capability catalogue is missing
     *          an administrator capability, or the stored administrator role identifier is unusable.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects one of the reads or inserts.
     *
     * @since   2.0.0
     */
    public function createInitialAdministrator(
        ExecutionContext $context,
        string $email,
        string $displayName,
        string $password,
    ): string {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('administrator.bootstrap'),
            AuthorizationResource::collection('administrator'),
        );
        $address = EmailAddress::fromString($email);
        $displayName = trim($displayName);

        if ($displayName === '' || mb_strlen($displayName) > 191) {
            throw new InvalidArgumentException('The administrator display name must contain 1 to 191 characters.');
        }

        $passwordHash = $this->passwords->hash($password);

        return $this->transactions->transactional(function () use (
            $context,
            $address,
            $displayName,
            $passwordHash,
        ): string {
            $this->lockAdministratorProvisioning();
            $this->assertAdministratorCapabilitiesAvailable();
            $this->assertEmailAvailable($address);

            $userId = Uuid::uuid7()->toString();
            $now = $this->clock->now();
            $this->database->insert($this->tables->raw('users'), [
                'id' => $userId,
                'email' => $address->value(),
                'email_normalized' => $address->value(),
                'display_name' => $displayName,
                'status' => 'active',
                'version' => 1,
                'security_epoch' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ], ['created_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE]);
            $this->ownership->record(AuthorizationResource::item('user', $userId), SiteContext::default());
            $this->database->insert($this->tables->raw('password_credentials'), [
                'user_id' => $userId,
                'password_hash' => $passwordHash,
                'changed_at' => $now,
            ], ['changed_at' => Types::DATETIME_IMMUTABLE]);

            $roleId = $this->administratorRoleId($now);
            $this->ensureAdministratorGrants($roleId, $userId, $now);
            $this->database->insert($this->tables->raw('user_roles'), [
                'user_id' => $userId,
                'role_id' => $roleId,
                'assigned_at' => $now,
                'assigned_by' => $userId,
            ], ['assigned_at' => Types::DATETIME_IMMUTABLE]);
            $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $now,
                $context->actorId(),
                'administrator.create',
                'user',
                $userId,
                'success',
                [
                    'email' => $address->value(),
                    'role_id' => $roleId,
                    'authority' => 'host-bootstrap',
                ],
            ));

            return $userId;
        });
    }

    /**
     * Serialise concurrent bootstraps by taking the default site row for update.
     *
     * SQLite has no row-level `FOR UPDATE`, so there the statement degrades to a plain existence check
     * and leans on the transaction's own write locking instead. Either way the row doubles as proof that
     * the schema migrations have been run at all, which is the more common reason this refuses.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the default site row is missing, so there is nothing to lock and
     *          nothing for the administrator to belong to.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the locking read.
     *
     * @since   2.0.0
     */
    private function lockAdministratorProvisioning(): void
    {
        $lock = $this->database->getDatabasePlatform() instanceof SQLitePlatform
            ? ''
            : ' FOR UPDATE';
        $site = $this->database->fetchOne(sprintf(
            'SELECT identifier FROM %s WHERE identifier = ?%s',
            $this->tables->quoted('sites'),
            $lock,
        ), [SiteContext::DEFAULT]);

        if ($site !== SiteContext::DEFAULT) {
            throw new RuntimeException(
                'The default site is unavailable. Run database:migrate before creating an administrator.',
            );
        }
    }

    /**
     * Refuse to provision unless every administrator capability exists in the catalogue.
     *
     * Run before the first insert, so a `capabilities` table left behind by an older release produces a
     * refusal naming exactly what is missing, rather than an administrator silently holding a partial
     * set of the grants the role is supposed to carry.
     *
     * @return  void
     *
     * @throws  RuntimeException  When one or more capabilities are absent; the message lists them.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the catalogue read.
     *
     * @since   2.0.0
     */
    private function assertAdministratorCapabilitiesAvailable(): void
    {
        $required = $this->administratorCapabilities();
        if ($required === []) {
            throw new RuntimeException('The live authorization registry has no administrator capabilities.');
        }
        $placeholders = implode(', ', array_fill(0, count($required), '?'));
        $available = $this->database->fetchFirstColumn(sprintf(
            'SELECT code FROM %s WHERE code IN (%s)',
            $this->tables->quoted('capabilities'),
            $placeholders,
        ), $required);
        $missing = array_values(array_diff(
            $required,
            array_values(array_filter($available, 'is_string')),
        ));

        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'The database is missing administrator capabilities (%s). Run database:migrate before retrying.',
                implode(', ', $missing),
            ));
        }
    }

    /**
     * Refuse the provisioning when the normalised address already belongs to a user.
     *
     * It runs inside the transaction that holds the site lock, so a competing bootstrap cannot slip
     * between this check and the insert that follows it. The message names the address, which is safe
     * here because the caller already holds the host bootstrap authority.
     *
     * @param   EmailAddress  $address  Already normalised address the new administrator would sign in with.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a user row already carries that normalised address.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the lookup.
     *
     * @since   2.0.0
     */
    private function assertEmailAvailable(EmailAddress $address): void
    {
        $existing = $this->database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE email_normalized = ?',
            $this->tables->quoted('users'),
        ), [$address->value()]);

        if ($existing !== false) {
            throw new InvalidArgumentException(sprintf(
                'A user with email %s already exists. Use a different email for a new administrator.',
                $address->value(),
            ));
        }
    }

    /**
     * Return the canonical `administrator` role identifier, creating the role on first use.
     *
     * Every bootstrap after the first reuses the existing row, which is what keeps all administrators on
     * one role instead of accumulating a role per account. A role this call creates is given a
     * default-site ownership row alongside it.
     *
     * @param   DateTimeImmutable  $now  Timestamp stamped on the role when this call is the one to create it.
     *
     * @return  string  UUID of the role, whether it was found or just created.
     *
     * @throws  RuntimeException  When the stored role identifier is not a usable non-empty string.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the lookup or the insert.
     *
     * @since   2.0.0
     */
    private function administratorRoleId(DateTimeImmutable $now): string
    {
        $existing = $this->database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE code = ?',
            $this->tables->quoted('roles'),
        ), ['administrator']);

        if ($existing !== false) {
            if (!is_string($existing) || $existing === '') {
                throw new RuntimeException('The administrator role identifier is invalid.');
            }

            return $existing;
        }

        $roleId = Uuid::uuid7()->toString();
        $this->database->insert($this->tables->raw('roles'), [
            'id' => $roleId,
            'code' => 'administrator',
            'name' => 'Administrator',
            'created_at' => $now,
        ], ['created_at' => Types::DATETIME_IMMUTABLE]);
        $this->ownership->record(AuthorizationResource::item('role', $roleId), SiteContext::default());

        return $roleId;
    }

    /**
     * Give the administrator role every capability in the set, skipping the ones it already holds.
     *
     * Each missing capability becomes one global grant with its own default-site ownership row, so a
     * role provisioned by an earlier release picks up capabilities added since without duplicating the
     * grants that are already recorded.
     *
     * @param   string             $roleId  UUID of the administrator role receiving the grants.
     * @param   string             $userId  UUID recorded as the granting actor on each new grant.
     * @param   DateTimeImmutable  $now     Timestamp stamped on each grant this call creates.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects one of the lookups or inserts.
     *
     * @since   2.0.0
     */
    private function ensureAdministratorGrants(string $roleId, string $userId, DateTimeImmutable $now): void
    {
        foreach ($this->administratorCapabilities() as $capability) {
            $existing = $this->database->fetchOne(sprintf(
                'SELECT id FROM %s WHERE role_id = ? AND capability_code = ? '
                . "AND scope_type = 'global' AND scope_identifier IS NULL LIMIT 1",
                $this->tables->quoted('role_capability_grants'),
            ), [$roleId, $capability]);
            if ($existing !== false) {
                continue;
            }

            $grantId = Uuid::uuid7()->toString();
            $this->database->insert($this->tables->raw('role_capability_grants'), [
                'id' => $grantId,
                'role_id' => $roleId,
                'capability_code' => $capability,
                'scope_type' => 'global',
                'scope_identifier' => null,
                'granted_at' => $now,
                'granted_by' => $userId,
            ], ['granted_at' => Types::DATETIME_IMMUTABLE]);
            $this->ownership->record(
                AuthorizationResource::item('grant', $grantId),
                SiteContext::default(),
            );
        }
    }

    /**
     * Derive the administrator role from enforceable human capabilities published by core.
     *
     * System-only bootstrap and worker capabilities carry no human grant scopes and are therefore
     * excluded by their typed definition rather than by another denylist. Extension capabilities are
     * also excluded: installation administrators may grant them explicitly but installing a package
     * must not silently expand every administrator's authority.
     *
     * @return  list<string>  Deterministically ordered core capability identifiers safe for human grants.
     *
     * @since   2.0.0
     */
    private function administratorCapabilities(): array
    {
        $capabilities = [];
        foreach ($this->authorizationPolicies->capabilityDefinitions()->ownedBy('core') as $definition) {
            if ($definition->enforceable() && $definition->allowsHumanGrant()) {
                $capabilities[] = $definition->capability->value();
            }
        }

        return $capabilities;
    }

    /**
     * Read a stored security epoch that reaches PHP as either a native integer or a decimal string.
     *
     * Drivers disagree on whether an integer column comes back typed, so both spellings are accepted: an
     * `int` is taken as it stands, a string only when it is a positive decimal without a leading zero.
     * Anything else is a corrupt row rather than a value to coerce, because the epoch is what a
     * revocation raises to invalidate every credential already issued to the user.
     *
     * @param   mixed  $value  Raw `security_epoch` column value exactly as the driver returned it.
     *
     * @return  int  The epoch, cast from the string form when that is what the driver produced.
     *
     * @throws  InvalidArgumentException  When the value is neither an integer nor a string of digits
     *          beginning with 1 through 9.
     *
     * @since   2.0.0
     */
    private function positiveInteger(mixed $value): int
    {
        if (!is_int($value) && (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1)) {
            throw new InvalidArgumentException('Stored user security epoch is invalid.');
        }

        return (int) $value;
    }

    /**
     * Normalize an optional positive version or policy generation read from a token row.
     *
     * @param   mixed  $value  Nullable native integer or decimal string returned by the driver.
     *
     * @return  ?int  Null when absent, otherwise the positive normalized generation.
     *
     * @throws  RuntimeException  When a non-null value is not a positive integer.
     *
     * @since   2.0.0
     */
    private function nullableTokenGeneration(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!is_int($value) && (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1)) {
            throw new RuntimeException('A stored token authority generation is invalid.');
        }

        return (int) $value;
    }

    /**
     * Mint a bearer token for a subject and return the plaintext secret, which exists only here.
     *
     * Nothing but the token's SHA-256 reaches `api_tokens`, so a caller that loses this return value has
     * to issue another token. The delegation check runs twice — once before the transaction and once
     * inside it with the subject's user row locked — and the capability set is then re-tested against the
     * subject's own grants for the site, so authority revoked mid-request cannot be captured. The row is
     * stamped with the epoch read under that lock, which is what a later revocation invalidates it by,
     * and the live-token count handed to the quota policy excludes the token a rotation is replacing.
     *
     * @param   ExecutionContext    $context       Actor and site the token is issued under and confined to.
     * @param   string              $email         Address of the subject the token will act as.
     * @param   string              $name          Operator-facing label, trimmed, of 1 to 191 characters.
     * @param   list<string>        $capabilities  Capabilities the token may exercise; each must already
     *          be granted to the subject and be delegable by the actor.
     * @param   ?DateTimeImmutable  $expiresAt     Expiry to set, or null for 30 days out; it must be in
     *          the future and no more than 90 days away.
     * @param   string              $audience      Consumer the token is accepted by, such as `kumwe-http`.
     * @param   string              $purpose       Why the token exists, such as `api`; it partitions the
     *          per-subject quota alongside the audience.
     * @param   ?string             $rotatedFrom   UUID of the token being replaced, excluded from the
     *          quota count, or null for a fresh issue.
     *
     * @return  array{token: string, token_id: string}  The plaintext secret under `token`, seen only
     *          here, and the stored row's UUID under `token_id`.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not act for
     *          the subject, or may not delegate one of the capabilities.
     * @throws  InvalidArgumentException  When the name, expiry, `rotatedFrom` identifier or capability set
     *          is unusable, the subject does not hold a requested capability, or the quota is full.
     * @throws  RuntimeException  When the subject's epoch or the live-token count cannot be read, or the
     *          delegation resolved to a different subject once the row was locked.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects one of the reads or the insert.
     *
     * @since   2.0.0
     */
    public function issueAccessToken(
        ExecutionContext $context,
        string $email,
        string $name,
        array $capabilities,
        ?DateTimeImmutable $expiresAt = null,
        string $audience = 'kumwe-http',
        string $purpose = 'api',
        ?string $rotatedFrom = null,
    ): array {
        $actorId = $context->actorId();
        $siteIdentifier = $context->site()->identifier();
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 191) {
            throw new InvalidArgumentException('A token name and at least one capability are required.');
        }
        $delegation = $this->tokenDelegation->authorize($context, $email, $capabilities);
        $userId = $delegation->subjectId;
        $tokenContext = AccessTokenContext::fromStrings($audience, $purpose);
        $audience = $tokenContext->audience;
        $purpose = $tokenContext->purpose;
        $siteIdentifier = SiteContext::fromString($siteIdentifier)->identifier();

        $uniqueCapabilities = $delegation->capabilities;
        $organizationIdentifier = $delegation->organization;
        $workspaceIdentifier = $delegation->workspace;
        $membershipId = $delegation->membershipId;
        $membershipVersion = $delegation->membershipVersion;
        $policyGeneration = $delegation->policyGeneration;
        foreach ($uniqueCapabilities as $capability) {
            if (
                $this->authorizationPolicies->requiresMembershipContext(Capability::fromString($capability))
                && $membershipId === null
            ) {
                throw new InvalidArgumentException(
                    'Organization-sensitive tokens require an exact organization membership context.',
                );
            }
        }

        $now = $this->clock->now();
        $expiresAt ??= $now->modify(self::DEFAULT_TOKEN_LIFETIME);
        if ($expiresAt <= $now || $expiresAt > $now->modify(self::MAXIMUM_TOKEN_LIFETIME)) {
            throw new InvalidArgumentException('API tokens must expire in the future and within 90 days.');
        }
        if ($rotatedFrom !== null && !Uuid::isValid($rotatedFrom)) {
            throw new InvalidArgumentException('The rotated-from token ID must be a canonical UUID.');
        }

        $token = $this->base64Url(random_bytes(48));
        $tokenId = Uuid::uuid7()->toString();
        $this->transactions->transactional(function () use (
            $tokenId,
            $userId,
            $token,
            $name,
            $uniqueCapabilities,
            $expiresAt,
            $now,
            $actorId,
            $audience,
            $purpose,
            $siteIdentifier,
            $rotatedFrom,
            $context,
            $email,
            $organizationIdentifier,
            $workspaceIdentifier,
            $membershipId,
            $membershipVersion,
            $policyGeneration,
        ): void {
            $epoch = $this->database->fetchOne(sprintf(
                "SELECT security_epoch FROM %s WHERE id = ? AND status = 'active'%s",
                $this->tables->quoted('users'),
                $this->database->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE',
            ), [$userId]);
            if (!is_int($epoch) && (!is_string($epoch) || preg_match('/^[0-9]+$/D', $epoch) !== 1)) {
                throw new RuntimeException('The user security epoch could not be locked.');
            }
            $lockedDelegation = $this->tokenDelegation->authorize($context, $email, $uniqueCapabilities, true);
            if (
                $lockedDelegation->subjectId !== $userId
                || $lockedDelegation->organization !== $organizationIdentifier
                || $lockedDelegation->workspace !== $workspaceIdentifier
                || $lockedDelegation->membershipId !== $membershipId
                || $lockedDelegation->membershipVersion !== $membershipVersion
                || $lockedDelegation->policyGeneration !== $policyGeneration
            ) {
                throw new RuntimeException('The token subject authority changed during issuance.');
            }
            $quotaSql = 'SELECT COUNT(*) FROM %s WHERE subject_id = ? AND site_identifier = ? '
                . 'AND audience = ? AND purpose = ? AND security_epoch = ? ';
            $quotaParameters = [
                $userId,
                $siteIdentifier,
                $audience,
                $purpose,
                (int) $epoch,
            ];
            if ($organizationIdentifier === null) {
                $quotaSql .= 'AND organization_identifier IS NULL ';
            } else {
                $quotaSql .= 'AND organization_identifier = ? ';
                $quotaParameters[] = $organizationIdentifier;
            }
            if ($workspaceIdentifier === null) {
                $quotaSql .= 'AND workspace_identifier IS NULL ';
            } else {
                $quotaSql .= 'AND workspace_identifier = ? ';
                $quotaParameters[] = $workspaceIdentifier;
            }
            $quotaSql .= 'AND revoked_at IS NULL AND expires_at > CURRENT_TIMESTAMP';
            if ($rotatedFrom !== null) {
                $quotaSql .= ' AND id <> ?';
                $quotaParameters[] = $rotatedFrom;
            }
            $activeCount = $this->database->fetchOne(sprintf(
                $quotaSql,
                $this->tables->quoted('api_tokens'),
            ), $quotaParameters);
            if (
                !is_int($activeCount)
                && (!is_string($activeCount) || preg_match('/^[0-9]+$/D', $activeCount) !== 1)
            ) {
                throw new RuntimeException('The active token quota could not be read.');
            }
            $this->quota->assertAllowed(
                $userId,
                $siteIdentifier,
                $audience,
                $purpose,
                (int) $activeCount,
            );
            $familyId = $tokenId;
            $parentTokenId = null;
            $delegationDepth = 0;
            if ($rotatedFrom !== null) {
                $parent = $this->database->fetchAssociative(sprintf(
                    'SELECT family_id, delegation_depth, site_identifier, organization_identifier, '
                    . 'workspace_identifier, membership_id, membership_version, policy_generation '
                    . 'FROM %s WHERE id = ? AND revoked_at IS NULL%s',
                    $this->tables->quoted('api_tokens'),
                    $this->database->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform
                        ? ''
                        : ' FOR UPDATE',
                ), [$rotatedFrom]);
                if ($parent === false) {
                    throw new InvalidArgumentException('A replacement token must inherit its exact parent scope.');
                }
                $parentMembershipVersion = $this->nullableTokenGeneration(
                    $parent['membership_version'] ?? null,
                );
                $parentPolicyGeneration = $this->nullableTokenGeneration(
                    $parent['policy_generation'] ?? null,
                );
                if (
                    !is_string($parent['family_id'] ?? null)
                    || ($parent['site_identifier'] ?? null) !== $siteIdentifier
                    || ($parent['organization_identifier'] ?? null) !== $organizationIdentifier
                    || ($parent['workspace_identifier'] ?? null) !== $workspaceIdentifier
                    || ($parent['membership_id'] ?? null) !== $membershipId
                    || $parentMembershipVersion !== $membershipVersion
                    || $parentPolicyGeneration !== $policyGeneration
                ) {
                    throw new InvalidArgumentException('A replacement token must inherit its exact parent scope.');
                }
                $depth = $parent['delegation_depth'] ?? null;
                if (!is_int($depth) && (!is_string($depth) || preg_match('/^[0-9]+$/D', $depth) !== 1)) {
                    throw new RuntimeException('The parent token delegation depth is invalid.');
                }
                $parentDepth = (int) $depth;
                if ($parentDepth > 16) {
                    throw new RuntimeException('The parent token delegation depth is invalid.');
                }
                $delegationDepth = $parentDepth;
                $familyId = $parent['family_id'];
                $parentTokenId = $rotatedFrom;
            }
            $this->database->insert($this->tables->raw('api_tokens'), [
                'id' => $tokenId,
                'subject_id' => $userId,
                'token_digest' => hash('sha256', $token),
                'name' => $name,
                'capabilities' => $uniqueCapabilities,
                'security_epoch' => (int) $epoch,
                'audience' => $audience,
                'purpose' => $purpose,
                'site_identifier' => $siteIdentifier,
                'rotated_from' => $rotatedFrom,
                'organization_identifier' => $organizationIdentifier,
                'workspace_identifier' => $workspaceIdentifier,
                'membership_id' => $membershipId,
                'membership_version' => $membershipVersion,
                'policy_generation' => $policyGeneration,
                'family_id' => $familyId,
                'parent_token_id' => $parentTokenId,
                'delegation_depth' => $delegationDepth,
                'owner_identifier' => 'core',
                'expires_at' => $expiresAt,
                'revoked_at' => null,
                'revocation_reason' => null,
                'created_at' => $now,
                'last_used_at' => null,
            ], [
                'capabilities' => Types::JSON,
                'expires_at' => Types::DATETIME_IMMUTABLE,
                'created_at' => Types::DATETIME_IMMUTABLE,
            ]);
            $this->ownership->record(
                AuthorizationResource::item('api_token', $tokenId),
                $context->site(),
            );
            $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $now,
                $actorId,
                'token.create',
                'api_token',
                $tokenId,
                'success',
                [
                    'subject_id' => $userId,
                    'capabilities' => $uniqueCapabilities,
                    'audience' => $audience,
                    'purpose' => $purpose,
                    'site_identifier' => $siteIdentifier,
                    'organization_identifier' => $organizationIdentifier,
                    'workspace_identifier' => $workspaceIdentifier,
                    'membership_id' => $membershipId,
                    'membership_version' => $membershipVersion,
                    'policy_generation' => $policyGeneration,
                    'family_id' => $familyId,
                    'parent_token_id' => $parentTokenId,
                    'expires_at' => $expiresAt->format(DATE_ATOM),
                    'rotated_from' => $rotatedFrom,
                ],
            ));
        });

        return ['token' => $token, 'token_id' => $tokenId];
    }

    /**
     * Replace a live token with a fresh secret carrying exactly the authority the old one had.
     *
     * Subject, capabilities, audience and purpose are read off the superseded token rather than taken
     * from the caller, so a rotation can never widen what the credential may do. The rotation check runs
     * once before the transaction to fail early and again inside it with the row locked; the replacement
     * is issued and the old token revoked as `rotated` in that same transaction, so a caller that loses
     * the response has lost both and must issue afresh. The swap is audited as `token.rotate`.
     *
     * @param   ExecutionContext    $context    Actor and site the rotation runs under; the token must
     *          belong to that site.
     * @param   string              $tokenId    UUID of the live token being replaced.
     * @param   string              $name       Operator-facing label for the replacement.
     * @param   ?DateTimeImmutable  $expiresAt  Expiry for the replacement, or null for the default
     *          lifetime; the superseded token's remaining life is not carried over.
     *
     * @return  array{token: string, token_id: string}  The replacement's plaintext secret under `token`
     *          and its new UUID under `token_id`.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage
     *          the token, or may no longer delegate the capabilities it carries.
     * @throws  InvalidArgumentException  When the token is absent, already dead, outside the site, the
     *          replacement's name or expiry is unusable, or the subject's quota refuses the replacement.
     * @throws  RuntimeException  When the superseded token was revoked by someone else between the check
     *          and the update, or the issuance it wraps could not lock the subject or read its quota.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects one of the reads, the insert or the update.
     *
     * @since   2.0.0
     */
    public function rotateAccessToken(
        ExecutionContext $context,
        string $tokenId,
        string $name,
        ?DateTimeImmutable $expiresAt,
    ): array {
        $actorId = $context->actorId();
        $this->tokenRotation->authorize($context, $tokenId);

        return $this->transactions->transactional(function () use (
            $context,
            $name,
            $expiresAt,
            $actorId,
            $tokenId,
        ): array {
            $rotation = $this->tokenRotation->authorize($context, $tokenId, true);
            $replacementExpiry = $expiresAt ?? $rotation->expiresAt;
            if ($replacementExpiry > $rotation->expiresAt) {
                throw new InvalidArgumentException('A token rotation cannot extend its credential lifetime.');
            }
            $created = $this->issueAccessToken(
                $context,
                $rotation->email,
                $name,
                $rotation->capabilities,
                $replacementExpiry,
                $rotation->audience,
                $rotation->purpose,
                $tokenId,
            );
            $now = $this->clock->now();
            $affected = $this->database->executeStatement(sprintf(
                'UPDATE %s SET revoked_at = ?, revocation_reason = ? WHERE id = ? AND revoked_at IS NULL',
                $this->tables->quoted('api_tokens'),
            ), [$now, 'rotated', $tokenId], [Types::DATETIME_IMMUTABLE, Types::STRING, Types::GUID]);
            if ($affected !== 1) {
                throw new RuntimeException('The replaced token changed during rotation.');
            }
            $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $now,
                $actorId,
                'token.rotate',
                'api_token',
                $tokenId,
                'success',
                ['replacement_token_id' => $created['token_id']],
            ));
            return $created;
        });
    }

    /**
     * Read every role grant the user holds, keeping the reach each one was recorded at.
     *
     * This is what `AuthenticatedPrincipal::issueFromGrantRows()` consumes after a successful sign-in, so
     * a capability granted over a single resource stays confined to it instead of being promoted to a
     * global grant on the way into the principal.
     *
     * @param   string  $userId  UUID of the user whose role grants are read.
     *
     * @return  list<array{capability: string, scope_type: string, scope_identifier: ?string}>
     *          One row per distinct grant, ordered by capability, scope type and scope identifier; empty
     *          when the user's roles grant nothing.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the read.
     *
     * @since   2.0.0
     */
    private function grantsFor(string $userId): array
    {
        /** @var list<array{capability: string, scope_type: string, scope_identifier: ?string}> */
        return $this->database->fetchAllAssociative(sprintf(
            'SELECT DISTINCT g.capability_code AS capability, g.scope_type, g.scope_identifier '
            . 'FROM %s ur INNER JOIN %s g ON g.role_id = ur.role_id WHERE ur.user_id = ? '
            . 'ORDER BY g.capability_code, g.scope_type, g.scope_identifier',
            $this->tables->quoted('user_roles'),
            $this->tables->quoted('role_capability_grants'),
        ), [$userId]);
    }

    /**
     * Render random bytes as unpadded base64url, the alphabet the token verifier accepts back.
     *
     * `+` and `/` are swapped for `-` and `_` and the `=` padding is dropped, so the secret survives an
     * `Authorization` header, a URL and a configuration file unescaped, and still matches the character
     * class `DoctrineAccessTokenVerifier` screens a presented token against.
     *
     * @param   string  $bytes  Raw random bytes to encode.
     *
     * @return  string  URL-safe, unpadded base64 text.
     *
     * @since   2.0.0
     */
    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
