<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Delivery\Administrator;

use DateInterval;
use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\Localization\Application\Translator;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaEnvironment;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\App\BusinessSchema\Domain\SchemaPlan;
use Kumwe\App\BusinessSchema\Domain\SchemaPlanStep;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves the one administrator screen the whole schema-plan lifecycle is driven from.
 *
 * Planning, approval, execution, purging, and recovery are separate authorized actions, and every one
 * of them redirects back here, so this handler is what makes them feel like a single workspace: it
 * renders the catalog of persisted plans, the selected plan with its journal steps, the installation
 * that plan would change, and the recovery evidence bound to it. Its least obvious job is the
 * `evidence_qualifies` flag: it re-runs the same `qualifies()` check the approval path will run, so an
 * operator sees that their drill is stale, or was taken against another source schema or database
 * environment, while reading the screen rather than when the approval is refused.
 *
 * It is mounted read-only on `GET /administrator/business-schema-plans` behind the
 * `business.schema.read` capability.
 *
 * @since  2.0.0
 */
final readonly class BusinessSchemaPlansHandler implements RequestHandlerInterface
{
    /**
     * Labels for the bounded schema-plan tasks rendered as contextual tabs.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const TABS = [
        'summary' => 'Summary',
        'operations' => 'Operations',
        'approval' => 'Approval',
        'execution' => 'Execution',
        'recovery' => 'Recovery',
        'history' => 'History',
    ];

    /**
     * Sentences shown for the `notice` key each schema action redirects with.
     *
     * Message identifiers keyed by the vocabulary the sibling handlers pass to
     * `BusinessSchemaAdministratorRequest::redirect()`. Resolving through a fixed map rather than
     * echoing the query string is what keeps an arbitrary URL from putting text on the screen; an
     * unrecognised key renders no notice at all.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const NOTICES = [
        'planned' => 'core.administrator.business_schema_plans.notice_planned',
        'purge-planned' => 'core.administrator.business_schema_plans.notice_purge_planned',
        'approved' => 'core.administrator.business_schema_plans.notice_approved',
        'executed' => 'core.administrator.business_schema_plans.notice_executed',
        'recovered' => 'core.administrator.business_schema_plans.notice_recovered',
        'evidence-recorded' => 'core.administrator.business_schema_plans.notice_evidence_recorded',
    ];

    /**
     * Wire the screen to the schema facade, the environment identity, the renderer, and the clock.
     *
     * @param  BusinessSchemaService      $schemas      Answers every read the screen makes, under
     *         `business.schema.read`.
     * @param  BusinessSchemaEnvironment  $environment  Driver, server version, and application release
     *         recovery evidence must have been drilled against.
     * @param  AdministratorRenderer      $renderer     Renders the `business-schema-plans` template.
     * @param  Translator                 $translator   Resolves notice wording for the locale in flight.
     * @param  ClockInterface             $clock        Supplies now, from which the evidence freshness
     *         floor is measured.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessSchemaService $schemas,
        private BusinessSchemaEnvironment $environment,
        private AdministratorRenderer $renderer,
        private Translator $translator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Render the plans workspace for the plan, evidence, and notice named in the query string.
     *
     * With no `plan` parameter the newest persisted plan is selected, so the screen is useful straight
     * after a redirect from an action. Evidence falls back to whatever the selected plan is already
     * bound to, and the freshness floor is seven days back or the plan's own creation instant, whichever
     * is later, matching the rule the approval path applies. A qualifying flag is still only part of the
     * story: approval additionally demands the recorded clean-drill proofs, which this screen does not
     * re-check.
     *
     * @param   ServerRequestInterface  $request  Administrator GET whose query string may carry `plan`,
     *          `evidence`, and `notice`.
     *
     * @return  ResponseInterface  The rendered workspace, marked `no-store` because it carries a CSRF
     *          token and plan checksums.
     *
     * @throws  \InvalidArgumentException  When the route was mounted without administrator authentication
     *          or authorization, so no session or execution context is attached.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `business.schema.read` is
     *          refused.
     * @throws  \Kumwe\App\BusinessSchema\Application\BusinessSchemaNotFound  When the `plan` parameter
     *          names a plan outside this site.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $context = AdministratorRequest::context($request);
        $plans = $this->schemas->plans($context);
        $capabilities = AdministratorRequest::capabilityMap($request);
        $query = $request->getQueryParams();
        $activeTab = BusinessSchemaAdministratorRequest::activeTab($query['tab'] ?? null);
        $selected = is_string($query['plan'] ?? null) ? trim($query['plan']) : '';
        if ($selected === '' && $plans !== []) {
            $selected = $plans[0]->id;
        }

        $plan = $selected === '' ? null : $this->schemas->plan($context, $selected);
        $steps = $plan === null ? [] : $this->schemas->steps($context, $plan->id);
        $installation = $plan === null
            ? null
            : $this->schemas->installation($context, $plan->definitionId);

        $evidenceId = is_string($query['evidence'] ?? null) ? trim($query['evidence']) : '';
        if ($evidenceId === '' && $plan?->recoveryEvidenceId !== null) {
            $evidenceId = $plan->recoveryEvidenceId;
        }
        $evidence = $evidenceId === '' ? null : $this->schemas->recoveryEvidence($context, $evidenceId);
        $freshnessFloor = $this->clock->now()->sub(new DateInterval('P7D'));
        if ($plan !== null && $plan->createdAt > $freshnessFloor) {
            $freshnessFloor = $plan->createdAt;
        }
        $evidenceQualifies = $plan !== null
            && $evidence !== null
            && $plan->fromSchemaChecksum !== null
            && $evidence->qualifies(
                $context->site()->identifier(),
                $this->environment->databaseDriver(),
                $this->environment->databaseServerVersion(),
                $this->environment->applicationRelease(),
                $plan->fromSchemaChecksum,
                $freshnessFloor,
            );
        $noticeKey = is_string($query['notice'] ?? null) ? $query['notice'] : '';
        $noticeId = self::NOTICES[$noticeKey] ?? null;
        $notice = $noticeId === null ? null : $this->translator->translate($noticeId);

        return new HtmlResponse($this->renderer->render('business-schema-plans', [
            'csrf' => AdministratorRequest::session($request)->csrfToken,
            'capabilities' => $capabilities,
            'plans' => array_map($this->planDocument(...), $plans),
            'plan' => $plan === null ? null : $this->planDocument($plan),
            'steps' => array_map(static fn (SchemaPlanStep $step): array => $step->toArray(), $steps),
            'installation' => $installation?->toArray(),
            'evidence' => $evidence?->toArray(),
            'evidence_qualifies' => $evidenceQualifies,
            'schema_environment' => [
                'database_driver' => $this->environment->databaseDriver(),
                'database_server_version' => $this->environment->databaseServerVersion(),
                'application_release' => $this->environment->applicationRelease(),
            ],
            'definitions' => $this->schemas->definitions($context),
            'notice' => $notice,
            'active_tab' => $activeTab,
            'workspace_tabs' => $this->tabs($selected, $evidenceId),
        ]), 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Build task links while retaining the selected plan and recovery evidence context.
     *
     * @param   string  $planId      Selected plan identifier, or an empty string.
     * @param   string  $evidenceId  Selected recovery evidence identifier, or an empty string.
     *
     * @return  list<array{id: string, label: string, href: string}>  KIS contextual-tab documents.
     *
     * @since   2.0.0
     */
    private function tabs(string $planId, string $evidenceId): array
    {
        $context = [];
        if ($planId !== '') {
            $context['plan'] = $planId;
        }
        if ($evidenceId !== '') {
            $context['evidence'] = $evidenceId;
        }

        $tabs = [];
        foreach (self::TABS as $identifier => $label) {
            $tabs[] = [
                'id' => $identifier,
                'label' => $label,
                'href' => '/administrator/business-schema-plans?' . http_build_query([
                    ...$context,
                    'tab' => $identifier,
                ]),
            ];
        }

        return $tabs;
    }

    /**
     * Flatten one plan into the document the catalog and detail panels read.
     *
     * The two added flags are derived from the plan's risk band rather than stored on it. The template
     * reads them to decide whether the approval form shows the checksum-confirmation and password
     * fields, and whether the approve button stays disabled until qualifying recovery evidence is
     * selected.
     *
     * @param   SchemaPlan  $plan  Plan to present, whether as a catalog row or the selected detail.
     *
     * @return  array<string, mixed>  The plan's own fields plus `requires_high_impact` and
     *          `requires_recovery_evidence`.
     *
     * @since   2.0.0
     */
    private function planDocument(SchemaPlan $plan): array
    {
        return [
            ...$plan->toArray(),
            'requires_high_impact' => $plan->risk->requiresHighImpactAuthorization(),
            'requires_recovery_evidence' => $plan->risk->requiresRecoveryEvidence(),
        ];
    }
}
