<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Projection;

use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Domain\ContentTypeDefinition;
use Kumwe\App\Studio\Application\Projection\RecordAuthorizedStudioContentFieldDisclosure;
use Kumwe\Context\Value\ExecutionContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins the explicit adapter for Content's current record-level, rather than field-level, read policy.
 *
 * @since  2.0.0
 */
#[CoversClass(RecordAuthorizedStudioContentFieldDisclosure::class)]
final class RecordAuthorizedStudioContentFieldDisclosureTest extends TestCase
{
    /**
     * Once authoritative Content services returned a record and model, every field is admitted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAuthorizedContentRecordAdmitsBothShapeAndValue(): void
    {
        $context = (new ReflectionClass(ExecutionContext::class))->newInstanceWithoutConstructor();
        $definition = (new ReflectionClass(ContentTypeDefinition::class))->newInstanceWithoutConstructor();
        $record = (new ReflectionClass(ContentRecord::class))->newInstanceWithoutConstructor();
        $disclosure = new RecordAuthorizedStudioContentFieldDisclosure();

        self::assertInstanceOf(ExecutionContext::class, $context);
        self::assertInstanceOf(ContentTypeDefinition::class, $definition);
        self::assertInstanceOf(ContentRecord::class, $record);
        self::assertTrue($disclosure->mayDescribe($context, $definition, 'body'));
        self::assertTrue($disclosure->mayDisclose($context, $record, 'body'));
    }
}
