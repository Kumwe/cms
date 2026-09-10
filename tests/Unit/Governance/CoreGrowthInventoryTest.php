<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Governance;

use Kumwe\App\Tools\Governance\CoreGrowthInventory;
use Kumwe\App\Tools\Governance\GovernanceViolation;
use Kumwe\App\Tools\Governance\LayerClassifier;
use Kumwe\App\Tools\Governance\PhpDeclarationScanner;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Holds `tools/Governance/CoreGrowthInventory.php` to the public-surface digest and classification rules.
 *
 * The clean fixture inventories four App symbols in sorted order with their layers; the surface digest is stable
 * across runs and moves only when a public member appears, disappears or changes signature; host evidence is
 * derived from the declaration; a duplicate FQCN and an unclassifiable name are refused rather than guessed.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class CoreGrowthInventoryTest extends TestCase
{
    /**
     * Load the governance classes once.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/tools/Governance/bootstrap.php';
    }

    /**
     * The clean fixture inventories its four App symbols, sorted, classified and digested.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheCleanFixtureIsClassifiedAndSorted(): void
    {
        $root = GovernanceFixture::cleanRoot();
        $inventory = CoreGrowthInventory::scan(
            $root,
            LayerClassifier::fromFile($root . '/docs/architecture/layers.json'),
        );

        self::assertSame(4, $inventory->count());
        self::assertSame(
            [
                'Kumwe\\App\\Example\\Application\\DescribeSubject',
                'Kumwe\\App\\Example\\Domain\\ExampleSubject',
                'Kumwe\\App\\Example\\Infrastructure\\PrefixedExampleService',
                'Kumwe\\App\\Kernel\\ContainerFactory',
            ],
            array_keys($inventory->symbols()),
        );
        self::assertSame(
            ['application', 'domain', 'infrastructure', 'kernel'],
            array_values(array_column($inventory->symbols(), 'layer')),
        );
        foreach ($inventory->symbols() as $fqcn => $symbol) {
            self::assertSame('class', $symbol['kind'], $fqcn);
            self::assertSame($fqcn, $symbol['fqcn']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{24}$/', $symbol['surface'], $fqcn);
            self::assertSame(CoreGrowthInventory::digest($symbol['canonical']), $symbol['surface'], $fqcn);
        }
        $adapter = $inventory->symbol('Kumwe\\App\\Example\\Infrastructure\\PrefixedExampleService');
        self::assertNotNull($adapter);
        self::assertSame('PrefixedExampleService', $adapter['short_name']);
        self::assertSame('src/Example/Infrastructure/PrefixedExampleService.php', $adapter['file']);
        self::assertSame(14, $adapter['line'], 'The line of the class keyword.');
        self::assertSame(['__construct', 'describe'], $adapter['methods']);
        self::assertSame(['Kumwe\\Example\\Contract\\ExampleServiceInterface'], $adapter['implements']);
        self::assertNull($adapter['extends']);
        self::assertNull($inventory->symbol('Kumwe\\App\\Example\\Domain\\Nowhere'));
    }

    /**
     * The canonical surface text lists the kind, modifiers, parent, interfaces, constants, properties and methods,
     * public members only, each sorted, with promoted constructor properties and flags rendered.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheCanonicalSurfaceListsEveryPublicFactAndNothingElse(): void
    {
        $declaration = self::declaration(<<<'PHP'
            <?php
            namespace Kumwe\App\Sample;

            use Kumwe\App\Sample\Contract\Beta;

            final readonly class Sample extends Base implements Beta, Alpha
            {
                public const string LIMIT = 'x';
                private const HIDDEN = 1;
                protected const int SHIELDED = 2;
                public static int $count = 0;
                private array $cache = [];

                public function __construct(public string $name, private int $secret, protected bool $flag = false)
                {
                }

                public static function make(?string $seed = null, int ...$rest): static
                {
                    return new static('x', 1);
                }

                public function &reference(array &$items): array
                {
                    return $items;
                }

                protected function hidden(): void
                {
                }

                private function secret(): int
                {
                    return $this->secret;
                }
            }
            PHP);

        self::assertSame(
            "kind class\n"
            . "modifiers final readonly\n"
            . "extends Kumwe\\App\\Sample\\Base\n"
            . "implements Kumwe\\App\\Sample\\Alpha\n"
            . "implements Kumwe\\App\\Sample\\Contract\\Beta\n"
            . "const LIMIT\n"
            . "property static count: int\n"
            . "property name: string\n"
            . "method __construct(string \$name, int \$secret, bool \$flag = default)\n"
            . "method static make(?string \$seed = default, int ...\$rest): static\n"
            . "method reference(array &\$items): array\n",
            CoreGrowthInventory::canonicalSurface($declaration),
        );
        self::assertSame(
            substr(hash('sha256', CoreGrowthInventory::canonicalSurface($declaration)), 0, 24),
            CoreGrowthInventory::surface($declaration),
        );
    }

    /**
     * The digest is stable across runs and member order, and moves only when the public surface moves.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheDigestIsStableAndMovesOnlyWithThePublicSurface(): void
    {
        $base = self::surfaceOf('public function describe(string $subject): string { return $subject; }');

        self::assertSame(
            $base,
            self::surfaceOf('public function describe(string $subject): string { return $subject; }'),
            'Two scans of one source agree.',
        );
        self::assertSame(
            $base,
            self::surfaceOf(
                'private function helper(): void {} public function describe(string $subject): string '
                . '{ return $subject; } // note',
            ),
            'A private method and a comment do not move the digest.',
        );
        self::assertSame(
            $base,
            self::surfaceOf(
                'protected int $state = 0; protected const HIDDEN = 1; public function describe(string $subject): '
                . 'string { return $subject; }',
            ),
            'Protected members do not move the digest.',
        );
        self::assertNotSame(
            $base,
            self::surfaceOf(
                'public function describe(string $subject): string { return $subject; } public function '
                . 'other(): void {}',
            ),
            'A new public method moves the digest.',
        );
        self::assertNotSame(
            $base,
            self::surfaceOf('public function describe(string $subject): ?string { return $subject; }'),
            'A changed return type moves the digest.',
        );
        self::assertNotSame(
            $base,
            self::surfaceOf('public function describe(string $subject, int $depth = 0): string { return $subject; }'),
            'A new parameter moves the digest.',
        );
        self::assertNotSame(
            $base,
            self::surfaceOf(
                'public const string DEFAULT = \'x\'; public function describe(string $subject): string '
                . '{ return $subject; }',
            ),
            'A new public constant moves the digest.',
        );
        self::assertNotSame(
            $base,
            self::surfaceOf('public function describe(string $subject): string { return $subject; }', 'readonly'),
            'A modifier moves the digest.',
        );
        $ordered = self::surfaceOf('public function a(): void {} public function b(): void {}');
        self::assertSame(
            $ordered,
            self::surfaceOf('public function b(): void {} public function a(): void {}'),
            'Member order does not move the digest.',
        );
        $enum = self::enumSurfaceOf('case Draft; case Published;');
        self::assertSame(
            $enum,
            self::enumSurfaceOf('case Published; case Draft;'),
            'Enum case order does not move the digest.',
        );
        self::assertNotSame(
            $enum,
            self::enumSurfaceOf('case Draft; case Published; case Archived;'),
            'A new enum case is public surface a consumer can match on, so it moves the digest.',
        );
    }

    /**
     * Host evidence carries `host-<layer>`, the sorted interfaces and the parent class.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testHostEvidenceDerivesFromTheDeclaration(): void
    {
        $class = self::declaration(<<<'PHP'
            <?php
            namespace Kumwe\App\Example\Infrastructure;

            use Kumwe\Example\Contract\ExampleServiceInterface;

            final class Adapter extends Base implements ExampleServiceInterface, \Stringable
            {
            }
            PHP);
        self::assertSame(
            [
                'classification' => 'host-infrastructure',
                'implements' => ['Kumwe\\Example\\Contract\\ExampleServiceInterface', 'Stringable'],
                'extends' => 'Kumwe\\App\\Example\\Infrastructure\\Base',
            ],
            CoreGrowthInventory::hostEvidence($class, 'infrastructure'),
        );

        $interface = self::declaration(<<<'PHP'
            <?php
            namespace Kumwe\App\Kernel;

            interface Port extends \Countable, \IteratorAggregate
            {
            }
            PHP);
        self::assertSame(
            ['classification' => 'host-kernel', 'implements' => ['Countable', 'IteratorAggregate'], 'extends' => null],
            CoreGrowthInventory::hostEvidence($interface, 'kernel'),
        );

        $enum = self::declaration(<<<'PHP'
            <?php
            namespace Kumwe\App\Delivery;

            enum Mode: string implements \JsonSerializable
            {
                case On = 'on';
            }
            PHP);
        self::assertSame(
            ['classification' => 'host-delivery', 'implements' => ['JsonSerializable'], 'extends' => null],
            CoreGrowthInventory::hostEvidence($enum, 'delivery'),
        );
    }

    /**
     * An FQCN declared in two production files and a name no layer rule classifies are refused with a fix.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDuplicateAndUnclassifiableNamesAreRefused(): void
    {
        $root = GovernanceFixture::copy();
        try {
            $classifier = LayerClassifier::fromFile($root . '/docs/architecture/layers.json');
            GovernanceFixture::write(
                $root,
                'src/Example/Domain/Twin.php',
                "<?php\n\nnamespace Kumwe\\App\\Example\\Domain;\n\nfinal class ExampleSubject\n{\n}\n",
            );
            try {
                CoreGrowthInventory::scan($root, $classifier);
                self::fail('A duplicate FQCN must be refused.');
            } catch (GovernanceViolation $violation) {
                self::assertStringContainsString(
                    'src/Example/Domain/Twin.php: declares Kumwe\\App\\Example\\Domain\\ExampleSubject, which '
                    . 'src/Example/Domain/ExampleSubject.php already declares',
                    $violation->getMessage(),
                );
                self::assertStringContainsString('Fix:', $violation->getMessage());
            }
            GovernanceFixture::delete($root, 'src/Example/Domain/Twin.php');

            GovernanceFixture::write(
                $root,
                'src/Nowhere/Thing.php',
                "<?php\n\nnamespace Kumwe\\App\\Nowhere;\n\nfinal class Thing\n{\n}\n",
            );
            try {
                CoreGrowthInventory::scan($root, $classifier);
                self::fail('An unclassifiable name must be refused.');
            } catch (GovernanceViolation $violation) {
                self::assertStringContainsString('Kumwe\\App\\Nowhere\\Thing', $violation->getMessage());
                self::assertStringContainsString('namespace_prefixes', $violation->getMessage());
            }
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * Scan one source and return its single declaration.
     *
     * @param   string  $source  Complete PHP source.
     *
     * @return  array<string, mixed>  The declaration.
     *
     * @since   2.0.0
     */
    private static function declaration(string $source): array
    {
        $scan = PhpDeclarationScanner::scanSource($source, 'sample.php');
        self::assertCount(1, $scan['declarations']);

        return $scan['declarations'][0];
    }

    /**
     * The surface digest of a class whose body is the given members.
     *
     * @param   string  $members    Member declarations.
     * @param   string  $modifiers  Class modifiers before `class`.
     *
     * @return  string  The 24-character digest.
     *
     * @since   2.0.0
     */
    private static function surfaceOf(string $members, string $modifiers = 'final'): string
    {
        return CoreGrowthInventory::surface(self::declaration(
            "<?php\nnamespace Kumwe\\App\\Sample;\n\n" . $modifiers . " class Sample\n{\n" . $members . "\n}\n",
        ));
    }

    /**
     * The surface digest of an enum whose body is the given cases and members.
     *
     * @param   string  $members  Case and member declarations.
     *
     * @return  string  The 24-character digest.
     *
     * @since   2.0.0
     */
    private static function enumSurfaceOf(string $members): string
    {
        return CoreGrowthInventory::surface(self::declaration(
            "<?php\nnamespace Kumwe\\App\\Sample;\n\nenum State\n{\n" . $members . "\n}\n",
        ));
    }
}
