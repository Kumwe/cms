<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Workflow\Domain;

use Kumwe\App\Content\Domain\ContentStatus;
use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Workflow\Domain\InvalidWorkflowTransition;
use Kumwe\App\Workflow\Domain\Workflow;
use Kumwe\App\Workflow\Domain\WorkflowDefinition;
use Kumwe\App\Workflow\Domain\WorkflowStateDefinition;
use Kumwe\App\Workflow\Domain\WorkflowTransitionDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Workflow::class)]
#[UsesClass(ContentStatus::class)]
#[UsesClass(InvalidWorkflowTransition::class)]
#[UsesClass(WorkflowDefinition::class)]
#[UsesClass(WorkflowStateDefinition::class)]
#[UsesClass(WorkflowTransitionDefinition::class)]
final class WorkflowTest extends TestCase
{
    public function testExposesClosedAllowedTargets(): void
    {
        $workflow = new Workflow();

        self::assertSame(
            [ContentStatus::Review, ContentStatus::Archived],
            $workflow->allowedTargets(ContentStatus::Draft),
        );
        self::assertSame(
            [ContentStatus::Draft, ContentStatus::Published, ContentStatus::Archived],
            $workflow->allowedTargets(ContentStatus::Review),
        );
    }

    public function testAllowsOnlyDeclaredTransition(): void
    {
        $workflow = new Workflow();

        self::assertTrue($workflow->allows(ContentStatus::Draft, ContentStatus::Review));
        self::assertFalse($workflow->allows(ContentStatus::Draft, ContentStatus::Published));
        self::assertFalse($workflow->allows(ContentStatus::Published, ContentStatus::Published));
    }

    public function testRejectsUndeclaredTransition(): void
    {
        $this->expectException(InvalidWorkflowTransition::class);

        (new Workflow())->assertCanTransition(ContentStatus::Archived, ContentStatus::Published);
    }

    public function testExecutesPersistedCustomWorkflowTransitions(): void
    {
        $now = new DateTimeImmutable('2026-08-05T12:00:00Z');
        $definition = new WorkflowDefinition(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb501',
            SiteContext::default(),
            'legal-review',
            'Legal review',
            [
                new WorkflowStateDefinition('draft', 'Draft', true, false),
                new WorkflowStateDefinition('legal', 'Legal review', false, false),
                new WorkflowStateDefinition('approved', 'Approved', false, true),
            ],
            [
                new WorkflowTransitionDefinition(
                    'draft',
                    'legal',
                    Capability::fromString('content.submit'),
                ),
                new WorkflowTransitionDefinition(
                    'legal',
                    'approved',
                    Capability::fromString('content.publish'),
                ),
            ],
            3,
            $now,
            $now,
        );
        $workflow = new Workflow($definition);

        self::assertTrue($workflow->allows('draft', 'legal'));
        self::assertTrue($workflow->allows('legal', 'approved'));
        self::assertFalse($workflow->allows('draft', 'approved'));
    }

    public function testPersistedWorkflowCannotBypassPublishingAuthority(): void
    {
        $now = new DateTimeImmutable('2026-08-05T12:00:00Z');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires content.publish');

        new WorkflowDefinition(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb502',
            SiteContext::default(),
            'unsafe',
            'Unsafe workflow',
            [
                new WorkflowStateDefinition('draft', 'Draft', true, false),
                new WorkflowStateDefinition('live', 'Live', false, true),
            ],
            [new WorkflowTransitionDefinition(
                'draft',
                'live',
                Capability::fromString('content.update'),
            )],
            1,
            $now,
            $now,
        );
    }
}
