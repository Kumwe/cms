<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Kernel\Configuration;

use InvalidArgumentException;
use Kumwe\App\Extension\Application\Package\PackageConformanceMode;
use Kumwe\App\Kernel\Configuration\ApplicationConfiguration;
use Kumwe\App\Kernel\Configuration\ConfigurationFactory;
use Kumwe\App\Kernel\Configuration\DatabaseConfiguration;
use Kumwe\App\Kernel\Configuration\RuntimeEnvironment;
use Kumwe\App\Kernel\Configuration\RedisConfiguration;
use Kumwe\App\Kernel\Configuration\RevocationFeedConfiguration;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigurationFactory::class)]
#[CoversClass(ApplicationConfiguration::class)]
#[CoversClass(DatabaseConfiguration::class)]
#[CoversClass(RedisConfiguration::class)]
#[CoversClass(RuntimeEnvironment::class)]
#[CoversClass(RevocationFeedConfiguration::class)]
final class ConfigurationFactoryTest extends TestCase
{
    public function testCreatesProductionConfiguration(): void
    {
        $configuration = (new ConfigurationFactory())->create(new Environment($this->values()));

        self::assertSame(RuntimeEnvironment::Production, $configuration->environment);
        self::assertTrue($configuration->isProduction());
        self::assertSame(['kumwe.test'], $configuration->trustedHosts);
        self::assertSame('default', $configuration->publicSite);
        self::assertSame('documentation', $configuration->siteContentProfile);
        self::assertSame('vdm', $configuration->businessProfile);
        self::assertSame('kumwe_', $configuration->database->tablePrefix);
        self::assertSame('pgsql', $configuration->database->driver);
        self::assertSame('redis', $configuration->redis->host);
        self::assertSame('kumwe.app', $configuration->redis->namespace);
    }

    public function testProductionRequiresHttps(): void
    {
        $values = $this->values();
        $values['APP_BASE_URL'] = 'http://kumwe.test';
        $this->expectException(InvalidArgumentException::class);

        (new ConfigurationFactory())->create(new Environment($values));
    }

    public function testProductionRefusesUnsignedLocalExtensions(): void
    {
        $values = $this->values();
        $values['EXTENSIONS_ALLOW_UNSIGNED_LOCAL'] = 'true';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('EXTENSIONS_ALLOW_UNSIGNED_LOCAL must be false when APP_ENV=production');

        (new ConfigurationFactory())->create(new Environment($values));
    }

    public function testDevelopmentAndTestingStillAllowUnsignedLocalExtensions(): void
    {
        foreach (['development', 'testing'] as $runtime) {
            $values = $this->values();
            $values['APP_ENV'] = $runtime;
            $values['APP_BASE_URL'] = 'http://localhost:8080';
            $values['EXTENSIONS_ALLOW_UNSIGNED_LOCAL'] = 'true';
            $configuration = (new ConfigurationFactory())->create(new Environment($values));

            self::assertTrue($configuration->allowUnsignedLocalExtensions);
            self::assertFalse($configuration->isProduction());
        }
    }

    public function testProductionRefusesDisablingInstallTimeConformanceScanning(): void
    {
        $values = $this->values();
        $values['EXTENSIONS_CONFORMANCE_ADMISSION'] = 'off';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('EXTENSIONS_CONFORMANCE_ADMISSION must be scan');

        (new ConfigurationFactory())->create(new Environment($values));
    }

    /**
     * Proves the default scans authoring evidence while Off remains an explicit non-production choice.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testConformanceAdmissionDefaultsToScan(): void
    {
        $configuration = (new ConfigurationFactory())->create(new Environment($this->values()));
        self::assertSame(PackageConformanceMode::Scan, $configuration->packageConformanceAdmission);

        $values = $this->values();
        $values['APP_ENV'] = 'testing';
        $values['EXTENSIONS_CONFORMANCE_ADMISSION'] = 'off';
        $off = (new ConfigurationFactory())->create(new Environment($values));
        self::assertSame(PackageConformanceMode::Off, $off->packageConformanceAdmission);
    }

    /**
     * Proves the spellings the variable accepted before the rename still boot and select the scanning posture.
     *
     * `enforce` and `warn` were the documented values, and `enforce` the shipped default, until the mode set
     * was reduced to `scan` and `off`. Both ran the scan, so both resolve to `Scan`, in production as well as
     * development and in any letter case, rather than stopping an existing installation at configuration time.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPreRenameConformanceAdmissionSpellingsStillSelectScan(): void
    {
        foreach (['enforce', 'warn', 'ENFORCE', 'Warn', ' enforce '] as $spelling) {
            $values = $this->values();
            $values['EXTENSIONS_CONFORMANCE_ADMISSION'] = $spelling;
            $production = (new ConfigurationFactory())->create(new Environment($values));
            self::assertSame(PackageConformanceMode::Scan, $production->packageConformanceAdmission, $spelling);

            $values['APP_ENV'] = 'development';
            $values['APP_BASE_URL'] = 'http://localhost:8080';
            $development = (new ConfigurationFactory())->create(new Environment($values));
            self::assertSame(PackageConformanceMode::Scan, $development->packageConformanceAdmission, $spelling);
        }
    }

    public function testUnknownConformanceAdmissionModeIsRefusedRatherThanDefaulted(): void
    {
        $values = $this->values();
        $values['EXTENSIONS_CONFORMANCE_ADMISSION'] = 'audit';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('EXTENSIONS_CONFORMANCE_ADMISSION must be scan or off');

        (new ConfigurationFactory())->create(new Environment($values));
    }

    public function testRevocationFeedIsDisabledUntilBothOriginAndKeyAreConfigured(): void
    {
        $configuration = (new ConfigurationFactory())->create(new Environment($this->values()));
        self::assertFalse($configuration->revocationFeed->isEnabled());

        $values = $this->values();
        $values['EXTENSIONS_REVOCATION_FEED_URL'] = 'https://revocations.kumwe.test/list.json';
        $this->expectException(InvalidArgumentException::class);

        (new ConfigurationFactory())->create(new Environment($values));
    }

    public function testConfiguredRevocationFeedIsAcceptedWithItsPinnedKey(): void
    {
        $values = $this->values();
        $values['EXTENSIONS_REVOCATION_FEED_URL'] = 'https://revocations.kumwe.test/list.json';
        $values['EXTENSIONS_REVOCATION_FEED_KEY'] = base64_encode(
            str_repeat("\x07", SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES),
        );
        $values['EXTENSIONS_REVOCATION_FEED_MAX_STALE_SECONDS'] = '86400';
        $configuration = (new ConfigurationFactory())->create(new Environment($values));

        self::assertTrue($configuration->revocationFeed->isEnabled());
        self::assertSame('https://revocations.kumwe.test/list.json', $configuration->revocationFeed->origin);
        self::assertSame(86_400, $configuration->revocationFeed->maxStaleSeconds);
    }

    public function testSecretMustContainAtLeastThirtyTwoBytes(): void
    {
        $values = $this->values();
        $values['APP_SECRET'] = 'too-short';
        $this->expectException(InvalidArgumentException::class);

        (new ConfigurationFactory())->create(new Environment($values));
    }

    public function testTrustedProxyRangesAreValidatedDuringConfiguration(): void
    {
        $values = $this->values();
        $values['APP_TRUSTED_PROXIES'] = '10.0.0.0/8,2001:db8::/129';
        $this->expectException(InvalidArgumentException::class);

        (new ConfigurationFactory())->create(new Environment($values));
    }

    public function testProductionRequiresIndependentRuntimeSigningKey(): void
    {
        $values = $this->values();
        unset($values['EXTENSION_RUNTIME_SIGNING_KEY']);
        $this->expectException(InvalidArgumentException::class);

        (new ConfigurationFactory())->create(new Environment($values));
    }

    public function testRuntimeSigningKeyCannotReuseApplicationSecret(): void
    {
        $values = $this->values();
        $values['EXTENSION_RUNTIME_SIGNING_KEY'] = $values['APP_SECRET'];
        $this->expectException(InvalidArgumentException::class);

        (new ConfigurationFactory())->create(new Environment($values));
    }

    public function testRuntimeProcessIdentityMustBeStableIdentifier(): void
    {
        $values = $this->values();
        $values['KUMWE_PROCESS_ID'] = 'random process request';
        $this->expectException(InvalidArgumentException::class);

        (new ConfigurationFactory())->create(new Environment($values));
    }

    public function testPublicSiteMustBeCanonicalIdentifier(): void
    {
        $values = $this->values();
        $values['APP_PUBLIC_SITE'] = 'Invalid Site';
        $this->expectException(InvalidArgumentException::class);

        (new ConfigurationFactory())->create(new Environment($values));
    }

    /**
     * Proves an operator may select either non-default content profile and omit the business dataset.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExplicitDemoProfileSelectionIsPreserved(): void
    {
        $values = $this->values();
        $values['KUMWE_SITE_CONTENT_PROFILE'] = 'placeholder';
        $values['KUMWE_BUSINESS_DEMO'] = 'off';

        $configuration = (new ConfigurationFactory())->create(new Environment($values));

        self::assertSame('placeholder', $configuration->siteContentProfile);
        self::assertSame('none', $configuration->businessProfile);

        $values['KUMWE_SITE_CONTENT_PROFILE'] = 'blank';
        $configuration = (new ConfigurationFactory())->create(new Environment($values));
        self::assertSame('blank', $configuration->siteContentProfile);
        self::assertSame('none', $configuration->businessProfile);
    }

    /**
     * Proves the named business selector wins over the legacy boolean without disabling the alias.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNamedBusinessProfileOverridesLegacyBoolean(): void
    {
        $values = $this->values();
        $values['KUMWE_BUSINESS_DEMO'] = 'off';
        $values['KUMWE_BUSINESS_PROFILE'] = 'farming';

        $configuration = (new ConfigurationFactory())->create(new Environment($values));

        self::assertSame('farming', $configuration->businessProfile);
    }

    /**
     * Proves a malformed site-content profile fails during bootstrap instead of reaching reconciliation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMalformedSiteContentProfileIsRejected(): void
    {
        $values = $this->values();
        $values['KUMWE_SITE_CONTENT_PROFILE'] = 'Company Demo';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('KUMWE_SITE_CONTENT_PROFILE');

        (new ConfigurationFactory())->create(new Environment($values));
    }

    /**
     * Proves a malformed business profile selector fails during bootstrap with its own diagnostic.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMalformedBusinessProfileIsRejected(): void
    {
        $values = $this->values();
        $values['KUMWE_BUSINESS_PROFILE'] = '../vdm';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('KUMWE_BUSINESS_PROFILE');

        (new ConfigurationFactory())->create(new Environment($values));
    }

    public function testPreviousRuntimeKeyRingCanBeReadFromProtectedFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'kumwe-runtime-keys-');
        self::assertIsString($file);
        file_put_contents($file, json_encode(['runtime-v0' => str_repeat('p', 32)], JSON_THROW_ON_ERROR));
        try {
            $values = $this->values();
            $values['EXTENSION_RUNTIME_PREVIOUS_KEYS_FILE'] = $file;
            $configuration = (new ConfigurationFactory())->create(new Environment($values));

            self::assertSame(['runtime-v0' => str_repeat('p', 32)], $configuration->runtimePreviousSigningKeys);
        } finally {
            unlink($file);
        }
    }

    public function testEmptyPreviousRuntimeKeyRingIsAnObjectRatherThanAList(): void
    {
        $values = $this->values();
        $values['EXTENSION_RUNTIME_PREVIOUS_KEYS'] = '{}';
        $configuration = (new ConfigurationFactory())->create(new Environment($values));
        self::assertSame([], $configuration->runtimePreviousSigningKeys);

        $values['EXTENSION_RUNTIME_PREVIOUS_KEYS'] = '[]';
        $this->expectException(InvalidArgumentException::class);
        (new ConfigurationFactory())->create(new Environment($values));
    }

    public function testRecordEncryptionMaterialDefaultsToNothingConfigured(): void
    {
        $configuration = (new ConfigurationFactory())->create(new Environment($this->values()));

        self::assertNull($configuration->recordEncryption->activeKey);
        self::assertNull($configuration->recordEncryption->activeKeyId);
        self::assertNull($configuration->recordEncryption->legacySecret);
        self::assertSame([], $configuration->recordEncryption->previousKeys);
    }

    public function testRecordEncryptionSecretsCanBeReadFromProtectedFiles(): void
    {
        $key = tempnam(sys_get_temp_dir(), 'kumwe-record-key-');
        $previous = tempnam(sys_get_temp_dir(), 'kumwe-record-previous-');
        self::assertIsString($key);
        self::assertIsString($previous);
        file_put_contents($key, str_repeat('r', 40) . "\n");
        file_put_contents($previous, json_encode(['record-v0' => str_repeat('q', 40)], JSON_THROW_ON_ERROR));
        try {
            $values = $this->values();
            $values['RECORD_ENCRYPTION_KEY_FILE'] = $key;
            $values['RECORD_ENCRYPTION_KEY_ID'] = 'record-v1';
            $values['RECORD_ENCRYPTION_PREVIOUS_KEYS_FILE'] = $previous;
            $configuration = (new ConfigurationFactory())->create(new Environment($values));

            self::assertSame(str_repeat('r', 40), $configuration->recordEncryption->activeKey);
            self::assertSame('record-v1', $configuration->recordEncryption->activeKeyId);
            self::assertSame(['record-v0' => str_repeat('q', 40)], $configuration->recordEncryption->previousKeys);
        } finally {
            unlink($key);
            unlink($previous);
        }
    }

    public function testApplicationSecretCanBeReadFromAProtectedFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'kumwe-app-secret-');
        self::assertIsString($file);
        file_put_contents($file, str_repeat('s', 40) . "\n");
        try {
            $values = $this->values();
            unset($values['APP_SECRET']);
            $values['APP_SECRET_FILE'] = $file;
            $configuration = (new ConfigurationFactory())->create(new Environment($values));

            self::assertSame(str_repeat('s', 40), $configuration->secret);
        } finally {
            unlink($file);
        }
    }

    public function testASecretSuppliedBothInlineAndByFileIsRefused(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'kumwe-record-key-');
        self::assertIsString($file);
        file_put_contents($file, str_repeat('r', 40));
        try {
            $values = $this->values();
            $values['RECORD_ENCRYPTION_KEY'] = str_repeat('r', 40);
            $values['RECORD_ENCRYPTION_KEY_FILE'] = $file;

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Configure RECORD_ENCRYPTION_KEY by value or by file, never both.');
            (new ConfigurationFactory())->create(new Environment($values));
        } finally {
            unlink($file);
        }
    }

    public function testARecordKeyIdentifierWithoutItsKeyIsRefused(): void
    {
        $values = $this->values();
        $values['RECORD_ENCRYPTION_KEY_ID'] = 'record-v1';

        $configuration = (new ConfigurationFactory())->create(new Environment($values));
        self::assertNull($configuration->recordEncryption->activeKeyId);
    }

    /**
     * The Studio browser asset origin is validated when the configuration is built, before any page could
     * embed a module URL nobody can load; a query string is one of the forms the locator refuses.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnusableStudioBrowserBaseUrlIsRefused(): void
    {
        $values = $this->values();
        $values['KUMWE_STUDIO_BROWSER_BASE_URL'] = 'https://cdn.example.test/npm?cache=false';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('KUMWE_STUDIO_BROWSER_BASE_URL must be an absolute HTTPS URL');

        (new ConfigurationFactory())->create(new Environment($values));
    }

    /**
     * @return array<string, string>
     */
    private function values(): array
    {
        return [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_BASE_URL' => 'https://kumwe.test',
            'APP_TRUSTED_HOSTS' => 'kumwe.test',
            'APP_SECRET' => str_repeat('a', 32),
            'EXTENSION_RUNTIME_SIGNING_KEY' => str_repeat('r', 32),
            'KUMWE_DEPLOYMENT_ID' => 'deployment-2026-08-05',
            'KUMWE_REPLICA_ID' => 'replica-one',
            'KUMWE_PROCESS_ID' => 'app-runtime',
            'KUMWE_INSTANCE_ID' => 'instance-one',
            'DB_HOST' => 'postgres',
            'DB_DRIVER' => 'pgsql',
            'DB_PORT' => '5432',
            'DB_NAME' => 'kumwe',
            'DB_USER' => 'kumwe',
            'DB_PASSWORD' => 'secret',
            'DB_TABLE_PREFIX' => 'kumwe_',
            'DB_SERVER_VERSION' => '17',
            'DB_SSLMODE' => 'require',
        ];
    }
}
