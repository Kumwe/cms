<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Delivery\Api;

use Kumwe\App\BusinessReporting\Application\ReportExecutionResult;
use Kumwe\App\BusinessReporting\Domain\ExportArtifact;
use Kumwe\App\BusinessReporting\Domain\ReportDefinition;
use Kumwe\App\BusinessReporting\Domain\ReportParameterDefinition;
use Kumwe\Context\Value\AuthenticatedSurface;

/**
 * Stable omission-safe REST representation of reports and export status.
 *
 * @since  2.0.0
 */
final class ReportApiPresenter
{
    /**
     * Present one active report for generated HTML discovery without exposing expressions or policy internals.
     *
     * @param   ReportDefinition  $definition   Active immutable report contribution.
     * @param   string            $routePrefix  Surface-specific route prefix.
     * @param   bool              $canExport    Whether the current authority may request its export.
     *
     * @return  array<string, mixed>  Safe summary plus execution and export URLs.
     *
     * @since   2.0.0
     */
    public function definition(ReportDefinition $definition, string $routePrefix, bool $canExport = false): array
    {
        $id = rawurlencode($definition->identifier());

        return [
            ...$this->summary($definition),
            'execute_url' => $routePrefix . '/' . $id,
            'export_url' => $canExport ? $routePrefix . '/' . $id . '/exports' : null,
            'can_export' => $canExport,
        ];
    }

    /**
     * Present safe report discovery metadata without expressions, policies, or physical sources.
     *
     * @param   ReportDefinition  $definition  Active immutable report contribution.
     *
     * @return  array<string, mixed>  Identifier, title, and typed parameter declarations.
     *
     * @since   2.0.0
     */
    public function summary(ReportDefinition $definition): array
    {
        return [
            'id' => $definition->identifier(),
            'title' => $definition->title,
            'parameters' => array_map(static fn (ReportParameterDefinition $parameter): array => [
                'name' => $parameter->name,
                'type' => $parameter->type->value,
                'required' => $parameter->required,
                'multiple' => $parameter->multiple,
                'default' => $parameter->defaultValue,
            ], $definition->parameters),
        ];
    }

    /**
     * Present one bounded report result with typed output metadata.
     *
     * @param   ReportExecutionResult  $result   Disclosure-safe report result.
     * @param   AuthenticatedSurface   $surface  Delivery surface used to compile generated detail links.
     *
     * @return  array<string, mixed>  JSON-ready report document.
     *
     * @since   2.0.0
     */
    public function report(
        ReportExecutionResult $result,
        AuthenticatedSurface $surface = AuthenticatedSurface::Api,
    ): array {
        $drillDowns = [];
        foreach ($result->rows as $row) {
            $links = [];
            foreach ($result->drillDowns as $drillDown) {
                $record = $row[$drillDown->recordAlias] ?? null;
                if (!is_string($record) && !is_int($record)) {
                    continue;
                }
                $links[] = [
                    'record_alias' => $drillDown->recordAlias,
                    'definition' => $drillDown->definitionIdentifier,
                    'view' => $drillDown->viewIdentifier,
                    'url' => $this->drillDownUrl(
                        $surface,
                        $drillDown->definitionIdentifier,
                        (string) $record,
                        $drillDown->viewIdentifier,
                    ),
                ];
            }
            $drillDowns[] = $links;
        }

        return [
            'report' => $result->reportIdentifier,
            'definition_checksum' => $result->definitionChecksum,
            'query_digest' => $result->queryDigest,
            'columns' => array_map(
                static fn (string $alias, string $label): array => [
                    'alias' => $alias,
                    'label' => $label,
                    'type' => $result->types[$alias]->value,
                ],
                array_keys($result->labels),
                array_values($result->labels),
            ),
            'rows' => $result->rows,
            'row_count' => count($result->rows),
            'drill_downs' => $drillDowns,
            'has_drill_downs' => array_any($drillDowns, static fn (array $links): bool => $links !== []),
        ];
    }

    /**
     * Compile a generated record-view URL without accepting report-authored paths.
     *
     * @param   AuthenticatedSurface  $surface     Authenticated delivery surface.
     * @param   string                $definition  Declared target business definition.
     * @param   string                $record      Disclosed target record identity.
     * @param   string                $view        Declared generated detail view.
     *
     * @return  string  Surface-specific generated view URL.
     *
     * @since   2.0.0
     */
    private function drillDownUrl(
        AuthenticatedSurface $surface,
        string $definition,
        string $record,
        string $view,
    ): string {
        $parts = array_map(rawurlencode(...), [$definition, $record, $view]);

        return match ($surface) {
            AuthenticatedSurface::Administrator => sprintf(
                '/administrator/business/%s/%s/views/%s',
                ...$parts,
            ),
            AuthenticatedSurface::Portal => sprintf('/portal/business/%s/%s/views/%s', ...$parts),
            default => sprintf('/api/v1/business/views/%s/%s/%s', ...$parts),
        };
    }

    /**
     * Present public export lifecycle metadata without parameters or authority-policy snapshots.
     *
     * @param   ExportArtifact  $artifact  Authorized export metadata.
     *
     * @return  array<string, mixed>  JSON-ready status document.
     *
     * @since   2.0.0
     */
    public function export(ExportArtifact $artifact): array
    {
        return [
            'id' => $artifact->id,
            'report' => $artifact->reportIdentifier,
            'status' => $artifact->status->value,
            'created_at' => $artifact->createdAt->format('Y-m-d\TH:i:s.uP'),
            'expires_at' => $artifact->expiresAt->format('Y-m-d\TH:i:s.uP'),
            'started_at' => $artifact->startedAt?->format('Y-m-d\TH:i:s.uP'),
            'completed_at' => $artifact->completedAt?->format('Y-m-d\TH:i:s.uP'),
            'filename' => $artifact->status->value === 'completed' ? $artifact->filename : null,
            'size' => $artifact->size,
            'row_count' => $artifact->rowCount,
            'checksum' => $artifact->checksum,
            'failure_code' => $artifact->failureCode,
            'version' => $artifact->version,
        ];
    }
}
