<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessSurface;

use Kumwe\App\BusinessDefinition\Domain\PortalOperation;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable;
use Kumwe\App\BusinessSurface\Application\BusinessSurface;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceService;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Proves action dispatch cannot bypass exact generated-surface exposure metadata.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessSurfaceService::class)]
#[CoversClass(BusinessRecordService::class)]
final class GeneratedBusinessActionExposureIntegrationTest extends TestCase
{
    /**
     * Refuse a portal-hidden action even when its entity and action operation are portal-enabled.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPortalCannotAddressActionOmittedFromSurfaceMetadata(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $administrator = TestKernelFactory::administratorContext($container);
        $principal = $administrator->principal();
        self::assertInstanceOf(AuthenticatedPrincipal::class, $principal);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $document = NeutralBusinessFixture::document('hidden' . $suffix, Uuid::uuid7()->toString());
        $document['portal_exposure'] = true;
        $document['portal_operations'] = [PortalOperation::Action->value];
        $definition = NeutralBusinessFixture::install($container, $administrator, $document);
        $record = Uuid::uuid7()->toString();
        $records = $container->get(BusinessRecordService::class);
        $surfaces = $container->get(BusinessSurfaceService::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(BusinessSurfaceService::class, $surfaces);
        $records->create(new CreateRecordCommand(
            $administrator,
            $definition->handle,
            NeutralBusinessFixture::recordValues('Hidden portal action ' . $suffix),
            NeutralBusinessFixture::idempotencyKey('hidden-action-create-' . $suffix),
            recordId: $record,
        ));
        $portal = $principal->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'hidden-action-portal-' . $suffix,
            surface: AuthenticatedSurface::Portal,
        );

        try {
            $surfaces->action(
                $portal,
                BusinessSurface::Portal,
                $definition->handle,
                $record,
                1,
                'approve',
                'hidden-action-attempt-' . $suffix,
            );
            self::fail('An action omitted from portal metadata must not be directly executable.');
        } catch (BusinessRecordDefinitionUnavailable) {
            // The non-enumerating definition failure is the stable generated-surface ceiling.
        }

        $unchanged = $surfaces->read(
            $administrator,
            BusinessSurface::Administrator,
            $definition->handle,
            $record,
        );
        self::assertSame(1, $unchanged['record']['version']);
        self::assertSame('draft', $unchanged['record']['workflow_state']);
    }
}
