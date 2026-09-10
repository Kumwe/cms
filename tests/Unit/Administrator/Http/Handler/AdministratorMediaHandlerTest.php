<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Administrator\Http\Handler;

use DateTimeImmutable;
use Kumwe\App\Administrator\Http\Handler\AdministratorMediaHandler;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\App\Administrator\Presentation\RecoveryAdministratorRenderer;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Identity\Application\Administration\AdministratorSession;
use Kumwe\App\Media\Application\MediaAsset;
use Kumwe\App\Media\Application\MediaService;
use Kumwe\App\Media\Application\MediaStorage;
use Kumwe\App\Presentation\Twig\AdministratorTwigEnvironment;
use Kumwe\App\Presentation\Twig\RecoveryAdministratorTwigEnvironment;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\InterfaceTranslation;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Twig\Loader\ArrayLoader;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;

/**
 * Pins the one media refusal an operator reads in place, rather than as a redirect.
 *
 * Every other media outcome redirects with a flag, so this is the single branch whose wording reaches
 * the screen directly: a post that carried no usable file. It re-renders the library at 422 with the
 * reason, and the reason now comes from the catalogue.
 *
 * @since  2.0.0
 */
#[CoversClass(AdministratorMediaHandler::class)]
final class AdministratorMediaHandlerTest extends TestCase
{
    /**
     * An upload without a usable file re-renders the library at 422 in catalogue wording.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUploadWithoutAFileIsRefusedInCatalogueWording(): void
    {
        $handler = new AdministratorMediaHandler(
            $this->media(),
            new AdministratorRenderer(
                new AdministratorTwigEnvironment(new ArrayLoader(['media.twig' => '{{ error }}|{{ total }}'])),
                new RecoveryAdministratorRenderer(new RecoveryAdministratorTwigEnvironment(new ArrayLoader())),
            ),
            InterfaceTranslation::translator(),
            sys_get_temp_dir() . '/kumwe-media-refusal-' . bin2hex(random_bytes(6)),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/administrator/media')
            ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, $this->session())
            ->withAttribute(
                ExecutionContextAttribute::NAME,
                AuthorizationContext::principal(['content.read', 'content.update'])->context(
                    SiteContext::default(),
                    \Kumwe\Context\Value\AuthenticationStrength::Password,
                    'media-refusal',
                ),
            )
            ->withUploadedFiles([]);

        $response = $handler->handle($request);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('Choose a media file to upload.', (string) $response->getBody());
    }

    /**
     * Build a media service over storage that holds nothing, which is all this branch needs.
     *
     * @return  MediaService  The service the handler browses through.
     *
     * @since   2.0.0
     */
    private function media(): MediaService
    {
        $storage = new class implements MediaStorage {
            /**
             * Answer with no assets at all.
             *
             * @param   SiteContext  $site  Site being listed.
             *
             * @return  list<MediaAsset>  Always empty.
             *
             * @since   2.0.0
             */
            public function all(SiteContext $site): array
            {
                return [];
            }

            /**
             * Answer that the site holds no such asset.
             *
             * @param   SiteContext  $site  Site being read.
             * @param   string       $id    Asset identifier.
             *
             * @return  ?MediaAsset  Always null.
             *
             * @since   2.0.0
             */
            public function find(SiteContext $site, string $id): ?MediaAsset
            {
                return null;
            }

            /**
             * Never reached; this case refuses before any store.
             *
             * @param   SiteContext        $site          Site being written.
             * @param   string             $source        Staged file path.
             * @param   string             $originalName  Client file name.
             * @param   int                $maximumBytes  Accepted size bound.
             * @param   DateTimeImmutable  $createdAt     Instant of the write.
             *
             * @return  MediaAsset  Never returned.
             *
             * @since   2.0.0
             */
            public function store(
                SiteContext $site,
                string $source,
                string $originalName,
                int $maximumBytes,
                DateTimeImmutable $createdAt,
            ): MediaAsset {
                throw new \RuntimeException('The refusal case never stores a file.');
            }

            /**
             * Never reached; this case deletes nothing.
             *
             * @param   SiteContext  $site  Site being changed.
             * @param   string       $id    Asset identifier.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function delete(SiteContext $site, string $id): void
            {
            }
        };
        $clock = new class implements ClockInterface {
            /**
             * Answer a fixed instant.
             *
             * @return  DateTimeImmutable  The instant this case runs at.
             *
             * @since   2.0.0
             */
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-08-19T09:00:00+00:00');
            }
        };
        $audit = new class implements AuditRecorder {
            /**
             * Discard the event; this case asserts on the response, not the trail.
             *
             * @param   AuditEvent  $event  Event the service recorded.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function record(AuditEvent $event): void
            {
            }
        };

        return new MediaService($storage, AuthorizationContext::gateway(), $audit, $clock, 5_000_000);
    }

    /**
     * An administrator session carrying the token the library form echoes.
     *
     * @return  AdministratorSession  Session bound to the request under test.
     *
     * @since   2.0.0
     */
    private function session(): AdministratorSession
    {
        return new AdministratorSession(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb711',
            AuthorizationContext::principal(['content.read', 'content.update']),
            'csrf-token',
            new DateTimeImmutable('+1 hour'),
        );
    }
}
