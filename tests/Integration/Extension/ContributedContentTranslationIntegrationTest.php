<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Extension;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Content\Application\TranslationGroupRepository;
use Kumwe\App\Content\Domain\ContentStatus;
use Kumwe\App\Extension\Application\ExtensionManager;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Contribution\OwnedRuntimeContributionRegistry;
use Kumwe\App\Extension\Contribution\TranslationGroupDeclaration;
use Kumwe\Extension\Spi\Contribution\TranslationSetItemAssociation;
use Kumwe\Extension\Package\PackageChecksum;
use Kumwe\Extension\Package\PackageSignatureMessage;
use Kumwe\App\Kernel\Configuration\ApplicationConfiguration;
use Kumwe\App\Kernel\ContainerFactory;
use Kumwe\App\Localization\Domain\LocaleTag;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Site\Application\SiteSettings;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\App\Kernel\Container;
use Laminas\Diactoros\ServerRequestFactory;
use Mezzio\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;
use ZipArchive;

#[CoversClass(ContentService::class)]
#[UsesClass(TranslationGroupDeclaration::class)]
#[UsesClass(OwnedRuntimeContributionRegistry::class)]
/**
 * The signed compatibility fixture for the extension content-translation item-association contract.
 *
 * Everything the contract promises is exercised on the real lifecycle rather than on doubles: a
 * package that declares one translation set is built, signed and installed through the ordinary trust
 * path; two locale variants of its content are stored through `ContentService`, the same public
 * application path every host-service consumer uses; both variants render through the real locale
 * negotiation middleware; and the refusals are proven where the contract makes them — an undeclared
 * locale, a set the claimed owner never declared, another owner's set, and a set whose package has
 * been disabled. The last case is the one that shows the declaration governs the association at
 * resolution time rather than being copied into content, while the already-associated variants keep
 * rendering, because stored content outlives the package that contributed it.
 *
 * @since  2.0.0
 */
final class ContributedContentTranslationIntegrationTest extends TestCase
{
    /**
     * Prove the declared set governs storage and delivery of contributed variants end to end.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testASignedPackageStoresAndServesLocaleVariantsUnderItsDeclaredSet(): void
    {
        $environment = Environment::fromGlobals();
        $container = TestKernelFactory::create($environment);
        $manager = $this->service($container, ExtensionManager::class);
        $trust = $this->service($container, TrustStore::class);
        $context = TestKernelFactory::administratorContext($container);
        // The tail of a UUIDv7 is its random half; the head is a timestamp whose first eight hex
        // characters only move every 65 seconds, which two suite runs on one database can share.
        $marker = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -8));
        $identifier = 'integration/stories-' . $marker;
        $namespace = str_replace('/', '.', $identifier);
        $set = $namespace . '.stories';
        $keyId = 'integration.stories.' . $marker;
        $archive = $this->packagedProvider($identifier, $set, $marker);
        $keyPair = sodium_crypto_sign_keypair();
        $bytes = file_get_contents($archive);
        if (!is_string($bytes)) {
            throw new RuntimeException('The content translation fixture package cannot be read.');
        }
        $signature = base64_encode(sodium_crypto_sign_detached(
            PackageSignatureMessage::forChecksum(PackageChecksum::calculate($bytes)),
            sodium_crypto_sign_secretkey($keyPair),
        ));
        $installed = false;
        $previousSettings = null;
        $settings = null;
        $editor = null;

        try {
            $trust->add(
                $context,
                $keyId,
                base64_encode(sodium_crypto_sign_publickey($keyPair)),
                'integration',
                '*',
                new DateTimeImmutable('+1 year'),
            );
            $manager->install($archive, $context, $keyId, $signature);
            $installed = true;
            $manager->activate($identifier, $context);
            $trust->synchronizeRuntimeMaterialization();

            $runtime = TestKernelFactory::create($environment);
            $editor = TestKernelFactory::administratorContext($runtime);
            $content = $this->service($runtime, ContentService::class);
            $groups = $this->service($runtime, TranslationGroupRepository::class);
            $settings = $this->service($runtime, SiteSettings::class);
            $previousSettings = $settings->managed($editor);
            $association = new TranslationSetItemAssociation($identifier, $set);
            $group = $association->groupIdForSite($editor->site()->identifier());

            $english = $this->page($content, $editor, 'Stories ' . $marker, 'stories-' . $marker);
            $german = $this->page($content, $editor, 'Geschichten ' . $marker, 'geschichten-' . $marker);
            foreach ([[$english, 'en-GB'], [$german, 'de']] as [$record, $locale]) {
                $stored = $content->translateContributed(
                    $editor,
                    $record->entry->id(),
                    $record->entry->version(),
                    LocaleTag::fromString($locale),
                    $association,
                );
                self::assertSame($group, $stored->entry->translationGroupId());
            }
            $resolved = $groups->forContent($editor->site(), $english->entry->id());
            self::assertNotNull($resolved);
            self::assertSame($group, $resolved->id);
            self::assertSame('en-GB', $resolved->fallbackLocale->toString());
            self::assertSame(['de', 'en-GB'], array_map(
                static fn (object $member): string => $member->locale->toString(),
                $resolved->members(),
            ));

            $settings->updateAll($editor, [
                'homepage_content_id' => $english->entry->id(),
                'homepage_slug' => 'stories-' . $marker,
            ]);
            $germanBody = $this->publicPage('/?locale=de', ['Accept-Language' => 'en-GB']);
            self::assertStringContainsString('Geschichten ' . $marker, $germanBody);
            self::assertStringContainsString('<html lang="de" dir="ltr">', $germanBody);
            $englishBody = $this->publicPage('/', ['Accept-Language' => 'en-GB;q=1.0, de;q=0.5']);
            self::assertStringContainsString('Stories ' . $marker, $englishBody);
            self::assertStringContainsString('hreflang="de"', $englishBody);
            $fallback = $this->publicPageResponse('/?locale=af', ['Accept-Language' => 'de']);
            self::assertStringContainsString('Stories ' . $marker, (string) $fallback->getBody());
            self::assertSame('en-GB', $fallback->getHeaderLine('Content-Language'));

            $this->assertRefusals($content, $editor, $association, $namespace, $set, $marker);
            $this->assertDisabledPackageRefusesNewAssociations(
                $manager,
                $trust,
                $environment,
                $context,
                $identifier,
                $set,
                $marker,
            );
        } finally {
            if ($previousSettings !== null && $settings !== null && $editor !== null) {
                $settings->updateAll($editor, $previousSettings);
            }
            if ($installed) {
                try {
                    $manager->disable($identifier, $context);
                } catch (Throwable) {
                }
                try {
                    $manager->uninstall($identifier, $context);
                } catch (Throwable) {
                }
            }
            if (is_file($archive)) {
                unlink($archive);
            }
        }
    }

    /**
     * Prove the three storage-time refusals the contract promises, leaving the store untouched.
     *
     * @param   ContentService                 $content      Service wired to the active registry.
     * @param   ExecutionContext               $context      Actor and site the attempts run under.
     * @param   TranslationSetItemAssociation  $association  Valid association for the installed package.
     * @param   string                         $namespace    Installed package's dotted namespace.
     * @param   string                         $set          Declared set identifier.
     * @param   string                         $marker       Per-run suffix for fresh entries.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertRefusals(
        ContentService $content,
        ExecutionContext $context,
        TranslationSetItemAssociation $association,
        string $namespace,
        string $set,
        string $marker,
    ): void {
        $entry = $content->create($context, 'Refused ' . $marker, 'refused-' . $marker, ['body' => 'Body']);

        try {
            $content->translateContributed(
                $context,
                $entry->entry->id(),
                $entry->entry->version(),
                LocaleTag::fromString('he'),
                $association,
            );
            self::fail('An undeclared locale must be refused.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                sprintf('Translation set %s does not declare locale he.', $set),
                $exception->getMessage(),
            );
        }

        try {
            $content->translateContributed(
                $context,
                $entry->entry->id(),
                $entry->entry->version(),
                LocaleTag::fromString('de'),
                new TranslationSetItemAssociation($association->owner->identifier(), $namespace . '.unheard'),
            );
            self::fail('A set the owner never declared must be refused.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                sprintf(
                    'Translation set %s.unheard is not an active declaration of %s.',
                    $namespace,
                    $association->owner->identifier(),
                ),
                $exception->getMessage(),
            );
        }

        try {
            new TranslationSetItemAssociation('rival/pages', $set);
            self::fail('Another owner\'s set must be refused.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('cannot claim content translation group', $exception->getMessage());
        }

        $unchanged = $content->get($context, $entry->entry->id());
        self::assertNull($unchanged->entry->translationGroupId());
        self::assertSame($entry->entry->version(), $unchanged->entry->version());
    }

    /**
     * Prove disabling the package withdraws the association while stored variants keep rendering.
     *
     * @param   ExtensionManager  $manager      Manager driving the lifecycle.
     * @param   TrustStore        $trust        Trust store publishing the runtime materialization.
     * @param   Environment       $environment  Environment the fresh runtime kernel is built from.
     * @param   ExecutionContext  $context      Actor and site the attempt runs under.
     * @param   string            $identifier   Installed package identifier.
     * @param   string            $set          Declared set identifier.
     * @param   string            $marker       Per-run suffix naming the stored variants.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertDisabledPackageRefusesNewAssociations(
        ExtensionManager $manager,
        TrustStore $trust,
        Environment $environment,
        ExecutionContext $context,
        string $identifier,
        string $set,
        string $marker,
    ): void {
        $manager->disable($identifier, $context);
        $trust->synchronizeRuntimeMaterialization();
        $withdrawn = TestKernelFactory::create($environment);
        $lateEditor = TestKernelFactory::administratorContext($withdrawn);
        $content = $this->service($withdrawn, ContentService::class);
        $entry = $content->create($lateEditor, 'Late ' . $marker, 'late-' . $marker, ['body' => 'Body']);

        try {
            $content->translateContributed(
                $lateEditor,
                $entry->entry->id(),
                $entry->entry->version(),
                LocaleTag::fromString('de'),
                new TranslationSetItemAssociation($identifier, $set),
            );
            self::fail('A disabled package\'s set must be refused.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                sprintf('Translation set %s is not an active declaration of %s.', $set, $identifier),
                $exception->getMessage(),
            );
        }

        $germanBody = $this->publicPage('/?locale=de', ['Accept-Language' => 'en-GB']);
        self::assertStringContainsString('Geschichten ' . $marker, $germanBody);
    }

    /**
     * Author one page and walk it to its public state through the built-in workflow.
     *
     * @param   ContentService    $content  Service the page is stored through.
     * @param   ExecutionContext  $context  Actor and site the page is created for.
     * @param   string            $title    Title of the page.
     * @param   string            $slug     Route segment the page is published under.
     *
     * @return  ContentRecord  The stored record, at the version the caller must hand back.
     *
     * @since   2.0.0
     */
    private function page(
        ContentService $content,
        ExecutionContext $context,
        string $title,
        string $slug,
    ): ContentRecord {
        $record = $content->create($context, $title, $slug, ['body' => 'Body of ' . $title]);
        $record = $content->transition(
            $context,
            $record->entry->id(),
            $record->entry->version(),
            ContentStatus::Review,
        );

        return $content->transition(
            $context,
            $record->entry->id(),
            $record->entry->version(),
            ContentStatus::Published,
        );
    }

    /**
     * Package the fixture provider and manifest under a wholly per-run identity.
     *
     * The package is assembled from strings rather than a directory so this fixture carries exactly the
     * two files the contract needs — a schema-4 manifest declaring one translation set, and its empty
     * service-composition provider — and nothing an unrelated example might grow later. The signed
     * manifest is the only declaration source. The PHP namespace carries the
     * per-run marker because a class name is process-global: a failed earlier run may leave its package
     * behind, and a repeated class name would hand this run the stale provider instead of its own.
     *
     * @param   string  $identifier  Per-run `vendor/name` identifier for the package.
     * @param   string  $set         Declared translation-set identifier inside its namespace.
     * @param   string  $marker      Per-run suffix keeping the provider class name unique.
     *
     * @return  string  Absolute path of the packaged archive.
     *
     * @throws  RuntimeException  When the fixture archive cannot be assembled.
     *
     * @since   2.0.0
     */
    private function packagedProvider(string $identifier, string $set, string $marker): string
    {
        $archive = tempnam(sys_get_temp_dir(), 'kumwe-content-translation-extension-');
        if (!is_string($archive)) {
            throw new RuntimeException('The content translation fixture archive cannot be allocated.');
        }
        $namespace = 'KumweIntegration\\Stories' . ucfirst($marker);
        $manifest = json_encode([
            'schema' => 4,
            'name' => $identifier,
            'type' => 'component',
            'version' => '1.0.0',
            'provider' => $namespace . '\\Provider',
            'autoload' => ['psr-4' => [$namespace . '\\' => 'src/']],
            'requires' => ['kumwe' => '^2.0.0', 'php' => '^8.5.0'],
            'contributions' => [
                'version' => 2,
                'capabilities' => [],
                'content' => [
                    'translation_groups' => [
                        ['group_id' => $set, 'locales' => ['de', 'en-GB'], 'fallback_locale' => 'en-GB'],
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $provider = <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace PROVIDER_NAMESPACE;

            use Kumwe\Extension\Spi\Application\ExtensionServiceProvider;
            use Kumwe\Extension\Spi\Runtime\ExtensionContainer;

            final class Provider implements ExtensionServiceProvider
            {
                public function register(ExtensionContainer $container): void
                {
                }
            }
            PHP;
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The content translation fixture archive cannot be opened.');
        }
        try {
            if (
                !$zip->addFromString('kumwe.json', $manifest)
                || !$zip->addFromString(
                    'src/Provider.php',
                    str_replace('PROVIDER_NAMESPACE', $namespace, $provider),
                )
            ) {
                throw new RuntimeException('A content translation fixture file cannot be packaged.');
            }
        } finally {
            $zip->close();
        }

        return $archive;
    }

    /**
     * Fetch one public page through the real application, following its canonical redirect.
     *
     * @param   string                 $path     Root-relative path to request.
     * @param   array<string, string>  $headers  Request headers to add.
     *
     * @return  string  The rendered HTML of the canonical page.
     *
     * @since   2.0.0
     */
    private function publicPage(string $path, array $headers = []): string
    {
        return (string) $this->publicPageResponse($path, $headers)->getBody();
    }

    /**
     * Fetch one public page through a freshly composed application, retaining its response metadata.
     *
     * A new kernel is built per request deliberately: delivery must resolve the association's group
     * from stored state alone, exactly as a later process serving a reader would.
     *
     * @param   string                 $path     Root-relative path to request.
     * @param   array<string, string>  $headers  Request headers to add.
     *
     * @return  ResponseInterface  Canonical 200 response after following a public-content redirect.
     *
     * @since   2.0.0
     */
    private function publicPageResponse(string $path, array $headers = []): ResponseInterface
    {
        $container = (new ContainerFactory())->create(Environment::fromGlobals());
        $application = $this->service($container, Application::class);
        $host = $this->service($container, ApplicationConfiguration::class)->trustedHosts[0];

        $response = $this->publicRequest($application, $host, $path, $headers);
        if ($response->getStatusCode() === 308) {
            $response = $this->publicRequest(
                $application,
                $host,
                $response->getHeaderLine('Location'),
                $headers,
            );
        }
        self::assertSame(200, $response->getStatusCode(), $path);

        return $response;
    }

    /**
     * Issue one public GET through the real application on a trusted host.
     *
     * @param   Application            $application  Booted application under test.
     * @param   string                 $host         Host name the installation answers to.
     * @param   string                 $path         Root-relative path to request.
     * @param   array<string, string>  $headers      Request headers to add.
     *
     * @return  ResponseInterface  The application's response, whatever its status.
     *
     * @since   2.0.0
     */
    private function publicRequest(
        Application $application,
        string $host,
        string $path,
        array $headers = [],
    ): ResponseInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://' . $host . $path)
            ->withHeader('Host', $host);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        $query = parse_url($path, PHP_URL_QUERY);
        if (is_string($query)) {
            parse_str($query, $parameters);
            $request = $request->withQueryParams($parameters);
        }

        return $application->handle($request);
    }

    /**
     * Resolve one service out of the container, refusing anything of the wrong type.
     *
     * @template T of object
     *
     * @param   Container        $container  Booted kernel container.
     * @param   class-string<T>  $service    Service to resolve.
     *
     * @return  T  The resolved service.
     *
     * @throws  RuntimeException  When the container answers with something else.
     *
     * @since   2.0.0
     */
    private function service(Container $container, string $service): object
    {
        $resolved = $container->get($service);
        if (!$resolved instanceof $service) {
            throw new RuntimeException(sprintf('The container did not supply %s.', $service));
        }

        return $resolved;
    }
}
