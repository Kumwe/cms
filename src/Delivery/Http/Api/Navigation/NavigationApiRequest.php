<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Http\Api\Navigation;

use InvalidArgumentException;
use JsonException;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;
use Kumwe\App\Delivery\Http\Api\Concurrency\EntityTag;
use Kumwe\App\Delivery\Http\Api\Concurrency\IfMatch;
use Kumwe\App\Delivery\Http\Api\Concurrency\RequireIfMatchMiddleware;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\Context\Value\ExecutionContext;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads and validates the parts of an HTTP request that the navigation API handlers all depend on.
 *
 * Menus and menu items are served by four handlers that share one request grammar — a JSON object
 * body, a route identifier, the execution context authentication attached, and an `If-Match`
 * precondition — so parsing it in one place keeps each handler to the operation it performs. Every
 * accessor reports a bad request as `InvalidArgumentException`, except the precondition check which
 * reports `NavigationPreconditionFailed`; `NavigationApiResponder` already maps those to 422 and 412,
 * which is why a handler can call these straight from its try block without inspecting the values
 * first. Nothing is stored: each call reads the request or decoded body it is handed.
 *
 * @since  2.0.0
 */
final class NavigationApiRequest
{
    /**
     * Decode the request body as a JSON object and return its top-level members.
     *
     * Decoding is depth limited and produces associative arrays throughout, so a nested object arrives
     * as an array rather than a `stdClass`. A JSON array at the top level is refused as firmly as a
     * scalar, because every navigation route addresses its fields by name.
     *
     * @param   ServerRequestInterface  $request  Request whose body carries the JSON document.
     *
     * @return  array<string, mixed>  The top-level members, keyed by field name.
     *
     * @throws  InvalidArgumentException  When the body is not valid JSON, or is not a JSON object.
     *
     * @since   2.0.0
     */
    public static function json(ServerRequestInterface $request): array
    {
        try {
            $data = json_decode((string) $request->getBody(), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The request body must be valid JSON.', 0, $exception);
        }

        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('The request body must be a JSON object.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Return an identifier the router captured from the request path.
     *
     * The navigation routes name their segments differently — `id` on a resource route, `menuId` on the
     * item collection under a menu — so the attribute to read is a parameter rather than a constant.
     *
     * @param   ServerRequestInterface  $request  Request matched by a route declaring that segment.
     * @param   string                  $name     Route attribute to read, quoted in the failure message.
     *
     * @return  string  The captured value; non-empty, but not otherwise validated here.
     *
     * @throws  InvalidArgumentException  When the attribute is absent, empty, or not a string.
     *
     * @since   2.0.0
     */
    public static function route(ServerRequestInterface $request, string $name): string
    {
        $value = $request->getAttribute($name);
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException(sprintf('The %s route identifier is missing.', $name));
        }

        return $value;
    }

    /**
     * Read a mandatory string field from a decoded body, trimmed of surrounding whitespace.
     *
     * @param   array<string, mixed>  $body  Decoded request body the field is read from.
     * @param   string                $name  Member to read, quoted verbatim in the failure message.
     *
     * @return  string  The trimmed value, guaranteed non-empty.
     *
     * @throws  InvalidArgumentException  When the member is absent, is not a string, or is blank once
     *          trimmed.
     *
     * @since   2.0.0
     */
    public static function string(array $body, string $name): string
    {
        $value = $body[$name] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('The %s field must be a non-empty string.', $name));
        }

        return trim($value);
    }

    /**
     * Read an optional string field, folding both absence and emptiness into null.
     *
     * The empty string deliberately becomes null instead of being rejected, so a client clears
     * `parent_id`, `content_id` or `target_url` by sending `""` as readily as by sending `null`.
     *
     * @param   array<string, mixed>  $body  Decoded request body the field is read from.
     * @param   string                $name  Member to read, quoted verbatim in the failure message.
     *
     * @return  ?string  The trimmed value, or null when the member is absent, null, or the empty string.
     *
     * @throws  InvalidArgumentException  When the member is present and non-empty but is not a string.
     *
     * @since   2.0.0
     */
    public static function nullableString(array $body, string $name): ?string
    {
        $value = $body[$name] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('The %s field must be a string or null.', $name));
        }

        return trim($value);
    }

    /**
     * Read the `target_type` field and hold it to the three kinds of link a menu item may carry.
     *
     * The accepted set is checked here as well as in `NavigationService`, so an unrecognised kind is
     * refused as a validation failure by the delivery layer instead of reaching the service.
     *
     * @param   array<string, mixed>  $body  Decoded request body carrying a `target_type` member.
     *
     * @return  string  One of `content`, `anchor` or `url`.
     *
     * @throws  InvalidArgumentException  When the member is absent or blank, or names another kind.
     *
     * @since   2.0.0
     */
    public static function targetType(array $body): string
    {
        $targetType = self::string($body, 'target_type');
        if (!in_array($targetType, ['content', 'anchor', 'url'], true)) {
            throw new InvalidArgumentException('The target_type field must be content, anchor or url.');
        }

        return $targetType;
    }

    /**
     * Read a non-negative integer field, falling back to a default when the member is not supplied.
     *
     * The value is required to be a JSON number that decoded to an integer, so `"3"` is refused rather
     * than coerced — a sort position quietly built from a string would order siblings by accident.
     *
     * @param   array<string, mixed>  $body     Decoded request body the field is read from.
     * @param   string                $name     Member to read, quoted verbatim in the failure message.
     * @param   int                   $default  Value used when the member is absent or null.
     *
     * @return  int  The supplied value or the default, zero or greater either way.
     *
     * @throws  InvalidArgumentException  When the member is present but is not a non-negative integer.
     *
     * @since   2.0.0
     */
    public static function integer(array $body, string $name, int $default = 0): int
    {
        $value = $body[$name] ?? $default;
        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException(sprintf('The %s field must be a non-negative integer.', $name));
        }

        return $value;
    }

    /**
     * Return the principal the API authentication middleware attached to this request.
     *
     * @param   ServerRequestInterface  $request  Request that has already passed API authentication.
     *
     * @return  AuthenticatedPrincipal  The authenticated caller behind the request.
     *
     * @throws  InvalidArgumentException  When no principal is attached, which means the route was
     *          mounted without the authentication middleware.
     *
     * @since   2.0.0
     */
    public static function principal(ServerRequestInterface $request): AuthenticatedPrincipal
    {
        $principal = $request->getAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);
        if (!$principal instanceof AuthenticatedPrincipal) {
            throw new InvalidArgumentException('An authenticated principal is required.');
        }

        return $principal;
    }

    /**
     * Return the execution context the API authentication middleware attached to this request.
     *
     * Every navigation handler passes this straight to `NavigationService`, which authorizes against
     * it. A context is only honoured by the authorization gateway when the authentication middleware
     * issued it, so reading one off the request rather than assembling one keeps policy out of the
     * delivery layer.
     *
     * @param   ServerRequestInterface  $request  Request that has already passed API authentication.
     *
     * @return  ExecutionContext  Actor and site every navigation read and write runs as.
     *
     * @throws  InvalidArgumentException  When no context is attached, which means the route was
     *          mounted without the authentication middleware.
     *
     * @since   2.0.0
     */
    public static function context(ServerRequestInterface $request): ExecutionContext
    {
        $context = $request->getAttribute(ExecutionContextAttribute::NAME);
        if (!$context instanceof ExecutionContext) {
            throw new InvalidArgumentException('An authenticated execution context is required.');
        }

        return $context;
    }

    /**
     * Settle the caller's `If-Match` precondition against the revision it is about to overwrite.
     *
     * `RequireIfMatchMiddleware` has already refused a missing or malformed header, so all that is
     * left to decide is whether the tag the client quoted still names the version just read from the
     * store. The version is handed back rather than a boolean, so a handler can pass the result
     * straight on as the expected version without unwrapping the precondition itself.
     *
     * @param   ServerRequestInterface  $request         Request carrying the parsed `IfMatch` attribute.
     * @param   int                     $currentVersion  Version of the record the handler just loaded.
     *
     * @return  int  The same version, now proven to be the one the caller read.
     *
     * @throws  NavigationPreconditionFailed  When no precondition is attached, or the quoted tag names
     *          another version.
     *
     * @since   2.0.0
     */
    public static function expectedVersion(ServerRequestInterface $request, int $currentVersion): int
    {
        $condition = $request->getAttribute(RequireIfMatchMiddleware::ATTRIBUTE);
        if (!$condition instanceof IfMatch || !$condition->matches(EntityTag::fromVersion($currentVersion))) {
            throw new NavigationPreconditionFailed();
        }

        return $currentVersion;
    }
}
