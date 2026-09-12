<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessDefinition\Domain;

use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\App\BusinessDefinition\Domain\DefinitionStatus;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\App\BusinessDefinition\Domain\IdentityStrategy;
use Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\App\BusinessDefinition\Domain\LocalizedDefinitionText;
use Kumwe\App\BusinessDefinition\Domain\ScopeMode;
use Kumwe\App\BusinessDefinition\Domain\StorageMode;
use Kumwe\Localization\Domain\LocaleTag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocalizedDefinitionText::class)]
#[UsesClass(CanonicalDefinitionJson::class)]
#[UsesClass(DefinitionOwner::class)]
#[UsesClass(EntityTypeDefinition::class)]
#[UsesClass(FieldDefinition::class)]
/**
 * Pins the locale dimension on business-definition wording, and the byte stability it was shaped around.
 *
 * A published definition version is immutable and identified by a SHA-256 over its canonical bytes, so
 * the dimension had to be added in a way that leaves an untranslated definition encoding to exactly the
 * bytes it already encoded to. That is the assertion this class exists for; everything else here proves
 * the dimension actually works once a definition uses it.
 *
 * @since  2.0.0
 */
final class LocalizedDefinitionLabelTest extends TestCase
{
    /**
     * Identifier the definition under test is declared with.
     *
     * @var    string
     * @since  2.0.0
     */
    private const ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bb920';

    /**
     * Prove an unchanged single-locale definition encodes and checksums exactly as it did before.
     *
     * The canonical document is compared key by key against the shape a definition carried before the
     * locale dimension existed, and the checksum against a digest taken over those same bytes. Either
     * assertion failing means a published version somewhere has been invalidated by this change.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUntranslatedDefinitionKeepsTheBytesItsChecksumWasTakenOver(): void
    {
        $definition = $this->definition();
        $document = $definition->toArray();

        self::assertArrayNotHasKey('label_translations', $document);
        self::assertArrayNotHasKey('text_translations', $document['fields'][0]);
        self::assertSame(
            hash('sha256', CanonicalDefinitionJson::encode($this->documentWithoutTheLocaleDimension())),
            $definition->checksum(),
        );
    }

    /**
     * Prove a translated definition writes the dimension, and that it survives a document round trip.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testATranslatedDefinitionCarriesItsWordingThroughTheCanonicalDocument(): void
    {
        $definition = $this->definition(
            ['singular_label' => ['de' => 'Rechnung'], 'plural_label' => ['de' => 'Rechnungen']],
            ['label' => ['de' => 'Kennung'], 'help_text' => ['de' => 'Wird automatisch vergeben.']],
        );

        $document = $definition->toArray();
        self::assertSame(
            ['plural_label' => ['de' => 'Rechnungen'], 'singular_label' => ['de' => 'Rechnung']],
            $document['label_translations'],
        );
        self::assertSame(
            ['help_text' => ['de' => 'Wird automatisch vergeben.'], 'label' => ['de' => 'Kennung']],
            $document['fields'][0]['text_translations'],
        );

        $reloaded = EntityTypeDefinition::fromArray($document);
        self::assertSame($document, $reloaded->toArray());
        self::assertSame($definition->checksum(), $reloaded->checksum());
        self::assertNotSame($this->definition()->checksum(), $definition->checksum());
    }

    /**
     * Prove wording resolves through the requested locale's own fallback chain, then to the declared text.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testWordingResolvesThroughTheLocaleChainAndThenToTheDeclaredText(): void
    {
        $definition = $this->definition(
            ['singular_label' => ['pt' => 'Fatura', 'de' => 'Rechnung'], 'plural_label' => ['de' => 'Rechnungen']],
            ['description' => ['de' => 'Eindeutige Kennung.']],
        );

        self::assertSame('Rechnung', $definition->singularLabelIn('de'));
        self::assertSame('Fatura', $definition->singularLabelIn('pt-BR'));
        self::assertSame('Invoice', $definition->singularLabelIn('af'));
        self::assertSame('Rechnungen', $definition->pluralLabelIn(LocaleTag::fromString('de')));
        self::assertSame('Invoices', $definition->pluralLabelIn('pt'));

        $field = $definition->fields()[0];
        self::assertSame('Eindeutige Kennung.', $field->descriptionIn('de'));
        self::assertSame('Unique identifier.', $field->descriptionIn('en-GB'));
        self::assertSame('Reference', $field->labelIn('de'));
        self::assertSame('', $field->helpTextIn('de'));
    }

    /**
     * Prove two authors declaring the same translations differently produce one document.
     *
     * Locale keys are normalised and both dimensions are sorted, so `pt_br` and `PT-BR` cannot appear as
     * separate translations of one member and declaration order cannot change a published checksum.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDeclarationOrderAndLocaleSpellingDoNotChangeTheChecksum(): void
    {
        $first = $this->definition(
            ['plural_label' => ['de' => 'Rechnungen'], 'singular_label' => ['PT_br' => 'Fatura', 'de' => 'Rechnung']],
        );
        $second = $this->definition(
            ['singular_label' => ['de' => 'Rechnung', 'pt-BR' => 'Fatura'], 'plural_label' => ['de' => 'Rechnungen']],
        );

        self::assertSame($first->toArray(), $second->toArray());
        self::assertSame($first->checksum(), $second->checksum());
    }

    /**
     * Two source keys that normalize to one locale are refused instead of overwriting by order.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDuplicateNormalizedLocaleKeysAreRefused(): void
    {
        $duplicates = ['pt_br' => 'Fatura', 'PT-BR' => 'Factura'];
        foreach ([$duplicates, array_reverse($duplicates, true)] as $translations) {
            try {
                $this->definition(['singular_label' => $translations]);
                self::fail('Normalized duplicate locale keys were accepted.');
            } catch (InvalidBusinessDefinition $exception) {
                self::assertStringContainsString(
                    'declares locale pt-BR more than once after normalization',
                    $exception->getMessage(),
                );
            }
        }
    }

    /**
     * Prove the dimension refuses what the declared text beside it would already refuse.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheDimensionRefusesUntranslatableMembersMalformedLocalesAndOverlongText(): void
    {
        try {
            $this->definition(['handle' => ['de' => 'rechnung']]);
            self::fail('A member that carries no translated text accepted one.');
        } catch (InvalidBusinessDefinition $exception) {
            self::assertStringContainsString('does not carry translated text', $exception->getMessage());
        }

        try {
            $this->definition(['singular_label' => ['not a locale' => 'Rechnung']]);
            self::fail('A malformed language tag was accepted as a translation locale.');
        } catch (InvalidBusinessDefinition $exception) {
            self::assertStringContainsString('is not a language tag', $exception->getMessage());
        }

        try {
            $this->definition(['singular_label' => ['de' => str_repeat('a', 121)]]);
            self::fail('A translation longer than the label it stands beside was accepted.');
        } catch (InvalidBusinessDefinition $exception) {
            self::assertStringContainsString('empty or over its bound', $exception->getMessage());
        }

        $this->expectException(InvalidBusinessDefinition::class);
        $this->definition(['singular_label' => ['de' => '   ']]);
    }

    /**
     * Prove a member declared with no translations at all is dropped rather than written as empty.
     *
     * An authoring tool that always sends the member, empty or not, must not be able to change a
     * published definition's bytes by sending nothing in it — so the empty map has to reduce to the
     * document and the checksum an untranslated definition already had.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMemberDeclaringNoTranslationsIsDroppedInsteadOfWrittenAsAnEmptyObject(): void
    {
        $definition = $this->definition(
            ['singular_label' => [], 'plural_label' => []],
            ['label' => [], 'description' => []],
        );

        $document = $definition->toArray();
        self::assertArrayNotHasKey('label_translations', $document);
        self::assertArrayNotHasKey('text_translations', $document['fields'][0]);
        self::assertSame($this->definition()->toArray(), $document);
        self::assertSame($this->definition()->checksum(), $definition->checksum());
    }

    /**
     * Prove the dimension refuses a translation map that is a list, and one past the locale ceiling.
     *
     * Both refusals protect the same thing: the map travels into a checksummed document and a stored
     * row, so it may neither arrive shaped as something other than a locale-keyed object nor grow
     * without a bound.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheDimensionRefusesAListShapedMapAndAMemberPastTheLocaleCeiling(): void
    {
        try {
            $this->definition(['singular_label' => ['Rechnung']]);
            self::fail('A translation map declared as a list was accepted.');
        } catch (InvalidBusinessDefinition $exception) {
            self::assertStringContainsString('translations must be an object', $exception->getMessage());
        }

        $locales = [];
        for ($region = 1; $region <= LocalizedDefinitionText::MAXIMUM_LOCALES + 1; $region++) {
            $locales[sprintf('de-%03d', $region)] = sprintf('Rechnung %03d', $region);
        }

        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('exceeds 64 translations');
        $this->definition(['singular_label' => $locales]);
    }

    /**
     * Prove a locale key and a translation that are not strings are refused rather than coerced.
     *
     * A decoded JSON object can carry a numeric key, which PHP hands over as an integer, so neither
     * side of the pair can be trusted to be a string just because the document parsed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testANonStringLocaleKeyAndANonStringTranslationAreBothRefused(): void
    {
        try {
            $this->definition(['singular_label' => [7 => 'Rechnung']]);
            self::fail('A numeric locale key was accepted as a language tag.');
        } catch (InvalidBusinessDefinition $exception) {
            self::assertStringContainsString('locale must be a string', $exception->getMessage());
        }

        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('translation must be a string');
        $this->definition(['singular_label' => ['de' => 42]]);
    }

    /**
     * Build the one definition every case here works from, optionally translated.
     *
     * @param   array<string, mixed>  $labelTranslations  Entity label translations to declare.
     * @param   array<string, mixed>  $fieldTranslations  Field wording translations to declare.
     *
     * @return  EntityTypeDefinition  A published, single-field entity owned by core.
     *
     * @since   2.0.0
     */
    private function definition(array $labelTranslations = [], array $fieldTranslations = []): EntityTypeDefinition
    {
        return new EntityTypeDefinition(
            self::ID,
            DefinitionOwner::core(),
            'default',
            'core.invoice',
            'Invoice',
            'Invoices',
            DefinitionStatus::Published,
            1,
            StorageMode::Relational,
            IdentityStrategy::Uuid,
            ScopeMode::Site,
            true,
            true,
            [
                new FieldDefinition(
                    'reference',
                    'Reference',
                    'core.uuid',
                    'Unique identifier.',
                    textTranslations: $fieldTranslations,
                ),
            ],
            labelTranslations: $labelTranslations,
        );
    }

    /**
     * The canonical document a definition of this shape carried before the locale dimension existed.
     *
     * Written out rather than derived, because deriving it from the definition under test would prove
     * nothing: the point is that the bytes match a document assembled without any knowledge of the new
     * members at all.
     *
     * @return  array<string, mixed>  The pre-dimension canonical document.
     *
     * @since   2.0.0
     */
    private function documentWithoutTheLocaleDimension(): array
    {
        return [
            'id' => self::ID,
            'owner' => DefinitionOwner::core()->toArray(),
            'site' => 'default',
            'handle' => 'core.invoice',
            'singular_label' => 'Invoice',
            'plural_label' => 'Invoices',
            'status' => 'published',
            'definition_version' => 1,
            'storage_mode' => StorageMode::Relational->value,
            'identity_strategy' => IdentityStrategy::Uuid->value,
            'scope' => ScopeMode::Site->value,
            'audit_enabled' => true,
            'revisions_enabled' => true,
            'fields' => [[
                'handle' => 'reference',
                'label' => 'Reference',
                'type' => 'core.uuid',
                'description' => 'Unique identifier.',
                'required' => false,
                'nullable' => true,
                'default' => null,
                'length' => null,
                'precision' => null,
                'scale' => null,
                'configuration' => [],
                'normalizers' => [],
                'validators' => [],
                'unique' => false,
                'indexed' => false,
                'immutable_after_create' => false,
                'server_only' => false,
                'computed' => false,
                'read_only' => false,
                'create_visible' => true,
                'update_visible' => true,
                'read_visible' => true,
                'searchable' => false,
                'filterable' => false,
                'sortable' => false,
                'reportable' => false,
                'exportable' => false,
                'sensitivity' => 'internal',
                'localized' => false,
                'help_text' => '',
                'form_group' => 'general',
                'order' => 0,
                'placements' => ['detail', 'form'],
                'visibility_condition' => null,
                'editability_condition' => null,
                'formula' => null,
            ]],
            'relationships' => [],
            'views' => [],
            'actions' => [],
            'workflow' => null,
            'compatibility_metadata' => [],
            'administrator_exposure' => true,
            'portal_exposure' => false,
            'public_exposure' => false,
        ];
    }
}
