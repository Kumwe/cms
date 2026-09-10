<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Mcp;

use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordQueryPurpose;
use Kumwe\App\BusinessReporting\Application\ExportService;
use Kumwe\App\BusinessReporting\Application\ReportExecutionRequest;
use Kumwe\App\BusinessReporting\Application\ReportService;
use Kumwe\App\BusinessReporting\Delivery\Api\ReportApiPresenter;
use RuntimeException;

/**
 * Context-free bounded MCP delegate for contributed reports and durable CSV artifacts.
 *
 * @since  2.0.0
 */
final readonly class ReportMcpHandlers
{
    /**
     * Largest artifact that may be embedded in one bounded MCP result.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAX_INLINE_DOWNLOAD_BYTES = 1_048_576;

    /**
     * Bind shared report use cases and their omission-safe presenter.
     *
     * @param  ReportService       $reports    Synchronous report executor and discovery authority.
     * @param  ExportService       $exports    Durable export lifecycle service.
     * @param  ReportApiPresenter  $presenter  Stable transport-neutral projection.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ReportService $reports,
        private ExportService $exports,
        private ReportApiPresenter $presenter,
    ) {
    }

    /**
     * List active reports whose declared capability the current principal holds.
     *
     * @param   ExecutionContext  $context  Authenticated MCP actor and scope.
     *
     * @return  array{items: list<array<string, mixed>>}  Bounded safe report summaries.
     *
     * @since   2.0.0
     */
    public function list(ExecutionContext $context): array
    {
        $items = [];
        foreach ($this->reports->available($context) as $definition) {
            $items[] = $this->presenter->summary($definition);
        }

        return ['items' => $items];
    }

    /**
     * Execute one active report through the shared policy-filtered service.
     *
     * @param   ExecutionContext      $context     Authenticated MCP actor and scope.
     * @param   string                $report      Namespaced report identifier.
     * @param   array<string, mixed>  $parameters  Typed values keyed by declared parameter name.
     *
     * @return  array<string, mixed>  Bounded report result document.
     *
     * @since   2.0.0
     */
    public function execute(ExecutionContext $context, string $report, array $parameters = []): array
    {
        return $this->presenter->report($this->reports->execute(new ReportExecutionRequest(
            $context,
            $report,
            $parameters,
            $context->organization()?->identifier(),
            BusinessRecordQueryPurpose::Report,
        )));
    }

    /**
     * Queue one policy-bound immutable CSV export.
     *
     * @param   ExecutionContext      $context           Authenticated MCP actor and scope.
     * @param   string                $report            Namespaced report identifier.
     * @param   array<string, mixed>  $parameters        Typed declared report parameters.
     * @param   int                   $retentionSeconds  Artifact lifetime from one minute through seven days.
     *
     * @return  array<string, mixed>  Queued export lifecycle metadata.
     *
     * @since   2.0.0
     */
    public function requestExport(
        ExecutionContext $context,
        string $report,
        array $parameters = [],
        int $retentionSeconds = 86_400,
    ): array {
        return $this->presenter->export($this->exports->request(
            $context,
            $report,
            $parameters,
            $context->organization()?->identifier(),
            $retentionSeconds,
        ));
    }

    /**
     * Read current authorized lifecycle metadata for one export artifact.
     *
     * @param   ExecutionContext  $context   Authenticated MCP actor and scope.
     * @param   string            $artifact  Export artifact UUID.
     *
     * @return  array<string, mixed>  Current safe export status.
     *
     * @since   2.0.0
     */
    public function exportStatus(ExecutionContext $context, string $artifact): array
    {
        return $this->presenter->export($this->exports->status($context, $artifact));
    }

    /**
     * Return a completed artifact inline only while it stays under the protocol-safe one-megabyte ceiling.
     *
     * @param   ExecutionContext  $context   Authenticated MCP actor and scope.
     * @param   string            $artifact  Completed export artifact UUID.
     *
     * @return  array{filename: string, size: int, checksum: string, encoding: string, content: string}
     *          Verified artifact bytes represented as bounded base64.
     *
     * @since   2.0.0
     */
    public function downloadExport(ExecutionContext $context, string $artifact): array
    {
        $download = $this->exports->download($context, $artifact);
        if ($download->size > self::MAX_INLINE_DOWNLOAD_BYTES) {
            fclose($download->stream);
            throw new McpToolRefusal(
                'result.too_large',
                'The result is too large for bounded MCP delivery.',
            );
        }
        $bytes = stream_get_contents($download->stream, self::MAX_INLINE_DOWNLOAD_BYTES + 1);
        fclose($download->stream);
        if (
            !is_string($bytes) || strlen($bytes) !== $download->size
            || !hash_equals($download->checksum, hash('sha256', $bytes))
        ) {
            throw new RuntimeException('The export could not be verified for MCP download.');
        }

        return [
            'filename' => $download->filename,
            'size' => $download->size,
            'checksum' => $download->checksum,
            'encoding' => 'base64',
            'content' => base64_encode($bytes),
        ];
    }
}
