<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Shared\Infrastructure\Configuration;

use InvalidArgumentException;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Environment::class)]
final class EnvironmentTest extends TestCase
{
    public function testReadsTypedValues(): void
    {
        $environment = new Environment([
            'NAME' => 'Kumwe',
            'ENABLED' => 'yes',
            'LIMIT' => '25',
            'HOSTS' => 'kumwe.test, admin.kumwe.test',
        ]);

        self::assertSame('Kumwe', $environment->string('NAME'));
        self::assertTrue($environment->boolean('ENABLED'));
        self::assertSame(25, $environment->positiveInteger('LIMIT', 10));
        self::assertSame(['kumwe.test', 'admin.kumwe.test'], $environment->commaSeparatedList('HOSTS'));
    }

    public function testMissingRequiredValueIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Environment([]))->string('APP_SECRET');
    }

    public function testInvalidBooleanIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Environment(['ENABLED' => 'sometimes']))->boolean('ENABLED');
    }

    public function testNonPositiveIntegerIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Environment(['LIMIT' => '0']))->positiveInteger('LIMIT', 1);
    }

    public function testDotenvEscapesAreDecodedInOnePass(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'kumwe-env-');
        self::assertIsString($file);
        self::assertNotFalse(file_put_contents(
            $file,
            'APP_TRUSTED_PROXIES="literal\\\\nproxy"' . "\n",
        ));
        $original = getenv('APP_TRUSTED_PROXIES');
        putenv('APP_TRUSTED_PROXIES');

        try {
            self::assertSame('literal\\nproxy', Environment::fromGlobals($file)->string('APP_TRUSTED_PROXIES'));
        } finally {
            unlink($file);
            if (is_string($original)) {
                putenv('APP_TRUSTED_PROXIES=' . $original);
            }
        }
    }

    /**
     * Proves every site and demo selector crosses the process/dotenv allow-list boundary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSiteAndDemoSelectorsAreReadFromDotenv(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'kumwe-env-');
        self::assertIsString($file);
        self::assertNotFalse(file_put_contents(
            $file,
            "APP_PUBLIC_SITE=marketing\n"
            . "KUMWE_SITE_CONTENT_PROFILE=placeholder\n"
            . "KUMWE_BUSINESS_DEMO=false\n",
        ));
        $names = ['APP_PUBLIC_SITE', 'KUMWE_SITE_CONTENT_PROFILE', 'KUMWE_BUSINESS_DEMO'];
        $originals = [];
        foreach ($names as $name) {
            $originals[$name] = getenv($name);
            putenv($name);
        }

        try {
            $environment = Environment::fromGlobals($file);
            self::assertSame('marketing', $environment->string('APP_PUBLIC_SITE'));
            self::assertSame('placeholder', $environment->string('KUMWE_SITE_CONTENT_PROFILE'));
            self::assertFalse($environment->boolean('KUMWE_BUSINESS_DEMO', true));
        } finally {
            unlink($file);
            foreach ($originals as $name => $value) {
                putenv(is_string($value) ? $name . '=' . $value : $name);
            }
        }
    }

    /**
     * The Studio browser asset origin is an allow-listed deployment setting, read from the process
     * environment like every other `KUMWE_` selector; an unlisted variable never reaches the configuration.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStudioBrowserBaseUrlIsReadFromTheProcessEnvironment(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'kumwe-env-');
        self::assertIsString($file);
        self::assertNotFalse(file_put_contents($file, ''));
        $names = ['KUMWE_STUDIO_BROWSER_BASE_URL', 'KUMWE_STUDIO_UNLISTED'];
        $originals = [];
        foreach ($names as $name) {
            $originals[$name] = getenv($name);
        }
        putenv('KUMWE_STUDIO_BROWSER_BASE_URL=http://127.0.0.1:8081');
        putenv('KUMWE_STUDIO_UNLISTED=ignored');

        try {
            $environment = Environment::fromGlobals($file);
            self::assertSame(
                'http://127.0.0.1:8081',
                $environment->string('KUMWE_STUDIO_BROWSER_BASE_URL', 'https://cdn.jsdelivr.net/npm'),
            );
            self::assertNull($environment->optionalString('KUMWE_STUDIO_UNLISTED'));
        } finally {
            unlink($file);
            foreach ($originals as $name => $value) {
                putenv(is_string($value) ? $name . '=' . $value : $name);
            }
        }
    }
}
