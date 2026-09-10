<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Http\Handler;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use Kumwe\App\Administrator\Automation\AutomationJobFormRegistry;
use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\App\Application\Automation\AutomationManagementService;
use Kumwe\Context\Value\ExecutionContext;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves the administrator automation screen and applies the schedule and job actions it posts back.
 *
 * Recurring schedules and the jobs they produce are managed from one screen behind the
 * `automation.manage` capability, so an operator can see a schedule beside the runs it caused. The
 * screen is deliberately job-type agnostic: it asks `AutomationJobFormRegistry` what fields each job
 * type advertises and renders those, which is how an extension's job type gets a usable form without
 * touching this handler. Every `POST` performs one action and redirects, so a refresh cannot re-run
 * a retry or create a second schedule.
 *
 * @since  2.0.0
 */
final readonly class AdministratorAutomationHandler implements RequestHandlerInterface
{
    /**
     * Wire the screen to the automation service and the job-form vocabulary it renders.
     *
     * @param  AutomationManagementService  $automation  Reads and mutates schedules and queued jobs.
     * @param  AdministratorRenderer        $renderer    Renders the `automation` template.
     * @param  AutomationJobFormRegistry    $forms       Per-job-type fields the screen renders and validates.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AutomationManagementService $automation,
        private AdministratorRenderer $renderer,
        private AutomationJobFormRegistry $forms,
    ) {
    }

    /**
     * Render the automation screen, first applying the action a `POST` carries.
     *
     * The job list is capped at the two hundred most recent runs, which keeps the screen a working
     * tail rather than an archive; anything older is read from the job store directly.
     *
     * @param   ServerRequestInterface  $request  Administrator request, already authenticated and CSRF-checked.
     *
     * @return  ResponseInterface  The rendered screen, or a 303 redirect to `?saved=1` after an action.
     *
     * @throws  InvalidArgumentException  When a required field is missing or the action is not supported.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = AdministratorRequest::session($request);

        if (strtoupper($request->getMethod()) === 'POST') {
            $this->mutate(AdministratorRequest::context($request), AdministratorRequest::form($request));

            return new RedirectResponse('/administrator/automation?saved=1', 303);
        }

        $context = AdministratorRequest::context($request);
        $jobTypes = $this->automation->jobTypes($context);
        return new HtmlResponse($this->renderer->render('automation', [
            'csrf' => $session->csrfToken,
            'capabilities' => AdministratorRequest::capabilityMap($request),
            'schedules' => $this->automation->schedules($context),
            'jobs' => $this->automation->jobs($context, 200),
            'job_types' => $this->forms->definitions($jobTypes),
            'saved' => ($request->getQueryParams()['saved'] ?? null) === '1',
        ]), 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Apply the single automation operation named by the form's `action` field.
     *
     * Enable and disable share one branch because they differ only in the flag they pass. Every
     * schedule change carries the version the operator loaded, so a concurrent edit is rejected by
     * the service rather than silently overwritten here.
     *
     * @param   ExecutionContext       $context  Actor and site the change is authorised and audited against.
     * @param   array<string, string>  $form     Flattened form as returned by `AdministratorRequest::form()`.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a required field is missing or `action` names no known operation.
     *
     * @since   2.0.0
     */
    private function mutate(ExecutionContext $context, array $form): void
    {
        $action = AdministratorRequest::required($form, 'action');

        switch ($action) {
            case 'schedule.create':
                $jobType = AdministratorRequest::required($form, 'job_type');
                $this->automation->createSchedule(
                    $context,
                    AdministratorRequest::required($form, 'name'),
                    AdministratorRequest::required($form, 'cron_expression'),
                    AdministratorRequest::required($form, 'timezone'),
                    $jobType,
                    $this->payload($form, $jobType),
                    AdministratorRequest::required($form, 'queue'),
                    $this->firstRun(AdministratorRequest::required($form, 'first_run')),
                );
                return;
            case 'schedule.enable':
            case 'schedule.disable':
                $this->automation->setScheduleEnabled(
                    $context,
                    AdministratorRequest::required($form, 'id'),
                    AdministratorRequest::positiveInteger($form, 'version'),
                    $action === 'schedule.enable',
                );
                return;
            case 'schedule.delete':
                $this->automation->deleteSchedule(
                    $context,
                    AdministratorRequest::required($form, 'id'),
                    AdministratorRequest::positiveInteger($form, 'version'),
                );
                return;
            case 'job.retry':
                $this->automation->retryJob($context, AdministratorRequest::required($form, 'id'));
                return;
            case 'job.cancel':
                $this->automation->cancelJob($context, AdministratorRequest::required($form, 'id'));
                return;
            default:
                throw new InvalidArgumentException('The automation action is not supported.');
        }
    }

    /**
     * Resolve the job payload from either the raw JSON field or the job type's own form fields.
     *
     * A screen rendering typed fields for a job type posts those fields and no `payload` at all, so
     * the absence of the key is what selects the registry-driven reader. The raw field remains the
     * path for job types that advertise no fields.
     *
     * @param   array<string, string>  $form     Flattened form carrying either `payload` or the typed fields.
     * @param   string                 $jobType  Job type whose field vocabulary interprets the form.
     *
     * @return  array<string, mixed>  The payload object stored on the schedule.
     *
     * @throws  InvalidArgumentException  When `payload` is not valid JSON or does not decode to an object.
     *
     * @since   2.0.0
     */
    private function payload(array $form, string $jobType): array
    {
        if (!array_key_exists('payload', $form)) {
            return $this->forms->payload($jobType, $form);
        }
        try {
            $payload = json_decode($form['payload'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The job payload must be valid JSON.', 0, $exception);
        }

        if (!is_array($payload) || array_is_list($payload)) {
            throw new InvalidArgumentException('The job payload must be a JSON object.');
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * Read the first-run instant from the value an HTML `datetime-local` control posts.
     *
     * The control sends no time zone, so the value is anchored to UTC rather than the server's
     * default and a schedule means the same thing wherever it was created. The parser's warnings are
     * inspected as well as its return value, because a value such as `2026-02-31T00:00` rolls over
     * into a valid date instead of failing.
     *
     * @param   string  $value  Field value in `Y-m-d\TH:i` form, as posted by the browser.
     *
     * @return  DateTimeImmutable  The instant, interpreted in UTC.
     *
     * @throws  InvalidArgumentException  When the value is malformed or names a date that does not exist.
     *
     * @since   2.0.0
     */
    private function firstRun(string $value): DateTimeImmutable
    {
        $firstRun = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        $invalid = $firstRun === false
            || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0));

        if ($invalid) {
            throw new InvalidArgumentException('The first run must be a valid UTC date and time.');
        }

        return $firstRun;
    }
}
