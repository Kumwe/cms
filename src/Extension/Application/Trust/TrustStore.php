<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Application\Trust;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\BusinessDefinition\Application\PackageDefinitionSynchronizer;
use Kumwe\App\Extension\Application\ExtensionRuntimeWithdrawal;
use Kumwe\Extension\Manifest\ExtensionIdentifier;
use Kumwe\Extension\Manifest\ExtensionManifest;
use Kumwe\Extension\Package\PackageChecksum;
use Kumwe\Extension\Package\PublicKeyPackageSignatureVerifier;
use Kumwe\Extension\Package\PackageSignature;
use Kumwe\App\Extension\Runtime\RuntimeCanonicalJson;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Audited, serialized application boundary for extension signing-key trust.
 *
 * Two jobs meet here. It is the only place signing keys are added, rotated and revoked: every mutation is
 * authorized against `extensions.manage`, taken under the installation-wide lifecycle lock, and committed
 * in one transaction with the trust generation bump and the audit record that describes it. Withdrawing
 * a key goes further and publishes a new runtime generation, because that is the change that alters what
 * is allowed to run. It is also the enforcement point the running system keeps coming back to — install
 * and activation call `assertTrusted()`, and every extension route, event listener, contributed job run,
 * Studio preview render and administrator menu render calls `enforceRuntimeTrust()` — which is what makes
 * a key revoked long after installation take effect at the next request rather than at the next
 * deployment. Enforcement fails closed: a release that cannot be verified is quarantined before the
 * failure is raised.
 *
 * @since  2.0.0
 */
final readonly class TrustStore
{
    /**
     * Wire the trust boundary to its store, its verifiers, the runtime publisher and the audit trail.
     *
     * @param  TrustStoreRepository               $repository                  Store holding the trust keys,
     *         the installed-release trust records and the lifecycle lock.
     * @param  PublicKeyPackageSignatureVerifier  $verifier                    SDK verifier for a package signature
     *         against a host-selected stored public key.
     * @param  ExtensionArtifactVerifier          $artifacts                   Verifier that holds a release
     *         record against the bytes actually deployed on disk.
     * @param  TrustRuntimeInvalidator            $runtime                     Runtime publisher told to
     *         supersede the compiled map whenever trust changes.
     * @param  TransactionManager                 $transactions                Transaction scope every
     *         mutation and enforcement check runs inside.
     * @param  AuditRecorder                      $audit                       Sink each key mutation is
     *         recorded to, from inside its own transaction.
     * @param  ClockInterface                     $clock                       Clock for expiry comparisons
     *         and for the timestamps written to key, release and audit records.
     * @param  AuthorizationGateway               $authorization               Gateway every administrative
     *         entry point checks `extensions.manage` against.
     * @param  bool                               $allowUnsignedLocalPackages  Whether an unsigned package
     *         is admitted at all; intended for local development only.
     * @param  ?PackageDefinitionSynchronizer     $businessDefinitions         Synchronizer that deactivates
     *         business definitions owned by a quarantined extension, or null where the installation
     *         registers none.
     * @param  ?ExtensionRuntimeWithdrawal        $runtimeWithdrawal           Removes resident contribution
     *         objects after a trust invalidation commits; null in isolated trust tests without a runtime.
     *
     * @since  2.0.0
     */
    public function __construct(
        private TrustStoreRepository $repository,
        private PublicKeyPackageSignatureVerifier $verifier,
        private ExtensionArtifactVerifier $artifacts,
        private TrustRuntimeInvalidator $runtime,
        private TransactionManager $transactions,
        private AuditRecorder $audit,
        private ClockInterface $clock,
        private AuthorizationGateway $authorization,
        private bool $allowUnsignedLocalPackages = false,
        private ?PackageDefinitionSynchronizer $businessDefinitions = null,
        private ?ExtensionRuntimeWithdrawal $runtimeWithdrawal = null,
    ) {
    }

    /**
     * List every trust key for administration, annotated with the extensions that still depend on it.
     *
     * The `affected_extensions` entry added to each row is precisely what an operator needs before
     * retiring a key: an orderly revocation is refused for as long as that list is non-empty.
     *
     * @param   ExecutionContext  $context  Actor, site and provenance the listing runs under.
     *
     * @return  list<array<string, mixed>>  Key rows, each carrying an extra `affected_extensions` list.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage
     *          extensions.
     *
     * @since   2.0.0
     */
    public function keys(ExecutionContext $context): array
    {
        $this->authorize($context, AuthorizationResource::collection('extension_trust_key'));
        return array_map(function (array $key): array {
            $keyId = $key['key_id'] ?? null;
            $key['affected_extensions'] = is_string($keyId)
                ? $this->repository->extensionsRequiringKey($keyId)
                : [];
            return $key;
        }, $this->repository->all());
    }

    /**
     * Report whether the trust schema is migrated far enough for lifecycle operations to run.
     *
     * @return  bool  True when the trust store is usable; false while an install or upgrade has not yet
     *          finished migrating it.
     *
     * @since   2.0.0
     */
    public function lifecycleReady(): bool
    {
        return $this->repository->lifecycleReady();
    }

    /**
     * Bring this replica's compiled runtime map up to the authoritative generation.
     *
     * Provisioning and test harnesses call this straight after a lifecycle change so the new
     * publication is visible immediately, instead of waiting for a request to converge it.
     *
     * @return  int  The generation now in force for this replica.
     *
     * @since   2.0.0
     */
    public function synchronizeRuntimeMaterialization(): int
    {
        return $this->runtime->materialize();
    }

    /**
     * Run an operation under the installation-wide extension lifecycle lock.
     *
     * Delivery middleware, the locked extension manager and administrator theme recovery all wrap their
     * work in this, so that at most one lifecycle operation is in flight across the installation. It is
     * exposed rather than kept private because those callers need the lock to span more than the single
     * trust mutation this class would take it for.
     *
     * @template T
     *
     * @param   callable(): T  $operation  Lifecycle work to run while the lock is held.
     *
     * @return  T  Whatever the operation returned, passed back unchanged.
     *
     * @since   2.0.0
     */
    public function synchronizedLifecycle(callable $operation): mixed
    {
        return $this->repository->synchronizedLifecycle($operation);
    }

    /**
     * Require that a release record still matches the extension bytes deployed on disk.
     *
     * Exposed for install, which calls it with a record it has just synthesized for the tree it moved
     * into place, so the deployed bytes are proven to match the package whose signature was accepted
     * before any migration is allowed to run against them.
     *
     * @param   array<string, mixed>  $release  Record carrying `runtime_path` and the package, artifact
     *          and deployed-tree digests to hold the deployment against.
     *
     * @return  void
     *
     * @throws  UntrustedPackage  When the retained package or the deployed tree no longer digests to the
     *          checksums recorded for the release.
     *
     * @since   2.0.0
     */
    public function assertArtifactIntegrity(array $release): void
    {
        $this->artifacts->assertMatches($release);
    }

    /**
     * List the active extension identifiers under a lock on the trust generation.
     *
     * The generation lock is taken for the read so the answer cannot straddle a concurrent activation or
     * quarantine, and the result is sorted so callers comparing it against a compiled map see a stable
     * order.
     *
     * @return  list<string>  Active `vendor/name` identifiers in ascending string order.
     *
     * @since   2.0.0
     */
    public function activeRuntimeIdentifiers(): array
    {
        return $this->transactions->transactional(function (): array {
            $this->repository->lockGeneration();
            $identifiers = $this->repository->activeExtensions();
            sort($identifiers, SORT_STRING);
            return $identifiers;
        });
    }

    /**
     * List the active extensions that pass live trust enforcement at this moment.
     *
     * Every candidate is put through `enforceRuntimeTrust()`, so a release that fails is quarantined as
     * a side effect and simply drops out of the answer instead of raising. That is what lets the
     * administrator navigation fail closed: an extension whose trust has lapsed stops appearing in the
     * menu on the very next render.
     *
     * @return  list<string>  Active identifiers whose current release still passes live trust enforcement.
     *
     * @since   2.0.0
     */
    public function trustedActiveRuntimeIdentifiers(): array
    {
        $trusted = [];
        foreach ($this->activeRuntimeIdentifiers() as $identifier) {
            try {
                $this->enforceRuntimeTrust($identifier);
                $trusted[] = $identifier;
            } catch (UntrustedPackage | InvalidArgumentException) {
                // enforceRuntimeTrust quarantines invalid releases; navigation fails closed immediately.
            }
        }
        return $trusted;
    }

    /**
     * Admit a new signing key that a vendor may sign extension releases with.
     *
     * The insert, the generation bump and the audit record share one transaction inside the lifecycle
     * lock, so no concurrent reader can see a key that has not yet been accounted for.
     *
     * @param   ExecutionContext   $context           Actor, site and provenance the addition runs under.
     * @param   string             $keyId             Stable lowercase identifier a release signature will
     *          name to select this key.
     * @param   string             $publicKeyBase64   Base64 Ed25519 public key; must decode to 32 bytes.
     * @param   string             $vendorNamespace   Vendor half of `vendor/name` the key may sign for,
     *          or `*` for any vendor.
     * @param   string             $extensionPattern  Name half of `vendor/name` the key may sign for, or
     *          `*` for any extension.
     * @param   DateTimeImmutable  $expiresAt         When the key stops being usable; must be in the
     *          future and no more than three years out.
     * @param   ?string            $rotatedFrom       Key this one supersedes, recorded for the rotation
     *          trail; null for a key that replaces nothing.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage
     *          extensions.
     * @throws  InvalidArgumentException  When the key identifier, public key, namespace constraint or
     *          expiry window fails validation.
     *
     * @since   2.0.0
     */
    public function add(
        ExecutionContext $context,
        string $keyId,
        string $publicKeyBase64,
        string $vendorNamespace,
        string $extensionPattern,
        DateTimeImmutable $expiresAt,
        ?string $rotatedFrom = null,
    ): void {
        $this->authorize($context, AuthorizationResource::collection('extension_trust_key'));
        $values = $this->keyValues(
            $context,
            $keyId,
            $publicKeyBase64,
            $vendorNamespace,
            $extensionPattern,
            $expiresAt,
            $rotatedFrom,
        );
        $this->repository->synchronizedLifecycle(function () use ($context, $values): void {
            $this->transactions->transactional(function () use ($context, $values): void {
                $this->repository->lockGeneration();
                $this->repository->add($values);
                $this->repository->advanceGeneration($this->clock->now());
                $this->record($context, 'extension.trust_key.add', $values['key_id'], [
                    'vendor_namespace' => $values['vendor_namespace'],
                    'extension_pattern' => $values['extension_pattern'],
                    'expires_at' => $values['expires_at']->format(DATE_ATOM),
                    'rotated_from' => $values['rotated_from'],
                ]);
            });
        });
    }

    /**
     * Begins a two-phase rotation in which the old key remains trusted during the overlap.
     *
     * The overlap is the whole point: releases already signed by the outgoing key keep verifying until
     * each has been re-signed, after which `finalizeRotation()` retires it. The replacement must carry
     * exactly the old key's vendor namespace and extension pattern, so rotation can never quietly widen
     * what a vendor is allowed to sign.
     *
     * @param   ExecutionContext   $context           Actor, site and provenance the rotation runs under.
     * @param   string             $oldKeyId          Key being superseded; must still be active.
     * @param   string             $newKeyId          Identifier of the replacement key.
     * @param   string             $publicKeyBase64   Base64 Ed25519 public key of the replacement.
     * @param   string             $vendorNamespace   Vendor namespace, which must equal the old key's.
     * @param   string             $extensionPattern  Extension pattern, which must equal the old key's.
     * @param   DateTimeImmutable  $expiresAt         When the replacement key stops being usable.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage
     *          extensions.
     * @throws  InvalidArgumentException  When an argument fails validation, no active key carries the old
     *          identifier, or the replacement would change the namespace constraints.
     *
     * @since   2.0.0
     */
    public function rotate(
        ExecutionContext $context,
        string $oldKeyId,
        string $newKeyId,
        string $publicKeyBase64,
        string $vendorNamespace,
        string $extensionPattern,
        DateTimeImmutable $expiresAt,
    ): void {
        $this->authorize($context, AuthorizationResource::item('extension_trust_key', $oldKeyId));
        $oldKeyId = $this->keyId($oldKeyId);
        $values = $this->keyValues(
            $context,
            $newKeyId,
            $publicKeyBase64,
            $vendorNamespace,
            $extensionPattern,
            $expiresAt,
            $oldKeyId,
        );
        $this->repository->synchronizedLifecycle(function () use ($context, $oldKeyId, $values): void {
            $this->transactions->transactional(function () use ($context, $oldKeyId, $values): void {
                $this->repository->lockGeneration();
                $old = $this->activeKey($oldKeyId);
                if ($old === null) {
                    throw new InvalidArgumentException('The active trust key to rotate does not exist.');
                }
                if (
                    ($old['vendor_namespace'] ?? null) !== $values['vendor_namespace']
                    || ($old['extension_pattern'] ?? null) !== $values['extension_pattern']
                ) {
                    throw new InvalidArgumentException(
                        'A replacement key must preserve the old key namespace constraints.',
                    );
                }
                $this->repository->add($values);
                $this->repository->advanceGeneration($this->clock->now());
                $this->record($context, 'extension.trust_key.rotate.begin', $oldKeyId, [
                    'replacement_key_id' => $values['key_id'],
                    'affected_extensions' => $this->repository->extensionsRequiringKey($oldKeyId),
                ]);
            });
        });
    }

    /**
     * Close a rotation by retiring the key the replacement has taken over from.
     *
     * This is `revoke()` under a rotation-specific audit action, so it inherits the same refusal: the
     * outgoing key cannot be retired while any installed release still names it.
     *
     * @param   ExecutionContext  $context   Actor, site and provenance the finalization runs under.
     * @param   string            $oldKeyId  Key the replacement has taken over from.
     * @param   string            $reason    Operator explanation recorded with the revocation.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage
     *          extensions.
     * @throws  InvalidArgumentException  When the key identifier or reason is invalid, the key is not
     *          active, or installed releases still require it.
     *
     * @since   2.0.0
     */
    public function finalizeRotation(ExecutionContext $context, string $oldKeyId, string $reason): void
    {
        $this->revoke($context, $oldKeyId, $reason, 'extension.trust_key.rotate.finalize');
    }

    /**
     * Withdraw a signing key in the orderly case, where nothing installed still depends on it.
     *
     * Revocation is refused for as long as any installed release names the key, so an operator upgrades
     * or quarantines those extensions first; `emergencyRevoke()` is the path that refuses to wait. The
     * revocation, the generation bump, the runtime invalidation and the audit record commit together,
     * and the replica's map is materialized afterwards on a best-effort basis.
     *
     * @param   ExecutionContext  $context      Actor, site and provenance the revocation runs under.
     * @param   string            $keyId        Key to withdraw.
     * @param   string            $reason       Operator explanation of 1 to 500 characters, stored on the
     *          key record and in the audit trail.
     * @param   string            $auditAction  Audit action recorded for this revocation; overridden by
     *          `finalizeRotation()` so a rotation reads differently from a plain revocation.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage
     *          extensions.
     * @throws  InvalidArgumentException  When the key identifier or reason is invalid, the key is not
     *          active, or installed releases still require it.
     *
     * @since   2.0.0
     */
    public function revoke(
        ExecutionContext $context,
        string $keyId,
        string $reason,
        string $auditAction = 'extension.trust_key.revoke',
    ): void {
        $this->authorize($context, AuthorizationResource::item('extension_trust_key', $keyId));
        $keyId = $this->keyId($keyId);
        $reason = $this->reason($reason);
        $this->repository->synchronizedLifecycle(function () use ($context, $keyId, $reason, $auditAction): void {
            $this->transactions->transactional(function () use ($context, $keyId, $reason, $auditAction): void {
                $this->repository->lockGeneration();
                $affected = $this->repository->extensionsRequiringKey($keyId);
                if ($affected !== []) {
                    throw new InvalidArgumentException(
                        'The trust key cannot be revoked until affected releases are upgraded or quarantined: '
                        . implode(', ', $affected),
                    );
                }
                $now = $this->clock->now();
                $this->repository->revoke($keyId, $context->actorId(), $reason, $now);
                $this->repository->advanceGeneration($now);
                $this->runtime->advance($auditAction, $keyId);
                $this->record($context, $auditAction, $keyId, ['reason' => $reason]);
                $this->transactions->afterCommit(function (): void {
                    $this->materializeBestEffort();
                });
            });
        });
    }

    /**
     * Immediately revokes a key and quarantines every active release signed by it.
     *
     * The break-glass path for a key believed compromised: it does not wait for affected extensions to
     * be upgraded, it disables them. Business definitions owned by each quarantined extension are
     * deactivated in the same transaction, so nothing the extension contributed stays live either.
     *
     * @param   ExecutionContext  $context  Actor, site and provenance the revocation runs under.
     * @param   string            $keyId    Key believed to be compromised.
     * @param   string            $reason   Operator explanation of 1 to 500 characters.
     *
     * @return  list<string>  Identifiers quarantined as a result, empty when the key signed nothing
     *          active.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage
     *          extensions.
     * @throws  InvalidArgumentException  When the key identifier or reason is invalid, or no active key
     *          carries that identifier.
     *
     * @since   2.0.0
     */
    public function emergencyRevoke(ExecutionContext $context, string $keyId, string $reason): array
    {
        $this->authorize($context, AuthorizationResource::item('extension_trust_key', $keyId));
        $keyId = $this->keyId($keyId);
        $reason = $this->reason($reason);
        $quarantined = $this->repository->synchronizedLifecycle(function () use ($context, $keyId, $reason): array {
            return $this->transactions->transactional(function () use ($context, $keyId, $reason): array {
                $this->repository->lockGeneration();
                $now = $this->clock->now();
                $this->repository->revoke($keyId, $context->actorId(), $reason, $now);
                $quarantined = $this->repository->quarantineExtensionsForKey($keyId, $now);
                foreach ($quarantined as $identifier) {
                    $this->businessDefinitions?->setActive($identifier, false, $context->actorId());
                }
                $this->repository->advanceGeneration($now);
                $this->runtime->advance('extension.trust_key.revoke.emergency', $keyId);
                $this->record($context, 'extension.trust_key.revoke.emergency', $keyId, [
                    'reason' => $reason,
                    'quarantined_extensions' => $quarantined,
                ]);
                $this->transactions->afterCommit(function (): void {
                    $this->materializeBestEffort();
                });
                return $quarantined;
            });
        });
        return $quarantined;
    }

    /**
     * Require that a package checksum carries a signature from a key usable for this extension.
     *
     * The named key has to be enabled, unrevoked, unexpired and admitted by its own namespace
     * constraint, and the signature has to verify over the checksum; an unsigned package gets through
     * only where the installation has deliberately allowed unsigned packages. Install asks twice — once
     * on the incoming archive before anything is staged, and again inside the transaction that persists
     * the release, with `$serialize` set — so a key revoked while the package was being unpacked cannot
     * be raced past this gate.
     *
     * @param   PackageChecksum      $checksum   Digest of the package bytes being admitted.
     * @param   ?PackageSignature    $signature  Signature presented with the package, or null when it
     *          carries none.
     * @param   ExtensionIdentifier  $extension  Extension the package claims to be, checked against the
     *          key's vendor and extension constraints.
     * @param   bool                 $serialize  Whether to take the trust generation lock before
     *          reading, for callers that must not race a concurrent revocation.
     *
     * @return  void
     *
     * @throws  UntrustedPackage  When the package is unsigned and unsigned packages are disabled, no
     *          usable key matches the signature, or the signature does not verify.
     *
     * @since   2.0.0
     */
    public function assertTrusted(
        PackageChecksum $checksum,
        ?PackageSignature $signature,
        ExtensionIdentifier $extension,
        bool $serialize = false,
    ): void {
        if ($serialize) {
            $this->repository->lockGeneration();
        }
        if ($signature === null) {
            if ($this->allowUnsignedLocalPackages) {
                return;
            }
            throw new UntrustedPackage('Unsigned extension packages are disabled for this installation.');
        }
        $key = $this->repository->usable($signature->keyId(), $extension->value(), $this->clock->now());
        $publicKey = $key['public_key_base64'] ?? null;
        if (!is_string($publicKey)) {
            throw new UntrustedPackage('The extension signing key is revoked, expired, or outside its namespace.');
        }
        if (!$this->verifier->verify($publicKey, $checksum, $signature)) {
            throw new UntrustedPackage('The extension package signature is invalid.');
        }
    }

    /**
     * Enforces deployed-byte trust and equality with the already-decoded runtime publication entry.
     *
     * Reach for this where the compiled map has been read and each entry must be proven to still
     * describe the authoritative release; `enforceRuntimeTrust()` is the variant for an identifier alone.
     *
     * @param   array<string, mixed>  $entry  One decoded entry from the compiled runtime publication.
     *
     * @return  void
     *
     * @throws  UntrustedPackage  When the entry carries no string identifier, or the extension's own
     *          release record fails verification.
     * @throws  RuntimePublicationMismatch  When the entry disagrees with authoritative release metadata.
     * @throws  InvalidArgumentException  When the identifier is malformed or the stored manifest cannot
     *          be parsed.
     *
     * @since   2.0.0
     */
    public function enforceRuntimeEntryTrust(array $entry): void
    {
        $identifier = $entry['identifier'] ?? null;
        if (!is_string($identifier)) {
            throw new UntrustedPackage('The compiled extension entry has no identifier.');
        }
        $this->enforceRuntimeTrust($identifier, $entry);
    }

    /**
     * Enforces trust for authoritative active inventory records that may be absent from the publication.
     *
     * This runs on every extension request, every extension event dispatch and every administrator menu
     * render, which is what makes a key revoked long after installation bite immediately. Failure is not
     * passive: an extension whose release cannot be verified is quarantined before the exception leaves
     * this method. A publication mismatch is deliberately exempt from that, because the extension is
     * still sound and only this replica's compiled map is out of step.
     *
     * @param   string                     $extensionIdentifier  `vendor/name` of the extension to check.
     * @param   array<string, mixed>|null  $entry                Compiled publication entry to hold
     *          against authoritative metadata, or null to check the installed release alone.
     *
     * @return  void
     *
     * @throws  RuntimePublicationMismatch  When a supplied entry names an extension that is no longer
     *          active, or disagrees with authoritative release metadata.
     * @throws  UntrustedPackage  When the extension is no longer active and no entry was supplied, or
     *          its release record is missing or unverified, its signing key is unusable, its signature
     *          fails, or its deployed bytes have changed. The extension is quarantined first.
     * @throws  InvalidArgumentException  When the identifier is not a valid `vendor/name`, or the stored
     *          manifest, checksum or signature cannot be parsed. The extension is quarantined first.
     *
     * @since   2.0.0
     */
    public function enforceRuntimeTrust(string $extensionIdentifier, ?array $entry = null): void
    {
        try {
            $this->transactions->transactional(function () use ($extensionIdentifier, $entry): void {
                $extension = ExtensionIdentifier::fromString($extensionIdentifier);
                $this->repository->lockGeneration();
                if (!in_array($extensionIdentifier, $this->repository->activeExtensions(), true)) {
                    if ($entry !== null) {
                        throw new RuntimePublicationMismatch('The compiled extension is no longer active.');
                    }
                    throw new UntrustedPackage('The extension is no longer active.');
                }
                $release = $this->verifiedInstalledRelease($extension);
                if ($entry !== null) {
                    $this->assertRuntimeEntryMatches($entry, $release, $extension);
                }
            });
        } catch (RuntimePublicationMismatch $exception) {
            throw $exception;
        } catch (UntrustedPackage | InvalidArgumentException $exception) {
            $this->quarantine($extensionIdentifier);
            throw $exception;
        }
    }

    /**
     * Re-run the full trust check over an extension's installed release and return its record.
     *
     * Four things must hold: the release row exists and carries a package digest, its trust state is
     * `verified`, its signature still checks out against a currently usable key, and the retained
     * package and deployed tree still digest to what was recorded.
     *
     * @param   ExtensionIdentifier  $extension  Extension whose installed release is being re-checked.
     *
     * @return  array<string, mixed>  The release record, now proven to be trustworthy.
     *
     * @throws  UntrustedPackage  When the release record is missing, is not in the `verified` state, its
     *          signing key is unusable, its signature fails, or its deployed bytes have changed.
     * @throws  InvalidArgumentException  When the stored package digest or signature is not in the shape
     *          its value object accepts.
     *
     * @since   2.0.0
     */
    private function verifiedInstalledRelease(ExtensionIdentifier $extension): array
    {
        $extensionIdentifier = $extension->value();
        $release = $this->repository->installedRelease($extensionIdentifier);
        if ($release === null || !is_string($release['package_sha256'] ?? null)) {
            throw new UntrustedPackage('The installed extension release trust record is missing.');
        }
        if (($release['trust_state'] ?? null) !== 'verified') {
            throw new UntrustedPackage('The installed extension release must be reinstalled to restore trust.');
        }
        $keyId = $release['signing_key_id'] ?? null;
        $signature = $release['signature_base64'] ?? null;
        $packageSignature = is_string($keyId) && is_string($signature)
            ? PackageSignature::ed25519($keyId, $signature)
            : null;
        $this->assertTrusted(PackageChecksum::sha256($release['package_sha256']), $packageSignature, $extension);
        $this->artifacts->assertMatches($release);
        return $release;
    }

    /**
     * Require that a compiled publication entry agrees with the authoritative release in every field.
     *
     * The manifest is re-parsed from the release record rather than taken from the entry, and is first
     * held against the release row itself; only then is every field the runtime depends on — version,
     * provider, type, root, signing key, artifact digests, autoload map, manifest schema and
     * contributions — compared with the entry. Two absences are tolerated so an older publication shape
     * is not mistaken for tampering: a missing `contributions` key reads as the manifest's own set, and
     * a missing `manifest_schema` key reads as schema 1.
     *
     * @param   array<string, mixed>  $entry      Decoded entry from the compiled runtime publication.
     * @param   array<string, mixed>  $release    Authoritative release record to hold it against.
     * @param   ExtensionIdentifier   $extension  Extension both are expected to describe.
     *
     * @return  void
     *
     * @throws  UntrustedPackage  When the stored manifest disagrees with the release row it is stored on.
     * @throws  RuntimePublicationMismatch  When any compiled field disagrees with authoritative metadata.
     * @throws  InvalidArgumentException  When the stored manifest cannot be parsed.
     *
     * @since   2.0.0
     */
    private function assertRuntimeEntryMatches(
        array $entry,
        array $release,
        ExtensionIdentifier $extension,
    ): void {
        $manifestValue = $release['manifest'] ?? null;
        $manifest = ExtensionManifest::fromJson(is_string($manifestValue)
            ? $manifestValue
            : json_encode($manifestValue, JSON_THROW_ON_ERROR));
        if (
            !$manifest->identifier()->equals($extension)
            || (string) $manifest->version() !== ($release['installed_version'] ?? null)
            || $manifest->serviceProvider() !== ($release['service_provider'] ?? null)
            || $manifest->type()->value !== ($release['extension_type'] ?? null)
        ) {
            throw new UntrustedPackage('The installed extension manifest does not match its runtime inventory.');
        }
        $expected = [
            'identifier' => $extension->value(),
            'version' => (string) $manifest->version(),
            'provider' => $manifest->serviceProvider(),
            'type' => $manifest->type()->value,
            'root' => $release['runtime_path'] ?? null,
            'signing_key_id' => $release['signing_key_id'] ?? null,
            'artifact_sha256' => $release['artifact_sha256'] ?? null,
            'deployed_tree_sha256' => $release['deployed_tree_sha256'] ?? null,
        ];
        foreach ($expected as $field => $value) {
            if (($entry[$field] ?? null) !== $value) {
                throw new RuntimePublicationMismatch(sprintf(
                    'The compiled extension %s does not match authoritative release metadata.',
                    $field,
                ));
            }
        }
        $autoload = $entry['autoload'] ?? null;
        if (
            !is_array($autoload)
            || RuntimeCanonicalJson::encode($autoload) !== RuntimeCanonicalJson::encode($manifest->autoload())
        ) {
            throw new RuntimePublicationMismatch('The compiled extension autoload map is not authoritative.');
        }
        if (($entry['manifest_schema'] ?? 1) !== $manifest->schemaVersion()) {
            throw new RuntimePublicationMismatch('The compiled extension manifest schema is not authoritative.');
        }
        $expectedContributions = $manifest->contributions()->declarations();
        $contributions = $entry['contributions'] ?? $expectedContributions;
        if (
            !is_array($contributions)
            || RuntimeCanonicalJson::encode($contributions) !== RuntimeCanonicalJson::encode($expectedContributions)
        ) {
            throw new RuntimePublicationMismatch('The compiled extension contributions are not authoritative.');
        }
    }

    /**
     * Report whether this replica's trust boundary is healthy enough to serve requests.
     *
     * The readiness probe calls this, and it is deliberately thorough rather than cheap: the schema must
     * be migrated, the compiled map must materialize, and every active extension must currently pass
     * enforcement. A materialization failure reads as not-ready rather than as an error, and an
     * extension that fails enforcement is quarantined by the pass that discovers it.
     *
     * @return  bool  True only when the schema, the runtime map and every active extension check out.
     *
     * @since   2.0.0
     */
    public function ready(): bool
    {
        if (!$this->lifecycleReady()) {
            return false;
        }
        try {
            $this->runtime->materialize();
        } catch (Throwable) {
            return false;
        }
        $ready = true;
        foreach ($this->activeRuntimeIdentifiers() as $identifier) {
            try {
                $this->enforceRuntimeTrust($identifier);
            } catch (UntrustedPackage | InvalidArgumentException) {
                $ready = false;
            }
        }
        return $ready;
    }

    /**
     * Withdraw an extension that failed enforcement, and publish the generation that takes it out.
     *
     * Only an extension that was still active is moved, so a release failing on every request does not
     * keep bumping the trust generation. Its business definitions are deactivated in the same
     * transaction, and the replica's compiled map is refreshed once that transaction commits.
     *
     * @param   string  $identifier  `vendor/name` of the extension to quarantine.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function quarantine(string $identifier): void
    {
        $this->transactions->transactional(function () use ($identifier): void {
            $this->repository->lockGeneration();
            $now = $this->clock->now();
            if ($this->repository->quarantineExtension($identifier, $now)) {
                $this->businessDefinitions?->setActive($identifier, false, 'system:trust-quarantine');
                $this->repository->advanceGeneration($now);
                $this->runtime->advance('extension.trust.quarantine', $identifier);
                $this->transactions->afterCommit(function (): void {
                    $this->materializeBestEffort();
                });
            }
        });
    }

    /**
     * Refresh this replica's compiled map after a commit, tolerating a failure to do so.
     *
     * Registered as an after-commit hook, so the authoritative change has already landed by the time it
     * runs. Failing here would unwind nothing, and bootstrap retries materialization and fails closed if
     * it still cannot converge, which is why the error is swallowed rather than propagated.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function materializeBestEffort(): void
    {
        try {
            $this->runtime->materialize();
        } catch (Throwable) {
            // Authoritative database state is committed; bootstrap retries materialization and fails closed.
        } finally {
            $this->runtimeWithdrawal?->withdrawAll();
        }
    }

    /**
     * Require `extensions.manage` on a resource before an administrative entry point proceeds.
     *
     * Called first in every public mutation, so the refusal is raised before the lifecycle lock is
     * taken and before any transaction is opened.
     *
     * @param   ExecutionContext       $context   Actor, site and provenance the operation runs under.
     * @param   AuthorizationResource  $resource  Trust-key collection, or the single key being acted on.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses the actor
     *          this capability on that resource.
     *
     * @since   2.0.0
     */
    private function authorize(ExecutionContext $context, AuthorizationResource $resource): void
    {
        $capability = Capability::fromString('extensions.manage');
        $this->authorization->assertAllowed($context, $capability, $resource);
    }

    /**
     * Validate every field of a proposed key and assemble the row the repository will store.
     *
     * Shared by `add()` and `rotate()` so that a rotation cannot admit a key a plain addition would
     * refuse. The algorithm is fixed at `ed25519` and the revocation columns start null, which is what
     * makes a freshly stored row unambiguously active.
     *
     * @param   ExecutionContext   $context           Actor credited on the record as having added it.
     * @param   string             $keyId             Proposed key identifier.
     * @param   string             $publicKeyBase64   Proposed base64 Ed25519 public key.
     * @param   string             $vendorNamespace   Vendor the key may sign for, or `*` for any.
     * @param   string             $extensionPattern  Extension the key may sign for, or `*` for any.
     * @param   DateTimeImmutable  $expiresAt         Proposed expiry.
     * @param   ?string            $rotatedFrom       Key this one supersedes, or null.
     *
     * @return  array{
     *            key_id: string, algorithm: string, public_key_base64: string, enabled: bool,
     *            vendor_namespace: string, extension_pattern: string, expires_at: DateTimeImmutable,
     *            rotated_from: ?string, added_by: string, added_at: DateTimeImmutable,
     *            revoked_at: null, revoked_by: null, revocation_reason: null
     *          }  The validated row, with both identifiers normalized to lowercase.
     *
     * @throws  InvalidArgumentException  When an identifier or the public key is malformed, the
     *          namespace constraint is invalid, or the expiry is not in the future and within three
     *          years.
     *
     * @since   2.0.0
     */
    private function keyValues(
        ExecutionContext $context,
        string $keyId,
        string $publicKeyBase64,
        string $vendorNamespace,
        string $extensionPattern,
        DateTimeImmutable $expiresAt,
        ?string $rotatedFrom,
    ): array {
        $keyId = $this->keyId($keyId);
        $publicKeyBase64 = $this->publicKey($publicKeyBase64);
        [$vendorNamespace, $extensionPattern] = $this->constraint($vendorNamespace, $extensionPattern);
        $now = $this->clock->now();
        if ($expiresAt <= $now || $expiresAt > $now->modify('+3 years')) {
            throw new InvalidArgumentException('A trust key must expire in the future and within three years.');
        }
        return [
            'key_id' => $keyId,
            'algorithm' => 'ed25519',
            'public_key_base64' => $publicKeyBase64,
            'enabled' => true,
            'vendor_namespace' => $vendorNamespace,
            'extension_pattern' => $extensionPattern,
            'expires_at' => $expiresAt,
            'rotated_from' => $rotatedFrom === null ? null : $this->keyId($rotatedFrom),
            'added_by' => $context->actorId(),
            'added_at' => $now,
            'revoked_at' => null,
            'revoked_by' => null,
            'revocation_reason' => null,
        ];
    }

    /**
     * Find a key by identifier, but only while it is still enabled, unrevoked and unexpired.
     *
     * Expiry is read whether the store hands back a `DateTimeImmutable` or a string, and an expiry
     * string that cannot be parsed abandons the search rather than falling through to another record.
     *
     * @param   string  $keyId  Identifier to look for, already normalized.
     *
     * @return  array<string, mixed>|null  The key row, or null when no usable key carries that
     *          identifier.
     *
     * @since   2.0.0
     */
    private function activeKey(string $keyId): ?array
    {
        foreach ($this->repository->all() as $key) {
            if (
                ($key['key_id'] ?? null) !== $keyId || !(bool) ($key['enabled'] ?? false)
                || ($key['revoked_at'] ?? null) !== null
            ) {
                continue;
            }
            $expiresAt = $key['expires_at'] ?? null;
            if ($expiresAt instanceof DateTimeImmutable && $expiresAt > $this->clock->now()) {
                return $key;
            }
            if (is_string($expiresAt)) {
                try {
                    if (new DateTimeImmutable($expiresAt) > $this->clock->now()) {
                        return $key;
                    }
                } catch (\Exception) {
                    return null;
                }
            }
        }
        return null;
    }

    /**
     * Normalize a trust key identifier and refuse anything that is not one.
     *
     * @param   string  $keyId  Identifier as supplied by an operator or read back from a record.
     *
     * @return  string  The identifier trimmed and lowercased, which is the stored spelling.
     *
     * @throws  InvalidArgumentException  When the result is not 3 to 127 characters of lowercase
     *          letters, digits, dot, underscore, colon or hyphen, starting with a letter or digit.
     *
     * @since   2.0.0
     */
    private function keyId(string $keyId): string
    {
        $keyId = strtolower(trim($keyId));
        if (preg_match('/^[a-z0-9][a-z0-9._:-]{2,126}$/D', $keyId) !== 1) {
            throw new InvalidArgumentException('A trust key ID must be a stable lowercase identifier.');
        }
        return $keyId;
    }

    /**
     * Normalize an operator-supplied revocation reason and refuse an unusable one.
     *
     * A reason is required because it is written to the key record and to the audit trail, where it is
     * the only account of why the key was withdrawn.
     *
     * @param   string  $reason  Explanation as supplied by the operator.
     *
     * @return  string  The reason with surrounding whitespace removed.
     *
     * @throws  InvalidArgumentException  When the trimmed reason is empty or exceeds 500 characters.
     *
     * @since   2.0.0
     */
    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('A trust-key revocation reason of 1 to 500 characters is required.');
        }
        return $reason;
    }

    /**
     * Validate an Ed25519 public key and return it in canonical base64.
     *
     * Decoding strictly and re-encoding is what makes the stored form canonical, so two spellings of the
     * same key cannot both be admitted and later be treated as different keys.
     *
     * @param   string  $encoded  Base64 public key as supplied by an operator.
     *
     * @return  string  Canonical base64 encoding of the 32 key bytes.
     *
     * @throws  InvalidArgumentException  When the value is not strict base64 of exactly 32 bytes.
     *
     * @since   2.0.0
     */
    private function publicKey(string $encoded): string
    {
        $bytes = base64_decode($encoded, true);
        if (!is_string($bytes) || strlen($bytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new InvalidArgumentException('An Ed25519 public key must be canonical base64 encoding of 32 bytes.');
        }
        return base64_encode($bytes);
    }

    /**
     * Validate the namespace constraint that bounds what a key is allowed to sign.
     *
     * Each half is either a single identifier or the wildcard `*`; there is no partial matching, so a
     * key is scoped to exactly one vendor, one extension, or everything.
     *
     * @param   string  $vendor   Vendor half of `vendor/name`, or `*` for any vendor.
     * @param   string  $pattern  Name half of `vendor/name`, or `*` for any extension.
     *
     * @return  array{string, string}  The trimmed, lowercased vendor and pattern, in that order.
     *
     * @throws  InvalidArgumentException  When either half is neither `*` nor a short identifier of
     *          lowercase letters, digits, dot, underscore or hyphen.
     *
     * @since   2.0.0
     */
    private function constraint(string $vendor, string $pattern): array
    {
        $vendor = strtolower(trim($vendor));
        $pattern = strtolower(trim($pattern));
        $segment = '[a-z0-9](?:[a-z0-9._-]{0,62})';
        if ($vendor !== '*' && preg_match('/^' . $segment . '$/D', $vendor) !== 1) {
            throw new InvalidArgumentException('The trust-key vendor namespace is invalid.');
        }
        if ($pattern !== '*' && preg_match('/^' . $segment . '$/D', $pattern) !== 1) {
            throw new InvalidArgumentException('The trust-key extension pattern is invalid.');
        }
        return [$vendor, $pattern];
    }

    /**
     * Write the audit record for a trust-key mutation that is about to commit.
     *
     * Called from inside the mutation's own transaction, so the trail rolls back with the change it
     * describes and can never claim something that did not happen.
     *
     * @param   ExecutionContext      $context   Actor, site and provenance credited with the mutation.
     * @param   string                $action    Audit action name, such as `extension.trust_key.revoke`.
     * @param   string                $keyId     Key the record is filed against as its subject.
     * @param   array<string, mixed>  $metadata  Action-specific detail stored with the record, such as
     *          the replacement key or the extensions quarantined.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function record(ExecutionContext $context, string $action, string $keyId, array $metadata): void
    {
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $this->clock->now(),
            $context->actorId(),
            $action,
            'extension_trust_key',
            $keyId,
            'success',
            $metadata,
        ));
    }
}
