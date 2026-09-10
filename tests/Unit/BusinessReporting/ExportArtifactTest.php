<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessReporting;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\BusinessReporting\Domain\ExportArtifact;
use Kumwe\App\BusinessReporting\Domain\ExportArtifactStatus;
use Kumwe\Context\Value\AuthenticatedSurface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExportArtifact::class)]
final class ExportArtifactTest extends TestCase
{
    /**
     * @var    list<array{capability: string, scope_type: string, scope_identifier: ?string}>
     */
    private const GRANTS = [
        [
            'capability' => 'business.record.export',
            'scope_type' => 'global',
            'scope_identifier' => null,
        ],
        [
            'capability' => 'kumwe.asset-inspection-example.view',
            'scope_type' => 'business_report',
            'scope_identifier' => 'kumwe.asset-inspection-example.inspection-summary',
        ],
    ];

    public function testNewAndLegacyDocumentsRoundTripThroughEveryStateTransition(): void
    {
        $legacy = $this->artifact();
        $empty = $this->artifact([]);
        $new = $this->artifact(self::GRANTS);

        self::assertArrayNotHasKey('authority_grants', $legacy->toArray());
        self::assertSame($legacy->toArray(), ExportArtifact::fromArray($legacy->toArray())->toArray());
        self::assertSame([], $empty->toArray()['authority_grants']);
        self::assertSame([], ExportArtifact::fromArray($empty->toArray())->authorityGrantRows);
        self::assertSame([self::GRANTS], $new->toArray()['authority_grants']);

        $legacyFailed = $legacy->fail(
            new DateTimeImmutable('2026-08-10T12:01:00+00:00'),
            'authorization_changed',
        );
        self::assertArrayNotHasKey('authority_grants', $legacyFailed->toArray());
        self::assertSame(
            $legacyFailed->toArray(),
            ExportArtifact::fromArray($legacyFailed->toArray())->toArray(),
        );

        $completed = $new
            ->start(new DateTimeImmutable('2026-08-10T12:01:00+00:00'))
            ->complete(
                new DateTimeImmutable('2026-08-10T12:02:00+00:00'),
                $new->id . '.' . str_repeat('d', 32) . '.csv',
                8,
                str_repeat('e', 64),
                1,
                str_repeat('f', 64),
            );

        self::assertSame(self::GRANTS, $completed->authorityGrantRows);
        self::assertSame($completed->toArray(), ExportArtifact::fromArray($completed->toArray())->toArray());
    }

    public function testAuthoritySnapshotLargerThanCanonicalCollectionLimitRoundTripsInChunks(): void
    {
        $grants = self::grantRows(513);
        $artifact = $this->artifact($grants);
        $document = $artifact->toArray();
        $chunks = $document['authority_grants'];

        self::assertSame($grants, $artifact->authorityGrantRows);
        self::assertIsArray($chunks);
        self::assertCount(2, $chunks);
        self::assertIsArray($chunks[0]);
        self::assertIsArray($chunks[1]);
        self::assertCount(512, $chunks[0]);
        self::assertCount(1, $chunks[1]);
        self::assertJson(CanonicalDefinitionJson::encode($document));

        $roundTrip = ExportArtifact::fromArray($document);

        self::assertSame($grants, $roundTrip->authorityGrantRows);
        self::assertSame($document, $roundTrip->toArray());
    }

    public function testPresentNullAuthoritySnapshotIsNotTreatedAsLegacy(): void
    {
        $document = $this->artifact()->toArray();
        $document['authority_grants'] = null;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('authority grants must form a list');

        ExportArtifact::fromArray($document);
    }

    /**
     * @param  mixed  $chunks  Invalid persisted authority-grant chunks.
     */
    #[DataProvider('invalidAuthorityGrantChunks')]
    public function testRejectsNonCanonicalAuthorityGrantChunks(mixed $chunks): void
    {
        $document = $this->artifact()->toArray();
        $document['authority_grants'] = $chunks;

        $this->expectException(InvalidArgumentException::class);

        ExportArtifact::fromArray($document);
    }

    /**
     * @return  iterable<string, array{0: mixed}>
     */
    public static function invalidAuthorityGrantChunks(): iterable
    {
        $rows = self::grantRows(513);

        yield 'flat new-key shape' => [[$rows[0]]];
        yield 'empty interior chunk' => [[array_slice($rows, 0, 512), [], [$rows[512]]]];
        yield 'empty final chunk' => [[array_slice($rows, 0, 512), []]];
        yield 'short non-final chunk' => [[[$rows[0]], [$rows[1]]]];
        yield 'oversized chunk' => [[$rows]];
        yield 'too many chunks' => [array_fill(0, 513, [$rows[0]])];
    }

    public function testCanonicalRowValidationSpansAuthorityGrantChunkBoundaries(): void
    {
        $rows = self::grantRows(513);
        $document = $this->artifact()->toArray();
        $document['authority_grants'] = [
            array_slice($rows, 0, 512),
            [$rows[511]],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('authority grants must be canonical');

        ExportArtifact::fromArray($document);
    }

    public function testCanonicalRowOrderValidationSpansAuthorityGrantChunkBoundaries(): void
    {
        $rows = self::grantRows(513);
        $document = $this->artifact()->toArray();
        $document['authority_grants'] = [
            array_slice($rows, 1, 512),
            [$rows[0]],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('authority grants must be canonical');

        ExportArtifact::fromArray($document);
    }

    /**
     * @param  array<mixed>  $rows  Invalid persisted authority rows.
     */
    #[DataProvider('invalidAuthorityGrants')]
    public function testRejectsNonCanonicalAuthorityGrantRows(array $rows): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->artifact($rows);
    }

    /**
     * @return  iterable<string, array{0: array<mixed>}>
     */
    public static function invalidAuthorityGrants(): iterable
    {
        yield 'not a list' => [[
            'capability' => 'business.record.export',
            'scope_type' => 'global',
            'scope_identifier' => null,
        ]];
        yield 'missing row key' => [[[
            'capability' => 'business.record.export',
            'scope_type' => 'global',
        ]]];
        yield 'duplicate row' => [[self::GRANTS[0], self::GRANTS[0]]];
        yield 'global scope with identifier' => [[[
            'capability' => 'business.record.export',
            'scope_type' => 'global',
            'scope_identifier' => 'default',
        ]]];
        yield 'named scope without identifier' => [[[
            'capability' => 'business.record.export',
            'scope_type' => 'site',
            'scope_identifier' => null,
        ]]];
        yield 'normalized capability spelling' => [[[
            'capability' => 'Business.Record.Export',
            'scope_type' => 'global',
            'scope_identifier' => null,
        ]]];
        yield 'out of canonical order' => [array_reverse(self::GRANTS)];
    }

    public function testRejectsMoreAuthorityRowsThanCanonicalChunksCanHold(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('too many authority grants');

        $this->artifact(array_fill(0, 262_145, self::GRANTS[0]));
    }

    /**
     * Build deterministic, globally canonical named-scope grant rows.
     *
     * @param   int  $count  Number of unique rows to build.
     *
     * @return  list<array{capability: string, scope_type: string, scope_identifier: string}>
     *
     * @since   2.0.0
     */
    private static function grantRows(int $count): array
    {
        $rows = [];
        for ($index = 0; $index < $count; ++$index) {
            $rows[] = [
                'capability' => 'business.record.export',
                'scope_type' => 'site',
                'scope_identifier' => sprintf('site-%06d', $index),
            ];
        }

        return $rows;
    }

    /**
     * @param  ?array<mixed>  $authorityGrantRows  Captured authority or null for a legacy document.
     */
    private function artifact(?array $authorityGrantRows = null): ExportArtifact
    {
        $createdAt = new DateTimeImmutable('2026-08-10T12:00:00+00:00');

        return new ExportArtifact(
            '019fecc6-8b97-7079-98e9-dc666b067439',
            'kumwe.asset-inspection-example.inspection-summary',
            1,
            str_repeat('a', 64),
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb301',
            'default',
            null,
            null,
            AuthenticatedSurface::Cli,
            str_repeat('b', 64),
            str_repeat('c', 64),
            [],
            str_repeat('d', 64),
            ExportArtifactStatus::Queued,
            $createdAt,
            $createdAt->modify('+1 hour'),
            null,
            null,
            'inspection-summary-20260810-120000.csv',
            null,
            null,
            null,
            null,
            null,
            null,
            1,
            authorityGrantRows: $authorityGrantRows,
        );
    }
}
