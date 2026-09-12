<?php

declare(strict_types=1);

namespace Kumwe\App\Content\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\Localization\Domain\InvalidLocaleTag;
use Kumwe\Localization\Domain\LocaleTag;
use Kumwe\App\Workflow\Domain\Workflow;
use Ramsey\Uuid\Uuid;

/**
 * An editable unit of content: its title, slug, body, workflow state, language and version.
 *
 * This is the aggregate every content change goes through. It is immutable, so `revise()`,
 * `reschedule()`, `transition()` and `translate()` each return a successor one version higher, and each
 * demands the version the caller believed it was editing — which is how two editors racing on the same
 * entry are separated before either write reaches the database. The constructor is private and
 * validating, so an entry cannot exist in an invalid shape: identifier, title, slug, state key, locale
 * and body are all checked on every construction, including when a row is rebuilt out of storage.
 *
 * One entry is one locale of one logical item. The locale it is written in and the translation group it
 * belongs to are carried here because they decide what the entry *is*, not where it is stored: an entry
 * with no locale is content nobody has declared a language for, an entry with a locale and no group is
 * a language declared for an item that has not been translated yet, and an entry with both is one
 * member of a `TranslationGroup`. Publication stays per entry — the state and window on this object are
 * this locale's alone — which is what lets English be live while another language is still drafting.
 *
 * Deliberately absent: which site owns the entry, which content type and workflow version it was
 * authored against, and when it was stored. Those are persistence facts and live on `ContentRecord`,
 * which keeps this class free of storage concerns and safe to reason about on its own.
 *
 * @since  2.0.0
 */
final readonly class ContentEntry
{
    /**
     * Body of the entry, restricted to JSON-compatible values under string keys.
     *
     * Held separately from the promoted constructor properties because the incoming array is validated
     * and only then narrowed to the string-keyed shape the rest of the class relies on.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    private array $data;

    /**
     * Language the entry is written in, or null when nobody has declared one.
     *
     * @var    ?LocaleTag
     * @since  2.0.0
     */
    private ?LocaleTag $locale;

    /**
     * Build a validated entry, rejecting anything the domain rules do not accept.
     *
     * Private because there are exactly two legitimate origins for an entry — a fresh `create()` and a
     * `reconstitute()` out of storage — and both must pass through the same checks.
     *
     * @param   string                   $id                  Canonical UUID identifying the entry.
     * @param   string                   $title               Human-readable title, already trimmed.
     * @param   string                   $slug                Route segment the public URL carries.
     * @param   array<array-key, mixed>  $data                Entry body; keys are proven to be strings here.
     * @param   string                   $status              Workflow state key the entry currently sits in.
     * @param   PublicationWindow        $publicationWindow   Period in which a published entry is visible.
     * @param   int                      $version             Optimistic-concurrency counter, starting at one.
     * @param   LocaleTag|string|null    $locale              Language this entry is written in, or null for none.
     * @param   ?string                  $translationGroupId  UUID of the logical item across locales, or null.
     *
     * @throws  InvalidArgumentException  When the identifier, title, slug, body, version or translation
     *          group breaks a rule.
     * @throws  InvalidLocaleTag  When the locale is not a well-formed language tag.
     *
     * @since   2.0.0
     */
    private function __construct(
        private string $id,
        private string $title,
        private string $slug,
        array $data,
        private string $status,
        private PublicationWindow $publicationWindow,
        private int $version,
        LocaleTag|string|null $locale = null,
        private ?string $translationGroupId = null,
    ) {
        self::assertUuid($id);
        self::assertTitle($title);
        self::assertSlug($slug);
        self::assertData($data);

        /** @var array<string, mixed> $data */
        $this->data = $data;

        if ($version < 1) {
            throw new InvalidArgumentException('A content entry version must be at least one.');
        }

        $this->locale = is_string($locale) ? LocaleTag::fromString($locale) : $locale;
        if ($translationGroupId !== null && !Uuid::isValid($translationGroupId)) {
            throw new InvalidArgumentException('A content translation group ID must be a canonical UUID.');
        }
        if ($translationGroupId !== null && $this->locale === null) {
            throw new InvalidArgumentException('An entry in a translation group must declare its locale.');
        }
    }

    /**
     * Start a brand new entry at version one.
     *
     * The identifier is lowercased and the title trimmed on the way in, so callers may pass either
     * casing of a UUID and need not normalise operator input themselves.
     *
     * @param   string                   $id                  Canonical UUID minted for the new entry.
     * @param   string                   $title               Human-readable title as the author typed it.
     * @param   string                   $slug                Route segment the public URL will carry.
     * @param   array<array-key, mixed>  $data                Entry body; must already satisfy its type schema.
     * @param   ContentStatus|string     $status              State to open in, as an enum case or a state key.
     * @param   ?PublicationWindow       $publicationWindow   Visibility period, or null for an unbounded one.
     * @param   LocaleTag|string|null    $locale              Language being authored, or null to declare none.
     * @param   ?string                  $translationGroupId  Group this locale joins; pass the group of the
     *          entry being translated to create a sibling, or null for content that stands alone.
     *
     * @return  self  A version-one entry ready to be stored.
     *
     * @throws  InvalidArgumentException  When any value breaks a domain rule.
     * @throws  InvalidLocaleTag  When the locale is not a well-formed language tag.
     *
     * @since   2.0.0
     */
    public static function create(
        string $id,
        string $title,
        string $slug,
        array $data = [],
        ContentStatus|string $status = ContentStatus::Draft,
        ?PublicationWindow $publicationWindow = null,
        LocaleTag|string|null $locale = null,
        ?string $translationGroupId = null,
    ): self {
        return new self(
            strtolower($id),
            trim($title),
            $slug,
            $data,
            self::stateKey($status),
            $publicationWindow ?? PublicationWindow::unbounded(),
            1,
            $locale,
            $translationGroupId === null ? null : strtolower($translationGroupId),
        );
    }

    /**
     * Rebuild an entry loaded from trusted persistence while preserving all
     * domain validation performed by the constructor.
     *
     * Unlike `create()` this preserves the stored version and state rather than starting over, so a
     * round trip through the repository leaves optimistic concurrency and workflow position intact.
     * Revalidating on the way back in is deliberate: a row corrupted outside the application fails
     * here rather than surfacing as invalid content later.
     *
     * @param   string                   $id                  Canonical UUID the row was stored under.
     * @param   string                   $title               Stored title.
     * @param   string                   $slug                Stored route segment.
     * @param   array<array-key, mixed>  $data                Stored entry body.
     * @param   ContentStatus|string     $status              Stored workflow state, as an enum case or key.
     * @param   PublicationWindow        $publicationWindow   Stored visibility period.
     * @param   int                      $version             Version the row currently carries.
     * @param   LocaleTag|string|null    $locale              Stored language tag, or null on a row written
     *          before the entry declared one.
     * @param   ?string                  $translationGroupId  Stored group identifier, or null when the entry
     *          belongs to no group.
     *
     * @return  self  The entry exactly as stored, with every domain rule re-checked.
     *
     * @throws  InvalidArgumentException  When a stored value no longer satisfies a domain rule.
     * @throws  InvalidLocaleTag  When the stored locale is not a well-formed language tag.
     *
     * @since   2.0.0
     */
    public static function reconstitute(
        string $id,
        string $title,
        string $slug,
        array $data,
        ContentStatus|string $status,
        PublicationWindow $publicationWindow,
        int $version,
        LocaleTag|string|null $locale = null,
        ?string $translationGroupId = null,
    ): self {
        return new self(
            strtolower($id),
            trim($title),
            $slug,
            $data,
            self::stateKey($status),
            $publicationWindow,
            $version,
            $locale,
            $translationGroupId === null ? null : strtolower($translationGroupId),
        );
    }

    /**
     * Return the entry's stable identifier.
     *
     * @return  string  Lowercase canonical UUID, unchanged across every revision of the entry.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Return the title as it should be shown to a reader or editor.
     *
     * @return  string  Between 1 and 255 characters, already trimmed.
     *
     * @since   2.0.0
     */
    public function title(): string
    {
        return $this->title;
    }

    /**
     * Return the route segment the entry is reachable under.
     *
     * @return  string  Lowercase ASCII words joined by single hyphens, at most 160 characters.
     *
     * @since   2.0.0
     */
    public function slug(): string
    {
        return $this->slug;
    }

    /**
     * Return the entry body.
     *
     * @return  array<string, mixed>  Top-level keys are strings and every value is JSON-compatible.
     *
     * @since   2.0.0
     */
    public function data(): array
    {
        return $this->data;
    }

    /**
     * Return the workflow position, projected onto `ContentStatus` where it is one of the built-in states.
     *
     * A site running a custom workflow has states the enum does not model, so callers that must handle
     * both get the raw key back instead; use `statusKey()` when only the key matters.
     *
     * @return  ContentStatus|string  An enum case for a built-in state, otherwise the raw state key.
     *
     * @since   2.0.0
     */
    public function status(): ContentStatus|string
    {
        return ContentStatus::tryFrom($this->status) ?? $this->status;
    }

    /**
     * Return the workflow position as the key persistence and workflow definitions speak in.
     *
     * @return  string  Lowercase state key, valid for both built-in and site-defined workflows.
     *
     * @since   2.0.0
     */
    public function statusKey(): string
    {
        return $this->status;
    }

    /**
     * Return the period within which a published entry is publicly visible.
     *
     * @return  PublicationWindow  Unbounded unless the author scheduled a start or an end.
     *
     * @since   2.0.0
     */
    public function publicationWindow(): PublicationWindow
    {
        return $this->publicationWindow;
    }

    /**
     * Return the version the entry currently stands at.
     *
     * This is the value a caller must hand back as its `ExpectedVersion` when revising, so it is what
     * an editor form carries in a hidden field across the round trip.
     *
     * @return  int  One for a newly created entry, incremented by every successful change.
     *
     * @since   2.0.0
     */
    public function version(): int
    {
        return $this->version;
    }

    /**
     * Return the language this entry is written in.
     *
     * @return  ?LocaleTag  The normalised tag, or null for content whose language nobody has declared —
     *          which is every entry authored before the model carried one.
     *
     * @since   2.0.0
     */
    public function locale(): ?LocaleTag
    {
        return $this->locale;
    }

    /**
     * Return the logical item this entry is one locale of.
     *
     * @return  ?string  Lowercase UUID shared by every locale of the item, or null when the entry belongs
     *          to no group and is therefore reachable in one language only.
     *
     * @since   2.0.0
     */
    public function translationGroupId(): ?string
    {
        return $this->translationGroupId;
    }

    /**
     * Decide whether the entry may be shown to the public at a given moment.
     *
     * Both halves must hold: the entry is in the published state, and the instant falls inside its
     * publication window. This is the only visibility rule; nothing about the reader is considered.
     *
     * @param   DateTimeImmutable  $instant  Moment the question is asked about, usually now.
     *
     * @return  bool  True only when a published entry's window contains the instant.
     *
     * @since   2.0.0
     */
    public function isVisibleAt(DateTimeImmutable $instant): bool
    {
        return $this->status === ContentStatus::Published->value && $this->publicationWindow->contains($instant);
    }

    /**
     * Produce the next version of the entry with new authored content.
     *
     * The workflow state is carried across untouched — editing never moves an entry through its
     * lifecycle, `transition()` does — and the version check happens before anything else, so a stale
     * editor is rejected rather than silently overwriting a colleague's work.
     *
     * @param   ExpectedVersion          $expectedVersion    Version the editor loaded and believes it is changing.
     * @param   string                   $title              Replacement title, trimmed on the way in.
     * @param   string                   $slug               Replacement route segment.
     * @param   array<array-key, mixed>  $data               Replacement body, validated as it is stored.
     * @param   ?PublicationWindow       $publicationWindow  New visibility period, or null to keep the current one.
     *
     * @return  self  A successor one version higher; the receiver is left untouched.
     *
     * @throws  VersionConflict  When the entry has already moved past the expected version.
     * @throws  InvalidArgumentException  When the replacement title, slug or body breaks a domain rule.
     *
     * @since   2.0.0
     */
    public function revise(
        ExpectedVersion $expectedVersion,
        string $title,
        string $slug,
        array $data,
        ?PublicationWindow $publicationWindow = null,
    ): self {
        $expectedVersion->assertMatches($this->version);

        return new self(
            $this->id,
            trim($title),
            $slug,
            $data,
            $this->status,
            $publicationWindow ?? $this->publicationWindow,
            $this->version + 1,
            $this->locale,
            $this->translationGroupId,
        );
    }

    /**
     * Produce the next version of the entry with only its publication window moved.
     *
     * Scheduling is separated from `revise()` so that changing when something goes live does not
     * require resubmitting the body, and so the revision trail records the two kinds of change apart.
     * A published entry can be scheduled out of visibility this way without leaving its state.
     *
     * @param   ExpectedVersion    $expectedVersion    Version the caller believes it is changing.
     * @param   PublicationWindow  $publicationWindow  Replacement visibility period.
     *
     * @return  self  A successor one version higher; the receiver is left untouched.
     *
     * @throws  VersionConflict  When the entry has already moved past the expected version.
     *
     * @since   2.0.0
     */
    public function reschedule(ExpectedVersion $expectedVersion, PublicationWindow $publicationWindow): self
    {
        $expectedVersion->assertMatches($this->version);

        return new self(
            $this->id,
            $this->title,
            $this->slug,
            $this->data,
            $this->status,
            $publicationWindow,
            $this->version + 1,
            $this->locale,
            $this->translationGroupId,
        );
    }

    /**
     * Produce the next version of the entry sitting in a new workflow state.
     *
     * The workflow is asked whether the edge exists before anything is built, which is why this is the
     * only way an entry's state may change: a target the workflow does not declare cannot be reached
     * even by a caller holding the right capability. Authorization is a separate question, decided by
     * `ContentService` from the transition's required capability.
     *
     * @param   ExpectedVersion       $expectedVersion  Version the caller believes it is changing.
     * @param   Workflow              $workflow         Lifecycle in force for this entry, built-in or site-defined.
     * @param   ContentStatus|string  $target           State to move to, as an enum case or a state key.
     *
     * @return  self  A successor one version higher, in the new state.
     *
     * @throws  VersionConflict  When the entry has already moved past the expected version.
     * @throws  InvalidArgumentException  When the target is not a well-formed state key.
     * @throws  \Kumwe\App\Workflow\Domain\InvalidWorkflowTransition  When the workflow declares no such edge.
     *
     * @since   2.0.0
     */
    public function transition(
        ExpectedVersion $expectedVersion,
        Workflow $workflow,
        ContentStatus|string $target,
    ): self {
        $expectedVersion->assertMatches($this->version);
        $workflow->assertCanTransition($this->status, $target);

        return new self(
            $this->id,
            $this->title,
            $this->slug,
            $this->data,
            self::stateKey($target),
            $this->publicationWindow,
            $this->version + 1,
            $this->locale,
            $this->translationGroupId,
        );
    }

    /**
     * Produce the next version of the entry placed in a translation group under a declared locale.
     *
     * This is how an existing entry becomes the first member of a group, and how a freshly authored
     * translation is bound to the item it translates: both sides call this with the same group
     * identifier and their own locale. Nothing else moves — the body, the workflow state and the
     * publication window are all carried across — because declaring what language something is in is
     * not an editorial change to it, and a translation going live is its own `transition()`.
     *
     * The group's own invariants, that a locale appears once and that two locales do not share a slug,
     * belong to `TranslationGroup` and to the database, since one entry cannot see its siblings.
     *
     * @param   ExpectedVersion  $expectedVersion     Version the caller believes it is changing.
     * @param   LocaleTag        $locale              Language this entry is declared to be written in.
     * @param   string           $translationGroupId  UUID of the logical item this locale belongs to.
     *
     * @return  self  A successor one version higher, carrying the locale and the group.
     *
     * @throws  VersionConflict  When the entry has already moved past the expected version.
     * @throws  InvalidArgumentException  When the group identifier is not a canonical UUID.
     *
     * @since   2.0.0
     */
    public function translate(
        ExpectedVersion $expectedVersion,
        LocaleTag $locale,
        string $translationGroupId,
    ): self {
        $expectedVersion->assertMatches($this->version);

        return new self(
            $this->id,
            $this->title,
            $this->slug,
            $this->data,
            $this->status,
            $this->publicationWindow,
            $this->version + 1,
            $locale,
            strtolower($translationGroupId),
        );
    }

    /**
     * Flatten the entry into the plain structure stored, checksummed and rendered elsewhere.
     *
     * This is the canonical wire shape of an entry: `ContentRevision` hashes it to detect tampering,
     * `ContentRecord::toArray()` spreads it before adding storage metadata, and the API and templates
     * read it. Keys are snake-case and window bounds are RFC 3339 strings or null, so changing this
     * shape changes stored revision checksums as well as the public payload.
     *
     * `locale` and `translation_group` are written only by an entry that declares them, for exactly that
     * reason. An entry authored before content carried a language dimension snapshots to the bytes its
     * stored revision checksum was taken over, so adding the dimension invalidates no history.
     *
     * @return  array<string, mixed>  Keyed by `id`, `title`, `slug`, `data`, `status`,
     *          `publication_window` and `version`, plus `locale` and `translation_group` when declared.
     *
     * @since   2.0.0
     */
    public function snapshot(): array
    {
        $snapshot = [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'data' => $this->data,
            'status' => $this->status,
            'publication_window' => [
                'starts_at' => $this->publicationWindow->startsAt()?->format(DATE_ATOM),
                'ends_at' => $this->publicationWindow->endsAt()?->format(DATE_ATOM),
            ],
            'version' => $this->version,
        ];
        if ($this->locale instanceof LocaleTag) {
            $snapshot['locale'] = $this->locale->toString();
        }
        if ($this->translationGroupId !== null) {
            $snapshot['translation_group'] = $this->translationGroupId;
        }

        return $snapshot;
    }

    /**
     * Refuse an identifier that is not a canonical UUID.
     *
     * Identifiers reach persistence and public URLs unquoted, so the shape is pinned here rather than
     * trusted from whichever caller minted it.
     *
     * @param   string  $id  Candidate identifier for the entry.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the identifier is not a canonical UUID.
     *
     * @since   2.0.0
     */
    private static function assertUuid(string $id): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $id) !== 1) {
            throw new InvalidArgumentException('A content entry ID must be a canonical UUID.');
        }
    }

    /**
     * Reduce a state given as either an enum case or a raw key to the validated key form.
     *
     * Sites may define their own workflow states, so the key cannot be checked against a fixed list;
     * what is enforced is the identifier grammar every state key must share, which keeps a
     * site-defined state safe to store and compare alongside the built-in ones.
     *
     * @param   ContentStatus|string  $state  State expressed as a built-in case or a workflow state key.
     *
     * @return  string  Lowercase state key of at most 40 characters.
     *
     * @throws  InvalidArgumentException  When the key is not a lowercase identifier of the accepted shape.
     *
     * @since   2.0.0
     */
    private static function stateKey(ContentStatus|string $state): string
    {
        $state = $state instanceof ContentStatus ? $state->value : $state;
        if (preg_match('/^[a-z][a-z0-9_-]{0,39}$/D', $state) !== 1) {
            throw new InvalidArgumentException('A workflow state key must be a lowercase identifier.');
        }
        return $state;
    }

    /**
     * Refuse a title that is empty or longer than storage and listings accommodate.
     *
     * Length is counted in characters after trimming, so whitespace padding cannot smuggle a blank
     * title past the check or push a legitimate one over the limit.
     *
     * @param   string  $title  Candidate title as supplied by the author.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the trimmed title is empty or exceeds 255 characters.
     *
     * @since   2.0.0
     */
    private static function assertTitle(string $title): void
    {
        $length = mb_strlen(trim($title));

        if ($length < 1 || $length > 255) {
            throw new InvalidArgumentException('A content title must contain between 1 and 255 characters.');
        }
    }

    /**
     * Refuse a slug that is not a clean, single-form route segment.
     *
     * Slugs become public URL path segments, so exactly one spelling of a given entry is admitted: no
     * uppercase, no leading, trailing or doubled hyphens, and no characters needing percent-encoding.
     * Which slugs are reserved for system routes is a separate, application-level question.
     *
     * @param   string  $slug  Candidate route segment.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the slug exceeds 160 characters or breaks the grammar.
     *
     * @since   2.0.0
     */
    private static function assertSlug(string $slug): void
    {
        if (
            mb_strlen($slug) > 160
            || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1
        ) {
            throw new InvalidArgumentException(
                'A slug must contain lowercase ASCII letters, digits, and single hyphens.',
            );
        }
    }

    /**
     * Refuse a body that could not survive a round trip through JSON storage.
     *
     * Top-level keys must be strings, which is what lets the constructor narrow the declared
     * `array<array-key, mixed>` down to `array<string, mixed>` afterwards; every value is then walked
     * recursively. This is a representability check only — whether the body satisfies its content
     * type's schema is decided by `JsonSchemaValidator`.
     *
     * @param   array<array-key, mixed>  $data  Candidate entry body.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a top-level key is not a string, or a value is not storable.
     *
     * @since   2.0.0
     */
    private static function assertData(array $data): void
    {
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Top-level content data keys must be strings.');
            }

            self::assertJsonValue($value);
        }
    }

    /**
     * Walk one body value, refusing anything JSON cannot carry back unchanged.
     *
     * Objects and resources are rejected outright, strings must be valid UTF-8, and floats must be
     * finite — `NAN` and `INF` encode to nothing an encoder will accept, so they are caught here
     * rather than at the moment a revision is checksummed. Arrays recurse regardless of key type,
     * since only the top level is required to be string-keyed.
     *
     * @param   mixed  $value  Candidate value from anywhere inside the entry body.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the value is not JSON-compatible, malformed UTF-8, or non-finite.
     *
     * @since   2.0.0
     */
    private static function assertJsonValue(mixed $value): void
    {
        if ($value === null || is_int($value) || is_bool($value)) {
            return;
        }

        if (is_string($value)) {
            if (!mb_check_encoding($value, 'UTF-8')) {
                throw new InvalidArgumentException('Content strings must contain valid UTF-8.');
            }

            return;
        }

        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException('Content numbers must be finite.');
            }

            return;
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException('Content data must contain only JSON-compatible values.');
        }

        foreach ($value as $nestedValue) {
            self::assertJsonValue($nestedValue);
        }
    }
}
