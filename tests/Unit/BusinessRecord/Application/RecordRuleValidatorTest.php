<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessRecord\Application;

use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordValidationFailed;
use Kumwe\App\BusinessRecord\Application\RecordRuleValidator;
use Kumwe\App\BusinessRecord\Application\RecordValueCodec;
use Kumwe\App\BusinessRecord\Application\ValidationViolation;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\Conversion\Decimal\ExactDecimal;
use Kumwe\Secret\Cipher\SodiumEnvelopeCipher;
use Kumwe\Secret\Value\EncryptedEnvelope;
use Kumwe\Secret\Value\KeyMaterial;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordRuleValidator::class)]
final class RecordRuleValidatorTest extends TestCase
{
    public function testCreateAppliesDefaultsNormalizationEncryptionAndStoredComputation(): void
    {
        $definition = EntityTypeDefinition::fromArray(NeutralBusinessFixture::backupDocument());
        $rules = self::rules();
        $input = NeutralBusinessFixture::recordValues('  Canonical name  ');
        $values = $rules->create(
            $definition,
            $input,
            'default',
            NeutralBusinessFixture::RECORD_ID,
            NeutralBusinessFixture::RECORD_ID,
        );

        self::assertSame(NeutralBusinessFixture::RECORD_ID, $values['id']);
        self::assertSame('Canonical name', $values['name']);
        self::assertSame('draft', $values['status']);
        self::assertFalse($values['enabled']);
        self::assertSame('Canonical name', $values['display_name']);
        self::assertInstanceOf(ExactDecimal::class, $values['amount']);
        self::assertSame(
            '12345678901234567890123456789012345.123456789012345678901234567890',
            $values['amount']->value(),
        );
        self::assertInstanceOf(EncryptedEnvelope::class, $values['credential']);
    }

    public function testUpdateRecomputesDependenciesAndRejectsImmutableOrCallerComputedFields(): void
    {
        $definition = EntityTypeDefinition::fromArray(NeutralBusinessFixture::backupDocument());
        $rules = self::rules();
        $current = $rules->create(
            $definition,
            NeutralBusinessFixture::recordValues('Before'),
            'default',
            NeutralBusinessFixture::RECORD_ID,
            NeutralBusinessFixture::RECORD_ID,
        );
        $updated = $rules->update(
            $definition,
            $current,
            ['name' => '  After  '],
            'default',
            NeutralBusinessFixture::RECORD_ID,
            NeutralBusinessFixture::RECORD_ID,
        );

        self::assertSame('After', $updated['name']);
        self::assertSame('After', $updated['display_name']);

        try {
            $rules->update(
                $definition,
                $updated,
                ['id' => NeutralBusinessFixture::SECOND_RECORD_ID],
                'default',
                NeutralBusinessFixture::RECORD_ID,
                NeutralBusinessFixture::RECORD_ID,
            );
            self::fail('The immutable public identity must not change.');
        } catch (BusinessRecordValidationFailed $exception) {
            self::assertSame('immutable', $exception->violations[0]->code);
        }

        try {
            $input = NeutralBusinessFixture::recordValues();
            $input['display_name'] = 'caller value';
            $rules->create(
                $definition,
                $input,
                'default',
                NeutralBusinessFixture::RECORD_ID,
                NeutralBusinessFixture::RECORD_ID,
            );
            self::fail('A caller must not supply a computed field.');
        } catch (BusinessRecordValidationFailed $exception) {
            self::assertContains('read_only', array_map(
                static fn (ValidationViolation $violation): string => $violation->code,
                $exception->violations,
            ));
        }
    }

    public function testValidationAggregatesUnknownExactAndCrossFieldFailuresDeterministically(): void
    {
        $definition = EntityTypeDefinition::fromArray(NeutralBusinessFixture::backupDocument());
        $input = NeutralBusinessFixture::recordValues('X');
        $input['amount'] = '-1.000000';
        $input['unknown'] = 'value';

        try {
            self::rules()->create(
                $definition,
                $input,
                'default',
                NeutralBusinessFixture::RECORD_ID,
                NeutralBusinessFixture::RECORD_ID,
            );
            self::fail('The invalid record must be rejected.');
        } catch (BusinessRecordValidationFailed $exception) {
            $violations = array_map(
                static fn (ValidationViolation $violation): string => $violation->field . ':' . $violation->code,
                $exception->violations,
            );
            self::assertContains('name:min_length', $violations);
            self::assertContains('amount:min', $violations);
            self::assertContains('unknown:unknown', $violations);
            self::assertContains('non_negative_amount:invariant.non_negative_amount', $violations);
        }
    }

    /**
     * Proves a malformed required value is reported once instead of cascading into a missing-value error.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInvalidTypeDoesNotAlsoReportTheRequiredRule(): void
    {
        $definition = EntityTypeDefinition::fromArray(NeutralBusinessFixture::backupDocument());
        $input = NeutralBusinessFixture::recordValues();
        $input['name'] = 42;

        try {
            self::rules()->create(
                $definition,
                $input,
                'default',
                NeutralBusinessFixture::RECORD_ID,
                NeutralBusinessFixture::RECORD_ID,
            );
            self::fail('A malformed required value must be rejected.');
        } catch (BusinessRecordValidationFailed $exception) {
            self::assertSame(
                ['invalid_type'],
                array_values(array_map(
                    static fn (ValidationViolation $violation): string => $violation->code,
                    array_filter(
                        $exception->violations,
                        static fn (ValidationViolation $violation): bool => $violation->field === 'name',
                    ),
                )),
            );
        }
    }

    public function testRepinRenormalizesStoredValuesRecomputesFormulasAndRejectsTargetViolations(): void
    {
        $definition = EntityTypeDefinition::fromArray(NeutralBusinessFixture::backupDocument());
        $rules = self::rules();
        $stored = $rules->create(
            $definition,
            NeutralBusinessFixture::recordValues('Before'),
            'default',
            NeutralBusinessFixture::RECORD_ID,
            NeutralBusinessFixture::RECORD_ID,
        );
        $stored['name'] = '  Re-pinned  ';
        $stored['display_name'] = 'stale computed value';
        $repinned = $rules->repin(
            $definition,
            $stored,
            'default',
            NeutralBusinessFixture::RECORD_ID,
            NeutralBusinessFixture::RECORD_ID,
        );
        self::assertSame('Re-pinned', $repinned['name']);
        self::assertSame('Re-pinned', $repinned['display_name']);

        $stored['name'] = ' X ';
        try {
            $rules->repin(
                $definition,
                $stored,
                'default',
                NeutralBusinessFixture::RECORD_ID,
                NeutralBusinessFixture::RECORD_ID,
            );
            self::fail('A stored row that violates the target definition must not be repinned.');
        } catch (BusinessRecordValidationFailed $exception) {
            self::assertContains('min_length', array_map(
                static fn (ValidationViolation $violation): string => $violation->code,
                $exception->violations,
            ));
        }
    }

    public function testOptionalNonNullDefaultsApplyAndExplicitNullUpdatesAreRejected(): void
    {
        $document = NeutralBusinessFixture::backupDocument();
        $document['fields'][] = [
            'handle' => 'non_null_optional',
            'label' => 'Non-null optional',
            'type' => 'core.text',
            'required' => false,
            'nullable' => false,
            'default' => 'fallback',
        ];
        $definition = EntityTypeDefinition::fromArray($document);
        $rules = self::rules();
        $values = $rules->create(
            $definition,
            NeutralBusinessFixture::recordValues(),
            'default',
            NeutralBusinessFixture::RECORD_ID,
            NeutralBusinessFixture::RECORD_ID,
        );
        self::assertSame('fallback', $values['non_null_optional']);

        try {
            $rules->update(
                $definition,
                $values,
                ['non_null_optional' => null],
                'default',
                NeutralBusinessFixture::RECORD_ID,
                NeutralBusinessFixture::RECORD_ID,
            );
            self::fail('An explicit null update reached a non-null physical column.');
        } catch (BusinessRecordValidationFailed $exception) {
            self::assertContains('not_nullable', array_map(
                static fn (ValidationViolation $violation): string => $violation->code,
                $exception->violations,
            ));
        }
    }

    /**
     * Proves create and update input satisfies the field's current visibility and editability conditions.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCreateAndUpdateInputsMustSatisfyVisibilityAndEditabilityConditions(): void
    {
        $definition = self::conditionalDefinition();
        $rules = self::rules();

        $hiddenInput = NeutralBusinessFixture::recordValues();
        $hiddenInput['conditional_note'] = 'Caller value';
        self::assertValidationCode(
            static fn (): array => $rules->create(
                $definition,
                $hiddenInput,
                'default',
                NeutralBusinessFixture::RECORD_ID,
                NeutralBusinessFixture::RECORD_ID,
            ),
            'not_visible',
        );

        $readOnlyInput = NeutralBusinessFixture::recordValues();
        $readOnlyInput['enabled'] = true;
        $readOnlyInput['conditional_note'] = 'Caller value';
        self::assertValidationCode(
            static fn (): array => $rules->create(
                $definition,
                $readOnlyInput,
                'default',
                NeutralBusinessFixture::RECORD_ID,
                NeutralBusinessFixture::RECORD_ID,
            ),
            'not_editable',
        );

        $allowedInput = NeutralBusinessFixture::recordValues();
        $allowedInput['enabled'] = true;
        $allowedInput['status'] = 'ready';
        $allowedInput['conditional_note'] = 'Allowed on create';
        $allowed = $rules->create(
            $definition,
            $allowedInput,
            'default',
            NeutralBusinessFixture::RECORD_ID,
            NeutralBusinessFixture::RECORD_ID,
        );
        self::assertSame('Allowed on create', $allowed['conditional_note']);

        $current = $rules->create(
            $definition,
            NeutralBusinessFixture::recordValues(),
            'default',
            NeutralBusinessFixture::RECORD_ID,
            NeutralBusinessFixture::RECORD_ID,
        );
        self::assertValidationCode(
            static fn (): array => $rules->update(
                $definition,
                $current,
                ['conditional_note' => 'Hidden update'],
                'default',
                NeutralBusinessFixture::RECORD_ID,
                NeutralBusinessFixture::RECORD_ID,
            ),
            'not_visible',
        );
        $visible = $rules->update(
            $definition,
            $current,
            ['enabled' => true],
            'default',
            NeutralBusinessFixture::RECORD_ID,
            NeutralBusinessFixture::RECORD_ID,
        );
        self::assertValidationCode(
            static fn (): array => $rules->update(
                $definition,
                $visible,
                ['conditional_note' => 'Read-only update'],
                'default',
                NeutralBusinessFixture::RECORD_ID,
                NeutralBusinessFixture::RECORD_ID,
            ),
            'not_editable',
        );
        $editable = $rules->update(
            $definition,
            $visible,
            ['status' => 'ready'],
            'default',
            NeutralBusinessFixture::RECORD_ID,
            NeutralBusinessFixture::RECORD_ID,
        );
        $updated = $rules->update(
            $definition,
            $editable,
            ['conditional_note' => 'Allowed on update'],
            'default',
            NeutralBusinessFixture::RECORD_ID,
            NeutralBusinessFixture::RECORD_ID,
        );
        self::assertSame('Allowed on update', $updated['conditional_note']);
    }

    /**
     * Build one definition with independent visibility and editability dependencies.
     *
     * @return  EntityTypeDefinition  Definition used by conditional input validation tests.
     *
     * @since   2.0.0
     */
    private static function conditionalDefinition(): EntityTypeDefinition
    {
        $document = NeutralBusinessFixture::backupDocument();
        $document['fields'][] = [
            'handle' => 'conditional_note',
            'label' => 'Conditional note',
            'type' => 'core.text',
            'default' => 'Default note',
            'visibility_condition' => [
                'op' => 'eq',
                'type' => 'boolean',
                'args' => [
                    ['op' => 'field', 'type' => 'boolean', 'field' => 'enabled'],
                    ['op' => 'literal', 'type' => 'boolean', 'value' => true],
                ],
            ],
            'editability_condition' => [
                'op' => 'eq',
                'type' => 'boolean',
                'args' => [
                    ['op' => 'field', 'type' => 'string', 'field' => 'status'],
                    ['op' => 'literal', 'type' => 'string', 'value' => 'ready'],
                ],
            ],
        ];

        return EntityTypeDefinition::fromArray($document);
    }

    /**
     * Require one rejected operation to carry an exact application validation code.
     *
     * @param   callable(): array<string, mixed>  $operation     Operation expected to fail validation.
     * @param   string                            $expectedCode  Stable violation code to find.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertValidationCode(callable $operation, string $expectedCode): void
    {
        try {
            $operation();
            self::fail('A condition-rejected input was accepted.');
        } catch (BusinessRecordValidationFailed $exception) {
            self::assertContains(
                $expectedCode,
                array_map(
                    static fn (ValidationViolation $violation): string => $violation->code,
                    $exception->violations,
                ),
            );
        }
    }

    private static function rules(): RecordRuleValidator
    {
        $cipher = new SodiumEnvelopeCipher(new KeyMaterial(
            'validation-key-v1',
            str_repeat("\x42", SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES),
        ));

        return new RecordRuleValidator(new RecordValueCodec($cipher));
    }
}
