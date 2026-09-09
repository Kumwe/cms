<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Authoring;

use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringDocuments;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringTarget;
use Kumwe\App\Studio\Domain\Authoring\StudioAuthoringIntent;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\Producer\Schema\StudioDocumentSchemaRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves the deterministic Studio document fragments the Content authoring host emits.
 *
 * @since  2.0.0
 */
#[CoversClass(ContentStudioAuthoringDocuments::class)]
final class ContentStudioAuthoringDocumentsTest extends TestCase
{
    /**
     * The target declaration is schema-valid against Producer's pinned corpus, with and without dependencies.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheDeclarationIsValidAgainstThePinnedSchema(): void
    {
        $registry = StudioDocumentSchemaRegistry::fromVendoredCorpus();
        $dependency = (object) [
            'kind' => 'block-definition',
            'id' => 'core/field-text',
            'versions' => '1.0.0',
            'required' => true,
        ];

        $bare = ContentStudioAuthoringDocuments::declaration();
        $dependent = ContentStudioAuthoringDocuments::declaration([$dependency]);

        self::assertTrue($registry->validateDefinition('authoring-target', 'declaration', $bare)->valid());
        self::assertTrue($registry->validateDefinition('authoring-target', 'declaration', $dependent)->valid());
        self::assertSame(ContentStudioAuthoringTarget::TARGET_ID, $bare->id);
        self::assertSame([], $bare->contributionDependencies);
        self::assertSame([$dependency], $dependent->contributionDependencies);
    }

    /**
     * A create context names the session's provisional entry; an edit context names the stored entry.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheResourceContextAlwaysNamesAResource(): void
    {
        $registry = StudioDocumentSchemaRegistry::fromVendoredCorpus();
        $session = self::session('contexts/' . str_repeat('a', 64));
        $create = new ContentStudioAuthoringTarget(
            StudioAuthoringIntent::Create,
            null,
            null,
            null,
            null,
            null,
            '/administrator/content/new',
        );
        $edit = new ContentStudioAuthoringTarget(
            StudioAuthoringIntent::Edit,
            'content-model:0192d1c4-7a5e-7c4a-9f4b-2f2e5d6a7b8c',
            '0.0.3',
            'content-type-v3',
            'content-entry:0192d1c4-7a5e-7c4a-9f4b-2f2e5d6a7b8d',
            'content-entry-v2',
            '/administrator/content/0192d1c4-7a5e-7c4a-9f4b-2f2e5d6a7b8d/edit',
        );

        $createContext = ContentStudioAuthoringDocuments::resourceContext($session, $create);
        $editContext = ContentStudioAuthoringDocuments::resourceContext($session, $edit);

        foreach ([[$create, $createContext], [$edit, $editContext]] as [$target, $context]) {
            self::assertTrue($registry->validateDefinition('authoring-target', 'resolveRequest', (object) [
                'targetId' => ContentStudioAuthoringTarget::TARGET_ID,
                'intent' => $target->intent->value,
                'resourceContext' => $context,
            ])->valid());
            self::assertSame($session->resourceContextKey, $context->key);
            self::assertSame(ContentStudioAuthoringTarget::SURFACE, $context->surface);
            self::assertSame('sites/default', $context->scopes[0]->id);
            self::assertSame(ContentStudioAuthoringDocuments::RESOURCE_TYPE, $context->resource->type);
        }
        self::assertSame(
            ContentStudioAuthoringDocuments::draftEntryId($session->resourceContextKey),
            $createContext->resource->id,
        );
        self::assertSame('content-entry:0192d1c4-7a5e-7c4a-9f4b-2f2e5d6a7b8d', $editContext->resource->id);
    }

    /**
     * Session, return and draft identifiers are stable, key-free projections of the opaque context key.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testIdentifiersAreStableAndCarryNoKeyMaterial(): void
    {
        $key = 'contexts/' . str_repeat('b', 64);

        $sessionId = ContentStudioAuthoringDocuments::sessionId($key);
        self::assertSame($sessionId, ContentStudioAuthoringDocuments::sessionId($key));
        self::assertStringNotContainsString(str_repeat('b', 16), $sessionId);
        self::assertMatchesRegularExpression('#^sessions/[0-9a-f]{40}$#', $sessionId);
        self::assertNotSame(
            ContentStudioAuthoringDocuments::returnContext($key)->key,
            ContentStudioAuthoringDocuments::returnContext($key, 'save-plans/1')->key,
        );
        self::assertMatchesRegularExpression(
            '#^content-entry:[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$#',
            ContentStudioAuthoringDocuments::draftEntryId($key),
        );
        self::assertNotSame(
            ContentStudioAuthoringDocuments::draftEntryId($key),
            ContentStudioAuthoringDocuments::draftEntryId('contexts/' . str_repeat('c', 64)),
        );
        self::assertMatchesRegularExpression(
            '#^content-model:draft/[0-9a-f]{32}$#',
            ContentStudioAuthoringDocuments::draftModelId($key),
        );
        self::assertMatchesRegularExpression(
            '#^content-blueprint:draft/[0-9a-f]{32}$#',
            ContentStudioAuthoringDocuments::draftBlueprintId($key),
        );
        $typeId = ContentStudioAuthoringDocuments::typeId('0192D1C4-7A5E-7C4A-9F4B-2F2E5D6A7B8C');
        $decoded = ContentStudioAuthoringDocuments::contentTypeId($typeId);
        self::assertSame('0192d1c4-7a5e-7c4a-9f4b-2f2e5d6a7b8c', $decoded);
        self::assertNull(
            ContentStudioAuthoringDocuments::contentTypeId('content-model:0192d1c4-7a5e-7c4a-9f4b-2f2e5d6a7b8c'),
        );
    }

    /**
     * Messages are bounded and diagnostics carry parameters only when given.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMessagesAndDiagnosticsAreBounded(): void
    {
        $long = ContentStudioAuthoringDocuments::message('kumwe.app/long', str_repeat('x', 600));
        $blank = ContentStudioAuthoringDocuments::message('kumwe.app/blank', '   ');
        $plain = ContentStudioAuthoringDocuments::diagnostic('kumwe.app/plain', 'warning', 'Plain');
        $parameterized = ContentStudioAuthoringDocuments::diagnostic('kumwe.app/with', 'error', 'With', ['count' => 2]);

        self::assertSame(500, mb_strlen($long->defaultMessage));
        self::assertSame('Untitled', $blank->defaultMessage);
        self::assertObjectNotHasProperty('parameters', $plain);
        self::assertSame(2, $parameterized->parameters->count);
    }

    /**
     * Build one contextual host session for the default site.
     *
     * @param   string  $key  Opaque resource-context key.
     *
     * @return  StudioHostSession  Hybrid Content authoring host session.
     *
     * @since   2.0.0
     */
    private static function session(string $key): StudioHostSession
    {
        return new StudioHostSession(
            $key,
            'users/author',
            'default',
            null,
            null,
            'administrator',
            str_repeat('d', 64),
            StudioSessionMode::Hybrid,
            StudioResourceKind::ContentAuthoring,
            'contexts/' . str_repeat('c', 64),
            'generation-1',
        );
    }
}
