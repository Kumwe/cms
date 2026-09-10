<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use LogicException;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
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
use Kumwe\Producer\Canonical\CanonicalJson;
use Kumwe\Producer\Wire\OperationRegistry;
use Kumwe\Producer\Wire\RequestEnvelope;
use stdClass;

/**
 * Builds one real authorized Producer request scope for direct App port tests.
 *
 * @since  2.0.0
 */
final readonly class StudioProducerRequest
{
    /**
     * Retain the exact live authority and parsed envelope for one port call.
     *
     * @param  StudioProducerRequestAuthority  $authority  Successfully authorized request authority.
     * @param  StudioHostSessionSnapshot       $snapshot   Trusted live App session snapshot.
     * @param  RequestEnvelope                 $envelope   Parsed canonical Producer envelope.
     *
     * @since  2.0.0
     */
    private function __construct(
        public StudioProducerRequestAuthority $authority,
        public StudioHostSessionSnapshot $snapshot,
        public RequestEnvelope $envelope,
    ) {
    }

    /**
     * Open, parse, and authorize one exact Producer operation using production App authority.
     *
     * @param   string        $capability      Closed Producer operation capability.
     * @param   mixed         $arguments       Candidate operation arguments.
     * @param   string|null   $expectedRevision Optional concurrency coordinate.
     * @param   string|null   $idempotencyKey  Optional mutation replay coordinate.
     * @param   list<string>  $capabilities    Effective trusted App capabilities.
     * @param   callable(StudioHostSessionSnapshot): StudioPreviewTransport|null $previewTransport
     *          Optional transport evidence derived from the opened session.
     * @param   string        $resource        Resource identifier the opened session is bound to.
     *
     * @return  self  Request-scoped authority, snapshot, and parsed envelope.
     *
     * @since  2.0.0
     */
    public static function authorized(
        string $capability,
        mixed $arguments,
        ?string $expectedRevision = null,
        ?string $idempotencyKey = null,
        array $capabilities = [
            'content.publish',
            'content.read',
            'content.unpublish',
            'content.update',
            'studio.mode.content',
        ],
        ?callable $previewTransport = null,
        string $resource = 'content-producer-port-test',
    ): self {
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
                return 'contexts/producer-port-test';
            }
        };
        $sessions = new StudioHostSessionAuthority(AuthorizationContext::gateway(), $repository, $keys);
        $context = AuthenticatedPrincipal::issueFromStrings(
            AuthorizationContext::provenance(),
            AuthorizationContext::SUBJECT,
            $capabilities,
        )->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'studio-producer-port-test',
            surface: AuthenticatedSurface::Administrator,
            sessionId: 'administrator-producer-port-test',
        );
        $snapshot = $sessions->open(
            $context,
            StudioSessionMode::Content,
            StudioResourceKind::Content,
            $resource,
        );
        $requestContext = (object) [
            'operationId' => $capability,
            'protocolVersion' => RequestEnvelope::WIRE_PROTOCOL_VERSION,
            'requestId' => 'requests/producer-port-test',
            'resourceContextKey' => $snapshot->session->resourceContextKey,
            'sessionGeneration' => $snapshot->generation,
        ];
        if ($expectedRevision !== null) {
            $requestContext->expectedRevision = $expectedRevision;
        }
        if ($idempotencyKey !== null) {
            $requestContext->idempotencyKey = $idempotencyKey;
        }
        $envelope = RequestEnvelope::parse(CanonicalJson::stringify((object) [
            'arguments' => $arguments,
            'context' => $requestContext,
        ]));
        $authority = new StudioProducerRequestAuthority(
            $context,
            $sessions,
            $previewTransport === null ? null : $previewTransport($snapshot),
        );
        $refusal = $authority->authorize(OperationRegistry::byCapability($capability), $envelope);
        if ($refusal !== null) {
            throw new LogicException('The Producer port test request was not authorized.');
        }

        return new self($authority, $snapshot, $envelope);
    }

    /**
     * Return the parsed Producer request context.
     *
     * @return  \Kumwe\Producer\Wire\RequestContext  Validated request context.
     *
     * @since  2.0.0
     */
    public function context(): \Kumwe\Producer\Wire\RequestContext
    {
        return $this->envelope->context();
    }

    /**
     * Return the parsed argument value.
     *
     * @return  mixed  Candidate operation arguments.
     *
     * @since  2.0.0
     */
    public function arguments(): mixed
    {
        return $this->envelope->arguments();
    }
}
