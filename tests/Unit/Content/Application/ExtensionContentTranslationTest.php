<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Content\Application;

use InvalidArgumentException;
use Kumwe\App\Content\Domain\TranslationGroup;
use Kumwe\App\Extension\Contribution\TranslationGroupDeclaration;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\Extension\Manifest\ExtensionIdentifier;
use Kumwe\Extension\Manifest\ManifestContributions;
use Kumwe\Localization\Domain\LocaleTag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranslationGroupDeclaration::class)]
#[UsesClass(ExtensionContributionRegistrySet::class)]
/**
 * Pins content translation as an extension contract, not a core-content feature.
 *
 * Decision D12 requires content translation to work for content contributed by extensions, and states
 * that this is the reason none of it can wait for Gate B: a package admitted against a contract with no
 * locale dimension would have to be migrated to gain one. These are the assertions that prove a package
 * reaches the model through the ordinary contribution path, with no core edit.
 *
 * @since  2.0.0
 */
final class ExtensionContentTranslationTest extends TestCase
{
    /**
     * Prove a package declares locale variants through the same registrar it declares everything else with.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnExtensionDeclaresLocaleVariantsThroughItsCanonicalManifest(): void
    {
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $owner = ContributionOwner::extension('acme/blog');
        $declaration = new TranslationGroupDeclaration('acme.blog.articles', ['en-GB', 'af', 'de'], 'en-GB');
        $registrar = $registries->activateManifest(self::manifest($declaration));
        $registrar->complete();

        self::assertSame(
            [['group_id' => 'acme.blog.articles', 'locales' => ['af', 'de', 'en-GB'], 'fallback_locale' => 'en-GB']],
            $registries->inventory($owner)['content']['translation_groups'],
        );
        self::assertTrue($declaration->publishes(LocaleTag::fromString('af')));
        self::assertFalse($declaration->publishes(LocaleTag::fromString('he')));
    }

    /**
     * Prove core ships no contributed content set, so the surface is empty until a package supplies one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCoreContributesNoContentTranslationGroup(): void
    {
        $registries = new ExtensionContributionRegistrySet();

        self::assertSame([], $registries->contentTranslationGroups()->definitions());
    }

    /**
     * Prove withdrawing the package withdraws its content sets in the same sweep as everything else.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRemovingThePackageWithdrawsItsContentTranslationGroups(): void
    {
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $owner = ContributionOwner::extension('acme/blog');
        $declaration = new TranslationGroupDeclaration('acme.blog.articles', ['en-GB', 'de'], 'en-GB');
        $registrar = $registries->activateManifest(self::manifest($declaration));
        $registrar->complete();

        $registries->remove($owner);

        self::assertSame([], $registries->contentTranslationGroups()->definitions());
    }

    /**
     * Prove a declaration that would leave a reader without a page is refused where it is written.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testADeclarationRefusesAnUnreachableFallbackAndAMalformedTag(): void
    {
        try {
            new TranslationGroupDeclaration('acme.blog.articles', ['en-GB', 'de'], 'af');
            self::fail('A fallback naming a language the package never publishes was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('fallback must be one of its declared locales', $exception->getMessage());
        }

        try {
            new TranslationGroupDeclaration('acme.blog.articles', ['not a locale'], 'not a locale');
            self::fail('A malformed language tag was accepted as a declared locale.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('is not a language tag', $exception->getMessage());
        }

        $this->expectExceptionMessage('A content translation group identifier must be namespaced.');
        new TranslationGroupDeclaration('articles', ['en-GB'], 'en-GB');
    }

    /**
     * Prove a package cannot declare a content set in no language, or in more than the model carries.
     *
     * The locale list is a closed claim an operator reads before installing, and the group behind it
     * holds at most `TranslationGroup::MAXIMUM_MEMBERS` locales, so a claim the content model could
     * never honour is refused in the manifest rather than at the first attempt to store it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPackageCannotDeclareNoLanguageOrMoreThanTheModelCarries(): void
    {
        try {
            new TranslationGroupDeclaration('acme.blog.articles', [], 'en-GB');
            self::fail('A content set promising no language at all was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('between one and 64 locales', $exception->getMessage());
        }

        $locales = [];
        for ($index = 0; $index <= TranslationGroup::MAXIMUM_MEMBERS; $index++) {
            $locales[] = chr(97 + intdiv($index, 26)) . chr(97 + $index % 26);
        }

        self::assertCount(TranslationGroup::MAXIMUM_MEMBERS + 1, $locales);
        $this->expectExceptionMessage('A content translation group must declare between one and 64 locales.');

        new TranslationGroupDeclaration('acme.blog.articles', $locales, 'aa');
    }

    /**
     * Prove a declaration read back out of a manifest is refused unless it is exactly what it claims.
     *
     * `fromArray()` is the boundary the signed manifest and the compiled runtime map both come back
     * through, so a member that is missing, extra, or of the wrong type fails here — before the
     * package's own code runs — rather than becoming a declaration nobody can reconcile.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAManifestDeclarationIsRefusedUnlessItsMembersAreExactlyWhatTheyClaim(): void
    {
        $declared = ['group_id' => 'acme.blog.articles', 'locales' => ['de', 'en-GB'], 'fallback_locale' => 'en-GB'];

        self::assertSame($declared, TranslationGroupDeclaration::fromArray($declared)->toArray());

        try {
            TranslationGroupDeclaration::fromArray(['group_id' => 'acme.blog.articles', 'locales' => ['en-GB']]);
            self::fail('A declaration missing its fallback locale was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('must carry exactly its members', $exception->getMessage());
        }

        try {
            TranslationGroupDeclaration::fromArray([...$declared, 'reader_default' => 'en-GB']);
            self::fail('A declaration carrying a member the contract does not define was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('must carry exactly its members', $exception->getMessage());
        }

        try {
            TranslationGroupDeclaration::fromArray([...$declared, 'locales' => ['first' => 'en-GB']]);
            self::fail('A declaration whose locales are not a list was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('member has the wrong type', $exception->getMessage());
        }

        $this->expectExceptionMessage('A content translation group locale must be a string.');
        TranslationGroupDeclaration::fromArray([...$declared, 'locales' => ['en-GB', 42]]);
    }

    /**
     * Parse one translation declaration through the canonical SDK manifest graph.
     *
     * @param   TranslationGroupDeclaration  $declaration  Signed content-language declaration.
     *
     * @return  ManifestContributions  Canonical package-owned graph.
     *
     * @since   2.0.0
     */
    private static function manifest(TranslationGroupDeclaration $declaration): ManifestContributions
    {
        return ManifestContributions::fromManifest(
            ExtensionIdentifier::fromString('acme/blog'),
            [
                'version' => 2,
                'content' => ['translation_groups' => [$declaration->toArray()]],
            ],
            4,
        );
    }
}
