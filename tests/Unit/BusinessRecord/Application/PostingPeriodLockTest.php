<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessRecord\Application;

use DateTimeImmutable;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\ScopeMode;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordPostingPeriodClosed;
use Kumwe\App\BusinessRecord\Application\PostingPeriodLock;
use Kumwe\App\BusinessRecord\Application\PostingPeriodRepository;
use Kumwe\App\BusinessRecord\Application\RecordValueCodec;
use Kumwe\App\BusinessRecord\Domain\BusinessRecord;
use Kumwe\App\BusinessRecord\Domain\PostingPeriod;
use Kumwe\App\BusinessRecord\Domain\PostingPeriodStatus;
use Kumwe\App\BusinessRecord\Domain\RecordScope;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Secret\Cipher\SodiumEnvelopeCipher;
use Kumwe\Secret\Value\KeyMaterial;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Pins which posting dates the temporal lock reads, and which mutations it leaves alone.
 *
 * @since  2.0.0
 */
#[CoversClass(PostingPeriodLock::class)]
#[CoversClass(BusinessRecordPostingPeriodClosed::class)]
final class PostingPeriodLockTest extends TestCase
{
    /**
     * A definition that declares no posting date is untouched: nothing is read and nothing refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testADefinitionWithoutADeclarationIsUntouched(): void
    {
        $repository = $this->repository();
        $lock = new PostingPeriodLock($repository, $this->codec());
        $definition = $this->definition(false);

        $lock->assertMutationOpen(
            $definition,
            $this->scope(),
            $this->record($definition, '2026-08-08'),
            ['service_date' => '2026-08-09'],
        );

        self::assertSame(0, $repository->reads, 'An undeclared definition must consult no periods at all.');
    }

    /**
     * A stored posting date inside a closed period refuses the mutation under the stable named code.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStoredPostingDateInsideAClosedPeriodRefuses(): void
    {
        $lock = new PostingPeriodLock($this->repository($this->closedAugust()), $this->codec());
        $definition = $this->definition();

        try {
            $lock->assertMutationOpen($definition, $this->scope(), $this->record($definition, '2026-08-08'));
            self::fail('A record dated inside a closed period must refuse every mutation.');
        } catch (BusinessRecordPostingPeriodClosed $refused) {
            self::assertSame('business_record.posting_period_closed', $refused->stableCode());
            self::assertSame('2026-08', $refused->periodKey);
            self::assertSame('2026-08-08', $refused->postingDate->format('Y-m-d'));
        }
    }

    /**
     * A submitted posting date inside a closed period refuses a creation — backdating included.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testASubmittedPostingDateInsideAClosedPeriodRefusesACreate(): void
    {
        $lock = new PostingPeriodLock($this->repository($this->closedAugust()), $this->codec());

        $this->expectException(BusinessRecordPostingPeriodClosed::class);
        $lock->assertMutationOpen(
            $this->definition(),
            $this->scope(),
            null,
            ['service_date' => '2026-08-15'],
            true,
        );
    }

    /**
     * A create that omits the posting field is judged at the field's declared default.
     *
     * A lock that ignored defaults would admit a backdated row simply because the caller left the
     * field out and let the definition fill it in.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACreateOmittingThePostingFieldIsJudgedAtItsDeclaredDefault(): void
    {
        $lock = new PostingPeriodLock($this->repository($this->closedAugust()), $this->codec());

        $this->expectException(BusinessRecordPostingPeriodClosed::class);
        $lock->assertMutationOpen(
            $this->definition(true, '2026-08-20'),
            $this->scope(),
            null,
            [],
            true,
        );
    }

    /**
     * Dates outside every closed range pass, and an open period does not refuse.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDatesOutsideClosedRangesAndOpenPeriodsAdmitTheMutation(): void
    {
        $openAugust = new PostingPeriod(
            'default',
            null,
            '2026-08',
            new DateTimeImmutable('2026-08-01T00:00:00Z'),
            new DateTimeImmutable('2026-09-01T00:00:00Z'),
            PostingPeriodStatus::Open,
            'actor-1',
            new DateTimeImmutable('2026-09-05T08:00:00Z'),
        );
        $lock = new PostingPeriodLock(
            $this->repository($openAugust, $this->closedAugust('2026-07', '-1 month')),
            $this->codec(),
        );
        $definition = $this->definition();

        $lock->assertMutationOpen($definition, $this->scope(), $this->record($definition, '2026-08-08'));
        $lock->assertMutationOpen(
            $definition,
            $this->scope(),
            $this->record($definition, '2026-09-02'),
            ['service_date' => '2026-09-03'],
        );

        self::assertTrue(true);
    }

    /**
     * A zoned posting declaration is judged at its absolute instant, and a dateless value at nothing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAZonedPostingDateIsJudgedAtItsInstant(): void
    {
        $lock = new PostingPeriodLock($this->repository($this->closedAugust()), $this->codec());
        $document = NeutralBusinessFixture::document();
        foreach ($document['fields'] as &$field) {
            if (is_array($field) && ($field['handle'] ?? null) === 'scheduled_for') {
                $field['configuration'] = ['posting_date' => true];
            }
        }
        unset($field);
        $definition = EntityTypeDefinition::fromArray($document);

        try {
            $lock->assertMutationOpen($definition, $this->scope(), null, [
                'scheduled_for' => [
                    'instant' => '2026-08-15T10:00:00Z',
                    'timezone' => 'Africa/Windhoek',
                ],
            ], true);
            self::fail('A zoned posting date inside the closed period must refuse.');
        } catch (BusinessRecordPostingPeriodClosed $refused) {
            self::assertSame('2026-08', $refused->periodKey);
        }

        // A stored value of a shape the posting types never produce carries no instant to judge.
        $record = new BusinessRecord(
            $definition->id,
            1,
            Uuid::uuid7()->toString(),
            Uuid::uuid7()->toString(),
            $this->scope(),
            1,
            null,
            ['scheduled_for' => 'not-a-value'],
            'actor-1',
            new DateTimeImmutable('2026-08-08T10:00:00Z'),
            'actor-1',
            new DateTimeImmutable('2026-08-08T10:00:00Z'),
        );
        $lock->assertMutationOpen($definition, $this->scope(), $record);
        self::assertTrue(true);
    }

    /**
     * A malformed submitted value is left for the validation path rather than refused here.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMalformedSubmittedValueIsLeftForValidation(): void
    {
        $lock = new PostingPeriodLock($this->repository($this->closedAugust()), $this->codec());

        $lock->assertMutationOpen(
            $this->definition(),
            $this->scope(),
            null,
            ['service_date' => 'not-a-date'],
            true,
        );

        self::assertTrue(true);
    }

    /**
     * Build the neutral definition, optionally declaring `service_date` as the posting date.
     *
     * @param   bool     $declared  Whether the posting-date declaration is present.
     * @param   ?string  $default   Declared default for the field, or null for none.
     *
     * @return  EntityTypeDefinition  Definition under test.
     *
     * @since   2.0.0
     */
    private function definition(bool $declared = true, ?string $default = null): EntityTypeDefinition
    {
        $document = NeutralBusinessFixture::document();
        foreach ($document['fields'] as &$field) {
            if (is_array($field) && ($field['handle'] ?? null) === 'service_date') {
                if ($declared) {
                    $field['configuration'] = ['posting_date' => true];
                }
                if ($default !== null) {
                    $field['default'] = $default;
                    $field['required'] = false;
                    $field['nullable'] = true;
                }
            }
        }
        unset($field);

        return EntityTypeDefinition::fromArray($document);
    }

    /**
     * Build a stored record whose `service_date` value carries the given posting date.
     *
     * @param   EntityTypeDefinition  $definition  Definition the record belongs to.
     * @param   string                $date        Posting date, as `YYYY-MM-DD`.
     *
     * @return  BusinessRecord  Stored record double with normalised values.
     *
     * @since   2.0.0
     */
    private function record(EntityTypeDefinition $definition, string $date): BusinessRecord
    {
        $now = new DateTimeImmutable('2026-08-08T10:00:00Z');

        return new BusinessRecord(
            $definition->id,
            1,
            Uuid::uuid7()->toString(),
            Uuid::uuid7()->toString(),
            $this->scope(),
            1,
            null,
            ['service_date' => new DateTimeImmutable($date . 'T00:00:00Z')],
            'actor-1',
            $now,
            'actor-1',
            $now,
        );
    }

    /**
     * Site scope every case runs in.
     *
     * @return  RecordScope  Site-mode scope for the default site.
     *
     * @since   2.0.0
     */
    private function scope(): RecordScope
    {
        return RecordScope::forDefinition(ScopeMode::Site, SiteContext::default(), null);
    }

    /**
     * Build the closed August 2026 declaration.
     *
     * @param   string  $key     Stable period key.
     * @param   string  $offset  Relative offset applied to both boundaries.
     *
     * @return  PostingPeriod  Closed declaration.
     *
     * @since   2.0.0
     */
    private function closedAugust(string $key = '2026-08', string $offset = '+0 seconds'): PostingPeriod
    {
        return new PostingPeriod(
            'default',
            null,
            $key,
            (new DateTimeImmutable('2026-08-01T00:00:00Z'))->modify($offset),
            (new DateTimeImmutable('2026-09-01T00:00:00Z'))->modify($offset),
            PostingPeriodStatus::Closed,
            'actor-1',
            new DateTimeImmutable('2026-09-05T08:00:00Z'),
        );
    }

    /**
     * Build an in-memory repository over the given declarations, counting containment reads.
     *
     * @param   PostingPeriod  $periods  Declarations the repository serves.
     *
     * @return  PostingPeriodRepository&object{reads: int}  Counting in-memory store.
     *
     * @since   2.0.0
     */
    private function repository(PostingPeriod ...$periods): PostingPeriodRepository
    {
        return new class (array_values($periods)) implements PostingPeriodRepository {
            /**
             * Containment reads served so far.
             *
             * @var    int
             * @since  2.0.0
             */
            public int $reads = 0;

            /**
             * Hold the declarations this double serves.
             *
             * @param  list<PostingPeriod>  $periods  Declarations, in declaration order.
             *
             * @since  2.0.0
             */
            public function __construct(private array $periods)
            {
            }

            /**
             * Read one declaration by scope and key.
             *
             * @param   string   $siteIdentifier          Site addressed.
             * @param   ?string  $organizationIdentifier  Organization scope, or null.
             * @param   string   $key                     Stable key.
             *
             * @return  ?PostingPeriod  The declaration, or null.
             *
             * @since   2.0.0
             */
            public function find(
                string $siteIdentifier,
                ?string $organizationIdentifier,
                string $key,
            ): ?PostingPeriod {
                foreach ($this->periods as $period) {
                    if ($period->key === $key) {
                        return $period;
                    }
                }

                return null;
            }

            /**
             * Store one declaration.
             *
             * @param   PostingPeriod  $period  Declaration to keep.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function save(PostingPeriod $period): void
            {
                $this->periods[] = $period;
            }

            /**
             * List every held declaration.
             *
             * @param   string   $siteIdentifier          Site addressed.
             * @param   ?string  $organizationIdentifier  Organization scope, or null.
             *
             * @return  list<PostingPeriod>  Declarations in declaration order.
             *
             * @since   2.0.0
             */
            public function listFor(string $siteIdentifier, ?string $organizationIdentifier): array
            {
                return $this->periods;
            }

            /**
             * Answer the first closed declaration containing the instant.
             *
             * @param   string             $siteIdentifier          Site addressed.
             * @param   ?string            $organizationIdentifier  Organization scope, or null.
             * @param   DateTimeImmutable  $instant                 Instant to classify.
             *
             * @return  ?PostingPeriod  The refusing declaration, or null.
             *
             * @since   2.0.0
             */
            public function closedPeriodContaining(
                string $siteIdentifier,
                ?string $organizationIdentifier,
                DateTimeImmutable $instant,
            ): ?PostingPeriod {
                ++$this->reads;
                foreach ($this->periods as $period) {
                    if ($period->isClosed() && $period->contains($instant)) {
                        return $period;
                    }
                }

                return null;
            }
        };
    }

    /**
     * Build the value codec the lock normalises submitted dates through.
     *
     * @return  RecordValueCodec  Codec over a unit-test cipher.
     *
     * @since   2.0.0
     */
    private function codec(): RecordValueCodec
    {
        return new RecordValueCodec(new SodiumEnvelopeCipher(new KeyMaterial(
            'unit-key-v1',
            str_repeat("\x5a", SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES),
        )));
    }
}
