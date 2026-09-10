<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Application\Authentication;

use InvalidArgumentException;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\MembershipContext;
use Kumwe\Context\Value\SiteContext;

/**
 * Verified principal plus the exact delegation envelope stored on its token.
 *
 * @since  2.0.0
 */
final readonly class VerifiedAccessToken
{
    /**
     * Capture a live principal together with the immutable envelope verified from its bearer token.
     *
     * @param   AuthenticatedPrincipal  $principal   Canonical live principal with intersected grants.
     * @param   string                  $tokenId     Stored token UUID.
     * @param   string                  $familyId    Root family UUID for revocation.
     * @param   SiteContext             $site        Exact stored site.
     * @param   ?MembershipContext      $membership  Live exact organization/workspace membership.
     * @param   string                  $audience    Exact stored audience.
     * @param   string                  $purpose     Exact stored purpose.
     *
     * @throws  InvalidArgumentException  When token or family identity is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        public AuthenticatedPrincipal $principal,
        public string $tokenId,
        public string $familyId,
        public SiteContext $site,
        public ?MembershipContext $membership,
        public string $audience,
        public string $purpose,
    ) {
        foreach (['token' => $tokenId, 'family' => $familyId] as $name => $value) {
            if (!\Ramsey\Uuid\Uuid::isValid($value)) {
                throw new InvalidArgumentException(sprintf('The verified %s identity is invalid.', $name));
            }
        }
    }

    /**
     * Mint an execution context carrying the exact stored delegation scope.
     *
     * @param   string                $requestId  Fresh unit-of-work identifier.
     * @param   AuthenticatedSurface  $surface    Delivery boundary presenting the token.
     *
     * @return  ExecutionContext  Context whose fingerprint includes membership and policy generations.
     *
     * @since   2.0.0
     */
    public function context(string $requestId, AuthenticatedSurface $surface): ExecutionContext
    {
        return $this->principal->context(
            $this->site,
            AuthenticationStrength::BearerToken,
            $requestId,
            null,
            $surface,
            $this->membership,
        );
    }
}
