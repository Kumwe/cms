<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Localization\Infrastructure;

use Kumwe\Localization\Application\MessageFormattingFailed;
use Kumwe\Localization\Application\MessagePatternValidator;
use Kumwe\Localization\Domain\LocaleTag;
use Kumwe\Localization\Domain\MessageCatalogueLayer;
use Kumwe\App\Localization\Infrastructure\CompiledMessageCatalogueRepository;
use Kumwe\App\Localization\Infrastructure\MessageCatalogueCompiler;
use Kumwe\App\Localization\Infrastructure\XliffCatalogue;
use Kumwe\App\Localization\Infrastructure\XliffCatalogueReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(MessageCatalogueCompiler::class)]
#[CoversClass(XliffCatalogueReader::class)]
#[CoversClass(XliffCatalogue::class)]
#[CoversClass(CompiledMessageCatalogueRepository::class)]
final class MessageCatalogueCompilerTest extends TestCase
{
    public function testItReadsAnXliffTwoDocumentIntoSourceAndTargetText(): void
    {
        $catalogue = (new XliffCatalogueReader())->read($this->document(
            '<unit id="core.site.home.heading"><segment><source>Choose a homepage</source>'
                . '<target>Kies \'n tuisblad</target></segment></unit>'
                . '<unit id="core.site.home.eyebrow"><segment><source>Site setup</source></segment></unit>',
            'af',
        ));

        self::assertSame('en-GB', $catalogue->sourceLanguage);
        self::assertSame('af', $catalogue->targetLanguage);
        self::assertSame(
            ['core.site.home.heading' => 'Kies \'n tuisblad', 'core.site.home.eyebrow' => 'Site setup'],
            $catalogue->patterns(),
        );
    }

    public function testItCompilesToADeterministicFileSortedByIdentifier(): void
    {
        $compiler = new MessageCatalogueCompiler();
        $patterns = [
            'core.z.last.message' => 'Last',
            'core.a.first.message' => 'First',
            'core.m.middle.message' => "It's quoted \\ and escaped",
        ];

        $first = $compiler->compile($patterns, 'en-GB', 'en-GB.xlf');
        $second = $compiler->compile(array_reverse($patterns, true), 'en-GB', 'en-GB.xlf');

        self::assertSame($first, $second, 'Document order must not change the compiled bytes.');
        self::assertStringContainsString("declare(strict_types=1);", $first);
        self::assertLessThan(
            strpos($first, 'core.m.middle.message'),
            (int) strpos($first, 'core.a.first.message'),
        );
        self::assertStringContainsString("'It\\'s quoted \\\\ and escaped'", $first);
    }

    public function testACompiledCatalogueIsValidPhpThatTheRepositoryCanRead(): void
    {
        $directory = $this->temporaryDirectory();
        $compiled = (new MessageCatalogueCompiler())->compile(
            ['core.site.home.heading' => 'Choose a published homepage.'],
            'en-GB',
            'en-GB.xlf',
        );
        file_put_contents($directory . '/en-GB.php', $compiled);

        $catalogue = (new CompiledMessageCatalogueRepository($directory))->catalogue(
            MessageCatalogueLayer::Core,
            LocaleTag::fromString('en-GB'),
        );

        self::assertSame(1, $catalogue->count());
        self::assertSame('Choose a published homepage.', $catalogue->pattern('core.site.home.heading'));
        self::assertSame(['core.site.home.heading'], $catalogue->identifiers());
        self::assertTrue($catalogue->has('core.site.home.heading'));
    }

    public function testAnAbsentCatalogueIsAnEmptyLayerRatherThanAFailure(): void
    {
        $repository = new CompiledMessageCatalogueRepository($this->temporaryDirectory());

        $catalogue = $repository->catalogue(MessageCatalogueLayer::Core, LocaleTag::fromString('zh-Hans'));

        self::assertSame(0, $catalogue->count());
        self::assertNull($catalogue->pattern('core.site.home.heading'));
    }

    public function testTheFirstExtensionDirectoryDeclaredWinsWithinTheExtensionLayer(): void
    {
        $first = $this->temporaryDirectory();
        $second = $this->temporaryDirectory();
        $compiler = new MessageCatalogueCompiler();
        file_put_contents(
            $first . '/en-GB.php',
            $compiler->compile(['acme.tools.client.label' => 'Patient'], 'en-GB', 'first.xlf'),
        );
        file_put_contents(
            $second . '/en-GB.php',
            $compiler->compile(
                ['acme.tools.client.label' => 'Learner', 'acme.tools.client.heading' => 'Learner record'],
                'en-GB',
                'second.xlf',
            ),
        );

        $catalogue = (new CompiledMessageCatalogueRepository($first, [$first, $second]))->catalogue(
            MessageCatalogueLayer::Extension,
            LocaleTag::fromString('en-GB'),
        );

        self::assertSame('Patient', $catalogue->pattern('acme.tools.client.label'));
        self::assertSame('Learner record', $catalogue->pattern('acme.tools.client.heading'));
    }

    public function testItRefusesAnIdentifierTheFrozenGrammarWouldNotAccept(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the grammar refuses');

        (new MessageCatalogueCompiler())->compile(
            ['Save settings and design' => 'Save settings and design'],
            'en-GB',
            'en-GB.xlf',
        );
    }

    /**
     * Malformed ICU is refused before it can become a compiled runtime catalogue.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testItRefusesMalformedIcuBeforeCompiling(): void
    {
        $this->expectException(MessageFormattingFailed::class);

        (new MessageCatalogueCompiler())->compile(
            ['core.site.home.heading' => '{count, plural, one {One} other {Many}'],
            'en-GB',
            'en-GB.xlf',
        );
    }

    /**
     * Studio's exact message corpus keeps its own named-placeholder grammar without widening App ICU.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testOnlyTheExactStudioShellNamespaceBypassesAppIcuValidation(): void
    {
        $validator = $this->createMock(MessagePatternValidator::class);
        $validator->expects(self::once())
            ->method('validate')
            ->with('Ordinary App message', self::isInstanceOf(LocaleTag::class));
        $compiled = (new MessageCatalogueCompiler(patterns: $validator))->compile([
            'core.studio.shell.move-destination-option' => '{value-type} {position}',
            'core.administrator.studio_composition.ready' => 'Ordinary App message',
        ], 'en-GB', 'en-GB.xlf');

        self::assertStringContainsString("'{value-type} {position}'", $compiled);
    }

    public function testItRefusesADocumentTypeDeclarationRatherThanIgnoringIt(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('declares a document type');

        (new XliffCatalogueReader())->read(
            '<?xml version="1.0"?><!DOCTYPE xliff [<!ENTITY x "y">]>'
                . '<xliff xmlns="urn:oasis:names:tc:xliff:document:2.0" version="2.0" srcLang="en-GB">'
                . '<file id="f"><unit id="core.a.b.c"><segment><source>Text</source></segment></unit></file>'
                . '</xliff>',
        );
    }

    public function testItRefusesADuplicateIdentifierAndAUnitWithNoSourceText(): void
    {
        $reader = new XliffCatalogueReader();

        try {
            $reader->read($this->document(
                '<unit id="core.a.b.c"><segment><source>One</source></segment></unit>'
                    . '<unit id="core.a.b.c"><segment><source>Two</source></segment></unit>',
            ));
            self::fail('A repeated identifier must be refused.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('more than once', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no source text');
        $reader->read($this->document('<unit id="core.a.b.c"><segment></segment></unit>'));
    }

    public function testItRefusesADocumentThatIsNotXliffTwo(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not an XLIFF 2.0 document');

        (new XliffCatalogueReader())->read(
            '<?xml version="1.0"?><xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2" '
                . 'srcLang="en-GB"><file id="f"></file></xliff>',
        );
    }

    public function testTheCheckedInSourceCatalogueCompilesToTheCheckedInCompiledCatalogue(): void
    {
        $root = dirname(__DIR__, 4);
        $expected = (new MessageCatalogueCompiler())->compileFile(
            $root . '/resources/localization/messages/en-GB.xlf',
            'en-GB',
        );

        self::assertSame(
            $expected,
            file_get_contents($root . '/resources/localization/compiled/en-GB.php'),
            'The compiled catalogue is stale; run composer translation:compile.',
        );
    }

    private function document(string $units, ?string $targetLanguage = null): string
    {
        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>'
                . '<xliff xmlns="urn:oasis:names:tc:xliff:document:2.0" version="2.0" srcLang="en-GB"%s>'
                . '<file id="core">%s</file></xliff>',
            $targetLanguage === null ? '' : sprintf(' trgLang="%s"', $targetLanguage),
            $units,
        );
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/kumwe-catalogue-' . bin2hex(random_bytes(6));
        mkdir($directory, 0o775, true);

        return $directory;
    }
}
