<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessRecord;

use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\App\BusinessRecord\Application\BusinessRecordRevisionCursor;
use Kumwe\App\BusinessRecord\Application\BusinessRecordRevisionRepository;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\DeleteRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\UpdateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordReferenceConflict;
use Kumwe\App\BusinessRecord\Application\Query\RecordHistoryQuery;
use Kumwe\App\BusinessRecord\Application\RecordFingerprint;
use Kumwe\App\BusinessRecord\Domain\BusinessRecordRevision;
use Kumwe\App\BusinessRecord\Domain\RecordScope;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordRevisionRepository;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessController;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Proves record history resolves identity ambiguity before it pages, and pages on a total ordering key.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessRecordService::class)]
#[CoversClass(DoctrineBusinessRecordRevisionRepository::class)]
#[CoversClass(BusinessRecordRevisionCursor::class)]
#[CoversClass(BusinessRecordReferenceConflict::class)]
final class BusinessRecordHistoryPagingIntegrationTest extends TestCase
{
    /**
     * Proves a page too small to reach the second generation still fails closed on the ambiguity.
     *
     * The older generation carries more revisions than the newer one, so a newest-first page bounded to
     * the first few rows sees only one record key. The page-local uniqueness check this replaces was
     * satisfied by exactly that page and handed back one subject's history under an identity two subjects
     * had held; the scope-wide probe refuses it whatever the page size or cursor position.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAmbiguousIdentityIsRefusedAtEveryPageSizeAndCursorPosition(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);

        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
        $definition = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::referenceTargetDocument($suffix, Uuid::uuid7()->toString()),
        );
        $identity = 'PAGED-' . strtoupper(substr($suffix, 0, 6));

        $first = $records->create(new CreateRecordCommand(
            $context,
            $definition->handle,
            ['label' => 'First generation'],
            NeutralBusinessFixture::idempotencyKey('history-first-' . $suffix),
            recordId: $identity,
        ));
        $version = 1;
        for ($edit = 1; $edit <= 4; $edit++) {
            $records->update(new UpdateRecordCommand(
                $context,
                $definition->handle,
                $identity,
                $version,
                ['label' => 'First generation edit ' . $edit],
                NeutralBusinessFixture::idempotencyKey('history-edit-' . $edit . '-' . $suffix),
            ));
            $version++;
        }
        $records->delete(new DeleteRecordCommand(
            $context,
            $definition->handle,
            $identity,
            $version,
            NeutralBusinessFixture::idempotencyKey('history-delete-first-' . $suffix),
        ));

        $history = $records->history(new RecordHistoryQuery($context, $definition->handle, $identity, limit: 2));
        self::assertCount(2, $history->revisions);
        self::assertTrue($history->hasMore);
        self::assertSame([$first->recordKey], array_values(array_unique(array_map(
            static fn (object $revision): string => $revision->recordKey,
            $history->revisions,
        ))));

        $second = $records->create(new CreateRecordCommand(
            $context,
            $definition->handle,
            ['label' => 'Second generation'],
            NeutralBusinessFixture::idempotencyKey('history-second-' . $suffix),
            recordId: $identity,
        ));
        self::assertNotSame($first->recordKey, $second->recordKey);
        $records->delete(new DeleteRecordCommand(
            $context,
            $definition->handle,
            $identity,
            1,
            NeutralBusinessFixture::idempotencyKey('history-delete-second-' . $suffix),
        ));

        foreach ([1, 2, 3, 100] as $pageSize) {
            try {
                $records->history(new RecordHistoryQuery(
                    $context,
                    $definition->handle,
                    $identity,
                    limit: $pageSize,
                ));
                self::fail(sprintf('A page of %d must not resolve an ambiguous identity.', $pageSize));
            } catch (BusinessRecordReferenceConflict $conflict) {
                self::assertSame('business_record.reference_conflict', $conflict->stableCode());
            }
        }

        foreach ([2, 4, 6] as $cursor) {
            try {
                $records->history(new RecordHistoryQuery(
                    $context,
                    $definition->handle,
                    $identity,
                    limit: 2,
                    beforeVersion: $cursor,
                ));
                self::fail(sprintf('A cursor at version %d must not resolve an ambiguity.', $cursor));
            } catch (BusinessRecordReferenceConflict $conflict) {
                self::assertSame('business_record.reference_conflict', $conflict->stableCode());
            }
        }
    }

    /**
     * Proves paging an ambiguous digest at the log itself repeats no revision and skips none.
     *
     * Two generations of one reused identity number their versions from one, so the newest-first window
     * holds pairs of rows that agree on record version and revision number. The page boundary is placed
     * inside such a pair deliberately: a cursor carrying only a record version either re-read the tied row
     * or stepped over it, while the total cursor resumes exactly where the previous page stopped.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPagingAcrossACollidingVersionRepeatsNothingAndSkipsNothing(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        $revisions = $container->get(BusinessRecordRevisionRepository::class);
        $definitions = $container->get(BusinessRecordDefinitionResolver::class);
        $access = $container->get(BusinessRecordAccessController::class);
        $fingerprints = $container->get(RecordFingerprint::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(BusinessRecordRevisionRepository::class, $revisions);
        self::assertInstanceOf(BusinessRecordDefinitionResolver::class, $definitions);
        self::assertInstanceOf(BusinessRecordAccessController::class, $access);
        self::assertInstanceOf(RecordFingerprint::class, $fingerprints);

        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
        $definition = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::referenceTargetDocument($suffix, Uuid::uuid7()->toString()),
        );
        $identity = 'TIED-' . strtoupper(substr($suffix, 0, 6));

        $expected = [];
        foreach (['first', 'second'] as $generation) {
            $created = $records->create(new CreateRecordCommand(
                $context,
                $definition->handle,
                ['label' => $generation . ' tied generation'],
                NeutralBusinessFixture::idempotencyKey('tied-create-' . $generation . '-' . $suffix),
                recordId: $identity,
            ));
            $records->update(new UpdateRecordCommand(
                $context,
                $definition->handle,
                $identity,
                1,
                ['label' => $generation . ' tied edit'],
                NeutralBusinessFixture::idempotencyKey('tied-edit-' . $generation . '-' . $suffix),
            ));
            $records->delete(new DeleteRecordCommand(
                $context,
                $definition->handle,
                $identity,
                2,
                NeutralBusinessFixture::idempotencyKey('tied-delete-' . $generation . '-' . $suffix),
            ));
            $expected[] = $created->recordKey;
        }
        self::assertCount(2, array_unique($expected));

        $transactions = $container->get(TransactionManager::class);
        self::assertInstanceOf(TransactionManager::class, $transactions);
        $digest = $fingerprints->digest($identity);
        $transactions->transactional(static function () use (
            $access,
            $context,
            $definition,
            $definitions,
            $digest,
            $revisions,
        ): void {
            $resolved = $definitions->forHistory($context, $definition->handle);
            $scope = RecordScope::forDefinition($resolved->definition->scope, $context->site(), null);
            $plan = $access->plan($context, 'business.record.history', $resolved, $scope);

            $whole = $revisions->historyByIdentityDigest($resolved, $scope, $plan, $digest, 201);
            self::assertCount(6, $whole);
            $tied = array_filter(
                $whole,
                static fn (BusinessRecordRevision $revision): bool => $revision->recordVersion === 3,
            );
            self::assertCount(2, $tied, 'The two generations must collide on the ambiguous component.');

            $paged = [];
            $cursor = null;
            for ($page = 0; $page < 10; $page++) {
                $window = $revisions->historyByIdentityDigest($resolved, $scope, $plan, $digest, 1, $cursor);
                if ($window === []) {
                    break;
                }
                $paged[] = $window[0]->revisionId;
                $cursor = BusinessRecordRevisionCursor::after($window[0]);
            }

            self::assertSame(
                array_map(
                    static fn (BusinessRecordRevision $revision): string => $revision->revisionId,
                    $whole,
                ),
                $paged,
                'Paging one row at a time must visit every revision exactly once, in the window order.',
            );
            self::assertCount(count(array_unique($paged)), $paged);
        });
    }

    /**
     * Proves the scope-wide generation probe separates one generation from several.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheGenerationProbeReportsEveryKeyTheDigestCovers(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        $revisions = $container->get(BusinessRecordRevisionRepository::class);
        $definitions = $container->get(BusinessRecordDefinitionResolver::class);
        $access = $container->get(BusinessRecordAccessController::class);
        $fingerprints = $container->get(RecordFingerprint::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(BusinessRecordRevisionRepository::class, $revisions);
        self::assertInstanceOf(BusinessRecordDefinitionResolver::class, $definitions);
        self::assertInstanceOf(BusinessRecordAccessController::class, $access);
        self::assertInstanceOf(RecordFingerprint::class, $fingerprints);

        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
        $definition = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::referenceTargetDocument($suffix, Uuid::uuid7()->toString()),
        );
        $identity = 'PROBE-' . strtoupper(substr($suffix, 0, 6));
        $transactions = $container->get(TransactionManager::class);
        self::assertInstanceOf(TransactionManager::class, $transactions);
        $digest = $fingerprints->digest($identity);

        /**
         * Read the distinct generations the digest covers, inside the transaction policy planning needs.
         *
         * @return  list<string>  Distinct record keys, ascending.
         */
        $probe = static function () use (
            $access,
            $context,
            $definition,
            $definitions,
            $digest,
            $revisions,
            $transactions,
        ): array {
            /** @var list<string> $keys */
            $keys = $transactions->transactional(static function () use (
                $access,
                $context,
                $definition,
                $definitions,
                $digest,
                $revisions,
            ): array {
                $resolved = $definitions->forHistory($context, $definition->handle);
                $scope = RecordScope::forDefinition($resolved->definition->scope, $context->site(), null);
                $plan = $access->plan($context, 'business.record.history', $resolved, $scope);

                return $revisions->recordKeysForIdentityDigest($resolved, $scope, $plan, $digest, 2);
            });

            return $keys;
        };

        self::assertSame([], $probe());

        $first = $records->create(new CreateRecordCommand(
            $context,
            $definition->handle,
            ['label' => 'Probe generation one'],
            NeutralBusinessFixture::idempotencyKey('probe-first-' . $suffix),
            recordId: $identity,
        ));
        $records->delete(new DeleteRecordCommand(
            $context,
            $definition->handle,
            $identity,
            1,
            NeutralBusinessFixture::idempotencyKey('probe-delete-first-' . $suffix),
        ));
        self::assertSame([$first->recordKey], $probe());

        $second = $records->create(new CreateRecordCommand(
            $context,
            $definition->handle,
            ['label' => 'Probe generation two'],
            NeutralBusinessFixture::idempotencyKey('probe-second-' . $suffix),
            recordId: $identity,
        ));
        $records->delete(new DeleteRecordCommand(
            $context,
            $definition->handle,
            $identity,
            1,
            NeutralBusinessFixture::idempotencyKey('probe-delete-second-' . $suffix),
        ));
        $keys = $probe();
        sort($keys, SORT_STRING);
        $both = [$first->recordKey, $second->recordKey];
        sort($both, SORT_STRING);
        self::assertSame($both, $keys);
    }
}
