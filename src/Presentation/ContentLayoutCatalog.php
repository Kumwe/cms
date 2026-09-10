<?php

declare(strict_types=1);

namespace Kumwe\App\Presentation;

use Kumwe\App\Content\Application\ContentModelRepository;
use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\Context\Value\SiteContext;

/**
 * Chooses the public site template a published record renders through, by its content type.
 *
 * This is the deliberate seam between the content model and the presentation: every core layout maps
 * from a content-type handle here, unknown and historical types keep the general `page` layout, and a
 * future presentation binding — for example a per-menu template selection — extends this decision point
 * rather than the handlers. Templates resolve through the isolated site loader chain, so an installed
 * theme or extension namespace may restyle a layout without widening this catalog.
 *
 * @since  2.0.0
 */
final readonly class ContentLayoutCatalog
{
    /**
     * Core layout template names by content-type handle.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const array LAYOUTS = [
        'article' => 'article',
        'document' => 'document',
        'faq' => 'faq',
        'guide' => 'guide',
        'landing' => 'landing',
        'reference' => 'reference',
    ];

    /**
     * Bind the catalog to the content-model catalog of the configured public site.
     *
     * @param  ContentModelRepository  $models          Published content-type catalog.
     * @param  string                  $siteIdentifier  Site whose types the public routes serve.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ContentModelRepository $models,
        private string $siteIdentifier,
    ) {
    }

    /**
     * Name every layout a menu item may bind, in the order selection screens offer them.
     *
     * @return  list<string>  The core layout handles followed by the general `page` layout.
     *
     * @since   2.0.0
     */
    public static function handles(): array
    {
        return [...array_keys(self::LAYOUTS), 'page'];
    }

    /**
     * Resolve the site template one published record renders through.
     *
     * A menu-item override wins when it names a layout this catalog knows — including `page`
     * itself — so an operator can re-dress one linked page without touching its content type. An
     * override naming anything else is ignored rather than trusted, which keeps a stale binding from
     * steering rendering at an arbitrary template after a layout is renamed or removed.
     *
     * @param   ContentRecord  $record    Published record whose pinned content type names the layout.
     * @param   ?string        $override  Menu-bound template name, or null when no binding applies.
     *
     * @return  string  Template name without the `.twig` suffix; `page` when the type declares no layout.
     *
     * @since   2.0.0
     */
    public function templateFor(ContentRecord $record, ?string $override = null): string
    {
        if ($override !== null && ($override === 'page' || isset(self::LAYOUTS[$override]))) {
            return self::LAYOUTS[$override] ?? 'page';
        }
        $type = $this->models->contentType(
            SiteContext::fromString($this->siteIdentifier),
            $record->contentTypeId,
        );

        return self::LAYOUTS[$type->handle ?? ''] ?? 'page';
    }
}
