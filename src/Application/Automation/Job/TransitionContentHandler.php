<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation\Job;

use Kumwe\App\Application\Automation\JobHandler;
use Kumwe\App\Application\Automation\PermanentFailure;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Content\Application\ContentService;

/**
 * Scheduled job that moves one content record to a workflow state at a planned moment.
 *
 * This is how a timed publication or expiry is delivered: the editor stores the record, the version
 * they saw and the state to move to, and the occurrence replays that intent later. The move itself is
 * not reimplemented — it goes back through `ContentService`, so the workflow edges, the capability
 * checks and the optimistic version guard are identical to an interactive transition, and a record
 * edited in the meantime is refused rather than transitioned from stale state.
 *
 * @since  2.0.0
 */
final readonly class TransitionContentHandler implements JobHandler
{
    /**
     * Bind the handler to the service that owns content transitions.
     *
     * @param  ContentService  $content  Service the recorded transition is replayed through.
     *
     * @since  2.0.0
     */
    public function __construct(private ContentService $content)
    {
    }

    /**
     * Report the job type a schedule or queued job names this handler by.
     *
     * @return  string  The constant `content.workflow.transition`.
     *
     * @since   2.0.0
     */
    public function type(): string
    {
        return 'content.workflow.transition';
    }

    /**
     * Replay a recorded transition against the content service.
     *
     * The payload is shape-checked before anything is touched, and a payload that does not carry the
     * three expected values fails permanently, because a retry would find the same broken payload. A
     * failure raised by the transition itself — a denied capability, a version that has moved on, an
     * edge the workflow does not allow — is left to propagate so the retry policy decides its fate.
     *
     * @param   array<string, mixed>  $payload  Requires string `id`, integer `version` and string `status`.
     * @param   ExecutionContext      $context  System context naming the site that owns the record.
     *
     * @return  void
     *
     * @throws  PermanentFailure  When `id`, `version` or `status` is absent or of the wrong type.
     *
     * @since   2.0.0
     */
    public function handle(array $payload, ExecutionContext $context): void
    {
        $id = $payload['id'] ?? null;
        $version = $payload['version'] ?? null;
        $status = $payload['status'] ?? null;

        if (!is_string($id) || !is_int($version) || !is_string($status)) {
            throw new PermanentFailure('The content transition job payload is invalid.');
        }

        $this->content->transition($context, $id, $version, $status);
    }
}
