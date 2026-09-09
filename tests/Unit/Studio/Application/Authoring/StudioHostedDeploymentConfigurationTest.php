<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Authoring;

use InvalidArgumentException;
use Kumwe\App\Studio\Application\Authoring\StudioContextualAuthoringConfiguration;
use Kumwe\App\Studio\Application\Authoring\StudioHostedDeploymentConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Proves the emitted deployment value carries only usable delivery facts.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioHostedDeploymentConfiguration::class)]
final class StudioHostedDeploymentConfigurationTest extends TestCase
{
    /**
     * A proven deployment exposes its identifiers, module coordinates, origin and return path unchanged.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCarriesTheDeliveryFactsUnchanged(): void
    {
        $configuration = new StudioHostedDeploymentConfiguration(
            'kumwe-studio-content',
            'kumwe-studio-content-configuration',
            '{"kind":"studio-deployment"}',
            'https://cdn.jsdelivr.net/npm/@kumwe/studio@0.1.0-beta.3/dist/browser/assets/studio-browser-a.min.js',
            'sha256-bQLLnuYhQprCbuB1LX5Yb09/n+KVS6xB+M7Mdh2/D34=',
            'https://cdn.jsdelivr.net',
            '/administrator/content/abc/edit',
        );

        self::assertInstanceOf(StudioContextualAuthoringConfiguration::class, $configuration);
        self::assertSame('kumwe-studio-content', $configuration->mountId);
        self::assertSame('kumwe-studio-content-configuration', $configuration->configurationId);
        self::assertSame('{"kind":"studio-deployment"}', $configuration->configurationJson);
        self::assertSame('https://cdn.jsdelivr.net', $configuration->scriptOrigin);
        self::assertSame('/administrator/content/abc/edit', $configuration->returnPath);
    }

    /**
     * Same-origin delivery carries no script origin to widen the response policy with.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSameOriginDeliveryCarriesNoScriptOrigin(): void
    {
        $configuration = new StudioHostedDeploymentConfiguration(
            'mount',
            'configuration',
            '{"kind":"studio-deployment"}',
            '/vendor/studio/assets/studio-browser-a.min.js',
            'sha256-bQLLnuYhQprCbuB1LX5Yb09/n+KVS6xB+M7Mdh2/D34=',
            null,
            '/administrator/content',
        );

        self::assertNull($configuration->scriptOrigin);
    }

    /**
     * Unusable identifiers, markup-bearing JSON or a foreign return path are refused at construction.
     *
     * @param   string  $mountId          Candidate mount id.
     * @param   string  $configurationId  Candidate configuration id.
     * @param   string  $json             Candidate inert JSON.
     * @param   string  $returnPath       Candidate return path.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('refusedDeployments')]
    public function testRefusesUnusableDeliveryFacts(
        string $mountId,
        string $configurationId,
        string $json,
        string $returnPath,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        new StudioHostedDeploymentConfiguration(
            $mountId,
            $configurationId,
            $json,
            '/assets/studio.js',
            'sha256-bQLLnuYhQprCbuB1LX5Yb09/n+KVS6xB+M7Mdh2/D34=',
            null,
            $returnPath,
        );
    }

    /**
     * Supply one refused member per closed rule.
     *
     * @return  iterable<string, array{string, string, string, string}>  Named refusals.
     *
     * @since   2.0.0
     */
    public static function refusedDeployments(): iterable
    {
        yield 'mount id with a space' => ['mount id', 'configuration', '{}', '/administrator/content'];
        yield 'configuration id starting with a digit' => ['mount', '1configuration', '{}', '/administrator/content'];
        yield 'identical identifiers' => ['same', 'same', '{}', '/administrator/content'];
        yield 'JSON carrying markup' => ['mount', 'configuration', '{"a":"<script>"}', '/administrator/content'];
        yield 'JSON that is not an object' => ['mount', 'configuration', '[]', '/administrator/content'];
        yield 'return path outside the administrator' => ['mount', 'configuration', '{}', '/'];
    }
}
