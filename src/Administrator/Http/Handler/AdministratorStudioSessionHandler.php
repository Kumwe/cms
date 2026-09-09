<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Http\Handler;

use JsonException;
use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\App\Studio\Application\Host\StudioHostAccessRefused;
use Kumwe\App\Studio\Application\Host\StudioProducerError;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Preview\StudioPreviewTransportGuard;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\Producer\Error\HostError;
use Kumwe\Producer\Wire\RequestEnvelope;
use Kumwe\Producer\Wire\StrictResponder;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\TextResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use stdClass;
use ValueError;

/**
 * Opens a Studio host session from authenticated administrator identity and server-side policy.
 *
 * @since  2.0.0
 */
final readonly class AdministratorStudioSessionHandler implements RequestHandlerInterface
{
    /**
     * Bind session opening to the production host authority boundary.
     *
     * @param  StudioHostSessionAuthority   $authority  Trusted identity, policy and generation service.
     * @param  StudioPreviewTransportGuard  $preview    Server-derived preview channel metadata.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioHostSessionAuthority $authority,
        private StudioPreviewTransportGuard $preview,
    ) {
    }

    /**
     * Validate the closed open request and return only the negotiated host authority projection.
     *
     * Contextual content-authoring sessions are opened only by the server-rendered editor mount; the
     * public open endpoint refuses that kind so a browser cannot mint an authoring session on its own.
     *
     * @param   ServerRequestInterface  $request  Authenticated, authorized and CSRF-checked request.
     *
     * @return  ResponseInterface  No-store session projection or canonical non-disclosing host error.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $body = json_decode((string) $request->getBody(), false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::response(StudioProducerError::error('invalid-request', 'studio.host/invalid-request'));
        }
        $members = $body instanceof stdClass ? array_keys(get_object_vars($body)) : [];
        sort($members, SORT_STRING);
        if (
            !$body instanceof stdClass
            || $members !== ['mode', 'resourceId', 'resourceKind']
            || !is_string($body->mode)
            || !is_string($body->resourceId)
            || !is_string($body->resourceKind)
        ) {
            return self::response(StudioProducerError::error('invalid-request', 'studio.host/invalid-request'));
        }

        try {
            $kind = StudioResourceKind::from($body->resourceKind);
            if ($kind === StudioResourceKind::ContentAuthoring) {
                return self::response(StudioProducerError::error('invalid-request', 'studio.host/invalid-request'));
            }
            $snapshot = $this->authority->open(
                AdministratorRequest::context($request),
                StudioSessionMode::from($body->mode),
                $kind,
                $body->resourceId,
            );
        } catch (StudioHostAccessRefused $refused) {
            return self::response(StudioProducerError::error(
                $refused->category,
                $refused->diagnosticCode,
            ));
        } catch (ValueError | \InvalidArgumentException) {
            return self::response(StudioProducerError::error('invalid-request', 'studio.host/invalid-request'));
        }

        return new JsonResponse([
            'hostCapabilities' => StudioHostSessionAuthority::capabilities($snapshot->session->resourceKind),
            'lifecycle' => [
                'canPublish' => $snapshot->canPublish,
                'canUnpublish' => $snapshot->canUnpublish,
            ],
            'mode' => $snapshot->session->mode->value,
            'permissions' => $snapshot->permissions,
            'protocolVersion' => RequestEnvelope::WIRE_PROTOCOL_VERSION,
            'preview' => [
                'channelId' => $this->preview->channelId($snapshot->session),
                'documentPath' => '/administrator/studio/preview',
                'origin' => $this->preview->origin(),
                'sourceId' => $this->preview->sourceId($snapshot->session),
            ],
            'resourceContextKey' => $snapshot->session->resourceContextKey,
            'resourceKind' => $snapshot->session->resourceKind->value,
            'sessionGeneration' => $snapshot->generation,
        ], 201, ['Cache-Control' => 'no-store']);
    }

    /**
     * Preserve a canonical application outcome at the administrator transport boundary.
     *
     * @param   HostError  $error  Canonical Producer refusal.
     *
     * @return  TextResponse  Exact canonical Producer response bytes and headers.
     *
     * @since   2.0.0
     */
    private static function response(HostError $error): TextResponse
    {
        $response = (new StrictResponder())->refusal($error);
        $headers = [];
        foreach ($response->headers as $name => $value) {
            if ($name !== '') {
                $headers[$name] = $value;
            }
        }

        return new TextResponse(
            $response->body,
            StudioProducerError::status($error->category()),
            $headers,
        );
    }
}
