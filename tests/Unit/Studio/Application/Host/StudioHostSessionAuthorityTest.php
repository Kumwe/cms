<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Host;

use InvalidArgumentException;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\MembershipContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Runtime\ActiveExtensionSet;
use Kumwe\App\Presentation\Application\SitePresentation;
use Kumwe\App\Site\Application\SiteSettings;
use Kumwe\App\Studio\Application\Composition\StudioBuiltInThemeRelease;
use Kumwe\App\Studio\Application\Composition\StudioPublishedTheme;
use Kumwe\App\Studio\Application\Host\StudioHostAccessRefused;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Host\StudioHostSessionRepository;
use Kumwe\App\Studio\Application\Host\StudioResourceContextKeyFactory;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\App\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins mode-specific authorization, trusted scope binding and generation invalidation.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioHostAccessRefused::class)]
#[CoversClass(StudioHostSession::class)]
#[CoversClass(StudioHostSessionAuthority::class)]
#[CoversClass(StudioResourceKind::class)]
#[CoversClass(StudioSessionMode::class)]
final class StudioHostSessionAuthorityTest extends TestCase
{
    /**
     * Enumerate every valid canonical mode/resource pair.
     *
     * @return  iterable<string, array{StudioSessionMode, StudioResourceKind}>  Named valid pairs.
     *
     * @since   2.0.0
     */
    public static function permittedModes(): iterable
    {
        yield 'content' => [StudioSessionMode::Content, StudioResourceKind::Content];
        yield 'hybrid' => [StudioSessionMode::Hybrid, StudioResourceKind::Content];
        yield 'model' => [StudioSessionMode::Model, StudioResourceKind::Content];
        yield 'content read-only' => [StudioSessionMode::ReadOnly, StudioResourceKind::Content];
        yield 'blueprint' => [StudioSessionMode::Blueprint, StudioResourceKind::Blueprint];
        yield 'blueprint read-only' => [StudioSessionMode::ReadOnly, StudioResourceKind::Blueprint];
    }

    /**
     * Enumerate every target-specific Blueprint lifecycle grant combination.
     *
     * @return  iterable<string, array{list<string>, bool, bool}>  Grants and exact lifecycle decisions.
     *
     * @since   2.0.0
     */
    public static function blueprintLifecycleAuthorities(): iterable
    {
        yield 'neither lifecycle grant' => [[], false, false];
        yield 'publish only' => [['content.publish'], true, false];
        yield 'unpublish only' => [['content.unpublish'], false, true];
        yield 'both lifecycle grants' => [['content.publish', 'content.unpublish'], true, true];
    }

    /**
     * Every canonical mode opens only under its exact capability and persists its trusted binding.
     *
     * @param   StudioSessionMode   $mode  Canonical mode under test.
     * @param   StudioResourceKind  $kind  Compatible host resource family.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('permittedModes')]
    public function testEveryCanonicalModeIsAuthorizedExactlyAndBoundServerSide(
        StudioSessionMode $mode,
        StudioResourceKind $kind,
    ): void {
        [$authority, $repository] = $this->authority();
        $context = self::context([
            $mode->capability(),
            'content.publish',
            'content.unpublish',
            'content.update',
        ]);

        $snapshot = $authority->open($context, $mode, $kind, 'resource-1');

        self::assertSame($mode, $snapshot->session->mode);
        self::assertSame($kind, $snapshot->session->resourceKind);
        self::assertSame($snapshot->session, $repository->find($snapshot->session->resourceContextKey));
        self::assertContains('studio.permission/read', $snapshot->permissions);
        self::assertSame($snapshot->generation, $snapshot->session->sessionGeneration);
        if ($mode === StudioSessionMode::ReadOnly) {
            self::assertNotContains('studio.permission/save', $snapshot->permissions);
            self::assertFalse($snapshot->canPublish);
            self::assertFalse($snapshot->canUnpublish);
        } else {
            self::assertTrue($snapshot->canPublish);
            self::assertTrue($snapshot->canUnpublish);
        }
    }

    /**
     * Content authority cannot be promoted and an authenticated browser session is mandatory.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testContentAuthorityCannotOpenBlueprintAndModeResourceMismatchesFailClosed(): void
    {
        [$authority] = $this->authority();

        try {
            $authority->open(
                self::context(['studio.mode.content']),
                StudioSessionMode::Blueprint,
                StudioResourceKind::Blueprint,
                'blueprint-1',
            );
            self::fail('Content authority must not acquire Blueprint mode.');
        } catch (StudioHostAccessRefused $refused) {
            self::assertSame('forbidden', $refused->category);
        }

        $withoutSession = AuthenticatedPrincipal::issueFromStrings(
            AuthorizationContext::provenance(),
            AuthorizationContext::SUBJECT,
            ['studio.mode.content'],
        )->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'studio-authority-test',
            surface: AuthenticatedSurface::Administrator,
        );
        try {
            $authority->open(
                $withoutSession,
                StudioSessionMode::Content,
                StudioResourceKind::Content,
                'content-without-session',
            );
            self::fail('Studio authority must not open without an authenticated browser session.');
        } catch (StudioHostAccessRefused $refused) {
            self::assertSame('forbidden', $refused->category);
            self::assertSame('studio.host/session-refused', $refused->diagnosticCode);
        }

        $this->expectException(StudioHostAccessRefused::class);
        $authority->open(
            self::context(['studio.mode.blueprint']),
            StudioSessionMode::Blueprint,
            StudioResourceKind::Content,
            'content-1',
        );
    }

    /**
     * Grant and security-epoch changes alter the live generation deterministically.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGenerationChangesAcrossGrantSecurityEpochCapabilityAndResourceChanges(): void
    {
        [$authority] = $this->authority();
        $base = $authority->open(
            self::context(['studio.mode.content']),
            StudioSessionMode::Content,
            StudioResourceKind::Content,
            'content-1',
        );

        $withGrant = $authority->resolve(
            self::context(['content.publish', 'studio.mode.content']),
            $base->session->resourceContextKey,
        );
        $withUnpublishGrant = $authority->resolve(
            self::context(['content.unpublish', 'studio.mode.content']),
            $base->session->resourceContextKey,
        );
        $withBothGrants = $authority->resolve(
            self::context(['content.publish', 'content.unpublish', 'studio.mode.content']),
            $base->session->resourceContextKey,
        );
        $withEpoch = $authority->resolve(
            self::context(['studio.mode.content'], epoch: 2),
            $base->session->resourceContextKey,
        );

        self::assertNotSame($base->generation, $withGrant->generation);
        self::assertNotSame($base->generation, $withEpoch->generation);
        self::assertNotSame($withGrant->generation, $withUnpublishGrant->generation);
        self::assertNotSame($withGrant->generation, $withBothGrants->generation);
        self::assertNotSame($withUnpublishGrant->generation, $withBothGrants->generation);
        self::assertContains('studio.permission/publish', $withGrant->permissions);
        self::assertSame($withGrant->permissions, $withUnpublishGrant->permissions);
        self::assertSame($withGrant->permissions, $withBothGrants->permissions);
        self::assertTrue($withGrant->canPublish);
        self::assertFalse($withGrant->canUnpublish);
        self::assertFalse($withUnpublishGrant->canPublish);
        self::assertTrue($withUnpublishGrant->canUnpublish);
        self::assertTrue($withBothGrants->canPublish);
        self::assertTrue($withBothGrants->canUnpublish);
    }

    /**
     * Blueprint authoring projects one protocol permission but retains exact authority for each target.
     *
     * @param   list<string>  $lifecycleGrants  Content lifecycle capabilities granted to the actor.
     * @param   bool          $canPublish       Expected live publication authority.
     * @param   bool          $canUnpublish     Expected live return-to-draft authority.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('blueprintLifecycleAuthorities')]
    public function testBlueprintPublicationRequiresTheDistinctContentPublishCapability(
        array $lifecycleGrants,
        bool $canPublish,
        bool $canUnpublish,
    ): void {
        [$authority] = $this->authority();
        $snapshot = $authority->open(
            self::context(['studio.mode.blueprint', ...$lifecycleGrants]),
            StudioSessionMode::Blueprint,
            StudioResourceKind::Blueprint,
            'blueprint-1',
        );

        self::assertContains('studio.permission/save', $snapshot->permissions);
        self::assertContains('studio.permission/edit-blueprint', $snapshot->permissions);
        self::assertSame(
            $canPublish || $canUnpublish,
            in_array('studio.permission/publish', $snapshot->permissions, true),
        );
        self::assertSame($canPublish, $snapshot->canPublish);
        self::assertSame($canUnpublish, $snapshot->canUnpublish);
    }

    /**
     * A public-presentation change makes every existing exact-preview session stale.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGenerationChangesWithTheExactPublishedThemeRevision(): void
    {
        $settingsDocument = ['presentation' => SitePresentation::defaults()];
        $settings = $this->createStub(SiteSettings::class);
        $settings->method('current')->willReturnCallback(
            static function () use (&$settingsDocument): array {
                return $settingsDocument;
            },
        );
        $theme = new StudioPublishedTheme(
            $settings,
            new ActiveExtensionSet(new ExtensionContributionRegistrySet(withCore: false)),
            new StudioBuiltInThemeRelease(str_repeat('a', 64)),
        );
        [$authority] = $this->authority($theme);
        $opened = $authority->open(
            self::context(['studio.mode.blueprint']),
            StudioSessionMode::Blueprint,
            StudioResourceKind::Blueprint,
            'blueprint-1',
        );

        $settingsDocument['presentation']['active_scheme'] = 'ocean';
        $resolved = $authority->resolve(
            self::context(['studio.mode.blueprint']),
            $opened->session->resourceContextKey,
        );

        self::assertNotSame($opened->generation, $resolved->generation);
        self::assertNotSame($opened->session->sessionGeneration, $resolved->generation);
    }

    /**
     * Persisted host sessions reject malformed stable, bounded, binding and scope coordinates.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStoredHostSessionRefusesEveryReachableMalformedCoordinate(): void
    {
        $refusals = [
            'resource context' => [
                static fn (): StudioHostSession => self::hostSession(resourceContextKey: '__proto__'),
                'The Studio resource-context key is invalid.',
            ],
            'actor' => [
                static fn (): StudioHostSession => self::hostSession(actorId: ''),
                'The Studio actor identifier is invalid.',
            ],
            'session binding' => [
                static fn (): StudioHostSession => self::hostSession(sessionBinding: 'not-a-sha256-digest'),
                'The Studio host-session binding is invalid.',
            ],
            'workspace without organization' => [
                static fn (): StudioHostSession => self::hostSession(organizationId: null),
                'A Studio workspace binding requires an organization binding.',
            ],
        ];

        foreach ($refusals as $label => [$operation, $message]) {
            try {
                $operation();
                self::fail($label . ' must be refused.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage(), $label);
            }
        }
    }

    /**
     * Actor, site, organization, workspace, surface and host-session mismatches share one safe refusal.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOpaqueBindingRefusesEveryOtherTrustedScopeWithoutDisclosure(): void
    {
        [$authority, $repository] = $this->authority();
        $repository->add(new StudioHostSession(
            'contexts/scope',
            AuthorizationContext::SUBJECT,
            SiteContext::DEFAULT,
            'acme',
            'finance',
            AuthenticatedSurface::Administrator->value,
            hash('sha256', 'administrator-session-test'),
            StudioSessionMode::Content,
            StudioResourceKind::Content,
            'private-content',
            'session-private',
        ));
        $contexts = [
            'actor' => self::context(
                ['studio.mode.content'],
                subject: '018f22e2-7c8b-7ab0-8f3a-88e8026bb399',
                membership: AuthorizationContext::membership('acme', 'finance'),
            ),
            'site' => self::context(
                ['studio.mode.content'],
                site: 'other-site',
                membership: AuthorizationContext::membership('acme', 'finance'),
            ),
            'organization' => self::context(
                ['studio.mode.content'],
                membership: AuthorizationContext::membership('other', 'finance'),
            ),
            'workspace' => self::context(
                ['studio.mode.content'],
                membership: AuthorizationContext::membership('acme', 'legal'),
            ),
            'surface' => self::context(
                ['studio.mode.content'],
                membership: AuthorizationContext::membership('acme', 'finance'),
                surface: AuthenticatedSurface::Api,
            ),
            'session' => self::context(
                ['studio.mode.content'],
                membership: AuthorizationContext::membership('acme', 'finance'),
                sessionId: 'administrator-session-other',
            ),
        ];

        foreach ($contexts as $label => $other) {
            try {
                $authority->resolve($other, 'contexts/scope');
                self::fail($label . ' mismatch must not resolve the opaque binding.');
            } catch (StudioHostAccessRefused $refused) {
                self::assertSame('studio.host/context-refused', $refused->diagnosticCode);
                self::assertStringNotContainsString('private-content', $refused->getMessage());
                self::assertStringNotContainsString('acme', $refused->getMessage());
            }
        }
    }

    /**
     * Assemble the production authority around deterministic in-memory boundary doubles.
     *
     * @param   ?StudioPublishedTheme  $theme  Exact public-theme projection, when generation binding is under test.
     *
     * @return  array{StudioHostSessionAuthority, StudioHostSessionRepository}  Authority and stored bindings.
     *
     * @since   2.0.0
     */
    private function authority(?StudioPublishedTheme $theme = null): array
    {
        $repository = new class implements StudioHostSessionRepository {
            /**
             * Sessions retained by opaque resource-context key.
             *
             * @var    array<string, StudioHostSession>
             * @since  2.0.0
             */
            private array $sessions = [];

            /**
             * Retain one binding under its opaque key.
             *
             * @param   StudioHostSession  $session  Binding opened by the authority under test.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function add(StudioHostSession $session): void
            {
                $this->sessions[$session->resourceContextKey] = $session;
            }

            /**
             * Find a retained binding by opaque key.
             *
             * @param   string  $resourceContextKey  Opaque key to resolve.
             *
             * @return  StudioHostSession|null  Retained binding, or null.
             *
             * @since   2.0.0
             */
            public function find(string $resourceContextKey): ?StudioHostSession
            {
                return $this->sessions[$resourceContextKey] ?? null;
            }
        };
        $keys = new class implements StudioResourceContextKeyFactory {
            /**
             * Next deterministic test-key suffix.
             *
             * @var    int
             * @since  2.0.0
             */
            private int $next = 1;

            /**
             * Allocate a deterministic unique key for one test runtime.
             *
             * @return  string  Next canonical test key.
             *
             * @since   2.0.0
             */
            public function create(): string
            {
                return 'contexts/test-' . $this->next++;
            }
        };

        return [
            new StudioHostSessionAuthority(AuthorizationContext::gateway(), $repository, $keys, $theme),
            $repository,
        ];
    }

    /**
     * Build one valid persisted host-session value with selected refusal coordinates overridden.
     *
     * @param   string       $resourceContextKey  Candidate stable resource-context key.
     * @param   string       $actorId             Candidate bounded actor identifier.
     * @param   string|null  $organizationId      Candidate organization binding.
     * @param   string|null  $workspaceId         Candidate workspace binding.
     * @param   string       $sessionBinding      Candidate authenticated-session digest.
     *
     * @return  StudioHostSession  Valid session when every candidate coordinate is accepted.
     *
     * @since   2.0.0
     */
    private static function hostSession(
        string $resourceContextKey = 'contexts/value-refusal',
        string $actorId = 'actor-1',
        ?string $organizationId = 'organization-1',
        ?string $workspaceId = 'workspace-1',
        string $sessionBinding = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    ): StudioHostSession {
        return new StudioHostSession(
            $resourceContextKey,
            $actorId,
            SiteContext::DEFAULT,
            $organizationId,
            $workspaceId,
            AuthenticatedSurface::Administrator->value,
            $sessionBinding,
            StudioSessionMode::Content,
            StudioResourceKind::Content,
            'content-1',
            'generation-1',
        );
    }

    /**
     * Mint an administrator context with one exact effective authority snapshot.
     *
     * @param   list<string>            $capabilities  Global grants carried by the test principal.
     * @param   int                     $epoch         Principal security epoch.
     * @param   string                  $subject       Authenticated actor UUID.
     * @param   string                  $site          Trusted execution-site identifier.
     * @param   MembershipContext|null  $membership    Trusted membership snapshot, when selected.
     * @param   AuthenticatedSurface    $surface       Trusted delivery surface.
     * @param   string                  $sessionId     Trusted administrator-session identity.
     *
     * @return  ExecutionContext  Provenance-bound test context.
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
        string $sessionId = 'administrator-session-test',
    ): ExecutionContext {
        return AuthenticatedPrincipal::issueFromStrings(
            AuthorizationContext::provenance(),
            $subject,
            $capabilities,
            securityEpoch: $epoch,
        )->context(
            SiteContext::fromString($site),
            AuthenticationStrength::Password,
            'studio-authority-test',
            surface: $surface,
            membership: $membership,
            sessionId: $sessionId,
        );
    }
}
