<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Http\Api;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\Context\Value\ExecutionContext;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The one way a JSON API handler obtains the actor it acts for, with the request's identity re-checked.
 *
 * Bearer authentication attaches two things to the request: the `ExecutionContext` every application
 * service demands, and the `AuthenticatedPrincipal` the token resolved to. Every API handler reads
 * them through here so that no handler invents its own attribute lookup and none proceeds on a context
 * whose subject disagrees with the principal the request was authenticated as — a mismatch means the
 * two attributes came from different places, which is a composition failure and never a request to
 * serve. Because the comparison requires a matching human subject on both sides, an API request cannot
 * borrow a system context; system work runs through the console and the queue instead.
 *
 * @since  2.0.0
 */
final class ApiExecutionContext
{
    /**
     * Take the authenticated execution context off the request after proving it matches the principal.
     *
     * @param   ServerRequestInterface  $request  Request the authentication middleware has already processed.
     *
     * @return  ExecutionContext  The attached context, safe to hand to an application service.
     *
     * @throws  InvalidArgumentException  When no execution context is attached, when no authenticated
     *          principal is attached, or when the context carries no principal or one
     *          naming a different subject.
     *
     * @since   2.0.0
     */
    public static function fromRequest(ServerRequestInterface $request): ExecutionContext
    {
        $context = $request->getAttribute(ExecutionContextAttribute::NAME);
        if (!$context instanceof ExecutionContext) {
            throw new InvalidArgumentException('An authenticated execution context is required.');
        }
        $principal = $request->getAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);
        if (
            !$principal instanceof AuthenticatedPrincipal
            || $context->principal()?->subject() !== $principal->subject()
        ) {
            throw new InvalidArgumentException('The API principal and execution context identities must match.');
        }

        return $context;
    }
}
