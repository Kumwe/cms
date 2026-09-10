<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Functional\BusinessSurface;

use Kumwe\App\Kernel\Container;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\UpdateRecordCommand;
use Kumwe\App\BusinessSurface\Application\BusinessSurface;
use Kumwe\App\BusinessSurface\Delivery\Browser\GeneratedBusinessBrowserController;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Proves the generated record surfaces speak their refusals from the catalogue, not from the class.
 *
 * The two sentences an operator most often meets on a generated surface are a refused save and a lost
 * concurrency race. Both used to be written into the controller; both are now looked up, and this
 * drives the real controller through the real container to prove the wording survived extraction
 * unchanged — the same sentences, at the same statuses, with the typed values still in the form.
 *
 * @since  2.0.0
 */
#[CoversClass(GeneratedBusinessBrowserController::class)]
final class GeneratedBusinessSurfaceWordingTest extends TestCase
{
    /**
     * Container shared across this file's cases, because installing a definition is expensive.
     *
     * @var    ?Container
     * @since  2.0.0
     */
    private static ?Container $kernel = null;

    /**
     * A refused save and a lost race each report themselves in resolved catalogue wording.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRefusalsAndConflictsAreReportedInCatalogueWording(): void
    {
        $container = $this->kernel();
        $installer = TestKernelFactory::administratorContext($container);
        $principal = $installer->principal();
        self::assertInstanceOf(AuthenticatedPrincipal::class, $principal);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $definition = NeutralBusinessFixture::install(
            $container,
            $installer,
            NeutralBusinessFixture::document('wording' . $suffix, Uuid::uuid7()->toString()),
        )->handle;
        $records = $this->service($container, BusinessRecordService::class);
        $browser = $this->service($container, GeneratedBusinessBrowserController::class);
        $record = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $installer,
            $definition,
            NeutralBusinessFixture::recordValues('Wording ' . $suffix),
            NeutralBusinessFixture::idempotencyKey('wording-create-' . $suffix),
            recordId: $record,
        ));
        $context = $this->context($principal, $suffix);

        $rejected = $browser->dispatch(
            $context,
            BusinessSurface::Administrator,
            '/administrator/business',
            'POST',
            $definition,
            $record,
            [],
            [
                'operation' => 'update',
                'operation_id' => 'wording-invalid-' . $suffix,
                'expected_version' => '1',
                'values' => ['name' => 'x'],
            ],
        );

        self::assertSame(422, $rejected->status);
        self::assertSame(
            'The business record failed validation. Review the marked fields.',
            $rejected->data['error_summary'] ?? null,
        );

        $records->update(new UpdateRecordCommand(
            $installer,
            $definition,
            $record,
            1,
            ['evolution_code' => 'MOVED'],
            NeutralBusinessFixture::idempotencyKey('wording-race-' . $suffix),
        ));

        $conflicted = $browser->dispatch(
            $context,
            BusinessSurface::Administrator,
            '/administrator/business',
            'POST',
            $definition,
            $record,
            [],
            [
                'operation' => 'update',
                'operation_id' => 'wording-conflict-' . $suffix,
                'expected_version' => '1',
                'values' => ['name' => 'Wording kept ' . $suffix],
            ],
        );

        self::assertSame(409, $conflicted->status);
        $conflict = $conflicted->data['version_conflict'] ?? null;
        self::assertIsArray($conflict);
        self::assertSame(
            'Another save changed this record after you opened it, so nothing you submitted was written '
                . 'and the newer record is untouched.',
            $conflict['summary'] ?? null,
        );
    }

    /**
     * Build, once, the kernel these cases resolve their services from.
     *
     * @return  Container  The functional test kernel.
     *
     * @since   2.0.0
     */
    private function kernel(): Container
    {
        return self::$kernel ??= TestKernelFactory::create(Environment::fromGlobals());
    }

    /**
     * Build the administrator execution context the browser surface authenticates with.
     *
     * @param   AuthenticatedPrincipal  $principal  Shared administrator principal.
     * @param   string                  $suffix     Run-unique correlation suffix.
     *
     * @return  ExecutionContext  Context bound to the administrator surface.
     *
     * @since   2.0.0
     */
    private function context(AuthenticatedPrincipal $principal, string $suffix): ExecutionContext
    {
        return $principal->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'wording-' . $suffix,
            surface: AuthenticatedSurface::Administrator,
        );
    }

    /**
     * Resolve one container service, failing the test rather than the type checker.
     *
     * @template  T of object
     *
     * @param   Container         $container  Kernel to resolve from.
     * @param   class-string<T>   $class      Service identifier.
     *
     * @return  T  The resolved service.
     *
     * @since   2.0.0
     */
    private function service(Container $container, string $class): object
    {
        $service = $container->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
