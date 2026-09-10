<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSecurity\Application\Approval;

use InvalidArgumentException;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Immutable actor/action/resource/version/context/payload binding for an approval request.
 *
 * @since  2.0.0
 */
final readonly class ApprovalBinding
{
    /**
     * Validate every value that makes an approval non-transferable.
     *
     * @param   string   $requesterId         Actor that alone may consume the approval.
     * @param   string   $action              Exact high-impact action.
     * @param   string   $resourceType        Exact resource type.
     * @param   string   $resourceId          Exact resource identity.
     * @param   int      $resourceVersion     Exact optimistic resource version.
     * @param   string   $siteIdentifier      Exact site.
     * @param   ?string  $organization        Exact organization, when scoped.
     * @param   ?string  $workspace           Exact workspace, when scoped.
     * @param   string   $contextFingerprint  Requester's current authority fingerprint.
     * @param   string   $payloadDigest       SHA-256 of the canonical requested mutation.
     *
     * @throws  InvalidArgumentException  When any binding is malformed.
     *
     * @since   2.0.0
     */
    public function __construct(
        private string $requesterId,
        private string $action,
        private string $resourceType,
        private string $resourceId,
        private int $resourceVersion,
        private string $siteIdentifier,
        private ?string $organization,
        private ?string $workspace,
        private string $contextFingerprint,
        private string $payloadDigest,
    ) {
        $identities = ['requester' => $requesterId, 'resource' => $resourceId, 'site' => $siteIdentifier];
        foreach ($identities as $name => $value) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D', $value) !== 1) {
                throw new InvalidArgumentException(sprintf('The approval %s identity is invalid.', $name));
            }
        }
        foreach (['action' => $action, 'resource type' => $resourceType] as $name => $value) {
            if (preg_match('/^[a-z][a-z0-9._:-]{0,126}$/D', $value) !== 1) {
                throw new InvalidArgumentException(sprintf('The approval %s is invalid.', $name));
            }
        }
        foreach (['organization' => $organization, 'workspace' => $workspace] as $name => $value) {
            if ($value !== null && preg_match('/^[a-z0-9][a-z0-9._:-]{0,190}$/D', $value) !== 1) {
                throw new InvalidArgumentException(sprintf('The approval %s is invalid.', $name));
            }
        }
        if ($resourceVersion < 1) {
            throw new InvalidArgumentException('An approval requires a positive resource version.');
        }
        foreach (['context' => $contextFingerprint, 'payload' => $payloadDigest] as $name => $digest) {
            if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                throw new InvalidArgumentException(sprintf('The approval %s digest is invalid.', $name));
            }
        }
    }

    /**
     * Derive a binding from a trusted execution context and canonical mutation digest.
     *
     * @param   ExecutionContext  $context          Requesting actor and exact scope.
     * @param   string            $action           Exact high-impact action.
     * @param   string            $resourceType     Exact resource type.
     * @param   string            $resourceId       Exact resource identity.
     * @param   int               $resourceVersion  Exact expected resource version.
     * @param   string            $payloadDigest    Canonical mutation SHA-256.
     *
     * @return  self  Complete non-transferable binding.
     *
     * @since   2.0.0
     */
    public static function fromContext(
        ExecutionContext $context,
        string $action,
        string $resourceType,
        string $resourceId,
        int $resourceVersion,
        string $payloadDigest,
    ): self {
        return new self(
            $context->actorId(),
            $action,
            $resourceType,
            $resourceId,
            $resourceVersion,
            $context->site()->identifier(),
            $context->organization()?->identifier(),
            $context->workspace()?->identifier(),
            $context->approvalFingerprint(),
            $payloadDigest,
        );
    }

    /** Return the requesting actor. @return string Requesting actor. @since 2.0.0 */
    public function requesterId(): string
    {
        return $this->requesterId;
    }

    /** Return the protected action. @return string Exact action. @since 2.0.0 */
    public function action(): string
    {
        return $this->action;
    }

    /** Return the protected resource type. @return string Exact resource type. @since 2.0.0 */
    public function resourceType(): string
    {
        return $this->resourceType;
    }

    /** Return the protected resource identity. @return string Exact resource identity. @since 2.0.0 */
    public function resourceId(): string
    {
        return $this->resourceId;
    }

    /** Return the frozen resource version. @return int Exact resource version. @since 2.0.0 */
    public function resourceVersion(): int
    {
        return $this->resourceVersion;
    }

    /** Return the bound site. @return string Exact site. @since 2.0.0 */
    public function siteIdentifier(): string
    {
        return $this->siteIdentifier;
    }

    /** Return the optional bound organization. @return ?string Exact organization. @since 2.0.0 */
    public function organization(): ?string
    {
        return $this->organization;
    }

    /** Return the optional bound workspace. @return ?string Exact workspace. @since 2.0.0 */
    public function workspace(): ?string
    {
        return $this->workspace;
    }

    /** Return the frozen authority fingerprint. @return string Authority fingerprint. @since 2.0.0 */
    public function contextFingerprint(): string
    {
        return $this->contextFingerprint;
    }

    /** Return the canonical mutation digest. @return string Canonical mutation digest. @since 2.0.0 */
    public function payloadDigest(): string
    {
        return $this->payloadDigest;
    }

    /**
     * Stable digest used to compare a request with a later consumption attempt.
     *
     * @return  string  SHA-256 over every exact binding.
     *
     * @since   2.0.0
     */
    public function digest(): string
    {
        return hash('sha256', implode("\n", [
            $this->requesterId,
            $this->action,
            $this->resourceType,
            $this->resourceId,
            (string) $this->resourceVersion,
            $this->siteIdentifier,
            $this->organization ?? '-',
            $this->workspace ?? '-',
            $this->contextFingerprint,
            $this->payloadDigest,
        ]));
    }
}
