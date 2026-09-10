<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\App\BusinessSchema\Domain\SchemaPlan;
use Kumwe\App\BusinessSchema\Domain\SchemaPlanStep;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Throwable;

/**
 * Drives schema-plan inspection, approval and execution from a host-authorized shell.
 *
 * Every stage names the capability it needs rather than sharing one, because plan,
 * approve, execute, recover and destructive are independently grantable. Approval binds
 * to the plan checksum the operator inspected, so a plan that changed underneath fails
 * instead of being applied.
 *
 * @since  2.0.0
 */
final readonly class ManageBusinessSchemaCommand implements Command
{
    /**
     * Capability each action demands, keyed by the action name an operator types.
     *
     * Keeping them apart is the point: a token can be given inspection without approval, or approval
     * without execution, so no one credential carries the whole lifecycle from plan to destructive
     * purge. The map doubles as the list of supported actions — an action missing from it is refused
     * before any capability is checked and before any plan is read.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const CAPABILITIES = [
        'definitions' => 'business.schema.read',
        'plans' => 'business.schema.read',
        'get' => 'business.schema.read',
        'plan' => 'business.schema.plan',
        'purge-plan' => 'business.schema.destructive',
        'approve' => 'business.schema.approve',
        'execute' => 'business.schema.execute',
        'recover' => 'business.schema.recover',
    ];

    /**
     * Wire the schema service and the gate that turns console options into an authorized actor.
     *
     * @param  BusinessSchemaService  $schema         Owns plan creation, approval, execution and the
     *         step journal recovery reads.
     * @param  ConsoleAuthorizer      $authorization  Turns `--site` and `--token-file` into an
     *         execution context carrying the capability the
     *         requested stage demands.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessSchemaService $schema,
        private ConsoleAuthorizer $authorization,
    ) {
    }

    /**
     * Name the console dispatcher registers this command under.
     *
     * @return  string  Always `business-schema`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'business-schema';
    }

    /**
     * Summary line `bin/kumwe list` prints beside the command name.
     *
     * @return  string  One-sentence statement of the plan lifecycle this command covers.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.business_schema.description';
    }

    /**
     * Dispatch one schema-plan stage and print its result as JSON.
     *
     * The first argument names the action and defaults to `plans`; the rest are `--name=value`
     * options. The action is resolved against `CAPABILITIES` before anything else happens, so an
     * unrecognised action fails without a token being verified or a plan being touched, and `recover`
     * is the one mapped action reached through the match's default arm. Approval is the stage worth
     * care: it binds to the `--expected-checksum` the operator read out of `get`, so a plan that
     * changed in the meantime is refused rather than applied. Anything riskier than an online-safe
     * addition must repeat that checksum as `--confirmation`, and a rebuilding or destructive plan
     * must additionally name the recovery drill it is approved against as `--evidence`.
     *
     * @param   list<string>  $arguments  Action name first, then `--name=value` options.
     * @param   Output        $output     Sink for the JSON result, or for the failure message.
     *
     * @return  int  `0` when the stage completed, `1` with its message on stderr when it did not.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $action = array_shift($arguments) ?? 'plans';
            $options = CommandInput::options($arguments);
            $capability = self::CAPABILITIES[$action]
                ?? throw new InvalidArgumentException('Unsupported business-schema action.');
            $context = $this->authorization->require($options, $capability);

            $result = match ($action) {
                'definitions' => ['items' => $this->schema->definitions($context)],
                'plans' => ['items' => array_map($this->plan(...), $this->schema->plans($context))],
                'get' => $this->planWithJournal($context, CommandInput::required($options, 'plan')),
                'plan' => $this->plan($this->schema->createPlan(
                    $context,
                    CommandInput::required($options, 'definition'),
                )),
                'purge-plan' => $this->plan($this->schema->createPurgePlan(
                    $context,
                    CommandInput::required($options, 'definition'),
                )),
                'approve' => $this->plan($this->schema->approve(
                    $context,
                    CommandInput::required($options, 'plan'),
                    CommandInput::required($options, 'expected-checksum'),
                    isset($options['confirmation']) ? CommandInput::required($options, 'confirmation') : null,
                    isset($options['evidence']) ? CommandInput::required($options, 'evidence') : null,
                )),
                'execute' => $this->schema->execute(
                    $context,
                    CommandInput::required($options, 'plan'),
                )->toArray(),
                default => $this->schema->recover(
                    $context,
                    CommandInput::required($options, 'plan'),
                )->toArray(),
            };
            $output->line(CommandInput::render($result));

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }

    /**
     * Combine a plan with its durable step journal into the single row `get` prints.
     *
     * This is the view an operator reads twice: before approving, to see exactly which operations are
     * proposed and under which checksum, and after an interrupted execution, to see how far the
     * journal got before deciding between `execute` and `recover`.
     *
     * @param   \Kumwe\Context\Value\ExecutionContext  $context  Authorized actor and site.
     * @param   string                                 $planId   UUID of the plan to read.
     *
     * @return  array<string, mixed>  The plan row with a `steps` list, in ordinal order, appended.
     *
     * @throws  \Kumwe\App\BusinessSchema\Application\BusinessSchemaNotFound  When the actor's site
     *          holds no plan under that identifier.
     *
     * @since   2.0.0
     */
    private function planWithJournal(
        \Kumwe\Context\Value\ExecutionContext $context,
        string $planId,
    ): array {
        return [
            ...$this->plan($this->schema->plan($context, $planId)),
            'steps' => array_map(
                static fn (SchemaPlanStep $step): array => $step->toArray(),
                $this->schema->steps($context, $planId),
            ),
        ];
    }

    /**
     * Render a plan together with the checksum an approval has to quote back.
     *
     * The checksum is derived from the plan's canonical form each time rather than read from storage,
     * so it always describes the plan as it stands now. That is what gives `--expected-checksum` its
     * force: an operator can only approve the plan they actually read.
     *
     * @param   SchemaPlan  $plan  Plan to render, in whatever state it currently holds.
     *
     * @return  array<string, mixed>  The plan's own array form with a `checksum` entry added.
     *
     * @since   2.0.0
     */
    private function plan(SchemaPlan $plan): array
    {
        return [...$plan->toArray(), 'checksum' => $plan->checksum()];
    }
}
