<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console;

use Kumwe\Localization\Application\Translator;

/**
 * `Output` implementation that writes each line to a pair of already-open stream resources.
 *
 * This is the implementation the container registers for real console runs: `standard()` binds it to
 * the process's `STDOUT` and `STDERR`, so command results stay on the stream a shell pipeline reads
 * and failure text stays on the stream a supervisor logs. Any other pair of writable resources works
 * too — a file handle, or `php://memory` in a test — which is why the streams are constructor input
 * rather than constants. Writing is unbuffered and unformatted: no colour, no prefix, no truncation,
 * and a failed `fwrite` is not reported, because losing a diagnostic line must not become the reason
 * a command fails.
 *
 * The translator arrives here once, which is what makes this the console's counterpart of the Twig
 * translation extension: every command resolves its user-facing wording through the surface it is
 * already handed, and none carries a translator of its own. The console negotiates no locale, so
 * messages resolve at the source locale the active-locale holder answers with outside a unit of
 * work. Numeric parameters are passed to ICU as strings, so a count prints verbatim the way the
 * console always printed it — greppable, never grouped by a locale's digit separators.
 *
 * @since  2.0.0
 */
final readonly class StreamOutput implements Output
{
    /**
     * Bind the two streams this output writes to and the translator wording resolves through.
     *
     * @param  resource    $standardOutput  Writable stream ordinary result lines are sent to.
     * @param  resource    $standardError   Writable stream failure lines are sent to; may be the same stream.
     * @param  Translator  $translator      Port every catalogue message is resolved and formatted through.
     *
     * @since  2.0.0
     */
    public function __construct(
        private mixed $standardOutput,
        private mixed $standardError,
        private Translator $translator,
    ) {
    }

    /**
     * Build the output the console process itself uses, bound to the standard streams.
     *
     * @param   Translator  $translator  Port the console's user-facing wording resolves through.
     *
     * @return  self  Output writing result lines to `STDOUT` and failure lines to `STDERR`.
     *
     * @since   2.0.0
     */
    public static function standard(Translator $translator): self
    {
        return new self(STDOUT, STDERR, $translator);
    }

    /**
     * Write one result line, terminated by the platform newline, to the output stream.
     *
     * @param   string  $message  Result text to emit verbatim.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function line(string $message): void
    {
        fwrite($this->standardOutput, $message . PHP_EOL);
    }

    /**
     * Write one failure line, terminated by the platform newline, to the error stream.
     *
     * @param   string  $message  Operator-facing explanation to emit verbatim.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function error(string $message): void
    {
        fwrite($this->standardError, $message . PHP_EOL);
    }

    /**
     * Resolve one catalogue message and write it as a result line.
     *
     * @param   string                                                   $identifier  Stable message identifier.
     * @param   array<string, string|int|float|bool|\DateTimeInterface>  $parameters  Values the pattern names.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function message(string $identifier, array $parameters = []): void
    {
        $this->line($this->text($identifier, $parameters));
    }

    /**
     * Resolve one catalogue message and write it as a failure line.
     *
     * @param   string                                                   $identifier  Stable message identifier.
     * @param   array<string, string|int|float|bool|\DateTimeInterface>  $parameters  Values the pattern names.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function failure(string $identifier, array $parameters = []): void
    {
        $this->error($this->text($identifier, $parameters));
    }

    /**
     * Resolve one catalogue message for composition into a longer line.
     *
     * @param   string                                                   $identifier  Stable message identifier.
     * @param   array<string, string|int|float|bool|\DateTimeInterface>  $parameters  Values the pattern names.
     *
     * @return  string  The resolved message, with numeric values substituted verbatim.
     *
     * @since   2.0.0
     */
    public function text(string $identifier, array $parameters = []): string
    {
        $values = [];
        foreach ($parameters as $name => $value) {
            $values[$name] = is_int($value) || is_float($value) ? (string) $value : $value;
        }

        return $this->translator->translate($identifier, $values);
    }
}
