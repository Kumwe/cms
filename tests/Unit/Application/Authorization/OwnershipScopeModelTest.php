<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Application\Authorization;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Authorization\OwnershipScope;
use Kumwe\App\Application\Authorization\OwnershipScopeLevel;
use Kumwe\App\Application\Authorization\OwnershipScopeNotPermitted;
use Kumwe\App\Application\Authorization\OwnershipScopeNotSiteBound;
use Kumwe\App\Application\Authorization\OwnershipScopeRule;
use Kumwe\App\Application\Authorization\ResourceOwnership;
use Kumwe\App\Application\Authorization\ResourceOwnershipScopePolicy;
use Kumwe\App\Application\Authorization\SiteGroup;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the shapes an owner may take and the categories each shape is admitted for.
 *
 * @since  2.0.0
 */
#[CoversClass(OwnershipScope::class)]
#[CoversClass(OwnershipScopeLevel::class)]
#[CoversClass(OwnershipScopeNotPermitted::class)]
#[CoversClass(OwnershipScopeNotSiteBound::class)]
#[CoversClass(OwnershipScopeRule::class)]
#[CoversClass(ResourceOwnership::class)]
#[CoversClass(ResourceOwnershipScopePolicy::class)]
#[CoversClass(SiteGroup::class)]
final class OwnershipScopeModelTest extends TestCase
{
    /**
     * A site scope contains exactly the site it names, which is the equality it replaces.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSiteScopeContainsOnlyItsOwnSite(): void
    {
        $scope = OwnershipScope::site(SiteContext::fromString('manufacturing'));

        self::assertTrue($scope->contains(SiteContext::fromString('manufacturing')));
        self::assertFalse($scope->contains(SiteContext::fromString('retail')));
        self::assertSame(['manufacturing'], $scope->sites);
        self::assertSame('site:manufacturing', $scope->describe());
    }

    /**
     * A group scope contains its declared members and nobody else, in either direction.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGroupScopeContainsDeclaredMembersAndNoOthers(): void
    {
        $scope = OwnershipScope::group(new SiteGroup('kumwe-group', 'Kumwe group', [
            'manufacturing',
            'retail',
        ]));

        self::assertTrue($scope->contains(SiteContext::fromString('manufacturing')));
        self::assertTrue($scope->contains(SiteContext::fromString('retail')));
        self::assertFalse($scope->contains(SiteContext::fromString('logistics')));
        self::assertFalse($scope->contains(SiteContext::default()));
    }

    /**
     * A group with no declared member is refused, since it would own resources nobody could reach.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAGroupCannotBeDeclaredWithoutMembers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SiteGroup('kumwe-group', 'Kumwe group', []);
    }

    /**
     * Membership is normalised, so two declarations of the same set compare equal.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGroupMembershipIsDeduplicatedAndSorted(): void
    {
        $group = new SiteGroup(' KUMWE-Group ', 'Kumwe group', [
            'retail',
            'Manufacturing',
            'retail',
        ]);

        self::assertSame('kumwe-group', $group->identifier);
        self::assertSame(['manufacturing', 'retail'], $group->members);
    }

    /**
     * Work that must run as one site refuses a scope that names several rather than electing one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testWorkRefusesToRunOnBehalfOfAScopeNamingSeveralSites(): void
    {
        $scope = OwnershipScope::group(new SiteGroup('kumwe-group', 'Kumwe group', ['manufacturing']));

        self::assertNull($scope->siteOrNull());
        $this->expectException(OwnershipScopeNotSiteBound::class);
        $scope->requireSite();
    }

    /**
     * Levels are ordered by reach, which is how a change is classified without a table of cases.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLevelsAreOrderedByReach(): void
    {
        self::assertTrue(OwnershipScopeLevel::Group->widerThan(OwnershipScopeLevel::Site));
        self::assertTrue(OwnershipScopeLevel::Installation->widerThan(OwnershipScopeLevel::Group));
        self::assertFalse(OwnershipScopeLevel::Site->widerThan(OwnershipScopeLevel::Site));
        self::assertFalse(OwnershipScopeLevel::Site->widerThan(OwnershipScopeLevel::Installation));
    }

    /**
     * The books of a legal entity cannot be assembled into a shared owner at all.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAccountingCategoriesCannotBeConstructedAtGroupScope(): void
    {
        $policy = new ResourceOwnershipScopePolicy();
        $group = OwnershipScope::group(new SiteGroup('kumwe-group', 'Kumwe group', [
            'manufacturing',
            'retail',
        ]));

        foreach (['accounting_document', 'ledger', 'pay_run'] as $category) {
            self::assertSame(OwnershipScopeRule::SiteOnly, $policy->rule($category));
            try {
                ResourceOwnership::of(
                    AuthorizationResource::item($category, '018f22e2-7c8b-7ab0-8f3a-88e8026bb601'),
                    $group,
                    $policy,
                );
                self::fail(sprintf('A %s must never be owned by a group.', $category));
            } catch (OwnershipScopeNotPermitted $refused) {
                self::assertStringContainsString($category, $refused->getMessage());
            }
        }
    }

    /**
     * Shared master data is admitted at group scope, which is what makes sharing opt-in rather than absent.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSharedMasterDataCategoriesAreAdmittedAtGroupScope(): void
    {
        $policy = new ResourceOwnershipScopePolicy();
        $group = OwnershipScope::group(new SiteGroup('kumwe-group', 'Kumwe group', ['manufacturing']));

        foreach (['client', 'person', 'price_list', 'product_service'] as $category) {
            self::assertSame(OwnershipScopeRule::SiteOrGroup, $policy->rule($category));
            $owner = ResourceOwnership::of(
                AuthorizationResource::item($category, '018f22e2-7c8b-7ab0-8f3a-88e8026bb602'),
                $group,
                $policy,
            );
            self::assertSame($group, $owner->scope);
        }
    }

    /**
     * Every category admits the site level, so nothing this build carries becomes uncreatable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryDeclaredCategoryStillAdmitsASiteOwner(): void
    {
        $policy = new ResourceOwnershipScopePolicy();

        foreach ($policy->table() as $category => $rule) {
            self::assertTrue(
                $rule->permits(OwnershipScopeLevel::Site),
                sprintf('Category %s must remain ownable by one site.', $category),
            );
        }
    }

    /**
     * A category nobody declared is isolated rather than shareable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUndeclaredCategoryFallsBackToIsolation(): void
    {
        self::assertSame(
            OwnershipScopeRule::SiteOnly,
            (new ResourceOwnershipScopePolicy())->rule('some_extension_category'),
        );
    }

    /**
     * An extension declares its own category once, and may not restate it differently.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAContributedCategoryIsDeclaredOnceAndThenFixed(): void
    {
        $policy = new ResourceOwnershipScopePolicy();
        $policy->register('inspection_asset', OwnershipScopeRule::SiteOrGroup);
        $policy->register('inspection_asset', OwnershipScopeRule::SiteOrGroup);

        self::assertSame(OwnershipScopeRule::SiteOrGroup, $policy->rule('inspection_asset'));

        $this->expectException(InvalidArgumentException::class);
        $policy->register('inspection_asset', OwnershipScopeRule::SiteOnly);
    }

    /**
     * A category this build reserves cannot be reclassified by anything that loads later.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReservedCategoriesCannotBeRedeclaredByAContribution(): void
    {
        $policy = new ResourceOwnershipScopePolicy();

        foreach (['ledger', 'pay_run', 'accounting_document', 'client'] as $category) {
            try {
                $policy->register($category, OwnershipScopeRule::SiteGroupOrInstallation);
                self::fail(sprintf('Category %s must not be redeclarable.', $category));
            } catch (InvalidArgumentException $refused) {
                self::assertStringContainsString($category, $refused->getMessage());
            }
        }
    }

    /**
     * A collection names a family, so it can never be paired with an owner.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACollectionHasNoOwnerToRecord(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ResourceOwnership::of(
            AuthorizationResource::collection('client'),
            OwnershipScope::site(SiteContext::default()),
            new ResourceOwnershipScopePolicy(),
        );
    }

    /**
     * Two readings of one owner are the same owner, which is what a compare-and-set must mean.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testScopeEqualityIgnoresAMembershipChangeUnderTheSameName(): void
    {
        $before = OwnershipScope::group(new SiteGroup('kumwe-group', 'Kumwe group', ['manufacturing']));
        $after = OwnershipScope::group(new SiteGroup('kumwe-group', 'Kumwe group', [
            'manufacturing',
            'retail',
        ]));

        self::assertTrue($before->equals($after));
        self::assertFalse($before->equals(OwnershipScope::site(SiteContext::fromString('manufacturing'))));
    }
}
