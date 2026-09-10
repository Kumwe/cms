<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Kernel\Container;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordNotFound;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\App\BusinessReporting\Application\ExportArtifactRepository;
use Kumwe\App\BusinessReporting\Application\ExportArtifactStorage;
use Kumwe\App\BusinessReporting\Application\ProjectionRuntime;
use Kumwe\App\BusinessReporting\Application\StoredExportArtifact;
use Kumwe\App\BusinessReporting\Domain\ExportArtifactStatus;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaInstallationRepository;
use Kumwe\App\BusinessSchema\Domain\PhysicalTableKind;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\App\BusinessSecurity\Application\Administration\BusinessSecurityAdministrationService;
use Kumwe\App\BusinessSurface\Application\BusinessRecordProjector;
use Kumwe\App\BusinessIntegration\Application\InboxStore;
use Kumwe\App\BusinessIntegration\Application\OutboxDispatcher;
use Kumwe\App\BusinessIntegration\Application\OutboxStore;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Runtime\RuntimeMaterializationState;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Identity\Application\Administration\AdministratorSessionStore;
use Kumwe\App\Identity\Application\StepUp\AdministratorStepUpProvider;
use Kumwe\App\Identity\Application\StepUp\AuthorizationStepUpProofAdapter;
use Kumwe\App\Identity\Domain\StepUp\StepUpIntent;
use Kumwe\App\Identity\Domain\UserStatus;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Cross-process proof for the signed asset-inspection deployment and its restored durable state.
 *
 * The workflow creates records through public adapters and queues work through the real CLI. This verifier
 * deliberately starts a fresh container for every phase, then checks the public record projection, installed
 * schema checksums, contribution inventory, outbox/inbox evidence, immutable export bytes, and attributable
 * audit rows. The resulting canonical manifest can therefore be compared byte-for-byte after clean restore.
 *
 * @since  2.0.0
 */
final class AssetInspectionDeploymentAcceptance
{
    /** Stable manifest grammar written before backup and compared after restore. @since 2.0.0 */
    private const string FORMAT = 'kumwe-asset-inspection-deployment-acceptance-v1';

    /** Installed example package owner. @since 2.0.0 */
    private const string OWNER = 'kumwe/asset-inspection-example';

    /**
     * Stable inspection definition selected by the signed deployment profile.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string INSPECTION_DEFINITION_ID = '019bc200-0000-7000-8000-000000000003';

    /** Durable consumer whose deduplication receipt is replay-tested. @since 2.0.0 */
    private const string CONSUMER = 'kumwe.asset-inspection-example.inspection-mutation-indexer';

    /** Contributed report used for synchronous execution and immutable CSV export. @since 2.0.0 */
    private const string REPORT = 'kumwe.asset-inspection-example.inspection-summary';

    /** Rebuilt durable projection whose active generation is restart and restore evidence. @since 2.0.0 */
    private const string PROJECTION = 'kumwe.asset-inspection-example.inspection-activity';

    /** Generation-bound recurring schedule preserved across disable and restore. @since 2.0.0 */
    private const string SCHEDULE = 'kumwe.asset-inspection-example.review-overdue-daily';

    /** Canonical checksum of the signed, operator-applied policy profile. @since 2.0.0 */
    private const string POLICY_PROFILE_CHECKSUM = '4111a514bab062215a032df003a3edd940f8b2648c8c20030567b6e46c1c220b';

    /** @var array<string, string> Definition handles keyed by stable definition UUID. @since 2.0.0 */
    private const array DEFINITIONS = [
        '019bc200-0000-7000-8000-000000000001' => 'kumwe.asset-inspection-example.location',
        '019bc200-0000-7000-8000-000000000002' => 'kumwe.asset-inspection-example.asset',
        '019bc200-0000-7000-8000-000000000003' => 'kumwe.asset-inspection-example.inspection',
        '019bc200-0000-7000-8000-000000000004' => 'kumwe.asset-inspection-example.finding',
        '019bc200-0000-7000-8000-000000000005' => 'kumwe.asset-inspection-example.measurement',
    ];

    /** @var array<string, string> Public record IDs keyed by a short acceptance label. @since 2.0.0 */
    private const array RECORDS = [
        'location' => '019bc210-0000-7000-8000-000000000001',
        'asset' => '019bc210-0000-7000-8000-000000000002',
        'inspection' => '019bc210-0000-7000-8000-000000000003',
        'finding_one' => '019bc210-0000-7000-8000-000000000004',
        'finding_two' => '019bc210-0000-7000-8000-000000000005',
        'measurement_one' => '019bc210-0000-7000-8000-000000000006',
        'measurement_two' => '019bc210-0000-7000-8000-000000000007',
        'inspection_denied' => '019bc210-0000-7000-8000-000000000008',
    ];

    /**
     * Dispatch one acceptance mode and reduce every failure to a stable non-zero process result.
     *
     * @param   list<string>  $arguments  Script name followed by the selected mode and its paths.
     *
     * @return  int  Zero after a proved phase, one after any refused or inconsistent phase.
     *
     * @since   2.0.0
     */
    public static function main(array $arguments): int
    {
        try {
            $mode = $arguments[1] ?? '';
            if ($mode === 'snapshot' && count($arguments) === 3) {
                fwrite(STDOUT, CanonicalDefinitionJson::encode(self::manifest(self::state($arguments[2]))) . "\n");

                return 0;
            }
            if ($mode === 'verify' && count($arguments) === 4) {
                self::verify($arguments[2], self::state($arguments[3]));

                return 0;
            }
            if ($mode === 'replay' && count($arguments) === 2) {
                self::replay();

                return 0;
            }
            if ($mode === 'lifecycle' && count($arguments) === 3 && $arguments[2] === 'disabled') {
                self::disabled();

                return 0;
            }
            if ($mode === 'generate-keypair' && count($arguments) === 4) {
                self::generateKeypair($arguments[2], $arguments[3]);

                return 0;
            }
            if ($mode === 'apply-policy' && count($arguments) === 2) {
                self::applyPolicy();

                return 0;
            }
            if ($mode === 'apply-seed-policy' && count($arguments) === 2) {
                self::applySeedPolicy();

                return 0;
            }

            throw new RuntimeException(
                'Usage: asset-inspection-deployment-acceptance.php '
                . 'snapshot STATE|verify MANIFEST STATE|replay|lifecycle disabled|'
                . 'generate-keypair SECRET PUBLIC|apply-policy|apply-seed-policy',
            );
        } catch (Throwable $failure) {
            fwrite(STDERR, 'Asset-inspection deployment acceptance failed: ' . $failure->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * Apply the signed row/field profile through a real administrator session and one-time MFA proofs.
     *
     * The policy operator intentionally holds Business Security administration without any business-record
     * operation. That separation is what lets the production self-escalation guard accept allow policies.
     * Enrollment, a live TOTP challenge, recovery challenges, session rotation, proof persistence/consumption,
     * typed policy construction, transactionality, and audit all use the production services.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function applyPolicy(): void
    {
        [$container, $administratorContext] = self::boot();
        $identities = self::service($container, AdministratorIdentityGateway::class);
        $access = self::service($container, AccessControlService::class);
        $sessions = self::service($container, AdministratorSessionStore::class);
        $stepUp = self::service($container, AdministratorStepUpProvider::class);
        $proofs = self::service($container, AuthorizationStepUpProofAdapter::class);
        $security = self::service($container, BusinessSecurityAdministrationService::class);
        if (
            !$identities instanceof AdministratorIdentityGateway
            || !$access instanceof AccessControlService
            || !$sessions instanceof AdministratorSessionStore
            || !$stepUp instanceof AdministratorStepUpProvider
            || !$proofs instanceof AuthorizationStepUpProofAdapter
            || !$security instanceof BusinessSecurityAdministrationService
        ) {
            throw new RuntimeException('The production policy-administration services are unavailable.');
        }

        $email = self::environment('KUMWE_ACCEPTANCE_POLICY_EMAIL');
        $password = self::environment('KUMWE_ACCEPTANCE_POLICY_PASSWORD');
        $operator = $access->createUser(
            $administratorContext,
            $email,
            'Asset inspection policy operator',
            $password,
            UserStatus::Active,
        );
        $role = $access->createRole(
            $administratorContext,
            'asset-inspection-policy-operator',
            'Asset inspection policy operator',
        );
        $access->grant($administratorContext, $role, 'administrator.access');
        $access->grant($administratorContext, $role, 'business.security.manage');
        $access->grant($administratorContext, $role, 'business.step_up.manage');
        $access->assignRole($administratorContext, $operator, $role);
        $principal = $identities->authenticate($email, $password, 'asset-inspection-policy-acceptance');
        if ($principal === null) {
            throw new RuntimeException('The separated policy operator could not be authenticated.');
        }
        $site = SiteContext::default();
        $createdSession = $sessions->create($principal->context(
            $site,
            AuthenticationStrength::Password,
            'asset-inspection-policy-login',
            surface: AuthenticatedSurface::Administrator,
        ), 'kumwe-asset-inspection-policy-acceptance/2.0');
        $profileRequests = self::policyProfileRequests();
        $seedRequests = self::seedPolicyRequests($container);
        if (count($profileRequests) !== 4) {
            throw new RuntimeException('The signed policy profile did not produce four closed requests.');
        }
        if (count($seedRequests) !== 14) {
            throw new RuntimeException('The acceptance seed policy set is incomplete.');
        }
        $requests = [...$profileRequests, ...array_slice($seedRequests, 0, 6)];

        $organization = self::environment('KUMWE_ACCEPTANCE_ORGANIZATION');
        $setup = $stepUp->beginEnrollment($principal->subject(), 'Kumwe', $email);
        $currentCounter = intdiv(time(), 30);
        $completion = $stepUp->confirmEnrollment(
            self::stepUpIntent(
                $principal,
                $createdSession->session->id,
                BusinessSecurityAdministrationService::stepUpPurpose('organization.create'),
            ),
            $setup->enrollmentId,
            self::totpCode($setup->secret, $currentCounter),
            'asset-inspection-policy-acceptance',
        );
        $verification = $completion->verification;
        $organizationId = $security->createOrganization(
            $principal->context(
                $site,
                AuthenticationStrength::MultiFactor,
                'asset-inspection-organization',
                surface: AuthenticatedSurface::Administrator,
                sessionId: $verification->rotatedSession->sessionId,
                stepUpProof: $proofs->adapt($verification),
            ),
            $organization,
            'Asset inspection acceptance',
        );

        $challengeCounter = max(intdiv(time(), 30), $currentCounter + 1);
        $verification = $stepUp->challenge(
            self::stepUpIntent(
                $principal,
                $verification->rotatedSession->sessionId,
                BusinessSecurityAdministrationService::stepUpPurpose('membership.create'),
            ),
            self::totpCode($setup->secret, $challengeCounter),
            'asset-inspection-policy-acceptance',
        );
        $membershipId = $security->createMembership(
            $principal->context(
                $site,
                AuthenticationStrength::MultiFactor,
                'asset-inspection-membership',
                surface: AuthenticatedSurface::Administrator,
                sessionId: $verification->rotatedSession->sessionId,
                stepUpProof: $proofs->adapt($verification),
            ),
            $organizationId,
            $administratorContext->actorId(),
            new DateTimeImmutable('-1 minute'),
            null,
        );

        if (count($completion->recoveryCodes) < count($requests)) {
            throw new RuntimeException('Policy acceptance did not receive enough recovery challenges.');
        }
        $policyIds = [];
        foreach ($requests as $offset => $request) {
            $verification = $stepUp->recover(
                self::stepUpIntent(
                    $principal,
                    $verification->rotatedSession->sessionId,
                    BusinessSecurityAdministrationService::stepUpPurpose('resource_policy.create'),
                ),
                $completion->recoveryCodes[$offset],
                'asset-inspection-policy-acceptance',
            );
            $policyIds[] = self::createPolicy($security, $proofs, $principal, $verification, $request);
        }

        if (count(array_unique($policyIds)) !== 10) {
            throw new RuntimeException('Primary policy administration did not create ten distinct rows.');
        }
        fwrite(STDOUT, CanonicalDefinitionJson::encode([
            'organization' => [
                'id' => $organizationId,
                'identifier' => $organization,
                'membership_id' => $membershipId,
            ],
            'profile_checksum' => self::POLICY_PROFILE_CHECKSUM,
            'profile_policy_ids' => array_slice($policyIds, 0, 4),
            'seed_policy_ids' => array_slice($policyIds, 4),
            'policy_ids' => $policyIds,
            'proofs' => ['enrollment' => 1, 'totp' => 1, 'recovery' => 10],
        ]) . "\n");
    }

    /**
     * Apply the remaining site-scoped seed policies through a second separated MFA operator.
     *
     * The primary operator spends all ten recovery codes on the four signed viewer policies and the first
     * six seed policies. A distinct principal with no business-record capability supplies the eight remaining
     * one-time proofs, preserving the production self-escalation boundary instead of bypassing it for fixtures.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function applySeedPolicy(): void
    {
        [$container, $administratorContext] = self::boot();
        $identities = self::service($container, AdministratorIdentityGateway::class);
        $access = self::service($container, AccessControlService::class);
        $sessions = self::service($container, AdministratorSessionStore::class);
        $stepUp = self::service($container, AdministratorStepUpProvider::class);
        $proofs = self::service($container, AuthorizationStepUpProofAdapter::class);
        $security = self::service($container, BusinessSecurityAdministrationService::class);
        if (
            !$identities instanceof AdministratorIdentityGateway
            || !$access instanceof AccessControlService
            || !$sessions instanceof AdministratorSessionStore
            || !$stepUp instanceof AdministratorStepUpProvider
            || !$proofs instanceof AuthorizationStepUpProofAdapter
            || !$security instanceof BusinessSecurityAdministrationService
        ) {
            throw new RuntimeException('The production seed-policy services are unavailable.');
        }

        $email = self::environment('KUMWE_ACCEPTANCE_POLICY_EMAIL');
        $password = self::environment('KUMWE_ACCEPTANCE_POLICY_PASSWORD');
        $operator = $access->createUser(
            $administratorContext,
            $email,
            'Asset inspection seed policy operator',
            $password,
            UserStatus::Active,
        );
        $role = $access->createRole(
            $administratorContext,
            'asset-inspection-seed-policy-operator',
            'Asset inspection seed policy operator',
        );
        $access->grant($administratorContext, $role, 'administrator.access');
        $access->grant($administratorContext, $role, 'business.security.manage');
        $access->grant($administratorContext, $role, 'business.step_up.manage');
        $access->assignRole($administratorContext, $operator, $role);
        $principal = $identities->authenticate($email, $password, 'asset-inspection-seed-policy-acceptance');
        if ($principal === null) {
            throw new RuntimeException('The separated seed-policy operator could not be authenticated.');
        }
        $site = SiteContext::default();
        $createdSession = $sessions->create($principal->context(
            $site,
            AuthenticationStrength::Password,
            'asset-inspection-seed-policy-login',
            surface: AuthenticatedSurface::Administrator,
        ), 'kumwe-asset-inspection-seed-policy-acceptance/2.0');
        $requests = array_slice(self::seedPolicyRequests($container), 6);
        if (count($requests) !== 8) {
            throw new RuntimeException('The secondary seed policy set is incomplete.');
        }

        $setup = $stepUp->beginEnrollment($principal->subject(), 'Kumwe', $email);
        $completion = $stepUp->confirmEnrollment(
            self::stepUpIntent(
                $principal,
                $createdSession->session->id,
                BusinessSecurityAdministrationService::stepUpPurpose('resource_policy.create'),
            ),
            $setup->enrollmentId,
            self::totpCode($setup->secret, intdiv(time(), 30)),
            'asset-inspection-seed-policy-acceptance',
        );
        if (count($completion->recoveryCodes) < 7) {
            throw new RuntimeException('Seed policy acceptance did not receive enough recovery challenges.');
        }

        $verification = $completion->verification;
        $policyIds = [];
        foreach ($requests as $offset => $request) {
            if ($offset > 0) {
                $verification = $stepUp->recover(
                    self::stepUpIntent(
                        $principal,
                        $verification->rotatedSession->sessionId,
                        BusinessSecurityAdministrationService::stepUpPurpose('resource_policy.create'),
                    ),
                    $completion->recoveryCodes[$offset - 1],
                    'asset-inspection-seed-policy-acceptance',
                );
            }
            $policyIds[] = self::createPolicy($security, $proofs, $principal, $verification, $request);
        }
        if (count(array_unique($policyIds)) !== 8) {
            throw new RuntimeException('Secondary policy administration did not create eight distinct rows.');
        }
        fwrite(STDOUT, CanonicalDefinitionJson::encode([
            'seed_policy_ids' => $policyIds,
            'policy_ids' => $policyIds,
            'proofs' => ['enrollment' => 1, 'totp' => 0, 'recovery' => 7],
        ]) . "\n");
    }

    /**
     * Read the signed deployment profile as host-owned policy-administration input.
     *
     * The author package does not parse, install or select policy. Deployment acceptance pins the exact
     * canonical profile bytes, validates its closed request shape and submits them through the normal App
     * administration boundary under a separately authenticated operator.
     *
     * @return  list<array<string, mixed>>  Four exact production policy-administration requests.
     *
     * @throws  RuntimeException  When the signed asset is absent, changed or structurally invalid.
     *
     * @since   2.0.0
     */
    private static function policyProfileRequests(): array
    {
        $path = dirname(__DIR__, 2) . '/examples/extensions/asset-inspection/policies/inspection-viewer.json';
        $json = file_get_contents($path);
        if (!is_string($json) || $json === '' || strlen($json) > 65_536) {
            throw new RuntimeException('The signed asset-inspection policy profile is unavailable.');
        }
        try {
            $profile = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new RuntimeException('The signed asset-inspection policy profile is invalid JSON.', 0, $failure);
        }
        if (
            !is_array($profile)
            || array_is_list($profile)
            || CanonicalDefinitionJson::checksum($profile) !== self::POLICY_PROFILE_CHECKSUM
            || ($profile['format'] ?? null) !== 'kumwe-asset-inspection-policy-profile-v1'
            || ($profile['definition_id'] ?? null) !== self::INSPECTION_DEFINITION_ID
        ) {
            throw new RuntimeException('The signed asset-inspection policy profile identity changed.');
        }
        $fieldRules = $profile['field_policy'] ?? null;
        $requests = $profile['policy_requests'] ?? null;
        if (
            !is_array($fieldRules)
            || array_is_list($fieldRules)
            || !is_array($requests)
            || !array_is_list($requests)
            || count($requests) !== 4
        ) {
            throw new RuntimeException('The signed asset-inspection policy request set is malformed.');
        }

        $result = [];
        $operations = [
            'business.record.browse',
            'business.record.export',
            'business.record.read',
            'business.record.report',
        ];
        foreach ($requests as $offset => $request) {
            if (
                !is_array($request)
                || array_is_list($request)
                || !is_string($request['policy_code'] ?? null)
                || ($request['operation'] ?? null) !== $operations[$offset]
                || ($request['effect'] ?? null) !== 'allow'
                || ($request['predicate_type'] ?? null) !== 'comparison'
                || ($request['field'] ?? null) !== 'risk_score'
                || ($request['operator'] ?? null) !== 'greater_than_or_equal'
                || ($request['value_type'] ?? null) !== 'integer'
                || ($request['value'] ?? null) !== '70'
                || ($request['priority'] ?? null) !== 100
            ) {
                throw new RuntimeException('A signed asset-inspection policy request changed its closed contract.');
            }
            $result[] = [
                'policyCode' => $request['policy_code'],
                'operation' => $request['operation'],
                'effect' => $request['effect'],
                'organizationId' => null,
                'definitionId' => self::INSPECTION_DEFINITION_ID,
                'predicateType' => $request['predicate_type'],
                'field' => $request['field'],
                'operator' => $request['operator'],
                'valueType' => $request['value_type'],
                'value' => $request['value'],
                'fieldRules' => $fieldRules + ['actions' => []],
                'priority' => $request['priority'],
            ];
        }

        return $result;
    }

    /**
     * Build the exact operator-owned policies needed to seed and verify the neutral example graph.
     *
     * Each row binds one operation to one definition. Create fields follow immutable definition ceilings;
     * relation rows disclose a UUID only when the definition is a graph target; read rows expose only fields
     * used by the restored graph. Inspection reads remain in the signed profile and its relate row repeats the
     * same risk threshold.
     *
     * @param   Container  $container  Live container whose registered example definitions bound each policy.
     *
     * @return  list<array<string, mixed>>  Fourteen closed production administration requests.
     *
     * @since   2.0.0
     */
    private static function seedPolicyRequests(Container $container): array
    {
        $requests = [];
        $seen = [];
        $registries = self::service($container, ExtensionContributionRegistrySet::class);
        if (!$registries instanceof ExtensionContributionRegistrySet) {
            throw new RuntimeException('The active extension contribution registry is unavailable.');
        }
        $definitions = $registries->businessDefinitions()->ownedBy(DefinitionOwner::extension(self::OWNER));
        foreach ($definitions as $definition) {
            if ((self::DEFINITIONS[$definition->id] ?? null) !== $definition->handle) {
                throw new RuntimeException('A seed policy definition is outside the signed example graph.');
            }
            $prefix = 'kumwe.asset-inspection-example.';
            if (!str_starts_with($definition->handle, $prefix)) {
                throw new RuntimeException('A seed policy definition handle is malformed.');
            }
            $short = substr($definition->handle, strlen($prefix));
            $createFields = [];
            $readFields = [];
            $identityAvailable = false;
            foreach ($definition->fields() as $field) {
                if (
                    $field->createVisible
                    && !$field->serverOnly
                    && !$field->computed
                    && $field->formula === null
                ) {
                    $createFields[] = $field->handle;
                }
                if (
                    $field->readVisible
                    && !in_array($field->sensitivity->value, ['restricted', 'secret'], true)
                ) {
                    $readFields[] = $field->handle;
                }
                if ($field->handle === 'id' && $field->type === 'core.uuid') {
                    $identityAvailable = true;
                }
            }
            if ($short === '' || $createFields === [] || $readFields === [] || !$identityAvailable) {
                throw new RuntimeException('A seed policy definition has no safe field contract.');
            }
            $expectedCreate = match ($short) {
                'location' => ['id', 'name', 'zone'],
                'asset' => ['id', 'asset_tag', 'name', 'active'],
                'inspection' => ['id', 'reference', 'inspection_date', 'raw_score', 'adjustment', 'internal_note'],
                'finding' => ['id', 'summary', 'severity', 'remediation'],
                'measurement' => ['id', 'metric', 'value', 'unit', 'acceptable'],
                default => throw new RuntimeException('A seed policy definition handle is unknown.'),
            };
            $expectedRead = match ($short) {
                'location' => ['id', 'name', 'zone'],
                'asset' => ['id', 'asset_tag', 'name', 'active'],
                'inspection' => ['id', 'reference', 'inspection_date', 'raw_score', 'adjustment', 'risk_score'],
                'finding' => ['id', 'summary', 'severity', 'remediation'],
                'measurement' => ['id', 'metric', 'value', 'unit', 'acceptable'],
                default => throw new RuntimeException('A seed policy definition handle is unknown.'),
            };
            if ($createFields !== $expectedCreate || $readFields !== $expectedRead) {
                throw new RuntimeException('A seed policy definition changed its immutable field ceilings.');
            }
            $seen[$definition->id] = true;
            $requests[] = self::seedPolicyRequest(
                $short . '.create',
                'business.record.create',
                $definition->id,
                ['create' => $createFields, 'actions' => []],
                false,
            );
            $requests[] = self::seedPolicyRequest(
                $short . '.relate',
                'business.record.relate',
                $definition->id,
                $short === 'location' ? ['actions' => []] : ['public_reference' => ['id'], 'actions' => []],
                $definition->id === self::INSPECTION_DEFINITION_ID,
            );
            if ($definition->id !== self::INSPECTION_DEFINITION_ID) {
                $readRules = ['detail' => $readFields, 'actions' => []];
                if ($short !== 'location') {
                    $readRules['include'] = $readFields;
                    $readRules['public_reference'] = ['id'];
                }
                $requests[] = self::seedPolicyRequest(
                    $short . '.read',
                    'business.record.read',
                    $definition->id,
                    $readRules,
                    false,
                );
            }
        }
        $seenIds = array_keys($seen);
        $expectedIds = array_keys(self::DEFINITIONS);
        sort($seenIds, SORT_STRING);
        sort($expectedIds, SORT_STRING);
        if ($seenIds !== $expectedIds || count($requests) !== 14) {
            throw new RuntimeException('The seed policy graph is incomplete.');
        }

        return $requests;
    }

    /**
     * Construct one exact allow request for the guarded Business Security service.
     *
     * @param   string                       $suffix               Unique policy-code suffix.
     * @param   string                       $operation            Exact record capability.
     * @param   string                       $definitionId         Stable definition UUID.
     * @param   array<string, list<string>>  $fieldRules           Explicit per-usage field limits.
     * @param   bool                         $inspectionThreshold  Whether to require risk score at least 70.
     *
     * @return  array<string, mixed>  Closed argument map accepted by `createResourcePolicy()`.
     *
     * @since   2.0.0
     */
    private static function seedPolicyRequest(
        string $suffix,
        string $operation,
        string $definitionId,
        array $fieldRules,
        bool $inspectionThreshold,
    ): array {
        return [
            'policyCode' => 'asset-inspection-acceptance.' . $suffix,
            'operation' => $operation,
            'effect' => 'allow',
            'organizationId' => null,
            'definitionId' => $definitionId,
            'predicateType' => $inspectionThreshold ? 'comparison' : 'constant',
            'field' => $inspectionThreshold ? 'risk_score' : null,
            'operator' => $inspectionThreshold ? 'greater_than_or_equal' : null,
            'valueType' => $inspectionThreshold ? 'integer' : null,
            'value' => $inspectionThreshold ? '70' : 'true',
            'fieldRules' => $fieldRules,
            'priority' => 100,
        ];
    }

    /**
     * Build the server-resolved intent for one rotating administrator MFA proof.
     *
     * @param   \Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal  $principal  Policy actor.
     * @param string $sessionId Current persisted administrator session UUID.
     * @param string $purpose Exact protected Business Security operation.
     *
     * @return  StepUpIntent  Context and epoch bound to the current session.
     *
     * @since   2.0.0
     */
    private static function stepUpIntent(object $principal, string $sessionId, string $purpose): StepUpIntent
    {
        if (!$principal instanceof \Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal) {
            throw new RuntimeException('The policy principal is unavailable.');
        }

        return new StepUpIntent(
            $principal->subject(),
            $sessionId,
            SiteContext::DEFAULT,
            null,
            null,
            $purpose,
            $principal->securityEpoch(),
        );
    }

    /**
     * Consume one fresh production proof while creating an exact profile request.
     *
     * @param BusinessSecurityAdministrationService $security Guarded policy service.
     * @param AuthorizationStepUpProofAdapter $proofs Provider-to-authorization adapter.
     * @param   \Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal  $principal     Policy actor.
     * @param \Kumwe\App\Identity\Domain\StepUp\StepUpVerification $verification Fresh rotated verification.
     * @param array<string, mixed> $request Closed signed request.
     *
     * @return  string  Persisted policy UUID.
     *
     * @since   2.0.0
     */
    private static function createPolicy(
        BusinessSecurityAdministrationService $security,
        AuthorizationStepUpProofAdapter $proofs,
        object $principal,
        object $verification,
        array $request,
    ): string {
        if (
            !$principal instanceof \Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal
            || !$verification instanceof \Kumwe\App\Identity\Domain\StepUp\StepUpVerification
        ) {
            throw new RuntimeException('A typed policy proof is unavailable.');
        }
        $context = $principal->context(
            SiteContext::default(),
            AuthenticationStrength::MultiFactor,
            'asset-inspection-policy-' . bin2hex(random_bytes(8)),
            surface: AuthenticatedSurface::Administrator,
            sessionId: $verification->rotatedSession->sessionId,
            stepUpProof: $proofs->adapt($verification),
        );
        $expectedKeys = [
            'policyCode',
            'operation',
            'effect',
            'organizationId',
            'definitionId',
            'predicateType',
            'field',
            'operator',
            'valueType',
            'value',
            'fieldRules',
            'priority',
        ];
        if (array_keys($request) !== $expectedKeys || !is_array($request['fieldRules'] ?? null)) {
            throw new RuntimeException('The policy field rules are malformed.');
        }
        foreach (['policyCode', 'operation', 'effect', 'definitionId', 'predicateType', 'value'] as $key) {
            if (!is_string($request[$key] ?? null) || $request[$key] === '') {
                throw new RuntimeException('A signed policy request string is malformed.');
            }
        }
        if ($request['predicateType'] === 'constant') {
            if (
                $request['field'] !== null
                || $request['operator'] !== null
                || $request['valueType'] !== null
                || $request['value'] !== 'true'
            ) {
                throw new RuntimeException('A constant policy predicate is malformed.');
            }
        } elseif ($request['predicateType'] === 'comparison') {
            foreach (['field', 'operator', 'valueType'] as $key) {
                if (!is_string($request[$key] ?? null) || $request[$key] === '') {
                    throw new RuntimeException('A comparison policy predicate is malformed.');
                }
            }
        } else {
            throw new RuntimeException('A policy predicate discriminator is unavailable.');
        }
        if ($request['organizationId'] !== null || !is_int($request['priority'] ?? null)) {
            throw new RuntimeException('The policy scope or priority is malformed.');
        }
        foreach ($request['fieldRules'] as $usage => $fields) {
            if (!is_string($usage) || !is_array($fields) || !array_is_list($fields)) {
                throw new RuntimeException('A policy field-disclosure entry is malformed.');
            }
            foreach ($fields as $field) {
                if (!is_string($field) || $field === '') {
                    throw new RuntimeException('A policy field-disclosure handle is malformed.');
                }
            }
        }

        return $security->createResourcePolicy($context, ...$request);
    }

    /**
     * Calculate the six-digit RFC 6238 value for a Base32 enrollment secret and exact counter.
     *
     * @param   string  $base32   Unpadded enrollment secret returned once by the provider.
     * @param   int     $counter  Non-negative thirty-second moving factor.
     *
     * @return  string  Zero-padded six-digit code.
     *
     * @since   2.0.0
     */
    private static function totpCode(string $base32, int $counter): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = 0;
        $bits = 0;
        $secret = '';
        foreach (str_split(strtoupper($base32)) as $character) {
            $value = strpos($alphabet, $character);
            if ($value === false) {
                throw new RuntimeException('The enrollment secret is not canonical Base32.');
            }
            $buffer = ($buffer << 5) | $value;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $secret .= chr(($buffer >> $bits) & 255);
                $buffer &= (1 << $bits) - 1;
            }
        }
        if ($secret === '' || $counter < 0) {
            throw new RuntimeException('The enrollment secret or counter is invalid.');
        }
        $digest = hash_hmac(
            'sha1',
            pack('N2', intdiv($counter, 4_294_967_296), $counter % 4_294_967_296),
            $secret,
            true,
        );
        sodium_memzero($secret);
        $offset = ord($digest[strlen($digest) - 1]) & 15;
        $binary = ((ord($digest[$offset]) & 127) << 24)
            | ((ord($digest[$offset + 1]) & 255) << 16)
            | ((ord($digest[$offset + 2]) & 255) << 8)
            | (ord($digest[$offset + 3]) & 255);

        return str_pad((string) ($binary % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Generate one protected Ed25519 release seed and its base64 public key for the ephemeral CI trust store.
     *
     * @param   string  $secretPath  New canonical path receiving the base64 signing seed at mode 0600.
     * @param   string  $publicPath  New canonical path receiving the base64 public key at mode 0600.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function generateKeypair(string $secretPath, string $publicPath): void
    {
        foreach ([$secretPath, $publicPath] as $path) {
            if (!str_starts_with($path, '/') || file_exists($path) || is_link($path)) {
                throw new RuntimeException('An acceptance key path must be a new absolute path.');
            }
            $parent = realpath(dirname($path));
            if (!is_string($parent) || $path !== $parent . '/' . basename($path) || !is_writable($parent)) {
                throw new RuntimeException('An acceptance key parent is unavailable or non-canonical.');
            }
        }
        $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $pair = sodium_crypto_sign_seed_keypair($seed);
        $public = sodium_crypto_sign_publickey($pair);
        try {
            self::writeProtected($secretPath, base64_encode($seed));
            self::writeProtected($publicPath, base64_encode($public));
        } finally {
            sodium_memzero($seed);
            sodium_memzero($pair);
            sodium_memzero($public);
        }

        fwrite(STDOUT, CanonicalDefinitionJson::encode([
            'secret_key_file' => $secretPath,
            'public_key_file' => $publicPath,
        ]) . "\n");
    }

    /**
     * Publish one owner-readable acceptance secret without following or replacing a path.
     *
     * @param   string  $path      New canonical output path.
     * @param   string  $contents  Bounded key material.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function writeProtected(string $path, string $contents): void
    {
        $handle = fopen($path, 'x+b');
        if (!is_resource($handle)) {
            throw new RuntimeException('An acceptance key file could not be created.');
        }
        try {
            if (
                chmod($path, 0600) !== true || fwrite($handle, $contents) !== strlen($contents)
                || fflush($handle) !== true
            ) {
                throw new RuntimeException('An acceptance key file could not be published safely.');
            }
        } catch (Throwable $failure) {
            fclose($handle);
            unlink($path);
            throw $failure;
        }
        fclose($handle);
    }

    /**
     * Replay a dispatched inspection event and prove the consumer ledger remains one row per event.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function replay(): void
    {
        [$container] = self::boot();
        $outbox = self::service($container, OutboxStore::class);
        $inbox = self::service($container, InboxStore::class);
        $dispatcher = self::service($container, OutboxDispatcher::class);
        $runtime = self::service($container, RuntimeMaterializationState::class);
        if (
            !$outbox instanceof OutboxStore || !$inbox instanceof InboxStore
            || !$dispatcher instanceof OutboxDispatcher || !$runtime instanceof RuntimeMaterializationState
            || !$runtime->trusted || $runtime->generation < 1
        ) {
            throw new RuntimeException('The trusted integration replay services are unavailable.');
        }

        $event = null;
        foreach ($outbox->recent(1_000) as $candidate) {
            if (
                ($candidate['aggregate_type'] ?? null) === '019bc200-0000-7000-8000-000000000003'
                && ($candidate['status'] ?? null) === 'dispatched'
            ) {
                $event = $candidate;
                break;
            }
        }
        $eventId = $event['event_id'] ?? null;
        if (!is_string($eventId) || (int) ($event['replay_count'] ?? -1) !== 0) {
            throw new RuntimeException('A once-dispatched inspection event is unavailable for replay.');
        }
        $before = $inbox->recent(self::CONSUMER, 1_000);
        $outbox->replay($eventId, 'deployment-acceptance');
        if (!$dispatcher->dispatchOne('deployment-acceptance-replay', (string) $runtime->generation, 60)) {
            throw new RuntimeException('The replayed inspection event was not claimable.');
        }
        $after = $inbox->recent(self::CONSUMER, 1_000);
        if (count($before) !== count($after)) {
            throw new RuntimeException('Duplicate delivery created a second consumer effect.');
        }
        $replayed = array_values(array_filter(
            $outbox->recent(1_000),
            static fn (array $row): bool => ($row['event_id'] ?? null) === $eventId,
        ));
        if (
            count($replayed) !== 1
            || ($replayed[0]['status'] ?? null) !== 'dispatched'
            || (int) ($replayed[0]['replay_count'] ?? 0) !== 1
        ) {
            throw new RuntimeException('The outbox replay evidence is inconsistent.');
        }

        fwrite(STDOUT, CanonicalDefinitionJson::encode([
            'event_id' => $eventId,
            'consumer_receipts_before' => count($before),
            'consumer_receipts_after' => count($after),
            'replay_count' => 1,
        ]) . "\n");
    }

    /**
     * Prove disabling withdraws every runtime contribution while retaining all eight generated rows.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function disabled(): void
    {
        [$container] = self::boot();
        $database = self::service($container, Connection::class);
        $tables = self::service($container, TableNames::class);
        $registries = self::service($container, ExtensionContributionRegistrySet::class);
        $installations = self::service($container, BusinessSchemaInstallationRepository::class);
        if (
            !$database instanceof Connection || !$tables instanceof TableNames
            || !$registries instanceof ExtensionContributionRegistrySet
            || !$installations instanceof BusinessSchemaInstallationRepository
        ) {
            throw new RuntimeException('The disabled lifecycle proof services are unavailable.');
        }
        $status = $database->fetchOne(sprintf(
            'SELECT status FROM %s WHERE identifier = ?',
            $tables->quoted('extensions'),
        ), [self::OWNER], [Types::STRING]);
        if ($status !== 'disabled') {
            throw new RuntimeException('The example extension is not disabled.');
        }
        if (self::leafCount($registries->inventory(ContributionOwner::extension(self::OWNER))) !== 0) {
            throw new RuntimeException('A disabled example contribution remains in the trusted runtime.');
        }
        $schedule = self::schedule($database, $tables);
        if ($schedule['active']) {
            throw new RuntimeException('The disabled contributed schedule remains executable.');
        }

        $recordRows = 0;
        $physicalTables = [];
        foreach (array_keys(self::DEFINITIONS) as $definitionId) {
            $installation = $installations->find($definitionId);
            if ($installation === null || $installation->status !== SchemaInstallationStatus::Disabled) {
                throw new RuntimeException('A disabled example schema was not preserved as disabled.');
            }
            foreach ($installation->blueprint->tables() as $table) {
                if ($table->kind !== PhysicalTableKind::Entity || isset($physicalTables[$table->physicalName])) {
                    continue;
                }
                $physicalTables[$table->physicalName] = true;
                $recordRows += (int) $database->fetchOne(sprintf(
                    'SELECT COUNT(*) FROM %s',
                    // Generated blueprint names already carry the configured prefix and portable validation.
                    $database->quoteSingleIdentifier($table->physicalName),
                ));
            }
        }
        if ($recordRows !== 8) {
            throw new RuntimeException('Disabling did not preserve the eight example records.');
        }

        fwrite(STDOUT, CanonicalDefinitionJson::encode([
            'status' => 'disabled',
            'runtime_contributions' => 0,
            'preserved_records' => $recordRows,
        ]) . "\n");
    }

    /**
     * Compare restored state with the canonical source manifest and report both digests.
     *
     * @param   string                $manifestPath  Source manifest emitted immediately before backup.
     * @param   array<string, mixed>  $state         Public acceptance identities and export evidence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function verify(string $manifestPath, array $state): void
    {
        $expected = self::document($manifestPath, self::FORMAT);
        $actual = self::manifest($state);
        $expectedChecksum = CanonicalDefinitionJson::checksum($expected);
        $actualChecksum = CanonicalDefinitionJson::checksum($actual);
        if (!hash_equals($expectedChecksum, $actualChecksum)) {
            throw new RuntimeException(sprintf(
                'The restored asset-inspection state differs from its source manifest (%s != %s).',
                $expectedChecksum,
                $actualChecksum,
            ));
        }

        fwrite(STDOUT, CanonicalDefinitionJson::encode([
            'format' => self::FORMAT,
            'source_manifest_checksum' => $expectedChecksum,
            'restored_manifest_checksum' => $actualChecksum,
            'records' => count(self::RECORDS),
            'audit_verified' => true,
            'export_bytes_verified' => true,
        ]) . "\n");
    }

    /**
     * Assemble all durable and runtime evidence that must survive a clean-target restore unchanged.
     *
     * @param   array<string, mixed>  $state  Public package and export identities captured by the workflow.
     *
     * @return  array<string, mixed>  Canonical restore-comparison document.
     *
     * @since   2.0.0
     */
    private static function manifest(array $state): array
    {
        [$container, $context] = self::boot();
        $database = self::service($container, Connection::class);
        $tables = self::service($container, TableNames::class);
        $records = self::service($container, BusinessRecordService::class);
        $projector = self::service($container, BusinessRecordProjector::class);
        $registries = self::service($container, ExtensionContributionRegistrySet::class);
        $installations = self::service($container, BusinessSchemaInstallationRepository::class);
        $runtime = self::service($container, RuntimeMaterializationState::class);
        $outbox = self::service($container, OutboxStore::class);
        $inbox = self::service($container, InboxStore::class);
        $exports = self::service($container, ExportArtifactRepository::class);
        $storage = self::service($container, ExportArtifactStorage::class);
        $projections = self::service($container, ProjectionRuntime::class);
        if (
            !$database instanceof Connection || !$tables instanceof TableNames
            || !$records instanceof BusinessRecordService || !$projector instanceof BusinessRecordProjector
            || !$registries instanceof ExtensionContributionRegistrySet
            || !$installations instanceof BusinessSchemaInstallationRepository
            || !$runtime instanceof RuntimeMaterializationState || !$outbox instanceof OutboxStore
            || !$inbox instanceof InboxStore || !$exports instanceof ExportArtifactRepository
            || !$storage instanceof ExportArtifactStorage || !$projections instanceof ProjectionRuntime
        ) {
            throw new RuntimeException('The asset-inspection verification services are unavailable.');
        }
        if (!$runtime->trusted || $runtime->generation < 1) {
            throw new RuntimeException('The asset-inspection runtime generation is not trusted.');
        }

        $packageSha = self::requiredState($state, 'package_sha256', '/^[0-9a-f]{64}$/D');
        $artifactId = self::requiredState(
            $state,
            'export_artifact_id',
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di',
        );
        $release = $database->fetchAssociative(sprintf(
            'SELECT e.identifier, e.installed_version, e.status, r.package_sha256, r.signing_key_id '
            . 'FROM %s e INNER JOIN %s r ON r.extension_id = e.id AND r.version = e.installed_version '
            . 'WHERE e.identifier = ?',
            $tables->quoted('extensions'),
            $tables->quoted('extension_releases'),
        ), [self::OWNER], [Types::STRING]);
        if (
            $release === false
            || ($release['status'] ?? null) !== 'active'
            || !is_string($release['package_sha256'] ?? null)
            || !hash_equals($packageSha, $release['package_sha256'])
            || ($release['signing_key_id'] ?? null) !== 'acceptance.asset-inspection.v1'
        ) {
            throw new RuntimeException('The active signed example release evidence is inconsistent.');
        }

        $schemas = [];
        foreach (array_keys(self::DEFINITIONS) as $definitionId) {
            $installation = $installations->find($definitionId);
            if ($installation === null || $installation->status !== SchemaInstallationStatus::Active) {
                throw new RuntimeException('An example schema installation is unavailable or inactive.');
            }
            $schemas[$definitionId] = $installation->toArray();
        }

        $projected = [
            'location' => self::record(
                $records,
                $projector,
                $context,
                self::DEFINITIONS['019bc200-0000-7000-8000-000000000001'],
                self::RECORDS['location'],
                ['assets'],
            ),
            'asset' => self::record(
                $records,
                $projector,
                $context,
                self::DEFINITIONS['019bc200-0000-7000-8000-000000000002'],
                self::RECORDS['asset'],
                ['inspections'],
            ),
            'inspection' => self::record(
                $records,
                $projector,
                $context,
                self::DEFINITIONS['019bc200-0000-7000-8000-000000000003'],
                self::RECORDS['inspection'],
                ['findings', 'measurements'],
            ),
            'finding_one' => self::record(
                $records,
                $projector,
                $context,
                self::DEFINITIONS['019bc200-0000-7000-8000-000000000004'],
                self::RECORDS['finding_one'],
            ),
            'finding_two' => self::record(
                $records,
                $projector,
                $context,
                self::DEFINITIONS['019bc200-0000-7000-8000-000000000004'],
                self::RECORDS['finding_two'],
            ),
            'measurement_one' => self::record(
                $records,
                $projector,
                $context,
                self::DEFINITIONS['019bc200-0000-7000-8000-000000000005'],
                self::RECORDS['measurement_one'],
            ),
            'measurement_two' => self::record(
                $records,
                $projector,
                $context,
                self::DEFINITIONS['019bc200-0000-7000-8000-000000000005'],
                self::RECORDS['measurement_two'],
            ),
        ];
        self::assertRecords($projected);
        self::assertDeniedRecord($records, $projector, $context);

        $artifact = $exports->find($artifactId);
        if (
            $artifact === null
            || $artifact->reportIdentifier !== self::REPORT
            || $artifact->status !== ExportArtifactStatus::Completed
            || $artifact->storageKey === null
            || $artifact->size === null
            || $artifact->checksum === null
        ) {
            throw new RuntimeException('The completed example export artifact is unavailable.');
        }
        $stream = $storage->open(new StoredExportArtifact(
            $artifact->storageKey,
            $artifact->size,
            $artifact->checksum,
        ));
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            $storedChecksum = hash_final($hash);
        } finally {
            fclose($stream);
        }
        if (!hash_equals($artifact->checksum, $storedChecksum)) {
            throw new RuntimeException('The private restored export bytes failed checksum verification.');
        }

        $outboxRows = array_values(array_filter(
            $outbox->recent(1_000),
            static fn (array $row): bool => in_array(
                $row['aggregate_type'] ?? null,
                array_keys(self::DEFINITIONS),
                true,
            ),
        ));
        $inboxRows = $inbox->recent(self::CONSUMER, 1_000);
        if ($outboxRows === [] || $inboxRows === []) {
            throw new RuntimeException('The durable example outbox or inbox evidence is absent.');
        }
        foreach ($outboxRows as $row) {
            if (($row['status'] ?? null) !== 'dispatched') {
                throw new RuntimeException('An example outbox event is not terminally dispatched.');
            }
        }
        foreach ($inboxRows as $row) {
            if (($row['status'] ?? null) !== 'completed') {
                throw new RuntimeException('An example consumer receipt is not terminally completed.');
            }
        }

        $audit = self::audit($database, $tables, $artifactId);
        if (count($audit) < 12) {
            throw new RuntimeException('The example audit trail does not cover its lifecycle and operations.');
        }
        $schedule = self::schedule($database, $tables);
        if (
            !$schedule['active'] || !$schedule['enabled']
            || $schedule['generation'] !== (string) $runtime->generation
        ) {
            throw new RuntimeException('The contributed schedule is not active on the trusted generation.');
        }
        $projection = self::projection($projections, $state);

        return [
            'format' => self::FORMAT,
            'release' => self::strings($release),
            'runtime' => [
                'generation' => $runtime->generation,
                'publication_checksum' => $runtime->publicationChecksum,
                'trusted' => $runtime->trusted,
            ],
            'contributions' => $registries->inventory(ContributionOwner::extension(self::OWNER)),
            'projection' => $projection,
            'schedule' => $schedule,
            'schemas' => $schemas,
            'records' => $projected,
            'policy' => [
                'profile_checksum' => self::POLICY_PROFILE_CHECKSUM,
                'allowed_record' => self::RECORDS['inspection'],
                'denied_record' => self::RECORDS['inspection_denied'],
                'restricted_field_omitted' => true,
            ],
            'outbox' => self::portableRows($outboxRows),
            'inbox' => self::portableRows($inboxRows),
            'export' => [
                'metadata' => $artifact->toArray(),
                'stored_checksum' => $storedChecksum,
            ],
            'audit' => $audit,
        ];
    }

    /**
     * Project a record through the same omission-safe document adapter used by REST, CLI, and MCP.
     *
     * @param   BusinessRecordService    $records     Shared generated-record runtime.
     * @param   BusinessRecordProjector  $projector   Delivery-neutral safe projector.
     * @param   ExecutionContext         $context     Authenticated acceptance administrator.
     * @param   string                   $definition  Namespaced definition handle.
     * @param   string                   $record      Stable public record UUID.
     * @param   list<string>             $includes    Bounded relationships to hydrate in order.
     *
     * @return  array<string, mixed>  Disclosure-safe record document.
     *
     * @since   2.0.0
     */
    private static function record(
        BusinessRecordService $records,
        BusinessRecordProjector $projector,
        ExecutionContext $context,
        string $definition,
        string $record,
        array $includes = [],
    ): array {
        return $projector->record($records->read(new ReadRecordQuery(
            $context,
            $definition,
            $record,
            includes: $includes,
        )));
    }

    /**
     * Assert computed, restricted, exact-decimal, and ordered-relation evidence in projected records.
     *
     * @param   array<string, array<string, mixed>>  $records  Projected acceptance record graph.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertRecords(array $records): void
    {
        $inspection = $records['inspection']['values'] ?? null;
        if (
            !is_array($inspection) || ($inspection['risk_score'] ?? null) !== 79
            || array_key_exists('internal_note', $inspection)
        ) {
            throw new RuntimeException('Computed or restricted inspection-field policy evidence is invalid.');
        }
        $findings = $records['inspection']['includes']['findings'] ?? null;
        $measurements = $records['inspection']['includes']['measurements'] ?? null;
        if (
            !is_array($findings) || !is_array($measurements)
            || array_column($findings, 'record_id') !== [self::RECORDS['finding_one'], self::RECORDS['finding_two']]
            || array_column($findings, 'position') !== [0, 1]
            || array_column($measurements, 'record_id') !== [
                self::RECORDS['measurement_one'],
                self::RECORDS['measurement_two'],
            ]
            || array_column($measurements, 'position') !== [0, 1]
            || ($records['measurement_one']['values']['value'] ?? null) !== '12.3456'
            || ($records['measurement_two']['values']['value'] ?? null) !== '9.8765'
        ) {
            throw new RuntimeException('Ordered relation or exact-decimal acceptance evidence is invalid.');
        }
    }

    /**
     * Require the operator-applied risk predicate to hide a persisted below-threshold inspection.
     *
     * @param   BusinessRecordService    $records    Shared generated-record runtime.
     * @param   BusinessRecordProjector  $projector  Delivery-neutral safe projector.
     * @param   ExecutionContext         $context    Authenticated acceptance administrator.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertDeniedRecord(
        BusinessRecordService $records,
        BusinessRecordProjector $projector,
        ExecutionContext $context,
    ): void {
        try {
            self::record(
                $records,
                $projector,
                $context,
                self::DEFINITIONS['019bc200-0000-7000-8000-000000000003'],
                self::RECORDS['inspection_denied'],
            );
        } catch (BusinessRecordNotFound) {
            return;
        }

        throw new RuntimeException('The below-threshold inspection escaped the operator-applied row policy.');
    }

    /**
     * Select only audit rows attributed to the example package, records, report, and export artifact.
     *
     * @param   Connection  $database    Live relational connection.
     * @param   TableNames  $tables      Safe physical table-name compiler.
     * @param   string      $artifactId  Export artifact included in the acceptance graph.
     *
     * @return  list<array<string, mixed>>  Stable audit evidence without database-specific scalar types.
     *
     * @since   2.0.0
     */
    private static function audit(Connection $database, TableNames $tables, string $artifactId): array
    {
        $subjects = [self::OWNER, self::REPORT, self::PROJECTION, $artifactId, ...array_values(self::RECORDS)];
        $rows = $database->fetchAllAssociative(sprintf(
            'SELECT action, subject_type, subject_id, outcome, metadata FROM %s '
            . 'WHERE subject_id IN (?) ORDER BY occurred_at, id',
            $tables->quoted('audit_events'),
        ), [$subjects], [ArrayParameterType::STRING]);

        return self::portableRows($rows);
    }

    /**
     * Read the preserved scheduler row for the signed package contribution.
     *
     * @param   Connection  $database  Live relational connection.
     * @param   TableNames  $tables    Safe physical table-name compiler.
     *
     * @return  array{
     *              id: string,
     *              contribution_id: string,
     *              checksum: string,
     *              generation: string,
     *              active: bool,
     *              enabled: bool,
     *              queue: string,
     *              maximum_attempts: int,
     *              job_type: string,
     *              execution_scope: string
     *          } Stable contribution identity and generation evidence.
     *
     * @since   2.0.0
     */
    private static function schedule(Connection $database, TableNames $tables): array
    {
        $rows = $database->fetchAllAssociative(sprintf(
            'SELECT id, contribution_id, contribution_checksum, contribution_generation, contribution_active, '
            . 'enabled, queue, maximum_attempts, job_type, execution_scope FROM %s WHERE contribution_id = ?',
            $tables->quoted('schedules'),
        ), [self::SCHEDULE], [Types::STRING]);
        if (count($rows) !== 1) {
            throw new RuntimeException('The preserved contributed schedule row is unavailable or duplicated.');
        }
        $row = $rows[0];
        $id = self::requiredRowString($row, 'id');
        $contributionId = self::requiredRowString($row, 'contribution_id');
        $checksum = self::requiredRowString($row, 'contribution_checksum');
        $generation = self::requiredRowString($row, 'contribution_generation');
        $queue = self::requiredRowString($row, 'queue');
        $maximumAttempts = self::requiredRowInteger($row, 'maximum_attempts');
        $jobType = self::requiredRowString($row, 'job_type');
        $executionScope = self::requiredRowString($row, 'execution_scope');
        if (
            $contributionId !== self::SCHEDULE
            || preg_match('/^[0-9a-f]{64}$/D', $checksum) !== 1
            || $queue !== 'kumwe.asset-inspection-example.integration'
            || $maximumAttempts !== 3
            || $jobType !== 'kumwe.asset-inspection-example.review-overdue'
            || $executionScope !== 'site'
        ) {
            throw new RuntimeException('The preserved contributed schedule contract is inconsistent.');
        }

        return [
            'id' => $id,
            'contribution_id' => $contributionId,
            'checksum' => $checksum,
            'generation' => $generation,
            'active' => self::databaseBoolean($row['contribution_active'] ?? null),
            'enabled' => self::databaseBoolean($row['enabled'] ?? null),
            'queue' => $queue,
            'maximum_attempts' => $maximumAttempts,
            'job_type' => $jobType,
            'execution_scope' => $executionScope,
        ];
    }

    /**
     * Read one required non-empty string from a DBAL row.
     *
     * @param   array<string, mixed>  $row  Selected durable row.
     * @param   string                $key  Required column name.
     *
     * @return  string  Validated column value.
     *
     * @since   2.0.0
     */
    private static function requiredRowString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('The preserved contributed schedule row is malformed.');
        }

        return $value;
    }

    /**
     * Read one required positive integer from a driver-neutral DBAL row.
     *
     * @param   array<string, mixed>  $row  Selected durable row.
     * @param   string                $key  Required column name.
     *
     * @return  int  Validated positive integer.
     *
     * @since   2.0.0
     */
    private static function requiredRowInteger(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);
        } else {
            $integer = false;
        }
        if (!is_int($integer) || $integer < 1) {
            throw new RuntimeException('A contributed schedule integer is malformed.');
        }

        return $integer;
    }

    /**
     * Require the active projection generation to match the operator rebuild captured before restart.
     *
     * @param   ProjectionRuntime     $runtime  Trusted production projection runtime.
     * @param   array<string, mixed>  $state    Protected deployment state containing rebuild evidence.
     *
     * @return  array<string, mixed>  Stable definition and active-generation inventory document.
     *
     * @since   2.0.0
     */
    private static function projection(ProjectionRuntime $runtime, array $state): array
    {
        $matches = array_values(array_filter(
            $runtime->inventory(),
            static fn (array $item): bool => ($item['projection_id'] ?? null) === self::PROJECTION,
        ));
        if (count($matches) !== 1) {
            throw new RuntimeException('The active asset-inspection projection generation is unavailable.');
        }
        $projection = $matches[0];
        $active = $projection['active_generation'] ?? null;
        if (!is_array($active)) {
            throw new RuntimeException('The asset-inspection projection has no active durable generation.');
        }
        $expectedGeneration = self::requiredState(
            $state,
            'projection_generation_id',
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di',
        );
        $expectedSource = self::requiredState($state, 'projection_source_checksum', '/^[0-9a-f]{64}$/D');
        $expectedProjection = self::requiredState($state, 'projection_checksum', '/^[0-9a-f]{64}$/D');
        $expectedSequence = self::positiveStateInteger($state, 'projection_last_sequence');
        if (
            ($active['definition_current'] ?? null) !== true
            || ($active['generation_id'] ?? null) !== $expectedGeneration
            || ($active['source_checksum'] ?? null) !== $expectedSource
            || ($active['projection_checksum'] ?? null) !== $expectedProjection
            || ($active['last_sequence'] ?? null) !== $expectedSequence
        ) {
            throw new RuntimeException('The active projection generation contradicts the captured rebuild.');
        }

        return $projection;
    }

    /**
     * Normalize one DBAL boolean without accepting an ambiguous scalar.
     *
     * @param   mixed  $value  Driver-specific boolean selected from a supported database.
     *
     * @return  bool  Canonical boolean value.
     *
     * @since   2.0.0
     */
    private static function databaseBoolean(mixed $value): bool
    {
        if (in_array($value, [true, 1, '1'], true)) {
            return true;
        }
        if (in_array($value, [false, 0, '0'], true)) {
            return false;
        }

        throw new RuntimeException('A contributed schedule boolean is malformed.');
    }

    /**
     * Boot a fresh production container and authenticate the deployed acceptance administrator.
     *
     * @return  array{0: Container, 1: ExecutionContext}  Fresh container and password-authenticated context.
     *
     * @since   2.0.0
     */
    private static function boot(
        string $emailVariable = 'KUMWE_ACCEPTANCE_ADMIN_EMAIL',
        string $passwordVariable = 'KUMWE_ACCEPTANCE_ADMIN_PASSWORD',
    ): array {
        $container = require dirname(__DIR__, 2) . '/bootstrap/container.php';
        if (!$container instanceof Container) {
            throw new RuntimeException('The production service container is unavailable.');
        }
        $email = self::environment($emailVariable);
        $password = self::environment($passwordVariable);
        if ($email === '' || $password === '') {
            throw new RuntimeException('The acceptance administrator credentials are unavailable.');
        }
        $identities = self::service($container, AdministratorIdentityGateway::class);
        if (!$identities instanceof AdministratorIdentityGateway) {
            throw new RuntimeException('The administrator identity gateway is unavailable.');
        }
        $principal = $identities->authenticate($email, $password, 'asset-inspection-deployment-acceptance');
        if ($principal === null) {
            throw new RuntimeException('The acceptance administrator could not be authenticated.');
        }

        return [$container, $principal->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'asset-inspection-acceptance-' . bin2hex(random_bytes(16)),
        )];
    }

    /**
     * Read one required non-empty environment value without coercion.
     *
     * @param   string  $name  Exact variable name selected by the acceptance phase.
     *
     * @return  string  Non-empty configured value.
     *
     * @since   2.0.0
     */
    private static function environment(string $name): string
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('A required acceptance environment value is unavailable.');
        }

        return $value;
    }

    /**
     * Resolve a container service while keeping the caller responsible for its exact contract.
     *
     * @param   Container     $container  Production service container.
     * @param   class-string  $service    Service identifier.
     *
     * @return  mixed  Resolved service.
     *
     * @since   2.0.0
     */
    private static function service(Container $container, string $service): mixed
    {
        return $container->get($service);
    }

    /**
     * Read and validate the workflow state document.
     *
     * @param   string  $path  Protected state-file path.
     *
     * @return  array<string, mixed>  Closed acceptance state object.
     *
     * @since   2.0.0
     */
    private static function state(string $path): array
    {
        $state = self::document($path, 'kumwe-asset-inspection-state-v1');
        $keys = array_keys($state);
        sort($keys, SORT_STRING);
        if (
            $keys !== [
            'export_artifact_id',
            'format',
            'package_sha256',
            'projection_checksum',
            'projection_generation_id',
            'projection_last_sequence',
            'projection_source_checksum',
            ]
        ) {
            throw new RuntimeException('The asset-inspection state document has unknown or missing keys.');
        }

        return $state;
    }

    /**
     * Decode a bounded JSON object and require its exact format marker.
     *
     * @param   string  $path    Input JSON path.
     * @param   string  $format  Required format identifier.
     *
     * @return  array<string, mixed>  Decoded JSON object.
     *
     * @since   2.0.0
     */
    private static function document(string $path, string $format): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('An asset-inspection acceptance document is unavailable.');
        }
        $size = filesize($path);
        if (!is_int($size) || $size < 2 || $size > 8_388_608) {
            throw new RuntimeException('An asset-inspection acceptance document has an invalid size.');
        }
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('An asset-inspection acceptance document could not be read.');
        }
        $decoded = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded) || ($decoded['format'] ?? null) !== $format) {
            throw new RuntimeException('An asset-inspection acceptance document has an invalid format.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Require one string field in workflow state against a closed regular expression.
     *
     * @param   array<string, mixed>  $state    Decoded state object.
     * @param   string                $key      Required member.
     * @param   string                $pattern  Anchored validation expression.
     *
     * @return  string  Validated member.
     *
     * @since   2.0.0
     */
    private static function requiredState(array $state, string $key, string $pattern): string
    {
        $value = $state[$key] ?? null;
        if (!is_string($value) || preg_match($pattern, $value) !== 1) {
            throw new RuntimeException('An asset-inspection state identity is invalid.');
        }

        return $value;
    }

    /**
     * Read one positive integer from protected workflow state without scalar coercion.
     *
     * @param   array<string, mixed>  $state  Decoded state object.
     * @param   string                $key    Required integer member.
     *
     * @return  int  Positive state value.
     *
     * @since   2.0.0
     */
    private static function positiveStateInteger(array $state, string $key): int
    {
        $value = $state[$key] ?? null;
        if (!is_int($value) || $value < 1) {
            throw new RuntimeException('An asset-inspection state sequence is invalid.');
        }

        return $value;
    }

    /**
     * Count contribution inventory leaves recursively.
     *
     * @param   array<array-key, mixed>  $value  Nested inventory group or leaf list.
     *
     * @return  int  Number of declared contribution documents.
     *
     * @since   2.0.0
     */
    private static function leafCount(array $value): int
    {
        if (array_is_list($value)) {
            return count($value);
        }
        $count = 0;
        foreach ($value as $child) {
            if (!is_array($child)) {
                throw new RuntimeException('A contribution inventory leaf is malformed.');
            }
            $count += self::leafCount($child);
        }

        return $count;
    }

    /**
     * Normalize database rows into portable string, integer, boolean, null, and decoded JSON values.
     *
     * @param   list<array<string, mixed>>  $rows  DBAL result rows.
     *
     * @return  list<array<string, mixed>>  Canonically key-sorted portable rows.
     *
     * @since   2.0.0
     */
    private static function portableRows(array $rows): array
    {
        $portable = [];
        foreach ($rows as $row) {
            $normalized = [];
            foreach ($row as $key => $value) {
                if ($key === 'metadata' && is_string($value)) {
                    $value = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
                }
                if (is_float($value)) {
                    $value = (string) $value;
                }
                if (
                    !is_null($value) && !is_bool($value) && !is_int($value)
                    && !is_string($value) && !is_array($value)
                ) {
                    throw new RuntimeException('A durable acceptance row contains an unsupported value.');
                }
                $normalized[$key] = $value;
            }
            ksort($normalized, SORT_STRING);
            $portable[] = $normalized;
        }

        return $portable;
    }

    /**
     * Normalize an extension release row into a string-or-null map.
     *
     * @param   array<string, mixed>  $row  DBAL release row.
     *
     * @return  array<string, string|null>  Portable release evidence.
     *
     * @since   2.0.0
     */
    private static function strings(array $row): array
    {
        $result = [];
        foreach ($row as $key => $value) {
            if (!is_string($value) && $value !== null) {
                throw new RuntimeException('The installed release row contains a non-string value.');
            }
            $result[$key] = $value;
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /** Prevent construction of the static acceptance harness. @since 2.0.0 */
    private function __construct()
    {
    }
}
