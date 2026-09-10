<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Domain\Projection;

use InvalidArgumentException;
use Kumwe\App\Studio\Domain\Projection\EntryCompositionOverrides;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Producer\Canonical\CanonicalEncodingException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves per-entry composition overrides are canonical immutable objects within their storage bounds.
 *
 * @since  2.0.0
 */
#[CoversClass(EntryCompositionOverrides::class)]
final class EntryCompositionOverridesTest extends TestCase
{
    /**
     * Canonical bytes detach the value from both the supplied object and every returned copy.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOverridesAreCanonicalAndImmutableAcrossObjectMutations(): void
    {
        $source = (object) [
            'z-slot' => (object) ['visible' => true, 'tone' => 'calm'],
            'a-slot' => ['first', 2],
        ];
        $overrides = new EntryCompositionOverrides(
            SiteContext::fromString('Publisher-Namibia'),
            '018f22e2-7c8b-7ab0-8f3a-88e8026be200',
            $source,
            4,
        );
        $source->{'z-slot'}->tone = 'mutated';
        $firstCopy = $overrides->values();
        $firstCopy->{'z-slot'}->tone = 'also-mutated';
        $secondCopy = $overrides->values();

        self::assertSame('publisher-namibia', $overrides->site->identifier());
        self::assertSame('018f22e2-7c8b-7ab0-8f3a-88e8026be200', $overrides->entryId);
        self::assertSame(4, $overrides->revision);
        self::assertSame(
            '{"a-slot":["first",2],"z-slot":{"tone":"calm","visible":true}}',
            $overrides->canonical(),
        );
        self::assertSame('calm', $secondCopy->{'z-slot'}->tone);
        self::assertNotSame($firstCopy, $secondCopy);
        self::assertNotSame($firstCopy->{'z-slot'}, $secondCopy->{'z-slot'});
    }

    /**
     * Invalid entry identity, revision, key count, key grammar and byte budget are rejected independently.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOverrideMetadataAndBudgetsAreBounded(): void
    {
        $tooMany = new \stdClass();
        for ($index = 0; $index < 1001; $index++) {
            $tooMany->{'slot-' . $index} = true;
        }
        $tooLarge = (object) ['slot' => str_repeat('x', 1_048_576)];
        $cases = [
            'entry UUID' => ['not-a-uuid', (object) [], 1],
            'revision' => ['018f22e2-7c8b-7ab0-8f3a-88e8026be200', (object) [], 0],
            'key grammar' => [
                '018f22e2-7c8b-7ab0-8f3a-88e8026be200',
                (object) ['bad key' => true],
                1,
            ],
            'reserved key' => [
                '018f22e2-7c8b-7ab0-8f3a-88e8026be200',
                (object) ['__proto__' => true],
                1,
            ],
            'nested member grammar' => [
                '018f22e2-7c8b-7ab0-8f3a-88e8026be200',
                (object) ['slot' => (object) ["bad\nkey" => true]],
                1,
            ],
            'member count' => ['018f22e2-7c8b-7ab0-8f3a-88e8026be200', $tooMany, 1],
            'byte budget' => ['018f22e2-7c8b-7ab0-8f3a-88e8026be200', $tooLarge, 1],
        ];

        foreach ($cases as $label => [$entryId, $values, $revision]) {
            try {
                new EntryCompositionOverrides(SiteContext::default(), $entryId, $values, $revision);
                self::fail(sprintf('The invalid override %s was accepted.', $label));
            } catch (InvalidArgumentException $failure) {
                self::assertNotSame('', $failure->getMessage(), $label);
            }
        }
    }

    /**
     * Canonical JSON refusals propagate rather than silently dropping dangerous or non-JSON members.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNonCanonicalOverrideValuesAreRefusedWhole(): void
    {
        $forbidden = new \stdClass();
        $forbidden->{'__proto__'} = 'pollution';
        $nestedForbidden = (object) ['slot' => $forbidden];
        $notJson = (object) ['slot' => fopen('php://memory', 'rb')];

        foreach ([$nestedForbidden, $notJson] as $values) {
            try {
                new EntryCompositionOverrides(
                    SiteContext::default(),
                    '018f22e2-7c8b-7ab0-8f3a-88e8026be200',
                    $values,
                    1,
                );
                self::fail('A non-canonical override object was accepted.');
            } catch (CanonicalEncodingException $failure) {
                self::assertContains($failure->rejection(), ['forbidden-member', 'unrepresentable']);
            }
        }
    }
}
