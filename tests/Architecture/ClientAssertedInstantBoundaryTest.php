<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Kumwe\App\BusinessRecord\Domain\BusinessRecordReplayWindow;
use Kumwe\App\BusinessRecord\Domain\ClientAssertedInstant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversClass(ClientAssertedInstant::class)]
#[CoversClass(BusinessRecordReplayWindow::class)]
/**
 * Holds the boundary decision D14 draws around a client's clock: it may be recorded, and it decides nothing.
 *
 * A terminal that captures work while disconnected has to be able to say when the work happened, and the
 * platform has to be able to record that without ever believing it. The two halves are tested here
 * together because either one alone is worthless: a claim nobody records cannot be reconciled, and a
 * claim something orders by is a clock an untrusted client controls.
 *
 * @since  2.0.0
 */
final class ClientAssertedInstantBoundaryTest extends TestCase
{
    /**
     * The paths that consume an instant to decide ordering, expiry, period assignment or numbering.
     *
     * Enumerated rather than inferred, so the assertion says which decisions are being protected. Each
     * entry is a repository-relative path that must exist and must not be able to reach a client's clock.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const DECIDING_PATHS = [
        'src/BusinessRecord/Infrastructure/Persistence/DoctrineBusinessNumberSequenceAllocator.php',
        'src/BusinessRecord/Domain/BusinessRecordIdempotency.php',
        'src/BusinessRecord/Domain/BusinessRecordReplayWindow.php',
        'src/BusinessRecord/Application/BusinessRecordIdempotencyPurger.php',
        'src/Infrastructure/Automation/DoctrineIdempotencyPurger.php',
        'src/Infrastructure/Persistence/DoctrineIdempotencyLedger.php',
        'src/Delivery/Http/Api/Idempotency/PersistentIdempotencyMiddleware.php',
        'src/BusinessRecord/Application/BusinessRecordRevisionRepository.php',
        'src/BusinessRecord/Application/BusinessRecordRevisionCursor.php',
    ];

    /**
     * Files that may name the type at all: the value, the command that carries it, and the trail.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const CARRIERS = [
        'src/BusinessRecord/Application/BusinessRecordMutationPublication.php',
        'src/BusinessRecord/Application/Command/WriteDocumentCommand.php',
        'src/BusinessRecord/Domain/ClientAssertedInstant.php',
    ];

    /**
     * No path that decides ordering, expiry, period assignment or numbering can read a client's clock.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNoOrderingExpiryPeriodOrNumberingPathReadsAClientAssertedInstant(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (self::DECIDING_PATHS as $path) {
            $contents = file_get_contents($root . '/' . $path);
            self::assertIsString($contents, $path . ' is enumerated as a deciding path but is absent.');
            self::assertStringNotContainsString(
                'ClientAssertedInstant',
                $contents,
                $path . ' decides ordering, expiry, period assignment or numbering and must not read a '
                    . "client's asserted instant.",
            );
        }
    }

    /**
     * Only the value, the aggregate command and the trail may name the type anywhere under `src/`.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOnlyTheDeclaredCarriersNameTheType(): void
    {
        $root = dirname(__DIR__, 2);
        $naming = [];
        $source = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/src', RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($source as $file) {
            self::assertInstanceOf(SplFileInfo::class, $file);
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents);
            if (str_contains($contents, 'ClientAssertedInstant')) {
                $naming[] = str_replace($root . '/', '', $file->getPathname());
            }
        }
        sort($naming, SORT_STRING);
        $carriers = self::CARRIERS;
        sort($carriers, SORT_STRING);

        self::assertSame(
            $carriers,
            $naming,
            'A client-asserted instant reached code that is not declared as one of its carriers.',
        );
    }

    /**
     * The claim says whose clock it came from, and cannot be mistaken for the server's own instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheClaimIsMarkedAsTheClientsAndNormalizesToUtc(): void
    {
        $asserted = new ClientAssertedInstant(
            new DateTimeImmutable('2026-08-14T11:30:00.000000', new DateTimeZone('+02:00')),
        );

        self::assertSame(
            ['asserted_by_client' => true, 'captured_at' => '2026-08-14T09:30:00.000000+00:00'],
            $asserted->toArray(),
        );
        self::assertSame(
            $asserted->toArray(),
            ClientAssertedInstant::fromPortableString($asserted->toPortableString())->toArray(),
        );
        self::assertArrayNotHasKey(
            'amount',
            $asserted->toArray(),
            'The export must stay a claim about a clock rather than acquire business meaning.',
        );
    }

    /**
     * A clock reading that cannot be a clock reading is refused rather than recorded.
     *
     * A terminal a week fast is exactly what this type exists to record, so the bound is on what can be a
     * clock at all and is never measured against the server's own instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACorruptClockReadingIsRefused(): void
    {
        $tolerated = new ClientAssertedInstant(new DateTimeImmutable('2099-12-31T23:59:59+00:00'));
        self::assertStringStartsWith('2099-12-31', $tolerated->toPortableString());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outside the recordable range');
        new ClientAssertedInstant(new DateTimeImmutable('1999-12-31T23:59:59+00:00'));
    }

    /**
     * The replay horizon runs from the server's claim instant, so a client cannot lengthen its own window.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheReplayHorizonIsMeasuredFromTheServersOwnInstant(): void
    {
        $window = new BusinessRecordReplayWindow(
            BusinessRecordReplayWindow::MINIMUM_REPLAY_SECONDS,
            BusinessRecordReplayWindow::MINIMUM_REPLAY_SECONDS * 2,
        );
        $claimedAt = new DateTimeImmutable('2026-08-14T00:00:00+00:00');

        self::assertTrue($window->admitsReplay($claimedAt, new DateTimeImmutable('2026-08-14T00:59:59+00:00')));
        self::assertFalse($window->admitsReplay($claimedAt, new DateTimeImmutable('2026-08-14T01:00:00+00:00')));
        self::assertSame(
            '2026-08-14T02:00:00+00:00',
            $window->expiryFrom($claimedAt)->format('Y-m-d\TH:i:sP'),
            'Retention must outlast replay so a late repeat meets a claim rather than an empty ledger.',
        );
    }
}
