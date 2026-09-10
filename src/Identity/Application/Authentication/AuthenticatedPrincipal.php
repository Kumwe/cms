<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Application\Authentication;

use InvalidArgumentException;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Identity\Domain\GrantScope;
use Kumwe\Context\Contract\Principal;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\MembershipContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Context\Value\StepUpProof;

/**
 * A human actor that has proved who it is, together with the exact scoped authority it holds.
 *
 * Only an authentication adapter mints one — `DoctrineAccessTokenVerifier` for bearer tokens, the
 * administrator session store for the back office — after which it rides the request under
 * `REQUEST_ATTRIBUTE` for the rest of the unit of work, so nothing further down re-derives who is
 * acting. Construction is private and the named constructors validate hard: the subject must be a
 * canonical UUID, the grants must be a duplicate-free list, and the credential identity and security
 * epoch must both be present, which lets every consumer treat the instance as trusted. The grant list
 * is the whole of the actor's authority and is stored in a stable order; the provenance object records
 * which authority vouched for it, and is what stops a principal assembled elsewhere from being wrapped
 * in an `ExecutionContext` that this installation's gateway will honour. The class answers the package's
 * `Principal` port, which is how `ExecutionContext::issueHuman()` reads it; the grants stay host-owned and are
 * reached only through `of()`, which narrows the port back to this class.
 *
 * @since  2.0.0
 */
final readonly class AuthenticatedPrincipal implements Principal
{
    /**
     * PSR-7 request attribute the authentication middleware publishes the current principal under.
     *
     * The class name doubles as the key, so no two components can drift apart on the spelling.
     *
     * @var    string
     * @since  2.0.0
     */
    public const REQUEST_ATTRIBUTE = self::class;

    /**
     * Every distinct capability the principal holds, keyed and sorted by capability value.
     *
     * Scope is deliberately dropped from this index: it answers the coarse "does this actor hold the
     * capability at all" question that `hasCapability()` asks, while `$grants` keeps the scoped truth
     * that `allows()` decides on.
     *
     * @var    array<string, Capability>
     * @since  2.0.0
     */
    private array $capabilities;

    /**
     * The principal's effective grants, deduplicated and ordered by capability, scope type and identifier.
     *
     * The order is stable across authentications of the same authority, which is what makes
     * `authorizationFingerprint()` reproducible.
     *
     * @var    list<PrincipalGrant>
     * @since  2.0.0
     */
    private array $grants;

    /**
     * Validate the identity and collapse the supplied grants into a sorted, duplicate-free set.
     *
     * A bare `Capability` entry is accepted as shorthand for a global grant of it, so an adapter that
     * only knows capability names need not build scopes itself. Two entries naming the same capability
     * and the same scope are rejected rather than merged, because a duplicate means the caller
     * assembled the set twice and the fingerprint would depend on which copy survived.
     *
     * @param   object        $provenance     Authority vouching for this principal; compared by object identity.
     * @param   string        $subject        Canonical UUID of the authenticated user.
     * @param   array<mixed>  $grants         Capability or PrincipalGrant values making up the whole authority.
     * @param   string        $credentialId   Credential the authentication came from, such as `api-token:<id>`.
     * @param   int           $securityEpoch  Authorization epoch of the user at authentication time.
     *
     * @throws  InvalidArgumentException  When the subject is not a canonical UUID, the grants are not a list
     *          or hold a value that is neither a Capability nor a PrincipalGrant or repeat one
     *          capability-and-scope pair, the credential identity is empty, longer than 191 bytes or carries
     *          a control character, or the security epoch is below 1.
     *
     * @since   2.0.0
     */
    private function __construct(
        private object $provenance,
        private string $subject,
        array $grants,
        private string $credentialId,
        private int $securityEpoch,
    ) {
        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}'
            . '-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD';

        if (preg_match($uuidPattern, $subject) !== 1) {
            throw new InvalidArgumentException('An authenticated principal subject must be a canonical UUID.');
        }

        if (!array_is_list($grants)) {
            throw new InvalidArgumentException('Principal grants must be a list.');
        }

        if (
            $credentialId === ''
            || strlen($credentialId) > 191
            || preg_match('/[\x00-\x1F\x7F]/', $credentialId) === 1
        ) {
            throw new InvalidArgumentException('A principal credential identity is invalid.');
        }

        if ($securityEpoch < 1) {
            throw new InvalidArgumentException('A principal security epoch must be positive.');
        }

        $indexed = [];
        $normalized = [];

        foreach ($grants as $grant) {
            if ($grant instanceof Capability) {
                $grant = new PrincipalGrant($grant, GrantScope::global());
            }

            if (!($grant instanceof PrincipalGrant)) {
                throw new InvalidArgumentException('Principal grants must be Capability or PrincipalGrant values.');
            }

            $capability = $grant->capability();
            $key = $capability->value() . "\0" . $grant->scope()->type() . "\0" . ($grant->scope()->identifier() ?? '');
            if (isset($normalized[$key])) {
                throw new InvalidArgumentException(sprintf('Principal grant %s occurs more than once.', $key));
            }

            $indexed[$capability->value()] = $capability;
            $normalized[$key] = $grant;
        }

        ksort($indexed, SORT_STRING);
        ksort($normalized, SORT_STRING);
        $this->capabilities = $indexed;
        $this->grants = array_values($normalized);
    }

    /**
     * Issue a principal from capability names alone, granting each one globally.
     *
     * Reach for this only where the actor genuinely holds unrestricted authority over each name, such
     * as an ephemeral identity or a test fixture. Every authentication adapter in this tree reads the
     * reach the store recorded and calls `issueFromGrantRows()` instead, because promoting a name that
     * was granted over one resource to a global grant hands the actor authority it never had.
     *
     * @param   object        $provenance     Authority vouching for this principal.
     * @param   string        $subject        UUID of the authenticated user; lowercased before validation.
     * @param   array<mixed>  $capabilities   Capability names to grant globally, as a list of strings.
     * @param   ?string       $credentialId   Credential the authentication came from; defaults to an
     *          `ephemeral:` identity derived from the subject.
     * @param   int           $securityEpoch  Authorization epoch of the user at authentication time.
     *
     * @return  self  A principal holding exactly one global grant per name.
     *
     * @throws  InvalidArgumentException  When the names are not a list, an entry is not a string or is not a
     *          well-formed capability, or the constructor's identity and duplicate checks fail.
     *
     * @since   2.0.0
     */
    public static function issueFromStrings(
        object $provenance,
        string $subject,
        array $capabilities,
        ?string $credentialId = null,
        int $securityEpoch = 1,
    ): self {
        if (!array_is_list($capabilities)) {
            throw new InvalidArgumentException('Principal capability names must be a list.');
        }

        $values = [];

        foreach ($capabilities as $capability) {
            if (!is_string($capability)) {
                throw new InvalidArgumentException('Principal capability names must be strings.');
            }

            $values[] = Capability::fromString($capability);
        }

        return new self(
            $provenance,
            strtolower($subject),
            $values,
            $credentialId ?? 'ephemeral:' . strtolower($subject),
            $securityEpoch,
        );
    }

    /**
     * Issue a principal from stored grant rows, preserving the reach each grant was recorded with.
     *
     * This is the path an authentication adapter takes when it has joined the credential against the
     * role-grant tables: a row whose `scope_type` is `global` becomes a global grant, and every other
     * row becomes a grant confined to that scope type and identifier. Nothing is widened on the way in,
     * so an actor granted a capability over one resource does not come back holding it everywhere.
     *
     * @param   object                                                                          $provenance
     *          Authority vouching for this principal.
     * @param   string                                                                          $subject
     *          UUID of the authenticated user; lowercased before validation.
     * @param   list<array{capability: string, scope_type: string, scope_identifier: ?string}>  $rows
     *          One row per stored grant, as read from the role-grant tables.
     * @param   ?string                                                                         $credentialId
     *          Credential the authentication came from; defaults to an `ephemeral:` identity derived from
     *          the subject.
     * @param   int                                                                             $securityEpoch
     *          Authorization epoch of the user at authentication time.
     *
     * @return  self  A principal holding one grant per row, at the reach the row recorded.
     *
     * @throws  InvalidArgumentException  When a row names a malformed capability or scope, or the
     *          constructor's identity and duplicate checks fail.
     *
     * @since   2.0.0
     */
    public static function issueFromGrantRows(
        object $provenance,
        string $subject,
        array $rows,
        ?string $credentialId = null,
        int $securityEpoch = 1,
    ): self {
        $grants = [];

        foreach ($rows as $row) {
            $capability = Capability::fromString($row['capability']);
            $scope = $row['scope_type'] === 'global'
                ? GrantScope::global()
                : GrantScope::named($row['scope_type'], (string) $row['scope_identifier']);
            $grants[] = new PrincipalGrant($capability, $scope);
        }

        return new self(
            $provenance,
            strtolower($subject),
            $grants,
            $credentialId ?? 'ephemeral:' . strtolower($subject),
            $securityEpoch,
        );
    }

    /**
     * Reach the App principal a host-issued context carries.
     *
     * The package context exposes its actor through the neutral `Principal` port, which is all a policy needs
     * to name an actor. Grants, capabilities and the ability to re-issue a context at a stronger authentication
     * strength stay on this class, so a consumer that needs them narrows the port here rather than trusting any
     * implementation that merely answers it. No App authority issues a context around another implementation,
     * so a foreign one is read as no principal at all and authorizes nothing.
     *
     * @param   ExecutionContext  $context  Context whose actor is being read.
     *
     * @return  ?self  The App principal, or null for a system context or a foreign implementation of the port.
     *
     * @since   2.0.0
     */
    public static function of(ExecutionContext $context): ?self
    {
        $principal = $context->principal();

        return $principal instanceof self ? $principal : null;
    }

    /**
     * The user this principal speaks for, in the single spelling every store keys the actor by.
     *
     * Lowercasing here rather than at each call site is what lets the idempotency records, the MCP
     * mutation ledger, the administrator session store and the grant lookups all agree on one value.
     *
     * @return  string  Lowercase canonical UUID of the authenticated user.
     *
     * @since   2.0.0
     */
    public function subject(): string
    {
        return strtolower($this->subject);
    }

    /**
     * Return the authorization epoch observed while this principal was authenticated.
     *
     * @return  int  Positive epoch invalidated whenever the actor's security state changes.
     *
     * @since   2.0.0
     */
    public function securityEpoch(): int
    {
        return $this->securityEpoch;
    }

    /**
     * Whether the principal holds a capability at all, ignoring the reach of the grant that confers it.
     *
     * This is the cheap pre-flight check a guard makes before doing work, not an authorization
     * decision: it answers true for an actor whose only grant over the capability is confined to one
     * resource. Anything that acts on a specific target must ask `allows()` with the scopes it means to
     * touch, or go through the authorization gateway.
     *
     * @param   Capability  $capability  Capability to look for; matched on its exact value, never as a prefix.
     *
     * @return  bool  True when at least one grant names this capability.
     *
     * @since   2.0.0
     */
    public function hasCapability(Capability $capability): bool
    {
        return isset($this->capabilities[$capability->value()]);
    }

    /**
     * Every distinct capability the principal holds, with the reach of each grant discarded.
     *
     * @return  list<Capability>  Sorted by capability value; a capability granted at several scopes appears once.
     *
     * @since   2.0.0
     */
    public function capabilities(): array
    {
        return array_values($this->capabilities);
    }

    /**
     * The principal's grants in full, each still paired with the reach it was granted over.
     *
     * @return  list<PrincipalGrant>  Deduplicated and ordered by capability, scope type and scope identifier.
     *
     * @since   2.0.0
     */
    public function grants(): array
    {
        return $this->grants;
    }

    /**
     * Rebuild this live principal under an exact previously captured grant ceiling.
     *
     * Background work uses this to retain the authority of the credential that requested it without
     * retaining that credential. Every requested grant must still occur in the freshly loaded principal;
     * grants acquired after the request are deliberately omitted from the returned principal.
     *
     * @param   list<array{capability: string, scope_type: string, scope_identifier: ?string}>  $rows
     *          Exact effective grant rows captured when work was requested.
     *
     * @return  ?self  Restricted live principal, or null when any captured grant is no longer held.
     *
     * @throws  InvalidArgumentException  When the captured rows are malformed, duplicated, or not canonically
     *          ordered.
     *
     * @since   2.0.0
     */
    public function restrictedToGrantRows(array $rows): ?self
    {
        self::assertCanonicalGrantRows($rows);
        $restricted = self::issueFromGrantRows(
            $this->provenance,
            $this->subject,
            $rows,
            $this->credentialId,
            $this->securityEpoch,
        );
        $current = [];
        foreach ($this->grants as $grant) {
            $current[self::grantKey($grant)] = true;
        }
        foreach ($restricted->grants as $grant) {
            if (!isset($current[self::grantKey($grant)])) {
                return null;
            }
        }

        return $restricted;
    }

    /**
     * Derive the digest that stands for this principal's exact effective authority.
     *
     * Binds idempotent results to the credential, current authorization epoch and
     * complete effective scoped-grant set, not merely to the user identifier. The idempotency
     * middleware stores this beside a reply and answers 409 rather than replaying when the caller's
     * fingerprint no longer matches, so revoking a grant or bumping the security epoch retires the
     * cached result instead of serving it to authority that no longer exists.
     *
     * @return  string  Lowercase hexadecimal SHA-256 over the subject, credential, epoch and ordered grants.
     *
     * @since   2.0.0
     */
    public function authorizationFingerprint(): string
    {
        $grants = array_map(
            static fn (PrincipalGrant $grant): string => implode(':', [
                $grant->capability()->value(),
                $grant->scope()->type(),
                $grant->scope()->identifier() ?? '',
            ]),
            $this->grants,
        );

        return hash('sha256', implode("\n", [
            $this->subject(),
            $this->credentialId,
            (string) $this->securityEpoch,
            ...$grants,
        ]));
    }

    /**
     * Digest effective authority independently of the browser session or token that presented it.
     *
     * A successful step-up rotates the session credential by design. Long-running maker-checker
     * bindings therefore use this digest: grants and the security epoch remain exact, while replacing
     * the session secret does not make an otherwise unchanged approval impossible to consume.
     *
     * @return  string  SHA-256 over subject, epoch, and the complete ordered grant set.
     *
     * @since   2.0.0
     */
    public function authorityFingerprint(): string
    {
        $grants = array_map(
            static fn (PrincipalGrant $grant): string => implode(':', [
                $grant->capability()->value(),
                $grant->scope()->type(),
                $grant->scope()->identifier() ?? '',
            ]),
            $this->grants,
        );

        return hash('sha256', implode("\n", [
            $this->subject(),
            (string) $this->securityEpoch,
            ...$grants,
        ]));
    }

    /**
     * Whether any single grant confers this capability over at least one of the scopes requested.
     *
     * A global grant satisfies the check outright; a scoped grant satisfies it only when it covers one
     * of `$requestedScopes`, which is why the gateway passes every scope a request legitimately touches
     * — the owning site and the resource itself — rather than only the narrowest. Capability and scope
     * must be satisfied by the *same* grant, so authority over one resource is never combined with a
     * different capability held elsewhere. An empty `$requestedScopes` therefore only ever passes on a
     * global grant.
     *
     * @param   Capability        $capability       Capability the caller is about to exercise.
     * @param   list<GrantScope>  $requestedScopes  Scopes any one of which would satisfy the request.
     *
     * @return  bool  True when a single grant covers both the capability and one of the scopes.
     *
     * @since   2.0.0
     */
    public function allows(Capability $capability, array $requestedScopes): bool
    {
        foreach ($this->grants as $grant) {
            if (!$grant->capability()->equals($capability)) {
                continue;
            }

            if ($grant->scope()->isGlobal()) {
                return true;
            }

            foreach ($requestedScopes as $scope) {
                if ($grant->scope()->covers($scope)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Wrap this principal in the execution context one unit of work runs under.
     *
     * The context is minted with the principal's own provenance, so it is trusted by exactly the
     * authority that authenticated it and by no other. Call this once per request or command
     * invocation and pass the result down; every authorization decision, audit record and idempotency
     * fingerprint reads the context rather than re-deriving the actor.
     *
     * @param   SiteContext             $site                    Site this
     *          unit of work executes in.
     * @param   AuthenticationStrength  $authenticationStrength  How the
     *          actor proved itself; `System` is rejected, since this context has a human behind it.
     * @param   string                  $requestId               Identifier
     *          of this single unit of work.
     * @param   ?string                 $correlationId           Trace
     *          identifier shared by related work; defaults to `$requestId`.
     * @param   ?AuthenticatedSurface   $surface                 Authenticated delivery boundary.
     * @param   ?MembershipContext      $membership              Exact live membership scope.
     * @param   ?string                 $sessionId               Rotated browser-session
     *          identity.
     * @param   ?StepUpProof            $stepUpProof             Fresh multi-factor proof.
     *
     * @return  ExecutionContext  A human context bound to this
     *          principal's authority.
     *
     * @throws  InvalidArgumentException  When the strength is `System`, or either identifier is empty, longer
     *          than 191 bytes, or carries a control character.
     *
     * @since   2.0.0
     */
    public function context(
        SiteContext $site,
        AuthenticationStrength $authenticationStrength,
        string $requestId,
        ?string $correlationId = null,
        ?AuthenticatedSurface $surface = null,
        ?MembershipContext $membership = null,
        ?string $sessionId = null,
        ?StepUpProof $stepUpProof = null,
    ): ExecutionContext {
        return ExecutionContext::issueHuman(
            $this->provenance,
            $this,
            $site,
            $authenticationStrength,
            $requestId,
            $correlationId,
            $surface,
            $membership,
            $sessionId,
            $stepUpProof,
        );
    }

    /**
     * Whether this principal was vouched for by a particular authority.
     *
     * Comparison is by object identity, not by class or value, so only the very object the
     * authentication adapter was wired with satisfies it. `ExecutionContext::issueHuman()` and
     * `DenyByDefaultAuthorizationGateway` both check this before trusting a principal, which is what
     * makes a principal forged or replayed from elsewhere in the process authorize nothing.
     *
     * @param   object  $provenance  Authority object to test this principal against.
     *
     * @return  bool  True only when it is the same instance the principal was issued with.
     *
     * @since   2.0.0
     */
    public function hasProvenance(object $provenance): bool
    {
        return $this->provenance === $provenance;
    }

    /**
     * Compile one exact capability-and-scope identity for subset comparisons.
     *
     * @param   PrincipalGrant  $grant  Validated principal grant.
     *
     * @return  string  Collision-free internal key.
     *
     * @since   2.0.0
     */
    private static function grantKey(PrincipalGrant $grant): string
    {
        return implode("\0", [
            $grant->capability()->value(),
            $grant->scope()->type(),
            $grant->scope()->identifier() ?? '',
        ]);
    }

    /**
     * Require persisted authority rows to use the exact canonical grant representation.
     *
     * @param   array<mixed>  $rows  Candidate captured grant rows.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the list, row shape, value, uniqueness, or order is invalid.
     *
     * @since   2.0.0
     */
    private static function assertCanonicalGrantRows(array $rows): void
    {
        if (!array_is_list($rows)) {
            throw new InvalidArgumentException('Restricted principal grant rows must form a list.');
        }
        $seen = [];
        $previous = null;
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new InvalidArgumentException('A restricted principal grant row is invalid.');
            }
            $keys = array_keys($row);
            sort($keys, SORT_STRING);
            if ($keys !== ['capability', 'scope_identifier', 'scope_type']) {
                throw new InvalidArgumentException('A restricted principal grant row is invalid.');
            }
            $capability = $row['capability'];
            $scopeType = $row['scope_type'];
            $scopeIdentifier = $row['scope_identifier'];
            if (!is_string($capability) || !is_string($scopeType)) {
                throw new InvalidArgumentException('A restricted principal grant row is invalid.');
            }
            $validatedCapability = Capability::fromString($capability);
            $scope = $scopeType === 'global'
                ? GrantScope::global()
                : (is_string($scopeIdentifier) ? GrantScope::named($scopeType, $scopeIdentifier) : null);
            if (
                $scope === null
                || $validatedCapability->value() !== $capability
                || $scope->type() !== $scopeType
                || $scope->identifier() !== $scopeIdentifier
            ) {
                throw new InvalidArgumentException('A restricted principal grant row is invalid.');
            }
            $key = implode("\0", [$capability, $scopeType, $scopeIdentifier ?? '']);
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('A restricted principal grant row occurs more than once.');
            }
            if ($previous !== null && strcmp($previous, $key) >= 0) {
                throw new InvalidArgumentException('Restricted principal grant rows must be canonical.');
            }
            $seen[$key] = true;
            $previous = $key;
        }
    }
}
