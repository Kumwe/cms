<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Studio;

use Kumwe\App\Application\Authorization\AuthenticatedSurface;
use Kumwe\App\Application\Authorization\AuthenticationStrength;
use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\Application\Authorization\SiteContext;
use Kumwe\App\Content\Application\ContentModelService;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextAuthority;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringDocuments;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringService;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringTargetResolver;
use Kumwe\App\Studio\Application\Authoring\HostedContentStudioAuthoringConfigurationProvider;
use Kumwe\App\Studio\Application\Authoring\StudioContextualAuthoringConfigurationProvider;
use Kumwe\App\Studio\Application\Authoring\StudioHostedDeploymentConfiguration;
use Kumwe\App\Studio\Application\Host\StudioAuthoringHostPort;
use Kumwe\App\Studio\Application\Host\StudioHostSessionRepository;
use Kumwe\App\Studio\Application\Host\StudioProducerHostFactory;
use Kumwe\App\Studio\Domain\Authoring\StudioAuthoringIntent;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\Producer\Schema\StudioDocumentSchemaRegistry;
use Kumwe\Producer\Wire\Dispatcher;
use Kumwe\Producer\Wire\RequestEnvelope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Drives one complete contextual authoring journey through the real container, wire and database.
 *
 * The Content editor mount opens its bindings and emits the deployment; the seven-operation authoring
 * port then answers exactly what the pinned Studio shell sends: target resolution, the reusable-type
 * catalogue, a from-type start, a save plan and the item save that persists a Content entry and
 * advances the opaque context to the stored item.
 *
 * @since  2.0.0
 */
#[CoversClass(ContentStudioAuthoringService::class)]
#[CoversClass(StudioAuthoringHostPort::class)]
#[CoversClass(HostedContentStudioAuthoringConfigurationProvider::class)]
final class ContentStudioAuthoringJourneyIntegrationTest extends TestCase
{
    /**
     * Create, resolve, start from a reusable type, plan and save one item end to end.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACreateMountResolvesStartsPlansAndSavesOneItemThroughTheWire(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = self::administratorContext($container);
        $registry = self::service($container, StudioDocumentSchemaRegistry::class);
        $provider = self::service($container, StudioContextualAuthoringConfigurationProvider::class);
        $targets = self::service($container, ContentStudioAuthoringTargetResolver::class);
        $hosts = self::service($container, StudioProducerHostFactory::class);
        $content = self::service($container, ContentService::class);
        $contexts = self::service($container, ContentStudioAuthoringContextAuthority::class);

        $configuration = $provider->forMount($context, $targets->create($context), 'integration-csrf');
        self::assertInstanceOf(StudioHostedDeploymentConfiguration::class, $configuration);
        $deployment = json_decode($configuration->configurationJson, false, 32, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $deployment);
        self::assertTrue($registry->validate('studio-deployment', $deployment)->valid());
        $session = $deployment->session;
        $resourceContext = $session->resourceContext;
        $key = $resourceContext->key;
        $generation = $session->sessionGeneration;
        self::assertIsString($key);
        self::assertIsString($generation);

        $dispatch = self::dispatcher($hosts, $context, $key, $generation);

        $resolution = $dispatch('authoring/resolve-target', 'request', (object) [
            'targetId' => $deployment->launch->targetId,
            'intent' => 'create',
            'resourceContext' => $resourceContext,
            'requestedPresentation' => 'inline',
        ], false);
        self::assertTrue($registry->validateDefinition('authoring-target', 'resolution', $resolution)->valid());
        self::assertSame(['blank', 'from-type'], $resolution->availableStarts);
        self::assertSame('inline', $resolution->initialPresentation);
        self::assertEquals($deployment->launch->resourceContext, $resolution->resourceContext);

        $catalogue = $dispatch('authoring/list-types', 'query', (object) [
            'targetId' => $deployment->launch->targetId,
            'resourceContext' => $resourceContext,
            'limit' => 100,
        ], false);
        self::assertNotSame([], $catalogue->items, 'The site must expose at least one reusable content type.');
        $page = array_values(array_filter(
            $catalogue->items,
            static fn (stdClass $item): bool
                => $item->reference->id === 'content-type:' . ContentService::CORE_PAGE_TYPE_ID,
        ));
        self::assertCount(1, $page, 'The core Page type must be listed as a reusable content type.');
        $type = $page[0]->reference;
        self::assertStringStartsWith('content-type:', $type->id);

        $snapshot = $dispatch('authoring/start', 'request', (object) [
            'targetId' => $deployment->launch->targetId,
            'resourceContext' => $resourceContext,
            'source' => (object) [
                'kind' => 'from-type',
                'type' => (object) ['id' => $type->id, 'version' => $type->version, 'revision' => $type->revision],
            ],
            'presentation' => 'inline',
        ], true);
        self::assertTrue($registry->validateDefinition('authoring-session', 'snapshot', $snapshot)->valid());
        self::assertSame($session->sessionId, $snapshot->sessionId);
        self::assertSame($generation, $snapshot->sessionGeneration);
        self::assertSame($deployment->contributions->generation, $snapshot->contributionGeneration);
        self::assertEquals($resolution->returnContext, $snapshot->presentation->returnContext);
        self::assertEquals($resolution->target, $snapshot->target);
        self::assertSame($type->id, $snapshot->state->coordinates->type->id);
        self::assertContains('save-item', $snapshot->capabilities->saveOutcomes);

        $slug = 'studio-journey-' . bin2hex(random_bytes(4));
        $entry = json_decode(json_encode($snapshot->state->entry, JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $entry);
        $entry->values->title = 'Studio journey page';
        $entry->values->slug = $slug;
        $entry->values->data_body = 'Composed through the contextual Studio journey.';
        $draft = (object) ['outcome' => 'save-item', 'entry' => $entry];

        $plan = $dispatch('authoring/plan-save', 'intent', (object) [
            'contractVersion' => '0.1-draft',
            'kind' => 'authoring-save-intent',
            'sessionId' => $snapshot->sessionId,
            'expected' => $snapshot->state->coordinates,
            'draft' => $draft,
        ], false);
        self::assertTrue($registry->validateDefinition('authoring-save', 'savePlan', $plan)->valid());
        self::assertSame('save-item', $plan->outcome);
        self::assertSame(['entry'], $plan->affectedArtifacts);
        self::assertNotEquals($snapshot->presentation->returnContext, $plan->successorContext);

        $accepted = [];
        foreach ($plan->consequences as $consequence) {
            $accepted[] = $consequence->code;
        }
        $result = $dispatch('authoring/save-item', 'request', (object) [
            'contractVersion' => '0.1-draft',
            'kind' => 'authoring-save-item-request',
            'plan' => (object) [
                'id' => $plan->id,
                'revision' => $plan->revision,
                'successorContext' => $plan->successorContext,
            ],
            'acceptedConsequences' => $accepted,
            'draft' => $draft,
        ], true);
        self::assertTrue($registry->validateDefinition('authoring-save', 'saveResult', $result)->valid());
        self::assertSame('save-item', $result->outcome);
        self::assertEquals($plan->successorContext, $result->plan->successorContext);
        self::assertEquals($plan->successorContext, $result->session->presentation->returnContext);
        self::assertSame($snapshot->sessionId, $result->session->sessionId);
        self::assertEquals($snapshot->target, $result->session->target);
        self::assertEquals($snapshot->resourceContext, $result->session->resourceContext);
        self::assertSame('Studio journey page', $result->session->state->entry->values->title);
        self::assertSame($slug, $result->session->state->entry->values->slug);
        self::assertEquals($snapshot->state->coordinates->model, $result->session->state->coordinates->model);
        self::assertEquals($snapshot->state->coordinates->blueprint, $result->session->state->coordinates->blueprint);
        $returnPath = $result->session->extensions->{'kumwe.app/return'}->path;
        self::assertMatchesRegularExpression('#^/administrator/content/[0-9a-f-]{36}/edit$#', $returnPath);
        $entryId = substr($returnPath, strlen('/administrator/content/'), 36);

        $record = $content->get($context, $entryId);
        self::assertSame('Studio journey page', $record->entry->title());
        self::assertSame($slug, $record->entry->slug());
        self::assertSame('Composed through the contextual Studio journey.', $record->entry->data()['body'] ?? null);

        $advanced = $contexts->resolve($context, self::contextKeyOf($container, $key));
        self::assertSame(StudioAuthoringIntent::Edit, $advanced->intent);
        self::assertSame('content-entry:' . $entryId, $advanced->entryId);
    }

    /**
     * A blank canvas becomes a reusable content type, and that type then takes a new version, through the wire.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testABlankStartSavesAsANewTypeAndThenANewTypeVersion(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = self::administratorContext($container);
        $registry = self::service($container, StudioDocumentSchemaRegistry::class);
        $provider = self::service($container, StudioContextualAuthoringConfigurationProvider::class);
        $targets = self::service($container, ContentStudioAuthoringTargetResolver::class);
        $hosts = self::service($container, StudioProducerHostFactory::class);
        $models = self::service($container, ContentModelService::class);

        $configuration = $provider->forMount($context, $targets->create($context), 'integration-csrf');
        self::assertInstanceOf(StudioHostedDeploymentConfiguration::class, $configuration);
        $deployment = json_decode($configuration->configurationJson, false, 32, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $deployment);
        $resourceContext = $deployment->session->resourceContext;
        $dispatch = self::dispatcher($hosts, $context, $resourceContext->key, $deployment->session->sessionGeneration);

        $dispatch('authoring/resolve-target', 'request', (object) [
            'targetId' => $deployment->launch->targetId,
            'intent' => 'create',
            'resourceContext' => $resourceContext,
            'requestedPresentation' => 'inline',
        ], false);
        $snapshot = $dispatch('authoring/start', 'request', (object) [
            'targetId' => $deployment->launch->targetId,
            'resourceContext' => $resourceContext,
            'source' => (object) ['kind' => 'blank'],
            'presentation' => 'inline',
        ], true);
        self::assertTrue($registry->validateDefinition('authoring-session', 'snapshot', $snapshot)->valid());
        self::assertObjectNotHasProperty('type', $snapshot);
        self::assertSame(['save-as-new-type'], $snapshot->capabilities->saveOutcomes);
        self::assertSame('draft', $snapshot->state->model->status);

        $name = 'Journey type ' . bin2hex(random_bytes(3));
        $model = self::clone($snapshot->state->model);
        $model->fields[] = self::dataField('summary', 'Summary');
        $typeDraft = (object) [
            'outcome' => 'save-as-new-type',
            'label' => (object) ['key' => 'kumwe.app/journey-type', 'defaultMessage' => $name],
            'authoringPolicy' => (object) ['modes' => ['model', 'blueprint', 'content'], 'itemComposition' => 'denied'],
            'model' => $model,
            'blueprint' => self::clone($snapshot->state->blueprint),
        ];
        $plan = $dispatch('authoring/plan-save', 'intent', (object) [
            'contractVersion' => '0.1-draft',
            'kind' => 'authoring-save-intent',
            'sessionId' => $snapshot->sessionId,
            'expected' => $snapshot->state->coordinates,
            'draft' => $typeDraft,
        ], false);
        self::assertSame('save-as-new-type', $plan->outcome);
        self::assertSame(['model', 'blueprint', 'reusable-content-type'], $plan->affectedArtifacts);

        $result = $dispatch('authoring/save-as-new-type', 'request', (object) [
            'contractVersion' => '0.1-draft',
            'kind' => 'authoring-save-as-new-type-request',
            'plan' => (object) [
                'id' => $plan->id,
                'revision' => $plan->revision,
                'successorContext' => $plan->successorContext,
            ],
            'acceptedConsequences' => self::codes($plan),
            'draft' => $typeDraft,
        ], true);
        self::assertTrue($registry->validateDefinition('authoring-save', 'saveResult', $result)->valid());
        self::assertSame('save-as-new-type', $result->outcome);
        self::assertEquals($plan->successorContext, $result->session->presentation->returnContext);
        self::assertEquals($snapshot->resourceContext, $result->session->resourceContext);
        self::assertObjectHasProperty('type', $result->session);
        $createdType = $result->session->type;
        self::assertSame($name, $createdType->label->defaultMessage);
        self::assertSame('published', $createdType->status);
        self::assertNotSame($snapshot->state->model->id, $result->session->state->model->id);
        self::assertSame('published', $result->session->state->model->status);
        self::assertContains('save-item', $result->session->capabilities->saveOutcomes);
        self::assertContains('save-new-type-version', $result->session->capabilities->saveOutcomes);
        self::assertEquals($snapshot->state->entry->values, $result->session->state->entry->values);
        $definitionId = ContentStudioAuthoringDocuments::contentTypeId($createdType->id);
        self::assertIsString($definitionId);
        $definition = $models->contentType($context, $definitionId, 1);
        self::assertSame($name, $definition->name);
        self::assertArrayHasKey('summary', $definition->schema()['properties'] ?? []);

        $model = self::clone($result->session->state->model);
        $model->fields[] = self::dataField('teaser', 'Teaser');
        $versionDraft = (object) [
            'outcome' => 'save-new-type-version',
            'model' => $model,
            'blueprint' => self::clone($result->session->state->blueprint),
        ];
        $versionPlan = $dispatch('authoring/plan-save', 'intent', (object) [
            'contractVersion' => '0.1-draft',
            'kind' => 'authoring-save-intent',
            'sessionId' => $snapshot->sessionId,
            'expected' => $result->session->state->coordinates,
            'draft' => $versionDraft,
        ], false);
        self::assertSame('save-new-type-version', $versionPlan->outcome);

        $versioned = $dispatch('authoring/save-new-type-version', 'request', (object) [
            'contractVersion' => '0.1-draft',
            'kind' => 'authoring-save-new-type-version-request',
            'plan' => (object) [
                'id' => $versionPlan->id,
                'revision' => $versionPlan->revision,
                'successorContext' => $versionPlan->successorContext,
            ],
            'acceptedConsequences' => self::codes($versionPlan),
            'draft' => $versionDraft,
        ], true);
        self::assertTrue($registry->validateDefinition('authoring-save', 'saveResult', $versioned)->valid());
        self::assertSame('save-new-type-version', $versioned->outcome);
        self::assertSame($createdType->id, $versioned->session->type->id);
        self::assertNotSame($createdType->version, $versioned->session->type->version);
        self::assertSame($result->session->state->model->id, $versioned->session->state->model->id);
        self::assertNotSame($result->session->state->model->revision, $versioned->session->state->model->revision);
        self::assertEquals($versionPlan->successorContext, $versioned->session->presentation->returnContext);
        $successor = $models->contentType($context, $definitionId, 2);
        self::assertArrayHasKey('teaser', $successor->schema()['properties'] ?? []);
    }

    /**
     * One dispatch closure bound to a host session for the seven authoring routes.
     *
     * @param   StudioProducerHostFactory  $hosts       Request-scoped host factory.
     * @param   ExecutionContext           $context     Administrator context.
     * @param   string                     $key         Host session resource-context key.
     * @param   string                     $generation  Host session generation.
     *
     * @return  \Closure(string, string, stdClass, bool): stdClass  Dispatcher returning the result value.
     *
     * @since   2.0.0
     */
    private static function dispatcher(
        StudioProducerHostFactory $hosts,
        ExecutionContext $context,
        string $key,
        string $generation,
    ): \Closure {
        return static function (
            string $route,
            string $member,
            stdClass $argument,
            bool $mutating
        ) use (
            $hosts,
            $context,
            $key,
            $generation,
        ): stdClass {
            $envelope = (object) [
                'arguments' => (object) [$member => $argument],
                'context' => (object) [
                    'operationId' => 'studio.operation/' . str_replace('/', '.', $route),
                    'protocolVersion' => RequestEnvelope::WIRE_PROTOCOL_VERSION,
                    'requestId' => 'studio-request/' . bin2hex(random_bytes(8)),
                    'resourceContextKey' => $key,
                    'sessionGeneration' => $generation,
                ],
            ];
            if ($mutating) {
                $envelope->context->idempotencyKey = 'studio-idempotency/' . bin2hex(random_bytes(8));
            }
            $response = (new Dispatcher($hosts->create($context)))->dispatch(
                $route,
                json_encode($envelope, JSON_THROW_ON_ERROR),
            );
            self::assertNull($response->refusalCategory, $route . ' refused: ' . $response->body);
            $decoded = json_decode($response->body, false, 64, JSON_THROW_ON_ERROR);
            self::assertInstanceOf(stdClass::class, $decoded);
            self::assertInstanceOf(stdClass::class, $decoded->value);

            return $decoded->value;
        };
    }

    /**
     * Every consequence code a plan lists, so the request accepts them all.
     *
     * @param   stdClass  $plan  Save plan.
     *
     * @return  list<string>  Consequence codes.
     *
     * @since   2.0.0
     */
    private static function codes(stdClass $plan): array
    {
        $codes = [];
        foreach ($plan->consequences as $consequence) {
            $codes[] = $consequence->code;
        }

        return $codes;
    }

    /**
     * One optional single-line data field for a model draft.
     *
     * @param   string  $key    Field key stored under the entry's data.
     * @param   string  $label  Human label.
     *
     * @return  stdClass  Schema-valid content-model field.
     *
     * @since   2.0.0
     */
    private static function dataField(string $key, string $label): stdClass
    {
        return (object) [
            'id' => 'data_' . $key,
            'kind' => 'string',
            'label' => (object) ['key' => 'kumwe.content/journey-' . $key, 'defaultMessage' => $label],
            'required' => false,
            'localized' => true,
            'cardinality' => 'one',
            'authoring' => (object) [
                'control' => 'studio.control/single-line-text',
                'group' => 'content',
                'order' => 10,
                'width' => 'full',
            ],
            'constraints' => (object) ['maxLength' => 255],
            'extensions' => (object) [
                'kumwe.app/source-field' => (object) ['storage' => 'data', 'key' => $key],
            ],
        ];
    }

    /**
     * Deep-copy one document so a draft never aliases the snapshot it was taken from.
     *
     * @param   stdClass  $document  Document to copy.
     *
     * @return  stdClass  Independent copy.
     *
     * @since   2.0.0
     */
    private static function clone(stdClass $document): stdClass
    {
        $copy = json_decode(json_encode($document, JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $copy);

        return $copy;
    }

    /**
     * Resolve the opaque authoring context key behind one host session key.
     *
     * @param   Container  $container   Booted container.
     * @param   string     $sessionKey  Host session resource-context key the browser holds.
     *
     * @return  string  Authoring context key the host session is bound to.
     *
     * @since   2.0.0
     */
    private static function contextKeyOf(Container $container, string $sessionKey): string
    {
        $sessions = self::service($container, StudioHostSessionRepository::class);
        $session = $sessions->find($sessionKey);
        self::assertNotNull($session);

        return $session->resourceId;
    }

    /**
     * Resolve one container service with its type proven.
     *
     * @template T of object
     *
     * @param   Container        $container  Booted container.
     * @param   class-string<T>  $service    Service identifier.
     *
     * @return  T  Resolved service.
     *
     * @since   2.0.0
     */
    private static function service(Container $container, string $service): object
    {
        $resolved = $container->get($service);
        self::assertInstanceOf($service, $resolved);

        return $resolved;
    }

    /**
     * Authenticate the integration administrator on the administrator surface with a session.
     *
     * @param   Container  $container  Booted container.
     *
     * @return  ExecutionContext  Administrator context bound to one administrator session.
     *
     * @since   2.0.0
     */
    private static function administratorContext(Container $container): ExecutionContext
    {
        TestKernelFactory::administratorContext($container);
        $identities = self::service($container, AdministratorIdentityGateway::class);
        $principal = $identities->authenticate(
            TestKernelFactory::ADMINISTRATOR_EMAIL,
            TestKernelFactory::ADMINISTRATOR_PASSWORD,
            'integration-tests',
        );
        self::assertNotNull($principal);

        return $principal->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'integration-studio-journey-' . bin2hex(random_bytes(8)),
            surface: AuthenticatedSurface::Administrator,
            sessionId: 'administrator-studio-journey-' . bin2hex(random_bytes(8)),
        );
    }
}
