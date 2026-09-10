<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Domain;

use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Domain\ScopeMode;
use Kumwe\Context\Value\SiteContext;

/**
 * Resolved site and organization coordinates that one business record lives under.
 *
 * A business definition declares a `ScopeMode`; this is that mode applied to a concrete request or a
 * concrete stored row, and it is what the query compiler binds into the `site_identifier` and
 * `organization_identifier` predicates of every record statement and what the record itself carries.
 * The constructor is private, so an instance has come either through `forDefinition()` on the request
 * path, where site and organization are taken from the execution context rather than caller input, or through
 * `reconstitute()` on the read path. Both refuse any combination of identifiers the mode does not
 * describe, so a record can never be written, read, or compared outside the scope its definition
 * declares.
 *
 * @since  2.0.0
 */
final readonly class RecordScope
{
    /**
     * Hold a scope tuple that one of the factories has already proved against its mode.
     *
     * @param  ScopeMode  $mode                    Scope dimensions the business definition declares.
     * @param  ?string    $siteIdentifier          Site the record belongs to; null for installation-wide
     *         and organization-only modes.
     * @param  ?string    $organizationIdentifier  Organization branch the record belongs to; null unless
     *         the mode carries an organization dimension.
     *
     * @since  2.0.0
     */
    private function __construct(
        public ScopeMode $mode,
        public ?string $siteIdentifier,
        public ?string $organizationIdentifier,
    ) {
    }

    /**
     * Derive the scope a request runs under from the definition's mode and the caller's site.
     *
     * Both values must be supplied from the execution context by the application service, so a request
     * cannot establish either scope. The organization is trimmed and matched against a narrow identifier
     * pattern before it is accepted. The mode decides which dimensions are
     * mandatory: an organization is required exactly when the mode carries one, and rejected otherwise,
     * rather than being ignored.
     *
     * @param   ScopeMode    $mode                    Scope dimensions declared by the business definition.
     * @param   SiteContext  $site                    Site the operation is executing against.
     * @param   ?string      $organizationIdentifier  Authenticated organization, or null.
     *
     * @return  self  Scope carrying the context's site for site-bearing modes and null elsewhere.
     *
     * @throws  InvalidArgumentException  When the organization identifier is malformed, absent for a mode
     *          that requires one, or supplied for a mode that does not accept one.
     *
     * @since   2.0.0
     */
    public static function forDefinition(
        ScopeMode $mode,
        SiteContext $site,
        ?string $organizationIdentifier,
    ): self {
        if ($organizationIdentifier !== null) {
            $organizationIdentifier = trim($organizationIdentifier);
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D', $organizationIdentifier) !== 1) {
                throw new InvalidArgumentException('The business-record organization identifier is invalid.');
            }
        }
        if (
            in_array($mode, [ScopeMode::Organization, ScopeMode::SiteOrganization], true)
            && $organizationIdentifier === null
        ) {
            throw new InvalidArgumentException('This business definition requires an organization scope.');
        }
        if (
            !in_array($mode, [ScopeMode::Organization, ScopeMode::SiteOrganization], true)
            && $organizationIdentifier !== null
        ) {
            throw new InvalidArgumentException('This business definition does not accept an organization scope.');
        }

        return new self(
            $mode,
            in_array($mode, [ScopeMode::Site, ScopeMode::SiteOrganization], true)
                ? $site->identifier()
                : null,
            $organizationIdentifier,
        );
    }

    /**
     * Rebuild the scope of a stored row from its persisted mode and scope columns.
     *
     * This is the read path's counterpart to `forDefinition()`: nothing is derived from the current
     * request and nothing is normalised, because the columns were written by an earlier one. What it
     * does check is that the stored pair still agrees with the mode of the definition version the row is
     * pinned to, so a row written under a different mode is refused rather than decoded into a record
     * claiming a scope it does not have.
     *
     * @param   ScopeMode  $mode                    Scope mode of the definition version the row pins.
     * @param   ?string    $siteIdentifier          `site_identifier` column as stored on the row.
     * @param   ?string    $organizationIdentifier  `organization_identifier` column as stored on the row.
     *
     * @return  self  Scope holding exactly the stored identifiers.
     *
     * @throws  InvalidArgumentException  When the stored identifiers do not match the dimensions the mode
     *          requires.
     *
     * @since   2.0.0
     */
    public static function reconstitute(
        ScopeMode $mode,
        ?string $siteIdentifier,
        ?string $organizationIdentifier,
    ): self {
        if (
            ($mode === ScopeMode::Installation && ($siteIdentifier !== null || $organizationIdentifier !== null))
            || ($mode === ScopeMode::Site && ($siteIdentifier === null || $organizationIdentifier !== null))
            || ($mode === ScopeMode::Organization && ($siteIdentifier !== null || $organizationIdentifier === null))
            || ($mode === ScopeMode::SiteOrganization
                && ($siteIdentifier === null || $organizationIdentifier === null))
        ) {
            throw new InvalidArgumentException('Stored business-record scope metadata is inconsistent.');
        }

        return new self($mode, $siteIdentifier, $organizationIdentifier);
    }

    /**
     * Prove that a caller's own site and organization resolve to this exact scope.
     *
     * The requested pair is put through `forDefinition()` first, so it is validated against the mode the
     * same way the request path would validate it, and only then compared. Reach for this to stop a
     * record loaded under one scope from being acted on by a caller standing in another.
     *
     * @param   SiteContext  $site                    Site the current operation is executing against.
     * @param   ?string      $organizationIdentifier  Organization the current operation asked to work in.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the requested organization is malformed or disagrees with
     *          the mode, or when the resolved site or organization differs from this scope.
     *
     * @since   2.0.0
     */
    public function assertRequest(SiteContext $site, ?string $organizationIdentifier): void
    {
        $requested = self::forDefinition($this->mode, $site, $organizationIdentifier);
        if (
            $requested->siteIdentifier !== $this->siteIdentifier
            || $requested->organizationIdentifier !== $this->organizationIdentifier
        ) {
            throw new InvalidArgumentException('The request scope does not match the business record scope.');
        }
    }

    /**
     * Export the scope in the canonical shape two scopes are compared through.
     *
     * Equality of business-record scopes is decided on this array rather than on the object, and the same
     * array is folded into the browse cursor digest, so a cursor cannot be replayed against a different
     * site or organization.
     *
     * @return  array{mode: string, site: ?string, organization: ?string}  The mode's backing value beside
     *          the two identifiers, keyed `mode`, `site` and `organization`.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode->value,
            'site' => $this->siteIdentifier,
            'organization' => $this->organizationIdentifier,
        ];
    }
}
