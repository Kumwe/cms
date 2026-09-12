<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessRecord\Application;

use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordValidationFailed;
use Kumwe\App\BusinessRecord\Application\RecordRuleValidator;
use Kumwe\App\BusinessRecord\Application\RecordValueCodec;
use Kumwe\App\BusinessRecord\Application\ValidationViolation;
use Kumwe\Secret\Cipher\SodiumEnvelopeCipher;
use Kumwe\Secret\Value\KeyMaterial;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Pins how a rule that spans a whole document is judged, and what a breach of one is reported as.
 *
 * The rule is the document's, not a line's, so the violation names the invariant rather than whichever
 * row happened to tip the total. Two refusals matter as much as the rule itself: a caller that hands over
 * no collection is refused rather than told the document is fine, and a rule left unjudged by a caller
 * that could not have moved it is left alone rather than reported.
 *
 * @since  2.0.0
 */
#[CoversClass(RecordRuleValidator::class)]
final class AggregateInvariantValidationTest extends TestCase
{
    /**
     * Proves a document whose total agrees with its lines is accepted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testADocumentWhoseTotalAgreesWithItsLinesIsAccepted(): void
    {
        $values = self::rules()->create(
            self::definition(),
            ['total' => '30.75'],
            'default',
            self::RECORD,
            self::RECORD,
            [],
            ['lines' => [['amount' => '10.25'], ['amount' => '20.50']]],
        );

        self::assertSame('30.75', (string) $values['total']?->value());
    }

    /**
     * Proves a violated aggregate rule is reported against the invariant rather than against a row.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAViolatedAggregateRuleNamesTheInvariantAndNotALine(): void
    {
        try {
            self::rules()->create(
                self::definition(),
                ['total' => '30.75'],
                'default',
                self::RECORD,
                self::RECORD,
                [],
                ['lines' => [['amount' => '10.25'], ['amount' => '20.51']]],
            );
            self::fail('A document contradicting its own lines was accepted.');
        } catch (BusinessRecordValidationFailed $exception) {
            self::assertSame(
                ['total_agrees_with_lines'],
                array_map(
                    static fn (ValidationViolation $violation): string => $violation->field,
                    $exception->violations,
                ),
            );
            self::assertSame(
                ['invariant.total_agrees_with_lines'],
                array_map(
                    static fn (ValidationViolation $violation): string => $violation->code,
                    $exception->violations,
                ),
            );
        }
    }

    /**
     * Proves a thousand lines are folded once for the command and still refuse a document off by a cent.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAThousandLineDocumentIsRefusedForOneCent(): void
    {
        $lines = [];
        for ($index = 0; $index < 1000; ++$index) {
            $lines[] = ['amount' => '1.00'];
        }

        $values = self::rules()->create(
            self::definition(),
            ['total' => '1000.00'],
            'default',
            self::RECORD,
            self::RECORD,
            [],
            ['lines' => $lines],
        );
        self::assertSame('1000.00', (string) $values['total']?->value());

        $this->expectException(BusinessRecordValidationFailed::class);
        self::rules()->create(
            self::definition(),
            ['total' => '1000.01'],
            'default',
            self::RECORD,
            self::RECORD,
            [],
            ['lines' => $lines],
        );
    }

    /**
     * Proves a caller that gathered no collection is refused rather than told the document is consistent.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACallerThatGatheredNoCollectionIsRefused(): void
    {
        try {
            self::rules()->create(
                self::definition(),
                ['total' => '30.75'],
                'default',
                self::RECORD,
                self::RECORD,
            );
            self::fail('A document rule was judged without the lines it reduces.');
        } catch (BusinessRecordValidationFailed $exception) {
            self::assertSame(
                ['invariant_invalid'],
                array_map(
                    static fn (ValidationViolation $violation): string => $violation->code,
                    $exception->violations,
                ),
            );
        }
    }

    /**
     * Proves an aggregate rule is left unjudged, not reported, by a caller that suspends it deliberately.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARepinLeavesAnAggregateRuleUnjudgedRatherThanBreachingIt(): void
    {
        $definition = self::definition();
        $rules = self::rules();
        $stored = $rules->create(
            $definition,
            ['total' => '30.75'],
            'default',
            self::RECORD,
            self::RECORD,
            [],
            ['lines' => [['amount' => '30.75']]],
        );

        $repinned = $rules->repin($definition, $stored, 'default', self::RECORD, self::RECORD);

        self::assertSame('30.75', (string) $repinned['total']?->value());
    }

    /**
     * Proves the collection-only seam judges the aggregate rules and leaves the field rules alone.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheCollectionSeamJudgesOnlyTheRulesThatReduceIt(): void
    {
        $definition = self::definition();
        $rules = self::rules();
        $stored = $rules->create(
            $definition,
            ['total' => '30.75'],
            'default',
            self::RECORD,
            self::RECORD,
            [],
            ['lines' => [['amount' => '30.75']]],
        );

        $rules->assertLineAggregates($definition, $stored, ['lines' => [['amount' => '30.75']]]);

        $this->expectException(BusinessRecordValidationFailed::class);
        $rules->assertLineAggregates($definition, $stored, ['lines' => [['amount' => '30.76']]]);
    }

    /**
     * Stable record identity every case here writes under.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string RECORD = '0191574f-f0b8-7bf3-a9aa-91c6b8244f10';

    /**
     * Build the document header declaring one owned-line collection and the rule that reduces it.
     *
     * @return  EntityTypeDefinition  A published-shaped definition with one aggregate invariant.
     *
     * @since   2.0.0
     */
    private static function definition(): EntityTypeDefinition
    {
        return EntityTypeDefinition::fromArray([
            'id' => Uuid::uuid7()->toString(),
            'owner' => ['type' => 'site', 'identifier' => 'default'],
            'site' => 'default',
            'handle' => 'site.default.rule_document',
            'singular_label' => 'Document',
            'plural_label' => 'Documents',
            'status' => 'draft',
            'definition_version' => 0,
            'storage_mode' => 'relational',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => true,
            'fields' => [
                [
                    'handle' => 'id',
                    'label' => 'ID',
                    'type' => 'core.uuid',
                    'required' => true,
                    'nullable' => false,
                    'unique' => true,
                    'indexed' => true,
                    'immutable_after_create' => true,
                    'server_only' => true,
                    'read_only' => true,
                ],
                [
                    'handle' => 'total',
                    'label' => 'Total',
                    'type' => 'core.decimal',
                    'required' => true,
                    'nullable' => false,
                    'precision' => 18,
                    'scale' => 2,
                ],
            ],
            'relationships' => [[
                'handle' => 'lines',
                'label' => 'Lines',
                'kind' => 'owned_line_collection',
                'target' => 'site.default.rule_document_line',
                'ordered' => true,
                'on_delete' => 'cascade',
            ]],
            'views' => [],
            'actions' => [],
            'workflow' => null,
            'compatibility_metadata' => [],
            'administrator_exposure' => true,
            'portal_exposure' => false,
            'public_exposure' => false,
            'record_invariants' => [[
                'handle' => 'total_agrees_with_lines',
                'message' => 'The document total must equal the sum of its lines.',
                'condition' => [
                    'op' => 'eq',
                    'type' => 'boolean',
                    'args' => [
                        ['op' => 'field', 'type' => 'decimal', 'field' => 'total'],
                        [
                            'op' => 'line_aggregate',
                            'type' => 'decimal',
                            'lines' => 'lines',
                            'field' => 'amount',
                            'aggregate' => 'sum',
                        ],
                    ],
                ],
            ]],
        ]);
    }

    /**
     * Build the validator with a deterministic, obviously synthetic secret key.
     *
     * @return  RecordRuleValidator  A validator over a codec no stored value here actually uses.
     *
     * @since   2.0.0
     */
    private static function rules(): RecordRuleValidator
    {
        $cipher = new SodiumEnvelopeCipher(new KeyMaterial(
            'aggregate-invariant-key-v1',
            str_repeat("\x11", SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES),
        ));

        return new RecordRuleValidator(new RecordValueCodec($cipher));
    }
}
