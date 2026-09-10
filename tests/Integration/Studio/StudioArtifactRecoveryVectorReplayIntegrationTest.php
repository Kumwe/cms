<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Studio;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Application\Persistence\TransactionState;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Content\Application\ContentModelRepository;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Studio\Application\Composition\StudioPublishedCompositionGuard;
use Kumwe\App\Studio\Application\Composition\StudioPublishedTheme;
use Kumwe\App\Studio\Application\Host\StudioArtifactAdmission;
use Kumwe\App\Studio\Application\Host\StudioArtifactHostPort;
use Kumwe\App\Studio\Application\Host\StudioArtifactRepository;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Host\StudioHostSessionRepository;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Host\StudioLocalizationHostPort;
use Kumwe\App\Studio\Application\Host\StudioModelHostPort;
use Kumwe\App\Studio\Application\Host\StudioMutationOutcomeCodec;
use Kumwe\App\Studio\Application\Host\StudioMutationReplayRepository;
use Kumwe\App\Studio\Application\Host\StudioPermissionHostPort;
use Kumwe\App\Studio\Application\Host\StudioProducerHost;
use Kumwe\App\Studio\Application\Host\StudioProducerMutationBoundary;
use Kumwe\App\Studio\Application\Host\StudioProducerRequestAuthority;
use Kumwe\App\Studio\Application\Host\StudioRecoveryHostPort;
use Kumwe\App\Studio\Application\Host\StudioRecoveryRepository;
use Kumwe\App\Studio\Application\Host\StudioResourceContextKeyFactory;
use Kumwe\App\Studio\Application\Host\StudioResourceHostPort;
use Kumwe\App\Studio\Application\Host\StudioTelemetryHostPort;
use Kumwe\App\Studio\Application\Media\StudioMediaHostPort;
use Kumwe\App\Studio\Application\Media\StudioMediaOperations;
use Kumwe\App\Studio\Application\Preview\StudioPreviewHostPort;
use Kumwe\App\Studio\Application\Projection\ContentStudioProjector;
use Kumwe\App\Studio\Domain\Artifact\StoredStudioArtifact;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\App\Studio\Infrastructure\Persistence\DoctrineStudioHostStorage;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Producer\Canonical\CanonicalJson;
use Kumwe\Producer\Schema\StudioContractResources;
use Kumwe\Producer\Wire\Dispatcher;
use Kumwe\Producer\Wire\OperationRegistry;
use Kumwe\Producer\Wire\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;
use stdClass;

/**
 * Replays Producer's vendored artifact and recovery host vectors through the App's real Producer ports.
 *
 * Every vector is read from the pinned testkit manifest through `StudioContractResources`, so the corpus is
 * never copied into the App. Each request travels the consumer path a browser request takes after
 * authentication: Producer's `Dispatcher` over a `StudioProducerHost` composed from the container's port
 * prototypes bound with `forRequest()`, the `StudioProducerMutationBoundary` over the container's transaction
 * manager, audit recorder, replay store and outcome codec, and `DoctrineStudioHostStorage` on the shared
 * integration database. The harness translates only what a vector cannot know about its host: the semantic
 * `argument` becomes the published HTTP wrapper (`reference`, `document`, `envelope`), the vector's
 * `resourceContextKey` and `sessionGeneration` become the live session key and generation the App issued,
 * and every artifact identifier carries a per-run suffix so a reused database stays idempotent.
 *
 * Studio permissions are derived from App authority rather than declared: `studio.permission/save` follows
 * the session mode, so a vector that withholds save from the save operation is replayed in the read-only
 * mode, and `studio.permission/publish` maps to the `content.publish` and `content.unpublish` capabilities.
 * The App cannot grant publish while withholding save, so a vector that withholds save only from a
 * lifecycle operation keeps its authoring mode and is decided on its publish authority alone. Recovery
 * vectors declare no permissions because Producer's baseline profile does not gate recovery; the App
 * additionally requires the session's save permission for recovery writes, so they run in an authoring mode.
 * Sessions are opened through the real `StudioHostSessionAuthority` over the in-memory session store and
 * gateway that `tests/Support/StudioProducerRequest` uses; that helper is not reused directly because it
 * opens Content-mode sessions only and turns an authorization refusal into a `LogicException`, whereas the
 * Blueprint and model vectors need their own modes and several vectors expect the authorization refusal.
 *
 * Out of this suite's consumer scope, deliberately and not silently:
 *  - `vectors/host-sequence/preview.*` (2 vectors): their `release-preview-render` and pending-render
 *    settlement actions drive Producer's asynchronous preview harness; the App serves previews through the
 *    grant and sequence ledger exercised by `StudioPreviewPersistenceTest`.
 *  - `vectors/host/{envelope,localization,media,model,permission,telemetry}.*` (20 vectors): they belong to
 *    port families other than the artifact and recovery ports this suite replays; the media family is
 *    replayed by `StudioMediaProducerPortTest`.
 *  - The `rateLimits` declared for `studio.operation/artifact.publish` by the idempotent-replay sequence: the
 *    App rate-limits recovery writes only, so that clause is proven by the single committed revision and
 *    audit row the three publish attempts leave behind.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineStudioHostStorage::class)]
#[CoversClass(StudioArtifactAdmission::class)]
#[CoversClass(StudioArtifactHostPort::class)]
#[CoversClass(StudioProducerHost::class)]
#[CoversClass(StudioProducerMutationBoundary::class)]
#[CoversClass(StudioProducerRequestAuthority::class)]
#[CoversClass(StudioPublishedCompositionGuard::class)]
#[CoversClass(StudioRecoveryHostPort::class)]
final class StudioArtifactRecoveryVectorReplayIntegrationTest extends TestCase
{
    /**
     * Stable App diagnostic code paired with every refusing single-operation vector.
     *
     * The corpus pins the refusal category; the App additionally commits to one delivery-safe diagnostic
     * per refusal path, and an authorization-stage refusal carries the session diagnostic rather than a
     * port diagnostic because the port is never reached.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const array HOST_REFUSAL_CODES = [
        'vector.host-vector.artifact.load.unknown' => 'studio.artifact/not-found',
        'vector.host-vector.artifact.publish.forbidden' => 'studio.host/session-refused',
        'vector.host-vector.artifact.publish.stale' => 'studio.artifact/revision-conflict',
        'vector.host-vector.artifact.save.forbidden' => 'studio.host/session-refused',
        'vector.host-vector.artifact.save.stale' => 'studio.artifact/revision-conflict',
        'vector.host-vector.artifact.unpublish.stale' => 'studio.artifact/revision-conflict',
    ];

    /**
     * Stable refusal code paired with every refusing sequence step, keyed by vector and step identifier.
     *
     * The wrong-operation refusal is Producer's own envelope cross-check, which fires before the App is
     * asked anything, so its code is the dispatcher's message key rather than an App diagnostic.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const array SEQUENCE_REFUSAL_CODES = [
        'vector.host-sequence.artifact.publish.changed-intent:publish-changed-intent'
            => 'studio.host/idempotency-intent-changed',
        'vector.host-sequence.recovery.store.changed-context:store-with-locale'
            => 'studio.host/idempotency-intent-changed',
        'vector.host-sequence.recovery.store.rate-limited:store-limited' => 'studio.recovery/rate-limited',
        'vector.host-sequence.recovery.store.wrong-operation-id:store-wrong-operation'
            => 'kumwe.producer/operation-mismatch',
    ];

    /**
     * Migrated production container shared by every replay in this process.
     *
     * @var    Container|null
     * @since  2.0.0
     */
    private static ?Container $container = null;

    /**
     * Supply every artifact and recovery single-operation vector named by the pinned testkit manifest.
     *
     * @return  iterable<string, array{stdClass}>  Vector identifier to decoded vector.
     *
     * @since   2.0.0
     */
    public static function hostVectors(): iterable
    {
        foreach (self::corpus('host-vectors', 'vectors/host/') as $id => $vector) {
            yield $id => [$vector];
        }
    }

    /**
     * Supply every artifact and recovery host-sequence vector named by the pinned testkit manifest.
     *
     * @return  iterable<string, array{stdClass}>  Vector identifier to decoded sequence.
     *
     * @since   2.0.0
     */
    public static function hostSequenceVectors(): iterable
    {
        foreach (self::corpus('host-sequence-vectors', 'vectors/host-sequence/') as $id => $vector) {
            yield $id => [$vector];
        }
    }

    /**
     * Replay one single-operation vector and prove its declared outcome plus the App's atomicity around it.
     *
     * A committed mutation leaves exactly one immutable revision row and one successful audit row whose
     * metadata discloses neither the idempotency key nor the artifact identity; a refused mutation leaves
     * neither, and the artifact head does not move.
     *
     * @param   stdClass  $vector  Decoded vendored single-operation vector.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('hostVectors')]
    public function testEveryArtifactAndRecoveryHostVectorReplays(stdClass $vector): void
    {
        $id = StudioVectorReplayRuntime::string($vector->id);
        $expect = StudioVectorReplayRuntime::object($vector->expect);
        $runtime = $this->runtime($vector);
        [$seedId, $version] = self::seedCoordinate($vector);
        $mutating = $vector->port === 'artifact'
            && in_array($vector->operation, ['publish', 'save', 'unpublish'], true);
        $before = $runtime->current($seedId, $version);
        $revisionsBefore = $runtime->revisionRows($seedId, $version);
        $auditBefore = $runtime->auditRows();

        $outcome = $runtime->invoke($vector);

        if ($expect->outcome === 'result') {
            self::assertFalse($outcome->refusal, $id . ': ' . $outcome->body);
            $this->assertResultExpectation($runtime, $vector, $expect, $outcome, $id);
            if (!$mutating) {
                return;
            }
            self::assertSame($revisionsBefore + 1, $runtime->revisionRows($seedId, $version), $id);
            self::assertSame($auditBefore + 1, $runtime->auditRows(), $id);
            $audit = $runtime->lastAudit();
            self::assertNotNull($audit, $id);
            $operation = StudioVectorReplayRuntime::string($vector->operation);
            self::assertSame('studio.artifact.' . $operation, $audit['action'], $id);
            self::assertSame('studio_artifact', $audit['subject_type'], $id);
            self::assertSame('success', $audit['outcome'], $id);
            $metadata = StudioVectorReplayRuntime::string($audit['metadata']);
            $context = StudioVectorReplayRuntime::object($vector->context);
            foreach ([$runtime->id($seedId), 'dependencyLock', 'slots'] as $secret) {
                self::assertStringNotContainsString($secret, $metadata, $id);
            }
            if (is_string($context->idempotencyKey ?? null)) {
                self::assertStringNotContainsString($context->idempotencyKey, $metadata, $id);
            }

            return;
        }

        self::assertTrue($outcome->refusal, $id . ': ' . $outcome->body);
        self::assertSame($expect->category, $outcome->category(), $id);
        self::assertSame(self::HOST_REFUSAL_CODES[$id] ?? null, $outcome->refusalCode(), $id);
        if (property_exists($expect, 'retryable')) {
            self::assertSame($expect->retryable, $outcome->retryable(), $id);
        }
        if (property_exists($expect, 'revision')) {
            self::assertSame($expect->revision, $outcome->revision(), $id);
        }
        foreach (StudioVectorReplayRuntime::strings($expect->messageMustNotContain ?? []) as $secret) {
            self::assertStringNotContainsString($secret, $outcome->body, $id);
            self::assertStringNotContainsString($runtime->id($secret), $outcome->body, $id);
        }
        if ($mutating) {
            self::assertInstanceOf(StoredStudioArtifact::class, $before, $id);
            $after = $runtime->current($seedId, $version);
            self::assertInstanceOf(StoredStudioArtifact::class, $after, $id);
            self::assertSame($before->revision, $after->revision, $id);
            self::assertSame($before->status, $after->status, $id);
            self::assertSame($revisionsBefore, $runtime->revisionRows($seedId, $version), $id);
            self::assertSame($auditBefore, $runtime->auditRows(), $id);
        }
    }

    /**
     * Replay one host-sequence vector step by step and prove its ordering, replay and atomicity semantics.
     *
     * A pending invocation is settled immediately after the settled invocation sharing its replay scope, so
     * in-flight coalescing collapses onto the completed outcome exactly as the corpus declares. Across the
     * whole sequence the audit ledger and the revision history grow by exactly the number of accepted,
     * non-replayed mutations: refusals and replays commit nothing.
     *
     * @param   stdClass  $vector  Decoded vendored host-sequence vector.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('hostSequenceVectors')]
    public function testEveryArtifactAndRecoverySequenceVectorReplays(stdClass $vector): void
    {
        $id = StudioVectorReplayRuntime::string($vector->id);
        $runtime = $this->runtime($vector);
        [$seedId, $version] = self::seedCoordinate($vector);
        $revisionsBefore = $runtime->revisionRows($seedId, $version);
        $auditBefore = $runtime->auditRows();
        /** @var array<string, StudioVectorReplayOutcome> $settled */
        $settled = [];
        /** @var array<string, stdClass> $pending */
        $pending = [];
        $accepted = 0;

        self::assertIsArray($vector->steps, $id);
        foreach ($vector->steps as $step) {
            $step = StudioVectorReplayRuntime::object($step);
            $stepId = StudioVectorReplayRuntime::string($step->id);
            $label = $id . ':' . $stepId;
            $action = StudioVectorReplayRuntime::string($step->action);
            if ($action === 'advance-clock') {
                self::assertIsInt($step->milliseconds, $label);
                $runtime->clock->advance($step->milliseconds);
                continue;
            }
            if ($action === 'settle') {
                $source = $settled[StudioVectorReplayRuntime::string($step->invocation)] ?? null;
                self::assertInstanceOf(StudioVectorReplayOutcome::class, $source, $label);
                $this->assertStepExpectation(
                    $runtime,
                    $vector,
                    $label,
                    StudioVectorReplayRuntime::object($step->expect),
                    $source,
                    $settled,
                );
                $settled[$stepId] = $source;
                continue;
            }
            self::assertSame('invoke', $action, $label);
            if (($step->completion ?? 'settled') === 'pending') {
                $pending[$stepId] = $step;
                continue;
            }
            $outcome = $runtime->invoke($step);
            $settled[$stepId] = $outcome;
            $expect = StudioVectorReplayRuntime::object($step->expect);
            $this->assertStepExpectation($runtime, $vector, $label, $expect, $outcome, $settled);
            if ($expect->outcome === 'result' && !property_exists($expect, 'sameAs')) {
                $accepted++;
            }
            foreach ($pending as $pendingId => $pendingStep) {
                if (!self::sameReplayScope($pendingStep, $step)) {
                    continue;
                }
                $replay = $runtime->invoke($pendingStep);
                self::assertSame($outcome->body, $replay->body, $id . ':' . $pendingId);
                $settled[$pendingId] = $replay;
                unset($pending[$pendingId]);
            }
        }
        self::assertSame([], $pending, $id . ' must settle every in-flight invocation.');

        $final = StudioVectorReplayRuntime::object($vector->expectFinal);
        foreach (StudioVectorReplayRuntime::objects($final->artifacts ?? []) as $artifact) {
            $head = $runtime->current(StudioVectorReplayRuntime::string($artifact->id), $version);
            self::assertInstanceOf(StoredStudioArtifact::class, $head, $id);
            self::assertSame($artifact->status, $head->status, $id);
            $origin = $settled[StudioVectorReplayRuntime::string($artifact->revisionFrom)] ?? null;
            self::assertInstanceOf(StudioVectorReplayOutcome::class, $origin, $id);
            self::assertSame($origin->revision(), $head->revision, $id);
        }
        foreach (StudioVectorReplayRuntime::objects($final->recovery ?? []) as $entry) {
            self::assertSame(
                CanonicalJson::stringify($entry->value),
                $runtime->envelope(StudioVectorReplayRuntime::string($entry->resourceContextKey)),
                $id,
            );
        }
        self::assertSame($auditBefore + $accepted, $runtime->auditRows(), $id);
        self::assertSame($revisionsBefore + ($vector->given->artifacts === [] ? 0 : $accepted), $runtime->revisionRows(
            $seedId,
            $version,
        ), $id);
    }

    /**
     * Prove save permission cannot perform lifecycle transitions or edit a published head.
     *
     * Canonical unpublish remains the only path that returns a published artifact to a saveable draft, and
     * every refused save leaves the head, its history and the audit ledger untouched.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSaveCannotBypassLifecycleOperationsAndRequiresCanonicalUnpublish(): void
    {
        $vector = self::vector('vectors/host/artifact.save.accepted.json');
        $context = StudioVectorReplayRuntime::object($vector->context);
        $runtime = $this->runtime($vector);
        $candidate = $runtime->current('vector.blueprint', '1.0.0')?->document();
        self::assertInstanceOf(stdClass::class, $candidate);
        $candidate->status = 'published';
        $auditBefore = $runtime->auditRows();

        $this->assertRefusal(
            $runtime->saveDocument($context, $candidate),
            'conflict',
            'studio.artifact/lifecycle-change-requires-publish',
            'vector.blueprint-r1',
            'save cannot publish',
        );
        self::assertSame('draft', $runtime->current('vector.blueprint', '1.0.0')?->status);
        self::assertSame('vector.blueprint-r1', $runtime->current('vector.blueprint', '1.0.0')?->revision);
        self::assertSame(1, $runtime->revisionRows('vector.blueprint', '1.0.0'));
        self::assertSame($auditBefore, $runtime->auditRows());

        $vector = self::vector('vectors/host/artifact.save.accepted.json');
        $seed = StudioVectorReplayRuntime::objects($vector->given->artifacts)[0];
        $seed->status = 'published';
        $runtime = $this->runtime($vector);
        $candidate = $runtime->current('vector.blueprint', '1.0.0')?->document();
        self::assertInstanceOf(stdClass::class, $candidate);
        $auditBefore = $runtime->auditRows();

        $this->assertRefusal(
            $runtime->saveDocument($context, $candidate),
            'conflict',
            'studio.artifact/not-draft',
            'vector.blueprint-r1',
            'published head refuses save',
        );
        self::assertSame($auditBefore, $runtime->auditRows());

        $unpublish = clone $context;
        $unpublish->operationId = 'studio.operation/artifact.unpublish';
        $unpublish->requestId = 'requests/unpublish-before-save';
        $unpublish->idempotencyKey = 'idempotency/unpublish-before-save';
        $unpublished = $runtime->invoke(self::step(
            'artifact',
            'unpublish',
            $unpublish,
            (object) ['id' => 'vector.blueprint', 'version' => '1.0.0'],
        ));
        self::assertFalse($unpublished->refusal, $unpublished->body);
        self::assertNotNull($unpublished->revision());
        self::assertSame('draft', $runtime->current('vector.blueprint', '1.0.0')?->status);

        $candidate = $runtime->current('vector.blueprint', '1.0.0')?->document();
        self::assertInstanceOf(stdClass::class, $candidate);
        self::assertInstanceOf(stdClass::class, $candidate->label);
        $candidate->label->defaultMessage = 'Saved only after canonical unpublish';
        $save = clone $context;
        $save->requestId = 'requests/save-after-unpublish';
        $save->expectedRevision = $unpublished->revision();
        $save->idempotencyKey = 'idempotency/save-after-unpublish';
        $saved = $runtime->saveDocument($save, $candidate);

        self::assertFalse($saved->refusal, $saved->body);
        self::assertNotNull($saved->revision());
        self::assertNotSame($unpublished->revision(), $saved->revision());
        self::assertSame('draft', $runtime->current('vector.blueprint', '1.0.0')?->status);
        self::assertSame($saved->revision(), $runtime->current('vector.blueprint', '1.0.0')?->revision);
        self::assertSame(3, $runtime->revisionRows('vector.blueprint', '1.0.0'));
        self::assertSame($auditBefore + 2, $runtime->auditRows());
    }

    /**
     * Publish and unpublish consume only their exact target-specific live authority.
     *
     * Holding `content.publish` never authorizes the return to draft and holding `content.unpublish` never
     * authorizes publication; each refusal is taken at the authorization stage before the port runs, so
     * the head and the audit ledger stay exactly as the accepted transition left them.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLifecycleMutationsCannotBorrowTheOppositeTargetAuthority(): void
    {
        $vector = self::vector('vectors/host/artifact.publish.accepted.json');
        $context = StudioVectorReplayRuntime::object($vector->context);
        $reference = (object) ['id' => 'vector.blueprint', 'version' => '1.0.0'];
        $publishOnly = $this->runtime($vector, canPublish: true, canUnpublish: false);
        $auditBefore = $publishOnly->auditRows();

        $published = $publishOnly->invoke($vector);
        self::assertFalse($published->refusal, $published->body);
        $publishedHead = $publishOnly->current('vector.blueprint', '1.0.0');
        self::assertInstanceOf(StoredStudioArtifact::class, $publishedHead);
        self::assertSame('published', $publishedHead->status);
        self::assertSame($published->revision(), $publishedHead->revision);

        $unpublish = clone $context;
        $unpublish->operationId = 'studio.operation/artifact.unpublish';
        $unpublish->requestId = 'requests/unpublish-with-publish-only';
        $unpublish->idempotencyKey = 'idempotency/unpublish-with-publish-only';
        $unpublish->expectedRevision = $published->revision();
        $this->assertRefusal(
            $publishOnly->invoke(self::step('artifact', 'unpublish', $unpublish, $reference)),
            'forbidden',
            'studio.host/session-refused',
            null,
            'publication authority must not authorize return to draft',
        );
        $afterRefusedUnpublish = $publishOnly->current('vector.blueprint', '1.0.0');
        self::assertInstanceOf(StoredStudioArtifact::class, $afterRefusedUnpublish);
        self::assertSame($publishedHead->revision, $afterRefusedUnpublish->revision);
        self::assertSame($publishedHead->status, $afterRefusedUnpublish->status);
        self::assertSame($auditBefore + 1, $publishOnly->auditRows());

        $vector = self::vector('vectors/host/artifact.publish.accepted.json');
        $seed = StudioVectorReplayRuntime::objects($vector->given->artifacts)[0];
        $seed->status = 'published';
        $unpublishOnly = $this->runtime($vector, canPublish: false, canUnpublish: true);
        $auditBefore = $unpublishOnly->auditRows();
        $unpublish = clone $context;
        $unpublish->operationId = 'studio.operation/artifact.unpublish';
        $unpublish->requestId = 'requests/unpublish-with-unpublish-only';
        $unpublish->idempotencyKey = 'idempotency/unpublish-with-unpublish-only';
        $unpublished = $unpublishOnly->invoke(self::step('artifact', 'unpublish', $unpublish, $reference));
        self::assertFalse($unpublished->refusal, $unpublished->body);
        $draftHead = $unpublishOnly->current('vector.blueprint', '1.0.0');
        self::assertInstanceOf(StoredStudioArtifact::class, $draftHead);
        self::assertSame('draft', $draftHead->status);
        self::assertSame($unpublished->revision(), $draftHead->revision);

        $publish = clone $context;
        $publish->requestId = 'requests/publish-with-unpublish-only';
        $publish->idempotencyKey = 'idempotency/publish-with-unpublish-only';
        $publish->expectedRevision = $unpublished->revision();
        $this->assertRefusal(
            $unpublishOnly->invoke(self::step('artifact', 'publish', $publish, $reference)),
            'forbidden',
            'studio.host/session-refused',
            null,
            'return-to-draft authority must not authorize publication',
        );
        $afterRefusedPublish = $unpublishOnly->current('vector.blueprint', '1.0.0');
        self::assertInstanceOf(StoredStudioArtifact::class, $afterRefusedPublish);
        self::assertSame($draftHead->revision, $afterRefusedPublish->revision);
        self::assertSame($draftHead->status, $afterRefusedPublish->status);
        self::assertSame($auditBefore + 1, $unpublishOnly->auditRows());
    }

    /**
     * Prove App-owned publication crosses the live composition guard and rolls back every refusal.
     *
     * The container's `StudioPublishedCompositionGuard` is consulted with the exact site model, theme and
     * renderer authorities: a model lock outside the published Content model, a theme lock that drifted
     * from the live public theme, and a block lock without a live renderer each refuse as a conflict that
     * names the safe current revision, and none of them appends a revision or an audit row.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAppOwnedBlueprintPublicationCrossesTheLivePublicationGuard(): void
    {
        $container = self::container();
        $models = StudioVectorReplayRuntime::service($container, ContentModelRepository::class);
        $types = $models->contentTypes(SiteContext::default());
        self::assertNotSame([], $types, 'The integration site must publish at least one content type.');
        $type = $types[0];
        self::assertNotNull($models->contentType(SiteContext::default(), $type->id, $type->version));
        $modelLock = (object) [
            'id' => ContentStudioProjector::modelId($type->id),
            'version' => ContentStudioProjector::modelVersion($type->version),
            'revision' => ContentStudioProjector::modelRevision($type->version),
        ];
        $themeLock = StudioVectorReplayRuntime::service($container, StudioPublishedTheme::class)
            ->reference(SiteContext::default())
            ->document();
        $owner = (object) ['id' => 'kumwe.app/content', 'version' => '2.0.0'];
        $cases = [
            'studio.artifact/model-lock-mismatch' => static function (stdClass $document) use ($owner): void {
                $document->owner = clone $owner;
            },
            'studio.artifact/theme-lock-mismatch' => static function (stdClass $document) use (
                $owner,
                $modelLock,
            ): void {
                $document->owner = clone $owner;
                $document->model = clone $modelLock;
            },
            'studio.artifact/block-renderer-unavailable' => static function (stdClass $document) use (
                $owner,
                $modelLock,
                $themeLock,
            ): void {
                $document->owner = clone $owner;
                $document->model = clone $modelLock;
                self::assertInstanceOf(stdClass::class, $document->dependencyLock);
                $document->dependencyLock->theme = clone $themeLock;
            },
        ];

        foreach ($cases as $code => $prepare) {
            $vector = self::vector('vectors/host/artifact.publish.accepted.json');
            $runtime = $this->runtime($vector, prepare: $prepare);
            $before = $runtime->current('vector.blueprint', '1.0.0');
            self::assertInstanceOf(StoredStudioArtifact::class, $before, $code);
            $auditBefore = $runtime->auditRows();

            $this->assertRefusal($runtime->invoke($vector), 'conflict', $code, $before->revision, $code);

            $after = $runtime->current('vector.blueprint', '1.0.0');
            self::assertInstanceOf(StoredStudioArtifact::class, $after, $code);
            self::assertSame($before->revision, $after->revision, $code);
            self::assertSame($before->status, $after->status, $code);
            self::assertSame($before->canonicalDocument, $after->canonicalDocument, $code);
            self::assertSame(1, $runtime->revisionRows('vector.blueprint', '1.0.0'), $code);
            self::assertSame($auditBefore, $runtime->auditRows(), $code);
        }
    }

    /**
     * Prove a Blueprint save cannot drift its owner, model or dependency locks.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBlueprintSaveRejectsEveryImmutableLockDrift(): void
    {
        foreach (['owner', 'model', 'dependency-lock'] as $lock) {
            $vector = self::vector('vectors/host/artifact.save.accepted.json');
            $context = StudioVectorReplayRuntime::object($vector->context);
            $runtime = $this->runtime($vector);
            $before = $runtime->current('vector.blueprint', '1.0.0');
            self::assertInstanceOf(StoredStudioArtifact::class, $before, $lock);
            $auditBefore = $runtime->auditRows();
            $candidate = $before->document();
            self::assertInstanceOf(stdClass::class, $candidate->owner, $lock);
            self::assertInstanceOf(stdClass::class, $candidate->model, $lock);
            self::assertInstanceOf(stdClass::class, $candidate->dependencyLock, $lock);
            if ($lock === 'owner') {
                $candidate->owner->version = '2.0.0';
            } elseif ($lock === 'model') {
                $candidate->model->revision = 'product-model-r2';
            } else {
                self::assertInstanceOf(stdClass::class, $candidate->dependencyLock->theme, $lock);
                $candidate->dependencyLock->theme->revision = 'commerce-theme-r4';
            }

            $this->assertRefusal(
                $runtime->saveDocument($context, $candidate),
                'conflict',
                'studio.artifact/blueprint-lock-conflict',
                'vector.blueprint-r1',
                $lock,
            );

            $after = $runtime->current('vector.blueprint', '1.0.0');
            self::assertInstanceOf(StoredStudioArtifact::class, $after, $lock);
            self::assertSame($before->canonicalDocument, $after->canonicalDocument, $lock);
            self::assertSame($before->canonicalDependencies, $after->canonicalDependencies, $lock);
            self::assertSame(1, $runtime->revisionRows('vector.blueprint', '1.0.0'), $lock);
            self::assertSame($auditBefore, $runtime->auditRows(), $lock);
        }
    }

    /**
     * Prove a save cannot create a new version coordinate or cross its trusted resource identifier.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSaveCannotEscapeItsExistingResourceCoordinate(): void
    {
        $vector = self::vector('vectors/host/artifact.save.accepted.json');
        $context = StudioVectorReplayRuntime::object($vector->context);
        $runtime = $this->runtime($vector);
        $auditBefore = $runtime->auditRows();
        $candidate = $runtime->current('vector.blueprint', '1.0.0')?->document();
        self::assertInstanceOf(stdClass::class, $candidate);
        $candidate->version = '2.0.0';

        $this->assertRefusal(
            $runtime->saveDocument($context, $candidate),
            'not-found',
            'studio.artifact/not-found',
            null,
            'save must not create a new version coordinate',
        );
        self::assertNull($runtime->current('vector.blueprint', '2.0.0'));
        self::assertSame(0, $runtime->revisionRows('vector.blueprint', '2.0.0'));

        $candidate = $runtime->current('vector.blueprint', '1.0.0')?->document();
        self::assertInstanceOf(stdClass::class, $candidate);
        $candidate->id = $runtime->id('vector.other-blueprint');

        $this->assertRefusal(
            $runtime->saveDocument($context, $candidate),
            'not-found',
            'studio.artifact/not-found',
            null,
            'save must not cross its trusted resource identifier',
        );
        self::assertNull($runtime->current('vector.other-blueprint', '1.0.0'));
        self::assertSame('vector.blueprint-r1', $runtime->current('vector.blueprint', '1.0.0')?->revision);
        self::assertSame(1, $runtime->revisionRows('vector.blueprint', '1.0.0'));
        self::assertSame($auditBefore, $runtime->auditRows());
    }

    /**
     * Assert one step's declared outcome against the actual dispatcher response.
     *
     * @param   StudioVectorReplayRuntime                 $runtime  Runtime the step ran in.
     * @param   stdClass                                  $vector   Decoded vendored sequence.
     * @param   string                                    $label    Vector and step identifier for messages.
     * @param   stdClass                                  $expect   Declared step expectation.
     * @param   StudioVectorReplayOutcome                 $outcome  Actual response for the step.
     * @param   array<string, StudioVectorReplayOutcome>  $settled  Outcomes settled so far, for `sameAs`.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertStepExpectation(
        StudioVectorReplayRuntime $runtime,
        stdClass $vector,
        string $label,
        stdClass $expect,
        StudioVectorReplayOutcome $outcome,
        array $settled,
    ): void {
        if ($expect->outcome === 'result') {
            self::assertFalse($outcome->refusal, $label . ': ' . $outcome->body);
            if (property_exists($expect, 'sameAs')) {
                $source = $settled[StudioVectorReplayRuntime::string($expect->sameAs)] ?? null;
                self::assertInstanceOf(StudioVectorReplayOutcome::class, $source, $label);
                self::assertSame($source->body, $outcome->body, $label);
            }
            $this->assertResultExpectation($runtime, $vector, $expect, $outcome, $label);

            return;
        }

        self::assertTrue($outcome->refusal, $label . ': ' . $outcome->body);
        self::assertSame($expect->category, $outcome->category(), $label);
        self::assertSame(self::SEQUENCE_REFUSAL_CODES[$label] ?? null, $outcome->refusalCode(), $label);
        if (property_exists($expect, 'retryable')) {
            self::assertSame($expect->retryable, $outcome->retryable(), $label);
        }
        if (property_exists($expect, 'retryAfterMilliseconds')) {
            self::assertSame($expect->retryAfterMilliseconds, $outcome->retryAfterMilliseconds(), $label);
        }
    }

    /**
     * Assert only the result fields the corpus declares, resolving artifact values against real storage.
     *
     * @param   StudioVectorReplayRuntime  $runtime  Runtime whose storage holds the seeded artifact.
     * @param   stdClass                   $vector   Decoded vendored vector or sequence.
     * @param   stdClass                   $expect   Declared result expectation.
     * @param   StudioVectorReplayOutcome  $outcome  Actual successful response.
     * @param   string                     $label    Identifier for assertion messages.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertResultExpectation(
        StudioVectorReplayRuntime $runtime,
        stdClass $vector,
        stdClass $expect,
        StudioVectorReplayOutcome $outcome,
        string $label,
    ): void {
        [$seedId, $version] = self::seedCoordinate($vector);
        $head = $runtime->current($seedId, $version);
        $value = $expect->value ?? null;
        if ($value === 'null') {
            self::assertNull($outcome->value(), $label);
        } elseif ($value === 'artifact') {
            self::assertInstanceOf(StoredStudioArtifact::class, $head, $label);
            $document = $outcome->value();
            self::assertInstanceOf(stdClass::class, $document, $label);
            self::assertSame($runtime->id($seedId), $document->id, $label);
            self::assertEquals($head->document(), $document, $label);
        } elseif ($value === 'artifact-references') {
            self::assertInstanceOf(StoredStudioArtifact::class, $head, $label);
            self::assertEquals($head->dependencies(), $outcome->value(), $label);
        }
        if (property_exists($expect, 'revision')) {
            self::assertSame($expect->revision, $outcome->revision(), $label);
        }
        if (($expect->revisionAdvances ?? false) === true) {
            self::assertInstanceOf(StoredStudioArtifact::class, $head, $label);
            self::assertNotNull($outcome->revision(), $label);
            self::assertNotSame(self::seedRevision($vector), $outcome->revision(), $label);
            self::assertSame($head->revision, $outcome->revision(), $label);
        }
        if (property_exists($expect, 'revisionAdvancesFrom')) {
            self::assertNotNull($outcome->revision(), $label);
            self::assertNotSame($expect->revisionAdvancesFrom, $outcome->revision(), $label);
        }
    }

    /**
     * Assert one dispatcher response is the exact App refusal named by category, code and safe revision.
     *
     * @param   StudioVectorReplayOutcome  $outcome   Actual dispatcher response.
     * @param   string                     $category  Expected closed Producer refusal category.
     * @param   string                     $code      Expected stable App diagnostic code.
     * @param   string|null                $revision  Expected safe current revision, or null when none.
     * @param   string                     $label     Identifier for assertion messages.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertRefusal(
        StudioVectorReplayOutcome $outcome,
        string $category,
        string $code,
        ?string $revision,
        string $label,
    ): void {
        self::assertTrue($outcome->refusal, $label . ': ' . $outcome->body);
        self::assertSame($category, $outcome->category(), $label);
        self::assertSame($code, $outcome->refusalCode(), $label);
        self::assertSame($revision, $outcome->revision(), $label);
    }

    /**
     * Build one isolated replay runtime over the shared container from a vector's `given` state.
     *
     * @param   stdClass      $vector        Decoded vendored vector or sequence.
     * @param   bool|null     $canPublish    Optional exact publication authority override.
     * @param   bool|null     $canUnpublish  Optional exact return-to-draft authority override.
     * @param   Closure|null  $prepare       Optional seed transformer applied before admission.
     *
     * @return  StudioVectorReplayRuntime  Seeded runtime with its own session scope and identifiers.
     *
     * @since   2.0.0
     */
    private function runtime(
        stdClass $vector,
        ?bool $canPublish = null,
        ?bool $canUnpublish = null,
        ?Closure $prepare = null,
    ): StudioVectorReplayRuntime {
        return new StudioVectorReplayRuntime(
            self::container(),
            StudioVectorReplayRuntime::object($vector->given),
            $canPublish,
            $canUnpublish,
            self::withholdsSave($vector),
            $prepare,
        );
    }

    /**
     * Boot the migrated production container once for every replay in this process.
     *
     * @return  Container  Shared container over the integration database.
     *
     * @since   2.0.0
     */
    private static function container(): Container
    {
        return self::$container ??= TestKernelFactory::create(Environment::fromGlobals());
    }

    /**
     * Name the seeded artifact coordinate a vector's expectations resolve against.
     *
     * @param   stdClass  $vector  Decoded vendored vector or sequence.
     *
     * @return  array{string, string}  Vector-local artifact identifier and version.
     *
     * @since   2.0.0
     */
    private static function seedCoordinate(stdClass $vector): array
    {
        $given = StudioVectorReplayRuntime::object($vector->given);
        $seeds = StudioVectorReplayRuntime::objects($given->artifacts);
        $seed = $seeds[0] ?? null;
        if ($seed === null) {
            return ['vector.absent', '1.0.0'];
        }
        $version = is_string($seed->version ?? null) ? $seed->version : '1.0.0';

        return [StudioVectorReplayRuntime::string($seed->id), $version];
    }

    /**
     * Read the revision a vector seeded its first artifact at.
     *
     * @param   stdClass  $vector  Decoded vendored vector or sequence.
     *
     * @return  string  Seeded revision.
     *
     * @since   2.0.0
     */
    private static function seedRevision(stdClass $vector): string
    {
        $given = StudioVectorReplayRuntime::object($vector->given);
        $seed = StudioVectorReplayRuntime::objects($given->artifacts)[0] ?? null;
        self::assertInstanceOf(stdClass::class, $seed);

        return StudioVectorReplayRuntime::string($seed->revision);
    }

    /**
     * Decide whether a vector withholds the save permission from the save operation it exercises.
     *
     * The App grants save with every authoring mode, so only the read-only mode can withhold it; that mode
     * also withholds publish, which is why the choice is made per exercised operation and not per vector.
     *
     * @param   stdClass  $vector  Decoded vendored vector or sequence.
     *
     * @return  bool  True when save is absent from a non-empty permission list and a save is exercised.
     *
     * @since   2.0.0
     */
    private static function withholdsSave(stdClass $vector): bool
    {
        $given = StudioVectorReplayRuntime::object($vector->given);
        $permissions = StudioVectorReplayRuntime::strings($given->permissions);
        if ($permissions === [] || in_array('studio.permission/save', $permissions, true)) {
            return false;
        }
        $operations = [$vector->operation ?? null];
        foreach (StudioVectorReplayRuntime::objects($vector->steps ?? []) as $step) {
            $operations[] = $step->operation ?? null;
        }

        return in_array('save', $operations, true);
    }

    /**
     * Decide whether a pending invocation shares the replay scope of a settled invocation.
     *
     * @param   stdClass  $pending  Deferred pending step.
     * @param   stdClass  $settled  Step that just settled.
     *
     * @return  bool  True when port, operation, resource context and idempotency key all match.
     *
     * @since   2.0.0
     */
    private static function sameReplayScope(stdClass $pending, stdClass $settled): bool
    {
        $pendingContext = StudioVectorReplayRuntime::object($pending->context);
        $settledContext = StudioVectorReplayRuntime::object($settled->context);

        return $pending->port === $settled->port
            && $pending->operation === $settled->operation
            && $pendingContext->resourceContextKey === $settledContext->resourceContextKey
            && ($pendingContext->idempotencyKey ?? null) === ($settledContext->idempotencyKey ?? null);
    }

    /**
     * Build one semantic vector step for a hand-written follow-up invocation.
     *
     * @param   string    $port       Canonical port name.
     * @param   string    $operation  Canonical operation name.
     * @param   stdClass  $context    Vector-shaped request context.
     * @param   mixed     $argument   Vector-shaped semantic argument.
     *
     * @return  stdClass  Step the runtime can invoke exactly like a corpus step.
     *
     * @since   2.0.0
     */
    private static function step(string $port, string $operation, stdClass $context, mixed $argument): stdClass
    {
        return (object) [
            'port' => $port,
            'operation' => $operation,
            'context' => $context,
            'argument' => $argument,
        ];
    }

    /**
     * Enumerate the artifact and recovery members of one manifest group in manifest order.
     *
     * @param   string  $group   Manifest group name.
     * @param   string  $prefix  Manifest-relative directory of the group's files.
     *
     * @return  iterable<string, stdClass>  Vector identifier to decoded vector.
     *
     * @since   2.0.0
     */
    private static function corpus(string $group, string $prefix): iterable
    {
        $manifest = StudioVectorReplayRuntime::decode(StudioContractResources::testkitManifestBytes());
        foreach (StudioVectorReplayRuntime::objects($manifest->groups) as $candidate) {
            if ($candidate->group !== $group) {
                continue;
            }
            foreach (StudioVectorReplayRuntime::objects($candidate->files) as $entry) {
                $file = StudioVectorReplayRuntime::string($entry->file);
                if (!str_starts_with($file, 'artifact.') && !str_starts_with($file, 'recovery.')) {
                    continue;
                }
                $vector = self::vector($prefix . $file);
                yield StudioVectorReplayRuntime::string($vector->id) => $vector;
            }
        }
    }

    /**
     * Decode one exact vendored testkit vector by its manifest-relative path.
     *
     * @param   string  $relative  Manifest-relative vector path.
     *
     * @return  stdClass  Decoded vector document.
     *
     * @since   2.0.0
     */
    private static function vector(string $relative): stdClass
    {
        return StudioVectorReplayRuntime::decode(StudioContractResources::testkitBytes($relative));
    }
}

/**
 * Real-container vector harness translating only the published HTTP wrapper and live session coordinates.
 *
 * @since  2.0.0
 */
final class StudioVectorReplayRuntime
{
    /**
     * Deterministic clock driven only by `advance-clock` sequence actions.
     *
     * @var    StudioVectorReplayClock
     * @since  2.0.0
     */
    public readonly StudioVectorReplayClock $clock;

    /**
     * Per-run suffix that keeps every seeded identifier unique on a reused database.
     *
     * @var    string
     * @since  2.0.0
     */
    public readonly string $token;

    /**
     * Shared integration database connection.
     *
     * @var    Connection
     * @since  2.0.0
     */
    private readonly Connection $database;

    /**
     * Installation table-name compiler.
     *
     * @var    TableNames
     * @since  2.0.0
     */
    private readonly TableNames $tables;

    /**
     * Authoritative App transaction manager.
     *
     * @var    TransactionManager
     * @since  2.0.0
     */
    private readonly TransactionManager $transactions;

    /**
     * Live transaction state the mutation boundary consults before it opens its own transaction.
     *
     * @var    TransactionState
     * @since  2.0.0
     */
    private readonly TransactionState $transactionState;

    /**
     * Container artifact persistence.
     *
     * @var    StudioArtifactRepository
     * @since  2.0.0
     */
    private readonly StudioArtifactRepository $artifacts;

    /**
     * Container recovery persistence.
     *
     * @var    StudioRecoveryRepository
     * @since  2.0.0
     */
    private readonly StudioRecoveryRepository $recovery;

    /**
     * Container keyed replay store.
     *
     * @var    StudioMutationReplayRepository
     * @since  2.0.0
     */
    private readonly StudioMutationReplayRepository $replays;

    /**
     * Container authenticated outcome codec.
     *
     * @var    StudioMutationOutcomeCodec
     * @since  2.0.0
     */
    private readonly StudioMutationOutcomeCodec $outcomes;

    /**
     * Container transactional audit sink.
     *
     * @var    AuditRecorder
     * @since  2.0.0
     */
    private readonly AuditRecorder $audit;

    /**
     * Container media use case the mutation boundary rehydrates upload grants through.
     *
     * @var    StudioMediaOperations
     * @since  2.0.0
     */
    private readonly StudioMediaOperations $media;

    /**
     * Container schema and active-content admission boundary.
     *
     * @var    StudioArtifactAdmission
     * @since  2.0.0
     */
    private readonly StudioArtifactAdmission $admission;

    /**
     * Container artifact port prototype, bound per request.
     *
     * @var    StudioArtifactHostPort
     * @since  2.0.0
     */
    private readonly StudioArtifactHostPort $artifactPort;

    /**
     * Recovery port over the container repository, the vector clock and the vector's rate limit.
     *
     * @var    StudioRecoveryHostPort
     * @since  2.0.0
     */
    private readonly StudioRecoveryHostPort $recoveryPort;

    /**
     * Container localization port prototype.
     *
     * @var    StudioLocalizationHostPort
     * @since  2.0.0
     */
    private readonly StudioLocalizationHostPort $localizationPort;

    /**
     * Container media port prototype.
     *
     * @var    StudioMediaHostPort
     * @since  2.0.0
     */
    private readonly StudioMediaHostPort $mediaPort;

    /**
     * Container model port prototype.
     *
     * @var    StudioModelHostPort
     * @since  2.0.0
     */
    private readonly StudioModelHostPort $modelPort;

    /**
     * Container preview port prototype.
     *
     * @var    StudioPreviewHostPort
     * @since  2.0.0
     */
    private readonly StudioPreviewHostPort $previewPort;

    /**
     * Container resource port prototype.
     *
     * @var    StudioResourceHostPort
     * @since  2.0.0
     */
    private readonly StudioResourceHostPort $resourcePort;

    /**
     * Container telemetry port prototype.
     *
     * @var    StudioTelemetryHostPort
     * @since  2.0.0
     */
    private readonly StudioTelemetryHostPort $telemetryPort;

    /**
     * Production session authority over an in-memory binding store.
     *
     * @var    StudioHostSessionAuthority
     * @since  2.0.0
     */
    private readonly StudioHostSessionAuthority $sessions;

    /**
     * Administrator execution context carrying the capabilities derived from the vector's permissions.
     *
     * @var    ExecutionContext
     * @since  2.0.0
     */
    private readonly ExecutionContext $context;

    /**
     * Exact authoring mode the vector's permissions resolve to.
     *
     * @var    StudioSessionMode
     * @since  2.0.0
     */
    private readonly StudioSessionMode $mode;

    /**
     * Host resource family of the seeded artifact.
     *
     * @var    StudioResourceKind
     * @since  2.0.0
     */
    private readonly StudioResourceKind $kind;

    /**
     * Trusted resource identifier every session in this runtime binds.
     *
     * @var    string
     * @since  2.0.0
     */
    private readonly string $resourceId;

    /**
     * Session generation the vector declares current.
     *
     * @var    string
     * @since  2.0.0
     */
    private readonly string $generation;

    /**
     * Live sessions keyed by the vector's own resource context key.
     *
     * @var    array<string, StudioHostSessionSnapshot>
     * @since  2.0.0
     */
    private array $snapshots = [];

    /**
     * Compose the runtime from the container and seed the vector's given artifacts.
     *
     * @param  Container     $container      Migrated production container.
     * @param  stdClass      $given          Vector `given` state.
     * @param  bool|null     $canPublish     Optional exact publication authority override.
     * @param  bool|null     $canUnpublish   Optional exact return-to-draft authority override.
     * @param  bool          $withholdsSave  Whether the session opens read-only so save is withheld.
     * @param  Closure|null  $prepare        Optional seed transformer applied before admission.
     *
     * @since  2.0.0
     */
    public function __construct(
        Container $container,
        stdClass $given,
        ?bool $canPublish = null,
        ?bool $canUnpublish = null,
        bool $withholdsSave = false,
        ?Closure $prepare = null,
    ) {
        $this->token = bin2hex(random_bytes(6));
        $this->clock = new StudioVectorReplayClock();
        $this->database = self::service($container, Connection::class);
        $this->tables = self::service($container, TableNames::class);
        $this->transactions = self::service($container, TransactionManager::class);
        $this->transactionState = self::service($container, TransactionState::class);
        $this->artifacts = self::service($container, StudioArtifactRepository::class);
        $this->recovery = self::service($container, StudioRecoveryRepository::class);
        $this->replays = self::service($container, StudioMutationReplayRepository::class);
        $this->outcomes = self::service($container, StudioMutationOutcomeCodec::class);
        $this->audit = self::service($container, AuditRecorder::class);
        $this->media = self::service($container, StudioMediaOperations::class);
        $this->admission = self::service($container, StudioArtifactAdmission::class);
        $this->artifactPort = self::service($container, StudioArtifactHostPort::class);
        $this->localizationPort = self::service($container, StudioLocalizationHostPort::class);
        $this->mediaPort = self::service($container, StudioMediaHostPort::class);
        $this->modelPort = self::service($container, StudioModelHostPort::class);
        $this->previewPort = self::service($container, StudioPreviewHostPort::class);
        $this->resourcePort = self::service($container, StudioResourceHostPort::class);
        $this->telemetryPort = self::service($container, StudioTelemetryHostPort::class);
        [$maximumWrites, $window] = self::rateLimit($given, 'studio.operation/recovery.store');
        $this->recoveryPort = new StudioRecoveryHostPort(
            $this->recovery,
            $this->clock,
            262144,
            $maximumWrites,
            $window,
        );

        $seeds = self::objects($given->artifacts);
        $seedKind = isset($seeds[0]) ? self::string($seeds[0]->kind) : 'blueprint';
        $permissions = self::strings($given->permissions);
        $hasPublish = in_array('studio.permission/publish', $permissions, true);
        $this->kind = $seedKind === 'blueprint' ? StudioResourceKind::Blueprint : StudioResourceKind::Content;
        $this->mode = $withholdsSave
            ? StudioSessionMode::ReadOnly
            : match ($seedKind) {
                'blueprint' => StudioSessionMode::Blueprint,
                'content-model' => StudioSessionMode::Model,
                default => StudioSessionMode::Content,
            };
        $capabilities = ['content.read', $this->mode->capability()];
        if ($canPublish ?? $hasPublish) {
            $capabilities[] = 'content.publish';
        }
        if ($canUnpublish ?? $hasPublish) {
            $capabilities[] = 'content.unpublish';
        }
        $this->context = AuthenticatedPrincipal::issueFromStrings(
            AuthorizationContext::provenance(),
            AuthorizationContext::SUBJECT,
            $capabilities,
        )->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'studio-vector-' . $this->token,
            surface: AuthenticatedSurface::Administrator,
            sessionId: 'administrator-vector-' . $this->token,
        );
        $this->sessions = new StudioHostSessionAuthority(
            AuthorizationContext::gateway(),
            $this->sessionRepository(),
            $this->keyFactory(),
            self::service($container, StudioPublishedTheme::class),
        );
        $this->generation = self::string($given->sessionGeneration);
        $this->resourceId = isset($seeds[0])
            ? $this->id(self::string($seeds[0]->id))
            : 'recovery-resource.' . $this->token;
        foreach ($seeds as $seed) {
            $this->seed($seed, $prepare);
        }
    }

    /**
     * Invoke one semantic vector step through Producer's dispatcher and the App's request-scoped host.
     *
     * @param   stdClass  $step  Vector step or vector carrying `port`, `operation`, `context` and `argument`.
     *
     * @return  StudioVectorReplayOutcome  Decoded canonical response.
     *
     * @since   2.0.0
     */
    public function invoke(stdClass $step): StudioVectorReplayOutcome
    {
        $port = self::string($step->port);
        $operation = self::string($step->operation);
        $context = self::object($step->context);
        $argument = property_exists($step, 'argument') ? $step->argument : null;

        return $this->dispatch($port, $operation, $context, $this->wrapper($port, $operation, $argument, $context));
    }

    /**
     * Save one exact candidate document through the published `document` wrapper.
     *
     * @param   stdClass  $context   Vector-shaped request context.
     * @param   stdClass  $document  Exact candidate Studio document, already carrying its live identifier.
     *
     * @return  StudioVectorReplayOutcome  Decoded canonical response.
     *
     * @since   2.0.0
     */
    public function saveDocument(stdClass $context, stdClass $document): StudioVectorReplayOutcome
    {
        return $this->dispatch('artifact', 'save', $context, (object) ['document' => $document]);
    }

    /**
     * Read the current stored head of a vector-local artifact identifier from the container repository.
     *
     * @param   string  $vectorId  Artifact identifier as the vector spells it.
     * @param   string  $version   Canonical artifact version.
     *
     * @return  StoredStudioArtifact|null  Current head or null.
     *
     * @since   2.0.0
     */
    public function current(string $vectorId, string $version): ?StoredStudioArtifact
    {
        return $this->artifacts->current(SiteContext::DEFAULT, $this->id($vectorId), $version);
    }

    /**
     * Count the immutable revision rows a vector-local artifact identifier has accumulated.
     *
     * @param   string  $vectorId  Artifact identifier as the vector spells it.
     * @param   string  $version   Canonical artifact version.
     *
     * @return  int  Number of stored revision rows.
     *
     * @since   2.0.0
     */
    public function revisionRows(string $vectorId, string $version): int
    {
        return (int) $this->database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE site_identifier = ? AND artifact_id = ? AND artifact_version = ?',
            $this->tables->quoted('studio_artifact_revisions'),
        ), [SiteContext::DEFAULT, $this->id($vectorId), $version]);
    }

    /**
     * Count the audit rows the mutation boundary committed for this runtime's trusted resource.
     *
     * @return  int  Number of audit rows carrying this runtime's resource identity digest.
     *
     * @since   2.0.0
     */
    public function auditRows(): int
    {
        return (int) $this->database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE subject_id = ?',
            $this->tables->quoted('audit_events'),
        ), [$this->resourceDigest()]);
    }

    /**
     * Read the newest audit row committed for this runtime's trusted resource.
     *
     * @return  array<string, mixed>|null  Action, subject type, outcome and metadata JSON, or null.
     *
     * @since   2.0.0
     */
    public function lastAudit(): ?array
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT action, subject_type, outcome, metadata FROM %s WHERE subject_id = ? '
                . 'ORDER BY position DESC LIMIT 1',
            $this->tables->quoted('audit_events'),
        ), [$this->resourceDigest()]);

        return $row === false ? null : $row;
    }

    /**
     * Read the canonical recovery envelope stored for the session opened under a vector context key.
     *
     * @param   string  $vectorContextKey  Resource context key as the vector spells it.
     *
     * @return  string|null  Canonical envelope bytes or null.
     *
     * @since   2.0.0
     */
    public function envelope(string $vectorContextKey): ?string
    {
        $snapshot = $this->snapshots[$vectorContextKey]
            ?? throw new RuntimeException('The vector context was never opened: ' . $vectorContextKey);

        return $this->recovery->loadEnvelope(
            $snapshot->session->actorId,
            $snapshot->session->sessionBinding,
            $snapshot->session->resourceContextKey,
        );
    }

    /**
     * Map a vector-local artifact identifier to the run-unique identifier stored in the database.
     *
     * @param   string  $vectorId  Artifact identifier as the vector spells it.
     *
     * @return  string  Identifier carrying this runtime's suffix.
     *
     * @since   2.0.0
     */
    public function id(string $vectorId): string
    {
        return $vectorId . '.' . $this->token;
    }

    /**
     * Resolve one container service and prove its type before use.
     *
     * @template T of object
     *
     * @param   Container        $container  Migrated production container.
     * @param   class-string<T>  $id         Service identifier.
     *
     * @return  T  Resolved service.
     *
     * @since   2.0.0
     */
    public static function service(Container $container, string $id): object
    {
        $service = $container->get($id);
        if (!$service instanceof $id) {
            throw new RuntimeException('The container does not serve ' . $id . '.');
        }

        return $service;
    }

    /**
     * Decode one canonical vector document.
     *
     * @param   string  $bytes  Exact vector bytes.
     *
     * @return  stdClass  Decoded object document.
     *
     * @since   2.0.0
     */
    public static function decode(string $bytes): stdClass
    {
        return self::object(json_decode($bytes, false, 64, JSON_THROW_ON_ERROR));
    }

    /**
     * Require one object-valued vector member.
     *
     * @param   mixed  $value  Candidate member.
     *
     * @return  stdClass  The member when it is an object.
     *
     * @since   2.0.0
     */
    public static function object(mixed $value): stdClass
    {
        return $value instanceof stdClass
            ? $value
            : throw new RuntimeException('The vector member is not an object.');
    }

    /**
     * Require one string-valued vector member.
     *
     * @param   mixed  $value  Candidate member.
     *
     * @return  string  The member when it is a string.
     *
     * @since   2.0.0
     */
    public static function string(mixed $value): string
    {
        return is_string($value) ? $value : throw new RuntimeException('The vector member is not a string.');
    }

    /**
     * Require one list of string vector members.
     *
     * @param   mixed  $value  Candidate member.
     *
     * @return  list<string>  The member when it is a list of strings.
     *
     * @since   2.0.0
     */
    public static function strings(mixed $value): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('The vector member is not a list.');
        }
        $strings = [];
        foreach ($value as $entry) {
            $strings[] = self::string($entry);
        }

        return $strings;
    }

    /**
     * Require one list of object vector members.
     *
     * @param   mixed  $value  Candidate member.
     *
     * @return  list<stdClass>  The member when it is a list of objects.
     *
     * @since   2.0.0
     */
    public static function objects(mixed $value): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('The vector member is not a list.');
        }
        $objects = [];
        foreach ($value as $entry) {
            $objects[] = self::object($entry);
        }

        return $objects;
    }

    /**
     * Dispatch one published wrapper through a fresh request-scoped host exactly as the HTTP handler does.
     *
     * @param   string    $port       Canonical port name.
     * @param   string    $operation  Canonical operation name.
     * @param   stdClass  $context    Vector-shaped request context.
     * @param   mixed     $arguments  Exact published HTTP wrapper.
     *
     * @return  StudioVectorReplayOutcome  Decoded canonical response.
     *
     * @since   2.0.0
     */
    private function dispatch(
        string $port,
        string $operation,
        stdClass $context,
        mixed $arguments,
    ): StudioVectorReplayOutcome {
        $route = OperationRegistry::byCapability('studio.operation/' . $port . '.' . $operation)->route;
        if (self::string($context->sessionGeneration) !== $this->generation) {
            throw new RuntimeException('Only current-generation vectors replay against the live session authority.');
        }
        $snapshot = $this->snapshot(self::string($context->resourceContextKey));
        $live = (object) [
            'operationId' => $context->operationId,
            'protocolVersion' => $context->protocolVersion,
            'requestId' => $context->requestId,
            'resourceContextKey' => $snapshot->session->resourceContextKey,
            'sessionGeneration' => $snapshot->generation,
        ];
        foreach (['expectedRevision', 'idempotencyKey', 'locale', 'traceContext'] as $optional) {
            if (property_exists($context, $optional)) {
                $live->{$optional} = $context->{$optional};
            }
        }
        $body = CanonicalJson::stringify((object) ['arguments' => $arguments, 'context' => $live]);

        $authority = new StudioProducerRequestAuthority($this->context, $this->sessions);
        $mutations = new StudioProducerMutationBoundary(
            $this->transactions,
            $this->transactionState,
            $this->replays,
            $this->outcomes,
            $this->audit,
            $this->clock,
            $this->media,
            $authority,
        );
        $host = new StudioProducerHost(
            $authority,
            $mutations,
            $this->artifactPort->forRequest($authority),
            $this->localizationPort->forRequest($authority),
            $this->mediaPort->forRequest($authority),
            $this->modelPort->forRequest($authority),
            new StudioPermissionHostPort($authority),
            $this->previewPort->forRequest($authority),
            $this->recoveryPort->forRequest($authority),
            $this->resourcePort->forRequest($authority),
            $this->telemetryPort->forRequest($authority),
        );

        return StudioVectorReplayOutcome::fromResponse((new Dispatcher($host))->dispatch($route, $body));
    }

    /**
     * Open, once per vector context key, one real session bound to this runtime's resource.
     *
     * @param   string  $vectorContextKey  Resource context key as the vector spells it.
     *
     * @return  StudioHostSessionSnapshot  Live session snapshot issued by the production authority.
     *
     * @since   2.0.0
     */
    private function snapshot(string $vectorContextKey): StudioHostSessionSnapshot
    {
        return $this->snapshots[$vectorContextKey] ??= $this->sessions->open(
            $this->context,
            $this->mode,
            $this->kind,
            $this->resourceId,
        );
    }

    /**
     * Translate one semantic vector argument into the published HTTP wrapper of its operation.
     *
     * @param   string    $port       Canonical port name.
     * @param   string    $operation  Canonical operation name.
     * @param   mixed     $argument   Vector semantic argument, or null when absent.
     * @param   stdClass  $context    Vector-shaped request context.
     *
     * @return  stdClass  Exact published wrapper.
     *
     * @since   2.0.0
     */
    private function wrapper(string $port, string $operation, mixed $argument, stdClass $context): stdClass
    {
        if ($port === 'recovery') {
            return $operation === 'store' ? (object) ['envelope' => $argument] : new stdClass();
        }
        if ($operation === 'save') {
            $semantic = self::object($argument);
            $document = self::fixture(self::string($semantic->kind));
            $document->id = $this->id(self::string($semantic->id));
            if (is_string($context->expectedRevision ?? null)) {
                $document->revision = $context->expectedRevision;
            }
            $document->status = 'draft';

            return (object) ['document' => $document];
        }
        $reference = clone self::object($argument);
        $reference->id = $this->id(self::string($reference->id));

        return (object) ['reference' => $reference];
    }

    /**
     * Seed one artifact declared by the vector's given state into the container repository.
     *
     * @param   stdClass      $seed     Vector artifact seed.
     * @param   Closure|null  $prepare  Optional transformer applied to the fixture before admission.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function seed(stdClass $seed, ?Closure $prepare): void
    {
        $document = self::fixture(self::string($seed->kind));
        $document->id = $this->id(self::string($seed->id));
        $document->revision = self::string($seed->revision);
        $document->status = is_string($seed->status ?? null) ? $seed->status : 'draft';
        if ($seed->kind !== 'entry' && is_string($seed->version ?? null)) {
            $document->version = $seed->version;
        }
        if ($prepare !== null) {
            $prepare($document);
        }
        $artifact = $this->admission->admit(SiteContext::DEFAULT, $document);
        $stored = $this->transactions->transactional(fn (): bool => $this->artifacts->store($artifact, null));
        if ($stored !== true) {
            throw new RuntimeException('The vector seed could not be stored.');
        }
    }

    /**
     * Compute the disclosure-safe audit subject digest the mutation boundary records for this resource.
     *
     * @return  string  Lowercase SHA-256 of the canonical resource and site identity.
     *
     * @since   2.0.0
     */
    private function resourceDigest(): string
    {
        return hash('sha256', CanonicalJson::stringify((object) [
            'resourceId' => $this->resourceId,
            'siteId' => SiteContext::DEFAULT,
        ]));
    }

    /**
     * Build the in-memory binding store the production session authority persists sessions into.
     *
     * @return  StudioHostSessionRepository  Store keyed by resource context key.
     *
     * @since   2.0.0
     */
    private function sessionRepository(): StudioHostSessionRepository
    {
        return new class implements StudioHostSessionRepository {
            /**
             * Retained sessions keyed by resource context key.
             *
             * @var    array<string, StudioHostSession>
             * @since  2.0.0
             */
            private array $sessions = [];

            /**
             * Retain one opened session.
             *
             * @param   StudioHostSession  $session  Opened session to retain.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function add(StudioHostSession $session): void
            {
                $this->sessions[$session->resourceContextKey] = $session;
            }

            /**
             * Serve one retained session by its exact resource context key.
             *
             * @param   string  $resourceContextKey  Requested resource context key.
             *
             * @return  StudioHostSession|null  The retained session, or null when unknown.
             *
             * @since   2.0.0
             */
            public function find(string $resourceContextKey): ?StudioHostSession
            {
                return $this->sessions[$resourceContextKey] ?? null;
            }
        };
    }

    /**
     * Build a key factory allocating run-unique resource context keys in opening order.
     *
     * @return  StudioResourceContextKeyFactory  Deterministic allocator for this runtime.
     *
     * @since   2.0.0
     */
    private function keyFactory(): StudioResourceContextKeyFactory
    {
        return new class ($this->token) implements StudioResourceContextKeyFactory {
            /**
             * Number of keys allocated so far.
             *
             * @var    int
             * @since  2.0.0
             */
            private int $allocated = 0;

            /**
             * Bind the allocator to the runtime's per-run suffix.
             *
             * @param  string  $token  Per-run suffix.
             *
             * @since  2.0.0
             */
            public function __construct(private readonly string $token)
            {
            }

            /**
             * Allocate the next run-unique resource context key.
             *
             * @return  string  Canonical stable identifier unique within this database.
             *
             * @since   2.0.0
             */
            public function create(): string
            {
                return 'contexts/vector-' . $this->token . '-' . ++$this->allocated;
            }
        };
    }

    /**
     * Resolve the recovery-write rate limit a vector declares for one operation.
     *
     * @param   stdClass  $given        Vector `given` state.
     * @param   string    $operationId  Operation whose limit is wanted.
     *
     * @return  array{int, int}  Maximum requests and window milliseconds, or the port defaults.
     *
     * @since   2.0.0
     */
    private static function rateLimit(stdClass $given, string $operationId): array
    {
        foreach (self::objects($given->rateLimits ?? []) as $limit) {
            if ($limit->operationId !== $operationId) {
                continue;
            }
            if (is_int($limit->maximumRequests) && is_int($limit->windowMilliseconds)) {
                return [$limit->maximumRequests, $limit->windowMilliseconds];
            }
        }

        return [60, 60000];
    }

    /**
     * Load one vendored canonical fixture for a requested artifact kind.
     *
     * @param   string  $kind  Canonical artifact kind.
     *
     * @return  stdClass  Freshly decoded fixture document.
     *
     * @since   2.0.0
     */
    private static function fixture(string $kind): stdClass
    {
        $name = match ($kind) {
            'blueprint' => 'blueprint.product.example.json',
            'content-model' => 'content-model.product.example.json',
            'entry' => 'entry.product.example.json',
            default => throw new RuntimeException('The vector seeds an unsupported artifact kind.'),
        };

        return self::decode(StudioContractResources::testkitBytes('fixtures/' . $name));
    }
}

/**
 * One decoded canonical Producer response, result or refusal.
 *
 * @since  2.0.0
 */
final readonly class StudioVectorReplayOutcome
{
    /**
     * Retain the exact bytes and their decoded document.
     *
     * @param  bool      $refusal   Whether the body is a host-error document.
     * @param  string    $body      Exact canonical response bytes.
     * @param  stdClass  $document  Decoded response document.
     *
     * @since  2.0.0
     */
    private function __construct(
        public bool $refusal,
        public string $body,
        public stdClass $document,
    ) {
    }

    /**
     * Decode one dispatcher response.
     *
     * @param   Response  $response  Finished wire response.
     *
     * @return  self  Decoded outcome.
     *
     * @since   2.0.0
     */
    public static function fromResponse(Response $response): self
    {
        return new self($response->refusal, $response->body, StudioVectorReplayRuntime::decode($response->body));
    }

    /**
     * Return the refusal category.
     *
     * @return  string|null  Closed Producer category, or null for a result.
     *
     * @since   2.0.0
     */
    public function category(): ?string
    {
        return $this->refusal ? StudioVectorReplayRuntime::string($this->document->category) : null;
    }

    /**
     * Return the stable refusal code: the first diagnostic code, else the refusal message key.
     *
     * @return  string|null  Refusal code, or null for a result.
     *
     * @since   2.0.0
     */
    public function refusalCode(): ?string
    {
        if (!$this->refusal) {
            return null;
        }
        $diagnostics = StudioVectorReplayRuntime::objects($this->document->diagnostics ?? []);
        if (isset($diagnostics[0])) {
            return StudioVectorReplayRuntime::string($diagnostics[0]->code);
        }

        return StudioVectorReplayRuntime::string(StudioVectorReplayRuntime::object($this->document->message)->key);
    }

    /**
     * Return the revision the document carries.
     *
     * @return  string|null  Advanced revision of a result, safe revision of a conflict, or null.
     *
     * @since   2.0.0
     */
    public function revision(): ?string
    {
        return property_exists($this->document, 'revision')
            ? StudioVectorReplayRuntime::string($this->document->revision)
            : null;
    }

    /**
     * Return the result value, which a result document always carries explicitly.
     *
     * @return  mixed  Result value, explicitly null when the operation answers with nothing.
     *
     * @since   2.0.0
     */
    public function value(): mixed
    {
        if ($this->refusal || !property_exists($this->document, 'value')) {
            throw new RuntimeException('The response carries no result value.');
        }

        return $this->document->value;
    }

    /**
     * Return the refusal's retryability.
     *
     * @return  bool|null  Retryable flag, or null for a result.
     *
     * @since   2.0.0
     */
    public function retryable(): ?bool
    {
        return $this->refusal && is_bool($this->document->retryable ?? null) ? $this->document->retryable : null;
    }

    /**
     * Return the refusal's retry hint.
     *
     * @return  int|null  Retry delay in milliseconds, or null when absent.
     *
     * @since   2.0.0
     */
    public function retryAfterMilliseconds(): ?int
    {
        $delay = $this->document->retryAfterMilliseconds ?? null;

        return is_int($delay) ? $delay : null;
    }
}

/**
 * Millisecond test clock driven only by `advance-clock` sequence actions.
 *
 * @since  2.0.0
 */
final class StudioVectorReplayClock implements ClockInterface
{
    /**
     * Current deterministic instant.
     *
     * @var    DateTimeImmutable
     * @since  2.0.0
     */
    private DateTimeImmutable $now;

    /**
     * Start the clock at one fixed instant so rate windows are reproducible.
     *
     * @since  2.0.0
     */
    public function __construct()
    {
        $this->now = new DateTimeImmutable('2026-08-29T12:00:00+00:00', new DateTimeZone('UTC'));
    }

    /**
     * Return the current deterministic instant.
     *
     * @return  DateTimeImmutable  Current vector instant.
     *
     * @since   2.0.0
     */
    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    /**
     * Advance the deterministic instant.
     *
     * @param   int  $milliseconds  Positive vector-requested offset.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function advance(int $milliseconds): void
    {
        $this->now = $this->now->modify(sprintf('+%d microseconds', $milliseconds * 1000));
    }
}
