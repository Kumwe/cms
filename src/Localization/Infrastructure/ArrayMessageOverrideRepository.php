<?php

declare(strict_types=1);

namespace Kumwe\App\Localization\Infrastructure;

use Kumwe\Localization\Application\MessageOverrideRepository;
use Kumwe\Localization\Domain\LocaleTag;

/**
 * Serves the administered override layers from a map held in memory.
 *
 * This is the adapter an installation runs with until site and organization overrides are stored
 * and administered, and it is the adapter every test of the chain uses, because the chain's
 * ordering is a property of the resolver rather than of where the two upper layers are kept. It
 * satisfies the same contract a stored implementation must: the whole bounded map for a scope is
 * returned in one call, so the render path never performs a lookup per message.
 *
 * @since  2.0.0
 */
final readonly class ArrayMessageOverrideRepository implements MessageOverrideRepository
{
    /**
     * Hold the override maps for every scope this installation carries.
     *
     * @param  array<string, array<string, array<string, string>>>  $site          Patterns keyed by site
     *         identifier, then locale tag, then message identifier.
     * @param  array<string, array<string, array<string, string>>>  $organization  Patterns keyed by
     *         `site/organization`, then locale tag, then message identifier.
     *
     * @since  2.0.0
     */
    public function __construct(private array $site = [], private array $organization = [])
    {
    }

    /**
     * Read every override a site has recorded for one locale.
     *
     * @param   string     $site    Site identifier the overrides belong to.
     * @param   LocaleTag  $locale  Exact locale to read.
     *
     * @return  array<string, string>  Patterns keyed by message identifier, empty when there are none.
     *
     * @since   2.0.0
     */
    public function siteOverrides(string $site, LocaleTag $locale): array
    {
        return $this->site[$site][$locale->toString()] ?? [];
    }

    /**
     * Read every override an organization within a site has recorded for one locale.
     *
     * @param   string     $site          Site the organization belongs to.
     * @param   string     $organization  Organization identifier the overrides belong to.
     * @param   LocaleTag  $locale        Exact locale to read.
     *
     * @return  array<string, string>  Patterns keyed by message identifier, empty when there are none.
     *
     * @since   2.0.0
     */
    public function organizationOverrides(string $site, string $organization, LocaleTag $locale): array
    {
        return $this->organization[$site . '/' . $organization][$locale->toString()] ?? [];
    }
}
