<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Demo\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionCompatibilityAnalyzer;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionContractAdmission;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionService;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionValidator;
use Kumwe\App\BusinessDefinition\Application\DefinitionCatalogEntry;
use Kumwe\App\BusinessDefinition\Application\DefinitionVersionRecord;
use Kumwe\App\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\BusinessDefinition\Domain\CompatibilityPlan;
use Kumwe\App\BusinessDefinition\Domain\DefinitionStatus;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\App\Demo\Application\DemoProfileLedger;
use Kumwe\App\Demo\Application\VdmBusinessManifestProjector;
use Kumwe\App\Demo\Application\VdmBusinessOperationGuard;
use Kumwe\App\Demo\Infrastructure\FilesystemDemoManifestCatalog;
use Kumwe\App\Demo\Infrastructure\VdmBusinessDemoInstaller;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Proves the VDM installer fails closed when live definitions or policies outpace preflight state.
 *
 * @since  2.0.0
 */
#[CoversClass(VdmBusinessDemoInstaller::class)]
#[UsesClass(FilesystemDemoManifestCatalog::class)]
#[UsesClass(VdmBusinessManifestProjector::class)]
#[UsesClass(VdmBusinessOperationGuard::class)]
final class VdmBusinessDemoInstallerTest extends TestCase
{
    /**
     * Reject an exact policy inserted after preflight when no installer checkpoint accompanies it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExistingPolicyDoesNotAssumeProvenanceAfterPreflight(): void
    {
        $ledger = $this->createMock(DemoProfileLedger::class);
        $ledger->expects(self::once())->method('asset')->willReturn(null);
        $database = $this->database();
        $installer = $this->installer($database, $ledger);
        $definition = $this->definition()->published(1);
        $baseline = $this->policyBaseline($installer, $definition);
        $calls = 0;
        $database->method('fetchAssociative')->willReturnCallback(
            function () use (&$calls, $baseline): array {
                ++$calls;

                return $calls === 1 ? ['id' => $baseline['id']] : $this->policyRow($baseline);
            },
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exists without an installer provenance checkpoint');

        $this->invoke($installer, 'installRecordPolicies', $this->context(), $definition, 'vdm', 'open');
    }

    /**
     * Accept an existing policy only after its checkpoint and complete live row both match.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExistingPolicyAcceptsAnExactPointOfUseCheckpoint(): void
    {
        $database = $this->database();
        $ledger = $this->createMock(DemoProfileLedger::class);
        $installer = $this->installer($database, $ledger);
        $baseline = $this->policyBaseline($installer, $this->definition()->published(1));
        $ledger->expects(self::once())->method('asset')->willReturn($this->policyAsset($baseline));
        $database->expects(self::once())->method('fetchAssociative')->willReturn($this->policyRow($baseline));
        $database->expects(self::once())->method('fetchOne')->willReturn('default');

        $this->invoke($installer, 'assertPolicyCheckpointAtInstallation', $this->context(), $baseline);

        self::addToAssertionCount(1);
    }

    /**
     * Reject a live policy edit even when its immutable installer checkpoint remains exact.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExistingPolicyRefusesRuntimeDivergence(): void
    {
        $database = $this->database();
        $ledger = $this->createStub(DemoProfileLedger::class);
        $installer = $this->installer($database, $ledger);
        $baseline = $this->policyBaseline($installer, $this->definition()->published(1));
        $ledger->method('asset')->willReturn($this->policyAsset($baseline));
        $row = $this->policyRow($baseline);
        $row['effect'] = 'deny';
        $database->method('fetchAssociative')->willReturn($row);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has diverged field effect');

        $this->invoke($installer, 'assertPolicyCheckpointAtInstallation', $this->context(), $baseline);
    }

    /**
     * Reject a removed live policy while its applied checkpoint still claims ownership.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExistingPolicyRefusesRemovalAfterApplication(): void
    {
        $database = $this->database();
        $ledger = $this->createStub(DemoProfileLedger::class);
        $installer = $this->installer($database, $ledger);
        $baseline = $this->policyBaseline($installer, $this->definition()->published(1));
        $ledger->method('asset')->willReturn($this->policyAsset($baseline));
        $database->method('fetchAssociative')->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is missing while its applied checkpoint remains');

        $this->invoke($installer, 'assertPolicyCheckpointAtInstallation', $this->context(), $baseline);
    }

    /**
     * Reject provenance whose resource ownership or immutable checksum cannot prove this policy.
     *
     * @param   string  $variant  Checkpoint corruption to apply.
     * @param   string  $message  Diagnostic fragment expected from the fail-closed boundary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('invalidPolicyAssets')]
    public function testExistingPolicyRefusesWrongOrCorruptProvenance(string $variant, string $message): void
    {
        $database = $this->database();
        $ledger = $this->createStub(DemoProfileLedger::class);
        $installer = $this->installer($database, $ledger);
        $baseline = $this->policyBaseline($installer, $this->definition()->published(1));
        $asset = $this->policyAsset($baseline);
        if ($variant === 'wrong-type') {
            $asset['resource_type'] = 'business_definition';
        } else {
            $asset['last_applied_checksum'] = str_repeat('f', 64);
        }
        $ledger->method('asset')->willReturn($asset);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        $this->invoke($installer, 'assertPolicyCheckpointAtInstallation', $this->context(), $baseline);
    }

    /**
     * Supply policy provenance variants that must never authorize existing-row adoption.
     *
     * @return  array<string, array{0: string, 1: string}>  Variant and expected diagnostic fragment.
     *
     * @since   2.0.0
     */
    public static function invalidPolicyAssets(): array
    {
        return [
            'wrong resource type' => ['wrong-type', 'checkpoint owned by another resource type'],
            'corrupt immutable checksum' => ['corrupt-checksum', 'inconsistent applied checkpoint'],
        ];
    }

    /**
     * Permit a released definition to advance while the current publication still equals its checkpoint.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUntouchedDefinitionMayAdvanceFromItsAppliedBaseline(): void
    {
        $baseline = $this->definition()->published(1);
        $desiredDocument = $this->definitionDocument();
        $desiredDocument['singular_label'] = 'Evolved client account';
        $desired = EntityTypeDefinition::fromArray($desiredDocument);
        $installer = $this->installer(
            $this->database(),
            $this->createStub(DemoProfileLedger::class),
            $this->definitionService($baseline),
        );

        $this->invoke($installer, 'assertDefinitionRuntime', $this->context(), $desired, [
            'last_applied_checksum' => $baseline->checksum(),
        ]);

        self::addToAssertionCount(1);
    }

    /**
     * Refuse a definition that differs from both the new release and the last installer baseline.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCustomizedDefinitionIsNeverOverwrittenByAnAdvance(): void
    {
        $baseline = $this->definition()->published(1);
        $desiredDocument = $this->definitionDocument();
        $desiredDocument['singular_label'] = 'Evolved client account';
        $desired = EntityTypeDefinition::fromArray($desiredDocument);
        $customizedDocument = $this->definitionDocument();
        $customizedDocument['singular_label'] = 'Operator-owned account';
        $customized = EntityTypeDefinition::fromArray($customizedDocument)->published(1);
        $installer = $this->installer(
            $this->database(),
            $this->createStub(DemoProfileLedger::class),
            $this->definitionService($customized),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('was customized; refusing demo reconciliation');

        $this->invoke($installer, 'assertDefinitionRuntime', $this->context(), $desired, [
            'last_applied_checksum' => $baseline->checksum(),
        ]);
    }

    /**
     * Proves each record-access mode shapes its generated policy rows exactly as released.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRecordAccessModesShapeGeneratedPolicyBaselines(): void
    {
        $installer = $this->installer($this->database(), $this->createStub(DemoProfileLedger::class));
        $definition = $this->definition()->published(1);
        $read = 'business.record.read';

        $open = $this->invoke($installer, 'policyBaselines', $definition, $read, 'vdm', 'open');
        self::assertIsArray($open);
        self::assertCount(1, $open);
        self::assertIsArray($open[0]);
        self::assertSame('allow', $open[0]['effect'] ?? null);
        self::assertSame(['type' => 'constant', 'value' => true], $open[0]['predicate'] ?? null);

        $organization = $this->invoke($installer, 'policyBaselines', $definition, $read, 'vdm', 'organization');
        self::assertIsArray($organization);
        self::assertCount(1, $organization);
        self::assertIsArray($organization[0]);
        self::assertSame('allow', $organization[0]['effect'] ?? null);
        self::assertSame([
            'type' => 'boolean',
            'operator' => 'any',
            'children' => [
                [
                    'type' => 'attribute_null',
                    'source' => 'context',
                    'attribute' => 'organization',
                    'is_null' => true,
                ],
                [
                    'type' => 'field_attribute_comparison',
                    'field' => 'organization',
                    'source' => 'context',
                    'attribute' => 'organization',
                    'operator' => 'equal',
                    'value_type' => 'string',
                ],
            ],
        ], $organization[0]['predicate'] ?? null);

        $organizationLess = [
            'type' => 'attribute_null',
            'source' => 'context',
            'attribute' => 'organization',
            'is_null' => true,
        ];
        $update = $this->invoke(
            $installer,
            'policyBaselines',
            $definition,
            'business.record.update',
            'vdm',
            'organization',
        );
        self::assertIsArray($update);
        self::assertIsArray($update[0] ?? null);
        self::assertSame($organizationLess, $update[0]['predicate'] ?? null);

        $workflow = $this->invoke(
            $installer,
            'policyBaselines',
            $definition,
            'business.record.action',
            'vdm',
            'organization',
        );
        self::assertIsArray($workflow);
        self::assertIsArray($workflow[0] ?? null);
        self::assertSame('boolean', $workflow[0]['predicate']['type'] ?? null);

        $readOnlyAction = $this->invoke(
            $installer,
            'policyBaselines',
            $definition,
            'business.record.action',
            'vdm',
            'organization-read',
        );
        self::assertIsArray($readOnlyAction);
        self::assertIsArray($readOnlyAction[0] ?? null);
        self::assertSame($organizationLess, $readOnlyAction[0]['predicate'] ?? null);

        $administration = $this->invoke($installer, 'policyBaselines', $definition, $read, 'vdm', 'administration');
        self::assertIsArray($administration);
        self::assertCount(2, $administration);
        self::assertSame($open[0], $administration[0]);
        $guard = $administration[1];
        self::assertIsArray($guard);
        self::assertSame('deny', $guard['effect'] ?? null);
        self::assertIsString($guard['policy_code'] ?? null);
        self::assertStringEndsWith('.portal-guard', $guard['policy_code']);
        self::assertSame([
            'type' => 'attribute_null',
            'source' => 'context',
            'attribute' => 'organization',
            'is_null' => false,
        ], $guard['predicate'] ?? null);
        $fields = $guard['fields'] ?? null;
        self::assertIsArray($fields);
        self::assertNotSame([], $fields);
        foreach ($fields as $handles) {
            self::assertSame([], $handles);
        }
        self::assertNotSame($open[0]['fixture_key'] ?? null, $guard['fixture_key'] ?? null);
    }

    /**
     * Proves an undeclared record-access vocabulary word is refused before any policy derivation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnknownRecordAccessModeIsRejected(): void
    {
        $installer = $this->installer($this->database(), $this->createStub(DemoProfileLedger::class));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unknown record-access mode');

        $this->invoke($installer, 'recordAccessModes', [
            'installation_order' => [
                ['fixture_key' => 'definition.sample', 'record_access' => 'everyone'],
            ],
        ]);
    }

    /**
     * Construct the installer with real policy helpers and inert collaborators outside the exercised seam.
     *
     * @param   Connection                  $database     Observable policy persistence connection.
     * @param   DemoProfileLedger           $ledger       Point-of-use provenance source.
     * @param   ?BusinessDefinitionService  $definitions  Optional read service for definition-state tests.
     *
     * @return  VdmBusinessDemoInstaller  Installer under test.
     *
     * @since   2.0.0
     */
    private function installer(
        Connection $database,
        DemoProfileLedger $ledger,
        ?BusinessDefinitionService $definitions = null,
    ): VdmBusinessDemoInstaller {
        /** @var BusinessDefinitionService $definitionService */
        $definitionService = $definitions
            ?? (new ReflectionClass(BusinessDefinitionService::class))->newInstanceWithoutConstructor();
        /** @var BusinessSchemaService $schemas */
        $schemas = (new ReflectionClass(BusinessSchemaService::class))->newInstanceWithoutConstructor();
        /** @var BusinessRecordService $records */
        $records = (new ReflectionClass(BusinessRecordService::class))->newInstanceWithoutConstructor();
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-11T12:00:00+00:00'));

        return new VdmBusinessDemoInstaller(
            $definitionService,
            $schemas,
            $records,
            new VdmBusinessManifestProjector(),
            new VdmBusinessOperationGuard(),
            $ledger,
            $database,
            new TableNames($database, 'kumwe_'),
            $this->createStub(TransactionManager::class),
            $this->createStub(AuditRecorder::class),
            $clock,
        );
    }

    /**
     * Build the real definition read service over one published catalog entry and no draft.
     *
     * @param   EntityTypeDefinition  $published  Current published definition returned to preflight.
     *
     * @return  BusinessDefinitionService  Authorized read service for the definition runtime assertion.
     *
     * @since   2.0.0
     */
    private function definitionService(EntityTypeDefinition $published): BusinessDefinitionService
    {
        $repository = $this->createStub(BusinessDefinitionRepository::class);
        $entry = new DefinitionCatalogEntry(
            $published->id,
            $published->siteIdentifier,
            $published->handle,
            $published->owner,
            true,
            0,
            $published->definitionVersion,
            DefinitionStatus::Published,
            new DateTimeImmutable('2026-08-11T12:00:00+00:00'),
        );
        $record = new DefinitionVersionRecord(
            $published,
            new CompatibilityPlan(null, 1, null, $published->checksum(), []),
            DefinitionStatus::Published,
            'system:profile-installer',
            new DateTimeImmutable('2026-08-11T12:00:00+00:00'),
        );
        $repository->method('entry')->willReturnCallback(
            static fn (SiteContext $site, string $identifier): ?DefinitionCatalogEntry =>
                $site->identifier() === $published->siteIdentifier
                && in_array($identifier, [$published->id, $published->handle], true)
                    ? $entry
                    : null,
        );
        $repository->method('published')->willReturn($record);
        $repository->method('draft')->willReturn(null);
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-11T12:00:00+00:00'));

        return new BusinessDefinitionService(
            $repository,
            new BusinessDefinitionValidator(new FieldTypeRegistry()),
            new BusinessDefinitionCompatibilityAnalyzer(),
            $this->createStub(BusinessDefinitionContractAdmission::class),
            $this->createStub(AuthorizationGateway::class),
            $this->createStub(ResourceSiteOwnershipWriter::class),
            $this->createStub(AuditRecorder::class),
            $this->createStub(TransactionManager::class),
            $clock,
        );
    }

    /**
     * Create a connection mock with deterministic identifier quoting.
     *
     * @return  Connection  Mockable policy connection.
     *
     * @since   2.0.0
     */
    private function database(): Connection
    {
        $database = $this->createMock(Connection::class);
        $database->method('quoteSingleIdentifier')->willReturnCallback(
            static fn (string $identifier): string => $identifier,
        );

        return $database;
    }

    /**
     * Mint the purpose-bound system context the production reconciler supplies.
     *
     * @return  ExecutionContext  Default-site profile installer context.
     *
     * @since   2.0.0
     */
    private function context(): ExecutionContext
    {
        return ExecutionContext::issueSystem(
            new \stdClass(),
            SystemIdentity::ProfileInstaller,
            SiteContext::default(),
            'test-vdm-policy-reconciliation',
        );
    }

    /**
     * Load the stable client-account definition used by policy and evolution tests.
     *
     * @return  EntityTypeDefinition  Released version-zero definition.
     *
     * @since   2.0.0
     */
    private function definition(): EntityTypeDefinition
    {
        return EntityTypeDefinition::fromArray($this->definitionDocument());
    }

    /**
     * Load one released definition document without mutating the source manifest.
     *
     * @return  array<string, mixed>  Client-account definition document.
     *
     * @since   2.0.0
     */
    private function definitionDocument(): array
    {
        $manifest = (new FilesystemDemoManifestCatalog(dirname(__DIR__, 4)))->vdmBusiness()['manifest'];
        $documents = $manifest['definition_documents'] ?? null;
        self::assertIsArray($documents);
        $document = $documents['definition.client_account'] ?? null;
        self::assertIsArray($document);

        return $document;
    }

    /**
     * Derive the installer's exact deterministic read policy for one definition.
     *
     * @param   VdmBusinessDemoInstaller  $installer   Installer under test.
     * @param   EntityTypeDefinition      $definition  Published definition owning the policy.
     *
     * @return  array<string, mixed>  Complete deterministic policy baseline.
     *
     * @since   2.0.0
     */
    private function policyBaseline(
        VdmBusinessDemoInstaller $installer,
        EntityTypeDefinition $definition,
    ): array {
        $baselines = $this->invoke($installer, 'policyBaselines', $definition, 'business.record.read', 'vdm', 'open');
        self::assertIsArray($baselines);
        $baseline = $baselines[0] ?? null;
        self::assertIsArray($baseline);

        return $baseline;
    }

    /**
     * Build the exact policy checkpoint recorded after a successful bootstrap transaction.
     *
     * @param   array<string, mixed>  $baseline  Deterministic desired policy.
     *
     * @return  array<string, mixed>  Complete immutable policy checkpoint.
     *
     * @since   2.0.0
     */
    private function policyAsset(array $baseline): array
    {
        $state = $baseline['state'] ?? null;
        self::assertIsArray($state);

        return [
            'fixture_key' => $baseline['fixture_key'],
            'resource_type' => 'resource_policy',
            'resource_id' => $baseline['id'],
            'last_applied_checksum' => CanonicalDefinitionJson::checksum($state),
            'last_applied_version' => 1,
            'last_applied_state' => $state,
        ];
    }

    /**
     * Build the full live row shape validated before an existing policy may be skipped.
     *
     * @param   array<string, mixed>  $baseline  Deterministic desired policy.
     *
     * @return  array<string, mixed>  Canonical active policy database row.
     *
     * @since   2.0.0
     */
    private function policyRow(array $baseline): array
    {
        return [
            'id' => $baseline['id'],
            'policy_code' => $baseline['policy_code'],
            'owner_kind' => 'core',
            'owner_identifier' => 'core',
            'capability_code' => $baseline['operation'],
            'resource_type' => 'business_record',
            'action' => $baseline['operation'],
            'effect' => 'allow',
            'scope_type' => 'site',
            'organization_id' => null,
            'entity_definition_id' => $baseline['definition_id'],
            'canonical_ast' => $baseline['predicate'],
            'field_rules' => $baseline['fields'],
            'ast_checksum' => $baseline['ast_checksum'],
            'policy_version' => 1,
            'priority' => -1_000,
            'status' => 'active',
        ];
    }

    /**
     * Invoke one private installer boundary while retaining production parameter validation.
     *
     * @param   VdmBusinessDemoInstaller  $installer  Installer under test.
     * @param   string                    $method     Private method name.
     * @param   mixed                     ...$args    Exact method arguments.
     *
     * @return  mixed  Method result.
     *
     * @since   2.0.0
     */
    private function invoke(VdmBusinessDemoInstaller $installer, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($installer, $method))->invoke($installer, ...$args);
    }
}
