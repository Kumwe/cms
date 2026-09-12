<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Http\Api\Idempotency;

use DateTimeImmutable;
use JsonException;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;
use Kumwe\App\Application\Idempotency\IdempotencyLedger;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\Context\Value\ExecutionContext;
use Laminas\Diactoros\Response;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

/**
 * Makes an API mutation idempotent by reserving its key, running it once, and replaying the stored result.
 *
 * This is the general-purpose half of the idempotency pair: the mutating API routes mount it behind
 * `RequireIdempotencyKeyMiddleware`. It stores the handler's response verbatim, which is precisely why
 * token issuance and rotation use `SecretOnceIdempotencyMiddleware` instead — those bodies carry a live
 * credential. A record is keyed by subject, operation and key, and the application-owned
 * `IdempotencyLedger` arbitrates the reservation on that identity, so two simultaneous first attempts
 * cannot both reach the handler; this middleware never touches the store behind that port. What a caller
 * is promised: a completed operation is replayed with `Idempotency-Replayed: true` rather than repeated,
 * a key reused for different content is refused with 422, a key presented under different credentials
 * with 409, and an attempt still in flight with 409. Exact authorization runs before the ledger is read
 * at all, so a replay can never be used to probe for a mutation the caller may not perform. The
 * handler's writes and the record that marks the key spent commit in one transaction, so a stored replay
 * always corresponds to an effect that actually landed; a 5xx or a thrown fault deletes the reservation
 * instead, leaving the key free for another attempt.
 *
 * @since  2.0.0
 */
final readonly class PersistentIdempotencyMiddleware implements MiddlewareInterface
{
    /**
     * Wire the middleware to the ledger, the clock and the policy check a reservation depends on.
     *
     * @param  IdempotencyLedger              $ledger            Application-owned ledger the reservation
     *         lifecycle runs against.
     * @param  ClockInterface                 $clock             Supplies the instant stored expiry and lock
     *         lapse are judged against.
     * @param  ProblemDetailsResponseFactory  $problems          Renders the refusals a reused or contested
     *         key is answered with.
     * @param  TransactionManager             $transactions      Commits the mutation and the record marking
     *         its key spent together, or neither.
     * @param  HttpMutationPreauthorizer      $preauthorization  Applies the route's exact policy before any
     *         record is observed or reserved.
     *
     * @since  2.0.0
     */
    public function __construct(
        private IdempotencyLedger $ledger,
        private ClockInterface $clock,
        private ProblemDetailsResponseFactory $problems,
        private TransactionManager $transactions,
        private HttpMutationPreauthorizer $preauthorization,
    ) {
    }

    /**
     * Reserve the key, run the mutation at most once, and keep its response for later repeats.
     *
     * The order of the steps is the security property. Authorization is applied first, before a single
     * ledger record is read, so a caller cannot learn from a replay or a 409 that an operation it may not
     * perform is already under way. The reservation is then a single ledger claim, so the store decides
     * between simultaneous first attempts rather than a read-then-write that could interleave; only the
     * loser walks the slower replay-or-takeover path, and when that path yields a response the handler is
     * never called. The handler and the write that marks the record `completed` share one transaction, so
     * a stored replay can only exist for a mutation that committed. Both failure paths give the key back:
     * a 5xx is carried out of the transaction as `ServerFailureResponse` so the rollback happens first and
     * the client still receives the handler's own response, while any other fault deletes the reservation
     * without asserting ownership — a lost lease must not mask the original exception — and re-throws.
     *
     * @param   ServerRequestInterface   $request  Mutation carrying the validated `Idempotency-Key`, the
     *          authenticated principal and the execution context bound to it, all attached upstream.
     * @param   RequestHandlerInterface  $handler  Rest of the pipeline, which performs the mutation.
     *
     * @return  ResponseInterface  The handler's response on a first run; on a repeat, either the stored
     *          response marked `Idempotency-Replayed: true`, or a problem document reporting a malformed
     *          report-export identifier, reused key, changed authorization context, or attempt still in flight.
     *
     * @throws  RuntimeException  When the request reaches this middleware without a validated key, an
     *          authenticated principal or a context bound to that principal; when the record cannot be
     *          read back; when the lease is lost before the completion write; or when a stored result
     *          fails its integrity check while being replayed.
     * @throws  \InvalidArgumentException  When the route carries no policy in `HttpMutationPreauthorizer`,
     *          a path segment is not a usable resource identifier, or the body that policy reads is not a
     *          usable JSON object.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not perform the
     *          mutation, or may not delegate a capability it would hand on — checked before the ledger is
     *          touched.
     * @throws  \Kumwe\App\Content\Application\ContentNotFound  When a workflow transition names an entry
     *          the context cannot reach.
     * @throws  \Kumwe\App\Content\Application\ContentModelNotFound  When the entry's pinned workflow
     *          version is no longer published.
     * @throws  \Kumwe\App\Workflow\Domain\InvalidWorkflowTransition  When the workflow declares no edge to
     *          the requested status.
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
            throw new RuntimeException('Persistent idempotency requires an authenticated request and validated key.');
        }

        // Replay is an authorization-sensitive read and must never precede exact use-case authorization.
        try {
            $this->preauthorization->authorize($request, $context);
        } catch (InvalidReportExportRequest) {
            return $this->problems->create(
                422,
                'Invalid business report export request',
                'The business report export identifier is invalid.',
                'urn:kumwe:problem:invalid-business-report-export',
                (string) $request->getUri(),
            )->withHeader('Cache-Control', 'no-store');
        }
        $subject = $principal->subject();
        $authorizationFingerprint = $context->authorizationFingerprint();
        $operation = strtoupper($request->getMethod()) . ' ' . $request->getUri()->getPath();
        $keyValue = (string) $key;
        $digest = $this->requestDigest($request);
        $ownerToken = Uuid::uuid7()->toString();

        if (!$this->ledger->reserve($subject, $operation, $keyValue, $digest, $authorizationFingerprint, $ownerToken)) {
            $result = $this->replayOrAcquire(
                $subject,
                $operation,
                $keyValue,
                $digest,
                $authorizationFingerprint,
                $ownerToken,
                $request,
            );
            if ($result instanceof ResponseInterface) {
                return $result;
            }
        }

        try {
            return $this->transactions->transactional(function () use (
                $handler,
                $request,
                $subject,
                $operation,
                $keyValue,
                $ownerToken,
            ): ResponseInterface {
                $response = $handler->handle($request);
                if ($response->getStatusCode() >= 500) {
                    throw new ServerFailureResponse($response);
                }
                $this->complete($subject, $operation, $keyValue, $ownerToken, $response);
                return $response;
            });
        } catch (ServerFailureResponse $failure) {
            $this->release($subject, $operation, $keyValue, $ownerToken, true);
            return $failure->response;
        } catch (Throwable $failure) {
            $this->release($subject, $operation, $keyValue, $ownerToken, false);
            throw $failure;
        }
    }

    /**
     * Answer the repeat that lost the reservation, or take the record over when it is nobody's any more.
     *
     * Reached only after the ledger refused the reservation, so a record for this subject, operation and
     * key certainly existed a moment ago. Expiry is judged before anything else: a record past its
     * retention window is dead, so it is taken over without comparing it to this request at all, and
     * losing that takeover re-reads the record — the one path that loops, three times over before the
     * caller is told to retry the request itself. A live record is instead held to both fingerprints
     * before its state is revealed — a key reused for different content is refused with 422, one
     * presented under a different credential with 409 — and only then is a completed result replayed. A
     * failed record, or an in-progress one whose lease has lapsed, is claimed by a conditional ledger
     * takeover, and losing that claim falls through to the 409 that reports an attempt still in flight.
     *
     * @param   string                  $subject                   Principal the record is keyed against.
     * @param   string                  $operation                 Method and path pair the key is scoped to.
     * @param   string                  $key                       The client's `Idempotency-Key`.
     * @param   string                  $digest                    Digest of this request, compared against
     *          the stored one and written back when a dead record is taken over.
     * @param   string                  $authorizationFingerprint  Credential and site fingerprint this
     *          request carries, compared against the stored one and re-stored on takeover.
     * @param   string                  $ownerToken                Random token this request claims the
     *          lease with.
     * @param   ServerRequestInterface  $request                   Request whose URI is quoted in any
     *          problem document produced here.
     *
     * @return  ?ResponseInterface  Null when this request now owns the reservation and must run the
     *          handler; otherwise the response to return instead of running it.
     *
     * @throws  RuntimeException  When the record cannot be read back after the collision, a column it
     *          needs is missing or malformed, or a stored result fails its integrity check.
     *
     * @since   2.0.0
     */
    private function replayOrAcquire(
        string $subject,
        string $operation,
        string $key,
        string $digest,
        string $authorizationFingerprint,
        string $ownerToken,
        ServerRequestInterface $request,
    ): ?ResponseInterface {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $row = $this->ledger->find($subject, $operation, $key);
            if ($row === null) {
                throw new RuntimeException('The idempotency record could not be loaded.');
            }

            $now = $this->clock->now();
            $id = $this->requiredString($row, 'id');
            $expiresAt = new DateTimeImmutable($this->requiredString($row, 'expires_at'));
            if ($expiresAt <= $now) {
                if ($this->ledger->takeOverExpired($id, $digest, $authorizationFingerprint, $ownerToken)) {
                    return null;
                }
                continue;
            }

            if (!hash_equals($this->requiredString($row, 'request_digest'), $digest)) {
                return $this->problems->create(
                    422,
                    'Idempotency Key Reused',
                    'This Idempotency-Key was already used for a different request.',
                    'urn:kumwe:problem:idempotency-key-reused',
                    (string) $request->getUri(),
                );
            }

            if (
                !hash_equals(
                    $this->requiredString($row, 'authorization_fingerprint'),
                    $authorizationFingerprint,
                )
            ) {
                return $this->problems->create(
                    409,
                    'Authorization Context Changed',
                    'This Idempotency-Key belongs to a different credential or authorization state.',
                    'urn:kumwe:problem:idempotency-authorization-changed',
                    (string) $request->getUri(),
                );
            }

            $state = $this->requiredString($row, 'state');
            if ($state === 'completed') {
                return $this->replay($row);
            }
            if (
                $state === 'failed' && $this->ledger->takeOverFailed(
                    $id,
                    $digest,
                    $authorizationFingerprint,
                    $ownerToken,
                )
            ) {
                return null;
            }

            $lockedUntil = $row['locked_until'] ?? null;
            if (
                $state === 'in_progress'
                && (!is_string($lockedUntil) || new DateTimeImmutable($lockedUntil) <= $now)
                && $this->ledger->takeOverStale($id, $authorizationFingerprint, $ownerToken)
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

        return $this->problems->create(
            409,
            'Operation In Progress',
            'Ownership of this idempotent operation changed concurrently; retry the request.',
            'urn:kumwe:problem:idempotency-in-progress',
            (string) $request->getUri(),
        );
    }

    /**
     * Rebuild the stored response for a repeat of a key whose operation already completed.
     *
     * The body is checked against its stored digest before any of it is sent, so a record edited in the
     * store is refused rather than replayed as though the service had produced it. The rebuilt response
     * carries `Idempotency-Replayed: true` on top of the stored headers, which is how a client tells a
     * replay from a fresh run.
     *
     * @param   array<string, mixed>  $row  Stored record, keyed by column name as the ledger returned it.
     *
     * @return  ResponseInterface  The stored status, headers and body, plus the replay marker.
     *
     * @throws  RuntimeException  When the stored body or its digest is missing, the two disagree, the
     *          stored headers are not a JSON object of non-empty names to strings, or the stored status is
     *          not a number.
     *
     * @since   2.0.0
     */
    private function replay(array $row): ResponseInterface
    {
        $body = $this->storedString($row, 'result_body');
        if (!hash_equals($this->requiredString($row, 'result_body_digest'), hash('sha256', $body))) {
            throw new RuntimeException('The stored idempotency response body failed its integrity check.');
        }
        $headers = $this->headers($row['result_headers'] ?? null);
        $headers['Idempotency-Replayed'] = 'true';
        $response = (new Response())->withStatus($this->integer($row, 'result_status'));
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        $response->getBody()->write($body);
        return $response;
    }

    /**
     * Settle the record as completed and store the response future repeats will be answered with.
     *
     * Runs inside the transaction that wraps the handler, so the mutation and the record marking its key
     * spent become durable together. The ledger re-states the whole claim in its conditional write, and a
     * refused completion is raised here as a thrown failure rather than a silent no-op, which rolls the
     * mutation back instead of leaving an effect no replay describes. Only the four headers a replay must
     * reproduce are kept; the rest are regenerated when the stored response is rebuilt.
     *
     * @param   string             $subject     Principal the record is keyed against.
     * @param   string             $operation   Method and path pair the key is scoped to.
     * @param   string             $key         The client's `Idempotency-Key`.
     * @param   string             $ownerToken  Token this request stored when it claimed the reservation.
     * @param   ResponseInterface  $response    Response to store and replay; its body is read in full.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the reservation is no longer this request's to settle, because the
     *          lease lapsed or another attempt took the record over.
     *
     * @since   2.0.0
     */
    private function complete(
        string $subject,
        string $operation,
        string $key,
        string $ownerToken,
        ResponseInterface $response,
    ): void {
        $body = (string) $response->getBody();
        $headers = [];
        foreach (['Content-Type', 'Cache-Control', 'ETag', 'Location'] as $name) {
            if ($response->hasHeader($name)) {
                $headers[$name] = $response->getHeaderLine($name);
            }
        }
        if (
            !$this->ledger->complete(
                $subject,
                $operation,
                $key,
                $ownerToken,
                $response->getStatusCode(),
                $body,
                $headers,
            )
        ) {
            throw new RuntimeException('The request no longer owns the active idempotency lease.');
        }
    }

    /**
     * Give the key back after an attempt that did not settle.
     *
     * The record is deleted rather than marked failed, so the next request presenting the key starts from
     * nothing: an operation whose transaction rolled back left no effect worth replaying. The ledger
     * deletes only a record this request still owns, so one another attempt has since taken over, or one
     * that already completed, is untouched. Ownership is only asserted on the path that still returns a
     * response — while unwinding from a fault the delete is best-effort, because a lost lease must not
     * replace the exception the caller is about to see.
     *
     * @param   string  $subject      Principal the record is keyed against.
     * @param   string  $operation    Method and path pair the key is scoped to.
     * @param   string  $key          The client's `Idempotency-Key`.
     * @param   string  $ownerToken   Token this request stored when it claimed the reservation.
     * @param   bool    $assertOwner  Whether failing to delete the record should be raised: true after a
     *          5xx, false while a thrown fault is propagating.
     *
     * @return  void
     *
     * @throws  RuntimeException  When ownership is asserted and the record was no longer this request's
     *          to delete.
     *
     * @since   2.0.0
     */
    private function release(
        string $subject,
        string $operation,
        string $key,
        string $ownerToken,
        bool $assertOwner,
    ): void {
        $released = $this->ledger->release($subject, $operation, $key, $ownerToken);
        if ($assertOwner && !$released) {
            throw new RuntimeException('The request no longer owns the active idempotency lease.');
        }
    }

    /**
     * Fingerprint the parts of a request that must not change between attempts at one key.
     *
     * Method, path, query, content type, precondition and body are hashed together, so a client that
     * reuses a key for different content is caught rather than handed the earlier result. Everything else
     * is excluded deliberately: a retry through another proxy, or with a fresh trace header, is the same
     * request and must still match.
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
     * Read a stored JSON column back as an object, whatever form the ledger handed it over in.
     *
     * Stores differ on JSON columns — some decode them, some return the text — so both are accepted and
     * only text is parsed. A JSON list is refused rather than tolerated, because the one column this backs
     * is the stored header map, and accepting a list would rebuild a replay with numeric header names.
     *
     * @param   mixed  $stored  Value of a JSON column as the ledger returned it: already decoded, or text.
     *
     * @return  array<string, mixed>  The decoded object, keyed by member name.
     *
     * @throws  RuntimeException  When the text is not valid JSON, or the value is not a JSON object.
     *
     * @since   2.0.0
     */
    private function jsonObject(mixed $stored): array
    {
        try {
            $decoded = is_string($stored) ? json_decode($stored, true, 64, JSON_THROW_ON_ERROR) : $stored;
        } catch (JsonException $exception) {
            throw new RuntimeException('An idempotency result contains invalid JSON.', 0, $exception);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('An idempotency result must contain a JSON object.');
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Read the stored header map back in a form that can be set on a response.
     *
     * Every entry is proved to be a non-empty name against a string value before any of them is applied,
     * so a corrupt record is refused outright instead of half-populating a replayed response.
     *
     * @param   mixed  $stored  Value of the `result_headers` column as the ledger returned it.
     *
     * @return  array<non-empty-string, string>  Header names to their single stored line.
     *
     * @throws  RuntimeException  When the value is not a JSON object, or an entry has a blank name or a
     *          value that is not a string.
     *
     * @since   2.0.0
     */
    private function headers(mixed $stored): array
    {
        $headers = $this->jsonObject($stored);
        foreach ($headers as $name => $value) {
            if ($name === '' || !is_string($value)) {
                throw new RuntimeException('Stored idempotency response headers must contain strings.');
            }
        }
        /** @var array<non-empty-string, string> $headers */
        return $headers;
    }

    /**
     * Read a ledger column that has to carry text for the record to be usable.
     *
     * Blank counts as missing here, which is why identifiers, states, digests and timestamps go through
     * this one rather than `storedString()`: an empty digest compared with `hash_equals()` would quietly
     * weaken the check it exists to make.
     *
     * @param   array<string, mixed>  $row    Stored record, keyed by column name.
     * @param   string                $field  Column to read, named in the failure message.
     *
     * @return  string  The stored value, guaranteed non-empty.
     *
     * @throws  RuntimeException  When the column is absent, is not a string, or is empty.
     *
     * @since   2.0.0
     */
    private function requiredString(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Idempotency field %s is invalid.', $field));
        }
        return $value;
    }

    /**
     * Read a ledger column that must be text but may legitimately be empty.
     *
     * This is the reading used for `result_body`, where a stored empty body is a real response — a 204,
     * for instance — and must be replayed as such rather than rejected the way `requiredString()` would.
     *
     * @param   array<string, mixed>  $row    Stored record, keyed by column name.
     * @param   string                $field  Column to read, named in the failure message.
     *
     * @return  string  The stored value, possibly the empty string.
     *
     * @throws  RuntimeException  When the column is absent or is not a string.
     *
     * @since   2.0.0
     */
    private function storedString(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException(sprintf('Idempotency field %s is invalid.', $field));
        }
        return $value;
    }

    /**
     * Read a stored numeric column back as an integer, whatever spelling the ledger used.
     *
     * A stored status arrives as an int from one store and as a decimal string from another, so both are
     * accepted. Anything else is corrupt storage rather than a client mistake, and is refused instead of
     * being cast into a status code that would then be replayed as though it had been served.
     *
     * @param   array<string, mixed>  $row    Stored record, keyed by column name.
     * @param   string                $field  Column to read, named in the failure message.
     *
     * @return  int  The stored number, for `result_status` the HTTP status to replay.
     *
     * @throws  RuntimeException  When the column is neither an integer nor a string of decimal digits.
     *
     * @since   2.0.0
     */
    private function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException(sprintf('Idempotency field %s is not an integer.', $field));
        }
        return (int) $value;
    }
}
