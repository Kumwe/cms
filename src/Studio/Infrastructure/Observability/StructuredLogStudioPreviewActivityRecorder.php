<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Infrastructure\Observability;

use InvalidArgumentException;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Preview\StudioPreviewActivityRecorder;
use Kumwe\Context\Value\ExecutionContext;
use Psr\Log\LoggerInterface;

/**
 * Writes bounded ephemeral preview security activity to the structured application log.
 *
 * The allowlist intentionally excludes draft bytes and digest, HTML, grant/context tokens, channel and
 * source identifiers, sequences, markers, and marker maps. Preview staging is not an authoritative domain
 * mutation and therefore does not enter the tamper-evident business audit trail; authoritative permission
 * decisions remain recorded by the existing authorization gateway.
 *
 * @since  2.0.0
 */
final readonly class StructuredLogStudioPreviewActivityRecorder implements StudioPreviewActivityRecorder
{
    /**
     * Closed action vocabulary preventing untrusted text from becoming a log field.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array ACTIONS = ['cancel', 'document-claim', 'render', 'stylesheet'];

    /**
     * Closed outcome vocabulary.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array OUTCOMES = ['accepted', 'completed', 'refused'];

    /**
     * Bind the bounded record to the redaction-processed application log.
     *
     * @param  LoggerInterface  $logger  Structured sink used by the authorization decision trail.
     *
     * @since  2.0.0
     */
    public function __construct(private LoggerInterface $logger)
    {
    }

    /**
     * Emit only the documented low-cardinality security metadata and request correlation.
     *
     * @param   ExecutionContext           $context   Authenticated actor and request correlation.
     * @param   StudioHostSessionSnapshot  $snapshot  Trusted site and resource family.
     * @param   string                     $action    Closed preview action.
     * @param   string                     $outcome   Closed activity outcome.
     * @param   string                     $reason    Stable non-disclosing diagnostic code.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a caller supplies an unregistered action, outcome, or reason.
     *
     * @since   2.0.0
     */
    public function record(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        string $action,
        string $outcome,
        string $reason,
    ): void {
        if (
            !in_array($action, self::ACTIONS, true)
            || !in_array($outcome, self::OUTCOMES, true)
            || preg_match('/^[a-z][a-z0-9.\/-]{0,119}$/D', $reason) !== 1
        ) {
            throw new InvalidArgumentException('The Studio preview activity record is invalid.');
        }
        $record = [
            'subject' => $context->actorId(),
            'site' => $context->site()->identifier(),
            'resource_kind' => $snapshot->session->resourceKind->value,
            'resource_fingerprint' => hash('sha256', $snapshot->session->resourceId),
            'request_id' => $context->requestId(),
            'correlation_id' => $context->correlationId(),
            'action' => $action,
            'outcome' => $outcome,
            'reason' => $reason,
        ];
        if ($outcome === 'refused') {
            $this->logger->warning('Studio preview activity.', $record);
        } else {
            $this->logger->info('Studio preview activity.', $record);
        }
    }
}
