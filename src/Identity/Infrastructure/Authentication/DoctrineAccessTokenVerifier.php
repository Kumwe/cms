<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Infrastructure\Authentication;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use JsonException;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Context\Value\MembershipContext;
use Kumwe\Context\Value\OrganizationContext;
use Kumwe\Context\Value\WorkspaceContext;
use Kumwe\App\Identity\Application\Authentication\AccessTokenContext;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Identity\Application\Authentication\ScopedAccessTokenVerifier;
use Kumwe\App\Identity\Application\Authentication\VerifiedAccessToken;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;

/**
 * Resolves a presented bearer token into the principal it authenticates, against the Doctrine store.
 *
 * This is the only `AccessTokenVerifier` the container wires, so every token-bearing surface — the REST
 * API, the console and the MCP server — is admitted here. A token is matched by SHA-256 digest against
 * the `api_tokens` table, joined to its user and its site, and the row must still be unrevoked and
 * unexpired, issued for exactly the audience, purpose and site being presented against, owned by an
 * `active` user on an enabled site, and stamped with that user's current security epoch — which is what
 * lets an operator retire every outstanding token by raising the epoch rather than deleting rows.
 *
 * The capability list recorded on a token is treated as a ceiling, not as authority. The grants the
 * principal comes back holding are re-read from the user's roles on every call and intersected with
 * that list, at the scope each role grant records, so revoking a role takes effect on the next request
 * instead of at token expiry. Malformed input is refused before any query runs, and every rejection —
 * unknown, malformed, expired, revoked, wrong surface, stale epoch, disabled account or site — is
 * reported as a null return, so nothing downstream can tell them apart.
 *
 * @since  2.0.0
 */
final readonly class DoctrineAccessTokenVerifier implements ScopedAccessTokenVerifier
{
    /**
     * Bind the verifier to the store it reads and to the authority it issues principals under.
     *
     * @param  Connection      $database    Connection carrying the token, user, site and role-grant tables.
     * @param  TableNames      $tables      Compiler for the physical, prefixed names of those tables.
     * @param  ClockInterface  $clock       Supplies the current time stamped into `last_used_at`.
     * @param  object          $provenance  Authority stamped on every principal issued here, compared by identity.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private ClockInterface $clock,
        private object $provenance,
    ) {
    }

    /**
     * Resolve the principal a token authenticates, re-reading its grants from the role tables.
     *
     * An audience and purpose pair the installation does not support, and a token shorter than 32
     * bytes, longer than 512, or holding a character outside `A-Za-z0-9._~+/-` with optional trailing
     * `=`, are all refused before any query runs. Beyond that the row must satisfy every condition the
     * class block lists, and the grants returned are the intersection of the capability list stored on
     * the token with the grants the user's roles still confer. A successful verification also refreshes
     * `last_used_at`, at most once every five minutes.
     *
     * @param   string  $token           Bearer credential exactly as presented; only its digest is compared.
     * @param   string  $audience        Surface the row must have been issued to, such as `kumwe-cli`.
     * @param   string  $purpose         Purpose the row must have been issued for, such as `management`.
     * @param   string  $siteIdentifier  Site being presented against; normalised before it is matched.
     *
     * @return  ?AuthenticatedPrincipal  The actor with its effective grants, or null for every rejection,
     *          including a malformed token, an unsupported audience and purpose pair, and a stored
     *          capability list or grant row this adapter cannot read back.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the token lookup, the grant read, or the
     *          last-used update.
     *
     * @since   2.0.0
     */
    public function verify(
        string $token,
        string $audience = 'kumwe-http',
        string $purpose = 'api',
        string $siteIdentifier = 'default',
    ): ?AuthenticatedPrincipal {
        return $this->verifyScoped($token, $audience, $purpose, $siteIdentifier)?->principal;
    }

    /**
     * Resolve the principal together with its exact live organization delegation envelope.
     *
     * @param   string  $token           Presented bearer secret.
     * @param   string  $audience        Exact delivery audience.
     * @param   string  $purpose         Exact delegated purpose.
     * @param   string  $siteIdentifier  Exact site being presented against.
     *
     * @return  ?VerifiedAccessToken  Verified scope and principal, or null for every failure.
     *
     * @since   2.0.0
     */
    public function verifyScoped(
        string $token,
        string $audience = 'kumwe-http',
        string $purpose = 'api',
        string $siteIdentifier = 'default',
    ): ?VerifiedAccessToken {
        try {
            $context = AccessTokenContext::fromStrings($audience, $purpose);
            $siteIdentifier = SiteContext::fromString($siteIdentifier)->identifier();
        } catch (InvalidArgumentException) {
            return null;
        }
        $length = strlen($token);
        if ($length < 32 || $length > 512 || preg_match('/^[A-Za-z0-9._~+\/-]+=*$/D', $token) !== 1) {
            return null;
        }

        $membershipIdExpression = $this->database->getDatabasePlatform() instanceof PostgreSQLPlatform
            ? 'CAST(m.id AS VARCHAR)'
            : 'm.id';
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT t.id, t.subject_id, t.capabilities, t.last_used_at, t.site_identifier, '
            . 't.organization_identifier, t.workspace_identifier, t.membership_id, t.membership_version, '
            . 't.policy_generation, t.family_id, '
            . 'u.security_epoch FROM %s t '
            . 'INNER JOIN %s u ON u.id = t.subject_id '
            . 'INNER JOIN %s s ON s.identifier = t.site_identifier '
            . 'WHERE t.token_digest = ? AND t.revoked_at IS NULL AND t.expires_at > CURRENT_TIMESTAMP '
            . "AND t.audience = ? AND t.purpose = ? AND t.site_identifier = ? "
            . "AND t.security_epoch = u.security_epoch AND u.status = 'active' AND s.enabled = ? "
            . 'AND ((t.organization_identifier IS NULL AND t.workspace_identifier IS NULL '
            . 'AND t.membership_id IS NULL AND t.membership_version IS NULL AND t.policy_generation IS NULL) '
            . 'OR EXISTS (SELECT 1 FROM %s o INNER JOIN %s m ON m.organization_id = o.id '
            . 'WHERE o.identifier = t.organization_identifier AND o.site_identifier = t.site_identifier '
            . "AND o.status = 'active' AND o.policy_generation = t.policy_generation "
            . 'AND %s = t.membership_id AND m.user_id = t.subject_id AND m.version = t.membership_version '
            . "AND m.status = 'active' AND m.valid_from <= CURRENT_TIMESTAMP "
            . 'AND (m.valid_until IS NULL OR m.valid_until > CURRENT_TIMESTAMP) '
            . 'AND (t.workspace_identifier IS NULL OR EXISTS (SELECT 1 FROM %s mw INNER JOIN %s w '
            . 'ON w.id = mw.workspace_id WHERE mw.membership_id = m.id AND w.organization_id = o.id '
            . "AND w.identifier = t.workspace_identifier AND w.status = 'active'))))",
            $this->tables->quoted('api_tokens'),
            $this->tables->quoted('users'),
            $this->tables->quoted('sites'),
            $this->tables->quoted('organizations'),
            $this->tables->quoted('organization_memberships'),
            $membershipIdExpression,
            $this->tables->quoted('membership_workspaces'),
            $this->tables->quoted('workspaces'),
        ), [hash('sha256', $token), $context->audience, $context->purpose, $siteIdentifier, true], [
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::BOOLEAN,
        ]);

        if (
            $row === false
            || !is_string($row['id'] ?? null)
            || !is_string($row['subject_id'] ?? null)
        ) {
            return null;
        }

        try {
            $membership = $this->membership($row);
            $principal = AuthenticatedPrincipal::issueFromGrantRows(
                $this->provenance,
                $row['subject_id'],
                $this->grantsFor(
                    $row['subject_id'],
                    $this->decodeCapabilities($row['capabilities'] ?? null),
                    $membership?->membershipId(),
                ),
                'api-token:' . $row['id'],
                $this->positiveInteger($row['security_epoch'] ?? null),
            );
            $this->touchUsage($row['id'], $row['last_used_at'] ?? null);

            $tokenId = $row['id'];
            $familyId = $row['family_id'] ?? $tokenId;
            if (!is_string($familyId)) {
                throw new InvalidArgumentException('Stored token family is invalid.');
            }

            return new VerifiedAccessToken(
                $principal,
                $tokenId,
                $familyId,
                SiteContext::fromString($siteIdentifier),
                $membership,
                $context->audience,
                $context->purpose,
            );
        } catch (InvalidArgumentException | JsonException) {
            return null;
        }
    }

    /**
     * Read a stored security epoch back as an integer, refusing a value that could not be one.
     *
     * Drivers return this column as an integer on some platforms and as a decimal string on others, so
     * both are accepted: an integer passes through untouched, while text must be a run of digits that
     * does not begin with a zero. Anything else — a float, padded text, a null column — is treated as a
     * corrupt row rather than coerced.
     *
     * @param   mixed  $value  Raw `security_epoch` column value, as the driver returned it.
     *
     * @return  int  The epoch as stored; a non-positive integer is rejected later, by the principal.
     *
     * @throws  InvalidArgumentException  When the value is neither an integer nor a run of digits
     *          beginning above zero.
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
     * Rebuild the exact organization/workspace membership bound to a verified token.
     *
     * The SQL predicate has already proved the generations live; this method validates the driver values
     * before they enter an execution context. A site-only token must carry no partial membership columns.
     *
     * @param   array<string, mixed>  $row  Verified token row.
     *
     * @return  ?MembershipContext  Exact live membership or null for a site-only token.
     *
     * @throws  InvalidArgumentException  When stored scope columns are partial or malformed.
     *
     * @since   2.0.0
     */
    private function membership(array $row): ?MembershipContext
    {
        $organization = $row['organization_identifier'] ?? null;
        $workspace = $row['workspace_identifier'] ?? null;
        $membershipId = $row['membership_id'] ?? null;
        $membershipVersion = $row['membership_version'] ?? null;
        $policyGeneration = $row['policy_generation'] ?? null;
        if ($organization === null) {
            if (
                $workspace !== null
                || $membershipId !== null
                || $membershipVersion !== null
                || $policyGeneration !== null
            ) {
                throw new InvalidArgumentException('A stored token has a partial organization binding.');
            }

            return null;
        }
        if (
            !is_string($organization)
            || ($workspace !== null && !is_string($workspace))
            || !is_string($membershipId)
        ) {
            throw new InvalidArgumentException('A stored token organization binding is invalid.');
        }

        return new MembershipContext(
            $membershipId,
            OrganizationContext::fromString($organization),
            $workspace === null ? null : WorkspaceContext::fromString($workspace),
            $this->positiveInteger($membershipVersion),
            $this->positiveInteger($policyGeneration),
        );
    }

    /**
     * Stamp the current time on a token's `last_used_at`, at most once every five minutes.
     *
     * The throttle is what keeps an otherwise read-only verification path from writing on every
     * request: a stamp newer than five minutes leaves the row untouched. The update is also conditioned
     * on the token still being unrevoked, so a token revoked between the lookup and this write is not
     * written to.
     *
     * @param   string  $tokenId     Identifier of the row the verification lookup matched.
     * @param   mixed   $lastUsedAt  Stamp read back with that row; null when the token has never been used.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the stored stamp is present but is not a parsable datetime string.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the update.
     *
     * @since   2.0.0
     */
    private function touchUsage(string $tokenId, mixed $lastUsedAt): void
    {
        $now = $this->clock->now();
        if ($lastUsedAt !== null) {
            if (!is_string($lastUsedAt)) {
                throw new InvalidArgumentException('Stored token usage time is invalid.');
            }
            try {
                $lastUsed = new DateTimeImmutable($lastUsedAt);
            } catch (\Exception $exception) {
                throw new InvalidArgumentException('Stored token usage time is invalid.', 0, $exception);
            }
            if ($lastUsed > $now->modify('-5 minutes')) {
                return;
            }
        }

        $this->database->executeStatement(sprintf(
            'UPDATE %s SET last_used_at = ? WHERE id = ? AND revoked_at IS NULL',
            $this->tables->quoted('api_tokens'),
        ), [$now, $tokenId], [Types::DATETIME_IMMUTABLE, Types::GUID]);
    }

    /**
     * Read the capability list recorded on a token row back into a list of strings.
     *
     * The column holds JSON text, but a driver may hand back an already decoded value, so both are
     * accepted. What comes back only bounds what the token may exercise; it confers nothing by itself,
     * since `grantsFor()` still has to find each name among the user's current role grants.
     *
     * @param   mixed  $stored  Raw `capabilities` column value: JSON text, or an already decoded value.
     *
     * @return  list<string>  Capability names the token was minted with, in the order they were stored.
     *
     * @throws  JsonException  When the stored text is not decodable JSON, or nests beyond 32 levels.
     * @throws  InvalidArgumentException  When the decoded value is not a list, or an entry is not a string.
     *
     * @since   2.0.0
     */
    private function decodeCapabilities(mixed $stored): array
    {
        if (is_string($stored)) {
            $stored = json_decode($stored, true, 32, JSON_THROW_ON_ERROR);
        }

        if (!is_array($stored) || !array_is_list($stored)) {
            throw new InvalidArgumentException('Stored token capabilities must be a JSON list.');
        }

        foreach ($stored as $capability) {
            if (!is_string($capability)) {
                throw new InvalidArgumentException('Stored token capabilities must contain strings.');
            }
        }

        return $stored;
    }

    /**
     * Look up the grants the user still holds over the capabilities the token names.
     *
     * This join is the reason a token is not a snapshot of authority: a capability comes back only if
     * it is both recorded on the token and still conferred by one of the user's roles, and it comes
     * back at the scope the role grant records rather than widened to global. A token minted with no
     * capabilities skips the query entirely and authorises nothing.
     *
     * @param   string        $subjectId          UUID of the user the token was issued to.
     * @param   list<string>  $tokenCapabilities  Capability names recorded on the token row.
     * @param   ?string       $membershipId       Exact SQL-validated organization membership, when scoped.
     *
     * @return  list<array{capability: string, scope_type: string, scope_identifier: ?string}>
     *          One row per surviving grant, ordered by capability, scope type and scope identifier.
     *
     * @throws  InvalidArgumentException  When a stored grant row carries a non-string capability or scope
     *          type, or a scope identifier that is neither null nor a string.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the grant read.
     *
     * @since   2.0.0
     */
    private function grantsFor(
        string $subjectId,
        array $tokenCapabilities,
        ?string $membershipId,
    ): array {
        if ($tokenCapabilities === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($tokenCapabilities), '?'));
        $sql = 'SELECT g.capability_code AS capability, g.scope_type, g.scope_identifier '
            . 'FROM %s ur INNER JOIN %s g ON g.role_id = ur.role_id '
            . 'WHERE ur.user_id = ? AND g.capability_code IN (%s)';
        $parameters = [$subjectId, ...$tokenCapabilities];
        if ($membershipId !== null) {
            $sql .= ' UNION SELECT g.capability_code AS capability, g.scope_type, g.scope_identifier '
                . 'FROM %s mr INNER JOIN %s g ON g.role_id = mr.role_id '
                . 'WHERE mr.membership_id = ? AND g.capability_code IN (%s)';
            $parameters = [...$parameters, $membershipId, ...$tokenCapabilities];
            $sql = sprintf(
                $sql,
                $this->tables->quoted('user_roles'),
                $this->tables->quoted('role_capability_grants'),
                $placeholders,
                $this->tables->quoted('membership_roles'),
                $this->tables->quoted('role_capability_grants'),
                $placeholders,
            );
        } else {
            $sql = sprintf(
                $sql,
                $this->tables->quoted('user_roles'),
                $this->tables->quoted('role_capability_grants'),
                $placeholders,
            );
        }
        $rows = $this->database->fetchAllAssociative(
            $sql . ' ORDER BY capability, scope_type, scope_identifier',
            $parameters,
        );

        $grants = [];
        foreach ($rows as $row) {
            if (!is_string($row['capability'] ?? null) || !is_string($row['scope_type'] ?? null)) {
                throw new InvalidArgumentException('A stored principal grant is invalid.');
            }
            $scopeIdentifier = $row['scope_identifier'] ?? null;
            if ($scopeIdentifier !== null && !is_string($scopeIdentifier)) {
                throw new InvalidArgumentException('A stored principal grant scope is invalid.');
            }
            $grants[] = [
                'capability' => $row['capability'],
                'scope_type' => $row['scope_type'],
                'scope_identifier' => $scopeIdentifier,
            ];
        }

        return $grants;
    }
}
