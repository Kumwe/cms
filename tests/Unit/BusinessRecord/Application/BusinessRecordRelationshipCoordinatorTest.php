<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessRecord\Application;

use DateTimeImmutable;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\BusinessDefinition\Domain\DeleteBehavior;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\App\BusinessDefinition\Domain\PortalOperation;
use Kumwe\App\BusinessDefinition\Domain\RelationshipDefinition;
use Kumwe\App\BusinessDefinition\Domain\RelationshipKind;
use Kumwe\App\BusinessDefinition\Domain\ScopeMode;
use Kumwe\App\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\App\BusinessRecord\Application\BusinessRecordMutationFence;
use Kumwe\App\BusinessRecord\Application\BusinessRecordMutationGeneration;
use Kumwe\App\BusinessRecord\Application\BusinessRecordReadRepository;
use Kumwe\App\BusinessRecord\Application\BusinessRecordRelationshipCoordinator;
use Kumwe\App\BusinessRecord\Application\Command\DocumentLineInput;
use Kumwe\App\BusinessRecord\Application\Command\DocumentWriteIntent;
use Kumwe\App\BusinessRecord\Application\Command\RelateRecordsCommand;
use Kumwe\App\BusinessRecord\Application\Command\WriteDocumentCommand;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordNotFound;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordReferenceConflict;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordValidationFailed;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRelationshipRejected;
use Kumwe\App\BusinessRecord\Application\OwnedLineCreateIntent;
use Kumwe\App\BusinessRecord\Application\OwnedLineMutationIntent;
use Kumwe\App\BusinessRecord\Application\OwnedLineReferenceIndex;
use Kumwe\App\BusinessRecord\Application\OwnedLineWrite;
use Kumwe\App\BusinessRecord\Application\RecordRuleValidator;
use Kumwe\App\BusinessRecord\Application\RecordValueCodec;
use Kumwe\App\BusinessRecord\Application\ResolvedBusinessDefinition;
use Kumwe\App\BusinessRecord\Application\StoredOwnedLine;
use Kumwe\App\BusinessRecord\Application\StoredRecordIdentity;
use Kumwe\App\BusinessRecord\Domain\BusinessRecord;
use Kumwe\App\BusinessRecord\Domain\RecordScope;
use Kumwe\App\BusinessSchema\Domain\PhysicalColumnBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalSchemaBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalTableBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalTableKind;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallation;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessPlan;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicyConstant;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicySchema;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicySet;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\Conversion\Decimal\ExactDecimal;
use Kumwe\Extension\Spi\Application\Automation\IdempotencyKey;
use Kumwe\Extension\Spi\BusinessSecurity\Application\FieldAccessUsage;
use Kumwe\Extension\Spi\BusinessSecurity\Application\FieldDisclosurePlan;
use Kumwe\Secret\Cipher\SodiumEnvelopeCipher;
use Kumwe\Secret\Value\KeyMaterial;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Characterizes the relationship decisions extracted from the stable business-record facade.
 *
 * These cases pin both public single-line behavior and the whole-document intent used by the aggregate
 * command: identity normalization, nested field and row policy, dense order, unchanged rows, removals,
 * renumbering, scope refusal and the exact pinned line generation all remain decided before persistence.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessRecordRelationshipCoordinator::class)]
#[CoversClass(OwnedLineCreateIntent::class)]
#[CoversClass(OwnedLineMutationIntent::class)]
#[CoversClass(OwnedLineReferenceIndex::class)]
#[CoversClass(OwnedLineWrite::class)]
final class BusinessRecordRelationshipCoordinatorTest extends TestCase
{
    /**
     * Reference-target fences sort on the fence's own lock key, never on the field that points at it.
     *
     * The fence is a row lock on the target definition, so two documents naming the same pair of targets
     * through differently-named fields must acquire that pair identically — sorting by field handle would
     * let them deadlock against each other. The field handle only breaks ties between fields that share
     * one target, where the order stops mattering for locking and merely has to be deterministic.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReferenceFencesAreOrderedByTheirLockKey(): void
    {
        self::assertSame(
            ['zulu_field', 'alpha_field', 'mike_field'],
            BusinessRecordRelationshipCoordinator::referenceAcquisitionOrder([
                'alpha_field' => 'target_bravo',
                'mike_field' => 'target_bravo',
                'zulu_field' => 'target_alpha',
            ]),
            'Fences must be acquired in target order, with the field handle only breaking ties.',
        );
        self::assertSame([], BusinessRecordRelationshipCoordinator::referenceAcquisitionOrder([]));
    }

    /**
     * A line that exists, must be written, and differs only in position reports itself as moved-only.
     *
     * The distinction is what lets the write side renumber a reordered collection set-based instead of
     * rewriting every surviving row's full column list, so each of the three ingredients — stored, has to
     * be written, values unchanged — has to flip the answer on its own.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOnlyAStoredUnchangedModifiedLineIsMovedOnly(): void
    {
        self::assertTrue((new OwnedLineWrite('k', 'r', 1, [], 3, true, false))->movedOnly());
        self::assertFalse(
            (new OwnedLineWrite('k', 'r', 1, [], null, true, true))->movedOnly(),
            'A new line is inserted, never renumbered.',
        );
        self::assertFalse(
            (new OwnedLineWrite('k', 'r', 1, [], 3, false, false))->movedOnly(),
            'An untouched line is not written at all.',
        );
        self::assertFalse(
            (new OwnedLineWrite('k', 'r', 1, [], 3, true, true))->movedOnly(),
            'A line whose values changed needs its own payload.',
        );
    }

    /**
     * The reference index answers keys for what resolved and replays the failure for what did not.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheReferenceIndexAnswersKeysAndReplaysFailures(): void
    {
        $failure = new BusinessRecordNotFound();
        $index = new OwnedLineReferenceIndex(
            ['client' => ['C-1' => 'stored-key-1']],
            ['blocked' => $failure],
        );

        self::assertSame('stored-key-1', $index->key('client', 'C-1'));
        self::assertNull($index->key('client', 'C-2'));
        self::assertNull($index->key('unknown', 'C-1'));
        self::assertSame($failure, $index->failure('blocked'));
        self::assertNull($index->failure('client'));
        self::assertNull(OwnedLineReferenceIndex::empty()->key('client', 'C-1'));
    }

    /**
     * Stable published header definition identity used throughout the characterization.
     *
     * @var    string
     * @since  2.0.0
     */
    private const HEADER_ID = '0191574f-f0b8-7bf3-a9aa-91c6b8245a10';

    /**
     * Stable published line definition identity used throughout the characterization.
     *
     * @var    string
     * @since  2.0.0
     */
    private const LINE_ID = '0191574f-f0b8-7bf3-a9aa-91c6b8245a11';

    /**
     * Stable document identity used by amendment cases.
     *
     * @var    string
     * @since  2.0.0
     */
    private const DOCUMENT_ID = '0191574f-f0b8-7bf3-a9aa-91c6b8245a12';

    /**
     * First stable owned-line identity.
     *
     * @var    string
     * @since  2.0.0
     */
    private const LINE_A = '0191574f-f0b8-7bf3-a9aa-91c6b8245a13';

    /**
     * Second stable owned-line identity.
     *
     * @var    string
     * @since  2.0.0
     */
    private const LINE_B = '0191574f-f0b8-7bf3-a9aa-91c6b8245a14';

    /**
     * Third stable owned-line identity.
     *
     * @var    string
     * @since  2.0.0
     */
    private const LINE_C = '0191574f-f0b8-7bf3-a9aa-91c6b8245a15';

    /**
     * Stable reference-identity line definition used to prove normalized collision handling.
     *
     * @var    string
     * @since  2.0.0
     */
    private const REFERENCE_LINE_ID = '0191574f-f0b8-7bf3-a9aa-91c6b8245a16';

    /**
     * Stable owner definition for the reference-identity line fixture.
     *
     * @var    string
     * @since  2.0.0
     */
    private const REFERENCE_OWNER_ID = '0191574f-f0b8-7bf3-a9aa-91c6b8245a17';

    /**
     * Stable entity-reference target definition used by owned-line resolution cases.
     *
     * @var    string
     * @since  2.0.0
     */
    private const TARGET_ID = '0191574f-f0b8-7bf3-a9aa-91c6b8245a18';

    /**
     * Stable owned-line definition carrying the entity-reference field under test.
     *
     * @var    string
     * @since  2.0.0
     */
    private const REFERENCE_FIELD_LINE_ID = '0191574f-f0b8-7bf3-a9aa-91c6b8245a19';

    /**
     * Stable owner definition for the entity-reference line fixture.
     *
     * @var    string
     * @since  2.0.0
     */
    private const REFERENCE_FIELD_OWNER_ID = '0191574f-f0b8-7bf3-a9aa-91c6b8245a20';

    /**
     * A create becomes one typed, dense intent before any repository write can run.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDocumentCreateProducesOneTypedDenseIntent(): void
    {
        [$owner, $line] = $this->resolvedDefinitions();
        $reads = $this->createMock(BusinessRecordReadRepository::class);
        $reads->expects(self::never())->method('ownedLinesForDocumentIntegrity');
        $coordinator = $this->coordinator($reads, $line);

        $intent = $coordinator->prepareDocumentMutation(
            $this->documentCommand([
                new DocumentLineInput($this->submittedLine('A', 'Alpha', '1.25'), self::LINE_A),
                new DocumentLineInput($this->submittedLine('B', 'Beta', '2.50'), self::LINE_B),
            ]),
            $owner,
            $this->scope(),
            null,
            $this->ownerAccess($owner->definition, $line->definition),
        );

        self::assertSame(RelationshipKind::OwnedLineCollection, $intent->relationship->kind);
        self::assertSame($line, $intent->line);
        self::assertSame([], $intent->removed);
        self::assertFalse($intent->renumber);
        self::assertSame([0, 1], array_map(
            static fn (OwnedLineWrite $write): int => $write->position,
            $intent->writes,
        ));
        self::assertSame(
            [self::LINE_A, self::LINE_B],
            array_map(static fn (OwnedLineWrite $write): string => $write->recordKey, $intent->writes),
        );
        self::assertSame([null, null], array_map(
            static fn (OwnedLineWrite $write): ?int => $write->storedVersion,
            $intent->writes,
        ));
        self::assertSame(
            [['id' => self::LINE_A, 'code' => 'A', 'description' => 'Alpha', 'amount' => '1.25'],
                ['id' => self::LINE_B, 'code' => 'B', 'description' => 'Beta', 'amount' => '2.50']],
            $intent->invariantValues(),
        );
    }

    /**
     * An unchanged survivor stays unwritten while an omitted stored line becomes an explicit removal.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDocumentAmendPreservesAnUnchangedLineAndRemovesTheOmittedLine(): void
    {
        [$owner, $line] = $this->resolvedDefinitions();
        $stored = [
            $this->storedLine(self::LINE_A, 0, 'A', 'Alpha', '1.25'),
            $this->storedLine(self::LINE_B, 1, 'B', 'Beta', '2.50'),
        ];
        $reads = $this->createMock(BusinessRecordReadRepository::class);
        $reads->expects(self::once())->method('ownedLinesForDocumentIntegrity')->willReturn($stored);

        $intent = $this->coordinator($reads, $line)->prepareDocumentMutation(
            $this->documentCommand(
                [new DocumentLineInput([], self::LINE_A)],
                DocumentWriteIntent::Amend,
            ),
            $owner,
            $this->scope(),
            $this->record($owner->definition, self::DOCUMENT_ID),
            $this->ownerAccess($owner->definition, $line->definition),
        );

        self::assertFalse($intent->renumber);
        self::assertSame([self::LINE_B], $intent->removed);
        self::assertCount(1, $intent->writes);
        self::assertFalse($intent->writes[0]->modified);
        self::assertSame(3, $intent->writes[0]->storedVersion);
    }

    /**
     * Moving one survivor requests deterministic two-pass renumbering for every retained existing row.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDocumentAmendTurnsAReorderIntoAnExplicitRenumberIntent(): void
    {
        [$owner, $line] = $this->resolvedDefinitions();
        $reads = $this->createMock(BusinessRecordReadRepository::class);
        $reads->method('ownedLinesForDocumentIntegrity')->willReturn([
            $this->storedLine(self::LINE_A, 0, 'A', 'Alpha', '1.25'),
            $this->storedLine(self::LINE_B, 1, 'B', 'Beta', '2.50'),
        ]);

        $intent = $this->coordinator($reads, $line)->prepareDocumentMutation(
            $this->documentCommand([
                new DocumentLineInput([], self::LINE_B),
                new DocumentLineInput([], self::LINE_A),
            ], DocumentWriteIntent::Amend),
            $owner,
            $this->scope(),
            $this->record($owner->definition, self::DOCUMENT_ID),
            $this->ownerAccess($owner->definition, $line->definition),
        );

        self::assertTrue($intent->renumber);
        self::assertSame([self::LINE_B, self::LINE_A], array_map(
            static fn (OwnedLineWrite $write): string => $write->recordId,
            $intent->writes,
        ));
        self::assertSame([0, 1], array_map(
            static fn (OwnedLineWrite $write): int => $write->position,
            $intent->writes,
        ));
        self::assertSame([true, true], array_map(
            static fn (OwnedLineWrite $write): bool => $write->modified,
            $intent->writes,
        ));
    }

    /**
     * A hidden stored line refuses a whole replacement instead of being deleted from a filtered view.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDocumentAmendRefusesWhenNestedPolicyHidesAStoredLine(): void
    {
        [$owner, $line] = $this->resolvedDefinitions();
        $reads = $this->createMock(BusinessRecordReadRepository::class);
        $reads->method('ownedLinesForDocumentIntegrity')->willReturn([
            $this->storedLine(self::LINE_A, 0, 'A', 'Alpha', '1.25'),
        ]);

        $this->expectException(BusinessRecordNotFound::class);
        $this->coordinator($reads, $line)->prepareDocumentMutation(
            $this->documentCommand([], DocumentWriteIntent::Amend),
            $owner,
            $this->scope(),
            $this->record($owner->definition, self::DOCUMENT_ID),
            $this->ownerAccess($owner->definition, $line->definition, false),
        );
    }

    /**
     * The existing single-line relate behavior produces the same pinned and normalized line decision.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSingleLineRelateProducesAPinnedOwnedLineIntent(): void
    {
        [$owner, $line] = $this->resolvedDefinitions();
        $reads = $this->createMock(BusinessRecordReadRepository::class);
        $access = $this->ownerAccess($owner->definition, $line->definition)->related('lines');
        self::assertNotNull($access);
        $command = new RelateRecordsCommand(
            self::context(),
            $owner->definition->handle,
            self::DOCUMENT_ID,
            1,
            'lines',
            self::LINE_C,
            IdempotencyKey::fromString('relationship-coordinator-relate'),
            targetValues: $this->submittedLine('C', 'Gamma', '3.75'),
        );

        $intent = $this->coordinator($reads, $line)->prepareOwnedLineCreate(
            $command,
            $owner,
            $this->scope(),
            $owner->definition->runtimeRelationship('lines')
                ?? throw new BusinessRelationshipRejected('The fixture relationship is missing.'),
            $access,
        );

        self::assertSame($line, $intent->line);
        self::assertSame(self::LINE_C, $intent->recordKey);
        self::assertSame(self::LINE_C, $intent->recordId);
        $amount = $intent->values['amount'] ?? null;
        self::assertInstanceOf(ExactDecimal::class, $amount);
        self::assertSame('3.75', $amount->value());
    }

    /**
     * Detach and reorder resolve an owned line only inside the named owner and nested policy.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOwnedLineIdentityBecomesTheStorageKeyForSingleLineMutation(): void
    {
        [$owner, $line] = $this->resolvedDefinitions();
        $reads = $this->createMock(BusinessRecordReadRepository::class);
        $reads->expects(self::exactly(3))->method('ownedLineIdentity')->willReturnOnConsecutiveCalls(
            new StoredRecordIdentity(self::LINE_B, self::LINE_B, 1, 3),
            new StoredRecordIdentity(self::LINE_A, self::LINE_A, 1, 3),
            new StoredRecordIdentity(self::LINE_B, self::LINE_B, 1, 3),
        );
        $access = $this->ownerAccess($owner->definition, $line->definition)->related('lines');
        self::assertNotNull($access);

        $key = $this->coordinator($reads, $line)->ownedLineKey(
            self::context(),
            $owner,
            $this->record($owner->definition, self::DOCUMENT_ID),
            $owner->definition->runtimeRelationship('lines')
                ?? throw new BusinessRelationshipRejected('The fixture relationship is missing.'),
            $access,
            self::LINE_B,
            PortalOperation::Reorder,
        );

        self::assertSame(self::LINE_B, $key);
        self::assertSame([self::LINE_A, self::LINE_B], $this->coordinator($reads, $line)->ownedLineKeys(
            self::context(),
            $owner,
            $this->record($owner->definition, self::DOCUMENT_ID),
            $owner->definition->runtimeRelationship('lines')
                ?? throw new BusinessRelationshipRejected('The fixture relationship is missing.'),
            $access,
            [self::LINE_A, self::LINE_B],
            PortalOperation::Reorder,
        ));
    }

    /**
     * An ordinary relationship target must pass its nested row decision and occupy the source scope.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExistingTargetValidationPreservesNestedPolicyAndScopeIsolation(): void
    {
        [$owner, $line] = $this->resolvedDefinitions();
        $coordinator = $this->coordinator($this->createStub(BusinessRecordReadRepository::class), $line);
        $source = $this->record($owner->definition, self::DOCUMENT_ID);
        $target = $this->record($line->definition, self::LINE_A);

        $coordinator->assertExistingTarget(
            self::context(),
            $source,
            $line->definition,
            $target,
            $this->lineAccess($line->definition),
            PortalOperation::Relation,
        );
        self::assertTrue(true);

        $otherSite = $this->record(
            $line->definition,
            self::LINE_B,
            RecordScope::forDefinition(ScopeMode::Site, SiteContext::fromString('other'), null),
        );
        $this->expectException(BusinessRecordReferenceConflict::class);
        $coordinator->assertExistingTarget(
            self::context(),
            $source,
            $line->definition,
            $otherSite,
            $this->lineAccess($line->definition),
            PortalOperation::Relation,
        );
    }

    /**
     * Definition lookup and normalized-key refusal remain stable at the extracted boundary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRelationshipLookupAndDuplicateNormalizedKeysRefuseByName(): void
    {
        [$owner, $line] = $this->resolvedDefinitions();
        $coordinator = $this->coordinator($this->createStub(BusinessRecordReadRepository::class), $line);

        self::assertSame(
            RelationshipKind::OwnedLineCollection,
            $coordinator->relationship($owner->definition, 'lines')->kind,
        );
        self::assertSame(
            [$line->definition->handle, $coordinator->relationship($owner->definition, 'lines')],
            $coordinator->relatedTarget($owner->definition, 'lines'),
        );
        self::assertTrue($coordinator->inputFieldAvailable(
            $this->field($line->definition, 'code'),
            FieldAccessUsage::Create,
        ));
        self::assertFalse($coordinator->inputFieldAvailable(
            $this->field($line->definition, 'id'),
            FieldAccessUsage::Create,
        ));

        $this->expectException(BusinessRelationshipRejected::class);
        $coordinator->assertUniqueTargetKeys([self::LINE_A, self::LINE_A]);
    }

    /**
     * Selector, portal, nested identity and row decisions fail closed at the coordinator boundary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSelectorAndNestedTargetRefusalsRemainIndistinguishable(): void
    {
        [$owner, $line] = $this->resolvedDefinitions();
        $coordinator = $this->coordinator($this->createStub(BusinessRecordReadRepository::class), $line);
        $unboundDocument = NeutralBusinessFixture::documentLineDocument('unbound', self::REFERENCE_LINE_ID);
        $unboundDocument['fields'][] = [
            'handle' => 'unbound_target',
            'label' => 'Unbound target',
            'type' => 'core.entity_reference',
        ];
        $unbound = EntityTypeDefinition::fromArray($unboundDocument)->published(1);

        $this->assertRefusal(
            BusinessRecordNotFound::class,
            null,
            static fn () => $coordinator->relatedTarget($unbound, 'unbound_target'),
        );
        $this->assertRefusal(
            BusinessRecordNotFound::class,
            null,
            static fn () => $coordinator->relatedTarget($owner->definition, 'undeclared'),
        );
        $this->assertRefusal(
            BusinessRelationshipRejected::class,
            'The relationship is not declared by the pinned definition.',
            static fn () => $coordinator->relationship($owner->definition, 'undeclared'),
        );
        $this->assertRefusal(
            BusinessRecordNotFound::class,
            null,
            fn () => $coordinator->assertPortalTargetOperation(
                self::portalContext(),
                $line->definition,
                PortalOperation::Read,
            ),
        );
        $this->assertRefusal(
            BusinessRecordNotFound::class,
            null,
            fn () => $coordinator->assertRelatedTargetAccess(
                $line->definition,
                $this->ownerAccess($owner->definition, $line->definition),
            ),
        );
        $this->assertRefusal(
            BusinessRecordNotFound::class,
            null,
            fn () => $coordinator->assertTargetRow(
                $this->record($line->definition, self::LINE_A),
                $this->lineAccess($line->definition, false),
            ),
        );
    }

    /**
     * A single embedded line refuses the wrong relationship, field, identity, values and row policy.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOwnedLineCreateCharacterizesEveryPrePersistenceRefusal(): void
    {
        [$owner, $line] = $this->resolvedDefinitions();
        $coordinator = $this->coordinator($this->createStub(BusinessRecordReadRepository::class), $line);
        $relationship = $coordinator->relationship($owner->definition, 'lines');
        $access = $this->lineAccess($line->definition);
        $ordinary = new RelationshipDefinition(
            'targets',
            'Targets',
            RelationshipKind::ManyToMany,
            $line->definition->handle,
            ordered: true,
            onDelete: DeleteBehavior::Restrict,
        );

        $this->assertRefusal(
            BusinessRelationshipRejected::class,
            'Only an owned-line relationship accepts embedded values.',
            fn () => $coordinator->prepareOwnedLineCreate(
                $this->relateCommand($this->submittedLine('C', 'Gamma', '3.75')),
                $owner,
                $this->scope(),
                $ordinary,
                $access,
            ),
        );
        $this->assertRefusal(
            BusinessRecordValidationFailed::class,
            'The business record failed validation.',
            fn () => $coordinator->prepareOwnedLineCreate(
                $this->relateCommand([
                    ...$this->submittedLine('C', 'Gamma', '3.75'),
                    'unavailable' => 'withheld',
                ]),
                $owner,
                $this->scope(),
                $relationship,
                $access,
            ),
        );
        $this->assertRefusal(
            BusinessRelationshipRejected::class,
            'A UUID business-record identity is invalid.',
            fn () => $coordinator->prepareOwnedLineCreate(
                $this->relateCommand($this->submittedLine('C', 'Gamma', '3.75'), 'not-a-uuid'),
                $owner,
                $this->scope(),
                $relationship,
                $access,
            ),
        );
        $this->assertRefusal(
            BusinessRecordValidationFailed::class,
            'The business record failed validation.',
            fn () => $coordinator->prepareOwnedLineCreate(
                $this->relateCommand(['code' => 'C']),
                $owner,
                $this->scope(),
                $relationship,
                $access,
            ),
        );
        $this->assertRefusal(
            BusinessRecordNotFound::class,
            null,
            fn () => $coordinator->prepareOwnedLineCreate(
                $this->relateCommand($this->submittedLine('C', 'Gamma', '3.75')),
                $owner,
                $this->scope(),
                $relationship,
                $this->lineAccess($line->definition, false),
            ),
        );
    }

    /**
     * Owned-line writes require both the generated table and its exact target-definition pin.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOwnedLineCreateRefusesIncompleteInstalledTableContracts(): void
    {
        [$owner, $line] = $this->resolvedDefinitions();
        $relationship = $owner->definition->runtimeRelationship('lines')
            ?? throw new BusinessRelationshipRejected('The fixture relationship is missing.');
        $command = $this->relateCommand($this->submittedLine('C', 'Gamma', '3.75'));
        $access = $this->lineAccess($line->definition);
        $coordinator = $this->coordinator($this->createStub(BusinessRecordReadRepository::class), $line);

        $this->assertRefusal(
            BusinessRelationshipRejected::class,
            'The owned-line table is unavailable.',
            fn () => $coordinator->prepareOwnedLineCreate(
                $command,
                $this->resolved($owner->definition),
                $this->scope(),
                $relationship,
                $access,
            ),
        );
        $this->assertRefusal(
            BusinessRelationshipRejected::class,
            'The owned-line pinned definition version is unavailable.',
            fn () => $coordinator->prepareOwnedLineCreate(
                $command,
                $this->resolved($owner->definition, $line->definition->definitionVersion, false),
                $this->scope(),
                $relationship,
                $access,
            ),
        );
    }

    /**
     * Whole-document preparation refuses invalid relationship shape, line schema, identity, values and policy.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDocumentPreparationCharacterizesStructuralAndLineRefusals(): void
    {
        [$owner, $line] = $this->resolvedDefinitions();
        $ordinaryDocument = NeutralBusinessFixture::documentHeaderDocument(
            'ordinary',
            self::HEADER_ID,
            $line->definition->handle,
            withAggregateInvariants: false,
        );
        $ordinaryDocument['relationships'][0]['kind'] = RelationshipKind::ManyToMany->value;
        $ordinaryDocument['relationships'][0]['on_delete'] = DeleteBehavior::Restrict->value;
        $ordinaryOwner = $this->resolved(
            EntityTypeDefinition::fromArray($ordinaryDocument)->published(1),
            $line->definition->definitionVersion,
        );
        $coordinator = $this->coordinator($this->createStub(BusinessRecordReadRepository::class), $line);
        $this->assertRefusal(
            BusinessRelationshipRejected::class,
            'A document is written over a declared owned-line collection.',
            fn () => $coordinator->prepareDocumentMutation(
                $this->documentCommand([]),
                $ordinaryOwner,
                $this->scope(),
                null,
                $this->ownerAccess($ordinaryOwner->definition, $line->definition),
            ),
        );

        $nestedDocument = NeutralBusinessFixture::documentHeaderDocument(
            'nestedline',
            self::REFERENCE_LINE_ID,
            $line->definition->handle,
        );
        $nestedLine = $this->resolved(EntityTypeDefinition::fromArray($nestedDocument)->published(1));
        $this->assertRefusal(
            BusinessRelationshipRejected::class,
            'A line type declaring its own aggregate invariant needs a command that writes its lines too.',
            fn () => $this->coordinator(
                $this->createStub(BusinessRecordReadRepository::class),
                $nestedLine,
            )->prepareDocumentMutation(
                $this->documentCommand([]),
                $owner,
                $this->scope(),
                null,
                $this->ownerAccess($owner->definition, $nestedLine->definition),
            ),
        );

        $oversizedReads = $this->createMock(BusinessRecordReadRepository::class);
        $oversizedReads->expects(self::once())->method('ownedLinesForDocumentIntegrity')->willReturn(array_fill(
            0,
            WriteDocumentCommand::MAXIMUM_LINES + 1,
            $this->storedLine(self::LINE_A, 0, 'A', 'Alpha', '1.25'),
        ));
        $this->assertRefusal(
            BusinessRelationshipRejected::class,
            'The stored document holds more lines than one command may write.',
            fn () => $this->coordinator($oversizedReads, $line)->prepareDocumentMutation(
                $this->documentCommand([], DocumentWriteIntent::Amend),
                $owner,
                $this->scope(),
                $this->record($owner->definition, self::DOCUMENT_ID),
                $this->ownerAccess($owner->definition, $line->definition),
            ),
        );

        [$referenceOwner, $referenceLine] = $this->referenceIdentityDefinitions();
        $referenceCoordinator = $this->coordinator(
            $this->createStub(BusinessRecordReadRepository::class),
            $referenceLine,
        );
        $referenceAccess = $this->ownerAccess($referenceOwner->definition, $referenceLine->definition);
        $this->assertRefusal(
            BusinessRelationshipRejected::class,
            'A reference-identity record requires an explicit identity.',
            fn () => $referenceCoordinator->prepareDocumentMutation(
                $this->documentCommand([
                    new DocumentLineInput($this->submittedLine('A', 'Alpha', '1.25')),
                ]),
                $referenceOwner,
                $this->scope(),
                null,
                $referenceAccess,
            ),
        );
        $this->assertRefusal(
            BusinessRelationshipRejected::class,
            'A document names one line identity more than once.',
            fn () => $referenceCoordinator->prepareDocumentMutation(
                $this->documentCommand([
                    new DocumentLineInput([
                        'line_number' => ' duplicate ',
                        ...$this->submittedLine('A', 'Alpha', '1.25'),
                    ]),
                    new DocumentLineInput([
                        'line_number' => 'DUPLICATE',
                        ...$this->submittedLine('B', 'Beta', '2.50'),
                    ]),
                ]),
                $referenceOwner,
                $this->scope(),
                null,
                $referenceAccess,
            ),
        );

        $this->assertRefusal(
            BusinessRecordValidationFailed::class,
            'The business record failed validation.',
            fn () => $coordinator->prepareDocumentMutation(
                $this->documentCommand([new DocumentLineInput(['code' => 'A'], self::LINE_A)]),
                $owner,
                $this->scope(),
                null,
                $this->ownerAccess($owner->definition, $line->definition),
            ),
        );
        $this->assertRefusal(
            BusinessRecordNotFound::class,
            null,
            fn () => $coordinator->prepareDocumentMutation(
                $this->documentCommand([
                    new DocumentLineInput($this->submittedLine('A', 'Alpha', '1.25'), self::LINE_A),
                ]),
                $owner,
                $this->scope(),
                null,
                $this->ownerAccess($owner->definition, $line->definition, false),
            ),
        );
    }

    /**
     * Aggregate reads preserve prepared and absent collections and refuse an oversized stored collection.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInvariantCollectionGatherHonorsTheCommandCeiling(): void
    {
        $lineDefinition = EntityTypeDefinition::fromArray(NeutralBusinessFixture::documentLineDocument(
            'invariant',
            self::LINE_ID,
        ))->published(1);
        $ownerDefinition = EntityTypeDefinition::fromArray(NeutralBusinessFixture::documentHeaderDocument(
            'invariant',
            self::HEADER_ID,
            $lineDefinition->handle,
        ))->published(1);
        $line = $this->resolved($lineDefinition);
        $owner = $this->resolved($ownerDefinition, $lineDefinition->definitionVersion);
        $reads = $this->createMock(BusinessRecordReadRepository::class);
        $reads->expects(self::once())->method('ownedLinesForDocumentIntegrity')->willReturn(array_fill(
            0,
            WriteDocumentCommand::MAXIMUM_LINES + 1,
            $this->storedLine(self::LINE_A, 0, 'A', 'Alpha', '1.25'),
        ));
        $coordinator = $this->coordinator($reads, $line);

        self::assertSame(['lines' => [['amount' => '1.25']]], $coordinator->invariantLineValues(
            self::context(),
            $owner,
            null,
            ['lines' => [['amount' => '1.25']]],
        ));
        self::assertSame(['lines' => []], $coordinator->invariantLineValues(self::context(), $owner, null));
        $this->assertRefusal(
            BusinessRelationshipRejected::class,
            'A document holds more lines than one aggregate invariant may reduce.',
            fn () => $coordinator->invariantLineValues(
                self::context(),
                $owner,
                $this->record($ownerDefinition, self::DOCUMENT_ID),
            ),
        );
    }

    /**
     * Entity references require nested authority, a scoped target and an existing identity before storage.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOwnedLineEntityReferenceResolutionFailsClosedAndStoresOnlyTheTargetKey(): void
    {
        [$owner, $line, $target] = $this->entityReferenceDefinitions();
        $relationship = $owner->definition->runtimeRelationship('lines')
            ?? throw new BusinessRelationshipRejected('The fixture relationship is missing.');
        $command = $this->relateCommand([
            ...$this->submittedLine('C', 'Gamma', '3.75'),
            'product' => ' target-001 ',
        ]);
        $withoutTarget = $this->lineAccess($line->definition);
        $this->assertRefusal(
            BusinessRecordValidationFailed::class,
            'The business record failed validation.',
            fn () => $this->coordinator(
                $this->createStub(BusinessRecordReadRepository::class),
                $line,
                $target,
            )->prepareOwnedLineCreate($command, $owner, $this->scope(), $relationship, $withoutTarget),
        );

        $targetAccess = $this->targetAccess($target->definition);
        $access = $this->lineAccess($line->definition, true, ['product' => $targetAccess]);
        $this->assertRefusal(
            BusinessRecordValidationFailed::class,
            'The business record failed validation.',
            fn () => $this->coordinator(
                $this->createStub(BusinessRecordReadRepository::class),
                $line,
                $target,
            )->prepareOwnedLineCreate(
                $this->relateCommand([
                    ...$this->submittedLine('C', 'Gamma', '3.75'),
                    'product' => ['invalid-reference-shape'],
                ]),
                $owner,
                $this->scope(),
                $relationship,
                $access,
            ),
        );
        $missingReads = $this->createMock(BusinessRecordReadRepository::class);
        $missingReads->expects(self::once())->method('identity')->willReturn(null);
        $this->assertRefusal(
            BusinessRecordValidationFailed::class,
            'The business record failed validation.',
            fn () => $this->coordinator($missingReads, $line, $target)->prepareOwnedLineCreate(
                $command,
                $owner,
                $this->scope(),
                $relationship,
                $access,
            ),
        );

        $scopeReads = $this->createMock(BusinessRecordReadRepository::class);
        $scopeReads->expects(self::never())->method('identity');
        $this->assertRefusal(
            BusinessRecordValidationFailed::class,
            'The business record failed validation.',
            fn () => $this->coordinator($scopeReads, $line, $target)->prepareOwnedLineCreate(
                $command,
                $owner,
                RecordScope::forDefinition(ScopeMode::SiteOrganization, SiteContext::default(), 'acme'),
                $relationship,
                $access,
            ),
        );

        $resolvedReads = $this->createMock(BusinessRecordReadRepository::class);
        $resolvedReads->expects(self::once())->method('identity')->willReturn(
            new StoredRecordIdentity(self::LINE_A, 'TARGET-001', 1, 1),
        );
        $intent = $this->coordinator($resolvedReads, $line, $target)->prepareOwnedLineCreate(
            $command,
            $owner,
            $this->scope(),
            $relationship,
            $access,
        );

        self::assertSame(self::LINE_A, $intent->values['product']);
    }

    /**
     * A document resolves every line's entity references in one batch instead of one read per line.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDocumentReferenceResolutionBatchesTheCollectionAndStoresTargetKeys(): void
    {
        [$owner, $line, $target] = $this->entityReferenceDefinitions();
        $reads = $this->createMock(BusinessRecordReadRepository::class);
        $reads->expects(self::never())->method('identity');
        $reads->expects(self::once())->method('identities')->willReturnCallback(
            static function (
                ResolvedBusinessDefinition $resolved,
                RecordScope $scope,
                BusinessRecordAccessPlan $access,
                array $recordIds,
            ): array {
                sort($recordIds);
                self::assertSame(['TARGET-001', 'TARGET-002'], $recordIds);

                return [
                    'TARGET-001' => new StoredRecordIdentity(self::LINE_A, 'TARGET-001', 1, 1),
                    'TARGET-002' => new StoredRecordIdentity(self::LINE_B, 'TARGET-002', 1, 1),
                ];
            },
        );

        $intent = $this->coordinator($reads, $line, $target)->prepareDocumentMutation(
            $this->documentCommand([
                new DocumentLineInput([...$this->submittedLine('A', 'Alpha', '1.25'), 'product' => ' target-001 ']),
                new DocumentLineInput([...$this->submittedLine('B', 'Beta', '2.50'), 'product' => ' target-002 ']),
                new DocumentLineInput($this->submittedLine('C', 'Gamma', '3.75')),
                new DocumentLineInput([...$this->submittedLine('D', 'Delta', '4.00'), 'product' => null]),
            ]),
            $owner,
            $this->scope(),
            null,
            $this->ownerAccess(
                $owner->definition,
                $line->definition,
                related: ['product' => $this->targetAccess($target->definition)],
            ),
        );

        self::assertSame(
            [self::LINE_A, self::LINE_B, null, null],
            array_map(
                static fn (OwnedLineWrite $write): mixed => $write->values['product'] ?? null,
                $intent->writes,
            ),
            'Submitted references resolve to storage keys; absent and null references stay unresolved.',
        );
    }

    /**
     * A batch reference failure replays per line: policy and existence refuse, infrastructure rethrows.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDocumentReferenceResolutionReplaysBatchFailuresPerLine(): void
    {
        [$owner, $line, $target] = $this->entityReferenceDefinitions();
        $referenceLine = new DocumentLineInput([
            ...$this->submittedLine('A', 'Alpha', '1.25'),
            'product' => ' target-001 ',
        ]);

        $blindReads = $this->createMock(BusinessRecordReadRepository::class);
        $blindReads->expects(self::never())->method('identities');
        $this->assertRefusal(
            BusinessRecordValidationFailed::class,
            'The business record failed validation.',
            fn () => $this->coordinator($blindReads, $line, $target)->prepareDocumentMutation(
                $this->documentCommand([$referenceLine]),
                $owner,
                $this->scope(),
                null,
                $this->ownerAccess($owner->definition, $line->definition),
            ),
        );

        $access = $this->ownerAccess(
            $owner->definition,
            $line->definition,
            related: ['product' => $this->targetAccess($target->definition)],
        );
        $emptyReads = $this->createMock(BusinessRecordReadRepository::class);
        $emptyReads->expects(self::once())->method('identities')->willReturn([]);
        $this->assertRefusal(
            BusinessRecordValidationFailed::class,
            'The business record failed validation.',
            fn () => $this->coordinator($emptyReads, $line, $target)->prepareDocumentMutation(
                $this->documentCommand([
                    new DocumentLineInput([...$this->submittedLine('A', 'Alpha', '1.25'), 'product' => 'missing-001']),
                    new DocumentLineInput([
                        ...$this->submittedLine('B', 'Beta', '2.50'),
                        'product' => str_repeat('X', 96),
                    ]),
                    new DocumentLineInput(['code' => 'C']),
                ]),
                $owner,
                $this->scope(),
                null,
                $access,
            ),
        );

        $brokenReads = $this->createMock(BusinessRecordReadRepository::class);
        $brokenReads->expects(self::once())->method('identities')->willThrowException(
            new RuntimeException('The reference lookup failed.'),
        );
        $this->assertRefusal(
            RuntimeException::class,
            'The reference lookup failed.',
            fn () => $this->coordinator($brokenReads, $line, $target)->prepareDocumentMutation(
                $this->documentCommand([$referenceLine]),
                $owner,
                $this->scope(),
                null,
                $access,
            ),
        );

        [$targetlessOwner, $targetlessLine] = $this->entityReferenceDefinitions(true);
        $targetlessReads = $this->createMock(BusinessRecordReadRepository::class);
        $targetlessReads->expects(self::never())->method('identities');
        $this->assertRefusal(
            BusinessRecordValidationFailed::class,
            'The business record failed validation.',
            fn () => $this->coordinator($targetlessReads, $targetlessLine)->prepareDocumentMutation(
                $this->documentCommand([
                    new DocumentLineInput([...$this->submittedLine('A', 'Alpha', '1.25'), 'accessory' => 'ANY']),
                ]),
                $targetlessOwner,
                $this->scope(),
                null,
                $this->ownerAccess($targetlessOwner->definition, $targetlessLine->definition),
            ),
        );
    }

    /**
     * Build an owner whose line identity is authored and normalized instead of generated.
     *
     * @return  array{ResolvedBusinessDefinition, ResolvedBusinessDefinition}  Owner then line definition.
     *
     * @since   2.0.0
     */
    private function referenceIdentityDefinitions(): array
    {
        $lineDocument = NeutralBusinessFixture::documentLineDocument('coordref', self::REFERENCE_LINE_ID);
        $lineDocument['identity_strategy'] = 'reference';
        $lineDocument['fields'][0] = [
            'handle' => 'line_number',
            'label' => 'Line number',
            'type' => 'core.reference_identity',
            'required' => true,
            'nullable' => false,
            'length' => 80,
            'normalizers' => ['trim', 'uppercase'],
            'unique' => true,
            'indexed' => true,
            'immutable_after_create' => true,
        ];
        $lineDefinition = EntityTypeDefinition::fromArray($lineDocument)->published(1);
        $ownerDefinition = EntityTypeDefinition::fromArray(NeutralBusinessFixture::documentHeaderDocument(
            'coordref',
            self::REFERENCE_OWNER_ID,
            $lineDefinition->handle,
            withAggregateInvariants: false,
        ))->published(1);

        return [
            $this->resolved($ownerDefinition, $lineDefinition->definitionVersion),
            $this->resolved($lineDefinition),
        ];
    }

    /**
     * Build an owner line whose product field resolves a reference-identity target.
     *
     * @param   bool  $withTargetlessField  Whether the line also declares a reference field naming no target.
     *
     * @return  array{ResolvedBusinessDefinition, ResolvedBusinessDefinition, ResolvedBusinessDefinition}
     *          Owner, line and reference target definitions.
     *
     * @since   2.0.0
     */
    private function entityReferenceDefinitions(bool $withTargetlessField = false): array
    {
        $targetDefinition = EntityTypeDefinition::fromArray(NeutralBusinessFixture::referenceTargetDocument(
            'coordtarget',
            self::TARGET_ID,
        ))->published(1);
        $lineDocument = NeutralBusinessFixture::documentLineDocument(
            'coordlink',
            self::REFERENCE_FIELD_LINE_ID,
        );
        $lineDocument['fields'][] = [
            'handle' => 'product',
            'label' => 'Product',
            'type' => 'core.entity_reference',
            'configuration' => ['target' => $targetDefinition->handle],
        ];
        if ($withTargetlessField) {
            $lineDocument['fields'][] = [
                'handle' => 'accessory',
                'label' => 'Accessory',
                'type' => 'core.entity_reference',
            ];
        }
        $lineDefinition = EntityTypeDefinition::fromArray($lineDocument)->published(1);
        $ownerDefinition = EntityTypeDefinition::fromArray(NeutralBusinessFixture::documentHeaderDocument(
            'coordlink',
            self::REFERENCE_FIELD_OWNER_ID,
            $lineDefinition->handle,
            withAggregateInvariants: false,
        ))->published(1);

        return [
            $this->resolved($ownerDefinition, $lineDefinition->definitionVersion),
            $this->resolved($lineDefinition),
            $this->resolved($targetDefinition),
        ];
    }

    /**
     * Build a nested target plan that reveals only the target's public reference identity.
     *
     * @param   EntityTypeDefinition  $target  Reference target protected by the plan.
     *
     * @return  BusinessRecordAccessPlan  Exact target identity and row decision.
     *
     * @since   2.0.0
     */
    private function targetAccess(EntityTypeDefinition $target): BusinessRecordAccessPlan
    {
        return new BusinessRecordAccessPlan(
            $target->id,
            'business.record.read',
            $this->rowPolicy(true),
            new FieldDisclosurePlan(['public_reference' => ['code']]),
            hash('sha256', 'relationship-reference-target-access'),
        );
    }

    /**
     * Build a stable public single-line command with selectable values and target identity.
     *
     * @param   array<string, mixed>  $values    Submitted embedded line values.
     * @param   string                $recordId  Caller-facing identity requested for the line.
     *
     * @return  RelateRecordsCommand  Valid bounded command ready for coordinator characterization.
     *
     * @since   2.0.0
     */
    private function relateCommand(array $values, string $recordId = self::LINE_C): RelateRecordsCommand
    {
        return new RelateRecordsCommand(
            self::context(),
            'site.default.doc_header_coord',
            self::DOCUMENT_ID,
            1,
            'lines',
            $recordId,
            IdempotencyKey::fromString('relationship-coordinator-refusal'),
            targetValues: $values,
        );
    }

    /**
     * Assert one precise public refusal while allowing a case to characterize several independent branches.
     *
     * @param   class-string<Throwable>  $expected   Exact exception type expected from the boundary.
     * @param   ?string                  $message    Exact stable message, or null for deliberately opaque errors.
     * @param   callable(): mixed        $operation  Coordinator operation expected to refuse.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertRefusal(string $expected, ?string $message, callable $operation): void
    {
        try {
            $operation();
        } catch (Throwable $failure) {
            self::assertSame($expected, $failure::class);
            if ($message !== null) {
                self::assertSame($message, $failure->getMessage());
            }

            return;
        }

        self::fail('The coordinator operation did not refuse the request.');
    }

    /**
     * Build the published header and line definitions with real, mutually pinned installation blueprints.
     *
     * @return  array{ResolvedBusinessDefinition, ResolvedBusinessDefinition}  Header then line definition.
     *
     * @since   2.0.0
     */
    private function resolvedDefinitions(): array
    {
        $line = EntityTypeDefinition::fromArray(NeutralBusinessFixture::documentLineDocument(
            'coord',
            self::LINE_ID,
        ))->published(1);
        $header = EntityTypeDefinition::fromArray(NeutralBusinessFixture::documentHeaderDocument(
            'coord',
            self::HEADER_ID,
            $line->handle,
            withAggregateInvariants: false,
        ))->published(1);

        return [
            $this->resolved($header, $line->definitionVersion),
            $this->resolved($line),
        ];
    }

    /**
     * Pair one definition with the minimum real physical schema this coordinator reads.
     *
     * @param   EntityTypeDefinition  $definition   Published definition to install.
     * @param   ?int                  $lineVersion  Pinned target version for an owner line table.
     * @param   bool                  $includePin   Whether the line table carries its required target pin.
     *
     * @return  ResolvedBusinessDefinition  Valid definition and active installation pair.
     *
     * @since   2.0.0
     */
    private function resolved(
        EntityTypeDefinition $definition,
        ?int $lineVersion = null,
        bool $includePin = true,
    ): ResolvedBusinessDefinition {
        $column = new PhysicalColumnBlueprint('record_key', 'c_record_key_coord', 'guid');
        $table = new PhysicalTableBlueprint(
            $lineVersion === null ? 'record' : 'line:lines',
            $lineVersion === null ? 'kb_coord_record' : 'kb_coord_lines',
            $lineVersion === null ? PhysicalTableKind::Entity : PhysicalTableKind::OwnedLine,
            [$column],
            [$column->physicalName],
            options: $lineVersion === null || !$includePin ? [] : ['target_definition_version' => $lineVersion],
        );
        $blueprint = new PhysicalSchemaBlueprint(
            $definition->id,
            $definition->definitionVersion,
            $definition->checksum(),
            [$table],
        );
        $now = new DateTimeImmutable('2026-08-22T10:00:00+00:00');
        $installation = new SchemaInstallation(
            $definition->id,
            $definition->siteIdentifier,
            'core',
            $definition->definitionVersion,
            $definition->checksum(),
            $blueprint->checksum(),
            $blueprint,
            SchemaInstallationStatus::Active,
            $now,
            $now,
        );

        return new ResolvedBusinessDefinition($definition, $installation);
    }

    /**
     * Build the extracted coordinator over mocks while keeping value normalization real.
     *
     * @param   BusinessRecordReadRepository  $reads   Read behavior selected by the current case.
     * @param   ResolvedBusinessDefinition    $line    Pinned line definition every target lookup returns.
     * @param   ?ResolvedBusinessDefinition   $target  Optional entity-reference target returned for live lookup.
     *
     * @return  BusinessRecordRelationshipCoordinator  Independently testable relationship seam.
     *
     * @since   2.0.0
     */
    private function coordinator(
        BusinessRecordReadRepository $reads,
        ResolvedBusinessDefinition $line,
        ?ResolvedBusinessDefinition $target = null,
    ): BusinessRecordRelationshipCoordinator {
        $fence = $this->createStub(BusinessRecordMutationFence::class);
        $fence->method('lock')->willReturnCallback(
            fn (ExecutionContext $_context, string $handle): BusinessRecordMutationGeneration =>
                $target !== null && $handle === $target->definition->handle
                    ? $this->generation($target)
                    : $this->generation($line),
        );
        $definitions = $this->createStub(BusinessRecordDefinitionResolver::class);
        $definitions->method('pinned')->willReturn($line);
        $definitions->method('forCreate')->willReturn($target ?? $line);
        $codec = new RecordValueCodec(new SodiumEnvelopeCipher(new KeyMaterial(
            'relationship-coordinator-key-v1',
            str_repeat("\x21", SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES),
        )));

        return new BusinessRecordRelationshipCoordinator(
            $reads,
            $fence,
            $definitions,
            $codec,
            new RecordRuleValidator($codec),
        );
    }

    /**
     * Rebuild the exact active generation held by the line-definition fence.
     *
     * @param   ResolvedBusinessDefinition  $line  Definition and installation observed under the lock.
     *
     * @return  BusinessRecordMutationGeneration  Generation matching every installation coordinate.
     *
     * @since   2.0.0
     */
    private function generation(ResolvedBusinessDefinition $line): BusinessRecordMutationGeneration
    {
        $installation = $line->installation;

        return new BusinessRecordMutationGeneration(
            $installation->definitionId,
            $installation->siteIdentifier,
            $installation->ownerIdentifier,
            $installation->definitionVersion,
            $installation->definitionChecksum,
            $installation->schemaChecksum,
            $installation->status,
        );
    }

    /**
     * Build a header plan carrying the exact nested line plan used by relationship writes.
     *
     * @param   EntityTypeDefinition                     $owner      Header definition protected by the outer plan.
     * @param   EntityTypeDefinition                     $line       Line definition protected by the nested plan.
     * @param   bool                                     $allowRows  Whether line row policy admits the collection.
     * @param   array<string, BusinessRecordAccessPlan>  $related    Entity-reference decisions for the line.
     *
     * @return  BusinessRecordAccessPlan  Header plan with one `lines` child.
     *
     * @since   2.0.0
     */
    private function ownerAccess(
        EntityTypeDefinition $owner,
        EntityTypeDefinition $line,
        bool $allowRows = true,
        array $related = [],
    ): BusinessRecordAccessPlan {
        return new BusinessRecordAccessPlan(
            $owner->id,
            'business.record.relate',
            $this->rowPolicy(true),
            new FieldDisclosurePlan(),
            hash('sha256', 'relationship-owner-access'),
            ['lines' => $this->lineAccess($line, $allowRows, $related)],
        );
    }

    /**
     * Build one nested line plan with selectable row visibility and full writable fields.
     *
     * @param   EntityTypeDefinition                     $line       Line definition protected by the plan.
     * @param   bool                                     $allowRows  Whether the line row predicate admits values.
     * @param   array<string, BusinessRecordAccessPlan>  $related    Nested entity-reference decisions by handle.
     *
     * @return  BusinessRecordAccessPlan  Exact line target plan.
     *
     * @since   2.0.0
     */
    private function lineAccess(
        EntityTypeDefinition $line,
        bool $allowRows = true,
        array $related = [],
    ): BusinessRecordAccessPlan {
        $create = [];
        $update = [];
        $identity = null;
        foreach ($line->fields() as $field) {
            if (in_array($field->type, ['core.uuid', 'core.reference_identity'], true)) {
                $identity = $field->handle;
            }
            if (!$field->serverOnly && !$field->readOnly && !$field->computed && $field->formula === null) {
                if ($field->createVisible) {
                    $create[] = $field->handle;
                }
                if ($field->updateVisible && !$field->immutableAfterCreate) {
                    $update[] = $field->handle;
                }
            }
        }
        self::assertNotNull($identity);

        return new BusinessRecordAccessPlan(
            $line->id,
            'business.record.relate',
            $this->rowPolicy($allowRows),
            new FieldDisclosurePlan([
                'create' => $create,
                'update' => $update,
                'public_reference' => [$identity],
            ]),
            hash('sha256', 'relationship-line-access-' . ($allowRows ? 'allow' : 'deny')),
            $related,
        );
    }

    /**
     * Build a constant row policy for a characterization case.
     *
     * @param   bool  $allowed  Truth value returned for every row.
     *
     * @return  RecordPolicySet  One explicit allow predicate and no denies.
     *
     * @since   2.0.0
     */
    private function rowPolicy(bool $allowed): RecordPolicySet
    {
        return new RecordPolicySet(
            new RecordPolicySchema([]),
            [new RecordPolicyConstant($allowed)],
        );
    }

    /**
     * Build a whole-document command for a create or amendment case.
     *
     * @param   list<DocumentLineInput>  $lines   Final submitted line collection.
     * @param   DocumentWriteIntent      $intent  Create or amend operation under test.
     *
     * @return  WriteDocumentCommand  Validated command carrying stable identities.
     *
     * @since   2.0.0
     */
    private function documentCommand(
        array $lines,
        DocumentWriteIntent $intent = DocumentWriteIntent::Create,
    ): WriteDocumentCommand {
        return new WriteDocumentCommand(
            self::context(),
            'site.default.doc_header_coord',
            'lines',
            [],
            $lines,
            IdempotencyKey::fromString('relationship-coordinator-document-' . $intent->value),
            $intent,
            $intent === DocumentWriteIntent::Amend ? 1 : null,
            $intent === DocumentWriteIntent::Amend ? self::DOCUMENT_ID : null,
        );
    }

    /**
     * Build normalized stored values for one line.
     *
     * @param   string  $id           Stable line identity.
     * @param   string  $code         Unique line code.
     * @param   string  $description  Human-readable line description.
     * @param   string  $amount       Exact two-decimal amount.
     *
     * @return  array<string, mixed>  Values in the shape a read repository returns.
     *
     * @since   2.0.0
     */
    private function storedValues(string $id, string $code, string $description, string $amount): array
    {
        return [
            'id' => $id,
            'code' => $code,
            'description' => $description,
            'amount' => ExactDecimal::fromString($amount, 18, 2),
        ];
    }

    /**
     * Build one stored owned line for document diff characterization.
     *
     * @param   string  $id           Stable line identity and storage key.
     * @param   int     $position     Current dense position.
     * @param   string  $code         Unique line code.
     * @param   string  $description  Human-readable line description.
     * @param   string  $amount       Exact two-decimal amount.
     *
     * @return  StoredOwnedLine  Stored line at optimistic version three.
     *
     * @since   2.0.0
     */
    private function storedLine(
        string $id,
        int $position,
        string $code,
        string $description,
        string $amount,
    ): StoredOwnedLine {
        return new StoredOwnedLine(
            $id,
            $id,
            $position,
            3,
            $this->storedValues($id, $code, $description, $amount),
        );
    }

    /**
     * Build caller-submitted values for one new line.
     *
     * @param   string  $code         Unique line code.
     * @param   string  $description  Human-readable line description.
     * @param   string  $amount       Exact two-decimal amount.
     *
     * @return  array<string, mixed>  Unnormalized line input.
     *
     * @since   2.0.0
     */
    private function submittedLine(string $code, string $description, string $amount): array
    {
        return ['code' => $code, 'description' => $description, 'amount' => $amount];
    }

    /**
     * Find one field declared by a characterization fixture.
     *
     * @param   EntityTypeDefinition  $definition  Definition to inspect.
     * @param   string                $handle      Field handle expected to exist.
     *
     * @return  FieldDefinition  Matching field declaration.
     *
     * @since   2.0.0
     */
    private function field(EntityTypeDefinition $definition, string $handle): FieldDefinition
    {
        foreach ($definition->fields() as $field) {
            if ($field->handle === $handle) {
                return $field;
            }
        }

        throw new BusinessRelationshipRejected('The fixture field is missing.');
    }

    /**
     * Build one record in a selectable scope without imposing unrelated definition field rules.
     *
     * @param   EntityTypeDefinition  $definition  Definition identity and version carried by the row.
     * @param   string                $recordId    Caller-facing identity and internal key.
     * @param   ?RecordScope          $scope       Explicit scope, or the default site scope.
     *
     * @return  BusinessRecord  Live record at optimistic version one.
     *
     * @since   2.0.0
     */
    private function record(
        EntityTypeDefinition $definition,
        string $recordId,
        ?RecordScope $scope = null,
    ): BusinessRecord {
        $now = new DateTimeImmutable('2026-08-22T10:00:00+00:00');

        return new BusinessRecord(
            $definition->id,
            $definition->definitionVersion,
            $recordId,
            $recordId,
            $scope ?? $this->scope(),
            1,
            null,
            [],
            'relationship-coordinator-test',
            $now,
            'relationship-coordinator-test',
            $now,
        );
    }

    /**
     * Return the default site scope shared by owner and line rows.
     *
     * @return  RecordScope  Site-scoped record coordinate.
     *
     * @since   2.0.0
     */
    private function scope(): RecordScope
    {
        return RecordScope::forDefinition(ScopeMode::Site, SiteContext::default(), null);
    }

    /**
     * Return a password-authenticated portal context for target-exposure refusal.
     *
     * @return  ExecutionContext  Human portal context on the default site.
     *
     * @since   2.0.0
     */
    private static function portalContext(): ExecutionContext
    {
        return AuthorizationContext::principal([])->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'relationship-coordinator-portal-test',
            surface: AuthenticatedSurface::Portal,
        );
    }

    /**
     * Return the system context used by relationship commands in these isolated tests.
     *
     * @return  ExecutionContext  Background context on the default site.
     *
     * @since   2.0.0
     */
    private static function context(): ExecutionContext
    {
        return ExecutionContext::issueSystem(
            new \stdClass(),
            SystemIdentity::Worker,
            SiteContext::default(),
            'relationship-coordinator-test',
        );
    }
}
