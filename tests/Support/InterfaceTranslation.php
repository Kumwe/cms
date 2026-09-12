<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use Kumwe\Localization\Application\ActiveLocale;
use Kumwe\Localization\Application\CatalogueTranslator;
use Kumwe\Localization\Application\MessageOverrideRepository;
use Kumwe\Localization\Application\SupportedLocales;
use Kumwe\Localization\Application\TranslationScope;
use Kumwe\Localization\Domain\LocaleTag;
use Kumwe\App\Localization\Infrastructure\ArrayMessageOverrideRepository;
use Kumwe\App\Localization\Infrastructure\CompiledMessageCatalogueRepository;
use Kumwe\Localization\Infrastructure\IntlMessagePatternFormatter;
use Kumwe\App\Localization\Presentation\TranslationTwigExtension;

/** Builds a translator over the repository's own compiled catalogues, as the container does. */
final class InterfaceTranslation
{
    /** Assemble a translator reading the real `resources/localization/compiled` directory. */
    public static function translator(
        ?string $locale = null,
        ?MessageOverrideRepository $overrides = null,
    ): CatalogueTranslator {
        $supported = new SupportedLocales();
        $active = new ActiveLocale($supported);
        $active->begin(
            LocaleTag::fromString($locale ?? SupportedLocales::SOURCE),
            TranslationScope::default(),
        );

        return new CatalogueTranslator(
            new CompiledMessageCatalogueRepository(self::compiledDirectory()),
            $overrides ?? new ArrayMessageOverrideRepository(),
            new IntlMessagePatternFormatter(),
            $active,
            $supported,
        );
    }

    /** An active-locale holder with a unit of work open at the requested (or source) locale. */
    public static function activeLocale(?string $locale = null): ActiveLocale
    {
        $active = new ActiveLocale(new SupportedLocales());
        $active->begin(
            LocaleTag::fromString($locale ?? SupportedLocales::SOURCE),
            TranslationScope::default(),
        );

        return $active;
    }

    /** Build the Twig extension the three rendering surfaces are given in production. */
    public static function twigExtension(?string $locale = null): TranslationTwigExtension
    {
        $supported = new SupportedLocales();
        $active = new ActiveLocale($supported);
        $active->begin(
            LocaleTag::fromString($locale ?? SupportedLocales::SOURCE),
            TranslationScope::default(),
        );

        return new TranslationTwigExtension(
            new CatalogueTranslator(
                new CompiledMessageCatalogueRepository(self::compiledDirectory()),
                new ArrayMessageOverrideRepository(),
                new IntlMessagePatternFormatter(),
                $active,
                $supported,
            ),
            $active,
        );
    }

    /** Absolute path of the compiled catalogue directory the build publishes. */
    public static function compiledDirectory(): string
    {
        return dirname(__DIR__, 2) . '/resources/localization/compiled';
    }
}
