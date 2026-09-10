<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Host;

use InvalidArgumentException;
use Kumwe\App\Studio\Application\Host\StudioResourceHostPort;
use Kumwe\App\Studio\Application\Host\StudioResourceSearchItem;
use Kumwe\App\Studio\Application\Host\StudioResourceSearchPage;
use Kumwe\App\Studio\Application\Host\StudioResourceSearchProvider;
use Kumwe\App\Tests\Support\StudioProducerRequest;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Producer\Error\HostRefusal;
use Kumwe\Producer\Wire\RequestContext;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StudioResourceHostPort::class)]
/**
 * Proves the resource port serves only bounded, authorized, canonically paged slices.
 *
 * @since  2.0.0
 */
final class StudioResourceHostPortTest extends TestCase
{
    /**
     * Prove an unbound port never serves a search, even a well-formed one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnboundPortRefusesDispatch(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires request authority');

        (new StudioResourceHostPort([]))->search(
            (object) ['query' => 'probe'],
            new RequestContext(
                'studio.resource/search',
                '1.0',
                'request-probe',
                'context-probe',
                'generation-1',
            ),
        );
    }

    /**
     * Prove the authorized page is deterministic and its opaque cursor resumes at the exact offset.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSearchServesADeterministicPageWithAnOpaqueResumableCursor(): void
    {
        $provider = self::provider();
        $first = StudioProducerRequest::authorized(
            'studio.operation/resource.search',
            (object) ['query' => (object) ['limit' => 2, 'resourceType' => 'kumwe.app/content', 'search' => 'alp']],
        );
        $port = new StudioResourceHostPort([$provider]);
        $page = $port->forRequest($first->authority)->search($first->arguments(), $first->context());

        self::assertCount(2, $page->value->items);
        self::assertSame('contents/alpha', $page->value->items[0]->id);
        self::assertSame('kumwe.app/content', $page->value->items[0]->resourceType);
        self::assertSame('Alpha', $page->value->items[0]->label->defaultMessage);
        self::assertSame('kumwe.app/resource-label', $page->value->items[0]->label->key);
        self::assertIsString($page->value->nextCursor);

        $second = StudioProducerRequest::authorized(
            'studio.operation/resource.search',
            (object) ['query' => (object) [
                'cursor' => $page->value->nextCursor,
                'limit' => 2,
                'resourceType' => 'kumwe.app/content',
            ]],
        );
        $tail = $port->forRequest($second->authority)->search($second->arguments(), $second->context());

        self::assertSame([['alp', 0, 2], ['', 2, 2]], $provider->calls);
        self::assertSame('contents/gamma', $tail->value->items[0]->id);
        self::assertObjectNotHasProperty('nextCursor', $tail->value);
    }

    /**
     * Prove an unowned resource type yields an empty page instead of disclosing the provider set.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnownedResourceTypeYieldsAnEmptyPage(): void
    {
        $request = StudioProducerRequest::authorized(
            'studio.operation/resource.search',
            (object) ['query' => (object) ['limit' => 5, 'resourceType' => 'kumwe.app/unowned']],
        );
        $page = (new StudioResourceHostPort([self::provider()]))
            ->forRequest($request->authority)
            ->search($request->arguments(), $request->context());

        self::assertSame([], $page->value->items);
        self::assertObjectNotHasProperty('nextCursor', $page->value);
    }

    /**
     * Prove a cursor outside the canonical opaque grammar is refused, not interpreted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testATamperedCursorIsRefused(): void
    {
        $request = StudioProducerRequest::authorized(
            'studio.operation/resource.search',
            (object) ['query' => (object) [
                'cursor' => 'index:2',
                'limit' => 2,
                'resourceType' => 'kumwe.app/content',
            ]],
        );

        $this->expectException(HostRefusal::class);

        (new StudioResourceHostPort([self::provider()]))
            ->forRequest($request->authority)
            ->search($request->arguments(), $request->context());
    }

    /**
     * Prove a provider page that repeats an identifier is refused as an internal contract breach.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAProviderRepeatingAnIdentifierIsRefused(): void
    {
        $provider = new class implements StudioResourceSearchProvider {
            /**
             * Return the exact qualified resource family this test provider owns.
             *
             * @return  string  Stable Studio resource type.
             *
             * @since   2.0.0
             */
            public function resourceType(): string
            {
                return 'kumwe.app/content';
            }

            /**
             * Serve one deterministic authorized slice and record the requested coordinates.
             *
             * @param   ExecutionContext  $context  Trusted App actor and scope.
             * @param   string            $search   Bounded human search text.
             * @param   int               $offset   Zero-based authorized-result offset.
             * @param   int               $limit    Requested item limit.
             *
             * @return  StudioResourceSearchPage  Deterministic provider page.
             *
             * @since   2.0.0
             */
            public function search(
                ExecutionContext $context,
                string $search,
                int $offset,
                int $limit,
            ): StudioResourceSearchPage {
                unset($context, $search, $offset, $limit);

                return new StudioResourceSearchPage([
                    new StudioResourceSearchItem('contents/alpha', 'Alpha'),
                    new StudioResourceSearchItem('contents/alpha', 'Alpha again'),
                ], false);
            }
        };
        $request = StudioProducerRequest::authorized(
            'studio.operation/resource.search',
            (object) ['query' => (object) ['limit' => 2, 'resourceType' => 'kumwe.app/content']],
        );

        $this->expectException(HostRefusal::class);

        (new StudioResourceHostPort([$provider]))
            ->forRequest($request->authority)
            ->search($request->arguments(), $request->context());
    }

    /**
     * Prove a duplicated provider family is rejected when the port is composed, not at dispatch.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testADuplicatedProviderFamilyIsRejectedAtComposition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid or duplicated');

        new StudioResourceHostPort([self::provider(), self::provider()]);
    }

    /**
     * Prove a search carrying a mutation-only concurrency or replay coordinate is refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMutationScopedContextCoordinateIsRefusedForSearch(): void
    {
        $arguments = (object) ['query' => (object) ['limit' => 2, 'resourceType' => 'kumwe.app/content']];
        $candidates = [
            StudioProducerRequest::authorized('studio.operation/resource.search', $arguments, 'revision-1'),
            StudioProducerRequest::authorized(
                'studio.operation/resource.search',
                $arguments,
                idempotencyKey: 'replays/resource-search',
            ),
        ];

        foreach ($candidates as $request) {
            try {
                (new StudioResourceHostPort([self::provider()]))
                    ->forRequest($request->authority)
                    ->search($request->arguments(), $request->context());
                self::fail('A mutation-scoped search context must be refused.');
            } catch (HostRefusal) {
                self::addToAssertionCount(1);
            }
        }
    }

    /**
     * Prove every argument shape outside the closed search query grammar is refused, not repaired.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testArgumentsOutsideTheClosedQueryGrammarAreRefused(): void
    {
        $candidates = [
            'probe',
            (object) ['query' => (object) ['limit' => 2, 'resourceType' => 'kumwe.app/content'], 'extra' => true],
            (object) ['query' => 'probe'],
            (object) ['query' => (object) ['resourceType' => 'kumwe.app/content']],
            (object) ['query' => (object) ['limit' => 0, 'resourceType' => 'kumwe.app/content']],
            (object) ['query' => (object) ['limit' => 101, 'resourceType' => 'kumwe.app/content']],
            (object) ['query' => (object) ['limit' => '2', 'resourceType' => 'kumwe.app/content']],
            (object) ['query' => (object) ['limit' => 2, 'resourceType' => 'unqualified']],
            (object) ['query' => (object) [
                'limit' => 2,
                'resourceType' => 'kumwe.app/content',
                'search' => str_repeat('s', 161),
            ]],
            (object) ['query' => (object) ['cursor' => 7, 'limit' => 2, 'resourceType' => 'kumwe.app/content']],
        ];

        foreach ($candidates as $arguments) {
            $request = StudioProducerRequest::authorized('studio.operation/resource.search', $arguments);
            try {
                (new StudioResourceHostPort([self::provider()]))
                    ->forRequest($request->authority)
                    ->search($request->arguments(), $request->context());
                self::fail('An argument shape outside the closed query grammar must be refused.');
            } catch (HostRefusal) {
                self::addToAssertionCount(1);
            }
        }
    }

    /**
     * Prove a provider page wider than the requested limit is refused as an internal contract breach.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAProviderPageWiderThanTheLimitIsRefused(): void
    {
        $request = StudioProducerRequest::authorized(
            'studio.operation/resource.search',
            (object) ['query' => (object) ['limit' => 1, 'resourceType' => 'kumwe.app/content']],
        );

        $this->expectException(HostRefusal::class);

        (new StudioResourceHostPort([self::provider()]))
            ->forRequest($request->authority)
            ->search($request->arguments(), $request->context());
    }

    /**
     * Prove a continued page whose next offset would cross the cursor ceiling is refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPageCrossingTheCursorCeilingIsRefused(): void
    {
        $request = StudioProducerRequest::authorized(
            'studio.operation/resource.search',
            (object) ['query' => (object) [
                'cursor' => base64_encode('index:4999900'),
                'limit' => 2,
                'resourceType' => 'kumwe.app/content',
            ]],
        );

        $this->expectException(HostRefusal::class);

        (new StudioResourceHostPort([self::endlessProvider()]))
            ->forRequest($request->authority)
            ->search($request->arguments(), $request->context());
    }

    /**
     * Prove a decodable cursor outside the exact offset grammar or ceiling is refused, not clamped.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCursorsOutsideTheOffsetGrammarOrCeilingAreRefused(): void
    {
        $candidates = [
            base64_encode('offset:1'),
            base64_encode('index:01'),
            base64_encode('index:9999999'),
        ];

        foreach ($candidates as $cursor) {
            $request = StudioProducerRequest::authorized(
                'studio.operation/resource.search',
                (object) ['query' => (object) [
                    'cursor' => $cursor,
                    'limit' => 2,
                    'resourceType' => 'kumwe.app/content',
                ]],
            );
            try {
                (new StudioResourceHostPort([self::provider()]))
                    ->forRequest($request->authority)
                    ->search($request->arguments(), $request->context());
                self::fail('A cursor outside the offset grammar or ceiling must be refused.');
            } catch (HostRefusal) {
                self::addToAssertionCount(1);
            }
        }
    }

    /**
     * Build one provider double that always reports another page at any offset.
     *
     * @return  StudioResourceSearchProvider  Endless provider owning the content family.
     *
     * @since   2.0.0
     */
    private static function endlessProvider(): StudioResourceSearchProvider
    {
        return new class implements StudioResourceSearchProvider {
            /**
             * Return the exact qualified resource family this test provider owns.
             *
             * @return  string  Stable Studio resource type.
             *
             * @since   2.0.0
             */
            public function resourceType(): string
            {
                return 'kumwe.app/content';
            }

            /**
             * Serve one full slice that always claims a further page exists.
             *
             * @param   ExecutionContext  $context  Trusted App actor and scope.
             * @param   string            $search   Bounded human search text.
             * @param   int               $offset   Zero-based authorized-result offset.
             * @param   int               $limit    Requested item limit.
             *
             * @return  StudioResourceSearchPage  Deterministic provider page with a next page.
             *
             * @since   2.0.0
             */
            public function search(
                ExecutionContext $context,
                string $search,
                int $offset,
                int $limit,
            ): StudioResourceSearchPage {
                unset($context, $search, $offset, $limit);

                return new StudioResourceSearchPage([
                    new StudioResourceSearchItem('contents/alpha', 'Alpha'),
                    new StudioResourceSearchItem('contents/beta', 'Beta'),
                ], true);
            }
        };
    }

    /**
     * Build one deterministic two-page provider double that records each authorized slice request.
     *
     * @return  StudioResourceSearchProvider  Recording provider owning the content family.
     *
     * @since   2.0.0
     */
    private static function provider(): StudioResourceSearchProvider
    {
        return new class implements StudioResourceSearchProvider {
            /**
             * Recorded authorized slice requests.
             *
             * @var    list<array{0: string, 1: int, 2: int}>
             * @since  2.0.0
             */
            public array $calls = [];

            /**
             * Return the exact qualified resource family this test provider owns.
             *
             * @return  string  Stable Studio resource type.
             *
             * @since   2.0.0
             */
            public function resourceType(): string
            {
                return 'kumwe.app/content';
            }

            /**
             * Serve one deterministic authorized slice and record the requested coordinates.
             *
             * @param   ExecutionContext  $context  Trusted App actor and scope.
             * @param   string            $search   Bounded human search text.
             * @param   int               $offset   Zero-based authorized-result offset.
             * @param   int               $limit    Requested item limit.
             *
             * @return  StudioResourceSearchPage  Deterministic provider page.
             *
             * @since   2.0.0
             */
            public function search(
                ExecutionContext $context,
                string $search,
                int $offset,
                int $limit,
            ): StudioResourceSearchPage {
                unset($context);
                $this->calls[] = [$search, $offset, $limit];
                if ($offset === 0) {
                    return new StudioResourceSearchPage([
                        new StudioResourceSearchItem('contents/alpha', 'Alpha'),
                        new StudioResourceSearchItem('contents/beta', 'Beta'),
                    ], true);
                }

                return new StudioResourceSearchPage([new StudioResourceSearchItem('contents/gamma', 'Gamma')], false);
            }
        };
    }
}
