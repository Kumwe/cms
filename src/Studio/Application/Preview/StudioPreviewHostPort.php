<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Preview;

use DateInterval;
use InvalidArgumentException;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Studio\Application\Composition\StudioCompositionThemeMismatch;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Host\StudioProducerError;
use Kumwe\App\Studio\Application\Host\StudioProducerRequestAuthority;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewGrant;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewRenderRequest;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewTransport;
use Kumwe\Producer\Wire\HostResult;
use Kumwe\Producer\Wire\Port\PreviewPortInterface;
use Kumwe\Producer\Wire\RequestContext;
use Psr\Clock\ClockInterface;
use stdClass;
use Throwable;

/**
 * Complete `preview.render` and `preview.cancel` host-port implementation.
 *
 * @since  2.0.0
 */
final readonly class StudioPreviewHostPort implements PreviewPortInterface, StudioPreviewDocumentClaimer
{
    /**
     * Maximum lifetime of an unpublished rendered document grant.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string GRANT_LIFETIME = 'PT60S';

    /**
     * Compose canonical draft resolution, rendering, replay persistence and browser transport checks.
     *
     * @param  StudioPreviewDraftSource             $drafts     Narrow AP-4 draft reader.
     * @param  StudioPreviewBindingSource           $bindings   Content-authority binding resolver.
     * @param  StudioPreviewRenderer                $renderer   Shared canonical site rendering path.
     * @param  StudioPreviewGrantRepository         $grants     Pending/cancel/single-use grant ledger.
     * @param  StudioPreviewTransportGuard          $guard      Origin/channel/source/sequence fence.
     * @param  StudioPreviewActivityRecorder        $activity   Bounded ephemeral security activity trail.
     * @param  ClockInterface                       $clock      Trusted expiry clock.
     * @param  StudioProducerRequestAuthority|null  $authority  Authorized Producer request scope, when bound.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioPreviewDraftSource $drafts,
        private StudioPreviewBindingSource $bindings,
        private StudioPreviewRenderer $renderer,
        private StudioPreviewGrantRepository $grants,
        private StudioPreviewTransportGuard $guard,
        private StudioPreviewActivityRecorder $activity,
        private ClockInterface $clock,
        private ?StudioProducerRequestAuthority $authority = null,
    ) {
    }

    /**
     * Bind this App-owned port implementation to one successfully authorized Producer request.
     *
     * @param   StudioProducerRequestAuthority  $authority  Trusted evidence for one exact dispatch.
     *
     * @return  self  Request-scoped preview port.
     *
     * @since   2.0.0
     */
    public function forRequest(StudioProducerRequestAuthority $authority): self
    {
        return new self(
            $this->drafts,
            $this->bindings,
            $this->renderer,
            $this->grants,
            $this->guard,
            $this->activity,
            $this->clock,
            $authority,
        );
    }

    /**
     * Render one exact unpublished draft through the authenticated browser-preview channel.
     *
     * @param   mixed           $arguments  Validated Producer operation arguments.
     * @param   RequestContext  $context    Validated Producer request context.
     *
     * @return  HostResult  Exact rendered payload.
     *
     * @since   2.0.0
     */
    public function render(mixed $arguments, RequestContext $context): HostResult
    {
        self::assertReadContext($context);

        return $this->invoke(
            'render',
            fn (
                ExecutionContext $execution,
                StudioHostSessionSnapshot $snapshot,
                StudioPreviewTransport $transport,
            ): stdClass => $this->renderValue($execution, $arguments, $snapshot, $transport),
        );
    }

    /**
     * Cancel one matching unpublished render attempt through the authenticated preview channel.
     *
     * @param   mixed           $arguments  Validated Producer operation arguments.
     * @param   RequestContext  $context    Validated Producer request context.
     *
     * @return  HostResult  Canonical empty cancellation acknowledgement.
     *
     * @since   2.0.0
     */
    public function cancel(mixed $arguments, RequestContext $context): HostResult
    {
        self::assertReadContext($context);

        return $this->invoke(
            'cancel',
            fn (
                ExecutionContext $execution,
                StudioHostSessionSnapshot $snapshot,
                StudioPreviewTransport $transport,
            ): null => $this->cancelValue($arguments, $snapshot, $transport),
        );
    }

    /**
     * Claim and render one exact unpublished draft, discarding late results after cancellation.
     *
     * @param   ExecutionContext           $context    Authenticated App request authority.
     * @param   mixed                      $arguments  Canonical operation arguments carrying exact `{payload}`.
     * @param   StudioHostSessionSnapshot  $snapshot   Live trusted host authority.
     * @param   StudioPreviewTransport     $transport  Accepted browser transport evidence.
     *
     * @return  stdClass  Exact `preview-message#/$defs/rendered` payload.
     *
     * @throws  StudioPreviewRefused  When the render cannot safely complete.
     *
     * @since   2.0.0
     */
    private function renderValue(
        ExecutionContext $context,
        mixed $arguments,
        StudioHostSessionSnapshot $snapshot,
        StudioPreviewTransport $transport,
    ): stdClass {
        $arguments = self::exactArguments($arguments, ['payload']);
        try {
            $request = StudioPreviewRenderRequest::fromPayload($arguments->payload);
        } catch (InvalidArgumentException) {
            throw new StudioPreviewRefused('invalid-request', 'studio.preview/invalid-render-payload');
        }
        if (
            !in_array('studio.permission/read', $snapshot->permissions, true)
        ) {
            throw new StudioPreviewRefused('forbidden', 'studio.preview/resource-refused');
        }
        $draft = $this->drafts->find($snapshot, $request);
        if ($draft === null) {
            throw new StudioPreviewRefused('not-found', 'studio.preview/draft-not-found');
        }
        if (
            !hash_equals($request->artifactId, $draft->artifactId())
            || !hash_equals($request->draftRevision, $draft->revision())
            || !hash_equals($request->draftDigest, $draft->digest())
        ) {
            throw new StudioPreviewRefused('conflict', 'studio.preview/draft-identity-mismatch');
        }
        $values = $this->bindings->resolve($context, $snapshot, $draft);
        $expiresAt = $this->clock->now()->add(new DateInterval(self::GRANT_LIFETIME));
        $admission = $this->grants->begin($snapshot, $request, $transport, $expiresAt);
        if ($admission === StudioPreviewRenderAdmission::Replayed) {
            throw new StudioPreviewRefused('invalid-request', 'studio.preview/request-replayed');
        }
        if ($admission === StudioPreviewRenderAdmission::Cancelled) {
            throw new StudioPreviewRefused('cancelled', 'studio.preview/render-cancelled');
        }
        try {
            $rendered = $this->renderer->render($snapshot, $draft, $request, $values);
        } catch (StudioCompositionThemeMismatch) {
            $this->grants->abandon($snapshot->session->resourceContextKey, $request->requestId);
            throw new StudioPreviewRefused('conflict', 'studio.preview/theme-lock-mismatch');
        } catch (Throwable) {
            $this->grants->abandon($snapshot->session->resourceContextKey, $request->requestId);
            throw new StudioPreviewRefused('unavailable', 'studio.preview/render-failed');
        }
        if (!$this->grants->complete($snapshot->session->resourceContextKey, $request, $rendered)) {
            throw new StudioPreviewRefused('cancelled', 'studio.preview/render-cancelled');
        }

        return (object) [
            'draftDigest' => $request->draftDigest,
            'markers' => $rendered->markers,
            'markerMap' => (object) $rendered->markerMap,
            'diagnostics' => $rendered->diagnostics,
            'requestId' => $request->requestId,
        ];
    }

    /**
     * Cancel only the matching digest inside this trusted resource context.
     *
     * @param   mixed                      $arguments  Canonical arguments carrying exact `{draftDigest}`.
     * @param   StudioHostSessionSnapshot  $snapshot   Live trusted host authority.
     * @param   StudioPreviewTransport     $transport  Accepted cancellation transport evidence.
     *
     * @return  null  Exact PreviewPort cancellation result.
     *
     * @throws  StudioPreviewRefused  When the exact `{draftDigest}` wrapper is malformed.
     *
     * @since   2.0.0
     */
    private function cancelValue(
        mixed $arguments,
        StudioHostSessionSnapshot $snapshot,
        StudioPreviewTransport $transport,
    ): null {
        $arguments = self::exactArguments($arguments, ['draftDigest']);
        if (!is_string($arguments->draftDigest) || preg_match('/^[a-f0-9]{64}$/D', $arguments->draftDigest) !== 1) {
            throw new StudioPreviewRefused('invalid-request', 'studio.preview/invalid-cancel-payload');
        }
        $this->grants->cancel(
            $snapshot->session->resourceContextKey,
            $arguments->draftDigest,
            $transport->sequence,
        );

        return null;
    }

    /**
     * Apply the preview transport fence, activity trail and canonical Producer refusal translation.
     *
     * @param string $action Closed action.
     * @param   callable(ExecutionContext, StudioHostSessionSnapshot, StudioPreviewTransport): mixed  $operation
     *          Preview operation body.
     *
     * @return  HostResult  Canonical rendered value or cancellation acknowledgement.
     *
     * @since   2.0.0
     */
    private function invoke(string $action, callable $operation): HostResult
    {
        $authority = $this->requestAuthority();
        $context = $authority->context();
        $snapshot = $authority->snapshot();
        $transport = $authority->previewTransport();
        if ($transport === null) {
            StudioProducerError::refuse('invalid-request', 'studio.preview/invalid-transport');
        }
        try {
            $this->guard->authorize($snapshot, $transport, 'port');
            $this->record($context, $snapshot, $action, 'accepted', 'studio.preview/transport-accepted');
            $value = $operation($context, $snapshot, $transport);
            $this->record($context, $snapshot, $action, 'completed', 'studio.preview/' . $action . '-completed');

            return new HostResult($value);
        } catch (StudioPreviewRefused $refused) {
            try {
                $this->record($context, $snapshot, $action, 'refused', $refused->diagnosticCode);
            } catch (StudioPreviewRefused $activityFailure) {
                $refused = $activityFailure;
            }
            StudioProducerError::refuse($refused->category, $refused->diagnosticCode);
        }
    }

    /**
     * Refuse mutation-only context on Producer's read-only preview operations.
     *
     * @param   RequestContext  $context  Validated Producer request context.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertReadContext(RequestContext $context): void
    {
        if ($context->expectedRevision !== null || $context->idempotencyKey !== null) {
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-context');
        }
    }

    /**
     * Require the per-request authority installed by the Producer host factory.
     *
     * @return  StudioProducerRequestAuthority  Trusted evidence for this dispatch.
     *
     * @since   2.0.0
     */
    private function requestAuthority(): StudioProducerRequestAuthority
    {
        return $this->authority ?? throw new \LogicException('A Studio preview port requires request authority.');
    }

    /**
     * Require an exact HTTP wrapper without conflating it with the raw testkit vector argument.
     *
     * @param   mixed         $candidate  Candidate wrapper.
     * @param   list<string>  $expected   Exact member set.
     *
     * @return  stdClass  Closed wrapper.
     *
     * @throws  StudioPreviewRefused  When the wrapper is absent, aliased or extended.
     *
     * @since   2.0.0
     */
    private static function exactArguments(mixed $candidate, array $expected): stdClass
    {
        $members = $candidate instanceof stdClass ? array_keys(get_object_vars($candidate)) : [];
        sort($members, SORT_STRING);
        $sorted = $expected;
        sort($sorted, SORT_STRING);
        if (!$candidate instanceof stdClass || $members !== $sorted) {
            throw new StudioPreviewRefused('invalid-request', 'studio.host/invalid-arguments');
        }

        return $candidate;
    }

    /**
     * Claim a completed preview document for the authenticated GET endpoint.
     *
     * @param   ExecutionContext           $context    Authenticated App request authority.
     * @param   StudioHostSessionSnapshot  $snapshot   Live trusted host authority.
     * @param   string                     $requestId  Session-unique render attempt.
     * @param   StudioPreviewTransport     $transport  Browser navigation evidence.
     *
     * @return  StudioPreviewGrant|null  Single-use live grant or null after replay/cancellation/expiry.
     *
     * @throws  StudioPreviewRefused  When origin, channel, source or sequence is invalid.
     *
     * @since   2.0.0
     */
    public function claimDocument(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        string $requestId,
        StudioPreviewTransport $transport,
    ): ?StudioPreviewGrant {
        try {
            $this->guard->authorize($snapshot, $transport, 'document');
            $this->record(
                $context,
                $snapshot,
                'document-claim',
                'accepted',
                'studio.preview/transport-accepted',
            );
            $grant = $this->grants->claim($snapshot, $requestId, $transport, $this->clock->now());
            $this->record(
                $context,
                $snapshot,
                'document-claim',
                $grant === null ? 'refused' : 'completed',
                $grant === null ? 'studio.preview/grant-unavailable' : 'studio.preview/document-claim-completed',
            );

            return $grant;
        } catch (StudioPreviewRefused $refused) {
            $this->record($context, $snapshot, 'document-claim', 'refused', $refused->diagnosticCode);
            throw $refused;
        }
    }

    /**
     * Read the exact combined stylesheet of one live, already-claimed preview document.
     *
     * @param   ExecutionContext           $context    Authenticated App request authority.
     * @param   StudioHostSessionSnapshot  $snapshot   Live trusted host authority.
     * @param   string                     $requestId  Session-unique render attempt.
     * @param   StudioPreviewTransport     $transport  Same-origin grant identity evidence.
     *
     * @return  string|null  Exact generated CSS, or null after absence, mismatch or expiry.
     *
     * @throws  StudioPreviewRefused  When origin, channel or source is invalid.
     *
     * @since   2.0.0
     */
    public function stylesheet(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        string $requestId,
        StudioPreviewTransport $transport,
    ): ?string {
        try {
            $this->guard->authorizeIdentity($snapshot, $transport);
            $this->record(
                $context,
                $snapshot,
                'stylesheet',
                'accepted',
                'studio.preview/transport-accepted',
            );
            $grant = $this->grants->claimed($snapshot, $requestId, $transport, $this->clock->now());
            $stylesheet = $grant?->document->stylesheet;
            $this->record(
                $context,
                $snapshot,
                'stylesheet',
                $stylesheet === null ? 'refused' : 'completed',
                $stylesheet === null
                    ? 'studio.preview/stylesheet-unavailable'
                    : 'studio.preview/stylesheet-completed',
            );

            return $stylesheet;
        } catch (StudioPreviewRefused $refused) {
            $this->record($context, $snapshot, 'stylesheet', 'refused', $refused->diagnosticCode);
            throw $refused;
        }
    }

    /**
     * Record bounded preview activity and fail closed if the security trail is unavailable.
     *
     * @param   ExecutionContext           $context   Authenticated actor and request correlation.
     * @param   StudioHostSessionSnapshot  $snapshot  Trusted site and resource family.
     * @param   string                     $action    Closed preview action.
     * @param   string                     $outcome   Closed activity outcome.
     * @param   string                     $reason    Stable non-disclosing diagnostic code.
     *
     * @return  void
     *
     * @throws  StudioPreviewRefused  When the structured record does not reach its sink.
     *
     * @since   2.0.0
     */
    private function record(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        string $action,
        string $outcome,
        string $reason,
    ): void {
        try {
            $this->activity->record($context, $snapshot, $action, $outcome, $reason);
        } catch (Throwable) {
            throw new StudioPreviewRefused('unavailable', 'studio.preview/activity-record-unavailable');
        }
    }
}
