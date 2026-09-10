<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Application\Administration;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Identity\Domain\EmailAddress;
use Kumwe\App\Identity\Domain\GrantScope;

/**
 * The single delegation check every path that mints an API token must clear before one is written.
 *
 * `HttpMutationPreauthorizer` screens the REST issuance route with it, `TokenRotationPreauthorizer` runs
 * it again for every rotation, and `DoctrineAdministratorIdentityGateway::issueAccessToken()` calls it as
 * the token is written, so no delivery surface can mint a token another would refuse. Issuance calls it
 * twice — once before the transaction and once inside it, once the subject's row is locked — so a grant
 * revoked between the two attempts cannot be captured in a token. It also normalises untrusted input:
 * capability strings are parsed and deduplicated here rather than taken on trust from the request.
 *
 * @since  2.0.0
 */
final readonly class TokenDelegationPreauthorizer
{
    /**
     * Wire the reader of subject grants to the gateway that judges them.
     *
     * @param  AccessControlRepository  $repository     Resolves the subject's UUID from an email and lists
     *         the grants backing each requested capability.
     * @param  AuthorizationGateway     $authorization  Judges both the actor's own access and, separately,
     *         whether it may hand each capability onward.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AccessControlRepository $repository,
        private AuthorizationGateway $authorization,
    ) {
    }

    /**
     * Clear an actor to mint a token for one subject and return the capability set that survived.
     *
     * Three conditions must hold: the actor holds `users.manage` over the site, and over the subject's own
     * user record whenever the subject is not the actor; the subject is already granted every requested
     * capability; and the actor may delegate each of those at every scope the subject holds it under, not
     * merely at one. Capabilities are parsed and deduplicated before any of that runs, so the returned
     * list is canonical and ordered by first request.
     *
     * @param   ExecutionContext  $context       Actor, site and provenance the issuance runs under.
     * @param   string            $email         Email naming the subject the token will authenticate as;
     *          normalised before it is looked up.
     * @param   array<mixed>      $capabilities  Capability codes exactly as the caller supplied them,
     *          validated here rather than trusted.
     * @param   bool              $lock          Whether target membership authority is locked for issuance.
     *
     * @return  TokenDelegation  The resolved subject with the deduplicated capabilities it may be issued.
     *
     * @throws  InvalidArgumentException  When the capability list is empty, is not a list, holds a
     *          non-string, names an unparseable capability or email, points at a subject that does not
     *          exist, or names a capability the subject is not granted.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage the
     *          site or the subject, or may not delegate one of the capabilities at the scope it is held.
     *
     * @since   2.0.0
     */
    public function authorize(
        ExecutionContext $context,
        string $email,
        array $capabilities,
        bool $lock = false,
    ): TokenDelegation {
        if (!array_is_list($capabilities) || $capabilities === []) {
            throw new InvalidArgumentException('At least one token capability is required.');
        }

        /** @var array<string, true> $requested */
        $requested = [];
        foreach ($capabilities as $capability) {
            if (!is_string($capability)) {
                throw new InvalidArgumentException('Token capabilities must be strings.');
            }
            $requested[Capability::fromString($capability)->value()] = true;
        }

        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('users.manage'),
            AuthorizationResource::item('site', $context->site()->identifier()),
        );
        $normalizedEmail = EmailAddress::fromString($email)->value();
        $subjectId = $this->repository->userIdByEmail($normalizedEmail)
            ?? throw new InvalidArgumentException('The requested active token subject does not exist.');
        if ($subjectId !== $context->actorId()) {
            $this->authorization->assertAllowed(
                $context,
                Capability::fromString('users.manage'),
                AuthorizationResource::item('user', $subjectId),
            );
        }

        $targetGrants = $this->repository->userGrants($subjectId);
        $authority = null;
        $organization = $context->organization()?->identifier();
        $workspace = $context->workspace()?->identifier();
        if ($organization !== null) {
            $authority = $this->repository->organizationMembershipAuthority(
                $subjectId,
                $context->site()->identifier(),
                $organization,
                $workspace,
                $lock,
            ) ?? throw new InvalidArgumentException(
                'The token subject has no live membership in the exact organization and workspace.',
            );
            array_push($targetGrants, ...$authority['grants']);
        }
        foreach (array_keys($requested) as $capability) {
            $matching = array_values(array_filter(
                $targetGrants,
                static fn (array $grant): bool => $grant['capability'] === $capability,
            ));
            if ($matching === []) {
                throw new InvalidArgumentException(sprintf(
                    'The token subject does not grant capability %s.',
                    $capability,
                ));
            }
            foreach ($matching as $grant) {
                $this->authorization->assertCanDelegate(
                    $context,
                    Capability::fromString($capability),
                    $grant['scope_type'] === 'global'
                        ? GrantScope::global()
                        : GrantScope::named($grant['scope_type'], $grant['scope_identifier'] ?? ''),
                );
            }
        }

        /** @var non-empty-list<string> $authorizedCapabilities */
        $authorizedCapabilities = array_keys($requested);
        return new TokenDelegation(
            $subjectId,
            $authorizedCapabilities,
            $organization,
            $workspace,
            $authority['membership_id'] ?? null,
            $authority['membership_version'] ?? null,
            $authority['policy_generation'] ?? null,
        );
    }
}
