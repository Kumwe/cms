<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Host;

use InvalidArgumentException;
use Kumwe\App\Studio\Application\Host\StudioProducerError;
use Kumwe\Producer\Error\HostRefusal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the App's direct Producer refusal construction and delivery status policy.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioProducerError::class)]
final class StudioProducerErrorTest extends TestCase
{
    /**
     * Enumerate Producer's complete closed refusal taxonomy and the App's HTTP status for each category.
     *
     * @return  iterable<string, array{string, int}>  Category and exact HTTP status.
     *
     * @since  2.0.0
     */
    public static function categoryStatuses(): iterable
    {
        yield 'invalid request' => ['invalid-request', 400];
        yield 'incompatible' => ['incompatible', 400];
        yield 'cancelled' => ['cancelled', 400];
        yield 'unauthenticated' => ['unauthenticated', 401];
        yield 'forbidden' => ['forbidden', 403];
        yield 'not found' => ['not-found', 404];
        yield 'conflict' => ['conflict', 409];
        yield 'limit exceeded' => ['limit-exceeded', 413];
        yield 'validation failed' => ['validation-failed', 422];
        yield 'rate limited' => ['rate-limited', 429];
        yield 'internal' => ['internal', 500];
        yield 'unavailable' => ['unavailable', 503];
    }

    /**
     * Every App refusal is a canonical Producer error with the agreed category-to-status mapping.
     *
     * @param   string  $category  Producer refusal category.
     * @param   int     $status    Exact App delivery status.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    #[DataProvider('categoryStatuses')]
    public function testEveryCategoryBuildsCanonicalProducerErrorWithItsDeliveryStatus(
        string $category,
        int $status,
    ): void {
        $error = StudioProducerError::error(
            $category,
            'studio.host/test-refusal',
            $category === 'conflict' ? 'revision-2' : null,
            $category === 'unavailable',
            in_array($category, ['rate-limited', 'unavailable'], true) ? 250 : null,
        );

        self::assertSame($category, $error->category());
        self::assertSame($status, StudioProducerError::status($category));
        self::assertSame('studio.host/request-refused', $error->message()->key());
        self::assertSame('studio.host/test-refusal', $error->diagnostics()[0]->code());
    }

    /**
     * A committed-state refusal preserves Producer's explicit mutation-boundary signal.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testCommittedStateRefusalRetainsCanonicalErrorAndCommitSignal(): void
    {
        try {
            StudioProducerError::refuse(
                'validation-failed',
                'studio.media/verification-failed',
                commitsState: true,
            );
        } catch (HostRefusal $refusal) {
            self::assertTrue($refusal->commitsState());
            self::assertSame('validation-failed', $refusal->error()->category());
            self::assertSame('studio.media/verification-failed', $refusal->error()->diagnostics()[0]->code());

            return;
        }

        self::fail('A Producer refusal must be thrown.');
    }

    /**
     * Refusal details follow the blocking diagnostic as error diagnostics, trimmed and bounded, and a blank
     * detail is dropped rather than sent as an empty message.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testBlankDetailsAreDroppedAndTheRestBecomeBoundedErrorDiagnostics(): void
    {
        $error = StudioProducerError::error(
            'validation-failed',
            'studio.host/test-refusal',
            details: ['  ', '', ' first ', str_repeat('x', 600)],
        );

        $diagnostics = $error->diagnostics();
        self::assertCount(3, $diagnostics);
        self::assertSame('blocking', $diagnostics[0]->severity());
        self::assertSame('error', $diagnostics[1]->severity());
        self::assertSame('studio.host/test-refusal', $diagnostics[1]->code());
        self::assertSame('first', $diagnostics[1]->message()->defaultMessage());
        self::assertSame(500, mb_strlen((string) $diagnostics[2]->message()->defaultMessage()));
    }

    /**
     * Unknown categories never fall through to an arbitrary status or error shape.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testUnknownCategoryFailsClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StudioProducerError::error('unknown', 'studio.host/test-refusal');
    }
}
