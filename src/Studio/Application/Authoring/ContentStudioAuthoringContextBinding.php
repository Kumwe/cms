<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Studio\Domain\Authoring\StudioAuthoringIntent;
use Kumwe\Context\Value\AuthenticatedSurface;

/**
 * Holds the server-side binding behind one opaque contextual Content authoring key.
 *
 * The binding contains only authenticated App scope, one-way browser-session and authority digests, and
 * the exact PHP-resolved Content target. It is never a browser configuration or bearer credential. This is the
 * App-side context foundation for `V2-STU-007`, `STUDIO-PROD-010`, and `STUDIO-PROD-012`.
 *
 * @since  2.0.0
 */
final readonly class ContentStudioAuthoringContextBinding
{
    /**
     * Capture one verified administrator scope and exact authoring target.
     *
     * @param   string                        $contextKey        Opaque CSPRNG-backed lookup key.
     * @param   string                        $actorId           Authenticated actor that opened the context.
     * @param   string                        $siteId            Active site that owns the Content target.
     * @param   string|null                   $organizationId    Active organization, when selected.
     * @param   string|null                   $workspaceId       Active workspace, when selected.
     * @param   string                        $surface           Authenticated administrator surface.
     * @param   string                        $sessionBinding    SHA-256 digest of the browser-session identity.
     * @param   string                        $authorityBinding  SHA-256 digest of the live approval authority.
     * @param   ContentStudioAuthoringTarget  $target            Exact trusted Content target stored server-side.
     * @param   DateTimeImmutable             $createdAt         Instant the binding was opened.
     * @param   DateTimeImmutable             $expiresAt         Hard upper bound on binding authority.
     *
     * @throws  InvalidArgumentException  When persisted metadata is malformed or not administrator-bound.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $contextKey,
        public string $actorId,
        public string $siteId,
        public ?string $organizationId,
        public ?string $workspaceId,
        public string $surface,
        public string $sessionBinding,
        public string $authorityBinding,
        public ContentStudioAuthoringTarget $target,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
    ) {
        self::stableId($contextKey, 240, 'context key');
        self::bounded($actorId, 191, 'actor');
        self::bounded($siteId, 191, 'site');
        self::nullableBounded($organizationId, 191, 'organization');
        self::nullableBounded($workspaceId, 191, 'workspace');
        if ($surface !== AuthenticatedSurface::Administrator->value) {
            throw new InvalidArgumentException('The Studio Content authoring surface is invalid.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $sessionBinding) !== 1) {
            throw new InvalidArgumentException('The Studio Content authoring session binding is invalid.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $authorityBinding) !== 1) {
            throw new InvalidArgumentException('The Studio Content authoring authority binding is invalid.');
        }
        if ($workspaceId !== null && $organizationId === null) {
            throw new InvalidArgumentException(
                'A Studio Content authoring workspace binding requires an organization binding.',
            );
        }
        self::nullableBounded($target->modelId, 240, 'target model');
        self::nullableBounded($target->modelVersion, 80, 'target model version');
        self::nullableBounded($target->modelRevision, 200, 'target model revision');
        self::nullableBounded($target->entryId, 240, 'target entry');
        self::nullableBounded($target->entryRevision, 200, 'target entry revision');
        self::bounded($target->returnPath, 500, 'target return path');
        $hasNoModel = $target->modelId === null
            && $target->modelVersion === null
            && $target->modelRevision === null;
        $hasCompleteModel = $target->modelId !== null
            && $target->modelVersion !== null
            && $target->modelRevision !== null;
        $hasNoEntry = $target->entryId === null && $target->entryRevision === null;
        $hasCompleteEntry = $target->entryId !== null && $target->entryRevision !== null;
        if (
            $target->intent === StudioAuthoringIntent::Create && (!$hasNoEntry || (!$hasNoModel && !$hasCompleteModel))
            || $target->intent === StudioAuthoringIntent::Edit && (!$hasCompleteModel || !$hasCompleteEntry)
        ) {
            throw new InvalidArgumentException('The Studio Content authoring target binding is incomplete.');
        }
        if ($expiresAt <= $createdAt) {
            throw new InvalidArgumentException('The Studio Content authoring expiry must follow its creation.');
        }
    }

    /**
     * Validate an optional persisted scope or target coordinate.
     *
     * @param   string|null  $value    Candidate identifier, or null when the coordinate is absent.
     * @param   int          $maximum  Maximum stored byte length.
     * @param   string       $name     Safe coordinate name used in a refusal.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a present value is empty, overlong, or contains control bytes.
     *
     * @since   2.0.0
     */
    private static function nullableBounded(?string $value, int $maximum, string $name): void
    {
        if ($value !== null) {
            self::bounded($value, $maximum, $name);
        }
    }

    /**
     * Validate one required persisted context coordinate.
     *
     * @param   string  $value    Candidate textual coordinate.
     * @param   int     $maximum  Maximum stored byte length.
     * @param   string  $name     Safe coordinate name used in a refusal.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the value is empty, overlong, or contains control bytes.
     *
     * @since   2.0.0
     */
    private static function bounded(string $value, int $maximum, string $name): void
    {
        if ($value === '' || strlen($value) > $maximum || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException(sprintf('The Studio Content authoring %s is invalid.', $name));
        }
    }

    /**
     * Apply the stable-identifier grammar to the opaque context key.
     *
     * @param   string  $value    Candidate opaque identifier.
     * @param   int     $maximum  Maximum stored byte length.
     * @param   string  $name     Safe coordinate name used in a refusal.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the value falls outside the stable-identifier profile.
     *
     * @since   2.0.0
     */
    private static function stableId(string $value, int $maximum, string $name): void
    {
        if (
            strlen($value) > $maximum
            || preg_match('/^contexts\/[a-f0-9]{64}$/D', $value) !== 1
        ) {
            throw new InvalidArgumentException(sprintf('The Studio Content authoring %s is invalid.', $name));
        }
    }
}
