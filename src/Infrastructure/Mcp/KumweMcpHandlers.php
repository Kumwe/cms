<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Mcp;

use Closure;
use InvalidArgumentException;
use JsonException;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Automation\AutomationManagementService;
use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Extension\Application\ExtensionManager;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\App\Identity\Application\Authentication\ScopedAccessTokenVerifier;
use Kumwe\App\Identity\Application\Authorization\InsufficientCapability;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Navigation\Application\MenuRecord;
use Kumwe\App\Navigation\Application\MenuItemRecord;
use Kumwe\App\Navigation\Application\NavigationService;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionService;
use Kumwe\App\BusinessDefinition\Application\DefinitionVersionRecord;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\App\BusinessSchema\Domain\SchemaPlan;
use Kumwe\App\BusinessSchema\Domain\SchemaPlanStep;
use Kumwe\App\Extension\Domain\ThemeSurface;
use Kumwe\App\Site\Application\SiteSettings;
use Kumwe\App\Identity\Domain\UserStatus;
use Psr\Clock\ClockInterface;

/**
 * The whole MCP surface: one guarded method for every tool, resource and prompt the catalogue advertises.
 *
 * `KumweMcpServerFactory` binds each catalogue entry to a method here by name, so this class is where a
 * tool call from an MCP client becomes a call into the same application services the REST API and the
 * administrator use — never into a repository directly. Three things hold for every entry. The caller's
 * capability is checked before any work starts; a write is additionally pre-authorized against the one
 * resource it names, so the decision is recorded before anything happens; and the write then runs inside
 * `McpMutationGuard`, so a tool call that a flaky transport retries cannot apply twice. Failures raised by
 * the services below — not found, version conflict, validation — travel outward unchanged, which is what
 * keeps an agent's refusal identical to the equivalent REST call's.
 *
 * Instances are immutable and start out unbound, refusing everything. `forContext()` binds one request's
 * actor for the HTTP transport; `forCredential()` binds a retained token for the long-lived stdio
 * transport and re-proves it on every access. A bound copy never carries both.
 *
 * @since  2.0.0
 */
final readonly class KumweMcpHandlers
{
    /**
     * Wire the application services, the catalogue, and the two guards every tool runs behind.
     *
     * The container builds one unbound instance: neither identity argument is supplied, so every tool refuses
     * until `forContext()` or `forCredential()` hands back a bound copy.
     *
     * @param  McpCapabilityCatalog         $catalog           Tools, resources and prompts this release exposes,
     *         as published by `discover()` and the capability resource.
     * @param  ContentService               $content           Content entries behind the `kumwe_content_*` tools.
     * @param  NavigationService            $navigation        Menus and menu items behind the `kumwe_menu_*` tools.
     * @param  AccessControlService         $access            Users, roles, capabilities and token metadata.
     * @param  SiteSettings                 $settings          The site settings document, read and replaced whole.
     * @param  ExtensionManager             $extensions        Extension activation, disabling and removal.
     * @param  TrustStore                   $trust             Extension signing keys, and the installation-wide
     *         lifecycle lock the trust and extension writes are taken under.
     * @param  AutomationManagementService  $automation        Schedules and jobs behind the automation tools.
     * @param  BusinessDefinitionService    $definitions       Business entity definition drafts and versions.
     * @param  BusinessSchemaService        $schema            Schema plans and their approval and execution.
     * @param  BusinessMcpHandlers          $businessRecords   Bounded generated-business MCP delegate.
     * @param  ReportMcpHandlers            $businessReports   Bounded report and export MCP delegate.
     * @param  McpMutationGuard             $mutations         Idempotency fence every write is run through.
     * @param  ClockInterface               $clock             Supplies the first-run instant a new schedule is
     *         anchored to.
     * @param  AuthorizationGateway         $authorization     Judges each write against the resource it names,
     *         before the fence is entered.
     * @param  ?ExecutionContext            $executionContext  Actor bound by `forContext()`; null while the
     *         instance is unbound.
     * @param  ?Closure                     $contextRefresh    Callback bound by `forCredential()` that
     *         re-verifies the retained token and mints a fresh context; null when no credential is retained.
     * @param  ?ExtensionExecutionGate      $extensionRuntime  Live authority for the resident extension
     *         generation; null only in isolated tests that have no extension runtime.
     *
     * @since  2.0.0
     */
    public function __construct(
        private McpCapabilityCatalog $catalog,
        private ContentService $content,
        private NavigationService $navigation,
        private AccessControlService $access,
        private SiteSettings $settings,
        private ExtensionManager $extensions,
        private TrustStore $trust,
        private AutomationManagementService $automation,
        private BusinessDefinitionService $definitions,
        private BusinessSchemaService $schema,
        private BusinessMcpHandlers $businessRecords,
        private ReportMcpHandlers $businessReports,
        private McpMutationGuard $mutations,
        private ClockInterface $clock,
        private AuthorizationGateway $authorization,
        private ?ExecutionContext $executionContext = null,
        private ?Closure $contextRefresh = null,
        private ?ExtensionExecutionGate $extensionRuntime = null,
    ) {
    }

    /**
     * Bind these handlers to one request's already-authenticated actor.
     *
     * The HTTP transport calls this per request, so every tool the session reaches runs as that request's
     * principal and is audited under it. Any retained stdio credential is dropped from the copy: a context
     * handed in here is the whole identity.
     *
     * @param   ExecutionContext  $context  Actor, site and provenance the copy's tools run under.
     *
     * @return  self  A copy carrying the same collaborators, bound to this context.
     *
     * @since   2.0.0
     */
    public function forContext(ExecutionContext $context): self
    {
        return new self(
            $this->catalog,
            $this->content,
            $this->navigation,
            $this->access,
            $this->settings,
            $this->extensions,
            $this->trust,
            $this->automation,
            $this->definitions,
            $this->schema,
            $this->businessRecords,
            $this->businessReports,
            $this->mutations,
            $this->clock,
            $this->authorization,
            $context,
            extensionRuntime: $this->extensionRuntime,
        );
    }

    /**
     * Bind a retained stdio credential that is reverified before every protected handler access.
     *
     * The stdio server outlives any single request, so the token is not resolved once at start-up: the closure
     * stored here re-runs `AccessTokenVerifier::verify()` on every context lookup, which is what makes a
     * revoked, expired or re-scoped token stop the very next tool call rather than the next process. Each
     * refresh mints a fresh random request identifier, so calls made in one long session stay apart in the
     * audit trail.
     *
     * @param   AccessTokenVerifier  $tokens          Verifier the retained token is presented to again.
     * @param   string               $token           Bearer credential the stdio session was opened with.
     * @param   string               $siteIdentifier  Site the token is presented against; normalised here, so
     *          verification and every tool agree on one spelling.
     *
     * @return  self  A copy that resolves its actor from the credential on every protected access.
     *
     * @throws  InvalidArgumentException  When the site identifier is not a usable site name.
     *
     * @since   2.0.0
     */
    public function forCredential(
        AccessTokenVerifier $tokens,
        string $token,
        string $siteIdentifier = SiteContext::DEFAULT,
    ): self {
        $site = SiteContext::fromString($siteIdentifier);
        $siteIdentifier = $site->identifier();
        $refresh = static function () use ($tokens, $token, $site, $siteIdentifier): ExecutionContext {
            $verified = $tokens instanceof ScopedAccessTokenVerifier
                ? $tokens->verifyScoped($token, 'kumwe-mcp', 'mcp', $siteIdentifier)
                : null;
            $principal = $verified !== null
                ? $verified->principal
                : (!($tokens instanceof ScopedAccessTokenVerifier)
                    ? $tokens->verify($token, 'kumwe-mcp', 'mcp', $siteIdentifier)
                    : null);
            $principal ??= throw new InsufficientCapability('authenticated');

            return $verified !== null
                ? $verified->context(
                    'mcp-stdio-' . bin2hex(random_bytes(16)),
                    AuthenticatedSurface::Mcp,
                )
                : $principal->context(
                    $site,
                    AuthenticationStrength::BearerToken,
                    'mcp-stdio-' . bin2hex(random_bytes(16)),
                    surface: AuthenticatedSurface::Mcp,
                );
        };

        return new self(
            $this->catalog,
            $this->content,
            $this->navigation,
            $this->access,
            $this->settings,
            $this->extensions,
            $this->trust,
            $this->automation,
            $this->definitions,
            $this->schema,
            $this->businessRecords,
            $this->businessReports,
            $this->mutations,
            $this->clock,
            $this->authorization,
            contextRefresh: $refresh,
            extensionRuntime: $this->extensionRuntime,
        );
    }

    /**
     * Publish everything this release exposes over MCP and the policy metadata for each tool.
     *
     * The only tool that checks no capability of its own, so a client can learn the shape of the surface it
     * may then be refused parts of. The result carries no schemas, handler internals or caller data; the
     * risk class, required capability and non-MCP alternative are public contract metadata.
     *
     * @return  array{
     *              product: string, mode: string, tools: list<string>, resources: list<string>,
     *              prompts: list<string>, tool_metadata: list<array{
     *                  name: string, capability: string|null, risk: string, alternative: string
     *              }>
     *          }  Public surface identity and per-tool policy metadata.
     *
     * @since   2.0.0
     */
    public function discover(): array
    {
        $this->principal();

        return $this->catalog->publicSummary();
    }

    /**
     * List the content entries of the caller's site that the caller may read.
     *
     * The service's default page size applies, so at most one hundred readable entries come back; this is a
     * survey tool, not an export. A short result means the store ran out, never that permission trimmed it.
     *
     * @param   bool  $includeDeleted  Whether trashed entries join the result.
     *
     * @return  array{items: list<array<string, mixed>>}  Serialised records under `items`, most recently
     *          updated first.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `content.read`.
     *
     * @since   2.0.0
     */
    public function listContent(bool $includeDeleted = false): array
    {
        $this->require('content.read');

        return ['items' => array_map(
            static fn (ContentRecord $record): array => $record->toArray(),
            $this->content->list($this->context(), includeDeleted: $includeDeleted),
        )];
    }

    /**
     * Create a draft page and return the record as stored.
     *
     * `$body` is a convenience for the ordinary single-field page: it is used only while `$data` is empty, and
     * any non-empty `$data` is stored instead of it rather than merged with it.
     *
     * @param   string                $operationId  Idempotency key this write is fenced on.
     * @param   string                $title        Human-readable title of the new entry.
     * @param   string                $slug         URL segment the entry becomes reachable at in its site.
     * @param   string                $body         Page body, used only while `$data` is empty.
     * @param   ?string               $contentType  Content type to create under, or null for the core page type.
     * @param   array<string, mixed>  $data         Field values for that type; when empty, `$body` is stored
     *          under a `body` key instead.
     *
     * @return  array<string, mixed>  The stored record, carrying the identifier and version a later update
     *          has to quote back.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `content.create`.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses `content.create` on the
     *          content collection.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function createContent(
        string $operationId,
        string $title,
        string $slug,
        string $body = '',
        ?string $contentType = null,
        array $data = [],
    ): array {
        $this->require('content.create');
        $this->preauthorize($operationId, 'content.create', AuthorizationResource::collection('content'));

        return $this->mutations->run($this->context($operationId), 'content.create', $operationId, [
            'title' => $title, 'slug' => $slug, 'body' => $body, 'content_type' => $contentType, 'data' => $data,
        ], fn (): array => $this->content->create(
            $this->context($operationId),
            $title,
            $slug,
            $data === [] ? ['body' => $body] : $data,
            contentTypeIdentifier: $contentType ?? ContentService::CORE_PAGE_TYPE_ID,
        )->toArray());
    }

    /**
     * Replace a page's title, slug and fields at an expected version.
     *
     * The version is the concurrency check: a client that read an entry, thought about it, and wrote it back
     * is refused if someone else wrote in between, instead of silently discarding that edit. As with creation,
     * `$body` applies only while `$data` is empty.
     *
     * @param   string                $operationId  Idempotency key this write is fenced on.
     * @param   string                $id           UUID of the entry to rewrite.
     * @param   int                   $version      Version the caller last read; the stored entry must still
     *          be at it.
     * @param   string                $title        Replacement title.
     * @param   string                $slug         Replacement URL segment.
     * @param   string                $body         Page body, used only while `$data` is empty.
     * @param   array<string, mixed>  $data         Replacement field values; when empty, `$body` is stored
     *          under a `body` key instead.
     *
     * @return  array<string, mixed>  The stored record with its version incremented.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `content.update`.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses `content.update` on this
     *          entry.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function updateContent(
        string $operationId,
        string $id,
        int $version,
        string $title,
        string $slug,
        string $body = '',
        array $data = [],
    ): array {
        $this->require('content.update');
        $this->preauthorize($operationId, 'content.update', AuthorizationResource::item('content', $id));

        return $this->mutations->run($this->context($operationId), 'content.update', $operationId, [
            'id' => $id, 'version' => $version, 'title' => $title, 'slug' => $slug, 'body' => $body, 'data' => $data,
        ], fn (): array => $this->content->update(
            $this->context($operationId),
            $id,
            $version,
            $title,
            $slug,
            $data === [] ? ['body' => $body] : $data,
        )->toArray());
    }

    /**
     * Move a content entry to another workflow state, under the capability that particular move demands.
     *
     * Unlike every other write here, the capability is not fixed: it is resolved from the entry's own workflow
     * first, so publishing asks for a publish capability while an installation-defined state asks for whatever
     * its edge declares. Resolving it reads the entry under `content.read` first, so an entry this caller
     * cannot see, or a move the workflow does not declare, is refused before the transition is ever
     * authorized.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $id           UUID of the entry to move.
     * @param   int     $version      Version the caller last read; the stored entry must still be at it.
     * @param   string  $status       State key to move to, spelled as the workflow in force spells it.
     *
     * @return  array<string, mixed>  The stored record in its new state, with its version incremented.
     *
     * @throws  InsufficientCapability  When no principal is bound to these handlers.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses the resolved transition
     *          capability on this entry.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function transitionContent(string $operationId, string $id, int $version, string $status): array
    {
        $target = $status;
        $this->preauthorize(
            $operationId,
            $this->content->transitionCapability($this->context($operationId), $id, $target)->value(),
            AuthorizationResource::item('content', $id),
        );

        return $this->mutations->run($this->context($operationId), 'content.transition', $operationId, [
            'id' => $id, 'version' => $version, 'status' => $status,
        ], fn (): array => $this->content->transition(
            $this->context($operationId),
            $id,
            $version,
            $target,
        )->toArray());
    }

    /**
     * Move a content entry to the trash at an expected version.
     *
     * Reversible: the row and its version line survive, `restoreContent()` brings the entry back, and until
     * then it is absent from listings that do not ask for deleted entries.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $id           UUID of the entry to trash.
     * @param   int     $version      Version the caller last read; the stored entry must still be at it.
     *
     * @return  array<string, mixed>  The stored record in its trashed state.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `content.delete`.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses `content.delete` on this
     *          entry.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function trashContent(string $operationId, string $id, int $version): array
    {
        $this->require('content.delete');
        $this->preauthorize($operationId, 'content.delete', AuthorizationResource::item('content', $id));
        return $this->mutations->run(
            $this->context($operationId),
            'content.trash',
            $operationId,
            compact('id', 'version'),
            fn (): array => $this->content->trash($this->context($operationId), $id, $version)->toArray()
        );
    }

    /**
     * Bring a trashed content entry back into the live listing at an expected version.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $id           UUID of the trashed entry to restore.
     * @param   int     $version      Version the caller last read; the stored entry must still be at it.
     *
     * @return  array<string, mixed>  The stored record in the state it is restored to.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `content.restore`.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses `content.restore` on this
     *          entry.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function restoreContent(string $operationId, string $id, int $version): array
    {
        $this->require('content.restore');
        $this->preauthorize($operationId, 'content.restore', AuthorizationResource::item('content', $id));
        return $this->mutations->run(
            $this->context($operationId),
            'content.restore',
            $operationId,
            compact('id', 'version'),
            fn (): array => $this->content->restore($this->context($operationId), $id, $version)->toArray()
        );
    }

    /**
     * List the navigation menus of the caller's site.
     *
     * @return  array{items: list<array<string, mixed>>}  Serialised menus under `items`.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `navigation.manage`.
     *
     * @since   2.0.0
     */
    public function listMenus(): array
    {
        $this->require('navigation.manage');

        return ['items' => array_map(
            static fn (MenuRecord $menu): array => $menu->toArray(),
            $this->navigation->menus($this->context()),
        )];
    }

    /**
     * Create an empty navigation menu.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $handle       Stable machine handle that templates and settings refer to the menu by.
     * @param   string  $title        Operator-facing label for the menu.
     *
     * @return  array<string, mixed>  The stored menu, carrying the identifier its items are created against.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `navigation.manage`.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses `navigation.manage` on the
     *          menu collection.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function createMenu(string $operationId, string $handle, string $title): array
    {
        $this->require('navigation.manage');
        $this->preauthorize($operationId, 'navigation.manage', AuthorizationResource::collection('menu'));

        return $this->mutations->run($this->context($operationId), 'menu.create', $operationId, [
            'handle' => $handle, 'title' => $title,
        ], fn (): array => $this->navigation->createMenu(
            $this->context($operationId),
            $handle,
            $title,
        )->toArray());
    }

    /**
     * List the items of one menu.
     *
     * Every item carries its materialised path and its parent, so a client can rebuild the tree from this one
     * call rather than walking parents.
     *
     * @param   string  $menuId  UUID of the menu whose items are wanted.
     *
     * @return  array{items: list<array<string, mixed>>}  Serialised items under `items`.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `navigation.manage`.
     *
     * @since   2.0.0
     */
    public function listMenuItems(string $menuId): array
    {
        $this->require('navigation.manage');
        return ['items' => array_map(
            static fn (MenuItemRecord $item): array => $item->toArray(),
            $this->navigation->items($this->context(), $menuId)
        )];
    }

    /**
     * Read one menu item, including the target it resolves to.
     *
     * Worth calling before an update: `updateMenuItem()` merges against the stored item, so this is how a
     * client learns what it is about to keep.
     *
     * @param   string  $id  UUID of the item to read.
     *
     * @return  array<string, mixed>  The stored item, carrying its path, parent, version and typed target.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `navigation.manage`.
     *
     * @since   2.0.0
     */
    public function getMenuItem(string $id): array
    {
        $this->require('navigation.manage');

        return $this->navigation->item($this->context(), $id)->toArray();
    }

    /**
     * Create a menu item under a menu, optionally beneath an existing item.
     *
     * The optional fields are declared as plain strings in the tool schema, so an empty string is how a
     * client says "none" here: an empty parent puts the item at the menu root, and an empty target field
     * leaves that part of the target unset.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $menuId       UUID of the menu the item belongs to; items never move between menus.
     * @param   string  $title        Label the navigation renders for this item.
     * @param   string  $slug         URL segment this item contributes to its path.
     * @param   int     $position     Sort order among siblings; lower values render first.
     * @param   string  $parentId     UUID of the parent item, or empty for a root-level item.
     * @param   string  $targetType   What the item points at — `content`, `anchor` or `url` — or empty.
     * @param   string  $contentId    Content the item resolves to for a content or anchor target, or empty.
     * @param   string  $targetUrl    Anchor fragment or external link, or empty.
     *
     * @return  array<string, mixed>  The stored item, with the path resolved from its parent and slug.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `navigation.manage`.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses `navigation.manage` on
     *          this menu.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function createMenuItem(
        string $operationId,
        string $menuId,
        string $title,
        string $slug,
        int $position = 0,
        string $parentId = '',
        string $targetType = '',
        string $contentId = '',
        string $targetUrl = '',
    ): array {
        $this->require('navigation.manage');
        $this->preauthorize($operationId, 'navigation.manage', AuthorizationResource::item('menu', $menuId));
        $input = compact(
            'menuId',
            'title',
            'slug',
            'position',
            'parentId',
            'targetType',
            'contentId',
            'targetUrl',
        );
        return $this->mutations->run(
            $this->context($operationId),
            'menu-item.create',
            $operationId,
            $input,
            fn (): array => $this->navigation->createItem(
                $this->context($operationId),
                $menuId,
                $parentId === '' ? null : $parentId,
                $title,
                $slug,
                $position,
                $targetType === '' ? null : $targetType,
                $contentId === '' ? null : $contentId,
                $targetUrl === '' ? null : $targetUrl,
            )->toArray()
        );
    }

    /**
     * Update a menu item's label, placement and target at an expected version.
     *
     * The optional arguments are merged against the stored item rather than applied blindly, which is what
     * lets a client rename an item without restating its whole target: null falls back to the stored value
     * and an empty string clears it. The target triple moves as a unit — supplying any one of `$targetType`,
     * `$contentId` or `$targetUrl` rewrites all three from that merge, and supplying none leaves the stored
     * target alone. A move also rewrites every descendant's path and bumps their versions, so a client
     * holding a child copy has to re-read it.
     *
     * @param   string   $operationId  Idempotency key this write is fenced on.
     * @param   string   $id           UUID of the item to update.
     * @param   int      $version      Version the caller last read; the stored item must still be at it.
     * @param   string   $title        Replacement label.
     * @param   string   $slug         Replacement URL segment.
     * @param   ?int     $position     Replacement sort order, or null to keep the stored one.
     * @param   ?string  $parentId     New parent, empty to move the item to the root, or null to keep the
     *          stored parent.
     * @param   ?string  $targetType   `content`, `anchor` or `url`; null falls back to the stored type.
     * @param   ?string  $contentId    Replacement content target, empty to clear it, null to fall back to
     *          the stored one.
     * @param   ?string  $targetUrl    Replacement fragment or link, empty to clear it, null to fall back to
     *          the stored one.
     *
     * @return  array<string, mixed>  The stored item, with its version incremented and its path re-resolved.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `navigation.manage`.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses `navigation.manage` on
     *          this item.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function updateMenuItem(
        string $operationId,
        string $id,
        int $version,
        string $title,
        string $slug,
        ?int $position = null,
        ?string $parentId = null,
        ?string $targetType = null,
        ?string $contentId = null,
        ?string $targetUrl = null,
    ): array {
        $this->require('navigation.manage');
        $this->preauthorize($operationId, 'navigation.manage', AuthorizationResource::item('menu_item', $id));
        $stored = $this->navigation->item($this->context($operationId), $id);
        $targetChanged = $targetType !== null || $contentId !== null || $targetUrl !== null;
        $input = compact(
            'id',
            'version',
            'title',
            'slug',
            'position',
            'parentId',
            'targetType',
            'contentId',
            'targetUrl',
        );

        return $this->mutations->run(
            $this->context($operationId),
            'menu-item.update',
            $operationId,
            $input,
            fn (): array => $this->navigation->updateItem(
                $this->context($operationId),
                $id,
                $version,
                $parentId === null ? $stored->parentId : ($parentId === '' ? null : $parentId),
                $title,
                $slug,
                $position ?? $stored->position,
                $targetChanged ? ($targetType ?? $stored->targetType) : null,
                $targetChanged
                    ? ($contentId === null ? $stored->contentId : ($contentId === '' ? null : $contentId))
                    : null,
                $targetChanged
                    ? ($targetUrl === null ? $stored->targetUrl : ($targetUrl === '' ? null : $targetUrl))
                    : null,
            )->toArray(),
        );
    }

    /**
     * Delete one menu item at an expected version.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $id           UUID of the item to delete.
     * @param   int     $version      Version the caller last read; the stored item must still be at it.
     *
     * @return  array{deleted: bool}  Always `deleted: true`; a refusal arrives as an exception, never as false.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `navigation.manage`.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses `navigation.manage` on
     *          this item.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function deleteMenuItem(string $operationId, string $id, int $version): array
    {
        $this->require('navigation.manage');
        $this->preauthorize($operationId, 'navigation.manage', AuthorizationResource::item('menu_item', $id));

        return $this->mutations->run(
            $this->context($operationId),
            'menu-item.delete',
            $operationId,
            compact('id', 'version'),
            function () use ($operationId, $id, $version): array {
                $this->navigation->deleteItem($this->context($operationId), $id, $version);

                return ['deleted' => true];
            },
        );
    }

    /**
     * Read the site settings document as an administrator.
     *
     * @return  array<string, mixed>  Every public setting key, with defaults filled in for keys never stored.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `settings.manage`.
     *
     * @since   2.0.0
     */
    public function getSettings(): array
    {
        $this->require('settings.manage');

        return $this->settings->managed($this->context());
    }

    /**
     * Replace the site settings document with the supplied values.
     *
     * This is a whole-document write rather than a patch: the tool schema demands every managed key, and the
     * result is validated as a unit because the keys constrain one another — the nominated homepage and
     * primary menu have to exist in this site. A rejected value therefore leaves the previous document intact.
     *
     * @param   string                $operationId            Idempotency key this write is fenced on.
     * @param   string                $siteName               Display name shown in page chrome and titles.
     * @param   string                $homepageContentId      UUID of the entry served as the homepage.
     * @param   string                $defaultLocale          Locale the site falls back to.
     * @param   string                $timezone               Timezone site-facing dates are rendered in.
     * @param   bool                  $searchIndexingEnabled  Whether the site may be indexed by search engines.
     * @param   array<string, mixed>  $presentation           Theme document: logo, footer, menus, button and
     *          header styling, and the colour schemes the site may render with.
     *
     * @return  array<string, mixed>  The settings document as it stands after the write.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `settings.manage`.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses `settings.manage` on this
     *          site.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function updateSettings(
        string $operationId,
        string $siteName,
        string $homepageContentId,
        string $defaultLocale,
        string $timezone,
        bool $searchIndexingEnabled,
        array $presentation,
    ): array {
        $this->require('settings.manage');
        $this->preauthorize(
            $operationId,
            'settings.manage',
            AuthorizationResource::item('site', $this->context()->site()->identifier()),
        );
        $values = [
            'site_name' => $siteName,
            'homepage_content_id' => $homepageContentId,
            'default_locale' => $defaultLocale,
            'timezone' => $timezone,
            'search_indexing_enabled' => $searchIndexingEnabled,
            'presentation' => $presentation,
        ];

        return $this->mutations->run(
            $this->context($operationId),
            'settings.update',
            $operationId,
            $values,
            function () use ($operationId, $values): array {
                $this->settings->updateAll($this->context($operationId), $values);

                return $this->settings->managed($this->context($operationId));
            },
        );
    }

    /**
     * List the users this credential may manage.
     *
     * Rows are filtered one by one rather than the whole call being refused, so an administrator scoped to
     * part of the installation sees a shorter list instead of an error.
     *
     * @return  array{items: list<array<string, mixed>>}  Visible users under `items`, each with its roles.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `users.manage`.
     *
     * @since   2.0.0
     */
    public function listUsers(): array
    {
        $this->require('users.manage');

        return ['items' => $this->access->users($this->context('users-list'))];
    }

    /**
     * List the manageable roles together with the capability vocabulary a grant may name.
     *
     * The two travel in one response because a client cannot compose a role without knowing which capability
     * codes exist, and this surface offers no second call for them.
     *
     * @return  array{
     *            items: list<array<string, mixed>>,
     *            capabilities: list<array{code: string, description: string}>
     *          }
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `users.manage`.
     *
     * @since   2.0.0
     */
    public function listRoles(): array
    {
        $this->require('users.manage');
        $context = $this->context('roles-list');
        return ['items' => $this->access->roles($context), 'capabilities' => $this->access->capabilities($context)];
    }

    /**
     * Update a user's address, display name and account status at an expected version.
     *
     * Two guards stand in front of the write: an actor may not move its own account to a status that cannot
     * sign in, and the requested status has to be a legal move from the one currently stored. The user's
     * security epoch advances as the edit lands, so every token issued before it stops verifying — editing an
     * account is also a credential revocation.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $id           UUID of the user to update.
     * @param   int     $version      Version the caller last read; the stored user must still be at it.
     * @param   string  $email        Replacement address for the account.
     * @param   string  $displayName  Replacement human-readable name.
     * @param   string  $status       Account state to store: `pending`, `active`, `suspended` or `disabled`.
     *
     * @return  array{updated: bool}  Always `updated: true`; a refusal arrives as an exception.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `users.manage`.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses `users.manage` on this
     *          user.
     * @throws  \ValueError  When the status is not one of the stored account states.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function updateUser(
        string $operationId,
        string $id,
        int $version,
        string $email,
        string $displayName,
        string $status,
    ): array {
        $this->require('users.manage');
        $this->preauthorize($operationId, 'users.manage', AuthorizationResource::item('user', $id));
        return $this->mutations->run(
            $this->context($operationId),
            'user.update',
            $operationId,
            compact('id', 'version', 'email', 'displayName', 'status'),
            function () use ($operationId, $id, $version, $email, $displayName, $status): array {
                $this->access->updateUser(
                    $this->context($operationId),
                    $id,
                    $email,
                    $displayName,
                    UserStatus::from($status),
                    $version,
                );
                return ['updated' => true];
            }
        );
    }

    /**
     * Create a permission role, initially conferring nothing.
     *
     * Capabilities are attached separately, so a role created here is inert until it is granted something.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $code         Stable machine code assignments refer to the role by.
     * @param   string  $name         Operator-facing label for the role.
     *
     * @return  array{id: string}  UUID of the stored role, under `id`.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `users.manage`.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses `users.manage` on the role
     *          collection.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function createRole(string $operationId, string $code, string $name): array
    {
        $this->require('users.manage');
        $this->preauthorize($operationId, 'users.manage', AuthorizationResource::collection('role'));
        return $this->mutations->run(
            $this->context($operationId),
            'role.create',
            $operationId,
            compact('code', 'name'),
            fn (): array => ['id' => $this->access->createRole($this->context($operationId), $code, $name)]
        );
    }

    /**
     * List the API token metadata issued for the caller's site.
     *
     * Metadata only. A token's plaintext exists solely in the response that minted it, so nothing here can be
     * replayed as a credential.
     *
     * @return  array{items: list<array<string, mixed>>}  Token rows under `items`, newest first.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `users.manage`.
     *
     * @since   2.0.0
     */
    public function listTokens(): array
    {
        $this->require('users.manage');

        return ['items' => $this->access->tokens($this->context('tokens-list'))];
    }

    /**
     * Revoke one API or MCP token immediately.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $tokenId      UUID of the token to kill.
     *
     * @return  array{revoked: bool}  Always `revoked: true`; a refusal arrives as an exception.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `users.manage`.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses `users.manage` on this
     *          token.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function revokeToken(string $operationId, string $tokenId): array
    {
        $this->require('users.manage');
        $this->preauthorize($operationId, 'users.manage', AuthorizationResource::item('api_token', $tokenId));

        return $this->mutations->run(
            $this->context($operationId),
            'token.revoke',
            $operationId,
            ['token_id' => $tokenId],
            function () use ($operationId, $tokenId): array {
                $this->access->revokeToken($this->context($operationId), $tokenId);

                return ['revoked' => true];
            },
        );
    }

    /**
     * Invalidate every token one user holds, in every site, by advancing their security epoch.
     *
     * The break-glass action for a compromised account: it reaches credentials this site never issued and
     * cannot be undone, so the user has to be issued fresh ones afterwards. Reach for
     * `revokeSubjectSiteTokens()` when only this site is affected.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $userId       UUID of the user whose credentials are being burned.
     * @param   string  $reason       Operator-facing justification recorded with the revocation.
     *
     * @return  array{revoked: int}  How many live tokens were revoked, under `revoked`; zero when the
     *          subject held none.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `users.manage`.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses `users.manage` on this
     *          user.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function emergencyRevokeSubjectTokens(
        string $operationId,
        string $userId,
        string $reason,
    ): array {
        $this->require('users.manage');
        $this->preauthorize($operationId, 'users.manage', AuthorizationResource::item('user', $userId));
        return $this->mutations->run(
            $this->context($operationId),
            'token.revoke-subject',
            $operationId,
            compact('userId', 'reason'),
            fn (): array => ['revoked' => $this->access->emergencyRevokeAllSubjectTokens(
                $this->context($operationId),
                $userId,
                $reason,
            )],
        );
    }

    /**
     * Revoke every token one user holds in the caller's site, leaving their other sites alone.
     *
     * The site-scoped counterpart to `emergencyRevokeSubjectTokens()`, and the right tool for an off-boarding
     * from one site. Authorization is asked against the site rather than the user, because the site is the
     * boundary actually being cleared.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $userId       UUID of the user whose tokens for this site are withdrawn.
     * @param   string  $reason       Operator-facing justification recorded with the revocation.
     *
     * @return  array{revoked: int}  How many of this site's tokens were revoked, under `revoked`.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `users.manage`.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses `users.manage` on this
     *          site.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function revokeSubjectSiteTokens(string $operationId, string $userId, string $reason): array
    {
        $this->require('users.manage');
        $this->preauthorize(
            $operationId,
            'users.manage',
            AuthorizationResource::item('site', $this->context($operationId)->site()->identifier()),
        );
        return $this->mutations->run(
            $this->context($operationId),
            'token.revoke-subject-site',
            $operationId,
            compact('userId', 'reason'),
            fn (): array => ['revoked' => $this->access->revokeSubjectTokens(
                $this->context($operationId),
                $userId,
                $reason,
            )],
        );
    }

    /**
     * List the extension signing keys and what still depends on each.
     *
     * Every row carries the active releases signed by that key, which is the number an operator needs before
     * finalizing a rotation: a key with dependents cannot be retired yet.
     *
     * @return  array{items: list<array<string, mixed>>}  Key rows under `items`, each with its
     *          `affected_extensions` list.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `extensions.manage`.
     *
     * @since   2.0.0
     */
    public function listTrustKeys(): array
    {
        $this->require('extensions.manage');
        return ['items' => $this->trust->keys($this->context('trust-keys-list'))];
    }

    /**
     * Register a constrained, expiring Ed25519 key that may sign extension packages.
     *
     * Trust is never open-ended here: a key is admitted only for one vendor namespace, one extension name
     * pattern and one expiry. The write is taken under the installation-wide extension lifecycle lock, so it
     * cannot interleave with an install or activation that is verifying against the key set.
     *
     * @param   string  $operationId       Idempotency key this write is fenced on.
     * @param   string  $keyId             Identifier package signatures name this key by.
     * @param   string  $publicKeyBase64   Base64-encoded Ed25519 public key.
     * @param   string  $vendorNamespace   Vendor whose packages this key is allowed to sign.
     * @param   string  $extensionPattern  Extension name pattern the key is confined to.
     * @param   string  $expiresAt         Expiry as a date string; a key is never admitted without one.
     *
     * @return  array{updated: bool}  Always `updated: true`; a refusal arrives as an exception.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `extensions.manage`.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses `extensions.manage` on the
     *          trust key collection.
     * @throws  InvalidArgumentException  When the identifier, key, namespace, pattern or expiry fails
     *          validation, or the operation identifier is malformed or reused with different arguments.
     * @throws  \DateMalformedStringException  When the expiry is not a readable date string.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the
     *          lease is lost before the write completes.
     *
     * @since   2.0.0
     */
    public function addTrustKey(
        string $operationId,
        string $keyId,
        string $publicKeyBase64,
        string $vendorNamespace,
        string $extensionPattern,
        string $expiresAt,
    ): array {
        $this->require('extensions.manage');
        $this->preauthorize(
            $operationId,
            'extensions.manage',
            AuthorizationResource::collection('extension_trust_key'),
        );
        return $this->runTrustMutation(
            $this->context($operationId),
            'trust-key.add',
            $operationId,
            compact('keyId', 'publicKeyBase64', 'vendorNamespace', 'extensionPattern', 'expiresAt'),
            function () use (
                $operationId,
                $keyId,
                $publicKeyBase64,
                $vendorNamespace,
                $extensionPattern,
                $expiresAt,
            ): array {
                $this->trust->add(
                    $this->context($operationId),
                    $keyId,
                    $publicKeyBase64,
                    $vendorNamespace,
                    $extensionPattern,
                    new \DateTimeImmutable($expiresAt),
                );
                return ['updated' => true];
            },
        );
    }

    /**
     * Add a replacement signing key while the key it supersedes stays valid.
     *
     * Rotation is deliberately two-step. This half only introduces the new key: the old one keeps verifying,
     * so releases already installed under it continue to load. Finalizing is a separate call to
     * `revokeTrustKey()` without `$emergency`, and it is refused until nothing installed still names the old
     * key. The replacement has to preserve the superseded key's vendor namespace and extension pattern, so a
     * rotation cannot quietly widen what the key is allowed to sign. Like every trust write, this runs under
     * the extension lifecycle lock.
     *
     * @param   string  $operationId       Idempotency key this write is fenced on.
     * @param   string  $oldKeyId          Identifier of the key being superseded; it must still be active.
     * @param   string  $newKeyId          Identifier the replacement key is registered under.
     * @param   string  $publicKeyBase64   Base64-encoded Ed25519 public key of the replacement.
     * @param   string  $vendorNamespace   Vendor whose packages the replacement may sign.
     * @param   string  $extensionPattern  Extension name pattern the replacement is confined to.
     * @param   string  $expiresAt         Expiry of the replacement, as a date string.
     *
     * @return  array{updated: bool}  Always `updated: true`; a refusal arrives as an exception.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `extensions.manage`.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses `extensions.manage` on the
     *          superseded key.
     * @throws  InvalidArgumentException  When an argument fails validation, no active key carries the old
     *          identifier, the replacement changes the namespace constraints, or the operation identifier is
     *          malformed or reused with different arguments.
     * @throws  \DateMalformedStringException  When the expiry is not a readable date string.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the
     *          lease is lost before the write completes.
     *
     * @since   2.0.0
     */
    public function rotateTrustKey(
        string $operationId,
        string $oldKeyId,
        string $newKeyId,
        string $publicKeyBase64,
        string $vendorNamespace,
        string $extensionPattern,
        string $expiresAt,
    ): array {
        $this->require('extensions.manage');
        $this->preauthorize(
            $operationId,
            'extensions.manage',
            AuthorizationResource::item('extension_trust_key', $oldKeyId),
        );
        $input = compact(
            'oldKeyId',
            'newKeyId',
            'publicKeyBase64',
            'vendorNamespace',
            'extensionPattern',
            'expiresAt',
        );
        return $this->runTrustMutation(
            $this->context($operationId),
            'trust-key.rotate',
            $operationId,
            $input,
            function () use (
                $operationId,
                $oldKeyId,
                $newKeyId,
                $publicKeyBase64,
                $vendorNamespace,
                $extensionPattern,
                $expiresAt,
            ): array {
                $this->trust->rotate(
                    $this->context($operationId),
                    $oldKeyId,
                    $newKeyId,
                    $publicKeyBase64,
                    $vendorNamespace,
                    $extensionPattern,
                    new \DateTimeImmutable($expiresAt),
                );
                return ['updated' => true];
            },
        );
    }

    /**
     * Finalize a rotation, or quarantine everything a compromised key ever signed.
     *
     * One tool with two very different outcomes, chosen by `$emergency`. Left false, this is the ordinary end
     * of a rotation and is refused while any active release still depends on the key. Set true, the key is
     * treated as compromised: every release it signed is quarantined at once, which takes those extensions out
     * of service until they are re-signed. Both paths run under the extension lifecycle lock.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $keyId        Identifier of the key being retired or disowned.
     * @param   string  $reason       Operator-facing justification recorded with the change.
     * @param   bool    $emergency    True to quarantine the key's releases immediately, false to finalize a
     *          completed rotation.
     *
     * @return  array<string, mixed>  For an emergency, the quarantined extension identifiers under
     *          `quarantined`, empty when the key signed nothing still installed; for a finalization,
     *          `updated: true`.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `extensions.manage`.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses `extensions.manage` on
     *          this key.
     * @throws  InvalidArgumentException  When the identifier or reason is rejected, no active key carries the
     *          identifier, releases still depend on it, or the operation identifier is malformed or reused
     *          with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the
     *          lease is lost before the write completes.
     *
     * @since   2.0.0
     */
    public function revokeTrustKey(
        string $operationId,
        string $keyId,
        string $reason,
        bool $emergency = false,
    ): array {
        $this->require('extensions.manage');
        $this->preauthorize(
            $operationId,
            'extensions.manage',
            AuthorizationResource::item('extension_trust_key', $keyId),
        );
        return $this->runTrustMutation(
            $this->context($operationId),
            $emergency ? 'trust-key.emergency-revoke' : 'trust-key.finalize',
            $operationId,
            compact('keyId', 'reason', 'emergency'),
            function () use ($operationId, $keyId, $reason, $emergency): array {
                $context = $this->context($operationId);
                if ($emergency) {
                    return ['quarantined' => $this->trust->emergencyRevoke($context, $keyId, $reason)];
                }
                $this->trust->finalizeRotation($context, $keyId, $reason);
                return ['updated' => true];
            },
        );
    }

    /**
     * List the installed extensions this credential may manage.
     *
     * @return  array{items: list<array<string, mixed>>}  Registry rows under `items`, each carrying the
     *          extension's identifier, type and lifecycle status.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `extensions.manage`.
     *
     * @since   2.0.0
     */
    public function listExtensions(): array
    {
        $this->require('extensions.manage');

        return ['items' => $this->extensions->installed($this->context())];
    }

    /**
     * Activate an installed extension so the next compiled runtime map carries it.
     *
     * A template is activated onto one presentation surface at a time and so needs `$surface`; every other
     * extension type leaves it unset. Taking over the administrator surface is the case that demands step-up
     * authentication, because a broken administrator theme locks operators out — and this surface cannot
     * supply it. No credential crosses a tool boundary, so the extension manager is always called with no
     * step-up proof and refuses that one change with `StepUpAuthenticationRequired`; the browser or
     * protected REST path remains the route for it. Every other activation
     * proceeds under the caller's existing `extensions.manage` authorization, taken under the
     * installation-wide extension lifecycle lock.
     *
     * @param   string   $operationId  Idempotency key this write is fenced on.
     * @param   string   $identifier   `vendor/name` identifier of the installed extension.
     * @param   ?string  $surface      `site` or `administrator` for a template; null or empty otherwise.
     *
     * @return  array<string, mixed>  The registry row for the extension after the status change.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `extensions.manage`.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses `extensions.manage` on
     *          this extension.
     * @throws  \Kumwe\App\Presentation\Application\StepUpAuthenticationRequired  When the change would take over
     *          the administrator surface, which no machine caller may prove.
     * @throws  InvalidArgumentException  When the surface is neither `site` nor `administrator`, or the
     *          operation identifier is malformed or reused with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the
     *          lease is lost before the write completes.
     *
     * @since   2.0.0
     */
    public function activateExtension(
        string $operationId,
        string $identifier,
        ?string $surface = null,
    ): array {
        $this->require('extensions.manage');
        $this->preauthorize(
            $operationId,
            'extensions.manage',
            AuthorizationResource::item('extension', $identifier),
        );

        $context = $this->context($operationId);
        $themeSurface = ThemeSurface::optional($surface);

        return $this->runExtensionMutation(
            $context,
            'extension.activate',
            $operationId,
            [
                'identifier' => $identifier,
                'surface' => $themeSurface?->value,
            ],
            fn (): array => $this->extensions->activate(
                $identifier,
                $context,
                $themeSurface,
            ),
        );
    }

    /**
     * Disable an installed extension so it stops contributing to the compiled runtime map.
     *
     * The reversible half of removal: the files stay on disk and the registry keeps the release, so
     * `activateExtension()` can put it back. An extension currently serving the administrator theme demands
     * step-up authentication, since disabling it changes what the administration UI renders with, and this
     * surface carries no credential with which to prove it: that one case is refused here and belongs to the
     * browser or the protected REST path. The console can restore the built-in administrator theme for
     * break-glass recovery, but cannot step up to disable a live administrator theme. Every other disable
     * proceeds under the
     * caller's existing `extensions.manage` authorization, taken under the extension lifecycle lock.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $identifier   `vendor/name` identifier of the installed extension.
     *
     * @return  array<string, mixed>  The registry row for the extension after the status change.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `extensions.manage`.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses `extensions.manage` on
     *          this extension.
     * @throws  \Kumwe\App\Presentation\Application\StepUpAuthenticationRequired  When the extension is the live
     *          administrator theme, which no machine caller may prove a step-up for.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function disableExtension(
        string $operationId,
        string $identifier,
    ): array {
        $this->require('extensions.manage');
        $this->preauthorize(
            $operationId,
            'extensions.manage',
            AuthorizationResource::item('extension', $identifier),
        );
        $context = $this->context($operationId);
        return $this->runExtensionMutation(
            $context,
            'extension.disable',
            $operationId,
            ['identifier' => $identifier],
            fn (): array => $this->extensions->disable($identifier, $context),
        );
    }

    /**
     * Remove an extension from the registry and retire the files it was serving from.
     *
     * The one lifecycle change the registry cannot undo: the extension row and the capabilities its package
     * contributed go with it. The runtime directory is retired rather than deleted outright, so processes
     * still running an older compiled generation keep reading what they have until they drain. Removing the
     * extension that serves the live administrator theme demands a step-up this surface cannot supply and is
     * refused here; do that one in the browser or protected REST path. The console can first restore the
     * built-in administrator theme for break-glass recovery, after which the inactive extension can be removed.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $identifier   `vendor/name` identifier of the extension to remove.
     *
     * @return  array{uninstalled: bool}  Always `uninstalled: true`; a refusal arrives as an exception.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `extensions.manage`.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses `extensions.manage` on
     *          this extension.
     * @throws  \Kumwe\App\Presentation\Application\StepUpAuthenticationRequired  When the extension is the live
     *          administrator theme, which no machine caller may prove a step-up for.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function uninstallExtension(
        string $operationId,
        string $identifier,
    ): array {
        $this->require('extensions.manage');
        $this->preauthorize(
            $operationId,
            'extensions.manage',
            AuthorizationResource::item('extension', $identifier),
        );
        $context = $this->context($operationId);
        return $this->runExtensionMutation(
            $context,
            'extension.uninstall',
            $operationId,
            ['identifier' => $identifier],
            function () use ($context, $identifier): array {
                $this->extensions->uninstall($identifier, $context);
                return ['uninstalled' => true];
            }
        );
    }

    /**
     * List the recurring automation schedules of the caller's site.
     *
     * @return  array{items: list<array<string, mixed>>}  Schedule rows under `items`; empty when none is
     *          manageable by this credential.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `automation.manage`.
     *
     * @since   2.0.0
     */
    public function listSchedules(): array
    {
        $this->require('automation.manage');

        return ['items' => $this->automation->schedules($this->context())];
    }

    /**
     * List the queued automation jobs this credential is allowed to see, most recent first.
     *
     * @param   int  $limit  Visible jobs to return; between 1 and 500.
     *
     * @return  array{items: list<array<string, mixed>>}  Job rows under `items`, most recent first; empty
     *          when none is visible.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `automation.manage`.
     * @throws  InvalidArgumentException  When the limit falls outside 1 to 500.
     *
     * @since   2.0.0
     */
    public function listJobs(int $limit = 100): array
    {
        $this->require('automation.manage');
        return ['items' => $this->automation->jobs($this->context(), $limit)];
    }

    /**
     * Create a recurring automation schedule.
     *
     * The first run is anchored to this object's clock, so a schedule created now becomes due from now rather
     * than from some implicit epoch. Occurrences are enqueued with an empty payload: a handler that needs
     * arguments has to be configured somewhere other than this surface.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $name         Operator-facing label the schedule is listed under.
     * @param   string  $cron         Five-field cron expression deciding when the schedule is due.
     * @param   string  $jobType      Registered handler type each occurrence enqueues.
     * @param   string  $timezone     Timezone the cron expression is evaluated in.
     * @param   string  $queue        Queue name the enqueued jobs are placed on.
     *
     * @return  array{id: string}  UUID of the stored schedule, under `id`.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `automation.manage`.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses `automation.manage` on the
     *          schedule collection.
     * @throws  InvalidArgumentException  When no handler is registered for the job type, the cron expression
     *          or timezone is rejected, or the operation identifier is malformed or reused with different
     *          arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the
     *          lease is lost before the write completes.
     *
     * @since   2.0.0
     */
    public function createSchedule(
        string $operationId,
        string $name,
        string $cron,
        string $jobType,
        string $timezone = 'UTC',
        string $queue = 'default',
    ): array {
        $this->require('automation.manage');

        $this->preauthorize($operationId, 'automation.manage', AuthorizationResource::collection('schedule'));
        return $this->mutations->run($this->context($operationId), 'schedule.create', $operationId, [
            'name' => $name, 'cron' => $cron, 'jobType' => $jobType,
            'timezone' => $timezone, 'queue' => $queue,
        ], fn (): array => ['id' => $this->automation->createSchedule(
            $this->context($operationId),
            $name,
            $cron,
            $timezone,
            $jobType,
            [],
            $queue,
            $this->clock->now(),
        )]);
    }

    /**
     * Resume or suspend a schedule at an expected version.
     *
     * Suspension stops dispatch without losing the schedule, so an agent can quiet a misbehaving job and hand
     * the decision to an operator instead of deleting the definition.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $id           UUID of the schedule to toggle.
     * @param   int     $version      Version the caller last read; the stored schedule must still be at it.
     * @param   bool    $enabled      True to resume dispatching, false to suspend it.
     *
     * @return  array{updated: bool}  Always `updated: true`; a refusal arrives as an exception.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `automation.manage`.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses `automation.manage` on
     *          this schedule.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function setScheduleEnabled(
        string $operationId,
        string $id,
        int $version,
        bool $enabled,
    ): array {
        $this->require('automation.manage');
        $this->preauthorize($operationId, 'automation.manage', AuthorizationResource::item('schedule', $id));
        return $this->mutations->run(
            $this->context($operationId),
            'schedule.update',
            $operationId,
            compact('id', 'version', 'enabled'),
            function () use ($operationId, $id, $version, $enabled): array {
                $this->automation->setScheduleEnabled($this->context($operationId), $id, $version, $enabled);
                return ['updated' => true];
            }
        );
    }

    /**
     * Delete a recurring schedule at an expected version.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $id           UUID of the schedule to remove.
     * @param   int     $version      Version the caller last read; the stored schedule must still be at it.
     *
     * @return  array{deleted: bool}  Always `deleted: true`; a refusal arrives as an exception.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `automation.manage`.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses `automation.manage` on
     *          this schedule.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function deleteSchedule(string $operationId, string $id, int $version): array
    {
        $this->require('automation.manage');
        $this->preauthorize($operationId, 'automation.manage', AuthorizationResource::item('schedule', $id));
        return $this->mutations->run(
            $this->context($operationId),
            'schedule.delete',
            $operationId,
            compact('id', 'version'),
            function () use ($operationId, $id, $version): array {
                $this->automation->deleteSchedule($this->context($operationId), $id, $version);
                return ['deleted' => true];
            }
        );
    }

    /**
     * Requeue a dead job for another attempt.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $id           UUID of the dead job to requeue.
     *
     * @return  array{updated: bool}  Always `updated: true`; a refusal arrives as an exception.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `automation.manage`.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses `automation.manage` on
     *          this job.
     * @throws  InvalidArgumentException  When no dead job carries that identifier, or the operation identifier
     *          is malformed or reused with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the
     *          lease is lost before the write completes.
     *
     * @since   2.0.0
     */
    public function retryJob(string $operationId, string $id): array
    {
        return $this->jobAction($operationId, $id, true);
    }

    /**
     * Withdraw a pending job so it is never dispatched.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $id           UUID of the pending job to withdraw.
     *
     * @return  array{updated: bool}  Always `updated: true`; a refusal arrives as an exception.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `automation.manage`.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses `automation.manage` on
     *          this job.
     * @throws  InvalidArgumentException  When no pending job carries that identifier, or the operation
     *          identifier is malformed or reused with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the
     *          lease is lost before the write completes.
     *
     * @since   2.0.0
     */
    public function cancelJob(string $operationId, string $id): array
    {
        return $this->jobAction($operationId, $id, false);
    }

    /**
     * Run the shared retry-or-cancel path for one job.
     *
     * Retrying and cancelling differ only in the audited operation name and the service call they make, so
     * they share one authorized and fenced body rather than two that could drift apart.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $id           UUID of the job being acted on.
     * @param   bool    $retry        True to requeue a dead job, false to cancel a pending one.
     *
     * @return  array{updated: bool}  Always `updated: true`; a refusal arrives as an exception.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `automation.manage`.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses `automation.manage` on
     *          this job.
     * @throws  InvalidArgumentException  When no job in the required state carries that identifier, or the
     *          operation identifier is malformed or reused with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the
     *          lease is lost before the write completes.
     *
     * @since   2.0.0
     */
    private function jobAction(string $operationId, string $id, bool $retry): array
    {
        $this->require('automation.manage');
        $this->preauthorize($operationId, 'automation.manage', AuthorizationResource::item('job', $id));
        return $this->mutations->run(
            $this->context($operationId),
            $retry ? 'job.retry' : 'job.cancel',
            $operationId,
            compact('id'),
            function () use ($operationId, $id, $retry): array {
                if ($retry) {
                    $this->automation->retryJob($this->context($operationId), $id);
                } else {
                    $this->automation->cancelJob($this->context($operationId), $id);
                }
                return ['updated' => true];
            }
        );
    }

    /**
     * Render the capability summary as the body of the `kumwe://capabilities` resource.
     *
     * The same document `discover()` returns, encoded for a client that reads the surface as a resource
     * rather than calling a tool. This handler checks no capability of its own, so the session's own
     * authentication is the only gate in front of it.
     *
     * @return  string  Pretty-printed JSON with unescaped slashes.
     *
     * @throws  JsonException  When the catalogue summary cannot be encoded.
     *
     * @since   2.0.0
     */
    public function capabilityResource(): string
    {
        return json_encode(
            $this->catalog->publicSummary(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Build the `kumwe_site_review` prompt for one review focus.
     *
     * The prompt only frames the request; it grants nothing, so the reviewing client is still held to whatever
     * capabilities its own credential carries when it starts reading.
     *
     * @param   string  $focus  Angle to review from: `content`, `seo`, `structure` or `extensions`.
     *
     * @return  list<array{role: string, content: string}>  A single user message naming the requested focus.
     *
     * @throws  InvalidArgumentException  When the focus is not one of the four supported values.
     *
     * @since   2.0.0
     */
    public function siteReviewPrompt(string $focus = 'content'): array
    {
        if (!in_array($focus, ['content', 'seo', 'structure', 'extensions'], true)) {
            throw new InvalidArgumentException('The site review focus is not supported.');
        }

        return [[
            'role' => 'user',
            'content' => sprintf('Review the Kumwe site with a %s focus and propose explicit changes.', $focus),
        ]];
    }

    /**
     * Discover the generated business entities visible to this MCP credential.
     *
     * @return  array{items: list<array<string, mixed>>, truncated: bool}  Bounded policy-filtered metadata.
     *
     * @throws  InsufficientCapability  When the credential cannot browse business records.
     *
     * @since   2.0.0
     */
    public function discoverBusinessRecords(): array
    {
        $this->require('business.record.browse');

        return $this->businessRecords->discover($this->context());
    }

    /**
     * Inspect one policy-visible generated business entity.
     *
     * @param   string  $definition  Definition UUID or namespaced handle.
     *
     * @return  array{definition: array<string, mixed>}  Safe typed metadata for this entity.
     *
     * @throws  InsufficientCapability  When the credential cannot read business records.
     *
     * @since   2.0.0
     */
    public function inspectBusinessRecord(string $definition): array
    {
        $this->require('business.record.read');

        return $this->businessRecords->inspect($this->context(), $definition);
    }

    /**
     * Execute one typed custom view declared by a policy-visible business definition.
     *
     * The custom view kind selects its exact browse/read/create/update/history/relation policy inside the
     * shared surface service, so this adapter performs no weaker static capability shortcut beforehand.
     *
     * @param   string                $definition  Definition UUID or namespaced handle.
     * @param   string                $view        Custom view handle.
     * @param   array<string, mixed>  $query       Shared bounded record-query document.
     * @param   array<string, mixed>  $parameters  Contract-specific bounded parameters.
     * @param   ?string               $record      Optional public record identity for detail-like views.
     *
     * @return  array<string, mixed>  Policy-filtered view metadata and validated result.
     *
     * @since   2.0.0
     */
    public function executeBusinessView(
        string $definition,
        string $view,
        array $query = [],
        array $parameters = [],
        ?string $record = null,
    ): array {
        return $this->businessRecords->view(
            $this->context(),
            $definition,
            $view,
            $query,
            $parameters,
            $record,
        );
    }

    /**
     * Search one generated business entity through the shared bounded query grammar.
     *
     * @param   string                $definition  Definition UUID or namespaced handle.
     * @param   array<string, mixed>  $query       Closed filter, search, sort and projection document.
     *
     * @return  array<string, mixed>  Policy-filtered definition metadata and one bounded record page.
     *
     * @throws  InsufficientCapability  When the credential cannot browse business records.
     *
     * @since   2.0.0
     */
    public function searchBusinessRecords(string $definition, array $query = []): array
    {
        $this->require('business.record.browse');

        return $this->businessRecords->search($this->context(), $definition, $query);
    }

    /**
     * Read one generated business record by its public identity.
     *
     * @param   string  $definition       Definition UUID or namespaced handle.
     * @param   string  $record           Public record identity.
     * @param   bool    $includeArchived  Whether archived rows may be returned.
     * @param   bool    $includeDeleted   Whether soft-deleted rows may be returned.
     *
     * @return  array<string, mixed>  Safe definition, record and semantic fields.
     *
     * @throws  InsufficientCapability  When the credential cannot read business records.
     *
     * @since   2.0.0
     */
    public function readBusinessRecord(
        string $definition,
        string $record,
        bool $includeArchived = false,
        bool $includeDeleted = false,
    ): array {
        $this->require('business.record.read');

        return $this->businessRecords->read(
            $this->context(),
            $definition,
            $record,
            $includeArchived,
            $includeDeleted,
        );
    }

    /**
     * Read one bounded page of generated business record history.
     *
     * @param   string  $definition     Definition UUID or namespaced handle.
     * @param   string  $record         Public record identity.
     * @param   int     $limit          Maximum revisions, from 1 through 200.
     * @param   ?int    $beforeVersion  Exclusive positive record-version cursor.
     *
     * @return  array<string, mixed>  Omission-safe revisions and continuation metadata.
     *
     * @throws  InsufficientCapability  When the credential cannot read business record history.
     *
     * @since   2.0.0
     */
    public function businessRecordHistory(
        string $definition,
        string $record,
        int $limit = 100,
        ?int $beforeVersion = null,
    ): array {
        $this->require('business.record.history');

        return $this->businessRecords->history(
            $this->context(),
            $definition,
            $record,
            $limit,
            $beforeVersion,
        );
    }

    /**
     * Plan one exact generated-business mutation against current trusted state.
     *
     * Planning is read-only but requires both record read and the exact mutation capability. The shared
     * planner then derives the definition, runtime generation, record policy, actor context, payload, and
     * current source-record version bindings that execution must re-prove.
     *
     * @param   string                $operationId        Identity the plan and eventual mutation share.
     * @param   string                $operation          Closed generated-business mutation name.
     * @param   string                $definition         Definition UUID or namespaced handle.
     * @param   ?string               $record             Existing or optional create record identity.
     * @param   ?int                  $expectedVersion    Current version for an existing record.
     * @param   array<string, mixed>  $values             Create or update values.
     * @param   ?string               $relationship       Declared relationship handle.
     * @param   ?string               $target             Target record identity.
     * @param   ?int                  $position           Optional ordered relation position.
     * @param   array<string, mixed>  $targetValues       Optional owned-line values.
     * @param   list<string>          $orderedRecordIds   Complete ordered relationship member list.
     * @param   ?string               $action             Declared action handle.
     * @param   array<string, mixed>  $input              Typed action input.
     * @param   ?string               $approvalRequestId  Independent approval UUID for execution.
     *
     * @return  array<string, mixed>  Signed five-minute plan and its safe current bindings.
     *
     * @throws  InsufficientCapability  When the credential lacks read or exact mutation capability.
     *
     * @since   2.0.0
     */
    public function planBusinessRecordMutation(
        string $operationId,
        string $operation,
        string $definition,
        ?string $record = null,
        ?int $expectedVersion = null,
        array $values = [],
        ?string $relationship = null,
        ?string $target = null,
        ?int $position = null,
        array $targetValues = [],
        array $orderedRecordIds = [],
        ?string $action = null,
        array $input = [],
        ?string $approvalRequestId = null,
    ): array {
        $this->require('business.record.read');
        $this->require(BusinessMcpHandlers::capabilityFor($operation));

        return $this->businessRecords->planMutation(
            $this->context(),
            $operationId,
            $operation,
            $definition,
            $record,
            $expectedVersion,
            $values,
            $relationship,
            $target,
            $position,
            $targetValues,
            $orderedRecordIds,
            $action,
            $input,
            $approvalRequestId,
        );
    }

    /**
     * Create one typed generated business record under a replay-safe identity.
     *
     * @param   string                $operationId  Caller-chosen stable operation identity.
     * @param   string                $plan         Signed plan for these exact mutation arguments.
     * @param   string                $definition   Definition UUID or namespaced handle.
     * @param   array<string, mixed>  $values       Values keyed by declared field handle.
     * @param   ?string               $record       Optional caller-chosen public identity.
     *
     * @return  array<string, mixed>  Omission-safe mutation result or an identical replay.
     *
     * @since   2.0.0
     */
    public function createBusinessRecord(
        string $operationId,
        string $plan,
        string $definition,
        array $values,
        ?string $record = null,
    ): array {
        return $this->businessRecords->create(
            $this->businessMutationContext($operationId, 'create'),
            $operationId,
            $plan,
            $definition,
            $values,
            $record,
        );
    }

    /**
     * Update one generated business record at the exact version the caller inspected.
     *
     * @param   string                $operationId      Caller-chosen stable operation identity.
     * @param   string                $plan             Signed plan for these exact mutation arguments.
     * @param   string                $definition       Definition UUID or namespaced handle.
     * @param   string                $record           Public record identity.
     * @param   int                   $expectedVersion  Optimistic version previously read.
     * @param   array<string, mixed>  $values           Replacement values by declared field handle.
     *
     * @return  array<string, mixed>  Omission-safe mutation result or an identical replay.
     *
     * @since   2.0.0
     */
    public function updateBusinessRecord(
        string $operationId,
        string $plan,
        string $definition,
        string $record,
        int $expectedVersion,
        array $values,
    ): array {
        return $this->businessRecords->update(
            $this->businessMutationContext($operationId, 'update'),
            $operationId,
            $plan,
            $definition,
            $record,
            $expectedVersion,
            $values,
        );
    }

    /**
     * Archive one generated business record at an exact optimistic version.
     *
     * @param   string  $operationId      Caller-chosen stable operation identity.
     * @param   string  $plan             Signed plan for these exact mutation arguments.
     * @param   string  $definition       Definition UUID or namespaced handle.
     * @param   string  $record           Public record identity.
     * @param   int     $expectedVersion  Optimistic version previously read.
     *
     * @return  array<string, mixed>  Omission-safe mutation result or an identical replay.
     *
     * @since   2.0.0
     */
    public function archiveBusinessRecord(
        string $operationId,
        string $plan,
        string $definition,
        string $record,
        int $expectedVersion,
    ): array {
        return $this->businessRecords->archive(
            $this->businessMutationContext($operationId, 'archive'),
            $operationId,
            $plan,
            $definition,
            $record,
            $expectedVersion,
        );
    }

    /**
     * Restore one archived or soft-deleted generated record at an exact version.
     *
     * @param   string  $operationId      Caller-chosen stable operation identity.
     * @param   string  $plan             Signed plan for these exact mutation arguments.
     * @param   string  $definition       Definition UUID or namespaced handle.
     * @param   string  $record           Public record identity.
     * @param   int     $expectedVersion  Optimistic version previously read.
     *
     * @return  array<string, mixed>  Omission-safe mutation result or an identical replay.
     *
     * @since   2.0.0
     */
    public function restoreBusinessRecord(
        string $operationId,
        string $plan,
        string $definition,
        string $record,
        int $expectedVersion,
    ): array {
        return $this->businessRecords->restore(
            $this->businessMutationContext($operationId, 'restore'),
            $operationId,
            $plan,
            $definition,
            $record,
            $expectedVersion,
        );
    }

    /**
     * Delete one generated business record at an exact optimistic version.
     *
     * @param   string  $operationId      Caller-chosen stable operation identity.
     * @param   string  $plan             Signed plan for these exact mutation arguments.
     * @param   string  $definition       Definition UUID or namespaced handle.
     * @param   string  $record           Public record identity.
     * @param   int     $expectedVersion  Optimistic version previously read.
     *
     * @return  array<string, mixed>  Omission-safe mutation result or an identical replay.
     *
     * @since   2.0.0
     */
    public function deleteBusinessRecord(
        string $operationId,
        string $plan,
        string $definition,
        string $record,
        int $expectedVersion,
    ): array {
        return $this->businessRecords->delete(
            $this->businessMutationContext($operationId, 'delete'),
            $operationId,
            $plan,
            $definition,
            $record,
            $expectedVersion,
        );
    }

    /**
     * Create one declared relationship link or owned line.
     *
     * @param   string                $operationId      Caller-chosen stable operation identity.
     * @param   string                $plan             Signed plan for these exact mutation arguments.
     * @param   string                $definition       Definition UUID or namespaced handle.
     * @param   string                $record           Public source-record identity.
     * @param   int                   $expectedVersion  Optimistic source version previously read.
     * @param   string                $relationship     Declared relationship handle.
     * @param   string                $target           Public target identity or new owned-line identity.
     * @param   ?int                  $position         Optional zero-based ordered position.
     * @param   array<string, mixed>  $targetValues     Values used only to create an owned line.
     *
     * @return  array<string, mixed>  Omission-safe mutation result or an identical replay.
     *
     * @since   2.0.0
     */
    public function relateBusinessRecords(
        string $operationId,
        string $plan,
        string $definition,
        string $record,
        int $expectedVersion,
        string $relationship,
        string $target,
        ?int $position = null,
        array $targetValues = [],
    ): array {
        return $this->businessRecords->relate(
            $this->businessMutationContext($operationId, 'relate'),
            $operationId,
            $plan,
            $definition,
            $record,
            $expectedVersion,
            $relationship,
            $target,
            $position,
            $targetValues,
        );
    }

    /**
     * Remove one declared generated-record relationship link.
     *
     * @param   string  $operationId      Caller-chosen stable operation identity.
     * @param   string  $plan             Signed plan for these exact mutation arguments.
     * @param   string  $definition       Definition UUID or namespaced handle.
     * @param   string  $record           Public source-record identity.
     * @param   int     $expectedVersion  Optimistic source version previously read.
     * @param   string  $relationship     Declared relationship handle.
     * @param   string  $target           Public target identity.
     *
     * @return  array<string, mixed>  Omission-safe mutation result or an identical replay.
     *
     * @since   2.0.0
     */
    public function unrelateBusinessRecords(
        string $operationId,
        string $plan,
        string $definition,
        string $record,
        int $expectedVersion,
        string $relationship,
        string $target,
    ): array {
        return $this->businessRecords->unrelate(
            $this->businessMutationContext($operationId, 'unrelate'),
            $operationId,
            $plan,
            $definition,
            $record,
            $expectedVersion,
            $relationship,
            $target,
        );
    }

    /**
     * Replace the complete order of one declared relationship.
     *
     * @param   string        $operationId       Caller-chosen stable operation identity.
     * @param   string        $plan              Signed plan for these exact mutation arguments.
     * @param   string        $definition        Definition UUID or namespaced handle.
     * @param   string        $record            Public source-record identity.
     * @param   int           $expectedVersion   Optimistic source version previously read.
     * @param   string        $relationship      Declared ordered relationship handle.
     * @param   list<string>  $orderedRecordIds  Complete target identities in their new order.
     *
     * @return  array<string, mixed>  Omission-safe mutation result or an identical replay.
     *
     * @since   2.0.0
     */
    public function reorderBusinessRecords(
        string $operationId,
        string $plan,
        string $definition,
        string $record,
        int $expectedVersion,
        string $relationship,
        array $orderedRecordIds,
    ): array {
        return $this->businessRecords->reorder(
            $this->businessMutationContext($operationId, 'reorder'),
            $operationId,
            $plan,
            $definition,
            $record,
            $expectedVersion,
            $relationship,
            $orderedRecordIds,
        );
    }

    /**
     * Request independent maker-checker approval for one high-impact action attempt.
     *
     * This surface publishes no vote, approve, reject, or step-up proof method.
     *
     * @param   string                $operationId      Caller-chosen stable operation identity.
     * @param   string                $plan             Signed plan for these exact mutation arguments.
     * @param   string                $definition       Definition UUID or namespaced handle.
     * @param   string                $record           Public record identity.
     * @param   int                   $expectedVersion  Optimistic version previously read.
     * @param   string                $action           Declared high-impact action handle.
     * @param   array<string, mixed>  $input            Typed action input, empty for current core actions.
     *
     * @return  array{approval_request_id: ?string}  Newly created approval identity.
     *
     * @since   2.0.0
     */
    public function requestBusinessRecordAction(
        string $operationId,
        string $plan,
        string $definition,
        string $record,
        int $expectedVersion,
        string $action,
        array $input = [],
    ): array {
        $result = $this->businessRecords->requestAction(
            $this->businessMutationContext($operationId, 'request_action'),
            $operationId,
            $plan,
            $definition,
            $record,
            $expectedVersion,
            $action,
            $input,
        );
        $requestId = $result['approval_request_id'] ?? null;
        if (
            array_keys($result) !== ['approval_request_id']
            || ($requestId !== null && !is_string($requestId))
        ) {
            throw new InvalidArgumentException('The business-record approval result is invalid.');
        }

        return ['approval_request_id' => $requestId];
    }

    /**
     * Execute one ordinary declared action; a high-impact attempt fails closed without browser step-up.
     *
     * @param   string                $operationId        Caller-chosen stable operation identity.
     * @param   string                $plan               Signed plan for these exact mutation arguments.
     * @param   string                $definition         Definition UUID or namespaced handle.
     * @param   string                $record             Public record identity.
     * @param   int                   $expectedVersion    Optimistic version previously read.
     * @param   string                $action             Declared action handle.
     * @param   array<string, mixed>  $input              Typed action input.
     * @param   ?string               $approvalRequestId  Independent approval UUID when required.
     *
     * @return  array<string, mixed>  Omission-safe mutation result or an identical replay.
     *
     * @since   2.0.0
     */
    public function executeBusinessRecordAction(
        string $operationId,
        string $plan,
        string $definition,
        string $record,
        int $expectedVersion,
        string $action,
        array $input = [],
        ?string $approvalRequestId = null,
    ): array {
        return $this->businessRecords->executeAction(
            $this->businessMutationContext($operationId, 'execute_action'),
            $operationId,
            $plan,
            $definition,
            $record,
            $expectedVersion,
            $action,
            $input,
            $approvalRequestId,
        );
    }

    /**
     * Inspect a completed generated-business mutation owned by this exact actor and policy context.
     *
     * @param   string  $operationId  Identity used for the original generated-business mutation.
     *
     * @return  array<string, mixed>  Caller-bound status and omission-safe mutation result.
     *
     * @throws  InsufficientCapability  When the credential cannot read business records.
     *
     * @since   2.0.0
     */
    public function businessRecordOperationStatus(string $operationId): array
    {
        $this->require('business.record.read');

        return $this->businessRecords->operationStatus($this->context(), $operationId);
    }

    /**
     * Business definition and schema tools.
     *
     * These read and drive exactly the services the REST routes and console commands use.
     * Composing a destructive purge plan is deliberately absent: it requires re-proving a
     * current password, which an agent surface must not be able to satisfy.
     */

    /**
     * List where every business entity definition in this site stands.
     *
     * The catalogue heads are flattened into plain rows here, so one call answers the question an agent
     * actually has. A non-zero `draft_revision` means unpublished work is waiting and is the token the next
     * write has to quote; a null `published_version` means the handle has never served anything.
     *
     * @return  array{items: list<array<string, mixed>>}  One row per handle under `items`, carrying its
     *          identifier, handle, site, owner and owner liveness, draft revision, published version and status.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `content.read`.
     *
     * @since   2.0.0
     */
    public function listBusinessDefinitions(): array
    {
        $this->require('content.read');
        $items = [];
        foreach ($this->definitions->catalog($this->context()) as $entry) {
            $items[] = [
                'id' => $entry->id,
                'handle' => $entry->handle,
                'site' => $entry->siteIdentifier,
                'owner' => $entry->owner->toArray(),
                'owner_active' => $entry->ownerActive,
                'draft_revision' => $entry->draftRevision,
                'published_version' => $entry->publishedVersion,
                'status' => $entry->status->value,
            ];
        }

        return ['items' => $items];
    }

    /**
     * Read one published version of a business entity definition.
     *
     * @param   string  $handle   The definition's handle, or its UUID.
     * @param   ?int    $version  Published version to load, or null for the one the catalogue head serves.
     *
     * @return  array<string, mixed>  The definition document with its version, status, checksum, publisher,
     *          publication instant and the compatibility plan that produced it.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `content.read`.
     *
     * @since   2.0.0
     */
    public function getBusinessDefinition(string $handle, ?int $version = null): array
    {
        $this->require('content.read');

        return $this->definitionVersion($this->definitions->published($this->context(), $handle, $version));
    }

    /**
     * Read the working draft of a business entity definition.
     *
     * The draft is where unpublished edits live, and its revision is the number `publishBusinessDefinition()`
     * has to be given, so this is the call that precedes a publication.
     *
     * @param   string  $handle  The definition's handle, or its UUID.
     *
     * @return  array<string, mixed>  The draft's revision, checksum, last editor and edit instant, plus the
     *          definition document itself under `definition`.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `content.read`.
     *
     * @since   2.0.0
     */
    public function getBusinessDefinitionDraft(string $handle): array
    {
        $this->require('content.read');
        $draft = $this->definitions->draft($this->context(), $handle);

        return [
            'revision' => $draft->revision,
            'checksum' => $draft->checksum,
            'updated_by' => $draft->updatedBy,
            'updated_at' => $draft->updatedAt->format(DATE_ATOM),
            'definition' => $draft->definition->toArray(),
        ];
    }

    /**
     * List every version of one definition that was ever published.
     *
     * @param   string  $handle  The definition's handle, or its UUID.
     *
     * @return  array{items: list<array<string, mixed>>}  Versions under `items`, newest first; empty when the
     *          definition exists but has never been published.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `content.read`.
     *
     * @since   2.0.0
     */
    public function listBusinessDefinitionHistory(string $handle): array
    {
        $this->require('content.read');

        return ['items' => array_map(
            $this->definitionVersion(...),
            $this->definitions->history($this->context(), $handle),
        )];
    }

    /**
     * Price what publishing the current draft would do, without recording that the question was asked.
     *
     * Read-only and unaudited, so an agent may ask it freely before deciding whether publication is safe. The
     * plan it returns is what `publishBusinessDefinition()` would demand confirmation for.
     *
     * @param   string  $handle  The definition's handle, or its UUID.
     *
     * @return  array<string, mixed>  Every classified difference between the published head and the draft.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `content.read`.
     *
     * @since   2.0.0
     */
    public function previewBusinessDefinitionCompatibility(string $handle): array
    {
        $this->require('content.read');

        return $this->definitions->previewDraft($this->context(), $handle)->toArray();
    }

    /**
     * Publish the working draft as a new immutable definition version.
     *
     * The revision is the concurrency check: publication is refused if the draft moved after the caller read
     * it, so an agent cannot publish edits it never saw. A plan carrying breaking changes is refused as well
     * unless `$confirmed` is set, which is the point at which a client must have shown the compatibility
     * preview to whoever is accountable for it.
     *
     * @param   string  $operationId       Idempotency key this write is fenced on.
     * @param   string  $handle            The definition's handle, or its UUID.
     * @param   int     $expectedRevision  Draft revision being published, as the caller last read it.
     * @param   bool    $confirmed         Whether the caller accepts a plan that carries breaking changes.
     *
     * @return  array<string, mixed>  The stored version with its checksum, publisher, publication instant and
     *          compatibility plan.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `content.update`.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses `content.update` on the
     *          business definition collection.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function publishBusinessDefinition(
        string $operationId,
        string $handle,
        int $expectedRevision,
        bool $confirmed = false,
    ): array {
        $this->require('content.update');
        $this->preauthorize($operationId, 'content.update', AuthorizationResource::collection('business_definition'));

        return $this->mutations->run(
            $this->context($operationId),
            'business_definition.publish',
            $operationId,
            ['handle' => $handle, 'expectedRevision' => $expectedRevision, 'confirmed' => $confirmed],
            fn (): array => $this->definitionVersion($this->definitions->publish(
                $this->context($operationId),
                $handle,
                $expectedRevision,
                $confirmed,
            )),
        );
    }

    /**
     * List the published definitions a schema plan can be compiled for.
     *
     * @return  array{items: list<array<string, mixed>>}  Plannable definitions under `items`, each with its
     *          identifier, handle, version and owner.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `business.schema.read`.
     *
     * @since   2.0.0
     */
    public function listSchemaDefinitions(): array
    {
        $this->require('business.schema.read');

        return ['items' => $this->schema->definitions($this->context())];
    }

    /**
     * List this site's schema plans, each with the checksum an approval has to quote.
     *
     * @return  array{items: list<array<string, mixed>>}  Plans under `items`, most recently created first.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `business.schema.read`.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When a plan holds more than 512
     *          operations, which the canonical encoder refuses to fingerprint.
     *
     * @since   2.0.0
     */
    public function listSchemaPlans(): array
    {
        $this->require('business.schema.read');

        return ['items' => array_map($this->schemaPlan(...), $this->schema->plans($this->context()))];
    }

    /**
     * Read one schema plan together with its durable step journal.
     *
     * The journal is what makes an interrupted execution recoverable: it records which operations actually
     * landed, so read it before deciding whether `recoverSchemaPlan()` is the right next call.
     *
     * @param   string  $planId  UUID of the plan to read.
     *
     * @return  array<string, mixed>  The plan and its checksum, plus one `steps` entry per operation in
     *          ordinal order.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `business.schema.read`.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When the plan holds more than 512
     *          operations, which the canonical encoder refuses to fingerprint.
     *
     * @since   2.0.0
     */
    public function getSchemaPlan(string $planId): array
    {
        $this->require('business.schema.read');
        $context = $this->context();

        return [
            ...$this->schemaPlan($this->schema->plan($context, $planId)),
            'steps' => array_map(
                static fn (SchemaPlanStep $step): array => $step->toArray(),
                $this->schema->steps($context, $planId),
            ),
        ];
    }

    /**
     * Compile a deterministic schema plan for a published definition.
     *
     * Runs no DDL. It records what would be done and returns the checksum an approver has to quote back, which
     * is what separates inspecting a change from authorising it.
     *
     * @param   string  $operationId   Idempotency key this write is fenced on.
     * @param   string  $definitionId  UUID of the published definition to plan against.
     *
     * @return  array<string, mixed>  The proposed plan, carrying the checksum an approval must match.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `business.schema.plan`.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses `business.schema.plan` on
     *          the schema collection.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function createSchemaPlan(string $operationId, string $definitionId): array
    {
        $this->require('business.schema.plan');
        $this->preauthorize($operationId, 'business.schema.plan', AuthorizationResource::collection('business_schema'));

        return $this->mutations->run(
            $this->context($operationId),
            'business_schema.plan',
            $operationId,
            ['definitionId' => $definitionId],
            fn (): array => $this->schemaPlan($this->schema->createPlan($this->context($operationId), $definitionId)),
        );
    }

    /**
     * Approve the exact plan that was inspected, identified by its checksum.
     *
     * The service's confirmation argument is deliberately never passed from here. Anything riskier than an
     * online-safe-additive plan has to quote its checksum a second time as a confirmation digested against
     * the approver's own authorization fingerprint, and this surface supplies none — so a high-impact plan
     * fails closed at this call rather than becoming approvable by anything holding a token. The expected
     * checksum is the other half: an approval is refused outright if the plan moved after it was read.
     *
     * @param   string   $operationId         Idempotency key this write is fenced on.
     * @param   string   $planId              UUID of the plan being approved.
     * @param   string   $expectedChecksum    Checksum of the plan as inspected; a mismatch refuses the approval.
     * @param   ?string  $recoveryEvidenceId  Recovery drill a rebuilding or destructive plan is approved on
     *          the strength of; null when the plan needs none, and naming one a plan does not need is refused.
     *
     * @return  array<string, mixed>  The plan in its approved state, at the revision the approval wrote.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `business.schema.approve`.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses `business.schema.approve`,
     *          or `business.schema.destructive` for a destructive plan, on this plan.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function approveSchemaPlan(
        string $operationId,
        string $planId,
        string $expectedChecksum,
        ?string $recoveryEvidenceId = null,
    ): array {
        $this->require('business.schema.approve');
        $this->preauthorize(
            $operationId,
            'business.schema.approve',
            AuthorizationResource::item('business_schema_plan', $planId),
        );

        // No confirmation is passed: a high-impact plan needs a re-proved password, which
        // this surface cannot supply, so such a plan fails closed here by design.
        return $this->mutations->run(
            $this->context($operationId),
            'business_schema.approve',
            $operationId,
            ['planId' => $planId, 'expectedChecksum' => $expectedChecksum],
            fn (): array => $this->schemaPlan($this->schema->approve(
                $this->context($operationId),
                $planId,
                $expectedChecksum,
                null,
                $recoveryEvidenceId,
            )),
        );
    }

    /**
     * Apply an approved schema plan to the physical tables.
     *
     * This is the call that changes physical tables. Ordinary failures propagate untouched; the one case the
     * service handles itself is a first-time set of definitions that reference each other, where the initial
     * plan pauses on a peer's table that does not exist yet and the connected peers are executed or resumed
     * before the requested plan is. What lands is journalled step by step, which is what leaves an interrupted
     * run recoverable through `recoverSchemaPlan()` rather than merely broken.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $planId       UUID of the approved plan to execute.
     *
     * @return  array<string, mixed>  The outcome: the fence taken, the completed and skipped step counts, and
     *          the resulting schema checksum.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `business.schema.execute`.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses `business.schema.execute`,
     *          or `business.schema.destructive` for a destructive plan, on this plan.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function executeSchemaPlan(string $operationId, string $planId): array
    {
        $this->require('business.schema.execute');
        $this->preauthorize(
            $operationId,
            'business.schema.execute',
            AuthorizationResource::item('business_schema_plan', $planId),
        );

        return $this->mutations->run(
            $this->context($operationId),
            'business_schema.execute',
            $operationId,
            ['planId' => $planId],
            fn (): array => $this->schema->execute($this->context($operationId), $planId)->toArray(),
        );
    }

    /**
     * Resume or reconcile a schema plan whose execution was interrupted.
     *
     * Recovery reads the journal rather than starting over: operations already recorded as landed are skipped,
     * so re-running is safe. A plan that was never interrupted is refused, which stops this being used as a
     * second execute.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $planId       UUID of the executing, failed or recovery-required plan.
     *
     * @return  array<string, mixed>  The same outcome shape as a first run, marked as resumed.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `business.schema.recover`.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses `business.schema.recover`
     *          on this plan.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function recoverSchemaPlan(string $operationId, string $planId): array
    {
        $this->require('business.schema.recover');
        $this->preauthorize(
            $operationId,
            'business.schema.recover',
            AuthorizationResource::item('business_schema_plan', $planId),
        );

        return $this->mutations->run(
            $this->context($operationId),
            'business_schema.recover',
            $operationId,
            ['planId' => $planId],
            fn (): array => $this->schema->recover($this->context($operationId), $planId)->toArray(),
        );
    }

    /**
     * Flatten a published definition version into the map the definition tools return.
     *
     * One projection shared by the read, history and publish tools, so a client sees the same keys whichever
     * of them produced the version.
     *
     * @param   DefinitionVersionRecord  $record  Stored version with its compatibility plan and publisher.
     *
     * @return  array<string, mixed>  Version number, status, checksum, publisher, publication instant,
     *          compatibility plan and the definition document itself.
     *
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When the definition cannot be
     *          canonically encoded, so no checksum can be computed for it.
     *
     * @since   2.0.0
     */
    private function definitionVersion(DefinitionVersionRecord $record): array
    {
        return [
            'version' => $record->definition->definitionVersion,
            'status' => $record->status->value,
            'checksum' => $record->definition->checksum(),
            'published_by' => $record->publishedBy,
            'published_at' => $record->publishedAt->format(DATE_ATOM),
            'compatibility' => $record->compatibility->toArray(),
            'definition' => $record->definition->toArray(),
        ];
    }

    /**
     * Serialise a plan together with the checksum an approval has to quote back.
     *
     * The checksum is not a stored column: it is recomputed from the canonical form on every read, which is
     * what lets a client prove that what it approves is byte-for-byte what it inspected.
     *
     * @param   SchemaPlan  $plan  Plan to serialise.
     *
     * @return  array<string, mixed>  The plan's own fields plus its `checksum`.
     *
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When the plan holds more than 512
     *          operations, which the canonical encoder refuses to fingerprint.
     *
     * @since   2.0.0
     */
    private function schemaPlan(SchemaPlan $plan): array
    {
        return [...$plan->toArray(), 'checksum' => $plan->checksum()];
    }

    /**
     * Execute one active contributed report through the shared policy-filtered report service.
     *
     * @param   string                $report      Namespaced active report identifier.
     * @param   array<string, mixed>  $parameters  Typed values keyed by declared parameter name.
     *
     * @return  array<string, mixed>  Bounded omission-safe report result.
     *
     * @since   2.0.0
     */
    public function executeBusinessReport(string $report, array $parameters = []): array
    {
        $this->require('business.record.report');

        return $this->businessReports->execute($this->context(), $report, $parameters);
    }

    /**
     * List active contributed reports visible to the bound MCP credential.
     *
     * @return  array{items: list<array<string, mixed>>}  Safe typed report summaries.
     *
     * @since   2.0.0
     */
    public function listBusinessReports(): array
    {
        $this->require('business.record.report');

        return $this->businessReports->list($this->context());
    }

    /**
     * Idempotently create one durable report export under the caller's exact authority snapshot.
     *
     * @param   string                $operationId       Stable MCP idempotency identity.
     * @param   string                $report            Namespaced active report identifier.
     * @param   array<string, mixed>  $parameters        Typed values keyed by declared parameter name.
     * @param   int                   $retentionSeconds  Artifact lifetime from one minute through seven days.
     *
     * @return  array<string, mixed>  Queued export lifecycle metadata or its replay.
     *
     * @since   2.0.0
     */
    public function requestBusinessReportExport(
        string $operationId,
        string $report,
        array $parameters = [],
        int $retentionSeconds = 86_400,
    ): array {
        $this->require('business.record.export');
        $this->preauthorize(
            $operationId,
            'business.record.export',
            AuthorizationResource::collection('business_report'),
        );

        return $this->mutations->run(
            $this->context($operationId),
            'business.report.export.request',
            $operationId,
            compact('report', 'parameters', 'retentionSeconds'),
            fn (): array => $this->businessReports->requestExport(
                $this->context($operationId),
                $report,
                $parameters,
                $retentionSeconds,
            ),
        );
    }

    /**
     * Read current authorized lifecycle metadata for one export.
     *
     * @param   string  $artifact  Export artifact UUID.
     *
     * @return  array<string, mixed>  Current safe export status.
     *
     * @since   2.0.0
     */
    public function businessReportExportStatus(string $artifact): array
    {
        $this->require('business.record.export');

        return $this->businessReports->exportStatus($this->context(), $artifact);
    }

    /**
     * Download one completed verified export within the MCP inline-size ceiling.
     *
     * @param   string  $artifact  Completed export artifact UUID.
     *
     * @return  array<string, mixed>  Base64 artifact bytes and checksum metadata.
     *
     * @since   2.0.0
     */
    public function downloadBusinessReportExport(string $artifact): array
    {
        $this->require('business.record.export');

        return $this->businessReports->downloadExport($this->context(), $artifact);
    }

    /**
     * Resolve, capability-check, resource-authorize, and bind one generated-business mutation context.
     *
     * The generic delegate still enforces definition exposure and row policy, while this outer handler
     * records the coarse collection decision before either idempotency ledger is entered.
     *
     * @param   string  $operationId  Caller-chosen stable operation identity.
     * @param   string  $operation    Closed generated-business mutation name.
     *
     * @return  ExecutionContext  MCP child context carrying the same operation identity.
     *
     * @throws  InsufficientCapability  When the credential lacks the operation's capability.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses the collection write.
     * @throws  InvalidArgumentException  When the operation or operation identity is invalid.
     *
     * @since   2.0.0
     */
    private function businessMutationContext(string $operationId, string $operation): ExecutionContext
    {
        $capability = BusinessMcpHandlers::capabilityFor($operation);
        $this->require($capability);
        $this->preauthorize(
            $operationId,
            $capability,
            AuthorizationResource::collection('business_record'),
        );

        return $this->context($operationId);
    }

    /**
     * Fail unless the bound credential resolves to a principal holding a capability.
     *
     * The first line of every protected tool, and deliberately earlier and cheaper than the gateway: it asks
     * whether the caller holds the capability at all, before any resource has been named or any work started.
     * It is a floor, not a substitute for `preauthorize()` — holding a capability is not permission to
     * exercise it on a particular record.
     *
     * @param   string  $capability  Capability code the tool needs, such as `content.read`.
     *
     * @return  AuthenticatedPrincipal  The resolved actor, once it is known to hold the capability.
     *
     * @throws  InsufficientCapability  When no principal is bound, or the principal lacks the capability.
     * @throws  InvalidArgumentException  When the code is not a valid capability identifier.
     *
     * @since   2.0.0
     */
    private function require(string $capability): AuthenticatedPrincipal
    {
        $principal = $this->principal();
        $value = Capability::fromString($capability);
        if (!$principal->hasCapability($value)) {
            throw new InsufficientCapability($capability);
        }

        return $principal;
    }

    /**
     * Resolve the human actor the bound identity currently authenticates.
     *
     * @return  AuthenticatedPrincipal  The actor these handlers are acting as.
     *
     * @throws  InsufficientCapability  When no context is bound, or the bound context carries no human
     *          principal — a system context authorizes nothing on this surface.
     *
     * @since   2.0.0
     */
    private function principal(): AuthenticatedPrincipal
    {
        return AuthenticatedPrincipal::of($this->context())
            ?? throw new InsufficientCapability('authenticated');
    }

    /**
     * Resolve the execution context this call runs under, re-proving a retained credential first.
     *
     * The resident extension generation is proven before even the retained credential is refreshed. That
     * makes the common boundary cover both HTTP and long-lived stdio MCP transports: once replacement,
     * withdrawal or trust revocation advances authority, no subsequent protected tool or resource can enter
     * an application service through a handler object built from the old graph.
     *
     * A retained credential takes precedence over a bound context and is re-read on every call rather than
     * cached, which is what makes a revoked stdio token stop the very next tool call. Passing an operation
     * identifier returns a child context carrying it as the request identifier, so the authorization decision,
     * the idempotency claim and the audit record of one tool call all tie together.
     *
     * @param   ?string  $operationId  Operation identifier to derive a child context from, or null for the
     *          bound context itself.
     *
     * @return  ExecutionContext  The context to authorize and audit this call under.
     *
     * @throws  InsufficientCapability  When nothing is bound, or the retained credential no longer verifies.
     * @throws  InvalidArgumentException  When the operation identifier cannot serve as a request identifier.
     * @throws  \RuntimeException  When this handler graph belongs to a superseded extension generation.
     *
     * @since   2.0.0
     */
    private function context(?string $operationId = null): ExecutionContext
    {
        $this->extensionRuntime?->assertCurrent();
        $context = $this->contextRefresh !== null
            ? ($this->contextRefresh)()
            : $this->executionContext;
        if (!$context instanceof ExecutionContext) {
            throw new InsufficientCapability('authenticated');
        }

        return $operationId === null
            ? $context
            : $context->child('mcp-' . $operationId, $operationId);
    }

    /**
     * Require the gateway's approval for a write before the mutation fence is entered.
     *
     * Where `require()` only proves the caller holds a capability, this asks whether it may exercise that
     * capability on this particular resource, and the decision is recorded before it is acted on. It runs
     * ahead of `McpMutationGuard`, so a refused call claims no idempotency key and leaves nothing to clean up.
     *
     * @param   string                 $operationId  Operation identifier the child context is derived from, so
     *          the decision is recorded against the same request as the write.
     * @param   string                 $action       Capability code being exercised.
     * @param   AuthorizationResource  $resource     Collection or item the action is aimed at.
     *
     * @return  void
     *
     * @throws  InsufficientCapability  When no principal is bound to these handlers.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses this actor the action on
     *          this resource.
     * @throws  InvalidArgumentException  When the code is not a valid capability identifier, or the operation
     *          identifier cannot serve as a request identifier.
     *
     * @since   2.0.0
     */
    private function preauthorize(string $operationId, string $action, AuthorizationResource $resource): void
    {
        $this->authorization->assertAllowed(
            $this->context($operationId),
            Capability::fromString($action),
            $resource,
        );
    }

    /**
     * Run a trust-key write under the extension lifecycle lock and the idempotency fence.
     *
     * The advisory lifecycle lock intentionally surrounds the complete mutation guard. This keeps the lock held
     * through the guard's outer transaction commit or rollback while nested TrustStore calls re-enter it safely.
     *
     * @template TResult of array<string, mixed>
     *
     * @param   ExecutionContext      $context      Actor the write is authorized and audited as.
     * @param   string                $operation    Operation name recorded for the write, under an `mcp.` prefix.
     * @param   string                $operationId  Idempotency key this write is fenced on.
     * @param   array<string, mixed>  $input        Arguments recorded with the claim and hashed, so reusing the
     *          identifier with different arguments is refused rather than replayed.
     * @param   callable(): TResult   $mutation     The trust-store write to perform, invoked at most once per
     *          identifier and from inside the fenced transaction.
     *
     * @return  TResult  Whatever the write returned on its first run, or the stored copy on a repeat.
     *
     * @since   2.0.0
     */
    private function runTrustMutation(
        ExecutionContext $context,
        string $operation,
        string $operationId,
        array $input,
        callable $mutation,
    ): array {
        return $this->trust->synchronizedLifecycle(
            fn (): array => $this->mutations->run($context, $operation, $operationId, $input, $mutation),
        );
    }

    /**
     * Run an extension lifecycle write under the same lock and fence as a trust mutation.
     *
     * Extension lifecycle changes and trust-key changes share one installation-wide lock, so at most one of
     * them is in flight across the installation and an activation cannot run while the key set it is verified
     * against is moving. As in `runTrustMutation()`, the lock encloses the whole guard, so it is still held
     * when the guard's transaction commits or rolls back.
     *
     * @template TResult of array<string, mixed>
     *
     * @param   ExecutionContext      $context      Actor the write is authorized and audited as.
     * @param   string                $operation    Operation name recorded for the write, under an `mcp.` prefix.
     * @param   string                $operationId  Idempotency key this write is fenced on.
     * @param   array<string, mixed>  $input        Arguments recorded with the claim and hashed, so reusing the
     *          identifier with different arguments is refused rather than replayed.
     * @param   callable(): TResult   $mutation     The lifecycle write to perform, invoked at most once per
     *          identifier and from inside the fenced transaction.
     *
     * @return  TResult  Whatever the write returned on its first run, or the stored copy on a repeat.
     *
     * @since   2.0.0
     */
    private function runExtensionMutation(
        ExecutionContext $context,
        string $operation,
        string $operationId,
        array $input,
        callable $mutation,
    ): array {
        return $this->trust->synchronizedLifecycle(
            fn (): array => $this->mutations->run($context, $operation, $operationId, $input, $mutation),
        );
    }
}
