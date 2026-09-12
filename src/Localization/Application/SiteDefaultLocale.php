<?php

declare(strict_types=1);

namespace Kumwe\App\Localization\Application;

use Kumwe\Localization\Application\SupportedLocales;
use Kumwe\Localization\Application\DefaultLocaleProvider;
use Kumwe\Localization\Domain\InvalidLocaleTag;
use Kumwe\Localization\Domain\LocaleTag;
use Kumwe\App\Site\Application\SiteSettings;
use Throwable;

/**
 * Reads the site's `default_locale` setting once and hands it to locale negotiation.
 *
 * The setting has existed, been validated as a language tag and been administered since 2.0.0
 * without anything consuming it. This is its consumer, and it is a separate collaborator for one
 * reason: negotiation runs on every request including liveness probes, and the setting lives in the
 * database. The value is therefore read at most once per process and held. That is the same
 * discipline the interface-translation decision record states for the override layers — a site
 * setting is administered state, not transactional state, and is cached accordingly — and a process
 * that has to observe a changed setting is recycled, which is how every other boot-time setting on
 * this platform already behaves.
 *
 * A stored value that no longer parses, or a settings read that fails, is answered with the source
 * locale rather than an exception. Negotiation runs before the error boundary can render anything,
 * so a locale it cannot resolve must degrade to the language the interface is authored in instead
 * of turning every page into a fault.
 *
 * @since  2.0.0
 */
final class SiteDefaultLocale implements DefaultLocaleProvider
{
    /**
     * The resolved default, or null until the first read.
     *
     * @var    ?LocaleTag
     * @since  2.0.0
     */
    private ?LocaleTag $resolved = null;

    /**
     * Bind the reader to the settings document and the locales this installation carries.
     *
     * @param  SiteSettings      $settings   Port the `default_locale` value is read from.
     * @param  SupportedLocales  $supported  Registry the stored value is reduced against.
     *
     * @since  2.0.0
     */
    public function __construct(
        private readonly SiteSettings $settings,
        private readonly SupportedLocales $supported,
    ) {
    }

    /**
     * The carried locale the site's `default_locale` setting selects.
     *
     * @return  LocaleTag  The setting reduced to a carried locale, or the source locale when the
     *          setting names a language this installation does not carry.
     *
     * @since   2.0.0
     */
    public function locale(): LocaleTag
    {
        if ($this->resolved instanceof LocaleTag) {
            return $this->resolved;
        }

        return $this->resolved = $this->read();
    }

    /**
     * Read and reduce the stored setting, degrading to the source locale on any failure.
     *
     * @return  LocaleTag  The carried locale the setting selects.
     *
     * @since   2.0.0
     */
    private function read(): LocaleTag
    {
        try {
            $stored = $this->settings->current()['default_locale'] ?? null;
        } catch (Throwable) {
            // Negotiation runs ahead of the error boundary on every request, including the liveness
            // probe, so an unavailable settings store degrades the language rather than the response.
            return $this->supported->source();
        }
        if (!is_string($stored) || $stored === '') {
            return $this->supported->source();
        }

        try {
            $candidate = LocaleTag::fromString($stored);
        } catch (InvalidLocaleTag) {
            return $this->supported->source();
        }

        return $this->supported->best($candidate) ?? $this->supported->source();
    }
}
