<?php

declare(strict_types=1);

namespace Kumwe\App\Localization\Infrastructure;

use InvalidArgumentException;
use Kumwe\Localization\Application\MessageCatalogueRepository;
use Kumwe\Localization\Domain\LocaleTag;
use Kumwe\Localization\Domain\MessageCatalogue;
use Kumwe\Localization\Domain\MessageCatalogueLayer;
use RuntimeException;

/**
 * Serves the core and extension layers from compiled PHP array files rather than from XLIFF.
 *
 * XLIFF is the authoring and interchange format, so a translator and a translation platform never
 * see a source file; it is emphatically not the runtime format. What this reads is the build's
 * output: one `return [...]` file per locale per catalogue directory, which the opcode cache holds
 * as compiled bytecode. A lookup is therefore an array access with no XML parser, no file read per
 * message and no cache warm-up on the request path.
 *
 * Extension catalogues are supplied as an ordered list of directories. Within the extension layer
 * the first directory carrying an identifier wins, which makes the outcome a property of the
 * declared order rather than of filesystem enumeration.
 *
 * @since  2.0.0
 */
final class CompiledMessageCatalogueRepository implements MessageCatalogueRepository
{
    /**
     * Catalogues already loaded, keyed by layer and locale tag.
     *
     * @var    array<string, MessageCatalogue>
     * @since  2.0.0
     */
    private array $loaded = [];

    /**
     * Bind the repository to the directories each file-shipped layer is compiled into.
     *
     * @param  string        $coreDirectory         Directory holding the CMS's own compiled catalogues.
     * @param  list<string>  $extensionDirectories  Compiled catalogue directories contributed by active
     *         extensions, in the order the extension layer resolves them.
     *
     * @since  2.0.0
     */
    public function __construct(
        private readonly string $coreDirectory,
        private readonly array $extensionDirectories = [],
    ) {
    }

    /**
     * Read one file-shipped layer for one locale.
     *
     * @param   MessageCatalogueLayer  $layer   Either the core layer or the extension layer.
     * @param   LocaleTag              $locale  Exact locale to read.
     *
     * @return  MessageCatalogue  The layer's messages, empty when nothing is compiled at this locale.
     *
     * @throws  InvalidArgumentException  When asked for a layer this repository does not serve.
     * @throws  RuntimeException  When a compiled catalogue file does not return a map of strings.
     *
     * @since   2.0.0
     */
    public function catalogue(MessageCatalogueLayer $layer, LocaleTag $locale): MessageCatalogue
    {
        $tag = $locale->toString();
        $key = $layer->value . '@' . $tag;
        if (isset($this->loaded[$key])) {
            return $this->loaded[$key];
        }

        $directories = match ($layer) {
            MessageCatalogueLayer::Core => [$this->coreDirectory],
            MessageCatalogueLayer::Extension => $this->extensionDirectories,
            default => throw new InvalidArgumentException(sprintf(
                'The compiled catalogue repository serves the core and extension layers only, not %s.',
                $layer->value,
            )),
        };

        $messages = [];
        foreach ($directories as $directory) {
            foreach ($this->read($directory, $tag) as $identifier => $pattern) {
                $messages[$identifier] ??= $pattern;
            }
        }

        return $this->loaded[$key] = new MessageCatalogue($locale, $layer, $messages);
    }

    /**
     * Read one compiled catalogue file, treating an absent one as an empty catalogue.
     *
     * The locale tag is validated by `LocaleTag` before it reaches here, so it cannot contain a path
     * separator; the basename is still asserted rather than trusted, because this call resolves a
     * filesystem path from a value that began life in a request header.
     *
     * @param   string  $directory  Directory the catalogue should be compiled into.
     * @param   string  $tag        Canonical locale tag naming the file.
     *
     * @return  array<string, string>  Patterns keyed by identifier, empty when nothing is compiled.
     *
     * @throws  RuntimeException  When the file does not return a map of strings to strings.
     *
     * @since   2.0.0
     */
    private function read(string $directory, string $tag): array
    {
        if (preg_match('/^[A-Za-z0-9-]{2,35}$/D', $tag) !== 1) {
            throw new RuntimeException('A compiled catalogue can only be read for a canonical locale tag.');
        }
        $path = $directory . '/' . $tag . '.php';
        if (!is_file($path)) {
            return [];
        }

        /** @var mixed $contents */
        $contents = require $path;
        if (!is_array($contents)) {
            throw new RuntimeException(sprintf('The compiled catalogue %s does not return an array.', $path));
        }

        $messages = [];
        foreach ($contents as $identifier => $pattern) {
            if (!is_string($identifier) || !is_string($pattern)) {
                throw new RuntimeException(sprintf(
                    'The compiled catalogue %s must map message identifiers to pattern strings.',
                    $path,
                ));
            }
            $messages[$identifier] = $pattern;
        }

        return $messages;
    }
}
