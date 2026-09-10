<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\OpenApi\Application;

use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\App\OpenApi\Application\OpenApiComponentClaimAdmission;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpenApiComponentClaimAdmission::class)]
/**
 * Proves publication and activation share one deterministic component namespace.
 *
 * @since  2.0.0
 */
final class OpenApiComponentClaimAdmissionTest extends TestCase
{
    /**
     * Reject distinct valid site handles that normalize onto one component family.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsNormalizedSiteDefinitionCollision(): void
    {
        $admission = new OpenApiComponentClaimAdmission(self::core());

        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('collides or is unsafe');

        $admission->admit(SiteContext::default(), [
            self::definition('site.default.claim.foo-bar', '018f5300-0000-7000-8000-000000000001'),
            self::definition('site.default.claim.foo.bar', '018f5300-0000-7000-8000-000000000002'),
        ]);
    }

    /**
     * Reject a generated family that would shadow a checked-in non-generated core component.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsCoreComponentShadowing(): void
    {
        $core = self::core();
        $core['components']['schemas']['Business_site_default_claim_item_Record'] = ['type' => 'object'];
        $admission = new OpenApiComponentClaimAdmission($core);

        $this->expectException(InvalidBusinessDefinition::class);
        $admission->admit(SiteContext::default(), [
            self::definition('site.default.claim.item', '018f5300-0000-7000-8000-000000000003'),
        ]);
    }

    /**
     * Build one minimal site-owned definition whose component family can be claimed.
     *
     * @param   string  $handle  Site-owned namespaced entity handle.
     * @param   string  $id      Definition UUID.
     *
     * @return  EntityTypeDefinition  Valid draft definition.
     *
     * @since   2.0.0
     */
    private static function definition(string $handle, string $id): EntityTypeDefinition
    {
        return EntityTypeDefinition::fromArray([
            'id' => $id,
            'owner' => ['type' => 'site', 'identifier' => 'default'],
            'site' => 'default',
            'handle' => $handle,
            'singular_label' => 'Item',
            'plural_label' => 'Items',
            'status' => 'draft',
            'definition_version' => 0,
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
     * Return a minimal core contract with no reserved generated-family names.
     *
     * @return  array<string, mixed>  OpenAPI 3.1 core document.
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
