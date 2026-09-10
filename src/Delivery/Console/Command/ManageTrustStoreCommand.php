<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\Context\Value\ExecutionContext;
use Throwable;

/**
 * Console entry point for curating the extension signing keys as `kumwe extension:trust`.
 *
 * The trust store decides which package signatures the platform will accept, so this is the command an
 * operator reaches for when a vendor key is introduced, replaced on schedule, or believed compromised.
 * It is listed among the recovery commands in `bootstrap/console.php`, so it runs on the reduced
 * container and stays reachable when the ordinary one cannot be built. `rotate` and
 * `finalize-rotation` are the two halves of a planned replacement, keeping the old key trusted through
 * the overlap; `emergency-revoke` skips the overlap and quarantines every release the key signed,
 * answering with those identifiers so the operator knows what just went offline.
 *
 * @since  2.0.0
 */
final readonly class ManageTrustStoreCommand implements Command
{
    /**
     * Wire the command to the trust store and to the console's token authorization route.
     *
     * @param  TrustStore         $trust          Audited store every action reads or mutates.
     * @param  ConsoleAuthorizer  $authorization  Resolves `--site` and `--token-file` into an authorized context.
     *
     * @since  2.0.0
     */
    public function __construct(private TrustStore $trust, private ConsoleAuthorizer $authorization)
    {
    }

    /**
     * Name the operator types to reach the trust-store actions.
     *
     * @return  string  Always `extension:trust`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'extension:trust';
    }

    /**
     * Describe the command for the console's command listing.
     *
     * @return  string  One-line summary naming the routine and the emergency key operations.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.extension_trust.description';
    }

    /**
     * Run one trust-store action and print its result as JSON.
     *
     * The first argument is the action and defaults to `list`; everything after it is a `--name=value`
     * option. Every action requires `extensions.manage`, so there is no read-only route into the key
     * list. `list` reports each key together with the extensions still depending on it, which is what
     * an operator checks before `revoke` — an ordinary revoke refuses while any release still needs the
     * key. Failures are reduced to a message and exit status 1 rather than a stack trace.
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
            $actor = $this->authorization->require($options, 'extensions.manage');
            $result = match ($action) {
                'list' => ['items' => $this->trust->keys($actor)],
                'add' => $this->add($actor, $options),
                'rotate' => $this->rotate($actor, $options),
                'revoke' => $this->revoke($actor, $options),
                'finalize-rotation' => $this->finalizeRotation($actor, $options),
                'emergency-revoke' => $this->emergencyRevoke($actor, $options),
                default => throw new InvalidArgumentException('Unsupported extension trust action.'),
            };
            $output->line(CommandInput::render($result));
            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());
            return 1;
        }
    }

    /**
     * Register a new signing key on behalf of the `add` action.
     *
     * The key material is read from a protected file rather than an argument, so it never reaches the
     * process table. `--vendor` and `--extension` narrow what the key may sign and both default to `*`,
     * which trusts it for every package; supply them whenever the key belongs to a single vendor.
     *
     * @param   ExecutionContext       $actor    Authorized actor and site the addition is audited against.
     * @param   array<string, string>  $options  Parsed options; `--key`, `--public-key-file` and
     *          `--expires-at` are required, `--vendor` and `--extension` optional.
     *
     * @return  array{updated: bool}  Always `['updated' => true]`; failure arrives as an exception instead.
     *
     * @throws  InvalidArgumentException  When a required option is missing, the key file fails its location
     *          and permission checks, or the expiry is in the past or more than three years out.
     * @throws  \DateMalformedStringException  When `--expires-at` is not a readable date and time.
     *
     * @since   2.0.0
     */
    private function add(ExecutionContext $actor, array $options): array
    {
        $this->trust->add(
            $actor,
            CommandInput::required($options, 'key'),
            CommandInput::secretFile(CommandInput::required($options, 'public-key-file')),
            $options['vendor'] ?? '*',
            $options['extension'] ?? '*',
            new DateTimeImmutable(CommandInput::required($options, 'expires-at')),
        );
        return ['updated' => true];
    }

    /**
     * Begin a planned key replacement on behalf of the `rotate` action.
     *
     * This is only the first half: the replacement is registered and the old key stays trusted so that
     * releases signed with it keep verifying while vendors re-sign. `finalize-rotation` closes the
     * overlap. The replacement has to carry the same `--vendor` and `--extension` constraints as the
     * key it succeeds, so a rotation cannot quietly widen what a key is trusted for.
     *
     * @param   ExecutionContext       $actor    Authorized actor and site the rotation is audited against.
     * @param   array<string, string>  $options  Parsed options; `--old-key`, `--new-key`,
     *          `--public-key-file` and `--expires-at` are required, `--vendor` and `--extension` optional.
     *
     * @return  array{updated: bool}  Always `['updated' => true]`; failure arrives as an exception instead.
     *
     * @throws  InvalidArgumentException  When a required option is missing, the key file fails its location
     *          and permission checks, the expiry is out of range, the old key is not active, or the
     *          replacement changes its namespace constraints.
     * @throws  \DateMalformedStringException  When `--expires-at` is not a readable date and time.
     *
     * @since   2.0.0
     */
    private function rotate(ExecutionContext $actor, array $options): array
    {
        $this->trust->rotate(
            $actor,
            CommandInput::required($options, 'old-key'),
            CommandInput::required($options, 'new-key'),
            CommandInput::secretFile(CommandInput::required($options, 'public-key-file')),
            $options['vendor'] ?? '*',
            $options['extension'] ?? '*',
            new DateTimeImmutable(CommandInput::required($options, 'expires-at')),
        );
        return ['updated' => true];
    }

    /**
     * Retire a signing key on behalf of the `revoke` action.
     *
     * The safe revocation: it refuses while any active release still depends on the key, naming those
     * extensions in the failure so the operator can upgrade or quarantine them first. Use
     * `emergency-revoke` when the key is compromised and that wait is unacceptable.
     *
     * @param   ExecutionContext       $actor    Authorized actor and site the revocation is audited against.
     * @param   array<string, string>  $options  Parsed options; `--key` and `--reason` are both required.
     *
     * @return  array{updated: bool}  Always `['updated' => true]`; failure arrives as an exception instead.
     *
     * @throws  InvalidArgumentException  When `--key` or `--reason` is missing or malformed, or a release
     *          signed by the key is still active.
     *
     * @since   2.0.0
     */
    private function revoke(ExecutionContext $actor, array $options): array
    {
        $this->trust->revoke(
            $actor,
            CommandInput::required($options, 'key'),
            CommandInput::required($options, 'reason'),
        );
        return ['updated' => true];
    }

    /**
     * Close a planned rotation on behalf of the `finalize-rotation` action.
     *
     * The second half of `rotate`: it revokes the superseded key under its own audit action once every
     * release has moved to the replacement, and refuses on the same terms as `revoke` while any has
     * not. `--key` names the old key being retired, not the replacement.
     *
     * @param   ExecutionContext       $actor    Authorized actor and site the finalization is audited against.
     * @param   array<string, string>  $options  Parsed options; `--key` and `--reason` are both required.
     *
     * @return  array{updated: bool}  Always `['updated' => true]`; failure arrives as an exception instead.
     *
     * @throws  InvalidArgumentException  When `--key` or `--reason` is missing or malformed, or a release
     *          signed by the superseded key is still active.
     *
     * @since   2.0.0
     */
    private function finalizeRotation(ExecutionContext $actor, array $options): array
    {
        $this->trust->finalizeRotation(
            $actor,
            CommandInput::required($options, 'key'),
            CommandInput::required($options, 'reason'),
        );
        return ['updated' => true];
    }

    /**
     * Revoke a compromised key immediately on behalf of the `emergency-revoke` action.
     *
     * Unlike `revoke` this does not wait for dependent releases: it revokes the key and quarantines
     * every active extension signed by it in the same transaction, deactivating the business
     * definitions those extensions published. The identifiers come back so the operator can see what
     * went offline; an empty list means the key signed nothing that was still active.
     *
     * @param   ExecutionContext       $actor    Authorized actor and site the revocation is audited against.
     * @param   array<string, string>  $options  Parsed options; `--key` and `--reason` are both required.
     *
     * @return  array{quarantined: list<string>}  Identifiers of the extensions quarantined by the revocation.
     *
     * @throws  InvalidArgumentException  When `--key` or `--reason` is missing or malformed.
     *
     * @since   2.0.0
     */
    private function emergencyRevoke(ExecutionContext $actor, array $options): array
    {
        return ['quarantined' => $this->trust->emergencyRevoke(
            $actor,
            CommandInput::required($options, 'key'),
            CommandInput::required($options, 'reason'),
        )];
    }
}
