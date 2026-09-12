<?php

declare(strict_types=1);

namespace Kumwe\App\Content\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use JsonException;
use Kumwe\App\Content\Application\TranslationGroupRepository;
use Kumwe\App\Content\Domain\InvalidTranslationGroup;
use Kumwe\App\Content\Domain\PublicationWindow;
use Kumwe\App\Content\Domain\TranslationGroup;
use Kumwe\App\Content\Domain\TranslationGroupMember;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Localization\Domain\InvalidLocaleTag;
use Kumwe\Localization\Domain\LocaleTag;
use Kumwe\Context\Value\SiteContext;
use RuntimeException;

/**
 * Doctrine DBAL implementation that assembles a translation group out of the entry rows themselves.
 *
 * `content_translation_groups` holds one row per logical item and carries exactly one fact the members
 * cannot: the declared fallback locale. Everything else is read from `content_entries`, joined to the
 * `workflow_definition_versions` row each entry is pinned to, so a member's publication is judged the
 * same way `DoctrineContentRepository` judges a single entry's — by its state key appearing in that
 * version's `public_states` list — and republishing a workflow cannot retroactively advertise a locale
 * in `hreflang` that it never advertised on the page itself.
 *
 * Two defensive readings matter here. A group whose stored fallback locale no longer has a member would
 * be refused by `TranslationGroup`, so the fallback is reduced to a member that exists before the group
 * is built: a fallback pointing at a locale that has since been deleted degrades to the first locale
 * the item carries rather than turning every page of that item into a fault. And a row whose locale is
 * malformed is dropped from the group instead of failing the read, because one bad row must not take
 * the other languages of an item off the site with it.
 *
 * @since  2.0.0
 */
final readonly class DoctrineTranslationGroupRepository implements TranslationGroupRepository
{
    /**
     * Bind the repository to the connection and the prefix-applying table-name resolver.
     *
     * @param  Connection  $database  DBAL connection every group read and write is issued on.
     * @param  TableNames  $tables    Resolver applying the configured prefix to the content tables.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * Load the group one content entry belongs to, with every locale of the item.
     *
     * @param   SiteContext  $site       Site the entry and every sibling must belong to.
     * @param   string       $contentId  UUID of the content entry whose group is wanted.
     *
     * @return  ?TranslationGroup  The group, or null when the entry declares none, is trashed, or belongs
     *          to another site.
     *
     * @throws  RuntimeException  When a stored member row or public-state list is malformed.
     *
     * @since   2.0.0
     */
    public function forContent(SiteContext $site, string $contentId): ?TranslationGroup
    {
        $groupId = $this->database->fetchOne(sprintf(
            'SELECT translation_group_id FROM %s WHERE id = ? AND site_identifier = ? AND deleted_at IS NULL',
            $this->tables->quoted('content_entries'),
        ), [$contentId, $site->identifier()]);
        if (!is_string($groupId) || $groupId === '') {
            return null;
        }

        return $this->ofId($site, $groupId);
    }

    /**
     * Record the group and its declared fallback, leaving an already-declared group untouched.
     *
     * @param   SiteContext  $site          Site that owns the group.
     * @param   string       $groupId       UUID identifying the logical item across locales.
     * @param   LocaleTag    $memberLocale  Locale used as fallback when the group is first declared without one.
     * @param   ?LocaleTag   $fallback      Explicit fallback to verify or record; null leaves an existing one.
     *
     * @return  void
     *
     * @throws  InvalidTranslationGroup  When the group belongs to another site or an explicit fallback
     *          contradicts its stored declaration.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the locking read or insert.
     *
     * @since   2.0.0
     */
    public function declareGroup(
        SiteContext $site,
        string $groupId,
        LocaleTag $memberLocale,
        ?LocaleTag $fallback = null,
    ): void {
        $existing = $this->database->fetchAssociative(sprintf(
            'SELECT id, site_identifier, fallback_locale FROM %s WHERE id = ? FOR UPDATE',
            $this->tables->quoted('content_translation_groups'),
        ), [$groupId]);
        if (is_array($existing)) {
            $owner = $existing['site_identifier'] ?? null;
            if (!is_string($owner) || $owner !== $site->identifier()) {
                throw new InvalidTranslationGroup('A translation group cannot be shared between sites.');
            }
            $storedFallback = $existing['fallback_locale'] ?? null;
            if (
                $fallback instanceof LocaleTag
                && (!is_string($storedFallback) || $storedFallback !== $fallback->toString())
            ) {
                throw new InvalidTranslationGroup('A translation group cannot change its declared fallback locale.');
            }

            return;
        }

        $this->database->insert($this->tables->raw('content_translation_groups'), [
            'id' => $groupId,
            'site_identifier' => $site->identifier(),
            'fallback_locale' => ($fallback ?? $memberLocale)->toString(),
        ]);
    }

    /**
     * Serialize an attachment and enforce the stored group's site and member ceiling.
     *
     * @param   SiteContext  $site       Site that must own the group.
     * @param   string       $groupId    UUID of the logical item being attached to.
     * @param   string       $contentId  Entry being attached, excluded from the existing-member count.
     *
     * @return  void
     *
     * @throws  InvalidTranslationGroup  When the group belongs to another site or already has 64 other members.
     * @throws  RuntimeException  When the declared group cannot be locked or its member count is unreadable.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects a locking read.
     *
     * @since   2.0.0
     */
    public function guardAttachment(SiteContext $site, string $groupId, string $contentId): void
    {
        $group = $this->database->fetchAssociative(sprintf(
            'SELECT site_identifier FROM %s WHERE id = ? FOR UPDATE',
            $this->tables->quoted('content_translation_groups'),
        ), [$groupId]);
        if (!is_array($group)) {
            throw new RuntimeException('A translation group must be declared before an entry is attached.');
        }
        $owner = $group['site_identifier'] ?? null;
        if (!is_string($owner) || $owner !== $site->identifier()) {
            throw new InvalidTranslationGroup('A translation group cannot be shared between sites.');
        }

        $members = $this->database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE translation_group_id = ? AND site_identifier = ? '
            . 'AND id <> ? AND deleted_at IS NULL',
            $this->tables->quoted('content_entries'),
        ), [$groupId, $site->identifier(), $contentId]);
        if (!is_int($members) && !(is_string($members) && ctype_digit($members))) {
            throw new RuntimeException('A translation group member count is unreadable.');
        }
        if ((int) $members >= TranslationGroup::MAXIMUM_MEMBERS) {
            throw new InvalidTranslationGroup(sprintf(
                'A translation group carries at most %d locales.',
                TranslationGroup::MAXIMUM_MEMBERS,
            ));
        }
    }

    /**
     * Assemble one group from its stored fallback and the entry rows that name it.
     *
     * @param   SiteContext  $site     Site every member must belong to.
     * @param   string       $groupId  UUID of the logical item.
     *
     * @return  ?TranslationGroup  The group, or null when it holds no readable member.
     *
     * @throws  RuntimeException  When a stored member row or public-state list is malformed.
     *
     * @since   2.0.0
     */
    private function ofId(SiteContext $site, string $groupId): ?TranslationGroup
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT e.id, e.locale, e.slug, e.workflow_state_key, e.publish_at, e.unpublish_at, '
            . 'wv.public_states AS definition_public_states FROM %s e INNER JOIN %s wv '
            . 'ON wv.workflow_id = e.workflow_id AND wv.version = e.workflow_version '
            . 'WHERE e.translation_group_id = ? AND e.site_identifier = ? AND e.deleted_at IS NULL '
            . 'ORDER BY e.locale',
            $this->tables->quoted('content_entries'),
            $this->tables->quoted('workflow_definition_versions'),
        ), [$groupId, $site->identifier()]);

        $members = [];
        foreach ($rows as $row) {
            $member = $this->member($row);
            if ($member !== null) {
                $members[] = $member;
            }
        }
        if ($members === []) {
            return null;
        }

        return new TranslationGroup($groupId, $this->fallback($groupId, $members), $members);
    }

    /**
     * Turn one joined entry row into a member, or drop it when its locale cannot be read.
     *
     * @param   array<string, mixed>  $row  Joined entry and workflow-version row.
     *
     * @return  ?TranslationGroupMember  The member, or null when the row declares no readable locale.
     *
     * @throws  RuntimeException  When a required column is absent or the public-state list is malformed.
     *
     * @since   2.0.0
     */
    private function member(array $row): ?TranslationGroupMember
    {
        $locale = $row['locale'] ?? null;
        if (!is_string($locale) || $locale === '') {
            return null;
        }
        try {
            $tag = LocaleTag::fromString($locale);
        } catch (InvalidLocaleTag) {
            return null;
        }

        return new TranslationGroupMember(
            $tag,
            $this->string($row, 'id'),
            $this->string($row, 'slug'),
            $this->string($row, 'workflow_state_key'),
            $this->isPublicState($row),
            new PublicationWindow(
                $this->instant($row['publish_at'] ?? null),
                $this->instant($row['unpublish_at'] ?? null),
            ),
        );
    }

    /**
     * Reduce the stored fallback to a locale the group still carries.
     *
     * @param   string                        $groupId  UUID of the logical item.
     * @param   list<TranslationGroupMember>  $members  Members already read, in locale order.
     *
     * @return  LocaleTag  The declared fallback when it still has a member, otherwise the first locale
     *          the item carries.
     *
     * @since   2.0.0
     */
    private function fallback(string $groupId, array $members): LocaleTag
    {
        $stored = $this->database->fetchOne(sprintf(
            'SELECT fallback_locale FROM %s WHERE id = ?',
            $this->tables->quoted('content_translation_groups'),
        ), [$groupId]);
        if (is_string($stored) && $stored !== '') {
            try {
                $declared = LocaleTag::fromString($stored);
            } catch (InvalidLocaleTag) {
                $declared = null;
            }
            foreach ($members as $member) {
                if ($declared !== null && $member->locale->equals($declared)) {
                    return $declared;
                }
            }
        }

        return $members[0]->locale;
    }

    /**
     * Decide whether a row's workflow state is public in the definition version the entry is pinned to.
     *
     * @param   array<string, mixed>  $row  Joined entry and workflow-version row.
     *
     * @return  bool  True when the entry's state key appears in that version's public-state list.
     *
     * @throws  RuntimeException  When the stored public-state list is not decodable JSON.
     *
     * @since   2.0.0
     */
    private function isPublicState(array $row): bool
    {
        try {
            $states = is_string($row['definition_public_states'] ?? null)
                ? json_decode($row['definition_public_states'], true, 16, JSON_THROW_ON_ERROR)
                : $row['definition_public_states'];
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored workflow public states are invalid.', 0, $exception);
        }

        return is_array($states) && in_array($this->string($row, 'workflow_state_key'), $states, true);
    }

    /**
     * Read a column that must hold a non-empty string.
     *
     * @param   array<string, mixed>  $row  Associative row as fetched from the driver.
     * @param   string                $key  Unqualified name of the column to read.
     *
     * @return  string  The stored value, guaranteed non-empty.
     *
     * @throws  RuntimeException  When the column is absent, not a string, or the empty string.
     *
     * @since   2.0.0
     */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Stored translation group field %s is invalid.', $key));
        }

        return $value;
    }

    /**
     * Normalise an optional publication bound, treating an empty string as an absent one.
     *
     * Drivers differ over whether a timestamp column arrives as a date object or as its raw string, and
     * over whether an unset column reads as SQL null or as the empty string, so all four are accepted
     * here rather than pinning the mapper to one platform. A bare string is read as UTC, which is the
     * zone every content timestamp is written in.
     *
     * @param   mixed  $value  Raw column value as the driver handed it back.
     *
     * @return  ?DateTimeImmutable  The instant, or null when the bound is open.
     *
     * @throws  RuntimeException  When a present value is neither a date object nor a readable string.
     * @throws  \DateMalformedStringException  When the string cannot be read as a date.
     *
     * @since   2.0.0
     */
    private function instant(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (!is_string($value)) {
            throw new RuntimeException('A stored translation group publication bound is invalid.');
        }

        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
