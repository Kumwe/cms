<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Http\Api\Automation;

use DateTimeImmutable;
use Kumwe\App\Application\Automation\AutomationManagementService;
use Kumwe\App\Application\Automation\Job\ScheduleRepository;
use Kumwe\App\Application\Automation\JobHandlerRegistry;
use Kumwe\App\Application\Automation\JobQueue;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Delivery\Http\Api\Automation\AutomationApiHandler;
use Kumwe\App\Delivery\Http\Api\Concurrency\IfMatch;
use Kumwe\App\Delivery\Http\Api\Concurrency\RequireIfMatchMiddleware;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;

#[CoversClass(AutomationApiHandler::class)]
final class AutomationApiHandlerTest extends TestCase
{
    private const ACTOR = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';
    private const SCHEDULE = '018f22e2-7c8b-7ab0-8f3a-88e8026bb401';

    public function testReadsScheduleWithStrongVersionEtag(): void
    {
        $schedules = $this->createMock(ScheduleRepository::class);
        $schedules->expects(self::once())->method('find')->with(
            self::isInstanceOf(ExecutionContext::class),
            self::SCHEDULE,
        )->willReturn($this->schedule());
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/api/v1/schedules/' . self::SCHEDULE)
            ->withAttribute('id', self::SCHEDULE)
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $this->principal())
            ->withAttribute(ExecutionContextAttribute::NAME, $this->context());

        $response = $this->handler($schedules)->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('"v3"', $response->getHeaderLine('ETag'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testRejectsStaleScheduleMutationWithoutWriting(): void
    {
        $schedules = $this->createMock(ScheduleRepository::class);
        $schedules->expects(self::once())->method('find')->with(
            self::isInstanceOf(ExecutionContext::class),
            self::SCHEDULE,
        )->willReturn($this->schedule());
        $schedules->expects(self::never())->method('setEnabled');
        $request = (new ServerRequestFactory())
            ->createServerRequest('PATCH', 'https://kumwe.test/api/v1/schedules/' . self::SCHEDULE)
            ->withAttribute('id', self::SCHEDULE)
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $this->principal())
            ->withAttribute(ExecutionContextAttribute::NAME, $this->context())
            ->withAttribute(RequireIfMatchMiddleware::ATTRIBUTE, IfMatch::fromHeader('"v2"'))
            ->withBody((new StreamFactory())->createStream('{"enabled":false}'));

        $response = $this->handler($schedules)->handle($request);

        self::assertSame(412, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
    }

    public function testRejectsInvalidScheduleDateAsValidationProblem(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/api/v1/schedules')
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $this->principal())
            ->withAttribute(ExecutionContextAttribute::NAME, $this->context())
            ->withBody((new StreamFactory())->createStream(json_encode([
                'name' => 'Session maintenance',
                'cron_expression' => '0 * * * *',
                'timezone' => 'UTC',
                'job_type' => 'system.sessions.purge',
                'queue' => 'default',
                'first_run' => '2026-02-31T10:00:00Z',
                'payload' => [],
            ], JSON_THROW_ON_ERROR)));

        $response = $this->handler($this->createStub(ScheduleRepository::class))->handle($request);

        self::assertSame(422, $response->getStatusCode());
    }

    /** @return array<string, mixed> */
    private function schedule(): array
    {
        return [
            'id' => self::SCHEDULE,
            'name' => 'Session maintenance',
            'enabled' => true,
            'version' => 3,
        ];
    }

    private function handler(ScheduleRepository $schedules): AutomationApiHandler
    {
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-04T10:00:00+00:00'));
        $automation = new AutomationManagementService(
            $schedules,
            $this->createStub(JobQueue::class),
            new JobHandlerRegistry([]),
            $transactions,
            $this->createStub(AuditRecorder::class),
            $clock,
            AuthorizationContext::gateway(),
            new \Kumwe\App\Application\Automation\JobExecutionScope(),
        );

        return new AutomationApiHandler($automation, new ProblemDetailsResponseFactory());
    }

    private function principal(): AuthenticatedPrincipal
    {
        return AuthorizationContext::principal(['automation.manage'], self::ACTOR);
    }

    private function context(): ExecutionContext
    {
        return $this->principal()->context(
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'test-request-0001',
        );
    }
}
