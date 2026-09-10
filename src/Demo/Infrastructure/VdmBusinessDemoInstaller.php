<?php

declare(strict_types=1);

namespace Kumwe\App\Demo\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use JsonException;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Application\Automation\IdempotencyKey;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionNotFound;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionService;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\App\BusinessDefinition\Domain\IdentityStrategy;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\ArchiveRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\ExecuteRecordActionCommand;
use Kumwe\App\BusinessRecord\Application\Command\RelateRecordsCommand;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\App\BusinessSchema\Domain\SchemaPlanStatus;
use Kumwe\Extension\Spi\BusinessSecurity\Application\FieldAccessUsage;
use Kumwe\App\Demo\Application\DemoProfileLedger;
use Kumwe\App\Demo\Application\VdmBusinessManifestProjector;
use Kumwe\App\Demo\Application\VdmBusinessOperationGuard;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Installs the VDM delivery example through definition, schema, record, relation, and workflow services.
 *
 * Definitions and generated records use the same application paths as administrator, REST, CLI, and MCP
 * requests, producing ordinary schema plans, revisions, audit entries, outbox events, and idempotency rows.
 * The only specialized persistence is the initial row/field policy bootstrap: Business Security correctly
 * requires a human step-up proof for its public administration service, so this purpose-bound installer
 * writes a closed constant-true policy document derived from immutable definition field ceilings and audits
 * it in the same transaction. No password, token, MFA material, or encryption key is shipped.
 *
 * @since  2.0.0
 */
final readonly class VdmBusinessDemoInstaller
{
    /**
     * Independent business-demo dataset key.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string DATASET = 'business-demo';

    /**
     * Business-record operations made available to actors who separately hold the matching capability.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array RECORD_OPERATIONS = [
        'business.record.action',
        'business.record.archive',
        'business.record.browse',
        'business.record.create',
        'business.record.delete',
        'business.record.export',
        'business.record.history',
        'business.record.read',
        'business.record.relate',
        'business.record.report',
        'business.record.restore',
        'business.record.transition',
        'business.record.update',
    ];

    /**
     * Bind every canonical runtime and the narrow policy-bootstrap dependencies.
     *
     * @param  BusinessDefinitionService     $definitions   Definition draft and publication service.
     * @param  BusinessSchemaService         $schemas       Persisted plan, approval, and execution service.
     * @param  BusinessRecordService         $records       Transactional record application service.
     * @param  VdmBusinessManifestProjector  $projector     Pure default-template site projection.
     * @param  VdmBusinessOperationGuard     $operations    Append-only operation checkpoint guard.
     * @param  DemoProfileLedger             $ledger        Stable profile provenance and restart state.
     * @param  Connection                    $database      Policy catalog connection.
     * @param  TableNames                    $tables        Validated physical table compiler.
     * @param  TransactionManager            $transactions  Policy, ownership, and audit transaction boundary.
     * @param  AuditRecorder                 $audit         Durable policy-bootstrap audit sink.
     * @param  ClockInterface                $clock         Trusted timestamp source.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessDefinitionService $definitions,
        private BusinessSchemaService $schemas,
        private BusinessRecordService $records,
        private VdmBusinessManifestProjector $projector,
        private VdmBusinessOperationGuard $operations,
        private DemoProfileLedger $ledger,
        private Connection $database,
        private TableNames $tables,
        private TransactionManager $transactions,
        private AuditRecorder $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Validate applied checkpoints and current definition-policy state without mutating either.
     *
     * This runs before the outer profile ledger accepts a candidate manifest and again immediately before
     * installation. Applied operation conflicts and current definition or policy divergence are therefore
     * rejected before this installer publishes a definition or writes a policy.
     *
     * @param   ExecutionContext      $context   Purpose-bound profile-installer context.
     * @param   array<string, mixed>  $manifest  Aggregate VDM source manifest.
     *
     * @return  void
     *
     * @throws  RuntimeException  When projected resources contradict their applied checkpoints or current
     *          definition and policy state.
     *
     * @since   2.0.0
     */
    public function preflight(ExecutionContext $context, array $manifest): void
    {
        $manifest = $this->projector->forSite($manifest, $context->site());
        $profile = $this->requiredString($manifest, 'profile');
        $modes = $this->recordAccessModes($manifest);
        $assets = $this->ledger->assets($context->site()->identifier(), self::DATASET);
        $records = $this->requiredMap($manifest, 'records_document');
        $operations = $this->operations->validate($records, $assets);
        $documents = $this->requiredMap($manifest, 'definition_documents');
        $order = $this->requiredList(
            $manifest,
            'installation_order',
            FilesystemDemoManifestCatalog::MAXIMUM_INSTALLATION_ORDER,
        );
        $this->preflightDefinitionsAndPolicies($context, $documents, $order, $operations, $assets, $profile, $modes);
    }

    /**
     * Apply the complete aggregate VDM manifest and return concise operator diagnostics.
     *
     * @param   ExecutionContext      $context   Purpose-bound profile-installer context.
     * @param   array<string, mixed>  $manifest  Profile plus embedded definition and record documents.
     *
     * @return  list<string>  Installed definition and record summaries.
     *
     * @since   2.0.0
     */
    public function install(ExecutionContext $context, array $manifest): array
    {
        $this->preflight($context, $manifest);
        $manifest = $this->projector->forSite($manifest, $context->site());
        $profile = $this->requiredString($manifest, 'profile');
        $modes = $this->recordAccessModes($manifest);
        $records = $this->requiredMap($manifest, 'records_document');
        $operations = $this->operations->validate(
            $records,
            $this->ledger->assets($context->site()->identifier(), self::DATASET),
        );
        $documents = $this->requiredMap($manifest, 'definition_documents');
        $order = $this->requiredList(
            $manifest,
            'installation_order',
            FilesystemDemoManifestCatalog::MAXIMUM_INSTALLATION_ORDER,
        );
        $installed = [];
        $installedModes = [];
        $messages = [];
        foreach ($order as $entry) {
            $entry = $this->map($entry, 'definition installation entry');
            $fixtureKey = $this->requiredString($entry, 'fixture_key');
            $document = $this->map($documents[$fixtureKey] ?? null, 'definition document');
            $definition = $this->installDefinition($context, $fixtureKey, $document);
            $this->installSchema($context, $definition);
            $installed[$definition->handle] = $definition;
            $installedModes[$definition->handle] = $modes[$fixtureKey] ?? 'open';
            $messages[] = sprintf('Prepared VDM business definition %s.', $definition->handle);
        }
        $this->transactions->transactional(function () use ($context, $installed, $installedModes, $profile): void {
            $createdPolicies = false;
            foreach ($installed as $definition) {
                $createdPolicies = $this->installRecordPolicies(
                    $context,
                    $definition,
                    $profile,
                    $installedModes[$definition->handle],
                ) || $createdPolicies;
            }
            if ($createdPolicies) {
                $this->database->executeStatement(sprintf(
                    'UPDATE %s SET policy_generation = policy_generation + 1 WHERE identifier = ?',
                    $this->tables->quoted('sites'),
                ), [$context->site()->identifier()]);
            }
        });

        $versions = $this->createRecords($context, $operations['records']);
        $this->relateRecords($context, $operations['relations'], $versions);
        $this->executeActions($context, $operations['actions'], $versions);
        $this->archiveRecords($context, $operations['archives'], $versions);
        $messages[] = sprintf('Reconciled %d VDM business records and their example workflows.', count($versions));

        return $messages;
    }

    /**
     * Validate every desired definition and policy against all existing checkpoints and live resources.
     *
     * @param   ExecutionContext            $context    Profile installer context.
     * @param   array<string, mixed>        $documents  Projected definition documents by fixture key.
     * @param   list<mixed>                 $order      Bounded released definition order.
     * @param   array{
     *              records: list<array<string, mixed>>,
     *              relations: list<array<string, mixed>>,
     *              actions: list<array<string, mixed>>,
     *              archives: list<array<string, mixed>>
     *          }                     $operations  Validated record-operation declarations.
     * @param   list<array<string, mixed>>  $assets     Complete VDM dataset checkpoint set.
     * @param   string                      $profile    Validated business demo profile name.
     * @param   array<string, string>       $modes      Validated record-access mode by definition fixture key.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a fixture was removed, reused, corrupted, or diverged in live state.
     *
     * @since   2.0.0
     */
    private function preflightDefinitionsAndPolicies(
        ExecutionContext $context,
        array $documents,
        array $order,
        array $operations,
        array $assets,
        string $profile,
        array $modes,
    ): void {
        /** @var array<string, EntityTypeDefinition> $definitions */
        $definitions = [];
        foreach ($order as $candidate) {
            $entry = $this->map($candidate, 'definition installation entry');
            $fixtureKey = $this->requiredString($entry, 'fixture_key');
            if (isset($definitions[$fixtureKey])) {
                throw new RuntimeException(sprintf('VDM definition fixture %s is duplicated.', $fixtureKey));
            }
            $document = $this->map($documents[$fixtureKey] ?? null, 'definition document');
            $definitions[$fixtureKey] = EntityTypeDefinition::fromArray($document);
        }
        if (count($definitions) !== count($documents)) {
            throw new RuntimeException('Every VDM definition document must appear exactly once in installation order.');
        }
        foreach ($documents as $fixtureKey => $document) {
            if (!isset($definitions[$fixtureKey])) {
                throw new RuntimeException(sprintf(
                    'VDM definition document %s is absent from installation order.',
                    $fixtureKey,
                ));
            }
        }

        /** @var array<string, array<string, mixed>> $assetsByFixture */
        $assetsByFixture = [];
        foreach ($assets as $offset => $asset) {
            $fixtureKey = $this->requiredString($asset, 'fixture_key');
            if (isset($assetsByFixture[$fixtureKey])) {
                throw new RuntimeException(sprintf(
                    'VDM asset fixture %s is duplicated at offset %d.',
                    $fixtureKey,
                    $offset,
                ));
            }
            $assetsByFixture[$fixtureKey] = $asset;
        }

        /** @var array<string, array<string, mixed>> $policies */
        $policies = [];
        foreach ($definitions as $definitionFixture => $definition) {
            foreach (self::RECORD_OPERATIONS as $operation) {
                $mode = $modes[$definitionFixture] ?? 'open';
                foreach ($this->policyBaselines($definition, $operation, $profile, $mode) as $policy) {
                    $fixtureKey = $this->requiredString($policy, 'fixture_key');
                    if (isset($policies[$fixtureKey])) {
                        throw new RuntimeException(sprintf('VDM policy fixture %s is duplicated.', $fixtureKey));
                    }
                    $policies[$fixtureKey] = $policy;
                }
            }
        }

        $claims = [];
        foreach (array_keys($definitions) as $fixtureKey) {
            $claims[] = ['fixture_key' => $fixtureKey, 'resource_type' => 'business_definition'];
        }
        foreach (array_keys($policies) as $fixtureKey) {
            $claims[] = ['fixture_key' => $fixtureKey, 'resource_type' => 'resource_policy'];
        }
        foreach (
            [
                'business_record' => $operations['records'],
                'business_relation' => $operations['relations'],
                'business_action' => $operations['actions'],
                'business_archive' => $operations['archives'],
            ] as $resourceType => $declarations
        ) {
            foreach ($declarations as $operation) {
                $claims[] = [
                    'fixture_key' => $this->requiredString($operation, 'fixture_key'),
                    'resource_type' => $resourceType,
                ];
            }
        }
        $this->operations->validateFixtureOwnership($claims, $assets);

        foreach ($assetsByFixture as $fixtureKey => $asset) {
            $resourceType = $asset['resource_type'] ?? null;
            if ($resourceType === 'business_definition') {
                $definition = $definitions[$fixtureKey] ?? null;
                if ($definition === null) {
                    throw new RuntimeException(sprintf(
                        'VDM definition fixture %s was removed while its applied checkpoint remains.',
                        $fixtureKey,
                    ));
                }
                $this->assertDefinitionAsset($fixtureKey, $definition, $asset);
            } elseif ($resourceType === 'resource_policy') {
                $policy = $policies[$fixtureKey] ?? null;
                if ($policy === null) {
                    throw new RuntimeException(sprintf(
                        'VDM policy fixture %s was removed while its applied checkpoint remains.',
                        $fixtureKey,
                    ));
                }
                $this->assertPolicyAsset($fixtureKey, $policy, $asset);
            }
        }

        foreach ($definitions as $fixtureKey => $definition) {
            $asset = $assetsByFixture[$fixtureKey] ?? null;
            if ($asset !== null && ($asset['resource_type'] ?? null) !== 'business_definition') {
                throw new RuntimeException(sprintf(
                    'VDM definition fixture %s reuses a checkpoint owned by another resource type.',
                    $fixtureKey,
                ));
            }
            $this->assertDefinitionRuntime($context, $definition, $asset);
        }
        foreach ($policies as $fixtureKey => $policy) {
            $asset = $assetsByFixture[$fixtureKey] ?? null;
            if ($asset !== null && ($asset['resource_type'] ?? null) !== 'resource_policy') {
                throw new RuntimeException(sprintf(
                    'VDM policy fixture %s reuses a checkpoint owned by another resource type.',
                    $fixtureKey,
                ));
            }
            $this->assertCurrentPolicy($context, $policy, $asset !== null);
        }
    }

    /**
     * Validate one definition checkpoint as an internally consistent mutable divergence baseline.
     *
     * @param   string                $fixtureKey  Stable definition fixture key.
     * @param   EntityTypeDefinition  $desired     Projected released draft identity.
     * @param   array<string, mixed>  $asset       Persisted definition checkpoint.
     *
     * @return  void
     *
     * @throws  RuntimeException  When identity, state, checksum, or version is corrupt.
     *
     * @since   2.0.0
     */
    private function assertDefinitionAsset(
        string $fixtureKey,
        EntityTypeDefinition $desired,
        array $asset,
    ): void {
        $resourceId = $this->requiredString($asset, 'resource_id');
        if ($resourceId !== $desired->id) {
            throw new RuntimeException(sprintf('VDM definition fixture %s changed resource identity.', $fixtureKey));
        }
        $state = $this->map($asset['last_applied_state'] ?? null, 'definition checkpoint state');
        $stored = EntityTypeDefinition::fromArray($state);
        $checksum = $this->checksum($asset, $fixtureKey);
        $version = $this->persistedPositiveInteger(
            $asset['last_applied_version'] ?? null,
            sprintf('VDM definition fixture %s version', $fixtureKey),
        );
        if (
            !hash_equals($checksum, $stored->checksum())
            || $version !== $stored->definitionVersion
            || $stored->id !== $desired->id
            || $stored->handle !== $desired->handle
            || $stored->siteIdentifier !== $desired->siteIdentifier
            || $stored->owner->toArray() !== $desired->owner->toArray()
        ) {
            throw new RuntimeException(sprintf(
                'VDM definition fixture %s has an inconsistent applied checkpoint.',
                $fixtureKey,
            ));
        }
    }

    /**
     * Check current published and draft definitions in one read-only pass before any definition is changed.
     *
     * An exact uncheckpointed definition or draft is accepted as recoverable crash residue: definition
     * publication commits through its application service before this installer can write its ledger asset.
     * Deterministic identity, ownership, and complete checksums make that adoption exact rather than heuristic.
     *
     * @param   ExecutionContext       $context  Profile installer context.
     * @param   EntityTypeDefinition   $desired  Projected released draft.
     * @param   ?array<string, mixed>  $asset    Validated prior checkpoint, or null before first apply.
     *
     * @return  void
     *
     * @throws  RuntimeException  When current state disappeared or diverged from both desired and baseline.
     *
     * @since   2.0.0
     */
    private function assertDefinitionRuntime(
        ExecutionContext $context,
        EntityTypeDefinition $desired,
        ?array $asset,
    ): void {
        try {
            $published = $this->definitions->published($context, $desired->handle)->definition;
            $desiredPublished = $desired->published($published->definitionVersion);
            if (
                !hash_equals($published->checksum(), $desiredPublished->checksum())
                && ($asset === null || !hash_equals($this->checksum($asset, $desired->handle), $published->checksum()))
            ) {
                throw new RuntimeException(sprintf(
                    'VDM definition %s was customized; refusing demo reconciliation.',
                    $desired->handle,
                ));
            }
        } catch (BusinessDefinitionNotFound) {
            if ($asset !== null) {
                throw new RuntimeException(sprintf(
                    'VDM definition %s is missing while its applied checkpoint remains.',
                    $desired->handle,
                ));
            }
        }

        try {
            $draft = $this->definitions->draft($context, $desired->handle);
            if (!hash_equals($desired->checksum(), $draft->definition->checksum())) {
                throw new RuntimeException(sprintf(
                    'VDM definition %s has a divergent draft; refusing demo reconciliation.',
                    $desired->handle,
                ));
            }
        } catch (BusinessDefinitionNotFound) {
        }
    }

    /**
     * Business-record operations an organization-scoped portal member may read with.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array READ_OPERATIONS = [
        'business.record.browse',
        'business.record.export',
        'business.record.history',
        'business.record.read',
        'business.record.report',
    ];

    /**
     * Business-record operations that execute declared workflow steps rather than structural edits.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array WORKFLOW_OPERATIONS = [
        'business.record.action',
        'business.record.transition',
    ];

    /**
     * Derive every deterministic policy row one definition operation requires under its access mode.
     *
     * The `open` mode answers the historical constant-true allow row byte for byte, so released
     * checkpoints stay valid. The `organization` mode scopes portal members to records whose
     * `organization` field equals their authenticated organization for reading and for declared
     * workflow actions, while structural mutations stay with organization-less contexts —
     * administrator, CLI, API token, and this installer — which always keep full visibility.
     * The `organization-read` mode narrows that further to reading alone. The `administration`
     * mode keeps the historical allow row and appends a deny row that vetoes all organization
     * contexts, so legacy definitions never leak between portal clients.
     *
     * @param   EntityTypeDefinition  $definition  Projected definition whose fields establish disclosure.
     * @param   string                $operation   Exact business-record capability and policy action.
     * @param   string                $profile     Validated business demo profile name.
     * @param   string                $mode        Validated record-access mode for the definition.
     *
     * @return  list<array<string, mixed>>  Desired policy baselines used by preflight and installation.
     *
     * @since   2.0.0
     */
    private function policyBaselines(
        EntityTypeDefinition $definition,
        string $operation,
        string $profile,
        string $mode,
    ): array {
        $fields = $this->recordFieldRules($definition);
        $organizationLess = [
            'type' => 'attribute_null',
            'source' => 'context',
            'attribute' => 'organization',
            'is_null' => true,
        ];
        $organizationScoped = in_array($operation, self::READ_OPERATIONS, true)
            || ($mode === 'organization' && in_array($operation, self::WORKFLOW_OPERATIONS, true));
        $allow = match (true) {
            $mode === 'open', $mode === 'administration' => ['type' => 'constant', 'value' => true],
            $organizationScoped => [
                'type' => 'boolean',
                'operator' => 'any',
                'children' => [
                    $organizationLess,
                    [
                        'type' => 'field_attribute_comparison',
                        'field' => 'organization',
                        'source' => 'context',
                        'attribute' => 'organization',
                        'operator' => 'equal',
                        'value_type' => 'string',
                    ],
                ],
            ],
            default => $organizationLess,
        };
        $baselines = [
            $this->assemblePolicyBaseline($definition, $operation, $profile, '', 'allow', $allow, $fields),
        ];
        if ($mode === 'administration') {
            $guard = [
                'type' => 'attribute_null',
                'source' => 'context',
                'attribute' => 'organization',
                'is_null' => false,
            ];
            $baselines[] = $this->assemblePolicyBaseline(
                $definition,
                $operation,
                $profile,
                '.portal-guard',
                'deny',
                $guard,
                $this->emptyFieldRules(),
            );
        }

        return $baselines;
    }

    /**
     * Assemble one deterministic policy row and its immutable ledger checkpoint identity.
     *
     * @param   EntityTypeDefinition  $definition  Projected definition owning the policy.
     * @param   string                $operation   Exact business-record capability and policy action.
     * @param   string                $profile     Validated business demo profile name.
     * @param   string                $codeSuffix  Stable policy-code suffix distinguishing companion rows.
     * @param   string                $effect      Policy effect, `allow` or `deny`.
     * @param   array<string, mixed>  $predicate   Canonical record predicate document.
     * @param   array<string, mixed>  $fields      Field disclosure rules for the row.
     *
     * @return  array<string, mixed>  Complete desired policy baseline.
     *
     * @since   2.0.0
     */
    private function assemblePolicyBaseline(
        EntityTypeDefinition $definition,
        string $operation,
        string $profile,
        string $codeSuffix,
        string $effect,
        array $predicate,
        array $fields,
    ): array {
        $policyCode = $this->policyCode($definition, $operation, $profile) . $codeSuffix;
        $fixtureKey = 'policy.' . substr(hash('sha256', $policyCode), 0, 32);
        $id = Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            sprintf('https://kumwe.dev/demo/%s/policy/%s', $profile, $policyCode),
        )->toString();
        $state = [
            'policy_code' => $policyCode,
            'definition_id' => $definition->id,
            'operation' => $operation,
        ];

        return [
            'fixture_key' => $fixtureKey,
            'id' => $id,
            'policy_code' => $policyCode,
            'definition_id' => $definition->id,
            'operation' => $operation,
            'effect' => $effect,
            'predicate' => $predicate,
            'fields' => $fields,
            'ast_checksum' => CanonicalDefinitionJson::checksum(['ast' => $predicate, 'fields' => $fields]),
            'state' => $state,
            'asset_checksum' => CanonicalDefinitionJson::checksum($state),
        ];
    }

    /**
     * Build the field-rule document a deny row carries: no disclosure in any usage.
     *
     * @return  array<string, list<string>>  Every field-access usage mapped to an empty allowlist.
     *
     * @since   2.0.0
     */
    private function emptyFieldRules(): array
    {
        $rules = [];
        foreach (FieldAccessUsage::cases() as $usage) {
            $rules[$usage->value] = [];
        }
        $rules['actions'] = [];

        return $rules;
    }

    /**
     * Read and validate the per-definition record-access modes the manifest declares.
     *
     * @param   array<string, mixed>  $manifest  Projected aggregate manifest.
     *
     * @return  array<string, string>  Validated mode by definition fixture key; `open` when undeclared.
     *
     * @throws  RuntimeException  When an installation entry declares an unknown record-access mode.
     *
     * @since   2.0.0
     */
    private function recordAccessModes(array $manifest): array
    {
        $modes = [];
        $declared = $this->requiredList(
            $manifest,
            'installation_order',
            FilesystemDemoManifestCatalog::MAXIMUM_INSTALLATION_ORDER,
        );
        foreach ($declared as $candidate) {
            $entry = $this->map($candidate, 'definition installation entry');
            $fixtureKey = $this->requiredString($entry, 'fixture_key');
            $mode = $entry['record_access'] ?? 'open';
            $modesAllowed = ['open', 'organization', 'organization-read', 'administration'];
            if (!is_string($mode) || !in_array($mode, $modesAllowed, true)) {
                throw new RuntimeException(sprintf(
                    'VDM definition %s declares an unknown record-access mode.',
                    $fixtureKey,
                ));
            }
            $modes[$fixtureKey] = $mode;
        }

        return $modes;
    }

    /**
     * Validate one generated policy checkpoint against its deterministic identity and state.
     *
     * @param   string                $fixtureKey  Stable policy fixture key.
     * @param   array<string, mixed>  $policy      Desired policy baseline.
     * @param   array<string, mixed>  $asset       Persisted policy checkpoint.
     *
     * @return  void
     *
     * @throws  RuntimeException  When resource identity, version, state, or checksum differs.
     *
     * @since   2.0.0
     */
    private function assertPolicyAsset(string $fixtureKey, array $policy, array $asset): void
    {
        $id = $this->requiredString($policy, 'id');
        if ($this->requiredString($asset, 'resource_id') !== $id) {
            throw new RuntimeException(sprintf('VDM policy fixture %s changed resource identity.', $fixtureKey));
        }
        $version = $this->persistedPositiveInteger(
            $asset['last_applied_version'] ?? null,
            sprintf('VDM policy fixture %s version', $fixtureKey),
        );
        $state = $this->map($asset['last_applied_state'] ?? null, 'policy checkpoint state');
        $desiredState = $this->map($policy['state'] ?? null, 'desired policy checkpoint state');
        $storedChecksum = $this->checksum($asset, $fixtureKey);
        $desiredChecksum = $this->requiredString($policy, 'asset_checksum');
        if (
            $version !== 1
            || CanonicalDefinitionJson::encode($state) !== CanonicalDefinitionJson::encode($desiredState)
            || !hash_equals($storedChecksum, CanonicalDefinitionJson::checksum($state))
            || !hash_equals($storedChecksum, $desiredChecksum)
        ) {
            throw new RuntimeException(sprintf(
                'VDM policy fixture %s has an inconsistent applied checkpoint.',
                $fixtureKey,
            ));
        }
    }

    /**
     * Validate one current generated policy row and its site ownership before any definition mutation.
     *
     * @param   ExecutionContext      $context   Profile installer context.
     * @param   array<string, mixed>  $policy    Desired policy baseline.
     * @param   bool                  $hasAsset  Whether exact installer provenance is present.
     *
     * @return  void
     *
     * @throws  RuntimeException  When row, policy document, ownership, or provenance differs.
     *
     * @since   2.0.0
     */
    private function assertCurrentPolicy(ExecutionContext $context, array $policy, bool $hasAsset): void
    {
        $policyCode = $this->requiredString($policy, 'policy_code');
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT id, policy_code, owner_kind, owner_identifier, capability_code, resource_type, action, '
                . 'effect, scope_type, organization_id, entity_definition_id, canonical_ast, field_rules, '
                . 'ast_checksum, policy_version, priority, status FROM %s WHERE policy_code = ?',
            $this->tables->quoted('resource_policies'),
        ), [$policyCode]);
        if ($row === false) {
            if ($hasAsset) {
                throw new RuntimeException(sprintf(
                    'VDM policy %s is missing while its applied checkpoint remains.',
                    $policyCode,
                ));
            }

            return;
        }
        if (!$hasAsset) {
            throw new RuntimeException(sprintf(
                'VDM policy %s exists without an installer provenance checkpoint.',
                $policyCode,
            ));
        }

        $operation = $this->requiredString($policy, 'operation');
        $expected = [
            'id' => $this->requiredString($policy, 'id'),
            'policy_code' => $policyCode,
            'owner_kind' => 'core',
            'owner_identifier' => 'core',
            'capability_code' => $operation,
            'resource_type' => 'business_record',
            'action' => $operation,
            'effect' => $this->requiredString($policy, 'effect'),
            'scope_type' => 'site',
            'entity_definition_id' => $this->requiredString($policy, 'definition_id'),
            'ast_checksum' => $this->requiredString($policy, 'ast_checksum'),
            'status' => 'active',
        ];
        foreach ($expected as $field => $value) {
            if (($row[$field] ?? null) !== $value) {
                throw new RuntimeException(sprintf('VDM policy %s has diverged field %s.', $policyCode, $field));
            }
        }
        if (($row['organization_id'] ?? null) !== null) {
            throw new RuntimeException(sprintf('VDM policy %s has a divergent organization scope.', $policyCode));
        }
        if (
            $this->persistedInteger($row['policy_version'] ?? null, 'policy version') !== 1
            || $this->persistedInteger($row['priority'] ?? null, 'policy priority') !== -1_000
        ) {
            throw new RuntimeException(sprintf('VDM policy %s has divergent precedence metadata.', $policyCode));
        }

        $actualAst = $this->decodedMap($row['canonical_ast'] ?? null, 'policy canonical AST');
        $actualFields = $this->decodedMap($row['field_rules'] ?? null, 'policy field rules');
        $desiredAst = $this->map($policy['predicate'] ?? null, 'desired policy canonical AST');
        $desiredFields = $this->map($policy['fields'] ?? null, 'desired policy field rules');
        $actualChecksum = CanonicalDefinitionJson::checksum(['ast' => $actualAst, 'fields' => $actualFields]);
        if (
            CanonicalDefinitionJson::encode($actualAst) !== CanonicalDefinitionJson::encode($desiredAst)
            || CanonicalDefinitionJson::encode($actualFields) !== CanonicalDefinitionJson::encode($desiredFields)
            || !hash_equals($this->requiredString($row, 'ast_checksum'), $actualChecksum)
        ) {
            throw new RuntimeException(sprintf('VDM policy %s has divergent policy documents.', $policyCode));
        }

        $ownership = $this->database->fetchOne(sprintf(
            'SELECT site_identifier FROM %s WHERE resource_type = ? AND resource_id = ?',
            $this->tables->quoted('resource_site_ownership'),
        ), ['resource_policy', $this->requiredString($policy, 'id')]);
        if ($ownership !== $context->site()->identifier()) {
            throw new RuntimeException(sprintf('VDM policy %s has divergent site ownership.', $policyCode));
        }
    }

    /**
     * Decode one JSON database value and require an object-shaped policy document.
     *
     * @param   mixed   $value  Native driver array or encoded JSON value.
     * @param   string  $name   Diagnostic policy document name.
     *
     * @return  array<string, mixed>  Decoded object-shaped document.
     *
     * @throws  RuntimeException  When JSON is invalid or does not decode to an object.
     *
     * @since   2.0.0
     */
    private function decodedMap(mixed $value, string $name): array
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException(sprintf('The VDM %s is invalid JSON.', $name), 0, $exception);
            }
        }

        return $this->map($value, $name);
    }

    /**
     * Require one canonical lowercase SHA-256 checkpoint checksum.
     *
     * @param   array<string, mixed>  $asset       Persisted checkpoint.
     * @param   string                $fixtureKey  Fixture key used in diagnostics.
     *
     * @return  string  Validated checksum.
     *
     * @throws  RuntimeException  When the checksum is absent or malformed.
     *
     * @since   2.0.0
     */
    private function checksum(array $asset, string $fixtureKey): string
    {
        $checksum = $asset['last_applied_checksum'] ?? null;
        if (!is_string($checksum) || preg_match('/^[a-f0-9]{64}$/D', $checksum) !== 1) {
            throw new RuntimeException(sprintf('VDM fixture %s has an invalid checkpoint checksum.', $fixtureKey));
        }

        return $checksum;
    }

    /**
     * Normalize one persisted integer without accepting fractions or loose numeric strings.
     *
     * @param   mixed   $value  Database-driver value.
     * @param   string  $name   Diagnostic field name.
     *
     * @return  int  Exact integer value.
     *
     * @throws  RuntimeException  When the value is not an exact integer.
     *
     * @since   2.0.0
     */
    private function persistedInteger(mixed $value, string $name): int
    {
        $integer = $this->canonicalPersistedInteger($value);
        if ($integer === null) {
            throw new RuntimeException(sprintf('The VDM %s is invalid.', $name));
        }

        return $integer;
    }

    /**
     * Normalize one persisted positive integer.
     *
     * @param   mixed   $value  Database-driver value.
     * @param   string  $name   Diagnostic field name.
     *
     * @return  positive-int  Exact positive integer value.
     *
     * @throws  RuntimeException  When the value is not a positive integer.
     *
     * @since   2.0.0
     */
    private function persistedPositiveInteger(mixed $value, string $name): int
    {
        $integer = $this->canonicalPersistedInteger($value);
        if ($integer === null || $integer < 1) {
            throw new RuntimeException(sprintf('The VDM %s is invalid.', $name));
        }

        return $integer;
    }

    /**
     * Normalize one canonical integer representation returned by a database driver.
     *
     * Native integers and plain base-ten strings are portable across PDO drivers. Booleans, floats,
     * whitespace, leading zeroes, and explicit plus signs are rejected instead of being coerced.
     *
     * @param   mixed  $value  Candidate database-driver value.
     *
     * @return  ?int  Exact integer, or null when the representation is not canonical or overflows.
     *
     * @since   2.0.0
     */
    private function canonicalPersistedInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^(?:0|-?[1-9][0-9]*)$/D', $value) !== 1) {
            return null;
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) ? $integer : null;
    }

    /**
     * Import and publish one definition, updating it only while the prior demo version remains untouched.
     *
     * Exact published or draft bytes without a ledger asset resume the non-atomic service-to-ledger boundary.
     * Any difference is treated as operator state and refused rather than adopted or overwritten.
     *
     * @param   ExecutionContext      $context     Profile installer context.
     * @param   string                $fixtureKey  Stable definition fixture key.
     * @param   array<string, mixed>  $document    Version-zero site-owned definition document.
     *
     * @return  EntityTypeDefinition  Published definition that now governs generated storage.
     *
     * @since   2.0.0
     */
    private function installDefinition(
        ExecutionContext $context,
        string $fixtureKey,
        array $document,
    ): EntityTypeDefinition {
        $draftDefinition = EntityTypeDefinition::fromArray($document);
        $asset = $this->ledger->asset($context->site()->identifier(), self::DATASET, $fixtureKey);
        $published = null;
        try {
            $published = $this->definitions->published($context, $draftDefinition->handle)->definition;
        } catch (BusinessDefinitionNotFound) {
        }
        if ($published !== null) {
            $desired = $draftDefinition->published($published->definitionVersion);
            if ($published->checksum() === $desired->checksum()) {
                $this->recordDefinitionAsset($context, $fixtureKey, $published);

                return $published;
            }
            if (($asset['last_applied_checksum'] ?? null) !== $published->checksum()) {
                throw new RuntimeException(sprintf(
                    'VDM definition %s was customized; refusing to overwrite it during demo reconciliation.',
                    $draftDefinition->handle,
                ));
            }
        }

        try {
            $draft = $this->definitions->draft($context, $draftDefinition->handle);
            if (!hash_equals($draftDefinition->checksum(), $draft->definition->checksum())) {
                throw new RuntimeException(sprintf(
                    'VDM definition %s has a divergent draft; refusing to overwrite it during demo reconciliation.',
                    $draftDefinition->handle,
                ));
            }
        } catch (BusinessDefinitionNotFound) {
            $draft = $this->definitions->importDraft($context, $document);
        }
        $published = $this->definitions->publish($context, $draft->definition->id, $draft->revision, true)->definition;
        $this->recordDefinitionAsset($context, $fixtureKey, $published);

        return $published;
    }

    /**
     * Persist the current published definition as the profile's immutable divergence baseline.
     *
     * @param   ExecutionContext      $context     Profile installer context.
     * @param   string                $fixtureKey  Stable definition fixture key.
     * @param   EntityTypeDefinition  $definition  Published definition.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function recordDefinitionAsset(
        ExecutionContext $context,
        string $fixtureKey,
        EntityTypeDefinition $definition,
    ): void {
        $state = $definition->toArray();
        $this->ledger->recordAsset(
            $context->site()->identifier(),
            self::DATASET,
            $fixtureKey,
            'business_definition',
            $definition->id,
            $definition->checksum(),
            $definition->definitionVersion,
            $state,
        );
    }

    /**
     * Drive one published definition to an active generated schema, resuming any interrupted plan.
     *
     * @param   ExecutionContext      $context     Profile installer context.
     * @param   EntityTypeDefinition  $definition  Published definition requiring active storage.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function installSchema(ExecutionContext $context, EntityTypeDefinition $definition): void
    {
        $installation = $this->schemas->installation($context, $definition->id);
        if (
            $installation?->status === SchemaInstallationStatus::Active
            && $installation->definitionVersion === $definition->definitionVersion
        ) {
            return;
        }
        $plan = $this->schemas->createPlan($context, $definition->id);
        if ($plan->status === SchemaPlanStatus::PendingApproval) {
            $confirmation = $plan->risk->requiresHighImpactAuthorization() ? $plan->checksum() : null;
            $plan = $this->schemas->approve($context, $plan->id, $plan->checksum(), $confirmation, null);
        }
        if ($plan->status === SchemaPlanStatus::Approved) {
            $this->schemas->execute($context, $plan->id);
        } elseif (
            in_array($plan->status, [
            SchemaPlanStatus::Executing,
            SchemaPlanStatus::Failed,
            SchemaPlanStatus::RecoveryRequired,
            ], true)
        ) {
            $this->schemas->recover($context, $plan->id);
        }
        $installation = $this->schemas->installation($context, $definition->id);
        if ($installation?->status !== SchemaInstallationStatus::Active) {
            throw new RuntimeException(sprintf('The VDM schema for %s did not become active.', $definition->handle));
        }
    }

    /**
     * Install explicit row/field policies derived from immutable definition exposure ceilings.
     *
     * @param   ExecutionContext      $context     Profile installer context.
     * @param   EntityTypeDefinition  $definition  Published definition receiving policies.
     * @param   string                $profile     Validated business demo profile name.
     * @param   string                $mode        Validated record-access mode for the definition.
     *
     * @return  bool  Whether at least one new policy row was installed.
     *
     * @since   2.0.0
     */
    private function installRecordPolicies(
        ExecutionContext $context,
        EntityTypeDefinition $definition,
        string $profile,
        string $mode,
    ): bool {
        $created = false;
        foreach (self::RECORD_OPERATIONS as $operation) {
            foreach ($this->policyBaselines($definition, $operation, $profile, $mode) as $baseline) {
                $policyCode = $this->requiredString($baseline, 'policy_code');
                $existing = $this->database->fetchAssociative(sprintf(
                    'SELECT id, entity_definition_id, action, ast_checksum FROM %s WHERE policy_code = ?',
                    $this->tables->quoted('resource_policies'),
                ), [$policyCode]);
                if ($existing !== false) {
                    $this->assertPolicyCheckpointAtInstallation($context, $baseline);
                    continue;
                }
                $created = true;
                $id = $this->requiredString($baseline, 'id');
                $effect = $this->requiredString($baseline, 'effect');
                $predicate = $this->map($baseline['predicate'] ?? null, 'policy predicate');
                $fields = $this->map($baseline['fields'] ?? null, 'policy field rules');
                $checksum = $this->requiredString($baseline, 'ast_checksum');
                $now = $this->clock->now();
                $this->transactions->transactional(function () use (
                    $context,
                    $definition,
                    $operation,
                    $policyCode,
                    $effect,
                    $predicate,
                    $fields,
                    $checksum,
                    $id,
                    $now,
                ): void {
                    $this->database->insert($this->tables->raw('resource_policies'), [
                        'id' => $id,
                        'policy_code' => $policyCode,
                        'owner_kind' => 'core',
                        'owner_identifier' => 'core',
                        'capability_code' => $operation,
                        'resource_type' => 'business_record',
                        'action' => $operation,
                        'effect' => $effect,
                        'scope_type' => 'site',
                        'organization_id' => null,
                        'entity_definition_id' => $definition->id,
                        'canonical_ast' => $predicate,
                        'field_rules' => $fields,
                        'ast_checksum' => $checksum,
                        'policy_version' => 1,
                        'priority' => -1_000,
                        'status' => 'active',
                        'created_by' => $context->actorId(),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], [
                        'canonical_ast' => Types::JSON,
                        'field_rules' => Types::JSON,
                        'created_at' => Types::DATETIME_IMMUTABLE,
                        'updated_at' => Types::DATETIME_IMMUTABLE,
                    ]);
                    $this->recordOwnership($context, 'resource_policy', $id);
                    $this->audit->record(new AuditEvent(
                        Uuid::uuid7()->toString(),
                        $now,
                        $context->actorId(),
                        'demo.business.policy.install',
                        'resource_policy',
                        $id,
                        'success',
                        [
                            'site' => $context->site()->identifier(),
                            'policy_code' => $policyCode,
                            'definition_id' => $definition->id,
                        ],
                    ));
                });
                $this->ledger->recordAsset(
                    $context->site()->identifier(),
                    self::DATASET,
                    $this->requiredString($baseline, 'fixture_key'),
                    'resource_policy',
                    $id,
                    $this->requiredString($baseline, 'asset_checksum'),
                    1,
                    $this->map($baseline['state'] ?? null, 'policy checkpoint state'),
                );
            }
        }

        return $created;
    }

    /**
     * Re-read and validate one existing policy checkpoint at the point where installation would skip it.
     *
     * Preflight is deliberately read-only and cannot prove provenance still exists when this later branch
     * runs. Requiring the exact checkpoint here prevents a row inserted between those reads, or a row whose
     * checkpoint was concurrently removed, from being adopted merely because its deterministic fields match.
     *
     * @param   ExecutionContext      $context  Profile installer context.
     * @param   array<string, mixed>  $policy   Desired deterministic policy baseline.
     *
     * @return  void
     *
     * @throws  RuntimeException  When provenance is absent, belongs to another resource type, is corrupt,
     *          or no longer agrees with the live policy row.
     *
     * @since   2.0.0
     */
    private function assertPolicyCheckpointAtInstallation(ExecutionContext $context, array $policy): void
    {
        $fixtureKey = $this->requiredString($policy, 'fixture_key');
        $asset = $this->ledger->asset(
            $context->site()->identifier(),
            self::DATASET,
            $fixtureKey,
        );
        if ($asset === null) {
            $this->assertCurrentPolicy($context, $policy, false);
            throw new RuntimeException(sprintf(
                'VDM policy %s changed while its installer provenance was being verified.',
                $this->requiredString($policy, 'policy_code'),
            ));
        }
        if (($asset['resource_type'] ?? null) !== 'resource_policy') {
            throw new RuntimeException(sprintf(
                'VDM policy fixture %s reuses a checkpoint owned by another resource type.',
                $fixtureKey,
            ));
        }

        $this->assertPolicyAsset($fixtureKey, $policy, $asset);
        $this->assertCurrentPolicy($context, $policy, true);
    }

    /**
     * Create every stable record once and rebuild source-version tracking when resuming.
     *
     * @param   ExecutionContext  $context       Profile installer context.
     * @param   list<mixed>       $declarations  Record declarations in dependency order.
     *
     * @return  array<string, int>  Latest known record version by public record ID.
     *
     * @since   2.0.0
     */
    private function createRecords(ExecutionContext $context, array $declarations): array
    {
        $versions = [];
        foreach ($declarations as $candidate) {
            $record = $this->map($candidate, 'record declaration');
            $fixtureKey = $this->requiredString($record, 'fixture_key');
            $recordId = $this->requiredString($record, 'record_id');
            $asset = $this->ledger->asset($context->site()->identifier(), self::DATASET, $fixtureKey);
            if ($asset !== null) {
                $state = $this->map($asset['last_applied_state'] ?? null, 'record checkpoint');
                $versions[$recordId] = $this->requiredInteger($state, 'version');
                continue;
            }
            $versions[$recordId] = $this->transactions->transactional(function () use (
                $context,
                $record,
                $fixtureKey,
                $recordId,
            ): int {
                $result = $this->records->create(new CreateRecordCommand(
                    $context,
                    $this->requiredString($record, 'definition'),
                    $this->requiredMap($record, 'values'),
                    IdempotencyKey::fromString($this->requiredString($record, 'idempotency_key')),
                    recordId: $recordId,
                ));
                $this->recordOperationAsset(
                    $context,
                    $fixtureKey,
                    'business_record',
                    $recordId,
                    $record,
                    $result->toArray(),
                );

                return $result->version;
            });
        }

        return $versions;
    }

    /**
     * Link all declared related records while advancing each source record's optimistic version.
     *
     * @param   ExecutionContext  $context       Profile installer context.
     * @param   list<mixed>       $declarations  Relationship declarations.
     * @param   array<string, int>   &$versions     Latest source versions by record ID.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function relateRecords(ExecutionContext $context, array $declarations, array &$versions): void
    {
        foreach ($declarations as $candidate) {
            $relation = $this->map($candidate, 'relationship declaration');
            $fixtureKey = $this->requiredString($relation, 'fixture_key');
            $source = $this->requiredString($relation, 'source_record_id');
            $asset = $this->ledger->asset($context->site()->identifier(), self::DATASET, $fixtureKey);
            if ($asset !== null) {
                $state = $this->map($asset['last_applied_state'] ?? null, 'relationship checkpoint');
                $versions[$source] = $this->requiredInteger($state, 'version');
                continue;
            }
            $expectedVersion = $versions[$source] ?? throw new RuntimeException(sprintf(
                'VDM relationship %s has no source version.',
                $fixtureKey,
            ));
            $versions[$source] = $this->transactions->transactional(function () use (
                $context,
                $relation,
                $source,
                $expectedVersion,
                $fixtureKey,
            ): int {
                $result = $this->records->relate(new RelateRecordsCommand(
                    $context,
                    $this->requiredString($relation, 'definition'),
                    $source,
                    $expectedVersion,
                    $this->requiredString($relation, 'relationship'),
                    $this->requiredString($relation, 'target_record_id'),
                    IdempotencyKey::fromString($this->requiredString($relation, 'idempotency_key')),
                    $this->optionalInteger($relation, 'position'),
                    targetValues: $this->optionalMap($relation, 'target_values'),
                ));
                $this->recordOperationAsset(
                    $context,
                    $fixtureKey,
                    'business_relation',
                    $source,
                    $relation,
                    $result->toArray(),
                );

                return $result->version;
            });
        }
    }

    /**
     * Execute the manifest's workflow actions in sequence, reconstructing versions on replay.
     *
     * @param   ExecutionContext  $context       Profile installer context.
     * @param   list<mixed>       $declarations  Action declarations.
     * @param   array<string, int>  &$versions     Latest record versions by ID.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function executeActions(ExecutionContext $context, array $declarations, array &$versions): void
    {
        foreach ($declarations as $candidate) {
            $action = $this->map($candidate, 'action declaration');
            $fixtureKey = $this->requiredString($action, 'fixture_key');
            $recordId = $this->requiredString($action, 'record_id');
            $asset = $this->ledger->asset($context->site()->identifier(), self::DATASET, $fixtureKey);
            if ($asset !== null) {
                $state = $this->map($asset['last_applied_state'] ?? null, 'action checkpoint');
                $versions[$recordId] = $this->requiredInteger($state, 'version');
                continue;
            }
            $expectedVersion = $versions[$recordId]
                ?? throw new RuntimeException('A VDM action has no record version.');
            $versions[$recordId] = $this->transactions->transactional(function () use (
                $context,
                $action,
                $recordId,
                $expectedVersion,
                $fixtureKey,
            ): int {
                $result = $this->records->action(new ExecuteRecordActionCommand(
                    $context,
                    $this->requiredString($action, 'definition'),
                    $recordId,
                    $expectedVersion,
                    $this->requiredString($action, 'action'),
                    IdempotencyKey::fromString($this->requiredString($action, 'idempotency_key')),
                ));
                $this->recordOperationAsset(
                    $context,
                    $fixtureKey,
                    'business_action',
                    $recordId,
                    $action,
                    $result->toArray(),
                );

                return $result->version;
            });
        }
    }

    /**
     * Archive the one historical sample after every workflow action has settled.
     *
     * @param   ExecutionContext  $context       Profile installer context.
     * @param   list<mixed>       $declarations  Archive declarations.
     * @param   array<string, int>  &$versions     Latest record versions by ID.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function archiveRecords(ExecutionContext $context, array $declarations, array &$versions): void
    {
        foreach ($declarations as $candidate) {
            $archive = $this->map($candidate, 'archive declaration');
            $fixtureKey = $this->requiredString($archive, 'fixture_key');
            $recordId = $this->requiredString($archive, 'record_id');
            $asset = $this->ledger->asset($context->site()->identifier(), self::DATASET, $fixtureKey);
            if ($asset !== null) {
                $state = $this->map($asset['last_applied_state'] ?? null, 'archive checkpoint');
                $versions[$recordId] = $this->requiredInteger($state, 'version');
                continue;
            }
            $expectedVersion = $versions[$recordId]
                ?? throw new RuntimeException('A VDM archive has no record version.');
            $versions[$recordId] = $this->transactions->transactional(function () use (
                $context,
                $archive,
                $recordId,
                $expectedVersion,
                $fixtureKey,
            ): int {
                $result = $this->records->archive(new ArchiveRecordCommand(
                    $context,
                    $this->requiredString($archive, 'definition'),
                    $recordId,
                    $expectedVersion,
                    IdempotencyKey::fromString($this->requiredString($archive, 'idempotency_key')),
                ));
                $this->recordOperationAsset(
                    $context,
                    $fixtureKey,
                    'business_archive',
                    $recordId,
                    $archive,
                    $result->toArray(),
                );

                return $result->version;
            });
        }
    }

    /**
     * Store one replayable operation checkpoint with its resulting source version.
     *
     * @param   ExecutionContext                     $context       Profile installer context.
     * @param   string                               $fixtureKey    Stable operation fixture key.
     * @param   string                               $resourceType  Diagnostic resource noun.
     * @param   string                               $resourceId    Public record identity.
     * @param   array<string, mixed>                 $request       Canonical manifest request.
     * @param   array<string, int|string|bool|null>  $result        Mutation result.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function recordOperationAsset(
        ExecutionContext $context,
        string $fixtureKey,
        string $resourceType,
        string $resourceId,
        array $request,
        array $result,
    ): void {
        $state = ['request' => $request, ...$result];
        $this->ledger->recordAsset(
            $context->site()->identifier(),
            self::DATASET,
            $fixtureKey,
            $resourceType,
            $resourceId,
            CanonicalDefinitionJson::checksum($request),
            $this->requiredInteger($state, 'version'),
            $state,
        );
    }

    /**
     * Derive every field disclosure usage from immutable definition flags and sensitivity ceilings.
     *
     * @param   EntityTypeDefinition  $definition  Published definition supplying field metadata.
     *
     * @return  array<string, list<string>>  Explicit allowed fields per usage plus declared actions.
     *
     * @since   2.0.0
     */
    private function recordFieldRules(EntityTypeDefinition $definition): array
    {
        $allowed = [];
        foreach (FieldAccessUsage::cases() as $usage) {
            $allowed[$usage->value] = [];
        }
        foreach ($definition->fields() as $field) {
            $readable = $field->readVisible;
            $queryable = $readable && !in_array($field->sensitivity->value, ['restricted', 'secret'], true);
            $this->addField(
                $allowed,
                FieldAccessUsage::Create,
                $field,
                $field->createVisible && !$field->serverOnly && !$field->computed && $field->formula === null,
            );
            $this->addField(
                $allowed,
                FieldAccessUsage::Update,
                $field,
                $field->updateVisible && !$field->serverOnly && !$field->readOnly
                    && !$field->computed && $field->formula === null,
            );
            $this->addField($allowed, FieldAccessUsage::Detail, $field, $readable);
            $this->addField($allowed, FieldAccessUsage::List, $field, $readable);
            $this->addField($allowed, FieldAccessUsage::Mcp, $field, $readable);
            $this->addField($allowed, FieldAccessUsage::Include, $field, $readable);
            $this->addField($allowed, FieldAccessUsage::Filter, $field, $queryable && $field->filterable);
            $this->addField($allowed, FieldAccessUsage::Relation, $field, $queryable && $field->filterable);
            $this->addField($allowed, FieldAccessUsage::Search, $field, $queryable && $field->searchable);
            $this->addField($allowed, FieldAccessUsage::Sort, $field, $queryable && $field->sortable);
            $this->addField($allowed, FieldAccessUsage::Aggregate, $field, $queryable && $field->reportable);
            $this->addField($allowed, FieldAccessUsage::Report, $field, $queryable && $field->reportable);
            $this->addField($allowed, FieldAccessUsage::Export, $field, $queryable && $field->exportable);
            $this->addField(
                $allowed,
                FieldAccessUsage::PublicReference,
                $field,
                $queryable && $field->type === ($definition->identityStrategy === IdentityStrategy::Uuid
                    ? 'core.uuid'
                    : 'core.reference_identity'),
            );
            $this->addField($allowed, FieldAccessUsage::Audit, $field, $readable);
        }
        $allowed['actions'] = array_map(static fn ($action): string => $action->handle, $definition->actions());

        return $allowed;
    }

    /**
     * Add one field handle to an explicit usage only when its immutable metadata admits it.
     *
     * @param   array<string, list<string>>  &$allowed   Field rules under construction.
     * @param   FieldAccessUsage  $usage      Exact disclosure context.
     * @param   FieldDefinition   $field      Published field metadata.
     * @param   bool              $condition  Whether this usage is permitted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function addField(
        array &$allowed,
        FieldAccessUsage $usage,
        FieldDefinition $field,
        bool $condition,
    ): void {
        if ($condition) {
            $allowed[$usage->value][] = $field->handle;
        }
    }

    /**
     * Record site ownership for the specialized policy bootstrap without duplicating an existing row.
     *
     * @param   ExecutionContext  $context       Profile installer context.
     * @param   string            $resourceType  Resource noun.
     * @param   string            $resourceId    Stable resource UUID.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function recordOwnership(ExecutionContext $context, string $resourceType, string $resourceId): void
    {
        $exists = $this->database->fetchOne(sprintf(
            'SELECT resource_id FROM %s WHERE resource_type = ? AND resource_id = ?',
            $this->tables->quoted('resource_site_ownership'),
        ), [$resourceType, $resourceId]);
        if ($exists === false) {
            $this->database->insert($this->tables->raw('resource_site_ownership'), [
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'site_identifier' => $context->site()->identifier(),
            ]);
        }
    }

    /**
     * Derive the stable bounded policy code for one definition operation.
     *
     * @param   EntityTypeDefinition  $definition  Published definition that owns the policy.
     * @param   string                $operation   Business-record capability represented by the policy.
     * @param   string                $profile     Validated business demo profile name.
     *
     * @return  string  Stable policy code unique to the profile, definition, and operation.
     *
     * @since   2.0.0
     */
    private function policyCode(EntityTypeDefinition $definition, string $operation, string $profile): string
    {
        return 'core.demo.' . $profile . '.'
            . str_replace('-', '', $definition->id)
            . '.'
            . substr($operation, strlen('business.record.'));
    }

    /**
     * Read one required object-shaped value from a manifest object.
     *
     * @param   array<string, mixed>  $document  Manifest object carrying the nested object.
     * @param   string                $key       Required field name.
     *
     * @return  array<string, mixed>  Validated object-shaped value.
     *
     * @since   2.0.0
     */
    private function requiredMap(array $document, string $key): array
    {
        return $this->map($document[$key] ?? null, sprintf('field %s', $key));
    }

    /**
     * Require a decoded manifest value to be an object-shaped array.
     *
     * @param   mixed   $value  Candidate decoded value.
     * @param   string  $name   Diagnostic noun identifying the value on failure.
     *
     * @return  array<string, mixed>  Validated object-shaped value.
     *
     * @since   2.0.0
     */
    private function map(mixed $value, string $name): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException(sprintf('The VDM demo %s is invalid.', $name));
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new RuntimeException(sprintf('The VDM demo %s has a non-string object key.', $name));
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /**
     * Read one optional object-shaped value from a manifest object.
     *
     * @param   array<string, mixed>  $document  Manifest object carrying the optional nested object.
     * @param   string                $key       Optional field name.
     *
     * @return  array<string, mixed>  Validated object, or an empty object when the field is absent.
     *
     * @since   2.0.0
     */
    private function optionalMap(array $document, string $key): array
    {
        return array_key_exists($key, $document) ? $this->map($document[$key], sprintf('field %s', $key)) : [];
    }

    /**
     * Read one required list while enforcing its declared fixture bound.
     *
     * @param   array<string, mixed>  $document  Manifest object carrying the list.
     * @param   string                $key       Required field name.
     * @param   int                   $maximum   Largest accepted item count.
     *
     * @return  list<mixed>  Validated bounded manifest list.
     *
     * @since   2.0.0
     */
    private function requiredList(array $document, string $key, int $maximum): array
    {
        $value = $document[$key] ?? null;
        if (!is_array($value) || !array_is_list($value) || count($value) > $maximum) {
            throw new RuntimeException(sprintf('The VDM demo list %s is invalid.', $key));
        }

        return $value;
    }

    /**
     * Read one required non-empty string from a manifest object.
     *
     * @param   array<string, mixed>  $document  Manifest object carrying the field.
     * @param   string                $key       Required field name.
     *
     * @return  string  Validated non-empty field value.
     *
     * @since   2.0.0
     */
    private function requiredString(array $document, string $key): string
    {
        $value = $document[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('The VDM demo field %s is invalid.', $key));
        }

        return $value;
    }

    /**
     * Read one required positive integer from a manifest object.
     *
     * @param   array<string, mixed>  $document  Manifest object carrying the field.
     * @param   string                $key       Required field name.
     *
     * @return  int  Validated positive field value.
     *
     * @since   2.0.0
     */
    private function requiredInteger(array $document, string $key): int
    {
        $value = $document[$key] ?? null;
        if (!is_int($value) || $value < 1) {
            throw new RuntimeException(sprintf('The VDM demo field %s is invalid.', $key));
        }

        return $value;
    }

    /**
     * Read one optional non-negative integer from a manifest object.
     *
     * @param   array<string, mixed>  $document  Manifest object carrying the field.
     * @param   string                $key       Optional field name.
     *
     * @return  ?int  Validated non-negative value, or null when absent.
     *
     * @since   2.0.0
     */
    private function optionalInteger(array $document, string $key): ?int
    {
        $value = $document[$key] ?? null;
        if ($value !== null && (!is_int($value) || $value < 0)) {
            throw new RuntimeException(sprintf('The VDM demo field %s is invalid.', $key));
        }

        return $value;
    }
}
