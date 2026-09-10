<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Preview;

use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewGrant;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewTransport;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Narrow application boundary used by the authenticated preview-document delivery adapter.
 *
 * @since  2.0.0
 */
interface StudioPreviewDocumentClaimer
{
    /**
     * Atomically claim one short-lived document under live authority and browser transport evidence.
     *
     * @param   ExecutionContext           $context    Authenticated App request authority.
     * @param   StudioHostSessionSnapshot  $snapshot   Fresh trusted Studio session.
     * @param   string                     $requestId  Session-unique render attempt.
     * @param   StudioPreviewTransport     $transport  Same-origin channel evidence.
     *
     * @return  StudioPreviewGrant|null  Single-use live grant, or null when unavailable.
     *
     * @throws  StudioPreviewRefused  When browser transport evidence is invalid or replayed.
     *
     * @since   2.0.0
     */
    public function claimDocument(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        string $requestId,
        StudioPreviewTransport $transport,
    ): ?StudioPreviewGrant;

    /**
     * Read the exact combined Producer and theme stylesheet from one live, already-claimed preview grant.
     *
     * @param   ExecutionContext           $context    Authenticated App request authority.
     * @param   StudioHostSessionSnapshot  $snapshot   Fresh trusted Studio session.
     * @param   string                     $requestId  Session-unique render attempt.
     * @param   StudioPreviewTransport     $transport  Same-origin channel evidence.
     *
     * @return  string|null  Closed generated stylesheet, or null when unavailable.
     *
     * @throws  StudioPreviewRefused  When browser transport evidence is invalid.
     *
     * @since   2.0.0
     */
    public function stylesheet(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        string $requestId,
        StudioPreviewTransport $transport,
    ): ?string;
}
