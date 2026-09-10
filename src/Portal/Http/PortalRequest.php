<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Http;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;
use Kumwe\App\Portal\Application\PortalSession;
use Kumwe\Context\Value\ExecutionContext;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared strict reader for flat portal forms and authenticated request attributes.
 *
 * @since  2.0.0
 */
final class PortalRequest
{
    /**
     * Flatten a parsed or URL-encoded form to string keys and values only.
     *
     * @param   ServerRequestInterface  $request  Request carrying a portal form.
     *
     * @return  array<string, string>  Safe flat form fields.
     *
     * @since   2.0.0
     */
    public static function form(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (!is_array($parsed)) {
            $parsed = [];
            parse_str((string) $request->getBody(), $parsed);
        }
        $form = [];
        foreach ($parsed as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $form[$key] = $value;
            }
        }

        return $form;
    }

    /**
     * Read the resolved portal session.
     *
     * @param   ServerRequestInterface  $request  Authenticated portal request.
     *
     * @return  PortalSession  Session attached by portal middleware.
     *
     * @throws  InvalidArgumentException  When the portal authentication boundary was skipped.
     *
     * @since   2.0.0
     */
    public static function session(ServerRequestInterface $request): PortalSession
    {
        $session = $request->getAttribute(PortalSession::REQUEST_ATTRIBUTE);
        if (!$session instanceof PortalSession) {
            throw new InvalidArgumentException('A portal session is required.');
        }

        return $session;
    }

    /**
     * Read the portal-surface execution context.
     *
     * @param   ServerRequestInterface  $request  Authorized portal request.
     *
     * @return  ExecutionContext  Context minted by the portal provenance authority.
     *
     * @throws  InvalidArgumentException  When the portal authorization boundary was skipped.
     *
     * @since   2.0.0
     */
    public static function context(ServerRequestInterface $request): ExecutionContext
    {
        $context = $request->getAttribute(ExecutionContextAttribute::NAME);
        if (!$context instanceof ExecutionContext) {
            throw new InvalidArgumentException('A portal execution context is required.');
        }

        return $context;
    }

    /**
     * Project the live principal's capabilities into a template lookup.
     *
     * @param   ServerRequestInterface  $request  Authenticated portal request.
     *
     * @return  array<string, true>  Capability names keyed to true.
     *
     * @since   2.0.0
     */
    public static function capabilityMap(ServerRequestInterface $request): array
    {
        $map = [];
        foreach (self::session($request)->identity->principal->capabilities() as $capability) {
            $map[$capability->value()] = true;
        }

        return $map;
    }
}
