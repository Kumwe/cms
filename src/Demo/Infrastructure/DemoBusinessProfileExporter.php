<?php

declare(strict_types=1);

namespace Kumwe\App\Demo\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionService;
use Kumwe\App\BusinessDefinition\Domain\DefinitionOwnerType;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\App\BusinessDefinition\Domain\RelationshipKind;
use Kumwe\App\BusinessRecord\Application\BusinessRecordRelationView;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\BusinessRecordView;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable;
use Kumwe\App\BusinessRecord\Application\Query\BrowseRecordsQuery;
use Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordQueryPurpose;
use Kumwe\App\BusinessRecord\Application\Query\RecordHistoryQuery;
use Kumwe\Conversion\Decimal\ExactDecimal;
use Kumwe\Conversion\Value\MoneyValue;
use Kumwe\Conversion\Value\QuantityValue;
use Kumwe\Extension\Spi\BusinessRecord\Value\ZonedDateTimeValue;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordProjection;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\App\BusinessSecurity\Application\Administration\BusinessSecurityAdministrationRepository;
use Kumwe\App\Demo\Application\DemoBusinessTemplateProjector;
use Kumwe\App\Demo\Application\DemoProfileLedger;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Kernel\Configuration\ApplicationConfiguration;
use RuntimeException;

/**
 * Projects the running business runtime back into the installable business and access manifests.
 *
 * This is the business half of the export contract `DemoProfileExporter` opens for site content: the
 * live services — never the tables — are walked and the result is exactly what the profile installer
 * consumes. Fixture keys, idempotency keys, and whole operation requests are reused byte for byte from
 * the provenance ledger whenever the resource is still at the version the installer left it, so an
 * export of an installed dataset stays diffable against the manifest it came from; operator-created or
 * operator-modified resources receive freshly minted keys and re-derived values. Record-access modes
 * are recovered through the frozen-selector invariant — a definition still present in the configured
 * source profile keeps its released mode, anything else falls back to `administration`, the most
 * restrictive mode. The access manifest observes a hard privacy rule: only identities already inside
 * the reserved `.example` zone are exported, every other identity is counted and withheld, and no
 * credential material of any kind is ever within reach of these read paths. The definitions leave through
 * `DemoBusinessTemplateProjector`: every handle is re-namespaced to `site.default.<profile>_<name>` with every
 * reference following it, the documents take the released-draft shape the installer publishes from, and the
 * installation order is settled over the full reference graph — reference fields as well as relationships.
 *
 * @since  2.0.0
 */
final readonly class DemoBusinessProfileExporter
{
    /**
     * Grammar an exported profile name must satisfy, mirroring the catalog's selection rule.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string PROFILE_NAME_PATTERN = '/^[a-z][a-z0-9-]{0,62}$/D';

    /**
     * Grammar every exported access role handle, organization, and workspace identifier must satisfy.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string ACCESS_NAME_PATTERN = '/^[a-z][a-z0-9-]{0,62}$/D';

    /**
     * Grammar every exported role capability must satisfy, mirroring the catalog's validation.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string CAPABILITY_PATTERN = '/^[a-z][a-z0-9._-]{2,190}$/D';

    /**
     * Grammar every exported identity address must satisfy, confining the cast to `.example`.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string EMAIL_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,63}@[a-z0-9][a-z0-9.-]{0,120}\.example$/D';

    /**
     * Wire the exporter to the authorized read surfaces of the business and access datasets.
     *
     * @param  BusinessDefinitionService                 $definitions    Authorized reader for the
     *         definition catalog and its published versions.
     * @param  BusinessRecordService                     $records        Authorized reader for records,
     *         relations, and revision history.
     * @param  AccessControlService                      $access         Authorized reader for users,
     *         roles, and their capability grants.
     * @param  BusinessSecurityAdministrationRepository  $security       Reader for organizations,
     *         workspaces, and memberships.
     * @param  FilesystemDemoManifestCatalog             $catalog        Released manifests the
     *         record-access recovery reads its frozen selectors from.
     * @param  ApplicationConfiguration                  $configuration  Validated process profile
     *         selectors naming the installed source profile.
     * @param  DemoProfileLedger                         $ledger         Provenance ledger holding
     *         installed fixture identities and applied requests.
     * @param  DemoBusinessTemplateProjector             $template       Pure projection of live definitions
     *         into the default-site template namespace, order, and draft shape.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessDefinitionService $definitions,
        private BusinessRecordService $records,
        private AccessControlService $access,
        private BusinessSecurityAdministrationRepository $security,
        private FilesystemDemoManifestCatalog $catalog,
        private ApplicationConfiguration $configuration,
        private DemoProfileLedger $ledger,
        private DemoBusinessTemplateProjector $template = new DemoBusinessTemplateProjector(),
    ) {
    }

    /**
     * Project the site's business dataset and demonstration cast into installable manifest documents.
     *
     * The result carries the `kumwe.demo-business-profile/v1` profile document, one released-draft document
     * per published site-owned definition keyed by its relative file name, the
     * `kumwe.demo-business-records/v1` records document, and the `kumwe.demo-access/v1` access
     * document. Definitions are declared in reference order under the `site.default.<profile>_` namespace
     * the catalog requires, so the package installs under the profile name it was exported as. When the
     * site publishes no site-owned business definitions every member is empty, and when no identity
     * qualifies for the access manifest its member is empty so the caller omits the file instead of
     * writing an invalid one. The withheld-identity count reports how many live identities were excluded
     * by the `.example` privacy rule.
     *
     * @param   ExecutionContext  $context  Authenticated administrator the reads run as.
     * @param   string            $profile  Profile name the manifests will be published under.
     *
     * @return  array{
     *              profile: array<string, mixed>,
     *              definitions: array<string, array<string, mixed>>,
     *              records: array<string, mixed>,
     *              access: array<string, mixed>,
     *              withheld_identities: int
     *          }  Manifest documents ready for `DemoProfileExporter::writePackage()`.
     *
     * @throws  InvalidArgumentException  When the profile name violates the selection grammar.
     * @throws  RuntimeException  When a live read answers with a shape the manifests cannot carry, or the
     *          published definitions reference one another in a cycle no installation order can satisfy.
     *
     * @since   2.0.0
     */
    public function documents(ExecutionContext $context, string $profile): array
    {
        if (preg_match(self::PROFILE_NAME_PATTERN, $profile) !== 1) {
            throw new InvalidArgumentException(
                'The export profile name must be lowercase letters, digits, and dashes.',
            );
        }
        $site = $context->site()->identifier();
        $withheld = 0;
        $definitions = $this->publishedDefinitions($context);
        if ($definitions === []) {
            return [
                'profile' => [],
                'definitions' => [],
                'records' => [],
                'access' => [],
                'withheld_identities' => 0,
            ];
        }
        $definitions = $this->template->orderByDependency($definitions);
        $installed = $this->installedAssets($site);
        $fixtures = $this->definitionFixtures($definitions, $installed);
        $handles = $this->template->templateHandles($profile, $definitions, $fixtures);
        $modes = $this->recoveredModes();
        $order = [];
        $documents = [];
        foreach ($definitions as $definition) {
            $fixture = $fixtures[$definition->handle];
            $tail = substr($fixture, strlen('definition.'));
            $file = 'definitions/' . str_replace('_', '-', $tail) . '.json';
            $order[] = [
                'fixture_key' => $fixture,
                'record_access' => $modes[$definition->id] ?? 'administration',
                'file' => $file,
                'id' => $definition->id,
                'handle' => $handles[$definition->handle],
                'depends_on' => $this->template->dependencies($definition, $definitions, $fixtures),
            ];
            $documents[$file] = $this->template->templateDocument($definition, $handles);
        }
        $records = $this->template->templateRecords(
            $this->recordsDocument($context, $profile, $site, $definitions, $fixtures, $installed),
            $handles,
        );
        $expected = $this->mapOf($records, 'expected');

        return [
            'profile' => [
                'format' => 'kumwe.demo-business-profile/v1',
                'profile' => $profile,
                'version' => 1,
                'label' => 'Exported business dataset',
                'description' => sprintf(
                    'Business dataset exported from the %s site of a running Kumwe installation.',
                    $site,
                ),
                'site_template' => 'default',
                'source_context' => [
                    'organization' => 'Exported system',
                    'official_url' => null,
                    'derived_service_areas' => [],
                    'fixture_privacy' => 'All records were exported from a running installation '
                        . 'and must be reviewed before publication.',
                ],
                'installation_order' => $order,
                'records_file' => 'records.json',
                'execution_order' => [
                    'publish_and_install_definitions',
                    'install_record_access_policies',
                    'create_records',
                    'relate_records',
                    'execute_actions',
                    'archive_records',
                ],
                'expected' => [
                    'definition_count' => count($order),
                    'record_count' => $this->intOf($expected, 'record_count'),
                    'relation_count' => $this->intOf($expected, 'relation_count'),
                    'action_count' => $this->intOf($expected, 'action_count'),
                    'archive_count' => $this->intOf($expected, 'archive_count'),
                ],
            ],
            'definitions' => $documents,
            'records' => $records,
            'access' => $this->accessDocument($context, $profile, $site, $withheld),
            'withheld_identities' => $withheld,
        ];
    }

    /**
     * Load every published, site-owned definition in catalog order.
     *
     * Core- and extension-owned definitions are deliberately left out: the profile installer imports
     * every document as a site-owned version-zero draft, so a package-owned definition exported here
     * could never be installed as itself and belongs to its package's release channel instead.
     *
     * @param   ExecutionContext  $context  Authenticated administrator the reads run as.
     *
     * @return  list<EntityTypeDefinition>  Published definitions the site owns, in catalog order.
     *
     * @since   2.0.0
     */
    private function publishedDefinitions(ExecutionContext $context): array
    {
        $definitions = [];
        foreach ($this->definitions->catalog($context) as $entry) {
            if ($entry->owner->type !== DefinitionOwnerType::Site || $entry->publishedVersion === null) {
                continue;
            }
            $definitions[] = $this->definitions->published($context, $entry->id)->definition;
        }

        return $definitions;
    }

    /**
     * Index the provenance ledger's business dataset for fixture and request reuse.
     *
     * @param   string  $site  Site identifier the export runs against.
     *
     * @return  array{
     *              definitions: array<string, string>,
     *              records: array<string, array<string, mixed>>,
     *              relations: array<string, array<string, mixed>>,
     *              actions: array<string, list<array<string, mixed>>>,
     *              archives: array<string, array<string, mixed>>,
     *              latest: array<string, int>,
     *              taken: array<string, true>
     *          }  Fixture keys by definition UUID, applied operation states by their natural identity,
     *          the last applied version per record, and every fixture key already in use.
     *
     * @since   2.0.0
     */
    private function installedAssets(string $site): array
    {
        $index = [
            'definitions' => [],
            'records' => [],
            'relations' => [],
            'actions' => [],
            'archives' => [],
            'latest' => [],
            'taken' => [],
        ];
        foreach ($this->ledger->assets($site, VdmBusinessDemoInstaller::DATASET) as $asset) {
            $type = $asset['resource_type'] ?? null;
            $fixture = $asset['fixture_key'] ?? null;
            $resource = $asset['resource_id'] ?? null;
            if (!is_string($type) || !is_string($fixture) || !is_string($resource)) {
                continue;
            }
            $index['taken'][$fixture] = true;
            if ($type === 'business_definition') {
                $index['definitions'][$resource] = $fixture;
                continue;
            }
            $state = $asset['last_applied_state'] ?? null;
            if (!is_array($state) || array_is_list($state) || !is_int($state['version'] ?? null)) {
                continue;
            }
            /** @var array<string, mixed> $state */
            $version = $state['version'];
            if (is_int($version)) {
                $index['latest'][$resource] = max($index['latest'][$resource] ?? 0, $version);
            }
            $request = $state['request'] ?? null;
            if (!is_array($request) || array_is_list($request)) {
                continue;
            }
            if ($type === 'business_record') {
                $index['records'][$resource] = $state;
            } elseif ($type === 'business_relation') {
                $index['relations'][$this->relationIdentity($request)] = $state;
            } elseif ($type === 'business_action') {
                $index['actions'][$resource][] = $state;
            } elseif ($type === 'business_archive') {
                $index['archives'][$resource] = $state;
            }
        }
        foreach ($index['actions'] as $recordId => $states) {
            usort(
                $states,
                static fn (array $left, array $right): int =>
                    (is_int($left['version'] ?? null) ? $left['version'] : 0)
                    <=> (is_int($right['version'] ?? null) ? $right['version'] : 0),
            );
            $index['actions'][$recordId] = $states;
        }

        return $index;
    }

    /**
     * Derive the natural identity a ledgered relation request is matched against a live link by.
     *
     * @param   array<array-key, mixed>  $request  Applied relate request out of the ledger.
     *
     * @return  string  Definition, source, relationship, and target joined into one lookup key.
     *
     * @since   2.0.0
     */
    private function relationIdentity(array $request): string
    {
        return implode('|', [
            is_string($request['definition'] ?? null) ? $request['definition'] : '',
            is_string($request['source_record_id'] ?? null) ? $request['source_record_id'] : '',
            is_string($request['relationship'] ?? null) ? $request['relationship'] : '',
            is_string($request['target_record_id'] ?? null) ? $request['target_record_id'] : '',
        ]);
    }

    /**
     * Settle the fixture key every exported definition is declared under.
     *
     * Ledgered definitions keep their installed keys; anything else receives a minted
     * `definition.<tail>` key whose tail is the handle's last dot segment with its vendor prefix
     * stripped, lowercased, confined to `[a-z0-9_]`, and deduplicated with numeric suffixes.
     *
     * @param   list<EntityTypeDefinition>  $definitions  Definitions in installation order.
     * @param   array<string, mixed>        $installed    Ledger index built by `installedAssets()`.
     *
     * @return  array<string, string>  Fixture key by live definition handle.
     *
     * @since   2.0.0
     */
    private function definitionFixtures(array $definitions, array $installed): array
    {
        $ledgered = $this->mapOf($installed, 'definitions');
        $taken = $this->mapOf($installed, 'taken');
        $fixtures = [];
        foreach ($definitions as $definition) {
            $fixture = $ledgered[$definition->id] ?? null;
            if (!is_string($fixture)) {
                $fixture = $this->mintDefinitionKey($definition->handle, $taken);
            }
            $taken[$fixture] = true;
            $fixtures[$definition->handle] = $fixture;
        }

        return $fixtures;
    }

    /**
     * Mint one collision-free `definition.<tail>` fixture key from a definition handle.
     *
     * @param   string                $handle  Namespaced definition handle, such as
     *          `site.default.vdm_client_account`.
     * @param   array<string, mixed>  $taken   Fixture keys already claimed by the ledger or this export.
     *
     * @return  string  Minted key satisfying the catalog's definition fixture grammar.
     *
     * @since   2.0.0
     */
    private function mintDefinitionKey(string $handle, array $taken): string
    {
        $segments = explode('.', $handle);
        $tail = strtolower($segments[count($segments) - 1]);
        $separator = strpos($tail, '_');
        if ($separator !== false && $separator + 1 < strlen($tail)) {
            $tail = substr($tail, $separator + 1);
        }
        $tail = preg_replace('/[^a-z0-9_]+/', '_', $tail) ?? $tail;
        if (preg_match('/^[a-z]/', $tail) !== 1) {
            $tail = 'definition_' . $tail;
        }
        $tail = substr($tail, 0, 64);
        $key = 'definition.' . $tail;
        if (!isset($taken[$key])) {
            return $key;
        }
        $suffix = 2;
        while (isset($taken[$key . '_' . $suffix])) {
            ++$suffix;
        }

        return $key . '_' . $suffix;
    }

    /**
     * Recover the released record-access modes through the frozen-selector invariant.
     *
     * The selected business profile is frozen at installation, so a definition that is still declared
     * by the configured source profile keeps the `record_access` that profile shipped for it. Every
     * other definition — operator-authored or belonging to no discoverable profile — is reported by
     * the caller as `administration`, the most restrictive mode.
     *
     * @return  array<string, string>  Released record-access mode by definition UUID.
     *
     * @since   2.0.0
     */
    private function recoveredModes(): array
    {
        $source = $this->configuration->businessProfile;
        if ($source === 'none' || !in_array($source, $this->catalog->businessProfiles(), true)) {
            return [];
        }
        $modes = [];
        $manifest = $this->catalog->business($source)['manifest'];
        $order = $manifest['installation_order'] ?? null;
        foreach (is_array($order) ? $order : [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = $entry['id'] ?? null;
            $mode = $entry['record_access'] ?? null;
            if (
                is_string($id)
                && is_string($mode)
                && in_array($mode, ['open', 'organization', 'organization-read', 'administration'], true)
            ) {
                $modes[$id] = $mode;
            }
        }

        return $modes;
    }

    /**
     * Project every live record, relation, workflow action, and archive into the records document.
     *
     * Definitions that only ever hold owned lines are skipped as record sources — their rows travel
     * inside the owning relations' `target_values` — and inverse-declared relationships are exported
     * from one deterministic side only, so a bidirectional link never becomes two relate calls.
     *
     * @param   ExecutionContext            $context      Authenticated administrator the reads run as.
     * @param   string                      $profile      Profile name minted identifiers embed.
     * @param   string                      $site         Site identifier the export runs against.
     * @param   list<EntityTypeDefinition>  $definitions  Definitions in dependency order.
     * @param   array<string, string>       $fixtures     Fixture key by definition handle.
     * @param   array<string, mixed>        $installed    Ledger index built by `installedAssets()`.
     *
     * @return  array<string, mixed>  Complete `kumwe.demo-business-records/v1` document.
     *
     * @since   2.0.0
     */
    private function recordsDocument(
        ExecutionContext $context,
        string $profile,
        string $site,
        array $definitions,
        array $fixtures,
        array $installed,
    ): array {
        $lineTargets = [];
        foreach ($definitions as $definition) {
            foreach ($definition->relationships() as $relationship) {
                if ($relationship->kind === RelationshipKind::OwnedLineCollection) {
                    $lineTargets[$relationship->target] = true;
                }
            }
        }
        $byHandle = [];
        foreach ($definitions as $definition) {
            $byHandle[$definition->handle] = $definition;
        }
        $records = [];
        $relations = [];
        $actions = [];
        $archives = [];
        $states = [];
        foreach ($definitions as $definition) {
            if (isset($lineTargets[$definition->handle])) {
                continue;
            }
            $tail = substr($fixtures[$definition->handle], strlen('definition.'));
            $page = $this->browseDefinition($context, $definition);
            $ordinal = 0;
            foreach ($page['records'] as $view) {
                ++$ordinal;
                $records[] = $this->recordEntry($profile, $definition, $view, $tail, $ordinal, $installed);
                $includes = $page['includes'][$view->recordId] ?? [];
                foreach (
                    $this->relationEntries(
                        $profile,
                        $definition,
                        $byHandle,
                        $view,
                        $includes,
                        $tail,
                        $ordinal,
                        $installed,
                    ) as $relation
                ) {
                    $relations[] = $relation;
                }
                foreach (
                    $this->actionEntries($context, $profile, $definition, $view, $tail, $ordinal, $installed) as $action
                ) {
                    $actions[] = $action;
                }
                if ($view->archivedAt !== null) {
                    $archives[] = $this->archiveEntry($profile, $definition, $view, $tail, $ordinal, $installed);
                }
                if ($view->workflowState !== null) {
                    $states[$tail][$view->workflowState] = ($states[$tail][$view->workflowState] ?? 0) + 1;
                }
            }
        }

        return [
            'format' => 'kumwe.demo-business-records/v1',
            'profile' => $profile,
            'version' => 1,
            'site' => $site,
            'currency' => $this->sourceCurrency(),
            'records' => $records,
            'relations' => $relations,
            'actions' => $actions,
            'archives' => $archives,
            'expected' => [
                'record_count' => count($records),
                'relation_count' => count($relations),
                'action_count' => count($actions),
                'archive_count' => count($archives),
                'workflow_states' => $states,
            ],
        ];
    }

    /**
     * Copy the currency the configured source profile declares, or fall back to `NAD`.
     *
     * @return  string  Manifest currency code for the exported records document.
     *
     * @since   2.0.0
     */
    private function sourceCurrency(): string
    {
        $source = $this->configuration->businessProfile;
        if ($source === 'none' || !in_array($source, $this->catalog->businessProfiles(), true)) {
            return 'NAD';
        }
        $manifest = $this->catalog->business($source)['manifest'];
        $document = $manifest['records_document'] ?? null;
        $currency = is_array($document) ? ($document['currency'] ?? null) : null;

        return is_string($currency) && $currency !== '' ? $currency : 'NAD';
    }

    /**
     * Read one definition's complete record set with every relationship hydrated.
     *
     * Pages of two hundred records are walked until the cursor ends, and relationship handles are
     * hydrated in chunks of four — the projection's include bound — with later chunks merged onto the
     * records the first chunk collected. A published definition whose generated schema is not active
     * holds no records by construction and is answered with an empty page instead of a failure.
     *
     * @param   ExecutionContext      $context     Authenticated administrator the reads run as.
     * @param   EntityTypeDefinition  $definition  Definition whose records are wanted.
     *
     * @return  array{
     *              records: list<BusinessRecordView>,
     *              includes: array<string, array<string, list<BusinessRecordRelationView>>>
     *          }  Records in creation order and hydrated relation rows by record and relationship.
     *
     * @since   2.0.0
     */
    private function browseDefinition(ExecutionContext $context, EntityTypeDefinition $definition): array
    {
        $handles = [];
        foreach ($definition->relationships() as $relationship) {
            $handles[] = $relationship->handle;
        }
        $chunks = $handles === [] ? [[]] : array_chunk($handles, 4);
        $records = [];
        $includes = [];
        try {
            foreach ($chunks as $offset => $chunk) {
                $cursor = null;
                do {
                    $page = $this->records->browse(new BrowseRecordsQuery(
                        $context,
                        $definition->handle,
                        new RecordQuerySpecification(
                            after: $cursor,
                            pageSize: 200,
                            projection: new RecordProjection(includes: $chunk),
                            includeArchived: true,
                        ),
                        null,
                        BusinessRecordQueryPurpose::Export,
                    ));
                    foreach ($page->records as $view) {
                        if ($offset === 0) {
                            $records[] = $view;
                        }
                        foreach ($view->includes as $handle => $rows) {
                            $includes[$view->recordId][$handle] = $rows;
                        }
                    }
                    $cursor = $page->nextCursor;
                } while ($cursor !== null);
            }
        } catch (BusinessRecordSchemaUnavailable) {
            return ['records' => [], 'includes' => []];
        }
        usort(
            $records,
            static fn (BusinessRecordView $left, BusinessRecordView $right): int =>
                [$left->createdAt->getTimestamp(), $left->recordId]
                <=> [$right->createdAt->getTimestamp(), $right->recordId],
        );

        return ['records' => $records, 'includes' => $includes];
    }

    /**
     * Declare one live record, reusing its applied create request whenever it is still exact.
     *
     * A ledgered record whose live version equals the last version any applied operation left it at
     * is exported as its stored request byte for byte, so reconciliation against the same system stays
     * checkpoint-exact. A record that moved on keeps its installed fixture and idempotency keys but
     * carries freshly encoded live values; an unledgered record is minted from its ordinal.
     *
     * @param   string                $profile     Profile name minted identifiers embed.
     * @param   EntityTypeDefinition  $definition  Definition the record belongs to.
     * @param   BusinessRecordView    $view        Disclosure-safe live record projection.
     * @param   string                $tail        Definition fixture tail minted keys derive from.
     * @param   int                   $ordinal     One-based position within the definition's records.
     * @param   array<string, mixed>  $installed   Ledger index built by `installedAssets()`.
     *
     * @return  array<string, mixed>  Manifest record declaration.
     *
     * @since   2.0.0
     */
    private function recordEntry(
        string $profile,
        EntityTypeDefinition $definition,
        BusinessRecordView $view,
        string $tail,
        int $ordinal,
        array $installed,
    ): array {
        $ledgered = $this->mapOf($installed, 'records');
        $latest = $this->mapOf($installed, 'latest');
        $state = $ledgered[$view->recordId] ?? null;
        if (is_array($state)) {
            $request = $state['request'] ?? null;
            if (is_array($request) && !array_is_list($request)) {
                if (($latest[$view->recordId] ?? null) === $view->version) {
                    /** @var array<string, mixed> $request */
                    return $request;
                }

                return [
                    'fixture_key' => $this->stringOf($request, 'fixture_key'),
                    'definition' => $definition->handle,
                    'record_id' => $view->recordId,
                    'idempotency_key' => $this->stringOf($request, 'idempotency_key'),
                    'values' => $this->encodeValues($definition, $view->values),
                ];
            }
        }
        $fixture = sprintf('record.%s.%04d', $tail, $ordinal);

        return [
            'fixture_key' => $fixture,
            'definition' => $definition->handle,
            'record_id' => $view->recordId,
            'idempotency_key' => sprintf('kumwe-demo-%s-v1:create:%s', $profile, $fixture),
            'values' => $this->encodeValues($definition, $view->values),
        ];
    }

    /**
     * Declare one record's live relations, applied requests first and in their applied order.
     *
     * Every hydrated relationship row becomes one relate declaration. Rows whose links the installer
     * applied reuse their stored requests and are emitted in ascending applied-version order, so a
     * reconciliation of the export against the same system replays the exact per-record sequence the
     * append-only guard expects; operator-added links follow with minted keys. A relationship pair
     * declared with an inverse is exported from its lexicographically first side only.
     *
     * @param   string                                           $profile     Profile name minted
     *          identifiers embed.
     * @param   EntityTypeDefinition                             $definition  Source definition.
     * @param   array<string, EntityTypeDefinition>              $byHandle    Exported definitions by
     *          handle, resolving owned-line targets.
     * @param   BusinessRecordView                               $view        Source record.
     * @param   array<string, list<BusinessRecordRelationView>>  $includes    Hydrated rows by
     *          relationship handle.
     * @param   string                                           $tail        Definition fixture tail.
     * @param   int                                              $ordinal     Source record ordinal.
     * @param   array<string, mixed>                             $installed   Ledger index.
     *
     * @return  list<array<string, mixed>>  Manifest relate declarations for this record.
     *
     * @since   2.0.0
     */
    private function relationEntries(
        string $profile,
        EntityTypeDefinition $definition,
        array $byHandle,
        BusinessRecordView $view,
        array $includes,
        string $tail,
        int $ordinal,
        array $installed,
    ): array {
        $ledgered = $this->mapOf($installed, 'relations');
        $applied = [];
        $minted = [];
        $taken = [];
        foreach ($definition->relationships() as $relationship) {
            if (
                $relationship->inverse !== null
                && [$definition->handle, $relationship->handle]
                    > [$relationship->target, $relationship->inverse]
            ) {
                continue;
            }
            $rows = $includes[$relationship->handle] ?? [];
            foreach ($rows as $row) {
                $identity = implode('|', [
                    $definition->handle,
                    $view->recordId,
                    $relationship->handle,
                    $row->recordId,
                ]);
                $state = $ledgered[$identity] ?? null;
                if (is_array($state)) {
                    $request = $state['request'] ?? null;
                    if (is_array($request) && !array_is_list($request)) {
                        /** @var array<string, mixed> $request */
                        $applied[] = [
                            'version' => is_int($state['version'] ?? null) ? $state['version'] : 0,
                            'request' => $request,
                        ];
                        continue;
                    }
                }
                $fixture = sprintf('relation.%s_%04d.%s', $tail, $ordinal, $relationship->handle);
                if (isset($taken[$fixture])) {
                    $suffix = 2;
                    while (isset($taken[$fixture . '_' . $suffix])) {
                        ++$suffix;
                    }
                    $fixture .= '_' . $suffix;
                }
                $taken[$fixture] = true;
                $declaration = [
                    'fixture_key' => $fixture,
                    'definition' => $definition->handle,
                    'source_record_id' => $view->recordId,
                    'relationship' => $relationship->handle,
                    'target_record_id' => $row->recordId,
                    'idempotency_key' => sprintf('kumwe-demo-%s-v1:relate:%s', $profile, $fixture),
                ];
                if ($row->position !== null) {
                    $declaration['position'] = $row->position;
                }
                if ($relationship->kind === RelationshipKind::OwnedLineCollection) {
                    $target = $byHandle[$relationship->target] ?? null;
                    if ($target !== null) {
                        $declaration['target_values'] = $this->encodeValues($target, $row->values);
                    }
                }
                $minted[] = $declaration;
            }
        }
        usort($applied, static fn (array $left, array $right): int => $left['version'] <=> $right['version']);
        $declarations = [];
        foreach ($applied as $entry) {
            $declarations[] = $entry['request'];
        }
        foreach ($minted as $declaration) {
            $declarations[] = $declaration;
        }

        return $declarations;
    }

    /**
     * Reconstruct one record's workflow actions from its revision history.
     *
     * Revisions are walked oldest first and every `action.<handle>` revision becomes one declaration.
     * Applied actions are matched to their ledgered requests by action handle, so an untouched record
     * replays byte-identically; anything beyond the ledger is minted from the record's ordinal.
     *
     * @param   ExecutionContext      $context     Authenticated administrator the read runs as.
     * @param   string                $profile     Profile name minted identifiers embed.
     * @param   EntityTypeDefinition  $definition  Definition the record belongs to.
     * @param   BusinessRecordView    $view        Source record.
     * @param   string                $tail        Definition fixture tail minted keys derive from.
     * @param   int                   $ordinal     Source record ordinal.
     * @param   array<string, mixed>  $installed   Ledger index built by `installedAssets()`.
     *
     * @return  list<array<string, mixed>>  Manifest action declarations in chronological order.
     *
     * @since   2.0.0
     */
    private function actionEntries(
        ExecutionContext $context,
        string $profile,
        EntityTypeDefinition $definition,
        BusinessRecordView $view,
        string $tail,
        int $ordinal,
        array $installed,
    ): array {
        if ($definition->actions() === []) {
            return [];
        }
        $ledgered = $this->mapOf($installed, 'actions');
        $queues = [];
        $states = $ledgered[$view->recordId] ?? [];
        foreach (is_array($states) ? $states : [] as $state) {
            if (!is_array($state)) {
                continue;
            }
            $request = $state['request'] ?? null;
            if (!is_array($request) || array_is_list($request)) {
                continue;
            }
            $handle = $request['action'] ?? null;
            if (is_string($handle)) {
                /** @var array<string, mixed> $request */
                $queues[$handle][] = $request;
            }
        }
        $declarations = [];
        $position = 0;
        foreach ($this->actionOperations($context, $definition, $view->recordId) as $action) {
            ++$position;
            $queued = $queues[$action] ?? [];
            $reused = array_shift($queued);
            if (is_array($reused)) {
                $queues[$action] = $queued;
                $declarations[] = $reused;
                continue;
            }
            $fixture = sprintf('action.%s_%04d.%s', $tail, $ordinal, $action);
            if ($position > 1) {
                $fixture = sprintf('action.%s_%04d.%s_%d', $tail, $ordinal, $action, $position);
            }
            $declarations[] = [
                'fixture_key' => $fixture,
                'definition' => $definition->handle,
                'record_id' => $view->recordId,
                'action' => $action,
                'idempotency_key' => sprintf('kumwe-demo-%s-v1:action:%s_%04d:%s', $profile, $tail, $ordinal, $action),
            ];
        }

        return $declarations;
    }

    /**
     * Read one record's executed workflow-action handles in chronological order.
     *
     * @param   ExecutionContext      $context     Authenticated administrator the read runs as.
     * @param   EntityTypeDefinition  $definition  Definition the record belongs to.
     * @param   string                $recordId    Public identity of the record.
     *
     * @return  list<string>  Action handles as `action.<handle>` revisions recorded them, oldest first.
     *
     * @since   2.0.0
     */
    private function actionOperations(
        ExecutionContext $context,
        EntityTypeDefinition $definition,
        string $recordId,
    ): array {
        $operations = [];
        $before = null;
        do {
            $result = $this->records->history(new RecordHistoryQuery(
                $context,
                $definition->handle,
                $recordId,
                null,
                200,
                $before,
            ));
            $before = null;
            foreach ($result->revisions as $revision) {
                $operations[] = $revision->operation;
                $before = $revision->recordVersion;
            }
        } while ($result->hasMore && $before !== null);
        $actions = [];
        foreach (array_reverse($operations) as $operation) {
            if (str_starts_with($operation, 'action.')) {
                $actions[] = substr($operation, strlen('action.'));
            }
        }

        return $actions;
    }

    /**
     * Declare one archived record, reusing the applied archive request when the ledger holds it.
     *
     * @param   string                $profile     Profile name minted identifiers embed.
     * @param   EntityTypeDefinition  $definition  Definition the record belongs to.
     * @param   BusinessRecordView    $view        Archived source record.
     * @param   string                $tail        Definition fixture tail minted keys derive from.
     * @param   int                   $ordinal     Source record ordinal.
     * @param   array<string, mixed>  $installed   Ledger index built by `installedAssets()`.
     *
     * @return  array<string, mixed>  Manifest archive declaration.
     *
     * @since   2.0.0
     */
    private function archiveEntry(
        string $profile,
        EntityTypeDefinition $definition,
        BusinessRecordView $view,
        string $tail,
        int $ordinal,
        array $installed,
    ): array {
        $ledgered = $this->mapOf($installed, 'archives');
        $state = $ledgered[$view->recordId] ?? null;
        if (is_array($state)) {
            $request = $state['request'] ?? null;
            if (is_array($request) && !array_is_list($request)) {
                /** @var array<string, mixed> $request */
                return $request;
            }
        }
        $fixture = sprintf('archive.%s_%04d', $tail, $ordinal);

        return [
            'fixture_key' => $fixture,
            'definition' => $definition->handle,
            'record_id' => $view->recordId,
            'idempotency_key' => sprintf('kumwe-demo-%s-v1:archive:%s_%04d', $profile, $tail, $ordinal),
        ];
    }

    /**
     * Re-encode one record's disclosed values into the scalar shapes a create request accepts.
     *
     * Only fields an authoring request may carry survive: server-only, computed, formula-backed, and
     * create-invisible fields are dropped along with restricted and secret sensitivities, and null
     * values are omitted the way released manifests omit them. Typed runtime values — exact decimals,
     * money, quantities, zoned datetimes, and instants — are collapsed to their canonical manifest
     * forms using each field's declared type.
     *
     * @param   EntityTypeDefinition  $definition  Definition whose field metadata governs admission.
     * @param   array<string, mixed>  $values      Disclosure-safe live values keyed by field handle.
     *
     * @return  array<string, mixed>  Create-request values keyed by field handle.
     *
     * @since   2.0.0
     */
    private function encodeValues(EntityTypeDefinition $definition, array $values): array
    {
        $encoded = [];
        foreach ($definition->fields() as $field) {
            if (
                !$field->createVisible
                || $field->serverOnly
                || $field->computed
                || $field->formula !== null
                || in_array($field->sensitivity->value, ['restricted', 'secret'], true)
                || !array_key_exists($field->handle, $values)
            ) {
                continue;
            }
            $value = $this->encodeValue($values[$field->handle], $field);
            if ($value !== null) {
                $encoded[$field->handle] = $value;
            }
        }

        return $encoded;
    }

    /**
     * Collapse one runtime value into the scalar form the manifests and create requests share.
     *
     * @param   mixed             $value  Disclosed runtime value.
     * @param   ?FieldDefinition  $field  Declared field the value belongs to, or null inside arrays.
     *
     * @return  mixed  JSON-encodable manifest value, or null when the value is null.
     *
     * @since   2.0.0
     */
    private function encodeValue(mixed $value, ?FieldDefinition $field): mixed
    {
        if ($value instanceof ExactDecimal) {
            return $value->value();
        }
        if (
            $value instanceof MoneyValue
            || $value instanceof QuantityValue
            || $value instanceof ZonedDateTimeValue
        ) {
            return $value->toArray();
        }
        if ($value instanceof DateTimeImmutable) {
            return match ($field?->type) {
                'core.date' => $value->format('Y-m-d'),
                'core.local_time' => $value->format('H:i:s.u'),
                default => $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            };
        }
        if (is_array($value)) {
            $encoded = [];
            foreach ($value as $key => $item) {
                $encoded[$key] = $this->encodeValue($item, null);
            }

            return $encoded;
        }

        return $value;
    }

    /**
     * Project the demonstration cast into the access manifest, withholding every real identity.
     *
     * Only identities whose address already lives inside the reserved `.example` zone are exported;
     * every other identity — and any identity whose role cannot be declared within the manifest's
     * bounds — increments the withheld count and is skipped. Roles are included only when a declared
     * identity references them, with their area derived from the capabilities they grant. When no
     * identity qualifies at all an empty document is returned so the caller omits the file.
     *
     * @param   ExecutionContext  $context   Authenticated administrator the reads run as.
     * @param   string            $profile   Profile name the manifest is published under.
     * @param   string            $site      Site identifier the export runs against.
     * @param   int               $withheld  Running count of identities excluded from the export.
     *
     * @return  array<string, mixed>  Complete `kumwe.demo-access/v1` document, or empty when no
     *          identity qualifies.
     *
     * @since   2.0.0
     */
    private function accessDocument(ExecutionContext $context, string $profile, string $site, int &$withheld): array
    {
        $roles = $this->eligibleRoles($context);
        $emails = [];
        $referenced = [];
        $organizations = $this->exportOrganizations($roles, $emails, $referenced, $withheld);
        $staff = $this->exportStaff($context, $roles, $emails, $referenced, $withheld);
        if ($staff === [] && $organizations === []) {
            return [];
        }
        $declaredRoles = [];
        foreach ($referenced as $handle => $ignored) {
            $role = $roles[$handle];
            $declaredRoles[] = [
                'handle' => $handle,
                'label' => $this->stringOf($role, 'label'),
                'area' => $this->stringOf($role, 'area'),
                'capabilities' => $role['capabilities'],
            ];
        }

        return [
            'format' => 'kumwe.demo-access/v1',
            'profile' => $profile,
            'version' => 1,
            'site' => $site,
            'description' => 'Demonstration staff roles, portal organizations, and sign-in identities '
                . 'exported from a running installation. Only identities inside the reserved .example '
                . 'zone are included, and passwords are never part of this manifest; the provisioning '
                . 'command generates them at deployment time.',
            'roles' => $declaredRoles,
            'staff' => $staff,
            'organizations' => $organizations,
        ];
    }

    /**
     * Read every live role that can be declared by the access manifest, keyed by its handle.
     *
     * A role qualifies when its handle satisfies the manifest grammar, its label fits the bound, its
     * grants resolve to between one and thirty-two well-formed capabilities, and those capabilities
     * settle a single area: `administrator.access` marks a staff role, otherwise `portal.access`
     * marks a portal role. Roles conferring neither area cannot carry a manifest identity.
     *
     * @param   ExecutionContext  $context  Authenticated administrator the read runs as.
     *
     * @return  array<string, array{label: string, area: string, capabilities: list<string>}>  Declared
     *          role shape by role handle.
     *
     * @since   2.0.0
     */
    private function eligibleRoles(ExecutionContext $context): array
    {
        $roles = [];
        foreach ($this->access->roles($context) as $role) {
            $handle = $role['code'] ?? null;
            $label = $role['name'] ?? null;
            if (
                !is_string($handle)
                || preg_match(self::ACCESS_NAME_PATTERN, $handle) !== 1
                || !is_string($label)
                || trim($label) === ''
                || strlen($label) > 120
            ) {
                continue;
            }
            $capabilities = [];
            $grants = $role['grants'] ?? null;
            foreach (is_array($grants) ? $grants : [] as $grant) {
                $capability = is_array($grant) ? ($grant['capability'] ?? null) : null;
                if (is_string($capability) && preg_match(self::CAPABILITY_PATTERN, $capability) === 1) {
                    $capabilities[$capability] = true;
                }
            }
            $capabilities = array_keys($capabilities);
            sort($capabilities, SORT_STRING);
            if ($capabilities === [] || count($capabilities) > 32) {
                continue;
            }
            $area = in_array('administrator.access', $capabilities, true)
                ? 'administrator'
                : (in_array('portal.access', $capabilities, true) ? 'portal' : null);
            if ($area === null) {
                continue;
            }
            $roles[$handle] = ['label' => trim($label), 'area' => $area, 'capabilities' => $capabilities];
        }

        return $roles;
    }

    /**
     * Project every active portal organization and its qualifying members into the manifest.
     *
     * @param   array<string, array{label: string, area: string, capabilities: list<string>}>  $roles
     *          Declared role shapes by handle.
     * @param array<string, true> $emails Addresses already declared across the manifest.
     * @param array<string, true> $referenced Role handles referenced by declared identities.
     * @param int $withheld Running count of identities excluded from the export.
     *
     * @return  list<array<string, mixed>>  Manifest organization declarations.
     *
     * @since   2.0.0
     */
    private function exportOrganizations(array $roles, array &$emails, array &$referenced, int &$withheld): array
    {
        $overview = $this->security->overview($this->siteOf());
        $memberships = [];
        foreach ($this->listOf($overview, 'memberships') as $membership) {
            if (!is_array($membership) || ($membership['status'] ?? null) !== 'active') {
                continue;
            }
            $organization = $membership['organization_identifier'] ?? null;
            if (is_string($organization)) {
                $memberships[$organization][] = $membership;
            }
        }
        $organizations = [];
        foreach ($this->listOf($overview, 'organizations') as $organization) {
            if (!is_array($organization) || ($organization['status'] ?? null) !== 'active') {
                continue;
            }
            $identifier = $organization['identifier'] ?? null;
            $label = $organization['name'] ?? null;
            if (
                !is_string($identifier)
                || preg_match(self::ACCESS_NAME_PATTERN, $identifier) !== 1
                || !is_string($label)
                || trim($label) === ''
                || strlen($label) > 160
            ) {
                continue;
            }
            $workspace = 'general';
            $members = [];
            foreach ($memberships[$identifier] ?? [] as $membership) {
                $member = $this->exportIdentity($membership, $roles, 'portal', $emails, $referenced, $withheld);
                $full = count($members) >= FilesystemDemoManifestCatalog::MAXIMUM_ORGANIZATION_MEMBERS;
                if ($member === null || $full) {
                    continue;
                }
                $members[] = $member;
                foreach ($this->listOf($membership, 'workspaces') as $assigned) {
                    $handle = is_array($assigned) ? ($assigned['identifier'] ?? null) : null;
                    if (is_string($handle) && preg_match(self::ACCESS_NAME_PATTERN, $handle) === 1) {
                        $workspace = $handle;
                        break;
                    }
                }
            }
            if ($members === [] || count($organizations) >= FilesystemDemoManifestCatalog::MAXIMUM_ORGANIZATIONS) {
                continue;
            }
            $organizations[] = [
                'identifier' => $identifier,
                'label' => trim($label),
                'workspace' => $workspace,
                'members' => $members,
            ];
        }

        return $organizations;
    }

    /**
     * Project every qualifying administrator-side identity into the manifest's staff list.
     *
     * Identities already declared as organization members are not declared twice, and everyone
     * else must clear the same bar: an address inside `.example` and an eligible staff-area role.
     *
     * @param ExecutionContext $context Authenticated administrator the read runs as.
     * @param   array<string, array{label: string, area: string, capabilities: list<string>}>  $roles
     *          Declared role shapes by handle.
     * @param array<string, true> $emails Addresses already declared across the manifest.
     * @param array<string, true> $referenced Role handles referenced by declared identities.
     * @param int $withheld Running count of identities excluded from the export.
     *
     * @return  list<array<string, mixed>>  Manifest staff declarations.
     *
     * @since   2.0.0
     */
    private function exportStaff(
        ExecutionContext $context,
        array $roles,
        array &$emails,
        array &$referenced,
        int &$withheld,
    ): array {
        $staff = [];
        foreach ($this->access->users($context) as $user) {
            if (!is_array($user) || ($user['status'] ?? null) !== 'active') {
                ++$withheld;
                continue;
            }
            $email = $user['email'] ?? null;
            if (is_string($email) && isset($emails[strtolower($email)])) {
                continue;
            }
            $person = $this->exportIdentity($user, $roles, 'administrator', $emails, $referenced, $withheld);
            if ($person !== null && count($staff) < FilesystemDemoManifestCatalog::MAXIMUM_STAFF) {
                $staff[] = $person;
            }
        }

        return $staff;
    }

    /**
     * Project one live identity row into a manifest identity, or withhold it.
     *
     * @param array<array-key, mixed> $row Live user or membership row carrying email, display
     *          name, and assigned roles.
     * @param   array<string, array{label: string, area: string, capabilities: list<string>}>  $roles
     *          Declared role shapes by handle.
     * @param string $area Area the identity's role must belong to.
     * @param array<string, true> $emails Addresses already declared across the manifest.
     * @param array<string, true> $referenced Role handles referenced by declared identities.
     * @param int $withheld Running count of identities excluded from the export.
     *
     * @return  ?array{email: string, display_name: string, role: string}  Declared identity, or null
     *          when the identity was withheld.
     *
     * @since   2.0.0
     */
    private function exportIdentity(
        array $row,
        array $roles,
        string $area,
        array &$emails,
        array &$referenced,
        int &$withheld,
    ): ?array {
        $email = $row['email'] ?? null;
        $name = $row['display_name'] ?? null;
        $email = is_string($email) ? strtolower($email) : '';
        if (
            preg_match(self::EMAIL_PATTERN, $email) !== 1
            || isset($emails[$email])
            || !is_string($name)
            || trim($name) === ''
            || strlen($name) > 120
        ) {
            ++$withheld;

            return null;
        }
        $role = null;
        foreach ($this->listOf($row, 'roles') as $assigned) {
            $handle = is_array($assigned) ? ($assigned['code'] ?? null) : null;
            if (is_string($handle) && ($roles[$handle]['area'] ?? null) === $area) {
                $role = $handle;
                break;
            }
        }
        if ($role === null) {
            ++$withheld;

            return null;
        }
        $emails[$email] = true;
        $referenced[$role] = true;

        return ['email' => $email, 'display_name' => trim($name), 'role' => $role];
    }

    /**
     * Name the site the security overview is read for.
     *
     * The overview is read beside an authorized service walk, so the public-site selector is the same
     * site every other read in this exporter is scoped to.
     *
     * @return  string  Site identifier the export runs against.
     *
     * @since   2.0.0
     */
    private function siteOf(): string
    {
        return $this->configuration->publicSite;
    }

    /**
     * Read one required object-shaped member out of an index or document.
     *
     * @param   array<string, mixed>  $document  Document to read from.
     * @param   string                $key       Required member name.
     *
     * @return  array<string, mixed>  The member's object value, empty when it is an empty array.
     *
     * @throws  RuntimeException  When the member is absent or not object-shaped.
     *
     * @since   2.0.0
     */
    private function mapOf(array $document, string $key): array
    {
        $value = $document[$key] ?? null;
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new RuntimeException(sprintf('The exported document lacks the %s object.', $key));
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Read one list-shaped member out of a live read-model row, tolerating its absence.
     *
     * @param   array<array-key, mixed>  $document  Row to read from.
     * @param   string                   $key       Member name.
     *
     * @return  list<mixed>  The member's list value, or empty when absent or not a list.
     *
     * @since   2.0.0
     */
    private function listOf(array $document, string $key): array
    {
        $value = $document[$key] ?? null;

        return is_array($value) && array_is_list($value) ? $value : [];
    }

    /**
     * Read one required string field out of an exported declaration.
     *
     * @param   array<array-key, mixed>  $document  Declaration to read from.
     * @param   string                   $key       Required field name.
     *
     * @return  string  The field's string value.
     *
     * @throws  RuntimeException  When the field is absent or not a string.
     *
     * @since   2.0.0
     */
    private function stringOf(array $document, string $key): string
    {
        $value = $document[$key] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException(sprintf('The exported declaration lacks the %s field.', $key));
        }

        return $value;
    }

    /**
     * Read one required integer field out of an exported document.
     *
     * @param   array<string, mixed>  $document  Document to read from.
     * @param   string                $key       Required field name.
     *
     * @return  int  The field's integer value.
     *
     * @throws  RuntimeException  When the field is absent or not an integer.
     *
     * @since   2.0.0
     */
    private function intOf(array $document, string $key): int
    {
        $value = $document[$key] ?? null;
        if (!is_int($value)) {
            throw new RuntimeException(sprintf('The exported document lacks the %s count.', $key));
        }

        return $value;
    }
}
