<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Delivery\Api;

use InvalidArgumentException;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionService;
use Kumwe\App\BusinessDefinition\Application\DefinitionCatalogEntry;
use Kumwe\App\BusinessDefinition\Application\DefinitionVersionRecord;
use Kumwe\App\Delivery\Http\Api\ApiExecutionContext;
use Kumwe\App\Delivery\Http\Api\Content\ContentApiRequest;
use Kumwe\App\Delivery\Http\Api\Business\BusinessApiResponder;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use stdClass;
use Throwable;

/**
 * The REST surface for the versioned business-definition catalog.
 *
 * Administrator, CLI and MCP callers reach the same BusinessDefinitionService, so this
 * adapter only translates HTTP into application calls: it never validates definitions,
 * decides compatibility, or touches persistence.
 *
 * @since  2.0.0
 */
final readonly class BusinessDefinitionApiHandler implements RequestHandlerInterface
{
    /**
     * Wire the adapter to the service it fronts and the collaborators that shape what it writes back.
     *
     * @param  BusinessDefinitionService       $definitions  Service every operation is dispatched to.
     * @param  BusinessDefinitionApiPresenter  $presenter    Shapes application results into REST documents.
     * @param  BusinessApiResponder            $responder    Maps failures onto RFC 9457 problem documents.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessDefinitionService $definitions,
        private BusinessDefinitionApiPresenter $presenter,
        private BusinessApiResponder $responder,
    ) {
    }

    /**
     * Route one definition request onto the application service and answer with its result.
     *
     * The path chooses the operation: no handle is the catalog collection, a handle alone is the published
     * head, and the segment after the handle names the sub-resource or lifecycle action. Every failure —
     * including one raised while resolving the execution context — reaches the responder, so a mapped
     * exception leaves as a problem document and only an unmapped one escapes as a fault.
     *
     * @param   ServerRequestInterface  $request  API request whose route attributes carry the handle.
     *
     * @return  ResponseInterface  A JSON document, or the problem document the responder produced.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $context = ApiExecutionContext::fromRequest($request);
            $method = strtoupper($request->getMethod());
            $identifier = $this->attribute($request, 'identifier');
            $action = $this->action($request, $identifier);

            if ($identifier === null) {
                if ($method !== 'GET') {
                    throw new InvalidArgumentException('The definition collection only supports GET.');
                }

                return $this->json(['items' => array_map(
                    $this->presenter->catalogEntry(...),
                    array_values(array_filter(
                        $this->definitions->catalog($context),
                        static fn (mixed $entry): bool => $entry instanceof DefinitionCatalogEntry,
                    )),
                )]);
            }

            return match ([$method, $action]) {
                ['GET', null] => $this->published($request, $identifier),
                ['GET', 'draft'] => $this->json($this->presenter->draft(
                    $this->definitions->draft($context, $identifier),
                )),
                ['GET', 'history'] => $this->json(['items' => array_map(
                    $this->presenter->version(...),
                    $this->definitions->history($context, $identifier),
                )]),
                ['GET', 'compatibility'] => $this->json($this->presenter->compatibility(
                    $this->definitions->previewDraft($context, $identifier),
                )),
                ['PUT', 'draft'] => $this->saveDraft($request, $context, $identifier),
                ['POST', 'validate'] => $this->json($this->presenter->draft(
                    $this->definitions->validateDraft($context, $identifier),
                )),
                ['POST', 'publish'] => $this->publish($request, $context, $identifier),
                ['POST', 'supersede'] => $this->lifecycle($request, $context, $identifier, 'supersede'),
                ['POST', 'deprecate'] => $this->lifecycle($request, $context, $identifier, 'deprecate'),
                ['POST', 'reject'] => $this->lifecycle($request, $context, $identifier, 'reject'),
                default => throw new InvalidArgumentException('The requested definition operation is not supported.'),
            };
        } catch (Throwable $exception) {
            return $this->responder->problem($exception, (string) $request->getUri());
        }
    }

    /**
     * Answer the published head of a definition, or the single version the query string asks for.
     *
     * @param   ServerRequestInterface  $request     Request whose optional `version` query pins a version.
     * @param   string                  $identifier  Definition handle taken from the route.
     *
     * @return  ResponseInterface  The version document, tagged with its `"vN"` entity tag.
     *
     * @throws  InvalidArgumentException  When `version` is present but is not a positive decimal integer.
     *
     * @since   2.0.0
     */
    private function published(ServerRequestInterface $request, string $identifier): ResponseInterface
    {
        $context = ApiExecutionContext::fromRequest($request);
        $requested = $request->getQueryParams()['version'] ?? null;
        $version = null;
        if (is_string($requested) && $requested !== '') {
            if (preg_match('/^[1-9][0-9]{0,8}$/D', $requested) !== 1) {
                throw new InvalidArgumentException('The requested definition version is invalid.');
            }
            $version = (int) $requested;
        }
        $record = $this->definitions->published($context, $identifier, $version);

        return $this->versioned($record);
    }

    /**
     * Replace the draft of a definition with the document the request body carries.
     *
     * The handle in the path wins: a body naming a different one is refused rather than quietly retargeted.
     * `If-Match` states the draft revision the caller expects to overwrite, and its absence is a claim that
     * the definition does not exist yet, so a blind write over someone else's edit is not expressible.
     *
     * @param   ServerRequestInterface  $request     Request whose JSON body carries a `definition` object.
     * @param   ExecutionContext        $context     Authenticated context the draft is saved under.
     * @param   string                  $identifier  Definition handle taken from the route.
     *
     * @return  ResponseInterface  The saved draft, tagged with its new `"rN"` entity tag.
     *
     * @throws  InvalidArgumentException  When the body or the `If-Match` precondition is malformed.
     *
     * @since   2.0.0
     */
    private function saveDraft(
        ServerRequestInterface $request,
        ExecutionContext $context,
        string $identifier,
    ): ResponseInterface {
        $body = ContentApiRequest::json($request);
        $document = $this->definitionDocument($body, $identifier);
        $draft = $this->definitions->importDraft(
            $context,
            $document,
            $this->expectedRevision($request),
        );

        return $this->json($this->presenter->draft($draft), 200, ['ETag' => '"r' . $draft->revision . '"']);
    }

    /**
     * Publish the current draft of a definition as its next version.
     *
     * `If-Match` pins the draft revision being published; without it the revision is read back from the
     * service, which publishes whatever the draft holds at that moment. The service refuses a compatibility
     * plan that changes behaviour or data unless the body sets `confirmed` to true.
     *
     * @param   ServerRequestInterface  $request     Request whose JSON body may carry `confirmed`.
     * @param   ExecutionContext        $context     Authenticated context the publication is recorded under.
     * @param   string                  $identifier  Definition handle taken from the route.
     *
     * @return  ResponseInterface  The new version document, answered 201 with its `"vN"` entity tag.
     *
     * @throws  InvalidArgumentException  When the body or the `If-Match` precondition is malformed.
     *
     * @since   2.0.0
     */
    private function publish(
        ServerRequestInterface $request,
        ExecutionContext $context,
        string $identifier,
    ): ResponseInterface {
        $body = ContentApiRequest::json($request);
        $expected = $this->expectedRevision($request)
            ?? $this->definitions->draft($context, $identifier)->revision;
        $record = $this->definitions->publish(
            $context,
            $identifier,
            $expected,
            ($body['confirmed'] ?? false) === true,
        );

        return $this->versioned($record, 201);
    }

    /**
     * Move one already published version of a definition into a later lifecycle state.
     *
     * The version is named in the body rather than inferred, so retiring an older version cannot be confused
     * with retiring the head.
     *
     * @param   ServerRequestInterface  $request     Request whose JSON body carries the target `version`.
     * @param   ExecutionContext        $context     Authenticated context the change is recorded under.
     * @param   string                  $identifier  Definition handle taken from the route.
     * @param   string                  $action      `supersede`, `deprecate`, or `reject` for anything else.
     *
     * @return  ResponseInterface  The version document in its new state.
     *
     * @throws  InvalidArgumentException  When the body carries no `version` that is a positive integer.
     *
     * @since   2.0.0
     */
    private function lifecycle(
        ServerRequestInterface $request,
        ExecutionContext $context,
        string $identifier,
        string $action,
    ): ResponseInterface {
        $body = ContentApiRequest::json($request);
        $version = $body['version'] ?? null;
        if (!is_int($version) || $version < 1) {
            throw new InvalidArgumentException('A definition lifecycle change requires the target version.');
        }
        $record = match ($action) {
            'supersede' => $this->definitions->supersede($context, $identifier, $version),
            'deprecate' => $this->definitions->deprecate($context, $identifier, $version),
            default => $this->definitions->reject($context, $identifier, $version),
        };

        return $this->versioned($record);
    }

    /**
     * Lift the definition document out of a request body and pin its handle to the route.
     *
     * A body that decoded to `stdClass` is re-decoded associatively so the service always receives one
     * shape. The handle is then forced to the route value, and a body that already spells a different one is
     * refused rather than silently rewritten, which keeps a definition from being moved by a typo.
     *
     * @param   array<string, mixed>  $body        Decoded request body.
     * @param   string                $identifier  Definition handle taken from the route.
     *
     * @return  array<string, mixed>  The definition document with `handle` set to the route value.
     *
     * @throws  InvalidArgumentException  When `definition` is missing, is not an object, or names another handle.
     * @throws  \JsonException  When a `stdClass` body will not round-trip within the depth limit.
     *
     * @since   2.0.0
     */
    private function definitionDocument(array $body, string $identifier): array
    {
        $document = $body['definition'] ?? null;
        if ($document instanceof stdClass) {
            $document = json_decode(json_encode($document, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
        }
        if (!is_array($document) || array_is_list($document)) {
            throw new InvalidArgumentException('The definition field must be a JSON object.');
        }
        /** @var array<string, mixed> $document */
        if (($document['handle'] ?? $identifier) !== $identifier) {
            throw new InvalidArgumentException('The definition handle must match the request path.');
        }
        $document['handle'] = $identifier;

        return $document;
    }

    /**
     * Read the draft revision an `If-Match` precondition pins the request to.
     *
     * @param   ServerRequestInterface  $request  Request whose `If-Match` may carry an `"rN"` entity tag.
     *
     * @return  ?int  The pinned revision, or null when the caller sent no precondition at all.
     *
     * @throws  InvalidArgumentException  When `If-Match` is present but is not an `"rN"` entity tag.
     *
     * @since   2.0.0
     */
    private function expectedRevision(ServerRequestInterface $request): ?int
    {
        $header = trim($request->getHeaderLine('If-Match'));
        if ($header === '') {
            return null;
        }
        if (preg_match('/^"r([1-9][0-9]{0,8})"$/D', $header, $matches) !== 1) {
            throw new InvalidArgumentException('The If-Match header must carry a definition draft revision.');
        }

        return (int) $matches[1];
    }

    /**
     * Answer a version record as JSON tagged with the entity tag callers revalidate against.
     *
     * Every response that answers with a single version leaves through here, so the `"vN"` tag is attached
     * in one place rather than at each operation that produces one.
     *
     * @param   DefinitionVersionRecord  $record  Version to render.
     * @param   int                      $status  Status to answer with; 201 when the version was just made.
     *
     * @return  ResponseInterface  The version document carrying its `"vN"` entity tag.
     *
     * @since   2.0.0
     */
    private function versioned(DefinitionVersionRecord $record, int $status = 200): ResponseInterface
    {
        return $this->json($this->presenter->version($record), $status, [
            'ETag' => '"v' . $record->definition->definitionVersion . '"',
        ]);
    }

    /**
     * Read a routing attribute, treating a missing, non-string or empty value alike as absent.
     *
     * @param   ServerRequestInterface  $request  Request the routing middleware has already matched.
     * @param   string                  $name     Name of the attribute the route captures.
     *
     * @return  ?string  The captured value, or null when the matched route captured none.
     *
     * @since   2.0.0
     */
    private function attribute(ServerRequestInterface $request, string $name): ?string
    {
        $value = $request->getAttribute($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The sub-resource segment following the definition handle, if any.
     *
     * Routes stay literal so each operation carries its own capability and OpenAPI entry;
     * the action is read back from the path rather than encoded as a route placeholder.
     *
     * @param   ServerRequestInterface  $request     Request whose path is split to find the segment.
     * @param   ?string                 $identifier  Definition handle, or null on the collection route.
     *
     * @return  ?string  The segment after the handle, or null when the handle ends the path.
     *
     * @since   2.0.0
     */
    private function action(ServerRequestInterface $request, ?string $identifier): ?string
    {
        if ($identifier === null) {
            return null;
        }
        $segments = explode('/', trim($request->getUri()->getPath(), '/'));
        $position = array_search($identifier, $segments, true);
        if ($position === false) {
            return null;
        }

        return $segments[$position + 1] ?? null;
    }

    /**
     * Answer a JSON document under the no-store policy every definition response carries.
     *
     * Definitions are editable state rather than published content, so nothing on this surface is cacheable;
     * the extra headers are merged last, which is what lets a caller-facing `ETag` sit beside that policy.
     *
     * @param   array<string, mixed>             $document  Body to encode.
     * @param   int                              $status    Status to answer with.
     * @param   array<non-empty-string, string>  $headers   Extra headers, merged over the cache policy.
     *
     * @return  ResponseInterface  The encoded document.
     *
     * @since   2.0.0
     */
    private function json(array $document, int $status = 200, array $headers = []): ResponseInterface
    {
        return new JsonResponse($document, $status, ['Cache-Control' => 'no-store', ...$headers]);
    }
}
