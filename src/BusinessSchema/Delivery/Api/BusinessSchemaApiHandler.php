<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Delivery\Api;

use InvalidArgumentException;
use Kumwe\App\Application\Security\HighImpactCredentialGuard;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\App\BusinessSchema\Domain\SchemaPlan;
use Kumwe\App\Delivery\Http\Api\ApiExecutionContext;
use Kumwe\App\Delivery\Http\Api\Business\BusinessApiResponder;
use Kumwe\App\Delivery\Http\Api\Content\ContentApiRequest;
use Kumwe\Context\Value\ExecutionContext;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * The REST surface for schema-plan inspection, approval and execution.
 *
 * The administrator screens, the CLI and MCP all drive BusinessSchemaService, so the same
 * checksum binding, high-impact step-up and recovery-evidence rules apply here without
 * being restated. This adapter never issues DDL and never composes a plan itself.
 *
 * One instance serves every `/api/v1/business-schema-*` route, dispatching on the request method and the
 * trailing path segment, so the stages stay separately routed and separately grantable while the reading
 * of a body, the step-up prompt, and the failure vocabulary are written once.
 *
 * @since  2.0.0
 */
final readonly class BusinessSchemaApiHandler implements RequestHandlerInterface
{
    /**
     * Wire the REST surface to the service that owns the rules and the collaborators that shape a reply.
     *
     * @param  BusinessSchemaService       $schema       Authorizes, composes, approves, and runs plans.
     * @param  BusinessSchemaApiPresenter  $presenter    Renders plans, steps, and outcomes as documents.
     * @param  BusinessApiResponder        $responder    Maps a failure onto its RFC 9457 problem document.
     * @param  HighImpactCredentialGuard   $credentials  Re-proves the caller's password before a high-impact stage.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessSchemaService $schema,
        private BusinessSchemaApiPresenter $presenter,
        private BusinessApiResponder $responder,
        private HighImpactCredentialGuard $credentials,
    ) {
    }

    /**
     * Dispatch one request on its method and path, and answer any failure as a problem document.
     *
     * The catch is deliberately total: every rejection this surface produces — a denied capability, an
     * unknown plan, a checksum that moved, a malformed body — is an expected outcome that the responder maps
     * onto RFC 9457, and it rethrows whatever it does not recognise so a genuine fault still surfaces as a
     * fault. A method and action pair no branch claims is rejected outright, so an unsupported request can
     * never fall through into a neighbouring stage.
     *
     * @param   ServerRequestInterface  $request  API request the authentication and authorization middleware
     *          have already accepted, carrying the `planId` attribute on the routes that name a plan.
     *
     * @return  ResponseInterface  The requested document, a 201 carrying a newly composed plan, or the
     *          problem document the failure maps to.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $context = ApiExecutionContext::fromRequest($request);
            $method = strtoupper($request->getMethod());
            $planId = $this->attribute($request, 'planId');
            $action = $this->action($request, $planId);

            if ($planId === null) {
                return match ([$method, $action]) {
                    ['GET', null] => $this->json(['items' => array_map(
                        $this->presenter->plan(...),
                        $this->schema->plans($context),
                    )]),
                    ['GET', 'definitions'] => $this->json(['items' => $this->schema->definitions($context)]),
                    ['POST', null] => $this->created($this->schema->createPlan(
                        $context,
                        $this->definitionId($request),
                    )),
                    ['POST', 'purge'] => $this->purge($request, $context),
                    default => throw new InvalidArgumentException('The requested plan operation is not supported.'),
                };
            }

            return match ([$method, $action]) {
                ['GET', null] => $this->json($this->planDocument($context, $planId)),
                ['POST', 'approve'] => $this->approve($request, $context, $planId),
                ['POST', 'execute'] => $this->json($this->presenter->outcome(
                    $this->schema->execute($context, $planId),
                )),
                ['POST', 'recover'] => $this->json($this->presenter->outcome(
                    $this->schema->recover($context, $planId),
                )),
                default => throw new InvalidArgumentException('The requested plan operation is not supported.'),
            };
        } catch (Throwable $exception) {
            return $this->responder->problem($exception, (string) $request->getUri());
        }
    }

    /**
     * Assemble the read document for one plan: its stored fields and the steps journalled beneath it.
     *
     * @param   ExecutionContext  $context  Actor and site the read is authorized against.
     * @param   string            $planId   Plan identifier captured from the route.
     *
     * @return  array<string, mixed>  The presented plan with a `steps` list appended — one entry per planned
     *          operation, in journal order, each carrying the state that step has reached.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not read plans.
     * @throws  \Kumwe\App\BusinessSchema\Application\BusinessSchemaNotFound  When no plan with that identifier
     *          belongs to the context's site.
     *
     * @since   2.0.0
     */
    private function planDocument(ExecutionContext $context, string $planId): array
    {
        $plan = $this->schema->plan($context, $planId);

        return [
            ...$this->presenter->plan($plan),
            'steps' => array_map($this->presenter->step(...), $this->schema->steps($context, $planId)),
        ];
    }

    /**
     * Compose a destructive purge plan for a definition, after a current-password step-up.
     *
     * @param   ServerRequestInterface  $request  API request whose JSON body carries `definition_id` and
     *          `current_password`.
     * @param   ExecutionContext        $context  Actor and site the plan is composed for.
     *
     * @return  ResponseInterface  A 201 carrying the composed plan; nothing is dropped until it is separately
     *          approved and executed.
     *
     * @throws  InvalidArgumentException  When the body is not a JSON object, or omits `current_password` or
     *          `definition_id`.
     * @throws  \Kumwe\App\Application\Security\HighImpactAuthenticationRequired  When the step-up fails.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not act
     *          destructively on schemas.
     * @throws  \Kumwe\App\BusinessSchema\Application\BusinessSchemaNotFound  When the definition is not
     *          installed on this site, or has no published version.
     *
     * @since   2.0.0
     */
    private function purge(ServerRequestInterface $request, ExecutionContext $context): ResponseInterface
    {
        // Composing a destructive plan is itself high impact: it names the tables a later
        // approval may drop, so it re-proves the caller's credential exactly as the
        // administrator screen does.
        $this->assertCurrentCredential($request, $context, 'business.schema.purge-plan');

        return $this->created($this->schema->createPurgePlan($context, $this->definitionId($request)));
    }

    /**
     * Approve a plan against the checksum the caller inspected, stepping up when it is high impact.
     *
     * Supplying `confirmation` is what marks the request as a high-impact approval: it triggers the
     * current-password check here, and the application layer then requires it to equal the plan's current
     * checksum. `recovery_evidence_id` names the tested restore drill a plan that destroys data must cite.
     * Both are optional to this adapter and judged by the application layer, which refuses a low-risk
     * approval that carries either of them.
     *
     * @param   ServerRequestInterface  $request  API request whose JSON body carries `expected_checksum` and,
     *          when the plan's risk demands them, `confirmation`, `recovery_evidence_id`, `current_password`.
     * @param   ExecutionContext        $context  Actor and site the approval is recorded for.
     * @param   string                  $planId   Plan identifier captured from the route.
     *
     * @return  ResponseInterface  The approved plan as a JSON document, under the same checksum, since
     *          approval records who accepted the plan rather than changing it.
     *
     * @throws  InvalidArgumentException  When the body is not a JSON object, `expected_checksum` is missing, or
     *          `confirmation` or `recovery_evidence_id` is supplied as something other than a string.
     * @throws  \Kumwe\App\Application\Security\HighImpactAuthenticationRequired  When a confirmation is
     *          supplied and the step-up fails.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not approve, or may
     *          not approve a destructive plan.
     * @throws  \Kumwe\App\BusinessSchema\Application\BusinessSchemaNotFound  When the plan, or the recovery
     *          evidence it cites, does not belong to the site.
     * @throws  \Kumwe\App\BusinessSchema\Application\BusinessSchemaConflict  When the plan changed after it was
     *          inspected, or the confirmation and recovery-evidence rules for its risk are not satisfied.
     *
     * @since   2.0.0
     */
    private function approve(
        ServerRequestInterface $request,
        ExecutionContext $context,
        string $planId,
    ): ResponseInterface {
        $body = ContentApiRequest::json($request);
        $expected = ContentApiRequest::requiredString($body, 'expected_checksum');
        $confirmation = $body['confirmation'] ?? null;
        $evidence = $body['recovery_evidence_id'] ?? null;
        if ($confirmation !== null && !is_string($confirmation)) {
            throw new InvalidArgumentException('The approval confirmation must be a string when supplied.');
        }
        if ($evidence !== null && !is_string($evidence)) {
            throw new InvalidArgumentException('The recovery evidence ID must be a string when supplied.');
        }
        if ($confirmation !== null) {
            $this->assertCurrentCredential($request, $context, 'business.schema.approve');
        }

        return $this->json($this->presenter->plan(
            $this->schema->approve($context, $planId, $expected, $confirmation, $evidence),
        ));
    }

    /**
     * Require a current-password step-up before a high-impact stage proceeds.
     *
     * An API token is a long-lived credential that can be replayed by whoever holds it, so the two stages
     * that can lead to installed data being dropped read `current_password` from the same JSON body and put
     * it through the shared guard rather than comparing anything here. Nothing about the credential is
     * echoed back; the caller learns only that the stage was refused.
     *
     * @param   ServerRequestInterface  $request  API request whose JSON body carries `current_password`.
     * @param   ExecutionContext        $context  Actor whose stored credential the guard checks.
     * @param   string                  $purpose  Stage the step-up is attributed to, such as
     *          `business.schema.approve`.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the body is not a JSON object or carries no non-empty
     *          `current_password`.
     * @throws  \Kumwe\App\Application\Security\HighImpactAuthenticationRequired  When the guard refuses the
     *          request, which includes a context that carries no human principal.
     *
     * @since   2.0.0
     */
    private function assertCurrentCredential(
        ServerRequestInterface $request,
        ExecutionContext $context,
        string $purpose,
    ): void {
        $body = ContentApiRequest::json($request);
        $password = $body['current_password'] ?? null;
        if (!is_string($password) || $password === '') {
            throw new InvalidArgumentException('This operation requires the caller\'s current password.');
        }
        $this->credentials->assertCurrentPassword($context, $password, $purpose);
    }

    /**
     * Read the business definition a plan is being composed for out of the request body.
     *
     * @param   ServerRequestInterface  $request  API request whose JSON body carries `definition_id`.
     *
     * @return  string  The trimmed definition identifier, guaranteed non-empty.
     *
     * @throws  InvalidArgumentException  When the body is not a JSON object, or `definition_id` is absent or
     *          not a non-empty string.
     *
     * @since   2.0.0
     */
    private function definitionId(ServerRequestInterface $request): string
    {
        return ContentApiRequest::requiredString(ContentApiRequest::json($request), 'definition_id');
    }

    /**
     * Answer a freshly composed plan with the entity tag a client can hold on to.
     *
     * The tag is the plan's canonical checksum rather than a storage revision, so the value the client
     * caches is the same value it must send back as `expected_checksum` when it approves.
     *
     * @param   SchemaPlan  $plan  Plan the planner has just persisted.
     *
     * @return  ResponseInterface  A 201 carrying the presented plan and a strong `ETag` of its checksum.
     *
     * @since   2.0.0
     */
    private function created(SchemaPlan $plan): ResponseInterface
    {
        return $this->json($this->presenter->plan($plan), 201, ['ETag' => '"' . $plan->checksum() . '"']);
    }

    /**
     * Read a route attribute, collapsing absent, non-string, and empty values into one answer.
     *
     * @param   ServerRequestInterface  $request  Request the routing middleware has already matched.
     * @param   string                  $name     Attribute the matched route declares, such as `planId`.
     *
     * @return  ?string  The attribute value, or null when this route declares no such segment; the caller
     *          reads that null as "a collection route" rather than as a malformed request.
     *
     * @since   2.0.0
     */
    private function attribute(ServerRequestInterface $request, string $name): ?string
    {
        $value = $request->getAttribute($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Read the action segment of the request path.
     *
     * Approve, execute and recover are independently grantable, so each has its own literal
     * route and capability; the action is read back from the path rather than routed as a
     * placeholder that would blur those grants together.
     *
     * On a plan route the action is whatever follows the plan identifier, which is located by value so a
     * longer mount path does not shift the offset; on a collection route it is the fourth segment.
     *
     * @param   ServerRequestInterface  $request  Request whose path is being classified.
     * @param   ?string                 $planId   Plan identifier already read from the route, or null when the
     *          matched route names no plan.
     *
     * @return  ?string  The action segment, or null when the path stops at the collection or at the plan.
     *
     * @since   2.0.0
     */
    private function action(ServerRequestInterface $request, ?string $planId): ?string
    {
        $segments = explode('/', trim($request->getUri()->getPath(), '/'));
        if ($planId === null) {
            $tail = $segments[3] ?? null;

            return $tail === '' ? null : $tail;
        }
        $position = array_search($planId, $segments, true);
        if ($position === false) {
            return null;
        }

        return $segments[$position + 1] ?? null;
    }

    /**
     * Serialize a document as the JSON response every successful branch returns.
     *
     * @param   array<string, mixed>             $document  Body to encode.
     * @param   int                              $status    Status code to answer with.
     * @param   array<non-empty-string, string>  $headers   Extra headers, applied after the caching default.
     *
     * @return  ResponseInterface  A JSON response marked `Cache-Control: no-store`, because a plan's status
     *          and steps move underneath a client that stores them.
     *
     * @since   2.0.0
     */
    private function json(array $document, int $status = 200, array $headers = []): ResponseInterface
    {
        return new JsonResponse($document, $status, ['Cache-Control' => 'no-store', ...$headers]);
    }
}
