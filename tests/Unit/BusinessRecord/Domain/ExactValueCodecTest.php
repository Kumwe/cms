<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessRecord\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\App\BusinessRecord\Application\PlannedFieldEncoding;
use Kumwe\App\BusinessRecord\Application\RecordColumnEncodingPlan;
use Kumwe\App\BusinessRecord\Application\RecordValueCodec;
use Kumwe\App\BusinessRecord\Application\SecretAssociatedData;
use Kumwe\App\BusinessSchema\Domain\PhysicalColumnBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalTableBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalTableKind;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\Conversion\Decimal\ExactDecimal;
use Kumwe\Conversion\Value\MoneyValue;
use Kumwe\Conversion\Value\QuantityValue;
use Kumwe\Extension\Spi\BusinessRecord\Value\ZonedDateTimeValue;
use Kumwe\Secret\Cipher\SodiumEnvelopeCipher;
use Kumwe\Secret\Value\EncryptedEnvelope;
use Kumwe\Secret\Value\KeyMaterial;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(PlannedFieldEncoding::class)]
#[CoversClass(RecordColumnEncodingPlan::class)]
#[CoversClass(RecordValueCodec::class)]
#[CoversClass(SecretAssociatedData::class)]
final class ExactValueCodecTest extends TestCase
{
    public function testMaximumPrecisionAndScaleRemainExactAndFloatsAreRejected(): void
    {
        $fraction = str_repeat('9', 65);
        $value = ExactDecimal::fromString('0.' . $fraction, 65, 65);

        self::assertSame('0.' . $fraction, $value->value());
        self::assertSame(
            1,
            $value->compare(ExactDecimal::fromString('0.' . str_repeat('8', 65), 65, 65)),
        );
        self::assertSame('0.' . str_repeat('0', 65), ExactDecimal::fromString('-0', 65, 65)->value());

        try {
            ExactDecimal::fromString('0.' . str_repeat('1', 66), 65, 65);
            self::fail('A value exceeding scale 65 must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('fractional digits', $exception->getMessage());
        }

        try {
            ExactDecimal::fromString('1', 65, 65);
            self::fail('A precision-65 scale-65 value cannot have an integer digit.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('precision', $exception->getMessage());
        }

        $codec = self::codec();
        try {
            $codec->normalize(self::field('amount'), 0.1, 'default', NeutralBusinessFixture::DEFINITION_ID, 'row');
            self::fail('A PHP float must never enter exact record storage.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('floats', $exception->getMessage());
        }
    }

    public function testMoneyQuantityAndTemporalValuesNormalizeWithoutLoss(): void
    {
        $codec = self::codec();
        $definitionId = NeutralBusinessFixture::DEFINITION_ID;
        $recordKey = NeutralBusinessFixture::RECORD_ID;

        $amount = $codec->normalize(self::field('amount'), '123.450001', 'default', $definitionId, $recordKey);
        $money = $codec->normalize(
            self::field('price'),
            ['amount' => '19.990000', 'currency' => 'nad'],
            'default',
            $definitionId,
            $recordKey,
        );
        $quantity = $codec->normalize(
            self::field('quantity'),
            ['amount' => '7.125000', 'unit' => 'unit'],
            'default',
            $definitionId,
            $recordKey,
        );
        $date = $codec->normalize(
            self::field('service_date'),
            '2026-08-08',
            'default',
            $definitionId,
            $recordKey,
        );
        $time = $codec->normalize(
            self::field('local_time'),
            '13:14:15.123456',
            'default',
            $definitionId,
            $recordKey,
        );
        $instant = $codec->normalize(
            self::field('recorded_at'),
            '2026-08-08T11:14:15.123456Z',
            'default',
            $definitionId,
            $recordKey,
        );
        $zoned = $codec->normalize(
            self::field('scheduled_for'),
            ['instant' => '2026-08-08T11:14:15.123456Z', 'timezone' => 'Africa/Windhoek'],
            'default',
            $definitionId,
            $recordKey,
        );

        self::assertInstanceOf(ExactDecimal::class, $amount);
        self::assertSame('123.450001000000000000000000000000', $amount->value());
        self::assertInstanceOf(MoneyValue::class, $money);
        self::assertSame([
            'amount' => '19.990000000000000000000000000000',
            'currency' => 'NAD',
        ], $money->toArray());
        self::assertInstanceOf(QuantityValue::class, $quantity);
        self::assertSame([
            'amount' => '7.125000000000000000000000000000',
            'unit' => 'unit',
        ], $quantity->toArray());
        self::assertInstanceOf(DateTimeImmutable::class, $date);
        self::assertSame('2026-08-08', $date->format('Y-m-d'));
        self::assertInstanceOf(DateTimeImmutable::class, $time);
        self::assertSame('13:14:15.123456', $time->format('H:i:s.u'));
        self::assertInstanceOf(DateTimeImmutable::class, $instant);
        self::assertSame('+00:00', $instant->format('P'));
        self::assertInstanceOf(ZonedDateTimeValue::class, $zoned);
        self::assertSame('Africa/Windhoek', $zoned->timezone);
        self::assertSame('2026-08-08T11:14:15.123456Z', $zoned->toArray()['instant']);
    }

    /**
     * Secret encoding binds the stored envelope to the host's exact site, definition, record and field.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSecretEncryptionAuthenticatesCiphertextAndRecordContext(): void
    {
        $key = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        $cipher = new SodiumEnvelopeCipher(new KeyMaterial('unit-key-v1', $key));
        $codec = new RecordValueCodec($cipher);
        $associatedData = SecretAssociatedData::for(
            'default',
            NeutralBusinessFixture::DEFINITION_ID,
            NeutralBusinessFixture::RECORD_ID,
            'credential',
        );
        $envelope = $codec->normalize(
            self::field('credential'),
            'plaintext-must-not-survive',
            'default',
            NeutralBusinessFixture::DEFINITION_ID,
            NeutralBusinessFixture::RECORD_ID,
        );

        self::assertInstanceOf(EncryptedEnvelope::class, $envelope);
        self::assertStringNotContainsString('plaintext-must-not-survive', $envelope->ciphertext);
        self::assertSame('plaintext-must-not-survive', $cipher->decrypt($envelope, $associatedData));

        self::assertSame(
            implode("\n", [
                'business-record-secret-v1',
                'default',
                NeutralBusinessFixture::DEFINITION_ID,
                NeutralBusinessFixture::RECORD_ID,
                'credential',
            ]),
            $associatedData,
        );
        $coordinates = [
            'default',
            NeutralBusinessFixture::DEFINITION_ID,
            NeutralBusinessFixture::RECORD_ID,
            'credential',
        ];
        foreach (array_keys($coordinates) as $coordinate) {
            $otherCell = $coordinates;
            $otherCell[$coordinate] .= '-other';
            try {
                $cipher->decrypt($envelope, SecretAssociatedData::for(...$otherCell));
                self::fail('A stored secret authenticated under another cell coordinate.');
            } catch (RuntimeException $exception) {
                self::assertStringNotContainsString('plaintext-must-not-survive', $exception->getMessage());
            }
        }

        $this->expectException(InvalidArgumentException::class);
        SecretAssociatedData::for(
            'default',
            NeutralBusinessFixture::DEFINITION_ID,
            "row\ninjected-field",
            'credential',
        );
    }

    public function testIntegerCodecUsesThePortableSignedDatabaseRange(): void
    {
        $codec = self::codec();
        $field = new FieldDefinition('sequence', 'Sequence', 'core.integer');

        self::assertSame(
            -2_147_483_648,
            $codec->normalize($field, -2_147_483_648, 'default', NeutralBusinessFixture::DEFINITION_ID, 'row'),
        );
        self::assertSame(
            2_147_483_647,
            $codec->normalize($field, 2_147_483_647, 'default', NeutralBusinessFixture::DEFINITION_ID, 'row'),
        );
        foreach ([-2_147_483_649, 2_147_483_648] as $outside) {
            try {
                $codec->normalize($field, $outside, 'default', NeutralBusinessFixture::DEFINITION_ID, 'row');
                self::fail('An integer outside the signed 32-bit range was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('signed 32-bit', $exception->getMessage());
            }
        }
    }

    public function testTemporalCodecsRejectYearsOutsideThePortableDatabaseRange(): void
    {
        $codec = self::codec();
        foreach (
            [
            ['service_date', '0999-12-31'],
            ['recorded_at', '0999-12-31T23:59:59.999999Z'],
            ['scheduled_for', [
                'instant' => '0999-12-31T23:59:59.999999Z',
                'timezone' => 'Africa/Windhoek',
            ]],
            ] as [$handle, $value]
        ) {
            try {
                $codec->normalize(
                    self::field($handle),
                    $value,
                    'default',
                    NeutralBusinessFixture::DEFINITION_ID,
                    'row',
                );
                self::fail('A temporal value before year 1000 was accepted for ' . $handle . '.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('portable', $exception->getMessage());
            }
        }

        $minimum = $codec->normalize(
            self::field('recorded_at'),
            '1000-01-01T00:00:00.000000Z',
            'default',
            NeutralBusinessFixture::DEFINITION_ID,
            'row',
        );
        self::assertSame('1000-01-01', $minimum->format('Y-m-d'));
    }

    /**
     * A compiled encoding plan writes the exact bytes the unplanned column encode always wrote.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCompiledEncodingPlansMatchTheColumnEncodeExactly(): void
    {
        $definition = EntityTypeDefinition::fromArray(NeutralBusinessFixture::documentLineDocument(
            'codecplan',
            '0191574f-f0b8-7bf3-a9aa-91c6b8245b01',
        ))->published(1);
        $table = new PhysicalTableBlueprint(
            'record',
            'kb_codecplan_record',
            PhysicalTableKind::Entity,
            [
                new PhysicalColumnBlueprint('record_key', 'c_record_key', 'guid'),
                new PhysicalColumnBlueprint('id', 'c_id', 'guid'),
                new PhysicalColumnBlueprint('code', 'c_code', 'string'),
                new PhysicalColumnBlueprint('description', 'c_description', 'string'),
            ],
            ['c_record_key'],
        );
        $codec = self::codec();

        $plan = $codec->encodingPlan($definition, $table);
        self::assertSame(
            ['code', 'description'],
            array_map(
                static fn (PlannedFieldEncoding $planned): string => $planned->field->handle,
                $plan->fields,
            ),
            'The plan holds exactly the columned fields; the UUID identity and column-less fields are out.',
        );

        $values = ['code' => 'A', 'description' => null];
        $encoded = $codec->encodePlanned($plan, $values);
        self::assertSame(['c_code' => 'A', 'c_description' => null], $encoded);
        self::assertSame($codec->encodeColumns($definition, $table, $values), $encoded);
        self::assertSame(
            ['c_code' => 'B'],
            $codec->encodePlanned($plan, ['code' => 'B']),
            'A value collection mentioning one planned field writes only that field.',
        );
    }

    private static function codec(): RecordValueCodec
    {
        return new RecordValueCodec(new SodiumEnvelopeCipher(new KeyMaterial(
            'unit-key-v1',
            str_repeat("\x5a", SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES),
        )));
    }

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
