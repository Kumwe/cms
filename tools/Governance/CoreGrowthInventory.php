<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Governance;

/**
 * The production inventory the core growth gate compares with its baseline.
 *
 * Every class-like declared under `src/` (production only; `tests/`, `examples/` and `tools/` are never
 * inventoried) is classified to its layer through `docs/architecture/layers.json`, digested to a public-surface
 * digest and, for host layers, described by the adapter or composition facts its declaration carries. The digest
 * is the first 24 hexadecimal characters of the SHA-256 of a canonical text built from the kind, the modifiers,
 * the parent, the sorted interfaces, the sorted public constants, the sorted public properties and the sorted
 * public method signatures (specification section 5.1); constructors count as public methods and their promoted
 * public parameters as properties. Private and protected members never move the digest, so a private change
 * that composes existing public APIs is invisible to the gate, while a new or widened public member is not.
 *
 * The inventory is a pure function of the source tree: the same files always yield the same symbols in the same
 * order, whatever order the filesystem returns them in.
 *
 * @since  2.0.0
 */
final readonly class CoreGrowthInventory
{
    /**
     * Repository-relative directory the inventory scans.
     *
     * @var    string
     * @since  2.0.0
     */
    public const SOURCE_DIRECTORY = 'src';

    /**
     * Number of hexadecimal characters of the SHA-256 kept as the surface digest.
     *
     * @var    int
     * @since  2.0.0
     */
    public const SURFACE_LENGTH = 24;

    /**
     * Keep the sorted symbols.
     *
     * @param  array<string, array{fqcn: string, short_name: string, kind: string, layer: string, surface: string,
     *         canonical: string, file: string, line: int, methods: list<string>, implements: list<string>,
     *         extends: string|null}>  $symbols  Symbols by fully qualified name, sorted.
     *
     * @since  2.0.0
     */
    private function __construct(
        private array $symbols,
    ) {
    }

    /**
     * Scan the production tree of a repository root.
     *
     * @param   string           $root        Absolute repository root, or a fixture root passed as `--root`.
     * @param   LayerClassifier  $classifier  Layer graph of that root.
     *
     * @return  self  The inventory.
     *
     * @throws  GovernanceViolation  When `src/` is missing, a file cannot be read, an FQCN is declared twice or a
     *          name is classified by no rule.
     *
     * @since   2.0.0
     */
    public static function scan(string $root, LayerClassifier $classifier): self
    {
        return self::fromScans(
            PhpDeclarationScanner::scanTree($root . '/' . self::SOURCE_DIRECTORY, self::SOURCE_DIRECTORY),
            $classifier,
        );
    }

    /**
     * Build the inventory from scans already taken of the production tree.
     *
     * @param   list<array{file: string, namespace: string, imports: array<string, string>,
     *          declarations: list<array<string, mixed>>, references: list<array{name: string, line: int}>,
     *          strings: list<array{value: string, line: int}>}>  $scans       Output of
     *          `PhpDeclarationScanner::scanTree()` for `src/`.
     * @param   LayerClassifier  $classifier  Layer graph of the root.
     *
     * @return  self  The inventory.
     *
     * @throws  GovernanceViolation  When an FQCN is declared in two files or a name is classified by no rule.
     *
     * @since   2.0.0
     */
    public static function fromScans(array $scans, LayerClassifier $classifier): self
    {
        $symbols = [];
        foreach ($scans as $scan) {
            foreach ($scan['declarations'] as $declaration) {
                /** @var string $fqcn */
                $fqcn = $declaration['fqcn'];
                if (isset($symbols[$fqcn])) {
                    throw GovernanceViolation::at(
                        $scan['file'],
                        sprintf('declares %s, which %s already declares', $fqcn, $symbols[$fqcn]['file']),
                        'one production FQCN is declared in exactly one file; remove or rename the duplicate',
                    );
                }
                $layer = $classifier->classify($fqcn);
                /** @var string $shortName */
                $shortName = $declaration['short_name'];
                /** @var string $kind */
                $kind = $declaration['kind'];
                /** @var int $line */
                $line = $declaration['line'];
                /** @var array<string, array{static: bool, parameters: list<array{name: string, type: string|null,
                 *   optional: bool, variadic: bool, by_reference: bool}>, return: string|null}> $methods */
                $methods = $declaration['methods'];
                $evidence = self::hostEvidence($declaration, $layer);
                $canonical = self::canonicalSurface($declaration);
                $symbols[$fqcn] = [
                    'fqcn' => $fqcn,
                    'short_name' => $shortName,
                    'kind' => $kind,
                    'layer' => $layer,
                    'surface' => self::digest($canonical),
                    'canonical' => $canonical,
                    'file' => $scan['file'],
                    'line' => $line,
                    'methods' => array_keys($methods),
                    'implements' => $evidence['implements'],
                    'extends' => $evidence['extends'],
                ];
            }
        }
        ksort($symbols, SORT_STRING);

        return new self($symbols);
    }

    /**
     * Every production symbol.
     *
     * @return  array<string, array{fqcn: string, short_name: string, kind: string, layer: string, surface: string,
     *          canonical: string, file: string, line: int, methods: list<string>, implements: list<string>,
     *          extends: string|null}>  By fully qualified name, sorted.
     *
     * @since   2.0.0
     */
    public function symbols(): array
    {
        return $this->symbols;
    }

    /**
     * One production symbol.
     *
     * @param   string  $fqcn  Fully qualified name without a leading backslash.
     *
     * @return  array{fqcn: string, short_name: string, kind: string, layer: string, surface: string,
     *          canonical: string, file: string, line: int, methods: list<string>, implements: list<string>,
     *          extends: string|null}|null  The symbol, or null when `src/` does not declare it.
     *
     * @since   2.0.0
     */
    public function symbol(string $fqcn): ?array
    {
        return $this->symbols[$fqcn] ?? null;
    }

    /**
     * Number of production symbols.
     *
     * @return  int  Declared class-likes under `src/`.
     *
     * @since   2.0.0
     */
    public function count(): int
    {
        return count($this->symbols);
    }

    /**
     * The public-surface digest of one declaration.
     *
     * @param   array<string, mixed>  $declaration  One declaration from `PhpDeclarationScanner`.
     *
     * @return  string  The first 24 hexadecimal characters of the SHA-256 of `canonicalSurface()`.
     *
     * @since   2.0.0
     */
    public static function surface(array $declaration): string
    {
        return self::digest(self::canonicalSurface($declaration));
    }

    /**
     * The digest of a canonical surface text.
     *
     * The gate takes it over a rewritten canonical text as well, to tell a rename a migration ledger records
     * from a change of surface.
     *
     * @param   string  $canonical  Text in the form `canonicalSurface()` returns.
     *
     * @return  string  The first 24 hexadecimal characters of its SHA-256.
     *
     * @since   2.0.0
     */
    public static function digest(string $canonical): string
    {
        return substr(hash('sha256', $canonical), 0, self::SURFACE_LENGTH);
    }

    /**
     * The canonical text the surface digest is taken over.
     *
     * One line per fact, in this order: `kind`, `modifiers`, `extends` (when a parent is declared), one
     * `implements` line per interface (sorted), one `const` line per public constant (sorted), one `case` line
     * per enum case (sorted; a case is public surface a consumer can match on), one `property` line per public
     * property (sorted, `static` and `readonly` flagged, `untyped` when no type is declared) and one `method`
     * line per public method (sorted, `static` flagged) rendering each parameter as
     * `<type> [&][...]$name[ = default]` and the return type when declared. Lines end with a newline.
     *
     * @param   array<string, mixed>  $declaration  One declaration from `PhpDeclarationScanner`.
     *
     * @return  string  The canonical text.
     *
     * @since   2.0.0
     */
    public static function canonicalSurface(array $declaration): string
    {
        $modifiers = [];
        foreach (['abstract', 'final', 'readonly'] as $modifier) {
            if (($declaration[$modifier] ?? false) === true) {
                $modifiers[] = $modifier;
            }
        }
        $lines = [
            'kind ' . self::string($declaration['kind'] ?? null),
            rtrim('modifiers ' . implode(' ', $modifiers)),
        ];
        $parent = $declaration['parent'] ?? null;
        if (is_string($parent)) {
            $lines[] = 'extends ' . $parent;
        }
        foreach (self::sortedInterfaces($declaration) as $interface) {
            $lines[] = 'implements ' . $interface;
        }
        /** @var list<string> $constants */
        $constants = $declaration['constants'] ?? [];
        sort($constants, SORT_STRING);
        foreach ($constants as $constant) {
            $lines[] = 'const ' . $constant;
        }
        /** @var list<string> $cases */
        $cases = $declaration['cases'] ?? [];
        sort($cases, SORT_STRING);
        foreach ($cases as $case) {
            $lines[] = 'case ' . $case;
        }
        /** @var array<string, array{type: string|null, static: bool, readonly: bool}> $properties */
        $properties = $declaration['properties'] ?? [];
        ksort($properties, SORT_STRING);
        foreach ($properties as $name => $property) {
            $flags = ($property['static'] ? 'static ' : '') . ($property['readonly'] ? 'readonly ' : '');
            $lines[] = sprintf('property %s%s: %s', $flags, $name, $property['type'] ?? 'untyped');
        }
        /** @var array<string, array{static: bool, parameters: list<array{name: string, type: string|null,
         *   optional: bool, variadic: bool, by_reference: bool}>, return: string|null}> $methods */
        $methods = $declaration['methods'] ?? [];
        ksort($methods, SORT_STRING);
        foreach ($methods as $name => $method) {
            $parameters = [];
            foreach ($method['parameters'] as $parameter) {
                $parameters[] = ($parameter['type'] === null ? '' : $parameter['type'] . ' ')
                    . ($parameter['by_reference'] ? '&' : '')
                    . ($parameter['variadic'] ? '...' : '')
                    . '$' . $parameter['name']
                    . ($parameter['optional'] ? ' = default' : '');
            }
            $lines[] = sprintf(
                'method %s%s(%s)%s',
                $method['static'] ? 'static ' : '',
                $name,
                implode(', ', $parameters),
                $method['return'] === null ? '' : ': ' . $method['return'],
            );
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * The adapter or composition evidence a host-layer declaration carries.
     *
     * @param   array<string, mixed>  $declaration  One declaration from `PhpDeclarationScanner`.
     * @param   string                $layer        Layer the declaration classifies to.
     *
     * @return  array{classification: string, implements: list<string>, extends: string|null}  `host-<layer>`, the
     *          sorted interfaces (parent interfaces for an interface) and the parent class, or null.
     *
     * @since   2.0.0
     */
    public static function hostEvidence(array $declaration, string $layer): array
    {
        $parent = $declaration['parent'] ?? null;

        return [
            'classification' => 'host-' . $layer,
            'implements' => self::sortedInterfaces($declaration),
            'extends' => is_string($parent) ? $parent : null,
        ];
    }

    /**
     * The interfaces of a declaration, sorted and without repeats.
     *
     * @param   array<string, mixed>  $declaration  One declaration from `PhpDeclarationScanner`.
     *
     * @return  list<string>  Fully qualified names.
     *
     * @since   2.0.0
     */
    private static function sortedInterfaces(array $declaration): array
    {
        /** @var list<string> $interfaces */
        $interfaces = $declaration['interfaces'] ?? [];
        $interfaces = array_values(array_unique($interfaces));
        sort($interfaces, SORT_STRING);

        return $interfaces;
    }

    /**
     * Render a decoded value for a line.
     *
     * @param   mixed  $value  The value.
     *
     * @return  string  The string itself, or its JSON encoding otherwise.
     *
     * @since   2.0.0
     */
    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR);
    }
}
