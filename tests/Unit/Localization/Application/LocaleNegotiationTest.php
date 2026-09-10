<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Localization\Application;

use Kumwe\App\Localization\Application\LocaleNegotiator;
use Kumwe\App\Localization\Application\SiteDefaultLocale;
use Kumwe\App\Localization\Application\SupportedLocales;
use Kumwe\App\Localization\Domain\InvalidLocaleTag;
use Kumwe\App\Localization\Domain\LocaleTag;
use Kumwe\App\Site\Application\SiteSettings;
use Kumwe\Context\Value\ExecutionContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(LocaleNegotiator::class)]
#[CoversClass(SiteDefaultLocale::class)]
#[CoversClass(SupportedLocales::class)]
final class LocaleNegotiationTest extends TestCase
{
    public function testTheVersionTwoSetIsTheNineDeclaredLanguagesWithEnGbAsTheSource(): void
    {
        $supported = new SupportedLocales();

        self::assertSame(
            ['en-GB', 'en-US', 'af', 'de', 'he', 'ar', 'es', 'pt-BR', 'zh-Hans'],
            $supported->tags(),
        );
        self::assertSame('en-GB', $supported->source()->toString());
        self::assertTrue($supported->carries(LocaleTag::fromString('zh-Hans')));
        self::assertFalse($supported->carries(LocaleTag::fromString('zh-Hant')));
    }

    public function testTheShippedDefaultLocaleOfPlainEnglishResolvesToTheSourceLocale(): void
    {
        $supported = new SupportedLocales();

        self::assertSame('en-GB', $supported->best(LocaleTag::fromString('en'))?->toString());
        self::assertSame('en-US', $supported->best(LocaleTag::fromString('en-US'))?->toString());
        self::assertSame('pt-BR', $supported->best(LocaleTag::fromString('pt-PT'))?->toString());
        self::assertNull($supported->best(LocaleTag::fromString('ja')));
    }

    public function testAnExplicitChoiceOutranksTheClientPreferenceAndTheSiteSetting(): void
    {
        $negotiator = $this->negotiator('he');

        self::assertSame('de', $negotiator->negotiate('de', 'ar,en-GB;q=0.8')->toString());
    }

    public function testTheClientPreferenceIsUsedWhenNoExplicitChoiceWasMade(): void
    {
        $negotiator = $this->negotiator('en');

        self::assertSame('ar', $negotiator->negotiate(null, 'ja;q=0.9, ar;q=0.8, de;q=0.4')->toString());
        self::assertSame('de', $negotiator->negotiate(null, 'de, af;q=0.1')->toString());
    }

    public function testTheSiteDefaultLocaleIsWhatDecidesWhenNothingElseExpressedAPreference(): void
    {
        self::assertSame('he', $this->negotiator('he')->negotiate(null, '')->toString());
        self::assertSame('en-GB', $this->negotiator('en')->negotiate(null, '')->toString());
        self::assertSame('af', $this->negotiator('af')->negotiate(null, '*')->toString());
    }

    public function testAnUnrecognisedOrRefusedPreferenceFallsThroughRatherThanFailing(): void
    {
        $negotiator = $this->negotiator('de');

        self::assertSame('de', $negotiator->negotiate('not a locale', '')->toString());
        self::assertSame('de', $negotiator->negotiate('ja', 'ja')->toString());
        self::assertSame('de', $negotiator->negotiate(null, 'ar;q=0')->toString());
        self::assertSame('de', $negotiator->negotiate(null, str_repeat('en-GB,', 200))->toString());
    }

    public function testAStoredLocaleTheInstallationDoesNotCarryDegradesToTheSourceLocale(): void
    {
        self::assertSame('en-GB', $this->negotiator('ja')->negotiate(null, '')->toString());
        self::assertSame('en-GB', $this->negotiator('not-a-locale-value')->negotiate(null, '')->toString());
    }

    public function testAnUnavailableSettingsStoreDegradesTheLanguageRatherThanTheResponse(): void
    {
        $negotiator = new LocaleNegotiator(
            new SupportedLocales(),
            new SiteDefaultLocale($this->failingSettings(), new SupportedLocales()),
        );

        self::assertSame('en-GB', $negotiator->negotiate(null, '')->toString());
    }

    public function testTheSiteSettingIsReadOnceRatherThanOncePerRequest(): void
    {
        $settings = $this->countingSettings('de');
        $default = new SiteDefaultLocale($settings, new SupportedLocales());

        $default->locale();
        $default->locale();
        $default->locale();

        self::assertSame(1, $settings->reads);
    }

    public function testARegistryWhoseSourceIsNotCarriedIsRefused(): void
    {
        $this->expectException(InvalidLocaleTag::class);

        new SupportedLocales(['de', 'af'], 'en-GB');
    }

    private function negotiator(string $storedLocale): LocaleNegotiator
    {
        $supported = new SupportedLocales();

        return new LocaleNegotiator(
            $supported,
            new SiteDefaultLocale($this->countingSettings($storedLocale), $supported),
        );
    }

    private function countingSettings(string $storedLocale): SiteSettings
    {
        return new class ($storedLocale) implements SiteSettings {
            public int $reads = 0;

            public function __construct(private readonly string $storedLocale)
            {
            }

            public function current(): array
            {
                $this->reads++;

                return ['default_locale' => $this->storedLocale];
            }

            public function managed(ExecutionContext $context): array
            {
                return $this->current();
            }

            public function update(ExecutionContext $context, string $siteName, string $homepageSlug): void
            {
            }

            public function updateAll(ExecutionContext $context, array $settings): void
            {
            }
        };
    }

    private function failingSettings(): SiteSettings
    {
        return new class implements SiteSettings {
            public function current(): array
            {
                throw new RuntimeException('The settings store is unavailable.');
            }

            public function managed(ExecutionContext $context): array
            {
                return $this->current();
            }

            public function update(ExecutionContext $context, string $siteName, string $homepageSlug): void
            {
            }

            public function updateAll(ExecutionContext $context, array $settings): void
            {
            }
        };
    }
}
