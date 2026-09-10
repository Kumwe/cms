<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\OpenApi\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\App\BusinessDefinition\Application\DefinitionCatalogEntry;
use Kumwe\App\BusinessDefinition\Application\DefinitionVersionRecord;
use Kumwe\App\BusinessDefinition\Domain\CompatibilityPlan;
use Kumwe\App\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\App\BusinessDefinition\Domain\DefinitionStatus;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\Extension\Manifest\ExtensionIdentifier;
use Kumwe\Extension\Manifest\ExtensionManifest;
use Kumwe\Extension\Manifest\ManifestContributions;
use Kumwe\Extension\Manifest\ExtensionType;
use Kumwe\Extension\Manifest\SemanticVersion;
use Kumwe\Extension\Manifest\VersionConstraint;
use Kumwe\App\OpenApi\Application\OpenApiComponentClaimAdmission;
use Kumwe\App\OpenApi\Application\OpenApiExtensionActivationAdmission;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpenApiExtensionActivationAdmission::class)]
/**
 * Pins activation-time component admission independently of public contract requests.
 *
 * @since  2.0.0
 */
final class OpenApiExtensionActivationAdmissionTest extends TestCase
{
    /**
     * Proves two distinct owner-valid handles cannot normalize to the same public component family.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsNormalizedComponentCollisionAcrossActivePackages(): void
    {
        $candidate = self::manifest('acme/foo-bar', 'acme.foo-bar.item', '018f5200-0000-7000-8000-000000000001');
        $existing = self::manifest('acme/foo_bar', 'acme.foo_bar.item', '018f5200-0000-7000-8000-000000000002');
        $admission = new OpenApiExtensionActivationAdmission(new OpenApiComponentClaimAdmission(self::core()));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('collides or is unsafe');

        $admission->admit($candidate, SiteContext::default(), [$candidate, $existing]);
    }

    /**
     * Proves compiler-owned golden components do not collide with a newly admitted safe entity.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAcceptsSafeCandidateAndIgnoresCompilerOwnedGoldenComponents(): void
    {
        $candidate = self::manifest('acme/editor', 'acme.editor.item', '018f5200-0000-7000-8000-000000000003');
        $core = self::core();
        $core['components']['schemas']['GeneratedBusinessRecord'] = ['type' => 'object'];
        $core['x-kumwe-generated-components'] = ['GeneratedBusinessRecord'];

        (new OpenApiExtensionActivationAdmission(new OpenApiComponentClaimAdmission($core)))->admit(
            $candidate,
            SiteContext::default(),
            [$candidate],
        );

        $this->addToAssertionCount(1);
    }

    /**
     * Reject an extension component family that normalizes over a published site definition.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsComponentCollisionBetweenSiteAndActivatingExtension(): void
    {
        $candidate = self::manifest(
            'site/default',
            'site.default.foo_bar',
            '018f5200-0000-7000-8000-000000000004',
        );
        $siteDefinition = self::siteDefinition(
            'site.default.foo-bar',
            '018f5200-0000-7000-8000-000000000005',
        );
        $now = new DateTimeImmutable('2026-08-10T00:00:00+00:00');
        $entry = new DefinitionCatalogEntry(
            $siteDefinition->id,
            'default',
            $siteDefinition->handle,
            DefinitionOwner::site('default'),
            true,
            0,
            1,
            DefinitionStatus::Published,
            $now,
        );
        $record = new DefinitionVersionRecord(
            $siteDefinition,
            new CompatibilityPlan(null, 1, null, $siteDefinition->checksum(), []),
            DefinitionStatus::Published,
            '018f5200-0000-7000-8000-000000000006',
            $now,
        );
        $repository = $this->createMock(BusinessDefinitionRepository::class);
        $repository->expects(self::once())->method('lockContractNamespace')->with(SiteContext::default());
        $repository->method('catalog')->willReturn([$entry]);
        $repository->method('publishedBatch')->willReturn([$siteDefinition->id => $record]);
        $admission = new OpenApiExtensionActivationAdmission(
            new OpenApiComponentClaimAdmission(self::core()),
            static fn (): BusinessDefinitionRepository => $repository,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('collides or is unsafe');
        $admission->admit($candidate, SiteContext::default(), [$candidate]);
    }

    /**
     * Build a strict manifest with one minimal generated entity.
     *
     * @param   string  $identifier  Extension owner identifier.
     * @param   string  $handle      Owner-valid entity handle.
     * @param   string  $id          Definition UUID.
     *
     * @return  ExtensionManifest  Schema-3 manifest ready for admission.
     *
     * @since   2.0.0
     */
    private static function manifest(string $identifier, string $handle, string $id): ExtensionManifest
    {
        $extension = ExtensionIdentifier::fromString($identifier);
        $definition = EntityTypeDefinition::fromArray([
            'id' => $id,
            'owner' => ['type' => 'extension', 'identifier' => $identifier],
            'site' => 'default',
            'handle' => $handle,
            'singular_label' => 'Item',
            'plural_label' => 'Items',
            'status' => 'published',
            'definition_version' => 1,
            'storage_mode' => 'relational',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => true,
            'fields' => [[
                'handle' => 'id',
                'label' => 'ID',
                'type' => 'core.uuid',
                'required' => true,
                'nullable' => false,
                'unique' => true,
                'indexed' => true,
                'immutable_after_create' => true,
                'server_only' => true,
                'read_only' => true,
            ]],
            'relationships' => [],
            'views' => [],
            'actions' => [],
            'workflow' => null,
            'compatibility_metadata' => [],
            'administrator_exposure' => true,
            'portal_exposure' => false,
            'public_exposure' => false,
        ]);

        return new ExtensionManifest(
            identifier: $extension,
            type: ExtensionType::Component,
            version: SemanticVersion::fromString('1.0.0'),
            serviceProvider: 'Acme\\Extension\\Provider',
            kumweCompatibility: VersionConstraint::fromString('^2.0.0'),
            phpCompatibility: VersionConstraint::fromString('^8.5.0'),
            contributions: ManifestContributions::fromManifest($extension, [
                'version' => 1,
                'business' => ['definitions' => [$definition->toArray()]],
            ]),
            schemaVersion: 3,
        );
    }

    /**
     * Build one published site definition for cross-owner admission tests.
     *
     * @param   string  $handle  Site-owned entity handle.
     * @param   string  $id      Definition UUID.
     *
     * @return  EntityTypeDefinition  Published version-one entity.
     *
     * @since   2.0.0
     */
    private static function siteDefinition(string $handle, string $id): EntityTypeDefinition
    {
        return EntityTypeDefinition::fromArray([
            'id' => $id,
            'owner' => ['type' => 'site', 'identifier' => 'default'],
            'site' => 'default',
            'handle' => $handle,
            'singular_label' => 'Item',
            'plural_label' => 'Items',
            'status' => 'published',
            'definition_version' => 1,
            'storage_mode' => 'relational',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => true,
            'fields' => [[
                'handle' => 'id',
                'label' => 'ID',
                'type' => 'core.uuid',
                'required' => true,
                'nullable' => false,
                'unique' => true,
                'indexed' => true,
                'immutable_after_create' => true,
                'server_only' => true,
                'read_only' => true,
            ]],
            'relationships' => [],
            'views' => [],
            'actions' => [],
            'workflow' => null,
            'compatibility_metadata' => [],
            'administrator_exposure' => true,
            'portal_exposure' => false,
            'public_exposure' => false,
        ]);
    }

    /**
     * Return the minimal immutable core contract shape admission consumes.
     *
     * @return  array<string, mixed>  OpenAPI 3.1 document with an empty component registry.
     *
     * @since   2.0.0
     */
    private static function core(): array
    {
        return [
            'openapi' => '3.1.0',
            'components' => ['schemas' => []],
        ];
    }
}
