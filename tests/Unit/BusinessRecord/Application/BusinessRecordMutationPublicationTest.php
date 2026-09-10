<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessRecord\Application;

use DateTimeImmutable;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\ScopeMode;
use Kumwe\App\BusinessIntegration\Application\BusinessRecordMutationEventPublisher;
use Kumwe\App\BusinessIntegration\Application\OutboxStore;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\IntegrationEvent;
use Kumwe\App\BusinessRecord\Application\BusinessRecordMutationPublication;
use Kumwe\App\BusinessRecord\Application\BusinessRecordRevisionRepository;
use Kumwe\App\BusinessRecord\Application\RecordFingerprint;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable;
use Kumwe\App\BusinessRecord\Domain\BusinessRecord;
use Kumwe\App\BusinessRecord\Domain\BusinessRecordRevision;
use Kumwe\App\BusinessRecord\Domain\RecordScope;
use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the independently testable revision, audit and event publication seam.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessRecordMutationPublication::class)]
final class BusinessRecordMutationPublicationTest extends TestCase
{
    /**
     * Revision, audit and event effects receive one canonical, disclosure-safe mutation in order.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPublicationBuildsTheExactTrailInTransactionOrder(): void
    {
        $order = [];
        $revision = null;
        $auditEvent = null;
        $integrationEvent = null;
        $revisions = $this->createMock(BusinessRecordRevisionRepository::class);
        $revisions->expects(self::once())
            ->method('append')
            ->willReturnCallback(static function (BusinessRecordRevision $entry) use (&$order, &$revision): void {
                $order[] = 'revision';
                $revision = $entry;
            });
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::once())
            ->method('record')
            ->willReturnCallback(static function (AuditEvent $entry) use (&$order, &$auditEvent): void {
                $order[] = 'audit';
                $auditEvent = $entry;
            });
        $outbox = $this->createMock(OutboxStore::class);
        $outbox->expects(self::once())
            ->method('append')
            ->willReturnCallback(static function (IntegrationEvent $entry) use (
                &$order,
                &$integrationEvent,
            ): void {
                $order[] = 'event';
                $integrationEvent = $entry;
            });
        $contributions = new ExtensionContributionRegistrySet();
        $execution = $this->createMock(ExtensionExecutionGate::class);
        $execution->expects(self::never())->method('assertCurrent');
        $events = new BusinessRecordMutationEventPublisher(
            $contributions->validateIntegrationContributions(),
            $contributions,
            $outbox,
            $execution,
        );
        $publication = new BusinessRecordMutationPublication(
            $revisions,
            $audit,
            new RecordFingerprint(str_repeat('publication-fingerprint-', 2)),
            $events,
        );
        $definition = self::definition();
        $record = self::record($definition);
        $now = new DateTimeImmutable('2026-08-22T10:15:30.123456Z');

        $publication->publish(
            self::context(),
            $definition,
            $record,
            'update',
            ['name', 'retired_field', 'credential'],
            $now,
            ['relationship' => 'lines', 'position' => 2],
        );

        self::assertSame(['revision', 'audit', 'event'], $order);
        self::assertInstanceOf(BusinessRecordRevision::class, $revision);
        self::assertSame(['credential', 'name', 'retired_field'], $revision->changedFields());
        self::assertSame('Visible name', $revision->snapshot()['name'] ?? null);
        self::assertSame('sealed-value', $revision->snapshot()['credential'] ?? null);
        self::assertArrayNotHasKey('virtual_summary', $revision->snapshot());
        self::assertSame(
            ['position' => 2, 'relationship' => 'lines'],
            $revision->snapshot()['runtime_relation_evidence'] ?? null,
        );

        self::assertInstanceOf(AuditEvent::class, $auditEvent);
        self::assertSame('business.record.update', $auditEvent->action());
        self::assertSame([
            ['field' => 'name', 'redacted' => false],
            ['field' => 'retired_field', 'redacted' => false],
        ], $auditEvent->metadata()['changed_fields']);
        self::assertStringNotContainsString('credential', $auditEvent->metadataAsJson());
        self::assertStringNotContainsString('sealed-value', $auditEvent->metadataAsJson());
        self::assertSame(
            ['position' => 2, 'relationship' => 'lines'],
            $auditEvent->metadata()['mutation_evidence'],
        );

        self::assertInstanceOf(IntegrationEvent::class, $integrationEvent);
        self::assertSame('core.business_record.mutated', $integrationEvent->eventType());
        self::assertSame(['name', 'retired_field'], $integrationEvent->payload()['changed_fields'] ?? null);
        self::assertSame('update', $integrationEvent->payload()['operation'] ?? null);
        $eventPayload = json_encode($integrationEvent->payload(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('credential', $eventPayload);
        self::assertStringNotContainsString('sealed-value', $eventPayload);
        self::assertSame($record->recordKey, $integrationEvent->aggregateId());
        self::assertSame($record->version, $integrationEvent->aggregateVersion());
    }

    /**
     * A definition that disables revision history still emits audit and event effects.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRevisionDisabledDefinitionStillPublishesItsAuditTrail(): void
    {
        $definition = self::definition(false);
        $revisions = $this->createMock(BusinessRecordRevisionRepository::class);
        $revisions->expects(self::never())->method('append');
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::once())->method('record');

        (new BusinessRecordMutationPublication(
            $revisions,
            $audit,
            new RecordFingerprint(str_repeat('publication-fingerprint-', 2)),
        ))->publish(
            self::context(),
            $definition,
            self::record($definition),
            'archive',
            [],
            new DateTimeImmutable('2026-08-22T10:15:30Z'),
        );
    }

    /**
     * Relationship evidence cannot overwrite a real stored field in revision history.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReservedEvidenceCollisionRefusesBeforeAnyEffectIsPublished(): void
    {
        $definition = self::definition(reservedEvidenceField: true);
        $revisions = $this->createMock(BusinessRecordRevisionRepository::class);
        $revisions->expects(self::never())->method('append');
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::never())->method('record');
        $publication = new BusinessRecordMutationPublication(
            $revisions,
            $audit,
            new RecordFingerprint(str_repeat('publication-fingerprint-', 2)),
        );

        $this->expectException(BusinessRecordSchemaUnavailable::class);
        $publication->publish(
            self::context(),
            $definition,
            self::record($definition, reservedEvidenceField: true),
            'relate.lines',
            ['runtime_relation_evidence'],
            new DateTimeImmutable('2026-08-22T10:15:30Z'),
            ['relationship' => 'lines'],
        );
    }

    /**
     * Build the published definition whose trail is exercised.
     *
     * @param   bool  $revisionsEnabled       Whether history should be appended.
     * @param   bool  $reservedEvidenceField  Whether to reproduce the reserved-key collision.
     *
     * @return  EntityTypeDefinition  Exact version-one definition.
     *
     * @since   2.0.0
     */
    private static function definition(
        bool $revisionsEnabled = true,
        bool $reservedEvidenceField = false,
    ): EntityTypeDefinition {
        $fields = [
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
                'handle' => 'name',
                'label' => 'Name',
                'type' => 'core.text',
                'required' => true,
                'nullable' => false,
                'length' => 160,
            ],
            [
                'handle' => 'credential',
                'label' => 'Credential',
                'type' => 'core.secret',
                'required' => true,
                'nullable' => false,
                'sensitivity' => 'secret',
                'exportable' => false,
            ],
            [
                'handle' => 'legacy_note',
                'label' => 'Legacy note',
                'type' => 'core.text',
                'required' => false,
                'nullable' => true,
                'length' => 160,
            ],
            [
                'handle' => 'virtual_summary',
                'label' => 'Virtual summary',
                'type' => 'core.computed',
                'computed' => true,
                'read_only' => true,
                'server_only' => true,
                'formula' => ['op' => 'field', 'type' => 'string', 'field' => 'name'],
                'computation_mode' => 'virtual',
            ],
        ];
        if ($reservedEvidenceField) {
            $fields[] = [
                'handle' => 'runtime_relation_evidence',
                'label' => 'Reserved collision',
                'type' => 'core.text',
                'required' => false,
                'nullable' => true,
                'length' => 160,
            ];
        }

        return EntityTypeDefinition::fromArray([
            'id' => '0191574f-f0b8-7bf3-a9aa-91c6b8244f91',
            'owner' => ['type' => 'site', 'identifier' => 'default'],
            'site' => 'default',
            'handle' => 'site.default.publication_probe',
            'singular_label' => 'Publication probe',
            'plural_label' => 'Publication probes',
            'status' => 'published',
            'definition_version' => 1,
            'storage_mode' => 'relational',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => $revisionsEnabled,
            'fields' => $fields,
            'relationships' => [],
            'views' => [],
            'actions' => [],
            'workflow' => null,
            'compatibility_metadata' => [],
            'administrator_exposure' => true,
            'portal_exposure' => false,
            'public_exposure' => false,
        ]);
    }

    /**
     * Build the applied record version supplied to publication.
     *
     * @param   EntityTypeDefinition  $definition            Definition the record was written against.
     * @param   bool                  $reservedEvidenceField  Whether to carry the colliding stored field.
     *
     * @return  BusinessRecord  Version two stored record.
     *
     * @since   2.0.0
     */
    private static function record(
        EntityTypeDefinition $definition,
        bool $reservedEvidenceField = false,
    ): BusinessRecord {
        $now = new DateTimeImmutable('2026-08-22T10:15:30.123456Z');
        $values = [
            'id' => '0191574f-f0b8-7bf3-a9aa-91c6b8244f92',
            'name' => 'Visible name',
            'credential' => 'sealed-value',
            'virtual_summary' => 'Visible name',
        ];
        if ($reservedEvidenceField) {
            $values['runtime_relation_evidence'] = 'occupied';
        }

        return new BusinessRecord(
            $definition->id,
            $definition->definitionVersion,
            '0191574f-f0b8-7bf3-a9aa-91c6b8244f92',
            '0191574f-f0b8-7bf3-a9aa-91c6b8244f92',
            RecordScope::forDefinition(ScopeMode::Site, SiteContext::default(), null),
            2,
            null,
            $values,
            SystemIdentity::CommandLine->value,
            $now,
            SystemIdentity::CommandLine->value,
            $now,
        );
    }

    /**
     * Build a deterministic system execution context for the publication.
     *
     * @return  ExecutionContext  Background context bound to the default site.
     *
     * @since   2.0.0
     */
    private static function context(): ExecutionContext
    {
        return ExecutionContext::issueSystem(
            new \stdClass(),
            SystemIdentity::CommandLine,
            SiteContext::default(),
            'publication-request',
            'publication-correlation',
        );
    }
}
