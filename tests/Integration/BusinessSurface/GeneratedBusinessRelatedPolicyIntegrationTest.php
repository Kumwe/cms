<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessSurface;

use Doctrine\DBAL\Connection;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\BusinessDefinition\Domain\PortalOperation;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\RelateRecordsCommand;
use Kumwe\App\BusinessRecord\Application\Command\ReorderRecordLinesCommand;
use Kumwe\App\BusinessRecord\Application\Command\UnrelateRecordsCommand;
use Kumwe\App\BusinessRecord\Application\Command\UpdateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordNotFound;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordValidationFailed;
use Kumwe\App\BusinessRecord\Application\Query\BrowseRecordsQuery;
use Kumwe\App\BusinessRecord\Application\Query\BrowseRelatedRecordsQuery;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\App\BusinessRecord\Application\Query\RecordHistoryQuery;
use Kumwe\App\BusinessRecord\Application\ValidationViolation;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordProjection;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\App\BusinessSurface\Application\BusinessRecordProjector;
use Kumwe\App\BusinessSurface\Application\BusinessSurface;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceCatalog;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceOperation;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceService;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Proves generated relation metadata, selectors, and writes share one nested target-policy boundary.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessSurfaceCatalog::class)]
#[CoversClass(BusinessSurfaceService::class)]
#[CoversClass(BusinessRecordService::class)]
#[CoversClass(BusinessRecordProjector::class)]
final class GeneratedBusinessRelatedPolicyIntegrationTest extends TestCase
{
    /**
     * Omits relation metadata when the nested target row or public identity is unavailable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCatalogOmitsRelationshipsWithoutUsableNestedTargetAccess(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $catalog = $container->get(BusinessSurfaceCatalog::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(BusinessSurfaceCatalog::class, $catalog);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $suffix = self::suffix();
        $target = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::relationTargetDocument($suffix, Uuid::uuid7()->toString()),
        );
        $line = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::ownedLineDocument($suffix, Uuid::uuid7()->toString()),
        );
        $owner = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::relationshipOwnerDocument(
                $suffix,
                Uuid::uuid7()->toString(),
                $target->handle,
                $line->handle,
            ),
        );
        self::assertContains('primary_target', self::relationshipHandles($catalog->definition(
            $context,
            BusinessSurface::Administrator,
            $owner->handle,
            BusinessSurfaceOperation::Relation,
        )));

        NeutralBusinessFixture::removeRecordAccess($container, $target->id);
        $always = ['type' => 'constant', 'value' => true];
        $fields = ['public_reference' => []];
        $policyCode = self::insertPolicy(
            $database,
            $tables,
            $target->id,
            'business.record.relate',
            $always,
            $fields,
            $context->actorId(),
        );

        try {
            self::assertNotContains('primary_target', self::relationshipHandles($catalog->definition(
                $context,
                BusinessSurface::Administrator,
                $owner->handle,
                BusinessSurfaceOperation::Relation,
            )));

            $fields = ['public_reference' => ['id']];
            self::updatePolicy($database, $tables, $policyCode, $always, $fields, 2);
            self::assertContains('primary_target', self::relationshipHandles($catalog->definition(
                $context,
                BusinessSurface::Administrator,
                $owner->handle,
                BusinessSurfaceOperation::Relation,
            )));

            $never = ['type' => 'constant', 'value' => false];
            self::updatePolicy($database, $tables, $policyCode, $never, $fields, 3);
            self::assertNotContains('primary_target', self::relationshipHandles($catalog->definition(
                $context,
                BusinessSurface::Administrator,
                $owner->handle,
                BusinessSurfaceOperation::Relation,
            )));
        } finally {
            $database->delete($tables->raw('resource_policies'), ['policy_code' => $policyCode]);
        }
    }

    /**
     * Refuses target choice enumeration when the source reference field is not writable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEntityReferenceSelectorRequiresTheSourceCreateFieldGrant(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        $catalog = $container->get(BusinessSurfaceCatalog::class);
        $surfaces = $container->get(BusinessSurfaceService::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(BusinessSurfaceCatalog::class, $catalog);
        self::assertInstanceOf(BusinessSurfaceService::class, $surfaces);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $suffix = self::suffix();
        $target = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::referenceTargetDocument($suffix, Uuid::uuid7()->toString()),
        );
        $owner = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::entityReferenceOwnerDocument(
                $suffix,
                Uuid::uuid7()->toString(),
                $target->handle,
            ),
        );
        $targetId = 'SELECTOR-' . strtoupper($suffix);
        $records->create(new CreateRecordCommand(
            $context,
            $target->handle,
            ['label' => 'Selector policy target'],
            NeutralBusinessFixture::idempotencyKey('selector-target-' . $suffix),
            recordId: $targetId,
        ));
        NeutralBusinessFixture::removeRecordAccess($container, $owner->id);
        $always = ['type' => 'constant', 'value' => true];
        $fields = ['create' => ['title']];
        $policyCode = self::insertPolicy(
            $database,
            $tables,
            $owner->id,
            'business.record.create',
            $always,
            $fields,
            $context->actorId(),
        );

        try {
            $metadata = $catalog->definition(
                $context,
                BusinessSurface::Administrator,
                $owner->handle,
                BusinessSurfaceOperation::Create,
            );
            self::assertNotContains('target_ref', self::fieldHandles($metadata));
            try {
                $surfaces->relationChoices(
                    $context,
                    BusinessSurface::Administrator,
                    $owner->handle,
                    'target_ref',
                    operation: BusinessSurfaceOperation::Create,
                );
                self::fail('A generated surface must not reopen a field omitted from its metadata.');
            } catch (BusinessRecordDefinitionUnavailable) {
                self::assertTrue(true);
            }
            try {
                $records->browseRelated(new BrowseRelatedRecordsQuery(
                    $context,
                    $owner->handle,
                    'target_ref',
                    'business.record.create',
                    null,
                    new RecordQuerySpecification(projection: new RecordProjection(['label'])),
                ));
                self::fail('A denied source field must not enumerate its target choices.');
            } catch (BusinessRecordNotFound) {
                self::assertTrue(true);
            }

            $fields = ['create' => ['title', 'target_ref']];
            self::updatePolicy($database, $tables, $policyCode, $always, $fields, 2);
            $choices = $records->browseRelated(new BrowseRelatedRecordsQuery(
                $context,
                $owner->handle,
                'target_ref',
                'business.record.create',
                null,
                new RecordQuerySpecification(projection: new RecordProjection(['label'])),
            ));
            self::assertCount(1, $choices->page->records);
            self::assertSame($targetId, $choices->page->records[0]->recordId);
        } finally {
            $database->delete($tables->raw('resource_policies'), ['policy_code' => $policyCode]);
        }
    }

    /**
     * Collapses validator failures for hidden definition fields without losing visible field errors.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCreateValidationDoesNotRevealFieldsOmittedByTheOperationPlan(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $suffix = self::suffix();
        $target = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::referenceTargetDocument($suffix, Uuid::uuid7()->toString()),
        );
        $owner = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::entityReferenceOwnerDocument(
                $suffix,
                Uuid::uuid7()->toString(),
                $target->handle,
            ),
        );
        NeutralBusinessFixture::removeRecordAccess($container, $owner->id);
        $always = ['type' => 'constant', 'value' => true];
        $policyCode = self::insertPolicy(
            $database,
            $tables,
            $owner->id,
            'business.record.create',
            $always,
            ['create' => ['title']],
            $context->actorId(),
        );

        try {
            try {
                $records->create(new CreateRecordCommand(
                    $context,
                    $owner->handle,
                    ['title' => 7],
                    NeutralBusinessFixture::idempotencyKey('hidden-validation-' . $suffix),
                    recordId: Uuid::uuid7()->toString(),
                ));
                self::fail('A malformed visible field and missing hidden field must fail validation.');
            } catch (BusinessRecordValidationFailed $exception) {
                self::assertSame(['title', 'record'], array_map(
                    static fn (ValidationViolation $violation): string => $violation->field,
                    $exception->violations,
                ));
                self::assertSame('field_access', $exception->violations[1]->code);
                self::assertNotContains('target_ref', array_map(
                    static fn (ValidationViolation $violation): string => $violation->field,
                    $exception->violations,
                ));
            }

            try {
                $records->create(new CreateRecordCommand(
                    $context,
                    $owner->handle,
                    ['title' => 'Invalid hidden identity'],
                    NeutralBusinessFixture::idempotencyKey('hidden-identity-' . $suffix),
                    recordId: 'not-a-uuid',
                ));
                self::fail('A malformed server-owned identity must fail without naming its hidden field.');
            } catch (BusinessRecordValidationFailed $exception) {
                self::assertCount(1, $exception->violations);
                self::assertSame('record', $exception->violations[0]->field);
                self::assertSame('field_access', $exception->violations[0]->code);
            }
        } finally {
            $database->delete($tables->raw('resource_policies'), ['policy_code' => $policyCode]);
        }
    }

    /**
     * Requires the selector's public-identity grant for relate, unrelate, and reorder writes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRelationshipMutationsRejectKnownIdsHiddenFromTheSelector(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $suffix = self::suffix();
        $target = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::relationTargetDocument($suffix, Uuid::uuid7()->toString()),
        );
        $line = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::ownedLineDocument($suffix, Uuid::uuid7()->toString()),
        );
        $owner = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::relationshipOwnerDocument(
                $suffix,
                Uuid::uuid7()->toString(),
                $target->handle,
                $line->handle,
            ),
        );
        $firstTarget = Uuid::uuid7()->toString();
        $secondTarget = Uuid::uuid7()->toString();
        foreach ([$firstTarget, $secondTarget] as $index => $targetId) {
            $records->create(new CreateRecordCommand(
                $context,
                $target->handle,
                ['label' => 'Mutation target ' . ($index + 1)],
                NeutralBusinessFixture::idempotencyKey('mutation-target-' . $index . '-' . $suffix),
                recordId: $targetId,
            ));
        }
        $ownerId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $owner->handle,
            ['title' => 'Relationship mutation owner'],
            NeutralBusinessFixture::idempotencyKey('mutation-owner-' . $suffix),
            recordId: $ownerId,
        ));
        NeutralBusinessFixture::removeRecordAccess($container, $target->id);
        $always = ['type' => 'constant', 'value' => true];
        $hiddenIdentity = ['public_reference' => []];
        $policyCode = self::insertPolicy(
            $database,
            $tables,
            $target->id,
            'business.record.relate',
            $always,
            $hiddenIdentity,
            $context->actorId(),
        );

        try {
            self::assertNotFound(static fn () => $records->relate(new RelateRecordsCommand(
                $context,
                $owner->handle,
                $ownerId,
                1,
                'tags',
                $firstTarget,
                NeutralBusinessFixture::idempotencyKey('hidden-relate-' . $suffix),
            )));

            $visibleIdentity = ['public_reference' => ['id']];
            self::updatePolicy($database, $tables, $policyCode, $always, $visibleIdentity, 2);
            $version = $records->relate(new RelateRecordsCommand(
                $context,
                $owner->handle,
                $ownerId,
                1,
                'tags',
                $firstTarget,
                NeutralBusinessFixture::idempotencyKey('visible-relate-' . $suffix),
            ))->version;
            self::assertSame(2, $version);

            self::updatePolicy($database, $tables, $policyCode, $always, $hiddenIdentity, 3);
            self::assertNotFound(static fn () => $records->unrelate(new UnrelateRecordsCommand(
                $context,
                $owner->handle,
                $ownerId,
                $version,
                'tags',
                $firstTarget,
                NeutralBusinessFixture::idempotencyKey('hidden-unrelate-' . $suffix),
            )));

            self::updatePolicy($database, $tables, $policyCode, $always, $visibleIdentity, 4);
            $version = $records->unrelate(new UnrelateRecordsCommand(
                $context,
                $owner->handle,
                $ownerId,
                $version,
                'tags',
                $firstTarget,
                NeutralBusinessFixture::idempotencyKey('visible-unrelate-' . $suffix),
            ))->version;
            $version = $records->relate(new RelateRecordsCommand(
                $context,
                $owner->handle,
                $ownerId,
                $version,
                'tags',
                $firstTarget,
                NeutralBusinessFixture::idempotencyKey('reorder-first-' . $suffix),
            ))->version;
            $version = $records->relate(new RelateRecordsCommand(
                $context,
                $owner->handle,
                $ownerId,
                $version,
                'tags',
                $secondTarget,
                NeutralBusinessFixture::idempotencyKey('reorder-second-' . $suffix),
            ))->version;

            self::updatePolicy($database, $tables, $policyCode, $always, $hiddenIdentity, 5);
            self::assertNotFound(static fn () => $records->reorder(new ReorderRecordLinesCommand(
                $context,
                $owner->handle,
                $ownerId,
                $version,
                'tags',
                [$secondTarget, $firstTarget],
                NeutralBusinessFixture::idempotencyKey('hidden-reorder-' . $suffix),
            )));

            self::updatePolicy($database, $tables, $policyCode, $always, $visibleIdentity, 6);
            self::assertSame($version + 1, $records->reorder(new ReorderRecordLinesCommand(
                $context,
                $owner->handle,
                $ownerId,
                $version,
                'tags',
                [$secondTarget, $firstTarget],
                NeutralBusinessFixture::idempotencyKey('visible-reorder-' . $suffix),
            ))->version);
        } finally {
            $database->delete($tables->raw('resource_policies'), ['policy_code' => $policyCode]);
        }
    }

    /**
     * Redacts direct read and browse references and empties includes until target identity is public.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRecordProjectionsRequireNestedTargetRowAndPublicIdentityAccess(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        $projector = $container->get(BusinessRecordProjector::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(BusinessRecordProjector::class, $projector);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $suffix = self::suffix();
        $target = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::referenceTargetDocument($suffix, Uuid::uuid7()->toString()),
        );
        $line = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::ownedLineDocument($suffix, Uuid::uuid7()->toString()),
        );
        $relationshipOwner = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::relationshipOwnerDocument(
                $suffix,
                Uuid::uuid7()->toString(),
                $target->handle,
                $line->handle,
            ),
        );
        $referenceOwner = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::entityReferenceOwnerDocument(
                $suffix,
                Uuid::uuid7()->toString(),
                $target->handle,
            ),
        );
        $targetId = 'DISCLOSE-' . strtoupper($suffix);
        $targetResult = $records->create(new CreateRecordCommand(
            $context,
            $target->handle,
            ['label' => 'Projection target'],
            NeutralBusinessFixture::idempotencyKey('projection-target-' . $suffix),
            recordId: $targetId,
        ));
        self::assertNotSame($targetId, $targetResult->recordKey);
        $relationshipOwnerId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $relationshipOwner->handle,
            ['title' => 'Projection relationship owner'],
            NeutralBusinessFixture::idempotencyKey('projection-relation-owner-' . $suffix),
            recordId: $relationshipOwnerId,
        ));
        $records->relate(new RelateRecordsCommand(
            $context,
            $relationshipOwner->handle,
            $relationshipOwnerId,
            1,
            'tags',
            $targetId,
            NeutralBusinessFixture::idempotencyKey('projection-relate-' . $suffix),
        ));
        $referenceOwnerId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $referenceOwner->handle,
            ['title' => 'Projection reference owner', 'target_ref' => $targetId],
            NeutralBusinessFixture::idempotencyKey('projection-reference-owner-' . $suffix),
            recordId: $referenceOwnerId,
        ));
        NeutralBusinessFixture::removeRecordAccess($container, $target->id);
        $always = ['type' => 'constant', 'value' => true];
        $fields = ['public_reference' => []];
        $readPolicy = self::insertPolicy(
            $database,
            $tables,
            $target->id,
            'business.record.read',
            $always,
            $fields,
            $context->actorId(),
        );
        $browsePolicy = self::insertPolicy(
            $database,
            $tables,
            $target->id,
            'business.record.browse',
            $always,
            $fields,
            $context->actorId(),
        );

        try {
            $read = $records->read(new ReadRecordQuery(
                $context,
                $referenceOwner->handle,
                $referenceOwnerId,
                projection: ['target_ref'],
            ));
            self::assertArrayNotHasKey('target_ref', $read->values);
            self::assertArrayNotHasKey('target_ref', $projector->record($read)['values']);

            $includedRead = $records->read(new ReadRecordQuery(
                $context,
                $relationshipOwner->handle,
                $relationshipOwnerId,
                includes: ['tags'],
            ));
            self::assertSame([], $includedRead->includes['tags']);

            $browse = $records->browse(new BrowseRecordsQuery(
                $context,
                $referenceOwner->handle,
                new RecordQuerySpecification(projection: new RecordProjection(['target_ref'])),
            ));
            self::assertArrayNotHasKey('target_ref', $browse->records[0]->values);
            self::assertArrayNotHasKey('target_ref', $projector->browse($browse)['items'][0]['values']);

            $includedBrowse = $records->browse(new BrowseRecordsQuery(
                $context,
                $relationshipOwner->handle,
                new RecordQuerySpecification(projection: new RecordProjection([], ['tags'])),
            ));
            self::assertSame([], $includedBrowse->records[0]->includes['tags']);

            $history = $records->history(new RecordHistoryQuery(
                $context,
                $referenceOwner->handle,
                $referenceOwnerId,
            ));
            self::assertArrayNotHasKey('target_ref', $history->revisions[0]->snapshot);
            self::assertArrayNotHasKey('target_ref', $projector->history($history)['items'][0]['snapshot']);

            $fields = ['public_reference' => ['code']];
            self::updatePolicy($database, $tables, $readPolicy, $always, $fields, 2);
            self::updatePolicy($database, $tables, $browsePolicy, $always, $fields, 2);
            self::assertSame($targetId, $records->read(new ReadRecordQuery(
                $context,
                $referenceOwner->handle,
                $referenceOwnerId,
                projection: ['target_ref'],
            ))->values['target_ref']);
            $includedRead = $records->read(new ReadRecordQuery(
                $context,
                $relationshipOwner->handle,
                $relationshipOwnerId,
                includes: ['tags'],
            ));
            self::assertSame($targetId, $includedRead->includes['tags'][0]->recordId);

            $never = ['type' => 'constant', 'value' => false];
            self::updatePolicy($database, $tables, $readPolicy, $never, $fields, 3);
            self::updatePolicy($database, $tables, $browsePolicy, $never, $fields, 3);
            self::assertArrayNotHasKey('target_ref', $records->read(new ReadRecordQuery(
                $context,
                $referenceOwner->handle,
                $referenceOwnerId,
                projection: ['target_ref'],
            ))->values);
            self::assertSame([], $records->read(new ReadRecordQuery(
                $context,
                $relationshipOwner->handle,
                $relationshipOwnerId,
                includes: ['tags'],
            ))->includes['tags']);
        } finally {
            $database->delete($tables->raw('resource_policies'), ['policy_code' => $browsePolicy]);
            $database->delete($tables->raw('resource_policies'), ['policy_code' => $readPolicy]);
        }
    }

    /**
     * Keeps portal traversal default-deny when targets omit their exact portal operation grants.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPortalRequiresTargetExposureAndOperationsForSelectorsReadsIncludesAndWrites(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $administrator = TestKernelFactory::administratorContext($container);
        $principal = $administrator->principal();
        self::assertNotNull($principal);
        $portal = $principal->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'related-policy-portal-' . self::suffix(),
            surface: AuthenticatedSurface::Portal,
        );
        $records = $container->get(BusinessRecordService::class);
        $surfaces = $container->get(BusinessSurfaceService::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(BusinessSurfaceService::class, $surfaces);
        $suffix = self::suffix();
        $targetDocument = NeutralBusinessFixture::relationTargetDocument(
            $suffix,
            Uuid::uuid7()->toString(),
        );
        $targetDocument['portal_exposure'] = true;
        $targetDocument['portal_operations'] = [];
        $target = NeutralBusinessFixture::install(
            $container,
            $administrator,
            $targetDocument,
        );
        $line = NeutralBusinessFixture::install(
            $container,
            $administrator,
            NeutralBusinessFixture::ownedLineDocument($suffix, Uuid::uuid7()->toString()),
        );
        $ownerDocument = NeutralBusinessFixture::relationshipOwnerDocument(
            $suffix,
            Uuid::uuid7()->toString(),
            $target->handle,
            $line->handle,
        );
        $owner = NeutralBusinessFixture::install(
            $container,
            $administrator,
            self::portalSource($ownerDocument),
        );
        $referenceDocument = NeutralBusinessFixture::entityReferenceOwnerDocument(
            $suffix,
            Uuid::uuid7()->toString(),
            $target->handle,
        );
        $reference = NeutralBusinessFixture::install(
            $container,
            $administrator,
            self::portalSource($referenceDocument),
        );
        $firstTarget = Uuid::uuid7()->toString();
        $secondTarget = Uuid::uuid7()->toString();
        foreach ([$firstTarget, $secondTarget] as $index => $targetId) {
            $records->create(new CreateRecordCommand(
                $administrator,
                $target->handle,
                ['label' => 'Portal-hidden target ' . ($index + 1)],
                NeutralBusinessFixture::idempotencyKey('portal-target-' . $index . '-' . $suffix),
                recordId: $targetId,
            ));
        }
        $ownerId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $administrator,
            $owner->handle,
            ['title' => 'Portal relation source'],
            NeutralBusinessFixture::idempotencyKey('portal-source-' . $suffix),
            recordId: $ownerId,
        ));
        $version = $records->relate(new RelateRecordsCommand(
            $administrator,
            $owner->handle,
            $ownerId,
            1,
            'tags',
            $firstTarget,
            NeutralBusinessFixture::idempotencyKey('portal-admin-first-' . $suffix),
        ))->version;
        $version = $records->relate(new RelateRecordsCommand(
            $administrator,
            $owner->handle,
            $ownerId,
            $version,
            'tags',
            $secondTarget,
            NeutralBusinessFixture::idempotencyKey('portal-admin-second-' . $suffix),
        ))->version;
        $referenceId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $administrator,
            $reference->handle,
            ['title' => 'Portal reference source', 'target_ref' => $firstTarget],
            NeutralBusinessFixture::idempotencyKey('portal-reference-' . $suffix),
            recordId: $referenceId,
        ));

        try {
            $surfaces->relationChoices(
                $portal,
                BusinessSurface::Portal,
                $owner->handle,
                'tags',
                $ownerId,
                BusinessSurfaceOperation::Relation,
            );
            self::fail('A portal selector must not traverse a target without its browse operation.');
        } catch (BusinessRecordDefinitionUnavailable) {
            self::assertTrue(true);
        }
        try {
            $surfaces->browse($portal, BusinessSurface::Portal, $owner->handle, [
                'projection' => ['includes' => ['tags']],
            ]);
            self::fail('A raw portal include must not reopen a relationship omitted from metadata.');
        } catch (BusinessRecordDefinitionUnavailable) {
            self::assertTrue(true);
        }
        self::assertNotFound(static fn () => $records->read(new ReadRecordQuery(
            $portal,
            $owner->handle,
            $ownerId,
            includes: ['tags'],
        )));
        self::assertNotFound(static fn () => $records->browse(new BrowseRecordsQuery(
            $portal,
            $owner->handle,
            new RecordQuerySpecification(projection: new RecordProjection(includes: ['tags'])),
        )));
        $read = $surfaces->read($portal, BusinessSurface::Portal, $reference->handle, $referenceId);
        $record = $read['record'] ?? null;
        self::assertIsArray($record);
        $values = $record['values'] ?? null;
        self::assertIsArray($values);
        self::assertArrayNotHasKey('target_ref', $values);

        self::assertDefinitionUnavailable(static fn () => $surfaces->relate(
            $portal,
            BusinessSurface::Portal,
            $owner->handle,
            $ownerId,
            $version,
            'tags',
            $firstTarget,
            'portal-surface-hidden-relate-' . $suffix,
        ));
        self::assertDefinitionUnavailable(static fn () => $surfaces->unrelate(
            $portal,
            BusinessSurface::Portal,
            $owner->handle,
            $ownerId,
            $version,
            'tags',
            $firstTarget,
            'portal-surface-hidden-unrelate-' . $suffix,
        ));
        self::assertDefinitionUnavailable(static fn () => $surfaces->reorder(
            $portal,
            BusinessSurface::Portal,
            $owner->handle,
            $ownerId,
            $version,
            'tags',
            [$secondTarget, $firstTarget],
            'portal-surface-hidden-reorder-' . $suffix,
        ));
        self::assertDefinitionUnavailable(static fn () => $surfaces->create(
            $portal,
            BusinessSurface::Portal,
            $reference->handle,
            ['title' => 'Denied surface reference', 'target_ref' => $firstTarget],
            'portal-surface-hidden-reference-create-' . $suffix,
            Uuid::uuid7()->toString(),
        ));
        self::assertDefinitionUnavailable(static fn () => $surfaces->update(
            $portal,
            BusinessSurface::Portal,
            $reference->handle,
            $referenceId,
            1,
            ['target_ref' => $secondTarget],
            'portal-surface-hidden-reference-update-' . $suffix,
        ));

        self::assertNotFound(static fn () => $records->relate(new RelateRecordsCommand(
            $portal,
            $owner->handle,
            $ownerId,
            $version,
            'tags',
            $firstTarget,
            NeutralBusinessFixture::idempotencyKey('portal-hidden-relate-' . $suffix),
        )));
        self::assertNotFound(static fn () => $records->unrelate(new UnrelateRecordsCommand(
            $portal,
            $owner->handle,
            $ownerId,
            $version,
            'tags',
            $firstTarget,
            NeutralBusinessFixture::idempotencyKey('portal-hidden-unrelate-' . $suffix),
        )));
        self::assertNotFound(static fn () => $records->reorder(new ReorderRecordLinesCommand(
            $portal,
            $owner->handle,
            $ownerId,
            $version,
            'tags',
            [$secondTarget, $firstTarget],
            NeutralBusinessFixture::idempotencyKey('portal-hidden-reorder-' . $suffix),
        )));
        self::assertReferenceValidation(static fn () => $records->create(new CreateRecordCommand(
            $portal,
            $reference->handle,
            ['title' => 'Denied portal reference', 'target_ref' => $firstTarget],
            NeutralBusinessFixture::idempotencyKey('portal-hidden-reference-create-' . $suffix),
            recordId: Uuid::uuid7()->toString(),
        )));
        self::assertReferenceValidation(static fn () => $records->update(new UpdateRecordCommand(
            $portal,
            $reference->handle,
            $referenceId,
            1,
            ['target_ref' => $secondTarget],
            NeutralBusinessFixture::idempotencyKey('portal-hidden-reference-update-' . $suffix),
        )));
    }

    /**
     * Lets a portal read an exposed relationship section without advertising relation mutations.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPortalReadOnlyRelationshipHydratesWithoutRelationOperation(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $administrator = TestKernelFactory::administratorContext($container);
        $principal = $administrator->principal();
        self::assertNotNull($principal);
        $portal = $principal->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'read-only-relation-' . self::suffix(),
            surface: AuthenticatedSurface::Portal,
        );
        $records = $container->get(BusinessRecordService::class);
        $surfaces = $container->get(BusinessSurfaceService::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(BusinessSurfaceService::class, $surfaces);
        $suffix = self::suffix();
        $targetDocument = NeutralBusinessFixture::relationTargetDocument(
            $suffix,
            Uuid::uuid7()->toString(),
        );
        $targetDocument['portal_exposure'] = true;
        $targetDocument['portal_operations'] = [PortalOperation::Read->value];
        $target = NeutralBusinessFixture::install($container, $administrator, $targetDocument);
        $line = NeutralBusinessFixture::install(
            $container,
            $administrator,
            NeutralBusinessFixture::ownedLineDocument($suffix, Uuid::uuid7()->toString()),
        );
        $ownerDocument = NeutralBusinessFixture::relationshipOwnerDocument(
            $suffix,
            Uuid::uuid7()->toString(),
            $target->handle,
            $line->handle,
        );
        $ownerDocument['portal_exposure'] = true;
        $ownerDocument['portal_operations'] = [PortalOperation::Read->value];
        $owner = NeutralBusinessFixture::install($container, $administrator, $ownerDocument);
        $targetId = Uuid::uuid7()->toString();
        $ownerId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $administrator,
            $target->handle,
            ['label' => 'Read-only relation target'],
            NeutralBusinessFixture::idempotencyKey('read-only-target-' . $suffix),
            recordId: $targetId,
        ));
        $records->create(new CreateRecordCommand(
            $administrator,
            $owner->handle,
            ['title' => 'Read-only relation owner'],
            NeutralBusinessFixture::idempotencyKey('read-only-owner-' . $suffix),
            recordId: $ownerId,
        ));
        $records->relate(new RelateRecordsCommand(
            $administrator,
            $owner->handle,
            $ownerId,
            1,
            'tags',
            $targetId,
            NeutralBusinessFixture::idempotencyKey('read-only-relate-' . $suffix),
        ));

        $result = $surfaces->relationship(
            $portal,
            BusinessSurface::Portal,
            $owner->handle,
            $ownerId,
            'tags',
        );
        $record = $result['record'] ?? null;
        self::assertIsArray($record);
        self::assertCount(1, $record['includes']['tags'] ?? []);
        self::assertArrayNotHasKey('relation', $result['available_operations']);
    }

    /**
     * Enable every source-side portal operation without changing a target definition's exposure.
     *
     * @param   array<string, mixed>  $document  Neutral source definition document.
     *
     * @return  array<string, mixed>  Source document with explicit complete portal opt-in.
     *
     * @since   2.0.0
     */
    private static function portalSource(array $document): array
    {
        $document['portal_exposure'] = true;
        $document['portal_operations'] = array_map(
            static fn (PortalOperation $operation): string => $operation->value,
            PortalOperation::cases(),
        );

        return $document;
    }

    /**
     * Assert an entity-reference write reports the same generic reference violation as an absent target.
     *
     * @param   callable(): mixed  $operation  Reference mutation expected to fail before persistence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertReferenceValidation(callable $operation): void
    {
        try {
            $operation();
        } catch (BusinessRecordValidationFailed $exception) {
            self::assertSame('target_ref', $exception->violations[0]->field);
            self::assertSame('reference', $exception->violations[0]->code);

            return;
        }

        self::fail('A portal-hidden entity-reference target must be rejected as an unavailable reference.');
    }

    /**
     * Assert a generated surface refuses a metadata-omitted reference or relationship handle.
     *
     * @param   callable(): mixed  $operation  Surface call expected to fail before record delegation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertDefinitionUnavailable(callable $operation): void
    {
        try {
            $operation();
        } catch (BusinessRecordDefinitionUnavailable) {
            self::assertTrue(true);

            return;
        }

        self::fail('A generated surface must refuse a related handle omitted from its metadata.');
    }

    /**
     * Return relationship handles from one safe catalog document.
     *
     * @param   array<string, mixed>  $metadata  Policy-filtered definition metadata.
     *
     * @return  list<string>  Relationship handles in declaration order.
     *
     * @since   2.0.0
     */
    private static function relationshipHandles(array $metadata): array
    {
        $relationships = $metadata['relationships'] ?? null;
        self::assertIsArray($relationships);

        $handles = [];
        foreach ($relationships as $relationship) {
            self::assertIsArray($relationship);
            $handle = $relationship['handle'] ?? null;
            self::assertIsString($handle);
            $handles[] = $handle;
        }

        return $handles;
    }

    /**
     * Return field handles from one safe catalog document.
     *
     * @param   array<string, mixed>  $metadata  Policy-filtered definition metadata.
     *
     * @return  list<string>  Field handles in declaration order.
     *
     * @since   2.0.0
     */
    private static function fieldHandles(array $metadata): array
    {
        $fields = $metadata['fields'] ?? null;
        self::assertIsArray($fields);
        $handles = [];
        foreach ($fields as $field) {
            self::assertIsArray($field);
            $handle = $field['handle'] ?? null;
            self::assertIsString($handle);
            $handles[] = $handle;
        }

        return $handles;
    }

    /**
     * Assert a policy-hidden identity produces the canonical non-enumerating result.
     *
     * @param   callable(): mixed  $operation  Mutation expected to fail before writing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertNotFound(callable $operation): void
    {
        try {
            $operation();
        } catch (BusinessRecordNotFound) {
            self::assertTrue(true);

            return;
        }

        self::fail('A policy-hidden relationship target must be indistinguishable from a missing target.');
    }

    /**
     * Insert one definition-specific allow policy in canonical stored form.
     *
     * @param   Connection           $database      Integration database.
     * @param   TableNames           $tables        Portable table names.
     * @param   string               $definitionId  Protected definition UUID.
     * @param   string               $operation     Exact record operation.
     * @param   array<string,mixed>  $ast           Canonical row predicate.
     * @param   array<string,mixed>  $fields        Explicit field rules.
     * @param   string               $actorId       Policy author identity.
     *
     * @return  string  Unique policy code for updates and cleanup.
     *
     * @since   2.0.0
     */
    private static function insertPolicy(
        Connection $database,
        TableNames $tables,
        string $definitionId,
        string $operation,
        array $ast,
        array $fields,
        string $actorId,
    ): string {
        $policyCode = 'test.business.surface.related.' . Uuid::uuid7()->toString();
        $database->insert($tables->raw('resource_policies'), [
            'id' => Uuid::uuid7()->toString(),
            'policy_code' => $policyCode,
            'owner_kind' => 'core',
            'owner_identifier' => 'core',
            'capability_code' => $operation,
            'resource_type' => 'business_record',
            'action' => $operation,
            'effect' => 'allow',
            'scope_type' => 'global',
            'organization_id' => null,
            'entity_definition_id' => $definitionId,
            'canonical_ast' => CanonicalDefinitionJson::encode($ast),
            'field_rules' => CanonicalDefinitionJson::encode($fields),
            'ast_checksum' => CanonicalDefinitionJson::checksum(['ast' => $ast, 'fields' => $fields]),
            'policy_version' => 1,
            'priority' => 10,
            'status' => 'active',
            'created_by' => $actorId,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        return $policyCode;
    }

    /**
     * Replace a test policy's predicate and field rules with a new canonical version.
     *
     * @param   Connection           $database    Integration database.
     * @param   TableNames           $tables      Portable table names.
     * @param   string               $policyCode  Policy row to update.
     * @param   array<string,mixed>  $ast         Replacement row predicate.
     * @param   array<string,mixed>  $fields      Replacement field rules.
     * @param   int                  $version     Strictly increasing policy version.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function updatePolicy(
        Connection $database,
        TableNames $tables,
        string $policyCode,
        array $ast,
        array $fields,
        int $version,
    ): void {
        $database->update($tables->raw('resource_policies'), [
            'canonical_ast' => CanonicalDefinitionJson::encode($ast),
            'field_rules' => CanonicalDefinitionJson::encode($fields),
            'ast_checksum' => CanonicalDefinitionJson::checksum(['ast' => $ast, 'fields' => $fields]),
            'policy_version' => $version,
        ], ['policy_code' => $policyCode]);
    }

    /**
     * Produce a short unique suffix accepted by neutral definition handles and idempotency keys.
     *
     * @return  string  Twelve lowercase hexadecimal characters.
     *
     * @since   2.0.0
     */
    private static function suffix(): string
    {
        return strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
    }
}
