<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Infrastructure\Session;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Identity\Application\StepUp\StepUpRejected;
use Kumwe\App\Identity\Application\StepUp\StepUpSessionRotator;
use Kumwe\App\Identity\Domain\StepUp\RotatedStepUpSession;
use Kumwe\App\Identity\Domain\StepUp\StepUpIntent;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Portal\Application\CreatedPortalSession;
use Kumwe\App\Portal\Application\PortalPasswordIdentity;
use Kumwe\App\Portal\Application\PortalSession;
use Kumwe\App\Portal\Application\PortalSessionIdentity;
use Kumwe\App\Portal\Application\PortalSessionIdentityLoader;
use Kumwe\App\Portal\Application\PortalSessionStore;
use Kumwe\App\Portal\Application\PortalContext;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

/**
 * Dedicated digest-backed portal session store with live membership reload and atomic step-up rotation.
 *
 * It never reads or writes `administrator_sessions`. Portal rows carry their own site, organization,
 * membership, and workspace selection, and `PortalSessionIdentityLoader` revalidates those coordinates
 * plus the user's current roles on every resolution. Rotation inserts a fresh row and deletes the old
 * identifier in one transaction, issuing independent cookie and CSRF secrets. Each session and its
 * authoritative site-ownership row are also created, rotated, and removed in the same transaction.
 *
 * @since  2.0.0
 */
final readonly class DoctrinePortalSessionStore implements PortalSessionStore, StepUpSessionRotator
{
    /**
     * Bind the store to persistence, live identity resolution, time, and a user-agent HMAC key.
     *
     * @param   Connection                   $database      Shared DBAL connection.
     * @param   TableNames                   $tables        Installation table-prefix mapper.
     * @param   PortalSessionIdentityLoader  $identities    Live user and membership loader.
     * @param   ClockInterface               $clock         Trusted UTC time source.
     * @param   ResourceSiteOwnershipWriter  $ownership     Portal-session site-ownership writer.
     * @param   TransactionManager           $transactions  Shared nesting-aware transaction coordinator.
     * @param   string                       $bindingKey    Dedicated raw key of at least 32 bytes.
     * @param   DateInterval                 $lifetime      Absolute portal session lifetime, 5 minutes through 7 days.
     *
     * @throws  InvalidArgumentException  When the key or lifetime falls outside its security bound.
     *
     * @since   2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private PortalSessionIdentityLoader $identities,
        private ClockInterface $clock,
        private ResourceSiteOwnershipWriter $ownership,
        private TransactionManager $transactions,
        private string $bindingKey,
        private DateInterval $lifetime = new DateInterval('PT8H'),
    ) {
        if (strlen($bindingKey) < 32) {
            throw new InvalidArgumentException('The portal session binding key must contain at least 32 bytes.');
        }
        $now = $clock->now();
        $seconds = $now->add($lifetime)->getTimestamp() - $now->getTimestamp();
        if ($seconds < 300 || $seconds > 604_800) {
            throw new InvalidArgumentException('The portal session lifetime must be 5 minutes through 7 days.');
        }
    }

    /**
     * Persist a new portal session and disclose its cookie token once.
     *
     * @param   PortalPasswordIdentity  $identity   Password-authenticated actor and epoch.
     * @param   PortalContext           $context    Server-issued site and membership selection.
     * @param   string                  $userAgent  Browser user agent bound by HMAC.
     *
     * @return  CreatedPortalSession  Session metadata and one-time cookie token.
     *
     * @since   2.0.0
     */
    public function create(
        PortalPasswordIdentity $identity,
        PortalContext $context,
        string $userAgent,
    ): CreatedPortalSession {
        $now = $this->clock->now();
        $expiresAt = $now->add($this->lifetime);
        $sessionId = Uuid::uuid7()->toString();
        $cookieToken = self::token(48);
        $csrfToken = self::token(32);
        $membership = $context->membership;
        $this->transactions->transactional(function () use (
            $identity,
            $context,
            $userAgent,
            $now,
            $expiresAt,
            $sessionId,
            $cookieToken,
            $csrfToken,
            $membership,
        ): void {
            $this->database->insert($this->tables->raw('portal_sessions'), [
                'id' => $sessionId,
                'user_id' => $identity->principal->subject(),
                'token_digest' => hash('sha256', $cookieToken),
                'csrf_token' => $csrfToken,
                'site_identifier' => $context->site->identifier(),
                'organization_identifier' => $membership?->organization()->identifier(),
                'membership_id' => $membership?->membershipId(),
                'workspace_identifier' => $membership?->workspace()?->identifier(),
                'membership_version' => $membership?->membershipVersion(),
                'policy_generation' => $membership?->policyGeneration(),
                'security_epoch' => $identity->securityEpoch,
                'user_agent_digest' => $this->userAgentDigest($userAgent),
                'created_at' => $now,
                'last_seen_at' => $now,
                'authenticated_at' => $now,
                'step_up_at' => null,
                'expires_at' => $expiresAt,
            ], [
                'created_at' => Types::DATETIME_IMMUTABLE,
                'last_seen_at' => Types::DATETIME_IMMUTABLE,
                'authenticated_at' => Types::DATETIME_IMMUTABLE,
                'expires_at' => Types::DATETIME_IMMUTABLE,
            ]);
            $this->ownership->record(
                AuthorizationResource::item('portal_session', $sessionId),
                $context->site,
            );
        });

        return new CreatedPortalSession(
            new PortalSession(
                $sessionId,
                new PortalSessionIdentity($identity->principal, $context, $identity->securityEpoch),
                $csrfToken,
                $now,
                null,
                $expiresAt,
            ),
            $cookieToken,
        );
    }

    /**
     * Resolve a portal cookie, bind it to its browser, and reload live identity and membership state.
     *
     * @param   string  $cookieToken  Opaque browser token.
     * @param   string  $userAgent    Presenting browser user agent.
     *
     * @return  ?PortalSession  Live session or null for every unknown, stale, or mismatched state.
     *
     * @since   2.0.0
     */
    public function find(string $cookieToken, string $userAgent): ?PortalSession
    {
        if (
            strlen($cookieToken) < 43
            || strlen($cookieToken) > 512
            || preg_match('/^[A-Za-z0-9_-]+$/D', $cookieToken) !== 1
        ) {
            return null;
        }
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT id, user_id, csrf_token, site_identifier, organization_identifier, membership_id, '
            . 'workspace_identifier, membership_version, policy_generation, security_epoch, user_agent_digest, '
            . 'authenticated_at, step_up_at, expires_at '
            . 'FROM %s WHERE token_digest = ?',
            $this->tables->quoted('portal_sessions'),
        ), [hash('sha256', $cookieToken)]);
        if (!is_array($row)) {
            return null;
        }
        $now = $this->clock->now();
        $expiresAt = $this->date($row['expires_at'] ?? null);
        $storedDigest = $this->requiredString($row, 'user_agent_digest');
        if ($expiresAt <= $now || !hash_equals($storedDigest, $this->userAgentDigest($userAgent))) {
            return null;
        }
        $identity = $this->identities->load(
            $this->requiredString($row, 'user_id'),
            $this->requiredString($row, 'site_identifier'),
            $this->optionalString($row, 'organization_identifier'),
            $this->optionalString($row, 'membership_id'),
            $this->optionalString($row, 'workspace_identifier'),
            $this->requiredString($row, 'id'),
        );
        if (!$identity instanceof PortalSessionIdentity || !$this->contextMatchesRow($identity, $row)) {
            return null;
        }
        $this->database->update(
            $this->tables->raw('portal_sessions'),
            ['last_seen_at' => $now],
            ['id' => $this->requiredString($row, 'id')],
            ['last_seen_at' => Types::DATETIME_IMMUTABLE],
        );

        return new PortalSession(
            $this->requiredString($row, 'id'),
            $identity,
            $this->requiredString($row, 'csrf_token'),
            $this->date($row['authenticated_at'] ?? null),
            $this->nullableDate($row['step_up_at'] ?? null),
            $expiresAt,
        );
    }

    /**
     * Replace an exact live portal session after successful step-up.
     *
     * @param   StepUpIntent       $intent      Old session and exact server-issued context.
     * @param   DateTimeImmutable  $verifiedAt  Successful challenge instant.
     *
     * @return  RotatedStepUpSession  Replacement identity, cookie, CSRF token, and expiry.
     *
     * @throws  StepUpRejected  When the old session or any live identity binding changed.
     *
     * @since   2.0.0
     */
    public function rotate(StepUpIntent $intent, DateTimeImmutable $verifiedAt): RotatedStepUpSession
    {
        return $this->transactions->transactional(function () use ($intent, $verifiedAt): RotatedStepUpSession {
            $row = $this->database->fetchAssociative(sprintf(
                'SELECT id, user_id, site_identifier, organization_identifier, membership_id, '
                . 'workspace_identifier, membership_version, policy_generation, security_epoch, user_agent_digest, '
                . 'authenticated_at, expires_at '
                . 'FROM %s WHERE id = ? AND user_id = ?%s',
                $this->tables->quoted('portal_sessions'),
                $this->lockClause(),
            ), [$intent->sessionId, $intent->subjectId]);
            if (!is_array($row)) {
                throw new StepUpRejected();
            }
            $expiresAt = $this->date($row['expires_at'] ?? null);
            $identity = $this->identities->load(
                $intent->subjectId,
                $this->requiredString($row, 'site_identifier'),
                $this->optionalString($row, 'organization_identifier'),
                $this->optionalString($row, 'membership_id'),
                $this->optionalString($row, 'workspace_identifier'),
                $this->requiredString($row, 'id'),
            );
            if (
                !$identity instanceof PortalSessionIdentity
                || $expiresAt <= $verifiedAt
                || !$this->contextMatchesRow($identity, $row)
                || $identity->securityEpoch !== $intent->securityEpoch
                || $identity->context->site->identifier() !== $intent->siteIdentifier
                || $identity->context->membership?->organization()->identifier() !== $intent->organizationIdentifier
                || $identity->context->membership?->workspace()?->identifier() !== $intent->workspaceIdentifier
            ) {
                throw new StepUpRejected();
            }

            $newId = Uuid::uuid7()->toString();
            $cookieToken = self::token(48);
            $csrfToken = self::token(32);
            $membership = $identity->context->membership;
            $this->database->insert($this->tables->raw('portal_sessions'), [
                'id' => $newId,
                'user_id' => $intent->subjectId,
                'token_digest' => hash('sha256', $cookieToken),
                'csrf_token' => $csrfToken,
                'site_identifier' => $identity->context->site->identifier(),
                'organization_identifier' => $membership?->organization()->identifier(),
                'membership_id' => $membership?->membershipId(),
                'workspace_identifier' => $membership?->workspace()?->identifier(),
                'membership_version' => $membership?->membershipVersion(),
                'policy_generation' => $membership?->policyGeneration(),
                'security_epoch' => $identity->securityEpoch,
                'user_agent_digest' => $this->requiredString($row, 'user_agent_digest'),
                'created_at' => $verifiedAt,
                'last_seen_at' => $verifiedAt,
                'authenticated_at' => $this->date($row['authenticated_at'] ?? null),
                'step_up_at' => $verifiedAt,
                'expires_at' => $expiresAt,
            ], [
                'created_at' => Types::DATETIME_IMMUTABLE,
                'last_seen_at' => Types::DATETIME_IMMUTABLE,
                'authenticated_at' => Types::DATETIME_IMMUTABLE,
                'step_up_at' => Types::DATETIME_IMMUTABLE,
                'expires_at' => Types::DATETIME_IMMUTABLE,
            ]);
            $deleted = $this->database->delete(
                $this->tables->raw('portal_sessions'),
                ['id' => $intent->sessionId, 'user_id' => $intent->subjectId],
            );
            if ($deleted !== 1) {
                throw new StepUpRejected();
            }
            $this->ownership->remove(
                AuthorizationResource::item('portal_session', $intent->sessionId),
                $identity->context->site,
            );
            $this->ownership->record(
                AuthorizationResource::item('portal_session', $newId),
                $identity->context->site,
            );

            return new RotatedStepUpSession($newId, $cookieToken, $csrfToken, $expiresAt);
        });
    }

    /**
     * Delete one session only when its authenticated owner agrees.
     *
     * @param   string  $sessionId  Session UUID.
     * @param   string  $subjectId  Owner UUID.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function delete(string $sessionId, string $subjectId): void
    {
        $this->transactions->transactional(function () use ($sessionId, $subjectId): void {
            $siteIdentifier = $this->database->fetchOne(sprintf(
                'SELECT site_identifier FROM %s WHERE id = ? AND user_id = ?%s',
                $this->tables->quoted('portal_sessions'),
                $this->lockClause(),
            ), [$sessionId, $subjectId]);
            if (!is_string($siteIdentifier) || $siteIdentifier === '') {
                return;
            }
            $site = SiteContext::fromString($siteIdentifier);
            $deleted = $this->database->delete($this->tables->raw('portal_sessions'), [
                'id' => $sessionId,
                'user_id' => $subjectId,
            ]);
            if ($deleted !== 1) {
                throw new RuntimeException('The portal session changed during deletion.');
            }
            $this->ownership->remove(AuthorizationResource::item('portal_session', $sessionId), $site);
        });
    }

    /**
     * Remove rows already unusable by expiry.
     *
     * @return  int  Number removed.
     *
     * @since   2.0.0
     */
    public function purgeExpired(): int
    {
        return $this->transactions->transactional(function (): int {
            $rows = $this->database->fetchAllAssociative(sprintf(
                'SELECT id, site_identifier FROM %s WHERE expires_at <= ? ORDER BY id%s',
                $this->tables->quoted('portal_sessions'),
                $this->lockClause(),
            ), [$this->clock->now()], [Types::DATETIME_IMMUTABLE]);
            foreach ($rows as $row) {
                $sessionId = $this->requiredString($row, 'id');
                $site = SiteContext::fromString($this->requiredString($row, 'site_identifier'));
                $deleted = $this->database->delete(
                    $this->tables->raw('portal_sessions'),
                    ['id' => $sessionId],
                );
                if ($deleted !== 1) {
                    throw new RuntimeException('An expired portal session changed during deletion.');
                }
                $this->ownership->remove(AuthorizationResource::item('portal_session', $sessionId), $site);
            }

            return count($rows);
        });
    }

    /**
     * Verify that loader output names the same site, organization, and workspace stored in the session.
     *
     * @param   PortalSessionIdentity  $identity  Live loader result.
     * @param   array<string, mixed>   $row       Stored portal row.
     *
     * @return  bool  True only for exact scope equality.
     *
     * @since   2.0.0
     */
    private function contextMatchesRow(PortalSessionIdentity $identity, array $row): bool
    {
        return $identity->context->site->identifier() === $this->requiredString($row, 'site_identifier')
            && $identity->context->membership?->organization()->identifier()
                === $this->optionalString($row, 'organization_identifier')
            && $identity->context->membership?->membershipId() === $this->optionalString($row, 'membership_id')
            && $identity->context->membership?->workspace()?->identifier()
                === $this->optionalString($row, 'workspace_identifier')
            && $identity->context->membership?->membershipVersion()
                === $this->optionalPositiveInteger($row['membership_version'] ?? null)
            && $identity->context->membership?->policyGeneration()
                === $this->optionalPositiveInteger($row['policy_generation'] ?? null)
            && $identity->securityEpoch === $this->requiredPositiveInteger($row['security_epoch'] ?? null);
    }

    /**
     * Normalize a required positive integer returned by any supported driver.
     *
     * @param   mixed  $value  Driver-returned integer.
     *
     * @return  int  Positive integer.
     *
     * @throws  RuntimeException  When absent, malformed, or below one.
     *
     * @since   2.0.0
     */
    private function requiredPositiveInteger(mixed $value): int
    {
        $integer = $this->optionalPositiveInteger($value);
        if ($integer === null) {
            throw new RuntimeException('A stored portal session generation is missing.');
        }

        return $integer;
    }

    /**
     * Normalize a nullable positive integer returned by any supported driver.
     *
     * @param   mixed  $value  Driver-returned integer or null.
     *
     * @return  ?int  Positive integer or null.
     *
     * @throws  RuntimeException  When malformed or below one.
     *
     * @since   2.0.0
     */
    private function optionalPositiveInteger(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!is_int($value) && (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1)) {
            throw new RuntimeException('A stored portal session generation is invalid.');
        }
        $integer = (int) $value;
        if ($integer < 1) {
            throw new RuntimeException('A stored portal session generation is invalid.');
        }

        return $integer;
    }

    /**
     * Derive a keyed browser binding without storing the user-agent value.
     *
     * @param   string  $userAgent  Browser user agent, including an empty value.
     *
     * @return  string  Lowercase SHA-256 HMAC.
     *
     * @since   2.0.0
     */
    private function userAgentDigest(string $userAgent): string
    {
        return hash_hmac('sha256', "portal-user-agent\0" . $userAgent, $this->bindingKey);
    }

    /**
     * Generate an unpadded URL-safe token with the requested entropy.
     *
     * @param   positive-int  $bytes  Random byte count.
     *
     * @return  string  URL-safe opaque token.
     *
     * @since   2.0.0
     */
    private static function token(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /**
     * Read a required non-empty string from a row.
     *
     * @param   array<string, mixed>  $row     Storage row.
     * @param   string                $column  Column name.
     *
     * @return  string  Stored string.
     *
     * @throws  RuntimeException  When absent or empty.
     *
     * @since   2.0.0
     */
    private function requiredString(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Stored portal session column %s is invalid.', $column));
        }

        return $value;
    }

    /**
     * Read a nullable string from a row.
     *
     * @param   array<string, mixed>  $row     Storage row.
     * @param   string                $column  Column name.
     *
     * @return  ?string  Stored value or null.
     *
     * @throws  RuntimeException  When a non-null value is not a non-empty string.
     *
     * @since   2.0.0
     */
    private function optionalString(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Stored portal session column %s is invalid.', $column));
        }

        return $value;
    }

    /**
     * Parse a required DBAL datetime.
     *
     * @param   mixed  $value  Driver-returned value.
     *
     * @return  DateTimeImmutable  Parsed instant.
     *
     * @throws  RuntimeException  When absent or malformed.
     *
     * @since   2.0.0
     */
    private function date(mixed $value): DateTimeImmutable
    {
        $date = $this->nullableDate($value);
        if (!$date instanceof DateTimeImmutable) {
            throw new RuntimeException('A stored portal session datetime is missing.');
        }

        return $date;
    }

    /**
     * Parse a nullable DBAL datetime.
     *
     * @param   mixed  $value  Driver-returned value or null.
     *
     * @return  ?DateTimeImmutable  Parsed instant or null.
     *
     * @throws  RuntimeException  When a non-null value is malformed.
     *
     * @since   2.0.0
     */
    private function nullableDate(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            try {
                return new DateTimeImmutable($value, new DateTimeZone('UTC'));
            } catch (Throwable $exception) {
                throw new RuntimeException('A stored portal session datetime is invalid.', 0, $exception);
            }
        }
        throw new RuntimeException('A stored portal session datetime is invalid.');
    }

    /**
     * Add a pessimistic row lock on databases that support it.
     *
     * @return  string  Portable lock suffix.
     *
     * @since   2.0.0
     */
    private function lockClause(): string
    {
        return $this->database->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE';
    }
}
