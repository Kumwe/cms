<?php

declare(strict_types=1);

namespace Kumwe\App\Workflow\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\Context\Value\SiteContext;

/**
 * One published version of a site's editorial workflow: its states, its edges, and what each edge costs.
 *
 * This is the persisted counterpart to the built-in lifecycle, and the thing that lets a site define
 * its own states without inventing an authorization model alongside them. The constructor is the only
 * validation point, so every later reader — `Workflow`, the content service, the administration
 * screens — can trust that there is exactly one non-public initial state, that state keys and edges
 * are unique, that every edge references declared states, and that crossing the public boundary costs
 * a publishing capability. Instances are immutable and versioned: changing a published workflow means
 * building and publishing a new version, never mutating this one.
 *
 * @since  2.0.0
 */
final readonly class WorkflowDefinition
{
    /**
     * States this version declares, in the order they were supplied.
     *
     * @var    list<WorkflowStateDefinition>
     * @since  2.0.0
     */
    private array $states;
    /**
     * Edges this version declares, in the order they were supplied.
     *
     * @var    list<WorkflowTransitionDefinition>
     * @since  2.0.0
     */
    private array $transitions;

    /**
     * Build a workflow version, enforcing every rule the rest of the system then takes for granted.
     *
     * Two of the checks are authorization rules rather than shape rules: an edge that enters a public
     * state from a non-public one must cost `content.publish`, and one that leaves a public state for
     * a non-public one must cost `content.unpublish` or `content.archive`. Without them a site could
     * define a workflow that published content behind a capability its editors already hold.
     *
     * @param   string                              $id           Canonical UUID identifying this workflow.
     * @param   SiteContext                         $site         Site whose content this workflow governs.
     * @param   string                              $handle       Lowercase identifier the workflow is addressed by.
     * @param   string                              $name         Human-readable label, 1 to 255 characters.
     * @param   list<WorkflowStateDefinition>       $states       Declared states; exactly one must be initial.
     * @param   list<WorkflowTransitionDefinition>  $transitions  Declared edges between those states.
     * @param   int                                 $version      Publication version, counting from one.
     * @param   DateTimeImmutable                   $createdAt    Instant this version was drafted.
     * @param   DateTimeImmutable                   $publishedAt  Instant this version became the one in force.
     *
     * @throws  InvalidArgumentException  When a field, the state set, or an edge breaks a workflow rule.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $id,
        public SiteContext $site,
        public string $handle,
        public string $name,
        array $states,
        array $transitions,
        public int $version,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $publishedAt,
    ) {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $id) !== 1) {
            throw new InvalidArgumentException('A workflow ID must be a canonical UUID.');
        }
        if (preg_match('/^[a-z][a-z0-9_-]{0,99}$/D', $handle) !== 1) {
            throw new InvalidArgumentException('A workflow handle must be a lowercase identifier.');
        }
        if (mb_strlen(trim($name)) < 1 || mb_strlen(trim($name)) > 255 || $version < 1) {
            throw new InvalidArgumentException('A workflow name and version must be valid.');
        }
        $keys = [];
        $publicStates = [];
        $initial = 0;
        foreach ($states as $state) {
            if (isset($keys[$state->key])) {
                throw new InvalidArgumentException('Workflow state keys must be unique.');
            }
            $keys[$state->key] = true;
            $publicStates[$state->key] = $state->public;
            $initial += $state->initial ? 1 : 0;
            if ($state->initial && $state->public) {
                throw new InvalidArgumentException('A workflow initial state cannot be public.');
            }
        }
        if ($states === [] || $initial !== 1) {
            throw new InvalidArgumentException('A workflow must contain exactly one initial state.');
        }
        $edges = [];
        foreach ($transitions as $transition) {
            if (!isset($keys[$transition->from], $keys[$transition->to])) {
                throw new InvalidArgumentException('A transition must reference states in the workflow.');
            }
            $edge = $transition->from . '>' . $transition->to;
            if (isset($edges[$edge])) {
                throw new InvalidArgumentException('Workflow transitions must be unique.');
            }
            if (
                !$publicStates[$transition->from]
                && $publicStates[$transition->to]
                && $transition->requiredCapability->value() !== 'content.publish'
            ) {
                throw new InvalidArgumentException('Entering a public workflow state requires content.publish.');
            }
            if (
                $publicStates[$transition->from]
                && !$publicStates[$transition->to]
                && !in_array(
                    $transition->requiredCapability->value(),
                    ['content.unpublish', 'content.archive'],
                    true,
                )
            ) {
                throw new InvalidArgumentException('Leaving a public workflow state requires content.unpublish.');
            }
            $edges[$edge] = true;
        }
        $this->states = $states;
        $this->transitions = $transitions;
    }

    /**
     * Returns the states this workflow version declares.
     *
     * @return  list<WorkflowStateDefinition>  Declaration order, with exactly one state flagged initial.
     *
     * @since   2.0.0
     */
    public function states(): array
    {
        return $this->states;
    }

    /**
     * Returns the edges this workflow version declares.
     *
     * @return  list<WorkflowTransitionDefinition>  Declaration order; each edge carries the capability it costs.
     *
     * @since   2.0.0
     */
    public function transitions(): array
    {
        return $this->transitions;
    }

    /**
     * Returns the state key newly created content starts on under this workflow.
     *
     * @return  string  Key of the single state flagged initial, which construction guarantees is not public.
     *
     * @throws  \LogicException  When no state is flagged initial, which the constructor rules out.
     *
     * @since   2.0.0
     */
    public function initialState(): string
    {
        foreach ($this->states as $state) {
            if ($state->initial) {
                return $state->key;
            }
        }
        throw new \LogicException('The validated workflow has no initial state.');
    }

    /**
     * Looks up the declared edge between two states, and with it the capability that edge costs.
     *
     * This is how the content service prices a status change on a custom workflow: it resolves the
     * edge here and authorizes the actor against `requiredCapability`, so an undeclared edge is
     * refused before any authorization decision is reached.
     *
     * @param   string  $from  Key of the state the content is leaving.
     * @param   string  $to    Key of the state the transition targets.
     *
     * @return  WorkflowTransitionDefinition  The matching edge, including the capability it requires.
     *
     * @throws  InvalidWorkflowTransition  When this version declares no edge between the two states.
     *
     * @since   2.0.0
     */
    public function transition(string $from, string $to): WorkflowTransitionDefinition
    {
        foreach ($this->transitions as $transition) {
            if ($transition->from === $from && $transition->to === $to) {
                return $transition;
            }
        }
        throw new InvalidWorkflowTransition($from, $to);
    }

    /**
     * Reports whether content resting on a state is visible to anonymous visitors.
     *
     * An unrecognised key answers false, so content on a state that this version no longer declares
     * fails closed and stays unpublished.
     *
     * @param   string  $stateKey  Key of the state to inspect.
     *
     * @return  bool  True only when the state is declared public by this version.
     *
     * @since   2.0.0
     */
    public function isPublic(string $stateKey): bool
    {
        foreach ($this->states as $state) {
            if ($state->key === $stateKey) {
                return $state->public;
            }
        }
        return false;
    }

    /**
     * Exports the version as the plain structure the API, console output and persistence layer read.
     *
     * @return  array<string, mixed>  States and transitions nested as arrays; timestamps in ATOM form.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'site' => $this->site->identifier(),
            'handle' => $this->handle,
            'name' => $this->name,
            'states' => array_map(
                static fn (WorkflowStateDefinition $state): array => $state->toArray(),
                $this->states,
            ),
            'transitions' => array_map(
                static fn (WorkflowTransitionDefinition $transition): array => $transition->toArray(),
                $this->transitions,
            ),
            'version' => $this->version,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'published_at' => $this->publishedAt->format(DATE_ATOM),
        ];
    }
}
