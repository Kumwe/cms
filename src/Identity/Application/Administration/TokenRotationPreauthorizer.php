<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Application\Administration;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Identity\Domain\Capability;

/**
 * The single rotation check every path that replaces an API token must clear before the swap is written.
 *
 * The REST pre-authorization path and `DoctrineAdministratorIdentityGateway::rotateAccessToken()` both
 * authorize through this one object, so the administrator, console and REST delivery surfaces cannot
 * drift. Rotation is treated as a fresh issuance rather than a copy:
 * beyond the actor's own `users.manage` over the token, the superseded token's subject and capabilities
 * are put back through `TokenDelegationPreauthorizer`, so authority the actor or the subject has since
 * lost cannot be carried forward. The gateway repeats the call with the row locked inside its write
 * transaction, so the token it is about to supersede cannot change between the check and the swap.
 *
 * @since  2.0.0
 */
final readonly class TokenRotationPreauthorizer
{
    /**
     * Wire the reader of the stored token to the two checks a rotation has to pass.
     *
     * @param  AccessControlRepository       $repository     Reads the live token being replaced,
     *         optionally under a row lock.
     * @param  AuthorizationGateway          $authorization  Judges whether the actor may manage this
     *         particular token.
     * @param  TokenDelegationPreauthorizer  $delegation     Re-runs the full issuance check against the
     *         token's own subject and capabilities.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AccessControlRepository $repository,
        private AuthorizationGateway $authorization,
        private TokenDelegationPreauthorizer $delegation,
    ) {
    }

    /**
     * Clear an actor to replace a token and return the scope the replacement must inherit.
     *
     * A token is refused when it is not live, when it belongs to a site other than the calling context's —
     * rotation never migrates a token between sites — or when re-resolving the stored email lands on a
     * different user than the row's own subject. Callers that write inside a transaction should call once
     * beforehand to fail early, then again with `$lock` set so the row is held for the swap.
     *
     * @param   ExecutionContext  $context  Actor, site and provenance the rotation runs under.
     * @param   string            $tokenId  UUID of the live token to be superseded.
     * @param   bool              $lock     Whether to read the token row for update, as the write
     *          transaction does.
     *
     * @return  TokenRotation  Subject, scope and capabilities the replacement token must be minted with.
     *
     * @throws  InvalidArgumentException  When the token is not live, belongs to another site, or resolves
     *          to a different subject than the one stored on it.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage the
     *          token, or may no longer delegate the capabilities it carries.
     *
     * @since   2.0.0
     */
    public function authorize(ExecutionContext $context, string $tokenId, bool $lock = false): TokenRotation
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('users.manage'),
            AuthorizationResource::item('api_token', $tokenId),
        );
        $token = $this->repository->activeTokenForRotation($tokenId, $lock)
            ?? throw new InvalidArgumentException('The active token to rotate does not exist.');
        if ($token['site_identifier'] !== $context->site()->identifier()) {
            throw new InvalidArgumentException('A token cannot be rotated outside its site context.');
        }
        if (
            ($token['organization_identifier'] ?? null) !== $context->organization()?->identifier()
            || ($token['workspace_identifier'] ?? null) !== $context->workspace()?->identifier()
        ) {
            throw new InvalidArgumentException('A token cannot be rotated outside its exact authority context.');
        }
        $delegation = $this->delegation->authorize(
            $context,
            $token['email'],
            $token['capabilities'],
            $lock,
        );
        if ($delegation->subjectId !== $token['subject_id']) {
            throw new InvalidArgumentException('The active token subject changed during authorization.');
        }
        if (
            $delegation->organization !== ($token['organization_identifier'] ?? null)
            || $delegation->workspace !== ($token['workspace_identifier'] ?? null)
            || $delegation->membershipId !== ($token['membership_id'] ?? null)
            || $delegation->membershipVersion !== ($token['membership_version'] ?? null)
            || $delegation->policyGeneration !== ($token['policy_generation'] ?? null)
        ) {
            throw new InvalidArgumentException('The token subject membership changed during rotation authorization.');
        }

        /** @var non-empty-list<string> $capabilities */
        $capabilities = $delegation->capabilities;
        return new TokenRotation(
            $delegation->subjectId,
            $token['email'],
            $capabilities,
            $token['site_identifier'],
            $token['audience'],
            $token['purpose'],
            $token['expires_at'],
            $token['organization_identifier'] ?? null,
            $token['workspace_identifier'] ?? null,
            $token['membership_id'] ?? null,
            $token['membership_version'] ?? null,
            $token['policy_generation'] ?? null,
            $token['family_id'] ?? $tokenId,
            $token['delegation_depth'] ?? 0,
        );
    }
}
