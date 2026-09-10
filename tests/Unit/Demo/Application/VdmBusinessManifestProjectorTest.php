<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Demo\Application;

use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\Demo\Application\VdmBusinessManifestProjector;
use Kumwe\App\Demo\Infrastructure\FilesystemDemoManifestCatalog;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Proves the released VDM template becomes a coherent site-owned graph without changing its source.
 *
 * @since  2.0.0
 */
#[CoversClass(VdmBusinessManifestProjector::class)]
#[UsesClass(FilesystemDemoManifestCatalog::class)]
final class VdmBusinessManifestProjectorTest extends TestCase
{
    /**
     * Project every definition, relationship target, and record operation into a non-default site.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAggregateProjectionIsCompleteAndLeavesTheTemplateUntouched(): void
    {
        $source = (new FilesystemDemoManifestCatalog(dirname(__DIR__, 4)))->vdmBusiness()['manifest'];
        $probeUuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $probeHandle = 'site.default.vdm_business_value';
        $source['projection_probe'] = ['uuid' => $probeUuid, 'handle' => $probeHandle];
        $projected = (new VdmBusinessManifestProjector())->forSite(
            $source,
            SiteContext::fromString('customer-east'),
        );

        self::assertSame('default', $source['site_template'] ?? null);
        self::assertSame('customer-east', $projected['site_template'] ?? null);
        $documents = $projected['definition_documents'] ?? null;
        self::assertIsArray($documents);
        self::assertCount(12, $documents);
        foreach ($documents as $document) {
            self::assertIsArray($document);
            $definition = EntityTypeDefinition::fromArray($document);
            self::assertSame('customer-east', $definition->siteIdentifier);
            self::assertSame('customer-east', $definition->owner->identifier);
            self::assertStringStartsWith('site.customer-east.vdm_', $definition->handle);
        }

        $records = $projected['records_document'] ?? null;
        self::assertIsArray($records);
        self::assertSame('customer-east', $records['site'] ?? null);
        $encoded = json_encode($projected, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('site.default.vdm_client_account', $encoded);
        self::assertStringContainsString('site.customer-east.vdm_client_account', $encoded);

        $sourceDocuments = $source['definition_documents'] ?? null;
        self::assertIsArray($sourceDocuments);
        $sourceClient = $sourceDocuments['definition.client_account'] ?? null;
        self::assertIsArray($sourceClient);
        self::assertSame('default', $sourceClient['site'] ?? null);
        self::assertSame('site.default.vdm_client_account', $sourceClient['handle'] ?? null);
        self::assertNotSame($sourceClient['id'] ?? null, $documents['definition.client_account']['id'] ?? null);
        self::assertSame(
            $projected,
            (new VdmBusinessManifestProjector())->forSite($source, SiteContext::fromString('customer-east')),
        );

        $sourceUuids = $this->identityUuids($source);
        $projectedUuids = $this->identityUuids($projected);
        self::assertCount(92, $sourceUuids);
        self::assertCount(92, $projectedUuids);
        self::assertSame([], array_values(array_intersect($sourceUuids, $projectedUuids)));
        self::assertSame($probeUuid, $projected['projection_probe']['uuid'] ?? null);
        self::assertSame($probeHandle, $projected['projection_probe']['handle'] ?? null);
    }

    /**
     * Reject source documents whose declared site would be silently overwritten by projection.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProjectionRejectsAContradictorySourceTemplate(): void
    {
        $source = (new FilesystemDemoManifestCatalog(dirname(__DIR__, 4)))->vdmBusiness()['manifest'];
        $source['site_template'] = 'foreign';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must declare the default site template');

        (new VdmBusinessManifestProjector())->forSite($source, SiteContext::default());
    }

    /**
     * Treat JSON object order as insignificant while retaining the exact owner field set.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProjectionAcceptsSemanticallyEquivalentOwnerKeyOrder(): void
    {
        $source = (new FilesystemDemoManifestCatalog(dirname(__DIR__, 4)))->vdmBusiness()['manifest'];
        $documents = $source['definition_documents'] ?? null;
        self::assertIsArray($documents);
        $client = $documents['definition.client_account'] ?? null;
        self::assertIsArray($client);
        $client['owner'] = ['identifier' => 'default', 'type' => 'site'];
        $documents['definition.client_account'] = $client;
        $source['definition_documents'] = $documents;

        $projected = (new VdmBusinessManifestProjector())->forSite(
            $source,
            SiteContext::fromString('customer-east'),
        );

        self::assertSame('customer-east', $projected['site_template'] ?? null);
    }

    /**
     * Project a differently named business profile through the same namespace derivation as VDM.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProjectionDerivesNamespacesFromTheTemplateProfileName(): void
    {
        $source = (new FilesystemDemoManifestCatalog(dirname(__DIR__, 4)))->vdmBusiness()['manifest'];
        $encoded = json_encode($source, JSON_THROW_ON_ERROR);
        $renamed = str_replace(['"vdm"', 'site.default.vdm_'], ['"farming"', 'site.default.farming_'], $encoded);
        /** @var array<string, mixed> $farming */
        $farming = json_decode($renamed, true, 64, JSON_THROW_ON_ERROR);

        $projected = (new VdmBusinessManifestProjector())->forSite(
            $farming,
            SiteContext::fromString('customer-east'),
        );

        $reencoded = json_encode($projected, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('site.customer-east.farming_client_account', $reencoded);
        self::assertStringNotContainsString('site.default.farming_client_account', $reencoded);
        $vdmProjection = (new VdmBusinessManifestProjector())->forSite(
            $source,
            SiteContext::fromString('customer-east'),
        );
        self::assertNotSame(
            $this->identityUuids($vdmProjection),
            $this->identityUuids($projected),
        );
    }

    /**
     * Refuse a template that declares no valid profile name to anchor its namespaces.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProjectionRejectsAMissingProfileName(): void
    {
        $source = (new FilesystemDemoManifestCatalog(dirname(__DIR__, 4)))->vdmBusiness()['manifest'];
        unset($source['profile']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('declares no valid profile name');

        (new VdmBusinessManifestProjector())->forSite($source, SiteContext::default());
    }

    /**
     * Reject site identifiers that cannot form the business owner namespace or a portable handle.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProjectionRejectsSiteIdentifiersOutsideBusinessDefinitionBounds(): void
    {
        $source = (new FilesystemDemoManifestCatalog(dirname(__DIR__, 4)))->vdmBusiness()['manifest'];

        try {
            (new VdmBusinessManifestProjector())->forSite($source, SiteContext::fromString('tenant:west'));
            self::fail('The business-definition owner constraint was not enforced.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('cannot own business demo definitions', $exception->getMessage());
        }

        try {
            (new VdmBusinessManifestProjector())->forSite($source, SiteContext::fromString('tenant..west'));
            self::fail('The business-definition handle grammar was not enforced.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('cannot form a portable handle', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot form a portable handle');

        (new VdmBusinessManifestProjector())->forSite($source, SiteContext::fromString(str_repeat('a', 191)));
    }

    /**
     * Normalize a document exported with published lifecycle state back to the released-draft shape.
     *
     * The installer publishes every template document with `EntityTypeDefinition::published()`, which only a
     * version-zero draft survives. A package written from a running site carries `published` and its source
     * version; once such a package installed, every later reconciliation died in preflight. Projection now
     * hands the installer a draft whose republished checksum equals the released draft's, so the comparison
     * against the checkpoint can happen at all.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProjectionNormalizesPublishedDocumentsToTheReleasedDraftShape(): void
    {
        $source = (new FilesystemDemoManifestCatalog(dirname(__DIR__, 4)))->vdmBusiness()['manifest'];
        $documents = $source['definition_documents'] ?? null;
        self::assertIsArray($documents);
        $released = $documents['definition.client_account'] ?? null;
        self::assertIsArray($released);
        $exported = $released;
        $exported['status'] = 'published';
        $exported['definition_version'] = 3;
        $documents['definition.client_account'] = $exported;
        $source['definition_documents'] = $documents;

        $projected = (new VdmBusinessManifestProjector())->forSite($source, SiteContext::default());

        $document = $projected['definition_documents']['definition.client_account'] ?? null;
        self::assertIsArray($document);
        self::assertSame('draft', $document['status'] ?? null);
        self::assertSame(0, $document['definition_version'] ?? null);
        self::assertSame(
            EntityTypeDefinition::fromArray($released)->published(3)->checksum(),
            EntityTypeDefinition::fromArray($document)->published(3)->checksum(),
        );
    }

    /**
     * Collect the definition and record UUIDs that form the globally persisted identity graph.
     *
     * @param   array<string, mixed>  $manifest  Projected or source aggregate.
     *
     * @return  list<string>  Sorted distinct UUID strings.
     *
     * @since   2.0.0
     */
    private function identityUuids(array $manifest): array
    {
        $uuids = [];
        $documents = $manifest['definition_documents'] ?? null;
        self::assertIsArray($documents);
        foreach ($documents as $document) {
            self::assertIsArray($document);
            $id = $document['id'] ?? null;
            self::assertIsString($id);
            self::assertTrue(Uuid::isValid($id));
            $uuids[] = $id;
        }
        $records = $manifest['records_document']['records'] ?? null;
        self::assertIsArray($records);
        foreach ($records as $record) {
            self::assertIsArray($record);
            $id = $record['record_id'] ?? null;
            self::assertIsString($id);
            self::assertTrue(Uuid::isValid($id));
            $uuids[] = $id;
        }
        $uuids = array_values(array_unique($uuids));
        sort($uuids);

        return $uuids;
    }
}
