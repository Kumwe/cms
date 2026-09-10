<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordPostingPeriodConflict;
use Kumwe\App\BusinessRecord\Domain\PostingPeriod;
use Kumwe\App\BusinessRecord\Domain\PostingPeriodStatus;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * Administrative face of the posting-period lock: the only supported way to close or re-open a range.
 *
 * Closing a period is an administrative act with installation-wide financial consequences, so it runs
 * the way other administered state runs here: the actor must hold the capability, the change and its
 * audit entry share one transaction, and the audit trail — not the row — is the history of who closed
 * or re-opened what and when. Core supplies no fiscal calendar through this surface: the caller names
 * the key and the half-open range, so what a period is, when it closes and what re-opening means stay
 * the declaring extension's rules. Both console and REST administration drive this one service, so the
 * capability gate and the audit entry cannot diverge between surfaces.
 *
 * Closing declares the range where none exists yet and closes a re-opened one; either way the range
 * behind an existing key is immutable — re-declaring a key over different instants is refused, because
 * every refusal already made under that key was made over the declared range.
 *
 * @since  2.0.0
 */
final readonly class PostingPeriodService
{
    /**
     * Capability closing and re-opening are gated on.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string MANAGE = 'business.period.manage';

    /**
     * Capability listing declared periods is gated on.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string READ = 'business.period.read';

    /**
     * Wire the service to the declaration store, the gate, and its transactional bookkeeping.
     *
     * @param  PostingPeriodRepository  $periods        Store the declarations are read from and
     *         written to.
     * @param  AuthorizationGateway     $authorization  Guard consulted before anything is read or
     *         written.
     * @param  TransactionManager       $transactions   Boundary the state change and its audit entry
     *         share.
     * @param  AuditRecorder            $audit          Sink every close and re-open is recorded to.
     * @param  ClockInterface           $clock          Source of the instant stored and audited.
     *
     * @since  2.0.0
     */
    public function __construct(
        private PostingPeriodRepository $periods,
        private AuthorizationGateway $authorization,
        private TransactionManager $transactions,
        private AuditRecorder $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Close one period, declaring its range when this key has not been declared before.
     *
     * @param   ExecutionContext   $context                 Actor and site the close runs as.
     * @param   string             $key                     Stable key the extension names the period by.
     * @param   DateTimeImmutable  $startsAt                First instant inside the range, inclusive.
     * @param   DateTimeImmutable  $endsAt                  First instant past the range, exclusive.
     * @param   ?string            $organizationIdentifier  Organization the period is confined to, or
     *          null for a site-wide period.
     *
     * @return  PostingPeriod  The declaration as now stored, closed.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage
     *          posting periods.
     * @throws  InvalidArgumentException  When the key, range or organization is malformed.
     * @throws  BusinessRecordPostingPeriodConflict  When the key is already declared over a different
     *          range, or the period is already closed.
     *
     * @since   2.0.0
     */
    public function close(
        ExecutionContext $context,
        string $key,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        ?string $organizationIdentifier = null,
    ): PostingPeriod {
        $this->authorize($context, self::MANAGE);
        $site = $context->site()->identifier();
        $now = $this->clock->now();
        $declared = new PostingPeriod(
            $site,
            $organizationIdentifier,
            $key,
            $startsAt,
            $endsAt,
            PostingPeriodStatus::Closed,
            $context->actorId(),
            $now,
        );

        return $this->transactions->transactional(function () use (
            $context,
            $site,
            $organizationIdentifier,
            $key,
            $declared,
            $now,
        ): PostingPeriod {
            $existing = $this->periods->find($site, $organizationIdentifier, $key);
            if ($existing !== null) {
                if (
                    $existing->startsAt->getTimestamp() !== $declared->startsAt->getTimestamp()
                    || $existing->endsAt->getTimestamp() !== $declared->endsAt->getTimestamp()
                ) {
                    throw new BusinessRecordPostingPeriodConflict(
                        'The posting period key is already declared over a different range.',
                    );
                }
                if ($existing->isClosed()) {
                    throw new BusinessRecordPostingPeriodConflict('The posting period is already closed.');
                }
            }
            $stored = $existing === null ? $declared : $existing->closed($context->actorId(), $now);
            $this->periods->save($stored);
            $this->record($context, 'business.period.close', $stored, $now);

            return $stored;
        });
    }

    /**
     * Re-open one closed period so its range admits mutations again.
     *
     * @param   ExecutionContext  $context                 Actor and site the re-open runs as.
     * @param   string            $key                     Stable key of the period to re-open.
     * @param   ?string           $organizationIdentifier  Organization scope of the declaration, or
     *          null for the site-wide one.
     *
     * @return  PostingPeriod  The declaration as now stored, open.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage
     *          posting periods.
     * @throws  BusinessRecordPostingPeriodConflict  When the key names no declaration in this scope,
     *          or the period is not closed.
     *
     * @since   2.0.0
     */
    public function reopen(
        ExecutionContext $context,
        string $key,
        ?string $organizationIdentifier = null,
    ): PostingPeriod {
        $this->authorize($context, self::MANAGE);
        $site = $context->site()->identifier();
        $now = $this->clock->now();

        return $this->transactions->transactional(function () use (
            $context,
            $site,
            $organizationIdentifier,
            $key,
            $now,
        ): PostingPeriod {
            $existing = $this->periods->find($site, $organizationIdentifier, $key);
            if ($existing === null || !$existing->isClosed()) {
                throw new BusinessRecordPostingPeriodConflict(
                    'Only a declared, closed posting period can be re-opened.',
                );
            }
            $stored = $existing->reopened($context->actorId(), $now);
            $this->periods->save($stored);
            $this->record($context, 'business.period.reopen', $stored, $now);

            return $stored;
        });
    }

    /**
     * List the declarations governing this actor's site, or one organization within it.
     *
     * @param   ExecutionContext  $context                 Actor and site the listing runs as.
     * @param   ?string           $organizationIdentifier  Organization whose declarations are listed
     *          beside the site-wide ones, or null to list every declaration on the site.
     *
     * @return  list<PostingPeriod>  Declarations ordered by their range start and then by key.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not read
     *          posting periods.
     *
     * @since   2.0.0
     */
    public function list(ExecutionContext $context, ?string $organizationIdentifier = null): array
    {
        $this->authorize($context, self::READ);

        return $this->periods->listFor($context->site()->identifier(), $organizationIdentifier);
    }

    /**
     * Prove the actor holds one of the posting-period capabilities for the site they work in.
     *
     * @param   ExecutionContext  $context     Actor and site the operation runs as.
     * @param   string            $capability  Capability the operation is about to exercise.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the capability is absent.
     *
     * @since   2.0.0
     */
    private function authorize(ExecutionContext $context, string $capability): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString($capability),
            AuthorizationResource::collection('business_posting_period'),
        );
    }

    /**
     * Record one period state change in the audit trail, inside the caller's transaction.
     *
     * The declared range travels in the metadata because an auditor asking why August refused a
     * posting needs the instants, and a range is operator-declared data rather than a secret.
     *
     * @param   ExecutionContext   $context  Actor and site the change ran as.
     * @param   string             $action   Stable audit action name.
     * @param   PostingPeriod      $period   Declaration as stored by the change.
     * @param   DateTimeImmutable  $at       Instant recorded against the event.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function record(
        ExecutionContext $context,
        string $action,
        PostingPeriod $period,
        DateTimeImmutable $at,
    ): void {
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $at,
            $context->actorId(),
            $action,
            'business_posting_period',
            $period->siteIdentifier . ':' . ($period->organizationIdentifier ?? '') . ':' . $period->key,
            'success',
            [
                'site' => $period->siteIdentifier,
                'organization' => $period->organizationIdentifier,
                'period_key' => $period->key,
                'starts_at' => $period->startsAt->format('Y-m-d\TH:i:s\Z'),
                'ends_at' => $period->endsAt->format('Y-m-d\TH:i:s\Z'),
                'status' => $period->status->value,
            ],
        ));
    }
}
