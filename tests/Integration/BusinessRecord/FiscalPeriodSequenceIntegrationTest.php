<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessRecord;

use DateTimeImmutable;
use DateTimeZone;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\Extension\Spi\Application\Automation\IdempotencyKey;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordPostingPeriodUndeclared;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordValidationFailed;
use Kumwe\App\BusinessRecord\Application\PostingPeriodService;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Proves the fiscal-period reset whole: declared periods partition one gapless run per period key.
 *
 * A `fiscal-period` sequence keys its counter on the stable key of the declared posting period
 * containing the record's own posting date — never on the instant the create happens to run at. What
 * is pinned here, against the real container, database and allocator: two creates dated inside two
 * different declared periods draw separate contiguous runs that each start at one and render the
 * declared key as the number's period segment; a create dated where no period was declared refuses
 * with the stable `business_record.posting_period_undeclared` code and burns no number, as does a
 * create carrying no posting date at all; a malformed posting date stays a field validation failure
 * rather than a period refusal; and a definition on the calendar resets is completely unaffected by
 * everything the posting-period surface declares.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessRecordService::class)]
#[CoversClass(BusinessRecordPostingPeriodUndeclared::class)]
final class FiscalPeriodSequenceIntegrationTest extends TestCase
{
    /**
     * Stable per-process suffix keeping this suite's definitions and periods apart from other runs.
     *
     * @var    ?string
     * @since  2.0.0
     */
    private static ?string $suffix = null;

    /**
     * The whole fiscal-period journey: two period runs, the named refusals, and the untouched control.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testFiscalPeriodsPartitionRunsAndAnUndeclaredDateRefusesByName(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        $periods = $container->get(PostingPeriodService::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(PostingPeriodService::class, $periods);
        $suffix = $this->suffix();

        // The database outlives test processes and periods are site-wide, so this run's declared
        // windows sit at a per-run offset far outside every date any other suite posts into.
        $base = (new DateTimeImmutable('3100-01-01T00:00:00Z'))
            ->modify('+' . ((int) hexdec(substr($suffix, 0, 8)) % 250_000) . ' days');
        $periodP = 'fpa-' . $suffix;
        $periodQ = 'fpb-' . $suffix;
        $this->declareOpenPeriod($periods, $context, $periodP, $base, $base->modify('+10 days'));
        $this->declareOpenPeriod($periods, $context, $periodQ, $base->modify('+10 days'), $base->modify('+20 days'));

        $fiscal = $this->installFiscalDefinition($container, $context, $suffix);

        // Two creates dated inside period P take one contiguous run keyed by P's declared key.
        self::assertSame(
            'FIS-' . $periodP . '-0001',
            $this->create($records, $context, $fiscal, 'Fiscal one ' . $suffix, $base->modify('+2 days')),
        );
        self::assertSame(
            'FIS-' . $periodP . '-0002',
            $this->create($records, $context, $fiscal, 'Fiscal two ' . $suffix, $base->modify('+3 days')),
        );

        // A create dated inside period Q starts Q's own run at one; P's run is untouched by it.
        self::assertSame(
            'FIS-' . $periodQ . '-0001',
            $this->create($records, $context, $fiscal, 'Fiscal three ' . $suffix, $base->modify('+12 days')),
        );

        // A posting date no declaration contains refuses by name: allocating under an empty period
        // key would silently hand the document a lifetime number, which is what `Never` means.
        $uncovered = $base->modify('+25 days');
        try {
            $this->create($records, $context, $fiscal, 'Fiscal undeclared ' . $suffix, $uncovered);
            self::fail('A posting date outside every declared period must refuse the allocation.');
        } catch (BusinessRecordPostingPeriodUndeclared $refused) {
            self::assertSame('business_record.posting_period_undeclared', $refused->stableCode());
            self::assertSame($uncovered->format('Y-m-d'), $refused->postingDate?->format('Y-m-d'));
        }

        // A record carrying no posting date at all is contained by no period either.
        try {
            $records->create(new CreateRecordCommand(
                $context,
                $fiscal->id,
                NeutralBusinessFixture::recordValues('Fiscal undated ' . $suffix),
                $this->key('undated-' . $suffix),
            ));
            self::fail('A fiscal-period sequence must refuse a record that declares no posting date.');
        } catch (BusinessRecordPostingPeriodUndeclared $refused) {
            self::assertNull($refused->postingDate);
        }

        // A malformed posting date stays the validation path's refusal, never a period verdict.
        try {
            $records->create(new CreateRecordCommand(
                $context,
                $fiscal->id,
                [
                    ...NeutralBusinessFixture::recordValues('Fiscal malformed ' . $suffix),
                    'posted_on' => 'not-a-date',
                ],
                $this->key('malformed-' . $suffix),
            ));
            self::fail('A malformed posting date must be reported as a field violation.');
        } catch (BusinessRecordValidationFailed $refused) {
            self::assertSame('posted_on', $refused->violations[0]->field);
        }

        // The refusals burned nothing: P's run continues contiguously at three.
        self::assertSame(
            'FIS-' . $periodP . '-0003',
            $this->create($records, $context, $fiscal, 'Fiscal four ' . $suffix, $base->modify('+4 days')),
        );

        // A calendar-reset definition is completely unaffected: it allocates from the command
        // instant's year wherever its posting date falls, declared period or none at all.
        $control = $this->installControlDefinition($container, $context, $suffix);
        $year = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y');
        self::assertSame(
            'CTL-' . $year . '-0001',
            $this->create($records, $context, $control, 'Control one ' . $suffix, $uncovered),
        );
        self::assertSame(
            'CTL-' . $year . '-0002',
            $this->create($records, $context, $control, 'Control two ' . $suffix, $base->modify('+2 days')),
        );
    }

    /**
     * Declare one open posting period, through the only administrative surface that can declare one.
     *
     * `PostingPeriodService` declares a range by closing it, so an open declaration is a close
     * followed by the audited re-open — the same lifecycle an operator drives.
     *
     * @param   PostingPeriodService  $periods   Administrative posting-period surface.
     * @param   ExecutionContext      $context   Administrator holding the manage capability.
     * @param   string                $key       Stable key the period is declared under.
     * @param   DateTimeImmutable     $startsAt  First instant inside the range, inclusive.
     * @param   DateTimeImmutable     $endsAt    First instant past the range, exclusive.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function declareOpenPeriod(
        PostingPeriodService $periods,
        ExecutionContext $context,
        string $key,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
    ): void {
        $periods->close($context, $key, $startsAt, $endsAt);
        $periods->reopen($context, $key);
    }

    /**
     * Install the fiscal fixture: the neutral document widened by a posting date and a fiscal sequence.
     *
     * @param   Container         $container  Real integration container.
     * @param   ExecutionContext  $context    Administrator the installation runs as.
     * @param   string            $suffix     Per-process fixture suffix.
     *
     * @return  EntityTypeDefinition  The installed definition.
     *
     * @since   2.0.0
     */
    private function installFiscalDefinition(
        Container $container,
        ExecutionContext $context,
        string $suffix,
    ): EntityTypeDefinition {
        $document = NeutralBusinessFixture::document('f' . $suffix, Uuid::uuid7()->toString());
        $document['fields'][] = $this->postingDateField();
        $document['fields'][] = $this->sequenceField('fiscal_number', 'fiscal-period', 'FIS-');

        return NeutralBusinessFixture::install($container, $context, $document);
    }

    /**
     * Install the control fixture: the same shape on a yearly reset, proving the calendar cases hold.
     *
     * @param   Container         $container  Real integration container.
     * @param   ExecutionContext  $context    Administrator the installation runs as.
     * @param   string            $suffix     Per-process fixture suffix.
     *
     * @return  EntityTypeDefinition  The installed definition.
     *
     * @since   2.0.0
     */
    private function installControlDefinition(
        Container $container,
        ExecutionContext $context,
        string $suffix,
    ): EntityTypeDefinition {
        $document = NeutralBusinessFixture::document('y' . $suffix, Uuid::uuid7()->toString());
        $document['fields'][] = $this->postingDateField();
        $document['fields'][] = $this->sequenceField('control_number', 'yearly', 'CTL-');

        return NeutralBusinessFixture::install($container, $context, $document);
    }

    /**
     * Declare the nullable posting-date field both fixtures carry.
     *
     * @return  array<string, mixed>  A `core.date` field declared as the posting date.
     *
     * @since   2.0.0
     */
    private function postingDateField(): array
    {
        return [
            'handle' => 'posted_on',
            'label' => 'Posted on',
            'type' => 'core.date',
            'required' => false,
            'nullable' => true,
            'filterable' => true,
            'sortable' => true,
            'configuration' => ['posting_date' => true],
        ];
    }

    /**
     * Declare one closed allocated-number field under the given reset period.
     *
     * @param   string  $handle  Field handle the run is allocated under.
     * @param   string  $reset   Reset period the sequence declares.
     * @param   string  $prefix  Literal head of the rendered number.
     *
     * @return  array<string, mixed>  A closed, required, unique `core.sequence` field declaration.
     *
     * @since   2.0.0
     */
    private function sequenceField(string $handle, string $reset, string $prefix): array
    {
        return [
            'handle' => $handle,
            'label' => ucfirst(str_replace('_', ' ', $handle)),
            'type' => 'core.sequence',
            'configuration' => [
                'scope' => 'site',
                'reset' => $reset,
                'prefix' => $prefix,
                'padding' => 4,
                'timezone' => 'UTC',
            ],
            'required' => true,
            'nullable' => false,
            'length' => 36,
            'unique' => true,
            'indexed' => true,
            'immutable_after_create' => true,
            'server_only' => true,
            'read_only' => true,
            'create_visible' => false,
            'update_visible' => false,
            'sortable' => true,
            'filterable' => true,
        ];
    }

    /**
     * Create one dated record and read its allocated number back through the ordinary read path.
     *
     * @param   BusinessRecordService  $records     Live record service.
     * @param   ExecutionContext       $context     Administrator the create runs as.
     * @param   EntityTypeDefinition   $definition  Installed fixture definition.
     * @param   string                 $name        Unique record name.
     * @param   DateTimeImmutable      $postedOn    Posting date the record declares.
     *
     * @return  string  The rendered number the definition's sequence field was given.
     *
     * @since   2.0.0
     */
    private function create(
        BusinessRecordService $records,
        ExecutionContext $context,
        EntityTypeDefinition $definition,
        string $name,
        DateTimeImmutable $postedOn,
    ): string {
        $recordId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $definition->id,
            [
                ...NeutralBusinessFixture::recordValues($name),
                'posted_on' => $postedOn->format('Y-m-d'),
            ],
            $this->key(strtolower(str_replace(' ', '-', $name))),
            recordId: $recordId,
        ));
        $view = $records->read(new ReadRecordQuery($context, $definition->id, $recordId));
        foreach ($view->values as $handle => $value) {
            if (str_ends_with($handle, '_number') && is_string($value)) {
                return $value;
            }
        }
        self::fail('The created record carries no allocated number.');
    }

    /**
     * Mint one idempotency key unique to this process and operation.
     *
     * @param   string  $operation  Short operation slug.
     *
     * @return  IdempotencyKey  Key under the neutral fixture namespace.
     *
     * @since   2.0.0
     */
    private function key(string $operation): IdempotencyKey
    {
        return NeutralBusinessFixture::idempotencyKey('fiscal-' . $operation);
    }

    /**
     * Answer the per-process suffix, minting it on first use.
     *
     * @return  string  Twelve lowercase hex characters.
     *
     * @since   2.0.0
     */
    private function suffix(): string
    {
        return self::$suffix ??= strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
    }
}
