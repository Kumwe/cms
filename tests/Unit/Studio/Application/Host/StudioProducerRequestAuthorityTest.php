<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Host;

use LogicException;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Host\StudioHostSessionRepository;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Host\StudioProducerRequestAuthority;
use Kumwe\App\Studio\Application\Host\StudioResourceContextKeyFactory;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewTransport;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Producer\Canonical\CanonicalJson;
use Kumwe\Producer\Wire\Dispatcher;
use Kumwe\Producer\Wire\HostResult;
use Kumwe\Producer\Wire\MutationOutcome;
use Kumwe\Producer\Wire\Operation;
use Kumwe\Producer\Wire\OperationRegistry;
use Kumwe\Producer\Wire\Port\ArtifactPortInterface;
use Kumwe\Producer\Wire\Port\AuthoringPortInterface;
use Kumwe\Producer\Wire\Port\AuthorizationInterface;
use Kumwe\Producer\Wire\Port\HostAdapterInterface;
use Kumwe\Producer\Wire\Port\LocalizationPortInterface;
use Kumwe\Producer\Wire\Port\MediaPortInterface;
use Kumwe\Producer\Wire\Port\ModelPortInterface;
use Kumwe\Producer\Wire\Port\MutationBoundaryInterface;
use Kumwe\Producer\Wire\Port\PermissionPortInterface;
use Kumwe\Producer\Wire\Port\PreviewPortInterface;
use Kumwe\Producer\Wire\Port\RecoveryPortInterface;
use Kumwe\Producer\Wire\Port\ResourcePortInterface;
use Kumwe\Producer\Wire\Port\TelemetryPortInterface;
use Kumwe\Producer\Wire\RequestContext;
use Kumwe\Producer\Wire\RequestEnvelope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Proves the App resolves fresh trusted authority before any Producer port or replay boundary.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioProducerRequestAuthority::class)]
final class StudioProducerRequestAuthorityTest extends TestCase
{
    /**
     * The closed Producer operation and the envelope operation must be byte-identical.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testOperationCapabilityMustEqualTheEnvelopeOperation(): void
    {
        [$sessions, $context, $snapshot] = $this->runtime(['studio.mode.content']);
        $authority = new StudioProducerRequestAuthority($context, $sessions);
        $request = self::request(
            'studio.operation/permission.refresh',
            $snapshot,
            new stdClass(),
        );

        self::assertNull($authority->authorize(
            OperationRegistry::byCapability('studio.operation/permission.refresh'),
            $request,
        ));
        self::assertSame($snapshot->session, $authority->snapshot()->session);

        $refusal = $authority->authorize(
            OperationRegistry::byCapability('studio.operation/artifact.load'),
            $request,
        );
        self::assertNotNull($refusal);
        self::assertSame('invalid-request', $refusal->category());
        self::assertSame('studio.host/operation-mismatch', $refusal->diagnostics()[0]->code());
    }

    /**
     * A stale generation refuses the call and clears the previous successful snapshot.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testStaleGenerationRefusesAndNoSnapshotSurvivesAcrossCalls(): void
    {
        [$sessions, $context, $snapshot] = $this->runtime(['studio.mode.content']);
        $authority = new StudioProducerRequestAuthority($context, $sessions);
        $operation = OperationRegistry::byCapability('studio.operation/permission.refresh');

        self::assertNull($authority->authorize(
            $operation,
            self::request($operation->capability, $snapshot, new stdClass()),
        ));
        $refusal = $authority->authorize(
            $operation,
            self::request($operation->capability, $snapshot, new stdClass(), 'generation-stale'),
        );

        self::assertNotNull($refusal);
        self::assertSame('invalid-request', $refusal->category());
        self::assertSame('studio.host/stale-session-generation', $refusal->diagnostics()[0]->code());
        $this->expectException(LogicException::class);
        $authority->snapshot();
    }

    /**
     * Revoked live mode authority is checked before Producer may consult keyed replay state.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testRevokedAuthorityStopsAKeyedMutationBeforeReplay(): void
    {
        [$sessions, , $snapshot] = $this->runtime(['studio.mode.content']);
        $revoked = self::context([]);
        $authority = new StudioProducerRequestAuthority($revoked, $sessions);
        $mutations = new class implements MutationBoundaryInterface {
            /**
             * Number of replay-boundary calls observed.
             *
             * @var    int
             * @since  2.0.0
             */
            public int $calls = 0;

            /**
             * Record the boundary call and fail, because revoked authority must never reach replay.
             *
             * @param   Operation        $operation     Closed Producer registry operation.
             * @param   RequestEnvelope  $request       Validated Producer request envelope.
             * @param   ?string          $scopeKey      Producer replay scope digest, or null when unkeyed.
             * @param   ?string          $intentDigest  Producer request intent digest, or null when unkeyed.
             * @param   callable         $mutation      Mutation that must never execute.
             *
             * @return  MutationOutcome  Never returned; the boundary always throws in this test.
             *
             * @since   2.0.0
             */
            public function execute(
                Operation $operation,
                RequestEnvelope $request,
                ?string $scopeKey,
                ?string $intentDigest,
                callable $mutation,
            ): MutationOutcome {
                unset($operation, $request, $scopeKey, $intentDigest, $mutation);
                $this->calls++;
                throw new LogicException('Revoked authority reached the mutation boundary.');
            }
        };
        $artifact = new class implements ArtifactPortInterface {
            /**
             * Whether a port operation was reached.
             *
             * @var    bool
             * @since  2.0.0
             */
            public bool $called = false;

            /**
             * Refuse the dependencies operation; revoked authority must never reach the artifact port.
             *
             * @param   mixed           $arguments  Operation argument value.
             * @param   RequestContext  $context    Validated Producer request context.
             *
             * @return  HostResult  Never returned; the port always throws in this test.
             *
             * @since   2.0.0
             */
            public function dependencies(mixed $arguments, RequestContext $context): HostResult
            {
                return $this->unexpected($arguments, $context);
            }

            /**
             * Refuse the load operation; revoked authority must never reach the artifact port.
             *
             * @param   mixed           $arguments  Operation argument value.
             * @param   RequestContext  $context    Validated Producer request context.
             *
             * @return  HostResult  Never returned; the port always throws in this test.
             *
             * @since   2.0.0
             */
            public function load(mixed $arguments, RequestContext $context): HostResult
            {
                return $this->unexpected($arguments, $context);
            }

            /**
             * Refuse the publish operation; revoked authority must never reach the artifact port.
             *
             * @param   mixed           $arguments  Operation argument value.
             * @param   RequestContext  $context    Validated Producer request context.
             *
             * @return  HostResult  Never returned; the port always throws in this test.
             *
             * @since   2.0.0
             */
            public function publish(mixed $arguments, RequestContext $context): HostResult
            {
                return $this->unexpected($arguments, $context);
            }

            /**
             * Refuse the save operation; revoked authority must never reach the artifact port.
             *
             * @param   mixed           $arguments  Operation argument value.
             * @param   RequestContext  $context    Validated Producer request context.
             *
             * @return  HostResult  Never returned; the port always throws in this test.
             *
             * @since   2.0.0
             */
            public function save(mixed $arguments, RequestContext $context): HostResult
            {
                return $this->unexpected($arguments, $context);
            }

            /**
             * Refuse the unpublish operation; revoked authority must never reach the artifact port.
             *
             * @param   mixed           $arguments  Operation argument value.
             * @param   RequestContext  $context    Validated Producer request context.
             *
             * @return  HostResult  Never returned; the port always throws in this test.
             *
             * @since   2.0.0
             */
            public function unpublish(mixed $arguments, RequestContext $context): HostResult
            {
                return $this->unexpected($arguments, $context);
            }

            /**
             * Refuse any unexpected port call.
             *
             * @param   mixed           $arguments  Operation argument value.
             * @param   RequestContext  $context    Validated Producer request context.
             *
             * @return  never  Always throws after marking the port as reached.
             *
             * @since   2.0.0
             */
            private function unexpected(mixed $arguments, RequestContext $context): never
            {
                unset($arguments, $context);
                $this->called = true;
                throw new LogicException('Revoked authority reached the artifact port.');
            }
        };
        $host = new class ($authority, $mutations, $artifact) implements HostAdapterInterface {
            /**
             * Bind only the authorities and required port under test.
             *
             * @param   AuthorizationInterface     $authority  Revoked request authority under test.
             * @param   MutationBoundaryInterface  $mutations  Observable failing replay boundary.
             * @param   ArtifactPortInterface      $artifact   Observable failing artifact port.
             *
             * @since   2.0.0
             */
            public function __construct(
                private AuthorizationInterface $authority,
                private MutationBoundaryInterface $mutations,
                private ArtifactPortInterface $artifact,
            ) {
            }

            /**
             * Expose the revoked request authority under test.
             *
             * @return  AuthorizationInterface  The bound request authority.
             *
             * @since   2.0.0
             */
            public function authorization(): AuthorizationInterface
            {
                return $this->authority;
            }

            /**
             * Expose the observable mutation boundary.
             *
             * @return  MutationBoundaryInterface  The bound replay boundary.
             *
             * @since   2.0.0
             */
            public function mutations(): MutationBoundaryInterface
            {
                return $this->mutations;
            }

            /**
             * Expose the observable artifact port.
             *
             * @return  ArtifactPortInterface  The bound artifact port.
             *
             * @since   2.0.0
             */
            public function artifact(): ArtifactPortInterface
            {
                return $this->artifact;
            }

            /**
             * Declare the optional contextual authoring port absent.
             *
             * @return  ?AuthoringPortInterface  Always null; the port is not under test.
             *
             * @since   2.0.0
             */
            public function authoring(): ?AuthoringPortInterface
            {
                return null;
            }

            /**
             * Declare the optional localization port absent.
             *
             * @return  ?LocalizationPortInterface  Always null; the port is not under test.
             *
             * @since   2.0.0
             */
            public function localization(): ?LocalizationPortInterface
            {
                return null;
            }

            /**
             * Declare the optional media port absent.
             *
             * @return  ?MediaPortInterface  Always null; the port is not under test.
             *
             * @since   2.0.0
             */
            public function media(): ?MediaPortInterface
            {
                return null;
            }

            /**
             * Declare the optional model port absent.
             *
             * @return  ?ModelPortInterface  Always null; the port is not under test.
             *
             * @since   2.0.0
             */
            public function model(): ?ModelPortInterface
            {
                return null;
            }

            /**
             * Declare the optional permission port absent.
             *
             * @return  ?PermissionPortInterface  Always null; the port is not under test.
             *
             * @since   2.0.0
             */
            public function permission(): ?PermissionPortInterface
            {
                return null;
            }

            /**
             * Declare the optional preview port absent.
             *
             * @return  ?PreviewPortInterface  Always null; the port is not under test.
             *
             * @since   2.0.0
             */
            public function preview(): ?PreviewPortInterface
            {
                return null;
            }

            /**
             * Declare the optional recovery port absent.
             *
             * @return  ?RecoveryPortInterface  Always null; the port is not under test.
             *
             * @since   2.0.0
             */
            public function recovery(): ?RecoveryPortInterface
            {
                return null;
            }

            /**
             * Declare the optional resource port absent.
             *
             * @return  ?ResourcePortInterface  Always null; the port is not under test.
             *
             * @since   2.0.0
             */
            public function resource(): ?ResourcePortInterface
            {
                return null;
            }

            /**
             * Declare the optional telemetry port absent.
             *
             * @return  ?TelemetryPortInterface  Always null; the port is not under test.
             *
             * @since   2.0.0
             */
            public function telemetry(): ?TelemetryPortInterface
            {
                return null;
            }
        };
        $request = self::requestDocument(
            'studio.operation/artifact.save',
            $snapshot,
            (object) ['document' => new stdClass()],
            expectedRevision: 'revision-1',
            idempotencyKey: 'idempotency/revoked-1',
        );

        $response = (new Dispatcher($host))->dispatch(
            'artifact/save',
            CanonicalJson::stringify($request),
        );

        self::assertSame('invalid-request', $response->refusalCategory);
        self::assertSame(0, $mutations->calls);
        self::assertFalse($artifact->called);
        $document = json_decode($response->body, false, 64, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $document);
        self::assertSame('studio.host/stale-session-generation', $document->diagnostics[0]->code);
    }

    /**
     * A resource-context key the authority never issued is refused without disclosure.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testAnUnknownResourceContextKeyIsRefusedWithoutDisclosure(): void
    {
        [$sessions, $context, $snapshot] = $this->runtime(['studio.mode.content']);
        $authority = new StudioProducerRequestAuthority($context, $sessions);
        $operation = OperationRegistry::byCapability('studio.operation/permission.refresh');
        $document = self::requestDocument($operation->capability, $snapshot, new stdClass());
        $document->context->resourceContextKey = 'contexts/never-issued';

        $refusal = $authority->authorize(
            $operation,
            RequestEnvelope::parse(CanonicalJson::stringify($document)),
        );

        self::assertNotNull($refusal);
        self::assertSame('forbidden', $refusal->category());
        self::assertSame('studio.host/context-refused', $refusal->diagnostics()[0]->code());
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must authorize');
        $authority->snapshot();
    }

    /**
     * Publish and unpublish are refused while the live target-specific transitions are withheld.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testLifecycleOperationsAreRefusedWithoutLiveTransitionAuthority(): void
    {
        [$sessions, $context, $snapshot] = $this->runtime(['studio.mode.content']);
        $authority = new StudioProducerRequestAuthority($context, $sessions);

        foreach (['studio.operation/artifact.publish', 'studio.operation/artifact.unpublish'] as $capability) {
            $refusal = $authority->authorize(
                OperationRegistry::byCapability($capability),
                self::request($capability, $snapshot, new stdClass()),
            );

            self::assertNotNull($refusal);
            self::assertSame('forbidden', $refusal->category());
            self::assertSame('studio.host/session-refused', $refusal->diagnostics()[0]->code());
        }
    }

    /**
     * Save-family and media operations answer only from the exact live App permission policy.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testSaveAndMediaPolicyArmsAnswerFromLiveGrants(): void
    {
        [$sessions, $context, $snapshot] = $this->runtime(['studio.mode.content']);
        $authority = new StudioProducerRequestAuthority($context, $sessions);

        self::assertNull($authority->authorize(
            OperationRegistry::byCapability('studio.operation/recovery.store'),
            self::request('studio.operation/recovery.store', $snapshot, new stdClass()),
        ));
        $refusal = $authority->authorize(
            OperationRegistry::byCapability('studio.operation/media.import-external'),
            self::request('studio.operation/media.import-external', $snapshot, new stdClass()),
        );
        self::assertNotNull($refusal);
        self::assertSame('forbidden', $refusal->category());
        self::assertSame('studio.host/session-refused', $refusal->diagnostics()[0]->code());

        [$granting, $grantingContext, $grantingSnapshot] = $this->runtime(['content.update', 'studio.mode.content']);
        $grantedAuthority = new StudioProducerRequestAuthority($grantingContext, $granting);

        self::assertNull($grantedAuthority->authorize(
            OperationRegistry::byCapability('studio.operation/media.authorize-upload'),
            self::request('studio.operation/media.authorize-upload', $grantingSnapshot, new stdClass()),
        ));
    }

    /**
     * The accessors expose exactly the trusted context and preview evidence bound at construction.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testAccessorsExposeTheBoundRequestEvidence(): void
    {
        [$sessions, $context] = $this->runtime(['studio.mode.content']);
        $transport = new StudioPreviewTransport(
            'https://studio.example.test',
            'channels/preview-1',
            'sources/browser-1',
            0,
        );
        $bound = new StudioProducerRequestAuthority($context, $sessions, $transport);

        self::assertSame($context, $bound->context());
        self::assertSame($transport, $bound->previewTransport());
        self::assertNull((new StudioProducerRequestAuthority($context, $sessions))->previewTransport());
    }

    /**
     * Permission explanation answers only from the live snapshot proved by authorization.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testPermitsAnswersFromTheLiveAuthorizedSnapshot(): void
    {
        [$sessions, $context, $snapshot] = $this->runtime(['studio.mode.content']);
        $authority = new StudioProducerRequestAuthority($context, $sessions);

        self::assertNull($authority->authorize(
            OperationRegistry::byCapability('studio.operation/permission.refresh'),
            self::request('studio.operation/permission.refresh', $snapshot, new stdClass()),
        ));
        self::assertTrue($authority->permits('studio.permission/save'));
        self::assertFalse($authority->permits('studio.permission/publish'));
        self::assertFalse($authority->permits('studio.permission/not-in-vocabulary'));
    }

    /**
     * Assemble production session authority around deterministic in-memory stores.
     *
     * @param   list<string>  $capabilities  Initial trusted capabilities.
     *
     * @return  array{StudioHostSessionAuthority, ExecutionContext, StudioHostSessionSnapshot}
     *
     * @since  2.0.0
     */
    private function runtime(array $capabilities): array
    {
        $repository = new class implements StudioHostSessionRepository {
            /**
             * Stored bindings keyed by opaque resource-context key.
             *
             * @var    array<string, StudioHostSession>
             * @since  2.0.0
             */
            private array $sessions = [];

            /**
             * Persist an opened binding under its resource-context key.
             *
             * @param   StudioHostSession  $session  Fully verified immutable binding.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function add(StudioHostSession $session): void
            {
                $this->sessions[$session->resourceContextKey] = $session;
            }

            /**
             * Resolve an opaque context key against the in-memory store.
             *
             * @param   string  $resourceContextKey  Canonical host-envelope key.
             *
             * @return  ?StudioHostSession  Stored binding, or null when unknown.
             *
             * @since   2.0.0
             */
            public function find(string $resourceContextKey): ?StudioHostSession
            {
                return $this->sessions[$resourceContextKey] ?? null;
            }
        };
        $keys = new class implements StudioResourceContextKeyFactory {
            /**
             * Mint the fixed deterministic context key for this test.
             *
             * @return  string  Stable opaque resource-context identifier.
             *
             * @since   2.0.0
             */
            public function create(): string
            {
                return 'contexts/producer-authority-test';
            }
        };
        $sessions = new StudioHostSessionAuthority(
            AuthorizationContext::gateway(),
            $repository,
            $keys,
        );
        $context = self::context($capabilities);
        $snapshot = $sessions->open(
            $context,
            StudioSessionMode::Content,
            StudioResourceKind::Content,
            'content-producer-authority',
        );

        return [$sessions, $context, $snapshot];
    }

    /**
     * Parse one exact Producer envelope for a trusted open session.
     *
     * @param   string                     $operationId      Closed Producer operation capability.
     * @param   StudioHostSessionSnapshot  $snapshot         Open trusted session.
     * @param   mixed                      $arguments        Operation argument value.
     * @param   string|null                $generation       Generation override under test.
     * @param   string|null                $expectedRevision Optional concurrency coordinate.
     * @param   string|null                $idempotencyKey   Optional replay coordinate.
     *
     * @return  RequestEnvelope  Parsed canonical Producer request.
     *
     * @since  2.0.0
     */
    private static function request(
        string $operationId,
        StudioHostSessionSnapshot $snapshot,
        mixed $arguments,
        ?string $generation = null,
        ?string $expectedRevision = null,
        ?string $idempotencyKey = null,
    ): RequestEnvelope {
        return RequestEnvelope::parse(CanonicalJson::stringify(self::requestDocument(
            $operationId,
            $snapshot,
            $arguments,
            $generation,
            $expectedRevision,
            $idempotencyKey,
        )));
    }

    /**
     * Build one exact Producer envelope document for dispatch or parsing.
     *
     * @param   string                     $operationId      Closed Producer operation capability.
     * @param   StudioHostSessionSnapshot  $snapshot         Open trusted session.
     * @param   mixed                      $arguments        Operation argument value.
     * @param   string|null                $generation       Generation override under test.
     * @param   string|null                $expectedRevision Optional concurrency coordinate.
     * @param   string|null                $idempotencyKey   Optional replay coordinate.
     *
     * @return  stdClass  Exact canonical envelope document.
     *
     * @since  2.0.0
     */
    private static function requestDocument(
        string $operationId,
        StudioHostSessionSnapshot $snapshot,
        mixed $arguments,
        ?string $generation = null,
        ?string $expectedRevision = null,
        ?string $idempotencyKey = null,
    ): stdClass {
        $context = (object) [
            'operationId' => $operationId,
            'protocolVersion' => RequestEnvelope::WIRE_PROTOCOL_VERSION,
            'requestId' => 'requests/producer-authority-test',
            'resourceContextKey' => $snapshot->session->resourceContextKey,
            'sessionGeneration' => $generation ?? $snapshot->generation,
        ];
        if ($expectedRevision !== null) {
            $context->expectedRevision = $expectedRevision;
        }
        if ($idempotencyKey !== null) {
            $context->idempotencyKey = $idempotencyKey;
        }

        return (object) [
            'arguments' => $arguments,
            'context' => $context,
        ];
    }

    /**
     * Mint a trusted administrator context with the selected live capabilities.
     *
     * @param   list<string>  $capabilities  Effective App grants.
     *
     * @return  ExecutionContext  Provenance-bound execution context.
     *
     * @since  2.0.0
     */
    private static function context(array $capabilities): ExecutionContext
    {
        return AuthenticatedPrincipal::issueFromStrings(
            AuthorizationContext::provenance(),
            AuthorizationContext::SUBJECT,
            $capabilities,
        )->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'studio-producer-authority-test',
            surface: AuthenticatedSurface::Administrator,
            sessionId: 'administrator-session-test',
        );
    }
}
