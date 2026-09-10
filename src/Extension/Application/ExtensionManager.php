<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Application;

use Kumwe\App\Extension\Domain\ThemeSurface;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Application-facing contract for the extension lifecycle: what is installed, and what may change it.
 *
 * Every delivery surface — the administrator screens, the REST API, the console commands, the MCP
 * handlers — depends on this interface rather than on a registry adapter, so the question of who may
 * install or activate an extension is answered in one place. An implementation owns the two concerns
 * callers must not have to think about: authorizing the actor carried by `$context`, and serializing
 * lifecycle changes against concurrent ones. That is why no method here takes a registry lease — the
 * shipped `RedisLockedExtensionManager` acquires and fences one around each call.
 *
 * @since  2.0.0
 */
interface ExtensionManager
{
    /**
     * List the installed extensions the actor is allowed to manage.
     *
     * @param   ExecutionContext  $context  Authenticated actor and site the listing is authorized against.
     *
     * @return  list<array<string, mixed>>  One row per installed extension, carrying its identifier, type,
     *          installed version and status; extensions the actor may not manage are omitted rather than
     *          redacted, so an empty list can mean either "none installed" or "none visible to this actor".
     *
     * @since   2.0.0
     */
    public function installed(ExecutionContext $context): array;

    /**
     * Install an extension package from an archive on disk.
     *
     * Placing and cleaning up the archive stays with the caller; an implementation reads the file and
     * never takes ownership of it. A signature is how a package clears the trust store, and the key
     * identifier and the signature travel together — neither is accepted on its own.
     *
     * @param   string            $archiveFile      Path to the extension ZIP to install; must name a regular file.
     * @param   ExecutionContext  $context          Actor and site the install is authorized against and audited to.
     * @param   ?string           $signingKeyId     Trust-store key that vouches for the package, or null when the
     *          package is offered unsigned.
     * @param   ?string           $base64Signature  Base64 detached signature over the SDK's domain-separated
     *          message for this package checksum, supplied together with `$signingKeyId`.
     *
     * @return  array<string, mixed>  The registry row for the extension as it now stands, including the version
     *          just installed and the runtime path its files were published to.
     *
     * @since   2.0.0
     */
    public function install(
        string $archiveFile,
        ExecutionContext $context,
        ?string $signingKeyId = null,
        ?string $base64Signature = null,
    ): array;

    /**
     * Activate an installed extension so the next compiled runtime map carries it.
     *
     * A template is activated onto one presentation surface at a time and therefore needs `$surface`;
     * every other extension type must leave it null. Taking over the administrator surface is the case
     * that asks for `$stepUpCredential`, because a broken administrator theme locks operators out.
     *
     * @param   string            $identifier        `vendor/name` identifier of the installed extension.
     * @param   ExecutionContext  $context           Actor and site the activation is authorized against.
     * @param   ?ThemeSurface     $surface           Presentation surface a template is being activated on; null
     *          for every non-template extension.
     * @param   ?string           $stepUpCredential  The actor's current password, re-supplied when the change
     *          demands step-up authentication; null when none is being offered.
     *
     * @return  array<string, mixed>  The registry row for the extension after the status change.
     *
     * @since   2.0.0
     */
    public function activate(
        string $identifier,
        ExecutionContext $context,
        ?ThemeSurface $surface = null,
        #[\SensitiveParameter] ?string $stepUpCredential = null,
    ): array;

    /**
     * Disable an installed extension so it stops contributing to the compiled runtime map.
     *
     * The files stay on disk and the registry keeps the release, so disabling is the reversible half of
     * removal. An extension currently serving the administrator theme still demands step-up
     * authentication, since disabling it changes what the administration UI renders with.
     *
     * @param   string            $identifier        `vendor/name` identifier of the installed extension.
     * @param   ExecutionContext  $context           Actor and site the change is authorized against.
     * @param   ?string           $stepUpCredential  The actor's current password, re-supplied when the change
     *          demands step-up authentication; null when none is being offered.
     *
     * @return  array<string, mixed>  The registry row for the extension after the status change.
     *
     * @since   2.0.0
     */
    public function disable(
        string $identifier,
        ExecutionContext $context,
        #[\SensitiveParameter] ?string $stepUpCredential = null,
    ): array;

    /**
     * Remove an extension from the registry and retire the files it was serving from.
     *
     * This is the one lifecycle change the registry cannot undo: the extension row and the capabilities
     * the package contributed go with it. The runtime directory is scheduled for retirement rather than
     * deleted outright, so processes still running an older compiled generation keep the files they are
     * reading until they drain.
     *
     * @param   string            $identifier        `vendor/name` identifier of the installed extension.
     * @param   ExecutionContext  $context           Actor and site the removal is authorized against.
     * @param   ?string           $stepUpCredential  The actor's current password, re-supplied when the removal
     *          demands step-up authentication; null when none is being offered.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function uninstall(
        string $identifier,
        ExecutionContext $context,
        #[\SensitiveParameter] ?string $stepUpCredential = null,
    ): void;
}
