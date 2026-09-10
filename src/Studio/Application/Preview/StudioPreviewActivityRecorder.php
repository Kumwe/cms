<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Preview;

use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Security-observability boundary for ephemeral preview staging activity.
 *
 * @since  2.0.0
 */
interface StudioPreviewActivityRecorder
{
    /**
     * Record one bounded preview action without receiving content, transport secrets, or marker data.
     *
     * @param   ExecutionContext           $context   Authenticated actor and request correlation.
     * @param   StudioHostSessionSnapshot  $snapshot  Trusted site and resource family.
     * @param   string                     $action    Closed action such as `render`, `document-claim`, or
     *          `stylesheet`.
     * @param   string                     $outcome   Closed `accepted`, `completed`, or `refused` result.
     * @param   string                     $reason    Stable non-disclosing diagnostic code.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function record(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        string $action,
        string $outcome,
        string $reason,
    ): void;
}
