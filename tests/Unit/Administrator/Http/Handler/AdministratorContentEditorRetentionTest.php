<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Administrator\Http\Handler;

use DateTimeImmutable;
use Kumwe\App\Administrator\Content\ContentEditorSubmission;
use Kumwe\App\Administrator\Content\ContentFormDataMapper;
use Kumwe\App\Administrator\Http\Handler\AdministratorContentEditorHandler;
use Kumwe\App\Administrator\Http\Handler\AdministratorCreateContentHandler;
use Kumwe\App\Administrator\Http\Handler\AdministratorUpdateContentHandler;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\App\Administrator\Presentation\RecoveryAdministratorRenderer;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\SiteContext;
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
use Kumwe\App\Identity\Application\Administration\AdministratorSession;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Presentation\Twig\AdministratorTwigEnvironment;
use Kumwe\App\Presentation\Twig\RecoveryAdministratorTwigEnvironment;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringLaunchResolver;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringTargetResolver;
use Kumwe\App\Studio\Application\Authoring\UnavailableStudioContextualAuthoringConfigurationProvider;
use Kumwe\App\Studio\Infrastructure\Release\PinnedStudioContextualAuthoringAvailability;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\ImmediateTransactionManager;
use Kumwe\App\Workflow\Domain\Workflow;
use Kumwe\App\Workflow\Domain\WorkflowDefinition;
use Kumwe\App\Workflow\Domain\WorkflowStateDefinition;
use Kumwe\App\Workflow\Domain\WorkflowTransitionDefinition;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Loader\ArrayLoader;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;

#[CoversClass(AdministratorContentEditorHandler::class)]
#[CoversClass(AdministratorCreateContentHandler::class)]
#[CoversClass(AdministratorUpdateContentHandler::class)]
#[CoversClass(ContentEditorSubmission::class)]
#[UsesClass(ContentService::class)]
#[UsesClass(ContentModelService::class)]
#[UsesClass(AdministratorRenderer::class)]
#[UsesClass(RecoveryAdministratorRenderer::class)]
#[UsesClass(ContentFormDataMapper::class)]
/**
 * Proves a refused content save returns the operator to their own editor with their work intact.
 *
 * The editor is the CMS counterpart of the generated business form: the same two failures — a body
 * the content model refuses, and a save that lost the optimistic-concurrency race — are the ones a
 * person can recover from, and neither may cost them what they typed. The template is stubbed down to
 * the model keys under assertion so the test states what the editor is handed rather than how the
 * production markup happens to arrange it.
 *
 * @since  2.0.0
 */
final class AdministratorContentEditorRetentionTest extends TestCase
{
    /**
     * Identity of the content type both fixtures author against.
     *
     * @var    string
     * @since  2.0.0
     */
    private const TYPE = '018f22e2-7c8b-7ab0-8f3a-88e8026bb402';

    /**
     * Identity of the workflow that content type pins itself to.
     *
     * @var    string
     * @since  2.0.0
     */
    private const WORKFLOW = '018f22e2-7c8b-7ab0-8f3a-88e8026bb401';

    /**
     * Identity of the stored entry the update fixtures revise.
     *
     * @var    string
     * @since  2.0.0
     */
    private const ENTRY = '018f22e2-7c8b-7ab0-8f3a-88e8026bb501';

    /**
     * A body the content model refuses keeps every typed value in the redrawn create form.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARefusedNewDraftIsRedrawnWithEverythingTheOperatorTyped(): void
    {
        $repository = $this->createMock(ContentRepository::class);
        $repository->expects(self::never())->method('insert');
        $handler = new AdministratorCreateContentHandler(
            $this->contentService($repository),
            $this->modelService(),
            new ContentFormDataMapper(),
            $this->editor($repository),
        );

        $response = $handler->handle($this->request('POST', '/administrator/content', [
            'content_type' => self::TYPE,
            'title' => 'Quarterly safety review',
            'slug' => 'quarterly-safety-review',
            'publish_at' => '2026-09-01T08:30',
            'field__reference' => 'QSR-2026',
            'field__body' => 'An hour of typing that must survive the refusal.',
        ]));
        $body = (string) $response->getBody();

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('title:Quarterly safety review', $body);
        self::assertStringContainsString('slug:quarterly-safety-review', $body);
        self::assertStringContainsString('publish:2026-09-01T08:30', $body);
        self::assertStringContainsString('reference:QSR-2026', $body);
        self::assertStringContainsString('body:An hour of typing that must survive the refusal.', $body);
        self::assertStringContainsString('violations:1', $body);
        self::assertStringContainsString('conflict:none', $body);
    }

    /**
     * A body the content model refuses keeps every typed value in the redrawn edit form.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARefusedRevisionIsRedrawnWithEverythingTheOperatorTyped(): void
    {
        $repository = $this->storedEntry(3);
        $repository->expects(self::never())->method('update');
        $handler = new AdministratorUpdateContentHandler(
            $this->contentService($repository),
            $this->modelService(),
            new ContentFormDataMapper(),
            $this->editor($repository),
        );

        $response = $handler->handle($this->request(
            'POST',
            '/administrator/content/' . self::ENTRY,
            [
                'content_type' => self::TYPE,
                'version' => '3',
                'title' => 'Revised safety review',
                'slug' => 'revised-safety-review',
                'field__reference' => 'RSR-2026',
                'field__body' => 'Rewritten body that must survive the refusal.',
            ],
            self::ENTRY,
        ));
        $body = (string) $response->getBody();

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('title:Revised safety review', $body);
        self::assertStringContainsString('slug:revised-safety-review', $body);
        self::assertStringContainsString('reference:RSR-2026', $body);
        self::assertStringContainsString('body:Rewritten body that must survive the refusal.', $body);
        self::assertStringContainsString('violations:1', $body);
        self::assertStringContainsString('conflict:none', $body);
    }

    /**
     * A lost concurrency race keeps the typed values and quotes the version the entry now carries.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStaleRevisionIsRedrawnWithRetainedValuesAndTheCurrentVersion(): void
    {
        $repository = $this->storedEntry(7);
        $repository->expects(self::never())->method('update');
        $handler = new AdministratorUpdateContentHandler(
            $this->contentService($repository),
            $this->modelService(),
            new ContentFormDataMapper(),
            $this->editor($repository),
        );

        $response = $handler->handle($this->request(
            'POST',
            '/administrator/content/' . self::ENTRY,
            [
                'content_type' => self::TYPE,
                'version' => '4',
                'title' => 'Edited while someone else saved',
                'slug' => 'edited-while-someone-else-saved',
                'field__reference' => 'CFL',
                'field__body' => 'Every line of this must still be here afterwards.',
            ],
            self::ENTRY,
        ));
        $body = (string) $response->getBody();

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('title:Edited while someone else saved', $body);
        self::assertStringContainsString('reference:CFL', $body);
        self::assertStringContainsString('body:Every line of this must still be here afterwards.', $body);
        self::assertStringContainsString('violations:0', $body);
        self::assertStringContainsString('conflict:4/7', $body);
        self::assertStringContainsString('version:7', $body);
    }

    /**
     * A first visit still renders from the stored entry, with nothing overlaid and nothing announced.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUntroubledVisitStillRendersTheStoredEntry(): void
    {
        $repository = $this->storedEntry(7);
        $repository->expects(self::never())->method('update');

        $response = $this->editor($repository)->handle(
            $this->request('GET', '/administrator/content/' . self::ENTRY . '/edit', [], self::ENTRY),
        );
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('input:absent', $body);
        self::assertStringContainsString('violations:0', $body);
        self::assertStringContainsString('conflict:none', $body);
        self::assertStringContainsString('reference:STORED', $body);
    }

    /**
     * Build the editor over the same stores the write handler under test uses.
     *
     * @param   ContentRepository  $repository  Store double the entry is re-read through.
     *
     * @return  AdministratorContentEditorHandler  Editor rendering the assertion-friendly template.
     *
     * @since   2.0.0
     */
    private function editor(ContentRepository $repository): AdministratorContentEditorHandler
    {
        return new AdministratorContentEditorHandler(
            $this->contentService($repository),
            $this->modelService(),
            $this->renderer(),
            new ContentStudioAuthoringTargetResolver(AuthorizationContext::gateway()),
            new ContentStudioAuthoringLaunchResolver(
                new PinnedStudioContextualAuthoringAvailability(dirname(__DIR__, 5), null),
                new UnavailableStudioContextualAuthoringConfigurationProvider(),
            ),
        );
    }

    /**
     * Build a store double holding one entry at the version the test wants to race against.
     *
     * @param   int  $version  Version the stored entry currently carries.
     *
     * @return  ContentRepository&\PHPUnit\Framework\MockObject\MockObject  Store returning that entry.
     *
     * @since   2.0.0
     */
    private function storedEntry(int $version): ContentRepository
    {
        $repository = $this->createMock(ContentRepository::class);
        $repository->method('find')->willReturn(new ContentRecord(
            ContentEntry::reconstitute(
                self::ENTRY,
                'Stored safety review',
                'stored-safety-review',
                ['reference' => 'STORED', 'body' => 'The stored body.'],
                ContentStatus::Draft,
                PublicationWindow::unbounded(),
                $version,
            ),
            self::TYPE,
            self::WORKFLOW,
            new DateTimeImmutable('2026-08-12T00:00:00+00:00'),
            new DateTimeImmutable('2026-08-12T00:00:00+00:00'),
        ));

        return $repository;
    }

    /**
     * Build the request the session, authorization and CSRF middleware would hand a content route.
     *
     * @param   string                 $method  HTTP method the route is reached with.
     * @param   string                 $path    Administrator path being exercised.
     * @param   array<string, string>  $body    Parsed form body, empty for a GET.
     * @param   ?string                $id      Route `id` attribute, or null on the create route.
     *
     * @return  ServerRequestInterface  Request carrying session and execution-context attributes.
     *
     * @since   2.0.0
     */
    private function request(
        string $method,
        string $path,
        array $body = [],
        ?string $id = null,
    ): ServerRequestInterface {
        $principal = AuthorizationContext::principal([
            'administrator.access',
            'content.create',
            'content.read',
            'content.update',
        ]);
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, 'https://kumwe.test' . $path)
            ->withParsedBody($body)
            ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, new AdministratorSession(
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb399',
                $principal,
                'csrf-token',
                new DateTimeImmutable('+1 hour'),
            ))
            ->withAttribute(ExecutionContextAttribute::NAME, $principal->context(
                SiteContext::default(),
                AuthenticationStrength::Password,
                'test-request-0001',
            ));

        return $id === null ? $request : $request->withAttribute('id', $id);
    }

    /**
     * Compose a real content service over the store double under test.
     *
     * @param   ContentRepository  $repository  Store double the service reads and would write through.
     *
     * @return  ContentService  Service validating against the fixture content model.
     *
     * @since   2.0.0
     */
    private function contentService(ContentRepository $repository): ContentService
    {
        return new ContentService(
            $repository,
            $this->createStub(AuditRecorder::class),
            new ImmediateTransactionManager(),
            $this->clock(),
            new Workflow(),
            AuthorizationContext::gateway(),
            AuthorizationContext::ownershipWriter(),
            $this->modelRepository(),
            new JsonSchemaValidator(),
        );
    }

    /**
     * Compose a real content-model service over the same fixture definitions.
     *
     * @return  ContentModelService  Service resolving the fixture type and its workflow.
     *
     * @since   2.0.0
     */
    private function modelService(): ContentModelService
    {
        return new ContentModelService(
            $this->modelRepository(),
            new JsonSchemaValidator(),
            new SchemaCompatibilityChecker(),
            AuthorizationContext::gateway(),
            AuthorizationContext::ownershipWriter(),
            $this->createStub(AuditRecorder::class),
            new ImmediateTransactionManager(),
            $this->clock(),
        );
    }

    /**
     * Publish one content type carrying a rule the browser cannot enforce on the operator's behalf.
     *
     * The `reference` field caps at three characters, which is what lets a submission be refused by
     * the schema on the server rather than by an input attribute in front of it.
     *
     * @return  ContentModelRepository  Store double serving that type and its workflow.
     *
     * @since   2.0.0
     */
    private function modelRepository(): ContentModelRepository
    {
        $now = new DateTimeImmutable('2026-08-12T00:00:00+00:00');
        $type = new ContentTypeDefinition(
            self::TYPE,
            SiteContext::default(),
            'page',
            'Page',
            self::WORKFLOW,
            1,
            [
                'type' => 'object',
                'properties' => [
                    'reference' => ['type' => 'string', 'maxLength' => 3],
                    'body' => ['type' => 'string'],
                ],
            ],
            1,
            $now,
            $now,
        );
        $workflow = new WorkflowDefinition(
            self::WORKFLOW,
            SiteContext::default(),
            'editorial',
            'Editorial workflow',
            [
                new WorkflowStateDefinition('draft', 'Draft', true, false),
                new WorkflowStateDefinition('published', 'Published', false, true),
            ],
            [new WorkflowTransitionDefinition('draft', 'published', Capability::fromString('content.publish'))],
            1,
            $now,
            $now,
        );
        $repository = $this->createStub(ContentModelRepository::class);
        $repository->method('contentTypes')->willReturn([$type]);
        $repository->method('contentType')->willReturn($type);
        $repository->method('workflow')->willReturn($workflow);

        return $repository;
    }

    /**
     * Build a renderer whose editor template echoes exactly the model keys the assertions read.
     *
     * @return  AdministratorRenderer  Renderer resolving `content-form.twig` from an in-memory loader.
     *
     * @since   2.0.0
     */
    private function renderer(): AdministratorRenderer
    {
        $template = "input:{{ editor_input ? 'present' : 'absent' }};"
            . "title:{{ editor_input ? editor_input.title : entry.title|default('') }};"
            . "slug:{{ editor_input ? editor_input.slug : entry.slug|default('') }};"
            . "publish:{{ editor_input ? editor_input.publish_at : '' }};"
            . 'violations:{{ content_violations|length }};'
            . 'conflict:'
            . "{{ version_conflict ? version_conflict.expected_version ~ '/' ~ version_conflict.current_version"
            . " : 'none' }};"
            . "version:{{ entry.version|default('none') }};"
            . '{% for field in fields %}{{ field.key }}:{{ field.value }};{% endfor %}';

        return new AdministratorRenderer(
            new AdministratorTwigEnvironment(new ArrayLoader(['content-form.twig' => $template])),
            new RecoveryAdministratorRenderer(
                new RecoveryAdministratorTwigEnvironment(new ArrayLoader()),
            ),
        );
    }

    /**
     * Supply the fixed instant the composed services stamp onto anything they would write.
     *
     * @return  ClockInterface  Clock pinned to one deterministic instant.
     *
     * @since   2.0.0
     */
    private function clock(): ClockInterface
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-12T00:00:00+00:00'));

        return $clock;
    }
}
