<?php

declare(strict_types=1);

namespace Kumwe\App\Localization\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\Localization\Application\MessageOverrideRecord;
use Kumwe\Localization\Application\MessageOverrideRepository;
use Kumwe\Localization\Application\MessageOverrideStore;
use Kumwe\Localization\Domain\LocaleTag;
use Kumwe\Localization\Domain\MessageCatalogueLayer;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Serves the two administered override layers from the `message_overrides` table.
 *
 * This is what turns the upper half of the chain from a resolver with nothing behind it into wording
 * an operator owns: a stored row beats the compiled catalogue for exactly one identifier at exactly
 * one locale, so relabelling "Client" as "Patient" changes that word and leaves every other message
 * — and every later core release's improvement to them — untouched.
 *
 * Two properties are load-bearing and are why this reads the way it does. A scope is fetched **whole**
 * in one statement, because the render path resolves hundreds of messages and a lookup per message
 * would make the override chain a scale defect wearing a feature's name. `CatalogueTranslator` holds
 * each locale-and-scope result for one locale unit of work, so this repository deliberately rereads on a
 * later request and an administered change becomes visible without restarting a long-lived worker.
 *
 * A missing table answers "no overrides" rather than raising. That is deliberate rather than lenient:
 * the recovery surfaces render before `database:migrate` has run, and an interface that cannot draw
 * its own recovery screen because nobody has overridden a word yet would be a worse failure than the
 * absent customisation.
 *
 * Both faces of the override contract are served here rather than by two adapters, because there is
 * one table and one row shape behind them: the render path's bounded map, and the administration
 * surface's list-and-write view of the same rows.
 *
 * @since  2.0.0
 */
final class DoctrineMessageOverrideRepository implements MessageOverrideRepository, MessageOverrideStore
{
    /**
     * Whether the override table exists, resolved once and then remembered.
     *
     * @var    ?bool
     * @since  2.0.0
     */
    private ?bool $installed = null;

    /**
     * Bind the repository to the connection and the prefixed table map.
     *
     * @param  Connection  $database  Connection the override rows are read from.
     * @param  TableNames  $tables    Resolver applying the configured prefix to table names.
     *
     * @since  2.0.0
     */
    public function __construct(
        private readonly Connection $database,
        private readonly TableNames $tables,
    ) {
    }

    /**
     * Read every override a site has recorded for one locale.
     *
     * @param   string     $site    Site identifier the overrides belong to.
     * @param   LocaleTag  $locale  Exact locale to read; no fallback is applied here, because the
     *          fallback walk belongs to the translator and not to a store.
     *
     * @return  array<string, string>  ICU patterns keyed by message identifier, empty when the site
     *          has overridden nothing at this locale.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the read.
     *
     * @since   2.0.0
     */
    public function siteOverrides(string $site, LocaleTag $locale): array
    {
        return $this->read('site', $site, null, $locale);
    }

    /**
     * Read every override an organization within a site has recorded for one locale.
     *
     * @param   string     $site          Site the organization belongs to.
     * @param   string     $organization  Organization identifier the overrides belong to.
     * @param   LocaleTag  $locale        Exact locale to read.
     *
     * @return  array<string, string>  ICU patterns keyed by message identifier, empty when the
     *          organization has overridden nothing at this locale.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the read.
     *
     * @since   2.0.0
     */
    public function organizationOverrides(string $site, string $organization, LocaleTag $locale): array
    {
        return $this->read('organization', $site, $organization, $locale);
    }

    /**
     * Fetch one scope's whole override map.
     *
     * @param   string     $level         Either `site` or `organization`, matching the stored level.
     * @param   string     $site          Site the scope belongs to.
     * @param   ?string    $organization  Organization within that site, or null for the site level.
     * @param   LocaleTag  $locale        Exact locale to read.
     *
     * @return  array<string, string>  Patterns keyed by message identifier, in stored order.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the read.
     *
     * @since   2.0.0
     */
    private function read(string $level, string $site, ?string $organization, LocaleTag $locale): array
    {
        $tag = $locale->toString();
        if (!$this->tableInstalled()) {
            return [];
        }

        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT message_identifier, pattern FROM %s '
            . 'WHERE scope_level = ? AND site_identifier = ? AND locale_tag = ? AND organization_identifier = ? '
            . 'ORDER BY message_identifier',
            $this->tables->quoted('message_overrides'),
        ), [$level, $site, $tag, $organization ?? '']);

        $overrides = [];
        foreach ($rows as $row) {
            $identifier = $row['message_identifier'] ?? null;
            $pattern = $row['pattern'] ?? null;
            if (is_string($identifier) && is_string($pattern)) {
                $overrides[$identifier] = $pattern;
            }
        }

        return $overrides;
    }

    /**
     * Lock the site's durable identity so an empty override scope can still be serialized.
     *
     * A row lock on existing overrides cannot protect the transition from 499 to 500 when the scope is
     * initially empty or two writers add different identifiers. Every override already references a
     * `sites` row, so locking that parent is portable across the three engines and requires no auxiliary
     * quota table. The application service opens the surrounding transaction before calling this method.
     *
     * @param   string  $site  Site whose wording mutation is being serialized.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the site does not exist.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the locking read.
     *
     * @since   2.0.0
     */
    public function lockSite(string $site): void
    {
        $locked = $this->database->fetchOne(sprintf(
            'SELECT identifier FROM %s WHERE identifier = ? FOR UPDATE',
            $this->tables->quoted('sites'),
        ), [$site]);
        if (!is_string($locked) || $locked === '') {
            throw new RuntimeException('Message wording cannot be changed for a site that does not exist.');
        }
    }

    /**
     * List every override stored for one scope, with the bookkeeping an administrator reads.
     *
     * @param   MessageCatalogueLayer  $layer         Administered layer to list, `Site` or `Organization`.
     * @param   string                 $site          Site the scope belongs to.
     * @param   ?string                $organization  Organization within that site, or null at site level.
     * @param   ?LocaleTag             $locale        Restrict to one locale, or null for every locale.
     *
     * @return  list<MessageOverrideRecord>  Stored overrides ordered by locale and then identifier.
     *
     * @throws  RuntimeException  When a stored row carries an unreadable layer or instant.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the read.
     *
     * @since   2.0.0
     */
    public function overrides(
        MessageCatalogueLayer $layer,
        string $site,
        ?string $organization = null,
        ?LocaleTag $locale = null,
    ): array {
        if (!$this->tableInstalled()) {
            return [];
        }

        $parameters = [$layer->value, $site, $organization ?? ''];
        $sql = sprintf(
            'SELECT scope_level, site_identifier, organization_identifier, locale_tag, message_identifier, '
            . 'pattern, updated_at FROM %s WHERE scope_level = ? AND site_identifier = ? AND '
            . 'organization_identifier = ?',
            $this->tables->quoted('message_overrides'),
        );
        if ($locale instanceof LocaleTag) {
            $sql .= ' AND locale_tag = ?';
            $parameters[] = $locale->toString();
        }
        $sql .= ' ORDER BY locale_tag, message_identifier';

        $records = [];
        foreach ($this->database->fetchAllAssociative($sql, $parameters) as $row) {
            $records[] = $this->hydrate($row);
        }

        return $records;
    }

    /**
     * Store or replace the wording one layer carries for one identifier at one locale.
     *
     * The application service holds the site's parent-row lock around this call. Update first keeps the
     * common replacement path to one write, but zero affected rows is not evidence of absence: MySQL and
     * MariaDB report zero when both values already equal the requested values. A portable keyed existence
     * read therefore distinguishes that no-op from a missing row before the insert path is selected. The
     * parent lock keeps the decision and insert inside the same serialized site transaction.
     *
     * @param   MessageOverrideRecord  $override  The override to write.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the write.
     *
     * @since   2.0.0
     */
    public function put(MessageOverrideRecord $override): void
    {
        $updated = $this->database->executeStatement(sprintf(
            'UPDATE %s SET pattern = ?, updated_at = ? WHERE scope_level = ? AND site_identifier = ? '
            . 'AND organization_identifier = ? AND locale_tag = ? AND message_identifier = ?',
            $this->tables->quoted('message_overrides'),
        ), [
            $override->pattern,
            $override->updatedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            $override->layer->value,
            $override->site,
            $override->organization ?? '',
            $override->locale,
            $override->identifier,
        ]);

        if ($updated !== 0 || $this->overrideExists($override)) {
            return;
        }

        $this->database->insert($this->tables->raw('message_overrides'), [
            'id' => Uuid::uuid7()->toString(),
            'scope_level' => $override->layer->value,
            'site_identifier' => $override->site,
            'organization_identifier' => $override->organization ?? '',
            'locale_tag' => $override->locale,
            'message_identifier' => $override->identifier,
            'pattern' => $override->pattern,
            'updated_at' => $override->updatedAt,
        ], ['updated_at' => Types::DATETIME_IMMUTABLE]);
    }

    /**
     * Distinguish a no-op update from a missing override using the portable unique identity.
     *
     * @param   MessageOverrideRecord  $override  Override whose identity may already be stored.
     *
     * @return  bool  True when the unique override row exists, regardless of its current values.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the existence read.
     *
     * @since   2.0.0
     */
    private function overrideExists(MessageOverrideRecord $override): bool
    {
        return $this->database->fetchOne(sprintf(
            'SELECT 1 FROM %s WHERE scope_level = ? AND site_identifier = ? '
            . 'AND organization_identifier = ? AND locale_tag = ? AND message_identifier = ?',
            $this->tables->quoted('message_overrides'),
        ), [
            $override->layer->value,
            $override->site,
            $override->organization ?? '',
            $override->locale,
            $override->identifier,
        ]) !== false;
    }

    /**
     * Withdraw one override so the layer below it answers again.
     *
     * @param   MessageCatalogueLayer  $layer         Administered layer the override sits in.
     * @param   string                 $site          Site the scope belongs to.
     * @param   ?string                $organization  Organization within that site, or null at site level.
     * @param   LocaleTag              $locale        Locale the override applies to.
     * @param   string                 $identifier    Message identifier to stop overriding.
     *
     * @return  bool  True when a row was removed.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the write.
     *
     * @since   2.0.0
     */
    public function remove(
        MessageCatalogueLayer $layer,
        string $site,
        ?string $organization,
        LocaleTag $locale,
        string $identifier,
    ): bool {
        $removed = $this->database->executeStatement(sprintf(
            'DELETE FROM %s WHERE scope_level = ? AND site_identifier = ? AND locale_tag = ? '
            . 'AND message_identifier = ? AND organization_identifier = ?',
            $this->tables->quoted('message_overrides'),
        ), [$layer->value, $site, $locale->toString(), $identifier, $organization ?? '']);
        return $removed > 0;
    }

    /**
     * Turn one stored row into the record an administration surface reads.
     *
     * @param   array<string, mixed>  $row  Row as the driver returned it.
     *
     * @return  MessageOverrideRecord  The hydrated override.
     *
     * @throws  RuntimeException  When the row carries an unreadable layer, text column or instant.
     *
     * @since   2.0.0
     */
    private function hydrate(array $row): MessageOverrideRecord
    {
        $level = $row['scope_level'] ?? null;
        $site = $row['site_identifier'] ?? null;
        $locale = $row['locale_tag'] ?? null;
        $identifier = $row['message_identifier'] ?? null;
        $pattern = $row['pattern'] ?? null;
        $updated = $row['updated_at'] ?? null;
        $organization = $row['organization_identifier'] ?? null;
        if (
            !is_string($level)
            || !is_string($site)
            || !is_string($locale)
            || !is_string($identifier)
            || !is_string($pattern)
            || !is_string($updated)
            || !is_string($organization)
        ) {
            throw new RuntimeException('A stored message override row is incomplete.');
        }
        $layer = MessageCatalogueLayer::tryFrom($level);
        if ($layer !== MessageCatalogueLayer::Site && $layer !== MessageCatalogueLayer::Organization) {
            throw new RuntimeException('A stored message override names a layer that is not administered.');
        }
        try {
            // The three engines spell a stored timestamp differently — with microseconds, with a
            // trailing offset, or without either — so the value is parsed rather than pattern-matched.
            $at = new DateTimeImmutable($updated, new DateTimeZone('UTC'));
        } catch (\Exception $unreadable) {
            throw new RuntimeException('A stored message override carries an unreadable instant.', 0, $unreadable);
        }

        return new MessageOverrideRecord(
            $layer,
            $site,
            $organization === '' ? null : $organization,
            $locale,
            $identifier,
            $pattern,
            $at,
        );
    }

    /**
     * Decide once whether this installation has the override table yet.
     *
     * @return  bool  True when the table exists and may be read.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the introspection.
     *
     * @since   2.0.0
     */
    private function tableInstalled(): bool
    {
        return $this->installed ??= $this->database->createSchemaManager()->tablesExist([
            $this->tables->raw('message_overrides'),
        ]);
    }
}
