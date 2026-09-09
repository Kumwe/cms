<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Authoring;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthenticatedSurface;
use Kumwe\App\Application\Authorization\AuthenticationStrength;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\Application\Authorization\MembershipContext;
use Kumwe\App\Application\Authorization\SiteContext;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Content\Application\ContentModelRepository;
use Kumwe\App\Content\Application\ContentModelService;
use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Application\ContentRepository;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Content\Domain\ContentEntry;
use Kumwe\App\Content\Domain\ContentStatus;
use Kumwe\App\Content\Domain\ContentTypeDefinition;
use Kumwe\App\Content\Domain\JsonSchemaValidator;
use Kumwe\App\Content\Domain\PublicationWindow;
use Kumwe\App\Content\Domain\SchemaCompatibilityChecker;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextAuthority;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextBinding;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextRefused;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextRepository;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextStale;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringTarget;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringTargetResolver;
use Kumwe\App\Studio\Application\Host\StudioResourceContextKeyFactory;
use Kumwe\App\Studio\Domain\Authoring\StudioAuthoringIntent;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\ImmediateTransactionManager;
use Kumwe\App\Workflow\Domain\Workflow;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Proves contextual Content authoring keys remain non-bearer, exact, and freshly authorized.
 *
 * @since  2.0.0
 */
#[CoversClass(ContentStudioAuthoringContextAuthority::class)]
#[CoversClass(ContentStudioAuthoringContextBinding::class)]
#[CoversClass(ContentStudioAuthoringContextRefused::class)]
#[CoversClass(ContentStudioAuthoringContextStale::class)]
#[UsesClass(ContentStudioAuthoringTargetResolver::class)]
#[UsesClass(ContentStudioAuthoringTarget::class)]
#[UsesClass(ContentModelService::class)]
#[UsesClass(ContentService::class)]
#[UsesClass(ContentTypeDefinition::class)]
#[UsesClass(ContentEntry::class)]
#[UsesClass(ContentRecord::class)]
#[UsesClass(JsonSchemaValidator::class)]
#[UsesClass(SchemaCompatibilityChecker::class)]
final class ContentStudioAuthoringContextAuthorityTest extends TestCase
{
    /**
     * Stable reusable Content-type identifier used by exact-target scenarios.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string TYPE_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026be810';

    /**
     * Stable Content-entry identifier used by edit-target scenarios.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string ENTRY_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026be820';

    /**
     * Blank create contexts persist only one-way authority and session bindings behind an opaque key.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBlankCreateOpensAndResolvesOnlyThroughTheSameTrustedSession(): void
    {
        [$authority, $contexts] = $this->authority(
            $this->createStub(ContentModelRepository::class),
            $this->createStub(ContentRepository::class),
        );
        $context = self::context(['content.create']);
        $target = (new ContentStudioAuthoringTargetResolver(AuthorizationContext::gateway()))->create($context);

        $key = $authority->open($context, $target);
        $binding = $contexts->find($key);

        self::assertMatchesRegularExpression('/^contexts\/[a-f0-9]{64}$/D', $key);
        self::assertInstanceOf(ContentStudioAuthoringContextBinding::class, $binding);
        self::assertSame($target->toArray(), $binding->target->toArray());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $binding->sessionBinding);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $binding->authorityBinding);
        self::assertSame($target->toArray(), $authority->resolve($context, $key)->toArray());
        $secondKey = $authority->open($context, $target);
        self::assertNotSame($key, $secondKey);
        self::assertInstanceOf(ContentStudioAuthoringContextBinding::class, $contexts->find($secondKey));
    }

    /**
     * Exact reusable-type bindings reload the immutable version and reject malformed or vanished revisions.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTypedCreateRevalidatesTheExactModelVersionAndRevision(): void
    {
        $definition = self::definition();
        $models = $this->createMock(ContentModelRepository::class);
        $models->expects(self::exactly(2))
            ->method('contentType')
            ->with(self::callback(self::isDefaultSite(...)), self::TYPE_ID, 3)
            ->willReturnOnConsecutiveCalls($definition, null);
        [$authority] = $this->authority($models, $this->createStub(ContentRepository::class));
        $context = self::context(['content.create', 'content.read']);
        $target = (new ContentStudioAuthoringTargetResolver(AuthorizationContext::gateway()))->create(
            $context,
            $definition,
        );
        $key = $authority->open($context, $target);

        try {
            $authority->resolve($context, $key);
            self::fail('A removed exact Content-type version must invalidate its context.');
        } catch (ContentStudioAuthoringContextRefused $refused) {
            self::assertSame('The Studio Content authoring context was refused.', $refused->getMessage());
        }

        [$malformedAuthority] = $this->authority(
            $this->createStub(ContentModelRepository::class),
            $this->createStub(ContentRepository::class),
        );
        $malformed = new ContentStudioAuthoringTarget(
            StudioAuthoringIntent::Create,
            $target->modelId,
            $target->modelVersion,
            'content-type-v999',
            null,
            null,
            $target->returnPath,
        );
        try {
            $malformedAuthority->open($context, $malformed);
            self::fail('A forged Content-type revision must be refused.');
        } catch (ContentStudioAuthoringContextRefused $refused) {
            self::assertSame('The Studio Content authoring context was refused.', $refused->getMessage());
            self::assertStringNotContainsString('content-type-v999', $refused->getMessage());
        }

        $programmingFailure = $this->createStub(ContentModelRepository::class);
        $programmingFailure->method('contentType')->willThrowException(new LogicException('unexpected failure'));
        [$strictAuthority] = $this->authority(
            $programmingFailure,
            $this->createStub(ContentRepository::class),
        );
        try {
            $strictAuthority->open($context, $target);
            self::fail('An unrelated Content logic failure must not be disguised as a context refusal.');
        } catch (LogicException $exception) {
            self::assertSame('unexpected failure', $exception->getMessage());
        }
    }

    /**
     * Concurrent Entry revision changes become an authorized stale result while deletion remains undisclosed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEditRevisionDriftIsStaleButADeletedEntryIsRefused(): void
    {
        $definition = self::definition();
        $versionSeven = self::record(7);
        $versionEight = self::record(8);
        $models = $this->createStub(ContentModelRepository::class);
        $models->method('contentType')->willReturn($definition);
        $content = $this->createMock(ContentRepository::class);
        $content->expects(self::exactly(2))
            ->method('find')
            ->with(self::ENTRY_ID, false)
            ->willReturnOnConsecutiveCalls($versionSeven, $versionEight);
        [$authority] = $this->authority($models, $content);
        $context = self::context(['content.read', 'content.update']);
        $target = (new ContentStudioAuthoringTargetResolver(AuthorizationContext::gateway()))->edit(
            $context,
            $versionSeven,
            $definition,
        );
        $key = $authority->open($context, $target);

        try {
            $authority->resolve($context, $key);
            self::fail('A moved Entry revision must make the exact context stale.');
        } catch (ContentStudioAuthoringContextStale $stale) {
            self::assertSame('content-entry-v8', $stale->current->entryRevision);
            self::assertSame('content-entry:' . self::ENTRY_ID, $stale->current->entryId);
        }

        $retargetedContent = $this->createMock(ContentRepository::class);
        $retargetedContent->expects(self::exactly(2))
            ->method('find')
            ->with(self::ENTRY_ID, false)
            ->willReturnOnConsecutiveCalls($versionSeven, self::record(8, 4));
        [$retargetedAuthority] = $this->authority($models, $retargetedContent);
        $retargetedKey = $retargetedAuthority->open($context, $target);
        try {
            $retargetedAuthority->resolve($context, $retargetedKey);
            self::fail('A changed Entry Content-type pin must not be reported as ordinary revision drift.');
        } catch (ContentStudioAuthoringContextRefused $refused) {
            self::assertSame('The Studio Content authoring context was refused.', $refused->getMessage());
        }

        $deletedContent = $this->createMock(ContentRepository::class);
        $deletedContent->expects(self::exactly(2))
            ->method('find')
            ->with(self::ENTRY_ID, false)
            ->willReturnOnConsecutiveCalls($versionSeven, null);
        [$deletedAuthority] = $this->authority($models, $deletedContent);
        $deletedKey = $deletedAuthority->open($context, $target);

        $this->expectException(ContentStudioAuthoringContextRefused::class);
        $deletedAuthority->resolve($context, $deletedKey);
    }

    /**
     * Foreign identity scopes share one refusal while a freshly authorized approval change is stale.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testScopeSessionAndApprovalChangesCannotReuseAnOpaqueContext(): void
    {
        [$authority, $contexts] = $this->authority(
            $this->createStub(ContentModelRepository::class),
            $this->createStub(ContentRepository::class),
        );
        $base = self::context(['content.create']);
        $target = (new ContentStudioAuthoringTargetResolver(AuthorizationContext::gateway()))->create($base);
        $key = $authority->open($base, $target);
        $foreign = [
            'actor' => self::context(
                ['content.create'],
                subject: '018f22e2-7c8b-7ab0-8f3a-88e8026bb399',
            ),
            'site' => self::context(['content.create'], site: 'other-site'),
            'organization' => self::context(
                ['content.create'],
                membership: AuthorizationContext::membership('other'),
            ),
            'workspace' => self::context(
                ['content.create'],
                membership: AuthorizationContext::membership('acme', 'finance'),
            ),
            'surface' => self::context(['content.create'], surface: AuthenticatedSurface::Api),
            'session' => self::context(['content.create'], sessionId: 'administrator-session-other'),
        ];

        foreach ($foreign as $label => $context) {
            try {
                $authority->resolve($context, $key);
                self::fail($label . ' must not reuse the opaque context.');
            } catch (ContentStudioAuthoringContextRefused $refused) {
                self::assertSame('The Studio Content authoring context was refused.', $refused->getMessage());
                self::assertStringNotContainsString(self::TYPE_ID, $refused->getMessage());
            }
        }

        $changedApproval = self::context(['content.create'], epoch: 2);
        try {
            $authority->resolve($changedApproval, $key);
            self::fail('A changed approval generation must make the context stale after fresh authorization.');
        } catch (ContentStudioAuthoringContextStale $stale) {
            self::assertSame($target->toArray(), $stale->current->toArray());
        }
        self::assertInstanceOf(ContentStudioAuthoringContextBinding::class, $contexts->find($key));
    }

    /**
     * Hard expiry refuses a binding before any target state is read or disclosed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExpiredContextRefusesBeforeTheExactModelIsReadAgain(): void
    {
        $definition = self::definition();
        $models = $this->createMock(ContentModelRepository::class);
        $models->expects(self::once())
            ->method('contentType')
            ->with(self::callback(self::isDefaultSite(...)), self::TYPE_ID, 3)
            ->willReturn($definition);
        $clock = $this->createMock(ClockInterface::class);
        $clock->expects(self::exactly(2))
            ->method('now')
            ->willReturnOnConsecutiveCalls(
                new DateTimeImmutable('2026-08-27T00:00:00+00:00'),
                new DateTimeImmutable('2026-08-27T08:00:00+00:00'),
            );
        [$authority, $contexts] = $this->authority(
            $models,
            $this->createStub(ContentRepository::class),
            clock: $clock,
        );
        $context = self::context(['content.create', 'content.read']);
        $target = (new ContentStudioAuthoringTargetResolver(AuthorizationContext::gateway()))->create(
            $context,
            $definition,
        );
        $key = $authority->open($context, $target);
        $binding = $contexts->find($key);

        self::assertInstanceOf(ContentStudioAuthoringContextBinding::class, $binding);
        self::assertSame('2026-08-27T00:00:00+00:00', $binding->createdAt->format('c'));
        self::assertSame('2026-08-27T08:00:00+00:00', $binding->expiresAt->format('c'));
        $this->expectException(ContentStudioAuthoringContextRefused::class);
        $authority->resolve($context, $key);
    }

    /**
     * Permission withdrawal remains a non-disclosing refusal and malformed keys never reach persistence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPermissionWithdrawalAndMalformedKeysFailBeforeAuthorityCanBeInferred(): void
    {
        [$authority] = $this->authority(
            $this->createStub(ContentModelRepository::class),
            $this->createStub(ContentRepository::class),
        );
        $context = self::context(['content.create']);
        $target = (new ContentStudioAuthoringTargetResolver(AuthorizationContext::gateway()))->create($context);
        $key = $authority->open($context, $target);

        try {
            $authority->resolve(self::context(['content.read']), $key);
            self::fail('A withdrawn create permission must refuse the context.');
        } catch (ContentStudioAuthoringContextRefused $refused) {
            self::assertSame('The Studio Content authoring context was refused.', $refused->getMessage());
        }

        $noSessionRepository = $this->createMock(ContentStudioAuthoringContextRepository::class);
        $noSessionRepository->expects(self::never())->method('add');
        [$noSessionAuthority] = $this->authority(
            $this->createStub(ContentModelRepository::class),
            $this->createStub(ContentRepository::class),
            $noSessionRepository,
        );
        try {
            $noSessionAuthority->open(self::context(['content.create'], sessionId: null), $target);
            self::fail('A context must not open without an authenticated browser session.');
        } catch (ContentStudioAuthoringContextRefused $refused) {
            self::assertSame('The Studio Content authoring context was refused.', $refused->getMessage());
        }

        $repository = $this->createMock(ContentStudioAuthoringContextRepository::class);
        $repository->expects(self::never())->method('find');
        [$boundedAuthority] = $this->authority(
            $this->createStub(ContentModelRepository::class),
            $this->createStub(ContentRepository::class),
            $repository,
        );
        foreach (
            [
                '',
                'not-a-context',
                "contexts/" . str_repeat('a', 63) . "\0",
                'contexts/' . str_repeat('A', 64),
                'contexts/' . str_repeat('x', 241),
            ] as $malformed
        ) {
            try {
                $boundedAuthority->resolve($context, $malformed);
                self::fail('A malformed key must be refused before persistence.');
            } catch (ContentStudioAuthoringContextRefused $refused) {
                self::assertSame('The Studio Content authoring context was refused.', $refused->getMessage());
            }
        }
    }

    /**
     * Persisted bindings reject malformed digests, scope nesting, and incomplete intent coordinates.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBindingRejectsEveryMalformedSecurityAndTargetShape(): void
    {
        $blank = new ContentStudioAuthoringTarget(
            StudioAuthoringIntent::Create,
            null,
            null,
            null,
            null,
            null,
            '/administrator/content/new',
        );
        $incomplete = new ContentStudioAuthoringTarget(
            StudioAuthoringIntent::Edit,
            'content-model:' . self::TYPE_ID,
            '0.0.3',
            'content-type-v3',
            null,
            null,
            '/administrator/content/' . self::ENTRY_ID . '/edit',
        );
        $refusals = [
            'session digest' => [
                'not-a-digest',
                hash('sha256', 'authority'),
                null,
                null,
                $blank,
                'The Studio Content authoring session binding is invalid.',
            ],
            'authority digest' => [
                hash('sha256', 'session'),
                'not-a-digest',
                null,
                null,
                $blank,
                'The Studio Content authoring authority binding is invalid.',
            ],
            'workspace nesting' => [
                hash('sha256', 'session'),
                hash('sha256', 'authority'),
                null,
                'finance',
                $blank,
                'A Studio Content authoring workspace binding requires an organization binding.',
            ],
            'target shape' => [
                hash('sha256', 'session'),
                hash('sha256', 'authority'),
                null,
                null,
                $incomplete,
                'The Studio Content authoring target binding is incomplete.',
            ],
        ];

        foreach ($refusals as $label => [$session, $authority, $organization, $workspace, $target, $message]) {
            try {
                new ContentStudioAuthoringContextBinding(
                    'contexts/' . hash('sha256', 'malformed-' . $label),
                    AuthorizationContext::SUBJECT,
                    SiteContext::DEFAULT,
                    $organization,
                    $workspace,
                    AuthenticatedSurface::Administrator->value,
                    $session,
                    $authority,
                    $target,
                    new DateTimeImmutable('2026-08-27T00:00:00+00:00'),
                    new DateTimeImmutable('2026-08-27T08:00:00+00:00'),
                );
                self::fail($label . ' must be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage(), $label);
            }
        }

        try {
            new ContentStudioAuthoringContextBinding(
                'contexts/' . hash('sha256', 'malformed-expiry'),
                AuthorizationContext::SUBJECT,
                SiteContext::DEFAULT,
                null,
                null,
                AuthenticatedSurface::Administrator->value,
                hash('sha256', 'session'),
                hash('sha256', 'authority'),
                $blank,
                new DateTimeImmutable('2026-08-27T08:00:00+00:00'),
                new DateTimeImmutable('2026-08-27T08:00:00+00:00'),
            );
            self::fail('A non-positive context lifetime must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'The Studio Content authoring expiry must follow its creation.',
                $exception->getMessage(),
            );
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The Studio Content authoring lifetime is invalid.');
        $this->authority(
            $this->createStub(ContentModelRepository::class),
            $this->createStub(ContentRepository::class),
            lifetime: 299,
        );
    }

    /**
     * Compose the authority around real Content application services and focused persistence doubles.
     *
     * @param   ContentModelRepository                        $models    Versioned Content-model store.
     * @param   ContentRepository                             $content   Content-entry store.
     * @param   ContentStudioAuthoringContextRepository|null  $contexts  Optional context store double.
     * @param   ClockInterface|null                           $clock     Optional deterministic authority clock.
     * @param   int                                           $lifetime  Hard context lifetime in seconds.
     *
     * @return  array{ContentStudioAuthoringContextAuthority, ContentStudioAuthoringContextRepository}
     *
     * @since   2.0.0
     */
    private function authority(
        ContentModelRepository $models,
        ContentRepository $content,
        ?ContentStudioAuthoringContextRepository $contexts = null,
        ?ClockInterface $clock = null,
        int $lifetime = 28_800,
    ): array {
        $contexts ??= new class implements ContentStudioAuthoringContextRepository {
            /**
             * In-memory bindings indexed only by their opaque keys.
             *
             * @var    array<string, ContentStudioAuthoringContextBinding>
             * @since  2.0.0
             */
            private array $bindings = [];

            /**
             * Retain one immutable test binding.
             *
             * @param   ContentStudioAuthoringContextBinding  $binding  Verified binding to retain.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function add(ContentStudioAuthoringContextBinding $binding): void
            {
                $this->bindings[$binding->contextKey] = $binding;
            }

            /**
             * Find one retained binding by opaque key.
             *
             * @param   string  $contextKey  Opaque key to resolve.
             *
             * @return  ContentStudioAuthoringContextBinding|null  Retained binding, or null.
             *
             * @since   2.0.0
             */
            public function find(string $contextKey): ?ContentStudioAuthoringContextBinding
            {
                return $this->bindings[$contextKey] ?? null;
            }

            /**
             * Replace one retained binding's target in place.
             *
             * @param   ContentStudioAuthoringContextBinding  $binding  Successor binding under the same key.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function advance(ContentStudioAuthoringContextBinding $binding): void
            {
                $this->bindings[$binding->contextKey] = $binding;
            }
        };
        $keys = new class implements StudioResourceContextKeyFactory {
            /**
             * Next deterministic key suffix.
             *
             * @var    int
             * @since  2.0.0
             */
            private int $next = 1;

            /**
             * Allocate a deterministic opaque test key.
             *
             * @return  string  Next unique key.
             *
             * @since   2.0.0
             */
            public function create(): string
            {
                return 'contexts/' . hash('sha256', 'content-authoring-' . $this->next++);
            }
        };
        $gateway = AuthorizationContext::gateway();
        if ($clock === null) {
            $clock = $this->createStub(ClockInterface::class);
            $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-27T00:00:00+00:00'));
        }
        $transactions = new ImmediateTransactionManager();
        $audit = $this->createStub(AuditRecorder::class);

        return [
            new ContentStudioAuthoringContextAuthority(
                $contexts,
                $keys,
                new ContentStudioAuthoringTargetResolver($gateway),
                new ContentModelService(
                    $models,
                    new JsonSchemaValidator(),
                    new SchemaCompatibilityChecker(),
                    $gateway,
                    AuthorizationContext::ownershipWriter(),
                    $audit,
                    $transactions,
                    $clock,
                ),
                new ContentService(
                    $content,
                    $audit,
                    $transactions,
                    $clock,
                    new Workflow(),
                    $gateway,
                    AuthorizationContext::ownershipWriter(),
                ),
                $clock,
                $lifetime,
            ),
            $contexts,
        ];
    }

    /**
     * Build one immutable version-three Content type.
     *
     * @return  ContentTypeDefinition  Exact reusable type fixture.
     *
     * @since   2.0.0
     */
    private static function definition(): ContentTypeDefinition
    {
        $time = new DateTimeImmutable('2026-08-27T00:00:00+00:00');

        return new ContentTypeDefinition(
            self::TYPE_ID,
            SiteContext::default(),
            'article',
            'Article',
            ContentService::CORE_WORKFLOW_ID,
            1,
            ['type' => 'object', 'properties' => []],
            3,
            $time,
            $time,
        );
    }

    /**
     * Build one exact Content record at a selected optimistic version.
     *
     * @param   int  $version             Positive Entry version.
     * @param   int  $contentTypeVersion  Exact reusable Content-type version pin.
     *
     * @return  ContentRecord  Live record pinned to the version-three Content type.
     *
     * @since   2.0.0
     */
    private static function record(int $version, int $contentTypeVersion = 3): ContentRecord
    {
        $time = new DateTimeImmutable('2026-08-27T00:00:00+00:00');

        return new ContentRecord(
            ContentEntry::reconstitute(
                self::ENTRY_ID,
                'Contextual article',
                'contextual-article',
                [],
                ContentStatus::Draft,
                PublicationWindow::unbounded(),
                $version,
            ),
            self::TYPE_ID,
            ContentService::CORE_WORKFLOW_ID,
            $time,
            $time,
            contentTypeVersion: $contentTypeVersion,
        );
    }

    /**
     * Mint an administrator request with selected live authority and scope coordinates.
     *
     * @param   list<string>            $capabilities  Global grants carried by the actor.
     * @param   int                     $epoch         Principal security epoch.
     * @param   string                  $subject       Authenticated actor identity.
     * @param   string                  $site          Active site identifier.
     * @param   MembershipContext|null  $membership    Active organization/workspace scope.
     * @param   AuthenticatedSurface    $surface       Authenticated delivery surface.
     * @param   string|null             $sessionId     Rotated administrator-session identity, when present.
     *
     * @return  ExecutionContext  Provenance-bound authenticated request context.
     *
     * @since   2.0.0
     */
    private static function context(
        array $capabilities,
        int $epoch = 1,
        string $subject = AuthorizationContext::SUBJECT,
        string $site = SiteContext::DEFAULT,
        ?MembershipContext $membership = null,
        AuthenticatedSurface $surface = AuthenticatedSurface::Administrator,
        ?string $sessionId = 'administrator-session-test',
    ): ExecutionContext {
        return AuthenticatedPrincipal::issueFromStrings(
            AuthorizationContext::provenance(),
            $subject,
            $capabilities,
            securityEpoch: $epoch,
        )->context(
            SiteContext::fromString($site),
            AuthenticationStrength::Password,
            'studio-content-authoring-context-test',
            surface: $surface,
            membership: $membership,
            sessionId: $sessionId,
        );
    }

    /**
     * Match the default site without coupling repository expectations to object identity.
     *
     * @param   SiteContext  $site  Candidate repository scope.
     *
     * @return  bool  True for the default test site.
     *
     * @since   2.0.0
     */
    private static function isDefaultSite(SiteContext $site): bool
    {
        return $site->identifier() === SiteContext::DEFAULT;
    }
}
