<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use JsonException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Proves the translation gates in both directions: green on this tree, red on a reintroduction.
 *
 * A check that has only ever been observed passing is a check nobody knows works. Each gate here is
 * therefore run twice — once against the committed tree, and once against a copy of the tree with
 * the thing it forbids put back — and the failure is asserted to name the file and say what to do.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class InterfaceTranslationGateTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testTheCompiledCatalogueIsCurrentAgainstItsXliffSource(): void
    {
        [$status, $output] = $this->execute('tools/compile-catalogues.php', ['--check'], $this->root);

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('are current', $output);
    }

    public function testTheHardcodedStringGatePassesOnTheCommittedTree(): void
    {
        [$status, $output] = $this->execute('tools/verify-translated-strings.php', [], $this->root);

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('enforced', $output);
    }

    public function testTheHardcodedStringGateFailsWhenAnEnforcedTemplateGainsInlineText(): void
    {
        $tree = $this->treeCopy();
        file_put_contents(
            $tree . '/templates/site/home.twig',
            "{% extends \"layout.twig\" %}\n{% block content %}<p>Choose a published homepage.</p>{% endblock %}\n",
        );

        [$status, $output] = $this->execute('tools/verify-translated-strings.php', [], $tree);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('templates/site/home.twig', $output);
        self::assertStringContainsString('Choose a published homepage.', $output);
        self::assertStringContainsString('composer translation:compile', $output);
    }

    public function testTheHardcodedStringGateEnforcesATemplateNobodyRegistered(): void
    {
        $tree = $this->treeCopy();
        file_put_contents(
            $tree . '/templates/site/newcomer.twig',
            "<p>A brand new sentence nobody catalogued.</p>\n",
        );

        [$status, $output] = $this->execute('tools/verify-translated-strings.php', [], $tree);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('templates/site/newcomer.twig', $output);
    }

    public function testTheHardcodedStringGateRefusesAnIdentifierTheCatalogueDoesNotCarry(): void
    {
        $tree = $this->treeCopy();
        file_put_contents(
            $tree . '/templates/site/faq.twig',
            "{% extends \"layout.twig\" %}\n{% block content %}{{ t('core.site.faq.invented') }}{% endblock %}\n",
        );

        [$status, $output] = $this->execute('tools/verify-translated-strings.php', [], $tree);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('core.site.faq.invented', $output);
        self::assertStringContainsString('the source catalogue does not carry', $output);
    }

    public function testTheHardcodedStringGateRefusesACatalogueEntryNothingReferences(): void
    {
        $tree = $this->treeCopy();
        $compiled = $tree . '/resources/localization/compiled/en-GB.php';
        $contents = file_get_contents($compiled);
        self::assertIsString($contents);
        file_put_contents(
            $compiled,
            str_replace("return [\n", "return [\n    'core.orphan.example.message' => 'Orphan',\n", $contents),
        );

        [$status, $output] = $this->execute('tools/verify-translated-strings.php', [], $tree);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('core.orphan.example.message', $output);
        self::assertStringContainsString('no template references', $output);
    }

    public function testTheExtractionRegisterCannotOutliveTheWorkItRecords(): void
    {
        $tree = $this->treeCopy();
        $this->registerPendingTemplate($tree, 'templates/administrator/media.twig');
        unlink($tree . '/templates/administrator/media.twig');

        [$status, $output] = $this->execute('tools/verify-translated-strings.php', [], $tree);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('templates/administrator/media.twig', $output);
        self::assertStringContainsString('no longer exists', $output);
    }

    public function testAnExtractedTemplateMustLeaveTheRegisterRatherThanLingerInIt(): void
    {
        $tree = $this->treeCopy();
        $this->registerPendingTemplate($tree, 'templates/administrator/media.twig');

        [$status, $output] = $this->execute('tools/verify-translated-strings.php', [], $tree);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('remove it from the extraction register', $output);
    }

    public function testEveryRegisterEntryStatesWhyItIsExempt(): void
    {
        $encoded = file_get_contents($this->root . '/tools/translation-extraction.json');
        self::assertIsString($encoded);
        /** @var array{allowed_literals: list<array{value: string, reason: string}>,
         *      pending_extraction: list<array{path: string, reason: string}>} $register */
        $register = json_decode($encoded, true, 16, JSON_THROW_ON_ERROR);

        self::assertNotSame([], $register['allowed_literals']);
        foreach ($register['allowed_literals'] as $entry) {
            self::assertGreaterThan(20, strlen($entry['reason']), $entry['value']);
        }
        foreach ($register['pending_extraction'] as $entry) {
            self::assertGreaterThan(20, strlen($entry['reason']), $entry['path']);
            self::assertStringContainsString('V2-LNG-008', $entry['reason'], $entry['path']);
            self::assertFileExists($this->root . '/' . $entry['path']);
        }
    }

    /**
     * Every deliberately untranslatable source names a declared category, and every category a reason.
     *
     * This is the half of the widened gate a reader has to be able to audit: an exemption that does
     * not say which category it belongs to, or a category that does not say why it is not translated,
     * is indistinguishable from an oversight.
     *
     * @return  void
     *
     * @throws  JsonException  When the register is not valid JSON.
     *
     * @since   2.0.0
     */
    public function testEveryUntranslatableSourceNamesADeclaredCategoryAndItsReason(): void
    {
        $encoded = file_get_contents($this->root . '/tools/translation-extraction.json');
        self::assertIsString($encoded);
        /** @var array{untranslatable_categories: list<array{category: string, reason: string}>,
         *      untranslatable_sources: list<array{path: string, category: string, reason: string}>,
         *      user_facing_keys: list<string>} $register */
        $register = json_decode($encoded, true, 16, JSON_THROW_ON_ERROR);

        self::assertNotSame([], $register['user_facing_keys']);
        self::assertNotSame([], $register['untranslatable_categories']);
        $categories = [];
        foreach ($register['untranslatable_categories'] as $entry) {
            self::assertGreaterThan(40, strlen($entry['reason']), $entry['category']);
            $categories[$entry['category']] = true;
        }
        self::assertNotSame([], $register['untranslatable_sources']);
        foreach ($register['untranslatable_sources'] as $entry) {
            self::assertArrayHasKey($entry['category'], $categories, $entry['path']);
            self::assertGreaterThan(20, strlen($entry['reason']), $entry['path']);
            self::assertFileExists($this->root . '/' . $entry['path']);
        }
    }

    /**
     * A console command that writes wording inline instead of looking it up fails the gate.
     *
     * Console output is a translatable surface, so a `line()` carrying a sentence is exactly what the
     * widened scanner exists to catch; the failure has to name the file and the sentence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheGateFailsWhenAConsoleCommandWritesWordingInline(): void
    {
        $tree = $this->treeCopy();
        $command = $tree . '/src/Delivery/Console/Command/HealthCheckCommand.php';
        $contents = file_get_contents($command);
        self::assertIsString($contents);
        file_put_contents($command, str_replace(
            "\$output->message('core.console.app_health.kumwe_is_ready');",
            "\$output->line('Kumwe is ready to serve traffic.');",
            $contents,
        ));

        [$status, $output] = $this->execute('tools/verify-translated-strings.php', [], $tree);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('HealthCheckCommand.php', $output);
        self::assertStringContainsString('Kumwe is ready to serve traffic.', $output);
        self::assertStringContainsString('message()', $output);
    }

    /**
     * A log line keeps its wording: the console rule reads the sink, not the method name.
     *
     * `error()` is both a console failure line and a PSR-3 log level. Refusing the second would make
     * the gate demand that log lines be translated, which the standard forbids, so this pins the
     * distinction the scanner draws.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheGateLeavesALoggerCallAlone(): void
    {
        $tree = $this->treeCopy();
        $middleware = $tree . '/src/Http/Middleware/ProblemDetailsMiddleware.php';
        $contents = file_get_contents($middleware);
        self::assertIsString($contents);
        file_put_contents($middleware, str_replace(
            "\$this->logger->error('Unhandled request exception.'",
            "\$this->logger->error('Unhandled request exception happened.'",
            $contents,
        ));

        [$status, $output] = $this->execute('tools/verify-translated-strings.php', [], $tree);

        self::assertSame(0, $status, $output);
    }

    /**
     * A user-facing error path that hardcodes its sentence fails the gate.
     *
     * The rendered-text half of the widened scanner is what keeps a new refusal from reaching an
     * operator as English regardless of the language the request resolved to.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheGateFailsWhenAnErrorPathHardcodesItsSentence(): void
    {
        $tree = $this->treeCopy();
        $handler = $tree . '/src/Administrator/Http/Handler/AdministratorLoginHandler.php';
        $contents = file_get_contents($handler);
        self::assertIsString($contents);
        file_put_contents($handler, str_replace(
            "'error' => \$this->translator->translate('core.administrator.login.invalid_credentials'),",
            "'error' => 'That email address and password do not match.',",
            $contents,
        ));

        [$status, $output] = $this->execute('tools/verify-translated-strings.php', [], $tree);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('AdministratorLoginHandler.php', $output);
        self::assertStringContainsString('That email address and password do not match.', $output);
    }

    /**
     * An exemption the tree no longer carries fails, exactly as a stale template entry does.
     *
     * An allowlist that outlives the file it excuses is how a gate quietly stops covering something,
     * so the register is held to the same freshness rule on both halves.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheGateRefusesAnExemptionForASourceThatNoLongerExists(): void
    {
        $tree = $this->treeCopy();
        $register = $tree . '/tools/translation-extraction.json';
        $contents = file_get_contents($register);
        self::assertIsString($contents);
        /** @var array{untranslatable_sources: list<array{path: string, category: string, reason: string}>} $decoded */
        $decoded = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        $decoded['untranslatable_sources'][] = [
            'path' => 'src/Delivery/Console/Command/RetiredCommand.php',
            'category' => $decoded['untranslatable_sources'][0]['category'],
            'reason' => 'Recorded by this fixture to prove a stale exemption is refused.',
        ];
        file_put_contents($register, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        [$status, $output] = $this->execute('tools/verify-translated-strings.php', [], $tree);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('RetiredCommand.php', $output);
        self::assertStringContainsString('no longer exists', $output);
    }

    /**
     * An exemption claiming a category the register never declared is refused before scanning.
     *
     * The category is what carries the reason a whole class of text is not translated, so an entry
     * inventing one would be an exemption with no stated justification behind it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheGateRefusesAnExemptionWithAnUndeclaredCategory(): void
    {
        $tree = $this->treeCopy();
        $register = $tree . '/tools/translation-extraction.json';
        $contents = file_get_contents($register);
        self::assertIsString($contents);
        /** @var array{untranslatable_sources: list<array{path: string, category: string, reason: string}>} $decoded */
        $decoded = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        $decoded['untranslatable_sources'][0]['category'] = 'because_i_said_so';
        file_put_contents($register, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        [$status, $output] = $this->execute('tools/verify-translated-strings.php', [], $tree);

        self::assertSame(65, $status, $output);
        self::assertStringContainsString('because_i_said_so', $output);
    }

    /**
     * A console identifier the catalogue does not carry fails, the way a template's does.
     *
     * The console half of the catalogue contract has to be proven in the same direction as the
     * template half, or a command could look up wording that resolves to its own identifier.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheGateRefusesAConsoleIdentifierTheCatalogueDoesNotCarry(): void
    {
        $tree = $this->treeCopy();
        $command = $tree . '/src/Delivery/Console/Command/HealthCheckCommand.php';
        $contents = file_get_contents($command);
        self::assertIsString($contents);
        file_put_contents($command, str_replace(
            "core.console.app_health.kumwe_is_ready",
            "core.console.app_health.invented_message",
            $contents,
        ));

        [$status, $output] = $this->execute('tools/verify-translated-strings.php', [], $tree);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('core.console.app_health.invented_message', $output);
        self::assertStringContainsString('the source catalogue does not carry', $output);
    }

    /**
     * The committed emitted and manifest-less stylesheets are independent of writing direction.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheStylesheetDirectionGatePassesOnTheCommittedTree(): void
    {
        [$status, $output] = $this->execute('tools/verify-stylesheet-direction.php', [], $this->root);

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('direction independent', $output);
    }

    /**
     * Physical declarations in all three directly served runtime fallbacks fail the gate.
     *
     * The site fallback is kept byte-current with its emitted source in this adversarial copy so the
     * physical-declaration failure, rather than the independent stale-artifact guard, is observed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheStylesheetDirectionGateCoversAllRuntimeFallbackStylesheets(): void
    {
        $tree = $this->treeCopy();
        foreach (['administrator', 'portal'] as $surface) {
            file_put_contents(
                $tree . '/public/assets/' . $surface . '.css',
                "\n.reintroduced-$surface { margin-left: 1rem; text-align: left; }\n",
                FILE_APPEND,
            );
        }
        $this->injectSiteCssProof(
            $tree,
            "\n.reintroduced-site { margin-left: 1rem; text-align: left; }\n",
        );

        [$status, $output] = $this->execute('tools/verify-stylesheet-direction.php', [], $tree);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('public/assets/administrator.css', $output);
        self::assertStringContainsString('public/assets/portal.css', $output);
        self::assertStringContainsString('public/assets/site.css', $output);
        self::assertStringContainsString('margin-inline-start', $output);
        self::assertStringContainsString('text-align: start', $output);
    }

    /**
     * CSS's ASCII case-insensitivity cannot hide a physical rule in referenced emitted CSS.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheStylesheetDirectionGateRefusesMixedCasePhysicalDeclarations(): void
    {
        $tree = $this->treeCopy();
        $this->injectSiteCssProof(
            $tree,
            "\n.mixed-case-proof { MARGIN-LEFT: 1rem; TEXT-ALIGN: RIGHT; }\n",
        );

        [$status, $output] = $this->execute('tools/verify-stylesheet-direction.php', [], $tree);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('public/assets/build/css/', $output);
        self::assertStringContainsString('margin-inline-start', $output);
        self::assertStringContainsString('text-align: end', $output);
    }

    /**
     * CSS named through manifest file and assets fields is scanned, not only entry css lists.
     *
     * These emitted files have no corresponding source beneath the historical CSS roots. Their
     * manifest ownership is enough to bring both into the direction contract.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheStylesheetDirectionGateScansEveryManifestCssOutputField(): void
    {
        $tree = $this->treeCopy();
        $outside = $tree . '/frontend-direction-proof';
        mkdir($outside, 0o775, true);
        file_put_contents(
            $outside . '/file-field-proof.css',
            ".file-field-proof { border-left: 1px solid; }\n",
        );
        file_put_contents(
            $tree . '/public/assets/build/css/file-field-proof.css',
            ".file-field-proof { border-left: 1px solid; }\n",
        );
        file_put_contents(
            $tree . '/public/assets/build/css/assets-field-proof.css',
            ".assets-field-proof { margin-right: 1rem; }\n",
        );
        file_put_contents($tree . '/public/assets/build/js/assets-field-proof.js', "export {};\n");
        $manifest = $this->viteManifest($tree);
        $manifest['frontend-direction-proof/file-field-proof.css'] = [
            'file' => 'css/file-field-proof.css',
            'name' => 'file-field-proof',
            'src' => 'frontend-direction-proof/file-field-proof.css',
        ];
        $manifest['_assets-field-proof.js'] = [
            'file' => 'js/assets-field-proof.js',
            'name' => 'assets-field-proof',
            'assets' => ['css/assets-field-proof.css'],
        ];
        $this->writeViteManifest($tree, $manifest);

        [$status, $output] = $this->execute('tools/verify-stylesheet-direction.php', [], $tree);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('file-field-proof.css', $output);
        self::assertStringContainsString('assets-field-proof.css', $output);
        self::assertStringContainsString('border-inline-start', $output);
        self::assertStringContainsString('margin-inline-end', $output);
    }

    /**
     * Malformed JSON cannot silently narrow the authoritative emitted stylesheet set.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheStylesheetDirectionGateRefusesAMalformedManifest(): void
    {
        $tree = $this->treeCopy();
        file_put_contents($tree . '/public/assets/build/.vite/manifest.json', "{\n");

        [$status, $output] = $this->execute('tools/verify-stylesheet-direction.php', [], $tree);

        self::assertSame(66, $status, $output);
        self::assertStringContainsString('invalid JSON', $output);
    }

    /**
     * Missing output files and missing imported chunks both fail the manifest graph closed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheStylesheetDirectionGateRefusesMissingManifestReferences(): void
    {
        foreach (['output', 'chunk', 'prototype constructor', 'prototype __proto__'] as $kind) {
            $tree = $this->treeCopy();
            $manifest = $this->viteManifest($tree);
            if ($kind === 'output') {
                $manifest['assets/administrator/main.ts']['css'][] = 'css/missing-direction-proof.css';
            } elseif ($kind === 'chunk') {
                $manifest['assets/site/main.ts']['imports'][] = '_missing-direction-proof.js';
            } else {
                $manifest['assets/site/main.ts']['dynamicImports'][] = str_ends_with($kind, '__proto__')
                    ? '__proto__'
                    : 'constructor';
            }
            $this->writeViteManifest($tree, $manifest);

            [$generatorStatus, $generatorOutput] = $this->executeNode(
                'tools/refresh-site-fallback.mjs',
                [],
                $tree,
            );
            self::assertSame(1, $generatorStatus, $kind . ': ' . $generatorOutput);
            self::assertStringContainsString('missing', strtolower($generatorOutput), $kind);

            [$status, $output] = $this->execute('tools/verify-stylesheet-direction.php', [], $tree);

            self::assertSame(66, $status, $kind . ': ' . $output);
            self::assertStringContainsString('missing', strtolower($output), $kind);
        }
    }

    /**
     * An unreferenced CSS file fails whether it sits in build/css or elsewhere below the build root.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheStylesheetDirectionGateRefusesOrphanBuildCssAnywhere(): void
    {
        foreach (['css/orphan-direction-proof.css', 'orphan-outside-css.css'] as $relative) {
            $tree = $this->treeCopy();
            file_put_contents(
                $tree . '/public/assets/build/' . $relative,
                ".orphan-proof { margin-inline-start: 1rem; }\n",
            );

            [$status, $output] = $this->execute('tools/verify-stylesheet-direction.php', [], $tree);

            self::assertSame(66, $status, $relative . ': ' . $output);
            self::assertStringContainsString('orphan', strtolower($output), $relative);
        }
    }

    /**
     * Traversal, absolute and platform-dependent manifest output paths are all refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheStylesheetDirectionGateRefusesUnsafeManifestPaths(): void
    {
        foreach (['../escape.css', '/tmp/escape.css', 'css\\escape.css'] as $unsafe) {
            $tree = $this->treeCopy();
            $manifest = $this->viteManifest($tree);
            $manifest[$this->nonEntryManifestKey($manifest)]['assets'] = [$unsafe];
            $this->writeViteManifest($tree, $manifest);

            [$status, $output] = $this->execute('tools/verify-stylesheet-direction.php', [], $tree);

            self::assertSame(66, $status, $unsafe . ': ' . $output);
            self::assertMatchesRegularExpression('/normalized|traversal/i', $output, $unsafe);
        }
    }

    /**
     * A manifest path cannot use a symlink even when its target is an otherwise owned CSS file.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheStylesheetDirectionGateRefusesSymlinkedBuildCss(): void
    {
        $tree = $this->treeCopy();
        $site = $this->siteEntryStylesheet($tree);
        $link = $tree . '/public/assets/build/css/symlink-direction-proof.css';
        self::assertTrue(symlink($site, $link));
        $manifest = $this->viteManifest($tree);
        $manifest[$this->nonEntryManifestKey($manifest)]['assets'] = ['css/symlink-direction-proof.css'];
        $this->writeViteManifest($tree, $manifest);

        [$generatorStatus, $generatorOutput] = $this->executeNode(
            'tools/refresh-site-fallback.mjs',
            [],
            $tree,
        );
        self::assertSame(1, $generatorStatus, $generatorOutput);
        self::assertStringContainsString('symlink', strtolower($generatorOutput));

        [$status, $output] = $this->execute('tools/verify-stylesheet-direction.php', [], $tree);

        self::assertSame(66, $status, $output);
        self::assertStringContainsString('symlink', strtolower($output));
    }

    /**
     * The generator reproduces entry-first, dependency-first static imports and excludes dynamic chunks.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheSiteFallbackUsesTheRecursiveStableManifestClosure(): void
    {
        $tree = $this->treeCopy();
        $build = $tree . '/public/assets/build';
        file_put_contents($build . '/js/closure-a.js', "export {};\n");
        file_put_contents($build . '/js/closure-b.js', "export {};\n");
        file_put_contents($build . '/js/closure-dynamic.js', "export {};\n");
        file_put_contents($build . '/css/closure-shared.css', ".shared { margin-inline-start: 1rem; }\n");
        file_put_contents($build . '/css/closure-first.css', ".first { padding-inline-end: 1rem; }\n");
        file_put_contents($build . '/css/closure-dynamic.css', ".dynamic { margin-inline: 1rem; }\n");
        $manifest = $this->viteManifest($tree);
        $manifest['_closure-b.js'] = [
            'file' => 'js/closure-b.js',
            'name' => 'closure-b',
            'src' => '_synthetic-closure-shared.css',
            'css' => ['css/closure-shared.css'],
        ];
        $manifest['_closure-a.js'] = [
            'file' => 'js/closure-a.js',
            'name' => 'closure-a',
            'imports' => ['_closure-b.js'],
            'css' => ['css/closure-first.css', 'css/closure-shared.css'],
        ];
        $manifest['_closure-dynamic.js'] = [
            'file' => 'js/closure-dynamic.js',
            'name' => 'closure-dynamic',
            'css' => ['css/closure-dynamic.css'],
        ];
        $manifest['assets/site/main.ts']['imports'][] = '_closure-a.js';
        $manifest['assets/site/main.ts']['dynamicImports'] = ['_closure-dynamic.js'];
        $this->writeViteManifest($tree, $manifest);
        self::assertFileDoesNotExist($tree . '/_synthetic-closure-shared.css');
        $originalSite = $this->siteEntryStylesheet($tree);
        $shared = file_get_contents($build . '/css/closure-shared.css');
        $first = file_get_contents($build . '/css/closure-first.css');
        $site = file_get_contents($originalSite);
        self::assertIsString($shared);
        self::assertIsString($first);
        self::assertIsString($site);
        $expected = $site . "\n" . $shared . "\n" . $first;

        [$generatorStatus, $generatorOutput] = $this->executeNode(
            'tools/refresh-site-fallback.mjs',
            [],
            $tree,
        );
        self::assertSame(0, $generatorStatus, $generatorOutput);
        self::assertSame($expected, file_get_contents($tree . '/public/assets/site.css'));
        self::assertStringNotContainsString('.dynamic', $expected);

        [$checkStatus, $checkOutput] = $this->executeNode(
            'tools/refresh-site-fallback.mjs',
            ['--check'],
            $tree,
        );
        self::assertSame(0, $checkStatus, $checkOutput);

        [$status, $output] = $this->execute('tools/verify-stylesheet-direction.php', [], $tree);

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('direction independent', $output);
    }

    /**
     * A cycle in the recursive site import closure fails instead of choosing an arbitrary order.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheStylesheetDirectionGateRefusesASiteManifestCycle(): void
    {
        $tree = $this->treeCopy();
        $manifest = $this->viteManifest($tree);
        $manifest['assets/site/main.ts']['imports'][] = 'assets/site/main.ts';
        $this->writeViteManifest($tree, $manifest);

        [$generatorStatus, $generatorOutput] = $this->executeNode(
            'tools/refresh-site-fallback.mjs',
            [],
            $tree,
        );
        self::assertSame(1, $generatorStatus, $generatorOutput);
        self::assertStringContainsString('cycle', strtolower($generatorOutput));

        [$status, $output] = $this->execute('tools/verify-stylesheet-direction.php', [], $tree);

        self::assertSame(66, $status, $output);
        self::assertStringContainsString('cycle', strtolower($output));
    }

    /**
     * The committed site fallback must reproduce its recursive manifest closure byte for byte.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheStylesheetDirectionGateRefusesAStaleSiteFallback(): void
    {
        $tree = $this->treeCopy();
        file_put_contents(
            $tree . '/public/assets/site.css',
            "\n.stale-proof { margin-inline-start: 1rem; }\n",
            FILE_APPEND,
        );

        [$status, $output] = $this->execute('tools/verify-stylesheet-direction.php', [], $tree);

        self::assertSame(66, $status, $output);
        self::assertStringContainsString('site.css fallback is stale', $output);
    }

    /**
     * Site fallback concatenation refuses relative URLs whose resolution base would change.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheSiteFallbackRefusesRelativeUrls(): void
    {
        $tree = $this->treeCopy();
        $this->injectSiteCssProof($tree, "\n.relative-proof { background: url(../image.png); }\n");

        [$generatorStatus, $generatorOutput] = $this->executeNode(
            'tools/refresh-site-fallback.mjs',
            [],
            $tree,
        );
        self::assertSame(1, $generatorStatus, $generatorOutput);
        self::assertStringContainsString('relative url()', $generatorOutput);

        [$status, $output] = $this->execute('tools/verify-stylesheet-direction.php', [], $tree);

        self::assertSame(66, $status, $output);
        self::assertStringContainsString('relative url()', $output);
    }

    /**
     * Embedded data stylesheets remain outside an inspectable emitted graph and fail closed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheStylesheetDirectionGateRefusesADataStylesheetImport(): void
    {
        $tree = $this->treeCopy();
        file_put_contents(
            $tree . '/public/assets/administrator.css',
            "\n@import \"data:text/css,.proof%7Bmargin-left%3A1rem%7D\";\n",
            FILE_APPEND,
        );

        [$status, $output] = $this->execute('tools/verify-stylesheet-direction.php', [], $tree);

        self::assertSame(66, $status, $output);
        self::assertStringContainsString('data: CSS @import', $output);
    }

    /**
     * The runtime register may neither omit an owned fallback nor broaden itself with another file.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheStylesheetDirectionGateRequiresExactlyThreeRuntimeFallbacks(): void
    {
        foreach (['missing', 'extra'] as $mutation) {
            $tree = $this->treeCopy();
            $path = $tree . '/tools/stylesheet-direction.json';
            $encoded = file_get_contents($path);
            self::assertIsString($encoded);
            /** @var array{runtime_fallback_stylesheets: list<string>, allowed_declarations: list<mixed>} $register */
            $register = json_decode($encoded, true, 16, JSON_THROW_ON_ERROR);
            if ($mutation === 'missing') {
                array_pop($register['runtime_fallback_stylesheets']);
            } else {
                $register['runtime_fallback_stylesheets'][] = 'public/assets/extra.css';
            }
            file_put_contents($path, json_encode($register, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            [$status, $output] = $this->execute('tools/verify-stylesheet-direction.php', [], $tree);

            self::assertSame(65, $status, $mutation . ': ' . $output);
            self::assertStringContainsString('exactly the administrator, portal and site', $output);
        }
    }

    /**
     * A registered runtime fallback remains tied to the URL its live renderer actually serves.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheStylesheetDirectionGateTiesFallbacksToRendererUrls(): void
    {
        foreach ([false, true] as $deadMatchingCall) {
            $tree = $this->treeCopy();
            $renderer = $tree . '/src/Presentation/SiteRenderer.php';
            $contents = file_get_contents($renderer);
            self::assertIsString($contents);
            $contents = str_replace('/assets/site.css', '/assets/unowned.css', $contents);
            if ($deadMatchingCall) {
                $contents = str_replace(
                    "        \$canonicalUrl = \$data['canonical_url'] ?? null;",
                    "        if (false) {\n"
                        . "            (\$this->assets ?? new ViteAssetManifest(''))->entry(\n"
                        . "                'assets/site/main.ts',\n"
                        . "                '/assets/site.css',\n"
                        . "            );\n"
                        . "        }\n"
                        . "        \$canonicalUrl = \$data['canonical_url'] ?? null;",
                    $contents,
                );
            }
            file_put_contents($renderer, $contents);

            [$status, $output] = $this->execute('tools/verify-stylesheet-direction.php', [], $tree);

            self::assertSame(66, $status, $output);
            self::assertStringContainsString('exactly one asset entry call', $output);
        }
    }

    /**
     * CSS-as-string and CSS-as-URL source modes fail because they emit no stylesheet to inspect.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheStylesheetDirectionGateRefusesOpaqueCssSourceModes(): void
    {
        $fixtures = [
            "import rawCss from './styles.css?raw'; void rawCss;\n" => '?inline/?raw/?url',
            "import inlineCss from './styles.css?inline'; void inlineCss;\n" => '?inline/?raw/?url',
            "import urlCss from './styles.css?url&no-inline'; void urlCss;\n" => '?inline/?raw/?url',
            "const queried = import(`./styles.css?url`); void queried;\n" => '?inline/?raw/?url',
            "const cssUrl = new URL('./styles.css', import.meta.url); void cssUrl;\n" => 'new URL',
            "const cssUrl = new URL(`./styles.css`, import.meta.url); void cssUrl;\n" => 'new URL',
            "const cssUrl = new URL(`./\${theme}.css`, import.meta.url); void cssUrl;\n" => 'new URL',
            "const cssUrl = new /* split */ URL (`./styles.css`, import /* split */ . meta . url);\n" => 'new URL',
            "const sheet = new CSSStyleSheet(); void sheet;\n" => 'runtime CSS',
            "const sheet = new /* split */ CSSStyleSheet (); void sheet;\n" => 'runtime CSS',
            "const Sheet = CSSStyleSheet; const sheet = new Sheet(); void sheet;\n" => 'runtime CSS',
            "document.adoptedStyleSheets = [];\n" => 'runtime CSS',
        ];
        foreach ($fixtures as $fixture => $diagnostic) {
            $tree = $this->treeCopy();
            file_put_contents($tree . '/assets/site/main.ts', "\n" . $fixture, FILE_APPEND);

            [$status, $output] = $this->execute('tools/verify-stylesheet-direction.php', [], $tree);

            self::assertSame(66, $status, $fixture . $output);
            self::assertStringContainsString($diagnostic, $output, $fixture);
        }
    }

    /**
     * Static Lit CSS is scanned through import aliases while opaque composition fails closed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheStylesheetDirectionGateAccountsForEveryLitCssCompositionMode(): void
    {
        $fixtures = [
            "import { css as litCss } from 'lit';\n"
                . "class Proof { static styles = litCss`"
                . ".proof { margin-left: 1rem; }`; }\n" => [1, 'margin-inline-start'],
            "import { css } from 'lit';\n"
                . "class Proof { static readonly styles: CSSResultGroup = ["
                . "css`.proof { margin-left: 1rem; }`]; }\n" => [
                    66,
                    'exactly one direct css tagged template',
                ],
            "import { css } from 'lit';\n"
                . "class Proof { static styles = css`.proof { margin-inline: 1rem; }`"
                . " || importedOpaqueStyles; }\n" => [
                    66,
                    'exactly one direct css tagged template',
                ],
            "import { css } from 'lit';\n"
                . "class Proof { static styles = css`"
                . ".proof { margin: \${spacing}; }`; }\n" => [66, 'interpolates a Lit css template'],
            "import { css, unsafeCSS } from 'lit';\n"
                . "class Proof { static styles = css`"
                . ".proof { margin: \${unsafeCSS('1rem')}; }`; }\n" => [66, 'unsafeCSS'],
        ];
        foreach ($fixtures as $fixture => [$expectedStatus, $diagnostic]) {
            $tree = $this->treeCopy();
            file_put_contents($tree . '/assets/site/lit-direction-proof.ts', $fixture);

            [$status, $output] = $this->execute('tools/verify-stylesheet-direction.php', [], $tree);

            self::assertSame($expectedStatus, $status, $fixture . $output);
            self::assertStringContainsString($diagnostic, $output, $fixture);
        }
    }

    /**
     * Escapes, comment-split identifiers and asymmetric shorthands cannot evade CSS inspection.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheStylesheetDirectionGateRefusesCssGrammarEvasions(): void
    {
        $fixtures = [
            ".proof { marg\\69n-left: 1rem; }\n" => [66, 'CSS escape'],
            ".proof { mar/**/gin-left: 1rem; }\n" => [66, 'splits a CSS identifier'],
            ".proof { mar/*\n*/gin-left: 1rem; }\n" => [66, 'splits a CSS identifier'],
            ".proof { margin-left\n: 1rem; }\n" => [1, 'margin-inline-start'],
            ".proof { margin: 0 1rem 0 2rem }\n" => [1, 'margin-inline-start and margin-inline-end'],
            ".proof { inset: 0 auto 0 1rem; }\n" => [1, 'inset-inline-start and inset-inline-end'],
            ".proof { border-width: 0 0 0 1px; }\n" => [1, 'border-inline-start-width'],
            ".proof { border-radius: 1px 2px 3px 4px; }\n" => [1, 'logical border-start/end'],
            ".proof { border-radius: 1px / 2px / 3px; }\n" => [1, 'logical border-start/end'],
            "@\\69mport '/absolute.css';\n" => [66, 'CSS escape'],
            "@im/**/port '/absolute.css';\n" => [66, 'splits a CSS identifier'],
            ".proof { background: u\\72l(../image.png); }\n" => [66, 'CSS escape'],
            ".proof { background: u/**/rl(../image.png); }\n" => [66, 'splits a CSS identifier'],
        ];
        foreach ($fixtures as $fixture => [$expectedStatus, $diagnostic]) {
            $tree = $this->treeCopy();
            $this->injectSiteCssProof($tree, "\n" . $fixture);

            if ($expectedStatus === 66) {
                [$generatorStatus, $generatorOutput] = $this->executeNode(
                    'tools/refresh-site-fallback.mjs',
                    [],
                    $tree,
                );
                self::assertSame(1, $generatorStatus, $fixture . $generatorOutput);
                self::assertStringContainsString($diagnostic, $generatorOutput, $fixture);
            }

            [$status, $output] = $this->execute('tools/verify-stylesheet-direction.php', [], $tree);

            self::assertSame($expectedStatus, $status, $fixture . $output);
            self::assertStringContainsString($diagnostic, $output, $fixture);
        }
    }

    /**
     * Build freshness and refresh publication preserve a path-scoped, split-privilege trust boundary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheBrowserBuildFreshnessContractIncludesUntrackedOutputsAndSiteFallback(): void
    {
        $ci = file_get_contents($this->root . '/.github/workflows/ci.yml');
        self::assertIsString($ci);
        self::assertStringContainsString(
            'git status --porcelain=v1 --untracked-files=all -- public/assets/build public/assets/site.css',
            $ci,
        );
        $nightly = file_get_contents($this->root . '/.github/workflows/nightly.yml');
        self::assertIsString($nightly);
        foreach ([$ci, $nightly] as $workflow) {
            self::assertStringContainsString('set -euo pipefail', $workflow);
            self::assertSame(1, substr_count($workflow, 'npm run check'));
            self::assertSame(1, substr_count($workflow, 'npm run build'));
            $frontendCheck = strpos($workflow, 'npm run check');
            $frontendBuild = strpos($workflow, 'npm run build');
            $frontendDrift = strpos($workflow, 'generated_status="$(git status --porcelain=v1');
            self::assertIsInt($frontendCheck);
            self::assertIsInt($frontendBuild);
            self::assertIsInt($frontendDrift);
            self::assertLessThan($frontendBuild, $frontendCheck);
            self::assertLessThan($frontendDrift, $frontendBuild);
            self::assertStringContainsString(
                'generated_status="$(git status --porcelain=v1 --untracked-files=all -- '
                    . 'public/assets/build public/assets/site.css)"',
                $workflow,
            );
            self::assertStringContainsString('if [ -n "$generated_status" ]; then', $workflow);
        }
        self::assertSame(2, substr_count(
            $ci . $nightly,
            'git status --porcelain=v1 --untracked-files=all -- public/assets/build public/assets/site.css',
        ));
        $refresh = file_get_contents($this->root . '/.github/workflows/refresh-browser-baselines.yml');
        self::assertIsString($refresh);
        self::assertStringContainsString(
            "permissions:\n  contents: read",
            $refresh,
        );
        self::assertStringContainsString('group: refresh-browser-baselines-${{ github.ref }}', $refresh);
        self::assertStringContainsString('cancel-in-progress: true', $refresh);
        self::assertSame(2, substr_count(
            $refresh,
            "if: github.ref_type == 'branch' && github.ref_name != github.event.repository.default_branch",
        ));
        self::assertSame(2, substr_count($refresh, 'ref: ${{ github.sha }}'));
        self::assertSame(2, substr_count($refresh, 'persist-credentials: false'));
        self::assertStringContainsString(
            'git add -- public/assets/build public/assets/site.css tests/Browser/screenshots',
            $refresh,
        );
        self::assertStringContainsString(
            "git diff --cached --binary --full-index --no-renames -- \\\n"
                . '            public/assets/build public/assets/site.css tests/Browser/screenshots',
            $refresh,
        );
        $build = strpos($refresh, 'npm run build');
        $check = strpos($refresh, 'npm run check');
        self::assertIsInt($build);
        self::assertIsInt($check);
        self::assertLessThan($check, $build, 'The refresh must build the repairable artifacts before checking them.');

        self::assertStringContainsString(
            'actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a',
            $refresh,
        );
        self::assertStringContainsString("permissions:\n      actions: read\n      contents: write", $refresh);
        self::assertStringContainsString(
            'actions/download-artifact@3e5f45b2cfb9172054b4087a40e8e0b5a5461e7c # v8.0.1',
            $refresh,
        );
        self::assertStringContainsString('artifact-ids: ${{ needs.render.outputs.artifact-id }}', $refresh);
        self::assertStringContainsString('git apply --index --binary "$patch_path"', $refresh);
        self::assertStringContainsString(
            'if [ -n "$(git status --porcelain=v1 --untracked-files=all)" ]; then',
            $refresh,
        );
        self::assertStringContainsString(
            'git diff --cached --raw --no-abbrev --no-renames -z',
            $refresh,
        );
        self::assertStringContainsString("while IFS= read -r -d '' metadata && IFS= read -r -d '' path; do", $refresh);
        self::assertStringContainsString(
            'public/assets/build/*|public/assets/site.css|tests/Browser/screenshots/*)',
            $refresh,
        );
        self::assertStringContainsString('000000|100644', $refresh);
        self::assertStringContainsString("echo 'changed=false' >> \"\$GITHUB_OUTPUT\"", $refresh);
        self::assertStringContainsString(
            'for required in public/assets/build/.vite/manifest.json public/assets/site.css; do',
            $refresh,
        );
        self::assertStringContainsString('if [ ! -f "$required" ] || [ -L "$required" ]; then', $refresh);
        self::assertStringContainsString(
            '--force-with-lease="refs/heads/${GITHUB_REF_NAME}:${GITHUB_SHA}"',
            $refresh,
        );
        self::assertStringContainsString('if [ "$(git rev-parse HEAD^)" != "$GITHUB_SHA" ]; then', $refresh);
        self::assertStringContainsString('if [ "$remote_sha" != "$GITHUB_SHA" ]; then', $refresh);

        $commitJob = strpos($refresh, "\n  commit:\n");
        self::assertIsInt($commitJob);
        $privileged = substr($refresh, $commitJob);
        self::assertSame(1, substr_count($refresh, 'contents: write'));
        self::assertStringContainsString('contents: write', $privileged);
        self::assertSame(1, substr_count($refresh, 'GH_TOKEN: ${{ github.token }}'));
        self::assertStringContainsString('GH_TOKEN: ${{ github.token }}', $privileged);
        $compareAndSwapStep = strpos($privileged, '- name: Compare-and-swap push to the source branch');
        $tokenBinding = strpos($privileged, 'GH_TOKEN: ${{ github.token }}');
        self::assertIsInt($compareAndSwapStep);
        self::assertIsInt($tokenBinding);
        self::assertGreaterThan($compareAndSwapStep, $tokenBinding);
        self::assertStringNotContainsString('npm ', $privileged);
        self::assertStringNotContainsString('npx ', $privileged);
        self::assertStringNotContainsString('composer ', $privileged);
        self::assertStringNotContainsString('php ', $privileged);
        self::assertStringNotContainsString('uses: ./', $privileged);
        self::assertStringNotContainsString('run: ./', $privileged);
        foreach (
            [
                'tools/',
                'bin/',
                'vendor/',
                'node_modules/',
                '.github/actions/',
                'python ',
                'ruby ',
                'perl ',
            ] as $execution
        ) {
            self::assertStringNotContainsString($execution, $privileged, $execution);
        }
        $privilegedUses = preg_match_all(
            '/^[ \t]*(?:-[ \t]*)?uses:[ \t]+(\S+)/m',
            $privileged,
            $privilegedReferences,
        );
        self::assertSame(2, $privilegedUses);
        self::assertSame([
            'actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1',
            'actions/download-artifact@3e5f45b2cfb9172054b4087a40e8e0b5a5461e7c',
        ], $privilegedReferences[1]);
        $privilegedRunCount = preg_match_all(
            '/^        run: \|\R(?<script>.*?)(?=^      - name:|\z)/ms',
            $privileged,
            $privilegedScripts,
        );
        self::assertSame(3, $privilegedRunCount);
        self::assertSame(
            [
                '977c9b972cfb160867963100bbcea4cadf866ce21fdc400288f6ef9822f001f9',
                'f807f5e5ef33291cbdcf9c7fb61e88af48411409cf6895f7d74503c6703b79f5',
                'c2f01cdb7ba8b1771f7807dbd3cc512bb83c24ec280966e57e23ed9cac199291',
            ],
            array_map(
                static fn (string $script): string => hash('sha256', rtrim($script)),
                $privilegedScripts['script'],
            ),
            'Privileged shell is an exact reviewed allowlist; repository code may not be added here.',
        );

        $uses = preg_match_all(
            '/^[ \t]*(?:-[ \t]*)?uses:[ \t]+(\S+)/m',
            $refresh,
            $actionReferences,
        );
        self::assertNotFalse($uses);
        self::assertGreaterThan(0, $uses);
        foreach ($actionReferences[1] as $reference) {
            if ($reference === './.github/actions/native-runtime') {
                // This action comes from the checked-out SHA; the privileged job forbids local actions above.
                self::assertFileExists($this->root . '/.github/actions/native-runtime/action.yml');
                continue;
            }
            self::assertMatchesRegularExpression(
                '/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+@[0-9a-f]{40}$/D',
                $reference,
                $reference,
            );
        }
        $package = file_get_contents($this->root . '/package.json');
        self::assertIsString($package);
        self::assertStringContainsString('vite build && node tools/refresh-site-fallback.mjs', $package);
        self::assertStringContainsString(
            '"check:site-fallback": "node tools/refresh-site-fallback.mjs --check"',
            $package,
        );
    }

    public function testTheThreeLayoutsEmitLanguageAndDirectionFromTheResolvedLocale(): void
    {
        foreach (['site', 'administrator', 'portal'] as $surface) {
            $layout = file_get_contents($this->root . '/templates/' . $surface . '/layout.twig');
            self::assertIsString($layout);
            self::assertStringContainsString('lang="{{ locale_tag() }}"', $layout, $surface);
            self::assertStringContainsString('dir="{{ text_direction() }}"', $layout, $surface);
            self::assertStringNotContainsString('<html lang="en">', $layout, $surface);
        }
    }

    /**
     * Record one template in a tree copy's extraction register, as the pending era did.
     *
     * The committed register holds no pending templates any more, so the register semantics —
     * a stale entry fails, a lingering entry fails — are proven against an entry this writes
     * into the copy rather than against an entry the tree no longer carries.
     *
     * @param  string  $tree      Root of the copied tree whose register is edited.
     * @param  string  $template  Repository-relative template path to record as pending.
     *
     * @return void
     *
     * @since  2.0.0
     */
    private function registerPendingTemplate(string $tree, string $template): void
    {
        $path = $tree . '/tools/translation-extraction.json';
        $encoded = file_get_contents($path);
        self::assertIsString($encoded);
        /** @var array{pending_extraction: list<array{path: string, reason: string}>} $register */
        $register = json_decode($encoded, true, 16, JSON_THROW_ON_ERROR);
        $register['pending_extraction'][] = [
            'path' => $template,
            'reason' => 'Awaiting extraction, reintroduced by this test fixture. V2-LNG-008.',
        ];
        file_put_contents($path, json_encode($register, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Read a copied tree's Vite manifest as its keyed chunk records.
     *
     * @param  string  $tree  Root of the copied tree.
     *
     * @return array<string, array<string, mixed>>  Manifest records keyed by Vite source or chunk.
     *
     * @throws JsonException  When the copied manifest is not valid JSON.
     *
     * @since  2.0.0
     */
    private function viteManifest(string $tree): array
    {
        $encoded = file_get_contents($tree . '/public/assets/build/.vite/manifest.json');
        self::assertIsString($encoded);
        /** @var array<string, array<string, mixed>> $manifest */
        $manifest = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);

        return $manifest;
    }

    /**
     * Write manifest records deterministically into a copied tree.
     *
     * @param  string                                $tree      Root of the copied tree.
     * @param  array<string, array<string, mixed>>  $manifest  Manifest records to write.
     *
     * @return void
     *
     * @throws JsonException  When a fixture value cannot be encoded.
     *
     * @since  2.0.0
     */
    private function writeViteManifest(string $tree, array $manifest): void
    {
        file_put_contents(
            $tree . '/public/assets/build/.vite/manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
    }

    /**
     * Select one generated non-entry chunk without coupling tests to its content hash.
     *
     * @param   array<string, array<string, mixed>>  $manifest  Keyed Vite records.
     *
     * @return  string  First deterministic non-entry key.
     *
     * @since   2.0.0
     */
    private function nonEntryManifestKey(array $manifest): string
    {
        foreach ($manifest as $key => $record) {
            if (($record['isEntry'] ?? false) !== true) {
                return $key;
            }
        }

        self::fail('The Vite fixture must carry a non-entry chunk.');
    }

    /**
     * Resolve the copied site entry's first emitted stylesheet.
     *
     * The committed fixture intentionally has one site CSS output. Tests that change the recursive
     * closure construct their expected concatenation explicitly instead of using this convenience.
     *
     * @param  string  $tree  Root of the copied tree.
     *
     * @return string  Absolute emitted stylesheet path.
     *
     * @throws JsonException  When the copied manifest is not valid JSON.
     *
     * @since  2.0.0
     */
    private function siteEntryStylesheet(string $tree): string
    {
        $manifest = $this->viteManifest($tree);
        $stylesheets = $manifest['assets/site/main.ts']['css'] ?? null;
        self::assertIsArray($stylesheets);
        self::assertCount(1, $stylesheets);
        self::assertIsString($stylesheets[0]);

        return $tree . '/public/assets/build/' . $stylesheets[0];
    }

    /**
     * Inject adversarial CSS while retaining the generator's complete site-closure byte order.
     *
     * A safe unique marker is first regenerated through the production Node tool, then replaced in
     * both its emitted chunk and generated fallback. Invalid CSS can therefore reach the PHP refusal
     * path without hand-assembling a fallback that would become stale when the static closure grows.
     *
     * @param   string  $tree     Root of the copied repository.
     * @param   string  $fixture  Invalid or physical CSS bytes to inject.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function injectSiteCssProof(string $tree, string $fixture): void
    {
        $marker = "\n.direction-contract-injection { margin-inline: 0; }\n";
        $site = $this->siteEntryStylesheet($tree);
        file_put_contents($site, $marker, FILE_APPEND);
        [$status, $output] = $this->executeNode('tools/refresh-site-fallback.mjs', [], $tree);
        self::assertSame(0, $status, $output);

        foreach ([$site, $tree . '/public/assets/site.css'] as $path) {
            $contents = file_get_contents($path);
            self::assertIsString($contents);
            self::assertSame(1, substr_count($contents, $marker), $path);
            file_put_contents($path, str_replace($marker, $fixture, $contents));
        }
    }

    /**
     * Run one gate against a tree and capture what an author would see.
     *
     * @param  string        $script     Repository-relative path of the gate.
     * @param  list<string>  $arguments  Arguments passed to the gate.
     * @param  string        $tree       Root the gate runs against.
     *
     * @return array{0: int, 1: string}  Exit status and combined output.
     *
     * @since  2.0.0
     */
    private function execute(string $script, array $arguments, string $tree): array
    {
        $command = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($tree . '/' . $script);
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }
        $output = [];
        $status = 0;
        exec($command . ' 2>&1', $output, $status);

        return [$status, implode("\n", $output)];
    }

    /**
     * Run one Node generator against a copied repository tree.
     *
     * @param   string        $script     Repository-relative generator path.
     * @param   list<string>  $arguments  Arguments passed to Node.
     * @param   string        $tree       Root the generator derives its owned paths from.
     *
     * @return  array{0: int, 1: string}  Exit status and combined output.
     *
     * @since   2.0.0
     */
    private function executeNode(string $script, array $arguments, string $tree): array
    {
        $command = 'node ' . escapeshellarg($tree . '/' . $script);
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }
        $output = [];
        $status = 0;
        exec($command . ' 2>&1', $output, $status);

        return [$status, implode("\n", $output)];
    }

    /**
     * Copy the parts of the tree the gates read into a scratch directory.
     *
     * @return string  Absolute path of the copy, removed when the process ends.
     *
     * @since  2.0.0
     */
    private function treeCopy(): string
    {
        $tree = sys_get_temp_dir() . '/kumwe-translation-gate-' . bin2hex(random_bytes(6));
        foreach (['templates', 'assets', 'resources/localization', 'tools', 'src'] as $directory) {
            $this->copyTree($this->root . '/' . $directory, $tree . '/' . $directory);
        }
        copy($this->root . '/vite.config.ts', $tree . '/vite.config.ts');
        $publicAssets = $tree . '/public/assets';
        mkdir($publicAssets, 0o775, true);
        foreach (['administrator.css', 'portal.css', 'site.css'] as $stylesheet) {
            copy($this->root . '/public/assets/' . $stylesheet, $publicAssets . '/' . $stylesheet);
        }
        $this->copyTree($this->root . '/public/assets/build', $publicAssets . '/build');
        register_shutdown_function(function () use ($tree): void {
            $this->removeTree($tree);
        });

        return $tree;
    }

    /**
     * Copy one directory tree recursively.
     *
     * @param  string  $from  Source directory.
     * @param  string  $to    Destination directory.
     *
     * @return void
     *
     * @since  2.0.0
     */
    private function copyTree(string $from, string $to): void
    {
        if (!is_dir($to)) {
            mkdir($to, 0o775, true);
        }
        $entries = scandir($from);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $source = $from . '/' . $entry;
            if (is_dir($source)) {
                $this->copyTree($source, $to . '/' . $entry);
                continue;
            }
            copy($source, $to . '/' . $entry);
        }
    }

    /**
     * Remove one directory tree recursively.
     *
     * @param  string  $path  Directory to remove.
     *
     * @return void
     *
     * @since  2.0.0
     */
    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->removeTree($child) : unlink($child);
        }
        rmdir($path);
    }
}
