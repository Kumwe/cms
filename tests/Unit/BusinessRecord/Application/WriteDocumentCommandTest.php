<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessRecord\Application;

use InvalidArgumentException;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\Extension\Spi\Application\Automation\IdempotencyKey;
use Kumwe\App\BusinessRecord\Application\Command\DocumentLineInput;
use Kumwe\App\BusinessRecord\Application\Command\DocumentWriteIntent;
use Kumwe\App\BusinessRecord\Application\Command\WriteDocumentCommand;
use Kumwe\App\BusinessRecord\Application\RecordRequestGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Pins the shape a document write has to arrive in before it may exist at all.
 *
 * The command is where a delivery adapter's untrusted text stops being untrusted, so the properties that
 * make the model rather than a convention are settled here: a line's slot is its place in the list, an
 * identity is claimed once, an amendment names the aggregate version it read, and a collection is bounded.
 *
 * @since  2.0.0
 */
#[CoversClass(WriteDocumentCommand::class)]
#[CoversClass(DocumentLineInput::class)]
#[CoversClass(DocumentWriteIntent::class)]
#[CoversClass(RecordRequestGuard::class)]
final class WriteDocumentCommandTest extends TestCase
{
    /**
     * Proves a create carries its whole collection in the order the caller listed it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACreateCarriesItsCollectionInTheSubmittedOrder(): void
    {
        $command = new WriteDocumentCommand(
            self::context(),
            'site.default.document',
            'lines',
            ['total' => '3.00'],
            [
                new DocumentLineInput(['amount' => '1.00']),
                new DocumentLineInput(['amount' => '2.00']),
            ],
            IdempotencyKey::fromString('document-create-order'),
        );

        self::assertSame(DocumentWriteIntent::Create, $command->intent);
        self::assertNull($command->expectedVersion);
        self::assertCount(2, $command->lines);
        self::assertSame(['amount' => '1.00'], $command->lines[0]->values);
        self::assertSame(['amount' => '2.00'], $command->lines[1]->values);
    }

    /**
     * Proves an empty collection is a legitimate document rather than a malformed command.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testADocumentWithNoLinesIsAcceptedAsWritten(): void
    {
        $command = new WriteDocumentCommand(
            self::context(),
            'site.default.document',
            'lines',
            ['total' => '0.00'],
            [],
            IdempotencyKey::fromString('document-create-empty'),
        );

        self::assertSame([], $command->lines);
    }

    /**
     * Proves an amendment cannot be submitted without the aggregate version the caller read.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnAmendmentWithoutAnAggregateVersionIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new WriteDocumentCommand(
            self::context(),
            'site.default.document',
            'lines',
            [],
            [],
            IdempotencyKey::fromString('document-amend-unversioned'),
            DocumentWriteIntent::Amend,
            recordId: Uuid::uuid7()->toString(),
        );
    }

    /**
     * Proves an amendment cannot be submitted without naming the document it amends.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnAmendmentWithoutADocumentIdentityIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new WriteDocumentCommand(
            self::context(),
            'site.default.document',
            'lines',
            [],
            [],
            IdempotencyKey::fromString('document-amend-anonymous'),
            DocumentWriteIntent::Amend,
            expectedVersion: 1,
        );
    }

    /**
     * Proves a create cannot pretend to have read an earlier version of a document that does not exist.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACreateCarryingAnExpectedVersionIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new WriteDocumentCommand(
            self::context(),
            'site.default.document',
            'lines',
            [],
            [],
            IdempotencyKey::fromString('document-create-versioned'),
            DocumentWriteIntent::Create,
            expectedVersion: 1,
        );
    }

    /**
     * Proves one line identity cannot be claimed twice inside a single document.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOneLineIdentityCannotBeClaimedTwice(): void
    {
        $identity = Uuid::uuid7()->toString();

        $this->expectException(InvalidArgumentException::class);
        new WriteDocumentCommand(
            self::context(),
            'site.default.document',
            'lines',
            [],
            [
                new DocumentLineInput(['amount' => '1.00'], $identity),
                new DocumentLineInput(['amount' => '2.00'], $identity),
            ],
            IdempotencyKey::fromString('document-duplicate-line'),
        );
    }

    /**
     * Proves a collection larger than one command may write is refused before anything is resolved.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACollectionBeyondTheDocumentCeilingIsRefused(): void
    {
        $lines = [];
        for ($index = 0; $index <= WriteDocumentCommand::MAXIMUM_LINES; ++$index) {
            $lines[] = new DocumentLineInput(['amount' => '1.00']);
        }

        $this->expectException(InvalidArgumentException::class);
        new WriteDocumentCommand(
            self::context(),
            'site.default.document',
            'lines',
            [],
            $lines,
            IdempotencyKey::fromString('document-overflow'),
        );
    }

    /**
     * Proves a malformed relationship handle never reaches a definition lookup.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMalformedCollectionHandleIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new WriteDocumentCommand(
            self::context(),
            'site.default.document',
            'Lines; DROP TABLE',
            [],
            [],
            IdempotencyKey::fromString('document-bad-handle'),
        );
    }

    /**
     * Proves a line value the record layer refuses to store is rejected at the command boundary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testALineCarryingAnUnstorableValueIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DocumentLineInput(['amount' => 1.5]);
    }

    /**
     * Build the execution context these commands are constructed under.
     *
     * @return  ExecutionContext  An unattended context on the default site; nothing here authorizes, so
     *          the context only has to exist for the command to hold one.
     *
     * @since   2.0.0
     */
    private static function context(): ExecutionContext
    {
        return ExecutionContext::issueSystem(
            new \stdClass(),
            SystemIdentity::Worker,
            SiteContext::default(),
            'write-document-command-test',
        );
    }
}
