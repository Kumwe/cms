<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Http\Handler;

use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Application\Presentation\Dashboard\DashboardPreferenceService;
use Kumwe\App\Content\Application\ContentModelService;
use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Delivery\Http\Dashboard\DashboardPreferenceQueryDecoder;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\App\InterfaceStandard\SurfaceArea;
use Kumwe\App\InterfaceStandard\SurfaceId;
use Kumwe\App\Presentation\Application\Dashboard\DashboardComposer;
use Kumwe\App\Presentation\Application\Dashboard\DashboardPreferenceFormPresenter;
use Kumwe\App\Presentation\Application\Dashboard\DashboardPreferenceFormProjection;
use Kumwe\App\Presentation\Application\Dashboard\DashboardWidget;
use Kumwe\App\Presentation\Application\Dashboard\DashboardWorkflowCatalog;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves the access-aware administrator dashboard through the shared KIS composition engine.
 *
 * Navigation contributions are the canonical workflow-widget catalog: the renderer first applies actor
 * capability and live extension-trust filtering, then `DashboardComposer` removes the dashboard self link
 * and resolves group and personal KIS preferences against that catalog. Content is merely one optional
 * group of widgets. An actor without `content.read` therefore receives useful permitted business or system
 * workflows without a denied content-service call or an empty content-centric landing page.
 *
 * @since  2.0.0
 */
final readonly class AdministratorDashboardHandler implements RequestHandlerInterface
{
    /**
     * Bind content summaries and the administrator contribution projection to one dashboard composer.
     *
     * @param  ContentService                    $content          Policy-authorized content records.
     * @param  ContentModelService               $models           Policy-authorized content-type definitions.
     * @param  AdministratorRenderer             $renderer         Shell and visible-navigation renderer.
     * @param  DashboardComposer                 $dashboard        Group-aware widget composer.
     * @param  DashboardPreferenceService        $preferences      Authorized query and mutation use case.
     * @param  DashboardPreferenceFormPresenter  $preferenceForms  Typed-state form mapper.
     * @param  DashboardPreferenceQueryDecoder   $preferenceQuery  Defensive GET and same-area URL codec.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ContentService $content,
        private ContentModelService $models,
        private AdministratorRenderer $renderer,
        private DashboardComposer $dashboard,
        private DashboardPreferenceService $preferences,
        private DashboardPreferenceFormPresenter $preferenceForms,
        private DashboardPreferenceQueryDecoder $preferenceQuery,
    ) {
    }

    /**
     * Compose and render the dashboard for the authenticated administrator.
     *
     * The content projection remains deliberately bounded to the latest 500 readable records; its wording
     * explicitly describes that bounded basis instead of presenting the number as an installation-wide
     * count. Every workflow card and shortcut is derived from the same filtered rows as the sidebar and
     * command palette. Preferences carry only those stable semantic identifiers, never hrefs or markup.
     *
     * @param   ServerRequestInterface  $request  Authenticated and route-authorized administrator request.
     *
     * @return  ResponseInterface  No-store HTML because the page carries a CSRF token and actor preferences.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = AdministratorRequest::session($request);
        $context = AdministratorRequest::context($request);
        $capabilities = AdministratorRequest::capabilityMap($request);
        $navigation = $this->renderer->visibleNavigation($capabilities);
        $surface = SurfaceId::fromString('core.administrator.dashboard');
        $preferenceQuery = $this->preferenceQuery->decode($request->getQueryParams());
        $workflowCatalog = new DashboardWorkflowCatalog(
            SurfaceArea::Administrator,
            $surface,
            $navigation,
        );
        $workflowIds = $workflowCatalog->identifiers();
        $coreWidgets = [$this->accessContextWidget($context, count($workflowIds))];
        $defaultWidgets = ['core.dashboard.administrator-context'];

        if (isset($capabilities['content.read'])) {
            $records = $this->content->list($context, 500, true);
            $types = $this->models->contentTypes($context);
            $coreWidgets = [
                ...$this->contentWidgets($records, count($types)),
                ...$coreWidgets,
            ];
            $defaultWidgets = [
                'core.dashboard.content-summary',
                'core.dashboard.recent-content',
                ...$defaultWidgets,
            ];
        }

        $visibleIds = array_fill_keys($workflowIds, true);
        foreach (
            [
                'core.business-records',
                'core.business-reports',
                'core.automation',
                'core.content',
                'core.create-content',
                'core.access',
                'core.settings',
            ] as $identifier
        ) {
            if (isset($visibleIds[$identifier])) {
                $defaultWidgets[] = $identifier;
            }
        }
        $defaultWidgets = array_slice($defaultWidgets, 0, 8);
        foreach (array_keys($visibleIds) as $identifier) {
            if (
                $identifier !== 'core.dashboard'
                && !in_array($identifier, $defaultWidgets, true)
                && count($defaultWidgets) < 8
            ) {
                $defaultWidgets[] = $identifier;
            }
        }
        $defaultShortcuts = array_slice(array_values(array_filter(
            $defaultWidgets,
            static fn (string $identifier): bool => isset($visibleIds[$identifier]),
        )), 0, 6);
        if ($defaultShortcuts === []) {
            $defaultShortcuts = array_slice(array_keys($visibleIds), 0, 6);
        }

        $dashboard = $this->dashboard->compose(
            SurfaceArea::Administrator,
            $surface,
            ContributionOwner::core(),
            $context,
            $navigation,
            $coreWidgets,
            $defaultWidgets,
            $defaultShortcuts,
            $preferenceQuery,
        );
        $view = $dashboard->toArray();
        $preferenceForms = $this->preferenceForms->present(
            $this->preferences->read(
                $context,
                SurfaceArea::Administrator,
                $surface,
                ContributionOwner::core(),
                isset($capabilities['users.manage']),
                $preferenceQuery,
            ),
            $dashboard->selectedWidgetIds,
            $dashboard->selectedShortcutIds,
            $workflowCatalog,
            $coreWidgets,
        );
        $view['preference_forms'] = $preferenceForms->forms;
        $view['preference_diagnostics'] = $preferenceForms->diagnostics;
        $view['access_group_browser'] = $this->accessGroupBrowser(
            SurfaceArea::Administrator,
            $preferenceForms,
        );
        $view['workflow_browser'] = $this->workflowBrowser(
            SurfaceArea::Administrator,
            $preferenceForms,
        );
        $view['preference_action'] = $this->preferenceQuery->mutationAction(
            SurfaceArea::Administrator,
            $preferenceQuery,
        );
        $this->applyPreferenceNotice(
            $view,
            $request->getQueryParams(),
            ($preferenceForms->accessGroupAdministration
                && ($preferenceQuery->search !== '' || $preferenceQuery->page > 1))
                || $preferenceQuery->workflowSearch !== ''
                || $preferenceQuery->workflowPage > 1,
        );

        return new HtmlResponse($this->renderer->render('dashboard', [
            'csrf' => $session->csrfToken,
            'capabilities' => $capabilities,
            'dashboard' => $view,
        ]), 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Describe the server-resolved administrator access context without reading an unrelated subsystem.
     *
     * @param   ExecutionContext  $context        Current trusted context.
     * @param   int               $workflowCount  Bounded live workflows selectable on this dashboard.
     *
     * @return  DashboardWidget  Always-available semantic context card.
     *
     * @since   2.0.0
     */
    private function accessContextWidget(
        ExecutionContext $context,
        int $workflowCount,
    ): DashboardWidget {
        return new DashboardWidget(
            'core.dashboard.administrator-context',
            DashboardWidget::KIND_CONTEXT,
            'core.administrator.dashboard.access_context.title',
            'core.administrator.dashboard.access_context.description',
            'shield',
            size: DashboardWidget::SIZE_LARGE,
            data: ['items' => [
                [
                    'label' => 'core.administrator.dashboard.access_context.site_label',
                    'value' => $context->site()->identifier(),
                ],
                [
                    'label' => 'core.administrator.dashboard.access_context.workspace_label',
                    'value' => $context->workspace()?->identifier() ?? '—',
                ],
                [
                    'label' => 'core.administrator.dashboard.access_context.workflows_label',
                    'value' => $workflowCount,
                ],
            ]],
        );
    }

    /**
     * Build bounded content-owned summary and activity widgets.
     *
     * Recent rows are informational. Their collection-level capability map cannot preserve grant reach,
     * so only the authorized collection action is exposed and no per-record editor href is inferred here.
     *
     * @param   list<ContentRecord>  $records    Latest readable content slice, newest first.
     * @param   int                  $typeCount  Number of readable current content types.
     *
     * @return  list<DashboardWidget>  Markup-free core models for the shared KIS renderer.
     *
     * @since   2.0.0
     */
    private function contentWidgets(array $records, int $typeCount): array
    {
        $counts = ['total' => 0, 'published' => 0, 'review' => 0, 'trashed' => 0];
        foreach ($records as $record) {
            $counts['total']++;
            if ($record->deletedAt !== null) {
                $counts['trashed']++;
                continue;
            }
            $status = $record->entry->statusKey();
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }
        $active = max(1, $counts['total'] - $counts['trashed']);
        $publishedPercent = min(100, (int) round(($counts['published'] / $active) * 100));

        return [
            new DashboardWidget(
                'core.dashboard.content-summary',
                DashboardWidget::KIND_SUMMARY,
                'core.administrator.dashboard.content_summary.title',
                'core.administrator.dashboard.content_summary.description',
                'content',
                size: DashboardWidget::SIZE_LARGE,
                data: [
                    'search' => [
                        'action' => '/administrator/content',
                        'label' => 'core.administrator.dashboard.content_summary.search_label',
                        'placeholder' => 'core.administrator.dashboard.content_summary.search_placeholder',
                        'button' => 'core.administrator.dashboard.content_summary.search_button',
                    ],
                    'metrics' => [
                        [
                            'label' => 'core.administrator.dashboard.content_summary.total_metric',
                            'value' => $counts['total'],
                        ],
                        [
                            'label' => 'core.administrator.dashboard.content_summary.published_metric',
                            'value' => $counts['published'],
                            'tone' => 'success',
                        ],
                        [
                            'label' => 'core.administrator.dashboard.content_summary.review_metric',
                            'value' => $counts['review'],
                            'tone' => $counts['review'] > 0 ? 'warning' : 'neutral',
                        ],
                        ['label' => 'core.administrator.dashboard.content_summary.types_metric', 'value' => $typeCount],
                    ],
                    'progress' => [
                        'value' => $publishedPercent,
                        'label' => 'core.administrator.dashboard.content_summary.publication_progress',
                        'parameters' => ['percent' => $publishedPercent],
                        'help' => 'core.administrator.dashboard.content_summary.publication_progress_help',
                        'help_parameters' => ['count' => $counts['total']],
                    ],
                ],
            ),
            new DashboardWidget(
                'core.dashboard.recent-content',
                DashboardWidget::KIND_ACTIVITY,
                'core.administrator.dashboard.recent_content.title',
                'core.administrator.dashboard.recent_content.description',
                'content',
                size: DashboardWidget::SIZE_LARGE,
                data: [
                    'items' => array_map(
                        static function (ContentRecord $record): array {
                            $status = $record->deletedAt !== null ? 'trashed' : $record->entry->statusKey();
                            $known = in_array($status, ['archived', 'draft', 'published', 'review', 'trashed'], true);

                            return [
                                'title' => $record->entry->title(),
                                'detail' => $record->updatedAt->format(DATE_ATOM),
                                'detail_label' => 'core.administrator.dashboard.recent_content.updated_at',
                                'detail_parameters' => ['at' => $record->updatedAt->getTimestamp()],
                                'status' => $status,
                                'status_label' => 'core.administrator.dashboard.recent_content.status_'
                                    . ($known ? $status : 'other'),
                                'status_parameters' => $known
                                    ? []
                                    : ['status' => str_replace('_', ' ', $status)],
                                'status_tone' => match ($status) {
                                    'published' => 'success',
                                    'review' => 'warning',
                                    'trashed' => 'danger',
                                    default => 'neutral',
                                },
                            ];
                        },
                        array_slice($records, 0, 6),
                    ),
                    'empty_title' => 'core.administrator.dashboard.recent_content.empty_title',
                    'empty_message' => 'core.administrator.dashboard.recent_content.empty_message',
                    'action' => [
                        'href' => '/administrator/content',
                        'label' => 'core.administrator.dashboard.recent_content.view_all',
                    ],
                ],
            ),
        ];
    }

    /**
     * Add only closed, server-selected preference result notices to the dashboard contract.
     *
     * @param   array<string, mixed>     $view           Dashboard view updated in place.
     * @param   array<array-key, mixed>  $query          Untrusted query values inspected only as exact flags.
     * @param   bool                     $browserActive  Whether validated group browse state is active.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function applyPreferenceNotice(array &$view, array $query, bool $browserActive): void
    {
        $view['preference_saved'] = ($query['dashboard-saved'] ?? null) === '1';
        $error = $query['dashboard-error'] ?? null;
        $view['preference_error'] = match ($error) {
            'conflict' => 'core.interface_standard.dashboard.conflict_notice',
            'invalid' => 'core.interface_standard.dashboard.invalid_notice',
            default => '',
        };
        $view['preference_open'] = $view['preference_saved']
            || $view['preference_error'] !== ''
            || $browserActive;
    }

    /**
     * Map typed paging state to fixed administrator links for the shared protected component.
     *
     * @param   SurfaceArea                        $area        Fixed delivery area.
     * @param   DashboardPreferenceFormProjection  $projection  Typed form projection.
     *
     * @return  array<string, bool|int|string>  No-JavaScript access-group browser contract.
     *
     * @since   2.0.0
     */
    private function accessGroupBrowser(
        SurfaceArea $area,
        DashboardPreferenceFormProjection $projection,
    ): array {
        $query = $projection->accessGroupQuery;

        return [
            'available' => $projection->accessGroupAdministration,
            'active' => $query->search !== '' || $query->page > 1,
            'search' => $query->search,
            'page' => $query->page,
            'result_count' => $projection->accessGroupResultCount,
            'has_previous' => $projection->accessGroupHasPrevious,
            'has_next' => $projection->accessGroupHasNext,
            'browse_limit' => $projection->accessGroupBrowseLimit,
            'action' => $this->preferenceQuery->browseAction($area),
            'clear_href' => $this->preferenceQuery->browseHref(
                $area,
                $query->withoutAccessGroupBrowser(),
            ),
            'previous_href' => $projection->accessGroupHasPrevious
                ? $this->preferenceQuery->browseHref($area, $query->previous())
                : '',
            'next_href' => $projection->accessGroupHasNext
                ? $this->preferenceQuery->browseHref($area, $query->next())
                : '',
        ];
    }

    /**
     * Map the shared bounded workflow page to fixed administrator no-JavaScript links.
     *
     * @param   SurfaceArea                        $area        Fixed delivery area.
     * @param   DashboardPreferenceFormProjection  $projection  Typed form and browser projection.
     *
     * @return  array<string, bool|int|string>  Independent workflow browser contract.
     *
     * @since   2.0.0
     */
    private function workflowBrowser(
        SurfaceArea $area,
        DashboardPreferenceFormProjection $projection,
    ): array {
        $page = $projection->workflowPage;
        if ($page === null) {
            return ['available' => false];
        }
        $query = $page->query;

        return [
            'available' => true,
            'active' => $query->workflowSearch !== '' || $query->workflowPage > 1,
            'search' => $query->workflowSearch,
            'page' => $query->workflowPage,
            'result_count' => count($page->candidates),
            'has_previous' => $page->hasPrevious(),
            'has_next' => $page->hasNext,
            'browse_limit' => $page->browseLimit,
            'action' => $this->preferenceQuery->browseAction($area),
            'clear_href' => $this->preferenceQuery->browseHref($area, $query->withoutWorkflowBrowser()),
            'previous_href' => $page->hasPrevious()
                ? $this->preferenceQuery->browseHref($area, $query->workflowPrevious())
                : '',
            'next_href' => $page->hasNext
                ? $this->preferenceQuery->browseHref($area, $query->workflowNext())
                : '',
        ];
    }
}
