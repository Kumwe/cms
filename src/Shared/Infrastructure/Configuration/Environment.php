<?php

declare(strict_types=1);

namespace Kumwe\App\Shared\Infrastructure\Configuration;

use InvalidArgumentException;

/**
 * Immutable, typed view over the allow-listed configuration values a deployment supplies.
 *
 * This is the only first-party class permitted to read the process environment or a dotenv file;
 * every other class receives an already-constructed instance, so configuration can never be pulled
 * from a superglobal deep inside a request. `ConfigurationFactory` is the primary consumer, turning
 * these raw strings into `ApplicationConfiguration`, and the accessors below reject malformed input at
 * that boundary so a mistyped port or byte limit fails at boot rather than at first use.
 *
 * @since  2.0.0
 */
final readonly class Environment
{
    /**
     * Environment variable names Kumwe reads; anything else in the process or dotenv scope is ignored.
     *
     * The allow-list is why an unrelated exported variable, or a stray line in a hand-edited `.env`,
     * cannot reach application configuration. Every secret has a `_FILE` companion beside it, so a
     * deployment that mounts secrets as files rather than exporting them needs no shell wrapper of its
     * own; `ConfigurationFactory` resolves the pair and refuses both spellings at once.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const PROCESS_KEYS = [
        'APP_ENV',
        'APP_DEBUG',
        'APP_BASE_URL',
        'APP_PUBLIC_SITE',
        'APP_TRUSTED_HOSTS',
        'APP_TRUSTED_PROXIES',
        'APP_MAX_BODY_BYTES',
        'APP_ADMIN_SESSION_SECONDS',
        'APP_SECRET',
        'APP_SECRET_FILE',
        'RECORD_ENCRYPTION_KEY_ID',
        'RECORD_ENCRYPTION_KEY',
        'RECORD_ENCRYPTION_KEY_FILE',
        'RECORD_ENCRYPTION_PREVIOUS_KEYS',
        'RECORD_ENCRYPTION_PREVIOUS_KEYS_FILE',
        'RECORD_ENCRYPTION_LEGACY_SECRET',
        'RECORD_ENCRYPTION_LEGACY_SECRET_FILE',
        'EXTENSION_RUNTIME_SIGNING_KEY_ID',
        'EXTENSION_RUNTIME_SIGNING_KEY',
        'EXTENSION_RUNTIME_PREVIOUS_KEYS',
        'EXTENSION_RUNTIME_PREVIOUS_KEYS_FILE',
        'EXTENSIONS_ALLOW_UNSIGNED_LOCAL',
        'EXTENSIONS_CONFORMANCE_ADMISSION',
        'EXTENSIONS_REVOCATION_FEED_URL',
        'EXTENSIONS_REVOCATION_FEED_KEY',
        'EXTENSIONS_REVOCATION_FEED_KEY_FILE',
        'EXTENSIONS_REVOCATION_FEED_MAX_STALE_SECONDS',
        'KUMWE_RELEASE',
        'KUMWE_DEPLOYMENT_ID',
        'KUMWE_REPLICA_ID',
        'KUMWE_PROCESS_ID',
        'KUMWE_INSTANCE_ID',
        'KUMWE_LOG_LEVEL',
        'KUMWE_METRICS_ENABLED',
        'KUMWE_METRICS_TOKEN',
        'KUMWE_METRICS_TOKEN_FILE',
        'KUMWE_SITE_CONTENT_PROFILE',
        'KUMWE_BUSINESS_DEMO',
        'KUMWE_BUSINESS_PROFILE',
        'KUMWE_STUDIO_BROWSER_BASE_URL',
        'BUSINESS_IDEMPOTENCY_REPLAY_SECONDS',
        'BUSINESS_IDEMPOTENCY_RETENTION_SECONDS',
        'DB_DRIVER',
        'DB_HOST',
        'DB_PORT',
        'DB_NAME',
        'DB_USER',
        'DB_PASSWORD',
        'DB_TABLE_PREFIX',
        'DB_SERVER_VERSION',
        'DB_SSLMODE',
        'REDIS_HOST',
        'REDIS_PORT',
        'REDIS_PASSWORD',
        'REDIS_DATABASE',
        'REDIS_NAMESPACE',
    ];

    /**
     * Wrap an already-resolved set of configuration values.
     *
     * Tests construct instances directly to pin behaviour without touching the machine; production
     * code goes through `fromGlobals()` so the allow-list and the precedence rules apply.
     *
     * @param  array<string, string>  $values  Raw variable values keyed by variable name, unvalidated.
     *
     * @since  2.0.0
     */
    public function __construct(private array $values)
    {
    }

    /**
     * Build an instance from the dotenv file and the process environment.
     *
     * The only first-party boundary permitted to read the process environment. Dotenv values are read
     * first and process values are applied over them, so a variable exported by the container or the
     * shell always wins over the file.
     *
     * @param   ?string  $dotenvFile  Absolute path to the dotenv file, or null for the repository-root `.env`.
     *
     * @return  self  Instance carrying the allow-listed variables that resolved to a value.
     *
     * @throws  InvalidArgumentException  When the dotenv file exists but cannot be read or parsed.
     *
     * @since   2.0.0
     */
    public static function fromGlobals(?string $dotenvFile = null): self
    {
        $dotenvFile ??= dirname(__DIR__, 4) . '/.env';
        $values = self::readDotenv($dotenvFile);

        foreach (self::PROCESS_KEYS as $key) {
            $value = getenv($key);

            if (is_string($value)) {
                $values[$key] = $value;
            }
        }

        return new self($values);
    }

    /**
     * Parse the dotenv file into the subset of assignments Kumwe recognises.
     *
     * Read only Kumwe's allow-listed settings. Process environment values are applied afterwards and
     * therefore always take precedence over this file. Blank lines and `#` comments are skipped, an
     * optional `export ` prefix is stripped, and an unknown key is dropped silently rather than
     * reported, so an operator can keep unrelated variables in the same file.
     *
     * @param   string  $path  Absolute path to the dotenv file; a missing file is not an error.
     *
     * @return  array<string, string>  Allow-listed values by variable name; empty when the file is absent.
     *
     * @throws  InvalidArgumentException  When the file cannot be read, a line carries no `=` assignment,
     *          or a quoted value is unterminated.
     *
     * @since   2.0.0
     */
    private static function readDotenv(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);

        if (!is_array($lines)) {
            throw new InvalidArgumentException(sprintf('Environment file "%s" could not be read.', $path));
        }

        $allowed = array_fill_keys(self::PROCESS_KEYS, true);
        $values = [];

        foreach ($lines as $lineNumber => $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }

            $separator = strpos($line, '=');

            if ($separator === false) {
                throw new InvalidArgumentException(sprintf(
                    'Environment file "%s" contains an invalid assignment on line %d.',
                    $path,
                    $lineNumber + 1,
                ));
            }

            $key = trim(substr($line, 0, $separator));

            if (!isset($allowed[$key])) {
                continue;
            }

            $values[$key] = self::parseDotenvValue(
                trim(substr($line, $separator + 1)),
                $path,
                $lineNumber + 1,
            );
        }

        return $values;
    }

    /**
     * Decode one dotenv right-hand side into the literal string the variable should carry.
     *
     * An unquoted value is trimmed and truncated at the first ` #` inline comment. A single-quoted
     * value is taken literally. A double-quoted value has `\n`, `\r`, `\t`, `\"` and `\\` expanded in a
     * single left-to-right pass, so the output of an escape is never rescanned as another escape.
     *
     * @param   string  $value       Right-hand side of the assignment, already trimmed of outer spaces.
     * @param   string  $path        Dotenv file path, used only to build the failure message.
     * @param   int     $lineNumber  One-based line number, used only to build the failure message.
     *
     * @return  string  The decoded value, which may legitimately be an empty string.
     *
     * @throws  InvalidArgumentException  When a quoted value has no matching closing quote.
     *
     * @since   2.0.0
     */
    private static function parseDotenvValue(string $value, string $path, int $lineNumber): string
    {
        if ($value === '') {
            return '';
        }

        $quote = $value[0];

        if ($quote !== '"' && $quote !== "'") {
            $comment = strpos($value, ' #');

            return trim($comment === false ? $value : substr($value, 0, $comment));
        }

        if (strlen($value) < 2 || !str_ends_with($value, $quote)) {
            throw new InvalidArgumentException(sprintf(
                'Environment file "%s" contains an unterminated quoted value on line %d.',
                $path,
                $lineNumber,
            ));
        }

        $value = substr($value, 1, -1);
        if ($quote !== '"') {
            return $value;
        }

        $decoded = '';
        $length = strlen($value);
        for ($index = 0; $index < $length; $index++) {
            $character = $value[$index];
            if ($character !== '\\' || $index + 1 >= $length) {
                $decoded .= $character;
                continue;
            }
            $escaped = $value[++$index];
            $decoded .= match ($escaped) {
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                '"' => '"',
                '\\' => '\\',
                default => '\\' . $escaped,
            };
        }

        return $decoded;
    }

    /**
     * Report whether a variable is present and carries a non-empty value.
     *
     * @param   string  $name  Environment variable name to test.
     *
     * @return  bool  False both when the variable is unset and when it resolves to an empty string.
     *
     * @since   2.0.0
     */
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values) && $this->values[$name] !== '';
    }

    /**
     * Read a variable the deployment is required to provide.
     *
     * The default covers an absent variable only. A present but empty assignment is a configuration
     * mistake rather than a request for the default, so it is rejected instead of falling back, unlike
     * `optionalString()` which treats the two alike.
     *
     * @param   string   $name     Environment variable name to read.
     * @param   ?string  $default  Fallback when the variable is absent; null makes it mandatory.
     *
     * @return  string  The configured value, never an empty string.
     *
     * @throws  InvalidArgumentException  When the variable is present but empty, or absent with no
     *          non-empty default.
     *
     * @since   2.0.0
     */
    public function string(string $name, ?string $default = null): string
    {
        $value = $this->values[$name] ?? $default;

        if ($value === null || $value === '') {
            throw new InvalidArgumentException(sprintf('Required environment variable "%s" is not configured.', $name));
        }

        return $value;
    }

    /**
     * Read a variable the deployment may legitimately leave unset.
     *
     * An empty assignment counts as absent, so `REDIS_PASSWORD=` in a dotenv file yields the default
     * rather than an empty password.
     *
     * @param   string   $name     Environment variable name to read.
     * @param   ?string  $default  Value to return when the variable is unset or empty.
     *
     * @return  ?string  The configured value, or the default; null means the setting is not in use.
     *
     * @since   2.0.0
     */
    public function optionalString(string $name, ?string $default = null): ?string
    {
        $value = $this->values[$name] ?? $default;

        return $value === '' ? $default : $value;
    }

    /**
     * Read a variable as a flag, accepting the spellings operators actually write.
     *
     * `1`, `true`, `yes` and `on` are true; `0`, `false`, `no` and `off` are false; the comparison is
     * case-insensitive. Any other spelling is a configuration error rather than a silent false, so a
     * mistyped security switch cannot quietly disable itself.
     *
     * @param   string  $name     Environment variable name to read.
     * @param   bool    $default  Value to use when the variable is unset or empty.
     *
     * @return  bool  The parsed flag, or the default when the variable is unset or empty.
     *
     * @throws  InvalidArgumentException  When the value is not one of the accepted spellings.
     *
     * @since   2.0.0
     */
    public function boolean(string $name, bool $default = false): bool
    {
        $value = $this->values[$name] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        return match (strtolower($value)) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw new InvalidArgumentException(
                sprintf('Environment variable "%s" must contain a boolean value.', $name),
            ),
        };
    }

    /**
     * Read a variable that must denote a count, port, or size of at least one.
     *
     * The default applies only when the variable is absent; a present but empty, non-numeric, or
     * non-positive assignment is rejected instead of falling back to it.
     *
     * @param   string  $name     Environment variable name to read.
     * @param   int     $default  Value to use when the variable is absent; it must itself be positive.
     *
     * @return  int  The parsed value, guaranteed to be one or greater.
     *
     * @throws  InvalidArgumentException  When the configured value is not an integer of at least one.
     *
     * @since   2.0.0
     */
    public function positiveInteger(string $name, int $default): int
    {
        $value = $this->values[$name] ?? (string) $default;

        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new InvalidArgumentException(
                sprintf('Environment variable "%s" must contain a positive integer.', $name),
            );
        }

        return (int) $value;
    }

    /**
     * Read a variable that must denote an index inside a fixed inclusive range.
     *
     * Serves bounded selectors such as the Redis database number, where zero is meaningful but a value
     * above the server's limit is not. As with `positiveInteger()`, the default applies only when the
     * variable is absent.
     *
     * @param   string  $name     Environment variable name to read.
     * @param   int     $default  Value to use when the variable is absent; it must itself be in range.
     * @param   int     $maximum  Largest accepted value, inclusive.
     *
     * @return  int  The parsed value, between zero and `$maximum` inclusive.
     *
     * @throws  InvalidArgumentException  When the value is not an integer within the permitted range.
     *
     * @since   2.0.0
     */
    public function nonNegativeInteger(string $name, int $default, int $maximum): int
    {
        $value = $this->values[$name] ?? (string) $default;
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0 || (int) $value > $maximum) {
            throw new InvalidArgumentException(sprintf(
                'Environment variable "%s" must contain an integer between 0 and %d.',
                $name,
                $maximum,
            ));
        }

        return (int) $value;
    }

    /**
     * Read a variable as a list of comma-separated entries.
     *
     * Entries are trimmed and empty ones discarded, so a trailing comma or padded separator in a
     * hand-edited dotenv file cannot widen a trusted-host or trusted-proxy list with a blank entry.
     *
     * @param   string  $name  Environment variable name to read.
     *
     * @return  list<string>  Entries in declaration order; empty when the variable is unset or blank.
     *
     * @since   2.0.0
     */
    public function commaSeparatedList(string $name): array
    {
        $value = $this->values[$name] ?? '';

        if (trim($value) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (string $item): string => trim($item), explode(',', $value)),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
