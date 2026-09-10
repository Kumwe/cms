<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Studio;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthenticatedSurface;
use Kumwe\App\Application\Authorization\AuthenticationStrength;
use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\Application\Authorization\SiteContext;
use Kumwe\App\Content\Application\ContentModelService;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Content\Infrastructure\Persistence\DoctrineContentRepository;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Kernel\ContainerFactory;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringCatalog;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextAuthority;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringDocuments;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringService;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringSession;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringState;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringTargetResolver;
use Kumwe\App\Studio\Application\Authoring\HostedContentStudioAuthoringConfigurationProvider;
use Kumwe\App\Studio\Application\Authoring\StudioContextualAuthoringConfigurationProvider;
use Kumwe\App\Studio\Application\Authoring\StudioHostedDeploymentConfiguration;
use Kumwe\App\Studio\Application\Host\StudioAuthoringHostPort;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Host\StudioHostSessionRepository;
use Kumwe\App\Studio\Application\Host\StudioProducerError;
use Kumwe\App\Studio\Application\Host\StudioProducerHostFactory;
use Kumwe\App\Studio\Application\Host\StudioProducerRequestAuthority;
use Kumwe\App\Studio\Application\Projection\ContentStudioProjector;
use Kumwe\App\Studio\Domain\Authoring\StudioAuthoringIntent;
use Kumwe\App\Studio\Infrastructure\Persistence\DoctrineContentStudioAuthoringContextRepository;
use Kumwe\App\Studio\Infrastructure\Release\PinnedStudioContextualAuthoringAvailability;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\Producer\Schema\StudioDocumentSchemaRegistry;
use Kumwe\Producer\Wire\Dispatcher;
use Kumwe\Producer\Wire\RequestEnvelope;
use Kumwe\Producer\Wire\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Drives the contextual authoring journeys through the real container, wire and database.
 *
 * The Content editor mount opens its bindings and emits the deployment; the seven-operation authoring
 * port then answers exactly what the pinned Studio shell sends: target resolution, the reusable-type
 * catalogue, a from-type, blank or existing start, a save plan and the saves that persist a Content
 * entry, publish a reusable type and its successor version, adopt a stored item to that version and
 * advance the opaque context to the stored item, and the conflict a stale save plan is refused with.
 *
 * @since  2.0.0
 */
#[CoversClass(ContentStudioAuthoringService::class)]
#[CoversClass(StudioAuthoringHostPort::class)]
#[CoversClass(HostedContentStudioAuthoringConfigurationProvider::class)]
#[CoversClass(ContentStudioAuthoringTargetResolver::class)]
#[CoversClass(ContentStudioAuthoringContextAuthority::class)]
#[CoversClass(ContentStudioAuthoringDocuments::class)]
#[CoversClass(ContentStudioAuthoringCatalog::class)]
#[CoversClass(ContentStudioAuthoringSession::class)]
#[CoversClass(ContentStudioAuthoringState::class)]
#[CoversClass(DoctrineContentStudioAuthoringContextRepository::class)]
#[CoversClass(PinnedStudioContextualAuthoringAvailability::class)]
#[CoversClass(StudioHostSessionAuthority::class)]
#[CoversClass(StudioProducerError::class)]
#[CoversClass(StudioProducerRequestAuthority::class)]
#[CoversClass(ContentService::class)]
#[CoversClass(DoctrineContentRepository::class)]
#[CoversClass(ContainerFactory::class)]
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
     * A blank canvas becomes a reusable content type, an item is saved under it, and the type then takes a
     * new version that the stored item adopts, all through the wire.
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
        $content = self::service($container, ContentService::class);

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

        // The item drafted on the blank canvas is saved under the new type before that type moves on.
        $item = self::clone($result->session->state->entry);
        $itemSlug = 'journey-type-item-' . bin2hex(random_bytes(4));
        $item->values->title = 'Journey type item';
        $item->values->slug = $itemSlug;
        $item->values->data_summary = 'Summarised through the journey.';
        $itemDraft = (object) ['outcome' => 'save-item', 'entry' => $item];
        $itemPlan = $dispatch('authoring/plan-save', 'intent', (object) [
            'contractVersion' => '0.1-draft',
            'kind' => 'authoring-save-intent',
            'sessionId' => $snapshot->sessionId,
            'expected' => $result->session->state->coordinates,
            'draft' => $itemDraft,
        ], false);
        self::assertSame('save-item', $itemPlan->outcome);
        $saved = $dispatch('authoring/save-item', 'request', (object) [
            'contractVersion' => '0.1-draft',
            'kind' => 'authoring-save-item-request',
            'plan' => (object) [
                'id' => $itemPlan->id,
                'revision' => $itemPlan->revision,
                'successorContext' => $itemPlan->successorContext,
            ],
            'acceptedConsequences' => self::codes($itemPlan),
            'draft' => $itemDraft,
        ], true);
        self::assertSame('save-item', $saved->outcome);
        self::assertSame($createdType->id, $saved->session->type->id);
        $returnPath = $saved->session->extensions->{'kumwe.app/return'}->path;
        self::assertMatchesRegularExpression('#^/administrator/content/[0-9a-f-]{36}/edit$#', $returnPath);
        $entryId = substr($returnPath, strlen('/administrator/content/'), 36);
        $stored = $content->get($context, $entryId);
        self::assertSame($definitionId, $stored->contentTypeId);
        self::assertSame(1, $stored->contentTypeVersion);
        self::assertSame('Journey type item', $stored->entry->title());
        self::assertSame($itemSlug, $stored->entry->slug());
        self::assertSame('Summarised through the journey.', $stored->entry->data()['summary'] ?? null);

        $model = self::clone($saved->session->state->model);
        $model->fields[] = self::dataField('teaser', 'Teaser');
        $versionDraft = (object) [
            'outcome' => 'save-new-type-version',
            'model' => $model,
            'blueprint' => self::clone($saved->session->state->blueprint),
        ];
        $versionPlan = $dispatch('authoring/plan-save', 'intent', (object) [
            'contractVersion' => '0.1-draft',
            'kind' => 'authoring-save-intent',
            'sessionId' => $snapshot->sessionId,
            'expected' => $saved->session->state->coordinates,
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

        // The stored item follows the type to its successor version; nothing the author wrote changed.
        $adopted = $content->get($context, $entryId);
        self::assertSame($definitionId, $adopted->contentTypeId);
        self::assertSame(2, $adopted->contentTypeVersion);
        self::assertSame($stored->entry->version(), $adopted->entry->version());
        self::assertSame('Journey type item', $adopted->entry->title());
        self::assertSame(
            '/administrator/content/' . $entryId . '/edit',
            $versioned->session->extensions->{'kumwe.app/return'}->path,
        );
    }

    /**
     * An edit mount starts the stored item, saves it in place, and refuses a plan that claims the
     * coordinates the editor loaded before that save.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnEditMountStartsTheStoredItemSavesItInPlaceAndRefusesAStalePlan(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = self::administratorContext($container);
        $registry = self::service($container, StudioDocumentSchemaRegistry::class);
        $provider = self::service($container, StudioContextualAuthoringConfigurationProvider::class);
        $targets = self::service($container, ContentStudioAuthoringTargetResolver::class);
        $hosts = self::service($container, StudioProducerHostFactory::class);
        $content = self::service($container, ContentService::class);
        $models = self::service($container, ContentModelService::class);

        $slug = 'studio-edit-journey-' . bin2hex(random_bytes(4));
        try {
            $content->create(
                $context,
                'Edit journey page',
                $slug,
                ['body' => 'Before the journey.'],
                null,
                ContentService::CORE_PAGE_TYPE_ID,
                'not-a-uuid',
            );
            self::fail('A pre-allocated entry identifier that is not a UUID must be refused.');
        } catch (InvalidArgumentException $refused) {
            self::assertSame('A pre-allocated content entry identifier must be a UUID.', $refused->getMessage());
        }
        $record = $content->create($context, 'Edit journey page', $slug, ['body' => 'Before the journey.']);
        $entryId = $record->entry->id();
        $definition = $models->contentType($context, $record->contentTypeId, $record->contentTypeVersion);
        $target = $targets->edit($context, $record, $definition);
        self::assertSame(StudioAuthoringIntent::Edit, $target->intent);

        $configuration = $provider->forMount($context, $target, 'integration-csrf');
        self::assertInstanceOf(StudioHostedDeploymentConfiguration::class, $configuration);
        self::assertSame('/administrator/content/' . $entryId . '/edit', $configuration->returnPath);
        $deployment = json_decode($configuration->configurationJson, false, 32, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $deployment);
        self::assertTrue($registry->validate('studio-deployment', $deployment)->valid());
        self::assertSame('edit', $deployment->launch->intent);
        self::assertSame('existing', $deployment->launch->start->kind);
        $resourceContext = $deployment->session->resourceContext;
        $generation = $deployment->session->sessionGeneration;
        $dispatch = self::dispatcher($hosts, $context, $resourceContext->key, $generation);

        $resolution = $dispatch('authoring/resolve-target', 'request', (object) [
            'targetId' => $deployment->launch->targetId,
            'intent' => 'edit',
            'resourceContext' => $resourceContext,
            'requestedPresentation' => 'inline',
        ], false);
        self::assertTrue($registry->validateDefinition('authoring-target', 'resolution', $resolution)->valid());
        self::assertSame(['existing'], $resolution->availableStarts);

        $snapshot = $dispatch('authoring/start', 'request', (object) [
            'targetId' => $deployment->launch->targetId,
            'resourceContext' => $resourceContext,
            'source' => (object) ['kind' => 'existing'],
            'presentation' => 'inline',
        ], true);
        self::assertTrue($registry->validateDefinition('authoring-session', 'snapshot', $snapshot)->valid());
        self::assertSame('Edit journey page', $snapshot->state->entry->values->title);
        self::assertSame($slug, $snapshot->state->entry->values->slug);
        self::assertSame('Before the journey.', $snapshot->state->entry->values->data_body);
        self::assertContains('save-item', $snapshot->capabilities->saveOutcomes);

        $entry = self::clone($snapshot->state->entry);
        $entry->values->title = 'Edit journey page, revised';
        $entry->values->data_body = 'After the journey.';
        $draft = (object) ['outcome' => 'save-item', 'entry' => $entry];
        $intent = (object) [
            'contractVersion' => '0.1-draft',
            'kind' => 'authoring-save-intent',
            'sessionId' => $snapshot->sessionId,
            'expected' => $snapshot->state->coordinates,
            'draft' => $draft,
        ];
        $plan = $dispatch('authoring/plan-save', 'intent', $intent, false);
        self::assertSame('save-item', $plan->outcome);
        $result = $dispatch('authoring/save-item', 'request', (object) [
            'contractVersion' => '0.1-draft',
            'kind' => 'authoring-save-item-request',
            'plan' => (object) [
                'id' => $plan->id,
                'revision' => $plan->revision,
                'successorContext' => $plan->successorContext,
            ],
            'acceptedConsequences' => self::codes($plan),
            'draft' => $draft,
        ], true);
        self::assertTrue($registry->validateDefinition('authoring-save', 'saveResult', $result)->valid());
        self::assertSame('save-item', $result->outcome);
        self::assertSame(
            '/administrator/content/' . $entryId . '/edit',
            $result->session->extensions->{'kumwe.app/return'}->path,
        );
        self::assertNotEquals($snapshot->state->coordinates, $result->session->state->coordinates);
        $stored = $content->get($context, $entryId);
        self::assertSame('Edit journey page, revised', $stored->entry->title());
        self::assertSame($slug, $stored->entry->slug());
        self::assertSame('After the journey.', $stored->entry->data()['body'] ?? null);
        self::assertSame($record->entry->version() + 1, $stored->entry->version());

        // A plan still claiming the coordinates loaded before that save is refused as a conflict.
        $stale = self::respond(
            $hosts,
            $context,
            $resourceContext->key,
            $generation,
            'authoring/plan-save',
            'intent',
            $intent,
            false,
        );
        self::assertSame('conflict', $stale->refusalCategory);
        self::assertStringContainsString('studio.authoring/expected-mismatch', $stale->body);
    }

    /**
     * A typed create mount starts from its type, and the wire refuses every malformed or contradictory request
     * on the way to a save: foreign arguments, unknown targets and contexts, foreign intents, unusable type
     * references, foreign sessions, empty intents, composed items, moved plans, unplanned or unaccepted
     * consequences, reserved identities, values the schema refuses and fields the model never declared. A new
     * type then takes every supported field kind, a second type with the same label takes a distinct handle,
     * an unsupported kind and a duplicate key are refused, a version that retypes a field and requires a new one
     * is planned as a breaking change, and the reusable-type catalogue pages through a cursor.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testATypedCreateMountRefusesMalformedRequestsAndPublishesEveryFieldKind(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = self::administratorContext($container);
        $provider = self::service($container, StudioContextualAuthoringConfigurationProvider::class);
        $targets = self::service($container, ContentStudioAuthoringTargetResolver::class);
        $hosts = self::service($container, StudioProducerHostFactory::class);
        $models = self::service($container, ContentModelService::class);

        $page = $models->contentType($context, ContentService::CORE_PAGE_TYPE_ID);
        $target = $targets->create($context, $page);
        self::assertSame(
            '/administrator/content/new?content_type=' . ContentService::CORE_PAGE_TYPE_ID,
            $target->returnPath,
        );
        $configuration = $provider->forMount($context, $target, 'integration-csrf');
        self::assertInstanceOf(StudioHostedDeploymentConfiguration::class, $configuration);
        $deployment = json_decode($configuration->configurationJson, false, 32, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $deployment);
        self::assertSame('from-type', $deployment->launch->start->kind);
        self::assertSame('content-type:' . ContentService::CORE_PAGE_TYPE_ID, $deployment->launch->start->type->id);
        $targetId = $deployment->launch->targetId;
        $resourceContext = $deployment->session->resourceContext;
        $key = $resourceContext->key;
        $generation = $deployment->session->sessionGeneration;
        $dispatch = self::dispatcher($hosts, $context, $key, $generation);
        $refused = static function (
            string $route,
            string $member,
            stdClass $argument,
            bool $mutating,
            string $category,
            string $code,
        ) use (
            $hosts,
            $context,
            $key,
            $generation,
        ): void {
            $response = self::respond($hosts, $context, $key, $generation, $route, $member, $argument, $mutating);
            self::assertSame($category, $response->refusalCategory, $route . ' answered: ' . $response->body);
            self::assertStringContainsString($code, $response->body, $route . ' answered: ' . $response->body);
        };
        $resolve = static fn (array $overrides = []): stdClass => (object) [
            'targetId' => $targetId,
            'intent' => 'create',
            'resourceContext' => $resourceContext,
            'requestedPresentation' => 'inline',
            ...$overrides,
        ];
        $foreignContext = self::clone($resourceContext);
        $foreignContext->key = substr($key, 0, -1) . (str_ends_with($key, 'a') ? 'b' : 'a');

        $invalid = 'validation-failed';
        $refused('authoring/resolve-target', 'bogus', $resolve(), false, 'invalid-request', 'invalid-arguments');
        $refused('authoring/resolve-target', 'request', (object) ['targetId' => 7], false, $invalid, 'schema-invalid');
        $foreignIntent = $resolve(['intent' => 'edit']);
        $refused('authoring/resolve-target', 'request', $foreignIntent, false, $invalid, 'intent-mismatch');
        $refused(
            'authoring/resolve-target',
            'request',
            $resolve(['targetId' => 'kumwe.app/elsewhere']),
            false,
            $invalid,
            'unknown-target',
        );
        $refused(
            'authoring/resolve-target',
            'request',
            $resolve(['resourceContext' => $foreignContext]),
            false,
            $invalid,
            'resource-context-mismatch',
        );
        $refused(
            'authoring/list-types',
            'query',
            (object) [
                'targetId' => $targetId,
                'resourceContext' => $resourceContext,
                'limit' => 1,
                'cursor' => 'bogus',
            ],
            false,
            $invalid,
            'invalid-cursor',
        );

        $start = static fn (array $overrides = []): stdClass => (object) [
            'targetId' => $targetId,
            'resourceContext' => $resourceContext,
            'source' => (object) ['kind' => 'blank'],
            'presentation' => 'inline',
            ...$overrides,
        ];
        $unknownType = (object) [
            'id' => 'content-type:018f22e2-7c8b-7ab0-8f3a-88e8026bb999',
            'version' => ContentStudioProjector::modelVersion(1),
            'revision' => ContentStudioProjector::modelRevision(1),
        ];
        $wrongRevision = self::clone($deployment->launch->start->type);
        $wrongRevision->revision = ContentStudioProjector::modelRevision(2);
        $existing = $start(['source' => (object) ['kind' => 'existing']]);
        $refused('authoring/start', 'request', $existing, true, $invalid, 'start-unavailable');
        $drifted = $start(['source' => (object) ['kind' => 'from-type', 'type' => $wrongRevision]]);
        $refused('authoring/start', 'request', $drifted, true, $invalid, 'invalid-type-reference');
        $unknown = $start(['source' => (object) ['kind' => 'from-type', 'type' => $unknownType]]);
        $refused('authoring/start', 'request', $unknown, true, 'not-found', 'type-not-found');
        $snapshot = $dispatch('authoring/start', 'request', $start(['source' => $deployment->launch->start]), true);
        self::assertSame($deployment->launch->start->type->id, $snapshot->state->coordinates->type->id);
        self::assertSame(
            ['save-item', 'save-new-type-version', 'save-as-new-type'],
            $snapshot->capabilities->saveOutcomes,
        );

        $intent = static fn (array $overrides = []): stdClass => (object) [
            'contractVersion' => '0.1-draft',
            'kind' => 'authoring-save-intent',
            'sessionId' => $snapshot->sessionId,
            'expected' => $snapshot->state->coordinates,
            ...$overrides,
        ];
        $entry = self::clone($snapshot->state->entry);
        $entry->values->title = 'Refusals page';
        $entry->values->slug = 'studio-refusals-' . bin2hex(random_bytes(4));
        $entry->values->data_body = 'A body the schema accepts.';
        $itemDraft = (object) ['outcome' => 'save-item', 'entry' => $entry];
        $foreignSession = $intent(['sessionId' => $snapshot->sessionId . '-x', 'draft' => $itemDraft]);
        $refused('authoring/plan-save', 'intent', $foreignSession, false, $invalid, 'session-mismatch');
        $refused('authoring/plan-save', 'intent', $intent(), false, $invalid, 'schema-invalid');
        $composed = (object) [
            'outcome' => 'save-item',
            'entry' => $entry,
            'itemBlueprint' => self::clone($snapshot->state->blueprint),
        ];
        $composedIntent = $intent(['draft' => $composed]);
        $refused('authoring/plan-save', 'intent', $composedIntent, false, $invalid, 'item-composition-denied');

        $saveItem = static fn (
            stdClass $plan,
            stdClass $draft,
            array $accepted,
            ?stdClass $reference = null,
        ): stdClass => (object) [
            'contractVersion' => '0.1-draft',
            'kind' => 'authoring-save-item-request',
            'plan' => $reference ?? (object) [
                'id' => $plan->id,
                'revision' => $plan->revision,
                'successorContext' => $plan->successorContext,
            ],
            'acceptedConsequences' => $accepted,
            'draft' => $draft,
        ];
        $plan = $dispatch('authoring/plan-save', 'intent', $intent(['draft' => $itemDraft]), false);
        $moved = (object) [
            'id' => $plan->id . '-moved',
            'revision' => $plan->revision,
            'successorContext' => $plan->successorContext,
        ];
        $movedSave = $saveItem($plan, $itemDraft, [], $moved);
        $refused('authoring/save-item', 'request', $movedSave, true, 'conflict', 'plan-mismatch');
        $unplanned = $saveItem($plan, $itemDraft, ['kumwe.app/never-planned']);
        $refused('authoring/save-item', 'request', $unplanned, true, $invalid, 'unknown-consequence');
        foreach (
            [
                ['slug', 'administrator', 'invalid-identity'],
                ['title', 5, 'invalid-identity'],
                ['data_body', 123, 'invalid-values'],
                ['data_bogus', 'undeclared', 'unknown-field'],
            ] as [$member, $value, $code]
        ) {
            $broken = self::clone($entry);
            $broken->values->{$member} = $value;
            $brokenDraft = (object) ['outcome' => 'save-item', 'entry' => $broken];
            $brokenPlan = $dispatch('authoring/plan-save', 'intent', $intent(['draft' => $brokenDraft]), false);
            $brokenSave = $saveItem($brokenPlan, $brokenDraft, self::codes($brokenPlan));
            $refused('authoring/save-item', 'request', $brokenSave, true, $invalid, $code);
        }

        $model = self::clone($snapshot->state->model);
        $bounded = (object) ['minimum' => '1', 'maximum' => '10'];
        $model->fields[] = self::field('integer', 'count', ['constraints' => $bounded]);
        $model->fields[] = self::field('decimal', 'ratio', ['constraints' => (object) ['minimum' => '0.5']]);
        $model->fields[] = self::field('boolean', 'featured');
        $model->fields[] = self::field('date', 'starts');
        $model->fields[] = self::field('date-time', 'published');
        $model->fields[] = self::field('media', 'hero');
        $audiences = [];
        foreach (['staff' => 'Staff', 'public' => 'Public'] as $value => $label) {
            $audiences[] = (object) [
                'value' => $value,
                'label' => (object) ['key' => 'kumwe.app/' . $value, 'defaultMessage' => $label],
            ];
        }
        $model->fields[] = self::field('enum', 'audience', ['enumValues' => $audiences]);
        $many = ['cardinality' => 'many', 'constraints' => (object) ['maxLength' => 40]];
        $model->fields[] = self::field('string', 'tags', $many);
        $required = ['required' => true, 'constraints' => (object) ['minLength' => 1]];
        $model->fields[] = self::field('string', 'digest', $required);
        $name = '9 lives ' . bin2hex(random_bytes(3));
        $typeDraft = static fn (stdClass $model, stdClass $blueprint): stdClass => (object) [
            'outcome' => 'save-as-new-type',
            'label' => (object) ['key' => 'kumwe.app/journey-refusals-type', 'defaultMessage' => $name],
            'authoringPolicy' => (object) ['modes' => ['model', 'blueprint', 'content'], 'itemComposition' => 'denied'],
            'model' => $model,
            'blueprint' => $blueprint,
        ];
        $saveType = static fn (stdClass $plan, stdClass $draft, array $accepted): stdClass => (object) [
            'contractVersion' => '0.1-draft',
            'kind' => 'authoring-save-as-new-type-request',
            'plan' => (object) [
                'id' => $plan->id,
                'revision' => $plan->revision,
                'successorContext' => $plan->successorContext,
            ],
            'acceptedConsequences' => $accepted,
            'draft' => $draft,
        ];
        $draft = $typeDraft($model, self::clone($snapshot->state->blueprint));
        $typePlan = $dispatch('authoring/plan-save', 'intent', $intent(['draft' => $draft]), false);
        self::assertSame('save-as-new-type', $typePlan->outcome);
        self::assertTrue($typePlan->confirmationRequired);
        $unconfirmed = $saveType($typePlan, $draft, []);
        $refused('authoring/save-as-new-type', 'request', $unconfirmed, true, $invalid, 'consequences-unaccepted');
        $confirmed = $saveType($typePlan, $draft, self::codes($typePlan));
        $created = $dispatch('authoring/save-as-new-type', 'request', $confirmed, true);
        self::assertSame('save-as-new-type', $created->outcome);
        $definitionId = ContentStudioAuthoringDocuments::contentTypeId($created->session->type->id);
        self::assertIsString($definitionId);
        $definition = $models->contentType($context, $definitionId, 1);
        self::assertStringStartsWith('studio-9-lives-', $definition->handle);
        $schema = $definition->schema();
        $properties = $schema['properties'] ?? [];
        self::assertEquals(
            ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'title' => 'Count'],
            $properties['count'] ?? null,
        );
        $ratio = ['type' => 'number', 'minimum' => 0.5, 'title' => 'Ratio'];
        self::assertEquals($ratio, $properties['ratio'] ?? null);
        self::assertEquals(['type' => 'boolean', 'title' => 'Featured'], $properties['featured'] ?? null);
        $starts = ['type' => 'string', 'format' => 'date', 'title' => 'Starts'];
        self::assertEquals($starts, $properties['starts'] ?? null);
        self::assertEquals(
            ['type' => 'string', 'format' => 'date-time', 'title' => 'Published'],
            $properties['published'] ?? null,
        );
        self::assertEquals(
            ['type' => 'string', 'x-kumwe-field' => 'media', 'title' => 'Hero'],
            $properties['hero'] ?? null,
        );
        self::assertEquals(
            ['type' => 'string', 'enum' => ['staff', 'public'], 'title' => 'Audience'],
            $properties['audience'] ?? null,
        );
        self::assertEquals(
            ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 40], 'title' => 'Tags'],
            $properties['tags'] ?? null,
        );
        self::assertEquals(['type' => 'string', 'minLength' => 1, 'title' => 'Digest'], $properties['digest'] ?? null);
        self::assertContains('digest', $schema['required'] ?? []);

        // The session now follows the new type; the same label again takes the next free handle.
        $typed = self::clone($created->session->state->model);
        $twinDraft = $typeDraft($typed, self::clone($created->session->state->blueprint));
        $twinPlan = $dispatch('authoring/plan-save', 'intent', $intent([
            'expected' => $created->session->state->coordinates,
            'draft' => $twinDraft,
        ]), false);
        $twinSave = $saveType($twinPlan, $twinDraft, self::codes($twinPlan));
        $twin = $dispatch('authoring/save-as-new-type', 'request', $twinSave, true);
        $twinId = ContentStudioAuthoringDocuments::contentTypeId($twin->session->type->id);
        self::assertIsString($twinId);
        self::assertSame($definition->handle . '-2', $models->contentType($context, $twinId, 1)->handle);

        // A kind the Content schema cannot carry, and two fields sharing one key, are refused at the save.
        foreach (
            [
                [self::field('money', 'price'), 'unsupported-field'],
                [self::field('string', 'summary2', ['extensions' => (object) [
                    'kumwe.app/source-field' => (object) ['storage' => 'data', 'key' => 'summary'],
                ]]), 'unsupported-model'],
            ] as [$field, $code]
        ) {
            $unsupported = self::clone($twin->session->state->model);
            $unsupported->fields[] = $field;
            $unsupportedDraft = $typeDraft($unsupported, self::clone($twin->session->state->blueprint));
            $unsupportedPlan = $dispatch('authoring/plan-save', 'intent', $intent([
                'expected' => $twin->session->state->coordinates,
                'draft' => $unsupportedDraft,
            ]), false);
            $refused(
                'authoring/save-as-new-type',
                'request',
                $saveType($unsupportedPlan, $unsupportedDraft, self::codes($unsupportedPlan)),
                true,
                'validation-failed',
                $code,
            );
        }

        // Retyping a field and requiring a new one is a breaking change the plan says out loud.
        $breaking = self::clone($twin->session->state->model);
        foreach ($breaking->fields as $field) {
            if ($field->id === 'data_count') {
                $field->kind = 'string';
                unset($field->constraints);
            }
        }
        $breaking->fields[] = self::field('string', 'teaser', ['required' => true]);
        $breakingPlan = $dispatch('authoring/plan-save', 'intent', $intent([
            'expected' => $twin->session->state->coordinates,
            'draft' => (object) [
                'outcome' => 'save-new-type-version',
                'model' => $breaking,
                'blueprint' => self::clone($twin->session->state->blueprint),
            ],
        ]), false);
        self::assertSame('save-new-type-version', $breakingPlan->outcome);
        self::assertContains(ContentStudioAuthoringService::BREAKING_CHANGE, self::codes($breakingPlan));

        // The reusable-type catalogue pages through a cursor once more than one type is published.
        $listing = static fn (array $overrides = []): stdClass => (object) [
            'targetId' => $targetId,
            'resourceContext' => $resourceContext,
            'limit' => 100,
            ...$overrides,
        ];
        $all = $dispatch('authoring/list-types', 'query', $listing(), false);
        $first = $dispatch('authoring/list-types', 'query', $listing(['limit' => 1]), false);
        self::assertGreaterThan(1, count($all->items));
        self::assertCount(1, $first->items);
        self::assertSame('offset:1', $first->nextCursor ?? null);
        $rest = $dispatch('authoring/list-types', 'query', $listing(['cursor' => 'offset:1']), false);
        self::assertCount(count($all->items) - 1, $rest->items);
        self::assertObjectNotHasProperty('nextCursor', $rest);
    }

    /**
     * One optional single-value data field of the given kind for a model draft.
     *
     * @param   string                $kind    Content-model field kind.
     * @param   string                $key     Field key stored under the entry's data.
     * @param   array<string, mixed>  $extras  Members overriding the defaults, such as constraints or enumValues.
     *
     * @return  stdClass  Schema-valid content-model field.
     *
     * @since   2.0.0
     */
    private static function field(string $kind, string $key, array $extras = []): stdClass
    {
        return (object) [
            'id' => 'data_' . $key,
            'kind' => $kind,
            'label' => (object) ['key' => 'kumwe.content/journey-' . $key, 'defaultMessage' => ucfirst($key)],
            'required' => false,
            'localized' => false,
            'cardinality' => 'one',
            'extensions' => (object) [
                'kumwe.app/source-field' => (object) ['storage' => 'data', 'key' => $key],
            ],
            ...$extras,
        ];
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
            $response = self::respond($hosts, $context, $key, $generation, $route, $member, $argument, $mutating);
            self::assertNull($response->refusalCategory, $route . ' refused: ' . $response->body);
            $decoded = json_decode($response->body, false, 64, JSON_THROW_ON_ERROR);
            self::assertInstanceOf(stdClass::class, $decoded);
            self::assertInstanceOf(stdClass::class, $decoded->value);

            return $decoded->value;
        };
    }

    /**
     * Dispatch one authoring route through a fresh request-scoped host and return the wire response as is.
     *
     * @param   StudioProducerHostFactory  $hosts       Request-scoped host factory.
     * @param   ExecutionContext           $context     Administrator context.
     * @param   string                     $key         Host session resource-context key.
     * @param   string                     $generation  Host session generation.
     * @param   string                     $route       Authoring route such as `authoring/plan-save`.
     * @param   string                     $member      Argument member the route reads.
     * @param   stdClass                   $argument    Argument document.
     * @param   bool                       $mutating    Whether the route needs an idempotency key.
     *
     * @return  Response  Wire response, refused or not.
     *
     * @since   2.0.0
     */
    private static function respond(
        StudioProducerHostFactory $hosts,
        ExecutionContext $context,
        string $key,
        string $generation,
        string $route,
        string $member,
        stdClass $argument,
        bool $mutating,
    ): Response {
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

        return (new Dispatcher($hosts->create($context)))->dispatch(
            $route,
            json_encode($envelope, JSON_THROW_ON_ERROR),
        );
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
