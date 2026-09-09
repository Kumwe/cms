<?php

declare(strict_types=1);

namespace Kumwe\App\Kernel\Configuration;

use Kumwe\App\BusinessRecord\Domain\BusinessRecordReplayWindow;
use Kumwe\App\Extension\Application\Package\PackageConformanceMode;
use Kumwe\App\Infrastructure\Observability\ObservabilityContract;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use JsonException;
use InvalidArgumentException;
use ValueError;

/**
 * Turns the raw environment into the validated configuration graph the rest of the process reads.
 *
 * This is the only translation step between `Environment` and `ApplicationConfiguration`, so it owns
 * every default a deployment may omit: the engine-specific database port and server version, the
 * body and session limits, the Redis namespace, and the placeholder secrets and identities that only
 * a `Testing` runtime is allowed to fall back on. `ContainerFactory` calls it once at boot and shares
 * the result, which is why a bad variable surfaces here rather than deep inside a request.
 *
 * @since  2.0.0
 */
final class ConfigurationFactory
{
    /**
     * Admission spellings shipped before the mode set was reduced to `scan` and `off`, by the mode that
     * keeps their meaning.
     *
     * `enforce` and `warn` both ran the install-time scan and differed only in whether an authoring
     * finding refused the install or merely recorded it. Mandatory package evidence now fails closed in
     * every mode and authoring findings are always advisory, so both select `Scan`. A `.env` written
     * from the example that carried `enforce` as its default therefore keeps booting, and keeps the
     * fuller posture, instead of stopping every command at configuration time.
     *
     * @var    array<string, PackageConformanceMode>
     * @since  2.0.0
     */
    private const array LEGACY_CONFORMANCE_ADMISSION = [
        'enforce' => PackageConformanceMode::Scan,
        'warn' => PackageConformanceMode::Scan,
    ];

    /**
     * Assemble the application, database, and Redis configuration from the supplied environment.
     *
     * Defaults are resolved before validation, so an unset variable yields the documented fallback
     * while a variable that is set but malformed is an error. Outside a `Testing` runtime the
     * extension runtime signing key and the four deployment identities are mandatory; under it they
     * take fixed placeholder values so a test run needs no secret material.
     *
     * @param   Environment  $environment  Allow-listed variables resolved from the process and dotenv file.
     *
     * @return  ApplicationConfiguration  Fully validated configuration, safe to share for the process lifetime.
     *
     * @throws  InvalidArgumentException  When a variable is missing, malformed, or fails a configuration rule.
     * @throws  ValueError  When `APP_ENV` names no known runtime, or no trusted host is configured.
     *
     * @since   2.0.0
     */
    public function create(Environment $environment): ApplicationConfiguration
    {
        $runtime = RuntimeEnvironment::from($environment->string('APP_ENV', 'production'));
        $databaseDriver = strtolower($environment->string('DB_DRIVER', 'mariadb'));
        $defaultPort = $databaseDriver === 'pgsql' ? 5432 : 3306;
        $defaultServerVersion = match ($databaseDriver) {
            'pgsql' => '17',
            'mysql' => '8.4',
            'mariadb' => 'mariadb-12.3.2',
            default => '',
        };
        $testing = $runtime === RuntimeEnvironment::Testing;
        $runtimeKey = $environment->optionalString('EXTENSION_RUNTIME_SIGNING_KEY');
        if ($runtimeKey === null && !$testing) {
            throw new InvalidArgumentException('EXTENSION_RUNTIME_SIGNING_KEY is required outside tests.');
        }

        return new ApplicationConfiguration(
            environment: $runtime,
            debug: $environment->boolean('APP_DEBUG'),
            baseUrl: $environment->string('APP_BASE_URL'),
            publicSite: $environment->string('APP_PUBLIC_SITE', 'default'),
            siteContentProfile: $environment->string('KUMWE_SITE_CONTENT_PROFILE', 'documentation'),
            businessProfile: $this->businessProfile($environment),
            trustedHosts: $this->trustedHosts($environment),
            trustedProxies: $environment->commaSeparatedList('APP_TRUSTED_PROXIES'),
            maxBodyBytes: $environment->positiveInteger('APP_MAX_BODY_BYTES', 2_097_152),
            administratorSessionSeconds: $environment->positiveInteger('APP_ADMIN_SESSION_SECONDS', 28_800),
            idempotencyReplay: BusinessRecordReplayWindow::fromConfiguration(
                $environment->optionalString('BUSINESS_IDEMPOTENCY_REPLAY_SECONDS'),
                $environment->optionalString('BUSINESS_IDEMPOTENCY_RETENTION_SECONDS'),
            ),
            allowUnsignedLocalExtensions: $environment->boolean('EXTENSIONS_ALLOW_UNSIGNED_LOCAL'),
            release: $environment->string('KUMWE_RELEASE', '2.0.0-dev'),
            secret: $this->fileBackedSecret($environment, 'APP_SECRET') ?? $environment->string('APP_SECRET'),
            runtimeSigningKeyId: $environment->string('EXTENSION_RUNTIME_SIGNING_KEY_ID', 'runtime-v1'),
            runtimeSigningKey: $runtimeKey ?? str_repeat('testing-runtime-key-', 2),
            runtimePreviousSigningKeys: $this->previousRuntimeKeys($this->previousKeysPayload($environment)),
            deploymentId: $environment->string('KUMWE_DEPLOYMENT_ID', $testing ? 'testing-deployment' : null),
            replicaId: $environment->string('KUMWE_REPLICA_ID', $testing ? 'testing-replica' : null),
            processId: $environment->string('KUMWE_PROCESS_ID', $testing ? 'testing-process' : null),
            instanceId: $environment->string('KUMWE_INSTANCE_ID', $testing ? 'testing-instance' : null),
            database: new DatabaseConfiguration(
                driver: $databaseDriver,
                host: $environment->string('DB_HOST'),
                port: $environment->positiveInteger('DB_PORT', $defaultPort),
                database: $environment->string('DB_NAME'),
                user: $environment->string('DB_USER'),
                password: $environment->string('DB_PASSWORD'),
                tablePrefix: $environment->string('DB_TABLE_PREFIX', 'kumwe_'),
                sslMode: $environment->string('DB_SSLMODE', 'require'),
                serverVersion: $environment->string('DB_SERVER_VERSION', $defaultServerVersion),
            ),
            redis: new RedisConfiguration(
                host: $environment->string('REDIS_HOST', 'redis'),
                port: $environment->positiveInteger('REDIS_PORT', 6379),
                password: $environment->optionalString('REDIS_PASSWORD'),
                database: $environment->nonNegativeInteger('REDIS_DATABASE', 0, 15),
                namespace: $environment->string('REDIS_NAMESPACE', 'kumwe.app'),
            ),
            recordEncryption: $this->recordEncryption($environment),
            packageConformanceAdmission: $this->packageConformanceAdmission($environment),
            revocationFeed: $this->revocationFeed($environment),
            logLevel: $this->logLevel($environment),
            metricsEnabled: $environment->optionalString('KUMWE_METRICS_ENABLED') === null
                ? null
                : $environment->boolean('KUMWE_METRICS_ENABLED'),
            metricsToken: $this->fileBackedSecret($environment, 'KUMWE_METRICS_TOKEN'),
            studioBrowserBaseUrl: $environment->string(
                'KUMWE_STUDIO_BROWSER_BASE_URL',
                ApplicationConfiguration::DEFAULT_STUDIO_BROWSER_BASE_URL,
            ),
        );
    }

    /**
     * Resolve how install-time admission treats the static conformance scan of packaged code.
     *
     * Omitting the variable selects `Scan`, so an installation that says nothing collects advisory
     * authoring observations as well as mandatory evidence. The spellings the variable accepted before
     * the mode set was reduced, `enforce` and `warn`, select `Scan` as well, so an existing `.env` keeps
     * booting. Any other spelling is a configuration error rather than a silent fall back to the default.
     *
     * @param   Environment  $environment  Allow-listed variables resolved from the process and dotenv file.
     *
     * @return  PackageConformanceMode  The selected admission posture.
     *
     * @throws  InvalidArgumentException  When the variable names no supported or previously supported mode.
     *
     * @since   2.0.0
     */
    private function packageConformanceAdmission(Environment $environment): PackageConformanceMode
    {
        $mode = strtolower(trim(
            $environment->string('EXTENSIONS_CONFORMANCE_ADMISSION', PackageConformanceMode::Scan->value),
        ));

        return PackageConformanceMode::tryFrom($mode)
            ?? self::LEGACY_CONFORMANCE_ADMISSION[$mode]
            ?? throw new InvalidArgumentException(
                'EXTENSIONS_CONFORMANCE_ADMISSION must be scan or off; the earlier enforce and warn spellings '
                . 'are still accepted and select scan.',
            );
    }

    /**
     * Assemble the upstream revocation-feed settings, all of which a deployment may omit.
     *
     * The pinned verification key follows the same by-value-or-by-file discipline as every other secret
     * here, even though a public key is not confidential: an operator who mounts trust material as files
     * should not have to make an exception for this one, and a key read from a mounted file is far
     * harder to mistype into an installation than one pasted into an environment variable.
     *
     * @param   Environment  $environment  Allow-listed variables resolved from the process and dotenv file.
     *
     * @return  RevocationFeedConfiguration  The configured feed, or a disabled one when nothing is set.
     *
     * @throws  InvalidArgumentException  When the key names both a value and a file, the named file is not
     *          a readable regular file or is blank, or the resulting feed settings are incoherent.
     *
     * @since   2.0.0
     */
    private function revocationFeed(Environment $environment): RevocationFeedConfiguration
    {
        return new RevocationFeedConfiguration(
            origin: $environment->optionalString('EXTENSIONS_REVOCATION_FEED_URL'),
            publicKeyBase64: $this->fileBackedSecret($environment, 'EXTENSIONS_REVOCATION_FEED_KEY'),
            maxStaleSeconds: $environment->positiveInteger('EXTENSIONS_REVOCATION_FEED_MAX_STALE_SECONDS', 172_800),
        );
    }

    /**
     * Read the log-level override, normalised to the vocabulary the contract declares.
     *
     * The override exists so verbosity stops riding on `APP_DEBUG`. Raising an installation to
     * `warning` for a noisy afternoon, or dropping it to `debug` to chase one incident, previously
     * meant redeploying with debug on — which also widens the detail `ProblemDetailsMiddleware` puts
     * into a 500 response, turning a logging decision into a disclosure decision.
     *
     * @param   Environment  $environment  Allow-listed variables resolved from the process and dotenv file.
     *
     * @return  ?string  Lower-cased level name, or null to take the level the contract declares.
     *
     * @throws  InvalidArgumentException  When the variable is set but names no known level.
     *
     * @since   2.0.0
     */
    private function logLevel(Environment $environment): ?string
    {
        $declared = $environment->optionalString('KUMWE_LOG_LEVEL');
        if ($declared === null) {
            return null;
        }
        $level = strtolower(trim($declared));
        if (!in_array($level, ObservabilityContract::LEVELS, true)) {
            throw new InvalidArgumentException('KUMWE_LOG_LEVEL must name a known log level.');
        }

        return $level;
    }

    /**
     * Assemble the dedicated record-encryption settings, all of which a deployment may omit.
     *
     * Omitting every one of them is the supported upgrade path, not a degraded mode: the key derived
     * from `APP_SECRET` stays active and no stored envelope has to move. Each secret may be supplied
     * inline or through a `_FILE` companion, which is what lets a bare-metal or systemd deployment use
     * the same mounted-secret discipline the container image already applies.
     *
     * @param   Environment  $environment  Allow-listed variables resolved from the process and dotenv file.
     *
     * @return  RecordEncryptionConfiguration  The configured record key material, possibly all null.
     *
     * @throws  InvalidArgumentException  When a setting names both a value and a file, a named file is
     *          not a readable regular file or is blank, the retired-key payload is not a flat JSON
     *          object of identifiers to secrets, or an identifier is set without its key.
     *
     * @since   2.0.0
     */
    private function recordEncryption(Environment $environment): RecordEncryptionConfiguration
    {
        $activeKey = $this->fileBackedSecret($environment, 'RECORD_ENCRYPTION_KEY');

        return new RecordEncryptionConfiguration(
            activeKeyId: $activeKey === null
                ? null
                : $environment->optionalString('RECORD_ENCRYPTION_KEY_ID'),
            activeKey: $activeKey,
            previousKeys: $this->keyMap(
                $this->fileBackedSecret($environment, 'RECORD_ENCRYPTION_PREVIOUS_KEYS'),
                'RECORD_ENCRYPTION_PREVIOUS_KEYS',
            ),
            legacySecret: $this->fileBackedSecret($environment, 'RECORD_ENCRYPTION_LEGACY_SECRET'),
        );
    }

    /**
     * Read one secret from its variable or from the file its `_FILE` companion names.
     *
     * The two spellings are mutually exclusive rather than ordered, so there is never a question which
     * one a deployment is actually running on. The file must be an absolute path to a readable regular
     * file and must not be a symbolic link, which stops a writable link inside the deployment tree from
     * redirecting a secret read; the same rule the runtime key ring already applied. A trailing newline
     * is stripped, because that is what `printf` into a mounted file and most secret managers produce.
     *
     * @param   Environment  $environment  Allow-listed variables to read the pair from.
     * @param   string       $name         Variable name; its file companion is `$name . '_FILE'`.
     *
     * @return  ?string  The resolved secret, or null when neither spelling is configured.
     *
     * @throws  InvalidArgumentException  When both spellings are supplied, the named file fails its
     *          location or permission checks, or the file is blank.
     *
     * @since   2.0.0
     */
    private function fileBackedSecret(Environment $environment, string $name): ?string
    {
        $inline = $environment->optionalString($name);
        $file = $environment->optionalString($name . '_FILE');
        if ($inline !== null && $file !== null) {
            throw new InvalidArgumentException(sprintf('Configure %s by value or by file, never both.', $name));
        }
        if ($file === null) {
            return $inline;
        }
        if (!str_starts_with($file, '/') || !is_file($file) || is_link($file) || !is_readable($file)) {
            throw new InvalidArgumentException(sprintf('%s_FILE must be a readable regular file.', $name));
        }
        $payload = file_get_contents($file);
        if (!is_string($payload) || trim($payload) === '') {
            throw new InvalidArgumentException(sprintf('%s_FILE is empty.', $name));
        }

        return rtrim($payload, "\r\n");
    }

    /**
     * Decode a flat JSON object of key identifier to secret.
     *
     * A decode failure or a structurally wrong payload is a configuration error rather than something to
     * tolerate: silently dropping a retired record key would leave stored envelopes unreadable with no
     * indication of why.
     *
     * @param   ?string  $encoded  JSON object of retired keys, or null when none are configured.
     * @param   string   $name     Variable name quoted in the failure message.
     *
     * @return  array<string, string>  Retired secrets keyed by identifier; empty when none are configured.
     *
     * @throws  InvalidArgumentException  When the payload is not valid JSON, is not an object, or maps
     *          something other than string identifiers to string secrets.
     *
     * @since   2.0.0
     */
    private function keyMap(?string $encoded, string $name): array
    {
        if ($encoded === null) {
            return [];
        }
        try {
            $decoded = json_decode($encoded, false, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(sprintf('%s must be valid JSON.', $name), 0, $exception);
        }
        if (!$decoded instanceof \stdClass) {
            throw new InvalidArgumentException(sprintf('%s must be a JSON object.', $name));
        }
        /** @var array<string, string> $keys */
        $keys = [];
        foreach (get_object_vars($decoded) as $keyId => $key) {
            if (!is_string($keyId) || !is_string($key)) {
                throw new InvalidArgumentException(sprintf('%s must map identifiers to secrets.', $name));
            }
            $keys[$keyId] = $key;
        }

        return $keys;
    }

    /**
     * Resolve the named business demonstration profile from the current and the legacy selector.
     *
     * `KUMWE_BUSINESS_PROFILE` names any discovered business dataset directly and wins when present.
     * The historical boolean `KUMWE_BUSINESS_DEMO` keeps working as an alias: enabled selects the
     * released `vdm` example and disabled selects the explicit `none` profile, so existing
     * deployments and Compose files keep their meaning without an edit.
     *
     * @param   Environment  $environment  Allow-listed variables resolved from the process and dotenv file.
     *
     * @return  string  Named business profile, or `none` for a deliberately empty business runtime.
     *
     * @since   2.0.0
     */
    private function businessProfile(Environment $environment): string
    {
        $named = $environment->optionalString('KUMWE_BUSINESS_PROFILE');
        if ($named !== null && $named !== '') {
            return $named;
        }

        return $environment->boolean('KUMWE_BUSINESS_DEMO', true) ? 'vdm' : 'none';
    }

    /**
     * Decode the retired runtime signing keys a rotation still needs to verify old artifacts with.
     *
     * The payload is a flat JSON object of key identifier to secret. Both a decode failure and a
     * structurally wrong payload are reported as a configuration error rather than being tolerated,
     * because silently dropping a retired key would make previously published runtime maps
     * unverifiable.
     *
     * @param   ?string  $encoded  JSON object of retired keys, or null when none are configured.
     *
     * @return  array<string, string>  Retired secrets keyed by identifier; empty when none are configured.
     *
     * @throws  InvalidArgumentException  When the payload is not valid JSON, is not an object, or maps
     *          something other than string identifiers to string secrets.
     *
     * @since   2.0.0
     */
    private function previousRuntimeKeys(?string $encoded): array
    {
        return $this->keyMap($encoded, 'EXTENSION_RUNTIME_PREVIOUS_KEYS');
    }

    /**
     * Resolve where the retired runtime signing keys are read from, by value or by file.
     *
     * Operators may inline the JSON in `EXTENSION_RUNTIME_PREVIOUS_KEYS` or point
     * `EXTENSION_RUNTIME_PREVIOUS_KEYS_FILE` at a mounted secret, never both, so there is no question
     * which one wins. The file must be an absolute path to a readable regular file and must not be a
     * symbolic link, which stops a writable link in the deployment tree from redirecting a secret read.
     *
     * @param   Environment  $environment  Allow-listed variables to read the two settings from.
     *
     * @return  ?string  Raw JSON payload, or null when no retired keys are configured.
     *
     * @throws  InvalidArgumentException  When both settings are supplied, the file is not a readable
     *          regular file, or the file is blank.
     *
     * @since   2.0.0
     */
    private function previousKeysPayload(Environment $environment): ?string
    {
        $encoded = $environment->optionalString('EXTENSION_RUNTIME_PREVIOUS_KEYS');
        $file = $environment->optionalString('EXTENSION_RUNTIME_PREVIOUS_KEYS_FILE');
        if ($encoded !== null && $file !== null) {
            throw new InvalidArgumentException('Configure previous runtime keys by value or file, never both.');
        }
        if ($file === null) {
            return $encoded;
        }
        if (!str_starts_with($file, '/') || !is_file($file) || is_link($file) || !is_readable($file)) {
            throw new InvalidArgumentException('EXTENSION_RUNTIME_PREVIOUS_KEYS_FILE must be a readable regular file.');
        }
        $payload = file_get_contents($file);
        if (!is_string($payload) || trim($payload) === '') {
            throw new InvalidArgumentException('EXTENSION_RUNTIME_PREVIOUS_KEYS_FILE is empty.');
        }

        return $payload;
    }

    /**
     * Read the host names the application is permitted to answer to.
     *
     * An empty list is refused rather than defaulted, because a deployment with no host allow-list
     * would accept any `Host` header and open the door to host-header poisoning.
     *
     * @param   Environment  $environment  Allow-listed variables to read `APP_TRUSTED_HOSTS` from.
     *
     * @return  non-empty-list<string>  Configured host names in declaration order, never empty.
     *
     * @throws  ValueError  When `APP_TRUSTED_HOSTS` is unset or contains no usable entry.
     *
     * @since   2.0.0
     */
    private function trustedHosts(Environment $environment): array
    {
        $hosts = $environment->commaSeparatedList('APP_TRUSTED_HOSTS');

        if ($hosts === []) {
            throw new ValueError('APP_TRUSTED_HOSTS must contain at least one host.');
        }

        return $hosts;
    }
}
