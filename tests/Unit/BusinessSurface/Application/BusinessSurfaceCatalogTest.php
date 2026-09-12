<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessSurface\Application;

use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\App\Application\Authorization\AuthorizationDecision;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\BusinessDefinition\Application\FieldTypeDefinitionResolver;
use Kumwe\App\BusinessDefinition\Domain\BuiltInFieldTypes;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\App\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable;
use Kumwe\App\BusinessRecord\Application\ResolvedBusinessDefinition;
use Kumwe\App\BusinessRecord\Domain\RecordScope;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessController;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessPlan;
use Kumwe\Extension\Spi\BusinessSecurity\Application\FieldDisclosurePlan;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicyConstant;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicySchema;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicySet;
use Kumwe\App\BusinessSurface\Application\BusinessSurface;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceCatalog;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceOperation;
use Kumwe\App\Extension\Runtime\RuntimeMaterializationState;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\Localization\Application\ActiveLocale;
use Kumwe\Localization\Application\SupportedLocales;
use Kumwe\Localization\Domain\LocaleTag;
use Kumwe\App\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(BusinessSurfaceCatalog::class)]
/**
 * Proves generated metadata uses row authority directly rather than field presence as its proxy.
 *
 * @since  2.0.0
 */
final class BusinessSurfaceCatalogTest extends TestCase
{
    /**
     * Published definition identity shared by catalog authority fixtures.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string DEFINITION_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bb702';

    /**
     * Prove generated metadata consumes the definition translations for the locale in flight.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGeneratedMetadataUsesLocalizedEntityAndFieldLabels(): void
    {
        $document = $this->definition();
        $document['label_translations'] = [
            'singular_label' => ['de' => 'Katalogprüfung'],
            'plural_label' => ['de' => 'Katalogprüfungen'],
        ];
        $document['fields'][1]['text_translations'] = [
            'label' => ['de' => 'Name auf Deutsch'],
        ];
        $active = new ActiveLocale(new SupportedLocales());
        $active->begin(LocaleTag::fromString('de'));
        $plan = new BusinessRecordAccessPlan(
            self::DEFINITION_ID,
            'business.record.read',
            new RecordPolicySet(new RecordPolicySchema([]), [new RecordPolicyConstant(true)]),
            new FieldDisclosurePlan(['detail' => ['name']]),
            hash('sha256', 'localized-definition-policy'),
        );
        $catalog = $this->catalog($plan, $document, $active);

        $metadata = $catalog->definition(
            $this->context(BusinessSurface::Api, ['business.record.read']),
            BusinessSurface::Api,
            'site.default.catalog_authority_test',
            BusinessSurfaceOperation::Read,
        );

        self::assertSame('Katalogprüfung', $metadata['singular_label']);
        self::assertSame('Katalogprüfungen', $metadata['plural_label']);
        self::assertSame('Name auf Deutsch', $metadata['fields'][0]['label']);
    }

    /**
     * Proves an explicit row denial with populated field rules cannot leak definition metadata.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testConstantRowDenialOmitsMetadataEvenWhenFieldRulesArePopulated(): void
    {
        $plan = new BusinessRecordAccessPlan(
            self::DEFINITION_ID,
            'business.record.read',
            new RecordPolicySet(
                new RecordPolicySchema([]),
                [new RecordPolicyConstant(true)],
                [new RecordPolicyConstant(true)],
            ),
            new FieldDisclosurePlan(['detail' => ['name']]),
            hash('sha256', 'denied-policy'),
        );
        $catalog = $this->catalog($plan);
        $context = $this->context(BusinessSurface::Api, ['business.record.read']);

        self::assertSame([], $catalog->definitions($context, BusinessSurface::Api, BusinessSurfaceOperation::Read));

        $this->expectException(BusinessRecordDefinitionUnavailable::class);
        $catalog->definition(
            $context,
            BusinessSurface::Api,
            'site.default.catalog_authority_test',
            BusinessSurfaceOperation::Read,
        );
    }

    /**
     * Proves row-authorized fieldless lifecycle metadata remains valid on every generated delivery surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRowAuthorizedFieldlessLifecycleMetadataRemainsAvailableOnEverySurface(): void
    {
        $plan = new BusinessRecordAccessPlan(
            self::DEFINITION_ID,
            'business.record.delete',
            new RecordPolicySet(new RecordPolicySchema([]), [new RecordPolicyConstant(true)]),
            new FieldDisclosurePlan(),
            hash('sha256', 'allowed-policy'),
        );
        $catalog = $this->catalog($plan);

        foreach (BusinessSurface::cases() as $surface) {
            $context = $this->context($surface, ['business.record.delete']);
            $metadata = $catalog->definition(
                $context,
                $surface,
                'site.default.catalog_authority_test',
                BusinessSurfaceOperation::Delete,
            );

            self::assertSame([], $metadata['fields'], $surface->value);
            self::assertSame([], $metadata['views'], $surface->value);
            self::assertSame([], $metadata['actions'], $surface->value);
            self::assertCount(
                1,
                $catalog->definitions($context, $surface, BusinessSurfaceOperation::Delete),
                $surface->value,
            );
            self::assertSame(['delete' => true], $catalog->operations(
                $context,
                $surface,
                'site.default.catalog_authority_test',
            ), $surface->value);
        }
    }

    /**
     * Proves a checker may resolve portal approval exposure without inheriting the maker's action grant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testApprovalExposurePreservesApproverOnlySeparationOfDuties(): void
    {
        $plan = new BusinessRecordAccessPlan(
            self::DEFINITION_ID,
            'business.record.delete',
            new RecordPolicySet(new RecordPolicySchema([]), [new RecordPolicyConstant(true)]),
            new FieldDisclosurePlan(),
            hash('sha256', 'approval-exposure-policy'),
        );
        $catalog = $this->catalog($plan);
        $context = $this->context(BusinessSurface::Portal, ['business.approval.approve']);
        $request = '0191574f-f0b8-7bf3-a9aa-91c6b8244e21';

        self::assertSame([$request => true], $catalog->approvalActions(
            $context,
            BusinessSurface::Portal,
            [[
                'request_id' => $request,
                'definition_id' => self::DEFINITION_ID,
                'action' => 'approve',
            ]],
        ));
    }

    /**
     * Proves one active definition with a withdrawn field-type provider cannot suppress healthy metadata.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInactiveFieldTypeOmitsOnlyItsOwningDefinition(): void
    {
        $valid = $this->resolved($this->definition());
        $orphanDocument = $this->definition();
        $orphanDocument['id'] = '018f22e2-7c8b-7ab0-8f3a-88e8026bb703';
        $orphanDocument['handle'] = 'site.default.catalog_orphan_test';
        $orphanDocument['fields'][1]['type'] = 'tests.orphan.text';
        $orphan = $this->resolved($orphanDocument);

        $definitions = $this->createStub(BusinessRecordDefinitionResolver::class);
        $definitions->method('activeInstalled')->willReturn([$valid, $orphan]);
        $access = $this->createStub(BusinessRecordAccessController::class);
        $access->method('plan')->willReturnCallback(static fn (
            ExecutionContext $_context,
            string $operation,
            ResolvedBusinessDefinition $resolved,
            RecordScope $_scope,
        ): BusinessRecordAccessPlan => new BusinessRecordAccessPlan(
            $resolved->definition->id,
            $operation,
            new RecordPolicySet(new RecordPolicySchema([]), [new RecordPolicyConstant(true)]),
            new FieldDisclosurePlan(['detail' => ['name']]),
            hash('sha256', $resolved->definition->id . ':' . $operation),
        ));
        $fieldTypes = $this->createStub(FieldTypeDefinitionResolver::class);
        $fieldTypes->method('get')->willReturnCallback(
            fn (string $identifier): FieldTypeDefinition => $identifier === 'tests.orphan.text'
                ? throw new InvalidBusinessDefinition('The contributed field type is inactive.')
                : $this->fieldType($identifier),
        );
        $authorization = $this->createStub(AuthorizationGateway::class);
        $authorization->method('decide')->willReturnCallback(static fn (
            ExecutionContext $_context,
            Capability $capability,
            AuthorizationResource $_resource,
        ): AuthorizationDecision => new AuthorizationDecision(
            $capability->value() === 'business.record.read',
            'test',
            $capability->value() === 'business.record.read' ? 'allowed' : 'denied',
        ));
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $catalog = new BusinessSurfaceCatalog(
            $definitions,
            $access,
            $fieldTypes,
            $authorization,
            $transactions,
            RuntimeMaterializationState::unavailable('catalog-orphan-test'),
        );
        $context = $this->context(BusinessSurface::Api, ['business.record.read']);

        self::assertSame(
            ['site.default.catalog_authority_test'],
            array_column(
                $catalog->definitions($context, BusinessSurface::Api, BusinessSurfaceOperation::Read),
                'handle',
            ),
        );
        try {
            $catalog->definition(
                $context,
                BusinessSurface::Api,
                'site.default.catalog_orphan_test',
                BusinessSurfaceOperation::Read,
            );
            self::fail('An inactive contributed field type must fail closed for its definition.');
        } catch (BusinessRecordDefinitionUnavailable) {
            self::assertTrue(true);
        }
        self::assertSame([], $catalog->operations(
            $context,
            BusinessSurface::Api,
            'site.default.catalog_orphan_test',
        ));
    }

    /**
     * Build a shared catalog around one exact access-plan decision.
     *
     * @param   BusinessRecordAccessPlan  $plan      Row and field authority returned for the requested operation.
     * @param   ?array<string, mixed>      $document  Definition document to expose, or null for the default.
     * @param   ?ActiveLocale              $active    Locale holder to project definition wording through.
     *
     * @return  BusinessSurfaceCatalog  Fully executable catalog fixture.
     *
     * @since   2.0.0
     */
    private function catalog(
        BusinessRecordAccessPlan $plan,
        ?array $document = null,
        ?ActiveLocale $active = null,
    ): BusinessSurfaceCatalog {
        $resolved = $this->resolved($document ?? $this->definition());
        $definitions = $this->createStub(BusinessRecordDefinitionResolver::class);
        $definitions->method('activeInstalled')->willReturn([$resolved]);
        $access = $this->createStub(BusinessRecordAccessController::class);
        $access->method('plan')->willReturn($plan);
        $fieldTypes = $this->createStub(FieldTypeDefinitionResolver::class);
        $fieldTypes->method('get')->willReturn($this->fieldType('core.text'));
        $authorization = $this->createStub(AuthorizationGateway::class);
        $authorization->method('decide')->willReturnCallback(
            static fn (
                ExecutionContext $_context,
                Capability $capability,
                AuthorizationResource $_resource,
            ): AuthorizationDecision =>
                new AuthorizationDecision(
                    $capability->value() === $plan->operation,
                    'test',
                    $capability->value() === $plan->operation ? 'allowed' : 'denied',
                ),
        );
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );

        return new BusinessSurfaceCatalog(
            $definitions,
            $access,
            $fieldTypes,
            $authorization,
            $transactions,
            RuntimeMaterializationState::unavailable('catalog-authority-test'),
            null,
            $active,
        );
    }

    /**
     * Reconstitute the definition-only portion needed by the catalog unit seam.
     *
     * @param   array<string, mixed>  $document  Valid entity definition document.
     *
     * @return  ResolvedBusinessDefinition  Reflection-backed catalog fixture.
     *
     * @since   2.0.0
     */
    private function resolved(array $document): ResolvedBusinessDefinition
    {
        $definition = EntityTypeDefinition::fromArray($document);
        $resolved = (new ReflectionClass(ResolvedBusinessDefinition::class))->newInstanceWithoutConstructor();
        (new ReflectionClass(ResolvedBusinessDefinition::class))
            ->getProperty('definition')
            ->setValue($resolved, $definition);

        return $resolved;
    }

    /**
     * Mint one context whose authenticated provenance exactly matches the generated surface.
     *
     * @param   BusinessSurface  $surface       Generated adapter under test.
     * @param   list<string>     $capabilities  Global capability vocabulary carried by the principal.
     *
     * @return  ExecutionContext  Provenance-bound caller context.
     *
     * @since   2.0.0
     */
    private function context(BusinessSurface $surface, array $capabilities): ExecutionContext
    {
        $authenticated = match ($surface) {
            BusinessSurface::Administrator => AuthenticatedSurface::Administrator,
            BusinessSurface::Portal => AuthenticatedSurface::Portal,
            BusinessSurface::Api => AuthenticatedSurface::Api,
            BusinessSurface::Cli => AuthenticatedSurface::Cli,
            BusinessSurface::Mcp => AuthenticatedSurface::Mcp,
        };

        return AuthorizationContext::principal($capabilities)->context(
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'business-catalog-authority-test-0001',
            surface: $authenticated,
        );
    }

    /**
     * Return a published definition that exposes delete on every generated surface.
     *
     * @return  array<string, mixed>  Valid published definition document.
     *
     * @since   2.0.0
     */
    private function definition(): array
    {
        return [
            'id' => self::DEFINITION_ID,
            'owner' => ['type' => 'site', 'identifier' => 'default'],
            'site' => 'default',
            'handle' => 'site.default.catalog_authority_test',
            'singular_label' => 'Catalog authority test',
            'plural_label' => 'Catalog authority tests',
            'status' => 'published',
            'definition_version' => 2,
            'storage_mode' => 'relational',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => true,
            'fields' => [
                [
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
                ],
                [
                    'handle' => 'name',
                    'label' => 'Name',
                    'type' => 'core.text',
                    'required' => true,
                    'nullable' => false,
                    'length' => 120,
                ],
            ],
            'relationships' => [],
            'views' => [],
            'actions' => [[
                'handle' => 'approve',
                'label' => 'Approve',
                'capability' => 'business.record.action',
                'administrator' => true,
                'portal' => true,
                'public' => false,
                'high_impact' => true,
                'transition' => 'approve',
            ]],
            'workflow' => [
                'initial_state' => 'draft',
                'states' => ['draft', 'approved'],
                'transitions' => [[
                    'handle' => 'approve',
                    'from' => 'draft',
                    'to' => 'approved',
                    'capability' => 'business.record.action',
                ]],
            ],
            'compatibility_metadata' => [],
            'administrator_exposure' => true,
            'portal_exposure' => true,
            'public_exposure' => false,
            'soft_delete_enabled' => true,
            'record_invariants' => [],
            'portal_operations' => ['approval', 'delete'],
        ];
    }

    /**
     * Resolve one built-in field type for metadata projection.
     *
     * @param   string  $identifier  Built-in type identifier.
     *
     * @return  FieldTypeDefinition  Matching immutable type.
     *
     * @since   2.0.0
     */
    private function fieldType(string $identifier): FieldTypeDefinition
    {
        foreach (BuiltInFieldTypes::all() as $type) {
            if ($type->id === $identifier) {
                return $type;
            }
        }

        self::fail('The requested built-in field type is missing.');
    }
}
