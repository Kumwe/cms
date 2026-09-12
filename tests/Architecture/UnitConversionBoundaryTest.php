<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\App\BusinessRecord\Application\RecordValueCodec;
use Kumwe\Conversion\Value\ConvertedQuantityValue;
use Kumwe\Conversion\Decimal\ExactDecimal;
use Kumwe\Conversion\Decimal\ExactDecimalArithmetic;
use Kumwe\Conversion\Contract\QuantityConverter;
use Kumwe\Conversion\Value\QuantityRoundingMode;
use Kumwe\Conversion\Value\QuantityValue;
use Kumwe\App\BusinessRecord\Domain\RecordValueGuard;
use Kumwe\Conversion\Value\UnitConversionFactor;
use Kumwe\Conversion\Contract\UnitConversionRequest;
use Kumwe\Secret\Cipher\SodiumEnvelopeCipher;
use Kumwe\Secret\Value\KeyMaterial;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversNothing]
/**
 * Holds the two boundaries decision D13.5 draws: tables stay outside core, conversions stay outside storage.
 *
 * This is the named architecture check for the unit-of-measure half of the conversion contract. It is
 * the counterpart of `MoneyConversionBoundaryTest` and asserts the same two properties for quantities,
 * so a future write path that accepts a converted quantity where a stored one belongs fails the build
 * rather than being caught in a stock count.
 *
 * @since  2.0.0
 */
final class UnitConversionBoundaryTest extends TestCase
{
    /**
     * Prove no shipped class relates two units, so every conversion table is a package's.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCoreShipsNoUnitConversionProviderOrConversionTable(): void
    {
        $offenders = [];
        $source = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src', RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($source as $file) {
            self::assertInstanceOf(SplFileInfo::class, $file);
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents);
            if (preg_match('/\bclass\s+\w+[^{]*\bimplements\b[^{]*\bUnitConversionProvider\b/', $contents) === 1) {
                $offenders[] = $file->getPathname();
            }
        }

        self::assertSame([], $offenders, 'Core must ship no unit conversion provider of any kind.');
    }

    /**
     * Prove a converted quantity cannot be written into a record value by any path that stores one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNoWritePathAcceptsAConvertedQuantityWhereStoredQuantityIsExpected(): void
    {
        $converted = self::converted();
        $codec = new RecordValueCodec(new SodiumEnvelopeCipher(new KeyMaterial(
            'unit-key-v1',
            str_repeat("\x5a", SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES),
        )));

        try {
            RecordValueGuard::assertValue($converted);
            self::fail('A converted quantity was admitted as a business-record value.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('unsupported runtime type', $exception->getMessage());
        }

        foreach ([$converted, $converted->toArray()] as $candidate) {
            try {
                $codec->normalize(
                    self::field('quantity'),
                    $candidate,
                    'default',
                    NeutralBusinessFixture::DEFINITION_ID,
                    NeutralBusinessFixture::RECORD_ID,
                );
                self::fail('A quantity field accepted a converted quantity.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('exact amount and unit', $exception->getMessage());
            }
        }

        $stored = $codec->normalize(
            self::field('quantity'),
            $converted->source->toArray(),
            'default',
            NeutralBusinessFixture::DEFINITION_ID,
            NeutralBusinessFixture::RECORD_ID,
        );
        self::assertInstanceOf(QuantityValue::class, $stored);
    }

    /**
     * Build one converted quantity for the boundary assertions.
     *
     * @return  ConvertedQuantityValue  A quantity stored in the fixture's pinned unit, shown per pallet.
     *
     * @since   2.0.0
     */
    private static function converted(): ConvertedQuantityValue
    {
        $asAt = new DateTimeImmutable('2026-08-14T00:00:00', new DateTimeZone('UTC'));

        return (new QuantityConverter())->convert(
            new UnitConversionRequest(
                new QuantityValue(ExactDecimal::fromString('2400.000000', 12, 6), 'unit'),
                'pallet',
                $asAt,
                12,
                6,
                QuantityRoundingMode::HalfUp,
            ),
            new UnitConversionFactor(
                'unit',
                'pallet',
                ExactDecimalArithmetic::fromLiteral('0.01000000'),
                $asAt,
                'acme.units.trade',
            ),
        );
    }

    /**
     * Read one field of the neutral business fixture.
     *
     * @param   string  $handle  Field handle to resolve.
     *
     * @return  FieldDefinition  The declared field.
     *
     * @since   2.0.0
     */
    private static function field(string $handle): FieldDefinition
    {
        $definition = EntityTypeDefinition::fromArray(NeutralBusinessFixture::backupDocument());
        foreach ($definition->fields() as $field) {
            if ($field->handle === $handle) {
                return $field;
            }
        }

        self::fail('The neutral fixture field ' . $handle . ' is unavailable.');
    }
}
