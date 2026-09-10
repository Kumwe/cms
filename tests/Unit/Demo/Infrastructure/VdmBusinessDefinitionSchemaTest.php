<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Demo\Infrastructure;

use DateTimeImmutable;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionValidator;
use Kumwe\App\BusinessDefinition\Application\DefinitionCatalogEntry;
use Kumwe\App\BusinessDefinition\Application\DefinitionVersionRecord;
use Kumwe\App\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\App\BusinessDefinition\Domain\CompatibilityPlan;
use Kumwe\App\BusinessDefinition\Domain\DefinitionStatus;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessSchema\Domain\PhysicalNameCompiler;
use Kumwe\App\BusinessSchema\Infrastructure\Schema\CanonicalDefinitionPhysicalSchemaCompiler;
use Kumwe\App\Demo\Infrastructure\FilesystemDemoManifestCatalog;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Pins the bundled VDM definitions to the canonical validation and physical-schema contracts.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class VdmBusinessDefinitionSchemaTest extends TestCase
{
    /**
     * Proves every bundled definition validates as one graph and compiles without a database connection.
     *
     * This reaches the canonical compiler's portable whole-value index checks before the installer creates
     * a schema plan, so a fixture cannot request an index that MySQL, PostgreSQL, and SQLite would represent
     * differently.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryBundledDefinitionCompilesToAPortablePhysicalSchema(): void
    {
        $manifest = (new FilesystemDemoManifestCatalog(dirname(__DIR__, 4)))->vdmBusiness()['manifest'];
        $documents = $manifest['definition_documents'] ?? null;
        self::assertIsArray($documents);

        $drafts = [];
        foreach ($documents as $document) {
            self::assertIsArray($document);
            self::assertFalse(array_is_list($document));
            $drafts[] = EntityTypeDefinition::fromArray($document);
        }
        self::assertCount(12, $drafts);

        $fieldTypes = new FieldTypeRegistry();
        (new BusinessDefinitionValidator($fieldTypes))->validateGraph($drafts);

        $definitions = [];
        foreach ($drafts as $draft) {
            $published = $draft->published(1);
            $definitions[$published->handle] = $published;
        }
        $compiler = new CanonicalDefinitionPhysicalSchemaCompiler(
            $this->repository($definitions),
            $fieldTypes,
            new PhysicalNameCompiler('kumwe_'),
        );
        foreach ($definitions as $definition) {
            $blueprint = $compiler->compile($definition, SiteContext::default());
            self::assertSame($definition->id, $blueprint->definitionId);
            self::assertSame($definition->definitionVersion, $blueprint->definitionVersion);
            self::assertSame($definition->checksum(), $blueprint->definitionChecksum);
            self::assertNotNull($blueprint->table('record'));
        }
    }

    /**
     * Build the published target catalog required when the compiler resolves fixture relationships.
     *
     * @param   array<string, EntityTypeDefinition>  $definitions  Published definitions keyed by handle.
     *
     * @return  BusinessDefinitionRepository  Read stub serving the exact fixture versions.
     *
     * @since   2.0.0
     */
    private function repository(array $definitions): BusinessDefinitionRepository
    {
        $entries = [];
        $versions = [];
        $publishedAt = new DateTimeImmutable('2026-08-11T00:00:00+00:00');
        foreach ($definitions as $definition) {
            $entries[$definition->handle] = new DefinitionCatalogEntry(
                $definition->id,
                $definition->siteIdentifier,
                $definition->handle,
                $definition->owner,
                true,
                0,
                $definition->definitionVersion,
                DefinitionStatus::Published,
                $publishedAt,
            );
            $versions[$definition->handle] = new DefinitionVersionRecord(
                $definition,
                new CompatibilityPlan(null, 1, null, $definition->checksum(), []),
                DefinitionStatus::Published,
                '00000000-0000-7000-8000-000000000001',
                $publishedAt,
            );
        }

        $repository = $this->createStub(BusinessDefinitionRepository::class);
        $repository->method('entry')->willReturnCallback(
            static fn (SiteContext $site, string $identifier): ?DefinitionCatalogEntry =>
                $site->identifier() === 'default' ? ($entries[$identifier] ?? null) : null,
        );
        $repository->method('published')->willReturnCallback(
            static fn (SiteContext $site, string $identifier, ?int $version = null): ?DefinitionVersionRecord =>
                $site->identifier() === 'default' && ($version === null || $version === 1)
                    ? ($versions[$identifier] ?? null)
                    : null,
        );

        return $repository;
    }
}
