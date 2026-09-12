<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Http\Api\Idempotency;

use JsonException;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;
use Kumwe\App\Application\Idempotency\SecretOnceIdempotencyLedger;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\Context\Value\ExecutionContext;
use Laminas\Diactoros\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Throwable;

/**
 * Makes a token mutation idempotent while guaranteeing its secret is handed out exactly once.
 *
 * `POST /api/v1/tokens` and `POST /api/v1/tokens/{tokenId}/rotate` are the only routes whose success
 * body carries a live credential, so they cannot use `PersistentIdempotencyMiddleware`: that middleware
 * stores the response verbatim, which would leave the plaintext token sitting in the idempotency ledger
 * for anyone who can repeat the key or read the record. This middleware reserves the key the same way,
 * but strips `token` from the body before storing it and marks the stored copy `secret_returned: false`,
 * so a replay proves the operation already happened without reissuing the secret. A caller that loses
 * the original response has lost the credential and must mint another. Every read and write goes through
 * the application-owned `SecretOnceIdempotencyLedger`, whose lease is deliberately short — two minutes,
 * against the persistent ledger's fifteen — because a token mutation is a single quick write and a long
 * lease would strand the key after a crash. Anything other than a 2xx or a 5xx is stored as-is, since a
 * refusal carries no secret to strip; a 5xx is rolled back and its reservation deleted, leaving the key
 * free to retry.
 *
 * @since  2.0.0
 */
final readonly class SecretOnceIdempotencyMiddleware implements MiddlewareInterface
{
    /**
     * Wire the middleware to the ledger and the policy check a reservation depends on.
     *
     * @param  SecretOnceIdempotencyLedger    $ledger            Application-owned ledger the reservation
     *         lifecycle runs against.
     * @param  ProblemDetailsResponseFactory  $problems          Renders the refusals a reused key answers
     *         with.
     * @param  HttpMutationPreauthorizer      $preauthorization  Applies the route's exact policy before any
     *         record is observed or reserved.
     * @param  TransactionManager             $transactions      Commits the minted token and its stored
     *         replay together, or neither.
     *
     * @since  2.0.0
     */
    public function __construct(
        private SecretOnceIdempotencyLedger $ledger,
        private ProblemDetailsResponseFactory $problems,
        private HttpMutationPreauthorizer $preauthorization,
        private TransactionManager $transactions,
    ) {
    }

    /**
     * Reserve the idempotency key, run the token operation, and store a replay that carries no secret.
     *
     * The order is deliberate. Authorization runs first, before any stored record is read, so a caller
     * cannot learn from a replay that an operation it may not perform has already happened. The
     * reservation is then taken outside the transaction, so a concurrent request with the same key is
     * refused rather than made to wait; if `acquire()` hands back a response — a replay, or a refusal —
     * the handler is never called at all. Only then does the transaction open, re-prove that this request
     * still owns the lease under a row lock, call the handler, and write the stripped result, so the
     * minted credential and the record saying the key is spent commit together or not at all. Both
     * failure paths delete the reservation, leaving the key free for another attempt: a 5xx returns the
     * handler's own response, anything else re-throws after the cleanup.
     *
     * @param   ServerRequestInterface   $request  Request carrying the validated `Idempotency-Key`, the
     *          authenticated principal and the execution context, all attached upstream.
     * @param   RequestHandlerInterface  $handler  Rest of the pipeline, which mints or rotates the token.
     *
     * @return  ResponseInterface  The handler's response on a first run; on a repeat, either the stored
     *          result with `Idempotency-Replayed: true` and no secret, or a problem
     *          document reporting a reused key, a changed authorization context, or
     *          an operation still in flight.
     *
     * @throws  RuntimeException  When the request reaches this middleware without an authenticated
     *          principal, a validated key or a matching execution context; when the lease is lost
     *          between acquiring it and completing; or when a stored result fails its integrity check.
     * @throws  \InvalidArgumentException  When the route has no exact authorization policy, or the body
     *          the policy check reads is not a usable JSON object.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not perform
     *          the mutation, checked before any record is observed.
     *
     * @since   2.0.0
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $key = $request->getAttribute(RequireIdempotencyKeyMiddleware::ATTRIBUTE);
        $principal = $request->getAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);
        $context = $request->getAttribute(ExecutionContextAttribute::NAME);
        if (
            !$key instanceof IdempotencyKey
            || !$principal instanceof AuthenticatedPrincipal
            || !$context instanceof ExecutionContext
            || $context->principal() !== $principal
        ) {
            throw new RuntimeException('Secret idempotency requires an authenticated request and validated key.');
        }

        $this->preauthorization->authorize($request, $context);
        $operation = strtoupper($request->getMethod()) . ' ' . $request->getUri()->getPath();
        $digest = $this->requestDigest($request);
        $owner = bin2hex(random_bytes(32));
        $replay = $this->acquire(
            $context,
            $principal,
            $operation,
            (string) $key,
            $digest,
            $owner,
            $request,
        );
        if ($replay !== null) {
            return $replay;
        }

        try {
            return $this->transactions->transactional(function () use (
                $principal,
                $context,
                $operation,
                $key,
                $owner,
                $request,
                $handler,
            ): ResponseInterface {
                $subject = $principal->subject();
                if (
                    !$this->ledger->confirmLease(
                        $subject,
                        $operation,
                        (string) $key,
                        $owner,
                        $context->authorizationFingerprint(),
                    )
                ) {
                    throw new RuntimeException('The token mutation lease is no longer owned by this request.');
                }
                $response = $handler->handle($request);
                if ($response->getStatusCode() >= 500) {
                    throw new SecretOnceResponseRollback($response);
                }
                [$storedBody, $headers] = $this->replaySafeResponse($response);
                if (
                    !$this->ledger->complete(
                        $subject,
                        $operation,
                        (string) $key,
                        $owner,
                        $context->authorizationFingerprint(),
                        $response->getStatusCode(),
                        $storedBody,
                        $headers,
                    )
                ) {
                    throw new RuntimeException('The token mutation lease was lost before completion.');
                }
                return $response;
            });
        } catch (SecretOnceResponseRollback $rollback) {
            $this->ledger->release($principal->subject(), $operation, (string) $key, $owner);
            return $rollback->response;
        } catch (Throwable $exception) {
            $this->ledger->release($principal->subject(), $operation, (string) $key, $owner);
            throw $exception;
        }
    }

    /**
     * Claim the key for this request, or answer the repeat that has already claimed it.
     *
     * The claim is a single ledger reservation, so the store's uniqueness rule on subject, operation and
     * key is what arbitrates between two simultaneous first attempts rather than a read-then-write that
     * could interleave. Only the loser takes the slower path: it compares the stored request digest and
     * authorization fingerprint against this request, refusing a key reused for different content with
     * 422 and one presented under different credentials with 409, then replays a completed result. A
     * record that failed, expired, or whose lease has run out is taken over by a conditional ledger
     * write, which either succeeds outright or leaves the caller with the 409 that says another attempt
     * is still running.
     *
     * @param   ExecutionContext        $context    Actor and site; its authorization fingerprint is stored
     *          so a later attempt under different credentials cannot replay this one.
     * @param   AuthenticatedPrincipal  $principal  Caller the record is keyed against, by subject.
     * @param   string                  $operation  Method and path pair the key is scoped to.
     * @param   string                  $key        The client's `Idempotency-Key`, already validated.
     * @param   string                  $digest     Digest of this request, compared against the stored one.
     * @param   string                  $owner      Random token proving this request holds the lease.
     * @param   ServerRequestInterface  $request    Request whose URI is quoted in any problem document.
     *
     * @return  ?ResponseInterface  Null when the caller now owns the reservation and must run the handler;
     *          otherwise the response to return instead of running it.
     *
     * @throws  RuntimeException  When the record vanishes between the failed reservation and the read, or
     *          a stored result proves unusable while being replayed.
     * @throws  JsonException  When a stored header map cannot be decoded, or a stripped body cannot be
     *          re-encoded, while a completed result is being replayed.
     *
     * @since   2.0.0
     */
    private function acquire(
        ExecutionContext $context,
        AuthenticatedPrincipal $principal,
        string $operation,
        string $key,
        string $digest,
        string $owner,
        ServerRequestInterface $request,
    ): ?ResponseInterface {
        if (
            $this->ledger->reserve(
                $principal->subject(),
                $operation,
                $key,
                $digest,
                $context->authorizationFingerprint(),
                $owner,
            )
        ) {
            return null;
        }
        $row = $this->record($principal->subject(), $operation, $key);
        $storedDigest = $row['request_digest'] ?? null;
        if (!is_string($storedDigest) || !hash_equals($storedDigest, $digest)) {
            return $this->problems->create(
                422,
                'Idempotency Key Reused',
                'This Idempotency-Key was already used for a different request.',
                'urn:kumwe:problem:idempotency-key-reused',
                (string) $request->getUri(),
            );
        }
        $storedFingerprint = $row['authorization_fingerprint'] ?? null;
        if (
            !is_string($storedFingerprint)
            || !hash_equals($storedFingerprint, $context->authorizationFingerprint())
        ) {
            return $this->problems->create(
                409,
                'Authorization Context Changed',
                'This Idempotency-Key belongs to a different credential or authorization state.',
                'urn:kumwe:problem:idempotency-authorization-changed',
                (string) $request->getUri(),
            );
        }
        if (($row['state'] ?? null) === 'completed') {
            return $this->replay($row, $principal->subject(), $operation, $key);
        }
        if (
            $this->ledger->takeOver(
                $principal->subject(),
                $operation,
                $key,
                $digest,
                $context->authorizationFingerprint(),
                $owner,
            )
        ) {
            return null;
        }
        return $this->problems->create(
            409,
            'Operation In Progress',
            'An operation with this Idempotency-Key is still in progress.',
            'urn:kumwe:problem:idempotency-in-progress',
            (string) $request->getUri(),
        );
    }

    /**
     * Reduce a successful token response to the body and headers it is safe to keep for replay.
     *
     * This is where the secret is removed: a 2xx body must be a JSON object carrying `token`, and what
     * is stored is that object without it, flagged `secret_returned: false`. The shape is enforced
     * rather than assumed, because a handler that answered 2xx without a token would otherwise have a
     * response stored that a replay could not distinguish from a stripped one. A non-2xx body is kept
     * verbatim, since a refusal carries nothing to strip. Only the three headers a replay must reproduce
     * are retained; everything else is regenerated when the stored response is rebuilt.
     *
     * @param   ResponseInterface  $response  Response the token handler produced, still holding its secret.
     *
     * @return  array{string, array<string, string>}  The body to store first, then the headers to store
     *          with it, keyed by header name.
     *
     * @throws  RuntimeException  When a 2xx body is not valid JSON, is not a JSON object, or carries no
     *          string `token` to strip.
     * @throws  JsonException  When the stripped object cannot be re-encoded, as with a body holding
     *          malformed UTF-8.
     *
     * @since   2.0.0
     */
    private function replaySafeResponse(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            try {
                $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('A token response must contain a JSON object.', 0, $exception);
            }
            if (!is_array($decoded) || array_is_list($decoded) || !is_string($decoded['token'] ?? null)) {
                throw new RuntimeException('A successful token response must contain a one-time token secret.');
            }
            unset($decoded['token']);
            $decoded['secret_returned'] = false;
            $body = json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        $headers = [];
        foreach (['Content-Type', 'Cache-Control', 'Location'] as $name) {
            if ($response->hasHeader($name)) {
                $headers[$name] = $response->getHeaderLine($name);
            }
        }
        return [$body, $headers];
    }

    /**
     * Rebuild the stored result for a repeat of a key that has already completed.
     *
     * The body's digest is verified before anything is sent, so a record altered in the store is refused
     * rather than replayed. A stored body still holding `token` is stripped here and written back, which
     * is the second line of defence behind `replaySafeResponse()`: a record stored before that stripping
     * applied is made safe on its first replay instead of handing the secret out again. The rebuilt
     * response is marked `Idempotency-Replayed: true`, so a client can tell a replay from a fresh run.
     *
     * @param   array<string, mixed>  $row        Stored record, as `record()` read it.
     * @param   string                $subject    Principal the record is keyed against, for the rewrite.
     * @param   string                $operation  Method and path pair the key is scoped to.
     * @param   string                $key        The client's `Idempotency-Key`.
     *
     * @return  ResponseInterface  The stored status, headers and secret-free body, plus the replay marker.
     *
     * @throws  RuntimeException  When the stored body is missing or fails its digest check, the stored
     *          headers are not a JSON object of strings, or the stored status is not a number.
     * @throws  JsonException  When the stored headers cannot be decoded, or the stripped body cannot be
     *          re-encoded.
     *
     * @since   2.0.0
     */
    private function replay(array $row, string $subject, string $operation, string $key): ResponseInterface
    {
        $body = $row['result_body'] ?? null;
        $digest = $row['result_body_digest'] ?? null;
        if (!is_string($body) || !is_string($digest) || !hash_equals($digest, hash('sha256', $body))) {
            throw new RuntimeException('The stored token response failed its integrity check.');
        }
        $decoded = json_decode($body, true);
        if (is_array($decoded) && !array_is_list($decoded) && array_key_exists('token', $decoded)) {
            unset($decoded['token']);
            $decoded['secret_returned'] = false;
            $body = json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $this->ledger->rewriteStoredResult($subject, $operation, $key, $body);
        }
        $headers = $row['result_headers'] ?? [];
        if (is_string($headers)) {
            $headers = json_decode($headers, true, 32, JSON_THROW_ON_ERROR);
        }
        if (!is_array($headers) || array_is_list($headers)) {
            throw new RuntimeException('Stored token response headers are invalid.');
        }
        $response = (new Response())->withStatus($this->integer($row['result_status'] ?? null));
        foreach ($headers as $name => $value) {
            if (!is_string($name) || $name === '' || !is_string($value)) {
                throw new RuntimeException('Stored token response headers are invalid.');
            }
            $response = $response->withHeader($name, $value);
        }
        $response = $response->withHeader('Idempotency-Replayed', 'true');
        $response->getBody()->write($body);
        return $response;
    }

    /**
     * Read the record a losing reservation collided with.
     *
     * Reached only after the ledger refused the claim, so the record is expected to exist; its absence
     * means a purge or a competing delete landed in between, which is a fault rather than a client
     * mistake.
     *
     * @param   string  $subject    Principal the record is keyed against.
     * @param   string  $operation  Method and path pair the key is scoped to.
     * @param   string  $key        The client's `Idempotency-Key`.
     *
     * @return  array<string, mixed>  The stored record, keyed by column name, exactly as the ledger
     *          returned it.
     *
     * @throws  RuntimeException  When no record carries that subject, operation and key.
     *
     * @since   2.0.0
     */
    private function record(string $subject, string $operation, string $key): array
    {
        $row = $this->ledger->find($subject, $operation, $key);
        if ($row === null) {
            throw new RuntimeException('The token idempotency record disappeared during acquisition.');
        }
        return $row;
    }

    /**
     * Fingerprint the parts of a request that must not change between attempts at one key.
     *
     * Method, path, query, content type, precondition and body are hashed together, so a client that
     * reuses a key for different content is caught rather than served the earlier result. Headers
     * outside that list are excluded on purpose: a retry through a different proxy, or with a new trace
     * header, is the same request and must still match.
     *
     * @param   ServerRequestInterface  $request  Request to fingerprint; its body is read in full.
     *
     * @return  string  Hex SHA-256 digest, compared with `hash_equals()` against the stored one.
     *
     * @since   2.0.0
     */
    private function requestDigest(ServerRequestInterface $request): string
    {
        return hash('sha256', implode("\n", [
            strtoupper($request->getMethod()),
            $request->getUri()->getPath(),
            $request->getUri()->getQuery(),
            $request->getHeaderLine('Content-Type'),
            $request->getHeaderLine('If-Match'),
            (string) $request->getBody(),
        ]));
    }

    /**
     * Read a stored HTTP status back as an integer, whatever spelling the ledger returned it in.
     *
     * Stores disagree on whether an integer column arrives as an int or a decimal string, so both are
     * accepted. Anything else is corrupt storage rather than a client mistake, and is refused instead of
     * being cast into a status that would be replayed as though it were the stored one.
     *
     * @param   mixed  $value  Value of the `result_status` column as the ledger returned it.
     *
     * @return  int  The stored status code.
     *
     * @throws  RuntimeException  When the value is neither an integer nor a string of decimal digits.
     *
     * @since   2.0.0
     */
    private function integer(mixed $value): int
    {
        if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException('Stored token response status is invalid.');
        }
        return (int) $value;
    }
}
