<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use Kumwe\Localization\Application\ActiveLocale;
use Kumwe\Localization\Application\MessageCatalogueRepository;
use Kumwe\Localization\Application\MessageOverrideRepository;
use Kumwe\Localization\Application\SupportedLocales;
use Kumwe\Localization\Domain\InvalidLocaleTag;
use Kumwe\Localization\Domain\LocaleTag;
use Kumwe\Localization\Domain\MessageCatalogue;
use Kumwe\Localization\Domain\MessageCatalogueChain;
use Kumwe\Localization\Domain\MessageCatalogueLayer;
use Kumwe\Producer\Wire\HostResult;
use Kumwe\Producer\Wire\Port\LocalizationPortInterface;
use Kumwe\Producer\Wire\RequestContext;
use stdClass;

/**
 * Studio localization port over App's compiled catalogue and site/organization override chain.
 *
 * @since  2.0.0
 */
final readonly class StudioLocalizationHostPort implements LocalizationPortInterface
{
    /**
     * Bind compiled catalogues, effective overrides, locale scope, and carried locale inventory.
     *
     * @param  MessageCatalogueRepository           $catalogues  Compiled core and extension layers.
     * @param  MessageOverrideRepository            $overrides   Site and organization wording.
     * @param  ActiveLocale                         $active      Trusted request override scope.
     * @param  SupportedLocales                     $supported   Exact carried-locale registry.
     * @param  StudioProducerRequestAuthority|null  $authority   Authorized Producer request scope, when bound.
     *
     * @since  2.0.0
     */
    public function __construct(
        private MessageCatalogueRepository $catalogues,
        private MessageOverrideRepository $overrides,
        private ActiveLocale $active,
        private SupportedLocales $supported,
        private ?StudioProducerRequestAuthority $authority = null,
    ) {
    }

    /**
     * Bind this App-owned port implementation to one successfully authorized Producer request.
     *
     * @param   StudioProducerRequestAuthority  $authority  Trusted evidence for one exact dispatch.
     *
     * @return  self  Request-scoped localization port.
     *
     * @since   2.0.0
     */
    public function forRequest(StudioProducerRequestAuthority $authority): self
    {
        return new self($this->catalogues, $this->overrides, $this->active, $this->supported, $authority);
    }

    /**
     * Resolve a closed namespace request without formatting unresolved ICU parameters.
     *
     * @param   mixed           $arguments  Validated Producer operation arguments.
     * @param   RequestContext  $context    Validated Producer request context.
     *
     * @return  HostResult  Canonical localized message map.
     *
     * @since   2.0.0
     */
    public function messages(mixed $arguments, RequestContext $context): HostResult
    {
        $this->requestAuthority();
        if ($context->expectedRevision !== null || $context->idempotencyKey !== null) {
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-context');
        }
        if (!$arguments instanceof stdClass || self::members($arguments) !== ['locale', 'namespaces']) {
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-arguments');
        }
        $localeValue = $arguments->locale;
        $namespaces = $arguments->namespaces;
        if (
            !is_string($localeValue)
            || !is_array($namespaces)
            || !array_is_list($namespaces)
            || $namespaces === []
            || count($namespaces) > 16
        ) {
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-arguments');
        }
        foreach ($namespaces as $namespace) {
            if (
                !is_string($namespace)
                || strlen($namespace) > 120
                || preg_match('/^[a-z][a-z0-9.-]*$/D', $namespace) !== 1
            ) {
                StudioProducerError::refuse('invalid-request', 'studio.host/invalid-arguments');
            }
        }
        try {
            $locale = LocaleTag::fromString($localeValue);
        } catch (InvalidLocaleTag) {
            StudioProducerError::refuse('not-found', 'studio.localization/locale-not-found');
        }
        if (!$this->supported->carries($locale)) {
            StudioProducerError::refuse('not-found', 'studio.localization/locale-not-found');
        }

        $messages = new stdClass();
        if (in_array('studio.shell', $namespaces, true)) {
            foreach ($this->studioMessageIdentifiers() as $identifier) {
                $pattern = $this->pattern($identifier, $locale);
                if ($pattern !== null) {
                    $messages->{self::wireIdentifier($identifier)} = $pattern;
                }
            }
        }

        return new HostResult($messages);
    }

    /**
     * Require the per-request authority installed by the Producer host factory.
     *
     * @return  StudioProducerRequestAuthority  Trusted evidence for this dispatch.
     *
     * @since   2.0.0
     */
    private function requestAuthority(): StudioProducerRequestAuthority
    {
        return $this->authority ?? throw new \LogicException('A Studio localization port requires request authority.');
    }

    /**
     * Discover the exact Studio message keys carried by compiled core and extension layers.
     *
     * @return  list<string>
     *
     * @since   2.0.0
     */
    private function studioMessageIdentifiers(): array
    {
        $identifiers = [];
        foreach ($this->supported->all() as $locale) {
            foreach ([MessageCatalogueLayer::Core, MessageCatalogueLayer::Extension] as $layer) {
                foreach ($this->catalogues->catalogue($layer, $locale)->identifiers() as $identifier) {
                    if (str_starts_with($identifier, 'core.studio.shell.')) {
                        $identifiers[$identifier] = true;
                    }
                }
            }
        }
        ksort($identifiers, SORT_STRING);

        return array_keys($identifiers);
    }

    /**
     * Resolve one Studio message through locale fallbacks and effective override layers.
     *
     * @param   string     $identifier  Internal compiled message identifier.
     * @param   LocaleTag  $locale      Requested carried locale.
     *
     * @return  ?string  Effective message pattern, or null when unavailable.
     *
     * @since   2.0.0
     */
    private function pattern(string $identifier, LocaleTag $locale): ?string
    {
        foreach (
            array_values(array_unique(array_merge(
                $locale->fallbacks(),
                $this->supported->source()->fallbacks(),
            ))) as $tag
        ) {
            $resolved = $this->chain(LocaleTag::fromString($tag))->resolve($identifier);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * Build the organization, site, extension, and core catalogue chain for one locale.
     *
     * @param   LocaleTag  $locale  Carried locale to resolve.
     *
     * @return  MessageCatalogueChain  Effective ordered message layers.
     *
     * @since   2.0.0
     */
    private function chain(LocaleTag $locale): MessageCatalogueChain
    {
        $scope = $this->active->scope();
        $organization = $scope->organization;

        return new MessageCatalogueChain($locale, [
            new MessageCatalogue(
                $locale,
                MessageCatalogueLayer::Organization,
                $organization === null ? [] : $this->overrides->organizationOverrides(
                    $scope->site,
                    $organization,
                    $locale,
                ),
            ),
            new MessageCatalogue(
                $locale,
                MessageCatalogueLayer::Site,
                $this->overrides->siteOverrides($scope->site, $locale),
            ),
            $this->catalogues->catalogue(MessageCatalogueLayer::Extension, $locale),
            $this->catalogues->catalogue(MessageCatalogueLayer::Core, $locale),
        ]);
    }

    /**
     * Convert an internal App Studio key to the alpha.11 shell wire identifier.
     *
     * @param   string  $identifier  Internal compiled message identifier.
     *
     * @return  string  Studio shell wire identifier.
     *
     * @since   2.0.0
     */
    private static function wireIdentifier(string $identifier): string
    {
        return 'studio.shell/' . substr($identifier, strlen('core.studio.shell.'));
    }

    /**
     * Return deterministic object member names for exact envelope validation.
     *
     * @param   stdClass  $document  Candidate protocol object.
     *
     * @return  list<string>
     *
     * @since   2.0.0
     */
    private static function members(stdClass $document): array
    {
        $members = array_keys(get_object_vars($document));
        sort($members, SORT_STRING);

        return $members;
    }
}
