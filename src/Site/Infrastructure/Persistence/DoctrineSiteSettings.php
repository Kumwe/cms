<?php

declare(strict_types=1);

namespace Kumwe\App\Site\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Presentation\Application\SitePresentation;
use Kumwe\App\Site\Application\SiteSettings;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Authoritative settings store: one key-value row per setting in the prefixed `site_settings` table.
 *
 * Settings are operator input that ends up in routing decisions, page chrome and inline CSS, so this
 * adapter validates in both directions rather than trusting the table. A read starts from a complete
 * set of defaults and overlays only the keys it recognises, so a row left behind by an older release
 * is ignored and a caller always receives every key; the presentation value is re-validated on the way
 * out as well, which is what stops a row edited directly in SQL from reaching a page. A write merges
 * the caller's keys over the current document, validates the result as a whole, proves the nominated
 * homepage is a published core Page of this site and the nominated primary menu is a menu this site
 * owns, then rewrites every row and records the audit entry inside one transaction. Each row carries a
 * version counter that is bumped on write, so a concurrent edit stays traceable afterwards. Nothing
 * here caches — `CachedSiteSettings` fronts this class in production.
 *
 * @since  2.0.0
 */
final readonly class DoctrineSiteSettings implements SiteSettings
{
    /**
     * Bind the store to the connection, table map and collaborators a settings write needs.
     *
     * @param  Connection            $database       DBAL connection the settings rows are read and written on.
     * @param  TableNames            $tables         Resolver applying the configured prefix to table names.
     * @param  TransactionManager    $transactions   Scope committing the rewritten rows with their audit entry.
     * @param  AuditRecorder         $audit          Sink recording which keys an actor changed.
     * @param  ClockInterface        $clock          Source of the update timestamp written to each row.
     * @param  AuthorizationGateway  $authorization  Policy proving `settings.manage` before a managed read
     *         or any write.
     * @param  ?ContentService       $content        Reader used to prove the nominated homepage is a
     *         published content entry; null skips that check, as a minimal wiring wants.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private TransactionManager $transactions,
        private AuditRecorder $audit,
        private ClockInterface $clock,
        private AuthorizationGateway $authorization,
        private ?ContentService $content = null,
    ) {
    }

    /**
     * Read every stored setting, overlaid on the shipped defaults.
     *
     * No authorization runs here: this is the read the public request path depends on. A row whose key
     * is absent from the storage map is skipped rather than surfaced, so an unrecognised row can never
     * introduce a setting, and the presentation value passes through validation again on the way out.
     *
     * @return  array<string, mixed>  Every public setting key, defaults included for keys never stored.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the read.
     * @throws  RuntimeException  When a stored setting value is not valid JSON.
     * @throws  InvalidArgumentException  When the stored presentation settings fail validation.
     *
     * @since   2.0.0
     */
    public function current(): array
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT setting_key, setting_value FROM %s',
            $this->tables->quoted('site_settings'),
        ));
        $settings = [
            'site_name' => 'Kumwe',
            'homepage_content_id' => null,
            'homepage_slug' => 'home',
            'default_locale' => 'en',
            'timezone' => 'UTC',
            'search_indexing_enabled' => true,
            'presentation' => SitePresentation::defaults(),
        ];

        foreach ($rows as $row) {
            $key = $row['setting_key'] ?? null;
            $value = $this->decode($row['setting_value'] ?? null);

            if (!is_string($key) || !array_key_exists($key, self::keyMap())) {
                continue;
            }

            $publicKey = self::keyMap()[$key];
            $settings[$publicKey] = $publicKey === 'presentation'
                ? SitePresentation::from($value)->toArray()
                : $value;
        }

        return $settings;
    }

    /**
     * Read the settings document for an actor holding `settings.manage` on this site.
     *
     * The content and the failure modes are those of `current()`; what this adds is the capability
     * check, which is what lets an administration surface read settings that the public read exposes
     * to nobody in particular.
     *
     * @param   ExecutionContext  $context  Actor and site the read runs as.
     *
     * @return  array<string, mixed>  Every public setting key, defaults included for keys never stored.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage settings.
     *
     * @since   2.0.0
     */
    public function managed(ExecutionContext $context): array
    {
        $this->authorize($context);

        return $this->current();
    }

    /**
     * Store a new site name and homepage slug, leaving every other setting as it stands.
     *
     * The other keys are read back from the current document and passed through `updateAll()`
     * unchanged, so the whole document is still validated, audited and versioned as a single write.
     *
     * @param   ExecutionContext  $context       Actor and site the write runs as.
     * @param   string            $siteName      Display name shown in page chrome and titles.
     * @param   string            $homepageSlug  Slug the homepage falls back to when no content id is set.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage settings.
     * @throws  InvalidArgumentException  When the name or the slug fails validation.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects a read or a write.
     *
     * @since   2.0.0
     */
    public function update(ExecutionContext $context, string $siteName, string $homepageSlug): void
    {
        $this->authorize($context);
        $current = $this->current();
        $this->updateAll($context, [
            'site_name' => $siteName,
            'homepage_content_id' => $current['homepage_content_id'],
            'homepage_slug' => $homepageSlug,
            'default_locale' => $current['default_locale'],
            'timezone' => $current['timezone'],
            'search_indexing_enabled' => $current['search_indexing_enabled'],
            'presentation' => $current['presentation'],
        ]);
    }

    /**
     * Merge the supplied keys over the current document, validate all of it, and rewrite the rows.
     *
     * Two referential checks run that value validation cannot express: a nominated homepage must be a
     * published core Page readable in this site's context, and the nominated primary menu must be a
     * menu this site owns — either check failing aborts before anything is written. The row rewrite
     * and the audit entry then share one transaction, so a settings change is never recorded without
     * being stored, and every row's version counter is bumped whether or not its value moved.
     *
     * @param   ExecutionContext      $context   Actor and site the write runs as.
     * @param   array<string, mixed>  $settings  Public setting keys to change, merged over the current document.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage settings.
     * @throws  InvalidArgumentException  When a value fails validation, the homepage is not a published
     *          content entry of this site, or the primary menu is not a menu this site owns.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects a read or a write.
     *
     * @since   2.0.0
     */
    public function updateAll(ExecutionContext $context, array $settings): void
    {
        $this->authorize($context);
        $actorId = $context->actorId();
        $updatedBy = $context->principal()?->subject();
        $normalized = $this->validate(array_replace($this->current(), $settings));
        $homepageId = $normalized['homepage_content_id'];
        if (is_string($homepageId) && $this->content !== null) {
            $homepage = $this->content->publishedById($homepageId, $context->site());
            if ($homepage === null) {
                throw new InvalidArgumentException(
                    'The homepage must be a published content entry for this site inside its publication window.',
                );
            }
        }
        $presentation = $normalized['presentation'];
        if (!is_array($presentation) || !is_string($presentation['primary_menu'] ?? null)) {
            throw new InvalidArgumentException('The primary menu setting is invalid.');
        }
        $menuId = $this->database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE handle = ?',
            $this->tables->quoted('navigation_menus'),
        ), [$presentation['primary_menu']]);
        $owned = is_string($menuId) && $menuId !== '' && $this->database->fetchOne(sprintf(
            'SELECT resource_id FROM %s WHERE resource_type = ? AND resource_id = ? AND site_identifier = ?',
            $this->tables->quoted('resource_site_ownership'),
        ), ['menu', $menuId, $context->site()->identifier()]) !== false;
        if (!$owned) {
            throw new InvalidArgumentException('The primary menu must be a managed menu for this site.');
        }

        $this->transactions->transactional(function () use ($actorId, $normalized, $updatedBy): void {
            foreach (self::keyMap() as $storageKey => $publicKey) {
                $this->upsert($storageKey, $normalized[$publicKey], $updatedBy);
            }

            $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $this->clock->now(),
                $actorId,
                'site.settings.update',
                'site',
                'global',
                'success',
                ['changed_keys' => array_keys($normalized)],
            ));
        });
    }

    /**
     * Prove the actor may manage this site's settings, before any other work runs.
     *
     * @param   ExecutionContext  $context  Actor and site to check `settings.manage` for.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses the actor.
     *
     * @since   2.0.0
     */
    private function authorize(ExecutionContext $context): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('settings.manage'),
            AuthorizationResource::item('site', $context->site()->identifier()),
        );
    }

    /**
     * Normalise a merged settings document and reject anything the site cannot safely run with.
     *
     * Values are canonicalised as well as checked — the homepage id is lowercased and an empty one
     * becomes null, an underscore locale is rewritten with a hyphen, the indexing flag is coerced from
     * whatever a form or JSON body supplied — so the rows receive one spelling per value rather than
     * whatever the caller typed.
     *
     * @param   array<string, mixed>  $settings  Full document to check, current values already merged in.
     *
     * @return  array<string, mixed>  The seven public keys, normalised and safe to store.
     *
     * @throws  InvalidArgumentException  When a value is absent, of the wrong type, or outside the
     *          shape its setting allows.
     *
     * @since   2.0.0
     */
    private function validate(array $settings): array
    {
        $siteName = $this->stringSetting($settings, 'site_name');
        $homepageContentId = $settings['homepage_content_id'] ?? null;
        $homepageSlug = $this->stringSetting($settings, 'homepage_slug');
        $locale = $this->stringSetting($settings, 'default_locale');
        $timezone = $this->stringSetting($settings, 'timezone');

        if ($siteName === '' || mb_strlen($siteName) > 160) {
            throw new InvalidArgumentException('The site name must contain 1 to 160 characters.');
        }

        if ($homepageContentId === '') {
            $homepageContentId = null;
        }
        if ($homepageContentId !== null && (!is_string($homepageContentId) || !Uuid::isValid($homepageContentId))) {
            throw new InvalidArgumentException('The homepage content identifier must be a canonical UUID or null.');
        }

        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $homepageSlug) !== 1) {
            throw new InvalidArgumentException('The homepage slug is invalid.');
        }

        if (preg_match('/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/D', $locale) !== 1) {
            throw new InvalidArgumentException('The default locale is invalid.');
        }

        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('The timezone is invalid.');
        }

        return [
            'site_name' => $siteName,
            'homepage_content_id' => $homepageContentId === null ? null : strtolower($homepageContentId),
            'homepage_slug' => $homepageSlug,
            'default_locale' => str_replace('_', '-', $locale),
            'timezone' => $timezone,
            'search_indexing_enabled' => filter_var(
                $settings['search_indexing_enabled'] ?? true,
                FILTER_VALIDATE_BOOL,
            ),
            'presentation' => SitePresentation::from($settings['presentation'] ?? null)->toArray(),
        ];
    }

    /**
     * Read one setting that must be a string, trimmed of surrounding whitespace.
     *
     * @param   array<string, mixed>  $settings  Document to read from.
     * @param   string                $key       Public setting key to read.
     *
     * @return  string  The trimmed value, empty when the setting held nothing but whitespace.
     *
     * @throws  InvalidArgumentException  When the setting is absent or is not a string.
     *
     * @since   2.0.0
     */
    private function stringSetting(array $settings, string $key): string
    {
        $value = $settings[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('The %s setting must be a string.', $key));
        }

        return trim($value);
    }

    /**
     * Write one settings row, inserting it when the key has never been stored and bumping its version.
     *
     * The value is bound as a JSON column and the timestamp as an immutable date, so Doctrine encodes
     * both and the row keeps the structure the setting actually has instead of a pre-encoded string.
     * A PHP null is bound as the JSON text `null` so the non-nullable column receives the JSON literal
     * rather than an SQL NULL parameter.
     *
     * @param   string   $key        Storage key of the setting, in its dotted `site.*` form.
     * @param   mixed    $value      Decoded value to store; Doctrine encodes it as JSON.
     * @param   ?string  $updatedBy  Human subject UUID, or null when a system identity performed the write.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the lookup, the insert, or the update.
     *
     * @since   2.0.0
     */
    private function upsert(string $key, mixed $value, ?string $updatedBy): void
    {
        $table = $this->tables->raw('site_settings');
        $exists = $this->database->fetchOne(
            sprintf('SELECT setting_key FROM %s WHERE setting_key = ?', $this->tables->quoted('site_settings')),
            [$key],
        );
        $storedValue = $value === null ? 'null' : $value;
        $valueType = $value === null ? Types::STRING : Types::JSON;
        $values = [
            'setting_value' => $storedValue,
            'updated_by' => $updatedBy,
            'updated_at' => $this->clock->now(),
        ];
        $types = [
            'setting_value' => $valueType,
            'updated_by' => Types::GUID,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ];

        if ($exists === false) {
            $this->database->insert($table, ['setting_key' => $key, 'version' => 1] + $values, $types);
            return;
        }

        $this->database->executeStatement(sprintf(
            'UPDATE %s SET setting_value = ?, updated_by = ?, updated_at = ?, version = version + 1 '
            . 'WHERE setting_key = ?',
            $this->tables->quoted('site_settings'),
        ), [$storedValue, $updatedBy, $this->clock->now(), $key], [
            $valueType,
            Types::GUID,
            Types::DATETIME_IMMUTABLE,
            Types::STRING,
        ]);
    }

    /**
     * Decode one stored setting value, tolerating a driver that has already decoded it.
     *
     * Some platforms hand a JSON column back as a decoded structure and some as the raw string, so a
     * non-string value is passed through untouched rather than decoded a second time.
     *
     * @param   mixed  $stored  Value exactly as the driver returned it.
     *
     * @return  mixed  The decoded value, or the input unchanged when it was not a string.
     *
     * @throws  RuntimeException  When the stored string is not valid JSON, or nests deeper than the
     *          eight levels a setting is allowed.
     *
     * @since   2.0.0
     */
    private function decode(mixed $stored): mixed
    {
        if (!is_string($stored)) {
            return $stored;
        }

        try {
            return json_decode($stored, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('A site setting contains invalid JSON.', 0, $exception);
        }
    }

    /**
     * Map the dotted storage keys to the public setting names callers work with.
     *
     * This map doubles as the allow-list a read filters rows through, and it fixes the order in which
     * a write rewrites the rows.
     *
     * @return  array<string, string>  Public setting name, keyed by the storage key of its row.
     *
     * @since   2.0.0
     */
    private static function keyMap(): array
    {
        return [
            'site.name' => 'site_name',
            'site.homepage_content_id' => 'homepage_content_id',
            'site.homepage_slug' => 'homepage_slug',
            'site.default_locale' => 'default_locale',
            'site.timezone' => 'timezone',
            'search.indexing_enabled' => 'search_indexing_enabled',
            'site.presentation' => 'presentation',
        ];
    }
}
