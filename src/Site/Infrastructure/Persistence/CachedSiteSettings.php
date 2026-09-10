<?php

declare(strict_types=1);

namespace Kumwe\App\Site\Infrastructure\Persistence;

use Kumwe\App\Infrastructure\Redis\RedisRuntime;
use Kumwe\App\Site\Application\SiteSettings;
use Kumwe\Context\Value\ExecutionContext;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Redis read cache in front of `DoctrineSiteSettings`, wired as the `SiteSettings` the container hands out.
 *
 * Nearly every public request reads the settings document — for the homepage nomination, the primary
 * menu handle and the presentation contract — and none of those reads has an actor, so the
 * unauthenticated `current()` answer is held in Redis for five minutes and only a miss reaches SQL.
 * Nothing else is cached: the administration read is capability-checked per call, and both writers go
 * straight to the database and drop the cached copy afterwards, so a saved change shows up on the next
 * request instead of when the entry expires. Dropping after the write rather than before is what keeps
 * a rejected write from leaving readers on a cache that disagrees with the table. SQL stays the source
 * of truth; the cache only saves the query.
 *
 * Because SQL is the source of truth, a cache that cannot answer is a slower request rather than a
 * failed one: `current()` falls back to the database and records why. This is the one deliberate
 * asymmetry with the fail-closed rate limiter that shares the same Redis. A limiter outage must refuse
 * sign-ins, because admitting an uncounted attempt loses a security property; a cache outage must not
 * refuse public page reads, because serving them from the authoritative table loses nothing but the
 * saved query. Refusing here would turn a dead cache into a site-wide outage for a document the
 * database can produce on demand. Both writers keep their invalidation *loud* for the same reason it is
 * ordered after the commit: an invalidation that silently did not happen leaves readers on a stale
 * document for the rest of its lifetime, which is a correctness problem rather than a performance one.
 *
 * @since  2.0.0
 */
final readonly class CachedSiteSettings implements SiteSettings
{
    /**
     * Redis key the settings document lives under, shared by the read and both invalidations.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string CACHE_KEY = 'site-settings';

    /**
     * Bind the cache to the database-backed settings it fronts.
     *
     * @param  DoctrineSiteSettings  $settings  Authoritative store every miss and every write goes to.
     * @param  RedisRuntime          $redis     Cache runtime holding the decoded document between reads.
     * @param  LoggerInterface       $logger    Sink for a degraded read, so an outage that costs only
     *         latency is still visible rather than silent.
     *
     * @since  2.0.0
     */
    public function __construct(
        private DoctrineSiteSettings $settings,
        private RedisRuntime $redis,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Read the settings document, from Redis when it is warm and from the database otherwise.
     *
     * A miss repopulates the cache with a five-minute lifetime, so a burst of public requests after an
     * expiry costs one query rather than one per request. A cache that raises — an unreachable server, a
     * damaged entry, a refused write — is answered from the database instead, at the cost of one query
     * per request until it recovers, and recorded at warning level naming the operation that failed. The
     * document returned is the authoritative one either way, so nothing a broken cache does can change
     * what a reader sees.
     *
     * @return  array<string, mixed>  Every public setting key, defaults included for keys never stored.
     *
     * @since   2.0.0
     */
    public function current(): array
    {
        try {
            $cached = $this->redis->cachedJson(self::CACHE_KEY);
            if ($cached !== null) {
                return $cached;
            }
        } catch (Throwable $failure) {
            return $this->degraded('read', $failure);
        }

        $settings = $this->settings->current();

        try {
            $this->redis->cacheJson(self::CACHE_KEY, $settings, 300);
        } catch (Throwable $failure) {
            $this->logger->warning('The site settings cache is unavailable; reads are served from the database.', [
                'cache_key' => self::CACHE_KEY,
                'operation' => 'write',
                'exception' => $failure,
            ]);
        }

        return $settings;
    }

    /**
     * Answer a public read from the authoritative table after the cache refused to serve it.
     *
     * The failure is recorded rather than rethrown, and the entry is not repopulated on the way out: a
     * server that just raised is in no state to be written to, and a poisoned entry that survived a
     * decode failure is dropped by the next successful write rather than by a read racing the outage.
     *
     * @param   string     $operation  Cache operation that refused, named for the operator reading the log.
     * @param   Throwable  $failure    Value the cache raised, attached to the record.
     *
     * @return  array<string, mixed>  Every public setting key, read from the database.
     *
     * @since   2.0.0
     */
    private function degraded(string $operation, Throwable $failure): array
    {
        $this->logger->warning('The site settings cache is unavailable; reads are served from the database.', [
            'cache_key' => self::CACHE_KEY,
            'operation' => $operation,
            'exception' => $failure,
        ]);

        return $this->settings->current();
    }

    /**
     * Read the settings document for an administrator, bypassing the cache entirely.
     *
     * The capability check depends on the actor, so a cached answer would either be served to the
     * wrong one or skip the check; the delegate answers every call directly.
     *
     * @param   ExecutionContext  $context  Actor and site the read runs as.
     *
     * @return  array<string, mixed>  Every public setting key, defaults included for keys never stored.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage settings.
     *
     * @since   2.0.0
     */
    public function managed(ExecutionContext $context): array
    {
        return $this->settings->managed($context);
    }

    /**
     * Store the site name and homepage slug in the database, then drop the cached document.
     *
     * Invalidation runs only after the write has returned, so a refused or failed update leaves the
     * cached copy in place and still matching what is stored.
     *
     * @param   ExecutionContext  $context       Actor and site the write runs as.
     * @param   string            $siteName      Display name shown in page chrome and titles.
     * @param   string            $homepageSlug  Slug the homepage falls back to when no content id is set.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage settings.
     * @throws  \InvalidArgumentException  When the name or the slug fails validation.
     *
     * @since   2.0.0
     */
    public function update(ExecutionContext $context, string $siteName, string $homepageSlug): void
    {
        $this->settings->update($context, $siteName, $homepageSlug);
        $this->redis->forgetCache(self::CACHE_KEY);
    }

    /**
     * Merge and store the supplied settings in the database, then drop the cached document.
     *
     * As with `update()`, the cache is invalidated only once the write has committed, so a rejected
     * document never leaves readers looking at an entry that disagrees with the table.
     *
     * @param   ExecutionContext      $context   Actor and site the write runs as.
     * @param   array<string, mixed>  $settings  Public setting keys to change, merged over the current document.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage settings.
     * @throws  \InvalidArgumentException  When a value, the nominated homepage, or the primary menu is
     *          rejected.
     *
     * @since   2.0.0
     */
    public function updateAll(ExecutionContext $context, array $settings): void
    {
        $this->settings->updateAll($context, $settings);
        $this->redis->forgetCache(self::CACHE_KEY);
    }
}
