<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessRecord;

use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\PolicyBusinessRecordReader;
use Kumwe\App\BusinessRecord\Application\RecordBrowseResult;
use Kumwe\App\Extension\Runtime\ExtensionExecutionContext;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordReadRequest;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordQuerySpecification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

#[CoversClass(PolicyBusinessRecordReader::class)]
#[CoversClass(RecordBrowseResult::class)]
/**
 * Proves the served SDK reader port answers through the complete policy-guarded browse path.
 *
 * @since  2.0.0
 */
final class SdkBusinessRecordReaderIntegrationTest extends TestCase
{
    /**
     * Prove a host-issued context reads one policy-admitted page through the container-served port.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAHostIssuedContextReadsOnePolicyAdmittedPage(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $definition = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::document('reader' . $suffix, Uuid::uuid7()->toString()),
        );

        $records = $container->get(BusinessRecordService::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        $reader = new PolicyBusinessRecordReader($records);

        $page = $reader->readPage(new BusinessRecordReadRequest(
            ExtensionExecutionContext::of($context),
            $definition->handle,
            new RecordQuerySpecification(),
        ));

        self::assertInstanceOf(RecordBrowseResult::class, $page);
        self::assertIsList($page->records());
        self::assertNull($page->nextCursor());
        self::assertSame([], $page->aggregates());
    }
}
