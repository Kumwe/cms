<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Studio;

use DateTimeImmutable;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Studio\Application\Host\StudioArtifactAdmission;
use Kumwe\App\Studio\Application\Host\StudioArtifactHostPort;
use Kumwe\App\Studio\Application\Host\StudioArtifactPublicationGuard;
use Kumwe\App\Studio\Application\Host\StudioArtifactRepository;
use Kumwe\App\Studio\Application\Host\StudioRecoveryHostPort;
use Kumwe\App\Studio\Application\Host\StudioRecoveryRepository;
use Kumwe\App\Studio\Domain\Artifact\StoredStudioArtifact;
use Kumwe\Producer\Schema\StudioContractResources;
use Kumwe\Producer\Schema\StudioDocumentSchemaRegistry;
use Kumwe\App\Tests\Support\StudioProducerRequest;
use Kumwe\Producer\Canonical\CanonicalJson;
use Kumwe\Producer\Error\HostRefusal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use stdClass;

/**
 * Integrates the App's artifact and recovery stores through Producer's direct port contracts.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioArtifactHostPort::class)]
#[CoversClass(StudioRecoveryHostPort::class)]
final class StudioArtifactRecoveryProducerIntegrationTest extends TestCase
{
    /**
     * Artifact load and dependency reads retain exact stored canonical bytes and revision authority.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testArtifactReadsUseTheDirectProducerPort(): void
    {
        $document = (object) [
            'id' => 'content-producer-port-test',
            'kind' => 'entry',
            'revision' => 'entry-r1',
            'status' => 'draft',
        ];
        $dependencies = [(object) [
            'id' => 'content-model/example',
            'version' => '1.0.0',
        ]];
        $artifact = new StoredStudioArtifact(
            'default',
            'content-producer-port-test',
            '1.0.0',
            'entry',
            'entry-r1',
            'draft',
            CanonicalJson::stringify($document),
            CanonicalJson::stringify($dependencies),
        );
        $repository = new class ($artifact) implements StudioArtifactRepository {
            /**
             * Retain the one immutable artifact head.
             *
             * @param   StoredStudioArtifact  $artifact  Sole stored artifact head this repository serves.
             *
             * @since   2.0.0
             */
            public function __construct(private StoredStudioArtifact $artifact)
            {
            }

            /**
             * {@inheritDoc}
             *
             * @param   string  $siteIdentifier  Site scope the read is bound to.
             * @param   string  $id              Requested artifact identifier.
             * @param   string  $version         Requested artifact version.
             *
             * @return  ?StoredStudioArtifact  The retained head when every coordinate matches, null otherwise.
             *
             * @since   2.0.0
             */
            public function current(string $siteIdentifier, string $id, string $version): ?StoredStudioArtifact
            {
                return $siteIdentifier === 'default'
                    && $id === $this->artifact->id
                    && $version === $this->artifact->version
                    ? $this->artifact
                    : null;
            }

            /**
             * {@inheritDoc}
             *
             * @param   string  $siteIdentifier  Site scope the read is bound to.
             * @param   string  $id              Requested artifact identifier.
             * @param   string  $version         Requested artifact version.
             * @param   string  $revision        Requested exact revision authority.
             *
             * @return  ?StoredStudioArtifact  The retained head when its revision also matches, null otherwise.
             *
             * @since   2.0.0
             */
            public function revision(
                string $siteIdentifier,
                string $id,
                string $version,
                string $revision,
            ): ?StoredStudioArtifact {
                return $revision === $this->artifact->revision
                    ? $this->current($siteIdentifier, $id, $version)
                    : null;
            }

            /**
             * {@inheritDoc}
             *
             * @param   StoredStudioArtifact  $artifact         Candidate artifact head to persist.
             * @param   ?string               $expectedCurrent  Revision the caller believes is current.
             *
             * @return  bool  Always false: this read-only double never accepts writes.
             *
             * @since   2.0.0
             */
            public function store(StoredStudioArtifact $artifact, ?string $expectedCurrent): bool
            {
                unset($artifact, $expectedCurrent);
                return false;
            }
        };
        $publication = new class implements StudioArtifactPublicationGuard {
            /**
             * {@inheritDoc}
             *
             * @param   SiteContext  $site       Site the publication would target.
             * @param   stdClass     $blueprint  Decoded artifact blueprint under review.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function assertPublishable(SiteContext $site, stdClass $blueprint): void
            {
                unset($site, $blueprint);
            }
        };
        $port = new StudioArtifactHostPort(
            $repository,
            new StudioArtifactAdmission(StudioDocumentSchemaRegistry::fromVendoredCorpus()),
            $publication,
        );
        $arguments = (object) ['reference' => (object) [
            'id' => 'content-producer-port-test',
            'version' => '1.0.0',
        ]];
        $load = StudioProducerRequest::authorized('studio.operation/artifact.load', $arguments);
        $loaded = $port->forRequest($load->authority)->load($load->arguments(), $load->context());
        $dependencyRequest = StudioProducerRequest::authorized(
            'studio.operation/artifact.dependencies',
            $arguments,
        );
        $locked = $port->forRequest($dependencyRequest->authority)->dependencies(
            $dependencyRequest->arguments(),
            $dependencyRequest->context(),
        );

        self::assertEquals($document, $loaded->value);
        self::assertSame('entry-r1', $loaded->revision);
        self::assertEquals($dependencies, $locked->value);
    }

    /**
     * Save, publish and unpublish append revisions under session permission and optimistic concurrency.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSaveAndLifecycleTransitionsUseTheDirectProducerPort(): void
    {
        $document = json_decode(
            StudioContractResources::testkitBytes('fixtures/entry.product.example.json'),
            false,
            64,
            JSON_THROW_ON_ERROR,
        );
        self::assertInstanceOf(stdClass::class, $document);
        $admission = new StudioArtifactAdmission(StudioDocumentSchemaRegistry::fromVendoredCorpus());
        $repository = new class ($admission->admit('default', $document)) implements StudioArtifactRepository {
            /**
             * Retain the mutable artifact head under optimistic concurrency.
             *
             * @param   StoredStudioArtifact  $head  Initial stored artifact head.
             *
             * @since   2.0.0
             */
            public function __construct(public StoredStudioArtifact $head)
            {
            }

            /**
             * {@inheritDoc}
             *
             * @param   string  $siteIdentifier  Site scope the read is bound to.
             * @param   string  $id              Requested artifact identifier.
             * @param   string  $version         Requested artifact version.
             *
             * @return  ?StoredStudioArtifact  The live head when every coordinate matches, null otherwise.
             *
             * @since   2.0.0
             */
            public function current(string $siteIdentifier, string $id, string $version): ?StoredStudioArtifact
            {
                return $siteIdentifier === $this->head->siteIdentifier
                    && $id === $this->head->id
                    && $version === $this->head->version
                    ? $this->head
                    : null;
            }

            /**
             * {@inheritDoc}
             *
             * @param   string  $siteIdentifier  Site scope the read is bound to.
             * @param   string  $id              Requested artifact identifier.
             * @param   string  $version         Requested artifact version.
             * @param   string  $revision        Requested exact revision authority.
             *
             * @return  ?StoredStudioArtifact  The live head when its revision also matches, null otherwise.
             *
             * @since   2.0.0
             */
            public function revision(
                string $siteIdentifier,
                string $id,
                string $version,
                string $revision,
            ): ?StoredStudioArtifact {
                return $revision === $this->head->revision
                    ? $this->current($siteIdentifier, $id, $version)
                    : null;
            }

            /**
             * {@inheritDoc}
             *
             * @param   StoredStudioArtifact  $artifact         Candidate artifact head to persist.
             * @param   ?string               $expectedCurrent  Revision the caller believes is current.
             *
             * @return  bool  True only when the optimistic predecessor matched the live head.
             *
             * @since   2.0.0
             */
            public function store(StoredStudioArtifact $artifact, ?string $expectedCurrent): bool
            {
                if ($expectedCurrent !== $this->head->revision) {
                    return false;
                }
                $this->head = $artifact;

                return true;
            }
        };
        $publication = new class implements StudioArtifactPublicationGuard {
            /**
             * {@inheritDoc}
             *
             * @param   SiteContext  $site       Site the publication would target.
             * @param   stdClass     $blueprint  Decoded artifact blueprint under review.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function assertPublishable(SiteContext $site, stdClass $blueprint): void
            {
                unset($site, $blueprint);
            }
        };
        $port = new StudioArtifactHostPort($repository, $admission, $publication);
        $reference = (object) ['reference' => (object) [
            'id' => $document->id,
            'version' => $document->model->version,
        ]];

        $save = StudioProducerRequest::authorized(
            'studio.operation/artifact.save',
            (object) ['document' => $document],
            expectedRevision: $document->revision,
            idempotencyKey: 'idempotency/artifact-save',
            resource: $document->id,
        );
        $saved = $port->forRequest($save->authority)->save($save->arguments(), $save->context());
        self::assertNull($saved->value);
        self::assertIsString($saved->revision);
        self::assertNotSame($document->revision, $saved->revision);
        self::assertSame($saved->revision, $repository->head->revision);
        self::assertSame('draft', $repository->head->status);

        $publish = StudioProducerRequest::authorized(
            'studio.operation/artifact.publish',
            $reference,
            expectedRevision: $saved->revision,
            idempotencyKey: 'idempotency/artifact-publish',
            resource: $document->id,
        );
        $published = $port->forRequest($publish->authority)->publish($publish->arguments(), $publish->context());
        self::assertSame($published->revision, $repository->head->revision);
        self::assertSame('published', $repository->head->status);

        $unpublish = StudioProducerRequest::authorized(
            'studio.operation/artifact.unpublish',
            $reference,
            expectedRevision: $published->revision,
            idempotencyKey: 'idempotency/artifact-unpublish',
            resource: $document->id,
        );
        $withdrawn = $port->forRequest($unpublish->authority)
            ->unpublish($unpublish->arguments(), $unpublish->context());
        self::assertSame($withdrawn->revision, $repository->head->revision);
        self::assertSame('draft', $repository->head->status);

        $stale = StudioProducerRequest::authorized(
            'studio.operation/artifact.save',
            (object) ['document' => $document],
            expectedRevision: $document->revision,
            idempotencyKey: 'idempotency/artifact-stale-save',
            resource: $document->id,
        );
        try {
            $port->forRequest($stale->authority)->save($stale->arguments(), $stale->context());
            self::fail('A save against a superseded revision must be refused.');
        } catch (HostRefusal $refused) {
            self::assertSame('conflict', $refused->error()->category());
        }

        $missing = StudioProducerRequest::authorized(
            'studio.operation/artifact.publish',
            (object) ['reference' => (object) ['id' => $document->id, 'version' => '9.9.9']],
            expectedRevision: $withdrawn->revision,
            idempotencyKey: 'idempotency/artifact-missing-publish',
            resource: $document->id,
        );
        try {
            $port->forRequest($missing->authority)->publish($missing->arguments(), $missing->context());
            self::fail('A lifecycle transition on an unknown head must be refused.');
        } catch (HostRefusal $refused) {
            self::assertSame('not-found', $refused->error()->category());
        }
    }

    /**
     * Recovery store, load, and discard remain actor/session/resource scoped under direct Producer requests.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testRecoveryRoundTripUsesTheDirectProducerPort(): void
    {
        $repository = new class implements StudioRecoveryRepository {
            /**
             * Stored envelope by complete trusted scope.
             *
             * @var    array<string, string>
             * @since  2.0.0
             */
            private array $envelopes = [];

            /**
             * {@inheritDoc}
             *
             * @param   string  $actorId             Acting user identity scope.
             * @param   string  $sessionBinding      Session binding scope.
             * @param   string  $resourceContextKey  Resource context scope.
             *
             * @return  ?string  The stored canonical envelope, or null when the scope holds none.
             *
             * @since   2.0.0
             */
            public function loadEnvelope(string $actorId, string $sessionBinding, string $resourceContextKey): ?string
            {
                return $this->envelopes[self::key($actorId, $sessionBinding, $resourceContextKey)] ?? null;
            }

            /**
             * {@inheritDoc}
             *
             * @param   string  $actorId                Acting user identity scope.
             * @param   string  $sessionBinding         Session binding scope.
             * @param   string  $resourceContextKey     Resource context scope.
             * @param   string  $canonicalEnvelope      Canonical envelope bytes to retain.
             * @param   int     $updatedAtMilliseconds  Update instant, ignored by this in-memory double.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function saveEnvelope(
                string $actorId,
                string $sessionBinding,
                string $resourceContextKey,
                string $canonicalEnvelope,
                int $updatedAtMilliseconds,
            ): void {
                unset($updatedAtMilliseconds);
                $this->envelopes[self::key($actorId, $sessionBinding, $resourceContextKey)] = $canonicalEnvelope;
            }

            /**
             * {@inheritDoc}
             *
             * @param   string  $actorId             Acting user identity scope.
             * @param   string  $sessionBinding      Session binding scope.
             * @param   string  $resourceContextKey  Resource context scope.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function discardEnvelope(string $actorId, string $sessionBinding, string $resourceContextKey): void
            {
                unset($this->envelopes[self::key($actorId, $sessionBinding, $resourceContextKey)]);
            }

            /**
             * {@inheritDoc}
             *
             * @param   string  $scopeDigest         Digest of the rate-limited scope.
             * @param   int     $nowMilliseconds     Current instant in milliseconds.
             * @param   int     $windowMilliseconds  Sliding window width in milliseconds.
             * @param   int     $maximumRequests     Requests allowed within the window.
             *
             * @return  ?int  Always null: this double never throttles.
             *
             * @since   2.0.0
             */
            public function consumeRateLimit(
                string $scopeDigest,
                int $nowMilliseconds,
                int $windowMilliseconds,
                int $maximumRequests,
            ): ?int {
                unset($scopeDigest, $nowMilliseconds, $windowMilliseconds, $maximumRequests);
                return null;
            }

            /**
             * Build one unambiguous in-memory scope key.
             *
             * @param   string  $actorId             Acting user identity scope.
             * @param   string  $sessionBinding      Session binding scope.
             * @param   string  $resourceContextKey  Resource context scope.
             *
             * @return  string  Collision-free digest of the three scope parts.
             *
             * @since   2.0.0
             */
            private static function key(string $actorId, string $sessionBinding, string $resourceContextKey): string
            {
                return hash('sha256', $actorId . "\0" . $sessionBinding . "\0" . $resourceContextKey);
            }
        };
        $clock = new class implements ClockInterface {
            /**
             * {@inheritDoc}
             *
             * @return  DateTimeImmutable  The fixed instant this deterministic clock always reports.
             *
             * @since   2.0.0
             */
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-08-29T12:00:00+00:00');
            }
        };
        $port = new StudioRecoveryHostPort($repository, $clock);
        $envelope = (object) ['draft' => (object) ['title' => 'Recovery']];
        $store = StudioProducerRequest::authorized(
            'studio.operation/recovery.store',
            (object) ['envelope' => $envelope],
            idempotencyKey: 'idempotency/recovery-round-trip',
        );
        $port->forRequest($store->authority)->store($store->arguments(), $store->context());
        $load = StudioProducerRequest::authorized('studio.operation/recovery.load', new stdClass());
        $loaded = $port->forRequest($load->authority)->load($load->arguments(), $load->context());
        self::assertEquals($envelope, $loaded->value);

        $discard = StudioProducerRequest::authorized(
            'studio.operation/recovery.discard',
            new stdClass(),
            idempotencyKey: 'idempotency/recovery-discard',
        );
        $port->forRequest($discard->authority)->discard($discard->arguments(), $discard->context());
        $empty = StudioProducerRequest::authorized('studio.operation/recovery.load', new stdClass());
        self::assertNull($port->forRequest($empty->authority)->load($empty->arguments(), $empty->context())->value);

        $oversized = StudioProducerRequest::authorized(
            'studio.operation/recovery.store',
            (object) ['envelope' => (object) ['draft' => str_repeat('x', 200)]],
            idempotencyKey: 'idempotency/recovery-oversized',
        );
        $bounded = new StudioRecoveryHostPort($repository, $clock, maximumBytes: 32);
        try {
            $bounded->forRequest($oversized->authority)->store($oversized->arguments(), $oversized->context());
            self::fail('An envelope above the recovery byte bound must be refused.');
        } catch (HostRefusal $refused) {
            self::assertSame('limit-exceeded', $refused->error()->category());
        }

        $malformed = StudioProducerRequest::authorized(
            'studio.operation/recovery.store',
            (object) ['envelope' => 'not-an-object'],
            idempotencyKey: 'idempotency/recovery-malformed',
        );
        try {
            $port->forRequest($malformed->authority)->store($malformed->arguments(), $malformed->context());
            self::fail('A non-object recovery envelope must be refused.');
        } catch (HostRefusal $refused) {
            self::assertSame('invalid-request', $refused->error()->category());
        }
    }
}
