<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Infrastructure;

use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Extension\Application\ExtensionManager;
use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Extension\Application\ExtensionRuntimeWithdrawal;
use Kumwe\App\Extension\Application\Install\ExtensionInstallReconciler;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Infrastructure\Redis\RedisRuntime;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Extension\Domain\ThemeSurface;
use RuntimeException;
use Throwable;

/**
 * Extension lifecycle manager that authorizes the actor and serializes the change before delegating.
 *
 * `DoctrineExtensionManager` does the registry work but assumes it is already alone and already
 * authorized; this decorator supplies both guarantees the `ExtensionManager` contract makes on its
 * behalf, which is why the container binds this class and not the inner one. Every mutating call takes
 * the installation-wide extension lifecycle lock through `TrustStore`, then the cross-process
 * `extension-registry` Redis lease, then a database fence that makes a lost lease detectable, and hands
 * the inner manager a `DatabaseFencedExtensionRegistryLease` carrying both. It is the bound
 * `ExtensionInstallReconciler` as well, so a startup pass settles interrupted installs behind exactly
 * the locks an operator-driven install would hold.
 *
 * @since  2.0.0
 */
final readonly class RedisLockedExtensionManager implements ExtensionManager, ExtensionInstallReconciler
{
    /**
     * Bind the decorator to the registry it guards and to the locks it guards it with.
     *
     * @param  DoctrineExtensionManager         $extensions     Registry implementation every call is
     *         delegated to once the locks are held.
     * @param  RedisRuntime                     $redis          Runtime the cross-process
     *         `extension-registry` lease is taken on.
     * @param  AuthorizationGateway             $authorization  Gateway asked for `extensions.manage`
     *         before any lock is taken.
     * @param  ExtensionRegistryFenceAllocator  $fences         Allocator of the monotonic database fence
     *         paired with each lease.
     * @param  TrustStore                       $trust          Owner of the installation-wide extension
     *         lifecycle lock every mutation runs inside.
     * @param  ExtensionRuntimeWithdrawal       $withdrawal     Resident graph withdrawal boundary.
     * @param  ExtensionExecutionGate           $execution      Live authority deciding whether that graph
     *         is still current.
     *
     * @since  2.0.0
     */
    public function __construct(
        private DoctrineExtensionManager $extensions,
        private RedisRuntime $redis,
        private AuthorizationGateway $authorization,
        private ExtensionRegistryFenceAllocator $fences,
        private TrustStore $trust,
        private ExtensionRuntimeWithdrawal $withdrawal,
        private ExtensionExecutionGate $execution,
    ) {
    }

    /**
     * List the installed extensions the actor is allowed to manage.
     *
     * A read takes neither the lifecycle lock nor a registry lease, and no capability is asserted here:
     * the inner manager filters the rows against the actor's grants, so an unauthorized caller sees an
     * empty list rather than a refusal.
     *
     * @param   ExecutionContext  $context  Authenticated actor and site the listing is filtered against.
     *
     * @return  list<array<string, mixed>>  One row per installed extension the actor may manage.
     *
     * @since   2.0.0
     */
    public function installed(ExecutionContext $context): array
    {
        return $this->extensions->installed($context);
    }

    /**
     * Settle interrupted install operations under the lifecycle lock and a freshly fenced lease.
     *
     * Nothing is locked when nothing is pending, which keeps `MaterializeExtensionRuntimeCommand` and
     * the runtime watcher from contending for the registry on every pass. No actor is authorized here:
     * reconciliation is driven by the process rather than by a request, which is what lets those two
     * call it with no operator in the loop.
     *
     * @return  int  How many operations this pass moved to a settled outcome; 0 when none were pending.
     *
     * @throws  RuntimeException  When another lifecycle operation already holds the extension lock or
     *          the registry lease.
     *
     * @since   2.0.0
     */
    public function reconcile(): int
    {
        if (!$this->extensions->hasPendingInstallOperations()) {
            return 0;
        }

        try {
            return $this->trust->synchronizedLifecycle(fn (): int =>
                $this->locked(fn (DatabaseFencedExtensionRegistryLease $lease): int =>
                    $this->extensions->reconcileInstallOperations($lease)));
        } finally {
            $this->withdrawStaleRuntime();
        }
    }

    /**
     * Report whether any install operation is still waiting to be settled.
     *
     * Answered straight from the registry without locking, so callers may poll it cheaply to decide
     * whether compiling a runtime map would be safe.
     *
     * @return  bool  True while at least one operation's outcome is unresolved.
     *
     * @since   2.0.0
     */
    public function hasPending(): bool
    {
        return $this->extensions->hasPendingInstallOperations();
    }

    /**
     * Authorize the actor, then install an extension package under the lifecycle lock and a fenced lease.
     *
     * The capability check runs against the extension collection before any lock is taken, so a refused
     * request never contends for the registry with a legitimate one.
     *
     * @param   string            $archiveFile      Path to the extension ZIP to install; must name a
     *          regular file, and the caller keeps ownership of it.
     * @param   ExecutionContext  $context          Actor and site the install is authorized against and
     *          audited to.
     * @param   ?string           $signingKeyId     Trust-store key vouching for the package, or null when
     *          the package is offered unsigned.
     * @param   ?string           $base64Signature  Base64 detached signature over the SDK's domain-separated
     *          message for this package checksum, supplied together with `$signingKeyId`.
     *
     * @return  array<string, mixed>  Registry row for the extension as it now stands, carrying the
     *          version just installed and the runtime path its files were published to.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage
     *          extensions.
     * @throws  RuntimeException  When another lifecycle operation already holds the extension lock or
     *          the registry lease.
     *
     * @since   2.0.0
     */
    public function install(
        string $archiveFile,
        ExecutionContext $context,
        ?string $signingKeyId = null,
        ?string $base64Signature = null,
    ): array {
        $this->authorize($context, AuthorizationResource::collection('extension'));
        try {
            return $this->trust->synchronizedLifecycle(fn (): array =>
                $this->locked(fn (DatabaseFencedExtensionRegistryLease $lease): array => $this->extensions->install(
                    $archiveFile,
                    $context,
                    $lease,
                    $signingKeyId,
                    $base64Signature,
                )));
        } finally {
            $this->withdrawStaleRuntime();
        }
    }

    /**
     * Authorize the actor, then activate an installed extension under the lifecycle lock and a fenced lease.
     *
     * Authorization here is checked against the individual extension rather than the collection, so a
     * grant may be narrowed to the extensions one operator is responsible for. Any further demand the
     * change makes — a template surface, or step-up on the administrator theme — is the inner manager's
     * to enforce once the locks are held.
     *
     * @param   string            $identifier        `vendor/name` identifier of the installed extension.
     * @param   ExecutionContext  $context           Actor and site the activation is authorized against.
     * @param   ?ThemeSurface     $surface           Presentation surface a template is being activated on;
     *          null for every non-template extension.
     * @param   ?string           $stepUpCredential  The actor's current password, re-supplied when the
     *          change demands step-up authentication; null when none is being offered.
     *
     * @return  array<string, mixed>  Registry row for the extension after the status change.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage
     *          this extension.
     * @throws  RuntimeException  When another lifecycle operation already holds the extension lock or
     *          the registry lease.
     *
     * @since   2.0.0
     */
    public function activate(
        string $identifier,
        ExecutionContext $context,
        ?ThemeSurface $surface = null,
        #[\SensitiveParameter] ?string $stepUpCredential = null,
    ): array {
        $this->authorize($context, AuthorizationResource::item('extension', $identifier));
        try {
            return $this->trust->synchronizedLifecycle(fn (): array =>
                $this->locked(fn (DatabaseFencedExtensionRegistryLease $lease): array => $this->extensions->activate(
                    $identifier,
                    $context,
                    $lease,
                    $surface,
                    $stepUpCredential,
                )));
        } finally {
            $this->withdrawStaleRuntime();
        }
    }

    /**
     * Authorize the actor, then disable an installed extension under the lifecycle lock and a fenced lease.
     *
     * The files and the release record stay put, so this is the reversible half of removal; only the
     * status the runtime map is compiled from changes.
     *
     * @param   string            $identifier        `vendor/name` identifier of the installed extension.
     * @param   ExecutionContext  $context           Actor and site the change is authorized against.
     * @param   ?string           $stepUpCredential  The actor's current password, re-supplied when the
     *          change demands step-up authentication; null when none is being offered.
     *
     * @return  array<string, mixed>  Registry row for the extension after the status change.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage
     *          this extension.
     * @throws  RuntimeException  When another lifecycle operation already holds the extension lock or
     *          the registry lease.
     *
     * @since   2.0.0
     */
    public function disable(
        string $identifier,
        ExecutionContext $context,
        #[\SensitiveParameter] ?string $stepUpCredential = null,
    ): array {
        $this->authorize($context, AuthorizationResource::item('extension', $identifier));
        try {
            return $this->trust->synchronizedLifecycle(fn (): array =>
                $this->locked(fn (DatabaseFencedExtensionRegistryLease $lease): array => $this->extensions->disable(
                    $identifier,
                    $context,
                    $lease,
                    $stepUpCredential,
                )));
        } finally {
            $this->withdrawStaleRuntime();
        }
    }

    /**
     * Authorize the actor, then remove an extension under the lifecycle lock and a fenced lease.
     *
     * The locked operation returns an empty array only because `locked()` is generic over what it runs;
     * removal itself yields nothing, and it is the one lifecycle change the registry cannot undo.
     *
     * @param   string            $identifier        `vendor/name` identifier of the installed extension.
     * @param   ExecutionContext  $context           Actor and site the removal is authorized against.
     * @param   ?string           $stepUpCredential  The actor's current password, re-supplied when the
     *          removal demands step-up authentication; null when none is being offered.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage
     *          this extension.
     * @throws  RuntimeException  When another lifecycle operation already holds the extension lock or
     *          the registry lease.
     *
     * @since   2.0.0
     */
    public function uninstall(
        string $identifier,
        ExecutionContext $context,
        #[\SensitiveParameter] ?string $stepUpCredential = null,
    ): void {
        $this->authorize($context, AuthorizationResource::item('extension', $identifier));
        try {
            $this->trust->synchronizedLifecycle(fn (): array => $this->locked(
                function (DatabaseFencedExtensionRegistryLease $lease) use (
                    $identifier,
                    $context,
                    $stepUpCredential,
                ): array {
                    $this->extensions->uninstall($identifier, $context, $lease, $stepUpCredential);

                    return [];
                },
            ));
        } finally {
            $this->withdrawStaleRuntime();
        }
    }

    /**
     * Drop all resident package objects once a lifecycle operation supersedes their signed graph.
     *
     * The check also covers an operation whose durable commit succeeded before an after-event failed.
     * An idempotent install leaves the generation current and therefore leaves the resident graph alone.
     * The gate itself fails closed on an unreadable authority, so this method never masks the lifecycle
     * result with a second infrastructure exception.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function withdrawStaleRuntime(): void
    {
        if (!$this->execution->isCurrent()) {
            $this->withdrawal->withdrawAll();
        }
    }

    /**
     * Require the `extensions.manage` capability on a resource before any lock is taken.
     *
     * @param   ExecutionContext       $context   Actor, site and provenance the check runs under.
     * @param   AuthorizationResource  $resource  Extension collection or single extension the call targets.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses the actor
     *          this action on this resource.
     *
     * @since   2.0.0
     */
    private function authorize(ExecutionContext $context, AuthorizationResource $resource): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('extensions.manage'),
            $resource,
        );
    }

    /**
     * Run registry work while holding the `extension-registry` lease and the fence allocated with it.
     *
     * The lease is refused rather than queued, so a second registry operation fails fast instead of
     * waiting behind a long install. It is released on both the success and the failure path, and the
     * release is ownership-checked: a lease that expired and was re-taken elsewhere is not deleted out
     * from under its new holder, and an already committed database mutation is not reinterpreted as a
     * failed request.
     *
     * @template T
     *
     * @param   callable(DatabaseFencedExtensionRegistryLease): T  $operation  Registry work to run with
     *          the fenced lease it is handed.
     *
     * @return  T  Whatever the operation returned, passed back unchanged.
     *
     * @throws  RuntimeException  When another extension registry operation already holds the lease.
     *
     * @since   2.0.0
     */
    private function locked(callable $operation): mixed
    {
        $mutex = $this->redis->acquireLease('extension-registry', 120);
        if ($mutex === null) {
            throw new RuntimeException('Another extension registry operation is already in progress.');
        }
        $lease = new DatabaseFencedExtensionRegistryLease($mutex, $this->fences->allocate());

        try {
            $result = $operation($lease);
        } catch (Throwable $exception) {
            $mutex->release();
            throw $exception;
        }
        // Release is ownership-checked. A lost lease cannot delete a newer holder and does not
        // reinterpret an already committed database mutation as a failed request.
        $mutex->release();

        return $result;
    }
}
