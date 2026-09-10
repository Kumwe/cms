<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Delivery\Administrator;

use InvalidArgumentException;
use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\App\Administrator\Http\Middleware\AdministratorCsrfMiddleware;
use Kumwe\App\Administrator\Http\Middleware\AdministratorSessionMiddleware;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable;
use Kumwe\App\BusinessSurface\Application\BusinessSurface;
use Kumwe\App\BusinessSurface\Application\GeneratedBusinessActionStepUp;
use Kumwe\App\BusinessSurface\Application\GeneratedBusinessStepUpInputRejected;
use Kumwe\App\BusinessSurface\Delivery\Browser\BusinessBrowserResult;
use Kumwe\App\BusinessSurface\Delivery\Browser\GeneratedBusinessBrowserController;
use Kumwe\App\BusinessSurface\Delivery\Browser\GeneratedBusinessConfirmationQuery;
use Kumwe\App\Http\Middleware\TrustedProxyMiddleware;
use Kumwe\App\Localization\Application\Translator;
use Kumwe\App\Identity\Application\Administration\AuthenticationThrottled;
use Kumwe\App\Identity\Application\StepUp\AdministratorStepUpProvider;
use Kumwe\App\Identity\Application\StepUp\StepUpRejected;
use Kumwe\App\Identity\Domain\StepUp\StepUpVerification;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Thin administrator adapter for the shared generated-business browser controller.
 *
 * @since  2.0.0
 */
final readonly class AdministratorBusinessSurfaceHandler implements RequestHandlerInterface
{
    /**
     * Bind the adapter to shared dispatch and the protected administrator renderer.
     *
     * @param   GeneratedBusinessBrowserController  $business         Shared progressive-enhancement controller.
     * @param   AdministratorRenderer               $renderer         Protected core Twig renderer.
     * @param   AdministratorStepUpProvider         $stepUp           Administrator MFA provider.
     * @param   GeneratedBusinessActionStepUp       $actionStepUp     Exact-purpose proof coordinator.
     * @param   Translator                          $translator       Resolves step-up wording for
     *          the locale in flight.
     * @param   bool                                $secureCookie     Whether rotated cookies require HTTPS.
     * @param   int                                 $sessionLifetime  Administrator cookie lifetime in seconds.
     *
     * @throws  InvalidArgumentException  When the configured session lifetime is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        private GeneratedBusinessBrowserController $business,
        private AdministratorRenderer $renderer,
        private AdministratorStepUpProvider $stepUp,
        private GeneratedBusinessActionStepUp $actionStepUp,
        private Translator $translator,
        private bool $secureCookie,
        private int $sessionLifetime,
    ) {
        if ($sessionLifetime < 300 || $sessionLifetime > 604_800) {
            throw new InvalidArgumentException('The administrator cookie lifetime is invalid.');
        }
    }

    /**
     * Decode trusted route attributes and render or redirect the shared outcome.
     *
     * @param   ServerRequestInterface  $request  Authenticated, authorized and CSRF-checked administrator request.
     *
     * @return  ResponseInterface  No-store HTML page, problem document, or 303 redirect.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return $this->availableResponse($request);
        } catch (BusinessRecordDefinitionUnavailable) {
            return $this->unavailableResponse($request);
        }
    }

    /**
     * Refuse an unavailable definition in the representation the caller can consume.
     *
     * A generated definition is intentionally non-enumerating: absence and policy denial raise the same
     * exception, so this response must not name the handle or guess which capability hid it. Browser
     * navigations receive the protected administrator shell and their own filtered navigation. Machine
     * callers and mutations retain the stable problem document they can branch on.
     *
     * @param   ServerRequestInterface  $request  Request refused before a generated surface could render.
     *
     * @return  ResponseInterface  Themed no-store HTML for a navigation, or a no-store problem document.
     *
     * @since   2.0.0
     */
    private function unavailableResponse(ServerRequestInterface $request): ResponseInterface
    {
        if (
            in_array(strtoupper($request->getMethod()), ['GET', 'HEAD'], true)
            && str_contains(strtolower($request->getHeaderLine('Accept')), 'text/html')
        ) {
            $session = AdministratorRequest::session($request);

            return new HtmlResponse($this->renderer->render('business-unavailable', [
                'csrf' => $session->csrfToken,
                'capabilities' => AdministratorRequest::capabilityMap($request),
            ]), 403, ['Cache-Control' => 'no-store']);
        }

        return new JsonResponse([
            'type' => 'urn:kumwe:problem:authorization-denied',
            'title' => 'Forbidden',
            'status' => 403,
            'detail' => 'The authenticated identity is not authorized for this operation.',
        ], 403, ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store']);
    }

    /**
     * Resolve one generated surface whose definition is available to the authenticated identity.
     *
     * @param   ServerRequestInterface  $request  Authenticated administrator request.
     *
     * @return  ResponseInterface  Generated page, redirect, or step-up validation response.
     *
     * @throws  BusinessRecordDefinitionUnavailable  When the definition is absent or intentionally hidden.
     *
     * @since   2.0.0
     */
    private function availableResponse(ServerRequestInterface $request): ResponseInterface
    {
        $session = AdministratorRequest::session($request);
        $context = AdministratorRequest::context($request);
        $body = $this->body($request);
        $purpose = $this->stepUpPurpose($request, $context, $body);
        if ($purpose === null) {
            return $this->response(
                $request,
                $this->dispatch($request, $context, $body),
                $session->csrfToken,
            );
        }

        try {
            /** @var array{0: BusinessBrowserResult, 1: StepUpVerification} $outcome */
            $outcome = $this->actionStepUp->execute(
                $context,
                $purpose,
                $body,
                $this->source($request),
                $this->stepUp,
                fn (ExecutionContext $stepped): BusinessBrowserResult => $this->dispatch(
                    $request,
                    $stepped,
                    $body,
                ),
            );
            [$result, $verification] = $outcome;
        } catch (AuthenticationThrottled $exception) {
            return $this->confirmationError(
                $request,
                $context,
                $body,
                $exception->getMessage(),
                429,
                ['Retry-After' => '900'],
            );
        } catch (StepUpRejected) {
            return $this->confirmationError(
                $request,
                $context,
                $body,
                $this->translator->translate('core.security.step_up.verification_code_rejected'),
                403,
            );
        } catch (GeneratedBusinessStepUpInputRejected $exception) {
            return $this->confirmationError($request, $context, $body, $exception->getMessage(), 422);
        }

        return $this->response(
            $request,
            $result,
            $verification->rotatedSession->csrfToken,
            $verification->rotatedSession->cookieToken,
        );
    }

    /**
     * Dispatch one request after its final authentication context has been resolved.
     *
     * @param   ServerRequestInterface  $request  Routed browser request.
     * @param   ExecutionContext        $context  Password or freshly stepped-up execution context.
     * @param   array<string, mixed>    $body     Parsed nested form body.
     *
     * @return  BusinessBrowserResult  Shared generated-browser result.
     *
     * @since   2.0.0
     */
    private function dispatch(
        ServerRequestInterface $request,
        ExecutionContext $context,
        array $body,
    ): BusinessBrowserResult {
        $operation = $request->getAttribute('operation');
        $view = $request->getAttribute('view');
        $related = $request->getAttribute('related');
        $media = $request->getAttribute('media');
        $ownedRelationship = $request->getAttribute('owned_relationship');
        $ownedField = $request->getAttribute('owned_field');
        $ownedKind = $request->getAttribute('owned_kind');
        $businessRelationship = $request->getAttribute('business_relationship');
        $query = $this->query($request);
        if ($businessRelationship !== null && strtoupper($request->getMethod()) === 'GET') {
            return $this->business->relationship(
                $context,
                BusinessSurface::Administrator,
                $this->attribute($request, 'definition') ?? '',
                $this->attribute($request, 'record') ?? '',
                is_string($businessRelationship) ? $businessRelationship : '',
                $query,
            );
        } elseif ($ownedRelationship !== null || $ownedField !== null || $ownedKind !== null) {
            return $this->business->ownedLineChoices(
                $context,
                BusinessSurface::Administrator,
                '/administrator/business',
                $this->attribute($request, 'definition') ?? '',
                $this->attribute($request, 'record') ?? '',
                is_string($ownedRelationship) ? $ownedRelationship : '',
                is_string($ownedField) ? $ownedField : '',
                is_string($ownedKind) ? $ownedKind : '',
                $query,
            );
        } elseif ($related !== null || $media !== null) {
            return $this->business->choices(
                $context,
                BusinessSurface::Administrator,
                '/administrator/business',
                $this->attribute($request, 'definition') ?? '',
                $this->attribute($request, 'record'),
                is_string($related) ? $related : null,
                is_string($media) ? $media : null,
                $query,
            );
        } elseif ($view !== null) {
            return $this->business->customView(
                $context,
                BusinessSurface::Administrator,
                $this->attribute($request, 'definition') ?? '',
                is_string($view) ? $view : '',
                $this->attribute($request, 'record'),
                $query,
            );
        } elseif ($operation !== null) {
            return $this->business->operationStatus($context, is_string($operation) ? $operation : '');
        }

        return $this->business->dispatch(
            $context,
            BusinessSurface::Administrator,
            '/administrator/business',
            $request->getMethod(),
            $this->attribute($request, 'definition'),
            $this->attribute($request, 'record'),
            $query,
            $body,
        );
    }

    /**
     * Render or redirect one shared result and optionally publish a rotated session cookie.
     *
     * @param   ServerRequestInterface  $request      Authenticated administrator request.
     * @param   BusinessBrowserResult   $result       Shared controller result.
     * @param   string                  $csrf         CSRF token for the active or rotated session.
     * @param   string|null             $cookieToken  Rotated opaque cookie token, when step-up succeeded.
     * @param   array<string, string>   $headers      Additional response headers.
     *
     * @return  ResponseInterface  No-store HTML page or same-origin redirect.
     *
     * @since   2.0.0
     */
    private function response(
        ServerRequestInterface $request,
        BusinessBrowserResult $result,
        string $csrf,
        ?string $cookieToken = null,
        array $headers = [],
    ): ResponseInterface {
        /** @var array<non-empty-string, array<string>|string> $responseHeaders */
        $responseHeaders = ['Cache-Control' => 'no-store'];
        foreach ($headers as $name => $value) {
            if ($name === '') {
                throw new InvalidArgumentException('A generated business response header name is invalid.');
            }
            $responseHeaders[$name] = $value;
        }
        if ($cookieToken !== null) {
            $responseHeaders['Set-Cookie'] = $this->cookie($cookieToken);
        }
        if ($result->redirect !== null) {
            return new RedirectResponse($result->redirect, $result->status, $responseHeaders);
        }

        return new HtmlResponse($this->renderer->render((string) $result->template, [
            ...$result->data,
            'csrf' => $csrf,
            'capabilities' => AdministratorRequest::capabilityMap($request),
            'business_base_path' => '/administrator/business',
            'active_navigation' => 'core.business-records',
        ]), $result->status, $responseHeaders);
    }

    /**
     * Resolve whether this exact POST needs a fresh proof and return its server-owned purpose.
     *
     * @param   ServerRequestInterface  $request  Routed administrator request.
     * @param   ExecutionContext        $context  Password-authenticated administrator context.
     * @param   array<string, mixed>    $body     Parsed confirmation body.
     *
     * @return  string|null  Exact high-impact action purpose, or null for every other request.
     *
     * @throws  InvalidArgumentException  When an action route omits a required identifier.
     *
     * @since   2.0.0
     */
    private function stepUpPurpose(
        ServerRequestInterface $request,
        ExecutionContext $context,
        array $body,
    ): ?string {
        if (strtoupper($request->getMethod()) !== 'POST' || ($body['operation'] ?? null) !== 'action') {
            return null;
        }
        $definition = $this->attribute($request, 'definition');
        $record = $this->attribute($request, 'record');
        $action = $body['action'] ?? null;
        if ($definition === null || $record === null || !is_string($action) || trim($action) === '') {
            throw new InvalidArgumentException('A generated business action route is incomplete.');
        }

        return $this->business->actionStepUpPurpose(
            $context,
            BusinessSurface::Administrator,
            $definition,
            $action,
        );
    }

    /**
     * Re-render the exact confirmation after a rejected or incomplete second-factor attempt.
     *
     * @param   ServerRequestInterface  $request  Routed administrator request.
     * @param   ExecutionContext        $context  Original password-authenticated context.
     * @param   array<string, mixed>    $body     Submitted action controls retained for correction.
     * @param   string                  $message  Safe non-enumerating verification message.
     * @param   int                     $status   Protected HTTP failure status.
     * @param   array<string, string>   $headers  Optional throttle headers.
     *
     * @return  ResponseInterface  Accessible no-store confirmation response.
     *
     * @since   2.0.0
     */
    private function confirmationError(
        ServerRequestInterface $request,
        ExecutionContext $context,
        array $body,
        string $message,
        int $status,
        array $headers = [],
    ): ResponseInterface {
        $result = $this->business->dispatch(
            $context,
            BusinessSurface::Administrator,
            '/administrator/business',
            'GET',
            $this->attribute($request, 'definition'),
            $this->attribute($request, 'record'),
            GeneratedBusinessConfirmationQuery::retain($body),
            [],
        );
        $result = new BusinessBrowserResult(
            $result->template,
            [...$result->data, 'error_summary' => $message],
            status: $status,
        );

        return $this->response(
            $request,
            $result,
            AdministratorRequest::session($request)->csrfToken,
            headers: $headers,
        );
    }

    /**
     * Resolve the trusted client address used by the verification throttle.
     *
     * @param   ServerRequestInterface  $request  Request carrying the trusted-proxy result.
     *
     * @return  string  Trusted source or a stable unknown marker.
     *
     * @since   2.0.0
     */
    private function source(ServerRequestInterface $request): string
    {
        $source = $request->getAttribute(TrustedProxyMiddleware::ATTRIBUTE_CLIENT_ADDRESS, 'unknown');

        return is_string($source) && $source !== '' ? $source : 'unknown';
    }

    /**
     * Serialize the rotated host-only administrator session cookie.
     *
     * @param   string  $token  New opaque administrator cookie token.
     *
     * @return  string  Hardened administrator-path cookie header.
     *
     * @since   2.0.0
     */
    private function cookie(string $token): string
    {
        return sprintf(
            '%s=%s; Path=/administrator; Max-Age=%d; HttpOnly; SameSite=Strict%s',
            AdministratorSessionMiddleware::COOKIE_NAME,
            $token,
            $this->sessionLifetime,
            $this->secureCookie ? '; Secure' : '',
        );
    }

    /**
     * Read one optional bounded route attribute.
     *
     * @param   ServerRequestInterface  $request  Routed request.
     * @param   string                  $name     Attribute name.
     *
     * @return  string|null  Route value, or null when absent.
     *
     * @since   2.0.0
     */
    private function attribute(ServerRequestInterface $request, string $name): ?string
    {
        $value = $request->getAttribute($name);
        return is_string($value) && $value !== '' && strlen($value) <= 191 ? $value : null;
    }

    /**
     * Preserve nested parsed form values for the schema-authorized input mapper.
     *
     * @param   ServerRequestInterface  $request  Browser request.
     *
     * @return  array<string, mixed>  Parsed body object, empty for a non-object body.
     *
     * @since   2.0.0
     */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getAttribute(
            AdministratorCsrfMiddleware::ATTRIBUTE_PARSED_BODY,
            $request->getParsedBody(),
        );
        if (!is_array($body) || array_is_list($body)) {
            return [];
        }

        return $this->stringKeyed($body);
    }

    /**
     * Preserve string-keyed query controls and discard numeric transport entries.
     *
     * @param   ServerRequestInterface  $request  Browser request.
     *
     * @return  array<string, mixed>  Decoded query object.
     *
     * @since   2.0.0
     */
    private function query(ServerRequestInterface $request): array
    {
        return $this->stringKeyed($request->getQueryParams());
    }

    /**
     * Narrow a PSR transport array to the object shape used by the shared controller.
     *
     * @param   array<mixed>  $values  Parsed transport values.
     *
     * @return  array<string, mixed>  String-keyed members.
     *
     * @since   2.0.0
     */
    private function stringKeyed(array $values): array
    {
        $object = [];
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $object[$key] = $value;
            }
        }

        return $object;
    }
}
