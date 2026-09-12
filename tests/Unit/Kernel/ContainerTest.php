<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Kernel;

use Kumwe\App\Kernel\Container;
use Laminas\ServiceManager\Exception\ContainerModificationsNotAllowedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use stdClass;

#[CoversClass(Container::class)]
/**
 * Verifies the Laminas-backed application container keeps the composition root's registration contract.
 *
 * @since  2.0.0
 */
final class ContainerTest extends TestCase
{
    /**
     * A closure registration materializes lazily, exactly once, and its factory receives this container.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testClosureRegistrationResolvesLazilyAsASingletonBuiltWithThisContainer(): void
    {
        $container = new Container();
        $invocations = 0;
        $received = null;
        $container->share(
            'application.service',
            static function (ContainerInterface $creationContext) use (&$invocations, &$received): stdClass {
                $invocations++;
                $received = $creationContext;

                return new stdClass();
            },
        );

        self::assertSame(0, $invocations, 'The factory must not run before the service is first resolved.');

        $first = $container->get('application.service');

        self::assertSame($container, $received, 'The factory must receive the wrapper container itself.');
        self::assertSame($first, $container->get('application.service'));
        self::assertSame(1, $invocations, 'A shared service materializes exactly once.');
    }

    /**
     * Package definitions keep canonical aliases and non-shared service lifetimes in the host container.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPackageDefinitionsPreserveFactoriesAliasesAndLifetimes(): void
    {
        $container = new Container();
        $container->configure((new \Kumwe\Localization\ConfigProvider())()['dependencies']);
        $supported = new \Kumwe\Localization\Application\SupportedLocales();
        $container->share(\Kumwe\Localization\Application\SupportedLocales::class, $supported);
        $container->share(
            \Kumwe\Localization\Application\DefaultLocaleProvider::class,
            new class implements \Kumwe\Localization\Application\DefaultLocaleProvider {
                /**
                 * Supply the host-selected default for this operation.
                 *
                 * @return  \Kumwe\Localization\Domain\LocaleTag  The carried Afrikaans locale.
                 *
                 * @since   2.0.0
                 */
                public function locale(): \Kumwe\Localization\Domain\LocaleTag
                {
                    return \Kumwe\Localization\Domain\LocaleTag::fromString('af');
                }
            },
        );

        $first = $container->get(\Kumwe\Localization\Application\LocaleNegotiator::class);
        self::assertInstanceOf(\Kumwe\Localization\Application\LocaleNegotiator::class, $first);
        self::assertSame('af', $first->negotiate(null, '')->toString());
        self::assertNotSame($first, $container->get(\Kumwe\Localization\Application\LocaleNegotiator::class));
        self::assertTrue($container->has(\Kumwe\Localization\Application\Translator::class));
    }

    /**
     * A ready instance registered under an identifier is served back as that very instance.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInstanceRegistrationServesThatInstance(): void
    {
        $container = new Container();
        $service = new stdClass();
        $container->share(stdClass::class, $service);

        self::assertSame($service, $container->get(stdClass::class));
    }

    /**
     * A configuration array is a registrable value and is served back unchanged.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testConfigurationArrayRegistrationServesTheArrayUnchanged(): void
    {
        $container = new Container();
        $configuration = ['paths' => ['cache' => '/tmp/cache'], 'debug' => false];
        $container->share('config', $configuration);

        self::assertSame($configuration, $container->get('config'));
    }

    /**
     * An alias resolves to the same shared instance as the identifier it points at.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAliasResolvesToTheAliasedRegistration(): void
    {
        $container = new Container();
        $service = new stdClass();
        $container->share('canonical.service', $service);
        $container->alias('alias.service', 'canonical.service');

        self::assertTrue($container->has('alias.service'));
        self::assertSame($service, $container->get('alias.service'));
    }

    /**
     * Resolving an unregistered identifier fails with the standard PSR-11 not-found contract.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testResolvingAnUnknownIdentifierThrowsTheStandardNotFound(): void
    {
        $container = new Container();

        $this->expectException(NotFoundExceptionInterface::class);

        $container->get('unregistered.service');
    }

    /**
     * Re-registering an identifier whose service has materialized throws instead of silently replacing it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOverwritingAMaterializedRegistrationThrows(): void
    {
        $container = new Container();
        $container->share('application.service', static fn (): stdClass => new stdClass());
        $container->get('application.service');

        $this->expectException(ContainerModificationsNotAllowedException::class);

        $container->share('application.service', new stdClass());
    }

    /**
     * The lookup probe reports registered identifiers and denies unknown ones.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testHasTellsWhetherAnIdentifierIsRegistered(): void
    {
        $container = new Container();
        $container->share('application.service', static fn (): stdClass => new stdClass());

        self::assertTrue($container->has('application.service'));
        self::assertFalse($container->has('unregistered.service'));
    }
}
