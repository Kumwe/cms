<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessSurface\Application;

use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\App\Application\Authorization\AuthorizationDecision;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\BusinessDefinition\Application\FieldTypeDefinitionResolver;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordNotFound;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordCursor;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessController;
use Kumwe\App\BusinessSurface\Application\BusinessSurface;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceCatalog;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceService;
use Kumwe\App\Extension\Runtime\RuntimeMaterializationState;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(BusinessSurfaceService::class)]
/**
 * Proves generated-business facade failures and presentation helpers remain safely bounded.
 *
 * @since  2.0.0
 */
final class BusinessSurfaceServiceTest extends TestCase
{
    /**
     * Proves record-addressed reads hide catalog denial while discovery keeps its collection semantics.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReadNormalizesUnavailableDefinitionWithoutChangingDiscovery(): void
    {
        $service = $this->service($this->emptyCatalog());

        foreach (BusinessSurface::cases() as $surface) {
            $context = $this->context($surface);
            self::assertSame([], $service->discover($context, $surface), $surface->value);

            try {
                $service->read($context, $surface, 'site.default.missing', 'missing-record');
                self::fail(
                    'An unavailable record-addressed definition must be indistinguishable from a missing record.',
                );
            } catch (BusinessRecordNotFound $exception) {
                self::assertSame('business_record.not_found', $exception->stableCode(), $surface->value);
            }
        }
    }

    /**
     * Proves selector labels are normalized to one line and a UTF-8-safe byte limit.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRelationshipChoiceLabelsAreSingleLineAndUtf8ByteBounded(): void
    {
        $choiceText = (new ReflectionClass(BusinessSurfaceService::class))->getMethod('choiceText');

        self::assertSame('Alpha Beta', $choiceText->invoke(null, " Alpha\n\tBeta ", 120));
        $bounded = $choiceText->invoke(null, str_repeat("\u{1F680}", 40), 120);
        self::assertIsString($bounded);
        self::assertLessThanOrEqual(120, strlen($bounded));
        self::assertSame(1, preg_match('//u', $bounded));
    }

    /**
     * Proves default projection narrowing keeps the opaque page-two cursor for final digest validation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCustomViewNarrowingCarriesCursorIntoTheFinalSignedProjection(): void
    {
        $document = NeutralBusinessFixture::document();
        $document['views'][] = [
            'handle' => 'summary',
            'label' => 'Summary',
            'kind' => 'list',
            'fields' => ['name', 'status'],
            'filters' => ['status'],
            'sorts' => ['name'],
            'administrator' => true,
            'portal' => false,
            'public' => false,
            'handler' => 'site.default.views.summary',
            'schema' => 'site.default.schemas.summary',
        ];
        $definition = EntityTypeDefinition::fromArray($document);
        $cursor = RecordCursor::fromString(str_repeat('a', 16) . '.' . str_repeat('b', 16));
        $query = new RecordQuerySpecification(after: $cursor);
        $service = (new ReflectionClass(BusinessSurfaceService::class))->newInstanceWithoutConstructor();
        $method = (new ReflectionClass(BusinessSurfaceService::class))->getMethod('customViewSpecification');
        $narrowed = $method->invoke($service, $query, $definition->toArray(), $definition, 'summary');

        self::assertInstanceOf(RecordQuerySpecification::class, $narrowed);
        self::assertSame($cursor, $narrowed->after);
        self::assertSame(['name', 'status'], $narrowed->projection->fields);
    }

    /**
     * Build a catalog that authorizes reads but contains no matching definition.
     *
     * @return  BusinessSurfaceCatalog  Executable empty catalog fixture.
     *
     * @since   2.0.0
     */
    private function emptyCatalog(): BusinessSurfaceCatalog
    {
        $definitions = $this->createStub(BusinessRecordDefinitionResolver::class);
        $definitions->method('activeInstalled')->willReturn([]);
        $authorization = $this->createStub(AuthorizationGateway::class);
        $authorization->method('decide')->willReturn(new AuthorizationDecision(true, 'test', 'allowed'));
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );

        return new BusinessSurfaceCatalog(
            $definitions,
            $this->createStub(BusinessRecordAccessController::class),
            $this->createStub(FieldTypeDefinitionResolver::class),
            $authorization,
            $transactions,
            RuntimeMaterializationState::unavailable('business-surface-service-test'),
        );
    }

    /**
     * Build a facade whose unreachable collaborators remain intentionally uninitialized.
     *
     * @param   BusinessSurfaceCatalog  $catalog  Exact catalog exercised by the read boundary.
     *
     * @return  BusinessSurfaceService  Reflection-backed focused fixture.
     *
     * @since   2.0.0
     */
    private function service(BusinessSurfaceCatalog $catalog): BusinessSurfaceService
    {
        $reflection = new ReflectionClass(BusinessSurfaceService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('catalog')->setValue($service, $catalog);

        return $service;
    }

    /**
     * Mint one provenance-matched context with generated read authority.
     *
     * @param   BusinessSurface  $surface  Generated adapter under test.
     *
     * @return  ExecutionContext  Authenticated caller context.
     *
     * @since   2.0.0
     */
    private function context(BusinessSurface $surface): ExecutionContext
    {
        $authenticated = match ($surface) {
            BusinessSurface::Administrator => AuthenticatedSurface::Administrator,
            BusinessSurface::Portal => AuthenticatedSurface::Portal,
            BusinessSurface::Api => AuthenticatedSurface::Api,
            BusinessSurface::Cli => AuthenticatedSurface::Cli,
            BusinessSurface::Mcp => AuthenticatedSurface::Mcp,
        };

        return AuthorizationContext::principal(['business.record.read'])->context(
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'business-surface-read-normalization-test',
            surface: $authenticated,
        );
    }
}
