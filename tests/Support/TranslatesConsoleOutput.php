<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use Kumwe\Localization\Application\CatalogueTranslator;

/**
 * Implements the translated half of the console `Output` contract for test doubles.
 *
 * Every recording double keeps its own `line()` and `error()` and gains the three catalogue-backed
 * methods by delegation, resolving through the repository's real compiled en-GB catalogue exactly as
 * `StreamOutput` does in production — numeric parameters substituted verbatim — so a test keeps
 * asserting on the same English sentences the console prints.
 */
trait TranslatesConsoleOutput
{
    public function message(string $identifier, array $parameters = []): void
    {
        $this->line($this->text($identifier, $parameters));
    }

    public function failure(string $identifier, array $parameters = []): void
    {
        $this->error($this->text($identifier, $parameters));
    }

    public function text(string $identifier, array $parameters = []): string
    {
        static $translator = null;
        if (!$translator instanceof CatalogueTranslator) {
            $translator = InterfaceTranslation::translator();
        }
        $values = [];
        foreach ($parameters as $name => $value) {
            $values[$name] = is_int($value) || is_float($value) ? (string) $value : $value;
        }

        return $translator->translate($identifier, $values);
    }
}
