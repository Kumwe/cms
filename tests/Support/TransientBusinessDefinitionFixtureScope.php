<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use Doctrine\DBAL\Connection;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\App\BusinessDefinition\Domain\DefinitionStatus;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaInstallationRepository;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Withdraws business definitions created by one integration-suite process without erasing their data.
 *
 * Integration tests deliberately mint definition identities because their record fixtures must not collide
 * with an earlier run. Those definitions used to remain live after PHPUnit exited, so the third suite pass
 * reached the per-site OpenAPI definition bound even though only test data was accumulating. This scope
 * snapshots the migrated catalog before the first test writes to it and, at process shutdown, retires only
 * the run-unique published heads added afterwards. A short explicit set of replay and backup fixtures remains
 * live because later processes intentionally reuse those identities. For everything else, the installation
 * is withheld before the matching version is rejected, and both writes share one transaction, so no
 * committed state can expose an active schema whose definition has already been withdrawn. Physical tables
 * and immutable version history remain intact for diagnosis; the next process simply no longer admits them
 * to live record or generated-contract discovery.
 *
 * @since  2.0.0
 */
final readonly class TransientBusinessDefinitionFixtureScope
{
    /**
     * Definition identities present before this integration process started creating fixtures.
     *
     * @var    array<string, true>
     * @since  2.0.0
     */
    private array $baseline;

    /**
     * Snapshot the migrated catalog and retain the production lifecycle ports needed to withdraw additions.
     *
     * @param  Connection                            $database       Connection used only to discover catalog heads.
     * @param  TableNames                            $tables         Validated physical table-name compiler.
     * @param  BusinessDefinitionRepository          $definitions   Version lifecycle store.
     * @param  BusinessSchemaInstallationRepository  $installations Physical installation lifecycle store.
     * @param  TransactionManager                    $transactions   Atomic boundary joining both lifecycle writes.
     * @param  ClockInterface                        $clock          Timestamp source shared with production services.
     *
     * @throws  RuntimeException  When the migrated catalog returns a malformed definition identity.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private BusinessDefinitionRepository $definitions,
        private BusinessSchemaInstallationRepository $installations,
        private TransactionManager $transactions,
        private ClockInterface $clock,
    ) {
        $baseline = [];
        $identifiers = $this->database->fetchFirstColumn(sprintf(
            'SELECT id FROM %s ORDER BY id',
            $this->tables->raw('business_definitions'),
        ));
        foreach ($identifiers as $identifier) {
            if (
                !is_string($identifier)
                || !Uuid::isValid($identifier)
            ) {
                throw new RuntimeException('The integration definition baseline contains an invalid identity.');
            }
            $baseline[$identifier] = true;
        }
        $this->baseline = $baseline;
    }

    /**
     * Withdraw every published definition added after the snapshot, retaining its tables and history.
     *
     * Draft-only heads cannot enter a generated contract and need no installation transition. Published
     * additions are handled in stable site/identity order. Active installations become disabled, in-flight
     * installations become preserved, and failed or already withheld rows stay untouched; the current
     * publication is then rejected through the ordinary repository lifecycle. Re-running this method is
     * harmless because rejected heads and withheld installations already describe the target state.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a newly created catalog row is malformed.
     *
     * @since  2.0.0
     */
    public function withdraw(): void
    {
        // Database-loss drills deliberately terminate every session except their own. The process-wide
        // fixture scope may have been created by an earlier container, so its otherwise idle DBAL session
        // can be one of those victims. Cleanup owns no transaction yet: discard that possibly stale session
        // before the first read and let DBAL establish one fresh connection for the complete atomic withdrawal.
        // A failure after work begins is still surfaced; this is a lifecycle boundary, not a retry loop.
        $this->database->close();

        /** @var list<array{id: string, site: string, version: int, status: DefinitionStatus}> $created */
        $created = [];
        /** @var array<string, true> $persistent */
        $persistent = array_fill_keys(NeutralBusinessFixture::persistentDefinitionHandles(), true);
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT id, handle, site_identifier, published_version, publication_state FROM %s '
            . 'ORDER BY site_identifier, id',
            $this->tables->raw('business_definitions'),
        ));
        foreach ($rows as $row) {
            $identifier = $row['id'] ?? null;
            if (
                !is_string($identifier)
                || !Uuid::isValid($identifier)
            ) {
                throw new RuntimeException('An integration business-definition row has an invalid identity.');
            }
            if (isset($this->baseline[$identifier])) {
                continue;
            }
            $handle = $row['handle'] ?? null;
            if (!is_string($handle) || $handle === '') {
                throw new RuntimeException('An integration business-definition row has an invalid handle.');
            }
            if (isset($persistent[$handle])) {
                continue;
            }
            $version = $this->publishedVersion($row['published_version'] ?? null);
            if ($version === null) {
                continue;
            }
            $site = $row['site_identifier'] ?? null;
            $status = $row['publication_state'] ?? null;
            if (
                !is_string($site)
                || $site === ''
                || !is_string($status)
                || DefinitionStatus::tryFrom($status) === null
            ) {
                throw new RuntimeException('An integration business-definition head is malformed.');
            }
            $created[] = [
                'id' => $identifier,
                'site' => $site,
                'version' => $version,
                'status' => DefinitionStatus::from($status),
            ];
        }
        if ($created === []) {
            return;
        }

        $at = $this->clock->now();
        $this->transactions->transactional(function () use ($created, $at): void {
            foreach ($created as $definition) {
                $installation = $this->installations->find($definition['id']);
                if ($installation?->status === SchemaInstallationStatus::Active) {
                    $this->installations->save($installation->disable($at));
                } elseif ($installation?->status === SchemaInstallationStatus::Installing) {
                    $this->installations->save($installation->preserve($at));
                }
                if ($definition['status'] !== DefinitionStatus::Rejected) {
                    $this->definitions->changeStatus(
                        SiteContext::fromString($definition['site']),
                        $definition['id'],
                        $definition['version'],
                        DefinitionStatus::Rejected,
                        $at,
                    );
                }
            }
        });
    }

    /**
     * Normalize a nullable driver value into a positive published version.
     *
     * @param   mixed  $value  Raw `published_version` value returned by Doctrine DBAL.
     *
     * @return  ?int  Positive version, or null for a draft-only catalog head.
     *
     * @throws  RuntimeException  When a non-null value is not a positive integer.
     *
     * @since  2.0.0
     */
    private function publishedVersion(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 1) {
            throw new RuntimeException('An integration business-definition head has an invalid published version.');
        }

        return $value;
    }
}
