<?php

declare(strict_types=1);

namespace Kumwe\App\Site\Application;

use Kumwe\Context\Value\ExecutionContext;

/**
 * Port through which every layer reads and writes the site's global settings document.
 *
 * The document is a flat map of public keys — `site_name`, `homepage_content_id`, `homepage_slug`,
 * `default_locale`, `timezone`, `search_indexing_enabled` and `presentation` — and it is where the
 * front end learns which record is mounted at `/`, which menu is primary, and whether the site may be
 * indexed. Reading splits in two on purpose: `current()` serves the rendering path, which has no actor
 * at all, while `managed()` serves administration and proves the `settings.manage` capability first.
 * Both writers are capability-checked and validate the merged document before storing it, so an
 * implementation may never persist a settings map it has not accepted, and a reader may treat every
 * key it receives as safe to render.
 *
 * @since  2.0.0
 */
interface SiteSettings
{
    /**
     * Read the effective settings document without an actor.
     *
     * This is the rendering path, called on nearly every public request, so an implementation fills
     * keys that were never stored with its defaults rather than failing or omitting them.
     *
     * @return  array<string, mixed>  Every public setting key, defaults included for keys never stored.
     *
     * @since   2.0.0
     */
    public function current(): array;

    /**
     * Read the settings document on behalf of an administrator.
     *
     * Same content as `current()`, but reachable only by an actor holding `settings.manage` on the
     * site, so an administration surface cannot hand configuration to an unauthorised caller.
     *
     * @param   ExecutionContext  $context  Actor and site the read runs as.
     *
     * @return  array<string, mixed>  Every public setting key, defaults included for keys never stored.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage settings.
     *
     * @since   2.0.0
     */
    public function managed(ExecutionContext $context): array;

    /**
     * Store the two settings an operator edits most, leaving every other key as it stands.
     *
     * A narrow front door onto `updateAll()`: the remaining keys are read back and rewritten
     * unchanged, so editing the site name never resets locale, timezone, or presentation as a side
     * effect, and the whole document is still validated and audited as one write.
     *
     * @param   ExecutionContext  $context       Actor and site the write runs as.
     * @param   string            $siteName      Display name shown in page chrome and titles.
     * @param   string            $homepageSlug  Slug the homepage falls back to when no content id is set.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage settings.
     * @throws  \InvalidArgumentException  When the name or the slug fails validation.
     *
     * @since   2.0.0
     */
    public function update(ExecutionContext $context, string $siteName, string $homepageSlug): void;

    /**
     * Replace the settings document with a validated merge of the supplied keys.
     *
     * Keys absent from `$settings` keep their current value, so a caller may send a partial map. The
     * merge is validated as a whole rather than key by key, because settings constrain one another —
     * the nominated homepage and primary menu have to exist and belong to this site — and it is stored
     * as one unit, so a rejected value leaves the previous document intact.
     *
     * @param   ExecutionContext      $context   Actor and site the write runs as.
     * @param   array<string, mixed>  $settings  Public setting keys to change, merged over the current document.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage settings.
     * @throws  \InvalidArgumentException  When a value, the nominated homepage, or the primary menu is
     *          rejected.
     *
     * @since   2.0.0
     */
    public function updateAll(ExecutionContext $context, array $settings): void;
}
