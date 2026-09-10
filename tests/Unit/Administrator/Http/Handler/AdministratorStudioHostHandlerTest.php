<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Administrator\Http\Handler;

use DateTimeImmutable;
use Kumwe\App\Administrator\Http\Handler\AdministratorStudioHostHandler;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Application\Persistence\TransactionState;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Studio\Application\Host\StudioArtifactAdmission;
use Kumwe\App\Studio\Application\Host\StudioArtifactHostPort;
use Kumwe\App\Studio\Application\Host\StudioArtifactPublicationGuard;
use Kumwe\App\Studio\Application\Host\StudioArtifactRepository;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Host\StudioHostSessionRepository;
use Kumwe\App\Studio\Application\Host\StudioLocalizationHostPort;
use Kumwe\App\Studio\Application\Host\StudioMutationReplayRepository;
use Kumwe\App\Studio\Application\Host\StudioProducerHost;
use Kumwe\App\Studio\Application\Host\StudioProducerHostFactory;
use Kumwe\App\Studio\Application\Host\StudioProducerRequestAuthority;
use Kumwe\App\Studio\Application\Host\StudioRecoveryHostPort;
use Kumwe\App\Studio\Application\Host\StudioRecoveryRepository;
use Kumwe\App\Studio\Application\Host\StudioResourceContextKeyFactory;
use Kumwe\App\Studio\Application\Host\StudioResourceHostPort;
use Kumwe\App\Studio\Application\Host\StudioTelemetryHostPort;
use Kumwe\App\Studio\Application\Media\StudioMediaHostPort;
use Kumwe\App\Studio\Application\Media\StudioMediaOperations;
use Kumwe\App\Studio\Application\Host\StudioModelHostPort;
use Kumwe\App\Studio\Application\Preview\StudioPreviewHostPort;
use Kumwe\App\Studio\Domain\Artifact\StoredStudioArtifact;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\App\Studio\Infrastructure\Host\SodiumStudioMutationOutcomeCodec;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Producer\Canonical\CanonicalJson;
use Kumwe\Producer\Wire\RequestEnvelope;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;

#[CoversClass(AdministratorStudioHostHandler::class)]
#[CoversClass(StudioProducerHostFactory::class)]
#[CoversClass(StudioProducerHost::class)]
#[CoversClass(StudioProducerRequestAuthority::class)]
/**
 * Proves the normative HTTP binding dispatches raw bytes through one complete request-scoped host.
 *
 * @since  2.0.0
 */
final class AdministratorStudioHostHandlerTest extends TestCase
{
    /**
     * Prove a canonical permission refresh crosses the whole factory-composed host verbatim.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACanonicalOperationCrossesTheCompleteRequestScopedHost(): void
    {
        [$factory, $context, $body] = $this->host('studio.operation/permission.refresh');
        $handler = new AdministratorStudioHostHandler($factory);

        $response = $handler->handle(self::request($context, 'permission', 'refresh', $body));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $decoded = json_decode((string) $response->getBody());
        self::assertContains('studio.permission/save', $decoded->value->permissions);
    }

    /**
     * Prove an unknown route refuses with a canonical host error and its mapped HTTP status.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnknownRouteRefusesWithACanonicalHostError(): void
    {
        [$factory, $context, $body] = $this->host('studio.operation/permission.refresh');
        $handler = new AdministratorStudioHostHandler($factory);

        $response = $handler->handle(self::request($context, 'invalid', 'invalid', $body));

        self::assertGreaterThanOrEqual(400, $response->getStatusCode());
        self::assertJson((string) $response->getBody());
        self::assertNotSame('', (string) $response->getBody());
    }

    /**
     * Prove a request whose route attributes are absent falls to the refused invalid route.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMissingRouteAttributeFallsToTheRefusedInvalidRoute(): void
    {
        [$factory, $context, $body] = $this->host('studio.operation/permission.refresh');
        $handler = new AdministratorStudioHostHandler($factory);
        $request = (new ServerRequest(
            [],
            [],
            'https://kumwe.test/administrator/studio/host',
            'POST',
            (new StreamFactory())->createStream($body),
        ))->withAttribute(ExecutionContextAttribute::NAME, $context);

        $response = $handler->handle($request);

        self::assertGreaterThanOrEqual(400, $response->getStatusCode());
        self::assertJson((string) $response->getBody());
    }

    /**
     * Prove the handler refuses composition under a non-positive body ceiling.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testANonPositiveBodyCeilingIsRejectedAtComposition(): void
    {
        $factory = (new \ReflectionClass(StudioProducerHostFactory::class))->newInstanceWithoutConstructor();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be positive');

        new AdministratorStudioHostHandler($factory, 0);
    }

    /**
     * Compose one real factory over deterministic in-memory authority and port dependencies.
     *
     * @param   string  $operationId  Canonical operation the returned body envelope names.
     *
     * @return  array{StudioProducerHostFactory, ExecutionContext, string}  Factory, context and body.
     *
     * @since   2.0.0
     */
    private function host(string $operationId): array
    {
        $repository = new class implements StudioHostSessionRepository {
            /**
             * Retained session.
             *
             * @var    StudioHostSession|null
             * @since  2.0.0
             */
            private ?StudioHostSession $session = null;

            /**
             * {@inheritDoc}
             *
             * @param   StudioHostSession  $session  Opened session to retain.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function add(StudioHostSession $session): void
            {
                $this->session = $session;
            }

            /**
             * {@inheritDoc}
             *
             * @param   string  $resourceContextKey  Requested resource context key.
             *
             * @return  StudioHostSession|null  The retained session when its key matches exactly.
             *
             * @since   2.0.0
             */
            public function find(string $resourceContextKey): ?StudioHostSession
            {
                return $this->session?->resourceContextKey === $resourceContextKey ? $this->session : null;
            }
        };
        $keys = new class implements StudioResourceContextKeyFactory {
            /**
             * {@inheritDoc}
             *
             * @return  string  One fixed deterministic resource context key.
             *
             * @since   2.0.0
             */
            public function create(): string
            {
                return 'contexts/http-host-test';
            }
        };
        $sessions = new StudioHostSessionAuthority(AuthorizationContext::gateway(), $repository, $keys);
        $context = AuthenticatedPrincipal::issueFromStrings(
            AuthorizationContext::provenance(),
            AuthorizationContext::SUBJECT,
            ['content.publish', 'content.read', 'content.unpublish', 'content.update', 'studio.mode.content'],
        )->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'studio-http-host-test',
            surface: AuthenticatedSurface::Administrator,
            sessionId: 'administrator-http-host-test',
        );
        $snapshot = $sessions->open(
            $context,
            StudioSessionMode::Content,
            StudioResourceKind::Content,
            'content-http-host-test',
        );
        $clock = new class implements ClockInterface {
            /**
             * {@inheritDoc}
             *
             * @return  DateTimeImmutable  The fixed instant this deterministic clock always reports.
             *
             * @since   2.0.0
             */
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-08-29T12:00:00+00:00');
            }
        };
        $transactions = new class implements TransactionManager {
            /**
             * {@inheritDoc}
             *
             * @param   callable  $operation  Work to run inside the transaction.
             *
             * @return  mixed  The operation's own result.
             *
             * @since   2.0.0
             */
            public function transactional(callable $operation): mixed
            {
                return $operation();
            }

            /**
             * {@inheritDoc}
             *
             * @param   callable  $operation  Work to run after commit.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function afterCommit(callable $operation): void
            {
                $operation();
            }

            /**
             * {@inheritDoc}
             *
             * @param   callable  $operation  Work to run after rollback.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function afterRollback(callable $operation): void
            {
                unset($operation);
            }
        };
        $artifact = new StoredStudioArtifact(
            'default',
            'content-http-host-test',
            '1.0.0',
            'entry',
            'entry-r1',
            'draft',
            CanonicalJson::stringify((object) [
                'id' => 'content-http-host-test',
                'kind' => 'entry',
                'revision' => 'entry-r1',
                'status' => 'draft',
            ]),
            '[]',
        );
        $factory = new StudioProducerHostFactory(
            $sessions,
            $transactions,
            self::createStub(TransactionState::class),
            self::createStub(StudioMutationReplayRepository::class),
            new SodiumStudioMutationOutcomeCodec(str_repeat('k', 32)),
            self::createStub(AuditRecorder::class),
            $clock,
            self::createStub(StudioMediaOperations::class),
            new StudioArtifactHostPort(
                new class ($artifact) implements StudioArtifactRepository {
                    /**
                     * Retain the one immutable artifact head.
                     *
                     * @param   StoredStudioArtifact  $artifact  Sole stored artifact head served.
                     *
                     * @since   2.0.0
                     */
                    public function __construct(private StoredStudioArtifact $artifact)
                    {
                    }

                    /**
                     * {@inheritDoc}
                     *
                     * @param   string  $siteIdentifier  Site scope the read is bound to.
                     * @param   string  $id              Requested artifact identifier.
                     * @param   string  $version         Requested artifact version.
                     *
                     * @return  ?StoredStudioArtifact  The retained head on an exact match, null otherwise.
                     *
                     * @since   2.0.0
                     */
                    public function current(
                        string $siteIdentifier,
                        string $id,
                        string $version,
                    ): ?StoredStudioArtifact {
                        return $id === $this->artifact->id && $version === $this->artifact->version
                            ? $this->artifact
                            : null;
                    }

                    /**
                     * {@inheritDoc}
                     *
                     * @param   string  $siteIdentifier  Site scope the read is bound to.
                     * @param   string  $id              Requested artifact identifier.
                     * @param   string  $version         Requested artifact version.
                     * @param   string  $revision        Requested exact revision authority.
                     *
                     * @return  ?StoredStudioArtifact  The retained head when its revision matches too.
                     *
                     * @since   2.0.0
                     */
                    public function revision(
                        string $siteIdentifier,
                        string $id,
                        string $version,
                        string $revision,
                    ): ?StoredStudioArtifact {
                        return $revision === $this->artifact->revision
                            ? $this->current($siteIdentifier, $id, $version)
                            : null;
                    }

                    /**
                     * {@inheritDoc}
                     *
                     * @param   StoredStudioArtifact  $artifact         Candidate artifact head.
                     * @param   ?string               $expectedCurrent  Revision believed current.
                     *
                     * @return  bool  Always false: this read-only double never accepts writes.
                     *
                     * @since   2.0.0
                     */
                    public function store(StoredStudioArtifact $artifact, ?string $expectedCurrent): bool
                    {
                        unset($artifact, $expectedCurrent);

                        return false;
                    }
                },
                new StudioArtifactAdmission(
                    \Kumwe\Producer\Schema\StudioDocumentSchemaRegistry::fromVendoredCorpus(),
                ),
                new class implements StudioArtifactPublicationGuard {
                    /**
                     * {@inheritDoc}
                     *
                     * @param   SiteContext  $site       Site the publication would target.
                     * @param   \stdClass    $blueprint  Decoded artifact blueprint under review.
                     *
                     * @return  void
                     *
                     * @since   2.0.0
                     */
                    public function assertPublishable(SiteContext $site, \stdClass $blueprint): void
                    {
                        unset($site, $blueprint);
                    }
                },
            ),
            new StudioLocalizationHostPort(
                self::createStub(\Kumwe\App\Localization\Application\MessageCatalogueRepository::class),
                self::createStub(\Kumwe\App\Localization\Application\MessageOverrideRepository::class),
                new \Kumwe\App\Localization\Application\ActiveLocale(
                    new \Kumwe\App\Localization\Application\SupportedLocales(),
                ),
                new \Kumwe\App\Localization\Application\SupportedLocales(),
            ),
            new StudioMediaHostPort(self::createStub(StudioMediaOperations::class)),
            new StudioModelHostPort(
                (new \ReflectionClass(\Kumwe\App\Studio\Application\Projection\StudioContentProjectionService::class))
                    ->newInstanceWithoutConstructor(),
            ),
            new StudioPreviewHostPort(
                self::createStub(\Kumwe\App\Studio\Application\Preview\StudioPreviewDraftSource::class),
                self::createStub(\Kumwe\App\Studio\Application\Preview\StudioPreviewBindingSource::class),
                self::createStub(\Kumwe\App\Studio\Application\Preview\StudioPreviewRenderer::class),
                self::createStub(\Kumwe\App\Studio\Application\Preview\StudioPreviewGrantRepository::class),
                (new \ReflectionClass(\Kumwe\App\Studio\Application\Preview\StudioPreviewTransportGuard::class))
                    ->newInstanceWithoutConstructor(),
                self::createStub(\Kumwe\App\Studio\Application\Preview\StudioPreviewActivityRecorder::class),
                $clock,
            ),
            new StudioRecoveryHostPort(self::createStub(StudioRecoveryRepository::class), $clock),
            new StudioResourceHostPort([]),
            new StudioTelemetryHostPort(new NullLogger()),
        );
        $body = CanonicalJson::stringify((object) [
            'arguments' => null,
            'context' => (object) [
                'operationId' => $operationId,
                'protocolVersion' => RequestEnvelope::WIRE_PROTOCOL_VERSION,
                'requestId' => 'requests/http-host-test',
                'resourceContextKey' => $snapshot->session->resourceContextKey,
                'sessionGeneration' => $snapshot->generation,
            ],
        ]);

        return [$factory, $context, $body];
    }

    /**
     * Build one authenticated administrator Studio host request.
     *
     * @param   ExecutionContext  $context    Host-issued administrator execution context.
     * @param   string            $port       Routed Producer port name.
     * @param   string            $operation  Routed Producer operation name.
     * @param   string            $body       Canonical request envelope bytes.
     *
     * @return  ServerRequest  Complete attribute-carrying HTTP request.
     *
     * @since   2.0.0
     */
    private static function request(
        ExecutionContext $context,
        string $port,
        string $operation,
        string $body,
    ): ServerRequest {
        return (new ServerRequest(
            [],
            [],
            'https://kumwe.test/administrator/studio/host/' . $port . '/' . $operation,
            'POST',
            (new StreamFactory())->createStream($body),
        ))
            ->withAttribute('port', $port)
            ->withAttribute('operation', $operation)
            ->withAttribute(ExecutionContextAttribute::NAME, $context);
    }
}
