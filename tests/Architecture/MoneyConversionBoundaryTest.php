<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\App\BusinessRecord\Application\RecordValueCodec;
use Kumwe\Conversion\Value\ConvertedMoneyValue;
use Kumwe\Conversion\Decimal\ExactDecimal;
use Kumwe\Conversion\Decimal\ExactDecimalArithmetic;
use Kumwe\Conversion\Contract\MoneyConversionRequest;
use Kumwe\Conversion\Contract\MoneyConverter;
use Kumwe\Conversion\Value\MoneyExchangeRate;
use Kumwe\Conversion\Value\MoneyRoundingMode;
use Kumwe\Conversion\Value\MoneyValue;
use Kumwe\App\BusinessRecord\Domain\RecordValueGuard;
use Kumwe\Secret\Cipher\SodiumEnvelopeCipher;
use Kumwe\Secret\Value\KeyMaterial;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversNothing]
/**
 * Holds the two boundaries decision D10 draws: rates stay outside core, conversions stay outside storage.
 *
 * @since  2.0.0
 */
final class MoneyConversionBoundaryTest extends TestCase
{
    /**
     * Prove no shipped class supplies an exchange rate, so every rate in an installation is a package's.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCoreShipsNoRateProviderRateTableOrRateFeed(): void
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
            if (preg_match('/\bclass\s+\w+[^{]*\bimplements\b[^{]*\bMoneyRateProvider\b/', $contents) === 1) {
                $offenders[] = $file->getPathname();
            }
        }

        self::assertSame([], $offenders, 'Core must ship no exchange-rate provider of any kind.');
    }

    /**
     * Prove a converted amount cannot be written into a record value by any path that stores one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNoWritePathAcceptsAConvertedAmountWhereStoredMoneyIsExpected(): void
    {
        $converted = self::converted();
        $codec = new RecordValueCodec(new SodiumEnvelopeCipher(new KeyMaterial(
            'unit-key-v1',
            str_repeat("\x5a", SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES),
        )));

        try {
            RecordValueGuard::assertValue($converted);
            self::fail('A converted amount was admitted as a business-record value.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('unsupported runtime type', $exception->getMessage());
        }

        foreach ([$converted, $converted->toArray()] as $candidate) {
            try {
                $codec->normalize(
                    self::field('price'),
                    $candidate,
                    'default',
                    NeutralBusinessFixture::DEFINITION_ID,
                    NeutralBusinessFixture::RECORD_ID,
                );
                self::fail('A money field accepted a converted amount.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('exact amount and currency', $exception->getMessage());
            }
        }

        $stored = $codec->normalize(
            self::field('price'),
            $converted->source->toArray(),
            'default',
            NeutralBusinessFixture::DEFINITION_ID,
            NeutralBusinessFixture::RECORD_ID,
        );
        self::assertInstanceOf(MoneyValue::class, $stored);
    }

    /**
     * Build one converted amount for the boundary assertions.
     *
     * @return  ConvertedMoneyValue  An amount stored in NAD, presented in EUR.
     *
     * @since   2.0.0
     */
    private static function converted(): ConvertedMoneyValue
    {
        $asAt = new DateTimeImmutable('2026-08-14T00:00:00', new DateTimeZone('UTC'));

        return (new MoneyConverter())->convert(
            new MoneyConversionRequest(
                new MoneyValue(ExactDecimal::fromString('25000.00', 12, 2), 'NAD'),
                'EUR',
                $asAt,
                12,
                2,
                MoneyRoundingMode::HalfUp,
            ),
            new MoneyExchangeRate(
                'NAD',
                'EUR',
                ExactDecimalArithmetic::fromLiteral('0.04938240'),
                $asAt,
                'acme.rates.ecb',
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
