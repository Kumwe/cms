<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\Context\Value\ExecutionContext;
use Throwable;

/**
 * Console entry point exposing the editorial lifecycle of content entries as `kumwe content`.
 *
 * The command adds no policy of its own: the action word selects which capability the bearer token has
 * to carry, `CommandInput` turns `--name=value` arguments into typed values, and `ContentService`
 * decides everything else, so a deployment script sees exactly the rules the administrator screens and
 * the REST API see. Every result is printed as pretty JSON for piping into another tool, and every
 * action that changes an existing entry takes the `--version` the operator read, which is what turns a
 * concurrent edit into a refusal rather than a silent overwrite.
 *
 * @since  2.0.0
 */
final readonly class ManageContentCommand implements Command
{
    /**
     * Wire the command to the content use cases and to the console's token authorization route.
     *
     * @param  ContentService     $content        Service every action delegates its read or mutation to.
     * @param  ConsoleAuthorizer  $authorization  Resolves `--site` and `--token-file` into an authorized context.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ContentService $content,
        private ConsoleAuthorizer $authorization,
    ) {
    }

    /**
     * Name the operator types to reach the content actions.
     *
     * @return  string  Always `content`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'content';
    }

    /**
     * Describe the command for the console's command listing.
     *
     * @return  string  One-line summary naming the seven actions the command accepts.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.content.description';
    }

    /**
     * Run one content action and print the resulting record, or record list, as JSON.
     *
     * The first argument is the action and defaults to `list`; everything after it is a `--name=value`
     * option. The action is mapped to a capability before the token is verified, so an unrecognised
     * action is rejected without a credential ever being read. `list` hides trashed entries unless
     * `--deleted=1` is passed, while `get` always reads them, which is how a record is inspected before
     * `restore`. Nothing escapes: a bad option, a denial, a version conflict or a missing record all
     * become one operator-readable line and exit status 1 rather than a stack trace.
     *
     * @param   list<string>  $arguments  Action name first, then `--name=value` options: `--site` and
     *          `--token-file` always, plus whatever the chosen action requires.
     * @param   Output        $output     Sink for the JSON result, or for the failure message.
     *
     * @return  int  0 when the action completed, 1 when any step failed.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $action = array_shift($arguments) ?? 'list';
            $options = CommandInput::options($arguments);
            $context = $this->authorization->require($options, match ($action) {
                'list', 'get' => 'content.read',
                'create' => 'content.create',
                'update' => 'content.update',
                'trash' => 'content.delete',
                'restore' => 'content.restore',
                'transition' => 'content.read',
                default => throw new \InvalidArgumentException('Unsupported content action.'),
            });
            $result = match ($action) {
                'list' => ['items' => array_map(
                    static fn (ContentRecord $record): array => $record->toArray(),
                    $this->content->list($context, includeDeleted: ($options['deleted'] ?? '0') === '1'),
                )],
                'get' => $this->content->get(
                    $context,
                    CommandInput::required($options, 'id'),
                    true,
                )->toArray(),
                'create' => $this->content->create(
                    $context,
                    CommandInput::required($options, 'title'),
                    CommandInput::required($options, 'slug'),
                    CommandInput::jsonObject($options, 'data'),
                    contentTypeIdentifier: $options['content-type'] ?? ContentService::CORE_PAGE_TYPE_ID,
                )->toArray(),
                'update' => $this->content->update(
                    $context,
                    CommandInput::required($options, 'id'),
                    CommandInput::positiveInteger($options, 'version'),
                    CommandInput::required($options, 'title'),
                    CommandInput::required($options, 'slug'),
                    CommandInput::jsonObject($options, 'data'),
                )->toArray(),
                'transition' => $this->transition($options, $context),
                'trash' => $this->content->trash(
                    $context,
                    CommandInput::required($options, 'id'),
                    CommandInput::positiveInteger($options, 'version'),
                )->toArray(),
                'restore' => $this->content->restore(
                    $context,
                    CommandInput::required($options, 'id'),
                    CommandInput::positiveInteger($options, 'version'),
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
     * Move an entry to another workflow state on behalf of the `transition` action.
     *
     * Only `content.read` was proved before this runs, because the capability a particular edge demands
     * is declared by the entry's pinned workflow rather than by the console; `ContentService` resolves
     * and enforces it, which is why publishing can be refused to an actor who may submit for review.
     *
     * @param   array<string, string>  $options  Parsed options; `--id`, `--version` and `--status` are required.
     * @param   ExecutionContext       $context  Authorized actor and site the move is performed and audited for.
     *
     * @return  array<string, mixed>  The stored record after the move, in the JSON shape the command prints.
     *
     * @throws  \InvalidArgumentException  When `--id`, `--status` or a positive `--version` is missing.
     * @throws  \Kumwe\App\Workflow\Domain\InvalidWorkflowTransition  When the workflow declares no such edge.
     * @throws  \Kumwe\App\Content\Domain\VersionConflict  When another writer moved the entry on first.
     *
     * @since   2.0.0
     */
    private function transition(
        array $options,
        ExecutionContext $context,
    ): array {
        $id = CommandInput::required($options, 'id');
        $target = CommandInput::required($options, 'status');

        return $this->content->transition(
            $context,
            $id,
            CommandInput::positiveInteger($options, 'version'),
            $target,
        )->toArray();
    }
}
