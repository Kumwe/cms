<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Studio\Application\Projection\ContentProjectionBindingRepository;
use Kumwe\App\Studio\Application\Composition\ContentBlueprintBindingStore;
use Kumwe\App\Studio\Application\Host\StudioPersistenceRace;
use Kumwe\App\Studio\Domain\Projection\ContentBlueprintBinding;
use Kumwe\App\Studio\Domain\Projection\EntryCompositionOverrides;
use Kumwe\Producer\Canonical\CanonicalEncodingException;
use RuntimeException;
use stdClass;

/**
 * Doctrine reader for host-owned Content-to-Blueprint bindings and per-entry overrides.
 *
 * Both queries carry the server-resolved site in their predicate, so a UUID learned from another site
 * cannot cross the model-port boundary. Canonical override bytes are decoded as objects and handed to
 * the domain value, which revalidates the member and byte limits before anything reaches Studio.
 *
 * @since  2.0.0
 */
final readonly class DoctrineContentProjectionBindingRepository implements
    ContentProjectionBindingRepository,
    ContentBlueprintBindingStore
{
    /**
     * Bind reads to the configured connection and prefix-aware table compiler.
     *
     * @param  Connection  $connection  Installation database.
     * @param  TableNames  $tables      Prefix-aware physical table names.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $connection, private TableNames $tables)
    {
    }

    /**
     * Read one exact Content type version's Blueprint binding.
     *
     * @param   SiteContext  $site                Server-resolved site.
     * @param   string       $contentTypeId       Canonical Content type UUID.
     * @param   int          $contentTypeVersion  Exact published definition version.
     *
     * @return  ?ContentBlueprintBinding  Revalidated binding, or null when none is configured.
     *
     * @throws  \Doctrine\DBAL\Exception  When the database read fails.
     * @throws  RuntimeException  When stored binding data is malformed.
     *
     * @since   2.0.0
     */
    public function blueprint(
        SiteContext $site,
        string $contentTypeId,
        int $contentTypeVersion,
    ): ?ContentBlueprintBinding {
        $row = $this->connection->fetchAssociative(sprintf(
            'SELECT blueprint_id, blueprint_version, blueprint_revision, binding_revision '
            . 'FROM %s WHERE site_identifier = ? AND content_type_id = ? AND content_type_version = ?',
            $this->tables->quoted('studio_content_blueprint_bindings'),
        ), [$site->identifier(), $contentTypeId, $contentTypeVersion], [
            ParameterType::STRING,
            Types::GUID,
            ParameterType::INTEGER,
        ]);
        if ($row === false) {
            return null;
        }

        try {
            return new ContentBlueprintBinding(
                $site,
                $contentTypeId,
                $contentTypeVersion,
                self::string($row, 'blueprint_id'),
                self::string($row, 'blueprint_version'),
                self::nullableString($row, 'blueprint_revision'),
                self::integer($row, 'binding_revision'),
            );
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException('Stored Studio Blueprint binding metadata is invalid.', 0, $exception);
        }
    }

    /**
     * Insert one initial type-version binding inside the caller's transaction.
     *
     * @param   ContentBlueprintBinding  $binding  Exact immutable Content-to-Blueprint binding.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function add(ContentBlueprintBinding $binding): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A Studio Content binding write requires an active transaction.');
        }
        try {
            $this->connection->insert($this->tables->raw('studio_content_blueprint_bindings'), [
                'site_identifier' => $binding->site->identifier(),
                'content_type_id' => $binding->contentTypeId,
                'content_type_version' => $binding->contentTypeVersion,
                'blueprint_id' => $binding->blueprintId,
                'blueprint_version' => $binding->blueprintVersion,
                'blueprint_revision' => $binding->blueprintRevision,
                'binding_revision' => $binding->revision,
            ], ['content_type_id' => Types::GUID]);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $exception) {
            throw new StudioPersistenceRace('A Studio Content binding was concurrently inserted.', 0, $exception);
        }
    }

    /**
     * Read one Content entry's canonical composition override object.
     *
     * @param   SiteContext  $site     Server-resolved site.
     * @param   string       $entryId  Canonical Content entry UUID.
     *
     * @return  ?EntryCompositionOverrides  Revalidated overrides, or null when the entry inherits completely.
     *
     * @throws  \Doctrine\DBAL\Exception  When the database read fails.
     * @throws  RuntimeException  When stored JSON is not an object or metadata is malformed.
     *
     * @since   2.0.0
     */
    public function overrides(SiteContext $site, string $entryId): ?EntryCompositionOverrides
    {
        $row = $this->connection->fetchAssociative(sprintf(
            'SELECT override_values, override_revision FROM %s '
            . 'WHERE site_identifier = ? AND content_entry_id = ?',
            $this->tables->quoted('studio_entry_composition_overrides'),
        ), [$site->identifier(), $entryId], [ParameterType::STRING, Types::GUID]);
        if ($row === false) {
            return null;
        }
        $raw = $row['override_values'] ?? null;
        if ($raw instanceof stdClass) {
            $values = $raw;
        } elseif (is_string($raw)) {
            $values = json_decode($raw, false);
        } else {
            $values = null;
        }
        if (!$values instanceof stdClass) {
            throw new RuntimeException('Stored Studio entry overrides are not a JSON object.');
        }

        try {
            return new EntryCompositionOverrides(
                $site,
                $entryId,
                $values,
                self::integer($row, 'override_revision'),
            );
        } catch (CanonicalEncodingException | InvalidArgumentException $exception) {
            throw new RuntimeException('Stored Studio entry override metadata is invalid.', 0, $exception);
        }
    }

    /**
     * Read one required non-empty string column.
     *
     * @param   array<string, mixed>  $row  Database row.
     * @param   string                $key  Column name.
     *
     * @return  string  Stored non-empty string.
     *
     * @throws  RuntimeException  When the value is absent or not a non-empty string.
     *
     * @since   2.0.0
     */
    private static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Stored Studio binding column %s is invalid.', $key));
        }

        return $value;
    }

    /**
     * Read one optional non-empty string column.
     *
     * @param   array<string, mixed>  $row  Database row.
     * @param   string                $key  Column name.
     *
     * @return  ?string  Null or the stored non-empty string.
     *
     * @throws  RuntimeException  When a present value is not a non-empty string.
     *
     * @since   2.0.0
     */
    private static function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Stored Studio binding column %s is invalid.', $key));
        }

        return $value;
    }

    /**
     * Read one positive integer column regardless of the driver's scalar representation.
     *
     * @param   array<string, mixed>  $row  Database row.
     * @param   string                $key  Column name.
     *
     * @return  int  Stored positive integer.
     *
     * @throws  RuntimeException  When the value is not a canonical positive integer.
     *
     * @since   2.0.0
     */
    private static function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (is_int($integer)) {
                return $integer;
            }
        }

        throw new RuntimeException(sprintf('Stored Studio binding column %s is invalid.', $key));
    }
}
