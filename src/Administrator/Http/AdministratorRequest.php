<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Http;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;
use Kumwe\App\Content\Domain\PublicationWindow;
use Kumwe\App\Identity\Application\Administration\AdministratorSession;
use Kumwe\Context\Value\ExecutionContext;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared reader that turns one administrator HTTP request into the validated values its handlers need.
 *
 * Every administrator screen posts a flat HTML form, and every one of them needs the same things
 * back: the session, the execution context, a required field, a version number. Concentrating that
 * parsing here is what keeps the handlers free of `is_string()` ladders and gives every screen the
 * same rejection for the same malformed input. Nothing here authorises anything — it only reads what
 * the authentication and authorization middleware already attached to the request, and fails loudly
 * when a route was mounted without them.
 *
 * @since  2.0.0
 */
final class AdministratorRequest
{
    /**
     * Read the request body as an array, decoding a urlencoded body the server did not parse.
     *
     * PSR-7 only guarantees a parsed body for the shapes the server understood, so a form arriving
     * with an unusual content type reaches the handler unparsed. Falling back to `parse_str` keeps
     * every administrator route reading its form the same way.
     *
     * @param   ServerRequestInterface  $request  Request whose body carries the submitted form.
     *
     * @return  array<array-key, mixed>  The parsed body, or the urlencoded body decoded in its place.
     *
     * @since   2.0.0
     */
    public static function parsedBody(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed)) {
            return $parsed;
        }

        $form = [];
        parse_str((string) $request->getBody(), $form);
        return $form;
    }

    /**
     * Flatten the parsed body into the string map every reader below expects.
     *
     * Keys and values that are not strings are dropped, and a list of strings — the shape a
     * multi-select or a repeated checkbox posts — is joined with commas so one reader can take both
     * forms. A field whose list holds anything other than strings is dropped rather than half kept.
     *
     * @param   ServerRequestInterface  $request  Request whose body carries the submitted form.
     *
     * @return  array<string, string>  Only the fields that survived flattening; an absent key was dropped.
     *
     * @since   2.0.0
     */
    public static function form(ServerRequestInterface $request): array
    {
        $parsed = self::parsedBody($request);

        $form = [];

        foreach ($parsed as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $form[$key] = $value;
                continue;
            }
            if (is_string($key) && is_array($value) && array_is_list($value)) {
                $strings = array_values(array_filter($value, 'is_string'));
                if (count($strings) === count($value)) {
                    $form[$key] = implode(',', $strings);
                }
            }
        }

        return $form;
    }

    /**
     * Read a field the handler cannot proceed without, trimming the whitespace around it.
     *
     * @param   array<string, string>  $form   Flattened form as returned by `form()`.
     * @param   string                 $field  Name of the mandatory field.
     *
     * @return  string  The trimmed value, guaranteed non-empty.
     *
     * @throws  InvalidArgumentException  When the field is absent or trims to the empty string.
     *
     * @since   2.0.0
     */
    public static function required(array $form, string $field): string
    {
        $value = trim($form[$field] ?? '');

        if ($value === '') {
            throw new InvalidArgumentException(sprintf('The %s field is required.', $field));
        }

        return $value;
    }

    /**
     * Read a field that must spell a positive decimal integer, such as an optimistic-locking version.
     *
     * The value is pattern-matched rather than cast, so `01`, `1.5` and `1abc` are refused instead of
     * quietly becoming a number that would then be compared against a stored version.
     *
     * @param   array<string, string>  $form   Flattened form as returned by `form()`.
     * @param   string                 $field  Name of the field holding the number.
     *
     * @return  int  The value as an integer, always one or greater.
     *
     * @throws  InvalidArgumentException  When the field is absent or is not a positive decimal integer.
     *
     * @since   2.0.0
     */
    public static function positiveInteger(array $form, string $field): int
    {
        $value = $form[$field] ?? '';

        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('The %s field must be a positive integer.', $field));
        }

        return (int) $value;
    }

    /**
     * Decode the `data` field of a content form into the entry body its content type validates.
     *
     * A blank field is an empty body rather than a failure, which is how a newly created entry with
     * nothing filled in still saves. A JSON array is refused because entry data is always keyed.
     *
     * @param   array<string, string>  $form  Flattened form as returned by `form()`.
     *
     * @return  array<string, mixed>  The decoded object, or an empty array when the field was blank.
     *
     * @throws  InvalidArgumentException  When the field is not valid JSON or does not decode to an object.
     *
     * @since   2.0.0
     */
    public static function contentData(array $form): array
    {
        $json = trim($form['data'] ?? '');

        if ($json === '') {
            return [];
        }

        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Content data must be a valid JSON object.', 0, $exception);
        }

        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('Content data must be a JSON object.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Build the publication window from the optional schedule fields of a content form.
     *
     * Either end may be left blank, which is how an entry publishes immediately, stays published
     * indefinitely, or both.
     *
     * @param   array<string, string>  $form  Flattened form carrying `publish_at` and `unpublish_at`.
     *
     * @return  PublicationWindow  Bounded by whichever of the two fields the operator filled in.
     *
     * @throws  \DateMalformedStringException  When either field is not a readable date and time.
     * @throws  InvalidArgumentException  When the window would close before it opens.
     *
     * @since   2.0.0
     */
    public static function publicationWindow(array $form): PublicationWindow
    {
        $startsAt = trim($form['publish_at'] ?? '');
        $endsAt = trim($form['unpublish_at'] ?? '');

        return new PublicationWindow(
            $startsAt === '' ? null : new DateTimeImmutable($startsAt),
            $endsAt === '' ? null : new DateTimeImmutable($endsAt),
        );
    }

    /**
     * Read the content identifier the router captured from the route path.
     *
     * @param   ServerRequestInterface  $request  Request the routing middleware has already matched.
     *
     * @return  string  The `{id}` segment, guaranteed non-empty.
     *
     * @throws  InvalidArgumentException  When the handler was reached through a route with no `{id}` segment.
     *
     * @since   2.0.0
     */
    public static function routeId(ServerRequestInterface $request): string
    {
        $id = $request->getAttribute('id');

        if (!is_string($id) || $id === '') {
            throw new InvalidArgumentException('The content route identifier is missing.');
        }

        return $id;
    }

    /**
     * Read the administrator session the authentication middleware attached to the request.
     *
     * @param   ServerRequestInterface  $request  Request that has already passed administrator authentication.
     *
     * @return  AdministratorSession  The signed-in session, carrying its principal and CSRF token.
     *
     * @throws  InvalidArgumentException  When no session is attached, meaning the route skipped authentication.
     *
     * @since   2.0.0
     */
    public static function session(ServerRequestInterface $request): AdministratorSession
    {
        $session = $request->getAttribute(AdministratorSession::REQUEST_ATTRIBUTE);

        if (!$session instanceof AdministratorSession) {
            throw new InvalidArgumentException('An administrator session is required.');
        }

        return $session;
    }

    /**
     * Read the execution context the authorization middleware attached to the request.
     *
     * @param   ServerRequestInterface  $request  Request that has already passed administrator authorization.
     *
     * @return  ExecutionContext  Actor, site and correlation identifiers every application service demands.
     *
     * @throws  InvalidArgumentException  When no context is attached, meaning the route skipped authorization.
     *
     * @since   2.0.0
     */
    public static function context(ServerRequestInterface $request): ExecutionContext
    {
        $context = $request->getAttribute(ExecutionContextAttribute::NAME);
        if (!$context instanceof ExecutionContext) {
            throw new InvalidArgumentException('An administrator execution context is required.');
        }

        return $context;
    }

    /**
     * Project the signed-in principal's capabilities into the lookup the administrator templates use.
     *
     * Templates hide controls the actor cannot use, and a keyed map lets a template ask for
     * `capabilities['content.delete']` directly instead of scanning a list on every render.
     *
     * @param   ServerRequestInterface  $request  Request carrying the administrator session.
     *
     * @return  array<string, true>  Capability code to `true`; a missing key means the actor lacks it.
     *
     * @throws  InvalidArgumentException  When no administrator session is attached to the request.
     *
     * @since   2.0.0
     */
    public static function capabilityMap(ServerRequestInterface $request): array
    {
        $map = [];
        foreach (self::session($request)->principal->capabilities() as $capability) {
            $map[$capability->value()] = true;
        }

        return $map;
    }
}
