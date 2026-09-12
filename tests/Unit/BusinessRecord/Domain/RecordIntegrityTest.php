<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessRecord\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessRecord\Application\BusinessRecordRevisionView;
use Kumwe\App\BusinessRecord\Application\RecordFingerprint;
use Kumwe\App\BusinessRecord\Domain\BusinessRecordRevision;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\Secret\Value\EncryptedEnvelope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BusinessRecordRevision::class)]
#[CoversClass(BusinessRecordRevisionView::class)]
#[CoversClass(RecordFingerprint::class)]
final class RecordIntegrityTest extends TestCase
{
    public function testRevisionChecksumCoversMetadataAndDisclosureViewRedactsSecrets(): void
    {
        $secret = new EncryptedEnvelope(
            str_repeat("\x7f", 32),
            str_repeat("\x01", SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES),
            'revision-key-v1',
        );
        $revision = self::revision($secret, 'create');
        $changedOperation = self::revision($secret, 'update');
        $view = BusinessRecordRevisionView::fromRevision(
            $revision,
            EntityTypeDefinition::fromArray(NeutralBusinessFixture::backupDocument()),
        );

        self::assertNotSame($revision->checksum(), $changedOperation->checksum());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $revision->checksum());
        self::assertArrayNotHasKey('credential', $view->snapshot);
        self::assertNotContains('credential', $view->changedFields);
        self::assertSame('Visible', $view->snapshot['name']);
        self::assertSame($revision->checksum(), $view->integrityChecksum);
    }

    public function testFingerprintCanonicalizesMapOrderAndRejectsFloats(): void
    {
        $fingerprints = new RecordFingerprint(str_repeat('f', 32));

        self::assertSame(
            $fingerprints->digest(['name' => 'Alpha', 'amount' => '1.000000']),
            $fingerprints->digest(['amount' => '1.000000', 'name' => 'Alpha']),
        );
        self::assertNotSame(
            $fingerprints->digest(['amount' => '1.000000']),
            $fingerprints->digest(['amount' => '1.000001']),
        );

        $this->expectException(InvalidArgumentException::class);
        $fingerprints->digest(['amount' => 1.0]);
    }

    private static function revision(EncryptedEnvelope $secret, string $operation): BusinessRecordRevision
    {
        return new BusinessRecordRevision(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e20',
            NeutralBusinessFixture::DEFINITION_ID,
            1,
            'default',
            null,
            NeutralBusinessFixture::RECORD_ID,
            str_repeat('a', 64),
            1,
            1,
            $operation,
            ['name' => 'Visible', 'credential' => $secret],
            ['credential', 'name'],
            'unit-actor',
            new DateTimeImmutable('2026-08-08T12:00:00.000000+00:00'),
        );
    }
}
