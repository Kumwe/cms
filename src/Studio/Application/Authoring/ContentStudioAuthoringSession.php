<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use stdClass;

/**
 * One authorized contextual authoring session as PHP re-derives it for a single host operation.
 *
 * The value pairs the opaque host session Studio addresses with the exact Content target its
 * opaque authoring context binds, plus the deterministic session identity and return pointer every
 * emitted document must repeat. It is rebuilt from trusted state on every request and never trusts
 * a coordinate the browser supplied.
 *
 * @since  2.0.0
 */
final readonly class ContentStudioAuthoringSession
{
    /**
     * Bind the trusted coordinates of one dispatch.
     *
     * @param  StudioHostSession             $host         Opened contextual host session.
     * @param  ContentStudioAuthoringTarget  $target       Freshly re-authorized Content target.
     * @param  string                        $generation   Live session generation proven by authorization.
     * @param  list<string>                  $permissions  Sorted canonical Studio permissions in force.
     *
     * @since  2.0.0
     */
    public function __construct(
        public StudioHostSession $host,
        public ContentStudioAuthoringTarget $target,
        public string $generation,
        public array $permissions,
    ) {
    }

    /**
     * The opaque host-session key Studio carries as `resourceContext.key`.
     *
     * @return  string  Opaque key.
     *
     * @since   2.0.0
     */
    public function key(): string
    {
        return $this->host->resourceContextKey;
    }

    /**
     * The deterministic Studio session identifier of this host session.
     *
     * @return  string  Stable identifier.
     *
     * @since   2.0.0
     */
    public function sessionId(): string
    {
        return ContentStudioAuthoringDocuments::sessionId($this->host->resourceContextKey);
    }

    /**
     * The resource context every document of this session repeats.
     *
     * @return  stdClass  Canonical resource context.
     *
     * @since   2.0.0
     */
    public function resourceContext(): stdClass
    {
        return ContentStudioAuthoringDocuments::resourceContext($this->host, $this->target);
    }

    /**
     * The host-minted return pointer of the session before any accepted save.
     *
     * @return  stdClass  Canonical return context.
     *
     * @since   2.0.0
     */
    public function returnContext(): stdClass
    {
        return ContentStudioAuthoringDocuments::returnContext($this->host->resourceContextKey);
    }
}
