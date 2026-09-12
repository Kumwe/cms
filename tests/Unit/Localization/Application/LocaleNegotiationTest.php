<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Localization\Application;

use Kumwe\Localization\Application\LocaleNegotiator;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Localization\Application\SiteDefaultLocale;
use Kumwe\Localization\Application\SupportedLocales;
use Kumwe\App\Site\Application\SiteSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(SiteDefaultLocale::class)]
final class LocaleNegotiationTest extends TestCase
{
    public function testTheSiteDefaultLocaleIsWhatDecidesWhenNothingElseExpressedAPreference(): void
    {
        self::assertSame('he', $this->negotiator('he')->negotiate(null, '')->toString());
        self::assertSame('en-GB', $this->negotiator('en')->negotiate(null, '')->toString());
        self::assertSame('af', $this->negotiator('af')->negotiate(null, '*')->toString());
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
