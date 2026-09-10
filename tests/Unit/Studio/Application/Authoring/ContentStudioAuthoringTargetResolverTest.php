<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Authoring;

use DateTimeImmutable;
use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Content\Domain\ContentEntry;
use Kumwe\App\Content\Domain\ContentStatus;
use Kumwe\App\Content\Domain\ContentTypeDefinition;
use Kumwe\App\Content\Domain\PublicationWindow;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringTarget;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringTargetMismatch;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringTargetResolver;
use Kumwe\App\Studio\Application\Projection\ContentStudioProjector;
use Kumwe\App\Studio\Domain\Authoring\StudioAuthoringIntent;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves Content New/Edit resolve one PHP-owned Studio target without trusting browser coordinates.
 *
 * @since  2.0.0
 */
#[CoversClass(ContentStudioAuthoringTargetResolver::class)]
#[CoversClass(ContentStudioAuthoringTarget::class)]
#[CoversClass(ContentStudioAuthoringTargetMismatch::class)]
#[CoversClass(StudioAuthoringIntent::class)]
#[UsesClass(ContentStudioProjector::class)]
final class ContentStudioAuthoringTargetResolverTest extends TestCase
{
    /**
     * An unqualified Content New visit remains blank even though the fallback uses Page fields.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBlankCreateHasNoReusableTypeOrPreviousEntryCoordinates(): void
    {
        $target = $this->resolver()->create(AuthorizationContext::human(['content.create']));

        self::assertSame(StudioAuthoringIntent::Create, $target->intent);
        self::assertNull($target->modelId);
        self::assertNull($target->modelVersion);
        self::assertNull($target->modelRevision);
        self::assertNull($target->entryId);
        self::assertNull($target->entryRevision);
        self::assertSame('/administrator/content/new', $target->returnPath);
    }

    /**
     * An explicit reusable type is pinned to its exact projected version and revision.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCreateFromTypeCarriesOnlyTheExplicitExactType(): void
    {
        $definition = $this->definition(version: 3);
        $target = $this->resolver()->create(
            AuthorizationContext::human(['content.create']),
            $definition,
        );

        self::assertSame('content-model:' . $definition->id, $target->modelId);
        self::assertSame('0.0.3', $target->modelVersion);
        self::assertSame('content-type-v3', $target->modelRevision);
        self::assertNull($target->entryId);
        self::assertStringEndsWith('?content_type=' . $definition->id, $target->returnPath);
    }

    /**
     * Edit resolution retains the stored type version and current optimistic Entry revision.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEditPinsTheExactStoredModelAndEntryRevision(): void
    {
        $definition = $this->definition(version: 3);
        $entryId = '018f22e2-7c8b-7ab0-8f3a-88e8026bb501';
        $record = new ContentRecord(
            ContentEntry::reconstitute(
                $entryId,
                'Trusted page',
                'trusted-page',
                [],
                ContentStatus::Draft,
                PublicationWindow::unbounded(),
                7,
            ),
            $definition->id,
            ContentService::CORE_WORKFLOW_ID,
            new DateTimeImmutable('2026-08-27T00:00:00+00:00'),
            new DateTimeImmutable('2026-08-27T00:00:00+00:00'),
            contentTypeVersion: 3,
        );

        $target = $this->resolver()->edit(
            AuthorizationContext::human(['content.update']),
            $record,
            $definition,
        );

        self::assertSame(StudioAuthoringIntent::Edit, $target->intent);
        self::assertSame('content-model:' . $definition->id, $target->modelId);
        self::assertSame('content-type-v3', $target->modelRevision);
        self::assertSame('content-entry:' . $entryId, $target->entryId);
        self::assertSame('content-entry-v7', $target->entryRevision);
        self::assertSame('/administrator/content/' . $entryId . '/edit', $target->returnPath);
    }

    /**
     * Target resolution independently refuses a caller without the relevant Content mutation grant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testResolutionRequiresTheExactContentMutationAuthority(): void
    {
        $this->expectException(AuthorizationDenied::class);

        $this->resolver()->create(AuthorizationContext::human(['content.read']));
    }

    /**
     * A definition from another site cannot be turned into a local authoring target.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCreateRefusesATypeFromAnotherSite(): void
    {
        $this->expectException(ContentStudioAuthoringTargetMismatch::class);

        $this->resolver()->create(
            AuthorizationContext::human(['content.create']),
            $this->definition(SiteContext::fromString('foreign-site')),
        );
    }

    /**
     * Edit cannot combine an Entry with a different immutable Content-type version.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEditRefusesMismatchedAuthoritativeCoordinates(): void
    {
        $definition = $this->definition(version: 3);
        $record = new ContentRecord(
            ContentEntry::reconstitute(
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb502',
                'Mismatched page',
                'mismatched-page',
                [],
                ContentStatus::Draft,
                PublicationWindow::unbounded(),
                1,
            ),
            $definition->id,
            ContentService::CORE_WORKFLOW_ID,
            new DateTimeImmutable('2026-08-27T00:00:00+00:00'),
            new DateTimeImmutable('2026-08-27T00:00:00+00:00'),
            contentTypeVersion: 2,
        );

        $this->expectException(ContentStudioAuthoringTargetMismatch::class);
        $this->expectExceptionMessage('The Content authoring target coordinates are inconsistent.');

        $this->resolver()->edit(
            AuthorizationContext::human(['content.update']),
            $record,
            $definition,
        );
    }

    /**
     * Build the resolver over the same deny-by-default gateway production uses.
     *
     * @return  ContentStudioAuthoringTargetResolver  Resolver under test.
     *
     * @since   2.0.0
     */
    private function resolver(): ContentStudioAuthoringTargetResolver
    {
        return new ContentStudioAuthoringTargetResolver(AuthorizationContext::gateway());
    }

    /**
     * Build one exact published reusable Content type.
     *
     * @param   ?SiteContext  $site     Owning site; defaults to the active test site.
     * @param   int           $version  Exact immutable definition version.
     *
     * @return  ContentTypeDefinition  Valid definition fixture.
     *
     * @since   2.0.0
     */
    private function definition(?SiteContext $site = null, int $version = 1): ContentTypeDefinition
    {
        $time = new DateTimeImmutable('2026-08-27T00:00:00+00:00');

        return new ContentTypeDefinition(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb402',
            $site ?? SiteContext::default(),
            'page',
            'Page',
            ContentService::CORE_WORKFLOW_ID,
            1,
            ['type' => 'object', 'properties' => ['body' => ['type' => 'string']]],
            $version,
            $time,
            $time,
        );
    }
}
