<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Http\Api\Idempotency;

use InvalidArgumentException;
use JsonException;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\BusinessReporting\Domain\ReportDefinitionGuard;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Content\Application\ContentModelRepository;
use Kumwe\App\Identity\Application\Administration\AccessControlRepository;
use Kumwe\App\Identity\Application\Administration\TokenDelegationPreauthorizer;
use Kumwe\App\Identity\Application\Administration\TokenRotationPreauthorizer;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Identity\Domain\GrantScope;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Performs exact application-policy checks before an idempotency record can be observed or reserved.
 *
 * A stored idempotency record is itself authorization-sensitive: replaying one tells the caller that the
 * operation already succeeded, and reserving a key spends it. Both idempotency middlewares therefore
 * call this before they touch the ledger, so nobody learns about — or interferes with — a mutation they
 * may not perform. What lives here is the policy the route-level capability guard cannot express: method
 * and path are matched to the exact capability and the exact resource the mutation targets, down to the
 * content entry, contributed report, menu item, role and grant, with token issuance and rotation delegated
 * to the preauthorizers that own those rules. The closing line is the point of the class — an unrecognised
 * route is refused, so mounting an idempotent endpoint without writing its policy here fails loudly
 * rather than running unguarded.
 *
 * @since  2.0.0
 */
final readonly class HttpMutationPreauthorizer
{
    /**
     * Wire the checker to the gateway and to the services that resolve a route's real target.
     *
     * @param  AuthorizationGateway          $authorization    Gateway every capability and delegation check
     *         is put to.
     * @param  ContentService                $content          Resolves which capability a requested workflow
     *         transition actually demands.
     * @param  AccessControlRepository       $access           Reads the grants a role carries and the role a
     *         grant belongs to.
     * @param  TokenDelegationPreauthorizer  $tokenDelegation  Owns the policy for minting a token on another
     *         subject's behalf.
     * @param  TokenRotationPreauthorizer    $tokenRotation    Owns the policy for replacing a live token.
     * @param  ?ContentModelRepository       $models           Resolves a content type or workflow handle to
     *         its stored id; when null, or when nothing matches, the path segment is authorized as the
     *         resource identifier instead.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AuthorizationGateway $authorization,
        private ContentService $content,
        private AccessControlRepository $access,
        private TokenDelegationPreauthorizer $tokenDelegation,
        private TokenRotationPreauthorizer $tokenRotation,
        private ?ContentModelRepository $models = null,
    ) {
    }

    /**
     * Apply the exact authorization policy this request's method and path call for.
     *
     * Matching is by whole path shape rather than by prefix, and the fall-through at the end throws, so a
     * route this class does not recognise is never allowed by default. Several branches assert more than
     * once, because reaching a resource is not authority over what it confers: assigning a role also
     * proves the actor may delegate every grant that role carries, and deleting a grant also proves the
     * actor may manage the role behind it. Branches whose policy depends on the payload decode the body
     * here, which means a malformed body is refused before any ledger row is read or written.
     *
     * @param   ServerRequestInterface  $request  Mutation being authorized; its method, path and — on the
     *          transition, role-grant and token-issuance routes — its JSON body select and feed the policy.
     * @param   ExecutionContext        $context  Actor, site and provenance every check is evaluated for.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the route carries no policy, the method and path are not a
     *          supported content mutation, a report or other path segment is not a usable resource identifier,
     *          the body is not a JSON object, a required body field is missing or blank, or a named grant is gone.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not perform the
     *          mutation, or may not delegate a capability the request would hand on.
     * @throws  \Kumwe\App\Content\Application\ContentNotFound  When a transition names an entry the context
     *          cannot reach.
     * @throws  \Kumwe\App\Content\Application\ContentModelNotFound  When the entry's pinned workflow version
     *          is no longer published.
     * @throws  \Kumwe\App\Workflow\Domain\InvalidWorkflowTransition  When the workflow declares no edge to
     *          the requested status.
     *
     * @since   2.0.0
     */
    public function authorize(ServerRequestInterface $request, ExecutionContext $context): void
    {
        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();

        if ($method === 'POST' && $path === '/api/v1/content') {
            $this->assert($context, 'content.create', AuthorizationResource::collection('content'));
            return;
        }
        if (
            $method === 'POST'
            && preg_match('#^/api/v1/business/reports/([^/]+)/exports$#D', $path, $match) === 1
        ) {
            $report = rawurldecode($match[1]);
            try {
                ReportDefinitionGuard::identifier($report, 'identifier');
            } catch (InvalidArgumentException $exception) {
                throw new InvalidReportExportRequest(
                    'The business report export identifier is invalid.',
                    0,
                    $exception,
                );
            }
            $this->assert(
                $context,
                'business.record.export',
                AuthorizationResource::item('business_report', $report),
            );
            return;
        }
        if (preg_match('#^/api/v1/content/([^/]+)(?:/(transition|restore))?$#D', $path, $match) === 1) {
            $id = rawurldecode($match[1]);
            $suffix = $match[2] ?? '';
            $action = match (true) {
                $method === 'PATCH' && $suffix === '' => 'content.update',
                $method === 'DELETE' && $suffix === '' => 'content.delete',
                $method === 'POST' && $suffix === 'restore' => 'content.restore',
                $method === 'POST' && $suffix === 'transition' => $this->transitionAction($request, $context, $id),
                default => throw new InvalidArgumentException('Unsupported idempotent content mutation.'),
            };
            $this->assert($context, $action, AuthorizationResource::item('content', $id));
            return;
        }
        if ($method === 'POST' && $path === '/api/v1/menus') {
            $this->assert($context, 'navigation.manage', AuthorizationResource::collection('menu'));
            return;
        }
        if (preg_match('#^/api/v1/menus/([^/]+)$#D', $path, $match) === 1) {
            $this->assert($context, 'navigation.manage', AuthorizationResource::item('menu', rawurldecode($match[1])));
            return;
        }
        if (preg_match('#^/api/v1/menus/([^/]+)/items$#D', $path, $match) === 1) {
            $this->assert($context, 'navigation.manage', AuthorizationResource::item('menu', rawurldecode($match[1])));
            return;
        }
        if (preg_match('#^/api/v1/menu-items/([^/]+)$#D', $path, $match) === 1) {
            $this->assert(
                $context,
                'navigation.manage',
                AuthorizationResource::item('menu_item', rawurldecode($match[1])),
            );
            return;
        }
        if ($method === 'PUT' && $path === '/api/v1/settings') {
            $this->assert(
                $context,
                'settings.manage',
                AuthorizationResource::item('site', $context->site()->identifier()),
            );
            return;
        }
        if (preg_match('#^/api/v1/extensions/([^/]+)/([^/]+)(?:/(?:activate|disable))?$#D', $path, $match) === 1) {
            $this->assert(
                $context,
                'extensions.manage',
                AuthorizationResource::item('extension', rawurldecode($match[1] . '/' . $match[2])),
            );
            return;
        }
        if ($method === 'POST' && $path === '/api/v1/extension-trust-keys') {
            $this->assert(
                $context,
                'extensions.manage',
                AuthorizationResource::collection('extension_trust_key'),
            );
            return;
        }
        if (
            preg_match('#^/api/v1/extension-trust-keys/([^/]+)(?:/rotate)?$#D', $path, $match) === 1
        ) {
            $this->assert(
                $context,
                'extensions.manage',
                AuthorizationResource::item('extension_trust_key', rawurldecode($match[1])),
            );
            return;
        }
        if ($method === 'POST' && $path === '/api/v1/users') {
            $this->assert($context, 'users.manage', AuthorizationResource::collection('user'));
            return;
        }
        if (preg_match('#^/api/v1/users/([^/]+)(?:/roles/([^/]+))?$#D', $path, $match) === 1) {
            $this->assert($context, 'users.manage', AuthorizationResource::item('user', rawurldecode($match[1])));
            if (isset($match[2])) {
                $roleId = rawurldecode($match[2]);
                $this->assert($context, 'users.manage', AuthorizationResource::item('role', $roleId));
                if ($method === 'PUT') {
                    foreach ($this->access->roleGrants($roleId) as $grant) {
                        $this->authorization->assertCanDelegate(
                            $context,
                            Capability::fromString($grant['capability']),
                            $grant['scope_type'] === 'global'
                                ? GrantScope::global()
                                : GrantScope::named(
                                    $grant['scope_type'],
                                    $grant['scope_identifier'] ?? '',
                                ),
                        );
                    }
                }
            }
            return;
        }
        if ($method === 'POST' && $path === '/api/v1/roles') {
            $this->assert($context, 'users.manage', AuthorizationResource::collection('role'));
            return;
        }
        if (preg_match('#^/api/v1/roles/([^/]+)/grants$#D', $path, $match) === 1) {
            $this->assert($context, 'users.manage', AuthorizationResource::item('role', rawurldecode($match[1])));
            $input = $this->jsonObject($request);
            $capability = $this->requiredString($input, 'capability');
            $scopeType = strtolower(isset($input['scope_type'])
                ? $this->requiredString($input, 'scope_type')
                : 'global');
            $scopeIdentifier = $input['scope_identifier'] ?? null;
            if ($scopeIdentifier !== null && !is_string($scopeIdentifier)) {
                throw new InvalidArgumentException('The scope_identifier field must be a string or null.');
            }
            $scopeIdentifier = $scopeIdentifier === null ? null : trim($scopeIdentifier);
            $scope = $scopeType === 'global'
                ? GrantScope::global()
                : GrantScope::named($scopeType, $scopeIdentifier ?? '');
            $this->authorization->assertCanDelegate($context, Capability::fromString($capability), $scope);
            return;
        }
        if (preg_match('#^/api/v1/grants/([^/]+)$#D', $path, $match) === 1) {
            $grantId = rawurldecode($match[1]);
            $this->assert($context, 'users.manage', AuthorizationResource::item('grant', $grantId));
            $grant = $this->access->grantRecord($grantId)
                ?? throw new InvalidArgumentException('The capability grant does not exist.');
            $this->assert($context, 'users.manage', AuthorizationResource::item('role', $grant['role_id']));
            return;
        }
        if ($method === 'POST' && $path === '/api/v1/tokens') {
            $input = $this->jsonObject($request);
            $capabilities = $input['capabilities'] ?? null;
            if (!is_array($capabilities)) {
                throw new InvalidArgumentException('The capabilities field must be an array.');
            }
            $this->tokenDelegation->authorize(
                $context,
                $this->requiredString($input, 'email'),
                $capabilities,
            );
            return;
        }
        if (preg_match('#^/api/v1/tokens/([^/]+)$#D', $path, $match) === 1) {
            $this->assert($context, 'users.manage', AuthorizationResource::item('api_token', rawurldecode($match[1])));
            return;
        }
        if ($method === 'POST' && preg_match('#^/api/v1/tokens/([^/]+)/rotate$#D', $path, $match) === 1) {
            $this->tokenRotation->authorize($context, rawurldecode($match[1]));
            return;
        }
        if ($method === 'DELETE' && preg_match('#^/api/v1/users/([^/]+)/tokens$#D', $path) === 1) {
            $this->assert(
                $context,
                'users.manage',
                AuthorizationResource::item('site', $context->site()->identifier()),
            );
            return;
        }
        if (
            $method === 'DELETE'
            && preg_match('#^/api/v1/users/([^/]+)/tokens/emergency$#D', $path, $match) === 1
        ) {
            $this->assert($context, 'users.manage', AuthorizationResource::item('user', rawurldecode($match[1])));
            return;
        }
        if ($method === 'POST' && $path === '/api/v1/schedules') {
            $this->assert($context, 'automation.manage', AuthorizationResource::collection('schedule'));
            return;
        }
        if (preg_match('#^/api/v1/schedules/([^/]+)$#D', $path, $match) === 1) {
            $this->assert(
                $context,
                'automation.manage',
                AuthorizationResource::item('schedule', rawurldecode($match[1])),
            );
            return;
        }
        if (preg_match('#^/api/v1/jobs/([^/]+)/(?:retry|cancel)$#D', $path, $match) === 1) {
            $this->assert($context, 'automation.manage', AuthorizationResource::item('job', rawurldecode($match[1])));
            return;
        }
        if ($method === 'POST' && in_array($path, ['/api/v1/content-types', '/api/v1/workflows'], true)) {
            $type = $path === '/api/v1/content-types' ? 'content_type' : 'workflow';
            $this->assert($context, 'content.update', AuthorizationResource::collection($type));
            return;
        }
        if ($method === 'PATCH' && preg_match('#^/api/v1/(content-types|workflows)/([^/]+)$#D', $path, $match) === 1) {
            $type = $match[1] === 'content-types' ? 'content_type' : 'workflow';
            $identifier = rawurldecode($match[2]);
            $definition = $this->models === null
                ? null
                : ($type === 'content_type'
                    ? $this->models->contentType($context->site(), $identifier)
                    : $this->models->workflow($context->site(), $identifier));
            $resourceId = $definition === null ? $identifier : $definition->id;
            $this->assert($context, 'content.update', AuthorizationResource::item($type, $resourceId));
            return;
        }

        throw new InvalidArgumentException('The idempotent endpoint has no exact authorization policy.');
    }

    /**
     * Resolve the capability a content transition demands, having first proved the entry is readable.
     *
     * The read check comes first because resolving the capability loads the entry and its workflow, and an
     * actor who may not read the entry must not learn from the answer whether it exists or where it can
     * go. Being told the capability is not being granted it — the caller still asserts it afterwards.
     *
     * @param   ServerRequestInterface  $request  Transition request whose JSON body carries the target
     *          `status`.
     * @param   ExecutionContext        $context  Actor, site and provenance the transition would run under.
     * @param   string                  $id       Identifier of the content entry the transition applies to.
     *
     * @return  string  Capability code the actor must hold to make this exact move.
     *
     * @throws  InvalidArgumentException  When the body is not a JSON object or carries no non-empty
     *          `status`.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not read the
     *          entry.
     * @throws  \Kumwe\App\Content\Application\ContentNotFound  When no entry matches within reach of the
     *          context.
     * @throws  \Kumwe\App\Content\Application\ContentModelNotFound  When the entry's pinned workflow version
     *          is no longer published.
     * @throws  \Kumwe\App\Workflow\Domain\InvalidWorkflowTransition  When the workflow declares no edge to
     *          the requested status.
     *
     * @since   2.0.0
     */
    private function transitionAction(
        ServerRequestInterface $request,
        ExecutionContext $context,
        string $id,
    ): string {
        $this->assert($context, 'content.read', AuthorizationResource::item('content', $id));
        $input = $this->jsonObject($request);
        $status = $this->requiredString($input, 'status');

        return $this->content->transitionCapability($context, $id, $status)->value();
    }

    /**
     * Put one capability-and-resource pair to the gateway, throwing unless it is allowed.
     *
     * @param   ExecutionContext       $context   Actor, site and provenance the check is evaluated for.
     * @param   string                 $action    Capability code the route demands, such as `content.update`.
     * @param   AuthorizationResource  $resource  Exact collection or item the capability is demanded over.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the action is not a well-formed capability code.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses the actor that
     *          action on that resource.
     *
     * @since   2.0.0
     */
    private function assert(ExecutionContext $context, string $action, AuthorizationResource $resource): void
    {
        $this->authorization->assertAllowed($context, Capability::fromString($action), $resource);
    }

    /**
     * Decode the mutation body as the JSON object a policy branch reads its fields from.
     *
     * A JSON array is refused as firmly as malformed JSON, because every branch that reaches for the body
     * expects named fields.
     *
     * @param   ServerRequestInterface  $request  Mutation whose payload the policy decision depends on.
     *
     * @return  array<string, mixed>  The decoded object's members, keyed by field name.
     *
     * @throws  InvalidArgumentException  When the body is not valid JSON within 32 levels of nesting, or
     *          decodes to something other than a JSON object.
     *
     * @since   2.0.0
     */
    private function jsonObject(ServerRequestInterface $request): array
    {
        try {
            $input = json_decode((string) $request->getBody(), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The mutation request body is invalid JSON.', 0, $exception);
        }
        if (!is_array($input) || array_is_list($input)) {
            throw new InvalidArgumentException('The mutation request body must be a JSON object.');
        }

        /** @var array<string, mixed> $input */
        return $input;
    }

    /**
     * Read a body field that a policy branch requires as a non-empty string.
     *
     * @param   array<string, mixed>  $input  Decoded mutation body.
     * @param   string                $field  Field to read, named back to the caller in the refusal.
     *
     * @return  string  The field's value with surrounding whitespace removed.
     *
     * @throws  InvalidArgumentException  When the field is absent, is not a string, or is blank once
     *          trimmed.
     *
     * @since   2.0.0
     */
    private function requiredString(array $input, string $field): string
    {
        $value = $input[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('The %s field must be a non-empty string.', $field));
        }

        return trim($value);
    }
}
