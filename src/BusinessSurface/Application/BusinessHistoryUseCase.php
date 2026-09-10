<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Application;

use Kumwe\Context\Value\ExecutionContext;

/**
 * Narrow delivery-neutral port for reading one bounded generated-business revision page.
 *
 * @since  2.0.0
 */
interface BusinessHistoryUseCase
{
    /**
     * Read caller-visible history under the exact authenticated generated surface.
     *
     * @param   ExecutionContext  $context        Authenticated actor and scope.
     * @param   BusinessSurface   $surface        Exact generated delivery boundary.
     * @param   string            $definition     Definition UUID or handle.
     * @param   string            $record         Public record identity.
     * @param   int               $limit          Maximum revisions, from 1 through 200.
     * @param   ?int              $beforeVersion  Exclusive positive record-version cursor.
     *
     * @return  array<string, mixed>  Omission-safe bounded revision page.
     *
     * @since   2.0.0
     */
    public function history(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $record,
        int $limit = 100,
        ?int $beforeVersion = null,
    ): array;
}
