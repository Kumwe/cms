<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Host;

use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Studio\Application\Host\StudioArtifactAdmission;
use Kumwe\App\Studio\Application\Host\StudioArtifactHostPort;
use Kumwe\App\Studio\Application\Host\StudioArtifactPublicationGuard;
use Kumwe\App\Studio\Application\Host\StudioArtifactRepository;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Host\StudioHostSessionRepository;
use Kumwe\App\Studio\Application\Host\StudioPersistenceRace;
use Kumwe\App\Studio\Application\Host\StudioProducerError;
use Kumwe\App\Studio\Application\Host\StudioProducerRequestAuthority;
use Kumwe\App\Studio\Application\Host\StudioResourceContextKeyFactory;
use Kumwe\App\Studio\Domain\Artifact\StoredStudioArtifact;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\StudioProducerRequest;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Producer\Canonical\CanonicalJson;
use Kumwe\Producer\Error\HostRefusal;
use Kumwe\Producer\Schema\StudioContractResources;
use Kumwe\Producer\Schema\StudioDocumentSchemaRegistry;
use Kumwe\Producer\Wire\OperationRegistry;
use Kumwe\Producer\Wire\RequestEnvelope;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(StudioArtifactHostPort::class)]
#[CoversClass(StudioArtifactAdmission::class)]
/**
 * Proves the artifact port serves admitted revisions only and refuses every boundary breach.
 *
 * @since  2.0.0
 */
final class StudioArtifactHostPortTest extends TestCase
{
    /**
     * Trusted App capabilities that authorize a complete Blueprint authoring session.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array BLUEPRINT_CAPABILITIES = [
        'content.publish',
        'content.read',
        'content.unpublish',
        'content.update',
        'studio.mode.blueprint',
    ];

    /**
     * The one admission boundary shared by every test, built over the vendored schema corpus.
     *
     * @var    StudioArtifactAdmission|null
     * @since  2.0.0
     */
    private static ?StudioArtifactAdmission $admission = null;

    /**
     * Prove an unbound port never dispatches, even a well-formed read.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnboundPortRefusesDispatch(): void
    {
        $request = StudioProducerRequest::authorized(
            'studio.operation/artifact.load',
            (object) ['reference' => (object) ['id' => 'content-producer-port-test', 'version' => '1.0.0']],
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires request authority');

        self::port(self::repository(null))->load($request->arguments(), $request->context());
    }

    /**
     * Prove load and dependencies serve the exact stored document, revision and locked references.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLoadAndDependenciesServeTheStoredArtifact(): void
    {
        $document = self::entryDocument();
        $head = self::admission()->admit('default', $document);
        $port = self::port(self::repository($head));
        $arguments = (object) ['reference' => (object) ['id' => $document->id, 'version' => '1.0.0']];

        $load = StudioProducerRequest::authorized(
            'studio.operation/artifact.load',
            $arguments,
            resource: $document->id,
        );
        $loaded = $port->forRequest($load->authority)->load($load->arguments(), $load->context());
        self::assertEquals($document, $loaded->value);
        self::assertSame('entry-r7', $loaded->revision);

        $historical = StudioProducerRequest::authorized(
            'studio.operation/artifact.load',
            (object) ['reference' => (object) [
                'id' => $document->id,
                'revision' => 'entry-r7',
                'version' => '1.0.0',
            ]],
            resource: $document->id,
        );
        $pinned = $port->forRequest($historical->authority)
            ->load($historical->arguments(), $historical->context());
        self::assertSame('entry-r7', $pinned->revision);

        $dependencies = StudioProducerRequest::authorized(
            'studio.operation/artifact.dependencies',
            $arguments,
            resource: $document->id,
        );
        $locked = $port->forRequest($dependencies->authority)
            ->dependencies($dependencies->arguments(), $dependencies->context());
        self::assertEquals([(object) [
            'id' => 'org.example.models/product',
            'version' => '1.0.0',
            'revision' => 'product-model-r1',
        ]], $locked->value);
    }

    /**
     * Prove a read operation refuses a context carrying mutation coordinates.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLoadRefusesAMutationScopedContext(): void
    {
        $request = StudioProducerRequest::authorized(
            'studio.operation/artifact.load',
            (object) ['reference' => (object) ['id' => 'content-producer-port-test', 'version' => '1.0.0']],
            expectedRevision: 'entry-r7',
        );

        try {
            self::port(self::repository(null))
                ->forRequest($request->authority)
                ->load($request->arguments(), $request->context());
            self::fail('A read carrying an expected revision must be refused.');
        } catch (HostRefusal $refused) {
            self::assertSame('invalid-request', $refused->error()->category());
        }
    }

    /**
     * Prove a reference outside the session's bound resource is a non-disclosing not-found.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLoadRefusesAReferenceOutsideTheSessionResource(): void
    {
        $head = self::admission()->admit('default', self::entryDocument());
        $request = StudioProducerRequest::authorized(
            'studio.operation/artifact.load',
            (object) ['reference' => (object) ['id' => 'products/trail-backpack', 'version' => '1.0.0']],
        );

        try {
            self::port(self::repository($head))
                ->forRequest($request->authority)
                ->load($request->arguments(), $request->context());
            self::fail('A reference outside the session resource must be refused.');
        } catch (HostRefusal $refused) {
            self::assertSame('not-found', $refused->error()->category());
        }
    }

    /**
     * Prove a load against an empty repository refuses as not-found.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLoadRefusesAnArtifactTheRepositoryDoesNotHold(): void
    {
        $request = StudioProducerRequest::authorized(
            'studio.operation/artifact.load',
            (object) ['reference' => (object) ['id' => 'content-producer-port-test', 'version' => '1.0.0']],
        );

        try {
            self::port(self::repository(null))
                ->forRequest($request->authority)
                ->load($request->arguments(), $request->context());
            self::fail('A load on an artifact the repository does not hold must be refused.');
        } catch (HostRefusal $refused) {
            self::assertSame('not-found', $refused->error()->category());
        }
    }

    /**
     * Prove an artifact kind outside the session's canonical mode is refused, not served.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLoadRefusesAKindTheSessionModeDoesNotServe(): void
    {
        $head = self::storedHead('content-model', 'draft', id: 'content-producer-port-test', revision: 'model-r1');
        $request = StudioProducerRequest::authorized(
            'studio.operation/artifact.load',
            (object) ['reference' => (object) ['id' => 'content-producer-port-test', 'version' => '1.0.0']],
        );

        try {
            self::port(self::repository($head))
                ->forRequest($request->authority)
                ->load($request->arguments(), $request->context());
            self::fail('A content-model artifact must not be served to a content-mode session.');
        } catch (HostRefusal $refused) {
            self::assertSame('forbidden', $refused->error()->category());
        }
    }

    /**
     * Prove every malformed argument wrapper and reference shape refuses as invalid-request.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMalformedArgumentShapesAreRefused(): void
    {
        $reference = (object) ['id' => 'content-producer-port-test', 'version' => '1.0.0'];
        $shapes = [
            'not-an-object',
            (object) ['reference' => $reference, 'extra' => true],
            (object) ['reference' => 'not-an-object'],
            (object) ['reference' => (object) ['id' => 'content-producer-port-test']],
            (object) ['reference' => (object) ['id' => '', 'version' => '1.0.0']],
            (object) ['reference' => (object) ['id' => 'content-producer-port-test', 'version' => 7]],
        ];
        $port = self::port(self::repository(null));
        foreach ($shapes as $shape) {
            $request = StudioProducerRequest::authorized('studio.operation/artifact.load', $shape);
            try {
                $port->forRequest($request->authority)->load($request->arguments(), $request->context());
                self::fail('A malformed artifact argument shape must be refused.');
            } catch (HostRefusal $refused) {
                self::assertSame('invalid-request', $refused->error()->category());
            }
        }
    }

    /**
     * Prove a save without an optimistic concurrency coordinate is refused before admission.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSaveRequiresAnExpectedRevision(): void
    {
        $request = StudioProducerRequest::authorized(
            'studio.operation/artifact.save',
            (object) ['document' => self::entryDocument()],
            resource: 'products/trail-backpack',
        );

        try {
            self::port(self::repository(null))
                ->forRequest($request->authority)
                ->save($request->arguments(), $request->context());
            self::fail('A save without an expected revision must be refused.');
        } catch (HostRefusal $refused) {
            self::assertSame('invalid-request', $refused->error()->category());
        }
    }

    /**
     * Prove non-object, unsupported, off-schema and unsafe documents never reach persistence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSaveRefusesDocumentsTheAdmissionBoundaryRejects(): void
    {
        $extraneous = self::entryDocument();
        $extraneous->extraneous = 'unmapped';
        $unsafe = self::entryDocument();
        $unsafe->values->name = 'eval(payload)';
        $documents = ['not-an-object', (object) ['kind' => 'mystery'], $extraneous, $unsafe];
        $port = self::port(self::repository(null));
        foreach ($documents as $document) {
            $request = StudioProducerRequest::authorized(
                'studio.operation/artifact.save',
                (object) ['document' => $document],
                expectedRevision: 'entry-r7',
                resource: 'products/trail-backpack',
            );
            try {
                $port->forRequest($request->authority)->save($request->arguments(), $request->context());
                self::fail('A document the admission boundary rejects must not be saved.');
            } catch (HostRefusal $refused) {
                self::assertSame('validation-failed', $refused->error()->category());
            }
        }
    }

    /**
     * Prove a save whose expected revision disagrees with the submitted document conflicts.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSaveRefusesAStaleExpectedRevision(): void
    {
        $head = self::storedHead('entry', 'draft');
        $request = StudioProducerRequest::authorized(
            'studio.operation/artifact.save',
            (object) ['document' => self::entryDocument()],
            expectedRevision: 'entry-r0',
            resource: 'products/trail-backpack',
        );

        try {
            self::port(self::repository($head))
                ->forRequest($request->authority)
                ->save($request->arguments(), $request->context());
            self::fail('A save against a mismatched expected revision must be refused.');
        } catch (HostRefusal $refused) {
            self::assertSame('conflict', $refused->error()->category());
        }
    }

    /**
     * Prove a save on an artifact head the repository does not hold is not-found.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSaveRefusesAnUnknownArtifactHead(): void
    {
        $request = StudioProducerRequest::authorized(
            'studio.operation/artifact.save',
            (object) ['document' => self::entryDocument()],
            expectedRevision: 'entry-r7',
            resource: 'products/trail-backpack',
        );

        try {
            self::port(self::repository(null))
                ->forRequest($request->authority)
                ->save($request->arguments(), $request->context());
            self::fail('A save on an unknown artifact head must be refused.');
        } catch (HostRefusal $refused) {
            self::assertSame('not-found', $refused->error()->category());
        }
    }

    /**
     * Prove a save conflicts when the stored head advanced past the expected revision.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSaveRefusesWhenTheStoredHeadMoved(): void
    {
        $head = self::storedHead('entry', 'draft', revision: 'entry-r8');
        $request = StudioProducerRequest::authorized(
            'studio.operation/artifact.save',
            (object) ['document' => self::entryDocument()],
            expectedRevision: 'entry-r7',
            resource: 'products/trail-backpack',
        );

        try {
            self::port(self::repository($head))
                ->forRequest($request->authority)
                ->save($request->arguments(), $request->context());
            self::fail('A save behind the stored head must be refused.');
        } catch (HostRefusal $refused) {
            self::assertSame('conflict', $refused->error()->category());
        }
    }

    /**
     * Prove generic saves cannot move coordinates, escape a non-draft head or change lifecycle.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSaveContinuityKeepsCoordinatesAndLifecycleStable(): void
    {
        $lifecycle = self::entryDocument();
        $lifecycle->status = 'published';
        $drifts = [
            [self::storedHead('entry', 'draft', version: '2.0.0'), self::entryDocument()],
            [self::storedHead('entry', 'published'), self::entryDocument()],
            [self::storedHead('entry', 'draft'), $lifecycle],
        ];
        foreach ($drifts as [$head, $document]) {
            $request = StudioProducerRequest::authorized(
                'studio.operation/artifact.save',
                (object) ['document' => $document],
                expectedRevision: 'entry-r7',
                resource: 'products/trail-backpack',
            );
            try {
                self::port(self::repository($head))
                    ->forRequest($request->authority)
                    ->save($request->arguments(), $request->context());
                self::fail('A save breaking draft continuity must be refused.');
            } catch (HostRefusal $refused) {
                self::assertSame('conflict', $refused->error()->category());
            }
        }
    }

    /**
     * Prove a Blueprint save cannot move the immutable owner, model and dependency lock.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBlueprintSavesCannotMoveTheImmutableLock(): void
    {
        $current = self::blueprintDocument();
        $current->status = 'draft';
        $head = self::admission()->admit('default', $current);
        $candidate = self::blueprintDocument();
        $candidate->status = 'draft';
        $candidate->dependencyLock->theme->revision = 'commerce-theme-r9';
        [$authority, $envelope] = self::scopedRequest(
            'studio.operation/artifact.save',
            (object) ['document' => $candidate],
            StudioSessionMode::Blueprint,
            StudioResourceKind::Blueprint,
            $current->id,
            self::BLUEPRINT_CAPABILITIES,
            'product-card-r5',
        );

        try {
            self::port(self::repository($head))
                ->forRequest($authority)
                ->save($envelope->arguments(), $envelope->context());
            self::fail('A Blueprint save moving the dependency lock must be refused.');
        } catch (HostRefusal $refused) {
            self::assertSame('conflict', $refused->error()->category());
        }
    }

    /**
     * Prove a concurrent persistence claimant surfaces as a retryable unavailable refusal.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSavePersistenceRaceIsRefusedAsRetryable(): void
    {
        $head = self::storedHead('entry', 'draft');
        $request = StudioProducerRequest::authorized(
            'studio.operation/artifact.save',
            (object) ['document' => self::entryDocument()],
            expectedRevision: 'entry-r7',
            resource: 'products/trail-backpack',
        );

        try {
            self::port(self::repository($head, 'race'))
                ->forRequest($request->authority)
                ->save($request->arguments(), $request->context());
            self::fail('A save losing a persistence race must be refused.');
        } catch (HostRefusal $refused) {
            self::assertSame('unavailable', $refused->error()->category());
            self::assertTrue($refused->error()->retryable());
        }
    }

    /**
     * Prove a compare-and-set store rejection conflicts instead of silently losing the save.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testALostSaveIsRefusedWithTheCurrentRevision(): void
    {
        $head = self::storedHead('entry', 'draft');
        $request = StudioProducerRequest::authorized(
            'studio.operation/artifact.save',
            (object) ['document' => self::entryDocument()],
            expectedRevision: 'entry-r7',
            resource: 'products/trail-backpack',
        );

        try {
            self::port(self::repository($head, 'reject'))
                ->forRequest($request->authority)
                ->save($request->arguments(), $request->context());
            self::fail('A save the store did not accept must be refused.');
        } catch (HostRefusal $refused) {
            self::assertSame('conflict', $refused->error()->category());
        }
    }

    /**
     * Prove save, publish and unpublish append revisions under one optimistic lineage.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSavePublishAndUnpublishAdvanceTheLifecycle(): void
    {
        $document = self::entryDocument();
        $repository = self::repository(self::admission()->admit('default', $document));
        $port = self::port($repository);
        $reference = (object) ['reference' => (object) ['id' => $document->id, 'version' => '1.0.0']];

        $save = StudioProducerRequest::authorized(
            'studio.operation/artifact.save',
            (object) ['document' => $document],
            expectedRevision: 'entry-r7',
            resource: $document->id,
        );
        $saved = $port->forRequest($save->authority)->save($save->arguments(), $save->context());
        self::assertNull($saved->value);
        self::assertIsString($saved->revision);
        self::assertNotSame('entry-r7', $saved->revision);
        self::assertSame($saved->revision, $repository->head->revision);
        self::assertSame('draft', $repository->head->status);

        $publish = StudioProducerRequest::authorized(
            'studio.operation/artifact.publish',
            $reference,
            expectedRevision: $saved->revision,
            resource: $document->id,
        );
        $published = $port->forRequest($publish->authority)
            ->publish($publish->arguments(), $publish->context());
        self::assertSame($published->revision, $repository->head->revision);
        self::assertSame('published', $repository->head->status);

        $unpublish = StudioProducerRequest::authorized(
            'studio.operation/artifact.unpublish',
            $reference,
            expectedRevision: $published->revision,
            resource: $document->id,
        );
        $withdrawn = $port->forRequest($unpublish->authority)
            ->unpublish($unpublish->arguments(), $unpublish->context());
        self::assertSame($withdrawn->revision, $repository->head->revision);
        self::assertSame('draft', $repository->head->status);
    }

    /**
     * Prove a schema-valid content-model save is admitted and appended under a model session.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAContentModelSaveCrossesTheAdmissionBoundary(): void
    {
        $current = self::contentModelDocument();
        $current->status = 'draft';
        $repository = self::repository(self::admission()->admit('default', $current));
        $candidate = self::contentModelDocument();
        $candidate->status = 'draft';
        [$authority, $envelope] = self::scopedRequest(
            'studio.operation/artifact.save',
            (object) ['document' => $candidate],
            StudioSessionMode::Model,
            StudioResourceKind::Content,
            $current->id,
            ['content.read', 'content.update', 'studio.mode.model'],
            $current->revision,
        );

        $saved = self::port($repository)
            ->forRequest($authority)
            ->save($envelope->arguments(), $envelope->context());

        self::assertNull($saved->value);
        self::assertSame($saved->revision, $repository->head->revision);
        self::assertSame('content-model', $repository->head->kind);
        self::assertSame('draft', $repository->head->status);
    }

    /**
     * Prove a lifecycle transition on an unknown artifact head is not-found.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPublishRefusesAnUnknownHead(): void
    {
        $request = self::publishRequest('entry-r7');

        try {
            self::port(self::repository(null))
                ->forRequest($request->authority)
                ->publish($request->arguments(), $request->context());
            self::fail('A publish on an unknown artifact head must be refused.');
        } catch (HostRefusal $refused) {
            self::assertSame('not-found', $refused->error()->category());
        }
    }

    /**
     * Prove a lifecycle transition behind the current head revision conflicts.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPublishRefusesAStaleExpectedRevision(): void
    {
        $head = self::storedHead('entry', 'draft');
        $request = self::publishRequest('entry-r0');

        try {
            self::port(self::repository($head))
                ->forRequest($request->authority)
                ->publish($request->arguments(), $request->context());
            self::fail('A publish behind the current head revision must be refused.');
        } catch (HostRefusal $refused) {
            self::assertSame('conflict', $refused->error()->category());
        }
    }

    /**
     * Prove a retired artifact accepts no further lifecycle transitions.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPublishRefusesARetiredArtifact(): void
    {
        $head = self::storedHead('entry', 'retired');
        $request = self::publishRequest('entry-r7');

        try {
            self::port(self::repository($head))
                ->forRequest($request->authority)
                ->publish($request->arguments(), $request->context());
            self::fail('A publish of a retired artifact must be refused.');
        } catch (HostRefusal $refused) {
            self::assertSame('conflict', $refused->error()->category());
        }
    }

    /**
     * Prove a concurrent claimant during a lifecycle write is a retryable unavailable refusal.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPublishPersistenceRaceIsRefusedAsRetryable(): void
    {
        $head = self::admission()->admit('default', self::entryDocument());
        $request = self::publishRequest('entry-r7');

        try {
            self::port(self::repository($head, 'race'))
                ->forRequest($request->authority)
                ->publish($request->arguments(), $request->context());
            self::fail('A publish losing a persistence race must be refused.');
        } catch (HostRefusal $refused) {
            self::assertSame('unavailable', $refused->error()->category());
            self::assertTrue($refused->error()->retryable());
        }
    }

    /**
     * Prove a lifecycle write the store did not accept conflicts with the live revision.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testALostPublishIsRefusedWithTheCurrentRevision(): void
    {
        $head = self::admission()->admit('default', self::entryDocument());
        $request = self::publishRequest('entry-r7');

        try {
            self::port(self::repository($head, 'reject'))
                ->forRequest($request->authority)
                ->publish($request->arguments(), $request->context());
            self::fail('A publish the store did not accept must be refused.');
        } catch (HostRefusal $refused) {
            self::assertSame('conflict', $refused->error()->category());
        }
    }

    /**
     * Prove the publication guard governs exactly the App-owned Content Blueprint family.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPublishConsultsTheGuardOnlyForAppOwnedBlueprints(): void
    {
        $owned = self::blueprintDocument();
        $owned->status = 'draft';
        $owned->owner = (object) ['id' => 'kumwe.app/content', 'version' => '2.0.0'];
        [$authority, $envelope] = self::scopedRequest(
            'studio.operation/artifact.publish',
            (object) ['reference' => (object) ['id' => $owned->id, 'version' => '1.0.0']],
            StudioSessionMode::Blueprint,
            StudioResourceKind::Blueprint,
            $owned->id,
            self::BLUEPRINT_CAPABILITIES,
            'product-card-r5',
        );
        try {
            self::port(self::repository(self::admission()->admit('default', $owned)), self::refusingGuard())
                ->forRequest($authority)
                ->publish($envelope->arguments(), $envelope->context());
            self::fail('An App-owned Blueprint the guard rejects must not publish.');
        } catch (HostRefusal $refused) {
            self::assertSame('incompatible', $refused->error()->category());
        }

        $foreign = self::blueprintDocument();
        $foreign->status = 'draft';
        $foreign->dependencyLock->plugins = [(object) [
            'id' => 'org.example.plugins/gallery',
            'version' => '1.0.0',
            'revision' => 'gallery-r1',
        ]];
        $repository = self::repository(self::admission()->admit('default', $foreign));
        [$authority, $envelope] = self::scopedRequest(
            'studio.operation/artifact.publish',
            (object) ['reference' => (object) ['id' => $foreign->id, 'version' => '1.0.0']],
            StudioSessionMode::Blueprint,
            StudioResourceKind::Blueprint,
            $foreign->id,
            self::BLUEPRINT_CAPABILITIES,
            'product-card-r5',
        );
        $published = self::port($repository, self::refusingGuard())
            ->forRequest($authority)
            ->publish($envelope->arguments(), $envelope->context());
        self::assertSame($published->revision, $repository->head->revision);
        self::assertSame('published', $repository->head->status);
    }

    /**
     * Prove an unpublish without live unpublish authority is forbidden despite publish authority.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnpublishRequiresLiveUnpublishAuthority(): void
    {
        $request = StudioProducerRequest::authorized(
            'studio.operation/artifact.publish',
            (object) ['reference' => (object) ['id' => 'products/trail-backpack', 'version' => '1.0.0']],
            expectedRevision: 'entry-r7',
            capabilities: ['content.publish', 'content.read', 'content.update', 'studio.mode.content'],
            resource: 'products/trail-backpack',
        );

        try {
            self::port(self::repository(null))
                ->forRequest($request->authority)
                ->unpublish($request->arguments(), $request->context());
            self::fail('An unpublish without live unpublish authority must be refused.');
        } catch (HostRefusal $refused) {
            self::assertSame('forbidden', $refused->error()->category());
        }
    }

    /**
     * Prove a read-only session cannot smuggle a save through an authorized read dispatch.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSaveRequiresTheSavePermission(): void
    {
        [$authority, $envelope] = self::scopedRequest(
            'studio.operation/artifact.load',
            (object) ['reference' => (object) ['id' => 'content-read-only-test', 'version' => '1.0.0']],
            StudioSessionMode::ReadOnly,
            StudioResourceKind::Content,
            'content-read-only-test',
            ['content.read', 'studio.mode.read-only'],
        );

        try {
            self::port(self::repository(null))
                ->forRequest($authority)
                ->save($envelope->arguments(), $envelope->context());
            self::fail('A save without the save permission must be refused.');
        } catch (HostRefusal $refused) {
            self::assertSame('forbidden', $refused->error()->category());
        }
    }

    /**
     * Build one authorized publish request against the entry fixture coordinates.
     *
     * @param   string  $expectedRevision  Optimistic concurrency coordinate to carry.
     *
     * @return  StudioProducerRequest  Authorized request bound to the fixture resource.
     *
     * @since   2.0.0
     */
    private static function publishRequest(string $expectedRevision): StudioProducerRequest
    {
        return StudioProducerRequest::authorized(
            'studio.operation/artifact.publish',
            (object) ['reference' => (object) ['id' => 'products/trail-backpack', 'version' => '1.0.0']],
            expectedRevision: $expectedRevision,
            resource: 'products/trail-backpack',
        );
    }

    /**
     * Open and authorize one Producer request against a purpose-built session scope.
     *
     * @param   string              $capability        Closed Producer operation capability.
     * @param   mixed               $arguments         Candidate operation arguments.
     * @param   StudioSessionMode   $mode              Exact canonical authoring mode to open.
     * @param   StudioResourceKind  $kind              Content or Blueprint host resource family.
     * @param   string              $resourceId        Resource identifier the session binds.
     * @param   list<string>        $capabilities      Effective trusted App capabilities.
     * @param   string|null         $expectedRevision  Optional concurrency coordinate.
     *
     * @return  array{StudioProducerRequestAuthority, RequestEnvelope}  Authority and parsed envelope.
     *
     * @since   2.0.0
     */
    private static function scopedRequest(
        string $capability,
        mixed $arguments,
        StudioSessionMode $mode,
        StudioResourceKind $kind,
        string $resourceId,
        array $capabilities,
        ?string $expectedRevision = null,
    ): array {
        $repository = new class implements StudioHostSessionRepository {
            /**
             * Retained session.
             *
             * @var    StudioHostSession|null
             * @since  2.0.0
             */
            private ?StudioHostSession $session = null;

            /**
             * Retain the one opened session.
             *
             * @param   StudioHostSession  $session  Opened session to retain.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function add(StudioHostSession $session): void
            {
                $this->session = $session;
            }

            /**
             * Serve the retained session for its exact resource context key.
             *
             * @param   string  $resourceContextKey  Requested resource context key.
             *
             * @return  StudioHostSession|null  The retained session when its key matches exactly.
             *
             * @since   2.0.0
             */
            public function find(string $resourceContextKey): ?StudioHostSession
            {
                return $this->session?->resourceContextKey === $resourceContextKey ? $this->session : null;
            }
        };
        $keys = new class implements StudioResourceContextKeyFactory {
            /**
             * Allocate the one fixed deterministic resource context key.
             *
             * @return  string  One fixed deterministic resource context key.
             *
             * @since   2.0.0
             */
            public function create(): string
            {
                return 'contexts/artifact-port-test';
            }
        };
        $sessions = new StudioHostSessionAuthority(AuthorizationContext::gateway(), $repository, $keys);
        $context = AuthenticatedPrincipal::issueFromStrings(
            AuthorizationContext::provenance(),
            AuthorizationContext::SUBJECT,
            $capabilities,
        )->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'studio-artifact-port-test',
            surface: AuthenticatedSurface::Administrator,
            sessionId: 'administrator-artifact-port-test',
        );
        $snapshot = $sessions->open($context, $mode, $kind, $resourceId);
        $requestContext = (object) [
            'operationId' => $capability,
            'protocolVersion' => RequestEnvelope::WIRE_PROTOCOL_VERSION,
            'requestId' => 'requests/artifact-port-test',
            'resourceContextKey' => $snapshot->session->resourceContextKey,
            'sessionGeneration' => $snapshot->generation,
        ];
        if ($expectedRevision !== null) {
            $requestContext->expectedRevision = $expectedRevision;
        }
        $envelope = RequestEnvelope::parse(CanonicalJson::stringify((object) [
            'arguments' => $arguments,
            'context' => $requestContext,
        ]));
        $authority = new StudioProducerRequestAuthority($context, $sessions);
        if ($authority->authorize(OperationRegistry::byCapability($capability), $envelope) !== null) {
            throw new LogicException('The scoped artifact port test request was not authorized.');
        }

        return [$authority, $envelope];
    }

    /**
     * Bind the port under test over a repository, the shared admission and a publication guard.
     *
     * @param   StudioArtifactRepository             $artifacts    Artifact persistence double.
     * @param   StudioArtifactPublicationGuard|null  $publication  Guard double; permissive when null.
     *
     * @return  StudioArtifactHostPort  Unbound port ready for one request scope.
     *
     * @since   2.0.0
     */
    private static function port(
        StudioArtifactRepository $artifacts,
        ?StudioArtifactPublicationGuard $publication = null,
    ): StudioArtifactHostPort {
        return new StudioArtifactHostPort($artifacts, self::admission(), $publication ?? self::permissiveGuard());
    }

    /**
     * Return the shared admission boundary over Producer's vendored schema corpus.
     *
     * @return  StudioArtifactAdmission  Schema and active-content admission boundary.
     *
     * @since   2.0.0
     */
    private static function admission(): StudioArtifactAdmission
    {
        return self::$admission ??= new StudioArtifactAdmission(StudioDocumentSchemaRegistry::fromVendoredCorpus());
    }

    /**
     * Build one in-memory artifact repository double with a configurable write behavior.
     *
     * @param   StoredStudioArtifact|null  $head    Initial stored artifact head, or null for none.
     * @param   string                     $writes  One of 'accept', 'reject' or 'race'.
     *
     * @return  StudioArtifactRepository  Recording single-head repository double.
     *
     * @since   2.0.0
     */
    private static function repository(?StoredStudioArtifact $head, string $writes = 'accept'): StudioArtifactRepository
    {
        return new class ($head, $writes) implements StudioArtifactRepository {
            /**
             * Retain the mutable artifact head and the configured write behavior.
             *
             * @param   StoredStudioArtifact|null  $head    Initial stored artifact head, or null.
             * @param   string                     $writes  One of 'accept', 'reject' or 'race'.
             *
             * @since   2.0.0
             */
            public function __construct(public ?StoredStudioArtifact $head, private string $writes)
            {
            }

            /**
             * Serve the retained head for its identifier without discriminating on version.
             *
             * @param   string  $siteIdentifier  Site scope the read is bound to.
             * @param   string  $id              Requested artifact identifier.
             * @param   string  $version         Requested artifact version, ignored for drift tests.
             *
             * @return  ?StoredStudioArtifact  The retained head on an identifier match, null otherwise.
             *
             * @since   2.0.0
             */
            public function current(string $siteIdentifier, string $id, string $version): ?StoredStudioArtifact
            {
                unset($version);

                return $siteIdentifier === 'default' && $this->head !== null && $id === $this->head->id
                    ? $this->head
                    : null;
            }

            /**
             * Serve the retained head only when its exact revision is requested.
             *
             * @param   string  $siteIdentifier  Site scope the read is bound to.
             * @param   string  $id              Requested artifact identifier.
             * @param   string  $version         Requested artifact version.
             * @param   string  $revision        Requested exact revision authority.
             *
             * @return  ?StoredStudioArtifact  The retained head when its revision matches, null otherwise.
             *
             * @since   2.0.0
             */
            public function revision(
                string $siteIdentifier,
                string $id,
                string $version,
                string $revision,
            ): ?StoredStudioArtifact {
                return $this->head !== null && $revision === $this->head->revision
                    ? $this->current($siteIdentifier, $id, $version)
                    : null;
            }

            /**
             * Persist under the configured behavior: optimistic accept, rejection, or a race.
             *
             * @param   StoredStudioArtifact  $artifact         Candidate artifact head to persist.
             * @param   ?string               $expectedCurrent  Revision the caller believes is current.
             *
             * @return  bool  True only when accepting and the optimistic predecessor matched.
             *
             * @since   2.0.0
             */
            public function store(StoredStudioArtifact $artifact, ?string $expectedCurrent): bool
            {
                if ($this->writes === 'race') {
                    throw new StudioPersistenceRace('A concurrent claimant committed first.');
                }
                if ($this->writes === 'reject' || $expectedCurrent !== $this->head?->revision) {
                    return false;
                }
                $this->head = $artifact;

                return true;
            }
        };
    }

    /**
     * Build a publication guard double that accepts every Blueprint.
     *
     * @return  StudioArtifactPublicationGuard  Permissive guard double.
     *
     * @since   2.0.0
     */
    private static function permissiveGuard(): StudioArtifactPublicationGuard
    {
        return new class implements StudioArtifactPublicationGuard {
            /**
             * Accept every candidate Blueprint without inspection.
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
    }

    /**
     * Build a publication guard double that refuses every Blueprint as incompatible.
     *
     * @return  StudioArtifactPublicationGuard  Refusing guard double.
     *
     * @since   2.0.0
     */
    private static function refusingGuard(): StudioArtifactPublicationGuard
    {
        return new class implements StudioArtifactPublicationGuard {
            /**
             * Refuse every candidate Blueprint with a canonical incompatible refusal.
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
                StudioProducerError::refuse('incompatible', 'studio.blueprint/theme-incompatible');
            }
        };
    }

    /**
     * Construct one minimal stored artifact head directly, bypassing schema admission.
     *
     * @param   string  $kind      Closed Studio artifact kind.
     * @param   string  $status    Canonical lifecycle status to retain.
     * @param   string  $version   Stored artifact version coordinate.
     * @param   string  $revision  Stored immutable revision identity.
     * @param   string  $id        Stored artifact identity.
     *
     * @return  StoredStudioArtifact  Minimal internally consistent stored head.
     *
     * @since   2.0.0
     */
    private static function storedHead(
        string $kind,
        string $status,
        string $version = '1.0.0',
        string $revision = 'entry-r7',
        string $id = 'products/trail-backpack',
    ): StoredStudioArtifact {
        $document = (object) ['id' => $id, 'kind' => $kind, 'revision' => $revision, 'status' => $status];

        return new StoredStudioArtifact(
            'default',
            $id,
            $version,
            $kind,
            $revision,
            $status,
            CanonicalJson::stringify($document),
            '[]',
        );
    }

    /**
     * Decode a fresh copy of the vendored schema-valid entry fixture document.
     *
     * @return  stdClass  Mutable entry document copy.
     *
     * @since   2.0.0
     */
    private static function entryDocument(): stdClass
    {
        return self::fixture('fixtures/entry.product.example.json');
    }

    /**
     * Decode a fresh copy of the vendored schema-valid Blueprint fixture document.
     *
     * @return  stdClass  Mutable Blueprint document copy.
     *
     * @since   2.0.0
     */
    private static function blueprintDocument(): stdClass
    {
        return self::fixture('fixtures/blueprint.product.example.json');
    }

    /**
     * Decode a fresh copy of the vendored schema-valid content-model fixture document.
     *
     * @return  stdClass  Mutable content-model document copy.
     *
     * @since   2.0.0
     */
    private static function contentModelDocument(): stdClass
    {
        return self::fixture('fixtures/content-model.product.example.json');
    }

    /**
     * Decode one vendored testkit fixture into a mutable object document.
     *
     * @param   string  $path  Testkit-relative fixture path.
     *
     * @return  stdClass  Decoded fixture document.
     *
     * @since   2.0.0
     */
    private static function fixture(string $path): stdClass
    {
        $document = json_decode(StudioContractResources::testkitBytes($path), false, 64, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $document);

        return $document;
    }
}
