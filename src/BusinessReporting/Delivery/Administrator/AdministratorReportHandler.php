<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Delivery\Administrator;

use InvalidArgumentException;
use JsonException;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\App\Administrator\Http\Middleware\AdministratorCsrfMiddleware;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordQueryPurpose;
use Kumwe\App\BusinessReporting\Application\ExportArtifactUnavailable;
use Kumwe\App\BusinessReporting\Application\ExportService;
use Kumwe\App\BusinessReporting\Application\ReportExecutionRequest;
use Kumwe\App\BusinessReporting\Application\ReportRowLimitExceeded;
use Kumwe\App\BusinessReporting\Application\ReportService;
use Kumwe\App\BusinessReporting\Application\ReportUnavailable;
use Kumwe\App\BusinessReporting\Delivery\Api\ReportApiPresenter;
use Kumwe\App\BusinessReporting\Delivery\Browser\ReportParameterInput;
use Kumwe\App\BusinessReporting\Domain\ReportDefinition;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Administrator HTML adapter for report execution and export lifecycle pages.
 *
 * @since  2.0.0
 */
final readonly class AdministratorReportHandler implements RequestHandlerInterface
{
    /**
     * Wire protected administrator rendering to shared report services.
     *
     * @param  ReportService           $reports    Synchronous report executor and discovery authority.
     * @param  ExportService           $exports    Queued export use cases.
     * @param  ReportApiPresenter      $presenter  Safe view-data projection.
     * @param  AdministratorRenderer   $renderer   Protected administrator renderer.
     * @param  StreamFactoryInterface  $streams    Wraps verified download resources in PSR streams.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ReportService $reports,
        private ExportService $exports,
        private ReportApiPresenter $presenter,
        private AdministratorRenderer $renderer,
        private StreamFactoryInterface $streams,
    ) {
    }

    /**
     * Render the exact operation declared by the route.
     *
     * @param   ServerRequestInterface  $request  Authenticated administrator request.
     *
     * @return  ResponseInterface  No-store report or export-status HTML, including a generic 422 error page.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $context = AdministratorRequest::context($request);
        $session = AdministratorRequest::session($request);
        $operation = $this->operation($request);
        $reportId = $request->getAttribute('report');
        $artifactId = $request->getAttribute('artifact');
        $data = ['report_result' => null, 'export' => null, 'report_error' => null];
        $status = 200;
        try {
            if ($operation === 'execute' && is_string($reportId)) {
                $data['report_result'] = $this->presenter->report($this->reports->execute(
                    new ReportExecutionRequest(
                        $context,
                        $reportId,
                        $this->parameters($request, $context, $reportId, BusinessRecordQueryPurpose::Report),
                        $context->organization()?->identifier(),
                        BusinessRecordQueryPurpose::Report,
                    ),
                ), AuthenticatedSurface::Administrator);
            } elseif ($operation === 'export_request' && is_string($reportId)) {
                $data['export'] = $this->presenter->export($this->exports->request(
                    $context,
                    $reportId,
                    $this->parameters($request, $context, $reportId, BusinessRecordQueryPurpose::Export),
                    $context->organization()?->identifier(),
                ));
            } elseif ($operation === 'export_status' && is_string($artifactId)) {
                $data['export'] = $this->presenter->export($this->exports->status($context, $artifactId));
            } elseif ($operation === 'export_download' && is_string($artifactId)) {
                $download = $this->exports->download($context, $artifactId);
                return new Response($this->streams->createStreamFromResource($download->stream), 200, [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                    'Content-Length' => (string) $download->size,
                    'Content-Disposition' => 'attachment; filename*=UTF-8\'\'' . rawurlencode($download->filename),
                    'ETag' => '"sha256-' . $download->checksum . '"',
                    'Cache-Control' => 'no-store',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            } elseif ($operation !== 'index') {
                throw new InvalidArgumentException('The administrator report route operation is invalid.');
            }
        } catch (ExportArtifactUnavailable | ReportUnavailable) {
            return new EmptyResponse(404, ['Cache-Control' => 'no-store']);
        } catch (InvalidArgumentException | ReportRowLimitExceeded) {
            $data['report_error'] = 'The report request could not be accepted. Review the parameters or use a '
                . 'queued export for large results, then try again.';
            $status = 422;
        }
        $data['reports'] = array_map(
            fn (ReportDefinition $definition): array => $this->presenter->definition(
                $definition,
                '/administrator/reports',
                $this->reports->isAvailable($context, $definition, BusinessRecordQueryPurpose::Export),
            ),
            $this->reports->available($context),
        );
        $data['capabilities'] = AdministratorRequest::capabilityMap($request);
        $data['csrf'] = $session->csrfToken;
        $data['administrator_session'] = $session;
        $data['active_navigation'] = 'core.business-reports';

        return new HtmlResponse($this->renderer->render('business-report', $data), $status, [
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Decode a nested API-style parameter object or the graphical JSON editor value.
     *
     * @param   ServerRequestInterface      $request   Report submission carrying its declared parameters.
     * @param   ExecutionContext            $context   Authenticated administrator authority.
     * @param   string                      $reportId  Exact route report identifier.
     * @param   BusinessRecordQueryPurpose  $purpose   Report or export policy purpose.
     *
     * @return  array<string, mixed>  String-keyed parameter values for the report definition.
     *
     * @since   2.0.0
     */
    private function parameters(
        ServerRequestInterface $request,
        ExecutionContext $context,
        string $reportId,
        BusinessRecordQueryPurpose $purpose,
    ): array {
        $source = $request->getQueryParams();
        if (strtoupper($request->getMethod()) !== 'GET') {
            $source = $request->getAttribute(AdministratorCsrfMiddleware::ATTRIBUTE_PARSED_BODY);
            if (!is_array($source)) {
                $source = AdministratorRequest::parsedBody($request);
            }
        }
        $parameters = $source['parameters'] ?? null;
        if ($parameters !== null && array_key_exists('parameters_json', $source)) {
            throw new InvalidArgumentException('Administrator report parameter representations cannot be mixed.');
        }
        if ($parameters === null && isset($source['parameters_json']) && is_string($source['parameters_json'])) {
            try {
                $parameters = json_decode($source['parameters_json'], true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException(
                    'Administrator report parameters must be valid JSON.',
                    0,
                    $exception,
                );
            }
        }
        $native = array_key_exists('parameters', $source);
        $parameters ??= [];
        if (!is_array($parameters) || ($parameters !== [] && array_is_list($parameters))) {
            throw new InvalidArgumentException('Administrator report parameters must form an object.');
        }

        /** @var array<string, mixed> $parameters */
        if (!$native) {
            return $parameters;
        }

        return ReportParameterInput::map(
            $this->reportDefinition($context, $reportId, $purpose)->parameters,
            $parameters,
        );
    }

    /**
     * Resolve metadata only from the same purpose-filtered discovery service used by execution.
     *
     * @param   ExecutionContext            $context   Authenticated administrator authority.
     * @param   string                      $reportId  Exact route report identifier.
     * @param   BusinessRecordQueryPurpose  $purpose   Report or export policy purpose.
     *
     * @return  ReportDefinition  Policy-visible immutable declaration.
     *
     * @throws  ReportUnavailable  When the identifier is absent or unavailable for this purpose.
     *
     * @since   2.0.0
     */
    private function reportDefinition(
        ExecutionContext $context,
        string $reportId,
        BusinessRecordQueryPurpose $purpose,
    ): ReportDefinition {
        foreach ($this->reports->available($context, $purpose) as $definition) {
            if (hash_equals($definition->identifier(), $reportId)) {
                return $definition;
            }
        }

        throw new ReportUnavailable('The report is unavailable.');
    }

    /**
     * Resolve an explicit operation or the closed administrator report route set.
     *
     * @param   ServerRequestInterface  $request  Matched administrator request.
     *
     * @return  string  One closed handler operation, or an empty string for an unknown route.
     *
     * @since   2.0.0
     */
    private function operation(ServerRequestInterface $request): string
    {
        $operation = $request->getAttribute('operation');
        if (is_string($operation) && $operation !== '') {
            return $operation;
        }

        $method = strtoupper($request->getMethod());
        $path = rtrim($request->getUri()->getPath(), '/');

        return match (true) {
            $method === 'GET' && $path === '/administrator/reports' => 'index',
            $method === 'POST'
                && preg_match('#^/administrator/reports/[^/]+/exports$#D', $path) === 1
                => 'export_request',
            $method === 'POST'
                && preg_match('#^/administrator/reports/[^/]+$#D', $path) === 1
                => 'execute',
            $method === 'GET'
                && preg_match('#^/administrator/reports/exports/[^/]+/download$#D', $path) === 1
                => 'export_download',
            $method === 'GET'
                && preg_match('#^/administrator/reports/exports/[^/]+$#D', $path) === 1
                => 'export_status',
            default => '',
        };
    }
}
