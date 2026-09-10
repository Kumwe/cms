<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionService;
use Kumwe\App\BusinessDefinition\Application\DefinitionCatalogEntry;
use Kumwe\App\BusinessDefinition\Application\DefinitionDraft;
use Kumwe\App\BusinessDefinition\Application\DefinitionVersionRecord;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Throwable;

/**
 * Drives the versioned business-definition catalogue from a host-authorized shell.
 *
 * Reads take content.read and mutations take content.update, matching the REST routes and
 * the administrator screens. Output is machine-readable JSON on success and a single
 * message on stderr with a non-zero exit on failure, so scripts can branch on the code.
 *
 * @since  2.0.0
 */
final readonly class ManageBusinessDefinitionsCommand implements Command
{
    /**
     * Actions that only read the catalogue, and so take `content.read` instead of `content.update`.
     *
     * Membership of this list is the whole capability decision, which is what lets a modeller with a
     * read-only token inspect drafts, history and compatibility plans without being able to publish.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const READ_ACTIONS = ['list', 'get', 'draft', 'history', 'compatibility'];

    /**
     * Wire the catalogue service and the gate that turns console options into an authorized actor.
     *
     * @param  BusinessDefinitionService  $definitions    Owns drafts, published versions, validation
     *         and compatibility analysis.
     * @param  ConsoleAuthorizer          $authorization  Turns `--site` and `--token-file` into an
     *         execution context carrying the capability
     *         the requested action demands.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessDefinitionService $definitions,
        private ConsoleAuthorizer $authorization,
    ) {
    }

    /**
     * Name the console dispatcher registers this command under.
     *
     * @return  string  Always `business-definition`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'business-definition';
    }

    /**
     * Summary line `bin/kumwe list` prints beside the command name.
     *
     * @return  string  One-sentence statement of the lifecycle this command covers.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.business_definition.description';
    }

    /**
     * Dispatch one catalogue action and print its result as JSON.
     *
     * The first argument names the action and defaults to `list`; the rest are `--name=value`
     * options. Two steps are worth understanding before scripting this. `import` reads the definition
     * document from a protected file rather than the command line, so a model never passes through
     * the process table. `publish` refuses a plan that changes behaviour or data unless `--confirmed=1`
     * asserts the operator read the `compatibility` report first — the report is the thing worth
     * looking at, and the flag is only the acknowledgement that they did.
     *
     * @param   list<string>  $arguments  Action name first, then `--name=value` options.
     * @param   Output        $output     Sink for the JSON result, or for the failure message.
     *
     * @return  int  `0` when the action completed, `1` with its message on stderr when it did not.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $action = array_shift($arguments) ?? 'list';
            $options = CommandInput::options($arguments);
            $capability = in_array($action, self::READ_ACTIONS, true) ? 'content.read' : 'content.update';
            $context = $this->authorization->require($options, $capability);

            $result = match ($action) {
                'list' => ['items' => array_map(
                    $this->catalogEntry(...),
                    $this->definitions->catalog($context),
                )],
                'get' => $this->version($this->definitions->published(
                    $context,
                    CommandInput::required($options, 'handle'),
                    isset($options['version']) ? CommandInput::positiveInteger($options, 'version') : null,
                )),
                'draft' => $this->draft(
                    $this->definitions->draft($context, CommandInput::required($options, 'handle')),
                ),
                'history' => ['items' => array_map(
                    $this->version(...),
                    $this->definitions->history($context, CommandInput::required($options, 'handle')),
                )],
                'compatibility' => $this->definitions->previewDraft(
                    $context,
                    CommandInput::required($options, 'handle'),
                )->toArray(),
                'import' => $this->draft($this->definitions->importJson(
                    $context,
                    CommandInput::secretFile(CommandInput::required($options, 'definition-file')),
                    isset($options['expected-revision'])
                        ? CommandInput::positiveInteger($options, 'expected-revision')
                        : null,
                )),
                'validate' => $this->draft(
                    $this->definitions->validateDraft($context, CommandInput::required($options, 'handle')),
                ),
                'publish' => $this->version($this->definitions->publish(
                    $context,
                    CommandInput::required($options, 'handle'),
                    CommandInput::positiveInteger($options, 'expected-revision'),
                    ($options['confirmed'] ?? '0') === '1',
                )),
                'supersede', 'deprecate', 'reject' => $this->version($this->retire(
                    $context,
                    $action,
                    CommandInput::required($options, 'handle'),
                    CommandInput::positiveInteger($options, 'version'),
                )),
                default => throw new InvalidArgumentException('Unsupported business-definition action.'),
            };
            $output->line(CommandInput::render($result));

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }

    /**
     * Move one published version into a terminal status.
     *
     * `supersede`, `deprecate` and `reject` differ only in the status they record, so the three
     * console actions share one call site; the match falls through to `reject`, which is safe because
     * `execute()` routes no other action here. Only a site-owned definition can be retired this way —
     * a definition a package owns follows its extension's lifecycle instead.
     *
     * @param   \Kumwe\Context\Value\ExecutionContext  $context  Authorized actor and site.
     * @param   string                                 $action   `supersede`, `deprecate` or `reject`.
     * @param   string                                 $handle   Definition handle being retired.
     * @param   int                                    $version  Published version to retire.
     *
     * @return  DefinitionVersionRecord  The version as stored after the status change.
     *
     * @throws  \Kumwe\App\BusinessDefinition\Application\BusinessDefinitionNotFound  When the actor's
     *          site holds no definition under that handle.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When the definition is
     *          package-owned, so its status is not the operator's to set.
     *
     * @since   2.0.0
     */
    private function retire(
        \Kumwe\Context\Value\ExecutionContext $context,
        string $action,
        string $handle,
        int $version,
    ): DefinitionVersionRecord {
        return match ($action) {
            'supersede' => $this->definitions->supersede($context, $handle, $version),
            'deprecate' => $this->definitions->deprecate($context, $handle, $version),
            default => $this->definitions->reject($context, $handle, $version),
        };
    }

    /**
     * Flatten one catalogue entry into the row the `list` action prints.
     *
     * Draft revision and published version are both carried because they answer different questions:
     * the revision is what `publish` must be handed as `--expected-revision`, while the published
     * version is what consumers are currently bound to.
     *
     * @param   DefinitionCatalogEntry  $entry  Catalogue row as the service returns it.
     *
     * @return  array<string, mixed>  Identity, site, owner, draft revision, published version and status.
     *
     * @since   2.0.0
     */
    private function catalogEntry(DefinitionCatalogEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'handle' => $entry->handle,
            'site' => $entry->siteIdentifier,
            'owner' => $entry->owner->toArray(),
            'owner_active' => $entry->ownerActive,
            'draft_revision' => $entry->draftRevision,
            'published_version' => $entry->publishedVersion,
            'status' => $entry->status->value,
        ];
    }

    /**
     * Flatten a working draft into the row the `draft`, `import` and `validate` actions print.
     *
     * The revision is what the operator hands back as `--expected-revision` when publishing, and the
     * checksum identifies exactly which draft body that revision was, so a script can prove it is
     * publishing the document it validated rather than one edited in the meantime.
     *
     * @param   DefinitionDraft  $draft  Current working draft of one definition.
     *
     * @return  array<string, mixed>  Revision, checksum, last editor, edit time, and the definition body.
     *
     * @since   2.0.0
     */
    private function draft(DefinitionDraft $draft): array
    {
        return [
            'revision' => $draft->revision,
            'checksum' => $draft->checksum,
            'updated_by' => $draft->updatedBy,
            'updated_at' => $draft->updatedAt->format(DATE_ATOM),
            'definition' => $draft->definition->toArray(),
        ];
    }

    /**
     * Flatten one published version into the row the `get`, `history` and retire actions print.
     *
     * The compatibility plan travels with the version because it records what publishing that version
     * did to the versions before it, which is the thing an operator needs when deciding whether a
     * consumer still bound to an older version is safe.
     *
     * @param   DefinitionVersionRecord  $record  Published version together with its compatibility plan.
     *
     * @return  array<string, mixed>  Version, status, checksum, publisher, publication time, the
     *          compatibility plan, and the definition body.
     *
     * @since   2.0.0
     */
    private function version(DefinitionVersionRecord $record): array
    {
        return [
            'version' => $record->definition->definitionVersion,
            'status' => $record->status->value,
            'checksum' => $record->definition->checksum(),
            'published_by' => $record->publishedBy,
            'published_at' => $record->publishedAt->format(DATE_ATOM),
            'compatibility' => $record->compatibility->toArray(),
            'definition' => $record->definition->toArray(),
        ];
    }
}
