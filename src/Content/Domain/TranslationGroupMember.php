<?php

declare(strict_types=1);

namespace Kumwe\App\Content\Domain;

use DateTimeImmutable;
use Kumwe\Localization\Domain\LocaleTag;

/**
 * One locale's entry inside a translation group: its own slug, its own publication state, its own window.
 *
 * This is the unit that makes per-locale publication real. English may be live while German is still
 * drafting, so a member carries the publication decision for its own locale and nothing else: whether
 * the entry's workflow state is public in the definition version the entry is pinned to, and the
 * schedule that state takes effect within. The repository decides the first half, because only it can
 * read the pinned workflow version's public states; the window is carried across unchanged so
 * `isVisibleAt()` answers the same question here as `ContentEntry::isVisibleAt()` does on the entry.
 *
 * The slug is the member's own. Two locales of one group never share one, which is what lets a visitor
 * arrive on `/about` and be offered `/ueber-uns` rather than the same URL twice.
 *
 * @since  2.0.0
 */
final readonly class TranslationGroupMember
{
    /**
     * Hold one locale's place in a group, with the publication facts that locale answers for.
     *
     * @param  LocaleTag          $locale             Locale this member is written in, already normalised.
     * @param  string             $contentId          UUID of the content entry carrying this locale.
     * @param  string             $slug               Route segment this locale is published under.
     * @param  string             $statusKey          Workflow state key the entry currently sits in.
     * @param  bool               $publicState        Whether that state key is public in the entry's pinned
     *         workflow definition version.
     * @param  PublicationWindow  $publicationWindow  Schedule the public state takes effect within.
     *
     * @since  2.0.0
     */
    public function __construct(
        public LocaleTag $locale,
        public string $contentId,
        public string $slug,
        public string $statusKey,
        public bool $publicState,
        public PublicationWindow $publicationWindow,
    ) {
    }

    /**
     * Decide whether this locale is one a visitor may be sent to at a given moment.
     *
     * Both halves must hold, exactly as they do for a single entry: the workflow state is public in the
     * version the entry was written under, and the schedule contains the instant. A drafting locale
     * answers false, which is how it stays out of `hreflang` and out of the language selector.
     *
     * @param   DateTimeImmutable  $instant  Moment the question is asked about, usually now.
     *
     * @return  bool  True only when this locale is publicly deliverable then.
     *
     * @since   2.0.0
     */
    public function isVisibleAt(DateTimeImmutable $instant): bool
    {
        return $this->publicState && $this->publicationWindow->contains($instant);
    }
}
